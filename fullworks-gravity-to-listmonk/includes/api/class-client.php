<?php
/**
 * Listmonk REST API client.
 *
 * @package FullworksGTL
 */

namespace FullworksGTL\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request() for the Listmonk API.
 *
 * Every call returns the same result shape so callers never have to deal with
 * WP_Error or raw HTTP responses:
 *
 *     array{ ok: bool, code: int|null, data: mixed, message: string }
 *
 * `data` is the unwrapped `data` key of Listmonk's `{ "data": ... }` envelope.
 */
class Client {

	/**
	 * Listmonk root URL, without a trailing slash or /api suffix.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API user name.
	 *
	 * @var string
	 */
	private $api_user;

	/**
	 * API user token.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Listmonk root URL, e.g. https://lists.example.com.
	 * @param string $api_user API user name (Admin → Users → API user).
	 * @param string $api_key  API token for that user.
	 */
	public function __construct( string $base_url, string $api_user, string $api_key ) {
		$this->base_url = self::normalise_url( $base_url );
		$this->api_user = trim( $api_user );
		$this->api_key  = trim( $api_key );
	}

	/**
	 * Normalise a user-entered Listmonk URL to its root.
	 *
	 * People paste the admin URL or the API URL as often as the root, so a
	 * trailing /admin or /api (and any trailing slash) is stripped.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalise_url( string $url ): string {
		$url = trim( $url );
		$url = preg_replace( '#/+$#', '', $url );
		$url = preg_replace( '#/(api|admin)$#i', '', $url );
		return untrailingslashit( (string) $url );
	}

	/**
	 * Whether URL, user and key are all present.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->base_url && '' !== $this->api_user && '' !== $this->api_key;
	}

	/**
	 * Fetch every list the API user can see.
	 *
	 * @return array{ok:bool,code:int|null,data:mixed,message:string} `data` is a list of list objects.
	 */
	public function get_lists(): array {
		$result = $this->request( 'GET', 'lists', array( 'per_page' => 'all' ) );
		if ( $result['ok'] ) {
			$result['data'] = ( is_array( $result['data'] ) && isset( $result['data']['results'] ) && is_array( $result['data']['results'] ) )
				? $result['data']['results']
				: array();
		}
		return $result;
	}

	/**
	 * Create a subscriber.
	 *
	 * Listmonk answers 409 when the e-mail already exists; callers use that to
	 * switch to the existing-subscriber path.
	 *
	 * @param array $subscriber Payload: email, name, status, lists, attribs, preconfirm_subscriptions.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string} `data` is the subscriber.
	 */
	public function create_subscriber( array $subscriber ): array {
		return $this->request( 'POST', 'subscribers', null, $subscriber );
	}

	/**
	 * Look a subscriber up by e-mail.
	 *
	 * Listmonk has no lookup-by-email endpoint, so this uses the SQL `query`
	 * filter. The address is compared case-insensitively (Listmonk's unique
	 * index is on LOWER(email)) and single quotes are doubled so the value
	 * stays a string literal.
	 *
	 * @param string $email E-mail address.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string} `data` is the subscriber array, or null when not found.
	 */
	public function find_subscriber_by_email( string $email ): array {
		$literal = str_replace( "'", "''", strtolower( trim( $email ) ) );
		$result  = $this->request(
			'GET',
			'subscribers',
			array(
				'query'    => "LOWER(subscribers.email) = '" . $literal . "'",
				'per_page' => 1,
			)
		);
		if ( $result['ok'] ) {
			$results        = ( is_array( $result['data'] ) && isset( $result['data']['results'] ) && is_array( $result['data']['results'] ) )
				? $result['data']['results']
				: array();
			$result['data'] = isset( $results[0] ) && is_array( $results[0] ) ? $results[0] : null;
		}
		return $result;
	}

	/**
	 * Add a subscriber to lists without touching any other subscription.
	 *
	 * PUT /api/subscribers/lists with action=add inserts missing
	 * subscriptions and, when `$status` is empty, leaves the status of
	 * existing ones alone, so an unsubscribe is never silently undone.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int[]  $list_ids      Lists to add.
	 * @param string $status        'confirmed', 'unconfirmed' or '' to keep existing.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string}
	 */
	public function add_subscriber_to_lists( int $subscriber_id, array $list_ids, string $status = '' ): array {
		$body = array(
			'ids'             => array( $subscriber_id ),
			'action'          => 'add',
			'target_list_ids' => array_values( array_map( 'intval', $list_ids ) ),
		);
		if ( '' !== $status ) {
			$body['status'] = $status;
		}
		return $this->request( 'PUT', 'subscribers/lists', null, $body );
	}

