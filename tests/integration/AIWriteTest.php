<?php
class AIWriteTest extends Telepilot_TestCase {
    private function setup_actor() {
        $input = array_merge( Telepilot_AI_Settings::defaults(), array_fill_keys( Telepilot_AI_Settings::permission_fields(), 1 ), array( 'ai_enabled' => 1, 'ai_openai_api_key' => 'sk-fixture-write-test' ) );
        update_option( 'telepilot_settings', Telepilot_AI_Settings::sanitize_into_settings( $input, array() ) );
        $actor = $this->actor();
        $session = ( new Telepilot_AI_Session_Manager() )->start_session( $actor, 'openai', 'gpt-4.1-mini' );
        return array( $actor, $session );
    }
    private function proposal( $actor, $session, $name, $args ) {
        return Telepilot_Actor_Access::as_actor( $actor, static function () use ( $actor, $session, $name, $args ) {
            $prepared = ( new Telepilot_AI_Tool_Executor() )->execute( $name, $args, $actor, $session );
            if ( is_wp_error( $prepared ) ) { return $prepared; }
            return ( new Telepilot_AI_Confirmation_Manager() )->create_pending_action( $session, $actor, $name, $prepared['ability'], $prepared['arguments'], $prepared['prompt'], $prepared['risk_level'] );
        }, 'ai' );
    }
    private function confirm( $actor, $id, $final = false ) {
        return Telepilot_Actor_Access::as_actor( $actor, static function () use ( $actor, $id, $final ) { return ( new Telepilot_AI_Confirmation_Manager() )->confirm( $id, $actor, $final ); }, 'ai' );
    }

    public function test_confirmed_plugin_update_uses_the_native_upgrader_once() {
        $this->assertTrue( class_exists( 'ZipArchive' ), 'Enable the ZIP extension for package and upgrader tests.' );
        list( $actor, $session ) = $this->setup_actor();
        $directory = WP_PLUGIN_DIR . '/telepilot-update-fixture';
        $file = 'telepilot-update-fixture/plugin.php';
        wp_mkdir_p( $directory );
        copy( dirname( __DIR__ ) . '/fixtures/plugin.php', $directory . '/plugin.php' );
        wp_clean_plugins_cache();
        $archive = wp_tempnam( 'telepilot-update.zip' );
        $zip = new ZipArchive();
        $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        $zip->addFile( dirname( __DIR__ ) . '/fixtures/plugin-updated.php', 'telepilot-update-fixture/plugin.php' );
        $zip->close();
        $this->mock_http( 'api.wordpress.org', array( 'plugins' => array(), 'themes' => array(), 'offers' => array(), 'no_update' => array(), 'translations' => array() ) );
        add_filter( 'filesystem_method', static function () { return 'direct'; } );
        add_filter( 'pre_site_transient_update_plugins', static function () use ( $file, $directory ) {
            $version = get_file_data( $directory . '/plugin.php', array( 'Version' => 'Version' ) )['Version'];
            return (object) array( 'last_checked' => time(), 'checked' => array( $file => $version ), 'no_update' => array(), 'response' => '1.0.0' === $version ? array( $file => (object) array( 'slug' => 'telepilot-update-fixture', 'plugin' => $file, 'new_version' => '2.0.0', 'package' => 'https://example.test/fixture.zip', 'url' => 'https://example.test/' ) ) : array() );
        } );
        $downloads = 0;
        add_filter( 'upgrader_pre_download', static function ( $reply, $package ) use ( $archive, &$downloads ) {
            if ( 'https://example.test/fixture.zip' !== $package ) { return new WP_Error( 'test_unexpected_package', 'Unexpected package.' ); }
            $downloads++;
            $copy = wp_tempnam( 'telepilot-download.zip' ); copy( $archive, $copy ); return $copy;
        }, 10, 2 );
        try {
            $proposal = $this->proposal( $actor, $session, 'telepilot_update_plugin', array( 'identifier' => 'telepilot-update-fixture' ) );
            $this->assertNotWPError( $proposal );
            $warning = $this->confirm( $actor, $proposal['action_id'] );
            $this->assertNotWPError( $warning );
            $this->assertSame( 0, $downloads );
            $result = $this->confirm( $actor, $warning['action_id'], true );
            $this->assertNotWPError( $result );
            $this->assertSame( '2.0.0', get_file_data( $directory . '/plugin.php', array( 'Version' => 'Version' ) )['Version'] );
            $this->assertWPError( $this->confirm( $actor, $warning['action_id'], true ) );
            $this->assertSame( 1, $downloads );
        } finally {
            wp_delete_file( $archive ); wp_delete_file( $directory . '/plugin.php' );
            if ( is_dir( $directory ) ) { rmdir( $directory ); }
            wp_clean_plugins_cache();
        }
    }

