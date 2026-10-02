<?php
/**
 * End-to-end scenarios against a real Listmonk. Run via tests/e2e/run.sh, not PHPUnit.
 *
 * wp eval-file scenarios.php <token> <step> -- each step runs in its own process because
 * Gravity Forms caches the current entry statically, so a second GFAPI::submit_form()
 * in the same request would reuse the first submission's values.
 *
 * phpcs:ignoreFile
 */
global $fwe;
$fwe = array(
	'token' => $args[0],
	'base'  => 'http://fwgtl-listmonk:9000',
	'fails' => 0,
);
$step = $args[1];

function lm( $method, $path, $body = null ) {
	global $fwe;
	$r = wp_remote_request( $fwe['base'] . '/api/' . $path, array(
		'method'  => $method,
		'headers' => array( 'Authorization' => 'token wpapi:' . $fwe['token'], 'Content-Type' => 'application/json' ),
		'body'    => null === $body ? null : wp_json_encode( $body ),
	) );
	return json_decode( wp_remote_retrieve_body( $r ), true );
}
function sub( $email ) {
	$r = lm( 'GET', 'subscribers?per_page=1&query=' . rawurlencode( "subscribers.email='$email'" ) );
	$s = $r['data']['results'][0] ?? null;
	if ( ! $s ) { return null; }
	$lists = array();
	foreach ( $s['lists'] as $l ) { $lists[ $l['id'] ] = $l['subscription_status']; }
	ksort( $lists );
	return array( 'status' => $s['status'], 'name' => $s['name'], 'attribs' => $s['attribs'], 'lists' => $lists );
}
function check( $label, $cond, $debug = null ) {
	global $fwe;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fwe['fails']++;
		echo '     ' . wp_json_encode( $debug ) . "\n";
	}
}
function notes( $entry_id ) {
	$out = array();
	foreach ( (array) GFAPI::get_notes( array( 'entry_id' => $entry_id ) ) as $n ) { if ( is_object( $n ) ) { $out[] = $n->value; } }
	return implode( ' | ', $out );
}
function state() { return get_option( 'fwgtl_e2e' ); }
function em( $n ) { return $n . state()['sfx'] . '@example.org'; }
function submit( $email, $first, $city, $consent = true ) {
	$in = array( 'input_1' => $email, 'input_2_3' => $first, 'input_2_6' => 'Tester', 'input_3' => $city );
	if ( $consent ) { $in['input_4_1'] = 'yes'; }
	$r = GFAPI::submit_form( state()['form_id'], $in );
	if ( is_wp_error( $r ) || empty( $r['entry_id'] ) ) {
		echo 'SUBMIT FAILED ' . wp_json_encode( is_wp_error( $r ) ? $r->get_error_messages() : $r['validation_messages'] ) . "\n";
		return 0;
	}
	return $r['entry_id'];
}

$addon = FullworksGTL\GF\Feed_AddOn::get_instance();

