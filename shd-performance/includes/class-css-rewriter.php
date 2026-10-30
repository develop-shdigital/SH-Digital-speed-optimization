<?php
/**
 * Rewrites CSS for the font optimizations and caches rewritten copies of local
 * stylesheets (Elementor's generated post-*.css files carry the @font-face rules
 * and the font-family declarations).
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Css_Rewriter {

	const CACHE_SUBDIR = 'shd-performance/css';

	/** @var string[] */
	private $families;

	/** @var array<string,bool> lower-case family => has fallback */
	private $fallbacks;

	/**
	 * @param string[] $families  Custom font families.
	 * @param string[] $fallbacks Families (lower-case) that have a size-matched fallback.
	 */
	public function __construct( array $families, array $fallbacks ) {
		$this->families  = $families;
		$this->fallbacks = array_fill_keys( $fallbacks, true );
	}

	/**
	 * Whether this CSS contains anything we would change.
	 *
	 * @param string $css CSS.
	 * @return bool
	 */
	public function wants( $css ) {
		if ( false !== stripos( $css, '@font-face' ) ) {
			return true;
		}
		foreach ( $this->families as $family ) {
			if ( isset( $this->fallbacks[ strtolower( $family ) ] ) && false !== stripos( $css, $family ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrite CSS text.
	 *
	 * @param string $css      CSS.
	 * @param string $base_url URL of the original file, to absolutize relative url()s when the CSS moves.
	 * @return string
	 */
	public function rewrite( $css, $base_url = '' ) {
		$faces = array();
		$css   = preg_replace_callback(
			'/@font-face\s*\{[^}]*\}/i',
			function ( $match ) use ( &$faces ) {
				$faces[] = $this->rewrite_font_face( $match[0] );
				return "\x00SHDFACE" . ( count( $faces ) - 1 ) . "\x00";
			},
			$css
		);

		$css = $this->add_fallbacks( $css );

		$css = preg_replace_callback(
			"/\x00SHDFACE(\d+)\x00/",
			static function ( $match ) use ( $faces ) {
				return $faces[ (int) $match[1] ];
			},
			$css
		);

		if ( '' !== $base_url ) {
			$css = self::absolutize_urls( $css, $base_url );
		}
		return $css;
	}

	/**
	 * font-display: swap, and a WOFF2 source first when one has been uploaded.
	 *
	 * @param string $rule One @font-face rule.
	 * @return string
	 */
	private function rewrite_font_face( $rule ) {
		$original = $rule;
		if ( preg_match( '/font-display\s*:\s*[a-z-]+/i', $rule ) ) {
			$rule = preg_replace( '/font-display\s*:\s*(auto|block)\b/i', 'font-display:swap', $rule );
		} else {
			$rule = preg_replace( '/\{/', '{font-display:swap;', $rule, 1 );
		}

		if ( false === stripos( $rule, 'woff2' ) && preg_match_all( '/(?<![\w-])src\s*:\s*([^;}]+)/i', $rule, $declarations, PREG_SET_ORDER ) ) {
			// Browsers use the last src declaration.
			$src = end( $declarations );
			if ( preg_match( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $src[1], $url ) ) {
				$woff2 = Fonts::woff2_for( $url[1] );
				if ( $woff2 ) {
					$new  = 'src:url("' . esc_url_raw( $woff2 ) . '") format("woff2"),' . trim( $src[1] );
					$rule = str_replace( $src[0], $new, $rule );
				}
				// A Latin subset is declared after the full font: for characters in its
				// unicode-range the browser uses it (the last matching rule wins); every
				// other character still comes from the full font.
				$subset = Fonts::subset_for( $url[1] );
				if ( $subset && false === stripos( $original, 'unicode-range' ) ) {
					$latin = preg_replace( '/(?<![\w-])src\s*:\s*[^;}]+/i', 'src:url("' . esc_url_raw( $subset ) . '") format("woff2")', $rule, 1 );
					$latin = preg_replace( '/\}\s*$/', ';unicode-range:' . Fonts::LATIN_RANGE . '}', $latin );
					// The full font only for characters it really has (not for emoji or symbols
					// it lacks, which would otherwise make the browser download it to find out).
					$ranges = Fonts::ranges_for( $url[1] );
					if ( '' !== $ranges ) {
						$rule = preg_replace( '/\}\s*$/', ';unicode-range:' . $ranges . '}', $rule );
					}
					$rule .= $latin;
				}
			}
		}
		return $rule;
	}

	/**
	 * Insert "<Family> Fallback" after each custom family in font-family lists and font custom properties.
	 *
	 * @param string $css CSS without @font-face blocks.
	 * @return string
	 */
	private function add_fallbacks( $css ) {
		if ( empty( $this->fallbacks ) ) {
			return $css;
		}
		return preg_replace_callback(
			'/((?:^|[;{\s])(?:font-family|--[\w-]*font[\w-]*)\s*:\s*)([^;{}]+)/i',
			function ( $match ) {
				return $match[1] . $this->add_fallback_to_list( $match[2] );
			},
			$css
		);
	}

	/**
	 * @param string $list Font family list.
	 * @return string
	 */
	private function add_fallback_to_list( $list ) {
		foreach ( $this->families as $family ) {
			if ( ! isset( $this->fallbacks[ strtolower( $family ) ] ) ) {
				continue;
			}
			$fallback = Fonts::fallback_name( $family );
			if ( false !== stripos( $list, $fallback ) ) {
				continue;
			}
			$quoted = '/(["\'])' . preg_quote( $family, '/' ) . '\1/i';
			if ( preg_match( $quoted, $list ) ) {
				$list = preg_replace( $quoted, '$0,"' . $fallback . '"', $list, 1 );
			} elseif ( preg_match( '/(^|,)\s*' . preg_quote( $family, '/' ) . '\s*(?=,|$|!)/i', $list ) ) {
				$list = preg_replace( '/(^|,)(\s*)' . preg_quote( $family, '/' ) . '(\s*)(?=,|$|!)/i', '$1$2' . $family . ',"' . $fallback . '"$3', $list, 1 );
			}
		}
		return $list;
	}

	/**
	 * Make relative url() references absolute so the CSS can live in another folder.
	 *
	 * @param string $css      CSS.
	 * @param string $base_url Original stylesheet URL.
	 * @return string
	 */
	public static function absolutize_urls( $css, $base_url ) {
		$base_dir = trailingslashit( dirname( strtok( $base_url, '?' ) ) );
		return preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			static function ( $match ) use ( $base_dir ) {
				$url = trim( $match[2] );
				if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i', $url ) ) {
					return $match[0];
				}
				return 'url(' . $match[1] . \WP_Http::make_absolute_url( $url, $base_dir ) . $match[1] . ')';
			},
			$css
		);
	}

	/**
	 * URL of a rewritten copy of a local stylesheet, or null to keep the original.
	 *
	 * @param string $href Stylesheet URL.
	 * @param string $sig  Settings signature (part of the cache key).
	 * @return string|null
	 */
	public function rewritten_url( $href, $sig ) {
		$path = Fonts::local_path( $href );
		if ( null === $path || ! preg_match( '/\.css$/i', $path ) ) {
			return null;
		}
		$mtime   = (int) filemtime( $path );
		$uploads = wp_get_upload_dir();
		$name    = sanitize_file_name( basename( $path, '.css' ) ) . '-' . substr( md5( $path . '|' . $mtime . '|' . $sig . '|' . SHD_PERF_VERSION ), 0, 12 ) . '.css';
		$dir     = trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
		$target  = trailingslashit( $dir ) . $name;
		$url     = trailingslashit( $uploads['baseurl'] ) . self::CACHE_SUBDIR . '/' . $name;

		if ( is_file( $target ) ) {
			// Still in use: keep it out of the daily clean-up of copies unused for 30 days
			// (stored pages of the page cache link to it for up to 12 hours).
			if ( filemtime( $target ) < time() - DAY_IN_SECONDS ) {
				@touch( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $url;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet.
		$css = file_get_contents( $path );
		if ( false === $css || ! $this->wants( $css ) ) {
			return null;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$rewritten = $this->rewrite( $css, $href );
		$temp      = $target . '.' . wp_generate_password( 6, false, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache file.
		if ( false === file_put_contents( $temp, $rewritten ) || ! @rename( $temp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $temp );
			return null;
		}
		return $url;
	}

	/**
	 * Delete cached copies.
	 */
	public static function clear_cache() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
		foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
			if ( is_string( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
