<?php
/**
 * Dashboard: overview, checklist guide, feature switches, fonts, Web Vitals and tools.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG   = 'shd-performance';
	const CAP    = 'manage_options';
	const NOTICE = 'shd_perf_notice_';

	/** Form actions, each handled by do_{name}() behind a capability and nonce check. */
	const ACTIONS = array( 'save', 'promote', 'reset', 'purge', 'cache_save', 'cache_preload', 'profile', 'fonts_detect', 'fonts_upload', 'fonts_remove', 'fonts_preload', 'media_lighter', 'media_upload', 'media_undo', 'media_cleanup', 'task', 'token', 'scan', 'health', 'export', 'import', 'clear_error' );

	/** @var Admin|null */
	private static $instance = null;

	/** @var string */
	private $page_hook = '';

	/**
	 * @return Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHD_PERF_FILE ), array( $this, 'action_links' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_shd_perf_' . $action, array( $this, 'dispatch' ) );
		}
		add_filter( 'media_row_actions', array( $this, 'media_row_action' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'media_field' ), 10, 2 );
	}

	/**
	 * Media Library (list view): "Replace file" under each image.
	 *
	 * @param string[] $actions Row actions.
	 * @param \WP_Post $post    Attachment.
	 * @return string[]
	 */
	public function media_row_action( $actions, $post ) {
		if ( current_user_can( self::CAP ) && Media_Replace::can_replace( $post->ID ) ) {
			$actions['shd_replace'] = '<a href="' . esc_url( self::url( 'images', array( 'attachment' => $post->ID ) ) ) . '">' . esc_html__( 'Replace file', 'shd-performance' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Media Library (grid view and edit screen): a "Replace file" field in the details.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array
	 */
	public function media_field( $fields, $post ) {
		if ( current_user_can( self::CAP ) && Media_Replace::can_replace( $post->ID ) ) {
			$fields['shd_replace'] = array(
				'label' => __( 'Replace file', 'shd-performance' ),
				'input' => 'html',
				'html'  => '<a class="button" href="' . esc_url( self::url( 'images', array( 'attachment' => $post->ID ) ) ) . '">' . esc_html__( 'Make lighter or replace…', 'shd-performance' ) . '</a>',
				'helps' => __( 'Keeps this image (same ID, title and alt text); every page that uses it shows the new file.', 'shd-performance' ),
			);
		}
		return $fields;
	}

	/**
	 * Dashboard URL.
	 *
	 * @param string $tab  Tab id.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	public function menu() {
		$this->page_hook = (string) add_menu_page(
			__( 'SHD Performance', 'shd-performance' ),
			__( 'SHD Performance', 'shd-performance' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			81
		);
	}

	/**
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->page_hook ) {
			wp_enqueue_style( 'shd-perf-admin', SHD_PERF_URL . 'assets/css/admin.css', array(), SHD_PERF_VERSION );
		}
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dashboard', 'shd-performance' ) . '</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------
	 * Form handling
	 * ------------------------------------------------------------------ */

	public function dispatch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below, per action.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$name   = (string) substr( $action, strlen( 'shd_perf_' ) );
		if ( ! current_user_can( self::CAP ) || ! in_array( $name, self::ACTIONS, true ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'shd-performance' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shd_perf_' . $name );
		call_user_func( array( $this, 'do_' . $name ) );
	}

	/**
	 * Store a notice for the next page view and go back to the dashboard.
	 *
	 * @param string $tab     Tab.
	 * @param string $type    success|warning|error|info.
	 * @param string $message Message.
	 * @param array  $args    Extra query arguments.
	 * @param string $anchor  Optional fragment.
	 */
	private function back( $tab, $type, $message, array $args = array(), $anchor = '' ) {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
		wp_safe_redirect( self::url( $tab, $args ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	/**
	 * @param string[] $purged Purged caches.
	 * @return string
	 */
	private static function purge_note( array $purged ) {
		if ( empty( $purged ) ) {
			return __( 'No supported page cache was found to clear automatically. If you use one, clear it now so visitors get the change.', 'shd-performance' );
		}
		/* translators: %s: cache names */
		$note = sprintf( __( 'Cleared automatically: %s.', 'shd-performance' ), implode( ', ', $purged ) );
		if ( in_array( 'Kadence Performance', $purged, true ) ) {
			$note .= ' ' . __( 'Press “Preload & Refresh All” in Settings → Kadence Performance to rebuild the cache; until then each page is cached on its first visit. Or deactivate Kadence Performance: the SHD page cache then takes over and refills itself.', 'shd-performance' );
		}
		return $note;
	}

	private function do_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in dispatch(); sanitized below.
		$input = isset( $_POST['shd'] ) && is_array( $_POST['shd'] ) ? wp_unslash( $_POST['shd'] ) : array();
		Settings::update( self::sanitize( $input ) );
		$this->back( 'features', 'success', __( 'Settings saved.', 'shd-performance' ) . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_promote() {
		$states = array();
		foreach ( array_keys( Settings::features() ) as $id ) {
			if ( 'test' === Settings::state( $id ) ) {
				$states[ $id ] = 'live';
			}
		}
		Settings::update( array( 'states' => $states ) );
		/* translators: %d: number of features */
		$message = sprintf( _n( '%d feature switched to Live.', '%d features switched to Live.', count( $states ), 'shd-performance' ), count( $states ) );
		$this->back( 'features', 'success', $message . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_reset() {
		Settings::update( array_diff_key( Settings::defaults(), array_flip( array( 'fonts', 'preview_token' ) ) ) );
		$this->back( 'features', 'success', __( 'All optimizations are back to their default settings (fonts and the preview link are kept).', 'shd-performance' ) . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_purge() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in dispatch().
		$tab = isset( $_REQUEST['tab'] ) && 'cache' === $_REQUEST['tab'] ? 'cache' : 'tools';
		$this->back( $tab, 'success', self::purge_note( Cache::purge_all() ) );
	}

	private function do_cache_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in dispatch(); sanitized below.
		$input = isset( $_POST['shd'] ) && is_array( $_POST['shd'] ) ? wp_unslash( $_POST['shd'] ) : array();
		Settings::update( self::sanitize( array_intersect_key( $input, array_flip( array( 'page_cache', 'cache_ttl', 'cache_campaign', 'cache_exclude' ) ) ) ) );
		$status = Page_Cache::sync();
		$note   = 'active' === $status['state'] ? ' ' . self::purge_note( Cache::purge_all() ) : '';
		$this->back( 'cache', 'success', __( 'Page cache settings saved.', 'shd-performance' ) . $note );
	}

	private function do_cache_preload() {
		if ( ! Page_Cache::serving() ) {
			$this->back( 'cache', 'warning', __( 'The page cache is not active.', 'shd-performance' ) );
		}
		$count = Page_Cache::queue_stale();
		Page_Cache::kick();
		$this->back(
			'cache',
			'success',
			0 === $count
				? __( 'Every page is already in the cache.', 'shd-performance' )
				/* translators: %d: number of pages */
				: sprintf( _n( '%d page is being cached in the background (about 10 pages per 30 seconds).', '%d pages are being cached in the background (about 10 pages per 30 seconds).', $count, 'shd-performance' ), $count )
		);
	}

	private function do_profile() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$choice = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : 'home';
		$path   = isset( $_POST['path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['path'] ) ) ) : '';
		// phpcs:enable
		$url = self::profile_url( $choice, $path );
		if ( '' === $url ) {
			$this->back( 'tools', 'error', __( 'Enter the address of a page on this site, for example /blog/.', 'shd-performance' ), array(), 'profile' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$report = Profiler::run( $url );
		if ( is_wp_error( $report ) ) {
			$this->back( 'tools', 'error', $report->get_error_message(), array(), 'profile' );
		}
		/* translators: 1: page address, 2: milliseconds */
		$this->back( 'tools', 'success', sprintf( __( '%1$s was built in %2$s ms on the server. Details below.', 'shd-performance' ), wp_make_link_relative( $url ), number_format_i18n( $report['total_ms'] ) ), array(), 'profile' );
	}

	/**
	 * Page to measure: a preset or an address on this site.
	 *
	 * @param string $choice home|posts|post|custom.
	 * @param string $path   Address or path for "custom".
	 * @return string URL, or '' if invalid.
	 */
	private static function profile_url( $choice, $path ) {
		if ( 'posts' === $choice && get_option( 'page_for_posts' ) ) {
			return (string) get_permalink( (int) get_option( 'page_for_posts' ) );
		}
		if ( 'post' === $choice ) {
			$latest = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'fields'      => 'ids',
				)
			);
			return empty( $latest ) ? '' : (string) get_permalink( (int) $latest[0] );
		}
		if ( 'custom' === $choice ) {
			// A path, or a full address on this site.
			$parts = wp_parse_url( $path );
			if ( ! is_array( $parts ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
				return '';
			}
			if ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return '';
			}
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			if ( ! preg_match( '#^/?[^\s<>"\'\\\\:]*$#', $path ) ) {
				return '';
			}
			return home_url( '/' . ltrim( $path, '/' ) );
		}
		return home_url( '/' );
	}

	private function do_fonts_detect() {
		$faces = Fonts::detect_and_store( Fonts::collect_css() );
		if ( empty( $faces ) ) {
			$this->back( 'fonts', 'warning', __( 'No custom @font-face rules were found on the front page or in the Elementor kit.', 'shd-performance' ) );
		}
		$fallbacks = array();
		foreach ( Fonts::metrics() as $entry ) {
			$fallbacks[] = $entry['family'];
		}
		/* translators: 1: number of font files, 2: families */
		$message = sprintf( _n( 'Found %1$d font file (%2$s).', 'Found %1$d font files (%2$s).', count( $faces ), 'shd-performance' ), count( $faces ), implode( ', ', Fonts::families() ) );
		if ( ! empty( $fallbacks ) ) {
			/* translators: %s: families */
			$message .= ' ' . sprintf( __( 'Size-matched fallback calculated for %s.', 'shd-performance' ), implode( ', ', $fallbacks ) );
		}
		$this->back( 'fonts', 'success', $message . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_fonts_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$files = isset( $_FILES['shd_fonts'] ) && is_array( $_FILES['shd_fonts']['name'] ) ? $_FILES['shd_fonts'] : null;
		if ( null === $files ) {
			$this->back( 'fonts', 'error', __( 'Choose one or more .woff2 files first.', 'shd-performance' ) );
		}
		$known  = array();
		foreach ( Fonts::faces() as $face ) {
			$known[ Fonts::key_for( $face['src'] ) ] = true;
		}
		$stored = array();
		$errors = array();
		$count  = count( $files['name'] );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( '' === (string) $files['name'][ $i ] ) {
				continue;
			}
			$result = Fonts::store_upload(
				array(
					'name'     => (string) $files['name'][ $i ],
					'tmp_name' => (string) $files['tmp_name'][ $i ],
					'error'    => (int) $files['error'][ $i ],
					'size'     => (int) $files['size'][ $i ],
				)
			);
			$name = sanitize_file_name( (string) $files['name'][ $i ] );
			$base = preg_replace( '/' . preg_quote( Fonts::SUBSET_SUFFIX, '/' ) . '$/', '', Fonts::key_for( $name ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = $name . ': ' . $result->get_error_message();
			} elseif ( ! empty( $known ) && ! isset( $known[ $base ] ) ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( '%s was saved, but no detected font file has this name, so it is not used. Rename it to match the original file (only the extension changes) and upload it again.', 'shd-performance' ), $name );
			} else {
				$stored[] = $name;
			}
		}
		$type    = empty( $errors ) ? 'success' : ( empty( $stored ) ? 'error' : 'warning' );
		$message = empty( $stored ) ? '' : sprintf(
			/* translators: %s: file names */
			__( 'Uploaded and in use: %s.', 'shd-performance' ),
			implode( ', ', $stored )
		);
		$message = trim( $message . ' ' . implode( ' ', $errors ) );
		if ( ! empty( $stored ) ) {
			$message .= ' ' . self::purge_note( Cache::purge_all() );
		}
		$this->back( 'fonts', $type, $message );
	}

	private function do_fonts_remove() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		Fonts::remove_upload( $key );
		$this->back( 'fonts', 'success', __( 'WOFF2 file removed; the original font file is used again.', 'shd-performance' ) . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_fonts_preload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in dispatch(); validated below.
		$weights = isset( $_POST['preload'] ) && is_array( $_POST['preload'] ) ? wp_unslash( $_POST['preload'] ) : array();
		$weights = array_values( array_filter( array_map( 'strval', $weights ), array( __CLASS__, 'is_weight' ) ) );
		Settings::update( array( 'fonts' => array( 'preload' => $weights ) ) );
		$this->back( 'fonts', 'success', __( 'Preload selection saved.', 'shd-performance' ) . ' ' . self::purge_note( Cache::purge_all() ) );
	}

	private function do_media_lighter() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$id      = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$quality = isset( $_POST['quality'] ) ? (int) $_POST['quality'] : Media_Replace::QUALITY;
		// phpcs:enable
		if ( ! in_array( $quality, Media_Replace::QUALITIES, true ) ) {
			$quality = Media_Replace::QUALITY;
		}
		$this->replaced( $id, Media_Replace::make_lighter( $id, $quality ) );
	}

	private function do_media_upload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$id   = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$file = isset( $_FILES['shd_file'] ) && is_array( $_FILES['shd_file'] ) ? $_FILES['shd_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by wp_handle_upload().
		// phpcs:enable
		$this->replaced( $id, Media_Replace::replace_with_upload( $id, $file ) );
	}

	/**
	 * @param int             $id     Attachment ID.
	 * @param array|\WP_Error $result Result of the replacement.
	 */
	private function replaced( $id, $result ) {
		if ( is_wp_error( $result ) ) {
			$this->back( 'images', 'error', $result->get_error_message(), array( 'attachment' => $id ) );
		}
		$kept  = count( wp_list_filter( $result['places'], array( 'kept' => true ) ) );
		$saved = $result['old_bytes'] > 0 ? round( 100 * ( 1 - $result['new_bytes'] / $result['old_bytes'] ) ) : 0;
		$message = sprintf(
			/* translators: 1: old size, 2: new size, 3: percent saved, 4: number of places */
			_n( 'Replaced: %1$s → %2$s (%3$d%% lighter). Updated in %4$d place; Elementor’s CSS and the page cache were cleared.', 'Replaced: %1$s → %2$s (%3$d%% lighter). Updated in %4$d places; Elementor’s CSS and the page cache were cleared.', count( $result['places'] ) - $kept, 'shd-performance' ),
			size_format( $result['old_bytes'] ),
			size_format( $result['new_bytes'] ),
			max( 0, $saved ),
			count( $result['places'] ) - $kept
		);
		if ( $kept > 0 ) {
			/* translators: %d: number of places */
			$message .= ' ' . sprintf( _n( '%d place could not be changed automatically and still shows the old file, which is kept.', '%d places could not be changed automatically and still show the old file, which is kept.', $kept, 'shd-performance' ), $kept );
		}
		$this->back( 'images', 'success', $message, array( 'attachment' => $id ) );
	}

	private function do_media_undo() {
		$id     = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$result = Media_Replace::undo( $id );
		if ( is_wp_error( $result ) ) {
			$this->back( 'images', 'error', $result->get_error_message(), array( 'attachment' => $id ) );
		}
		/* translators: %d: number of places */
		$this->back( 'images', 'success', sprintf( _n( 'Undone: the image uses its earlier file again, in %d place too.', 'Undone: the image uses its earlier file again, in %d places too.', count( $result['places'] ), 'shd-performance' ), count( $result['places'] ) ), array( 'attachment' => $id ) );
	}

	private function do_media_cleanup() {
		$id     = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$result = Media_Replace::delete_old_files( $id );
		if ( is_wp_error( $result ) ) {
			$this->back( 'images', 'error', $result->get_error_message(), array( 'attachment' => $id ) );
		}
		/* translators: 1: number of files, 2: size */
		$this->back( 'images', 'success', sprintf( __( 'Deleted %1$d old files (%2$s).', 'shd-performance' ), $result['deleted'], size_format( $result['bytes'] ) ), array( 'attachment' => $id ) );
	}

	private function do_task() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$id   = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$done = isset( $_POST['done'] ) && '1' === $_POST['done'];
		$step = isset( $_POST['next_step'] ) ? (int) $_POST['next_step'] : 0;
		// phpcs:enable
		$ids = wp_list_pluck( Checklist::tasks(), 'id' );
		if ( ! in_array( $id, $ids, true ) ) {
			$this->back( 'checklist', 'error', __( 'Unknown task.', 'shd-performance' ) );
		}
		Checklist::mark( $id, $done );
		$message = $done ? __( 'Task marked as done.', 'shd-performance' ) : __( 'Task reopened.', 'shd-performance' );
		if ( $step > 0 ) {
			$this->back( 'checklist', 'success', $message, array( 'step' => $step ) );
		}
		$this->back( 'checklist', 'success', $message, array(), 'task-' . $id );
	}

	private function do_token() {
		Settings::update( array( 'preview_token' => wp_generate_password( 20, false, false ) ) );
		$this->back( 'overview', 'success', __( 'New preview link created; the old one no longer works.', 'shd-performance' ) );
	}

	private function do_scan() {
		$scan = Site_Scanner::run();
		/* translators: 1: documents, 2: animations, 3: large images */
		$message = sprintf( __( 'Scanned %1$d Elementor documents: %2$d entrance animations, %3$d image files over 200 KB.', 'shd-performance' ), $scan['documents'], $scan['animations'], count( $scan['images'] ) );
		$this->back( 'tools', 'success', $message, array(), 'scan' );
	}

	private function do_health() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$health = Health_Checks::run();
		$counts = array_count_values( wp_list_pluck( $health['results'], 'status' ) );
		/* translators: 1: good checks, 2: checks to improve */
		$message = sprintf( __( 'Health checks finished: %1$d good, %2$d to improve.', 'shd-performance' ), isset( $counts['good'] ) ? $counts['good'] : 0, ( isset( $counts['warn'] ) ? $counts['warn'] : 0 ) + ( isset( $counts['bad'] ) ? $counts['bad'] : 0 ) );
		$this->back( 'tools', 'success', $message, array(), 'health' );
	}

	private function do_export() {
		$export = array_diff_key( Settings::get(), array_flip( array( 'preview_token' ) ) );
		$export = array(
			'plugin'   => 'shd-performance',
			'version'  => SHD_PERF_VERSION,
			'settings' => $export,
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=shd-performance-settings-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function do_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in dispatch().
		$file = isset( $_FILES['shd_import'] ) && is_array( $_FILES['shd_import'] ) ? $_FILES['shd_import'] : null;
		if ( null === $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 200 * KB_IN_BYTES ) {
			$this->back( 'tools', 'error', __( 'Choose a settings file exported from SHD Performance.', 'shd-performance' ), array(), 'transfer' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the uploaded temp file.
		$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true );
		if ( ! is_array( $data ) || ! isset( $data['plugin'], $data['settings'] ) || 'shd-performance' !== $data['plugin'] || ! is_array( $data['settings'] ) ) {
			$this->back( 'tools', 'error', __( 'This file is not an SHD Performance settings export.', 'shd-performance' ), array(), 'transfer' );
		}
		Settings::update( self::sanitize( $data['settings'] ) );
		$this->back( 'tools', 'success', __( 'Settings imported.', 'shd-performance' ) . ' ' . self::purge_note( Cache::purge_all() ), array(), 'transfer' );
	}

	private function do_clear_error() {
		delete_option( Plugin::ERROR_OPT );
		$this->back( 'tools', 'success', __( 'Error message cleared.', 'shd-performance' ) );
	}

	/**
	 * Whitelist and clean submitted or imported settings.
	 *
	 * @param array $input Raw values.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$clean = array();

		if ( isset( $input['states'] ) && is_array( $input['states'] ) ) {
			foreach ( array_keys( Settings::features() ) as $id ) {
				if ( isset( $input['states'][ $id ] ) && in_array( $input['states'][ $id ], Settings::STATES, true ) ) {
					$clean['states'][ $id ] = $input['states'][ $id ];
				}
			}
		}
		if ( isset( $input['first_screen_sections'] ) ) {
			$clean['first_screen_sections'] = max( 1, min( 6, (int) $input['first_screen_sections'] ) );
		}
		if ( isset( $input['animation_mode'] ) ) {
			$clean['animation_mode'] = 'keep_below' === $input['animation_mode'] ? 'keep_below' : 'all';
		}
		if ( isset( $input['toc_phones'] ) ) {
			$clean['toc_phones'] = 'collapsed' === $input['toc_phones'] ? 'collapsed' : 'expanded';
		}
		if ( isset( $input['delay_timeout'] ) ) {
			$clean['delay_timeout'] = max( 0, min( 60, (int) $input['delay_timeout'] ) );
		}
		foreach ( array( 'tracking_patterns', 'delay_exclusions', 'keep_inline', 'consent_scripts' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = self::lines( $input[ $key ] );
			}
		}
		if ( isset( $input['redirect_domains'] ) ) {
			$domains = array();
			foreach ( self::lines( $input['redirect_domains'] ) as $line ) {
				$host = wp_parse_url( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $line ) ? $line : 'https://' . $line, PHP_URL_HOST );
				if ( is_string( $host ) && preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
					$domains[] = strtolower( $host );
				}
			}
			$clean['redirect_domains'] = array_values( array_unique( $domains ) );
		}
		if ( isset( $input['youtube_label'] ) && is_string( $input['youtube_label'] ) ) {
			$clean['youtube_label'] = sanitize_text_field( $input['youtube_label'] );
		}
		if ( isset( $input['rum_sample'] ) ) {
			$clean['rum_sample'] = max( 0, min( 100, (int) $input['rum_sample'] ) );
		}
		foreach ( array( 'page_cache', 'cache_campaign' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset( $input['cache_ttl'] ) ) {
			$clean['cache_ttl'] = max( 1, min( 12, (int) $input['cache_ttl'] ) );
		}
		if ( isset( $input['cache_exclude'] ) ) {
			$paths = array();
			foreach ( self::lines( $input['cache_exclude'] ) as $line ) {
				$path = (string) wp_parse_url( $line, PHP_URL_PATH );
				if ( '' !== $path && preg_match( '#^/?[^\s<>"\'\\\\]*$#', $path ) ) {
					$paths[] = '/' . ltrim( $path, '/' );
				}
			}
			$clean['cache_exclude'] = array_values( array_unique( $paths ) );
		}
		if ( isset( $input['fonts']['preload'] ) && is_array( $input['fonts']['preload'] ) ) {
			$clean['fonts']['preload'] = array_values( array_filter( array_map( 'strval', $input['fonts']['preload'] ), array( __CLASS__, 'is_weight' ) ) );
		}
		return $clean;
	}

	/**
	 * @param string|array $value Textarea value or list.
	 * @return string[]
	 */
	private static function lines( $value ) {
		$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
		$clean = array();
		foreach ( $lines as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ) );
			if ( '' !== $line && strlen( $line ) <= 300 ) {
				$clean[] = $line;
			}
		}
		return array_slice( array_values( array_unique( $clean ) ), 0, 100 );
	}

	/**
	 * @param string $weight Font weight.
	 * @return bool
	 */
	public static function is_weight( $weight ) {
		return (bool) preg_match( '/^[1-9]00$/', (string) $weight );
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,string>
	 */
	private function tabs() {
		return array(
			'overview'  => __( 'Overview', 'shd-performance' ),
			'checklist' => __( 'Checklist', 'shd-performance' ),
			'features'  => __( 'Optimizations', 'shd-performance' ),
			'cache'     => __( 'Page cache', 'shd-performance' ),
			'images'    => __( 'Images', 'shd-performance' ),
			'fonts'     => __( 'Fonts', 'shd-performance' ),
			'vitals'    => __( 'Web Vitals', 'shd-performance' ),
			'tools'     => __( 'Tools', 'shd-performance' ),
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';

		echo '<div class="wrap shd-perf">';
		echo '<h1>' . esc_html__( 'SHD Performance', 'shd-performance' ) . ' <span class="shd-version">' . esc_html( SHD_PERF_VERSION ) . '</span></h1>';
		$this->notices();
		echo '<nav class="nav-tab-wrapper shd-tabs" aria-label="' . esc_attr__( 'Dashboard sections', 'shd-performance' ) . '">';
		foreach ( $tabs as $id => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $id ) ),
				$id === $tab ? ' nav-tab-active' : '',
				$id === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div class="shd-body">';
		call_user_func( array( $this, 'tab_' . $tab ) );
		echo '</div></div>';
	}

	private function notices() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
		if ( defined( 'SHD_PERFORMANCE_DISABLE' ) && SHD_PERFORMANCE_DISABLE ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'SHD_PERFORMANCE_DISABLE is set in wp-config.php: no optimization is applied.', 'shd-performance' ) . '</p></div>';
		}
		$error = get_option( Plugin::ERROR_OPT );
		if ( is_array( $error ) ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				/* translators: 1: date, 2: page, 3: message */
				esc_html( sprintf( __( 'On %1$s a page (%2$s) was delivered without optimizations because of an error: %3$s', 'shd-performance' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] ), $error['url'], $error['message'] ) ),
				esc_url( self::url( 'tools' ) . '#error' ),
				esc_html__( 'Details', 'shd-performance' )
			);
		}
	}

	/* Small output helpers. */

	/**
	 * Opening tag and hidden fields of a dashboard form.
	 *
	 * @param string $action    Action name.
	 * @param bool   $multipart File uploads.
	 * @param string $class     CSS class.
	 */
	private function form_open( $action, $multipart = false, $class = '' ) {
		printf(
			'<form method="post" action="%1$s"%2$s%3$s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$multipart ? ' enctype="multipart/form-data"' : '',
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : ''
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( 'shd_perf_' . $action ) );
		wp_nonce_field( 'shd_perf_' . $action );
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action  Action name.
	 * @param string $label   Button text.
	 * @param array  $fields  Hidden fields.
	 * @param string $class   Button classes.
	 * @param string $confirm Confirmation question.
	 */
	private function button( $action, $label, array $fields = array(), $class = 'button', $confirm = '' ) {
		$this->form_open( $action, false, 'shd-inline-form' );
		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
		}
		printf(
			'<button type="submit" class="%1$s"%2$s>%3$s</button></form>',
			esc_attr( $class ),
			'' !== $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : '',
			esc_html( $label )
		);
	}

	/**
	 * @param string $status Status key.
	 * @param string $label  Text.
	 * @return string HTML.
	 */
	private static function badge( $status, $label ) {
		return '<span class="shd-badge shd-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @param string $status done|open|unknown.
	 * @return string HTML.
	 */
	private static function task_badge( $status ) {
		$labels = array(
			'done'    => __( 'Done', 'shd-performance' ),
			'open'    => __( 'To do', 'shd-performance' ),
			'unknown' => __( 'Not checked', 'shd-performance' ),
		);
		return self::badge( $status, isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
	}

	/**
	 * @param string $status good|warn|bad|info.
	 * @return string HTML.
	 */
	private static function health_badge( $status ) {
		$labels = array(
			'good' => __( 'Good', 'shd-performance' ),
			'warn' => __( 'Improve', 'shd-performance' ),
			'bad'  => __( 'Problem', 'shd-performance' ),
			'info' => __( 'Info', 'shd-performance' ),
		);
		return self::badge( $status, isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
	}

	/**
	 * @param int $time Timestamp.
	 * @return string
	 */
	private static function when( $time ) {
		/* translators: %s: time difference */
		return sprintf( __( '%s ago', 'shd-performance' ), human_time_diff( (int) $time ) );
	}

	/**
	 * @return string Front-end link that switches the current admin to the Test view.
	 */
	private static function test_view_url( $view = 'test' ) {
		return wp_nonce_url( add_query_arg( 'shd-perf-view', $view, home_url( '/' ) ), 'shd-perf-view' );
	}

	/**
	 * @return bool Whether the current admin has the Test view on.
	 */
	private static function admin_in_test_view() {
		return isset( $_COOKIE[ Request::VIEW_COOKIE ] ) && 'test' === $_COOKIE[ Request::VIEW_COOKIE ];
	}

	/**
	 * @return array<string,int> Feature counts per state.
	 */
	private static function state_counts() {
		$counts = array(
			'live' => 0,
			'test' => 0,
			'off'  => 0,
		);
		foreach ( array_keys( Settings::features() ) as $id ) {
			++$counts[ Settings::state( $id ) ];
		}
		return $counts;
	}

	/* ------------------------------------------------------------------
	 * Overview
	 * ------------------------------------------------------------------ */

	private function tab_overview() {
		$counts   = self::state_counts();
		$progress = Checklist::progress();
		$next     = Checklist::next();
		$health   = Health_Checks::last();
		$preview  = add_query_arg( 'shd-preview', Settings::preview_token(), home_url( '/' ) );

		echo '<div class="shd-grid">';

		// Automatic optimizations.
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Automatic optimizations', 'shd-performance' ) . '</h2>';
		echo '<p class="shd-stats">';
		echo self::badge( 'live', sprintf( /* translators: %d: count */ _n( '%d Live', '%d Live', $counts['live'], 'shd-performance' ), $counts['live'] ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		echo self::badge( 'test', sprintf( /* translators: %d: count */ _n( '%d in Test', '%d in Test', $counts['test'], 'shd-performance' ), $counts['test'] ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::badge( 'off', sprintf( /* translators: %d: count */ _n( '%d Off', '%d Off', $counts['off'], 'shd-performance' ), $counts['off'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</p>';
		echo '<p>' . esc_html__( 'Live features apply to every visitor. Test features apply only in the Test view, so you can check them before visitors get them.', 'shd-performance' ) . '</p>';
		if ( $counts['test'] > 0 ) {
			echo '<p><strong>' . esc_html__( 'Next: check the Test features in the Test view, then switch them to Live on the Optimizations tab. They contain the largest mobile-score gains.', 'shd-performance' ) . '</strong></p>';
		}
		echo '<p><a class="button" href="' . esc_url( self::url( 'features' ) ) . '">' . esc_html__( 'Optimizations', 'shd-performance' ) . '</a></p>';
		echo '</section>';

		// Page cache.
		$cache = Page_Cache::status();
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Page cache', 'shd-performance' ) . '</h2>';
		echo '<p class="shd-stats">' . self::cache_badge( $cache ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
		echo '<p>' . esc_html( self::cache_summary( $cache ) ) . '</p>';
		echo '<p><a class="button' . ( 'active' === $cache['state'] ? '' : ' button-primary' ) . '" href="' . esc_url( self::url( 'cache' ) ) . '">' . esc_html__( 'Page cache', 'shd-performance' ) . '</a></p>';
		echo '</section>';

		// Checklist.
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Manual tasks', 'shd-performance' ) . '</h2>';
		$this->progress_bar( $progress['done'], $progress['total'] );
		if ( null !== $next ) {
			echo '<p>' . esc_html__( 'Next task:', 'shd-performance' ) . ' <strong>' . esc_html( $next['task']['title'] ) . '</strong></p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'checklist', array( 'step' => $next['step'] ) ) ) . '">' . esc_html__( 'Continue the step-by-step guide', 'shd-performance' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( self::url( 'checklist' ) ) . '">' . esc_html__( 'All tasks', 'shd-performance' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'All required tasks are done.', 'shd-performance' ) . '</p>';
		}
		echo '</section>';

		// Preview.
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Test view and preview link', 'shd-performance' ) . '</h2>';
		echo '<p>' . ( self::admin_in_test_view() ? esc_html__( 'You are browsing the site in the Test view (Live and Test features).', 'shd-performance' ) : esc_html__( 'You are browsing the site in the Live view (what visitors get).', 'shd-performance' ) ) . '</p>';
		echo '<p>';
		if ( self::admin_in_test_view() ) {
			echo '<a class="button" href="' . esc_url( self::test_view_url( 'live' ) ) . '">' . esc_html__( 'Switch to the Live view', 'shd-performance' ) . '</a> ';
		} else {
			echo '<a class="button button-primary" href="' . esc_url( self::test_view_url() ) . '">' . esc_html__( 'Open the site in the Test view', 'shd-performance' ) . '</a> ';
		}
		echo '<a class="button" href="' . esc_url( add_query_arg( 'shd-off', '1', home_url( '/' ) ) ) . '">' . esc_html__( 'Compare: homepage without optimizations', 'shd-performance' ) . '</a>';
		echo '</p>';
		echo '<p><label for="shd-preview-link">' . esc_html__( 'Secret link to the Test view, for phones and people without a login (also works for PageSpeed Insights):', 'shd-performance' ) . '</label><br>';
		echo '<input id="shd-preview-link" class="large-text code" type="text" readonly value="' . esc_attr( $preview ) . '" onfocus="this.select()"></p>';
		echo '<div class="shd-actions">';
		echo '<a class="button" href="' . esc_url( 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $preview ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Test the preview in PageSpeed Insights', 'shd-performance' ) . '</a> ';
		$this->button( 'token', __( 'Create a new link', 'shd-performance' ), array(), 'button-link', __( 'The current preview link will stop working. Continue?', 'shd-performance' ) );
		echo '</div>';
		echo '</section>';

		// Health.
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Health checks', 'shd-performance' ) . '</h2>';
		if ( null === $health ) {
			echo '<p>' . esc_html__( 'The health checks request the homepage the way a phone, a computer and an ad click would, and check the server settings. They take about half a minute.', 'shd-performance' ) . '</p>';
		} else {
			$statuses = array_count_values( wp_list_pluck( $health['results'], 'status' ) );
			echo '<p class="shd-stats">';
			foreach ( array( 'good', 'warn', 'bad' ) as $status ) {
				if ( ! empty( $statuses[ $status ] ) ) {
					echo self::health_badge( $status ) . ' ' . esc_html( (string) $statuses[ $status ] ) . ' &nbsp; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				}
			}
			echo '</p><p class="description">' . esc_html( sprintf( /* translators: %s: time */ __( 'Last run %s.', 'shd-performance' ), self::when( $health['time'] ) ) ) . '</p>';
			$problems = array_filter(
				$health['results'],
				static function ( $result ) {
					return in_array( $result['status'], array( 'bad', 'warn' ), true );
				}
			);
			if ( ! empty( $problems ) ) {
				echo '<ul class="shd-list">';
				foreach ( array_slice( $problems, 0, 5 ) as $result ) {
					echo '<li>' . self::health_badge( $result['status'] ) . ' ' . esc_html( $result['label'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				}
				echo '</ul>';
			}
		}
		echo '<div class="shd-actions">';
		$this->button( 'health', null === $health ? __( 'Run health checks', 'shd-performance' ) : __( 'Run again', 'shd-performance' ), array(), 'button' );
		echo ' <a class="button-link" href="' . esc_url( self::url( 'tools' ) . '#health' ) . '">' . esc_html__( 'All results', 'shd-performance' ) . '</a></div>';
		echo '</section>';

		// Vitals.
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Real-visitor Core Web Vitals (phones)', 'shd-performance' ) . '</h2>';
		$summary = Rum::summary();
		$this->vitals_assessment( $summary['m'] );
		echo '<p><a class="button" href="' . esc_url( self::url( 'vitals' ) ) . '">' . esc_html__( 'Web Vitals', 'shd-performance' ) . '</a></p>';
		echo '</section>';

		echo '</div>';
	}

	/**
	 * @param int $done  Done.
	 * @param int $total Total.
	 */
	private function progress_bar( $done, $total ) {
		$percent = $total > 0 ? (int) round( 100 * $done / $total ) : 0;
		printf(
			'<div class="shd-progress" role="progressbar" aria-valuemin="0" aria-valuemax="%1$d" aria-valuenow="%2$d"><span style="width:%3$d%%"></span></div><p class="shd-progress-label">%4$s</p>',
			(int) $total,
			(int) $done,
			(int) $percent,
			/* translators: 1: done, 2: total */
			esc_html( sprintf( __( '%1$d of %2$d required tasks done', 'shd-performance' ), $done, $total ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Checklist
	 * ------------------------------------------------------------------ */

	private function tab_checklist() {
		$tasks = Checklist::tasks();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 0;
		if ( $step > 0 ) {
			$this->wizard( $tasks, $step );
			return;
		}

		$progress = Checklist::progress();
		$next     = Checklist::next();
		echo '<div class="shd-intro">';
		echo '<p>' . esc_html__( 'These steps need a person: settings in other plugins, at the host or in content. Each task explains why it matters and exactly what to do. Where the plugin can detect the result, the status updates by itself (after the health checks or the page scan on the Tools tab); otherwise mark the task as done.', 'shd-performance' ) . '</p>';
		$this->progress_bar( $progress['done'], $progress['total'] );
		if ( null !== $next ) {
			echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'checklist', array( 'step' => $next['step'] ) ) ) . '">' . esc_html__( 'Start the step-by-step guide', 'shd-performance' ) . '</a></p>';
		}
		echo '</div>';

		$group = '';
		foreach ( $tasks as $index => $task ) {
			if ( $task['group'] !== $group ) {
				$group = $task['group'];
				echo '<h2 class="shd-group">' . esc_html( $group ) . '</h2>';
			}
			$status = Checklist::status( $task );
			$open   = null !== $next && $next['task']['id'] === $task['id'];
			printf( '<details class="shd-task shd-task--%1$s" id="task-%2$s"%3$s>', esc_attr( $status['status'] ), esc_attr( $task['id'] ), $open ? ' open' : '' );
			echo '<summary>' . self::task_badge( $status['status'] ) . ' <span class="shd-task__title">' . esc_html( $task['title'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			if ( ! empty( $task['optional'] ) ) {
				echo ' ' . self::badge( 'optional', __( 'Optional', 'shd-performance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</summary><div class="shd-task__body">';
			$this->task_body( $task, $status );
			echo '<div class="shd-actions">';
			echo '<a class="button-link" href="' . esc_url( self::url( 'checklist', array( 'step' => $index + 1 ) ) ) . '">' . esc_html__( 'Open in the guide', 'shd-performance' ) . '</a> ';
			$this->mark_button( $task, $status );
			echo '</div></div></details>';
		}
	}

	/**
	 * One task at a time, with back/skip/done navigation.
	 *
	 * @param array $tasks Tasks.
	 * @param int   $step  1-based step.
	 */
	private function wizard( array $tasks, $step ) {
		$total = count( $tasks );
		echo '<p><a href="' . esc_url( self::url( 'checklist' ) ) . '">&larr; ' . esc_html__( 'All tasks', 'shd-performance' ) . '</a></p>';
		if ( $step > $total ) {
			$progress = Checklist::progress();
			echo '<section class="shd-card shd-wizard">';
			echo '<h2>' . esc_html__( 'You have gone through every task', 'shd-performance' ) . '</h2>';
			$this->progress_bar( $progress['done'], $progress['total'] );
			$next = Checklist::next();
			if ( null !== $next ) {
				echo '<p>' . esc_html__( 'Some tasks are still open. You can come back to them any time.', 'shd-performance' ) . '</p>';
				echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'checklist', array( 'step' => $next['step'] ) ) ) . '">' . esc_html__( 'Go to the first open task', 'shd-performance' ) . '</a></p>';
			}
			echo '</section>';
			return;
		}

		$task   = $tasks[ $step - 1 ];
		$status = Checklist::status( $task );
		echo '<section class="shd-card shd-wizard">';
		/* translators: 1: step, 2: steps, 3: group */
		echo '<p class="shd-eyebrow">' . esc_html( sprintf( __( 'Step %1$d of %2$d · %3$s', 'shd-performance' ), $step, $total, $task['group'] ) ) . '</p>';
		echo '<h2>' . esc_html( $task['title'] ) . ' ' . self::task_badge( $status['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
		if ( ! empty( $task['optional'] ) ) {
			echo ' ' . self::badge( 'optional', __( 'Optional', 'shd-performance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</h2>';
		$this->task_body( $task, $status );

		echo '<div class="shd-wizard__nav">';
		if ( $step > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( 'checklist', array( 'step' => $step - 1 ) ) ) . '">&larr; ' . esc_html__( 'Back', 'shd-performance' ) . '</a> ';
		}
		echo '<a class="button" href="' . esc_url( self::url( 'checklist', array( 'step' => $step + 1 ) ) ) . '">' . esc_html__( 'Skip for now', 'shd-performance' ) . ' &rarr;</a> ';
		if ( 'done' === $status['status'] ) {
			echo '<a class="button button-primary" href="' . esc_url( self::url( 'checklist', array( 'step' => $step + 1 ) ) ) . '">' . esc_html__( 'Next', 'shd-performance' ) . ' &rarr;</a> ';
			if ( $status['manual'] ) {
				$this->button(
					'task',
					__( 'Reopen', 'shd-performance' ),
					array(
						'task'      => $task['id'],
						'done'      => '0',
						'next_step' => (string) $step,
					),
					'button-link'
				);
			}
		} else {
			$this->button(
				'task',
				__( 'Mark as done and continue', 'shd-performance' ) . ' →',
				array(
					'task'      => $task['id'],
					'done'      => '1',
					'next_step' => (string) ( $step + 1 ),
				),
				'button button-primary'
			);
		}
		echo '</div></section>';
	}

	/**
	 * @param array $task   Task.
	 * @param array $status Status.
	 */
	private function task_body( array $task, array $status ) {
		if ( '' !== $status['note'] ) {
			echo '<p class="shd-task__status"><strong>' . esc_html__( 'Detected:', 'shd-performance' ) . '</strong> ' . esc_html( $status['note'] ) . '</p>';
		}
		echo '<p class="shd-task__why"><strong>' . esc_html__( 'Why:', 'shd-performance' ) . '</strong> ' . esc_html( $task['why'] ) . '</p>';
		echo '<ol class="shd-steps">';
		foreach ( $task['steps'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ol>';
		if ( ! empty( $task['links'] ) ) {
			echo '<p class="shd-links">';
			foreach ( $task['links'] as $label => $href ) {
				$external = 0 !== strpos( $href, admin_url() );
				printf(
					'<a class="button button-small" href="%1$s"%2$s>%3$s%4$s</a> ',
					esc_url( $href ),
					$external ? ' target="_blank" rel="noopener"' : '',
					esc_html( $label ),
					$external ? ' <span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'shd-performance' ) . '</span><span aria-hidden="true">↗</span>' : ''
				);
			}
			echo '</p>';
		}
	}

	/**
	 * @param array $task   Task.
	 * @param array $status Status.
	 */
	private function mark_button( array $task, array $status ) {
		if ( 'done' !== $status['status'] ) {
			$this->button(
				'task',
				__( 'Mark as done', 'shd-performance' ),
				array(
					'task' => $task['id'],
					'done' => '1',
				)
			);
		} elseif ( $status['manual'] ) {
			$this->button(
				'task',
				__( 'Reopen', 'shd-performance' ),
				array(
					'task' => $task['id'],
					'done' => '0',
				),
				'button-link'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Optimizations
	 * ------------------------------------------------------------------ */

	private function tab_features() {
		$settings = Settings::get();
		$counts   = self::state_counts();
		$groups   = array(
			'content'     => __( 'Content and images', 'shd-performance' ),
			'third-party' => __( 'Third-party scripts', 'shd-performance' ),
			'javascript'  => __( 'JavaScript', 'shd-performance' ),
			'fonts'       => __( 'Fonts', 'shd-performance' ),
			'layout'      => __( 'Layout stability', 'shd-performance' ),
			'agents'      => __( 'Accessibility and AI agents', 'shd-performance' ),
			'monitoring'  => __( 'Monitoring', 'shd-performance' ),
		);
		$labels   = array(
			'off'  => __( 'Off', 'shd-performance' ),
			'test' => __( 'Test', 'shd-performance' ),
			'live' => __( 'Live', 'shd-performance' ),
		);

		echo '<div class="shd-intro"><p>' . esc_html__( 'Every change here is automatic and reversible: nothing in Elementor, your content or other plugins is modified. Off = not applied. Test = only in the Test view (for you and the secret preview link). Live = for every visitor.', 'shd-performance' ) . '</p>';
		if ( $counts['test'] > 0 ) {
			echo '<div class="shd-actions">';
			echo '<a class="button" href="' . esc_url( self::test_view_url() ) . '">' . esc_html__( 'Check the Test features on the site', 'shd-performance' ) . '</a> ';
			$this->button( 'promote', __( 'Switch all Test features to Live', 'shd-performance' ), array(), 'button button-primary', __( 'Have you checked the Test view (menus, forms, cookie banner, video, carousels)? Switch every Test feature to Live?', 'shd-performance' ) );
			echo '</div>';
		}
		echo '</div>';

		$this->form_open( 'save', false, 'shd-features' );
		$by_group = array();
		foreach ( Settings::features() as $id => $feature ) {
			$by_group[ $feature['group'] ][ $id ] = $feature;
		}
		foreach ( $groups as $group => $group_label ) {
			if ( empty( $by_group[ $group ] ) ) {
				continue;
			}
			echo '<h2 class="shd-group">' . esc_html( $group_label ) . '</h2>';
			foreach ( $by_group[ $group ] as $id => $feature ) {
				$state = Settings::state( $id );
				echo '<div class="shd-feature">';
				echo '<div class="shd-feature__text"><h3 id="shd-f-' . esc_attr( $id ) . '">' . esc_html( $feature['title'] ) . ' ';
				echo 'low' === $feature['risk'] ? self::badge( 'low', __( 'Safe', 'shd-performance' ) ) : self::badge( 'medium', __( 'Check in Test view first', 'shd-performance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				echo '</h3><p>' . esc_html( $feature['fixes'] ) . '</p></div>';
				echo '<fieldset class="shd-seg" aria-labelledby="shd-f-' . esc_attr( $id ) . '">';
				foreach ( $labels as $value => $label ) {
					printf(
						'<label><input type="radio" name="shd[states][%1$s]" value="%2$s"%3$s><span class="shd-seg__%2$s">%4$s</span></label>',
						esc_attr( $id ),
						esc_attr( $value ),
						checked( $state, $value, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset></div>';
			}
		}

		echo '<details class="shd-advanced"><summary>' . esc_html__( 'Advanced settings', 'shd-performance' ) . '</summary><table class="form-table" role="presentation"><tbody>';
		$this->number_row( 'first_screen_sections', __( 'Sections in the first screen', 'shd-performance' ), $settings['first_screen_sections'], 1, 6, __( 'How many top-level sections of a page count as the first screen (main-image priority, lazy-loading, Elementor widget CSS).', 'shd-performance' ) );
		echo '<tr><th scope="row"><label for="shd-animation-mode">' . esc_html__( 'Entrance animations', 'shd-performance' ) . '</label></th><td><select id="shd-animation-mode" name="shd[animation_mode]">';
		printf( '<option value="all"%1$s>%2$s</option>', selected( $settings['animation_mode'], 'all', false ), esc_html__( 'Remove everywhere (fastest)', 'shd-performance' ) );
		printf( '<option value="keep_below"%1$s>%2$s</option>', selected( $settings['animation_mode'], 'keep_below', false ), esc_html__( 'Keep below the first screen', 'shd-performance' ) );
		echo '</select><p class="description">' . esc_html__( '“Keep below the first screen” keeps the animations further down the page; they then depend on Elementor’s JavaScript again.', 'shd-performance' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="shd-toc-phones">' . esc_html__( 'Table of contents on phones', 'shd-performance' ) . '</label></th><td><select id="shd-toc-phones" name="shd[toc_phones]">';
		printf( '<option value="expanded"%1$s>%2$s</option>', selected( $settings['toc_phones'], 'expanded', false ), esc_html__( 'Open, as designed', 'shd-performance' ) );
		printf( '<option value="collapsed"%1$s>%2$s</option>', selected( $settings['toc_phones'], 'collapsed', false ), esc_html__( 'Collapsed, with a button to open it', 'shd-performance' ) );
		echo '</select><p class="description">' . esc_html__( 'On posts with many headings the open table of contents fills the first screen on phones and pushes the featured image out of it. For first-time visitors the cookie banner then becomes the largest element, which PageSpeed counts as a slow LCP (about 4.5 s instead of 2–3 s; mobile score about 80 instead of 88–96 on the affected posts). “Collapsed” shows the heading with an arrow button that opens the list. Desktop is not affected.', 'shd-performance' ) . '</p></td></tr>';
		$this->number_row( 'delay_timeout', __( 'Start delayed scripts after (seconds)', 'shd-performance' ), $settings['delay_timeout'], 0, 60, __( 'Delayed scripts start on the first interaction or after this time. 0 = only on interaction (visitors who leave without scrolling or tapping are then not counted by analytics).', 'shd-performance' ) );
		$this->lines_row( 'tracking_patterns', __( 'Tracking scripts to delay', 'shd-performance' ), $settings['tracking_patterns'], __( 'One part of a script address per line.', 'shd-performance' ) );
		$this->lines_row( 'delay_exclusions', __( 'Never delay', 'shd-performance' ), $settings['delay_exclusions'], __( 'Scripts whose address contains one of these lines always load normally.', 'shd-performance' ) );
		$this->lines_row( 'consent_scripts', __( 'Cookie banner scripts', 'shd-performance' ), $settings['consent_scripts'], __( 'Downloaded early without blocking and started at the top of the page body; never delayed.', 'shd-performance' ) );
		$this->lines_row( 'keep_inline', __( 'Inline scripts to run immediately', 'shd-performance' ), $settings['keep_inline'], __( 'Inline scripts containing one of these texts are never deferred or delayed.', 'shd-performance' ) );
		echo '<tr><th scope="row"><label for="shd-youtube-label">' . esc_html__( 'Video play button label', 'shd-performance' ) . '</label></th><td><input id="shd-youtube-label" class="regular-text" type="text" name="shd[youtube_label]" value="' . esc_attr( $settings['youtube_label'] ) . '"><p class="description">' . esc_html__( 'Read by screen readers. Empty = “Video abspielen” on German sites, “Play video” otherwise.', 'shd-performance' ) . '</p></td></tr>';
		$this->number_row( 'rum_sample', __( 'Web Vitals sample (%)', 'shd-performance' ), $settings['rum_sample'], 0, 100, __( 'Share of page views that report their Core Web Vitals.', 'shd-performance' ) );
		$this->lines_row( 'redirect_domains', __( 'Old domains to check', 'shd-performance' ), $settings['redirect_domains'], __( 'Checked by the health checks: each should redirect to this site in one step.', 'shd-performance' ) );
		echo '</tbody></table></details>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'shd-performance' ) . '</button></p></form>';

		echo '<hr><div class="shd-actions">';
		$this->button( 'reset', __( 'Reset all optimizations to the defaults', 'shd-performance' ), array(), 'button-link button-link-delete', __( 'Reset every optimization and advanced setting to its default?', 'shd-performance' ) );
		echo '</div>';
	}

	/**
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @param string $help  Description.
	 */
	private function number_row( $key, $label, $value, $min, $max, $help ) {
		printf(
			'<tr><th scope="row"><label for="shd-%1$s">%2$s</label></th><td><input id="shd-%1$s" class="small-text" type="number" name="shd[%1$s]" value="%3$d" min="%4$d" max="%5$d"><p class="description">%6$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $value,
			(int) $min,
			(int) $max,
			esc_html( $help )
		);
	}

	/**
	 * @param string   $key   Setting.
	 * @param string   $label Label.
	 * @param string[] $value Lines.
	 * @param string   $help  Description.
	 */
	private function lines_row( $key, $label, $value, $help ) {
		printf(
			'<tr><th scope="row"><label for="shd-%1$s">%2$s</label></th><td><textarea id="shd-%1$s" class="large-text code" rows="4" name="shd[%1$s]">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( implode( "\n", (array) $value ) ),
			esc_html( $help )
		);
	}

	/* ------------------------------------------------------------------
	 * Page cache
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $status Page cache status.
	 * @return string HTML.
	 */
	private static function cache_badge( array $status ) {
		switch ( $status['state'] ) {
			case 'active':
				return self::badge( 'good', __( 'Active', 'shd-performance' ) );
			case 'blocked':
				return self::badge( 'warn', __( 'Waiting', 'shd-performance' ) );
			case 'off':
				return self::badge( 'off', __( 'Off', 'shd-performance' ) );
			default:
				return self::badge( 'bad', __( 'Not working', 'shd-performance' ) );
		}
	}

	/**
	 * One sentence about the page cache state.
	 *
	 * @param array $status Page cache status.
	 * @return string
	 */
	private static function cache_summary( array $status ) {
		if ( 'active' === $status['state'] ) {
			$delivery = array(
				'htaccess' => __( 'Stored pages are sent directly by the web server (Apache, .htaccess), without PHP and WordPress.', 'shd-performance' ),
				'php'      => __( 'Stored pages are sent by a small PHP file (wp-content/advanced-cache.php) before WordPress loads.', 'shd-performance' ),
				'plugin'   => __( 'Stored pages are sent after WordPress has loaded its plugins: faster than without a cache, but see the note on the Page cache tab.', 'shd-performance' ),
			);
			return isset( $delivery[ $status['delivery'] ] ) ? $delivery[ $status['delivery'] ] : '';
		}
		if ( 'blocked' === $status['state'] && 'Kadence Performance' === $status['blocker'] ) {
			return __( 'Kadence Performance is still active and runs the page cache. Deactivate it: SHD Performance then takes over the page cache automatically.', 'shd-performance' );
		}
		if ( 'blocked' === $status['state'] && 'advanced-cache.php' === $status['blocker'] ) {
			return __( 'Another plugin’s wp-content/advanced-cache.php is in place. Only one page cache can run; deactivate the other cache plugin (it removes its file), then save the page cache settings here.', 'shd-performance' );
		}
		if ( 'blocked' === $status['state'] ) {
			/* translators: %s: plugin name */
			return sprintf( __( '%s is active and brings its own page cache. Only one page cache can run: deactivate it to use the SHD page cache, or switch the SHD page cache off.', 'shd-performance' ), $status['blocker'] );
		}
		if ( 'off' === $status['state'] ) {
			return __( 'Switched off: every visit waits for WordPress to build the page.', 'shd-performance' );
		}
		return implode( ' ', (array) $status['notes'] );
	}

	private function tab_cache() {
		$settings = Settings::get();
		$status   = Page_Cache::status();

		echo '<div class="shd-intro"><p>' . esc_html__( 'Stores every page as a finished file, with all optimizations applied. The next visitors get that file in a few milliseconds instead of waiting about 2.5 seconds for WordPress to build the page. Logged-in users, form submissions and addresses with parameters always get a fresh page. Replaces the page cache of Kadence Performance.', 'shd-performance' ) . '</p></div>';

		echo '<div class="shd-grid shd-grid--2">';
		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Status', 'shd-performance' ) . ' ' . self::cache_badge( $status ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
		echo '<p>' . esc_html( self::cache_summary( $status ) ) . '</p>';
		if ( 'blocked' === $status['state'] && 'Kadence Performance' === $status['blocker'] ) {
			echo '<ol class="shd-steps">';
			echo '<li>' . esc_html__( 'Plugins → Kadence Performance → Deactivate. It removes its own cache rules and its wp-content/advanced-cache.php.', 'shd-performance' ) . '</li>';
			echo '<li>' . esc_html__( 'SHD Performance installs its page cache in the same moment (advanced-cache.php, rules at the top of .htaccess) and caches every page in the background within a few minutes. WP_CACHE stays switched on in wp-config.php.', 'shd-performance' ) . '</li>';
			echo '<li>' . esc_html__( 'Come back here: the status should be “Active”. Then run the health checks on the Tools tab.', 'shd-performance' ) . '</li>';
			echo '<li>' . esc_html__( 'Afterwards you can delete Kadence Performance and its old cache folder wp-content/cache/solid-performance.', 'shd-performance' ) . '</li>';
			echo '</ol>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'shd-performance' ) . '</a></p>';
		}
		if ( 'active' === $status['state'] && ! empty( $status['notes'] ) ) {
			echo '<ul class="shd-list">';
			foreach ( (array) $status['notes'] as $note ) {
				echo '<li>' . esc_html( $note ) . '</li>';
			}
			echo '</ul>';
		}
		if ( 'active' === $status['state'] ) {
			$stats = Page_Cache::stats();
			echo '<table class="widefat striped shd-table"><tbody>';
			echo '<tr><th scope="row">' . esc_html__( 'Stored pages', 'shd-performance' ) . '</th><td>' . esc_html( sprintf( '%s (%s)', number_format_i18n( $stats['pages'] ), size_format( $stats['bytes'] ) ) ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Waiting to be cached', 'shd-performance' ) . '</th><td>' . esc_html( number_format_i18n( $stats['queue'] ) ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Last cleared', 'shd-performance' ) . '</th><td>' . esc_html( $stats['purged'] > 0 ? self::when( (int) $stats['purged'] ) : '—' ) . '</td></tr>';
			echo '</tbody></table>';
			echo '<div class="shd-actions">';
			$this->button( 'purge', __( 'Clear the page cache', 'shd-performance' ), array( 'tab' => 'cache' ) );
			echo ' ';
			$this->button( 'cache_preload', __( 'Cache all pages now', 'shd-performance' ) );
			echo '</div>';
		}
		echo '</section>';

		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Cleared automatically when', 'shd-performance' ) . '</h2>';
		echo '<ul class="shd-list">';
		foreach (
			array(
				__( 'a page or post is published, updated, unpublished or deleted;', 'shd-performance' ),
				__( 'you save in Elementor: pages, templates, header and footer, popups, site settings (global colours and fonts), and when display conditions change;', 'shd-performance' ),
				__( 'you press Elementor → Tools → “Clear Files & Data”;', 'shd-performance' ),
				__( 'menus, widgets, the Customizer, the theme, permalinks or the site title change;', 'shd-performance' ),
				__( 'plugins or themes are updated, switched on or off;', 'shd-performance' ),
				__( 'you save settings in SHD Performance.', 'shd-performance' ),
			) as $item
		) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
		echo '<p>' . esc_html__( 'Afterwards every page is cached again in the background. A new comment only clears its own post.', 'shd-performance' ) . '</p>';
		echo '<h3>' . esc_html__( 'Elementor’s Element Cache', 'shd-performance' ) . '</h3>';
		echo '<p>' . esc_html__( 'Elementor → Settings → Performance → “Element Cache” keeps the rendered widgets in the database (default: 1 day). That makes building a page faster, but WordPress still starts for every visit, so it does not replace a page cache. The two work together: visitors get the stored page from here, and when a page has to be built again, Element Cache makes that faster. Keep it on.', 'shd-performance' ) . '</p>';
		echo '<p>' . esc_html__( 'If a change does not show up, press Elementor → Tools → “Clear Files & Data”: it clears Elementor’s caches and generated CSS files, and the page cache here with them.', 'shd-performance' ) . '</p>';
		echo '</section>';
		echo '</div>';

		$this->form_open( 'cache_save' );
		echo '<h2>' . esc_html__( 'Settings', 'shd-performance' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Page cache', 'shd-performance' ) . '</th><td><input type="hidden" name="shd[page_cache]" value="0"><label><input type="checkbox" name="shd[page_cache]" value="1"' . checked( ! empty( $settings['page_cache'] ), true, false ) . '> ' . esc_html__( 'Store pages and send them without starting WordPress', 'shd-performance' ) . '</label></td></tr>';
		$this->number_row( 'cache_ttl', __( 'Keep pages for (hours)', 'shd-performance' ), $settings['cache_ttl'], 1, 12, __( 'Pages are rebuilt in the background before they expire. At most 12 hours: the pages contain security tokens for the forms and the newsletter sign-up, which WordPress accepts for 12 to 24 hours.', 'shd-performance' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Ad and campaign links', 'shd-performance' ) . '</th><td><input type="hidden" name="shd[cache_campaign]" value="0"><label><input type="checkbox" name="shd[cache_campaign]" value="1"' . checked( ! empty( $settings['cache_campaign'] ), true, false ) . '> ' . esc_html__( 'Send the cached page to addresses that only add marketing parameters (?gclid=…, ?utm_…, ?fbclid=…)', 'shd-performance' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Visitors from Google Ads and campaigns then get the fast page too. Switch this on only after the checklist task “Load Google Analytics 4 from one place”: MonsterInsights writes the address of the request into the page on the server, so on a cached page it would not see the gclid. The tracking scripts in the browser still read the parameters from the address.', 'shd-performance' ) . '</p></td></tr>';
		$this->lines_row( 'cache_exclude', __( 'Never cache', 'shd-performance' ), $settings['cache_exclude'], __( 'One address path per line, for example /kontakt/ (also excludes the pages below it). * matches anything, for example /blog/*/print/.', 'shd-performance' ) );
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save page cache settings', 'shd-performance' ) . '</button></p></form>';
	}

	/* ------------------------------------------------------------------
	 * Images
	 * ------------------------------------------------------------------ */

	private function tab_images() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$id = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;
		if ( $id > 0 ) {
			$this->image_screen( $id );
			return;
		}

		echo '<div class="shd-intro"><p>' . esc_html__( 'Replace the file of an image once, here, instead of choosing a new picture in every page. The image keeps its place in the media library (same ID, title, alt text and caption); WordPress makes its sizes again, and every page, Elementor template, header, footer and setting that links to it is switched to the new file. Elementor’s CSS and the page cache are cleared automatically. The old files stay on the server until you delete them, so each replacement can be undone.', 'shd-performance' ) . '</p>';
		echo '<p>' . esc_html__( '“Make lighter” turns the current file into a WebP file with the same width and height. At quality 90 it looks the same as the original; photos saved as PNG usually become 80–90% smaller, JPEG files 20–40%. You compare both before anything changes. Or upload a file you prepared yourself.', 'shd-performance' ) . '</p></div>';

		$images = Site_Scanner::large_images( 40, 150 * KB_IN_BYTES );
		echo '<h2>' . esc_html__( 'Largest image files', 'shd-performance' ) . '</h2>';
		if ( empty( $images ) ) {
			echo '<p>' . esc_html__( 'No image file over 150 KB.', 'shd-performance' ) . '</p>';
		} else {
			echo '<table class="widefat striped shd-table shd-images"><thead><tr><th><span class="screen-reader-text">' . esc_html__( 'Preview', 'shd-performance' ) . '</span></th><th>' . esc_html__( 'File', 'shd-performance' ) . '</th><th>' . esc_html__( 'Dimensions', 'shd-performance' ) . '</th><th>' . esc_html__( 'Size', 'shd-performance' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'shd-performance' ) . '</span></th></tr></thead><tbody>';
			foreach ( $images as $image ) {
				$file = (string) get_post_meta( (int) $image['id'], '_wp_attached_file', true );
				printf(
					'<tr><td>%1$s</td><td>%2$s<br><span class="description">%3$s</span></td><td>%4$s</td><td>%5$s</td><td><a class="button" href="%6$s">%7$s</a></td></tr>',
					wp_get_attachment_image( (int) $image['id'], array( 60, 60 ), false, array( 'class' => 'shd-thumb', 'loading' => 'lazy' ) ),
					esc_html( $image['file'] ),
					esc_html( dirname( $file ) ),
					esc_html( $image['width'] . ' × ' . $image['height'] ),
					esc_html( size_format( (int) $image['bytes'] ) ),
					esc_url( self::url( 'images', array( 'attachment' => (int) $image['id'] ) ) ),
					esc_html__( 'Make lighter or replace', 'shd-performance' )
				);
			}
			echo '</tbody></table>';
		}

		$replaced = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'meta_key'       => Media_Replace::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'modified',
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $replaced ) ) {
			echo '<h2>' . esc_html__( 'Replaced images', 'shd-performance' ) . '</h2>';
			echo '<table class="widefat striped shd-table shd-images"><thead><tr><th><span class="screen-reader-text">' . esc_html__( 'Preview', 'shd-performance' ) . '</span></th><th>' . esc_html__( 'File', 'shd-performance' ) . '</th><th>' . esc_html__( 'Before → after', 'shd-performance' ) . '</th><th>' . esc_html__( 'When', 'shd-performance' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'shd-performance' ) . '</span></th></tr></thead><tbody>';
			foreach ( $replaced as $rid ) {
				$record = Media_Replace::record( (int) $rid );
				if ( null === $record ) {
					continue;
				}
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td><a class="button" href="%5$s">%6$s</a></td></tr>',
					wp_get_attachment_image( (int) $rid, array( 60, 60 ), false, array( 'class' => 'shd-thumb', 'loading' => 'lazy' ) ),
					esc_html( wp_basename( $record['new']['file'] ) ),
					esc_html( size_format( (int) $record['old']['bytes'] ) . ' → ' . size_format( (int) $record['new']['bytes'] ) ),
					esc_html( self::when( (int) $record['time'] ) ),
					esc_url( self::url( 'images', array( 'attachment' => (int) $rid ) ) ),
					esc_html__( 'Details', 'shd-performance' )
				);
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * One image: where it is used, the lighter WebP version, an upload, and the last replacement.
	 *
	 * @param int $id Attachment ID.
	 */
	private function image_screen( $id ) {
		echo '<p><a href="' . esc_url( self::url( 'images' ) ) . '">← ' . esc_html__( 'All images', 'shd-performance' ) . '</a></p>';
		if ( ! Media_Replace::can_replace( $id ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This is not an image file in the media library (SVG files and missing files cannot be replaced here).', 'shd-performance' ) . '</p></div>';
			return;
		}
		$file   = (string) get_attached_file( $id );
		$rel    = (string) get_post_meta( $id, '_wp_attached_file', true );
		$meta   = wp_get_attachment_metadata( $id );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$bytes  = (int) filesize( $file );
		$record = Media_Replace::record( $id );

		echo '<h2>' . esc_html( get_the_title( $id ) ) . '</h2>';
		echo '<div class="shd-grid shd-grid--2">';

		echo '<section class="shd-card">';
		echo '<h2>' . esc_html__( 'Current file', 'shd-performance' ) . '</h2>';
		echo '<p>' . wp_get_attachment_image( $id, 'medium', false, array( 'class' => 'shd-current' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		echo '<table class="widefat striped shd-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'File', 'shd-performance' ) . '</th><td>' . esc_html( $rel ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Dimensions', 'shd-performance' ) . '</th><td>' . esc_html( $width . ' × ' . $height ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Size', 'shd-performance' ) . '</th><td>' . esc_html( size_format( $bytes ) . ' (' . get_post_mime_type( $id ) . ')' ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open at full size', 'shd-performance' ) . '</a> · <a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">' . esc_html__( 'Edit in the media library', 'shd-performance' ) . '</a></p>';

		$places = Media_Replace::usage( $id );
		echo '<h3>' . esc_html__( 'Used in', 'shd-performance' ) . '</h3>';
		if ( empty( $places ) ) {
			echo '<p>' . esc_html__( 'No page, template or setting links to this file directly. Where the image is chosen by its ID (for example a featured image shown by a theme template), the new file appears automatically.', 'shd-performance' ) . '</p>';
		} else {
			echo '<ul class="shd-list">';
			$labels = array();
			foreach ( $places as $place ) {
				$key = $place['label'];
				if ( isset( $labels[ $key ] ) ) {
					continue;
				}
				$labels[ $key ] = true;
				if ( count( $labels ) > 15 ) {
					break;
				}
				echo '<li>' . ( '' !== $place['edit'] ? '<a href="' . esc_url( $place['edit'] ) . '">' . esc_html( $place['label'] ) . '</a>' : esc_html( $place['label'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<section class="shd-card">';
		if ( null !== $record ) {
			echo '<h2>' . esc_html__( 'Last replacement', 'shd-performance' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: time ago, 2: old file, 3: old size, 4: new file, 5: new size, 6: number of places */
					__( '%1$s: %2$s (%3$s) was replaced by %4$s (%5$s), in %6$d places.', 'shd-performance' ),
					self::when( (int) $record['time'] ),
					wp_basename( $record['old']['file'] ),
					size_format( (int) $record['old']['bytes'] ),
					wp_basename( $record['new']['file'] ),
					size_format( (int) $record['new']['bytes'] ),
					(int) $record['places']
				)
			) . '</p>';
			$old = Media_Replace::old_files( $id );
			echo '<div class="shd-actions">';
			if ( empty( $record['old_deleted'] ) && $record['new']['file'] === $rel ) {
				$this->button( 'media_undo', __( 'Undo', 'shd-performance' ), array( 'attachment' => $id ), 'button', __( 'Switch every page back to the earlier file and delete the new one?', 'shd-performance' ) );
			}
			if ( ! empty( $old ) ) {
				$this->button(
					'media_cleanup',
					/* translators: %d: number of files */
					sprintf( _n( 'Delete the %d old file', 'Delete the %d old files', count( $old ), 'shd-performance' ), count( $old ) ),
					array( 'attachment' => $id ),
					'button',
					__( 'Delete the earlier files from the server? The replacement can then no longer be undone. Links to them from other websites or old newsletters stop working.', 'shd-performance' )
				);
			}
			echo '</div>';
			if ( ! empty( $old ) ) {
				echo '<p class="description">' . esc_html__( 'Keep the old files for a few days, until you have checked the pages. Search engines and old newsletters may still link to them.', 'shd-performance' ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Make lighter', 'shd-performance' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$quality = isset( $_GET['q'] ) ? (int) $_GET['q'] : Media_Replace::QUALITY;
		$quality = in_array( $quality, Media_Replace::QUALITIES, true ) ? $quality : Media_Replace::QUALITY;
		$preview = Media_Replace::preview( $id, $quality );
		if ( is_wp_error( $preview ) ) {
			echo '<p>' . esc_html( $preview->get_error_message() ) . '</p>';
		} else {
			$saved = $bytes > 0 ? (int) round( 100 * ( 1 - $preview['bytes'] / $bytes ) ) : 0;
			echo '<div class="shd-compare">';
			printf( '<figure><img src="%1$s" alt="" loading="lazy"><figcaption>%2$s</figcaption></figure>', esc_url( (string) wp_get_attachment_url( $id ) ), esc_html( sprintf( /* translators: %s: size */ __( 'Now: %s', 'shd-performance' ), size_format( $bytes ) ) ) );
			printf( '<figure><img src="%1$s" alt="" loading="lazy"><figcaption>%2$s</figcaption></figure>', esc_url( $preview['url'] ), esc_html( sprintf( /* translators: 1: size, 2: quality */ __( 'WebP: %1$s (quality %2$d)', 'shd-performance' ), size_format( $preview['bytes'] ), $quality ) ) );
			echo '</div>';
			/* translators: 1: width, 2: height, 3: percent */
			echo '<p>' . esc_html( sprintf( __( 'Same dimensions (%1$d × %2$d), %3$d%% smaller. Compare both at full size:', 'shd-performance' ), $preview['width'], $preview['height'], max( 0, $saved ) ) ) . ' <a href="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'now', 'shd-performance' ) . '</a> · <a href="' . esc_url( $preview['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'WebP', 'shd-performance' ) . '</a></p>';
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="shd-inline-form"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="images"><input type="hidden" name="attachment" value="' . esc_attr( (string) $id ) . '">';
			echo '<label>' . esc_html__( 'Quality', 'shd-performance' ) . ' <select name="q">';
			foreach ( Media_Replace::QUALITIES as $q ) {
				echo '<option value="' . esc_attr( (string) $q ) . '"' . selected( $q, $quality, false ) . '>' . esc_html( (string) $q . ( Media_Replace::QUALITY === $q ? ' – ' . __( 'recommended', 'shd-performance' ) : '' ) ) . '</option>';
			}
			echo '</select></label> <button type="submit" class="button">' . esc_html__( 'Show', 'shd-performance' ) . '</button></form>';
			echo '<div class="shd-actions">';
			if ( $saved >= 10 ) {
				$this->button( 'media_lighter', __( 'Use the WebP version everywhere', 'shd-performance' ), array( 'attachment' => $id, 'quality' => $quality ), 'button button-primary' );
			} else {
				echo '<p>' . esc_html__( 'This file is already compact: WebP would not make it noticeably smaller.', 'shd-performance' ) . '</p>';
			}
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Upload a new file', 'shd-performance' ) . '</h2>';
		/* translators: 1: width, 2: height, 3: maximum upload size */
		echo '<p>' . esc_html( sprintf( __( 'JPEG, PNG, WebP, AVIF or GIF. Ideally %1$d × %2$d pixels or larger, so the image stays sharp where it is shown large; pages keep their layout. Up to %3$s.', 'shd-performance' ), $width, $height, size_format( wp_max_upload_size() ) ) ) . '</p>';
		$this->form_open( 'media_upload', true, 'shd-inline-form' );
		echo '<input type="hidden" name="attachment" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="file" name="shd_file" accept="image/jpeg,image/png,image/webp,image/avif,image/gif" required> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Upload and replace everywhere', 'shd-performance' ) . '</button></form>';
		echo '</section>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * Fonts
	 * ------------------------------------------------------------------ */

	private function tab_fonts() {
		$faces    = Fonts::faces();
		$metrics  = Fonts::metrics();
		$settings = Settings::get();
		$woff2    = is_array( $settings['fonts']['woff2'] ) ? $settings['fonts']['woff2'] : array();

		echo '<div class="shd-intro"><p>' . esc_html__( 'Custom fonts are detected from the Elementor kit and the homepage. With the “Fonts” optimization on, text is shown immediately in a size-matched fallback, uploaded WOFF2 files replace the larger originals (same letters, same look), and the main weights are preloaded.', 'shd-performance' ) . '</p>';
		echo '<div class="shd-actions">';
		$this->button( 'fonts_detect', empty( $faces ) ? __( 'Detect fonts', 'shd-performance' ) : __( 'Detect again', 'shd-performance' ), array(), empty( $faces ) ? 'button button-primary' : 'button' );
		echo '</div></div>';

		if ( empty( $faces ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Font files', 'shd-performance' ) . '</h2>';
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Family', 'shd-performance' ) . '</th><th>' . esc_html__( 'Weight', 'shd-performance' ) . '</th><th>' . esc_html__( 'Style', 'shd-performance' ) . '</th><th>' . esc_html__( 'Current file', 'shd-performance' ) . '</th><th>' . esc_html__( 'WOFF2', 'shd-performance' ) . '</th><th>' . esc_html__( 'Latin subset (optional)', 'shd-performance' ) . '</th></tr></thead><tbody>';
		foreach ( $faces as $face ) {
			$key  = Fonts::key_for( $face['src'] );
			$path = Fonts::local_path( $face['src'] );
			$size = $path ? ' (' . size_format( (int) filesize( $path ) ) . ')' : '';
			echo '<tr><td>' . esc_html( $face['family'] ) . '</td><td>' . esc_html( $face['weight'] ) . '</td><td>' . esc_html( $face['style'] ) . '</td>';
			echo '<td><code>' . esc_html( basename( (string) wp_parse_url( $face['src'], PHP_URL_PATH ) ) ) . '</code>' . esc_html( $size ) . '</td><td>';
			if ( isset( $woff2[ $key ] ) ) {
				$local = Fonts::local_path( $woff2[ $key ] );
				echo self::badge( 'good', __( 'In use', 'shd-performance' ) ) . ' <code>' . esc_html( rawurldecode( basename( (string) wp_parse_url( $woff2[ $key ], PHP_URL_PATH ) ) ) ) . '</code>' . ( $local ? ' (' . esc_html( size_format( (int) filesize( $local ) ) ) . ')' : '' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				$this->button( 'fonts_remove', __( 'Remove', 'shd-performance' ), array( 'key' => $key ), 'button-link button-link-delete' );
			} elseif ( Fonts::has_woff2( $face ) ) {
				echo self::badge( 'good', __( 'Already WOFF2', 'shd-performance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			} else {
				echo self::badge( 'open', __( 'Not uploaded', 'shd-performance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			}
			echo '</td><td>';
			$subset_key = $key . Fonts::SUBSET_SUFFIX;
			if ( isset( $woff2[ $subset_key ] ) ) {
				$local = Fonts::local_path( $woff2[ $subset_key ] );
				echo self::badge( 'good', __( 'In use', 'shd-performance' ) ) . ' <code>' . esc_html( rawurldecode( basename( (string) wp_parse_url( $woff2[ $subset_key ], PHP_URL_PATH ) ) ) ) . '</code>' . ( $local ? ' (' . esc_html( size_format( (int) filesize( $local ) ) ) . ')' : '' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				$this->button( 'fonts_remove', __( 'Remove', 'shd-performance' ), array( 'key' => $subset_key ), 'button-link button-link-delete' );
			} else {
				echo '–';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Upload WOFF2 files', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Give each file the name of the original with the .woff2 extension (for example Fractul_Office-Regular.woff2). The files are stored in the uploads folder and used instead of the originals; Elementor is not changed. Make sure your font licence covers web use.', 'shd-performance' ) . '</p>';
		echo '<p>' . esc_html__( 'Optional, recommended: also upload a Latin subset of each file, named like Fractul_Office-Regular-latin.woff2. It contains every character German and English text needs (about 25 KB instead of 58 KB). Browsers use it for those characters and fetch the full file only when a page contains another character, so nothing is lost. A subset must cover exactly this range:', 'shd-performance' ) . ' <code>' . esc_html( Fonts::LATIN_RANGE ) . '</code></p>';
		$this->form_open( 'fonts_upload', true, 'shd-upload' );
		echo '<input type="file" name="shd_fonts[]" accept=".woff2,font/woff2" multiple required> <button type="submit" class="button button-primary">' . esc_html__( 'Upload', 'shd-performance' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Preload', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Preloaded fonts start downloading immediately. Choose only the weights used in the first screen (usually body text and headings); each extra file competes with the main image. Only uploaded WOFF2 files are preloaded.', 'shd-performance' ) . '</p>';
		$weights = array();
		foreach ( $faces as $face ) {
			if ( 'normal' === strtolower( $face['style'] ) && self::is_weight( $face['weight'] ) ) {
				$weights[ $face['weight'] ] = true;
			}
		}
		ksort( $weights );
		$this->form_open( 'fonts_preload' );
		echo '<fieldset class="shd-weights"><legend class="screen-reader-text">' . esc_html__( 'Weights to preload', 'shd-performance' ) . '</legend>';
		foreach ( array_keys( $weights ) as $weight ) {
			printf(
				'<label><input type="checkbox" name="preload[]" value="%1$s"%2$s> %1$s</label> ',
				esc_attr( (string) $weight ),
				checked( in_array( (string) $weight, array_map( 'strval', (array) $settings['fonts']['preload'] ), true ), true, false )
			);
		}
		echo '</fieldset><p><button type="submit" class="button">' . esc_html__( 'Save preload selection', 'shd-performance' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Size-matched fallback', 'shd-performance' ) . '</h2>';
		if ( empty( $metrics ) ) {
			echo '<p>' . esc_html__( 'No fallback could be calculated: this needs the original font as a local .ttf or .otf file.', 'shd-performance' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'While the web font loads, text is drawn in a local system font scaled to the same width and line height, so the page barely moves when the font arrives.', 'shd-performance' ) . '</p>';
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Family', 'shd-performance' ) . '</th><th>size-adjust</th><th>ascent-override</th><th>descent-override</th><th>line-gap-override</th></tr></thead><tbody>';
		foreach ( $metrics as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s%%</td><td>%3$s%%</td><td>%4$s%%</td><td>%5$s%%</td></tr>',
				esc_html( Fonts::fallback_name( $entry['family'] ) ),
				esc_html( number_format_i18n( (float) $entry['size_adjust'], 2 ) ),
				esc_html( number_format_i18n( (float) $entry['ascent'], 2 ) ),
				esc_html( number_format_i18n( (float) $entry['descent'], 2 ) ),
				esc_html( number_format_i18n( (float) $entry['line_gap'], 2 ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------
	 * Web Vitals
	 * ------------------------------------------------------------------ */

	private function tab_vitals() {
		if ( 'live' !== Settings::state( 'rum' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The “Measure real-visitor Core Web Vitals” optimization is not Live, so no visitor data is collected.', 'shd-performance' ) . '</p></div>';
		}
		echo '<div class="shd-intro"><p>' . esc_html__( 'Google decides whether a site passes the Core Web Vitals from real visits: at least 75% of page views need a good LCP (loading), INP (responsiveness) and CLS (visual stability). These numbers come from your visitors over the last 28 days (no cookies; no IP address or user agent stored).', 'shd-performance' ) . '</p></div>';

		$summary = Rum::summary();
		echo '<div class="shd-grid shd-grid--2">';
		foreach ( array(
			'm' => __( 'Phones', 'shd-performance' ),
			'd' => __( 'Tablets and computers', 'shd-performance' ),
		) as $device => $label ) {
			$row = $summary[ $device ];
			echo '<section class="shd-card"><h2>' . esc_html( $label ) . '</h2>';
			$this->vitals_assessment( $row );
			echo '<table class="widefat shd-table shd-vitals"><thead><tr><th>' . esc_html__( 'Metric', 'shd-performance' ) . '</th><th>' . esc_html__( '75th percentile', 'shd-performance' ) . '</th><th>' . esc_html__( 'Rating', 'shd-performance' ) . '</th><th>' . esc_html__( 'Good up to', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( Rum::METRICS as $metric ) {
				printf(
					'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
					esc_html( self::metric_name( $metric ) ),
					esc_html( self::metric_value( $metric, $row[ $metric ] ) ),
					self::rating_badge( Rum::rating( $metric, $row[ $metric ] ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
					esc_html( self::metric_value( $metric, Rum::THRESHOLDS[ $metric ][0] ) )
				);
			}
			echo '</tbody></table></section>';
		}
		echo '</div>';

		$pages = Rum::pages();
		echo '<h2>' . esc_html__( 'Pages', 'shd-performance' ) . '</h2>';
		if ( empty( $pages ) ) {
			echo '<p>' . esc_html__( 'Not enough page views yet (at least 3 per page and device).', 'shd-performance' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Page', 'shd-performance' ) . '</th><th>' . esc_html__( 'Device', 'shd-performance' ) . '</th><th>' . esc_html__( 'Page views', 'shd-performance' ) . '</th><th>LCP</th><th>INP</th><th>CLS</th></tr></thead><tbody>';
		foreach ( $pages as $page ) {
			echo '<tr><td><a href="' . esc_url( home_url( $page['path'] ) ) . '">' . esc_html( $page['path'] ) . '</a></td><td>' . esc_html( 'm' === $page['device'] ? __( 'Phone', 'shd-performance' ) : __( 'Computer', 'shd-performance' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $page['samples'] ) ) . '</td>';
			foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
				echo '<td>' . esc_html( self::metric_value( $metric, $page[ $metric ] ) ) . ' ' . self::rating_badge( Rum::rating( $metric, $page[ $metric ] ), true ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Pass/fail line for one device.
	 *
	 * @param array $row Summary row.
	 */
	private function vitals_assessment( array $row ) {
		if ( $row['count'] < 50 ) {
			/* translators: %d: page views */
			echo '<p>' . self::badge( 'unknown', __( 'Not enough data yet', 'shd-performance' ) ) . ' ' . esc_html( sprintf( _n( '%d page view measured in the last 28 days; about 50 are needed for a stable result.', '%d page views measured in the last 28 days; about 50 are needed for a stable result.', $row['count'], 'shd-performance' ), $row['count'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			if ( 0 === $row['count'] ) {
				return;
			}
		}
		$ratings = array();
		foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
			$ratings[ $metric ] = Rum::rating( $metric, $row[ $metric ] );
		}
		$passed = ! in_array( 'needs-improvement', $ratings, true ) && ! in_array( 'poor', $ratings, true ) && 'none' !== $ratings['lcp'] && 'none' !== $ratings['cls'];
		echo '<p class="shd-assessment">' . ( $passed ? self::badge( 'good', __( 'Core Web Vitals: passed', 'shd-performance' ) ) : self::badge( 'bad', __( 'Core Web Vitals: not passed', 'shd-performance' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
		foreach ( $ratings as $metric => $rating ) {
			echo ' <span class="shd-metric">' . esc_html( self::metric_name( $metric ) ) . ' ' . esc_html( self::metric_value( $metric, $row[ $metric ] ) ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * @param string $metric Metric.
	 * @return string
	 */
	private static function metric_name( $metric ) {
		$names = array(
			'lcp'  => 'LCP',
			'inp'  => 'INP',
			'cls'  => 'CLS',
			'fcp'  => 'FCP',
			'ttfb' => 'TTFB',
		);
		return isset( $names[ $metric ] ) ? $names[ $metric ] : strtoupper( $metric );
	}

	/**
	 * @param string     $metric Metric.
	 * @param float|null $value  Value.
	 * @return string
	 */
	private static function metric_value( $metric, $value ) {
		if ( null === $value ) {
			return '–';
		}
		if ( 'cls' === $metric ) {
			return number_format_i18n( (float) $value, 2 );
		}
		if ( 'inp' === $metric ) {
			return number_format_i18n( (float) $value ) . ' ms';
		}
		return number_format_i18n( (float) $value / 1000, 'ttfb' === $metric ? 2 : 1 ) . ' s';
	}

	/**
	 * @param string $rating  Rating.
	 * @param bool   $compact Short label.
	 * @return string HTML.
	 */
	private static function rating_badge( $rating, $compact = false ) {
		$labels = array(
			'good'              => __( 'Good', 'shd-performance' ),
			'needs-improvement' => $compact ? __( 'Improve', 'shd-performance' ) : __( 'Needs improvement', 'shd-performance' ),
			'poor'              => __( 'Poor', 'shd-performance' ),
			'none'              => __( 'No data', 'shd-performance' ),
		);
		$classes = array(
			'good'              => 'good',
			'needs-improvement' => 'warn',
			'poor'              => 'bad',
			'none'              => 'unknown',
		);
		return self::badge( $classes[ $rating ], $labels[ $rating ] );
	}

	/* ------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------ */

	private function tab_tools() {
		$this->tools_health();
		$this->tools_profile();
		$this->tools_scan();

		echo '<h2>' . esc_html__( 'Page cache', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Clears the SHD page cache (and any other page cache plugin that is active) and the rewritten CSS copies. Content changes clear the page cache automatically; use this after changing something elsewhere, for example a plugin setting.', 'shd-performance' ) . '</p>';
		echo '<div class="shd-actions">';
		$this->button( 'purge', __( 'Clear the page cache', 'shd-performance' ) );
		echo ' <a class="button-link" href="' . esc_url( self::url( 'cache' ) ) . '">' . esc_html__( 'Page cache status and settings', 'shd-performance' ) . '</a>';
		echo '</div>';

		echo '<h2 id="transfer">' . esc_html__( 'Export and import settings', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Moves the settings between staging and the live site (the secret preview link and uploaded font files are not included).', 'shd-performance' ) . '</p>';
		echo '<div class="shd-actions">';
		$this->button( 'export', __( 'Export settings', 'shd-performance' ) );
		$this->form_open( 'import', true, 'shd-inline-form' );
		echo ' <input type="file" name="shd_import" accept=".json,application/json" required> <button type="submit" class="button">' . esc_html__( 'Import', 'shd-performance' ) . '</button></form>';
		echo '</div>';

		echo '<h2 id="error">' . esc_html__( 'Diagnostics', 'shd-performance' ) . '</h2>';
		$error = get_option( Plugin::ERROR_OPT );
		echo '<table class="widefat striped shd-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Plugin version', 'shd-performance' ) . '</th><td>' . esc_html( SHD_PERF_VERSION ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'WordPress / PHP', 'shd-performance' ) . '</th><td>' . esc_html( get_bloginfo( 'version' ) . ' / ' . PHP_VERSION ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Last error', 'shd-performance' ) . '</th><td>';
		if ( is_array( $error ) ) {
			echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] ) . ' · ' . $error['url'] . ' · ' . $error['message'] ) . ' ';
			$this->button( 'clear_error', __( 'Clear', 'shd-performance' ), array(), 'button-link' );
		} else {
			esc_html_e( 'none', 'shd-performance' );
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Emergency switch', 'shd-performance' ) . '</th><td>' . esc_html__( 'If the dashboard cannot be reached, add define( \'SHD_PERFORMANCE_DISABLE\', true ); to wp-config.php, or deactivate the plugin: every optimization stops immediately, and the stored pages are no longer sent from the first page that WordPress builds after that. To stop them at once without WordPress, delete the folder wp-content/cache/shd-performance/pages (for example by FTP).', 'shd-performance' ) . '</td></tr>';
		echo '</tbody></table>';
	}

	private function tools_health() {
		$health = Health_Checks::last();
		echo '<h2 id="health">' . esc_html__( 'Health checks', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Requests the homepage like a phone, a computer, an ad click and an uncached visit, and checks server settings, redirects and robots.txt. Takes about half a minute.', 'shd-performance' ) . '</p>';
		echo '<div class="shd-actions">';
		$this->button( 'health', null === $health ? __( 'Run health checks', 'shd-performance' ) : __( 'Run again', 'shd-performance' ), array(), 'button button-primary' );
		if ( null !== $health ) {
			echo ' <span class="description">' . esc_html( sprintf( /* translators: %s: time */ __( 'Last run %s.', 'shd-performance' ), self::when( $health['time'] ) ) ) . '</span>';
		}
		echo '</div>';
		if ( null === $health ) {
			return;
		}
		$tasks = array();
		foreach ( Checklist::tasks() as $index => $task ) {
			$tasks[ $task['id'] ] = $index + 1;
		}
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Check', 'shd-performance' ) . '</th><th>' . esc_html__( 'Result', 'shd-performance' ) . '</th><th>' . esc_html__( 'Details', 'shd-performance' ) . '</th></tr></thead><tbody>';
		foreach ( $health['results'] as $result ) {
			echo '<tr><td>' . self::health_badge( $result['status'] ) . ' ' . esc_html( $result['label'] ) . '</td><td>' . esc_html( $result['value'] ) . '</td><td>' . esc_html( $result['detail'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
			if ( in_array( $result['status'], array( 'warn', 'bad' ), true ) && ! empty( $result['task'] ) && isset( $tasks[ $result['task'] ] ) ) {
				echo ' <a href="' . esc_url( self::url( 'checklist', array( 'step' => $tasks[ $result['task'] ] ) ) ) . '">' . esc_html__( 'How to fix', 'shd-performance' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function tools_profile() {
		$report = Profiler::last();
		echo '<h2 id="profile">' . esc_html__( 'Where the build time goes', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Builds one page on this server the way a first-time visitor gets it without the page cache, and measures which plugins, Elementor widgets, shortcodes, database queries and requests to other servers take the time. Visitors are not affected. The page is built twice (the first build warms up the server), so this takes a few seconds.', 'shd-performance' ) . '</p>';
		$this->form_open( 'profile', false, 'shd-actions' );
		echo '<label for="shd-profile-page" class="screen-reader-text">' . esc_html__( 'Page', 'shd-performance' ) . '</label><select id="shd-profile-page" name="page">';
		$options = array( 'home' => __( 'Homepage', 'shd-performance' ) );
		if ( get_option( 'page_for_posts' ) ) {
			$options['posts'] = __( 'Blog (posts page)', 'shd-performance' );
		}
		$options['post']   = __( 'Latest post', 'shd-performance' );
		$options['custom'] = __( 'Other address:', 'shd-performance' );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select> <input type="text" name="path" class="regular-text" placeholder="/blog/" aria-label="' . esc_attr__( 'Other address', 'shd-performance' ) . '"> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Measure', 'shd-performance' ) . '</button></form>';
		if ( null === $report ) {
			return;
		}

		$total = max( 1, (int) $report['total_ms'] );
		echo '<p><strong>' . esc_html( wp_make_link_relative( $report['url'] ) ) . '</strong>: ' . esc_html(
			sprintf(
				/* translators: 1: milliseconds, 2: time ago, 3: PHP version, 4: OPcache on/off, 5: object cache yes/no, 6: memory */
				__( 'built in %1$s ms on the server (measured %2$s). PHP %3$s, OPcache %4$s, persistent object cache %5$s, %6$s MB memory.', 'shd-performance' ),
				number_format_i18n( $total ),
				self::when( (int) $report['time'] ),
				$report['env']['php'],
				$report['env']['opcache'] ? __( 'on', 'shd-performance' ) : __( 'off', 'shd-performance' ),
				$report['env']['object_cache'] ? __( 'yes', 'shd-performance' ) : __( 'no', 'shd-performance' ),
				number_format_i18n( (float) $report['env']['memory_mb'], 1 )
			)
		) . '</p>';

		$hints = Profiler::hints( $report );
		if ( ! empty( $hints ) ) {
			echo '<div class="shd-card"><h3>' . esc_html__( 'What to do', 'shd-performance' ) . '</h3><ol class="shd-steps">';
			foreach ( $hints as $hint ) {
				echo '<li>' . esc_html( $hint ) . '</li>';
			}
			echo '</ol></div>';
		}

		// Time by plugin.
		echo '<h3>' . esc_html__( 'Time by plugin', 'shd-performance' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Time spent in each plugin’s own code (its hooks, widgets and shortcodes; nested calls are counted where they belong). The rest is WordPress itself, the theme’s template files and the main database query.', 'shd-performance' ) . '</p>';
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Plugin or theme', 'shd-performance' ) . '</th><th>' . esc_html__( 'Time', 'shd-performance' ) . '</th><th>' . esc_html__( 'Share', 'shd-performance' ) . '</th></tr></thead><tbody>';
		$attributed = 0;
		foreach ( (array) $report['components'] as $component => $ms ) {
			$attributed += (int) $ms;
			if ( $ms < 1 ) {
				continue;
			}
			printf( '<tr><td>%1$s</td><td>%2$s ms</td><td>%3$d%%</td></tr>', esc_html( Profiler::label( $component ) ), esc_html( number_format_i18n( $ms ) ), (int) round( 100 * $ms / $total ) );
		}
		printf( '<tr><td><em>%1$s</em></td><td>%2$s ms</td><td>%3$d%%</td></tr>', esc_html__( 'Not in a plugin hook (WordPress, templates, main query)', 'shd-performance' ), esc_html( number_format_i18n( max( 0, $total - $attributed ) ) ), (int) round( 100 * max( 0, $total - $attributed ) / $total ) );
		echo '</tbody></table>';

		// [elementor-element] is Elementor's Element Cache placeholder, not a shortcode on the pages.
		$placeholder = null;
		$shortcodes  = array();
		foreach ( (array) $report['shortcodes'] as $shortcode ) {
			if ( 'elementor-element' === $shortcode['tag'] ) {
				$placeholder = $shortcode;
			} else {
				$shortcodes[] = $shortcode;
			}
		}

		if ( ! empty( $report['elements'] ) ) {
			echo '<h3>' . esc_html__( 'Elementor widgets', 'shd-performance' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Widgets that were built for this page (widgets served from Elementor’s Element Cache do not appear). “With content” includes the widgets inside, for example the post cards of a post list.', 'shd-performance' ) . '</p>';
			if ( null !== $placeholder ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: 1: number of widgets, 2: milliseconds */
						__( 'Elementor’s Element Cache stores each page without the widgets whose content can change between visits: post lists, menus, post info, excerpts, forms, anything with a dynamic tag, and most add-on widgets. In their place it keeps an [elementor-element] placeholder and builds the widget again each time the page is built: %1$d placeholders on this page, %2$s ms with everything inside them. That time is already counted in this table; it is the part of the page that Elementor cannot store.', 'shd-performance' ),
						(int) $placeholder['count'],
						number_format_i18n( (int) ( isset( $placeholder['total_ms'] ) ? $placeholder['total_ms'] : $placeholder['ms'] ) )
					)
				) . '</p>';
			}
			echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Widget', 'shd-performance' ) . '</th><th>' . esc_html__( 'Plugin', 'shd-performance' ) . '</th><th>' . esc_html__( 'Count', 'shd-performance' ) . '</th><th>' . esc_html__( 'Own time', 'shd-performance' ) . '</th><th>' . esc_html__( 'With content', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $report['elements'] as $element ) {
				printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$d</td><td>%4$s ms</td><td>%5$s ms</td></tr>', esc_html( $element['name'] ), esc_html( Profiler::label( $element['component'] ) ), (int) $element['count'], esc_html( number_format_i18n( $element['ms'] ) ), esc_html( number_format_i18n( $element['total_ms'] ) ) );
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $shortcodes ) ) {
			echo '<h3>' . esc_html__( 'Shortcodes', 'shd-performance' ) . '</h3><table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Shortcode', 'shd-performance' ) . '</th><th>' . esc_html__( 'Plugin', 'shd-performance' ) . '</th><th>' . esc_html__( 'Count', 'shd-performance' ) . '</th><th>' . esc_html__( 'Own time', 'shd-performance' ) . '</th><th>' . esc_html__( 'With content', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( $shortcodes as $shortcode ) {
				printf( '<tr><td><code>[%1$s]</code></td><td>%2$s</td><td>%3$d</td><td>%4$s ms</td><td>%5$s ms</td></tr>', esc_html( $shortcode['tag'] ), esc_html( Profiler::label( $shortcode['component'] ) ), (int) $shortcode['count'], esc_html( number_format_i18n( $shortcode['ms'] ) ), esc_html( number_format_i18n( isset( $shortcode['total_ms'] ) ? $shortcode['total_ms'] : $shortcode['ms'] ) ) );
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Requests to other servers', 'shd-performance' ) . '</h3>';
		if ( empty( $report['http'] ) ) {
			echo '<p>' . esc_html__( 'None: good.', 'shd-performance' ) . '</p>';
		} else {
			echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Address', 'shd-performance' ) . '</th><th>' . esc_html__( 'Requested by', 'shd-performance' ) . '</th><th>' . esc_html__( 'Time', 'shd-performance' ) . '</th><th>' . esc_html__( 'Result', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $report['http'] as $call ) {
				printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s ms</td><td>%4$s</td></tr>', esc_html( $call['url'] ), esc_html( Profiler::label( $call['component'] ) ), esc_html( number_format_i18n( $call['ms'] ) ), esc_html( $call['result'] ) );
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Database', 'shd-performance' ) . '</h3><p>' . esc_html(
			sprintf(
				/* translators: 1: queries, 2: milliseconds */
				__( '%1$s queries, %2$s ms (queries before SHD Performance loaded are counted but not timed).', 'shd-performance' ),
				number_format_i18n( (int) $report['db']['count'] ),
				number_format_i18n( (int) $report['db']['ms'] )
			)
		) . '</p>';
		if ( ! empty( $report['db']['slowest'] ) ) {
			echo '<details><summary>' . esc_html__( 'Slowest queries', 'shd-performance' ) . '</summary><table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Query', 'shd-performance' ) . '</th><th>' . esc_html__( 'Time', 'shd-performance' ) . '</th><th>' . esc_html__( 'Called from', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $report['db']['slowest'] as $query ) {
				printf( '<tr><td><code>%1$s</code></td><td>%2$s ms</td><td>%3$s</td></tr>', esc_html( $query['sql'] ), esc_html( number_format_i18n( (float) $query['ms'], 1 ) ), esc_html( $query['caller'] ) );
			}
			echo '</tbody></table></details>';
		}

		echo '<details><summary>' . esc_html__( 'Timeline and slowest hooks', 'shd-performance' ) . '</summary>';
		echo '<table class="widefat striped shd-table"><tbody>';
		foreach ( Profiler::phases( $report ) as $phase ) {
			printf( '<tr><td>%1$s</td><td>%2$s ms</td></tr>', esc_html( $phase['label'] ), esc_html( number_format_i18n( $phase['ms'] ) ) );
		}
		echo '</tbody></table><table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Hook', 'shd-performance' ) . '</th><th>' . esc_html__( 'Time in its callbacks', 'shd-performance' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $report['hooks'] as $hook => $ms ) {
			printf( '<tr><td><code>%1$s</code></td><td>%2$s ms</td></tr>', esc_html( $hook ), esc_html( number_format_i18n( $ms ) ) );
		}
		echo '</tbody></table></details>';
	}

	private function tools_scan() {
		$scan = Site_Scanner::last();
		echo '<h2 id="scan">' . esc_html__( 'Page scan', 'shd-performance' ) . '</h2>';
		echo '<p>' . esc_html__( 'Reads every published Elementor page and template and the media library: add-on widgets in use, entrance animations, video widgets and the largest image files.', 'shd-performance' ) . '</p>';
		echo '<div class="shd-actions">';
		$this->button( 'scan', null === $scan ? __( 'Scan pages', 'shd-performance' ) : __( 'Scan again', 'shd-performance' ), array(), 'button button-primary' );
		if ( null !== $scan ) {
			echo ' <span class="description">' . esc_html( sprintf( /* translators: 1: documents, 2: time */ __( '%1$d documents, scanned %2$s.', 'shd-performance' ), $scan['documents'], self::when( $scan['time'] ) ) ) . '</span>';
		}
		echo '</div>';
		if ( null === $scan ) {
			return;
		}

		$this->scan_widgets( __( 'Qi Addons widgets', 'shd-performance' ), $scan['qi'], __( 'No Qi Addons widget is used: the plugin can be deactivated.', 'shd-performance' ) );
		$this->scan_widgets( __( 'LA-Studio Kit widgets', 'shd-performance' ), $scan['lastudio'], __( 'No LA-Studio Kit widget is used.', 'shd-performance' ) );

		echo '<h3>' . esc_html__( 'Entrance animations', 'shd-performance' ) . '</h3>';
		if ( empty( $scan['animated'] ) ) {
			echo '<p>' . esc_html__( 'None.', 'shd-performance' ) . '</p>';
		} else {
			/* translators: 1: animations, 2: documents */
			echo '<p>' . esc_html( sprintf( __( '%1$d entrance animations in %2$d documents. The “Show content immediately” optimization neutralizes them on the site; removing them in Elementor (Advanced → Motion Effects → Entrance Animation: None) makes that permanent and lightens the editor data.', 'shd-performance' ), $scan['animations'], count( $scan['animated'] ) ) ) . '</p>';
			echo '<details><summary>' . esc_html__( 'Show documents', 'shd-performance' ) . '</summary><ul class="shd-list">';
			foreach ( $scan['animated'] as $doc ) {
				echo '<li>' . $this->doc_link( $doc ) . ' – ' . esc_html( (string) $doc['count'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link escapes.
			}
			echo '</ul></details>';
		}

		echo '<h3>' . esc_html__( 'Video widgets', 'shd-performance' ) . '</h3>';
		if ( empty( $scan['videos'] ) ) {
			echo '<p>' . esc_html__( 'None.', 'shd-performance' ) . '</p>';
		} else {
			echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'Document', 'shd-performance' ) . '</th><th>' . esc_html__( 'Source', 'shd-performance' ) . '</th><th>' . esc_html__( 'Handled by the plugin', 'shd-performance' ) . '</th></tr></thead><tbody>';
			foreach ( $scan['videos'] as $video ) {
				if ( 'youtube' === $video['source'] ) {
					$handled = $video['overlay'] ? __( 'No: uses Elementor’s own image overlay, which already loads the player on click.', 'shd-performance' ) : __( 'Yes: click-to-play preview.', 'shd-performance' );
				} elseif ( ! $video['autoplay'] ) {
					$handled = __( 'Not needed: self-hosted video loads on play.', 'shd-performance' );
				} elseif ( 'hosted' !== $video['source'] ) {
					$handled = __( 'Not changed: player from another site.', 'shd-performance' );
				} elseif ( ! isset( $video['section'] ) ) {
					$handled = __( 'Run “Scan pages” again for details.', 'shd-performance' );
				} else {
					$state = Settings::state( 'lazy_videos' );
					if ( empty( $video['section'] ) || $video['section'] <= (int) Settings::get()['first_screen_sections'] ) {
						$handled = __( 'No: plays automatically in the first screen.', 'shd-performance' );
					} elseif ( 'live' === $state ) {
						$handled = __( 'Yes: loads when it comes into view.', 'shd-performance' );
					} elseif ( 'test' === $state ) {
						$handled = __( 'In the Test view: loads when it comes into view.', 'shd-performance' );
					} else {
						$handled = __( 'No: “Start autoplaying videos further down when they come into view” is off.', 'shd-performance' );
					}
					if ( empty( $video['mute'] ) || empty( $video['inline'] ) ) {
						$handled .= ' ' . __( 'Mute or Play On Mobile is off: see the checklist.', 'shd-performance' );
					}
				}
				echo '<tr><td>' . $this->doc_link( $video ) . '</td><td>' . esc_html( $video['source'] . ( $video['autoplay'] ? ' · autoplay' : '' ) ) . '</td><td>' . esc_html( $handled ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link escapes.
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Largest image files', 'shd-performance' ) . '</h3>';
		if ( empty( $scan['images'] ) ) {
			echo '<p>' . esc_html__( 'No image over 200 KB.', 'shd-performance' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Make them lighter or replace them on the Images tab: every page that uses an image shows the new file.', 'shd-performance' ) . '</p>';
		echo '<table class="widefat striped shd-table"><thead><tr><th>' . esc_html__( 'File', 'shd-performance' ) . '</th><th>' . esc_html__( 'Dimensions', 'shd-performance' ) . '</th><th>' . esc_html__( 'Size', 'shd-performance' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'shd-performance' ) . '</span></th></tr></thead><tbody>';
		foreach ( $scan['images'] as $image ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td><td><a href="%5$s">%6$s</a></td></tr>',
				esc_url( admin_url( 'post.php?post=' . (int) $image['id'] . '&action=edit' ) ),
				esc_html( $image['file'] ),
				esc_html( $image['width'] . ' × ' . $image['height'] ),
				esc_html( size_format( (int) $image['bytes'] ) ),
				esc_url( self::url( 'images', array( 'attachment' => (int) $image['id'] ) ) ),
				esc_html__( 'Make lighter or replace', 'shd-performance' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * @param string $title Heading.
	 * @param array  $rows  Documents with widget and count.
	 * @param string $empty Text when unused.
	 */
	private function scan_widgets( $title, array $rows, $empty ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul class="shd-list">';
		foreach ( $rows as $row ) {
			echo '<li>' . $this->doc_link( $row ) . ' – <code>' . esc_html( $row['widget'] ) . '</code> × ' . esc_html( (string) $row['count'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link escapes.
		}
		echo '</ul>';
	}

	/**
	 * @param array $doc Document (id, title, type).
	 * @return string HTML.
	 */
	private function doc_link( array $doc ) {
		$view = get_permalink( (int) $doc['id'] );
		$edit = admin_url( 'post.php?post=' . (int) $doc['id'] . '&action=elementor' );
		return sprintf(
			'<a href="%1$s">%2$s</a> <span class="description">(%3$s)</span>%4$s',
			esc_url( $edit ),
			esc_html( $doc['title'] ),
			esc_html( $doc['type'] ),
			$view && 'elementor_library' !== $doc['type'] ? ' <a href="' . esc_url( $view ) . '" class="description">' . esc_html__( 'view', 'shd-performance' ) . '</a>' : ''
		);
	}
}
