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
			return $heading . "\n" . __( 'No posts matched that request.', 'wp-telepilot' );
		}

		$lines   = array( $heading );
		foreach ( $posts as $post ) {
			$lines[] = sprintf(
				__( '[%1$d] %2$s [%3$s]', 'wp-telepilot' ),
				$post->ID,
				html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$post->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'posts', $heading ) ) . "\n\n" . __( 'No posts matched that request.', 'wp-telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'posts', $heading ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d | %3$d items', 'wp-telepilot' ), $result['page'], $result['total_pages'], isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $result['items'] ) )
		);

		foreach ( $result['items'] as $post ) {
			$blocks[] = $this->format_post_summary_block( $post );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use Open Editor for long-form body changes, or run /posts search keyword for a targeted lookup.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_stats_message( $stats ) {
		return implode(
			"\n",
			array(
				__( 'Post Stats', 'wp-telepilot' ),
				sprintf( __( 'Published: %d', 'wp-telepilot' ), $stats['publish'] ),
				sprintf( __( 'Drafts: %d', 'wp-telepilot' ), $stats['draft'] ),
				sprintf( __( 'Pending: %d', 'wp-telepilot' ), $stats['pending'] ),
				sprintf( __( 'Scheduled: %d', 'wp-telepilot' ), $stats['future'] ),
				sprintf( __( 'Trash: %d', 'wp-telepilot' ), $stats['trash'] ),
			)
		);
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'posts', __( 'Posts Commands', 'wp-telepilot' ) ) ),
				Telepilot_Telegram_Response_Builder::code( '/posts new' ) . ' ' . __( 'Start a guided draft creation flow', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts list' ) . ' ' . __( 'Show recent posts', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts drafts' ) . ' ' . __( 'Show draft posts', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts search keyword' ) . ' ' . __( 'Search posts', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts stats' ) . ' ' . __( 'Show post counts', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts create My draft title' ) . ' ' . __( 'Create a new draft post', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts title 123 New title' ) . ' ' . __( 'Update a post title', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts excerpt 123 New excerpt' ) . ' ' . __( 'Update a post excerpt', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts categories 123' ) . ' ' . __( 'Open a category checklist for a post', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts categories 123 4,8' ) . ' ' . __( 'Assign category IDs to a post directly', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts tags 123 5,9' ) . ' ' . __( 'Assign tag IDs to a post', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts schedule 123 2026-07-20 14:30' ) . ' ' . __( 'Schedule a post in site local time', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts open 123' ) . ' ' . __( 'Generate a secure browser editor link for long-form changes', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts publish 123' ) . ' ' . __( 'Publish a post', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts draft 123' ) . ' ' . __( 'Move a published post back to draft', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts trashed' ) . ' ' . __( 'List trashed posts', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts restore 123' ) . ' ' . __( 'Restore a trashed post', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts trash 123' ) . ' ' . __( 'Move a post to trash', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/posts delete 123' ) . ' ' . __( 'Permanently delete a post after confirmation', 'wp-telepilot' ),
			)
		);
	}

	public function build_list_keyboard( $posts, $subcommand = 'latest', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = $this->build_hub_rows();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( 'trash' === $post->post_status || 'trashed' === $subcommand ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'restore', sprintf( __( 'Restore [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts restore ' . (int) $post->ID,
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( __( 'Delete [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts delete ' . (int) $post->ID,
					),
				);
				continue;
			}

			$rows[] = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'edit', sprintf( __( 'Open Editor [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts open ' . (int) $post->ID,
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'categories', sprintf( __( 'Categories [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts categories ' . (int) $post->ID,
				),
			);

			if ( 'publish' === $post->post_status ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'draft', sprintf( __( 'Draft [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts draft ' . (int) $post->ID,
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( __( 'Trash [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts trash ' . (int) $post->ID,
					),
				);
				continue;
			}

			$rows[] = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'publish', sprintf( __( 'Publish [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts publish ' . (int) $post->ID,
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( __( 'Trash [%d]', 'wp-telepilot' ), $post->ID ) ),
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

	public function build_hub_keyboard() {
		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $this->build_hub_rows() ),
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
				'text'          => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ),
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

	private function format_post_summary_block( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$title   = get_the_title( $post );
		$title   = '' !== (string) $title ? $title : __( 'Untitled post', 'wp-telepilot' );
		$author  = get_the_author_meta( 'display_name', (int) $post->post_author );
		$author  = '' !== (string) $author ? $author : get_the_author_meta( 'user_login', (int) $post->post_author );
		$author  = '' !== (string) $author ? $author : __( 'Unknown author', 'wp-telepilot' );

		$lines = array(
			Telepilot_Telegram_Response_Builder::label(
				'posts',
				sprintf(
					__( '[%1$d] %2$s', 'wp-telepilot' ),
					$post->ID,
					Telepilot_Telegram_Response_Builder::escape( $title )
				)
			),
			sprintf( __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->humanize_status( $post->post_status ) ) ),
			sprintf( __( 'Author: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $author ) ),
			sprintf( __( 'Date: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_post_time( 'Y-m-d H:i:s', false, $post, true ) ) ),
			sprintf( __( 'Updated: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_post_modified_time( 'Y-m-d H:i:s', false, $post, true ) ) ),
		);

		return implode( "\n", $lines );
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
		return $this->update_status( $post_id, 'publish', __( 'published', 'wp-telepilot' ) );
	}

	public function unpublish( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
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
			'label'         => __( 'moved back to draft', 'wp-telepilot' ),
		);
	}

	public function create_draft( $title, $category_ids = array() ) {
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

		$category_ids = array_values( array_filter( array_map( 'absint', (array) $category_ids ) ) );
		if ( ! empty( $category_ids ) ) {
			$assigned_categories = wp_set_post_terms( $post_id, $category_ids, 'category', false );

			if ( is_wp_error( $assigned_categories ) ) {
				wp_delete_post( $post_id, true );

				return $assigned_categories;
			}
		}

		$this->bump_cache_version();

		return array(
			'post'        => get_post( $post_id ),
			'after_state' => array(
				'category' => $this->get_assigned_term_ids( $post_id, 'category' ),
			),
			'label'       => __( 'created as draft', 'wp-telepilot' ),
		);
	}

	public function update_title( $post_id, $title ) {
		return $this->update_fields(
			$post_id,
			array( 'post_title' => sanitize_text_field( $title ) ),
			__( 'title updated', 'wp-telepilot' )
		);
	}

	public function update_excerpt( $post_id, $excerpt ) {
		return $this->update_fields(
			$post_id,
			array( 'post_excerpt' => sanitize_textarea_field( $excerpt ) ),
			__( 'excerpt updated', 'wp-telepilot' )
		);
	}

	public function assign_terms( $post_id, $taxonomy, $term_ids ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
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
			'label'        => 'category' === $taxonomy ? __( 'categories updated', 'wp-telepilot' ) : __( 'tags updated', 'wp-telepilot' ),
		);
	}

	public function get_assigned_term_ids( $post_id, $taxonomy ) {
		$taxonomy = 'post_tag' === $taxonomy ? 'post_tag' : 'category';
		$term_ids = wp_get_post_terms( absint( $post_id ), $taxonomy, array( 'fields' => 'ids' ) );

		return array_values( array_filter( array_map( 'absint', is_array( $term_ids ) ? $term_ids : array() ) ) );
	}

	public function render_creation_prompt_message( $category_term = null ) {
		$blocks   = array();
		$blocks[] = Telepilot_Telegram_Response_Builder::bold( __( 'New Post', 'wp-telepilot' ) );

		if ( $category_term instanceof WP_Term ) {
			$blocks[] = sprintf(
				__( 'Send the post title now to create a draft in category [%1$d] %2$s.', 'wp-telepilot' ),
				$category_term->term_id,
				Telepilot_Telegram_Response_Builder::escape( $category_term->name )
			);
		} else {
			$blocks[] = __( 'Send the post title now to create a new draft.', 'wp-telepilot' );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'After the draft is created, WP Telepilot will show a category checklist so you can refine the assignment before publishing.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_post_created_message( $post, $category_ids = array() ) {
		if ( ! $post instanceof WP_Post ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Post Created', 'wp-telepilot' ) );
		}

		$title   = get_the_title( $post );
		$title   = '' !== (string) $title ? $title : __( 'Untitled post', 'wp-telepilot' );
		$summary = $this->format_selected_categories_summary( $category_ids );

		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Post Created', 'wp-telepilot' ) ),
				implode(
					"\n",
					array(
						sprintf( __( 'Post: [%1$d] %2$s', 'wp-telepilot' ), $post->ID, Telepilot_Telegram_Response_Builder::escape( $title ) ),
						sprintf( __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->humanize_status( $post->post_status ) ) ),
						sprintf( __( 'Categories: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary ) ),
					)
				),
				Telepilot_Telegram_Response_Builder::italic( __( 'Next steps: edit the body, adjust categories, or publish when you are ready.', 'wp-telepilot' ) ),
			)
		);
	}

	public function render_category_picker_message( $post, $categories_result, $selected_ids ) {
		if ( ! $post instanceof WP_Post ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Post Categories', 'wp-telepilot' ) );
		}

		$title   = get_the_title( $post );
		$title   = '' !== (string) $title ? $title : __( 'Untitled post', 'wp-telepilot' );
		$summary = $this->format_selected_categories_summary( $selected_ids );

		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Select Post Categories', 'wp-telepilot' ) ),
				implode(
					"\n",
					array(
						sprintf( __( 'Post: [%1$d] %2$s', 'wp-telepilot' ), $post->ID, Telepilot_Telegram_Response_Builder::escape( $title ) ),
						sprintf( __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->humanize_status( $post->post_status ) ) ),
						sprintf(
							__( 'Page %1$d of %2$d', 'wp-telepilot' ),
							isset( $categories_result['page'] ) ? (int) $categories_result['page'] : 1,
							isset( $categories_result['total_pages'] ) ? (int) $categories_result['total_pages'] : 1
						),
						sprintf( __( 'Selected: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary ) ),
					)
				),
				Telepilot_Telegram_Response_Builder::italic( __( 'Toggle the categories below, then tap Done to save the selection to this post.', 'wp-telepilot' ) ),
			)
		);
	}

	public function render_post_categories_saved_message( $post, $category_ids = array() ) {
		if ( ! $post instanceof WP_Post ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Post Categories Saved', 'wp-telepilot' ) );
		}

		$title   = get_the_title( $post );
		$title   = '' !== (string) $title ? $title : __( 'Untitled post', 'wp-telepilot' );
		$summary = $this->format_selected_categories_summary( $category_ids );

		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Post Categories Saved', 'wp-telepilot' ) ),
				implode(
					"\n",
					array(
						sprintf( __( 'Post: [%1$d] %2$s', 'wp-telepilot' ), $post->ID, Telepilot_Telegram_Response_Builder::escape( $title ) ),
						sprintf( __( 'Categories: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary ) ),
					)
				),
				Telepilot_Telegram_Response_Builder::italic( __( 'Continue with the editor, keep refining the draft, or publish when ready.', 'wp-telepilot' ) ),
			)
		);
	}

	public function build_category_picker_keyboard( $state_token, $categories_result, $selected_ids, $post ) {
		$rows         = array();
		$page         = isset( $categories_result['page'] ) ? max( 1, (int) $categories_result['page'] ) : 1;
		$selected_map = array_fill_keys( array_map( 'absint', (array) $selected_ids ), true );

		foreach ( $categories_result['items'] as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$is_selected = isset( $selected_map[ (int) $term->term_id ] );
			$rows[]      = array(
				array(
					'text'          => sprintf(
						'%1$s [%2$d] %3$s',
						$is_selected ? '☑' : '☐',
						(int) $term->term_id,
						$term->name
					),
					'callback_data' => sprintf( 'tp:postflow:togglecat:%1$s:%2$d:%3$d', $state_token, (int) $term->term_id, $page ),
				),
			);
		}

		if ( ! empty( $categories_result['total_pages'] ) && (int) $categories_result['total_pages'] > 1 ) {
			$pagination = array();

			if ( $page > 1 ) {
				$pagination[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ),
					'callback_data' => sprintf( 'tp:postflow:pagecat:%1$s:%2$d', $state_token, $page - 1 ),
				);
			}

			if ( $page < (int) $categories_result['total_pages'] ) {
				$pagination[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ),
					'callback_data' => sprintf( 'tp:postflow:pagecat:%1$s:%2$d', $state_token, $page + 1 ),
				);
			}

			if ( ! empty( $pagination ) ) {
				$rows[] = $pagination;
			}
		}

		if ( $post instanceof WP_Post ) {
			$rows[] = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::button_label( 'confirm', sprintf( __( 'Done [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => sprintf( 'tp:postflow:applycat:%s', $state_token ),
				),
				array(
					'text'          => __( 'Clear', 'wp-telepilot' ),
					'callback_data' => sprintf( 'tp:postflow:clearcat:%1$s:%2$d', $state_token, $page ),
				),
			);
			$rows[] = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::button_label( 'cancel', __( 'Cancel', 'wp-telepilot' ) ),
					'callback_data' => sprintf( 'tp:postflow:cancelcat:%s', $state_token ),
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'edit', sprintf( __( 'Open Editor [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts open ' . (int) $post->ID,
				),
			);
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	public function build_post_followup_keyboard( $post ) {
		$rows = array();

		if ( $post instanceof WP_Post ) {
			$rows[] = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'categories', sprintf( __( 'Categories [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts categories ' . (int) $post->ID,
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'edit', sprintf( __( 'Open Editor [%d]', 'wp-telepilot' ), $post->ID ) ),
					'callback_data' => '/posts open ' . (int) $post->ID,
				),
			);

			if ( 'publish' === $post->post_status ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'draft', sprintf( __( 'Draft [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts draft ' . (int) $post->ID,
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( __( 'Trash [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts trash ' . (int) $post->ID,
					),
				);
			} else {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'publish', sprintf( __( 'Publish [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts publish ' . (int) $post->ID,
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( __( 'Trash [%d]', 'wp-telepilot' ), $post->ID ) ),
						'callback_data' => '/posts trash ' . (int) $post->ID,
					),
				);
			}
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			array_merge( $this->build_hub_rows(), $this->navigation_rows() )
		);
	}

	public function schedule( $post_id, DateTimeImmutable $scheduled_at ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
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
			'label'         => __( 'scheduled', 'wp-telepilot' ),
			'scheduled_at'  => $scheduled_at->format( 'Y-m-d H:i:s' ),
		);
	}

	public function trash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_trash_post( $post_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_post_trash_failed', __( 'WordPress could not trash that post.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'trashed', 'wp-telepilot' ),
		);
	}

	public function restore( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_untrash_post( $post_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_post_restore_failed', __( 'WordPress could not restore that post.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'restored', 'wp-telepilot' ),
		);
	}

	public function delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
		}

		$before_state = array(
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
		);

		$result = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepilot_post_delete_failed', __( 'WordPress could not delete that post.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'wp-telepilot' ),
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

		$action_label = 'unpublish' === $action ? __( 'Draft', 'wp-telepilot' ) : ucfirst( $action );

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$d]', 'wp-telepilot' ), $action_label, $post_id ),
				'tp:post:' . $action . ':' . (int) $post_id . ':' . $token,
				'/posts list'
			),
			$this->navigation_rows()
		);
	}

	private function update_status( $post_id, $status, $label ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
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
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
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

	private function humanize_status( $status ) {
		$status = str_replace( array( '-', '_' ), ' ', sanitize_key( (string) $status ) );

		return '' !== $status ? ucwords( $status ) : __( 'Unknown', 'wp-telepilot' );
	}

	private function build_hub_rows() {
		return array(
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', __( 'New Post', 'wp-telepilot' ) ),
					'callback_data' => '/posts new',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', __( 'Latest', 'wp-telepilot' ) ),
					'callback_data' => '/posts list',
				),
			),
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'draft', __( 'Drafts', 'wp-telepilot' ) ),
					'callback_data' => '/posts drafts',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'stats', __( 'Stats', 'wp-telepilot' ) ),
					'callback_data' => '/posts stats',
				),
			),
		);
	}

	private function format_selected_categories_summary( $category_ids ) {
		$category_ids = array_values( array_filter( array_map( 'absint', (array) $category_ids ) ) );
		if ( empty( $category_ids ) ) {
			return __( 'None selected', 'wp-telepilot' );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'include'    => $category_ids,
				'orderby'    => 'include',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return implode( ', ', array_map( 'strval', $category_ids ) );
		}

		$labels = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$labels[] = sprintf( '[%1$d] %2$s', $term->term_id, $term->name );
		}

		if ( empty( $labels ) ) {
			return __( 'None selected', 'wp-telepilot' );
		}

		if ( count( $labels ) > 4 ) {
			$visible = array_slice( $labels, 0, 4 );

			return sprintf(
				/* translators: 1: visible category list, 2: number of additional categories. */
				__( '%1$s and %2$d more', 'wp-telepilot' ),
				implode( ', ', $visible ),
				count( $labels ) - 4
			);
		}

		return implode( ', ', $labels );
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Site', 'wp-telepilot' ) ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
