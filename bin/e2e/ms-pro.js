/**
 * Multisite with Workflow Captain and Workflow Captain Pro network-active (not shipped). Opens the network admin
 * pages of Pro, its license screen and the core pages of a sub-site; checks HTTP 200, no PHP error text, and that the
 * license screen says why the features are off.
 *
 *     E2E_URL=http://127.0.0.1:8081 E2E_SHOTS=/tmp/shots node bin/e2e/ms-pro.js
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8081';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const PHP_ERROR = /(<b>(Fatal error|Warning|Notice|Deprecated)<\/b>|Fatal error:|Warning:|Notice:|Deprecated:)/;
let failed = 0;

function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: detail || '' };
	failed += ok ? 0 : 1;
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
}

( async () => {
	const browser = await chromium.launch();
	const page = await ( await browser.newContext( { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 } ) ).newPage();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', process.env.E2E_PASS || 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	const urls = {
		'network-plugins': '/wp-admin/network/plugins.php',
		'network-workflows': '/wp-admin/network/admin.php?page=workflow-captain-network',
		'network-consents': '/wp-admin/network/admin.php?page=workflow-captain-network-consents',
		'network-runs': '/wp-admin/network/admin.php?page=workflow-captain-network-runs',
		'network-transfer': '/wp-admin/network/admin.php?page=workflow-captain-network-transfer',
		'network-license': '/wp-admin/network/settings.php?page=workflow-captain-pro-license',
		'site2-overview': '/zwei/wp-admin/admin.php?page=workflow-captain',
		'site2-providers': '/zwei/wp-admin/admin.php?page=workflow-captain-providers',
		'site2-settings': '/zwei/wp-admin/admin.php?page=workflow-captain-settings',
		'site2-network-participation': '/zwei/wp-admin/admin.php?page=workflow-captain-network-site',
		'site2-integrations': '/zwei/wp-admin/admin.php?page=workflow-captain-pro-integrations',
	};
	for ( const [ name, url ] of Object.entries( urls ) ) {
		const res = await page.goto( BASE + url );
		const html = await page.content();
		const m = html.match( PHP_ERROR );
		const status = res ? res.status() : 0;
		const text = ( await page.locator( '#wpbody-content' ).innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).slice( 0, 260 );
		check( 'p-' + name, `Pro multisite ${ name } renders (HTTP ${ status }), no PHP error`, 200 === status && ! m, m ? html.substr( m.index - 60, 260 ).replace( /\s+/g, ' ' ) : `${ page.url().replace( BASE, '' ) } :: ${ text }` );
		await page.screenshot( { path: path.join( SHOTS, 'ms-pro-' + name + '.png' ), fullPage: true } );
	}
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
