<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Command_Router {
	private $linking_service;
	private $permission_service;
	private $dashboard_service;
	private $comments_service;
	private $posts_service;
	private $pages_service;
	private $media_service;
	private $users_service;
	private $taxonomies_service;
	private $confirmation_service;

	public function __construct( TelePress_User_Linking_Service $linking_service, TelePress_Permission_Service $permission_service, TelePress_Dashboard_Service $dashboard_service, TelePress_Comments_Service $comments_service, TelePress_Posts_Service $posts_service, TelePress_Pages_Service $pages_service, TelePress_Media_Service $media_service, TelePress_Users_Service $users_service, TelePress_Taxonomies_Service $taxonomies_service, TelePress_Confirmation_Service $confirmation_service ) {
		$this->linking_service    = $linking_service;
		$this->permission_service = $permission_service;
		$this->dashboard_service  = $dashboard_service;
		$this->comments_service   = $comments_service;
		$this->posts_service      = $posts_service;
		$this->pages_service      = $pages_service;
		$this->media_service      = $media_service;
		$this->users_service      = $users_service;
		$this->taxonomies_service = $taxonomies_service;
		$this->confirmation_service = $confirmation_service;
	}

	public function route( $update, $identity ) {
		$command = $this->parse_command( $update );

		if ( empty( $command['name'] ) ) {
			return TelePress_Telegram_Response_Builder::success(
				__( 'Webhook received. No supported command was detected yet.', 'telepress' ),
				array(
					'command' => '',
				)
			);
		}

		switch ( $command['name'] ) {
			case '/start':
				return $this->handle_start( $identity );

			case '/help':
				return $this->handle_help( $identity );

			case '/menu':
				return $this->handle_menu( $identity );

			case '/settings':
				return $this->handle_settings( $identity );

			case '/link':
				return $this->handle_link( $command, $update );

			case '/unlink':
				return $this->handle_unlink( $identity );

			case '/chatid':
				return $this->handle_chat_id( $identity );

			case '/site':
			case '/dashboard':
				return $this->handle_dashboard( $identity );

			case '/comments':
				return $this->handle_comments( $command, $identity );

			case 'tp:comment':
				return $this->handle_comment_callback( $command, $identity );

			case '/posts':
				return $this->handle_posts( $command, $identity );

			case 'tp:post':
				return $this->handle_post_callback( $command, $identity );

			case '/pages':
				return $this->handle_pages( $command, $identity );

			case 'tp:page':
				return $this->handle_page_callback( $command, $identity );

			case '/media':
				return $this->handle_media( $command, $identity );

			case 'tp:media':
				return $this->handle_media_callback( $command, $identity );

			case '/users':
				return $this->handle_users( $command, $identity );

			case 'tp:user':
				return $this->handle_user_callback( $command, $identity );

			case '/categories':
				return $this->handle_terms( 'category', 'categories', $command, $identity );

			case '/tags':
				return $this->handle_terms( 'post_tag', 'tags', $command, $identity );

			case 'tp:term':
				return $this->handle_term_callback( $command, $identity );

			default:
				return TelePress_Telegram_Response_Builder::success(
					sprintf(
						/* translators: %s: command name. */
						__( 'Command `%s` is recognized, but its handler is not implemented yet.', 'telepress' ),
						$command['name']
					),
					array(
						'command' => $command['name'],
					)
				);
		}
	}

	public function parse_command( $update ) {
		$text = '';

		if ( isset( $update['message']['text'] ) ) {
			$text = trim( wp_strip_all_tags( $update['message']['text'] ) );
		} elseif ( isset( $update['callback_query']['data'] ) ) {
			$text = trim( wp_strip_all_tags( $update['callback_query']['data'] ) );
		}

		if ( '' === $text ) {
			return array(
				'raw'  => '',
				'name' => '',
				'args' => array(),
			);
		}

		if ( 0 === strpos( $text, 'tp:' ) ) {
			$parts = explode( ':', $text );
			$name  = isset( $parts[1] ) ? 'tp:' . $parts[1] : '';

			return array(
				'raw'  => $text,
				'name' => $name,
				'args' => array_slice( $parts, 2 ),
			);
		}

		$parts = preg_split( '/\s+/', $text );
		$name  = isset( $parts[0] ) ? strtolower( (string) $parts[0] ) : '';
		$args  = array_slice( $parts, 1 );

		return array(
			'raw'  => $text,
			'name' => $name,
			'args' => $args,
		);
	}

	private function handle_start( $identity ) {
		$chat_id = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'telepress' );

		$message = sprintf(
			/* translators: %s: Telegram chat ID. */
			__(
				"TelePress is connected.\n\nYour current chat ID: %s\n\nNext steps:\n1. Add this chat ID to TelePress Allowed Chat IDs if you are using an allow list.\n2. Generate a one-time link code from your WordPress profile.\n3. Send /link CODE here to connect your Telegram account.\n\nUse /help to view commands.",
				'telepress'
			),
			$chat_id
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$message = sprintf(
				/* translators: 1: WordPress display name, 2: Telegram chat ID. */
				__( "TelePress is connected to WordPress user %1$s.\n\nCurrent chat ID: %2$s\n\nUse /menu to open the command hub or /site to view your site overview.", 'telepress' ),
				$identity['wp_user']->display_name,
				$chat_id
			);
		}

		return TelePress_Telegram_Response_Builder::success(
			$message,
			array(
				'command'      => '/start',
				'reply_markup' => $this->build_home_keyboard( $identity ),
			)
		);
	}

	private function handle_help( $identity ) {
		$commands = array();

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			$commands[] = __( 'Setup flow:', 'telepress' );
			$commands[] = '/start';
			$commands[] = '/chatid';
			$commands[] = '/link CODE';
			$commands[] = '';
		}

		$commands[] = __( 'Available now:', 'telepress' );
		$commands[] = '/start';
		$commands[] = '/help';
		$commands[] = '/menu';
		$commands[] = '/site';
		$commands[] = '/chatid';
		$commands[] = '/link CODE';

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$commands[] = '/unlink';
			$commands[] = '/settings';
			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$commands[] = '/comments pending';
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$commands[] = '/posts latest';
				$commands[] = '/posts search keyword';
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_pages' ) ) {
				$commands[] = '/pages list';
				$commands[] = '/pages search keyword';
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'upload_files' ) ) {
				$commands[] = '/media recent';
				$commands[] = '/media search keyword';
			}

			$future_commands = array();

			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$commands[] = '/users list';
				$commands[] = '/users search keyword';
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_categories' ) ) {
				$commands[] = '/categories list';
				$commands[] = '/categories search keyword';
				$commands[] = '/tags list';
				$commands[] = '/tags search keyword';
			}

			if ( ! empty( $future_commands ) ) {
				$commands[] = '';
				$commands[] = __( 'Coming next in the MVP:', 'telepress' );
				$commands   = array_merge( $commands, $future_commands );
			}
		}

		return TelePress_Telegram_Response_Builder::success(
			implode( "\n", $commands ),
			array(
				'command'      => '/help',
				'reply_markup' => $this->build_home_keyboard( $identity ),
			)
		);
	}

	private function handle_menu( $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'read' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		return TelePress_Telegram_Response_Builder::success(
			__(
				"TelePress Menu\nChoose an area below. Use Telegram for quick review and short actions, then jump into WordPress when you need full editing.",
				'telepress'
			),
			array(
				'command'      => '/menu',
				'reply_markup' => $this->build_home_keyboard( $identity ),
			)
		);
	}

	private function handle_settings( $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'manage_options' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$settings = get_option( 'telepress_settings', array() );
		$url      = admin_url( 'admin.php?page=telepress' );

		return TelePress_Telegram_Response_Builder::success(
			sprintf(
				__(
					"TelePress Settings\nAdmin URL: %1$s\nTransport: %2$s\nLinking: %3$s",
					'telepress'
				),
				$url,
				! empty( $settings['transport_mode'] ) ? ucfirst( (string) $settings['transport_mode'] ) : __( 'Webhook', 'telepress' ),
				! empty( $settings['linking_enabled'] ) ? __( 'Enabled', 'telepress' ) : __( 'Disabled', 'telepress' )
			),
			array(
				'command'      => '/settings',
				'reply_markup' => $this->build_home_keyboard( $identity ),
			)
		);
	}

	private function handle_chat_id( $identity ) {
		$chat_id          = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'telepress' );
		$telegram_user_id = ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : __( 'Unavailable', 'telepress' );

		return TelePress_Telegram_Response_Builder::success(
			sprintf(
				/* translators: 1: Telegram chat ID, 2: Telegram user ID. */
				__( "Current chat ID: %1$s\nTelegram user ID: %2$s\n\nAdd the chat ID to TelePress Allowed Chat IDs if you want this conversation to be authorized.", 'telepress' ),
				$chat_id,
				$telegram_user_id
			),
			array(
				'command' => '/chatid',
			)
		);
	}

	private function handle_link( $command, $update ) {
		$private_chat_result = $this->require_private_action(
			array(
				'chat_type' => isset( $update['message']['chat']['type'] ) ? sanitize_key( $update['message']['chat']['type'] ) : '',
			)
		);

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		if ( empty( $command['args'][0] ) ) {
			return TelePress_Telegram_Response_Builder::error(
				__( 'Usage: `/link CODE`', 'telepress' ),
				array(
					'command' => '/link',
				)
			);
		}

		$message = isset( $update['message'] ) ? $update['message'] : array();
		$result  = $this->linking_service->consume_link_code( $command['args'][0], $message );

		$diagnostics = get_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$diagnostics = array_merge(
			$diagnostics,
			array(
				'last_link_attempt_at'     => time(),
				'last_link_attempt_status' => ! empty( $result['ok'] ) ? 'success' : 'failed',
				'last_link_attempt_detail' => isset( $result['message'] ) ? sanitize_text_field( wp_strip_all_tags( $result['message'] ) ) : '',
			)
		);
		update_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION, $diagnostics, false );

		return $result;
	}

	private function handle_unlink( $identity ) {
		$private_chat_result = $this->require_private_action( $identity );

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$link_result = $this->permission_service->require_linked_user( $identity );

		if ( true !== $link_result ) {
			return $link_result;
		}

		$result = $this->linking_service->unlink_user( $identity['wp_user']->ID );

		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'telegram_account_unlinked',
				'resource_type'    => 'user',
				'resource_id'      => (string) $identity['wp_user']->ID,
			)
		);

		return TelePress_Telegram_Response_Builder::success(
			__( 'Telegram has been unlinked from your WordPress account.', 'telepress' ),
			array(
				'command' => '/unlink',
			)
		);
	}

	private function handle_dashboard( $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'read' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$summary = $this->dashboard_service->get_summary();

		return TelePress_Telegram_Response_Builder::success(
			$this->dashboard_service->render_summary_message( $summary ),
			array(
				'command'      => '/site',
				'data'         => $summary,
				'reply_markup' => $this->dashboard_service->build_overview_keyboard( $identity['wp_user'] ),
			)
		);
	}

	private function handle_comments( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'moderate_comments' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$subcommand = ! empty( $command['args'][0] ) ? strtolower( (string) $command['args'][0] ) : 'pending';

		if ( 'pending' === $subcommand ) {
			$comments = $this->comments_service->list_pending( 5 );

			return TelePress_Telegram_Response_Builder::success(
				$this->comments_service->render_pending_message( $comments ),
				array(
					'command'      => '/comments',
					'reply_markup' => $this->comments_service->build_pending_keyboard( $comments, $identity['telegram_user_id'] ),
				)
			);
		}

		$private_chat_result = $this->require_private_action( $identity );

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$comment_id = ! empty( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;

		if ( ! in_array( $subcommand, array( 'approve', 'reject', 'spam', 'trash' ), true ) || ! $comment_id ) {
			return TelePress_Telegram_Response_Builder::error(
				__( 'Supported comments commands: `/comments pending`, `/comments approve 123`, `/comments reject 123`, `/comments spam 123`, `/comments trash 123`', 'telepress' ),
				array(
					'command' => '/comments',
				)
			);
		}

		if ( 'approve' === $subcommand ) {
			$result = $this->comments_service->moderate_comment( $comment_id, $subcommand );

			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_comment_moderation( $identity, $comment_id, $subcommand, $result );

			return TelePress_Telegram_Response_Builder::success(
				sprintf(
					__( 'Comment #%1$d has been %2$s.', 'telepress' ),
					$comment_id,
					$result['label']
				),
				array(
					'command' => '/comments',
				)
			);
		}

		return TelePress_Telegram_Response_Builder::success(
			sprintf(
				__( 'Confirm moderation for comment #%1$d: %2$s', 'telepress' ),
				$comment_id,
				$subcommand
			),
			array(
				'command'      => '/comments',
				'reply_markup' => $this->comments_service->build_action_confirmation_keyboard( $comment_id, $subcommand, $identity['telegram_user_id'] ),
			)
		);
	}

	private function handle_comment_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$permission_result = $this->permission_service->require_capability( $identity, 'moderate_comments' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$action     = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$comment_id = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token      = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		if ( ! $comment_id || '' === $token ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That moderation action is incomplete.', 'telepress' ) );
		}

		$payload = $this->confirmation_service->consume_token( $token );

		if ( empty( $payload ) || empty( $payload['telegram_user_id'] ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['comment_id'] !== $comment_id || (string) $payload['action'] !== $action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That moderation action is invalid or expired.', 'telepress' ) );
		}

		$result = $this->comments_service->moderate_comment( $comment_id, $action );

		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_comment_moderation( $identity, $comment_id, $action, $result );

		return TelePress_Telegram_Response_Builder::success(
			sprintf(
				/* translators: 1: comment id, 2: action label. */
				__( 'Comment #%1$d has been %2$s.', 'telepress' ),
				$comment_id,
				$result['label']
			),
			array(
				'command' => '/comments',
			)
		);
	}

	private function log_comment_moderation( $identity, $comment_id, $action, $result ) {
		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'comment_moderated',
				'resource_type'    => 'comment',
				'resource_id'      => (string) $comment_id,
				'before_state'     => array( 'status' => $result['before_status'] ),
				'after_state'      => array( 'status' => $result['after_status'], 'action' => $action ),
			)
		);
	}

	private function handle_posts( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'edit_posts' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'latest';

		if ( 'latest' === $subcommand ) {
			$result = $this->posts_service->latest_page( $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->posts_service->render_page_message( $result, __( 'Latest Posts', 'telepress' ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->posts_service->build_list_keyboard( $result['items'], 'latest', '', $result['page'], $result['total_pages'] ),
				)
			);
		}

		if ( 'drafts' === $subcommand ) {
			$result = $this->posts_service->drafts_page( $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->posts_service->render_page_message( $result, __( 'Draft Posts', 'telepress' ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->posts_service->build_list_keyboard( $result['items'], 'drafts', '', $result['page'], $result['total_pages'] ),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/posts search keyword`', 'telepress' ) );
			}

			$posts = $this->posts_service->search_page( $term, $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->posts_service->render_page_message( $posts, sprintf( __( 'Post Search: %s', 'telepress' ), $term ) ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->posts_service->build_list_keyboard( $posts['items'], 'search', $term, $posts['page'], $posts['total_pages'] ),
				)
			);
		}

		if ( 'stats' === $subcommand ) {
			return TelePress_Telegram_Response_Builder::success(
				$this->posts_service->render_stats_message( $this->posts_service->stats() ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->build_home_keyboard( $identity ),
				)
			);
		}

		$post_id = ! empty( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		if ( ! $post_id ) {
			return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/posts publish 123` or `/posts unpublish 123`', 'telepress' ) );
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
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'post_state_changed', 'post', $post_id, 'publish', $result );

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Post #%1$d has been %2$s.', 'telepress' ), $post_id, $result['label'] ),
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

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm unpublish for post #%d', 'telepress' ), $post_id ),
				array(
					'command'      => '/posts',
					'reply_markup' => $this->posts_service->build_action_confirmation_keyboard( $post_id, 'unpublish', $identity['telegram_user_id'] ),
				)
			);
		}

		return TelePress_Telegram_Response_Builder::error( __( 'Supported posts commands: `/posts latest`, `/posts drafts`, `/posts search keyword`, `/posts publish 123`, `/posts unpublish 123`, `/posts stats`', 'telepress' ) );
	}

	private function handle_post_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$post_action = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$post_id     = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token       = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['post_id'] !== $post_id || (string) $payload['action'] !== $post_action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That post action is invalid or expired.', 'telepress' ) );
		}

		$result = $this->posts_service->unpublish( $post_id );
		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action( $identity, 'post_state_changed', 'post', $post_id, $post_action, $result );

		return TelePress_Telegram_Response_Builder::success(
			sprintf( __( 'Post #%1$d has been %2$s.', 'telepress' ), $post_id, $result['label'] ),
			array( 'command' => '/posts' )
		);
	}

	private function handle_pages( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'edit_pages' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'list' === $subcommand ) {
			$pages = $this->pages_service->latest_page( $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->pages_service->render_page_message( $pages, __( 'Recent Pages', 'telepress' ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->pages_service->build_list_keyboard( $pages['items'], 'list', '', $pages['page'], $pages['total_pages'] ),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/pages search keyword`', 'telepress' ) );
			}

			$pages = $this->pages_service->search_page( $term, $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->pages_service->render_page_message( $pages, sprintf( __( 'Page Search: %s', 'telepress' ), $term ) ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->pages_service->build_list_keyboard( $pages['items'], 'search', $term, $pages['page'], $pages['total_pages'] ),
				)
			);
		}

		$page_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
		if ( ! $page_id ) {
			return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/pages list`, `/pages publish 123`, `/pages draft 123`, `/pages trash 123`, `/pages restore 123`', 'telepress' ) );
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
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, 'publish', $result );
			return TelePress_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'telepress' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
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
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, 'draft', $result );
			return TelePress_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'telepress' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
		}

		if ( in_array( $subcommand, array( 'trash', 'restore' ), true ) ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm %1$s for page #%2$d', 'telepress' ), $subcommand, $page_id ),
				array(
					'command'      => '/pages',
					'reply_markup' => $this->pages_service->build_action_confirmation_keyboard( $page_id, $subcommand, $identity['telegram_user_id'] ),
				)
			);
		}

		return TelePress_Telegram_Response_Builder::error( __( 'Supported pages commands: `/pages list`, `/pages publish 123`, `/pages draft 123`, `/pages trash 123`, `/pages restore 123`', 'telepress' ) );
	}

	private function handle_page_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$page_action = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$page_id     = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token       = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$permission_result = $this->permission_service->require_capability( $identity, 'edit_page', $page_id );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['page_id'] !== $page_id || (string) $payload['action'] !== $page_action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That page action is invalid or expired.', 'telepress' ) );
		}

		$result = 'restore' === $page_action ? $this->pages_service->restore( $page_id ) : $this->pages_service->trash( $page_id );
		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, $page_action, $result );

		return TelePress_Telegram_Response_Builder::success(
			sprintf( __( 'Page #%1$d has been %2$s.', 'telepress' ), $page_id, $result['label'] ),
			array( 'command' => '/pages' )
		);
	}

	private function log_content_action( $identity, $action_name, $resource_type, $resource_id, $action, $result ) {
		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => $action_name,
				'resource_type'    => $resource_type,
				'resource_id'      => (string) $resource_id,
				'before_state'     => array( 'status' => $result['before_status'] ),
				'after_state'      => array( 'status' => $result['after_status'], 'action' => $action ),
			)
		);
	}

	private function handle_media( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'upload_files' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'recent';

		if ( 'recent' === $subcommand ) {
			$items = $this->media_service->recent_page( $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->media_service->render_page_message( $items, __( 'Recent Media', 'telepress' ) ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->media_service->build_list_keyboard( $items['items'], 'recent', '', $items['page'], $items['total_pages'] ),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/media search keyword`', 'telepress' ) );
			}

			$items = $this->media_service->search_page( $term, $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->media_service->render_page_message( $items, sprintf( __( 'Media Search: %s', 'telepress' ), $term ) ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->media_service->build_list_keyboard( $items['items'], 'search', $term, $items['page'], $items['total_pages'] ),
				)
			);
		}

		$attachment_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
		if ( 'delete' === $subcommand && $attachment_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'delete_post', $attachment_id );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm delete for media #%d', 'telepress' ), $attachment_id ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->media_service->build_delete_confirmation_keyboard( $attachment_id, $identity['telegram_user_id'] ),
				)
			);
		}

		return TelePress_Telegram_Response_Builder::error( __( 'Supported media commands: `/media recent`, `/media search keyword`, `/media delete 123`, or send a photo/document to upload it.', 'telepress' ) );
	}

	private function handle_media_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$action        = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$attachment_id = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token         = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$permission_result = $this->permission_service->require_capability( $identity, 'delete_post', $attachment_id );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['attachment_id'] !== $attachment_id || 'delete' !== $action || (string) $payload['action'] !== $action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That media action is invalid or expired.', 'telepress' ) );
		}

		$result = $this->media_service->delete( $attachment_id );
		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'media_deleted',
				'resource_type'    => 'attachment',
				'resource_id'      => (string) $attachment_id,
				'before_state'     => $result['before_state'],
				'after_state'      => array( 'action' => 'delete' ),
			)
		);

		return TelePress_Telegram_Response_Builder::success(
			sprintf( __( 'Media #%1$d has been %2$s.', 'telepress' ), $attachment_id, $result['label'] ),
			array( 'command' => '/media' )
		);
	}

	private function handle_users( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$permission_result = $this->permission_service->require_capability( $identity, 'list_users' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'list' === $subcommand ) {
			$users = $this->users_service->recent_page( $page );
			return TelePress_Telegram_Response_Builder::success(
				$this->users_service->render_page_message( $users, __( 'Recent Users', 'telepress' ) ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_list_keyboard( $users['items'], 'list', '', $users['page'], $users['total_pages'] ),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/users search keyword`', 'telepress' ) );
			}

			$users = $this->users_service->search_page( $term, $page );

			return TelePress_Telegram_Response_Builder::success(
				$this->users_service->render_page_message( $users, sprintf( __( 'User Search: %s', 'telepress' ), $term ) ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_list_keyboard( $users['items'], 'search', $term, $users['page'], $users['total_pages'] ),
				)
			);
		}

		if ( 'create' === $subcommand ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'create_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$username = isset( $args[1] ) ? (string) $args[1] : '';
			$email    = isset( $args[2] ) ? (string) $args[2] : '';
			$role     = isset( $args[3] ) ? (string) $args[3] : '';

			if ( '' === $username || '' === $email || '' === $role ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/users create username email role`', 'telepress' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'You cannot create a user with that role.', 'telepress' ) );
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm create user `%1$s` with role `%2$s`', 'telepress' ), $username, $role ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_confirmation_keyboard(
						'create',
						0,
						$identity['telegram_user_id'],
						array(
							'username' => $username,
							'email'    => $email,
							'role'     => $role,
						)
					),
				)
			);
		}

		$user_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;

		if ( 'disable' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm disable for user #%d', 'telepress' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_confirmation_keyboard( 'disable', $user_id, $identity['telegram_user_id'] ),
				)
			);
		}

		if ( 'enable' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->users_service->enable_user( $user_id );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_enabled', $user_id, $result['before_state'], $result['after_state'] );

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepress' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm password reset for user #%d', 'telepress' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_confirmation_keyboard( 'reset-password', $user_id, $identity['telegram_user_id'] ),
				)
			);
		}

		if ( 'role' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'promote_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$role = isset( $args[2] ) ? (string) $args[2] : '';
			if ( '' === $role ) {
				return TelePress_Telegram_Response_Builder::error( __( 'Usage: `/users role 123 editor`', 'telepress' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'telepress' ) );
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm role change for user #%1$d to `%2$s`', 'telepress' ), $user_id, $role ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->users_service->build_confirmation_keyboard( 'role', $user_id, $identity['telegram_user_id'], array( 'role' => $role ) ),
				)
			);
		}

		return TelePress_Telegram_Response_Builder::error( __( 'Supported user commands: `/users list`, `/users search keyword`, `/users create username email role`, `/users disable 123`, `/users enable 123`, `/users reset-password 123`, `/users role 123 editor`', 'telepress' ) );
	}

	private function handle_user_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$action  = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$user_id = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token   = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (string) $payload['action'] !== $action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepress' ) );
		}

		if ( 'create' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'create_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->users_service->create_user( $payload['username'], $payload['email'], $payload['role'] );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			TelePress_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'user_created',
					'resource_type'    => 'user',
					'resource_id'      => (string) $result['user']->ID,
					'after_state'      => array(
						'user_login' => $result['user']->user_login,
						'user_email' => $result['user']->user_email,
						'roles'      => $result['user']->roles,
					),
				)
			);

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d `%2$s` has been created.', 'telepress' ), $result['user']->ID, $result['user']->user_login ),
				array( 'command' => '/users' )
			);
		}

		if ( 'disable' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return TelePress_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepress' ) );
			}

			$result = $this->users_service->disable_user( $user_id );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_disabled', $user_id, $result['before_state'], $result['after_state'] );

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepress' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return TelePress_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepress' ) );
			}

			$result = $this->users_service->generate_reset_link( $user_id );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			TelePress_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'user_password_reset_generated',
					'resource_type'    => 'user',
					'resource_id'      => (string) $user_id,
				)
			);

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( "Password reset generated for user #%1$d\nReset URL: %2$s", 'telepress' ), $user_id, $result['url'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'role' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'promote_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id || empty( $payload['role'] ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepress' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $payload['role'] ) ) {
				return TelePress_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'telepress' ) );
			}

			$result = $this->users_service->assign_role( $user_id, $payload['role'] );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_role_changed', $user_id, $result['before_state'], $result['after_state'] );

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepress' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		return TelePress_Telegram_Response_Builder::error( __( 'That user action is not supported.', 'telepress' ) );
	}

	private function log_user_action( $identity, $action_name, $user_id, $before_state, $after_state ) {
		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => $action_name,
				'resource_type'    => 'user',
				'resource_id'      => (string) $user_id,
				'before_state'     => $before_state,
				'after_state'      => $after_state,
			)
		);
	}

	private function handle_terms( $taxonomy, $resource, $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'manage_categories' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'list' === $subcommand ) {
			$result = $this->taxonomies_service->list_terms( $taxonomy, $page );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return TelePress_Telegram_Response_Builder::success(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%s List', 'telepress' ), ucfirst( $resource ) ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->taxonomies_service->build_terms_keyboard( $resource, $result ),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s search keyword`', 'telepress' ), $resource ) );
			}

			$result = $this->taxonomies_service->list_terms( $taxonomy, $page, 5, $term );
			if ( is_wp_error( $result ) ) {
				return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return TelePress_Telegram_Response_Builder::success(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%1$s Search: %2$s', 'telepress' ), ucfirst( $resource ), $term ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->taxonomies_service->build_terms_keyboard( $resource, $result ),
				)
			);
		}

		if ( 'create' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$name = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $name ) ) {
				return TelePress_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s create Name`', 'telepress' ), $resource ) );
			}

			$term = $this->taxonomies_service->create_term( $taxonomy, $name );
			if ( is_wp_error( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d `%3$s` has been created.', 'telepress' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
				array( 'command' => '/' . $resource )
			);
		}

		$term_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;

		if ( 'rename' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$name = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $name ) ) {
				return TelePress_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s rename 12 New Name`', 'telepress' ), $resource ) );
			}

			$term = $this->taxonomies_service->rename_term( $taxonomy, $term_id, $name );
			if ( is_wp_error( $term ) ) {
				return TelePress_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d has been renamed to `%3$s`.', 'telepress' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'delete' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			return TelePress_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm delete for %1$s #%2$d', 'telepress' ), rtrim( $resource, 's' ), $term_id ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->taxonomies_service->build_delete_confirmation_keyboard( $taxonomy, $term_id, $identity['telegram_user_id'] ),
				)
			);
		}

		return TelePress_Telegram_Response_Builder::error( sprintf( __( 'Supported %1$s commands: `/%1$s list`, `/%1$s search keyword`, `/%1$s create Name`, `/%1$s rename 12 New Name`, `/%1$s delete 12`', 'telepress' ), $resource ) );
	}

	private function handle_term_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$taxonomy = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$action   = isset( $command['args'][1] ) ? (string) $command['args'][1] : '';
		$term_id  = isset( $command['args'][2] ) ? absint( $command['args'][2] ) : 0;
		$token    = isset( $command['args'][3] ) ? (string) $command['args'][3] : '';

		$permission_result = $this->permission_service->require_capability( $identity, 'manage_categories' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (string) $payload['taxonomy'] !== $taxonomy || (int) $payload['term_id'] !== $term_id || 'delete' !== $action || (string) $payload['action'] !== $action ) {
			return TelePress_Telegram_Response_Builder::error( __( 'That term action is invalid or expired.', 'telepress' ) );
		}

		$term = $this->taxonomies_service->delete_term( $taxonomy, $term_id );
		if ( is_wp_error( $term ) ) {
			return TelePress_Telegram_Response_Builder::error( $term->get_error_message() );
		}

		$resource = 'post_tag' === $taxonomy ? 'tags' : 'categories';

		return TelePress_Telegram_Response_Builder::success(
			sprintf( __( 'Term #%1$d `%2$s` has been deleted.', 'telepress' ), $term_id, $term->name ),
			array( 'command' => '/' . $resource )
		);
	}

	private function extract_page_from_args( $args ) {
		$page = 1;

		if ( empty( $args ) ) {
			return array( array(), $page );
		}

		$last_key = array_key_last( $args );
		$last_arg = isset( $args[ $last_key ] ) ? (string) $args[ $last_key ] : '';

		if ( preg_match( '/^page:(\d+)$/', $last_arg, $matches ) ) {
			$page = max( 1, (int) $matches[1] );
			unset( $args[ $last_key ] );
			$args = array_values( $args );
		}

		return array( $args, $page );
	}

	private function require_private_action( $identity ) {
		return $this->permission_service->require_private_chat( $identity );
	}

	private function build_home_keyboard( $identity ) {
		$rows   = array();
		$rows[] = array(
			array(
				'text'          => __( 'Site Overview', 'telepress' ),
				'callback_data' => '/site',
			),
			array(
				'text'          => __( 'Menu', 'telepress' ),
				'callback_data' => '/menu',
			),
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Posts', 'telepress' ),
						'callback_data' => '/posts latest',
					),
					array(
						'text'          => __( 'Pages', 'telepress' ),
						'callback_data' => '/pages list',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Comments', 'telepress' ),
						'callback_data' => '/comments pending',
					),
					array(
						'text'          => __( 'Media', 'telepress' ),
						'callback_data' => '/media recent',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Users', 'telepress' ),
						'callback_data' => '/users list',
					),
				);
			}

			$admin_row = array(
				array(
					'text' => __( 'Open wp-admin', 'telepress' ),
					'url'  => admin_url(),
				),
			);

			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_options' ) ) {
				$admin_row[] = array(
					'text' => __( 'Settings', 'telepress' ),
					'url'  => admin_url( 'admin.php?page=telepress' ),
				);
			}

			$rows[] = $admin_row;
		}

		return TelePress_Telegram_Response_Builder::keyboard( $rows );
	}
}
