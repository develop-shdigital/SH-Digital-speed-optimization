<?php
/**
 * Real-user Core Web Vitals: collection endpoint, storage and 75th-percentile summaries.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Rum {

	const TABLE      = 'shd_perf_vitals';
	const DB_VERSION = '1';
	const REST_NAMESPACE = 'shd-performance/v1';
	const METRICS    = array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' );

	/**
	 * Core Web Vitals thresholds: good up to the first value, poor above the second.
	 */
	const THRESHOLDS = array(
		'lcp'  => array( 2500, 4000 ),
		'inp'  => array( 200, 500 ),
		'cls'  => array( 0.1, 0.25 ),
		'fcp'  => array( 1800, 3000 ),
		'ttfb' => array( 800, 1800 ),
	);

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			path varchar(190) NOT NULL DEFAULT '',
			device char(1) NOT NULL DEFAULT 'd',
			lcp int(10) unsigned NULL,
			fcp int(10) unsigned NULL,
			ttfb int(10) unsigned NULL,
			inp int(10) unsigned NULL,
			cls decimal(7,4) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY path (path)
			) {$charset};"
		);
		update_option( 'shd_perf_rum_db', self::DB_VERSION, false );
	}

	public static function routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/vitals',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store one page view's metrics.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function collect( $request ) {
		if ( 'off' === Settings::state( 'rum' ) ) {
			return new \WP_REST_Response( null, 204 );
		}
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || empty( $data['m'] ) || ! is_array( $data['m'] ) ) {
			return new \WP_REST_Response( null, 400 );
		}
		if ( ! self::within_rate_limit() ) {
			return new \WP_REST_Response( null, 429 );
		}

		$metrics = $data['m'];
		$path    = isset( $data['p'] ) ? (string) $data['p'] : '/';
		$path    = (string) strtok( $path, '?#' );
		$path    = '/' . ltrim( substr( (string) preg_replace( '#[^\w\-/.%~]#', '', $path ), 0, 189 ), '/' );

		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'path'       => $path,
			'device'     => ( isset( $data['d'] ) && 'm' === $data['d'] ) ? 'm' : 'd',
			'lcp'        => self::milliseconds( $metrics, 'LCP' ),
			'fcp'        => self::milliseconds( $metrics, 'FCP' ),
			'ttfb'       => self::milliseconds( $metrics, 'TTFB' ),
			'inp'        => self::milliseconds( $metrics, 'INP' ),
			'cls'        => ( isset( $metrics['CLS'] ) && is_numeric( $metrics['CLS'] ) && $metrics['CLS'] >= 0 && $metrics['CLS'] <= 20 ) ? round( (float) $metrics['CLS'], 4 ) : null,
		);
		if ( null === $row['lcp'] && null === $row['fcp'] && null === $row['ttfb'] && null === $row['inp'] && null === $row['cls'] ) {
			return new \WP_REST_Response( null, 400 );
		}

		global $wpdb;
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * @param array  $metrics Metrics.
	 * @param string $key     Metric name.
	 * @return int|null
	 */
	private static function milliseconds( array $metrics, $key ) {
		if ( ! isset( $metrics[ $key ] ) || ! is_numeric( $metrics[ $key ] ) ) {
			return null;
		}
		$value = (float) $metrics[ $key ];
		return ( $value >= 0 && $value <= 120000 ) ? (int) round( $value ) : null;
	}

	/**
	 * At most 600 beacons per minute, so the endpoint cannot be used to flood the database.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		$key   = 'shd_perf_rum_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= 600 ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Inline loader that pulls in web-vitals after the page has loaded.
	 *
	 * @return string
	 */
	public static function loader_tag() {
		$config = array(
			'url'    => esc_url_raw( rest_url( self::REST_NAMESPACE . '/vitals' ) ),
			'lib'    => SHD_PERF_URL . 'assets/js/vendor/web-vitals.iife.js?ver=' . SHD_PERF_VERSION,
			'sample' => max( 0, min( 100, (int) Settings::get()['rum_sample'] ) ),
		);
		return '<script id="shd-perf-rum">window.shdPerfRum=' . wp_json_encode( $config ) . ';' . Assets::script( 'rum' ) . "</script>\n";
	}

	/**
	 * 75th percentile per metric and device.
	 *
	 * @param int $days Window.
	 * @return array<string,array>
	 */
	public static function summary( $days = 28 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$out   = array();
		foreach ( array( 'm', 'd' ) as $device ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = array( 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND device = %s", $since, $device ) ) );
			foreach ( self::METRICS as $metric ) {
				$row[ $metric ] = self::p75( $metric, $since, $device );
			}
			$out[ $device ] = $row;
		}
		return $out;
	}

	/**
	 * Pages with enough samples, with their 75th percentiles.
	 *
	 * @param int $days  Window.
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function pages( $days = 28, $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT path, device, COUNT(*) AS samples FROM {$table} WHERE created_at >= %s GROUP BY path, device HAVING samples >= 3 ORDER BY samples DESC LIMIT %d", $since, $limit ), ARRAY_A );
		$rows   = array();
		foreach ( (array) $groups as $group ) {
			$row = $group;
			foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
				$row[ $metric ] = self::p75( $metric, $since, $group['device'], $group['path'] );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param string      $metric Column (whitelisted).
	 * @param string      $since  Datetime.
	 * @param string      $device m|d.
	 * @param string|null $path   Optional path.
	 * @return float|null
	 */
	private static function p75( $metric, $since, $device, $path = null ) {
		if ( ! in_array( $metric, self::METRICS, true ) ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$where = $wpdb->prepare( 'created_at >= %s AND device = %s', $since, $device );
		if ( null !== $path ) {
			$where .= $wpdb->prepare( ' AND path = %s', $path );
		}
		$where .= " AND {$metric} IS NOT NULL";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		if ( 0 === $count ) {
			return null;
		}
		$offset = (int) floor( 0.75 * ( $count - 1 ) );
		$value  = $wpdb->get_var( "SELECT {$metric} FROM {$table} WHERE {$where} ORDER BY {$metric} ASC LIMIT 1 OFFSET {$offset}" );
		// phpcs:enable
		return null === $value ? null : (float) $value;
	}

	/**
	 * @param string     $metric Metric.
	 * @param float|null $value  Value.
	 * @return string good|needs-improvement|poor|none
	 */
	public static function rating( $metric, $value ) {
		if ( null === $value || ! isset( self::THRESHOLDS[ $metric ] ) ) {
			return 'none';
		}
		list( $good, $poor ) = self::THRESHOLDS[ $metric ];
		if ( $value <= $good ) {
			return 'good';
		}
		return $value <= $poor ? 'needs-improvement' : 'poor';
	}

	public static function prune() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}
}
