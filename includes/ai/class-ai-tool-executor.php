<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** AI adapts references and task grants; domain operations own authorization and execution. */
class Telepilot_AI_Tool_Executor {
	public function execute( $tool_name, $arguments, $identity, $session ) {
		return $this->dispatch( $tool_name, $arguments, $identity, $session, false );
	}

	public function execute_confirmed( $tool_name, $arguments, $identity, $session ) {
		if ( ! Telepilot_Operation_Context::current()->confirmed ) {
			return new WP_Error( 'telepilot_confirmation_required', 'A claimed confirmation is required.' );
		}
		return $this->dispatch( $tool_name, $arguments, $identity, $session, true );
	}

	public function resolve_arguments( $definition, $arguments, $session ) {
		$arguments = array_filter( $arguments, static function ( $value ) { return null !== $value; } );
		if ( empty( $arguments['reference_number'] ) ) { return $arguments; }
		$properties = (array) $definition['input_schema']['properties'];
		$key = isset( $properties['post_id'] ) ? 'post_id' : ( isset( $properties['page_id'] ) ? 'page_id' : ( isset( $properties['comment_id'] ) ? 'comment_id' : 'identifier' ) );
		$type = array( 'post_id' => 'post', 'page_id' => 'page', 'comment_id' => 'comment', 'identifier' => 'plugin' )[ $key ];
		$entity = ( new Telepilot_AI_Conversation_Store() )->resolve_reference( $session['session_id'] ?? '', $arguments['reference_number'], array( $type ) );
		if ( ! $entity ) { return new WP_Error( 'telepilot_reference_ambiguous', 'Please specify the item ID; that typed reference is unavailable.' ); }
		$value = 'identifier' === $key ? ( $entity['identifier'] ?? '' ) : ( $entity['id'] ?? 0 );
		if ( ! $value || ( isset( $arguments[ $key ] ) && (string) $arguments[ $key ] !== (string) $value ) ) { return new WP_Error( 'telepilot_reference_ambiguous', 'The ID and reference do not identify the same item.' ); }
		$arguments[ $key ] = $value;
		unset( $arguments['reference_number'] );
		return $arguments;
	}

	private function dispatch( $tool_name, $arguments, $identity, $session, $confirmed ) {
		$current = ( new Telepilot_AI_Session_Manager() )->get_session( $identity );
		if ( ! $current || ( $session['session_id'] ?? '' ) !== $current['session_id'] ) { return new WP_Error( 'telepilot_ai_session_invalid', 'This AI session has expired or changed.' ); }
		$permissions = new Telepilot_AI_Permission_Manager();
		if ( true !== $permissions->require_ai_mode_access( $identity ) ) { return new WP_Error( 'telepilot_ai_access_denied', 'AI access is no longer available.' ); }
		$definition = ( new Telepilot_Ability_Registry() )->get_ability_by_tool( $tool_name );
		if ( ! $definition || ! $permissions->is_tool_enabled( $definition['ability_name'] ) ) { return new WP_Error( 'telepilot_ai_tool_disabled', 'This task is not enabled.' ); }
		$arguments = $this->resolve_arguments( $definition, $arguments, $session );
		if ( is_wp_error( $arguments ) ) { return $arguments; }
		return Telepilot_Actor_Access::as_actor( $identity, static function () use ( $definition, $arguments, $confirmed ) {
			return ( new Telepilot_Ability_Executor() )->execute( $definition['ability_name'], $arguments, ! $confirmed );
		}, 'ai', $confirmed );
	}
}
