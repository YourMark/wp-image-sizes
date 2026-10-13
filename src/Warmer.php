<?php
/**
 * Makes the sizes a page shows for certain before a visitor asks for them.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Fetches pages from the site itself and makes the sizes every visitor gets.
 *
 * Reading the rendered page rather than post content catches every image the
 * page shows: header, footer, widgets, template parts and whatever a plugin or
 * template prints with wp_get_attachment_image(). Only an img's src, preloads
 * and share images are made. The other srcset candidates depend on the
 * visitor's screen and wait for a browser that picks them.
 */
final class Warmer {

	/**
	 * Cron hook that warms the queued pages.
	 */
	private const HOOK = 'yourmark/image-sizes/warm';

	/**
	 * Option holding the URLs waiting to be warmed.
	 */
	private const QUEUE = 'yourmark_image_sizes_warm_queue';

	/**
	 * Post types that change what every page shows rather than a page of their own.
	 */
	private const SITE_WIDE = [ 'wp_template', 'wp_template_part', 'wp_block', 'wp_global_styles' ];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_after_insert_post', [ self::class, 'queue_post' ], 10, 2 );

		// Places an image can sit on every page; warming the front page covers them.
		foreach ( [ 'update_option_sidebars_widgets', 'customize_save_after', 'switch_theme' ] as $hook ) {
			add_action( $hook, [ self::class, 'queue_front_page' ] );
		}

		add_action( self::HOOK, [ self::class, 'run_queue' ] );
	}

	/**
	 * Queue a published post's page, or the front page for a site-wide change.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function queue_post( int $post_id, WP_Post $post ): void {
		if ( $post->post_status !== 'publish' || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( in_array( $post->post_type, self::SITE_WIDE, true ) ) {
			self::queue_front_page();
			return;
		}

		if ( $post->post_type !== 'attachment' && is_post_type_viewable( $post->post_type ) ) {
			self::queue( (string) get_permalink( $post ) );
		}
	}

	/**
	 * Queue the front page.
	 *
	 * @return void
	 */
	public static function queue_front_page(): void {
		self::queue( home_url( '/' ) );
	}

	/**
	 * Warm every queued page.
	 *
	 * @return void
	 */
	public static function run_queue(): void {
		$urls = (array) get_option( self::QUEUE, [] );
		delete_option( self::QUEUE );

		foreach ( $urls as $url ) {
			self::warm( (string) $url );
		}
	}

	/**
	 * Fetch a page and make the sizes it shows for certain that have no file yet.
	 *
	 * @param string $url Page on this site.
	 * @return int Number of sizes made.
	 */
	public static function warm( string $url ): int {
		// Only this site: the admin passes URLs in, and the request comes from the server.
		if ( ! Sizes::enabled() || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return 0;
		}

		$response = wp_remote_get( $url, self::request_args() );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return 0;
		}

		$basedir = wp_get_upload_dir()['basedir'];
		$made    = 0;

		foreach ( self::image_urls( wp_remote_retrieve_body( $response ) ) as $image_url ) {
			$relative = Generator::relative_path( $image_url );

			if ( $relative === null || file_exists( "{$basedir}/{$relative}" ) ) {
				continue;
			}

			if ( is_array( Generator::make( $relative ) ) ) {
				++$made;
			}
		}

		return $made;
	}

	/**
	 * Every page URL in the site's sitemap.
	 *
	 * Starts at core's /wp-sitemap.xml, which SEO plugins redirect to their own index.
	 *
	 * @return string[]
	 */
	public static function sitemap_urls(): array {
		return array_values( array_unique( self::read_sitemap( home_url( '/wp-sitemap.xml' ), 0 ) ) );
	}

	/**
	 * Add a page to the queue and warm it a minute from now.
	 *
	 * The delay lets a burst of saves share one run.
	 *
	 * @param string $url Page on this site.
	 * @return void
	 */
	private static function queue( string $url ): void {
		$queue = (array) get_option( self::QUEUE, [] );

		if ( ! in_array( $url, $queue, true ) ) {
			$queue[] = $url;
			update_option( self::QUEUE, $queue, false );
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Image URLs every visitor of a page loads: img src, image preloads and share images.
	 *
	 * @param string $html Page HTML.
	 * @return string[]
	 */
	private static function image_urls( string $html ): array {
		$tags = new WP_HTML_Tag_Processor( $html );
		$urls = [];

		while ( $tags->next_tag() ) {
			$url = match ( $tags->get_tag() ) {
				'IMG' => $tags->get_attribute( 'src' ),
				'LINK' => $tags->get_attribute( 'rel' ) === 'preload' && $tags->get_attribute( 'as' ) === 'image' ? $tags->get_attribute( 'href' ) : null,
				'META' => in_array( $tags->get_attribute( 'property' ) ?? $tags->get_attribute( 'name' ), [ 'og:image', 'twitter:image' ], true ) ? $tags->get_attribute( 'content' ) : null,
				default => null,
			};

			if ( is_string( $url ) && $url !== '' ) {
				$urls[] = $url;
			}
		}

		return array_unique( $urls );
	}

	/**
	 * Page URLs in a sitemap, following a sitemap index one level down.
	 *
	 * @param string $url   Sitemap or sitemap index.
	 * @param int    $depth Levels of index already followed.
	 * @return string[]
	 */
	private static function read_sitemap( string $url, int $depth ): array {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( wp_remote_retrieve_body( wp_remote_get( $url, self::request_args() ) ) );
		libxml_use_internal_errors( $previous );

		if ( $xml === false ) {
			return [];
		}

		if ( $xml->getName() === 'sitemapindex' ) {
			if ( $depth > 0 ) {
				return [];
			}

			$urls = [];

			foreach ( $xml->sitemap as $sitemap ) {
				array_push( $urls, ...self::read_sitemap( (string) $sitemap->loc, $depth + 1 ) );
			}

			return $urls;
		}

		$urls = [];

		foreach ( $xml->url as $entry ) {
			$urls[] = (string) $entry->loc;
		}

		return $urls;
	}

	/**
	 * Arguments for a request from the site to itself.
	 *
	 * @return array<string, mixed>
	 */
	private static function request_args(): array {
		return [
			'timeout'   => 30,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		];
	}
}
