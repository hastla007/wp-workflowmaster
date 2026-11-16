/**
 * Admin E2E (not shipped: /bin is export-ignore). Logs in to wp-admin, opens every Workflow Captain page, checks HTTP 200
 * and that no PHP error text is in the HTML, renders the builder, saves a draft, imports a template, saves a setting and
 * provokes a field error. Screenshots go to $E2E_SHOTS (default: the current directory), never into the repository.
 *
 *     E2E_URL=http://127.0.0.1:8080 E2E_USER=admin E2E_PASS=... E2E_SHOTS=/tmp/shots node bin/e2e/admin.js
 *
 * Prints one JSON line per check and exits 1 when a check failed.
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const USER = process.env.E2E_USER || 'admin';
const PASS = process.env.E2E_PASS || 'admin-e2e-pass';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const PHP_ERROR = /(Fatal error|Parse error|Warning:|Notice:|Deprecated:|<b>Warning<\/b>|<b>Notice<\/b>|<b>Deprecated<\/b>|<b>Fatal error<\/b>)/;

let failed = 0;
function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: detail || '' };
	if ( ! ok ) {
		failed++;
	}
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
}

async function open( page, id, name, url, shot ) {
	const res = await page.goto( BASE + url, { waitUntil: 'load' } );
	const html = await page.content();
	const m = html.match( PHP_ERROR );
	const status = res ? res.status() : 0;
	check( id, name + ' (HTTP 200, no PHP error text)', 200 === status && ! m, `status ${ status }${ m ? ', found: ' + html.substr( Math.max( 0, m.index - 80 ), 240 ).replace( /\s+/g, ' ' ) : '' }` );
	if ( shot ) {
		await page.screenshot( { path: path.join( SHOTS, shot + '.png' ), fullPage: true } );
	}
	return html;
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 } );
	const page = await context.newPage();
	const consoleErrors = [];
	page.on( 'pageerror', ( e ) => consoleErrors.push( String( e ) ) );
	// Only errors caused by Workflow Captain count: WooCommerce from git has no built JS (404s are expected there).
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() && ! m.text().startsWith( 'Failed to load resource' ) ) {
			consoleErrors.push( m.text() );
		}
	} );
	page.on( 'response', ( r ) => {
		if ( r.status() >= 400 && r.url().includes( 'workflow-captain' ) ) {
			consoleErrors.push( r.status() + ' ' + r.url() );
		}
	} );

	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	check( 'h0', 'login to wp-admin', page.url().includes( '/wp-admin' ), page.url() );

	const a = '/wp-admin/admin.php?page=';
	await open( page, 'h1', 'Übersicht', a + 'workflow-captain', 'overview' );
	await open( page, 'h2', 'Workflows list', a + 'workflow-captain-workflows', 'workflows' );
	await open( page, 'h3', 'Läufe', a + 'workflow-captain-runs', 'runs' );
	const runsHtml = await page.content();
	const runLink = runsHtml.match( /page=workflow-captain-runs&(?:amp;)?run=(\d+)/ );
	if ( runLink ) {
		await open( page, 'h3b', 'Lauf detail', a + 'workflow-captain-runs&run=' + runLink[ 1 ], 'run-detail' );
	}
	await open( page, 'h4', 'Ereignisse', a + 'workflow-captain-events', 'events' );
	const provHtml = await open( page, 'h5', 'Provider', a + 'workflow-captain-providers', 'providers' );
	const testLink = provHtml.match( /href="([^"]*page=workflow-captain-providers[^"]*test=woocommerce\.order\.paid[^"]*)"/ );
	check( 'h5a', 'Provider page offers a Testereignis link for woocommerce.order.paid', !! testLink );
	if ( testLink ) {
		const url = testLink[ 1 ].replace( /&amp;/g, '&' ).replace( BASE, '' );
		const html = await open( page, 'h5b', 'Testereignis woocommerce.order.paid', url, 'provider-test-event' );
		check( 'h5c', 'Testereignis report shown', html.includes( 'Testereignis' ) && html.includes( 'woocommerce.order.paid' ) );
	}
	await open( page, 'h6', 'Vorlagen', a + 'workflow-captain-templates', 'templates' );
	const before = ( await page.content() ).length;
	const forms = await page.locator( 'form:has(input[name="action"][value="workflow_captain_template"])' ).count();
	check( 'h6a', 'Vorlagen lists templates', forms > 0, forms + ' templates' );
	if ( forms > 0 ) {
		await Promise.all( [ page.waitForNavigation(), page.locator( 'form:has(input[name="action"][value="workflow_captain_template"]) button' ).first().click() ] );
		const html = await page.content();
		// Without the Captain providers the template's trigger is unavailable: imported as draft with that notice.
		const notice = html.includes( 'Als Entwurf importiert' ) ? 'success' : ( html.includes( 'provider_unavailable' ) ? 'provider_unavailable' : 'none' );
		check( 'h6b', 'template imported as draft (redirect to editor with canvas, notice shown, no PHP error)', /edit=\d+/.test( page.url() ) && 'none' !== notice && ( await page.locator( '.wfc-canvas .wfc-node' ).count() ) > 0 && ! PHP_ERROR.test( html ), page.url() + ' notice=' + notice );
		await page.screenshot( { path: path.join( SHOTS, 'template-imported.png' ), fullPage: true } );
	}
	void before;
	await open( page, 'h7', 'Hilfe', a + 'workflow-captain-help', 'help' );
	for ( const tab of [ 'general', 'execution', 'notify', 'privacy', 'roles', 'status' ] ) {
		await open( page, 'h8-' + tab, 'Einstellungen tab ' + tab, a + 'workflow-captain-settings&tab=' + tab, 'settings-' + tab );
	}
	await open( page, 'h9', 'Eigene Connectoren', a + 'workflow-captain-connectors', 'connectors' );

	// Settings: save a valid value, then an out-of-range value that must produce a field error.
	await page.goto( BASE + a + 'workflow-captain-settings&tab=execution' );
	const batch = page.locator( '#wfc-batch' );
	if ( await batch.count() ) {
		await batch.fill( '30' );
		await Promise.all( [ page.waitForNavigation(), page.locator( '#submit, input[type=submit][name=submit], button[type=submit]' ).first().click() ] );
		let html = await page.content();
		check( 'h10', 'settings: valid value saved', ( await page.locator( '#wfc-batch' ).inputValue() ) === '30' && ! PHP_ERROR.test( html ), await page.locator( '#wfc-batch' ).inputValue() );
		await page.evaluate( () => {
			const el = document.getElementById( 'wfc-batch' );
			el.removeAttribute( 'max' );
			el.removeAttribute( 'min' );
			el.value = '5000';
		} );
		await Promise.all( [ page.waitForNavigation(), page.locator( '#submit, input[type=submit][name=submit], button[type=submit]' ).first().click() ] );
		html = await page.content();
		const invalid = await page.locator( '#wfc-batch[aria-invalid="true"]' ).count();
		check( 'h11', 'settings: out-of-range value shows a field error and is clamped', invalid > 0 && html.includes( '5000' ) && ( await page.locator( '#wfc-batch' ).inputValue() ) === '200', 'value ' + ( await page.locator( '#wfc-batch' ).inputValue() ) + ', aria-invalid ' + invalid );
		await page.screenshot( { path: path.join( SHOTS, 'settings-field-error.png' ), fullPage: true } );
		await page.locator( '#wfc-batch' ).fill( '25' );
		await Promise.all( [ page.waitForNavigation(), page.locator( '#submit, input[type=submit][name=submit], button[type=submit]' ).first().click() ] );
	} else {
		check( 'h10', 'settings: batch field found', false );
	}

	// Builder: new workflow editor and an existing one.
	consoleErrors.length = 0;
	await open( page, 'h12', 'Workflow builder (new)', a + 'workflow-captain-workflows&edit=new', 'builder-new' );
	await page.waitForSelector( '.wfc-canvas', { timeout: 5000 } ).catch( () => null );
	const nodes = await page.locator( '.wfc-canvas .wfc-node' ).count();
	check( 'h12a', 'builder canvas renders nodes', nodes >= 3, nodes + ' nodes' );
	await page.fill( '#wfc-name', 'E2E Entwurf aus dem Builder' );
	await page.locator( '.wfc-add[data-type="action"]' ).first().click().catch( () => null );
	const nodesAfter = await page.locator( '.wfc-canvas .wfc-node' ).count();
	check( 'h12b', 'builder palette adds a node', nodesAfter === nodes + 1, nodes + ' -> ' + nodesAfter );
	await page.screenshot( { path: path.join( SHOTS, 'builder-canvas.png' ), fullPage: true } );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button[name="do"][value="save"]' ).click() ] );
	const saved = await page.content();
	check( 'h12c', 'saving a draft works (redirect to edit=<id>, no PHP error)', /edit=\d+/.test( page.url() ) && ! PHP_ERROR.test( saved ) && ( await page.locator( '#wfc-name' ).inputValue() ) === 'E2E Entwurf aus dem Builder', page.url() );
	const nodesSaved = await page.locator( '.wfc-canvas .wfc-node' ).count();
	check( 'h12d', 'saved draft re-opens with the added node', nodesSaved === nodesAfter, String( nodesSaved ) );
	await page.screenshot( { path: path.join( SHOTS, 'builder-saved.png' ), fullPage: true } );
	check( 'h12e', 'no JavaScript errors in the builder', 0 === consoleErrors.length, consoleErrors.join( ' | ' ).slice( 0, 400 ) );

	// Dry run of a published workflow from the editor (Testlauf).
	await page.goto( BASE + a + 'workflow-captain-workflows' );
	const wfLink = ( await page.content() ).match( /page=workflow-captain-workflows&(?:amp;)?edit=(\d+)/ );
	if ( wfLink ) {
		await open( page, 'h13', 'Workflow editor (existing)', a + 'workflow-captain-workflows&edit=' + wfLink[ 1 ], 'builder-existing' );
		const dry = page.locator( 'button[name="do"][value="dryrun"]' ).first(); // Header and form both offer "Testlauf" since the third run.
		if ( await dry.count() ) {
			await page.evaluate( () => document.querySelectorAll( 'details' ).forEach( ( d ) => ( d.open = true ) ) );
			await Promise.all( [ page.waitForNavigation(), dry.click() ] );
			const html = await page.content();
			check( 'h13a', 'Testlauf (dry run) renders without PHP error', ! PHP_ERROR.test( html ) && page.url().includes( 'dryrun' ), page.url() );
			await page.screenshot( { path: path.join( SHOTS, 'builder-dryrun.png' ), fullPage: true } );
		}
	}

	// Plugin-level admin screens that list Workflow Captain: plugins page and Site Health.
	await open( page, 'h14', 'Plugins page', '/wp-admin/plugins.php', 'plugins' );
	await open( page, 'h15', 'Site Health status', '/wp-admin/site-health.php', 'site-health' );
	await open( page, 'h16', 'Dashboard', '/wp-admin/', 'dashboard' );

	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
