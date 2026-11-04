<?php
/**
 * YouTube helpers for the click-to-play facade.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Youtube {

	const META_HOOK = 'shd_perf_youtube_meta';

	/** Widths of the local thumbnail copies. */
	const THUMB_WIDTHS = array( 480, 640, 960, 1280 );

	/** Folder in uploads for the thumbnail copies. */
	const THUMB_DIR = 'shd-performance/yt';

	public static function register() {
		add_action( self::META_HOOK, array( __CLASS__, 'fetch_meta' ) );
	}

	/**
	 * @param string $url YouTube URL.
	 * @return string 11-character id or ''.
	 */
	public static function video_id( $url ) {
		if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~i', (string) $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Player parameters equivalent to Elementor's video widget settings.
	 *
	 * @param array  $s  Widget settings.
	 * @param string $id Video id.
	 * @return string Query string without autoplay.
	 */
	public static function params( array $s, $id ) {
		$yes    = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ] ) && 'yes' === $s[ $key ];
		};
		$params = array(
			'controls'       => ( ! isset( $s['controls'] ) || $yes( 'controls' ) ) ? 1 : 0,
			'rel'            => $yes( 'rel' ) ? 1 : 0,
			'playsinline'    => $yes( 'play_on_mobile' ) ? 1 : 0,
			'cc_load_policy' => $yes( 'cc_load_policy' ) ? 1 : 0,
		);
		if ( $yes( 'mute' ) ) {
			$params['mute'] = 1;
		}
		if ( $yes( 'loop' ) ) {
			$params['loop']     = 1;
			$params['playlist'] = $id;
		}
		foreach ( array( 'start', 'end' ) as $key ) {
			if ( isset( $s[ $key ] ) && '' !== $s[ $key ] && (int) $s[ $key ] > 0 ) {
				$params[ $key ] = (int) $s[ $key ];
			}
		}
		return http_build_query( $params, '', '&' );
	}

	/**
	 * Facade for Elementor's video widget, placed inside its .elementor-video container.
	 *
	 * @param string $id       Video id.
	 * @param array  $settings Widget settings.
	 * @param bool   $eager    Whether the video is in the first screen.
	 * @param string $label    Custom button label ('' = automatic).
	 * @param bool   $high     Whether the thumbnail is the page's main image (fetchpriority="high").
	 * @return string
	 */
	public static function facade_html( $id, array $settings, $eager, $label = '', $high = false ) {
		$host = ( isset( $settings['yt_privacy'] ) && 'yes' === $settings['yt_privacy'] ) ? 'www.youtube-nocookie.com' : 'www.youtube.com';
		return self::button_html( $id, $host, self::params( $settings, $id ), '', $eager, $label, $high && $eager );
	}

	/**
	 * Facade for a YouTube <iframe> embed (WordPress embed block or oEmbed).
	 *
	 * @param string $src   Iframe src.
	 * @param string $title Iframe title.
	 * @param bool   $eager Whether the embed is in the first screen.
	 * @param string $label Custom button label ('' = automatic).
	 * @return string '' when the src is not a plain YouTube embed.
	 */
	public static function iframe_facade_html( $src, $title, $eager, $label = '' ) {
		if ( ! preg_match( '~^(?:https?:)?//(?:www\.)?(youtube(?:-nocookie)?\.com)/embed/([A-Za-z0-9_-]{11})(?:\?([^#]*))?~i', html_entity_decode( (string) $src, ENT_QUOTES ), $m ) ) {
			return '';
		}
		$query = array();
		parse_str( isset( $m[3] ) ? $m[3] : '', $query );
		if ( ! empty( $query['autoplay'] ) ) {
			return ''; // Meant to start by itself.
		}
		unset( $query['autoplay'] );
		return self::button_html( $m[2], 'www.' . strtolower( $m[1] ), http_build_query( $query, '', '&' ), (string) $title, $eager, $label );
	}

	/**
	 * Shared facade button: thumbnail, play icon and the data the click handler needs.
	 *
	 * @param string $id    Video id.
	 * @param string $host  www.youtube.com or www.youtube-nocookie.com.
	 * @param string $query Player parameters (without autoplay).
	 * @param string $title Known video title ('' = from the oEmbed cache).
	 * @param bool   $eager Whether the video is in the first screen.
	 * @param string $label Custom button label ('' = automatic).
	 * @param bool   $high  Whether the thumbnail gets fetchpriority="high".
	 * @return string
	 */
	private static function button_html( $id, $host, $query, $title, $eager, $label, $high = false ) {
		$meta  = self::meta( $id );
		$title = '' !== trim( $title ) ? trim( $title ) : $meta['title'];
		$base  = 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/';
		$label = '' !== trim( $label ) ? $label : ( 0 === strpos( get_locale(), 'de' ) ? 'Video abspielen' : 'Play video' );
		if ( '' !== $title ) {
			$label .= ': ' . $title;
		}

		// Copies on this site, in WebP and in several widths: no connection to YouTube's image
		// server, cached for 30 days (YouTube's images only for 2 hours), and phones download
		// a smaller file than the 1280 px JPEG.
		$local = self::thumbnails( $id );
		if ( ! empty( $local ) ) {
			$srcset = array();
			foreach ( $local as $width => $url ) {
				$srcset[] = $url . ' ' . $width . 'w';
			}
			$src    = end( $local );
			$srcset = implode( ', ', $srcset );
		} else {
			$src    = $base . ( $meta['maxres'] ? 'maxresdefault.jpg' : 'hqdefault.jpg' );
			$srcset = $base . 'mqdefault.jpg 320w, ' . $base . 'hqdefault.jpg 480w';
			if ( $meta['maxres'] ) {
				$srcset .= ', ' . $base . 'maxresdefault.jpg 1280w';
			}
		}

		$img = sprintf(
			'<img src="%1$s" srcset="%2$s" sizes="%3$s" width="1280" height="720" alt="" decoding="async"%4$s%5$s>',
			esc_url( $src ),
			esc_attr( $srcset ),
			$eager ? '100vw' : 'auto, 100vw',
			$eager ? '' : ' loading="lazy"',
			$high ? ' fetchpriority="high"' : ''
		);

		$play = '<span class="shd-yt__play" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48" focusable="false">'
			. '<path d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="#f00"/>'
			. '<path d="M45 24 27 14v20" fill="#fff"/></svg></span>';

		return sprintf(
			'<button type="button" class="shd-yt" data-shd-yt="%1$s" data-shd-yt-host="%2$s" data-shd-yt-params="%3$s" data-shd-yt-title="%4$s" aria-label="%5$s">%6$s%7$s</button>',
			esc_attr( $id ),
			esc_attr( $host ),
			esc_attr( $query ),
			esc_attr( '' !== $title ? $title : 'YouTube' ),
			esc_attr( $label ),
			$img,
			$play
		);
	}

	/**
	 * Local WebP copies of the video's thumbnail, made once (16:9, at most 1280 px wide).
	 *
	 * @param string $id Video id.
	 * @return array<int,string> Width => URL, smallest first; empty when not available.
	 */
	public static function thumbnails( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $id ) ) {
			return array();
		}
		$key   = 'shd_perf_ytimg_' . $id;
		$saved = get_transient( $key );
		if ( 'none' === $saved ) {
			return array();
		}
		if ( ! is_array( $saved ) || ! self::thumbnails_exist( $saved ) ) {
			// One request makes them; the others keep YouTube's images meanwhile.
			if ( get_transient( $key . '_busy' ) ) {
				return array();
			}
			set_transient( $key . '_busy', 1, MINUTE_IN_SECONDS );
			$saved = self::make_thumbnails( $id );
			set_transient( $key, empty( $saved ) ? 'none' : $saved, empty( $saved ) ? DAY_IN_SECONDS : MONTH_IN_SECONDS );
			delete_transient( $key . '_busy' );
			if ( empty( $saved ) ) {
				return array();
			}
		}
		$uploads = wp_get_upload_dir();
		$urls    = array();
		ksort( $saved );
		foreach ( $saved as $width => $file ) {
			$urls[ (int) $width ] = trailingslashit( $uploads['baseurl'] ) . self::THUMB_DIR . '/' . rawurlencode( $file );
		}
		return $urls;
	}

	/**
	 * @param array $files Width => file name.
	 * @return bool
	 */
	private static function thumbnails_exist( array $files ) {
		if ( empty( $files ) ) {
			return false;
		}
		$uploads = wp_get_upload_dir();
		foreach ( $files as $file ) {
			if ( ! is_file( trailingslashit( $uploads['basedir'] ) . self::THUMB_DIR . '/' . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Download the largest thumbnail YouTube has and save WebP copies (JPEG where the
	 * server cannot write WebP) in the widths of THUMB_WIDTHS. The 4:3 thumbnails YouTube
	 * offers when there is no HD one have black bars above and below a 16:9 video; only
	 * the 16:9 middle is kept, like the facade shows it.
	 *
	 * @param string $id Video id.
	 * @return array<int,string> Width => file name.
	 */
	private static function make_thumbnails( $id ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::THUMB_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return array();
		}
		$webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

		foreach ( array( 'maxresdefault', 'sddefault', 'hqdefault' ) as $name ) {
			$temp = download_url( 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/' . $name . '.jpg', 5 );
			if ( is_wp_error( $temp ) ) {
				// Only a missing size is worth trying the next one; a server that cannot reach
				// YouTube's image server would just wait again (the page then keeps YouTube's images).
				if ( 'http_404' === $temp->get_error_code() ) {
					continue;
				}
				return array();
			}
			$editor = wp_get_image_editor( $temp );
			if ( is_wp_error( $editor ) ) {
				wp_delete_file( $temp );
				continue;
			}
			$size   = $editor->get_size();
			$width  = (int) $size['width'];
			$height = (int) $size['height'];
			if ( $width < 320 ) {
				wp_delete_file( $temp );
				continue;
			}
			$wide = (int) round( $width * 9 / 16 );
			if ( $height > $wide + 2 ) {
				$editor->crop( 0, (int) floor( ( $height - $wide ) / 2 ), $width, $wide );
			}
			$editor->set_quality( 85 );

			$made = array();
			foreach ( array_reverse( self::THUMB_WIDTHS ) as $target ) {
				if ( $target > $width ) {
					continue;
				}
				if ( $target < $width && is_wp_error( $editor->resize( $target, 0 ) ) ) {
					continue;
				}
				$file  = $id . '-' . $target . ( $webp ? '.webp' : '.jpg' );
				$saved = $editor->save( $dir . '/' . $file, $webp ? 'image/webp' : 'image/jpeg' );
				if ( ! is_wp_error( $saved ) ) {
					$made[ $target ] = $file;
				}
				$width = $target;
			}
			wp_delete_file( $temp );
			ksort( $made );
			return $made;
		}
		return array();
	}

	/**
	 * Cached title and HD-thumbnail availability; fetched in the background on first use.
	 *
	 * @param string $id Video id.
	 * @return array{title:string,maxres:bool}
	 */
	public static function meta( $id ) {
		$meta = get_transient( 'shd_perf_yt_' . $id );
		if ( is_array( $meta ) && isset( $meta['title'], $meta['maxres'] ) ) {
			return $meta;
		}
		if ( ! wp_next_scheduled( self::META_HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time() + 5, self::META_HOOK, array( $id ) );
		}
		return array(
			'title'  => '',
			'maxres' => false,
		);
	}

	/**
	 * Cron: fetch the oEmbed title and check whether maxresdefault.jpg exists.
	 *
	 * @param string $id Video id.
	 */
	public static function fetch_meta( $id ) {
		if ( '' === self::video_id( 'https://youtu.be/' . $id ) ) {
			return;
		}
		$meta     = array(
			'title'  => '',
			'maxres' => false,
		);
		$response = wp_remote_get( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $id ), array( 'timeout' => 8 ) );
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['title'] ) ) {
				$meta['title'] = sanitize_text_field( $body['title'] );
			}
		}
		$head           = wp_remote_head( 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/maxresdefault.jpg', array( 'timeout' => 8 ) );
		$meta['maxres'] = 200 === (int) wp_remote_retrieve_response_code( $head );

		set_transient( 'shd_perf_yt_' . $id, $meta, MONTH_IN_SECONDS );
	}
}
