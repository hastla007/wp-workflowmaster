<?php
/**
 * SET-WC-07 (quiet time and sending days) and SET-WC-08 (daily limit per recipient): window arithmetic in the site's
 * time zone across DST changes, the engine putting a held message back in the queue, the atomic daily slot with two
 * connections, the settings form, export/import and privacy.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\Pacing;
use WorkflowCaptain\Engine\WpdbEngineStore;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

echo "\n# SET-WC-07/-08 sending window and daily limit\n";

$wfc_ts = static fn( string $local, string $tz ): int => ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp();
$wfc_lc = static fn( int $ts, string $tz ): string => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'D Y-m-d H:i' );
$b      = 'Europe/Berlin';

// Window arithmetic.
check( 'SET-WC-07 off by default: every hour and day is open', Pacing::is_open( $wfc_ts( '2026-11-22 03:00', $b ), $b, -1, -1, 127 ) );
check( 'SET-WC-07 the same hour for start and end means no quiet time', Pacing::is_open( $wfc_ts( '2026-11-23 22:30', $b ), $b, 22, 22, 127 ) );
check( 'SET-WC-07 quiet time over midnight: 22:00 to 07:00 holds 23:30 and 06:59, lets 07:00 and 21:59 through', ! Pacing::is_open( $wfc_ts( '2026-11-23 23:30', $b ), $b, 22, 7, 127 ) && ! Pacing::is_open( $wfc_ts( '2026-11-24 06:59', $b ), $b, 22, 7, 127 ) && Pacing::is_open( $wfc_ts( '2026-11-24 07:00', $b ), $b, 22, 7, 127 ) && Pacing::is_open( $wfc_ts( '2026-11-23 21:59', $b ), $b, 22, 7, 127 ) );
check( 'SET-WC-07 quiet time within a day: 12:00 to 14:00', ! Pacing::is_open( $wfc_ts( '2026-11-23 13:15', $b ), $b, 12, 14, 127 ) && Pacing::is_open( $wfc_ts( '2026-11-23 14:00', $b ), $b, 12, 14, 127 ) );
check( 'SET-WC-07 a message at 23:30 waits until 07:00 of the next day', 'Tue 2026-11-24 07:00' === $wfc_lc( Pacing::next_open( $wfc_ts( '2026-11-23 23:30', $b ), $b, 22, 7, 127 ), $b ) );
check( 'SET-WC-07 an open moment is returned unchanged', $wfc_ts( '2026-11-23 10:17', $b ) === Pacing::next_open( $wfc_ts( '2026-11-23 10:17', $b ), $b, 22, 7, 127 ) );
check( 'SET-WC-07 Monday to Friday: Saturday afternoon waits until Monday 00:00, with quiet time until Monday 07:00', 'Mon 2026-11-23 00:00' === $wfc_lc( Pacing::next_open( $wfc_ts( '2026-11-21 15:00', $b ), $b, -1, -1, 31 ), $b ) && 'Mon 2026-11-23 07:00' === $wfc_lc( Pacing::next_open( $wfc_ts( '2026-11-21 15:00', $b ), $b, 22, 7, 31 ), $b ) );
check( 'SET-WC-07 sending day is the local day: Friday 23:30 in Berlin is already Saturday in Tokyo', Pacing::is_open( $wfc_ts( '2026-11-20 23:30', $b ), $b, -1, -1, 31 ) && ! Pacing::is_open( $wfc_ts( '2026-11-20 23:30', $b ), 'Asia/Tokyo', -1, -1, 31 ) );
// DST: Berlin jumps from 02:00 to 03:00 on 29 March 2026 and back from 03:00 to 02:00 on 25 October 2026.
$wfc_spring = Pacing::next_open( $wfc_ts( '2026-03-29 01:30', $b ), $b, 1, 3, 127 );
check( 'SET-WC-07 DST spring: quiet 01:00 to 03:00 ends at 03:00 summer time, one real hour after 01:30', 'Sun 2026-03-29 03:00' === $wfc_lc( $wfc_spring, $b ) && 1800 === $wfc_spring - $wfc_ts( '2026-03-29 01:30', $b ) );
$wfc_autumn_from = $wfc_ts( '2026-10-25 01:30', $b );
$wfc_autumn      = Pacing::next_open( $wfc_autumn_from, $b, 1, 3, 127 );
check( 'SET-WC-07 DST autumn: quiet 01:00 to 03:00 lasts one real hour longer (02:00 twice): from 01:30 summer time 2.5 real hours until 03:00 winter time', 'Sun 2026-10-25 03:00' === $wfc_lc( $wfc_autumn, $b ) && 9000 === $wfc_autumn - $wfc_autumn_from );
check( 'SET-WC-07 time zones with half hours keep full local hours (Asia/Kolkata)', '07:00' === ( new DateTimeImmutable( '@' . Pacing::next_open( $wfc_ts( '2026-11-23 23:10', 'Asia/Kolkata' ), 'Asia/Kolkata', 22, 7, 127 ) ) )->setTimezone( new DateTimeZone( 'Asia/Kolkata' ) )->format( 'H:i' ) );
check( 'SET-WC-08 the calendar day is the site\'s day', '2026-11-24' === Pacing::day( $wfc_ts( '2026-11-24 00:30', $b ), $b ) && '2026-11-23' === Pacing::day( $wfc_ts( '2026-11-24 00:30', $b ), 'UTC' ) );

// Engine: a held message goes back to the queue and goes out when the window opens.
$wfc_pacing = array( 'quiet_hours_start' => 22, 'quiet_hours_end' => 7, 'send_days' => 127, 'daily_limit' => 0, 'daily_limit_action' => 0 );
$e          = engine_site( $b, null, 'wp_', 1, static function () use ( &$wfc_pacing ): array { return $wfc_pacing; } );
$e->clock->time = $wfc_ts( '2026-11-23 23:30', $b );
$g              = sample_graph();
$g['nodes'][2]  = array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.user', 'config' => array( 'subject' => 'Hallo', 'message' => 'Text' ) );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$wfc_mail = array_values( array_filter( steps_of( $e ), static fn( $s ) => 'mail' === $s['node_id'] ) )[0] ?? array();
check( 'SET-WC-07 in the quiet time the message is not sent; the step waits until 07:00 without a counted attempt', array() === $e->dir->sent && EngineStore::STEP_QUEUED === ( $wfc_mail['state'] ?? '' ) && 0 === (int) $wfc_mail['attempts'] && $wfc_ts( '2026-11-24 07:00', $b ) === strtotime( $wfc_mail['due_at'] . ' UTC' ) && EngineStore::RUN_RUNNING === run_of( $e )['state'] );
check( 'SET-WC-07 the step log says why and until when, and books no side effect', 'quiet_hours' === ( $wfc_mail['result']['deferred'] ?? '' ) && str_starts_with( (string) ( $wfc_mail['result']['until'] ?? '' ), '2026-11-24T06:00:00' ) && EngineStore::EFFECT_NONE === $wfc_mail['effect'] );
$e->clock->time = $wfc_ts( '2026-11-24 06:59', $b );
drain( $e );
check( 'SET-WC-07 nothing goes out before the window opens', array() === $e->dir->sent );
$e->clock->time = $wfc_ts( '2026-11-24 07:01', $b );
drain( $e );
check( 'SET-WC-07 at the start of the window the message goes out once and the run ends', 1 === count( $e->dir->sent ) && EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );
check( 'SET-WC-07 messages to the operator are never held back', ! str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Engine/Actions/NotifyAdmin.php' ), 'Pacing' ) );

// Daily limit: one message per day, the second run waits for the next day.
$wfc_pacing = array( 'quiet_hours_start' => -1, 'quiet_hours_end' => -1, 'send_days' => 127, 'daily_limit' => 1, 'daily_limit_action' => Pacing::LIMIT_DEFER );
$e          = engine_site( $b, null, 'wp_', 1, static function () use ( &$wfc_pacing ): array { return $wfc_pacing; } );
$e->clock->time = $wfc_ts( '2026-11-23 10:00', $b );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-1' ) );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-2' ) );
drain( $e );
$wfc_runs = $e->store->find_runs( array(), 10, 0 );
check( 'SET-WC-08 two runs for the same customer on one day: one message goes out, the other waits', 1 === count( $e->dir->sent ) && 2 === count( $wfc_runs ) && 1 === count( array_filter( $wfc_runs, static fn( $r ) => EngineStore::RUN_RUNNING === $r['state'] ) ) );
$e->clock->time = $wfc_ts( '2026-11-23 23:59', $b );
drain( $e );
check( 'SET-WC-08 the waiting message does not go out on the same calendar day', 1 === count( $e->dir->sent ) );
$e->clock->time = $wfc_ts( '2026-11-24 00:01', $b );
drain( $e );
check( 'SET-WC-08 on the next calendar day of the site the waiting message goes out', 2 === count( $e->dir->sent ) );
// Skip instead of wait.
$wfc_pacing['daily_limit_action'] = Pacing::LIMIT_SKIP;
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-3' ) );
drain( $e );
$wfc_skip = array_values( array_filter( $e->store->steps_for_run( $e->store->find_runs( array(), 1, 0 )[0]['id'] ), static fn( $s ) => 'mail' === $s['node_id'] ) )[0] ?? array();
check( 'SET-WC-08 "skip": the surplus message is dropped and the run notes it', 2 === count( $e->dir->sent ) && 'daily_limit' === ( $wfc_skip['result']['skipped'] ?? '' ) && EngineStore::RUN_SUCCEEDED === $e->store->find_runs( array(), 1, 0 )[0]['state'] );
// A failed mail gives the slot back.
$wfc_pacing['daily_limit'] = 1;
$e->clock->time            = $wfc_ts( '2026-11-25 10:00', $b );
$e->dir->fail_mails        = 1;
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-4' ) );
drain( $e );
$e->clock->advance( 5 * MINUTE_IN_SECONDS );
drain( $e );
check( 'SET-WC-08 a failed mail gives its slot back: the retry on the same day still goes out', 3 === count( $e->dir->sent ) );

// Atomic slot with two connections to the same database (two workers).
$wfc_file = tempnam( sys_get_temp_dir(), 'wfc' );
$wfc_pdo1 = new PDO( 'sqlite:' . $wfc_file );
$wfc_pdo1->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
( new FakeWpdb( $wfc_pdo1 ) )->create_engine_tables();
$wfc_pdo2 = new PDO( 'sqlite:' . $wfc_file );
$wfc_pdo2->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$wfc_s1   = new WpdbEngineStore( new FakeWpdb( $wfc_pdo1 ) );
$wfc_s2   = new WpdbEngineStore( new FakeWpdb( $wfc_pdo2 ) );
$wfc_take = array( $wfc_s1->take_send_slot( '2026-11-23', 17, 2 ), $wfc_s2->take_send_slot( '2026-11-23', 17, 2 ), $wfc_s1->take_send_slot( '2026-11-23', 17, 2 ), $wfc_s2->take_send_slot( '2026-11-23', 17, 2 ) );
check( 'SET-WC-08 two workers on two connections share the limit: exactly two of four attempts get a slot', array( true, true, false, false ) === $wfc_take );
check( 'SET-WC-08 the slot is taken in one conditional UPDATE (no read-then-write race)', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Engine/WpdbEngineStore.php' ), 'SET sent = sent + 1 WHERE day = %s AND user_id = %d AND sent < %d' ) );
$wfc_s1->release_send_slot( '2026-11-23', 17 );
check( 'SET-WC-08 a released slot can be taken again, by the other worker', $wfc_s2->take_send_slot( '2026-11-23', 17, 2 ) && ! $wfc_s1->take_send_slot( '2026-11-23', 17, 2 ) );
check( 'SET-WC-08 another customer and another day have their own count', $wfc_s1->take_send_slot( '2026-11-23', 18, 2 ) && $wfc_s1->take_send_slot( '2026-11-24', 17, 2 ) );
$wfc_s1->take_send_slot( '2026-11-20', 17, 2 );
check( 'SET-WC-08 old counters are purged, and the eraser removes a user\'s counters', 1 === $wfc_s1->purge_send_counts( '2026-11-21' ) && 2 === $wfc_s1->erase_send_counts( 17 ) && 1 === (int) $wfc_pdo1->query( "SELECT COUNT(*) FROM wp_workflow_captain_sends" )->fetchColumn() );
unset( $wfc_s1, $wfc_s2, $wfc_pdo1, $wfc_pdo2 );
@unlink( $wfc_file );
check( 'SET-WC-08 the counter table is created by the schema and removed with the other tables', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Install.php' ), "CREATE TABLE {\$t( 'sends' )}" ) && in_array( 'sends', WpdbEngineStore::TABLES, true ) && str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'workflow-captain.php' ), "define( 'WORKFLOW_CAPTAIN_DB_VERSION', '5' )" ) );
check( 'SET-WC-08 privacy: the eraser and a deleted user clear the counters, the daily maintenance purges old days', 3 === substr_count( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Privacy/Privacy.php' ), '_send_counts(' ) );

// Settings: register, form, export/import.
check( 'SET-WC-07 defaults: no quiet time, every day, no daily limit (nothing changes for existing sites)', -1 === Settings::defaults()['quiet_hours_start'] && -1 === Settings::defaults()['quiet_hours_end'] && 127 === Settings::defaults()['send_days'] && 0 === Settings::defaults()['daily_limit'] );
check( 'SET-WC-07 the form sends weekdays as a list; it is stored as a mask', 31 === Settings::sanitize( array( 'send_days' => array( '', '1', '2', '3', '4', '5' ) ) )['send_days'] && 96 === Settings::sanitize( array( 'send_days' => array( '6', '7' ) ) )['send_days'] );
check( 'SET-WC-07 no day chosen is a problem at the field, never a silent "every day"', isset( Settings::problems( array( 'send_days' => array( '' ) ) )['send_days'] ) && array() === Settings::problems( array( 'send_days' => array( '', '1' ) ) ) );
check( 'SET-WC-07 the quiet time is a list of full hours with "Off" first', 'Off' === SettingsRegistry::choices( 'quiet_hours_start' )[-1] && '22:00' === SettingsRegistry::choices( 'quiet_hours_end' )[22] && 25 === count( SettingsRegistry::choices( 'quiet_hours_start' ) ) );
check( 'SET-WC-08 "when the limit is reached" is a choice, not a switch', 2 === count( SettingsRegistry::choices( 'daily_limit_action' ) ) );
check( 'SET-WC-07 import: a mask or a weekday list is accepted, an unknown day is an error', array( true, 31, '' ) === SettingsTransfer::check( 'send_days', 31 ) && array( true, 65, '' ) === SettingsTransfer::check( 'send_days', array( 1, 7 ) ) && array( false, null, 'unknown_day' ) === SettingsTransfer::check( 'send_days', array( 8 ) ) && array( false, null, 'out_of_range' ) === SettingsTransfer::check( 'send_days', 0 ) );
check( 'SET-WC-07 import: quiet time and limit are checked against their range', array( false, null, 'out_of_range' ) === SettingsTransfer::check( 'quiet_hours_start', 24 ) && array( true, -1, '' ) === SettingsTransfer::check( 'quiet_hours_end', '-1' ) && array( false, null, 'out_of_range' ) === SettingsTransfer::check( 'daily_limit', 21 ) );
check( 'SET-WC-07 the log and the import preview show days and hours by name', 'Monday, Friday' === \WorkflowCaptain\Admin\SettingsPage::display_value( 'send_days', 17 ) && '07:00' === \WorkflowCaptain\Admin\SettingsPage::display_value( 'quiet_hours_end', 7 ) && 'Off' === \WorkflowCaptain\Admin\SettingsPage::display_value( 'quiet_hours_start', -1 ) );
check( 'SET-WC-07 the values are exported (site policy, no personal data)', array( 'quiet_hours_start', 'quiet_hours_end', 'send_days', 'daily_limit', 'daily_limit_action' ) === array_values( array_intersect( array_keys( SettingsTransfer::export( SettingsTransfer::defaults(), '0' )['settings'] ), array( 'quiet_hours_start', 'quiet_hours_end', 'send_days', 'daily_limit', 'daily_limit_action' ) ) ) );
check( 'SET-WC-07 a filter can set the days as a list too', 3 === SettingsRegistry::clean( 'send_days', array( 1, 2 ) ) );
