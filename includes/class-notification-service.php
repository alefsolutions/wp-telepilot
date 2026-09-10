<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Notification_Service {
	private $client;

	public function __construct( $client = null ) {
		$this->client = $client instanceof Telepilot_Telegram_Client ? $client : new Telepilot_Telegram_Client();
	}

	public static function option_labels() {
		return array(
			'new_post_published'       => __( 'Content: new post published', 'wp-telepilot' ),
			'new_page_published'       => __( 'Content: new page published', 'wp-telepilot' ),
			'new_comment'              => __( 'Comments: new comment received', 'wp-telepilot' ),
			'comment_status_changed'   => __( 'Comments: moderation status changed', 'wp-telepilot' ),
			'user_registered'          => __( 'Users: new user registered', 'wp-telepilot' ),
			'user_profile_updated'     => __( 'Users: profile updated', 'wp-telepilot' ),
			'user_deleted'             => __( 'Users: user deleted', 'wp-telepilot' ),
			'user_role_changed'        => __( 'Users: role changed', 'wp-telepilot' ),
			'failed_login'             => __( 'Security: failed login', 'wp-telepilot' ),
			'password_reset_requested' => __( 'Security: password reset requested', 'wp-telepilot' ),
			'password_reset_completed' => __( 'Security: password reset completed', 'wp-telepilot' ),
			'plugin_activated'         => __( 'System: plugin activated', 'wp-telepilot' ),
			'plugin_deactivated'       => __( 'System: plugin deactivated', 'wp-telepilot' ),
			'theme_switched'           => __( 'System: theme switched', 'wp-telepilot' ),
			'plugin_updates'           => __( 'Updates: plugin updates available', 'wp-telepilot' ),
			'theme_updates'            => __( 'Updates: theme updates available', 'wp-telepilot' ),
			'core_updates'             => __( 'Updates: core updates available', 'wp-telepilot' ),
			'update_completed'         => __( 'Updates: upgrade completed', 'wp-telepilot' ),
		);
	}

	public function handle_new_comment( $comment_id, $comment_approved, $commentdata = array() ) {
		if ( 'spam' === $comment_approved ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$post = get_post( $comment->comment_post_ID );

		$this->send_standard_notification(
			'new_comment',
			'new_comment_notification',
			'moderate_comments',
			__( 'New Comment', 'wp-telepilot' ),
			array(
				__( 'Author', 'wp-telepilot' )  => $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ),
				__( 'Post', 'wp-telepilot' )    => $post ? get_the_title( $post ) : __( 'Unknown Post', 'wp-telepilot' ),
				__( 'Status', 'wp-telepilot' )  => $this->humanize_comment_status( $comment_approved ),
				__( 'Preview', 'wp-telepilot' ) => wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 120, '...' ),
			),
			$this->build_comment_links( $comment, $post ),
			array(
				'comment_id'   => (int) $comment_id,
				'comment_post' => isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : (int) $comment->comment_post_ID,
			),
			'comment',
			(string) $comment_id
		);
	}

	public function handle_comment_status_change( $comment_id, $comment_status ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$post = get_post( $comment->comment_post_ID );

		$this->send_standard_notification(
			'comment_status_changed',
			'comment_status_changed_notification',
			'moderate_comments',
			__( 'Comment Status Changed', 'wp-telepilot' ),
			array(
				__( 'Author', 'wp-telepilot' ) => $comment->comment_author ? $comment->comment_author : __( 'Anonymous', 'wp-telepilot' ),
				__( 'Post', 'wp-telepilot' )   => $post ? get_the_title( $post ) : __( 'Unknown Post', 'wp-telepilot' ),
				__( 'Status', 'wp-telepilot' ) => $this->humanize_comment_status( $comment_status ),
			),
			$this->build_comment_links( $comment, $post ),
			array(
				'comment_id' => (int) $comment_id,
				'status'     => (string) $comment_status,
			),
			'comment',
			(string) $comment_id
		);
	}

	public function handle_post_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$setting_key = 'page' === $post->post_type ? 'new_page_published' : 'new_post_published';
		$title       = 'page' === $post->post_type ? __( 'Page Published', 'wp-telepilot' ) : __( 'Post Published', 'wp-telepilot' );
		$capability  = 'page' === $post->post_type ? 'edit_pages' : 'edit_posts';

		$this->send_standard_notification(
			$setting_key,
			'post_transition_notification',
			$capability,
			$title,
			array(
				__( 'Title', 'wp-telepilot' )  => get_the_title( $post ),
				__( 'Author', 'wp-telepilot' ) => $this->get_post_author_label( $post ),
				__( 'Type', 'wp-telepilot' )   => ucfirst( (string) $post->post_type ),
				__( 'Status', 'wp-telepilot' ) => ucfirst( (string) $new_status ),
			),
			$this->build_post_links( $post ),
			array(
				'post_id'    => (int) $post->ID,
				'post_type'  => (string) $post->post_type,
				'old_status' => (string) $old_status,
				'new_status' => (string) $new_status,
			),
			(string) $post->post_type,
			(string) $post->ID
		);
	}

	public function handle_user_registered( $user_id, $userdata = array() ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->send_standard_notification(
			'user_registered',
			'user_registered_notification',
			'manage_options',
			__( 'User Registered', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $user->user_login,
				__( 'Email', 'wp-telepilot' )    => $user->user_email,
				__( 'Role', 'wp-telepilot' )     => $this->implode_roles( $user->roles ),
			),
			$this->build_user_links( $user->ID ),
			array(
				'user_id'  => (int) $user_id,
				'userdata' => is_array( $userdata ) ? $userdata : array(),
			),
			'user',
			(string) $user_id
		);
	}

	public function handle_user_profile_updated( $user_id, $old_user_data, $userdata = array() ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! $old_user_data instanceof WP_User ) {
			return;
		}

		$changes = $this->summarize_user_changes( $old_user_data, $user, $userdata );
		if ( '' === $changes ) {
			return;
		}

		$this->send_standard_notification(
			'user_profile_updated',
			'user_profile_updated_notification',
			'manage_options',
			__( 'User Profile Updated', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $user->user_login,
				__( 'Email', 'wp-telepilot' )    => $user->user_email,
				__( 'Changed', 'wp-telepilot' )  => $changes,
			),
			$this->build_user_links( $user->ID ),
			array(
				'user_id' => (int) $user_id,
				'changes' => $changes,
			),
			'user',
			(string) $user_id
		);
	}

	public function handle_user_deleted( $user_id, $reassign = null, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user_id );
		}

		$username = $user instanceof WP_User ? $user->user_login : sprintf( __( 'User #%d', 'wp-telepilot' ), (int) $user_id );
		$email    = $user instanceof WP_User ? $user->user_email : '';

		$this->send_standard_notification(
			'user_deleted',
			'user_deleted_notification',
			'manage_options',
			__( 'User Deleted', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' )     => $username,
				__( 'Email', 'wp-telepilot' )        => $email ? $email : __( 'Unknown', 'wp-telepilot' ),
				__( 'Reassigned To', 'wp-telepilot' ) => $reassign ? sprintf( __( 'User #%d', 'wp-telepilot' ), (int) $reassign ) : __( 'No reassignment', 'wp-telepilot' ),
			),
			array(),
			array(
				'user_id'  => (int) $user_id,
				'reassign' => null === $reassign ? null : (int) $reassign,
			),
			'user',
			(string) $user_id
		);
	}

	public function handle_user_role_changed( $user_id, $role, $old_roles = array() ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->send_standard_notification(
			'user_role_changed',
			'user_role_changed_notification',
			'manage_options',
			__( 'User Role Changed', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $user->user_login,
				__( 'Old Roles', 'wp-telepilot' ) => $this->implode_roles( (array) $old_roles ),
				__( 'New Role', 'wp-telepilot' )  => $this->normalize_role( $role ),
			),
			$this->build_user_links( $user->ID ),
			array(
				'user_id'   => (int) $user_id,
				'old_roles' => (array) $old_roles,
				'new_role'  => (string) $role,
			),
			'user',
			(string) $user_id
		);
	}

	public function handle_failed_login( $username ) {
		$this->send_standard_notification(
			'failed_login',
			'failed_login_notification',
			'manage_options',
			__( 'Failed Login', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $username,
				__( 'Site', 'wp-telepilot' )     => get_bloginfo( 'name' ),
			),
			array(),
			array(
				'username' => (string) $username,
			),
			'security_event',
			sanitize_key( (string) $username )
		);
	}

	public function handle_password_reset_requested( $user_login, $key ) {
		$user = get_user_by( 'login', $user_login );

		$this->send_standard_notification(
			'password_reset_requested',
			'password_reset_requested_notification',
			'manage_options',
			__( 'Password Reset Requested', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $user_login,
				__( 'Email', 'wp-telepilot' )    => $user instanceof WP_User ? $user->user_email : __( 'Unknown', 'wp-telepilot' ),
				__( 'Note', 'wp-telepilot' )     => __( 'A reset key was generated, but WP Telepilot does not include secrets in Telegram alerts.', 'wp-telepilot' ),
			),
			$user instanceof WP_User ? $this->build_user_links( $user->ID ) : array(),
			array(
				'user_login' => (string) $user_login,
				'key_sent'   => ! empty( $key ),
			),
			'security_event',
			sanitize_key( (string) $user_login )
		);
	}

	public function handle_password_reset_completed( $user, $new_pass ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->send_standard_notification(
			'password_reset_completed',
			'password_reset_completed_notification',
			'manage_options',
			__( 'Password Reset Completed', 'wp-telepilot' ),
			array(
				__( 'Username', 'wp-telepilot' ) => $user->user_login,
				__( 'Email', 'wp-telepilot' )    => $user->user_email,
				__( 'Status', 'wp-telepilot' )   => __( 'Password updated successfully', 'wp-telepilot' ),
			),
			$this->build_user_links( $user->ID ),
			array(
				'user_id' => (int) $user->ID,
			),
			'security_event',
			(string) $user->ID
		);
	}

	public function handle_plugin_activated( $plugin, $network_wide = false ) {
		$this->send_standard_notification(
			'plugin_activated',
			'plugin_activated_notification',
			'activate_plugins',
			__( 'Plugin Activated', 'wp-telepilot' ),
			array(
				__( 'Plugin', 'wp-telepilot' )  => $this->plugin_label_from_path( $plugin ),
				__( 'Scope', 'wp-telepilot' )   => $network_wide ? __( 'Network-wide', 'wp-telepilot' ) : __( 'Single site', 'wp-telepilot' ),
			),
			array(
				array(
					'label' => __( 'Plugins', 'wp-telepilot' ),
					'url'   => admin_url( 'plugins.php' ),
				),
			),
			array(
				'plugin'       => (string) $plugin,
				'network_wide' => (bool) $network_wide,
			),
			'plugin',
			(string) $plugin
		);
	}

	public function handle_plugin_deactivated( $plugin, $network_deactivating = false ) {
		$this->send_standard_notification(
			'plugin_deactivated',
			'plugin_deactivated_notification',
			'activate_plugins',
			__( 'Plugin Deactivated', 'wp-telepilot' ),
			array(
				__( 'Plugin', 'wp-telepilot' ) => $this->plugin_label_from_path( $plugin ),
				__( 'Scope', 'wp-telepilot' )  => $network_deactivating ? __( 'Network-wide', 'wp-telepilot' ) : __( 'Single site', 'wp-telepilot' ),
			),
			array(
				array(
					'label' => __( 'Plugins', 'wp-telepilot' ),
					'url'   => admin_url( 'plugins.php' ),
				),
			),
			array(
				'plugin'               => (string) $plugin,
				'network_deactivating' => (bool) $network_deactivating,
			),
			'plugin',
			(string) $plugin
		);
	}

	public function handle_theme_switched( $new_name, $new_theme, $old_theme ) {
		$old_name_label = $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : __( 'Unknown', 'wp-telepilot' );

		$this->send_standard_notification(
			'theme_switched',
			'theme_switched_notification',
			'switch_themes',
			__( 'Theme Switched', 'wp-telepilot' ),
			array(
				__( 'Old Theme', 'wp-telepilot' ) => $old_name_label,
				__( 'New Theme', 'wp-telepilot' ) => $new_name,
			),
			array(
				array(
					'label' => __( 'Themes', 'wp-telepilot' ),
					'url'   => admin_url( 'themes.php' ),
				),
			),
			array(
				'old_theme' => $old_name_label,
				'new_theme' => (string) $new_name,
			),
			'theme',
			sanitize_title( (string) $new_name )
		);
	}

	public function handle_upgrader_process_complete( $upgrader, $options ) {
		$options = is_array( $options ) ? $options : array();

		if ( empty( $options['type'] ) || empty( $options['action'] ) ) {
			return;
		}

		$type   = (string) $options['type'];
		$action = (string) $options['action'];

		if ( 'update' !== $action || ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return;
		}

		$items = $this->extract_upgrader_items( $type, $options );
		$key   = 'telepilot_upgrade_' . md5( wp_json_encode( array( $type, $action, $items ) ) );

		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );

		$capability = 'plugin' === $type ? 'update_plugins' : ( 'theme' === $type ? 'update_themes' : 'update_core' );

		$this->send_standard_notification(
			'update_completed',
			'update_completed_notification',
			$capability,
			__( 'Upgrade Completed', 'wp-telepilot' ),
			array(
				__( 'Type', 'wp-telepilot' )  => ucfirst( $type ),
				__( 'Action', 'wp-telepilot' ) => ucfirst( $action ),
				__( 'Items', 'wp-telepilot' )  => ! empty( $items ) ? implode( ', ', $items ) : __( 'Not provided by WordPress', 'wp-telepilot' ),
			),
			array(
				array(
					'label' => __( 'Updates', 'wp-telepilot' ),
					'url'   => admin_url( 'update-core.php' ),
				),
			),
			array(
				'type'   => $type,
				'action' => $action,
				'items'  => $items,
			),
			'update',
			$type . ':' . $action
		);
	}

	public function handle_automatic_updates_complete( $update_results ) {
		$summary = $this->summarize_automatic_updates( $update_results );
		if ( empty( $summary['count'] ) ) {
			return;
		}

		$key = 'telepilot_auto_updates_' . md5( wp_json_encode( $summary ) );
		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );

		$this->send_standard_notification(
			'update_completed',
			'automatic_updates_completed_notification',
			'update_core',
			__( 'Automatic Updates Completed', 'wp-telepilot' ),
			array(
				__( 'Successful Updates', 'wp-telepilot' ) => (string) $summary['count'],
				__( 'Types', 'wp-telepilot' )              => implode( ', ', $summary['types'] ),
				__( 'Items', 'wp-telepilot' )              => implode( ', ', $summary['items'] ),
			),
			array(
				array(
					'label' => __( 'Updates', 'wp-telepilot' ),
					'url'   => admin_url( 'update-core.php' ),
				),
			),
			array(
				'summary' => $summary,
			),
			'update',
			'automatic'
		);
	}

	public function maybe_send_update_notifications() {
		$this->maybe_send_single_update_notification(
			'plugin_updates',
			'update_plugins',
			'update_plugins',
			__( 'Plugin Updates Available', 'wp-telepilot' ),
			__( 'Plugins awaiting update', 'wp-telepilot' ),
			admin_url( 'update-core.php' )
		);
		$this->maybe_send_single_update_notification(
			'theme_updates',
			'update_themes',
			'update_themes',
			__( 'Theme Updates Available', 'wp-telepilot' ),
			__( 'Themes awaiting update', 'wp-telepilot' ),
			admin_url( 'update-core.php' )
		);
		$this->maybe_send_single_update_notification(
			'core_updates',
			'update_core',
			'update_core',
			__( 'WordPress Core Updates Available', 'wp-telepilot' ),
			__( 'Core updates available', 'wp-telepilot' ),
			admin_url( 'update-core.php' )
		);
	}

	private function maybe_send_single_update_notification( $setting_key, $transient_key, $capability, $title, $count_label, $url ) {
		if ( ! $this->is_notification_enabled( $setting_key ) ) {
			return;
		}

		$transient = get_site_transient( $transient_key );
		$count     = 0;

		if ( 'update_core' === $transient_key ) {
			if ( ! empty( $transient->updates ) && is_array( $transient->updates ) ) {
				foreach ( $transient->updates as $update ) {
					if ( ! empty( $update->response ) && 'latest' !== $update->response ) {
						++$count;
					}
				}
			}
		} elseif ( ! empty( $transient->response ) && is_array( $transient->response ) ) {
			$count = count( $transient->response );
		}

		if ( $count < 1 ) {
			return;
		}

		$dedupe_key = 'telepilot_notified_' . $setting_key . '_' . gmdate( 'Ymd' );
		if ( get_transient( $dedupe_key ) ) {
			return;
		}

		set_transient( $dedupe_key, 1, DAY_IN_SECONDS );

		$this->send_standard_notification(
			$setting_key,
			$setting_key . '_notification',
			$capability,
			$title,
			array(
				__( 'Site', 'wp-telepilot' ) => get_bloginfo( 'name' ),
				$count_label              => (string) $count,
			),
			array(
				array(
					'label' => __( 'Open updates screen', 'wp-telepilot' ),
					'url'   => $url,
				),
			),
			array(
				'source' => $setting_key,
				'count'  => $count,
			),
			'update',
			$setting_key
		);
	}

	private function send_standard_notification( $setting_key, $action_name, $capability, $title, $facts = array(), $links = array(), $context = array(), $resource_type = 'notification', $resource_id = '' ) {
		try {
			if ( ! $this->is_notification_enabled( $setting_key ) ) {
				return;
			}

			$message    = $this->build_standard_message( $title, $facts, $links );
			$recipients = $this->get_recipient_chat_ids( $capability );
			$target = in_array( $resource_type, array( 'post', 'page', 'comment' ), true ) ? array( 'type' => $resource_type, 'id' => (int) $resource_id ) : array();

			foreach ( $recipients as $chat_id ) {
				$response = ( new Telepilot_Telegram_Service() )->queue_notification(
					$chat_id,
					$message,
					$capability,
					$target
				);

				if ( is_wp_error( $response ) ) {
					Telepilot_Audit_Log_Repository::log(
						array(
							'chat_id'        => $chat_id,
							'action_name'    => 'telegram_notification_failed',
							'resource_type'  => $resource_type,
							'resource_id'    => $resource_id ? $resource_id : $action_name,
							'was_successful' => 0,
							'failure_reason' => $response->get_error_message(),
							'context'        => wp_parse_args(
								$context,
								array(
									'notification' => $setting_key,
									'action_name'  => $action_name,
								)
							),
						)
					);
					continue;
				}

				Telepilot_Audit_Log_Repository::log(
					array(
						'chat_id'       => $chat_id,
						'action_name'   => 'telegram_notification_queued',
						'resource_type' => $resource_type,
						'resource_id'   => $resource_id ? $resource_id : $action_name,
						'context'       => wp_parse_args(
							$context,
							array(
								'notification' => $setting_key,
								'action_name'  => $action_name,
							)
						),
						'after_state'   => array( 'job_id' => $response ),
					)
				);
			}
		} catch ( Throwable $throwable ) {
			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'    => 'telegram_notification_exception',
					'resource_type'  => $resource_type,
					'resource_id'    => $resource_id ? $resource_id : $action_name,
					'was_successful' => 0,
					'failure_reason' => $throwable->getMessage(),
					'context'        => wp_parse_args(
						$context,
						array(
							'notification' => $setting_key,
							'action_name'  => $action_name,
							'file'         => $throwable->getFile(),
							'line'         => $throwable->getLine(),
						)
					),
				)
			);
		}
	}

	private function build_standard_message( $title, $facts, $links ) {
		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( $title );
		$lines[] = sprintf(
			/* translators: %s: site name. */
			__( 'Site: %s', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::escape( get_bloginfo( 'name' ) )
		);
		$lines[] = sprintf(
			/* translators: %s: current local time. */
			__( 'Time: %s', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::escape( wp_date( 'Y-m-d H:i:s T' ) )
		);
		$lines[] = '';

		foreach ( $facts as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$lines[] = sprintf(
				'%1$s: %2$s',
				Telepilot_Telegram_Response_Builder::escape( $label ),
				Telepilot_Telegram_Response_Builder::escape( $value )
			);
		}

		$link_parts = array();

		foreach ( $links as $link ) {
			if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
				continue;
			}

			$link_parts[] = Telepilot_Telegram_Response_Builder::link( $link['label'], $link['url'] );
		}

		if ( ! empty( $link_parts ) ) {
			$lines[] = '';
			$lines[] = __( 'Open:', 'wp-telepilot' ) . ' ' . implode( ' | ', $link_parts );
		}

		return implode( "\n", $lines );
	}

	private function build_post_links( $post ) {
		$links = array();
		$edit  = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );

		if ( 'publish' === $post->post_status ) {
			$permalink = get_permalink( $post );
			if ( $permalink ) {
				$links[] = array(
					'label' => __( 'View', 'wp-telepilot' ),
					'url'   => $permalink,
				);
			}
		}

		$links[] = array(
			'label' => __( 'Edit', 'wp-telepilot' ),
			'url'   => $edit,
		);

		return $links;
	}

	private function build_comment_links( $comment, $post ) {
		$links = array(
			array(
				'label' => __( 'Moderate', 'wp-telepilot' ),
				'url'   => admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID ),
			),
		);

		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			$permalink = get_comment_link( $comment );
			if ( $permalink ) {
				$links[] = array(
					'label' => __( 'View Comment', 'wp-telepilot' ),
					'url'   => $permalink,
				);
			}
		}

		return $links;
	}

	private function build_user_links( $user_id ) {
		return array(
			array(
				'label' => __( 'Edit User', 'wp-telepilot' ),
				'url'   => admin_url( 'user-edit.php?user_id=' . (int) $user_id ),
			),
			array(
				'label' => __( 'Users', 'wp-telepilot' ),
				'url'   => admin_url( 'users.php' ),
			),
		);
	}

	private function get_post_author_label( $post ) {
		$author = get_userdata( (int) $post->post_author );

		if ( $author instanceof WP_User ) {
			return $author->display_name ? $author->display_name : $author->user_login;
		}

		return __( 'Unknown', 'wp-telepilot' );
	}

	private function summarize_user_changes( $old_user_data, $user, $userdata ) {
		$changes = array();

		if ( $old_user_data->user_email !== $user->user_email ) {
			$changes[] = __( 'email', 'wp-telepilot' );
		}

		if ( $old_user_data->display_name !== $user->display_name ) {
			$changes[] = __( 'display name', 'wp-telepilot' );
		}

		if ( $old_user_data->user_url !== $user->user_url ) {
			$changes[] = __( 'website', 'wp-telepilot' );
		}

		if ( is_array( $userdata ) ) {
			if ( array_key_exists( 'first_name', $userdata ) ) {
				$changes[] = __( 'first name', 'wp-telepilot' );
			}

			if ( array_key_exists( 'last_name', $userdata ) ) {
				$changes[] = __( 'last name', 'wp-telepilot' );
			}

			if ( array_key_exists( 'description', $userdata ) ) {
				$changes[] = __( 'bio', 'wp-telepilot' );
			}
		}

		$changes = array_values( array_unique( $changes ) );

		return implode( ', ', $changes );
	}

	private function summarize_automatic_updates( $update_results ) {
		$summary = array(
			'count' => 0,
			'types' => array(),
			'items' => array(),
		);

		if ( ! is_array( $update_results ) ) {
			return $summary;
		}

		foreach ( $update_results as $type => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( is_wp_error( $item ) ) {
					continue;
				}

				++$summary['count'];
				$summary['types'][] = ucfirst( (string) $type );

				if ( is_object( $item ) ) {
					if ( ! empty( $item->name ) ) {
						$summary['items'][] = (string) $item->name;
					} elseif ( ! empty( $item->slug ) ) {
						$summary['items'][] = (string) $item->slug;
					}
				}
			}
		}

		$summary['types'] = array_values( array_unique( array_filter( $summary['types'] ) ) );
		$summary['items'] = array_values( array_unique( array_filter( $summary['items'] ) ) );

		if ( empty( $summary['items'] ) ) {
			$summary['items'][] = __( 'Updated items reported by WordPress', 'wp-telepilot' );
		}

		return $summary;
	}

	private function extract_upgrader_items( $type, $options ) {
		if ( 'plugin' === $type && ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			return array_map( array( $this, 'plugin_label_from_path' ), $options['plugins'] );
		}

		if ( 'plugin' === $type && ! empty( $options['plugin'] ) ) {
			return array( $this->plugin_label_from_path( $options['plugin'] ) );
		}

		if ( 'theme' === $type && ! empty( $options['themes'] ) && is_array( $options['themes'] ) ) {
			return array_map(
				static function( $theme_slug ) {
					$theme = wp_get_theme( (string) $theme_slug );
					return $theme instanceof WP_Theme && $theme->exists() ? $theme->get( 'Name' ) : (string) $theme_slug;
				},
				$options['themes']
			);
		}

		if ( 'theme' === $type && ! empty( $options['theme'] ) ) {
			$theme = wp_get_theme( (string) $options['theme'] );
			return array( $theme instanceof WP_Theme && $theme->exists() ? $theme->get( 'Name' ) : (string) $options['theme'] );
		}

		if ( 'core' === $type ) {
			return array( __( 'WordPress core', 'wp-telepilot' ) );
		}

		return array();
	}

	private function plugin_label_from_path( $plugin ) {
		$plugin = (string) $plugin;
		$base   = basename( $plugin, '.php' );

		if ( false !== strpos( $plugin, '/' ) ) {
			$segments = explode( '/', $plugin );
			$base     = $segments[0];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $base ) );
	}

	private function humanize_comment_status( $status ) {
		$status = (string) $status;

		if ( '1' === $status || 'approve' === $status ) {
			return __( 'Approved', 'wp-telepilot' );
		}

		if ( '0' === $status || 'hold' === $status ) {
			return __( 'Pending', 'wp-telepilot' );
		}

		if ( 'spam' === $status ) {
			return __( 'Spam', 'wp-telepilot' );
		}

		if ( 'trash' === $status ) {
			return __( 'Trashed', 'wp-telepilot' );
		}

		return ucfirst( $status );
	}

	private function normalize_role( $role ) {
		if ( '' === (string) $role ) {
			return __( 'None', 'wp-telepilot' );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $role ) );
	}

	private function implode_roles( $roles ) {
		$roles = array_map( array( $this, 'normalize_role' ), array_filter( (array) $roles ) );

		return ! empty( $roles ) ? implode( ', ', $roles ) : __( 'No role', 'wp-telepilot' );
	}

	private function get_recipient_chat_ids( $capability ) {
		$recipients = array();
		$users      = get_users(
			array(
				'meta_key'     => Telepilot_User_Linking_Service::META_TELEGRAM_CHAT,
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			$chat_id = get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );
			$identity = array( 'wp_user' => $user, 'chat_id' => (string) $chat_id, 'telegram_user_id' => (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true ), 'chat_type' => 'private' );
			if ( true === Telepilot_Actor_Access::check( $identity, $capability ) ) {
				$recipients[] = (string) $chat_id;
			}
		}

		return array_values( array_unique( $recipients ) );
	}

	private function is_notification_enabled( $key ) {
		$settings = get_option( 'telepilot_settings', array() );
		$enabled  = isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] )
			? $settings['default_notifications']
			: array();

		return in_array( $key, $enabled, true );
	}
}
