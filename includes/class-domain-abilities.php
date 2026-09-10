<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One permission/schema/execution boundary for commands, AI and MCP domain work. */
class Telepilot_Domain_Abilities {
	public static function domains() {
		return array(
			'posts' => array( 'class' => 'Telepilot_Posts_Service', 'cap' => 'edit_posts', 'reads' => 'latest,latest_page,drafts,drafts_page,trashed_page,search,search_page,stats,get_assigned_term_ids,filtered_page', 'writes' => 'create_draft,update_draft,update_title,update_excerpt,assign_terms,schedule,publish,unpublish,trash,restore,delete' ),
			'pages' => array( 'class' => 'Telepilot_Pages_Service', 'cap' => 'edit_pages', 'reads' => 'latest,latest_page,search_page,drafts_page,trashed_page,get_page_details,filtered_page', 'writes' => 'create_page,update_title,update_slug,set_status,publish,draft,trash,restore,delete' ),
			'comments' => array( 'class' => 'Telepilot_Comments_Service', 'cap' => 'moderate_comments', 'reads' => 'pending_page,approved_page,spam_page,trash_page,search_page,get_comment_details,filtered_page', 'writes' => 'moderate_comment,reply_to_comment' ),
			'users' => array( 'class' => 'Telepilot_Users_Service', 'cap' => 'list_users', 'reads' => 'recent,recent_page,search_page,get_user_details', 'writes' => 'create_user,disable_user,enable_user,assign_role,generate_reset_link,send_reset_email,update_email,update_display_name,send_welcome_email,delete_user' ),
			'plugins' => array( 'class' => 'Telepilot_Plugins_Service', 'cap' => 'activate_plugins', 'reads' => 'list_page,search_page,updates_page,get_plugin_details', 'writes' => 'refresh_updates,activate,deactivate,delete,update' ),
			'terms' => array( 'class' => 'Telepilot_Taxonomies_Service', 'cap' => 'manage_categories', 'reads' => 'list_terms,get_term_details,get_term_usage_details', 'writes' => 'create_term,rename_term,update_slug,update_description,update_parent,delete_term,reassign_and_delete_term' ),
			'media' => array( 'class' => 'Telepilot_Media_Service', 'cap' => 'upload_files', 'reads' => 'recent,recent_page,search,search_page,get_item_details', 'writes' => '' ),
		);
	}

	public static function definition( $domain, $method ) {
		$domain_definition = self::domains()[ $domain ] ?? null;
		if ( ! $domain_definition || ! in_array( $method, explode( ',', $domain_definition['reads'] . ',' . $domain_definition['writes'] ), true ) || ! method_exists( $domain_definition['class'], $method ) ) { return null; }
		$write = in_array( $method, explode( ',', $domain_definition['writes'] ), true );
		$properties = array(); $required = array();
		foreach ( ( new ReflectionMethod( $domain_definition['class'], $method ) )->getParameters() as $parameter ) {
			$name = $parameter->getName();
			if ( preg_match( '/_ids$/', $name ) ) { $schema = array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'maxItems' => 100 ); }
			elseif ( preg_match( '/_id$/', $name ) || in_array( $name, array( 'page', 'limit' ), true ) ) { $schema = array( 'type' => 'integer', 'minimum' => in_array( $name, array( 'parent_id', 'reassign_id' ), true ) ? 0 : 1 ); }
			else { $schema = array( 'type' => 'string', 'maxLength' => in_array( $name, array( 'excerpt', 'content', 'description' ), true ) ? 10000 : 1000 ); }
			if ( 'limit' === $name ) { $schema['maximum'] = 100; }
			if ( 'wp_user' === $name ) { $schema = array( 'type' => 'integer', 'minimum' => 1 ); }
			if ( 'taxonomy' === $name ) { $schema['enum'] = array( 'category', 'post_tag' ); }
			if ( 'email' === $name ) { $schema['format'] = 'email'; }
			if ( $parameter->isDefaultValueAvailable() && null === $parameter->getDefaultValue() ) { $schema['type'] = array( $schema['type'], 'null' ); }
			$properties[ $name ] = $schema;
			if ( ! $parameter->isOptional() ) { $required[] = $name; }
		}
		return array( 'name' => 'telepilot/' . $domain . '-' . str_replace( '_', '-', $method ), 'domain' => $domain, 'method' => $method, 'capability' => $domain_definition['cap'], 'risk_level' => $write ? 'write' : 'read', 'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ) );
	}

