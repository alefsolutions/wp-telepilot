<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Telepilot_Pages_Command_Handler {
	private function handle_pages( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'edit_pages' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'latest' === $subcommand ) {
			$subcommand = 'list';
		}

		if ( 'list' === $subcommand ) {
			$pages = $this->pages_service->latest_page( $page );
			if ( is_wp_error( $pages ) ) { return Telepilot_Telegram_Response_Builder::error( $pages->get_error_message(), array( 'code' => $pages->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, __( 'Recent Pages', 'wp-telepilot' ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $pages ) {
							return $this->pages_service->build_list_keyboard( $pages['items'], 'list', '', $pages['page'], $pages['total_pages'] );
						},
						'pages_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/pages search keyword`', 'wp-telepilot' ) );
			}

			$pages = $this->pages_service->search_page( $term, $page );

			if ( is_wp_error( $pages ) ) { return Telepilot_Telegram_Response_Builder::error( $pages->get_error_message(), array( 'code' => $pages->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, sprintf( /* translators: %s: search keyword. */ __( 'Page Search: %s', 'wp-telepilot' ), $term ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $pages, $term ) {
							return $this->pages_service->build_list_keyboard( $pages['items'], 'search', $term, $pages['page'], $pages['total_pages'] );
						},
						'pages_search'
					),
				)
			);
		}

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_help_message(),
				array(
					'command'      => '/pages',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( 'drafts' === $subcommand ) {
			$pages = $this->pages_service->drafts_page( $page );
			if ( is_wp_error( $pages ) ) { return Telepilot_Telegram_Response_Builder::error( $pages->get_error_message(), array( 'code' => $pages->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, __( 'Draft Pages', 'wp-telepilot' ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $pages ) {
							return $this->pages_service->build_list_keyboard( $pages['items'], 'drafts', '', $pages['page'], $pages['total_pages'] );
						},
						'pages_drafts'
					),
				)
			);
		}

		if ( 'trashed' === $subcommand ) {
			$pages = $this->pages_service->trashed_page( $page );
			if ( is_wp_error( $pages ) ) { return Telepilot_Telegram_Response_Builder::error( $pages->get_error_message(), array( 'code' => $pages->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, __( 'Trashed Pages', 'wp-telepilot' ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $pages ) {
							return $this->pages_service->build_list_keyboard( $pages['items'], 'trashed', '', $pages['page'], $pages['total_pages'] );
						},
						'pages_trashed'
					),
				)
			);
		}

		if ( in_array( $subcommand, array( 'create', 'new' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$title = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $title ) ) {
				return $this->start_pending_page_creation( $identity );
			}

			return $this->create_page_draft_response( $identity, $title );
		}

		$page_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
		if ( 'details' === $subcommand && $page_id ) {
			$page_item = $this->pages_service->get_page_details( $page_id );
			if ( is_wp_error( $page_item ) ) {
				return Telepilot_Telegram_Response_Builder::error( $page_item->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_details_message( $page_item ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $page_item ) {
							return $this->pages_service->build_list_keyboard( array( $page_item ), 'list', '', 1, 1 );
						},
						'page_details'
					),
				)
			);
		}

		if ( ! $page_id ) {
			return $this->invalid_subcommand_response(
				'/pages',
				$subcommand,
				$this->pages_service->render_help_message(),
				$this->safe_home_keyboard( $identity )
			);
		}

		if ( 'publish' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'publish_pages' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->pages_service->publish( $page_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, 'publish', $result );
			return Telepilot_Telegram_Response_Builder::success( sprintf( /* translators: 1: page ID, 2: action result. */ __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
		}

		if ( 'draft' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->pages_service->draft( $page_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, 'draft', $result );
			return Telepilot_Telegram_Response_Builder::success( sprintf( /* translators: 1: page ID, 2: action result. */ __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
		}

		if ( 'title' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$title = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $title ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/pages title 123 New title`', 'wp-telepilot' ) );
			}

			$result = $this->pages_service->update_title( $page_id, $title );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_updated', 'page', $page_id, 'title', $result );
			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: %d: page ID. */ __( 'Page #%1$d title has been updated.', 'wp-telepilot' ), $page_id ),
				array( 'command' => '/pages' )
			);
		}

		if ( 'slug' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$slug = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $slug ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/pages slug 123 about-us`', 'wp-telepilot' ) );
			}

			$result = $this->pages_service->update_slug( $page_id, $slug );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_updated', 'page', $page_id, 'slug', $result );
			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: %d: page ID. */ __( 'Page #%1$d slug has been updated.', 'wp-telepilot' ), $page_id ),
				array( 'command' => '/pages' )
			);
		}

		if ( 'status' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$status = isset( $args[2] ) ? (string) $args[2] : '';
			if ( '' === $status ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/pages status 123 private`', 'wp-telepilot' ) );
			}

			if ( in_array( sanitize_key( $status ), array( 'publish', 'private' ), true ) ) {
				$permission_result = $this->permission_service->require_capability( $identity, 'publish_pages' );
				if ( true !== $permission_result ) {
					return $permission_result;
				}
			}

			$result = $this->pages_service->set_status( $page_id, $status );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, 'status', $result );
			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: page ID, 2: action result. */ __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ),
				array( 'command' => '/pages' )
			);
		}

		if ( in_array( $subcommand, array( 'trash', 'restore', 'delete' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability(
				$identity,
				'delete' === $subcommand ? 'delete_post' : 'edit_page',
				$page_id
			);
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: requested action, 2: page ID. */ __( 'Confirm %1$s for page #%2$d', 'wp-telepilot' ), $subcommand, $page_id ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $page_id, $subcommand, $identity ) {
							return $this->pages_service->build_action_confirmation_keyboard( $page_id, $subcommand, $identity['telegram_user_id'] );
						},
						'pages_action_confirm'
					),
				)
			);
		}

		return $this->invalid_subcommand_response(
			'/pages',
			$subcommand,
			$this->pages_service->render_help_message(),
			$this->safe_home_keyboard( $identity )
		);
	}
}
