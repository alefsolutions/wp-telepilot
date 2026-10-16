<?php
class TelegramFeedbackTest extends Telepilot_TestCase {
	private $calls = array();
	private $fail_typing = false;
	private $fail_edit = false;
	private $fail_send = false;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( array( Telepilot_Jobs_Repository::table_name(), Telepilot_Jobs_Repository::operations_table(), Telepilot_Jobs_Repository::locks_table() ) as $table ) { $wpdb->query( "DELETE FROM $table" ); }
		$this->calls = array();
		$this->fail_typing = $this->fail_edit = $this->fail_send = false;
		update_option( 'telepilot_settings', array( 'bot_token' => 'fixture', 'rate_limit_per_minute' => 100 ) );
		add_filter( 'pre_http_request', function ( $response, $args, $url ) {
			if ( 'api.telegram.org' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return $response; }
			$method = basename( $url );
			$this->calls[] = array( 'method' => $method, 'body' => $args['body'] );
			if ( in_array( $method, array( 'sendMessage', 'editMessageText' ), true ) ) {
				$this->assertStringNotContainsString( 'Working on it', $args['body']['text'] );
				$this->assertStringNotContainsString( 'preparing the reply', $args['body']['text'] );
				global $wp_filter;
				foreach ( $wp_filter['http_api_curl']->callbacks ?? array() as $callbacks ) {
					foreach ( $callbacks as $callback ) {
						$this->assertFalse( is_array( $callback['function'] ) && $callback['function'][0] instanceof Telepilot_Telegram_Activity, 'Activity hooks must be removed before delivery.' );
					}
				}
			}
			if ( ( 'sendChatAction' === $method && $this->fail_typing ) || ( 'editMessageText' === $method && $this->fail_edit ) || ( 'sendMessage' === $method && $this->fail_send ) ) { return new WP_Error( 'fixture_failure', 'Fixture failure' ); }
			return array( 'headers' => array(), 'body' => '{"ok":true,"result":{"message_id":700}}', 'response' => array( 'code' => 200 ) );
		}, PHP_INT_MAX, 3 );
	}

	private function update( $actor, $id, $text = '/menu', $button = false ) {
		$message = array( 'message_id' => $id, 'date' => time(), 'text' => $text, 'from' => array( 'id' => $actor['telegram_user_id'] ), 'chat' => array( 'id' => $actor['chat_id'], 'type' => 'private' ) );
		return $button
			? array( 'update_id' => $id, 'callback_query' => array( 'id' => 'callback-' . $id, 'data' => $text, 'from' => $message['from'], 'message' => $message ) )
			: array( 'update_id' => $id, 'message' => $message );
	}

	private function methods() { return array_column( $this->calls, 'method' ); }

	public function test_command_sends_only_typing_and_final_result_and_duplicate_is_silent() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$update = $this->update( $actor, 41001, '/posts' );
		$id = $service->handle_update( $update )['operation_id'];
		$this->assertSame( array(), $this->calls, 'Message ingress must not send feedback.' );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'sendMessage' ), $this->methods() );
		$this->assertSame( '0', Telepilot_Jobs_Repository::get( $id )['placeholder_message_id'] );
		$service->handle_update( $update );
		$service->process_jobs( 1 );
		$this->assertCount( 2, $this->calls );
	}

	public function test_button_acknowledges_silently_and_only_edits_the_final_result() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$id = $service->handle_update( $this->update( $actor, 41002, '/menu', true ) )['operation_id'];
		$this->assertSame( array( 'answerCallbackQuery' ), $this->methods() );
		$this->assertArrayNotHasKey( 'text', $this->calls[0]['body'] );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'answerCallbackQuery', 'sendChatAction', 'editMessageText' ), $this->methods() );
		$this->assertSame( 41002, $this->calls[2]['body']['message_id'] );
		$this->assertSame( '41002', Telepilot_Jobs_Repository::get( $id )['placeholder_message_id'] );
	}

	public function test_legacy_placeholder_is_preserved_and_deleted_button_falls_back() {
		global $wpdb;
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$id = $service->handle_update( $this->update( $actor, 41003 ) )['operation_id'];
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'placeholder_message_id' => 811 ), array( 'id' => $id ) );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'editMessageText' ), $this->methods() );
		$this->assertSame( 811, $this->calls[1]['body']['message_id'] );
		$this->calls = array();
		$this->fail_edit = true;
		$service->handle_update( $this->update( $actor, 41004, '/menu', true ) );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'answerCallbackQuery', 'sendChatAction', 'editMessageText', 'sendMessage' ), $this->methods() );
	}

	public function test_guided_input_gets_typing_and_its_result() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$service->handle_update( $this->update( $actor, 41005, '/posts new' ) );
		$service->process_jobs( 1 );
		$this->calls = array();
		$service->handle_update( $this->update( $actor, 41006, 'Quiet guided draft' ) );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'sendMessage' ), $this->methods() );
		$this->assertCount( 1, get_posts( array( 'post_status' => 'draft', 's' => 'Quiet guided draft' ) ) );
	}

	public function test_upload_gets_one_useful_response_without_enabling_imports() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$update = $this->update( $actor, 41007, '' );
		$update['message']['document'] = array( 'file_id' => 'fixture', 'file_name' => 'fixture.txt' );
		$service->handle_update( $update );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'sendMessage' ), $this->methods() );
		$this->assertStringContainsString( 'Direct Uploads Disabled', $this->calls[1]['body']['text'] );
	}

	public function test_typing_failure_and_delivery_retry_do_not_repeat_a_write() {
		global $wpdb;
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$this->fail_typing = $this->fail_send = true;
		$id = $service->handle_update( $this->update( $actor, 41008, '/posts create Quiet retry draft' ) )['operation_id'];
		$service->process_jobs( 1 );
		$this->assertSame( 'completed', Telepilot_Jobs_Repository::get( $id )['execution_state'] );
		$this->assertCount( 1, get_posts( array( 'post_status' => 'draft', 's' => 'Quiet retry draft' ) ) );
		$this->calls = array();
		$this->fail_send = false;
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendMessage' ), $this->methods(), 'Saved-outcome retries must not restart typing.' );
		$this->assertSame( 'complete', Telepilot_Jobs_Repository::get( $id )['status'] );
		$this->assertCount( 1, get_posts( array( 'post_status' => 'draft', 's' => 'Quiet retry draft' ) ) );
	}

	public function test_notification_and_rejected_requests_do_not_type() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$service->queue_notification( $actor['chat_id'], 'Useful notification', 'read' );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendMessage' ), $this->methods() );
		$this->calls = array();
		$settings = get_option( 'telepilot_settings' );
		$settings['allowed_chat_ids'] = '999';
		update_option( 'telepilot_settings', $settings );
		$this->calls = array();
		$service->handle_update( $this->update( $actor, 41009 ) );
		$service->process_jobs( 1 );
		$this->assertSame( array(), $this->calls );
	}

	public function test_ai_retry_wait_is_quiet_until_it_executes_and_then_sends_final_reply() {
		global $wpdb;
		$actor = $this->actor();
		$input = array_merge( Telepilot_AI_Settings::defaults(), array( 'ai_enabled' => 1, 'ai_openai_api_key' => 'sk-test-fixture-not-a-real-secret', 'bot_token' => 'fixture' ) );
		$settings = get_option( 'telepilot_settings' );
		update_option( 'telepilot_settings', array_merge( $settings, Telepilot_AI_Settings::sanitize_into_settings( $input, $settings ) ) );
		$this->assertTrue( Telepilot_Queue_Schema::ensure( true ) );
		$sessions = new Telepilot_AI_Session_Manager();
		$sessions->start_session( $actor, 'openai', 'gpt-4.1-mini' );
		$provider_calls = 0;
		add_filter( 'pre_http_request', static function ( $response, $args, $url ) use ( &$provider_calls ) {
			if ( 'api.openai.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return $response; }
			$provider_calls++;
			return array( 'headers' => array(), 'body' => 1 === $provider_calls ? '{"error":{}}' : '{"choices":[{"message":{"content":"Recovered fixture answer."}}]}', 'response' => array( 'code' => 1 === $provider_calls ? 429 : 200 ) );
		}, PHP_INT_MAX, 3 );
		$this->calls = array();
		$service = new Telepilot_Telegram_Service();
		$service->handle_update( $this->update( $actor, 41010, 'List my posts' ) );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'sendMessage' ), $this->methods() );
		$this->assertStringContainsString( 'retry is queued', $this->calls[1]['body']['text'] );
		$this->calls = array();
		$service->process_jobs( 1 );
		$this->assertSame( array(), $this->calls, 'A continuation waiting for its retry time must not type.' );
		$wpdb->query( "UPDATE " . Telepilot_Jobs_Repository::table_name() . " SET available_at='2000-01-01 00:00:00' WHERE transport='ai_continuation'" );
		$service->process_jobs( 1 );
		$this->assertSame( array( 'sendChatAction', 'sendMessage' ), $this->methods() );
		$this->assertStringContainsString( 'Recovered fixture answer.', $this->calls[1]['body']['text'] );
		$this->assertSame( '', $sessions->get_session( $actor )['request_id'] );
		$this->assertSame( 2, $provider_calls );
	}
}
