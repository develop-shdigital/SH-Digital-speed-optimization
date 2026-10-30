<?php
/**
 * Feature registry and stored settings.
 *
 * Every automatic optimization is a feature with one of three states:
 * - off:  never applied.
 * - test: applied only in the preview (admins with "Test view" on, or the secret preview link).
 * - live: applied for every visitor.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'shd_perf_settings';

	const STATES = array( 'off', 'test', 'live' );

	/** @var array|null */
	private static $cache = null;

	/**
	 * Feature definitions shown on the Optimizations tab.
	 *
	 * @return array<string,array>
	 */
	public static function features() {
		return array(
			'animations'          => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Show content immediately (no entrance animations)', 'shd-performance' ),
				'fixes'   => __( 'Elementor keeps every element with an entrance animation invisible until its JavaScript has run. On sh-digital.ch that held the main content back by 2.0 s on desktop and 7.6 s on phones, and hid the header (logo and menu) on every page. This removes the hidden state so content paints with the first frame. The animations stay saved in Elementor, so turning this off restores them.', 'shd-performance' ),
			),
			'lcp_image'           => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Prioritize the main image', 'shd-performance' ),
				'fixes'   => __( 'Picks the largest image in the first screen (or the post’s featured image), removes lazy-loading from it and gives it fetchpriority="high". Takes the high priority away from the header logo, which currently competes with it.', 'shd-performance' ),
			),
			'image_sizes'         => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Download images at the size they are shown', 'shd-performance' ),
				'fixes'   => __( 'Adds sizes="auto" to lazy-loaded images so the browser picks the smallest file from the srcset that is still sharp at the displayed size (the 34 px avatars currently download 768 px files). Nothing is re-compressed, so image quality is unchanged.', 'shd-performance' ),
			),
			'image_dimensions'    => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Reserve space for images without dimensions', 'shd-performance' ),
				'fixes'   => __( 'Adds the real width and height to uploaded images that lack them, so the layout does not jump when they load.', 'shd-performance' ),
			),
			'lazy_backgrounds'    => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Load background images and carousel slides further down when they come into view', 'shd-performance' ),
				'fixes'   => __( 'Browsers download every CSS background image and every carousel slide during page load, even far below the screen. On /blog/ the post cards use the full-size featured images as backgrounds (about 3.5 MB on a phone); on /resultate/ the carousels load about 3.7 MB of mockups. Backgrounds of blog cards after the first row, of sections below the first screen and of the footer, and the slides of carousels below the first section, now load when they come within about one screen of view (all slides of a carousel together, so none appears empty). The mobile menu’s background loads after the page has loaded or on the first tap. Elementor already does this for backgrounds from the 4th section on; this extends it. Image files and quality are unchanged.', 'shd-performance' ),
			),
			'lazy_iframes'        => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Lazy-load iframes below the first screen', 'shd-performance' ),
				'fixes'   => __( 'Embeds such as maps load when they come near the viewport instead of during page load.', 'shd-performance' ),
			),
			'lazy_videos'         => array(
				'group'   => 'content',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Start autoplaying videos further down when they come into view', 'shd-performance' ),
				'fixes'   => __( 'A video set to play automatically is downloaded during page load even far below the screen: on /google-ads-agentur/ the 735 KB “Umsatzwachstum” chart animation in the fourth section competed with the first screen on phones. Elementor video widgets below the first screen now load and start when they come within about one screen of view, so the animation also plays when visitors actually see it. The video box keeps its size (Elementor sizes it from the widget’s aspect ratio), so nothing moves. Videos in the first screen are unchanged.', 'shd-performance' ),
			),
			'youtube_facade'      => array(
				'group'   => 'third-party',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Click-to-play YouTube previews', 'shd-performance' ),
				'fixes'   => __( 'Elementor’s YouTube widget loads the full player (about 1 MB) before anyone presses play. This shows the video thumbnail with a play button instead; the player loads on click and starts playing with that click.', 'shd-performance' ),
			),
			'recaptcha_on_demand' => array(
				'group'   => 'third-party',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Load reCAPTCHA when a form is used', 'shd-performance' ),
				'fixes'   => __( 'Elementor Pro loads reCAPTCHA v3 (349 KB, about 0.8 s of phone CPU) on every page because a form is on every page. This loads it as soon as a visitor focuses or touches a form. A submit that happens before it is ready is held until the token exists, so spam protection is unchanged.', 'shd-performance' ),
			),
			'delay_tracking'      => array(
				'group'   => 'third-party',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Start tracking scripts after the first interaction', 'shd-performance' ),
				'fixes'   => __( 'Google Analytics (MonsterInsights), Simple Analytics, SHD Tracking Manager and SHD Analytics start on the first scroll, tap, click or key press, or after the timeout below, instead of competing with the page load. The tracking manager still reads the stored CookieFirst consent and still captures gclid/UTM parameters from the URL.', 'shd-performance' ),
			),
			'consent_async'       => array(
				'group'   => 'third-party',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Load the cookie banner without blocking the page', 'shd-performance' ),
				'fixes'   => __( 'CookieFirst’s consent.js is loaded synchronously in the <head> and held back the first paint by about 1 s on phones. Now its download starts at the top of the page without blocking it (preload and an early connection), and it runs as soon as the page body has started, which CookieFirst’s loader needs to show the banner. On this site CookieFirst does not block scripts itself (script_loading: do-nothing); the tracking scripts read the consent cookie and start only after it has run, so consent handling is unchanged.', 'shd-performance' ),
			),
			'dedupe_scripts'      => array(
				'group'   => 'javascript',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Load duplicate scripts only once', 'shd-performance' ),
				'fixes'   => __( 'The CookieFirst plugin prints cookiefirst-plugin-public.js twice. Identical script files are now loaded once.', 'shd-performance' ),
			),
			'defer_scripts'       => array(
				'group'   => 'javascript',
				'default' => 'test',
				'risk'    => 'medium',
				'title'   => __( 'Stop JavaScript from blocking the first paint', 'shd-performance' ),
				'fixes'   => __( 'Loads the remaining scripts (jQuery, Elementor, plugins) with defer and keeps their order; inline scripts that depend on them are deferred too. Everything still runs before the page’s DOMContentLoaded event. Check the preview before switching to Live.', 'shd-performance' ),
			),
			'delay_all_scripts'   => array(
				'group'   => 'javascript',
				'default' => 'test',
				'risk'    => 'medium',
				'title'   => __( 'Start all non-essential JavaScript on the first interaction', 'shd-performance' ),
				'fixes'   => __( 'Biggest win for the mobile score (Total Blocking Time is 30% of it): Elementor, jQuery and plugin scripts start on the first scroll, tap, click or key press, or after the timeout, in their original order (Elementor Pro and the add-ons start together with Elementor, as on a normal page load). Taps on menus, buttons and forms made before that are replayed once the scripts are ready. The site’s own script files are pre-downloaded a few seconds after loading, so a later first tap does not wait for the network. Carousels start moving and the chat or map appear after the first interaction. Check menus, forms, carousels and the video in the preview before switching to Live.', 'shd-performance' ),
			),
			'fonts'               => array(
				'group'   => 'fonts',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Fonts: visible text, WOFF2, preload and size-matched fallback', 'shd-performance' ),
				'fixes'   => __( 'Sets font-display: swap so text is never invisible, serves WOFF2 files when you upload them on the Fonts tab (same letters, a fraction of the size of the current .ttf files), preloads the main weights and uses a size-matched fallback so the text barely moves when the font arrives.', 'shd-performance' ),
			),
			'addon_assets'        => array(
				'group'   => 'javascript',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Unload add-on files a page does not use', 'shd-performance' ),
				'fixes'   => __( 'Qi Addons is used on no page but loads 7 files on every page; LA-Studio Kit loads scripts on pages without its widgets. Their files are removed on pages that do not use them. The shared Swiper library that Elementor’s carousels need is kept.', 'shd-performance' ),
			),
			'emoji'               => array(
				'group'   => 'javascript',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Disable the WordPress emoji script', 'shd-performance' ),
				'fixes'   => __( 'Modern browsers display emoji natively; the detection script and its extra download are not needed.', 'shd-performance' ),
			),
			'jquery_migrate'      => array(
				'group'   => 'javascript',
				'default' => 'test',
				'risk'    => 'medium',
				'title'   => __( 'Remove jQuery Migrate', 'shd-performance' ),
				'fixes'   => __( 'jQuery Migrate only keeps very old plugin code working. Check the preview (browser console and interactive elements) before switching to Live.', 'shd-performance' ),
			),
			'cursor'              => array(
				'group'   => 'javascript',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Lighter custom cursor', 'shd-performance' ),
				'fixes'   => __( 'The cursor-dot script redraws 60 times per second forever and leaves a stray dot at the top left on phones. It now runs only on devices with a mouse and pauses when the mouse stops; it looks the same.', 'shd-performance' ),
			),
			'toc_prefill'         => array(
				'group'   => 'layout',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Blog table of contents: filled in from the start', 'shd-performance' ),
				'fixes'   => __( 'Elementor Pro fills the table of contents with JavaScript after the page has loaded, so the box grows and pushes the article down (CLS 0.2 to 0.7 on phones). The same list is now written into the page, so the box has its final size from the first paint. It looks and works as before.', 'shd-performance' ),
			),
			'preconnect'          => array(
				'group'   => 'third-party',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Connect early to required third parties', 'shd-performance' ),
				'fixes'   => __( 'Adds preconnect hints for third-party scripts that still load during page load (for example CookieFirst).', 'shd-performance' ),
			),
			'rum'                 => array(
				'group'   => 'monitoring',
				'default' => 'live',
				'risk'    => 'low',
				'title'   => __( 'Measure real-visitor Core Web Vitals', 'shd-performance' ),
				'fixes'   => __( 'Records LCP, INP, CLS, FCP and TTFB from real visits (no cookies; no IP address or user agent stored) and shows the 75th percentile on the Web Vitals tab. Google has no real-visitor data for this site yet, so this is how you can see whether the Core Web Vitals pass.', 'shd-performance' ),
			),
		);
	}

	/**
	 * Default option values (feature states plus feature options).
	 *
	 * @return array
	 */
	public static function defaults() {
		$states = array();
		foreach ( self::features() as $id => $feature ) {
			$states[ $id ] = $feature['default'];
		}

		return array(
			'states'                => $states,
			'first_screen_sections' => 2,
			'animation_mode'        => 'all',
			'toc_phones'            => 'expanded',
			'delay_timeout'         => 10,
			'tracking_patterns'     => array(
				'googletagmanager.com/gtag/js',
				'google-analytics.com/analytics.js',
				'google-analytics-for-wordpress/assets/js/frontend-gtag',
				'scripts.simpleanalyticscdn.com',
				'/shd-tracking-manager/',
				'/shd-analytics/',
				'connect.facebook.net',
				'snap.licdn.com',
				'static.hotjar.com',
				'analytics.tiktok.com',
				'cdn.leadinfo.net',
				'bzrcdn.openai.com',
				'clarity.ms',
			),
			'delay_exclusions'      => array(
				'consent.cookiefirst.com',
			),
			'keep_inline'           => array(
				'lakitCheckHeaderTransparency',
				'ChatWidgetConfig',
				'cursor-dot',
				'wp_consent_type',
				'lazyloadRunObserver',
				'dataLayer',
			),
			'consent_scripts'       => array(
				'consent.cookiefirst.com',
			),
			'youtube_label'         => '',
			'rum_sample'            => 100,
			'redirect_domains'      => array( 'shdigital.ch', 'sh-digital.ch' ),
			'page_cache'            => true,
			'cache_ttl'             => 10,
			'cache_campaign'        => false,
			'cache_exclude'         => array(),
			'preview_token'         => '',
			'fonts'                 => array(
				'faces'   => array(),
				'woff2'   => array(),
				'metrics' => array(),
				'preload' => array( '400', '600' ),
			),
		);
	}

	/**
	 * Stored settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$settings = array_merge( $defaults, $stored );

		$settings['states'] = array_merge( $defaults['states'], isset( $stored['states'] ) && is_array( $stored['states'] ) ? $stored['states'] : array() );
		$settings['fonts']  = array_merge( $defaults['fonts'], isset( $stored['fonts'] ) && is_array( $stored['fonts'] ) ? $stored['fonts'] : array() );

		foreach ( $settings['states'] as $id => $state ) {
			if ( ! in_array( $state, self::STATES, true ) ) {
				$settings['states'][ $id ] = isset( $defaults['states'][ $id ] ) ? $defaults['states'][ $id ] : 'off';
			}
		}

		self::$cache = $settings;
		return $settings;
	}

	/**
	 * Merge and store new settings.
	 *
	 * @param array $changes Partial settings.
	 */
	public static function update( array $changes ) {
		$current = self::get();
		foreach ( $changes as $key => $value ) {
			if ( in_array( $key, array( 'states', 'fonts' ), true ) && is_array( $value ) ) {
				$current[ $key ] = array_merge( $current[ $key ], $value );
			} else {
				$current[ $key ] = $value;
			}
		}
		update_option( self::OPTION, $current, true );
		self::$cache = null;
	}

	/**
	 * @param string $feature Feature id.
	 * @return string off|test|live
	 */
	public static function state( $feature ) {
		$settings = self::get();
		return isset( $settings['states'][ $feature ] ) ? $settings['states'][ $feature ] : 'off';
	}

	/**
	 * Secret token for the anonymous preview link, created on first use.
	 *
	 * @return string
	 */
	public static function preview_token() {
		$settings = self::get();
		if ( empty( $settings['preview_token'] ) ) {
			$token = wp_generate_password( 20, false, false );
			self::update( array( 'preview_token' => $token ) );
			return $token;
		}
		return $settings['preview_token'];
	}

	/**
	 * Drop the in-memory copy (used after direct option changes).
	 */
	public static function flush() {
		self::$cache = null;
	}
}
