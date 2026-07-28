<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Ability_Registry {
	public function get_abilities() {
		$abilities = array(
			'telepilot/get-site-info' => array(
				'tool_name'      => 'telepilot_get_site_info',
				'description'    => 'Get the WordPress site overview including site name, URL, WordPress version, PHP version, active theme, draft count, comment count, and update counts.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'     => 'read',
			),
			'telepilot/get-site-health' => array(
				'tool_name'      => 'telepilot_get_site_health',
				'description'    => 'Get a high-level WordPress site health and update summary.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'     => 'read',
			),
			'telepilot/search-posts' => array(
				'tool_name'      => 'telepilot_search_posts',
				'description'    => 'List or search WordPress posts by keyword or status.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'query'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'latest', 'draft', 'trash' ) ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/get-post' => array(
				'tool_name'      => 'telepilot_get_post',
				'description'    => 'Get a single WordPress post by post ID or by a recent reference number from the current conversation.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/list-pages' => array(
				'tool_name'      => 'telepilot_list_pages',
				'description'    => 'List or search WordPress pages by keyword or status.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'query'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'latest', 'draft', 'trash' ) ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/get-page' => array(
				'tool_name'      => 'telepilot_get_page',
				'description'    => 'Get a single WordPress page by page ID or by a recent reference number from the current conversation.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/list-comments' => array(
				'tool_name'      => 'telepilot_list_comments',
				'description'    => 'List or search WordPress comments by moderation status or keyword.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'approved', 'spam', 'trash', 'all' ) ),
						'query'  => array( 'type' => 'string' ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/list-users' => array(
				'tool_name'      => 'telepilot_list_users',
				'description'    => 'List or search WordPress users.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
						'page'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/list-plugins' => array(
				'tool_name'      => 'telepilot_list_plugins',
				'description'    => 'List installed plugins, search plugins, or list plugins with updates.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'mode'  => array( 'type' => 'string', 'enum' => array( 'list', 'search', 'updates' ) ),
						'query' => array( 'type' => 'string' ),
						'page'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/list-updates' => array(
				'tool_name'      => 'telepilot_list_updates',
				'description'    => 'Get a summary of WordPress core, theme, and plugin updates, plus the current plugin update list.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'     => 'read',
			),
			'telepilot/get-recent-alerts' => array(
				'tool_name'      => 'telepilot_get_recent_alerts',
				'description'    => 'Get recent operational alerts and audit activity from WP Telepilot.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'     => 'read',
			),
			'telepilot/create-draft' => array(
				'tool_name'              => 'telepilot_create_draft',
				'description'            => 'Prepare a new WordPress draft post using a title and optional category IDs.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'title'      => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'required'   => array( 'title' ),
				),
				'risk_level'            => 'low_write',
				'requires_confirmation' => true,
			),
			'telepilot/update-draft' => array(
				'tool_name'              => 'telepilot_update_draft',
				'description'            => 'Prepare a title or excerpt update for a draft post by post ID or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
						'title'            => array( 'type' => 'string' ),
						'excerpt'          => array( 'type' => 'string' ),
					),
				),
				'risk_level'            => 'low_write',
				'requires_confirmation' => true,
			),
			'telepilot/schedule-post' => array(
				'tool_name'              => 'telepilot_schedule_post',
				'description'            => 'Prepare scheduling for a post using a post ID or recent reference and a site-local datetime string.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
						'scheduled_at'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'scheduled_at' ),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
			'telepilot/publish-post' => array(
				'tool_name'              => 'telepilot_publish_post',
				'description'            => 'Prepare publishing for a post by post ID or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'high_risk',
				'requires_confirmation' => true,
			),
			'telepilot/unpublish-post' => array(
				'tool_name'              => 'telepilot_unpublish_post',
				'description'            => 'Prepare moving a published post back to draft by post ID or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
			'telepilot/approve-comment' => array(
				'tool_name'              => 'telepilot_approve_comment',
				'description'            => 'Prepare approving a comment by comment ID or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
			'telepilot/trash-comment' => array(
				'tool_name'              => 'telepilot_trash_comment',
				'description'            => 'Prepare trashing a comment by comment ID or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
			'telepilot/activate-plugin' => array(
				'tool_name'              => 'telepilot_activate_plugin',
				'description'            => 'Prepare activation for a plugin by identifier or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'identifier'       => array( 'type' => 'string' ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
			'telepilot/deactivate-plugin' => array(
				'tool_name'              => 'telepilot_deactivate_plugin',
				'description'            => 'Prepare deactivation for a plugin by identifier or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'identifier'       => array( 'type' => 'string' ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'high_risk',
				'requires_confirmation' => true,
			),
			'telepilot/update-plugin' => array(
				'tool_name'              => 'telepilot_update_plugin',
				'description'            => 'Prepare updating a plugin by identifier or recent reference.',
				'input_schema'           => array(
					'type'       => 'object',
					'properties' => array(
						'identifier'       => array( 'type' => 'string' ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'            => 'moderate_write',
				'requires_confirmation' => true,
			),
		);

		return apply_filters( 'telepilot_ability_registry', $abilities );
	}

	public function get_ability( $ability_name ) {
		$abilities = $this->get_abilities();

		return ! empty( $abilities[ $ability_name ] ) ? $abilities[ $ability_name ] : null;
	}

	public function get_ability_by_tool( $tool_name ) {
		foreach ( $this->get_abilities() as $ability_name => $definition ) {
			if ( ! empty( $definition['tool_name'] ) && (string) $definition['tool_name'] === (string) $tool_name ) {
				$definition['ability_name'] = $ability_name;
				return $definition;
			}
		}

		return null;
	}
}
