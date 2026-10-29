<?php
/**
 * Per-request context: which view applies and whether this response may be optimized.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Request {

	const VIEW_COOKIE = 'shd_perf_view';

	/** @var string|null live|test|off */
	private static $view = null;

	/**
	 * The view for this request.
	 *
	 * - off:  plugin disabled by constant, or an admin opened a page with ?shd-off=1.
	 * - test: Live and Test features (admin with Test view on, or the secret preview link).
	 * - live: Live features only (everyone else).
	 *
	 * @return string
	 */
	public static function view() {
		if ( null !== self::$view ) {
			return self::$view;
		}

		$view = self::compute_view();

		// The user is only known after `init`; do not cache an answer computed earlier.
		if ( did_action( 'init' ) ) {
			self::$view = $view;
		}
		return $view;
	}

	/**
	 * @return string
	 */
	private static function compute_view() {
		if ( defined( 'SHD_PERFORMANCE_DISABLE' ) && SHD_PERFORMANCE_DISABLE ) {
			return 'off';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
		if ( isset( $_GET['shd-off'] ) && ( self::is_admin_user() || self::has_preview_token() ) ) {
			return 'off';
		}
		// phpcs:enable

		if ( self::has_preview_token() ) {
			return 'test';
		}

		if ( isset( $_COOKIE[ self::VIEW_COOKIE ] ) && 'test' === $_COOKIE[ self::VIEW_COOKIE ] && self::is_admin_user() ) {
			return 'test';
		}

		return 'live';
	}

	/**
	 * Whether a feature applies to this request.
	 *
	 * @param string $feature Feature id.
	 * @return bool
	 */
	public static function is_active( $feature ) {
		$view  = self::view();
		$state = Settings::state( $feature );

		if ( 'off' === $view || 'off' === $state ) {
			return false;
		}
		if ( 'live' === $state ) {
			return true;
		}
		return 'test' === $view;
	}

	/**
	 * Whether this request shows something other than what visitors get
	 * (the Test view, or a page opened with ?shd-off=1 for comparison).
	 *
	 * @return bool
	 */
	public static function is_preview() {
		$view = self::view();
		return 'test' === $view || ( 'off' === $view && ! ( defined( 'SHD_PERFORMANCE_DISABLE' ) && SHD_PERFORMANCE_DISABLE ) );
	}

	/**
	 * Whether the current front-end response is a page we may rewrite.
	 *
	 * @return bool
	 */
	public static function should_optimize() {
		if ( 'off' === self::view() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_trackback() || is_embed() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] && 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only checks.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || isset( $_GET['preview'] ) || isset( $_GET['fl_builder'] ) ) {
			return false;
		}
		// phpcs:enable
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
				return false;
			}
			if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
				return false;
			}
		}

		/**
		 * Filters whether SHD Performance rewrites the current response.
		 *
		 * @param bool $optimize Whether to optimize.
		 */
		return (bool) apply_filters( 'shd_performance_should_optimize', true );
	}

	/**
	 * Whether the request carries the secret preview token.
	 *
	 * @return bool
	 */
	private static function has_preview_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- compared against a secret.
		if ( ! isset( $_GET['shd-preview'] ) ) {
			return false;
		}
		$token = (string) Settings::get()['preview_token'];
		$given = sanitize_text_field( wp_unslash( $_GET['shd-preview'] ) );
		// phpcs:enable
		return '' !== $token && hash_equals( $token, $given );
	}

	/**
	 * @return bool
	 */
	private static function is_admin_user() {
		return function_exists( 'current_user_can' ) && did_action( 'init' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Reset the cached view (tests only).
	 */
	public static function reset() {
		self::$view = null;
	}
}
