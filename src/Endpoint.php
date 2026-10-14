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
 *
 * @phpstan-import-type Size from Generator
 * @phpstan-type Response array{status: int, headers: array<string, string>, file: string|null, then?: Size}
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
	 * Send the response for a request that names a size, else let WordPress carry on.
	 *
	 * @return void
	 */
	public static function serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';

		if ( ! in_array( $method, [ 'get', 'head' ], true ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with the uploads path and looked up in attachment metadata, never output.
		$uri      = wp_unslash( $_SERVER['REQUEST_URI'] );
		$relative = Generator::relative_path( $uri );

		if ( $relative === Health::PROBE ) {
			Health::answer_probe();
			return;
		}

		$response = self::respond( $uri, $relative );

		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );

		foreach ( $response['headers'] as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		if ( $response['file'] !== null && $method === 'get' ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the image without loading it into memory.
			readfile( $response['file'] );
		}

		if ( isset( $response['then'] ) ) {
			self::finish_response();
			Generator::complete( $response['then'] );
		}

		exit;
	}

	/**
	 * The response to a request URI, or null when it is not one for this plugin.
	 *
	 * Only sizes in attachment metadata are sent, or redirected to. Any other
	 * file in uploads that reaches PHP may be one the server deliberately
	 * refuses, such as Gravity Forms' protected uploads, so it is left to
	 * WordPress. A request through the PHP URL is always this plugin's, and gets
	 * a 404 rather than whatever page WordPress would render there.
	 *
	 * @param string      $uri      Request URI, path and query.
	 * @param string|null $relative Its path below the uploads base, when the caller already worked it out.
	 * @return array|null
	 * @phpstan-return Response|null
	 */
	public static function respond( string $uri, ?string $relative = null ): ?array {
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		$php_url  = isset( $query[ self::PARAM ] );
		$relative = $relative ?? Generator::relative_path( $uri );
		$result   = $relative === null ? null : Generator::resolve( $relative, Generator::defers() );

		if ( $result === null ) {
			return $php_url ? self::not_found() : null;
		}

		if ( $result['action'] === 'redirect' ) {
			/*
			 * A permanent redirect still expires: a browser keeps a 301 without
			 * one forever, and switching WebP to AVIF and back would then loop.
			 * A stand-in is not kept at all, so the next request tries the real
			 * size again.
			 */
			return [
				'status'  => $result['permanent'] ? 301 : 302,
				'headers' => [
					// Through the PHP URL while the target has no file and the server would not pass it on.
					'Location'      => wp_sanitize_redirect( Urls::url( $result['url'] ) ),
					'Cache-Control' => $result['permanent'] ? 'public, max-age=' . DAY_IN_SECONDS : 'no-store, max-age=0',
				],
				'file'    => null,
			];
		}

		if ( $result['action'] === 'stand-in' ) {
			// Not kept anywhere, Cloudflare included: the next request gets the real size.
			return [
				'status'  => 200,
				'headers' => [
					'Content-Type'   => $result['mime'],
					'Content-Length' => (string) filesize( $result['path'] ),
					'Cache-Control'  => 'no-store, max-age=0',
				],
				'file'    => $result['path'],
				'then'    => $result['size'],
			];
		}

		// The PHP URL stays in a page only until its next render, and an image replaced under the same name changes behind it.
		$max_age = $php_url ? DAY_IN_SECONDS : YEAR_IN_SECONDS;

		return [
			'status'  => 200,
			'headers' => [
				'Content-Type'   => $result['mime'],
				'Content-Length' => (string) filesize( $result['path'] ),
				'Cache-Control'  => "public, max-age={$max_age}",
			],
			'file'    => $result['path'],
		];
	}

	/**
	 * Send the response to the visitor and keep running.
	 *
	 * @return void
	 */
	private static function finish_response(): void {
		ignore_user_abort( true );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * A 404 for a PHP URL that names no size.
	 *
	 * @return array
	 * @phpstan-return Response
	 */
	private static function not_found(): array {
		return [
			'status'  => 404,
			'headers' => [
				'Content-Type'  => 'text/plain; charset=utf-8',
				'Cache-Control' => 'no-store, max-age=0',
			],
			'file'    => null,
		];
	}
}
