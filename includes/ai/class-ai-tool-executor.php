<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Tool_Executor {
	private $registry;
	private $permission_manager;
	private $dashboard_service;
	private $posts_service;
	private $pages_service;
	private $comments_service;
	private $users_service;
	private $plugins_service;
	private $conversation_store;

	public function __construct() {
		$confirmation_service      = new Telepilot_Confirmation_Service();
		$this->registry            = new Telepilot_AI_Tool_Registry();
		$this->permission_manager  = new Telepilot_AI_Permission_Manager();
		$this->dashboard_service   = new Telepilot_Dashboard_Service();
		$this->posts_service       = new Telepilot_Posts_Service( $confirmation_service );
		$this->pages_service       = new Telepilot_Pages_Service( $confirmation_service );
		$this->comments_service    = new Telepilot_Comments_Service( $confirmation_service );
		$this->users_service       = new Telepilot_Users_Service( $confirmation_service );
		$this->plugins_service     = new Telepilot_Plugins_Service( $confirmation_service );
		$this->conversation_store  = new Telepilot_AI_Conversation_Store();
	}

	public function execute( $tool_name, $arguments, $identity, $session ) {
		$tool = $this->registry->get_tool( $tool_name );

		if ( empty( $tool ) ) {
			return new WP_Error( 'telepilot_ai_tool_missing', __( 'That AI tool is not registered.', 'wp-telepilot' ) );
		}

		if ( ! $this->permission_manager->is_tool_enabled( $tool['ability'] ) ) {
			return new WP_Error( 'telepilot_ai_tool_disabled', __( 'That AI ability is currently disabled by the site administrator.', 'wp-telepilot' ) );
		}

		$capability_check = $this->check_tool_capability( $tool['ability'], $identity );
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

		if ( '' !== $query ) {
			$result = $this->posts_service->search_page( $query, $page, $limit );
		} elseif ( 'draft' === $status ) {
			$result = $this->posts_service->drafts_page( $page, $limit );
		} elseif ( 'trash' === $status ) {
			$result = $this->posts_service->trashed_page( $page, $limit );
		} else {
			$result = $this->posts_service->latest_page( $page, $limit );
		}

		return $this->build_post_list_result( $result, $query ? 'search_posts' : 'list_posts' );
	}

	private function get_post( $arguments, $session ) {
		$post_id = ! empty( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( ! $post_id && ! empty( $arguments['reference_number'] ) && ! empty( $session['session_id'] ) ) {
			$entity = $this->conversation_store->resolve_reference( $session['session_id'], absint( $arguments['reference_number'] ), array( 'post' ) );
			$post_id = ! empty( $entity['id'] ) ? absint( $entity['id'] ) : 0;
		}

		$post = get_post( $post_id );
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
				'public_url'  => get_permalink( $post ),
			),
		);
	}

	private function list_pages( $arguments ) {
		$query  = ! empty( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$status = ! empty( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : 'latest';
		$page   = ! empty( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$limit  = ! empty( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 5;

		if ( '' !== $query ) {
			$result = $this->pages_service->search_page( $query, $page, $limit );
		} elseif ( 'draft' === $status ) {
			$result = $this->pages_service->drafts_page( $page, $limit );
		} elseif ( 'trash' === $status ) {
			$result = $this->pages_service->trashed_page( $page, $limit );
		} else {
			$result = $this->pages_service->latest_page( $page, $limit );
		}

		return $this->build_page_list_result( $result, $query ? 'search_pages' : 'list_pages' );
	}

	private function get_page( $arguments, $session ) {
		$page_id = ! empty( $arguments['page_id'] ) ? absint( $arguments['page_id'] ) : 0;

		if ( ! $page_id && ! empty( $arguments['reference_number'] ) && ! empty( $session['session_id'] ) ) {
			$entity = $this->conversation_store->resolve_reference( $session['session_id'], absint( $arguments['reference_number'] ), array( 'page' ) );
			$page_id = ! empty( $entity['id'] ) ? absint( $entity['id'] ) : 0;
		}

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

		if ( '' !== $query ) {
			$result = $this->comments_service->search_page( $query, $page, $limit );
		} else {
			switch ( $status ) {
				case 'approved':
					$result = $this->comments_service->approved_page( $page, $limit );
					break;
				case 'spam':
					$result = $this->comments_service->spam_page( $page, $limit );
					break;
				case 'trash':
					$result = $this->comments_service->trash_page( $page, $limit );
					break;
				default:
					$result = $this->comments_service->pending_page( $page, $limit );
					break;
			}
		}

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
				'email'        => $user->user_email,
				'roles'        => array_values( (array) $user->roles ),
				'status'       => $this->users_service->is_disabled( $user->ID ) ? 'disabled' : 'active',
			);
			$entities[] = array(
				'position' => $position,
				'type'     => 'user',
				'id'       => $user->ID,
				'title'    => $user->display_name ? $user->display_name : $user->user_login,
			);
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

		if ( 'search' === $mode && '' !== $query ) {
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

	private function check_tool_capability( $ability, $identity ) {
		$user = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user'] : null;

		if ( ! $user ) {
			return new WP_Error( 'telepilot_ai_user_missing', __( 'A linked WordPress user is required.', 'wp-telepilot' ) );
		}

		switch ( $ability ) {
			case 'telepilot/get-site-info':
			case 'telepilot/get-site-health':
				return user_can( $user, 'read' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot access site information.', 'wp-telepilot' ) );

			case 'telepilot/get-recent-alerts':
				return user_can( $user, 'manage_options' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot access recent alerts.', 'wp-telepilot' ) );

			case 'telepilot/search-posts':
			case 'telepilot/get-post':
				return user_can( $user, 'edit_posts' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot read posts through AI mode.', 'wp-telepilot' ) );

			case 'telepilot/list-pages':
			case 'telepilot/get-page':
				return user_can( $user, 'edit_pages' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot read pages through AI mode.', 'wp-telepilot' ) );

			case 'telepilot/list-comments':
				return user_can( $user, 'moderate_comments' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot read comments through AI mode.', 'wp-telepilot' ) );

			case 'telepilot/list-users':
				return user_can( $user, 'list_users' ) ? true : new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot read users through AI mode.', 'wp-telepilot' ) );

			case 'telepilot/list-plugins':
			case 'telepilot/list-updates':
				return ( user_can( $user, 'activate_plugins' ) || user_can( $user, 'update_plugins' ) || user_can( $user, 'delete_plugins' ) )
					? true
					: new WP_Error( 'telepilot_ai_capability_denied', __( 'That account cannot read plugin management data through AI mode.', 'wp-telepilot' ) );
		}

		return new WP_Error( 'telepilot_ai_capability_unknown', __( 'That AI capability is not mapped yet.', 'wp-telepilot' ) );
	}
}
