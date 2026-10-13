<?php
/**
 * The image section under Settings > Media.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Adds the format choice, the server status and the maintenance tools to Settings > Media.
 */
final class Settings {

	/**
	 * Settings section ID.
	 */
	private const SECTION = 'yourmark-image-sizes';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add_fields' ] );
	}

	/**
	 * Register the option, section and fields.
	 *
	 * @return void
	 */
	public static function add_fields(): void {
		register_setting(
			'media',
			Formats::OPTION,
			[
				'type'              => 'string',
				'default'           => 'webp',
				'sanitize_callback' => [ Formats::class, 'sanitize' ],
			]
		);

		add_settings_section( self::SECTION, __( 'Images', 'yourmark-image-sizes' ), [ self::class, 'section' ], 'media' );
		add_settings_field( Formats::OPTION, __( 'File format', 'yourmark-image-sizes' ), [ self::class, 'format_field' ], 'media', self::SECTION );

		if ( Sizes::enabled() && current_user_can( 'manage_options' ) ) {
			add_settings_field( 'yourmark-image-sizes-tools', __( 'Maintenance', 'yourmark-image-sizes' ), [ Admin::class, 'tools' ], 'media', self::SECTION );
		}
	}

	/**
	 * Say when sizes are made.
	 *
	 * @return void
	 */
	public static function section(): void {
		echo '<p>' . esc_html__( 'Image sizes are made the first time a page, the editor or the media library uses them, not at upload. Sizes nobody uses are never made.', 'yourmark-image-sizes' ) . '</p>';
	}

	/**
	 * Radio buttons for the format, each with what it means.
	 *
	 * @return void
	 */
	public static function format_field(): void {
		$current = Formats::sanitize( get_option( Formats::OPTION, 'webp' ) );
		$choices = [
			'original' => [ __( 'As uploaded', 'yourmark-image-sizes' ), __( 'JPEG and PNG stay JPEG and PNG.', 'yourmark-image-sizes' ) ],
			'webp'     => [ __( 'WebP', 'yourmark-image-sizes' ), __( 'Works in every browser. The safe choice.', 'yourmark-image-sizes' ) ],
			'avif'     => [ __( 'AVIF', 'yourmark-image-sizes' ), __( 'About a quarter smaller than WebP at the same quality, but twice as slow to make.', 'yourmark-image-sizes' ) ],
		];

		echo '<fieldset class="yourmark-image-sizes__formats"><legend class="screen-reader-text">' . esc_html__( 'File format', 'yourmark-image-sizes' ) . '</legend>';

		foreach ( $choices as $format => [ $label, $description ] ) {
			$mime      = Formats::CHOICES[ $format ];
			$supported = $mime === null || Formats::supports( $mime );

			printf(
				'<label class="yourmark-image-sizes__format"><input type="radio" name="%1$s" value="%2$s" aria-describedby="yourmark-image-sizes-format-%2$s"%3$s%4$s> <strong>%5$s</strong> <span id="yourmark-image-sizes-format-%2$s" class="description">%6$s</span></label>',
				esc_attr( Formats::OPTION ),
				esc_attr( $format ),
				checked( $current, $format, false ),
				disabled( $supported, false, false ),
				esc_html( $label ),
				esc_html( $supported ? $description : __( 'Not supported by this server.', 'yourmark-image-sizes' ) )
			);
		}

		echo '<p class="description">' . esc_html__( 'Applies to new uploads and to every size made from now on. Older uploads can be converted below.', 'yourmark-image-sizes' ) . '</p></fieldset>';
	}
}
