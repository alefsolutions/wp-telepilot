<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Media_Service {
	private $confirmation_service;
	private $telegram_client;
	const PER_PAGE = 5;

	public function __construct( TelePress_Confirmation_Service $confirmation_service, TelePress_Telegram_Client $telegram_client ) {
		$this->confirmation_service = $confirmation_service;
		$this->telegram_client      = $telegram_client;
	}

	public function recent( $limit = 5 ) {
		return get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => max( 1, absint( $limit ) ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	public function recent_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_media_page(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			),
			$page,
			$limit
		);
	}

	public function search( $term, $limit = 5 ) {
		return get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				's'                => sanitize_text_field( $term ),
				'posts_per_page'   => max( 1, absint( $limit ) ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_media_page(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				's'           => sanitize_text_field( $term ),
			),
			$page,
			$limit
		);
	}

	public function render_list_message( $items, $heading ) {
		if ( empty( $items ) ) {
			return $heading . "\n" . __( 'No media items matched that request.', 'telepress' );
		}

		$lines = array( $heading );
		foreach ( $items as $item ) {
			$lines[] = sprintf(
				__( '#%1$d %2$s', 'telepress' ),
				$item->ID,
				html_entity_decode( get_the_title( $item ), ENT_QUOTES, get_bloginfo( 'charset' ) )
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return TelePress_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No media items matched that request.', 'telepress' );
		}

		$lines   = array( TelePress_Telegram_Response_Builder::bold( $heading ) );
		$lines[] = TelePress_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepress' ), $result['page'], $result['total_pages'] )
		);
		$lines[] = '';

		foreach ( $result['items'] as $item ) {
			$lines[] = sprintf(
				__( "- #%1$d %2$s\n  Preview: %3$s", 'telepress' ),
				$item->ID,
				TelePress_Telegram_Response_Builder::escape( get_the_title( $item ) ),
				TelePress_Telegram_Response_Builder::escape( wp_get_attachment_url( $item->ID ) )
			);
		}

		$lines[] = '';
		$lines[] = TelePress_Telegram_Response_Builder::italic( __( 'Tip: send a photo or document in this private chat to upload it straight into WordPress.', 'telepress' ) );

		return implode( "\n", $lines );
	}

	public function delete( $attachment_id ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepress_media_not_found', __( 'Media item not found.', 'telepress' ) );
		}

		$before_state = array(
			'title' => get_the_title( $item ),
			'url'   => wp_get_attachment_url( $attachment_id ),
		);

		$result = wp_delete_attachment( $attachment_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepress_media_delete_failed', __( 'WordPress could not delete that media item.', 'telepress' ) );
		}

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'telepress' ),
		);
	}

	public function build_delete_confirmation_keyboard( $attachment_id, $telegram_user_id ) {
		$token = $this->confirmation_service->create_token(
			array(
				'action'           => 'delete',
				'attachment_id'    => (int) $attachment_id,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm delete #%d', 'telepress' ), $attachment_id ),
							'callback_data' => 'tp:media:delete:' . (int) $attachment_id . ':' . $token,
						),
					),
				)
			),
			$this->navigation_rows()
		);
	}

	public function build_list_keyboard( $items, $subcommand = 'recent', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Delete #%d', 'telepress' ), $item->ID ),
					'callback_data' => '/media delete ' . (int) $item->ID,
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
			return trim( '/media search ' . $search_term . ' page:' . $page );
		}

		return '/media recent page:' . $page;
	}

	public function import_from_update( $update ) {
		$message = isset( $update['message'] ) ? $update['message'] : array();
		$file    = $this->extract_file_reference( $message );

		if ( empty( $file['file_id'] ) ) {
			return new WP_Error( 'telepress_media_missing_file', __( 'No supported Telegram file was found in that message.', 'telepress' ) );
		}

		$file_response = $this->telegram_client->get_file( $file['file_id'] );
		if ( is_wp_error( $file_response ) ) {
			return $file_response;
		}

		if ( empty( $file_response['result']['file_path'] ) ) {
			return new WP_Error( 'telepress_media_missing_path', __( 'Telegram did not return a downloadable file path.', 'telepress' ) );
		}

		$download_url = $this->telegram_client->build_file_url( $file_response['result']['file_path'] );
		$tmp_file     = download_url( $download_url, 30 );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$filename = ! empty( $file['filename'] ) ? sanitize_file_name( $file['filename'] ) : basename( $file_response['result']['file_path'] );
		if ( '' === $filename ) {
			$filename = 'telegram-upload';
		}

		$upload = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload( $upload, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return $attachment_id;
		}

		return array(
			'attachment_id' => $attachment_id,
			'title'         => get_the_title( $attachment_id ),
			'url'           => wp_get_attachment_url( $attachment_id ),
		);
	}

	private function extract_file_reference( $message ) {
		if ( ! empty( $message['photo'] ) && is_array( $message['photo'] ) ) {
			$photo = end( $message['photo'] );

			return array(
				'file_id'  => isset( $photo['file_id'] ) ? (string) $photo['file_id'] : '',
				'filename' => 'telegram-photo-' . gmdate( 'Ymd-His' ) . '.jpg',
			);
		}

		if ( ! empty( $message['document']['file_id'] ) ) {
			return array(
				'file_id'  => (string) $message['document']['file_id'],
				'filename' => ! empty( $message['document']['file_name'] ) ? (string) $message['document']['file_name'] : 'telegram-document',
			);
		}

		return array(
			'file_id'  => '',
			'filename' => '',
		);
	}

	private function query_media_page( $args, $page, $limit ) {
		$page  = max( 1, absint( $page ) );
		$limit = max( 1, absint( $limit ) );

		$query = new WP_Query(
			array_merge(
				$args,
				array(
					'posts_per_page'   => $limit,
					'paged'            => $page,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
				)
			)
		);

		return array(
			'items'       => $query->posts,
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => (int) $query->found_posts,
			'total_pages' => max( 1, (int) $query->max_num_pages ),
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
