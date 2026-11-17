/**
 * WF-BUILDER-12 in the real browser (not shipped: /bin is export-ignore): workflows are built on the canvas of the real
 * wp-admin, saved as draft, re-opened, published, and every path runs for real (orders through WC CRUD, worker through
 * "wp workflow-captain process"); the results are read on the "Läufe" page.
 *
 *     E2E_URL=http://127.0.0.1:8080 E2E_SHOTS=/tmp/shots NODE_PATH=$(npm root -g) node bin/e2e/builder-flows.js
 *
 * Paths: Dann/Sonst, nested condition at the allowed depth (and one level too deep), Warten (delay), Abbruch,
 * Fehler/Wiederholung, unklarer Ausgang mit Bestätigung, Entwurf/Veröffentlichen (a draft change does not reach new
 * runs before it is published), Wiederöffnen. One JSON line per check; exit 1 when one failed.
 */
'use strict';

const { chromium } = require( 'playwright' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const ROOT = process.env.E2E_ROOT || '/home/user/wp-e2e';
const OPS = path.join( __dirname, 'ops.php' );
const MAILS = process.env.E2E_MAIL_FILE || path.join( ROOT, 'logs/mail.jsonl' );
const A = '/wp-admin/admin.php?page=';

let failed = 0;
function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: 'string' === typeof detail ? detail : JSON.stringify( detail || '' ) };
	if ( ! ok ) {
		failed++;
	}
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
	return ok;
}

