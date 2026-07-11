<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Settings_Page {
	private $page_hook = '';

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_tools_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'update_option_telepress_settings', array( $this, 'handle_settings_updated' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'render_user_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_actions' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_actions' ) );
	}

	public function add_menu_page() {
		$this->page_hook = add_menu_page(
			__( 'TelePress', 'telepress' ),
			__( 'TelePress', 'telepress' ),
			'manage_options',
			'telepress',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			56
		);
	}

	public function register_settings() {
		register_setting(
			'telepress_settings',
			'telepress_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'telepress-admin',
			TELEPRESS_URL . 'assets/css/admin.css',
			array(),
			TELEPRESS_VERSION
		);

		wp_enqueue_script(
			'telepress-admin',
			TELEPRESS_URL . 'assets/js/admin.js',
			array(),
			TELEPRESS_VERSION,
			true
		);
	}

	public function sanitize_settings( $input ) {
		$output                            = array();
		$output['bot_token']              = isset( $input['bot_token'] ) ? sanitize_text_field( $input['bot_token'] ) : '';
		$output['webhook_secret']         = isset( $input['webhook_secret'] ) ? sanitize_text_field( $input['webhook_secret'] ) : '';
		$output['transport_mode']         = isset( $input['transport_mode'] ) && 'polling' === $input['transport_mode'] ? 'polling' : 'webhook';
		$output['allowed_chat_ids']       = isset( $input['allowed_chat_ids'] ) ? sanitize_textarea_field( $input['allowed_chat_ids'] ) : '';
		$output['stale_update_window']    = isset( $input['stale_update_window'] ) ? max( 30, absint( $input['stale_update_window'] ) ) : TelePress_Telegram_Service::DEFAULT_STALE_WINDOW;
		$output['log_retention_days']     = isset( $input['log_retention_days'] ) ? max( 1, absint( $input['log_retention_days'] ) ) : 30;
		$output['rate_limit_per_minute']  = isset( $input['rate_limit_per_minute'] ) ? max( 1, absint( $input['rate_limit_per_minute'] ) ) : 20;
		$output['linking_enabled']        = ! empty( $input['linking_enabled'] ) ? 1 : 0;
		$output['default_notifications']  = isset( $input['default_notifications'] ) && is_array( $input['default_notifications'] )
			? array_map( 'sanitize_text_field', $input['default_notifications'] )
			: array();

		return $output;
	}

	public function render_page() {
		$settings                 = $this->get_settings();
		$webhook_url              = rest_url( TelePress_REST_Webhook_Controller::REST_NAMESPACE . TelePress_REST_Webhook_Controller::ROUTE );
		$webhook_status           = get_option( 'telepress_webhook_status', array() );
		$diagnostics              = get_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$recent_logs              = TelePress_Audit_Log_Repository::recent_logs( 8 );
		$linking_count            = count(
			get_users(
				array(
					'meta_key'     => TelePress_User_Linking_Service::META_TELEGRAM_ID,
					'meta_compare' => 'EXISTS',
					'fields'       => 'ID',
				)
			)
		);
		$last_webhook_log         = TelePress_Audit_Log_Repository::latest_log_by_action( 'webhook_received' );
		$last_delivery_log        = TelePress_Audit_Log_Repository::latest_log_by_action( 'telegram_response_sent' );
		$last_delivery_failure    = TelePress_Audit_Log_Repository::latest_log_by_action( 'telegram_response_failed' );
		$dashboard_service        = new TelePress_Dashboard_Service();
		$dashboard_summary        = $dashboard_service->get_summary();
		$logo_url                 = $this->get_logo_url();
		?>
		<div class="wrap telepress-admin">
			<div class="telepress-shell">
				<section class="telepress-hero">
					<div class="telepress-hero-copy">
						<?php if ( $logo_url ) : ?>
							<img class="telepress-hero-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'TelePress logo', 'telepress' ); ?>" />
						<?php endif; ?>
						<p class="telepress-kicker"><?php esc_html_e( 'Telegram-first WordPress operations', 'telepress' ); ?></p>
						<h1><?php esc_html_e( 'TelePress Control Center', 'telepress' ); ?></h1>
						<p class="telepress-lead">
							<?php esc_html_e( 'Configure secure Telegram access, monitor transport health, and ship a dependable mobile operations companion for WordPress.', 'telepress' ); ?>
						</p>
					</div>
					<div class="telepress-hero-card">
						<span class="telepress-badge"><?php esc_html_e( 'Phase 0 + 1', 'telepress' ); ?></span>
						<ul class="telepress-hero-metrics">
							<li><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong><span><?php esc_html_e( 'WordPress', 'telepress' ); ?></span></li>
							<li><strong><?php echo esc_html( PHP_VERSION ); ?></strong><span><?php esc_html_e( 'PHP', 'telepress' ); ?></span></li>
							<li><strong><?php echo esc_html( (string) $linking_count ); ?></strong><span><?php esc_html_e( 'Linked users', 'telepress' ); ?></span></li>
						</ul>
						<p class="telepress-hero-note"><?php esc_html_e( 'Brand focus: secure linking, reliable transport, and action-first Telegram workflows.', 'telepress' ); ?></p>
					</div>
				</section>

				<div class="telepress-grid">
					<section class="telepress-panel telepress-panel-wide">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Settings', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'Plugin Configuration', 'telepress' ); ?></h2>
							</div>
							<nav class="telepress-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'telepress' ); ?>">
								<button class="telepress-tab is-active" type="button" data-tab="telegram"><?php esc_html_e( 'Telegram', 'telepress' ); ?></button>
								<button class="telepress-tab" type="button" data-tab="security"><?php esc_html_e( 'Security', 'telepress' ); ?></button>
								<button class="telepress-tab" type="button" data-tab="notifications"><?php esc_html_e( 'Notifications', 'telepress' ); ?></button>
								<button class="telepress-tab" type="button" data-tab="logging"><?php esc_html_e( 'Logging', 'telepress' ); ?></button>
								<button class="telepress-tab" type="button" data-tab="linking"><?php esc_html_e( 'Linking', 'telepress' ); ?></button>
							</nav>
						</div>

						<form method="post" action="options.php">
							<?php settings_fields( 'telepress_settings' ); ?>

							<div class="telepress-tab-panel is-active" data-panel="telegram">
								<div class="telepress-field-grid">
									<label class="telepress-field">
										<span><?php esc_html_e( 'Bot Token', 'telepress' ); ?></span>
										<input type="password" name="telepress_settings[bot_token]" value="<?php echo esc_attr( $settings['bot_token'] ); ?>" class="regular-text" autocomplete="off" />
										<small><?php esc_html_e( 'Store the Telegram bot token used for webhook and outbound messages.', 'telepress' ); ?></small>
									</label>
									<label class="telepress-field telepress-field-full">
										<span><?php esc_html_e( 'Webhook URL', 'telepress' ); ?></span>
										<input type="text" readonly value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text code" />
										<small><?php esc_html_e( 'Set this URL in Telegram and pair it with the webhook secret below.', 'telepress' ); ?></small>
									</label>
									<label class="telepress-field telepress-field-full">
										<span><?php esc_html_e( 'Allowed Chat IDs', 'telepress' ); ?></span>
										<textarea name="telepress_settings[allowed_chat_ids]" rows="4"><?php echo esc_textarea( $settings['allowed_chat_ids'] ); ?></textarea>
										<small><?php esc_html_e( 'One chat ID per line, or comma-separated. Leave blank to allow any chat that can authenticate.', 'telepress' ); ?></small>
									</label>
								</div>
							</div>

							<div class="telepress-tab-panel" data-panel="security">
								<div class="telepress-field-grid">
									<label class="telepress-field">
										<span><?php esc_html_e( 'Webhook Secret', 'telepress' ); ?></span>
										<input type="text" name="telepress_settings[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" class="regular-text code" />
										<small><?php esc_html_e( 'TelePress validates Telegram using the X-Telegram-Bot-Api-Secret-Token header.', 'telepress' ); ?></small>
									</label>
									<label class="telepress-field">
										<span><?php esc_html_e( 'Transport Mode', 'telepress' ); ?></span>
										<select name="telepress_settings[transport_mode]">
											<option value="webhook" <?php selected( 'webhook', $settings['transport_mode'] ); ?>><?php esc_html_e( 'Webhook', 'telepress' ); ?></option>
											<option value="polling" <?php selected( 'polling', $settings['transport_mode'] ); ?>><?php esc_html_e( 'Polling Fallback', 'telepress' ); ?></option>
										</select>
										<small><?php esc_html_e( 'Use polling if your host, CDN, or firewall blocks Telegram webhooks with 403 or bot challenges.', 'telepress' ); ?></small>
									</label>
									<label class="telepress-field">
										<span><?php esc_html_e( 'Rate Limit per Minute', 'telepress' ); ?></span>
										<input type="number" min="1" name="telepress_settings[rate_limit_per_minute]" value="<?php echo esc_attr( (string) $settings['rate_limit_per_minute'] ); ?>" />
										<small><?php esc_html_e( 'Reserved for inbound command throttling during MVP hardening.', 'telepress' ); ?></small>
									</label>
									<label class="telepress-field">
										<span><?php esc_html_e( 'Stale Update Window (seconds)', 'telepress' ); ?></span>
										<input type="number" min="30" name="telepress_settings[stale_update_window]" value="<?php echo esc_attr( (string) $settings['stale_update_window'] ); ?>" />
										<small><?php esc_html_e( 'Telegram commands older than this are ignored to prevent delayed reply floods.', 'telepress' ); ?></small>
									</label>
								</div>
							</div>

							<div class="telepress-tab-panel" data-panel="notifications">
								<div class="telepress-checkbox-grid">
									<?php foreach ( $this->notification_options() as $key => $label ) : ?>
										<label class="telepress-check">
											<input type="checkbox" name="telepress_settings[default_notifications][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['default_notifications'], true ) ); ?> />
											<span><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="telepress-tab-panel" data-panel="logging">
								<div class="telepress-field-grid">
									<label class="telepress-field">
										<span><?php esc_html_e( 'Log Retention Days', 'telepress' ); ?></span>
										<input type="number" min="1" name="telepress_settings[log_retention_days]" value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>" />
										<small><?php esc_html_e( 'Controls how long audit records should be retained before cleanup.', 'telepress' ); ?></small>
									</label>
								</div>
							</div>

							<div class="telepress-tab-panel" data-panel="linking">
								<label class="telepress-check telepress-check-inline">
									<input type="checkbox" name="telepress_settings[linking_enabled]" value="1" <?php checked( ! empty( $settings['linking_enabled'] ) ); ?> />
									<span><?php esc_html_e( 'Allow users to link Telegram accounts from their WordPress profile', 'telepress' ); ?></span>
								</label>
								<p class="telepress-inline-note">
									<?php esc_html_e( 'Each user can generate a one-time code from their profile and send `/link CODE` to the bot.', 'telepress' ); ?>
								</p>
							</div>

							<div class="telepress-actions">
								<?php submit_button( __( 'Save TelePress Settings', 'telepress' ), 'primary', 'submit', false ); ?>
							</div>
						</form>
					</section>

					<section class="telepress-panel">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Rollout', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'MVP Readiness', 'telepress' ); ?></h2>
							</div>
						</div>
						<ul class="telepress-status-list">
							<li class="<?php echo $settings['bot_token'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Telegram bot token configured', 'telepress' ); ?></span>
								<strong><?php echo $settings['bot_token'] ? esc_html__( 'Ready', 'telepress' ) : esc_html__( 'Pending', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo $settings['webhook_secret'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Webhook secret set', 'telepress' ); ?></span>
								<strong><?php echo $settings['webhook_secret'] ? esc_html__( 'Ready', 'telepress' ) : esc_html__( 'Pending', 'telepress' ); ?></strong>
							</li>
							<li class="is-good">
								<span><?php esc_html_e( 'Transport mode', 'telepress' ); ?></span>
								<strong><?php echo 'polling' === $settings['transport_mode'] ? esc_html__( 'Polling', 'telepress' ) : esc_html__( 'Webhook', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $settings['linking_enabled'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'User linking enabled', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $settings['linking_enabled'] ) ? esc_html__( 'Active', 'telepress' ) : esc_html__( 'Disabled', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo $last_webhook_log ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Webhook traffic received', 'telepress' ); ?></span>
								<strong><?php echo $last_webhook_log ? esc_html( $this->format_log_time( $last_webhook_log['created_at'] ) ) : esc_html__( 'Waiting', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $webhook_status['ok'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Telegram webhook synced', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $webhook_status['message'] ) ? esc_html( $webhook_status['message'] ) : esc_html__( 'Pending', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo $last_delivery_log ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last Telegram delivery', 'telepress' ); ?></span>
								<strong><?php echo $last_delivery_log ? esc_html( $this->format_log_time( $last_delivery_log['created_at'] ) ) : esc_html__( 'Not sent yet', 'telepress' ); ?></strong>
							</li>
						</ul>
						<?php if ( $last_delivery_failure ) : ?>
							<p class="telepress-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: relative log time. */
										__( 'Most recent delivery failure: %s', 'telepress' ),
										$this->format_log_time( $last_delivery_failure['created_at'] )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $webhook_status['error'] ) ) : ?>
							<p class="telepress-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: webhook error message. */
										__( 'Webhook note: %s', 'telepress' ),
										$webhook_status['error']
									)
								);
								?>
							</p>
						<?php endif; ?>
						<p class="telepress-inline-note">
							<?php esc_html_e( 'Webhook mode is preferred for real-time replies. Polling fallback depends on WP-Cron and can delay messages if your host does not trigger cron promptly.', 'telepress' ); ?>
						</p>
					</section>

					<section class="telepress-panel">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Snapshot', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'Dashboard Preview', 'telepress' ); ?></h2>
							</div>
						</div>
						<ul class="telepress-status-list">
							<li class="is-good">
								<span><?php esc_html_e( 'Site health', 'telepress' ); ?></span>
								<strong><?php echo esc_html( $dashboard_summary['site_health'] ); ?></strong>
							</li>
							<li class="is-good">
								<span><?php esc_html_e( 'Draft posts', 'telepress' ); ?></span>
								<strong><?php echo esc_html( (string) $dashboard_summary['draft_posts_count'] ); ?></strong>
							</li>
							<li class="<?php echo $dashboard_summary['pending_comments'] > 0 ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Pending comments', 'telepress' ); ?></span>
								<strong><?php echo esc_html( (string) $dashboard_summary['pending_comments'] ); ?></strong>
							</li>
							<li class="<?php echo array_sum( $dashboard_summary['pending_updates'] ) > 0 ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Available updates', 'telepress' ); ?></span>
								<strong><?php echo esc_html( (string) array_sum( $dashboard_summary['pending_updates'] ) ); ?></strong>
							</li>
						</ul>
					</section>

					<section class="telepress-panel telepress-panel-wide">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Diagnostics', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'Transport Health', 'telepress' ); ?></h2>
							</div>
						</div>
						<ul class="telepress-status-list">
							<li class="is-good">
								<span><?php esc_html_e( 'Last processed transport', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_transport'] ) ? esc_html( strtoupper( (string) $diagnostics['last_transport'] ) ) : esc_html__( 'Unknown', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_webhook_received_at'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last webhook received', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_webhook_received_at'] ) ? esc_html( $this->format_timestamp( (int) $diagnostics['last_webhook_received_at'] ) ) : esc_html__( 'Never', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_webhook_auth_status'] ) && 'success' === $diagnostics['last_webhook_auth_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last webhook auth result', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_webhook_auth_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_webhook_auth_status'] ) ) : esc_html__( 'Unknown', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_poll_at'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last poll run', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_poll_at'] ) ? esc_html( $this->format_timestamp( (int) $diagnostics['last_poll_at'] ) ) : esc_html__( 'Never', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_poll_status'] ) && 'failed' !== $diagnostics['last_poll_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last poll status', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_poll_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_poll_status'] ) ) : esc_html__( 'Unknown', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['last_send_error_message'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last Telegram send error', 'telepress' ); ?></span>
								<strong><?php echo empty( $diagnostics['last_send_error_message'] ) ? esc_html__( 'None', 'telepress' ) : esc_html__( 'Present', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['last_link_attempt_status'] ) || 'success' === $diagnostics['last_link_attempt_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last link attempt', 'telepress' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_link_attempt_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_link_attempt_status'] ) ) : esc_html__( 'Unknown', 'telepress' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['stale_updates_dropped'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Stale updates dropped', 'telepress' ); ?></span>
								<strong><?php echo esc_html( (string) ( isset( $diagnostics['stale_updates_dropped'] ) ? (int) $diagnostics['stale_updates_dropped'] : 0 ) ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['duplicate_updates_ignored'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Duplicate updates ignored', 'telepress' ); ?></span>
								<strong><?php echo esc_html( (string) ( isset( $diagnostics['duplicate_updates_ignored'] ) ? (int) $diagnostics['duplicate_updates_ignored'] : 0 ) ); ?></strong>
							</li>
						</ul>
						<?php if ( ! empty( $diagnostics['last_webhook_auth_error'] ) ) : ?>
							<p class="telepress-inline-note"><?php echo esc_html( sprintf( __( 'Last webhook auth error: %s', 'telepress' ), $diagnostics['last_webhook_auth_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_poll_error'] ) ) : ?>
							<p class="telepress-inline-note"><?php echo esc_html( sprintf( __( 'Last poll error: %s', 'telepress' ), $diagnostics['last_poll_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_send_error_message'] ) ) : ?>
							<p class="telepress-inline-note"><?php echo esc_html( sprintf( __( 'Last Telegram API send error: %s', 'telepress' ), $diagnostics['last_send_error_message'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_link_attempt_detail'] ) ) : ?>
							<p class="telepress-inline-note"><?php echo esc_html( sprintf( __( 'Last link attempt detail: %s', 'telepress' ), $diagnostics['last_link_attempt_detail'] ) ); ?></p>
						<?php endif; ?>
						<div class="telepress-actions">
							<form method="post">
								<?php wp_nonce_field( 'telepress_tools_actions', 'telepress_tools_nonce' ); ?>
								<button class="button button-secondary" type="submit" name="telepress_poll_now" value="1"><?php esc_html_e( 'Poll Now', 'telepress' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepress_refresh_webhook_status" value="1"><?php esc_html_e( 'Refresh Webhook Status', 'telepress' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepress_flush_updates" value="1"><?php esc_html_e( 'Flush Old Updates', 'telepress' ); ?></button>
							</form>
						</div>
					</section>

					<section class="telepress-panel telepress-panel-wide">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Quick Start', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'First-Time Setup', 'telepress' ); ?></h2>
							</div>
						</div>
						<ol class="telepress-setup-list">
							<li><?php esc_html_e( 'Create a Telegram bot with BotFather, then paste the bot token into the Telegram tab.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'Choose Webhook for direct delivery or Polling Fallback if your host blocks Telegram with 403 or bot protection.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'If you use Webhook mode, TelePress will register the webhook URL and webhook secret with Telegram when you save settings.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'Message the bot with /start or /chatid to reveal your current Telegram chat ID.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'Add that chat ID to Allowed Chat IDs if you want to restrict access to specific chats.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'Generate a one-time link code from your WordPress profile, then send /link CODE in Telegram.', 'telepress' ); ?></li>
							<li><?php esc_html_e( 'Use /menu for the guided command hub or /site for the site overview once your account is linked.', 'telepress' ); ?></li>
						</ol>
					</section>

					<section class="telepress-panel">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Command Surface', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'Primary Telegram Commands', 'telepress' ); ?></h2>
							</div>
						</div>
						<ul class="telepress-setup-list">
							<li><code>/start</code> <?php esc_html_e( 'Starts onboarding and confirms the current chat ID.', 'telepress' ); ?></li>
							<li><code>/menu</code> <?php esc_html_e( 'Opens the guided TelePress command hub.', 'telepress' ); ?></li>
							<li><code>/site</code> <?php esc_html_e( 'Shows the site overview and operational shortcuts.', 'telepress' ); ?></li>
							<li><code>/help</code> <?php esc_html_e( 'Lists the available command surface for the linked account.', 'telepress' ); ?></li>
							<li><code>/link CODE</code> <?php esc_html_e( 'Links Telegram to a WordPress user in private chat.', 'telepress' ); ?></li>
							<li><code>/unlink</code> <?php esc_html_e( 'Removes the Telegram link and revokes access.', 'telepress' ); ?></li>
						</ul>
					</section>

					<section class="telepress-panel telepress-panel-wide">
						<div class="telepress-panel-heading">
							<div>
								<p class="telepress-section-label"><?php esc_html_e( 'Audit Trail', 'telepress' ); ?></p>
								<h2><?php esc_html_e( 'Recent Activity', 'telepress' ); ?></h2>
							</div>
						</div>
						<?php if ( empty( $recent_logs ) ) : ?>
							<p class="telepress-empty-state"><?php esc_html_e( 'No audit records yet. Webhook activity and account linking events will appear here.', 'telepress' ); ?></p>
						<?php else : ?>
							<table class="widefat fixed striped telepress-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Time', 'telepress' ); ?></th>
										<th><?php esc_html_e( 'Action', 'telepress' ); ?></th>
										<th><?php esc_html_e( 'Chat', 'telepress' ); ?></th>
										<th><?php esc_html_e( 'Result', 'telepress' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recent_logs as $log ) : ?>
										<tr>
											<td><?php echo esc_html( get_date_from_gmt( $log['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
											<td><?php echo esc_html( $log['action_name'] ); ?></td>
											<td><?php echo esc_html( $log['chat_id'] ? $log['chat_id'] : '-' ); ?></td>
											<td>
												<span class="telepress-status-pill <?php echo ! empty( $log['was_successful'] ) ? 'is-good' : 'is-bad'; ?>">
													<?php echo ! empty( $log['was_successful'] ) ? esc_html__( 'Success', 'telepress' ) : esc_html__( 'Failed', 'telepress' ); ?>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_user_profile_section( $user ) {
		$settings = $this->get_settings();

		if ( empty( $settings['linking_enabled'] ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$linking_service = new TelePress_User_Linking_Service();
		$telegram_id     = get_user_meta( $user->ID, TelePress_User_Linking_Service::META_TELEGRAM_ID, true );
		$telegram_chat   = get_user_meta( $user->ID, TelePress_User_Linking_Service::META_TELEGRAM_CHAT, true );
		$link_code       = $linking_service->get_active_link_code( $user->ID );
		$expires         = (int) get_user_meta( $user->ID, TelePress_User_Linking_Service::META_LINK_EXPIRES, true );
		?>
		<h2><?php esc_html_e( 'TelePress', 'telepress' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="telepress-link-code"><?php esc_html_e( 'Linking Code', 'telepress' ); ?></label></th>
				<td>
					<?php if ( $link_code && $expires > time() ) : ?>
						<input id="telepress-link-code" type="text" class="regular-text code" readonly value="<?php echo esc_attr( $link_code ); ?>" />
						<p class="description"><?php esc_html_e( 'Send `/link CODE` to your TelePress bot within the next hour. TelePress stores only the hashed version of this code on the server.', 'telepress' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Generate a one-time code below to link your Telegram account.', 'telepress' ); ?></p>
					<?php endif; ?>
					<?php wp_nonce_field( 'telepress_profile_actions', 'telepress_profile_nonce' ); ?>
					<p><button class="button" type="submit" name="telepress_generate_link_code" value="1"><?php esc_html_e( 'Generate Link Code', 'telepress' ); ?></button></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Linked Telegram', 'telepress' ); ?></th>
				<td>
					<p><?php echo $telegram_id ? esc_html( $telegram_id ) : esc_html__( 'Not linked yet.', 'telepress' ); ?></p>
					<?php if ( $telegram_chat ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Chat ID: %s', 'telepress' ), $telegram_chat ) ); ?></p>
					<?php endif; ?>
					<?php if ( $telegram_id ) : ?>
						<p><button class="button button-secondary" type="submit" name="telepress_unlink_telegram" value="1"><?php esc_html_e( 'Unlink Telegram', 'telepress' ); ?></button></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_user_profile_actions( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( empty( $_POST['telepress_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['telepress_profile_nonce'] ) ), 'telepress_profile_actions' ) ) {
			return;
		}

		$linking_service = new TelePress_User_Linking_Service();

		if ( ! empty( $_POST['telepress_unlink_telegram'] ) ) {
			$linking_service->unlink_user( $user_id );
			return;
		}

		if ( empty( $_POST['telepress_generate_link_code'] ) ) {
			return;
		}

		$linking_service->generate_link_code( $user_id );
	}

	public function handle_settings_updated( $old_value, $value ) {
		$settings = wp_parse_args(
			$value,
			array(
				'bot_token'       => '',
				'webhook_secret'  => '',
				'transport_mode'  => 'webhook',
				'stale_update_window' => TelePress_Telegram_Service::DEFAULT_STALE_WINDOW,
			)
		);

		if ( empty( $settings['bot_token'] ) ) {
			delete_option( 'telepress_webhook_status' );
			delete_transient( 'telepress_admin_notice' );
			$this->sync_transport_schedule( $settings['transport_mode'] );
			return;
		}

		$client = new TelePress_Telegram_Client( (string) $settings['bot_token'] );
		$command_result = $client->set_commands( $this->telegram_commands() );
		$transport_mode = $settings['transport_mode'];
		$webhook_result = null;

		$this->sync_transport_schedule( $transport_mode );

		if ( 'polling' === $transport_mode ) {
			$delete_result = $client->delete_webhook();
			update_option(
				'telepress_webhook_status',
				array(
					'ok'        => ! is_wp_error( $delete_result ) ? 1 : 0,
					'message'   => __( 'Polling mode active', 'telepress' ),
					'error'     => is_wp_error( $delete_result ) ? $delete_result->get_error_message() : '',
					'synced_at' => time(),
				),
				false
			);
		} else {
			$webhook_result = $client->set_webhook(
				rest_url( TelePress_REST_Webhook_Controller::REST_NAMESPACE . TelePress_REST_Webhook_Controller::ROUTE ),
				(string) $settings['webhook_secret']
			);
		}

		if ( 'webhook' === $transport_mode && is_wp_error( $webhook_result ) ) {
			update_option(
				'telepress_webhook_status',
				array(
					'ok'        => 0,
					'message'   => __( 'Sync failed', 'telepress' ),
					'error'     => $webhook_result->get_error_message(),
					'synced_at' => time(),
				),
				false
			);
		} elseif ( 'webhook' === $transport_mode ) {
			$webhook_info = $client->get_webhook_info();
			$status_label = __( 'Registered', 'telepress' );
			$status_error = '';

			if ( is_wp_error( $webhook_info ) ) {
				$status_label = __( 'Registered', 'telepress' );
				$status_error = $webhook_info->get_error_message();
			} elseif ( ! empty( $webhook_info['result']['last_error_message'] ) ) {
				$status_label = __( 'Registered with warning', 'telepress' );
				$status_error = (string) $webhook_info['result']['last_error_message'];
			}

			update_option(
				'telepress_webhook_status',
				array(
					'ok'              => 1,
					'message'         => $status_label,
					'error'           => $status_error,
					'synced_at'       => time(),
					'telegram_result' => is_array( $webhook_info ) && isset( $webhook_info['result'] ) ? $webhook_info['result'] : array(),
				),
				false
			);
		}

		if ( is_wp_error( $command_result ) || ( 'webhook' === $transport_mode && is_wp_error( $webhook_result ) ) ) {
			$errors = array();

			if ( is_wp_error( $command_result ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message. */
					__( 'command sync failed: %s', 'telepress' ),
					$command_result->get_error_message()
				);
			}

			if ( 'webhook' === $transport_mode && is_wp_error( $webhook_result ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message. */
					__( 'webhook registration failed: %s', 'telepress' ),
					$webhook_result->get_error_message()
				);
			}

			set_transient(
				'telepress_admin_notice',
				array(
					'type'    => 'error',
					'message' => sprintf( __( 'TelePress saved your settings, but Telegram sync had issues: %s', 'telepress' ), implode( '; ', $errors ) ),
				),
				60
			);

			return;
		}

		set_transient(
			'telepress_admin_notice',
			array(
				'type'    => 'success',
				'message' => 'polling' === $transport_mode
					? __( 'TelePress settings saved, Telegram slash commands synced, and polling fallback is now active.', 'telepress' )
					: __( 'TelePress settings saved, Telegram slash commands synced, and the webhook was registered successfully.', 'telepress' ),
			),
			60
		);
	}

	public function render_admin_notice() {
		if ( empty( $_GET['page'] ) || 'telepress' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$notice = get_transient( 'telepress_admin_notice' );

		if ( empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'telepress_admin_notice' );
		$type = 'error' === $notice['type'] ? 'notice notice-error' : 'notice notice-success';
		?>
		<div class="<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	private function get_settings() {
		$defaults = array(
			'bot_token'             => '',
			'webhook_secret'        => '',
			'transport_mode'        => 'webhook',
			'stale_update_window'   => TelePress_Telegram_Service::DEFAULT_STALE_WINDOW,
			'allowed_chat_ids'      => '',
			'default_notifications' => array(),
			'log_retention_days'    => 30,
			'rate_limit_per_minute' => 20,
			'linking_enabled'       => 1,
		);

		return wp_parse_args( get_option( 'telepress_settings', array() ), $defaults );
	}

	private function sync_transport_schedule( $transport_mode ) {
		if ( 'polling' === $transport_mode ) {
			if ( ! wp_next_scheduled( 'telepress_poll_updates' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'telepress_every_minute', 'telepress_poll_updates' );
			}

			return;
		}

		wp_clear_scheduled_hook( 'telepress_poll_updates' );
	}

	private function notification_options() {
		return array(
			'new_post_published' => __( 'New post published', 'telepress' ),
			'new_comment'        => __( 'New comment', 'telepress' ),
			'failed_login'       => __( 'Failed login', 'telepress' ),
			'plugin_updates'     => __( 'Plugin updates', 'telepress' ),
			'theme_updates'      => __( 'Theme updates', 'telepress' ),
			'core_updates'       => __( 'Core updates', 'telepress' ),
		);
	}

	private function telegram_commands() {
		return array(
			array(
				'command'     => 'start',
				'description' => __( 'Connect TelePress and view first steps', 'telepress' ),
			),
			array(
				'command'     => 'help',
				'description' => __( 'Show available commands', 'telepress' ),
			),
			array(
				'command'     => 'menu',
				'description' => __( 'Open the TelePress command hub', 'telepress' ),
			),
			array(
				'command'     => 'site',
				'description' => __( 'View the site overview and shortcuts', 'telepress' ),
			),
			array(
				'command'     => 'settings',
				'description' => __( 'Open TelePress settings information', 'telepress' ),
			),
			array(
				'command'     => 'chatid',
				'description' => __( 'Show the current Telegram chat ID', 'telepress' ),
			),
			array(
				'command'     => 'link',
				'description' => __( 'Link Telegram to your WordPress account', 'telepress' ),
			),
			array(
				'command'     => 'unlink',
				'description' => __( 'Unlink Telegram from your WordPress account', 'telepress' ),
			),
			array(
				'command'     => 'dashboard',
				'description' => __( 'Legacy alias for the site overview', 'telepress' ),
			),
			array(
				'command'     => 'comments',
				'description' => __( 'Review and moderate comments', 'telepress' ),
			),
			array(
				'command'     => 'posts',
				'description' => __( 'List, search, and publish posts', 'telepress' ),
			),
			array(
				'command'     => 'pages',
				'description' => __( 'Manage pages', 'telepress' ),
			),
			array(
				'command'     => 'media',
				'description' => __( 'Review or upload media', 'telepress' ),
			),
			array(
				'command'     => 'users',
				'description' => __( 'Manage WordPress users', 'telepress' ),
			),
			array(
				'command'     => 'categories',
				'description' => __( 'List and manage post categories', 'telepress' ),
			),
			array(
				'command'     => 'tags',
				'description' => __( 'List and manage post tags', 'telepress' ),
			),
		);
	}

	private function format_log_time( $gmt_datetime ) {
		$timestamp = mysql2date( 'U', $gmt_datetime, true );

		if ( ! $timestamp ) {
			return __( 'Unknown', 'telepress' );
		}

		return sprintf(
			/* translators: 1: local datetime, 2: human relative time. */
			__( '%1$s (%2$s ago)', 'telepress' ),
			wp_date( 'Y-m-d H:i:s', $timestamp ),
			human_time_diff( $timestamp, current_time( 'timestamp', true ) )
		);
	}

	private function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return __( 'Unknown', 'telepress' );
		}

		return sprintf(
			__( '%1$s (%2$s ago)', 'telepress' ),
			wp_date( 'Y-m-d H:i:s', $timestamp ),
			human_time_diff( $timestamp, current_time( 'timestamp', true ) )
		);
	}

	public function handle_tools_actions() {
		if ( empty( $_GET['page'] ) || 'telepress' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( empty( $_POST['telepress_tools_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['telepress_tools_nonce'] ) ), 'telepress_tools_actions' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$client   = new TelePress_Telegram_Client( (string) $settings['bot_token'] );

		if ( ! empty( $_POST['telepress_poll_now'] ) ) {
			if ( 'polling' !== $settings['transport_mode'] ) {
				$this->set_tools_notice( 'error', __( 'Poll Now is only available when Transport Mode is set to Polling Fallback.', 'telepress' ) );
				return;
			}

			$telegram = new TelePress_Telegram_Service();
			$result   = $telegram->poll_updates();
			$this->set_tools_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'TelePress polled Telegram successfully.', 'telepress' )
			);
			return;
		}

		if ( ! empty( $_POST['telepress_refresh_webhook_status'] ) ) {
			$result = $client->get_webhook_info();
			if ( is_wp_error( $result ) ) {
				$this->set_tools_notice( 'error', $result->get_error_message() );
				return;
			}

			update_option(
				'telepress_webhook_status',
				array(
					'ok'              => 1,
					'message'         => __( 'Refreshed', 'telepress' ),
					'error'           => ! empty( $result['result']['last_error_message'] ) ? (string) $result['result']['last_error_message'] : '',
					'synced_at'       => time(),
					'telegram_result' => isset( $result['result'] ) ? $result['result'] : array(),
				),
				false
			);
			$this->set_tools_notice( 'success', __( 'Webhook status refreshed.', 'telepress' ) );
			return;
		}

		if ( ! empty( $_POST['telepress_flush_updates'] ) ) {
			$telegram = new TelePress_Telegram_Service();
			$result   = $telegram->flush_pending_updates( $settings['transport_mode'] );
			$this->set_tools_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'Queued Telegram updates were flushed.', 'telepress' )
			);
		}
	}

	private function set_tools_notice( $type, $message ) {
		set_transient(
			'telepress_admin_notice',
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	private function get_logo_url() {
		$logo_path = TELEPRESS_PATH . 'TELEPRESS LOGO.png';

		if ( ! file_exists( $logo_path ) ) {
			return '';
		}

		return str_replace( ' ', '%20', TELEPRESS_URL . 'TELEPRESS LOGO.png' );
	}
}
