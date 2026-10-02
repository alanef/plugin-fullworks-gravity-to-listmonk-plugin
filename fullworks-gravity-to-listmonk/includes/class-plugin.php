<?php
/**
 * Plugin-wide hooks that do not depend on Gravity Forms.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin notices and the suggested privacy-policy text.
 */
class Plugin {

	/**
	 * Option under which the Gravity Forms add-on framework stores plugin settings.
	 */
	const SETTINGS_OPTION = 'gravityformsaddon_fullworks-gravity-to-listmonk_settings';

	/**
	 * Transient caching the Listmonk lists for the feed editor.
	 */
	const LISTS_TRANSIENT = 'fwgtl_lists';

	/**
	 * Wire hooks. Runs on plugins_loaded.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_missing_gravity_forms' ) );
	}

	/**
	 * Tell administrators the plugin does nothing without Gravity Forms.
	 */
	public static function maybe_notice_missing_gravity_forms() {
		if ( class_exists( '\\GFForms' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Fullworks Gravity to Listmonk needs Gravity Forms 2.5 or later to be installed and active.', 'fullworks-gravity-to-listmonk' )
			. '</p></div>';
	}

	/**
	 * Suggest privacy-policy text: submitted personal data goes to a Listmonk server.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__( 'This text is suggested by the Fullworks Gravity to Listmonk plugin. Edit it to name your Listmonk server and how you use the data.', 'fullworks-gravity-to-listmonk' )
			. '</p><p>'
			. esc_html__( 'When you submit a form that is connected to our mailing list, your e-mail address, your name and any other form answers we have chosen to store are sent to our Listmonk mailing-list server and used to send you the e-mails you signed up for. Where a list uses double opt-in you will receive a confirmation e-mail first. You can unsubscribe at any time using the link in every e-mail.', 'fullworks-gravity-to-listmonk' )
			. '</p>';
		wp_add_privacy_policy_content( 'Fullworks Gravity to Listmonk', wp_kses_post( $content ) );
	}
}
