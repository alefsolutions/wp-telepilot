<?php
class SubmissionReadinessTest extends Telepilot_TestCase {
    /** @dataProvider malformed_tokens */
    public function test_editor_rejects_malformed_request_tokens( $value ) {
        $saved_get = $_GET; $saved_post = $_POST;
        try {
            $editor = new Telepilot_Post_Editor_Service();
            $_POST = array(); $_GET = array( 'token' => $value );
            $this->assertSame( '', $this->invoke( $editor, 'get_request_token' ) );
            $_POST = array( 'telepilot_post_editor_token' => $value );
            $_GET = array( 'token' => str_repeat( 'a', 24 ) );
            $this->assertSame( '', $this->invoke( $editor, 'get_request_token' ), 'An invalid POST token must not fall back to a GET token.' );
        } finally { $_GET = $saved_get; $_POST = $saved_post; }
    }

    public function malformed_tokens() {
        return array( array( array( 'nested' ) ), array( '' ), array( 'not-a-token' ), array( str_repeat( 'a', 23 ) ), array( str_repeat( 'a', 25 ) ), array( 'X' . str_repeat( 'a', 24 ) ), array( str_repeat( 'A', 24 ) ) );
    }

    public function test_editor_reads_an_issued_token_without_requiring_a_display_nonce() {
        $saved_get = $_GET; $saved_post = $_POST;
        try {
            $actor = $this->actor();
            $post = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID ) );
            $editor = new Telepilot_Post_Editor_Service();
            $link = $editor->create_edit_link( $post, $actor['wp_user'], $actor['telegram_user_id'] );
            $_POST = array(); $_GET = array( 'token' => $link['token'] );
            $this->assertSame( $link['token'], $this->invoke( $editor, 'get_request_token' ) );
            $this->assertNotEmpty( $this->invoke( $editor, 'get_session', $link['token'] ) );
        } finally { $_GET = $saved_get; $_POST = $saved_post; }
    }

    public function test_editor_missing_and_invalid_save_nonce_do_not_write_or_consume_token() {
        $actor = $this->actor(); wp_set_current_user( $actor['wp_user']->ID );
        $post = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID, 'post_content' => 'Original' ) );
        $editor = new Telepilot_Post_Editor_Service();
        $link = $editor->create_edit_link( $post, $actor['wp_user'], $actor['telegram_user_id'] );
        $session = $this->invoke( $editor, 'get_session', $link['token'] );
        $saved_request = $_REQUEST; $saved_post = $_POST;
        try {
            foreach ( array( array(), array( '_wpnonce' => 'invalid' ) ) as $request ) {
                $_REQUEST = $request; $_POST = array( 'telepilot_post_content' => 'Changed' );
                try {
                    $this->invoke( $editor, 'handle_save_request', $link['token'], $session, get_post( $post ), $actor['wp_user'] );
                    $this->fail( 'The save handler accepted an invalid nonce.' );
                } catch ( WPDieException $error ) {
                    $this->assertSame( 'Original', get_post( $post )->post_content );
                    $this->assertNotNull( Telepilot_Security_Records::read( $link['token'], 'post_editor' ) );
                }
            }
        } finally { $_REQUEST = $saved_request; $_POST = $saved_post; }
    }

    public function test_post_statistics_are_actor_scoped_and_invalidated_by_native_writes() {
        $one = $this->actor( 'author' ); $two = $this->actor( 'author' );
        self::factory()->post->create( array( 'post_author' => $one['wp_user']->ID, 'post_status' => 'draft' ) );
        self::factory()->post->create_many( 2, array( 'post_author' => $two['wp_user']->ID, 'post_status' => 'draft' ) );
        $service = new Telepilot_Posts_Service( new Telepilot_Confirmation_Service() );
        wp_set_current_user( $one['wp_user']->ID ); $this->assertSame( 1, $service->stats()['draft'] );
        wp_set_current_user( $two['wp_user']->ID ); $this->assertSame( 2, $service->stats()['draft'] );
        wp_set_current_user( $one['wp_user']->ID );
        $post = self::factory()->post->create( array( 'post_author' => $one['wp_user']->ID, 'post_status' => 'draft' ) );
        $this->assertSame( 2, $service->stats()['draft'] );
        wp_delete_post( $post, true ); $this->assertSame( 1, $service->stats()['draft'] );
        $admin = $this->actor(); wp_set_current_user( $admin['wp_user']->ID );
        $this->assertSame( 3, $service->stats()['draft'] );
        wp_set_current_user( $one['wp_user']->ID ); $this->assertSame( 1, $service->stats()['draft'] );
    }

    public function test_audit_identity_filters_match_the_union_and_preserve_pagination_and_erasure() {
        global $wpdb;
        $actor = $this->actor();
        // Other lifecycle suites commit custom-table rows; isolate the audit identity fixtures.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Telepilot_Audit_Log_Repository::table_name() ) );
        $records = array(
            array( 'wp_user_id' => $actor['wp_user']->ID ),
            array( 'telegram_user_id' => $actor['telegram_user_id'] ),
            array( 'chat_id' => $actor['chat_id'] ),
        );
        foreach ( $records as $index => $record ) { Telepilot_Audit_Log_Repository::log( array_merge( array( 'action_name' => 'matching', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $index ) ), $record ) ); }
        Telepilot_Audit_Log_Repository::log( array( 'action_name' => 'unrelated' ) );
        for ( $mask = 0; $mask < 8; $mask++ ) {
            $rows = Telepilot_Audit_Log_Repository::find_personal_logs( $mask & 1 ? $actor['wp_user']->ID : 0, $mask & 2 ? $actor['telegram_user_id'] : '', $mask & 4 ? $actor['chat_id'] : '' );
            $this->assertCount( (int) (bool) ( $mask & 1 ) + (int) (bool) ( $mask & 2 ) + (int) (bool) ( $mask & 4 ), $rows );
        }
        $first = Telepilot_Audit_Log_Repository::find_personal_logs( $actor['wp_user']->ID, $actor['telegram_user_id'], $actor['chat_id'], 1, 2 );
        $second = Telepilot_Audit_Log_Repository::find_personal_logs( $actor['wp_user']->ID, $actor['telegram_user_id'], $actor['chat_id'], 2, 2 );
        $this->assertCount( 2, $first ); $this->assertCount( 1, $second );
        $this->assertNotContains( $second[0]['id'], array_column( $first, 'id' ) );
        $this->assertSame( 0, Telepilot_Audit_Log_Repository::anonymize_personal_logs( 0 ) );
        $this->assertSame( 3, Telepilot_Audit_Log_Repository::anonymize_personal_logs( $actor['wp_user']->ID, $actor['telegram_user_id'], $actor['chat_id'] ) );
        $this->assertSame( array(), Telepilot_Audit_Log_Repository::find_personal_logs( $actor['wp_user']->ID, $actor['telegram_user_id'], $actor['chat_id'] ) );
        $this->assertNotEmpty( Telepilot_Audit_Log_Repository::latest_log_by_action( 'unrelated' ) );
    }

    public function test_editor_rich_html_respects_actor_capabilities_and_preserves_backslashes() {
        $content = '<p>Using C:\\Windows\\Temp</p><script>window.fixture = 1;</script>';
        foreach ( array( 'author', 'administrator' ) as $role ) {
            $actor = $this->actor( $role ); wp_set_current_user( $actor['wp_user']->ID );
            $post = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID ) );
            $editor = new Telepilot_Post_Editor_Service(); $link = $editor->create_edit_link( $post, $actor['wp_user'], $actor['telegram_user_id'] );
            $this->assertSame( $post, $editor->save( $link['token'], array( 'post_content' => $content ) ) );
            $saved = get_post( $post )->post_content;
            $this->assertStringContainsString( '<p>Using C:\\Windows\\Temp</p>', $saved );
            if ( 'administrator' === $role ) { $this->assertStringContainsString( '<script>', $saved ); }
            else { $this->assertStringNotContainsString( '<script>', $saved ); }
        }
    }

    public function test_retain_data_uninstall_revokes_ephemeral_data_without_removing_accounts_or_settings() {
        $actor = $this->actor();
        update_option( 'telepilot_settings', array( 'cleanup_on_uninstall' => 0, 'fixture_marker' => 'preserved' ) );
        $job = Telepilot_Jobs_Repository::enqueue( 0, 'notification', array( '_telepilot_actor' => array( 'user_id' => $actor['wp_user']->ID ) ), '', array( 'chat_id' => $actor['chat_id'] ), array( 'result' => array( 'secret' => 'fixture' ) ) );
        $token = Telepilot_Security_Records::issue( 'fixture', array( 'secret' => 'fixture' ), $actor['wp_user']->ID, 300 );
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'wp-telepilot/wp-telepilot.php' ); }
        require dirname( TELEPILOT_FILE ) . '/uninstall.php';
        $saved = Telepilot_Jobs_Repository::get( $job );
        $this->assertSame( '', $saved['payload'] ); $this->assertSame( 'cancelled', $saved['status'] ); $this->assertNull( $saved['outcome'] );
        $this->assertNull( Telepilot_Security_Records::read( $token, 'fixture' ) );
        $this->assertSame( 'preserved', get_option( 'telepilot_settings' )['fixture_marker'] );
        $this->assertSame( $actor['chat_id'], get_user_meta( $actor['wp_user']->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ) );
        Telepilot_Activator::activate();
    }
}
