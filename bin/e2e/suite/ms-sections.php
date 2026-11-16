<?php
/**
 * Multisite sections of bin/e2e/suite/checks.php (run with wpsuitems, see ms.sh): network-wide workflows of Workflow
 * Captain Pro with one activation per participating subsite (B2), the seats of the license contract (WF-MS-22), the open
 * cases of WF-MS-23 and the providers per site (T103). Not shipped.
 *
 * Sections without "--url" run on the main site, which is the Network Admin here and holds no activation of its own
 * (WORKFLOW-NETZWERK-BETRIEB.md, section 2: managing needs only one participating site with a license).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;

/** The MultisiteNetwork feature object of Pro. */
function s_net() {
	$pro = s_pro();
	foreach ( $pro ? (array) $pro->features()->all() : array() as $f ) {
		if ( $f instanceof \WorkflowCaptainPro\Features\MultisiteNetwork ) {
			return $f;
		}
	}
	return null;
}

function s_net_rows( string $table, string $where = '1=1' ): array {
	global $wpdb;
	return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->base_prefix}workflow_captain_pro_net_{$table} WHERE {$where} ORDER BY id", ARRAY_A ); // phpcs:ignore
}

/** Steps of a target: notify.admin and a tag (default), or the given list. */
function s_net_steps( string $tag ): array {
	return array(
		array( 'id' => 'mail', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Netz ' . $tag . ' {{subject.id}}', 'message' => 'Typ {{data.post_type}}' ) ),
		array( 'id' => 'tag', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => $tag ) ),
	);
}

/** Network workflow: source site -> targets; $steps per blog ID overrides the default steps. */
function s_net_def( int $source, array $targets, string $tag, array $steps = array() ): array {
	$t = array();
	foreach ( $targets as $blog ) {
		$t[] = array(
			'blog_id' => $blog,
			'steps'   => $steps[ $blog ] ?? s_net_steps( $tag ),
		);
	}
	return array(
		'source'  => array( 'blog_id' => $source, 'event_type' => 'wordpress.post.published' ),
		'fields'  => array( 'post_type' ),
		'targets' => $t,
	);
}

/** Save and publish as the given user; returns [id, violations]. */
function s_net_publish( int $user, string $name, array $def ): array {
	$svc = s_net()->service();
	$d   = $svc->save_draft( $user, null, $name, $def );
	return array( (int) $d['id'], $d['id'] ? $svc->publish( $user, (int) $d['id'] ) : $d['violations'] );
}

/** Tags of the core on a site. */
function s_site_tags( int $blog, string $tag ): int {
	global $wpdb;
	$p = $wpdb->get_blog_prefix( $blog );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}workflow_captain_tags WHERE tag = %s", $tag ) ); // phpcs:ignore
}

function s_site_events( int $blog, string $like ): array {
	global $wpdb;
	$p = $wpdb->get_blog_prefix( $blog );
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, state, last_error FROM {$p}workflow_captain_events WHERE type LIKE %s ORDER BY id", $like ), ARRAY_A ); // phpcs:ignore
}

function s_children( int $parent ): array {
	$out = array();
	foreach ( s_net_rows( 'steps', 'run_id = ' . (int) $parent ) as $st ) {
		$out[ (int) $st['target_blog_id'] ] = $st['state'] . ( '' !== (string) ( $st['last_error'] ?? '' ) ? ':' . $st['last_error'] : '' );
	}
	ksort( $out );
	return $out;
}

/** Newest parent run of a network workflow. */
function s_parent( int $wf ): array {
	$runs = s_net_rows( 'runs', 'workflow_id = ' . (int) $wf );
	return $runs ? end( $runs ) : array();
}

/** State of the local runs on the target sites of a parent run: blog => state:reason (inspect of the target queue). */
function s_local( int $parent ): array {
	$out   = array();
	$steps = s_net_rows( 'steps', 'run_id = ' . (int) $parent );
	$live  = s_net()->service()->inspect_run( $parent );
	foreach ( $steps as $st ) {
		$l                                  = $live[ (int) $st['id'] ] ?? null;
		$out[ (int) $st['target_blog_id'] ] = is_array( $l ) ? $l['state'] . ( '' !== (string) ( $l['last_error'] ?? '' ) ? ':' . $l['last_error'] : '' ) : 'none';
	}
	ksort( $out );
	return $out;
}

/** Network reports of the sites: blog => licensed/state/limit/count. */
function s_reports(): array {
	$out = array();
	foreach ( s_net()->store()->site_reports() as $blog => $r ) {
		$out[ (int) $blog ] = array(
			'licensed' => (bool) $r['licensed'],
			'state'    => (string) $r['license_state'],
			'limit'    => (int) $r['site_limit'],
			'count'    => (int) $r['site_count'],
		);
	}
	ksort( $out );
	return $out;
}

/** This site reports its license seat and queue now (as it does on wp_loaded when something changed). */
function s_report_now(): void {
	delete_transient( 'workflow_captain_pro_net_report' );
	s_net()->report_site();
}

/** Mails of the multisite log whose subject starts with $prefix. */
function s_ms_mails( string $prefix ): array {
	return array_values( array_filter( array_map( static fn( $l ) => json_decode( $l, true ), (array) @file( '/home/user/wp-e2e/logs/mail-suite-ms.jsonl', FILE_IGNORE_NEW_LINES ) ), static fn( $m ) => is_array( $m ) && str_starts_with( (string) $m['subject'], $prefix ) ) );
}

$super = s_admin_id();

