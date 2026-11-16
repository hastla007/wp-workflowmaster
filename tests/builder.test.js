/**
 * Browser test of assets/js/builder.js (headless Chromium via Playwright).
 *
 *   node tests/builder.test.js
 *
 * Skips with exit 0 when Playwright is not installed.
 */
const path = require( 'path' );
const fs = require( 'fs' );
const os = require( 'os' );
let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( e ) {
	console.log( 'skip: playwright not installed' );
	process.exit( 0 );
}

const graph = {
	nodes: [
		{ id: 't', type: 'trigger', event: 'pass.ticket.checked_in' },
		{ id: 'mail', type: 'action', action: 'notify.admin', config: { subject: 'Einlass', message: 'x' } },
		{ id: 'e', type: 'end' },
	],
	edges: [ { from: 't', to: 'mail' }, { from: 'mail', to: 'e' } ],
};
const config = {
	actions: [
		{ key: 'notify.admin', label: 'Nachricht an Betreiber', fields: [ { key: 'subject', label: 'Betreff' } ] },
		{ key: 'workflow.tag.set', label: 'Markierung setzen', fields: [ { key: 'tag', label: 'Markierung' } ] },
		{ key: 'notify.user', label: 'Nachricht an Benutzer', fields: [ { key: 'subject', label: 'Betreff' } ] },
	],
	events: [ 'pass.ticket.checked_in', 'pass.order.paid' ],
	fields: { 'pass.ticket.checked_in': { checkin_source: 'string', device_id: 'integer', area_id: 'integer' } },
	coreMails: { 'pass.order.paid': [ { to: 'Käufer', mail: 'tickets', off: 'nicht abschaltbar' } ] },
	i18n: {},
};
const css = 'file://' + path.resolve( __dirname, '../assets/css/builder.css' );
const js = 'file://' + path.resolve( __dirname, '../assets/js/builder.js' );
const html = [
	'<!doctype html><html lang="de"><head><meta charset="utf-8">',
	'<link rel="stylesheet" href="' + css + '"></head><body>',
	'<textarea id="wfc-graph">' + JSON.stringify( graph ) + '</textarea>',
	'<script>window.workflowCaptainBuilder = ' + JSON.stringify( config ) + ';</script>',
	'<script src="' + js + '"></script></body></html>',
].join( '\n' );

let failures = 0;
function check( label, ok ) {
	console.log( ( ok ? 'ok   ' : 'FAIL ' ) + label );
	if ( ! ok ) {
		failures++;
	}
}

