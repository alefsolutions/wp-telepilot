<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Telegram_Response_Builder {
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

	public static function keyboard( $rows ) {
		$inline_keyboard = array();

		foreach ( $rows as $row ) {
			$buttons = array();

			foreach ( $row as $button ) {
				if ( empty( $button['text'] ) || empty( $button['callback_data'] ) ) {
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
}
