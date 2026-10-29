<?php
/**
 * Custom web-font registry: detected @font-face rules, uploaded WOFF2 files,
 * fallback metrics and preloads.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Fonts {

	const UPLOAD_SUBDIR = 'shd-performance/fonts';

	/** File-name suffix of an uploaded Latin subset (Font-latin.woff2). */
	const SUBSET_SUFFIX = '-latin';

	/**
	 * Characters in a Latin subset (the range Google Fonts uses for "latin", plus
	 * the arrows U+2190-2199). A subset must be made for exactly this range: the
	 * full font stays declared for every other character it has.
	 */
	const LATIN_RANGE = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2190-2199,U+2212,U+2215,U+FEFF,U+FFFD';

	/**
	 * Parse @font-face rules from CSS text.
	 *
	 * "src" is the first URL of the last src declaration (the one browsers use);
	 * "sources" lists every URL of every src declaration, that one first.
	 *
	 * @param string $css CSS.
	 * @return array<int,array{family:string,weight:string,style:string,src:string,sources:string[]}>
	 */
	public static function parse_font_faces( $css ) {
		$faces = array();
		if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/i', $css, $blocks ) ) {
			return $faces;
		}
		foreach ( $blocks[1] as $body ) {
			$family = self::descriptor( $body, 'font-family' );
			if ( '' === $family ) {
				continue;
			}
			$sources = array();
			if ( preg_match_all( '/(?:^|[;{\s])src\s*:\s*([^;]+)/i', $body, $declarations ) ) {
				foreach ( array_reverse( $declarations[1] ) as $declaration ) {
					if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $declaration, $urls ) ) {
						$sources = array_merge( $sources, array_map( 'trim', $urls[1] ) );
					}
				}
			}
			$sources = array_values( array_unique( $sources ) );
			$faces[] = array(
				'family'  => trim( $family, " \t\n\r\0\x0B'\"" ),
				'weight'  => self::descriptor( $body, 'font-weight' ) ? self::descriptor( $body, 'font-weight' ) : '400',
				'style'   => self::descriptor( $body, 'font-style' ) ? self::descriptor( $body, 'font-style' ) : 'normal',
				'src'     => isset( $sources[0] ) ? $sources[0] : '',
				'sources' => $sources,
			);
		}
		return $faces;
	}

	/**
	 * @param string $body Declaration block.
	 * @param string $name Descriptor name.
	 * @return string
	 */
	private static function descriptor( $body, $name ) {
		if ( preg_match( '/(?:^|[;{\s])' . preg_quote( $name, '/' ) . '\s*:\s*([^;]+)/i', $body, $match ) ) {
			return trim( $match[1] );
		}
		return '';
	}

	/**
	 * Detected faces (stored by the Fonts tab).
	 *
	 * @return array
	 */
	public static function faces() {
		$fonts = Settings::get()['fonts'];
		return is_array( $fonts['faces'] ) ? $fonts['faces'] : array();
	}

	/**
	 * Families that have custom @font-face rules.
	 *
	 * @return string[]
	 */
	public static function families() {
		$families = array();
		foreach ( self::faces() as $face ) {
			$families[ strtolower( $face['family'] ) ] = $face['family'];
		}
		return array_values( $families );
	}

	/**
	 * Key that links a .ttf/.otf/.woff source to its uploaded .woff2 twin.
	 *
	 * @param string $url Font URL.
	 * @return string
	 */
	public static function key_for( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return strtolower( preg_replace( '/\.(ttf|otf|woff2?|eot|svg)$/i', '', basename( $path ) ) );
	}

	/**
	 * Uploaded WOFF2 URL for a font source, if any.
	 *
	 * @param string $url Font URL from an @font-face src.
	 * @return string|null
	 */
	public static function woff2_for( $url ) {
		$map = Settings::get()['fonts']['woff2'];
		$key = self::key_for( $url );
		return ( is_array( $map ) && isset( $map[ $key ] ) ) ? $map[ $key ] : null;
	}

	/**
	 * Characters the original font file has (CSS unicode-range), if detected.
	 *
	 * @param string $url Font URL from an @font-face src.
	 * @return string
	 */
	public static function ranges_for( $url ) {
		$key = self::key_for( $url );
		foreach ( self::faces() as $face ) {
			if ( ! empty( $face['ranges'] ) && self::key_for( $face['src'] ) === $key ) {
				return (string) $face['ranges'];
			}
		}
		return '';
	}

	/**
	 * Uploaded Latin subset (Font-latin.woff2) for a font source, if any.
	 *
	 * @param string $url Font URL from an @font-face src.
	 * @return string|null
	 */
	public static function subset_for( $url ) {
		$map = Settings::get()['fonts']['woff2'];
		$key = self::key_for( $url ) . self::SUBSET_SUFFIX;
		return ( is_array( $map ) && isset( $map[ $key ] ) ) ? $map[ $key ] : null;
	}

	/**
	 * Name of the size-matched fallback family.
	 *
	 * @param string $family Web font family.
	 * @return string
	 */
	public static function fallback_name( $family ) {
		return $family . ' Fallback';
	}

	/**
	 * Families with computed fallback metrics, keyed by lower-case name.
	 *
	 * @return array<string,array>
	 */
	public static function metrics() {
		$metrics = Settings::get()['fonts']['metrics'];
		return is_array( $metrics ) ? $metrics : array();
	}

	/**
	 * @font-face rules for the size-matched local fallbacks.
	 *
	 * @return string
	 */
	public static function fallback_css() {
		$css = '';
		foreach ( self::metrics() as $entry ) {
			if ( empty( $entry['family'] ) || empty( $entry['size_adjust'] ) ) {
				continue;
			}
			$css .= sprintf(
				'@font-face{font-family:"%1$s";src:local("Arial"),local("ArialMT"),local("Helvetica"),local("Liberation Sans");size-adjust:%2$s%%;ascent-override:%3$s%%;descent-override:%4$s%%;line-gap-override:%5$s%%}',
				esc_attr( self::fallback_name( $entry['family'] ) ),
				self::number( $entry['size_adjust'] ),
				self::number( $entry['ascent'] ),
				self::number( $entry['descent'] ),
				self::number( $entry['line_gap'] )
			);
		}
		return $css;
	}

	/**
	 * WOFF2 URLs to preload (only uploaded WOFF2 files for the chosen weights).
	 *
	 * @return string[]
	 */
	public static function preload_urls() {
		$weights = array_map( 'strval', (array) Settings::get()['fonts']['preload'] );
		$urls    = array();
		foreach ( self::faces() as $face ) {
			if ( 'normal' !== strtolower( $face['style'] ) || ! in_array( (string) $face['weight'], $weights, true ) ) {
				continue;
			}
			// The browser downloads the Latin subset for Latin text, so that is what to preload.
			$woff2 = self::subset_for( $face['src'] );
			if ( ! $woff2 ) {
				$woff2 = self::woff2_for( $face['src'] );
			}
			if ( ! $woff2 && preg_match( '/\.woff2(\?|#|$)/i', $face['src'] ) ) {
				$woff2 = $face['src'];
			}
			if ( $woff2 ) {
				$urls[ $woff2 ] = $woff2;
			}
		}
		return array_values( $urls );
	}

	/**
	 * Whether a face has a WOFF2 version (its own, or an uploaded one).
	 *
	 * @param array $face Face.
	 * @return bool
	 */
	public static function has_woff2( array $face ) {
		foreach ( isset( $face['sources'] ) ? $face['sources'] : array( $face['src'] ) as $source ) {
			if ( preg_match( '/\.woff2(\?|#|$)/i', $source ) ) {
				return true;
			}
		}
		return null !== self::woff2_for( $face['src'] );
	}

	/**
	 * Whether a face is one of the site's own fonts: a file in the uploads
	 * folder, or a font that only exists in old formats (TTF/OTF/EOT/SVG).
	 * Fonts that themes or plugins already ship as WOFF/WOFF2 (icon fonts,
	 * locally hosted Google Fonts) are left alone.
	 *
	 * @param array $face Face.
	 * @return bool
	 */
	private static function is_own_face( array $face ) {
		$uploads = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		$modern  = false;
		foreach ( $face['sources'] as $source ) {
			$path = (string) wp_parse_url( $source, PHP_URL_PATH );
			if ( '' !== $uploads && 0 === strpos( $path, $uploads . '/' ) ) {
				return true;
			}
			if ( preg_match( '/\.woff2?$/i', $path ) ) {
				$modern = true;
			}
		}
		return ! $modern;
	}

	/**
	 * Local path for a URL in the uploads directory or the site root.
	 *
	 * @param string $url URL (absolute or root-relative).
	 * @return string|null
	 */
	public static function local_path( $url ) {
		$uploads = wp_get_upload_dir();
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host    = wp_parse_url( $url, PHP_URL_HOST );
		$home    = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && strtolower( $host ) !== strtolower( (string) $home ) ) {
			return null;
		}

		$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' !== $base_path && 0 === strpos( $path, $base_path . '/' ) ) {
			$candidate = $uploads['basedir'] . substr( $path, strlen( $base_path ) );
		} else {
			$site_path = (string) wp_parse_url( site_url(), PHP_URL_PATH );
			$relative  = '' !== $site_path && 0 === strpos( $path, $site_path ) ? substr( $path, strlen( $site_path ) ) : $path;
			$candidate = untrailingslashit( ABSPATH ) . $relative;
		}

		$real = realpath( rawurldecode( $candidate ) );
		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}
		$root = realpath( ABSPATH );
		$up   = realpath( $uploads['basedir'] );
		if ( ( $root && 0 === strpos( $real, $root ) ) || ( $up && 0 === strpos( $real, $up ) ) ) {
			return $real;
		}
		return null;
	}

	/**
	 * CSS where custom @font-face rules live: the Elementor kit stylesheet and
	 * the front page's local stylesheets and inline styles, as visitors get
	 * them without this plugin's changes.
	 *
	 * @return string
	 */
	public static function collect_css() {
		$css     = '';
		$uploads = wp_get_upload_dir();
		$kit     = (int) get_option( 'elementor_active_kit' );
		if ( $kit > 0 ) {
			$file = trailingslashit( $uploads['basedir'] ) . 'elementor/css/post-' . $kit . '.css';
			if ( is_readable( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet.
				$css .= Css_Rewriter::absolutize_urls( (string) file_get_contents( $file ), trailingslashit( $uploads['baseurl'] ) . 'elementor/css/post-' . $kit . '.css' ) . "\n";
			}
		}

		$url      = add_query_arg(
			array(
				'shd-off'     => '1',
				'shd-preview' => Settings::preview_token(),
				'shd-nocache' => wp_generate_password( 8, false, false ),
			),
			home_url( '/' )
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $css;
		}

		$seen = array();
		$tags = new \WP_HTML_Tag_Processor( (string) wp_remote_retrieve_body( $response ) );
		while ( $tags->next_tag() ) {
			if ( 'STYLE' === $tags->get_tag() ) {
				$css .= $tags->get_modifiable_text() . "\n";
				continue;
			}
			if ( 'LINK' !== $tags->get_tag() || 'stylesheet' !== strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ) ) {
				continue;
			}
			$href = (string) $tags->get_attribute( 'href' );
			$path = '' !== $href ? self::local_path( (string) strtok( $href, '?#' ) ) : null;
			if ( null === $path || isset( $seen[ $path ] ) || ! preg_match( '/\.css$/i', $path ) || filesize( $path ) > 2 * MB_IN_BYTES ) {
				continue;
			}
			$seen[ $path ] = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet.
			$css .= Css_Rewriter::absolutize_urls( (string) file_get_contents( $path ), $href ) . "\n";
		}
		return $css;
	}

	/**
	 * Detect faces in CSS and compute fallback metrics; stores the result.
	 *
	 * @param string $css CSS gathered from the front page.
	 * @return array Detected faces.
	 */
	public static function detect_and_store( $css ) {
		$faces = array();
		foreach ( self::parse_font_faces( $css ) as $face ) {
			if ( '' === $face['src'] || 0 === strpos( $face['src'], 'data:' ) || ! self::is_own_face( $face ) ) {
				continue;
			}
			$faces[ strtolower( $face['family'] . '|' . $face['weight'] . '|' . $face['style'] ) ] = $face;
		}
		$faces = array_values( $faces );

		// Exact character coverage of each local TrueType/OpenType file: lets the full
		// font be skipped for characters it does not have when a Latin subset is used.
		foreach ( $faces as $index => $face ) {
			foreach ( $face['sources'] as $source ) {
				$path = preg_match( '/\.(ttf|otf)(\?|$)/i', $source ) ? self::local_path( $source ) : null;
				if ( null !== $path ) {
					$faces[ $index ]['ranges'] = Font_Metrics::unicode_ranges( $path );
					break;
				}
			}
		}

		$metrics = array();
		foreach ( $faces as $face ) {
			$family_key = strtolower( $face['family'] );
			if ( isset( $metrics[ $family_key ] ) && '400' !== (string) $face['weight'] ) {
				continue;
			}
			// Metrics are read from a TrueType/OpenType source; WOFF/WOFF2 are compressed.
			$path = null;
			foreach ( isset( $face['sources'] ) ? $face['sources'] : array( $face['src'] ) as $source ) {
				if ( preg_match( '/\.(ttf|otf)(\?|$)/i', $source ) ) {
					$path = self::local_path( $source );
					if ( null !== $path ) {
						break;
					}
				}
			}
			if ( null === $path ) {
				continue;
			}
			$overrides = Font_Metrics::fallback_overrides( $path );
			if ( null !== $overrides ) {
				$metrics[ $family_key ] = array_merge( array( 'family' => $face['family'] ), $overrides );
			}
		}

		Settings::update(
			array(
				'fonts' => array(
					'faces'   => $faces,
					'metrics' => $metrics,
				),
			)
		);
		return $faces;
	}

	/**
	 * Store an uploaded WOFF2 file.
	 *
	 * @param array $file One entry of $_FILES (already normalised).
	 * @return string|\WP_Error Public URL.
	 */
	public static function store_upload( array $file ) {
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'upload', __( 'The upload failed.', 'shd-performance' ) );
		}
		$name = sanitize_file_name( $file['name'] );
		if ( ! preg_match( '/\.woff2$/i', $name ) ) {
			return new \WP_Error( 'type', __( 'Only .woff2 files can be uploaded here.', 'shd-performance' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the uploaded temp file header.
		$magic = (string) file_get_contents( $file['tmp_name'], false, null, 0, 4 );
		if ( 'wOF2' !== $magic ) {
			return new \WP_Error( 'type', __( 'This file is not a WOFF2 font.', 'shd-performance' ) );
		}
		if ( filesize( $file['tmp_name'] ) > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'size', __( 'The font file is larger than 2 MB.', 'shd-performance' ) );
		}

		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'dir', __( 'The fonts folder could not be created.', 'shd-performance' ) );
		}
		$target = trailingslashit( $dir ) . $name;
		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'move', __( 'The font file could not be saved.', 'shd-performance' ) );
		}
		chmod( $target, 0644 );

		$url = trailingslashit( $uploads['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . rawurlencode( $name );
		$map = Settings::get()['fonts']['woff2'];
		$map = is_array( $map ) ? $map : array();
		$map[ self::key_for( $name ) ] = $url;
		Settings::update( array( 'fonts' => array( 'woff2' => $map ) ) );

		return $url;
	}

	/**
	 * Remove an uploaded WOFF2 mapping (the file is deleted too).
	 *
	 * @param string $key Font key.
	 */
	public static function remove_upload( $key ) {
		$map = Settings::get()['fonts']['woff2'];
		if ( ! is_array( $map ) || ! isset( $map[ $key ] ) ) {
			return;
		}
		$path = self::local_path( $map[ $key ] );
		if ( $path ) {
			wp_delete_file( $path );
		}
		unset( $map[ $key ] );
		Settings::update( array( 'fonts' => array( 'woff2' => $map ) ) );
	}

	/**
	 * @param float|int|string $value Number.
	 * @return string
	 */
	private static function number( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
