<?php
/**
 * Create-or-update logic for a Listmonk subscriber.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL;

use FullworksGTL\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one person to one or more Listmonk lists, whether or not Listmonk
 * already knows them.
 *
 * New e-mail: a single POST /api/subscribers. Listmonk sends the double
 * opt-in e-mail itself.
 *
 * Existing e-mail (POST answers 409). Listmonk's obvious update endpoint,
 * PUT /api/subscribers/:id, is unsafe here: it replaces attribs wholesale,
 * deletes every subscription not named in the request, and re-sends the
 * opt-in e-mail for every unconfirmed double opt-in list. So instead:
 *
 * - blocklisted subscribers are left completely alone;
 * - only lists the subscriber is not on are added, via
 *   PUT /api/subscribers/lists (action=add), which never alters existing
 *   subscriptions, so an earlier unsubscribe stays an unsubscribe;
 * - newly added double opt-in lists get a confirmation e-mail via
 *   POST /api/subscribers/:id/optin;
 * - name/attributes are only updated when the feed asks for it, with attribs
 *   merged into the existing ones and the full list set sent back. That
 *   update is skipped when it would re-send an opt-in e-mail for a list the
 *   subscriber was already asked to confirm.
 */
class Subscriber_Sync {

	const STATUS_CREATED  = 'created';
	const STATUS_EXISTING = 'existing';
	const STATUS_SKIPPED  = 'skipped';
	const STATUS_ERROR    = 'error';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client Listmonk client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Subscribe an e-mail address to lists.
	 *
	 * @param string $email    E-mail address.
	 * @param string $name     Name ('' lets Listmonk derive one on create and keeps the existing one on update).
	 * @param array  $attribs  Attributes (string keys).
	 * @param int[]  $list_ids Target list IDs.
	 * @param array  $options  {
	 *     Optional.
	 *
	 *     @type bool $preconfirm      Mark new subscriptions confirmed and skip the double opt-in e-mail.
	 *     @type bool $update_existing Update name and merge attributes of an existing subscriber.
	 * }
	 * @return array{status:string,subscriber_id:int|null,added_lists:int[],unsubscribed_lists:int[],updated:bool,optin_sent:bool,message:string}
	 */
	public function subscribe( string $email, string $name, array $attribs, array $list_ids, array $options = array() ): array {
		$preconfirm      = ! empty( $options['preconfirm'] );
		$update_existing = ! empty( $options['update_existing'] );
		$list_ids        = array_values( array_unique( array_filter( array_map( 'intval', $list_ids ) ) ) );
		$email           = trim( $email );
		$name            = trim( $name );

		if ( ! is_email( $email ) ) {
			return self::outcome(
				self::STATUS_SKIPPED,
				null,
				sprintf(
					/* translators: %s: the value that was supplied as an e-mail address */
					__( 'Not sent to Listmonk: "%s" is not a valid e-mail address.', 'fullworks-gravity-to-listmonk' ),
					$email
				)
			);
		}
		if ( ! $list_ids ) {
			return self::outcome( self::STATUS_SKIPPED, null, __( 'Not sent to Listmonk: no lists are selected on this feed.', 'fullworks-gravity-to-listmonk' ) );
		}

		$created = $this->client->create_subscriber(
			array(
				'email'                    => $email,
				'name'                     => $name,
				'status'                   => 'enabled',
				'lists'                    => $list_ids,
				'attribs'                  => (object) $attribs,
				'preconfirm_subscriptions' => $preconfirm,
			)
		);

		if ( $created['ok'] ) {
			$id = ( is_array( $created['data'] ) && isset( $created['data']['id'] ) ) ? (int) $created['data']['id'] : null;
			return self::outcome(
				self::STATUS_CREATED,
				$id,
				sprintf(
					/* translators: %s: comma-separated list IDs */
					__( 'Listmonk: new subscriber created and added to list(s) %s.', 'fullworks-gravity-to-listmonk' ),
					implode( ', ', $list_ids )
				),
				array( 'added_lists' => $list_ids )
			);
		}

		if ( 409 !== $created['code'] ) {
			return self::outcome( self::STATUS_ERROR, null, $created['message'] );
		}

		return $this->subscribe_existing( $email, $name, $attribs, $list_ids, $preconfirm, $update_existing );
	}

