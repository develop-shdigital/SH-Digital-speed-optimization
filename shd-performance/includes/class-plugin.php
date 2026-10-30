<?php
/**
 * Bootstrap: hooks, output buffer, admin bar, cron.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const CRON_HOOK  = 'shd_perf_daily';
	const ERROR_OPT  = 'shd_perf_last_error';

	/** @var Plugin|null */
	private static $instance = null;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_action( 'init', array( $this, 'init' ) );
		// Late priority: page caches that buffer earlier wrap our buffer and store the optimized page.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 9999 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( self::CRON_HOOK, array( $this, 'daily' ) );

		Page_Cache::register();
		Youtube::register();
		Rum::register();

		if ( is_admin() && class_exists( __NAMESPACE__ . '\Admin' ) ) {
			Admin::instance()->register();
		}
	}

	public function init() {
		if ( Rum::DB_VERSION !== get_option( 'shd_perf_rum_db' ) ) {
			Rum::install();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		$this->maybe_switch_view();
	}

	/**
	 * Admin-bar link: switch between Live view and Test view (stored in a cookie).
	 */
	private function maybe_switch_view() {
		if ( ! isset( $_GET['shd-perf-view'], $_GET['_wpnonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shd-perf-view' ) ) {
			return;
		}
		$view = 'test' === $_GET['shd-perf-view'] ? 'test' : 'live';
		setcookie( Request::VIEW_COOKIE, $view, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( remove_query_arg( array( 'shd-perf-view', '_wpnonce' ) ) );
		exit;
	}

	public function start_buffer() {
		if ( Request::is_preview() ) {
			// The Test view and the unoptimized comparison must never end up in a page cache.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
		if ( ! Request::should_optimize() ) {
			return;
		}
		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Output-buffer callback.
	 *
	 * @param string $html  Buffer.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public function process( $html, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		// Only rewrite a complete document; partially flushed output passes through.
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ! is_string( $html ) || ! Html_Optimizer::is_document( $html ) || ! self::is_html_response() ) {
			return $html;
		}
		$measure = Profiler::begin();
		try {
			$optimizer = new Html_Optimizer( self::active_features(), Settings::get(), self::allows_data_scripts() );
			return $optimizer->optimize( $html );
		} catch ( \Throwable $error ) {
			update_option(
				self::ERROR_OPT,
				array(
					'time'    => time(),
					'message' => $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')',
					'url'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				),
				false
			);
			return $html;
		} finally {
			Profiler::end( $measure, 'plugin:shd-performance' );
		}
	}

	/**
	 * @return array<string,bool>
	 */
	public static function active_features() {
		$active = array();
		foreach ( array_keys( Settings::features() ) as $id ) {
			$active[ $id ] = Request::is_active( $id );
		}
		return $active;
	}

	/**
	 * @return bool
	 */
	private static function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}
		return true;
	}

	/**
	 * Deferring inline scripts uses data: URLs; a Content-Security-Policy may forbid them.
	 *
	 * @return bool
	 */
	private static function allows_data_scripts() {
		foreach ( headers_list() as $header ) {
			if ( 0 !== stripos( $header, 'content-security-policy:' ) ) {
				continue;
			}
			$policy = strtolower( substr( $header, strlen( 'content-security-policy:' ) ) );
			if ( preg_match( '/(?:^|;)\s*script-src(?:-elem)?\s+([^;]*)/', $policy, $m ) || preg_match( '/(?:^|;)\s*default-src\s+([^;]*)/', $policy, $m ) ) {
				return false !== strpos( $m[1], 'data:' );
			}
		}
		return true;
	}

	/**
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view  = Request::view();
		$label = 'test' === $view ? __( 'Test view', 'shd-performance' ) : ( 'off' === $view ? __( 'Off', 'shd-performance' ) : __( 'Live view', 'shd-performance' ) );
		$bar->add_node(
			array(
				'id'    => 'shd-perf',
				/* translators: %s: current view */
				'title' => sprintf( __( 'SHD Performance: %s', 'shd-performance' ), $label ),
				'href'  => admin_url( 'admin.php?page=shd-performance' ),
			)
		);

		if ( Page_Cache::serving() ) {
			$bar->add_node(
				array(
					'parent' => 'shd-perf',
					'id'     => 'shd-perf-purge',
					'title'  => __( 'Clear the page cache', 'shd-performance' ),
					'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=shd_perf_purge&tab=cache' ), 'shd_perf_purge' ),
				)
			);
		}

		if ( ! is_admin() ) {
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$current = ( is_ssl() ? 'https://' : 'http://' ) . $host . remove_query_arg( array( 'shd-off', 'shd-preview' ), $uri );
			$bar->add_node(
				array(
					'parent' => 'shd-perf',
					'id'     => 'shd-perf-view',
					'title'  => 'test' === $view ? __( 'Switch to Live view (what visitors get)', 'shd-performance' ) : __( 'Switch to Test view (Live + Test features)', 'shd-performance' ),
					'href'   => wp_nonce_url( add_query_arg( 'shd-perf-view', 'test' === $view ? 'live' : 'test', $current ), 'shd-perf-view' ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'shd-perf',
					'id'     => 'shd-perf-off',
					'title'  => __( 'Show this page without optimizations', 'shd-performance' ),
					'href'   => add_query_arg( 'shd-off', '1', $current ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'shd-perf',
					'id'     => 'shd-perf-psi',
					'title'  => __( 'Test this page in PageSpeed Insights', 'shd-performance' ),
					'href'   => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( remove_query_arg( 'shd-perf-view', $current ) ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}

	public function daily() {
		Rum::prune();
		$uploads = wp_get_upload_dir();
		foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . Css_Rewriter::CACHE_SUBDIR . '/*.css' ) as $file ) {
			if ( is_string( $file ) && filemtime( $file ) < time() - 30 * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	public static function activate() {
		Rum::install();
		Settings::preview_token();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		// Takes over from Kadence Performance once that is deactivated; until then this only records the reason.
		Page_Cache::sync();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Youtube::META_HOOK );
		Page_Cache::teardown( true );
	}
}
