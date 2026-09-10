<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Database-backed one-time credentials. Only token hashes are stored. */
class Telepilot_Security_Records {
	public static function table_name() { global $wpdb; return $wpdb->prefix . 'telepilot_security_records'; }
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			token_hash VARCHAR(64) NOT NULL,
			purpose VARCHAR(32) NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'ready',
			payload LONGTEXT NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY actor_purpose (actor_id, purpose),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB $charset;" );
	}

	public static function issue( $purpose, $payload, $actor_id, $ttl ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 12 ) );
		$payload['_security_binding'] = array( 'actor_id' => (int) $actor_id, 'site_id' => get_current_blog_id(), 'generation' => Telepilot_Actor_Access::generation( $actor_id ) );
		$created = $wpdb->insert( self::table_name(), array( 'token_hash' => hash( 'sha256', $token ), 'purpose' => $purpose, 'actor_id' => $actor_id, 'payload' => wp_json_encode( $payload ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ) ) );
		return $created ? $token : '';
	}

	public static function read( $token, $purpose ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', (string) $token ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE token_hash=%s AND purpose=%s AND state=%s AND expires_at>%s', hash( 'sha256', $token ), $purpose, 'ready', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		if ( ! $row ) { return null; }
		$payload = json_decode( $row['payload'], true );
		$binding = $payload['_security_binding'] ?? array();
		$user = get_user_by( 'id', $row['actor_id'] );
		if ( ! Telepilot_Actor_Access::active_user( $user ) || get_current_blog_id() !== (int) ( $binding['site_id'] ?? 0 ) || ! hash_equals( Telepilot_Actor_Access::generation( $row['actor_id'] ), (string) ( $binding['generation'] ?? '' ) ) ) { return null; }
		return $payload;
	}

	public static function claim( $token, $purpose ) {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET state=%s WHERE token_hash=%s AND purpose=%s AND state=%s AND expires_at>%s', 'consumed', hash( 'sha256', $token ), $purpose, 'ready', gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public static function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE expires_at<=%s OR state=%s', gmdate( 'Y-m-d H:i:s' ), 'consumed' ) );
	}

	public static function erase_actor( $actor_id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'actor_id' => $actor_id ) );
	}
}
