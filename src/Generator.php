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
	 * Suffixes core adds to an attached file that its sizes do not carry.
	 */
	private const ATTACHED_SUFFIXES = [ '', '-scaled', '-rotated' ];

	/**
	 * Extensions an attached image can have.
	 */
	public const SOURCE_EXTENSIONS = [ 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'avif', 'gif', 'heic' ];

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
	 * change in dimensions is sent on to the size that replaced it, which its own
	 * request then makes. When a size cannot be made right now, a size that
	 * exists stands in on a temporary redirect, so the visitor never gets a
	 * broken image.
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

		if ( ! $found['exact'] ) {
			return [
				'action'    => 'redirect',
				'url'       => $size['url'],
				'permanent' => true,
			];
		}

		if ( is_wp_error( self::ensure( $size, false ) ) ) {
			return [
				'action'    => 'redirect',
				'url'       => self::stand_in( $size ),
				'permanent' => false,
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
	 * @param bool   $retry    Whether to try again although the size failed in the last ten minutes; for someone asking on purpose.
	 * @return array|WP_Error|null The size, why it could not be made, or null when the path names no size.
	 * @phpstan-return Size|WP_Error|null
	 */
	public static function make( string $relative, bool $retry = false ) {
		$found = self::find( $relative );

		if ( $found === null || ! $found['exact'] ) {
			return null;
		}

		$made = self::ensure( $found['size'], $retry );

		return is_wp_error( $made ) ? $made : $found['size'];
	}

	/**
	 * The size a path names, or the one that took its place.
	 *
	 * The file name only narrows the search to an attachment; what is made is
	 * decided by its metadata, so a request cannot ask for dimensions nobody
	 * registered. Every candidate is checked for the exact name before any is
	 * taken as a stand-in, so attachments that share a stem cannot answer for
	 * each other.
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
		$candidates                              = [];

		foreach ( self::attachments( $directory, $stem ) as $id ) {
			$meta = wp_get_attachment_metadata( $id );

			$candidates[ $id ] = array_filter(
				is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [],
				fn( $size ): bool => is_array( $size ) && ! empty( $size['file'] ) && ! empty( $size['width'] ) && ! empty( $size['height'] )
			);
		}

		foreach ( $candidates as $id => $sizes ) {
			foreach ( $sizes as $name => $size ) {
				if ( $size['file'] === $file ) {
					return self::found( $id, (string) $name, $size, true );
				}
			}
		}

		// The same name in another format: a URL saved before a format switch.
		foreach ( $candidates as $id => $sizes ) {
			foreach ( $sizes as $name => $size ) {
				if ( pathinfo( (string) $size['file'], PATHINFO_FILENAME ) === pathinfo( $file, PATHINFO_FILENAME ) ) {
					return self::found( $id, (string) $name, $size, false );
				}
			}
		}

		foreach ( $candidates as $id => $sizes ) {
			$nearest = self::nearest( $sizes, (int) $width, (int) $height );

			if ( $nearest !== null ) {
				return self::found( $id, $nearest, $sizes[ $nearest ], false );
			}
		}

		return null;
	}

	/**
	 * Attachments whose sizes can carry a stem, oldest first.
	 *
	 * The stem's own file, or its -scaled or -rotated copy, in any image
	 * extension. A stem that ends in a source extension, as lazy sizes in
	 * another format do (photo-jpg), is looked up without it as well. Exact
	 * names rather than a LIKE: the lookup reads only _wp_attached_file rows and
	 * never misses the right attachment behind a row limit.
	 *
	 * @param string $directory Directory below the uploads base, with a trailing slash or empty.
	 * @param string $stem      Name the sizes carry.
	 * @return int[]
	 */
	public static function attachments( string $directory, string $stem ): array {
		global $wpdb;

		$stems = [ $stem ];

		if ( preg_match( '/^(.+)-(?:' . implode( '|', self::SOURCE_EXTENSIONS ) . ')$/i', $stem, $match ) ) {
			$stems[] = $match[1];
		}

		$names = [];

		foreach ( $stems as $candidate ) {
			foreach ( self::ATTACHED_SUFFIXES as $suffix ) {
				foreach ( self::SOURCE_EXTENSIONS as $extension ) {
					$names[] = "{$directory}{$candidate}{$suffix}.{$extension}";
				}
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No API finds an attachment by file name; the placeholders are built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders}) ORDER BY post_id LIMIT 10", $names ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * The size closest to requested dimensions: same shape first, then the smallest that is wide enough.
	 *
	 * @param array<string, array<string, mixed>> $sizes  Metadata sizes with width and height.
	 * @param int                                 $width  Requested width.
	 * @param int                                 $height Requested height.
	 * @return string|null Size name.
	 */
	private static function nearest( array $sizes, int $width, int $height ): ?string {
		$candidates = array_filter( $sizes, fn( $size ): bool => is_array( $size ) && ! empty( $size['width'] ) && ! empty( $size['height'] ) );

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
		$file   = basename( (string) $size['file'] );

		return [
			'size'  => [
				'attachment' => $attachment_id,
				'width'      => (int) $size['width'],
				'height'     => (int) $size['height'],
				'crop'       => self::registered_crop( $attachment_id, $name, (int) $size['width'], (int) $size['height'] ),
				'mime'       => (string) ( $size['mime-type'] ?? wp_check_filetype( $file )['type'] ),
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
	 * @param array $size  Size from find().
	 * @param bool  $retry Whether to ignore a remembered failure.
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function ensure( array $size, bool $retry ) {
		if ( file_exists( $size['target'] ) ) {
			return true;
		}

		$key = self::FAILED . md5( $size['target'] );

		if ( $retry ) {
			delete_transient( $key );
		}

		$failed = get_transient( $key );

		if ( is_string( $failed ) ) {
			return new WP_Error( 'yourmark_image_sizes_failed', $failed );
		}

		$made = self::generate( $size );

		// A busy server is no fault of the image: the next request simply tries again.
		if ( is_wp_error( $made ) && $made->get_error_code() !== 'yourmark_image_sizes_busy' ) {
			set_transient( $key, $made->get_error_message(), self::RETRY_AFTER );
			self::log( "could not make {$size['file']}: {$made->get_error_message()}" );
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
			fn( $entry ): bool => is_array( $entry ) && ! empty( $entry['file'] ) && file_exists( $directory . '/' . basename( (string) $entry['file'] ) )
		);
		$nearest   = self::nearest( $made, $size['width'], $size['height'] );

		return $nearest === null
			? (string) wp_get_attachment_url( $size['attachment'] )
			: dirname( $size['url'] ) . '/' . basename( (string) $made[ $nearest ]['file'] );
	}

	/**
	 * Resize the attachment's file to a size and save it under the size's name.
	 *
	 * One deadline covers both waits: for another request making the same file,
	 * and for a resize slot. Without it, a queue of requests for one file would
	 * each wait in turn and tie up PHP workers.
	 *
	 * @param array $size Size from find().
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function generate( array $size ) {
		/**
		 * Filters how many seconds a request waits, for the file and a resize slot together, before it gets a stand-in.
		 *
		 * @param int $seconds Seconds.
		 */
		$deadline  = microtime( true ) + max( 0, (int) apply_filters( 'yourmark/image-sizes/wait', 10 ) );
		$lock_path = self::lock_dir() . md5( $size['target'] ) . '.lock';
		$file_lock = self::acquire( [ $lock_path ], $deadline );

		if ( $file_lock === 'busy' ) {
			return self::busy();
		}

		try {
			// Another request may have made it while this one waited.
			if ( file_exists( $size['target'] ) ) {
				return true;
			}

			$slot = self::acquire( self::slot_paths(), $deadline );

			if ( $slot === 'busy' ) {
				return self::busy();
			}

			try {
				return self::write( $size );
			} finally {
				self::release( $slot );
			}
		} finally {
			self::release( $file_lock );

			// Only once the file exists: a request still waiting on this lock then finds it and stops.
			if ( file_exists( $size['target'] ) ) {
				wp_delete_file( $lock_path );
			}
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
	 * The error for a request that found every slot taken until its deadline.
	 *
	 * @return WP_Error
	 */
	private static function busy(): WP_Error {
		return new WP_Error( 'yourmark_image_sizes_busy', 'Every resize slot is taken.' );
	}

	/**
	 * Lock files for the resize slots.
	 *
	 * Each resize holds 150 to 350 MB outside PHP's memory limit, so a page of
	 * new images must not start them all at once.
	 *
	 * @return string[]
	 */
	private static function slot_paths(): array {
		/**
		 * Filters how many images this server resizes at once.
		 *
		 * @param int $slots Concurrent resizes.
		 */
		$slots = max( 1, (int) apply_filters( 'yourmark/image-sizes/concurrency', 2 ) );

		return array_map( fn( int $i ): string => self::lock_dir() . "slot-{$i}.lock", range( 0, $slots - 1 ) );
	}

	/**
	 * Take the first of some locks that comes free before a deadline.
	 *
	 * A lock that cannot be opened at all, say in a directory that is not
	 * writable, is not a reason to stop making images: the work goes ahead
	 * unlocked and the problem is logged.
	 *
	 * @param string[] $paths    Lock files, created when missing.
	 * @param float    $deadline Microtime to give up at.
	 * @return resource|'busy'|null A held lock; busy when all stayed taken; null when none could be opened.
	 */
	private static function acquire( array $paths, float $deadline ) {
		$handles = array_filter( array_map( [ self::class, 'open' ], $paths ) );

		if ( ! $handles ) {
			self::log( 'cannot open lock files in ' . self::lock_dir() . '; images are resized without a limit.' );

			return null;
		}

		do {
			foreach ( $handles as $index => $handle ) {
				if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
					unset( $handles[ $index ] );
					array_walk( $handles, [ self::class, 'close' ] );

					return $handle;
				}
			}

			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		array_walk( $handles, [ self::class, 'close' ] );

		return 'busy';
	}

	/**
	 * Open a lock file without a warning when it cannot be.
	 *
	 * A warning would land in the image response under WP_DEBUG_DISPLAY.
	 *
	 * @param string $path Lock file.
	 * @return resource|null
	 */
	private static function open( string $path ) {
		// Another site user's lock file can only be read, which is enough for flock().
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checks before the native fopen() below.
		if ( is_file( $path ) ) {
			$mode = is_writable( $path ) ? 'c' : 'r';
		} else {
			$mode = is_writable( dirname( $path ) ) ? 'c' : '';
		}
		// phpcs:enable

		if ( $mode === '' || ( $mode === 'r' && ! is_readable( $path ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock() needs a native handle.
		$handle = fopen( $path, $mode );

		return $handle ? $handle : null;
	}

	/**
	 * Close a lock file that was opened but not taken.
	 *
	 * @param resource $handle Lock handle.
	 * @return void
	 */
	private static function close( $handle ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() in open().
		fclose( $handle );
	}

	/**
	 * Release a lock from acquire().
	 *
	 * @param resource|null $handle Lock handle, or null when work went ahead unlocked.
	 * @return void
	 */
	private static function release( $handle ): void {
		if ( ! $handle ) {
			return;
		}

		flock( $handle, LOCK_UN );
		self::close( $handle );
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
	 * Log a problem, once an hour per message.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	private static function log( string $message ): void {
		$key = 'yourmark_image_sizes_logged_' . md5( $message );

		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failure in production needs a trace; the transient above keeps it to once an hour.
		error_log( "YourMark image sizes: {$message}" );
	}
}
