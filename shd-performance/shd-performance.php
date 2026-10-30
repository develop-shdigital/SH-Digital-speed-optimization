<?php
/**
 * Plugin Name:       SHD Performance
 * Plugin URI:        https://www.sh-digital.ch/
 * Description:       Speed and Core Web Vitals optimizations and a page cache for the SH Digital Elementor site, with a dashboard checklist for the steps that need a person.
 * Version:           1.2.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            SH Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shd-performance
 *
 * @package SHD\Performance
 */

defined( 'ABSPATH' ) || exit;

define( 'SHD_PERF_VERSION', '1.2.0' );
define( 'SHD_PERF_FILE', __FILE__ );
define( 'SHD_PERF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHD_PERF_URL', plugin_dir_url( __FILE__ ) );

require_once SHD_PERF_DIR . 'includes/class-settings.php';
require_once SHD_PERF_DIR . 'includes/class-request.php';
require_once SHD_PERF_DIR . 'includes/class-assets.php';
require_once SHD_PERF_DIR . 'includes/class-font-metrics.php';
require_once SHD_PERF_DIR . 'includes/class-fonts.php';
require_once SHD_PERF_DIR . 'includes/class-css-rewriter.php';
require_once SHD_PERF_DIR . 'includes/class-html-optimizer.php';
require_once SHD_PERF_DIR . 'includes/class-toc.php';
require_once SHD_PERF_DIR . 'includes/class-youtube.php';
require_once SHD_PERF_DIR . 'includes/class-rum.php';
require_once SHD_PERF_DIR . 'includes/class-cache.php';
require_once SHD_PERF_DIR . 'includes/class-page-cache.php';
require_once SHD_PERF_DIR . 'includes/class-profiler.php';
require_once SHD_PERF_DIR . 'includes/class-plugin.php';

if ( is_admin() ) {
	require_once SHD_PERF_DIR . 'includes/admin/class-health-checks.php';
	require_once SHD_PERF_DIR . 'includes/admin/class-site-scanner.php';
	require_once SHD_PERF_DIR . 'includes/admin/class-checklist.php';
	require_once SHD_PERF_DIR . 'includes/admin/class-admin.php';
}

register_activation_hook( __FILE__, array( 'SHD\Performance\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SHD\Performance\Plugin', 'deactivate' ) );

// Only for a page the dashboard measures (one-time token); does nothing otherwise.
SHD\Performance\Profiler::maybe_start();
SHD\Performance\Plugin::instance()->boot();
