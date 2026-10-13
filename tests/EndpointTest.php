<?php
/**
 * Tests for answering requests for sizes.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Converter;
use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Generator;
use YourMark\ImageSizes\Pruner;

/**
 * What a request for a size gets: the file, a redirect, a stand-in or a 404.
 */
final class EndpointTest extends TestCase {

	/**
	 * Lock files held by a test.
	 *
	 * @var resource[]
	 */
	private array $held = [];

	/**
	 * Release locks a test held.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->release_locks();
		parent::tear_down();
	}

	public function test_a_size_is_made_and_served_on_its_first_request(): void {
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$file = $this->size_file( $id, 'medium' );

		$response = Endpoint::respond( $this->uri( $id, $file ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( $this->path( $id, $file ), $response['file'] );
		$this->assertSame( 'image/webp', $response['headers']['Content-Type'] );
		$this->assertSame( 'public, max-age=' . YEAR_IN_SECONDS, $response['headers']['Cache-Control'] );
		$this->assertSame( 'red', $this->colour( $response['file'] ) );
		$this->assertSame( [ $file ], Pruner::made( $id ) );
	}

	public function test_the_php_url_serves_the_size_with_a_short_cache(): void {
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$file = $this->size_file( $id, 'medium' );

		$response = Endpoint::respond( $this->php_uri( $id, $file ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'public, max-age=' . DAY_IN_SECONDS, $response['headers']['Cache-Control'] );
	}

	public function test_a_php_url_for_no_image_is_a_404(): void {
		$response = Endpoint::respond( '/index.php?' . Endpoint::PARAM . '=' . rawurlencode( '2026/10/nothing-300x200.webp' ) );

		$this->assertSame( 404, $response['status'] );
		$this->assertNull( $response['file'] );
	}

	public function test_anything_else_is_left_to_wordpress(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$this->assertNull( Endpoint::respond( $this->uri( $id, 'red-copy.webp' ) ), 'A file that is no size.' );
		$this->assertNull( Endpoint::respond( $this->uri( $id, 'unknown-300x225.webp' ) ), 'A size of no attachment.' );
		$this->assertNull( Endpoint::respond( '/wp-content/uploads/../wp-config-300x200.webp' ), 'A path out of uploads.' );
		$this->assertNull( Endpoint::respond( '/about/?x-' . Endpoint::PARAM . '=1' ), 'A page whose query only looks alike.' );
	}

	public function test_a_name_in_another_format_is_redirected_for_a_day(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$response = Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/red-300x225.webp', $response['headers']['Location'] );
		$this->assertSame( 'public, max-age=' . DAY_IN_SECONDS, $response['headers']['Cache-Control'] );
	}

	public function test_a_redirect_goes_through_php_where_the_server_would_not_pass_it_on(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->routing( false );

		$response = Endpoint::respond( $this->php_uri( $id, 'red-300x225.jpg' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringContainsString( 'index.php?' . Endpoint::PARAM . '=', $response['headers']['Location'] );
	}

	public function test_made_up_dimensions_are_redirected_to_the_nearest_size_without_making_it(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$response = Endpoint::respond( $this->uri( $id, 'red-700x525.webp' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/red-768x576.webp', $response['headers']['Location'] );
		$this->assertFileDoesNotExist( $this->path( $id, 'red-768x576.webp' ) );
	}

	public function test_a_size_from_before_its_registration_changed_is_redirected(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		Endpoint::respond( $this->uri( $id, 'red-1024x768.webp' ) );

		update_option( 'large_size_w', 1100 );
		update_option( 'large_size_h', 1100 );
		Pruner::prune( $id, false, false );

		$response = Endpoint::respond( $this->uri( $id, 'red-1024x768.webp' ) );

		$this->assertFileDoesNotExist( $this->path( $id, 'red-1024x768.webp' ) );
		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/red-1100x825.webp', $response['headers']['Location'] );
	}

	public function test_switching_formats_and_back_never_redirects_a_name_to_itself(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) )['status'] );

		update_option( 'yourmark_image_format', 'webp' );
		$there = Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) );
		$this->assertSame( 301, $there['status'] );
		$this->assertStringEndsWith( '/red--jpg-300x225.webp', $there['headers']['Location'] );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, 'red--jpg-300x225.webp' ) )['status'] );

		update_option( 'yourmark_image_format', 'original' );
		Pruner::prune( $id, false, false );
		$back = Endpoint::respond( $this->uri( $id, 'red--jpg-300x225.webp' ) );
		$this->assertSame( 301, $back['status'] );
		$this->assertStringEndsWith( '/red-300x225.jpg', $back['headers']['Location'] );
		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, 'red-300x225.jpg' ) )['status'], 'The way back ends at the file.' );
	}

	public function test_two_images_sharing_a_name_each_get_their_own_sizes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$jpeg = $this->upload( $this->image( 'photo.jpg', 'red' ) );
		$png  = $this->upload( $this->image( 'photo.png', 'blue' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$jpeg_file = $this->size_file( $jpeg, 'medium' );
		$png_file  = $this->size_file( $png, 'medium' );

		$this->assertNotSame( $jpeg_file, $png_file );
		$this->assertSame( 'red', $this->colour( (string) Endpoint::respond( $this->uri( $jpeg, $jpeg_file ) )['file'] ) );
		$this->assertSame( 'blue', $this->colour( (string) Endpoint::respond( $this->uri( $png, $png_file ) )['file'] ) );
	}

	public function test_an_upload_named_like_a_converted_size_gets_its_own_sizes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$jpeg = $this->upload( $this->image( 'photo.jpg', 'red' ) );
		update_option( 'yourmark_image_format', 'webp' );
		$webp = $this->upload( $this->image( 'photo-jpg.webp', 'blue' ) );

		$jpeg_file = $this->size_file( $jpeg, 'medium' );
		$webp_file = $this->size_file( $webp, 'medium' );

		$this->assertNotSame( $jpeg_file, $webp_file );
		$this->assertSame( 'red', $this->colour( (string) Endpoint::respond( $this->uri( $jpeg, $jpeg_file ) )['file'] ) );
		$this->assertSame( 'blue', $this->colour( (string) Endpoint::respond( $this->uri( $webp, $webp_file ) )['file'] ) );
	}

	public function test_an_old_size_name_finds_its_image_among_lookalikes(): void {
		update_option( 'yourmark_image_format', 'original' );
		$png  = $this->upload( $this->image( 'photo.png', 'blue' ) );
		$jpeg = $this->upload( $this->image( 'photo.jpg', 'red' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$response = Endpoint::respond( $this->uri( $jpeg, 'photo-300x225.jpg' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/' . $this->size_file( $jpeg, 'medium' ), $response['headers']['Location'], 'Not the PNG, although it is older.' );
		$this->assertNotSame( $this->size_file( $png, 'medium' ), $this->size_file( $jpeg, 'medium' ) );
	}

	public function test_an_old_size_name_follows_a_conversion_to_a_numbered_name(): void {
		update_option( 'yourmark_image_format', 'original' );
		$id = $this->upload( $this->image( 'photo.jpg', 'red' ) );
		copy( (string) get_attached_file( $id ), $this->path( $id, 'photo.png' ) );
		update_option( 'yourmark_image_format', 'webp' );

		$this->assertTrue( Converter::convert( $id ) );
		$this->assertSame( 'photo-1.webp', basename( (string) get_attached_file( $id ) ) );

		$response = Endpoint::respond( $this->uri( $id, 'photo-300x225.jpg' ) );

		$this->assertSame( 301, $response['status'] );
		$this->assertStringEndsWith( '/photo-1-300x225.webp', $response['headers']['Location'] );
	}

	public function test_a_size_that_fails_gets_a_stand_in_and_is_remembered_until_asked_again(): void {
		$id     = $this->upload( $this->image( 'red.jpg' ) );
		$source = (string) get_attached_file( $id );
		$large  = $this->size_file( $id, 'large' );
		Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) );

		copy( $source, "{$this->fixtures}/intact.webp" );
		file_put_contents( $source, 'not an image' );

		$response = Endpoint::respond( $this->uri( $id, $large ) );

		$this->assertSame( 302, $response['status'] );
		$this->assertStringEndsWith( '/' . $this->size_file( $id, 'medium' ), $response['headers']['Location'] );
		$this->assertSame( 'no-store, max-age=0', $response['headers']['Cache-Control'] );
		$this->assertFileExists( $this->lock_file( $id, $large ), 'A failure leaves its lock for the next try.' );

		copy( "{$this->fixtures}/intact.webp", $source );

		$this->assertWPError( Generator::make( $this->relative( $id, $large ) ), 'A failure is remembered.' );
		$this->assertIsArray( Generator::make( $this->relative( $id, $large ), true ), 'Asking on purpose tries again.' );
		$this->assertFileExists( $this->path( $id, $large ) );
		$this->assertFileDoesNotExist( $this->lock_file( $id, $large ), 'A success removes its lock.' );
	}

	public function test_a_busy_server_gets_a_stand_in_without_remembering_a_failure(): void {
		$id    = $this->upload( $this->image( 'red.jpg' ) );
		$large = $this->size_file( $id, 'large' );
		Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) );

		add_filter( 'yourmark/image-sizes/wait', '__return_zero' );
		$this->hold( $this->lock_dir() . 'slot-0.lock' );
		$this->hold( $this->lock_dir() . 'slot-1.lock' );

		$this->assertSame( 302, Endpoint::respond( $this->uri( $id, $large ) )['status'] );

		$this->release_locks();

		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, $large ) )['status'] );
	}

	public function test_one_deadline_covers_waiting_for_the_file_and_for_a_slot(): void {
		$id    = $this->upload( $this->image( 'red.jpg' ) );
		$large = $this->size_file( $id, 'large' );

		add_filter( 'yourmark/image-sizes/wait', fn(): int => 1 );
		$this->hold( $this->lock_file( $id, $large ) );
		$this->hold( $this->lock_dir() . 'slot-0.lock' );
		$this->hold( $this->lock_dir() . 'slot-1.lock' );

		$start    = microtime( true );
		$response = Endpoint::respond( $this->uri( $id, $large ) );

		$this->assertSame( 302, $response['status'] );
		$this->assertLessThan( 1.8, microtime( true ) - $start );
	}

	public function test_without_a_usable_lock_directory_sizes_are_still_made_and_it_is_logged(): void {
		$id      = $this->upload( $this->image( 'red.jpg' ) );
		$blocker = "{$this->fixtures}/not-a-directory";
		file_put_contents( $blocker, '' );
		$log = (string) ini_get( 'error_log' );
		file_put_contents( $log, '' );

		// A path below a file can never be created, whoever runs the tests.
		add_filter( 'yourmark/image-sizes/lock-dir', fn(): string => "{$blocker}/locks" );

		$this->assertSame( 200, Endpoint::respond( $this->uri( $id, $this->size_file( $id, 'medium' ) ) )['status'] );
		$this->assertStringContainsString( 'cannot open lock files', (string) file_get_contents( $log ) );
	}

	/**
	 * Lock directory as the plugin uses it.
	 *
	 * @return string
	 */
	private function lock_dir(): string {
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/yourmark-image-sizes' );

		return WP_CONTENT_DIR . '/cache/yourmark-image-sizes/';
	}

	/**
	 * Lock file of one size's file.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Size file name.
	 * @return string
	 */
	private function lock_file( int $attachment_id, string $file ): string {
		return $this->lock_dir() . md5( $this->path( $attachment_id, $file ) ) . '.lock';
	}

	/**
	 * Hold a lock, as another request would.
	 *
	 * @param string $path Lock file.
	 * @return void
	 */
	private function hold( string $path ): void {
		$handle = fopen( $path, 'c' );
		flock( $handle, LOCK_EX );
		$this->held[] = $handle;
	}

	/**
	 * Release the locks held so far.
	 *
	 * @return void
	 */
	private function release_locks(): void {
		foreach ( $this->held as $handle ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}

		$this->held = [];
	}
}
