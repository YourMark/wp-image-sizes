<?php
/**
 * Finds and makes the file of a size.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_Error;

/**
 * Turns a path in the uploads directory into the size it names, and makes its file.
 *
 * @phpstan-type Size array{width: int, height: int, mime: string, file: string, url: string, source: string, target: string}
 */
final class Generator {

	/**
	 * Path below the uploads base for a URL or request path in this site's uploads.
	 *
	 * Understands both the file's own URL and the PHP URL that Urls points a
	 * missing size at.
	 *
	 * @param string $url Absolute URL, or a path as in REQUEST_URI.
	 * @return string|null Null for anything outside the uploads directory.
	 */
	public static function relative_path( string $url ): ?string {
		$base = wp_get_upload_dir()['baseurl'];
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $host && $host !== wp_parse_url( $base, PHP_URL_HOST ) ) {
			return null;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( isset( $query[ Endpoint::PARAM ] ) && is_string( $query[ Endpoint::PARAM ] ) ) {
			$relative = ltrim( $query[ Endpoint::PARAM ], '/' );
		} else {
			$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
			$path      = wp_parse_url( $url, PHP_URL_PATH );

			if ( ! is_string( $path ) || ! str_starts_with( $path, "{$base_path}/" ) ) {
				return null;
			}

			$relative = rawurldecode( substr( $path, strlen( $base_path ) + 1 ) );
		}

		return ( $relative === '' || str_contains( $relative, '..' ) ) ? null : $relative;
	}

	/**
	 * Make the size a path names, unless its file already exists.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|WP_Error|null The size, the image editor's error, or null when the path names no size.
	 * @phpstan-return Size|WP_Error|null
	 */
	public static function make( string $relative ) {
		$size = self::find( $relative );

		if ( $size === null ) {
			return null;
		}

		$made = self::generate( $size );

		return is_wp_error( $made ) ? $made : $size;
	}

