<?php
/** Real DDL, real database permissions, and historical-schema regression coverage. */
class QueueSchemaTest extends Telepilot_TestCase {
    public function set_up() {
        parent::set_up();
        global $wpdb;
        foreach ( array( Telepilot_Jobs_Repository::table_name(), Telepilot_Jobs_Repository::operations_table(), Telepilot_Jobs_Repository::locks_table() ) as $table ) { $wpdb->query( "DELETE FROM $table" ); }
        delete_option( Telepilot_Queue_Schema::REVISION_OPTION );
        delete_option( Telepilot_Queue_Schema::STATUS_OPTION );
        delete_option( Telepilot_Queue_Schema::LOCK_OPTION );
    }

    public function tear_down() {
        global $wpdb;
        $wpdb->query( 'ALTER TABLE ' . Telepilot_Jobs_Repository::table_name() . ' MODIFY COLUMN update_id BIGINT UNSIGNED NULL DEFAULT NULL' );
        delete_option( Telepilot_Queue_Schema::LOCK_OPTION );
        Telepilot_Queue_Schema::ensure( true );
        parent::tear_down();
    }

    private function legacy() {
        global $wpdb;
        $this->assertNotFalse( $wpdb->query( 'ALTER TABLE ' . Telepilot_Jobs_Repository::table_name() . ' MODIFY COLUMN update_id BIGINT UNSIGNED NOT NULL' ) );
        delete_option( Telepilot_Queue_Schema::REVISION_OPTION );
        $this->assertFalse( Telepilot_Queue_Schema::inspect()['ready'] );
    }

    public function versions() { return array( array( '0.3.0' ), array( '0.4.0-beta.1' ), array( '0.4.0-beta.2' ) ); }

    /** @dataProvider versions */
    public function test_real_upgrade_repairs_historical_schema_even_at_current_version( $version ) {
        global $wpdb;
        $this->legacy();
        $id = Telepilot_Jobs_Repository::enqueue( 710001, 'webhook', array( 'fixture' => 'preserve' ), '/menu' );
        $before = Telepilot_Jobs_Repository::get( $id );
        $actor = $this->actor();
        update_option( 'telepilot_settings', array( 'bot_token' => 'fixture-preserved', 'transport_mode' => 'webhook' ) );
        update_option( 'telepilot_schema_version', $version );
        ( new Telepilot_Bootstrap() )->maybe_upgrade_schema();
        $this->assertTrue( Telepilot_Queue_Schema::status()['ready'] );
        $this->assertSame( $before, Telepilot_Jobs_Repository::get( $id ) );
        $this->assertSame( 'fixture-preserved', get_option( 'telepilot_settings' )['bot_token'] );
        $this->assertSame( $actor['chat_id'], get_user_meta( $actor['wp_user']->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ) );
        $this->assertSame( 'duplicate', Telepilot_Jobs_Repository::enqueue( 710001, 'webhook', array() ) );
        $errors = $wpdb->suppress_errors();
        $this->assertFalse( $wpdb->query( 'INSERT INTO ' . Telepilot_Jobs_Repository::table_name() . ' (update_id,transport,payload,available_at,created_at,updated_at) SELECT update_id,transport,payload,available_at,created_at,updated_at FROM ' . Telepilot_Jobs_Repository::table_name() . ' WHERE id=' . (int) $id ) );
        $wpdb->suppress_errors( $errors );
        $this->assertIsInt( Telepilot_Jobs_Repository::enqueue( null, 'ai_continuation', array() ) );
        $this->assertIsInt( Telepilot_Jobs_Repository::enqueue( null, 'ai_continuation', array() ) );
        $alters = 0;
        $watch = static function ( $query ) use ( &$alters ) { if ( 0 === strpos( $query, 'ALTER TABLE' ) ) { $alters++; } return $query; };
        add_filter( 'query', $watch );
        Telepilot_Queue_Schema::ensure( true );
        ( new Telepilot_Bootstrap() )->maybe_upgrade_schema();
        remove_filter( 'query', $watch );
        $this->assertSame( 0, $alters, 'Repeated repairs must only inspect an already compatible column.' );
    }

