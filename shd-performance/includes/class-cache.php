<?php
/**
 * Purge page caches after settings change.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Cache {

	/**
	 * Purge every page cache we know how to purge, plus our CSS copies.
	 *
	 * @return string[] Names of the caches that were purged.
	 */
	public static function purge_all() {
		$purged = array();

		// Kadence Performance (formerly Solid Performance), the page cache on sh-digital.ch.
		if ( has_action( 'solidwp/performance/purge/all' ) ) {
			do_action( 'solidwp/performance/purge/all' );
			$purged[] = 'Kadence Performance';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'WP Super Cache';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$purged[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
			$purged[] = 'WP Fastest Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'SiteGround Optimizer';
		}

		/**
		 * Lets other cache layers purge too (for example a custom Kadence Performance hook).
		 */
		do_action( 'shd_performance_purge_cache' );

		Css_Rewriter::clear_cache();
		return $purged;
	}
}
