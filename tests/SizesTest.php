<?php
/**
 * Tests for which sizes exist at upload and how lazy sizes are named.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Formats;
use YourMark\ImageSizes\Sizes;

/**
 * Upload, metadata and naming.
 */
final class SizesTest extends TestCase {

	public function test_an_upload_writes_only_its_original(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$this->assertSame( [], wp_get_attachment_metadata( $id, true )['sizes'] );
		$this->assertSame( [ $this->relative( $id, 'red.webp' ) ], $this->upload_files() );
	}

	public function test_every_registered_size_is_listed_as_lazy(): void {
		$id    = $this->upload( $this->image( 'red.jpg' ) );
		$sizes = wp_get_attachment_metadata( $id )['sizes'];

		ksort( $sizes );

		$this->assertSame( [ 'large', 'medium', 'medium_large', 'thumbnail' ], array_keys( $sizes ) );
		$this->assertSame( 'red-768x576.webp', $sizes['medium_large']['file'] );
		$this->assertTrue( $sizes['medium'][ Sizes::LAZY ] );
	}

	public function test_the_browser_is_asked_for_no_sizes_after_an_upload(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		// What the block editor's client-side processing asks before it makes sizes itself.
		$this->assertSame( [], wp_get_missing_image_subsizes( $id ) );
	}

	public function test_uploads_stay_on_the_server_while_sizes_are_lazy(): void {
		$this->assertFalse( Sizes::server_side_uploads( true ) );

		add_filter( 'yourmark/image-sizes/enabled', '__return_false' );
		$this->assertTrue( Sizes::server_side_uploads( true ), 'Without lazy sizes the browser may help again.' );
	}

	public function test_a_rotated_camera_photo_is_turned_and_scaled_at_upload(): void {
		$path = $this->image( 'camera.jpg', 'red', 3200, 2400 );
		$jpeg = (string) file_get_contents( $path );
		$tiff = "II*\x00" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvV', 0x0112, 3, 1 ) . pack( 'vv', 6, 0 ) . pack( 'V', 0 );
		$exif = "Exif\x00\x00" . $tiff;
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 ) );

		$id   = $this->upload( $path );
		$meta = wp_get_attachment_metadata( $id, true );

		$this->assertSame( 'camera-scaled.webp', basename( (string) get_attached_file( $id ) ) );
		$this->assertSame( [ 1920, 2560 ], [ $meta['width'], $meta['height'] ], 'Turned upright and scaled to the threshold.' );
	}

	public function test_lazy_entries_never_reach_the_database(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );

		$this->assertSame( [], wp_get_attachment_metadata( $id, true )['sizes'] );
	}

	public function test_a_lazy_name_carries_the_source_extension_when_the_format_changes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.png' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertSame( 'photo--png-300x225.webp', $this->size_file( $id, 'medium' ) );
	}

	public function test_a_lazy_name_keeps_the_plain_stem_in_the_source_format(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.png' ) );

		$this->assertSame( 'photo-300x225.png', $this->size_file( $id, 'medium' ) );
	}

	public function test_no_upload_can_be_named_like_a_lazy_stem(): void {
		$id = $this->upload( $this->image( 'photo--jpg.webp' ) );

		$this->assertSame( 'photo-jpg.webp', basename( (string) get_attached_file( $id ) ), 'Core collapses the double hyphen.' );
	}

	public function test_the_stem_drops_core_suffixes_but_keeps_an_edit(): void {
		$this->assertSame( 'photo', Sizes::stem( [ 'file' => '2026/10/photo-scaled.jpg' ] ) );
		$this->assertSame( 'photo', Sizes::stem( [ 'file' => '2026/10/photo-rotated.jpg' ] ) );
		$this->assertSame(
			'photo-e1697',
			Sizes::stem(
				[
					'file'           => '2026/10/photo-e1697.jpg',
					'original_image' => 'photo.jpg',
				]
			)
		);
	}

	public function test_an_animated_png_keeps_its_format(): void {
		$id = $this->upload( $this->animated_png( 'moving.png' ) );

		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( '1', get_post_meta( $id, Sizes::ANIMATED, true ) );
		$this->assertSame( 'moving-300x225.png', $this->size_file( $id, 'medium' ) );
	}

	public function test_an_animated_upload_from_before_the_flag_is_recognised_once(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->animated_png( 'moving.png' ) );
		delete_post_meta( $id, Sizes::ANIMATED );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertSame( 'moving-300x225.png', $this->size_file( $id, 'medium' ) );
		$this->assertSame( '1', get_post_meta( $id, Sizes::ANIMATED, true ) );
	}

	public function test_an_animated_webp_keeps_its_format_when_avif_is_chosen(): void {
		if ( ! Formats::supports( 'image/avif' ) ) {
			$this->markTestSkipped( 'The image editor cannot write AVIF.' );
		}

		update_option( 'yourmark_image_format', 'avif' );
		$id = $this->upload( $this->animated_webp( 'moving.webp' ) );

		$this->assertSame( 'image/webp', get_post_mime_type( $id ) );
		$this->assertSame( 'moving.webp', basename( (string) get_attached_file( $id ) ) );
		$this->assertSame( 'moving-300x225.webp', $this->size_file( $id, 'medium' ) );
	}

	public function test_an_upload_with_a_capital_extension_gets_its_sizes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'PHOTO.JPG' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$file = $this->size_file( $id, 'medium' );

		$this->assertSame( 'PHOTO--jpg-300x225.webp', $file );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, $file ) )['status'] );
	}

	public function test_an_upload_outside_year_and_month_folders_gets_its_sizes(): void {
		add_filter(
			'upload_dir',
			fn( array $dir ): array => array_merge(
				$dir,
				[
					'subdir' => '',
					'path'   => $dir['basedir'],
					'url'    => $dir['baseurl'],
				]
			)
		);
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$file = $this->size_file( $id, 'medium' );

		$this->assertSame( 'red.webp', (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, $file ) )['status'] );
		$this->assertFileExists( wp_get_upload_dir()['basedir'] . "/{$file}" );
	}
}