	public static function check( $definition, $arguments, $preflight = false ) {
		$context = Telepilot_Operation_Context::current();
		$user = wp_get_current_user();
		if ( ! Telepilot_Actor_Access::active_user( $user ) || $context->site_id !== get_current_blog_id() || $context->actor->ID !== $user->ID ) { return new WP_Error( 'telepilot_actor_denied', 'An active authenticated site actor is required.' ); }
		if ( $context->identity && true !== ( $access = Telepilot_Actor_Access::check( $context->identity ) ) ) { return $access; }
		$domain = $definition['domain']; $method = $definition['method']; $write = 'read' !== $definition['risk_level'];
		$cap = $definition['capability'];
		if ( 'terms' === $domain && ! $write ) {
			$taxonomy = get_taxonomy( $arguments['taxonomy'] ?? 'category' );
			if ( $taxonomy ) { $cap = $taxonomy->cap->assign_terms; }
		}
		if ( 'users' === $domain && $write ) { $cap = 'create_user' === $method ? 'create_users' : ( 'assign_role' === $method ? 'promote_users' : ( 'delete_user' === $method ? 'delete_users' : 'edit_users' ) ); }
		if ( 'plugins' === $domain ) {
			$cap = in_array( $method, array( 'update', 'refresh_updates' ), true ) ? 'update_plugins' : ( 'delete' === $method ? 'delete_plugins' : 'activate_plugins' );
			if ( ! $write && ( user_can( $user, 'update_plugins' ) || user_can( $user, 'delete_plugins' ) ) ) { $cap = 'read'; }
		}
		if ( ! user_can( $user, $cap ) ) { return new WP_Error( 'telepilot_capability_denied', 'Your WordPress account cannot perform this operation.' ); }
		if ( 'media' === $domain && ! empty( $arguments['attachment_id'] ) && ! user_can( $user, 'read_post', $arguments['attachment_id'] ) ) { return new WP_Error( 'telepilot_target_denied', 'You cannot access this attachment.' ); }
		if ( ! $preflight && 'ai' === $context->source && $write && ! $context->confirmed ) { return new WP_Error( 'telepilot_confirmation_required', 'A confirmed proposal is required.' ); }
		if ( 'mcp' === $context->source && $write ) { return new WP_Error( 'telepilot_mcp_read_only', 'MCP is read-only.' ); }
		if ( in_array( $domain, array( 'posts', 'pages' ), true ) ) {
			$id = $arguments['post_id'] ?? $arguments['page_id'] ?? 0;
			if ( 'create_draft' === $method ) {
				foreach ( $arguments['category_ids'] ?? $arguments['categories'] ?? array() as $term_id ) {
					if ( ! term_exists( $term_id, 'category' ) || ! user_can( $user, get_taxonomy( 'category' )->cap->assign_terms ) ) { return new WP_Error( 'telepilot_category_invalid', 'A selected category is unavailable or cannot be assigned.' ); }
				}
			}
			if ( 'schedule' === $method && isset( $arguments['scheduled_at'] ) ) {
				try { $date = $arguments['scheduled_at'] instanceof DateTimeInterface ? $arguments['scheduled_at'] : new DateTimeImmutable( $arguments['scheduled_at'], wp_timezone() ); }
				catch ( Exception $error ) { return new WP_Error( 'telepilot_schedule_invalid', 'Specify an unambiguous future date and time.' ); }
				if ( $date->getTimestamp() <= time() + 60 ) { return new WP_Error( 'telepilot_schedule_past', 'Scheduling requires a time more than one minute in the future.' ); }
			}
			if ( $id && ! user_can( $user, in_array( $method, array( 'trash', 'delete' ), true ) ? 'delete_post' : 'edit_post', $id ) ) { return new WP_Error( 'telepilot_target_denied', 'This content is not accessible to your account.' ); }
			if ( in_array( $method, array( 'publish', 'unpublish', 'schedule', 'draft' ), true ) || ( 'set_status' === $method && 'draft' !== ( $arguments['status'] ?? '' ) ) ) {
				if ( ! user_can( $user, 'pages' === $domain ? 'publish_pages' : 'publish_posts' ) ) { return new WP_Error( 'telepilot_publish_denied', 'Publishing permission is required.' ); }
			}
		}
		if ( 'users' === $domain && $write && ! empty( $arguments['user_id'] ) && ! user_can( $user, 'delete_user' === $method ? 'delete_user' : ( 'assign_role' === $method ? 'promote_user' : 'edit_user' ), $arguments['user_id'] ) ) { return new WP_Error( 'telepilot_target_denied', 'You cannot manage this user.' ); }
		if ( 'users' === $domain && isset( $arguments['role'] ) ) {
			$role = get_role( $arguments['role'] );
			if ( ! $role ) { return new WP_Error( 'telepilot_role_invalid', 'Unknown WordPress role.' ); }
			foreach ( $role->capabilities as $capability => $granted ) {
				if ( $granted && ! user_can( $user, $capability ) ) { return new WP_Error( 'telepilot_role_escalation', 'You cannot grant capabilities you do not possess.' ); }
			}
		}
		if ( 'comments' === $domain && ! empty( $arguments['comment_id'] ) && ! user_can( $user, 'edit_comment', $arguments['comment_id'] ) ) { return new WP_Error( 'telepilot_target_denied', 'You cannot access this comment.' ); }
		if ( isset( $arguments['wp_user'] ) && ( ! $arguments['wp_user'] instanceof WP_User || $arguments['wp_user']->ID !== $user->ID ) ) { return new WP_Error( 'telepilot_actor_mismatch', 'Caller-supplied actor identity is not trusted.' ); }
		return true;
	}

