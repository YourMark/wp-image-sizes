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
 * @phpstan-type StandIn array{url: string, path: string, mime: string}
 * @phpstan-type Resolution array{action: 'serve', path: string, mime: string}|array{action: 'redirect', url: string, permanent: bool}|array{action: 'stand-in', path: string, mime: string, size: Size}
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
	 * Making a size waits for its locks, up to the wait filter.
	 */
	private const WAIT = 'wait';

	/**
	 * Making a size from the queue: the caller holds a slot, and a size another request is making is left to it.
	 */
	private const HELD = 'held';

	/**
	 * Seconds one request spends working through the queue before it lets the next request take over.
	 */
	private const RUN_FOR = 20;

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
	 * With $defer, a missing size is not waited for: the smallest file that
	 * exists and is at least as wide is sent at once, and the caller queues the
	 * size with complete() once the response is out, for the next visitor.
	 *
	 * @param string $relative Path below the uploads base.
	 * @param bool   $defer    Whether the caller can make the size after responding.
	 * @return array|null Null when the path names no image of this site.
	 * @phpstan-return Resolution|null
	 */
	public static function resolve( string $relative, bool $defer = false ): ?array {
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

		if ( $defer && ! file_exists( $size['target'] ) ) {
			$stand_in = self::stand_in( $size );

			return [
				'action' => 'stand-in',
				'path'   => $stand_in['path'],
				'mime'   => $stand_in['mime'],
				'size'   => $size,
			];
		}

		if ( is_wp_error( self::ensure( $size, false ) ) ) {
			return [
				'action'    => 'redirect',
				'url'       => self::stand_in( $size )['url'],
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
	 * Queue a size that was answered with a stand-in, and work through the queue if a slot is free.
	 *
	 * Runs after the response went out. Only as many requests as there are
	 * slots resize; every other one queues its size and frees its PHP worker
	 * at once, so a page of new images is not served one resize at a time.
	 *
	 * @param array $size Size from a stand-in resolution.
	 * @phpstan-param Size $size
	 * @return void
	 */
	public static function complete( array $size ): void {
		self::enqueue( $size['target'] );

		$slot = self::acquire( self::slot_paths(), 0.0 );

		if ( $slot === 'busy' ) {
			return;
		}

		try {
			$until = microtime( true ) + self::RUN_FOR;

			while ( microtime( true ) < $until ) {
				$target = self::dequeue();

				if ( $target === null ) {
					break;
				}

				$found = self::find_target( $target );

				if ( $found !== null ) {
					self::ensure( $found, false, self::HELD );
				}
			}
		} finally {
			self::release( $slot );
		}
	}

	/**
	 * Add a size to the queue, once however often it is asked for.
	 *
	 * @param string $target Absolute path the size is written to.
	 * @return void
	 */
	private static function enqueue( string $target ): void {
		$directory = self::queue_dir();

		if ( $directory !== null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A small marker file; WP_Filesystem may need credentials.
			file_put_contents( $directory . md5( $target ), $target );
		}
	}

	/**
	 * Take the oldest size off the queue, claimed so that no other request takes it too.
	 *
	 * @return string|null Absolute path the size is written to.
	 */
	private static function dequeue(): ?string {
		$directory = self::queue_dir();
		$entries   = $directory === null ? false : glob( $directory . '*' );

		if ( ! $entries ) {
			return null;
		}

		usort( $entries, fn( string $a, string $b ): int => (int) filemtime( $a ) <=> (int) filemtime( $b ) );

		foreach ( $entries as $entry ) {
			$claimed = $entry . '.' . wp_generate_password( 6, false );

			// Another request may claim it first, so a failed rename is expected and not worth a warning.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- The atomic rename is the claim.
			if ( ! @rename( $entry, $claimed ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local marker file.
			$target = (string) file_get_contents( $claimed );
			wp_delete_file( $claimed );

			return $target;
		}

		return null;
	}

	/**
	 * Directory of queued sizes for this site.
	 *
	 * Inside the lock directory, which several sites may share, so per uploads directory.
	 *
	 * @return string|null With a trailing slash; null when it cannot be created.
	 */
	private static function queue_dir(): ?string {
		$directory = self::lock_dir() . 'queue-' . substr( md5( wp_get_upload_dir()['basedir'] ), 0, 12 ) . '/';

		return is_dir( $directory ) || wp_mkdir_p( $directory ) ? $directory : null;
	}

	/**
	 * The size a queued target path names.
	 *
	 * @param string $target Absolute path below the uploads directory.
	 * @return array|null
	 * @phpstan-return Size|null
	 */
	private static function find_target( string $target ): ?array {
		$basedir = wp_get_upload_dir()['basedir'];

		if ( ! str_starts_with( $target, "{$basedir}/" ) ) {
			return null;
		}

		$found = self::find( substr( $target, strlen( $basedir ) + 1 ) );

		return $found !== null && $found['exact'] ? $found['size'] : null;
	}

	/**
	 * Whether a request can be answered before its size is made.
	 *
	 * Needs a way to end the response and keep running, which PHP-FPM and
	 * LiteSpeed have. Elsewhere the visitor waits for the size, as before.
	 *
	 * @return bool
	 */
	public static function defers(): bool {
		$can = PHP_SAPI !== 'cli' && ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) );

		/**
		 * Filters whether a missing size is answered with a larger file at once and made afterwards.
		 *
		 * @param bool $defers True on PHP-FPM and LiteSpeed.
		 */
		return (bool) apply_filters( 'yourmark/image-sizes/defer', $can );
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
	 * The file name only narrows the search to attachments; what is made is
	 * decided by their metadata, so a request cannot ask for dimensions nobody
	 * registered. Every candidate is checked for the exact name before any is
	 * taken as a stand-in, so attachments that share a stem cannot answer for
	 * each other. For a stand-in, an attachment whose source had the requested
	 * extension goes first: photo-300x225.jpg belongs to photo.jpg, not to the
	 * photo.png beside it.
	 *
	 * @param string $relative Path below the uploads base.
	 * @return array|null
	 * @phpstan-return Found|null
	 */
	private static function find( string $relative ): ?array {
		if ( ! preg_match( '#^((?:[^/]+/)*)([^/]+)-(\d+)x(\d+)\.([a-z0-9]+)$#i', $relative, $match ) ) {
			return null;
		}

		[ , $directory, $stem, $width, $height, $extension ] = $match;
		$file       = basename( $relative );
		$source     = self::split_stem( $stem )[1] ?? self::normal_extension( $extension );
		$candidates = [];

		foreach ( self::attachments( $directory, $stem ) as $id => $names ) {
			$meta  = wp_get_attachment_metadata( $id );
			$sizes = array_filter(
				is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [],
				fn( $size ): bool => is_array( $size ) && ! empty( $size['file'] ) && ! empty( $size['width'] ) && ! empty( $size['height'] )
			);

			foreach ( $sizes as $name => $size ) {
				if ( $size['file'] === $file ) {
					return self::found( $id, (string) $name, $size, true );
				}
			}

			$extensions        = array_map( fn( string $name ): string => self::normal_extension( pathinfo( $name, PATHINFO_EXTENSION ) ), $names );
			$candidates[ $id ] = [
				'sizes' => $sizes,
				'match' => in_array( $source, $extensions, true ),
			];
		}

		// Stable: among equals the oldest attachment stays first.
		uasort( $candidates, fn( array $a, array $b ): int => (int) $b['match'] <=> (int) $a['match'] );

		// The same name in another format: a URL saved before a format switch.
		foreach ( $candidates as $id => $candidate ) {
			foreach ( $candidate['sizes'] as $name => $size ) {
				if ( pathinfo( (string) $size['file'], PATHINFO_FILENAME ) === pathinfo( $file, PATHINFO_FILENAME ) ) {
					return self::found( $id, (string) $name, $size, false );
				}
			}
		}

		foreach ( $candidates as $id => $candidate ) {
			$nearest = self::nearest( $candidate['sizes'], (int) $width, (int) $height );

			if ( $nearest !== null ) {
				return self::found( $id, $nearest, $candidate['sizes'][ $nearest ], false );
			}
		}

		return null;
	}

	/**
	 * Attachments whose sizes can carry a stem, oldest first, with the file names that matched.
	 *
	 * Matches the attachment's own file, its -scaled or -rotated copy, and any
	 * file it was converted from, in every image extension. A stem with a
	 * source extension (photo--jpg) is looked up by the name before it. Exact
	 * names rather than a LIKE, so no row limit can hide the right attachment
	 * behind lookalikes.
	 *
	 * @param string $directory Directory below the uploads base, with a trailing slash or empty.
	 * @param string $stem      Name the sizes carry.
	 * @return array<int, string[]> Attachment ID => matching file names below the uploads base.
	 */
	public static function attachments( string $directory, string $stem ): array {
		global $wpdb;

		$base  = self::split_stem( $stem )[0] ?? $stem;
		$names = [];

		foreach ( self::ATTACHED_SUFFIXES as $suffix ) {
			foreach ( self::SOURCE_EXTENSIONS as $extension ) {
				$names[] = "{$directory}{$base}{$suffix}.{$extension}";
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No API finds an attachment by file name; the placeholders are built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attached_file', %s) AND meta_value IN ({$placeholders}) ORDER BY post_id", Converter::PREVIOUS, ...$names ) );

		$attachments = [];

		foreach ( $rows as $row ) {
			$attachments[ (int) $row->post_id ][] = (string) $row->meta_value;
		}

		return $attachments;
	}

	/**
	 * A lazy stem split into its name and source extension, as in photo--jpg.
	 *
	 * @param string $stem Name a size carries.
	 * @return array{0: string, 1: string}|array{} Name and normalised extension, or nothing for a plain stem.
	 */
	private static function split_stem( string $stem ): array {
		$pattern = '/^(.+)' . preg_quote( Sizes::SEPARATOR, '/' ) . '(' . implode( '|', self::SOURCE_EXTENSIONS ) . ')$/i';

		return preg_match( $pattern, $stem, $match ) ? [ $match[1], self::normal_extension( $match[2] ) ] : [];
	}

	/**
	 * An extension in lower case, with JPEG's spellings as one.
	 *
	 * @param string $extension Extension.
	 * @return string
	 */
	private static function normal_extension( string $extension ): string {
		$extension = strtolower( $extension );

		return in_array( $extension, [ 'jpeg', 'jpe' ], true ) ? 'jpg' : $extension;
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
	 * @param array  $size  Size from find().
	 * @param bool   $retry Whether to ignore a remembered failure.
	 * @param string $wait  WAIT or HELD.
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function ensure( array $size, bool $retry, string $wait = self::WAIT ) {
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

		$made = self::generate( $size, $wait );

		// A busy server is no fault of the image: the next request simply tries again.
		if ( is_wp_error( $made ) && $made->get_error_code() !== 'yourmark_image_sizes_busy' ) {
			set_transient( $key, $made->get_error_message(), self::RETRY_AFTER );
			self::log( "could not make {$size['file']}: {$made->get_error_message()}" );
		}

		return $made;
	}

	/**
	 * A file that exists and can stand in for a size: the smallest made size at least as wide, else the attached file.
	 *
	 * Never a smaller one, which the browser would stretch into a blur.
	 *
	 * @param array $size Size without a file.
	 * @phpstan-param Size $size
	 * @return array
	 * @phpstan-return StandIn
	 */
	private static function stand_in( array $size ): array {
		$meta      = wp_get_attachment_metadata( $size['attachment'] );
		$directory = dirname( $size['source'] );
		$made      = array_filter(
			is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [],
			fn( $entry ): bool => is_array( $entry ) && ! empty( $entry['file'] ) && ! empty( $entry['width'] ) && (int) $entry['width'] >= $size['width'] && file_exists( $directory . '/' . basename( (string) $entry['file'] ) )
		);
		$nearest   = self::nearest( $made, $size['width'], $size['height'] );

		if ( $nearest === null ) {
			return [
				'url'  => (string) wp_get_attachment_url( $size['attachment'] ),
				'path' => $size['source'],
				'mime' => (string) get_post_mime_type( $size['attachment'] ),
			];
		}

		$file = basename( (string) $made[ $nearest ]['file'] );

		return [
			'url'  => dirname( $size['url'] ) . "/{$file}",
			'path' => "{$directory}/{$file}",
			'mime' => (string) ( $made[ $nearest ]['mime-type'] ?? wp_check_filetype( $file )['type'] ),
		];
	}

	/**
	 * Resize the attachment's file to a size and save it under the size's name.
	 *
	 * One deadline covers both waits: for another request making the same file,
	 * and for a resize slot. Without it, a queue of requests for one file would
	 * each wait in turn and tie up PHP workers.
	 *
	 * HELD is for the queue: the caller holds a slot already, and a size
	 * another request is making is left to it.
	 *
	 * @param array  $size Size from find().
	 * @param string $wait WAIT or HELD.
	 * @phpstan-param Size $size
	 * @return true|WP_Error
	 */
	private static function generate( array $size, string $wait ) {
		/**
		 * Filters how many seconds a request waits, for the file and a resize slot together, before it gets a stand-in.
		 *
		 * @param int $seconds Seconds.
		 */
		$deadline  = microtime( true ) + max( 0, (int) apply_filters( 'yourmark/image-sizes/wait', 10 ) );
		$lock_path = self::lock_dir() . md5( $size['target'] ) . '.lock';
		$file_lock = self::acquire( [ $lock_path ], $wait === self::WAIT ? $deadline : 0.0 );

		if ( $file_lock === 'busy' ) {
			return self::busy();
		}

		try {
			// Another request may have made it while this one waited.
			if ( file_exists( $size['target'] ) ) {
				return true;
			}

			if ( $wait === self::HELD ) {
				return self::write( $size );
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

		// Recorded before it exists: a request that dies in between leaves a record of nothing, which pruning clears, rather than a file nobody knows about.
		Pruner::record( $size['attachment'], $size['file'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- An atomic rename is the point; WP_Filesystem::move() may copy.
		if ( ! rename( $saved['path'], $size['target'] ) ) {
			wp_delete_file( $saved['path'] );

			return new WP_Error( 'yourmark_image_sizes_rename', "Could not move {$saved['file']} into place." );
		}

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

		while ( true ) {
			foreach ( $handles as $index => $handle ) {
				if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
					unset( $handles[ $index ] );
					array_walk( $handles, [ self::class, 'close' ] );

					return $handle;
				}
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			usleep( 100000 );
		}

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
