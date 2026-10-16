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
		return Telepilot_Domain_Abilities::run( 'pages', 'latest', get_defined_vars(), function () use ( $limit ) {
			return $this->operation_latest( $limit );
		} );
	}

	private function operation_latest( $limit = 5 ) {
		$result = $this->latest_page( 1, $limit );
		return is_wp_error( $result ) ? $result : $result['items'];
	}

	public function latest_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'latest_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_latest_page( $page, $limit );
		} );
	}

	private function operation_latest_page( $page = 1, $limit = self::PER_PAGE ) {
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
		return Telepilot_Domain_Abilities::run( 'pages', 'search_page', get_defined_vars(), function () use ( $term, $page, $limit ) {
			return $this->operation_search_page( $term, $page, $limit );
		} );
	}

	private function operation_search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
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
		return Telepilot_Domain_Abilities::run( 'pages', 'drafts_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_drafts_page( $page, $limit );
		} );
	}

	private function operation_drafts_page( $page = 1, $limit = self::PER_PAGE ) {
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
		return Telepilot_Domain_Abilities::run( 'pages', 'trashed_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_trashed_page( $page, $limit );
		} );
	}

	private function operation_trashed_page( $page = 1, $limit = self::PER_PAGE ) {
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
			return $heading . "\n" . __( 'No pages matched that request.', 'wp-telepilot' );
		}

		$lines = array( $heading );
		foreach ( $pages as $page ) {
			$lines[] = sprintf(
				/* translators: 1: page ID, 2: page title, 3: post status. */ __( '[%1$d] %2$s [%3$s]', 'wp-telepilot' ),
				$page->ID,
				html_entity_decode( get_the_title( $page ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$page->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'pages', $heading ) ) . "\n\n" . __( 'No pages matched that request.', 'wp-telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'pages', $heading ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( /* translators: 1: current page number, 2: total number of pages, 3: total number of items. */ __( 'Page %1$d of %2$d | %3$d items', 'wp-telepilot' ), $result['page'], $result['total_pages'], isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $result['items'] ) )
		);

		foreach ( $result['items'] as $page ) {
			$blocks[] = $this->format_page_summary_block( $page );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use the buttons below, or run /pages search keyword to narrow the list.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'pages', __( 'Pages Commands', 'wp-telepilot' ) ) ),
				Telepilot_Telegram_Response_Builder::code( '/pages new' ) . ' ' . __( 'Start a guided draft page flow', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages create About us' ) . ' ' . __( 'Create a new draft page immediately', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages list' ) . ' ' . __( 'Show recent pages', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages latest' ) . ' ' . __( 'Alias for the recent pages list', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages drafts' ) . ' ' . __( 'Show draft pages only', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages search about' ) . ' ' . __( 'Search pages', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages details 123' ) . ' ' . __( 'Show page details and browser access links', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages title 123 New title' ) . ' ' . __( 'Update a page title', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages slug 123 about-us' ) . ' ' . __( 'Update a page slug', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages status 123 private' ) . ' ' . __( 'Set page status to draft, publish, or private', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages trashed' ) . ' ' . __( 'Show trashed pages', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages publish 123' ) . ' ' . __( 'Publish a page', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages draft 123' ) . ' ' . __( 'Move a page to draft', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages trash 123' ) . ' ' . __( 'Trash a page', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages restore 123' ) . ' ' . __( 'Restore a trashed page', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/pages delete 123' ) . ' ' . __( 'Permanently delete a page after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Page creation keeps the title first. Optional fields like slug and status can be set later after the draft exists.', 'wp-telepilot' ) ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Published pages can be previewed directly from Telegram. Drafts are linked to wp-admin because browser preview for drafts normally requires WordPress authentication.', 'wp-telepilot' ) ),
			)
		);
	}

	public function get_page_details( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'get_page_details', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_get_page_details( $page_id );
		} );
	}

	private function operation_get_page_details( $page_id ) {
		$page = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		return $page;
	}

	public function render_details_message( $page ) {
		if ( ! ( $page instanceof WP_Post ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'pages', __( 'Page Details', 'wp-telepilot' ) ) ) . "\n\n" . __( 'Page not found.', 'wp-telepilot' );
		}

		$blocks   = array();
		$blocks[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'pages', __( 'Page Details', 'wp-telepilot' ) ) );
		$blocks[] = implode(
			"\n",
			array(
				sprintf( /* translators: 1: page ID, 2: page title. */ __( 'Page: [%1$d] %2$s', 'wp-telepilot' ), $page->ID, Telepilot_Telegram_Response_Builder::escape( get_the_title( $page ) ) ),
				sprintf( /* translators: %s: post status. */ __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $page->post_status ) ),
				sprintf( /* translators: %s: page slug. */ __( 'Slug: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $page->post_name ) ),
				sprintf( /* translators: %s: last modification date and time. */ __( 'Modified: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_the_modified_date( 'Y-m-d H:i:s', $page ) ) ),
			)
		);
		$taxonomy_lines = $this->build_page_taxonomy_lines( $page );
		if ( ! empty( $taxonomy_lines ) ) {
			$blocks[] = implode( "\n", $taxonomy_lines );
		}
		$blocks[] = $this->describe_access_link( $page );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_creation_prompt_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'New Page', 'wp-telepilot' ) ),
				__( 'Send the page title now to create a new draft page.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Optional fields like slug or status can be set later after the draft exists.', 'wp-telepilot' ) ),
			)
		);
	}

	public function render_page_created_message( $page ) {
		if ( ! ( $page instanceof WP_Post ) ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Page Created', 'wp-telepilot' ) );
		}

		$title = get_the_title( $page );
		$title = '' !== (string) $title ? $title : __( 'Untitled page', 'wp-telepilot' );

		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Page Created', 'wp-telepilot' ) ),
				implode(
					"\n",
					array(
						sprintf( /* translators: 1: page ID, 2: page title. */ __( 'Page: [%1$d] %2$s', 'wp-telepilot' ), $page->ID, Telepilot_Telegram_Response_Builder::escape( $title ) ),
						sprintf( /* translators: %s: readable status label. */ __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->humanize_status( $page->post_status ) ) ),
						sprintf( /* translators: %s: page slug. */ __( 'Slug: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $page->post_name ) ),
					)
				),
				Telepilot_Telegram_Response_Builder::italic( __( 'Next steps: review details, change slug or status if needed, and publish when ready.', 'wp-telepilot' ) ),
			)
		);
	}

	public function create_page( $title ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'create_page', get_defined_vars(), function () use ( $title ) {
			return $this->operation_create_page( $title );
		} );
	}

	private function operation_create_page( $title ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new WP_Error( 'telepilot_capability_denied', __( 'You cannot create pages.', 'wp-telepilot' ) );
		}
		$page_id = wp_insert_post(
			wp_slash( array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $title ),
				'post_author' => get_current_user_id(),
			) ),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		$this->bump_cache_version();

		return array(
			'post'  => get_post( $page_id ),
			'label' => __( 'created as draft', 'wp-telepilot' ),
		);
	}

	public function update_title( $page_id, $title ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'update_title', get_defined_vars(), function () use ( $page_id, $title ) {
			return $this->operation_update_title( $page_id, $title );
		} );
	}

	private function operation_update_title( $page_id, $title ) {
		return $this->update_fields(
			$page_id,
			array(
				'post_title' => sanitize_text_field( $title ),
			),
			__( 'title updated', 'wp-telepilot' )
		);
	}

	public function update_slug( $page_id, $slug ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'update_slug', get_defined_vars(), function () use ( $page_id, $slug ) {
			return $this->operation_update_slug( $page_id, $slug );
		} );
	}

	private function operation_update_slug( $page_id, $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'telepilot_page_invalid_slug', __( 'That page slug is invalid.', 'wp-telepilot' ) );
		}

		return $this->update_fields(
			$page_id,
			array(
				'post_name' => $slug,
			),
			__( 'slug updated', 'wp-telepilot' )
		);
	}

	public function set_status( $page_id, $status ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'set_status', get_defined_vars(), function () use ( $page_id, $status ) {
			return $this->operation_set_status( $page_id, $status );
		} );
	}

	private function operation_set_status( $page_id, $status ) {
		$allowed_statuses = array(
			'draft'   => __( 'moved to draft', 'wp-telepilot' ),
			'publish' => __( 'published', 'wp-telepilot' ),
			'private' => __( 'made private', 'wp-telepilot' ),
		);

		$status = sanitize_key( $status );
		if ( ! isset( $allowed_statuses[ $status ] ) ) {
			return new WP_Error( 'telepilot_page_invalid_status', __( 'That page status is not supported.', 'wp-telepilot' ) );
		}

		return $this->update_status( $page_id, $status, $allowed_statuses[ $status ] );
	}

	public function publish( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'publish', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_publish( $page_id );
		} );
	}

	private function operation_publish( $page_id ) {
		return $this->update_status( $page_id, 'publish', __( 'published', 'wp-telepilot' ) );
	}

	public function draft( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'draft', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_draft( $page_id );
		} );
	}

	private function operation_draft( $page_id ) {
		return $this->update_status( $page_id, 'draft', __( 'moved to draft', 'wp-telepilot' ) );
	}

	public function trash( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'trash', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_trash( $page_id );
		} );
	}

	private function operation_trash( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_trash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_page_trash_failed', __( 'WordPress could not trash that page.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'trashed', 'wp-telepilot' ),
		);
	}

	public function restore( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'restore', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_restore( $page_id );
		} );
	}

	private function operation_restore( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_untrash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_page_restore_failed', __( 'WordPress could not restore that page.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'restored', 'wp-telepilot' ),
		);
	}

	public function delete( $page_id ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'delete', get_defined_vars(), function () use ( $page_id ) {
			return $this->operation_delete( $page_id );
		} );
	}

	private function operation_delete( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		$before_state = array(
			'title'  => get_the_title( $page ),
			'status' => $page->post_status,
			'slug'   => (string) $page->post_name,
		);

		$result = wp_delete_post( $page_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepilot_page_delete_failed', __( 'WordPress could not delete that page.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'wp-telepilot' ),
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
				sprintf( /* translators: 1: requested action, 2: page ID. */ __( 'Confirm %1$s [%2$d]', 'wp-telepilot' ), ucfirst( $action ), $page_id ),
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
					'text' => Telepilot_Telegram_Response_Builder::label( 'preview', sprintf( /* translators: %d: page ID. */ __( 'Preview [%d]', 'wp-telepilot' ), $page_item->ID ) ),
					'url'  => $this->get_preview_url( $page_item ),
				);
			} else {
				$row[] = array(
					'text' => Telepilot_Telegram_Response_Builder::label( 'edit', sprintf( /* translators: %d: page ID. */ __( 'Edit [%d]', 'wp-telepilot' ), $page_item->ID ) ),
					'url'  => $this->get_admin_edit_url( $page_item->ID ),
				);
			}

			if ( 'trash' === $page_item->post_status ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'restore', sprintf( /* translators: %d: page ID. */ __( 'Restore [%d]', 'wp-telepilot' ), $page_item->ID ) ),
					'callback_data' => '/pages restore ' . (int) $page_item->ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( /* translators: %d: page ID. */ __( 'Delete [%d]', 'wp-telepilot' ), $page_item->ID ) ),
					'callback_data' => '/pages delete ' . (int) $page_item->ID,
				);
			} else {
				if ( 'publish' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'publish', sprintf( /* translators: %d: page ID. */ __( 'Publish [%d]', 'wp-telepilot' ), $page_item->ID ) ),
						'callback_data' => '/pages publish ' . (int) $page_item->ID,
					);
				}

				if ( 'draft' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'draft', sprintf( /* translators: %d: page ID. */ __( 'Draft [%d]', 'wp-telepilot' ), $page_item->ID ) ),
						'callback_data' => '/pages draft ' . (int) $page_item->ID,
					);
				}

				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( /* translators: %d: page ID. */ __( 'Trash [%d]', 'wp-telepilot' ), $page_item->ID ) ),
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

		if ( 'search' === $subcommand ) { return Telepilot_Pagination_Service::search_row( 'pages', $search_term, $page, $total_pages ); }

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
		if ( 'drafts' === $subcommand ) {
			return '/pages drafts page:' . $page;
		}

		if ( 'trashed' === $subcommand ) {
			return '/pages trashed page:' . $page;
		}

		return '/pages list page:' . $page;
	}

	private function format_page_summary_block( $page ) {
		if ( ! $page instanceof WP_Post ) {
			return '';
		}

		$title  = get_the_title( $page );
		$title  = '' !== (string) $title ? $title : __( 'Untitled page', 'wp-telepilot' );
		$author = get_the_author_meta( 'display_name', (int) $page->post_author );
		$author = '' !== (string) $author ? $author : get_the_author_meta( 'user_login', (int) $page->post_author );
		$author = '' !== (string) $author ? $author : __( 'Unknown author', 'wp-telepilot' );
		$lines  = array(
			Telepilot_Telegram_Response_Builder::label(
				'pages',
				sprintf(
					/* translators: 1: page ID, 2: page title. */ __( '[%1$d] %2$s', 'wp-telepilot' ),
					$page->ID,
					Telepilot_Telegram_Response_Builder::escape( $title )
				)
			),
			sprintf( /* translators: %s: readable status label. */ __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->humanize_status( $page->post_status ) ) ),
			sprintf( /* translators: %s: author name. */ __( 'Author: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $author ) ),
			sprintf( /* translators: %s: last modification date and time. */ __( 'Updated: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_post_modified_time( 'Y-m-d H:i:s', false, $page, true ) ) ),
		);

		$lines = array_merge( $lines, $this->build_page_taxonomy_lines( $page ) );

		$access_link = $this->describe_access_link( $page );
		if ( '' !== $access_link ) {
			$lines[] = $access_link;
		}

		return implode( "\n", $lines );
	}

	private function build_page_taxonomy_lines( $page ) {
		if ( ! $page instanceof WP_Post ) {
			return array();
		}

		$lines      = array();
		$taxonomies = get_object_taxonomies( 'page', 'objects' );

		if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
			return $lines;
		}

		foreach ( $taxonomies as $taxonomy => $taxonomy_object ) {
			if ( ! $taxonomy_object instanceof WP_Taxonomy ) {
				continue;
			}

			$summary = $this->format_taxonomy_term_summary( $page->ID, $taxonomy );
			if ( '' === $summary ) {
				continue;
			}

			$label   = ! empty( $taxonomy_object->labels->name ) ? (string) $taxonomy_object->labels->name : ucfirst( str_replace( '_', ' ', (string) $taxonomy ) );
			$lines[] = sprintf( /* translators: 1: display label, 2: summary text. */ __( '%1$s: %2$s', 'wp-telepilot' ), $label, Telepilot_Telegram_Response_Builder::escape( $summary ) );
		}

		return $lines;
	}

	private function format_taxonomy_term_summary( $post_id, $taxonomy ) {
		$terms = get_the_terms( (int) $post_id, (string) $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$labels = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$labels[] = sprintf( '%1$s [%2$d]', $term->name, $term->term_id );
		}

		if ( empty( $labels ) ) {
			return '';
		}

		if ( count( $labels ) > 4 ) {
			$visible = array_slice( $labels, 0, 4 );

			return sprintf(
				/* translators: 1: visible terms, 2: number of additional terms. */
				__( '%1$s and %2$d more', 'wp-telepilot' ),
				implode( ', ', $visible ),
				count( $labels ) - 4
			);
		}

		return implode( ', ', $labels );
	}

	private function describe_access_link( $page ) {
		if ( ! $page instanceof WP_Post ) {
			return '';
		}

		if ( 'publish' === $page->post_status ) {
			$url = $this->get_preview_url( $page );
			if ( '' !== $url ) {
				return __( 'Preview:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Open in browser', 'wp-telepilot' ), $url );
			}
		}

		return __( 'Edit:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Open in wp-admin', 'wp-telepilot' ), $this->get_admin_edit_url( $page->ID ) );
	}

	private function get_preview_url( $page ) {
		$url = get_permalink( $page );

		return $url ? (string) $url : '';
	}

	private function humanize_status( $status ) {
		$status = str_replace( array( '-', '_' ), ' ', sanitize_key( (string) $status ) );

		return '' !== $status ? ucwords( $status ) : __( 'Unknown', 'wp-telepilot' );
	}

	private function get_admin_edit_url( $page_id ) {
		return admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' );
	}

	private function update_status( $page_id, $status, $label ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_update_post(
			wp_slash( array(
				'ID'          => $page_id,
				'post_status' => $status,
			) ),
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
			return new WP_Error( 'telepilot_page_not_found', __( 'Page not found.', 'wp-telepilot' ) );
		}

		$before_state = array(
			'post_title' => (string) $page->post_title,
			'post_name'  => (string) $page->post_name,
			'post_status'=> (string) $page->post_status,
		);

		$result = wp_update_post(
			wp_slash( array_merge(
				array(
					'ID' => $page_id,
				),
				$fields
			) ),
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
		$args = Telepilot_Actor_Access::content_query( $args );
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_pages_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit, get_current_user_id(), wp_get_current_user()->allcaps ) ) );
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

	public function filtered_page( $query = '', $status = 'latest', $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'pages', 'filtered_page', get_defined_vars(), function () use ( $query, $status, $page, $limit ) {
			$statuses = 'latest' === $status ? array( 'publish', 'draft', 'private' ) : array( $status );
			if ( array_diff( $statuses, array( 'publish', 'draft', 'pending', 'future', 'trash', 'private' ) ) ) { return new WP_Error( 'telepilot_invalid_status', 'Unsupported page status.' ); }
			return $this->query_pages_page( array( 'post_type' => 'page', 'post_status' => $statuses, 's' => $query ), $page, $limit );
		} );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_pages_cache_version', 1 ) );
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
