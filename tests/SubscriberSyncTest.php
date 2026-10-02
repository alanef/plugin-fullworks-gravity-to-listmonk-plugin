<?php
/**
 * Create-or-update behaviour against a scripted Listmonk.
 *
 * @package FullworksGTL
 */

use FullworksGTL\Api\Client;
use FullworksGTL\Subscriber_Sync;

require_once __DIR__ . '/includes/class-listmonk-http-mock.php';

/**
 * @covers \FullworksGTL\Subscriber_Sync
 */
class SubscriberSyncTest extends WP_UnitTestCase {

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

	private function sync() {
		return new Subscriber_Sync( new Client( 'https://lists.example.com', 'u', 'k' ) );
	}

	/**
	 * Script an existing subscriber: POST answers 409, the lookup returns them.
	 *
	 * @param array  $lists   Subscriptions: [ id, subscription_status, optin ].
	 * @param string $status  Subscriber status.
	 * @param array  $attribs Existing attributes.
	 */
	private function existing( array $lists, $status = 'enabled', array $attribs = array( 'city' => 'Leeds' ) ) {
		$subscriptions = array();
		foreach ( $lists as $l ) {
			$subscriptions[] = array(
				'id'                  => $l[0],
				'subscription_status' => $l[1],
				'optin'               => $l[2],
			);
		}
		$this->http
			->on( 'POST', 'subscribers', 409, null, 'E-mail already exists.' )
			->on(
				'GET',
				'subscribers',
				200,
				array(
					'results' => array(
						array(
							'id'      => 42,
							'email'   => 'jo@example.com',
							'name'    => 'Jo Old',
							'status'  => $status,
							'attribs' => $attribs,
							'lists'   => $subscriptions,
						),
					),
				)
			)
			->on( 'PUT', 'subscribers/lists', 200, true )
			->on( 'PUT', 'subscribers/42', 200, array( 'id' => 42 ) )
			->on( 'POST', 'subscribers/42/optin', 200, true );
	}

	public function test_new_subscriber_is_created_in_one_request() {
		$this->http->on( 'POST', 'subscribers', 200, array( 'id' => 9 ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array( 'city' => 'York' ), array( 3, '4', 3 ), array( 'preconfirm' => false ) );

		$this->assertSame( Subscriber_Sync::STATUS_CREATED, $out['status'] );
		$this->assertSame( 9, $out['subscriber_id'] );
		$this->assertCount( 1, $this->http->requests );
		$body = $this->http->requests[0]['body'];
		$this->assertSame( array( 3, 4 ), $body['lists'] );
		$this->assertSame( array( 'city' => 'York' ), $body['attribs'] );
		$this->assertFalse( $body['preconfirm_subscriptions'] );
		$this->assertSame( 'enabled', $body['status'] );
	}

	public function test_empty_attribs_are_sent_as_json_object() {
		$this->http->on( 'POST', 'subscribers', 200, array( 'id' => 9 ) );

		$this->sync()->subscribe( 'jo@example.com', '', array(), array( 3 ) );

		// Listmonk rejects "attribs": [] - it must be a JSON object.
		$this->assertStringContainsString( '"attribs":{}', $this->http->requests[0]['raw'] );
	}

	public function test_invalid_email_is_skipped_without_calling_listmonk() {
		$out = $this->sync()->subscribe( 'not-an-email', 'Jo', array(), array( 3 ) );

		$this->assertSame( Subscriber_Sync::STATUS_SKIPPED, $out['status'] );
		$this->assertEmpty( $this->http->requests );
	}

	public function test_no_lists_is_skipped() {
		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array() );

		$this->assertSame( Subscriber_Sync::STATUS_SKIPPED, $out['status'] );
		$this->assertEmpty( $this->http->requests );
	}

