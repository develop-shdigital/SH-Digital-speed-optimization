<?php
/**
 * Elementor Pro table of contents, filled in on the server.
 *
 * The widget's script builds the list after the page has loaded, so the box
 * grows and pushes everything below it down. This writes the same list (same
 * headings, link targets and markup as the script, see getNestedLevel() in
 * Elementor Pro's table-of-contents handler) into the page; the script later
 * replaces it with an identical list.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Toc {

	/** Container tags whose nesting is tracked; they always have an end tag. */
	const CONTAINERS = array( 'DIV', 'SECTION', 'HEADER', 'FOOTER', 'MAIN', 'ARTICLE', 'ASIDE', 'NAV', 'FIGURE' );

	/** Elementor documents the script does not search for headings. */
	const SKIPPED_DOCUMENTS = array( 'header', 'footer', 'popup' );

	const SPINNER_MARK = 'data-shd-toc';
	const WIDGET_MARK  = 'data-shd-toc-widget';
	const TITLE_MARK   = 'data-shd-toc-title';

	/** Set on a table of contents that starts collapsed on phones; the value is the max width in px. */
	const COLLAPSE_ATTR = 'data-shd-toc-min';

	/**
	 * @param string $html     Full HTML document.
	 * @param int    $collapse Lists start collapsed up to this screen width in px (with a button
	 *                         to open them); 0 = as designed.
	 * @return array{0:string,1:int,2:int} HTML, lists filled in, lists set to start collapsed.
	 */
	public static function prefill( $html, $collapse = 0 ) {
		if ( false === strpos( $html, 'elementor-toc__spinner-container' ) ) {
			return array( $html, 0, 0 );
		}
		$collapse = max( 0, (int) $collapse );

		$p        = new \WP_HTML_Tag_Processor( $html );
		$stack    = array(); // [ tag, Elementor document type|null, widget id|null, toc index|null ].
		$tocs     = array();
		$headings = array();
		$heading  = null;
		$template = 0;

		while ( $p->next_token() ) {
			$type = $p->get_token_type();
			if ( '#text' === $type ) {
				if ( null !== $heading ) {
					$heading['text'] .= $p->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}
			$tag = $p->get_tag();

			if ( 'TEMPLATE' === $tag ) {
				$template += $p->is_tag_closer() ? -1 : 1;
				continue;
			}

			if ( $p->is_tag_closer() ) {
				if ( null !== $heading && $tag === $heading['tag'] ) {
					$headings[] = $heading;
					$heading    = null;
				}
				if ( in_array( $tag, self::CONTAINERS, true ) ) {
					while ( $stack ) {
						$entry = array_pop( $stack );
						if ( $entry[0] === $tag ) {
							break;
						}
					}
				}
				continue;
			}

			if ( $collapse && self::has_class( $p, 'elementor-toc__header-title' ) ) {
				$owner = self::current_toc( $stack );
				if ( null !== $owner ) {
					$p->set_attribute( self::TITLE_MARK, (string) $owner );
				}
			}

			if ( preg_match( '/^H[1-6]$/', $tag ) && null === $heading && ! $template ) {
				if ( self::in_searched_document( $stack ) && ! self::has_class( $p, 'elementor-toc__header-title' ) ) {
					$heading = array(
						'tag'    => $tag,
						'level'  => (int) substr( $tag, 1 ),
						'id'     => (string) $p->get_attribute( 'id' ),
						'widget' => self::closest_widget_id( $stack ),
						'text'   => '',
					);
				}
				continue;
			}

			if ( ! in_array( $tag, self::CONTAINERS, true ) ) {
				continue;
			}

			$document = null;
			$widget   = null;
			$toc      = null;
			if ( self::has_class( $p, 'elementor' ) ) {
				$document = strtolower( (string) $p->get_attribute( 'data-elementor-type' ) );
			}
			if ( self::has_class( $p, 'elementor-widget' ) ) {
				$id     = $p->get_attribute( 'id' );
				$widget = is_string( $id ) ? $id : '';
			}
			if ( 'table-of-contents.default' === $p->get_attribute( 'data-widget_type' ) ) {
				$settings = json_decode( (string) $p->get_attribute( 'data-settings' ), true );
				$toc      = count( $tocs );
				$tocs[]   = array(
					'settings' => is_array( $settings ) ? $settings : null,
					'popup'    => self::in_popup( $stack ),
					'spinner'  => false,
					'body'     => '',
				);
				if ( $collapse ) {
					$p->set_attribute( self::WIDGET_MARK, (string) $toc );
				}
			}
			if ( self::has_class( $p, 'elementor-toc__body' ) ) {
				$owner = self::current_toc( $stack );
				if ( null !== $owner ) {
					$tocs[ $owner ]['body'] = (string) $p->get_attribute( 'id' );
				}
			}
			if ( self::has_class( $p, 'elementor-toc__spinner-container' ) ) {
				$owner = self::current_toc( $stack );
				if ( null !== $owner && ! $tocs[ $owner ]['spinner'] ) {
					$tocs[ $owner ]['spinner'] = true;
					$p->set_attribute( self::SPINNER_MARK, (string) $owner );
				}
			}
			$stack[] = array( $tag, $document, $widget, $toc );
		}

		$lists = array();
		foreach ( $tocs as $i => $toc ) {
			$list = $toc['spinner'] ? self::build( $toc['settings'], $toc['popup'], $headings ) : null;
			if ( null !== $list ) {
				$lists[ $i ] = $list;
			}
		}

		$html   = $p->get_updated_html();
		$filled = 0;
		// The spinner box holds only an icon (no nested div), so its end tag is the first </div>.
		$html = preg_replace_callback(
			'~<div\b[^>]*\s' . self::SPINNER_MARK . '="(\d+)"[^>]*>(.*?)</div>~s',
			static function ( $m ) use ( $lists, &$filled ) {
				if ( ! isset( $lists[ (int) $m[1] ] ) || false !== stripos( $m[2], '<div' ) ) {
					return str_replace( ' ' . self::SPINNER_MARK . '="' . $m[1] . '"', '', $m[0] );
				}
				++$filled;
				return $lists[ (int) $m[1] ];
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return array( $p->get_updated_html(), 0, 0 );
		}
		if ( ! $collapse ) {
			return array( $html, $filled, 0 );
		}

		// Start collapsed on phones: buttons after the title, state on the widget.
		$collapsed = array();
		$html      = preg_replace_callback(
			'~<([a-z][a-z0-9]*)\b[^>]*\s' . self::TITLE_MARK . '="(\d+)"[^>]*>.*?</\1>~is',
			static function ( $m ) use ( $lists, $tocs, &$collapsed ) {
				$i       = (int) $m[2];
				$element = str_replace( ' ' . self::TITLE_MARK . '="' . $m[2] . '"', '', $m[0] );
				if ( ! isset( $lists[ $i ] ) || '' === $tocs[ $i ]['body'] ) {
					return $element;
				}
				$collapsed[ $i ] = true;
				return $element . self::buttons( $tocs[ $i ]['body'] );
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			return array( $p->get_updated_html(), 0, 0 );
		}

		$w = new \WP_HTML_Tag_Processor( $html );
		while ( $w->next_tag() ) {
			$i = $w->get_attribute( self::WIDGET_MARK );
			if ( null === $i ) {
				continue;
			}
			$w->remove_attribute( self::WIDGET_MARK );
			if ( isset( $collapsed[ (int) $i ] ) ) {
				$w->add_class( 'elementor-toc--collapsed' );
				$w->set_attribute( self::COLLAPSE_ATTR, (string) $collapse );
			}
		}
		return array( $w->get_updated_html(), $filled, count( $collapsed ) );
	}

	/**
	 * Open and close buttons like Elementor Pro's own (its stylesheet shows the right one
	 * for the elementor-toc--collapsed class); the toc script makes them work at once.
	 *
	 * @param string $body Id of the list box.
	 * @return string
	 */
	private static function buttons( $body ) {
		$locale = get_locale();
		$de     = 0 === strpos( $locale, 'de' );
		$labels = array(
			'expand'   => $de ? 'Inhaltsverzeichnis öffnen' : 'Open table of contents',
			'collapse' => $de ? ( 0 === strpos( $locale, 'de_CH' ) ? 'Inhaltsverzeichnis schliessen' : 'Inhaltsverzeichnis schließen' ) : 'Close table of contents',
		);
		$icons  = array(
			'expand'   => 'M12 16.2 4.4 8.6l1.5-1.5 6.1 6.1 6.1-6.1 1.5 1.5z',
			'collapse' => 'M12 7.8l7.6 7.6-1.5 1.5-6.1-6.1-6.1 6.1-1.5-1.5z',
		);
		$out    = '';
		foreach ( $labels as $kind => $label ) {
			$out .= '<div class="elementor-toc__toggle-button elementor-toc__toggle-button--' . $kind . '" role="button" tabindex="0" aria-controls="' . esc_attr( $body ) . '" aria-expanded="false" aria-label="' . esc_attr( $label ) . '" data-shd-toc-toggle>'
				. '<svg aria-hidden="true" viewBox="0 0 24 24"><path d="' . $icons[ $kind ] . '"/></svg></div>';
		}
		return $out;
	}

	/**
	 * The list the widget's script would build, or null when it cannot be predicted
	 * (custom search area, excluded headings, icons in front of the items).
	 *
	 * @param array|null $settings Widget settings from data-settings.
	 * @param bool       $popup    Whether the widget is in a popup (the script searches only the popup).
	 * @param array      $found    Headings in the searched documents.
	 * @return string|null
	 */
	private static function build( $settings, $popup, array $found ) {
		if ( ! is_array( $settings ) || $popup || ! empty( $settings['container'] ) || ! empty( $settings['exclude_headings_by_selector'] )
			|| ( isset( $settings['minimize_box'] ) && 'yes' === $settings['minimize_box'] )
			|| ( isset( $settings['marker_view'] ) && 'bullets' === $settings['marker_view'] && ! empty( $settings['icon']['value'] ) )
			|| empty( $settings['headings_by_tags'] ) || ! is_array( $settings['headings_by_tags'] ) ) {
			return null;
		}

		$tags = array_map( 'strtoupper', $settings['headings_by_tags'] );
		$data = array();
		$i    = 0;
		foreach ( $found as $heading ) {
			if ( ! in_array( $heading['tag'], $tags, true ) ) {
				continue;
			}
			$anchor = '' !== $heading['id'] ? $heading['id'] : ( (string) $heading['widget'] );
			$data[] = array(
				'tag'    => $heading['level'],
				'text'   => $heading['text'],
				'anchor' => '' !== $anchor ? $anchor : 'elementor-toc__heading-anchor-' . $i,
				'level'  => null,
			);
			++$i;
		}

		if ( ! $data ) {
			return isset( $settings['no_headings_message'] ) ? wp_kses_post( (string) $settings['no_headings_message'] ) : null;
		}

		$nested = ! empty( $settings['hierarchical_view'] );
		if ( $nested ) {
			foreach ( $data as $n => $item ) {
				$data[ $n ]['level'] = 0;
				for ( $k = $n - 1; $k >= 0; $k-- ) {
					if ( $data[ $k ]['tag'] <= $item['tag'] ) {
						$data[ $n ]['level'] = $data[ $k ]['level'] + ( $data[ $k ]['tag'] < $item['tag'] ? 1 : 0 );
						break;
					}
				}
			}
		}

		$wrapper = isset( $settings['marker_view'] ) && 'numbers' === $settings['marker_view'] ? 'ol' : 'ul';
		$pointer = 0;
		return self::level( $nested ? 0 : null, $data, $pointer, $wrapper );
	}

	/**
	 * One list level (Elementor Pro's getNestedLevel()).
	 *
	 * @param int|null $level   Level of this list; null for a flat list.
	 * @param array    $data    Headings.
	 * @param int      $pointer Current heading, shared across levels.
	 * @param string   $wrapper ol or ul.
	 * @return string
	 */
	private static function level( $level, array $data, &$pointer, $wrapper ) {
		$out   = '<' . $wrapper . ' class="elementor-toc__list-wrapper">';
		$count = count( $data );
		while ( $pointer < $count ) {
			$item = $data[ $pointer ];
			if ( null !== $level && $level > $item['level'] ) {
				break;
			}
			if ( $level !== $item['level'] ) {
				break; // Not produced by the script's own levels; stop rather than loop.
			}
			$class = 'elementor-toc__list-item-text' . ( 0 === $item['level'] ? ' elementor-toc__top-level' : '' );
			$out  .= '<li class="elementor-toc__list-item"><div class="elementor-toc__list-item-text-wrapper">'
				. '<a href="#' . esc_attr( $item['anchor'] ) . '" class="' . $class . '">' . esc_html( $item['text'] ) . '</a></div>';
			++$pointer;
			if ( $pointer < $count && null !== $level && $level < $data[ $pointer ]['level'] ) {
				$out .= self::level( $data[ $pointer ]['level'], $data, $pointer, $wrapper );
			}
			$out .= '</li>';
		}
		return $out . '</' . $wrapper . '>';
	}

	/**
	 * Elementor's mobile breakpoint (max width in px).
	 *
	 * @return int
	 */
	public static function mobile_max_width() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) ) {
			$manager = \Elementor\Plugin::$instance->breakpoints;
			if ( method_exists( $manager, 'get_breakpoints' ) ) {
				$mobile = $manager->get_breakpoints( 'mobile' );
				if ( is_object( $mobile ) && method_exists( $mobile, 'get_value' ) && (int) $mobile->get_value() > 0 ) {
					return (int) $mobile->get_value();
				}
			}
		}
		return 767;
	}

	private static function has_class( $p, $name ) {
		return in_array( $name, preg_split( '/\s+/', trim( (string) $p->get_attribute( 'class' ) ) ), true );
	}

	/** The script searches all Elementor documents except headers, footers and popups. */
	private static function in_searched_document( array $stack ) {
		foreach ( $stack as $entry ) {
			if ( null !== $entry[1] && ! in_array( $entry[1], self::SKIPPED_DOCUMENTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** In a popup the script searches only that popup. */
	private static function in_popup( array $stack ) {
		foreach ( array_reverse( $stack ) as $entry ) {
			if ( null !== $entry[1] ) {
				return 'popup' === $entry[1];
			}
		}
		return false;
	}

	private static function closest_widget_id( array $stack ) {
		foreach ( array_reverse( $stack ) as $entry ) {
			if ( null !== $entry[2] ) {
				return $entry[2];
			}
		}
		return '';
	}

	private static function current_toc( array $stack ) {
		foreach ( array_reverse( $stack ) as $entry ) {
			if ( null !== $entry[3] ) {
				return $entry[3];
			}
		}
		return null;
	}
}
