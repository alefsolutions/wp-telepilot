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
	private $post_editor_service;
	private $pages_service;
	private $media_service;
	private $users_service;
	private $plugins_service;
	private $taxonomies_service;
	private $notifications_command_service;
	private $site_settings_command_service;
	private $confirmation_service;

	public function __construct( Telepilot_User_Linking_Service $linking_service, Telepilot_Permission_Service $permission_service, Telepilot_Dashboard_Service $dashboard_service, Telepilot_Comments_Service $comments_service, Telepilot_Posts_Service $posts_service, Telepilot_Post_Editor_Service $post_editor_service, Telepilot_Pages_Service $pages_service, Telepilot_Media_Service $media_service, Telepilot_Users_Service $users_service, Telepilot_Plugins_Service $plugins_service, Telepilot_Taxonomies_Service $taxonomies_service, Telepilot_Notifications_Command_Service $notifications_command_service, Telepilot_Site_Settings_Command_Service $site_settings_command_service, Telepilot_Confirmation_Service $confirmation_service ) {
		$this->linking_service    = $linking_service;
		$this->permission_service = $permission_service;
		$this->dashboard_service  = $dashboard_service;
		$this->comments_service   = $comments_service;
		$this->posts_service      = $posts_service;
		$this->post_editor_service = $post_editor_service;
		$this->pages_service      = $pages_service;
		$this->media_service      = $media_service;
		$this->users_service      = $users_service;
		$this->plugins_service    = $plugins_service;
		$this->taxonomies_service = $taxonomies_service;
		$this->notifications_command_service = $notifications_command_service;
		$this->site_settings_command_service = $site_settings_command_service;
		$this->confirmation_service = $confirmation_service;
	}

	public function route( $update, $identity ) {
		$command = $this->parse_command( $update );
		$pending = $this->maybe_handle_pending_post_creation( $command, $update, $identity );

		if ( is_array( $pending ) ) {
			return $pending;
		}

		if ( empty( $command['name'] ) ) {
			return Telepilot_Telegram_Response_Builder::success(
				__( 'Webhook received. No supported command was detected yet.', 'wp-telepilot' ),
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
				return $this->handle_settings( $command, $identity );

			case '/notifications':
				return $this->handle_notifications( $command, $identity );

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

			case 'tp:postflow':
				return $this->handle_post_flow_callback( $command, $identity );

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
				return $this->unknown_command_response( $command['name'], $identity );
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
		$chat_id = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'wp-telepilot' );
		$blocks  = array(
			Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot', 'wp-telepilot' ) ),
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$roles    = ! empty( $identity['wp_user']->roles ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $identity['wp_user']->roles ) ) : __( 'no role', 'wp-telepilot' );
			$blocks[] = implode(
				"\n",
				array(
					sprintf( __( 'Linked WordPress user: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $identity['wp_user']->display_name ? $identity['wp_user']->display_name : $identity['wp_user']->user_login ) ),
					sprintf( __( 'Roles: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $roles ) ),
					sprintf( __( 'Current chat ID: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $chat_id ) ),
				)
			);
			$blocks[] = __( 'Use /menu to open the command hub or /site to view your site overview.', 'wp-telepilot' );
		} else {
			$blocks[] = __( 'This bot is ready to connect to your WordPress site.', 'wp-telepilot' );
			$blocks[] = implode(
				"\n",
				array(
					sprintf( __( 'Current chat ID: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $chat_id ) ),
					__( 'Next steps:', 'wp-telepilot' ),
					__( '1. Add this chat ID to WP Telepilot Allowed Chat IDs if you use an allow list.', 'wp-telepilot' ),
					__( '2. Generate a one-time link code from your WordPress profile.', 'wp-telepilot' ),
					sprintf( __( '3. Send %s here to connect your Telegram account.', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( '/link CODE' ) ),
				)
			);
			$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Use /help to review commands once you are linked.', 'wp-telepilot' ) );
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks( $blocks ),
			array(
				'command'      => '/start',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_help( $identity ) {
		$commands = array();

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			$commands[] = Telepilot_Telegram_Response_Builder::bold( __( 'Setup Flow', 'wp-telepilot' ) );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/start' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/chatid' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/link CODE' );
			$commands[] = '';
		}

		$commands[] = Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Core Commands', 'wp-telepilot' ) ) );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/start' ) . ' ' . __( 'Onboarding', 'wp-telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/help' ) . ' ' . __( 'Show commands', 'wp-telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/menu' ) . ' ' . __( 'Open the command hub', 'wp-telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/site' ) . ' ' . __( 'Show site overview', 'wp-telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/chatid' ) . ' ' . __( 'Reveal the current chat ID', 'wp-telepilot' );
		$commands[] = Telepilot_Telegram_Response_Builder::code( '/link CODE' ) . ' ' . __( 'Link Telegram to WordPress', 'wp-telepilot' );

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/unlink' ) . ' ' . __( 'Unlink Telegram', 'wp-telepilot' );
			$commands[] = Telepilot_Telegram_Response_Builder::code( '/settings' ) . ' ' . __( 'WP Telepilot settings info', 'wp-telepilot' );
			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_options' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/notifications list' ) . ' ' . __( 'Review and toggle Telegram alerts', 'wp-telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/comments pending' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts new' ) . ' ' . __( 'Start a guided draft flow', 'wp-telepilot' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts open 123' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/posts help' ) . ' ' . __( 'Show posts examples', 'wp-telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_pages' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/pages help' ) . ' ' . __( 'Show pages examples', 'wp-telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'upload_files' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/media help' ) . ' ' . __( 'Show media examples', 'wp-telepilot' );
			}

			$future_commands = array();

			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/users help' ) . ' ' . __( 'Show user-management examples', 'wp-telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins updates' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/plugins help' ) . ' ' . __( 'Show plugin-management examples', 'wp-telepilot' );
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_categories' ) ) {
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/categories list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/categories post 12' ) . ' ' . __( 'Start a post in a category', 'wp-telepilot' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/categories search keyword' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/tags list' );
				$commands[] = Telepilot_Telegram_Response_Builder::code( '/tags search keyword' );
			}

			if ( ! empty( $future_commands ) ) {
				$commands[] = '';
				$commands[] = __( 'Coming next on the roadmap:', 'wp-telepilot' );
				$commands   = array_merge( $commands, $future_commands );
			}
		}

		$commands[] = '';
		$commands[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use module-specific help like /users help when you need concrete examples.', 'wp-telepilot' ) );

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
			Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'menu', __( 'WP Telepilot Menu', 'wp-telepilot' ) ) ) .
			"\n\n" .
			__(
				'Choose an area below. Only the sections your linked WordPress account can access are shown here.',
				'wp-telepilot'
			),
			array(
				'command'      => '/menu',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function handle_settings( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'manage_options' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$args       = isset( $command['args'] ) ? $command['args'] : array();
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'summary';

		if ( in_array( $subcommand, array( 'summary', 'list' ), true ) ) {
			$subcommand = 'summary';
		}

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->site_settings_command_service->render_help_message(),
				array(
					'command'      => '/settings',
					'reply_markup' => $this->site_settings_command_service->build_keyboard(),
				)
			);
		}

		if ( 'summary' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->site_settings_command_service->render_summary_message( $this->site_settings_command_service->get_summary() ),
				array(
					'command'      => '/settings',
					'reply_markup' => $this->site_settings_command_service->build_keyboard(),
				)
			);
		}

		if ( ! empty( $args[1] ) && in_array( strtolower( (string) $args[1] ), array( 'help', '?' ), true ) ) {
			$field_help = $this->site_settings_command_service->render_field_help( $subcommand );
			if ( '' !== $field_help ) {
				return Telepilot_Telegram_Response_Builder::success_html(
					$field_help,
					array(
						'command'      => '/settings',
						'reply_markup' => $this->site_settings_command_service->build_keyboard(),
					)
				);
			}
		}

		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$value = implode( ' ', array_slice( $args, 1 ) );
		if ( '' === trim( $value ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				$this->site_settings_command_service->render_help_message(),
				array(
					'command' => '/settings',
				)
			);
		}

		$result = $this->site_settings_command_service->update_core_setting( $subcommand, $value );
		if ( is_wp_error( $result ) ) {
			if ( 'telepilot_settings_unsupported_field' === $result->get_error_code() ) {
				return $this->invalid_subcommand_response(
					'/settings',
					$subcommand,
					$this->site_settings_command_service->render_help_message(),
					$this->site_settings_command_service->build_keyboard()
				);
			}

			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'site_setting_updated',
				'resource_type'    => 'site_setting',
				'resource_id'      => (string) $result['field'],
				'before_state'     => $result['before_state'],
				'after_state'      => $result['after_state'],
			)
		);

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Setting `%1$s` has been %2$s.', 'wp-telepilot' ), $subcommand, isset( $result['label_text'] ) ? $result['label_text'] : __( 'updated', 'wp-telepilot' ) ),
			array(
				'command' => '/settings',
			)
		);
	}

	private function handle_notifications( $command, $identity ) {
		$permission_result = $this->permission_service->require_capability( $identity, 'manage_options' );

		if ( true !== $permission_result ) {
			return $permission_result;
		}

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->notifications_command_service->render_help_message(),
				array(
					'command'      => '/notifications',
					'reply_markup' => $this->notifications_command_service->build_list_keyboard(
						$this->notifications_command_service->list_page( 1 )
					),
				)
			);
		}

		if ( in_array( $subcommand, array( 'list', 'status' ), true ) ) {
			$result = $this->notifications_command_service->list_page( $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->notifications_command_service->render_page_message( $result ),
				array(
					'command'      => '/notifications',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result ) {
							return $this->notifications_command_service->build_list_keyboard( $result );
						},
						'notifications_list'
					),
				)
			);
		}

		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$key = isset( $args[1] ) ? (string) $args[1] : '';
		if ( '' === $key || ! in_array( $subcommand, array( 'enable', 'disable', 'toggle' ), true ) ) {
			return $this->invalid_subcommand_response(
				'/notifications',
				$subcommand,
				$this->notifications_command_service->render_help_message(),
				$this->notifications_command_service->build_list_keyboard( $this->notifications_command_service->list_page( 1 ) )
			);
		}

		$enabled = 'enable' === $subcommand ? true : false;

		if ( 'toggle' === $subcommand ) {
			$page_result = $this->notifications_command_service->list_page( 1, 100 );
			foreach ( $page_result['items'] as $item ) {
				if ( $item['key'] === sanitize_key( $key ) ) {
					$enabled = ! $item['enabled'];
					break;
				}
			}
		}

		$result = $this->notifications_command_service->update_option_state( $key, $enabled );
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'notification_setting_updated',
				'resource_type'    => 'notification_setting',
				'resource_id'      => (string) $result['key'],
				'before_state'     => $result['before_state'],
				'after_state'      => $result['after_state'],
			)
		);

		return Telepilot_Telegram_Response_Builder::success(
			sprintf(
				__( 'Notification `%1$s` has been %2$s.', 'wp-telepilot' ),
				$result['key'],
				$result['label_text']
			),
			array(
				'command' => '/notifications',
			)
		);
	}

	private function handle_chat_id( $identity ) {
		$chat_id          = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : __( 'Unavailable', 'wp-telepilot' );
		$telegram_user_id = ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : __( 'Unavailable', 'wp-telepilot' );
		$username         = ! empty( $identity['telegram_username'] ) ? (string) $identity['telegram_username'] : '';
		$linked_status    = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User
			? sprintf(
				__( 'Linked WordPress user: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $identity['wp_user']->display_name ? $identity['wp_user']->display_name : $identity['wp_user']->user_login )
			)
			: __( 'Linked WordPress user: not linked yet.', 'wp-telepilot' );

		$detail_lines = array(
			sprintf( __( 'Chat ID: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $chat_id ) ),
			sprintf( __( 'Telegram user ID: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $telegram_user_id ) ),
		);

		if ( '' !== $username ) {
			$detail_lines[] = sprintf( __( 'Telegram username: @%s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $username ) );
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'link', __( 'Current Chat Details', 'wp-telepilot' ) ) ),
					implode( "\n", $detail_lines ),
					$linked_status,
					__( 'Add the chat ID to Allowed Chat IDs if you want this conversation to be authorized.', 'wp-telepilot' ),
				)
			),
			array(
				'command'      => '/chatid',
				'reply_markup' => $this->safe_home_keyboard( $identity ),
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
				__( 'Usage: `/link CODE`', 'wp-telepilot' ),
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
			__( 'Telegram has been unlinked from your WordPress account.', 'wp-telepilot' ),
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

		list( $args, $page ) = $this->extract_page_from_args( $command['args'] );
		$subcommand = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : 'pending';
		$comment_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->comments_service->render_help_message(),
				array(
					'command'      => '/comments',
					'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( in_array( $subcommand, array( 'pending', 'approved', 'spam', 'trash' ), true ) && ! $comment_id ) {
			switch ( $subcommand ) {
				case 'approved':
					$result  = $this->comments_service->approved_page( $page );
					$heading = __( 'Approved Comments', 'wp-telepilot' );
					break;
				case 'spam':
					$result  = $this->comments_service->spam_page( $page );
					$heading = __( 'Spam Comments', 'wp-telepilot' );
					break;
				case 'trash':
					$result  = $this->comments_service->trash_page( $page );
					$heading = __( 'Trashed Comments', 'wp-telepilot' );
					break;
				default:
					$result  = $this->comments_service->pending_page( $page );
					$heading = __( 'Pending Comments', 'wp-telepilot' );
					break;
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->comments_service->render_page_message( $result, $heading ),
				array(
					'command'      => '/comments',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result, $subcommand ) {
							return $this->comments_service->build_list_keyboard( $result['items'], $subcommand, '', $result['page'], $result['total_pages'] );
						},
						'comments_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/comments search keyword`', 'wp-telepilot' ) );
			}

			$result = $this->comments_service->search_page( $term, $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->comments_service->render_page_message( $result, sprintf( __( 'Comment Search: %s', 'wp-telepilot' ), $term ) ),
				array(
					'command'      => '/comments',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $result, $term ) {
							return $this->comments_service->build_list_keyboard( $result['items'], 'search', $term, $result['page'], $result['total_pages'] );
						},
						'comments_search'
					),
				)
			);
		}

		if ( 'details' === $subcommand && $comment_id ) {
			$comment = $this->comments_service->get_comment_details( $comment_id );

			if ( is_wp_error( $comment ) ) {
				return Telepilot_Telegram_Response_Builder::error( $comment->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->comments_service->render_comment_details_message( $comment ),
				array(
					'command' => '/comments',
				)
			);
		}

		$private_chat_result = $this->require_private_action( $identity );

		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		if ( ! $comment_id ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				$this->comments_service->render_help_message(),
				array(
					'command' => '/comments',
				)
			);
		}

		if ( 'reply' === $subcommand ) {
			$content = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $content ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/comments reply 123 Thank you for your comment`', 'wp-telepilot' ) );
			}

			$result = $this->comments_service->reply_to_comment( $comment_id, $content, $identity['wp_user'] );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_comment_activity( $identity, 'comment_replied', $comment_id, 'reply', $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Reply [%1$d] has been posted to comment [%2$d].', 'wp-telepilot' ), $result['reply']->comment_ID, $comment_id ),
				array(
					'command' => '/comments',
				)
			);
		}

		if ( ! in_array( $subcommand, array( 'approve', 'reject', 'spam', 'trash', 'restore', 'unspam', 'delete' ), true ) ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				$this->comments_service->render_help_message(),
				array(
					'command' => '/comments',
				)
			);
		}

		if ( in_array( $subcommand, array( 'approve', 'restore', 'unspam' ), true ) ) {
			$result = $this->comments_service->moderate_comment( $comment_id, $subcommand );

			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_comment_activity( $identity, 'comment_moderated', $comment_id, $subcommand, $result );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Comment #%1$d has been %2$s.', 'wp-telepilot' ), $comment_id, $result['label'] ),
				array(
					'command' => '/comments',
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Confirm moderation for comment [%1$d]: %2$s', 'wp-telepilot' ), $comment_id, $subcommand ),
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That moderation action is incomplete.', 'wp-telepilot' ) );
		}

		$payload = $this->confirmation_service->consume_token( $token );

		if ( empty( $payload ) || empty( $payload['telegram_user_id'] ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['comment_id'] !== $comment_id || (string) $payload['action'] !== $action ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That moderation action is invalid or expired.', 'wp-telepilot' ) );
		}

		$result = $this->comments_service->moderate_comment( $comment_id, $action );

		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_comment_activity( $identity, 'comment_moderated', $comment_id, $action, $result );

		return Telepilot_Telegram_Response_Builder::success(
			sprintf(
				/* translators: 1: comment id, 2: action label. */
				__( 'Comment #%1$d has been %2$s.', 'wp-telepilot' ),
				$comment_id,
				$result['label']
			),
			array(
				'command' => '/comments',
			)
		);
	}

	private function log_comment_activity( $identity, $action_name, $comment_id, $action, $result ) {
		$before_state = array();
		$after_state  = array();

		if ( isset( $result['before_status'] ) ) {
			$before_state['status'] = $result['before_status'];
		}

		if ( isset( $result['after_status'] ) ) {
			$after_state['status'] = $result['after_status'];
		}

		if ( isset( $result['reply'] ) && $result['reply'] instanceof WP_Comment ) {
			$after_state['reply_id'] = (int) $result['reply']->comment_ID;
		}

		if ( '' !== $action ) {
			$after_state['action'] = $action;
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => $action_name,
				'resource_type'    => 'comment',
				'resource_id'      => (string) $comment_id,
				'before_state'     => $before_state,
				'after_state'      => $after_state,
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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->posts_service->render_page_message( $posts, sprintf( __( 'Post Search: %s', 'wp-telepilot' ), $term ) ),
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
				__( 'Post: [%1$d] %2$s', 'wp-telepilot' ),
				$post_id,
				Telepilot_Telegram_Response_Builder::escape( $post ? get_the_title( $post ) : __( 'Post', 'wp-telepilot' ) )
			);
			$message_lines[] = sprintf(
				__( 'Editor link: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::link( __( 'Open secure editor', 'wp-telepilot' ), $link['url'] )
			);
			$message_lines[] = sprintf(
				__( 'Expires: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( wp_date( 'Y-m-d H:i:s T', (int) $link['expires_at'] ) )
			);
			if ( $preview_url ) {
				$message_lines[] = sprintf(
					__( 'Preview: %s', 'wp-telepilot' ),
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
				sprintf( __( 'Post #%1$d has been %2$s.', 'wp-telepilot' ), $post_id, $result['label'] ),
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
				sprintf( __( 'Post: [%1$d] %2$s', 'wp-telepilot' ), $post_id, Telepilot_Telegram_Response_Builder::escape( get_the_title( $result['post'] ) ) ),
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
				sprintf( __( 'Post #%1$d excerpt has been updated.', 'wp-telepilot' ), $post_id ),
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
				sprintf( __( 'Post #%1$d %2$s have been updated.', 'wp-telepilot' ), $post_id, 'categories' === $subcommand ? __( 'categories', 'wp-telepilot' ) : __( 'tags', 'wp-telepilot' ) ),
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
				sprintf( __( 'Post #%1$d has been scheduled for %2$s.', 'wp-telepilot' ), $post_id, $result['scheduled_at'] ),
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
				sprintf( __( 'Confirm draft for post [%d]', 'wp-telepilot' ), $post_id ),
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
				sprintf( __( 'Confirm %1$s for post [%2$d]', 'wp-telepilot' ), $subcommand, $post_id ),
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

	private function handle_post_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$post_action = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$post_id     = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token       = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$capability        = in_array( $post_action, array( 'trash', 'restore', 'delete' ), true ) ? 'delete_post' : 'edit_post';
		$permission_result = $this->permission_service->require_capability( $identity, $capability, $post_id );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['post_id'] !== $post_id || (string) $payload['action'] !== $post_action ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That post action is invalid or expired.', 'wp-telepilot' ) );
		}

		switch ( $post_action ) {
			case 'unpublish':
				$result = $this->posts_service->unpublish( $post_id );
				break;
			case 'trash':
				$result = $this->posts_service->trash( $post_id );
				break;
			case 'restore':
				$result = $this->posts_service->restore( $post_id );
				break;
			case 'delete':
				$result = $this->posts_service->delete( $post_id );
				break;
			default:
				return Telepilot_Telegram_Response_Builder::error( __( 'That post action is not supported.', 'wp-telepilot' ) );
		}
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action(
			$identity,
			'delete' === $post_action ? 'post_deleted' : 'post_state_changed',
			'post',
			$post_id,
			$post_action,
			$result
		);

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Post #%1$d has been %2$s.', 'wp-telepilot' ), $post_id, $result['label'] ),
			array( 'command' => '/posts' )
		);
	}

	private function handle_post_flow_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$action       = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$state_token  = isset( $command['args'][1] ) ? (string) $command['args'][1] : '';
		$flow_service = $this->get_flow_state_service();
		$state        = $flow_service->get_flow( $state_token );

		if ( empty( $state ) || empty( $identity['telegram_user_id'] ) || (string) $state['telegram_user_id'] !== (string) $identity['telegram_user_id'] ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That post category selection is invalid or expired.', 'wp-telepilot' ) );
		}

		$post_id = ! empty( $state['post_id'] ) ? absint( $state['post_id'] ) : 0;
		if ( ! $post_id ) {
			$flow_service->delete_flow( $state_token );

			return Telepilot_Telegram_Response_Builder::error( __( 'That post category selection is incomplete.', 'wp-telepilot' ) );
		}

		$permission_result = $this->permission_service->require_capability( $identity, 'edit_post', $post_id );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$selected_ids = ! empty( $state['selected_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $state['selected_ids'] ) ) ) : array();
		$page         = isset( $command['args'][3] ) ? max( 1, absint( $command['args'][3] ) ) : 1;

		switch ( $action ) {
			case 'togglecat':
				$term_id = isset( $command['args'][2] ) ? absint( $command['args'][2] ) : 0;
				if ( $term_id ) {
					$selected_ids = $this->toggle_id_in_list( $selected_ids, $term_id );
				}

				$state['selected_ids'] = $selected_ids;
				$flow_service->update_flow( $state_token, $state );

				return $this->render_post_category_picker_state( $state_token, $state, $page );

			case 'pagecat':
				$page = isset( $command['args'][2] ) ? max( 1, absint( $command['args'][2] ) ) : 1;
				$flow_service->update_flow( $state_token, $state );

				return $this->render_post_category_picker_state( $state_token, $state, $page );

			case 'clearcat':
				$page                 = isset( $command['args'][2] ) ? max( 1, absint( $command['args'][2] ) ) : 1;
				$state['selected_ids'] = array();
				$flow_service->update_flow( $state_token, $state );

				return $this->render_post_category_picker_state( $state_token, $state, $page );

			case 'applycat':
				$result = $this->posts_service->assign_terms( $post_id, 'category', $selected_ids );
				if ( is_wp_error( $result ) ) {
					return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
				}

				$flow_service->delete_flow( $state_token );
				$this->log_content_action( $identity, 'post_updated', 'post', $post_id, 'categories', $result );

				return Telepilot_Telegram_Response_Builder::success_html(
					$this->posts_service->render_post_categories_saved_message(
						$result['post'],
						isset( $result['after_state']['category'] ) ? $result['after_state']['category'] : array()
					),
					array(
						'command'      => '/posts',
						'reply_markup' => $this->safe_reply_markup(
							function() use ( $result ) {
								return $this->posts_service->build_post_followup_keyboard( $result['post'] );
							},
							'post_categories_saved'
						),
					)
				);

			case 'cancelcat':
				$flow_service->delete_flow( $state_token );
				$post = get_post( $post_id );

				return Telepilot_Telegram_Response_Builder::success_html(
					Telepilot_Telegram_Response_Builder::join_blocks(
						array(
							Telepilot_Telegram_Response_Builder::bold( __( 'Category Selection Closed', 'wp-telepilot' ) ),
							sprintf( __( 'No category changes were saved for post [%d].', 'wp-telepilot' ), $post_id ),
						)
					),
					array(
						'command'      => '/posts',
						'reply_markup' => $this->safe_reply_markup(
							function() use ( $post ) {
								return $this->posts_service->build_post_followup_keyboard( $post );
							},
							'post_categories_cancelled'
						),
					)
				);
		}

		return Telepilot_Telegram_Response_Builder::error( __( 'That post category action is not supported.', 'wp-telepilot' ) );
	}

	private function start_pending_post_creation( $identity, $category_ids = array() ) {
		$flow_service      = $this->get_flow_state_service();
		$category_ids      = array_values( array_filter( array_map( 'absint', (array) $category_ids ) ) );
		$primary_category  = ! empty( $category_ids ) ? get_term( $category_ids[0], 'category' ) : null;
		$pending_started   = $flow_service->start_pending_post(
			$identity['telegram_user_id'],
			array(
				'chat_id'       => ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '',
				'wp_user_id'    => $identity['wp_user']->ID,
				'category_ids'  => $category_ids,
				'started_at'    => time(),
			)
		);

		if ( ! $pending_started ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'WP Telepilot could not start that post creation flow.', 'wp-telepilot' ) );
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			$this->posts_service->render_creation_prompt_message( $primary_category instanceof WP_Term ? $primary_category : null ),
			array(
				'command'      => '/posts',
				'reply_markup' => $this->safe_reply_markup(
					function() {
						return $this->posts_service->build_hub_keyboard();
					},
					'post_create_prompt'
				),
			)
		);
	}

	private function maybe_handle_pending_post_creation( $command, $update, $identity ) {
		if ( empty( $identity['telegram_user_id'] ) ) {
			return null;
		}

		$flow_service = $this->get_flow_state_service();
		$pending      = $flow_service->get_pending_post( $identity['telegram_user_id'] );

		if ( empty( $pending ) ) {
			return null;
		}

		if ( ! empty( $command['name'] ) && '/' === substr( (string) $command['name'], 0, 1 ) ) {
			$flow_service->clear_pending_post( $identity['telegram_user_id'] );

			return null;
		}

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			$flow_service->clear_pending_post( $identity['telegram_user_id'] );

			return Telepilot_Telegram_Response_Builder::error( __( 'This post creation flow is no longer linked to a WordPress user.', 'wp-telepilot' ) );
		}

		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		if ( ! empty( $pending['chat_id'] ) && (string) $pending['chat_id'] !== (string) $identity['chat_id'] ) {
			$flow_service->clear_pending_post( $identity['telegram_user_id'] );

			return Telepilot_Telegram_Response_Builder::error( __( 'This post creation flow belongs to a different chat. Start it again from the current conversation.', 'wp-telepilot' ) );
		}

		if ( ! empty( $pending['wp_user_id'] ) && (int) $pending['wp_user_id'] !== (int) $identity['wp_user']->ID ) {
			$flow_service->clear_pending_post( $identity['telegram_user_id'] );

			return Telepilot_Telegram_Response_Builder::error( __( 'This post creation flow no longer matches the linked WordPress account.', 'wp-telepilot' ) );
		}

		$title = isset( $update['message']['text'] ) ? trim( wp_strip_all_tags( (string) $update['message']['text'] ) ) : '';
		if ( '' === $title ) {
			return null;
		}

		$flow_service->clear_pending_post( $identity['telegram_user_id'] );

		return $this->create_post_and_open_category_picker(
			$identity,
			$title,
			! empty( $pending['category_ids'] ) ? (array) $pending['category_ids'] : array()
		);
	}

	private function create_post_and_open_category_picker( $identity, $title, $category_ids ) {
		$result = $this->posts_service->create_draft( $title, $category_ids );
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action( $identity, 'post_created', 'post', $result['post']->ID, 'create', $result );

		return $this->open_post_category_picker(
			$result['post']->ID,
			$identity,
			isset( $result['after_state']['category'] ) ? (array) $result['after_state']['category'] : array()
		);
	}

	private function open_post_category_picker( $post_id, $identity, $selected_ids = null ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'Post not found.', 'wp-telepilot' ) );
		}

		$flow_service = $this->get_flow_state_service();
		if ( null === $selected_ids ) {
			$selected_ids = $this->posts_service->get_assigned_term_ids( $post_id, 'category' );
		}

		$state = array(
			'post_id'          => $post_id,
			'telegram_user_id' => (string) $identity['telegram_user_id'],
			'selected_ids'     => array_values( array_filter( array_map( 'absint', (array) $selected_ids ) ) ),
			'opened_at'        => time(),
		);

		$token = $flow_service->create_flow( $state );
		if ( '' === $token ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'WP Telepilot could not open that category picker.', 'wp-telepilot' ) );
		}

		return $this->render_post_category_picker_state( $token, $state, 1 );
	}

	private function render_post_category_picker_state( $state_token, $state, $page ) {
		$post_id            = ! empty( $state['post_id'] ) ? absint( $state['post_id'] ) : 0;
		$selected_ids       = ! empty( $state['selected_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $state['selected_ids'] ) ) ) : array();
		$post               = get_post( $post_id );
		$categories_result  = $this->taxonomies_service->list_terms( 'category', $page );

		if ( is_wp_error( $categories_result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $categories_result->get_error_message() );
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			$this->posts_service->render_category_picker_message( $post, $categories_result, $selected_ids ),
			array(
				'command'      => '/posts',
				'reply_markup' => $this->safe_reply_markup(
					function() use ( $state_token, $categories_result, $selected_ids, $post ) {
						return $this->posts_service->build_category_picker_keyboard( $state_token, $categories_result, $selected_ids, $post );
					},
					'post_category_picker'
				),
			)
		);
	}

	private function toggle_id_in_list( $ids, $target_id ) {
		$ids       = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$target_id = absint( $target_id );

		if ( ! $target_id ) {
			return $ids;
		}

		$existing_key = array_search( $target_id, $ids, true );
		if ( false !== $existing_key ) {
			unset( $ids[ $existing_key ] );

			return array_values( $ids );
		}

		$ids[] = $target_id;

		return array_values( array_unique( $ids ) );
	}

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
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->pages_service->render_page_message( $pages, sprintf( __( 'Page Search: %s', 'wp-telepilot' ), $term ) ),
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
			return Telepilot_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
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
			return Telepilot_Telegram_Response_Builder::success( sprintf( __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ), array( 'command' => '/pages' ) );
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
				sprintf( __( 'Page #%1$d title has been updated.', 'wp-telepilot' ), $page_id ),
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
				sprintf( __( 'Page #%1$d slug has been updated.', 'wp-telepilot' ), $page_id ),
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
				sprintf( __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ),
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
				sprintf( __( 'Confirm %1$s for page #%2$d', 'wp-telepilot' ), $subcommand, $page_id ),
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

	private function handle_page_callback( $command, $identity ) {
		$private_chat_result = $this->require_private_action( $identity );
		if ( true !== $private_chat_result ) {
			return $private_chat_result;
		}

		$page_action = isset( $command['args'][0] ) ? (string) $command['args'][0] : '';
		$page_id     = isset( $command['args'][1] ) ? absint( $command['args'][1] ) : 0;
		$token       = isset( $command['args'][2] ) ? (string) $command['args'][2] : '';

		$permission_result = $this->permission_service->require_capability(
			$identity,
			'delete' === $page_action ? 'delete_post' : 'edit_page',
			$page_id
		);
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || (int) $payload['page_id'] !== $page_id || (string) $payload['action'] !== $page_action ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That page action is invalid or expired.', 'wp-telepilot' ) );
		}

		switch ( $page_action ) {
			case 'restore':
				$result = $this->pages_service->restore( $page_id );
				break;
			case 'trash':
				$result = $this->pages_service->trash( $page_id );
				break;
			case 'delete':
				$result = $this->pages_service->delete( $page_id );
				break;
			default:
				return Telepilot_Telegram_Response_Builder::error( __( 'That page action is not supported.', 'wp-telepilot' ) );
		}
		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_content_action(
			$identity,
			'delete' === $page_action ? 'page_deleted' : 'page_state_changed',
			'page',
			$page_id,
			$page_action,
			$result
		);

		return Telepilot_Telegram_Response_Builder::success(
			sprintf( __( 'Page #%1$d has been %2$s.', 'wp-telepilot' ), $page_id, $result['label'] ),
			array( 'command' => '/pages' )
		);
	}

	private function log_content_action( $identity, $action_name, $resource_type, $resource_id, $action, $result ) {
		$before_state = isset( $result['before_state'] ) ? $result['before_state'] : array();
		$after_state  = isset( $result['after_state'] ) ? $result['after_state'] : array();

		if ( isset( $result['before_status'] ) ) {
			$before_state = array_merge( $before_state, array( 'status' => $result['before_status'] ) );
		}

		if ( isset( $result['after_status'] ) ) {
			$after_state = array_merge( $after_state, array( 'status' => $result['after_status'] ) );
		}

		if ( ! empty( $action ) ) {
			$after_state['action'] = $action;
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => $action_name,
				'resource_type'    => $resource_type,
				'resource_id'      => (string) $resource_id,
				'before_state'     => $before_state,
				'after_state'      => $after_state,
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
				$this->media_service->render_page_message( $items, __( 'Recent Media', 'wp-telepilot' ) ),
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
				$this->media_service->render_page_message( $items, sprintf( __( 'Media Search: %s', 'wp-telepilot' ), $term ) ),
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
		if ( 'details' === $subcommand && $attachment_id ) {
			$details = $this->media_service->get_item_details( $attachment_id );
			if ( is_wp_error( $details ) ) {
				return Telepilot_Telegram_Response_Builder::error( $details->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->media_service->render_details_message( $details ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $details, $attachment_id ) {
							return $this->media_service->build_item_keyboard( $attachment_id, ! empty( $details['url'] ) ? (string) $details['url'] : '' );
						},
						'media_details'
					),
				)
			);
		}

		if ( 'open' === $subcommand && $attachment_id ) {
			$details = $this->media_service->get_item_details( $attachment_id );
			if ( is_wp_error( $details ) ) {
				return Telepilot_Telegram_Response_Builder::error( $details->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->media_service->render_details_message( $details ),
				array(
					'command'      => '/media',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $details, $attachment_id ) {
							return $this->media_service->build_item_keyboard( $attachment_id, ! empty( $details['url'] ) ? (string) $details['url'] : '' );
						},
						'media_open'
					),
				)
			);
		}

		return $this->invalid_subcommand_response(
			'/media',
			$subcommand,
			$this->media_service->render_help_message(),
			$this->safe_home_keyboard( $identity )
		);
	}

	private function handle_media_callback( $command, $identity ) {
		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Media Is Read-Only', 'wp-telepilot' ) ),
					__( 'Media write actions are disabled in this release.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Use wp-admin for uploads, deletes, or metadata changes.', 'wp-telepilot' ) ),
				)
			),
			array(
				'command' => '/media',
			)
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

		if ( 'email-reset-password' === $subcommand ) {
			$subcommand = 'send-reset';
		}

		if ( 'welcome-email' === $subcommand ) {
			$subcommand = 'send-welcome';
		}

		if ( 'list' === $subcommand ) {
			$users = $this->users_service->recent_page( $page );
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_page_message( $users, __( 'Recent Users', 'wp-telepilot' ) ),
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
					Telepilot_Telegram_Response_Builder::bold( __( 'Users Search', 'wp-telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users search keyword' )
				);
			}

			$users = $this->users_service->search_page( $term, $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_page_message( $users, sprintf( __( 'User Search: %s', 'wp-telepilot' ), $term ) ),
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

		if ( 'details' === $subcommand ) {
			$user_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;
			if ( ! $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/users details 123`', 'wp-telepilot' ) );
			}

			$user = $this->users_service->get_user_details( $user_id );
			if ( is_wp_error( $user ) ) {
				return Telepilot_Telegram_Response_Builder::error( $user->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->users_service->render_details_message( $user ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user ) {
							return $this->users_service->build_list_keyboard( array( $user ), 'list', '', 1, 1 );
						},
						'user_details'
					),
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
					Telepilot_Telegram_Response_Builder::bold( __( 'Create User', 'wp-telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users create username email role' ) . "\n" .
					__( 'Example:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/users create jane jane@example.com editor' )
				);
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot create a user with that role.', 'wp-telepilot' ) );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm create user `%1$s` with role `%2$s`', 'wp-telepilot' ), $username, $role ),
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

		if ( 'email' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$email = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $email ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/users email 123 jane@example.com`', 'wp-telepilot' ) );
			}

			$result = $this->users_service->update_email( $user_id, $email );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_email_updated', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d email has been updated.', 'wp-telepilot' ), $user_id ),
				array( 'command' => '/users' )
			);
		}

		if ( 'display-name' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$display_name = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $display_name ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/users display-name 123 Jane Doe`', 'wp-telepilot' ) );
			}

			$result = $this->users_service->update_display_name( $user_id, $display_name );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_display_name_updated', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d display name has been updated.', 'wp-telepilot' ), $user_id ),
				array( 'command' => '/users' )
			);
		}

		if ( 'disable' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm disable for user [%d]', 'wp-telepilot' ), $user_id ),
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
				sprintf( __( 'User #%1$d has been %2$s.', 'wp-telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm password reset for user [%d]', 'wp-telepilot' ), $user_id ),
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
				sprintf( __( 'Confirm password reset email for user [%d]', 'wp-telepilot' ), $user_id ),
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

		if ( 'send-welcome' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'create_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm welcome email resend for user [%d]', 'wp-telepilot' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity ) {
							return $this->users_service->build_confirmation_keyboard( 'send-welcome', $user_id, $identity['telegram_user_id'] );
						},
						'user_send_welcome_confirm'
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
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/users role 123 editor`', 'wp-telepilot' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $role ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'wp-telepilot' ) );
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm role change for user #%1$d to `%2$s`', 'wp-telepilot' ), $user_id, $role ),
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

		if ( 'delete' === $subcommand && $user_id ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'delete_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$reassign_id = isset( $args[2] ) ? absint( $args[2] ) : 0;

			return Telepilot_Telegram_Response_Builder::success(
				$reassign_id
					? sprintf( __( 'Confirm delete for user [%1$d] and reassign content to user [%2$d]', 'wp-telepilot' ), $user_id, $reassign_id )
					: sprintf( __( 'Confirm delete for user [%d]', 'wp-telepilot' ), $user_id ),
				array(
					'command'      => '/users',
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $user_id, $identity, $reassign_id ) {
							return $this->users_service->build_confirmation_keyboard(
								'delete',
								$user_id,
								$identity['telegram_user_id'],
								array(
									'reassign_id' => $reassign_id,
								)
							);
						},
						'users_delete_confirm'
					),
				)
			);
		}

		return $this->invalid_subcommand_response(
			'/users',
			$subcommand,
			$this->users_service->render_help_message(),
			$this->safe_home_keyboard( $identity )
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
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
				sprintf( __( 'User #%1$d `%2$s` has been created.', 'wp-telepilot' ), $result['user']->ID, $result['user']->user_login ),
				array( 'command' => '/users' )
			);
		}

		if ( 'disable' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
			}

			$result = $this->users_service->disable_user( $user_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_disabled', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'wp-telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'reset-password' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
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
				Telepilot_Telegram_Response_Builder::bold( __( 'Password Reset Link Generated', 'wp-telepilot' ) ) .
				"\n\n" .
				sprintf(
					__( 'User: #%1$d', 'wp-telepilot' ),
					$user_id
				) .
				"\n" .
				__( 'Open:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::link( __( 'Reset password', 'wp-telepilot' ), $result['url'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'send-reset' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'edit_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
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
				Telepilot_Telegram_Response_Builder::bold( __( 'Password Reset Email Sent', 'wp-telepilot' ) ) .
				"\n\n" .
				sprintf(
					__( 'User: #%1$d (%2$s)', 'wp-telepilot' ),
					$user_id,
					Telepilot_Telegram_Response_Builder::escape( $result['user']->user_login )
				) .
				"\n" .
				sprintf(
					__( 'Email: %s', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::escape( $result['user']->user_email )
				),
				array( 'command' => '/users' )
			);
		}

		if ( 'send-welcome' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'create_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
			}

			$result = $this->users_service->send_welcome_email( $user_id );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => $identity['wp_user']->ID,
					'telegram_user_id' => $identity['telegram_user_id'],
					'chat_id'          => $identity['chat_id'],
					'action_name'      => 'user_welcome_emailed',
					'resource_type'    => 'user',
					'resource_id'      => (string) $user_id,
					'after_state'      => array(
						'user_login' => $result['user']->user_login,
						'user_email' => $result['user']->user_email,
						'action'     => 'send-welcome',
					),
				)
			);

			return Telepilot_Telegram_Response_Builder::success_html(
				Telepilot_Telegram_Response_Builder::bold( __( 'Welcome Email Sent', 'wp-telepilot' ) ) .
				"\n\n" .
				sprintf(
					__( 'User: #%1$d (%2$s)', 'wp-telepilot' ),
					$user_id,
					Telepilot_Telegram_Response_Builder::escape( $result['user']->user_login )
				) .
				"\n" .
				sprintf(
					__( 'Email: %s', 'wp-telepilot' ),
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
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
			}

			if ( ! $this->users_service->actor_can_assign_role( $identity['wp_user'], $payload['role'] ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'You cannot assign that role.', 'wp-telepilot' ) );
			}

			$result = $this->users_service->assign_role( $user_id, $payload['role'] );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_role_changed', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'wp-telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		if ( 'delete' === $action ) {
			$permission_result = $this->permission_service->require_capability( $identity, 'delete_users' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			if ( (int) $payload['user_id'] !== $user_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'That user action is invalid or expired.', 'wp-telepilot' ) );
			}

			$result = $this->users_service->delete_user( $user_id, isset( $payload['reassign_id'] ) ? absint( $payload['reassign_id'] ) : 0 );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_user_action( $identity, 'user_deleted', $user_id, $result['before_state'], $result['after_state'] );

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'User #%1$d has been %2$s.', 'wp-telepilot' ), $user_id, $result['label'] ),
				array( 'command' => '/users' )
			);
		}

		return Telepilot_Telegram_Response_Builder::error( __( 'That user action is not supported.', 'wp-telepilot' ) );
	}

	private function handle_plugins( $command, $identity ) {
		$link_result = $this->permission_service->require_linked_user( $identity );
		if ( true !== $link_result ) {
			return $link_result;
		}

		$can_plugins = $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' );
		if ( ! $can_plugins ) {
			return Telepilot_Telegram_Response_Builder::error(
				__( 'You do not have permission to perform that action.', 'wp-telepilot' ),
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
				$this->plugins_service->render_page_message( $result, __( 'Installed Plugins', 'wp-telepilot' ) ),
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
				$this->plugins_service->render_page_message( $result, __( 'Plugin Updates', 'wp-telepilot' ) ),
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

		if ( 'refresh' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$permission_result = $this->permission_service->require_capability( $identity, 'update_plugins' );
			if ( true !== $permission_result ) {
				return $permission_result;
			}

			$result = $this->plugins_service->refresh_updates();
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			$this->log_plugin_action( $identity, 'refresh', $result );

			return Telepilot_Telegram_Response_Builder::success(
				__( 'Plugin update information has been refreshed.', 'wp-telepilot' ),
				array(
					'command' => '/plugins',
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error_html(
					Telepilot_Telegram_Response_Builder::bold( __( 'Plugins Search', 'wp-telepilot' ) ) . "\n\n" .
					__( 'Usage:', 'wp-telepilot' ) . ' ' . Telepilot_Telegram_Response_Builder::code( '/plugins search keyword' )
				);
			}

			$result = $this->plugins_service->search_page( $term, $page );

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_page_message( $result, sprintf( __( 'Plugin Search: %s', 'wp-telepilot' ), $term ) ),
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
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/plugins details IDENTIFIER`', 'wp-telepilot' ) );
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
			return $this->invalid_subcommand_response(
				'/plugins',
				$subcommand,
				$this->plugins_service->render_help_message(),
				$this->safe_home_keyboard( $identity )
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

		$message = sprintf( __( 'Confirm %1$s for plugin [%2$s]', 'wp-telepilot' ), $subcommand, $identifier );
		if ( 'delete' === $subcommand ) {
			$message = Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Delete Plugin?', 'wp-telepilot' ) ),
					sprintf( __( 'Plugin: [%s]', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $identifier ) ),
					__( 'Deleting a plugin removes its files from WordPress. Make sure it is not needed before you continue.', 'wp-telepilot' ),
				)
			);
		}

		return Telepilot_Telegram_Response_Builder::success_html(
			$message,
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is invalid or expired.', 'wp-telepilot' ) );
		}

		$payload = $this->confirmation_service->consume_token( $token );
		if ( empty( $payload ) || empty( $payload['telegram_user_id'] ) || (string) $payload['telegram_user_id'] !== (string) $identity['telegram_user_id'] || empty( $payload['action'] ) || empty( $payload['plugin_file'] ) ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is invalid or expired.', 'wp-telepilot' ) );
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
				return Telepilot_Telegram_Response_Builder::error( __( 'That plugin action is not supported.', 'wp-telepilot' ) );
		}

		if ( is_wp_error( $result ) ) {
			return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		$this->log_plugin_action( $identity, $action, $result );

		$plugin = ! empty( $result['plugin'] ) ? $result['plugin'] : array();
		$label  = ! empty( $plugin['identifier'] ) ? $plugin['identifier'] : sanitize_key( basename( $plugin_ref, '.php' ) );

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::bold( __( 'Plugin Action Completed', 'wp-telepilot' ) ) .
			"\n\n" .
			sprintf(
				__( 'Plugin: [%1$s]', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $label )
			) .
			"\n" .
			sprintf(
				__( 'Result: %s', 'wp-telepilot' ),
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

		if ( 'help' === $subcommand ) {
			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_help_message( $resource ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_home_keyboard( $identity ),
				)
			);
		}

		if ( 'list' === $subcommand ) {
			$result = $this->taxonomies_service->list_terms( $taxonomy, $page );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%s List', 'wp-telepilot' ), ucfirst( $resource ) ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $resource, $result, $identity ) {
							return $this->taxonomies_service->build_terms_keyboard( $resource, $result, 'categories' === $resource && $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) );
						},
						'terms_list'
					),
				)
			);
		}

		if ( 'search' === $subcommand ) {
			$term = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s search keyword`', 'wp-telepilot' ), $resource ) );
			}

			$result = $this->taxonomies_service->list_terms( $taxonomy, $page, 5, $term );
			if ( is_wp_error( $result ) ) {
				return Telepilot_Telegram_Response_Builder::error( $result->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_terms_message( $result, sprintf( __( '%1$s Search: %2$s', 'wp-telepilot' ), ucfirst( $resource ), $term ) ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $resource, $result, $identity ) {
							return $this->taxonomies_service->build_terms_keyboard( $resource, $result, 'categories' === $resource && $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) );
						},
						'terms_search'
					),
				)
			);
		}

		$term_id = ! empty( $args[1] ) ? absint( $args[1] ) : 0;

		if ( 'details' === $subcommand && $term_id ) {
			$term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->taxonomies_service->render_term_details_message( $term, $resource ),
				array(
					'command'      => '/' . $resource,
					'reply_markup' => $this->safe_reply_markup(
						function() use ( $resource, $term, $identity ) {
							return $this->taxonomies_service->build_terms_keyboard(
								$resource,
								array(
									'items'       => array( $term ),
									'page'        => 1,
									'total_pages' => 1,
									'search'      => '',
								),
								'categories' === $resource && $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' )
							);
						},
						'term_details'
					),
				)
			);
		}

		if ( 'post' === $subcommand && 'categories' === $resource ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$edit_posts_result = $this->permission_service->require_capability( $identity, 'edit_posts' );
			if ( true !== $edit_posts_result ) {
				return $edit_posts_result;
			}

			if ( ! $term_id ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/categories post 12` or `/categories post 12 My draft title`', 'wp-telepilot' ) );
			}

			$term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$title = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $title ) ) {
				return $this->start_pending_post_creation( $identity, array( $term_id ) );
			}

			return $this->create_post_and_open_category_picker( $identity, $title, array( $term_id ) );
		}

		if ( 'create' === $subcommand ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$name = implode( ' ', array_slice( $args, 1 ) );
			if ( '' === trim( $name ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s create Name`', 'wp-telepilot' ), $resource ) );
			}

			$term = $this->taxonomies_service->create_term( $taxonomy, $name );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$this->log_term_action(
				$identity,
				'term_created',
				$taxonomy,
				$term->term_id,
				array(),
				array(
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => isset( $term->parent ) ? (int) $term->parent : 0,
				)
			);

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d `%3$s` has been created.', 'wp-telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'rename' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$name = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $name ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s rename 12 New Name`', 'wp-telepilot' ), $resource ) );
			}

			$before_term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			$term = $this->taxonomies_service->rename_term( $taxonomy, $term_id, $name );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$this->log_term_action(
				$identity,
				'term_updated',
				$taxonomy,
				$term->term_id,
				$this->build_term_state( $before_term ),
				$this->build_term_state( $term, 'rename' )
			);

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d has been renamed to `%3$s`.', 'wp-telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id, $term->name ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'slug' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$slug = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $slug ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s slug 12 new-slug`', 'wp-telepilot' ), $resource ) );
			}

			$before_term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			$term        = $this->taxonomies_service->update_slug( $taxonomy, $term_id, $slug );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$this->log_term_action(
				$identity,
				'term_updated',
				$taxonomy,
				$term->term_id,
				$this->build_term_state( $before_term ),
				$this->build_term_state( $term, 'slug' )
			);

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d slug has been updated.', 'wp-telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'description' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			$description = implode( ' ', array_slice( $args, 2 ) );
			if ( '' === trim( $description ) ) {
				return Telepilot_Telegram_Response_Builder::error( sprintf( __( 'Usage: `/%1$s description 12 New description`', 'wp-telepilot' ), $resource ) );
			}

			$before_term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			$term        = $this->taxonomies_service->update_description( $taxonomy, $term_id, $description );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$this->log_term_action(
				$identity,
				'term_updated',
				$taxonomy,
				$term->term_id,
				$this->build_term_state( $before_term ),
				$this->build_term_state( $term, 'description' )
			);

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( '%1$s #%2$d description has been updated.', 'wp-telepilot' ), ucfirst( rtrim( $resource, 's' ) ), $term->term_id ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'parent' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			if ( 'categories' !== $resource ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Parent assignment is only available for categories.', 'wp-telepilot' ) );
			}

			$raw_parent = isset( $args[2] ) ? (string) $args[2] : '';
			if ( '' === trim( $raw_parent ) ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Usage: `/categories parent 12 3` or `/categories parent 12 none`', 'wp-telepilot' ) );
			}

			$parent_id   = 'none' === strtolower( $raw_parent ) ? 0 : absint( $raw_parent );
			$before_term = $this->taxonomies_service->get_term_details( $taxonomy, $term_id );
			$term        = $this->taxonomies_service->update_parent( $term_id, $parent_id );
			if ( is_wp_error( $term ) ) {
				return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
			}

			$this->log_term_action(
				$identity,
				'term_updated',
				$taxonomy,
				$term->term_id,
				$this->build_term_state( $before_term ),
				$this->build_term_state( $term, 'parent' )
			);

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Category #%1$d parent has been updated.', 'wp-telepilot' ), $term->term_id ),
				array( 'command' => '/' . $resource )
			);
		}

		if ( 'delete' === $subcommand && $term_id ) {
			$private_chat_result = $this->require_private_action( $identity );
			if ( true !== $private_chat_result ) {
				return $private_chat_result;
			}

			return Telepilot_Telegram_Response_Builder::success(
				sprintf( __( 'Confirm delete for %1$s #%2$d', 'wp-telepilot' ), rtrim( $resource, 's' ), $term_id ),
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

		return $this->invalid_subcommand_response(
			'/' . $resource,
			$subcommand,
			$this->taxonomies_service->render_help_message( $resource ),
			$this->safe_home_keyboard( $identity )
		);
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
			return Telepilot_Telegram_Response_Builder::error( __( 'That term action is invalid or expired.', 'wp-telepilot' ) );
		}

		$term = $this->taxonomies_service->delete_term( $taxonomy, $term_id );
		if ( is_wp_error( $term ) ) {
			return Telepilot_Telegram_Response_Builder::error( $term->get_error_message() );
		}

		$this->log_term_action(
			$identity,
			'term_deleted',
			$taxonomy,
			$term_id,
			$this->build_term_state( $term ),
			array(
				'action' => 'delete',
			)
		);

		$resource = 'post_tag' === $taxonomy ? 'tags' : 'categories';

		return Telepilot_Telegram_Response_Builder::success(
			sprintf(
				'post_tag' === $taxonomy ? __( 'Tag #%1$d `%2$s` has been deleted.', 'wp-telepilot' ) : __( 'Category #%1$d `%2$s` has been deleted.', 'wp-telepilot' ),
				$term_id,
				$term->name
			),
			array( 'command' => '/' . $resource )
		);
	}

	private function log_term_action( $identity, $action_name, $taxonomy, $term_id, $before_state, $after_state ) {
		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => $action_name,
				'resource_type'    => $taxonomy,
				'resource_id'      => (string) $term_id,
				'before_state'     => $before_state,
				'after_state'      => $after_state,
			)
		);
	}

	private function build_term_state( $term, $action = '' ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return '' !== $action ? array( 'action' => $action ) : array();
		}

		$state = array(
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => isset( $term->parent ) ? (int) $term->parent : 0,
			'count'       => isset( $term->count ) ? (int) $term->count : 0,
		);

		if ( '' !== $action ) {
			$state['action'] = $action;
		}

		return $state;
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

	private function parse_id_list( $raw_ids ) {
		$raw_ids = trim( (string) $raw_ids );
		if ( '' === $raw_ids || 'none' === strtolower( $raw_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					'absint',
					array_map( 'trim', explode( ',', $raw_ids ) )
				)
			)
		);
	}

	private function parse_site_datetime( $raw_value ) {
		$raw_value = trim( (string) $raw_value );
		if ( '' === $raw_value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $raw_value, wp_timezone() );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private function invalid_subcommand_response( $command_name, $subcommand, $help_message, $reply_markup = array() ) {
		$command_string = trim( $command_name . ' ' . $subcommand );

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Command Not Recognized', 'wp-telepilot' ) ),
					sprintf(
						__( 'I could not match %s.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::code( $command_string )
					),
					$help_message,
				)
			),
			array(
				'command'      => $command_name,
				'reply_markup' => is_array( $reply_markup ) ? $reply_markup : array(),
			)
		);
	}

	private function unknown_command_response( $command_name, $identity ) {
		$command_label = '' !== (string) $command_name ? Telepilot_Telegram_Response_Builder::code( (string) $command_name ) : __( 'that command', 'wp-telepilot' );

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Command Not Recognized', 'wp-telepilot' ) ),
					sprintf( __( 'I could not match %s.', 'wp-telepilot' ), $command_label ),
					__( 'Use /menu or /help to see what this bot can do.', 'wp-telepilot' ),
				)
			),
			array(
				'command'      => (string) $command_name,
				'reply_markup' => $this->safe_home_keyboard( $identity ),
			)
		);
	}

	private function require_private_action( $identity ) {
		return $this->permission_service->require_private_chat( $identity );
	}

	private function get_flow_state_service() {
		return new Telepilot_Flow_State_Service();
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

	public function get_home_keyboard( $identity ) {
		return $this->safe_home_keyboard( $identity );
	}

	private function build_home_keyboard( $identity ) {
		if ( ! $this->is_chat_allowed_for_menu( $identity ) ) {
			return array();
		}

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			return Telepilot_Telegram_Response_Builder::keyboard( $this->build_onboarding_keyboard_rows() );
		}

		$rows   = array();
		$rows[] = array(
			array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Site Overview', 'wp-telepilot' ) ),
				'callback_data' => '/site',
			),
			array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
				'callback_data' => '/menu',
			),
		);

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			$content_row = array();
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$content_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', __( 'Posts', 'wp-telepilot' ) ),
					'callback_data' => '/posts list',
				);
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_pages' ) ) {
				$content_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'pages', __( 'Pages', 'wp-telepilot' ) ),
					'callback_data' => '/pages list',
				);
			}
			if ( ! empty( $content_row ) ) {
				$rows[] = $content_row;
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'edit_posts' ) ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'posts', __( 'New Post', 'wp-telepilot' ) ),
						'callback_data' => '/posts new',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'draft', __( 'Drafts', 'wp-telepilot' ) ),
						'callback_data' => '/posts drafts',
					),
				);
			}

			$editorial_row = array();
			if ( $this->permission_service->user_can( $identity['wp_user'], 'moderate_comments' ) ) {
				$editorial_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'comments', __( 'Comments', 'wp-telepilot' ) ),
					'callback_data' => '/comments pending',
				);
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'upload_files' ) ) {
				$editorial_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'media', __( 'Media', 'wp-telepilot' ) ),
					'callback_data' => '/media list',
				);
			}
			if ( ! empty( $editorial_row ) ) {
				$rows[] = $editorial_row;
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_categories' ) ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'categories', __( 'Categories', 'wp-telepilot' ) ),
						'callback_data' => '/categories list',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'tags', __( 'Tags', 'wp-telepilot' ) ),
						'callback_data' => '/tags list',
					),
				);
			}

			$admin_tools_row = array();
			if ( $this->permission_service->user_can( $identity['wp_user'], 'list_users' ) ) {
				$admin_tools_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'users', __( 'Users', 'wp-telepilot' ) ),
					'callback_data' => '/users list',
				);
			}
			if ( $this->permission_service->user_can( $identity['wp_user'], 'activate_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'update_plugins' ) || $this->permission_service->user_can( $identity['wp_user'], 'delete_plugins' ) ) {
				$admin_tools_row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'plugins', __( 'Plugins', 'wp-telepilot' ) ),
					'callback_data' => '/plugins list',
				);
			}
			if ( ! empty( $admin_tools_row ) ) {
				$rows[] = $admin_tools_row;
			}

			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_options' ) ) {
				$rows[] = array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'notifications', __( 'Notifications', 'wp-telepilot' ) ),
						'callback_data' => '/notifications list',
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::label( 'settings', __( 'Settings', 'wp-telepilot' ) ),
						'callback_data' => '/settings',
					),
				);
			}

			$admin_row = array(
				array(
					'text' => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Open wp-admin', 'wp-telepilot' ) ),
					'url'  => admin_url(),
				),
			);

			if ( $this->permission_service->user_can( $identity['wp_user'], 'manage_options' ) ) {
				$admin_row[] = array(
					'text' => Telepilot_Telegram_Response_Builder::label( 'settings', __( 'Settings', 'wp-telepilot' ) ),
					'url'  => admin_url( 'admin.php?page=telepilot' ),
				);
			}

			$rows[] = $admin_row;
		}

		return Telepilot_Telegram_Response_Builder::keyboard( $rows );
	}

	private function build_onboarding_keyboard_rows() {
		return array(
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Start', 'wp-telepilot' ) ),
					'callback_data' => '/start',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'link', __( 'Chat ID', 'wp-telepilot' ) ),
					'callback_data' => '/chatid',
				),
			),
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Help', 'wp-telepilot' ) ),
					'callback_data' => '/help',
				),
			),
		);
	}

	private function is_chat_allowed_for_menu( $identity ) {
		$chat_id = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '';

		if ( '' === $chat_id ) {
			return true;
		}

		$settings = get_option( 'telepilot_settings', array() );
		$allowed  = isset( $settings['allowed_chat_ids'] ) ? (string) $settings['allowed_chat_ids'] : '';
		$chat_ids = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $allowed ) ) ) );

		if ( empty( $chat_ids ) ) {
			return true;
		}

		return in_array( $chat_id, $chat_ids, true );
	}
}
