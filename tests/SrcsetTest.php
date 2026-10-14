<?php
/**
 * Tests for trimming srcset to what a placement can use.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes\Tests;

use YourMark\ImageSizes\Srcset;
use YourMark\ImageSizes\Warmer;

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * Slots and srcset trimming.
 */
final class SrcsetTest extends TestCase {

	/**
	 * Drop the layout a test gave the theme.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		parent::tear_down();
		wp_clean_theme_json_cache();
	}

	public function test_sizes_gives_the_widest_slot(): void {
		$this->assertSame( 1344.0, Srcset::sizes_slot( '(max-width: 1344px) 100vw, 1344px' ) );
		$this->assertSame( 496.0, Srcset::sizes_slot( '(min-width: 48.875rem) 31rem, 22rem' ) );
		$this->assertSame( 480.0, Srcset::sizes_slot( 'auto, (max-width: 480px) 100vw, 480px' ) );
		$this->assertNull( Srcset::sizes_slot( '100vw' ), 'A whole viewport has no upper bound.' );
		$this->assertNull( Srcset::sizes_slot( '(min-width: 600px) calc(50vw - 2rem), 100vw' ) );
	}

	public function test_sizes_is_read_for_a_given_screen_as_a_browser_would(): void {
		$portrait = '(min-width: 48.875rem) 31rem, 22rem';

		$this->assertSame( 352.0, Srcset::slot_at( $portrait, 390 ) );
		$this->assertSame( 496.0, Srcset::slot_at( $portrait, 1440 ) );
		$this->assertSame( 390.0, Srcset::slot_at( '(max-width: 1344px) 100vw, 1344px', 390 ) );
		$this->assertSame( 1344.0, Srcset::slot_at( '(max-width: 1344px) 100vw, 1344px', 1440 ) );
		$this->assertSame( 640.0, Srcset::slot_at( '(min-width: 600px) and (max-width: 1000px) 640px, 300px', 768 ) );
		$this->assertNull( Srcset::slot_at( 'auto, 300px', 390 ) );
		$this->assertNull( Srcset::slot_at( 'calc(100vw - 2rem)', 390 ) );
	}

	public function test_warming_makes_the_pick_of_each_common_screen_and_no_more(): void {
		$srcset = 'p-263.webp 263w, p-400.webp 400w, p-520.webp 520w, p-640.webp 640w, p-768.webp 768w, p-896.webp 896w, p-1200.webp 1200w';
		$picks  = Warmer::picks( $srcset, '(min-width: 48.875rem) 31rem, 22rem' );

		sort( $picks );

		// Phone 352x3 = 1056, Lighthouse 352x1.75 = 616, tablet 352x2 = 704, laptop 496 and 496x2 = 992.
		$this->assertSame( [ 'p-1200.webp', 'p-520.webp', 'p-640.webp', 'p-768.webp' ], $picks );
		$this->assertSame( [], Warmer::picks( $srcset, 'auto, 300px' ) );
	}

	public function test_candidates_past_twice_the_slot_are_cut_but_one_wide_enough_stays(): void {
		$srcset = 'a.webp 1344w, b.webp 300w, c.webp 768w, d.webp 1024w, e.webp 400w, f.webp 560w, g.webp 1280w';

		$this->assertSame( 'b.webp 300w, e.webp 400w, f.webp 560w, c.webp 768w', Srcset::trim( $srcset, '(max-width: 335px) 100vw, 335px' ) );
		$this->assertSame( $srcset, Srcset::trim( $srcset, '100vw' ) );
		$this->assertSame( 'x.webp 1x, y.webp 2x', Srcset::trim( 'x.webp 1x, y.webp 2x', '300px' ), 'Density descriptors are left alone.' );
	}

	public function test_a_slot_follows_content_size_alignment_and_columns(): void {
		$this->layout(
			[
				'contentSize' => '60rem',
				'wideSize'    => '80rem',
			]
		);

		$image       = [
			'blockName' => 'core/image',
			'attrs'     => [],
		];
		$constrained = [ 'layout' => [ 'type' => 'constrained' ] ];
		$full        = [
			'blockName' => 'core/group',
			'attrs'     => [ 'align' => 'full' ] + $constrained,
		];
		$column      = [
			'blockName' => 'core/column',
			'attrs'     => [],
		];
		$quarter     = [
			'blockName' => 'core/column',
			'attrs'     => [ 'width' => '25%' ],
		];
		$columns     = [
			'blockName'   => 'core/columns',
			'attrs'       => [ 'align' => 'wide' ],
			'innerBlocks' => [ $column, $column ],
		];

		$this->assertSame( 960.0, $this->chain( $image ) );
		$this->assertSame(
			1280.0,
			$this->chain(
				[
					'blockName' => 'core/image',
					'attrs'     => [ 'align' => 'wide' ],
				]
			)
		);
		$this->assertNull(
			$this->chain(
				$full,
				[
					'blockName' => 'core/image',
					'attrs'     => [ 'align' => 'full' ],
				]
			)
		);
		$this->assertSame( 960.0, $this->chain( $full, $image ), 'Content in a full-width group keeps to the content size.' );
		$this->assertSame( 640.0, $this->chain( $full, $columns, $column, $image ) );
		$this->assertSame( 320.0, $this->chain( $full, $columns, $quarter, $image ) );
	}

	public function test_an_image_in_a_column_gets_a_short_srcset(): void {
		$this->layout( [ 'contentSize' => '60rem' ] );

		$id     = $this->upload( $this->image( 'red.jpg', 'red', 2400, 1800 ) );
		$url    = (string) wp_get_attachment_url( $id );
		$figure = "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"full\"} --><figure class=\"wp-block-image size-full\"><img src=\"{$url}\" alt=\"\" class=\"wp-image-{$id}\"/></figure><!-- /wp:image -->";
		$html   = wp_filter_content_tags( do_blocks( "<!-- wp:columns --><div class=\"wp-block-columns\"><!-- wp:column --><div class=\"wp-block-column\">{$figure}</div><!-- /wp:column --><!-- wp:column --><div class=\"wp-block-column\"></div><!-- /wp:column --></div><!-- /wp:columns -->" ) );

		$this->assertStringContainsString( 'sizes="auto, (max-width: 480px) 100vw, 480px"', $html );
		preg_match( '/srcset="([^"]+)"/', $html, $srcset );
		$this->assertSame( [ 300, 400, 520, 640, 768, 1024 ], array_map( 'intval', (array) preg_replace( '/.* (\d+)w$/', '$1', explode( ', ', $srcset[1] ?? '' ) ) ) );
	}

	public function test_a_sizes_the_theme_set_is_kept(): void {
		$id   = $this->upload( $this->image( 'red.jpg' ) );
		$url  = (string) wp_get_attachment_url( $id );
		$html = do_blocks( "<!-- wp:image {\"id\":{$id}} --><figure class=\"wp-block-image\"><img src=\"{$url}\" sizes=\"22rem\" alt=\"\"/></figure><!-- /wp:image -->" );

		$this->assertStringContainsString( 'sizes="22rem"', $html );
	}

	/**
	 * Give the theme a layout.
	 *
	 * @param array<string, string> $layout Content and wide size.
	 * @return void
	 */
	private function layout( array $layout ): void {
		add_filter(
			'wp_theme_json_data_theme',
			fn( $data ) => $data->update_with(
				[
					'version'  => 3,
					'settings' => [ 'layout' => $layout ],
				]
			)
		);
		wp_clean_theme_json_cache();
	}

	/**
	 * The slot of the last of some nested blocks, outermost first.
	 *
	 * @param array<string, mixed> ...$blocks Parsed blocks.
	 * @return float|null
	 */
	private function chain( array ...$blocks ): ?float {
		$slot         = null;
		$parent_block = null;

		foreach ( $blocks as $block ) {
			$slot         = Srcset::slot( $block, $slot, $parent_block );
			$parent_block = $block;
		}

		return $slot;
	}
}
