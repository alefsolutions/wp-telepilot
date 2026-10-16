<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Telepilot_Posts_Command_Handler {
	private function handle_posts( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'edit_posts' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'draft' === $subcommand ) {
			$subcommand = 'unpublish';
		}

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_help_message(),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() {
							return $this->posts_service->build_hub_keyboard();
						},
						'posts_help'
					),
				)
			);
		}

		if ( in_array( $subcommand, array( 'list', 'latest' ), true ) ) {
			$result = $this->posts_service->latest_page( $page );
			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $result, __( 'Latest Posts', 'wp-telepilot' ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->posts_service->build_list_keyboard( $result['items'], 'latest', '', $result['page'], $result['total_pages'] );
						},
						'posts_list'
					),
				)
			);
		}

		if ( 'drafts' === $subcommand ) {
			$result = $this->posts_service->drafts_page( $page );
			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $result, __( 'Draft Posts', 'wp-telepilot' ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->posts_service->build_list_keyboard( $result['items'], 'drafts', '', $result['page'], $result['total_pages'] );
						},
						'posts_drafts'
					),
				)
			);
		}

		if ( 'trashed' === $subcommand ) {
			$result = $this->posts_service->trashed_page( $page );
			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $result, __( 'Trashed Posts', 'wp-telepilot' ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->posts_service->build_list_keyboard( $result['items'], 'trashed', '', $result['page'], $result['total_pages'] );
						},
						'posts_trashed'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts search keyword`', 'wp-telepilot' ) );
			}

			$posts = $this->posts_service->search_page( $term, $page );

			if ( is_wp_error( $posts ) ) { return Telepilot_Telegram_Response_Builder::error( $posts->get_error_message(), array( 'code' => $posts->get_error_code() ) ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $posts, sprintf( /* translators: %s: search keyword. */ __( 'Post Search: %s', 'wp-telepilot' ), $term ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $posts, $term ) {
							return $this->posts_service->build_list_keyboard( $posts['items'], 'search', $term, $posts['page'], $posts['total_pages'] );
						},
						'posts_search'
					),
				)
			);
		}

		if ( 'stats' === $subcommand ) {
			$stats = $this->posts_service->stats();
			if ( is_wp_error( $stats ) ) { return Telepilot_Telegram_Response_Builder::error( $stats->get_error_message() ); }
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_stats_message( $stats ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() {
							return $this->posts_service->build_hub_keyboard();
						},
						'posts_stats'
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
				return $this->start_pending_post_creation( $identity );
			}

			return $this->create_post_and_open_category_picker( $identity, $title, array() );
		}

		$post_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
		if ( ! $post_id ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_help_message(),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() {
							return $this->posts_service->build_hub_keyboard();
						},
						'posts_default'
					),
				)
			);
		}

		if ( in_array( $subcommand, array( 'open', 'edit' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$link = $this->post_editor_service->create_edit_link( $post_id, $identity['wp_user'], $identity['telegram_user_id'] );
			if ( is_wp_error( $link ) ) {
				return Telepilot_Telegram_Response_Builder::error( $link->get_error_message() );
			}

			$post        = ! empty( $link['post'] ) ? $link['post'] : get_post( $post_id );
			$preview_url = $post && 'publish' === $post->post_status ? get_permalink( $post ) : '';

			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'post_browser_editor_link_generated',
					'resource_type'    => 'post',
					'resource_id'      => (string) $post_id,
					'after_state'      => array(
						'expires_at' => ! empty( $link['expires_at'] ) ? (int) $link['expires_at'] : 0,
					),
				)
			);

			$message_lines   = array();
			$message_lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'Post Editor Ready', 'wp-telepilot' ) );
			$message_lines[] = '';
			$message_lines[] = sprintf(
				/* translators: 1: post ID, 2: post title. */ __( 'Post: [%1$d] %2$s', 'wp-telepilot' ),
				$post_id,
				Telepilot_Telegram_Response_Builder::escape( $post ? get_the_title( $post ) : __( 'Post', 'wp-telepilot' ) )
			);
			$message_lines[] = sprintf(
				/* translators: %s: formatted link to the secure editor. */ __( 'Editor link: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::link( __( 'Open secure editor', 'wp-telepilot' ), $link['url'] )
			);
			$message_lines[] = sprintf(
				/* translators: %s: formatted date and time. */ __( 'Expires: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( wp_date( 'Y-m-d H:i:s T', (int) $link['expires_at'] ) )
			);
			if ( $preview_url ) {
				$message_lines[] = sprintf(
					/* translators: %s: formatted link to the requested admin or preview page. */ __( 'Preview: %s', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::link( __( 'Open published post', 'wp-telepilot' ), $preview_url )
				);
			}
			$message_lines[] = '';
			$message_lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Use the browser editor for long-form content, then come back to Telegram to continue your workflow.', 'wp-telepilot' ) );

			return Telepilot_Telegram_Response_Builder::success_html(
				implode( "\n", $message_lines ),
				array(
					'command' => '/posts',
				)
			);
		}

		if ( 'publish' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'publish_posts' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->posts_service->publish( $post_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_state_changed', 'post', $post_id, 'publish', $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: post ID, 2: action result. */ __( 'Post #%1$d has been %2$s.', 'wp-telepilot' ), $post_id, $result['label'] ),
				array( 'command' => '/posts' )
			);
		}

		if ( 'title' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$title = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $title ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts title 123 New title`', 'wp-telepilot' ) );
			}

			$result = $this->posts_service->update_title( $post_id, $title );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_updated', 'post', $post_id, 'title', $result );

			return Telepilot_Telegram_Response_Builder::success_html(
				Telepilot_Telegram_Response_Builder::bold( __( 'Post Title Updated', 'wp-telepilot' ) ) .
				"\n\n" .
				sprintf( /* translators: 1: post ID, 2: post title. */ __( 'Post: [%1$d] %2$s', 'wp-telepilot' ), $post_id, Telepilot_Telegram_Response_Builder::escape( get_the_title( $result['post'] ) ) ),
				array( 'command' => '/posts' )
			);
		}

		if ( 'excerpt' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$excerpt = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $excerpt ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts excerpt 123 New excerpt`', 'wp-telepilot' ) );
			}

			$result = $this->posts_service->update_excerpt( $post_id, $excerpt );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_updated', 'post', $post_id, 'excerpt', $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: %d: post ID. */ __( 'Post #%1$d excerpt has been updated.', 'wp-telepilot' ), $post_id ),
				array( 'command' => '/posts' )
			);
		}

		if ( in_array( $subcommand, array( 'categories', 'tags' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( 'categories' === $subcommand && ( ! isset( $args[2] ) || '' === trim( (string) $args[2] ) ) ) {
				return $this->open_post_category_picker( $post_id, $identity );
			}

			$term_ids = $this->parse_id_list( isset( $args[2] ) ? (string) $args[2] : '' );
			if ( empty( $term_ids ) && 'none' !== strtolower( isset( $args[2] ) ? (string) $args[2] : '' ) ) {
				return Telepilot_Telegram_Response_Builder::error(
					'categories' === $subcommand
						? __( 'Usage: `/posts categories 123 4,8`', 'wp-telepilot' )
						: __( 'Usage: `/posts tags 123 5,9`', 'wp-telepilot' )
				);
			}

			$taxonomy = 'categories' === $subcommand ? 'category' : 'post_tag';
			$result   = $this->posts_service->assign_terms( $post_id, $taxonomy, $term_ids );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_updated', 'post', $post_id, $subcommand, $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: post ID, 2: requested action. */ __( 'Post #%1$d %2$s have been updated.', 'wp-telepilot' ), $post_id, 'categories' === $subcommand ? __( 'categories', 'wp-telepilot' ) : __( 'tags', 'wp-telepilot' ) ),
				array( 'command' => '/posts' )
			);
		}

		if ( 'schedule' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'publish_posts' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$scheduled_at = $this->parse_site_datetime( implode( ' ', array_slice( $args, 2 ) ) );
			if ( ! $scheduled_at ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts schedule 123 2026-07-20 14:30`', 'wp-telepilot' ) );
			}

			$result = $this->posts_service->schedule( $post_id, $scheduled_at );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_state_changed', 'post', $post_id, 'schedule', $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: post ID, 2: scheduled at. */ __( 'Post #%1$d has been scheduled for %2$s.', 'wp-telepilot' ), $post_id, $result['scheduled_at'] ),
				array( 'command' => '/posts' )
			);
		}

		if ( 'unpublish' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: %d: post ID. */ __( 'Confirm draft for post [%d]', 'wp-telepilot' ), $post_id ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $post_id, $identity ) {
							return $this->posts_service->build_action_confirmation_keyboard( $post_id, 'unpublish', $identity['telegram_user_id'] );
						},
						'posts_unpublish_confirm'
					),
				)
			);
		}

		if ( in_array( $subcommand, array( 'trash', 'restore', 'delete' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$capability = 'restore' === $subcommand ? 'delete_post' : 'delete_post';
			$permission_result = $this->permission_service->require_capability( $identity, $capability, $post_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( /* translators: 1: requested action, 2: post ID. */ __( 'Confirm %1$s for post [%2$d]', 'wp-telepilot' ), $subcommand, $post_id ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $post_id, $subcommand, $identity ) {
							return $this->posts_service->build_action_confirmation_keyboard( $post_id, $subcommand, $identity['telegram_user_id'] );
						},
						'posts_action_confirm'
					),
				)
			);
		}

		return $this->invalid_subcommand_response(
			'/posts',
			$subcommand,
			$this->posts_service->render_help_message(),
			$this->safe_reply_markup(
				function() {
					return $this->posts_service->build_hub_keyboard();
				},
				'posts_invalid'
			)
		);
	}
}
