<?php
/** @group stabilization */
class QueueTest extends Telepilot_TestCase {
	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( array( Telepilot_Jobs_Repository::table_name(), Telepilot_Jobs_Repository::operations_table(), Telepilot_Jobs_Repository::locks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM $table" );
		}
	}

	private function update( $actor, $id, $text = '/posts create Queue test' ) {
		return array( 'update_id' => $id, 'message' => array( 'message_id' => $id, 'date' => time(), 'text' => $text, 'from' => array( 'id' => $actor['telegram_user_id'] ), 'chat' => array( 'id' => $actor['chat_id'], 'type' => 'private' ) ) );
	}

	public function test_delivery_retry_never_repeats_a_write() {
		global $wpdb;
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$before = (int) wp_count_posts()->draft;
		$accepted = $service->handle_update( $this->update( $actor, 1001 ) );
		$this->assertTrue( $accepted['queued'] );
		$this->assertSame( $before, (int) wp_count_posts()->draft, 'Ingress must not write WordPress content.' );
		$id = $accepted['operation_id'];
		$service->process_jobs( 1 );
		$job = Telepilot_Jobs_Repository::get( $id );
		$this->assertSame( 'completed', $job['execution_state'] );
		$this->assertSame( 'pending', $job['status'], $job['last_error'] ?? '' );
		$this->assertSame( $before + 1, (int) wp_count_posts()->draft );
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
		$service->process_jobs( 1 );
		$this->assertSame( $before + 1, (int) wp_count_posts()->draft, 'Delivery failure must not create a second draft.' );
		$this->assertSame( $job['outcome'], Telepilot_Jobs_Repository::get( $id )['outcome'] );
	}

