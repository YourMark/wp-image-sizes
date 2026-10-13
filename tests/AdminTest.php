<?php
/**
 * Tests for who may use the admin tools.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * Nonces and capabilities on every AJAX action.
 */
final class AdminTest extends TestCase {

	/**
	 * Send JSON through the test suite's AJAX handler instead of exiting.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', fn(): array => [ $this, 'die_handler' ] );
	}

	/**
	 * Stop an AJAX response the way WP_Ajax_UnitTestCase does.
	 *
	 * @param string $message Output, or a status code for wp_die( -1 ).
	 * @return void
	 * @throws WPAjaxDieContinueException With the message, for any response.
	 * @throws WPAjaxDieStopException For a bare wp_die( -1 ), as a failed nonce check gives.
	 */
	public function die_handler( $message ): void {
		if ( $message === '-1' || $message === -1 ) {
			throw new WPAjaxDieStopException( '-1' );
		}

		throw new WPAjaxDieContinueException( (string) $message );
	}

	public function test_every_tool_needs_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST    = [ 'operation' => 'count' ];
		$_REQUEST = $_POST;
		$this->expectException( WPAjaxDieStopException::class );

		do_action( 'wp_ajax_yourmark_image_sizes_batch' );
	}

	public function test_an_editor_cannot_run_the_site_wide_tools(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		foreach ( [ 'pages', 'warm', 'batch' ] as $endpoint ) {
			$this->assertRefused( $this->call( $endpoint, [ 'operation' => 'count' ] ), $endpoint );
		}
	}

	public function test_an_author_cannot_make_sizes_of_someone_elses_image(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertRefused( $this->call( 'image', [ 'id' => $id ] ), 'image' );
	}

	public function test_the_owner_can_make_one_size_by_its_name(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->call(
			'image',
			[
				'id'   => $id,
				'size' => 'medium',
			]
		);

		$this->assertTrue( $response['success'] );
		$this->assertFileExists( $this->path( $id, $this->size_file( $id, 'medium' ) ) );
	}

	/**
	 * Assert that an AJAX response refused the user.
	 *
	 * @param array<string, mixed> $response Decoded JSON.
	 * @param string               $endpoint Action, for the failure message.
	 * @return void
	 */
	private function assertRefused( array $response, string $endpoint ): void {
		$this->assertFalse( $response['success'] ?? null, $endpoint );
		$this->assertSame( 'You are not allowed to do this.', $response['data']['message'] ?? null, $endpoint );
	}

	/**
	 * Run an AJAX action with a valid nonce and return its decoded JSON.
	 *
	 * @param string               $endpoint Action without its prefix.
	 * @param array<string, mixed> $data     POST data.
	 * @return array<string, mixed>
	 */
	private function call( string $endpoint, array $data ): array {
		$_POST    = $data + [ '_ajax_nonce' => wp_create_nonce( 'yourmark-image-sizes' ) ];
		$_REQUEST = $_POST;

		ob_start();

		try {
			do_action( "wp_ajax_yourmark_image_sizes_{$endpoint}" );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return (array) json_decode( (string) ob_get_clean(), true );
	}
}
