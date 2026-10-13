<?php
/**
 * Converts existing uploads to the chosen format and makes their sizes on demand.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_Error;

/**
 * Brings an upload from before the format setting into line with it.
 */
final class Converter {

	/**
	 * Metadata key listing files an attachment was converted from.
	 */
	public const PREVIOUS = 'yourmark_previous_files';

	/**
	 * Store an attachment's file in the format chosen under Settings > Media.
	 *
	 * The old file stays on disk, so links to it in content keep working, and is
	 * deleted with the attachment. Stored sizes in the old format leave the
	 * metadata and come back lazily in the new one; requests for their old names
	 * are redirected to the new ones.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool|WP_Error True when converted, false when skip_reason() gives a reason not to.
	 */
	public static function convert( int $attachment_id ) {
		$target = Formats::target();
		$source = (string) get_attached_file( $attachment_id );

		if ( $target === null || self::skip_reason( $attachment_id ) !== null ) {
			return false;
		}

		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		// WebP and AVIF have no reliable EXIF orientation, so bake the rotation into the pixels.
		$editor->maybe_exif_rotate();

		$directory = dirname( $source );
		$name      = pathinfo( $source, PATHINFO_FILENAME ) . '.' . wp_get_default_extension_for_mime_type( $target );

		if ( file_exists( "{$directory}/{$name}" ) ) {
			$name = wp_unique_filename( $directory, $name );
		}

		$saved = $editor->save( "{$directory}/{$name}", $target );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$meta = wp_get_attachment_metadata( $attachment_id, true );
		$meta = is_array( $meta ) ? $meta : [];

		$meta['file']           = _wp_relative_upload_path( $saved['path'] );
		$meta['width']          = $saved['width'];
		$meta['height']         = $saved['height'];
		$meta['filesize']       = (int) filesize( $saved['path'] );
		$meta[ self::PREVIOUS ] = [ ...(array) ( $meta[ self::PREVIOUS ] ?? [] ), basename( $source ) ];
		$meta['sizes']          = array_filter( (array) ( $meta['sizes'] ?? [] ), fn( array $size ): bool => ( $size['mime-type'] ?? '' ) === $target );

		update_attached_file( $attachment_id, $saved['path'] );
		wp_update_attachment_metadata( $attachment_id, $meta );
		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $target,
			]
		);

		return true;
	}

	/**
	 * Why an attachment is not converted, or null when it would be.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null 'current' when already in the chosen format, 'icon' for the site icon, 'type' for a format that is never converted, such as GIF or SVG.
	 */
	public static function skip_reason( int $attachment_id ): ?string {
		$target = Formats::target();
		$mime   = (string) get_post_mime_type( $attachment_id );

		if ( $mime === $target ) {
			return 'current';
		}

		if ( $target === null || ! Formats::converts( $mime, $target ) || ! get_attached_file( $attachment_id ) ) {
			return 'type';
		}

		return self::convertible( $attachment_id ) ? null : 'icon';
	}

	/**
	 * Whether an attachment may be converted at all.
	 *
	 * The site icon stays as it is: iOS ignores a WebP or AVIF apple-touch-icon.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function convertible( int $attachment_id ): bool {
		return $attachment_id !== (int) get_option( 'site_icon' ) && get_post_meta( $attachment_id, '_wp_attachment_context', true ) !== 'site-icon';
	}

	/**
	 * Make every size of an attachment that has no file yet.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of sizes made.
	 */
	public static function make_sizes( int $attachment_id ): int {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return 0;
		}

		$basedir   = wp_get_upload_dir()['basedir'];
		$directory = dirname( (string) $meta['file'] );
		$prefix    = $directory === '.' ? '' : "{$directory}/";
		$made      = 0;

		foreach ( $meta['sizes'] ?? [] as $size ) {
			$relative = $prefix . $size['file'];

			if ( ! file_exists( "{$basedir}/{$relative}" ) && is_array( Generator::make( $relative ) ) ) {
				++$made;
			}
		}

		return $made;
	}
}