	public function test_delivery_retry_does_not_resend_content_after_ownership_changes() {
		global $wpdb;
		$actor = $this->actor( 'author' );
		$post = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID, 'post_status' => 'draft', 'post_title' => 'Private title fixture' ) );
		update_option( 'telepilot_settings', array( 'bot_token' => 'fixture' ) );
		$service = new Telepilot_Telegram_Service();
		$id = $service->handle_update( $this->update( $actor, 1015, '/posts' ) )['operation_id'];
		$service->process_jobs( 1 );
		$this->assertStringContainsString( 'Private title fixture', Telepilot_Jobs_Repository::get( $id )['outcome'] );
		wp_update_post( array( 'ID' => $post, 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		$sent = '';
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$sent ) {
			if ( false !== strpos( $url, '/sendMessage' ) ) { $sent .= wp_json_encode( $args['body'] ); }
			return $pre;
		}, PHP_INT_MAX, 3 );
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
		$service->process_jobs( 1 );
		$this->assertStringNotContainsString( 'Private title fixture', $sent );
		$this->assertStringContainsString( 'data changed', $sent );
	}

	public function test_duplicate_updates_have_one_durable_operation() {
		global $wpdb;
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$update = $this->update( $actor, 1002 );
		$first = $service->handle_update( $update );
		$suppressed = $wpdb->suppress_errors();
		$second = $service->handle_update( $update );
		$wpdb->suppress_errors( $suppressed );
		$this->assertIsInt( $first['operation_id'] );
		$this->assertSame( 'duplicate', $second['operation_id'] );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Telepilot_Jobs_Repository::operations_table() ) );
	}

	public function test_expired_execution_is_quarantined_but_other_chats_continue() {
		global $wpdb;
		$service = new Telepilot_Telegram_Service();
		$one = $this->actor();
		$two = $this->actor();
		$id = $service->handle_update( $this->update( $one, 1003 ) )['operation_id'];
		$service->handle_update( $this->update( $one, 1004 ) );
		$other = $service->handle_update( $this->update( $two, 1005 ) )['operation_id'];
		$claimed = Telepilot_Jobs_Repository::claim_pending_jobs()[0];
		$this->assertSame( $id, (int) $claimed['id'] );
		$this->assertTrue( Telepilot_Jobs_Repository::begin_execution( $id, $claimed['lease_token'] ) );
		$wpdb->update( Telepilot_Jobs_Repository::locks_table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'job_id' => $id ) );
		$next = Telepilot_Jobs_Repository::claim_pending_jobs()[0];
		$this->assertSame( 'uncertain', Telepilot_Jobs_Repository::get( $id )['status'] );
		$this->assertSame( $other, (int) $next['id'] );
		$this->assertFalse( Telepilot_Jobs_Repository::save_outcome( $id, $claimed['lease_token'], array( 'ok' => true ) ), 'Expired owners cannot finish an operation.' );
	}

	public function test_claims_are_serialized_per_chat_and_cancellation_does_not_stop_live_workers() {
		$service = new Telepilot_Telegram_Service();
		$actor = $this->actor();
		$first = $service->handle_update( $this->update( $actor, 1006 ) )['operation_id'];
		$second = $service->handle_update( $this->update( $actor, 1007 ) )['operation_id'];
		$claim = Telepilot_Jobs_Repository::claim_pending_jobs()[0];
		$this->assertSame( array(), Telepilot_Jobs_Repository::claim_pending_jobs() );
		$this->assertFalse( Telepilot_Jobs_Repository::cancel( $first ) );
		$this->assertTrue( Telepilot_Jobs_Repository::cancel( $second ) );
		$this->assertFalse( Telepilot_Jobs_Repository::mark_complete( $first, 'wrong-owner' ) );
		$this->assertTrue( Telepilot_Jobs_Repository::mark_complete( $first, $claim['lease_token'] ) );
	}

	public function test_relink_before_execution_cancels_the_old_request() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$before = (int) wp_count_posts()->draft;
		$id = $service->handle_update( $this->update( $actor, 1008 ) )['operation_id'];
		Telepilot_Actor_Access::revoke( $actor['wp_user']->ID );
		$service->process_jobs( 1 );
		$this->assertSame( $before, (int) wp_count_posts()->draft );
		$this->assertSame( 'complete', Telepilot_Jobs_Repository::get( $id )['status'] );
	}

	public function test_wp_admin_writes_invalidate_post_list_cache() {
		$actor = $this->actor();
		wp_set_current_user( $actor['wp_user']->ID );
		$posts = new Telepilot_Posts_Service( new Telepilot_Confirmation_Service() );
		$id = self::factory()->post->create( array( 'post_title' => 'Before', 'post_status' => 'draft', 'post_author' => $actor['wp_user']->ID ) );
		$posts->latest_page();
		wp_update_post( array( 'ID' => $id, 'post_title' => 'After' ) );
		$items = $posts->latest_page()['items'];
		$matching = array_values( array_filter( $items, static function ( $post ) use ( $id ) { return $post->ID === $id; } ) );
		$this->assertSame( 'After', $matching[0]->post_title );
	}

	public function test_notification_rechecks_target_access_at_delivery() {
		$actor = $this->actor( 'author' );
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$service = new Telepilot_Telegram_Service();
		$job = $service->queue_notification( $actor['chat_id'], 'Publication notification', 'edit_posts', array( 'type' => 'post', 'id' => $id ) );
		$this->assertIsInt( $job );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$sent = 0;
		add_filter( 'pre_http_request', static function ( $response, $args, $url ) use ( &$sent ) { if ( false !== strpos( $url, '/sendMessage' ) ) { $sent++; } return $response; }, PHP_INT_MAX, 3 );
		$service->process_jobs( 1 );
		$this->assertSame( 0, $sent );
		$this->assertSame( 'complete', Telepilot_Jobs_Repository::get( $job )['status'] );
		$this->assertWPError( $service->queue_notification( $actor['chat_id'], 'Private draft', 'edit_posts', array( 'type' => 'post', 'id' => $id ) ) );
	}

	public function test_unchanged_telegram_edit_is_success() {
		$this->mock_http( 'api.telegram.org', array( 'ok' => false, 'description' => 'Bad Request: message is not modified' ), 400 );
		$result = ( new Telepilot_Telegram_Client( 'fake-token' ) )->edit_message_text( '123', 1, 'same' );
		$this->assertTrue( $result['ok'] );
	}

	public function test_callback_is_persisted_before_bounded_acknowledgement() {
		global $wpdb;
		$actor = $this->actor();
		update_option( 'telepilot_settings', array( 'bot_token' => 'fixture' ) );
		$observed = false;
		add_filter( 'pre_http_request', function ( $response, $args, $url ) use ( &$observed, $wpdb ) {
			if ( false !== strpos( $url, '/answerCallbackQuery' ) ) {
				$observed = true;
				$this->assertArrayNotHasKey( 'text', $args['body'], 'Button acknowledgement should not show a Queued toast.' );
				$this->assertLessThanOrEqual( 2, $args['timeout'] );
				$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Telepilot_Jobs_Repository::table_name() ) );
			}
			$this->assertStringNotContainsString( '/sendMessage', $url );
			$this->assertStringNotContainsString( '/editMessageText', $url );
			return $response;
		}, PHP_INT_MAX, 3 );
		( new Telepilot_Telegram_Service() )->handle_update( array( 'update_id' => 1100, 'callback_query' => array( 'id' => 'fixture', 'data' => '/menu', 'from' => array( 'id' => $actor['telegram_user_id'] ), 'message' => array( 'message_id' => 1, 'chat' => array( 'id' => $actor['chat_id'], 'type' => 'private' ) ) ) ) );
		$this->assertTrue( $observed );
	}

	public function test_queue_failure_does_not_fall_back_to_synchronous_write() {
		global $wpdb;
		$actor = $this->actor();
		$before = (int) wp_count_posts()->draft;
		$table = Telepilot_Jobs_Repository::table_name();
		$failure = static function ( $query ) use ( $table ) {
			return 0 === strpos( $query, "INSERT INTO `$table`" ) ? 'INTENTIONAL TEST DATABASE FAILURE' : $query;
		};
		$suppressed = $wpdb->suppress_errors();
		add_filter( 'query', $failure );
		try {
			$result = ( new Telepilot_Telegram_Service() )->handle_update( $this->update( $actor, 1101 ) );
		} finally {
			remove_filter( 'query', $failure );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( $before, (int) wp_count_posts()->draft );
	}
}
