<?php
/**
 * SHD Performance page cache (advanced-cache.php drop-in).
 *
 * Copied to wp-content/advanced-cache.php by the SHD Performance plugin, which
 * also removes it again. WordPress loads it early on every request while
 * WP_CACHE is true: stored pages are sent before WordPress itself starts.
 *
 * @package SHD\Performance
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SHD_PERF_DROPIN' ) ) {
	define( 'SHD_PERF_DROPIN', '2' );

	$shd_perf_config_file = WP_CONTENT_DIR . '/cache/shd-performance/config.php';
	$shd_perf_config      = is_file( $shd_perf_config_file ) ? include $shd_perf_config_file : null;

	if ( is_array( $shd_perf_config ) && isset( $shd_perf_config['handler'], $shd_perf_config['pages'] ) ) {
		if ( is_file( $shd_perf_config['handler'] ) ) {
			if ( ! class_exists( '\SHD\Performance\Page_Cache_Handler', false ) ) {
				require_once $shd_perf_config['handler'];
			}
			\SHD\Performance\Page_Cache_Handler::start( $shd_perf_config );
		} else {
			// The plugin's files are gone: stop the web server from serving pages nobody refreshes, then remove this file.
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename
			if ( is_dir( $shd_perf_config['pages'] ) ) {
				@rename( $shd_perf_config['pages'], dirname( $shd_perf_config['pages'] ) . '/orphaned-' . time() );
			}
			@unlink( $shd_perf_config_file );
			@unlink( __FILE__ );
			// phpcs:enable
		}
	}
	unset( $shd_perf_config_file, $shd_perf_config );
}
