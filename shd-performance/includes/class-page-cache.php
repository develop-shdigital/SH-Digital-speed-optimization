<?php
/**
 * Page cache: stores each finished (optimized) page as a static file and sends
 * it to the next visitors without starting WordPress, through .htaccess rules
 * on Apache and the advanced-cache.php drop-in everywhere else. Replaces the
 * page cache of Kadence Performance.
 *
 * Works together with Elementor's own "Element Cache", which only keeps the
 * rendered widgets in the database (WordPress still starts for every visit):
 * pages are cleared when Elementor saves a document or clears its files, so a
 * stored page never links to Elementor CSS files that were just deleted.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Page_Cache {

	const MARKER        = 'SHD Performance page cache';
	const HTACCESS_TAG  = 'SHD Performance';
	const WP_CACHE_NOTE = '// Added by SHD Performance (page cache)';
	const STATUS_OPT    = 'shd_perf_cache_status';
	const QUEUE_OPT     = 'shd_perf_cache_queue';
	const KEY_OPT       = 'shd_perf_cache_key';
	const LOCK          = 'shd_perf_cache_lock';
	const MAINTAIN_HOOK = 'shd_perf_cache_maintain';
	const PRELOAD_HOOK  = 'shd_perf_cache_preload';
	const AJAX_ACTION   = 'shd_perf_cache_preload';
	const BATCH         = 10;
	const PRELOAD_UA    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/** Visitors with one of these cookies get WordPress' own page: logged in, post password, comment author, shop cart, Test view. */
	const BYPASS_COOKIES = array( 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'shd_perf_view' );

	/** Marketing parameters (and every utm_*) that may get the cached page when that option is on. */
	const CAMPAIGN_PARAMS = array( 'gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', 'dclid', 'fbclid', 'msclkid', 'li_fat_id', 'ttclid', 'twclid', 'mc_cid', 'mc_eid', '_gl', 'srsltid' );

	/** Post types that are not pages themselves but change how pages look (Elementor templates and site settings, blocks, Additional CSS). */
	const LAYOUT_TYPES = array( 'elementor_library', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'e-floating-buttons', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css' );

	/** Plugins with their own page cache; only one page cache may run. */
	const OTHER_CACHES = array(
		'wp-rocket/wp-rocket.php'                     => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'           => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                 => 'WP Super Cache',
		'litespeed-cache/litespeed-cache.php'         => 'LiteSpeed Cache',
		'wp-fastest-cache/wpFastestCache.php'         => 'WP Fastest Cache',
		'sg-cachepress/sg-cachepress.php'             => 'SiteGround Optimizer',
		'breeze/breeze.php'                           => 'Breeze',
		'cache-enabler/cache-enabler.php'             => 'Cache Enabler',
		'comet-cache/comet-cache.php'                 => 'Comet Cache',
		'hummingbird-performance/wp-hummingbird.php'  => 'Hummingbird',
		'wp-optimize/wp-optimize.php'                 => 'WP-Optimize',
		'flying-press/flying-press.php'               => 'FlyingPress',
		'nitropack/main.php'                          => 'NitroPack',
		'swift-performance-lite/performance.php'      => 'Swift Performance',
		'powered-cache/powered-cache.php'             => 'Powered Cache',
		'simple-cache/simple-cache.php'               => 'Simple Cache',
	);

	/** @var bool */
	private static $purge_pending = false;

	/** @var bool */
	private static $preload_pending = false;

	/** @var bool */
	private static $sync_pending = false;

	/* ------------------------------------------------------------------
	 * Setup
	 * ------------------------------------------------------------------ */

	public static function register() {
		// Without the drop-in (WP_CACHE off), the plugin records the pages itself; .htaccess still serves them.
		// config.php only exists while the cache is active (Settings are not read here: too early for translations).
		if ( ! defined( 'SHD_PERF_DROPIN' ) ) {
			$config = self::config();
			if ( null !== $config && ! empty( $config['enabled'] ) ) {
				self::load_handler();
				Page_Cache_Handler::start( $config );
			}
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_sync' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'plugins_changed' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugins_changed' ) );
		add_action( self::MAINTAIN_HOOK, array( __CLASS__, 'maintain' ) );
		add_action( self::PRELOAD_HOOK, array( __CLASS__, 'preload' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'preload_request' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'preload_request' ) );

		foreach ( array( 'update_option_' . Settings::OPTION, 'update_option_home', 'update_option_siteurl', 'update_option_permalink_structure' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'sync_later' ) );
		}

		// Content changes: every stored page may show the changed content (menus, post lists, related posts).
		add_action( 'transition_post_status', array( __CLASS__, 'post_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ) );
		add_action( 'wp_update_comment_count', array( __CLASS__, 'comments_changed' ) );
		add_action( 'edit_comment', array( __CLASS__, 'comment_edited' ) );
		add_action( 'updated_option', array( __CLASS__, 'option_changed' ) );
		// Elementor: a saved document or template gets new CSS files and drops its Element Cache entry.
		add_action( 'elementor/document/after_save', array( __CLASS__, 'elementor_saved' ) );
		$purge = array(
			// Elementor's "Clear Files & Data" deletes every generated CSS file that stored pages link to.
			'elementor/core/files/clear_cache',
			'update_option_elementor_pro_theme_builder_conditions',
			'wp_update_nav_menu',
			'wp_delete_nav_menu',
			'customize_save_after',
			'switch_theme',
			'upgrader_process_complete',
			'activated_plugin',
			'deactivated_plugin',
		);
		foreach ( array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure', 'category_base', 'tag_base', 'date_format', 'time_format', 'WPLANG', 'site_icon', 'sidebars_widgets', 'home', 'siteurl' ) as $option ) {
			$purge[] = 'update_option_' . $option;
		}
		foreach ( $purge as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge_later' ) );
		}
	}

	/**
	 * Whether the page cache is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( Settings::get()['page_cache'] ) && ! is_multisite() && ! ( defined( 'SHD_PERFORMANCE_DISABLE' ) && SHD_PERFORMANCE_DISABLE );
	}

	/**
	 * Whether pages are being stored and served right now.
	 *
	 * @return bool
	 */
	public static function serving() {
		return self::enabled() && 'active' === self::status()['state'];
	}

	/**
	 * Last installation result.
	 *
	 * @return array{time:int,signature:string,version:string,state:string,delivery:string,blocker:string,notes:string[]}
	 */
	public static function status() {
		$status = get_option( self::STATUS_OPT );
		return array_merge(
			array(
				'time'      => 0,
				'signature' => '',
				'version'   => '',
				'state'     => 'off',
				'delivery'  => '',
				'blocker'   => '',
				'notes'     => array(),
			),
			is_array( $status ) ? $status : array()
		);
	}

	/* Paths. */

	/**
	 * @return string wp-content/cache/shd-performance
	 */
	public static function dir() {
		return WP_CONTENT_DIR . '/cache/shd-performance';
	}

	/**
	 * @return string
	 */
	public static function pages_dir() {
		return self::dir() . '/pages';
	}

	/**
	 * @return string
	 */
	private static function config_file() {
		return self::dir() . '/config.php';
	}

	/**
	 * @return string
	 */
	private static function dropin_file() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * @return string
	 */
	private static function htaccess_file() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return get_home_path() . '.htaccess';
	}

	/**
	 * Host of the site address as it appears in requests (lower case, with a non-standard port).
	 *
	 * @return string Empty if unusable.
	 */
	public static function host() {
		$parts = wp_parse_url( home_url() );
		$host  = strtolower( rtrim( isset( $parts['host'] ) ? (string) $parts['host'] : '', '.' ) );
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		return preg_match( '/^[a-z0-9.-]+(?::\d+)?$/', $host ) ? $host : '';
	}

	/**
	 * Lifetime of a stored page in seconds.
	 *
	 * The pages contain security tokens (nonces) for forms and AJAX requests,
	 * which WordPress accepts for 12 to 24 hours, so a page lives at most 12 hours.
	 *
	 * @return int
	 */
	public static function ttl() {
		return max( 1, min( 12, (int) Settings::get()['cache_ttl'] ) ) * HOUR_IN_SECONDS;
	}

	/**
	 * Base path of the stored files for a URL (without ".html"), or null if it is not cached.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public static function file_for( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['query'] ) ) {
			return null;
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) ) . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
		if ( $host !== self::host() ) {
			return null;
		}
		self::load_handler();
		$path = Page_Cache_Handler::key_path( isset( $parts['path'] ) ? $parts['path'] : '/' );
		if ( null === $path ) {
			return null;
		}
		foreach ( self::exclude_patterns() as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return null;
			}
		}
		return self::pages_dir() . '/' . $host . $path . 'index-' . ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ? 'http' : 'https' );
	}

	/**
	 * The request handler is usually loaded already, by the drop-in (possibly from an
	 * older copy of the plugin): never declare it twice.
	 */
	private static function load_handler() {
		if ( ! class_exists( __NAMESPACE__ . '\Page_Cache_Handler', false ) ) {
			require_once SHD_PERF_DIR . 'includes/page-cache/class-page-cache-handler.php';
		}
	}

	/**
	 * Settings for the drop-in, as written to config.php.
	 *
	 * @return array|null
	 */
	private static function config() {
		$file = self::config_file();
		if ( ! is_file( $file ) ) {
			return null;
		}
		$config = include $file;
		return is_array( $config ) ? $config : null;
	}

	/**
	 * Exclusions as regular expressions on the URL path.
	 *
	 * @return string[]
	 */
	private static function exclude_patterns() {
		$patterns = array();
		foreach ( (array) Settings::get()['cache_exclude'] as $line ) {
			$path = trim( rawurldecode( (string) wp_parse_url( trim( (string) $line ), PHP_URL_PATH ) ) );
			if ( '' === $path ) {
				continue;
			}
			$pattern = '#^' . str_replace( '\*', '.*', preg_quote( '/' . ltrim( $path, '/' ), '#' ) ) . '#u';
			if ( false !== @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validates the pattern.
				$patterns[] = $pattern;
			}
		}
		return $patterns;
	}

	/* ------------------------------------------------------------------
	 * Storing pages (called by the request handler at the end of a request)
	 * ------------------------------------------------------------------ */

	/**
	 * Store the page if it may be shown to every visitor.
	 *
	 * @param string $html Complete response body.
	 * @param string $file Base path from the request handler.
	 */
	public static function store( $html, $file ) {
		if ( ! self::storable( $html ) || 0 !== strpos( $file, self::pages_dir() . '/' ) ) {
			return;
		}
		// Cleared while this page was being built: it may show the content from before the change.
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && (float) $_SERVER['REQUEST_TIME_FLOAT'] <= self::purged_at() ) {
			return;
		}
		if ( ! is_file( self::pages_dir() . '/.htaccess' ) && ! self::ensure_dirs() ) {
			return;
		}
		if ( ! wp_mkdir_p( dirname( $file ) ) ) {
			return;
		}
		$html .= "\n<!-- " . self::MARKER . ', ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
		$gzip  = function_exists( 'gzencode' ) ? gzencode( $html, 6 ) : false;
		if ( false === $gzip || ! self::write( $file . '.html.gz', $gzip ) ) {
			wp_delete_file( $file . '.html.gz' );
		}
		self::write( $file . '.html', $html );
	}

	/**
	 * @param string $html Response body.
	 * @return bool
	 */
	private static function storable( $html ) {
		if ( ! self::serving() || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || ! did_action( 'template_redirect' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- only checks whether there is input.
		if ( is_admin() || is_user_logged_in() || 'live' !== Request::view() || ! empty( $_GET ) || ! empty( $_POST ) || 200 !== http_response_code() ) {
			return false;
		}
		if ( is_404() || is_search() || is_feed() || is_preview() || is_robots() || is_trackback() || is_embed() || is_customize_preview() || ( is_singular() && post_password_required() ) ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			$name  = strtolower( trim( (string) strstr( $header, ':', true ) ) );
			$value = ltrim( (string) substr( (string) strstr( $header, ':' ), 1 ) );
			// Responses that are not HTML, redirects, and pages marked noindex by header (the header would be lost).
			if ( ( 'content-type' === $name && false === stripos( $value, 'text/html' ) ) || 'location' === $name || 'x-robots-tag' === $name ) {
				return false;
			}
			if ( 'set-cookie' === $name ) {
				foreach ( self::BYPASS_COOKIES as $prefix ) {
					if ( 0 === stripos( $value, $prefix ) ) {
						return false;
					}
				}
			}
		}
		if ( ! Html_Optimizer::is_document( $html ) || false === stripos( substr( $html, -4096 ), '</html>' ) ) {
			return false;
		}
		/**
		 * Filters whether the current page is stored in the page cache.
		 *
		 * @param bool $store Whether to store it.
		 */
		return (bool) apply_filters( 'shd_performance_cache_page', true );
	}

	/**
	 * Write a file atomically (other requests never see a half-written file).
	 *
	 * @param string $file Path.
	 * @param string $data Content.
	 * @return bool
	 */
	private static function write( $file, $data ) {
		$temp = $file . '.' . uniqid( '', true ) . '.tmp';
		// phpcs:disable WordPress.WP.AlternativeFunctions -- cache files, written before WP_Filesystem is available.
		if ( false === @file_put_contents( $temp, $data ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		@chmod( $temp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		// phpcs:enable
		if ( '.php' === substr( $file, -4 ) && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Clearing
	 * ------------------------------------------------------------------ */

	/**
	 * Delete every stored page and refill the cache in the background.
	 *
	 * @return bool Whether the page cache is in use.
	 */
	public static function purge() {
		self::$purge_pending = false;
		if ( ! is_dir( self::pages_dir() ) && ! self::serving() ) {
			return false;
		}
		if ( ! wp_mkdir_p( self::dir() ) ) {
			return false;
		}
		self::write( self::dir() . '/purged', (string) microtime( true ) );
		$pages = self::pages_dir();
		if ( is_dir( $pages ) ) {
			// Renaming is instant: the web server stops finding the old pages at once.
			$trash = self::dir() . '/trash-' . uniqid();
			if ( @rename( $pages, $trash ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				self::ensure_dirs();
				self::delete_tree( $trash );
			} else {
				foreach ( self::files() as $file ) {
					wp_delete_file( $file );
				}
			}
		}
		if ( ! self::serving() ) {
			return false;
		}
		self::ensure_dirs();
		self::preload_later();
		return true;
	}

	/**
	 * Clear everything once, at the end of this request (several changes often come together).
	 */
	public static function purge_later() {
		if ( self::$purge_pending || ! is_dir( self::pages_dir() ) ) {
			return;
		}
		self::$purge_pending = true;
		add_action( 'shutdown', array( __CLASS__, 'purge' ), 5 );
	}

	/**
	 * Delete the stored copies of one URL.
	 *
	 * @param string $url URL.
	 */
	public static function delete_url( $url ) {
		$file = self::file_for( $url );
		if ( null === $file ) {
			return;
		}
		foreach ( array( '.html', '.html.gz' ) as $ext ) {
			foreach ( array( 'https', 'http' ) as $scheme ) {
				$path = preg_replace( '/index-https?$/', 'index-' . $scheme, $file ) . $ext;
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function post_changed( $new_status, $old_status, $post ) {
		if ( ( 'publish' === $new_status || 'publish' === $old_status ) && self::affects_pages( $post ) ) {
			self::purge_later();
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function post_deleted( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && self::affects_pages( $post ) ) {
			self::purge_later();
		}
	}

	/**
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function affects_pages( $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		/**
		 * Filters the post types whose changes clear the page cache, besides the public ones.
		 *
		 * @param string[] $types Post types.
		 */
		$layout  = (array) apply_filters( 'shd_performance_cache_layout_post_types', self::LAYOUT_TYPES );
		$affects = in_array( $post->post_type, $layout, true ) || is_post_type_viewable( $post->post_type );
		/**
		 * Filters whether a change to this post clears the page cache.
		 *
		 * @param bool     $affects Whether it clears the cache.
		 * @param \WP_Post $post    Post.
		 */
		return (bool) apply_filters( 'shd_performance_cache_purge_on_post', $affects, $post );
	}

	/**
	 * Elementor saved a document. Autosaves and drafts (saved while someone is still editing) change nothing visitors see.
	 *
	 * @param object $document Elementor document.
	 */
	public static function elementor_saved( $document ) {
		$post = is_object( $document ) && method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && self::affects_pages( $post ) ) {
			self::purge_later();
		}
	}

	/**
	 * A post's approved comments changed: only that page shows them.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function comments_changed( $post_id ) {
		$url = get_permalink( $post_id );
		if ( $url ) {
			self::delete_url( $url );
		}
	}

	/**
	 * @param int $comment_id Comment ID.
	 */
	public static function comment_edited( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && '1' === (string) $comment->comment_approved ) {
			self::comments_changed( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Classic widgets are stored in options named widget_*.
	 *
	 * @param string $option Option name.
	 */
	public static function option_changed( $option ) {
		if ( 0 === strpos( (string) $option, 'widget_' ) ) {
			self::purge_later();
		}
	}

	/**
	 * @return float Time of the last purge.
	 */
	private static function purged_at() {
		return (float) @file_get_contents( self::dir() . '/purged' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Stored page files (.html and .html.gz).
	 *
	 * @return \Generator<string>
	 */
	private static function files() {
		$pages = self::pages_dir();
		if ( ! is_dir( $pages ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pages, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isFile() && preg_match( '/^index-https?\.html(?:\.gz)?$/', $item->getFilename() ) ) {
				yield $item->getPathname();
			}
		}
	}

	/**
	 * Delete a folder inside the cache folder, with everything in it.
	 *
	 * @param string $dir Folder.
	 */
	private static function delete_tree( $dir ) {
		if ( ! is_dir( $dir ) || 0 !== strpos( $dir, self::dir() ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $dir );
		// phpcs:enable
	}

	/**
	 * Numbers for the dashboard.
	 *
	 * @return array{pages:int,bytes:int,queue:int,purged:float}
	 */
	public static function stats() {
		$pages = 0;
		$bytes = 0;
		foreach ( self::files() as $file ) {
			$bytes += (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '.html' === substr( $file, -5 ) ) {
				++$pages;
			}
		}
		return array(
			'pages'  => $pages,
			'bytes'  => $bytes,
			'queue'  => count( self::queue() ),
			'purged' => self::purged_at(),
		);
	}

	/* ------------------------------------------------------------------
	 * Preloading: every public page is requested in the background, so the
	 * first visitor (and Google's test) already gets a stored page.
	 * ------------------------------------------------------------------ */

	/**
	 * Queue the pages that are missing or in their last hour, and start at the end of this request.
	 */
	public static function preload_later() {
		if ( self::$preload_pending ) {
			return;
		}
		self::$preload_pending = true;
		add_action( 'shutdown', array( __CLASS__, 'preload_now' ), 20 );
	}

	public static function preload_now() {
		self::$preload_pending = false;
		if ( self::serving() && self::queue_stale() > 0 ) {
			self::kick();
		}
	}

	/**
	 * @return int Number of URLs added to the queue.
	 */
	public static function queue_stale() {
		$refresh = time() - self::ttl() + HOUR_IN_SECONDS;
		$urls    = array();
		foreach ( self::urls() as $url ) {
			$file  = self::file_for( $url );
			$mtime = null === $file ? false : @filemtime( $file . '.html' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( null !== $file && ( ! $mtime || $mtime < $refresh ) ) {
				$urls[] = $url;
			}
		}
		self::enqueue( $urls );
		return count( $urls );
	}

	/**
	 * Public pages: home, published posts and pages, category and tag archives.
	 *
	 * @return string[]
	 */
	public static function urls() {
		$urls  = array( home_url( '/' ) );
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library', 'e-floating-buttons' ) );
		$ids   = get_posts(
			array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 500,
				'fields'           => 'ids',
				'orderby'          => array(
					'post_type' => 'ASC',
					'date'      => 'DESC',
				),
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		$taxonomies = array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format', 'elementor_library_type', 'elementor_library_category' ) );
		$terms      = empty( $taxonomies ) ? array() : get_terms(
			array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => true,
				'number'     => 200,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_string( $url ) ) {
					$urls[] = $url;
				}
			}
		}
		/**
		 * Filters the URLs the page cache fills in the background.
		 *
		 * @param string[] $urls URLs.
		 */
		$urls  = (array) apply_filters( 'shd_performance_cache_preload_urls', $urls );
		$clean = array();
		foreach ( $urls as $url ) {
			if ( is_string( $url ) && null !== self::file_for( $url ) ) {
				$clean[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $clean ) ), 0, 1000 );
	}

	/**
	 * @return string[]
	 */
	private static function queue() {
		wp_cache_delete( self::QUEUE_OPT, 'options' );
		$queue = get_option( self::QUEUE_OPT, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * @param string[] $urls URLs.
	 */
	private static function enqueue( array $urls ) {
		if ( empty( $urls ) ) {
			return;
		}
		update_option( self::QUEUE_OPT, array_values( array_unique( array_merge( self::queue(), $urls ) ) ), false );
	}

	/**
	 * Start the next preload batch in a separate background request.
	 */
	public static function kick() {
		// Fallback through WP-Cron in case the server blocks requests to itself.
		if ( ! wp_next_scheduled( self::PRELOAD_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::PRELOAD_HOOK );
		}
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => self::AJAX_ACTION,
					'key'    => self::key(),
				),
			)
		);
	}

	/**
	 * Background request (admin-ajax.php) that runs one preload batch.
	 */
	public static function preload_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated by the secret key (no user session in this request).
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( self::key(), $key ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::preload();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Request up to BATCH queued pages (about 25 seconds at most), then continue in a new request.
	 */
	public static function preload() {
		if ( ! self::serving() || get_transient( self::LOCK ) ) {
			return;
		}
		// Longer than one batch (25 s plus one slow page), short enough that a batch that died is not waited for long.
		set_transient( self::LOCK, time(), 90 );
		$queue = self::queue();
		$batch = array_splice( $queue, 0, self::BATCH );
		update_option( self::QUEUE_OPT, $queue, false );
		$deadline = microtime( true ) + 25;
		foreach ( $batch as $index => $url ) {
			if ( microtime( true ) > $deadline ) {
				self::enqueue( array_slice( $batch, $index ) );
				break;
			}
			self::fetch( $url );
		}
		delete_transient( self::LOCK );
		if ( ! empty( self::queue() ) ) {
			self::kick();
		}
	}

	/**
	 * Request a page as a first-time visitor on a computer; WordPress builds it and the cache stores it.
	 *
	 * @param string $url URL.
	 */
	private static function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => self::PRELOAD_UA,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					'Accept'        => 'text/html',
					'X-SHD-Preload' => '1',
				),
			)
		);
		// A page that is gone or now redirects must not stay in the cache.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::delete_url( $url );
		}
	}

	/**
	 * Secret for the background requests.
	 *
	 * @return string
	 */
	private static function key() {
		$key = (string) get_option( self::KEY_OPT, '' );
		if ( strlen( $key ) < 20 ) {
			$key = wp_generate_password( 32, false, false );
			update_option( self::KEY_OPT, $key, false );
		}
		return $key;
	}

	/**
	 * Hourly: remove pages past their lifetime and queue the ones in their last hour.
	 */
	public static function maintain() {
		if ( ! self::serving() ) {
			return;
		}
		$limit = time() - self::ttl();
		foreach ( self::files() as $file ) {
			if ( (int) @filemtime( $file ) < $limit ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_delete_file( $file );
			}
		}
		foreach ( array( 'trash', 'orphaned' ) as $leftover ) {
			foreach ( (array) glob( self::dir() . '/' . $leftover . '-*', GLOB_ONLYDIR ) as $dir ) {
				if ( is_string( $dir ) ) {
					self::delete_tree( $dir );
				}
			}
		}
		if ( self::queue_stale() > 0 ) {
			self::kick();
		}
	}

	/* ------------------------------------------------------------------
	 * Installation: config.php, drop-in, WP_CACHE, .htaccess
	 * ------------------------------------------------------------------ */

	/**
	 * Install on the next admin page view when something changed or went missing.
	 */
	public static function maybe_sync() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = self::status();
		if ( $status['signature'] !== self::signature() || ( 'active' === $status['state'] && ! self::installed( $status ) ) ) {
			self::sync();
		}
	}

	public static function sync_later() {
		if ( ! self::$sync_pending ) {
			self::$sync_pending = true;
			add_action( 'shutdown', array( __CLASS__, 'sync' ), 1 );
		}
	}

	/**
	 * Kadence Performance (or another cache plugin) was switched on or off: take over or step back
	 * at the end of the request, when the list of active plugins is saved.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function plugins_changed( $plugin ) {
		if ( self::is_kadence( $plugin ) || isset( self::OTHER_CACHES[ $plugin ] ) ) {
			self::sync_later();
		}
	}

	/**
	 * Install or remove everything the page cache needs, according to the settings.
	 *
	 * @return array Status.
	 */
	public static function sync() {
		self::$sync_pending = false;
		$previous           = self::status();
		$status             = array(
			'time'      => time(),
			'signature' => self::signature(),
			'version'   => SHD_PERF_VERSION,
			'state'     => 'off',
			'delivery'  => '',
			'blocker'   => '',
			'notes'     => array(),
		);

		if ( ! self::enabled() ) {
			$status['state'] = is_multisite() ? 'unsupported' : 'off';
			if ( is_multisite() ) {
				$status['notes'][] = __( 'The page cache is not available on multisite networks.', 'shd-performance' );
			}
			self::teardown( false );
			return self::save_status( $status );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) || '' === self::host() ) {
			$status['state']   = 'unsupported';
			$status['notes'][] = __( 'The page cache needs pretty permalinks (Settings → Permalinks, any setting except “Plain”).', 'shd-performance' );
			self::teardown( false );
			return self::save_status( $status );
		}
		$blocker = self::blocker();
		if ( '' !== $blocker ) {
			$status['state']   = 'blocked';
			$status['blocker'] = $blocker;
			self::teardown( false );
			return self::save_status( $status );
		}
		$fresh = ! is_dir( self::pages_dir() );
		if ( ! self::ensure_dirs() || ! self::write_config() ) {
			$status['state']   = 'error';
			/* translators: %s: folder */
			$status['notes'][] = sprintf( __( 'The folder %s cannot be created or written. Ask your host to make wp-content/cache writable for WordPress.', 'shd-performance' ), self::relative( self::dir() ) );
			self::teardown( false );
			return self::save_status( $status );
		}

		$dropin = self::install_dropin();
		if ( 'foreign' === $dropin ) {
			$status['state']   = 'blocked';
			$status['blocker'] = 'advanced-cache.php';
			self::teardown( false );
			return self::save_status( $status );
		}
		$wp_cache = self::ensure_wp_cache();
		$htaccess = self::sync_htaccess( true );

		$status['state']    = 'active';
		$status['delivery'] = 'written' === $htaccess ? 'htaccess' : ( 'ok' === $dropin && in_array( $wp_cache, array( 'ok', 'added' ), true ) ? 'php' : 'plugin' );
		if ( 'unwritable' === $dropin ) {
			$status['notes'][] = __( 'wp-content/advanced-cache.php cannot be written, so stored pages are only sent after WordPress has loaded its plugins. Ask your host to make wp-content writable for WordPress, then save the page cache settings again.', 'shd-performance' );
		}
		if ( 'disabled' === $wp_cache ) {
			$status['notes'][] = __( 'wp-config.php sets WP_CACHE to false. Change it to define( \'WP_CACHE\', true ); so WordPress loads the page cache before anything else.', 'shd-performance' );
		} elseif ( 'unwritable' === $wp_cache ) {
			$status['notes'][] = __( 'wp-config.php cannot be changed by WordPress. Add define( \'WP_CACHE\', true ); near the top of wp-config.php so WordPress loads the page cache before anything else.', 'shd-performance' );
		} elseif ( 'added' === $wp_cache ) {
			$status['notes'][] = __( 'define( \'WP_CACHE\', true ); was added to wp-config.php.', 'shd-performance' );
		}
		if ( 'unwritable' === $htaccess ) {
			$status['notes'][] = __( 'The .htaccess file cannot be changed, so stored pages are sent by PHP (fast, but not as fast as directly by Apache). Make .htaccess writable for WordPress and save the page cache settings again.', 'shd-performance' );
		}
		self::save_status( $status );

		if ( ! wp_next_scheduled( self::MAINTAIN_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::MAINTAIN_HOOK );
		}
		if ( $fresh || 'active' !== $previous['state'] || $previous['signature'] !== $status['signature'] ) {
			// A fresh start, new settings or a new plugin version: begin with an empty cache and fill it.
			self::purge();
		} else {
			// Pages may be missing, for example when the last background request could not reach the server.
			self::preload_later();
		}
		return $status;
	}

	/**
	 * Remove what the page cache installed. Leaves another plugin's drop-in and .htaccess rules alone.
	 *
	 * @param bool $deactivating Also remove the WP_CACHE line we added and our options.
	 */
	public static function teardown( $deactivating ) {
		self::sync_htaccess( false );
		$dropin = self::dropin_file();
		if ( is_file( $dropin ) && self::is_ours( (string) file_get_contents( $dropin ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $dropin );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $dropin, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		self::delete_tree( self::dir() );
		wp_clear_scheduled_hook( self::MAINTAIN_HOOK );
		wp_clear_scheduled_hook( self::PRELOAD_HOOK );
		delete_option( self::QUEUE_OPT );
		delete_transient( self::LOCK );
		if ( $deactivating ) {
			self::remove_wp_cache_line();
			delete_option( self::STATUS_OPT );
			delete_option( self::KEY_OPT );
		}
	}

	/**
	 * @param array $status Status.
	 * @return array
	 */
	private static function save_status( array $status ) {
		update_option( self::STATUS_OPT, $status, true );
		return $status;
	}

	/**
	 * Everything the installation depends on; a change triggers a new installation.
	 *
	 * @return string
	 */
	private static function signature() {
		return md5(
			(string) wp_json_encode(
				array(
					SHD_PERF_VERSION,
					self::enabled(),
					self::host(),
					self::ttl(),
					! empty( Settings::get()['cache_campaign'] ),
					self::exclude_patterns(),
					self::blocker(),
					(string) get_option( 'permalink_structure' ),
					WP_CONTENT_DIR,
					SHD_PERF_DIR,
				)
			)
		);
	}

	/**
	 * Whether the installed files are still in place.
	 *
	 * @param array $status Status.
	 * @return bool
	 */
	private static function installed( array $status ) {
		if ( ! is_file( self::config_file() ) || ! is_dir( self::pages_dir() ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( 'plugin' !== $status['delivery'] && ! self::is_ours( (string) @file_get_contents( self::dropin_file(), false, null, 0, 600 ) ) ) {
			return false;
		}
		$htaccess = self::htaccess_file();
		if ( 'htaccess' === $status['delivery'] && false === strpos( (string) @file_get_contents( $htaccess ), '# BEGIN ' . self::HTACCESS_TAG ) ) {
			return false;
		}
		// phpcs:enable
		// Installed from WP-CLI, where Apache cannot be detected: add the rules from a web request.
		if ( 'htaccess' !== $status['delivery'] && self::is_apache() && wp_is_writable( is_file( $htaccess ) ? $htaccess : dirname( $htaccess ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Name of an active plugin that brings its own page cache, or ''.
	 *
	 * @return string
	 */
	public static function blocker() {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( self::is_kadence( $plugin ) ) {
				return 'Kadence Performance';
			}
			if ( isset( self::OTHER_CACHES[ $plugin ] ) ) {
				return self::OTHER_CACHES[ $plugin ];
			}
		}
		return '';
	}

	/**
	 * Kadence Performance, formerly Solid Performance.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function is_kadence( $plugin ) {
		return in_array( basename( (string) $plugin ), array( 'solid-performance.php', 'kadence-performance.php' ), true );
	}

	/**
	 * @return bool
	 */
	public static function kadence_active() {
		return 'Kadence Performance' === self::blocker();
	}

	/**
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_ours( $content ) {
		return false !== strpos( substr( $content, 0, 600 ), self::MARKER );
	}

	/**
	 * Folders and the web server settings for stored pages.
	 *
	 * @return bool
	 */
	private static function ensure_dirs() {
		if ( ! wp_mkdir_p( self::pages_dir() ) ) {
			return false;
		}
		$silence = "<?php\n// Silence is golden.\n";
		$files   = array(
			self::dir() . '/index.php'         => $silence,
			self::pages_dir() . '/index.php'   => $silence,
			self::pages_dir() . '/.htaccess'   => self::pages_htaccess(),
		);
		foreach ( $files as $file => $content ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ( ! is_file( $file ) || file_get_contents( $file ) !== $content ) && ! self::write( $file, $content ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Response headers for pages Apache sends directly.
	 *
	 * @return string
	 */
	private static function pages_htaccess() {
		$charset = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) get_option( 'blog_charset', 'UTF-8' ) );
		return '# ' . self::MARKER . ": response headers for pages the web server sends from this folder.\n"
			. "<IfModule mod_mime.c>\n"
			. "\tRemoveHandler .html .gz\n"
			. "\tRemoveType .gz\n"
			. "\tAddEncoding gzip .gz\n"
			. "\tAddCharset " . ( '' !== $charset ? $charset : 'UTF-8' ) . " .html\n"
			. "</IfModule>\n"
			. "<IfModule mod_setenvif.c>\n"
			. "\tSetEnvIfNoCase Request_URI \\.gz$ no-gzip\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "\tHeader set Cache-Control \"max-age=0, public\"\n"
			. "\tHeader set X-SHD-Cache \"HIT (htaccess)\"\n"
			. "\tHeader merge Vary Accept-Encoding\n"
			. "</IfModule>\n";
	}

	/**
	 * @return bool
	 */
	private static function write_config() {
		$charset = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) get_option( 'blog_charset', 'UTF-8' ) );
		$config  = array(
			'enabled'         => true,
			'plugin'          => plugin_basename( SHD_PERF_FILE ),
			'handler'         => SHD_PERF_DIR . 'includes/page-cache/class-page-cache-handler.php',
			'pages'           => self::pages_dir(),
			'host'            => self::host(),
			'ttl'             => self::ttl(),
			'charset'         => '' !== $charset ? $charset : 'UTF-8',
			'campaign'        => ! empty( Settings::get()['cache_campaign'] ),
			'campaign_params' => self::CAMPAIGN_PARAMS,
			'bypass_cookies'  => self::BYPASS_COOKIES,
			'exclude'         => self::exclude_patterns(),
		);
		$php = "<?php\n// " . self::MARKER . ": settings read by wp-content/advanced-cache.php. Written by the plugin; changes are overwritten.\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates PHP code.
		return self::write( self::config_file(), $php );
	}

	/**
	 * Copy the drop-in to wp-content/advanced-cache.php (replacing a leftover Kadence Performance drop-in).
	 *
	 * @return string ok|foreign|unwritable
	 */
	private static function install_dropin() {
		$file     = self::dropin_file();
		$template = (string) file_get_contents( SHD_PERF_DIR . 'includes/page-cache/advanced-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_file( $file ) ) {
			$current = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $current === $template ) {
				return 'ok';
			}
			$kadence = false !== strpos( $current, 'Kadence Performance' ) || false !== strpos( $current, 'SolidWP\\Performance' );
			if ( '' !== trim( $current ) && ! self::is_ours( $current ) && ! $kadence ) {
				return 'foreign';
			}
		}
		return self::write( $file, $template ) ? 'ok' : 'unwritable';
	}

	/**
	 * Make sure WordPress loads the drop-in: WP_CACHE must be true.
	 *
	 * @return string ok|added|disabled|unwritable
	 */
	private static function ensure_wp_cache() {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return 'ok';
		}
		// WordPress defines WP_CACHE as false when wp-config.php does not set it, so look at the file.
		$file    = self::wp_config_file();
		$content = '' === $file ? '' : (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== strpos( $content, self::WP_CACHE_NOTE ) ) {
			return 'added';
		}
		if ( preg_match( '/^\s*define\s*\(\s*[\'"]WP_CACHE[\'"]/m', $content ) ) {
			return 'disabled';
		}
		if ( '' === $file || ! wp_is_writable( $file ) ) {
			return 'unwritable';
		}
		$updated = preg_replace( '/^<\?php\s*?\R/', "<?php\ndefine( 'WP_CACHE', true ); " . self::WP_CACHE_NOTE . "\n", $content, 1, $count );
		if ( 1 !== $count || ! is_string( $updated ) ) {
			return 'unwritable';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- in place, keeps owner and permissions.
		return false !== file_put_contents( $file, $updated, LOCK_EX ) ? 'added' : 'unwritable';
	}

	private static function remove_wp_cache_line() {
		$file = self::wp_config_file();
		if ( '' === $file || ! wp_is_writable( $file ) ) {
			return;
		}
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $content, self::WP_CACHE_NOTE ) ) {
			return;
		}
		$updated = preg_replace( '/^.*' . preg_quote( self::WP_CACHE_NOTE, '/' ) . '.*\R/m', '', $content );
		if ( is_string( $updated ) && '' !== $updated ) {
			file_put_contents( $file, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * @return string Path of wp-config.php, or ''.
	 */
	private static function wp_config_file() {
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( is_file( dirname( ABSPATH ) . '/wp-config.php' ) && ! is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}
		return '';
	}

	/**
	 * Add, update or remove our block at the top of .htaccess (before the WordPress rules,
	 * which would otherwise send every request to PHP), and remove leftover Kadence Performance rules.
	 *
	 * @param bool $enable Add (true) or remove (false).
	 * @return string written|removed|not_apache|unwritable
	 */
	private static function sync_htaccess( $enable ) {
		$file    = self::htaccess_file();
		$exists  = is_file( $file );
		$content = $exists ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ours    = false !== strpos( $content, '# BEGIN ' . self::HTACCESS_TAG );
		if ( $enable && ! $ours && ! self::is_apache() ) {
			return 'not_apache';
		}
		$updated = self::strip_block( $content, self::HTACCESS_TAG );
		if ( ! self::kadence_active() ) {
			// Kadence Performance removes these when it is deactivated; a leftover would keep serving its old pages.
			$updated = self::strip_block( self::strip_block( $updated, 'KadencePerformance' ), 'SolidPerformance' );
		}
		if ( $enable ) {
			$updated = '# BEGIN ' . self::HTACCESS_TAG . "\n" . self::htaccess_rules() . '# END ' . self::HTACCESS_TAG . "\n\n" . ltrim( $updated );
		}
		if ( $updated === $content ) {
			return $enable ? 'written' : 'removed';
		}
		if ( ! ( $exists ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) ) ) ) {
			return 'unwritable';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- in place, keeps owner and permissions.
		if ( false === file_put_contents( $file, $updated, LOCK_EX ) ) {
			return 'unwritable';
		}
		return $enable ? 'written' : 'removed';
	}

	/**
	 * @param string $content .htaccess content.
	 * @param string $tag     Marker name.
	 * @return string
	 */
	private static function strip_block( $content, $tag ) {
		$tag     = preg_quote( $tag, '/' );
		$updated = preg_replace( '/^[ \t]*# BEGIN ' . $tag . '[ \t]*\R.*?^[ \t]*# END ' . $tag . '[ \t]*(?:\R[ \t]*(?=\R))?\R?/ms', '', $content );
		return is_string( $updated ) ? $updated : $content;
	}

	/**
	 * Rewrite rules: send the stored page when there is one for this address.
	 *
	 * @return string
	 */
	private static function htaccess_rules() {
		$host    = self::host();
		$files   = addcslashes( self::pages_dir() . '/' . $host, '\\"$%' );
		$url     = addcslashes( (string) wp_parse_url( content_url( '/cache/shd-performance/pages/' . $host ), PHP_URL_PATH ), '\\"$%' );
		$cookies = implode( '|', array_map( 'preg_quote', self::BYPASS_COOKIES ) );
		$target  = '%{REQUEST_URI}index-%{ENV:SHD_CACHE_SCHEME}.%{ENV:SHD_CACHE_EXT}';
		return "# Pages stored by the SHD Performance page cache are sent without starting WordPress.\n"
			. "<IfModule mod_rewrite.c>\n"
			. "RewriteEngine On\n"
			. "RewriteRule ^ - [E=SHD_CACHE_SCHEME:http,E=SHD_CACHE_EXT:html]\n"
			. "RewriteCond %{HTTPS} =on [NC,OR]\n"
			. "RewriteCond %{SERVER_PORT} =443 [OR]\n"
			. "RewriteCond %{HTTP:X-Forwarded-Proto} =https [NC]\n"
			. "RewriteRule ^ - [E=SHD_CACHE_SCHEME:https]\n"
			. "RewriteCond %{HTTP:Accept-Encoding} gzip [NC]\n"
			. "RewriteRule ^ - [E=SHD_CACHE_EXT:html.gz]\n"
			. "RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$\n"
			. "RewriteCond %{QUERY_STRING} ^$\n"
			. "RewriteCond %{REQUEST_URI} /$\n"
			. 'RewriteCond %{HTTP_HOST} =' . $host . " [NC]\n"
			. 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ") [NC]\n"
			. "RewriteCond %{HTTP:X-SHD-Preload} ^$\n"
			. 'RewriteCond "' . $files . $target . "\" -f\n"
			. 'RewriteRule ^ "' . $url . $target . "\" [L]\n"
			. "</IfModule>\n";
	}

	/**
	 * Apache or LiteSpeed (both read .htaccess).
	 *
	 * @return bool
	 */
	private static function is_apache() {
		global $is_apache;
		return ! empty( $is_apache );
	}

	/**
	 * @param string $path Absolute path.
	 * @return string Path relative to the WordPress folder.
	 */
	private static function relative( $path ) {
		return 0 === strpos( $path, ABSPATH ) ? substr( $path, strlen( ABSPATH ) ) : $path;
	}
}
