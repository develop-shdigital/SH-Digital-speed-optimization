<?php
/**
 * Replace the file behind a media library image, everywhere it is used.
 *
 * The attachment keeps its ID, title, alt text and caption. The new file (an upload,
 * or a lighter WebP made from the current file) is stored under a new name in the same
 * folder, WordPress makes its sizes, and every reference to the old files is rewritten
 * to the new ones: post content, Elementor data and page settings, other post meta,
 * term meta and options (serialized values are unpacked, changed and packed again).
 * The old files stay on disk until they are deleted on purpose, so the replacement can
 * be undone and links from elsewhere (other sites, newsletters, search engines) keep
 * working.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Media_Replace {

	const META        = '_shd_perf_replace';
	const PREVIEW_DIR = 'shd-performance/replace-preview';
	const QUALITIES   = array( 80, 85, 90, 95 );
	const QUALITY     = 90;

	/** File types a replacement may have. */
	const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'gif'          => 'image/gif',
	);

	/** Post meta that is WordPress' own bookkeeping of attachments, or is rebuilt anyway. */
	const SKIP_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_elementor_css', '_elementor_element_cache', '_shd_perf_replace', '_edit_lock', '_edit_last' );

	/** Post types whose content is never rewritten. */
	const SKIP_TYPES = array( 'revision', 'attachment', 'oembed_cache', 'customize_changeset' );

	public static function register() {
		add_action( 'delete_attachment', array( __CLASS__, 'forget' ) );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return bool Whether the file of this attachment can be replaced.
	 */
	public static function can_replace( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) || 'image/svg+xml' === $post->post_mime_type ) {
			return false;
		}
		$file = get_attached_file( $post->ID );
		return is_string( $file ) && is_file( $file );
	}

	/**
	 * Whether a lighter WebP can be made from the current file: not for animations,
	 * and not for AVIF files, which are already smaller than WebP.
	 *
	 * @param int $id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function can_make_lighter( $id ) {
		$file = (string) get_attached_file( $id );
		$mime = (string) get_post_mime_type( $id );
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return new \WP_Error( 'shd_no_webp', __( 'The image library on this server cannot write WebP files. Upload a lighter file instead.', 'shd-performance' ) );
		}
		if ( 'image/gif' === $mime || 'image/avif' === $mime ) {
			return new \WP_Error( 'shd_skip', __( 'GIF and AVIF files are not converted (GIFs may be animated; AVIF is already compact). Upload a lighter file instead.', 'shd-performance' ) );
		}
		if ( 'image/webp' === $mime && self::is_animated_webp( $file ) ) {
			return new \WP_Error( 'shd_skip', __( 'This WebP file is animated and is not converted. Upload a lighter file instead.', 'shd-performance' ) );
		}
		return true;
	}

	/**
	 * Every file WordPress keeps for an attachment, relative to the uploads folder:
	 * "full" (the file in use), "original" (the unscaled upload, if any) and "size:<name>".
	 *
	 * @param mixed  $meta Attachment metadata.
	 * @param string $file The file in use, relative to the uploads folder (_wp_attached_file).
	 * @return array<string,string>
	 */
	public static function files( $meta, $file ) {
		$dir   = dirname( $file );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$files = array( 'full' => $file );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files['original'] = $dir . wp_basename( (string) $meta['original_image'] );
		}
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[ 'size:' . $name ] = $dir . wp_basename( (string) $size['file'] );
				}
			}
		}
		return $files;
	}

	/**
	 * Old file => new file: the file in use, the unscaled original and each size by name.
	 * A size the new image does not have (it is smaller) maps to the new main file.
	 *
	 * @param array<string,string> $old Files by role.
	 * @param array<string,string> $new Files by role.
	 * @return array<string,string>
	 */
	public static function map( array $old, array $new ) {
		$map = array();
		foreach ( $old as $role => $path ) {
			$target = isset( $new[ $role ] ) ? $new[ $role ] : $new['full'];
			if ( $target !== $path && ! isset( $map[ $path ] ) ) {
				$map[ $path ] = $target;
			}
		}
		return $map;
	}

	/* ------------------------------------------------------------------ */
	/* Lighter version                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * A WebP copy of the current file at the given quality, kept in a preview folder so it
	 * can be compared before it is used. Made once per file and quality.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $quality 1–100.
	 * @return array{path:string,url:string,bytes:int,width:int,height:int}|\WP_Error
	 */
	public static function preview( $id, $quality ) {
		$ok = self::can_make_lighter( $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$source  = (string) get_attached_file( $id );
		$uploads = wp_get_upload_dir();
		$dir     = $uploads['basedir'] . '/' . self::PREVIEW_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'shd_preview_dir', __( 'The preview folder in wp-content/uploads could not be created.', 'shd-performance' ) );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$key  = (int) $id . '-q' . (int) $quality . '-' . substr( md5( $source . '|' . filemtime( $source ) . '|' . filesize( $source ) ), 0, 10 );
		$path = $dir . '/' . $key . '.webp';
		if ( ! is_file( $path ) ) {
			$made = self::encode_webp( $source, $path, $quality );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
		}
		$size = wp_getimagesize( $path );
		return array(
			'path'   => $path,
			'url'    => $uploads['baseurl'] . '/' . self::PREVIEW_DIR . '/' . $key . '.webp',
			'bytes'  => (int) filesize( $path ),
			'width'  => $size ? (int) $size[0] : 0,
			'height' => $size ? (int) $size[1] : 0,
		);
	}

	/**
	 * Replace the file with the WebP preview at the given quality.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $quality Quality.
	 * @return array|\WP_Error Result of apply().
	 */
	public static function make_lighter( $id, $quality ) {
		if ( ! self::can_replace( $id ) ) {
			return new \WP_Error( 'shd_not_image', __( 'This is not an image in the media library.', 'shd-performance' ) );
		}
		$preview = self::preview( $id, $quality );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$source = (string) get_attached_file( $id );
		if ( $preview['bytes'] > 0.9 * filesize( $source ) ) {
			return new \WP_Error( 'shd_no_gain', __( 'The WebP version would not be noticeably smaller, so the file was kept.', 'shd-performance' ) );
		}
		$dir    = dirname( $source );
		$target = $dir . '/' . wp_unique_filename( $dir, self::stem( $source ) . '.webp' );
		if ( ! copy( $preview['path'], $target ) ) {
			return new \WP_Error( 'shd_copy', __( 'The new file could not be written to the uploads folder.', 'shd-performance' ) );
		}
		return self::apply( $id, $target, 'lighter', (int) $quality );
	}

	/**
	 * @param string $source  Source file.
	 * @param string $target  WebP file to write.
	 * @param int    $quality Quality.
	 * @return array|\WP_Error Saved file data.
	 */
	private static function encode_webp( $source, $target, $quality ) {
		wp_raise_memory_limit( 'image' );
		$temp = '';
		// A lossless WebP is loaded with lossless output settings; go through a lossless PNG
		// so the new file is an ordinary (lossy) WebP.
		if ( 'image/webp' === wp_get_image_mime( $source ) && function_exists( 'wp_get_webp_info' ) ) {
			$info = wp_get_webp_info( $source );
			if ( isset( $info['type'] ) && 'lossless' === $info['type'] ) {
				$editor = wp_get_image_editor( $source );
				if ( is_wp_error( $editor ) ) {
					return $editor;
				}
				$temp  = wp_tempnam( 'shd-replace' ) . '.png';
				$saved = $editor->save( $temp, 'image/png' );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$source = $saved['path'];
			}
		}
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->set_quality( max( 1, min( 100, (int) $quality ) ) );
		$saved = $editor->save( $target, 'image/webp' );
		if ( '' !== $temp ) {
			wp_delete_file( $temp );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return new \WP_Error( 'shd_webp', __( 'The WebP file could not be written.', 'shd-performance' ) );
		}
		return $saved;
	}

	/* ------------------------------------------------------------------ */
	/* Upload                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace the file with an uploaded one, which is stored next to the current file.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $file Entry from $_FILES.
	 * @return array|\WP_Error Result of apply().
	 */
	public static function replace_with_upload( $id, array $file ) {
		if ( ! self::can_replace( $id ) ) {
			return new \WP_Error( 'shd_not_image', __( 'This is not an image in the media library.', 'shd-performance' ) );
		}
		if ( empty( $file['tmp_name'] ) || ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) ) {
			$too_big = isset( $file['error'] ) && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new \WP_Error( 'shd_upload', $too_big ? __( 'The file is larger than this server accepts.', 'shd-performance' ) : __( 'Choose an image file to upload.', 'shd-performance' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$subdir = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$subdir = '.' === $subdir ? '' : '/' . trim( $subdir, '/' );
		$here   = static function ( $dirs ) use ( $subdir ) {
			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;
			return $dirs;
		};
		add_filter( 'upload_dir', $here );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::MIMES,
			)
		);
		remove_filter( 'upload_dir', $here );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return new \WP_Error( 'shd_upload', is_array( $moved ) && isset( $moved['error'] ) ? (string) $moved['error'] : __( 'The upload failed.', 'shd-performance' ) );
		}
		if ( ! wp_getimagesize( $moved['file'] ) || ! wp_image_editor_supports( array( 'mime_type' => $moved['type'] ) ) ) {
			wp_delete_file( $moved['file'] );
			return new \WP_Error( 'shd_upload', __( 'This file is not an image WordPress can make sizes from (JPEG, PNG, WebP, AVIF or GIF).', 'shd-performance' ) );
		}
		return self::apply( $id, $moved['file'], 'upload' );
	}

	/* ------------------------------------------------------------------ */
	/* Replace, undo, clean up                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Point the attachment at the new file, make its sizes and rewrite every reference.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $new_path New file (absolute, in the attachment's folder).
	 * @param string $how      lighter|upload.
	 * @param int    $quality  WebP quality for "lighter".
	 * @return array{places:array,old_bytes:int,new_bytes:int}|\WP_Error
	 */
	private static function apply( $id, $new_path, $how, $quality = 0 ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'image' );

		$uploads   = wp_get_upload_dir();
		$old_rel   = (string) get_post_meta( $id, '_wp_attached_file', true );
		$old_meta  = wp_get_attachment_metadata( $id );
		$old_mime  = (string) get_post_mime_type( $id );
		$old_bytes = (int) @filesize( $uploads['basedir'] . '/' . $old_rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$type      = wp_check_filetype( $new_path, self::MIMES );
		if ( empty( $type['type'] ) ) {
			wp_delete_file( $new_path );
			return new \WP_Error( 'shd_type', __( 'Unsupported file type.', 'shd-performance' ) );
		}

		update_attached_file( $id, $new_path );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $type['type'] ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $id );
		$new_meta = wp_generate_attachment_metadata( $id, $new_path );
		if ( ! is_array( $new_meta ) || empty( $new_meta['file'] ) ) {
			// Put everything back as it was.
			$made = self::files( $new_meta, (string) get_post_meta( $id, '_wp_attached_file', true ) );
			update_attached_file( $id, $uploads['basedir'] . '/' . $old_rel );
			$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $old_mime ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_update_attachment_metadata( $id, $old_meta );
			clean_post_cache( $id );
			foreach ( array_unique( array_merge( array_values( $made ), array( _wp_relative_upload_path( $new_path ) ) ) ) as $rel ) {
				if ( $rel !== $old_rel ) {
					self::delete_file( $rel );
				}
			}
			return new \WP_Error( 'shd_sizes', __( 'WordPress could not make the image sizes from the new file. Nothing was changed.', 'shd-performance' ) );
		}
		wp_update_attachment_metadata( $id, $new_meta );
		$new_rel   = (string) get_post_meta( $id, '_wp_attached_file', true );
		$old_files = self::files( $old_meta, $old_rel );
		$new_files = self::files( $new_meta, $new_rel );
		$places    = self::rewrite( self::map( $old_files, $new_files ), true );

		$previous = get_post_meta( $id, self::META, true );
		update_post_meta(
			$id,
			self::META,
			array(
				'time'      => time(),
				'user'      => get_current_user_id(),
				'how'       => $how,
				'quality'   => (int) $quality,
				'old'       => array(
					'file'  => $old_rel,
					'mime'  => $old_mime,
					'meta'  => $old_meta,
					'bytes' => $old_bytes,
				),
				'new'       => array(
					'file'  => $new_rel,
					'mime'  => $type['type'],
					'bytes' => (int) @filesize( $uploads['basedir'] . '/' . $new_rel ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				),
				'places'    => count( $places ),
				'old_files' => array_values( array_unique( array_diff( array_values( $old_files ), array_values( $new_files ) ) ) ),
				'previous'  => is_array( $previous ) ? $previous : null,
			)
		);
		self::refresh();

		return array(
			'places'    => $places,
			'old_bytes' => $old_bytes,
			'new_bytes' => (int) @filesize( $uploads['basedir'] . '/' . $new_rel ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
	}

	/**
	 * Undo the last replacement: references, file and sizes go back to the old ones,
	 * and the new files are deleted.
	 *
	 * @param int $id Attachment ID.
	 * @return array{places:array}|\WP_Error
	 */
	public static function undo( $id ) {
		global $wpdb;
		$record  = self::record( $id );
		$uploads = wp_get_upload_dir();
		$current = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( null === $record || ! empty( $record['old_deleted'] ) ) {
			return new \WP_Error( 'shd_no_undo', __( 'There is no replacement to undo (the old files may have been deleted).', 'shd-performance' ) );
		}
		if ( $current !== $record['new']['file'] ) {
			return new \WP_Error( 'shd_changed', __( 'The image was changed in the meantime, so the replacement cannot be undone automatically.', 'shd-performance' ) );
		}
		if ( ! is_file( $uploads['basedir'] . '/' . $record['old']['file'] ) ) {
			return new \WP_Error( 'shd_missing', __( 'The old file is no longer in the uploads folder.', 'shd-performance' ) );
		}
		$new_files = self::files( wp_get_attachment_metadata( $id ), $current );
		$old_files = self::files( $record['old']['meta'], $record['old']['file'] );
		$places    = self::rewrite( self::map( $new_files, $old_files ), true );

		update_attached_file( $id, $uploads['basedir'] . '/' . $record['old']['file'] );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $record['old']['mime'] ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_update_attachment_metadata( $id, $record['old']['meta'] );
		clean_post_cache( $id );
		foreach ( array_unique( array_values( $new_files ) ) as $rel ) {
			if ( ! in_array( $rel, $old_files, true ) ) {
				self::delete_file( $rel );
			}
		}
		if ( is_array( $record['previous'] ) ) {
			update_post_meta( $id, self::META, $record['previous'] );
		} else {
			delete_post_meta( $id, self::META );
		}
		self::refresh();
		return array( 'places' => $places );
	}

	/**
	 * Delete the files the image had before (all earlier replacements). Refused while
	 * anything on the site still points to one of them.
	 *
	 * @param int $id Attachment ID.
	 * @return array{deleted:int,bytes:int}|\WP_Error
	 */
	public static function delete_old_files( $id ) {
		$record = self::record( $id );
		if ( null === $record ) {
			return new \WP_Error( 'shd_no_record', __( 'This image has not been replaced.', 'shd-performance' ) );
		}
		$old = self::old_files( $id );
		if ( empty( $old ) ) {
			return array(
				'deleted' => 0,
				'bytes'   => 0,
			);
		}
		$still = self::rewrite( array_fill_keys( $old, '' ), false );
		if ( ! empty( $still ) ) {
			$names = array();
			foreach ( array_slice( $still, 0, 5 ) as $place ) {
				$names[] = $place['label'];
			}
			/* translators: %s: list of places */
			return new \WP_Error( 'shd_in_use', sprintf( __( 'The old files are still used here, so they were kept: %s. Replace or remove the image there first.', 'shd-performance' ), implode( ', ', $names ) ) );
		}
		$uploads = wp_get_upload_dir();
		$deleted = 0;
		$bytes   = 0;
		foreach ( array_diff( $old, self::shared( $id, $old ) ) as $rel ) {
			$path = $uploads['basedir'] . '/' . $rel;
			if ( is_file( $path ) ) {
				$bytes += (int) filesize( $path );
				self::delete_file( $rel );
				$deleted += is_file( $path ) ? 0 : 1;
			}
		}
		$record['old_deleted'] = time();
		$record['previous']    = null;
		update_post_meta( $id, self::META, $record );
		return array(
			'deleted' => $deleted,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Files from earlier versions of the image that are still on disk.
	 *
	 * @param int $id Attachment ID.
	 * @return string[] Paths relative to the uploads folder.
	 */
	public static function old_files( $id ) {
		$record = self::record( $id );
		if ( null === $record || ! empty( $record['old_deleted'] ) ) {
			return array();
		}
		$current = self::files( wp_get_attachment_metadata( $id ), (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$uploads = wp_get_upload_dir();
		$old     = array();
		for ( $r = $record; is_array( $r ); $r = isset( $r['previous'] ) ? $r['previous'] : null ) {
			foreach ( (array) $r['old_files'] as $rel ) {
				if ( is_string( $rel ) && ! in_array( $rel, $current, true ) && is_file( $uploads['basedir'] . '/' . $rel ) ) {
					$old[] = $rel;
				}
			}
			if ( ! empty( $r['old_deleted'] ) ) {
				break;
			}
		}
		return array_values( array_unique( $old ) );
	}

	/**
	 * When the attachment is deleted, its earlier files go with it (unless another
	 * attachment uses the same files).
	 *
	 * @param int $id Attachment ID.
	 */
	public static function forget( $id ) {
		$old = self::old_files( $id );
		foreach ( array_diff( $old, self::shared( $id, $old ) ) as $rel ) {
			self::delete_file( $rel );
		}
	}

	/**
	 * Which of the files belong to another attachment too (the same file can be in the
	 * media library twice).
	 *
	 * @param int      $id    Attachment ID.
	 * @param string[] $files Paths relative to the uploads folder.
	 * @return string[]
	 */
	private static function shared( $id, array $files ) {
		global $wpdb;
		if ( empty( $files ) ) {
			return array();
		}
		$dir  = dirname( $files[0] );
		$dir  = '.' === $dir ? '' : $dir . '/';
		$like = '' === $dir ? '%' : $wpdb->esc_like( $dir ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id <> %d AND meta_value LIKE %s", $id, $like ), ARRAY_A );
		$shared = array();
		foreach ( (array) $rows as $row ) {
			$other  = self::files( wp_get_attachment_metadata( (int) $row['post_id'] ), (string) $row['meta_value'] );
			$record = self::record( (int) $row['post_id'] );
			$used   = array_merge( array_values( $other ), null === $record ? array() : self::old_files( (int) $row['post_id'] ) );
			$shared = array_merge( $shared, array_intersect( $files, $used ) );
		}
		return array_values( array_unique( $shared ) );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return array|null The last replacement.
	 */
	public static function record( $id ) {
		$record = get_post_meta( $id, self::META, true );
		return is_array( $record ) && isset( $record['old']['file'], $record['new']['file'] ) ? $record + array( 'previous' => null ) : null;
	}

	/**
	 * Where the image is used: references to any of its files, and featured images.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,array{type:string,id:int,label:string,edit:string}>
	 */
	public static function usage( $id ) {
		global $wpdb;
		$files  = self::files( wp_get_attachment_metadata( $id ), (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$places = self::rewrite( array_fill_keys( array_values( $files ), '' ), false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$featured = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s AND p.post_type <> 'revision'", (string) $id ) );
		foreach ( (array) $featured as $post_id ) {
			$places[] = self::place( 'featured', (int) $post_id, '' );
		}
		return $places;
	}

	/* ------------------------------------------------------------------ */
	/* References                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Find, and optionally rewrite, every reference to the given files in post content,
	 * post meta (Elementor data included), term meta and options.
	 *
	 * All files of an attachment are in one folder, so a reference is the folder path
	 * below the uploads URL (with "/" or the "\/" of JSON) followed by the file name.
	 * Host and scheme do not matter, so https://, http:// and relative links all match.
	 *
	 * @param array<string,string> $map   Old file => new file, relative to the uploads folder.
	 * @param bool                 $apply Rewrite (true) or only list the places (false).
	 * @return array<int,array{type:string,id:int,label:string,edit:string}>
	 */
	public static function rewrite( array $map, $apply ) {
		global $wpdb;
		if ( empty( $map ) ) {
			return array();
		}
		$names = array();
		$dir   = '';
		foreach ( $map as $old => $new ) {
			$dir  = dirname( $old );
			$from = wp_basename( $old );
			$to   = wp_basename( (string) $new );
			// Also as written in JSON (\u00fc for ü) and in encoded URLs (%C3%BC).
			foreach ( array( $from => $to, substr( (string) wp_json_encode( $from ), 1, -1 ) => substr( (string) wp_json_encode( $to ), 1, -1 ), rawurlencode( $from ) => rawurlencode( $to ) ) as $a => $b ) {
				$names[ $a ] = $b;
			}
		}
		$dir     = '.' === $dir ? '' : $dir;
		$uploads = wp_get_upload_dir();
		$base    = trim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' );
		$parts   = array_filter( array_merge( explode( '/', $base ), '' === $dir ? array() : explode( '/', $dir ) ), 'strlen' );
		$sep     = '\\\\*/';
		$prefix  = implode( $sep, array_map( array( __CLASS__, 'quote' ), $parts ) ) . $sep;
		$regex   = '~(?<![\w.\-])(' . $prefix . ')(' . implode( '|', array_map( array( __CLASS__, 'quote' ), array_keys( $names ) ) ) . ')(?![\w.\-])~';

		// Cheap pre-selection in SQL: rows that contain the shortest file name stems.
		$stems = array();
		foreach ( array_keys( $names ) as $name ) {
			$stems[] = pathinfo( $name, PATHINFO_FILENAME );
		}
		usort(
			$stems,
			static function ( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);
		$keep = array();
		foreach ( $stems as $stem ) {
			foreach ( $keep as $k ) {
				if ( false !== strpos( $stem, $k ) ) {
					continue 2;
				}
			}
			$keep[] = $stem;
		}
		$like = static function ( $column ) use ( $wpdb, $keep ) {
			$or = array();
			foreach ( $keep as $stem ) {
				$or[] = $wpdb->prepare( "{$column} LIKE %s", '%' . $wpdb->esc_like( $stem ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return '(' . implode( ' OR ', $or ) . ')';
		};

		// Returns the changed string and the number of references in it.
		$replace = static function ( $value ) use ( $regex, $names ) {
			$count = 0;
			$out   = preg_replace_callback(
				$regex,
				static function ( $m ) use ( $names ) {
					return $m[1] . $names[ $m[2] ];
				},
				$value,
				-1,
				$count
			);
			return null === $out ? array( $value, 0 ) : array( $out, $count );
		};

		$places = array();
		$skip   = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_TYPES ) ) . "'";

		// Post content and excerpts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type NOT IN ({$skip}) AND ( " . $like( 'post_content' ) . ' OR ' . $like( 'post_excerpt' ) . ' )', ARRAY_A );
		foreach ( (array) $rows as $row ) {
			list( $content, $hits ) = self::rewrite_value( $row['post_content'], $replace );
			list( $excerpt, $more ) = self::rewrite_value( $row['post_excerpt'], $replace );
			if ( $hits + $more > 0 ) {
				if ( $apply && null !== $content && null !== $excerpt ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $content, 'post_excerpt' => $excerpt ), array( 'ID' => $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					clean_post_cache( (int) $row['ID'] );
				}
				$places[] = self::place( 'post', (int) $row['ID'], '', null === $content || null === $excerpt );
			}
		}

		// Post meta (Elementor data and page settings, SEO images, …).
		$keys = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_META ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type NOT IN ({$skip}) AND pm.meta_key NOT IN ({$keys}) AND " . $like( 'pm.meta_value' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			list( $value, $hits ) = self::rewrite_value( $row['meta_value'], $replace );
			if ( $hits > 0 ) {
				if ( $apply && null !== $value ) {
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'meta_id' => $row['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					wp_cache_delete( (int) $row['post_id'], 'post_meta' );
				}
				$places[] = self::place( 'meta', (int) $row['post_id'], (string) $row['meta_key'], null === $value );
			}
		}

		// Term meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE " . $like( 'meta_value' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			list( $value, $hits ) = self::rewrite_value( $row['meta_value'], $replace );
			if ( $hits > 0 ) {
				if ( $apply && null !== $value ) {
					$wpdb->update( $wpdb->termmeta, array( 'meta_value' => $value ), array( 'meta_id' => $row['meta_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					wp_cache_delete( (int) $row['term_id'], 'term_meta' );
				}
				$places[] = self::place( 'term', (int) $row['term_id'], (string) $row['meta_key'], null === $value );
			}
		}

		// Options (theme and plugin settings, widgets), except caches and this plugin's own data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name NOT LIKE 'shd\\_perf\\_%' AND " . $like( 'option_value' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			list( $value, $hits ) = self::rewrite_value( $row['option_value'], $replace );
			if ( $hits > 0 ) {
				if ( $apply && null !== $value ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_id' => $row['option_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					wp_cache_delete( (string) $row['option_name'], 'options' );
					wp_cache_delete( 'alloptions', 'options' );
				}
				$places[] = self::place( 'option', (int) $row['option_id'], (string) $row['option_name'], null === $value );
			}
		}

		return $places;
	}

	/**
	 * Rewrite one stored value. Serialized PHP values are unpacked, changed and packed again
	 * (their string lengths change). Values that cannot be unpacked safely (objects) are
	 * only searched: their references are counted, the value is returned as null.
	 *
	 * @param string|null $value   Stored value.
	 * @param callable    $replace Returns array( changed string, references ).
	 * @return array{0:string|null,1:int} New value (null: cannot be changed safely), references.
	 */
	private static function rewrite_value( $value, $replace ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array( $value, 0 );
		}
		if ( ! is_serialized( $value ) ) {
			return $replace( $value );
		}
		$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ( false === $data && 'b:0;' !== trim( $value ) ) || self::has_object( $data ) ) {
			list( , $hits ) = $replace( $value );
			return array( null, $hits );
		}
		$hits = 0;
		$data = self::rewrite_data( $data, $replace, $hits );
		if ( null === $data ) {
			list( , $hits ) = $replace( $value );
			return array( null, $hits );
		}
		return array( $hits > 0 ? serialize( $data ) : $value, $hits ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * @param mixed    $data    Unserialized value.
	 * @param callable $replace Returns array( changed string, references ).
	 * @param int      $hits    References found (by reference).
	 * @return mixed Null when a nested value cannot be changed safely.
	 */
	private static function rewrite_data( $data, $replace, &$hits ) {
		if ( is_string( $data ) ) {
			list( $out, $n ) = is_serialized( $data ) ? self::rewrite_value( $data, $replace ) : $replace( $data );
			$hits           += $n;
			return null === $out && $n > 0 ? null : ( null === $out ? $data : $out );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $item ) {
				$item = self::rewrite_data( $item, $replace, $hits );
				if ( null === $item && null !== $data[ $key ] ) {
					return null;
				}
				$data[ $key ] = $item;
			}
		}
		return $data;
	}

	/**
	 * @param mixed $data Unserialized value.
	 * @return bool
	 */
	private static function has_object( $data ) {
		if ( is_object( $data ) ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( self::has_object( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string $type post|meta|term|option|featured.
	 * @param int    $id   Post, term or option ID.
	 * @param string $key  Meta key or option name.
	 * @param bool   $kept The value could not be rewritten safely (it holds objects).
	 * @return array{type:string,id:int,label:string,edit:string,kept:bool}
	 */
	private static function place( $type, $id, $key, $kept = false ) {
		$label = '';
		$edit  = '';
		if ( 'term' === $type ) {
			$term  = get_term( $id );
			$label = $term && ! is_wp_error( $term ) ? $term->name : '#' . $id;
			$edit  = (string) get_edit_term_link( $id );
		} elseif ( 'option' === $type ) {
			/* translators: %s: option name */
			$label = sprintf( __( 'Setting “%s”', 'shd-performance' ), $key );
		} else {
			$post  = get_post( $id );
			$title = $post && '' !== $post->post_title ? $post->post_title : '#' . $id;
			$kind  = $post ? get_post_type_object( $post->post_type ) : null;
			$label = $title . ( $kind ? ' (' . $kind->labels->singular_name . ')' : '' );
			if ( 'featured' === $type ) {
				/* translators: %s: page or post */
				$label = sprintf( __( 'Featured image of %s', 'shd-performance' ), $label );
			}
			$edit = $post && get_post_meta( $id, '_elementor_edit_mode', true ) ? admin_url( 'post.php?post=' . $id . '&action=elementor' ) : (string) get_edit_post_link( $id, 'raw' );
		}
		return array(
			'type'  => $type,
			'id'    => $id,
			'label' => $label,
			'edit'  => $edit,
			'kept'  => (bool) $kept,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Elementor's generated CSS (background images) and every page cache.
	 */
	private static function refresh() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		Cache::purge_all();
	}

	/**
	 * @param string $rel File relative to the uploads folder.
	 */
	private static function delete_file( $rel ) {
		$uploads = wp_get_upload_dir();
		$path    = $uploads['basedir'] . '/' . ltrim( $rel, '/' );
		if ( '' !== $rel && is_file( $path ) ) {
			wp_delete_file_from_directory( $path, $uploads['basedir'] );
		}
	}

	/**
	 * File name without extension and without WordPress' "-scaled" and edit suffixes.
	 *
	 * @param string $file File.
	 * @return string
	 */
	private static function stem( $file ) {
		$stem = (string) preg_replace( '/(?:-scaled)?(?:-e\d{10,})?$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
		return '' !== $stem ? $stem : 'image';
	}

	/**
	 * @param string $text Literal text.
	 * @return string Regex-quoted.
	 */
	private static function quote( $text ) {
		return preg_quote( (string) $text, '~' );
	}

	/**
	 * @param string $file WebP file.
	 * @return bool
	 */
	private static function is_animated_webp( $file ) {
		$head = (string) @file_get_contents( $file, false, null, 0, 64 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return strlen( $head ) > 20 && 'VP8X' === substr( $head, 12, 4 ) && ( ord( $head[20] ) & 0x02 );
	}

	/**
	 * Daily: delete preview files older than a day.
	 */
	public static function clean_previews() {
		$uploads = wp_get_upload_dir();
		foreach ( (array) glob( $uploads['basedir'] . '/' . self::PREVIEW_DIR . '/*.webp' ) as $file ) {
			if ( is_string( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
