<?php
/**
 * Plugin Name:       Fullworks Gravity to Listmonk
 * Plugin URI:        https://github.com/alanef/plugin-fullworks-gravity-to-listmonk-plugin
 * Description:       Adds Gravity Forms submitters to Listmonk mailing lists, with per-form feeds, field mapping and conditional logic.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Update URI:        https://github.com/alanef/plugin-fullworks-gravity-to-listmonk-plugin
 * Author:            Fullworks
 * Author URI:        https://fullworks.net/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fullworks-gravity-to-listmonk
 * Domain Path:       /languages
 *
 * @package FullworksGTL
 *
 * "Gravity Forms" is a trademark of Rocketgenius, Inc. This plugin is an
 * independent, unofficial add-on and uses the name only to describe what it
 * is compatible with. Listmonk is open-source software by its respective authors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FWGTL_VERSION', '1.1.0' );
define( 'FWGTL_PATH', plugin_dir_path( __FILE__ ) );
define( 'FWGTL_URL', plugin_dir_url( __FILE__ ) );
define( 'FWGTL_BASENAME', plugin_basename( __FILE__ ) );
define( 'FWGTL_FILE', __FILE__ );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// The zip ships vendor/; a git checkout without `composer install` falls back to explicit includes.
	require_once __DIR__ . '/includes/api/class-client.php';
	require_once __DIR__ . '/includes/class-subscriber-sync.php';
	require_once __DIR__ . '/includes/class-feed-meta.php';
	require_once __DIR__ . '/includes/class-plugin.php';
}

require_once __DIR__ . '/includes/gf/class-bootstrap.php';

add_action( 'plugins_loaded', array( 'FullworksGTL\\Plugin', 'register' ) );

/*
 * Gravity Forms fires gform_loaded during its own plugins_loaded handler, so
 * the add-on must be hooked at file scope; hooking from inside another
 * plugins_loaded callback can miss the action entirely.
 */
add_action( 'gform_loaded', array( 'FullworksGTL\\GF\\Bootstrap', 'load_addon' ), 5 );

// Self-update from GitHub releases. Managed by wordpress-plugin-boilerplate/tooling: present only
// while readme.txt has no Type: header (GitHub-only release).
if ( file_exists( __DIR__ . '/includes/class-github-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-github-updater.php';
}
