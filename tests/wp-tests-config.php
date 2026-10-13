<?php
/**
 * WordPress test suite configuration.
 *
 * Runs the site's own core from web/wp against a separate database and a
 * throwaway content directory, so a test run never touches the site. The
 * database settings can be overridden with WP_TESTS_DB_* environment variables.
 *
 * @package YourMark\ImageSizes
 */

// Test code: never run it over HTTP, although it sits in the web root.
PHP_SAPI === 'cli' || exit;

/**
 * An environment variable, or a default when it is not set.
 *
 * @param string $name    Variable name.
 * @param string $fallback Value when unset.
 * @return string
 */
function yourmark_image_sizes_tests_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return $value === false ? $fallback : $value;
}

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/wp/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/yourmark-image-sizes-tests' );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );

define( 'DB_NAME', yourmark_image_sizes_tests_env( 'WP_TESTS_DB_NAME', 'yourmark_image_sizes_tests' ) );
define( 'DB_USER', yourmark_image_sizes_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', yourmark_image_sizes_tests_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', yourmark_image_sizes_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Image sizes tests' );
// Quoted: the suite puts it in a shell command, and Herd's PHP lives under Application Support.
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test suite reads it from here.
