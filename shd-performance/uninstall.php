<?php
/**
 * Removes everything SHD Performance stored: options, the Web Vitals table,
 * transients, scheduled events, the files in uploads/shd-performance
 * (rewritten CSS copies and uploaded WOFF2 fonts) and the page cache folder.
 *
 * @package SHD\Performance
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'shd_perf_settings', 'shd_perf_checklist', 'shd_perf_health', 'shd_perf_scan', 'shd_perf_rum_db', 'shd_perf_last_error', 'shd_perf_cache_status', 'shd_perf_cache_queue', 'shd_perf_cache_key', 'shd_perf_profile' ) as $shd_perf_option ) {
	delete_option( $shd_perf_option );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shd_perf_vitals" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_shd\\_perf\\_%' OR option_name LIKE '\\_transient\\_timeout\\_shd\\_perf\\_%'" );
// phpcs:enable

wp_clear_scheduled_hook( 'shd_perf_daily' );
wp_clear_scheduled_hook( 'shd_perf_youtube_meta' );
wp_clear_scheduled_hook( 'shd_perf_cache_maintain' );
wp_clear_scheduled_hook( 'shd_perf_cache_preload' );

// Deactivation already removed the page cache (drop-in, .htaccess rules, stored pages); this catches leftovers.
$shd_perf_uploads = wp_get_upload_dir();
foreach ( array( trailingslashit( $shd_perf_uploads['basedir'] ) . 'shd-performance', WP_CONTENT_DIR . '/cache/shd-performance' ) as $shd_perf_dir ) {
	if ( is_dir( $shd_perf_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $shd_perf_dir, true );
		}
	}
}