switch ( $s_section ) {

	// ---- Licenses: one activation per participating subsite (B2) ---------------------------------------------------
	case 'ms_prepare':
		$pro = s_pro();
		s_check( 'ms1', 'Pro network-activated licenses per site (kernel option network is off; every subsite has its own activation, B2)', $pro && true !== (bool) $pro->license()->config( 'network' ), $pro ? var_export( $pro->license()->config( 'network' ), true ) : 'no addon' );
		$net = s_net();
		s_check( 'ms4', 'network tables created (schema current)', $net && 'current' === $net->maybe_migrate() && $net->schema_current(), $net ? $net->maybe_migrate() : 'no feature' );
		break;

	case 'ms_site_license':
		// Run with --url of the site. Param <plan>[:<environment>[:refused]]. The previous activation is released first.
		$parts = explode( ':', $s_param );
		$plan  = $parts[0];
		$env   = $parts[1] ?? 'production';
		$want  = $parts[2] ?? 'ok';
		$pro   = s_pro();
		$keys  = (array) s_state( 'keys' );
		$blog  = get_current_blog_id();
		if ( $pro->has_activation() ) {
			$pro->license()->deactivate();
		}
		$r  = $pro->license()->activate( (string) $keys[ $plan ], $env );
		$st = $pro->license()->status();
		if ( 'refused' === $want ) {
			$code = is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : 'activated';
			s_check( 'ms-seat-server/' . $blog, 'site ' . $blog . ': a further live activation of the ' . $plan . ' license is refused by the license server (live-site limit of the contract)', is_wp_error( $r ) && ! $st->access, $code );
		} else {
			$ok = ! is_wp_error( $r ) && $st->access;
			s_check( 'ms2/' . $blog . '/' . $plan, 'site ' . $blog . ': own activation of the ' . $plan . ' license (' . $env . ') against the shop', $ok, is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : $st->state . ' ' . $st->environment . ' ' . $st->site_count . '/' . $st->site_limit );
		}
		s_report_now();
		break;

	case 'ms_site_unlicense':
		$pro = s_pro();
		$r   = $pro->license()->deactivate();
		s_check( 'ms-release/' . get_current_blog_id(), 'site ' . get_current_blog_id() . ': activation released (seat back to the license)', ! is_wp_error( $r ) && ! $pro->license()->status()->access, is_wp_error( $r ) ? $r->get_error_code() : 'released' );
		s_report_now();
		break;

	case 'ms_site_refresh':
		$pro = s_pro();
		s_refresh( $pro );
		s_report_now();
		if ( '' !== $s_param ) {
			$access = $pro->license()->status()->access;
			s_check( 'ms-refresh/' . $s_param . '/' . get_current_blog_id(), 'site ' . get_current_blog_id() . ' refreshed its own license from the shop (' . $s_param . ')', 'expired' === $s_param ? ! $access : $access, $pro->license()->status()->state );
		}
		break;

	case 'ms_reports':
		$rep  = s_reports();
		$want = array();
		foreach ( array( 2, 3, 4, 5 ) as $b ) {
			$want[ $b ] = array( 'licensed' => true, 'state' => 'active', 'limit' => 4, 'count' => 4 );
		}
		s_check( 'ms3', 'every participating site reports its own seat in the network table (licensed, site_limit 4, site_count 4 of the license)', array_intersect_key( $rep, $want ) == $want, $rep );
		$pro = s_pro();
		s_check( 'ms3b', 'main site without activation: the feature is off there, the Network Admin still works (a participating site holds a license)', ! $pro->features()->is_active( 'workflow.multisite_network' ) && empty( $rep[1]['licensed'] ) && null === s_net()->service()->admin_problem(), array( 'main' => $rep[1] ?? null, 'admin_problem' => s_net()->service()->admin_problem() ) );
		break;

	// ---- Consents, permissions, publishing -------------------------------------------------------------------------
	case 'ms_setup':
		$net = s_net();
		$svc = $net->service();
		$sa  = (int) wpmu_create_user( 'siteadmin3', 'siteadmin-e2e-pass', 'siteadmin3@example.test' );
		add_user_to_blog( 3, $sa, 'administrator' );
		s_state( 'site_admin', $sa );
		$draft = $svc->save_draft( $sa, null, 'Nicht erlaubt', s_net_def( 2, array( 3 ), 'x' ) );
		s_check( 'ms5', 'site admin cannot define network workflows (permission:super_admin)', 0 === $draft['id'] && in_array( 'permission:super_admin', $draft['violations'], true ), $draft );
		s_check( 'ms6', 'site admin of site 3 may not release site 4, but its own site 3', ! $svc->grant( $sa, 4, 'target', 'notify.admin' ) && $svc->grant( $sa, 3, 'target', 'notify.admin' ), 'ok' );
		$svc->grant( $super, 3, 'target', 'workflow.tag.set' );
		foreach ( array( 4, 5 ) as $b ) {
			$svc->grant( $super, $b, 'target', 'notify.admin' );
			$svc->grant( $super, $b, 'target', 'workflow.tag.set' );
		}
		// The core events are not declared network-publishable (only the suite adapters of Pro declare theirs).
		delete_site_option( 'e2e_suite_net_publish' );
		list( , $p0 ) = s_net_publish( $super, 'Suite nicht netzfähig', s_net_def( 2, array( 3 ), 'netz-x' ) );
		s_check( 'ms7a', 'wordpress.post.published is not declared network-publishable: publishing is refused (source:not_publishable)', in_array( 'source:not_publishable', $p0, true ), $p0 );
		update_site_option( 'e2e_suite_net_publish', 1 ); // Declared by the suite mu-plugin through the documented filter.
		$net->policy()->publishable()->flush();
		list( $a, $pa ) = s_net_publish( $super, 'Suite A nach B', s_net_def( 2, array( 3 ), 'netz-ab' ) );
		list( $f, $pf ) = s_net_publish( $super, 'Suite Fan-out', s_net_def( 2, array( 3, 4, 5 ), 'netz-fan' ) );
		s_check( 'ms7', 'super admin publishes "site A -> site B" and a fan-out to three sites (main site without activation)', array() === $pa && array() === $pf, array( $a, $pa, $f, $pf ) );
		s_state( 'net_wf', array( 'ab' => $a, 'fan' => $f ) );
		$prev = $svc->preview( s_net_def( 2, array( 3, 4, 5 ), 'netz-fan' ) );
		$lic  = array_map( static fn( $t ) => $t['licensed'], $prev['targets'] );
		s_check( 'ms8', 'preview: source not released yet, 4 sites of 4 (site_limit of the license, no filter), every site with its own activation', false === $prev['source']['consented'] && 4 === $prev['seats']['used'] && 4 === $prev['seats']['limit'] && 4 === $prev['seats']['license_limit'] && array() === $prev['seats']['unlicensed'] && $prev['source']['licensed'] && array( 3 => true, 4 => true, 5 => true ) === $lic && null === $prev['license'], array( 'source' => $prev['source'], 'seats' => $prev['seats'], 'targets' => $lic ) );
		break;

	case 'ms_source_check':
		$wf = (array) s_state( 'net_wf' );
		s_check( 'ms9', 'source not released: no network run starts (source_not_consented), nothing reaches the targets', array() === s_parent( (int) $wf['ab'] ) && 0 === s_site_tags( 3, 'netz-ab' ), s_net_rows( 'runs' ) );
		$ok = s_net()->service()->grant( $super, 2, 'source', 'wordpress.post', array( 'post_type' ) );
		s_check( 'ms10', 'site 2 releases the event family wordpress.post with the field post_type', $ok );
		break;

	case 'ms_run_check':
		$wf  = (array) s_state( 'net_wf' );
		$ab  = s_parent( (int) $wf['ab'] );
		$fan = s_parent( (int) $wf['fan'] );
		s_check( 'ms11', 'site A -> site B: parent run succeeded, child step on site 3 succeeded, tag on site 3', $ab && 'succeeded' === $ab['state'] && array( 3 => 'succeeded' ) === s_children( (int) $ab['id'] ) && 1 === s_site_tags( 3, 'netz-ab' ), array( 'parent' => $ab['state'] ?? null, 'children' => $ab ? s_children( (int) $ab['id'] ) : null ) );
		s_check( 'ms12', 'fan-out: one child step per target (3, 4, 5), all succeeded, tag on each target', $fan && 'succeeded' === $fan['state'] && array( 3 => 'succeeded', 4 => 'succeeded', 5 => 'succeeded' ) === s_children( (int) $fan['id'] ) && 1 === s_site_tags( 4, 'netz-fan' ) && 1 === s_site_tags( 5, 'netz-fan' ), array( 'parent' => $fan['state'] ?? null, 'children' => $fan ? s_children( (int) $fan['id'] ) : null ) );
		s_check( 'ms13', 'nothing ran on the source site itself or on the main site (no tag there)', 0 === s_site_tags( 2, 'netz-fan' ) + s_site_tags( 1, 'netz-fan' ) );
		$mails = s_ms_mails( 'Netz netz-fan' );
		s_check( 'ms14', 'fan-out: three admin mails, one per target site, with the released field', 3 === count( $mails ) && str_contains( (string) $mails[0]['message'], 'post' ), array_column( $mails, 'subject' ) );
		$data = wp_json_encode( s_net_rows( 'runs' ) ) . wp_json_encode( s_net_rows( 'steps' ) );
		s_check( 'ms15', 'no actor_user_id and no post title in the network tables (field allowlist, R-17.3)', ! str_contains( $data, 'actor_user_id' ) && ! str_contains( $data, 'Netz-Beitrag' ), strlen( $data ) . ' bytes' );
		s_net()->service()->revoke( $super, 5, 'target', 'workflow.tag.set' );
		break;

	case 'ms_consent_check':
		$wf  = (array) s_state( 'net_wf' );
		$fan = s_parent( (int) $wf['fan'] );
		$ch  = s_children( (int) $fan['id'] );
		s_check( 'ms16', 'target without consent: its child step pauses (paused_site_consent), the others succeed (partial)', 'succeeded' === ( $ch[3] ?? '' ) && 'succeeded' === ( $ch[4] ?? '' ) && str_starts_with( (string) ( $ch[5] ?? '' ), 'paused_site_consent' ), array( 'parent' => $fan['state'], 'children' => $ch ) );
		s_state( 'consent_run', (int) $fan['id'] );
		// The new consent is given in the Network Admin (main site, no activation of its own).
		s_net()->service()->grant( $super, 5, 'target', 'workflow.tag.set' );
		break;

	case 'ms_consent_resume':
		$ch = s_children( (int) s_state( 'consent_run' ) );
		s_check( 'ms17', 'new consent (given in the Network Admin) resumes the paused child step on site 5 (after its worker ran)', 'succeeded' === ( $ch[5] ?? '' ) && 2 === s_site_tags( 5, 'netz-fan' ), $ch );
		break;

	// ---- WF-MS-22: seats of the license contract ---------------------------------------------------------------------
	case 'ms_seats_contract':
		// Site 1 has no activation: the license server refused a fifth live site (ms-seat-server/1).
		list( $id, $p ) = s_net_publish( $super, 'Suite zu viele', s_net_def( 2, array( 3, 4, 5, 1 ), 'netz-limit' ) );
		s_state( 'limit_wf', $id );
		s_check( 'ms18', 'five sites with a license for four: publishing is refused (seats:limit_exceeded from the signed site_limit, seats:site_unlicensed:1)', in_array( 'seats:limit_exceeded', $p, true ) && in_array( 'seats:site_unlicensed:1', $p, true ), $p );
		$prev = s_net()->service()->preview( s_net_def( 2, array( 3, 4, 5, 1 ), 'netz-limit' ) );
		s_check( 'ms18b', 'preview names the site without its own activation and "5 sites of 4"', array( 1 ) === $prev['seats']['unlicensed'] && 5 === $prev['seats']['used'] && 4 === $prev['seats']['limit'] && false === $prev['targets'][1]['licensed'], $prev['seats'] );
		break;

	case 'ms_seats_dev':
		// Site 1 now holds a development activation (no live seat on the server): only the limit of the contract remains.
		$p = s_net()->service()->publish( $super, (int) s_state( 'limit_wf' ) );
		s_check( 'ms18c', 'site 1 with a development activation: still refused, only seats:limit_exceeded (blog IDs count against site_limit)', array( 'seats:limit_exceeded' ) === $p, $p );
		break;

	// ---- License change and expiry per site --------------------------------------------------------------------------
	case 'ms_wait_prep':
		// A post on site 2, only the source worker ran: the child steps wait in the queues of the targets.
		$wf  = (array) s_state( 'net_wf' );
		$fan = s_parent( (int) $wf['fan'] );
		s_state( $s_param, (int) $fan['id'] );
		s_state( 'tags_' . $s_param, array( 3 => s_site_tags( 3, 'netz-fan' ), 4 => s_site_tags( 4, 'netz-fan' ), 5 => s_site_tags( 5, 'netz-fan' ) ) );
		s_check( 'ms22/' . $s_param, 'fan-out child steps wait for the target workers (' . $s_param . ')', array( 3, 4, 5 ) === array_keys( s_children( (int) $fan['id'] ) ) && array() === array_filter( s_children( (int) $fan['id'] ), static fn( $s ) => ! str_starts_with( $s, 'waiting_site_worker' ) ), s_children( (int) $fan['id'] ) );
		break;

	case 'ms_switch_target_check':
		// Site 4 switched to the basic license (no workflow.multisite_network) while its child step waits.
		$run = (int) s_state( 'switch_run' );
		$rep = s_reports();
		$ch  = s_children( $run );
		s_check( 'ms27', 'site 4 changed to a license without the network right: it reports no seat, its waiting child step blocks at once (site_license_inactive), sites 3 and 5 keep waiting', empty( $rep[4]['licensed'] ) && 'active' === ( $rep[4]['state'] ?? '' ) && 'blocked_provider:site_license_inactive' === ( $ch[4] ?? '' ) && str_starts_with( (string) ( $ch[3] ?? '' ), 'waiting' ) && str_starts_with( (string) ( $ch[5] ?? '' ), 'waiting' ), array( 'report4' => $rep[4] ?? null, 'children' => $ch ) );
		break;

	case 'ms_switch_target_run':
		$run    = (int) s_state( 'switch_run' );
		$before = (array) s_state( 'tags_switch_run' );
		$ch     = s_children( $run );
		$local  = s_local( $run );
		s_check( 'ms28', 'after the target workers: sites 3 and 5 succeeded, site 4 ran nothing (its local run paused, no tag)', 'succeeded' === ( $ch[3] ?? '' ) && 'succeeded' === ( $ch[5] ?? '' ) && str_starts_with( (string) ( $ch[4] ?? '' ), 'blocked_provider' ) && str_starts_with( (string) ( $local[4] ?? '' ), 'paused' ) && s_site_tags( 4, 'netz-fan' ) === (int) $before[4], array( 'children' => $ch, 'local' => $local ) );
		list( , $p ) = s_net_publish( $super, 'Suite ohne Platz', s_net_def( 2, array( 4 ), 'netz-ohne' ) );
		s_check( 'ms29', 'a new network workflow with site 4 cannot be published (seats:site_unlicensed:4)', in_array( 'seats:site_unlicensed:4', $p, true ), $p );
		break;

	case 'ms_switch_new_run':
		// A new post on site 2 while site 4 has no seat: nothing is queued on site 4.
		$wf  = (array) s_state( 'net_wf' );
		$fan = s_parent( (int) $wf['fan'] );
		$ch  = s_children( (int) $fan['id'] );
		s_state( 'switch_run2', (int) $fan['id'] );
		s_check( 'ms30', 'new run while site 4 has no seat: its child step is blocked before queueing (site_license_inactive), sites 3 and 5 get theirs', (int) $fan['id'] !== (int) s_state( 'switch_run' ) && 'blocked_provider:site_license_inactive' === ( $ch[4] ?? '' ) && 'none' === ( s_local( (int) $fan['id'] )[4] ?? '' ) && 'succeeded' === ( $ch[3] ?? '' ) && 'succeeded' === ( $ch[5] ?? '' ), array( 'children' => $ch, 'local' => s_local( (int) $fan['id'] ) ) );
		break;

	case 'ms_switch_back':
		// Site 4 has the network license again (and its worker ran): nothing continues by itself.
		$a = s_children( (int) s_state( 'switch_run' ) );
		$b = s_children( (int) s_state( 'switch_run2' ) );
		s_check( 'ms31', 'seat back on site 4: nothing continues by itself (R-17.9, needs "resume run")', str_starts_with( (string) ( $a[4] ?? '' ), 'blocked_provider' ) && str_starts_with( (string) ( $b[4] ?? '' ), 'blocked_provider' ), array( $a, $b ) );
		$n = s_net()->service()->resume_run( $super, (int) s_state( 'switch_run' ) ) + s_net()->service()->resume_run( $super, (int) s_state( 'switch_run2' ) );
		s_check( 'ms31b', '"resume run" in the Network Admin (main site without activation) continues the two child steps of site 4', 2 === $n, $n );
		break;

	case 'ms_switch_done':
		$a = s_children( (int) s_state( 'switch_run' ) );
		$b = s_children( (int) s_state( 'switch_run2' ) );
		$t = (array) s_state( 'tags_switch_run' );
		s_check( 'ms32', 'after resume and the worker of site 4: both child steps of site 4 succeeded, sites 3 and 5 ran each run exactly once', 'succeeded' === ( $a[4] ?? '' ) && 'succeeded' === ( $b[4] ?? '' ) && 'succeeded' === ( $b[3] ?? '' ) && 'succeeded' === ( $b[5] ?? '' ) && s_site_tags( 3, 'netz-fan' ) === (int) $t[3] + 2 && s_site_tags( 4, 'netz-fan' ) === (int) $t[4] + 2, array( $a, $b, 'tags' => array( 3 => s_site_tags( 3, 'netz-fan' ), 4 => s_site_tags( 4, 'netz-fan' ) ), 'before' => $t ) );
		break;

	case 'ms_source_unlicensed':
		// The source site 2 changed to the basic license; a post was published there and its worker ran.
		$rep = s_reports();
		s_check( 'ms20', 'source site without the network right: the new post starts no network run, nothing is deleted', count( s_net_rows( 'runs' ) ) === (int) s_state( 'runs_before_source' ) && count( s_net_rows( 'workflows' ) ) >= 2 && empty( $rep[2]['licensed'] ), array( 'runs' => count( s_net_rows( 'runs' ) ), 'report2' => $rep[2] ?? null ) );
		$p = s_net()->service()->publish( $super, (int) ( (array) s_state( 'net_wf' ) )['ab'] );
		s_check( 'ms21', 'source site without the network right: publishing is refused (seats:site_unlicensed:2)', in_array( 'seats:site_unlicensed:2', $p, true ), $p );
		break;

	case 'ms_runs_before':
		s_state( $s_param, count( s_net_rows( 'runs' ) ) );
		break;

	case 'ms_expired_check':
		// The license of all four sites expired in the shop, every site refreshed; the target workers ran afterwards.
		$run    = (int) s_state( 'expire_run' );
		$before = (array) s_state( 'tags_expire_run' );
		$ch     = s_children( $run );
		$local  = s_local( $run );
		s_check( 'ms23', 'license expired on every site: the network view blocks the three child steps at once (site_license_inactive)', array( 3, 4, 5 ) === array_keys( $ch ) && array() === array_filter( $ch, static fn( $s ) => 'blocked_provider:site_license_inactive' !== $s ), $ch );
		s_check( 'ms23b', 'license expired: the target sites pause their queued runs instead of running them; no tag', array() === array_filter( $local, static fn( $s ) => ! str_starts_with( $s, 'paused' ) ) && s_site_tags( 4, 'netz-fan' ) === (int) $before[4], $local );
		break;

	case 'ms_expired_source':
		s_check( 'ms23c', 'license expired: a new post on the source site starts no network run', count( s_net_rows( 'runs' ) ) === (int) s_state( 'runs_before_expired_post' ), count( s_net_rows( 'runs' ) ) );
		break;

	case 'ms_renewed_check':
		$run = (int) s_state( 'expire_run' );
		$ch  = s_children( $run );
		s_check( 'ms24', 'renewed and refreshed on every site: the blocked child steps do not continue by themselves (R-17.9)', array() === array_filter( $ch, static fn( $s ) => ! str_starts_with( $s, 'blocked_provider' ) ) && array() === array_filter( s_local( $run ), static fn( $s ) => ! str_starts_with( $s, 'paused' ) ), array( 'children' => $ch, 'local' => s_local( $run ) ) );
		$n = s_net()->service()->resume_run( $super, $run );
		s_check( 'ms25', 'renewed: "resume run" in the Network Admin continues the three child steps', 3 === $n, $n );
		break;

	case 'ms_resumed_check':
		$ch = s_children( (int) s_state( 'expire_run' ) );
		s_check( 'ms26', 'after resume and the target workers: all three child steps succeeded', array( 3 => 'succeeded', 4 => 'succeeded', 5 => 'succeeded' ) === $ch, $ch );
		break;

	case 'ms_pause_net':
		foreach ( (array) s_state( 'net_wf' ) as $id ) {
			s_net()->service()->set_status( $super, (int) $id, 'paused' );
		}
		break;

	// ---- WF-MS-23: capability removal ------------------------------------------------------------------------------
	case 'ms_cap_setup':
		$u = (int) wpmu_create_user( 'netadmin2', 'netadmin-e2e-pass', 'netadmin2@example.test' );
		grant_super_admin( $u );
		s_net()->service()->grant( $super, 3, 'target', 'workflow.note.add' );
		list( $id, $p ) = s_net_publish( $u, 'Suite Rechte', s_net_def( 2, array( 3 ), 'netz-cap', array( 3 => array( array( 'id' => 'note', 'action' => 'workflow.note.add', 'config' => array( 'text' => 'Netz {{data.post_type}}' ) ) ) ) ) );
		s_state( 'cap', array( 'user' => $u, 'wf' => $id ) );
		s_check( 'ms33', 'a second super admin publishes "site 2 -> site 3" (note)', $id > 0 && array() === $p, array( $id, $p ) );
		break;

	case 'ms_cap_ran':
		$cap = (array) s_state( 'cap' );
		$run = s_parent( (int) $cap['wf'] );
		s_check( 'ms33b', 'while the publisher is super admin: the run succeeded on site 3', $run && array( 3 => 'succeeded' ) === s_children( (int) $run['id'] ), $run ? s_children( (int) $run['id'] ) : 'no run' );
		s_state( 'cap_runs', count( s_net_rows( 'runs', 'workflow_id = ' . (int) $cap['wf'] ) ) );
		revoke_super_admin( (int) $cap['user'] );
		break;

	case 'ms_cap_check':
		// Post on site 2 after the publisher lost the super admin right; source and target workers ran.
		$cap  = (array) s_state( 'cap' );
		$runs = s_net_rows( 'runs', 'workflow_id = ' . (int) $cap['wf'] );
		$last = end( $runs );
		$ch   = $last ? s_children( (int) $last['id'] ) : array();
		s_check( 'ms34', 'publisher lost the super admin right: the new child step is blocked (publisher_not_authorized), nothing queued on site 3, no other user takes its place', count( $runs ) === (int) s_state( 'cap_runs' ) + 1 && str_ends_with( (string) ( $ch[3] ?? '' ), 'publisher_not_authorized' ) && 'none' === ( s_local( (int) $last['id'] )[3] ?? '' ), array( 'children' => $ch, 'local' => $last ? s_local( (int) $last['id'] ) : null ) );
		$n = s_net()->service()->resume_run( $super, (int) $last['id'] );
		s_check( 'ms34b', '"resume run" does not continue it while the publisher has no right (a decision: publish again)', 0 === $n, $n );
		s_net()->service()->set_status( $super, (int) $cap['wf'], 'paused' );
		// The admin of site 3 loses his role there: he can no longer manage the participation of site 3.
		$sa = (int) s_state( 'site_admin' );
		remove_user_from_blog( $sa, 3 );
		$svc = s_net()->service();
		s_check( 'ms35', 'site admin removed from site 3: he can neither grant nor revoke for site 3 any more; the consent of site 3 stays (it belongs to the site)', ! $svc->grant( $sa, 3, 'target', 'workflow.tag.remove' ) && ! $svc->revoke( $sa, 3, 'target', 'notify.admin' ) && s_net()->policy()->target_allows( 3, 'notify.admin' ), 'ok' );
		break;

	// ---- WF-MS-23: provider plugin missing on the target site --------------------------------------------------------
	case 'ms_provider_setup':
		// Network contract of voucher.issue declared by the suite mu-plugin (no product declares one yet, WFC-PRO-17).
		$svc = s_net()->service();
		$c   = s_net()->policy()->contracts()->get( 'voucher.issue' );
		$g   = $svc->grant( $super, 5, 'target', 'voucher.issue' ) && $svc->grant( $super, 3, 'target', 'workflow.tag.set' );
		$def = s_net_def(
			2,
			array( 3, 5 ),
			'netz-prov',
			array(
				3 => array( array( 'id' => 'tag', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'netz-prov' ) ) ),
				5 => array( array( 'id' => 'voucher', 'action' => 'voucher.issue', 'config' => array( 'reason' => 'goodwill', 'recipient_field' => 'actor_user_id', 'amount_minor' => 500, 'currency' => 'EUR' ) ) ),
			)
		);
		list( $id, $p ) = s_net_publish( $super, 'Suite Anbieter fehlt', $def );
		s_state( 'prov_wf', $id );
		s_check( 'ms36', 'network workflow with the provider action voucher.issue on site 5 published (network contract present, consent given)', null !== $c && $g && array() === $p, array( 'contract' => null !== $c, 'grant' => $g, 'publish' => $p ) );
		break;

	case 'ms_provider_check':
		$run = s_parent( (int) s_state( 'prov_wf' ) );
		$ch  = $run ? s_children( (int) $run['id'] ) : array();
		s_state( 'prov_run', $run ? (int) $run['id'] : 0 );
		s_check( 'ms37', 'Voucher Captain (and its Pro add-on) missing on site 5: the action is not there, its step pauses (action_unavailable) and blocks in the network view, site 3 succeeds', 'succeeded' === ( $ch[3] ?? '' ) && 'blocked_provider:action_unavailable' === ( $ch[5] ?? '' ) && 'paused:action_unavailable' === ( s_local( (int) $run['id'] )[5] ?? '' ) && 1 === s_site_tags( 3, 'netz-prov' ), array( 'children' => $ch, 'local' => $run ? s_local( (int) $run['id'] ) : null ) );
		break;

	case 'ms_provider_resume':
		// Voucher Captain was activated on site 5 (without Voucher Captain Pro).
		$n = s_net()->service()->resume_run( $super, (int) s_state( 'prov_run' ) );
		s_check( 'ms38', 'Voucher Captain and Voucher Captain Pro with license active on site 5: "resume run" continues only the step of site 5', 1 === $n, $n );
		break;

	case 'ms_provider_after':
		$run = (int) s_state( 'prov_run' );
		$ch  = s_children( $run );
		$l   = s_local( $run );
		s_check( 'ms38b', 'resumed on site 5: the core refuses the voucher command across sites (action_not_network_safe: product commands are not network-safe yet), nothing issued, site 3 not repeated', 'succeeded' === ( $ch[3] ?? '' ) && str_contains( (string) ( $l[5] ?? '' ), 'action_not_network_safe' ) && 1 === s_site_tags( 3, 'netz-prov' ), array( 'children' => $ch, 'local' => $l ) );
		s_net()->service()->set_status( $super, (int) s_state( 'prov_wf' ), 'archived' );
		break;

	// ---- WF-MS-23: timeout of a target step after its side effect ------------------------------------------------------
	case 'ms_timeout_setup':
		list( $id, $p ) = s_net_publish( $super, 'Suite Zeitüberschreitung', s_net_def( 2, array( 3 ), 'netz-timeout', array( 3 => array( array( 'id' => 'mail', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Netz-Timeout {{subject.id}}', 'message' => 'Typ {{data.post_type}}' ) ) ) ) ) );
		s_state( 'timeout_wf', $id );
		s_check( 'ms39', 'network workflow "site 2 -> site 3, admin mail" published', $id > 0 && array() === $p, $p );
		break;

	case 'ms_timeout_check':
		// Site 3: the mail was handed over, then the call timed out (suite mu-plugin, option e2e_suite_mail_timeout).
		$run = s_parent( (int) s_state( 'timeout_wf' ) );
		$ch  = $run ? s_children( (int) $run['id'] ) : array();
		$l   = $run ? s_local( (int) $run['id'] ) : array();
		s_state( 'timeout_run', $run ? (int) $run['id'] : 0 );
		s_check( 'ms40', 'timeout after the mail went out on site 3: the step is not repeated, it needs a decision (local action_required unclear_outcome, network manual_review)', str_starts_with( (string) ( $ch[3] ?? '' ), 'manual_review' ) && str_contains( (string) ( $l[3] ?? '' ), 'unclear_outcome' ) && 1 === count( s_ms_mails( 'Netz-Timeout' ) ), array( 'children' => $ch, 'local' => $l, 'mails' => count( s_ms_mails( 'Netz-Timeout' ) ) ) );
		$n = s_net()->service()->resume_run( $super, (int) $run['id'] );
		s_state( 'timeout_resumed', $n );
		break;

	case 'ms_timeout_after':
		$run = (int) s_state( 'timeout_run' );
		s_check( 'ms40b', '"resume run" and the worker of site 3 do not send the mail a second time (started side effect stays a decision on the site)', 1 === count( s_ms_mails( 'Netz-Timeout' ) ) && str_contains( (string) ( s_local( $run )[3] ?? '' ), 'unclear_outcome' ), array( 'resumed' => s_state( 'timeout_resumed' ), 'children' => s_children( $run ), 'local' => s_local( $run ), 'mails' => count( s_ms_mails( 'Netz-Timeout' ) ) ) );
		s_net()->service()->set_status( $super, (int) s_state( 'timeout_wf' ), 'archived' );
		break;

	// ---- WF-MS-23: cron of a target site does not run (site_worker_late) -----------------------------------------------
	case 'ms_cron_setup':
		list( $id, $p ) = s_net_publish( $super, 'Suite Cron', s_net_def( 2, array( 4 ), 'netz-cron', array( 4 => array( array( 'id' => 'tag', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'netz-cron' ) ) ) ) ) );
		s_state( 'cron_wf', $id );
		s_check( 'ms41', 'network workflow "site 2 -> site 4, tag" published', $id > 0 && array() === $p, $p );
		break;

	case 'ms_cron_check':
		// Only the source worker ran; the worker of site 4 did not run for 20 minutes [time simulated].
		$run = s_parent( (int) s_state( 'cron_wf' ) );
		s_state( 'cron_run', (int) $run['id'] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}workflow_captain_pro_net_steps SET updated_at = %s WHERE run_id = %d", gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ), (int) $run['id'] ) ); // phpcs:ignore
		$flagged = s_net()->service()->flag_late_steps( time() );
		$health  = s_net()->service()->health( time() );
		$ch      = s_children( (int) $run['id'] );
		s_check( 'ms42', 'daily maintenance marks the waiting step of site 4 as late (site_worker_late), state stays "waiting for the site worker"', $flagged >= 1 && 'waiting_site_worker:site_worker_late' === ( $ch[4] ?? '' ), array( 'flagged' => $flagged, 'children' => $ch ) );
		s_check( 'ms42b', 'status page: site 4 "late", the other sites are not late, nothing moved to another site', 'late' === ( $health[4]['verdict'] ?? '' ) && 'late' !== ( $health[3]['verdict'] ?? '' ) && 0 === s_site_tags( 3, 'netz-cron' ), array( 4 => $health[4]['verdict'] ?? null, 3 => $health[3]['verdict'] ?? null ) );
		break;

	case 'ms_cron_after':
		$ch = s_children( (int) s_state( 'cron_run' ) );
		s_check( 'ms43', 'cron of site 4 runs again: the late step runs there and succeeds', 'succeeded' === ( $ch[4] ?? '' ) && 1 === s_site_tags( 4, 'netz-cron' ), $ch );
		break;

	case 'ms_dup':
		// Run on site 2 (--url): the source event of the cron run is delivered to the network a second time
		// (a repeated delivery of the consumer, or a replay of the same event ID).
		$ev   = s_site_events( 2, 'wordpress.post.published' );
		$last = end( $ev );
		$env  = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT envelope FROM {$wpdb->prefix}workflow_captain_events WHERE id = %d", (int) $last['id'] ) ), true );
		$wf   = (int) s_state( 'cron_wf' );
		$runs = count( s_net_rows( 'runs', 'workflow_id = ' . $wf ) );
		$out  = s_net()->router()->route( $env );
		$out2 = s_net()->router()->route( $env );
		s_check( 'ms43b', 'duplicate delivery of the same source event (twice): no second network run, no second child step, nothing more on site 4', count( s_net_rows( 'runs', 'workflow_id = ' . $wf ) ) === $runs && 1 === count( s_net_rows( 'steps', 'run_id = ' . (int) s_state( 'cron_run' ) ) ) && 1 === s_site_tags( 4, 'netz-cron' ), array( 'runs' => count( s_net_rows( 'runs', 'workflow_id = ' . $wf ) ), 'route' => array( $out, $out2 ) ) );
		s_net()->service()->set_status( $super, $wf, 'archived' );
		break;

	// ---- WF-MS-23: site deleted and restored ---------------------------------------------------------------------------
	case 'ms_life_setup':
		list( $id, $p ) = s_net_publish( $super, 'Suite Lebenszyklus', s_net_def( 2, array( 5 ), 'netz-life', array( 5 => array( array( 'id' => 'tag', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'netz-life' ) ) ) ) ) );
		s_state( 'life_wf', $id );
		s_check( 'ms44', 'network workflow "site 2 -> site 5, tag" published', $id > 0 && array() === $p, $p );
		break;

	case 'ms_life_flagged':
		// A post on site 2 (source worker ran), then site 5 was flagged as deleted in the Network Admin.
		$wf  = s_net()->store()->get_workflow( (int) s_state( 'life_wf' ) );
		$run = s_parent( (int) s_state( 'life_wf' ) );
		s_state( 'life_run', (int) $run['id'] );
		$ch = s_children( (int) $run['id'] );
		s_check( 'ms45', 'site 5 flagged as deleted: the workflow waits for a confirmation (needs_confirmation) and the open step of site 5 pauses (site_deleted)', 'needs_confirmation' === ( $wf['status'] ?? '' ) && 'paused_site_consent:site_deleted' === ( $ch[5] ?? '' ), array( 'status' => $wf['status'] ?? null, 'reason' => $wf['status_reason'] ?? null, 'children' => $ch ) );
		$c = s_net()->service()->confirm_targets( $super, (int) s_state( 'life_wf' ) );
		s_check( 'ms45b', 'while the site is flagged deleted the targets cannot be confirmed (target:5:site_deleted)', array( 'target:5:site_deleted' ) === $c, $c );
		break;

	case 'ms_life_restored':
		// Site 5 activated again (restore of the flag): nothing continues before the super admin confirms the targets.
		$run = (int) s_state( 'life_run' );
		$before = s_children( $run );
		$c   = s_net()->service()->confirm_targets( $super, (int) s_state( 'life_wf' ) );
		$wf  = s_net()->store()->get_workflow( (int) s_state( 'life_wf' ) );
		s_check( 'ms46', 'site restored: the step waited for the confirmation; "confirm target sites" makes the workflow active and continues the step', 'paused_site_consent:site_deleted' === ( $before[5] ?? '' ) && array() === $c && 'active' === ( $wf['status'] ?? '' ) && str_starts_with( (string) ( s_children( $run )[5] ?? '' ), 'waiting_site_worker' ), array( 'before' => $before, 'confirm' => $c, 'status' => $wf['status'] ?? null, 'after' => s_children( $run ) ) );
		break;

	case 'ms_life_done':
		$ch = s_children( (int) s_state( 'life_run' ) );
		s_check( 'ms46b', 'after the worker of the restored site 5: the step succeeded', 'succeeded' === ( $ch[5] ?? '' ) && 1 === s_site_tags( 5, 'netz-life' ), $ch );
		break;

	case 'ms_life_deleted':
		// Site 5 was deleted for good (tables dropped) after a dump; a post on site 2 started a run before.
		$wf  = s_net()->store()->get_workflow( (int) s_state( 'life_wf' ) );
		$run = s_parent( (int) s_state( 'life_wf' ) );
		s_state( 'life_run2', (int) $run['id'] );
		$c = s_net()->service()->confirm_targets( $super, (int) s_state( 'life_wf' ) );
		s_check( 'ms47', 'site 5 deleted: workflow needs confirmation, the open step pauses (site_deleted), confirming is refused (target:5:site_missing)', 'needs_confirmation' === ( $wf['status'] ?? '' ) && 'paused_site_consent:site_deleted' === ( s_children( (int) $run['id'] )[5] ?? '' ) && array( 'target:5:site_missing' ) === $c, array( 'status' => $wf['status'] ?? null, 'children' => s_children( (int) $run['id'] ), 'confirm' => $c ) );
		break;

	case 'ms_life_backup':
		// Site 5 restored from the dump (tables, wp_blogs row): same blog ID, registration date, domain and path.
		$c  = s_net()->service()->confirm_targets( $super, (int) s_state( 'life_wf' ) );
		$ch = s_children( (int) s_state( 'life_run2' ) );
		s_check( 'ms48', 'restored from a backup older than the run: the targets can be confirmed again; the step does not wait for a run the site no longer knows, it blocks for a decision (payload_expired: the data left the network when it was queued)', array() === $c && 'blocked_provider:payload_expired' === ( $ch[5] ?? '' ), array( 'confirm' => $c, 'children' => $ch ) );
		break;

	case 'ms_life_backup_done':
		// A new post on site 2 after the restore; source and site 5 worker ran.
		$run = s_parent( (int) s_state( 'life_wf' ) );
		$ch  = s_children( (int) $run['id'] );
		s_check( 'ms48b', 'after the restore (its own activation came back with the backup) a new run reaches site 5 and succeeds', (int) $run['id'] !== (int) s_state( 'life_run2' ) && 'succeeded' === ( $ch[5] ?? '' ) && 2 === s_site_tags( 5, 'netz-life' ), array( 'children' => $ch, 'report5' => s_reports()[5] ?? null ) );
		s_net()->service()->set_status( $super, (int) s_state( 'life_wf' ), 'archived' );
		break;

	// ---- WF-MS-23: blog switch and cache isolation (no persistent object cache) ----------------------------------------
	case 'ms_cache':
		$sites = s_net()->sites();
		$start = get_current_blog_id();
		$names = array();
		foreach ( array( 3, 4, 5 ) as $b ) {
			$names[ $b ] = $sites->on_site( $b, static fn() => get_option( 'blogname' ) . '|' . get_current_blog_id() );
		}
		$thrown = false;
		try {
			$sites->on_site( 4, static function () {
				throw new \RuntimeException( 'suite' );
			} );
		} catch ( \RuntimeException $e ) {
			$thrown = true;
		}
		$sites->on_site( 3, static fn() => set_transient( 'e2e_suite_iso', 'drei', 300 ) );
		$leak = $sites->on_site( 4, static fn() => get_transient( 'e2e_suite_iso' ) );
		s_check( 'ms49', 'blog switch: each site reads its own options, the current site comes back also after an exception, a transient of site 3 is not visible on site 4', array( 3 => 'ziel|3', 4 => 'drei|4', 5 => 'vier|5' ) === $names && $thrown && get_current_blog_id() === $start && false === $leak && false === get_transient( 'e2e_suite_iso' ), array( 'names' => $names, 'back' => get_current_blog_id(), 'leak' => $leak ) );
		$using = wp_using_ext_object_cache();
		s_check( 'ms49b', 'no persistent object cache on this test site (isolation across requests with Redis/Memcached not testable here)', ! $using, $using ? 'external cache' : 'internal cache only' );
		break;

	// ---- T103: providers per site in a multisite --------------------------------------------------------------------
	case 'ms_provider':
		// Run on the site given by --url. Param: expected adapters "voucher-captain" (or "-voucher-captain" for absent).
		$r = s_registered();
		foreach ( array_filter( explode( ',', $s_param ) ) as $want ) {
			$absent = str_starts_with( $want, '-' );
			$name   = ltrim( $want, '-' );
			s_check( 't103/' . get_current_blog_id() . '/' . $want, 'site ' . get_current_blog_id() . ': adapter ' . $name . ( $absent ? ' not registered' : ' registered' ), $absent !== in_array( $name, $r, true ), implode( ',', $r ) );
		}
		break;

	case 'ms_provider_event':
		$v = vcap()->issuer->issue_standalone( array( 'amount' => 9, 'source' => 'admin', 'recipient_email' => 'ms-gutschein@customer.test', 'send' => false ) );
		s_work( 3 );
		$here  = s_site_events( 2, 'voucher.%' );
		$there = s_site_events( 3, 'voucher.%' );
		s_check( 't103/iso', 'voucher event of site 2 is stored and processed only in the tables of site 2 (site isolation)', ! is_wp_error( $v ) && $here && 'done' === end( $here )['state'] && array() === $there && array() === s_site_events( 1, 'voucher.%' ), array( 'site2' => array_column( $here, 'state' ), 'site3' => count( $there ) ) );
		break;

	case 'ms_provider_paused':
		s_work( 2 );
		$here = s_site_events( 2, 'voucher.%' );
		s_check( 't103/off', 'Voucher Captain deactivated on site 2: its waiting event pauses there (adapter_unregistered)', $here && 'paused' === end( $here )['state'], end( $here ) );
		break;

	default:
		s_check( '?', 'unknown ms section ' . $s_section, false );
}
