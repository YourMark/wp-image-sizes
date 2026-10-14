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
 * template prints with wp_get_attachment_image(). Made are each img's src,
 * preloads and share images, and for an image that is not lazy, the srcset
 * candidate each of a few common screens picks. Those are above the fold and
 * decide LCP; a lazy image waits for a browser, and meanwhile the base
 * stands in.
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
	 * Screens whose srcset pick is made ahead: viewport width and device pixel ratio.
	 *
	 * A phone, Lighthouse's phone, a tablet, and a laptop with and without a retina screen.
	 */
	private const SCREENS = [ [ 390, 3.0 ], [ 412, 1.75 ], [ 768, 2.0 ], [ 1440, 1.0 ], [ 1440, 2.0 ] ];

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
		if ( ! Sizes::enabled() || ! self::is_own_page( $url ) ) {
			return 0;
		}

		// A page cache would hand back HTML from before a change in sizes; a query string makes the usual ones pass.
		$response = wp_safe_remote_get( add_query_arg( 'yourmark-warm', '1', $url ), self::request_args( 0 ) );

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
		return array_values( array_unique( array_filter( self::read_sitemap( home_url( '/wp-sitemap.xml' ), 0 ), [ self::class, 'is_own_page' ] ) ) );
	}

	/**
	 * Whether a URL is a page on this site: same scheme, host and port as home.
	 *
	 * The admin passes URLs in and the request comes from the server, so nothing
	 * else may get through.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_own_page( string $url ): bool {
		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $home ) || ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return ( $parts['scheme'] ?? '' ) === ( $home['scheme'] ?? '' )
			&& strtolower( $parts['host'] ?? '' ) === strtolower( $home['host'] ?? '' )
			&& ( $parts['port'] ?? null ) === ( $home['port'] ?? null );
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
	 * Image URLs a page's visitors load first: img src, image preloads, share images and the picks of eager images.
	 *
	 * @param string $html Page HTML.
	 * @return string[]
	 */
	private static function image_urls( string $html ): array {
		$tags = new WP_HTML_Tag_Processor( $html );
		$urls = [];

		while ( $tags->next_tag() ) {
			if ( $tags->get_tag() === 'IMG' && $tags->get_attribute( 'loading' ) !== 'lazy' ) {
				array_push( $urls, ...self::picks( (string) $tags->get_attribute( 'srcset' ), (string) $tags->get_attribute( 'sizes' ) ) );
			}

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
	 * The srcset candidates the common screens pick for an image.
	 *
	 * Worked out as the browser does, from sizes and the widths in srcset, so
	 * only what a visitor would load is made.
	 *
	 * @param string $srcset Srcset attribute.
	 * @param string $sizes  Sizes attribute.
	 * @return string[] URLs.
	 */
	public static function picks( string $srcset, string $sizes ): array {
		$candidates = Srcset::candidates( $srcset );
		$picks      = [];

		if ( ! $candidates || $sizes === '' ) {
			return [];
		}

		foreach ( self::SCREENS as [ $viewport, $density ] ) {
			$slot = Srcset::slot_at( $sizes, $viewport );
			$pick = $slot === null ? null : Srcset::pick( $candidates, $slot, $density );

			if ( $pick !== null ) {
				$picks[] = $pick;
			}
		}

		return array_values( array_unique( $picks ) );
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
		$xml      = simplexml_load_string( wp_remote_retrieve_body( wp_safe_remote_get( $url, self::request_args( 3 ) ) ) );
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
				$child = (string) $sitemap->loc;

				// The index is read from this site, but what it lists is fetched by the server too.
				if ( self::is_own_page( $child ) ) {
					array_push( $urls, ...self::read_sitemap( $child, $depth + 1 ) );
				}
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
	 * @param int $redirects Redirects to follow. Pages need none; the sitemap is redirected by SEO plugins.
	 * @return array<string, mixed>
	 */
	private static function request_args( int $redirects ): array {
		return [
			'timeout'     => 30,
			'redirection' => $redirects,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		];
	}
}
