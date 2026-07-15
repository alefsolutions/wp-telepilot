<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Users_Service {
	const META_DISABLED = '_telepilot_disabled';
	const PER_PAGE      = 5;

	private $confirmation_service;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function recent( $limit = 5 ) {
		return get_users(
			array(
				'number'      => max( 1, absint( $limit ) ),
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'count_total' => false,
			)
		);
	}

	public function recent_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_users_page(
			array(
				'orderby' => 'registered',
				'order'   => 'DESC',
			),
			$page,
			$limit
		);
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		$term = sanitize_text_field( $term );

		return $this->query_users_page(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			),
			$page,
			$limit
		);
	}

	public function render_list_message( $users, $heading ) {
		if ( empty( $users ) ) {
			return $heading . "\n" . __( 'No users matched that request.', 'telepilot' );
		}

		$lines = array( $heading );

		foreach ( $users as $user ) {
			$roles      = implode( ', ', array_map( 'sanitize_text_field', $user->roles ) );
			$is_disabled = $this->is_disabled( $user->ID ) ? __( 'disabled', 'telepilot' ) : __( 'active', 'telepilot' );
			$lines[]    = sprintf(
				__( '[%1$d] %2$s [%3$s] (%4$s)', 'telepilot' ),
				$user->ID,
				$user->user_login,
				$roles ? $roles : __( 'no role', 'telepilot' ),
				$is_disabled
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( $heading ) . "\n\n" . __( 'No users matched that request.', 'telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( $heading ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $user ) {
			$roles       = implode( ', ', array_map( 'sanitize_text_field', $user->roles ) );
			$is_disabled = $this->is_disabled( $user->ID ) ? __( 'disabled', 'telepilot' ) : __( 'active', 'telepilot' );
			$blocks[]    = sprintf(
				__( '[%1$d] %2$s [%3$s] (%4$s)', 'telepilot' ),
				$user->ID,
				Telepilot_Telegram_Response_Builder::escape( $user->user_login ),
				Telepilot_Telegram_Response_Builder::escape( $roles ? $roles : __( 'no role', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::escape( $is_disabled )
			);
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use /users help for examples and /users search keyword to jump to a person quickly.', 'telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Users Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/users list' ) . ' ' . __( 'Show recent users', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users search jane' ) . ' ' . __( 'Search by username, display name, or email', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users details 123' ) . ' ' . __( 'Show a user summary and Telegram link status', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users create jane jane@example.com editor' ) . ' ' . __( 'Create a new user', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users email 123 jane@example.com' ) . ' ' . __( 'Update a user email address', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users display-name 123 Jane Doe' ) . ' ' . __( 'Update a display name', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users disable 123' ) . ' ' . __( 'Disable a user', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users enable 123' ) . ' ' . __( 'Re-enable a user', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users reset-password 123' ) . ' ' . __( 'Generate a password reset link', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users email-reset-password 123' ) . ' ' . __( 'Email the official WordPress reset link to the user', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users welcome-email 123' ) . ' ' . __( 'Re-send the WordPress welcome email to the user', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users role 123 editor' ) . ' ' . __( 'Change a user role', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/users delete 123 1' ) . ' ' . __( 'Delete a user and optionally reassign their content', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Tip: user creation and other sensitive actions must be confirmed in a private chat.', 'telepilot' ) ),
			)
		);
	}

	public function get_user_details( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		return $user;
	}

	public function render_details_message( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return Telepilot_Telegram_Response_Builder::bold( __( 'User Details', 'telepilot' ) ) . "\n\n" . __( 'User not found.', 'telepilot' );
		}

		$roles            = implode( ', ', array_map( 'sanitize_text_field', (array) $user->roles ) );
		$telegram_user_id = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$telegram_chat_id = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );
		$telegram_name    = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_NAME, true );
		$lines            = array(
			Telepilot_Telegram_Response_Builder::bold( __( 'User Details', 'telepilot' ) ),
			implode(
				"\n",
				array(
					sprintf( __( 'User: [%1$d] %2$s', 'telepilot' ), $user->ID, Telepilot_Telegram_Response_Builder::escape( $user->user_login ) ),
					sprintf( __( 'Display name: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $user->display_name ) ),
					sprintf( __( 'Email: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $user->user_email ) ),
					sprintf( __( 'Roles: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $roles ? $roles : __( 'no role', 'telepilot' ) ) ),
					sprintf( __( 'Status: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $this->is_disabled( $user->ID ) ? __( 'disabled', 'telepilot' ) : __( 'active', 'telepilot' ) ) ),
					sprintf( __( 'Registered: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( mysql2date( 'Y-m-d H:i:s', $user->user_registered ) ) ),
				)
			),
		);

		if ( '' !== $telegram_user_id ) {
			$link_lines = array(
				sprintf( __( 'Telegram user ID: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $telegram_user_id ) ),
				sprintf( __( 'Chat ID: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $telegram_chat_id ? $telegram_chat_id : __( 'Unknown', 'telepilot' ) ) ),
			);

			if ( '' !== $telegram_name ) {
				$link_lines[] = sprintf( __( 'Telegram username: @%s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $telegram_name ) );
			}

			$lines[] = implode( "\n", $link_lines );
		} else {
			$lines[] = __( 'Telegram: not linked yet.', 'telepilot' );
		}

		$lines[] = sprintf(
			__( 'Admin: %s', 'telepilot' ),
			Telepilot_Telegram_Response_Builder::link( __( 'Open user in wp-admin', 'telepilot' ), admin_url( 'user-edit.php?user_id=' . (int) $user->ID ) )
		);

		return Telepilot_Telegram_Response_Builder::join_blocks( $lines );
	}

	public function create_user( $username, $email, $role ) {
		$username = sanitize_user( $username, true );
		$email    = sanitize_email( $email );
		$role     = sanitize_key( $role );

		if ( '' === $username || ! validate_username( $username ) ) {
			return new WP_Error( 'telepilot_invalid_username', __( 'The username is invalid.', 'telepilot' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error( 'telepilot_username_exists', __( 'That username already exists.', 'telepilot' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'telepilot_invalid_email', __( 'The email address is invalid.', 'telepilot' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'telepilot_email_exists', __( 'That email address is already in use.', 'telepilot' ) );
		}

		if ( ! get_role( $role ) ) {
			return new WP_Error( 'telepilot_invalid_role', __( 'That role does not exist.', 'telepilot' ) );
		}

		$password = wp_generate_password( 24, true, true );
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		$user->set_role( $role );

		$this->bump_cache_version();

		return array(
			'user'  => $user,
			'label' => __( 'created', 'telepilot' ),
		);
	}

	public function disable_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		update_user_meta( $user_id, self::META_DISABLED, 1 );
		$this->bump_cache_version();

		return array(
			'user'         => $user,
			'before_state' => array( 'disabled' => false ),
			'after_state'  => array( 'disabled' => true ),
			'label'        => __( 'disabled', 'telepilot' ),
		);
	}

	public function enable_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		delete_user_meta( $user_id, self::META_DISABLED );
		$this->bump_cache_version();

		return array(
			'user'         => $user,
			'before_state' => array( 'disabled' => true ),
			'after_state'  => array( 'disabled' => false ),
			'label'        => __( 'enabled', 'telepilot' ),
		);
	}

	public function assign_role( $user_id, $role ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$role = sanitize_key( $role );
		if ( ! get_role( $role ) ) {
			return new WP_Error( 'telepilot_invalid_role', __( 'That role does not exist.', 'telepilot' ) );
		}

		$before_roles = $user->roles;
		$user->set_role( $role );
		$this->bump_cache_version();

		return array(
			'user'         => $user,
			'before_state' => array( 'roles' => $before_roles ),
			'after_state'  => array( 'roles' => $user->roles ),
			'label'        => sprintf( __( 'assigned role %s', 'telepilot' ), $role ),
		);
	}

	public function generate_reset_link( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		return array(
			'user'  => $user,
			'url'   => $url,
			'label' => __( 'password reset generated', 'telepilot' ),
		);
	}

	public function send_reset_email( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$result = retrieve_password( $user->user_login );
		if ( true !== $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'telepilot_reset_email_failed', __( 'WordPress could not send the password reset email.', 'telepilot' ) );
		}

		return array(
			'user'  => $user,
			'label' => __( 'password reset emailed', 'telepilot' ),
		);
	}

	public function update_email( $user_id, $email ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'telepilot_invalid_email', __( 'The email address is invalid.', 'telepilot' ) );
		}

		$existing = email_exists( $email );
		if ( $existing && (int) $existing !== (int) $user_id ) {
			return new WP_Error( 'telepilot_email_exists', __( 'That email address is already in use.', 'telepilot' ) );
		}

		$before_state = array(
			'user_email' => (string) $user->user_email,
		);

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();
		$updated = get_user_by( 'id', $user_id );

		return array(
			'user'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'user_email' => (string) $updated->user_email,
			),
			'label'        => __( 'email updated', 'telepilot' ),
		);
	}

	public function update_display_name( $user_id, $display_name ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$display_name = sanitize_text_field( $display_name );
		if ( '' === $display_name ) {
			return new WP_Error( 'telepilot_invalid_display_name', __( 'The display name cannot be empty.', 'telepilot' ) );
		}

		$before_state = array(
			'display_name' => (string) $user->display_name,
		);

		$result = wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $display_name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();
		$updated = get_user_by( 'id', $user_id );

		return array(
			'user'         => $updated,
			'before_state' => $before_state,
			'after_state'  => array(
				'display_name' => (string) $updated->display_name,
			),
			'label'        => __( 'display name updated', 'telepilot' ),
		);
	}

	public function send_welcome_email( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		if ( ! function_exists( 'wp_send_new_user_notifications' ) ) {
			return new WP_Error( 'telepilot_welcome_unsupported', __( 'WordPress welcome email notifications are not available on this site.', 'telepilot' ) );
		}

		wp_send_new_user_notifications( $user_id, 'user' );

		return array(
			'user'  => $user,
			'label' => __( 'welcome email sent', 'telepilot' ),
		);
	}

	public function delete_user( $user_id, $reassign_id = 0 ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'telepilot' ) );
		}

		$reassign_id = absint( $reassign_id );
		if ( $reassign_id && $reassign_id === $user_id ) {
			return new WP_Error( 'telepilot_user_reassign_invalid', __( 'A user cannot be reassigned to themselves.', 'telepilot' ) );
		}

		if ( $reassign_id && ! get_user_by( 'id', $reassign_id ) ) {
			return new WP_Error( 'telepilot_user_reassign_missing', __( 'The reassignment user could not be found.', 'telepilot' ) );
		}

		$before_state = array(
			'user_login'   => (string) $user->user_login,
			'user_email'   => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'roles'        => array_values( array_map( 'sanitize_text_field', (array) $user->roles ) ),
		);

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $user_id, $reassign_id ? $reassign_id : null );

		if ( ! $result ) {
			return new WP_Error( 'telepilot_user_delete_failed', __( 'WordPress could not delete that user.', 'telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'before_state' => $before_state,
			'after_state'  => array(
				'reassign_to' => $reassign_id,
			),
			'label'        => __( 'deleted', 'telepilot' ),
		);
	}

	public function build_confirmation_keyboard( $action, $user_id, $telegram_user_id, $extra = array() ) {
		$payload = array(
			'action'           => (string) $action,
			'user_id'          => (int) $user_id,
			'telegram_user_id' => (string) $telegram_user_id,
		);

		if ( ! empty( $extra ) ) {
			$payload = array_merge( $payload, $extra );
		}

		$token = $this->confirmation_service->create_token( $payload );

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$d]', 'telepilot' ), ucfirst( str_replace( '-', ' ', $action ) ), $user_id ),
				'tp:user:' . $action . ':' . (int) $user_id . ':' . $token,
				'/users list'
			),
			$this->navigation_rows()
		);
	}

	public function build_list_keyboard( $users, $subcommand = 'list', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$rows[] = array(
				array(
					'text'          => sprintf( __( 'Details [%d]', 'telepilot' ), $user->ID ),
					'callback_data' => '/users details ' . (int) $user->ID,
				),
				array(
					'text'          => sprintf( __( 'Reset [%d]', 'telepilot' ), $user->ID ),
					'callback_data' => '/users reset-password ' . (int) $user->ID,
				),
				array(
					'text'          => sprintf( __( 'Email Reset [%d]', 'telepilot' ), $user->ID ),
					'callback_data' => '/users email-reset-password ' . (int) $user->ID,
				),
			);

			$rows[] = array(
				$this->is_disabled( $user->ID )
					? array(
						'text'          => sprintf( __( 'Enable [%d]', 'telepilot' ), $user->ID ),
						'callback_data' => '/users enable ' . (int) $user->ID,
					)
					: array(
						'text'          => sprintf( __( 'Disable [%d]', 'telepilot' ), $user->ID ),
						'callback_data' => '/users disable ' . (int) $user->ID,
					),
				array(
					'text'          => sprintf( __( 'Welcome [%d]', 'telepilot' ), $user->ID ),
					'callback_data' => '/users welcome-email ' . (int) $user->ID,
				),
			);
		}

		$pagination = $this->build_pagination_row( $subcommand, $search_term, $page, $total_pages );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	private function build_pagination_row( $subcommand, $search_term, $page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return array();
		}

		$buttons = array();

		if ( $page > 1 ) {
			$buttons[] = array(
				'text'          => __( 'Prev', 'telepilot' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepilot' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page + 1 ),
			);
		}

		return $buttons;
	}

	private function build_command( $subcommand, $search_term, $page ) {
		if ( 'search' === $subcommand ) {
			return trim( '/users search ' . $search_term . ' page:' . $page );
		}

		return '/users list page:' . $page;
	}

	private function query_users_page( $args, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$cache_key = 'telepilot_users_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $args, $page, $limit ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_User_Query(
			array_merge(
				$args,
				array(
					'number'      => $limit,
					'offset'      => ( $page - 1 ) * $limit,
					'count_total' => true,
				)
			)
		);

		$total_items = (int) $query->get_total();

		$result = array(
			'items'       => $query->get_results(),
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => $total_items,
			'total_pages' => max( 1, (int) ceil( $total_items / $limit ) ),
		);

		set_transient( $cache_key, $result, 30 );

		return $result;
	}

	public function block_disabled_user( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( $this->is_disabled( $user->ID ) ) {
			return new WP_Error( 'telepilot_user_disabled', __( 'This user account has been disabled by WP Telepilot.', 'telepilot' ) );
		}

		return $user;
	}

	public function is_disabled( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_DISABLED, true );
	}

	public function actor_can_assign_role( $actor_user, $role ) {
		if ( ! ( $actor_user instanceof WP_User ) ) {
			return false;
		}

		$role = sanitize_key( $role );

		if ( 'administrator' === $role && ! in_array( 'administrator', (array) $actor_user->roles, true ) ) {
			return false;
		}

		return true;
	}

	private function bump_cache_version() {
		update_option( 'telepilot_users_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_users_cache_version', 1 ) );
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepilot' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepilot' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