( async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const file = path.join( os.tmpdir(), 'wfc-builder-test.html' );
	fs.writeFileSync( file, html );
	await page.goto( 'file://' + file );
	const graphNow = async () => JSON.parse( await page.$eval( '#wfc-graph', ( t ) => t.value ) );
	const nodeX = async ( id ) => ( await graphNow() ).nodes.find( ( n ) => id === n.id ).x;

	check( 'renders three nodes', 3 === ( await page.$$( '.wfc-node' ) ).length );
	check( 'renders two arrows', 2 === ( await page.$$( '.wfc-edge' ) ).length );

	await page.click( '.wfc-node[data-id="mail"]' );
	await page.click( 'button[data-type="condition"]' );
	let g = await graphNow();
	check( 'palette adds a condition node', g.nodes.some( ( n ) => 'condition' === n.type ) );
	await page.click( 'button[data-type="trigger"]' );
	check( 'second trigger is refused', 1 === ( await graphNow() ).nodes.filter( ( n ) => 'trigger' === n.type ).length );

	await page.focus( '.wfc-node[data-id="mail"]' );
	await page.keyboard.press( 'c' );
	await page.click( '.wfc-node[data-id="condition1"]' );
	g = await graphNow();
	check( 'keyboard connect replaces the single outgoing edge of an action', 1 === g.edges.filter( ( e ) => 'mail' === e.from ).length && g.edges.some( ( e ) => 'mail' === e.from && 'condition1' === e.to ) );

	await page.click( '.wfc-node[data-id="condition1"]' );
	await page.click( 'text=Dann verbinden …' );
	await page.click( '.wfc-node[data-id="e"]' );
	await page.click( 'button[data-type="end"]' );
	await page.click( '.wfc-node[data-id="condition1"]' );
	await page.click( 'text=Sonst verbinden …' );
	await page.click( '.wfc-node[data-id="end1"]' );
	g = await graphNow();
	check( 'condition has a then and an else edge', g.edges.some( ( e ) => 'condition1' === e.from && 'then' === e.branch && 'e' === e.to ) && g.edges.some( ( e ) => 'condition1' === e.from && 'else' === e.branch && 'end1' === e.to ) );
	check( 'then and else labels are drawn', 2 === ( await page.$$( '.wfc-edge-label' ) ).length );

	await page.focus( '.wfc-node[data-id="e"]' );
	const before = await nodeX( 'e' );
	await page.keyboard.press( 'ArrowRight' );
	check( 'arrow key moves the node by 10', before + 10 === await nodeX( 'e' ) );
	await page.click( 'text=Rückgängig' );
	check( 'undo restores the position', before === await nodeX( 'e' ) );

	await page.click( '.wfc-node[data-id="mail"]' );
	await page.fill( '.wfc-inspector input[type="text"]', 'Neuer Betreff' );
	await page.press( '.wfc-inspector input[type="text"]', 'Tab' );
	check( 'inspector writes the action config', 'Neuer Betreff' === ( await graphNow() ).nodes.find( ( n ) => 'mail' === n.id ).config.subject );

	await page.focus( '.wfc-node[data-id="end1"]' );
	await page.keyboard.press( 'Delete' );
	g = await graphNow();
	check( 'Delete removes node and its edges', ! g.nodes.some( ( n ) => 'end1' === n.id ) && ! g.edges.some( ( e ) => 'end1' === e.to ) );

	const items = await page.$$eval( '.wfc-linear li', ( lis ) => lis.map( ( l ) => l.textContent ) );
	// Condition form (WF-BUILDER-04)
	await page.click( '.wfc-node[data-id="condition1"]' );
	await page.fill( '.wfc-rule-field', 'data.device_id' );
	await page.press( '.wfc-rule-field', 'Tab' );
	await page.selectOption( '.wfc-rule-op', 'gte' );
	await page.fill( '.wfc-rule-value', '4' );
	await page.press( '.wfc-rule-value', 'Tab' );
	let rule = ( await graphNow() ).nodes.find( ( n ) => 'condition1' === n.id ).rules.items[ 0 ];
	check( 'condition form writes field, operator and a number value', 'data.device_id' === rule.field && 'gte' === rule.operator && 4 === rule.value );
	await page.click( '.wfc-rule-add' );
	await page.selectOption( '.wfc-rule:nth-of-type(2) .wfc-rule-op', 'in' );
	await page.fill( '.wfc-rule:nth-of-type(2) .wfc-rule-value', 'scanner, web' );
	await page.press( '.wfc-rule:nth-of-type(2) .wfc-rule-value', 'Tab' );
	rule = ( await graphNow() ).nodes.find( ( n ) => 'condition1' === n.id ).rules.items[ 1 ];
	check( 'condition form: list values for "in"', 'in' === rule.operator && 'scanner' === rule.value[ 0 ] && 'web' === rule.value[ 1 ] );
	check( 'condition form shows the data type of the field', 'integer' === await page.$eval( '.wfc-rule-type', ( x ) => x.textContent ) );
	check( 'condition form offers the catalog fields', ( await page.$$eval( '#wfc-fields option', ( o ) => o.map( ( x ) => x.value ) ) ).includes( 'data.checkin_source' ) );

	// Token picker
	await page.click( '.wfc-node[data-id="mail"]' );
	await page.selectOption( '.wfc-token', 'data.checkin_source' );
	check( 'token picker inserts a placeholder', ( await graphNow() ).nodes.find( ( n ) => 'mail' === n.id ).config.subject.includes( '{{data.checkin_source}}' ) );

	check( 'notify node: no core mail for check-in is stated', ( await page.$eval( '.wfc-core-mails', ( x ) => x.textContent ) ).includes( 'keine eigene Mail' ) );
	await page.click( '.wfc-node[data-id="t"]' );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'pass.order.paid' );
	await page.press( '.wfc-inspector input[list="wfc-events"]', 'Tab' );
	await page.click( '.wfc-node[data-id="mail"]' );
	check( 'notify node lists the core mail of the trigger event', ( await page.$eval( '.wfc-core-mails', ( x ) => x.textContent ) ).includes( 'Käufer: tickets' ) );
	check( 'operator message is not flagged when the core mail goes to the buyer', 0 === ( await page.$$( '.wfc-core-mails-duplicate' ) ).length );
	await page.selectOption( '.wfc-inspector select', 'notify.user' );
	check( 'customer message is flagged as possible duplicate of the buyer mail', 1 === ( await page.$$( '.wfc-core-mails-duplicate' ) ).length );
	await page.selectOption( '.wfc-inspector select', 'notify.admin' );
	await page.click( '.wfc-node[data-id="t"]' );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'pass.ticket.checked_in' );
	await page.press( '.wfc-inspector input[list="wfc-events"]', 'Tab' );

	// Zoom
	const width = async () => parseInt( await page.$eval( '.wfc-canvas', ( s ) => s.getAttribute( 'width' ) ), 10 );
	const w1 = await width();
	await page.click( '.wfc-zoom-in' );
	check( 'zoom enlarges the canvas', ( await width() ) > w1 );
	await page.click( '.wfc-zoom-reset' );
	check( 'zoom reset returns to 100 %', w1 === await width() );

	check( 'linear view lists the steps in order', items.length > 0 && items[ 0 ].startsWith( 'Auslöser' ) );
	check( 'nodes are focusable with a label', 'Nachricht an Betreiber' === await page.$eval( '.wfc-node[data-id="mail"]', ( n ) => n.getAttribute( 'aria-label' ) ) );
	check( 'no JavaScript errors', 0 === errors.length );
	if ( errors.length ) {
		console.log( errors );
	}
	await browser.close();
	fs.unlinkSync( file );
	console.log( failures ? failures + ' failed' : 'all passed' );
	process.exit( failures ? 1 : 0 );
} )();
