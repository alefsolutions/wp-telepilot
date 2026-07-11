<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Dashboard_Service {
	public function get_summary() {
		global $wpdb;

		$health_label         = $this->get_health_label();
		$active_theme         = wp_get_theme();
		$plugin_counts        = $this->get_plugin_counts();
		$draft_posts_count    = (int) wp_count_posts( 'post' )->draft;
		$pending_comments     = (int) wp_count_comments()->moderated;
		$update_counts        = $this->get_update_counts();
		$database_version     = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';

		return array(
			'site_name'             => get_bloginfo( 'name' ),
			'site_url'              => home_url( '/' ),
			'wordpress_version'     => get_bloginfo( 'version' ),
			'php_version'           => PHP_VERSION,
			'database_version'      => $database_version,
			'active_theme'          => $active_theme->exists() ? $active_theme->get( 'Name' ) : __( 'Unknown', 'telepress' ),
			'active_plugins_count'  => $plugin_counts['active'],
			'inactive_plugins_count'=> $plugin_counts['inactive'],
			'site_health'           => $health_label,
			'draft_posts_count'     => $draft_posts_count,
			'pending_comments'      => $pending_comments,
			'pending_updates'       => $update_counts,
		);
	}

	public function render_summary_message( $summary ) {
		$lines   = array();
		$lines[] = __( 'Dashboard Summary', 'telepress' );
		$lines[] = sprintf( __( 'Site: %s', 'telepress' ), $summary['site_name'] );
		$lines[] = sprintf( __( 'URL: %s', 'telepress' ), $summary['site_url'] );
		$lines[] = sprintf( __( 'WordPress: %s', 'telepress' ), $summary['wordpress_version'] );
		$lines[] = sprintf( __( 'PHP: %s', 'telepress' ), $summary['php_version'] );
		$lines[] = sprintf( __( 'Database: %s', 'telepress' ), $summary['database_version'] );
		$lines[] = sprintf( __( 'Theme: %s', 'telepress' ), $summary['active_theme'] );
		$lines[] = sprintf( __( 'Active plugins: %d', 'telepress' ), $summary['active_plugins_count'] );
		$lines[] = sprintf( __( 'Site health: %s', 'telepress' ), $summary['site_health'] );
		$lines[] = sprintf( __( 'Draft posts: %d', 'telepress' ), $summary['draft_posts_count'] );
		$lines[] = sprintf( __( 'Pending comments: %d', 'telepress' ), $summary['pending_comments'] );
		$lines[] = sprintf(
			__( 'Pending updates: core %1$d, plugins %2$d, themes %3$d', 'telepress' ),
			$summary['pending_updates']['core'],
			$summary['pending_updates']['plugins'],
			$summary['pending_updates']['themes']
		);

		return implode( "\n", $lines );
	}

	private function get_health_label() {
		if ( class_exists( 'WP_Site_Health' ) ) {
			$tests = WP_Site_Health::get_instance()->get_tests();

			if ( ! empty( $tests['direct'] ) ) {
				return __( 'Checks available', 'telepress' );
			}
		}

		return __( 'Not evaluated', 'telepress' );
	}

	private function get_plugin_counts() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		return array(
			'active'   => count( $active_plugins ),
			'inactive' => max( 0, count( $all_plugins ) - count( $active_plugins ) ),
		);
	}

	private function get_update_counts() {
		$counts = array(
			'core'    => 0,
			'plugins' => 0,
			'themes'  => 0,
		);

		$core_updates = get_site_transient( 'update_core' );
		if ( ! empty( $core_updates->updates ) && is_array( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( ! empty( $update->response ) && 'latest' !== $update->response ) {
					$counts['core']++;
				}
			}
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		if ( ! empty( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) {
			$counts['plugins'] = count( $plugin_updates->response );
		}

		$theme_updates = get_site_transient( 'update_themes' );
		if ( ! empty( $theme_updates->response ) && is_array( $theme_updates->response ) ) {
			$counts['themes'] = count( $theme_updates->response );
		}

		return $counts;
	}
}
