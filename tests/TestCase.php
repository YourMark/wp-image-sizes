<?php
/**
 * Shared set-up for the image sizes tests.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use Imagick;
use ImagickPixel;
use WP_UnitTestCase;
use YourMark\ImageSizes\Endpoint;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * Makes test images, uploads them the way the media library does, and cleans up the files.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Directory for source images before upload.
	 *
	 * @var string
	 */
	protected string $fixtures;

	/**
	 * Start every test with an empty uploads directory and the default format.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->fixtures = sys_get_temp_dir() . '/yourmark-image-sizes-fixtures-' . wp_generate_password( 6, false );
		wp_mkdir_p( $this->fixtures );

		$this->empty_uploads();
		update_option( 'yourmark_image_format', 'webp' );
		$this->routing( true );
	}

	/**
	 * Set what the server test found: whether a request for a missing file reaches WordPress.
	 *
	 * @param bool $direct True for the usual server, false for one that answers missing files itself.
	 * @return void
	 */
	protected function routing( bool $direct ): void {
		update_option(
			'yourmark_image_sizes_routing',
			[
				'ok'      => $direct,
				'checked' => time(),
			]
		);
	}

	/**
	 * Write a PNG with an acTL chunk, which is all that makes a PNG animated.
	 *
	 * ImageMagick cannot write an animated PNG, so the chunk is put in by hand.
	 *
	 * @param string $name File name.
	 * @return string Absolute path.
	 */
	protected function animated_png( string $name ): string {
		$png  = (string) file_get_contents( $this->image( "still-{$name}", 'red', 400, 300 ) );
		$data = pack( 'NN', 2, 0 );
		$actl = pack( 'N', strlen( $data ) ) . 'acTL' . $data . pack( 'N', crc32( 'acTL' . $data ) );

		// After the signature (8 bytes) and the IHDR chunk (25 bytes), before any image data.
		file_put_contents( "{$this->fixtures}/{$name}", substr( $png, 0, 33 ) . $actl . substr( $png, 33 ) );

		return "{$this->fixtures}/{$name}";
	}

	/**
	 * Remove the files this test made.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->empty_uploads();
		$this->rmdir( $this->fixtures );
		rmdir( $this->fixtures );

		parent::tear_down();
	}

	/**
	 * A one-colour image, so a test can tell which source a size came from.
	 *
	 * @param string $name   File name, its extension deciding the format.
	 * @param string $colour Any ImageMagick colour.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @return string Absolute path.
	 */
	protected function image( string $name, string $colour = 'red', int $width = 1200, int $height = 900 ): string {
		$image = new Imagick();
		$image->newImage( $width, $height, new ImagickPixel( $colour ) );
		$image->setImageFormat( pathinfo( $name, PATHINFO_EXTENSION ) );
		$image->writeImage( "{$this->fixtures}/{$name}" );

		return "{$this->fixtures}/{$name}";
	}

	/**
	 * A two-frame animated WebP.
	 *
	 * @param string $name File name.
	 * @return string Absolute path.
	 */
	protected function animated_webp( string $name ): string {
		$animation = new Imagick();

		foreach ( [ 'red', 'blue' ] as $colour ) {
			$frame = new Imagick();
			$frame->newImage( 400, 300, new ImagickPixel( $colour ) );
			$frame->setImageFormat( 'webp' );
			$frame->setImageDelay( 50 );
			$animation->addImage( $frame );
		}

		$animation->setFormat( 'webp' );
		$animation->writeImages( "{$this->fixtures}/{$name}", true );

		return "{$this->fixtures}/{$name}";
	}

	/**
	 * Upload a file the way the media library does, through wp_handle_upload.
	 *
	 * @param string $path Source image; copied, so it can be uploaded again.
	 * @return int Attachment ID.
	 */
	protected function upload( string $path ): int {
		$copy = "{$this->fixtures}/upload-" . wp_generate_password( 6, false ) . '-' . basename( $path );
		copy( $path, $copy );

		$id = media_handle_sideload(
			[
				'name'     => basename( $path ),
				'tmp_name' => $copy,
			]
		);

		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : 'Upload failed.' );

		return $id;
	}

	/**
	 * Path below the uploads base of a file next to an attachment's own.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File name.
	 * @return string
	 */
	protected function relative( int $attachment_id, string $file ): string {
		$directory = dirname( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );

		return ( $directory === '.' ? '' : "{$directory}/" ) . $file;
	}

	/**
	 * Absolute path of a file next to an attachment's own.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File name.
	 * @return string
	 */
	protected function path( int $attachment_id, string $file ): string {
		return wp_get_upload_dir()['basedir'] . '/' . $this->relative( $attachment_id, $file );
	}

	/**
	 * The request URI of a file's own URL in uploads.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File name.
	 * @return string
	 */
	protected function uri( int $attachment_id, string $file ): string {
		return wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) . '/' . $this->relative( $attachment_id, $file );
	}

	/**
	 * The request URI of the PHP URL for a file.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          File name.
	 * @return string
	 */
	protected function php_uri( int $attachment_id, string $file ): string {
		return '/index.php?' . Endpoint::PARAM . '=' . rawurlencode( $this->relative( $attachment_id, $file ) );
	}

	/**
	 * The file name of a size in an attachment's metadata, lazy or stored.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size name.
	 * @return string
	 */
	protected function size_file( int $attachment_id, string $size ): string {
		$meta = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( $size, $meta['sizes'] );

		return $meta['sizes'][ $size ]['file'];
	}

	/**
	 * Colour name of an image's top-left pixel, as red, blue or other.
	 *
	 * @param string $path Image.
	 * @return string
	 */
	protected function colour( string $path ): string {
		$pixel = ( new Imagick( $path ) )->getImagePixelColor( 5, 5 )->getColor();

		if ( $pixel['r'] > 200 && $pixel['b'] < 60 ) {
			return 'red';
		}

		return $pixel['b'] > 200 && $pixel['r'] < 60 ? 'blue' : 'other';
	}

	/**
	 * Every file in the uploads directory, below its base.
	 *
	 * @return string[]
	 */
	protected function upload_files(): array {
		$base  = wp_get_upload_dir()['basedir'];
		$files = [];

		if ( ! is_dir( $base ) ) {
			return [];
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			$files[] = substr( $file->getPathname(), strlen( $base ) + 1 );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Delete everything in the uploads directory.
	 *
	 * @return void
	 */
	private function empty_uploads(): void {
		$base = wp_get_upload_dir()['basedir'];

		if ( is_dir( $base ) ) {
			$this->rmdir( $base );
		}
	}
}
