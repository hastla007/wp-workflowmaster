<?php
/**
 * SET-WC-06 (decision S4): English source strings; de_DE is "du", de_DE_formal is "Sie", never mixed in one file.
 * Included by tests/run.php.
 *
 * @package WorkflowCaptain
 */

$wfc_lang = dirname( __DIR__ ) . '/languages/';
$wfc_msgs = static function ( string $file, string $field ): array {
	preg_match_all( '/^' . $field . '(?:\[\d\])? (".*")$/m', (string) file_get_contents( $file ), $m );
	return array_values( array_filter( array_map( static fn( $q ) => (string) json_decode( $q ), $m[1] ), static fn( $s ) => '' !== $s ) );
};
$wfc_src = $wfc_msgs( $wfc_lang . 'workflow-captain.pot', 'msgid' );
check( 'S4 source strings are English: no umlaut or ß in the template', array() === array_values( array_filter( $wfc_src, static fn( $s ) => 1 === preg_match( '/[äöüÄÖÜß]/u', $s ) ) ) );
$wfc_du  = $wfc_msgs( $wfc_lang . 'workflow-captain-de_DE.po', 'msgstr' );
$wfc_sie = $wfc_msgs( $wfc_lang . 'workflow-captain-de_DE_formal.po', 'msgstr' );
$wfc_formal_words = '/\b(?:\w+(?:en|rn) Sie|Ihnen|Ihr(?:e|en|em|er|es)?)\b|, Sie\b|\bbis Sie\b/u';
$wfc_du_words     = '/\b(?:du|dich|dir|dein(?:e|en|em|er|es)?)\b/ui';
check( 'S4 de_DE (du) has no Sie form', array() === array_values( array_filter( $wfc_du, static fn( $s ) => 1 === preg_match( $wfc_formal_words, $s ) ) ) );
check( 'S4 de_DE_formal (Sie) has no du form', array() === array_values( array_filter( $wfc_sie, static fn( $s ) => 1 === preg_match( $wfc_du_words, $s ) ) ) );
check( 'S4 both German files translate every string and are German', count( $wfc_du ) >= count( $wfc_src ) && count( $wfc_sie ) >= count( $wfc_src ) && in_array( 'Einstellungen', $wfc_du, true ) && in_array( 'Einstellungen', $wfc_sie, true ) );
check( 'S4 the du file really uses du where the formal one says Sie', in_array( 'Testnachricht übergeben. Kommt sie nicht an, prüf den Mailversand der Website.', $wfc_du, true ) && in_array( 'Testnachricht übergeben. Kommt sie nicht an, prüfen Sie den Mailversand der Website.', $wfc_sie, true ) );
check( 'S4 the plugin loads its translations from languages/ on init', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/workflow-captain.php' ), "load_plugin_textdomain( 'workflow-captain', false, dirname( plugin_basename( WORKFLOW_CAPTAIN_FILE ) ) . '/languages' )" ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/make-l10n.php' ) . ' --check 2>&1', $wfc_l10n_out, $wfc_l10n_rc );
check( 'S4 compiled .mo and .l10n.php match the .po files and cover the template', 0 === $wfc_l10n_rc );

// SET-WC-06 part 3: the shipped data files hold English, every text is in a gettext call (DataTexts) and so translated.
$wfc_known = \WorkflowCaptain\Admin\DataTexts::texts();
$wfc_unknown = array();
foreach ( glob( dirname( __DIR__ ) . '/assets/recipes/*.json' ) as $wfc_file ) {
	$wfc_r = json_decode( (string) file_get_contents( $wfc_file ), true );
	$wfc_t = array( $wfc_r['name'] );
	foreach ( $wfc_r['graph']['nodes'] as $wfc_n ) {
		foreach ( array( 'subject', 'message', 'text' ) as $wfc_f ) {
			if ( isset( $wfc_n['config'][ $wfc_f ] ) ) {
				$wfc_t[] = $wfc_n['config'][ $wfc_f ];
			}
		}
	}
	foreach ( $wfc_t as $wfc_s ) {
		if ( ! isset( $wfc_known[ $wfc_s ] ) || 1 === preg_match( '/[äöüÄÖÜß]/u', $wfc_s ) ) {
			$wfc_unknown[] = basename( $wfc_file ) . ': ' . $wfc_s;
		}
	}
}
$wfc_cm = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/assets/data/core-mails.json' ), true );
foreach ( $wfc_cm['events'] as $wfc_type => $wfc_mails ) {
	foreach ( $wfc_mails as $wfc_m ) {
		if ( ! isset( \WorkflowCaptain\Admin\DataTexts::recipients()[ $wfc_m['to'] ] ) ) {
			$wfc_unknown[] = $wfc_type . ' to: ' . $wfc_m['to'];
		}
		foreach ( array( 'off', 'note' ) as $wfc_f ) {
			if ( isset( $wfc_m[ $wfc_f ] ) && ! isset( $wfc_known[ $wfc_m[ $wfc_f ] ] ) ) {
				$wfc_unknown[] = $wfc_type . ' ' . $wfc_f . ': ' . $wfc_m[ $wfc_f ];
			}
		}
	}
}
check( 'S4 every text of the shipped recipes and the core-mail overview is English and translatable', array() === $wfc_unknown );
$wfc_mail = \WorkflowCaptain\Admin\DataTexts::core_mails( array( 'x' => array( array( 'to' => 'operator', 'mail' => 'm', 'off' => 'later via cron' ) ) ) )['x'][0];
check( 'S4 core-mail overview keeps the recipient key for the editor and adds its name', 'operator' === $wfc_mail['to'] && 'Site operator' === $wfc_mail['to_label'] && 'later via cron' === $wfc_mail['off'] );
$wfc_rec = \WorkflowCaptain\Admin\DataTexts::recipe( array( 'name' => 'Check-in: notice to the site operator', 'graph' => array( 'nodes' => array( array( 'config' => array( 'subject' => 'Own subject', 'to' => 'site_admin' ) ) ) ) ) );
check( 'S4 a recipe keeps unknown texts and other fields as they are', 'Check-in: notice to the site operator' === $wfc_rec['name'] && 'Own subject' === $wfc_rec['graph']['nodes'][0]['config']['subject'] && 'site_admin' === $wfc_rec['graph']['nodes'][0]['config']['to'] );
