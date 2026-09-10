<?php
/** @group stabilization */
class AuthorizationRegressionTest extends Telepilot_TestCase {
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
