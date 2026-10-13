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
 * @phpstan-type Size array{attachment: int, width: int, height: int, crop: bool|array{string, string}|null, mime: string, file: string, url: string, source: string, target: string}
 * @phpstan-type Found array{size: Size, exact: bool}
 * @phpstan-type Resolution array{action: 'serve', path: string, mime: string}|array{action: 'redirect', url: string, permanent: bool}
 */
final class Generator {

	/**
	 * Transient prefix that remembers a size which failed to make.
	 */
	private const FAILED = 'yourmark_image_sizes_failed_';

	/**
	 * Seconds before a failed size is tried again.
	 */
	private const RETRY_AFTER = 600;

	/**
	 * Seconds a request waits for a free resize slot before it gets a stand-in.
	 */
	private const WAIT = 10;

	/**
	 * Suffixes core adds to an attached file that its sizes do not carry.
	 */
	private const ATTACHED_SUFFIXES = [ '', '-scaled', '-rotated' ];

	/**
	 * Extensions an attached image can have.
	 */
	private const SOURCE_EXTENSIONS = [ 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'avif', 'gif', 'heic' ];

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
	 * Whether a path is named like a size, which is all a lazy size can be.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return bool
	 */
	public static function looks_like_size( string $relative ): bool {
		return (bool) preg_match( '#-\d+x\d+\.[a-z0-9]+$#i', $relative );
	}

	/**
	 * What a request for a missing file in uploads should get.
	 *
	 * The exact size is made and served. A name from before a format switch or a
	 * change in dimensions is sent on permanently to the size that replaced it.
	 * When a size cannot be made right now, a size that exists stands in, on a
	 * temporary redirect, so the visitor never gets a broken image.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|null Null when the path names no image of this site.
	 * @phpstan-return Resolution|null
	 */
	public static function resolve( string $relative ): ?array {
		$found = self::find( $relative );

		if ( $found === null ) {
			return null;
		}

		$size = $found['size'];

		if ( is_wp_error( self::ensure( $size ) ) ) {
			return [
				'action'    => 'redirect',
				'url'       => self::stand_in( $size ),
				'permanent' => false,
			];
		}

		if ( ! $found['exact'] ) {
			return [
				'action'    => 'redirect',
				'url'       => $size['url'],
				'permanent' => true,
			];
		}

		return [
			'action' => 'serve',
			'path'   => $size['target'],
			'mime'   => $size['mime'],
		];
	}

	/**
	 * Make the size a path names exactly, unless its file already exists.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|WP_Error|null The size, why it could not be made, or null when the path names no size.
	 * @phpstan-return Size|WP_Error|null
	 */
	public static function make( string $relative ) {
		$found = self::find( $relative );

		if ( $found === null || ! $found['exact'] ) {
			return null;
		}

		$made = self::ensure( $found['size'] );

		return is_wp_error( $made ) ? $made : $found['size'];
	}

	/**
	 * The size a path names, or the one that took its place.
	 *
	 * The file name only narrows the search to an attachment; what is made is
	 * decided by its metadata, so a request cannot ask for dimensions nobody
	 * registered.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|null
	 * @phpstan-return Found|null
	 */
	private static function find( string $relative ): ?array {
		if ( ! preg_match( '#^((?:[^/]+/)*)([^/]+)-(\d+)x(\d+)\.[a-z0-9]+$#i', $relative, $match ) ) {
			return null;
		}

		[ , $directory, $stem, $width, $height ] = $match;
		$file                                    = basename( $relative );

		foreach ( self::attachments( $directory, $stem ) as $id ) {
			$meta  = wp_get_attachment_metadata( $id );
			$sizes = is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [];

			// The name exactly, else the same name in another format, else the nearest size.
			foreach ( $sizes as $name => $size ) {
				if ( ( $size['file'] ?? '' ) === $file ) {
					return self::found( $id, (string) $name, $size, true );
				}
			}

			foreach ( $sizes as $name => $size ) {
				if ( pathinfo( (string) ( $size['file'] ?? '' ), PATHINFO_FILENAME ) === pathinfo( $file, PATHINFO_FILENAME ) ) {
					return self::found( $id, (string) $name, $size, false );
				}
			}

			$nearest = self::nearest( $sizes, (int) $width, (int) $height );

			if ( $nearest !== null ) {
				return self::found( $id, $nearest, $sizes[ $nearest ], false );
			}
		}

		return null;
	}

