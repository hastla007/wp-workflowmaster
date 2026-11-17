<?php
/**
 * Minimum platform of the Captain Suite (owner decision MINVER-1, 17.11.2026): PHP 8.5, WordPress 7.0, WooCommerce
 * 11.2 where used, MySQL 9.7 or MariaDB 12.3.
 *
 * PHP and WordPress are hard limits: below them workflow-captain.php loads nothing but an admin notice and refuses
 * activation. An older database server is not blocked; Site Health shows a recommendation.
 *
 * This file is loaded before the version check, on any PHP version a site may still run. It must therefore use only
 * syntax old PHP versions can parse (no enums, readonly, typed constants, match, union or mixed types) and must not
 * rely on the plugin's autoloader. Every check takes the versions as parameters, so the tests can inject them.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	/** Lowest supported PHP version. */
	const PHP = '8.5';

	/** Lowest supported WordPress version (WordPress 6.8 raises core deprecations under PHP 8.5). */
	const WORDPRESS = '7.0';

	/** Lowest WooCommerce version the built-in WooCommerce adapter registers with. */
	const WOOCOMMERCE = '11.2.0';

	/** Oldest database servers without a warning (LTS releases). */
	const MYSQL   = '9.7';
	const MARIADB = '12.3';

	/**
	 * Hard requirements that are not met.
	 *
	 * @param string $php       PHP version, e.g. PHP_VERSION.
	 * @param string $wordpress WordPress version, e.g. get_bloginfo( 'version' ).
	 * @return string[] "php" and/or "wordpress"; empty when the plugin may run.
	 */
	public static function unmet( $php, $wordpress ) {
		$unmet = array();
		if ( version_compare( self::release( (string) $php ), self::PHP, '<' ) ) {
			$unmet[] = 'php';
		}
		if ( version_compare( self::release( (string) $wordpress ), self::WORDPRESS, '<' ) ) {
			$unmet[] = 'wordpress';
		}
		return $unmet;
	}

	/**
	 * Release part of a version string: "7.0-RC1-61000" and "8.5.0-dev" count as 7.0 and 8.5.0, so a release
	 * candidate of the minimum version is accepted. Empty or unreadable strings count as 0.
	 */
	public static function release( $version ) {
		return 1 === preg_match( '/^\d+(\.\d+){0,2}/', trim( (string) $version ), $m ) ? $m[0] : '0';
	}

	/**
	 * Server type and version from a string such as "9.7.4", "12.3.1-MariaDB-ubu2404" or "5.5.5-10.11.6-MariaDB"
	 * (the replication prefix of older MariaDB servers is skipped).
	 *
	 * @return array{type:string,version:string}|null Null when no version can be read.
	 */
	public static function parse_db_server( $info ) {
		$info    = trim( (string) $info );
		$mariadb = false !== stripos( $info, 'mariadb' );
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}
		if ( 1 !== preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', $info, $m ) ) {
			return null;
		}
		return array(
			'type'    => $mariadb ? 'mariadb' : 'mysql',
			'version' => $m[1] . '.' . $m[2] . '.' . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : '0' ),
		);
	}

	/**
	 * Site Health status of the database server: never "critical" – an older server keeps working.
	 *
	 * @param string $info Server version string, e.g. $wpdb->db_server_info().
	 * @return array{status:string,code:string} "good"/"ok", "good"/"unknown" or "recommended"/"old_server".
	 */
	public static function database( $info ) {
		$server = self::parse_db_server( $info );
		if ( null === $server ) {
			return array( 'status' => 'good', 'code' => 'unknown' );
		}
		$minimum = 'mariadb' === $server['type'] ? self::MARIADB : self::MYSQL;
		return version_compare( $server['version'], $minimum, '<' )
			? array( 'status' => 'recommended', 'code' => 'old_server' )
			: array( 'status' => 'good', 'code' => 'ok' );
	}

	/** Admin text for unmet hard requirements. */
	public static function message( $php, $wordpress ) {
		return sprintf(
			/* translators: 1: minimum PHP version, 2: minimum WordPress version, 3: PHP version of this site, 4: WordPress version of this site */
			__( 'Workflow Captain braucht PHP %1$s und WordPress %2$s oder neuer; diese Website nutzt PHP %3$s und WordPress %4$s. Das Plugin bleibt inaktiv, gespeicherte Daten bleiben erhalten.', 'workflow-captain' ),
			self::PHP,
			self::WORDPRESS,
			(string) $php,
			(string) $wordpress
		);
	}

	/**
	 * Called by workflow-captain.php when unmet() is not empty: shows the notice in the (network) admin and refuses
	 * the activation. Nothing else of the plugin is loaded.
	 */
	public static function refuse( $plugin_file, $php, $wordpress ) {
		$text   = self::message( $php, $wordpress );
		$notice = static function () use ( $text ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
		};
		add_action( 'admin_notices', $notice );
		add_action( 'network_admin_notices', $notice );
		register_activation_hook(
			$plugin_file,
			static function () use ( $text ) {
				wp_die( esc_html( $text ), esc_html__( 'Workflow Captain', 'workflow-captain' ), array( 'back_link' => true ) );
			}
		);
	}
}
