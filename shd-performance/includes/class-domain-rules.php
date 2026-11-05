<?php
/**
 * The site's other addresses: robots.txt sitemap lines and one-step redirects.
 *
 * robots.txt listed https://sh-digital.ch/sitemap_index.xml next to the www sitemap.
 * Sitemap lines that point to another address of this site are removed: from the
 * robots.txt file (backed up first, nothing else changes) or, when WordPress builds
 * robots.txt, from its output.
 *
 * Requests for another address of the site (sh-digital.ch without www, the old domains
 * in the settings) that reach this server are sent to the same page on the canonical
 * address by Apache, in one step and before WordPress starts (WordPress needed about a
 * second for this redirect). Certificate checks (/.well-known/) are left alone. A
 * redirect set up at the host (shdigital.ch at Hostpoint) happens before this and has
 * to be changed there.
 *
 * Applied from the admin (at most once an hour, and after the settings change), never
 * on a visitor's request.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Domain_Rules {

	/** .htaccess marker. Not "SHD Performance …": the page cache looks for "# BEGIN SHD Performance". */
	const TAG = 'SHD Redirects';

	/** Option: result of the last run. */
	const STATUS = 'shd_perf_domain_status';

	/** Transient: checked recently. */
	const CHECKED = 'shd_perf_domain_checked';

	const FEATURE = 'domain_hygiene';

	public static function register() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_apply' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'apply_soon' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'apply_soon' ) );
	}

	public static function apply_soon() {
		delete_transient( self::CHECKED );
	}

	public static function maybe_apply() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || false !== get_transient( self::CHECKED ) ) {
			return;
		}
		set_transient( self::CHECKED, 1, HOUR_IN_SECONDS );
		self::apply();
	}

	/**
	 * @return array Status: robots ('cleaned', 'clean', 'virtual', 'unwritable', 'off'),
	 *               redirects ('active', 'off', 'not_apache', 'unwritable', 'reverted', 'none').
	 */
	public static function apply() {
		$live   = 'live' === Settings::state( self::FEATURE );
		$before = self::status();
		$status = array(
			'time'      => time(),
			'robots'    => $live ? self::clean_robots() : 'off',
			'redirects' => self::sync_rules( $live ),
			'hosts'     => self::other_hosts(),
		);
		if ( 'cleaned' === $status['robots'] ) {
			$status['robots_cleaned'] = time();
		} elseif ( isset( $before['robots_cleaned'] ) ) {
			$status['robots_cleaned'] = (int) $before['robots_cleaned'];
		}
		update_option( self::STATUS, $status, false );
		return $status;
	}

	/**
	 * @return array Result of the last run, or an empty array.
	 */
	public static function status() {
		$status = get_option( self::STATUS );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Remove our .htaccess block (plugin deactivated).
	 */
	public static function remove() {
		self::sync_rules( false );
		delete_option( self::STATUS );
		delete_transient( self::CHECKED );
	}

	/* ------------------------------------------------------------------ */
	/* Addresses                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * @return string Host of the site's address (www.sh-digital.ch).
	 */
	public static function canonical_host() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * @return string[] The site's other addresses: the host with or without www and the
	 *                  old domains from the settings (each also with www).
	 */
	public static function other_hosts() {
		$home = self::canonical_host();
		if ( '' === $home || is_multisite() || preg_match( '/^[\d.]+$|^\[|^localhost$/', $home ) ) {
			return array();
		}
		$hosts = array( 0 === strpos( $home, 'www.' ) ? substr( $home, 4 ) : 'www.' . $home );
		foreach ( (array) Settings::get()['redirect_domains'] as $domain ) {
			$domain = strtolower( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://|[/:?\#].*$#i', '', trim( (string) $domain ) ) );
			if ( '' === $domain || ! preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $domain ) ) {
				continue;
			}
			$hosts[] = $domain;
			if ( 0 !== strpos( $domain, 'www.' ) ) {
				$hosts[] = 'www.' . $domain;
			}
		}
		return array_values( array_unique( array_diff( $hosts, array( $home ) ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* robots.txt                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $text robots.txt.
	 * @return string Without sitemap lines that point to another address of this site.
	 */
	public static function without_other_sitemaps( $text ) {
		$others = self::other_hosts();
		if ( empty( $others ) || false === stripos( $text, 'sitemap' ) ) {
			return $text;
		}
		$eol     = false !== strpos( $text, "\r\n" ) ? "\r\n" : "\n";
		$lines   = preg_split( '/\r\n|\n|\r/', $text );
		$count   = count( $lines );
		$out     = array();
		$removed = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( preg_match( '/^\s*sitemap\s*:\s*(\S+)/i', $lines[ $i ], $m ) && in_array( strtolower( (string) wp_parse_url( $m[1], PHP_URL_HOST ) ), $others, true ) ) {
				++$removed;
				// The comment right above it goes too when it introduced only this line ("# Yoast SEO Sitemaps").
				$next = $i + 1 < $count ? $lines[ $i + 1 ] : '';
				if ( ! empty( $out ) && preg_match( '/^\s*#/', (string) end( $out ) ) && ! preg_match( '/^\s*sitemap\s*:/i', $next ) ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $lines[ $i ];
		}
		if ( 0 === $removed ) {
			return $text;
		}
		$clean = (string) preg_replace( '/(?:' . preg_quote( $eol, '/' ) . '){3,}/', $eol . $eol, implode( $eol, $out ) );
		return rtrim( $clean ) . $eol;
	}

	/**
	 * robots.txt built by WordPress (no file on the server).
	 *
	 * @param string $output robots.txt.
	 * @return string
	 */
	public static function filter_robots( $output ) {
		return 'live' === Settings::state( self::FEATURE ) ? self::without_other_sitemaps( (string) $output ) : $output;
	}

	/**
	 * @return string 'cleaned', 'clean', 'virtual' or 'unwritable'.
	 */
	private static function clean_robots() {
		$file = self::home_path() . 'robots.txt';
		if ( ! is_file( $file ) ) {
			return 'virtual';
		}
		$text  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$clean = self::without_other_sitemaps( $text );
		if ( $clean === $text ) {
			return 'clean';
		}
		$dir = wp_upload_dir( null, false )['basedir'] . '/shd-performance/backup';
		if ( ! wp_is_writable( $file ) || ! wp_mkdir_p( $dir ) ) {
			return 'unwritable';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- backup, then in place (keeps owner and permissions).
		if ( false === file_put_contents( $dir . '/robots-' . gmdate( 'Ymd-His' ) . '.txt', $text ) || false === file_put_contents( $file, $clean, LOCK_EX ) ) {
			return 'unwritable';
		}
		// phpcs:enable
		return 'cleaned';
	}

	/* ------------------------------------------------------------------ */
	/* Redirects                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * @return string Apache rules, or '' when the site has no other address.
	 */
	public static function rules() {
		$hosts = self::other_hosts();
		if ( empty( $hosts ) ) {
			return '';
		}
		$home   = self::canonical_host();
		$scheme = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https' : 'http';
		$alts   = implode(
			'|',
			array_map(
				function ( $host ) {
					return preg_quote( $host, '/' );
				},
				$hosts
			)
		);
		// The path is taken from the request line as sent (still encoded; %{REQUEST_URI} is
		// decoded, so "%20" would become a space). Apache appends the query string itself:
		// a "?" taken from the request line is refused since Apache 2.4.56 (403).
		// Like WordPress's own redirect: pages only. Form posts (a 301 turns them into GET and
		// loses the data), the REST API, AJAX, cron and certificate checks are left alone.
		// Checked on the request line: .htaccess rules run again after WordPress's rewrite to
		// index.php, when the path no longer shows what was asked for.
		$base = preg_quote( untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ), '/' );
		return '# Other addresses of this site go to the same page on ' . $scheme . '://' . $home . " in one step (SHD Performance).\n"
			. "<IfModule mod_rewrite.c>\n"
			. "RewriteEngine On\n"
			. "RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$\n"
			. 'RewriteCond %{HTTP_HOST} ^(?:' . $alts . ")\\.?(?::[0-9]+)?$ [NC]\n"
			. 'RewriteCond %{THE_REQUEST} !^[A-Z]+\\s+' . $base . "/(?:\\.well-known/|wp-json(?:[/?\\s]|$)|wp-admin/admin-ajax\\.php|wp-cron\\.php|xmlrpc\\.php)\n"
			. "RewriteCond %{THE_REQUEST} ^[A-Z]+\\s+(/[^?\\s]*)\n"
			. 'RewriteRule ^ ' . $scheme . '://' . $home . "%1 [R=301,L,NE]\n"
			. "</IfModule>\n";
	}

	/**
	 * Add, update or remove our block at the top of .htaccess. After writing, the site must
	 * still answer; otherwise the previous file is put back.
	 *
	 * @param bool $enable Rules wanted.
	 * @return string 'active', 'off', 'none', 'not_apache', 'unwritable' or 'reverted'.
	 */
	private static function sync_rules( $enable ) {
		$file    = self::home_path() . '.htaccess';
		$exists  = is_file( $file );
		$content = $exists ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ours    = false !== strpos( $content, '# BEGIN ' . self::TAG );
		$rules   = $enable ? self::rules() : '';
		if ( '' !== $rules && ! $ours && ! Page_Cache::is_apache() ) {
			return 'not_apache';
		}
		$updated = Page_Cache::strip_block( $content, self::TAG );
		if ( '' !== $rules ) {
			$updated = '# BEGIN ' . self::TAG . "\n" . $rules . '# END ' . self::TAG . "\n\n" . ltrim( $updated );
		}
		if ( $updated === $content ) {
			return '' !== $rules ? 'active' : ( $enable ? 'none' : 'off' );
		}
		if ( ! ( $exists ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) ) ) ) {
			return 'unwritable';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- in place, keeps owner and permissions.
		if ( false === file_put_contents( $file, $updated, LOCK_EX ) ) {
			return 'unwritable';
		}
		if ( '' === $rules ) {
			return $enable ? 'none' : 'off';
		}
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			file_put_contents( $file, $content, LOCK_EX );
			return 'reverted';
		}
		// phpcs:enable
		return 'active';
	}

	/**
	 * @return string Folder of the site's address (where robots.txt and .htaccess are), with a slash.
	 */
	private static function home_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() );
	}
}
