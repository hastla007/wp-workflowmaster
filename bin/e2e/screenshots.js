/**
 * Screenshots for WordPress.org (.wordpress-org/screenshot-N.png, 1280 x 800, deviceScaleFactor 1) from the real test
 * site with the data of the third run (bin/e2e/run-third.sh). Not shipped (/bin is export-ignore).
 *
 *     E2E_OUT=.wordpress-org NODE_PATH=$(npm root -g) node bin/e2e/screenshots.js
 *
 * Only the browser view is changed: the "development version" notices of the WooCommerce build from Git are hidden,
 * and the site name in the toolbar reads "Beispielshop". Nothing is saved on the site.
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const OUT = process.env.E2E_OUT || path.join( __dirname, '../../.wordpress-org' );
const LOGS = process.env.E2E_LOGS || ( process.env.E2E_ROOT || '/home/user/wp-e2e' ) + '/logs';
const A = '/wp-admin/admin.php?page=';

( async () => {
	const s = JSON.parse( fs.readFileSync( LOGS + '/third-state.json', 'utf8' ) );
	const corr = fs.readFileSync( LOGS + '/third-correlation.txt', 'utf8' ).trim();
	const browser = await chromium.launch();
	const page = await ( await browser.newContext( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 } ) ).newPage();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	const tidy = async () => page.evaluate( () => {
		document.querySelectorAll( '.notice, .error, .updated, .update-nag' ).forEach( ( n ) => {
			if ( /development version|development mode|pnpm/i.test( n.textContent ) ) {
				n.remove();
			}
		} );
		const name = document.querySelector( '#wp-admin-bar-site-name > a' );
		if ( name ) {
			name.textContent = 'Beispielshop';
		}
	} );
	const shot = async ( n, url, prepare ) => {
		await page.goto( BASE + url );
		await tidy();
		if ( prepare ) {
			await prepare();
		}
		await page.waitForTimeout( 150 );
		await page.screenshot( { path: path.join( OUT, `screenshot-${ n }.png` ) } );
		console.log( 'screenshot-' + n, url );
	};
	const scrollTo = ( sel, offset = 40 ) => page.evaluate( ( [ q, o ] ) => {
		const e = document.querySelector( q );
		window.scrollTo( 0, e ? e.getBoundingClientRect().top + window.scrollY - o : 0 );
	}, [ sel, offset ] );

	// 1. Builder: condition with a group, origin on the nodes, header with provider and last run.
	await shot( 1, A + 'workflow-captain-workflows&edit=' + s.w_group, async () => {
		await page.waitForSelector( '.wfc-node' );
		await page.click( '.wfc-node[data-id="check"]' );
		await scrollTo( '.wfc-editor-head', 44 );
	} );
	// 2. Schedule trigger with the next three dues.
	await shot( 2, A + 'workflow-captain-workflows&edit=' + s.w_weekly, async () => {
		await page.waitForSelector( '.wfc-node' );
		await page.click( '.wfc-node[data-id="start"]' );
		await scrollTo( '.wfc-editor-head', 44 );
	} );
	// 3. Overview.
	await shot( 3, A + 'workflow-captain' );
	// 4. Workflow list with last run and errors of 30 days.
	await shot( 4, A + 'workflow-captain-workflows' );
	// 5. Runs with state, workflow and time range filters and page navigation.
	await shot( 5, A + 'workflow-captain-runs&period=30' );
	// 6. One run: correlation, timeline, export.
	await shot( 6, A + 'workflow-captain-runs&correlation=' + corr, async () => {
		const href = await page.locator( '.wrap table.widefat tbody tr', { hasText: 'Große Bestellung' } ).locator( 'a' ).first().getAttribute( 'href' );
		await page.goto( href );
		await tidy();
		await page.click( '.wfc-run-data summary' );
		await scrollTo( '.wfc-editor-head', 44 );
	} );
	// 7. Events of one provider with page navigation.
	await shot( 7, A + 'workflow-captain-events&product=woocommerce' );
	// 8. Schedules.
	await shot( 8, A + 'workflow-captain-schedules' );
	// 9. Settings > Status: schema, upgrade, worker and the audit log.
	await shot( 9, A + 'workflow-captain-settings&tab=status' );
	await browser.close();
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