	/**
	 * The size a path names, lazy or stored.
	 *
	 * The file name only narrows the search to an attachment. What is made is
	 * decided by that attachment's metadata, so a request cannot ask for
	 * dimensions nobody registered. Stored sizes count too, so a pruned or lost
	 * file comes back under its old name.
	 *
	 * A name that matches a size in another format is answered with that size:
	 * content saved before a switch from WebP to AVIF still asks for .webp.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|null
	 * @phpstan-return Size|null
	 */
	private static function find( string $relative ): ?array {
		if ( ! preg_match( '#^((?:[^/]+/)*)([^/]+)-\d+x\d+\.[a-z0-9]+$#i', $relative, $match ) ) {
			return null;
		}

		[ , $directory, $stem ] = $match;
		$file                   = basename( $relative );

		global $wpdb;

		$prefix = $wpdb->esc_like( "{$directory}{$stem}" );

		// The attached file may be a -scaled or -rotated copy, so match on the stem its sizes carry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API looks up an attachment by file name; only a size's first request gets here.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 50",
				"{$prefix}.%",
				"{$prefix}-%"
			)
		);

		$wanted = pathinfo( $file, PATHINFO_FILENAME );
		$moved  = null;

		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );

			foreach ( $meta['sizes'] ?? [] as $size ) {
				$name = (string) ( $size['file'] ?? '' );

				if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
					continue;
				}

				if ( $name === $file ) {
					return self::size( (int) $id, $size );
				}

				if ( $moved === null && pathinfo( $name, PATHINFO_FILENAME ) === $wanted ) {
					$moved = self::size( (int) $id, $size );
				}
			}
		}

		return $moved;
	}

	/**
	 * A metadata entry as a size to make.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $size          Entry from the attachment's sizes.
	 * @return array
	 * @phpstan-return Size
	 */
	private static function size( int $attachment_id, array $size ): array {
		$source = (string) get_attached_file( $attachment_id );
		$file   = (string) $size['file'];

		return [
			'width'  => (int) $size['width'],
			'height' => (int) $size['height'],
			'mime'   => $size['mime-type'] ?? (string) wp_check_filetype( $file )['type'],
			'file'   => $file,
			'url'    => dirname( (string) wp_get_attachment_url( $attachment_id ) ) . "/{$file}",
			'source' => $source,
			'target' => dirname( $source ) . "/{$file}",
		];
	}

	/**
	 * Resize the attachment's file to a size and save it under the size's name.
	 *
	 * Resizes from the attached file, which core has already scaled down to its
	 * big-image threshold: a third of the memory and half the time of the
	 * camera original, for the same result at every registered size.
	 *
	 * @param array $size Size from find().
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function generate( array $size ) {
		// A page full of new images asks for several sizes of one file at once; the rest wait for the first.
		$lock_path = get_temp_dir() . 'yourmark-image-sizes-' . md5( $size['target'] ) . '.lock';
		$file_lock = self::lock( $lock_path, true );

		try {
			if ( file_exists( $size['target'] ) ) {
				return true;
			}

			$slot = self::slot();

			try {
				$editor = wp_get_image_editor( $size['source'] );

				if ( is_wp_error( $editor ) ) {
					return $editor;
				}

				$source = $editor->get_size();

				// A size in another shape than the upload, like a square thumbnail, is cut to it.
				$crop = abs( $source['width'] / $source['height'] - $size['width'] / $size['height'] ) > 0.01;

				$resized = $editor->resize( $size['width'], $size['height'], $crop );

				if ( is_wp_error( $resized ) ) {
					return $resized;
				}

				// Written beside the target and renamed into place, so the web server never serves a half-written file.
				$partial = dirname( $size['target'] ) . '/.partial-' . wp_generate_password( 8, false ) . '-' . basename( $size['target'] );

				// The metadata names the file and its format; the output-format mapping would rename a stored PNG to .webp.
				$exact = fn(): array => [];
				add_filter( 'image_editor_output_format', $exact, PHP_INT_MAX );
				$saved = $editor->save( $partial, $size['mime'] );
				remove_filter( 'image_editor_output_format', $exact, PHP_INT_MAX );

				if ( is_wp_error( $saved ) ) {
					return $saved;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- An atomic rename is the point; WP_Filesystem::move() may copy.
				if ( ! rename( $saved['path'], $size['target'] ) ) {
					wp_delete_file( $saved['path'] );

					return new WP_Error( 'yourmark_image_sizes_rename', "Could not move {$saved['file']} into place." );
				}

				return true;
			} finally {
				self::release( $slot );
			}
		} finally {
			self::release( $file_lock );

			// Safe once the file is in place: a request that still opens the old lock finds the file and stops.
			wp_delete_file( $lock_path );
		}
	}

	/**
	 * Wait for one of a few server-wide resize slots.
	 *
	 * Each resize holds 150 to 350 MB outside PHP's memory limit, so a page of
	 * new images must not start them all at once. The slots live in the system
	 * temp directory, shared by every site running as the same user.
	 *
	 * @return resource|null Null after 20 seconds without a free slot.
	 */
	private static function slot() {
		/**
		 * Filters how many images this server resizes at once.
		 *
		 * @param int $slots Concurrent resizes.
		 */
		$slots    = max( 1, (int) apply_filters( 'yourmark/image-sizes/concurrency', 2 ) );
		$deadline = microtime( true ) + 20;

		do {
			for ( $i = 0; $i < $slots; $i++ ) {
				$handle = self::lock( sys_get_temp_dir() . "/yourmark-image-sizes-slot-{$i}.lock", false );

				if ( $handle ) {
					return $handle;
				}
			}

			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		// Better a slow image than a missing one.
		return null;
	}

	/**
	 * Take an exclusive lock on a file.
	 *
	 * @param string $path Lock file, created when missing.
	 * @param bool   $wait Whether to wait for it.
	 * @return resource|null Null when the lock is taken and $wait is false, or the file cannot be opened.
	 */
	private static function lock( string $path, bool $wait ) {
		// Another site user's lock file can only be read, which is enough for flock().
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pairs with the native fopen() below.
		$mode = is_file( $path ) && ! is_writable( $path ) ? 'r' : 'c';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock() needs a native handle.
		$handle = fopen( $path, $mode );

		if ( ! $handle ) {
			return null;
		}

		if ( flock( $handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB ) ) {
			return $handle;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() above.
		fclose( $handle );

		return null;
	}

	/**
	 * Release a lock from lock().
	 *
	 * @param resource|null $handle Lock handle.
	 * @return void
	 */
	private static function release( $handle ): void {
		if ( ! $handle ) {
			return;
		}

		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() in lock().
		fclose( $handle );
	}
}
