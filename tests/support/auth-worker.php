<?php
// Exercise authentication before init against the disposable integration database.
$input = json_decode( trim( fgets( STDIN ) ), true );
require dirname( __DIR__ ) . '/wp-tests-config.php';
define( 'WP_USE_THEMES', false );
define( 'DISABLE_WP_CRON', true );
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_http_request', static function () { return new WP_Error( 'test_network_blocked', 'Network disabled.' ); }, PHP_INT_MAX );
add_action( 'muplugins_loaded', static function () { require getenv( 'TELEPILOT_TEST_PLUGIN_FILE' ) ?: dirname( __DIR__, 2 ) . '/wp-telepilot.php'; } );
add_action( 'plugins_loaded', static function () use ( $input ) {
    add_filter( 'wp_is_application_passwords_available', '__return_true' );
    add_filter( 'application_password_is_api_request', '__return_true' );
    add_filter( 'salt', static function ( $salt, $scheme ) { return 'telepilot-auth-worker-fixture-' . $scheme; }, 10, 2 );
    $user = get_user_by( 'id', $input['user_id'] );
    $_COOKIE[AUTH_COOKIE] = $input['cookie'];
    $valid_cookie = $user->ID === wp_validate_auth_cookie( $input['cookie'], 'auth' );
    $id = apply_filters( 'determine_current_user', false );
    $password = wp_authenticate( $user->user_login, $input['password'] );
    $application = wp_authenticate_application_password( null, $user->user_login, $input['application_password'] );
    echo wp_json_encode( array( 'before_init' => ! did_action( 'init' ), 'cookie_initially_valid' => $valid_cookie, 'current_user' => $id, 'password_denied' => is_wp_error( $password ), 'application_denied' => is_wp_error( $application ), 'cookie_denied' => false === wp_validate_auth_cookie( $input['cookie'], 'auth' ) ) );
    exit;
}, PHP_INT_MAX );
require ABSPATH . 'wp-settings.php';
