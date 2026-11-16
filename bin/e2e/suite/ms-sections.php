<?php
/**
 * Multisite sections of bin/e2e/suite/checks.php (run with wpsuitems, see ms.sh): network-wide workflows of Workflow
 * Captain Pro with a real license (WF-MS-22, WF-MS-23) and the providers per site (T103). Not shipped.
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

/** Network workflow: source site -> targets, each with notify.admin and a tag. */
function s_net_def( int $source, array $targets, string $tag ): array {
	$t = array();
	foreach ( $targets as $blog ) {
		$t[] = array(
			'blog_id' => $blog,
			'steps'   => array(
				array( 'id' => 'mail', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Netz ' . $tag . ' {{subject.id}}', 'message' => 'Typ {{data.post_type}}' ) ),
				array( 'id' => 'tag', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => $tag ) ),
			),
		);
	}
	return array(
		'source'  => array( 'blog_id' => $source, 'event_type' => 'wordpress.post.published' ),
		'fields'  => array( 'post_type' ),
		'targets' => $t,
	);
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

$super = s_admin_id();

switch ( $s_section ) {

	case 'ms_license':
		$pro  = s_pro();
		$keys = (array) s_state( 'keys' );
		s_check( 'ms1', 'Pro network-activated keeps its license for the network (kernel option network)', $pro && true === (bool) $pro->license()->config( 'network' ), $pro ? var_export( $pro->license()->config( 'network' ), true ) : 'no addon' );
		$r = s_activate( $pro, (string) $keys[ $s_param ?: 'wcp_full' ] );
		s_check( 'ms2/' . ( $s_param ?: 'wcp_full' ), 'license activated once for the network (' . ( $s_param ?: 'wcp_full' ) . ')', 'ok' === $r, $r );
		break;

	case 'ms_license_sites':
		$pro = s_pro();
		$out = array();
		foreach ( array( 1, 2, 3, 4, 5 ) as $blog ) {
			switch_to_blog( $blog );
			$out[ $blog ] = $pro->features()->is_active( 'workflow.multisite_network' );
			restore_current_blog();
		}
		s_check( 'ms3', 'workflow.multisite_network active on every site of the network with one activation', array( 1 => true, 2 => true, 3 => true, 4 => true, 5 => true ) === $out, $out );
		$net = s_net();
		s_check( 'ms4', 'network tables created (schema current)', $net && in_array( $net->maybe_migrate(), array( 'current' ), true ) && $net->schema_current(), $net ? $net->maybe_migrate() : 'no feature' );
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
		// Source not released yet: the first workflow can be published (preview says so) but no run starts.
		$a = $svc->save_draft( $super, null, 'Suite A nach B', s_net_def( 2, array( 3 ), 'netz-ab' ) );
		$f = $svc->save_draft( $super, null, 'Suite Fan-out', s_net_def( 2, array( 3, 4, 5 ), 'netz-fan' ) );
		$pa = $svc->publish( $super, (int) $a['id'] );
		$pf = $svc->publish( $super, (int) $f['id'] );
		s_check( 'ms7', 'super admin publishes "site A -> site B" and a fan-out to three sites', array() === $pa && array() === $pf, array( $a, $f, $pa, $pf ) );
		s_state( 'net_wf', array( 'ab' => (int) $a['id'], 'fan' => (int) $f['id'] ) );
		$prev = $svc->preview( s_net_def( 2, array( 3, 4, 5 ), 'netz-fan' ) );
		s_check( 'ms8', 'preview: source not released yet, seats 4 without limit, no license problem', false === $prev['source']['consented'] && 4 === $prev['seats']['used'] && null === $prev['seats']['limit'] && null === $prev['license'], array( 'source' => $prev['source'], 'seats' => $prev['seats'], 'license' => $prev['license'] ) );
		break;

	case 'ms_source_check':
		// A post was published on site 2 and its worker ran; the source family is not released.
		$wf = (array) s_state( 'net_wf' );
		s_check( 'ms9', 'source not released: no network run starts (source_not_consented), nothing reaches the targets', array() === s_parent( (int) $wf['ab'] ) && 0 === s_site_tags( 3, 'netz-ab' ), s_net_rows( 'runs' ) );
		$ok = s_net()->service()->grant( $super, 2, 'source', 'wordpress.post', array( 'post_type' ) );
		s_check( 'ms10', 'site 2 releases the event family wordpress.post with the field post_type', $ok );
		break;

	case 'ms_run_check':
		// Second post on site 2: source worker ran, then the target workers of sites 3, 4 and 5.
		$wf  = (array) s_state( 'net_wf' );
		$ab  = s_parent( (int) $wf['ab'] );
		$fan = s_parent( (int) $wf['fan'] );
		s_check( 'ms11', 'site A -> site B: parent run succeeded, child step on site 3 succeeded, tag on site 3', $ab && 'succeeded' === $ab['state'] && array( 3 => 'succeeded' ) === s_children( (int) $ab['id'] ) && 1 === s_site_tags( 3, 'netz-ab' ), array( 'parent' => $ab['state'] ?? null, 'children' => $ab ? s_children( (int) $ab['id'] ) : null ) );
		s_check( 'ms12', 'fan-out: one child step per target (3, 4, 5), all succeeded, tag on each target', $fan && 'succeeded' === $fan['state'] && array( 3 => 'succeeded', 4 => 'succeeded', 5 => 'succeeded' ) === s_children( (int) $fan['id'] ) && 1 === s_site_tags( 4, 'netz-fan' ) && 1 === s_site_tags( 5, 'netz-fan' ), array( 'parent' => $fan['state'] ?? null, 'children' => $fan ? s_children( (int) $fan['id'] ) : null ) );
		s_check( 'ms13', 'nothing ran on the source site itself or on the main site (no tag there)', 0 === s_site_tags( 2, 'netz-fan' ) + s_site_tags( 1, 'netz-fan' ) );
		$mails = array_values( array_filter( array_map( static fn( $l ) => json_decode( $l, true ), (array) @file( '/home/user/wp-e2e/logs/mail-suite-ms.jsonl', FILE_IGNORE_NEW_LINES ) ), static fn( $m ) => is_array( $m ) && str_starts_with( (string) $m['subject'], 'Netz netz-fan' ) ) );
		s_check( 'ms14', 'fan-out: three admin mails, one per target site, with the released field', 3 === count( $mails ) && str_contains( (string) $mails[0]['message'], 'post' ), array_column( $mails, 'subject' ) );
		$data = wp_json_encode( s_net_rows( 'runs' ) ) . wp_json_encode( s_net_rows( 'steps' ) );
		s_check( 'ms15', 'no actor_user_id and no post title in the network tables (field allowlist, R-17.3)', ! str_contains( $data, 'actor_user_id' ) && ! str_contains( $data, 'Netz-Beitrag' ), strlen( $data ) . ' bytes' );
		s_net()->service()->revoke( $super, 5, 'target', 'workflow.tag.set' );
		break;

	case 'ms_consent_check':
		// Third post after site 5 revoked workflow.tag.set: sites 3 and 4 run, site 5 waits for its consent.
		$wf  = (array) s_state( 'net_wf' );
		$fan = s_parent( (int) $wf['fan'] );
		$ch  = s_children( (int) $fan['id'] );
		s_check( 'ms16', 'target without consent: its child step pauses (paused_site_consent), the others succeed (partial)', 'succeeded' === ( $ch[3] ?? '' ) && 'succeeded' === ( $ch[4] ?? '' ) && str_starts_with( (string) ( $ch[5] ?? '' ), 'paused_site_consent' ), array( 'parent' => $fan['state'], 'children' => $ch ) );
		s_state( 'consent_run', (int) $fan['id'] );
		s_net()->service()->grant( $super, 5, 'target', 'workflow.tag.set' );
		break;

	case 'ms_consent_resume':
		$ch = s_children( (int) s_state( 'consent_run' ) );
		s_check( 'ms17', 'new consent resumes the paused child step on site 5 (after its worker ran)', 'succeeded' === ( $ch[5] ?? '' ) && 2 === s_site_tags( 5, 'netz-fan' ), $ch );
		break;

	case 'ms_pause_net':
		foreach ( (array) s_state( 'net_wf' ) as $id ) {
			s_net()->service()->set_status( $super, (int) $id, 'paused' );
		}
		break;

	case 'ms_limit':
		// WF-MS-22: seats of the network (filter until the license server reports seats, WF-MS-25).
		add_filter( 'workflow_captain_pro_network_site_limit', static fn() => 3 );
		$net = s_net();
		$svc = $net->service();
		$d   = $svc->save_draft( $super, null, 'Suite zu viele', s_net_def( 2, array( 3, 4, 5, 1 ), 'netz-limit' ) );
		$p   = $svc->publish( $super, (int) $d['id'] );
		s_check( 'ms18', 'site limit 3 (filter workflow_captain_pro_network_site_limit): a fifth site cannot be published (seats:limit_exceeded)', in_array( 'seats:limit_exceeded', $p, true ), $p );
		break;

	case 'ms_switch_check':
		// License switched to the basic plan (without workflow.multisite_network), post on site 2, workers ran.
		$pro = s_pro();
		$wf  = (array) s_state( 'net_wf' );
		s_check( 'ms19', 'license without workflow.multisite_network: the feature is off on the network', ! $pro->features()->is_active( 'workflow.multisite_network' ) );
		$runs = count( s_net_rows( 'runs' ) );
		s_check( 'ms20', 'license without the right: the new post starts no network run (license_inactive), nothing is deleted', $runs === (int) s_state( 'runs_before_switch' ) && count( s_net_rows( 'workflows' ) ) >= 2, $runs . ' runs' );
		$net = s_net();
		$d   = $net->service()->publish( $super, (int) $wf['ab'] );
		s_check( 'ms21', 'license without the right: publishing is refused (license_inactive)', in_array( 'license_inactive', $d, true ), $d );
		break;

	case 'ms_runs_before':
		s_state( 'runs_before_switch', count( s_net_rows( 'runs' ) ) );
		break;

	case 'ms_expire_prep':
		// Post on site 2 and the source worker ran: child steps wait in the queues of sites 3, 4, 5.
		$wf  = (array) s_state( 'net_wf' );
		$fan = s_parent( (int) $wf['fan'] );
		s_state( 'expire_run', (int) $fan['id'] );
		s_state( 'tags_before_expiry', s_site_tags( 4, 'netz-fan' ) );
		s_check( 'ms22', 'before the expiry: the fan-out child steps wait for the target workers', array() === array_filter( s_children( (int) $fan['id'] ), static fn( $s ) => ! str_starts_with( $s, 'waiting_site_worker' ) ), s_children( (int) $fan['id'] ) );
		break;

	case 'ms_refresh':
		$pro = s_pro();
		s_refresh( $pro );
		s_check( 'ms-refresh/' . $s_param, 'network license refreshed from the shop (' . $s_param . ')', 'expired' === $s_param ? ! $pro->license()->status()->access : $pro->license()->status()->access, $pro->license()->status()->state );
		break;

	case 'ms_expired_check':
		$run   = (int) s_state( 'expire_run' );
		$local = s_net()->service()->inspect_run( $run );
		$states = array_map( static fn( $l ) => is_array( $l ) ? $l['state'] . ':' . $l['last_error'] : 'none', $local );
		s_check( 'ms23', 'license expired: the target sites pause the queued runs (foreign_unavailable) instead of running them; no tag', array() !== $states && array() === array_filter( $states, static fn( $x ) => ! str_starts_with( $x, 'paused:' ) ) && s_site_tags( 4, 'netz-fan' ) === (int) s_state( 'tags_before_expiry' ), $states );
		s_check( 'ms23b', 'license expired: the network view still shows the child steps as waiting (observer off without license)', array() === array_filter( s_children( $run ), static fn( $x ) => 'waiting_site_worker' !== $x ), s_children( $run ) );
		break;

	case 'ms_renewed_check':
		$run    = (int) s_state( 'expire_run' );
		$states = array_map( static fn( $l ) => is_array( $l ) ? $l['state'] . ':' . $l['last_error'] : 'none', s_net()->service()->inspect_run( $run ) );
		s_check( 'ms24', 'renewed: the paused runs on the targets do not continue by themselves (R-17.9)', array() === array_filter( $states, static fn( $x ) => ! str_starts_with( $x, 'paused:' ) ), $states );
		$n = s_net()->service()->resume_run( $super, $run );
		s_check( 'ms25', 'renewed: "resume run" by the super admin continues the three child steps', 3 === $n, $n );
		break;

	case 'ms_resumed_check':
		$ch = s_children( (int) s_state( 'expire_run' ) );
		s_check( 'ms26', 'after resume and the target workers: all three child steps succeeded', array( 3 => 'succeeded', 4 => 'succeeded', 5 => 'succeeded' ) === $ch, $ch );
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
		// Voucher Captain deactivated on site 2 only, an event was stored before.
		s_work( 2 );
		$here = s_site_events( 2, 'voucher.%' );
		s_check( 't103/off', 'Voucher Captain deactivated on site 2: its waiting event pauses there (adapter_unregistered)', $here && 'paused' === end( $here )['state'], end( $here ) );
		break;

	default:
		s_check( '?', 'unknown ms section ' . $s_section, false );
}
