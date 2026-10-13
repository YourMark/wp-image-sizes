<?php
/**
 * The maintenance tools and the sizes panel on a single image.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

use WP_Post;

/**
 * Runs the WP-CLI tools from the admin, in small AJAX batches so no request times out.
 */
final class Admin {

	/**
	 * Nonce action shared by every request.
	 */
	private const NONCE = 'yourmark-image-sizes';

	/**
	 * Attachments handled per batch request.
	 */
	private const BATCH = 25;

	/**
	 * Pages warmed per request.
	 */
	private const PAGES = 3;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'media_row_actions', [ self::class, 'row_action' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ self::class, 'attachment_field' ], 10, 2 );

		foreach ( [ 'pages', 'warm', 'batch', 'image' ] as $endpoint ) {
			add_action( "wp_ajax_yourmark_image_sizes_{$endpoint}", [ self::class, "ajax_{$endpoint}" ] );
		}
	}

	/**
	 * Load the script and styles where the tools or the panel can appear.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Sizes::enabled() || ! in_array( $hook, [ 'options-media.php', 'upload.php', 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$plugin = dirname( __DIR__ ) . '/yourmark-image-sizes.php';
		$assets = dirname( __DIR__ ) . '/assets';

		wp_enqueue_style( 'yourmark-image-sizes', plugins_url( 'assets/admin.css', $plugin ), [], (string) filemtime( "{$assets}/admin.css" ) );
		wp_enqueue_script( 'yourmark-image-sizes', plugins_url( 'assets/admin.js', $plugin ), [], (string) filemtime( "{$assets}/admin.js" ), true );
		wp_add_inline_script(
			'yourmark-image-sizes',
			'window.yourmarkImageSizes = ' . wp_json_encode(
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'format'  => Formats::label(),
					'i18n'    => self::strings(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * The maintenance tools for Settings > Media, one card each.
	 *
	 * @return void
	 */
	public static function tools(): void {
		$label = Formats::label();
		$tools = [];

		if ( $label !== null ) {
			$tools['convert'] = [
				/* translators: %s: WebP or AVIF */
				sprintf( __( 'Convert to %s', 'yourmark-image-sizes' ), $label ),
				__( 'Stores uploads from before this setting in the chosen format. The old file stays, so existing links keep working.', 'yourmark-image-sizes' ),
				__( 'Convert originals', 'yourmark-image-sizes' ),
			];
		}

		$tools['warm'] = [
			__( 'Warm pages', 'yourmark-image-sizes' ),
			__( 'Makes the images every page in the sitemap shows, so no visitor waits for one.', 'yourmark-image-sizes' ),
			__( 'Warm all pages', 'yourmark-image-sizes' ),
		];

		$tools['prune'] = [
			__( 'Clean up sizes', 'yourmark-image-sizes' ),
			__( 'Deletes the sizes WordPress made at upload and the files of sizes that no longer exist. Sizes pages use come back on their next request.', 'yourmark-image-sizes' ),
			__( 'Count size files', 'yourmark-image-sizes' ),
		];

		echo '<ul class="yourmark-image-sizes__tools" data-yourmark-image-sizes-group>';

		foreach ( $tools as $task => [ $title, $description, $button ] ) {
			printf(
				'<li class="yourmark-image-sizes__tool yourmark-image-sizes__tool--%1$s" data-yourmark-image-sizes-tool>
					<h3 class="yourmark-image-sizes__title">%2$s</h3>
					<p class="yourmark-image-sizes__description" id="yourmark-image-sizes-%1$s">%3$s</p>
					<progress class="yourmark-image-sizes__progress" max="100" value="0" hidden></progress>
					<p class="yourmark-image-sizes__status" role="status"></p>
					<p class="yourmark-image-sizes__actions"><button type="button" class="button" data-yourmark-image-sizes="%1$s" aria-describedby="yourmark-image-sizes-%1$s">%4$s</button></p>
				</li>',
				esc_attr( $task ),
				esc_html( $title ),
				esc_html( $description ),
				esc_html( $button )
			);
		}

		echo '</ul>';
	}

	/**
	 * Link in the media library's list view, while an image has work left.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Attachment.
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, WP_Post $post ): array {
		if ( ! self::can_edit( $post->ID ) ) {
			return $actions;
		}

		$label = self::action_label( $post->ID );

		if ( $label !== null ) {
			$actions['yourmark_image_sizes'] = sprintf(
				'<span class="yourmark-image-sizes yourmark-image-sizes--row" data-yourmark-image-sizes-tool><button type="button" class="button-link" data-yourmark-image-sizes="image" data-id="%1$d">%2$s</button> <span class="yourmark-image-sizes__status" role="status"></span></span>',
				$post->ID,
				esc_html( $label )
			);
		}

		return $actions;
	}

	/**
	 * Sizes panel in the media modal and on the attachment's edit screen.
	 *
	 * @param array<string, mixed> $fields Attachment fields.
	 * @param WP_Post              $post   Attachment.
	 * @return array<string, mixed>
	 */
	public static function attachment_field( array $fields, WP_Post $post ): array {
		if ( ! self::can_edit( $post->ID ) ) {
			return $fields;
		}

		$fields['yourmark_image_sizes'] = [
			'label'        => __( 'Sizes', 'yourmark-image-sizes' ),
			'input'        => 'html',
			'html'         => sprintf(
				'<div class="yourmark-image-sizes yourmark-image-sizes--attachment" data-yourmark-image-sizes-tool><div class="yourmark-image-sizes__body">%s</div><p class="yourmark-image-sizes__status" role="status" tabindex="-1"></p></div>',
				self::panel( $post->ID )
			),
			'show_in_edit' => true,
		];

		return $fields;
	}

	/**
	 * Every page URL in the sitemap.
	 *
	 * @return void
	 */
	public static function ajax_pages(): void {
		self::authorise( 'manage_options' );
		wp_send_json_success( [ 'urls' => Warmer::sitemap_urls() ] );
	}

	/**
	 * Warm a few pages.
	 *
	 * @return void
	 */
	public static function ajax_warm(): void {
		self::authorise( 'manage_options' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorise().
		$urls = isset( $_POST['urls'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['urls'] ) ) : [];
		$made = 0;

		foreach ( array_slice( $urls, 0, self::PAGES ) as $url ) {
			$made += Warmer::warm( $url );
		}

		wp_send_json_success( [ 'made' => $made ] );
	}

	/**
	 * Convert, count or prune one batch of the media library.
	 *
	 * @return void
	 */
	public static function ajax_batch(): void {
		self::authorise( 'manage_options' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in authorise().
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$after     = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		// phpcs:enable

		if ( ! in_array( $operation, [ 'convert', 'count', 'prune' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown task.', 'yourmark-image-sizes' ) ], 400 );
		}

		$ids = Library::image_ids( $after, self::BATCH );

		// Convert: what happened to each image, so the summary can say why nothing changed. Count and prune: files and bytes.
		$counts = array_fill_keys( [ 'converted', 'current', 'icon', 'animated', 'type', 'failed', 'files', 'bytes' ], 0 );

		foreach ( $ids as $id ) {
			if ( $operation !== 'convert' ) {
				$pruned           = Pruner::prune( $id, true, $operation === 'count' );
				$counts['files'] += count( $pruned['files'] );
				$counts['bytes'] += $pruned['bytes'];
				continue;
			}

			$reason = Converter::skip_reason( $id ) ?? ( Converter::convert( $id ) === true ? 'converted' : 'failed' );
			++$counts[ $reason ];
		}

		$result = [
			'last'      => $ids ? end( $ids ) : $after,
			'done'      => count( $ids ) < self::BATCH,
			'processed' => count( $ids ),
			'total'     => Library::count(),
		] + $counts;

		wp_send_json_success( $result );
	}

	/**
	 * Make one size of an image, or convert it if needed and make all the missing ones.
	 *
	 * @return void
	 */
	public static function ajax_image(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in authorise().
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : '';
		// phpcs:enable

		self::authorise( 'upload_files', $id );

		if ( ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Not an image.', 'yourmark-image-sizes' ) ], 400 );
		}

		if ( $name !== '' ) {
			$size = self::sizes( $id )[ $name ] ?? null;
			$made = $size === null ? null : Generator::make( $size['path'] );

			if ( ! is_array( $made ) ) {
				$message = is_wp_error( $made ) ? $made->get_error_message() : __( 'This image has no such size.', 'yourmark-image-sizes' );
				wp_send_json_error( [ 'message' => $message ], 500 );
			}

			self::respond( $id, false, 1 );
		}

		$converted = Converter::convert( $id );

		if ( is_wp_error( $converted ) ) {
			wp_send_json_error( [ 'message' => $converted->get_error_message() ], 500 );
		}

		self::respond( $id, $converted, Converter::make_sizes( $id ) );
	}

	/**
	 * Send the result of a single-image action with the panel as it is now.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $converted     Whether the original was converted.
	 * @param int  $made          Sizes made.
	 * @return void
	 */
	private static function respond( int $attachment_id, bool $converted, int $made ): void {
		wp_send_json_success(
			[
				'converted' => $converted,
				'made'      => $made,
				'panel'     => self::panel( $attachment_id ),
				'action'    => self::action_label( $attachment_id ),
			]
		);
	}

	/**
	 * Every size of an image: what it is called, how big it is and whether its file exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array{label: string, named: bool, width: int, height: int, made: bool, path: string}> Keyed by size name; path is below the uploads base.
	 */
	private static function sizes( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return [];
		}

		$labels    = self::size_labels();
		$basedir   = wp_get_upload_dir()['basedir'];
		$directory = dirname( (string) $meta['file'] );
		$prefix    = $directory === '.' ? '' : "{$directory}/";
		$sizes     = [];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			if ( empty( $size['file'] ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
				continue;
			}

			$path = $prefix . $size['file'];

			$sizes[ (string) $name ] = [
				'label'  => $labels[ $name ] ?? (string) $name,
				'named'  => isset( $labels[ $name ] ),
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'made'   => file_exists( "{$basedir}/{$path}" ),
				'path'   => $path,
			];
		}

		uasort( $sizes, fn( array $a, array $b ): int => $a['width'] * $a['height'] <=> $b['width'] * $b['height'] );

		return $sizes;
	}

	/**
	 * The panel's content: which sizes exist, which do not yet, and what the button does.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML.
	 */
	private static function panel( int $attachment_id ): string {
		$sizes   = self::sizes( $attachment_id );
		$total   = count( $sizes );
		$made    = count( array_filter( $sizes, fn( array $size ): bool => $size['made'] ) );
		$convert = Converter::skip_reason( $attachment_id ) === null;
		$items   = '';

		foreach ( $sizes as $name => $size ) {
			$dimensions = sprintf( '%d × %d', $size['width'], $size['height'] );
			$label      = $size['named'] ? esc_html( $size['label'] ) : '<code>' . esc_html( $size['label'] ) . '</code>';

			$state = $size['made']
				? '<span class="yourmark-image-sizes__state">' . esc_html__( 'On disk', 'yourmark-image-sizes' ) . '</span>'
				: sprintf(
					'<button type="button" class="button-link" data-yourmark-image-sizes="size" data-id="%1$d" data-size="%2$s" aria-label="%3$s">%4$s</button>',
					$attachment_id,
					esc_attr( $name ),
					/* translators: 1: size name, 2: dimensions */
					esc_attr( sprintf( __( 'Make %1$s (%2$s) now', 'yourmark-image-sizes' ), $size['label'], $dimensions ) ),
					esc_html__( 'Make now', 'yourmark-image-sizes' )
				);

			$items .= sprintf(
				'<li class="yourmark-image-sizes__size%1$s"><span class="yourmark-image-sizes__size-name">%2$s <span class="yourmark-image-sizes__size-dimensions">%3$s</span></span> %4$s</li>',
				$size['made'] ? ' is-made' : '',
				$label,
				esc_html( $dimensions ),
				$state
			);
		}

		$summary = $made === $total
			/* translators: %d: number of sizes */
			? sprintf( _n( 'Its %d size is on disk.', 'All %d sizes are on disk.', $total, 'yourmark-image-sizes' ), $total )
			/* translators: 1: sizes on disk, 2: all sizes */
			: sprintf( _n( '%1$d of %2$d sizes is on disk.', '%1$d of %2$d sizes are on disk.', $made, 'yourmark-image-sizes' ), $made, $total ) . ' ' . __( 'The rest is made the first time something uses it.', 'yourmark-image-sizes' );

		$html = '<p class="yourmark-image-sizes__summary">' . esc_html( $summary ) . '</p><ul class="yourmark-image-sizes__sizes">' . $items . '</ul>';

		if ( $convert ) {
			$html .= '<p class="yourmark-image-sizes__note">' . esc_html(
				sprintf(
					/* translators: 1: current format, 2: WebP or AVIF */
					__( 'The original is %1$s. The button below converts it to %2$s and makes every size.', 'yourmark-image-sizes' ),
					strtoupper( (string) wp_get_default_extension_for_mime_type( (string) get_post_mime_type( $attachment_id ) ) ),
					(string) Formats::label()
				)
			) . '</p>';
		}

		$label = self::action_label( $attachment_id );

		if ( $label !== null ) {
			$html .= sprintf(
				'<p class="yourmark-image-sizes__actions"><button type="button" class="button button-small" data-yourmark-image-sizes="image" data-id="%1$d">%2$s</button></p>',
				$attachment_id,
				esc_html( $label )
			);
		}

		return $html;
	}

	/**
	 * Text of the button that finishes an image, or null when there is nothing left to do.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	private static function action_label( int $attachment_id ): ?string {
		if ( Converter::skip_reason( $attachment_id ) === null ) {
			/* translators: %s: WebP or AVIF */
			return sprintf( __( 'Generate %s', 'yourmark-image-sizes' ), (string) Formats::label() );
		}

		$missing = array_filter( self::sizes( $attachment_id ), fn( array $size ): bool => ! $size['made'] );

		return $missing ? __( 'Make missing sizes', 'yourmark-image-sizes' ) : null;
	}

	/**
	 * Readable names for sizes, as the editor's size dropdown shows them.
	 *
	 * @return array<string, string>
	 */
	private static function size_labels(): array {
		/** This filter is documented in wp-admin/includes/media.php */
		return (array) apply_filters(
			'image_size_names_choose',
			[
				'thumbnail'    => __( 'Thumbnail', 'yourmark-image-sizes' ),
				'medium'       => __( 'Medium', 'yourmark-image-sizes' ),
				'medium_large' => __( 'Medium large', 'yourmark-image-sizes' ),
				'large'        => __( 'Large', 'yourmark-image-sizes' ),
			]
		);
	}

	/**
	 * Stop the request unless it carries the nonce and the user may act.
	 *
	 * @param string $capability    Capability for site-wide tools.
	 * @param int    $attachment_id Attachment the request acts on, checked with edit_post instead.
	 * @return void
	 */
	private static function authorise( string $capability, int $attachment_id = 0 ): void {
		check_ajax_referer( self::NONCE );

		$allowed = $attachment_id ? current_user_can( 'edit_post', $attachment_id ) : current_user_can( $capability );

		if ( ! $allowed ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'yourmark-image-sizes' ) ], 403 );
		}
	}

	/**
	 * Whether the current user gets the sizes panel for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function can_edit( int $attachment_id ): bool {
		return Sizes::enabled() && wp_attachment_is_image( $attachment_id ) && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Text the script shows. {name} is replaced with a value.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		$cache = Health::direct()
			? ''
			: ' ' . __( 'Clear the page cache afterwards: this server does not pass missing images to WordPress.', 'yourmark-image-sizes' );

		return [
			'warming'       => __( 'Warming pages: {done} of {total}', 'yourmark-image-sizes' ),
			'warmed'        => __( 'Done. Made {made} sizes across {total} pages.', 'yourmark-image-sizes' ),
			'warmedNone'    => __( 'Done. All {total} pages already had every image they show.', 'yourmark-image-sizes' ),
			'noPages'       => __( 'No pages found in the sitemap.', 'yourmark-image-sizes' ),
			'converting'    => __( 'Converting: {done} of {total} images', 'yourmark-image-sizes' ),
			'converted'     => __( 'Done. Converted {converted} originals to {format}.', 'yourmark-image-sizes' ),
			'convertedNone' => __( 'Done. Nothing needed converting.', 'yourmark-image-sizes' ),
			'skipCurrent'   => __( '{count} were already {format}.', 'yourmark-image-sizes' ),
			'skipIcon'      => __( 'The site icon stays PNG: iOS ignores any other format.', 'yourmark-image-sizes' ),
			'skipAnimated'  => __( '{count} are animated and stay as they are, so they keep moving.', 'yourmark-image-sizes' ),
			'skipType'      => __( '{count} are GIF, SVG or another format that is never converted.', 'yourmark-image-sizes' ),
			'failed'        => __( '{count} could not be converted; the server log has the reason.', 'yourmark-image-sizes' ),
			'counting'      => __( 'Counting: {done} of {total} images', 'yourmark-image-sizes' ),
			'found'         => __( 'Found {files} size files ({size}).', 'yourmark-image-sizes' ) . $cache,
			'found1'        => __( 'Found 1 size file ({size}).', 'yourmark-image-sizes' ) . $cache,
			'delete'        => __( 'Delete {files} files', 'yourmark-image-sizes' ),
			'delete1'       => __( 'Delete 1 file', 'yourmark-image-sizes' ),
			'cancel'        => __( 'Cancel', 'yourmark-image-sizes' ),
			'pruning'       => __( 'Deleting: {done} of {total} images', 'yourmark-image-sizes' ),
			'pruned'        => __( 'Done. Deleted {files} files and freed {size}. Sizes come back as pages use them.', 'yourmark-image-sizes' ),
			'pruned1'       => __( 'Done. Deleted 1 file and freed {size}. Sizes come back as pages use them.', 'yourmark-image-sizes' ),
			'nothing'       => __( 'There are no size files to clean up.', 'yourmark-image-sizes' ),
			'cancelled'     => __( 'Nothing was deleted.', 'yourmark-image-sizes' ),
			'making'        => __( 'Making…', 'yourmark-image-sizes' ),
			'madeOne'       => __( 'Done. The size is on disk.', 'yourmark-image-sizes' ),
			'made'          => __( 'Done. Made {made} sizes.', 'yourmark-image-sizes' ),
			'convertedOne'  => __( 'Done. Converted the original and made {made} sizes.', 'yourmark-image-sizes' ),
			'error'         => __( 'Something went wrong: {message}', 'yourmark-image-sizes' ),
		];
	}
}
