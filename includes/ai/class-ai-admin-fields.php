<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_AI_Admin_Fields {
	public static function render( $settings ) {
		$usage = Telepilot_OpenAI_Provider::usage();
		?>
		<div class="telepilot-panel telepilot-panel-nested">
			<h3><?php esc_html_e( 'AI Access And Privacy', 'wp-telepilot' ); ?></h3>
			<p><?php esc_html_e( 'AI is opt-in. Granted roles still require WordPress capabilities and an authorized linked private Telegram chat. Save changes before testing the saved key and selected model.', 'wp-telepilot' ); ?></p>
			<fieldset><legend><?php esc_html_e( 'Roles allowed to enter AI mode', 'wp-telepilot' ); ?></legend>
			<input type="hidden" name="telepilot_settings[ai_allowed_roles][]" value="" />
			<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
				<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[ai_allowed_roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $settings['ai_allowed_roles'], true ) ); ?> /><span><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></span></label>
			<?php endforeach; ?>
			</fieldset>
			<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[ai_remove_api_key]" value="1" /><span><?php esc_html_e( 'Remove the saved OpenAI key on save (takes precedence over replacement)', 'wp-telepilot' ); ?></span></label>
			<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[ai_include_email_fields]" value="1" <?php checked( ! empty( $settings['ai_include_email_fields'] ) ); ?> /><span><?php esc_html_e( 'Allow user email fields in approved AI user lookups (sent to OpenAI)', 'wp-telepilot' ); ?></span></label>
			<label class="telepilot-field"><span><?php esc_html_e( 'Site daily provider-request limit (UTC)', 'wp-telepilot' ); ?></span><input type="number" min="1" max="10000" name="telepilot_settings[ai_daily_request_limit]" value="<?php echo esc_attr( $settings['ai_daily_request_limit'] ); ?>" /></label>
			<p><?php echo esc_html( sprintf( 'Today: %d provider requests; %d reported tokens. Includes tests and retries. Requests are capped at 30 seconds and 1,200 output tokens.', $usage['requests'], $usage['tokens'] ) ); ?></p>
			<p><?php esc_html_e( 'Keys require authenticated encryption. Older unauthenticated key formats must be replaced. Security links, credentials and IPs are excluded; email consent is limited to supported fields. Conversation history is short-lived and cleared with /endchat or privacy erasure.', 'wp-telepilot' ); ?></p>
		</div>
		<?php $mcp = Telepilot_MCP_Integration_Service::settings(); ?>
		<div class="telepilot-panel telepilot-panel-nested">
			<h3><?php esc_html_e( 'Independent Read-Only MCP', 'wp-telepilot' ); ?></h3>
			<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[mcp_enabled]" value="1" <?php checked( ! empty( $mcp['mcp_enabled'] ) ); ?> /><span><?php esc_html_e( 'Enable the dedicated MCP server (independent of OpenAI)', 'wp-telepilot' ); ?></span></label>
			<p><code><?php echo esc_html( rest_url( Telepilot_MCP_Integration_Service::REST_NAMESPACE . '/' . Telepilot_MCP_Integration_Service::REST_ROUTE ) ); ?></code></p>
			<p><?php esc_html_e( 'Requires the official WordPress MCP Adapter 0.5.0+ and Abilities API (WordPress 6.9+). Use HTTPS and a WordPress application password. No Telegram linkage is needed. No abilities are exposed until selected below.', 'wp-telepilot' ); ?></p>
			<fieldset><legend><?php esc_html_e( 'MCP role grants', 'wp-telepilot' ); ?></legend><input type="hidden" name="telepilot_settings[mcp_allowed_roles][]" value="" />
			<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
				<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[mcp_allowed_roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $mcp['mcp_allowed_roles'], true ) ); ?> /><span><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></span></label>
			<?php endforeach; ?></fieldset>
			<fieldset><legend><?php esc_html_e( 'Approved MCP read abilities', 'wp-telepilot' ); ?></legend>
			<?php foreach ( Telepilot_MCP_Integration_Service::read_definitions() as $name => $definition ) : ?>
				<label class="telepilot-check"><input type="checkbox" name="telepilot_settings[mcp_allowed_abilities][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $mcp['mcp_allowed_abilities'], true ) ); ?> /><span><?php echo esc_html( $name ); ?></span></label>
			<?php endforeach; ?></fieldset>
		</div>
		<?php
	}
}
