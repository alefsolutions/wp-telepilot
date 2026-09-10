<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independently optional, read-only bridge to the official WordPress MCP Adapter. */
class Telepilot_MCP_Integration_Service {
	const SERVER_ID = 'wp-telepilot-readonly';
	const REST_NAMESPACE = 'wp-telepilot-mcp/v1';
	const REST_ROUTE = 'server';

	public static function defaults() {
		return array( 'mcp_enabled' => 0, 'mcp_allowed_roles' => array( 'administrator' ), 'mcp_allowed_abilities' => array() );
	}
	public static function settings() { return wp_parse_args( get_option( 'telepilot_settings', array() ), self::defaults() ); }
	public static function sanitize( $input ) {
		$reads = array_keys( self::read_definitions() );
		return array( 'mcp_enabled' => empty( $input['mcp_enabled'] ) ? 0 : 1,
			'mcp_allowed_roles' => array_values( array_intersect( array_keys( wp_roles()->roles ), array_map( 'sanitize_key', (array) ( $input['mcp_allowed_roles'] ?? array( 'administrator' ) ) ) ) ),
			'mcp_allowed_abilities' => array_values( array_intersect( $reads, (array) ( $input['mcp_allowed_abilities'] ?? array() ) ) ),
		);
	}
	public static function read_definitions() {
		return array_filter( ( new Telepilot_Ability_Registry() )->get_abilities(), static function ( $definition ) { return 'read' === $definition['risk_level']; } );
	}

	public function maybe_register_adapter() {
		// Register hooks even when disabled; every callback rechecks saved configuration.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
	}
	public function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) { wp_register_ability_category( 'wp-telepilot', array( 'label' => 'WP Telepilot', 'description' => 'Explicitly approved read-only WordPress management abilities.' ) ); }
	}
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || empty( self::settings()['mcp_enabled'] ) ) { return; }
		foreach ( $this->get_exportable_abilities() as $name => $definition ) {
			$schema = $definition['input_schema'];
			$schema['properties'] = (array) $schema['properties'];
			unset( $schema['properties']['reference_number'] ); // MCP has no Telegram conversation references.
			if ( ! $schema['properties'] ) { $schema['properties'] = (object) array(); }
			wp_register_ability( $name, array(
				'label' => ucwords( str_replace( array( 'telepilot/', '-' ), array( '', ' ' ), $name ) ),
				'description' => $definition['description'], 'category' => 'wp-telepilot', 'input_schema' => $schema,
				'output_schema' => $definition['output_schema'],
				'permission_callback' => function ( $input = array() ) use ( $name ) { return $this->permission( $name, (array) $input ); },
				'execute_callback' => function ( $input = array() ) use ( $name ) { return $this->execute( $name, (array) $input ); },
				// Custom server only: no generic REST or default-server discovery.
				'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
			) );
		}
	}
	public function register_server( $adapter ) {
		if ( empty( self::settings()['mcp_enabled'] ) || ! function_exists( 'wp_get_ability' ) || ! $this->get_status()['available'] ) { return; }
		$tools = array_keys( $this->get_exportable_abilities() );
		if ( ! $tools ) { return; }
		$adapter->create_server( self::SERVER_ID, self::REST_NAMESPACE, self::REST_ROUTE, 'WP Telepilot Read-Only', 'Authenticated, explicitly approved WordPress reads. No write tools.', TELEPILOT_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools, array(), array(), function () { return true === $this->transport_permission(); }
		);
	}
	public function transport_permission() {
		$user = wp_get_current_user(); $settings = self::settings();
		if ( ! is_ssl() || empty( $settings['mcp_enabled'] ) || ! Telepilot_Actor_Access::active_user( $user ) || ! array_intersect( $user->roles, (array) $settings['mcp_allowed_roles'] ) ) { return new WP_Error( 'telepilot_mcp_denied', 'MCP requires HTTPS, an authenticated active WordPress actor, and an explicit role grant.', array( 'status' => 403 ) ); }
		return true;
	}
	public function permission( $name, $input = array() ) {
		$access = $this->transport_permission();
		if ( is_wp_error( $access ) ) { return $access; }
		if ( ! isset( $this->get_exportable_abilities()[ $name ] ) || isset( $input['reference_number'] ) ) { return new WP_Error( 'telepilot_mcp_read_only', 'This ability is not an approved MCP read.' ); }
		return Telepilot_Operation_Context::run( 'mcp', array(), static function () use ( $name, $input ) { return Telepilot_Ability_Executor::permission( $name, $input ); } );
	}
	public function execute( $name, $input = array() ) {
		$access = $this->permission( $name, $input );
		if ( is_wp_error( $access ) ) { return $access; }
		return Telepilot_Operation_Context::run( 'mcp', array(), static function () use ( $name, $input ) {
			$result = ( new Telepilot_Ability_Executor() )->execute( $name, $input );
			return is_wp_error( $result ) ? $result : Telepilot_AI_Data_Policy::clean( $result );
		} );
	}
	public function get_exportable_abilities() {
		$settings = self::settings();
		return empty( $settings['mcp_enabled'] ) ? array() : array_intersect_key( self::read_definitions(), array_flip( (array) $settings['mcp_allowed_abilities'] ) );
	}
	public function get_status() {
		$adapter = class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) && defined( 'WP_MCP_VERSION' ) && version_compare( WP_MCP_VERSION, '0.5.0', '>=' );
		$abilities = function_exists( 'wp_register_ability' );
		$available = $adapter && $abilities;
		$enabled = ! empty( self::settings()['mcp_enabled'] );
		return array( 'available' => $available, 'enabled' => $enabled, 'version' => $adapter ? WP_MCP_VERSION : '',
			'label' => ! $available ? 'MCP dependencies missing' : ( $enabled ? ( $this->get_exportable_abilities() ? 'Read-only MCP configured' : 'MCP needs approved reads' ) : 'MCP disabled' ),
			'description' => ! $available ? 'Install and activate the official WordPress MCP Adapter (0.5.0+) and use WordPress with the Abilities API (6.9+). Telegram commands and OpenAI chat do not require MCP.' : 'Select approved reads and role grants below. Connect over HTTPS using a WordPress application password. No Telegram linkage or OpenAI key is required. Configuration is not a live connection test.',
			'endpoint' => rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE ),
		);
	}
}
