<?php
/**
 * Answers a request for a size whose file does not exist yet.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Makes and sends a missing size when its request reaches WordPress.
 *
 * The request arrives one of two ways: the web server hands a missing file in
 * uploads to WordPress, or the page pointed at the PHP URL from Urls because
 * the server does not. Either way the file is on disk afterwards.
 */
final class Endpoint {

	/**
	 * Query parameter of the PHP URL, holding the path below the uploads base.
	 */
	public const PARAM = 'yourmark-image';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		// After init, so every size the theme and plugins register is known.
		add_action( 'wp_loaded', [ self::class, 'serve' ] );
	}

	/**
	 * Send the requested size if the request names one, else let WordPress carry on to its 404.
	 *
	 * Only sizes in attachment metadata are sent, or redirected to. Any other
	 * file in uploads that reaches PHP may be one the server deliberately
	 * refuses, such as Gravity Forms' protected uploads.
	 *
	 * @return void
	 */
	public static function serve(): void {
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with the uploads path and looked up in attachment metadata, never output.
		$relative = Generator::relative_path( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		if ( $relative === null ) {
			return;
		}

		if ( $relative === Health::PROBE ) {
			Health::answer_probe();
			return;
		}

		$result = Generator::resolve( $relative );

		if ( $result === null ) {
			return;
		}

		if ( $result['action'] === 'redirect' ) {
			// A stand-in is temporary: the next request should try the real size again.
			if ( ! $result['permanent'] ) {
				nocache_headers();
			}

			wp_safe_redirect( $result['url'], $result['permanent'] ? 301 : 302 );
			exit;
		}

		// The PHP URL stays in a page only until its next render, and an image replaced under the same name changes behind it.
		$max_age = isset( $_GET[ self::PARAM ] ) ? DAY_IN_SECONDS : YEAR_IN_SECONDS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only tells which URL form was used.

		status_header( 200 );
		header( "Content-Type: {$result['mime']}" );
		header( 'Content-Length: ' . (string) filesize( $result['path'] ) );
		header( "Cache-Control: public, max-age={$max_age}" );

		if ( $method === 'GET' ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the image without loading it into memory.
			readfile( $result['path'] );
		}

		exit;
	}
}