	public function test_create_error_other_than_conflict_is_an_error() {
		$this->http->on( 'POST', 'subscribers', 400, null, 'invalid email' );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ) );

		$this->assertSame( Subscriber_Sync::STATUS_ERROR, $out['status'] );
		$this->assertStringContainsString( 'invalid email', $out['message'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_existing_subscriber_only_gets_missing_lists_added_and_optin_sent() {
		$this->existing( array( array( 1, 'confirmed', 'single' ), array( 3, 'confirmed', 'double' ) ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo New', array( 'city' => 'York' ), array( 3, 5 ) );

		$this->assertSame( Subscriber_Sync::STATUS_EXISTING, $out['status'] );
		$this->assertSame( array( 5 ), $out['added_lists'] );
		$add = $this->http->calls( 'PUT', 'subscribers/lists' );
		$this->assertCount( 1, $add );
		$this->assertSame( array( 5 ), $add[0]['body']['target_list_ids'] );
		$this->assertSame( 'unconfirmed', $add[0]['body']['status'] );
		$this->assertCount( 1, $this->http->calls( 'POST', 'subscribers/42/optin' ) );
		$this->assertTrue( $out['optin_sent'] );
		// Name/attribs untouched by default: the destructive PUT is never called.
		$this->assertCount( 0, $this->http->calls( 'PUT', 'subscribers/42' ) );
	}

	public function test_existing_subscriber_already_on_all_lists_makes_no_changes() {
		$this->existing( array( array( 3, 'confirmed', 'double' ) ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ) );

		$this->assertSame( Subscriber_Sync::STATUS_EXISTING, $out['status'] );
		$this->assertCount( 0, $this->http->calls( 'PUT', 'subscribers/lists' ) );
		$this->assertCount( 0, $this->http->calls( 'POST', 'subscribers/42/optin' ) );
		$this->assertStringContainsString( 'already on every selected list', $out['message'] );
	}

	public function test_unsubscribed_list_is_not_resubscribed() {
		$this->existing( array( array( 3, 'unsubscribed', 'double' ) ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ) );

		$this->assertSame( array( 3 ), $out['unsubscribed_lists'] );
		$this->assertSame( array(), $out['added_lists'] );
		$this->assertCount( 0, $this->http->calls( 'PUT', 'subscribers/lists' ) );
		$this->assertStringContainsString( 'left unsubscribed', $out['message'] );
	}

	public function test_blocklisted_subscriber_is_left_alone() {
		$this->existing( array(), 'blocklisted' );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ), array( 'update_existing' => true ) );

		$this->assertSame( Subscriber_Sync::STATUS_SKIPPED, $out['status'] );
		$this->assertSame( 42, $out['subscriber_id'] );
		$this->assertCount( 2, $this->http->requests, 'Only the create attempt and the lookup' );
	}

	public function test_preconfirm_adds_confirmed_and_sends_no_optin() {
		$this->existing( array() );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ), array( 'preconfirm' => true ) );

		$this->assertSame( 'confirmed', $this->http->calls( 'PUT', 'subscribers/lists' )[0]['body']['status'] );
		$this->assertCount( 0, $this->http->calls( 'POST', 'subscribers/42/optin' ) );
		$this->assertFalse( $out['optin_sent'] );
	}

	public function test_update_existing_merges_attribs_and_keeps_every_list() {
		$this->existing( array( array( 1, 'confirmed', 'single' ), array( 2, 'unsubscribed', 'single' ) ), 'enabled', array( 'city' => 'Leeds', 'plan' => 'pro' ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo New', array( 'city' => 'York' ), array( 3 ), array( 'update_existing' => true ) );

		$this->assertTrue( $out['updated'] );
		$put = $this->http->calls( 'PUT', 'subscribers/42' );
		$this->assertCount( 1, $put );
		$body = $put[0]['body'];
		$this->assertSame(
			array(
				'city' => 'York',
				'plan' => 'pro',
			),
			$body['attribs']
		);
		$this->assertSame( array( 1, 2, 3 ), $body['lists'], 'Lists not sent would be deleted by Listmonk' );
		$this->assertFalse( $body['preconfirm_subscriptions'] );
		$this->assertSame( 'Jo New', $body['name'] );
		$this->assertSame( 'enabled', $body['status'] );
		// The PUT sends the opt-in e-mail for the new list itself; no second e-mail.
		$this->assertCount( 0, $this->http->calls( 'POST', 'subscribers/42/optin' ) );
		$this->assertTrue( $out['optin_sent'] );
	}

	public function test_update_existing_keeps_name_when_none_submitted() {
		$this->existing( array( array( 3, 'confirmed', 'single' ) ) );

		$this->sync()->subscribe( 'jo@example.com', '', array( 'a' => 'b' ), array( 3 ), array( 'update_existing' => true ) );

		$this->assertSame( 'Jo Old', $this->http->calls( 'PUT', 'subscribers/42' )[0]['body']['name'] );
	}

	public function test_update_skipped_when_it_would_resend_pending_optin() {
		$this->existing( array( array( 3, 'unconfirmed', 'double' ) ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo New', array( 'city' => 'York' ), array( 3 ), array( 'update_existing' => true ) );

		$this->assertFalse( $out['updated'] );
		$this->assertCount( 0, $this->http->calls( 'PUT', 'subscribers/42' ) );
		$this->assertStringContainsString( 're-send a pending double opt-in', $out['message'] );
	}

	public function test_update_runs_with_pending_optin_when_a_new_list_needs_one_anyway() {
		$this->existing( array( array( 3, 'unconfirmed', 'double' ) ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo New', array(), array( 3, 4 ), array( 'update_existing' => true ) );

		$this->assertTrue( $out['updated'] );
		$this->assertCount( 1, $this->http->calls( 'PUT', 'subscribers/42' ) );
		$this->assertCount( 0, $this->http->calls( 'POST', 'subscribers/42/optin' ) );
	}

	public function test_conflict_but_lookup_empty_is_an_error() {
		$this->http
			->on( 'POST', 'subscribers', 409, null, 'E-mail already exists.' )
			->on( 'GET', 'subscribers', 200, array( 'results' => array() ) );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ) );

		$this->assertSame( Subscriber_Sync::STATUS_ERROR, $out['status'] );
	}

	public function test_failed_list_add_is_an_error() {
		$this->http
			->on( 'POST', 'subscribers', 409, null, 'exists' )
			->on(
				'GET',
				'subscribers',
				200,
				array(
					'results' => array(
						array(
							'id'     => 42,
							'email'  => 'jo@example.com',
							'status' => 'enabled',
							'lists'  => array(),
						),
					),
				)
			)
			->on( 'PUT', 'subscribers/lists', 403, null, 'no permission' );

		$out = $this->sync()->subscribe( 'jo@example.com', 'Jo', array(), array( 3 ) );

		$this->assertSame( Subscriber_Sync::STATUS_ERROR, $out['status'] );
		$this->assertSame( 42, $out['subscriber_id'] );
	}
}
