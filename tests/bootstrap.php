<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
require dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/functions.php';
tests_add_filter( 'muplugins_loaded', function () {
    // Deny outbound HTTP, including during plugin bootstrap and WordPress upgrades.
    add_filter( 'pre_http_request', function () {
        return new WP_Error( 'telepilot_test_network_blocked', 'No external network in tests.' );
    }, PHP_INT_MAX );
    require getenv( 'TELEPILOT_TEST_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/wp-telepilot.php';
} );
require dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
require __DIR__ . '/support/TestCase.php';