switch ( $step ) {
	case 'setup':
		$addon->update_plugin_settings( array( 'listmonk_url' => $fwe['base'] . '/admin/', 'api_user' => 'wpapi', 'api_key' => $fwe['token'] ) );
		check( 'URL normalised on save', $fwe['base'] === $addon->get_plugin_setting( 'listmonk_url' ), $addon->get_plugin_setting( 'listmonk_url' ) );
		$lists = $addon->get_client()->get_lists();
		check( 'connection test lists 3 lists', $lists['ok'] && 3 === count( $lists['data'] ), $lists );
		$form_id = GFAPI::add_form( array(
			'title'  => 'E2E ' . time(),
			'fields' => array(
				array( 'id' => 1, 'type' => 'email', 'label' => 'Email' ),
				array( 'id' => 2, 'type' => 'name', 'label' => 'Name', 'nameFormat' => 'advanced', 'inputs' => array(
					array( 'id' => '2.3', 'label' => 'First' ), array( 'id' => '2.6', 'label' => 'Last' ) ) ),
				array( 'id' => 3, 'type' => 'text', 'label' => 'City' ),
				array( 'id' => 4, 'type' => 'checkbox', 'label' => 'Consent', 'choices' => array( array( 'text' => 'Yes', 'value' => 'yes' ) ), 'inputs' => array( array( 'id' => '4.1', 'label' => 'Yes' ) ) ),
			),
		) );
		$meta = array(
			'feedName'        => 'Newsletter',
			'list_1'          => '1',
			'list_2'          => '1',
			'fields_email'    => '1',
			'fields_name'     => '2',
			'attributes'      => array(
				array( 'key' => 'gf_custom', 'custom_key' => 'city', 'value' => '3', 'custom_value' => '' ),
				array( 'key' => 'gf_custom', 'custom_key' => 'source', 'value' => 'gf_custom', 'custom_value' => 'form {form_id}' ),
			),
			'preconfirm'      => '0',
			'update_existing' => '0',
			'feed_condition_conditional_logic'        => '1',
			'feed_condition_conditional_logic_object' => array( 'conditionalLogic' => array(
				'actionType' => 'show', 'logicType' => 'all',
				'rules'      => array( array( 'fieldId' => '4', 'operator' => 'is', 'value' => 'yes' ) ) ) ),
		);
		$feed_id = GFAPI::add_feed( $form_id, $meta, 'fullworks-gravity-to-listmonk' );
		update_option( 'fwgtl_e2e', array( 'form_id' => $form_id, 'feed_id' => $feed_id, 'meta' => $meta, 'sfx' => wp_rand( 10000, 99999 ) ) );
		// Pre-existing subscribers for later steps.
		lm( 'POST', 'subscribers', array( 'email' => em( 'bob' ), 'name' => 'Bob Orig', 'lists' => array( 3 ), 'attribs' => array( 'plan' => 'pro' ), 'preconfirm_subscriptions' => true ) );
		$c = lm( 'POST', 'subscribers', array( 'email' => em( 'carol' ), 'name' => 'Carol', 'lists' => array( 2 ), 'preconfirm_subscriptions' => true ) );
		lm( 'PUT', 'subscribers/lists', array( 'ids' => array( $c['data']['id'] ), 'action' => 'unsubscribe', 'target_list_ids' => array( 2 ) ) );
		lm( 'POST', 'subscribers', array( 'email' => em( 'dave' ), 'name' => 'Dave', 'status' => 'blocklisted', 'lists' => array() ) );
		lm( 'POST', 'subscribers', array( 'email' => em( 'erin' ), 'name' => 'Erin Orig', 'lists' => array( 1, 2, 3 ), 'attribs' => array( 'plan' => 'pro', 'city' => 'Hull' ), 'preconfirm_subscriptions' => true ) );
		echo 'SUFFIX ' . state()['sfx'] . "\n";
		break;

	case 'a':
		$id = submit( em( 'alice' ), 'Alice', 'Leeds' );
		$s  = sub( em( 'alice' ) );
		check( 'a) new subscriber created on lists 1+2 unconfirmed', $s && array( 1 => 'unconfirmed', 2 => 'unconfirmed' ) === $s['lists'], $s );
		check( 'a) full name mapped', $s && 'Alice Tester' === $s['name'], $s );
		check( 'a) attribs city + merge-tag source', $s && 'Leeds' === $s['attribs']['city'] && 'form ' . state()['form_id'] === $s['attribs']['source'], $s );
		check( 'a) entry note says created', false !== strpos( notes( $id ), 'new subscriber created' ), notes( $id ) );
		check( 'a) subscriber id in entry meta', (bool) gform_get_meta( $id, 'fwgtl_subscriber_id' ) );
		break;

	case 'b':
		$id = submit( em( 'nocon' ), 'No', 'Hull', false );
		check( 'b) no consent -> feed not run', $id && null === sub( em( 'nocon' ) ) && '' === notes( $id ), notes( $id ) );
		break;

	case 'c':
		$id = submit( em( 'alice' ), 'Alice', 'York' );
		check( 'c) repeat: already on every list', false !== strpos( notes( $id ), 'already on every selected list' ), notes( $id ) );
		check( 'c) repeat: attribs untouched (update off)', 'Leeds' === sub( em( 'alice' ) )['attribs']['city'], sub( em( 'alice' ) ) );
		break;

	case 'd':
		$id = submit( em( 'bob' ), 'Bob', 'York' );
		$s  = sub( em( 'bob' ) );
		check( 'd) existing: list 3 kept confirmed, 1+2 added', array( 1 => 'unconfirmed', 2 => 'unconfirmed', 3 => 'confirmed' ) === $s['lists'], $s );
		check( 'd) existing: name/attribs untouched', 'Bob Orig' === $s['name'] && array( 'plan' => 'pro' ) === $s['attribs'], $s );
		check( 'd) note says added', false !== strpos( notes( $id ), 'added to list(s) 1, 2' ), notes( $id ) );
		break;

	case 'e':
		$id = submit( em( 'carol' ), 'Carol', 'York' );
		$s  = sub( em( 'carol' ) );
		check( 'e) unsubscribed list stays unsubscribed, list 1 added', array( 1 => 'unconfirmed', 2 => 'unsubscribed' ) === $s['lists'], $s );
		check( 'e) note says left unsubscribed', false !== strpos( notes( $id ), 'left unsubscribed' ), notes( $id ) );
		break;

	case 'f':
		$id = submit( em( 'dave' ), 'Dave', 'York' );
		$s  = sub( em( 'dave' ) );
		check( 'f) blocklisted untouched', 'blocklisted' === $s['status'] && array() === $s['lists'], $s );
		check( 'f) note says blocklisted', false !== strpos( notes( $id ), 'blocklisted' ), notes( $id ) );
		break;

	case 'g':
		$meta                    = state()['meta'];
		$meta['update_existing'] = '1';
		GFAPI::update_feed( state()['feed_id'], $meta, state()['form_id'] );
		$id = submit( em( 'erin' ), 'Erin', 'York' );
		$s  = sub( em( 'erin' ) );
		check( 'g) update: attribs merged', 'pro' === $s['attribs']['plan'] && 'York' === $s['attribs']['city'] && isset( $s['attribs']['source'] ), $s );
		check( 'g) update: name updated', 'Erin Tester' === $s['name'], $s );
		check( 'g) update: all 3 lists kept confirmed', array( 1 => 'confirmed', 2 => 'confirmed', 3 => 'confirmed' ) === $s['lists'], $s );
		break;

	case 'h':
		$id = submit( em( 'alice' ), 'Alicia', 'Bath' );
		$s  = sub( em( 'alice' ) );
		check( 'h) pending DOI: update skipped', 'Alice Tester' === $s['name'] && 'Leeds' === $s['attribs']['city'], $s );
		check( 'h) note explains skip', false !== strpos( notes( $id ), 're-send a pending double opt-in' ), notes( $id ) );
		break;

	case 'i':
		$addon->update_plugin_settings( array( 'listmonk_url' => $fwe['base'], 'api_user' => 'wpapi', 'api_key' => 'wrong' ) );
		$id = submit( em( 'zed' ), 'Zed', 'York' );
		check( 'i) bad key: entry saved with error note', $id && false !== strpos( notes( $id ), 'rejected the API credentials' ), notes( $id ) );
		$addon->update_plugin_settings( array( 'listmonk_url' => $fwe['base'], 'api_user' => 'wpapi', 'api_key' => $fwe['token'] ) );
		break;
}

if ( $fwe['fails'] ) {
	echo "STEP $step FAILED\n";
}
