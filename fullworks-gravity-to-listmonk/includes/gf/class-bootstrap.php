<?php
/**
 * Registers the Gravity Forms feed add-on.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL\GF;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the feed add-on once Gravity Forms is available.
 *
 * The add-on class extends \GFFeedAddOn, which only exists after Gravity Forms
 * has loaded its add-on framework, so its file is required here rather than
 * autoloaded.
 */
class Bootstrap {

	/**
	 * Include the add-on class and register it with Gravity Forms.
	 */
	public static function load_addon() {
		if ( ! method_exists( '\\GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}
		require_once __DIR__ . '/class-feed-addon.php';
		\GFAddOn::register( 'FullworksGTL\\GF\\Feed_AddOn' );
	}
}
