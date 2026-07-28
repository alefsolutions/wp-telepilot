<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Telepilot_AI_Provider_Interface {
	public function get_name();

	public function is_configured();

	public function test_connection();

	public function chat( $messages, $tools = array(), $options = array() );
}
