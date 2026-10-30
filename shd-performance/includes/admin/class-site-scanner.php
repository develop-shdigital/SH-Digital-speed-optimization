<?php
/**
 * Scans saved Elementor data and the media library: which add-on widgets are
 * used where, entrance animations, video widgets, and the largest images.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Site_Scanner {

	const OPTION = 'shd_perf_scan';

	/**
	 * @return array Stored scan.
	 */
	public static function run() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value, p.post_title, p.post_type
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data' AND p.post_status IN ('publish','private','future') AND p.post_type <> 'revision'",
			ARRAY_A
		);

		$scan = array(
			'time'       => time(),
			'documents'  => 0,
			'qi'         => array(),
			'lastudio'   => array(),
			'animations' => 0,
			'animated'   => array(),
			'videos'     => array(),
			'toc'        => 0,
			'forms'      => 0,
			'images'     => self::large_images(),
		);

		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			++$scan['documents'];

			$widgets    = array();
			$animations = 0;
			$videos     = array();
			// One walk per top-level section, so videos know which section of the page they are in.
			foreach ( array_values( $data ) as $index => $section ) {
				self::walk( array( $section ), $widgets, $animations, $videos, $index + 1 );
			}

			$doc = array(
				'id'    => (int) $row['post_id'],
				'title' => '' !== $row['post_title'] ? $row['post_title'] : '#' . $row['post_id'],
				'type'  => $row['post_type'],
			);

			foreach ( $widgets as $widget => $count ) {
				if ( 0 === strpos( $widget, 'qi' ) ) {
					$scan['qi'][] = $doc + array( 'widget' => $widget, 'count' => $count );
				} elseif ( 0 === strpos( $widget, 'lakit' ) || 0 === strpos( $widget, 'lastudio' ) ) {
					$scan['lastudio'][] = $doc + array( 'widget' => $widget, 'count' => $count );
				}
			}
			$scan['animations'] += $animations;
			if ( $animations > 0 ) {
				$scan['animated'][] = $doc + array( 'count' => $animations );
			}
			foreach ( $videos as $video ) {
				$scan['videos'][] = $doc + $video;
			}
			$scan['toc']   += isset( $widgets['table-of-contents'] ) ? $widgets['table-of-contents'] : 0;
			$scan['forms'] += isset( $widgets['form'] ) ? $widgets['form'] : 0;
		}

		usort(
			$scan['animated'],
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		update_option( self::OPTION, $scan, false );
		return $scan;
	}

	/**
	 * @return array|null
	 */
	public static function last() {
		$scan = get_option( self::OPTION );
		return is_array( $scan ) && isset( $scan['time'] ) ? $scan : null;
	}

	/**
	 * @param array $elements   Elementor elements.
	 * @param array $widgets    Widget counts (by reference).
	 * @param int   $animations Entrance animations (by reference).
	 * @param array $videos     Video widgets (by reference).
	 * @param int   $section    Top-level section (1-based).
	 */
	private static function walk( array $elements, array &$widgets, &$animations, array &$videos, $section = 0 ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : array();
			foreach ( array( '_animation', 'animation' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) && 'none' !== $settings[ $key ] ) {
					++$animations;
				}
			}
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] && ! empty( $element['widgetType'] ) ) {
				$type             = strtolower( (string) $element['widgetType'] );
				$widgets[ $type ] = isset( $widgets[ $type ] ) ? $widgets[ $type ] + 1 : 1;
				if ( 'video' === $type ) {
					$source = isset( $settings['video_type'] ) ? (string) $settings['video_type'] : 'youtube';
					$url    = isset( $settings['youtube_url'] ) ? (string) $settings['youtube_url'] : '';
					if ( 'hosted' === $source ) {
						$external = isset( $settings['insert_url'] ) && 'yes' === $settings['insert_url'];
						$url      = $external ? ( isset( $settings['external_url']['url'] ) ? (string) $settings['external_url']['url'] : '' ) : ( isset( $settings['hosted_url']['url'] ) ? (string) $settings['hosted_url']['url'] : '' );
					}
					$path     = 'hosted' === $source && '' !== $url ? Fonts::local_path( $url ) : null;
					$videos[] = array(
						'source'   => $source,
						'autoplay' => ! empty( $settings['autoplay'] ),
						'mute'     => isset( $settings['mute'] ) && 'yes' === $settings['mute'],
						'inline'   => isset( $settings['play_on_mobile'] ) && 'yes' === $settings['play_on_mobile'],
						'overlay'  => isset( $settings['show_image_overlay'] ) && 'yes' === $settings['show_image_overlay'] && ! empty( $settings['image_overlay']['url'] ),
						'url'      => $url,
						'bytes'    => null !== $path ? (int) @filesize( $path ) : 0, // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						'section'  => (int) $section,
					);
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $widgets, $animations, $videos, $section );
			}
		}
	}

	/**
	 * Largest image files in the media library.
	 *
	 * @param int $limit Rows.
	 * @param int $min   Smallest file size listed, in bytes.
	 * @return array
	 */
	public static function large_images( $limit = 25, $min = 204800 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'",
			ARRAY_A
		);
		$images = array();
		foreach ( (array) $rows as $row ) {
			$meta = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$size = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
			if ( 0 === $size ) {
				$file = get_attached_file( (int) $row['ID'] );
				$size = ( $file && is_readable( $file ) ) ? (int) filesize( $file ) : 0;
			}
			if ( $size < $min ) {
				continue;
			}
			$images[] = array(
				'id'     => (int) $row['ID'],
				'file'   => isset( $meta['file'] ) ? basename( (string) $meta['file'] ) : '',
				'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'bytes'  => $size,
			);
		}
		usort(
			$images,
			static function ( $a, $b ) {
				return $b['bytes'] - $a['bytes'];
			}
		);
		return array_slice( $images, 0, $limit );
	}
}
