<?php
/**
 * Run the HTML optimizer over saved pages and check that nothing is lost.
 *
 * Usage (inside a WordPress install with the plugin active):
 *   wp eval-file tests/optimize-fixtures.php <fixtures-dir> <output-dir> [all|live|nodelay|all-<feature>,<feature>]
 *
 * "all" enables every feature (Live and Test); "live" only the features that
 * are Live by default; "nodelay" everything except "Start all non-essential
 * JavaScript on the first interaction"; "all-<feature>,..." everything except
 * the listed features (ids as in Settings::features()). For each page it prints timing and
 * counters, and checks:
 * visible text unchanged, balanced script tags, no leftover removal markers,
 * no element still hidden by an entrance animation, every facade placed (only
 * YouTube iframes may disappear, each replaced by a facade), every table of
 * contents item filled in names a heading of the page, and that the result
 * still parses as a full HTML document.
 *
 * @package SHD\Performance
 */

use SHD\Performance\Html_Optimizer;
use SHD\Performance\Settings;

if ( ! class_exists( Html_Optimizer::class ) ) {
	fwrite( STDERR, "Activate the shd-performance plugin first.\n" );
	exit( 1 );
}

$in   = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
$out  = isset( $args[1] ) ? rtrim( $args[1], '/' ) : '';
$mode = isset( $args[2] ) ? $args[2] : 'all';
if ( ! is_dir( $in ) || '' === $out ) {
	fwrite( STDERR, "Usage: wp eval-file tests/optimize-fixtures.php <fixtures-dir> <output-dir> [all|live|nodelay|all-<feature>,<feature>]\n" );
	exit( 1 );
}
wp_mkdir_p( $out );

$features = array();
$without  = 0 === strpos( $mode, 'all-' ) ? explode( ',', substr( $mode, 4 ) ) : array();
foreach ( Settings::features() as $id => $feature ) {
	if ( 'all' === $mode ) {
		$features[ $id ] = true;
	} elseif ( 'nodelay' === $mode ) {
		$features[ $id ] = 'delay_all_scripts' !== $id;
	} elseif ( $without ) {
		$features[ $id ] = ! in_array( $id, $without, true );
	} else {
		$features[ $id ] = 'live' === $feature['default'];
	}
}

/**
 * Visible text of a document (scripts, styles and tags removed, whitespace collapsed).
 *
 * @param string $html HTML.
 * @return string
 */
function shd_test_visible_text( $html ) {
	$html = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
	$html = preg_replace( '#<!--.*?-->#s', ' ', $html );
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5 );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Tables of contents filled in by the plugin (top-level lists, nested lists included).
 *
 * @param string $html HTML.
 * @return string[]
 */
function shd_test_toc_lists( $html ) {
	$lists  = array();
	$offset = 0;
	while ( preg_match( '#<(ol|ul) class="elementor-toc__list-wrapper">#', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$tag   = $m[1][0];
		$start = $m[0][1];
		$depth = 0;
		$pos   = $start;
		while ( preg_match( '#<(/?)' . $tag . '\b#', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += '' === $t[1][0] ? 1 : -1;
			$pos    = $t[0][1] + 1;
			if ( 0 === $depth ) {
				break;
			}
		}
		$end     = strpos( $html, '>', $pos ) + 1;
		$lists[] = substr( $html, $start, $end - $start );
		$offset  = $end;
	}
	return $lists;
}

/**
 * Texts of the h1-h6 headings in a document.
 *
 * @param string $html HTML.
 * @return string[]
 */
function shd_test_headings( $html ) {
	preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m );
	return array_map(
		static function ( $inner ) {
			return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5 ) ) );
		},
		$m[2]
	);
}

/**
 * Whether the full HTML parser can walk the whole document.
 *
 * Anything after </html> (cache plugins append comments there) is ignored,
 * because the parser does not support content outside the document.
 *
 * @param string $html HTML.
 * @return string '' when fine, otherwise the parser error.
 */
function shd_test_parse_error( $html ) {
	if ( ! method_exists( 'WP_HTML_Processor', 'create_full_parser' ) ) {
		return '';
	}
	$end = strripos( $html, '</html>' );
	if ( false !== $end ) {
		$html = substr( $html, 0, $end + 7 );
	}
	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		return 'could not create parser';
	}
	while ( $processor->next_token() ) {
		// Walk every token.
	}
	$error = $processor->get_last_error();
	return null === $error ? '' : (string) $error;
}

