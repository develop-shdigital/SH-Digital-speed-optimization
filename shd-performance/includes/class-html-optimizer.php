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

	/** Attribute that marks an IcoFont <i> to draw as SVG in finalize(). */
	const ICON_MARK = 'data-shd-icon';

	/**
	 * Characters of main-content text after which an element no longer counts as first
	 * screen, even when the page is one large section (the hero texts here have 70 to 290
	 * characters before their image; the portrait on the case study, 2.5 phone screens
	 * down, has 955).
	 */
	const FIRST_SCREEN_TEXT = 700;

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
	private $handle_src     = array();
	private $uses_qi        = false;
	private $uses_lastudio  = false;
	private $uses_dlicon    = false;
	private $recaptcha_ok   = false;
	private $has_toc        = false;
	private $toc_collapsed  = 0;

	// Structure.
	private $stack          = array();
	private $header_depth   = 0;
	private $in_footer      = false;
	private $main_seen      = false;
	private $top_index      = 0;

	// Per device ('m' phones, 'd' desktops): visible top-level sections so far, and the
	// position of the current one among them (0 = hidden on that device).
	private $top_seen       = array(
		'm' => 0,
		'd' => 0,
	);
	private $top_pos        = array(
		'm' => 0,
		'd' => 0,
	);

	// LCP image candidates: bookmark name => array( 'm' => score, 'd' => score, 'hidden' => array( device => bool ) ).
	private $lcp            = array();
	private $lcp_counter    = 0;
	private $other_high     = false;

	// Sections with a background image in the first screen (resolved in finalize()).
	private $bg_candidates  = array();

	/** @var string[] Preload tags for the main images, placed early in <head>. */
	private $image_preloads = array();

	// YouTube facades in the first screen: number => array( 'm' => score, 'd' => score ); winners get high priority.
	private $facade_lcp     = array();
	private $facade_high    = array();

	// Iframes from other sites that load when they come near the screen.
	private $lazy_frames    = 0;

	// IcoFont icons marked to be drawn as SVG.
	private $icons          = 0;

	// Accessibility: the id the "Skip to content" link points to when no element has it,
	// the outline so far (array( tag level, level given )), and whether an <a> to the
	// homepage is open (its image is the logo).
	private $skip_target    = '';
	private $needs_main     = false;
	private $headings       = array();
	private $in_home_link   = false;

	// Characters of main-content text seen so far.
	private $text_now       = 0;

	// Scripts.
	private $any_delayed    = false;
	private $any_deferred   = false;
	private $seen_src       = array();
	private $preconnect     = array();
	private $held_recaptcha = false;
	private $has_body       = false;

	/** @var array<string,bool[]> Per link target, in page order: whether each such link has no text (see unnamed_links()). */
	private $unnamed_links  = array();

	/** @var array<string,int> Links seen so far, per target. */
	private $links_seen     = array();

	/** @var array<int,array{0:string,1:array<string,string>}> Cookie banner scripts started from the top of <body>. */
	private $consent_moved  = array();

	// Video facades.
	private $pending_video  = null;
	private $facades        = array();
	private $video_count    = 0;
	private $iframe_facades = array();

	private $needs_dims_css = false;

	// Lazy background images: marked elements and blog/loop cards seen so far.
	private $lazy_bg        = 0;
	private $loop_items     = 0;

	// Autoplaying videos below the first screen, started when they come near.
	private $lazy_videos    = 0;

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
			$collapse = 'collapsed' === $this->s['toc_phones'] ? Toc::mobile_max_width() : 0;
			list( $html, $filled, $this->toc_collapsed ) = Toc::prefill( $html, $collapse );
			if ( $filled ) {
				$this->count( 'toc_prefilled', $filled );
			}
			if ( $this->toc_collapsed ) {
				$this->count( 'toc_collapsed', $this->toc_collapsed );
			}
		}

		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_token() ) {
			$type = $p->get_token_type();
			if ( '#text' === $type ) {
				// How far down the main content we are, for pages that are one large section.
				if ( $this->main_seen && $this->inside( 'main' ) ) {
					$this->text_now += strlen( trim( $p->get_modifiable_text() ) );
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}
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
				$name = strtolower( $name );
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

		$this->has_body     = (bool) preg_match( '/<body\b/i', $html );
		$this->unnamed_links = self::unnamed_links( $html );
		$this->needs_main = ! preg_match( '/<main\b|\srole=["\']main["\']/i', $html );
		// "Skip to content" link whose target does not exist (Hello Elementor points it at #content).
		if ( preg_match( '/<a\b(?=[^>]*\bclass=["\'][^"\']*\bskip-link\b)[^>]*\bhref=["\']#([A-Za-z][\w-]*)["\']/i', $html, $skip )
			&& ! preg_match( '/\sid=["\']' . preg_quote( $skip[1], '/' ) . '["\']/', $html ) ) {
			$this->skip_target = $skip[1];
		}
		// LA-Studio Kit adds its "dlicon" icon font stylesheet to every page with its widgets, but
		// draws its icons as inline SVG; the font is only needed for class="dlicon …" elements.
		$this->uses_dlicon = (bool) preg_match( '/class=["\'][^"\']*\bdlicon\b|(?:["\']|&quot;)dlicon\s/i', $html );
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
			case 'A':
				$this->in_home_link = self::is_home_link( $p->get_attribute( 'href' ) );
				$this->on_anchor( $p );
				break;
			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$this->on_heading( $p, (int) substr( $tag, 1 ) );
				break;
			case 'IMG':
				$this->on_img( $p );
				break;
			case 'IFRAME':
				$this->on_iframe( $p );
				break;
			case 'VIDEO':
				$this->on_video( $p );
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
			case 'I':
				$this->on_icon( $p );
				break;
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p   Processor.
	 * @param string                 $tag Tag.
	 */
	private function push( $p, $tag ) {
		$role   = '';
		$type   = $p->get_attribute( 'data-elementor-type' );
		$parent = empty( $this->stack ) ? null : $this->stack[ count( $this->stack ) - 1 ];

		// Hidden on phones / on desktops by Elementor's responsive visibility (itself or an ancestor).
		$hidden = array();
		foreach ( self::hide_classes() as $device => $classes ) {
			$hidden[ $device ] = null !== $parent && $parent[3][ $device ];
			foreach ( $classes as $class ) {
				if ( ! $hidden[ $device ] && $p->has_class( $class ) ) {
					$hidden[ $device ] = true;
				}
			}
		}
		// Post of the loop grid card this element belongs to (its background may be that post's image).
		$loop = null !== $parent ? $parent[4] : 0;
		if ( 'loop-item' === $type && preg_match( '/\be-loop-item-(\d+)\b/', (string) $p->get_attribute( 'class' ), $post ) ) {
			$loop = (int) $post[1];
		}

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
				$this->skip_target_here( $p );
			} else {
				$role = 'nested';
			}
		} elseif ( 'HEADER' === $tag && 0 === $this->header_depth && ! $this->main_seen && ! $this->inside( 'header' ) ) {
			$role = 'header';
		} elseif ( ( $p->has_class( 'e-parent' ) || $p->has_class( 'elementor-top-section' ) ) && 'main' === $this->nearest_document() ) {
			$role = 'top';
			++$this->top_index;
			// A section shown only on phones (or only on desktops) is the first screen there.
			foreach ( array_keys( $this->top_pos ) as $device ) {
				$this->top_pos[ $device ] = $hidden[ $device ] ? 0 : ++$this->top_seen[ $device ];
			}
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

		// Already marked (a page optimized before): its backgrounds wait as well.
		$lazy          = $lazy || null !== $p->get_attribute( 'data-shd-lazy-bg' );
		$this->stack[] = array( $tag, $role, $lazy, $hidden, $loop );

		if ( ! empty( $this->on['lcp_image'] ) ) {
			$this->background_candidate( $p );
		}
	}

	/**
	 * Elementor's classes that hide an element on phones ('m') and on desktops ('d').
	 * Lighthouse tests desktops 1350 px wide: with Elementor's "laptop" breakpoint
	 * active, that width is a laptop.
	 *
	 * @return array<string,string[]>
	 */
	private static function hide_classes() {
		static $classes = null;
		if ( null !== $classes ) {
			return $classes;
		}
		$classes = array(
			'm' => array( 'elementor-hidden-mobile' ),
			'd' => array( 'elementor-hidden-desktop' ),
		);
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) && method_exists( \Elementor\Plugin::$instance->breakpoints, 'get_active_breakpoints' ) ) {
			try {
				$active = (array) \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
				if ( isset( $active['laptop'] ) && is_object( $active['laptop'] ) && 1350 <= (int) $active['laptop']->get_value() ) {
					$classes['d'] = array( 'elementor-hidden-laptop' );
				} elseif ( isset( $active['widescreen'] ) && is_object( $active['widescreen'] ) && 1350 >= (int) $active['widescreen']->get_value() ) {
					$classes['d'] = array( 'elementor-hidden-widescreen' );
				}
			} catch ( \Throwable $e ) {
				// Keep the defaults.
			}
		}
		return $classes;
	}

	/**
	 * @param string $device 'm' or 'd'.
	 * @return bool Whether the current element is hidden on that device.
	 */
	private function hidden( $device ) {
		return ! empty( $this->stack ) && ! empty( $this->stack[ count( $this->stack ) - 1 ][3][ $device ] );
	}

	/**
	 * Remember a section with a background image in the first screen; its image is found
	 * in the CSS and preloaded in finalize().
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function background_candidate( $p ) {
		if ( count( $this->bg_candidates ) >= 8 || $this->header_depth > 0 || $this->text_now > self::FIRST_SCREEN_TEXT || ! $this->inside( 'main' ) || $this->inside_lazy_bg() ) {
			return;
		}
		$devices = array();
		foreach ( array_keys( $this->top_pos ) as $device ) {
			if ( $this->in_first_screen_on( $device ) ) {
				$devices[ $device ] = true;
			}
		}
		if ( empty( $devices ) ) {
			return;
		}
		$url   = null;
		$style = $p->get_attribute( 'style' );
		if ( is_string( $style ) && preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', html_entity_decode( $style, ENT_QUOTES ), $m ) ) {
			// Inline image, e.g. the image overlay of Elementor's video widget.
			$url = trim( $m[2] );
			if ( 0 === stripos( $url, 'data:' ) ) {
				return;
			}
		} else {
			$settings = $p->get_attribute( 'data-settings' );
			$id       = $p->get_attribute( 'data-id' );
			if ( ! is_string( $settings ) || ! is_string( $id ) || '' === $id || ! preg_match( '/"background_background"\s*:\s*"classic"/', $settings ) ) {
				return;
			}
		}
		$this->bg_candidates[] = array(
			'id'      => (string) $p->get_attribute( 'data-id' ),
			'loop'    => (int) $this->stack[ count( $this->stack ) - 1 ][4],
			'url'     => $url,
			'devices' => $devices,
		);
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
			return $this->in_first_screen() ? '' : 'view';
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
		if ( 'A' === $tag ) {
			$this->in_home_link = false;
			return;
		}
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
		if ( $this->top_index < 1 || ! $this->inside( 'main' ) ) {
			return false;
		}
		// Also a section that comes later in the page but is among the first ones shown on
		// phones or on desktops (the others being hidden there).
		if ( $this->top_index <= $limit ) {
			return true;
		}
		foreach ( $this->top_pos as $position ) {
			if ( $position >= 1 && $position <= $limit ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current element is visible on a device and in its first screen.
	 *
	 * @param string   $device   'm' or 'd'.
	 * @param int|null $sections Number of top-level sections that count (default: setting).
	 * @return bool
	 */
	private function in_first_screen_on( $device, $sections = null ) {
		if ( $this->hidden( $device ) ) {
			return false;
		}
		if ( $this->header_depth > 0 ) {
			return true;
		}
		if ( $this->in_footer || $this->inside( 'popup' ) || ! $this->inside( 'main' ) ) {
			return false;
		}
		$limit = null === $sections ? max( 1, (int) $this->s['first_screen_sections'] ) : $sections;
		return $this->top_pos[ $device ] >= 1 && $this->top_pos[ $device ] <= $limit;
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
		$this->list_roles( $p );
		$widget = $p->get_attribute( 'data-widget_type' );
		if ( is_string( $widget ) && '' !== $widget ) {
			$name = strtolower( strtok( $widget, '.' ) );
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
			// Built in insert_facades(), once it is known whether its thumbnail is the main image.
			$this->facades[ $video['n'] ] = $video;
			$this->pending_video          = null;
		}
	}

	/**
	 * Elementor Pro marks its loop grid and loop carousel as role="list" but not their
	 * items. Grid cards become list items; the carousel, whose slides are groups
	 * ("slide 1 of 10"), loses the list role.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function list_roles( $p ) {
		if ( empty( $this->on['a11y_names'] ) ) {
			return;
		}
		$n = count( $this->stack );
		if ( $p->has_class( 'elementor-loop-container' ) && 'list' === $p->get_attribute( 'role' ) ) {
			if ( $p->has_class( 'swiper' ) ) {
				$p->remove_attribute( 'role' );
				$this->count( 'list_roles' );
			} elseif ( $n > 0 ) {
				$this->stack[ $n - 1 ][1] = 'list';
			}
			return;
		}
		if ( $n >= 2 && 'list' === $this->stack[ $n - 2 ][1] && 'loop-item' === $p->get_attribute( 'data-elementor-type' ) && null === $p->get_attribute( 'role' ) ) {
			$p->set_attribute( 'role', 'listitem' );
			$this->count( 'list_roles' );
		}
	}

	/**
	 * For every link, in page order and per target: whether it contains no text at all
	 * (only an icon, or an image without alt text). Content the tag processor does not
	 * visit (comments, scripts, styles and other raw text) is left out, so the order
	 * matches the links on_anchor() sees.
	 *
	 * @param string $html HTML.
	 * @return array<string,bool[]>
	 */
	private static function unnamed_links( $html ) {
		$html  = (string) preg_replace( '#<!--.*?-->|<(script|style|textarea|title|iframe|xmp|noembed|noframes)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$found = array();
		if ( ! preg_match_all( '#<a\b([^>]*)>#i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}
		foreach ( $tags as $tag ) {
			if ( ! preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag[1][0], $href ) ) {
				continue;
			}
			$target = html_entity_decode( (string) end( $href ), ENT_QUOTES | ENT_HTML5 );
			$from   = $tag[0][1] + strlen( $tag[0][0] );
			$inner  = substr( $html, $from, 4000 );
			if ( preg_match( '#</a\s*>|<a\b#i', $inner, $close, PREG_OFFSET_CAPTURE ) ) {
				$inner = substr( $inner, 0, $close[0][1] );
			}
			// Text (also screen-reader text and SVG titles), an image with alt text or a labelled element names the link.
			$named = preg_match( '/\b(?:aria-label|aria-labelledby|title)\s*=\s*(["\'])\s*[^"\'\s]/i', $tag[1][0] )
				|| '' !== trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES ), " \t\n\r\0\x0B\xC2\xA0" )
				|| preg_match( '/<img\b[^>]*\balt\s*=\s*(["\'])\s*[^"\'\s]|\baria-label\s*=\s*(["\'])\s*[^"\'\s]/i', $inner );
			$found[ $target ][] = ! $named;
		}
		return $found;
	}

	/**
	 * Links that contain only an icon (Elementor icon widget, icon box) or only an image
	 * without alt text give screen readers and AI agents no name. Name them after what
	 * they do. Links with text are never renamed.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_anchor( $p ) {
		$href = $p->get_attribute( 'href' );
		if ( empty( $this->on['a11y_names'] ) || ! is_string( $href ) ) {
			return;
		}
		$n                         = isset( $this->links_seen[ $href ] ) ? $this->links_seen[ $href ] + 1 : 0;
		$this->links_seen[ $href ] = $n;
		$unnamed                   = isset( $this->unnamed_links[ $href ][ $n ] ) ? $this->unnamed_links[ $href ][ $n ] : null;
		// Elementor icon links never have text; elsewhere only links found to have none.
		if ( ! ( true === $unnamed || ( null === $unnamed && $p->has_class( 'elementor-icon' ) ) ) || $p->has_class( 'elementor-social-icon' ) ) {
			return;
		}
		foreach ( array( 'aria-label', 'aria-labelledby', 'title' ) as $attr ) {
			$value = $p->get_attribute( $attr );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return;
			}
		}
		$name = self::link_name( $href );
		if ( '' !== $name ) {
			$p->set_attribute( 'aria-label', $name );
			$this->count( 'link_names' );
		}
	}

	/**
	 * The "Skip to content" link points to an id no element has: the main content (the
	 * page's Elementor document) gets it, so keyboard and screen reader users land there.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at the main content element.
	 */
	private function skip_target_here( $p ) {
		if ( empty( $this->on['a11y_names'] ) ) {
			return;
		}
		// No <main> on the page (Elementor's full-width template): the main content is marked as
		// the main landmark, which screen readers jump to ("Document does not have a main landmark").
		if ( $this->needs_main && null === $p->get_attribute( 'role' ) ) {
			$p->set_attribute( 'role', 'main' );
			$this->needs_main = false;
			$this->count( 'main_landmark' );
		}
		if ( '' === $this->skip_target || null !== $p->get_attribute( 'id' ) ) {
			return;
		}
		$p->set_attribute( 'id', $this->skip_target );
		$this->skip_target = '';
		$this->count( 'skip_target' );
	}

	/**
	 * An iframe without a title is announced as an unnamed frame ("Frames must have a title").
	 * It gets one from what it shows: the kind of embed, or the last part of its address.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function frame_title( $p ) {
		$title = $p->get_attribute( 'title' );
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return;
		}
		$src = $p->get_attribute( 'src' );
		$src = is_string( $src ) && '' !== trim( $src ) ? $src : $p->get_attribute( 'data-src' );
		$name = is_string( $src ) ? self::frame_name( $src ) : '';
		if ( '' !== $name ) {
			$p->set_attribute( 'title', $name );
			$this->count( 'frame_titles' );
		}
	}

	/**
	 * @param string $src Iframe address.
	 * @return string A title in the site's language, or ''.
	 */
	public static function frame_name( $src ) {
		$de   = 0 === strpos( get_locale(), 'de' );
		$src  = html_entity_decode( trim( $src ), ENT_QUOTES );
		$host = strtolower( (string) wp_parse_url( ( 0 === strpos( $src, '//' ) ? 'https:' : '' ) . $src, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		if ( '' === $host && '' === $path ) {
			return '';
		}
		if ( preg_match( '/(?:^|\.)(?:youtube(?:-nocookie)?\.com|youtu\.be)$/', $host ) ) {
			return $de ? 'YouTube-Video' : 'YouTube video';
		}
		if ( preg_match( '/(?:^|\.)vimeo\.com$/', $host ) ) {
			return $de ? 'Vimeo-Video' : 'Vimeo video';
		}
		if ( preg_match( '/(?:^|\.)google\.[a-z.]+$/', $host ) && false !== strpos( $path, '/maps' ) ) {
			return $de ? 'Karte (Google Maps)' : 'Map (Google Maps)';
		}
		if ( preg_match( '/(?:^|\.)calendly\.com$/', $host ) ) {
			return $de ? 'Terminbuchung (Calendly)' : 'Booking (Calendly)';
		}
		$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$last  = empty( $parts ) ? '' : rawurldecode( (string) end( $parts ) );
		$last  = trim( (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $last ) );
		$words = trim( (string) preg_replace( '/[\s_-]+/', ' ', $last ) );
		if ( '' === $words || ! preg_match( '/\pL/u', $words ) || strlen( $words ) > 80 ) {
			return '' !== $host ? ( $de ? 'Eingebetteter Inhalt von ' : 'Embedded content from ' ) . $host : '';
		}
		$words = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $words, 0, 1 ) ) . mb_substr( $words, 1 ) : ucfirst( $words );
		if ( preg_match( '#/charts?/#i', $path ) ) {
			return ( $de ? 'Diagramm: ' : 'Chart: ' ) . $words;
		}
		return $words;
	}

	/**
	 * A heading that skips levels (an H4 right after the H1) breaks the outline screen
	 * reader users navigate by and fails Lighthouse's heading order check. It keeps its
	 * tag, look and SEO weight and gets the level it has in the outline (aria-level);
	 * later headings of the same tag level under the same parent get the same level.
	 * Levels set by the author (aria-level) and elements that are no heading for
	 * assistive technology (another role) are left alone.
	 *
	 * @param \WP_HTML_Tag_Processor $p     Processor.
	 * @param int                    $level Level of the tag (1–6).
	 */
	private function on_heading( $p, $level ) {
		if ( empty( $this->on['a11y_names'] ) ) {
			return;
		}
		$role = $p->get_attribute( 'role' );
		if ( is_string( $role ) && 'heading' !== strtolower( trim( $role ) ) ) {
			return;
		}
		while ( ! empty( $this->headings ) && $this->headings[ count( $this->headings ) - 1 ][0] >= $level ) {
			array_pop( $this->headings );
		}
		$given = empty( $this->headings ) ? $level : min( $level, $this->headings[ count( $this->headings ) - 1 ][1] + 1 );
		$own   = $p->get_attribute( 'aria-level' );
		if ( is_string( $own ) && ctype_digit( trim( $own ) ) && (int) $own > 0 ) {
			$given = (int) $own;
		} elseif ( $given !== $level ) {
			$p->set_attribute( 'aria-level', (string) $given );
			$this->count( 'heading_levels' );
		}
		$this->headings[] = array( $level, $given );
	}

	/**
	 * The logo (an image linked to the homepage) whose alternative text is just its file
	 * name ("SH-Digital_Logo_Preview_Midnight") gets the site's name instead. Images
	 * without alt text are left to on_anchor(), which names the link.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function logo_alt( $p ) {
		$alt = $p->get_attribute( 'alt' );
		if ( ! is_string( $alt ) || '' === trim( $alt ) || ! self::file_name_alt( $alt, (string) $p->get_attribute( 'src' ) ) ) {
			return;
		}
		$name = self::brand_name();
		if ( '' !== $name ) {
			$p->set_attribute( 'alt', $name );
			$this->count( 'logo_alt' );
		}
	}

	/**
	 * @param string $alt Alternative text.
	 * @param string $src Image address.
	 * @return bool Whether the text is the file name, or looks like one (no spaces, underscores).
	 */
	public static function file_name_alt( $alt, $src ) {
		$words = static function ( $text ) {
			return trim( (string) preg_replace( '/[\s_.-]+/', ' ', strtolower( $text ) ) );
		};
		$file = preg_replace( '/\.(?:png|jpe?g|webp|avif|gif|svg)$/i', '', wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$file = preg_replace( '/-(?:scaled|\d+x\d+|e\d{10,})$/i', '', (string) $file );
		$alt  = trim( $alt );
		return ( '' !== $file && $words( $alt ) === $words( $file ) )
			|| ( false === strpos( $alt, ' ' ) && ( false !== strpos( $alt, '_' ) || preg_match( '/^(?:img|dsc|image|bild|logo)[\W_]*\d*$/i', $alt ) ) );
	}

	/**
	 * @return string The organisation's or site's short name (Yoast SEO settings, else the site title), or ''.
	 */
	public static function brand_name() {
		static $name = null;
		if ( null !== $name ) {
			return $name;
		}
		$names = array();
		if ( class_exists( '\WPSEO_Options' ) ) {
			$names[] = (string) \WPSEO_Options::get( 'company_name', '' );
			$names[] = (string) \WPSEO_Options::get( 'website_name', '' );
		}
		$names[] = (string) get_bloginfo( 'name' );
		$name    = '';
		foreach ( $names as $candidate ) {
			$candidate = trim( wp_strip_all_tags( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5 ) ) );
			if ( '' !== $candidate && strlen( $candidate ) <= 60 ) {
				$name = $candidate;
				break;
			}
		}
		return $name;
	}

	/**
	 * @param mixed $href Link target.
	 * @return bool Whether the link goes to the homepage.
	 */
	private static function is_home_link( $href ) {
		if ( ! is_string( $href ) || '' === trim( $href ) ) {
			return false;
		}
		$strip = static function ( $url ) {
			return strtolower( untrailingslashit( (string) preg_replace( '#^(?:https?:)?//#i', '', trim( $url ) ) ) );
		};
		$href = trim( html_entity_decode( $href, ENT_QUOTES ) );
		return '/' === $href || $strip( $href ) === $strip( home_url( '/' ) );
	}

	/**
	 * A name for an icon-only link, from its target, in the site's language.
	 *
	 * @param string $href Link target.
	 * @return string Empty when nothing sensible can be said.
	 */
	public static function link_name( $href ) {
		$locale = get_locale();
		$de     = 0 === strpos( $locale, 'de' );
		$ch     = 0 === strpos( $locale, 'de_CH' );
		$target = rawurldecode( html_entity_decode( trim( $href ), ENT_QUOTES ) );
		if ( '' === $target ) {
			return '';
		}
		if ( preg_match( '/^#elementor-action:action=([a-z_]+):([a-z_]+)/i', $target, $m ) ) {
			$action = strtolower( $m[1] . ':' . $m[2] );
			$names  = array(
				'off_canvas:open'   => $de ? 'Menü öffnen' : 'Open menu',
				'off_canvas:close'  => $de ? ( $ch ? 'Menü schliessen' : 'Menü schließen' ) : 'Close menu',
				'off_canvas:toggle' => $de ? 'Menü' : 'Menu',
				'popup:open'        => $de ? 'Fenster öffnen' : 'Open dialog',
				'popup:close'       => $de ? ( $ch ? 'Fenster schliessen' : 'Fenster schließen' ) : 'Close dialog',
				'lightbox:open'     => $de ? ( $ch ? 'Bild vergrössern' : 'Bild vergrößern' ) : 'Enlarge image',
			);
			return isset( $names[ $action ] ) ? $names[ $action ] : '';
		}
		if ( in_array( strtolower( $target ), array( '#top', '#page', '#site-header', '#masthead' ), true ) ) {
			return $de ? 'Nach oben' : 'Back to top';
		}
		if ( 0 === stripos( $target, 'tel:' ) ) {
			return ( $de ? 'Anrufen: ' : 'Call ' ) . trim( substr( $target, 4 ) );
		}
		if ( 0 === stripos( $target, 'mailto:' ) ) {
			return ( $de ? 'E-Mail an ' : 'Email ' ) . trim( (string) strtok( substr( $target, 7 ), '?' ) );
		}
		if ( '/' === substr( $target, 0, 1 ) && '//' !== substr( $target, 0, 2 ) ) {
			// Relative to the site: complete it with the site's scheme and host.
			$home   = wp_parse_url( home_url() );
			$target = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $target;
		}
		$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		if ( preg_match( '#/(?:login|log-in|signin|sign-in|anmelden|kundenlogin)(?:[/?\#.]|$)#i', (string) wp_parse_url( $target, PHP_URL_PATH ) ) ) {
			return $de ? 'Anmelden' : 'Log in';
		}
		$networks = array(
			'linkedin.com'  => 'LinkedIn',
			'youtube.com'   => 'YouTube',
			'youtu.be'      => 'YouTube',
			'instagram.com' => 'Instagram',
			'facebook.com'  => 'Facebook',
			'x.com'         => 'X',
			'twitter.com'   => 'X',
			'tiktok.com'    => 'TikTok',
			'xing.com'      => 'XING',
			'wa.me'         => 'WhatsApp',
			'whatsapp.com'  => 'WhatsApp',
			'pinterest.com' => 'Pinterest',
			'threads.net'   => 'Threads',
			'bsky.app'      => 'Bluesky',
			'github.com'    => 'GitHub',
			'vimeo.com'     => 'Vimeo',
		);
		foreach ( $networks as $domain => $name ) {
			if ( $host === $domain || '.' . $domain === substr( $host, -strlen( $domain ) - 1 ) ) {
				return $name;
			}
		}
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $home ) {
			$path = (string) wp_parse_url( $target, PHP_URL_PATH );
			if ( '' === trim( $path, '/' ) ) {
				return $de ? 'Startseite' : 'Home';
			}
			$id = url_to_postid( $target );
			if ( $id ) {
				return wp_strip_all_tags( get_the_title( $id ) );
			}
			// Not a post (an archive, for example): the last part of the address.
			$slug = (string) preg_replace( '/[-_]+/', ' ', rawurldecode( basename( untrailingslashit( $path ) ) ) );
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $slug, 0, 1 ) ) . mb_substr( $slug, 1 ) : ucfirst( $slug );
		}
		return (string) preg_replace( '/^www\./', '', $host );
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

		// In the first screen its thumbnail (as wide as the widget, 16:9) can be the main image.
		if ( ! empty( $this->on['lcp_image'] ) && $this->text_now <= self::FIRST_SCREEN_TEXT ) {
			$scores = array();
			foreach ( array_keys( $this->top_pos ) as $device ) {
				$scores[ $device ] = $this->in_first_screen_on( $device ) ? 1280 * 720 + ( 1 === $this->top_pos[ $device ] ? 1000000000 : 0 ) : 0;
			}
			if ( max( $scores ) > 0 ) {
				$this->facade_lcp[ $n ] = $scores;
			}
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_img( $p ) {
		$loading = $p->get_attribute( 'loading' );

		if ( $this->in_home_link && ! empty( $this->on['a11y_names'] ) ) {
			$this->logo_alt( $p );
		}

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
			} else {
				// WordPress guesses the main image from the post content, which with Elementor
				// templates is often an image far down the page. Only the chosen main image keeps
				// high priority; WordPress's choice gets it back if no main image is found.
				if ( 'high' === $p->get_attribute( 'fetchpriority' ) ) {
					$p->remove_attribute( 'fetchpriority' );
					// Only an image near the top of the page may get it back.
					if ( ! $this->other_high && $this->text_now <= self::FIRST_SCREEN_TEXT && $this->in_first_screen() && $p->set_bookmark( 'shd_high' ) ) {
						$this->other_high = true;
					}
				}
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
		$area     = (int) $p->get_attribute( 'width' ) * (int) $p->get_attribute( 'height' );
		$featured = $this->inside( 'featured' );
		if ( ! $featured && ( $area < 150 * 150 || $this->text_now > self::FIRST_SCREEN_TEXT ) ) {
			return;
		}

		$candidate = array( 'hidden' => array() );
		$any       = false;
		foreach ( array_keys( $this->top_pos ) as $device ) {
			$candidate['hidden'][ $device ] = $this->hidden( $device );
			$candidate[ $device ]           = 0;
			if ( $this->in_first_screen_on( $device ) ) {
				$candidate[ $device ] = $area + ( $featured ? 4000000000 : 0 ) + ( 1 === $this->top_pos[ $device ] ? 1000000000 : 0 );
				$any                  = true;
			}
		}
		if ( ! $any ) {
			return;
		}

		$name = 'shd_lcp_' . ( $this->lcp_counter++ );
		if ( ! $p->set_bookmark( $name ) ) {
			return;
		}
		$this->lcp[ $name ] = $candidate;

		// A document allows only 10 bookmarks: keep the best three per device.
		$keep = array();
		foreach ( array_keys( $this->top_pos ) as $device ) {
			$scores = array();
			foreach ( $this->lcp as $key => $c ) {
				if ( $c[ $device ] > 0 ) {
					$scores[ $key ] = $c[ $device ];
				}
			}
			arsort( $scores );
			foreach ( array_slice( array_keys( $scores ), 0, 3 ) as $key ) {
				$keep[ $key ] = true;
			}
		}
		foreach ( array_keys( $this->lcp ) as $key ) {
			if ( ! isset( $keep[ $key ] ) ) {
				$p->release_bookmark( $key );
				unset( $this->lcp[ $key ] );
			}
		}
	}

	/**
	 * The main image per device gets high priority and no lazy-loading. When phones and
	 * desktops show different main images, each is preloaded for its own screen width
	 * only; an image hidden on phones stays lazy there, so phones never download it.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function apply_lcp( $p ) {
		$winners = array();
		foreach ( array_keys( $this->top_pos ) as $device ) {
			$best   = 0;
			$winner = null;
			foreach ( $this->lcp as $name => $c ) {
				if ( $c[ $device ] > $best ) {
					$best   = $c[ $device ];
					$winner = $name;
				}
			}
			foreach ( $this->facade_lcp as $n => $scores ) {
				if ( $scores[ $device ] > $best ) {
					$best   = $scores[ $device ];
					$winner = 'facade:' . $n;
				}
			}
			$winners[ $device ] = $winner;
			if ( null !== $winner && 0 === strpos( $winner, 'facade:' ) ) {
				$this->facade_high[ (int) substr( $winner, 7 ) ] = true;
				$winners[ $device ]                              = null;
			}
		}

		$images = array_values( array_unique( array_filter( $winners ) ) );
		if ( empty( $images ) ) {
			if ( empty( $this->facade_high ) && $this->other_high && $p->seek( 'shd_high' ) ) {
				$p->set_attribute( 'fetchpriority', 'high' );
			}
			return;
		}

		if ( 1 === count( $images ) && $winners['m'] === $winners['d'] ) {
			if ( $p->seek( $images[0] ) ) {
				$p->set_attribute( 'fetchpriority', 'high' );
				$this->make_eager( $p );
				$this->count( 'lcp_image' );
			}
			return;
		}

		$mobile_max = Toc::mobile_max_width();
		foreach ( $winners as $device => $name ) {
			if ( null === $name || ! $p->seek( $name ) ) {
				continue;
			}
			$hidden_on_phones = 'd' === $device && ! empty( $this->lcp[ $name ]['hidden']['m'] );
			if ( $hidden_on_phones ) {
				// Lazy and hidden: phones never download it; desktops get it from the preload.
				$p->set_attribute( 'loading', 'lazy' );
				$this->strip_auto_sizes( $p );
			} else {
				$this->make_eager( $p );
			}
			$media = 'm' === $device ? '(max-width: ' . $mobile_max . 'px)' : '(min-width: ' . ( $mobile_max + 1 ) . 'px)';
			$tag   = $this->image_preload_tag( $p, $media );
			if ( '' !== $tag ) {
				$this->image_preloads[] = $tag;
				$this->count( 'lcp_image' );
			}
		}
	}

	/**
	 * @param \WP_HTML_Tag_Processor $p Processor at an image.
	 */
	private function make_eager( $p ) {
		if ( 'lazy' === $p->get_attribute( 'loading' ) ) {
			$p->remove_attribute( 'loading' );
		}
		$this->strip_auto_sizes( $p );
	}

	/**
	 * sizes="auto" picks the file from the laid-out width, which a preload cannot know;
	 * a preloaded image must pick the same file as its preload.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at an image.
	 */
	private function strip_auto_sizes( $p ) {
		$sizes = $p->get_attribute( 'sizes' );
		if ( is_string( $sizes ) && 0 === stripos( $sizes, 'auto, ' ) ) {
			$p->set_attribute( 'sizes', substr( $sizes, 6 ) );
		}
	}

	/**
	 * <link rel="preload"> for the image at the processor, for one screen width range.
	 *
	 * @param \WP_HTML_Tag_Processor $p     Processor at an image.
	 * @param string                 $media Media query.
	 * @return string
	 */
	private function image_preload_tag( $p, $media ) {
		$src = $p->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return '';
		}
		$tag    = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"';
		$srcset = $p->get_attribute( 'srcset' );
		$sizes  = $p->get_attribute( 'sizes' );
		if ( is_string( $srcset ) && '' !== trim( $srcset ) ) {
			$tag .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
			if ( is_string( $sizes ) && '' !== trim( $sizes ) ) {
				$tag .= ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		}
		return $tag . ' fetchpriority="high" media="' . esc_attr( $media ) . '">';
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
		if ( ! empty( $this->on['a11y_names'] ) ) {
			$this->frame_title( $p );
		}
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
		if ( empty( $this->on['lazy_iframes'] ) ) {
			return;
		}
		// Another site's iframe (an embedded app, a map, a form) starts loading when it comes
		// near the screen. The browser's loading="lazy" starts iframes several screens ahead:
		// the case-study chart app further down the page still loaded, with its own analytics
		// and reCAPTCHA, while the page was loading. Elementor pages are often one large
		// section, so the position is left to the browser: an iframe already on screen
		// starts as soon as the page is laid out. loading="eager" set by the author is kept.
		$src = $p->get_attribute( 'src' );
		if ( is_string( $src ) && self::other_site( $src ) && null === $p->get_attribute( 'srcdoc' ) && null === $p->get_attribute( 'data-src' ) && 0 === $this->header_depth && 'eager' !== strtolower( trim( (string) $p->get_attribute( 'loading' ) ) ) ) {
			$p->set_attribute( 'data-shd-src', $src );
			$p->remove_attribute( 'src' );
			++$this->lazy_frames;
			return;
		}
		if ( $this->in_first_screen() ) {
			return;
		}
		if ( null === $p->get_attribute( 'loading' ) ) {
			$p->set_attribute( 'loading', 'lazy' );
			$this->count( 'lazy_iframes' );
		}
	}

	/**
	 * @param string $url URL.
	 * @return bool Whether it is an http(s) URL on another host than this site.
	 */
	private static function other_site( $url ) {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES );
		if ( ! preg_match( '#^(?:https?:)?//([^/:?\#]+)#i', $url, $m ) ) {
			return false;
		}
		return strtolower( $m[1] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Autoplaying Elementor video below the first screen: download and start it only
	 * when it comes near the viewport. Elementor's video widget has no JavaScript for
	 * self-hosted videos (autoplay is the HTML attribute alone), and sizes the video
	 * box from the widget's aspect ratio, so the later download moves nothing.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_video( $p ) {
		if ( empty( $this->on['lazy_videos'] ) || null === $p->get_attribute( 'autoplay' ) || ! $p->has_class( 'elementor-video' ) || $this->in_first_screen() ) {
			return;
		}
		$p->remove_attribute( 'autoplay' );
		$p->set_attribute( 'data-shd-autoplay', true );
		$preload = $p->get_attribute( 'preload' );
		if ( is_string( $preload ) ) {
			$p->set_attribute( 'data-shd-preload', $preload );
		}
		$p->set_attribute( 'preload', 'none' );
		++$this->lazy_videos;
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
	 * An IcoFont icon with an SVG drawing: marked here, drawn in insert_icons().
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor.
	 */
	private function on_icon( $p ) {
		if ( empty( $this->on['svg_icons'] ) ) {
			return;
		}
		$class = $p->get_attribute( 'class' );
		if ( ! is_string( $class ) || false === strpos( $class, 'icofont-' ) || ! preg_match( '/(?<![\w-])(icofont-[a-z0-9-]+)(?![\w-])/', $class, $m ) || '' === Icons::svg( $m[1] ) ) {
			return;
		}
		$p->set_attribute( self::ICON_MARK, $m[1] );
		++$this->icons;
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
		// The SHD Consent banner must run at once, never delayed or deferred.
		if ( 0 === strpos( $id, 'shd-consent' ) ) {
			return;
		}
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
			// Not plain async: CookieFirst's loader writes its banner script with document.write()
			// when it runs before <body> exists, which browsers ignore in async scripts (no banner).
			// Not defer either: that waits for every blocking script in the body. The file is
			// preloaded from the head and started by a tiny script at the top of <body>.
			$type = strtolower( trim( (string) $p->get_attribute( 'type' ) ) );
			if ( ! empty( $this->on['consent_async'] ) && $this->has_body && null === $p->get_attribute( 'async' ) && null === $p->get_attribute( 'defer' ) && in_array( $type, self::CLASSIC_TYPES, true ) ) {
				$attributes = array();
				foreach ( (array) $p->get_attribute_names_with_prefix( '' ) as $name ) {
					if ( ! in_array( $name, array( 'src', 'type', 'async', 'defer' ), true ) ) {
						$value               = $p->get_attribute( $name );
						$attributes[ $name ] = true === $value ? '' : (string) $value;
					}
				}
				$this->consent_moved[] = array( $src, $attributes );
				$p->set_attribute( self::REMOVE_MARK, true );
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
		return 'elementor-icons-dlicon' === $handle && ! $this->uses_dlicon;
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
		$html = $this->insert_icons( $html );

		if ( ! empty( $this->on['lcp_image'] ) ) {
			$html = $this->drop_unused_preloads( $html );
		}
		$this->background_preloads( $html );
		$html = $this->insert_head( $html );
		if ( ! empty( $this->on['combine_css'] ) ) {
			list( $html, $joined ) = Css_Combiner::combine( $html );
			if ( $joined > 0 ) {
				$this->count( 'css_combined', $joined );
			}
		}
		$html = $this->insert_body_start( $html );
		$html = $this->insert_body_end( $html );

		return $html;
	}

	/**
	 * Draws the marked IcoFont icons as inline SVG inside the same <i>, so Elementor's
	 * size, spacing and colour rules for the icon still apply, and leaves out the IcoFont
	 * stylesheet when nothing on the page mentions IcoFont any more: its 525 KB font is
	 * then never downloaded. Runs before the stylesheets are combined.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function insert_icons( $html ) {
		if ( 0 === $this->icons ) {
			return $html;
		}
		$drawn = 0;
		$new   = preg_replace_callback(
			'#<i\b([^>]*?)\s' . self::ICON_MARK . '="(icofont-[a-z0-9-]+)"([^>]*)>\s*</i>#i',
			function ( $m ) use ( &$drawn ) {
				$svg = Icons::svg( $m[2] );
				if ( '' === $svg ) {
					return $m[0];
				}
				$name  = $m[2];
				$attrs = preg_replace_callback(
					'/(\sclass=)(["\'])(.*?)\2/i',
					function ( $c ) use ( $name ) {
						$classes = array_diff( preg_split( '/\s+/', trim( $c[3] ) ), array( 'icofont', $name, '' ) );
						array_unshift( $classes, 'shd-icon', Icons::class_name( $name ) );
						return $c[1] . $c[2] . implode( ' ', $classes ) . $c[2];
					},
					$m[1] . $m[3],
					1
				);
				++$drawn;
				return '<i' . $attrs . '>' . $svg . '</i>';
			},
			$html
		);
		$new = is_string( $new ) ? $new : $html;
		// A marked icon that could not be drawn (an <i> with content) keeps the font.
		$clean = preg_replace( '#(<i\b[^>]*?)\s' . self::ICON_MARK . '="[^"]*"#i', '$1', $new );
		$new   = is_string( $clean ) ? $clean : $new;
		if ( 0 === $drawn ) {
			return $new;
		}
		$this->count( 'icons_svg', $drawn );

		$rest = preg_replace( '#<link\b[^>]*\bid=(["\'])elementor-icons-icofont-css\1[^>]*>[ \t]*\n?#i', '', $new );
		if ( is_string( $rest ) && $rest !== $new && false === stripos( $rest, 'icofont' ) ) {
			$new = $rest;
			$this->count( 'icon_font_removed' );
		}
		return $new;
	}

	/**
	 * Remove image preloads for files the page does not show (not in its markup, scripts
	 * or stylesheets). /kontakt/ preloaded a copy of its hero image under another name:
	 * phones downloaded it first, with high priority, while the image actually shown waited.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function drop_unused_preloads( $html ) {
		$end = stripos( $html, '</head>' );
		if ( false === $end || ! preg_match_all( '#<link\b[^>]*>#i', substr( $html, 0, $end ), $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$css = false;
		for ( $i = count( $m[0] ) - 1; $i >= 0; $i-- ) {
			$tag    = $m[0][ $i ][0];
			$offset = $m[0][ $i ][1];
			$attrs  = Css_Combiner::attributes( $tag );
			if ( ! isset( $attrs['rel'], $attrs['as'], $attrs['href'] ) || isset( $attrs['imagesrcset'] ) || 'preload' !== strtolower( trim( $attrs['rel'] ) ) || 'image' !== strtolower( trim( $attrs['as'] ) ) ) {
				continue;
			}
			$file = wp_basename( (string) wp_parse_url( html_entity_decode( $attrs['href'], ENT_QUOTES ), PHP_URL_PATH ) );
			if ( strlen( $file ) < 5 ) {
				continue;
			}
			$rest = substr( $html, 0, $offset ) . substr( $html, $offset + strlen( $tag ) );
			if ( false !== strpos( $rest, $file ) || false !== strpos( $rest, rawurlencode( $file ) ) ) {
				continue;
			}
			if ( false === $css ) {
				$css = Backgrounds::stylesheets( $rest );
			}
			if ( null === $css || false !== strpos( $css, $file ) ) {
				continue;
			}
			$html = $rest;
			$this->count( 'unused_preloads' );
		}
		return $html;
	}

	/**
	 * Preload the background images of the first sections (at most two per device). The
	 * browser otherwise finds them only after downloading and applying the stylesheet
	 * that names them, which made them start 2 s late on most pages.
	 *
	 * @param string $html Document (with its stylesheets still in place).
	 */
	private function background_preloads( $html ) {
		if ( empty( $this->bg_candidates ) ) {
			return;
		}
		$ids = array();
		foreach ( $this->bg_candidates as $candidate ) {
			if ( null === $candidate['url'] && '' !== $candidate['id'] ) {
				$ids[ $candidate['id'] ] = true;
			}
		}
		$rules = empty( $ids ) ? array() : Backgrounds::collect( $html, array_keys( $ids ) );

		// Lighthouse's phone (412 px) and desktop (1350 px).
		$widths = array(
			'm' => 412,
			'd' => 1350,
		);
		$urls   = array(
			'm' => array(),
			'd' => array(),
		);
		foreach ( $this->bg_candidates as $candidate ) {
			foreach ( $widths as $device => $width ) {
				if ( empty( $candidate['devices'][ $device ] ) || count( $urls[ $device ] ) >= 2 ) {
					continue;
				}
				$url = null !== $candidate['url'] ? $candidate['url'] : Backgrounds::url( $rules, $candidate['id'], $candidate['loop'], $width );
				if ( null === $url || 'none' === $url || ! preg_match( '#^(?:https?:)?//|^/(?!/)#i', $url ) ) {
					continue;
				}
				if ( ! in_array( $url, $urls[ $device ], true ) ) {
					$urls[ $device ][] = $url;
				}
			}
		}

		$mobile_max = Toc::mobile_max_width();
		$tags       = array();
		foreach ( array_values( array_unique( array_merge( $urls['m'], $urls['d'] ) ) ) as $url ) {
			$on_phones   = array_search( $url, $urls['m'], true );
			$on_desktops = array_search( $url, $urls['d'], true );
			$media       = '';
			if ( false === $on_phones ) {
				$media = '(min-width: ' . ( $mobile_max + 1 ) . 'px)';
			} elseif ( false === $on_desktops ) {
				$media = '(max-width: ' . $mobile_max . 'px)';
			}
			// Which of them is the largest element is not known here (a smooth gradient
			// covering the whole first screen does not count for the browser): both are
			// shown at once, so both get high priority.
			$tags[] = '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high"' . ( '' !== $media ? ' media="' . esc_attr( $media ) . '"' : '' ) . '>';
		}
		if ( ! empty( $tags ) ) {
			$this->count( 'background_preloads', count( $tags ) );
			$this->image_preloads = array_merge( $tags, $this->image_preloads );
		}
	}

	/**
	 * Starts the cookie banner scripts at the top of <body> (async, so nothing waits for them).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function insert_body_start( $html ) {
		if ( empty( $this->consent_moved ) || ! preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$code = '';
		foreach ( $this->consent_moved as $script ) {
			$code .= 's=d.createElement("script");s.src=' . wp_json_encode( $script[0] ) . ';a=' . wp_json_encode( (object) $script[1] ) . ';'
				. 'for(k in a){s.setAttribute(k,a[k])}d.body.appendChild(s);';
		}
		$at = $m[0][1] + strlen( $m[0][0] );
		return substr( $html, 0, $at ) . '<script id="shd-perf-consent">(function(d){var s,a,k;' . $code . '}(document));</script>' . substr( $html, $at );
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
				$video        = $this->facades[ $n ];
				$placed[ $n ] = true;
				return $m[1] . Youtube::facade_html( $video['id'], $video['settings'], $video['first'], (string) $this->s['youtube_label'], ! empty( $this->facade_high[ $n ] ) ) . $m[3];
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
		foreach ( $this->image_preloads as $tag ) {
			$tags .= $tag . "\n";
		}
		foreach ( $this->consent_moved as $script ) {
			$tags .= '<link rel="preload" as="script" href="' . esc_attr( $script[0] ) . '">' . "\n";
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
			$css .= Assets::toc_css( Toc::mobile_max_width(), $this->toc_collapsed > 0 );
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
				'all'      => ! empty( $this->on['delay_all_scripts'] ),
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
		if ( $this->toc_collapsed ) {
			$scripts .= '<script id="shd-perf-toc">' . Assets::script( 'toc' ) . "</script>\n";
		}
		if ( $this->lazy_bg > 0 ) {
			$this->count( 'lazy_backgrounds', $this->lazy_bg );
			$scripts .= '<script id="shd-perf-lazy-bg">' . Assets::script( 'lazy-bg' ) . "</script>\n";
		}
		if ( $this->lazy_videos > 0 ) {
			$this->count( 'lazy_videos', $this->lazy_videos );
			$scripts .= '<script id="shd-perf-lazy-video">' . Assets::script( 'lazy-video' ) . "</script>\n";
		}
		if ( $this->lazy_frames > 0 ) {
			$this->count( 'lazy_frames', $this->lazy_frames );
			// Without an interaction, frames start with the delayed scripts' timeout (10 s when that is 0).
			$timeout  = (int) $this->s['delay_timeout'];
			$scripts .= '<script id="shd-perf-lazy-iframe">window.shdPerfFrames=' . wp_json_encode( array( 'timeout' => ( $timeout > 0 ? $timeout : 10 ) * 1000 ) ) . ';' . Assets::script( 'lazy-iframe' ) . "</script>\n";
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
