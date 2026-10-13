<?php
/**
 * The file format images are stored and resized in.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Converts uploads and sizes to the format chosen under Settings > Media.
 *
 * Sizes made lazily take the extension of the current format, so switching
 * needs no regeneration: the next request for a size makes it in the new
 * format, and `wp image-sizes prune` clears out the old files.
 */
final class Formats {

	/**
	 * Option holding the format: original, webp or avif.
	 */
	public const OPTION = 'yourmark_image_format';

	/**
	 * Formats on offer, with the MIME type each writes. Original keeps what was uploaded.
	 */
	public const CHOICES = [
		'original' => null,
		'webp'     => 'image/webp',
		'avif'     => 'image/avif',
	];

	/**
	 * Upload formats that are converted.
	 */
	private const CONVERTIBLE = [ 'image/jpeg', 'image/png', 'image/webp' ];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_handle_upload', [ self::class, 'convert_upload' ] );
		add_filter( 'image_editor_output_format', [ self::class, 'output_format' ], 10, 3 );
		add_filter( 'wp_editor_set_quality', [ self::class, 'quality' ], 10, 2 );
	}

	/**
	 * MIME type images are written in, or null to keep the uploaded format.
	 *
	 * @return string|null
	 */
	public static function target(): ?string {
		$format = (string) get_option( self::OPTION, 'webp' );

		// A host without AVIF support still gets WebP rather than the uploaded JPEG.
		if ( $format === 'avif' && ! self::supports( 'image/avif' ) ) {
			$format = 'webp';
		}

		return self::CHOICES[ $format ] ?? null;
	}

	/**
	 * Name of the target format for buttons and notices, or null to keep the uploaded format.
	 *
	 * @return string|null
	 */
	public static function label(): ?string {
		return match ( self::target() ) {
			'image/webp' => 'WebP',
			'image/avif' => 'AVIF',
			default      => null,
		};
	}

	/**
	 * Whether the server's image editor can write a format.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function supports( string $mime ): bool {
		static $supports = [];

		$supports[ $mime ] ??= wp_image_editor_supports( [ 'mime_type' => $mime ] );

		return $supports[ $mime ];
	}

	/**
	 * Settings API sanitiser for the option.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize( $value ): string {
		return is_string( $value ) && array_key_exists( $value, self::CHOICES ) ? $value : 'webp';
	}

	/**
	 * Store an uploaded image in the target format, so the original is as light as its sizes.
	 *
	 * Runs for browser uploads and sideloads alike. On any editor failure the
	 * upload is kept as it came in.
	 *
	 * @param array{file: string, url: string, type: string} $upload Uploaded file.
	 * @return array{file: string, url: string, type: string}
	 */
	public static function convert_upload( array $upload ): array {
		$target = self::target();

		if ( $target === null || ! self::converts( $upload['type'], $target ) || self::is_site_icon_request() ) {
			return $upload;
		}

		// Converting keeps only the first frame, and the upload is deleted afterwards.
		if ( self::is_animated( $upload['file'], $upload['type'] ) ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $upload['file'] );

		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		// WebP and AVIF have no reliable EXIF orientation, so bake the rotation into the pixels.
		$editor->maybe_exif_rotate();

		$directory = dirname( $upload['file'] );
		$name      = pathinfo( $upload['file'], PATHINFO_FILENAME ) . '.' . wp_get_default_extension_for_mime_type( $target );

		// Only when taken: wp_unique_filename() counts the source file as a clash and appends -1.
		if ( file_exists( "{$directory}/{$name}" ) ) {
			$name = wp_unique_filename( $directory, $name );
		}

		$saved = $editor->save( "{$directory}/{$name}", $target );

		if ( is_wp_error( $saved ) ) {
			return $upload;
		}

		wp_delete_file( $upload['file'] );

		return [
			'file' => $saved['path'],
			'url'  => dirname( $upload['url'] ) . "/{$saved['file']}",
			'type' => $target,
		];
	}

	/**
	 * Write sizes in the target format, also for an upload that slipped through above.
	 *
	 * Core also applies this mapping to the original of an image it saves again,
	 * so an animation is left out here as well.
	 *
	 * @param array<string, string> $formats  Source MIME type => output MIME type.
	 * @param string|null           $filename Path of the image, when core has one.
	 * @param string|null           $mime     Its MIME type.
	 * @return array<string, string>
	 */
	public static function output_format( array $formats, $filename = null, $mime = null ): array {
		$target = self::target();

		if ( $target === null || self::is_site_icon_request() ) {
			return $formats;
		}

		if ( is_string( $filename ) && is_string( $mime ) && self::is_animated( $filename, $mime ) ) {
			return $formats;
		}

		foreach ( self::CONVERTIBLE as $mime ) {
			if ( self::converts( $mime, $target ) ) {
				$formats[ $mime ] = $target;
			}
		}

		return $formats;
	}

	/**
	 * Quality per format, at which WebP and AVIF measured the same.
	 *
	 * Core uses 86 for WebP and 82 for AVIF. AVIF at 82 came out larger than
	 * WebP at 82; at 65 it matched WebP's SSIM at about a quarter less weight.
	 *
	 * @param int    $quality   Quality from 1 to 100.
	 * @param string $mime_type Output MIME type.
	 * @return int
	 */
	public static function quality( int $quality, string $mime_type ): int {
		return match ( $mime_type ) {
			'image/webp' => 82,
			'image/avif' => 65,
			default      => $quality,
		};
	}

	/**
	 * Whether a WebP or PNG file is animated.
	 *
	 * Core's wp_get_webp_info() reports alpha and animation as one type, so the
	 * header is read here: WebP keeps an animation flag in its VP8X chunk, an
	 * animated PNG has an acTL chunk before its image data.
	 *
	 * @param string $file Absolute path.
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_animated( string $file, string $mime ): bool {
		if ( ! in_array( $mime, [ 'image/webp', 'image/png' ], true ) || ! is_readable( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file's first bytes.
		$head = (string) file_get_contents( $file, false, null, 0, $mime === 'image/webp' ? 21 : 65536 );

		if ( $mime === 'image/webp' ) {
			return substr( $head, 12, 4 ) === 'VP8X' && strlen( $head ) > 20 && ( ord( $head[20] ) & 0x02 ) !== 0;
		}

		$actl = strpos( $head, 'acTL' );
		$idat = strpos( $head, 'IDAT' );

		return $actl !== false && ( $idat === false || $actl < $idat );
	}

	/**
	 * Whether an upload format is converted to the target.
	 *
	 * @param string $mime   Upload MIME type.
	 * @param string $target Target MIME type.
	 * @return bool
	 */
	public static function converts( string $mime, string $target ): bool {
		return $mime !== $target && in_array( $mime, self::CONVERTIBLE, true );
	}

	/**
	 * Whether the current request is the customiser cropping a site icon.
	 *
	 * The icon is the one upload that has to stay a PNG: iOS ignores a WebP or
	 * AVIF apple-touch-icon. Core picks its crop flow on the same request field.
	 *
	 * @return bool
	 */
	private static function is_site_icon_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading core's own context switch, acting on nothing.
		return isset( $_POST['context'] ) && $_POST['context'] === 'site-icon';
	}
}
