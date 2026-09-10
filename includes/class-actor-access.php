<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared authorization boundary. Re-read identity and policy on every invocation. */
class Telepilot_Actor_Access {
	const GENERATION_META = '_telepilot_link_generation';

	public static function generation( $user_id ) {
		return (string) get_user_meta( $user_id, self::GENERATION_META, true ) . ':' . (string) get_option( 'telepilot_access_epoch', '' );
	}

	public static function revoke( $user_id ) {
		update_user_meta( $user_id, self::GENERATION_META, wp_generate_uuid4() );
		do_action( 'telepilot_actor_revoked', (int) $user_id );
	}

	public static function changed_user_meta( $meta_id, $user_id, $key ) {
		global $wpdb;
		if ( in_array( $key, array( '_telepilot_disabled', Telepilot_User_Linking_Service::META_TELEGRAM_ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, $wpdb->prefix . 'capabilities' ), true ) ) {
			self::revoke( $user_id );
		}
	}

	public static function chat_allowed( $chat_id ) {
		$settings = get_option( 'telepilot_settings', array() );
		$allowed = preg_split( '/[\s,]+/', trim( (string) ( $settings['allowed_chat_ids'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return '' !== (string) $chat_id && ( ! $allowed || in_array( (string) $chat_id, $allowed, true ) );
	}

	public static function active_user( $user ) {
		return $user instanceof WP_User && $user->exists() && is_user_member_of_blog( $user->ID ) && ! get_user_meta( $user->ID, '_telepilot_disabled', true );
	}

	public static function check( $identity, $capability = '', $object_id = null ) {
		$id = isset( $identity['wp_user']->ID ) ? (int) $identity['wp_user']->ID : 0;
		$user = $id ? get_user_by( 'id', $id ) : false;
		if ( ! self::active_user( $user ) ) {
			return new WP_Error( 'telepilot_actor_denied', __( 'Your account is unlinked, suspended, or no longer authorized on this site.', 'wp-telepilot' ) );
		}
		$telegram_id = (string) ( $identity['telegram_user_id'] ?? '' );
		$chat_id = (string) ( $identity['chat_id'] ?? '' );
		if ( 'private' !== ( $identity['chat_type'] ?? '' ) || '' === $telegram_id || $telegram_id !== $chat_id || ! self::chat_allowed( $chat_id ) || $telegram_id !== (string) get_user_meta( $id, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true ) || $chat_id !== (string) get_user_meta( $id, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ) ) {
			return new WP_Error( 'telepilot_chat_denied', __( 'Use your authorized, linked private Telegram chat.', 'wp-telepilot' ) );
		}
		if ( isset( $identity['linkage_generation'] ) && ! hash_equals( self::generation( $id ), (string) $identity['linkage_generation'] ) ) {
			return new WP_Error( 'telepilot_actor_revoked', __( 'This request belongs to a revoked link. Please start again.', 'wp-telepilot' ) );
		}
		if ( '' !== $capability && ! ( null === $object_id ? user_can( $user, $capability ) : user_can( $user, $capability, $object_id ) ) ) {
			return new WP_Error( 'telepilot_capability_denied', __( 'Your WordPress account cannot perform this action.', 'wp-telepilot' ) );
		}
		return true;
	}

	public static function as_actor( $identity, callable $callback ) {
		$check = self::check( $identity );
		if ( is_wp_error( $check ) ) {
			return Telepilot_Telegram_Response_Builder::error( $check->get_error_message(), array( 'code' => $check->get_error_code() ) );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $identity['wp_user']->ID );
		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	public static function bind_payload( $payload ) {
		$user_id = get_current_user_id();
		$payload['_actor_binding'] = array( 'user_id' => $user_id, 'site_id' => get_current_blog_id(), 'generation' => self::generation( $user_id ) );
		return $payload;
	}

	public static function valid_payload( $payload ) {
		$binding = $payload['_actor_binding'] ?? array();
		return ! empty( $binding['user_id'] ) && (int) $binding['user_id'] === get_current_user_id() && (int) ( $binding['site_id'] ?? 0 ) === get_current_blog_id() && hash_equals( self::generation( $binding['user_id'] ), (string) ( $binding['generation'] ?? '' ) ) && self::active_user( wp_get_current_user() );
	}

	public static function content_query( $args ) {
		$user = wp_get_current_user();
		$type = get_post_type_object( $args['post_type'] ?? 'post' );
		if ( ! self::active_user( $user ) || ! $type || ! user_can( $user, $type->cap->edit_posts ) ) {
			$args['post__in'] = array( 0 );
			return $args;
		}
		if ( ! user_can( $user, $type->cap->edit_others_posts ) ) {
			$args['author'] = $user->ID;
		}
		if ( ! user_can( $user, $type->cap->read_private_posts ) && isset( $args['post_status'] ) ) {
			$args['post_status'] = array_values( array_diff( (array) $args['post_status'], array( 'private' ) ) );
			if ( ! $args['post_status'] ) {
				$args['post__in'] = array( 0 );
			}
		}
		return $args;
	}
}
