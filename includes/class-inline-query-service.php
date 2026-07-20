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
		$resources    = $this->get_available_resources( $identity );

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
			$results[] = $this->build_intro_result();

			foreach ( $resources as $resource_key => $resource ) {
				$results[] = $this->build_resource_result( $resource_key, $resource );
			}
		} else {
			$matching_resources = $this->filter_matching_resources( $resources, $parsed );

			foreach ( $matching_resources as $resource_key => $resource ) {
				$results[] = $this->build_resource_result( $resource_key, $resource );
			}

			$resolved_resource = $this->resolve_resource( $resources, $parsed );
			if ( ! empty( $resolved_resource['key'] ) ) {
				$action_results = $this->build_action_results( $resolved_resource['key'], $resolved_resource['resource'], $parsed );

				foreach ( $action_results as $action_result ) {
					$results[] = $action_result;
				}
			}
		}

		if ( empty( $results ) ) {
			$results[] = $this->build_article_result(
				'inline-no-match',
				__( 'No Inline Suggestions Yet', 'wp-telepilot' ),
				__( 'Keep typing a supported resource or action such as posts, pages, categories, tags, or plugins.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Inline Mode', 'wp-telepilot' ) ),
						__( 'Keep typing a resource such as posts, pages, categories, tags, or plugins to refine the command.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::italic( __( 'Slash commands remain the main private-chat workflow. Inline mode is an assisted discovery layer.', 'wp-telepilot' ) ),
					),
				),
				$this->build_switch_inline_keyboard(
					array(
						array(
							__( 'Try Posts', 'wp-telepilot' ),
							'posts ',
						),
						array(
							__( 'Try Pages', 'wp-telepilot' ),
							'pages ',
						),
					)
				)
			);
		}

		return array(
			'results'     => $results,
			'cache_time'  => empty( $results ) ? 0 : 45,
			'is_personal' => true,
			'next_offset' => '',
			'context'     => $this->determine_context( $parsed, $resources ),
		);
	}

	private function build_intro_result() {
		return $this->build_article_result(
			'inline-root',
			__( 'WP Telepilot Inline Mode', 'wp-telepilot' ),
			__( 'Use inline mode to discover supported resources and valid next actions.', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Inline Mode', 'wp-telepilot' ) ),
					__( 'Type a resource such as posts, pages, categories, tags, plugins, or settings.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Inline mode helps you discover valid next steps. Slash commands remain the main private-chat workflow.', 'wp-telepilot' ) ),
				)
			),
			$this->build_switch_inline_keyboard(
				array(
					array(
						__( 'Posts', 'wp-telepilot' ),
						'posts ',
					),
					array(
						__( 'Pages', 'wp-telepilot' ),
						'pages ',
					),
					array(
						__( 'Categories', 'wp-telepilot' ),
						'categories ',
					),
					array(
						__( 'Plugins', 'wp-telepilot' ),
						'plugins ',
					),
				)
			)
		);
	}

	private function get_available_resources( $identity ) {
		$resources = array();
		$wp_user   = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user'] : null;

		if ( ! $wp_user ) {
			return $resources;
		}

		if ( $this->permission_service->user_can( $wp_user, 'edit_posts' ) ) {
			$resources['posts'] = array(
				'title'       => __( 'Posts', 'wp-telepilot' ),
				'description' => __( 'Content listing, search, creation, and post actions.', 'wp-telepilot' ),
				'icon'        => 'posts',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'edit_pages' ) ) {
			$resources['pages'] = array(
				'title'       => __( 'Pages', 'wp-telepilot' ),
				'description' => __( 'Page listing, search, creation, and page actions.', 'wp-telepilot' ),
				'icon'        => 'pages',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'manage_categories' ) ) {
			$resources['categories'] = array(
				'title'       => __( 'Categories', 'wp-telepilot' ),
				'description' => __( 'Category listing, search, maintenance, and post-start actions.', 'wp-telepilot' ),
				'icon'        => 'categories',
			);
			$resources['tags'] = array(
				'title'       => __( 'Tags', 'wp-telepilot' ),
				'description' => __( 'Tag listing, search, and maintenance actions.', 'wp-telepilot' ),
				'icon'        => 'tags',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'activate_plugins' ) || $this->permission_service->user_can( $wp_user, 'update_plugins' ) || $this->permission_service->user_can( $wp_user, 'delete_plugins' ) ) {
			$resources['plugins'] = array(
				'title'       => __( 'Plugins', 'wp-telepilot' ),
				'description' => __( 'Plugin listing, updates, and plugin management actions.', 'wp-telepilot' ),
				'icon'        => 'plugins',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'manage_options' ) ) {
			$resources['settings'] = array(
				'title'       => __( 'Settings', 'wp-telepilot' ),
				'description' => __( 'Safe WP Telepilot and site configuration commands.', 'wp-telepilot' ),
				'icon'        => 'settings',
			);
		}

		return $resources;
	}

	private function filter_matching_resources( $resources, $parsed ) {
		$token = ! empty( $parsed['name'] ) ? strtolower( (string) $parsed['name'] ) : '';

		if ( '' === $token ) {
			return array();
		}

		$matches = array();

		foreach ( $resources as $resource_key => $resource ) {
			if ( 0 === strpos( $resource_key, $token ) ) {
				$matches[ $resource_key ] = $resource;
			}
		}

		return $matches;
	}

	private function resolve_resource( $resources, $parsed ) {
		$token = ! empty( $parsed['name'] ) ? strtolower( (string) $parsed['name'] ) : '';

		if ( '' === $token || ! isset( $resources[ $token ] ) ) {
			return array(
				'key'      => '',
				'resource' => array(),
			);
		}

		return array(
			'key'      => $token,
			'resource' => $resources[ $token ],
		);
	}

	private function build_resource_result( $resource_key, $resource ) {
		$title       = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( (string) $resource_key );
		$description = ! empty( $resource['description'] ) ? (string) $resource['description'] : '';
		$icon        = ! empty( $resource['icon'] ) ? (string) $resource['icon'] : '';

		return $this->build_article_result(
			'inline-resource-' . $resource_key,
			$title,
			$description,
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( Telepilot_Telegram_Response_Builder::label( $icon, $title ) ),
					$description,
					Telepilot_Telegram_Response_Builder::italic( __( 'Keep typing or use the button below to continue this inline path.', 'wp-telepilot' ) ),
				)
			),
			$this->build_switch_inline_keyboard(
				array(
					array(
						sprintf( __( '%s Actions', 'wp-telepilot' ), $title ),
						$resource_key . ' ',
					),
				)
			)
		);
	}

	private function build_action_results( $resource_key, $resource, $parsed ) {
		$actions          = $this->get_actions_for_resource( $resource_key );
		$typed_action     = isset( $parsed['args'][0] ) ? strtolower( (string) $parsed['args'][0] ) : '';
		$has_action_space = ! empty( $parsed['ends_in_space'] ) && 1 === count( (array) $parsed['args'] );
		$results          = array();

		foreach ( $actions as $action_key => $action ) {
			if ( '' !== $typed_action && 0 !== strpos( $action_key, $typed_action ) && ! $has_action_space ) {
				continue;
			}

			$results[] = $this->build_action_result( $resource_key, $resource, $action_key, $action );
		}

		if ( empty( $results ) && '' !== $typed_action ) {
			$results[] = $this->build_article_result(
				'inline-action-pending-' . $resource_key,
				sprintf( __( '%s Inline Path', 'wp-telepilot' ), ! empty( $resource['title'] ) ? $resource['title'] : ucfirst( $resource_key ) ),
				__( 'Keep typing to refine the next valid action or target.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%s Inline Path', 'wp-telepilot' ), ! empty( $resource['title'] ) ? $resource['title'] : ucfirst( $resource_key ) ) ),
						__( 'Keep typing to refine the next valid action or target.', 'wp-telepilot' ),
					)
				),
				$this->build_switch_inline_keyboard(
					array(
						array(
							__( 'Continue Inline', 'wp-telepilot' ),
							trim( $resource_key . ' ' . $typed_action ) . ' ',
						),
					)
				)
			);
		}

		return $results;
	}

	private function get_actions_for_resource( $resource_key ) {
		$definitions = array(
			'posts'      => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show recent posts.', 'wp-telepilot' ), 'next' => __( 'Ready to list posts now.', 'wp-telepilot' ) ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search posts by keyword.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search keyword.', 'wp-telepilot' ) ),
				'open'    => array( 'title' => __( 'Open', 'wp-telepilot' ), 'description' => __( 'Choose a post target in the next stage.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ) ),
				'publish' => array( 'title' => __( 'Publish', 'wp-telepilot' ), 'description' => __( 'Choose a post to publish.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a publishable post.', 'wp-telepilot' ) ),
				'draft'   => array( 'title' => __( 'Draft', 'wp-telepilot' ), 'description' => __( 'Choose a post to move back to draft.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ) ),
				'trash'   => array( 'title' => __( 'Trash', 'wp-telepilot' ), 'description' => __( 'Choose a post to move to trash.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ) ),
				'new'     => array( 'title' => __( 'New', 'wp-telepilot' ), 'description' => __( 'Start guided post creation.', 'wp-telepilot' ), 'next' => __( 'Next step: begin guided post creation.', 'wp-telepilot' ) ),
			),
			'pages'      => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show recent pages.', 'wp-telepilot' ), 'next' => __( 'Ready to list pages now.', 'wp-telepilot' ) ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search pages by keyword.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search keyword.', 'wp-telepilot' ) ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a page target in the next stage.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ) ),
				'open'    => array( 'title' => __( 'Open', 'wp-telepilot' ), 'description' => __( 'Choose a page for browser editing.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ) ),
				'trash'   => array( 'title' => __( 'Trash', 'wp-telepilot' ), 'description' => __( 'Choose a page to move to trash.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ) ),
				'new'     => array( 'title' => __( 'New', 'wp-telepilot' ), 'description' => __( 'Start guided page creation.', 'wp-telepilot' ), 'next' => __( 'Next step: begin guided page creation.', 'wp-telepilot' ) ),
			),
			'categories' => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show categories.', 'wp-telepilot' ), 'next' => __( 'Ready to list categories now.', 'wp-telepilot' ) ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search categories by name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ) ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a category target in the next stage.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a category target.', 'wp-telepilot' ) ),
				'create'  => array( 'title' => __( 'Create', 'wp-telepilot' ), 'description' => __( 'Create a new category.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new category name.', 'wp-telepilot' ) ),
				'post'    => array( 'title' => __( 'Post', 'wp-telepilot' ), 'description' => __( 'Choose a category, then start a post workflow.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a category target.', 'wp-telepilot' ) ),
			),
			'tags'       => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show tags.', 'wp-telepilot' ), 'next' => __( 'Ready to list tags now.', 'wp-telepilot' ) ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search tags by name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ) ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a tag target in the next stage.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a tag target.', 'wp-telepilot' ) ),
				'create'  => array( 'title' => __( 'Create', 'wp-telepilot' ), 'description' => __( 'Create a new tag.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new tag name.', 'wp-telepilot' ) ),
			),
			'plugins'    => array(
				'list'       => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show installed plugins.', 'wp-telepilot' ), 'next' => __( 'Ready to list plugins now.', 'wp-telepilot' ) ),
				'updates'    => array( 'title' => __( 'Updates', 'wp-telepilot' ), 'description' => __( 'Show plugins with updates.', 'wp-telepilot' ), 'next' => __( 'Ready to list plugin updates now.', 'wp-telepilot' ) ),
				'search'     => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search plugins by identifier or name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ) ),
				'details'    => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a plugin target in the next stage.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ) ),
				'activate'   => array( 'title' => __( 'Activate', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to activate.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ) ),
				'deactivate' => array( 'title' => __( 'Deactivate', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to deactivate.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ) ),
				'update'     => array( 'title' => __( 'Update', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to update.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ) ),
				'delete'     => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to delete.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ) ),
			),
			'settings'   => array(
				'help'      => array( 'title' => __( 'Help', 'wp-telepilot' ), 'description' => __( 'Review safe settings commands.', 'wp-telepilot' ), 'next' => __( 'Ready to review safe settings guidance.', 'wp-telepilot' ) ),
				'summary'   => array( 'title' => __( 'Summary', 'wp-telepilot' ), 'description' => __( 'Review the current settings summary.', 'wp-telepilot' ), 'next' => __( 'Ready to show the settings summary.', 'wp-telepilot' ) ),
				'title'     => array( 'title' => __( 'Title', 'wp-telepilot' ), 'description' => __( 'Update the site title.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new site title.', 'wp-telepilot' ) ),
				'tagline'   => array( 'title' => __( 'Tagline', 'wp-telepilot' ), 'description' => __( 'Update the site tagline.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new tagline.', 'wp-telepilot' ) ),
				'timezone'  => array( 'title' => __( 'Timezone', 'wp-telepilot' ), 'description' => __( 'Update the site timezone.', 'wp-telepilot' ), 'next' => __( 'Next step: type a valid timezone string.', 'wp-telepilot' ) ),
			),
		);

		return isset( $definitions[ $resource_key ] ) ? $definitions[ $resource_key ] : array();
	}

	private function build_action_result( $resource_key, $resource, $action_key, $action ) {
		$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
		$action_title   = ! empty( $action['title'] ) ? (string) $action['title'] : ucfirst( $action_key );
		$description    = ! empty( $action['description'] ) ? (string) $action['description'] : '';
		$next           = ! empty( $action['next'] ) ? (string) $action['next'] : '';
		$prefill        = trim( $resource_key . ' ' . $action_key ) . ' ';

		return $this->build_article_result(
			'inline-action-' . $resource_key . '-' . $action_key,
			sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ),
			$description,
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ) ),
					$description,
					Telepilot_Telegram_Response_Builder::italic( $next ),
				)
			),
			$this->build_switch_inline_keyboard(
				array(
					array(
						__( 'Continue Inline', 'wp-telepilot' ),
						$prefill,
					),
				)
			)
		);
	}

	private function build_article_result( $id, $title, $description, $message_text, $reply_markup = array() ) {
		$result = array(
			'type'                  => 'article',
			'id'                    => substr( md5( (string) $id ), 0, 32 ),
			'title'                 => sanitize_text_field( (string) $title ),
			'description'           => sanitize_text_field( (string) $description ),
			'input_message_content' => array(
				'message_text' => (string) $message_text,
				'parse_mode'   => 'HTML',
			),
		);

		if ( ! empty( $reply_markup ) && is_array( $reply_markup ) ) {
			$result['reply_markup'] = $reply_markup;
		}

		return $result;
	}

	private function build_private_chat_button( $text, $start_parameter ) {
		return array(
			'text'            => sanitize_text_field( (string) $text ),
			'start_parameter' => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $start_parameter ),
		);
	}

	private function build_switch_inline_keyboard( $buttons ) {
		$rows = array();

		foreach ( array_chunk( (array) $buttons, 2 ) as $button_row ) {
			$row = array();

			foreach ( $button_row as $button ) {
				if ( empty( $button[0] ) || ! isset( $button[1] ) ) {
					continue;
				}

				$row[] = array(
					'text'                            => sanitize_text_field( (string) $button[0] ),
					'switch_inline_query_current_chat'=> (string) $button[1],
				);
			}

			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		return array(
			'inline_keyboard' => $rows,
		);
	}

	private function determine_context( $parsed, $resources ) {
		if ( empty( $parsed['name'] ) ) {
			return 'root_suggestions';
		}

		$resource = $this->resolve_resource( $resources, $parsed );

		if ( ! empty( $resource['key'] ) ) {
			return $resource['key'] . '_actions';
		}

		return 'resource_suggestions';
	}
}
