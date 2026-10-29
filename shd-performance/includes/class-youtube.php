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
	 * @return string
	 */
	public static function facade_html( $id, array $settings, $eager, $label = '' ) {
		$host = ( isset( $settings['yt_privacy'] ) && 'yes' === $settings['yt_privacy'] ) ? 'www.youtube-nocookie.com' : 'www.youtube.com';
		return self::button_html( $id, $host, self::params( $settings, $id ), '', $eager, $label );
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
	 * @return string
	 */
	private static function button_html( $id, $host, $query, $title, $eager, $label ) {
		$meta  = self::meta( $id );
		$title = '' !== trim( $title ) ? trim( $title ) : $meta['title'];
		$base  = 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/';
		$label = '' !== trim( $label ) ? $label : ( 0 === strpos( get_locale(), 'de' ) ? 'Video abspielen' : 'Play video' );
		if ( '' !== $title ) {
			$label .= ': ' . $title;
		}

		$srcset = $base . 'mqdefault.jpg 320w, ' . $base . 'hqdefault.jpg 480w';
		if ( $meta['maxres'] ) {
			$srcset .= ', ' . $base . 'maxresdefault.jpg 1280w';
		}

		$img = sprintf(
			'<img src="%1$s" srcset="%2$s" sizes="%3$s" width="1280" height="720" alt="" decoding="async"%4$s>',
			esc_url( $base . ( $meta['maxres'] ? 'maxresdefault.jpg' : 'hqdefault.jpg' ) ),
			esc_attr( $srcset ),
			$eager ? '100vw' : 'auto, 100vw',
			$eager ? '' : ' loading="lazy"'
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
