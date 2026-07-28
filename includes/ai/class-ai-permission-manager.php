<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Permission_Manager {
	private $permission_service;

	public function __construct() {
		$this->permission_service = new Telepilot_Permission_Service();
	}

	public function require_ai_mode_access( $identity ) {
		$link_result = $this->permission_service->require_linked_user( $identity );
		if ( true !== $link_result ) {
			return $link_result;
		}

		$private_result = $this->permission_service->require_private_chat( $identity );
		if ( true !== $private_result ) {
			return $private_result;
		}

		if ( ! Telepilot_AI_Settings::is_enabled() ) {
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'AI Chat Unavailable', 'wp-telepilot' ) ),
						__( 'AI chat mode is currently disabled for this site.', 'wp-telepilot' ),
						__( 'An administrator can enable it from WP Telepilot settings.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/chat',
					'code'    => 'telepilot_ai_disabled',
				)
			);
		}

		return $this->permission_service->require_capability( $identity, 'read' );
	}

	public function is_tool_enabled( $tool_name ) {
		$settings = Telepilot_AI_Settings::get_settings();

		$map = array(
			'telepilot/get-site-info'     => 'ai_allow_site_read',
			'telepilot/get-site-health'   => 'ai_allow_site_read',
			'telepilot/search-posts'      => 'ai_allow_content_read',
			'telepilot/get-post'          => 'ai_allow_content_read',
			'telepilot/list-pages'        => 'ai_allow_content_read',
			'telepilot/list-comments'     => 'ai_allow_comments_read',
			'telepilot/list-users'        => 'ai_allow_users_read',
			'telepilot/list-plugins'      => 'ai_allow_plugins_read',
			'telepilot/list-updates'      => 'ai_allow_plugins_read',
			'telepilot/get-recent-alerts' => 'ai_allow_site_read',
			'telepilot/create-draft'      => 'ai_allow_create_drafts',
			'telepilot/update-draft'      => 'ai_allow_edit_drafts',
			'telepilot/schedule-post'     => 'ai_allow_edit_drafts',
			'telepilot/publish-post'      => 'ai_allow_publish_posts',
			'telepilot/unpublish-post'    => 'ai_allow_publish_posts',
			'telepilot/approve-comment'   => 'ai_allow_manage_comments',
			'telepilot/trash-comment'     => 'ai_allow_manage_comments',
			'telepilot/activate-plugin'   => 'ai_allow_activate_plugins',
			'telepilot/deactivate-plugin' => 'ai_allow_activate_plugins',
			'telepilot/update-plugin'     => 'ai_allow_update_plugins',
			'telepilot/create-draft'      => 'ai_allow_create_drafts',
			'telepilot/update-draft'      => 'ai_allow_edit_drafts',
			'telepilot/schedule-post'     => 'ai_allow_edit_drafts',
			'telepilot/publish-post'      => 'ai_allow_publish_posts',
			'telepilot/unpublish-post'    => 'ai_allow_publish_posts',
			'telepilot/approve-comment'   => 'ai_allow_manage_comments',
			'telepilot/trash-comment'     => 'ai_allow_manage_comments',
		);

		if ( empty( $map[ $tool_name ] ) ) {
			return false;
		}

		return ! empty( $settings[ $map[ $tool_name ] ] );
	}
}
