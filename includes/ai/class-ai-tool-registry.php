<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Tool_Registry {
	public function get_tool_schemas() {
		$schemas = array();

		foreach ( $this->get_tools() as $tool_name => $definition ) {
			$schemas[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool_name,
					'description' => $definition['description'],
					'parameters'  => $definition['input_schema'],
				),
			);
		}

		return $schemas;
	}

	public function get_tool( $tool_name ) {
		$tools = $this->get_tools();

		return ! empty( $tools[ $tool_name ] ) ? $tools[ $tool_name ] : null;
	}

	public function get_tools() {
		return array(
			'telepilot_get_site_info' => array(
				'ability'      => 'telepilot/get-site-info',
				'description'  => 'Get the WordPress site overview including site name, URL, WordPress version, PHP version, active theme, draft count, comment count, and update counts.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'   => 'read',
			),
			'telepilot_get_site_health' => array(
				'ability'      => 'telepilot/get-site-health',
				'description'  => 'Get a high-level WordPress site health and update summary.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'   => 'read',
			),
			'telepilot_search_posts' => array(
				'ability'      => 'telepilot/search-posts',
				'description'  => 'List or search WordPress posts by keyword or status.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'latest', 'draft', 'trash' ) ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_get_post' => array(
				'ability'      => 'telepilot/get-post',
				'description'  => 'Get a single WordPress post by post ID or by a recent reference number from the current conversation.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_list_pages' => array(
				'ability'      => 'telepilot/list-pages',
				'description'  => 'List or search WordPress pages by keyword or status.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'latest', 'draft', 'trash' ) ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_get_page' => array(
				'ability'      => 'telepilot/get-page',
				'description'  => 'Get a single WordPress page by page ID or by a recent reference number from the current conversation.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'reference_number' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_list_comments' => array(
				'ability'      => 'telepilot/list-comments',
				'description'  => 'List or search WordPress comments by moderation status or keyword.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'approved', 'spam', 'trash', 'all' ) ),
						'query'  => array( 'type' => 'string' ),
						'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_list_users' => array(
				'ability'      => 'telepilot/list-users',
				'description'  => 'List or search WordPress users.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
						'page'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_list_plugins' => array(
				'ability'      => 'telepilot/list-plugins',
				'description'  => 'List installed plugins, search plugins, or list plugins with updates.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'mode'  => array( 'type' => 'string', 'enum' => array( 'list', 'search', 'updates' ) ),
						'query' => array( 'type' => 'string' ),
						'page'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
			'telepilot_list_updates' => array(
				'ability'      => 'telepilot/list-updates',
				'description'  => 'Get a summary of WordPress core, theme, and plugin updates, plus the current plugin update list.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'risk_level'   => 'read',
			),
			'telepilot_get_recent_alerts' => array(
				'ability'      => 'telepilot/get-recent-alerts',
				'description'  => 'Get recent operational alerts and audit activity from WP Telepilot.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'risk_level'   => 'read',
			),
		);
	}
}