    public function test_unexpected_type_and_missing_unique_index_are_not_blindly_changed() {
        global $wpdb;
        $table = Telepilot_Jobs_Repository::table_name();
        $wpdb->query( "ALTER TABLE $table MODIFY COLUMN update_id VARCHAR(64) NULL" );
        $result = Telepilot_Queue_Schema::ensure();
        $this->assertSame( 'telepilot_queue_repair_unexpected_column', $result->get_error_code() );
        $this->assertFalse( get_option( Telepilot_Queue_Schema::REVISION_OPTION ) );
        $this->assertStringStartsWith( 'varchar', $wpdb->get_row( "SHOW COLUMNS FROM $table LIKE 'update_id'", ARRAY_A )['Type'] );
        $wpdb->query( "ALTER TABLE $table MODIFY COLUMN update_id BIGINT UNSIGNED NULL, DROP INDEX update_id" );
        try {
            $this->assertSame( 'telepilot_queue_repair_unexpected_index', Telepilot_Queue_Schema::ensure( true )->get_error_code() );
        } finally { $wpdb->query( "ALTER TABLE $table ADD UNIQUE KEY update_id (update_id)" ); }
    }

    public function test_denied_alter_is_throttled_and_manual_retry_recovers() {
        global $wpdb;
        $this->legacy();
        $root = $wpdb;
        $name = 'tp_repair_' . substr( md5( wp_generate_uuid4() ), 0, 12 );
        $root->query( "CREATE USER '$name'@'%' IDENTIFIED BY 'test-only-repair'" );
        $root->query( "GRANT SELECT, INSERT, UPDATE, DELETE ON `" . DB_NAME . "`.* TO '$name'@'%'" );
        $restricted = new wpdb( $name, 'test-only-repair', DB_NAME, DB_HOST );
        $restricted->set_prefix( $root->prefix );
        $restricted->query( 'SET SESSION sql_mode=\'STRICT_ALL_TABLES\'' );
        $root->query( 'COMMIT' );
        try {
            $wpdb = $restricted;
            $result = Telepilot_Queue_Schema::ensure();
            $this->assertSame( 'telepilot_queue_repair_alter_failed', $result->get_error_code() );
            $this->assertSame( 1142, $result->get_error_data()['database_errno'] );
            $this->assertFalse( get_option( Telepilot_Queue_Schema::REVISION_OPTION ) );
            $this->assertGreaterThan( time() + 290, get_option( Telepilot_Queue_Schema::STATUS_OPTION )['next_retry'] );
            $this->assertSame( 'telepilot_queue_repair_deferred', Telepilot_Queue_Schema::ensure()->get_error_code() );
        } finally { $wpdb = $root; $restricted->close(); $root->query( "DROP USER '$name'@'%'" ); }
        $this->assertTrue( Telepilot_Queue_Schema::ensure( true ) );
        $this->assertTrue( Telepilot_Queue_Schema::status()['ready'] );
    }

    public function test_expired_lease_and_interrupted_success_are_recoverable() {
        global $wpdb;
        $this->legacy();
        update_option( Telepilot_Queue_Schema::LOCK_OPTION, ( time() + 300 ) . ':old-worker', false );
        $this->assertSame( 'telepilot_queue_repair_busy', Telepilot_Queue_Schema::ensure( true )->get_error_code() );
        update_option( Telepilot_Queue_Schema::LOCK_OPTION, ( time() - 1 ) . ':old-worker', false );
        update_option( Telepilot_Queue_Schema::STATUS_OPTION, array( 'next_retry' => time() - 1, 'message' => 'Interrupted' ), false );
        // Simulate death after DDL but before writing the success revision.
        $wpdb->query( 'ALTER TABLE ' . Telepilot_Jobs_Repository::table_name() . ' MODIFY COLUMN update_id BIGINT UNSIGNED NULL DEFAULT NULL' );
        $this->assertTrue( Telepilot_Queue_Schema::ensure() );
        $this->assertSame( Telepilot_Queue_Schema::REVISION, get_option( Telepilot_Queue_Schema::REVISION_OPTION ) );
    }

