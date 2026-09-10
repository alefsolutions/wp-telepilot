<?php
// This database is disposable. Never point these tests at a WordPress installation.
$database = getenv( 'TELEPILOT_TEST_DB_NAME' ) ?: 'telepilot_tests';
if ( ! preg_match( '/^telepilot_tests(?:_[a-z0-9]+)?$/D', $database ) ) {
    throw new RuntimeException( 'Refusing a non-test database.' );
}
define( 'DB_NAME', $database );
define( 'DB_USER', getenv( 'TELEPILOT_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'TELEPILOT_TEST_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'TELEPILOT_TEST_DB_HOST' ) ?: '127.0.0.1:3308' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'ABSPATH', dirname( __DIR__ ) . '/.test-env/wordpress/' );
define( 'WP_TESTS_DOMAIN', 'telepilot.test' );
define( 'WP_TESTS_EMAIL', 'tester@example.test' );
define( 'WP_TESTS_TITLE', 'Isolated WP Telepilot Tests' );
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) );
define( 'WP_DEBUG', true );
$table_prefix = 'wptests_';
