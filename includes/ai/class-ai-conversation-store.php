<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Conversation_Store {
	const STORE_PREFIX = 'telepilot_ai_context_';

	public function get_context( $session_id ) {
		$key = $this->build_key( $session_id );

		if ( '' === $key ) {
			return $this->default_context();
		}

		$context = get_transient( $key );
		if ( ! is_array( $context ) ) {
			return $this->default_context();
		}
		if ( (string) ( $context['data_generation'] ?? '' ) !== self::data_generation() ) {
			$this->clear( $session_id );
			return $this->default_context();
		}

		return wp_parse_args( $context, $this->default_context() );
	}

	public function clear( $session_id ) {
		$key = $this->build_key( $session_id );

		if ( '' !== $key ) {
			delete_transient( $key );
		}
	}

	public function add_message( $session_id, $role, $content ) {
		$context = $this->get_context( $session_id );
		$role    = in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'assistant';
		$content = mb_substr( trim( (string) Telepilot_AI_Data_Policy::clean( $content ) ), 0, 4000 );

		if ( '' === $content ) {
			return $context;
		}

		$context['messages'][] = array(
			'role'        => $role,
			'content'     => $content,
			'recorded_at' => time(),
		);

		if ( count( $context['messages'] ) > 8 ) {
			$context['messages'] = array_slice( $context['messages'], -8 );
		}

		$this->persist( $session_id, $context );

		return $context;
	}

	public function remember_result( $session_id, $intent, $entities, $summary ) {
		$context                 = $this->get_context( $session_id );
		$context['last_intent']  = sanitize_key( (string) $intent );
		if ( ! empty( $entities ) && is_array( $entities ) ) { $context['entities'] = Telepilot_AI_Data_Policy::clean( array_slice( array_values( $entities ), 0, 10 ) ); }
		$context['last_summary'] = mb_substr( (string) Telepilot_AI_Data_Policy::clean( $summary ), 0, 1000 );
		$context['updated_at']   = time();

		$this->persist( $session_id, $context );

		return $context;
	}

	public function build_context_prompt( $session_id ) {
		$context = $this->get_context( $session_id );
		$lines   = array();

		if ( ! empty( $context['last_intent'] ) ) {
			$lines[] = 'Last structured intent: ' . $context['last_intent'];
		}

		if ( ! empty( $context['last_summary'] ) ) {
			$lines[] = 'Last result summary: ' . $context['last_summary'];
		}

		if ( ! empty( $context['entities'] ) ) {
			$lines[] = 'Recent references:';

			foreach ( $context['entities'] as $entity ) {
				$parts = array();
				if ( ! empty( $entity['position'] ) ) {
					$parts[] = '#' . (int) $entity['position'];
				}
				if ( ! empty( $entity['type'] ) ) {
					$parts[] = sanitize_key( (string) $entity['type'] );
				}
				if ( isset( $entity['id'] ) ) {
					$parts[] = '[' . $entity['id'] . ']';
				}
				if ( ! empty( $entity['title'] ) ) {
					$parts[] = (string) $entity['title'];
				}
				if ( ! empty( $entity['identifier'] ) ) {
					$parts[] = (string) $entity['identifier'];
				}

				$lines[] = '- ' . implode( ' ', $parts );
			}
		}

		return implode( "\n", $lines );
	}

	public function get_message_history( $session_id ) {
		$context = $this->get_context( $session_id );

		return ! empty( $context['messages'] ) && is_array( $context['messages'] ) ? $context['messages'] : array();
	}

	public function resolve_reference( $session_id, $reference_number, $types = array() ) {
		$context          = $this->get_context( $session_id );
		$reference_number = absint( $reference_number );
		$types            = array_filter( array_map( 'sanitize_key', (array) $types ) );

		if ( ! $reference_number || empty( $context['entities'] ) ) {
			return null;
		}

		foreach ( $context['entities'] as $entity ) {
			if ( empty( $entity['position'] ) || (int) $entity['position'] !== $reference_number ) {
				continue;
			}

			if ( ! empty( $types ) && ( empty( $entity['type'] ) || ! in_array( sanitize_key( (string) $entity['type'] ), $types, true ) ) ) {
				continue;
			}

			return $entity;
		}

		return null;
	}

	private function persist( $session_id, $context ) {
		$context['data_generation'] = self::data_generation();
		$key = $this->build_key( $session_id );

		if ( '' === $key ) {
			return;
		}

		set_transient( $key, $context, Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS );
	}

	private function build_key( $session_id ) {
		$session_id = trim( (string) $session_id );

		return '' === $session_id ? '' : self::STORE_PREFIX . md5( $session_id );
	}

	public static function data_generation() {
		return (string) get_option( 'telepilot_posts_cache_version', '' );
	}

	private function default_context() {
		return array(
			'messages'     => array(),
			'last_intent'  => '',
			'entities'     => array(),
			'last_summary' => '',
			'updated_at'   => 0,
		);
	}
}