// SHD_TEST_SETTINGS='{"toc_phones":"collapsed"}' overrides settings for this run.
$settings = Settings::get();
$override = json_decode( (string) getenv( 'SHD_TEST_SETTINGS' ), true );
if ( is_array( $override ) ) {
	$settings = array_merge( $settings, $override );
}

$failures = 0;
$files    = glob( $in . '/*.html' );
sort( $files );

foreach ( $files as $file ) {
	$name = basename( $file );
	$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	$start     = microtime( true );
	$optimizer = new Html_Optimizer( $features, $settings, true );
	$result    = $optimizer->optimize( $html );
	$elapsed   = ( microtime( true ) - $start ) * 1000;
	file_put_contents( $out . '/' . $name, $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	$problems = array();
	// Filled-in tables of contents add links and text; each item must name a heading.
	$compared = $result;
	$headings = shd_test_headings( $html );
	foreach ( shd_test_toc_lists( $result ) as $list ) {
		preg_match_all( '#<a href="\#[^"]+" class="elementor-toc__list-item-text[^"]*">(.*?)</a>#s', $list, $items );
		foreach ( $items[1] as $item ) {
			$item = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $item, ENT_QUOTES | ENT_HTML5 ) ) );
			if ( ! in_array( $item, $headings, true ) ) {
				$problems[] = 'table of contents item is not a heading: ' . $item;
			}
		}
		$compared = str_replace( $list, '', $compared );
	}
	if ( shd_test_visible_text( $html ) !== shd_test_visible_text( $compared ) ) {
		$problems[] = 'visible text changed';
	}
	if ( substr_count( strtolower( $result ), '<script' ) !== substr_count( strtolower( $result ), '</script>' ) ) {
		$problems[] = 'unbalanced <script> tags';
	}
	if ( false !== strpos( $result, Html_Optimizer::REMOVE_MARK ) ) {
		$problems[] = 'removal marker left behind';
	}
	if ( ! empty( $features['animations'] ) && 'all' === $settings['animation_mode'] && false !== strpos( $result, 'elementor-invisible' ) ) {
		$problems[] = 'elementor-invisible left';
	}
	// Element counts outside scripts (script text may legitimately be re-encoded).
	$markup_before = strtolower( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html ) );
	$markup_after  = strtolower( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $compared ) );

	// Facades: one per Elementor video slot, plus one per YouTube iframe that was replaced.
	$facades        = substr_count( $result, 'class="shd-yt"' );
	$youtube_frames = static function ( $markup ) {
		return preg_match_all( '#<iframe\b[^>]*\bsrc="[^"]*youtube(?:-nocookie)?\.com/embed/#i', $markup );
	};
	$iframe_facades = $youtube_frames( $markup_before ) - $youtube_frames( $markup_after );
	if ( substr_count( $result, 'data-shd-yt-slot=' ) + $iframe_facades !== $facades ) {
		$problems[] = 'video without facade';
	}
	if ( false !== strpos( $result, 'data-shd-yt-iframe=' ) ) {
		$problems[] = 'YouTube iframe marker left behind';
	}
	foreach ( array( '<img', '<a ', '<form', '<iframe', '<video', '<svg', '<h1', '<h2' ) as $tag ) {
		$expected = substr_count( $markup_before, $tag );
		if ( '<img' === $tag || '<svg' === $tag ) {
			$expected += $facades;
			if ( '<svg' === $tag ) {
				$expected += substr_count( $markup_after, 'data-shd-toc-toggle' ); // Arrow icon of each table of contents button.
			}
		} elseif ( '<iframe' === $tag ) {
			$expected -= $iframe_facades;
		}
		if ( substr_count( $markup_after, $tag ) !== $expected ) {
			$problems[] = "count of {$tag} changed";
		}
	}
	$parse = shd_test_parse_error( $result );
	if ( '' !== $parse && shd_test_parse_error( $html ) !== $parse ) {
		$problems[] = 'parser: ' . $parse;
	}

	$stats = $optimizer->stats();
	ksort( $stats );
	$summary = array();
	foreach ( $stats as $key => $value ) {
		$summary[] = "{$key}={$value}";
	}

	printf(
		"%-45s %6.1f ms  %s  %s\n",
		$name,
		$elapsed,
		empty( $problems ) ? 'OK  ' : 'FAIL',
		empty( $problems ) ? implode( ' ', $summary ) : implode( '; ', $problems )
	);
	if ( ! empty( $problems ) ) {
		++$failures;
	}
}

printf( "\n%d page(s), %d failure(s)\n", count( $files ), $failures );
exit( $failures > 0 ? 1 : 0 );
