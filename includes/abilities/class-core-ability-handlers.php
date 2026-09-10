<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Core_Ability_Handlers {
	private $registry;
	private $dashboard_service;
	private $posts_service;
	private $pages_service;
	private $comments_service;
	private $users_service;
	private $plugins_service;

	public function __construct() {
		$confirmation_service      = new Telepilot_Confirmation_Service();
		$this->registry            = new Telepilot_Ability_Registry();
		$this->dashboard_service   = new Telepilot_Dashboard_Service();
		$this->posts_service       = new Telepilot_Posts_Service( $confirmation_service );
		$this->pages_service       = new Telepilot_Pages_Service( $confirmation_service );
		$this->comments_service    = new Telepilot_Comments_Service( $confirmation_service );
		$this->users_service       = new Telepilot_Users_Service( $confirmation_service );
		$this->plugins_service     = new Telepilot_Plugins_Service( $confirmation_service );
	}

	public function execute( $tool_name, $arguments, $identity, $session ) {
		$tool = $this->registry->get_ability_by_tool( $tool_name );

		if ( empty( $tool ) ) {
			return new WP_Error( 'telepilot_ai_tool_missing', __( 'That AI tool is not registered.', 'wp-telepilot' ) );
		}

		$capability_check = Telepilot_Ability_Executor::permission( $tool['ability_name'], $arguments );
		if ( is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		switch ( $tool_name ) {
			case 'telepilot_get_site_info':
				return $this->get_site_info();

			case 'telepilot_get_site_health':
				return $this->get_site_health();

			case 'telepilot_search_posts':
				return $this->search_posts( $arguments );

			case 'telepilot_get_post':
				return $this->get_post( $arguments, $session );

			case 'telepilot_list_pages':
				return $this->list_pages( $arguments );

			case 'telepilot_get_page':
				return $this->get_page( $arguments, $session );

			case 'telepilot_list_comments':
				return $this->list_comments( $arguments );

			case 'telepilot_list_users':
				return $this->list_users( $arguments );

			case 'telepilot_list_plugins':
				return $this->list_plugins( $arguments );

			case 'telepilot_list_updates':
				return $this->list_updates();

			case 'telepilot_get_recent_alerts':
				return $this->get_recent_alerts( $arguments );

			case 'telepilot_create_draft':
			case 'telepilot_update_draft':
			case 'telepilot_schedule_post':
			case 'telepilot_publish_post':
			case 'telepilot_unpublish_post':
			case 'telepilot_approve_comment':
			case 'telepilot_trash_comment':
			case 'telepilot_activate_plugin':
			case 'telepilot_deactivate_plugin':
			case 'telepilot_update_plugin':
				return $this->prepare_action( $tool_name, $arguments, $session );
		}

		return new WP_Error( 'telepilot_ai_tool_unsupported', __( 'That AI tool is not supported yet.', 'wp-telepilot' ) );
	}

	private function get_site_info() {
		$summary = $this->dashboard_service->get_summary();

		return array(
			'ok'       => true,
			'intent'   => 'site_info',
			'summary'  => sprintf( 'Site %1$s running WordPress %2$s on PHP %3$s.', $summary['site_name'], $summary['wordpress_version'], $summary['php_version'] ),
			'entities' => array(),
			'data'     => array(
				'site_name'         => $summary['site_name'],
				'site_url'          => $summary['site_url'],
				'wordpress_version' => $summary['wordpress_version'],
				'php_version'       => $summary['php_version'],
				'database_version'  => $summary['database_version'],
				'active_theme'      => $summary['active_theme'],
				'draft_posts_count' => $summary['draft_posts_count'],
				'pending_comments'  => $summary['pending_comments'],
				'pending_updates'   => $summary['pending_updates'],
			),
		);
	}

	private function get_site_health() {
		$summary = $this->dashboard_service->get_summary();

		return array(
			'ok'       => true,
			'intent'   => 'site_health',
			'summary'  => sprintf( 'Site health is %1$s with %2$d plugin updates pending.', $summary['site_health'], (int) $summary['pending_updates']['plugins'] ),
			'entities' => array(),
			'data'     => array(
				'site_health'     => $summary['site_health'],
				'pending_updates' => $summary['pending_updates'],
				'draft_posts'     => $summary['draft_posts_count'],
				'pending_comments'=> $summary['pending_comments'],
			),
		);
	}

	private function search_posts( $arguments ) {
		$query  = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$status = ! empty( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'latest';
		$page   = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit  = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		$result = $this->posts_service->filtered_page( $query, $status, $page, $limit );
		if ( is_wp_error( $result ) ) { return $result; }

		return $this->build_post_list_result( $result, $query ? 'search_posts' : 'list_posts' );
	}

	private function get_post( $arguments, $session ) {
		$post_id = ! empty( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;


		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_ai_post_not_found', __( 'That post could not be found.', 'wp-telepilot' ) );
		}

		$categories = $this->collect_term_labels( $post->ID, 'category' );
		$tags       = $this->collect_term_labels( $post->ID, 'post_tag' );
		$author     = get_the_author_meta( 'display_name', (int) $post->post_author );

		return array(
			'ok'      => true,
			'intent'  => 'get_post',
			'summary' => sprintf( 'Loaded post %1$d named %2$s.', $post->ID, get_the_title( $post ) ),
			'entities'=> array(
				array(
					'position' => 1,
					'type'     => 'post',
					'id'       => $post->ID,
					'title'    => get_the_title( $post ),
				),
			),
			'data'    => array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post ),
				'status'      => $post->post_status,
				'author'      => $author ? $author : get_the_author_meta( 'user_login', (int) $post->post_author ),
				'date'        => get_post_time( 'Y-m-d H:i:s', false, $post, true ),
				'updated'     => get_post_modified_time( 'Y-m-d H:i:s', false, $post, true ),
				'excerpt'     => wp_strip_all_tags( (string) $post->post_excerpt ),
				'categories'  => $categories,
				'tags'        => $tags,
				'admin_url'   => admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ),
				'public_url'  => 'publish' === $post->post_status ? get_permalink( $post ) : '',
			),
		);
	}

	private function list_pages( $arguments ) {
		$query  = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$status = ! empty( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'latest';
		$page   = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit  = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		$result = $this->pages_service->filtered_page( $query, $status, $page, $limit );
		if ( is_wp_error( $result ) ) { return $result; }

		return $this->build_page_list_result( $result, $query ? 'search_pages' : 'list_pages' );
	}

	private function get_page( $arguments, $session ) {
		$page_id = ! empty( $arguments['page_id'] ) ? absint( $arguments['page_id'] ) : 0;


		$page = $this->pages_service->get_page_details( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return array(
			'ok'      => true,
			'intent'  => 'get_page',
			'summary' => sprintf( 'Loaded page %1$d named %2$s.', $page->ID, get_the_title( $page ) ),
			'entities'=> array(
				array(
					'position' => 1,
					'type'     => 'page',
					'id'       => $page->ID,
					'title'    => get_the_title( $page ),
				),
			),
			'data'    => array(
				'id'         => $page->ID,
				'title'      => get_the_title( $page ),
				'status'     => $page->post_status,
				'slug'       => $page->post_name,
				'updated'    => get_post_modified_time( 'Y-m-d H:i:s', false, $page, true ),
				'admin_url'  => admin_url( 'post.php?post=' . (int) $page->ID . '&action=edit' ),
				'public_url' => 'publish' === $page->post_status ? get_permalink( $page ) : '',
			),
		);
	}

	private function list_comments( $arguments ) {
		$status = ! empty( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'pending';
		$query  = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$page   = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit  = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		$result = $this->comments_service->filtered_page( $query, $status, $page, $limit );
		if ( is_wp_error( $result ) ) { return $result; }

		$items    = array();
		$entities = array();
		$position = 1;

		foreach ( $result['items'] as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			$post_title   = get_the_title( $comment->comment_post_ID );
			$status_label = wp_get_comment_status( $comment );
			$excerpt      = wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 140, '...' );

			$items[]    = array(
				'id'         => $comment->comment_ID,
				'author'     => $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ),
				'status'     => $status_label,
				'post_title' => $post_title ? $post_title : __( 'Unknown Post', 'wp-telepilot' ),
				'date'       => get_comment_date( 'Y-m-d H:i:s', $comment ),
				'excerpt'    => $excerpt,
			);
			$entities[] = array(
				'position' => $position,
				'type'     => 'comment',
				'id'       => $comment->comment_ID,
				'title'    => $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ),
			);
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => '' !== $query ? 'search_comments' : 'list_comments',
			'summary' => sprintf( 'Loaded %d comments.', count( $items ) ),
			'entities'=> $entities,
			'data'    => array(
				'items'       => $items,
				'page'        => isset( $result['page'] ) ? (int) $result['page'] : 1,
				'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1,
				'total_items' => isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $items ),
			),
		);
	}

	private function list_users( $arguments ) {
		$query = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$page  = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		$result   = '' !== $query ? $this->users_service->search_page( $query, $page, $limit ) : $this->users_service->recent_page( $page, $limit );
		$items    = array();
		$entities = array();
		$position = 1;

		foreach ( $result['items'] as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$items[]    = array(
				'id'           => $user->ID,
				'user_login'   => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
				'status'       => $this->users_service->is_disabled( $user->ID ) ? 'disabled' : 'active',
			);
			$entities[] = array(
				'position' => $position,
				'type'     => 'user',
				'id'       => $user->ID,
				'title'    => $user->display_name ? $user->display_name : $user->user_login,
			);
			if ( ! empty( Telepilot_AI_Settings::get_settings()['ai_include_email_fields'] ) && 'ai' === Telepilot_Operation_Context::current()->source ) { $items[ count( $items ) - 1 ]['email'] = $user->user_email; }
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => '' !== $query ? 'search_users' : 'list_users',
			'summary' => sprintf( 'Loaded %d users.', count( $items ) ),
			'entities'=> $entities,
			'data'    => array(
				'items'       => $items,
				'page'        => isset( $result['page'] ) ? (int) $result['page'] : 1,
				'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1,
				'total_items' => isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $items ),
			),
		);
	}

	private function list_plugins( $arguments ) {
		$mode  = ! empty( $arguments['mode'] ) ? sanitize_key( (string) $arguments['mode'] ) : 'list';
		$query = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$page  = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		if ( '' !== $query && 'updates' !== $mode ) {
			$result = $this->plugins_service->search_page( $query, $page, $limit );
		} elseif ( 'updates' === $mode ) {
			$result = $this->plugins_service->updates_page( $page, $limit );
		} else {
			$result = $this->plugins_service->list_page( $page, $limit );
		}

		$items    = array();
		$entities = array();
		$position = 1;

		foreach ( $result['items'] as $plugin ) {
			$items[]    = array(
				'identifier'     => $plugin['identifier'],
				'name'           => $plugin['name'],
				'status'         => ! empty( $plugin['is_active'] ) ? 'active' : 'inactive',
				'version'        => $plugin['version'],
				'update_version' => $plugin['update_version'],
			);
			$entities[] = array(
				'position'   => $position,
				'type'       => 'plugin',
				'id'         => $plugin['identifier'],
				'title'      => $plugin['name'],
				'identifier' => $plugin['identifier'],
			);
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => 'updates' === $mode ? 'list_plugin_updates' : ( '' !== $query ? 'search_plugins' : 'list_plugins' ),
			'summary' => sprintf( 'Loaded %d plugins.', count( $items ) ),
			'entities'=> $entities,
			'data'    => array(
				'items'       => $items,
				'page'        => isset( $result['page'] ) ? (int) $result['page'] : 1,
				'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1,
				'total_items' => isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $items ),
			),
		);
	}

	private function list_updates() {
		$summary        = $this->dashboard_service->get_summary();
		$plugin_updates = $this->plugins_service->updates_page( 1, 10 );
		$items          = array();
		$entities       = array();
		$position       = 1;

		foreach ( $plugin_updates['items'] as $plugin ) {
			$items[]    = array(
				'identifier'     => $plugin['identifier'],
				'name'           => $plugin['name'],
				'version'        => $plugin['version'],
				'update_version' => $plugin['update_version'],
			);
			$entities[] = array(
				'position'   => $position,
				'type'       => 'plugin',
				'id'         => $plugin['identifier'],
				'title'      => $plugin['name'],
				'identifier' => $plugin['identifier'],
			);
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => 'list_updates',
			'summary' => sprintf( 'Core updates: %1$d. Plugin updates: %2$d. Theme updates: %3$d.', (int) $summary['pending_updates']['core'], (int) $summary['pending_updates']['plugins'], (int) $summary['pending_updates']['themes'] ),
			'entities'=> $entities,
			'data'    => array(
				'core'    => (int) $summary['pending_updates']['core'],
				'plugins' => (int) $summary['pending_updates']['plugins'],
				'themes'  => (int) $summary['pending_updates']['themes'],
				'items'   => $items,
			),
		);
	}

	private function get_recent_alerts( $arguments ) {
		$limit = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;
		$logs  = Telepilot_Audit_Log_Repository::recent_logs( $limit );
		$items = array();

		foreach ( $logs as $log ) {
			$items[] = array(
				'action'     => ! empty( $log['action_name'] ) ? (string) $log['action_name'] : '',
				'resource'   => ! empty( $log['resource_type'] ) ? (string) $log['resource_type'] : '',
				'resource_id'=> ! empty( $log['resource_id'] ) ? (string) $log['resource_id'] : '',
				'success'    => ! empty( $log['was_successful'] ),
				'created_at' => ! empty( $log['created_at'] ) ? (string) $log['created_at'] : '',
			);
		}

		return array(
			'ok'      => true,
			'intent'  => 'recent_alerts',
			'summary' => sprintf( 'Loaded %d recent audit entries.', count( $items ) ),
			'entities'=> array(),
			'data'    => array(
				'items' => $items,
			),
		);
	}

	public function execute_confirmed( $tool_name, $arguments, $identity, $session ) {
		$tool = $this->registry->get_ability_by_tool( $tool_name );

		if ( empty( $tool ) ) {
			return new WP_Error( 'telepilot_ai_tool_missing', __( 'That AI tool is not registered.', 'wp-telepilot' ) );
		}

		$capability_check = Telepilot_Ability_Executor::permission( $tool['ability_name'], $arguments );
		if ( is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		switch ( $tool_name ) {
			case 'telepilot_create_draft':
				return $this->execute_create_draft( $arguments );
			case 'telepilot_update_draft':
				return $this->execute_update_draft( $arguments, $session );
			case 'telepilot_schedule_post':
				return $this->execute_schedule_post( $arguments, $session );
			case 'telepilot_publish_post':
				return $this->execute_publish_post( $arguments, $session );
			case 'telepilot_unpublish_post':
				return $this->execute_unpublish_post( $arguments, $session );
			case 'telepilot_approve_comment':
				return $this->execute_comment_action( 'approve', $arguments, $session );
			case 'telepilot_trash_comment':
				return $this->execute_comment_action( 'trash', $arguments, $session );
			case 'telepilot_activate_plugin':
				return $this->execute_plugin_action( 'activate', $arguments, $session );
			case 'telepilot_deactivate_plugin':
				return $this->execute_plugin_action( 'deactivate', $arguments, $session );
			case 'telepilot_update_plugin':
				return $this->execute_plugin_action( 'update', $arguments, $session );
		}

		return new WP_Error( 'telepilot_ai_tool_unsupported', __( 'That AI tool is not supported yet.', 'wp-telepilot' ) );
	}

	private function build_post_list_result( $result, $intent ) {
		$items    = array();
		$entities = array();
		$position = 1;

		foreach ( $result['items'] as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$items[]    = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'status'     => $post->post_status,
				'author'     => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'updated'    => get_post_modified_time( 'Y-m-d H:i:s', false, $post, true ),
				'categories' => $this->collect_term_labels( $post->ID, 'category' ),
				'tags'       => $this->collect_term_labels( $post->ID, 'post_tag' ),
			);
			$entities[] = array(
				'position' => $position,
				'type'     => 'post',
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
			);
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => $intent,
			'summary' => sprintf( 'Loaded %d posts.', count( $items ) ),
			'entities'=> $entities,
			'data'    => array(
				'items'       => $items,
				'page'        => isset( $result['page'] ) ? (int) $result['page'] : 1,
				'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1,
				'total_items' => isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $items ),
			),
		);
	}

	private function prepare_action( $tool_name, $arguments, $session ) {
		switch ( $tool_name ) {
			case 'telepilot_create_draft':
				$title = ! empty( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : '';
				if ( '' === $title ) {
					return new WP_Error( 'telepilot_ai_title_required', __( 'A draft title is required.', 'wp-telepilot' ) );
				}

				return array(
					'ok'         => true,
					'ability'    => 'telepilot/create-draft',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'title'      => $title,
						'categories' => array_values( array_filter( array_map( 'absint', isset( $arguments['categories'] ) ? (array) $arguments['categories'] : array() ) ) ),
					),
					'prompt'     => sprintf( __( 'You are about to create a new draft post titled "%s".', 'wp-telepilot' ), $title ),
					'risk_level' => 'low_write',
				);

			case 'telepilot_update_draft':
				$post = $this->resolve_post_from_arguments( $arguments, $session );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( 'draft' !== $post->post_status && 'pending' !== $post->post_status && 'future' !== $post->post_status ) {
					return new WP_Error( 'telepilot_ai_update_draft_invalid', __( 'Only drafts, pending posts, or scheduled posts can be updated through this AI draft tool.', 'wp-telepilot' ) );
				}
				$title   = isset( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : '';
				$excerpt = isset( $arguments['excerpt'] ) ? sanitize_textarea_field( (string) $arguments['excerpt'] ) : '';
				if ( '' === $title && '' === $excerpt ) {
					return new WP_Error( 'telepilot_ai_update_draft_empty', __( 'Provide a new title or excerpt for the draft update.', 'wp-telepilot' ) );
				}

				return array(
					'ok'         => true,
					'ability'    => 'telepilot/update-draft',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'post_id' => (int) $post->ID,
						'title'   => $title,
						'excerpt' => $excerpt,
					),
					'prompt'     => sprintf( __( 'You are about to update draft post [%1$d] %2$s.', 'wp-telepilot' ), $post->ID, get_the_title( $post ) ),
					'risk_level' => 'low_write',
				);

			case 'telepilot_schedule_post':
				$post = $this->resolve_post_from_arguments( $arguments, $session );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$scheduled_at = $this->parse_site_datetime( ! empty( $arguments['scheduled_at'] ) ? $arguments['scheduled_at'] : '' );
				if ( ! $scheduled_at ) {
					return new WP_Error( 'telepilot_ai_schedule_invalid', __( 'Provide a valid site-local schedule datetime such as 2026-07-28 14:30.', 'wp-telepilot' ) );
				}

				return array(
					'ok'         => true,
					'ability'    => 'telepilot/schedule-post',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'post_id'      => (int) $post->ID,
						'scheduled_at' => $scheduled_at->format( 'Y-m-d H:i:s' ),
					),
					'prompt'     => sprintf( __( 'You are about to schedule post [%1$d] %2$s for %3$s.', 'wp-telepilot' ), $post->ID, get_the_title( $post ), $scheduled_at->format( 'Y-m-d H:i:s' ) ),
					'risk_level' => 'moderate_write',
				);

			case 'telepilot_publish_post':
				$post = $this->resolve_post_from_arguments( $arguments, $session );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				return array(
					'ok'         => true,
					'ability'    => 'telepilot/publish-post',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'post_id' => (int) $post->ID,
					),
					'prompt'     => sprintf( __( 'You are about to publish post [%1$d] %2$s.', 'wp-telepilot' ), $post->ID, get_the_title( $post ) ),
					'risk_level' => 'high_risk',
				);

			case 'telepilot_unpublish_post':
				$post = $this->resolve_post_from_arguments( $arguments, $session );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				return array(
					'ok'         => true,
					'ability'    => 'telepilot/unpublish-post',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'post_id' => (int) $post->ID,
					),
					'prompt'     => sprintf( __( 'You are about to move post [%1$d] %2$s back to draft.', 'wp-telepilot' ), $post->ID, get_the_title( $post ) ),
					'risk_level' => 'moderate_write',
				);

			case 'telepilot_approve_comment':
			case 'telepilot_trash_comment':
				$comment = $this->resolve_comment_from_arguments( $arguments, $session );
				if ( is_wp_error( $comment ) ) {
					return $comment;
				}
				$action_label = 'telepilot_approve_comment' === $tool_name ? __( 'approve', 'wp-telepilot' ) : __( 'trash', 'wp-telepilot' );

				return array(
					'ok'         => true,
					'ability'    => 'telepilot_approve_comment' === $tool_name ? 'telepilot/approve-comment' : 'telepilot/trash-comment',
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'comment_id' => (int) $comment->comment_ID,
					),
					'prompt'     => sprintf( __( 'You are about to %1$s comment [%2$d] by %3$s.', 'wp-telepilot' ), $action_label, $comment->comment_ID, $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ) ),
					'risk_level' => 'moderate_write',
				);

			case 'telepilot_activate_plugin':
			case 'telepilot_deactivate_plugin':
			case 'telepilot_update_plugin':
				$plugin = $this->resolve_plugin_from_arguments( $arguments, $session );
				if ( is_wp_error( $plugin ) ) {
					return $plugin;
				}
				$action_label = str_replace( 'telepilot_', '', $tool_name );
				$action_label = str_replace( '_', ' ', $action_label );

				return array(
					'ok'         => true,
					'ability'    => $this->registry->get_ability_by_tool( $tool_name )['ability_name'],
					'tool_name'  => $tool_name,
					'arguments'  => array(
						'identifier' => (string) $plugin['identifier'],
					),
					'prompt'     => sprintf( __( 'You are about to %1$s plugin [%2$s] %3$s.', 'wp-telepilot' ), $action_label, $plugin['identifier'], $plugin['name'] ),
					'risk_level' => in_array( $tool_name, array( 'telepilot_deactivate_plugin' ), true ) ? 'high_risk' : 'moderate_write',
				);
		}

		return new WP_Error( 'telepilot_ai_prepare_unsupported', __( 'That AI action preview is not supported yet.', 'wp-telepilot' ) );
	}

	private function build_page_list_result( $result, $intent ) {
		$items    = array();
		$entities = array();
		$position = 1;

		foreach ( $result['items'] as $page_item ) {
			if ( ! $page_item instanceof WP_Post ) {
				continue;
			}

			$items[]    = array(
				'id'      => $page_item->ID,
				'title'   => get_the_title( $page_item ),
				'status'  => $page_item->post_status,
				'updated' => get_post_modified_time( 'Y-m-d H:i:s', false, $page_item, true ),
				'slug'    => $page_item->post_name,
			);
			$entities[] = array(
				'position' => $position,
				'type'     => 'page',
				'id'       => $page_item->ID,
				'title'    => get_the_title( $page_item ),
			);
			$position++;
		}

		return array(
			'ok'      => true,
			'intent'  => $intent,
			'summary' => sprintf( 'Loaded %d pages.', count( $items ) ),
			'entities'=> $entities,
			'data'    => array(
				'items'       => $items,
				'page'        => isset( $result['page'] ) ? (int) $result['page'] : 1,
				'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1,
				'total_items' => isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $items ),
			),
		);
	}

	private function collect_term_labels( $post_id, $taxonomy ) {
		$terms = get_the_terms( (int) $post_id, (string) $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$labels = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$labels[] = array(
				'id'   => $term->term_id,
				'name' => $term->name,
			);
		}

		return $labels;
	}

	private function resolve_post_from_arguments( $arguments, $session ) {
		$post_id = ! empty( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;


		$post = $post_id ? get_post( $post_id ) : null;

		return ( $post && 'post' === $post->post_type ) ? $post : new WP_Error( 'telepilot_ai_post_not_found', __( 'That post could not be found.', 'wp-telepilot' ) );
	}

	private function resolve_comment_from_arguments( $arguments, $session ) {
		$comment_id = ! empty( $arguments['comment_id'] ) ? absint( $arguments['comment_id'] ) : 0;


		$comment = get_comment( $comment_id );

		return $comment instanceof WP_Comment ? $comment : new WP_Error( 'telepilot_ai_comment_not_found', __( 'That comment could not be found.', 'wp-telepilot' ) );
	}

	private function resolve_plugin_from_arguments( $arguments, $session ) {
		$identifier = ! empty( $arguments['identifier'] ) ? sanitize_text_field( (string) $arguments['identifier'] ) : '';


		$plugin = $this->plugins_service->get_plugin_details( $identifier );

		return is_wp_error( $plugin ) ? $plugin : $plugin;
	}

	private function execute_create_draft( $arguments ) {
		$result = $this->posts_service->create_draft(
			! empty( $arguments['title'] ) ? (string) $arguments['title'] : '',
			! empty( $arguments['categories'] ) ? (array) $arguments['categories'] : array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Draft post [%1$d] %2$s was created.', 'wp-telepilot' ), $result['post']->ID, get_the_title( $result['post'] ) ),
			'data'    => array(
				'post_id' => $result['post']->ID,
				'title'   => get_the_title( $result['post'] ),
				'status'  => $result['post']->post_status,
			),
		);
	}

	private function execute_update_draft( $arguments, $session ) {
		$post = $this->resolve_post_from_arguments( $arguments, $session );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$messages = array();
		$title    = isset( $arguments['title'] ) ? (string) $arguments['title'] : '';
		$excerpt  = isset( $arguments['excerpt'] ) ? (string) $arguments['excerpt'] : '';

		if ( '' !== $title ) {
			$title_result = $this->posts_service->update_title( $post->ID, $title );
			if ( is_wp_error( $title_result ) ) {
				return $title_result;
			}
			$messages[] = __( 'title updated', 'wp-telepilot' );
		}

		if ( '' !== $excerpt ) {
			$excerpt_result = $this->posts_service->update_excerpt( $post->ID, $excerpt );
			if ( is_wp_error( $excerpt_result ) ) {
				return $excerpt_result;
			}
			$messages[] = __( 'excerpt updated', 'wp-telepilot' );
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Draft post [%1$d] %2$s was updated: %3$s.', 'wp-telepilot' ), $post->ID, get_the_title( get_post( $post->ID ) ), implode( ', ', $messages ) ),
			'data'    => array(
				'post_id' => $post->ID,
			),
		);
	}

	private function execute_schedule_post( $arguments, $session ) {
		$post = $this->resolve_post_from_arguments( $arguments, $session );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$scheduled_at = $this->parse_site_datetime( ! empty( $arguments['scheduled_at'] ) ? $arguments['scheduled_at'] : '' );
		if ( ! $scheduled_at ) {
			return new WP_Error( 'telepilot_ai_schedule_invalid', __( 'The requested schedule time is invalid.', 'wp-telepilot' ) );
		}

		$result = $this->posts_service->schedule( $post->ID, $scheduled_at );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Post [%1$d] %2$s was scheduled for %3$s.', 'wp-telepilot' ), $post->ID, get_the_title( $post ), $scheduled_at->format( 'Y-m-d H:i:s' ) ),
			'data'    => array(
				'post_id'      => $post->ID,
				'scheduled_at' => $scheduled_at->format( 'Y-m-d H:i:s' ),
			),
		);
	}

	private function execute_publish_post( $arguments, $session ) {
		$post = $this->resolve_post_from_arguments( $arguments, $session );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = $this->posts_service->publish( $post->ID );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Post [%1$d] %2$s was published.', 'wp-telepilot' ), $post->ID, get_the_title( $post ) ),
			'data'    => array(
				'post_id' => $post->ID,
			),
		);
	}

	private function execute_unpublish_post( $arguments, $session ) {
		$post = $this->resolve_post_from_arguments( $arguments, $session );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = $this->posts_service->unpublish( $post->ID );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Post [%1$d] %2$s was moved back to draft.', 'wp-telepilot' ), $post->ID, get_the_title( $post ) ),
			'data'    => array(
				'post_id' => $post->ID,
			),
		);
	}

	private function execute_comment_action( $action, $arguments, $session ) {
		$comment = $this->resolve_comment_from_arguments( $arguments, $session );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		$result = $this->comments_service->moderate_comment( $comment->comment_ID, $action );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Comment [%1$d] was %2$s.', 'wp-telepilot' ), $comment->comment_ID, $result['label'] ),
			'data'    => array(
				'comment_id' => $comment->comment_ID,
			),
		);
	}

	private function execute_plugin_action( $action, $arguments, $session ) {
		$plugin = $this->resolve_plugin_from_arguments( $arguments, $session );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		switch ( $action ) {
			case 'activate':
				$result = $this->plugins_service->activate( $plugin['identifier'] );
				break;
			case 'deactivate':
				$result = $this->plugins_service->deactivate( $plugin['identifier'] );
				break;
			case 'update':
				$result = $this->plugins_service->update( $plugin['identifier'] );
				break;
			default:
				return new WP_Error( 'telepilot_ai_plugin_action_invalid', __( 'Unsupported AI plugin action.', 'wp-telepilot' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'summary' => sprintf( __( 'Plugin [%1$s] %2$s was %3$s.', 'wp-telepilot' ), $plugin['identifier'], $plugin['name'], $result['label'] ),
			'data'    => array(
				'identifier' => $plugin['identifier'],
			),
		);
	}

	private function parse_site_datetime( $raw_value ) {
		$raw_value = trim( (string) $raw_value );
		if ( '' === $raw_value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $raw_value, wp_timezone() );
		} catch ( Exception $exception ) {
			return null;
		}
	}
}
