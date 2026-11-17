<?php
/**
 * Builds languages/workflow-captain.pot from the gettext calls in the shipped PHP files (no WP-CLI needed).
 *
 *   php bin/make-pot.php           write the file
 *   php bin/make-pot.php --check   exit 1 if the file is missing strings or lists strings no longer used
 *
 * Reads __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e(), _x(), _n() with literal strings and the
 * text domain "workflow-captain", and "translators:" comments right before a call.
 *
 * @package WorkflowCaptain
 */

$root   = dirname( __DIR__ );
$domain = 'workflow-captain';
$funcs  = array( '__' => 's', '_e' => 's', 'esc_html__' => 's', 'esc_html_e' => 's', 'esc_attr__' => 's', 'esc_attr_e' => 's', '_x' => 'sc', '_n' => 'sp' );

$files = array( 'workflow-captain.php' );
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
	}
}
sort( $files );

$entries = array();
foreach ( $files as $rel ) {
	$tokens  = token_get_all( (string) file_get_contents( $root . '/' . $rel ) );
	$comment = null;
	$count   = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( preg_match( '/translators:\s*(.+?)\s*(\*\/)?$/s', $t[1], $m ) ) {
				$comment = trim( preg_replace( '/\s*\*\/$/', '', $m[1] ) );
			}
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funcs[ $t[1] ] ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
			continue;
		}
		$args  = array();
		$depth = 0;
		$cur   = array();
		for ( $k = $j + 1; $k < $count; $k++ ) {
			$tk = $tokens[ $k ];
			if ( '(' === $tk || '[' === $tk ) {
				$depth++;
			} elseif ( ')' === $tk || ']' === $tk ) {
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
				$depth--;
			} elseif ( ',' === $tk && 0 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			if ( ! ( is_array( $tk ) && in_array( $tk[0], array( T_WHITESPACE, T_COMMENT ), true ) ) ) {
				$cur[] = $tk;
			}
		}
		$literal = static function ( array $arg ): ?string {
			if ( 1 !== count( $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
				return null;
			}
			$raw = $arg[0][1];
			return "'" === $raw[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $raw, 1, -1 ) ) : stripcslashes( substr( $raw, 1, -1 ) );
		};
		$shape   = $funcs[ $t[1] ];
		$msgid   = $literal( $args[0] ?? array() );
		$context = 'sc' === $shape ? $literal( $args[1] ?? array() ) : null;
		$plural  = 'sp' === $shape ? $literal( $args[1] ?? array() ) : null;
		$dom     = $literal( $args[ 'sc' === $shape ? 2 : ( 'sp' === $shape ? 3 : 1 ) ] ?? array() );
		if ( null === $msgid || $domain !== $dom ) {
			$comment = null;
			continue;
		}
		$key = ( $context ?? '' ) . "\x04" . $msgid;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array( 'msgid' => $msgid, 'context' => $context, 'plural' => $plural, 'refs' => array(), 'comments' => array() );
		}
		$entries[ $key ]['refs'][] = $rel . ':' . $t[2];
		if ( null !== $comment ) {
			$entries[ $key ]['comments'][ $comment ] = true;
		}
		$comment = null;
	}
}
ksort( $entries );

$esc = static fn( string $s ): string => '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s ) . '"';
$out = "# Copyright (C) Captain Suite\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Workflow Captain\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: workflow-captain\\n\"\n";
foreach ( $entries as $e ) {
	$out .= "\n";
	foreach ( array_keys( $e['comments'] ) as $c ) {
		$out .= '#. translators: ' . $c . "\n";
	}
	$out .= '#: ' . implode( ' ', $e['refs'] ) . "\n";
	if ( null !== $e['context'] ) {
		$out .= 'msgctxt ' . $esc( $e['context'] ) . "\n";
	}
	$out .= 'msgid ' . $esc( $e['msgid'] ) . "\n";
	if ( null !== $e['plural'] ) {
		$out .= 'msgid_plural ' . $esc( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

$target = $root . '/languages/workflow-captain.pot';
if ( in_array( '--check', $argv, true ) ) {
	$ids = static function ( string $pot ): array {
		preg_match_all( '/^(?:msgctxt (".*")\n)?msgid (".*")$/m', $pot, $m, PREG_SET_ORDER );
		$list = array_map( static fn( $x ) => $x[1] . '|' . $x[2], $m );
		sort( $list );
		return $list;
	};
	$current = is_readable( $target ) ? (string) file_get_contents( $target ) : '';
	if ( $ids( $current ) !== $ids( $out ) ) {
		fwrite( STDERR, "languages/workflow-captain.pot is out of date: run php bin/make-pot.php\n" );
		exit( 1 );
	}
	echo 'POT up to date: ' . count( $entries ) . " strings\n";
	exit( 0 );
}
if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}
file_put_contents( $target, $out );
echo 'Wrote ' . count( $entries ) . " strings to languages/workflow-captain.pot\n";
