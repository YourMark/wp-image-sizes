<?php
/**
 * Tests for replacing and deleting attachments.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use Imagick;
use ImagickPixel;
use YourMark\ImageSizes\Converter;
use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Pruner;

/**
 * Sizes made from an old file must not outlive it, and nothing else may go.
 */
final class CleanupTest extends TestCase {

	public function test_replacing_a_file_under_its_own_name_drops_sizes_of_the_old_picture(): void {
		$id     = $this->upload( $this->image( 'red.jpg' ) );
		$medium = $this->size_file( $id, 'medium' );
		Endpoint::respond( $this->uri( $id, $medium ) );

		$this->paint( (string) get_attached_file( $id ), 'blue' );
		$this->regenerate( $id );

		$this->assertFileDoesNotExist( $this->path( $id, $medium ) );
		$this->assertSame( [], Pruner::made( $id ) );
		$this->assertSame( 'blue', $this->colour( (string) Endpoint::respond( $this->uri( $id, $medium ) )['file'] ) );
	}

	public function test_replacing_a_file_under_a_new_name_drops_the_old_names_sizes(): void {
		$id     = $this->upload( $this->image( 'red.jpg' ) );
		$medium = $this->size_file( $id, 'medium' );
		Endpoint::respond( $this->uri( $id, $medium ) );

		$replacement = $this->path( $id, 'replacement.webp' );
		$this->paint( $replacement, 'blue' );
		update_attached_file( $id, $replacement );
		$this->regenerate( $id );

		$this->assertFileDoesNotExist( $this->path( $id, $medium ) );
		$this->assertSame( 'replacement-300x225.webp', $this->size_file( $id, 'medium' ) );
	}

	public function test_an_unrecorded_file_under_a_lazy_name_goes_on_replace(): void {
		$id     = $this->upload( $this->image( 'red.jpg' ) );
		$legacy = $this->path( $id, $this->size_file( $id, 'medium' ) );
		copy( (string) get_attached_file( $id ), $legacy );

		$this->regenerate( $id );

		$this->assertFileDoesNotExist( $legacy );
	}

	public function test_an_unrecorded_file_survives_a_replace_when_another_image_shares_the_name(): void {
		update_option( 'yourmark_image_format', 'original' );
		$jpeg = $this->upload( $this->image( 'photo.jpg', 'red' ) );
		$this->upload( $this->image( 'photo.png', 'blue' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$legacy = $this->path( $jpeg, $this->size_file( $jpeg, 'medium' ) );
		copy( (string) get_attached_file( $jpeg ), $legacy );

		$this->regenerate( $jpeg );

		$this->assertFileExists( $legacy );
	}

	public function test_deleting_an_attachment_removes_everything_this_plugin_made_and_nothing_else(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'red.jpg' ) );
		Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertTrue( Converter::convert( $id ) );
		Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) );

		$foreign = $this->relative( $id, 'unrelated.webp' );
		copy( (string) get_attached_file( $id ), $this->path( $id, 'unrelated.webp' ) );

		wp_delete_attachment( $id, true );

		$this->assertSame( [ $foreign ], $this->upload_files() );
	}

	/**
	 * Overwrite an image with one colour, as a replace plugin would.
	 *
	 * @param string $path   Image.
	 * @param string $colour Colour.
	 * @return void
	 */
	private function paint( string $path, string $colour ): void {
		$image = new Imagick();
		$image->newImage( 1200, 900, new ImagickPixel( $colour ) );
		$image->setImageFormat( pathinfo( $path, PATHINFO_EXTENSION ) );
		$image->writeImage( $path );
	}

	/**
	 * Make metadata again from the attached file, as a replace plugin and wp media regenerate do.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function regenerate( int $attachment_id ): void {
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, (string) get_attached_file( $attachment_id ) ) );
	}
}
