<?php
/**
 * Boots WordPress with only this plugin loaded.
 *
 * @package YourMark\ImageSizes
 */

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

// Some tests make the plugin log on purpose; keep that out of the test output.
ini_set( 'error_log', sys_get_temp_dir() . '/yourmark-image-sizes-tests.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test runs only.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

$yourmark_image_sizes_tests = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require $yourmark_image_sizes_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/yourmark-image-sizes.php';
	}
);

require $yourmark_image_sizes_tests . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
