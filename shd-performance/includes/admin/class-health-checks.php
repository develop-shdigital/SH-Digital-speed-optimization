<?php
/**
 * Automated health checks run from the dashboard.
 *
 * The site requests its own pages the way a phone, a desktop browser, an ad
 * click and an uncached visit would, and inspects server settings.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Health_Checks {

	const OPTION = 'shd_perf_health';

	const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
	const UA_MOBILE  = 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

	/**
	 * Run every check and store the results.
	 *
	 * @return array
	 */
	public static function run() {
		$results = array();
		$home    = home_url( '/' );

		$desktop  = self::fetch( $home, self::UA_DESKTOP );
		$mobile_1 = self::fetch( $home, self::UA_MOBILE );
		$mobile_2 = self::fetch( $home, self::UA_MOBILE );
		$campaign = self::fetch( add_query_arg( array( 'utm_source' => 'shd-check', 'gclid' => 'shd-check' ), $home ), self::UA_MOBILE );
		$fresh    = self::fetch( add_query_arg( 'shd-nocache', wp_generate_password( 8, false, false ), $home ), self::UA_DESKTOP );

		$results['cache_desktop']   = self::cache_result( __( 'Page cache, desktop visitors', 'shd-performance' ), $desktop, 'cache-mobile' );
		$results['cache_mobile']    = self::mobile_result( $mobile_1, $mobile_2 );
		$results['cache_campaign']  = self::cache_result( __( 'Page cache for ad/campaign links (?gclid=, ?utm_…)', 'shd-performance' ), $campaign, 'cache-query-params' );
		$results['ttfb_uncached']   = self::uncached_result( $fresh );
		$results['optimized_cache'] = self::marker_result( $desktop );
		$results['compression']     = self::compression_result();
		$results['ga_duplicate']    = self::ga_result( $desktop );

		$results['php']          = self::php_result();
		$results['opcache']      = self::opcache_result();
		$results['object_cache'] = array(
			'label'  => __( 'Persistent object cache', 'shd-performance' ),
			'status' => wp_using_ext_object_cache() ? 'good' : 'warn',
			'value'  => wp_using_ext_object_cache() ? __( 'active', 'shd-performance' ) : __( 'not active', 'shd-performance' ),
			'detail' => __( 'A persistent object cache (Redis or Memcached, offered by many hosts) speeds up every uncached page, the admin and form submissions.', 'shd-performance' ),
			'task'   => 'server-ttfb',
		);
		$results['autoload']     = self::autoload_result();
		$results['debug']        = self::debug_result();
		$results['font_icons']   = self::font_icons_result();
		$results                 = array_merge( $results, self::kadence_results() );
		$results['redirects']    = self::redirect_result();
		$results['robots']       = self::robots_result();

		$stored = array(
			'time'    => time(),
			'results' => $results,
		);
		update_option( self::OPTION, $stored, false );
		return $stored;
	}

	/**
	 * Last stored results.
	 *
	 * @return array{time:int,results:array}|null
	 */
	public static function last() {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && isset( $stored['results'] ) ? $stored : null;
	}

	/**
	 * @param string $url URL.
	 * @param string $ua  User agent.
	 * @return array{ok:bool,code:int,ms:int,headers:array,body:string,error:string}
	 */
	private static function fetch( $url, $ua ) {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'redirection' => 3,
				'user-agent' => $ua,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'headers'    => array(
					'Accept'          => 'text/html',
					'Accept-Encoding' => 'gzip',
					'Cache-Control'   => 'no-cache',
				),
				'decompress' => true,
				'cookies'    => array(),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'ms'      => $ms,
				'headers' => array(),
				'body'    => '',
				'error'   => $response->get_error_message(),
			);
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'ok'      => 200 === (int) wp_remote_retrieve_response_code( $response ),
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'ms'      => $ms,
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => '',
		);
	}

	/**
	 * Cache status from common response headers or footprints.
	 *
	 * @param array $response Response.
	 * @return string hit|miss|unknown
	 */
	private static function cache_state( array $response ) {
		$headers = $response['headers'];
		foreach ( array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-wp-super-cache', 'x-cache-status', 'x-rocket-nginx-serving-static' ) as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				return preg_match( '/\bhit\b/i', $headers[ $name ] ) ? 'hit' : 'miss';
			}
		}
		if ( isset( $headers['age'] ) && (int) $headers['age'] > 0 ) {
			return 'hit';
		}
		return 'unknown';
	}

	private static function describe( array $response ) {
		if ( ! $response['ok'] ) {
			/* translators: 1: HTTP code, 2: error */
			return sprintf( __( 'request failed (%1$s %2$s)', 'shd-performance' ), $response['code'], $response['error'] );
		}
		$state = self::cache_state( $response );
		$label = 'hit' === $state ? 'HIT' : ( 'miss' === $state ? 'MISS' : __( 'no cache header', 'shd-performance' ) );
		$via   = isset( $response['headers']['x-cached-by'] ) ? ' · ' . $response['headers']['x-cached-by'] : '';
		return sprintf( '%s · %s ms%s', $label, number_format_i18n( $response['ms'] ), $via );
	}

	private static function cache_result( $label, array $response, $task ) {
		$state = self::cache_state( $response );
		return array(
			'label'  => $label,
			'status' => ! $response['ok'] ? 'warn' : ( 'hit' === $state ? 'good' : ( 'miss' === $state ? 'bad' : 'warn' ) ),
			'value'  => self::describe( $response ),
			'detail' => __( 'A cached page is answered in well under a second; a MISS means WordPress builds the page for that visitor (about 2.5 s on this server when measured).', 'shd-performance' ),
			'task'   => $task,
		);
	}

	private static function mobile_result( array $first, array $second ) {
		$state_1 = self::cache_state( $first );
		$state_2 = self::cache_state( $second );
		$status  = 'hit' === $state_1 ? 'good' : ( 'hit' === $state_2 ? 'warn' : 'bad' );
		return array(
			'label'  => __( 'Page cache, phone visitors', 'shd-performance' ),
			'status' => $first['ok'] ? $status : 'warn',
			/* translators: 1: first request, 2: second request */
			'value'  => sprintf( __( 'first request: %1$s — second request: %2$s', 'shd-performance' ), self::describe( $first ), self::describe( $second ) ),
			'detail' => __( 'Google tests the mobile version. If the first phone request is a MISS, the first phone visitor to every page (and often Google’s test) waits for WordPress to build the page.', 'shd-performance' ),
			'task'   => 'cache-mobile',
		);
	}

	private static function uncached_result( array $response ) {
		$ms = $response['ms'];
		return array(
			'label'  => __( 'Time to build an uncached page', 'shd-performance' ),
			'status' => ! $response['ok'] ? 'warn' : ( $ms <= 800 ? 'good' : ( $ms <= 1800 ? 'warn' : 'bad' ) ),
			/* translators: %s: milliseconds */
			'value'  => $response['ok'] ? sprintf( __( '%s ms (homepage, cache bypassed)', 'shd-performance' ), number_format_i18n( $ms ) ) : self::describe( $response ),
			'detail' => __( 'Measured from the server itself, so network time is close to zero. Every cache miss, logged-in editor and form submission waits this long.', 'shd-performance' ),
			'task'   => 'server-ttfb',
		);
	}

	private static function marker_result( array $response ) {
		$found = (bool) preg_match( '/<!-- SHD Performance [^\s]+ \((\w+)\)/', $response['body'], $m );
		return array(
			'label'  => __( 'Cached pages include the optimizations', 'shd-performance' ),
			'status' => ! $response['ok'] ? 'warn' : ( $found ? 'good' : 'bad' ),
			'value'  => $found ? __( 'yes', 'shd-performance' ) : __( 'no — purge the page cache', 'shd-performance' ),
			'detail' => __( 'Pages cached before a change was made still show the old version until the page cache is purged.', 'shd-performance' ),
			'task'   => 'preview-test',
		);
	}

	private static function compression_result() {
		// Headers only: WordPress' HTTP client cannot decode Brotli bodies.
		$response = wp_remote_head(
			home_url( '/' ),
			array(
				'timeout'    => 15,
				'user-agent' => self::UA_DESKTOP,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'headers'    => array( 'Accept-Encoding' => 'br, gzip' ),
			)
		);
		$encoding = is_wp_error( $response ) ? '' : strtolower( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) );
		return array(
			'label'  => __( 'Text compression', 'shd-performance' ),
			'status' => false !== strpos( $encoding, 'br' ) ? 'good' : ( false !== strpos( $encoding, 'gzip' ) ? 'warn' : 'bad' ),
			'value'  => '' !== $encoding ? $encoding : __( 'none detected', 'shd-performance' ),
			'detail' => __( 'Brotli makes HTML, CSS and JavaScript about 15–20% smaller than gzip.', 'shd-performance' ),
			'task'   => 'compression',
		);
	}

	private static function ga_result( array $response ) {
		$body = $response['body'];
		$ids  = array();
		if ( preg_match( '/"v4_id"\s*:\s*"(G-[A-Z0-9]+)"/', $body, $m ) ) {
			$ids['MonsterInsights'] = $m[1];
		}
		if ( preg_match( '/SHDTrackingConfig\s*=\s*\{.*?"ga4"\s*:\s*\{[^}]*"enabled"\s*:\s*true[^}]*"id"\s*:\s*"(G-[A-Z0-9]+)"/s', $body, $m ) ) {
			$ids['SHD Tracking Manager'] = $m[1];
		}
		$duplicate = count( $ids ) > 1 && count( array_unique( $ids ) ) < count( $ids );
		return array(
			'label'  => __( 'Google Analytics 4 loaded once', 'shd-performance' ),
			'status' => $duplicate ? 'warn' : 'good',
			'value'  => $duplicate ? sprintf( '%s: %s', implode( ' + ', array_keys( $ids ) ), reset( $ids ) ) : __( 'no duplicate found', 'shd-performance' ),
			'detail' => __( 'The same GA4 property configured by two plugins costs extra JavaScript and can count page views twice.', 'shd-performance' ),
			'task'   => 'tracking-ga',
		);
	}

	private static function php_result() {
		$version = PHP_VERSION;
		return array(
			'label'  => __( 'PHP version', 'shd-performance' ),
			'status' => version_compare( $version, '8.2', '>=' ) ? 'good' : ( version_compare( $version, '8.0', '>=' ) ? 'warn' : 'bad' ),
			'value'  => $version,
			'detail' => __( 'PHP 8.2 or newer is faster and still receives security updates.', 'shd-performance' ),
			'task'   => 'server-ttfb',
		);
	}

	private static function opcache_result() {
		$enabled = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$enabled = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}
		return array(
			'label'  => __( 'PHP OPcache', 'shd-performance' ),
			'status' => $enabled ? 'good' : 'bad',
			'value'  => $enabled ? __( 'enabled', 'shd-performance' ) : __( 'not enabled', 'shd-performance' ),
			'detail' => __( 'Without OPcache, PHP recompiles WordPress on every request.', 'shd-performance' ),
			'task'   => 'server-ttfb',
		);
	}

	private static function autoload_result() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top   = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY size DESC LIMIT 5", ARRAY_A );
		$names = array();
		foreach ( (array) $top as $row ) {
			$names[] = $row['option_name'] . ' (' . size_format( (int) $row['size'] ) . ')';
		}
		return array(
			'label'  => __( 'Autoloaded options', 'shd-performance' ),
			'status' => $bytes < 800 * KB_IN_BYTES ? 'good' : ( $bytes < 2 * MB_IN_BYTES ? 'warn' : 'bad' ),
			'value'  => size_format( $bytes ),
			/* translators: %s: option names */
			'detail' => sprintf( __( 'Loaded on every request. Largest: %s', 'shd-performance' ), implode( ', ', $names ) ),
			'task'   => 'server-ttfb',
		);
	}

	private static function debug_result() {
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$debug       = defined( 'WP_DEBUG' ) && WP_DEBUG;
		return array(
			'label'  => __( 'Debug settings', 'shd-performance' ),
			'status' => $savequeries ? 'bad' : ( $debug ? 'warn' : 'good' ),
			'value'  => $savequeries ? 'SAVEQUERIES' : ( $debug ? 'WP_DEBUG' : __( 'off', 'shd-performance' ) ),
			'detail' => __( 'WP_DEBUG and especially SAVEQUERIES slow down every request on a live site.', 'shd-performance' ),
			'task'   => 'server-ttfb',
		);
	}

	private static function font_icons_result() {
		$state  = get_option( 'elementor_experiment-e_font_icon_svg', 'default' );
		$active = 'active' === $state;
		return array(
			'label'  => __( 'Elementor “Inline Font Icons”', 'shd-performance' ),
			'status' => $active ? 'good' : 'warn',
			'value'  => $active ? __( 'active', 'shd-performance' ) : (string) $state,
			'detail' => __( 'Inline SVG icons remove the 4 Font Awesome stylesheets and about 170 KB of icon fonts.', 'shd-performance' ),
			'task'   => 'font-icons',
		);
	}

	/**
	 * Kadence Performance (formerly Solid Performance) settings that matter here.
	 *
	 * @return array
	 */
	private static function kadence_results() {
		if ( ! function_exists( 'swpsp_config_get' ) ) {
			return array();
		}
		try {
			$mobile = (bool) swpsp_config_get( 'page_cache.mobile_cache.enabled' );
			$lazy   = (bool) swpsp_config_get( 'page_cache.lazy_loading.enabled' );
		} catch ( \Throwable $error ) {
			return array();
		}
		return array(
			'kadence_mobile_cache' => array(
				'label'  => __( 'Kadence Performance: “Mobile Cache” off', 'shd-performance' ),
				'status' => $mobile ? 'bad' : 'good',
				'value'  => $mobile ? __( 'on (phones get a separate cache that the preloader never fills)', 'shd-performance' ) : __( 'off', 'shd-performance' ),
				'detail' => __( 'The site sends phones and computers the same HTML, so one shared cache is safe and lets the preloader cache pages for phones too.', 'shd-performance' ),
				'task'   => 'cache-mobile',
			),
			'kadence_lazy_images'  => array(
				'label'  => __( 'Kadence Performance: “Lazy Load Images” off', 'shd-performance' ),
				'status' => $lazy ? 'warn' : 'good',
				'value'  => $lazy ? __( 'on', 'shd-performance' ) : __( 'off', 'shd-performance' ),
				'detail' => __( 'This option only affects inline background images (here: the cover images of two videos) and loads them with JavaScript, which delays them when they are in the first screen. It also makes Kadence Performance re-write every page.', 'shd-performance' ),
				'task'   => 'cache-lazy-images',
			),
		);
	}

	private static function redirect_result() {
		$domains = array_filter( array_map( 'trim', (array) Settings::get()['redirect_domains'] ) );
		$home    = home_url( '/' );
		$lines   = array();
		$worst   = 'good';
		foreach ( $domains as $domain ) {
			$url  = 'https://' . preg_replace( '#^https?://#', '', rtrim( $domain, '/' ) ) . '/';
			$hops = 0;
			for ( $i = 0; $i < 5; $i++ ) {
				$response = wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 15 ) );
				if ( is_wp_error( $response ) ) {
					$lines[] = $domain . ': ' . $response->get_error_message();
					$worst   = 'warn';
					continue 2;
				}
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! $location ) {
					break;
				}
				++$hops;
				$url = \WP_Http::make_absolute_url( $location, $url );
			}
			$arrived = untrailingslashit( $url ) === untrailingslashit( $home );
			/* translators: 1: domain, 2: hops */
			$lines[] = sprintf( _n( '%1$s: %2$d redirect', '%1$s: %2$d redirects', $hops, 'shd-performance' ), $domain, $hops ) . ( $arrived ? '' : ' → ' . $url );
			if ( $hops > 1 || ! $arrived ) {
				$worst = 'warn';
			}
		}
		return array(
			'label'  => __( 'Old domains redirect in one step', 'shd-performance' ),
			'status' => empty( $domains ) ? 'info' : $worst,
			'value'  => empty( $domains ) ? __( 'no domains configured', 'shd-performance' ) : implode( ' · ', $lines ),
			'detail' => __( 'Each extra redirect costs a full connection setup before the page starts loading.', 'shd-performance' ),
			'task'   => 'redirects',
		);
	}

	private static function robots_result() {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 15, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
		$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$foreign  = array();
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', $body, $m ) ) {
			foreach ( $m[1] as $url ) {
				if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
					$foreign[] = $url;
				}
			}
		}
		return array(
			'label'  => __( 'robots.txt sitemap lines', 'shd-performance' ),
			'status' => empty( $foreign ) ? 'good' : 'warn',
			'value'  => empty( $foreign ) ? __( 'all point to this domain', 'shd-performance' ) : implode( ', ', $foreign ),
			'detail' => __( 'Sitemap lines should use the canonical domain only.', 'shd-performance' ),
			'task'   => 'robots',
		);
	}
}
