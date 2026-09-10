<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Domain_Cache {
	public static function register() {
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'set_object_terms', 'created_term', 'edited_term', 'delete_term', 'clean_post_cache' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ) );
		}
	}

	public static function invalidate() {
		// Random generations avoid lost increments under concurrent writes.
		foreach ( array( 'telepilot_posts_cache_version', 'telepilot_pages_cache_version' ) as $option ) {
			update_option( $option, (string) random_int( 1, PHP_INT_MAX - 1 ), false );
		}
	}
}