	/**
	 * Replace a subscriber's core fields.
	 *
	 * Beware: PUT /api/subscribers/:id replaces attribs wholesale, deletes
	 * every subscription whose list is not in `lists`, and re-sends the
	 * double opt-in e-mail for unconfirmed double opt-in lists. Callers must
	 * pass the full merged attribs and full list set.
	 *
	 * @param int   $subscriber_id Subscriber ID.
	 * @param array $subscriber    Payload: email, name, status, attribs, lists, preconfirm_subscriptions.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string}
	 */
	public function update_subscriber( int $subscriber_id, array $subscriber ): array {
		return $this->request( 'PUT', 'subscribers/' . $subscriber_id, null, $subscriber );
	}

	/**
	 * Send the double opt-in confirmation e-mail.
	 *
	 * Listmonk only e-mails when the subscriber has an unconfirmed
	 * subscription to a double opt-in list, so this is a no-op otherwise.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string}
	 */
	public function send_optin( int $subscriber_id ): array {
		return $this->request( 'POST', 'subscribers/' . $subscriber_id . '/optin', null, new \stdClass() );
	}

	/**
	 * Perform a request and normalise the response.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below /api/.
	 * @param array|null $query  Query-string arguments.
	 * @param mixed      $body   JSON body, or null for none.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string}
	 */
	private function request( string $method, string $path, $query = null, $body = null ): array {
		if ( ! $this->is_configured() ) {
			return self::result( false, null, null, __( 'Listmonk URL, API user and API key must all be set.', 'fullworks-gravity-to-listmonk' ) );
		}

		$url = $this->base_url . '/api/' . $path;
		if ( is_array( $query ) && $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'token ' . $this->api_user . ':' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		/**
		 * Filter the HTTP arguments for a Listmonk API request.
		 *
		 * @param array  $args   wp_remote_request() arguments.
		 * @param string $method HTTP method.
		 * @param string $path   API path below /api/.
		 */
		$args = apply_filters( 'fwgtl_http_args', $args, $method, $path );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return self::result(
				false,
				null,
				null,
				sprintf(
					/* translators: %s: transport error message */
					__( 'Could not reach Listmonk: %s', 'fullworks-gravity-to-listmonk' ),
					$response->get_error_message()
				)
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data    = ( is_array( $decoded ) && array_key_exists( 'data', $decoded ) ) ? $decoded['data'] : null;

		if ( $code >= 200 && $code < 300 ) {
			return self::result( true, $code, $data, '' );
		}

		$detail = ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) ? $decoded['message'] : '';

		if ( 401 === $code || 403 === $code ) {
			$message = __( 'Listmonk rejected the API credentials, or the API user lacks permission for this action.', 'fullworks-gravity-to-listmonk' );
		} elseif ( 404 === $code && '' === $detail ) {
			$message = __( 'Listmonk API endpoint not found. Check the Listmonk URL.', 'fullworks-gravity-to-listmonk' );
		} else {
			$message = sprintf(
				/* translators: 1: HTTP status code, 2: error detail returned by Listmonk */
				__( 'Listmonk returned HTTP %1$d. %2$s', 'fullworks-gravity-to-listmonk' ),
				$code,
				$detail
			);
		}

		return self::result( false, $code, $data, trim( $message . ( $detail && false === strpos( $message, $detail ) ? ' ' . $detail : '' ) ) );
	}

	/**
	 * Build a normalised result.
	 *
	 * @param bool     $ok      Success.
	 * @param int|null $code    HTTP status.
	 * @param mixed    $data    Unwrapped data.
	 * @param string   $message Human-readable message.
	 * @return array{ok:bool,code:int|null,data:mixed,message:string}
	 */
	private static function result( bool $ok, $code, $data, string $message ): array {
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'data'    => $data,
			'message' => $message,
		);
	}
}
