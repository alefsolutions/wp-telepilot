<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Media_Service {
	private $confirmation_service;
	private $telegram_client;
	const PER_PAGE = 5;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service, Telepilot_Telegram_Client $telegram_client ) {
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
			return $heading . "\n" . __( 'No media items matched that request.', 'telepilot' );
		}

		$lines = array( $heading );
		foreach ( $items as $item ) {
			$lines[] = sprintf(
				__( '[%1$d] %2$s', 'telepilot' ),
				$item->ID,
				html_entity_decode( get_the_title( $item ), ENT_QUOTES, get_bloginfo( 'charset' ) )
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No media items matched that request.', 'telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $item ) {
			$title = get_the_title( $item );
			if ( '' === (string) $title ) {
				$title = sprintf( __( 'Attachment [%d]', 'telepilot' ), $item->ID );
			}

			$block_lines = array(
				sprintf(
					__( '[%1$d] %2$s', 'telepilot' ),
					$item->ID,
					Telepilot_Telegram_Response_Builder::escape( $title )
				),
			);

			$preview_url = wp_get_attachment_url( $item->ID );
			if ( $preview_url ) {
				$block_lines[] = __( 'Preview:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Open file', 'telepilot' ), $preview_url );
			}

			$blocks[] = implode( "\n", $block_lines );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use /media details ID for metadata or /media open ID to jump straight to the file.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Media Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/media list' ) . ' ' . __( 'Show recent media items', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/media search logo' ) . ' ' . __( 'Search media by title', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/media details 123' ) . ' ' . __( 'Show media metadata and preview links', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/media open 123' ) . ' ' . __( 'Open the media file in your browser', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Media is read-only in this release. Use WordPress wp-admin for uploads or metadata edits.', 'telepilot' ) ),
			)
		);
	}

	public function get_item_details( $attachment_id ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepilot_media_not_found', __( 'Media item not found.', 'telepilot' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_size = ( $file_path && file_exists( $file_path ) ) ? size_format( (int) filesize( $file_path ) ) : '';

		return array(
			'item'      => $item,
			'title'     => get_the_title( $item ),
			'url'       => wp_get_attachment_url( $attachment_id ),
			'mime_type' => (string) get_post_mime_type( $item ),
			'alt_text'  => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'   => (string) $item->post_excerpt,
			'file_size' => $file_size,
			'metadata'  => is_array( $metadata ) ? $metadata : array(),
		);
	}

	public function render_details_message( $details ) {
		if ( empty( $details['item'] ) || ! $details['item'] instanceof WP_Post ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Media Details', 'telepilot' ) ) . "\n\n" . __( 'Media item not found.', 'telepilot' );
		}

		$blocks   = array();
		$blocks[] = Telepilot_Telegram_Response_Builder::bold( __( 'Media Details', 'telepilot' ) );

		$detail_lines   = array();
		$detail_lines[] = sprintf(
			__( 'Item: [%1$d] %2$s', 'telepilot' ),
			(int) $details['item']->ID,
			Telepilot_Telegram_Response_Builder::escape( $details['title'] ? $details['title'] : __( 'Untitled attachment', 'telepilot' ) )
		);
		$detail_lines[] = sprintf( __( 'Mime type: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $details['mime_type'] ? $details['mime_type'] : __( 'Unknown', 'telepilot' ) ) );

		if ( ! empty( $details['file_size'] ) ) {
			$detail_lines[] = sprintf( __( 'File size: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $details['file_size'] ) );
		}

		if ( ! empty( $details['metadata']['width'] ) && ! empty( $details['metadata']['height'] ) ) {
			$detail_lines[] = sprintf( __( 'Dimensions: %1$d x %2$d', 'telepilot' ), (int) $details['metadata']['width'], (int) $details['metadata']['height'] );
		}

		if ( '' !== (string) $details['alt_text'] ) {
			$detail_lines[] = sprintf( __( 'Alt text: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $details['alt_text'] ) );
		}

		if ( '' !== (string) $details['caption'] ) {
			$detail_lines[] = sprintf( __( 'Caption: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $details['caption'] ) );
		}

		if ( ! empty( $details['url'] ) ) {
			$detail_lines[] = sprintf( __( 'Preview: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open file', 'telepilot' ), $details['url'] ) );
		}

		$blocks[] = implode( "\n", $detail_lines );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function rename( $attachment_id, $title ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepilot_media_not_found', __( 'Media item not found.', 'telepilot' ) );
		}

		$before_state = array(
			'title' => (string) $item->post_title,
		);

		$result = wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => sanitize_text_field( $title ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();
		$updated = get_post( $attachment_id );

		return array(
			'item'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'title' => (string) $updated->post_title,
			),
			'label'        => __( 'renamed', 'telepilot' ),
		);
	}

	public function update_alt_text( $attachment_id, $alt_text ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepilot_media_not_found', __( 'Media item not found.', 'telepilot' ) );
		}

		$before_state = array(
			'alt_text' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		return array(
			'item'         => get_post( $attachment_id ),
			'before_state' => $before_state,
			'after_state'  => array(
				'alt_text' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			),
			'label'        => __( 'alt text updated', 'telepilot' ),
		);
	}

	public function update_caption( $attachment_id, $caption ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepilot_media_not_found', __( 'Media item not found.', 'telepilot' ) );
		}

		$before_state = array(
			'caption' => (string) $item->post_excerpt,
		);

		$result = wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_excerpt' => sanitize_textarea_field( $caption ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();
		$updated = get_post( $attachment_id );

		return array(
			'item'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'caption' => (string) $updated->post_excerpt,
			),
			'label'        => __( 'caption updated', 'telepilot' ),
		);
	}

	public function delete( $attachment_id ) {
		$item = get_post( $attachment_id );
		if ( ! $item || 'attachment' !== $item->post_type ) {
			return new WP_Error( 'telepilot_media_not_found', __( 'Media item not found.', 'telepilot' ) );
		}

		$before_state = array(
			'title' => get_the_title( $item ),
			'url'   => wp_get_attachment_url( $attachment_id ),
		);

		$result = wp_delete_attachment( $attachment_id, true );
		if ( ! $result ) {
			return new WP_Error( 'telepilot_media_delete_failed', __( 'WordPress could not delete that media item.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'label'        => __( 'deleted', 'telepilot' ),
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

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm delete [%d]', 'telepilot' ), $attachment_id ),
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

			$row = array(
				array(
					'text'          => sprintf( __( 'Details [%d]', 'telepilot' ), $item->ID ),
					'callback_data' => '/media details ' . (int) $item->ID,
				),
			);

			$attachment_url = wp_get_attachment_url( $item->ID );
			if ( $attachment_url ) {
				$row[] = array(
					'text' => sprintf( __( 'Open [%d]', 'telepilot' ), $item->ID ),
					'url'  => $attachment_url,
				);
			}

			$rows[] = $row;
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
			return trim( '/media search ' . $search_term . ' page:' . $page );
		}

		return '/media recent page:' . $page;
	}

	public function build_item_keyboard( $attachment_id, $attachment_url = '' ) {
		$row = array(
			array(
				'text'          => sprintf( __( 'Details [%d]', 'telepilot' ), $attachment_id ),
				'callback_data' => '/media details ' . (int) $attachment_id,
			),
		);

		if ( '' !== (string) $attachment_url ) {
			$row[] = array(
				'text' => sprintf( __( 'Open [%d]', 'telepilot' ), $attachment_id ),
				'url'  => (string) $attachment_url,
			);
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( array( $row ) ),
			$this->navigation_rows()
		);
	}

	public function import_from_update( $update ) {
		$message = isset( $update['message'] ) ? $update['message'] : array();
		$file    = $this->extract_file_reference( $message );

		if ( empty( $file['file_id'] ) ) {
			return new WP_Error( 'telepilot_media_missing_file', __( 'No supported Telegram file was found in that message.', 'telepilot' ) );
		}

		$file_response = $this->telegram_client->get_file( $file['file_id'] );
		if ( is_wp_error( $file_response ) ) {
			return $file_response;
		}

		if ( empty( $file_response['result']['file_path'] ) ) {
			return new WP_Error( 'telepilot_media_missing_path', __( 'Telegram did not return a downloadable file path.', 'telepilot' ) );
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

		$this->bump_cache_version();

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
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_media_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
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
					'orderby'                => 'date',
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
		update_option( 'telepilot_media_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_media_cache_version', 1 ) );
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
