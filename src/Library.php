<?php
/**
 * Walks the media library in batches.
 *
 * @package YourMark\ImageSizes
 */

namespace YourMark\ImageSizes;

/**
 * Image attachments in ID order, for the batch tools in WP-CLI and the admin.
 */
final class Library {

	/**
	 * Image attachment IDs after a given ID.
	 *
	 * A cursor rather than a page number: converting changes an attachment's MIME
	 * type, and an offset would then skip the ones behind it.
	 *
	 * @param int $after Last ID handled; 0 to start.
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	public static function image_ids( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WP_Query has no ID cursor; this is a batch job, not a page load.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID LIMIT %d",
				'image/%',
				$after,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of image attachments, outside the trash.
	 *
	 * @return int
	 */
	public static function count(): int {
		$counts = (array) wp_count_attachments( 'image' );
		unset( $counts['trash'] );

		return (int) array_sum( $counts );
	}
}
