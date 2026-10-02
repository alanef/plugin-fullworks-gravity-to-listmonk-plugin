<?php
/**
 * Gravity Forms feed add-on: Listmonk.
 *
 * Required only from Bootstrap::load_addon(), after Gravity Forms has loaded,
 * so the parent class exists when this file is parsed.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL\GF;

use FullworksGTL\Api\Client;
use FullworksGTL\Feed_Meta;
use FullworksGTL\Plugin;
use FullworksGTL\Subscriber_Sync;

defined( 'ABSPATH' ) || exit;

\GFForms::include_feed_addon_framework();

/**
 * Settings (Forms → Settings → Listmonk) and per-form feeds.
 */
class Feed_AddOn extends \GFFeedAddOn {

	// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore -- GFFeedAddOn requires underscore-prefixed framework properties.

	/**
	 * Version.
	 *
	 * @var string
	 */
	protected $_version = FWGTL_VERSION;

	/**
	 * Minimum Gravity Forms version (the settings framework used here arrived in 2.5).
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5';

	/**
	 * Add-on slug; also the settings option suffix.
	 *
	 * @var string
	 */
	protected $_slug = 'fullworks-gravity-to-listmonk';

	/**
	 * Plugin path relative to the plugins directory.
	 *
	 * @var string
	 */
	protected $_path = 'fullworks-gravity-to-listmonk/fullworks-gravity-to-listmonk.php';

	/**
	 * Full path to the main plugin file, set in the constructor.
	 *
	 * @var string
	 */
	protected $_full_path;

	/**
	 * Title.
	 *
	 * @var string
	 */
	protected $_title = 'Fullworks Gravity to Listmonk';

	/**
	 * Short title for tabs.
	 *
	 * @var string
	 */
	protected $_short_title = 'Listmonk';

	/**
	 * Capabilities.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_edit_settings';

	/**
	 * Capabilities.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_edit_forms';

	/**
	 * Capabilities.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_uninstall';

	// phpcs:enable PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton.
	 *
	 * @var Feed_AddOn|null
	 */
	private static $instance = null;

	/**
	 * Per-request connection-test cache, keyed on the credentials tested.
	 *
	 * @var array<string,array>
	 */
	private $connection_cache = array();

