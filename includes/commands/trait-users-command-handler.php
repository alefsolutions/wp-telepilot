<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Telepilot_Users_Command_Handler {
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
			if ( is_wp_error( $users ) ) { return Telepilot_Telegram_Response_Builder::error( $users->get_error_message(), array( 'code' => $users->get_error_code() ) ); }
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

			if ( is_wp_error( $users ) ) { return Telepilot_Telegram_Response_Builder::error( $users->get_error_message(), array( 'code' => $users->get_error_code() ) ); }

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
}