	/**
	 * Handle an e-mail address Listmonk already has.
	 *
	 * @param string $email           E-mail address.
	 * @param string $name            Name.
	 * @param array  $attribs         Attributes.
	 * @param int[]  $list_ids        Target lists.
	 * @param bool   $preconfirm      Skip double opt-in.
	 * @param bool   $update_existing Update name and attributes.
	 * @return array
	 */
	private function subscribe_existing( string $email, string $name, array $attribs, array $list_ids, bool $preconfirm, bool $update_existing ): array {
		$found = $this->client->find_subscriber_by_email( $email );
		if ( ! $found['ok'] ) {
			return self::outcome( self::STATUS_ERROR, null, $found['message'] );
		}
		$subscriber = $found['data'];
		if ( ! is_array( $subscriber ) || empty( $subscriber['id'] ) ) {
			return self::outcome( self::STATUS_ERROR, null, __( 'Listmonk reported the e-mail address exists but it could not be looked up. Check the API user can read subscribers.', 'fullworks-gravity-to-listmonk' ) );
		}

		$id = (int) $subscriber['id'];

		if ( isset( $subscriber['status'] ) && 'blocklisted' === $subscriber['status'] ) {
			return self::outcome( self::STATUS_SKIPPED, $id, __( 'Listmonk: subscriber is blocklisted, so no lists or details were changed.', 'fullworks-gravity-to-listmonk' ) );
		}

		$current       = self::subscriptions( $subscriber );
		$missing       = array_values( array_diff( $list_ids, array_keys( $current ) ) );
		$unsubscribed  = array();
		$pending_optin = false;
		foreach ( $current as $list_id => $sub ) {
			if ( in_array( $list_id, $list_ids, true ) && 'unsubscribed' === $sub['status'] ) {
				$unsubscribed[] = $list_id;
			}
			if ( 'double' === $sub['optin'] && 'unconfirmed' === $sub['status'] ) {
				$pending_optin = true;
			}
		}

		$notes = array();

		if ( $missing ) {
			$added = $this->client->add_subscriber_to_lists( $id, $missing, $preconfirm ? 'confirmed' : 'unconfirmed' );
			if ( ! $added['ok'] ) {
				return self::outcome( self::STATUS_ERROR, $id, $added['message'] );
			}
			$notes[] = sprintf(
				/* translators: %s: comma-separated list IDs */
				__( 'existing subscriber added to list(s) %s', 'fullworks-gravity-to-listmonk' ),
				implode( ', ', $missing )
			);
		} else {
			$notes[] = __( 'existing subscriber is already on every selected list', 'fullworks-gravity-to-listmonk' );
		}

		if ( $unsubscribed ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated list IDs */
				__( 'left unsubscribed from list(s) %s', 'fullworks-gravity-to-listmonk' ),
				implode( ', ', $unsubscribed )
			);
		}

		// A new unconfirmed subscription needs its confirmation e-mail. Both the
		// attribute update and the explicit opt-in call send it, so only one runs.
		$wants_optin = $missing && ! $preconfirm;
		$updated     = false;
		$optin_sent  = false;

		if ( $update_existing ) {
			if ( $pending_optin && ! $wants_optin ) {
				$notes[] = __( 'name and attributes not updated because that would re-send a pending double opt-in e-mail', 'fullworks-gravity-to-listmonk' );
			} else {
				$result = $this->client->update_subscriber(
					$id,
					array(
						'email'                    => (string) $subscriber['email'],
						'name'                     => '' !== $name ? $name : (string) ( $subscriber['name'] ?? '' ),
						'status'                   => (string) ( $subscriber['status'] ?? 'enabled' ),
						'attribs'                  => (object) array_merge( self::as_array( $subscriber['attribs'] ?? array() ), $attribs ),
						'lists'                    => array_values( array_unique( array_merge( array_keys( $current ), $missing ) ) ),
						// Never true here: it would confirm every pending double opt-in subscription.
						'preconfirm_subscriptions' => false,
					)
				);
				if ( ! $result['ok'] ) {
					return self::outcome( self::STATUS_ERROR, $id, $result['message'] );
				}
				$updated    = true;
				$optin_sent = $wants_optin;
				$notes[]    = __( 'name and attributes updated', 'fullworks-gravity-to-listmonk' );
			}
		}

		if ( $wants_optin && ! $optin_sent ) {
			$optin = $this->client->send_optin( $id );
			if ( $optin['ok'] ) {
				$optin_sent = true;
			} else {
				$notes[] = sprintf(
					/* translators: %s: error message */
					__( 'opt-in e-mail could not be requested: %s', 'fullworks-gravity-to-listmonk' ),
					$optin['message']
				);
			}
		}

		return self::outcome(
			self::STATUS_EXISTING,
			$id,
			sprintf(
				/* translators: %s: semicolon-separated details of what was done */
				__( 'Listmonk: %s.', 'fullworks-gravity-to-listmonk' ),
				implode( '; ', $notes )
			),
			array(
				'added_lists'        => $missing,
				'unsubscribed_lists' => $unsubscribed,
				'updated'            => $updated,
				'optin_sent'         => $optin_sent,
			)
		);
	}

	/**
	 * Index a subscriber's subscriptions by list ID.
	 *
	 * @param array $subscriber Subscriber from the API.
	 * @return array<int,array{status:string,optin:string}>
	 */
	private static function subscriptions( array $subscriber ): array {
		$out = array();
		foreach ( self::as_array( $subscriber['lists'] ?? array() ) as $list ) {
			if ( is_array( $list ) && ! empty( $list['id'] ) ) {
				$out[ (int) $list['id'] ] = array(
					'status' => (string) ( $list['subscription_status'] ?? '' ),
					'optin'  => (string) ( $list['optin'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Coerce a decoded JSON value to an array.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function as_array( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Build an outcome.
	 *
	 * @param string   $status  One of the STATUS_* constants.
	 * @param int|null $id      Subscriber ID.
	 * @param string   $message Human-readable summary.
	 * @param array    $extra   Extra keys.
	 * @return array
	 */
	private static function outcome( string $status, $id, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'status'             => $status,
				'subscriber_id'      => $id,
				'added_lists'        => array(),
				'unsubscribed_lists' => array(),
				'updated'            => false,
				'optin_sent'         => false,
				'message'            => $message,
			),
			$extra
		);
	}
}
