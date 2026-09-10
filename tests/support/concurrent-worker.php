<?php
// Child process for races against the disposable test database, never a production bootstrap.
require dirname( __DIR__ ) . '/wp-tests-config.php';
define( 'WP_USE_THEMES', false );
define( 'DISABLE_WP_CRON', true );
require_once ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_http_request', static function () { return new WP_Error( 'test_network_blocked', 'Network disabled.' ); }, PHP_INT_MAX );
add_action( 'muplugins_loaded', static function () { require getenv( 'TELEPILOT_TEST_PLUGIN_FILE' ) ?: dirname( __DIR__, 2 ) . '/wp-telepilot.php'; } );
require ABSPATH . 'wp-settings.php';
echo "READY\n";
flush();
$input = json_decode( trim( fgets( STDIN ) ), true );
if ( 'claim' === $input['action'] ) {
    $result = Telepilot_Jobs_Repository::claim_pending_jobs();
    $result = array_map( static function ( $job ) { return array( 'id' => $job['id'], 'chat' => $job['placeholder_chat_id'] ); }, $result );
} elseif ( 'editor' === $input['action'] ) {
    $saved = ( new Telepilot_Post_Editor_Service() )->save( $input['token'], array( 'post_content' => $input['content'] ) );
    $result = array( 'ok' => ! is_wp_error( $saved ), 'result' => is_wp_error( $saved ) ? $saved->get_error_code() : $saved );
} else { throw new RuntimeException( 'Unknown test action.' ); }
echo wp_json_encode( $result );
