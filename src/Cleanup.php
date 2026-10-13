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
	 * Names the sizes carried before an attachment's file changed, by attachment ID.
	 *
	 * @var array<int, string[]>
	 */
	private static array $previous_stems = [];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'update_attached_file', [ self::class, 'remember_stem' ], 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'after_generate' ], 10, 3 );
		add_action( 'delete_attachment', [ self::class, 'before_delete' ] );
	}

	/**
	 * Note the name the old file's sizes carry, before a replacement changes it.
	 *
	 * Core saves new metadata while it makes sizes, before after_generate runs,
	 * so this is the last moment the old name can be read.
	 *
	 * @param string $file          New attached file.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function remember_stem( string $file, int $attachment_id ): string {
		$meta = wp_get_attachment_metadata( $attachment_id, true );

		if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
			self::$previous_stems[ $attachment_id ][] = Sizes::stem( $meta );
		}

		return $file;
	}

	/**
	 * Delete size files from before, once metadata is made for a new or replaced file.
	 *
	 * Runs on upload too, where it finds nothing: core gives a new upload a name
	 * whose sizes do not exist yet.
	 *
	 * @param mixed  $metadata      New attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       'create' for a new or replaced file, 'update' when core fills in missing sizes.
	 * @return mixed
	 */
	public static function after_generate( $metadata, int $attachment_id, string $context ) {
		$attached = get_attached_file( $attachment_id );

		if ( $context !== 'create' || ! is_array( $metadata ) || empty( $metadata['file'] ) || ! $attached ) {
			return $metadata;
		}

		$stems = array_unique( [ Sizes::stem( $metadata ), ...( self::$previous_stems[ $attachment_id ] ?? [] ) ] );
		$keep  = array_column( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : [], 'file' );

		unset( self::$previous_stems[ $attachment_id ] );

		foreach ( $stems as $stem ) {
			foreach ( Pruner::leftovers( $attachment_id, dirname( $attached ), $stem, $keep ) as $path ) {
				wp_delete_file( $path );
			}
		}

		return $metadata;
	}

	/**
	 * Delete what core's own deletion misses: sizes in a format switched away from, and converted-from originals.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function before_delete( int $attachment_id ): void {
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$attached = get_attached_file( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! $attached ) {
			return;
		}

		$directory = dirname( $attached );

		foreach ( Pruner::leftovers( $attachment_id, $directory, Sizes::stem( $meta ), [] ) as $path ) {
			wp_delete_file( $path );
		}

		foreach ( (array) ( $meta[ Converter::PREVIOUS ] ?? [] ) as $file ) {
			wp_delete_file( $directory . '/' . basename( (string) $file ) );
		}
	}
}
