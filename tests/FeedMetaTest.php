<?php
/**
 * Feed meta parsing.
 *
 * @package FullworksGTL
 */

use FullworksGTL\Feed_Meta;

/**
 * @covers \FullworksGTL\Feed_Meta
 */
class FeedMetaTest extends WP_UnitTestCase {

	public function test_selected_list_ids_from_real_feed_meta_shape() {
		$meta = array(
			'feedName'                                => 'Newsletter',
			'list_1'                                  => '1',
			'list_2'                                  => '0',
			'list_12'                                 => '1',
			'fields_email'                            => '1',
			'preconfirm'                              => '1',
			// Array-valued meta sits alongside the list checkboxes.
			'attributes'                              => array( array( 'key' => 'gf_custom' ) ),
			'feed_condition_conditional_logic_object' => array( 'conditionalLogic' => array() ),
			'list_99'                                 => array( 'not', 'a', 'checkbox' ),
		);

		$this->assertSame( array( 1, 12 ), Feed_Meta::selected_list_ids( $meta ) );
	}

	public function test_selected_list_ids_empty() {
		$this->assertSame( array(), Feed_Meta::selected_list_ids( array() ) );
		$this->assertSame( array(), Feed_Meta::selected_list_ids( null ) );
	}
}
