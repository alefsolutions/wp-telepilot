<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Chat_Handler {
	private $session_manager;
	private $permission_manager;
	private $provider;
	private $audit_logger;
	private $conversation_store;
	private $orchestrator;

	public function __construct() {
		$this->session_manager    = new Telepilot_AI_Session_Manager();
		$this->permission_manager = new Telepilot_AI_Permission_Manager();
		$this->provider           = new Telepilot_OpenAI_Provider();
		$this->audit_logger       = new Telepilot_AI_Audit_Logger();
		$this->conversation_store = new Telepilot_AI_Conversation_Store();
		$this->orchestrator       = new Telepilot_AI_Orchestrator();
	}

	public function start_chat_mode( $identity ) {
		$permission_result = $this->permission_manager->require_ai_mode_access( $identity );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		if ( $this->session_manager->is_active( $identity ) ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Already Active', 'wp-telepilot' ) ),
						__( 'AI chat mode is already active for this Telegram chat.', 'wp-telepilot' ),
						__( 'Use /newchat to start a fresh conversation or /endchat to exit AI mode.', 'wp-telepilot' ),
					)
				),
				array(
					'command'      => '/chat',
					'reply_markup' => $this->build_ai_keyboard(),
				)
			);
		}

		if ( ! $this->provider->is_configured() ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Unavailable', 'wp-telepilot' ) ),
						__( 'An administrator must configure an AI provider before AI chat mode can be used.', 'wp-telepilot' ),
						__( 'Open WP Telepilot settings and add the OpenAI API key first.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/chat',
					'code'    => 'telepilot_ai_unconfigured',
				)
			);
		}

		$connection = $this->provider->test_connection();
		if ( is_wp_error( $connection ) ) {
			Telepilot_AI_Settings::store_provider_status(
				array(
					'ok'         => 0,
					'message'    => $connection->get_error_message(),
					'checked_at' => time(),
					'provider'   => $this->provider->get_name(),
					'model'      => Telepilot_AI_Settings::get_model(),
				)
			);

			$this->audit_logger->log(
				$identity,
				'ai_mode_start_failed',
				array(
					'provider' => $this->provider->get_name(),
					'model'    => Telepilot_AI_Settings::get_model(),
				),
				false,
				null,
				$connection->get_error_message()
			);

			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Unavailable', 'wp-telepilot' ) ),
						__( 'The configured AI provider could not be reached successfully.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::italic( $connection->get_error_message() ),
					)
				),
				array(
					'command' => '/chat',
					'code'    => 'telepilot_ai_provider_unavailable',
				)
			);
		}

		Telepilot_AI_Settings::store_provider_status(
			array(
				'ok'         => 1,
				'message'    => isset( $connection['message'] ) ? (string) $connection['message'] : __( 'OpenAI connection verified successfully.', 'wp-telepilot' ),
				'checked_at' => time(),
				'provider'   => $this->provider->get_name(),
				'model'      => Telepilot_AI_Settings::get_model(),
			)
		);

		$session = $this->session_manager->start_session( $identity, $this->provider->get_name(), Telepilot_AI_Settings::get_model() );
		if ( ! empty( $session['session_id'] ) ) {
			$this->conversation_store->clear( $session['session_id'] );
		}

		$this->audit_logger->log(
			$identity,
			'ai_mode_entered',
			array(
				'session_id' => ! empty( $session['session_id'] ) ? (string) $session['session_id'] : '',
				'provider'   => $this->provider->get_name(),
				'model'      => Telepilot_AI_Settings::get_model(),
			)
		);

		$roles = ! empty( $identity['wp_user']->roles ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $identity['wp_user']->roles ) ) : __( 'No role', 'wp-telepilot' );

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Mode Active', 'wp-telepilot' ) ),
					implode(
						"\n",
						array(
							sprintf( __( 'Site: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_bloginfo( 'name' ) ) ),
							sprintf( __( 'Account: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $identity['wp_user']->display_name ? $identity['wp_user']->display_name : $identity['wp_user']->user_login ) ),
							sprintf( __( 'Access: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $roles ) ),
						)
					),
					__( 'You can now ask questions or give instructions in natural language.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Use /endchat to leave AI mode. Use /context to review the current AI session.', 'wp-telepilot' ) ),
				)
			),
			array(
				'command'      => '/chat',
				'reply_markup' => $this->build_ai_keyboard(),
			)
		);
	}

	public function end_chat_mode( $identity ) {
		$session = $this->session_manager->get_session( $identity );
		$this->session_manager->end_session( $identity );

		if ( is_array( $session ) && empty( $session['expired'] ) ) {
			if ( ! empty( $session['session_id'] ) ) {
				$this->conversation_store->clear( $session['session_id'] );
			}
			$this->audit_logger->log(
				$identity,
				'ai_mode_ended',
				array(
					'session_id' => ! empty( $session['session_id'] ) ? (string) $session['session_id'] : '',
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Mode Ended', 'wp-telepilot' ) ),
					__( 'The current AI conversation and pending AI actions were cleared.', 'wp-telepilot' ),
					__( 'You can continue using the normal WP Telepilot menu and commands.', 'wp-telepilot' ),
				)
			),
			array(
				'command' => '/endchat',
			)
		);
	}

	public function new_chat( $identity ) {
		$session = $this->session_manager->get_session( $identity );
		if ( ! is_array( $session ) || ! empty( $session['expired'] ) || empty( $session['ai_mode_active'] ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Not Active', 'wp-telepilot' ) ),
						__( 'Use /chat first to start an AI conversation.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/newchat',
				)
			);
		}

		$session['conversation_reference'] = '';
		$session['started_at']             = time();
		$session['last_activity_at']       = time();
		$session['expires_at']             = time() + ( Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS );
		$this->session_manager->update_session( $identity, $session );
		if ( ! empty( $session['session_id'] ) ) {
			$this->conversation_store->clear( $session['session_id'] );
		}

		$this->audit_logger->log(
			$identity,
			'ai_conversation_reset',
			array(
				'session_id' => ! empty( $session['session_id'] ) ? (string) $session['session_id'] : '',
			)
		);

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'New AI Conversation Started', 'wp-telepilot' ) ),
					__( 'Previous conversational context has been cleared.', 'wp-telepilot' ),
				)
			),
			array(
				'command'      => '/newchat',
				'reply_markup' => $this->build_ai_keyboard(),
			)
		);
	}

	public function render_context( $identity ) {
		$session = $this->session_manager->get_session( $identity );
		if ( ! is_array( $session ) || ! empty( $session['expired'] ) || empty( $session['ai_mode_active'] ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Not Active', 'wp-telepilot' ) ),
						__( 'Use /chat to begin an AI session first.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/context',
				)
			);
		}

		$this->session_manager->refresh_session( $identity, $session );
		$started_ago = human_time_diff( (int) $session['started_at'], time() );

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Context', 'wp-telepilot' ) ),
					implode(
						"\n",
						array(
							sprintf( __( 'Site: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_bloginfo( 'name' ) ) ),
							sprintf( __( 'WordPress user: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $identity['wp_user']->display_name ? $identity['wp_user']->display_name : $identity['wp_user']->user_login ) ),
							sprintf( __( 'Role: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( implode( ', ', (array) $identity['wp_user']->roles ) ) ),
							__( 'Mode: AI chat', 'wp-telepilot' ),
							sprintf( __( 'Conversation started: %s ago', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $started_ago ) ),
							sprintf( __( 'Pending action: %s', 'wp-telepilot' ), ! empty( $session['pending_action_id'] ) ? Telepilot_Telegram_Response_Builder::code( $session['pending_action_id'] ) : __( 'None', 'wp-telepilot' ) ),
						)
					),
				)
			),
			array(
				'command'      => '/context',
				'reply_markup' => $this->build_ai_keyboard(),
			)
		);
	}

	public function cancel_pending_action( $identity ) {
		$session = $this->session_manager->get_session( $identity );
		if ( ! is_array( $session ) || ! empty( $session['expired'] ) || empty( $session['ai_mode_active'] ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Nothing To Cancel', 'wp-telepilot' ) ),
						__( 'There is no active AI session or pending AI action to cancel right now.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/cancel',
				)
			);
		}

		$session['pending_action_id'] = '';
		$this->session_manager->update_session( $identity, $session );

		$this->audit_logger->log(
			$identity,
			'ai_pending_action_cleared',
			array(
				'session_id' => ! empty( $session['session_id'] ) ? (string) $session['session_id'] : '',
			)
		);

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Pending Action Cancelled', 'wp-telepilot' ) ),
					__( 'The current AI pending action has been cleared.', 'wp-telepilot' ),
				)
			),
			array(
				'command'      => '/cancel',
				'reply_markup' => $this->build_ai_keyboard(),
			)
		);
	}

	public function handle_text_message( $update, $identity ) {
		$session = $this->session_manager->get_session( $identity );
		if ( is_array( $session ) && ! empty( $session['expired'] ) ) {
			$this->audit_logger->log( $identity, 'ai_session_expired' );

			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Session Expired', 'wp-telepilot' ) ),
						__( 'Your AI chat session expired due to inactivity.', 'wp-telepilot' ),
						__( 'Use /chat to begin a new AI session.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/chat',
				)
			);
		}

		if ( ! is_array( $session ) || empty( $session['ai_mode_active'] ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Not Active', 'wp-telepilot' ) ),
						__( 'Use /chat to begin an AI session first.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/chat',
				)
			);
		}

		$session = $this->session_manager->refresh_session( $identity, $session );
		$text   = ! empty( $update['message']['text'] ) ? trim( wp_strip_all_tags( (string) $update['message']['text'] ) ) : '';
		$result = $this->orchestrator->handle_message( $text, $identity, $session );

		if ( is_array( $result ) && empty( $result['reply_markup'] ) ) {
			$result['reply_markup'] = $this->build_ai_keyboard();
		}

		return $result;
	}

	public function render_help( $identity ) {
		$rows = array(
			Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Help', 'wp-telepilot' ) ),
			Telepilot_Telegram_Response_Builder::code( '/chat' ) . ' ' . __( 'Enter AI mode', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/context' ) . ' ' . __( 'Show current AI session context', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/newchat' ) . ' ' . __( 'Start a fresh AI conversation', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/cancel' ) . ' ' . __( 'Cancel the current pending AI action', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/endchat' ) . ' ' . __( 'Leave AI mode', 'wp-telepilot' ),
			'',
			__( 'Natural-language WordPress actions are introduced progressively across the V4 phases. Explicit slash commands still take priority over AI interpretation.', 'wp-telepilot' ),
		);

		return Telepilot_Telegram_Response_Builder::success_html(
			implode( "\n", $rows ),
			array(
				'command'      => '/help',
				'reply_markup' => $this->build_ai_keyboard(),
			)
		);
	}

	public function is_active( $identity ) {
		return $this->session_manager->is_active( $identity );
	}

	private function build_ai_keyboard() {
		return Telepilot_Telegram_Response_Builder::keyboard(
			array(
				array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'context', __( 'Context', 'wp-telepilot' ) ),
						'callback_data' => '/context',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'refresh', __( 'New Chat', 'wp-telepilot' ) ),
						'callback_data' => '/newchat',
					),
				),
				array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'cancel', __( 'Cancel', 'wp-telepilot' ) ),
						'callback_data' => '/cancel',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'End Chat', 'wp-telepilot' ) ),
						'callback_data' => '/endchat',
					),
				),
			)
		);
	}
}
