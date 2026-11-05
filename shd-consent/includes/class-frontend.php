<?php
/**
 * Front end: styles and the head script in <head>, the banner at the top of <body>
 * (painted with the first frame, no third-party request), the "cookie settings"
 * link, and holding back tracking scripts until consent.
 *
 * @package SHD\Consent
 */

namespace SHD\Consent;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/** Consent tools this banner replaces (domain of their scripts => name). */
	const REPLACED = array( 'cookiefirst.com' => 'CookieFirst' );

	/** Option: stopped scripts of replaced consent tools, by handle or id (for the settings page). */
	const STOPPED_OPTION = 'shd_consent_stopped';

	/** @var bool|null Whether the banner is used on this request. */
	private static $on = null;

	/** @var bool */
	private static $printed = false;

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'banner' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'banner' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 10000 );
		// Before WordPress lists the hosts for its dns-prefetch hints (wp_head, 2), and again
		// just before the head and footer scripts are printed, for scripts added later.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stop_replaced' ), PHP_INT_MAX );
		add_action( 'wp_print_scripts', array( __CLASS__, 'stop_replaced' ), PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'stop_replaced' ), 9 );
		add_shortcode( 'shd_cookie_settings', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * A replaced consent tool that something still adds to the pages (a plugin setting, the
	 * theme, a code snippet) would show its own banner and button next to this one and keep
	 * its own record of the choice. While this banner is on, its scripts print nothing; the
	 * handles stay registered, so scripts that depend on them still load.
	 */
	public static function stop_replaced() {
		if ( ! self::on() ) {
			return;
		}
		foreach ( wp_scripts()->registered as $handle => $script ) {
			if ( ! is_string( $script->src ) || '' === $script->src ) {
				continue;
			}
			$name = self::replaced( $script->src );
			if ( '' !== $name ) {
				$script->src = false;
				self::stopped( $name, (string) $handle );
			}
		}
	}

	/**
	 * @param string $url Script address.
	 * @return string Name of the replaced consent tool the script belongs to, or ''.
	 */
	public static function replaced( $url ) {
		$host = strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		foreach ( self::REPLACED as $domain => $name ) {
			if ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Remembers a stopped script for the settings page: written when a script is new, then at
	 * most once a day per script. Not while an administrator only previews the banner.
	 *
	 * @param string $name   Consent tool.
	 * @param string $source Script handle or id, or "page" for a script written into the page.
	 */
	public static function stopped( $name, $source ) {
		if ( ! Settings::active() ) {
			return;
		}
		$seen = self::stopped_scripts();
		if ( isset( $seen[ $source ] ) && $seen[ $source ]['time'] > time() - DAY_IN_SECONDS ) {
			return;
		}
		$seen[ $source ] = array(
			'name' => $name,
			'time' => time(),
		);
		uasort(
			$seen,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		update_option( self::STOPPED_OPTION, array_slice( $seen, 0, 5, true ), true );
	}

	/**
	 * @return array<string,array{name:string,time:int}> Stopped scripts by handle or id, latest first.
	 */
	public static function stopped_scripts() {
		$seen = array();
		foreach ( (array) get_option( self::STOPPED_OPTION, array() ) as $source => $item ) {
			if ( is_array( $item ) && isset( $item['name'], $item['time'] ) ) {
				$seen[ (string) $source ] = array(
					'name' => (string) $item['name'],
					'time' => (int) $item['time'],
				);
			}
		}
		return $seen;
	}

	/**
	 * @return bool Whether the banner is used on this request.
	 */
	public static function on() {
		if ( null === self::$on ) {
			self::$on = ! is_admin() && ! is_feed() && ! is_customize_preview() && ! self::in_builder() && ( Settings::active() || self::preview() );
		}
		return self::$on;
	}

	/**
	 * Admins can try the banner while another banner plugin is still active: ?shd-consent-preview=1.
	 *
	 * @return bool
	 */
	public static function preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		return isset( $_GET['shd-consent-preview'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * @return bool Inside the Elementor editor (or its preview frame).
	 */
	private static function in_builder() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		return isset( $_GET['elementor-preview'] ) || ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] );
	}

	public static function head() {
		if ( ! self::on() ) {
			return;
		}
		$s      = Settings::get();
		$config = array(
			'v'        => (string) $s['version'],
			'days'     => max( 1, (int) $s['days'] ),
			'cf'       => ! empty( $s['compat_cookiefirst'] ),
			'importCf' => ! empty( $s['compat_cookiefirst'] ) && '1' === (string) $s['version'],
			'gcm'      => ! empty( $s['consent_mode'] ),
			'log'      => ! empty( $s['log'] ) ? rest_url( Log::ROUTE . '/log' ) : '',
			'cats'     => self::categories(),
			'preview'  => self::preview(),
		);
		echo '<style id="shd-consent-css">' . self::css() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet.
		echo '<script id="shd-consent-head">window.shdConsentConfig=' . wp_json_encode( $config ) . ';' . self::asset( 'consent-head' ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON and static script.
	}

	/**
	 * The banner, as early in <body> as the theme allows (wp_body_open), else in the footer.
	 */
	public static function banner() {
		if ( self::$printed || ! self::on() ) {
			return;
		}
		self::$printed = true;
		$s      = Settings::get();
		$t      = $s['texts'];
		$colors = $s['colors'];
		$style  = '';
		foreach ( array( 'bg' => 'background', 'text' => 'text', 'button' => 'button', 'button-text' => 'button_text', 'link' => 'link' ) as $var => $key ) {
			$color  = sanitize_hex_color( (string) $colors[ $key ] );
			$style .= '--shdc-' . $var . ':' . ( $color ? $color : '#000000' ) . ';';
		}

		echo "\n" . '<div id="shd-consent" class="shd-consent shd-consent--' . esc_attr( 'bar' === $s['layout'] ? 'bar' : 'box' ) . '" role="dialog" aria-modal="false" aria-labelledby="shd-consent-title" aria-describedby="shd-consent-text" tabindex="-1" style="' . esc_attr( $style ) . '"><div class="shd-consent__inner">';
		echo '<p class="shd-consent__title" id="shd-consent-title" role="heading" aria-level="2">' . esc_html( $t['title'] ) . '</p>';
		echo '<p class="shd-consent__text" id="shd-consent-text">' . esc_html( $t['message'] ) . '</p>';

		echo '<div class="shd-consent__details" id="shd-consent-details" hidden>';
		self::category( 'necessary', $s['necessary'], $t, true );
		foreach ( self::categories() as $name ) {
			self::category( $name, $s['categories'][ $name ], $t, false );
		}
		echo '</div>';

		echo '<div class="shd-consent__buttons">';
		echo '<button type="button" data-shd-action="accept">' . esc_html( $t['accept'] ) . '</button>';
		echo '<button type="button" data-shd-action="reject">' . esc_html( $t['reject'] ) . '</button>';
		echo '<button type="button" class="shd-consent__save" data-shd-action="save" hidden>' . esc_html( $t['save'] ) . '</button>';
		echo '</div>';

		echo '<p class="shd-consent__footer"><button type="button" class="shd-consent__more" data-shd-action="details" aria-expanded="false" aria-controls="shd-consent-details">' . esc_html( $t['settings'] ) . '</button>';
		if ( '' !== (string) $s['privacy_url'] ) {
			echo '<a href="' . esc_url( $s['privacy_url'] ) . '">' . esc_html( $t['privacy'] ) . '</a>';
		}
		if ( '' !== (string) $s['imprint_url'] ) {
			echo '<a href="' . esc_url( $s['imprint_url'] ) . '">' . esc_html( $t['imprint'] ) . '</a>';
		}
		echo '</p></div></div>';

		if ( ! empty( $s['reopen_button'] ) ) {
			echo '<button type="button" id="shd-consent-reopen" class="shd-consent-reopen" data-shd-consent-open aria-label="' . esc_attr( $t['reopen'] ) . '" title="' . esc_attr( $t['reopen'] ) . '" style="' . esc_attr( $style ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l7 3v5c0 4.4-2.9 8.3-7 10-4.1-1.7-7-5.6-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg></button>';
		}
		echo '<script id="shd-consent-js">' . self::asset( 'consent' ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script.
	}

	/**
	 * One category in the settings layer.
	 *
	 * @param string $name     Category.
	 * @param array  $category Settings of the category.
	 * @param array  $t        Texts.
	 * @param bool   $fixed    Always on (necessary).
	 */
	private static function category( $name, array $category, array $t, $fixed ) {
		$id = 'shd-consent-' . $name;
		echo '<div class="shd-consent__cat"><label for="' . esc_attr( $id ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $fixed ? ' checked disabled' : '' ) . '> ' . esc_html( $category['label'] );
		if ( $fixed ) {
			echo '<span class="shd-consent__always">' . esc_html( $t['always'] ) . '</span>';
		}
		echo '</label>';
		if ( '' !== trim( (string) $category['description'] ) ) {
			echo '<p>' . esc_html( $category['description'] ) . '</p>';
		}
		$services = Settings::services( $category['services'] );
		if ( ! empty( $services ) ) {
			echo '<details><summary>' . esc_html( $t['services'] . ' (' . count( $services ) . ')' ) . '</summary><ul>';
			foreach ( $services as $service ) {
				echo '<li><strong>' . esc_html( $service['name'] ) . '</strong>';
				$about = array_filter( array( $service['provider'], $service['purpose'] ) );
				if ( ! empty( $about ) ) {
					echo ' – ' . esc_html( implode( ': ', $about ) );
				}
				if ( '' !== $service['url'] ) {
					echo ' (<a href="' . esc_url( $service['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $t['privacy'] ) . '</a>)';
				}
				echo '</li>';
			}
			echo '</ul></details>';
		}
		echo '</div>';
	}

	/**
	 * @return string[] Optional categories that are switched on.
	 */
	public static function categories() {
		$on = array();
		foreach ( Settings::CATEGORIES as $name ) {
			if ( ! empty( Settings::get()['categories'][ $name ]['enabled'] ) ) {
				$on[] = $name;
			}
		}
		return $on;
	}

	public static function start_buffer() {
		if ( ! self::on() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter' ) );
	}

	/**
	 * Output buffer: stop scripts of replaced consent tools that are written straight into
	 * the page, and hold back tracking scripts, in a complete HTML document.
	 *
	 * @param string $html  Buffer.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public static function filter( $html, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}
		try {
			return Blocker::process( $html, ! empty( Settings::get()['block_scripts'] ) );
		} catch ( \Throwable $error ) {
			return $html;
		}
	}

	/**
	 * [shd_cookie_settings] – a link that opens the banner's settings.
	 *
	 * @param array|string $atts Attributes (text).
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'text' => Settings::get()['texts']['reopen'] ), (array) $atts );
		return '<a href="#cookie-einstellungen" class="shd-consent-open">' . esc_html( $atts['text'] ) . '</a>';
	}

	/**
	 * @param string $name Script name in assets/js (the minified build when there is one).
	 * @return string
	 */
	private static function asset( $name ) {
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$min            = SHD_CONSENT_DIR . 'assets/js/min/' . $name . '.min.js';
			$file           = is_readable( $min ) ? $min : SHD_CONSENT_DIR . 'assets/js/' . $name . '.js';
			$cache[ $name ] = str_replace( '</script', '<\/script', (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return $cache[ $name ];
	}

	/**
	 * @return string The stylesheet without comments and extra whitespace.
	 */
	private static function css() {
		$css = (string) file_get_contents( SHD_CONSENT_DIR . 'assets/css/consent.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = (string) preg_replace( '/\s+/', ' ', $css );
		$css = (string) preg_replace( '/\s*([{}:;,>~])\s*/', '$1', $css );
		return str_replace( array( ';}', '</' ), array( '}', '<\/' ), trim( $css ) );
	}
}
