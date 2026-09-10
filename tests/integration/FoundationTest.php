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

    public function test_quoted_command_syntax_is_preserved() {
        $command = ( new Telepilot_Command_Grammar() )->parse_text( '/posts new "Test title"' );
        $this->assertSame( '/posts', $command['name'] );
        $this->assertSame( array( 'new', 'Test title' ), $command['args'] );
    }
}
