/**
 * Accessibility E2E (WF-BUILDER-09, WF-SET-15; not shipped). Runs axe-core on every Workflow Captain admin page at
 * desktop width and at 390 px, and drives the builder and the settings form with the keyboard only.
 *
 *     AXE=/path/to/node_modules/axe-core/axe.min.js E2E_SHOTS=/tmp/shots node bin/e2e/a11y.js
 *
 * Only violations inside the plugin's own markup count (the .wrap of the page); WordPress and WooCommerce chrome
 * (admin menu, notices of other plugins) are reported separately as context. One JSON line per check.
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.E2E_SHOTS || process.cwd();
const RESULTS = process.env.E2E_RESULTS || '';
const AXE = fs.readFileSync( process.env.AXE, 'utf8' );
const a = '/wp-admin/admin.php?page=';

let failed = 0;
function check( id, name, ok, detail ) {
	const row = { id, name, result: ok ? 'PASS' : 'FAIL', detail: 'string' === typeof detail ? detail : JSON.stringify( detail ) };
	failed += ok ? 0 : 1;
	console.log( JSON.stringify( row ) );
	if ( RESULTS ) {
		fs.appendFileSync( RESULTS, JSON.stringify( row ) + '\n' );
	}
}

async function axe( page ) {
	await page.addScriptTag( { content: AXE } );
	return page.evaluate( async () => {
		const r = await window.axe.run( document, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] } } );
		return r.violations.map( ( v ) => ( {
			id: v.id,
			impact: v.impact,
			own: v.nodes.filter( ( n ) => n.target.some( ( t ) => document.querySelector( t ) && document.querySelector( t ).closest( '#wpbody-content .wrap' ) && ! document.querySelector( t ).closest( '.notice:not(.wfc-notice)' ) ) ).map( ( n ) => n.target.join( ' ' ) + ' :: ' + n.failureSummary.replace( /\s+/g, ' ' ).slice( 0, 160 ) ),
			other: v.nodes.length,
		} ) );
	} );
}

( async () => {
	const browser = await chromium.launch();
	for ( const [ label, width ] of [ [ 'desktop', 1280 ], [ 'mobile', 390 ] ] ) {
		const context = await browser.newContext( { viewport: { width, height: 900 }, deviceScaleFactor: 1 } );
		const page = await context.newPage();
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', process.env.E2E_USER || 'admin' );
		await page.fill( '#user_pass', process.env.E2E_PASS || 'admin-e2e-pass' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
		const pages = {
			overview: 'workflow-captain',
			workflows: 'workflow-captain-workflows',
			builder: 'workflow-captain-workflows&edit=new',
			runs: 'workflow-captain-runs',
			events: 'workflow-captain-events',
			providers: 'workflow-captain-providers',
			templates: 'workflow-captain-templates',
			help: 'workflow-captain-help',
			connectors: 'workflow-captain-connectors',
		};
		for ( const tab of [ 'general', 'execution', 'notify', 'privacy', 'roles', 'status' ] ) {
			pages[ 'settings-' + tab ] = 'workflow-captain-settings&tab=' + tab;
		}
		for ( const [ name, slug ] of Object.entries( pages ) ) {
			await page.goto( BASE + a + slug );
			const v = await axe( page );
			const own = v.filter( ( x ) => x.own.length );
			check( `x-${ label }-${ name }`, `axe (WCAG 2.2 AA) ${ name } at ${ width }px: no violation in Workflow Captain markup`, 0 === own.length, own.map( ( x ) => `${ x.id} (${ x.impact }): ${ x.own.slice( 0, 3 ).join( ' | ' ) }` ).join( ' ;; ' ) || `outside plugin: ${ v.map( ( x ) => x.id ).join( ',' ) || 'none' }` );
			if ( 'mobile' === label ) {
				const overflow = await page.evaluate( () => {
					const wrap = document.querySelector( '#wpbody-content .wrap' );
					return wrap ? Math.max( 0, wrap.scrollWidth - wrap.clientWidth ) : -1;
				} );
				check( `x-${ label }-${ name }-overflow`, `${ name } at 390px: plugin content does not overflow horizontally`, overflow <= 1, overflow + 'px' );
				await page.screenshot( { path: path.join( SHOTS, `a11y-390-${ name }.png` ), fullPage: true } );
			}
		}

		if ( 'desktop' === label ) {
			// Keyboard-only builder flow: Tab to a node, arrows move it, C + Enter connects, Delete removes.
			await page.goto( BASE + a + 'workflow-captain-workflows&edit=new' );
			await page.waitForSelector( '.wfc-canvas .wfc-node' );
			const graph = () => page.evaluate( () => JSON.parse( document.getElementById( 'wfc-graph' ).value ) );
			// Start at the last toolbar button (a keyboard user reaches the toolbar first), then Tab into the canvas.
			await page.focus( '.wfc-zoom-in' );
			let reached = null;
			for ( let i = 0; i < 20 && ! reached; i++ ) {
				await page.keyboard.press( 'Tab' );
				reached = await page.evaluate( () => ( document.activeElement && document.activeElement.classList.contains( 'wfc-node' ) ? document.activeElement.getAttribute( 'data-id' ) : null ) );
			}
			check( 'x-kb-1', 'builder: a node is reachable with Tab', !! reached, String( reached ) );
			const focusVisible = await page.evaluate( () => {
				const el = document.activeElement;
				const s = getComputedStyle( el );
				return s.outlineStyle !== 'none' || ( el.querySelector( 'rect' ) && getComputedStyle( el.querySelector( 'rect' ) ).strokeWidth !== '0px' );
			} );
			check( 'x-kb-1b', 'builder: focused node has a visible focus indicator', focusVisible );
			// Positions come from the canvas (the builder lays out nodes without stored x/y), read from the transform.
			const pos = () => page.evaluate( ( id ) => document.querySelector( `.wfc-node[data-id="${ id }"]` ).getAttribute( 'transform' ).match( /-?[0-9.]+/g ).map( Number ), reached );
			const before = await pos();
			await page.keyboard.press( 'ArrowRight' );
			await page.keyboard.press( 'ArrowDown' );
			const after = await pos();
			const stored = ( await graph() ).nodes.find( ( n ) => n.id === reached );
			const stillFocused = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'data-id' ) );
			check( 'x-kb-2', 'builder: arrow keys move the node (+10/+10), store the position and keep focus', after[ 0 ] === before[ 0 ] + 10 && after[ 1 ] === before[ 1 ] + 10 && stored.x === after[ 0 ] && stored.y === after[ 1 ] && stillFocused === reached, `${ before } -> ${ after }, stored ${ stored.x },${ stored.y }, focus ${ stillFocused }` );
			// Add an end node with the palette (keyboard: focus button + Enter), then connect the end of the chain to it.
			const nodesBefore = ( await graph() ).nodes.length;
			await page.focus( '.wfc-add[data-type="end"]' );
			await page.keyboard.press( 'Enter' );
			const g1 = await graph();
			check( 'x-kb-3', 'builder: palette button works with Enter', g1.nodes.length === nodesBefore + 1, nodesBefore + ' -> ' + g1.nodes.length );
			const newEnd = g1.nodes[ g1.nodes.length - 1 ].id;
			// Remove the edge mail -> end, then connect mail -> newEnd by keyboard.
			const edgesBefore = g1.edges.length;
			await page.focus( `.wfc-node[data-id="end"]` );
			await page.keyboard.press( 'Delete' );
			const g2 = await graph();
			check( 'x-kb-4', 'builder: Delete removes the focused node and its edges', ! g2.nodes.some( ( n ) => 'end' === n.id ) && g2.edges.length === edgesBefore - 1, `${ g2.nodes.length } nodes, ${ g2.edges.length } edges` );
			await page.focus( `.wfc-node[data-id="mail"]` );
			await page.keyboard.press( 'c' );
			const live = await page.evaluate( () => Array.from( document.querySelectorAll( '[aria-live]' ) ).map( ( e ) => e.textContent ).join( ' ' ) );
			await page.focus( `.wfc-node[data-id="${ newEnd }"]` );
			await page.keyboard.press( 'Enter' );
			const g3 = await graph();
			check( 'x-kb-5', 'builder: C then Enter on the target connects (announced in a live region)', g3.edges.some( ( e ) => 'mail' === e.from && e.to === newEnd ) && live.length > 0, `live: ${ live.trim().slice( 0, 120 ) }` );
			await page.screenshot( { path: path.join( SHOTS, 'a11y-builder-keyboard.png' ), fullPage: true } );

			// Keyboard-only settings: Tab to the batch field, type, Enter submits.
			await page.goto( BASE + a + 'workflow-captain-settings&tab=execution' );
			let onField = false;
			await page.focus( '.nav-tab-active' );
			for ( let i = 0; i < 40 && ! onField; i++ ) {
				await page.keyboard.press( 'Tab' );
				onField = await page.evaluate( () => document.activeElement && 'wfc-batch' === document.activeElement.id );
			}
			check( 'x-kb-6', 'settings: field reachable with Tab and has a label', onField && await page.evaluate( () => !! document.querySelector( 'label[for="wfc-batch"]' ) ) );
			await page.keyboard.press( 'Control+A' );
			await page.keyboard.type( '40' );
			await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
			check( 'x-kb-7', 'settings: Enter in the field saves', '40' === await page.locator( '#wfc-batch' ).inputValue() );
			await page.locator( '#wfc-batch' ).fill( '25' );
			await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
		}
		await context.close();
	}
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
