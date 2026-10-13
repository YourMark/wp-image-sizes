<?php
/**
 * Tests for how the plugin lives alongside core: editing, REST, switching off and on.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Converter;
use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Formats;
use YourMark\ImageSizes\Sizes;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

// Core loads its image editors on first use; the test editor below extends one.
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/**
 * Core flows the plugin must not break.
 */
final class LifecycleTest extends TestCase {

	/**
	 * Load core's image editing functions.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/image-edit.php';
	}

	/**
	 * Clear what the image editor read from the request.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_editing_an_unconverted_jpeg_keeps_the_attachment_working(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'legacy.jpg' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->edit( $id, [ [ 'r' => 90 ] ] );

		$file = (string) get_attached_file( $id );

		$this->assertFileExists( $file );
		$this->assertStringEndsWith( '.jpg', $file );
		$this->assertSame( 'image/jpeg', wp_check_filetype( $file )['type'] );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium_large' ) ) )['status'] );
	}

	public function test_restoring_an_edit_brings_back_lazy_sizes(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->edit( $id, [ [ 'r' => 90 ] ] );

		$_REQUEST = [];
		wp_restore_image( $id );

		$this->assertFileExists( (string) get_attached_file( $id ) );
		$this->assertSame( 'red.webp', basename( (string) get_attached_file( $id ) ) );
		$this->assertTrue( wp_get_attachment_metadata( $id )['sizes']['medium_large'][ Sizes::LAZY ] ?? false );
		$this->assertSame( [], array_filter( (array) wp_get_attachment_metadata( $id, true )['sizes'], fn( $size ): bool => ! empty( $size[ Sizes::LAZY ] ) ) );
	}

	public function test_the_block_editor_does_not_process_uploads_in_the_browser(): void {
		add_filter( 'wp_client_side_media_processing_enabled', '__return_true', 1 );

		$this->assertFalse( wp_is_client_side_media_processing_enabled() );
	}

	public function test_the_rest_api_gives_working_urls_and_no_internal_marker(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->routing( false );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$sizes = rest_do_request( "/wp/v2/media/{$id}" )->get_data()['media_details']['sizes'];

		$this->assertStringContainsString( 'index.php?' . Endpoint::PARAM . '=', $sizes['medium']['source_url'] );
		$this->assertArrayNotHasKey( Sizes::LAZY, $sizes['medium'] );
	}

	public function test_the_rest_api_still_lists_attachments_without_metadata(): void {
		$zip = self::factory()->attachment->create_object(
			[
				'file'           => '2026/10/archive.zip',
				'post_mime_type' => 'application/zip',
			]
		);
		$this->upload( $this->image( 'red.jpg' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 200, rest_do_request( "/wp/v2/media/{$zip}" )->get_status() );
		$this->assertSame( 200, rest_do_request( '/wp/v2/media' )->get_status() );
	}

	public function test_an_edit_of_the_thumbnail_only_or_all_but_it_keeps_every_file_in_place(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'legacy.jpg' ) );
		update_option( 'yourmark_image_format', 'webp' );

		foreach ( [ 'thumbnail', 'nothumb', 'all' ] as $target ) {
			$this->edit( $id, [ [ 'r' => 90 ] ], $target );

			$this->assertFileExists( (string) get_attached_file( $id ), $target );

			foreach ( wp_get_attachment_metadata( $id, true )['sizes'] as $name => $size ) {
				$this->assertFileExists( $this->path( $id, $size['file'] ), "{$target}: {$name}" );
			}
		}
	}

	public function test_switching_the_plugin_off_and_on_again(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) );

		add_filter( 'yourmark/image-sizes/enabled', '__return_false' );
		$missing = array_keys( wp_get_missing_image_subsizes( $id ) );
		sort( $missing );
		$this->assertSame( [ 'large', 'medium', 'medium_large', 'thumbnail' ], $missing, 'Off: core sees every size as missing.' );

		wp_update_image_subsizes( $id );
		$this->assertCount( 4, wp_get_attachment_metadata( $id, true )['sizes'], 'Core makes them, as with --only-missing.' );
		remove_filter( 'yourmark/image-sizes/enabled', '__return_false' );

		foreach ( wp_get_attachment_metadata( $id )['sizes'] as $name => $size ) {
			$this->assertFileExists( $this->path( $id, $size['file'] ), $name );
		}
	}

	public function test_conversion_avoids_a_name_whose_sizes_another_image_has(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.jpg', 'red', 3200, 2400 ) );
		$this->assertSame( 'photo-scaled.jpg', basename( (string) get_attached_file( $id ) ) );
		copy( (string) get_attached_file( $id ), $this->path( $id, 'photo.webp' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertTrue( Converter::convert( $id ) );
		$this->assertSame( 'photo-scaled-1.webp', basename( (string) get_attached_file( $id ) ) );
		$this->assertStringStartsWith( 'photo-scaled-1-', $this->size_file( $id, 'medium' ) );
	}

	public function test_conversion_does_not_count_the_images_own_original_as_a_clash(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.jpg', 'red', 3200, 2400 ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertTrue( Converter::convert( $id ) );
		$this->assertSame( 'photo-scaled.webp', basename( (string) get_attached_file( $id ) ) );
	}

	public function test_a_server_that_cannot_write_webp_keeps_the_uploaded_format(): void {
		add_filter( 'wp_image_editors', fn(): array => [ Without_Webp_Editor::class ] );

		$this->assertNull( Formats::target() );

		$id = $this->upload( $this->image( 'red.jpg' ) );

		$this->assertSame( 'red.jpg', basename( (string) get_attached_file( $id ) ) );
		$this->assertSame( 'red-300x225.jpg', $this->size_file( $id, 'medium' ) );
	}

	/**
	 * Run an edit through core's image editor, as Media > Edit image does.
	 *
	 * @param int                              $attachment_id Attachment ID.
	 * @param array<int, array<string, mixed>> $history       Edit steps, such as [ [ 'r' => 90 ] ].
	 * @param string                           $target        Which files the edit applies to: all, thumbnail or nothumb.
	 * @return void
	 */
	private function edit( int $attachment_id, array $history, string $target = 'all' ): void {
		$_REQUEST = [
			'history' => wp_json_encode( $history ),
			'target'  => $target,
			'do'      => 'save',
			'context' => '',
		];

		$result = wp_save_image( $attachment_id );

		$this->assertTrue( empty( $result->error ), (string) ( $result->error ?? '' ) );
	}
}

/**
 * The Imagick editor, minus WebP and AVIF, as on a host built without them.
 */
final class Without_Webp_Editor extends \WP_Image_Editor_Imagick {

	/**
	 * Refuse WebP and AVIF.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ) {
		return ! in_array( $mime_type, [ 'image/webp', 'image/avif' ], true ) && parent::supports_mime_type( $mime_type );
	}
}
