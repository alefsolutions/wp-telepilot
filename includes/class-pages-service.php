<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Pages_Service {
	private $confirmation_service;
	const PER_PAGE = 5;

	public function __construct( TelePress_Confirmation_Service $confirmation_service ) {
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
			return $heading . "\n" . __( 'No pages matched that request.', 'telepress' );
		}

		$lines = array( $heading );
		foreach ( $pages as $page ) {
			$lines[] = sprintf(
				__( '#%1$d %2$s [%3$s]', 'telepress' ),
				$page->ID,
				html_entity_decode( get_the_title( $page ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				$page->post_status
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return TelePress_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No pages matched that request.', 'telepress' );
		}

		$lines   = array( TelePress_Telegram_Response_Builder::bold( $heading ) );
		$lines[] = TelePress_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepress' ), $result['page'], $result['total_pages'] )
		);
		$lines[] = '';

		foreach ( $result['items'] as $page ) {
			$lines[] = sprintf(
				__( '- #%1$d %2$s [%3$s]', 'telepress' ),
				$page->ID,
				TelePress_Telegram_Response_Builder::escape( get_the_title( $page ) ),
				TelePress_Telegram_Response_Builder::escape( $page->post_status )
			);

			$access_link = $this->describe_access_link( $page );
			if ( '' !== $access_link ) {
				$lines[] = '  ' . $access_link;
			}
		}

		$lines[] = '';
		$lines[] = TelePress_Telegram_Response_Builder::italic( __( 'Tip: use the buttons below, or run /pages search keyword to narrow the list.', 'telepress' ) );

		return implode( "\n", $lines );
	}

	public function render_help_message() {
		$lines   = array();
		$lines[] = TelePress_Telegram_Response_Builder::bold( __( 'Pages Commands', 'telepress' ) );
		$lines[] = '';
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages list' ) . ' ' . __( 'Show recent pages', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages search about' ) . ' ' . __( 'Search pages', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages trashed' ) . ' ' . __( 'Show trashed pages', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages publish 123' ) . ' ' . __( 'Publish a page', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages draft 123' ) . ' ' . __( 'Move a page to draft', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages trash 123' ) . ' ' . __( 'Trash a page', 'telepress' );
		$lines[] = TelePress_Telegram_Response_Builder::code( '/pages restore 123' ) . ' ' . __( 'Restore a trashed page', 'telepress' );
		$lines[] = '';
		$lines[] = TelePress_Telegram_Response_Builder::italic( __( 'Published pages can be previewed directly from Telegram. Drafts are linked to wp-admin because browser preview for drafts normally requires WordPress authentication.', 'telepress' ) );

		return implode( "\n", $lines );
	}

	public function publish( $page_id ) {
		return $this->update_status( $page_id, 'publish', __( 'published', 'telepress' ) );
	}

	public function draft( $page_id ) {
		return $this->update_status( $page_id, 'draft', __( 'moved to draft', 'telepress' ) );
	}

	public function trash( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepress_page_not_found', __( 'Page not found.', 'telepress' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_trash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepress_page_trash_failed', __( 'WordPress could not trash that page.', 'telepress' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'trashed', 'telepress' ),
		);
	}

	public function restore( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'telepress_page_not_found', __( 'Page not found.', 'telepress' ) );
		}

		$before_status = $page->post_status;
		$result        = wp_untrash_post( $page_id );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepress_page_restore_failed', __( 'WordPress could not restore that page.', 'telepress' ) );
		}

		$this->bump_cache_version();

		return array(
			'post'          => get_post( $page_id ),
			'before_status' => $before_status,
			'after_status'  => get_post_status( $page_id ),
			'label'         => __( 'restored', 'telepress' ),
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

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm %1$s #%2$d', 'telepress' ), ucfirst( $action ), $page_id ),
							'callback_data' => 'tp:page:' . $action . ':' . (int) $page_id . ':' . $token,
						),
					),
				)
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
					'text' => sprintf( __( 'Preview #%d', 'telepress' ), $page_item->ID ),
					'url'  => $this->get_preview_url( $page_item ),
				);
			} else {
				$row[] = array(
					'text' => sprintf( __( 'Edit #%d', 'telepress' ), $page_item->ID ),
					'url'  => $this->get_admin_edit_url( $page_item->ID ),
				);
			}

			if ( 'trash' === $page_item->post_status ) {
				$row[] = array(
					'text'          => sprintf( __( 'Restore #%d', 'telepress' ), $page_item->ID ),
					'callback_data' => '/pages restore ' . (int) $page_item->ID,
				);
			} else {
				if ( 'publish' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => sprintf( __( 'Publish #%d', 'telepress' ), $page_item->ID ),
						'callback_data' => '/pages publish ' . (int) $page_item->ID,
					);
				}

				if ( 'draft' !== $page_item->post_status ) {
					$row[] = array(
						'text'          => sprintf( __( 'Draft #%d', 'telepress' ), $page_item->ID ),
						'callback_data' => '/pages draft ' . (int) $page_item->ID,
					);
				}

				$row[] = array(
					'text'          => sprintf( __( 'Trash #%d', 'telepress' ), $page_item->ID ),
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
			return trim( '/pages search ' . $search_term . ' page:' . $page );
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
				return __( 'Preview:', 'telepress' ) . ' ' . TelePress_Telegram_Response_Builder::link( __( 'Open in browser', 'telepress' ), $url );
			}
		}

		return __( 'Edit:', 'telepress' ) . ' ' . TelePress_Telegram_Response_Builder::link( __( 'Open in wp-admin', 'telepress' ), $this->get_admin_edit_url( $page->ID ) );
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
			return new WP_Error( 'telepress_page_not_found', __( 'Page not found.', 'telepress' ) );
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

	private function query_pages_page( $args, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepress_pages_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
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
		update_option( 'telepress_pages_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepress_pages_cache_version', 1 ) );
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
