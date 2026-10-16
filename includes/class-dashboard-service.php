<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Dashboard_Service {
	const CACHE_KEY = 'telepilot_dashboard_summary';

	public function get_summary() {
		return Telepilot_Domain_Abilities::run( 'site', 'get_summary', array(), function () { return $this->operation_get_summary(); } );
	}

	private function operation_get_summary() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$health_label         = $this->get_health_label();
		$active_theme         = wp_get_theme();
		$plugin_counts        = $this->get_plugin_counts();
		$draft_posts_count    = (int) wp_count_posts( 'post' )->draft;
		$pending_comments     = (int) wp_count_comments()->moderated;
		$update_counts        = $this->get_update_counts();
		$database_version     = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';

		$summary = array(
			'site_name'             => get_bloginfo( 'name' ),
			'site_url'              => home_url( '/' ),
			'wordpress_version'     => get_bloginfo( 'version' ),
			'php_version'           => PHP_VERSION,
			'database_version'      => $database_version,
			'active_theme'          => $active_theme->exists() ? $active_theme->get( 'Name' ) : __( 'Unknown', 'wp-telepilot' ),
			'active_plugins_count'  => $plugin_counts['active'],
			'inactive_plugins_count'=> $plugin_counts['inactive'],
			'site_health'           => $health_label,
			'draft_posts_count'     => $draft_posts_count,
			'pending_comments'      => $pending_comments,
			'pending_updates'       => $update_counts,
		);

		set_transient( self::CACHE_KEY, $summary, MINUTE_IN_SECONDS );

		return $summary;
	}

	public function render_summary_message( $summary ) {
		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'site', __( 'Site Overview', 'wp-telepilot' ) ) );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'site', sprintf( /* translators: %s: site name. */ __( 'Site: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['site_name'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'link', sprintf( /* translators: %s: site url. */ __( 'URL: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['site_url'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'site', sprintf( /* translators: %s: wordpress version. */ __( 'WordPress: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['wordpress_version'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'settings', sprintf( /* translators: %s: php version. */ __( 'PHP: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['php_version'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'settings', sprintf( /* translators: %s: database version. */ __( 'Database: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['database_version'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'pages', sprintf( /* translators: %s: active theme. */ __( 'Theme: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['active_theme'] ) ) );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'posts', sprintf( /* translators: %d: draft posts count. */ __( 'Posts in draft: %d', 'wp-telepilot' ), $summary['draft_posts_count'] ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'comments', sprintf( /* translators: %d: pending comments. */ __( 'Pending comments: %d', 'wp-telepilot' ), $summary['pending_comments'] ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'plugins', sprintf( /* translators: %d: active plugins count. */ __( 'Active plugins: %d', 'wp-telepilot' ), $summary['active_plugins_count'] ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'stats', sprintf( /* translators: %s: site health. */ __( 'Site health: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['site_health'] ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::label( 'update', sprintf(
			/* translators: 1: pending updates, 2: pending updates, 3: pending updates. */ __( 'Updates: core %1$d, plugins %2$d, themes %3$d', 'wp-telepilot' ),
			$summary['pending_updates']['core'],
			$summary['pending_updates']['plugins'],
			$summary['pending_updates']['themes']
		) );

		return implode( "\n", $lines );
	}

	public function build_overview_keyboard( $wp_user ) {
		$rows   = array();
		$rows[] = array(
			array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'refresh', __( 'Refresh', 'wp-telepilot' ) ),
				'callback_data' => '/site',
			),
			array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
				'callback_data' => '/menu',
			),
		);

		if ( $wp_user instanceof WP_User ) {
			$content_row = array();
			if ( user_can( $wp_user, 'edit_posts' ) ) {
				$content_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', __( 'Posts', 'wp-telepilot' ) ),
					'callback_data' => '/posts list',
				);
			}
			if ( user_can( $wp_user, 'edit_pages' ) ) {
				$content_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'pages', __( 'Pages', 'wp-telepilot' ) ),
					'callback_data' => '/pages list',
				);
			}
			if ( ! empty( $content_row ) ) {
				$rows[] = $content_row;
			}

			$editorial_row = array();
			if ( user_can( $wp_user, 'moderate_comments' ) ) {
				$editorial_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'comments', __( 'Comments', 'wp-telepilot' ) ),
					'callback_data' => '/comments pending',
				);
			}
			if ( user_can( $wp_user, 'upload_files' ) ) {
				$editorial_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'media', __( 'Media', 'wp-telepilot' ) ),
					'callback_data' => '/media list',
				);
			}
			if ( ! empty( $editorial_row ) ) {
				$rows[] = $editorial_row;
			}

			if ( user_can( $wp_user, 'manage_categories' ) ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'categories', __( 'Categories', 'wp-telepilot' ) ),
						'callback_data' => '/categories list',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'tags', __( 'Tags', 'wp-telepilot' ) ),
						'callback_data' => '/tags list',
					),
				);
			}

			$admin_tools_row = array();
			if ( user_can( $wp_user, 'list_users' ) ) {
				$admin_tools_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'users', __( 'Users', 'wp-telepilot' ) ),
					'callback_data' => '/users list',
				);
			}
			if ( user_can( $wp_user, 'activate_plugins' ) || user_can( $wp_user, 'update_plugins' ) || user_can( $wp_user, 'delete_plugins' ) ) {
				$admin_tools_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'plugins', __( 'Plugins', 'wp-telepilot' ) ),
					'callback_data' => '/plugins list',
				);
			}
			if ( ! empty( $admin_tools_row ) ) {
				$rows[] = $admin_tools_row;
			}

			if ( user_can( $wp_user, 'manage_options' ) ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'notifications', __( 'Notifications', 'wp-telepilot' ) ),
						'callback_data' => '/notifications list',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'settings', __( 'Settings', 'wp-telepilot' ) ),
						'callback_data' => '/settings',
					),
				);
			}
		}

		$admin_row = array(
			array(
				'text' => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Open wp-admin', 'wp-telepilot' ) ),
				'url'  => admin_url(),
			),
		);

		if ( $wp_user instanceof WP_User && user_can( $wp_user, 'manage_options' ) ) {
			$admin_row[] = array(
				'text' => Telepilot_Telegram_Response_Builder::label( 'settings', __( 'Telepilot Settings', 'wp-telepilot' ) ),
				'url'  => admin_url( 'admin.php?page=telepilot' ),
			);
		}

		$rows[] = $admin_row;

		return Telepilot_Telegram_Response_Builder::keyboard( $rows );
	}

	private function get_health_label() {
		$counts = get_transient( 'health-check-site-status-result' );
		if ( is_string( $counts ) ) { $counts = json_decode( $counts, true ); }
		if ( ! is_array( $counts ) || ! isset( $counts['critical'], $counts['recommended'] ) ) { return __( 'Not evaluated: open Tools > Site Health in WordPress.', 'wp-telepilot' ); }
		return sprintf( /* translators: 1: critical, 2: recommended. */ __( 'Last Site Health results: %1$d critical, %2$d recommended improvements.', 'wp-telepilot' ), (int) $counts['critical'], (int) $counts['recommended'] );
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
