<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Comments_Service {
	const PER_PAGE = 5;

	private $confirmation_service;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function pending_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'hold', '', $page, $limit );
	}

	public function approved_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'approve', '', $page, $limit );
	}

	public function spam_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'spam', '', $page, $limit );
	}

	public function trash_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'trash', '', $page, $limit );
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'all', sanitize_text_field( $term ), $page, $limit );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No comments matched that request.', 'telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $comment ) {
			$blocks[] = $this->format_comment_summary_line( $comment );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use details for the full context, and keep destructive moderation actions in private chat.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Comments Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/comments pending' ) . ' ' . __( 'Show pending comments', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments approved' ) . ' ' . __( 'Show approved comments', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments spam' ) . ' ' . __( 'Show spam comments', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments trash' ) . ' ' . __( 'Show trashed comments', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments search keyword' ) . ' ' . __( 'Search comments by author, email, URL, or content', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments details 123' ) . ' ' . __( 'Show comment details', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments approve 123' ) . ' ' . __( 'Approve a comment', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments reject 123' ) . ' ' . __( 'Move a comment back to moderation hold', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments spam 123' ) . ' ' . __( 'Mark a comment as spam', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments trash 123' ) . ' ' . __( 'Move a comment to trash', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments restore 123' ) . ' ' . __( 'Restore a trashed comment', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments unspam 123' ) . ' ' . __( 'Remove a comment from spam', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments delete 123' ) . ' ' . __( 'Permanently delete a comment after confirmation', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments reply 123 Thank you for your comment' ) . ' ' . __( 'Post an approved reply to a comment', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Tip: pagination uses the same `page:N` suffix as other WP Telepilot list commands.', 'telepilot' ) ),
			)
		);
	}

	public function render_comment_details_message( $comment ) {
		if ( ! ( $comment instanceof WP_Comment ) ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'Comment Details', 'telepilot' ) ) . "\n\n" . __( 'Comment not found.', 'telepilot' );
		}

		$post_title = get_the_title( $comment->comment_post_ID );
		$post_url   = get_permalink( $comment->comment_post_ID );
		$status     = $this->status_label( $comment );
		$admin_url  = admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID );
		$content    = trim( wp_strip_all_tags( $comment->comment_content ) );
		$content    = wp_html_excerpt( $content, 900, '...' );

		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'Comment Details', 'telepilot' ) );
		$lines[] = '';
		$lines[] = sprintf( __( 'Comment: [%d]', 'telepilot' ), $comment->comment_ID );
		$lines[] = sprintf( __( 'Status: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $status ) );
		$lines[] = sprintf( __( 'Author: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'telepilot' ) ) );

		if ( '' !== (string) $comment->comment_author_email ) {
			$lines[] = sprintf( __( 'Email: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $comment->comment_author_email ) );
		}

		$lines[] = sprintf( __( 'Post: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $post_title ? $post_title : __( 'Unknown Post', 'telepilot' ) ) );
		$lines[] = sprintf( __( 'Date: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_comment_date( 'Y-m-d H:i:s', $comment ) ) );
		$lines[] = sprintf( __( 'Admin: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open comment in wp-admin', 'telepilot' ), $admin_url ) );

		if ( $post_url ) {
			$lines[] = sprintf( __( 'Post: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open post', 'telepilot' ), $post_url ) );
		}

		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'Content', 'telepilot' ) );
		$lines[] = Telepilot_Telegram_Response_Builder::escape( $content ? $content : __( 'No visible content.', 'telepilot' ) );

		return implode( "\n", $lines );
	}

	public function get_comment_details( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'telepilot' ) );
		}

		return $comment;
	}

	public function moderate_comment( $comment_id, $action ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'telepilot' ) );
		}

		$before_status = wp_get_comment_status( $comment );

		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment_id, 'approve' );
				$label  = __( 'approved', 'telepilot' );
				break;

			case 'reject':
				$result = wp_set_comment_status( $comment_id, 'hold' );
				$label  = __( 'moved to moderation hold', 'telepilot' );
				break;

			case 'trash':
				$result = wp_trash_comment( $comment_id );
				$label  = __( 'trashed', 'telepilot' );
				break;

			case 'spam':
				$result = wp_spam_comment( $comment_id );
				$label  = __( 'marked as spam', 'telepilot' );
				break;

			case 'restore':
				$result = wp_untrash_comment( $comment_id );
				$label  = __( 'restored', 'telepilot' );
				break;

			case 'unspam':
				$result = wp_unspam_comment( $comment_id );
				$label  = __( 'removed from spam', 'telepilot' );
				break;

			case 'delete':
				$result = wp_delete_comment( $comment_id, true );
				$label  = __( 'deleted', 'telepilot' );
				break;

			default:
				return new WP_Error( 'telepilot_invalid_comment_action', __( 'Unsupported comment action.', 'telepilot' ) );
		}

		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_comment_update_failed', __( 'WordPress could not update that comment.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'comment'       => 'delete' === $action ? null : get_comment( $comment_id ),
			'before_status' => $before_status,
			'after_status'  => 'delete' === $action ? 'deleted' : wp_get_comment_status( $comment_id ),
			'label'         => $label,
		);
	}

	public function reply_to_comment( $comment_id, $content, $wp_user ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'telepilot' ) );
		}

		if ( ! ( $wp_user instanceof WP_User ) ) {
			return new WP_Error( 'telepilot_reply_user_invalid', __( 'A valid WordPress user is required to reply.', 'telepilot' ) );
		}

		$content = trim( (string) wp_unslash( $content ) );
		if ( '' === $content ) {
			return new WP_Error( 'telepilot_reply_content_required', __( 'Reply content is required.', 'telepilot' ) );
		}

		$reply_id = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $comment->comment_post_ID,
				'comment_parent'       => (int) $comment_id,
				'user_id'              => (int) $wp_user->ID,
				'comment_author'       => $wp_user->display_name ? $wp_user->display_name : $wp_user->user_login,
				'comment_author_email' => $wp_user->user_email,
				'comment_author_url'   => $wp_user->user_url,
				'comment_content'      => wp_kses_post( $content ),
				'comment_approved'     => 1,
				'comment_type'         => '',
			)
		);

		if ( ! $reply_id ) {
			return new WP_Error( 'telepilot_reply_insert_failed', __( 'WordPress could not create that reply.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'comment'       => get_comment( $comment_id ),
			'reply'         => get_comment( $reply_id ),
			'before_status' => wp_get_comment_status( $comment ),
			'after_status'  => wp_get_comment_status( $comment ),
			'label'         => __( 'reply posted', 'telepilot' ),
		);
	}

	public function build_list_keyboard( $comments, $subcommand = 'pending', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			$status = wp_get_comment_status( $comment );
			$row    = array(
				array(
					'text'          => sprintf( __( 'Details [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments details ' . (int) $comment->comment_ID,
				),
			);

			if ( in_array( $status, array( 'hold', 'unapproved' ), true ) ) {
				$row[] = array(
					'text'          => sprintf( __( 'Approve [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments approve ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Spam [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments spam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Trash [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments trash ' . (int) $comment->comment_ID,
				);
			} elseif ( 'approved' === $status ) {
				$row[] = array(
					'text'          => sprintf( __( 'Reject [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments reject ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Spam [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments spam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Trash [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments trash ' . (int) $comment->comment_ID,
				);
			} elseif ( 'spam' === $status ) {
				$row[] = array(
					'text'          => sprintf( __( 'Unspam [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments unspam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Delete [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments delete ' . (int) $comment->comment_ID,
				);
			} elseif ( 'trash' === $status ) {
				$row[] = array(
					'text'          => sprintf( __( 'Restore [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments restore ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => sprintf( __( 'Delete [%d]', 'telepilot' ), $comment->comment_ID ),
					'callback_data' => '/comments delete ' . (int) $comment->comment_ID,
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

	public function build_action_confirmation_keyboard( $comment_id, $action, $telegram_user_id ) {
		$token = $this->confirmation_service->create_token(
			array(
				'action'           => (string) $action,
				'comment_id'       => (int) $comment_id,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$d]', 'telepilot' ), ucfirst( $action ), $comment_id ),
				'tp:comment:' . $action . ':' . (int) $comment_id . ':' . $token,
				'/comments pending'
			),
			$this->navigation_rows()
		);
	}

	private function query_comments_page( $status, $search, $page, $limit ) {
		$status    = $this->normalize_status( $status );
		$search    = sanitize_text_field( $search );
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_comments_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $status, $search, $page, $limit ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query_args = array(
			'status'                    => $status,
			'number'                    => $limit,
			'offset'                    => ( $page - 1 ) * $limit,
			'orderby'                   => 'comment_date_gmt',
			'order'                     => 'DESC',
			'update_comment_meta_cache' => false,
			'update_comment_post_cache' => false,
		);

		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}

		$items = get_comments( $query_args );
		$total = get_comments(
			array_merge(
				$query_args,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		$total_pages = max( 1, (int) ceil( (int) $total / $limit ) );
		$result      = array(
			'items'       => is_array( $items ) ? $items : array(),
			'page'        => min( $page, $total_pages ),
			'per_page'    => $limit,
			'total_items' => (int) $total,
			'total_pages' => $total_pages,
			'status'      => $status,
			'search'      => $search,
		);

		set_transient( $cache_key, $result, 30 );

		return $result;
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
			return trim( '/comments search ' . $search_term . ' page:' . $page );
		}

		return '/comments ' . $subcommand . ' page:' . $page;
	}

	private function format_comment_summary_line( $comment ) {
		$excerpt    = wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 90, '...' );
		$post_title = get_the_title( $comment->comment_post_ID );
		$status     = $this->status_label( $comment );

		return sprintf(
			__( '[%1$d] %2$s on %3$s [%4$s]: %5$s', 'telepilot' ),
			$comment->comment_ID,
			Telepilot_Telegram_Response_Builder::escape( $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'telepilot' ) ),
			Telepilot_Telegram_Response_Builder::escape( $post_title ? $post_title : __( 'Unknown Post', 'telepilot' ) ),
			Telepilot_Telegram_Response_Builder::escape( $status ),
			Telepilot_Telegram_Response_Builder::escape( $excerpt )
		);
	}

	private function status_label( $comment ) {
		$status = wp_get_comment_status( $comment );

		switch ( $status ) {
			case 'approved':
				return __( 'approved', 'telepilot' );
			case 'hold':
			case 'unapproved':
				return __( 'pending', 'telepilot' );
			case 'spam':
				return __( 'spam', 'telepilot' );
			case 'trash':
				return __( 'trash', 'telepilot' );
			default:
				return sanitize_text_field( (string) $status );
		}
	}

	private function normalize_status( $status ) {
		switch ( sanitize_key( $status ) ) {
			case 'approve':
			case 'approved':
				return 'approve';
			case 'hold':
			case 'pending':
				return 'hold';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return 'all';
		}
	}

	private function bump_cache_version() {
		update_option( 'telepilot_comments_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_comments_cache_version', 1 ) );
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
