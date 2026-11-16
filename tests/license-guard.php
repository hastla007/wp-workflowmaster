<?php
/**
 * Guard "no license code in the core" (docs/SPEC-suite-events.md, R-3.11, R-3.12, C-1; TODO WF-SPEC-12).
 *
 * Fails when a shipped file of the free core contains a license client, a call of the license server, a right
 * (entitlement) check, an own updater or the product key of an add-on. Also C-11 and C-12: no wp_set_current_user()
 * and no filter that suppresses or rewrites the mails of a core.
 *
 *   php tests/license-guard.php [plugin root]
 *
 * Exits 1 and lists file:line of every hit. The optional root is for the self-test in tests/run.php.
 *
 * @package WorkflowCaptain
 */

$root  = isset( $argv[1] ) && '' !== $argv[1] ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );
$paths = array( 'workflow-captain.php', 'uninstall.php', 'readme.txt', 'src', 'assets' );

$patterns = array(
	'/\bLicenseClient\b/'                                                     => 'license client',
	'#wplc/v1#'                                                               => 'WP License Captain API',
	'/wplc_/i'                                                                => 'WP License Captain prefix (wplc_)',
	'/^\s*\*?\s*Update URI\s*:/'                                              => '"Update URI" header',
	'/site_transient_update_plugins/'                                         => 'own update filter',
	'/[\'"]update_plugins_/'                                                  => 'own update filter (update_plugins_{hostname})',
	'/[\'"]plugins_api[\'"]/'                                                 => 'own plugins_api filter',
	'/entitlement/i'                                                          => 'right (entitlement) check',
	'/licen[sc]e_key/i'                                                       => 'license key handling',
	'/\b(voucher|pass|affiliate|form|review|course|workflow)-captain-pro\b/' => 'product key of an add-on',
	'/\b(suite_integrations|custom_connectors|multisite_network|event_correlation|advanced_logic|recipe_templates|extended_logs)\b/' => 'Pro right',
	'/\bwp_set_current_user\s*\(/'                                            => 'wp_set_current_user() (C-11, R-8.5)',
	'/[\'"](pre_wp_mail|wp_mail)[\'"]/'                                       => 'mail filter (C-12, R-9.2)',
);

// The single allowed occurrence: the list of field names the envelope validator rejects (it keeps license keys OUT).
$allowed = array(
	'src/Envelope/Validator.php' => '/const FORBIDDEN_DATA_NAME/',
);

$files = array();
foreach ( $paths as $path ) {
	$full = $root . '/' . $path;
	if ( is_file( $full ) ) {
		$files[] = $path;
	} elseif ( is_dir( $full ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
	}
}
sort( $files );

$hits = array();
foreach ( $files as $file ) {
	foreach ( file( $root . '/' . $file ) as $n => $line ) {
		if ( isset( $allowed[ $file ] ) && preg_match( $allowed[ $file ], $line ) ) {
			continue;
		}
		foreach ( $patterns as $pattern => $what ) {
			if ( preg_match( $pattern, $line ) ) {
				$hits[] = sprintf( '%s:%d: %s', $file, $n + 1, $what );
			}
		}
	}
}

if ( array() !== $hits ) {
	fwrite( STDERR, "License guard failed:\n" . implode( "\n", $hits ) . "\n" );
	exit( 1 );
}
echo 'License guard: ' . count( $files ) . " shipped files clean\n";
exit( 0 );
