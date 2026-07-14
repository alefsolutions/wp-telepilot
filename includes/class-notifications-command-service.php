<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Notifications_Command_Service {
	const PER_PAGE = 5;

	public function list_page( $page = 1, $limit = self::PER_PAGE ) {
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
			return Telepilot_Telegram_Response_Builder::bold( __( 'Notifications', 'telepilot' ) ) . "\n\n" . __( 'No notification options were found.', 'telepilot' );
		}

		$lines   = array( Telepilot_Telegram_Response_Builder::bold( __( 'Notifications', 'telepilot' ) ) );
		$lines[] = Telepilot_Telegram_Response_Builder::italic(
			sprintf( __( 'Page %1$d of %2$d', 'telepilot' ), $result['page'], $result['total_pages'] )
		);
		$lines[] = '';

		foreach ( $result['items'] as $item ) {
			$lines[] = sprintf(
				__( '[%1$s] %2$s [%3$s]', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $item['key'] ),
				Telepilot_Telegram_Response_Builder::escape( $item['label'] ),
				Telepilot_Telegram_Response_Builder::escape( $item['enabled'] ? __( 'enabled', 'telepilot' ) : __( 'disabled', 'telepilot' ) )
			);
		}

		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Tip: toggle only the alerts you actually want sent to Telegram.', 'telepilot' ) );

		return implode( "\n", $lines );
	}

	public function render_help_message() {
		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'Notification Commands', 'telepilot' ) );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/notifications list' ) . ' ' . __( 'Show notification options', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/notifications enable new_comment' ) . ' ' . __( 'Enable a notification type', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/notifications disable plugin_updates' ) . ' ' . __( 'Disable a notification type', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/notifications toggle failed_login' ) . ' ' . __( 'Toggle a notification type', 'telepilot' );

		return implode( "\n", $lines );
	}

	public function update_option_state( $key, $enabled ) {
		$key    = $this->normalize_key( $key );
		$labels = Telepilot_Notification_Service::option_labels();

		if ( '' === $key || ! isset( $labels[ $key ] ) ) {
			return new WP_Error( 'telepilot_notification_invalid', __( 'That notification key is not supported.', 'telepilot' ) );
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
			'label_text'   => $enabled ? __( 'enabled', 'telepilot' ) : __( 'disabled', 'telepilot' ),
		);
	}

	public function build_list_keyboard( $result ) {
		$rows = array();

		foreach ( $result['items'] as $item ) {
			$rows[] = array(
				array(
					'text'          => sprintf(
						$item['enabled'] ? __( 'Disable [%s]', 'telepilot' ) : __( 'Enable [%s]', 'telepilot' ),
						$item['key']
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
				'text'          => __( 'Prev', 'telepilot' ),
				'callback_data' => '/notifications list page:' . ( $page - 1 ),
			);
		}

		if ( $page < $total_pages ) {
			$buttons[] = array(
				'text'          => __( 'Next', 'telepilot' ),
				'callback_data' => '/notifications list page:' . ( $page + 1 ),
			);
		}

		return $buttons;
	}

	private function navigation_rows() {
		return array(
			array(
				array(
					'text'          => __( 'Settings', 'telepilot' ),
					'callback_data' => '/settings',
				),
				array(
					'text'          => __( 'Menu', 'telepilot' ),
					'callback_data' => '/menu',
				),
			),
		);
	}
}
