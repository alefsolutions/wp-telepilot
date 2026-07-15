<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Telegram_Response_Builder {
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

		foreach ( $rows as $row ) {
			$buttons = array();

			foreach ( $row as $button ) {
				if ( empty( $button['text'] ) ) {
					continue;
				}

				if ( ! empty( $button['url'] ) ) {
					$buttons[] = array(
						'text' => (string) $button['text'],
						'url'  => esc_url_raw( (string) $button['url'] ),
					);
					continue;
				}

				if ( empty( $button['callback_data'] ) ) {
					continue;
				}

				$buttons[] = array(
					'text'          => (string) $button['text'],
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

	public static function confirmation_keyboard( $confirm_text, $confirm_callback, $cancel_callback = '/menu', $cancel_text = '' ) {
		if ( '' === $cancel_text ) {
			$cancel_text = __( 'Cancel', 'telepilot' );
		}

		return self::keyboard(
			array(
				array(
					array(
						'text'          => (string) $confirm_text,
						'callback_data' => (string) $confirm_callback,
					),
					array(
						'text'          => (string) $cancel_text,
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
}
