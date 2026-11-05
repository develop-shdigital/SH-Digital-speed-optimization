<?php
/**
 * Removes the settings, the consent log table and the scheduled clean-up.
 *
 * @package SHD\Consent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'shd_consent_settings' );
delete_option( 'shd_consent_db' );
delete_option( 'shd_consent_stopped' );
wp_clear_scheduled_hook( 'shd_consent_daily' );
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shd_consent_log" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_shd\\_consent\\_%' OR option_name LIKE '\\_transient\\_timeout\\_shd\\_consent\\_%'" );
// phpcs:enable
