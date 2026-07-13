<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Command_Router {
	private $linking_service;
	private $permission_service;
	private $dashboard_service;
	private $comments_service;
	private $posts_service;
	private $pages_service;
	private $media_service;
	private $users_service;
	private $plugins_service;
	private $taxonomies_service;
	private $confirmation_service;

	public function __construct( Telepilot_User_Linking_Service $linking_service, Telepilot_Permission_Service $permission_service, Telepilot_Dashboard_Service $dashboard_service, Telepilot_Comments_Service $comments_service, Telepilot_Posts_Service $posts_service, Telepilot_Pages_Service $pages_service, Telepilot_Media_Service $media_service, Telepilot_Users_Service $users_service, Telepilot_Plugins_Service $plugins_service, Telepilot_Taxonomies_Service $taxonomies_service, Telepilot_Confirmation_Service $confirmation_service ) {
		$this->linking_service    = $linking_service;
		$this->permission_service = $permission_service;
		$this->dashboard_service  = $dashboard_service;
		$this->comments_service   = $comments_service;
		$this->posts_service      = $posts_service;
		$this->pages_service      = $pages_service;
		$this->media_service      = $media_service;
		$this->users_service      = $users_service;
		$this->plugins_service    = $plugins_service;
		$this->taxonomies_service = $taxonomies_service;
		$this->confirmation_service = $confirmation_service;
	}

	public function route( $update, $identity ) {
		$command = $this->parse_command( $update );

		if ( empty( $command['name'] ) ) {
			return Telepilot_Telegram_Response_Builder::success(
				__( 'Webhook received. No supported command was detected yet.', 'telepilot' ),
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

			case '/plugins':
				return $this->handle_plugins( $command, $identity );

			case 'tp:plugin':
				return $this->handle_plugin_callback( $command, $identity );

			case '/categories':
				return $this->handle_terms( 'category', 'categories', $command, $identity );

			case '/tags':
				return $this->handle_terms( 'post_tag', 'tags', $command, $identity );

			case 'tp:term':
				return $this->handle_term_callback( $command, $identity );

			default:
				return Telepilot_Telegram_Response_Builder::success(
					sprintf(
						/* translators: %s: command name. */
						__( 'Command `%s` is recognized, but its handler is not implemented yet.', 'telepilot' ),
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
		$chat_id = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'telepilot' );

		$message = sprintf(
			/* translators: %s: Telegram chat ID. */
			__(
				"WP Telepilot is connected.\n\nYour current chat ID: %s\n\nNext steps:\n1. Add this chat ID to WP Telepilot Allowed Chat IDs if you are using an allow list.\n2. Generate a one-time link code from your WordPress profile.\n3. Send /link CODE here to connect your Telegram account.\n\nUse /help to view commands.",
				'telepilot'
			),
			$chat_id
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$message = sprintf(
				/* translators: 1: WordPress display name, 2: Telegram chat ID. */
				__( "WP Telepilot is connected to WordPress user %1$s.\n\nCurrent chat ID: %2$s\n\nUse /menu to open the command hub or /site to view your site overview.", 'telepilot' ),
				$identity['wp_user']->display_name,
				$chat_id
			);
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			$message,
			array(
				'command'      => '/start',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_help( $identity ) {
		$commands = array();

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			$commands[] = Telepilot_Telegram_Response_Builder::bold( __( 'Setup Flow', 'telepilot' ) );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/start' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/chatid' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/link CODE' );
			$commands[] = '';
		}

		$commands[] = Telepilot_Telegram_Response_Builder::bold( __( 'Core Commands', 'telepilot' ) );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/start' ) . ' ' . __( 'Onboarding', 'telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/help' ) . ' ' . __( 'Show commands', 'telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/menu' ) . ' ' . __( 'Open the command hub', 'telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/site' ) . ' ' . __( 'Show site overview', 'telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/chatid' ) . ' ' . __( 'Reveal the current chat ID', 'telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/link CODE' ) . ' ' . __( 'Link Telegram to WordPress', 'telepilot' );

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/unlink' ) . ' ' . __( 'Unlink Telegram', 'telepilot' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/settings' ) . ' ' . __( 'WP Telepilot settings info', 'telepilot' );
			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/comments pending' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts help' ) . ' ' . __( 'Show posts examples', 'telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_pages' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages help' ) . ' ' . __( 'Show pages examples', 'telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'upload_files' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media help' ) . ' ' . __( 'Show media examples', 'telepilot' );
			}

			$future_commands = array();

			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users help' ) . ' ' . __( 'Show user-management examples', 'telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins updates' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins help' ) . ' ' . __( 'Show plugin-management examples', 'telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_categories' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/categories list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/categories search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/tags list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/tags search keyword' );
			}

			if ( ! empty( $future_commands ) ) {
				$commands[] = '';
				$commands[] = __( 'Coming next on the roadmap:', 'telepilot' );
				$commands   = array_merge( $commands, $future_commands );
			}
		}

		$commands[] = '';
		$commands[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use module-specific help like /users help when you need concrete examples.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::success_html(
			implode( "\n", $commands ),
			array(
				'command'      => '/help',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_menu( $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'read' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Menu', 'telepilot' ) ) .
			"\n\n" .
			__(
				'Choose an area below. Use Telegram for quick review and short actions, then jump into WordPress when you need full editing.',
				'telepilot'
			),
			array(
				'command'      => '/menu',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_settings( $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'manage_options' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$settings = get_option( 'telepilot_settings', array() );
		$url      = admin_url( 'admin.php?page=telepilot' );

		return Telepilot_Telegram_Response_Builder::success_html(
			sprintf(
				__(
					"<b>WP Telepilot Settings</b>\n\nAdmin: %1$s\nTransport: %2$s\nLinking: %3$s",
					'telepilot'
				),
				Telepilot_Telegram_Response_Builder::link( __( 'Open settings', 'telepilot' ), $url ),
				Telepilot_Telegram_Response_Builder::escape( ! empty( $settings['transport_mode'] ) ? ucfirst( (string) $settings['transport_mode'] ) : __( 'Webhook', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::escape( ! empty( $settings['linking_enabled'] ) ? __( 'Enabled', 'telepilot' ) : __( 'Disabled', 'telepilot' ) )
			),
			array(
				'command'      => '/settings',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_chat_id( $identity ) {
		$chat_id          = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'telepilot' );
		$telegram_user_id = ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : __( 'Unavailable', 'telepilot' );

		return Telepilot_Telegram_Response_Builder::success_html(
			sprintf(
				/* translators: 1: Telegram chat ID, 2: Telegram user ID. */
				__( "<b>Current Chat Details</b>\n\nChat ID: %1$s\nTelegram user ID: %2$s\n\nAdd the chat ID to Allowed Chat IDs if you want this conversation to be authorized.", 'telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $chat_id ),
				Telepilot_Telegram_Response_Builder::escape( $telegram_user_id )
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
			return Telepilot_Telegram_Response_Builder::error(
				__( 'Usage: `/link CODE`', 'telepilot' ),
				array(
					'command' => '/link',
				)
			);
		}

		$message = isset( $update['message'] ) ? $update['message'] : array();
		$result  = $this->linking_service->consume_link_code( $command['args'][0], $message );

		$diagnostics = get_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$diagnostics = array_merge(
			$diagnostics,
			array(
				'last_link_attempt_at'     => time(),
				'last_link_attempt_status' => ! empty( $result['ok'] ) ? 'success' : 'failed',
				'last_link_attempt_detail' => isset( $result['message'] ) ? sanitize_text_field( wp_strip_all_tags( $result['message'] ) ) : '',
			)
		);
		update_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION, $diagnostics, false );

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
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'telegram_account_unlinked',
				'resource_type'    => 'user',
				'resource_id'      => (string) $identity['wp_user']->ID,
			)
		);

		return Telepilot_Telegram_Response_Builder::success(
			__( 'Telegram has been unlinked from your WordPress account.', 'telepilot' ),
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

		return Telepilot_Telegram_Response_Builder::success_html(
			$this->dashboard_service->render_summary_message( $summary ),
			array(
				'command'      => '/site',
				'data'         => $summary,
				'reply_markup' => $this->safe_reply_markup(
					function() use ( $identity ) {
						return $this->dashboard_service->build_overview_keyboard( $identity['wp_user'] );
					},
					'dashboard_overview'
				),
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

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->comments_service->render_pending_message( $comments ),
				array(
					'command'      => '/comments',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $comments, $identity ) {
							return $this->comments_service->build_pending_keyboard( $comments, $identity['telegram_user_id'] );
						},
						'comments_pending'
					),
				)
			);
		}

		$private_chat_result = $this->require_private_action( $identity );

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$comment_id = ! empty( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;

		if ( ! in_array( $subcommand, array( 'approve', 'reject', 'spam', 'trash' ), true ) || ! $comment_id ) {
			return Telepilot_Telegram_Response_Builder::error(
				__( 'Supported comments commands: `/comments pending`, `/comments approve 123`, `/comments reject 123`, `/comments spam 123`, `/comments trash 123`', 'telepilot' ),
				array(
					'command' => '/comments',
				)
			);
		}

		if ( 'approve' === $subcommand ) {
			$result = $this->comments_service->moderate_comment( $comment_id, $subcommand );

			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_comment_moderation( $identity, $comment_id, $subcommand, $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf(
					__( 'Comment #%1$d has been %2$s.', 'telepilot' ),
					$comment_id,
					$result['label']
				),
				array(
					'command' => '/comments',
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::success(
			sprintf(
				__( 'Confirm moderation for comment #%1$d: %2$s', 'telepilot' ),
				$comment_id,
				$subcommand
			),
			array(
				'command'      => '/comments',
				'reply_markup' => $this->safe_reply_markup(
					function() use ( $comment_id, $subcommand, $identity ) {
						return $this->comments_service->build_action_confirmation_keyboard( $comment_id, $subcommand, $identity['telegram_user_id'] );
					},
					'comments_confirm'
				),
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That moderation action is incomplete.', 'telepilot' ) );
		}

		$payload = $this->confirmation_service->consume_token( $token );

		if ( empty( $payload ) || empty( $payload['telegram_user_id'] ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['comment_id'] !== $comment_id || (string) $payload['action'] !== $action ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That moderation action is invalid or expired.', 'telepilot' ) );
		}

		$result = $this->comments_service->moderate_comment( $comment_id, $action );

		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_comment_moderation( $identity, $comment_id, $action, $result );

		return Telepilot_Telegram_Response_Builder::success(
			sprintf(
				/* translators: 1: comment id, 2: action label. */
				__( 'Comment #%1$d has been %2$s.', 'telepilot' ),
				$comment_id,
				$result['label']
			),
			array(
				'command' => '/comments',
			)
		);
	}

	private function log_comment_moderation( $identity, $comment_id, $action, $result ) {
		Telepilot_Audit_Log_Repository::log(
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
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_help_message(),
				array(
					'command'      => '/posts',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( in_array( $subcommand, array( 'list', 'latest' ), true ) ) {
			$result = $this->posts_service->latest_page( $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $result, __( 'Latest Posts', 'telepilot' ) ),
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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $result, __( 'Draft Posts', 'telepilot' ) ),
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

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts search keyword`', 'telepilot' ) );
			}

			$posts = $this->posts_service->search_page( $term, $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $posts, sprintf( __( 'Post Search: %s', 'telepilot' ), $term ) ),
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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_stats_message( $this->posts_service->stats() ),
				array(
					'command'      => '/posts',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		$post_id = ! empty( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		if ( ! $post_id ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/posts publish 123` or `/posts unpublish 123`', 'telepilot' ) );
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
				sprintf( __( 'Post #%1$d has been %2$s.', 'telepilot' ), $post_id, $result['label'] ),
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
				sprintf( __( 'Confirm unpublish for post [%d]', 'telepilot' ), $post_id ),
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

		return Telepilot_Telegram_Response_Builder::error_html(
			$this->posts_service->render_help_message(),
			array(
				'command' => '/posts',
			)
		);
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That post action is invalid or expired.', 'telepilot' ) );
		}

		$result = $this->posts_service->unpublish( $post_id );
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action( $identity, 'post_state_changed', 'post', $post_id, $post_action, $result );

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Post #%1$d has been %2$s.', 'telepilot' ), $post_id, $result['label'] ),
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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, __( 'Recent Pages', 'telepilot' ) ),
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
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/pages search keyword`', 'telepilot' ) );
			}

			$pages = $this->pages_service->search_page( $term, $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, sprintf( __( 'Page Search: %s', 'telepilot' ), $term ) ),
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

		if ( 'trashed' === $subcommand ) {
			$pages = $this->pages_service->trashed_page( $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, __( 'Trashed Pages', 'telepilot' ) ),
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

		$page_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
		if ( ! $page_id ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				$this->pages_service->render_help_message(),
				array(
					'command' => '/pages',
				)
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
			return Telepilot_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
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
			return Telepilot_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
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

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm %1$s for page #%2$d', 'telepilot' ), $subcommand, $page_id ),
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

		return Telepilot_Telegram_Response_Builder::error_html(
			$this->pages_service->render_help_message(),
			array(
				'command' => '/pages',
			)
		);
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That page action is invalid or expired.', 'telepilot' ) );
		}

		$result = 'restore' === $page_action ? $this->pages_service->restore( $page_id ) : $this->pages_service->trash( $page_id );
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action( $identity, 'page_state_changed', 'page', $page_id, $page_action, $result );

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Page #%1$d has been %2$s.', 'telepilot' ), $page_id, $result['label'] ),
			array( 'command' => '/pages' )
		);
	}

	private function log_content_action( $identity, $action_name, $resource_type, $resource_id, $action, $result ) {
		Telepilot_Audit_Log_Repository::log(
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
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->media_service->render_help_message(),
				array(
					'command'      => '/media',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( in_array( $subcommand, array( 'list', 'recent' ), true ) ) {
			$items = $this->media_service->recent_page( $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->media_service->render_page_message( $items, __( 'Recent Media', 'telepilot' ) ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $items ) {
							return $this->media_service->build_list_keyboard( $items['items'], 'list', '', $items['page'], $items['total_pages'] );
						},
						'media_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error_html(
					$this->media_service->render_help_message(),
					array(
						'command' => '/media',
					)
				);
			}

			$items = $this->media_service->search_page( $term, $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->media_service->render_page_message( $items, sprintf( __( 'Media Search: %s', 'telepilot' ), $term ) ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $items, $term ) {
							return $this->media_service->build_list_keyboard( $items['items'], 'search', $term, $items['page'], $items['total_pages'] );
						},
						'media_search'
					),
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

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm delete for media [%d]', 'telepilot' ), $attachment_id ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $attachment_id, $identity ) {
							return $this->media_service->build_delete_confirmation_keyboard( $attachment_id, $identity['telegram_user_id'] );
						},
						'media_delete_confirm'
					),
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			$this->media_service->render_help_message(),
			array(
				'command' => '/media',
			)
		);
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That media action is invalid or expired.', 'telepilot' ) );
		}

		$result = $this->media_service->delete( $attachment_id );
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		Telepilot_Audit_Log_Repository::log(
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

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Media #%1$d has been %2$s.', 'telepilot' ), $attachment_id, $result['label'] ),
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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_page_message( $users, __( 'Recent Users', 'telepilot' ) ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $users ) {
							return $this->users_service->build_list_keyboard( $users['items'], 'list', '', $users['page'], $users['total_pages'] );
						},
						'users_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error_html(
					Telepilot_Telegram_Response_Builder::bold( __( 'Users Search', 'telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users search keyword' )
				);
			}

			$users = $this->users_service->search_page( $term, $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_page_message( $users, sprintf( __( 'User Search: %s', 'telepilot' ), $term ) ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $users, $term ) {
							return $this->users_service->build_list_keyboard( $users['items'], 'search', $term, $users['page'], $users['total_pages'] );
						},
						'users_search'
					),
				)
			);
		}

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_help_message(),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_home_keyboard( $identity ),
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
				return Telepilot_Telegram_Response_Builder::error_html(
					Telepilot_Telegram_Response_Builder::bold( __( 'Create User', 'telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users create username email role' ) . "\n" .
					__( 'Example:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users create jane jane@example.com editor' )
				);
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot create a user with that role.', 'telepilot' ) );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm create user `%1$s` with role `%2$s`', 'telepilot' ), $username, $role ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $identity, $username, $email, $role ) {
							return $this->users_service->build_confirmation_keyboard(
								'create',
								0,
								$identity['telegram_user_id'],
								array(
									'username' => $username,
									'email'    => $email,
									'role'     => $role,
								)
							);
						},
						'users_create_confirm'
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

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm disable for user [%d]', 'telepilot' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity ) {
							return $this->users_service->build_confirmation_keyboard( 'disable', $user_id, $identity['telegram_user_id'] );
						},
						'users_disable_confirm'
					),
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
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_enabled', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm password reset for user [%d]', 'telepilot' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity ) {
							return $this->users_service->build_confirmation_keyboard( 'reset-password', $user_id, $identity['telegram_user_id'] );
						},
						'user_reset_password_confirm'
					),
				)
			);
		}

		if ( 'send-reset' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm password reset email for user [%d]', 'telepilot' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity ) {
							return $this->users_service->build_confirmation_keyboard( 'send-reset', $user_id, $identity['telegram_user_id'] );
						},
						'user_send_reset_confirm'
					),
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
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/users role 123 editor`', 'telepilot' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'telepilot' ) );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm role change for user #%1$d to `%2$s`', 'telepilot' ), $user_id, $role ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity, $role ) {
							return $this->users_service->build_confirmation_keyboard( 'role', $user_id, $identity['telegram_user_id'], array( 'role' => $role ) );
						},
						'users_role_confirm'
					),
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			$this->users_service->render_help_message(),
			array(
				'command' => '/users',
			)
		);
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepilot' ) );
		}

		if ( 'create' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'create_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->users_service->create_user( $payload['username'], $payload['email'], $payload['role'] );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			Telepilot_Audit_Log_Repository::log(
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

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d `%2$s` has been created.', 'telepilot' ), $result['user']->ID, $result['user']->user_login ),
				array( 'command' => '/users' )
			);
		}

		if ( 'disable' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepilot' ) );
			}

			$result = $this->users_service->disable_user( $user_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_disabled', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepilot' ) );
			}

			$result = $this->users_service->generate_reset_link( $user_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'user_password_reset_generated',
					'resource_type'    => 'user',
					'resource_id'      => (string) $user_id,
				)
			);

			return Telepilot_Telegram_Response_Builder::success_html(
				Telepilot_Telegram_Response_Builder::bold( __( 'Password Reset Link Generated', 'telepilot' ) ) .
				"\n\n" .
				sprintf(
					__( 'User: #%1$d', 'telepilot' ),
					$user_id
				) .
				"\n" .
				__( 'Open:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Reset password', 'telepilot' ), $result['url'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'send-reset' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepilot' ) );
			}

			$result = $this->users_service->send_reset_email( $user_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'user_password_reset_emailed',
					'resource_type'    => 'user',
					'resource_id'      => (string) $user_id,
					'after_state'      => array(
						'user_login' => $result['user']->user_login,
						'user_email' => $result['user']->user_email,
						'action'     => 'send-reset',
					),
				)
			);

			return Telepilot_Telegram_Response_Builder::success_html(
				Telepilot_Telegram_Response_Builder::bold( __( 'Password Reset Email Sent', 'telepilot' ) ) .
				"\n\n" .
				sprintf(
					__( 'User: #%1$d (%2$s)', 'telepilot' ),
					$user_id,
					Telepilot_Telegram_Response_Builder::escape( $result['user']->user_login )
				) .
				"\n" .
				sprintf(
					__( 'Email: %s', 'telepilot' ),
					Telepilot_Telegram_Response_Builder::escape( $result['user']->user_email )
				),
				array( 'command' => '/users' )
			);
		}

		if ( 'role' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'promote_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id || empty( $payload['role'] ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'telepilot' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $payload['role'] ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'telepilot' ) );
			}

			$result = $this->users_service->assign_role( $user_id, $payload['role'] );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_role_changed', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		return Telepilot_Telegram_Response_Builder::error( __( 'That user action is not supported.', 'telepilot' ) );
	}

	private function handle_plugins( $command, $identity ) {
		$link_result = $this->permission_service->require_linked_user( $identity );
		if ( true !== $link_result ) {
			return $link_result;
		}

		$can_plugins = $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' );
		if ( ! $can_plugins ) {
			return Telepilot_Telegram_Response_Builder::error(
				__( 'You do not have permission to perform that action.', 'telepilot' ),
				array(
					'code'       => 'telepilot_capability_denied',
					'capability' => 'activate_plugins',
				)
			);
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_help_message(),
				array(
					'command'      => '/plugins',
					'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( in_array( $subcommand, array( 'list', 'installed' ), true ) ) {
			$result = $this->plugins_service->list_page( $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_page_message( $result, __( 'Installed Plugins', 'telepilot' ) ),
				array(
					'command'      => '/plugins',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->plugins_service->build_list_keyboard( $result['items'], 'list', '', $result['page'], $result['total_pages'] );
						},
						'plugins_list'
					),
				)
			);
		}

		if ( 'updates' === $subcommand ) {
			$result = $this->plugins_service->updates_page( $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_page_message( $result, __( 'Plugin Updates', 'telepilot' ) ),
				array(
					'command'      => '/plugins',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->plugins_service->build_list_keyboard( $result['items'], 'updates', '', $result['page'], $result['total_pages'] );
						},
						'plugins_updates'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error_html(
					Telepilot_Telegram_Response_Builder::bold( __( 'Plugins Search', 'telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/plugins search keyword' )
				);
			}

			$result = $this->plugins_service->search_page( $term, $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_page_message( $result, sprintf( __( 'Plugin Search: %s', 'telepilot' ), $term ) ),
				array(
					'command'      => '/plugins',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result, $term ) {
							return $this->plugins_service->build_list_keyboard( $result['items'], 'search', $term, $result['page'], $result['total_pages'] );
						},
						'plugins_search'
					),
				)
			);
		}

		$identifier = isset( $args[1] ) ? sanitize_text_field( (string) $args[1] ) : '';

		if ( 'details' === $subcommand ) {
			if ( '' === $identifier ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/plugins details IDENTIFIER`', 'telepilot' ) );
			}

			$result = $this->plugins_service->get_plugin_details( $identifier );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_details_message( $result ),
				array(
					'command'      => '/plugins',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result, $identity ) {
							return $this->plugins_service->build_list_keyboard( array( $result ), 'list', '', 1, 1 );
						},
						'plugin_details'
					),
				)
			);
		}

		if ( ! in_array( $subcommand, array( 'activate', 'deactivate', 'update', 'delete' ), true ) || '' === $identifier ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				$this->plugins_service->render_help_message(),
				array(
					'command' => '/plugins',
				)
			);
		}

		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		if ( in_array( $subcommand, array( 'activate', 'deactivate' ), true ) ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'activate_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		if ( 'update' === $subcommand ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'update_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		if ( 'delete' === $subcommand ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'delete_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Confirm %1$s for plugin [%2$s]', 'telepilot' ), $subcommand, $identifier ),
			array(
				'command'      => '/plugins',
				'reply_markup' => $this->safe_reply_markup(
					function() use ( $subcommand, $identifier, $identity ) {
						return $this->plugins_service->build_action_confirmation_keyboard( $subcommand, $identifier, $identity['telegram_user_id'] );
					},
					'plugins_action_confirm'
				),
			)
		);
	}

	private function handle_plugin_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$mode  = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$token = isset( $command['args'][1] ) ? (string) $command['args'][1] : '';

		if ( 'confirm' !== $mode || '' === $token ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is invalid or expired.', 'telepilot' ) );
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || empty( $payload['telegram_user_id'] ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || empty( $payload['action'] ) || empty( $payload['plugin_file'] ) ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is invalid or expired.', 'telepilot' ) );
		}

		$action     = (string) $payload['action'];
		$plugin_ref = (string) $payload['plugin_file'];

		if ( in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'activate_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		if ( 'update' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'update_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		if ( 'delete' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'delete_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}
		}

		switch ( $action ) {
			case 'activate':
				$result = $this->plugins_service->activate( $plugin_ref );
				break;
			case 'deactivate':
				$result = $this->plugins_service->deactivate( $plugin_ref );
				break;
			case 'update':
				$result = $this->plugins_service->update( $plugin_ref );
				break;
			case 'delete':
				$result = $this->plugins_service->delete( $plugin_ref );
				break;
			default:
				return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is not supported.', 'telepilot' ) );
		}

		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_plugin_action( $identity, $action, $result );

		$plugin = ! empty( $result['plugin'] ) ? $result['plugin'] : array();
		$label  = ! empty( $plugin['identifier'] ) ? $plugin['identifier'] : sanitize_key( basename( $plugin_ref, '.php' ) );

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::bold( __( 'Plugin Action Completed', 'telepilot' ) ) .
			"\n\n" .
			sprintf(
				__( 'Plugin: [%1$s]', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $label )
			) .
			"\n" .
			sprintf(
				__( 'Result: %s', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $result['label'] )
			),
			array(
				'command' => '/plugins',
			)
		);
	}

	private function log_plugin_action( $identity, $action, $result ) {
		$plugin = ! empty( $result['plugin'] ) ? $result['plugin'] : array();

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'plugin_' . sanitize_key( $action ),
				'resource_type'    => 'plugin',
				'resource_id'      => ! empty( $plugin['file'] ) ? (string) $plugin['file'] : null,
				'before_state'     => isset( $result['before_state'] ) ? $result['before_state'] : array(),
				'after_state'      => isset( $result['after_state'] ) ? $result['after_state'] : array(),
			)
		);
	}

	private function log_user_action( $identity, $action_name, $user_id, $before_state, $after_state ) {
		Telepilot_Audit_Log_Repository::log(
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
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%s List', 'telepilot' ), ucfirst( $resource ) ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $resource, $result ) {
							return $this->taxonomies_service->build_terms_keyboard( $resource, $result );
						},
						'terms_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s search keyword`', 'telepilot' ), $resource ) );
			}

			$result = $this->taxonomies_service->list_terms( $taxonomy, $page, 5, $term );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%1$s Search: %2$s', 'telepilot' ), ucfirst( $resource ), $term ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $resource, $result ) {
							return $this->taxonomies_service->build_terms_keyboard( $resource, $result );
						},
						'terms_search'
					),
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
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s create Name`', 'telepilot' ), $resource ) );
			}

			$term = $this->taxonomies_service->create_term( $taxonomy, $name );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d `%3$s` has been created.', 'telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
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
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s rename 12 New Name`', 'telepilot' ), $resource ) );
			}

			$term = $this->taxonomies_service->rename_term( $taxonomy, $term_id, $name );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d has been renamed to `%3$s`.', 'telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'delete' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm delete for %1$s #%2$d', 'telepilot' ), rtrim( $resource, 's' ), $term_id ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $taxonomy, $term_id, $identity ) {
							return $this->taxonomies_service->build_delete_confirmation_keyboard( $taxonomy, $term_id, $identity['telegram_user_id'] );
						},
						'terms_delete_confirm'
					),
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Supported %1$s commands: `/%1$s list`, `/%1$s search keyword`, `/%1$s create Name`, `/%1$s rename 12 New Name`, `/%1$s delete 12`', 'telepilot' ), $resource ) );
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That term action is invalid or expired.', 'telepilot' ) );
		}

		$term = $this->taxonomies_service->delete_term( $taxonomy, $term_id );
		if ( is_wp_error( $term ) ) {
			return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
		}

		$resource = 'post_tag' === $taxonomy ? 'tags' : 'categories';

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Term #%1$d `%2$s` has been deleted.', 'telepilot' ), $term_id, $term->name ),
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

	private function safe_reply_markup( $callback, $context = '' ) {
		try {
			$markup = is_callable( $callback ) ? call_user_func( $callback ) : array();
		} catch ( Throwable $throwable ) {
			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'    => 'telegram_keyboard_build_failed',
					'resource_type'  => 'telegram_keyboard',
					'resource_id'    => $context ? (string) $context : null,
					'was_successful' => 0,
					'failure_reason' => $throwable->getMessage(),
					'context'        => array(
						'context' => $context,
						'file'    => $throwable->getFile(),
						'line'    => $throwable->getLine(),
					),
				)
			);

			return array();
		}

		return is_array( $markup ) ? $markup : array();
	}

	private function safe_home_keyboard( $identity ) {
		return $this->safe_reply_markup(
			function() use ( $identity ) {
				return $this->build_home_keyboard( $identity );
			},
			'home'
		);
	}

	private function build_home_keyboard( $identity ) {
		$rows   = array();
		$rows[] = array(
			array(
				'text'          => __( 'Site Overview', 'telepilot' ),
				'callback_data' => '/site',
			),
			array(
				'text'          => __( 'Menu', 'telepilot' ),
				'callback_data' => '/menu',
			),
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Posts', 'telepilot' ),
						'callback_data' => '/posts list',
					),
					array(
						'text'          => __( 'Pages', 'telepilot' ),
						'callback_data' => '/pages list',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Comments', 'telepilot' ),
						'callback_data' => '/comments pending',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'upload_files' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Media', 'telepilot' ),
						'callback_data' => '/media list',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Users', 'telepilot' ),
						'callback_data' => '/users list',
					),
				);
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' ) ) {
				$rows[] = array(
					array(
						'text'          => __( 'Plugins', 'telepilot' ),
						'callback_data' => '/plugins list',
					),
				);
			}

			$admin_row = array(
				array(
					'text' => __( 'Open wp-admin', 'telepilot' ),
					'url'  => admin_url(),
				),
			);

			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_options' ) ) {
				$admin_row[] = array(
					'text' => __( 'Settings', 'telepilot' ),
					'url'  => admin_url( 'admin.php?page=telepilot' ),
				);
			}

			$rows[] = $admin_row;
		}

		return Telepilot_Telegram_Response_Builder::keyboard( $rows );
	}
}