    public function test_draft_creation_is_confirmed_single_use_and_attributed() {
        list( $actor, $session ) = $this->setup_actor();
        $category = self::factory()->category->create();
        $before = (int) wp_count_posts()->draft;
        $proposal = $this->proposal( $actor, $session, 'telepilot_create_draft', array( 'title' => 'Confirmed draft', 'categories' => array( $category ) ) );
        $this->assertNotWPError( $proposal );
        $this->assertSame( $before, (int) wp_count_posts()->draft );
        $result = $this->confirm( $actor, $proposal['action_id'] );
        $this->assertNotWPError( $result );
        $post = get_post( $result['data']['post_id'] );
        $this->assertSame( $actor['wp_user']->ID, (int) $post->post_author );
        $this->assertSame( 'draft', $post->post_status );
        $this->assertContains( $category, wp_get_post_categories( $post->ID ) );
        $this->assertWPError( $this->confirm( $actor, $proposal['action_id'] ) );
        $this->assertSame( $before + 1, (int) wp_count_posts()->draft );
    }

    public function test_publish_requires_a_second_confirmation_and_binds_revision() {
        list( $actor, $session ) = $this->setup_actor();
        $id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $actor['wp_user']->ID ) );
        $proposal = $this->proposal( $actor, $session, 'telepilot_publish_post', array( 'post_id' => $id ) );
        $this->assertNotWPError( $proposal );
        $this->assertWPError( $this->confirm( $actor, $proposal['action_id'], true ) );
        $warning = $this->confirm( $actor, $proposal['action_id'] );
        $this->assertTrue( $warning['warning'] );
        $this->assertSame( 'draft', get_post_status( $id ) );
        wp_update_post( array( 'ID' => $id, 'post_title' => 'Changed after preview' ) );
        $this->assertWPError( $this->confirm( $actor, $warning['action_id'], true ) );
        $this->assertSame( 'draft', get_post_status( $id ) );
        $proposal = $this->proposal( $actor, $session, 'telepilot_publish_post', array( 'post_id' => $id ) );
        $warning = $this->confirm( $actor, $proposal['action_id'] );
        $result = $this->confirm( $actor, $warning['action_id'], true );
        $this->assertNotWPError( $result );
        $this->assertSame( 'publish', get_post_status( $id ) );
    }

    public function test_wrong_actor_cannot_burn_a_token_and_expiry_is_absolute() {
        global $wpdb;
        list( $actor, $session ) = $this->setup_actor();
        $proposal = $this->proposal( $actor, $session, 'telepilot_create_draft', array( 'title' => 'Owner only' ) );
        $other = $this->actor();
        ( new Telepilot_AI_Session_Manager() )->start_session( $other, 'openai', 'gpt-4.1-mini' );
        $this->assertWPError( $this->confirm( $other, $proposal['action_id'] ) );
        $this->assertNotNull( ( new Telepilot_AI_Confirmation_Manager() )->get_pending_action( $proposal['action_id'], $actor ) );
        $wpdb->update( Telepilot_Security_Records::table_name(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'token_hash' => hash( 'sha256', $proposal['action_id'] ) ) );
        $this->assertWPError( $this->confirm( $actor, $proposal['action_id'] ) );
    }

    public function test_pending_limit_cancel_and_reconfiguration() {
        list( $actor, $session ) = $this->setup_actor();
        for ( $i = 0; $i < 3; $i++ ) { $this->assertNotWPError( $this->proposal( $actor, $session, 'telepilot_create_draft', array( 'title' => 'Pending ' . $i ) ) ); }
        $this->assertWPError( $this->proposal( $actor, $session, 'telepilot_create_draft', array( 'title' => 'Fourth' ) ) );
        Telepilot_Actor_Access::as_actor( $actor, static function () use ( $actor ) { ( new Telepilot_AI_Chat_Handler() )->cancel_pending_action( $actor ); } );
        $proposal = $this->proposal( $actor, $session, 'telepilot_create_draft', array( 'title' => 'After cancel' ) );
        $this->assertNotWPError( $proposal );
        $settings = get_option( 'telepilot_settings' ); $settings['ai_allow_create_drafts'] = 0; update_option( 'telepilot_settings', $settings );
        $this->assertWPError( $this->confirm( $actor, $proposal['action_id'] ) );
    }

    public function test_draft_edits_and_comment_actions_use_shared_execution() {
        list( $actor, $session ) = $this->setup_actor();
        $id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_excerpt' => 'Old excerpt' ) );
        $proposal = $this->proposal( $actor, $session, 'telepilot_update_draft', array( 'post_id' => $id, 'title' => 'Edited title', 'excerpt' => '' ) );
        $this->assertNotWPError( $proposal );
        $this->assertNotWPError( $this->confirm( $actor, $proposal['action_id'] ) );
        $this->assertSame( 'Edited title', get_post( $id )->post_title );
        $this->assertSame( '', get_post( $id )->post_excerpt );
        $comment = self::factory()->comment->create( array( 'comment_post_ID' => $id, 'comment_approved' => '0' ) );
        foreach ( array( 'telepilot_approve_comment' => 'approved', 'telepilot_trash_comment' => 'trash' ) as $tool => $status ) {
            $proposal = $this->proposal( $actor, $session, $tool, array( 'comment_id' => $comment ) );
            $this->assertNotWPError( $proposal );
            $this->assertNotWPError( $this->confirm( $actor, $proposal['action_id'] ) );
            $this->assertSame( $status, wp_get_comment_status( $comment ) );
        }
    }

    public function test_schedule_is_future_and_shows_timezone() {
        list( $actor, $session ) = $this->setup_actor();
        $id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
        $this->assertWPError( $this->proposal( $actor, $session, 'telepilot_schedule_post', array( 'post_id' => $id, 'scheduled_at' => '2020-01-01 12:00:00' ) ) );
        $proposal = $this->proposal( $actor, $session, 'telepilot_schedule_post', array( 'post_id' => $id, 'scheduled_at' => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
        $this->assertNotWPError( $proposal );
        $this->assertStringContainsString( 'Timezone: ' . wp_timezone_string(), $proposal['summary'] );
        $this->assertNotWPError( $this->confirm( $actor, $proposal['action_id'] ) );
        $this->assertSame( 'future', get_post_status( $id ), wp_json_encode( array( $proposal, get_post( $id ), gmdate( 'Y-m-d H:i:s' ) ) ) );
    }

    public function test_plugin_activation_and_deactivation_require_final_confirmation() {
        list( $actor, $session ) = $this->setup_actor();
        $directory = WP_PLUGIN_DIR . '/telepilot-qa-fixture';
        wp_mkdir_p( $directory );
        copy( dirname( __DIR__ ) . '/fixtures/plugin.php', $directory . '/plugin.php' );
        wp_clean_plugins_cache( false );
        try {
            foreach ( array( 'telepilot_activate_plugin' => true, 'telepilot_deactivate_plugin' => false ) as $tool => $expected ) {
                $proposal = $this->proposal( $actor, $session, $tool, array( 'identifier' => 'telepilot-qa-fixture' ) );
                $this->assertNotWPError( $proposal );
                $warning = $this->confirm( $actor, $proposal['action_id'] );
                $this->assertTrue( $warning['warning'] );
                $this->assertSame( ! $expected, is_plugin_active( 'telepilot-qa-fixture/plugin.php' ) );
                $result = $this->confirm( $actor, $warning['action_id'], true );
                $this->assertNotWPError( $result );
                $this->assertSame( $expected, is_plugin_active( 'telepilot-qa-fixture/plugin.php' ) );
            }
        } finally {
            deactivate_plugins( 'telepilot-qa-fixture/plugin.php', true );
            unlink( $directory . '/plugin.php' ); rmdir( $directory ); wp_clean_plugins_cache( false );
        }
    }
}
