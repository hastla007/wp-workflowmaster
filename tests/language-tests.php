<?php
/**
 * SET-WC-18 (language version per recipient for notify.user) and SET-WC-32 (placeholder modifiers that write dates,
 * numbers and amounts in the language of the message, with ext-intl and with the fallback without it).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Engine\Actions\NotifyUser;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\Formatter;
use WorkflowCaptain\Engine\Graph;
use WorkflowCaptain\Engine\Template;

echo "\n# SET-WC-18/-32 language per recipient and formats per language\n";

$wfc_env = array(
	'type'        => 'pass.order.paid',
	'occurred_at' => '2026-11-16T06:30:00Z',
	'subject'     => array( 'type' => 'order', 'id' => '42' ),
	'data'        => array( 'total_minor' => 123450, 'currency' => 'EUR', 'count' => 1234567, 'share' => 12.5, 'yen_minor' => 1500, 'price' => '19.99', 'label' => 'x' ),
);

// Raw output stays as before.
check( 'SET-WC-32 without a modifier a placeholder keeps its raw value (backward compatible)', 'Am 2026-11-16T06:30:00Z: 123450 EUR' === Template::render( 'Am {{occurred_at}}: {{data.total_minor}} {{data.currency}}', $wfc_env, 'de_DE', 'Europe/Berlin' ) );
check( 'SET-WC-32 an unknown modifier in an older published text stays as written', 'x {{data.count|shout}}' === Template::render( 'x {{data.count|shout}}', $wfc_env, 'de_DE', 'UTC' ) );

// Validation at publish.
check( 'SET-WC-32 known modifiers pass the check', array() === Template::validate( '{{occurred_at|date}} {{occurred_at|day}} {{data.count|number}} {{data.share|number:2}} {{data.total_minor|money}} {{data.total_minor|money:JPY}}', 'message', 5000 ) );
check( 'SET-WC-32 an unknown modifier is refused at publish', array( 'message:unknown_modifier' ) === Template::validate( 'Hallo {{data.count|shout}}', 'message', 5000 ) );
check( 'SET-WC-32 a bad argument is refused (currency not ISO, decimals over 6, date with argument)', array( 'subject:invalid_modifier' ) === Template::validate( '{{data.total_minor|money:eur}}', 'subject', 200 ) && array( 'subject:invalid_modifier' ) === Template::validate( '{{data.share|number:7}}', 'subject', 200 ) && array( 'subject:invalid_modifier' ) === Template::validate( '{{occurred_at|date:long}}', 'subject', 200 ) );
$wfc_g                          = sample_graph();
$wfc_g['nodes'][2]['config']['message'] = 'Am {{occurred_at|datum}}';
check( 'SET-WC-32 the graph check names the node and field of an unknown modifier', in_array( 'node:mail:message:unknown_modifier', Graph::violations( $wfc_g, engine_site()->actions ), true ) );

// Fallback without ext-intl (documented formats).
Formatter::$intl = false;
check( 'SET-WC-32 fallback: date as Y-m-d H:i in the given time zone, day as Y-m-d', '2026-11-16 07:30 / 2026-11-16' === Template::render( '{{occurred_at|date}} / {{occurred_at|day}}', $wfc_env, 'de_DE', 'Europe/Berlin' ) );
check( 'SET-WC-32 fallback: a Unix time is a date too', '2026-11-16 06:30' === Formatter::format( 'date', null, 1794810600, array(), 'en_US', 'UTC' ) );
check( 'SET-WC-32 fallback: numbers in German and English', '1.234.567 / 12,50' === Template::render( '{{data.count|number}} / {{data.share|number:2}}', $wfc_env, 'de_DE_formal', 'UTC' ) && '1,234,567 / 12.5' === Template::render( '{{data.count|number}} / {{data.share|number}}', $wfc_env, 'en_US', 'UTC' ) );
check( 'SET-WC-32 fallback: an amount in minor units with the currency of the event', '1.234,50 EUR' === Template::render( '{{data.total_minor|money}}', $wfc_env, 'de_DE', 'UTC' ) && 'EUR 1,234.50' === Template::render( '{{data.total_minor|money}}', $wfc_env, 'en_GB', 'UTC' ) );
check( 'SET-WC-32 fallback: the decimals come from ISO 4217 (JPY 0), a decimal string is in the major unit', '1.500 JPY' === Template::render( '{{data.yen_minor|money:JPY}}', $wfc_env, 'de_DE', 'UTC' ) && '19,99 EUR' === Template::render( '{{data.price|money}}', $wfc_env, 'de_DE', 'UTC' ) );
check( 'SET-WC-32 fallback: French writes a space between thousands', "1\u{00A0}234\u{00A0}567" === Template::render( '{{data.count|number}}', $wfc_env, 'fr_FR', 'UTC' ) );
check( 'SET-WC-32 a value that does not fit the modifier is written raw (no currency, not a number, not a date)', '123450' === Template::render( '{{data.total_minor|money}}', array( 'data' => array( 'total_minor' => 123450 ) ), 'de_DE', 'UTC' ) && 'x' === Template::render( '{{data.label|number}}', $wfc_env, 'de_DE', 'UTC' ) && 'x' === Template::render( '{{data.label|date}}', $wfc_env, 'de_DE', 'UTC' ) );
check( 'SET-WC-32 a missing field stays empty with a modifier', '' === Template::render( '{{data.nothing|money}}', $wfc_env, 'de_DE', 'UTC' ) );
Formatter::$intl = null;

// With ext-intl (ICU decides the exact spaces, so only the parts are compared).
if ( class_exists( NumberFormatter::class ) ) {
	$wfc_de = Template::render( '{{occurred_at|date}}|{{data.total_minor|money}}|{{data.count|number}}', $wfc_env, 'de_DE', 'Europe/Berlin' );
	check( 'SET-WC-32 intl: German date, amount and number', str_contains( $wfc_de, '16.11.2026' ) && str_contains( $wfc_de, '07:30' ) && str_contains( $wfc_de, '1.234,50' ) && str_contains( $wfc_de, '€' ) && str_contains( $wfc_de, '1.234.567' ) );
	$wfc_en = Template::render( '{{occurred_at|day}}|{{data.total_minor|money}}|{{data.yen_minor|money:JPY}}', $wfc_env, 'en_US', 'Europe/Berlin' );
	check( 'SET-WC-32 intl: English date and amounts with the ISO decimals', str_contains( $wfc_en, 'Nov 16, 2026' ) && str_contains( $wfc_en, '€1,234.50' ) && str_contains( $wfc_en, '1,500' ) && ! str_contains( $wfc_en, '1,500.00' ) );
} else {
	check( 'SET-WC-32 intl: not installed, fallback tested above', true );
}

// Language versions: validation.
$wfc_dir = new FakeDirectory();
$wfc_nu  = new NotifyUser( $wfc_dir );
$wfc_ok  = array( 'subject' => 'Order {{subject.id}}', 'message' => 'Paid {{data.total_minor|money}}' );
check( 'SET-WC-18 a configuration without versions is valid as before', array() === $wfc_nu->validate_config( $wfc_ok ) );
check( 'SET-WC-18 versions by locale and by bare language are valid', array() === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => 'Bestellung', 'message' => 'Bezahlt' ), 'fr' => array( 'subject' => 'Commande', 'message' => 'Payée' ) ) ) ) );
check( 'SET-WC-18 versions must be a map', array( 'versions:invalid' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => 'de_DE' ) ) && array( 'versions:invalid' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( array( 'subject' => 'a', 'message' => 'b' ) ) ) ) );
check( 'SET-WC-18 a bad locale key is refused', array( 'versions:invalid_locale' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'DE-de' => array( 'subject' => 'a', 'message' => 'b' ) ) ) ) );
check( 'SET-WC-18 a version needs subject and message, checked like the main text, and nothing else', array( 'versions.de_DE.message:required' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => 'a' ) ) ) ) && array( 'versions:invalid' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => 'a', 'message' => 'b', 'to' => 'x@example.com' ) ) ) ) && array( 'versions.de_DE.subject:too_long' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => str_repeat( 'a', 201 ), 'message' => 'b' ) ) ) ) );
check( 'SET-WC-18 an unknown modifier in a version is refused', array( 'versions.de_DE.message:unknown_modifier' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => 'a', 'message' => '{{data.count|x}}' ) ) ) ) );
$wfc_many = array();
foreach ( array( 'de', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'sv', 'da', 'cs', 'fi' ) as $wfc_l ) {
	$wfc_many[ $wfc_l ] = array( 'subject' => 'a', 'message' => 'b' );
}
check( 'SET-WC-18 at most 10 versions', array( 'versions:too_many' ) === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => $wfc_many ) ) && array() === $wfc_nu->validate_config( $wfc_ok + array( 'versions' => array_slice( $wfc_many, 0, 10 ) ) ) );

// Choosing the version.
$wfc_cfg = $wfc_ok + array( 'versions' => array( 'de_DE' => array( 'subject' => 'Bestellung {{subject.id}}', 'message' => 'Bezahlt: {{data.total_minor|money}} am {{occurred_at|day}}' ), 'de' => array( 'subject' => 'Bestellung (de)', 'message' => 'de' ), 'fr_FR' => array( 'subject' => 'Commande', 'message' => 'fr' ) ) );
check( 'SET-WC-18 exact locale first', 'de_DE' === NotifyUser::pick_version( $wfc_cfg, 'de_DE', 'en_US' )[2] && 'de_DE' === NotifyUser::pick_version( $wfc_cfg, 'de_DE_formal', 'en_US' )[2] );
check( 'SET-WC-18 then the bare language, then the first of the same language', 'de' === NotifyUser::pick_version( $wfc_cfg, 'de_AT', 'en_US' )[2] && 'fr_FR' === NotifyUser::pick_version( $wfc_cfg, 'fr_CA', 'en_US' )[2] );
check( 'SET-WC-18 otherwise the main text in the site language', array( 'Order {{subject.id}}', 'Paid {{data.total_minor|money}}', 'en_US' ) === NotifyUser::pick_version( $wfc_cfg, 'it_IT', 'en_US' ) && 'en_US' === NotifyUser::pick_version( $wfc_cfg, null, 'en_US' )[2] );

// Sending.
Formatter::$intl  = false;
$wfc_dir->locales = array( 17 => 'de_DE_formal' );
$wfc_ctx          = static fn( array $config, int $user = 17 ) => new ActionContext( $wfc_env + array( 'actor_user_id' => $user ), $config, 'k', 9, 1, 1, 1 );
$wfc_nu->execute( $wfc_ctx( $wfc_cfg ) );
$wfc_mail = end( $wfc_dir->sent );
check( 'SET-WC-18 a German recipient gets the German version with German formats', 'Bestellung 42' === $wfc_mail[1] && 'Bezahlt: 1.234,50 EUR am 2026-11-16' === $wfc_mail[2] );
$wfc_dir->locales = array( 17 => 'ja' );
$wfc_nu->execute( $wfc_ctx( $wfc_cfg ) );
$wfc_mail = end( $wfc_dir->sent );
check( 'SET-WC-18 without a matching version the main text goes out, formatted in the site language', 'Order 42' === $wfc_mail[1] && 'Paid EUR 1,234.50' === $wfc_mail[2] );
$wfc_dir->locales = array();
$wfc_nu->execute( $wfc_ctx( array_merge( $wfc_ok, array( 'message' => 'Raw {{data.total_minor}} {{occurred_at}}' ) ) ) );
check( 'SET-WC-18 a published version without versions and modifiers sends exactly the old text', 'Raw 123450 2026-11-16T06:30:00Z' === end( $wfc_dir->sent )[2] );
Formatter::$intl = null;

// End to end through publish and the engine.
$e             = engine_site();
$e->dir->locales = array( 17 => 'de_DE' );
$wfc_g         = sample_graph();
$wfc_g['nodes'][2] = array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.user', 'config' => array( 'subject' => 'Check-in', 'message' => 'Ticket {{subject.id}}', 'versions' => array( 'de' => array( 'subject' => 'Einlass', 'message' => 'Ticket {{subject.id}} eingecheckt' ) ) ) );
publish( $e, $wfc_g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'SET-WC-18 the engine sends the version of the recipient\'s language', 1 === count( $e->dir->sent ) && 'Einlass' === $e->dir->sent[0][1] && str_starts_with( $e->dir->sent[0][2], 'Ticket 8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d eingecheckt' ) );

// Editor and documentation.
$wfc_field = array_values( array_filter( $wfc_nu->builder_fields(), static fn( $f ) => 'versions' === $f['key'] ) )[0] ?? array();
check( 'SET-WC-18 the editor offers language versions with labels for every field', 'versions' === ( $wfc_field['type'] ?? '' ) && array( 'locale', 'subject', 'message', 'add', 'remove', 'version', 'invalid' ) === array_keys( $wfc_field['labels'] ?? array() ) );
check( 'SET-WC-18 the directory on WordPress reads the profile language', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Engine/WpDirectory.php' ), 'get_user_locale( $user_id )' ) );
$wfc_spec = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'docs/SPEC-suite-events.md' );
check( 'SET-WC-32 the specification names the rule and the legal review of price statements', str_contains( $wfc_spec, 'R-9.10' ) && str_contains( $wfc_spec, '|money' ) && str_contains( $wfc_spec, 'Rechtsprüfung' ) );
