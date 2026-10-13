<?php
/**
 * Tests for which sizes exist at upload and how lazy sizes are named.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

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

	public function test_lazy_entries_never_reach_the_database(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );

		$this->assertSame( [], wp_get_attachment_metadata( $id, true )['sizes'] );
	}

	public function test_a_lazy_name_carries_the_source_extension_when_the_format_changes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.png' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertSame( 'photo-png-300x225.webp', $this->size_file( $id, 'medium' ) );
	}

	public function test_a_lazy_name_keeps_the_plain_stem_in_the_source_format(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.png' ) );

		$this->assertSame( 'photo-300x225.png', $this->size_file( $id, 'medium' ) );
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

	public function test_an_animation_keeps_its_format(): void {
		if ( ! Formats::supports( 'image/avif' ) ) {
			$this->markTestSkipped( 'The image editor cannot write AVIF.' );
		}

		update_option( 'yourmark_image_format', 'avif' );
		$id   = $this->upload( $this->animated_webp( 'moving.webp' ) );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertSame( 'image/webp', get_post_mime_type( $id ) );
		$this->assertStringEndsWith( '.webp', $meta['file'] );
		$this->assertTrue( $meta[ Sizes::ANIMATED ] );
		$this->assertSame( 'moving-300x225.webp', $meta['sizes']['medium']['file'] );
	}
}
