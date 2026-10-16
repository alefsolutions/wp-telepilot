<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_AI_Session_Manager {
	const SESSION_PREFIX = '_telepilot_ai_session_';
	private function key() { return self::SESSION_PREFIX . get_current_blog_id(); }
	private function configuration_hash() {
		$settings = array_intersect_key( Telepilot_AI_Settings::get_settings(), Telepilot_AI_Settings::defaults() );
		$settings['_site_url'] = home_url();
		$settings['_site_timezone'] = wp_timezone_string();
		return hash( 'sha256', wp_json_encode( $settings ) );
	}

	public function get_session( $identity ) {
		$id = (int) ( $identity['wp_user']->ID ?? 0 );
		if ( ! $id ) { return null; }
		$session = get_user_meta( $id, $this->key(), true );
		if ( ! is_array( $session ) ) { return null; }
		if ( ! $this->valid( $session, $identity ) ) { $this->end_session( $identity ); return null; }
		return $session;
	}

	public function valid( $session, $identity ) {
		return true === ( new Telepilot_AI_Permission_Manager() )->check( $identity )
			&& hash_equals( $this->configuration_hash(), (string) ( $session['configuration_hash'] ?? '' ) )
			&& ! empty( $session['ai_mode_active'] ) && time() < (int) ( $session['expires_at'] ?? 0 )
			&& (int) ( $session['wordpress_user_id'] ?? 0 ) === (int) $identity['wp_user']->ID
			&& (int) ( $session['site_id'] ?? 0 ) === get_current_blog_id()
			&& (string) ( $session['telegram_chat_id'] ?? '' ) === (string) $identity['chat_id']
			&& (string) ( $session['telegram_user_id'] ?? '' ) === (string) $identity['telegram_user_id']
			&& hash_equals( Telepilot_Actor_Access::generation( $identity['wp_user']->ID ), (string) ( $session['generation'] ?? '' ) );
	}

	public function is_active( $identity ) { return null !== $this->get_session( $identity ); }

	public function start_session( $identity, $provider, $model ) {
		if ( true !== ( new Telepilot_AI_Permission_Manager() )->check( $identity ) ) { return null; }
		$this->end_session( $identity );
		$session = array(
			'configuration_hash' => $this->configuration_hash(),
			'session_id' => wp_generate_uuid4(), 'wordpress_user_id' => (int) $identity['wp_user']->ID,
			'telegram_chat_id' => (string) $identity['chat_id'], 'telegram_user_id' => (string) $identity['telegram_user_id'],
			'site_id' => get_current_blog_id(), 'generation' => Telepilot_Actor_Access::generation( $identity['wp_user']->ID ),
			'ai_mode_active' => true, 'provider' => $provider, 'model' => $model,
			'started_at' => time(), 'last_activity_at' => time(), 'expires_at' => time() + Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS,
			'pending_action_id' => '', 'request_id' => '',
		);
		update_user_meta( $identity['wp_user']->ID, $this->key(), $session );
		return $session;
	}

	public function update_session( $identity, $session ) {
		$current = $this->get_session( $identity );
		if ( ! $current || ! $this->valid( $session, $identity ) || ! hash_equals( $current['session_id'], $session['session_id'] ?? '' ) ) { return null; }
		update_user_meta( $identity['wp_user']->ID, $this->key(), $session );
		return $session;
	}

	public function refresh_session( $identity, $session = null ) {
		$current = $this->get_session( $identity );
		if ( ! $current || ( $session && $session['session_id'] !== $current['session_id'] ) ) { return null; }
		$current['last_activity_at'] = time();
		$current['expires_at'] = time() + Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS;
		return $this->update_session( $identity, $current );
	}

	public function end_session( $identity ) { self::erase_actor( (int) ( $identity['wp_user']->ID ?? 0 ) ); }

	public static function erase_actor( $id ) {
		if ( ! $id ) { return; }
		$key = self::SESSION_PREFIX . get_current_blog_id();
		$session = get_user_meta( $id, $key, true );
		if ( ! empty( $session['session_id'] ) ) {
			( new Telepilot_AI_Conversation_Store() )->clear( $session['session_id'] );
			do_action( 'telepilot_ai_session_ended', $session['session_id'], $id );
		}
		delete_user_meta( $id, $key );
	}

	public static function purge( $all = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session maintenance reads current per-site user metadata before expiring stale sessions.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id,meta_value FROM %i WHERE meta_key=%s', $wpdb->usermeta, self::SESSION_PREFIX . get_current_blog_id() ), ARRAY_A );
		foreach ( $rows as $row ) {
			$session = maybe_unserialize( $row['meta_value'] );
			if ( $all || ! is_array( $session ) || time() >= (int) ( $session['expires_at'] ?? 0 ) ) { self::erase_actor( $row['user_id'] ); }
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session maintenance reads current per-site user metadata before expiring stale sessions.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'telepilot_ai_' ) . '%' ) );
		foreach ( $keys as $key ) { if ( preg_match( '/^telepilot_ai_(requests|tokens)_(\d{8})$/', $key, $match ) && $match[2] < gmdate( 'Ymd', time() - 7 * DAY_IN_SECONDS ) ) { delete_option( $key ); } }
	}
}
