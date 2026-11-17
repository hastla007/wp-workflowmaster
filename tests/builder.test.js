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
	// Codes of the last server check: one the builder does not test itself, one it does (shown once, from its own check).
	violations: { mail: [ 'invalid_to', 'needs_exactly_one_outgoing_edge' ] },
	conditions: { maxDepth: 2, maxRules: 20 },
	lastFired: { at: Date.parse( '2026-11-16T06:30:00Z' ), runs: 'admin.php?page=workflow-captain-runs&workflow=3' },
	i18n: {},
};
// Expected next due times, computed by Schedule::upcoming() in PHP (tests/engine-tests.php checks the same cases).
// Conditions::validate() in PHP gives these codes for the same rules (checked when the cases were written).
const R = ( field, operator, value ) => ( { field: field || 'data.a', operator: operator || 'eq', value: undefined === value ? 1 : value } );
const conditionCases = [
	[ { op: 'and', items: [ R() ] }, [] ],
	[ { op: 'and', items: [ R(), { op: 'or', items: [ R( 'type', 'eq', 'x' ), R( 'data.b', 'in', [ 1, 2 ] ) ] } ] }, [] ],
	[ { op: 'and', items: [ { op: 'or', items: [ { op: 'and', items: [ R() ] } ] } ] }, [ 'condition:too_deep' ] ],
	[ { op: 'and', items: [ { op: 'or', items: [] } ] }, [ 'condition:invalid_group' ] ],
	[ { op: 'xor', items: [ R() ] }, [ 'condition:invalid_group' ] ],
	[ { op: 'and', items: [ R( 'data.' ), R( 'data.a', 'gt', '5' ), R( 'data.a', 'in', 'x' ), R( 'data.a', 'eq', [ 1 ] ), R( 'data.a', 'bogus' ) ] }, [ 'condition:invalid_field', 'condition:number_expected', 'condition:list_expected', 'condition:scalar_expected', 'condition:invalid_operator' ] ],
	[ { op: 'and', items: Array.from( { length: 21 }, () => R() ) }, [ 'condition:too_many_rules' ] ],
	[ { op: 'and', items: [ R( 'data.a', 'exists', null ), R( 'actor_user_id', 'eq', null ) ] }, [] ],
];
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
	const file = path.join( os.tmpdir(), 'wfc-builder-test-' + process.pid + '.html' );
	fs.writeFileSync( file, html );
	await page.goto( 'file://' + file );
	const graphNow = async () => JSON.parse( await page.$eval( '#wfc-graph', ( t ) => t.value ) );
	const nodeX = async ( id ) => ( await graphNow() ).nodes.find( ( n ) => id === n.id ).x;
	const focused = () => page.evaluate( () => {
		const a = document.activeElement;
		return ( a.getAttribute( 'data-id' ) ? a.tagName.toLowerCase() + '#' + a.getAttribute( 'data-id' ) : a.tagName.toLowerCase() + '.' + ( a.getAttribute( 'class' ) || '' ) );
	} );

	check( 'renders three nodes', 3 === ( await page.$$( '.wfc-node' ) ).length );
	check( 'renders two arrows', 2 === ( await page.$$( '.wfc-edge' ) ).length );

	// Origin and short description on the node, jump from a run step (docs/UI-ENTWUERFE.md, sections 2 and 5)
	check( 'trigger node shows its provider and kind', 'PASS CAPTAIN · AUSLÖSER' === await page.$eval( '.wfc-node[data-id="t"] .wfc-node-kicker', ( x ) => x.textContent ) );
	check( 'action node shows its origin', 'WORKFLOW CAPTAIN · AKTION' === await page.$eval( '.wfc-node[data-id="mail"] .wfc-node-kicker', ( x ) => x.textContent ) );
	check( 'action node shows a short description instead of the ID', 'Einlass' === await page.$eval( '.wfc-node[data-id="mail"] .wfc-node-sub', ( x ) => x.textContent ) && 0 === ( await page.$$( '.wfc-node-id' ) ).length );
	const tipT = await page.$eval( '.wfc-node[data-id="t"] title', ( x ) => x.textContent );
	check( 'node tooltip names the contract version and the ID', tipT.includes( 'Vertrag 1.0' ) && tipT.includes( 'ID: t' ) );
	check( 'node from the run is marked and selected', 1 === ( await page.$$( '.wfc-node.is-focus.is-selected[data-id="mail"]' ) ).length );
	check( 'jump to the node is announced in the live region', ( await page.$eval( '[aria-live]', ( x ) => x.textContent ) ).includes( 'Knoten aus dem Lauf markiert' ) );
	check( 'inspector names origin, plugin and contract version', ( await page.$eval( '.wfc-origin', ( x ) => x.textContent ) ).includes( 'Herkunft: Workflow Captain 0.2.0, Vertrag 1.0' ) && 1 === ( await page.$$( '.wfc-focus-note' ) ).length );
	check( 'linear view marks the step from the run', 'step' === await page.$eval( '.wfc-linear li.is-focus', ( x ) => x.getAttribute( 'aria-current' ) ) );

	// Warning signs on the node (docs/UI-ENTWUERFE.md, section 3)
	check( 'valid workflow: warning sign only where the last server check reported a code', 1 === ( await page.$$( '.wfc-node-warn-mark' ) ).length && 1 === ( await page.$$( '.wfc-node[data-id="mail"] .wfc-node-warn-mark' ) ).length );
	check( 'server code the builder does not check itself is shown in the inspector, covered codes are not repeated', 1 === ( await page.$$( '.wfc-problems li' ) ).length && ( await page.$eval( '.wfc-problems', ( x ) => x.textContent ) ).includes( 'Beim letzten Prüfen gemeldet: invalid_to' ) );
	for ( const [ rules, want ] of conditionCases ) {
		const got = await page.evaluate( ( r ) => window.WorkflowCaptainBuilder.conditionViolations( r, 2, 20 ), rules );
		check( 'condition check like Conditions::validate(): ' + JSON.stringify( want ), JSON.stringify( want ) === JSON.stringify( got ) );
	}

	// Schedule preview in JavaScript gives the same times as Schedule::upcoming() in PHP, daylight saving included
	for ( const [ sched, now, tz, want ] of scheduleCases ) {
		const got = await page.evaluate( ( [ s, n, z ] ) => window.WorkflowCaptainBuilder.upcoming( s, Date.parse( n ), z, 3 ).map( ( t ) => new Date( t ).toISOString().replace( '.000Z', 'Z' ) ), [ sched, now, tz ] );
		check( 'schedule preview ' + tz + ' ' + JSON.stringify( sched ) + ' from ' + now, JSON.stringify( want ) === JSON.stringify( got ) );
	}

	await page.click( '.wfc-node[data-id="mail"]' );
	await page.click( 'button[data-type="condition"]' );
	let g = await graphNow();
	check( 'palette adds a condition node', g.nodes.some( ( n ) => 'condition' === n.type ) );
	check( 'codes of the last server check are dropped after a change', 0 === ( await page.$$( '.wfc-problems li' ) ).length || ! ( await page.$eval( '.wfc-problems', ( x ) => x.textContent ) ).includes( 'invalid_to' ) );
	const condLabel = await page.$eval( '.wfc-node[data-id="condition1"]', ( x ) => x.getAttribute( 'aria-label' ) );
	check( 'new unconnected condition: warning sign on the node, warning in its name and tooltip', 1 === ( await page.$$( '.wfc-node[data-id="condition1"].has-problem .wfc-node-warn-mark' ) ).length && condLabel.includes( 'Warnung:' ) && condLabel.includes( 'Dann-Ausgang ist nicht verbunden' ) && condLabel.includes( 'Nicht mit dem Auslöser verbunden' ) && ( await page.$eval( '.wfc-node[data-id="condition1"] title', ( x ) => x.textContent ) ).includes( '⚠ Der Sonst-Ausgang' ) );
	check( 'warning sign is hidden from screen readers (the name carries it)', 'true' === await page.$eval( '.wfc-node[data-id="condition1"] .wfc-node-warn-mark', ( x ) => x.getAttribute( 'aria-hidden' ) ) );
	check( 'linear view repeats the warning as text', ( await page.$eval( '.wfc-linear li.wfc-unconnected', ( x ) => x.textContent ) ).includes( '⚠ Warnung:' ) );
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
	// The rebuild after a text field change waits for the focus move (setTimeout 0); wait for it before reading.
	await page.waitForSelector( '.wfc-rule:nth-of-type(1) select.wfc-rule-field' );
	check( 'a path outside the catalog is kept and shown as such', 'data.custom_flag' === ( await graphNow() ).nodes.find( ( n ) => 'condition1' === n.id ).rules.items[ 0 ].field && ( await page.$eval( '.wfc-rule:nth-of-type(1) .wfc-rule-field', ( x ) => x.options[ x.selectedIndex ].textContent ) ).includes( 'nicht im Katalog' ) );
	check( 'condition node shows its first rule as short description', ( await page.$eval( '.wfc-node[data-id="condition1"] .wfc-node-sub', ( x ) => x.textContent ) ).startsWith( 'custom_flag ≥ 4' ) );

	// "+ Gruppe": groups of rules in the form, as deep as the engine allows (docs/UI-ENTWUERFE.md, section 3)
	const cond = async () => ( await graphNow() ).nodes.find( ( n ) => 'condition1' === n.id ).rules;
	await page.click( '.wfc-group-add' );
	let rules = await cond();
	check( '+ Gruppe adds a group with one rule and its own OR', 3 === rules.items.length && 'or' === rules.items[ 2 ].op && 1 === rules.items[ 2 ].items.length && ! rules.items[ 2 ].items[ 0 ].op );
	check( '+ Gruppe moves the focus to the field of the new rule', await page.$eval( '.wfc-rule-group .wfc-rule-field', ( x ) => x === document.activeElement ) );
	check( 'group is a fieldset with legend and a labelled combination', 'Gruppe 1' === await page.$eval( '.wfc-rule-group legend', ( x ) => x.textContent ) && 'Verknüpfung in der Gruppe' === await page.$eval( '.wfc-rule-group .wfc-group-op', ( x ) => document.querySelector( 'label[for="' + x.id + '"]' ).textContent ) );
	check( 'no "+ Gruppe" inside a group (engine allows one level, condition:too_deep)', 0 === ( await page.$$( '.wfc-rule-group .wfc-group-add' ) ).length && 1 === ( await page.$$( '.wfc-group-add' ) ).length );
	check( '"+ Gruppe" is explained', ( await page.$eval( '#wfc-group-hint', ( x ) => x.textContent ) ).includes( 'keine weitere Gruppe' ) && 'wfc-group-hint' === await page.$eval( '.wfc-group-add', ( x ) => x.getAttribute( 'aria-describedby' ) ) );
	await page.selectOption( '.wfc-rule-group .wfc-rule-field', 'data.area_id' );
	await page.selectOption( '.wfc-rule-group .wfc-rule-op', 'neq' );
	await page.fill( '.wfc-rule-group .wfc-rule-value', '3' );
	await page.press( '.wfc-rule-group .wfc-rule-value', 'Tab' );
	await page.waitForTimeout( 20 );
	await page.click( '.wfc-group-rule-add' );
	await page.selectOption( '.wfc-rule-group .wfc-group-op', 'and' );
	rules = await cond();
	check( 'rules inside the group are written, with a second rule and the group combination', 'and' === rules.items[ 2 ].op && 2 === rules.items[ 2 ].items.length && 'data.area_id' === rules.items[ 2 ].items[ 0 ].field && 'neq' === rules.items[ 2 ].items[ 0 ].operator && 3 === rules.items[ 2 ].items[ 0 ].value );
	check( 'top-level rules keep their positions (nth-of-type counts rule rows only)', 'data.custom_flag' === rules.items[ 0 ].field && 2 === ( await page.$$( '.wfc-rules > .wfc-rule' ) ).length );
	check( 'condition with a group has no rule warning', ! ( await page.$eval( '.wfc-node[data-id="condition1"]', ( x ) => x.getAttribute( 'aria-label' ) ) ).includes( 'gültiges Feld' ) );
	await page.click( '.wfc-rule-group .wfc-rule-remove' );
	await page.click( '.wfc-rule-group .wfc-rule-remove' );
	rules = await cond();
	check( 'removing the last rule of a group removes the group and keeps the focus in the form', 2 === rules.items.length && 0 === ( await page.$$( '.wfc-rule-group' ) ).length && await page.$eval( '.wfc-group-add', ( x ) => x === document.activeElement ) );
	await page.evaluate( () => {
		const b = window.workflowCaptainBuilderInstance;
		b.node( 'condition1' ).rules.items.push( { op: 'or', items: [ { op: 'and', items: [ { field: 'type', operator: 'eq', value: 'x' } ] } ] } );
		b.commit();
	} );
	check( 'deeper groups fall back to JSON and the node warns (condition:too_deep)', 0 === ( await page.$$( '.wfc-rules' ) ).length && 1 === ( await page.$$( '.wfc-inspector textarea.code' ) ).length && ( await page.$eval( '.wfc-node[data-id="condition1"]', ( x ) => x.getAttribute( 'aria-label' ) ) ).includes( 'zu tief verschachtelt' ) && ( await page.$eval( '.wfc-problems', ( x ) => x.textContent ) ).includes( 'zu tief' ) );
	await page.evaluate( () => {
		const b = window.workflowCaptainBuilderInstance;
		b.node( 'condition1' ).rules.items.pop();
		b.commit();
	} );
	await page.evaluate( () => {
		const b = window.workflowCaptainBuilderInstance;
		const items = b.node( 'condition1' ).rules.items;
		while ( items.length < 20 ) {
			items.push( { field: 'type', operator: 'eq', value: 'x' } );
		}
		b.commit();
	} );
	check( 'at 20 rules "+ Regel" and "+ Gruppe" are disabled and the limit is named', await page.$eval( '.wfc-rule-add', ( x ) => x.disabled ) && await page.$eval( '.wfc-group-add', ( x ) => x.disabled ) && ( await page.$eval( '#wfc-group-hint', ( x ) => x.textContent ) ).includes( 'Höchstens 20 Regeln' ) );
	await page.evaluate( () => {
		const b = window.workflowCaptainBuilderInstance;
		b.node( 'condition1' ).rules.items.splice( 2 );
		b.commit();
	} );

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
	check( 'schedule form names the last fired time with a link to the runs', '2026-11-16T06:30:00Z' === await page.$eval( '.wfc-last-fired time', ( x ) => x.getAttribute( 'datetime' ) ) && ( await page.$eval( '.wfc-last-fired', ( x ) => x.textContent ) ).startsWith( 'Zuletzt ausgelöst: Mo., 16.11.2026, 07:30' ) && 1 === ( await page.$$( '.wfc-last-fired a[href*="workflow=3"]' ) ).length );
	check( 'schedule change announces the next due time', ( await page.$eval( '[aria-live]', ( x ) => x.textContent ) ).startsWith( 'Nächster Termin:' ) );
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

	// Drag from the palette onto the canvas (workflow-builder.svg); click and Enter stay the keyboard way
	check( 'palette buttons are draggable and describe both ways', 'true' === await page.$eval( 'button[data-type="action"]', ( x ) => x.getAttribute( 'draggable' ) ) && ( await page.$eval( '#' + await page.$eval( 'button[data-type="action"]', ( x ) => x.getAttribute( 'aria-describedby' ) ), ( x ) => x.textContent ) ).includes( 'Tastatur: Enter' ) );
	await page.$eval( '.wfc-canvas-wrap', ( w ) => w.scrollTo( 0, 0 ) );
	// The drop point is the centre of the new node (180 x 64), snapped to 10 px; an occupied spot moves right.
	const spot = await page.evaluate( () => window.workflowCaptainBuilderInstance.freeSpot( 430, 230 ) );
	await page.dragAndDrop( 'button[data-type="action"]', '.wfc-canvas', { targetPosition: { x: 522, y: 263 } } );
	const dropped = ( await graphNow() ).nodes.find( ( n ) => 'action1' === n.id );
	check( 'dragging "+ Aktion" onto the canvas adds the node where it was dropped, without hiding another', !! dropped && spot[ 0 ] === dropped.x && spot[ 1 ] === dropped.y && 230 === dropped.y );
	check( 'dropped node has the focus and is announced', 'g#action1' === await focused() && ( await page.$eval( '[aria-live]', ( x ) => x.textContent ) ).startsWith( 'Knoten hinzugefügt' ) );
	await page.keyboard.press( 'ArrowDown' );
	check( 'dropped node moves with the arrow keys', 240 === ( await graphNow() ).nodes.find( ( n ) => 'action1' === n.id ).y );
	await page.keyboard.press( 'Delete' );

	// Zoom
	const width = async () => parseInt( await page.$eval( '.wfc-canvas', ( s ) => s.getAttribute( 'width' ) ), 10 );
	const w1 = await width();
	await page.click( '.wfc-zoom-in' );
	check( 'zoom enlarges the canvas', ( await width() ) > w1 );
	await page.click( '.wfc-zoom-reset' );
	check( 'zoom reset returns to 100 %', w1 === await width() );

	// Focus management (WF-BUILDER-09, E2E second run): a rebuild never drops the focus to the page start.
	const live = await page.$( '[aria-live]' );
	await page.focus( 'button[data-type="delay"]' );
	await page.keyboard.press( 'Enter' );
	check( 'focus: after adding from the palette the new node has the focus', 'g#delay1' === await focused() );
	check( 'live region survives the rebuild and announces the new node', await live.evaluate( ( n ) => n.isConnected && n.textContent.includes( 'Warten' ) ) );
	await page.focus( '.wfc-node[data-id="mail"]' );
	await page.keyboard.press( 'Enter' );
	await page.focus( '.wfc-inspector input[type="text"]' );
	await page.keyboard.type( ' X' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 20 );
	check( 'focus: Tab after editing an inspector field reaches the next control, not the page start', 'body.' !== ( await focused() ).slice( 0, 5 ) && ( await graphNow() ).nodes.find( ( n ) => 'mail' === n.id ).config.subject.endsWith( ' X' ) );
	await page.focus( '.wfc-node[data-id="delay1"]' );
	await page.keyboard.press( 'Delete' );
	check( 'focus: after Delete the focus is on a remaining node', ( await focused() ).startsWith( 'g#' ) );

	// Small screen: the canvas is hidden, the linear view is the way to edit (WF-BUILDER-09).
	const small = await browser.newPage( { viewport: { width: 390, height: 800 } } );
	await small.goto( 'file://' + file );
	check( 'small screen: canvas hidden, linear view shown', ! ( await small.isVisible( '.wfc-canvas' ) ) && await small.isVisible( '.wfc-linear-wrap summary' ) );
	await small.focus( '.wfc-linear-wrap summary' );
	// The list may already be open (the page marks the step from a run on small screens); open it only if closed.
	if ( ! ( await small.$eval( '.wfc-linear-wrap', ( d ) => d.open ) ) ) {
		await small.keyboard.press( 'Enter' );
	}
	await small.keyboard.press( 'Tab' );
	await small.keyboard.press( 'Tab' );
	await small.keyboard.press( 'Enter' );
	const smallState = await small.evaluate( () => ( { open: document.querySelector( '.wfc-linear-wrap' ).open, focus: document.activeElement.tagName + ':' + document.activeElement.textContent, inspector: document.querySelector( '.wfc-inspector h3' ).textContent } ) );
	check( 'small screen: choosing a step keeps the list open and moves the focus to the inspector heading', smallState.open && 'H3:Nachricht an Betreiber' === smallState.focus && 'Nachricht an Betreiber' === smallState.inspector );
	await small.close();

	check( 'linear view lists the steps in order', items.length > 0 && items[ 0 ].startsWith( 'Auslöser' ) );
	check( 'nodes are focusable with a label, origin and the run mark (the subject edited in the focus test may be part of it)', /^Nachricht an Betreiber(, X)? \(Workflow Captain\), aus dem Lauf$/.test( await page.$eval( '.wfc-node[data-id="mail"]', ( n ) => n.getAttribute( 'aria-label' ) ) ) );

	// Texts on the nodes stay inside the node with the real fonts (E2E third run: "WORKFLOW CAPTAIN · AUSLÖSER" ran over
	// the edge in wp-admin): a long event name is shortened with "…", the full name stays in the tooltip.
	await page.click( '.wfc-node[data-id="t"]' );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'pass.mmmmmmmmmmmmmmmmmmmmmmmmmm.wwwwwwwwww' );
	await page.$eval( '.wfc-inspector input[list="wfc-events"]', ( i ) => i.dispatchEvent( new Event( 'change', { bubbles: true } ) ) );
	// The texts are shortened after the rebuild has been laid out (measured in the next frame); wait for it.
	await page.waitForFunction( () => ( document.querySelector( '.wfc-node[data-id="t"] .wfc-node-title' ) || {} ).textContent?.endsWith( '…' ), null, { timeout: 3000 } ).catch( () => {} );
	const fit = await page.evaluate( () => ( {
		widest: Math.max( ...Array.from( document.querySelectorAll( '.wfc-node-kicker, .wfc-node-title, .wfc-node-sub' ) ).map( ( t ) => t.getComputedTextLength() ) ),
		title: document.querySelector( '.wfc-node[data-id="t"] .wfc-node-title' ).textContent,
		tip: document.querySelector( '.wfc-node[data-id="t"] title' ).textContent,
	} ) );
	check( 'node texts fit inside the node (at most 166 px), long ones end with "…", the tooltip keeps the full text', fit.widest <= 166 && fit.title.endsWith( '…' ) && fit.tip.includes( 'pass.mmmmmmmmmmmmmmmmmmmmmmmmmm.wwwwwwwwww' ) );
	await page.fill( '.wfc-inspector input[list="wfc-events"]', 'pass.ticket.checked_in' );
	await page.$eval( '.wfc-inspector input[list="wfc-events"]', ( i ) => i.dispatchEvent( new Event( 'change', { bubbles: true } ) ) );

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
