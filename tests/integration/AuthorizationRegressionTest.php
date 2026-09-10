<?php
/** @group stabilization */
class AuthorizationRegressionTest extends Telepilot_TestCase {
	public function test_group_and_disallowed_chat_are_denied() {
		$actor = $this->actor();
		$actor['chat_type'] = 'supergroup';
		$this->assertWPError( Telepilot_Actor_Access::check( $actor, 'edit_posts' ) );
		$actor['chat_type'] = 'private';
		update_option( 'telepilot_settings', array( 'allowed_chat_ids' => '777' ) );
		$this->assertWPError( Telepilot_Actor_Access::check( $actor, 'edit_posts' ) );
	}

	public function test_unlink_and_relink_invalidate_old_identity_and_pending_flow() {
		$actor = $this->actor();
		$actor['linkage_generation'] = Telepilot_Actor_Access::generation( $actor['wp_user']->ID );
		wp_set_current_user( $actor['wp_user']->ID );
		$flows = new Telepilot_Flow_State_Service();
		$token = $flows->create_flow( array( 'title' => 'Pending' ) );
		$this->assertIsArray( $flows->get_flow( $token ) );
		Telepilot_Actor_Access::revoke( $actor['wp_user']->ID );
		$this->assertWPError( Telepilot_Actor_Access::check( $actor ) );
		$this->assertNull( $flows->get_flow( $token ) );
	}

	public function test_author_lists_never_include_another_authors_draft() {
		$actor = $this->actor( 'author' );
		$other = $this->actor( 'author' );
		$own = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID, 'post_status' => 'draft' ) );
		$secret = self::factory()->post->create( array( 'post_author' => $other['wp_user']->ID, 'post_status' => 'draft' ) );
		$posts = new Telepilot_Posts_Service( new Telepilot_Confirmation_Service() );
		wp_set_current_user( $actor['wp_user']->ID );
		foreach ( array( $posts->latest_page(), $posts->latest_page() ) as $result ) {
			$ids = wp_list_pluck( $result['items'], 'ID' );
			$this->assertContains( $own, $ids );
			$this->assertNotContains( $secret, $ids );
		}
		$this->assertWPError( Telepilot_Actor_Access::check( $actor, 'edit_post', $secret ) );
	}

	public function test_actor_scope_attributes_new_content_and_restores_previous_user() {
		$actor = $this->actor( 'editor' );
		wp_set_current_user( 0 );
		$result = Telepilot_Actor_Access::as_actor( $actor, function () {
			$confirmation = new Telepilot_Confirmation_Service();
			return array( ( new Telepilot_Posts_Service( $confirmation ) )->create_draft( 'New post' ), ( new Telepilot_Pages_Service( $confirmation ) )->create_page( 'New page' ) );
		} );
		foreach ( $result as $created ) {
			$this->assertSame( $actor['wp_user']->ID, (int) $created['post']->post_author );
		}
		$this->assertSame( 0, get_current_user_id() );
		try {
			Telepilot_Actor_Access::as_actor( $actor, function () { throw new RuntimeException( 'Fixture' ); } );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 0, get_current_user_id() );
		}
	}

	public function test_role_changes_and_cached_responses_cannot_bypass_access() {
		$actor = $this->actor();
		$service = new Telepilot_Telegram_Service();
		$command = ( new Telepilot_Command_Grammar() )->parse_text( '/users list' );
		$key = $this->invoke( $service, 'build_response_cache_key', $actor, $command );
		set_transient( $key, array( 'ok' => true, 'message' => 'Sensitive cached user list' ), 30 );
		$actor['wp_user']->set_role( 'subscriber' );
		$this->assertWPError( Telepilot_Actor_Access::check( $actor, 'list_users' ) );
		$this->assertNull( $this->invoke( $service, 'get_cached_command_response', $actor, $command, array() ) );
	}

	public function test_wrong_actor_cannot_consume_confirmation() {
		$owner = $this->actor();
		$other = $this->actor();
		wp_set_current_user( $owner['wp_user']->ID );
		$confirmation = new Telepilot_Confirmation_Service();
		$token = $confirmation->create_token( array( 'post_id' => 123 ) );
		wp_set_current_user( $other['wp_user']->ID );
		$this->assertNull( $confirmation->consume_token( $token ) );
		wp_set_current_user( $owner['wp_user']->ID );
		$this->assertIsArray( $confirmation->consume_token( $token ) );
	}
    public function test_suspension_revokes_telegram_capabilities() {
        $actor = $this->actor();
        update_user_meta( $actor['wp_user']->ID, '_telepilot_disabled', 1 );
        $this->assertNotSame( true, ( new Telepilot_Permission_Service() )->require_capability( $actor, 'edit_posts' ) );
    }

    public function test_allowed_chat_is_not_a_notification_subscription() {
        update_option( 'telepilot_settings', array( 'allowed_chat_ids' => '800' ) );
        $recipients = $this->invoke( new Telepilot_Notification_Service(), 'get_recipient_chat_ids', 'manage_options' );
        $this->assertSame( array(), $recipients );
    }

    public function test_suspended_linked_users_do_not_receive_notifications() {
        $actor = $this->actor();
        update_user_meta( $actor['wp_user']->ID, '_telepilot_disabled', 1 );
        $recipients = $this->invoke( new Telepilot_Notification_Service(), 'get_recipient_chat_ids', 'manage_options' );
        $this->assertNotContains( $actor['chat_id'], $recipients );
    }
}
