<?php
/**
 * Background images of Elementor elements, read from the page's CSS.
 *
 * Elementor writes a section's background image into a stylesheet
 * (".elementor-123 .elementor-element.elementor-element-abc1234{background-image:url(…)}"),
 * often with other images for tablets and phones in @media blocks. The browser only
 * finds such an image after it has downloaded and applied that stylesheet, so the
 * largest element of many pages started loading 2 s late. This finds the image a given
 * element shows at a given screen width, so it can be preloaded from <head>.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Backgrounds {

	/** @var array<string,string|false> Stylesheet contents by URL, for this request. */
	private static $files = array();

	/**
	 * Background rules for the given element ids, from every stylesheet and <style> in the
	 * document, in cascade order.
	 *
	 * @param string   $html Document.
	 * @param string[] $ids  Elementor element ids (data-id).
	 * @return array<int,array{sel:string,media:string[],url:string}>
	 */
	public static function collect( $html, array $ids ) {
		$needles = array();
		foreach ( $ids as $id ) {
			if ( preg_match( '/^[a-z0-9]+$/i', $id ) ) {
				$needles[] = 'elementor-element-' . $id;
			}
		}
		if ( empty( $needles ) ) {
			return array();
		}
		$rules = array();
		if ( ! preg_match_all( '#<!--.*?-->|<(script|noscript|template|textarea|title)\b[^>]*>.*?</\1\s*>|<style\b([^>]*)>(.*?)</style\s*>|<link\b[^>]*>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return $rules;
		}
		foreach ( $matches as $m ) {
			$css   = '';
			$media = '';
			$base  = '';
			if ( isset( $m[3] ) && 0 === stripos( $m[0], '<style' ) ) {
				$css   = $m[3];
				$attrs = Css_Combiner::attributes( '<style' . $m[2] . '>' );
				$media = isset( $attrs['media'] ) ? $attrs['media'] : '';
			} elseif ( 0 === stripos( $m[0], '<link' ) ) {
				$attrs = Css_Combiner::attributes( $m[0] );
				if ( ! isset( $attrs['rel'], $attrs['href'] ) || 'stylesheet' !== strtolower( trim( $attrs['rel'] ) ) ) {
					continue;
				}
				$base  = html_entity_decode( $attrs['href'], ENT_QUOTES );
				$css   = self::file( $base );
				$media = isset( $attrs['media'] ) ? $attrs['media'] : '';
			}
			if ( '' === $css ) {
				continue;
			}
			$found = false;
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $css, $needle ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				continue;
			}
			$outer = '' === trim( $media ) || 'all' === strtolower( trim( $media ) ) ? array() : array( trim( html_entity_decode( $media, ENT_QUOTES ) ) );
			foreach ( self::rules( $css, $needles ) as $rule ) {
				$rule['media'] = array_merge( $outer, $rule['media'] );
				// A relative url() in a stylesheet is relative to that file.
				if ( '' !== $base && 'none' !== $rule['url'] && ! preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $rule['url'] ) ) {
					$rule['url'] = \WP_Http::make_absolute_url( $rule['url'], trailingslashit( dirname( strtok( 0 === strpos( $base, '//' ) ? 'https:' . $base : $base, '?' ) ) ) );
				}
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * The background image an element shows at a screen width ('none' when a rule
	 * removes it, null when no rule sets one).
	 *
	 * @param array  $rules From collect().
	 * @param string $id    Element id.
	 * @param int    $loop  Loop item post id (0 outside loop grids).
	 * @param int    $width Viewport width in CSS pixels.
	 * @return string|null
	 */
	public static function url( array $rules, $id, $loop, $width ) {
		$url = null;
		foreach ( $rules as $rule ) {
			if ( ! self::targets( $rule['sel'], $id, $loop ) ) {
				continue;
			}
			$ok = true;
			foreach ( $rule['media'] as $query ) {
				if ( ! self::media_matches( $query, $width ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$url = $rule['url'];
			}
		}
		return $url;
	}

	/**
	 * Style rules mentioning one of the needles that set or remove a background image.
	 *
	 * @param string   $css     CSS.
	 * @param string[] $needles Class names to look for.
	 * @return array<int,array{sel:string,media:string[],url:string}>
	 */
	public static function rules( $css, array $needles ) {
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out   = array();
		$stack = array();
		$len   = strlen( $css );
		$i     = 0;
		while ( $i < $len ) {
			$close = strpos( $css, '}', $i );
			if ( false === $close ) {
				break;
			}
			$open = strpos( $css, '{', $i );
			if ( false === $open || $open > $close ) {
				// End of an at-rule block.
				array_pop( $stack );
				$i = $close + 1;
				continue;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			// Statements without a block (@charset, @import) end with ";" before the next rule.
			$semi = strrpos( $prelude, ';' );
			if ( false !== $semi ) {
				$prelude = trim( substr( $prelude, $semi + 1 ) );
			}
			if ( '' !== $prelude && '@' === $prelude[0] ) {
				$stack[] = 0 === stripos( $prelude, '@media' ) ? trim( substr( $prelude, 6 ) ) : '';
				$i       = $open + 1;
				continue;
			}
			$body = substr( $css, $open + 1, $close - $open - 1 );
			$i    = $close + 1;
			$hit  = false;
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $prelude, $needle ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				continue;
			}
			$url = self::declared_image( $body );
			if ( null !== $url ) {
				$out[] = array(
					'sel'   => $prelude,
					'media' => array_values( array_filter( $stack, 'strlen' ) ),
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * The image a declaration block sets: its URL, 'none' when it removes the image
	 * (background-image:none, a gradient alone, or a background shorthand without an
	 * image), null when it does not touch it or depends on a variable.
	 *
	 * @param string $body Declarations.
	 * @return string|null
	 */
	public static function declared_image( $body ) {
		$found = null;
		if ( ! preg_match_all( '/(?:^|;)\s*(background(?:-image)?)\s*:\s*([^;]+)/i', $body, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $m as $declaration ) {
			$value = trim( $declaration[2] );
			if ( false !== stripos( $value, 'var(' ) ) {
				$found = null;
			} elseif ( preg_match( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $value, $u ) ) {
				$found = 0 === stripos( trim( $u[2] ), 'data:' ) ? 'none' : trim( $u[2] );
			} elseif ( preg_match( '/^(?:none|initial|unset)\b/i', $value ) || 'background' === strtolower( $declaration[1] ) || false !== stripos( $value, 'gradient(' ) ) {
				$found = 'none';
			}
		}
		return $found;
	}

	/**
	 * Whether a selector list styles the element itself (not a child, a pseudo-element
	 * or a hover state). Inside a loop grid, rules for another post's card do not count.
	 *
	 * @param string $selectors Selector list.
	 * @param string $id        Element id.
	 * @param int    $loop      Loop item post id (0 = not in a loop item).
	 * @return bool
	 */
	public static function targets( $selectors, $id, $loop ) {
		$needle = 'elementor-element-' . $id;
		foreach ( explode( ',', $selectors ) as $part ) {
			$part = trim( $part );
			$at   = strpos( $part, $needle );
			if ( false === $at ) {
				continue;
			}
			$rest = substr( $part, $at + strlen( $needle ) );
			// The element itself (more classes, attributes or :not(…) on the same element), or
			// Elementor's motion-effects layer that carries the same image.
			if ( ! preg_match( '/^(?:\.[\w-]+|\[[^\]]*\]|:not\([^)]*\))*(?:\s*>\s*\.elementor-motion-effects-container\s*>\s*\.elementor-motion-effects-layer)?\s*$/', $rest ) ) {
				continue;
			}
			if ( preg_match( '/:(?:hover|focus|active)|::?(?:before|after)/i', $part ) ) {
				continue;
			}
			if ( preg_match_all( '/\be-loop-item-(\d+)\b/', $part, $posts ) && ! in_array( (string) $loop, $posts[1], true ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Evaluate a media query for a screen of the given width (only width conditions are
	 * understood; anything else counts as not matching).
	 *
	 * @param string $query Media query list.
	 * @param int    $width Viewport width.
	 * @return bool
	 */
	public static function media_matches( $query, $width ) {
		foreach ( explode( ',', strtolower( html_entity_decode( $query, ENT_QUOTES ) ) ) as $part ) {
			$part = trim( $part );
			$not  = false;
			if ( 0 === strpos( $part, 'not ' ) ) {
				$not  = true;
				$part = trim( substr( $part, 4 ) );
			}
			$part = (string) preg_replace( '/^only\s+/', '', $part );
			$ok   = true;
			foreach ( preg_split( '/\s+and\s+|\)\s*and\s*\(/', $part ) as $condition ) {
				$condition = trim( $condition, " \t\n\r()" );
				if ( in_array( $condition, array( '', 'all', 'screen' ), true ) ) {
					continue;
				}
				if ( preg_match( '/^(min|max)-width\s*:\s*([\d.]+)(px|em|rem)$/', $condition, $c ) ) {
					$value = (float) $c[2] * ( 'px' === $c[3] ? 1 : 16 );
					if ( ( 'min' === $c[1] && $width < $value ) || ( 'max' === $c[1] && $width > $value ) ) {
						$ok = false;
						break;
					}
					continue;
				}
				$ok = false;
				break;
			}
			if ( $not ) {
				$ok = ! $ok;
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * All local stylesheets the document links, joined (null when one of this site's
	 * stylesheets cannot be read, so the caller cannot know what they reference).
	 *
	 * @param string $html Document.
	 * @return string|null
	 */
	public static function stylesheets( $html ) {
		$css  = '';
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$attrs = Css_Combiner::attributes( $tag );
				if ( ! isset( $attrs['rel'], $attrs['href'] ) || 'stylesheet' !== strtolower( trim( $attrs['rel'] ) ) ) {
					continue;
				}
				$href = html_entity_decode( $attrs['href'], ENT_QUOTES );
				$host = strtolower( (string) wp_parse_url( 0 === strpos( $href, '//' ) ? 'https:' . $href : $href, PHP_URL_HOST ) );
				$text = self::file( $href );
				if ( '' === $text && ( '' === $host || $host === $home ) ) {
					return null;
				}
				$css .= $text;
			}
		}
		return $css;
	}

	/**
	 * Contents of a local stylesheet ('' when not local or unreadable).
	 *
	 * @param string $href URL.
	 * @return string
	 */
	private static function file( $href ) {
		if ( ! array_key_exists( $href, self::$files ) ) {
			$path                 = Fonts::local_path( 0 === strpos( $href, '//' ) ? 'https:' . $href : $href );
			self::$files[ $href ] = ( null !== $path && preg_match( '/\.css$/i', $path ) && filesize( $path ) <= Css_Combiner::MAX_FILE ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet.
		}
		return false === self::$files[ $href ] ? '' : (string) self::$files[ $href ];
	}
}
