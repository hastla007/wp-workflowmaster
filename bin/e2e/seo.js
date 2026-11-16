/**
 * WF-SEO-08 on the real site (not shipped: /bin is export-ignore): post actions of the core (tag, note, mail on the
 * published post) with Yoast SEO active, once in the block editor and once in the Classic Editor plugin, plus a private
 * post. Yoast metadata, the post row and the Yoast indexable must be the same before and after the workflow run.
 *
 *     E2E_URL=http://127.0.0.1:8080 E2E_SHOTS=/tmp/shots NODE_PATH=$(npm root -g) node bin/e2e/seo.js
 *
 * Needs Yoast SEO (setup.sh with E2E_YOAST=1) and the Classic Editor plugin from git (E2E_CLASSIC=1) in www.
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
const MAILS = path.join( ROOT, 'logs/mail.jsonl' );

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
function wp( ...args ) {
	return execFileSync( 'wp', args, { env: { ...process.env, E2E_DB: '', E2E_MAIL_FILE: MAILS }, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
}
const ops = ( section, param ) => wp( 'eval-file', OPS, section, String( param || '' ) );
const snap = ( post ) => JSON.parse( ops( 'seo_snapshot', post ).match( /JSON=(.*)/ )[ 1 ] );
const draft = ( title ) => ops( 'seo_draft', title ).match( /POST=(\d+)/ )[ 1 ];
const work = () => {
	for ( let i = 0; i < 3; i++ ) {
		wp( 'workflow-captain', 'process' );
	}
};
const mails = ( needle ) => ( fs.existsSync( MAILS ) ? fs.readFileSync( MAILS, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ).subject ).filter( ( s ) => s.includes( needle ) ) : [] );
const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );
// The four Yoast fields the test sets (Yoast adds empty helper fields of its own on the first save).
const KEYS = [ '_yoast_wpseo_canonical', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_yoast_wpseo_title' ];
const fieldsOf = ( s ) => s.meta.filter( ( m ) => KEYS.includes( m.meta_key ) );

/** Compares the SEO part of two snapshots; returns the keys that differ. */
function seoDiff( a, b ) {
	return [ 'meta', 'post', 'indexable' ].filter( ( k ) => ! same( a[ k ], b[ k ] ) );
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	wp( 'plugin', 'activate', 'wordpress-seo' );
	wp( 'plugin', 'deactivate', 'classic-editor' );
	const wf = ops( 'seo_setup' ).match( /WF=(\d+)/ )[ 1 ];
	const browser = await chromium.launch();
	const page = await ( await browser.newContext( { viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 } ) ).newPage();
	const ours = []; // JS errors or failed requests caused by Workflow Captain
	page.on( 'pageerror', ( e ) => /workflow-captain|workflowCaptain/i.test( String( e.stack || e ) ) && ours.push( String( e ) ) );
	page.on( 'response', ( r ) => r.status() >= 400 && r.url().includes( 'workflow-captain' ) && ours.push( r.status() + ' ' + r.url() ) );
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-e2e-pass' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	// ===== Block editor =================================================================================================
	const p1 = draft( 'SEO Block' );
	const s0 = snap( p1 );
	await page.goto( BASE + `/wp-admin/post.php?post=${ p1 }&action=edit` );
	await page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId(), null, { timeout: 30000 } );
	await page.evaluate( () => {
		wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
	} );
	const editorHtml = await page.content();
	check( 's1', 'block editor opens with Yoast active; Workflow Captain loads no script or style in the editor', ! /plugins\/workflow-captain\/|workflowCaptainBuilder/.test( editorHtml ), p1 );
	await page.waitForTimeout( 500 );
	await page.locator( '.editor-post-publish-panel__toggle, .editor-post-publish-button__button' ).first().click();
	await page.locator( '.editor-post-publish-panel .editor-post-publish-button' ).click();
	await page.waitForFunction( () => 'publish' === wp.data.select( 'core/editor' ).getEditedPostAttribute( 'status' ) && ! wp.data.select( 'core/editor' ).isSavingPost(), null, { timeout: 30000 } );
	await page.waitForTimeout( 1000 );
	await page.screenshot( { path: path.join( SHOTS, 'seo-block-published.png' ) } );
	const s1 = snap( p1 );
	check( 's2', 'block editor: published through the UI, one wordpress.post.published event, Yoast fields kept by the save', 'publish' === s1.post.post_status && 1 === s1.events && same( fieldsOf( s0 ), fieldsOf( s1 ) ), { added_by_yoast: s1.meta.filter( ( m ) => ! KEYS.includes( m.meta_key ) ).map( ( m ) => m.meta_key ) } );
	work();
	const s2 = snap( p1 );
	check( 's3', 'block editor: workflow ran (tag, note, one mail on the post)', same( s2.tags, [ 'seo-gesehen' ] ) && 1 === s2.notes.length && s2.notes[ 0 ].includes( p1 ) && 1 === mails( 'SEO Beitrag ' + p1 ).length, { tags: s2.tags, notes: s2.notes } );
	check( 's4', 'block editor: Yoast meta, post row and Yoast indexable unchanged by the workflow run', 0 === seoDiff( s1, s2 ).length && null !== s2.indexable, { diff: seoDiff( s1, s2 ), indexable: s2.indexable } );
	await page.goto( BASE + `/wp-admin/post.php?post=${ p1 }&action=edit` );
	await page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ).getCurrentPostId(), null, { timeout: 30000 } );
	const head = await ( await page.request.get( BASE + `/?p=${ p1 }` ) ).text();
	check( 's5', 'front end of the post shows the Yoast title and description after the run', head.includes( 'SEO Titel SEO Block' ) && head.includes( 'Beschreibung für SEO Block' ), ( head.match( /<title>[^<]*<\/title>/ ) || [ '' ] )[ 0 ] );

	// ===== Classic Editor =============================================================================================
	wp( 'plugin', 'activate', 'classic-editor' );
	wp( 'option', 'update', 'classic-editor-replace', 'classic' );
	const p2 = draft( 'SEO Classic' );
	const c0 = snap( p2 );
	await page.goto( BASE + `/wp-admin/post.php?post=${ p2 }&action=edit` );
	const classic = ( await page.locator( '#post #publish' ).count() ) === 1;
	const yoastBox = await page.locator( '#wpseo_meta' ).count();
	check( 's6', 'classic editor (plugin from git) opens with the Yoast metabox; no Workflow Captain script or style', classic && yoastBox > 0 && ! /plugins\/workflow-captain\/|workflowCaptainBuilder/.test( await page.content() ), { classic, yoastBox } );
	await Promise.all( [ page.waitForNavigation(), page.locator( '#publish' ).click() ] );
	await page.screenshot( { path: path.join( SHOTS, 'seo-classic-published.png' ) } );
	const c1 = snap( p2 );
	check( 's7', 'classic editor: published with the form, one event, Yoast fields of the metabox saved back', 'publish' === c1.post.post_status && 1 === c1.events && same( fieldsOf( c0 ), fieldsOf( c1 ) ), { added_by_yoast: c1.meta.filter( ( m ) => ! KEYS.includes( m.meta_key ) ).map( ( m ) => m.meta_key ) } );
	work();
	const c2 = snap( p2 );
	check( 's8', 'classic editor: workflow ran (tag, note, one mail)', same( c2.tags, [ 'seo-gesehen' ] ) && 1 === c2.notes.length && 1 === mails( 'SEO Beitrag ' + p2 ).length );
	check( 's9', 'classic editor: Yoast meta, post row and Yoast indexable unchanged by the workflow run', 0 === seoDiff( c1, c2 ).length && null !== c2.indexable, { diff: seoDiff( c1, c2 ), indexable: c2.indexable } );
	// Update the published post once more in the classic editor: no second event, no second run, meta unchanged.
	await page.goto( BASE + `/wp-admin/post.php?post=${ p2 }&action=edit` );
	await Promise.all( [ page.waitForNavigation(), page.locator( '#publish' ).click() ] );
	work();
	const c3 = snap( p2 );
	check( 's10', 'classic editor: "Aktualisieren" of the published post starts nothing again (one event, one note, one mail)', 1 === c3.events && 1 === c3.notes.length && 1 === mails( 'SEO Beitrag ' + p2 ).length && same( c2.meta, c3.meta ) );

	// ===== Private post ===============================================================================================
	const p3 = draft( 'SEO Privat' );
	await page.goto( BASE + `/wp-admin/post.php?post=${ p3 }&action=edit` );
	await page.locator( '#visibility .edit-visibility' ).click();
	await page.locator( '#visibility-radio-private' ).check();
	await page.locator( '#post-visibility-select .save-post-visibility' ).click();
	await Promise.all( [ page.waitForNavigation(), page.locator( '#publish' ).click() ] );
	work();
	const v = snap( p3 );
	check( 's11', 'private post: no wordpress.post.published event, no tag, no note (only public publishing counts)', 'private' === v.post.post_status && 0 === v.events && 0 === v.tags.length && 0 === v.notes.length, { status: v.post.post_status, events: v.events } );

	check( 's12', 'no JS error or failed request of Workflow Captain in either editor', 0 === ours.length, ours.join( ' | ' ) );
	await browser.close();
	wp( 'plugin', 'deactivate', 'classic-editor' );
	wp( 'option', 'delete', 'classic-editor-replace' );
	wp( 'plugin', 'deactivate', 'wordpress-seo' );
	void wf;
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
