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

$settings         = get_option( 'telepilot_settings', array() );
$cleanup_enabled  = ! empty( $settings['cleanup_on_uninstall'] );
$bot_token        = isset( $settings['bot_token'] ) ? (string) $settings['bot_token'] : '';
$telegram_api_url = '';

if ( '' !== $bot_token ) {
	$telegram_api_url = 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/deleteWebhook';
	wp_remote_post(
		$telegram_api_url,
		array(
			'timeout' => 10,
			'body'    => array(
				'drop_pending_updates' => true,
			),
		)
	);
}

global $wpdb;
// Ephemeral request data is not a durable user setting, even in retain-data mode.
foreach ( array( 'telepilot_jobs' => "payload='',status='cancelled',lease_token=NULL", 'telepilot_operations' => 'result=NULL', 'telepilot_security_records' => "payload='',state='consumed'" ) as $suffix => $assignment ) {
	$table = $wpdb->prefix . $suffix;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
		$wpdb->query( "UPDATE $table SET $assignment" );
	}
}

if ( ! $cleanup_enabled ) {
		update_option( 'telepilot_access_epoch', wp_generate_uuid4(), false );
	return;
}

$options_to_delete = array(
	'telepilot_settings',
	'telepilot_schema_version',
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

foreach ( $options_to_delete as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_telepilot_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_telepilot_' ) . '%'
	)
);

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
		'_telepilot_link_code',
		'_telepilot_link_code_hash',
		'_telepilot_link_expires',
		'_telepilot_telegram_user_id',
		'_telepilot_telegram_chat_id',
		'_telepilot_telegram_username',
		'_telepilot_linked_at',
		'_telepilot_disabled',
		'_telepilot_last_command_at'
	)
);

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_audit_logs' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_jobs' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_processed_updates' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_operations' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_job_locks' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'telepilot_security_records' );
delete_metadata( 'user', 0, '_telepilot_link_generation', '', true );
