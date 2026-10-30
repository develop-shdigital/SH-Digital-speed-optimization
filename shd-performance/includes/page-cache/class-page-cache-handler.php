<?php
/**
 * Page cache request handling that runs before WordPress has loaded.
 *
 * Loaded by wp-content/advanced-cache.php (the drop-in written by
 * SHD Performance): serves a stored page when there is one, otherwise records
 * the page WordPress builds and hands it to Page_Cache::store(). It may only
 * use PHP and the WordPress functions that exist when advanced-cache.php runs
 * (the plugin API, no database).
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Page_Cache_Handler {

	/** @var array Settings from wp-content/cache/shd-performance/config.php. */
	private static $config = array();

	/** @var array|null The request being recorded. */
	private static $request = null;

	/** @var string Output recorded so far. */
	private static $html = '';

	/**
	 * Entry point, called by the drop-in.
	 *
	 * @param array $config Cache settings.
	 */
	public static function start( array $config ) {
		self::$config = $config;
		// If the plugin was deactivated without its clean-up, stop serving pages it can no longer refresh.
		add_action( 'init', array( __CLASS__, 'check_plugin' ), 0 );

		if ( defined( 'SHD_PERFORMANCE_DISABLE' ) && SHD_PERFORMANCE_DISABLE ) {
			// Emergency switch in wp-config.php: the web server must stop sending the stored pages too.
			self::retire_pages();
			return;
		}
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		$request = self::request( $config, $_SERVER, $_COOKIE );
		if ( null === $request ) {
			return;
		}
		if ( ! $request['preload'] ) {
			self::serve( $request );
		}
		if ( $request['store'] ) {
			self::record( $request );
		}
	}

	/**
	 * Describe a request the cache may answer or store, or null if it may do neither.
	 *
	 * @param array $config Cache settings.
	 * @param array $server $_SERVER.
	 * @param array $cookie $_COOKIE.
	 * @return array{file:string,store:bool,preload:bool,gzip:bool,head:bool}|null
	 */
	public static function request( array $config, array $server, array $cookie ) {
		if ( 'cli' === PHP_SAPI || defined( 'WP_ADMIN' ) || defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_INSTALLING' ) || defined( 'IFRAME_REQUEST' ) ) {
			return null;
		}
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}
		// Only front-end pages (index.php), not wp-login.php, wp-cron.php and the like.
		if ( isset( $server['SCRIPT_NAME'] ) && 'index.php' !== basename( (string) $server['SCRIPT_NAME'] ) ) {
			return null;
		}
		$uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
		$parts = explode( '?', $uri, 2 );
		$path  = self::key_path( $parts[0] );
		if ( null === $path ) {
			return null;
		}
		// Ad and campaign links (?gclid=…, ?utm_…) may get the cached page, but never store one.
		$store = true;
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			if ( empty( $config['campaign'] ) || ! self::only_campaign_params( $parts[1], (array) $config['campaign_params'] ) ) {
				return null;
			}
			$store = false;
		}
		foreach ( (array) $config['exclude'] as $pattern ) {
			if ( @preg_match( $pattern, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- patterns are validated when saved.
				return null;
			}
		}
		foreach ( array_keys( $cookie ) as $name ) {
			foreach ( (array) $config['bypass_cookies'] as $prefix ) {
				if ( 0 === strpos( (string) $name, $prefix ) ) {
					return null;
				}
			}
		}
		$host = isset( $server['HTTP_HOST'] ) ? strtolower( (string) $server['HTTP_HOST'] ) : '';
		if ( '' === $host || $host !== $config['host'] ) {
			return null;
		}
		$https = ( isset( $server['HTTPS'] ) && ( 'on' === strtolower( (string) $server['HTTPS'] ) || '1' === (string) $server['HTTPS'] ) )
			|| ( isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'] )
			|| ( isset( $server['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] ) );

		return array(
			'file'    => $config['pages'] . '/' . $host . $path . 'index-' . ( $https ? 'https' : 'http' ),
			'store'   => $store,
			'preload' => isset( $server['HTTP_X_SHD_PRELOAD'] ),
			'gzip'    => isset( $server['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( (string) $server['HTTP_ACCEPT_ENCODING'], 'gzip' ),
			'head'    => 'HEAD' === $method,
		);
	}

	/**
	 * The decoded URL path used as the cache folder, or null if the path is not cached.
	 *
	 * Only clean WordPress permalinks ending in a slash: letters (any script),
	 * digits, "-", ".", "_" and "~". The web server's rewrite rules see the
	 * decoded path, so the folders use it too.
	 *
	 * @param string $raw_path Path as requested (percent-encoded).
	 * @return string|null
	 */
	public static function key_path( $raw_path ) {
		if ( strlen( $raw_path ) > 400 || '' === $raw_path || '/' !== $raw_path[0] || '/' !== substr( $raw_path, -1 ) ) {
			return null;
		}
		$path = rawurldecode( $raw_path );
		if ( ! preg_match( '#^/(?:[\p{L}\p{N}_~-][\p{L}\p{N}._~-]*/)*$#u', $path ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * @param string   $query  Query string.
	 * @param string[] $params Marketing parameters.
	 * @return bool
	 */
	private static function only_campaign_params( $query, array $params ) {
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = strtolower( rawurldecode( (string) strstr( $pair . '=', '=', true ) ) );
			if ( ! in_array( $name, $params, true ) && 0 !== strpos( $name, 'utm_' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Send the stored page and stop, if there is a fresh one.
	 *
	 * @param array $request Request.
	 */
	private static function serve( array $request ) {
		$html  = $request['file'] . '.html';
		$mtime = @filemtime( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is a normal miss.
		if ( ! $mtime || $mtime + (int) self::$config['ttl'] < time() ) {
			return;
		}
		$gzip = $request['gzip'] && ! ini_get( 'zlib.output_compression' ) && is_file( $html . '.gz' );
		$file = $gzip ? $html . '.gz' : $html;
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size ) {
			return;
		}

		header( 'Content-Type: text/html; charset=' . self::$config['charset'] );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'Cache-Control: max-age=0, public' );
		header( 'Vary: Accept-Encoding' );
		header( 'X-SHD-Cache: HIT (php)' );
		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			if ( $since && $since >= $mtime ) {
				http_response_code( 304 );
				exit;
			}
		}
		if ( $gzip ) {
			header( 'Content-Encoding: gzip' );
		}
		header( 'Content-Length: ' . $size );
		if ( ! $request['head'] ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- cached page.
		}
		exit;
	}

	/**
	 * Record what WordPress sends; the plugin decides at the end whether to store it.
	 *
	 * @param array $request Request.
	 */
	private static function record( array $request ) {
		self::$request = $request;
		header( 'X-SHD-Cache: MISS' );
		ob_start( array( __CLASS__, 'collect' ) );
	}

	/**
	 * Output-buffer callback: passes everything through unchanged.
	 *
	 * @param string $chunk Output.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public static function collect( $chunk, $phase ) {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			// Discarded output (ob_clean): only what is printed afterwards is the page.
			self::$html = '';
			if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
				self::$request = null;
			}
			return '';
		}
		self::$html .= $chunk;
		if ( ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && null !== self::$request ) {
			$request       = self::$request;
			self::$request = null;
			if ( class_exists( __NAMESPACE__ . '\Page_Cache', false ) ) {
				try {
					Page_Cache::store( self::$html, $request['file'] );
				} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the visitor still gets the page.
				}
			}
			self::$html = '';
		}
		return $chunk;
	}

	/**
	 * On `init`: if the plugin is no longer active, stop serving pages and remove the drop-in.
	 */
	public static function check_plugin() {
		if ( defined( 'SHD_PERF_VERSION' ) || ( function_exists( 'wp_installing' ) && wp_installing() ) ) {
			return;
		}
		// Still active but not loaded (recovery mode after an error): keep everything.
		if ( in_array( self::$config['plugin'], (array) get_option( 'active_plugins', array() ), true ) ) {
			return;
		}
		self::retire_pages();
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort clean-up before WordPress' file API is loaded.
		@unlink( dirname( self::$config['pages'] ) . '/config.php' );
		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( is_file( $dropin ) && false !== strpos( (string) @file_get_contents( $dropin, false, null, 0, 600 ), 'SHD Performance page cache' ) ) {
			@unlink( $dropin );
		}
		// phpcs:enable
	}

	/**
	 * Move the stored pages out of reach: the web server's rewrite rules only send files under pages/.
	 */
	private static function retire_pages() {
		if ( isset( self::$config['pages'] ) && is_dir( self::$config['pages'] ) ) {
			@rename( self::$config['pages'], dirname( self::$config['pages'] ) . '/orphaned-' . time() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		}
	}
}
