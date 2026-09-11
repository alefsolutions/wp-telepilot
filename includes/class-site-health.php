<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Site_Health {
	public static function register( $tests ) {
		$tests['direct']['telepilot_runtime'] = array( 'label' => 'WP Telepilot runtime', 'test' => array( self::class, 'test' ) );
		return $tests;
	}
	public static function test() {
		global $wpdb;
		$settings = get_option( 'telepilot_settings', array() ); $issues = array();
		$queue_schema = Telepilot_Queue_Schema::status();
		if ( ! $queue_schema['ready'] ) { $issues[] = 'AI continuation queue schema is not verified. ' . $queue_schema['message']; }
		if ( ! empty( $settings['bot_token'] ) && 'webhook' === ( $settings['transport_mode'] ?? 'webhook' ) && empty( $settings['webhook_secret'] ) ) { $issues[] = 'Webhook authentication secret is missing.'; }
		if ( ! wp_next_scheduled( 'telepilot_hourly_maintenance' ) ) { $issues[] = 'Retention maintenance is not scheduled.'; }
		$uncertain = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Telepilot_Jobs_Repository::table_name() . " WHERE status='uncertain'" );
		if ( $uncertain ) { $issues[] = sprintf( '%d uncertain operations need Queue Recovery review.', $uncertain ); }
		if ( Telepilot_AI_Settings::is_enabled() && ! Telepilot_AI_Settings::has_api_key() ) { $issues[] = 'AI is enabled without a decryptable authenticated API key.'; }
		$mcp = ( new Telepilot_MCP_Integration_Service() )->get_status();
		if ( $mcp['enabled'] && ! $mcp['available'] ) { $issues[] = 'MCP is enabled but its official dependencies are unavailable.'; }
		return array( 'label' => $issues ? 'WP Telepilot needs attention' : 'WP Telepilot local runtime checks passed', 'status' => $issues ? 'recommended' : 'good', 'badge' => array( 'label' => 'WP Telepilot', 'color' => 'blue' ), 'description' => '<p>' . esc_html( $issues ? implode( ' ', $issues ) : 'Queue recovery, retention scheduling, and optional-service configuration checks passed. This does not test live Telegram, OpenAI, or MCP delivery.' ) . '</p>', 'actions' => '<p><a href="' . esc_url( admin_url( 'admin.php?page=telepilot' ) ) . '">Open WP Telepilot diagnostics</a></p>', 'test' => 'telepilot_runtime' );
	}
}
