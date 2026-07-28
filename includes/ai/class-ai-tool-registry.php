<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Tool_Registry {
	private $ability_registry;

	public function __construct() {
		$this->ability_registry = new Telepilot_Ability_Registry();
	}

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
		$tools = array();

		foreach ( $this->ability_registry->get_abilities() as $ability_name => $definition ) {
			if ( empty( $definition['tool_name'] ) ) {
				continue;
			}

			$tools[ $definition['tool_name'] ] = array(
				'ability'               => $ability_name,
				'description'           => $definition['description'],
				'input_schema'          => $definition['input_schema'],
				'risk_level'            => ! empty( $definition['risk_level'] ) ? $definition['risk_level'] : 'read',
				'requires_confirmation' => ! empty( $definition['requires_confirmation'] ),
			);
		}

		return $tools;
	}
}
