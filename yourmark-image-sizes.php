<?php
/**
 * Plugin Name: YourMark image sizes
 * Description: Makes each image size the first time something uses it, in the format chosen under Settings > Media.
 *
 * Nothing but the original is written at upload. Every registered size exists
 * in the attachment metadata only, until a request for its file reaches
 * WordPress.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Sizes.php';
require_once __DIR__ . '/src/Formats.php';
require_once __DIR__ . '/src/Generator.php';
require_once __DIR__ . '/src/Endpoint.php';
require_once __DIR__ . '/src/Health.php';
require_once __DIR__ . '/src/Urls.php';
require_once __DIR__ . '/src/Warmer.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Library.php';
require_once __DIR__ . '/src/Pruner.php';
require_once __DIR__ . '/src/Converter.php';
require_once __DIR__ . '/src/Cleanup.php';
require_once __DIR__ . '/src/Admin.php';

add_action(
	'init',
	static function (): void {
		load_muplugin_textdomain( 'yourmark-image-sizes', 'yourmark-image-sizes/languages' );
	}
);

Sizes::register();
Formats::register();
Endpoint::register();
Health::register();
Urls::register();
Warmer::register();
Settings::register();
Cleanup::register();
Admin::register();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Command.php';
	\WP_CLI::add_command( 'image-sizes', Command::class );
}
