/**
 * Accessibility, second run (WF-BUILDER-09, WF-SET-15; not shipped): the linear step view on a small screen, focus
 * management of the builder with the keyboard only, and the target-size finding (WCAG 2.5.8) of the confirmation
 * boxes, measured in the real wp-admin.
 *
 *     AXE=/path/to/axe.min.js E2E_SHOTS=/tmp/shots NODE_PATH=$(npm root -g) node bin/e2e/a11y2.js
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const A = '/wp-admin/admin.php?page=';

let failed = 0;
function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: 'string' === typeof detail ? detail : JSON.stringify( detail || '' ) };
	failed += ok ? 0 : 1;
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
	return ok;
}
const focused = ( page ) => page.evaluate( () => {
	const a = document.activeElement;
	const id = a.getAttribute( 'data-id' );
	return a.tagName.toLowerCase() + ( id ? '#' + id : '' ) + ' ' + ( a.getAttribute( 'aria-label' ) || a.textContent || a.value || '' ).trim().slice( 0, 40 );
} );
async function login( browser, width ) {
	const page = await ( await browser.newContext( { viewport: { width, height: 900 }, deviceScaleFactor: 1 } ) ).newPage();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	return page;
}
/** Presses Tab until the focus is on an element matching the predicate (at most 80 times). */
async function tabTo( page, test ) {
	for ( let i = 0; i < 80; i++ ) {
		await page.keyboard.press( 'Tab' );
		if ( await page.evaluate( test ) ) {
			return true;
		}
	}
	return false;
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const browser = await chromium.launch();

	// ===== 390 px: linear step view, keyboard only ======================================================================
	let page = await login( browser, 390 );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=new' );
	await page.fill( '#wfc-name', 'A11y linear' );
	check( 'y1', '390 px: canvas hidden, linear step view offered (summary visible, closed)', ! ( await page.isVisible( '.wfc-canvas' ) ) && await page.isVisible( '.wfc-linear-wrap summary' ) && ! ( await page.$eval( '.wfc-linear-wrap', ( d ) => d.open ) ) );
	await page.focus( '#wfc-name' );
	const reached = await tabTo( page, () => document.activeElement.matches( '.wfc-linear-wrap summary' ) );
	await page.keyboard.press( 'Enter' );
	const steps = await page.locator( '.wfc-linear li button' ).allInnerTexts();
	check( 'y2', '390 px: the list is reached with Tab and opened with Enter; every step is a button in flow order', reached && 3 === steps.length && steps[ 0 ].startsWith( 'Auslöser' ) && steps[ 2 ] === 'Ende', steps );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Enter' );
	const afterChoose = await focused( page );
	check( 'y3', '390 px: Enter on a step opens it in the inspector, the focus goes to its heading, the list stays open', afterChoose.startsWith( 'h3' ) && afterChoose.includes( 'Nachricht an Betreiber' ) && await page.$eval( '.wfc-linear-wrap', ( d ) => d.open ), afterChoose );
	await page.screenshot( { path: path.join( SHOTS, 'a11y2-linear-390.png' ), fullPage: true } );
	await tabTo( page, () => document.activeElement.matches( '.wfc-inspector input[type=text]' ) );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' (mobil)' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 50 );
	const afterEdit = await focused( page );
	check( 'y4', '390 px: Tab after editing the subject moves on to the next control (not to the page start)', ! afterEdit.startsWith( 'body' ) && afterEdit.startsWith( 'select' ), afterEdit );
	const saveReached = await tabTo( page, () => document.activeElement.matches( 'button[name="do"][value="save"]' ) );
	await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
	const saved = JSON.parse( await page.locator( '#wfc-graph' ).inputValue() );
	check( 'y5', '390 px: saved with the keyboard; re-opened draft has the edited subject', saveReached && /edit=\d+/.test( page.url() ) && saved.nodes.find( ( n ) => 'mail' === n.id ).config.subject.endsWith( '(mobil)' ), page.url() );
	if ( process.env.AXE ) {
		await page.addScriptTag( { content: fs.readFileSync( process.env.AXE, 'utf8' ) } );
		await page.evaluate( () => document.querySelector( '.wfc-linear-wrap' ).open = true );
		const v = await page.evaluate( async () => ( await window.axe.run( document.querySelector( '.wfc-builder' ), { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] } } ) ).violations.map( ( x ) => x.id + ':' + x.nodes.length ) );
		check( 'y6', '390 px: axe-core (WCAG 2.2 AA) on the builder with the open step list: no violation', 0 === v.length, v );
	}

	// ===== 1280 px: focus after palette, delete, inspector ===========================================================
	page = await login( browser, 1280 );
	await page.goto( BASE + A + 'workflow-captain-workflows&edit=new' );
	await page.focus( '.wfc-add[data-type="condition"]' );
	await page.keyboard.press( 'Enter' );
	const afterAdd = await focused( page );
	check( 'y7', 'desktop: Enter on "+ Wenn/Sonst" puts the focus on the new node on the canvas', afterAdd.startsWith( 'g#condition1' ), afterAdd );
	await page.keyboard.press( 'Delete' );
	const afterDel = await focused( page );
	check( 'y8', 'desktop: Delete removes it and leaves the focus on a node of the flow', /^g#/.test( afterDel ), afterDel );
	await page.focus( '.wfc-node[data-id="mail"]' );
	await page.keyboard.press( 'Enter' );
	const inspectorFields = [];
	await page.focus( '.wfc-inspector input[type=text]' );
	for ( let i = 0; i < 6; i++ ) {
		await page.keyboard.type( 'x' );
		await page.keyboard.press( 'Tab' );
		await page.waitForTimeout( 30 );
		inspectorFields.push( await focused( page ) );
	}
	check( 'y9', 'desktop: Tab through the inspector while editing never drops the focus to the page start', inspectorFields.every( ( f ) => ! f.startsWith( 'body' ) ), inspectorFields );

	// ===== Target size (WCAG 2.5.8) ==================================================================================
	await page.goto( BASE + A + 'workflow-captain' );
	const box = await page.evaluate( () => {
		const cb = document.querySelector( 'input[name="confirm"]' );
		if ( ! cb ) {
			return null;
		}
		const r = cb.getBoundingClientRect();
		const l = cb.closest( 'label' ).getBoundingClientRect();
		const cx = r.left + r.width / 2;
		const cy = r.top + r.height / 2;
		// Spacing exception: a 24 px circle around the centre must not overlap another target (the label is the same target).
		let nearest = Infinity;
		document.querySelectorAll( 'a, button, input:not([type=hidden]), select, textarea' ).forEach( ( t ) => {
			if ( t === cb || ! t.getClientRects().length ) {
				return;
			}
			const b = t.getBoundingClientRect();
			const dx = Math.max( b.left - cx, 0, cx - b.right );
			const dy = Math.max( b.top - cy, 0, cy - b.bottom );
			nearest = Math.min( nearest, Math.hypot( dx, dy ) );
		} );
		return { box: [ Math.round( r.width ), Math.round( r.height ) ], label: [ Math.round( l.width ), Math.round( l.height ) ], nearest: Math.round( nearest ) };
	} );
	// The box itself is 16 px (WordPress); clicking its label ticks it, so box + label form one target of at least 24 x 24.
	check( 'y10', 'target size (WCAG 2.5.8): the pause confirmation box with its label is at least 24 x 24 px', null !== box && box.label[ 0 ] >= 24 && box.label[ 1 ] >= 24, box );
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
