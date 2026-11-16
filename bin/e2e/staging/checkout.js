/**
 * Real orders in the browser on the staging site (WooCommerce 11.3 release package, with its JS): Checkout block
 * with bank transfer, cash on delivery and a 100 % coupon (free order: WooCommerce calls payment_complete()), and the
 * classic [woocommerce_checkout] shortcode for comparison. The events, runs and the absence of personal data are
 * checked with bin/e2e/staging/staging.php. Not shipped (/bin is export-ignore).
 *
 *     . bin/e2e/staging/env-staging.sh && NODE_PATH=$(npm root -g) node bin/e2e/staging/checkout.js
 */
'use strict';

const { chromium } = require( 'playwright' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8090';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const PHP = path.join( __dirname, 'staging.php' );
// Same values as E2E_PII in bin/e2e/checks.php (the checkout validates the phone, so a real-looking number here):
// staging.php co_pii searches the plugin's tables for them.
const PII = { email: 'erika.musterfrau.e2e@example.test', first: 'Erikae2e', last: 'Musterfraue2e', address: 'Teststrasse 42e2e', phone: '+49 30 98765432', city: 'Musterstadte2e', postcode: '10115', address2: 'Hinterhof 3' };

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
const wp = ( ...args ) => execFileSync( 'wp', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
const st = ( ...args ) => {
	const out = wp( 'eval-file', PHP, ...args.map( String ) );
	out.split( '\n' ).filter( ( l ) => /^(PASS|FAIL) /.test( l ) && l.startsWith( 'FAIL' ) ).forEach( () => failed++ );
	return out;
};

const ours = [];
function watch( page ) {
	page.on( 'pageerror', ( e ) => /workflow-captain|workflowCaptain/i.test( String( e.stack || e ) ) && ours.push( String( e ) ) );
	page.on( 'response', ( r ) => r.status() >= 400 && r.url().includes( 'workflow-captain' ) && ours.push( r.status() + ' ' + r.url() ) );
}

/** Guest order through the Checkout block; returns the order ID from the order-received URL. */
async function blockOrder( browser, product, method, coupon, shot ) {
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 } );
	const page = await ctx.newPage();
	watch( page );
	await page.goto( BASE + '/?add-to-cart=' + product );
	await page.goto( BASE + '/checkout/' );
	await page.locator( '#email' ).waitFor( { timeout: 30000 } );
	await page.fill( '#email', PII.email );
	await page.selectOption( '#shipping-country', 'DE' ).catch( () => {} );
	await page.fill( '#shipping-first_name', PII.first );
	await page.fill( '#shipping-last_name', PII.last );
	await page.fill( '#shipping-address_1', PII.address );
	const toggle = page.locator( '.wc-block-components-address-form__address_2-toggle' );
	if ( await toggle.count() ) {
		await toggle.click();
	}
	await page.fill( '#shipping-address_2', PII.address2 ).catch( () => {} );
	await page.fill( '#shipping-postcode', PII.postcode );
	await page.fill( '#shipping-city', PII.city );
	await page.fill( '#shipping-phone', PII.phone );
	if ( coupon ) {
		await page.getByRole( 'button', { name: /Add coupons|Gutschein/i } ).click();
		await page.locator( '.wc-block-components-totals-coupon__input input' ).fill( coupon );
		await page.getByRole( 'button', { name: /^Apply$|Anwenden/i } ).click();
		await page.locator( '.wc-block-components-totals-discount, .wc-block-components-chip' ).first().waitFor( { timeout: 20000 } );
		await page.waitForTimeout( 1000 );
	} else {
		await page.locator( `#radio-control-wc-payment-method-options-${ method }` ).check();
	}
	await page.waitForTimeout( 800 ); // the Store API saves the address in the background
	await page.screenshot( { path: path.join( SHOTS, `checkout-block-${ shot }.png` ), fullPage: true } );
	await Promise.all( [
		page.waitForURL( /order-received/, { timeout: 60000 } ),
		page.locator( '.wc-block-components-checkout-place-order-button' ).click(),
	] );
	await page.screenshot( { path: path.join( SHOTS, `checkout-block-${ shot }-received.png` ) } );
	const id = ( page.url().match( /order-received\/(\d+)/ ) || [] )[ 1 ];
	await ctx.close();
	return id;
}

