<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_AI_Chat_Handler {
	private $sessions;
	public function __construct() { $this->sessions = new Telepilot_AI_Session_Manager(); }
	public function is_active( $identity ) { return $this->sessions->is_active( $identity ); }

	public function start_chat_mode( $identity ) {
		$access = ( new Telepilot_AI_Permission_Manager() )->require_ai_mode_access( $identity );
		if ( true !== $access ) { return $access; }
		if ( ! Telepilot_AI_Settings::has_api_key() ) { return $this->reply( 'AI unavailable', 'An administrator must save a valid encrypted OpenAI API key first.', true ); }
		if ( $this->is_active( $identity ) ) { return $this->reply( 'AI active', 'Ask a question, use /newchat, or leave with /endchat.' ); }
		$session = $this->sessions->start_session( $identity, 'openai', Telepilot_AI_Settings::get_model() );
		return $session ? $this->reply( 'AI chat active', 'Site: ' . home_url() . "\nAsk in natural language. Slash commands and buttons still work normally. All changes require confirmation. Use /endchat to leave." ) : $this->reply( 'AI unavailable', 'Access could not be established.', true );
	}

	public function end_chat_mode( $identity ) {
		$this->sessions->end_session( $identity );
		return Telepilot_Telegram_Response_Builder::success_html( "<b>AI chat ended</b>\nConversation, continuations, and pending actions are cleared. Use /menu for commands." );
	}

	public function new_chat( $identity ) {
		if ( ! $this->is_active( $identity ) ) { return $this->inactive(); }
		$this->sessions->end_session( $identity );
		return $this->start_chat_mode( $identity );
	}

	public function render_context( $identity ) {
		$session = $this->sessions->refresh_session( $identity );
		if ( ! $session ) { return $this->inactive(); }
		$context = ( new Telepilot_AI_Conversation_Store() )->get_context( $session['session_id'] );
		return $this->reply( 'AI context', 'Site: ' . home_url() . "\nAccount: [" . $identity['wp_user']->ID . '] ' . $identity['wp_user']->display_name . "\nModel: " . Telepilot_AI_Settings::get_model() . "\nRecent references: " . count( $context['entities'] ) . "\nRequest: " . ( empty( $session['request_id'] ) ? 'Idle' : 'Processing' ) . "\nPending proposal: " . ( empty( $session['pending_action_id'] ) ? 'None' : 'Awaiting confirmation' ) );
	}

	public function cancel_pending_action( $identity ) {
		$session = $this->sessions->get_session( $identity );
		if ( ! $session ) { return $this->inactive(); }
		( new Telepilot_AI_Orchestrator() )->cancel_session_actions( $session['session_id'] );
		$session['pending_action_id'] = ''; $session['request_id'] = '';
		$this->sessions->update_session( $identity, $session );
		return $this->reply( 'Cancelled', 'Pending AI proposals and queued continuations were cancelled. AI mode remains active.' );
	}

	public function handle_text_message( $update, $identity ) {
		$session = $this->sessions->refresh_session( $identity );
		if ( ! $session ) { return $this->inactive(); }
		return ( new Telepilot_AI_Orchestrator() )->handle_message( trim( (string) ( $update['message']['text'] ?? '' ) ), $identity, $session );
	}

	public function handle_callback( $command, $identity ) {
		if ( ! $this->is_active( $identity ) ) { return $this->inactive(); }
		$action = $command['args'][0] ?? '';
		$id = $command['args'][1] ?? '';
		$orchestrator = new Telepilot_AI_Orchestrator();
		if ( in_array( $action, array( 'confirm', 'execute' ), true ) ) { return $orchestrator->confirm_pending_action( $id, $identity, 'execute' === $action ); }
		if ( 'cancel' === $action ) { return $orchestrator->cancel_pending_action( $id, $identity ); }
		return $this->reply( 'Invalid action', 'Use a current confirmation button.', true );
	}

	public function render_help( $identity ) {
		if ( ! $this->is_active( $identity ) ) { return $this->inactive(); }
		return $this->reply( 'AI commands', "/chat - Enter AI mode\n/context - Review session\n/newchat - Clear and start again\n/cancel - Cancel pending AI work\n/endchat - Leave AI mode\nExplicit commands always take priority." );
	}

	private function inactive() { return $this->reply( 'AI not active', 'The session expired, was revoked, or has not started. Use /chat if your account has access.', true ); }
	private function reply( $heading, $text, $error = false ) {
		$method = $error ? 'error_html' : 'success_html';
		return Telepilot_Telegram_Response_Builder::$method( Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . Telepilot_Telegram_Response_Builder::escape( $text ), array( 'command' => '/chat', 'reply_markup' => $error ? array( 'inline_keyboard' => array() ) : self::keyboard() ) );
	}
	public static function keyboard() {
		return Telepilot_Telegram_Response_Builder::keyboard( array(
			array( array( 'text' => Telepilot_Telegram_Response_Builder::label( 'context', 'Context' ), 'callback_data' => '/context' ), array( 'text' => Telepilot_Telegram_Response_Builder::label( 'refresh', 'New chat' ), 'callback_data' => '/newchat' ) ),
			array( array( 'text' => Telepilot_Telegram_Response_Builder::label( 'cancel', 'Cancel' ), 'callback_data' => '/cancel' ), array( 'text' => Telepilot_Telegram_Response_Builder::label( 'menu', 'End chat' ), 'callback_data' => '/endchat' ) ),
		) );
	}
}
