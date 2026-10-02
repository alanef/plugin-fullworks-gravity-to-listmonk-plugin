<?php
/**
 * Reading values out of stored feed meta.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL;

defined( 'ABSPATH' ) || exit;

/**
 * Feed-meta helpers that do not need Gravity Forms loaded.
 */
class Feed_Meta {

	/**
	 * List IDs ticked on a feed.
	 *
	 * The list checkboxes are stored as `list_{id} => '1'` alongside other
	 * meta, some of which (field maps, conditional logic) are arrays.
	 *
	 * @param array $meta Feed meta or submitted feed settings.
	 * @return int[]
	 */
	public static function selected_list_ids( $meta ) {
		$ids = array();
		foreach ( (array) $meta as $key => $value ) {
			if ( is_scalar( $value ) && '1' === (string) $value && preg_match( '/^list_(\d+)$/', (string) $key, $m ) ) {
				$ids[] = (int) $m[1];
			}
		}
		return $ids;
	}
}
