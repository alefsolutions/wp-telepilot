<?php
class ConcurrencyTest extends Telepilot_TestCase {
    private function race( $inputs ) {
        $workers = array();
        try {
            foreach ( $inputs as $input ) {
                $process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/support/concurrent-worker.php' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
                $this->assertIsResource( $process );
                $workers[] = array( $process, $pipes, $input );
            }
            foreach ( $workers as $worker ) { $this->assertSame( "READY\n", fgets( $worker[1][1] ) ); }
            foreach ( $workers as $worker ) { fwrite( $worker[1][0], wp_json_encode( $worker[2] ) . "\n" ); fclose( $worker[1][0] ); }
            $results = array();
            foreach ( $workers as &$worker ) {
                $output = stream_get_contents( $worker[1][1] ); $errors = stream_get_contents( $worker[1][2] );
                fclose( $worker[1][1] ); fclose( $worker[1][2] );
                $code = proc_close( $worker[0] ); $worker[0] = null;
                $this->assertSame( 0, $code, $errors . $output );
                $results[] = json_decode( $output, true );
                $this->assertSame( JSON_ERROR_NONE, json_last_error(), $errors . $output );
            }
            return $results;
        } finally { foreach ( $workers as $worker ) { if ( is_resource( $worker[0] ) ) { proc_terminate( $worker[0] ); proc_close( $worker[0] ); } } }
    }

    public function test_two_processes_cannot_claim_the_same_chat() {
        global $wpdb;
        foreach ( array( Telepilot_Jobs_Repository::table_name(), Telepilot_Jobs_Repository::operations_table(), Telepilot_Jobs_Repository::locks_table() ) as $table ) { $wpdb->query( "DELETE FROM $table" ); }
        $one = $this->actor(); $two = $this->actor();
        foreach ( array( $one, $one, $two ) as $actor ) { ( new Telepilot_Telegram_Service() )->queue_notification( $actor['chat_id'], 'Race fixture', 'read' ); }
        $results = $this->race( array( array( 'action' => 'claim' ), array( 'action' => 'claim' ) ) );
        $claims = array_merge( ...$results );
        $this->assertCount( 2, $claims );
        $this->assertCount( 2, array_unique( array_column( $claims, 'id' ) ) );
        $this->assertCount( 2, array_unique( array_column( $claims, 'chat' ) ) );
    }

    public function test_two_browser_saves_consume_a_link_only_once() {
        global $wpdb;
        $actor = $this->actor( 'author' );
        $id = self::factory()->post->create( array( 'post_author' => $actor['wp_user']->ID, 'post_status' => 'draft', 'post_content' => 'Original' ) );
        $link = ( new Telepilot_Post_Editor_Service() )->create_edit_link( $id, $actor['wp_user'], $actor['telegram_user_id'] );
        $wpdb->query( 'COMMIT' ); // Child connections must see the fixture before the barrier opens.
        $results = $this->race( array( array( 'action' => 'editor', 'token' => $link['token'], 'content' => 'Writer one' ), array( 'action' => 'editor', 'token' => $link['token'], 'content' => 'Writer two' ) ) );
        $this->assertSame( 1, count( array_filter( array_column( $results, 'ok' ) ) ), wp_json_encode( $results ) );
        wp_cache_flush();
        $this->assertContains( get_post( $id )->post_content, array( 'Writer one', 'Writer two' ) );
    }
}