/** Guest order through the classic shortcode checkout (page "Kasse klassisch"). */
async function classicOrder( browser, product, url ) {
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 } );
	const page = await ctx.newPage();
	watch( page );
	await page.goto( BASE + '/?add-to-cart=' + product );
	await page.goto( url );
	await page.locator( '#billing_first_name' ).waitFor( { timeout: 30000 } );
	await page.fill( '#billing_first_name', PII.first );
	await page.fill( '#billing_last_name', PII.last );
	await page.fill( '#billing_address_1', PII.address );
	await page.fill( '#billing_address_2', PII.address2 ).catch( () => {} );
	await page.fill( '#billing_postcode', PII.postcode );
	await page.fill( '#billing_city', PII.city );
	await page.fill( '#billing_phone', PII.phone );
	await page.fill( '#billing_email', PII.email );
	await page.waitForTimeout( 1500 ); // update_order_review
	await page.locator( '#payment_method_bacs' ).check();
	await page.screenshot( { path: path.join( SHOTS, 'checkout-classic-bacs.png' ), fullPage: true } );
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 60000 } ), page.locator( '#place_order' ).click() ] );
	const id = ( page.url().match( /order-received\/(\d+)/ ) || [] )[ 1 ];
	await ctx.close();
	return id;
}

/** The shop manager marks a bank transfer as received: status "processing" on the order screen. */
async function adminProcessing( browser, id ) {
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 } );
	const page = await ctx.newPage();
	watch( page );
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	const hpos = 'yes' === wp( 'option', 'get', 'woocommerce_custom_orders_table_enabled' ).trim();
	await page.goto( BASE + ( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `/wp-admin/post.php?post=${ id }&action=edit` ) );
	await page.locator( '#order_status' ).selectOption( 'wc-processing' );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button.save_order' ).click() ] );
	const html = await page.content();
	await ctx.close();
	return { hpos, saved: /Order updated|Bestellung aktualisiert/i.test( html ) };
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const state = JSON.parse( st( 'co_setup' ).match( /STATE=(.*)/ )[ 1 ] );
	const browser = await chromium.launch();

	// 1. Checkout block, bank transfer: on-hold; the manager marks it received (processing). No payment_complete().
	st( 'co_mode', 'blocks' );
	const o1 = await blockOrder( browser, state.product, 'bacs', '', 'bacs' );
	// The worker runs before the manager acts (system cron every minute; the transfer arrives days later). Without
	// this pass the "on-hold" event is discarded on re-read when the status changes first (R-7.4, finding 3).
	wp( 'workflow-captain', 'process' );
	const a1 = await adminProcessing( browser, o1 );
	check( 'co-blocks-bacs-0', 'Checkout block, bank transfer: order placed (order-received page), manager sets "processing" on the order screen', !! o1 && a1.saved, { order: o1, ...a1 } );
	st( 'co_check', o1, 'blocks-bacs', 'bacs', 'store-api', 'on-hold,processing', 0 );

	// 2. Checkout block, cash on delivery: processing at once (WooCommerce does not call payment_complete() either).
	const o2 = await blockOrder( browser, state.product, 'cod', '', 'cod' );
	st( 'co_check', o2, 'blocks-cod', 'cod', 'store-api', 'processing', 0 );

	// 3. Checkout block with a 100 % coupon: nothing to pay, WooCommerce calls payment_complete() -> order.paid.
	const o3 = await blockOrder( browser, state.product, '', 'gratis100', 'free' );
	st( 'co_check', o3, 'blocks-free', 'none', 'store-api', 'processing', 1 );

	// 4. Classic shortcode checkout, bank transfer, for comparison.
	const url = st( 'co_mode', 'classic' ).match( /CHECKOUT=(.*)/ )[ 1 ].trim();
	const o4 = await classicOrder( browser, state.product, url );
	st( 'co_check', o4, 'classic-bacs', 'bacs', 'checkout', 'on-hold', 0 );
	st( 'co_mode', 'blocks' );

	// 5. An abandoned block checkout (address typed, never placed) and the personal-data search.
	st( 'co_mark' );
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	await page.goto( BASE + '/?add-to-cart=' + state.product );
	await page.goto( BASE + '/checkout/' );
	await page.locator( '#email' ).waitFor( { timeout: 30000 } );
	await page.fill( '#email', PII.email );
	await page.fill( '#shipping-first_name', PII.first );
	await page.fill( '#shipping-city', PII.city );
	await page.waitForTimeout( 3000 );
	await ctx.close();
	st( 'co_drafts' );
	st( 'co_pii' );

	check( 'co-js', 'no JS error or failed request of Workflow Captain on cart, checkout, order-received and the order screen', 0 === ours.length, ours.join( ' | ' ) );
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
