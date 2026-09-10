<?php
class FoundationTest extends Telepilot_TestCase {
    public function test_standard_and_custom_roles_are_real_wordpress_actors() {
        foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
            $actor = $this->actor( $role );
            $this->assertContains( $role, $actor['wp_user']->roles );
        }
        add_role( 'telepilot_test_custom', 'Custom', array( 'read' => true, 'edit_posts' => true ) );
        $actor = $this->actor( 'telepilot_test_custom' );
        $this->assertTrue( user_can( $actor['wp_user'], 'edit_posts' ) );
        $this->assertFalse( user_can( $actor['wp_user'], 'publish_posts' ) );
        remove_role( 'telepilot_test_custom' );
    }

    public function test_http_is_offline_unless_explicitly_mocked() {
        $this->assertWPError( wp_remote_get( 'https://api.telegram.org/botfake/getMe' ) );
        $this->mock_http( 'api.telegram.org', array( 'ok' => true, 'result' => array( 'id' => 1 ) ) );
        $this->assertSame( 200, wp_remote_retrieve_response_code( wp_remote_get( 'https://api.telegram.org/botfake/getMe' ) ) );
        $this->mock_http( 'api.openai.com', array( 'error' => array( 'type' => 'rate_limit_error' ) ), 429 );
        $this->assertSame( 429, wp_remote_retrieve_response_code( wp_remote_get( 'https://api.openai.com/v1/models' ) ) );
    }

    public function test_schema_creation_is_repeatable_and_preserves_settings() {
        update_option( 'telepilot_test_marker', 'preserved' );
        $pending = Telepilot_Jobs_Repository::pending_count();
        Telepilot_Jobs_Repository::create_table();
        Telepilot_Jobs_Repository::create_table();
        $this->assertSame( $pending, Telepilot_Jobs_Repository::pending_count() );
        $this->assertSame( 'preserved', get_option( 'telepilot_test_marker' ) );
    }

    public function test_v4_migration_preserves_content_linkage_and_ordinary_settings() {
        $actor = $this->actor(); $post = self::factory()->post->create();
        update_option( 'telepilot_schema_version', '0.3.1' );
        update_option( 'telepilot_settings', array( 'bot_token' => 'fixture', 'transport_mode' => 'webhook', 'ai_enabled' => 1, 'ai_openai_api_key_encrypted' => 'plain:unsafe', 'ai_allow_manage_users' => 1 ) );
        set_transient( 'telepilot_ai_action_legacy', array( 'unsafe' => 'pending' ), 300 );
        ( new Telepilot_Bootstrap() )->maybe_upgrade_schema();
        $settings = get_option( 'telepilot_settings' );
        $this->assertSame( 'fixture', $settings['bot_token'] );
        $this->assertSame( 'webhook', $settings['transport_mode'] );
        $this->assertSame( 0, $settings['ai_enabled'] );
        $this->assertArrayNotHasKey( 'ai_allow_manage_users', $settings );
        $this->assertFalse( get_transient( 'telepilot_ai_action_legacy' ) );
        $this->assertNotNull( get_post( $post ) );
        $this->assertSame( $actor['chat_id'], get_user_meta( $actor['wp_user']->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ) );
        $this->assertSame( Telepilot_Bootstrap::SCHEMA_VERSION, get_option( 'telepilot_schema_version' ) );
    }

    public function test_quoted_command_syntax_is_preserved() {
        $command = ( new Telepilot_Command_Grammar() )->parse_text( '/posts new "Test title"' );
        $this->assertSame( '/posts', $command['name'] );
        $this->assertSame( array( 'new', 'Test title' ), $command['args'] );
    }
}
