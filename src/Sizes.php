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
	 * Post meta key, '1' for an animated upload and '0' for a still one; read from the file once.
	 */
	public const ANIMATED = '_yourmark_image_sizes_animated';

	/**
	 * Separates a stem from the source extension in a lazy size name.
	 *
	 * Two hyphens, because sanitize_file_name() collapses any run of them in an
	 * upload's name: no upload can be called photo--jpg, so no upload's sizes can
	 * collide with those of photo.jpg.
	 */
	public const SEPARATOR = '--';

	/**
	 * Key that marks an in-between width: a srcset candidate, not a size anyone picks.
	 */
	public const STEP = 'yourmark_step';

	/**
	 * Widths offered between the registered sizes, so a browser never has to jump from 300 to 768.
	 *
	 * With core's sizes they make a ladder of about 1.25x per step: 300, 400,
	 * 520, 640, 768, 1024, 1280, 1536, 2048. Wider steps leave a phone at
	 * 1.75x past the 12 KiB Lighthouse lets a srcset waste; finer ones buy
	 * nothing but files.
	 */
	private const STEPS = [ 400, 520, 640, 1280 ];

	/**
	 * A step this close to a width the image already offers adds a file and saves nothing.
	 */
	private const MIN_GAP = 1.15;

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
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'mark_animated' ], 5, 2 );
		add_filter( 'wp_get_attachment_metadata', [ self::class, 'add_lazy_sizes' ], 10, 2 );
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'remove_lazy_sizes' ] );
		add_filter( 'wp_client_side_media_processing_enabled', [ self::class, 'server_side_uploads' ] );
	}

	/**
	 * Keep uploads on the server while sizes are lazy.
	 *
	 * The browser's processing exists to make sizes at upload, which this
	 * plugin no longer does. Worse, it scales a large image and applies its EXIF
	 * rotation only as part of making missing sizes, so with none missing a
	 * camera photo would stay full size and sideways. The server does both.
	 *
	 * @param bool $enabled Whether the block editor processes uploads in the browser.
	 * @return bool
	 */
	public static function server_side_uploads( bool $enabled ): bool {
		return $enabled && ! self::enabled();
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
	 * File name without extension that an upload's lazy sizes are named after.
	 *
	 * When a size changes format, the source extension joins the name:
	 * photo.jpg and photo.png in one folder would otherwise both get
	 * photo-300x200.webp, and one would show the other's picture. They get
	 * photo--jpg-300x200.webp and photo--png-300x200.webp.
	 *
	 * @param array<string, mixed> $data        Attachment metadata.
	 * @param string               $mime        Source MIME type.
	 * @param string               $output_mime MIME type the sizes are written in.
	 * @return string
	 */
	public static function lazy_stem( array $data, string $mime, string $output_mime ): string {
		$stem = self::stem( $data );

		return $mime === $output_mime ? $stem : $stem . self::SEPARATOR . strtolower( pathinfo( (string) $data['file'], PATHINFO_EXTENSION ) );
	}

	/**
	 * Note whether a new or replaced upload is animated, before anything reads its sizes.
	 *
	 * @param mixed $metadata      New attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function mark_animated( $metadata, int $attachment_id ) {
		delete_post_meta( $attachment_id, self::ANIMATED );
		self::is_animated( $attachment_id );

		return $metadata;
	}

	/**
	 * Whether an attachment is an animated WebP or PNG.
	 *
	 * Telling an animation apart means opening the file, so the answer is kept
	 * in post meta: written at upload, or on first need for an upload from
	 * before this plugin.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_animated( int $attachment_id ): bool {
		$known = get_post_meta( $attachment_id, self::ANIMATED, true );

		if ( $known === '1' || $known === '0' ) {
			return $known === '1';
		}

		$animated = Formats::is_animated( (string) get_attached_file( $attachment_id ), (string) get_post_mime_type( $attachment_id ) );
		update_post_meta( $attachment_id, self::ANIMATED, $animated ? '1' : '0' );

		return $animated;
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
	 * @param mixed $data          Attachment metadata; false or a string when missing or corrupt.
	 * @param int   $attachment_id Attachment ID; 0 when unknown, which treats the upload as still.
	 * @return mixed
	 */
	public static function add_lazy_sizes( $data, int $attachment_id = 0 ) {
		if ( ! is_array( $data ) || empty( $data['file'] ) || empty( $data['width'] ) || empty( $data['height'] ) ) {
			return $data;
		}

		$mime = self::resizable_mime( $data['file'] );

		if ( $mime === null || ! self::enabled() ) {
			return $data;
		}

		$output_mime = wp_get_image_editor_output_format( $data['file'], $mime )[ $mime ] ?? $mime;

		// An animation keeps its format: converting keeps only the first frame. Only asked when the format would change.
		if ( $output_mime !== $mime && $attachment_id > 0 && in_array( $mime, [ 'image/webp', 'image/png' ], true ) && self::is_animated( $attachment_id ) ) {
			$output_mime = $mime;
		}

		$extension = wp_get_default_extension_for_mime_type( $output_mime );

		// A format without a file extension could not be written under any name.
		if ( $extension === false ) {
			return $data;
		}

		$stem = self::lazy_stem( $data, $mime, $output_mime );
		$base = self::base();

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

		return self::add_steps( $data, $stem, $extension, $output_mime );
	}

	/**
	 * Widths a srcset may offer between the registered sizes.
	 *
	 * @return int[]
	 */
	public static function steps(): array {
		/**
		 * Filters the in-between widths. Each is only a name in the srcset until a browser asks for it.
		 *
		 * @param int[] $widths Widths in pixels.
		 */
		$widths = array_map( 'intval', (array) apply_filters( 'yourmark/image-sizes/steps', self::STEPS ) );

		return array_values( array_unique( array_filter( $widths, fn( int $width ): bool => $width > 0 ) ) );
	}

	/**
	 * Whether a metadata entry is an in-between width.
	 *
	 * @param mixed $size Entry from an attachment's sizes.
	 * @return bool
	 */
	public static function is_step( $size ): bool {
		return is_array( $size ) && ! empty( $size[ self::STEP ] );
	}

	/**
	 * Add the in-between widths, in the upload's own proportions.
	 *
	 * Only widths well apart from the upload's own and from its sizes in the
	 * same proportions. Core puts any entry with the right proportions in a
	 * srcset, so these reach every image without touching a template, old
	 * content included.
	 *
	 * @param array<string, mixed> $data        Attachment metadata with its sizes.
	 * @param string               $stem        Name the lazy sizes carry.
	 * @param string               $extension   Extension the lazy sizes are written with.
	 * @param string               $output_mime MIME type the lazy sizes are written in.
	 * @return array<string, mixed>
	 */
	private static function add_steps( array $data, string $stem, string $extension, string $output_mime ): array {
		$width  = (int) $data['width'];
		$height = (int) $data['height'];
		$taken  = [ $width ];

		foreach ( (array) $data['sizes'] as $size ) {
			if ( is_array( $size ) && ! empty( $size['width'] ) && ! empty( $size['height'] ) && wp_image_matches_ratio( $width, $height, (int) $size['width'], (int) $size['height'] ) ) {
				$taken[] = (int) $size['width'];
			}
		}

		foreach ( self::steps() as $step ) {
			if ( $step >= $width || self::near( $step, $taken ) ) {
				continue;
			}

			$step_height = (int) max( 1, round( $height * $step / $width ) );

			$data['sizes'][ "yourmark-{$step}w" ] = [
				'width'     => $step,
				'height'    => $step_height,
				'file'      => "{$stem}-{$step}x{$step_height}.{$extension}",
				'mime-type' => $output_mime,
				self::LAZY  => true,
				self::STEP  => true,
			];
		}

		return $data;
	}

	/**
	 * Whether a width lies within MIN_GAP of any of some others.
	 *
	 * @param int   $width  Width.
	 * @param int[] $others Widths already offered.
	 * @return bool
	 */
	private static function near( int $width, array $others ): bool {
		foreach ( $others as $other ) {
			if ( max( $width, $other ) < min( $width, $other ) * self::MIN_GAP ) {
				return true;
			}
		}

		return false;
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
