<?php
class MaintenanceFollowupTest extends Telepilot_TestCase {
    public function test_scheduling_a_draft_does_not_publish_it_immediately() {
        $actor = $this->actor(); wp_set_current_user( $actor['wp_user']->ID );
        $id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $actor['wp_user']->ID ) );
        $date = new DateTimeImmutable( '+2 days', wp_timezone() );
        $result = ( new Telepilot_Posts_Service( new Telepilot_Confirmation_Service() ) )->schedule( $id, $date );
        $this->assertNotWPError( $result );
        $this->assertSame( 'future', get_post_status( $id ) );
        $this->assertSame( $date->format( 'Y-m-d H:i:s' ), get_post( $id )->post_date );
    }

    public function test_nested_queue_acceptance_does_not_commit_a_content_transaction() {
        global $wpdb;
        $actor = $this->actor();
        update_option( 'telepilot_test_transaction', 'before' );
        $wpdb->query( 'COMMIT' );
        Telepilot_DB_Transaction::begin();
        update_option( 'telepilot_test_transaction', 'after' );
        $id = ( new Telepilot_Telegram_Service() )->queue_notification( $actor['chat_id'], 'Nested fixture', 'read' );
        $this->assertIsInt( $id );
        Telepilot_DB_Transaction::rollback();
        wp_cache_flush();
        $this->assertSame( 'before', get_option( 'telepilot_test_transaction' ) );
        $this->assertNull( Telepilot_Jobs_Repository::get( $id ) );
    }

    public function test_polling_keeps_its_offset_when_persistence_fails() {
        global $wpdb;
        $actor = $this->actor();
        update_option( 'telepilot_settings', array( 'bot_token' => 'fixture', 'transport_mode' => 'polling' ) );
        update_option( 'telepilot_telegram_poll_offset', 2000 );
        $this->mock_http( 'api.telegram.org', array( 'ok' => true, 'result' => array( array( 'update_id' => 2001, 'message' => array( 'date' => time(), 'text' => '/posts', 'from' => array( 'id' => $actor['telegram_user_id'] ), 'chat' => array( 'id' => $actor['chat_id'], 'type' => 'private' ) ) ) ) ) );
        $table = Telepilot_Jobs_Repository::table_name();
        $failure = static function ( $query ) use ( $table ) { return 0 === strpos( $query, "INSERT INTO `$table`" ) ? 'INTENTIONAL TEST DATABASE FAILURE' : $query; };
        $suppressed = $wpdb->suppress_errors(); add_filter( 'query', $failure );
        try { $result = ( new Telepilot_Telegram_Service() )->poll_updates(); }
        finally { remove_filter( 'query', $failure ); $wpdb->suppress_errors( $suppressed ); }
        $this->assertWPError( $result );
        $this->assertSame( 'telepilot_poll_queue_failed', $result->get_error_code() );
        $this->assertSame( 2000, (int) get_option( 'telepilot_telegram_poll_offset' ) );
    }
}
