<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Telegram_Response_Builder {
	public static function icon( $key ) {
		$icons = array(
			'confirm'       => '✅',
			'cancel'        => '❌',
			'menu'          => '🧭',
			'site'          => '🌐',
			'refresh'       => '🔄',
			'posts'         => '📝',
			'pages'         => '📄',
			'comments'      => '💬',
			'media'         => '🖼️',
			'users'         => '👤',
			'plugins'       => '🔌',
			'notifications' => '🔔',
			'settings'      => '⚙️',
			'categories'    => '🗂️',
			'category'      => '🗂️',
			'tags'          => '🏷️',
			'tag'           => '🏷️',
			'details'       => 'ℹ️',
			'open'          => '🔗',
			'preview'       => '👁️',
			'edit'          => '✏️',
			'publish'       => '📤',
			'draft'         => '📥',
			'trash'         => '🗑️',
			'delete'        => '🗑️',
			'restore'       => '♻️',
			'enable'        => '✅',
			'disable'       => '⛔',
			'update'        => '⬆️',
			'email'         => '✉️',
			'reset'         => '🔑',
			'welcome'       => '👋',
			'prev'          => '◀️',
			'next'          => '▶️',
			'stats'         => '📊',
			'link'          => '🔗',
		);

		$key = sanitize_key( (string) $key );

		return isset( $icons[ $key ] ) ? $icons[ $key ] : '';
	}

	public static function label( $icon_key, $text ) {
		$icon = self::icon( $icon_key );
		$text = (string) $text;

		return '' !== $icon ? $icon . ' ' . $text : $text;
	}

	public static function button_label( $icon_key, $text ) {
		return self::normalize_button_text( self::label( $icon_key, $text ) );
	}

	public static function success( $message, $extra = array() ) {
		return wp_parse_args(
			$extra,
			array(
				'ok'      => true,
				'message' => $message,
			)
		);
	}

	public static function error( $message, $extra = array() ) {
		return wp_parse_args(
			$extra,
			array(
				'ok'      => false,
				'message' => $message,
			)
		);
	}

	public static function success_html( $message, $extra = array() ) {
		return self::success(
			$message,
			wp_parse_args(
				$extra,
				array(
					'parse_mode' => 'HTML',
				)
			)
		);
	}

	public static function error_html( $message, $extra = array() ) {
		return self::error(
			$message,
			wp_parse_args(
				$extra,
				array(
					'parse_mode' => 'HTML',
				)
			)
		);
	}

	public static function keyboard( $rows ) {
		$inline_keyboard = array();
		if ( ! is_array( $rows ) ) { return array(); }

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$buttons = array();

			foreach ( $row as $button ) {
				if ( ! is_array( $button ) ) { continue; }
				if ( empty( $button['text'] ) ) {
					continue;
				}

				$text = self::normalize_button_text( (string) $button['text'] );

				if ( '' === $text ) {
					continue;
				}

				if ( ! empty( $button['url'] ) ) {
					$buttons[] = array(
						'text' => $text,
						'url'  => esc_url_raw( (string) $button['url'] ),
					);
					continue;
				}

				if ( ! isset( $button['callback_data'] ) || '' === $button['callback_data'] ) {
					continue;
				}
				if ( ! is_string( $button['callback_data'] ) || strlen( $button['callback_data'] ) > 64 ) {
					Telepilot_Audit_Log_Repository::log( array( 'action_name' => 'telegram_callback_omitted', 'resource_type' => 'telegram_keyboard', 'was_successful' => 0, 'failure_reason' => 'Callback exceeds Telegram byte limit or is not a string.' ) );
					continue;
				}

				$buttons[] = array(
					'text'          => $text,
					'callback_data' => (string) $button['callback_data'],
				);
			}

			if ( ! empty( $buttons ) ) {
				$inline_keyboard[] = $buttons;
			}
		}

		if ( empty( $inline_keyboard ) ) {
			return array();
		}

		return array(
			'inline_keyboard' => $inline_keyboard,
		);
	}

	public static function transport_keyboard( $markup ) {
		if ( ! is_array( $markup ) || ! isset( $markup['inline_keyboard'] ) || ! is_array( $markup['inline_keyboard'] ) ) { return $markup; }
		$keyboard = self::keyboard( $markup['inline_keyboard'] );
		return $keyboard ?: self::empty_keyboard();
	}

	public static function append_rows( $keyboard, $rows ) {
		$existing = array();

		if ( ! empty( $keyboard['inline_keyboard'] ) && is_array( $keyboard['inline_keyboard'] ) ) {
			$existing = $keyboard['inline_keyboard'];
		}

		$extra = self::keyboard( $rows );

		if ( empty( $extra['inline_keyboard'] ) ) {
			return $keyboard;
		}

		return array(
			'inline_keyboard' => array_merge( $existing, $extra['inline_keyboard'] ),
		);
	}

	public static function empty_keyboard() {
		return array(
			'inline_keyboard' => array(),
		);
	}

	public static function confirmation_keyboard( $confirm_text, $confirm_callback, $cancel_callback = '/menu', $cancel_text = '' ) {
		if ( '' === $cancel_text ) {
			$cancel_text = __( 'Cancel', 'wp-telepilot' );
		}

		return self::keyboard(
			array(
				array(
					array(
						'text'          => self::button_label( 'confirm', $confirm_text ),
						'callback_data' => (string) $confirm_callback,
					),
					array(
						'text'          => self::button_label( 'cancel', $cancel_text ),
						'callback_data' => (string) $cancel_callback,
					),
				),
			)
		);
	}

	public static function join_blocks( $blocks ) {
		$blocks = array_values(
			array_filter(
				(array) $blocks,
				function( $block ) {
					return null !== $block && '' !== $block;
				}
			)
		);

		return implode( "\n\n", $blocks );
	}

	public static function escape( $text ) {
		return esc_html( (string) $text );
	}

	public static function bold( $text ) {
		return '<b>' . self::escape( $text ) . '</b>';
	}

	public static function italic( $text ) {
		return '<i>' . self::escape( $text ) . '</i>';
	}

	public static function code( $text ) {
		return '<code>' . self::escape( $text ) . '</code>';
	}

	public static function link( $text, $url ) {
		return '<a href="' . esc_url( (string) $url ) . '">' . self::escape( $text ) . '</a>';
	}

	private static function normalize_button_text( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

		if ( '' === $text ) {
			return '';
		}

		$text = preg_replace( '/\bOpen Editor\b/u', 'Edit', $text );
		$text = preg_replace( '/\bOpen wp-admin\b/u', 'Admin', $text );
		$text = preg_replace( '/\bSite Overview\b/u', 'Overview', $text );
		$text = preg_replace( '/\bTelepilot Settings\b/u', 'Telepilot', $text );
		$text = preg_replace( '/\bEmail Reset Password\b/u', 'Email Reset', $text );
		$text = preg_replace( '/\bReset Password\b/u', 'Reset', $text );
		$text = preg_replace( '/^(✅)\s+Confirm\s+/u', '$1 ', $text );

		return trim( $text );
	}
}
