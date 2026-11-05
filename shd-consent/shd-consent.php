<?php
/**
 * Plugin Name:       SHD Consent
 * Plugin URI:        https://www.sh-digital.ch/
 * Description:       Own cookie consent banner: no third-party service, no page-view limits. Opt-in by category, Google Consent Mode v2, blocks tracking scripts until consent, consent log, and compatible with scripts written for CookieFirst.
 * Version:           1.0.1
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            SH Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shd-consent
 *
 * @package SHD\Consent
 */

defined( 'ABSPATH' ) || exit;

define( 'SHD_CONSENT_VERSION', '1.0.1' );
define( 'SHD_CONSENT_FILE', __FILE__ );
define( 'SHD_CONSENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHD_CONSENT_URL', plugin_dir_url( __FILE__ ) );

require_once SHD_CONSENT_DIR . 'includes/class-settings.php';
require_once SHD_CONSENT_DIR . 'includes/class-blocker.php';
require_once SHD_CONSENT_DIR . 'includes/class-log.php';
require_once SHD_CONSENT_DIR . 'includes/class-frontend.php';

if ( is_admin() ) {
	require_once SHD_CONSENT_DIR . 'includes/admin/class-admin.php';
}

register_activation_hook( __FILE__, array( 'SHD\Consent\Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'SHD\Consent\Log', 'unschedule' ) );

SHD\Consent\Log::register();
SHD\Consent\Frontend::register();
if ( is_admin() ) {
	SHD\Consent\Admin::register();
}
