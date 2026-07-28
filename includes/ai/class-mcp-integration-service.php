<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_MCP_Integration_Service {
	private $ability_registry;

	public function __construct() {
		$this->ability_registry = new Telepilot_Ability_Registry();
	}

	public function maybe_register_adapter() {
		$status = $this->get_status();

		if ( empty( $status['available'] ) ) {
			return;
		}

		do_action(
			'telepilot_mcp_register_abilities',
			$this->get_exportable_abilities(),
			array(
				'site_id' => (int) get_current_blog_id(),
				'status'  => $status,
			)
		);
	}

	public function get_status() {
		$detected_version = apply_filters( 'telepilot_mcp_adapter_version', '' );
		$available        = (bool) apply_filters(
			'telepilot_mcp_adapter_available',
			class_exists( 'Telepilot_MCP_Adapter' ) || defined( 'TELEPILOT_MCP_ADAPTER_VERSION' )
		);

		if ( ! $available ) {
			return array(
				'available'   => false,
				'label'       => __( 'MCP Adapter not installed', 'wp-telepilot' ),
				'description' => __( 'The MCP adapter is optional. Telegram AI mode works without it.', 'wp-telepilot' ),
				'version'     => '',
			);
		}

		if ( ! $detected_version && defined( 'TELEPILOT_MCP_ADAPTER_VERSION' ) ) {
			$detected_version = (string) TELEPILOT_MCP_ADAPTER_VERSION;
		}

		return array(
			'available'   => true,
			'label'       => __( 'MCP Adapter detected', 'wp-telepilot' ),
			'description' => __( 'Approved Telepilot abilities can be exposed to compatible external AI clients through the optional adapter.', 'wp-telepilot' ),
			'version'     => (string) $detected_version,
		);
	}

	public function get_exportable_abilities() {
		$abilities = array();

		foreach ( $this->ability_registry->get_abilities() as $ability_name => $definition ) {
			if ( ! $this->is_ability_exportable( $ability_name, $definition ) ) {
				continue;
			}

			$abilities[ $ability_name ] = $definition;
		}

		return apply_filters( 'telepilot_mcp_exportable_abilities', $abilities );
	}

	private function is_ability_exportable( $ability_name, $definition ) {
		$permission_manager = new Telepilot_AI_Permission_Manager();

		if ( ! $permission_manager->is_tool_enabled( $ability_name ) ) {
			return false;
		}

		$risk_level = ! empty( $definition['risk_level'] ) ? sanitize_key( (string) $definition['risk_level'] ) : 'read';

		if ( 'high_risk' === $risk_level ) {
			return (bool) apply_filters( 'telepilot_mcp_allow_high_risk_abilities', false, $ability_name, $definition );
		}

		return true;
	}
}
