/**
 * Third E2E run (docs/E2E-RESULTS.md, "Dritter Lauf (neue Oberfläche)"; not shipped): the screens built since the
 * second run, in Chromium with the real wp-admin CSS, on the data of bin/e2e/third.php, at 1280 and 390 px, with
 * axe-core (WCAG 2.2 AA), keyboard, JavaScript errors and horizontal overflow.
 *
 *     AXE=/path/to/axe.min.js E2E_SHOTS=/tmp/shots NODE_PATH=$(npm root -g) node bin/e2e/third.js
 *     E2E_ONLY=lock ...   only the status tab while an upgrade lock is held (called by upgrade.sh)
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const AXE = process.env.AXE ? fs.readFileSync( process.env.AXE, 'utf8' ) : '';
const LOGS = process.env.E2E_LOGS || ( process.env.E2E_ROOT || '/home/user/wp-e2e' ) + '/logs';
const A = '/wp-admin/admin.php?page=';
const PII = [ 'erika.musterfrau.e2e@example.test', 'Erikae2e', 'Musterfraue2e', 'Teststrasse 42e2e', '+49301234e2e', 'Musterstadte2e', 'admin@example.test' ];

let failed = 0;
function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: 'string' === typeof detail ? detail : JSON.stringify( undefined === detail ? '' : detail ) };
	failed += ok ? 0 : 1;
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
	return ok;
}
const wp = ( ...args ) => execFileSync( 'wp', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const sql = ( q ) => execFileSync( 'mysql', [ '-N', process.env.E2E_MAIN_DB || 'wp_e2e', '-e', q ], { encoding: 'utf8' } ).trim();

const jsErrors = [];
async function login( browser, width, height = 900 ) {
	const context = await browser.newContext( { viewport: { width, height }, deviceScaleFactor: 1, acceptDownloads: true } );
	const page = await context.newPage();
	page.on( 'pageerror', ( e ) => jsErrors.push( width + ' ' + page.url() + ' :: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() && /workflow-captain|wfc|builder/i.test( m.text() + ( m.location().url || '' ) ) ) {
			jsErrors.push( width + ' ' + page.url() + ' :: ' + m.text() );
		}
	} );
	page.on( 'requestfailed', ( r ) => {
		if ( r.url().includes( '/plugins/workflow-captain/' ) ) {
			jsErrors.push( 'request failed ' + r.url() );
		}
	} );
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	return page;
}

/** axe-core on the page; violations whose nodes are in the plugin's markup (inside .wrap, outside foreign notices). */
async function axe( page, scope ) {
	if ( ! AXE ) {
		return null;
	}
	await page.addScriptTag( { content: AXE } );
	return page.evaluate( async ( sel ) => {
		const r = await window.axe.run( sel ? document.querySelector( sel ) : document, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] } } );
		const own = ( t ) => {
			const n = document.querySelector( t );
			return n && n.closest( '#wpbody-content .wrap' ) && ! n.closest( '.notice:not(.wfc-notice):not(.wfc-problems):not(.wfc-focus-note)' );
		};
		return r.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.filter( ( n ) => n.target.some( own ) ).map( ( n ) => n.target.join( ' ' ) + ' :: ' + n.failureSummary.replace( /\s+/g, ' ' ).slice( 0, 140 ) ) } ) ).filter( ( v ) => v.nodes.length );
	}, scope || null );
}
const overflow = ( page ) => page.evaluate( () => {
	const wrap = document.querySelector( '#wpbody-content .wrap' ) || document.body;
	return Math.max( 0, document.documentElement.scrollWidth - window.innerWidth, Math.round( wrap.getBoundingClientRect().right - window.innerWidth ) );
} );
async function a11yPage( page, id, label, width, scope ) {
	const v = await axe( page, scope );
	if ( null !== v ) {
		check( `x3-${ id }-${ width }`, `axe (WCAG 2.2 AA) ${ label } at ${ width } px: no violation in Workflow Captain markup`, 0 === v.length, v.map( ( x ) => x.id + ' (' + x.impact + '): ' + x.nodes.slice( 0, 2 ).join( ' | ' ) ).join( ' ;; ' ) || 'none' );
	}
	if ( 390 === width ) {
		const o = await overflow( page );
		check( `x3-${ id }-390-overflow`, `${ label } at 390 px: no horizontal page scroll`, o <= 1, o + 'px' );
	}
	await page.screenshot( { path: path.join( SHOTS, `third-${ id }-${ width }.png` ), fullPage: true } );
}
const texts = ( page, sel ) => page.locator( sel ).allInnerTexts();
async function tabTo( page, test, max = 120 ) {
	for ( let i = 0; i < max; i++ ) {
		await page.keyboard.press( 'Tab' );
		if ( await page.evaluate( test ) ) {
			return true;
		}
	}
	return false;
}
const focused = ( page ) => page.evaluate( () => {
	const a = document.activeElement;
	return a.tagName.toLowerCase() + ( a.className && 'string' === typeof a.className ? '.' + a.className.trim().split( /\s+/ ).join( '.' ) : '' ) + ' ' + ( a.getAttribute( 'aria-label' ) || a.textContent || a.value || '' ).trim().slice( 0, 50 );
} );
const count = async ( page ) => {
	const t = ( await page.locator( '.displaying-num' ).first().innerText().catch( () => '' ) ) || '';
	return parseInt( t.replace( /\D/g, '' ), 10 ) || 0;
};

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const browser = await chromium.launch();

	if ( 'lock' === process.env.E2E_ONLY ) {
		const page = await login( browser, 1280 );
		await page.goto( BASE + A + 'workflow-captain-settings&tab=status' );
		const row = await page.locator( 'tr', { hasText: 'Aktualisierung der Datenbank' } ).innerText();
		check( 'u8b', 'lock held: Settings > Status shows "Aktualisierung der Datenbank: läuft gerade" and schema 3 / 4', row.includes( 'läuft gerade' ) && ( await page.locator( 'tr', { hasText: 'Datenbankschema' } ).innerText() ).includes( '3 / 4' ), row );
		await page.screenshot( { path: path.join( SHOTS, 'third-status-lock-1280.png' ), fullPage: true } );
		await browser.close();
		process.exit( failed ? 1 : 0 );
	}

	const s = JSON.parse( fs.readFileSync( LOGS + '/third-state.json', 'utf8' ) );
	const corr = fs.readFileSync( LOGS + '/third-correlation.txt', 'utf8' ).trim();
	const totalRuns = parseInt( sql( 'SELECT COUNT(*) FROM wp_workflow_captain_runs' ), 10 );
	const runs30 = parseInt( sql( 'SELECT COUNT(*) FROM wp_workflow_captain_runs WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY' ), 10 );
	const totalEvents = parseInt( sql( 'SELECT COUNT(*) FROM wp_workflow_captain_events' ), 10 );
	let page = await login( browser, 1280 );

	// ===== Workflow list ==============================================================================================
	await page.goto( BASE + A + 'workflow-captain-workflows' );
	const heads = await texts( page, '.wrap table.widefat thead th' );
	const rowOf = async ( name ) => page.locator( '.wrap table.widefat tbody tr', { hasText: name } ).first();
	const paidRow = await ( await rowOf( 'Bestellung bezahlt: Betreiber informieren' ) ).innerText();
	const errRow = await rowOf( 'Statuswechsel: Feld fehlt' );
	const errCells = await errRow.locator( 'td' ).allInnerTexts();
	check( 'v1', 'workflow list: columns "Letzter Lauf" and "Fehler (30 Tage)"; last run with time, state and link', heads.some( ( h ) => h.includes( 'Letzter Lauf' ) ) && heads.some( ( h ) => /Fehler.*30/.test( h ) ) && /Erfolgreich/.test( paidRow ) && 1 === await ( await rowOf( 'Bestellung bezahlt: Betreiber informieren' ) ).locator( 'a[href*="run="]' ).count(), { heads, paidRow } );
	const errLink = errRow.locator( 'td:last-child a' );
	const errDb = sql( `SELECT COUNT(*) FROM wp_workflow_captain_runs WHERE workflow_id=${ s.w_error } AND state IN ('failed','action_required')` );
	check( 'v2', 'workflow list: the workflow with action_required runs shows its error count (linked to its runs) and its last state in the warning style', errDb === errCells[ errCells.length - 1 ].trim() && '0' !== errDb && 1 === await errRow.locator( '.wfc-run-state-bad' ).count() && ( await errLink.getAttribute( 'href' ) || '' ).includes( 'workflow=' + s.w_error ), errCells );
	const draftCells = await ( await rowOf( 'Entwurf: Bewertung anfragen' ) ).locator( 'td' ).allInnerTexts();
	check( 'v3', 'workflow list: a draft without runs shows "–" (with screen reader text) and 0 errors', draftCells.slice( -2 ).join( '|' ).includes( '0' ), draftCells );
	await a11yPage( page, 'workflows', 'workflow list', 1280 );
	// Paging: 45 more drafts (test data, removed afterwards).
	execFileSync( 'wp', [ 'eval', 'for ( $i = 1; $i <= 45; $i++ ) { WorkflowCaptain\\Plugin::instance()->workflows()->save( null, sprintf( "Blättern %02d", $i ), array( "nodes" => array( array( "id" => "t", "type" => "trigger", "event" => "wordpress.post.published" ), array( "id" => "e", "type" => "end" ) ), "edges" => array( array( "from" => "t", "to" => "e" ) ) ) ); }' ], { stdio: 'ignore' } );
	await page.goto( BASE + A + 'workflow-captain-workflows' );
	const wfTotal = await count( page );
	const p1 = await page.locator( '.wrap table.widefat tbody tr' ).count();
	await Promise.all( [ page.waitForNavigation(), page.click( '.pagination-links a.next-page' ) ] );
	const p2 = await page.locator( '.wrap table.widefat tbody tr' ).count();
	check( 'v4', 'workflow list pages after 50 entries (WP_List_Table markup, "2 von 2")', wfTotal > 50 && 50 === p1 && wfTotal - 50 === p2 && page.url().includes( 'paged=2' ) && ( await page.locator( '.tablenav-paging-text' ).innerText() ).includes( '2 von 2' ), { wfTotal, p1, p2 } );
	execFileSync( 'wp', [ 'eval', 'global $wpdb; $t = $wpdb->prefix . "workflow_captain_workflows"; $wpdb->query( "DELETE FROM $t WHERE name LIKE \'Blättern %\'" );' ], { stdio: 'ignore' } );

	// ===== Runs ========================================================================================================
	await page.goto( BASE + A + 'workflow-captain-runs' );
	const allLink = await page.locator( '.subsubsub a' ).first().innerText();
	const pages = await page.locator( '.tablenav-paging-text' ).innerText();
	const firstIds = await texts( page, '.wrap table.widefat tbody tr td:first-child' );
	check( 'v5', `runs: all ${ totalRuns } runs counted, 50 per page, "1 von ${ Math.ceil( totalRuns / 50 ) }"`, allLink.includes( '(' + totalRuns + ')' ) && totalRuns === await count( page ) && 50 === firstIds.length && pages.includes( '1 von ' + Math.ceil( totalRuns / 50 ) ), { allLink, pages } );
	await a11yPage( page, 'runs', 'runs list', 1280 );
	// Keyboard: the next-page link is reached with Tab and followed with Enter.
	await page.focus( '#wfc-to' );
	const nextReached = await tabTo( page, () => document.activeElement.matches( 'a.next-page' ) );
	await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
	const secondIds = await texts( page, '.wrap table.widefat tbody tr td:first-child' );
	check( 'v6', 'runs: next page with the keyboard; page 2 continues below page 1 (newest first, no overlap)', nextReached && page.url().includes( 'paged=2' ) && 50 === secondIds.length && parseInt( secondIds[ 0 ], 10 ) < parseInt( firstIds[ 49 ], 10 ), { first: firstIds[ 49 ], second: secondIds[ 0 ] } );
	await Promise.all( [ page.waitForNavigation(), page.click( '.pagination-links a.last-page' ) ] );
	const lastRows = await page.locator( '.wrap table.widefat tbody tr' ).count();
	check( 'v6b', 'runs: last page holds the rest', lastRows === totalRuns - 50 * ( Math.ceil( totalRuns / 50 ) - 1 ), lastRows );
	// State filter.
	await page.goto( BASE + A + 'workflow-captain-runs' );
	const states = {};
	for ( const st of [ 'running', 'action_required', 'paused', 'failed', 'succeeded', 'stopped', 'cancelled' ] ) {
		await page.goto( BASE + A + 'workflow-captain-runs&state=' + st );
		const cells = await texts( page, '.wrap table.widefat tbody tr td:nth-child(4)' );
		states[ st ] = [ cells.length, [ ...new Set( cells ) ].join( '/' ), parseInt( sql( `SELECT COUNT(*) FROM wp_workflow_captain_runs WHERE state='${ st }'` ), 10 ) ];
	}
	check( 'v7', 'runs: every state filter shows only runs of that state, as many as stored (current link marked aria-current)', Object.values( states ).every( ( [ n, labels, db ] ) => n === Math.min( db, 50 ) && n > 0 && ! labels.includes( '/' ) ) && 1 === await page.locator( '.subsubsub a[aria-current="page"]' ).count(), states );
	// Workflow filter with state, paging keeps both.
	await page.goto( BASE + A + 'workflow-captain-runs&workflow=' + s.w_paid );
	const wfNotice = await page.locator( '.notice-info' ).first().innerText();
	const wfNames = [ ...new Set( await texts( page, '.wrap table.widefat tbody tr td:nth-child(2)' ) ) ];
	const nextHref = await page.locator( 'a.next-page' ).getAttribute( 'href' ).catch( () => '' );
	check( 'v8', 'runs: workflow filter (notice, only that workflow, paging keeps the filter)', wfNotice.includes( 'Bestellung bezahlt: Betreiber informieren' ) && 1 === wfNames.length && ( nextHref || '' ).includes( 'workflow=' + s.w_paid ), { wfNotice, wfNames, nextHref } );
	await page.goto( BASE + A + 'workflow-captain-runs&workflow=' + s.w_paid + '&state=failed' );
	check( 'v8b', 'runs: workflow and state filter together', 2 === await page.locator( '.wrap table.widefat tbody tr' ).count(), await texts( page, '.wrap table.widefat tbody tr td:nth-child(4)' ) );
	// Correlation filter.
	await page.goto( BASE + A + 'workflow-captain-runs&correlation=' + corr );
	const corrRows = await page.locator( '.wrap table.widefat tbody tr' ).count();
	const corrWfs = await texts( page, '.wrap table.widefat tbody tr td:nth-child(2)' );
	check( 'v9', 'runs: correlation filter shows the three runs of one order.paid event (three workflows)', 3 === corrRows && 3 === new Set( corrWfs ).size, corrWfs );
	await page.goto( BASE + A + 'workflow-captain-runs&correlation=not-a-uuid' );
	check( 'v9b', 'runs: a correlation that is no UUID is ignored (all runs, no notice)', totalRuns === await count( page ) && 0 === await page.locator( '.notice-info' ).count() );
	// Time range: preset, own dates, swapped dates, keyboard.
	await page.goto( BASE + A + 'workflow-captain-runs' );
	await page.focus( '#wfc-period' );
	await page.selectOption( '#wfc-period', '30' );
	const filterReached = await tabTo( page, () => document.activeElement.matches( '.wfc-run-period button' ), 20 );
	await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
	check( 'v10', `runs: "letzte 30 Tage" chosen and sent with the keyboard (Tab to "Filtern", Enter) counts ${ runs30 } of ${ totalRuns } runs`, filterReached && page.url().includes( 'period=30' ) && runs30 === await count( page ) && runs30 < totalRuns && 1 === await page.locator( 'a', { hasText: 'Zeitraum entfernen' } ).count(), { url: page.url(), shown: await count( page ) } );
	const oldest = sql( "SELECT DATE(MIN(created_at)) FROM wp_workflow_captain_runs" );
	const oldEnd = sql( "SELECT DATE(MIN(created_at) + INTERVAL 9 DAY) FROM wp_workflow_captain_runs" );
	const inRange = parseInt( sql( `SELECT COUNT(*) FROM wp_workflow_captain_runs WHERE created_at BETWEEN '${ oldest } 00:00:00' AND '${ oldEnd } 23:59:59'` ), 10 );
	await page.goto( BASE + A + `workflow-captain-runs&from=${ oldEnd }&to=${ oldest }&state=succeeded&period=7` );
	const swapped = await count( page );
	check( 'v11', 'runs: own dates win over the preset, reversed dates are swapped, the state filter stays', swapped === inRange && inRange > 0 && ( await page.inputValue( '#wfc-from' ) ) === oldest && ( await page.inputValue( '#wfc-to' ) ) === oldEnd, { swapped, inRange, oldest, oldEnd } );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// ===== Run view ====================================================================================================
	const groupRun = sql( `SELECT id FROM wp_workflow_captain_runs WHERE workflow_id=${ s.w_group } AND context LIKE '%${ corr }%' LIMIT 1` );
	await page.goto( BASE + A + 'workflow-captain-runs&run=' + groupRun );
	const meta = await page.locator( '.wfc-run-meta' ).innerText();
	const timeline = await texts( page, '.wrap table.widefat:not(.wfc-run-meta) tbody tr' );
	await page.click( '.wfc-run-data summary' );
	const data = await page.locator( '.wfc-run-data pre' ).innerText();
	const html = await page.content();
	check( 'v12', 'run view: correlation with links to runs and events, timeline with every step and time elements, redacted data (catalog fields, no personal data)', meta.includes( corr ) && meta.includes( 'Läufe mit dieser Korrelation' ) && timeline.length >= 3 && 0 < await page.locator( '.wrap table.widefat:not(.wfc-run-meta) time' ).count() && data.includes( 'total_minor' ) && ! PII.some( ( p ) => html.includes( p ) ), { meta: meta.slice( 0, 300 ), steps: timeline.length } );
	await a11yPage( page, 'run', 'run view', 1280 );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.locator( 'a', { hasText: 'Lauf exportieren' } ).click() ] );
	const file = path.join( SHOTS, 'third-run-export.json' );
	await download.saveAs( file );
	const raw = fs.readFileSync( file, 'utf8' );
	let exp = {};
	try {
		exp = JSON.parse( raw );
	} catch ( e ) {}
	check( 'v13', 'run export: JSON file with run, timeline and redacted event, no personal data (billing data, admin mail), no user login', !! exp && raw.includes( corr ) && raw.includes( 'check' ) && ! PII.some( ( p ) => raw.includes( p ) ) && ! raw.includes( '"admin"' ), { file: download.suggestedFilename(), keys: Object.keys( exp ), bytes: raw.length } );
	// Jump to the editor.
	const jump = page.locator( 'a.wfc-jump' ).nth( 1 );
	const jumpNode = ( ( await jump.getAttribute( 'href' ) ) || '' ).replace( /.*node=/, '' );
	await Promise.all( [ page.waitForNavigation(), jump.click() ] );
	await page.waitForSelector( '.wfc-node' );
	const focusNode = await page.locator( '.wfc-node.is-focus' ).getAttribute( 'data-id' ).catch( () => '' );
	const live = await page.locator( '[aria-live]' ).allInnerTexts();
	check( 'v14', '"Im Editor zeigen": the editor opens with the step\'s node marked and selected, the note in the inspector and an announcement', focusNode === jumpNode && await page.isVisible( '.wfc-focus-note' ) && live.join( ' ' ).includes( 'Knoten aus dem Lauf markiert' ), { jumpNode, focusNode, live } );
	// Unclear outcome: "Erneut senden?".
	const unclear = sql( "SELECT id FROM wp_workflow_captain_runs WHERE last_error='unclear_outcome' LIMIT 1" );
	await page.goto( BASE + A + 'workflow-captain-runs&run=' + unclear );
	check( 'v15', 'run view: unclear outcome shows the confirmation box "Erneut senden?" (label 24 px high) and "Fortsetzen"', 1 === await page.locator( 'label.wfc-confirm' ).count() && ( await page.locator( 'label.wfc-confirm' ).boundingBox() ).height >= 24 );

	// ===== Events ======================================================================================================
	await page.goto( BASE + A + 'workflow-captain-events' );
	const evPages = await page.locator( '.tablenav-paging-text' ).innerText();
	check( 'v16', `events: ${ totalEvents } events, 50 per page with page navigation`, totalEvents === await count( page ) && evPages.includes( '1 von ' + Math.ceil( totalEvents / 50 ) ), evPages );
	await a11yPage( page, 'events', 'events list', 1280 );
	await Promise.all( [ page.waitForNavigation(), page.click( 'a.next-page' ) ] );
	check( 'v16b', 'events: page 2', page.url().includes( 'paged=2' ) && 50 === await page.locator( '.wrap table.widefat tbody tr' ).count() );
	await page.goto( BASE + A + 'workflow-captain-events&product=wordpress' );
	const wpTypes = [ ...new Set( ( await texts( page, '.wrap table.widefat tbody tr td:nth-child(2)' ) ).map( ( t ) => t.split( '.' )[ 0 ] ) ) ];
	const wpCount = parseInt( sql( "SELECT COUNT(*) FROM wp_workflow_captain_events WHERE product='wordpress'" ), 10 );
	check( 'v17', 'events: provider filter (notice, only wordpress.* events, counts of the filtered list)', ( await page.locator( '.notice-info' ).first().innerText() ).includes( 'wordpress' ) && 1 === wpTypes.length && 'wordpress' === wpTypes[ 0 ] && wpCount === await count( page ), { wpTypes, wpCount } );
	await page.goto( BASE + A + 'workflow-captain-events&product=woocommerce&state=done' );
	const doneStates = [ ...new Set( await texts( page, '.wrap table.widefat tbody tr td:nth-child(5)' ) ) ];
	const nextEv = await page.locator( 'a.next-page' ).getAttribute( 'href' ).catch( () => '' );
	check( 'v18', 'events: state and provider filter together, paging keeps both', 1 === doneStates.length && ( nextEv || '' ).includes( 'product=woocommerce' ) && ( nextEv || '' ).includes( 'state=done' ), { doneStates, nextEv } );
	await page.goto( BASE + A + 'workflow-captain-events&correlation=' + corr );
	const corrEv = await texts( page, '.wrap table.widefat tbody tr td:nth-child(2)' );
	check( 'v19', 'events: correlation filter shows the order.paid event of the three runs, link back to its runs', 1 === corrEv.length && corrEv[ 0 ].includes( 'woocommerce.order.paid' ) && 1 === await page.locator( '.notice-info a', { hasText: 'Läufe mit dieser Korrelation' } ).count(), corrEv );

	// ===== Overview ====================================================================================================
	await page.goto( BASE + A + 'workflow-captain' );
	const card = page.locator( '.wrap a', { hasText: 'Läufe · 30 Tage' } );
	const cardText = await card.innerText();
	const cardHref = await card.getAttribute( 'href' );
	const prov = await texts( page, '.wrap table.widefat tbody tr' );
	check( 'v20', `overview: card "Läufe · 30 Tage" = ${ runs30 }, linked to the run list for 30 days`, cardText.includes( String( runs30 ) ) && cardHref.includes( 'period=30' ) && cardHref.includes( 'workflow-captain-runs' ), { cardText, cardHref } );
	check( 'v21', 'overview: provider status lists WooCommerce, WordPress and the schedule adapter as "angemeldet" with counts', prov.length >= 3 && prov.filter( ( r ) => r.includes( 'angemeldet' ) ).length >= 3 && prov.some( ( r ) => /WooCommerce/.test( r ) && /Ereignistypen/.test( r ) ), prov );
	await a11yPage( page, 'overview', 'overview', 1280 );

	// ===== Schedules ===================================================================================================
	await page.goto( BASE + A + 'workflow-captain-schedules' );
	const sched = await texts( page, '.wrap table.widefat tbody tr' );
	const weekly = sched.find( ( r ) => r.includes( 'Wochenbericht' ) ) || '';
	const daily = sched.find( ( r ) => r.includes( 'Täglicher Abgleich' ) ) || '';
	const monthly = sched.find( ( r ) => r.includes( 'Monatsabschluss' ) ) || '';
	const nextDt = await page.locator( 'tr', { hasText: 'Wochenbericht' } ).locator( 'time' ).first().getAttribute( 'datetime' );
	const nextLocal = new Date( nextDt ).toLocaleString( 'de-DE', { timeZone: 'Europe/Berlin', weekday: 'short', hour: '2-digit', minute: '2-digit' } );
	check( 'v22', 'schedules page: three schedule workflows by name; weekly rule, next due a Monday 07:30 site time (UTC in datetime)', 3 === sched.length && weekly.includes( 'wöchentlich, Montag, 07:30' ) && /^Mo/.test( nextLocal ) && nextLocal.includes( '07:30' ), { weekly, nextDt, nextLocal } );
	check( 'v23', 'schedules page: daily one with last fired time and link to its runs; paused monthly one says "keiner (pausiert)"', /Zuletzt|\d{2}\.\d{2}\.\d{4}/.test( daily ) && 1 === await page.locator( 'tr', { hasText: 'Täglicher Abgleich' } ).locator( 'a', { hasText: 'Läufe' } ).count() && monthly.includes( 'keiner (pausiert)' ) && monthly.includes( 'monatlich, am 1., 06:00' ), { daily, monthly } );
	await a11yPage( page, 'schedules', 'schedules', 1280 );

	// ===== Settings > Status ===========================================================================================
	await page.goto( BASE + A + 'workflow-captain-settings&tab=status' );
	const up = await page.locator( 'tr', { hasText: 'Aktualisierung der Datenbank' } ).innerText();
	const audit = await page.locator( 'table', { hasText: 'upgrade' } ).or( page.locator( '.wrap table' ).last() ).last().innerText();
	check( 'v24', 'status tab: "Aktualisierung der Datenbank: keine offen", schema 5 / 5', up.includes( 'keine offen' ) && ( await page.locator( 'tr', { hasText: 'Datenbankschema' } ).innerText() ).includes( '5 / 5' ), up );
	check( 'v25', 'status tab: audit entries with readable labels (resumed upgrade, run export)', audit.includes( 'Abgebrochene Datenbank-Aktualisierung wiederholt' ) && audit.includes( 'Lauf exportiert' ) && ! /\brun_export\b/.test( audit ), audit.split( '\n' ).slice( 0, 6 ) );
	await a11yPage( page, 'status', 'settings status', 1280 );

	// ===== Builder =====================================================================================================
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_group );
	await page.waitForSelector( '.wfc-node' );
	const head = await page.locator( '.wfc-editor-head' ).innerText();
	check( 'v26', 'editor header: name, "Aktiv · Version 1 veröffentlicht", trigger with provider and versions, last run linked, test run and publish at the top', head.includes( 'Große Bestellung' ) && head.includes( 'Version 1 veröffentlicht' ) && /Auslöser woocommerce\.order\.paid von WooCommerce/.test( head ) && /Letzter Lauf: Lauf \d+/.test( head ) && 2 === await page.locator( '.wfc-editor-head button' ).count(), head );
	const kicker = await page.locator( '.wfc-node[data-id="start"] .wfc-node-kicker' ).textContent();
	await page.click( '.wfc-node[data-id="start"]' );
	const origin = await page.locator( '.wfc-origin' ).innerText();
	check( 'v27', 'origin on the node ("WOOCOMMERCE · AUSLÖSER") and in the inspector ("Herkunft: WooCommerce 9.9.5, Vertrag …")', /WOOCOMMERCE/i.test( kicker ) && /Herkunft: WooCommerce/.test( origin ), { kicker, origin } );
	await page.click( '.wfc-node[data-id="check"]' );
	const fieldOpts = await page.locator( '.wfc-rule-field' ).first().locator( 'optgroup' ).evaluateAll( ( gs ) => gs.map( ( g ) => g.label + ':' + g.children.length ) );
	const groups0 = await page.locator( '.wfc-rule-group' ).count();
	check( 'v28', 'condition form: the stored group is shown as a group (fieldset with its own AND/OR), field list grouped (envelope / event data)', 1 === groups0 && fieldOpts.length >= 2 && ( await page.locator( '.wfc-rule-group .wfc-group-op' ).inputValue() ) === 'or', fieldOpts );
	// Example value from the test-run sample.
	await page.evaluate( () => {
		const d = document.querySelector( '#wfc-sample' ).closest( 'details' );
		d.open = true;
	} );
	await page.fill( '#wfc-sample', JSON.stringify( { data: { total_minor: 9900, payment_method: 'bacs', currency: 'EUR' } } ) );
	await page.click( '.wfc-node[data-id="start"]' );
	await page.click( '.wfc-node[data-id="check"]' );
	const examples = await texts( page, '.wfc-rule-example' );
	check( 'v29', 'field list with example value from the sample event ("Beispiel: 9900", "Beispiel: bacs")', examples.some( ( e ) => e.includes( '9900' ) ) && examples.some( ( e ) => e.includes( 'bacs' ) ), examples );
	// "+ Gruppe" with the keyboard; focus goes into the new group.
	await page.focus( '.wfc-group-add' );
	await page.keyboard.press( 'Enter' );
	const afterGroup = await focused( page );
	const groups1 = await page.locator( '.wfc-rule-group' ).count();
	check( 'v30', '"+ Gruppe" with Enter: a second group, the focus is on the field of its first rule; a group offers no further "+ Gruppe"', 2 === groups1 && afterGroup.includes( 'wfc-rule-field' ) && 0 === await page.locator( '.wfc-rule-group .wfc-group-add' ).count(), afterGroup );
	await page.selectOption( '.wfc-rule-group >> nth=1 >> .wfc-rule-field', 'data.currency' );
	const afterSelect = await focused( page );
	check( 'v31', 'focus kept after changing a field in the new group (no jump to the page start)', ! afterSelect.startsWith( 'body' ), afterSelect );
	await page.click( '.wfc-group-remove >> nth=1' );
	await a11yPage( page, 'builder-group', 'builder with a condition group', 1280, '.wfc-builder' );
	// Drag from the palette.
	const before = await page.locator( '.wfc-node' ).count();
	const box = await page.locator( '.wfc-canvas' ).boundingBox();
	await page.dragAndDrop( '.wfc-add[data-type="delay"]', '.wfc-canvas-wrap', { targetPosition: { x: Math.round( box.width - 120 ), y: 60 } } );
	const after = await page.locator( '.wfc-node' ).count();
	const graph = JSON.parse( await page.inputValue( '#wfc-graph' ) );
	const added = graph.nodes.find( ( n ) => 'delay' === n.type );
	check( 'v32', 'drag from the palette onto the canvas adds the node where it was dropped', after === before + 1 && !! added && Math.abs( added.x - ( box.width - 120 - 90 ) ) < 120, { before, after, added } );
	// Warning sign: the new delay node is not connected.
	const warn = await page.locator( `.wfc-node[data-id="${ added && added.id }"]` );
	check( 'v33', 'warning sign on the unconnected node (badge with "!", text in the accessible name)', await warn.evaluate( ( g ) => g.classList.contains( 'has-problem' ) ) && 1 === await warn.locator( '.wfc-node-warn-mark' ).count() && /Nicht mit dem Auslöser verbunden|Ausgang/.test( await warn.getAttribute( 'aria-label' ) ), await warn.getAttribute( 'aria-label' ) );
	// The add-on action whose add-on is gone: warning on the node of W8.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_addon );
	await page.waitForSelector( '.wfc-node' );
	const markLabel = await page.locator( '.wfc-node[data-id="mark"]' ).getAttribute( 'aria-label' );
	check( 'v34', 'action of an add-on that is gone: node marked unavailable with the warning "Aktion nicht verfügbar"', await page.locator( '.wfc-node[data-id="mark"].is-unavailable' ).count() === 1 && markLabel.includes( 'Aktion nicht verfügbar' ), markLabel );
	// With the add-on active: its own input fields.
	wp( 'option', 'update', 'e2e_addon_action', '1' );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_addon );
	await page.waitForSelector( '.wfc-node' );
	await page.click( '.wfc-node[data-id="mark"]' );
	const insp = page.locator( '.wfc-inspector' );
	const color = insp.getByLabel( 'Farbe' );
	const fields = { color: await color.inputValue().catch( () => '' ), prio: await insp.getByLabel( 'Priorität' ).inputValue().catch( () => '' ), comment: await insp.getByLabel( 'Kommentar' ).inputValue().catch( () => '' ), options: await color.locator( 'option' ).allInnerTexts().catch( () => [] ) };
	check( 'v35', 'input fields of an add-on action (builder_fields): select "Farbe" with its options, number "Priorität", textarea "Kommentar", labelled and filled from the stored config', 'red' === fields.color && '2' === fields.prio && 'Erstattung prüfen' === fields.comment && fields.options.includes( 'Rot' ) && fields.options.includes( 'Grün' ), fields );
	await insp.getByLabel( 'Priorität' ).fill( '5' );
	await page.keyboard.press( 'Tab' );
	const afterPrio = await focused( page );
	check( 'v36', 'add-on field changed, Tab moves on (focus kept), the graph holds the new value', ! afterPrio.startsWith( 'body' ) && /"priority": ?"?5/.test( await page.inputValue( '#wfc-graph' ) ), afterPrio );
	wp( 'option', 'delete', 'e2e_addon_action' );
	// Schedule preview and last fired.
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_weekly );
	await page.waitForSelector( '.wfc-node' );
	await page.click( '.wfc-node[data-id="start"]' );
	const dues = await page.locator( '.wfc-next time' ).evaluateAll( ( ts ) => ts.map( ( t ) => t.getAttribute( 'datetime' ) ) );
	const duesLocal = dues.map( ( d ) => new Date( d ).toLocaleString( 'de-DE', { timeZone: 'Europe/Berlin', weekday: 'short', hour: '2-digit', minute: '2-digit' } ) );
	check( 'v37', 'schedule preview: next three dues, each a Monday 07:30 in the site time zone, a week apart; "Zuletzt ausgelöst: noch nie."', 3 === dues.length && duesLocal.every( ( d ) => /^Mo/.test( d ) && d.includes( '07:30' ) ) && ( await page.locator( '.wfc-last-fired' ).innerText() ).includes( 'noch nie' ), duesLocal );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_daily );
	await page.waitForSelector( '.wfc-node' );
	await page.click( '.wfc-node[data-id="start"]' );
	const lf = await page.locator( '.wfc-last-fired' ).innerText();
	check( 'v38', 'schedule form of the daily workflow: "Zuletzt ausgelöst:" with time and link to its runs', lf.startsWith( 'Zuletzt ausgelöst:' ) && ! lf.includes( 'noch nie' ) && 1 === await page.locator( '.wfc-last-fired a[href*="workflow=' + s.w_daily + '"]' ).count(), lf );
	await a11yPage( page, 'builder-schedule', 'builder with schedule preview', 1280, '.wfc-builder' );

	// ===== 390 px =====================================================================================================
	const mobile = await login( browser, 390 );
	for ( const [ id, slug ] of [ [ 'workflows', 'workflow-captain-workflows' ], [ 'runs', 'workflow-captain-runs&period=90' ], [ 'run', 'workflow-captain-runs&run=' + groupRun ], [ 'events', 'workflow-captain-events&product=woocommerce' ], [ 'overview', 'workflow-captain' ], [ 'schedules', 'workflow-captain-schedules' ], [ 'status', 'workflow-captain-settings&tab=status' ] ] ) {
		await mobile.goto( BASE + A + slug );
		await a11yPage( mobile, id, id, 390 );
	}
	await mobile.goto( BASE + A + 'workflow-captain-runs' );
	check( 'v39', '390 px: time range form and page navigation of the runs visible (not hidden like tablenav top)', await mobile.isVisible( '#wfc-period' ) && await mobile.isVisible( '.pagination-links a.next-page' ) );
	await mobile.goto( BASE + A + 'workflow-captain-workflows&edit=' + s.w_group );
	await mobile.waitForSelector( '.wfc-linear-wrap' );
	await mobile.click( '.wfc-linear-wrap summary' );
	const linear = await texts( mobile, '.wfc-linear li' );
	check( 'v40', '390 px: linear step view of the workflow with a group lists Dann/Sonst branches', ! ( await mobile.isVisible( '.wfc-canvas' ) ) && linear.some( ( l ) => l.startsWith( 'Dann' ) ) && linear.some( ( l ) => l.startsWith( 'Sonst' ) ), linear );
	await mobile.click( '.wfc-linear li button >> text=Wenn/Sonst' );
	const mFocus = await focused( mobile );
	check( 'v41', '390 px: choosing the condition in the list opens it in the inspector with the focus on its heading; the group form fits the screen', mFocus.startsWith( 'h3' ) && 1 === await mobile.locator( '.wfc-rule-group' ).count() && ( await overflow( mobile ) ) <= 1, { mFocus, overflow: await overflow( mobile ) } );
	await a11yPage( mobile, 'builder-linear', 'builder (linear view, condition group)', 390, '.wfc-builder' );
	await mobile.goto( BASE + A + 'workflow-captain-runs&run=' + groupRun );
	await mobile.click( 'a.wfc-jump >> nth=1' );
	await mobile.waitForSelector( '.wfc-linear-wrap' );
	check( 'v42', '390 px: "Im Editor zeigen" opens the linear list with the step marked', await mobile.$eval( '.wfc-linear-wrap', ( d ) => d.open ) && 1 <= await mobile.locator( '.wfc-linear [aria-current], .wfc-linear .is-focus, .wfc-linear li', { hasText: 'aus dem Lauf' } ).count(), await texts( mobile, '.wfc-linear li' ) );

	check( 'v43', 'no JavaScript error and no failed request of Workflow Captain on any page of this run', 0 === jsErrors.length, jsErrors.slice( 0, 5 ) );
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	check( 'v-crash', 'third.js finished without an exception', false, String( e && e.stack || e ).slice( 0, 600 ) );
	process.exit( 1 );
} );
