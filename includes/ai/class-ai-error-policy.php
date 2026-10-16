<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Terminal tool failures are handled by the application, not negotiated by the model. */
final class Telepilot_AI_Error_Policy {
	public static function terminal_message( $error ) {
		$messages = array(
			'telepilot_ai_tool_disabled' => 'This AI task is disabled by the site administrator. An administrator can review the AI task settings.',
			'telepilot_ai_access_denied' => 'AI access is no longer available for your account. An administrator can review your access.',
			'telepilot_ai_session_invalid' => 'This AI session has expired or changed. Use /chat to start an authorized session.',
			'telepilot_actor_denied' => 'Your account is no longer authorized for this action. An administrator can review your access.',
			'telepilot_chat_denied' => 'This action requires your authorized, linked private Telegram chat.',
			'telepilot_actor_revoked' => 'Your Telegram link has changed or been revoked. Use /start to review your connection.',
			'telepilot_capability_denied' => 'Your WordPress account cannot perform this action. An administrator can review your permissions.',
			'telepilot_target_denied' => 'Your WordPress account cannot access or change that item. An administrator can review your permissions.',
			'telepilot_target_changed' => 'That item changed or is not in a supported state for this action. No action was executed. Request a fresh lookup.',
			'telepilot_ai_update_draft_invalid' => 'This AI editing task only supports current drafts. Use the authorized WordPress editor for other content.',
			'telepilot_publish_denied' => 'Your WordPress account does not have publishing permission. An administrator can review your permissions.',
			'telepilot_term_assignment_denied' => 'The selected categories or tags are unavailable or cannot be assigned by your account. An administrator can review the selection and permissions.',
			'telepilot_role_escalation' => 'Your account cannot grant those permissions. An administrator can review the requested role.',
			'telepilot_actor_mismatch' => 'This request does not match the authenticated account. No action was executed.',
			'telepilot_confirmation_required' => 'This action could not be prepared through the authorized confirmation workflow. No action was executed.',
			'telepilot_mcp_read_only' => 'This interface supports read-only operations. No action was executed.',
			'telepilot_ability_missing' => 'That action is not supported by AI chat. Use /help to review available site-management commands.',
			'telepilot_ai_tool_missing' => 'That action is not supported by AI chat. Use /help to review available site-management commands.',
			'telepilot_ai_tool_unsupported' => 'That action is not supported by AI chat. Use /help to review available site-management commands.',
			'telepilot_ai_prepare_unsupported' => 'That action cannot be prepared by AI chat. Use /help to review available site-management commands.',
		);
		foreach ( $error->get_error_codes() as $code ) {
			if ( isset( $messages[ $code ] ) ) { return $messages[ $code ]; }
		}
		return null;
	}
}
