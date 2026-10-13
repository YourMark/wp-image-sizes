<?php
/**
 * Which sub-sizes are made at upload, and the metadata for the rest.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Makes no sizes at upload and lists every registered size as lazy.
 *
 * Lazy sizes are added when metadata is read, never stored. A size the theme
 * registers later is therefore available for every existing upload at once,
 * and a size it drops disappears without leaving stale entries behind.
 */
final class Sizes {

	/**
	 * Key that marks a metadata entry whose file is made on first request.
	 */
	public const LAZY = 'yourmark_lazy';

	/**
	 * Formats made lazily. GIF stays out: resizing one drops its animation.
	 */
	private const RESIZABLE = [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'keep_base_sizes' ], 10, 2 );
		add_filter( 'wp_get_attachment_metadata', [ self::class, 'add_lazy_sizes' ] );
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'remove_lazy_sizes' ] );
	}

	/**
	 * Whether sizes wait for their first request.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		static $supported = null;

		// Without an image editor nothing can be made later either.
		$supported ??= wp_image_editor_supports();

		/**
		 * Filters whether sizes wait for their first request.
		 *
		 * @param bool $enabled True when the server has an image editor.
		 */
		return (bool) apply_filters( 'yourmark/image-sizes/enabled', $supported );
	}

	/**
	 * Size names still made at upload.
	 *
	 * @return string[]
	 */
	public static function base(): array {
		/**
		 * Filters the sizes made at upload. None by default: the media library's
		 * first look at a new upload makes the one it shows.
		 *
		 * @param string[] $sizes Size names.
		 */
		return (array) apply_filters( 'yourmark/image-sizes/base', [] );
	}

	/**
	 * File name without extension that an upload's sizes are named after.
	 *
	 * Core names sizes after the attached file without its -scaled or -rotated
	 * suffix, so URLs already in content keep pointing at the same names. An
	 * edited image is attached under a new name (photo-e123) and keeps it.
	 *
	 * @param array<string, mixed> $data Attachment metadata.
	 * @return string
	 */
	public static function stem( array $data ): string {
		return (string) preg_replace( '/(?:-(?:scaled|rotated))+$/', '', pathinfo( (string) $data['file'], PATHINFO_FILENAME ) );
	}

	/**
	 * Make only the base sizes at upload.
	 *
	 * The block editor's client-side processing asks wp_get_missing_image_subsizes()
	 * what to make, which reads metadata with the lazy sizes already in it, so the
	 * browser makes the same base sizes this does on the server.
	 *
	 * @param array<string, array<string, mixed>> $sizes      Sizes to make, keyed by name.
	 * @param array<string, mixed>                $image_meta Metadata of the upload.
	 * @return array<string, array<string, mixed>>
	 */
	public static function keep_base_sizes( array $sizes, array $image_meta ): array {
		if ( ! self::enabled() || self::resizable_mime( (string) ( $image_meta['file'] ?? '' ) ) === null ) {
			return $sizes;
		}

		return array_intersect_key( $sizes, array_flip( self::base() ) );
	}

	/**
	 * Add an entry for every registered size the upload has no file for yet.
	 *
	 * @param mixed $data Attachment metadata; false or a string when missing or corrupt.
	 * @return mixed
	 */
	public static function add_lazy_sizes( $data ) {
		if ( ! is_array( $data ) || empty( $data['file'] ) || empty( $data['width'] ) || empty( $data['height'] ) ) {
			return $data;
		}

		$mime = self::resizable_mime( $data['file'] );

		if ( $mime === null || ! self::enabled() ) {
			return $data;
		}

		$output_mime = wp_get_image_editor_output_format( $data['file'], $mime )[ $mime ] ?? $mime;
		$extension   = wp_get_default_extension_for_mime_type( $output_mime );
		$stem        = self::stem( $data );
		$base        = self::base();

		if ( ! isset( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
			$data['sizes'] = [];
		}

		foreach ( wp_get_registered_image_subsizes() as $size_name => $size ) {
			if ( isset( $data['sizes'][ $size_name ] ) || in_array( $size_name, $base, true ) ) {
				continue;
			}

			$dimensions = image_resize_dimensions( (int) $data['width'], (int) $data['height'], $size['width'], $size['height'], $size['crop'] );

			// False when the upload is too small for this size, as in core.
			if ( ! $dimensions ) {
				continue;
			}

			[ , , , , $width, $height ] = $dimensions;

			$data['sizes'][ $size_name ] = [
				'width'     => $width,
				'height'    => $height,
				'file'      => "{$stem}-{$width}x{$height}.{$extension}",
				'mime-type' => $output_mime,
				self::LAZY  => true,
			];
		}

		return $data;
	}

	/**
	 * Keep lazy entries out of the database, where they would go stale.
	 *
	 * Code that reads metadata, changes it and saves it back would otherwise
	 * store them.
	 *
	 * @param mixed $data Attachment metadata.
	 * @return mixed
	 */
	public static function remove_lazy_sizes( $data ) {
		if ( is_array( $data ) && isset( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			$data['sizes'] = array_filter( $data['sizes'], fn( $size ): bool => empty( $size[ self::LAZY ] ) );
		}

		return $data;
	}

	/**
	 * An upload's MIME type, if its sizes are made lazily.
	 *
	 * @param string $file Path or file name of the upload.
	 * @return string|null
	 */
	private static function resizable_mime( string $file ): ?string {
		$mime = wp_check_filetype( $file )['type'];

		return in_array( $mime, self::RESIZABLE, true ) ? $mime : null;
	}
}
