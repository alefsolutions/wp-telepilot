<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Posts_Service {
	private $confirmation_service;
	const PER_PAGE = 5;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function latest( $limit = 5 ) {
		return get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page'      => max( 1, absint( $limit ) ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
	}

	public function latest_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_posts_page(
			array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future' ),
				'ignore_sticky_posts' => true,
			),
			$page,
			$limit
		);
	}

	public function drafts( $limit = 5 ) {
		return get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'draft',
				'posts_per_page'      => max( 1, absint( $limit ) ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
	}

	public function drafts_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_posts_page(
			array(
				'post_type'           => 'post',
				'post_status'         => 'draft',
				'ignore_sticky_posts' => true,
			),
			$page,
			$limit
		);
	}

	public function trashed_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_posts_page(
			array(
				'post_type'           => 'post',
				'post_status'         => 'trash',
				'ignore_sticky_posts' => true,
			),
			$page,
			$limit
		);
	}

	public function search( $term, $limit = 5 ) {
		return get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future' ),
				's'                   => sanitize_text_field( $term ),
				'posts_per_page'      => max( 1, absint( $limit ) ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
			)
		);
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_posts_page(
			array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future' ),
				's'                   => sanitize_text_field( $term ),
				'ignore_sticky_posts' => true,
			),
			$page,
			$limit
		);
	}

	public function stats() {
		$counts = wp_count_posts( 'post' );

		return array(
			'publish' => isset( $counts->publish ) ? (int) $counts->publish : 0,
			'draft'   => isset( $counts->draft ) ? (int) $counts->draft : 0,
			'pending' => isset( $counts->pending ) ? (int) $counts->pending : 0,
			'future'  => isset( $counts->future ) ? (int) $counts->future : 0,
			'trash'   => isset( $counts->trash ) ? (int) $counts->trash : 0,
		);
	}

	public function render_list_message( $posts, $heading ) {
		if ( empty( $posts ) ) {
			return $heading . "\n" . __( 'No posts matched that request.', 'telepilot' );
		}

		$lines   = array( $heading );
		foreach ( $posts as $post ) {
			$lines[] = sprintf(
				__( '[%1$d] %2$s [%3$s]', 'telepilot' ),
				$post->ID,
				html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$post->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No posts matched that request.', 'telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $post ) {
			$blocks[] = sprintf(
				__( '[%1$d] %2$s [%3$s]', 'telepilot' ),
				$post->ID,
				Telepilot_Telegram_Response_Builder::escape( get_the_title( $post ) ),
				Telepilot_Telegram_Response_Builder::escape( $post->post_status )
			);
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use Open Editor for long-form body changes, or run /posts search keyword for a targeted lookup.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_stats_message( $stats ) {
		return implode(
			"\n",
			array(
				__( 'Post Stats', 'telepilot' ),
				sprintf( __( 'Published: %d', 'telepilot' ), $stats['publish'] ),
				sprintf( __( 'Drafts: %d', 'telepilot' ), $stats['draft'] ),
				sprintf( __( 'Pending: %d', 'telepilot' ), $stats['pending'] ),
				sprintf( __( 'Scheduled: %d', 'telepilot' ), $stats['future'] ),
				sprintf( __( 'Trash: %d', 'telepilot' ), $stats['trash'] ),
			)
		);
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Posts Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/posts list' ) . ' ' . __( 'Show recent posts', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts drafts' ) . ' ' . __( 'Show draft posts', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts search keyword' ) . ' ' . __( 'Search posts', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts stats' ) . ' ' . __( 'Show post counts', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts create My draft title' ) . ' ' . __( 'Create a new draft post', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts title 123 New title' ) . ' ' . __( 'Update a post title', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts excerpt 123 New excerpt' ) . ' ' . __( 'Update a post excerpt', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts categories 123 4,8' ) . ' ' . __( 'Assign category IDs to a post', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts tags 123 5,9' ) . ' ' . __( 'Assign tag IDs to a post', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts schedule 123 2026-07-20 14:30' ) . ' ' . __( 'Schedule a post in site local time', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts open 123' ) . ' ' . __( 'Generate a secure browser editor link for long-form changes', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts publish 123' ) . ' ' . __( 'Publish a post', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts draft 123' ) . ' ' . __( 'Move a published post back to draft', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts trashed' ) . ' ' . __( 'List trashed posts', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts restore 123' ) . ' ' . __( 'Restore a trashed post', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts trash 123' ) . ' ' . __( 'Move a post to trash', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts delete 123' ) . ' ' . __( 'Permanently delete a post after confirmation', 'telepilot' ),
			)
		);
	}

	public function build_list_keyboard( $posts, $subcommand = 'latest', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( 'trash' === $post->post_status || 'trashed' === $subcommand ) {
				$rows[] = array(
					array(
						'text'          => sprintf( __( 'Restore [%d]', 'telepilot' ), $post->ID ),
						'callback_data' => '/posts restore ' . (int) $post->ID,
					),
					array(
						'text'          => sprintf( __( 'Delete [%d]', 'telepilot' ), $post->ID ),
						'callback_data' => '/posts delete ' . (int) $post->ID,
					),
				);
				continue;
			}

			if ( 'publish' === $post->post_status ) {
				$rows[] = array(
					array(
						'text'          => sprintf( __( 'Open Editor [%d]', 'telepilot' ), $post->ID ),
						'callback_data' => '/posts open ' . (int) $post->ID,
					),
					array(
						'text'          => sprintf( __( 'Draft [%d]', 'telepilot' ), $post->ID ),
						'callback_data' => '/posts draft ' . (int) $post->ID,
					),
					array(
						'text'          => sprintf( __( 'Trash [%d]', 'telepilot' ), $post->ID ),
						'callback_data' => '/posts trash ' . (int) $post->ID,
					),
				);
				continue;
			}

			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Open Editor [%d]', 'telepilot' ), $post->ID ),
					'callback_data' => '/posts open ' . (int) $post->ID,
				),
				array(
					'text'          => sprintf( __( 'Publish [%d]', 'telepilot' ), $post->ID ),
					'callback_data' => '/posts publish ' . (int) $post->ID,
				),
				array(
					'text'          => sprintf( __( 'Trash [%d]', 'telepilot' ), $post->ID ),
					'callback_data' => '/posts trash ' . (int) $post->ID,
				),
			);
		}

		$pagination = $this->build_pagination_row( $subcommand, $search_term, $page, $total_pages );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	private function build_pagination_row( $subcommand, $search_term, $page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return array();
		}

		$buttons = array();

		if ( $page > 1 ) {
			$buttons[] = array(
				'text'          => __( 'Prev', 'telepilot' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepilot' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page + 1 ),
			);
		}

		return $buttons;
	}

	private function build_command( $subcommand, $search_term, $page ) {
		if ( 'search' === $subcommand ) {
			return trim( '/posts search ' . $search_term . ' page:' . $page );
		}

		if ( 'trashed' === $subcommand ) {
			return '/posts trashed page:' . $page;
		}

		return '/posts ' . $subcommand . ' page:' . $page;
	}

	private function query_posts_page( $args, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_posts_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_Query(
			array_merge(
				$args,
				array(
					'posts_per_page'         => $limit,
					'paged'                  => $page,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'suppress_filters'       => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);

		$result = array(
			'items'       => $query->posts,
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => (int) $query->found_posts,
			'total_pages' => max( 1, (int) $query->max_num_pages ),
		);

		set_transient( $cache_key, $result, 30 );

		return $result;
	}

	public function publish( $post_id ) {
		return $this->update_status( $post_id, 'publish', __( 'published', 'telepilot' ) );
	}

	public function unpublish( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'moved back to draft', 'telepilot' ),
		);
	}

	public function create_draft( $title ) {
		$title  = sanitize_text_field( $title );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->bump_cache_version();

		return array(
			'post'  => get_post( $post_id ),
			'label' => __( 'created as draft', 'telepilot' ),
		);
	}

	public function update_title( $post_id, $title ) {
		return $this->update_fields(
			$post_id,
			array( 'post_title' => sanitize_text_field( $title ) ),
			__( 'title updated', 'telepilot' )
		);
	}

	public function update_excerpt( $post_id, $excerpt ) {
		return $this->update_fields(
			$post_id,
			array( 'post_excerpt' => sanitize_textarea_field( $excerpt ) ),
			__( 'excerpt updated', 'telepilot' )
		);
	}

	public function assign_terms( $post_id, $taxonomy, $term_ids ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$taxonomy  = 'post_tag' === $taxonomy ? 'post_tag' : 'category';
		$term_ids  = array_values( array_filter( array_map( 'absint', (array) $term_ids ) ) );
		$before_ids = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		$result = wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return array(
			'post'         => get_post( $post_id ),
			'before_state' => array( $taxonomy => array_map( 'absint', (array) $before_ids ) ),
			'after_state'  => array( $taxonomy => array_map( 'absint', (array) $result ) ),
			'label'        => 'category' === $taxonomy ? __( 'categories updated', 'telepilot' ) : __( 'tags updated', 'telepilot' ),
		);
	}

	public function schedule( $post_id, DateTimeImmutable $scheduled_at ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$gmt = $scheduled_at->setTimezone( new DateTimeZone( 'UTC' ) );
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $scheduled_at->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $gmt->format( 'Y-m-d H:i:s' ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $post->post_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'scheduled', 'telepilot' ),
			'scheduled_at'  => $scheduled_at->format( 'Y-m-d H:i:s' ),
		);
	}

	public function trash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_trash_post( $post_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_post_trash_failed', __( 'WordPress could not trash that post.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'trashed', 'telepilot' ),
		);
	}

	public function restore( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_untrash_post( $post_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_post_restore_failed', __( 'WordPress could not restore that post.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'restored', 'telepilot' ),
		);
	}

	public function delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_state = array(
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
		);

		$result = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepilot_post_delete_failed', __( 'WordPress could not delete that post.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'telepilot' ),
		);
	}

	public function build_action_confirmation_keyboard( $post_id, $action, $telegram_user_id ) {
		$token = $this->confirmation_service->create_token(
			array(
				'action'           => (string) $action,
				'post_id'          => (int) $post_id,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		$action_label = 'unpublish' === $action ? __( 'Draft', 'telepilot' ) : ucfirst( $action );

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$d]', 'telepilot' ), $action_label, $post_id ),
				'tp:post:' . $action . ':' . (int) $post_id . ':' . $token,
				'/posts list'
			),
			$this->navigation_rows()
		);
	}

	private function update_status( $post_id, $status, $label ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => $label,
		);
	}

	private function update_fields( $post_id, $fields, $label ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'telepilot' ) );
		}

		$before_state = array(
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => (string) $post->post_excerpt,
		);

		$result = wp_update_post(
			array_merge(
				array(
					'ID' => $post_id,
				),
				$fields
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $post_id );
		$this->bump_cache_version();

		return array(
			'post'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'post_title'   => (string) $updated->post_title,
				'post_excerpt' => (string) $updated->post_excerpt,
			),
			'label'        => $label,
		);
	}

	private function bump_cache_version() {
		update_option( 'telepilot_posts_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_posts_cache_version', 1 ) );
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepilot' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepilot' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
