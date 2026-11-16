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
	sources: {
		products: { 'pass-captain': { label: 'Pass Captain', version: '1.4.0', contract: '1.0' }, 'workflow-captain': { label: 'Workflow Captain', version: '0.2.0', contract: '1.0' } },
		events: { 'pass.ticket.checked_in': 'pass-captain', 'pass.order.paid': 'pass-captain', 'workflow.schedule.due': 'workflow-captain' },
		actions: { 'notify.admin': 'workflow-captain', 'workflow.tag.set': 'workflow-captain', 'notify.user': 'workflow-captain' },
	},
	timezone: 'Europe/Berlin',
	locale: 'de-DE',
	focusNode: 'mail',
	i18n: {},
};
// Expected next due times, computed by Schedule::upcoming() in PHP (tests/engine-tests.php checks the same cases).
const scheduleCases = [
	[ { frequency: 'daily', time: '02:30' }, '2026-03-27T12:00:00Z', 'Europe/Berlin', [ '2026-03-28T01:30:00Z', '2026-03-29T01:30:00Z', '2026-03-30T00:30:00Z' ] ],
	[ { frequency: 'daily', time: '02:30' }, '2026-10-24T12:00:00Z', 'Europe/Berlin', [ '2026-10-25T00:30:00Z', '2026-10-26T01:30:00Z', '2026-10-27T01:30:00Z' ] ],
	[ { frequency: 'weekly', time: '07:30', weekday: 1 }, '2026-11-16T08:00:00Z', 'Europe/Berlin', [ '2026-11-23T06:30:00Z', '2026-11-30T06:30:00Z', '2026-12-07T06:30:00Z' ] ],
	[ { frequency: 'monthly', time: '23:15', day: 28 }, '2026-01-30T00:00:00Z', 'America/New_York', [ '2026-03-01T04:15:00Z', '2026-03-29T03:15:00Z', '2026-04-29T03:15:00Z' ] ],
	[ { frequency: 'daily', time: '02:30' }, '2026-03-07T12:00:00Z', 'America/New_York', [ '2026-03-08T07:30:00Z', '2026-03-09T06:30:00Z', '2026-03-10T06:30:00Z' ] ],
	[ { frequency: 'daily', time: '01:30' }, '2026-10-31T12:00:00Z', 'America/New_York', [ '2026-11-01T05:30:00Z', '2026-11-02T06:30:00Z', '2026-11-03T06:30:00Z' ] ],
	[ { frequency: 'weekly', time: '00:00', weekday: 7 }, '2026-04-03T12:00:00Z', 'Australia/Sydney', [ '2026-04-04T13:00:00Z', '2026-04-11T14:00:00Z', '2026-04-18T14:00:00Z' ] ],
	[ { frequency: 'daily', time: '09:00' }, '2026-11-16T08:00:00Z', 'UTC', [ '2026-11-16T09:00:00Z', '2026-11-17T09:00:00Z', '2026-11-18T09:00:00Z' ] ],
	[ { frequency: 'daily', time: '09:00' }, '2026-11-16T08:00:00Z', '+05:30', [ '2026-11-17T03:30:00Z', '2026-11-18T03:30:00Z', '2026-11-19T03:30:00Z' ] ],
	[ { frequency: 'weekly', time: '07:30' }, '2026-11-16T08:00:00Z', 'Europe/Berlin', [] ],
];
const css = 'file://' + path.resolve( __dirname, '../assets/css/builder.css' );
const js = 'file://' + path.resolve( __dirname, '../assets/js/builder.js' );
const html = [
	'<!doctype html><html lang="de"><head><meta charset="utf-8">',
	'<link rel="stylesheet" href="' + css + '"></head><body>',
	'<textarea id="wfc-graph">' + JSON.stringify( graph ) + '</textarea>',
	'<textarea id="wfc-sample">' + JSON.stringify( { data: { device_id: 7 } } ) + '</textarea>',
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

	// Origin and short description on the node, jump from a run step (docs/UI-ENTWUERFE.md, sections 2 and 5)
	check( 'trigger node shows its provider and kind', 'PASS CAPTAIN · AUSLÖSER' === await page.$eval( '.wfc-node[data-id="t"] .wfc-node-kicker', ( x ) => x.textContent ) );
	check( 'action node shows its origin', 'WORKFLOW CAPTAIN · AKTION' === await page.$eval( '.wfc-node[data-id="mail"] .wfc-node-kicker', ( x ) => x.textContent ) );
	check( 'action node shows a short description instead of the ID', 'Einlass' === await page.$eval( '.wfc-node[data-id="mail"] .wfc-node-sub', ( x ) => x.textContent ) && 0 === ( await page.$$( '.wfc-node-id' ) ).length );
	const tipT = await page.$eval( '.wfc-node[data-id="t"] title', ( x ) => x.textContent );
	check( 'node tooltip names the contract version and the ID', tipT.includes( 'Vertrag 1.0' ) && tipT.includes( 'ID: t' ) );
	check( 'node from the run is marked and selected', 1 === ( await page.$$( '.wfc-node.is-focus.is-selected[data-id="mail"]' ) ).length );
	check( 'jump to the node is announced in the live region', ( await page.$eval( '.wfc-builder [aria-live]', ( x ) => x.textContent ) ).includes( 'Knoten aus dem Lauf markiert' ) );
	check( 'inspector names origin, plugin and contract version', ( await page.$eval( '.wfc-origin', ( x ) => x.textContent ) ).includes( 'Herkunft: Workflow Captain 0.2.0, Vertrag 1.0' ) && 1 === ( await page.$$( '.wfc-focus-note' ) ).length );
	check( 'linear view marks the step from the run', 'step' === await page.$eval( '.wfc-linear li.is-focus', ( x ) => x.getAttribute( 'aria-current' ) ) );

	// Schedule preview in JavaScript gives the same times as Schedule::upcoming() in PHP, daylight saving included
	for ( const [ sched, now, tz, want ] of scheduleCases ) {
		const got = await page.evaluate( ( [ s, n, z ] ) => window.WorkflowCaptainBuilder.upcoming( s, Date.parse( n ), z, 3 ).map( ( t ) => new Date( t ).toISOString().replace( '.000Z', 'Z' ) ), [ sched, now, tz ] );
		check( 'schedule preview ' + tz + ' ' + JSON.stringify( sched ) + ' from ' + now, JSON.stringify( want ) === JSON.stringify( got ) );
	}

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
	check( 'condition node warns about the open else branch (its end node was deleted)', 1 === ( await page.$$( '.wfc-branch-open' ) ).length && ( await page.$eval( '.wfc-branch-open', ( x ) => x.textContent ) ).startsWith( 'Der Sonst-Ausgang' ) );
	check( 'field is a list, not free text', 'SELECT' === await page.$eval( '.wfc-rule-field', ( x ) => x.tagName ) );
	await page.selectOption( '.wfc-rule-field', 'data.device_id' );
	check( 'field list shows the example value from the sample event', 'Beispiel: 7' === await page.$eval( '.wfc-rule-example', ( x ) => x.textContent ) );
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
	const offered = await page.$$eval( '.wfc-rule:nth-of-type(1) .wfc-rule-field option', ( o ) => o.map( ( x ) => [ x.value, x.textContent, x.parentNode.tagName ] ) );
	check( 'condition form offers the catalog fields with data type, grouped', offered.some( ( [ v, t, p ] ) => 'data.checkin_source' === v && 'checkin_source · string' === t && 'OPTGROUP' === p ) && offered.some( ( [ v ] ) => 'subject.id' === v ) );
	check( 'field list is labelled', 'Feld' === await page.$eval( '.wfc-rule-field', ( x ) => x.getAttribute( 'aria-label' ) ) );
	await page.selectOption( '.wfc-rule:nth-of-type(1) .wfc-rule-field', '__custom' );
	check( 'other field switches to a focused text field', 'INPUT' === await page.$eval( '.wfc-rule:nth-of-type(1) .wfc-rule-field', ( x ) => x.tagName ) && await page.$eval( '.wfc-rule:nth-of-type(1) .wfc-rule-field', ( x ) => x === document.activeElement ) );
	await page.fill( '.wfc-rule:nth-of-type(1) .wfc-rule-field', 'data.custom_flag' );
	await page.press( '.wfc-rule:nth-of-type(1) .wfc-rule-field', 'Tab' );
	check( 'a path outside the catalog is kept and shown as such', 'data.custom_flag' === ( await graphNow() ).nodes.find( ( n ) => 'condition1' === n.id ).rules.items[ 0 ].field && ( await page.$eval( '.wfc-rule:nth-of-type(1) .wfc-rule-field', ( x ) => x.options[ x.selectedIndex ].textContent ) ).includes( 'nicht im Katalog' ) );
	check( 'condition node shows its first rule as short description', ( await page.$eval( '.wfc-node[data-id="condition1"] .wfc-node-sub', ( x ) => x.textContent ) ).startsWith( 'custom_flag ≥ 4' ) );

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

	// Schedule trigger (WF-CORE-04)
	await page.click( '.wfc-node[data-id="t"]' );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'workflow.schedule.due' );
	await page.press( '.wfc-inspector input[list="wfc-events"]', 'Tab' );
	await page.click( '.wfc-node[data-id="t"]' );
	await page.selectOption( '.wfc-schedule-frequency', 'weekly' );
	await page.selectOption( '.wfc-schedule-weekday', '3' );
	let sched = ( await graphNow() ).nodes.find( ( n ) => 't' === n.id ).schedule;
	check( 'schedule form writes frequency, time and weekday', 'weekly' === sched.frequency && '08:00' === sched.time && 3 === sched.weekday );
	const next = await page.$$eval( '.wfc-next time', ( t ) => t.map( ( x ) => [ x.getAttribute( 'datetime' ), x.textContent ] ) );
	check( 'schedule preview lists three Wednesdays at 08:00 in the site time zone', 3 === next.length && next.every( ( [ , text ] ) => text.startsWith( 'Mi' ) && text.endsWith( '08:00' ) ) );
	check( 'schedule preview has machine-readable times and a label', next.every( ( [ iso ] ) => /^\d{4}-\d\d-\d\dT0[67]:00:00Z$/.test( iso ) ) && 'wfc-next-label' === await page.$eval( '.wfc-next', ( x ) => x.getAttribute( 'aria-labelledby' ) ) );
	check( 'schedule change announces the next due time', ( await page.$eval( '.wfc-builder [aria-live]', ( x ) => x.textContent ) ).startsWith( 'Nächster Termin:' ) );
	check( 'schedule trigger summarises the schedule on the node', 'wöchentlich Mittwoch 08:00' === await page.$eval( '.wfc-node[data-id="t"] .wfc-node-sub', ( x ) => x.textContent ) );
	await page.selectOption( '.wfc-schedule-frequency', 'monthly' );
	sched = ( await graphNow() ).nodes.find( ( n ) => 't' === n.id ).schedule;
	check( 'schedule form: monthly drops the weekday and sets a day', 'monthly' === sched.frequency && undefined === sched.weekday && 1 === sched.day );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'pass.ticket.checked_in' );
	await page.press( '.wfc-inspector input[list="wfc-events"]', 'Tab' );

	// Serialisation (WF-CORE-05): no order hint without it, the hint names the key with it
	await page.click( '.wfc-node[data-id="t"]' );
	check( 'serialisation is off by default and shows no order hint', '' === await page.$eval( '.wfc-serialize', ( s ) => s.value ) && 0 === ( await page.$$( '.wfc-serialize-hint' ) ).length );
	await page.selectOption( '.wfc-serialize', 'subject' );
	check( 'serialisation by object is written to the trigger', 'subject' === ( await graphNow() ).nodes.find( ( n ) => 't' === n.id ).serialize );
	check( 'serialisation hint says there is no order across objects', ( await page.$eval( '.wfc-serialize-hint', ( x ) => x.textContent ) ).includes( 'keine Reihenfolge' ) );
	await page.selectOption( '.wfc-serialize', '' );
	check( 'switching serialisation off removes the key', undefined === ( await graphNow() ).nodes.find( ( n ) => 't' === n.id ).serialize );

	// Zoom
	const width = async () => parseInt( await page.$eval( '.wfc-canvas', ( s ) => s.getAttribute( 'width' ) ), 10 );
	const w1 = await width();
	await page.click( '.wfc-zoom-in' );
	check( 'zoom enlarges the canvas', ( await width() ) > w1 );
	await page.click( '.wfc-zoom-reset' );
	check( 'zoom reset returns to 100 %', w1 === await width() );

	check( 'linear view lists the steps in order', items.length > 0 && items[ 0 ].startsWith( 'Auslöser' ) );
	check( 'nodes are focusable with a label, origin and the run mark (config was reset by the action switch)', 'Nachricht an Betreiber (Workflow Captain), aus dem Lauf' === await page.$eval( '.wfc-node[data-id="mail"]', ( n ) => n.getAttribute( 'aria-label' ) ) );

	// Narrow screen: no horizontal page scroll
	await page.setViewportSize( { width: 360, height: 800 } );
	await page.click( '.wfc-linear-wrap summary' );
	check( 'narrow screen: no horizontal scrolling of the page', await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) );
	check( 'no JavaScript errors', 0 === errors.length );
	if ( errors.length ) {
		console.log( errors );
	}
	await browser.close();
	fs.unlinkSync( file );
	console.log( failures ? failures + ' failed' : 'all passed' );
	process.exit( failures ? 1 : 0 );
} )();
