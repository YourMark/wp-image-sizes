<?php
/**
 * Points missing sizes at PHP where the server does not pass them on.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_HTML_Tag_Processor;

/**
 * Rewrites the URL of a size without a file to index.php, which every host runs.
 *
 * Only on servers that fail the Health test. Once the file exists, the next
 * render prints its ordinary URL again, so a page only goes through PHP for
 * sizes nobody has asked for yet.
 */
final class Urls {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_get_attachment_image_src', [ self::class, 'image_src' ] );
		add_filter( 'wp_calculate_image_srcset', [ self::class, 'srcset' ] );
		add_filter( 'wp_content_img_tag', [ self::class, 'content_img' ] );
		add_filter( 'wp_prepare_attachment_for_js', [ self::class, 'media_library' ] );
	}

	/**
	 * The URL to print for a file in uploads.
	 *
	 * @param string $url File URL.
	 * @return string
	 */
	public static function url( string $url ): string {
		if ( Health::direct() || ! Sizes::enabled() ) {
			return $url;
		}

		$relative = Generator::relative_path( $url );

		// Only a size can be made; a missing original stays a plain 404.
		if ( $relative === null || ! Generator::looks_like_size( $relative ) || file_exists( wp_get_upload_dir()['basedir'] . "/{$relative}" ) ) {
			return $url;
		}

		// Slashes left as they are: core only builds a srcset when the src contains the size's path, as in 2026/10/photo-300x200.webp.
		return add_query_arg( Endpoint::PARAM, str_replace( '%2F', '/', rawurlencode( $relative ) ), home_url( '/index.php' ) );
	}

	/**
	 * Covers wp_get_attachment_image() and everything else built on it.
	 *
	 * @param mixed $image Array of URL, width, height and is-intermediate, or false.
	 * @return mixed
	 */
	public static function image_src( $image ) {
		if ( is_array( $image ) && is_string( $image[0] ?? null ) ) {
			$image[0] = self::url( $image[0] );
		}

		return $image;
	}

	/**
	 * Covers every srcset core builds.
	 *
	 * @param mixed $sources Candidates keyed by width, each with a url.
	 * @return mixed
	 */
	public static function srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( is_string( $source['url'] ?? null ) ) {
				$sources[ $width ]['url'] = self::url( $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Covers the src saved in post content, which no attachment function prints.
	 *
	 * @param string $html An img tag.
	 * @return string
	 */
	public static function content_img( string $html ): string {
		$tag = new WP_HTML_Tag_Processor( $html );

		if ( ! $tag->next_tag( [ 'tag_name' => 'img' ] ) ) {
			return $html;
		}

		$src = $tag->get_attribute( 'src' );

		if ( is_string( $src ) ) {
			$tag->set_attribute( 'src', self::url( $src ) );
		}

		return $tag->get_updated_html();
	}

	/**
	 * Covers the media library, which builds its URLs with image_downsize().
	 *
	 * @param array<string, mixed> $response Attachment data for the media modal.
	 * @return array<string, mixed>
	 */
	public static function media_library( array $response ): array {
		foreach ( $response['sizes'] ?? [] as $name => $size ) {
			if ( is_string( $size['url'] ?? null ) ) {
				$response['sizes'][ $name ]['url'] = self::url( $size['url'] );
			}
		}

		return $response;
	}
}