    public function test_job_transactions_cannot_run_schema_changes() {
        Telepilot_DB_Transaction::begin();
        try { $this->assertSame( 'telepilot_queue_repair_transaction', Telepilot_Queue_Schema::ensure( true )->get_error_code() ); }
        finally { Telepilot_DB_Transaction::rollback(); }
    }

    public function test_operation_insert_failure_rolls_back_the_queue_record() {
        global $wpdb;
        $table = Telepilot_Jobs_Repository::operations_table();
        $fail = static function ( $query ) use ( $table ) { return 0 === strpos( $query, "INSERT INTO `$table`" ) ? 'INSERT INTO telepilot_missing_test_table VALUES (1)' : $query; };
        add_filter( 'query', $fail );
        try { $result = Telepilot_Jobs_Repository::enqueue( null, 'ai_continuation', array( 'content' => 'private fixture' ) ); }
        finally { remove_filter( 'query', $fail ); }
        $this->assertSame( 'telepilot_operation_enqueue_failed', $result->get_error_code() );
        $this->assertSame( 'operation_insert', $result->get_error_data()['stage'] );
        $this->assertSame( 1146, $result->get_error_data()['database_errno'] );
        $this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Telepilot_Jobs_Repository::table_name() ) );
    }

    public function test_site_health_distinguishes_tables_from_usable_ai_queue() {
        $this->legacy();
        $this->assertTrue( Telepilot_Queue_Schema::table_exists() );
        $this->assertFalse( Telepilot_Queue_Schema::status()['ready'] );
        $health = Telepilot_Site_Health::test();
        $this->assertSame( 'recommended', $health['status'] );
        $this->assertStringContainsString( 'AI continuation queue schema is not verified', $health['description'] );
        $this->assertTrue( Telepilot_Queue_Schema::ensure() );
        $this->assertStringNotContainsString( 'AI continuation queue schema is not verified', Telepilot_Site_Health::test()['description'] );
    }

    public function test_enqueue_error_is_captured_before_rollback_without_sensitive_data() {
        global $wpdb;
        $this->legacy();
        $wpdb->query( "SET SESSION sql_mode='STRICT_ALL_TABLES'" );
        $result = Telepilot_Jobs_Repository::enqueue( null, 'ai_continuation', array( 'content' => 'private-conversation-sk-123' ) );
        $this->assertWPError( $result );
        $data = $result->get_error_data();
        $this->assertSame( 'null_constraint', $data['error_category'] );
        $this->assertSame( 1048, $data['database_errno'] );
        $this->assertSame( 36, strlen( $data['correlation_id'] ) );
        $record = get_option( 'telepilot_queue_enqueue_failure' );
        $this->assertSame( $data['correlation_id'], $record['correlation_id'] );
        $this->assertStringNotContainsString( 'private-conversation', wp_json_encode( $record ) );
        $this->assertStringNotContainsString( 'INSERT', wp_json_encode( $record ) );
        $this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Telepilot_Jobs_Repository::table_name() ) );
    }

    public function test_retry_button_requires_admin_and_nonce() {
        $this->legacy();
        $_GET['page'] = 'telepilot'; $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array( 'telepilot_retry_database_repair' => '1' );
        wp_set_current_user( $this->actor()['wp_user']->ID );
        $page = new Telepilot_Settings_Page();
        $page->handle_tools_actions();
        $this->assertFalse( Telepilot_Queue_Schema::inspect()['ready'] );
        wp_set_current_user( $this->actor( 'subscriber' )['wp_user']->ID );
        $_POST['telepilot_tools_nonce'] = wp_create_nonce( 'telepilot_tools_actions' );
        $page->handle_tools_actions();
        $this->assertFalse( Telepilot_Queue_Schema::inspect()['ready'] );
        wp_set_current_user( $this->actor()['wp_user']->ID );
        $_POST['telepilot_tools_nonce'] = wp_create_nonce( 'telepilot_tools_actions' );
        $page->handle_tools_actions();
        $this->assertTrue( Telepilot_Queue_Schema::status()['ready'] );
        $_POST = array(); unset( $_GET['page'] );
    }
}
