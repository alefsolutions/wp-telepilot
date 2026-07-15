<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Site_Settings_Command_Service {
	public function get_summary() {
		$settings = get_option( 'telepilot_settings', array() );

		return array(
			'transport_mode'        => isset( $settings['transport_mode'] ) ? (string) $settings['transport_mode'] : 'webhook',
			'linking_enabled'       => ! empty( $settings['linking_enabled'] ),
			'cleanup_on_uninstall'  => ! empty( $settings['cleanup_on_uninstall'] ),
			'notifications_count'   => isset( $settings['default_notifications'] ) && is_array( $settings['default_notifications'] ) ? count( $settings['default_notifications'] ) : 0,
			'allowed_chat_count'    => $this->count_allowed_chats( isset( $settings['allowed_chat_ids'] ) ? (string) $settings['allowed_chat_ids'] : '' ),
			'log_retention_days'    => isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30,
			'rate_limit_per_minute' => isset( $settings['rate_limit_per_minute'] ) ? (int) $settings['rate_limit_per_minute'] : 20,
			'stale_update_window' => isset( $settings['stale_update_window'] ) ? (int) $settings['stale_update_window'] : Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW,
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
		$lines[] = sprintf( __( 'Uninstall cleanup: %s', 'telepilot' ), Telepilot_Telegram_Response_Builder::escape( $summary['cleanup_on_uninstall'] ? __( 'Enabled', 'telepilot' ) : __( 'Preserve data', 'telepilot' ) ) );
		$lines[] = sprintf( __( 'Notification types enabled: %d', 'telepilot' ), (int) $summary['notifications_count'] );
		$lines[] = sprintf( __( 'Allowed chats configured: %d', 'telepilot' ), (int) $summary['allowed_chat_count'] );
		$lines[] = sprintf( __( 'Log retention: %d days', 'telepilot' ), (int) $summary['log_retention_days'] );
		$lines[] = sprintf( __( 'Rate limit: %d commands/minute', 'telepilot' ), (int) $summary['rate_limit_per_minute'] );
		$lines[] = sprintf( __( 'Stale update window: %d seconds', 'telepilot' ), (int) $summary['stale_update_window'] );
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
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( __( 'Settings Commands', 'telepilot' ) ),
				Telepilot_Telegram_Response_Builder::code( '/settings' ) . ' ' . __( 'Show the current WP Telepilot and safe site settings summary', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings title My Site' ) . ' ' . __( 'Update the site title', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings tagline Telegram-first operations' ) . ' ' . __( 'Update the site tagline', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings admin-email admin@example.com' ) . ' ' . __( 'Update the admin email', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings timezone Pacific/Port_Moresby' ) . ' ' . __( 'Update the timezone string', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings timezone help' ) . ' ' . __( 'Show timezone examples and validation tips', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings date-format F j, Y' ) . ' ' . __( 'Update the WordPress date format', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings date-format help' ) . ' ' . __( 'Show date format examples', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings time-format g:i a' ) . ' ' . __( 'Update the WordPress time format', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings time-format help' ) . ' ' . __( 'Show time format examples', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings retention 45' ) . ' ' . __( 'Update audit log retention days', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings rate-limit 30' ) . ' ' . __( 'Update the per-minute command limit', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings stale-window 180' ) . ' ' . __( 'Update the stale update rejection window', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings linking off' ) . ' ' . __( 'Enable or disable new Telegram account linking', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/settings uninstall-cleanup on' ) . ' ' . __( 'Choose whether uninstall removes WP Telepilot data', 'telepilot' ),
				Telepilot_Telegram_Response_Builder::code( '/notifications list' ) . ' ' . __( 'Manage Telegram notification types', 'telepilot' ),
			)
		);
	}

	public function render_field_help( $field ) {
		$field = sanitize_key( (string) $field );

		switch ( $field ) {
			case 'timezone':
				return Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Timezone Help', 'telepilot' ) ),
						__( 'Use a valid PHP timezone identifier from the WordPress timezone list.', 'telepilot' ),
						Telepilot_Telegram_Response_Builder::code( '/settings timezone Pacific/Port_Moresby' ),
						Telepilot_Telegram_Response_Builder::code( '/settings timezone Australia/Sydney' ),
					)
				);

			case 'date-format':
				return Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Date Format Help', 'telepilot' ) ),
						__( 'Use a standard WordPress/PHP date format string.', 'telepilot' ),
						Telepilot_Telegram_Response_Builder::code( '/settings date-format F j, Y' ),
						Telepilot_Telegram_Response_Builder::code( '/settings date-format Y-m-d' ),
					)
				);

			case 'time-format':
				return Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Time Format Help', 'telepilot' ) ),
						__( 'Use a standard WordPress/PHP time format string.', 'telepilot' ),
						Telepilot_Telegram_Response_Builder::code( '/settings time-format g:i a' ),
						Telepilot_Telegram_Response_Builder::code( '/settings time-format H:i' ),
					)
				);
		}

		return '';
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

				return $this->update_option_value( 'time_format', $format );

			case 'retention':
				$days = absint( $value );
				if ( $days < 1 ) {
					return new WP_Error( 'telepilot_settings_invalid_retention', __( 'Log retention must be at least 1 day.', 'telepilot' ) );
				}

				return $this->update_plugin_setting( 'log_retention_days', $days );

			case 'rate-limit':
				$rate = absint( $value );
				if ( $rate < 1 ) {
					return new WP_Error( 'telepilot_settings_invalid_rate_limit', __( 'Rate limit must be at least 1 command per minute.', 'telepilot' ) );
				}

				return $this->update_plugin_setting( 'rate_limit_per_minute', $rate );

			case 'stale-window':
				$seconds = absint( $value );
				if ( $seconds < 30 ) {
					return new WP_Error( 'telepilot_settings_invalid_stale_window', __( 'The stale update window must be at least 30 seconds.', 'telepilot' ) );
				}

				return $this->update_plugin_setting( 'stale_update_window', $seconds );

			case 'linking':
				$enabled = $this->normalize_boolean_string( $value );
				if ( null === $enabled ) {
					return new WP_Error( 'telepilot_settings_invalid_linking', __( 'Use on/off, enable/disable, yes/no, or 1/0 for linking.', 'telepilot' ) );
				}

				return $this->update_plugin_setting( 'linking_enabled', $enabled ? 1 : 0, $enabled ? __( 'enabled', 'telepilot' ) : __( 'disabled', 'telepilot' ) );

			case 'uninstall-cleanup':
				$enabled = $this->normalize_boolean_string( $value );
				if ( null === $enabled ) {
					return new WP_Error( 'telepilot_settings_invalid_uninstall_cleanup', __( 'Use on/off, enable/disable, yes/no, or 1/0 for uninstall cleanup.', 'telepilot' ) );
				}

				return $this->update_plugin_setting( 'cleanup_on_uninstall', $enabled ? 1 : 0, $enabled ? __( 'enabled', 'telepilot' ) : __( 'disabled', 'telepilot' ) );
		}

		return new WP_Error( 'telepilot_settings_unsupported_field', __( 'That settings field is not supported.', 'telepilot' ) );
	}

	private function update_option_value( $option_name, $new_value, $unused_label = null ) {
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
			'label_text'   => __( 'updated', 'telepilot' ),
		);
	}

	private function update_plugin_setting( $setting_key, $new_value, $label_text = null ) {
		$settings = get_option( 'telepilot_settings', array() );
		$before   = isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : null;

		$settings[ $setting_key ] = $new_value;
		update_option( 'telepilot_settings', $settings, false );

		$updated_settings = get_option( 'telepilot_settings', array() );
		$after            = isset( $updated_settings[ $setting_key ] ) ? $updated_settings[ $setting_key ] : null;

		return array(
			'field'        => $setting_key,
			'before_state' => array(
				'value' => is_scalar( $before ) ? (string) $before : wp_json_encode( $before ),
			),
			'after_state'  => array(
				'value' => is_scalar( $after ) ? (string) $after : wp_json_encode( $after ),
			),
			'label_text'   => $label_text ? $label_text : __( 'updated', 'telepilot' ),
		);
	}

	private function normalize_boolean_string( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( '1', 'on', 'yes', 'true', 'enable', 'enabled' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( '0', 'off', 'no', 'false', 'disable', 'disabled' ), true ) ) {
			return false;
		}

		return null;
	}

	private function count_allowed_chats( $allowed_chat_ids ) {
		$items = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", (string) $allowed_chat_ids ) ) ) );

		return count( $items );
	}
}
