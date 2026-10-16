<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Notifications_Command_Service {
	const PER_PAGE = 5;

	public function list_page( $page = 1, $limit = self::PER_PAGE ) {
		return Telepilot_Domain_Abilities::run( 'notifications', 'list_page', get_defined_vars(), function () use ( $page, $limit ) { return $this->operation_list_page( $page, $limit ); } );
	}

	private function operation_list_page( $page = 1, $limit = self::PER_PAGE ) {
		$page   = max( 1, absint( $page ) );
		$limit  = max( 1, absint( $limit ) );
		$items  = array();
		$labels = Telepilot_Notification_Service::option_labels();
		$keys   = array_keys( $labels );
		$total  = count( $keys );
		$pages  = max( 1, (int) ceil( $total / $limit ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * $limit;

		foreach ( array_slice( $keys, $offset, $limit ) as $key ) {
			$items[] = array(
				'key'     => (string) $key,
				'label'   => (string) $labels[ $key ],
				'enabled' => $this->is_enabled( $key ),
				'list_number' => count( $items ) + 1,
			);
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $limit,
			'total_items' => $total,
			'total_pages' => $pages,
		);
	}

	public function render_page_message( $result ) {
		if ( empty( $result['items'] ) ) {
			return Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'notifications', __( 'Notifications', 'wp-telepilot' ) ) ) . "\n\n" . __( 'No notification options were found.', 'wp-telepilot' );
		}

		$blocks   = array( Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'notifications', __( 'Notifications', 'wp-telepilot' ) ) ) );
		$blocks[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( /* translators: 1: current page number, 2: total number of pages. */ __( 'Page %1$d of %2$d', 'wp-telepilot' ), $result['page'], $result['total_pages'] )
		);

		foreach ( $result['items'] as $item ) {
			$blocks[] = implode(
				"\n",
				array(
					Telepilot_Telegram_Response_Builder::label(
						'notifications',
						sprintf(
							/* translators: 1: plugin list number, 2: display label, 3: enabled or disabled state. */ __( '[%1$d] %2$s [%3$s]', 'wp-telepilot' ),
							isset( $item['list_number'] ) ? (int) $item['list_number'] : 0,
							Telepilot_Telegram_Response_Builder::escape( $item['label'] ),
							Telepilot_Telegram_Response_Builder::escape( $item['enabled'] ? __( 'enabled', 'wp-telepilot' ) : __( 'disabled', 'wp-telepilot' ) )
						)
					),
					sprintf(
						/* translators: %s: notification setting name. */ __( 'Key: [%s]', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::escape( $item['key'] )
					),
				)
			);
		}

		$blocks[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: toggle only the alerts you actually want sent to Telegram.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::join_blocks( $blocks );
	}

	public function render_help_message() {
		$entries = array(
			Telepilot_Telegram_Response_Builder::code( '/notifications list' ) . ' ' . __( 'Show notification options', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/notifications enable new_comment' ) . ' ' . __( 'Enable a notification type', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/notifications disable plugin_updates' ) . ' ' . __( 'Disable a notification type', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::code( '/notifications toggle failed_login' ) . ' ' . __( 'Toggle a notification type', 'wp-telepilot' ),
		);

		return Telepilot_Telegram_Response_Builder::join_blocks(
			array_merge(
				array(
					Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( 'notifications', __( 'Notification Commands', 'wp-telepilot' ) ) ),
				),
				$entries
			)
		);
	}

	public function update_option_state( $key, $enabled ) {
		return Telepilot_Domain_Abilities::run( 'notifications', 'update_option_state', get_defined_vars(), function () use ( $key, $enabled ) { return $this->operation_update_option_state( $key, $enabled ); } );
	}

	private function operation_update_option_state( $key, $enabled ) {
		$key    = $this->normalize_key( $key );
		$labels = Telepilot_Notification_Service::option_labels();

		if ( '' === $key || ! isset( $labels[ $key ] ) ) {
			return new WP_Error( 'telepilot_notification_invalid', __( 'That notification key is not supported.', 'wp-telepilot' ) );
		}

		$settings = get_option( 'telepilot_settings', array() );
		$current  = isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] )
			? array_values( array_unique( array_map( 'sanitize_text_field', $settings['default_notifications'] ) ) )
			: array();
		$before   = in_array( $key, $current, true );

		if ( $enabled && ! $before ) {
			$current[] = $key;
		}

		if ( ! $enabled ) {
			$current = array_values( array_diff( $current, array( $key ) ) );
		}

		$settings['default_notifications'] = array_values( array_unique( $current ) );
		update_option( 'telepilot_settings', $settings, false );

		return array(
			'key'          => $key,
			'label'        => (string) $labels[ $key ],
			'before_state' => array(
				'enabled' => $before,
			),
			'after_state'  => array(
				'enabled' => in_array( $key, $settings['default_notifications'], true ),
			),
			'label_text'   => $enabled ? __( 'enabled', 'wp-telepilot' ) : __( 'disabled', 'wp-telepilot' ),
		);
	}

	public function build_list_keyboard( $result ) {
		$rows = array();

		foreach ( $result['items'] as $item ) {
			$list_number = isset( $item['list_number'] ) ? (int) $item['list_number'] : 0;
			$rows[]      = array(
				array(
					'text'          => sprintf(
						Telepilot_Telegram_Response_Builder::label(
							$item['enabled'] ? 'disable' : 'enable',
							sprintf( $item['enabled'] ? /* translators: %d: plugin list number. */ __( 'Disable [%d]', 'wp-telepilot' ) : /* translators: %d: plugin list number. */ __( 'Enable [%d]', 'wp-telepilot' ), $list_number )
						)
					),
					'callback_data' => '/notifications ' . ( $item['enabled'] ? 'disable ' : 'enable ' ) . $item['key'],
				),
			);
		}

		$pagination = $this->build_pagination_row( $result['page'], $result['total_pages'] );
		if ( ! empty( $pagination ) ) {
			$rows[] = $pagination;
		}

		return Telepilot_Telegram_Response_Builder::append_rows(
			Telepilot_Telegram_Response_Builder::keyboard( $rows ),
			$this->navigation_rows()
		);
	}

	private function is_enabled( $key ) {
		$settings = get_option( 'telepilot_settings', array() );
		$enabled  = isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] )
			? $settings['default_notifications']
			: array();

		return in_array( $key, $enabled, true );
	}

	private function normalize_key( $key ) {
		return sanitize_key( (string) $key );
	}

	private function build_pagination_row( $page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return array();
		}

		$buttons = array();

		if ( $page > 1 ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'prev', __( 'Prev', 'wp-telepilot' ) ),
				'callback_data' => '/notifications list page:' . ( $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => Telepilot_Telegram_Response_Builder::label( 'next', __( 'Next', 'wp-telepilot' ) ),
				'callback_data' => '/notifications list page:' . ( $page + 1 ),
			);
		}

		return $buttons;
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'settings', __( 'Settings', 'wp-telepilot' ) ),
					'callback_data' => '/settings',
				),
				array(
					'text'          => Telepilot_Telegram_Response_Builder::label( 'menu', __( 'Menu', 'wp-telepilot' ) ),
					'callback_data' => '/menu',
				),
			),
		);
	}
}