// WP-CLI on the live test database (never a copy: E2E_DB empty), mails into the default log the web server uses too.
function wp( ...args ) {
	return execFileSync( 'wp', args, { env: { ...process.env, E2E_DB: '', E2E_MAIL_FILE: MAILS }, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
}
function ops( section, param ) {
	return wp( 'eval-file', OPS, section, String( param || '' ) );
}
function order( total, method ) {
	return ( ops( 'b12_order', total + ',' + ( method || 'bacs' ) ).match( /ORDER=(\d+)/ ) || [] )[ 1 ];
}
function work( times ) {
	for ( let i = 0; i < ( times || 3 ); i++ ) {
		wp( 'workflow-captain', 'process' );
	}
}
function runs( wf ) {
	const m = ops( 'b12_runs', wf ).match( /JSON=(.*)/ );
	return m ? JSON.parse( m[ 1 ] ) : [];
}
function mails( needle ) {
	if ( ! fs.existsSync( MAILS ) ) {
		return [];
	}
	return fs.readFileSync( MAILS, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ).subject ).filter( ( s ) => s.includes( needle ) );
}

// ----- builder helpers: everything through the canvas and the inspector, like a user -------------------------------
async function select( page, id ) {
	await page.click( `.wfc-canvas .wfc-node[data-id="${ id }"]` );
}
async function setInput( locator, value ) {
	// Since the third run the rule field is a list of the catalog fields (select); other inputs are typed.
	if ( 'SELECT' === await locator.evaluate( ( e ) => e.tagName ) ) {
		await locator.selectOption( String( value ) );
		return;
	}
	await locator.fill( String( value ) );
	await locator.dispatchEvent( 'change' );
}
async function connect( page, from, to, which ) {
	await select( page, from );
	const label = 'then' === which ? 'Dann verbinden …' : ( 'else' === which ? 'Sonst verbinden …' : 'Verbinden mit …' );
	await page.locator( '.wfc-inspector button', { hasText: label } ).click();
	await page.click( `.wfc-canvas .wfc-node[data-id="${ to }"]` );
}
async function add( page, type ) {
	const before = await page.$$eval( '.wfc-canvas .wfc-node', ( ns ) => ns.map( ( n ) => n.dataset.id ) );
	await page.click( `.wfc-add[data-type="${ type }"]` );
	const after = await page.$$eval( '.wfc-canvas .wfc-node', ( ns ) => ns.map( ( n ) => n.dataset.id ) );
	return after.find( ( id ) => ! before.includes( id ) );
}
async function setAction( page, id, key, fields ) {
	await select( page, id );
	const sel = page.locator( '.wfc-inspector select' ).first();
	if ( ( await sel.inputValue() ) !== key ) {
		await sel.selectOption( key ); // a new action starts with an empty configuration
	}
	for ( const [ label, value ] of Object.entries( fields ) ) {
		const p = page.locator( '.wfc-inspector p', { has: page.locator( 'label', { hasText: new RegExp( '^' + label + '$' ) } ) } );
		const input = p.locator( 'input[type=text], textarea' ).first();
		await setInput( input, value );
	}
}
async function setTrigger( page, id, event ) {
	await select( page, id );
	await setInput( page.locator( '.wfc-inspector input[list="wfc-events"]' ), event );
}
async function graph( page ) {
	return JSON.parse( await page.locator( '#wfc-graph' ).inputValue() );
}
async function submit( page, value ) {
	// Header and form both have publish and test run since the third run; the form button is the last one.
	await Promise.all( [ page.waitForNavigation(), page.locator( `button[name="do"][value="${ value }"]` ).last().click() ] );
	const id = ( page.url().match( /edit=(\d+)/ ) || [] )[ 1 ];
	return id ? parseInt( id, 10 ) : 0;
}
async function notice( page ) {
	return ( await page.locator( '.wrap .notice' ).allInnerTexts() ).join( ' | ' ).replace( /\s+/g, ' ' ).trim();
}
async function runPage( page, runId ) {
	await page.goto( BASE + A + 'workflow-captain-runs&run=' + runId );
	const heading = await page.locator( '.wrap h2' ).first().innerText();
	// Since the third run: a table of run facts first, then the timeline (step, state label, created, waits until,
	// ended, attempts, result, error). Rows are mapped to the old shape [node, state, attempts, due, result, error].
	const states = { Wartet: 'queued', 'Läuft': 'running', 'Wartet auf Wiederholung': 'retry_wait', Erledigt: 'succeeded', 'Aktion erforderlich': 'action_required', Pausiert: 'paused', Abgebrochen: 'cancelled' };
	const raw = await page.$$eval( '.wrap table.widefat:not(.wfc-run-meta) tbody tr', ( trs ) => trs.map( ( tr ) => Array.from( tr.cells ).map( ( c ) => ( c.querySelector( 'code' ) && 'TH' === c.tagName ? c.querySelector( 'code' ).innerText : c.innerText ).trim() ) ) );
	const rows = raw.map( ( r ) => [ r[ 0 ], states[ r[ 1 ] ] || r[ 1 ], r[ 5 ], r[ 3 ] || r[ 2 ], r[ 6 ], r[ 7 ], '' ] );
	return { heading, rows };
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	fs.writeFileSync( MAILS, '' );
	const browser = await chromium.launch();
	const page = await ( await browser.newContext( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 } ) ).newPage();
	const jsErrors = [];
	page.on( 'pageerror', ( e ) => jsErrors.push( String( e ) ) );
	page.on( 'dialog', ( d ) => d.accept() );
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', process.env.E2E_USER || 'admin' );
	await page.fill( '#user_pass', process.env.E2E_PASS || 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	// ===== 1. Dann/Sonst, built on the canvas ========================================================================
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=new' );
	await page.fill( '#wfc-name', 'B12 Dann Sonst' );
	await setTrigger( page, 'start', 'woocommerce.order.paid' );
	const cond = await add( page, 'condition' );
	await select( page, cond );
	await setInput( page.locator( '.wfc-inspector .wfc-rule-field' ), 'data.total_minor' );
	await page.locator( '.wfc-inspector .wfc-rule-op' ).selectOption( 'gte' );
	await setInput( page.locator( '.wfc-inspector .wfc-rule-value' ), '5000' );
	await setAction( page, 'mail', 'notify.admin', { Betreff: 'B12 gross {{subject.id}}' } );
	const tag = await add( page, 'action' );
	await setAction( page, tag, 'workflow.tag.set', { Markierung: 'b12-klein' } );
	await connect( page, 'start', cond );
	await connect( page, cond, 'mail', 'then' );
	await connect( page, cond, tag, 'else' );
	await connect( page, tag, 'end' );
	let g = await graph( page );
	const edges = g.edges.map( ( e ) => e.from + '>' + e.to + ( e.branch ? ':' + e.branch : '' ) ).sort();
	check( 'b1', 'canvas: trigger, Wenn/Sonst with a rule from the form, Dann and Sonst connected by clicks', JSON.stringify( edges ) === JSON.stringify( [ `${ cond }>${ tag }:else`, `${ cond }>mail:then`, 'mail>end', `start>${ cond }`, `${ tag }>end` ].sort() ) && 'gte' === g.nodes.find( ( n ) => n.id === cond ).rules.items[ 0 ].operator, edges );
	await page.locator( '.wfc-linear-wrap summary' ).click();
	const linear = await page.locator( '.wfc-linear li' ).allInnerTexts();
	check( 'b1b', 'linear step view lists both branches with Dann/Sonst', linear.some( ( t ) => t.startsWith( 'Dann: ' ) ) && linear.some( ( t ) => t.startsWith( 'Sonst: ' ) ), linear );
	await page.screenshot( { path: path.join( SHOTS, 'b12-dann-sonst.png' ), fullPage: true } );
	const wf1 = await submit( page, 'save' );
	const draftOrder = order( 99 );
	work();
	check( 'b2', 'draft saved; an order paid now starts no run of the draft', wf1 > 0 && 0 === runs( wf1 ).length && /Entwurf|noch nicht veröffentlicht/.test( await page.locator( '.wfc-editor-meta' ).innerText() ), { wf1, draftOrder } );

	// Re-open: the saved graph comes back with the same nodes, edges and positions.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf1 );
	const g2 = await graph( page );
	check( 'b3', 'Wiederöffnen: nodes, edges, rule and canvas positions as saved', JSON.stringify( g2.nodes.map( ( n ) => [ n.id, n.x, n.y ] ) ) === JSON.stringify( g.nodes.map( ( n ) => [ n.id, n.x, n.y ] ) ) && g2.edges.length === g.edges.length && 5000 === g2.nodes.find( ( n ) => n.id === cond ).rules.items[ 0 ].value, g2.nodes.map( ( n ) => n.id ) );

	// Nested condition: the form edits one level; groups are entered as JSON (graph field), then shown as JSON rules.
	g = g2;
	g.nodes.find( ( n ) => n.id === cond ).rules = { op: 'and', items: [ { field: 'data.total_minor', operator: 'gte', value: 5000 }, { op: 'or', items: [ { field: 'data.payment_method', operator: 'eq', value: 'bacs' }, { field: 'data.payment_method', operator: 'eq', value: 'cod' } ] } ] };
	const tooDeep = JSON.parse( JSON.stringify( g ) );
	tooDeep.nodes.find( ( n ) => n.id === cond ).rules.items[ 1 ].items.push( { op: 'and', items: [ { field: 'type', operator: 'exists' } ] } );
	await page.locator( '#wfc-graph' ).fill( JSON.stringify( tooDeep ) );
	await submit( page, 'publish' );
	const deepNotice = await notice( page );
	check( 'b4', 'nested condition one level too deep: publishing refused with condition:too_deep', deepNotice.includes( 'condition:too_deep' ) && runs( wf1 ).length === 0, deepNotice );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf1 );
	await page.locator( '#wfc-graph' ).fill( JSON.stringify( g ) );
	await submit( page, 'save' );
	await select( page, cond );
	// Since the third run the form shows groups ("+ Gruppe"); before, a group fell back to JSON.
	check( 'b5', 'nested group within the limit: inspector shows the group in the form (fieldset with its own AND/OR)', 1 === await page.locator( '.wfc-inspector .wfc-rule-group' ).count() && 'or' === await page.locator( '.wfc-inspector .wfc-group-op' ).inputValue() );
	await submit( page, 'publish' );
	const pubNotice = await notice( page );
	check( 'b6', 'Prüfen und veröffentlichen: published (notice, state Aktiv)', pubNotice.includes( 'Veröffentlicht' ) && ( await page.locator( '.wfc-editor-meta' ).innerText() ).startsWith( 'Aktiv' ), pubNotice );
	const big = order( 99, 'bacs' );
	const bigCheque = order( 99, 'cheque' );
	const small = order( 20, 'bacs' );
	work();
	const r1 = runs( wf1 );
	const byOrder = Object.fromEntries( r1.map( ( r ) => [ r.order, r ] ) );
	const pathOf = ( r ) => ( r ? r.steps.map( ( s ) => s[ 0 ] ).join( '>' ) : '' );
	check( 'b7', 'real runs: 99 EUR bacs takes Dann (mail), 99 EUR cheque and 20 EUR bacs take Sonst (tag); all succeeded', byOrder[ big ] && 'succeeded' === byOrder[ big ].state && pathOf( byOrder[ big ] ) === `${ cond }>mail>end` && pathOf( byOrder[ bigCheque ] ) === `${ cond }>${ tag }>end` && pathOf( byOrder[ small ] ) === `${ cond }>${ tag }>end` && 3 === r1.length && r1.every( ( r ) => 'succeeded' === r.state ), r1.map( ( r ) => [ r.order, r.state, pathOf( r ) ] ) );
	check( 'b7b', 'mail only for the Dann path, exactly once', 1 === mails( 'B12 gross ' + big ).length && 0 === mails( 'B12 gross ' + bigCheque ).length && 0 === mails( 'B12 gross ' + small ).length );
	const rp = await runPage( page, byOrder[ big ].id );
	check( 'b7c', 'run page in the browser: Erfolgreich, condition step with outcome "then"', rp.heading.includes( 'Erfolgreich' ) && rp.rows.some( ( r ) => r[ 0 ] === cond && r[ 4 ].includes( '"outcome":"then"' ) ), rp );
	await page.screenshot( { path: path.join( SHOTS, 'b12-run-then.png' ), fullPage: true } );

	// Draft change on a published workflow: new runs keep the published version until it is published again.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf1 );
	await setAction( page, 'mail', 'notify.admin', { Betreff: 'B12 neu {{subject.id}}' } );
	await submit( page, 'save' );
	const o5 = order( 99 );
	work();
	const keptOld = 1 === mails( 'B12 gross ' + o5 ).length && 0 === mails( 'B12 neu ' + o5 ).length;
	await submit( page, 'publish' );
	const o6 = order( 99 );
	work();
	check( 'b8', 'Entwurf/Veröffentlichen: saved draft does not change new runs; after publishing they use version 2', keptOld && 1 === mails( 'B12 neu ' + o6 ).length && 0 === mails( 'B12 gross ' + o6 ).length, { keptOld, o5, o6 } );

	// ===== 2. Warten (delay) and Abbruch ===============================================================================
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=new' );
	await page.fill( '#wfc-name', 'B12 Warten' );
	await setTrigger( page, 'start', 'woocommerce.order.paid' );
	const wait = await add( page, 'delay' );
	await select( page, wait );
	await setInput( page.locator( '.wfc-inspector input[type=number]' ), '5' );
	await page.locator( '.wfc-inspector select' ).selectOption( 'minutes' );
	await setAction( page, 'mail', 'notify.admin', { Betreff: 'B12 nach Warten {{subject.id}}' } );
	await connect( page, 'start', wait );
	await connect( page, wait, 'mail' );
	check( 'b9', 'canvas: Warten node with 5 minutes', 'Warten: 5 Min.' === ( await page.locator( `.wfc-node[data-id="${ wait }"]` ).getAttribute( 'aria-label' ) ) );
	const wf2 = await submit( page, 'publish' );
	await page.goto( BASE + A + 'workflow-captain-workflows' ); // leave the editor
	// Pause "B12 Dann Sonst" so its runs do not mix in.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf1 );
	await submit( page, 'pause' );
	const w1 = order( 30 );
	const w2 = order( 31 );
	work();
	let r2 = runs( wf2 );
	const waiting = r2.length === 2 && r2.every( ( r ) => 'running' === r.state && r.steps.some( ( s ) => s[ 0 ] === 'mail' && 'queued' === s[ 1 ] ) );
	const rw = await runPage( page, r2[ 0 ].id );
	check( 'b10', 'real runs wait: delay step done, mail step queued for 5 minutes later, run page shows "Läuft"', waiting && rw.heading.includes( 'Läuft' ) && 0 === mails( 'B12 nach Warten' ).length, rw.rows );
	// Abbruch of the first run in the browser.
	const cancelId = r2.find( ( r ) => r.order === w1 ).id;
	await page.goto( BASE + A + 'workflow-captain-runs&run=' + cancelId );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button[name="do"][value="cancel"]' ).click() ] );
	const after = await runPage( page, cancelId );
	check( 'b11', 'Lauf abbrechen in the browser: run Abgebrochen, waiting step cancelled', after.heading.includes( 'Abgebrochen' ) && after.rows.some( ( r ) => 'mail' === r[ 0 ] && 'cancelled' === r[ 1 ] ), after.rows );
	await page.screenshot( { path: path.join( SHOTS, 'b12-cancelled.png' ), fullPage: true } );
	ops( 'b12_due', wf2 );
	work();
	r2 = runs( wf2 );
	check( 'b12', 'after 5 minutes: the cancelled run sends nothing, the other mails once and succeeds', 0 === mails( 'B12 nach Warten ' + w1 ).length && 1 === mails( 'B12 nach Warten ' + w2 ).length && 'cancelled' === r2.find( ( r ) => r.order === w1 ).state && 'succeeded' === r2.find( ( r ) => r.order === w2 ).state, r2.map( ( r ) => [ r.order, r.state ] ) );

	// ===== 3. Fehler / Wiederholung ======================================================================================
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf2 );
	await submit( page, 'pause' );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=new' );
	await page.fill( '#wfc-name', 'B12 Fehler' );
	await setTrigger( page, 'start', 'woocommerce.order.paid' );
	await setAction( page, 'mail', 'notify.admin', { Betreff: 'B12 Fehler {{subject.id}}' } );
	const wf3 = await submit( page, 'publish' );
	wp( 'option', 'update', 'e2e_mail_fail', '["B12 Fehler",1]', '--format=json' );
	const f1 = order( 40 );
	work( 1 );
	let r3 = runs( wf3 );
	const rf = await runPage( page, r3[ 0 ].id );
	check( 'b13', 'mail server fails once: step in retry_wait with mail_failed, attempt 1, run still Läuft (browser)', 'running' === r3[ 0 ].state && rf.rows.some( ( r ) => 'mail' === r[ 0 ] && 'retry_wait' === r[ 1 ] && '1' === r[ 2 ] && 'mail_failed' === r[ 5 ] ) && 0 === mails( 'B12 Fehler ' + f1 ).length, rf.rows );
	await page.screenshot( { path: path.join( SHOTS, 'b12-retry.png' ), fullPage: true } );
	ops( 'b12_due', wf3 );
	work();
	r3 = runs( wf3 );
	check( 'b14', 'retry after the wait: mail sent exactly once, run Erfolgreich, attempts 2', 'succeeded' === r3[ 0 ].state && 1 === mails( 'B12 Fehler ' + f1 ).length && r3[ 0 ].steps.some( ( s ) => 'mail' === s[ 0 ] && 2 === s[ 2 ] ), r3[ 0 ] );

	// Unclear outcome: the worker dies while sending; continuing needs the confirmation in the browser.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf3 );
	await setAction( page, 'mail', 'notify.admin', { Betreff: 'B12 Absturz {{subject.id}}' } );
	await submit( page, 'save' );
	await submit( page, 'publish' );
	const c1 = order( 41 );
	ops( 'b12_crash' );
	wp( 'eval', 'global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}workflow_captain_steps SET lease_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE state = \'running\'" );' );
	work( 1 );
	const crashRun = runs( wf3 ).find( ( r ) => r.order === c1 );
	await page.goto( BASE + A + 'workflow-captain-runs&run=' + crashRun.id );
	const confirmBox = page.locator( 'input[name="confirm"]' );
	const hasConfirm = ( await confirmBox.count() ) === 1;
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button[name="do"][value="continue"]' ).click() ] );
	const refused = ( await notice( page ) ).includes( 'Bestätigung' );
	check( 'b15', 'unclear outcome: Aktion erforderlich, "Erneut senden?" box; Fortsetzen without the tick is refused, no mail', 'action_required' === crashRun.state && 'unclear_outcome' === crashRun.reason && hasConfirm && refused && 0 === mails( 'B12 Absturz ' + c1 ).length, { state: crashRun.state, reason: crashRun.reason, hasConfirm, refused } );
	await page.locator( 'input[name="confirm"]' ).check();
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button[name="do"][value="continue"]' ).click() ] );
	work();
	const crashAfter = runs( wf3 ).find( ( r ) => r.order === c1 );
	check( 'b16', 'with the tick: the mail goes out once more (exactly one captured), run Erfolgreich', 'succeeded' === crashAfter.state && 1 === mails( 'B12 Absturz ' + c1 ).length, crashAfter );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + wf3 );
	await submit( page, 'pause' );

	check( 'b17', 'no JavaScript errors in the builder and run pages', 0 === jsErrors.length, jsErrors.join( ' | ' ).slice( 0, 400 ) );
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
