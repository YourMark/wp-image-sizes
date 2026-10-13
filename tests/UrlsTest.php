<?php
/**
 * Tests for the URLs pages print for sizes.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Endpoint;
use YourMark\ImageSizes\Health;
use YourMark\ImageSizes\Urls;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * Pointing missing sizes at PHP on servers that would not pass them on.
 */
final class UrlsTest extends TestCase {

	public function test_on_a_usual_server_every_url_is_the_files_own(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );

		$this->assertStringEndsWith( '/' . $this->size_file( $id, 'medium' ), (string) wp_get_attachment_image_url( $id, 'medium' ) );
	}

	public function test_a_missing_size_points_at_php_until_it_exists(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->routing( false );

		$this->assertStringContainsString( 'index.php?' . Endpoint::PARAM . '=', (string) wp_get_attachment_image_url( $id, 'medium' ) );

		Endpoint::respond( $this->php_uri( $id, $this->size_file( $id, 'medium' ) ) );

		$this->assertStringEndsWith( '/' . $this->size_file( $id, 'medium' ), (string) wp_get_attachment_image_url( $id, 'medium' ) );
	}

	public function test_srcset_and_content_point_missing_sizes_at_php(): void {
		$id = $this->upload( $this->image( 'red.jpg' ) );
		$this->routing( false );

		$html = wp_get_attachment_image( $id, 'large' );

		$this->assertMatchesRegularExpression( '/srcset="[^"]*index\.php\?' . Endpoint::PARAM . '=/', $html, 'The srcset survives a src through PHP.' );

		$tag = '<img src="' . wp_get_upload_dir()['baseurl'] . '/' . $this->relative( $id, $this->size_file( $id, 'large' ) ) . '">';
		$this->assertStringContainsString( 'index.php?' . Endpoint::PARAM . '=', Urls::content_img( $tag ) );
	}

	public function test_a_missing_file_that_is_no_size_is_left_alone(): void {
		$id  = $this->upload( $this->image( 'red.jpg' ) );
		$url = wp_get_upload_dir()['baseurl'] . '/' . $this->relative( $id, 'gone.webp' );
		$this->routing( false );

		$this->assertSame( $url, Urls::url( $url ) );
	}

	public function test_the_server_test_result_reaches_site_health(): void {
		$this->routing( true );
		$this->assertSame( 'good', Health::site_health_result()['status'] );

		$this->routing( false );
		$this->assertSame( 'recommended', Health::site_health_result()['status'] );
	}
}
