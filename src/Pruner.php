<?php
/**
 * Finds and deletes size files that can go.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Lists an attachment's size files that nothing needs, or that come back on request.
 */
final class Pruner {

	/**
	 * Delete, or only measure, the size files of an attachment that can go.
	 *
	 * Deleted stored sizes are also dropped from the stored metadata, so they
	 * become lazy sizes under the same name. That keeps the way back open: with
	 * this plugin switched off, `wp media regenerate --only-missing` sees them as
	 * missing and makes them again.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $stored        Whether to include sizes made at upload.
	 * @param bool $dry_run       Whether to only measure.
	 * @return array{files: string[], bytes: int}
	 */
	public static function prune( int $attachment_id, bool $stored, bool $dry_run ): array {
		$files = self::files( $attachment_id, $stored );
		$bytes = 0;

		foreach ( $files as $path ) {
			$bytes += (int) filesize( $path );

			if ( ! $dry_run ) {
				wp_delete_file( $path );
			}
		}

		if ( $stored && ! $dry_run && $files ) {
			self::forget( $attachment_id, array_map( 'basename', $files ) );
		}

		return [
			'files' => $files,
			'bytes' => $bytes,
		];
	}

	/**
	 * Size files of an attachment that can go.
	 *
	 * Orphans are files named like one of its sizes that no attachment refers to
	 * any more: a format switched away from, or a size no longer registered.
	 * Stored sizes are the ones core made at upload; deleting them is safe
	 * because the endpoint makes them again under the same name.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $stored        Whether to include stored sizes.
	 * @return string[] Absolute paths.
	 */
	public static function files( int $attachment_id, bool $stored ): array {
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$attached = get_attached_file( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! $attached ) {
			return [];
		}

		$directory  = dirname( $attached );
		$stem       = Sizes::stem( $meta );
		$base       = Sizes::base();
		$registered = wp_get_registered_image_subsizes();
		$current    = [];

		// Only a registered size comes back lazily. The site icon's sizes, for one, are registered only while it is cropped.
		$stored = $stored && Converter::convertible( $attachment_id );

		foreach ( $meta['sizes'] ?? [] as $name => $size ) {
			$current[ $size['file'] ] = empty( $size[ Sizes::LAZY ] ) && isset( $registered[ $name ] ) && ! in_array( $name, $base, true );
		}

		$files = [];

		foreach ( self::size_files( $directory, $stem ) as $path ) {
			$file = basename( $path );

			if ( isset( $current[ $file ] ) ) {
				if ( $stored && $current[ $file ] ) {
					$files[] = $path;
				}

				continue;
			}

			if ( ! self::used_elsewhere( $directory, $file, $attachment_id ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/**
	 * Size files of an attachment that no other attachment uses, except the ones to keep.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $directory     Absolute directory of its file.
	 * @param string   $stem          Name its sizes carry.
	 * @param string[] $keep          File names to leave alone.
	 * @return string[] Absolute paths.
	 */
	public static function leftovers( int $attachment_id, string $directory, string $stem, array $keep ): array {
		$files = [];

		foreach ( self::size_files( $directory, $stem ) as $path ) {
			$file = basename( $path );

			if ( ! in_array( $file, $keep, true ) && ! self::used_elsewhere( $directory, $file, $attachment_id ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/**
	 * Every file in a directory named like a size of a stem, in any format.
	 *
	 * @param string $directory Absolute directory.
	 * @param string $stem      Name the sizes carry.
	 * @return string[] Absolute paths.
	 */
	private static function size_files( string $directory, string $stem ): array {
		$paths = glob( $directory . '/' . addcslashes( $stem, '*?[]\\' ) . '-*x*.*' );

		// The glob also matches a longer name, such as photo-2-300x200.webp for photo.
		$pattern = '/^' . preg_quote( $stem, '/' ) . '-\d+x\d+\.(?:jpe?g|png|webp|avif)$/i';

		return array_values( array_filter( is_array( $paths ) ? $paths : [], fn( string $path ): bool => (bool) preg_match( $pattern, basename( $path ) ) ) );
	}

	/**
	 * Whether another attachment uses a file as its upload or names it in its metadata.
	 *
	 * An upload can itself be called photo-1440x990.webp, which looks exactly
	 * like a size of photo.webp.
	 *
	 * @param string $directory     Absolute directory.
	 * @param string $file          File name.
	 * @param int    $attachment_id Attachment the file is checked for.
	 * @return bool
	 */
	private static function used_elsewhere( string $directory, string $file, int $attachment_id ): bool {
		global $wpdb;

		$relative = ltrim( substr( $directory, strlen( wp_get_upload_dir()['basedir'] ) ) . "/{$file}", '/' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API searches all attachment metadata for a file name; runs for deletions only.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} WHERE post_id != %d AND ( ( meta_key = '_wp_attached_file' AND meta_value = %s ) OR ( meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ) ) LIMIT 1",
				$attachment_id,
				$relative,
				'%' . $wpdb->esc_like( "\"{$file}\"" ) . '%'
			)
		);
	}

	/**
	 * Drop stored entries whose files were deleted, so they become lazy sizes.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $files         Deleted file names.
	 * @return void
	 */
	private static function forget( int $attachment_id, array $files ): void {
		$meta = wp_get_attachment_metadata( $attachment_id, true );

		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			return;
		}

		$meta['sizes'] = array_filter( $meta['sizes'], fn( array $size ): bool => ! in_array( $size['file'] ?? '', $files, true ) );
		wp_update_attachment_metadata( $attachment_id, $meta );
	}
}