	/**
	 * Attachments whose sizes carry a stem: the stem's own file, or its -scaled or -rotated copy.
	 *
	 * Exact names rather than a LIKE, so a request for a made-up name costs one indexed lookup.
	 *
	 * @param string $directory Directory below the uploads base, with a trailing slash or empty.
	 * @param string $stem      Name the sizes carry.
	 * @return int[]
	 */
	private static function attachments( string $directory, string $stem ): array {
		global $wpdb;

		$names = [];

		foreach ( self::ATTACHED_SUFFIXES as $suffix ) {
			foreach ( self::SOURCE_EXTENSIONS as $extension ) {
				$names[] = "{$directory}{$stem}{$suffix}.{$extension}";
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No API finds an attachment by file name; the placeholders are built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders}) LIMIT 5", $names ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * The size closest to requested dimensions: same shape first, then the smallest that is wide enough.
	 *
	 * @param array<string, array<string, mixed>> $sizes  Metadata sizes.
	 * @param int                                 $width  Requested width.
	 * @param int                                 $height Requested height.
	 * @return string|null Size name.
	 */
	private static function nearest( array $sizes, int $width, int $height ): ?string {
		$candidates = array_filter( $sizes, fn( array $size ): bool => ! empty( $size['width'] ) && ! empty( $size['height'] ) );

		if ( ! $candidates ) {
			return null;
		}

		$shape = $height > 0 ? $width / $height : 0;
		$alike = array_filter( $candidates, fn( array $size ): bool => abs( $size['width'] / $size['height'] - $shape ) < 0.02 );
		$pool  = $alike ? $alike : $candidates;

		uasort( $pool, fn( array $a, array $b ): int => $a['width'] <=> $b['width'] );

		foreach ( $pool as $name => $size ) {
			if ( $size['width'] >= $width ) {
				return (string) $name;
			}
		}

		return (string) array_key_last( $pool );
	}

	/**
	 * A metadata entry as a size to make.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $name          Size name.
	 * @param array<string, mixed> $size          Entry from the attachment's sizes.
	 * @param bool                 $exact         Whether it is the requested name.
	 * @return array
	 * @phpstan-return Found
	 */
	private static function found( int $attachment_id, string $name, array $size, bool $exact ): array {
		$source = (string) get_attached_file( $attachment_id );
		$file   = (string) $size['file'];

		return [
			'size'  => [
				'attachment' => $attachment_id,
				'width'      => (int) $size['width'],
				'height'     => (int) $size['height'],
				'crop'       => self::registered_crop( $attachment_id, $name, (int) $size['width'], (int) $size['height'] ),
				'mime'       => $size['mime-type'] ?? (string) wp_check_filetype( $file )['type'],
				'file'       => $file,
				'url'        => dirname( (string) wp_get_attachment_url( $attachment_id ) ) . "/{$file}",
				'source'     => $source,
				'target'     => dirname( $source ) . "/{$file}",
			],
			'exact' => $exact,
		];
	}

	/**
	 * The registered crop for a size, when the entry still has the registered dimensions.
	 *
	 * A crop position like [ 'left', 'top' ] only shows in the registration;
	 * an entry from before the registration changed falls back to centre.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $name          Size name.
	 * @param int    $width         Entry width.
	 * @param int    $height        Entry height.
	 * @return bool|array{string, string}|null Null when unknown.
	 */
	private static function registered_crop( int $attachment_id, string $name, int $width, int $height ) {
		$spec = wp_get_registered_image_subsizes()[ $name ] ?? null;
		$meta = wp_get_attachment_metadata( $attachment_id, true );

		if ( $spec === null || ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return null;
		}

		$dimensions = image_resize_dimensions( (int) $meta['width'], (int) $meta['height'], $spec['width'], $spec['height'], $spec['crop'] );

		if ( ! $dimensions || $dimensions[4] !== $width || $dimensions[5] !== $height ) {
			return null;
		}

		return is_array( $spec['crop'] ) ? [ (string) $spec['crop'][0], (string) $spec['crop'][1] ] : (bool) $spec['crop'];
	}

	/**
	 * Make a size's file unless it exists, remembering a failure for a while.
	 *
	 * @param array $size Size from find().
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function ensure( array $size ) {
		if ( file_exists( $size['target'] ) ) {
			return true;
		}

		$key    = self::FAILED . md5( $size['target'] );
		$failed = get_transient( $key );

		if ( is_string( $failed ) ) {
			return new WP_Error( 'yourmark_image_sizes_failed', $failed );
		}

		$made = self::generate( $size );

		// A busy server is no fault of the image: the next request simply tries again.
		if ( is_wp_error( $made ) && $made->get_error_code() !== 'yourmark_image_sizes_busy' ) {
			set_transient( $key, $made->get_error_message(), self::RETRY_AFTER );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failure in production needs a trace; the transient keeps it to once per ten minutes.
			error_log( "YourMark image sizes: could not make {$size['file']}: {$made->get_error_message()}" );
		}

		return $made;
	}

	/**
	 * URL of a file that exists and can stand in for a size: the nearest made size, else the attached file.
	 *
	 * @param array $size Size that could not be made.
	 * @phpstan-param Size $size
	 * @return string
	 */
	private static function stand_in( array $size ): string {
		$meta      = wp_get_attachment_metadata( $size['attachment'] );
		$directory = dirname( $size['source'] );
		$made      = array_filter(
			is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [],
			fn( array $entry ): bool => isset( $entry['file'] ) && file_exists( "{$directory}/{$entry['file']}" )
		);
		$nearest   = self::nearest( $made, $size['width'], $size['height'] );

		return $nearest === null
			? (string) wp_get_attachment_url( $size['attachment'] )
			: dirname( $size['url'] ) . '/' . $made[ $nearest ]['file'];
	}

	/**
	 * Resize the attachment's file to a size and save it under the size's name.
	 *
	 * Resizes from the attached file, which core has already scaled down to its
	 * big-image threshold.
	 *
	 * @param array $size Size from find().
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function generate( array $size ) {
		// A page full of new images asks for several sizes of one file at once; the rest wait for the first.
		$lock_path = self::lock_dir() . md5( $size['target'] ) . '.lock';
		$file_lock = self::lock( $lock_path, true );

		try {
			if ( file_exists( $size['target'] ) ) {
				return true;
			}

			$slot = self::slot();

			if ( $slot === null ) {
				return new WP_Error( 'yourmark_image_sizes_busy', 'Every resize slot is taken.' );
			}

			try {
				return self::write( $size );
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
	 * Resize and write one size, beside the target first and then renamed into place.
	 *
	 * The rename means the web server never serves a half-written file.
	 *
	 * @param array $size Size from find().
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function write( array $size ) {
		$directory = dirname( $size['target'] );

		self::sweep_partials( $directory );

		$editor = wp_get_image_editor( $size['source'] );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$source = $editor->get_size();
		$crop   = $size['crop'] ?? abs( $source['width'] / max( 1, $source['height'] ) - $size['width'] / max( 1, $size['height'] ) ) > 0.01;

		$resized = $editor->resize( $size['width'], $size['height'], $crop );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$partial = "{$directory}/.partial-" . wp_generate_password( 8, false ) . '-' . $size['file'];

		// The metadata names the file and its format; the output-format mapping would rename a stored PNG to .webp.
		$exact = fn(): array => [];
		add_filter( 'image_editor_output_format', $exact, PHP_INT_MAX );

		try {
			$saved = $editor->save( $partial, $size['mime'] );
		} finally {
			remove_filter( 'image_editor_output_format', $exact, PHP_INT_MAX );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- An atomic rename is the point; WP_Filesystem::move() may copy.
		if ( ! rename( $saved['path'], $size['target'] ) ) {
			wp_delete_file( $saved['path'] );

			return new WP_Error( 'yourmark_image_sizes_rename', "Could not move {$saved['file']} into place." );
		}

		Pruner::record( $size['attachment'], $size['file'] );

		return true;
	}

	/**
	 * Delete half-written files left behind by a request that died mid-save.
	 *
	 * @param string $directory Absolute directory.
	 * @return void
	 */
	private static function sweep_partials( string $directory ): void {
		$partials = glob( "{$directory}/.partial-*" );

		foreach ( is_array( $partials ) ? $partials : [] as $path ) {
			if ( filemtime( $path ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Wait for one of a few server-wide resize slots.
	 *
	 * Each resize holds 150 to 350 MB outside PHP's memory limit, so a page of
	 * new images must not start them all at once.
	 *
	 * @return resource|null Null when none came free in time.
	 */
	private static function slot() {
		/**
		 * Filters how many images this server resizes at once.
		 *
		 * @param int $slots Concurrent resizes.
		 */
		$slots    = max( 1, (int) apply_filters( 'yourmark/image-sizes/concurrency', 2 ) );
		$deadline = microtime( true ) + self::WAIT;

		do {
			for ( $i = 0; $i < $slots; $i++ ) {
				$handle = self::lock( self::lock_dir() . "slot-{$i}.lock", false );

				if ( $handle ) {
					return $handle;
				}
			}

			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		return null;
	}

	/**
	 * Directory for lock files, shared by web requests and WP-CLI.
	 *
	 * Not the temp directory: PHP-FPM and the CLI often get different ones, and
	 * would then not see each other's locks.
	 *
	 * @return string With a trailing slash.
	 */
	private static function lock_dir(): string {
		/**
		 * Filters the lock directory. Point every site on a server at one directory to share the resize slots between them.
		 *
		 * @param string $directory Absolute path.
		 */
		$directory = trailingslashit( (string) apply_filters( 'yourmark/image-sizes/lock-dir', WP_CONTENT_DIR . '/cache/yourmark-image-sizes' ) );

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		return $directory;
	}

	/**
	 * Take an exclusive lock on a file.
	 *
	 * @param string $path Lock file, created when missing.
	 * @param bool   $wait Whether to wait for it.
	 * @return resource|null Null when the lock is taken and $wait is false, or the file cannot be opened.
	 */
	private static function lock( string $path, bool $wait ) {
		// Another site user's lock file can only be read, which is enough for flock(). Checked first, so fopen() never warns into an image response.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pairs with the native fopen() below.
		$mode = is_file( $path ) ? ( is_writable( $path ) ? 'c' : 'r' ) : 'c';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- As above.
		if ( ( $mode === 'c' && ! is_writable( dirname( $path ) ) && ! is_file( $path ) ) || ( $mode === 'r' && ! is_readable( $path ) ) ) {
			return null;
		}

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
