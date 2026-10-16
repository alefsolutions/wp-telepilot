<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'telepilot_daily_maintenance' );
wp_clear_scheduled_hook( 'telepilot_hourly_maintenance' );
wp_clear_scheduled_hook( 'telepilot_poll_updates' );
wp_clear_scheduled_hook( 'telepilot_process_jobs' );
delete_transient( 'telepilot_poll_lock' );
delete_transient( 'telepilot_admin_notice' );
delete_transient( 'telepilot_dashboard_summary' );

$telepilot_settings         = get_option( 'telepilot_settings', array() );
$telepilot_cleanup_enabled  = ! empty( $telepilot_settings['cleanup_on_uninstall'] );
$telepilot_bot_token        = isset( $telepilot_settings['bot_token'] ) ? (string) $telepilot_settings['bot_token'] : '';
$telepilot_telegram_api_url = '';

if ( '' !== $telepilot_bot_token ) {
	$telepilot_telegram_api_url = 'https://api.telegram.org/bot' . rawurlencode( $telepilot_bot_token ) . '/deleteWebhook';
	wp_remote_post(
		$telepilot_telegram_api_url,
		array(
			'timeout' => 10,
			'body'    => array(
				'drop_pending_updates' => true,
			),
		)
	);
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$telepilot_ai_sessions = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id,meta_value FROM %i WHERE meta_key=%s', $wpdb->usermeta, '_telepilot_ai_session_' . get_current_blog_id() ), ARRAY_A );
foreach ( $telepilot_ai_sessions as $telepilot_entry ) {
	$telepilot_session = maybe_unserialize( $telepilot_entry['meta_value'] );
	if ( ! empty( $telepilot_session['session_id'] ) ) { delete_transient( 'telepilot_ai_context_' . md5( $telepilot_session['session_id'] ) ); }
	delete_user_meta( $telepilot_entry['user_id'], '_telepilot_ai_session_' . get_current_blog_id() );
}
// Ephemeral request data is not a durable user setting, even in retain-data mode.
foreach ( array( 'telepilot_jobs', 'telepilot_operations', 'telepilot_security_records' ) as $telepilot_suffix ) {
	$telepilot_table = $wpdb->prefix . $telepilot_suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $telepilot_table ) ) ) !== $telepilot_table ) { continue; }
	switch ( $telepilot_suffix ) {
		case 'telepilot_jobs':
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET payload='',status='cancelled',lease_token=NULL", $telepilot_table ) );
			break;
		case 'telepilot_operations':
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET result=NULL', $telepilot_table ) );
			break;
		case 'telepilot_security_records':
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET payload='',state='consumed'", $telepilot_table ) );
			break;
	}
}

if ( ! $telepilot_cleanup_enabled ) {
		update_option( 'telepilot_access_epoch', wp_generate_uuid4(), false );
	return;
}

$telepilot_options_to_delete = array(
	'telepilot_settings',
	'telepilot_schema_version',
	'telepilot_queue_repair_revision',
	'telepilot_queue_repair_status',
	'telepilot_queue_repair_lock',
	'telepilot_queue_enqueue_failure',
	'telepilot_webhook_status',
	'telepilot_transport_diagnostics',
	'telepilot_command_diagnostics',
	'telepilot_telegram_poll_offset',
	'telepilot_comments_cache_version',
	'telepilot_posts_cache_version',
	'telepilot_pages_cache_version',
	'telepilot_media_cache_version',
	'telepilot_users_cache_version',
	'telepilot_plugins_cache_version',
	'telepilot_terms_cache_version',
	'telepilot_admin_notice',
	'telepilot_access_epoch',
	'telepilot_ai_provider_status',
);

foreach ( $telepilot_options_to_delete as $telepilot_option_name ) {
	delete_option( $telepilot_option_name );
	delete_site_option( $telepilot_option_name );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$telepilot_ai_keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'telepilot_ai_' ) . '%' ) );
foreach ( $telepilot_ai_keys as $telepilot_key ) { delete_option( $telepilot_key ); }

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query(
	$wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_telepilot_' ) . '%', $wpdb->esc_like( '_transient_timeout_telepilot_' ) . '%' )
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query(
	$wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s, %s, %s)', $wpdb->usermeta, '_telepilot_link_code', '_telepilot_link_code_hash', '_telepilot_link_expires', '_telepilot_telegram_user_id', '_telepilot_telegram_chat_id', '_telepilot_telegram_username', '_telepilot_linked_at', '_telepilot_disabled', '_telepilot_last_command_at' )
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_audit_logs' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_jobs' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_processed_updates' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_operations' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_job_locks' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall immediately revokes ephemeral data; explicit cleanup removes only plugin-owned data and tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'telepilot_security_records' ) );
delete_metadata( 'user', 0, '_telepilot_link_generation', '', true );
