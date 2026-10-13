<?php
/**
 * Finds and deletes size files that can go.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Deletes only files this plugin or core made for an attachment.
 *
 * A file merely named like a size, such as one dropped in by FTP or made by
 * another plugin, is never touched: the plugin records every file it writes,
 * and core lists its own in the metadata.
 */
final class Pruner {

	/**
	 * Post meta key, one row per file this plugin made for the attachment.
	 */
	public const MADE = '_yourmark_image_sizes_made';

	/**
	 * Note a file made for an attachment, so it can be cleaned up later.
	 *
	 * One row per file rather than one array, so two sizes made at once cannot overwrite each other's record.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File name, in the attachment's directory.
	 * @return void
	 */
	public static function record( int $attachment_id, string $file ): void {
		if ( ! in_array( $file, self::made( $attachment_id ), true ) ) {
			add_post_meta( $attachment_id, self::MADE, basename( $file ) );
		}
	}

	/**
	 * Files this plugin made for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[] File names.
	 */
	public static function made( int $attachment_id ): array {
		return array_map( fn( $file ): string => basename( (string) $file ), (array) get_post_meta( $attachment_id, self::MADE, false ) );
	}

	/**
	 * Delete, or only measure, the size files of an attachment that can go.
	 *
	 * Deleted stored sizes are also dropped from the stored metadata, so they
	 * become lazy sizes. That keeps the way back open: with this plugin
	 * switched off, `wp media regenerate --only-missing` sees them as missing.
	 * A URL to a deleted name is redirected to the size that replaced it.
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

		if ( ! $dry_run ) {
			// Records of files that are gone anyway would otherwise outlive them.
			$attached = (string) get_attached_file( $attachment_id );
			$missing  = array_filter( self::made( $attachment_id ), fn( string $file ): bool => ! file_exists( dirname( $attached ) . "/{$file}" ) );

			self::forget( $attachment_id, [ ...array_map( 'basename', $files ), ...$missing ] );
		}

		return [
			'files' => $files,
			'bytes' => $bytes,
		];
	}

	/**
	 * Size files of an attachment that can go.
	 *
	 * Without $stored: files this plugin made that the metadata no longer
	 * lists, such as sizes in a format switched away from. With it, also the
	 * sizes core made at upload, for registered sizes only: those come back on
	 * request, while a size registered only for a moment, like the site icon's,
	 * would not.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $stored        Whether to include sizes made at upload.
	 * @return string[] Absolute paths of files that exist.
	 */
	public static function files( int $attachment_id, bool $stored ): array {
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$attached = get_attached_file( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! $attached ) {
			return [];
		}

		$directory  = dirname( $attached );
		$registered = wp_get_registered_image_subsizes();
		$base       = Sizes::base();
		$stored     = $stored && Converter::convertible( $attachment_id );
		$listed     = [];
		$files      = [];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			$file = (string) ( $size['file'] ?? '' );

			if ( $file === '' ) {
				continue;
			}

			$listed[] = $file;

			if ( $stored && empty( $size[ Sizes::LAZY ] ) && isset( $registered[ $name ] ) && ! in_array( $name, $base, true ) ) {
				$files[] = $file;
			}
		}

		foreach ( self::made( $attachment_id ) as $file ) {
			if ( ! in_array( $file, $listed, true ) ) {
				$files[] = $file;
			}
		}

		$paths = array_map( fn( string $file ): string => "{$directory}/{$file}", array_unique( $files ) );

		return array_values( array_filter( $paths, 'file_exists' ) );
	}

	/**
	 * Every size file to delete when an attachment's file is replaced.
	 *
	 * The files this plugin made, and any file under a lazy size's name: one
	 * made before the plugin kept records still shows the old image.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      The new metadata, before lazy sizes are added.
	 * @return string[] Absolute paths of files that exist.
	 */
	public static function stale( int $attachment_id, array $metadata ): array {
		$attached = get_attached_file( $attachment_id );

		if ( ! $attached ) {
			return [];
		}

		$directory = dirname( $attached );
		$files     = self::made( $attachment_id );
		$relative  = dirname( (string) ( $metadata['file'] ?? '' ) );
		$others    = array_diff( Generator::attachments( $relative === '.' ? '' : "{$relative}/", Sizes::stem( $metadata ) ), [ $attachment_id ] );

		// A file under a lazy name that was not recorded, made before the records existed, is only this attachment's when no other shares its name.
		if ( ! $others ) {
			$lazy = Sizes::add_lazy_sizes( $metadata );

			foreach ( is_array( $lazy ) ? (array) ( $lazy['sizes'] ?? [] ) : [] as $size ) {
				if ( ! empty( $size[ Sizes::LAZY ] ) ) {
					$files[] = (string) $size['file'];
				}
			}
		}

		// Core may just have made a base size under a name this plugin used before.
		$files = array_diff( $files, array_column( (array) ( $metadata['sizes'] ?? [] ), 'file' ) );
		$paths = array_map( fn( string $file ): string => "{$directory}/{$file}", array_unique( $files ) );

		return array_values( array_filter( $paths, 'file_exists' ) );
	}

	/**
	 * Drop deleted files from the records and from the stored sizes.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $files         Deleted file names.
	 * @return void
	 */
	public static function forget( int $attachment_id, array $files ): void {
		foreach ( $files as $file ) {
			delete_post_meta( $attachment_id, self::MADE, $file );
		}

		$meta = wp_get_attachment_metadata( $attachment_id, true );

		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			return;
		}

		$sizes = array_filter( (array) $meta['sizes'], fn( $size ): bool => ! in_array( $size['file'] ?? '', $files, true ) );

		if ( count( $sizes ) !== count( $meta['sizes'] ) ) {
			$meta['sizes'] = $sizes;
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
	}
}
