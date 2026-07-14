<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Site_Settings_Command_Service {
	public function get_summary() {
		$settings = get_option( 'telepilot_settings', array() );

		return array(
			'transport_mode'      => isset( $settings['transport_mode'] ) ? (string) $settings['transport_mode'] : 'webhook',
			'linking_enabled'     => ! empty( $settings['linking_enabled'] ),
			'notifications_count' => isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] ) ? count( $settings['default_notifications'] ) : 0,
			'allowed_chat_count'  => $this->count_allowed_chats( isset( $settings['allowed_chat_ids'] ) ? (string) $settings['allowed_chat_ids'] : '' ),
			'blogname'            => (string) get_option( 'blogname', get_bloginfo( 'name' ) ),
			'blogdescription'     => (string) get_option( 'blogdescription', get_bloginfo( 'description' ) ),
			'admin_email'         => (string) get_option( 'admin_email', '' ),
			'timezone_string'     => wp_timezone_string(),
			'date_format'         => (string) get_option( 'date_format', 'F j, Y' ),
			'time_format'         => (string) get_option( 'time_format', 'g:i a' ),
			'settings_url'        => admin_url( 'admin.php?page=telepilot' ),
		);
	}

	public function render_summary_message( $summary ) {
		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Settings', 'telepilot' ) );
		$lines[] = '';
		$lines[] = sprintf( __( 'Admin: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::link( __( 'Open settings', 'telepilot' ), $summary['settings_url'] ) );
		$lines[] = sprintf( __( 'Transport: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( ucfirst( (string) $summary['transport_mode'] ) ) );
		$lines[] = sprintf( __( 'Linking: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['linking_enabled'] ? __( 'Enabled', 'telepilot' ) : __( 'Disabled', 'telepilot' ) ) );
		$lines[] = sprintf( __( 'Notification types enabled: %d', 'telepilot' ), (int) $summary['notifications_count'] );
		$lines[] = sprintf( __( 'Allowed chats configured: %d', 'telepilot' ), (int) $summary['allowed_chat_count'] );
		$lines[] = '';
		$lines[] = sprintf( __( 'Site title: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['blogname'] ) );
		$lines[] = sprintf( __( 'Tagline: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['blogdescription'] ) );
		$lines[] = sprintf( __( 'Admin email: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['admin_email'] ) );
		$lines[] = sprintf( __( 'Timezone: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['timezone_string'] ? $summary['timezone_string'] : __( 'UTC', 'telepilot' ) ) );
		$lines[] = sprintf( __( 'Date format: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::code( $summary['date_format'] ) );
		$lines[] = sprintf( __( 'Time format: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::code( $summary['time_format'] ) );

		return implode( "\n", $lines );
	}

	public function render_help_message() {
		$lines   = array();
		$lines[] = Telepilot_Telegram_Response_Builder::bold( __( 'Settings Commands', 'telepilot' ) );
		$lines[] = '';
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings' ) . ' ' . __( 'Show the current WP Telepilot and safe site settings summary', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings title My Site' ) . ' ' . __( 'Update the site title', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings tagline Telegram-first operations' ) . ' ' . __( 'Update the site tagline', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings admin-email admin@example.com' ) . ' ' . __( 'Update the admin email', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings timezone Pacific/Port_Moresby' ) . ' ' . __( 'Update the timezone string', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings date-format F j, Y' ) . ' ' . __( 'Update the WordPress date format', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/settings time-format g:i a' ) . ' ' . __( 'Update the WordPress time format', 'telepilot' );
		$lines[] = Telepilot_Telegram_Response_Builder::code( '/notifications list' ) . ' ' . __( 'Manage Telegram notification types', 'telepilot' );

		return implode( "\n", $lines );
	}

	public function build_keyboard() {
		return Telepilot_Telegram_Response_Builder::keyboard(
			array(
				array(
					array(
						'text'          => __( 'Notifications', 'telepilot' ),
						'callback_data' => '/notifications list',
					),
					array(
						'text'          => __( 'Site', 'telepilot' ),
						'callback_data' => '/site',
					),
				),
				array(
					array(
						'text' => __( 'Open wp-admin', 'telepilot' ),
						'url'  => admin_url( 'admin.php?page=telepilot' ),
					),
				),
			)
		);
	}

	public function update_core_setting( $field, $value ) {
		$field = sanitize_key( (string) $field );
		$value = (string) $value;

		switch ( $field ) {
			case 'title':
				return $this->update_option_value( 'blogname', sanitize_text_field( $value ), __( 'site title updated', 'telepilot' ) );

			case 'tagline':
				return $this->update_option_value( 'blogdescription', sanitize_text_field( $value ), __( 'site tagline updated', 'telepilot' ) );

			case 'admin-email':
				$email = sanitize_email( $value );
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'telepilot_settings_invalid_email', __( 'That admin email address is invalid.', 'telepilot' ) );
				}

				return $this->update_option_value( 'admin_email', $email, __( 'admin email updated', 'telepilot' ) );

			case 'timezone':
				$timezone = trim( $value );
				if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
					return new WP_Error( 'telepilot_settings_invalid_timezone', __( 'That timezone string is invalid.', 'telepilot' ) );
				}

				return $this->update_option_value( 'timezone_string', $timezone, __( 'timezone updated', 'telepilot' ) );

			case 'date-format':
				$format = sanitize_text_field( $value );
				if ( '' === $format ) {
					return new WP_Error( 'telepilot_settings_invalid_date_format', __( 'The date format cannot be empty.', 'telepilot' ) );
				}

				return $this->update_option_value( 'date_format', $format, __( 'date format updated', 'telepilot' ) );

			case 'time-format':
				$format = sanitize_text_field( $value );
				if ( '' === $format ) {
					return new WP_Error( 'telepilot_settings_invalid_time_format', __( 'The time format cannot be empty.', 'telepilot' ) );
				}

				return $this->update_option_value( 'time_format', $format, __( 'time format updated', 'telepilot' ) );
		}

		return new WP_Error( 'telepilot_settings_unsupported_field', __( 'That settings field is not supported.', 'telepilot' ) );
	}

	private function update_option_value( $option_name, $new_value, $label ) {
		$before = get_option( $option_name, '' );
		update_option( $option_name, $new_value, false );
		$after = get_option( $option_name, '' );

		return array(
			'field'        => $option_name,
			'before_state' => array(
				'value' => is_scalar( $before ) ? (string) $before : '',
			),
			'after_state'  => array(
				'value' => is_scalar( $after ) ? (string) $after : '',
			),
			'label'        => $label,
		);
	}

	private function count_allowed_chats( $allowed_chat_ids ) {
		$items = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", (string) $allowed_chat_ids ) ) ) );

		return count( $items );
	}
}
