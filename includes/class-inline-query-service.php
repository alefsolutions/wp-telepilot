<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Inline_Query_Service {
	private $permission_service;
	private $grammar;

	public function __construct( Telepilot_Permission_Service $permission_service ) {
		$this->permission_service = $permission_service;
		$this->grammar            = new Telepilot_Command_Grammar();
	}

	public function build_response_payload( $update, $identity ) {
		$inline_query = ! empty( $update['inline_query'] ) && is_array( $update['inline_query'] ) ? $update['inline_query'] : array();
		$query        = isset( $inline_query['query'] ) ? (string) $inline_query['query'] : '';
		$chat_type    = isset( $inline_query['chat_type'] ) ? sanitize_key( (string) $inline_query['chat_type'] ) : '';
		$parsed       = $this->grammar->parse_text( $query );

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			return array(
				'results'     => array(),
				'cache_time'  => 0,
				'is_personal' => true,
				'button'      => $this->build_private_chat_button(
					__( 'Open WP Telepilot', 'wp-telepilot' ),
					'telepilot_inline_setup'
				),
				'context'     => 'link_required',
			);
		}

		if ( ! in_array( $chat_type, array( 'private', 'sender' ), true ) ) {
			return array(
				'results'     => array(),
				'cache_time'  => 0,
				'is_personal' => true,
				'button'      => $this->build_private_chat_button(
					__( 'Continue in Private Chat', 'wp-telepilot' ),
					'telepilot_inline_private'
				),
				'context'     => 'private_chat_required',
			);
		}

		$results = array();

		if ( '' === trim( $query ) ) {
			$results[] = $this->build_article_result(
				'inline-root',
				__( 'WP Telepilot Inline Mode', 'wp-telepilot' ),
				__( 'Keep typing a resource like posts, pages, categories, tags, or plugins.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Inline Mode', 'wp-telepilot' ) ),
						__( 'Keep typing a resource such as posts, pages, categories, tags, or plugins to refine the command.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::italic( __( 'Slash commands remain the main private-chat workflow. Inline mode is an assisted discovery layer.', 'wp-telepilot' ) ),
					)
				)
			);
		} elseif ( ! empty( $parsed['name'] ) ) {
			$hint = $this->build_resource_hint_result( $parsed['name'] );

			if ( ! empty( $hint ) ) {
				$results[] = $hint;
			}
		}

		return array(
			'results'     => $results,
			'cache_time'  => empty( $results ) ? 0 : 60,
			'is_personal' => true,
			'next_offset' => '',
			'context'     => 'ready',
		);
	}

	private function build_resource_hint_result( $resource ) {
		$resource = strtolower( (string) $resource );
		$hints    = array(
			'posts'      => __( 'Keep typing an action like list, open, publish, trash, search, or new.', 'wp-telepilot' ),
			'pages'      => __( 'Keep typing an action like list, details, search, open, trash, or new.', 'wp-telepilot' ),
			'categories' => __( 'Keep typing an action like list, details, search, create, or post.', 'wp-telepilot' ),
			'tags'       => __( 'Keep typing an action like list, details, search, or create.', 'wp-telepilot' ),
			'plugins'    => __( 'Keep typing an action like list, details, search, activate, or deactivate.', 'wp-telepilot' ),
			'settings'   => __( 'Keep typing a settings action or switch back to slash commands for direct edits.', 'wp-telepilot' ),
		);

		if ( ! isset( $hints[ $resource ] ) ) {
			return array();
		}

		return $this->build_article_result(
			'inline-hint-' . $resource,
			sprintf( __( '%s Inline Hint', 'wp-telepilot' ), ucfirst( $resource ) ),
			$hints[ $resource ],
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%s Inline Hint', 'wp-telepilot' ), ucfirst( $resource ) ) ),
					$hints[ $resource ],
				)
			)
		);
	}

	private function build_article_result( $id, $title, $description, $message_text ) {
		return array(
			'type'                  => 'article',
			'id'                    => substr( md5( (string) $id ), 0, 32 ),
			'title'                 => sanitize_text_field( (string) $title ),
			'description'           => sanitize_text_field( (string) $description ),
			'input_message_content' => array(
				'message_text' => (string) $message_text,
				'parse_mode'   => 'HTML',
			),
		);
	}

	private function build_private_chat_button( $text, $start_parameter ) {
		return array(
			'text'            => sanitize_text_field( (string) $text ),
			'start_parameter' => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $start_parameter ),
		);
	}
}
