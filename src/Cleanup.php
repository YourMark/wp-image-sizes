<?php
/**
 * Removes size files when their attachment's file is replaced or deleted.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Keeps sizes made from an old file from outliving it.
 *
 * Core overwrites every size when it regenerates them. A size made lazily is
 * not in the stored metadata, so without this it would stay on disk after a
 * replace and keep showing the old image under the same name.
 */
final class Cleanup {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'after_generate' ], 10, 3 );
		add_action( 'delete_attachment', [ self::class, 'before_delete' ] );
	}

	/**
	 * Delete the sizes made from the previous file, once metadata is made for a new or replaced one.
	 *
	 * Runs on upload too, where there is nothing to delete yet.
	 *
	 * @param mixed  $metadata      New attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       'create' for a new or replaced file, 'update' when core fills in missing sizes.
	 * @return mixed
	 */
	public static function after_generate( $metadata, int $attachment_id, string $context ) {
		if ( $context !== 'create' || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$stale = Pruner::stale( $attachment_id, $metadata );

		foreach ( $stale as $path ) {
			wp_delete_file( $path );
		}

		Pruner::forget( $attachment_id, array_map( 'basename', $stale ) );

		return $metadata;
	}

	/**
	 * Delete what core's own deletion misses: files this plugin made that the metadata no longer lists, and converted-from originals.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function before_delete( int $attachment_id ): void {
		$meta     = wp_get_attachment_metadata( $attachment_id, true );
		$attached = get_attached_file( $attachment_id );

		if ( ! $attached ) {
			return;
		}

		$directory = dirname( $attached );
		$files     = [ ...Pruner::made( $attachment_id ), ...( is_array( $meta ) ? (array) ( $meta[ Converter::PREVIOUS ] ?? [] ) : [] ) ];

		foreach ( $files as $file ) {
			wp_delete_file( $directory . '/' . basename( (string) $file ) );
		}
	}
}
