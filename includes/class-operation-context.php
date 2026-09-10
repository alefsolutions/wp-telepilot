<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Telepilot_Operation_Context {
	private static $active = null;
	public $actor;
	public $site_id;
	public $source;
	public $operation_id;
	public $identity;
	public $reply_message_id = 0;
	public $expected_target = array();
	public $confirmed = false;

	private function __construct( $source, $identity = array() ) {
		$this->actor = wp_get_current_user();
		$this->site_id = get_current_blog_id();
		$this->source = $source;
		$this->identity = $identity;
		$this->operation_id = (string) ( $identity['operation_id'] ?? ( self::$active->operation_id ?? wp_generate_uuid4() ) );
		$this->reply_message_id = (int) ( $identity['reply_message_id'] ?? ( self::$active->reply_message_id ?? 0 ) );
		$this->expected_target = self::$active->expected_target ?? array();
	}

	public static function current() { return self::$active ?: new self( 'wordpress' ); }
	public static function run( $source, $identity, callable $callback, $confirmed = false ) {
		$previous = self::$active;
		self::$active = new self( $source, $identity );
		self::$active->confirmed = $confirmed;
		try { return $callback(); }
		finally { self::$active = $previous; }
	}
}
