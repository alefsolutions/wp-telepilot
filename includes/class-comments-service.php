<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Comments_Service {
	private $confirmation_service;

	public function __construct( TelePress_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function list_pending( $limit = 5 ) {
		$comments = get_comments(
			array(
				'status' => 'hold',
				'number' => max( 1, absint( $limit ) ),
				'orderby'=> 'comment_date_gmt',
				'order'  => 'DESC',
			)
		);

		return $comments;
	}

	public function render_pending_message( $comments ) {
		if ( empty( $comments ) ) {
			return __( "Pending Comments\nNo comments are waiting for moderation.", 'telepress' );
		}

		$lines   = array();
		$lines[] = __( 'Pending Comments', 'telepress' );

		foreach ( $comments as $comment ) {
			$excerpt   = wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 70, '...' );
			$post_title = get_the_title( $comment->comment_post_ID );
			$lines[]   = sprintf(
				/* translators: 1: comment id, 2: author, 3: post title, 4: excerpt. */
				__( '#%1$d by %2$s on %3$s: %4$s', 'telepress' ),
				$comment->comment_ID,
				$comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'telepress' ),
				$post_title ? $post_title : __( 'Unknown Post', 'telepress' ),
				$excerpt
			);
		}

		return implode( "\n", $lines );
	}

	public function build_pending_keyboard( $comments, $telegram_user_id ) {
		$rows = array();

		foreach ( $comments as $comment ) {
			$approve_token = $this->confirmation_service->create_token(
				array(
					'action'           => 'approve',
					'comment_id'       => (int) $comment->comment_ID,
					'telegram_user_id' => (string) $telegram_user_id,
				)
			);
			$reject_token = $this->confirmation_service->create_token(
				array(
					'action'           => 'reject',
					'comment_id'       => (int) $comment->comment_ID,
					'telegram_user_id' => (string) $telegram_user_id,
				)
			);
			$spam_token = $this->confirmation_service->create_token(
				array(
					'action'           => 'spam',
					'comment_id'       => (int) $comment->comment_ID,
					'telegram_user_id' => (string) $telegram_user_id,
				)
			);
			$trash_token = $this->confirmation_service->create_token(
				array(
					'action'           => 'trash',
					'comment_id'       => (int) $comment->comment_ID,
					'telegram_user_id' => (string) $telegram_user_id,
				)
			);

			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Approve #%d', 'telepress' ), $comment->comment_ID ),
					'callback_data' => 'tp:comment:approve:' . (int) $comment->comment_ID . ':' . $approve_token,
				),
			);
			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Reject #%d', 'telepress' ), $comment->comment_ID ),
					'callback_data' => 'tp:comment:reject:' . (int) $comment->comment_ID . ':' . $reject_token,
				),
				array(
					'text'          => sprintf( __( 'Spam #%d', 'telepress' ), $comment->comment_ID ),
					'callback_data' => 'tp:comment:spam:' . (int) $comment->comment_ID . ':' . $spam_token,
				),
				array(
					'text'          => sprintf( __( 'Trash #%d', 'telepress' ), $comment->comment_ID ),
					'callback_data' => 'tp:comment:trash:' . (int) $comment->comment_ID . ':' . $trash_token,
				),
			);
		}

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	public function moderate_comment( $comment_id, $action ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'telepress_comment_not_found', __( 'Comment not found.', 'telepress' ) );
		}

		$before_status = wp_get_comment_status( $comment );

		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment_id, 'approve' );
				$label  = __( 'approved', 'telepress' );
				break;

			case 'reject':
				$result = wp_set_comment_status( $comment_id, 'hold' );
				$label  = __( 'rejected', 'telepress' );
				break;

			case 'trash':
				$result = wp_trash_comment( $comment_id );
				$label  = __( 'trashed', 'telepress' );
				break;

			case 'spam':
				$result = wp_spam_comment( $comment_id );
				$label  = __( 'marked as spam', 'telepress' );
				break;

			default:
				return new WP_Error( 'telepress_invalid_comment_action', __( 'Unsupported comment action.', 'telepress' ) );
		}

		if ( false === $result ) {
			return new WP_Error( 'telepress_comment_update_failed', __( 'WordPress could not update that comment.', 'telepress' ) );
		}

		return array(
			'comment'       => get_comment( $comment_id ),
			'before_status' => $before_status,
			'after_status'  => wp_get_comment_status( $comment_id ),
			'label'         => $label,
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

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm %1$s #%2$d', 'telepress' ), ucfirst( $action ), $comment_id ),
							'callback_data' => 'tp:comment:' . $action . ':' . (int) $comment_id . ':' . $token,
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
