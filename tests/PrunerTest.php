<?php
/**
 * Tests for what clean-up deletes, and what it never touches.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Pruner;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * Pruning.
 */
final class PrunerTest extends TestCase {

	public function test_a_file_merely_named_like_a_size_is_never_deleted(): void {
		$id      = $this->upload( $this->image( 'red.jpg' ) );
		$foreign = $this->path( $id, 'red-640x480.webp' );
		copy( (string) get_attached_file( $id ), $foreign );

		Pruner::prune( $id, true, false );
		$this->assertFileExists( $foreign, 'Pruning.' );

		wp_delete_attachment( $id, true );
		$this->assertFileExists( $foreign, 'Deleting the attachment.' );
	}

	public function test_a_size_in_a_format_switched_away_from_is_pruned_and_redirected(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'red.jpg' ) );
		Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$pruned = Pruner::prune( $id, false, false );

		$this->assertSame( [ $this->path( $id, 'red-300x225.jpg' ) ], $pruned['files'] );
		$this->assertFileDoesNotExist( $this->path( $id, 'red-300x225.jpg' ) );
		$this->assertSame( [], Pruner::made( $id ) );

		$response = Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/red--jpg-300x225.webp', $response['headers']['Location'] );
	}

	public function test_a_stored_registered_size_is_pruned_forgotten_and_made_again_on_request(): void {
		$id = $this->upload_with_stored_medium( 'red.jpg' );

		$this->assertFileExists( $this->path( $id, 'red-300x225.webp' ) );

		Pruner::prune( $id, true, false );

		$this->assertFileDoesNotExist( $this->path( $id, 'red-300x225.webp' ) );
		$this->assertSame( [], wp_get_attachment_metadata( $id, true )['sizes'] );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, 'red-300x225.webp' ) )['status'] );
	}

	public function test_a_stored_size_that_is_not_registered_is_kept(): void {
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$file = $this->path( $id, 'red-123x92.webp' );
		copy( (string) get_attached_file( $id ), $file );

		$meta                    = wp_get_attachment_metadata( $id, true );
		$meta['sizes']['custom'] = [
			'file'      => 'red-123x92.webp',
			'width'     => 123,
			'height'    => 92,
			'mime-type' => 'image/webp',
		];
		wp_update_attachment_metadata( $id, $meta );

		Pruner::prune( $id, true, false );

		$this->assertFileExists( $file );
		$this->assertArrayHasKey( 'custom', wp_get_attachment_metadata( $id, true )['sizes'] );
	}

	public function test_the_site_icon_is_never_pruned(): void {
		$id = $this->upload_with_stored_medium( 'icon.png', 512, 512 );
		update_option( 'site_icon', $id );

		$this->assertSame( [], Pruner::prune( $id, true, false )['files'] );
	}

	public function test_a_dry_run_deletes_nothing(): void {
		$id = $this->upload_with_stored_medium( 'red.jpg' );

		$this->assertCount( 1, Pruner::prune( $id, true, true )['files'] );
		$this->assertFileExists( $this->path( $id, 'red-300x225.webp' ) );
		$this->assertCount( 1, wp_get_attachment_metadata( $id, true )['sizes'] );
	}

	public function test_records_of_files_that_are_gone_are_dropped(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		Pruner::record( $id, 'red-1x1.webp' );

		Pruner::prune( $id, false, false );

		$this->assertSame( [], Pruner::made( $id ) );
	}

	public function test_a_file_still_listed_as_a_size_is_not_pruned_although_it_was_recorded(): void {
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$file = $this->size_file( $id, 'medium' );
		Endpoint::respond( $this->uri( $id, $file ) );

		$this->assertSame( [], Pruner::prune( $id, false, false )['files'] );
		$this->assertFileExists( $this->path( $id, $file ) );
	}

	/**
	 * Upload with core making the medium size at upload, as before this plugin.
	 *
	 * @param string $name   File name.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @return int Attachment ID.
	 */
	private function upload_with_stored_medium( string $name, int $width = 1200, int $height = 900 ): int {
		$base = fn(): array => [ 'medium' ];
		add_filter( 'yourmark/image-sizes/base', $base );
		$id   = $this->upload( $this->image( $name, 'red', $width, $height ) );
		remove_filter( 'yourmark/image-sizes/base', $base );

		return $id;
	}
}
