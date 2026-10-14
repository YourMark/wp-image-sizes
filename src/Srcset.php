<?php
/**
 * Keeps each srcset to the widths its placement can use.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_Block;
use WP_HTML_Tag_Processor;

/**
 * Works out how wide an image block can be painted, and drops candidates far beyond it.
 *
 * Core's sizes only knows the file: a full-size image in a column gets
 * "(max-width: 1344px) 100vw, 1344px". The block tree knows better, from the
 * theme's content and wide sizes, the block's alignment and its columns.
 * Every candidate past twice the widest slot is cut, as large sites cap
 * density at 2x.
 */
final class Srcset {

	/**
	 * Pixels per rem and em, the browser default.
	 */
	private const FONT_SIZE = 16;

	/**
	 * Attribute a block carries while it renders: how wide it can be, 0 when it may span the viewport.
	 */
	public const SLOT = 'yourmarkSlot';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'render_block_data', [ self::class, 'stamp' ], PHP_INT_MAX, 3 );
		// After the theme, which may know the slot better and set sizes itself.
		add_filter( 'render_block_core/image', [ self::class, 'image_block' ], 20, 2 );
		add_filter( 'wp_content_img_tag', [ self::class, 'content_img' ], 20 );
		add_filter( 'wp_get_attachment_image_attributes', [ self::class, 'attributes' ], 20 );
	}

	/**
	 * Give a block about to render the width it can take, worked out from its parent's.
	 *
	 * Passed down through the parsed blocks rather than kept on a stack: core
	 * fires render_block twice for a synced pattern, and a synced pattern's
	 * content still renders with the pattern as its parent.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $source Unfiltered parsed block.
	 * @param WP_Block|null        $parent_block Parent block, null at the top.
	 * @return array<string, mixed>
	 */
	public static function stamp( array $block, array $source = [], ?WP_Block $parent_block = null ): array {
		if ( empty( $block['blockName'] ) ) {
			return $block;
		}

		$outer = $parent_block?->parsed_block['attrs'][ self::SLOT ] ?? null;

		$block['attrs'][ self::SLOT ] = self::slot( $block, is_numeric( $outer ) && $outer > 0 ? (float) $outer : null, $parent_block?->parsed_block ) ?? 0;

		return $block;
	}

	/**
	 * Give an image block the sizes its place in the layout allows, unless it has one.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public static function image_block( string $html, array $block ): string {
		$tags = new WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( [ 'tag_name' => 'img' ] ) || $tags->get_attribute( 'sizes' ) !== null ) {
			return $html;
		}

		$slot = (float) ( $block['attrs'][ self::SLOT ] ?? 0 );
		$set  = self::pixels( (string) ( $block['attrs']['width'] ?? '' ) );
		$file = self::file_width( $block, $tags );

		// A width set in the editor is exact; the layout is only an upper bound.
		if ( $set !== null ) {
			$slot = $slot > 0 ? min( $slot, $set ) : $set;
		}

		if ( $slot <= 0 || $file === null ) {
			return $html;
		}

		$width = (int) ceil( min( $slot, $file ) );

		// Core's own form, with the slot in place of the file width; core fills in srcset and leaves it alone.
		$tags->set_attribute( 'sizes', "(max-width: {$width}px) 100vw, {$width}px" );

		return $tags->get_updated_html();
	}

	/**
	 * Trim the srcset of an image in content.
	 *
	 * @param string $tag Img tag.
	 * @return string
	 */
	public static function content_img( string $tag ): string {
		$tags = new WP_HTML_Tag_Processor( $tag );

		if ( ! $tags->next_tag( [ 'tag_name' => 'img' ] ) ) {
			return $tag;
		}

		$srcset = $tags->get_attribute( 'srcset' );
		$sizes  = $tags->get_attribute( 'sizes' );

		if ( ! is_string( $srcset ) || ! is_string( $sizes ) ) {
			return $tag;
		}

		$tags->set_attribute( 'srcset', self::trim( $srcset, $sizes ) );

		return $tags->get_updated_html();
	}

	/**
	 * Trim the srcset of an image a template prints.
	 *
	 * @param mixed $attributes Img attributes.
	 * @return mixed
	 */
	public static function attributes( $attributes ) {
		if ( is_array( $attributes ) && is_string( $attributes['srcset'] ?? null ) && is_string( $attributes['sizes'] ?? null ) ) {
			$attributes['srcset'] = self::trim( $attributes['srcset'], $attributes['sizes'] );
		}

		return $attributes;
	}

	/**
	 * A srcset without the candidates past twice the widest slot in sizes, smallest first.
	 *
	 * Keeps the first candidate at or beyond that, so a 2x screen always finds
	 * one wide enough. Left as it is when sizes has no upper bound, as with a
	 * plain 100vw, or uses anything this cannot read, such as calc().
	 *
	 * @param string $srcset Srcset with width descriptors.
	 * @param string $sizes  Sizes attribute.
	 * @return string
	 */
	public static function trim( string $srcset, string $sizes ): string {
		$slot = self::sizes_slot( $sizes );

		if ( $slot === null ) {
			return $srcset;
		}

		$candidates = [];

		foreach ( explode( ',', $srcset ) as $candidate ) {
			if ( ! preg_match( '/^(\S+)\s+(\d+)w$/', trim( $candidate ), $match ) ) {
				return $srcset;
			}

			$candidates[ (int) $match[2] ] = $match[1];
		}

		ksort( $candidates );

		$kept = [];

		foreach ( $candidates as $width => $url ) {
			$kept[] = "{$url} {$width}w";

			if ( $width >= 2 * $slot ) {
				break;
			}
		}

		return implode( ', ', $kept );
	}

	/**
	 * The widest an image is painted according to its sizes, in CSS pixels.
	 *
	 * @param string $sizes Sizes attribute.
	 * @return float|null Null when unbounded or unreadable.
	 */
	public static function sizes_slot( string $sizes ): ?float {
		$widest = 0.0;

		foreach ( explode( ',', $sizes ) as $entry ) {
			$entry = trim( $entry );

			if ( $entry === '' || strtolower( $entry ) === 'auto' ) {
				continue;
			}

			if ( ! preg_match( '/^(?:(\(.*\))\s+)?([\d.]+)(px|r?em|vw)$/i', $entry, $match ) ) {
				return null;
			}

			[ , $media, $number, $unit ] = $match;

			if ( strtolower( $unit ) === 'vw' ) {
				// A share of the viewport is only bounded under a max-width.
				if ( ! preg_match( '/max-width:\s*([\d.]+)(px|r?em)/i', $media, $bound ) ) {
					return null;
				}

				$width = (float) self::pixels( $bound[1] . $bound[2] ) * (float) $number / 100;
			} else {
				$width = (float) self::pixels( $number . $unit );
			}

			$widest = max( $widest, $width );
		}

		return $widest > 0 ? $widest : null;
	}

	/**
	 * How wide a block can be, from the width of its parent.
	 *
	 * Follows the theme's content and wide sizes, alignment and columns. A
	 * layout the blocks do not describe, such as a grid made in the theme's
	 * CSS, stays invisible; the theme can set sizes itself there.
	 *
	 * @param array<string, mixed>      $block  Parsed block.
	 * @param float|null                $outer  Width of the parent, null when it may span the viewport; ignored at the top.
	 * @param array<string, mixed>|null $parent_block Parsed parent block, null at the top.
	 * @return float|null Null when it may span the viewport.
	 */
	public static function slot( array $block, ?float $outer, ?array $parent_block ): ?float {
		$layout  = wp_get_global_settings( [ 'layout' ] );
		$content = self::pixels( (string) ( $layout['contentSize'] ?? '' ) );
		$wide    = self::pixels( (string) ( $layout['wideSize'] ?? '' ) ) ?? $content;
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$align   = $attrs['align'] ?? '';

		// At the top, content is laid out as a constrained group would.
		$parent_type = $parent_block === null ? 'constrained' : (string) ( $parent_block['attrs']['layout']['type'] ?? '' );
		$outer       = $parent_block === null ? null : $outer;

		if ( $align === 'full' ) {
			return $parent_block === null ? null : $outer;
		}

		if ( $parent_type === 'constrained' ) {
			$limit = $align === 'wide' ? $wide : ( self::pixels( (string) ( $parent_block['attrs']['layout']['contentSize'] ?? '' ) ) ?? $content );

			return self::narrower( $outer, $limit );
		}

		if ( ( $block['blockName'] ?? '' ) === 'core/column' && ( $parent_block['blockName'] ?? '' ) === 'core/columns' ) {
			$siblings = array_filter( (array) ( $parent_block['innerBlocks'] ?? [] ), fn( $child ): bool => is_array( $child ) && ( $child['blockName'] ?? '' ) === 'core/column' );

			return self::column_width( (string) ( $attrs['width'] ?? '' ), $outer, max( 1, count( $siblings ) ) );
		}

		return $outer;
	}

	/**
	 * The narrower of two widths, where null is unbounded.
	 *
	 * @param float|null $a Width.
	 * @param float|null $b Width.
	 * @return float|null
	 */
	private static function narrower( ?float $a, ?float $b ): ?float {
		if ( $a === null || $b === null ) {
			return $a ?? $b;
		}

		return min( $a, $b );
	}

	/**
	 * A column's width: its own setting, else an equal share of its row.
	 *
	 * @param string     $setting Column width attribute, such as 33.33% or 240px.
	 * @param float|null $row     Width of the columns block, null when unknown.
	 * @param int        $columns Columns in the row.
	 * @return float|null
	 */
	private static function column_width( string $setting, ?float $row, int $columns ): ?float {
		if ( str_ends_with( $setting, '%' ) ) {
			return $row === null ? null : $row * (float) $setting / 100;
		}

		return self::pixels( $setting ) ?? ( $row === null ? null : $row / $columns );
	}

	/**
	 * Width of the file an image block shows.
	 *
	 * @param array<string, mixed>  $block Parsed image block.
	 * @param WP_HTML_Tag_Processor $tags  Processor at the img.
	 * @return int|null
	 */
	private static function file_width( array $block, WP_HTML_Tag_Processor $tags ): ?int {
		$width = (int) $tags->get_attribute( 'width' );

		if ( $width > 0 ) {
			return $width;
		}

		$id    = (int) ( $block['attrs']['id'] ?? 0 );
		$image = $id > 0 ? wp_get_attachment_image_src( $id, (string) ( $block['attrs']['sizeSlug'] ?? 'full' ) ) : false;

		return is_array( $image ) && $image[1] > 0 ? (int) $image[1] : null;
	}

	/**
	 * A CSS length in pixels: px, rem or em, or a bare number as the block editor stores some widths.
	 *
	 * @param string $length Length.
	 * @return float|null Null for anything else, such as a percentage or calc().
	 */
	private static function pixels( string $length ): ?float {
		if ( ! preg_match( '/^([\d.]+)(px|r?em)?$/i', trim( $length ), $match ) || (float) $match[1] <= 0 ) {
			return null;
		}

		$unit = strtolower( $match[2] ?? 'px' );

		return (float) $match[1] * ( $unit === 'px' || $unit === '' ? 1 : self::FONT_SIZE );
	}
}
