<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Ability_Executor {
	public static function permission( $name, $arguments = array() ) {
		$definition = ( new Telepilot_Ability_Registry() )->get_ability( $name );
		if ( ! $definition ) { return new WP_Error( 'telepilot_ability_missing', 'Unknown ability.' ); }
		$map = array(
			'telepilot/search-posts' => array( 'posts', 'filtered_page' ), 'telepilot/get-post' => array( 'posts', 'get_assigned_term_ids' ),
			'telepilot/list-pages' => array( 'pages', 'filtered_page' ), 'telepilot/get-page' => array( 'pages', 'get_page_details' ),
			'telepilot/list-comments' => array( 'comments', 'filtered_page' ), 'telepilot/list-users' => array( 'users', 'recent_page' ),
			'telepilot/list-plugins' => array( 'plugins', 'list_page' ), 'telepilot/list-updates' => array( 'plugins', 'updates_page' ),
			'telepilot/create-draft' => array( 'posts', 'create_draft' ), 'telepilot/update-draft' => array( 'posts', 'update_title' ),
			'telepilot/schedule-post' => array( 'posts', 'schedule' ), 'telepilot/publish-post' => array( 'posts', 'publish' ), 'telepilot/unpublish-post' => array( 'posts', 'unpublish' ),
			'telepilot/approve-comment' => array( 'comments', 'moderate_comment' ), 'telepilot/trash-comment' => array( 'comments', 'moderate_comment' ),
			'telepilot/activate-plugin' => array( 'plugins', 'activate' ), 'telepilot/deactivate-plugin' => array( 'plugins', 'deactivate' ), 'telepilot/update-plugin' => array( 'plugins', 'update' ),
			'telepilot/lookup-terms' => array( 'terms', 'list_terms' ),
		);
		if ( isset( $map[ $name ] ) ) {
			$domain = Telepilot_Domain_Abilities::definition( $map[ $name ][0], $map[ $name ][1] );
			return Telepilot_Domain_Abilities::check( $domain, $arguments, true );
		}
		$user = wp_get_current_user();
		$cap = 'manage_options';
		return Telepilot_Actor_Access::active_user( $user ) && user_can( $user, $cap ) ? true : new WP_Error( 'telepilot_capability_denied', 'You cannot access this ability.' );
	}

	public function execute( $name, $arguments = array(), $prepare = false ) {
		$definition = ( new Telepilot_Ability_Registry() )->get_ability( $name );
		if ( ! $definition ) { return new WP_Error( 'telepilot_ability_missing', 'Unknown ability.' ); }
		$validation = rest_validate_value_from_schema( $arguments, $definition['input_schema'], $name );
		if ( is_wp_error( $validation ) ) { return $validation; }
		$permission = self::permission( $name, $arguments );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$context = Telepilot_Operation_Context::current();
		$is_write = 'read' !== $definition['risk_level'];
		if ( $is_write && 'mcp' === $context->source ) { return new WP_Error( 'telepilot_mcp_read_only', 'MCP cannot execute writes.' ); }
		if ( $is_write && ! $prepare && ! $context->confirmed ) { return new WP_Error( 'telepilot_confirmation_required', 'Confirm this operation before execution.' ); }
		if ( 'telepilot/update-draft' === $name ) {
			$post = ! empty( $arguments['post_id'] ) ? get_post( $arguments['post_id'] ) : null;
			if ( ! $post || 'draft' !== $post->post_status ) { return new WP_Error( 'telepilot_target_changed', 'Only a current draft can be edited with this ability.' ); }
		}
		$handlers = new Telepilot_Core_Ability_Handlers();
		if ( 'telepilot/lookup-terms' === $name ) {
			$result = ( new Telepilot_Taxonomies_Service( new Telepilot_Confirmation_Service() ) )->list_terms( $arguments['taxonomy'] ?? 'category', $arguments['page'] ?? 1, 10, $arguments['query'] ?? '' );
			if ( is_wp_error( $result ) ) { return $result; }
			$result = array( 'ok' => true, 'summary' => 'Available taxonomy terms.', 'data' => array( 'items' => array_map( static function ( $term ) { return array( 'id' => $term->term_id, 'name' => $term->name, 'taxonomy' => $term->taxonomy ); }, $result['items'] ?? array() ) ) );
		} else {
			$method = $is_write && ! $prepare ? 'execute_confirmed' : 'execute';
			$result = $handlers->$method( $definition['tool_name'], $arguments, array( 'wp_user' => wp_get_current_user() ), array() );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		$result['status'] = ! empty( $result['ok'] ) ? 'success' : 'error';
		$result['operation_id'] = $context->operation_id;
		$result['references'] = $result['entities'] ?? array();
		$result['changes'] = $is_write && ! $prepare ? ( $result['data'] ?? array() ) : array();
		Telepilot_Audit_Log_Repository::log( array( 'wp_user_id' => $context->actor->ID, 'action_name' => $name, 'was_successful' => ! empty( $result['ok'] ), 'context' => array( 'operation_id' => $context->operation_id, 'source' => $context->source, 'prepared' => $prepare ), 'after_state' => $result['changes'] ) );
		return $result;
	}
}
