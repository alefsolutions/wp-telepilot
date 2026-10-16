<?php
class TelegramActivityTest extends Telepilot_TestCase {
	private function client() {
		return new class {
			public $calls = array();
			public $on_send;
			public function send_chat_action( $chat_id, $action ) {
				$this->calls[] = array( $chat_id, $action );
				return $this->on_send ? ( $this->on_send )() : array( 'ok' => true );
			}
		};
	}

	public function test_scoped_refresh_is_throttled_and_reentrant_safe() {
		$now = 100.0;
		$client = $this->client();
		$client->on_send = static function () { Telepilot_Telegram_Activity::pulse(); return array( 'ok' => true ); };
		$activity = new Telepilot_Telegram_Activity( $client, '123', static function () use ( &$now ) { return $now; } );
		$result = $activity->run( function () use ( &$now, $client ) {
			Telepilot_Telegram_Activity::pulse();
			$now = 103.99;
			Telepilot_Telegram_Activity::pulse();
			$this->assertCount( 1, $client->calls );
			$now = 104.0;
			Telepilot_Telegram_Activity::pulse();
			$this->assertCount( 2, $client->calls );
			return 'operation result';
		} );
		$this->assertSame( 'operation result', $result );
		$now = 110.0;
		Telepilot_Telegram_Activity::pulse();
		$activity->refresh();
		$this->assertCount( 2, $client->calls );
		$this->assertFalse( has_action( 'http_api_curl', array( $activity, 'attach_progress' ) ) );
	}

	/** @dataProvider failures */
	public function test_feedback_failure_stops_attempts_but_not_work( $throws ) {
		$now = 0;
		$client = $this->client();
		$client->on_send = static function () use ( $throws ) {
			if ( $throws ) { throw new RuntimeException( 'Feedback failed' ); }
			return new WP_Error( 'feedback_failed', 'Feedback failed' );
		};
		$activity = new Telepilot_Telegram_Activity( $client, '123', static function () use ( &$now ) { return $now; } );
		$this->assertSame( 'finished', $activity->run( static function () use ( &$now ) {
			$now = 10;
			Telepilot_Telegram_Activity::pulse();
			return 'finished';
		} ) );
		$this->assertCount( 1, $client->calls );
		$this->assertFalse( has_action( 'http_api_curl', array( $activity, 'attach_progress' ) ) );
	}

	public function failures() { return array( 'WP_Error' => array( false ), 'exception' => array( true ) ); }

	public function test_work_exception_cleans_up_and_later_execution_can_start() {
		$client = $this->client();
		$activity = new Telepilot_Telegram_Activity( $client, '123' );
		try {
			$activity->run( static function () { throw new RuntimeException( 'Operation failed' ); } );
			$this->fail( 'Operation exceptions must propagate.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Operation failed', $error->getMessage() );
		}
		$this->assertFalse( has_action( 'http_api_curl', array( $activity, 'attach_progress' ) ) );
		Telepilot_Telegram_Activity::pulse();
		$this->assertCount( 1, $client->calls );
		$activity->run( static function () {} );
		$this->assertCount( 2, $client->calls );
	}

	/** @dataProvider transfers */
	public function test_curl_progress_refresh_and_excluded_requests( $url, $blocking, $expected, $fail_refresh = false ) {
		if ( ! function_exists( 'curl_init' ) || ( ! defined( 'CURLOPT_XFERINFOFUNCTION' ) && ! defined( 'CURLOPT_PROGRESSFUNCTION' ) ) ) { $this->markTestSkipped( 'cURL progress is unavailable.' ); }
		$now = 0;
		$client = $this->client();
		if ( $fail_refresh ) {
			$client->on_send = static function () use ( $client ) { return count( $client->calls ) > 1 ? new WP_Error( 'fixture_feedback', 'Feedback failed during transfer' ) : array( 'ok' => true ); };
		}
		$activity = new Telepilot_Telegram_Activity( $client, '123', static function () use ( &$now ) { return $now; } );
		// Exercise a real cURL progress callback using a local file, with no network.
		$handle = curl_init( 'file:///' . str_replace( ' ', '%20', ltrim( str_replace( '\\', '/', __FILE__ ), '/' ) ) );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
		$activity->run( function () use ( &$now, $handle, $url, $blocking ) {
			if ( 'loopback' === $url ) { $url = rest_url( 'telepilot/v1/process-jobs' ); }
			do_action_ref_array( 'http_api_curl', array( &$handle, array( 'blocking' => $blocking ), $url ) );
			$now = 4;
			$this->assertSame( file_get_contents( __FILE__ ), curl_exec( $handle ), curl_error( $handle ) );
		} );
		$this->assertCount( $expected, $client->calls );
		$now = 8;
		curl_exec( $handle );
		$this->assertCount( $expected, $client->calls, 'Retained callbacks must be inactive after execution.' );
		$this->assertFalse( has_action( 'http_api_curl', array( $activity, 'attach_progress' ) ) );
	}

	public function transfers() {
		return array(
			'provider' => array( 'https://api.openai.com/v1/chat/completions', true, 2 ),
			'feedback failure during transfer' => array( 'https://api.openai.com/v1/chat/completions', true, 2, true ),
			'download' => array( 'https://downloads.wordpress.org/plugin/fixture.zip', true, 2 ),
			'getFile' => array( 'https://api.telegram.org/botfixture/getFile', true, 2 ),
			'feedback' => array( 'https://api.telegram.org/botfixture/sendChatAction', true, 1 ),
			'send' => array( 'https://api.telegram.org/botfixture/sendMessage', true, 1 ),
			'edit' => array( 'https://api.telegram.org/botfixture/editMessageText', true, 1 ),
			'acknowledgement' => array( 'https://api.telegram.org/botfixture/answerCallbackQuery', true, 1 ),
			'loopback' => array( 'loopback', true, 1 ),
			'nonblocking' => array( 'https://downloads.wordpress.org/plugin/fixture.zip', false, 1 ),
		);
	}

	public function test_activity_timeout_does_not_change_delivery_timeouts() {
		$timeouts = array();
		add_filter( 'pre_http_request', static function ( $response, $args, $url ) use ( &$timeouts ) {
			if ( 'api.telegram.org' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return $response; }
			$timeouts[ basename( $url ) ] = $args['timeout'];
			return array( 'headers' => array(), 'body' => '{"ok":true,"result":true}', 'response' => array( 'code' => 200 ) );
		}, PHP_INT_MAX, 3 );
		$client = new Telepilot_Telegram_Client( 'fixture' );
		$client->send_chat_action( '123' );
		$client->answer_callback_query( 'fixture' );
		$client->send_message( '123', 'Result' );
		$this->assertSame( array( 'sendChatAction' => 1, 'answerCallbackQuery' => 2, 'sendMessage' => 10 ), $timeouts );
	}
}
