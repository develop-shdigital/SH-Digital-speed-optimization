<?php
/**
 * Consent log: proof of each choice (when, which categories, which banner version).
 * Stores a random consent ID, no IP address and no user agent.
 *
 * @package SHD\Consent
 */

namespace SHD\Consent;

defined( 'ABSPATH' ) || exit;

final class Log {

	const TABLE      = 'shd_consent_log';
	const DB_VERSION = '1';
	const DB_OPTION  = 'shd_consent_db';
	const CRON       = 'shd_consent_daily';
	const ROUTE      = 'shd-consent/v1';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::CRON, array( __CLASS__, 'prune' ) );
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			consent_id varchar(40) NOT NULL DEFAULT '',
			version varchar(20) NOT NULL DEFAULT '',
			functional tinyint(1) NOT NULL DEFAULT 0,
			performance tinyint(1) NOT NULL DEFAULT 0,
			advertising tinyint(1) NOT NULL DEFAULT 0,
			method varchar(12) NOT NULL DEFAULT '',
			path varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY consent_id (consent_id)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function routes() {
		register_rest_route(
			self::ROUTE,
			'/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store one choice. The body is JSON: {id, v, c: {functional, performance, advertising}, m, p}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function collect( $request ) {
		if ( empty( Settings::get()['log'] ) ) {
			return new \WP_REST_Response( null, 204 );
		}
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) || ! isset( $data['c'] ) || ! is_array( $data['c'] ) ) {
			return new \WP_REST_Response( null, 400 );
		}
		$id = (string) $data['id'];
		if ( ! preg_match( '/^[a-f0-9-]{8,40}$/i', $id ) ) {
			return new \WP_REST_Response( null, 400 );
		}
		if ( ! self::within_rate_limit() ) {
			return new \WP_REST_Response( null, 429 );
		}
		$path = isset( $data['p'] ) ? (string) strtok( (string) $data['p'], '?#' ) : '/';
		$path = '/' . ltrim( substr( (string) preg_replace( '#[^\w\-/.%~]#', '', $path ), 0, 189 ), '/' );
		$method = isset( $data['m'] ) && in_array( $data['m'], array( 'all', 'necessary', 'custom' ), true ) ? $data['m'] : 'custom';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'consent_id'  => strtolower( $id ),
				'version'     => substr( sanitize_text_field( isset( $data['v'] ) ? (string) $data['v'] : '' ), 0, 20 ),
				'functional'  => ! empty( $data['c']['functional'] ) ? 1 : 0,
				'performance' => ! empty( $data['c']['performance'] ) ? 1 : 0,
				'advertising' => ! empty( $data['c']['advertising'] ) ? 1 : 0,
				'method'      => $method,
				'path'        => $path,
			)
		);
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * At most 600 entries per minute, so the endpoint cannot be used to fill the database.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		$key   = 'shd_consent_rate_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= 600 ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Daily: delete entries older than the retention period.
	 */
	public static function prune() {
		global $wpdb;
		$months = max( 1, (int) Settings::get()['log_months'] );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) ) ) );
	}

	/**
	 * @param int    $page     Page (1-based).
	 * @param int    $per_page Rows per page.
	 * @param string $search   Consent ID to look for.
	 * @return array{rows:array,total:int}
	 */
	public static function query( $page, $per_page, $search = '' ) {
		global $wpdb;
		$table = self::table();
		$where = '' !== $search ? $wpdb->prepare( 'WHERE consent_id LIKE %s', $wpdb->esc_like( strtolower( $search ) ) . '%' ) : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Shares of choices over the last 30 days.
	 *
	 * @return array{total:int,all:int,necessary:int,custom:int}
	 */
	public static function summary() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT method, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY method", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array(
			'total'     => 0,
			'all'       => 0,
			'necessary' => 0,
			'custom'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['method'] ] ) ) {
				$out[ $row['method'] ] = (int) $row['n'];
			}
			$out['total'] += (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Send the whole log as CSV.
	 */
	public static function export_csv() {
		global $wpdb;
		$table = self::table();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'time_utc', 'consent_id', 'banner_version', 'necessary', 'functional', 'statistics', 'marketing', 'choice', 'page' ) );
		$last = PHP_INT_MAX;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT 2000", $last ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				fputcsv( $out, array( $row['created_at'], $row['consent_id'], $row['version'], 1, $row['functional'], $row['performance'], $row['advertising'], $row['method'], $row['path'] ) );
				$last = (int) $row['id'];
			}
		} while ( ! empty( $rows ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
