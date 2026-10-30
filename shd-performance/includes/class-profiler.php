<?php
/**
 * "Where the build time goes": measures one uncached page build on this server.
 *
 * The dashboard requests a page with a one-time token (as a first-time visitor,
 * without the page cache). Only that request is measured: the time of every
 * action callback, Elementor element and shortcode is attributed to the plugin
 * or theme it belongs to, and database queries and outgoing HTTP requests are
 * recorded. Nothing is measured or changed for any other request.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Profiler {

	const PARAM  = 'shd-profile';
	const TOKEN  = 'shd_perf_profile_token_';
	const RESULT = 'shd_perf_profile_result_';
	const OPTION = 'shd_perf_profile';

	/** Filters measured like actions (the page content and the template choice). */
	const FILTERS = array( 'the_content', 'get_the_excerpt', 'template_include', 'wp_nav_menu_items' );

	/** Moments that split the request into phases, in the order WordPress reaches them. */
	const MARKS = array( 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded', 'parse_request', 'wp', 'template_redirect', 'template_include', 'wp_head', 'wp_footer', 'shutdown' );

	/** @var string */
	private static $token = '';

	/** @var float Request start (seconds). */
	private static $start = 0.0;

	/** @var array<string,float> Mark => seconds since the start. */
	private static $marks = array();

	/** @var array<string,int> Component => exclusive nanoseconds. */
	private static $components = array();

	/** @var array<string,int> Hook => inclusive nanoseconds. */
	private static $hooks = array();

	/** @var array<string,array> Element name => [component, count, own nanoseconds, nanoseconds including nested elements]. */
	private static $elements = array();

	/** @var array<string,array> Shortcode => [component, count, own nanoseconds, nanoseconds including nested work]. */
	private static $shortcodes = array();

	/** @var int[] Time spent in nested measured calls, per open call. */
	private static $stack = array();

	/** @var array<int,array> Open Elementor elements: [start, depth]. */
	private static $open_elements = array();

	/** @var array<string,bool> */
	private static $wrapped = array();

	/** @var array<int,array> Open outgoing requests. */
	private static $open_http = array();

	/** @var array<int,array> Finished outgoing requests. */
	private static $http = array();

	/** @var array<string,string> Resolved plugin folder => slug (for symlinked plugins). */
	private static $plugin_dirs = array();

	/** @var float */
	private static $last_plugin = 0.0;

	/* ------------------------------------------------------------------
	 * Measuring (inside the requested page)
	 * ------------------------------------------------------------------ */

	/**
	 * Start measuring if this request carries the current token. Called while the plugin loads.
	 */
	public static function maybe_start() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared against a one-time secret.
		$given = isset( $_GET[ self::PARAM ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $given ) {
			return;
		}
		// One key per token: a measurement never shares a cached option with the request that started it.
		if ( ! get_transient( self::TOKEN . md5( $given ) ) ) {
			return;
		}
		delete_transient( self::TOKEN . md5( $given ) );

		self::$token       = $given;
		self::$start       = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		self::$marks['me'] = microtime( true ) - self::$start;
		self::$last_plugin = microtime( true );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$real = realpath( WP_PLUGIN_DIR . '/' . dirname( (string) $plugin ) );
			if ( $real && '.' !== dirname( (string) $plugin ) ) {
				self::$plugin_dirs[ wp_normalize_path( $real ) . '/' ] = dirname( (string) $plugin );
			}
		}

		add_action( 'all', array( __CLASS__, 'on_hook' ) );
		add_action( 'plugin_loaded', array( __CLASS__, 'plugin_loaded' ) );
		add_action( 'elementor/frontend/before_render', array( __CLASS__, 'element_start' ) );
		add_action( 'elementor/frontend/after_render', array( __CLASS__, 'element_end' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'wrap_shortcodes' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( __CLASS__, 'http_start' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'http_end' ), PHP_INT_MAX, 5 );
		add_action( 'shutdown', array( __CLASS__, 'finish' ), PHP_INT_MAX );
	}

	/**
	 * @return bool Whether this request is being measured.
	 */
	public static function active() {
		return '' !== self::$token;
	}

	/**
	 * Runs before every action and filter: note the phase and measure the callbacks of actions.
	 *
	 * @param string $hook Hook name.
	 */
	public static function on_hook( $hook ) {
		if ( in_array( $hook, self::MARKS, true ) && ! isset( self::$marks[ $hook ] ) ) {
			self::$marks[ $hook ] = microtime( true ) - self::$start;
		}
		if ( isset( self::$wrapped[ $hook ] ) ) {
			return;
		}
		// do_action() counts the action before it runs the "all" hook; filters are left alone (too many calls).
		if ( did_action( $hook ) || in_array( $hook, self::FILTERS, true ) ) {
			self::wrap( $hook );
		} else {
			self::$wrapped[ $hook ] = true;
		}
	}

	/**
	 * Replace each callback of a hook (in place, same key) with a timed call of the original.
	 *
	 * @param string $hook Hook name.
	 */
	private static function wrap( $hook ) {
		global $wp_filter;
		self::$wrapped[ $hook ] = true;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $entry ) {
				$original = $entry['function'];
				if ( is_array( $original ) && __CLASS__ === $original[0] ) {
					continue;
				}
				$component = self::component( self::callback_file( $original ) );
				$wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] = static function ( ...$args ) use ( $original, $component, $hook ) {
					$started = self::enter();
					try {
						return call_user_func_array( $original, $args );
					} finally {
						$elapsed                = self::leave( $started, $component );
						self::$hooks[ $hook ] = ( isset( self::$hooks[ $hook ] ) ? self::$hooks[ $hook ] : 0 ) + $elapsed;
					}
				};
			}
		}
	}

	/**
	 * @return int Start time (ns).
	 */
	private static function enter() {
		self::$stack[] = 0;
		return hrtime( true );
	}

	/**
	 * Close a measured call: its own time (without nested measured calls) goes to the component.
	 *
	 * @param int    $started   Start time (ns).
	 * @param string $component Component.
	 * @return int Inclusive time (ns).
	 */
	private static function leave( $started, $component ) {
		$elapsed = hrtime( true ) - $started;
		$nested  = (int) array_pop( self::$stack );
		self::$components[ $component ] = ( isset( self::$components[ $component ] ) ? self::$components[ $component ] : 0 ) + max( 0, $elapsed - $nested );
		if ( ! empty( self::$stack ) ) {
			self::$stack[ count( self::$stack ) - 1 ] += $elapsed;
		}
		return $elapsed;
	}

	/**
	 * Measure work outside hooks, for example SHD Performance's own output optimizer.
	 *
	 * @return int|null Start time, or null when not measuring.
	 */
	public static function begin() {
		return self::active() ? self::enter() : null;
	}

	/**
	 * @param int|null $started   Value from begin().
	 * @param string   $component Component.
	 */
	public static function end( $started, $component ) {
		if ( null !== $started ) {
			self::leave( $started, $component );
		}
	}

	/**
	 * Time to include each plugin loaded after this one.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function plugin_loaded( $plugin ) {
		$now  = microtime( true );
		$slug = dirname( plugin_basename( (string) $plugin ) );
		if ( '.' !== $slug && 'shd-performance' !== $slug ) {
			$key                       = 'plugin:' . $slug;
			self::$components[ $key ] = ( isset( self::$components[ $key ] ) ? self::$components[ $key ] : 0 ) + (int) round( ( $now - self::$last_plugin ) * 1e9 );
		}
		self::$last_plugin = $now;
	}

	/**
	 * @param object $element Elementor element.
	 */
	public static function element_start( $element ) {
		self::$open_elements[] = array( self::enter(), count( self::$stack ) );
	}

	/**
	 * @param object $element Elementor element.
	 */
	public static function element_end( $element ) {
		$open = array_pop( self::$open_elements );
		if ( null === $open ) {
			return;
		}
		// An element that failed to finish leaves nested entries behind: drop them.
		while ( count( self::$stack ) > $open[1] ) {
			array_pop( self::$stack );
		}
		$type      = is_object( $element ) && method_exists( $element, 'get_type' ) ? (string) $element->get_type() : '';
		$name      = 'widget' === $type && method_exists( $element, 'get_name' ) ? (string) $element->get_name() : ( '' !== $type ? $type : 'element' );
		$component = 'widget' === $type ? self::component( self::class_file( $element ) ) : 'plugin:elementor';
		$elapsed   = hrtime( true ) - $open[0];
		$nested    = (int) self::$stack[ count( self::$stack ) - 1 ];
		self::leave( $open[0], $component );
		$own = max( 0, $elapsed - $nested );
		if ( ! isset( self::$elements[ $name ] ) ) {
			self::$elements[ $name ] = array( $component, 0, 0, 0 );
		}
		++self::$elements[ $name ][1];
		self::$elements[ $name ][2] += $own;
		self::$elements[ $name ][3] += $elapsed;
	}

	/**
	 * Shortcodes run inside the content, not as hooks: time each shortcode callback.
	 */
	public static function wrap_shortcodes() {
		global $shortcode_tags;
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$component                = self::component( self::callback_file( $callback ) );
			$shortcode_tags[ $tag ] = static function ( ...$args ) use ( $callback, $component, $tag ) {
				$started = self::enter();
				try {
					return call_user_func_array( $callback, $args );
				} finally {
					$nested  = (int) self::$stack[ count( self::$stack ) - 1 ];
					$elapsed = self::leave( $started, $component );
					if ( ! isset( self::$shortcodes[ $tag ] ) ) {
						self::$shortcodes[ $tag ] = array( $component, 0, 0, 0 );
					}
					++self::$shortcodes[ $tag ][1];
					self::$shortcodes[ $tag ][2] += max( 0, $elapsed - $nested );
					self::$shortcodes[ $tag ][3] += $elapsed;
				}
			};
		}
	}

	/**
	 * @param false|array|\WP_Error $pre  Short-circuit value.
	 * @param array                 $args Request arguments.
	 * @param string                $url  URL.
	 * @return false|array|\WP_Error
	 */
	public static function http_start( $pre, $args, $url ) {
		if ( false === $pre ) {
			self::$open_http[] = array(
				'url'       => self::short_url( (string) $url ),
				'component' => self::caller_component(),
				'start'     => microtime( true ),
			);
		}
		return $pre;
	}

	/**
	 * @param array|\WP_Error $response Response.
	 * @param string          $context  Context.
	 * @param string          $class    Transport.
	 * @param array           $args     Arguments.
	 * @param string          $url      URL.
	 */
	public static function http_end( $response, $context, $class, $args, $url ) {
		$open = array_pop( self::$open_http );
		if ( null === $open || count( self::$http ) >= 30 ) {
			return;
		}
		$open['ms']     = (int) round( ( microtime( true ) - $open['start'] ) * 1000 );
		$open['result'] = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
		unset( $open['start'] );
		self::$http[] = $open;
	}

	/**
	 * At the very end of the request (after the output buffers): store the result for the dashboard.
	 */
	public static function finish() {
		global $wpdb;
		$total = microtime( true ) - self::$start;
		$ms    = static function ( $ns ) {
			return (int) round( $ns / 1e6 );
		};

		$components = array_map( $ms, self::$components );
		arsort( $components );
		$hooks = array_map( $ms, self::$hooks );
		arsort( $hooks );
		$elements = array();
		foreach ( self::$elements as $name => $row ) {
			$elements[] = array(
				'name'      => $name,
				'component' => $row[0],
				'count'     => $row[1],
				'ms'        => $ms( $row[2] ),
				'total_ms'  => $ms( $row[3] ),
			);
		}
		usort(
			$elements,
			static function ( $a, $b ) {
				return $b['ms'] - $a['ms'];
			}
		);
		$shortcodes = array();
		foreach ( self::$shortcodes as $tag => $row ) {
			$shortcodes[] = array(
				'tag'       => $tag,
				'component' => $row[0],
				'count'     => $row[1],
				'ms'        => $ms( $row[2] ),
				'total_ms'  => $ms( $row[3] ),
			);
		}
		usort(
			$shortcodes,
			static function ( $a, $b ) {
				return $b['ms'] - $a['ms'];
			}
		);

		$queries = array();
		$db_ms   = 0.0;
		foreach ( (array) ( isset( $wpdb->queries ) ? $wpdb->queries : array() ) as $query ) {
			if ( ! is_array( $query ) || ! isset( $query[0], $query[1] ) ) {
				continue;
			}
			$db_ms    += (float) $query[1] * 1000;
			$queries[] = array(
				'sql'    => substr( preg_replace( '/\s+/', ' ', (string) $query[0] ), 0, 220 ),
				'ms'     => round( (float) $query[1] * 1000, 1 ),
				'caller' => implode( ', ', array_slice( explode( ', ', isset( $query[2] ) ? (string) $query[2] : '' ), -3 ) ),
			);
		}
		usort(
			$queries,
			static function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);

		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$opcache = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}

		set_transient(
			self::RESULT . self::$token,
			array(
				'total_ms'   => (int) round( $total * 1000 ),
				'status'     => (int) http_response_code(),
				'marks'      => array_map(
					static function ( $seconds ) {
						return (int) round( $seconds * 1000 );
					},
					self::$marks + array( 'end' => $total )
				),
				'components' => array_slice( $components, 0, 15, true ),
				'hooks'      => array_slice( $hooks, 0, 12, true ),
				'elements'   => array_slice( $elements, 0, 15 ),
				'shortcodes' => array_slice( $shortcodes, 0, 10 ),
				'db'         => array(
					'count'   => (int) $wpdb->num_queries,
					'logged'  => count( $queries ),
					'ms'      => (int) round( $db_ms ),
					'slowest' => array_slice( $queries, 0, 6 ),
				),
				'http'       => self::$http,
				'env'        => array(
					'php'          => PHP_VERSION,
					'opcache'      => $opcache,
					'object_cache' => wp_using_ext_object_cache(),
					'memory_mb'    => round( memory_get_peak_usage( true ) / MB_IN_BYTES, 1 ),
				),
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/* Attribution. */

	/**
	 * @param callable $callback Callback.
	 * @return string File that defines it, or ''.
	 */
	private static function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new \ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
			return (string) $reflection->getFileName();
		} catch ( \ReflectionException $error ) {
			return '';
		}
	}

	/**
	 * @param object $object Object.
	 * @return string File that defines its class.
	 */
	private static function class_file( $object ) {
		try {
			return (string) ( new \ReflectionClass( $object ) )->getFileName();
		} catch ( \ReflectionException $error ) {
			return '';
		}
	}

	/**
	 * Plugin, theme or WordPress core, from a file path.
	 *
	 * @param string $file File.
	 * @return string plugin:<folder>, theme:<folder>, mu-plugin:<file> or core.
	 */
	private static function component( $file ) {
		if ( '' === $file ) {
			return 'core';
		}
		$file = wp_normalize_path( $file );
		foreach ( array(
			'plugin:'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin:' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme:'     => wp_normalize_path( get_theme_root() ) . '/',
		) as $prefix => $dir ) {
			if ( 0 === strpos( $file, $dir ) ) {
				return $prefix . strtok( substr( $file, strlen( $dir ) ), '/' );
			}
		}
		foreach ( self::$plugin_dirs as $dir => $slug ) {
			if ( 0 === strpos( $file, $dir ) ) {
				return 'plugin:' . $slug;
			}
		}
		return 'core';
	}

	/**
	 * The first plugin or theme in the call stack (who made an HTTP request).
	 *
	 * @return string
	 */
	private static function caller_component() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- attribution in a measured request only.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$component = self::component( $frame['file'] );
			if ( 'core' !== $component && 'plugin:shd-performance' !== $component ) {
				return $component;
			}
		}
		return 'core';
	}

	/**
	 * @param string $url URL.
	 * @return string Host and path, without the query.
	 */
	private static function short_url( $url ) {
		$parts = wp_parse_url( $url );
		return ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );
	}

	/* ------------------------------------------------------------------
	 * Running a measurement (from the dashboard)
	 * ------------------------------------------------------------------ */

	/**
	 * Build the page twice as a first-time visitor (the first build warms up PHP's caches) and keep the second.
	 *
	 * @param string $url Page on this site.
	 * @return array|\WP_Error Report.
	 */
	public static function run( $url ) {
		$report = null;
		for ( $run = 0; $run < 2; $run++ ) {
			$token = wp_generate_password( 32, false, false );
			set_transient( self::TOKEN . md5( $token ), 1, 2 * MINUTE_IN_SECONDS );
			$started  = microtime( true );
			$response = wp_remote_get(
				add_query_arg( self::PARAM, $token, $url ),
				array(
					'timeout'     => 60,
					'redirection' => 0,
					'user-agent'  => Page_Cache::PRELOAD_UA,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => array( 'Accept' => 'text/html' ),
				)
			);
			$client = (int) round( ( microtime( true ) - $started ) * 1000 );
			delete_transient( self::TOKEN . md5( $token ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			// The page is sent before it stores its measurement (at the very end of the request): wait for it.
			$result = null;
			for ( $wait = 0; $wait < 100 && ! is_array( $result ); $wait++ ) {
				if ( $wait > 0 ) {
					usleep( 100000 );
				}
				$result = self::fresh_transient( self::RESULT . $token );
			}
			delete_transient( self::RESULT . $token );
			if ( ! is_array( $result ) ) {
				return new \WP_Error( 'shd_profile', sprintf( /* translators: %d: HTTP status */ __( 'The page answered with status %d, but the measurement did not run. A security plugin or the host may block requests from the server to itself.', 'shd-performance' ), (int) wp_remote_retrieve_response_code( $response ) ) );
			}
			$result['client_ms'] = $client;
			$report              = $result;
		}
		$report['url']  = $url;
		$report['time'] = time();
		update_option( self::OPTION, $report, false );
		return $report;
	}

	/**
	 * Read a transient written by another request (bypassing this request's option caches).
	 *
	 * @param string $key Transient name.
	 * @return mixed
	 */
	private static function fresh_transient( $key ) {
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		return get_transient( $key );
	}

	/**
	 * Last report.
	 *
	 * @return array|null
	 */
	public static function last() {
		$report = get_option( self::OPTION );
		return is_array( $report ) && isset( $report['total_ms'] ) ? $report : null;
	}

	/**
	 * Phases of the request in milliseconds.
	 *
	 * @param array $report Report.
	 * @return array<int,array{label:string,ms:int}>
	 */
	public static function phases( array $report ) {
		$labels = array(
			'me'                => __( 'Start: WordPress core and the plugins loaded before SHD Performance', 'shd-performance' ),
			'plugins_loaded'    => __( 'Loading the remaining plugins', 'shd-performance' ),
			'setup_theme'       => __( 'Plugins starting up (plugins_loaded)', 'shd-performance' ),
			'after_setup_theme' => __( 'Loading the theme', 'shd-performance' ),
			'init'              => __( 'Theme setup (after_setup_theme)', 'shd-performance' ),
			'wp_loaded'         => __( 'Plugins and theme initializing (init)', 'shd-performance' ),
			'parse_request'     => __( 'After initializing (wp_loaded)', 'shd-performance' ),
			'wp'                => __( 'Reading the address and the main database query', 'shd-performance' ),
			'template_redirect' => __( 'After the query (wp)', 'shd-performance' ),
			'template_include'  => __( 'Before the page is built (template_redirect)', 'shd-performance' ),
			'wp_head'           => __( 'Page: template start, up to wp_head', 'shd-performance' ),
			'wp_footer'         => __( 'Page: <head>, header, content and footer (wp_head to wp_footer)', 'shd-performance' ),
			'shutdown'          => __( 'Page: scripts at the end (wp_footer)', 'shd-performance' ),
			'end'               => __( 'Finishing: SHD Performance optimizes the page, then it is sent', 'shd-performance' ),
		);
		$marks  = isset( $report['marks'] ) ? (array) $report['marks'] : array();
		$phases = array();
		$prev   = 0;
		foreach ( $labels as $mark => $label ) {
			if ( ! isset( $marks[ $mark ] ) ) {
				continue;
			}
			$phases[] = array(
				'label' => $label,
				'ms'    => max( 0, (int) $marks[ $mark ] - $prev ),
			);
			$prev     = (int) $marks[ $mark ];
		}
		return $phases;
	}

	/**
	 * Readable name of a component.
	 *
	 * @param string $component plugin:<folder>, theme:<folder>, mu-plugin:<file> or core.
	 * @return string
	 */
	public static function label( $component ) {
		static $plugins = null;
		if ( 'core' === $component ) {
			return __( 'WordPress core', 'shd-performance' );
		}
		list( $type, $slug ) = array_pad( explode( ':', $component, 2 ), 2, '' );
		if ( 'plugin' === $type ) {
			if ( null === $plugins ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = array();
				foreach ( get_plugins() as $file => $data ) {
					$plugins[ dirname( $file ) ] = $data['Name'];
				}
			}
			return isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : $slug;
		}
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $slug );
			/* translators: %s: theme name */
			return sprintf( __( 'Theme: %s', 'shd-performance' ), $theme->exists() ? $theme->get( 'Name' ) : $slug );
		}
		/* translators: %s: file name */
		return sprintf( __( 'Must-use plugin: %s', 'shd-performance' ), $slug );
	}

	/**
	 * What to do about the biggest items, in plain words.
	 *
	 * @param array $report Report.
	 * @return string[]
	 */
	public static function hints( array $report ) {
		$hints = array();
		$total = max( 1, (int) $report['total_ms'] );

		$http_ms = 0;
		foreach ( (array) $report['http'] as $call ) {
			$http_ms += (int) $call['ms'];
		}
		if ( $http_ms >= 100 ) {
			$first   = reset( $report['http'] );
			$hints[] = sprintf(
				/* translators: 1: count, 2: milliseconds, 3: plugin, 4: address */
				__( 'Requests to other servers: %1$d during this page, %2$d ms in total (the first by %3$s to %4$s). The page waits for every one of them. Ask the plugin’s developer to store the answer (for example for an hour) or to make the request in the background.', 'shd-performance' ),
				count( $report['http'] ),
				$http_ms,
				self::label( $first['component'] ),
				$first['url']
			);
		}

		foreach ( (array) $report['elements'] as $element ) {
			if ( in_array( $element['name'], array( 'loop-grid', 'loop-carousel', 'posts', 'archive-posts' ), true ) && $element['total_ms'] >= 0.15 * $total ) {
				$hints[] = sprintf(
					/* translators: 1: widget, 2: milliseconds, 3: percent */
					__( 'The post list (Elementor widget “%1$s”) takes %2$d ms with the cards inside it (%3$d%% of the page): every post shown is built from its own card template with several widgets. Show fewer posts per page (for example 9 or 12) with page numbers or a “Load more” button: Elementor → edit the template → select the widget → Query: Posts per page, and Pagination.', 'shd-performance' ),
					$element['name'],
					$element['total_ms'],
					(int) round( 100 * $element['total_ms'] / $total )
				);
				break;
			}
		}

		foreach ( (array) $report['shortcodes'] as $shortcode ) {
			// [elementor-element] only wraps widgets from Elementor's Element Cache; their time is in the widget table.
			if ( 'elementor-element' !== $shortcode['tag'] && $shortcode['ms'] >= max( 50, 0.08 * $total ) ) {
				$hints[] = sprintf(
					/* translators: 1: shortcode, 2: plugin, 3: count, 4: milliseconds */
					__( 'The shortcode [%1$s] (%2$s) runs %3$d times and takes %4$d ms. If it computes something (such as the reading time) for every post card, it should store the result with the post instead.', 'shd-performance' ),
					$shortcode['tag'],
					self::label( $shortcode['component'] ),
					$shortcode['count'],
					$shortcode['ms']
				);
			}
		}

		foreach ( (array) $report['components'] as $component => $ms ) {
			if ( 'core' === $component || 'plugin:elementor' === $component || 'plugin:elementor-pro' === $component || $ms < max( 150, 0.15 * $total ) ) {
				continue;
			}
			$hints[] = sprintf(
				/* translators: 1: plugin, 2: milliseconds, 3: percent */
				__( '%1$s takes %2$d ms (%3$d%% of the page). Check whether you still need it on every page, whether a lighter setting exists, or replace it.', 'shd-performance' ),
				self::label( $component ),
				$ms,
				(int) round( 100 * $ms / $total )
			);
		}

		if ( (int) $report['db']['ms'] >= 200 || (int) $report['db']['count'] >= 300 ) {
			$hints[] = sprintf(
				/* translators: 1: query count, 2: milliseconds */
				__( 'Database: %1$d queries, %2$d ms. A persistent object cache (Redis or Memcached, ask your host) keeps most of these answers in memory.', 'shd-performance' ),
				(int) $report['db']['count'],
				(int) $report['db']['ms']
			);
		} elseif ( empty( $report['env']['object_cache'] ) ) {
			$hints[] = __( 'No persistent object cache: ask your host for Redis. It usually makes every uncached page, the admin and form submissions noticeably faster.', 'shd-performance' );
		}
		if ( empty( $report['env']['opcache'] ) ) {
			$hints[] = __( 'PHP OPcache is off: PHP compiles WordPress and every plugin again for each request. Ask your host to switch it on; this alone often halves the time.', 'shd-performance' );
		}
		if ( version_compare( (string) $report['env']['php'], '8.2', '<' ) ) {
			/* translators: %s: PHP version */
			$hints[] = sprintf( __( 'PHP %s: PHP 8.3 or newer is faster. Switch in your hosting panel (test on staging first).', 'shd-performance' ), $report['env']['php'] );
		}
		return $hints;
	}
}
