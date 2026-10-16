<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_AI_Confirmation_Manager {
	public function create_pending_action( $session, $identity, $tool_name, $ability, $arguments, $summary, $risk_level = 'low_write' ) {
		global $wpdb;
		$current = ( new Telepilot_AI_Session_Manager() )->get_session( $identity );
		$definition = ( new Telepilot_Ability_Registry() )->get_ability_by_tool( $tool_name );
		if ( ! $current || $current['session_id'] !== $session['session_id'] || ! $definition || $definition['ability_name'] !== $ability || 'read' === $definition['risk_level'] ) { return new WP_Error( 'telepilot_proposal_invalid', 'A current authorized write proposal is required.' ); }
		$validation = rest_validate_value_from_schema( $arguments, $definition['input_schema'], $ability );
		if ( is_wp_error( $validation ) ) { return $validation; }
		$access = Telepilot_Ability_Executor::permission( $ability, $arguments );
		if ( is_wp_error( $access ) || ! ( new Telepilot_AI_Permission_Manager() )->is_tool_enabled( $ability ) ) { return new WP_Error( 'telepilot_proposal_denied', 'This task is not authorized.' ); }
		$target = $this->snapshot( $ability, $arguments );
		if ( is_wp_error( $target ) ) { return $target; }
		$preview = $this->preview( $ability, $arguments, $target );
		if ( mb_strlen( $preview ) > 2800 ) { return new WP_Error( 'telepilot_preview_too_long', 'The exact changes are too long to confirm safely in chat. Use the posts browser editor instead.' ); }
		$lock = 'telepilot-proposal-' . md5( get_current_blog_id() . ':' . $identity['wp_user']->ID );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proposal quotas and single-use confirmation state must reflect current security records.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'telepilot_proposal_busy', 'Another proposal is being prepared. Try again.' ); }
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proposal quotas and single-use confirmation state must reflect current security records.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE actor_id=%d AND purpose IN (%s,%s) AND state=%s AND expires_at>%s', Telepilot_Security_Records::table_name(), $identity['wp_user']->ID, 'ai_proposal', 'ai_confirm', 'ready', gmdate( 'Y-m-d H:i:s' ) ) );
			if ( $count >= min( 3, (int) Telepilot_AI_Settings::get_settings()['ai_max_pending_confirmations'] ) ) { return new WP_Error( 'telepilot_proposal_limit', 'Cancel or finish an existing proposal first. At most three proposals may be pending.' ); }
			$payload = array( 'session_id' => $session['session_id'], 'chat_id' => (string) $identity['chat_id'], 'telegram_user_id' => (string) $identity['telegram_user_id'], 'wordpress_user_id' => $identity['wp_user']->ID, 'site_id' => get_current_blog_id(), 'tool_name' => $tool_name, 'ability' => $ability, 'arguments' => $arguments, 'target' => $target, 'summary' => $preview, 'expires_at' => time() + 300, 'high_risk' => in_array( $ability, array( 'telepilot/publish-post', 'telepilot/unpublish-post', 'telepilot/activate-plugin', 'telepilot/deactivate-plugin', 'telepilot/update-plugin' ), true ) );
			$id = Telepilot_Security_Records::issue( 'ai_proposal', $payload, $identity['wp_user']->ID, 300 );
			if ( ! $id ) { return new WP_Error( 'telepilot_proposal_save_failed', 'The proposal could not be saved. No action ran.' ); }
			$payload['action_id'] = $id;
			return $payload;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proposal quotas and single-use confirmation state must reflect current security records.
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public function get_pending_action( $id, $identity, $final = false ) {
		$payload = Telepilot_Security_Records::read( $id, $final ? 'ai_confirm' : 'ai_proposal' );
		$session = ( new Telepilot_AI_Session_Manager() )->get_session( $identity );
		if ( ! $payload || ! $session || $payload['session_id'] !== $session['session_id'] || (int) $payload['wordpress_user_id'] !== (int) $identity['wp_user']->ID || $payload['chat_id'] !== (string) $identity['chat_id'] || $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['site_id'] !== get_current_blog_id() ) { return null; }
		return $payload;
	}

	public function confirm( $id, $identity, $final = false ) {
		global $wpdb;
		$payload = $this->get_pending_action( $id, $identity, $final );
		if ( ! $payload ) { return new WP_Error( 'telepilot_proposal_expired', 'This proposal is expired, cancelled, used, or does not belong to this session.' ); }
		$access = Telepilot_Ability_Executor::permission( $payload['ability'], $payload['arguments'] );
		if ( is_wp_error( $access ) || ! ( new Telepilot_AI_Permission_Manager() )->is_tool_enabled( $payload['ability'] ) ) { return new WP_Error( 'telepilot_proposal_denied', 'Permissions or configuration changed. Request a fresh proposal.' ); }
		$target = $this->snapshot( $payload['ability'], $payload['arguments'] );
		if ( is_wp_error( $target ) || $target['revision'] !== $payload['target']['revision'] ) { return new WP_Error( 'telepilot_target_changed', 'The target changed since this proposal. Nothing ran; cancel it and request a fresh proposal.' ); }
		// Ownership and permissions are verified before the atomic, non-reversible claim.
		if ( ! Telepilot_Security_Records::claim( $id, $final ? 'ai_confirm' : 'ai_proposal' ) ) { return new WP_Error( 'telepilot_proposal_used', 'This proposal was already used.' ); }
		if ( $payload['high_risk'] && ! $final ) {
			$new_id = Telepilot_Security_Records::issue( 'ai_confirm', $payload, $identity['wp_user']->ID, max( 1, $payload['expires_at'] - time() ) );
			if ( ! $new_id ) { return new WP_Error( 'telepilot_proposal_save_failed', 'Final confirmation could not be saved. No action ran.' ); }
			$payload['action_id'] = $new_id; $payload['warning'] = true;
			return $payload;
		}
		Telepilot_DB_Transaction::begin();
		try {
			$current = $this->snapshot( $payload['ability'], $payload['arguments'], true );
			if ( is_wp_error( $current ) || $current['revision'] !== $payload['target']['revision'] ) {
				Telepilot_DB_Transaction::rollback();
				return new WP_Error( 'telepilot_target_changed', 'The target changed while confirmation was being claimed. Request a fresh proposal.' );
			}
			$session = ( new Telepilot_AI_Session_Manager() )->get_session( $identity );
			if ( ! $session || $session['session_id'] !== $payload['session_id'] ) { Telepilot_DB_Transaction::rollback(); return new WP_Error( 'telepilot_proposal_revoked', 'Session access was revoked before execution.' ); }
			$result = Telepilot_Actor_Access::as_actor( $identity, function () use ( $payload, $identity, $session ) {
				Telepilot_Operation_Context::current()->expected_target = $payload['target'];
				return ( new Telepilot_AI_Tool_Executor() )->execute_confirmed( $payload['tool_name'], $payload['arguments'], $identity, $session );
			}, 'ai', true );
			// Plugins may run non-transactional hooks; never promise rollback of their effects.
			Telepilot_DB_Transaction::commit();
			if ( is_wp_error( $result ) ) {
				Telepilot_Audit_Log_Repository::log( array( 'wp_user_id' => $identity['wp_user']->ID, 'action_name' => 'ai_confirmed_write_failed', 'was_successful' => 0, 'failure_reason' => $result->get_error_code() ) );
				return $result;
			}
			$result['changes'] = array( 'before' => $payload['target'], 'after' => $this->snapshot( $payload['ability'], $payload['arguments'], false, $result ) );
			Telepilot_Audit_Log_Repository::log( array( 'wp_user_id' => $identity['wp_user']->ID, 'action_name' => 'ai_confirmed_write_completed', 'was_successful' => 1, 'before_state' => $result['changes']['before'], 'after_state' => $result['changes']['after'], 'context' => array( 'operation_id' => Telepilot_Operation_Context::current()->operation_id, 'ability' => $payload['ability'] ) ) );
			return $result;
		} catch ( Throwable $error ) {
			Telepilot_DB_Transaction::rollback();
			throw $error; // The durable queue quarantines uncertain execution, never repeats it.
		}
	}

	public function cancel_pending_action( $id, $identity ) {
		foreach ( array( false, true ) as $final ) {
			$payload = $this->get_pending_action( $id, $identity, $final );
			if ( $payload && Telepilot_Security_Records::claim( $id, $final ? 'ai_confirm' : 'ai_proposal' ) ) { return $payload; }
		}
		return null;
	}

	public static function cancel_session_actions( $session_id, $actor_id = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revocation must consume every current proposal for this session immediately.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT token_hash,payload FROM %i WHERE actor_id=%d AND purpose IN (%s,%s) AND state=%s', Telepilot_Security_Records::table_name(), $actor_id ?: get_current_user_id(), 'ai_proposal', 'ai_confirm', 'ready' ), ARRAY_A );
		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revocation must consume every current proposal for this session immediately.
			if ( ( $payload['session_id'] ?? '' ) === $session_id ) { $wpdb->update( Telepilot_Security_Records::table_name(), array( 'state' => 'consumed', 'payload' => '{}' ), array( 'token_hash' => $row['token_hash'], 'state' => 'ready' ) ); }
		}
	}

	public function build_confirmation_keyboard( $id, $final = false, $target_id = '' ) {
		$label = ( $final ? 'Execute' : 'Confirm' ) . ( '' !== (string) $target_id ? ' [' . $target_id . ']' : '' );
		return Telepilot_Telegram_Response_Builder::keyboard( array( array(
			array( 'text' => Telepilot_Telegram_Response_Builder::button_label( 'confirm', $label ), 'callback_data' => 'tp:ai:' . ( $final ? 'execute:' : 'confirm:' ) . $id ),
			array( 'text' => Telepilot_Telegram_Response_Builder::button_label( 'cancel', 'Cancel' ), 'callback_data' => 'tp:ai:cancel:' . $id ),
		) ) );
	}

	public function snapshot( $ability, $args, $lock = false, $result = array() ) {
		global $wpdb;
		$post_id = (int) ( $args['post_id'] ?? $result['data']['post_id'] ?? 0 );
		if ( $post_id ) {
			if ( $lock ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmation revision checks and FOR UPDATE locks require fresh rows and taxonomy state.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE ID=%d FOR UPDATE", $wpdb->posts, $post_id ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmation revision checks and FOR UPDATE locks require fresh rows and taxonomy state.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID=%d', $wpdb->posts, $post_id ), ARRAY_A );
			}
			if ( ! $row || 'post' !== $row['post_type'] ) { return new WP_Error( 'telepilot_target_missing', 'The post no longer exists.' ); }
			clean_post_cache( $post_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmation revision checks and FOR UPDATE locks require fresh rows and taxonomy state.
			$row['_terms'] = $wpdb->get_col( $wpdb->prepare( 'SELECT term_taxonomy_id FROM %i WHERE object_id=%d ORDER BY term_taxonomy_id', $wpdb->term_relationships, $post_id ) );
			return array( 'id' => $post_id, 'title' => $row['post_title'], 'status' => $row['post_status'], 'excerpt' => $row['post_excerpt'], 'revision' => hash( 'sha256', wp_json_encode( $row ) ) );
		}
		if ( ! empty( $args['comment_id'] ) ) {
			if ( $lock ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmation revision checks and FOR UPDATE locks require fresh rows and taxonomy state.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE comment_ID=%d FOR UPDATE", $wpdb->comments, $args['comment_id'] ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmation revision checks and FOR UPDATE locks require fresh rows and taxonomy state.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE comment_ID=%d', $wpdb->comments, $args['comment_id'] ), ARRAY_A );
			}
			if ( ! $row ) { return new WP_Error( 'telepilot_target_missing', 'The comment no longer exists.' ); }
			clean_comment_cache( (int) $row['comment_ID'] );
			return array( 'id' => (int) $row['comment_ID'], 'title' => $row['comment_author'], 'status' => $row['comment_approved'], 'revision' => hash( 'sha256', wp_json_encode( $row ) ) );
		}
		if ( ! empty( $args['identifier'] ) ) {
			wp_clean_plugins_cache( false );
			$plugin = ( new Telepilot_Plugins_Service( new Telepilot_Confirmation_Service() ) )->get_plugin_details( $args['identifier'] );
			if ( is_wp_error( $plugin ) ) { return $plugin; }
			$plugin['revision'] = hash( 'sha256', wp_json_encode( $plugin ) );
			$plugin['id'] = $plugin['identifier']; $plugin['title'] = $plugin['name'];
			return $plugin;
		}
		if ( 'telepilot/create-draft' !== $ability ) { return new WP_Error( 'telepilot_target_missing', 'Specify the exact target ID.' ); }
		$categories = array();
		foreach ( $args['categories'] ?? array() as $id ) {
			$term = get_term( $id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) { return new WP_Error( 'telepilot_category_missing', 'A selected category no longer exists.' ); }
			$categories[] = '[' . $term->term_id . '] ' . $term->name;
		}
		return array( 'id' => '', 'title' => $args['title'] ?? '', 'categories' => $categories, 'revision' => hash( 'sha256', wp_json_encode( $categories ) ) );
	}

	private function preview( $ability, $args, $target ) {
		$lines = array( 'Site: ' . home_url(), 'Action: ' . str_replace( 'telepilot/', '', $ability ), 'Target: ' . ( '' === (string) $target['id'] ? 'New post' : '[' . $target['id'] . ']' ) . ' ' . $target['title'] );
		if ( isset( $target['status'] ) ) { $lines[] = 'Current status: ' . $target['status']; }
		foreach ( array( 'title', 'excerpt', 'scheduled_at' ) as $key ) { if ( array_key_exists( $key, $args ) ) { $lines[] = ucfirst( $key ) . ': ' . ( '' === $args[ $key ] ? '(empty)' : $args[ $key ] ); } }
		if ( isset( $target['categories'] ) ) { $lines[] = 'Categories: ' . ( $target['categories'] ? implode( ', ', $target['categories'] ) : 'WordPress default category' ); }
		if ( isset( $args['scheduled_at'] ) ) { $lines[] = 'Timezone: ' . wp_timezone_string(); }
		if ( isset( $target['version'] ) ) { $lines[] = 'Installed: ' . $target['version'] . '; available update: ' . ( $target['update_version'] ?: 'none' ); }
		$lines[] = 'Expires five minutes after proposal creation. No change until confirmation.';
		return implode( "\n", $lines );
	}
}
