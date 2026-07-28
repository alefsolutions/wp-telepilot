<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Audit_Logger {
	public function log( $identity, $action_name, $context = array(), $success = true, $resource_id = null, $failure_reason = '' ) {
		$settings = Telepilot_AI_Settings::get_settings();

		if ( empty( $settings['ai_log_activity'] ) ) {
			return;
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
				'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : null,
				'chat_id'          => ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : null,
				'action_name'      => (string) $action_name,
				'resource_type'    => 'telepilot_ai',
				'resource_id'      => null === $resource_id ? null : (string) $resource_id,
				'context'          => $context,
				'was_successful'   => $success ? 1 : 0,
				'failure_reason'   => $failure_reason ? (string) $failure_reason : null,
			)
		);
	}
}
