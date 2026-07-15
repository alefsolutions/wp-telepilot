<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Pages_Service {
	private $confirmation_service;
	const PER_PAGE = 5;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function latest( $limit = 5 ) {
		return get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => max( 1, absint( $limit ) ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	public function latest_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_pages_page(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
			),
			$page,
			$limit
		);
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_pages_page(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				's'           => sanitize_text_field( $term ),
			),
			$page,
			$limit
		);
	}

	public function drafts_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_pages_page(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'draft' ),
			),
			$page,
			$limit
		);
	}

	public function trashed_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_pages_page(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'trash' ),
			),
			$page,
			$limit
		);
	}

	public function render_list_message( $pages, $heading ) {
		if ( empty( $pages ) ) {
			return $heading . "\n" . __( 'No pages matched that request.', 'telepilot' );
		}

		$lines = array( $heading );
		foreach ( $pages as $page ) {
			$lines[] = sprintf(
				__( '[%1$d] %2$s [%3$s]', 'telepilot' ),
				$page->ID,
				html_entity_decode( get_the_title( $page ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$page->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No pages matched that request.', 'telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $page ) {
			$block_lines = array(
				sprintf(
					__( '[%1$d] %2$s [%3$s]', 'telepilot' ),
					$page->ID,
					Telepilot_Telegram_Response_Builder::escape( get_the_title( $page ) ),
					Telepilot_Telegram_Response_Builder::escape( $page->post_status )
				),
			);

			$access_link = $this->describe_access_link( $page );
			if ( '' !== $access_link ) {
				$block_lines[] = $access_link;
			}

			$blocks[] = implode( "\n", $block_lines );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use the buttons below, or run /pages search keyword to narrow the list.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Pages Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/pages list' ) . ' ' . __( 'Show recent pages', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages latest' ) . ' ' . __( 'Alias for the recent pages list', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages drafts' ) . ' ' . __( 'Show draft pages only', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages search about' ) . ' ' . __( 'Search pages', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages details 123' ) . ' ' . __( 'Show page details and browser access links', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages title 123 New title' ) . ' ' . __( 'Update a page title', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages slug 123 about-us' ) . ' ' . __( 'Update a page slug', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages status 123 private' ) . ' ' . __( 'Set page status to draft, publish, or private', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages trashed' ) . ' ' . __( 'Show trashed pages', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages publish 123' ) . ' ' . __( 'Publish a page', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages draft 123' ) . ' ' . __( 'Move a page to draft', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages trash 123' ) . ' ' . __( 'Trash a page', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages restore 123' ) . ' ' . __( 'Restore a trashed page', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages delete 123' ) . ' ' . __( 'Permanently delete a page after confirmation', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Published pages can be previewed directly from Telegram. Drafts are linked to wp-admin because browser preview for drafts normally requires WordPress authentication.', 'telepilot' ) ),
			)
		);
	}

	public function get_page_details( $page_id ) {
		$page = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		return $page;
	}

	public function render_details_message( $page ) {
		if ( ! ( $page instanceof WP_Post ) ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Page Details', 'telepilot' ) ) . "\n\n" . __( 'Page not found.', 'telepilot' );
		}

		$blocks   = array();
		$blocks[] = Telepilot_Telegram_Response_Builder::bold( __( 'Page Details', 'telepilot' ) );
		$blocks[] = implode(
			"\n",
			array(
				sprintf( __( 'Page: [%1$d] %2$s', 'telepilot' ), $page->ID, Telepilot_Telegram_Response_Builder::escape( get_the_title( $page ) ) ),
				sprintf( __( 'Status: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $page->post_status ) ),
				sprintf( __( 'Slug: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $page->post_name ) ),
				sprintf( __( 'Modified: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_the_modified_date( 'Y-m-d H:i:s', $page ) ) ),
			)
		);
		$blocks[] = $this->describe_access_link( $page );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function create_page( $title ) {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $title ),
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		$this->bump_cache_version();

		return array(
			'post'  => get_post( $page_id ),
			'label' => __( 'created as draft', 'telepilot' ),
		);
	}

	public function update_title( $page_id, $title ) {
		return $this->update_fields(
			$page_id,
			array(
				'post_title' => sanitize_text_field( $title ),
			),
			__( 'title updated', 'telepilot' )
		);
	}

	public function update_slug( $page_id, $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'telepilot_page_invalid_slug', __( 'That page slug is invalid.', 'telepilot' ) );
		}

		return $this->update_fields(
			$page_id,
			array(
				'post_name' => $slug,
			),
			__( 'slug updated', 'telepilot' )
		);
	}

	public function set_status( $page_id, $status ) {
		$allowed_statuses = array(
			'draft'   => __( 'moved to draft', 'telepilot' ),
			'publish' => __( 'published', 'telepilot' ),
			'private' => __( 'made private', 'telepilot' ),
		);

		$status = sanitize_key( $status );
		if ( ! isset( $allowed_statuses[ $status ] ) ) {
			return new WP_Error( 'telepilot_page_invalid_status', __( 'That page status is not supported.', 'telepilot' ) );
		}

		return $this->update_status( $page_id, $status, $allowed_statuses[ $status ] );
	}

	public function publish( $page_id ) {
		return $this->update_status( $page_id, 'publish', __( 'published', 'telepilot' ) );
	}

	public function draft( $page_id ) {
		return $this->update_status( $page_id, 'draft', __( 'moved to draft', 'telepilot' ) );
	}

	public function trash( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_trash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_page_trash_failed', __( 'WordPress could not trash that page.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'trashed', 'telepilot' ),
		);
	}

	public function restore( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_untrash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_page_restore_failed', __( 'WordPress could not restore that page.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'restored', 'telepilot' ),
		);
	}

	public function delete( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		$before_state = array(
			'title'  => get_the_title( $page ),
			'status' => $page->post_status,
			'slug'   => (string) $page->post_name,
		);

		$result = wp_delete_post( $page_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepilot_page_delete_failed', __( 'WordPress could not delete that page.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'telepilot' ),
		);
	}

	public function build_action_confirmation_keyboard( $page_id, $action, $telegram_user_id ) {
		$token = $this->confirmation_service->create_token(
			array(
				'action'           => (string) $action,
				'page_id'          => (int) $page_id,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$d]', 'telepilot' ), ucfirst( $action ), $page_id ),
				'tp:page:' . $action . ':' . (int) $page_id . ':' . $token,
				'/pages list'
			),
			$this->navigation_rows()
		);
	}

	public function build_list_keyboard( $pages, $subcommand = 'list', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $pages as $page_item ) {
			if ( ! $page_item instanceof WP_Post ) {
				continue;
			}

			$row = array();

			if ( 'publish' === $page_item->post_status ) {
				$row[] = array(
					'text' => sprintf( __( 'Preview [%d]', 'telepilot' ), $page_item->ID ),
					'url'  => $this->get_preview_url( $page_item ),
				);
			} else {
				$row[] = array(
					'text' => sprintf( __( 'Edit [%d]', 'telepilot' ), $page_item->ID ),
					'url'  => $this->get_admin_edit_url( $page_item->ID ),
				);
			}

			if ( 'trash' === $page_item->post_status ) {
				$row[] = array(
					'text'          => sprintf( __( 'Restore [%d]', 'telepilot' ), $page_item->ID ),
					'callback_data' => '/pages restore ' . (int) $page_item->ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Delete [%d]', 'telepilot' ), $page_item->ID ),
					'callback_data' => '/pages delete ' . (int) $page_item->ID,
				);
			} else {
				if ( 'publish' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => sprintf( __( 'Publish [%d]', 'telepilot' ), $page_item->ID ),
						'callback_data' => '/pages publish ' . (int) $page_item->ID,
					);
				}

				if ( 'draft' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => sprintf( __( 'Draft [%d]', 'telepilot' ), $page_item->ID ),
						'callback_data' => '/pages draft ' . (int) $page_item->ID,
					);
				}

				$row[] = array(
					'text'          => sprintf( __( 'Trash [%d]', 'telepilot' ), $page_item->ID ),
					'callback_data' => '/pages trash ' . (int) $page_item->ID,
				);
			}

			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
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
			return trim( '/pages search ' . $search_term . ' page:' . $page );
		}

		if ( 'drafts' === $subcommand ) {
			return '/pages drafts page:' . $page;
		}

		if ( 'trashed' === $subcommand ) {
			return '/pages trashed page:' . $page;
		}

		return '/pages list page:' . $page;
	}

	private function describe_access_link( $page ) {
		if ( ! $page instanceof WP_Post ) {
			return '';
		}

		if ( 'publish' === $page->post_status ) {
			$url = $this->get_preview_url( $page );
			if ( '' !== $url ) {
				return __( 'Preview:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Open in browser', 'telepilot' ), $url );
			}
		}

		return __( 'Edit:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Open in wp-admin', 'telepilot' ), $this->get_admin_edit_url( $page->ID ) );
	}

	private function get_preview_url( $page ) {
		$url = get_permalink( $page );

		return $url ? (string) $url : '';
	}

	private function get_admin_edit_url( $page_id ) {
		return admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' );
	}

	private function update_status( $page_id, $status, $label ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => $label,
		);
	}

	private function update_fields( $page_id, $fields, $label ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'telepilot' ) );
		}

		$before_state = array(
			'post_title' => (string) $page->post_title,
			'post_name'  => (string) $page->post_name,
			'post_status'=> (string) $page->post_status,
		);

		$result = wp_update_post(
			array_merge(
				array(
					'ID' => $page_id,
				),
				$fields
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $page_id );
		$this->bump_cache_version();

		return array(
			'post'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'post_title'  => (string) $updated->post_title,
				'post_name'   => (string) $updated->post_name,
				'post_status' => (string) $updated->post_status,
			),
			'label'        => $label,
		);
	}

	private function query_pages_page( $args, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_pages_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
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

	private function bump_cache_version() {
		update_option( 'telepilot_pages_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_pages_cache_version', 1 ) );
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
