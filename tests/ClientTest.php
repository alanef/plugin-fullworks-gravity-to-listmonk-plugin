<?php
/**
 * Listmonk API client.
 *
 * @package FullworksGTL
 */

use FullworksGTL\Api\Client;

require_once __DIR__ . '/includes/class-listmonk-http-mock.php';

/**
 * @covers \FullworksGTL\Api\Client
 */
class ClientTest extends WP_UnitTestCase {

	/**
	 * HTTP mock.
	 *
	 * @var FWGTL_Listmonk_Http_Mock
	 */
	private $http;

	public function set_up() {
		parent::set_up();
		$this->http = new FWGTL_Listmonk_Http_Mock();
	}

	public function tear_down() {
		$this->http->remove();
		parent::tear_down();
	}

	private function client() {
		return new Client( 'https://lists.example.com', 'wpuser', 'secret-token' );
	}

	/**
	 * @dataProvider url_provider
	 */
	public function test_normalise_url( $raw, $expected ) {
		$this->assertSame( $expected, Client::normalise_url( $raw ) );
	}

	public function url_provider() {
		return array(
			'root'           => array( 'https://lists.example.com', 'https://lists.example.com' ),
			'trailing slash' => array( 'https://lists.example.com/', 'https://lists.example.com' ),
			'admin url'      => array( 'https://lists.example.com/admin/', 'https://lists.example.com' ),
			'api url'        => array( ' https://lists.example.com/api ', 'https://lists.example.com' ),
			'sub-directory'  => array( 'https://example.com/listmonk/', 'https://example.com/listmonk' ),
		);
	}

	public function test_unconfigured_client_makes_no_request() {
		$client = new Client( 'https://lists.example.com', '', 'key' );
		$this->assertFalse( $client->is_configured() );
		$result = $client->get_lists();
		$this->assertFalse( $result['ok'] );
		$this->assertEmpty( $this->http->requests );
	}

	public function test_sends_token_auth_header_and_unwraps_lists() {
		$this->http->on(
			'GET',
			'lists',
			200,
			array(
				'results' => array(
					array(
						'id'   => 3,
						'name' => 'News',
					),
				),
				'total'   => 1,
			)
		);

		$result = $this->client()->get_lists();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'News', $result['data'][0]['name'] );
		$this->assertSame( 'token wpuser:secret-token', $this->http->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'all', $this->http->requests[0]['query']['per_page'] );
		$this->assertStringStartsWith( 'https://lists.example.com/api/lists?', $this->http->requests[0]['url'] );
	}

	public function test_find_by_email_quotes_and_lowercases() {
		$this->http->on( 'GET', 'subscribers', 200, array( 'results' => array() ) );

		$result = $this->client()->find_subscriber_by_email( " O'Brien@Example.com " );

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['data'] );
		$this->assertSame( "LOWER(subscribers.email) = 'o''brien@example.com'", $this->http->requests[0]['query']['query'] );
	}

	public function test_auth_failure_message() {
		$this->http->on( 'GET', 'lists', 403, null, 'permission denied' );

		$result = $this->client()->get_lists();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 403, $result['code'] );
		$this->assertStringContainsString( 'rejected the API credentials', $result['message'] );
	}

	public function test_conflict_keeps_code_and_detail() {
		$this->http->on( 'POST', 'subscribers', 409, null, 'E-mail already exists.' );

		$result = $this->client()->create_subscriber( array( 'email' => 'a@example.com' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 409, $result['code'] );
		$this->assertStringContainsString( 'E-mail already exists.', $result['message'] );
	}

	public function test_transport_error() {
		$this->http->on( 'GET', 'lists', 'transport' );

		$result = $this->client()->get_lists();

		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['code'] );
		$this->assertStringContainsString( 'Could not reach Listmonk', $result['message'] );
	}

	public function test_add_to_lists_payload() {
		$this->http->on( 'PUT', 'subscribers/lists', 200, true );

		$this->client()->add_subscriber_to_lists( 7, array( '2', 5 ), 'unconfirmed' );

		$this->assertSame(
			array(
				'ids'             => array( 7 ),
				'action'          => 'add',
				'target_list_ids' => array( 2, 5 ),
				'status'          => 'unconfirmed',
			),
			$this->http->requests[0]['body']
		);
	}
}
