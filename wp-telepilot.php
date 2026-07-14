<?php
/**
 * Plugin Name: WP Telepilot
 * Plugin URI: https://alefdigitalsolutions.com
 * Description: Manage key WordPress operations from Telegram with secure remote workflows, audit logging, and WP Telepilot guidance.
 * Version: 0.2.2
 * Author: Alef Digital Solutions
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Text Domain: telepilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TELEPILOT_VERSION', '0.2.2' );
define( 'TELEPILOT_FILE', __FILE__ );
define( 'TELEPILOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'TELEPILOT_URL', plugin_dir_url( __FILE__ ) );

require_once TELEPILOT_PATH . 'includes/class-activator.php';
require_once TELEPILOT_PATH . 'includes/class-deactivator.php';
require_once TELEPILOT_PATH . 'includes/class-audit-log-repository.php';
require_once TELEPILOT_PATH . 'includes/class-jobs-repository.php';
require_once TELEPILOT_PATH . 'includes/class-processed-updates-repository.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-response-builder.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-client.php';
require_once TELEPILOT_PATH . 'includes/class-user-linking-service.php';
require_once TELEPILOT_PATH . 'includes/class-linked-user-resolver.php';
require_once TELEPILOT_PATH . 'includes/class-permission-service.php';
require_once TELEPILOT_PATH . 'includes/class-rate-limiter.php';
require_once TELEPILOT_PATH . 'includes/class-confirmation-service.php';
require_once TELEPILOT_PATH . 'includes/class-dashboard-service.php';
require_once TELEPILOT_PATH . 'includes/class-comments-service.php';
require_once TELEPILOT_PATH . 'includes/class-posts-service.php';
require_once TELEPILOT_PATH . 'includes/class-post-editor-service.php';
require_once TELEPILOT_PATH . 'includes/class-pages-service.php';
require_once TELEPILOT_PATH . 'includes/class-media-service.php';
require_once TELEPILOT_PATH . 'includes/class-users-service.php';
require_once TELEPILOT_PATH . 'includes/class-plugins-service.php';
require_once TELEPILOT_PATH . 'includes/class-taxonomies-service.php';
require_once TELEPILOT_PATH . 'includes/class-notification-service.php';
require_once TELEPILOT_PATH . 'includes/class-notifications-command-service.php';
require_once TELEPILOT_PATH . 'includes/class-site-settings-command-service.php';
require_once TELEPILOT_PATH . 'includes/class-command-router.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-service.php';
require_once TELEPILOT_PATH . 'includes/class-rest-webhook-controller.php';
require_once TELEPILOT_PATH . 'includes/class-settings-page.php';
require_once TELEPILOT_PATH . 'includes/class-bootstrap.php';

register_activation_hook( __FILE__, array( 'Telepilot_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Telepilot_Deactivator', 'deactivate' ) );

function telepilot() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Telepilot_Bootstrap();
	}

	return $plugin;
}

add_action(
	'plugins_loaded',
	function() {
		telepilot()->boot();
	}
);
