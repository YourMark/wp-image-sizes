<?php
/**
 * WP-CLI commands.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage image sizes that are made on their first request.
 */
final class Command {

	/**
	 * Attachments handled per batch.
	 */
	private const BATCH = 100;

	/**
	 * Test whether this server passes a request for a missing image to WordPress.
	 *
	 * When it does, a new size gets its final URL straight away. When it does
	 * not, a new size is first served through PHP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-sizes check
	 *
	 * @return void
	 */
	public function check(): void {
		if ( Health::check() ) {
			WP_CLI::success( 'Missing images reach WordPress, so new sizes get their final URL straight away.' );
			return;
		}

		WP_CLI::warning( 'Missing images do not reach WordPress, so new sizes are first served through PHP. README.md has the server rule that avoids this.' );
	}

	/**
	 * Make the sizes pages show for certain, before a visitor asks for them.
	 *
	 * Makes each img's src, image preloads and share images. Run it after a
	 * deploy that changes image sizes or templates.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : Pages to warm. Defaults to every page in the sitemap.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-sizes warm
	 *     wp image-sizes warm https://example.com/over/
	 *
	 * @param string[] $args Page URLs.
	 * @return void
	 */
	public function warm( array $args ): void {
		$urls = $args ? $args : Warmer::sitemap_urls();

		if ( ! $urls ) {
			WP_CLI::error( 'No pages to warm: pass URLs, or check that /wp-sitemap.xml answers.' );
		}

		$progress = Utils\make_progress_bar( 'Warming pages', count( $urls ) );
		$made     = 0;

		foreach ( $urls as $url ) {
			$made += Warmer::warm( $url );
			$progress->tick();
		}

		$progress->finish();
		WP_CLI::success( sprintf( 'Made %d sizes across %d pages.', $made, count( $urls ) ) );
	}

	/**
	 * Store uploads from before the format setting in the chosen format.
	 *
	 * The old file stays on disk, so links to it keep working. Sizes follow
	 * lazily in the new format.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-sizes convert
	 *
	 * @return void
	 */
	public function convert(): void {
		$label = Formats::label();

		if ( $label === null ) {
			WP_CLI::error( 'Images keep their uploaded format on this site. Choose WebP or AVIF under Settings > Media.' );
		}

		$progress  = Utils\make_progress_bar( "Converting to {$label}", Library::count() );
		$converted = 0;
		$after     = 0;

		do {
			$ids = Library::image_ids( $after, self::BATCH );

			foreach ( $ids as $id ) {
				$result = Converter::convert( $id );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( "Attachment {$id}: {$result->get_error_message()}" );
				} elseif ( $result ) {
					++$converted;
				}

				$after = $id;
				$progress->tick();
			}

			$full = count( $ids ) === self::BATCH;
		} while ( $full );

		$progress->finish();
		WP_CLI::success( "Converted {$converted} images to {$label}." );
	}

	/**
	 * Delete size files that nothing needs.
	 *
	 * Removes files no attachment refers to any more, such as sizes in a format
	 * switched away from. With --stored, also deletes the sizes made at upload;
	 * each comes back under its old name on its next request.
	 *
	 * Size files another plugin made without registering the size look the same
	 * as orphans. Check a --dry-run first on sites with page builders.
	 *
	 * ## OPTIONS
	 *
	 * [--stored]
	 * : Also delete sizes made at upload.
	 *
	 * [--dry-run]
	 * : List the files instead of deleting them.
	 *
	 * [--yes]
	 * : Skip the page cache question on servers that serve new sizes through PHP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-sizes prune --dry-run
	 *     wp image-sizes prune --stored
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function prune( array $args, array $assoc_args ): void {
		if ( ! Sizes::enabled() ) {
			WP_CLI::error( 'Sizes are made at upload on this site, so every size file is in use.' );
		}

		$stored  = (bool) Utils\get_flag_value( $assoc_args, 'stored', false );
		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		// Without routing, a cached page keeps pointing at a deleted file's own URL, which the server answers with a 404.
		if ( ! $dry_run && ! Health::direct() ) {
			WP_CLI::confirm( 'This server does not pass missing images to WordPress, so cached pages will show broken images until the page cache is cleared. Continue?', $assoc_args );
		}

		$basedir = wp_get_upload_dir()['basedir'];
		$count   = 0;
		$bytes   = 0;
		$after   = 0;

		do {
			$ids = Library::image_ids( $after, self::BATCH );

			foreach ( $ids as $id ) {
				$result = Pruner::prune( $id, $stored, $dry_run );
				$count += count( $result['files'] );
				$bytes += $result['bytes'];
				$after  = $id;

				if ( $dry_run ) {
					foreach ( $result['files'] as $path ) {
						WP_CLI::log( substr( $path, strlen( $basedir ) + 1 ) );
					}
				}
			}

			$full = count( $ids ) === self::BATCH;
		} while ( $full );

		$summary = sprintf( '%d files, %s', $count, size_format( $bytes ) );
		WP_CLI::success( $dry_run ? "Would delete {$summary}." : "Deleted {$summary}." );
	}
}
