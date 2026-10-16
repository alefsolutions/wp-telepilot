<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Comments_Service {
	const PER_PAGE = 5;

	private $confirmation_service;

	public function filtered_page( $query = '', $status = 'all', $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'filtered_page', get_defined_vars(), function () use ( $query, $status, $page, $limit ) {
			$statuses = array( 'all' => 'all', 'pending' => 'hold', 'approved' => 'approve', 'spam' => 'spam', 'trash' => 'trash' );
			if ( ! isset( $statuses[ $status ] ) ) { return new WP_Error( 'telepilot_invalid_status', 'Unsupported comment status.' ); }
			return $this->query_comments_page( $statuses[ $status ], $query, $page, $limit );
		} );
	}

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function pending_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'pending_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_pending_page( $page, $limit );
		} );
	}

	private function operation_pending_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'hold', '', $page, $limit );
	}

	public function approved_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'approved_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_approved_page( $page, $limit );
		} );
	}

	private function operation_approved_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'approve', '', $page, $limit );
	}

	public function spam_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'spam_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_spam_page( $page, $limit );
		} );
	}

	private function operation_spam_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'spam', '', $page, $limit );
	}

	public function trash_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'trash_page', get_defined_vars(), function () use ( $page, $limit ) {
			return $this->operation_trash_page( $page, $limit );
		} );
	}

	private function operation_trash_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'trash', '', $page, $limit );
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'search_page', get_defined_vars(), function () use ( $term, $page, $limit ) {
			return $this->operation_search_page( $term, $page, $limit );
		} );
	}

	private function operation_search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_comments_page( 'all', sanitize_text_field( $term ), $page, $limit );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'comments', $heading ) ) . "\n\n" . __( 'No comments matched that request.', 'wp-telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'comments', $heading ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( /* translators: 1: current page number, 2: total number of pages, 3: total number of items. */ __( 'Page %1$d of %2$d | %3$d items', 'wp-telepilot' ), $result['page'], $result['total_pages'], isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $result['items'] ) )
		);

		foreach ( $result['items'] as $comment ) {
			$blocks[] = $this->format_comment_summary_line( $comment );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use details for the full context, and keep destructive moderation actions in private chat.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'comments', __( 'Comments Commands', 'wp-telepilot' ) ) ),
				Telepilot_Telegram_Response_Builder::code( '/comments pending' ) . ' ' . __( 'Show pending comments', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments approved' ) . ' ' . __( 'Show approved comments', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments spam' ) . ' ' . __( 'Show spam comments', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments trash' ) . ' ' . __( 'Show trashed comments', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments search keyword' ) . ' ' . __( 'Search comments by author, email, URL, or content', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments details 123' ) . ' ' . __( 'Show comment details', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments approve 123' ) . ' ' . __( 'Approve a comment', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments reject 123' ) . ' ' . __( 'Move a comment back to moderation hold', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments spam 123' ) . ' ' . __( 'Mark a comment as spam', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments trash 123' ) . ' ' . __( 'Move a comment to trash', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments restore 123' ) . ' ' . __( 'Restore a trashed comment', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments unspam 123' ) . ' ' . __( 'Remove a comment from spam', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments delete 123' ) . ' ' . __( 'Permanently delete a comment after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/comments reply 123 Thank you for your comment' ) . ' ' . __( 'Post an approved reply to a comment', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Tip: pagination uses the same `page:N` suffix as other WP Telepilot list commands.', 'wp-telepilot' ) ),
			)
		);
	}

	public function render_comment_details_message( $comment ) {
		if ( ! ( $comment instanceof WP_Comment ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'comments', __( 'Comment Details', 'wp-telepilot' ) ) ) . "\n\n" . __( 'Comment not found.', 'wp-telepilot' );
		}

		$post_title = get_the_title( $comment->comment_post_ID );
		$post_url   = get_permalink( $comment->comment_post_ID );
		$status     = $this->status_label( $comment );
		$admin_url  = admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID );
		$content    = trim( wp_strip_all_tags( $comment->comment_content ) );
		$content    = wp_html_excerpt( $content, 900, '...' );

		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'comments', __( 'Comment Details', 'wp-telepilot' ) ) );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %d: comment ID. */ __( 'Comment: [%d]', 'wp-telepilot' ), $comment->comment_ID );
		$lines[] = sprintf( /* translators: %s: current status. */ __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $status ) );
		$lines[] = sprintf( /* translators: %s: comment author. */ __( 'Author: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ) ) );

		if ( '' !== (string) $comment->comment_author_email ) {
			$lines[] = sprintf( /* translators: %s: comment author email. */ __( 'Email: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $comment->comment_author_email ) );
		}

		$lines[] = sprintf( /* translators: %s: post title. */ __( 'Post: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $post_title ? $post_title : __( 'Unknown Post', 'wp-telepilot' ) ) );
		$lines[] = sprintf( /* translators: %s: comment. */ __( 'Date: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_comment_date( 'Y-m-d H:i:s', $comment ) ) );
		$lines[] = sprintf( /* translators: %s: formatted link to the requested admin or preview page. */ __( 'Admin: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open comment in wp-admin', 'wp-telepilot' ), $admin_url ) );

		if ( $post_url ) {
			$lines[] = sprintf( /* translators: %s: formatted link to the requested admin or preview page. */ __( 'Post: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open post', 'wp-telepilot' ), $post_url ) );
		}

		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'details', __( 'Content', 'wp-telepilot' ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::escape( $content ? $content : __( 'No visible content.', 'wp-telepilot' ) );

		return implode( "\n", $lines );
	}

	public function get_comment_details( $comment_id ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'get_comment_details', get_defined_vars(), function () use ( $comment_id ) {
			return $this->operation_get_comment_details( $comment_id );
		} );
	}

	private function operation_get_comment_details( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'wp-telepilot' ) );
		}

		return $comment;
	}

	public function moderate_comment( $comment_id, $action ) {
		return Telepilot_Domain_Abilities::run( 'comments', 'moderate_comment', get_defined_vars(), function () use ( $comment_id, $action ) {
			return $this->operation_moderate_comment( $comment_id, $action );
		} );
	}

	private function operation_moderate_comment( $comment_id, $action ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'wp-telepilot' ) );
		}

		$before_status = wp_get_comment_status( $comment );

		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment_id, 'approve' );
				$label  = __( 'approved', 'wp-telepilot' );
				break;

			case 'reject':
				$result = wp_set_comment_status( $comment_id, 'hold' );
				$label  = __( 'moved to moderation hold', 'wp-telepilot' );
				break;

			case 'trash':
				$result = wp_trash_comment( $comment_id );
				$label  = __( 'trashed', 'wp-telepilot' );
				break;

			case 'spam':
				$result = wp_spam_comment( $comment_id );
				$label  = __( 'marked as spam', 'wp-telepilot' );
				break;

			case 'restore':
				$result = wp_untrash_comment( $comment_id );
				$label  = __( 'restored', 'wp-telepilot' );
				break;

			case 'unspam':
				$result = wp_unspam_comment( $comment_id );
				$label  = __( 'removed from spam', 'wp-telepilot' );
				break;

			case 'delete':
				$result = wp_delete_comment( $comment_id, true );
				$label  = __( 'deleted', 'wp-telepilot' );
				break;

			default:
				return new WP_Error( 'telepilot_invalid_comment_action', __( 'Unsupported comment action.', 'wp-telepilot' ) );
		}

		if ( false === $result || null === $result ) {
			return new WP_Error( 'telepilot_comment_update_failed', __( 'WordPress could not update that comment.', 'wp-telepilot' ) );
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
		return Telepilot_Domain_Abilities::run( 'comments', 'reply_to_comment', get_defined_vars(), function () use ( $comment_id, $content, $wp_user ) {
			return $this->operation_reply_to_comment( $comment_id, $content, $wp_user );
		} );
	}

	private function operation_reply_to_comment( $comment_id, $content, $wp_user ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepilot_comment_not_found', __( 'Comment not found.', 'wp-telepilot' ) );
		}

		if ( ! ( $wp_user instanceof WP_User ) ) {
			return new WP_Error( 'telepilot_reply_user_invalid', __( 'A valid WordPress user is required to reply.', 'wp-telepilot' ) );
		}

		$content = trim( (string) $content );
		if ( '' === $content ) {
			return new WP_Error( 'telepilot_reply_content_required', __( 'Reply content is required.', 'wp-telepilot' ) );
		}

		$reply_id = wp_insert_comment(
			wp_slash( array(
				'comment_post_ID'      => (int) $comment->comment_post_ID,
				'comment_parent'       => (int) $comment_id,
				'user_id'              => (int) $wp_user->ID,
				'comment_author'       => $wp_user->display_name ? $wp_user->display_name : $wp_user->user_login,
				'comment_author_email' => $wp_user->user_email,
				'comment_author_url'   => $wp_user->user_url,
				'comment_content'      => wp_kses_post( $content ),
				'comment_approved'     => 1,
				'comment_type'         => '',
			) )
		);

		if ( ! $reply_id ) {
			return new WP_Error( 'telepilot_reply_insert_failed', __( 'WordPress could not create that reply.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'comment'       => get_comment( $comment_id ),
			'reply'         => get_comment( $reply_id ),
			'before_status' => wp_get_comment_status( $comment ),
			'after_status'  => wp_get_comment_status( $comment ),
			'label'         => __( 'reply posted', 'wp-telepilot' ),
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
					'text'          => Telepilot_Telegram_Response_Builder::label( 'details', sprintf( /* translators: %d: comment ID. */ __( 'Details [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments details ' . (int) $comment->comment_ID,
				),
			);

			if ( in_array( $status, array( 'hold', 'unapproved' ), true ) ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'enable', sprintf( /* translators: %d: comment ID. */ __( 'Approve [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments approve ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'comments', sprintf( /* translators: %d: comment ID. */ __( 'Spam [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments spam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( /* translators: %d: comment ID. */ __( 'Trash [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments trash ' . (int) $comment->comment_ID,
				);
			} elseif ( 'approved' === $status ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'disable', sprintf( /* translators: %d: comment ID. */ __( 'Reject [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments reject ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'comments', sprintf( /* translators: %d: comment ID. */ __( 'Spam [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments spam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'trash', sprintf( /* translators: %d: comment ID. */ __( 'Trash [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments trash ' . (int) $comment->comment_ID,
				);
			} elseif ( 'spam' === $status ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'restore', sprintf( /* translators: %d: comment ID. */ __( 'Unspam [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments unspam ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( /* translators: %d: comment ID. */ __( 'Delete [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments delete ' . (int) $comment->comment_ID,
				);
			} elseif ( 'trash' === $status ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'restore', sprintf( /* translators: %d: comment ID. */ __( 'Restore [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
					'callback_data' => '/comments restore ' . (int) $comment->comment_ID,
				);
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( /* translators: %d: comment ID. */ __( 'Delete [%d]', 'wp-telepilot' ), $comment->comment_ID ) ),
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
				sprintf( /* translators: 1: requested action, 2: comment ID. */ __( 'Confirm %1$s [%2$d]', 'wp-telepilot' ), ucfirst( $action ), $comment_id ),
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

		if ( 'search' === $subcommand ) { return Telepilot_Pagination_Service::search_row( 'comments', $search_term, $page, $total_pages ); }

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
		return '/comments ' . $subcommand . ' page:' . $page;
	}

	private function format_comment_summary_line( $comment ) {
		$excerpt    = wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 90, '...' );
		$post_title = get_the_title( $comment->comment_post_ID );
		$status     = $this->status_label( $comment );

		return implode(
			"\n",
			array(
				Telepilot_Telegram_Response_Builder::label(
					'comments',
					sprintf(
						/* translators: 1: comment ID, 2: comment author. */ __( '[%1$d] %2$s', 'wp-telepilot' ),
						$comment->comment_ID,
						Telepilot_Telegram_Response_Builder::escape( $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ) )
					)
				),
				sprintf( /* translators: %s: current status. */ __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( ucfirst( $status ) ) ),
				sprintf( /* translators: %s: post title. */ __( 'Post: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $post_title ? $post_title : __( 'Unknown Post', 'wp-telepilot' ) ) ),
				sprintf( /* translators: %s: comment. */ __( 'Date: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( get_comment_date( 'Y-m-d H:i:s', $comment ) ) ),
				sprintf( /* translators: %s: excerpt. */ __( 'Excerpt: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $excerpt ? $excerpt : __( 'No visible content.', 'wp-telepilot' ) ) ),
			)
		);
	}

	private function status_label( $comment ) {
		$status = wp_get_comment_status( $comment );

		switch ( $status ) {
			case 'approved':
				return __( 'approved', 'wp-telepilot' );
			case 'hold':
			case 'unapproved':
				return __( 'pending', 'wp-telepilot' );
			case 'spam':
				return __( 'spam', 'wp-telepilot' );
			case 'trash':
				return __( 'trash', 'wp-telepilot' );
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
