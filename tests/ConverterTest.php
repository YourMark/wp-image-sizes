<?php
/**
 * Tests for converting older uploads.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Converter;
use YourMark\ImageSizes\Formats;
use YourMark\ImageSizes\Pruner;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * Conversion to the chosen format.
 */
final class ConverterTest extends TestCase {

	public function test_an_old_jpeg_becomes_webp_and_keeps_its_old_file(): void {
		update_option( 'yourmark_image_format', 'original' );
		$base = fn(): array => [ 'medium' ];
		add_filter( 'yourmark/image-sizes/base', $base );
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		remove_filter( 'yourmark/image-sizes/base', $base );

		copy( (string) get_attached_file( $id ), $this->path( $id, 'red-123x92.jpg' ) );
		$meta                    = wp_get_attachment_metadata( $id, true );
		$meta['sizes']['custom'] = [
			'file'      => 'red-123x92.jpg',
			'width'     => 123,
			'height'    => 92,
			'mime-type' => 'image/jpeg',
		];
		wp_update_attachment_metadata( $id, $meta );

		update_option( 'yourmark_image_format', 'webp' );
		$this->assertTrue( Converter::convert( $id ) );

		$meta = wp_get_attachment_metadata( $id, true );

		$this->assertSame( 'image/webp', get_post_mime_type( $id ) );
		$this->assertSame( 'red.webp', basename( (string) get_attached_file( $id ) ) );
		$this->assertFileExists( $this->path( $id, 'red.jpg' ), 'The old file stays for links in content.' );
		$this->assertSame( [ $this->relative( $id, 'red.jpg' ) ], get_post_meta( $id, Converter::PREVIOUS, false ) );
		$this->assertArrayNotHasKey( 'medium', $meta['sizes'], 'A registered size makes way for a lazy one.' );
		$this->assertArrayHasKey( 'custom', $meta['sizes'], 'An unregistered size could not come back, so it stays.' );
		$this->assertSame( [ 'red-300x225.jpg' ], Pruner::made( $id ) );
	}

	public function test_conversion_picks_a_name_no_other_image_has(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'red.jpg' ) );
		copy( (string) get_attached_file( $id ), $this->path( $id, 'red.png' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertTrue( Converter::convert( $id ) );
		$this->assertSame( 'red-1.webp', basename( (string) get_attached_file( $id ) ) );
	}

	public function test_what_is_never_converted_and_why(): void {
		$current = $this->upload( $this->image( 'current.webp' ) );

		update_option( 'yourmark_image_format', 'original' );
		$icon     = $this->upload( $this->image( 'icon.png', 'red', 512, 512 ) );
		$gif      = $this->upload( $this->image( 'still.gif' ) );
		$animated = $this->upload( $this->animated_png( 'moving.png' ) );
		update_option( 'site_icon', $icon );

		update_option( 'yourmark_image_format', 'webp' );
		$this->assertSame( 'current', Converter::skip_reason( $current ) );
		$this->assertSame( 'icon', Converter::skip_reason( $icon ) );
		$this->assertSame( 'type', Converter::skip_reason( $gif ) );
		$this->assertSame( 'animated', Converter::skip_reason( $animated ) );
		$this->assertFalse( Converter::convert( $icon ) );
		$this->assertFalse( Converter::convert( $animated ) );
	}

	public function test_an_animated_upload_is_not_converted_on_upload(): void {
		$id = $this->upload( $this->animated_png( 'moving.png' ) );

		$this->assertTrue( Formats::is_animated( (string) get_attached_file( $id ), 'image/png' ) );
		$this->assertSame( 'moving.png', basename( (string) get_attached_file( $id ) ) );
	}
}
