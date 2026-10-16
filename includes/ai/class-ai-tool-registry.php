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
		return $this->get_request_context()['schemas'];
	}

	/** One assessment supplies both model instructions and callable schemas. */
	public function get_request_context( $remaining_tool_calls = PHP_INT_MAX ) {
		$schemas = array();
		$capabilities = array();
		$permissions = new Telepilot_AI_Permission_Manager();

		foreach ( $this->get_tools() as $tool_name => $definition ) {
			$enabled = $permissions->is_tool_enabled( $definition['ability'] );
			$access = $enabled ? Telepilot_Ability_Executor::permission( $definition['ability'] ) : null;
			$status = ! $enabled ? 'disabled' : ( is_wp_error( $access ) ? 'denied' : 'available' );
			$capabilities[] = array(
				'tool' => $tool_name,
				'description' => $definition['description'],
				'status' => $status,
				'requires_confirmation' => $definition['requires_confirmation'],
			);
			if ( 'available' !== $status || $remaining_tool_calls <= 0 ) { continue; }
			$schemas[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool_name,
					'description' => $definition['description'],
					'parameters'  => self::strict_schema( $definition['input_schema'] ),
					'strict'      => true,
				),
			);
		}

		return array( 'schemas' => $schemas, 'capabilities' => $capabilities, 'remaining_tool_calls' => max( 0, $remaining_tool_calls ) );
	}

	public static function strict_schema( $schema ) {
		$required = $schema['required'] ?? array();
		$properties = (array) ( $schema['properties'] ?? array() );
		foreach ( $properties as $key => &$property ) {
			if ( ! in_array( $key, $required, true ) ) {
				$property['type'] = array( $property['type'], 'null' );
				if ( isset( $property['enum'] ) ) { $property['enum'][] = null; }
			}
		}
		unset( $property );
		$schema['properties'] = $properties ? $properties : (object) array();
		$schema['required'] = array_keys( $properties );
		$schema['additionalProperties'] = false;
		return $schema;
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
