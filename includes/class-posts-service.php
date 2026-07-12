<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Posts_Service {
	private $confirmation_service;
	const PER_PAGE = 5;

	public function __construct( TelePress_Confirmation_Service $confirmation_service ) {
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
			return $heading . "\n" . __( 'No posts matched that request.', 'telepress' );
		}

		$lines   = array( $heading );
		foreach ( $posts as $post ) {
			$lines[] = sprintf(
				__( '#%1$d %2$s [%3$s]', 'telepress' ),
				$post->ID,
				html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$post->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return TelePress_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No posts matched that request.', 'telepress' );
		}

		$lines   = array( TelePress_Telegram_Response_Builder::bold( $heading ) );
		$lines[] = TelePress_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepress' ), $result['page'], $result['total_pages'] )
		);
		$lines[] = '';

		foreach ( $result['items'] as $post ) {
			$lines[] = sprintf(
				__( '- #%1$d %2$s [%3$s]', 'telepress' ),
				$post->ID,
				TelePress_Telegram_Response_Builder::escape( get_the_title( $post ) ),
				TelePress_Telegram_Response_Builder::escape( $post->post_status )
			);
		}

		$lines[] = '';
		$lines[] = TelePress_Telegram_Response_Builder::italic( __( 'Tip: use the buttons below, or run /posts search keyword for a targeted lookup.', 'telepress' ) );

		return implode( "\n", $lines );
	}

	public function render_stats_message( $stats ) {
		return implode(
			"\n",
			array(
				__( 'Post Stats', 'telepress' ),
				sprintf( __( 'Published: %d', 'telepress' ), $stats['publish'] ),
				sprintf( __( 'Drafts: %d', 'telepress' ), $stats['draft'] ),
				sprintf( __( 'Pending: %d', 'telepress' ), $stats['pending'] ),
				sprintf( __( 'Scheduled: %d', 'telepress' ), $stats['future'] ),
				sprintf( __( 'Trash: %d', 'telepress' ), $stats['trash'] ),
			)
		);
	}

	public function build_list_keyboard( $posts, $subcommand = 'latest', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( 'publish' === $post->post_status ) {
				$rows[] = array(
					array(
						'text'          => sprintf( __( 'Unpublish #%d', 'telepress' ), $post->ID ),
						'callback_data' => '/posts unpublish ' . (int) $post->ID,
					),
				);
				continue;
			}

			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Publish #%d', 'telepress' ), $post->ID ),
					'callback_data' => '/posts publish ' . (int) $post->ID,
				),
			);
		}

		$pagination = $this->build_pagination_row( $subcommand, $search_term, $page, $total_pages );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard( $rows ),
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
				'text'          => __( 'Prev', 'telepress' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepress' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page + 1 ),
			);
		}

		return $buttons;
	}

	private function build_command( $subcommand, $search_term, $page ) {
		if ( 'search' === $subcommand ) {
			return trim( '/posts search ' . $search_term . ' page:' . $page );
		}

		return '/posts ' . $subcommand . ' page:' . $page;
	}

	private function query_posts_page( $args, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepress_posts_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
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
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepress_post_not_found', __( 'Post not found.', 'telepress' ) );
		}

		$before_status = $post->post_status;
		$result        = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'published', 'telepress' ),
		);
	}

	public function unpublish( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepress_post_not_found', __( 'Post not found.', 'telepress' ) );
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

		return array(
			'post'          => get_post( $post_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $post_id ),
			'label'         => __( 'moved back to draft', 'telepress' ),
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

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm %1$s #%2$d', 'telepress' ), ucfirst( $action ), $post_id ),
							'callback_data' => 'tp:post:' . $action . ':' . (int) $post_id . ':' . $token,
						),
					),
				)
			),
			$this->navigation_rows()
		);
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepress' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepress' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
