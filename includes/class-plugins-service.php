<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Plugins_Service {
	const PER_PAGE = 5;

	private $confirmation_service;

	public function __construct( Telepilot_Confirmation_Service $confirmation_service ) {
		$this->confirmation_service = $confirmation_service;
	}

	public function list_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_plugins_page( '', 'all', $page, $limit );
	}

	public function search_page( $term, $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_plugins_page( sanitize_text_field( $term ), 'search', $page, $limit );
	}

	public function updates_page( $page = 1, $limit = self::PER_PAGE ) {
		return $this->query_plugins_page( '', 'updates', $page, $limit );
	}

	public function render_page_message( $result, $heading ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'plugins', $heading ) ) . "\n\n" . __( 'No plugins matched that request.', 'wp-telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'plugins', $heading ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d | %3$d items', 'wp-telepilot' ), $result['page'], $result['total_pages'], isset( $result['total_items'] ) ? (int) $result['total_items'] : count( $result['items'] ) )
		);

		foreach ( $result['items'] as $plugin ) {
			$blocks[] = $this->format_plugin_summary_block( $plugin );
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use /plugins details slug for a deeper look before activating, updating, or deleting.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	private function format_plugin_summary_block( $plugin ) {
		$status = ! empty( $plugin['is_active'] ) ? __( 'Active', 'wp-telepilot' ) : __( 'Inactive', 'wp-telepilot' );
		$lines  = array(
			Telepilot_Telegram_Response_Builder::label(
				'plugins',
				sprintf(
					__( '[%1$d] %2$s', 'wp-telepilot' ),
					isset( $plugin['list_number'] ) ? (int) $plugin['list_number'] : 0,
					Telepilot_Telegram_Response_Builder::escape( $plugin['name'] )
				)
			),
			sprintf( __( 'Identifier: [%s]', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['identifier'] ) ),
			sprintf( __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $status ) ),
			sprintf( __( 'Version: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['version'] ) ),
		);

		if ( ! empty( $plugin['author'] ) ) {
			$lines[] = sprintf( __( 'Author: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( wp_strip_all_tags( $plugin['author'] ) ) );
		}

		if ( ! empty( $plugin['update_version'] ) ) {
			$lines[] = sprintf( __( 'Update available: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['update_version'] ) );
		}

		if ( ! empty( $plugin['is_self'] ) ) {
			$lines[] = __( 'Protected: this plugin is excluded from self-management.', 'wp-telepilot' );
		}

		return implode( "\n", $lines );
	}

	public function render_details_message( $plugin ) {
		if ( empty( $plugin ) || ! is_array( $plugin ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'plugins', __( 'Plugin Details', 'wp-telepilot' ) ) ) . "\n\n" . __( 'Plugin not found.', 'wp-telepilot' );
		}

		$status = $plugin['is_active'] ? __( 'Active', 'wp-telepilot' ) : __( 'Inactive', 'wp-telepilot' );
		$lines  = array(
			Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'plugins', __( 'Plugin Details', 'wp-telepilot' ) ) ),
			'',
			sprintf( __( 'Name: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['name'] ) ),
			sprintf( __( 'Identifier: [%s]', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['identifier'] ) ),
			sprintf( __( 'Status: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $status ) ),
			sprintf( __( 'Version: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['version'] ) ),
			sprintf( __( 'File: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['file'] ) ),
		);

		if ( ! empty( $plugin['author'] ) ) {
			$lines[] = sprintf( __( 'Author: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( wp_strip_all_tags( $plugin['author'] ) ) );
		}

		if ( ! empty( $plugin['update_version'] ) ) {
			$lines[] = sprintf( __( 'Update available: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $plugin['update_version'] ) );
		}

		if ( ! empty( $plugin['is_self'] ) ) {
			$lines[] = __( 'Protected: WP Telepilot does not manage itself through Telegram.', 'wp-telepilot' );
		}

		return implode( "\n", $lines );
	}

	public function render_help_message() {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'plugins', __( 'Plugins Commands', 'wp-telepilot' ) ) ),
				Telepilot_Telegram_Response_Builder::code( '/plugins list' ) . ' ' . __( 'Show installed plugins', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins search seo' ) . ' ' . __( 'Search installed plugins', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins updates' ) . ' ' . __( 'Show plugins with available updates', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins refresh' ) . ' ' . __( 'Refresh plugin update information', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins details akismet' ) . ' ' . __( 'Show plugin details', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins activate akismet' ) . ' ' . __( 'Activate a plugin after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins deactivate akismet' ) . ' ' . __( 'Deactivate a plugin after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins update akismet' ) . ' ' . __( 'Update a plugin after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/plugins delete akismet' ) . ' ' . __( 'Delete a plugin after confirmation', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::italic( __( 'Tip: use the plugin identifier shown in brackets, such as [akismet], when running plugin commands.', 'wp-telepilot' ) ),
			)
		);
	}

	public function refresh_updates() {
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}

		wp_update_plugins();
		wp_clean_plugins_cache( false );
		$this->bump_cache_version();

		return array(
			'label'       => __( 'plugin metadata refreshed', 'wp-telepilot' ),
			'after_state' => array(
				'refreshed_at' => time(),
			),
		);
	}

	public function build_list_keyboard( $plugins, $subcommand = 'list', $search_term = '', $page = 1, $total_pages = 1 ) {
		$rows = array();

		foreach ( $plugins as $plugin ) {
			$identifier  = $plugin['identifier'];
			$list_number = isset( $plugin['list_number'] ) ? (int) $plugin['list_number'] : 0;
			$row         = array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'details', sprintf( __( 'Details [%d]', 'wp-telepilot' ), $list_number ) ),
					'callback_data' => '/plugins details ' . $identifier,
				),
			);

			if ( $plugin['is_self'] ) {
				$rows[] = $row;
				continue;
			}

			if ( ! empty( $plugin['update_version'] ) ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'update', sprintf( __( 'Update [%d]', 'wp-telepilot' ), $list_number ) ),
					'callback_data' => '/plugins update ' . $identifier,
				);
			} elseif ( $plugin['is_active'] ) {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'disable', sprintf( __( 'Deactivate [%d]', 'wp-telepilot' ), $list_number ) ),
					'callback_data' => '/plugins deactivate ' . $identifier,
				);
			} else {
				$row[] = array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'enable', sprintf( __( 'Activate [%d]', 'wp-telepilot' ), $list_number ) ),
					'callback_data' => '/plugins activate ' . $identifier,
				);
			}

			$row[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'delete', sprintf( __( 'Delete [%d]', 'wp-telepilot' ), $list_number ) ),
				'callback_data' => '/plugins delete ' . $identifier,
			);

			$rows[] = $row;
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

	public function build_action_confirmation_keyboard( $action, $plugin_file, $telegram_user_id ) {
		$resolved = $this->resolve_plugin_file( $plugin_file );
		$plugin   = $resolved ? $this->get_plugin_record( $resolved ) : array();
		$label  = ! empty( $plugin['identifier'] ) ? $plugin['identifier'] : $this->identifier_from_plugin_file( $plugin_file );
		$token  = $this->confirmation_service->create_token(
			array(
				'action'           => (string) $action,
				'plugin_file'      => (string) ( $resolved ? $resolved : $plugin_file ),
				'identifier'       => (string) $label,
				'telegram_user_id' => (string) $telegram_user_id,
			)
		);

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::confirmation_keyboard(
				sprintf( __( 'Confirm %1$s [%2$s]', 'wp-telepilot' ), ucfirst( $action ), $label ),
				'tp:plugin:confirm:' . $token,
				'/plugins list'
			),
			$this->navigation_rows()
		);
	}

	public function get_plugin_details( $identifier ) {
		$plugin_file = $this->resolve_plugin_file( $identifier );

		if ( ! $plugin_file ) {
			return new WP_Error( 'telepilot_plugin_not_found', __( 'Plugin not found.', 'wp-telepilot' ) );
		}

		return $this->get_plugin_record( $plugin_file );
	}

	public function activate( $identifier ) {
		$plugin_file = $this->resolve_plugin_file( $identifier );
		if ( ! $plugin_file ) {
			return new WP_Error( 'telepilot_plugin_not_found', __( 'Plugin not found.', 'wp-telepilot' ) );
		}

		$self_action = $this->guard_self_action( $plugin_file, 'activate' );
		if ( is_wp_error( $self_action ) ) {
			return $self_action;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'telepilot_plugin_already_active', __( 'That plugin is already active.', 'wp-telepilot' ) );
		}

		$result = activate_plugin( $plugin_file, '', false, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->bump_cache_version();

		return $this->build_action_result( $plugin_file, __( 'activated', 'wp-telepilot' ) );
	}

	public function deactivate( $identifier ) {
		$plugin_file = $this->resolve_plugin_file( $identifier );
		if ( ! $plugin_file ) {
			return new WP_Error( 'telepilot_plugin_not_found', __( 'Plugin not found.', 'wp-telepilot' ) );
		}

		$self_action = $this->guard_self_action( $plugin_file, 'deactivate' );
		if ( is_wp_error( $self_action ) ) {
			return $self_action;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'telepilot_plugin_already_inactive', __( 'That plugin is already inactive.', 'wp-telepilot' ) );
		}

		$before = $this->get_plugin_record( $plugin_file );
		deactivate_plugins( $plugin_file, false, false );
		$this->bump_cache_version();

		return array(
			'plugin'       => $this->get_plugin_record( $plugin_file ),
			'before_state' => $before,
			'after_state'  => $this->get_plugin_record( $plugin_file ),
			'label'        => __( 'deactivated', 'wp-telepilot' ),
		);
	}

	public function delete( $identifier ) {
		$plugin_file = $this->resolve_plugin_file( $identifier );
		if ( ! $plugin_file ) {
			return new WP_Error( 'telepilot_plugin_not_found', __( 'Plugin not found.', 'wp-telepilot' ) );
		}

		$self_action = $this->guard_self_action( $plugin_file, 'delete' );
		if ( is_wp_error( $self_action ) ) {
			return $self_action;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'telepilot_plugin_active_delete_blocked', __( 'Deactivate the plugin before deleting it.', 'wp-telepilot' ) );
		}

		$before = $this->get_plugin_record( $plugin_file );
		$result = delete_plugins( array( $plugin_file ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error( 'telepilot_plugin_delete_failed', __( 'WordPress could not delete that plugin.', 'wp-telepilot' ) );
		}

		$this->bump_cache_version();

		return array(
			'plugin'       => $before,
			'before_state' => $before,
			'after_state'  => array( 'deleted' => true ),
			'label'        => __( 'deleted', 'wp-telepilot' ),
		);
	}

	public function update( $identifier ) {
		$plugin_file = $this->resolve_plugin_file( $identifier );
		if ( ! $plugin_file ) {
			return new WP_Error( 'telepilot_plugin_not_found', __( 'Plugin not found.', 'wp-telepilot' ) );
		}

		$self_action = $this->guard_self_action( $plugin_file, 'update' );
		if ( is_wp_error( $self_action ) ) {
			return $self_action;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_update_plugins();

		$before = $this->get_plugin_record( $plugin_file );
		if ( empty( $before['update_version'] ) ) {
			return new WP_Error( 'telepilot_plugin_no_update', __( 'That plugin does not currently have an available update.', 'wp-telepilot' ) );
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error( 'telepilot_plugin_update_failed', __( 'WordPress could not update that plugin.', 'wp-telepilot' ) );
		}

		wp_clean_plugins_cache( false );
		wp_update_plugins();
		$this->bump_cache_version();

		return array(
			'plugin'       => $this->get_plugin_record( $plugin_file ),
			'before_state' => $before,
			'after_state'  => $this->get_plugin_record( $plugin_file ),
			'label'        => __( 'updated', 'wp-telepilot' ),
		);
	}

	public function resolve_plugin_file( $identifier ) {
		$identifier = strtolower( trim( (string) $identifier ) );
		if ( '' === $identifier ) {
			return '';
		}

		$plugins = $this->all_plugins();

		if ( isset( $plugins[ $identifier ] ) ) {
			return $identifier;
		}

		foreach ( array_keys( $plugins ) as $plugin_file ) {
			$record = $this->get_plugin_record( $plugin_file );

			if ( strtolower( $record['identifier'] ) === $identifier ) {
				return $plugin_file;
			}

			if ( strtolower( basename( $plugin_file ) ) === $identifier ) {
				return $plugin_file;
			}

			if ( strtolower( basename( $plugin_file, '.php' ) ) === $identifier ) {
				return $plugin_file;
			}
		}

		return '';
	}

	private function query_plugins_page( $term, $mode, $page, $limit ) {
		$page      = max( 1, absint( $page ) );
		$limit     = max( 1, absint( $limit ) );
		$term      = sanitize_text_field( $term );
		$cache_key = 'telepilot_plugins_' . $this->get_cache_version() . '_' . md5( wp_json_encode( array( $term, $mode, $page, $limit ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$plugins = array_values( $this->all_plugin_records() );

		if ( 'search' === $mode && '' !== $term ) {
			$plugins = array_values(
				array_filter(
					$plugins,
					function( $plugin ) use ( $term ) {
						$haystacks = array(
							$plugin['identifier'],
							$plugin['file'],
							$plugin['name'],
							wp_strip_all_tags( $plugin['author'] ),
						);

						foreach ( $haystacks as $haystack ) {
							if ( false !== stripos( (string) $haystack, $term ) ) {
								return true;
							}
						}

						return false;
					}
				)
			);
		}

		if ( 'updates' === $mode ) {
			$plugins = array_values(
				array_filter(
					$plugins,
					function( $plugin ) {
						return ! empty( $plugin['update_version'] );
					}
				)
			);
		}

		usort(
			$plugins,
			function( $left, $right ) {
				if ( $left['is_active'] !== $right['is_active'] ) {
					return $left['is_active'] ? -1 : 1;
				}

				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		$total_items = count( $plugins );
		$total_pages = max( 1, (int) ceil( $total_items / $limit ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $limit;

		$items  = array_slice( $plugins, $offset, $limit );
		foreach ( $items as $index => $plugin ) {
			$items[ $index ]['list_number'] = $index + 1;
		}

		$result = array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => $total_items,
			'total_pages' => $total_pages,
			'mode'        => $mode,
			'search'      => $term,
		);

		set_transient( $cache_key, $result, 30 );

		return $result;
	}

	private function all_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	private function all_plugin_records() {
		$records = array();

		foreach ( $this->all_plugins() as $plugin_file => $plugin_data ) {
			$records[ $plugin_file ] = $this->build_plugin_record( $plugin_file, $plugin_data );
		}

		return $records;
	}

	private function get_plugin_record( $plugin_file ) {
		$plugins = $this->all_plugins();

		if ( empty( $plugins[ $plugin_file ] ) ) {
			return array();
		}

		return $this->build_plugin_record( $plugin_file, $plugins[ $plugin_file ] );
	}

	private function build_plugin_record( $plugin_file, $plugin_data ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$updates       = get_site_transient( 'update_plugins' );
		$update_data   = ! empty( $updates->response[ $plugin_file ] ) ? $updates->response[ $plugin_file ] : null;
		$identifier    = $this->identifier_from_plugin_file( $plugin_file );

		return array(
			'file'           => (string) $plugin_file,
			'identifier'     => $identifier,
			'name'           => isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : $identifier,
			'version'        => isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '',
			'author'         => isset( $plugin_data['Author'] ) ? (string) $plugin_data['Author'] : '',
			'is_active'      => is_plugin_active( $plugin_file ),
			'is_self'        => plugin_basename( TELEPILOT_FILE ) === $plugin_file,
			'update_version' => ! empty( $update_data->new_version ) ? (string) $update_data->new_version : '',
		);
	}

	private function identifier_from_plugin_file( $plugin_file ) {
		$plugin_file = (string) $plugin_file;

		if ( false !== strpos( $plugin_file, '/' ) ) {
			$parts = explode( '/', $plugin_file );
			return sanitize_key( $parts[0] );
		}

		return sanitize_key( basename( $plugin_file, '.php' ) );
	}

	private function build_action_result( $plugin_file, $label ) {
		$plugin = $this->get_plugin_record( $plugin_file );

		return array(
			'plugin'       => $plugin,
			'before_state' => array(),
			'after_state'  => $plugin,
			'label'        => $label,
		);
	}

	private function bump_cache_version() {
		update_option( 'telepilot_plugins_cache_version', $this->get_cache_version() + 1, false );
	}

	private function get_cache_version() {
		return max( 1, (int) get_option( 'telepilot_plugins_cache_version', 1 ) );
	}

	private function guard_self_action( $plugin_file, $action ) {
		if ( plugin_basename( TELEPILOT_FILE ) !== $plugin_file ) {
			return true;
		}

		$message = __( 'WP Telepilot cannot manage its own plugin file through Telegram for safety.', 'wp-telepilot' );

		if ( 'update' === $action ) {
			$message = __( 'WP Telepilot cannot update itself through Telegram for safety.', 'wp-telepilot' );
		} elseif ( 'delete' === $action ) {
			$message = __( 'WP Telepilot cannot delete itself through Telegram for safety.', 'wp-telepilot' );
		} elseif ( 'deactivate' === $action ) {
			$message = __( 'WP Telepilot cannot deactivate itself through Telegram for safety.', 'wp-telepilot' );
		}

		return new WP_Error( 'telepilot_plugin_self_action_blocked', $message );
	}

	private function build_pagination_row( $subcommand, $search_term, $page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return array();
		}

		$buttons = array();

		if ( $page > 1 ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ),
				'callback_data' => $this->build_command( $subcommand, $search_term, $page + 1 ),
			);
		}

		return $buttons;
	}

	private function build_command( $subcommand, $search_term, $page ) {
		if ( 'search' === $subcommand ) {
			return trim( '/plugins search ' . $search_term . ' page:' . $page );
		}

		if ( 'updates' === $subcommand ) {
			return '/plugins updates page:' . $page;
		}

		return '/plugins list page:' . $page;
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
					'callback_data' => '/menu',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'site', __( 'Site', 'wp-telepilot' ) ),
					'callback_data' => '/site',
				),
			),
		);
	}
}
