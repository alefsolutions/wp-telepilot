<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Notification_Service {
	private $client;

	public function __construct() {
		$this->client = new TelePress_Telegram_Client();
	}

	public function handle_new_comment( $comment_id, $comment_approved ) {
		if ( 'spam' === $comment_approved ) {
			return;
		}

		if ( ! $this->is_notification_enabled( 'new_comment' ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: author, 2: post title, 3: excerpt. */
			__( "New Comment\nFrom: %1$s\nPost: %2$s\nPreview: %3$s", 'telepress' ),
			$comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'telepress' ),
			get_the_title( $comment->comment_post_ID ),
			wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 90, '...' )
		);

		$this->send_notification( 'new_comment_notification', 'moderate_comments', $message, array( 'comment_id' => $comment_id ) );
	}

	public function handle_post_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
			return;
		}

		if ( ! $this->is_notification_enabled( 'new_post_published' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: post title, 2: site name, 3: permalink. */
			__( "New Post Published\nTitle: %1$s\nSite: %2$s\nURL: %3$s", 'telepress' ),
			$post->post_title,
			get_bloginfo( 'name' ),
			get_permalink( $post )
		);

		$this->send_notification( 'new_post_notification', 'edit_posts', $message, array( 'post_id' => $post->ID ) );
	}

	public function handle_failed_login( $username ) {
		if ( ! $this->is_notification_enabled( 'failed_login' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: username, 2: site name. */
			__( "Failed Login\nUsername: %1$s\nSite: %2$s", 'telepress' ),
			$username,
			get_bloginfo( 'name' )
		);

		$this->send_notification( 'failed_login_notification', 'manage_options', $message, array( 'username' => $username ) );
	}

	public function maybe_send_update_notifications() {
		$this->maybe_send_single_update_notification( 'plugin_updates', 'update_plugins', 'manage_options', __( 'Plugin updates are available for this site.', 'telepress' ) );
		$this->maybe_send_single_update_notification( 'theme_updates', 'update_themes', 'manage_options', __( 'Theme updates are available for this site.', 'telepress' ) );
		$this->maybe_send_single_update_notification( 'core_updates', 'update_core', 'manage_options', __( 'WordPress core updates are available for this site.', 'telepress' ) );
	}

	private function maybe_send_single_update_notification( $setting_key, $transient_key, $capability, $message ) {
		if ( ! $this->is_notification_enabled( $setting_key ) ) {
			return;
		}

		$transient = get_site_transient( $transient_key );
		$has_update = false;

		if ( 'update_core' === $transient_key ) {
			if ( ! empty( $transient->updates ) && is_array( $transient->updates ) ) {
				foreach ( $transient->updates as $update ) {
					if ( ! empty( $update->response ) && 'latest' !== $update->response ) {
						$has_update = true;
						break;
					}
				}
			}
		} else {
			$has_update = ! empty( $transient->response ) && is_array( $transient->response );
		}

		if ( ! $has_update ) {
			return;
		}

		$dedupe_key = 'telepress_notified_' . $setting_key . '_' . gmdate( 'Ymd' );

		if ( get_transient( $dedupe_key ) ) {
			return;
		}

		set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
		$this->send_notification( $setting_key . '_notification', $capability, $message, array( 'source' => $setting_key ) );
	}

	private function send_notification( $action_name, $capability, $message, $context = array() ) {
		$recipients = $this->get_recipient_chat_ids( $capability );

		foreach ( $recipients as $chat_id ) {
			$response = $this->client->send_message( $chat_id, $message );

			if ( is_wp_error( $response ) ) {
				TelePress_Audit_Log_Repository::log(
					array(
						'chat_id'         => $chat_id,
						'action_name'     => 'telegram_notification_failed',
						'resource_type'   => 'telegram_notification',
						'resource_id'     => $action_name,
						'was_successful'  => 0,
						'failure_reason'  => $response->get_error_message(),
						'context'         => $context,
					)
				);
				continue;
			}

			TelePress_Audit_Log_Repository::log(
				array(
					'chat_id'         => $chat_id,
					'action_name'     => 'telegram_notification_sent',
					'resource_type'   => 'telegram_notification',
					'resource_id'     => $action_name,
					'context'         => $context,
				)
			);
		}
	}

	private function get_recipient_chat_ids( $capability ) {
		$recipients = array();
		$users      = get_users(
			array(
				'meta_key'     => TelePress_User_Linking_Service::META_TELEGRAM_CHAT,
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			if ( ! user_can( $user, $capability ) ) {
				continue;
			}

			$chat_id = get_user_meta( $user->ID, TelePress_User_Linking_Service::META_TELEGRAM_CHAT, true );
			if ( $chat_id ) {
				$recipients[] = (string) $chat_id;
			}
		}

		$settings = get_option( 'telepress_settings', array() );
		$allowed  = isset( $settings['allowed_chat_ids'] ) ? (string) $settings['allowed_chat_ids'] : '';
		$extra    = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $allowed ) ) ) );

		return array_values( array_unique( array_merge( $recipients, $extra ) ) );
	}

	private function is_notification_enabled( $key ) {
		$settings = get_option( 'telepress_settings', array() );
		$enabled  = isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] )
			? $settings['default_notifications']
			: array();

		return in_array( $key, $enabled, true );
	}
}
