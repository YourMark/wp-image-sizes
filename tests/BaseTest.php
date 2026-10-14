<?php
/**
 * Tests for the base sizes made at upload.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Pruner;
use YourMark\ImageSizes\Sizes;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * The base: always there, so a missing size never falls back to the original.
 */
final class BaseTest extends TestCase {

	/**
	 * Use the default base, which TestCase switches off.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'yourmark/image-sizes/base', '__return_empty_array' );
	}

	public function test_an_upload_gets_768_and_1536_and_nothing_else(): void {
		$id    = $this->upload( $this->image( 'red.jpg', 'red', 2400, 1800 ) );
		$sizes = wp_get_attachment_metadata( $id, true )['sizes'];

		ksort( $sizes );

		$this->assertSame( [ '1536x1536', 'medium_large' ], array_keys( $sizes ) );
		$this->assertSame( [ 'red-1536x1152.webp', 'red-768x576.webp', 'red.webp' ], array_map( 'basename', $this->upload_files() ) );
	}

	public function test_a_base_size_wider_than_the_upload_is_not_made(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$this->assertSame( [ 'medium_large' ], array_keys( wp_get_attachment_metadata( $id, true )['sizes'] ) );
	}

	public function test_a_missing_size_is_stood_in_for_by_the_base_not_the_original(): void {
		add_filter( 'yourmark/image-sizes/defer', '__return_true' );
		$id = $this->upload( $this->image( 'red.jpg', 'red', 2400, 1800 ) );

		$response = Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) );

		$this->assertSame( $this->path( $id, 'red-768x576.webp' ), $response['file'] );
	}

	public function test_an_older_upload_gets_its_base_once(): void {
		add_filter( 'yourmark/image-sizes/base', '__return_empty_array' );
		$id = $this->upload( $this->image( 'red.jpg', 'red', 2400, 1800 ) );
		remove_filter( 'yourmark/image-sizes/base', '__return_empty_array' );

		$this->assertSame( 2, Sizes::make_base( $id ) );
		$this->assertFileExists( $this->path( $id, 'red-1536x1152.webp' ) );
		$this->assertSame( 0, Sizes::make_base( $id ), 'Running again makes nothing.' );
	}

	public function test_pruning_keeps_the_base(): void {
		$id = $this->upload( $this->image( 'red.jpg', 'red', 2400, 1800 ) );

		$this->assertSame( [], Pruner::prune( $id, true, false )['files'] );
		$this->assertFileExists( $this->path( $id, 'red-768x576.webp' ) );
	}

	public function test_an_animation_gets_no_base(): void {
		$id = $this->upload( $this->animated_png( 'moving.png' ) );

		$this->assertSame( [], wp_get_attachment_metadata( $id, true )['sizes'], 'None at upload.' );
		$this->assertSame( 0, Sizes::make_base( $id ) );
	}
}
