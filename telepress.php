<?php
/**
 * Plugin Name: TelePress
 * Plugin URI: https://alefdigitalsolutions.com
 * Description: Manage key WordPress operations from Telegram with secure remote workflows and audit logging.
 * Version: 0.1.0
 * Author: Alef Digital Solutions
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: telepress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TELEPRESS_VERSION', '0.1.0' );
define( 'TELEPRESS_FILE', __FILE__ );
define( 'TELEPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TELEPRESS_URL', plugin_dir_url( __FILE__ ) );

require_once TELEPRESS_PATH . 'includes/class-activator.php';
require_once TELEPRESS_PATH . 'includes/class-deactivator.php';
require_once TELEPRESS_PATH . 'includes/class-audit-log-repository.php';
require_once TELEPRESS_PATH . 'includes/class-telegram-response-builder.php';
require_once TELEPRESS_PATH . 'includes/class-telegram-client.php';
require_once TELEPRESS_PATH . 'includes/class-user-linking-service.php';
require_once TELEPRESS_PATH . 'includes/class-linked-user-resolver.php';
require_once TELEPRESS_PATH . 'includes/class-permission-service.php';
require_once TELEPRESS_PATH . 'includes/class-rate-limiter.php';
require_once TELEPRESS_PATH . 'includes/class-confirmation-service.php';
require_once TELEPRESS_PATH . 'includes/class-dashboard-service.php';
require_once TELEPRESS_PATH . 'includes/class-comments-service.php';
require_once TELEPRESS_PATH . 'includes/class-posts-service.php';
require_once TELEPRESS_PATH . 'includes/class-pages-service.php';
require_once TELEPRESS_PATH . 'includes/class-media-service.php';
require_once TELEPRESS_PATH . 'includes/class-users-service.php';
require_once TELEPRESS_PATH . 'includes/class-taxonomies-service.php';
require_once TELEPRESS_PATH . 'includes/class-notification-service.php';
require_once TELEPRESS_PATH . 'includes/class-command-router.php';
require_once TELEPRESS_PATH . 'includes/class-telegram-service.php';
require_once TELEPRESS_PATH . 'includes/class-rest-webhook-controller.php';
require_once TELEPRESS_PATH . 'includes/class-settings-page.php';
require_once TELEPRESS_PATH . 'includes/class-bootstrap.php';

register_activation_hook( __FILE__, array( 'TelePress_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TelePress_Deactivator', 'deactivate' ) );

function telepress() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new TelePress_Bootstrap();
	}

	return $plugin;
}

add_action(
	'plugins_loaded',
	function() {
		telepress()->boot();
	}
);
