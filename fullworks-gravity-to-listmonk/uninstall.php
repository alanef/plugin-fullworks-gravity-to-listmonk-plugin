<?php
/**
 * Uninstall: remove the plugin's own settings and cache.
 *
 * Feeds live in Gravity Forms' gf_addon_feed table and entry notes/meta in
 * Gravity Forms' own tables; Gravity Forms' add-on uninstall routine owns those.
 *
 * @package FullworksGTL
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gravityformsaddon_fullworks-gravity-to-listmonk_settings' );
delete_option( 'gravityformsaddon_fullworks-gravity-to-listmonk_version' );
delete_transient( 'fwgtl_lists' );
