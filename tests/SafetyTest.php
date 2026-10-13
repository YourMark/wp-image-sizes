<?php
/**
 * Tests for the guards around animation and loopback requests.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Formats;
use YourMark\ImageSizes\Warmer;

/**
 * Animation detection and what the warmer may fetch.
 */
final class SafetyTest extends TestCase {

	public function test_an_animated_webp_is_told_apart_from_a_still_one(): void {
		$this->assertTrue( Formats::is_animated( $this->animated_webp( 'moving.webp' ), 'image/webp' ) );
		$this->assertFalse( Formats::is_animated( $this->image( 'still.webp' ), 'image/webp' ) );
		$this->assertFalse( Formats::is_animated( $this->image( 'still.png' ), 'image/png' ) );
	}

	public function test_the_format_mapping_leaves_an_animation_alone(): void {
		$moving = $this->animated_webp( 'moving.webp' );
		$still  = $this->image( 'still.jpg' );

		update_option( 'yourmark_image_format', 'webp' );

		$this->assertSame( [], array_intersect_key( Formats::output_format( [], $moving, 'image/webp' ), [ 'image/webp' => 1 ] ) );
		$this->assertSame( 'image/webp', Formats::output_format( [], $still, 'image/jpeg' )['image/jpeg'] );
	}

	public function test_the_warmer_only_fetches_pages_of_this_site(): void {
		$home = home_url( '/' );

		$this->assertTrue( Warmer::is_own_page( $home . 'about/' ) );
		$this->assertFalse( Warmer::is_own_page( str_replace( '://example.org', '://example.org:6379', $home ) ), 'Another port.' );
		$this->assertFalse( Warmer::is_own_page( str_replace( 'http://', 'https://', $home ) ), 'Another scheme.' );
		$this->assertFalse( Warmer::is_own_page( 'http://evil.test@example.org/' ), 'Credentials in the URL.' );
		$this->assertFalse( Warmer::is_own_page( 'http://example.org.evil.test/' ), 'Another host.' );
	}
}
