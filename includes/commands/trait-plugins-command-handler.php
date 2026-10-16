<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Telepilot_Plugins_Command_Handler {
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
			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }

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
			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }

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

			if ( is_wp_error( $result ) ) { return Telepilot_Telegram_Response_Builder::error( $result->get_error_message(), array( 'code' => $result->get_error_code() ) ); }

			return Telepilot_Telegram_Response_Builder::success_html(
				$this->plugins_service->render_page_message( $result, sprintf( /* translators: %s: search keyword. */ __( 'Plugin Search: %s', 'wp-telepilot' ), $term ) ),
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

		$message = sprintf( /* translators: 1: requested action, 2: plugin identifier. */ __( 'Confirm %1$s for plugin [%2$s]', 'wp-telepilot' ), $subcommand, $identifier );
		if ( 'delete' === $subcommand ) {
			$message = Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Delete Plugin?', 'wp-telepilot' ) ),
					sprintf( /* translators: %s: plugin identifier. */ __( 'Plugin: [%s]', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $identifier ) ),
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
}
