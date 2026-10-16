<?php
/** @group stabilization */
class SecurityTest extends Telepilot_TestCase {
	private function editor_fixture() {
		$actor = $this->actor( 'author' );
		$id = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID, 'post_status' => 'draft', 'post_content' => 'Original' ) );
		$editor = new Telepilot_Post_Editor_Service();
		$link = $editor->create_edit_link( $id, $actor['wp_user'], $actor['telegram_user_id'], 86400 );
		return array( $actor, $id, $editor, $link );
	}

	public function test_editor_is_unique_one_hour_and_atomically_single_use() {
		list( $actor, $id, $editor, $link ) = $this->editor_fixture();
		$another = $editor->create_edit_link( $id, $actor['wp_user'], $actor['telegram_user_id'] );
		$this->assertNotSame( $link['token'], $another['token'] );
		$this->assertLessThanOrEqual( time() + 3600, $link['expires_at'] );
		wp_set_current_user( 0 );
		$this->assertSame( $id, $editor->save( $link['token'], array( 'post_content' => 'New content' ) ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertWPError( $editor->save( $link['token'], array( 'post_content' => 'Replay' ) ) );
		$this->assertSame( 'New content', get_post( $id )->post_content );
	}

	public function test_editor_rejects_revocation_and_changed_content() {
		list( $actor, $id, $editor, $link ) = $this->editor_fixture();
		wp_update_post( array( 'ID' => $id, 'post_content' => 'Changed in wp-admin' ) );
		$result = $editor->save( $link['token'], array( 'post_content' => 'Stale editor text' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'telepilot_editor_conflict', $result->get_error_code() );
		$this->assertSame( 'Changed in wp-admin', get_post( $id )->post_content );
		$link = $editor->create_edit_link( $id, $actor['wp_user'], $actor['telegram_user_id'] );
		Telepilot_Actor_Access::revoke( $actor['wp_user']->ID );
		$this->assertWPError( $editor->save( $link['token'], array( 'post_content' => 'Revoked edit' ) ) );
	}

	public function test_expired_tokens_and_pages_cannot_use_browser_editor() {
		global $wpdb;
		list( $actor, $id, $editor, $link ) = $this->editor_fixture();
		$wpdb->update( Telepilot_Security_Records::table_name(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'token_hash' => hash( 'sha256', $link['token'] ) ) );
		$this->assertWPError( $editor->save( $link['token'], array( 'post_content' => 'Too late' ) ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertWPError( $editor->create_edit_link( $page, $actor['wp_user'], $actor['telegram_user_id'] ) );
	}

	public function test_only_one_claim_can_succeed() {
		$actor = $this->actor();
		$token = Telepilot_Security_Records::issue( 'confirmation', array(), $actor['wp_user']->ID, 300 );
		$this->assertTrue( Telepilot_Security_Records::claim( $token, 'confirmation' ) );
		$this->assertFalse( Telepilot_Security_Records::claim( $token, 'confirmation' ) );
	}

	public function test_missing_webhook_secret_fails_closed() {
		update_option( 'telepilot_settings', array() );
		$request = new WP_REST_Request( 'POST', '/telepilot/v1/webhook' );
		$result = ( new Telepilot_REST_Webhook_Controller() )->handle_webhook( $request );
		$this->assertSame( 403, $result->get_status() );
	}

	public function test_credentials_and_security_links_are_redacted_before_logging() {
		global $wpdb;
		Telepilot_Audit_Log_Repository::log( array( 'action_name' => 'redaction_test', 'context' => array( 'raw_command' => '/link SECRET12', 'headers' => array( 'Authorization' => 'Bearer testsecret' ), 'api_key' => 'sk-abc', 'reset_url' => 'https://example.test/reset?key=SECRET', 'editor_url' => 'https://example.test/?token=SECRET', 'post_content' => 'Private draft' ) ) );
		$row = Telepilot_Audit_Log_Repository::latest_log_by_action( 'redaction_test' );
		$json = wp_json_encode( $row );
		foreach ( array( 'SECRET12', 'testsecret', 'sk-abc', 'Private draft', '?token=' ) as $secret ) { $this->assertStringNotContainsString( $secret, $json ); }
		$this->assertNull( $row['ip_address'] );
	}

	public function test_privacy_erasure_removes_pending_jobs_and_editor_tokens() {
		list( $actor, $post, $editor, $link ) = $this->editor_fixture();
		$service = new Telepilot_Telegram_Service();
		$job = $service->queue_notification( $actor['chat_id'], 'Private notice', 'edit_posts' );
		$privacy = new Telepilot_Privacy_Service();
		$export = $privacy->export_personal_data( $actor['wp_user']->user_email );
		$this->assertContains( 'wp-telepilot-jobs', wp_list_pluck( $export['data'], 'group_id' ) );
		$privacy->erase_personal_data( $actor['wp_user']->user_email );
		$this->assertSame( '', Telepilot_Jobs_Repository::get( $job )['payload'] );
		$this->assertWPError( $editor->save( $link['token'], array( 'post_content' => 'Erased identity' ) ) );
	}

	public function test_retention_removes_terminal_and_old_failed_delivery_payloads() {
		global $wpdb;
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$id = $service->queue_notification( $actor['chat_id'], 'Retained content', 'read' );
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'status' => 'complete', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $id ) );
		$failed = $service->queue_notification( $actor['chat_id'], 'Failed content', 'read' );
		$wpdb->update( Telepilot_Jobs_Repository::table_name(), array( 'status' => 'failed', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), array( 'id' => $failed ) );
		Telepilot_Jobs_Repository::purge_payloads();
		$this->assertSame( '', Telepilot_Jobs_Repository::get( $id )['payload'] );
		$this->assertNull( Telepilot_Jobs_Repository::get( $failed )['outcome'] );
	}

	public function test_deactivation_preserves_linkage_and_content_but_revokes_links() {
		list( $actor, $id, $editor, $link ) = $this->editor_fixture();
		Telepilot_Deactivator::deactivate();
		$this->assertSame( $actor['telegram_user_id'], get_user_meta( $actor['wp_user']->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true ) );
		$this->assertNotNull( get_post( $id ) );
		$this->assertWPError( $editor->save( $link['token'], array( 'post_content' => 'No' ) ) );
		$this->assertFalse( wp_next_scheduled( 'telepilot_process_jobs' ) );
	}

	public function test_uninstall_cleanup_preserves_wordpress_content_and_other_options() {
		global $wpdb;
		$actor = $this->actor();
		$post = self::factory()->post->create();
		update_option( 'telepilot_settings', array( 'cleanup_on_uninstall' => 1 ) );
		update_option( 'unrelated_plugin_setting', 'keep' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'wp-telepilot/wp-telepilot.php' ); }
		require dirname( TELEPILOT_FILE ) . '/uninstall.php';
		$this->assertFalse( get_option( 'telepilot_settings' ) );
		$this->assertSame( 'keep', get_option( 'unrelated_plugin_setting' ) );
		$this->assertNotNull( get_post( $post ) );
		$this->assertNotFalse( get_user_by( 'id', $actor['wp_user']->ID ) );
		$this->assertSame( '', get_user_meta( $actor['wp_user']->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true ) );
		Telepilot_Activator::activate();
	}
}
