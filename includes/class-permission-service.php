<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Permission_Service {
	public function user_can( $wp_user, $capability, $object_id = null ) {
		if ( ! ( $wp_user instanceof WP_User ) ) {
			return false;
		}

		if ( null === $object_id ) {
			return user_can( $wp_user, $capability );
		}

		return user_can( $wp_user, $capability, $object_id );
	}

	public function require_linked_user( $identity ) {
		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Link Required', 'telepilot' ) ),
					__( 'This Telegram account is not linked to a WordPress user yet.', 'telepilot' ),
					sprintf(
						__( 'Generate a one-time code from your WordPress profile, then send %s in this private chat.', 'telepilot' ),
						Telepilot_Telegram_Response_Builder::code( '/link CODE' )
					),
					Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use /chatid if you need to confirm which Telegram chat you are in.', 'telepilot' ) ),
				)
			),
			array(
				'code' => 'telepilot_link_required',
			)
		);
	}

	public function require_capability( $identity, $capability, $object_id = null ) {
		$link_result = $this->require_linked_user( $identity );

		if ( true !== $link_result ) {
			return $link_result;
		}

		if ( $this->user_can( $identity['wp_user'], $capability, $object_id ) ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Permission Denied', 'telepilot' ) ),
					__( 'Your linked WordPress account does not have permission to perform that action.', 'telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Use /menu to see what is available to your account.', 'telepilot' ) ),
				)
			),
			array(
				'code'       => 'telepilot_capability_denied',
				'capability' => $capability,
			)
		);
	}

	public function require_private_chat( $identity ) {
		if ( ! empty( $identity['chat_type'] ) && 'private' === $identity['chat_type'] ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Private Chat Required', 'telepilot' ) ),
					__( 'This action is only available in a private chat with your WP Telepilot bot.', 'telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Open the bot directly, then try the command again.', 'telepilot' ) ),
				)
			),
			array(
				'code' => 'telepilot_private_chat_required',
			)
		);
	}
}
