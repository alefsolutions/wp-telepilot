<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Domain_Cache {
	public static function register() {
		add_action( 'updated_option', array( __CLASS__, 'option_changed' ) );
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'set_object_terms', 'created_term', 'edited_term', 'delete_term', 'clean_post_cache', 'clean_user_cache', 'user_register', 'profile_update', 'delete_user', 'set_user_role', 'clean_comment_cache', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'clean_term_cache', 'add_attachment', 'edit_attachment', 'delete_attachment' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ) );
		}
	}

	public static function option_changed( $option ) {
		if ( in_array( $option, array( 'blogname', 'blogdescription', 'home', 'siteurl', 'timezone_string', 'gmt_offset', 'admin_email', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_category' ), true ) ) { self::invalidate(); }
	}

	public static function invalidate() {
		// Random generations avoid lost increments under concurrent writes.
		foreach ( array( 'telepilot_posts_cache_version', 'telepilot_pages_cache_version', 'telepilot_users_cache_version', 'telepilot_comments_cache_version', 'telepilot_plugins_cache_version', 'telepilot_terms_cache_version', 'telepilot_media_cache_version' ) as $option ) {
			update_option( $option, (string) random_int( 1, PHP_INT_MAX - 1 ), false );
		}
	}
}
