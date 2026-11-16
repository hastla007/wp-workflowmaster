/**
 * Browser test of assets/js/settings.js (headless Chromium via Playwright): confirmed reset of a tab and the
 * warning before leaving with unsaved changes (WF-SET-13).
 *
 *   node tests/settings.test.js
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

const js = 'file://' + path.resolve( __dirname, '../assets/js/settings.js' );
const html = [
	'<!doctype html><html lang="de"><head><meta charset="utf-8"></head><body>',
	'<form class="wfc-settings-form" onsubmit="return false">',
	'<input id="a" type="number" value="7" data-default="25" aria-invalid="true">',
	'<input type="hidden" name="x" value="0"><input id="b" type="checkbox" data-default="1">',
	'<button type="button" class="wfc-reset-tab">Standardwerte</button><button id="s" type="submit">Speichern</button>',
	'</form>',
	'<script>window.workflowCaptainSettings = { unsaved: "U", reset: "R?" };</script>',
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
	const file = path.join( os.tmpdir(), 'wfc-settings-test.html' );
	fs.writeFileSync( file, html );
	await page.goto( 'file://' + file );
	const value = () => page.$eval( '#a', ( x ) => x.value );
	const blocked = () => page.evaluate( () => {
		const ev = new Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( ev );
		return ev.defaultPrevented;
	} );

	check( 'no warning before any change', false === await blocked() );

	let answer = false;
	page.on( 'dialog', ( d ) => ( answer ? d.accept() : d.dismiss() ) );
	await page.click( '.wfc-reset-tab' );
	check( 'cancelled confirmation leaves the values', '7' === await value() );

	answer = true;
	await page.click( '.wfc-reset-tab' );
	check( 'confirmed reset sets the defaults of the tab', '25' === await value() && await page.$eval( '#b', ( x ) => x.checked ) );
	check( 'reset clears the field error marker', null === await page.$eval( '#a', ( x ) => x.getAttribute( 'aria-invalid' ) ) );
	check( 'unsaved changes warn before leaving', true === await blocked() );

	await page.click( '#s' );
	check( 'no warning while the form is being saved', false === await blocked() );
	check( 'no JavaScript errors', 0 === errors.length );

	await browser.close();
	fs.unlinkSync( file );
	console.log( failures ? failures + ' failed' : 'all passed' );
	process.exit( failures ? 1 : 0 );
} )();
