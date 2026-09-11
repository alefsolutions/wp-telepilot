<?php
/**
 * Plugin Name: WP Telepilot
 * Plugin URI: https://alefdigitalsolutions.com
 * Description: Manage key WordPress operations from Telegram with secure remote workflows, audit logging, and WP Telepilot guidance.
 * Version: 0.4.0-beta.2
 * Author: Alef Digital Solutions
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-telepilot
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TELEPILOT_VERSION', '0.4.0-beta.2' );
define( 'TELEPILOT_FILE', __FILE__ );
define( 'TELEPILOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'TELEPILOT_URL', plugin_dir_url( __FILE__ ) );

require_once TELEPILOT_PATH . 'includes/class-activator.php';
require_once TELEPILOT_PATH . 'includes/class-deactivator.php';
require_once TELEPILOT_PATH . 'includes/class-audit-log-repository.php';
require_once TELEPILOT_PATH . 'includes/class-jobs-repository.php';
require_once TELEPILOT_PATH . 'includes/class-db-transaction.php';
require_once TELEPILOT_PATH . 'includes/class-queue-schema.php';
require_once TELEPILOT_PATH . 'includes/class-processed-updates-repository.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-settings.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-data-policy.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-admin-fields.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-audit-logger.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-session-manager.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-provider-interface.php';
require_once TELEPILOT_PATH . 'includes/ai/class-openai-provider.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-permission-manager.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-conversation-store.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-confirmation-manager.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ability-registry.php';
require_once TELEPILOT_PATH . 'includes/abilities/class-ability-executor.php';
require_once TELEPILOT_PATH . 'includes/abilities/class-core-ability-handlers.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-tool-registry.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-tool-executor.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-orchestrator.php';
require_once TELEPILOT_PATH . 'includes/ai/class-ai-chat-handler.php';
require_once TELEPILOT_PATH . 'includes/ai/class-mcp-integration-service.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-response-builder.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-client.php';
require_once TELEPILOT_PATH . 'includes/class-command-grammar.php';
require_once TELEPILOT_PATH . 'includes/class-user-linking-service.php';
require_once TELEPILOT_PATH . 'includes/class-linked-user-resolver.php';
require_once TELEPILOT_PATH . 'includes/class-permission-service.php';
require_once TELEPILOT_PATH . 'includes/class-actor-access.php';
require_once TELEPILOT_PATH . 'includes/class-domain-cache.php';
require_once TELEPILOT_PATH . 'includes/class-security-records.php';
require_once TELEPILOT_PATH . 'includes/class-data-redactor.php';
require_once TELEPILOT_PATH . 'includes/class-job-recovery-page.php';
require_once TELEPILOT_PATH . 'includes/class-operation-context.php';
require_once TELEPILOT_PATH . 'includes/class-domain-abilities.php';
require_once TELEPILOT_PATH . 'includes/class-rate-limiter.php';
require_once TELEPILOT_PATH . 'includes/class-confirmation-service.php';
require_once TELEPILOT_PATH . 'includes/class-flow-state-service.php';
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
require_once TELEPILOT_PATH . 'includes/class-privacy-service.php';
require_once TELEPILOT_PATH . 'includes/class-site-settings-command-service.php';
require_once TELEPILOT_PATH . 'includes/commands/trait-posts-command-handler.php';
require_once TELEPILOT_PATH . 'includes/commands/trait-pages-command-handler.php';
require_once TELEPILOT_PATH . 'includes/commands/trait-users-command-handler.php';
require_once TELEPILOT_PATH . 'includes/commands/trait-plugins-command-handler.php';
require_once TELEPILOT_PATH . 'includes/class-command-router.php';
require_once TELEPILOT_PATH . 'includes/class-telegram-service.php';
require_once TELEPILOT_PATH . 'includes/class-rest-webhook-controller.php';
require_once TELEPILOT_PATH . 'includes/class-settings-page.php';
require_once TELEPILOT_PATH . 'includes/class-site-health.php';
require_once TELEPILOT_PATH . 'includes/class-bootstrap.php';

register_activation_hook( __FILE__, array( 'Telepilot_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Telepilot_Deactivator', 'deactivate' ) );
add_action( 'telepilot_actor_revoked', array( 'Telepilot_AI_Session_Manager', 'erase_actor' ) );
add_action( 'telepilot_erase_actor_data', array( 'Telepilot_AI_Session_Manager', 'erase_actor' ) );
add_action( 'telepilot_ai_session_ended', array( 'Telepilot_AI_Confirmation_Manager', 'cancel_session_actions' ), 10, 2 );
( new Telepilot_MCP_Integration_Service() )->maybe_register_adapter();
add_filter( 'site_status_tests', array( 'Telepilot_Site_Health', 'register' ) );

function telepilot() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Telepilot_Bootstrap();
	}

	return $plugin;
}

add_action(
	'init',
	function() {
		telepilot()->boot();
	}
);
