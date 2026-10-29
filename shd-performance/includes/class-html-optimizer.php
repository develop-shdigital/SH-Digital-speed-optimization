<?php
/**
 * Rewrites the finished HTML document in one pass with WP_HTML_Tag_Processor.
 *
 * The pass tracks the page structure (header, main Elementor document, its
 * top-level sections, footer) so decisions like "is this in the first screen"
 * are based on the real layout, then applies the active features tag by tag.
 * Insertions that need finished knowledge (facades, head tags, removals) are
 * done in finalize().
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Html_Optimizer {

	/** Container-like tags whose nesting we track. */
	const STRUCTURAL = array( 'DIV', 'SECTION', 'HEADER', 'FOOTER', 'MAIN', 'ARTICLE', 'ASIDE', 'NAV', 'FIGURE' );

	/** Attribute that marks tags to delete in finalize(). */
	const REMOVE_MARK = 'data-shd-x';

	const CLASSIC_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript' );

	/** Origins a third-party script contacts right after it loads (CookieFirst's banner asks edge.* for the visitor's region). */
	const RELATED_ORIGINS = array(
		'consent.cookiefirst.com' => array( 'edge.cookiefirst.com' ),
	);

	/** @var array<string,bool> Active features. */
	private $on;

	/** @var array Settings. */
	private $s;

	/** @var bool Whether set_modifiable_text() is available (WP 6.7+). */
	private $can_modify_text;

	/** @var bool Whether data: script URLs are allowed (no restrictive CSP). */
	private $can_defer_inline;

	/** @var Css_Rewriter|null */
	private $css;

	/** @var string */
	private $font_sig = '';

	// Analysis.
	private $widget_types   = array();
	private $handle_src     = array();
	private $uses_qi        = false;
	private $uses_lastudio  = false;
	private $recaptcha_ok   = false;
	private $has_toc        = false;

	// Structure.
	private $stack          = array();
	private $header_depth   = 0;
	private $in_footer      = false;
	private $main_seen      = false;
	private $top_index      = 0;
	private $first_screen_widgets = array();

	// LCP image candidates: bookmark name => score.
	private $lcp            = array();
	private $lcp_counter    = 0;

	// Scripts.
	private $any_delayed    = false;
	private $any_deferred   = false;
	private $seen_src       = array();
	private $preconnect     = array();
	private $held_recaptcha = false;

	// Video facades.
	private $pending_video  = null;
	private $facades        = array();
	private $video_count    = 0;
	private $iframe_facades = array();

	private $needs_dims_css = false;

	// Lazy background images: marked elements and blog/loop cards seen so far.
	private $lazy_bg        = 0;
	private $loop_items     = 0;

	/** @var array<string,int> */
	private $stats = array();

	/**
	 * @param array<string,bool> $features Active features for this request.
	 * @param array              $settings Settings.
	 * @param bool               $can_defer_inline Whether data: scripts are allowed.
	 */
	public function __construct( array $features, array $settings, $can_defer_inline = true ) {
		$this->on               = $features;
		$this->s                = $settings;
		$this->can_modify_text  = method_exists( '\WP_HTML_Tag_Processor', 'set_modifiable_text' );
		$this->can_defer_inline = $can_defer_inline && $this->can_modify_text;

		if ( ! empty( $this->on['fonts'] ) ) {
			$fallbacks      = array_keys( Fonts::metrics() );
			$this->css      = new Css_Rewriter( Fonts::families(), $fallbacks );
			$this->font_sig = md5( wp_json_encode( array( Fonts::families(), $fallbacks, $settings['fonts']['woff2'] ) ) );
		}
	}

	/**
	 * @param string $html Full HTML document.
	 * @return string
	 */
	public function optimize( $html ) {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || ! self::is_document( $html ) ) {
			return $html;
		}

		$this->analyze( $html );

		if ( $this->has_toc && ! empty( $this->on['toc_prefill'] ) ) {
			list( $html, $filled ) = Toc::prefill( $html );
			if ( $filled ) {
				$this->count( 'toc_prefilled', $filled );
			}
		}

		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $p->get_tag();
			if ( $p->is_tag_closer() ) {
				$this->close( $tag );
				continue;
			}
			$this->open( $p, $tag );
		}
		$this->apply_lcp( $p );

		return $this->finalize( $p->get_updated_html() );
	}

	/**
	 * Counters for the debug comment and tests.
	 *
	 * @return array<string,int>
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function is_document( $html ) {
		return is_string( $html ) && strlen( $html ) < 8 * MB_IN_BYTES && false !== stripos( $html, '<html' ) && false !== stripos( $html, '</head>' ) && false !== stripos( $html, '</html>' );
	}

	/* ------------------------------------------------------------------ */
	/* Analysis                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Cheap whole-document facts needed before the pass reaches <body>.
	 *
	 * @param string $html HTML.
	 */
	private function analyze( $html ) {
		if ( preg_match_all( '/\bdata-widget_type="([a-z0-9_-]+)\./i', $html, $m ) ) {
			foreach ( $m[1] as $name ) {
				$name                        = strtolower( $name );
				$this->widget_types[ $name ] = true;
				if ( 0 === strpos( $name, 'qi' ) ) {
					$this->uses_qi = true;
				} elseif ( 0 === strpos( $name, 'lakit' ) || 0 === strpos( $name, 'lastudio' ) ) {
					$this->uses_lastudio = true;
				} elseif ( 'table-of-contents' === $name ) {
					$this->has_toc = true;
				}
			}
		}
		// Qi widget markup; "qodef-qi--touch"/"--no-touch" are body classes Qi adds on every page.
		if ( false !== strpos( $html, 'qodef-shortcode' ) || preg_match( '/qodef-qi-(?!-)/', $html ) ) {
			$this->uses_qi = true;
		}
		if ( preg_match( '/data-settings="[^"]*lakit_/i', $html ) || false !== strpos( $html, 'data-lakit-' ) ) {
			$this->uses_lastudio = true;
		}

		if ( preg_match_all( '/<script\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bid=["\']([^"\']+)-js["\']/i', $tag, $id ) && preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $src ) ) {
					$this->handle_src[ $id[1] ] = html_entity_decode( $src[1], ENT_QUOTES );
				}
			}
		}

		$this->recaptcha_ok = false !== strpos( $html, 'elementor-g-recaptcha' )
			&& false === stripos( $html, 'wpcf7' )
			&& false === stripos( $html, 'gform_' )
			&& ! preg_match( '/class=["\'][^"\']*(?<![\w-])g-recaptcha(?![\w-])/i', $html );
	}

	/* ------------------------------------------------------------------ */
	/* Structure tracking                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @param \WP_HTML_Tag_Processor $p   Processor.
	 * @param string                 $tag Tag name.
	 */
	private function open( $p, $tag ) {
		if ( in_array( $tag, self::STRUCTURAL, true ) ) {
			$this->push( $p, $tag );
		}

		$this->strip_animation( $p );

		switch ( $tag ) {
			case 'DIV':
				$this->on_div( $p );
				break;
			case 'IMG':
				$this->on_img( $p );
				break;
			case 'IFRAME':
				$this->on_iframe( $p );
				break;
			case 'SCRIPT':
				$this->on_script( $p );
				break;
			case 'LINK':
				$this->on_link( $p );
				break;
			case 'STYLE':
				$this->on_style( $p );
				break;
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p   Processor.
	 * @param string                 $tag Tag.
	 */
	private function push( $p, $tag ) {
		$role = '';
		$type = $p->get_attribute( 'data-elementor-type' );

		if ( is_string( $type ) && '' !== $type ) {
			if ( 'header' === $type ) {
				$role = 'header';
			} elseif ( 'footer' === $type ) {
				$role = 'footer';
			} elseif ( 'popup' === $type ) {
				$role = 'popup';
			} elseif ( ! $this->main_seen && 0 === $this->header_depth && ! $this->in_footer && ! $this->inside( 'popup' ) ) {
				$role            = 'main';
				$this->main_seen = true;
			} else {
				$role = 'nested';
			}
		} elseif ( 'HEADER' === $tag && 0 === $this->header_depth && ! $this->main_seen && ! $this->inside( 'header' ) ) {
			$role = 'header';
		} elseif ( ( $p->has_class( 'e-parent' ) || $p->has_class( 'elementor-top-section' ) ) && 'main' === $this->nearest_document() ) {
			$role = 'top';
			++$this->top_index;
		} elseif ( 'FIGURE' === $tag && $p->has_class( 'wp-has-aspect-ratio' ) ) {
			$role = 'aspect';
		} elseif ( 'DIV' === $tag && $p->has_class( 'wp-block-embed__wrapper' ) ) {
			$role = 'embed';
		} elseif ( 'DIV' === $tag && ( $p->has_class( 'elementor-widget-image-carousel' ) || $p->has_class( 'elementor-widget-media-carousel' ) || $p->has_class( 'elementor-widget-loop-carousel' ) ) ) {
			$role = 'carousel';
		}

		if ( 'header' === $role ) {
			++$this->header_depth;
		} elseif ( 'footer' === $role ) {
			$this->in_footer = true;
		}

		$lazy = false;
		if ( ! empty( $this->on['lazy_backgrounds'] ) && ! $this->inside_lazy_bg() ) {
			$mode = $this->lazy_bg_mode( $p, $tag, $role, is_string( $type ) ? $type : '' );
			if ( '' !== $mode ) {
				$p->set_attribute( 'data-shd-lazy-bg', $mode );
				++$this->lazy_bg;
				$lazy = true;
			}
		}

		$this->stack[] = array( $tag, $role, $lazy );
	}

	/**
	 * Which background images can wait: "view" = load when near the screen,
	 * "idle" = hidden until opened (mobile menu, popups), load after the page.
	 *
	 * @param \WP_HTML_Tag_Processor $p    Processor.
	 * @param string                 $tag  Tag name.
	 * @param string                 $role Structural role.
	 * @param string                 $type data-elementor-type.
	 * @return string view|idle|''
	 */
	private function lazy_bg_mode( $p, $tag, $role, $type ) {
		if ( 'popup' === $role || $p->has_class( 'e-off-canvas' ) ) {
			return 'idle';
		}
		if ( 'footer' === $role ) {
			return 'view';
		}
		if ( 'top' === $role ) {
			return $this->top_index > (int) $this->s['first_screen_sections'] ? 'view' : '';
		}
		// Loop grid cards (not carousel slides: a slide outside the carousel's window never "comes into view").
		if ( 'loop-item' === $type && ! $p->has_class( 'swiper-slide' ) ) {
			++$this->loop_items;
			return ( $this->loop_items > 3 || ! $this->in_first_screen() ) ? 'view' : '';
		}
		// Carousels are watched as a whole; their slides load together (see on_img()).
		if ( 'carousel' === $role && ! $this->in_first_screen( 1 ) ) {
			return 'view';
		}
		return '';
	}

	/**
	 * @return bool Whether an ancestor already defers its background images.
	 */
	private function inside_lazy_bg() {
		foreach ( $this->stack as $item ) {
			if ( ! empty( $item[2] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $tag Closing tag name.
	 */
	private function close( $tag ) {
		if ( ! in_array( $tag, self::STRUCTURAL, true ) ) {
			return;
		}
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( $this->stack[ $i ][0] !== $tag ) {
				continue;
			}
			while ( count( $this->stack ) > $i ) {
				$item = array_pop( $this->stack );
				if ( 'header' === $item[1] ) {
					$this->header_depth = max( 0, $this->header_depth - 1 );
				}
			}
			return;
		}
	}

	/**
	 * @param string $role Role.
	 * @return bool
	 */
	private function inside( $role ) {
		foreach ( $this->stack as $item ) {
			if ( $role === $item[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Role of the nearest enclosing Elementor document.
	 *
	 * @return string
	 */
	private function nearest_document() {
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( in_array( $this->stack[ $i ][1], array( 'main', 'nested', 'header', 'footer', 'popup' ), true ) ) {
				return $this->stack[ $i ][1];
			}
		}
		return '';
	}

	/**
	 * Header, or one of the first N top-level sections of the main document.
	 *
	 * @param int|null $sections Override for N.
	 * @return bool
	 */
	private function in_first_screen( $sections = null ) {
		if ( $this->header_depth > 0 ) {
			return true;
		}
		if ( $this->in_footer || $this->inside( 'popup' ) ) {
			return false;
		}
		$limit = null === $sections ? max( 1, (int) $this->s['first_screen_sections'] ) : $sections;
		return $this->top_index >= 1 && $this->top_index <= $limit && $this->inside( 'main' );
	}

	/* ------------------------------------------------------------------ */
	/* Elements                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Remove Elementor's hidden-until-animated state.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function strip_animation( $p ) {
		if ( empty( $this->on['animations'] ) ) {
			return;
		}
		if ( 'keep_below' === $this->s['animation_mode'] && ! $this->in_first_screen() ) {
			return;
		}

		if ( $p->has_class( 'elementor-invisible' ) ) {
			$p->remove_class( 'elementor-invisible' );
			$this->count( 'animations' );
		}

		$settings = $p->get_attribute( 'data-settings' );
		if ( ! is_string( $settings ) || false === strpos( $settings, 'animation' ) ) {
			return;
		}
		$data = json_decode( $settings, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$prefixes = array( '_animation' );
		if ( in_array( (string) $p->get_attribute( 'data-element_type' ), array( 'container', 'section', 'column' ), true ) ) {
			$prefixes[] = 'animation';
		}

		$changed = false;
		foreach ( array_keys( $data ) as $key ) {
			foreach ( $prefixes as $prefix ) {
				if ( $key === $prefix || 0 === strpos( $key, $prefix . '_' ) ) {
					unset( $data[ $key ] );
					$changed = true;
				}
			}
		}
		if ( ! $changed ) {
			return;
		}
		if ( empty( $data ) ) {
			$p->remove_attribute( 'data-settings' );
		} else {
			$p->set_attribute( 'data-settings', wp_json_encode( $data ) );
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_div( $p ) {
		$widget = $p->get_attribute( 'data-widget_type' );
		if ( is_string( $widget ) && '' !== $widget ) {
			$name = strtolower( strtok( $widget, '.' ) );
			if ( $this->in_first_screen() ) {
				$this->first_screen_widgets[ $name ] = true;
			}
			if ( 'theme-post-featured-image' === $name && ! empty( $this->stack ) ) {
				$this->stack[ count( $this->stack ) - 1 ][1] = 'featured';
			}
			if ( 'video' === $name && ! empty( $this->on['youtube_facade'] ) ) {
				$this->video_widget( $p );
			}
			return;
		}

		if ( null !== $this->pending_video && $p->has_class( 'elementor-video' ) ) {
			$video = $this->pending_video;
			$p->set_attribute( 'data-shd-yt-slot', (string) $video['n'] );
			$p->add_class( 'shd-yt-slot' );
			$this->facades[ $video['n'] ] = Youtube::facade_html( $video['id'], $video['settings'], $video['first'], (string) $this->s['youtube_label'] );
			$this->pending_video          = null;
		}
	}

	/**
	 * Elementor video widget: swap the player for a click-to-play facade.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function video_widget( $p ) {
		$settings = json_decode( (string) $p->get_attribute( 'data-settings' ), true );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$type = isset( $settings['video_type'] ) ? $settings['video_type'] : 'youtube';
		if ( 'youtube' !== $type || ! empty( $settings['autoplay'] ) ) {
			return;
		}
		// Elementor's own image overlay already loads the player on click. Like Elementor
		// (Widget_Video::has_image_overlay()), an overlay without an image does not count.
		if ( isset( $settings['show_image_overlay'] ) && 'yes' === $settings['show_image_overlay'] && ! empty( $settings['image_overlay']['url'] ) ) {
			return;
		}
		$id = Youtube::video_id( isset( $settings['youtube_url'] ) ? (string) $settings['youtube_url'] : '' );
		if ( '' === $id ) {
			return;
		}

		$n = $this->video_count++;
		$p->set_attribute( 'data-widget_type', 'video.shd-facade' );
		$p->set_attribute( 'data-shd-yt-widget', (string) $n );
		$this->pending_video = array(
			'n'        => $n,
			'id'       => $id,
			'settings' => $settings,
			'first'    => $this->in_first_screen(),
		);
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_img( $p ) {
		$loading = $p->get_attribute( 'loading' );

		if ( ! empty( $this->on['lcp_image'] ) ) {
			if ( $this->header_depth > 0 ) {
				if ( 'high' === $p->get_attribute( 'fetchpriority' ) ) {
					$p->remove_attribute( 'fetchpriority' );
					$this->count( 'logo_priority' );
				}
				if ( 'lazy' === $loading ) {
					$p->remove_attribute( 'loading' );
					$loading = null;
				}
			} elseif ( $this->in_first_screen() ) {
				$this->consider_lcp( $p );
			}
		}

		if ( ! empty( $this->on['image_dimensions'] ) ) {
			$this->add_dimensions( $p );
		}

		// Carousel slides further down: lazy, and all loaded together when the carousel
		// comes near the screen (a slide outside the carousel's window never "comes into view").
		if ( ! empty( $this->on['lazy_backgrounds'] ) && null === $loading && $this->inside( 'carousel' ) && $this->inside_lazy_bg() ) {
			$p->set_attribute( 'loading', 'lazy' );
			$p->set_attribute( 'data-shd-lazy-img', true );
			$loading = 'lazy';
			$this->count( 'lazy_carousel_images' );
		}

		if ( ! empty( $this->on['image_sizes'] ) && 'lazy' === $loading ) {
			$sizes  = $p->get_attribute( 'sizes' );
			$srcset = $p->get_attribute( 'srcset' );
			if ( is_string( $sizes ) && '' !== trim( $sizes ) && is_string( $srcset ) && '' !== trim( $srcset ) && 0 !== stripos( ltrim( $sizes ), 'auto' ) ) {
				$p->set_attribute( 'sizes', 'auto, ' . $sizes );
				$this->count( 'sizes_auto' );
			}
		}
	}

	/**
	 * Remember the biggest first-screen images; the winner is chosen in apply_lcp().
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function consider_lcp( $p ) {
		$width  = (int) $p->get_attribute( 'width' );
		$height = (int) $p->get_attribute( 'height' );
		$score  = $width * $height;

		if ( $this->inside( 'featured' ) ) {
			$score += 4000000000;
		} elseif ( $score < 150 * 150 ) {
			return;
		}
		if ( 1 === $this->top_index ) {
			$score += 1000000000;
		}

		if ( count( $this->lcp ) >= 4 ) {
			asort( $this->lcp );
			$lowest = key( $this->lcp );
			if ( $this->lcp[ $lowest ] >= $score ) {
				return;
			}
			$p->release_bookmark( $lowest );
			unset( $this->lcp[ $lowest ] );
		}

		$name = 'shd_lcp_' . ( $this->lcp_counter++ );
		if ( $p->set_bookmark( $name ) ) {
			$this->lcp[ $name ] = $score;
		}
	}

	/**
	 * Give the winning image high priority and no lazy-loading.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function apply_lcp( $p ) {
		if ( empty( $this->lcp ) ) {
			return;
		}
		arsort( $this->lcp );
		$winner = key( $this->lcp );
		if ( ! $p->seek( $winner ) ) {
			return;
		}
		$p->set_attribute( 'fetchpriority', 'high' );
		if ( 'lazy' === $p->get_attribute( 'loading' ) ) {
			$p->remove_attribute( 'loading' );
			$sizes = $p->get_attribute( 'sizes' );
			if ( is_string( $sizes ) && 0 === stripos( $sizes, 'auto, ' ) ) {
				$p->set_attribute( 'sizes', substr( $sizes, 6 ) );
			}
		}
		$this->count( 'lcp_image' );
	}

	/**
	 * Real width/height for uploaded images that lack them.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function add_dimensions( $p ) {
		$width  = trim( (string) $p->get_attribute( 'width' ) );
		$height = trim( (string) $p->get_attribute( 'height' ) );
		if ( '' !== $width && '' !== $height ) {
			return;
		}
		$src = $p->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src || 0 === strpos( $src, 'data:' ) || preg_match( '/\.svg(\?|$)/i', $src ) ) {
			return;
		}
		$path = Fonts::local_path( $src );
		if ( null === $path ) {
			return;
		}
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size || $size[0] < 1 || $size[1] < 1 ) {
			return;
		}

		if ( '' !== $width && (int) $width > 0 ) {
			$p->set_attribute( 'height', (string) (int) round( (int) $width * $size[1] / $size[0] ) );
		} elseif ( '' !== $height && (int) $height > 0 ) {
			$p->set_attribute( 'width', (string) (int) round( (int) $height * $size[0] / $size[1] ) );
		} else {
			$p->set_attribute( 'width', (string) $size[0] );
			$p->set_attribute( 'height', (string) $size[1] );
		}
		$p->set_attribute( 'data-shd-dims', true );
		$this->needs_dims_css = true;
		$this->count( 'dimensions' );
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_iframe( $p ) {
		// YouTube embeds in WordPress' responsive embed wrapper: the facade fills exactly the iframe's box.
		if ( ! empty( $this->on['youtube_facade'] ) && $this->in_aspect_embed() ) {
			// Embeds sit in article text, rarely at the top: the thumbnail is always lazy-loaded.
			$facade = Youtube::iframe_facade_html( (string) $p->get_attribute( 'src' ), (string) $p->get_attribute( 'title' ), false, (string) $this->s['youtube_label'] );
			if ( '' !== $facade ) {
				$n                          = count( $this->iframe_facades );
				$this->iframe_facades[ $n ] = $facade;
				$p->set_attribute( 'data-shd-yt-iframe', (string) $n );
				return;
			}
		}
		if ( empty( $this->on['lazy_iframes'] ) || $this->in_first_screen() ) {
			return;
		}
		if ( null === $p->get_attribute( 'loading' ) ) {
			$p->set_attribute( 'loading', 'lazy' );
			$this->count( 'lazy_iframes' );
		}
	}

	/**
	 * Whether the current element sits directly in a responsive embed wrapper
	 * (figure.wp-has-aspect-ratio > div.wp-block-embed__wrapper).
	 *
	 * @return bool
	 */
	private function in_aspect_embed() {
		$n = count( $this->stack );
		return $n >= 2 && 'embed' === $this->stack[ $n - 1 ][1] && 'aspect' === $this->stack[ $n - 2 ][1];
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_link( $p ) {
		$rel  = strtolower( trim( (string) $p->get_attribute( 'rel' ) ) );
		$href = (string) $p->get_attribute( 'href' );

		if ( 'dns-prefetch' === $rel && ! empty( $this->on['emoji'] ) && false !== strpos( $href, 's.w.org' ) ) {
			$this->remove_link( $p );
			return;
		}
		if ( 'stylesheet' !== $rel ) {
			return;
		}

		$handle = preg_replace( '/-css$/', '', (string) $p->get_attribute( 'id' ) );

		if ( ! empty( $this->on['animations'] ) && 'all' === $this->s['animation_mode'] && 0 === strpos( $handle, 'e-animation-' ) ) {
			$this->remove_link( $p );
			return;
		}
		if ( ! empty( $this->on['addon_assets'] ) && '' !== $handle && $this->is_unused_addon( $handle ) ) {
			$this->remove_link( $p );
			return;
		}

		if ( null !== $this->css && '' !== $href && preg_match( '#/uploads/elementor/css/[^/?]+\.css#i', $href ) ) {
			$url = $this->css->rewritten_url( $href, $this->font_sig );
			if ( $url ) {
				$p->set_attribute( 'href', $url );
				$this->count( 'css_rewritten' );
			}
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_style( $p ) {
		if ( null === $this->css || ! $this->can_modify_text ) {
			return;
		}
		$css = $p->get_modifiable_text();
		if ( '' === $css || ! $this->css->wants( $css ) ) {
			return;
		}
		$new = $this->css->rewrite( $css );
		if ( $new !== $css ) {
			$p->set_modifiable_text( $new );
			$this->count( 'inline_css_rewritten' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Scripts                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_script( $p ) {
		$type = strtolower( trim( (string) $p->get_attribute( 'type' ) ) );
		$id   = (string) $p->get_attribute( 'id' );
		$src  = $p->get_attribute( 'src' );
		$src  = is_string( $src ) ? trim( $src ) : '';
		$code = ( '' === $src && $this->can_modify_text ) ? $p->get_modifiable_text() : '';

		if ( ! empty( $this->on['emoji'] ) && ( 'wp-emoji-settings' === $id || ( '' === $src && ( false !== strpos( $code, 'wp-emoji-settings' ) || false !== strpos( $code, '_wpemojiSettings' ) ) ) ) ) {
			$this->remove_script( $p );
			return;
		}

		if ( ! in_array( $type, self::CLASSIC_TYPES, true ) || null !== $p->get_attribute( 'nomodule' ) ) {
			return;
		}

		list( $handle, $part ) = self::handle_from_id( $id );

		if ( '' !== $handle && ! empty( $this->on['addon_assets'] ) && $this->is_unused_addon( $handle ) ) {
			$this->remove_script( $p );
			return;
		}
		if ( 'jquery-migrate' === $handle && ! empty( $this->on['jquery_migrate'] ) ) {
			$this->remove_script( $p );
			return;
		}

		if ( '' !== $src ) {
			$this->external_script( $p, $src, $handle );
		} elseif ( $this->can_modify_text ) {
			$this->inline_script( $p, $code, $handle, $part );
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p      Processor.
	 * @param string                 $src    Script URL.
	 * @param string                 $handle WordPress handle or ''.
	 */
	private function external_script( $p, $src, $handle ) {
		$key = self::normalize_url( $src );
		if ( ! empty( $this->on['dedupe_scripts'] ) && isset( $this->seen_src[ $key ] ) ) {
			$this->remove_script( $p );
			$this->count( 'duplicates' );
			return;
		}
		$this->seen_src[ $key ] = true;

		if ( ! empty( $this->on['recaptcha_on_demand'] ) && $this->recaptcha_ok && preg_match( '#/recaptcha/(?:api|enterprise)\.js#i', $src ) ) {
			$this->hold_script( $p, $src, 'shd/recaptcha' );
			$this->held_recaptcha = true;
			$this->count( 'recaptcha' );
			return;
		}

		if ( self::matches( $src, (array) $this->s['consent_scripts'] ) ) {
			if ( ! empty( $this->on['consent_async'] ) && null === $p->get_attribute( 'async' ) ) {
				$p->remove_attribute( 'defer' );
				$p->set_attribute( 'async', true );
				$this->count( 'consent_async' );
			}
			$this->add_preconnect( $src );
			return;
		}

		if ( $this->will_delay( $src ) ) {
			$this->hold_script( $p, $src, 'shd/delayed' );
			$this->any_delayed = true;
			$this->count( 'delayed' );
			return;
		}

		if ( ! empty( $this->on['defer_scripts'] ) && $this->can_defer_inline
			&& null === $p->get_attribute( 'async' ) && null === $p->get_attribute( 'defer' )
			&& ! self::matches( $src, (array) $this->s['delay_exclusions'] ) ) {
			$p->set_attribute( 'defer', true );
			$this->any_deferred = true;
			$this->count( 'deferred' );
			return;
		}

		if ( null === $p->get_attribute( 'async' ) && null === $p->get_attribute( 'defer' ) ) {
			$this->add_preconnect( $src );
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p      Processor.
	 * @param string                 $code   Script text.
	 * @param string                 $handle Handle or ''.
	 * @param string                 $part   main|extra|before|after|translations.
	 */
	private function inline_script( $p, $code, $handle, $part ) {
		if ( '' === trim( $code ) ) {
			return;
		}

		if ( ! empty( $this->on['cursor'] ) && false !== strpos( $code, 'cursor-dot' ) && false !== strpos( $code, 'requestAnimationFrame' ) && false === strpos( $code, 'shd-cursor' ) ) {
			$p->set_modifiable_text( Assets::cursor_script( $code ) );
			$this->count( 'cursor' );
			return;
		}

		$data_only = self::is_data_only( $code );

		if ( '' !== $handle && 'extra' !== $part && ! $data_only && isset( $this->handle_src[ $handle ] ) && $this->will_delay( $this->handle_src[ $handle ] ) ) {
			$this->delay_inline( $p );
			return;
		}

		if ( $data_only || $this->keep_inline( $code ) ) {
			return;
		}

		if ( ! empty( $this->on['delay_all_scripts'] ) && $this->any_delayed ) {
			$this->delay_inline( $p );
			return;
		}

		if ( ! empty( $this->on['defer_scripts'] ) && $this->can_defer_inline && $this->any_deferred ) {
			$p->set_attribute( 'src', 'data:text/javascript;base64,' . base64_encode( $code ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- keeps inline scripts in defer order.
			$p->set_attribute( 'defer', true );
			$p->set_modifiable_text( '' );
			$this->count( 'deferred_inline' );
		}
	}

	/**
	 * @param string $src Script URL.
	 * @return bool
	 */
	private function will_delay( $src ) {
		if ( self::matches( $src, (array) $this->s['delay_exclusions'] ) || self::matches( $src, (array) $this->s['consent_scripts'] ) ) {
			return false;
		}
		if ( preg_match( '#/recaptcha/(?:api|enterprise)\.js#i', $src ) && ! empty( $this->on['recaptcha_on_demand'] ) && $this->recaptcha_ok ) {
			return false;
		}
		if ( ! empty( $this->on['delay_all_scripts'] ) ) {
			return true;
		}
		return ! empty( $this->on['delay_tracking'] ) && self::matches( $src, (array) $this->s['tracking_patterns'] );
	}

	/**
	 * @param string $code Inline script.
	 * @return bool
	 */
	private function keep_inline( $code ) {
		foreach ( (array) $this->s['keep_inline'] as $needle ) {
			if ( '' !== $needle && false !== strpos( $code, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make an external script inert; a loader brings it back later.
	 *
	 * @param \WP_HTML_Tag_Processor $p    Processor.
	 * @param string                 $src  URL.
	 * @param string                 $type Placeholder type.
	 */
	private function hold_script( $p, $src, $type ) {
		$original = $p->get_attribute( 'type' );
		if ( is_string( $original ) && '' !== $original ) {
			$p->set_attribute( 'data-shd-type', $original );
		}
		// An async script never blocked the ones after it; the loader keeps it that way.
		if ( 'shd/delayed' === $type && null !== $p->get_attribute( 'async' ) ) {
			$p->set_attribute( 'data-shd-async', true );
		}
		$p->set_attribute( 'type', $type );
		$p->set_attribute( 'data-shd-src', $src );
		$p->remove_attribute( 'src' );
		$p->remove_attribute( 'async' );
		$p->remove_attribute( 'defer' );
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function delay_inline( $p ) {
		$original = $p->get_attribute( 'type' );
		if ( is_string( $original ) && '' !== $original ) {
			$p->set_attribute( 'data-shd-type', $original );
		}
		$p->set_attribute( 'type', 'shd/delayed' );
		$this->count( 'delayed_inline' );
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function remove_script( $p ) {
		$p->set_attribute( self::REMOVE_MARK, true );
		$p->set_attribute( 'type', 'shd/removed' );
		$p->remove_attribute( 'src' );
		if ( $this->can_modify_text ) {
			$p->set_modifiable_text( '' );
		}
		$this->count( 'removed_scripts' );
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function remove_link( $p ) {
		$p->set_attribute( self::REMOVE_MARK, true );
		$p->set_attribute( 'rel', 'shd-removed' );
		$p->remove_attribute( 'href' );
		$this->count( 'removed_styles' );
	}

	/**
	 * @param string $handle Handle.
	 * @return bool
	 */
	private function is_unused_addon( $handle ) {
		if ( ! $this->uses_qi && 0 === strpos( $handle, 'qi-addons-for-elementor' ) ) {
			return true;
		}
		if ( ! $this->uses_lastudio && ( 0 === strpos( $handle, 'lastudio-kit' ) || 0 === strpos( $handle, 'lakit-' ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $src Script URL.
	 */
	private function add_preconnect( $src ) {
		if ( empty( $this->on['preconnect'] ) ) {
			return;
		}
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $home ) {
			return;
		}
		$hosts = array_merge( array( $host ), isset( self::RELATED_ORIGINS[ $host ] ) ? self::RELATED_ORIGINS[ $host ] : array() );
		foreach ( $hosts as $origin ) {
			if ( count( $this->preconnect ) < 3 ) {
				$this->preconnect[ $origin ] = true;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Finalize                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Insertions and removals that need the finished pass.
	 *
	 * @param string $html HTML after the pass.
	 * @return string
	 */
	private function finalize( $html ) {
		$html = $this->insert_facades( $html );
		$html = $this->insert_iframe_facades( $html );

		$html = preg_replace( '/<script\b[^>]*\b' . self::REMOVE_MARK . '\b[^>]*>\s*<\/script>[ \t]*\n?/i', '', $html );
		$html = preg_replace( '/<link\b[^>]*\b' . self::REMOVE_MARK . '\b[^>]*>[ \t]*\n?/i', '', $html );

		if ( ! empty( $this->on['widget_css_async'] ) ) {
			$html = $this->async_widget_css( $html );
		}

		$html = $this->insert_head( $html );
		$html = $this->insert_body_end( $html );

		return $html;
	}

	/**
	 * Replace marked YouTube iframes with their facades.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function insert_iframe_facades( $html ) {
		if ( empty( $this->iframe_facades ) ) {
			return $html;
		}
		return preg_replace_callback(
			'/<iframe\b[^>]*\bdata-shd-yt-iframe="(\d+)"[^>]*>\s*<\/iframe>/i',
			function ( $m ) {
				$n = (int) $m[1];
				if ( ! isset( $this->iframe_facades[ $n ] ) ) {
					return $m[0];
				}
				$this->count( 'youtube_facades' );
				return $this->iframe_facades[ $n ];
			},
			$html
		);
	}

	/**
	 * @param string $html HTML.
	 * @return string
	 */
	private function insert_facades( $html ) {
		if ( empty( $this->video_count ) ) {
			return $html;
		}
		$placed = array();
		$html   = preg_replace_callback(
			'/(<div\b[^>]*\bdata-shd-yt-slot="(\d+)"[^>]*>)\s*(<\/div>)/i',
			function ( $m ) use ( &$placed ) {
				$n = (int) $m[2];
				if ( ! isset( $this->facades[ $n ] ) ) {
					return $m[0];
				}
				$placed[ $n ] = true;
				return $m[1] . $this->facades[ $n ] . $m[3];
			},
			$html
		);

		// Any widget whose facade could not be placed keeps Elementor's own player.
		for ( $n = 0; $n < $this->video_count; $n++ ) {
			if ( isset( $placed[ $n ] ) ) {
				$this->count( 'youtube_facades' );
				continue;
			}
			$html = preg_replace_callback(
				'/<div\b[^>]*\bdata-shd-yt-widget="' . $n . '"[^>]*>/i',
				static function ( $m ) {
					return str_replace( 'video.shd-facade', 'video.default', $m[0] );
				},
				$html,
				1
			);
		}
		return $html;
	}

	/**
	 * Load widget CSS for widgets that only appear below the first screen without blocking.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function async_widget_css( $html ) {
		$head_end = stripos( $html, '</head>' );
		if ( false === $head_end ) {
			return $html;
		}
		$head = substr( $html, 0, $head_end );
		$head = preg_replace_callback(
			'/<link\b[^>]*\bid=["\']widget-([a-z0-9-]+)-css["\'][^>]*>/i',
			function ( $m ) {
				$name = strtolower( $m[1] );
				if ( isset( $this->first_screen_widgets[ $name ] ) || ! isset( $this->widget_types[ $name ] ) ) {
					return $m[0];
				}
				$t = new \WP_HTML_Tag_Processor( $m[0] );
				if ( ! $t->next_tag( 'LINK' ) || 'stylesheet' !== strtolower( (string) $t->get_attribute( 'rel' ) ) ) {
					return $m[0];
				}
				$media = strtolower( trim( (string) $t->get_attribute( 'media' ) ) );
				if ( '' !== $media && 'all' !== $media ) {
					return $m[0];
				}
				$t->set_attribute( 'media', 'print' );
				$t->set_attribute( 'onload', "this.media='all'" );
				$this->count( 'async_css' );
				return $t->get_updated_html();
			},
			$head
		);
		return $head . substr( $html, $head_end );
	}

	/**
	 * Early head tags: preconnects, font preloads, our CSS and the delayed-script loader.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function insert_head( $html ) {
		$tags = '';

		foreach ( array_keys( $this->preconnect ) as $host ) {
			$tags .= '<link rel="preconnect" href="https://' . esc_attr( $host ) . '">' . "\n";
		}

		if ( ! empty( $this->on['fonts'] ) ) {
			foreach ( Fonts::preload_urls() as $url ) {
				$tags .= '<link rel="preload" href="' . esc_url( $url ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
			}
		}

		$css = '';
		if ( ! empty( $this->on['fonts'] ) ) {
			$css .= Fonts::fallback_css();
		}
		if ( $this->needs_dims_css ) {
			$css .= 'img[data-shd-dims]{height:auto}';
		}
		if ( ! empty( $this->facades ) || ! empty( $this->iframe_facades ) ) {
			$css .= Assets::youtube_css();
		}
		if ( ! empty( $this->on['toc_prefill'] ) && $this->has_toc ) {
			$css .= Assets::toc_css( Toc::mobile_max_width() );
		}
		if ( $this->lazy_bg > 0 ) {
			$css .= Assets::lazy_bg_css();
		}
		if ( '' !== $css ) {
			$tags .= '<style id="shd-perf-css">' . $css . "</style>\n";
		}

		if ( $this->any_delayed ) {
			$config = array(
				'timeout'  => max( 0, (int) $this->s['delay_timeout'] ) * 1000,
				'replay'   => ! empty( $this->on['delay_all_scripts'] ),
				'prefetch' => true,
			);
			$tags  .= '<script id="shd-perf-delay">window.shdPerfDelay=' . wp_json_encode( $config ) . ';' . Assets::script( 'delay' ) . "</script>\n";
		}

		if ( '' === $tags ) {
			return $html;
		}

		if ( preg_match( '/<meta\s[^>]*charset=[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) && $m[0][1] < stripos( $html, '</head>' ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
		} elseif ( preg_match( '/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
		} else {
			return $html;
		}
		return substr( $html, 0, $at ) . "\n" . $tags . substr( $html, $at );
	}

	/**
	 * Small loaders that act after the content: YouTube facades, reCAPTCHA, real-user metrics.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function insert_body_end( $html ) {
		$scripts = '';
		if ( ! empty( $this->facades ) || ! empty( $this->iframe_facades ) ) {
			$scripts .= '<script id="shd-perf-youtube">' . Assets::script( 'youtube' ) . "</script>\n";
		}
		if ( $this->held_recaptcha ) {
			$scripts .= '<script id="shd-perf-recaptcha">' . Assets::script( 'recaptcha' ) . "</script>\n";
		}
		if ( $this->lazy_bg > 0 ) {
			$this->count( 'lazy_backgrounds', $this->lazy_bg );
			$scripts .= '<script id="shd-perf-lazy-bg">' . Assets::script( 'lazy-bg' ) . "</script>\n";
		}
		if ( ! empty( $this->on['rum'] ) ) {
			$scripts .= Rum::loader_tag();
		}
		$stats    = $this->stats;
		$summary  = array();
		ksort( $stats );
		foreach ( $stats as $key => $value ) {
			$summary[] = $key . '=' . $value;
		}
		$scripts .= '<!-- SHD Performance ' . SHD_PERF_VERSION . ' (' . esc_html( Request::view() ) . ') ' . esc_html( implode( ' ', $summary ) ) . " -->\n";

		$at = strripos( $html, '</body>' );
		if ( false === $at ) {
			return $html . $scripts;
		}
		return substr( $html, 0, $at ) . $scripts . substr( $html, $at );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Split a WordPress script id ("handle-js", "handle-js-extra", ...) into handle and part.
	 *
	 * @param string $id Element id.
	 * @return array{0:string,1:string}
	 */
	public static function handle_from_id( $id ) {
		if ( preg_match( '/^(.+)-js(?:-(extra|before|after|translations))?$/', $id, $m ) ) {
			return array( $m[1], isset( $m[2] ) && '' !== $m[2] ? $m[2] : 'main' );
		}
		return array( '', '' );
	}

	/**
	 * Whether an inline script only assigns data (localized config), so it may run early.
	 *
	 * @param string $code Script.
	 * @return bool
	 */
	public static function is_data_only( $code ) {
		$code = trim( $code );
		$code = preg_replace( '#\s*//\#\s*source(?:Mapping)?URL=\S*\s*$#', '', $code );
		$code = preg_replace( '#^/\*\s*<!\[CDATA\[\s*\*/|/\*\s*\]\]>\s*\*/$#', '', trim( $code ) );
		$code = trim( $code );
		if ( '' === $code || strlen( $code ) > 30000 || ! preg_match( '/^(?:var|let|const|window)\b/', $code ) ) {
			return false;
		}
		$result = @preg_match( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- backtracking limit means "not data".
			'/^(?:\s*(?:(?:var|let|const)\s+[A-Za-z_$][\w$]*|window(?:\.[A-Za-z_$][\w$]*)+)\s*=\s*(?:\{.*?\}|\[.*?\]|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|-?\d+(?:\.\d+)?|true|false|null)\s*;?)+\s*$/s',
			$code
		);
		if ( 1 !== $result ) {
			return false;
		}
		// A data assignment never calls or declares functions.
		return ! preg_match( '/\bfunction\b|=>|\)\s*\(|\b(?:new|document|addEventListener)\b/', preg_replace( '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '""', $code ) );
	}

	/**
	 * @param string   $url      URL.
	 * @param string[] $patterns Substrings.
	 * @return bool
	 */
	public static function matches( $url, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern && false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a script URL for duplicate detection ("/a/../b" => "/b").
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = html_entity_decode( $url, ENT_QUOTES );
		do {
			$before = $url;
			$url    = preg_replace( '#/(?!\.\./)[^/?]+/\.\./#', '/', $url );
		} while ( $before !== $url );
		return strtolower( preg_replace( '#^https?:#i', '', $url ) );
	}

	/**
	 * @param string $key Counter.
	 */
	private function count( $key, $by = 1 ) {
		$this->stats[ $key ] = ( isset( $this->stats[ $key ] ) ? $this->stats[ $key ] : 0 ) + $by;
	}
}