	public static function run( $domain, $method, $arguments, callable $execute ) {
		$definition = self::definition( $domain, $method );
		if ( ! $definition ) { return new WP_Error( 'telepilot_ability_missing', 'Unregistered operation.' ); }
		$normalized = $arguments;
		foreach ( $normalized as $key => $value ) {
			if ( $value instanceof DateTimeInterface ) { $normalized[ $key ] = $value->format( DATE_ATOM ); }
			if ( $value instanceof WP_User ) { $normalized[ $key ] = $value->ID; }
		}
		$valid = rest_validate_value_from_schema( $normalized, $definition['input_schema'], $definition['name'] );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$access = self::check( $definition, $arguments );
		if ( is_wp_error( $access ) ) { return $access; }
		$context = Telepilot_Operation_Context::current();
		$result = $execute();
		$ok = ! is_wp_error( $result );
		if ( $ok && 'read' !== $definition['risk_level'] ) { Telepilot_Domain_Cache::invalidate(); }
		Telepilot_Audit_Log_Repository::log( array( 'wp_user_id' => $context->actor->ID, 'action_name' => $definition['name'], 'resource_type' => $domain, 'resource_id' => (string) ( $arguments['post_id'] ?? $arguments['page_id'] ?? $arguments['user_id'] ?? $arguments['comment_id'] ?? $arguments['term_id'] ?? '' ), 'was_successful' => $ok, 'context' => array( 'operation_id' => $context->operation_id, 'source' => $context->source, 'risk' => $definition['risk_level'] ), 'failure_reason' => $ok ? null : $result->get_error_code() ) );
		return $result;
	}
}
