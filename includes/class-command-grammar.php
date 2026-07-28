<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Command_Grammar {
	public function extract_text_from_update( $update ) {
		if ( ! empty( $update['message']['text'] ) ) {
			return trim( wp_strip_all_tags( (string) $update['message']['text'] ) );
		}

		if ( ! empty( $update['callback_query']['data'] ) ) {
			return trim( wp_strip_all_tags( (string) $update['callback_query']['data'] ) );
		}

		return '';
	}

	public function parse_update( $update ) {
		return $this->parse_text( $this->extract_text_from_update( $update ) );
	}

	public function parse_text( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( '' === $text ) {
			return array(
				'raw'            => '',
				'name'           => '',
				'args'           => array(),
				'tokens'         => array(),
				'ends_in_space'  => false,
				'open_quote'     => '',
				'is_callback'    => false,
			);
		}

		if ( 0 === strpos( $text, 'tp:' ) ) {
			$parts = explode( ':', $text );
			$name  = isset( $parts[1] ) ? 'tp:' . $parts[1] : '';

			return array(
				'raw'            => $text,
				'name'           => $name,
				'args'           => array_slice( $parts, 2 ),
				'tokens'         => $parts,
				'ends_in_space'  => false,
				'open_quote'     => '',
				'is_callback'    => true,
			);
		}

		$analysis = $this->analyze_text( $text );
		$tokens   = $analysis['tokens'];
		$name     = isset( $tokens[0] ) ? strtolower( (string) $tokens[0] ) : '';

		return array(
			'raw'            => $text,
			'name'           => $name,
			'args'           => array_slice( $tokens, 1 ),
			'tokens'         => $tokens,
			'ends_in_space'  => ! empty( $analysis['ends_in_space'] ),
			'open_quote'     => ! empty( $analysis['open_quote'] ) ? (string) $analysis['open_quote'] : '',
			'is_callback'    => false,
		);
	}

	public function analyze_text( $text ) {
		$text            = (string) $text;
		$tokens          = array();
		$current         = '';
		$open_quote      = '';
		$length          = strlen( $text );
		$ends_in_space   = false;

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $text[ $index ];

			if ( '' !== $open_quote ) {
				if ( '\\' === $char && $index + 1 < $length ) {
					$next = $text[ $index + 1 ];

					if ( $next === $open_quote || '\\' === $next ) {
						$current .= $next;
						$index++;
						continue;
					}
				}

				if ( $char === $open_quote ) {
					$open_quote = '';
					continue;
				}

				$current .= $char;
				continue;
			}

			if ( '"' === $char || '\'' === $char ) {
				$open_quote = $char;
				continue;
			}

			if ( preg_match( '/\s/u', $char ) ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}

				$ends_in_space = true;
				continue;
			}

			$current       .= $char;
			$ends_in_space = false;
		}

		if ( '' !== $current ) {
			$tokens[] = $current;
		}

		return array(
			'tokens'        => $tokens,
			'ends_in_space' => $ends_in_space,
			'open_quote'    => $open_quote,
		);
	}
}
