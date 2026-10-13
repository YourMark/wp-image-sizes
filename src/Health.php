<?php
/**
 * Whether a request for a missing image reaches WordPress.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Tests that a request for a missing file in uploads reaches WordPress.
 *
 * Many hosts answer a missing image themselves: nginx in front of Apache, or a
 * static-file location ending in =404. There, Urls points missing sizes at PHP
 * instead. Until the first test passes, that is what happens everywhere.
 */
final class Health {

	/**
	 * File in the uploads root that only this plugin answers.
	 */
	public const PROBE = 'yourmark-image-sizes-probe.webp';

	/**
	 * Option holding the last result: ok and checked, a timestamp.
	 */
	private const OPTION = 'yourmark_image_sizes_routing';

	/**
	 * Transient holding the token the probe must echo.
	 */
	private const TOKEN = 'yourmark_image_sizes_probe_token';

	/**
	 * Cron hook that runs the test.
	 */
	private const HOOK = 'yourmark/image-sizes/check';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function (): void {
				self::check();
			}
		);
		add_action( 'admin_init', [ self::class, 'schedule_check' ] );
		add_filter( 'site_status_tests', [ self::class, 'add_site_health_test' ] );
	}

	/**
	 * Report the result under Tools > Site Health, where server matters belong.
	 *
	 * @param array<string, array<string, mixed>> $tests Site Health tests by type.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_site_health_test( array $tests ): array {
		$tests['direct']['yourmark_image_sizes'] = [
			'label' => __( 'Image sizes', 'yourmark-image-sizes' ),
			'test'  => [ self::class, 'site_health_result' ],
		];

		return $tests;
	}

	/**
	 * Site Health result: good when new sizes get their final URL straight away.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_health_result(): array {
		$direct = self::direct();

		return [
			'label'       => $direct
				? __( 'New image sizes get their final URL straight away', 'yourmark-image-sizes' )
				: __( 'New image sizes are served through PHP the first time', 'yourmark-image-sizes' ),
			'status'      => $direct ? 'good' : 'recommended',
			'badge'       => [
				'label' => __( 'Performance', 'yourmark-image-sizes' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html(
				$direct
					? __( 'The web server passes a request for a missing image to WordPress, which makes the size and leaves it on disk for the server to serve from then on.', 'yourmark-image-sizes' )
					: __( 'The web server answers a request for a missing image itself, so pages point a new size at PHP until it exists. That works on every server, but each new size takes a detour once. The server rule in the image sizes plugin’s README removes the detour.', 'yourmark-image-sizes' )
			) . '</p>',
			'test'        => 'yourmark_image_sizes',
		];
	}

	/**
	 * Whether the server passes a missing file in uploads to WordPress.
	 *
	 * @return bool
	 */
	public static function direct(): bool {
		$routing = get_option( self::OPTION );

		return is_array( $routing ) && ! empty( $routing['ok'] );
	}

	/**
	 * Whether the test has run at all.
	 *
	 * @return bool
	 */
	public static function checked(): bool {
		return is_array( get_option( self::OPTION ) );
	}

	/**
	 * Request the probe from this site and store whether WordPress answered it.
	 *
	 * @return bool
	 */
	public static function check(): bool {
		$token = strtolower( wp_generate_password( 24, false ) );
		set_transient( self::TOKEN, $token, 5 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			add_query_arg( 'token', $token, wp_get_upload_dir()['baseurl'] . '/' . self::PROBE ),
			[
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			]
		);

		$ok = wp_remote_retrieve_header( $response, 'x-yourmark-image-sizes' ) === $token;

		delete_transient( self::TOKEN );
		update_option(
			self::OPTION,
			[
				'ok'      => $ok,
				'checked' => time(),
			],
			true
		);

		return $ok;
	}

	/**
	 * Test again once a day, in the background.
	 *
	 * A host move or a new nginx template can change the answer.
	 *
	 * @return void
	 */
	public static function schedule_check(): void {
		$routing = get_option( self::OPTION );

		if ( is_array( $routing ) && $routing['checked'] > time() - DAY_IN_SECONDS ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Echo the token back, which proves the request reached WordPress.
	 *
	 * @return void
	 */
	public static function answer_probe(): void {
		$token = get_transient( self::TOKEN );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A one-off token from check(), not a form.
		$given = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! is_string( $token ) || ! hash_equals( $token, $given ) ) {
			return;
		}

		status_header( 204 );
		header( "X-YourMark-Image-Sizes: {$token}" );
		exit;
	}
}