	/**
	 * Singleton accessor used by GFAddOn::register().
	 *
	 * @return Feed_AddOn
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->_full_path = FWGTL_FILE;
		parent::__construct();
	}

	/**
	 * Sidebar icon.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return 'dashicons-email-alt';
	}

	// ------------------------------------------------------------------
	// Plugin settings.
	// ------------------------------------------------------------------

	/**
	 * Plugin settings: Listmonk URL, API user and API key.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Listmonk connection', 'fullworks-gravity-to-listmonk' ),
				'description' => '<p>' . esc_html__( 'Create an API user in Listmonk under Users → New → API, give it a role that can manage subscribers and read lists, then paste its user name and token here.', 'fullworks-gravity-to-listmonk' ) . '</p>',
				'fields'      => array(
					array(
						'name'                => 'listmonk_url',
						'label'               => esc_html__( 'Listmonk URL', 'fullworks-gravity-to-listmonk' ),
						'type'                => 'text',
						'class'               => 'medium',
						'required'            => true,
						'placeholder'         => 'https://lists.example.com',
						'tooltip'             => esc_html__( 'The root address of your Listmonk install, without /admin or /api.', 'fullworks-gravity-to-listmonk' ),
						'validation_callback' => array( $this, 'validate_url_setting' ),
					),
					array(
						'name'     => 'api_user',
						'label'    => esc_html__( 'API user', 'fullworks-gravity-to-listmonk' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
					),
					array(
						'name'              => 'api_key',
						'label'             => esc_html__( 'API key', 'fullworks-gravity-to-listmonk' ),
						'type'              => 'text',
						'input_type'        => 'password',
						'class'             => 'medium',
						'required'          => true,
						'feedback_callback' => array( $this, 'is_valid_api_key' ),
					),
					array(
						'name' => 'connection_status',
						'type' => 'html',
						'html' => array( $this, 'render_connection_status' ),
					),
				),
			),
		);
	}

	/**
	 * Reject anything that is not an http(s) URL.
	 *
	 * @param array  $field Field.
	 * @param string $value Value.
	 */
	public function validate_url_setting( $field, $value ) {
		// Not wp_http_validate_url(): it rejects private hosts, and a self-hosted
		// Listmonk is often on a LAN address or a Docker service name.
		$value  = trim( (string) $value );
		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = wp_parse_url( $value, PHP_URL_HOST );
		if ( '' !== $value && ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $host ) ) ) {
			$this->set_field_error( $field, esc_html__( 'Enter a full http:// or https:// URL.', 'fullworks-gravity-to-listmonk' ) );
		}
	}

	/**
	 * Sanitise and store settings; drop cached lists and connection results.
	 *
	 * @param array $settings Settings.
	 */
	public function update_plugin_settings( $settings ) {
		$settings['listmonk_url'] = esc_url_raw( Client::normalise_url( (string) rgar( $settings, 'listmonk_url' ) ) );
		$settings['api_user']     = sanitize_text_field( (string) rgar( $settings, 'api_user' ) );
		$settings['api_key']      = trim( sanitize_text_field( (string) rgar( $settings, 'api_key' ) ) );
		$this->connection_cache   = array();
		delete_transient( Plugin::LISTS_TRANSIENT );
		parent::update_plugin_settings( $settings );
	}

	/**
	 * Feedback tick/cross beside the API key.
	 *
	 * @param string $value API key being rendered.
	 * @return bool|null Null when there is nothing to test yet.
	 */
	public function is_valid_api_key( $value ) {
		$result = $this->test_connection();
		return null === $result ? null : $result['ok'];
	}

	/**
	 * Status line under the credentials.
	 *
	 * @return string
	 */
	public function render_connection_status() {
		$result = $this->test_connection();
		if ( null === $result ) {
			return '';
		}
		if ( $result['ok'] ) {
			return '<p><strong>' . esc_html__( 'Connected.', 'fullworks-gravity-to-listmonk' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: %d: number of lists */
						_n( 'Listmonk returned %d list.', 'Listmonk returned %d lists.', count( $result['data'] ), 'fullworks-gravity-to-listmonk' ),
						count( $result['data'] )
					)
				) . '</p>';
		}
		return '<p><strong>' . esc_html__( 'Not connected.', 'fullworks-gravity-to-listmonk' ) . '</strong> ' . esc_html( $result['message'] ) . '</p>';
	}

	/**
	 * Test the saved credentials once per request.
	 *
	 * @return array|null Client result, or null when not configured.
	 */
	private function test_connection() {
		$client = $this->get_client();
		if ( ! $client->is_configured() ) {
			return null;
		}
		$key = md5( (string) wp_json_encode( $this->get_plugin_settings() ) );
		if ( ! isset( $this->connection_cache[ $key ] ) ) {
			$this->connection_cache[ $key ] = $client->get_lists();
			if ( $this->connection_cache[ $key ]['ok'] ) {
				set_transient( Plugin::LISTS_TRANSIENT, $this->connection_cache[ $key ]['data'], 10 * MINUTE_IN_SECONDS );
			}
		}
		return $this->connection_cache[ $key ];
	}

	/**
	 * Client built from the saved settings.
	 *
	 * @return Client
	 */
	public function get_client() {
		return new Client(
			(string) $this->get_plugin_setting( 'listmonk_url' ),
			(string) $this->get_plugin_setting( 'api_user' ),
			(string) $this->get_plugin_setting( 'api_key' )
		);
	}

	/**
	 * Feeds can only be created once credentials are saved.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return $this->get_client()->is_configured();
	}

	/**
	 * Allow feed duplication.
	 *
	 * @param int|array $id Feed ID or feed.
	 * @return bool
	 */
	public function can_duplicate_feed( $id ) {
		return true;
	}

	// ------------------------------------------------------------------
	// Feed settings.
	// ------------------------------------------------------------------

	/**
	 * Listmonk lists, cached for ten minutes.
	 *
	 * @return array{ok:bool,lists:array,message:string}
	 */
	private function get_lists() {
		$cached = get_transient( Plugin::LISTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return array(
				'ok'      => true,
				'lists'   => $cached,
				'message' => '',
			);
		}
		$result = $this->get_client()->get_lists();
		if ( $result['ok'] ) {
			set_transient( Plugin::LISTS_TRANSIENT, $result['data'], 10 * MINUTE_IN_SECONDS );
		}
		return array(
			'ok'      => $result['ok'],
			'lists'   => $result['ok'] ? $result['data'] : array(),
			'message' => $result['message'],
		);
	}

	/**
	 * Checkbox choices, one per Listmonk list.
	 *
	 * @return array
	 */
	private function list_choices() {
		$choices = array();
		foreach ( $this->get_lists()['lists'] as $list ) {
			if ( ! is_array( $list ) || empty( $list['id'] ) ) {
				continue;
			}
			$flags     = array();
			$flags[]   = 'double' === rgar( $list, 'optin' ) ? __( 'double opt-in', 'fullworks-gravity-to-listmonk' ) : __( 'single opt-in', 'fullworks-gravity-to-listmonk' );
			$flags[]   = 'public' === rgar( $list, 'type' ) ? __( 'public', 'fullworks-gravity-to-listmonk' ) : __( 'private', 'fullworks-gravity-to-listmonk' );
			$choices[] = array(
				'name'  => 'list_' . (int) $list['id'],
				'label' => sprintf( '%s (%s)', (string) rgar( $list, 'name' ), implode( ', ', $flags ) ),
			);
		}
		return $choices;
	}

	/**
	 * Per-feed settings.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$lists = $this->get_lists();

		if ( ! $lists['ok'] ) {
			$lists_field = array(
				'name' => 'lists_error',
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'Could not load lists from Listmonk:', 'fullworks-gravity-to-listmonk' ) . ' ' . esc_html( $lists['message'] ) . '</p>',
			);
		} elseif ( ! $lists['lists'] ) {
			$lists_field = array(
				'name' => 'lists_error',
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'Listmonk has no lists yet. Create one in Listmonk first.', 'fullworks-gravity-to-listmonk' ) . '</p>',
			);
		} else {
			$lists_field = array(
				'name'     => 'lists',
				'label'    => esc_html__( 'Lists', 'fullworks-gravity-to-listmonk' ),
				'type'     => 'checkbox',
				'required' => true,
				'choices'  => $this->list_choices(),
				'tooltip'  => esc_html__( 'The submitter is added to every list ticked here. Double opt-in lists send a confirmation e-mail unless you skip opt-in below.', 'fullworks-gravity-to-listmonk' ),
			);
		}

		return array(
			array(
				'title'  => esc_html__( 'Listmonk feed', 'fullworks-gravity-to-listmonk' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Name', 'fullworks-gravity-to-listmonk' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
						'tooltip'  => esc_html__( 'A name to identify this feed.', 'fullworks-gravity-to-listmonk' ),
					),
					$lists_field,
				),
			),
			array(
				'title'  => esc_html__( 'Field mapping', 'fullworks-gravity-to-listmonk' ),
				'fields' => array(
					array(
						'name'      => 'fields',
						'label'     => esc_html__( 'Subscriber fields', 'fullworks-gravity-to-listmonk' ),
						'type'      => 'field_map',
						'field_map' => array(
							array(
								'name'       => 'email',
								'label'      => esc_html__( 'E-mail address', 'fullworks-gravity-to-listmonk' ),
								'required'   => true,
								'field_type' => array( 'email', 'hidden', 'text' ),
							),
							array(
								'name'     => 'name',
								'label'    => esc_html__( 'Name', 'fullworks-gravity-to-listmonk' ),
								'required' => false,
								'tooltip'  => esc_html__( 'Map a Name field (or its full-name choice). When empty, Listmonk derives a name from the e-mail address.', 'fullworks-gravity-to-listmonk' ),
							),
						),
					),
					array(
						'name'        => 'attributes',
						'label'       => esc_html__( 'Attributes', 'fullworks-gravity-to-listmonk' ),
						'type'        => 'generic_map',
						'key_field'   => array(
							'title'        => esc_html__( 'Attribute', 'fullworks-gravity-to-listmonk' ),
							'allow_custom' => true,
							'placeholder'  => esc_html__( 'Attribute key', 'fullworks-gravity-to-listmonk' ),
						),
						'value_field' => array(
							'title'        => esc_html__( 'Form field', 'fullworks-gravity-to-listmonk' ),
							'allow_custom' => true,
						),
						'tooltip'     => esc_html__( 'Stored on the subscriber as Listmonk attributes (JSON), e.g. city, company or source. Empty values are not sent.', 'fullworks-gravity-to-listmonk' ),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Options', 'fullworks-gravity-to-listmonk' ),
				'fields' => array(
					array(
						'name'    => 'options',
						'label'   => esc_html__( 'Options', 'fullworks-gravity-to-listmonk' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'    => 'preconfirm',
								'label'   => esc_html__( 'Skip double opt-in (mark subscriptions confirmed)', 'fullworks-gravity-to-listmonk' ),
								'tooltip' => esc_html__( 'Only use this when the form itself collects clear consent. No confirmation e-mail is sent.', 'fullworks-gravity-to-listmonk' ),
							),
							array(
								'name'    => 'update_existing',
								'label'   => esc_html__( 'Update name and attributes of existing subscribers', 'fullworks-gravity-to-listmonk' ),
								'tooltip' => esc_html__( 'When the e-mail address is already in Listmonk, overwrite its name and merge these attributes into its existing ones. Off by default so a form cannot change an existing subscriber\'s details.', 'fullworks-gravity-to-listmonk' ),
							),
						),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Conditional logic', 'fullworks-gravity-to-listmonk' ),
				'fields' => array(
					array(
						'name'           => 'feed_condition',
						'label'          => esc_html__( 'Condition', 'fullworks-gravity-to-listmonk' ),
						'type'           => 'feed_condition',
						'checkbox_label' => esc_html__( 'Enable condition', 'fullworks-gravity-to-listmonk' ),
						'instructions'   => esc_html__( 'Send to Listmonk if', 'fullworks-gravity-to-listmonk' ),
						'tooltip'        => esc_html__( 'For example, only subscribe people who ticked a consent checkbox.', 'fullworks-gravity-to-listmonk' ),
					),
				),
			),
		);
	}

	/**
	 * Require at least one list.
	 *
	 * @param array $settings Submitted settings.
	 * @return bool
	 */
	public function feed_settings_validation( $settings ) {
		$valid = parent::feed_settings_validation( $settings );
		if ( ! Feed_Meta::selected_list_ids( $settings ) ) {
			$this->set_field_error( array( 'name' => 'lists' ), esc_html__( 'Select at least one list.', 'fullworks-gravity-to-listmonk' ) );
			$valid = false;
		}
		return $valid;
	}

	/**
	 * Feed list columns.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName' => esc_html__( 'Name', 'fullworks-gravity-to-listmonk' ),
			'lists'    => esc_html__( 'Lists', 'fullworks-gravity-to-listmonk' ),
		);
	}

	/**
	 * Lists column: list names where known, IDs otherwise.
	 *
	 * @param array $feed Feed.
	 * @return string
	 */
	public function get_column_value_lists( $feed ) {
		$names = array();
		foreach ( $this->get_lists()['lists'] as $list ) {
			if ( is_array( $list ) && isset( $list['id'] ) ) {
				$names[ (int) $list['id'] ] = (string) rgar( $list, 'name' );
			}
		}
		$out = array();
		foreach ( Feed_Meta::selected_list_ids( rgar( $feed, 'meta', array() ) ) as $id ) {
			$out[] = isset( $names[ $id ] ) ? $names[ $id ] : '#' . $id;
		}
		return esc_html( implode( ', ', $out ) );
	}

	// ------------------------------------------------------------------
	// Processing.
	// ------------------------------------------------------------------

	/**
	 * Send the entry to Listmonk. Conditional logic has already been applied.
	 *
	 * Never blocks the submission: failures become entry notes and log lines.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array Entry.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$map   = self::get_field_map_fields( $feed, 'fields' );
		$email = trim( (string) $this->get_field_value( $form, $entry, rgar( $map, 'email' ) ) );
		$name  = rgar( $map, 'name' ) ? trim( (string) $this->get_field_value( $form, $entry, $map['name'] ) ) : '';

		$attribs = array();
		foreach ( $this->get_generic_map_fields( $feed, 'attributes', $form, $entry ) as $key => $value ) {
			$key   = trim( sanitize_text_field( (string) $key ) );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' !== $key && '' !== $value ) {
				$attribs[ $key ] = $value;
			}
		}

		$subscriber = array(
			'email'    => $email,
			'name'     => $name,
			'attribs'  => $attribs,
			'list_ids' => Feed_Meta::selected_list_ids( rgar( $feed, 'meta', array() ) ),
			'options'  => array(
				'preconfirm'      => '1' === (string) rgars( $feed, 'meta/preconfirm' ),
				'update_existing' => '1' === (string) rgars( $feed, 'meta/update_existing' ),
			),
		);

		/**
		 * Filter the subscriber data before it is sent to Listmonk.
		 *
		 * @param array $subscriber email, name, attribs, list_ids, options.
		 * @param array $feed       Feed.
		 * @param array $entry      Entry.
		 * @param array $form       Form.
		 */
		$subscriber = apply_filters( 'fwgtl_subscriber', $subscriber, $feed, $entry, $form );

		$sync    = new Subscriber_Sync( $this->get_client() );
		$outcome = $sync->subscribe(
			(string) rgar( $subscriber, 'email' ),
			(string) rgar( $subscriber, 'name' ),
			(array) rgar( $subscriber, 'attribs', array() ),
			(array) rgar( $subscriber, 'list_ids', array() ),
			(array) rgar( $subscriber, 'options', array() )
		);

		if ( Subscriber_Sync::STATUS_ERROR === $outcome['status'] ) {
			$this->add_feed_error( $outcome['message'], $feed, $entry, $form );
		} else {
			$this->log_debug( __METHOD__ . '(): ' . $outcome['message'] );
			$this->add_note( rgar( $entry, 'id' ), $outcome['message'], Subscriber_Sync::STATUS_SKIPPED === $outcome['status'] ? 'error' : 'success' );
			if ( $outcome['subscriber_id'] ) {
				gform_update_meta( rgar( $entry, 'id' ), 'fwgtl_subscriber_id', (int) $outcome['subscriber_id'] );
			}
		}

		/**
		 * Fires after a feed has been processed.
		 *
		 * @param array $outcome Subscriber_Sync outcome.
		 * @param array $feed    Feed.
		 * @param array $entry   Entry.
		 * @param array $form    Form.
		 */
		do_action( 'fwgtl_after_subscribe', $outcome, $feed, $entry, $form );

		return $entry;
	}
}
