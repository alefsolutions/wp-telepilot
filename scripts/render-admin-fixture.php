<?php
// Generates a local, non-production admin snapshot using the isolated test database.
require dirname( __DIR__ ) . '/tests/bootstrap.php';
$id = wp_insert_user( array( 'user_login' => 'visual_qa_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $id );
add_role( 'visual_qa_custom', 'Custom editorial team with an intentionally long capability-grant label', array( 'read' => true ) );
Telepilot_Audit_Log_Repository::create_table();
$timings = array();
for ( $index = 0; $index < 25; $index++ ) {
    Telepilot_Audit_Log_Repository::log( array( 'action_name' => 'telegram_command_received', 'resource_id' => '/pages list', 'was_successful' => 1 ) );
    $timings[] = array( 'command' => '/pages', 'transport' => 'webhook', 'duration_ms' => 12, 'dispatch_error' => '', 'recorded_at' => time() );
}
update_option( Telepilot_Telegram_Service::COMMAND_DIAGNOSTICS_OPTION, $timings );
if ( getenv( 'TELEPILOT_TEST_QUEUE_REPAIR_FAILURE' ) ) {
    delete_option( Telepilot_Queue_Schema::REVISION_OPTION );
    update_option( Telepilot_Queue_Schema::STATUS_OPTION, array( 'message' => 'Queue repair could not change update_id to nullable. Ask your host to check ALTER permission and database locks, then use Retry Database Repair.', 'next_retry' => time() + 300, 'error_category' => 'permission_denied', 'database_errno' => 1142, 'correlation_id' => 'ebf0b0d0-42f3-4f2e-a84e-37356fc68f29' ), false );
    update_option( 'telepilot_queue_enqueue_failure', array( 'error_category' => 'null_constraint', 'database_errno' => 1048, 'correlation_id' => 'ebf0b0d0-42f3-4f2e-a84e-37356fc68f29' ), false );
}
ob_start();
( new Telepilot_Settings_Page() )->render_page();
$body = ob_get_clean();
$logo = 'data:image/png;base64,' . base64_encode( file_get_contents( TELEPILOT_PATH . 'assets/images/telepilot-logo.png' ) );
$body = str_replace( esc_url( TELEPILOT_URL . 'assets/images/telepilot-logo.png' ), $logo, $body );
$css = file_get_contents( ABSPATH . 'wp-includes/css/buttons.css' ) . file_get_contents( ABSPATH . 'wp-admin/css/common.css' ) . file_get_contents( ABSPATH . 'wp-admin/css/forms.css' ) . file_get_contents( ABSPATH . 'wp-admin/css/list-tables.css' ) . file_get_contents( TELEPILOT_PATH . 'assets/css/admin.css' );
$frame = 'body{margin:0;font:13px sans-serif;background:#f0f0f1}#wpcontent{margin-left:160px;padding:32px 0 0 20px}.qa-sidebar{position:fixed;inset:0 auto 0 0;width:160px;background:#1d2327}@media(max-width:960px){#wpcontent{margin-left:36px}.qa-sidebar{width:36px}}@media(max-width:782px){#wpcontent{margin-left:0;padding:46px 0 0 10px}.qa-sidebar{display:none}}';
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Telepilot isolated admin QA</title><style>' . $css . $frame . '</style></head><body class="wp-core-ui"><aside class="qa-sidebar" aria-hidden="true"></aside><main id="wpcontent">' . $body . '</main><script>' . file_get_contents( TELEPILOT_PATH . 'assets/js/admin.js' ) . '</script></body></html>';
file_put_contents( dirname( __DIR__ ) . '/.test-env/admin-qa.html', $html );
echo "Generated isolated .test-env/admin-qa.html\n";
