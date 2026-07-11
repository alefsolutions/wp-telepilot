<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Users_Service {
	const META_DISABLED = '_telepress_disabled';
	const PER_PAGE      = 5;

	private $confirmation_service;

	public function __construct( TelePress_Confirmation_Service $confirmation_service ) {
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
			return $heading . "\n" . __( 'No users matched that request.', 'telepress' );
		}

		$lines = array( $heading );

		foreach ( $users as $user ) {
			$roles      = implode( ', ', array_map( 'sanitize_text_field', $user->roles ) );
			$is_disabled = $this->is_disabled( $user->ID ) ? __( 'disabled', 'telepress' ) : __( 'active', 'telepress' );
			$lines[]    = sprintf(
				__( '#%1$d %2$s [%3$s] (%4$s)', 'telepress' ),
				$user->ID,
				$user->user_login,
				$roles ? $roles : __( 'no role', 'telepress' ),
				$is_disabled
			);
		}

		return implode( "\n", $lines );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return $heading . "\n" . __( 'No users matched that request.', 'telepress' );
		}

		$lines   = array( $heading );
		$lines[] = sprintf( __( 'Page %1$d of %2$d', 'telepress' ), $result['page'], $result['total_pages'] );

		foreach ( $result['items'] as $user ) {
			$roles       = implode( ', ', array_map( 'sanitize_text_field', $user->roles ) );
			$is_disabled = $this->is_disabled( $user->ID ) ? __( 'disabled', 'telepress' ) : __( 'active', 'telepress' );
			$lines[]     = sprintf(
				__( '#%1$d %2$s [%3$s] (%4$s)', 'telepress' ),
				$user->ID,
				$user->user_login,
				$roles ? $roles : __( 'no role', 'telepress' ),
				$is_disabled
			);
		}

		$lines[] = __( 'Tip: use `/users search keyword` to jump to a person quickly.', 'telepress' );

		return implode( "\n", $lines );
	}

	public function create_user( $username, $email, $role ) {
		$username = sanitize_user( $username, true );
		$email    = sanitize_email( $email );
		$role     = sanitize_key( $role );

		if ( '' === $username || ! validate_username( $username ) ) {
			return new WP_Error( 'telepress_invalid_username', __( 'The username is invalid.', 'telepress' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error( 'telepress_username_exists', __( 'That username already exists.', 'telepress' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'telepress_invalid_email', __( 'The email address is invalid.', 'telepress' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'telepress_email_exists', __( 'That email address is already in use.', 'telepress' ) );
		}

		if ( ! get_role( $role ) ) {
			return new WP_Error( 'telepress_invalid_role', __( 'That role does not exist.', 'telepress' ) );
		}

		$password = wp_generate_password( 24, true, true );
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		$user->set_role( $role );

		return array(
			'user'  => $user,
			'label' => __( 'created', 'telepress' ),
		);
	}

	public function disable_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepress_user_not_found', __( 'User not found.', 'telepress' ) );
		}

		update_user_meta( $user_id, self::META_DISABLED, 1 );

		return array(
			'user'         => $user,
			'before_state' => array( 'disabled' => false ),
			'after_state'  => array( 'disabled' => true ),
			'label'        => __( 'disabled', 'telepress' ),
		);
	}

	public function enable_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepress_user_not_found', __( 'User not found.', 'telepress' ) );
		}

		delete_user_meta( $user_id, self::META_DISABLED );

		return array(
			'user'         => $user,
			'before_state' => array( 'disabled' => true ),
			'after_state'  => array( 'disabled' => false ),
			'label'        => __( 'enabled', 'telepress' ),
		);
	}

	public function assign_role( $user_id, $role ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepress_user_not_found', __( 'User not found.', 'telepress' ) );
		}

		$role = sanitize_key( $role );
		if ( ! get_role( $role ) ) {
			return new WP_Error( 'telepress_invalid_role', __( 'That role does not exist.', 'telepress' ) );
		}

		$before_roles = $user->roles;
		$user->set_role( $role );

		return array(
			'user'         => $user,
			'before_state' => array( 'roles' => $before_roles ),
			'after_state'  => array( 'roles' => $user->roles ),
			'label'        => sprintf( __( 'assigned role %s', 'telepress' ), $role ),
		);
	}

	public function generate_reset_link( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'telepress_user_not_found', __( 'User not found.', 'telepress' ) );
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
			'label' => __( 'password reset generated', 'telepress' ),
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

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard(
				array(
					array(
						array(
							'text'          => sprintf( __( 'Confirm %1$s #%2$d', 'telepress' ), ucfirst( str_replace( '-', ' ', $action ) ), $user_id ),
							'callback_data' => 'tp:user:' . $action . ':' . (int) $user_id . ':' . $token,
						),
					),
				)
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

			$row = array();
			$row[] = array(
				'text'          => sprintf( __( 'Reset #%d', 'telepress' ), $user->ID ),
				'callback_data' => '/users reset-password ' . (int) $user->ID,
			);

			if ( $this->is_disabled( $user->ID ) ) {
				$row[] = array(
					'text'          => sprintf( __( 'Enable #%d', 'telepress' ), $user->ID ),
					'callback_data' => '/users enable ' . (int) $user->ID,
				);
			} else {
				$row[] = array(
					'text'          => sprintf( __( 'Disable #%d', 'telepress' ), $user->ID ),
					'callback_data' => '/users disable ' . (int) $user->ID,
				);
			}

			$rows[] = $row;
		}

		$pagination = $this->build_pagination_row( $subcommand, $search_term, $page, $total_pages );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return TelePress_Telegram_Response_Builder::append_rows(
			TelePress_Telegram_Response_Builder::keyboard( $rows ),
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
				'text'          => __( 'Prev', 'telepress' ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepress' ),
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
		$page  = max( 1, absint( $page ) );
		$limit = max( 1, absint( $limit ) );

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

		return array(
			'items'       => $query->get_results(),
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => $total_items,
			'total_pages' => max( 1, (int) ceil( $total_items / $limit ) ),
		);
	}

	public function block_disabled_user( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( $this->is_disabled( $user->ID ) ) {
			return new WP_Error( 'telepress_user_disabled', __( 'This user account has been disabled by TelePress.', 'telepress' ) );
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

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Menu', 'telepress' ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => __( 'Site', 'telepress' ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
