<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Inline_Query_Service {
	private $permission_service;
	private $grammar;
	private $confirmation_service;
	private $posts_service;
	private $pages_service;
	private $taxonomies_service;
	private $plugins_service;

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
			$resolved_resource = $this->resolve_resource( $resources, $parsed );

			if ( empty( $resolved_resource['key'] ) ) {
				$matching_resources = $this->filter_matching_resources( $resources, $parsed );

				foreach ( $matching_resources as $resource_key => $resource ) {
					$results[] = $this->build_resource_result( $resource_key, $resource );
				}
			} else {
				$exact_results = $this->build_exact_query_results( $resolved_resource['key'], $resolved_resource['resource'], $parsed, $identity );

				foreach ( $exact_results as $exact_result ) {
					$results[] = $exact_result;
				}

				if ( empty( $exact_results ) || empty( $parsed['args'] ) || empty( $this->resolve_action( $resolved_resource['key'], $parsed, $identity ) ) ) {
					$action_results = $this->build_action_results( $resolved_resource['key'], $resolved_resource['resource'], $parsed, $identity );

					foreach ( $action_results as $action_result ) {
						$results[] = $action_result;
					}
				}
			}
		}

		$results = $this->unique_results( $results );

		if ( empty( $results ) ) {
			$results[] = $this->build_article_result(
				'inline-no-match',
				__( 'No Inline Suggestions Yet', 'wp-telepilot' ),
				__( 'Keep typing a supported resource or action such as posts, pages, categories, tags, plugins, or settings.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Inline Mode', 'wp-telepilot' ) ),
						__( 'Keep typing a resource such as posts, pages, categories, tags, plugins, or settings to refine the command.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::italic( __( 'Slash commands remain the main private-chat workflow. Inline mode now supports guided execution handoff for supported paths.', 'wp-telepilot' ) ),
					)
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
			'cache_time'  => ! empty( $parsed['args'] ) ? 15 : 45,
			'is_personal' => true,
			'next_offset' => '',
			'context'     => $this->determine_context( $parsed, $resources, $identity ),
		);
	}

	private function build_intro_result() {
		return $this->build_article_result(
			'inline-root',
			__( 'WP Telepilot Inline Mode', 'wp-telepilot' ),
			__( 'Use inline mode to discover supported resources, actions, and ID-based targets.', 'wp-telepilot' ),
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'WP Telepilot Inline Mode', 'wp-telepilot' ) ),
					__( 'Type a resource such as posts, pages, categories, tags, plugins, or settings.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Inline mode helps you discover valid next steps and can hand supported commands back into your linked private chat.', 'wp-telepilot' ) ),
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
				'description' => __( 'Page listing, search, browser access, and page actions.', 'wp-telepilot' ),
				'icon'        => 'pages',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'manage_categories' ) ) {
			$resources['categories'] = array(
				'title'       => __( 'Categories', 'wp-telepilot' ),
				'description' => __( 'Category listing, search, maintenance, and category-led post starts.', 'wp-telepilot' ),
				'icon'        => 'categories',
			);
			$resources['tags']       = array(
				'title'       => __( 'Tags', 'wp-telepilot' ),
				'description' => __( 'Tag listing, search, and maintenance actions.', 'wp-telepilot' ),
				'icon'        => 'tags',
			);
		}

		if ( $this->permission_service->user_can( $wp_user, 'activate_plugins' ) || $this->permission_service->user_can( $wp_user, 'update_plugins' ) || $this->permission_service->user_can( $wp_user, 'delete_plugins' ) ) {
			$resources['plugins'] = array(
				'title'       => __( 'Plugins', 'wp-telepilot' ),
				'description' => __( 'Plugin listing, updates, search, and management actions.', 'wp-telepilot' ),
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

	private function build_exact_query_results( $resource_key, $resource, $parsed, $identity ) {
		$resolved_action = $this->resolve_action( $resource_key, $parsed, $identity );

		if ( empty( $resolved_action['key'] ) ) {
			return array();
		}

		$action_key = $resolved_action['key'];
		$action     = $resolved_action['action'];
		$mode       = ! empty( $action['inline_mode'] ) ? (string) $action['inline_mode'] : '';
		$extra_text = trim( implode( ' ', array_slice( (array) $parsed['args'], 1 ) ) );

		switch ( $mode ) {
			case 'run':
				return array(
					$this->build_run_result( $resource_key, $resource, $action_key, $action ),
				);

			case 'run_query':
				if ( '' !== $extra_text ) {
					return array(
						$this->build_run_query_result( $resource_key, $resource, $action_key, $action, $parsed ),
					);
				}
				break;

			case 'target_run':
				return $this->build_target_results( $resource_key, $resource, $action_key, $action, $parsed, $identity );

			case 'guide':
				return array(
					$this->build_guide_result( $resource_key, $resource, $action_key, $action ),
				);
		}

		return array();
	}

	private function resolve_action( $resource_key, $parsed, $identity ) {
		$action_key = isset( $parsed['args'][0] ) ? strtolower( (string) $parsed['args'][0] ) : '';
		$actions    = $this->get_actions_for_resource( $resource_key, $identity );

		if ( '' === $action_key || ! isset( $actions[ $action_key ] ) ) {
			return array(
				'key'    => '',
				'action' => array(),
			);
		}

		return array(
			'key'    => $action_key,
			'action' => $actions[ $action_key ],
		);
	}

	private function build_action_results( $resource_key, $resource, $parsed, $identity ) {
		$actions          = $this->get_actions_for_resource( $resource_key, $identity );
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
			$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
			$results[]      = $this->build_article_result(
				'inline-action-pending-' . $resource_key,
				sprintf( __( '%s Inline Path', 'wp-telepilot' ), $resource_title ),
				__( 'Keep typing to refine the next valid action or target.', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%s Inline Path', 'wp-telepilot' ), $resource_title ) ),
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

	private function get_actions_for_resource( $resource_key, $identity = array() ) {
		$definitions = array(
			'posts'      => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show recent posts.', 'wp-telepilot' ), 'next' => __( 'Run the recent posts view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'drafts'  => array( 'title' => __( 'Drafts', 'wp-telepilot' ), 'description' => __( 'Show draft posts.', 'wp-telepilot' ), 'next' => __( 'Run the draft posts view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'trashed' => array( 'title' => __( 'Trashed', 'wp-telepilot' ), 'description' => __( 'Show trashed posts.', 'wp-telepilot' ), 'next' => __( 'Run the trashed posts view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search posts by keyword.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search keyword.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'stats'   => array( 'title' => __( 'Stats', 'wp-telepilot' ), 'description' => __( 'Show post counts by status.', 'wp-telepilot' ), 'next' => __( 'Run the post stats view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'new'     => array( 'title' => __( 'New', 'wp-telepilot' ), 'description' => __( 'Start guided post creation.', 'wp-telepilot' ), 'next' => __( 'Run the new post flow.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'open'    => array( 'title' => __( 'Open', 'wp-telepilot' ), 'description' => __( 'Choose a post for secure browser editing.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'publish' => array( 'title' => __( 'Publish', 'wp-telepilot' ), 'description' => __( 'Choose a post to publish.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a publishable post.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'draft'   => array( 'title' => __( 'Draft', 'wp-telepilot' ), 'description' => __( 'Choose a post to move back to draft.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'trash'   => array( 'title' => __( 'Trash', 'wp-telepilot' ), 'description' => __( 'Choose a post to move to trash.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a post target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'restore' => array( 'title' => __( 'Restore', 'wp-telepilot' ), 'description' => __( 'Choose a trashed post to restore.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a trashed post.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'delete'  => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a trashed post to delete permanently.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a trashed post.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
			),
			'pages'      => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show recent pages.', 'wp-telepilot' ), 'next' => __( 'Run the recent pages view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'drafts'  => array( 'title' => __( 'Drafts', 'wp-telepilot' ), 'description' => __( 'Show draft pages.', 'wp-telepilot' ), 'next' => __( 'Run the draft pages view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'trashed' => array( 'title' => __( 'Trashed', 'wp-telepilot' ), 'description' => __( 'Show trashed pages.', 'wp-telepilot' ), 'next' => __( 'Run the trashed pages view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search pages by keyword.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search keyword.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a page to see preview or wp-admin edit links.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'open'    => array( 'title' => __( 'Open', 'wp-telepilot' ), 'description' => __( 'Choose a page to see preview or wp-admin edit links.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ), 'inline_mode' => 'target_run', 'command_action' => 'details' ),
				'publish' => array( 'title' => __( 'Publish', 'wp-telepilot' ), 'description' => __( 'Choose a page to publish.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'draft'   => array( 'title' => __( 'Draft', 'wp-telepilot' ), 'description' => __( 'Choose a page to move to draft.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'trash'   => array( 'title' => __( 'Trash', 'wp-telepilot' ), 'description' => __( 'Choose a page to move to trash.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a page target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'restore' => array( 'title' => __( 'Restore', 'wp-telepilot' ), 'description' => __( 'Choose a trashed page to restore.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a trashed page.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'delete'  => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a trashed page to delete permanently.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a trashed page.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'new'     => array( 'title' => __( 'New', 'wp-telepilot' ), 'description' => __( 'Guided page creation is planned for the next stage.', 'wp-telepilot' ), 'next' => __( 'This path will open in the next beta stage.', 'wp-telepilot' ), 'inline_mode' => 'guide' ),
			),
			'categories' => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show categories.', 'wp-telepilot' ), 'next' => __( 'Run the category list.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search categories by name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a category target.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a category target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'create'  => array( 'title' => __( 'Create', 'wp-telepilot' ), 'description' => __( 'Create a new category.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new category name.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'post'    => array( 'title' => __( 'Post', 'wp-telepilot' ), 'description' => __( 'Choose a category, then start a post workflow.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a category target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'delete'  => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a category to delete.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a category target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
			),
			'tags'       => array(
				'list'    => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show tags.', 'wp-telepilot' ), 'next' => __( 'Run the tag list.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'search'  => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search tags by name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'details' => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a tag target.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a tag target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'create'  => array( 'title' => __( 'Create', 'wp-telepilot' ), 'description' => __( 'Create a new tag.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new tag name.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'delete'  => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a tag to delete.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a tag target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
			),
			'plugins'    => array(
				'list'       => array( 'title' => __( 'List', 'wp-telepilot' ), 'description' => __( 'Show installed plugins.', 'wp-telepilot' ), 'next' => __( 'Run the installed plugins view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'updates'    => array( 'title' => __( 'Updates', 'wp-telepilot' ), 'description' => __( 'Show plugins with updates.', 'wp-telepilot' ), 'next' => __( 'Run the plugin updates view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'search'     => array( 'title' => __( 'Search', 'wp-telepilot' ), 'description' => __( 'Search plugins by identifier or name.', 'wp-telepilot' ), 'next' => __( 'Next step: type a search term.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'details'    => array( 'title' => __( 'Details', 'wp-telepilot' ), 'description' => __( 'Choose a plugin target.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'activate'   => array( 'title' => __( 'Activate', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to activate.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'deactivate' => array( 'title' => __( 'Deactivate', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to deactivate.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'update'     => array( 'title' => __( 'Update', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to update.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
				'delete'     => array( 'title' => __( 'Delete', 'wp-telepilot' ), 'description' => __( 'Choose a plugin to delete.', 'wp-telepilot' ), 'next' => __( 'Next step: choose a plugin target.', 'wp-telepilot' ), 'inline_mode' => 'target_run' ),
			),
			'settings'   => array(
				'help'     => array( 'title' => __( 'Help', 'wp-telepilot' ), 'description' => __( 'Review safe settings commands.', 'wp-telepilot' ), 'next' => __( 'Run the settings help view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'summary'  => array( 'title' => __( 'Summary', 'wp-telepilot' ), 'description' => __( 'Review the current settings summary.', 'wp-telepilot' ), 'next' => __( 'Run the settings summary view.', 'wp-telepilot' ), 'inline_mode' => 'run' ),
				'title'    => array( 'title' => __( 'Title', 'wp-telepilot' ), 'description' => __( 'Update the site title.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new site title.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'tagline'  => array( 'title' => __( 'Tagline', 'wp-telepilot' ), 'description' => __( 'Update the site tagline.', 'wp-telepilot' ), 'next' => __( 'Next step: type the new tagline.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
				'timezone' => array( 'title' => __( 'Timezone', 'wp-telepilot' ), 'description' => __( 'Update the site timezone.', 'wp-telepilot' ), 'next' => __( 'Next step: type a valid timezone string.', 'wp-telepilot' ), 'inline_mode' => 'run_query' ),
			),
		);

		$actions = isset( $definitions[ $resource_key ] ) ? $definitions[ $resource_key ] : array();

		if ( empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			return $actions;
		}

		foreach ( array_keys( $actions ) as $action_key ) {
			if ( ! $this->action_allowed_for_identity( $resource_key, $action_key, $identity ) ) {
				unset( $actions[ $action_key ] );
			}
		}

		return $actions;
	}

	private function action_allowed_for_identity( $resource_key, $action_key, $identity ) {
		$wp_user = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user'] : null;

		if ( ! $wp_user ) {
			return false;
		}

		switch ( $resource_key ) {
			case 'posts':
				if ( in_array( $action_key, array( 'list', 'drafts', 'trashed', 'search', 'stats', 'new', 'open' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'edit_posts' );
				}

				if ( 'publish' === $action_key ) {
					return $this->permission_service->user_can( $wp_user, 'edit_posts' ) && $this->permission_service->user_can( $wp_user, 'publish_posts' );
				}

				if ( in_array( $action_key, array( 'draft', 'trash', 'restore', 'delete' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'edit_posts' );
				}

				return false;

			case 'pages':
				if ( in_array( $action_key, array( 'list', 'drafts', 'trashed', 'search', 'details', 'open', 'new', 'draft' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'edit_pages' );
				}

				if ( 'publish' === $action_key ) {
					return $this->permission_service->user_can( $wp_user, 'edit_pages' ) && $this->permission_service->user_can( $wp_user, 'publish_pages' );
				}

				if ( in_array( $action_key, array( 'trash', 'restore', 'delete' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'edit_pages' );
				}

				return false;

			case 'categories':
				if ( 'post' === $action_key ) {
					return $this->permission_service->user_can( $wp_user, 'manage_categories' ) && $this->permission_service->user_can( $wp_user, 'edit_posts' );
				}

				return $this->permission_service->user_can( $wp_user, 'manage_categories' );

			case 'tags':
				return $this->permission_service->user_can( $wp_user, 'manage_categories' );

			case 'plugins':
				if ( in_array( $action_key, array( 'list', 'search', 'details' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'activate_plugins' ) || $this->permission_service->user_can( $wp_user, 'update_plugins' ) || $this->permission_service->user_can( $wp_user, 'delete_plugins' );
				}

				if ( in_array( $action_key, array( 'updates', 'update' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'update_plugins' );
				}

				if ( in_array( $action_key, array( 'activate', 'deactivate' ), true ) ) {
					return $this->permission_service->user_can( $wp_user, 'activate_plugins' );
				}

				if ( 'delete' === $action_key ) {
					return $this->permission_service->user_can( $wp_user, 'delete_plugins' );
				}

				return false;

			case 'settings':
				return $this->permission_service->user_can( $wp_user, 'manage_options' );
		}

		return false;
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

	private function build_run_result( $resource_key, $resource, $action_key, $action ) {
		$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
		$action_title   = ! empty( $action['title'] ) ? (string) $action['title'] : ucfirst( $action_key );
		$command_action = ! empty( $action['command_action'] ) ? (string) $action['command_action'] : $action_key;
		$command_text   = '/' . trim( $resource_key . ' ' . $command_action );

		return $this->build_article_result(
			'run:' . $resource_key . ':' . $command_action,
			sprintf( __( 'Run %1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ),
			sprintf( __( 'Execute %s in your linked private chat.', 'wp-telepilot' ), $command_text ),
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ) ),
					sprintf( __( 'Command: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $command_text ) ),
					Telepilot_Telegram_Response_Builder::italic( __( 'WP Telepilot will run this in your linked private chat.', 'wp-telepilot' ) ),
				)
			)
		);
	}

	private function build_run_query_result( $resource_key, $resource, $action_key, $action, $parsed ) {
		$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
		$action_title   = ! empty( $action['title'] ) ? (string) $action['title'] : ucfirst( $action_key );
		$query_text     = trim( (string) $parsed['raw'] );
		$command_text   = '/' . ltrim( $query_text, '/' );

		return $this->build_article_result(
			'runq:' . $resource_key . ':' . $action_key,
			sprintf( __( 'Run %1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ),
			Telepilot_Telegram_Response_Builder::escape( $command_text ),
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ) ),
					sprintf( __( 'Command: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $command_text ) ),
					Telepilot_Telegram_Response_Builder::italic( __( 'WP Telepilot will run this in your linked private chat.', 'wp-telepilot' ) ),
				)
			)
		);
	}

	private function build_guide_result( $resource_key, $resource, $action_key, $action ) {
		$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
		$action_title   = ! empty( $action['title'] ) ? (string) $action['title'] : ucfirst( $action_key );
		$description    = ! empty( $action['description'] ) ? (string) $action['description'] : '';
		$prefill        = trim( $resource_key . ' ' . $action_key ) . ' ';

		return $this->build_article_result(
			'guide:' . $resource_key . ':' . $action_key,
			sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ),
			$description,
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ) ),
					$description,
					Telepilot_Telegram_Response_Builder::italic( __( 'This inline path is planned for the next stage.', 'wp-telepilot' ) ),
				)
			),
			$this->build_switch_inline_keyboard(
				array(
					array(
						__( 'Keep Inline Context', 'wp-telepilot' ),
						$prefill,
					),
				)
			)
		);
	}

	private function build_target_results( $resource_key, $resource, $action_key, $action, $parsed, $identity ) {
		$targets = $this->lookup_targets( $resource_key, $action_key, $parsed, $identity );

		if ( empty( $targets ) ) {
			return array(
				$this->build_article_result(
					'inline-target-empty-' . $resource_key . '-' . $action_key,
					sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), ! empty( $resource['title'] ) ? $resource['title'] : ucfirst( $resource_key ), ! empty( $action['title'] ) ? $action['title'] : ucfirst( $action_key ) ),
					__( 'No matching targets were found for that path yet.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::join_blocks(
						array(
							Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), ! empty( $resource['title'] ) ? $resource['title'] : ucfirst( $resource_key ), ! empty( $action['title'] ) ? $action['title'] : ucfirst( $action_key ) ) ),
							__( 'No matching targets were found for that path yet.', 'wp-telepilot' ),
							Telepilot_Telegram_Response_Builder::italic( __( 'Try a different keyword or clear the search fragment to see recent targets.', 'wp-telepilot' ) ),
						)
					)
				),
			);
		}

		$results = array();

		foreach ( $targets as $target ) {
			$result = $this->build_target_run_result( $resource_key, $resource, $action_key, $action, $target );

			if ( ! empty( $result ) && is_array( $result ) ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	private function lookup_targets( $resource_key, $action_key, $parsed, $identity ) {
		switch ( $resource_key ) {
			case 'posts':
				return $this->lookup_post_targets( $action_key, $parsed, $identity );
			case 'pages':
				return $this->lookup_page_targets( $action_key, $parsed, $identity );
			case 'categories':
			case 'tags':
				return $this->lookup_term_targets( $resource_key, $parsed );
			case 'plugins':
				return $this->lookup_plugin_targets( $action_key, $parsed );
		}

		return array();
	}

	private function lookup_post_targets( $action_key, $parsed, $identity ) {
		$search_term = trim( implode( ' ', array_slice( (array) $parsed['args'], 1 ) ) );
		$post_id     = absint( $search_term );
		$posts       = array();

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
				$posts[] = $post;
			}
		} elseif ( in_array( $action_key, array( 'restore', 'delete' ), true ) ) {
			$posts = $this->query_post_targets( 'post', array( 'trash' ), $search_term );
		} elseif ( '' === $search_term ) {
			$result = $this->get_posts_service()->latest_page( 1 );
			$posts  = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		} else {
			$result = $this->get_posts_service()->search_page( $search_term, 1 );
			$posts  = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		}

		return array_values(
			array_filter(
				$posts,
				function( $post ) use ( $action_key, $identity ) {
					return $this->is_post_action_candidate( $post, $action_key, $identity );
				}
			)
		);
	}

	private function is_post_action_candidate( $post, $action_key, $identity ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return false;
		}

		$wp_user = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user'] : null;

		if ( ! $wp_user ) {
			return false;
		}

		switch ( $action_key ) {
			case 'open':
				return 'trash' !== $post->post_status && $this->permission_service->user_can( $wp_user, 'edit_post', $post->ID );
			case 'publish':
				return in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) && $this->permission_service->user_can( $wp_user, 'edit_post', $post->ID ) && $this->permission_service->user_can( $wp_user, 'publish_posts' );
			case 'draft':
				return in_array( $post->post_status, array( 'publish', 'future', 'pending' ), true ) && $this->permission_service->user_can( $wp_user, 'edit_post', $post->ID );
			case 'trash':
				return 'trash' !== $post->post_status && $this->permission_service->user_can( $wp_user, 'delete_post', $post->ID );
			case 'restore':
			case 'delete':
				return 'trash' === $post->post_status && $this->permission_service->user_can( $wp_user, 'delete_post', $post->ID );
		}

		return false;
	}

	private function lookup_page_targets( $action_key, $parsed, $identity ) {
		$search_term = trim( implode( ' ', array_slice( (array) $parsed['args'], 1 ) ) );
		$page_id     = absint( $search_term );
		$pages       = array();

		if ( $page_id > 0 ) {
			$page = get_post( $page_id );

			if ( $page instanceof WP_Post && 'page' === $page->post_type ) {
				$pages[] = $page;
			}
		} elseif ( in_array( $action_key, array( 'restore', 'delete' ), true ) ) {
			$pages = $this->query_post_targets( 'page', array( 'trash' ), $search_term );
		} elseif ( '' === $search_term ) {
			$result = $this->get_pages_service()->latest_page( 1 );
			$pages = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		} else {
			$result = $this->get_pages_service()->search_page( $search_term, 1 );
			$pages  = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		}

		return array_values(
			array_filter(
				$pages,
				function( $page ) use ( $action_key, $identity ) {
					return $this->is_page_action_candidate( $page, $action_key, $identity );
				}
			)
		);
	}

	private function is_page_action_candidate( $page, $action_key, $identity ) {
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			return false;
		}

		$wp_user = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user'] : null;

		if ( ! $wp_user ) {
			return false;
		}

		switch ( $action_key ) {
			case 'details':
			case 'open':
				return 'trash' !== $page->post_status && $this->permission_service->user_can( $wp_user, 'edit_page', $page->ID );
			case 'publish':
				return in_array( $page->post_status, array( 'draft', 'private' ), true ) && $this->permission_service->user_can( $wp_user, 'edit_page', $page->ID ) && $this->permission_service->user_can( $wp_user, 'publish_pages' );
			case 'draft':
				return 'trash' !== $page->post_status && 'draft' !== $page->post_status && $this->permission_service->user_can( $wp_user, 'edit_page', $page->ID );
			case 'trash':
				return 'trash' !== $page->post_status && $this->permission_service->user_can( $wp_user, 'edit_page', $page->ID );
			case 'restore':
				return 'trash' === $page->post_status && $this->permission_service->user_can( $wp_user, 'edit_page', $page->ID );
			case 'delete':
				return 'trash' === $page->post_status && $this->permission_service->user_can( $wp_user, 'delete_post', $page->ID );
		}

		return false;
	}

	private function query_post_targets( $post_type, $post_statuses, $search_term = '', $limit = 5 ) {
		$args = array(
			'post_type'           => sanitize_key( $post_type ),
			'post_status'         => array_values( array_map( 'sanitize_key', (array) $post_statuses ) ),
			'posts_per_page'      => max( 1, absint( $limit ) ),
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'suppress_filters'    => false,
			'ignore_sticky_posts' => true,
		);

		$search_term = sanitize_text_field( $search_term );

		if ( '' !== $search_term ) {
			$args['s'] = $search_term;
		}

		return get_posts( $args );
	}

	private function lookup_term_targets( $resource_key, $parsed ) {
		$taxonomy   = $this->get_taxonomy_for_resource( $resource_key );
		$search_term = trim( implode( ' ', array_slice( (array) $parsed['args'], 1 ) ) );
		$term_id     = absint( $search_term );
		$terms       = array();

		if ( $term_id > 0 ) {
			$term = $this->get_taxonomies_service()->get_term_details( $taxonomy, $term_id );

			if ( ! is_wp_error( $term ) && $term instanceof WP_Term ) {
				$terms[] = $term;
			}
		} else {
			$result = $this->get_taxonomies_service()->list_terms( $taxonomy, 1, 5, $search_term );
			$terms  = ! is_wp_error( $result ) && ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		}

		return array_values(
			array_filter(
				$terms,
				function( $term ) {
					return $term instanceof WP_Term;
				}
			)
		);
	}

	private function lookup_plugin_targets( $action_key, $parsed ) {
		$search_term = trim( implode( ' ', array_slice( (array) $parsed['args'], 1 ) ) );
		$plugins     = array();

		if ( '' !== $search_term ) {
			$exact = $this->get_plugins_service()->get_plugin_details( $search_term );

			if ( ! is_wp_error( $exact ) && ! empty( $exact ) && is_array( $exact ) ) {
				$plugins[] = $exact;
			} else {
				$result  = $this->get_plugins_service()->search_page( $search_term, 1 );
				$plugins = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
			}
		} else {
			if ( 'update' === $action_key ) {
				$result = $this->get_plugins_service()->updates_page( 1 );
			} else {
				$result = $this->get_plugins_service()->list_page( 1 );
			}

			$plugins = ! empty( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		}

		return array_values(
			array_filter(
				$plugins,
				function( $plugin ) use ( $action_key ) {
					if ( empty( $plugin ) || ! is_array( $plugin ) ) {
						return false;
					}

					if ( ! empty( $plugin['is_self'] ) && 'details' !== $action_key ) {
						return false;
					}

					switch ( $action_key ) {
						case 'details':
							return true;
						case 'activate':
							return empty( $plugin['is_active'] );
						case 'deactivate':
							return ! empty( $plugin['is_active'] );
						case 'update':
							return ! empty( $plugin['update_version'] );
						case 'delete':
							return empty( $plugin['is_active'] );
					}

					return false;
				}
			)
		);
	}

	private function get_taxonomy_for_resource( $resource_key ) {
		return 'tags' === $resource_key ? 'post_tag' : 'category';
	}

	private function build_target_run_result( $resource_key, $resource, $action_key, $action, $target ) {
		$command_action = ! empty( $action['command_action'] ) ? (string) $action['command_action'] : $action_key;
		$resource_title = ! empty( $resource['title'] ) ? (string) $resource['title'] : ucfirst( $resource_key );
		$action_title   = ! empty( $action['title'] ) ? (string) $action['title'] : ucfirst( $action_key );
		$target_key     = '';
		$target_title   = '';
		$description    = '';

		if ( in_array( $resource_key, array( 'posts', 'pages' ), true ) && $target instanceof WP_Post ) {
			$target_key   = (string) $target->ID;
			$target_title = sprintf(
				'[%1$d] %2$s',
				$target->ID,
				html_entity_decode( get_the_title( $target ), ENT_QUOTES, get_bloginfo( 'charset' ) )
			);
			$description  = 'posts' === $resource_key ? $this->build_post_target_description( $target ) : $this->build_page_target_description( $target );
		} elseif ( in_array( $resource_key, array( 'categories', 'tags' ), true ) && $target instanceof WP_Term ) {
			$target_key   = (string) $target->term_id;
			$target_title = sprintf( '[%1$d] %2$s', $target->term_id, $target->name );
			$description  = $this->build_term_target_description( $target, $resource_key );
		} elseif ( 'plugins' === $resource_key && is_array( $target ) ) {
			$identifier   = ! empty( $target['identifier'] ) ? (string) $target['identifier'] : '';
			$name         = ! empty( $target['name'] ) ? (string) $target['name'] : $identifier;
			$target_key   = $identifier;
			$target_title = sprintf( '[%1$s] %2$s', $identifier, $name );
			$description  = $this->build_plugin_target_description( $target );
		}

		if ( '' === $target_key || '' === $target_title ) {
			return array();
		}

		return $this->build_article_result(
			'run:' . $resource_key . ':' . $command_action . ':' . $target_key,
			$target_title,
			$description,
			$this->build_target_message( $resource_title, $action_title, $target_title, '/' . trim( $resource_key . ' ' . $command_action . ' ' . $target_key ) )
		);
	}

	private function build_post_target_description( $post ) {
		$status  = sanitize_text_field( ucfirst( str_replace( array( '-', '_' ), ' ', (string) $post->post_status ) ) );
		$updated = get_post_modified_time( 'Y-m-d H:i:s', false, $post, true );

		return sprintf( __( 'Status: %1$s | Updated: %2$s', 'wp-telepilot' ), $status, $updated );
	}

	private function build_page_target_description( $page ) {
		$status  = sanitize_text_field( ucfirst( str_replace( array( '-', '_' ), ' ', (string) $page->post_status ) ) );
		$updated = get_post_modified_time( 'Y-m-d H:i:s', false, $page, true );

		return sprintf( __( 'Status: %1$s | Updated: %2$s', 'wp-telepilot' ), $status, $updated );
	}

	private function build_term_target_description( $term, $resource_key ) {
		$count_label = sprintf( __( 'Count: %d', 'wp-telepilot' ), (int) $term->count );
		$slug_label  = sprintf( __( 'Slug: %s', 'wp-telepilot' ), sanitize_text_field( $term->slug ) );

		if ( 'categories' === $resource_key && ! empty( $term->parent ) ) {
			return $slug_label . ' | ' . $count_label;
		}

		return $slug_label . ' | ' . $count_label;
	}

	private function build_plugin_target_description( $plugin ) {
		$status = ! empty( $plugin['is_active'] ) ? __( 'Active', 'wp-telepilot' ) : __( 'Inactive', 'wp-telepilot' );
		$parts  = array(
			sprintf( __( 'Status: %s', 'wp-telepilot' ), $status ),
		);

		if ( ! empty( $plugin['version'] ) ) {
			$parts[] = sprintf( __( 'Version: %s', 'wp-telepilot' ), $plugin['version'] );
		}

		if ( ! empty( $plugin['update_version'] ) ) {
			$parts[] = sprintf( __( 'Update: %s', 'wp-telepilot' ), $plugin['update_version'] );
		}

		return implode( ' | ', $parts );
	}

	private function build_target_message( $resource_title, $action_title, $target_title, $command_text ) {
		return Telepilot_Telegram_Response_Builder::join_blocks(
			array(
				Telepilot_Telegram_Response_Builder::bold( sprintf( __( '%1$s -> %2$s', 'wp-telepilot' ), $resource_title, $action_title ) ),
				sprintf( __( 'Target: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::escape( $target_title ) ),
				sprintf( __( 'Command: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $command_text ) ),
				Telepilot_Telegram_Response_Builder::italic( __( 'WP Telepilot will run this in your linked private chat.', 'wp-telepilot' ) ),
			)
		);
	}

	private function build_article_result( $id, $title, $description, $message_text, $reply_markup = array() ) {
		$result = array(
			'type'                  => 'article',
			'id'                    => $this->normalize_result_id( $id ),
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

	private function normalize_result_id( $id ) {
		$id = trim( preg_replace( '/[^A-Za-z0-9:_-]/', '-', (string) $id ) );

		if ( '' === $id ) {
			return substr( md5( wp_generate_uuid4() ), 0, 32 );
		}

		if ( strlen( $id ) > 64 ) {
			return substr( md5( $id ), 0, 32 );
		}

		return $id;
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
					'switch_inline_query_current_chat' => (string) $button[1],
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

	private function determine_context( $parsed, $resources, $identity ) {
		if ( empty( $parsed['name'] ) ) {
			return 'root_suggestions';
		}

		$resource = $this->resolve_resource( $resources, $parsed );

		if ( empty( $resource['key'] ) ) {
			return 'resource_suggestions';
		}

		$action = $this->resolve_action( $resource['key'], $parsed, $identity );

		if ( empty( $action['key'] ) ) {
			return $resource['key'] . '_actions';
		}

		return $resource['key'] . '_' . $action['key'] . '_targets';
	}

	private function unique_results( $results ) {
		$unique = array();
		$seen   = array();

		foreach ( (array) $results as $result ) {
			if ( empty( $result['id'] ) ) {
				continue;
			}

			if ( isset( $seen[ $result['id'] ] ) ) {
				continue;
			}

			$seen[ $result['id'] ] = true;
			$unique[]              = $result;
		}

		return $unique;
	}

	private function get_confirmation_service() {
		if ( ! $this->confirmation_service instanceof Telepilot_Confirmation_Service ) {
			$this->confirmation_service = new Telepilot_Confirmation_Service();
		}

		return $this->confirmation_service;
	}

	private function get_posts_service() {
		if ( ! $this->posts_service instanceof Telepilot_Posts_Service ) {
			$this->posts_service = new Telepilot_Posts_Service( $this->get_confirmation_service() );
		}

		return $this->posts_service;
	}

	private function get_pages_service() {
		if ( ! $this->pages_service instanceof Telepilot_Pages_Service ) {
			$this->pages_service = new Telepilot_Pages_Service( $this->get_confirmation_service() );
		}

		return $this->pages_service;
	}

	private function get_taxonomies_service() {
		if ( ! $this->taxonomies_service instanceof Telepilot_Taxonomies_Service ) {
			$this->taxonomies_service = new Telepilot_Taxonomies_Service( $this->get_confirmation_service() );
		}

		return $this->taxonomies_service;
	}

	private function get_plugins_service() {
		if ( ! $this->plugins_service instanceof Telepilot_Plugins_Service ) {
			$this->plugins_service = new Telepilot_Plugins_Service( $this->get_confirmation_service() );
		}

		return $this->plugins_service;
	}
}
