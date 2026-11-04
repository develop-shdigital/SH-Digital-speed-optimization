<?php
/**
 * One stylesheet instead of many: the render-blocking stylesheets in <head>, and the
 * inline styles between them, joined in their original order into one cached file.
 *
 * WordPress prints every plugin's and every Elementor widget's stylesheet as its own
 * <link>, and the browser paints nothing until all of them have arrived. On
 * sh-digital.ch that was 22 to 33 files per page; the server answered them in waves,
 * the last one up to 1.5 s after the HTML. Joined in the same order, the cascade is
 * unchanged, so the page looks exactly as before.
 *
 * A stylesheet stays separate when joining could change how it applies: other hosts,
 * print styles, @import, CSS that does not close its own blocks, inline styles with
 * relative URLs, and the files Elementor loads itself when a widget needs them. Each
 * joined <link> leaves an inert placeholder with its id and URL, so scripts that check
 * whether a stylesheet is on the page (LA-Studio Kit, Elementor) do not load it again.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Css_Combiner {

	const PREFIX = 'combined-';

	/** Placeholder rel value: an unknown link type, so browsers fetch nothing. */
	const PLACEHOLDER_REL = 'shd-combined';

	/** Largest stylesheet that is joined (bytes). */
	const MAX_FILE = 1048576;

	/**
	 * Elementor's loader adds these when a widget needs them and checks for a
	 * <link href="…"> with exactly that URL; they keep their own <link>.
	 */
	const KEEP = array(
		'/elementor/assets/lib/swiper/',
		'/elementor/assets/css/conditionals/lightbox',
		'/elementor/assets/css/conditionals/dialog',
		'/elementor/css/custom-lightbox',
	);

	/** Attributes that mean "load this stylesheet in a special way". */
	const SPECIAL = array( 'onload', 'disabled', 'integrity', 'title', 'nonce', 'data-no-optimize', 'data-noptimize', 'data-skip-combine' );

	/**
	 * @param string $html Document.
	 * @return array{0:string,1:int} Document and the number of stylesheets joined.
	 */
	public static function combine( $html ) {
		$end = stripos( $html, '</head>' );
		if ( false === $end ) {
			return array( $html, 0 );
		}
		$head = substr( $html, 0, $end );
		$runs = self::runs( self::items( $head ) );
		if ( empty( $runs ) ) {
			return array( $html, 0 );
		}

		$urls = array();
		foreach ( $runs as $r => $run ) {
			$urls[ $r ] = self::write( $run );
		}

		$joined = 0;
		$number = 0;
		$ids    = array();
		foreach ( $runs as $r => $run ) {
			if ( null !== $urls[ $r ] ) {
				++$number;
				$ids[ $r ] = 1 === $number ? 'shd-perf-combined-css' : 'shd-perf-combined-' . $number . '-css';
			}
		}

		// Replace from the end of <head> so earlier offsets stay valid.
		for ( $r = count( $runs ) - 1; $r >= 0; $r-- ) {
			if ( null === $urls[ $r ] ) {
				continue;
			}
			$run = $runs[ $r ];
			for ( $i = count( $run ) - 1; $i >= 0; $i-- ) {
				$item        = $run[ $i ];
				$replacement = self::placeholder( $item );
				if ( 0 === $i ) {
					$replacement = '<link rel="stylesheet" id="' . esc_attr( $ids[ $r ] ) . '" href="' . esc_url( $urls[ $r ] ) . '" media="all" />' . "\n" . $replacement;
				}
				$head = substr( $head, 0, $item['offset'] ) . $replacement . substr( $head, $item['offset'] + $item['length'] );
			}
			$joined += count( $run );
		}

		return array( $head . substr( $html, $end ), $joined );
	}

	/**
	 * Stylesheets and inline styles in <head>, in page order. Each item says whether it
	 * can be joined; anything that cannot is a barrier that ends the current run.
	 *
	 * @param string $head Markup before </head>.
	 * @return array<int,array>
	 */
	private static function items( $head ) {
		$items = array();
		// Comments and raw-text elements are matched whole, so markup inside them is never read as tags.
		if ( ! preg_match_all( '#<!--.*?-->|<(script|noscript|template|style|title|textarea)\b([^>]*)>(.*?)</\1\s*>|<link\b[^>]*>#is', $head, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $items;
		}
		foreach ( $matches as $m ) {
			$tag    = $m[0][0];
			$offset = $m[0][1];
			if ( 0 === strpos( $tag, '<!--' ) ) {
				continue;
			}
			$name = ( isset( $m[1] ) && $m[1][1] >= 0 && '' !== $m[1][0] ) ? strtolower( $m[1][0] ) : 'link';
			if ( 'link' === $name ) {
				$item = self::link_item( $tag, $offset );
			} elseif ( 'style' === $name ) {
				$item = self::style_item( $m[2][0], $m[3][0], $offset, strlen( $tag ) );
			} elseif ( ( 'noscript' === $name || 'template' === $name ) && preg_match( '#<(link|style)\b#i', $m[3][0] ) ) {
				// Styles that apply in other conditions: keep the order around them.
				$item = array( 'ok' => false );
			} else {
				$item = null;
			}
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * @param string $tag    <link> tag.
	 * @param int    $offset Offset in <head>.
	 * @return array|null Item, or null for links that are not stylesheets.
	 */
	private static function link_item( $tag, $offset ) {
		$attrs = self::attributes( $tag );
		if ( ! isset( $attrs['rel'] ) || 'stylesheet' !== strtolower( trim( $attrs['rel'] ) ) ) {
			return null;
		}
		$item = array(
			'ok'     => false,
			'kind'   => 'link',
			'offset' => $offset,
			'length' => strlen( $tag ),
			'id'     => isset( $attrs['id'] ) ? $attrs['id'] : '',
			'href'   => isset( $attrs['href'] ) ? $attrs['href'] : '',
		);
		foreach ( self::SPECIAL as $name ) {
			if ( isset( $attrs[ $name ] ) ) {
				return $item;
			}
		}
		$media = self::media( isset( $attrs['media'] ) ? $attrs['media'] : '' );
		$href  = html_entity_decode( $item['href'], ENT_QUOTES );
		if ( null === $media || '' === $href || self::kept( $href ) ) {
			return $item;
		}
		$url  = self::absolute( $href );
		$path = Fonts::local_path( $url );
		if ( null === $path || ! preg_match( '/\.css$/i', $path ) || filesize( $path ) > self::MAX_FILE ) {
			return $item;
		}
		$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet.
		if ( false === $css || ! self::safe( $css ) || ( '' !== $media && preg_match( '/@font-face/i', $css ) ) ) {
			return $item;
		}
		$item['ok']    = true;
		$item['css']   = Css_Rewriter::absolutize_urls( $css, $url );
		$item['media'] = $media;
		$item['label'] = '' !== $item['id'] ? $item['id'] : wp_basename( $path );
		return $item;
	}

	/**
	 * @param string $attributes Attributes of the <style> tag.
	 * @param string $css        Its content.
	 * @param int    $offset     Offset in <head>.
	 * @param int    $length     Length of the whole element.
	 * @return array|null Item, or null for an empty style (left where it is).
	 */
	private static function style_item( $attributes, $css, $offset, $length ) {
		$attrs = self::attributes( '<style' . $attributes . '>' );
		$item  = array(
			'ok'     => false,
			'kind'   => 'style',
			'offset' => $offset,
			'length' => $length,
			'id'     => isset( $attrs['id'] ) ? $attrs['id'] : '',
		);
		if ( '' === trim( $css ) ) {
			return null;
		}
		$type = isset( $attrs['type'] ) ? strtolower( trim( $attrs['type'] ) ) : '';
		// Our own styles (SHD Performance, SHD Consent) are small and needed for the first paint: they stay inline.
		if ( ( '' !== $type && 'text/css' !== $type ) || 0 === strpos( $item['id'], 'shd-' ) ) {
			return $item;
		}
		foreach ( self::SPECIAL as $name ) {
			if ( isset( $attrs[ $name ] ) ) {
				return $item;
			}
		}
		$media = self::media( isset( $attrs['media'] ) ? $attrs['media'] : '' );
		// A relative url() means "relative to this page"; in a file elsewhere it would point somewhere else.
		if ( null === $media || preg_match( '/url\(\s*+[\'"]?+(?![a-z][a-z0-9+.-]*:|\/|#)/i', $css ) || ! self::safe( $css ) || ( '' !== $media && preg_match( '/@font-face/i', $css ) ) ) {
			return $item;
		}
		$item['ok']    = true;
		$item['css']   = $css;
		$item['media'] = $media;
		$item['label'] = '' !== $item['id'] ? $item['id'] : 'inline style';
		return $item;
	}

	/**
	 * Consecutive joinable items (two or more), split at every barrier.
	 *
	 * @param array $items Items.
	 * @return array<int,array>
	 */
	private static function runs( array $items ) {
		$runs    = array();
		$current = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['ok'] ) ) {
				$current[] = $item;
				continue;
			}
			if ( count( $current ) > 1 ) {
				$runs[] = $current;
			}
			$current = array();
		}
		if ( count( $current ) > 1 ) {
			$runs[] = $current;
		}
		return $runs;
	}

	/**
	 * Write the joined file (named after its content) and return its URL.
	 *
	 * @param array $run Items.
	 * @return string|null
	 */
	private static function write( array $run ) {
		$css = '';
		foreach ( $run as $item ) {
			$chunk = (string) preg_replace( '/^\s*@charset\s+[^;]+;/i', '', $item['css'] );
			$chunk = self::font_display( $chunk );
			if ( '' !== $item['media'] ) {
				$chunk = '@media ' . $item['media'] . '{' . $chunk . "\n}";
			}
			$css .= '/* ' . str_replace( '*/', '', $item['label'] ) . " */\n" . $chunk . "\n";
		}

		$uploads = wp_get_upload_dir();
		$name    = self::PREFIX . substr( md5( $css ), 0, 16 ) . '.css';
		$dir     = trailingslashit( $uploads['basedir'] ) . Css_Rewriter::CACHE_SUBDIR;
		$target  = trailingslashit( $dir ) . $name;
		$url     = trailingslashit( $uploads['baseurl'] ) . Css_Rewriter::CACHE_SUBDIR . '/' . $name;

		if ( is_file( $target ) ) {
			// Still in use: keep it out of the daily clean-up of files unused for 30 days.
			if ( filemtime( $target ) < time() - DAY_IN_SECONDS ) {
				@touch( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $url;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$temp = $target . '.' . wp_generate_password( 6, false, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache file.
		if ( false === file_put_contents( $temp, $css ) || ! @rename( $temp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $temp );
			return null;
		}
		return $url;
	}

	/**
	 * What stays in place of a joined element: links keep their id and URL (for scripts
	 * that look for them) on an inert link type; inline styles keep an empty element.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	private static function placeholder( array $item ) {
		if ( 'link' === $item['kind'] ) {
			return '<link rel="' . self::PLACEHOLDER_REL . '"' . ( '' !== $item['id'] ? ' id="' . esc_attr( $item['id'] ) . '"' : '' ) . ' href="' . esc_attr( html_entity_decode( $item['href'], ENT_QUOTES ) ) . '" />';
		}
		return '' !== $item['id'] ? '<style id="' . esc_attr( $item['id'] ) . '"></style>' : '';
	}

	/**
	 * Whether CSS can be joined with others: every comment, string and block it opens is
	 * closed again (otherwise it would swallow the next file's rules), and it has no
	 * @import or @namespace (only valid at the start of a stylesheet).
	 *
	 * @param string $css CSS.
	 * @return bool
	 */
	public static function safe( $css ) {
		if ( preg_match( '#<\s*/?\s*(?:style|script)\b#i', $css ) ) {
			return false;
		}
		$code = preg_replace( '#/\*.*?\*/|"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'#s', '', $css );
		if ( null === $code || false !== strpos( $code, '/*' ) || false !== strpos( $code, '"' ) || false !== strpos( $code, "'" ) ) {
			return false;
		}
		if ( preg_match( '/@(?:import|namespace)\b/i', $code ) ) {
			return false;
		}
		$depth = 0;
		$count = strlen( $code );
		for ( $i = strcspn( $code, '{}' ); $i < $count; $i += 1 + strcspn( $code, '{}', $i + 1 ) ) {
			$depth += '{' === $code[ $i ] ? 1 : -1;
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/**
	 * font-display: swap for @font-face rules without one (or with auto/block), so text
	 * and icons do not stay invisible while their font loads.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function font_display( $css ) {
		if ( false === stripos( $css, '@font-face' ) ) {
			return $css;
		}
		return (string) preg_replace_callback(
			'/@font-face\s*\{[^}]*\}/i',
			static function ( $m ) {
				if ( preg_match( '/font-display\s*:/i', $m[0] ) ) {
					return (string) preg_replace( '/font-display\s*:\s*(?:auto|block)\b/i', 'font-display:swap', $m[0] );
				}
				return (string) preg_replace( '/\{/', '{font-display:swap;', $m[0], 1 );
			},
			$css
		);
	}

	/**
	 * Media attribute to wrap with: '' for all screens, the query itself, or null for
	 * styles that never block rendering on screens (print, speech).
	 *
	 * @param string $media Media attribute.
	 * @return string|null
	 */
	private static function media( $media ) {
		$media = trim( html_entity_decode( (string) $media, ENT_QUOTES ) );
		if ( '' === $media || 'all' === strtolower( $media ) ) {
			return '';
		}
		foreach ( explode( ',', strtolower( $media ) ) as $part ) {
			$part = trim( (string) preg_replace( '/^(?:only|not)\s+/', '', trim( $part ) ) );
			if ( '' === $part || 0 === strpos( $part, 'all' ) || 0 === strpos( $part, 'screen' ) || 0 === strpos( $part, '(' ) ) {
				return $media;
			}
		}
		return null;
	}

	/**
	 * @param string $href Stylesheet URL.
	 * @return bool Whether it is one that Elementor loads itself.
	 */
	private static function kept( $href ) {
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );
		foreach ( self::KEEP as $part ) {
			if ( false !== strpos( $path, $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $href URL as written in the page.
	 * @return string Absolute URL.
	 */
	private static function absolute( $href ) {
		if ( 0 === strpos( $href, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $href;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			$home = wp_parse_url( home_url() );
			return ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $href;
		}
		return $href;
	}

	/**
	 * Attributes of one start tag (names lower-cased, values decoded as written).
	 *
	 * @param string $tag Start tag.
	 * @return array<string,string>
	 */
	public static function attributes( $tag ) {
		$attrs = array();
		if ( preg_match_all( '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', (string) preg_replace( '/^<\w+|\/?>$/', '', $tag ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$name = strtolower( $a[1] );
				if ( isset( $attrs[ $name ] ) ) {
					continue;
				}
				$value = '';
				if ( isset( $a[4] ) && '' !== $a[4] ) {
					$value = $a[4];
				} elseif ( isset( $a[3] ) && '' !== $a[3] ) {
					$value = $a[3];
				} elseif ( isset( $a[2] ) ) {
					$value = $a[2];
				}
				$attrs[ $name ] = $value;
			}
		}
		return $attrs;
	}
}
