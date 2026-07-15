<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Settings_Page {
	private $page_hook = '';

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_tools_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'update_option_telepilot_settings', array( $this, 'handle_settings_updated' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'render_user_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_actions' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_actions' ) );
	}

	public function add_menu_page() {
		$this->page_hook = add_menu_page(
			__( 'WP Telepilot', 'telepilot' ),
			__( 'WP Telepilot', 'telepilot' ),
			'manage_options',
			'telepilot',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			56
		);
	}

	public function register_settings() {
		register_setting(
			'telepilot_settings',
			'telepilot_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'telepilot-admin',
			TELEPILOT_URL . 'assets/css/admin.css',
			array(),
			TELEPILOT_VERSION
		);

		wp_enqueue_script(
			'telepilot-admin',
			TELEPILOT_URL . 'assets/js/admin.js',
			array(),
			TELEPILOT_VERSION,
			true
		);
	}

	public function sanitize_settings( $input ) {
		$existing                          = get_option( 'telepilot_settings', array() );
		$output                            = array();
		$output['bot_token']              = isset( $input['bot_token'] ) ? sanitize_text_field( $input['bot_token'] ) : '';
		$output['webhook_secret']         = isset( $input['webhook_secret'] ) ? sanitize_text_field( $input['webhook_secret'] ) : '';
		$output['worker_secret']          = ! empty( $existing['worker_secret'] ) ? sanitize_text_field( (string) $existing['worker_secret'] ) : wp_generate_password( 32, false, false );
		$output['transport_mode']         = isset( $input['transport_mode'] ) && 'polling' === $input['transport_mode'] ? 'polling' : 'webhook';
		$output['allowed_chat_ids']       = isset( $input['allowed_chat_ids'] ) ? sanitize_textarea_field( $input['allowed_chat_ids'] ) : '';
		$output['stale_update_window']    = isset( $input['stale_update_window'] ) ? max( 30, absint( $input['stale_update_window'] ) ) : Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW;
		$output['log_retention_days']     = isset( $input['log_retention_days'] ) ? max( 1, absint( $input['log_retention_days'] ) ) : 30;
		$output['rate_limit_per_minute']  = isset( $input['rate_limit_per_minute'] ) ? max( 1, absint( $input['rate_limit_per_minute'] ) ) : 20;
		$output['linking_enabled']        = ! empty( $input['linking_enabled'] ) ? 1 : 0;
		$output['cleanup_on_uninstall']   = ! empty( $input['cleanup_on_uninstall'] ) ? 1 : 0;
		$output['default_notifications']  = isset( $input['default_notifications'] ) && is_array( $input['default_notifications'] )
			? array_map( 'sanitize_text_field', $input['default_notifications'] )
			: array();

		return $output;
	}

	public function render_page() {
		$settings                 = $this->get_settings();
		$webhook_url              = rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::ROUTE );
		$worker_url               = rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::WORKER_ROUTE );
		$webhook_status           = get_option( 'telepilot_webhook_status', array() );
		$diagnostics              = get_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$command_diagnostics      = get_option( Telepilot_Telegram_Service::COMMAND_DIAGNOSTICS_OPTION, array() );
		$recent_logs              = Telepilot_Audit_Log_Repository::recent_logs( 25 );
		$linking_count            = count(
			get_users(
				array(
					'meta_key'     => Telepilot_User_Linking_Service::META_TELEGRAM_ID,
					'meta_compare' => 'EXISTS',
					'fields'       => 'ID',
				)
			)
		);
		$last_webhook_log         = Telepilot_Audit_Log_Repository::latest_log_by_action( 'webhook_received' );
		$last_delivery_log        = Telepilot_Audit_Log_Repository::latest_log_by_action( 'telegram_response_sent' );
		$last_delivery_failure    = Telepilot_Audit_Log_Repository::latest_log_by_action( 'telegram_response_failed' );
		$dashboard_service        = new Telepilot_Dashboard_Service();
		$dashboard_summary        = $dashboard_service->get_summary();
		$job_counts               = Telepilot_Jobs_Repository::status_counts();
		$queued_job_count         = (int) $job_counts['pending'] + (int) $job_counts['processing'];
		$schema_version           = (string) get_option( 'telepilot_schema_version', __( 'Unknown', 'telepilot' ) );
		$next_daily_maintenance   = wp_next_scheduled( 'telepilot_daily_maintenance' );
		$next_poll_run            = wp_next_scheduled( 'telepilot_poll_updates' );
		$next_job_run             = wp_next_scheduled( 'telepilot_process_jobs' );
		$poll_lock_active         = (bool) get_transient( Telepilot_Telegram_Service::POLL_LOCK_TRANSIENT );
		$table_status             = $this->get_database_table_status();
		$logo_url                 = $this->get_logo_url();
		$product_name             = __( 'WP Telepilot', 'telepilot' );
		$product_version          = TELEPILOT_VERSION;
		?>
		<div class="wrap telepilot-admin">
			<div class="telepilot-shell">
				<section class="telepilot-hero">
					<div class="telepilot-hero-copy">
						<?php if ( $logo_url ) : ?>
							<img class="telepilot-hero-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WP Telepilot logo', 'telepilot' ); ?>" />
						<?php endif; ?>
						<p class="telepilot-kicker"><?php esc_html_e( 'Telegram-first WordPress operations', 'telepilot' ); ?></p>
						<h1><?php echo esc_html( $product_name ); ?><?php esc_html_e( ' Control Center', 'telepilot' ); ?></h1>
						<div class="telepilot-hero-meta">
							<span class="telepilot-status-pill is-good"><?php echo esc_html( sprintf( __( 'Version %s', 'telepilot' ), $product_version ) ); ?></span>
						</div>
						<p class="telepilot-lead">
							<?php esc_html_e( 'Configure secure Telegram access, monitor transport health, and run WordPress operations from chat with a cleaner command experience.', 'telepilot' ); ?>
						</p>
					</div>
					<div class="telepilot-hero-card">
						<span class="telepilot-badge"><?php echo esc_html( sprintf( __( 'Release %s', 'telepilot' ), $product_version ) ); ?></span>
						<ul class="telepilot-hero-metrics">
							<li><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong><span><?php esc_html_e( 'WordPress', 'telepilot' ); ?></span></li>
							<li><strong><?php echo esc_html( PHP_VERSION ); ?></strong><span><?php esc_html_e( 'PHP', 'telepilot' ); ?></span></li>
							<li><strong><?php echo esc_html( (string) $linking_count ); ?></strong><span><?php esc_html_e( 'Linked users', 'telepilot' ); ?></span></li>
						</ul>
						<p class="telepilot-hero-note"><?php esc_html_e( 'Release focus: secure linking, reliable transport, and action-first Telegram workflows.', 'telepilot' ); ?></p>
					</div>
				</section>

				<div class="telepilot-grid">
					<section class="telepilot-panel telepilot-panel-wide">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Settings', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Plugin Configuration', 'telepilot' ); ?></h2>
							</div>
							<nav class="telepilot-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'telepilot' ); ?>">
								<button class="telepilot-tab is-active" type="button" data-tab="telegram"><?php esc_html_e( 'Telegram', 'telepilot' ); ?></button>
								<button class="telepilot-tab" type="button" data-tab="security"><?php esc_html_e( 'Security', 'telepilot' ); ?></button>
								<button class="telepilot-tab" type="button" data-tab="notifications"><?php esc_html_e( 'Notifications', 'telepilot' ); ?></button>
								<button class="telepilot-tab" type="button" data-tab="logging"><?php esc_html_e( 'Logging', 'telepilot' ); ?></button>
								<button class="telepilot-tab" type="button" data-tab="linking"><?php esc_html_e( 'Linking', 'telepilot' ); ?></button>
							</nav>
						</div>

						<form method="post" action="options.php">
							<?php settings_fields( 'telepilot_settings' ); ?>

							<div class="telepilot-tab-panel is-active" data-panel="telegram">
								<div class="telepilot-field-grid">
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Bot Token', 'telepilot' ); ?></span>
										<input type="password" name="telepilot_settings[bot_token]" value="<?php echo esc_attr( $settings['bot_token'] ); ?>" class="regular-text" autocomplete="off" />
										<small><?php esc_html_e( 'Store the Telegram bot token used for webhook and outbound messages.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field telepilot-field-full">
										<span><?php esc_html_e( 'Webhook URL', 'telepilot' ); ?></span>
										<input type="text" readonly value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text code" />
										<small><?php esc_html_e( 'Set this URL in Telegram and pair it with the webhook secret below.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field telepilot-field-full">
										<span><?php esc_html_e( 'Allowed Chat IDs', 'telepilot' ); ?></span>
										<textarea name="telepilot_settings[allowed_chat_ids]" rows="4"><?php echo esc_textarea( $settings['allowed_chat_ids'] ); ?></textarea>
										<small><?php esc_html_e( 'One chat ID per line, or comma-separated. Leave blank to allow any chat that can authenticate.', 'telepilot' ); ?></small>
									</label>
								</div>
							</div>

							<div class="telepilot-tab-panel" data-panel="security">
								<div class="telepilot-field-grid">
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Webhook Secret', 'telepilot' ); ?></span>
										<input type="text" name="telepilot_settings[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" class="regular-text code" />
										<small><?php esc_html_e( 'WP Telepilot validates Telegram using the X-Telegram-Bot-Api-Secret-Token header.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field telepilot-field-full">
										<span><?php esc_html_e( 'Async Worker Endpoint', 'telepilot' ); ?></span>
										<input type="text" readonly value="<?php echo esc_attr( $worker_url ); ?>" class="regular-text code" />
										<small><?php esc_html_e( 'WP Telepilot uses this private REST endpoint internally for background job processing with a generated worker secret.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Transport Mode', 'telepilot' ); ?></span>
										<select name="telepilot_settings[transport_mode]">
											<option value="webhook" <?php selected( 'webhook', $settings['transport_mode'] ); ?>><?php esc_html_e( 'Webhook', 'telepilot' ); ?></option>
											<option value="polling" <?php selected( 'polling', $settings['transport_mode'] ); ?>><?php esc_html_e( 'Polling Fallback', 'telepilot' ); ?></option>
										</select>
										<small><?php esc_html_e( 'Use polling if your host, CDN, or firewall blocks Telegram webhooks with 403 or bot challenges.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Rate Limit per Minute', 'telepilot' ); ?></span>
										<input type="number" min="1" name="telepilot_settings[rate_limit_per_minute]" value="<?php echo esc_attr( (string) $settings['rate_limit_per_minute'] ); ?>" />
										<small><?php esc_html_e( 'Reserved for inbound command throttling and future hardening controls.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Stale Update Window (seconds)', 'telepilot' ); ?></span>
										<input type="number" min="30" name="telepilot_settings[stale_update_window]" value="<?php echo esc_attr( (string) $settings['stale_update_window'] ); ?>" />
										<small><?php esc_html_e( 'Telegram commands older than this are ignored to prevent delayed reply floods.', 'telepilot' ); ?></small>
									</label>
								</div>
							</div>

							<div class="telepilot-tab-panel" data-panel="notifications">
								<div class="telepilot-checkbox-grid">
									<?php foreach ( $this->notification_options() as $key => $label ) : ?>
										<label class="telepilot-check">
											<input type="checkbox" name="telepilot_settings[default_notifications][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['default_notifications'], true ) ); ?> />
											<span><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="telepilot-tab-panel" data-panel="logging">
								<div class="telepilot-field-grid">
									<label class="telepilot-field">
										<span><?php esc_html_e( 'Log Retention Days', 'telepilot' ); ?></span>
										<input type="number" min="1" name="telepilot_settings[log_retention_days]" value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>" />
										<small><?php esc_html_e( 'Controls how long audit records should be retained before cleanup.', 'telepilot' ); ?></small>
									</label>
									<label class="telepilot-check telepilot-check-inline">
										<input type="checkbox" name="telepilot_settings[cleanup_on_uninstall]" value="1" <?php checked( ! empty( $settings['cleanup_on_uninstall'] ) ); ?> />
										<span><?php esc_html_e( 'Delete WP Telepilot data when the plugin is uninstalled', 'telepilot' ); ?></span>
									</label>
								</div>
							</div>

							<div class="telepilot-tab-panel" data-panel="linking">
								<label class="telepilot-check telepilot-check-inline">
									<input type="checkbox" name="telepilot_settings[linking_enabled]" value="1" <?php checked( ! empty( $settings['linking_enabled'] ) ); ?> />
									<span><?php esc_html_e( 'Allow users to link Telegram accounts from their WordPress profile', 'telepilot' ); ?></span>
								</label>
								<p class="telepilot-inline-note">
									<?php esc_html_e( 'Each user can generate a one-time code from their profile and send `/link CODE` to the bot.', 'telepilot' ); ?>
								</p>
							</div>

							<div class="telepilot-actions">
								<?php submit_button( __( 'Save WP Telepilot Settings', 'telepilot' ), 'primary', 'submit', false ); ?>
							</div>
						</form>
					</section>

					<section class="telepilot-panel">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Operations', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'System Status', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ul class="telepilot-status-list">
							<li class="<?php echo $settings['bot_token'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Telegram bot token configured', 'telepilot' ); ?></span>
								<strong><?php echo $settings['bot_token'] ? esc_html__( 'Ready', 'telepilot' ) : esc_html__( 'Pending', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo $settings['webhook_secret'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Webhook secret set', 'telepilot' ); ?></span>
								<strong><?php echo $settings['webhook_secret'] ? esc_html__( 'Ready', 'telepilot' ) : esc_html__( 'Pending', 'telepilot' ); ?></strong>
							</li>
							<li class="is-good">
								<span><?php esc_html_e( 'Transport mode', 'telepilot' ); ?></span>
								<strong><?php echo 'polling' === $settings['transport_mode'] ? esc_html__( 'Polling', 'telepilot' ) : esc_html__( 'Webhook', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $settings['linking_enabled'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'User linking enabled', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $settings['linking_enabled'] ) ? esc_html__( 'Active', 'telepilot' ) : esc_html__( 'Disabled', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo $last_webhook_log ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Webhook traffic received', 'telepilot' ); ?></span>
								<strong><?php echo $last_webhook_log ? esc_html( $this->format_log_time( $last_webhook_log['created_at'] ) ) : esc_html__( 'Waiting', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $webhook_status['ok'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Telegram webhook synced', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $webhook_status['message'] ) ? esc_html( $webhook_status['message'] ) : esc_html__( 'Pending', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo $last_delivery_log ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last Telegram delivery', 'telepilot' ); ?></span>
								<strong><?php echo $last_delivery_log ? esc_html( $this->format_log_time( $last_delivery_log['created_at'] ) ) : esc_html__( 'Not sent yet', 'telepilot' ); ?></strong>
							</li>
						</ul>
						<?php if ( $last_delivery_failure ) : ?>
							<p class="telepilot-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: relative log time. */
										__( 'Most recent delivery failure: %s', 'telepilot' ),
										$this->format_log_time( $last_delivery_failure['created_at'] )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $webhook_status['error'] ) ) : ?>
							<p class="telepilot-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: webhook error message. */
										__( 'Webhook note: %s', 'telepilot' ),
										$webhook_status['error']
									)
								);
								?>
							</p>
						<?php endif; ?>
						<p class="telepilot-inline-note">
							<?php esc_html_e( 'Webhook mode is preferred for real-time replies. Polling fallback depends on WP-Cron and can delay messages if your host does not trigger cron promptly.', 'telepilot' ); ?>
						</p>
					</section>

					<section class="telepilot-panel">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Release Prep', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Hardening Readiness', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ul class="telepilot-status-list">
							<li class="is-good">
								<span><?php esc_html_e( 'Plugin schema version', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( $schema_version ); ?></strong>
							</li>
							<li class="<?php echo (int) $table_status['ready_count'] === (int) $table_status['total_count'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Database tables ready', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( sprintf( __( '%1$d/%2$d', 'telepilot' ), (int) $table_status['ready_count'], (int) $table_status['total_count'] ) ); ?></strong>
							</li>
							<li class="is-good">
								<span><?php esc_html_e( 'Direct-chat-only sensitive actions', 'telepilot' ); ?></span>
								<strong><?php esc_html_e( 'Enforced', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $settings['cleanup_on_uninstall'] ) ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Uninstall behavior', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $settings['cleanup_on_uninstall'] ) ? esc_html__( 'Delete data', 'telepilot' ) : esc_html__( 'Preserve data', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo $next_daily_maintenance ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Next daily maintenance', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( $this->format_schedule_timestamp( $next_daily_maintenance, __( 'Not scheduled', 'telepilot' ) ) ); ?></strong>
							</li>
							<li class="<?php echo 'polling' !== $settings['transport_mode'] || $next_poll_run ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Next polling run', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( 'polling' === $settings['transport_mode'] ? $this->format_schedule_timestamp( $next_poll_run, __( 'Not scheduled', 'telepilot' ) ) : __( 'Webhook mode', 'telepilot' ) ); ?></strong>
							</li>
							<li class="<?php echo $next_job_run ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Background worker schedule', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( $this->format_schedule_timestamp( $next_job_run, __( 'Idle', 'telepilot' ) ) ); ?></strong>
							</li>
							<li class="<?php echo $poll_lock_active ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Polling lock', 'telepilot' ); ?></span>
								<strong><?php echo $poll_lock_active ? esc_html__( 'Active', 'telepilot' ) : esc_html__( 'Clear', 'telepilot' ); ?></strong>
							</li>
						</ul>
						<?php if ( ! empty( $table_status['missing'] ) ) : ?>
							<p class="telepilot-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: comma-separated table names. */
										__( 'Missing database tables: %s', 'telepilot' ),
										implode( ', ', $table_status['missing'] )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</section>

					<section class="telepilot-panel">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Snapshot', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Dashboard Preview', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ul class="telepilot-status-list">
							<li class="is-good">
								<span><?php esc_html_e( 'Site health', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( $dashboard_summary['site_health'] ); ?></strong>
							</li>
							<li class="is-good">
								<span><?php esc_html_e( 'Draft posts', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) $dashboard_summary['draft_posts_count'] ); ?></strong>
							</li>
							<li class="<?php echo $dashboard_summary['pending_comments'] > 0 ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Pending comments', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) $dashboard_summary['pending_comments'] ); ?></strong>
							</li>
							<li class="<?php echo array_sum( $dashboard_summary['pending_updates'] ) > 0 ? 'is-warn' : 'is-good'; ?>">
								<span><?php esc_html_e( 'Available updates', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) array_sum( $dashboard_summary['pending_updates'] ) ); ?></strong>
							</li>
						</ul>
					</section>

					<section class="telepilot-panel telepilot-panel-wide">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Diagnostics', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Transport Health', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ul class="telepilot-status-list">
							<li class="is-good">
								<span><?php esc_html_e( 'Last processed transport', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_transport'] ) ? esc_html( strtoupper( (string) $diagnostics['last_transport'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_webhook_received_at'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last webhook received', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_webhook_received_at'] ) ? esc_html( $this->format_timestamp( (int) $diagnostics['last_webhook_received_at'] ) ) : esc_html__( 'Never', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_webhook_auth_status'] ) && 'success' === $diagnostics['last_webhook_auth_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last webhook auth result', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_webhook_auth_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_webhook_auth_status'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_poll_at'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last poll run', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_poll_at'] ) ? esc_html( $this->format_timestamp( (int) $diagnostics['last_poll_at'] ) ) : esc_html__( 'Never', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_command_name'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last processed command', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_command_name'] ) ? esc_html( $diagnostics['last_command_name'] ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo isset( $diagnostics['last_command_duration_ms'] ) && (int) $diagnostics['last_command_duration_ms'] < 2000 ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last command duration', 'telepilot' ); ?></span>
								<strong>
									<?php
									echo isset( $diagnostics['last_command_duration_ms'] )
										? esc_html( sprintf( __( '%d ms', 'telepilot' ), (int) $diagnostics['last_command_duration_ms'] ) )
										: esc_html__( 'Unknown', 'telepilot' );
									?>
								</strong>
							</li>
							<li class="<?php echo 0 === $queued_job_count ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Queued background jobs', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) $queued_job_count ); ?></strong>
							</li>
							<li class="<?php echo empty( $job_counts['failed'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Failed background jobs', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) $job_counts['failed'] ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_worker_trigger_status'] ) && 'success' === $diagnostics['last_worker_trigger_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last worker trigger', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_worker_trigger_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_worker_trigger_status'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['last_background_job_status'] ) || 'success' === $diagnostics['last_background_job_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last background job', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_background_job_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_background_job_status'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_transport_self_test_status'] ) && 'success' === $diagnostics['last_transport_self_test_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last transport self-test', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_transport_self_test_at'] ) ? esc_html( $this->format_timestamp( (int) $diagnostics['last_transport_self_test_at'] ) ) : esc_html__( 'Never', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['slow_commands'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Slow command count', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) ( isset( $diagnostics['slow_commands'] ) ? (int) $diagnostics['slow_commands'] : 0 ) ); ?></strong>
							</li>
							<li class="<?php echo ! empty( $diagnostics['last_poll_status'] ) && 'failed' !== $diagnostics['last_poll_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last poll status', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_poll_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_poll_status'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['last_send_error_message'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last Telegram send error', 'telepilot' ); ?></span>
								<strong><?php echo empty( $diagnostics['last_send_error_message'] ) ? esc_html__( 'None', 'telepilot' ) : esc_html__( 'Present', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['last_link_attempt_status'] ) || 'success' === $diagnostics['last_link_attempt_status'] ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Last link attempt', 'telepilot' ); ?></span>
								<strong><?php echo ! empty( $diagnostics['last_link_attempt_status'] ) ? esc_html( ucfirst( (string) $diagnostics['last_link_attempt_status'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['stale_updates_dropped'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Stale updates dropped', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) ( isset( $diagnostics['stale_updates_dropped'] ) ? (int) $diagnostics['stale_updates_dropped'] : 0 ) ); ?></strong>
							</li>
							<li class="<?php echo empty( $diagnostics['duplicate_updates_ignored'] ) ? 'is-good' : 'is-warn'; ?>">
								<span><?php esc_html_e( 'Duplicate updates ignored', 'telepilot' ); ?></span>
								<strong><?php echo esc_html( (string) ( isset( $diagnostics['duplicate_updates_ignored'] ) ? (int) $diagnostics['duplicate_updates_ignored'] : 0 ) ); ?></strong>
							</li>
						</ul>
						<?php if ( ! empty( $diagnostics['last_webhook_auth_error'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last webhook auth error: %s', 'telepilot' ), $diagnostics['last_webhook_auth_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_poll_error'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last poll error: %s', 'telepilot' ), $diagnostics['last_poll_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_send_error_message'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last Telegram API send error: %s', 'telepilot' ), $diagnostics['last_send_error_message'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_link_attempt_detail'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last link attempt detail: %s', 'telepilot' ), $diagnostics['last_link_attempt_detail'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_worker_trigger_error'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last worker trigger error: %s', 'telepilot' ), $diagnostics['last_worker_trigger_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_background_job_error'] ) ) : ?>
							<p class="telepilot-inline-note"><?php echo esc_html( sprintf( __( 'Last background job error: %s', 'telepilot' ), $diagnostics['last_background_job_error'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostics['last_transport_self_test_status'] ) ) : ?>
							<p class="telepilot-inline-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: webhook status message, 2: worker status message. */
										__( 'Last self-test results: webhook route %1$s; worker route %2$s.', 'telepilot' ),
										! empty( $diagnostics['last_transport_self_test_webhook'] ) ? $diagnostics['last_transport_self_test_webhook'] : __( 'not checked', 'telepilot' ),
										! empty( $diagnostics['last_transport_self_test_worker'] ) ? $diagnostics['last_transport_self_test_worker'] : __( 'not checked', 'telepilot' )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<div class="telepilot-actions">
							<form method="post">
								<?php wp_nonce_field( 'telepilot_tools_actions', 'telepilot_tools_nonce' ); ?>
								<button class="button button-secondary" type="submit" name="telepilot_poll_now" value="1"><?php esc_html_e( 'Poll Now', 'telepilot' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepilot_process_jobs_now" value="1"><?php esc_html_e( 'Process Queue', 'telepilot' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepilot_run_transport_self_test" value="1"><?php esc_html_e( 'Run Transport Self-Test', 'telepilot' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepilot_refresh_webhook_status" value="1"><?php esc_html_e( 'Refresh Webhook Status', 'telepilot' ); ?></button>
								<button class="button button-secondary" type="submit" name="telepilot_flush_updates" value="1"><?php esc_html_e( 'Flush Old Updates', 'telepilot' ); ?></button>
							</form>
						</div>
					</section>

					<section class="telepilot-panel telepilot-panel-wide">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Performance', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Recent Command Timings', 'telepilot' ); ?></h2>
							</div>
						</div>
						<?php if ( empty( $command_diagnostics ) || ! is_array( $command_diagnostics ) ) : ?>
							<p class="telepilot-empty-state"><?php esc_html_e( 'No command timings recorded yet. Use Telegram commands and WP Telepilot will log recent execution times here.', 'telepilot' ); ?></p>
						<?php else : ?>
							<div class="telepilot-table-scroll">
								<table class="widefat fixed striped telepilot-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'When', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Command', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Transport', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Duration', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Delivery', 'telepilot' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( array_reverse( $command_diagnostics ) as $entry ) : ?>
											<tr>
												<td><?php echo ! empty( $entry['recorded_at'] ) ? esc_html( $this->format_timestamp( (int) $entry['recorded_at'] ) ) : esc_html__( 'Unknown', 'telepilot' ); ?></td>
												<td><?php echo ! empty( $entry['command'] ) ? esc_html( $entry['command'] ) : '-'; ?></td>
												<td><?php echo ! empty( $entry['transport'] ) ? esc_html( strtoupper( (string) $entry['transport'] ) ) : '-'; ?></td>
												<td><?php echo isset( $entry['duration_ms'] ) ? esc_html( sprintf( __( '%d ms', 'telepilot' ), (int) $entry['duration_ms'] ) ) : '-'; ?></td>
												<td><?php echo ! empty( $entry['dispatch_error'] ) ? esc_html__( 'Failed', 'telepilot' ) : esc_html__( 'Sent', 'telepilot' ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</section>

					<section class="telepilot-panel telepilot-panel-wide">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Quick Start', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'First-Time Setup', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ol class="telepilot-setup-list">
							<li><?php esc_html_e( 'Create a Telegram bot with BotFather, then paste the bot token into the Telegram tab.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'Choose Webhook for direct delivery or Polling Fallback if your host blocks Telegram with 403 or bot protection.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'If you use Webhook mode, WP Telepilot will register the webhook URL and webhook secret with Telegram when you save settings.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'Message the bot with /start or /chatid to reveal your current Telegram chat ID.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'Add that chat ID to Allowed Chat IDs if you want to restrict access to specific chats.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'Generate a one-time link code from your WordPress profile, then send /link CODE in Telegram.', 'telepilot' ); ?></li>
							<li><?php esc_html_e( 'Use /menu for the guided command hub or /site for the site overview once your account is linked.', 'telepilot' ); ?></li>
						</ol>
					</section>

					<section class="telepilot-panel">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Command Surface', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Primary Telegram Commands', 'telepilot' ); ?></h2>
							</div>
						</div>
						<ul class="telepilot-setup-list">
							<li><code>/start</code> <?php esc_html_e( 'Starts onboarding and confirms the current chat ID.', 'telepilot' ); ?></li>
							<li><code>/menu</code> <?php esc_html_e( 'Opens the guided WP Telepilot command hub.', 'telepilot' ); ?></li>
							<li><code>/site</code> <?php esc_html_e( 'Shows the site overview and operational shortcuts.', 'telepilot' ); ?></li>
							<li><code>/help</code> <?php esc_html_e( 'Lists the available command surface for the linked account.', 'telepilot' ); ?></li>
							<li><code>/link CODE</code> <?php esc_html_e( 'Links Telegram to a WordPress user in private chat.', 'telepilot' ); ?></li>
							<li><code>/unlink</code> <?php esc_html_e( 'Removes the Telegram link and revokes access.', 'telepilot' ); ?></li>
						</ul>
					</section>

					<section class="telepilot-panel telepilot-panel-wide">
						<div class="telepilot-panel-heading">
							<div>
								<p class="telepilot-section-label"><?php esc_html_e( 'Audit Trail', 'telepilot' ); ?></p>
								<h2><?php esc_html_e( 'Recent Activity', 'telepilot' ); ?></h2>
							</div>
						</div>
						<?php if ( empty( $recent_logs ) ) : ?>
							<p class="telepilot-empty-state"><?php esc_html_e( 'No audit records yet. Webhook activity and account linking events will appear here.', 'telepilot' ); ?></p>
						<?php else : ?>
							<div class="telepilot-table-scroll">
								<table class="widefat fixed striped telepilot-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Time', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Action', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Command', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Chat', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Telegram', 'telepilot' ); ?></th>
											<th><?php esc_html_e( 'Result', 'telepilot' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent_logs as $log ) : ?>
											<tr>
												<td><?php echo esc_html( get_date_from_gmt( $log['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
												<td><?php echo esc_html( $log['action_name'] ); ?></td>
												<td><?php echo esc_html( $this->extract_log_command( $log ) ); ?></td>
												<td><?php echo esc_html( $log['chat_id'] ? $log['chat_id'] : '-' ); ?></td>
												<td><?php echo esc_html( $this->extract_log_telegram_username( $log ) ); ?></td>
												<td>
													<span class="telepilot-status-pill <?php echo ! empty( $log['was_successful'] ) ? 'is-good' : 'is-bad'; ?>">
														<?php echo ! empty( $log['was_successful'] ) ? esc_html__( 'Success', 'telepilot' ) : esc_html__( 'Failed', 'telepilot' ); ?>
													</span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
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

		$linking_service = new Telepilot_User_Linking_Service();
		$telegram_id     = get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$telegram_chat   = get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );
		$telegram_name   = get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_NAME, true );
		$link_code       = $linking_service->get_active_link_code( $user->ID );
		$expires         = (int) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_LINK_EXPIRES, true );
		?>
		<h2><?php esc_html_e( 'WP Telepilot', 'telepilot' ); ?></h2>
		<p><?php esc_html_e( 'Linking lets this user authenticate with the Telegram bot, open the command hub, and run the WordPress actions allowed by their role.', 'telepilot' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=telepilot' ) ); ?>"><?php esc_html_e( 'Open WP Telepilot settings', 'telepilot' ); ?></a></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="telepilot-link-code"><?php esc_html_e( 'Linking Code', 'telepilot' ); ?></label></th>
				<td>
					<?php if ( $link_code && $expires > time() ) : ?>
						<input id="telepilot-link-code" type="text" class="regular-text code" readonly value="<?php echo esc_attr( $link_code ); ?>" />
						<p class="description"><?php esc_html_e( 'Send `/link CODE` to your WP Telepilot bot within the next hour. WP Telepilot stores only the hashed version of this code on the server.', 'telepilot' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Generate a one-time code below to link your Telegram account.', 'telepilot' ); ?></p>
					<?php endif; ?>
					<?php wp_nonce_field( 'telepilot_profile_actions', 'telepilot_profile_nonce' ); ?>
					<p><button class="button" type="submit" name="telepilot_generate_link_code" value="1"><?php esc_html_e( 'Generate Link Code', 'telepilot' ); ?></button></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Linked Telegram', 'telepilot' ); ?></th>
				<td>
					<p><?php echo $telegram_id ? esc_html( $telegram_id ) : esc_html__( 'Not linked yet.', 'telepilot' ); ?></p>
					<?php if ( $telegram_chat ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Chat ID: %s', 'telepilot' ), $telegram_chat ) ); ?></p>
					<?php endif; ?>
					<?php if ( $telegram_name ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Telegram username: @%s', 'telepilot' ), $telegram_name ) ); ?></p>
					<?php endif; ?>
					<?php if ( $telegram_id ) : ?>
						<p><button class="button button-secondary" type="submit" name="telepilot_unlink_telegram" value="1"><?php esc_html_e( 'Unlink Telegram', 'telepilot' ); ?></button></p>
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

		if ( empty( $_POST['telepilot_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['telepilot_profile_nonce'] ) ), 'telepilot_profile_actions' ) ) {
			return;
		}

		$linking_service = new Telepilot_User_Linking_Service();

		if ( ! empty( $_POST['telepilot_unlink_telegram'] ) ) {
			$linking_service->unlink_user( $user_id );
			return;
		}

		if ( empty( $_POST['telepilot_generate_link_code'] ) ) {
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
				'stale_update_window' => Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW,
			)
		);

		if ( empty( $settings['bot_token'] ) ) {
			delete_option( 'telepilot_webhook_status' );
			delete_transient( 'telepilot_admin_notice' );
			$this->sync_transport_schedule( $settings['transport_mode'] );
			return;
		}

		$client = new Telepilot_Telegram_Client( (string) $settings['bot_token'] );
		$command_result = $client->set_commands( $this->telegram_commands() );
		$transport_mode = $settings['transport_mode'];
		$webhook_result = null;

		$this->sync_transport_schedule( $transport_mode );

		if ( 'polling' === $transport_mode ) {
			$delete_result = $client->delete_webhook();
			update_option(
				'telepilot_webhook_status',
				array(
					'ok'        => ! is_wp_error( $delete_result ) ? 1 : 0,
					'message'   => __( 'Polling mode active', 'telepilot' ),
					'error'     => is_wp_error( $delete_result ) ? $delete_result->get_error_message() : '',
					'synced_at' => time(),
				),
				false
			);
		} else {
			$webhook_result = $client->set_webhook(
				rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::ROUTE ),
				(string) $settings['webhook_secret']
			);
		}

		if ( 'webhook' === $transport_mode && is_wp_error( $webhook_result ) ) {
			update_option(
				'telepilot_webhook_status',
				array(
					'ok'        => 0,
					'message'   => __( 'Sync failed', 'telepilot' ),
					'error'     => $webhook_result->get_error_message(),
					'synced_at' => time(),
				),
				false
			);
		} elseif ( 'webhook' === $transport_mode ) {
			$webhook_info = $client->get_webhook_info();
			$status_label = __( 'Registered', 'telepilot' );
			$status_error = '';

			if ( is_wp_error( $webhook_info ) ) {
				$status_label = __( 'Registered', 'telepilot' );
				$status_error = $webhook_info->get_error_message();
			} elseif ( ! empty( $webhook_info['result']['last_error_message'] ) ) {
				$status_label = __( 'Registered with warning', 'telepilot' );
				$status_error = (string) $webhook_info['result']['last_error_message'];
			}

			update_option(
				'telepilot_webhook_status',
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
					__( 'command sync failed: %s', 'telepilot' ),
					$command_result->get_error_message()
				);
			}

			if ( 'webhook' === $transport_mode && is_wp_error( $webhook_result ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message. */
					__( 'webhook registration failed: %s', 'telepilot' ),
					$webhook_result->get_error_message()
				);
			}

			set_transient(
				'telepilot_admin_notice',
				array(
					'type'    => 'error',
					'message' => sprintf( __( 'WP Telepilot saved your settings, but Telegram sync had issues: %s', 'telepilot' ), implode( '; ', $errors ) ),
				),
				60
			);

			return;
		}

		set_transient(
			'telepilot_admin_notice',
			array(
				'type'    => 'success',
				'message' => 'polling' === $transport_mode
					? __( 'Settings saved. Telegram commands synced. Polling fallback is active.', 'telepilot' )
					: __( 'Settings saved. Telegram commands synced. Webhook is active.', 'telepilot' ),
			),
			60
		);
	}

	public function render_admin_notice() {
		if ( empty( $_GET['page'] ) || 'telepilot' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$notice = get_transient( 'telepilot_admin_notice' );

		if ( empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'telepilot_admin_notice' );
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
			'worker_secret'         => '',
			'transport_mode'        => 'webhook',
			'stale_update_window'   => Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW,
			'allowed_chat_ids'      => '',
			'default_notifications' => array(),
			'log_retention_days'    => 30,
			'rate_limit_per_minute' => 20,
			'linking_enabled'       => 1,
			'cleanup_on_uninstall'  => 0,
		);

		return wp_parse_args( get_option( 'telepilot_settings', array() ), $defaults );
	}

	private function sync_transport_schedule( $transport_mode ) {
		if ( 'polling' === $transport_mode ) {
			if ( ! wp_next_scheduled( 'telepilot_poll_updates' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'telepilot_every_minute', 'telepilot_poll_updates' );
			}

			return;
		}

		wp_clear_scheduled_hook( 'telepilot_poll_updates' );
	}

	private function notification_options() {
		return Telepilot_Notification_Service::option_labels();
	}

	private function get_database_table_status() {
		global $wpdb;

		$tables = array(
			Telepilot_Audit_Log_Repository::table_name(),
			Telepilot_Jobs_Repository::table_name(),
			Telepilot_Processed_Updates_Repository::table_name(),
		);

		$ready   = array();
		$missing = array();

		foreach ( $tables as $table_name ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( $found === $table_name ) {
				$ready[] = $table_name;
				continue;
			}

			$missing[] = $table_name;
		}

		return array(
			'ready_count' => count( $ready ),
			'total_count' => count( $tables ),
			'missing'     => $missing,
		);
	}

	private function format_schedule_timestamp( $timestamp, $fallback ) {
		if ( empty( $timestamp ) ) {
			return $fallback;
		}

		return $this->format_timestamp( (int) $timestamp );
	}

	private function telegram_commands() {
		return array(
			array(
				'command'     => 'start',
				'description' => __( 'Connect WP Telepilot and view first steps', 'telepilot' ),
			),
			array(
				'command'     => 'help',
				'description' => __( 'Show available commands', 'telepilot' ),
			),
			array(
				'command'     => 'menu',
				'description' => __( 'Open the WP Telepilot command hub', 'telepilot' ),
			),
			array(
				'command'     => 'site',
				'description' => __( 'View the site overview and shortcuts', 'telepilot' ),
			),
			array(
				'command'     => 'settings',
				'description' => __( 'Open WP Telepilot settings information', 'telepilot' ),
			),
			array(
				'command'     => 'notifications',
				'description' => __( 'Review and toggle Telegram notification types', 'telepilot' ),
			),
			array(
				'command'     => 'chatid',
				'description' => __( 'Show the current Telegram chat ID', 'telepilot' ),
			),
			array(
				'command'     => 'link',
				'description' => __( 'Link Telegram to your WordPress account', 'telepilot' ),
			),
			array(
				'command'     => 'unlink',
				'description' => __( 'Unlink Telegram from your WordPress account', 'telepilot' ),
			),
			array(
				'command'     => 'dashboard',
				'description' => __( 'Legacy alias for the site overview', 'telepilot' ),
			),
			array(
				'command'     => 'comments',
				'description' => __( 'Review and moderate comments', 'telepilot' ),
			),
			array(
				'command'     => 'posts',
				'description' => __( 'List, search, and publish posts', 'telepilot' ),
			),
			array(
				'command'     => 'pages',
				'description' => __( 'Manage pages', 'telepilot' ),
			),
			array(
				'command'     => 'media',
				'description' => __( 'Review media library items', 'telepilot' ),
			),
			array(
				'command'     => 'users',
				'description' => __( 'Manage WordPress users', 'telepilot' ),
			),
			array(
				'command'     => 'plugins',
				'description' => __( 'Manage installed plugins', 'telepilot' ),
			),
			array(
				'command'     => 'categories',
				'description' => __( 'List and manage post categories', 'telepilot' ),
			),
			array(
				'command'     => 'tags',
				'description' => __( 'List and manage post tags', 'telepilot' ),
			),
		);
	}

	private function format_log_time( $gmt_datetime ) {
		$timestamp = mysql2date( 'U', $gmt_datetime, true );

		if ( ! $timestamp ) {
			return __( 'Unknown', 'telepilot' );
		}

		return sprintf(
			/* translators: 1: local datetime, 2: human relative time. */
			__( '%1$s (%2$s ago)', 'telepilot' ),
			wp_date( 'Y-m-d H:i:s', $timestamp ),
			human_time_diff( $timestamp, current_time( 'timestamp', true ) )
		);
	}

	private function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return __( 'Unknown', 'telepilot' );
		}

		return sprintf(
			__( '%1$s (%2$s ago)', 'telepilot' ),
			wp_date( 'Y-m-d H:i:s', $timestamp ),
			human_time_diff( $timestamp, current_time( 'timestamp', true ) )
		);
	}

	private function extract_log_command( $log ) {
		if ( ! empty( $log['resource_id'] ) && 0 === strpos( (string) $log['resource_id'], '/' ) ) {
			return $this->normalize_logged_command( (string) $log['resource_id'] );
		}

		if ( ! empty( $log['context'] ) ) {
			$context = json_decode( $log['context'], true );

			if ( is_array( $context ) && ! empty( $context['command'] ) ) {
				return $this->normalize_logged_command( (string) $context['command'] );
			}
		}

		if ( ! empty( $log['after_state'] ) ) {
			$after_state = json_decode( $log['after_state'], true );

			if ( is_array( $after_state ) && ! empty( $after_state['command'] ) ) {
				return $this->normalize_logged_command( (string) $after_state['command'] );
			}
		}

		return '-';
	}

	private function extract_log_telegram_username( $log ) {
		foreach ( array( 'context', 'after_state', 'before_state' ) as $field ) {
			if ( empty( $log[ $field ] ) ) {
				continue;
			}

			$data = json_decode( $log[ $field ], true );
			if ( ! is_array( $data ) || empty( $data['telegram_username'] ) ) {
				continue;
			}

			return '@' . ltrim( (string) $data['telegram_username'], '@' );
		}

		return '-';
	}

	private function normalize_logged_command( $command ) {
		$command = trim( (string) $command );
		if ( 0 !== strpos( $command, 'tp:' ) ) {
			return '' !== $command ? $command : '-';
		}

		$parts = explode( ':', $command );
		$type  = isset( $parts[1] ) ? (string) $parts[1] : '';

		switch ( $type ) {
			case 'comment':
				return trim( sprintf( '/comments %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'post':
				return trim( sprintf( '/posts %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'page':
				return trim( sprintf( '/pages %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'media':
				return trim( sprintf( '/media %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'user':
				return trim( sprintf( '/users %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'plugin':
				return '/plugins confirm';
			case 'term':
				$resource = isset( $parts[2] ) && 'post_tag' === $parts[2] ? '/tags' : '/categories';
				return trim( sprintf( '%1$s %2$s %3$s', $resource, isset( $parts[3] ) ? $parts[3] : '', isset( $parts[4] ) ? $parts[4] : '' ) );
		}

		return $command;
	}

	public function handle_tools_actions() {
		if ( empty( $_GET['page'] ) || 'telepilot' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( empty( $_POST['telepilot_tools_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['telepilot_tools_nonce'] ) ), 'telepilot_tools_actions' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$client   = new Telepilot_Telegram_Client( (string) $settings['bot_token'] );

		if ( ! empty( $_POST['telepilot_poll_now'] ) ) {
			if ( 'polling' !== $settings['transport_mode'] ) {
				$this->set_tools_notice( 'error', __( 'Poll Now is only available when Transport Mode is set to Polling Fallback.', 'telepilot' ) );
				return;
			}

			$telegram = new Telepilot_Telegram_Service();
			$result   = $telegram->poll_updates();
			$this->set_tools_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'WP Telepilot polled Telegram successfully.', 'telepilot' )
			);
			return;
		}

		if ( ! empty( $_POST['telepilot_process_jobs_now'] ) ) {
			$telegram = new Telepilot_Telegram_Service();
			$jobs     = $telegram->process_jobs();
			$this->set_tools_notice(
				'success',
				sprintf(
					/* translators: %d: number of jobs claimed for processing. */
					__( 'WP Telepilot processed %d queued background job(s).', 'telepilot' ),
					is_array( $jobs ) ? count( $jobs ) : 0
				)
			);
			return;
		}

		if ( ! empty( $_POST['telepilot_run_transport_self_test'] ) ) {
			$telegram = new Telepilot_Telegram_Service();
			$result   = $telegram->run_transport_self_test();
			$webhook  = ! empty( $result['results']['webhook'] ) ? $result['results']['webhook'] : array();
			$worker   = ! empty( $result['results']['worker'] ) ? $result['results']['worker'] : array();

			$this->set_tools_notice(
				! empty( $result['ok'] ) ? 'success' : 'error',
				sprintf(
					/* translators: 1: webhook route result, 2: worker route result. */
					__( 'Transport self-test finished. Webhook route: %1$s. Worker route: %2$s.', 'telepilot' ),
					! empty( $webhook['message'] ) ? $webhook['message'] : __( 'Unknown', 'telepilot' ),
					! empty( $worker['message'] ) ? $worker['message'] : __( 'Unknown', 'telepilot' )
				)
			);
			return;
		}

		if ( ! empty( $_POST['telepilot_refresh_webhook_status'] ) ) {
			$result = $client->get_webhook_info();
			if ( is_wp_error( $result ) ) {
				$this->set_tools_notice( 'error', $result->get_error_message() );
				return;
			}

			update_option(
				'telepilot_webhook_status',
				array(
					'ok'              => 1,
					'message'         => __( 'Refreshed', 'telepilot' ),
					'error'           => ! empty( $result['result']['last_error_message'] ) ? (string) $result['result']['last_error_message'] : '',
					'synced_at'       => time(),
					'telegram_result' => isset( $result['result'] ) ? $result['result'] : array(),
				),
				false
			);
			$this->set_tools_notice( 'success', __( 'Webhook status refreshed.', 'telepilot' ) );
			return;
		}

		if ( ! empty( $_POST['telepilot_flush_updates'] ) ) {
			$telegram = new Telepilot_Telegram_Service();
			$result   = $telegram->flush_pending_updates( $settings['transport_mode'] );
			$this->set_tools_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'Queued Telegram updates were flushed.', 'telepilot' )
			);
		}
	}

	private function set_tools_notice( $type, $message ) {
		set_transient(
			'telepilot_admin_notice',
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	private function get_logo_url() {
		$logo_path = TELEPILOT_PATH . 'assets/images/telepilot-logo.png';

		if ( ! file_exists( $logo_path ) ) {
			return '';
		}

		return TELEPILOT_URL . 'assets/images/telepilot-logo.png';
	}
}
