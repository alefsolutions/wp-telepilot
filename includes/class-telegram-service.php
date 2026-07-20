<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Telegram_Service {
	const DIAGNOSTICS_OPTION         = 'telepilot_transport_diagnostics';
	const COMMAND_DIAGNOSTICS_OPTION = 'telepilot_command_diagnostics';
	const STALE_UPDATE_WINDOW_OPTION = 'stale_update_window';
	const DEFAULT_STALE_WINDOW       = 180;
	const POLL_LOCK_TRANSIENT        = 'telepilot_poll_lock';
	const QUEUE_FALLBACK_DELAY       = 15;
	const JOB_LOCK_TIMEOUT           = 180;
	const RESPONSE_CACHE_TTL         = 30;
	private $client;
	private $user_resolver;
	private $permission_service;
	private $rate_limiter;
	private $command_router;
	private $media_service;
	private $users_service;
	private $plugins_service;
	private $post_editor_service;
	private $confirmation_service;

	public function __construct() {
		$this->client             = new Telepilot_Telegram_Client();
		$this->user_resolver      = new Telepilot_Linked_User_Resolver();
		$this->permission_service = new Telepilot_Permission_Service();
		$this->rate_limiter       = new Telepilot_Rate_Limiter();
		$this->confirmation_service = new Telepilot_Confirmation_Service();
		$this->media_service      = new Telepilot_Media_Service( $this->confirmation_service, $this->client );
		$this->users_service      = new Telepilot_Users_Service( $this->confirmation_service );
		$this->plugins_service    = new Telepilot_Plugins_Service( $this->confirmation_service );
		$this->post_editor_service = new Telepilot_Post_Editor_Service();
		$this->command_router     = new Telepilot_Command_Router(
			new Telepilot_User_Linking_Service(),
			$this->permission_service,
			new Telepilot_Dashboard_Service(),
			new Telepilot_Comments_Service( $this->confirmation_service ),
			new Telepilot_Posts_Service( $this->confirmation_service ),
			$this->post_editor_service,
			new Telepilot_Pages_Service( $this->confirmation_service ),
			$this->media_service,
			$this->users_service,
			$this->plugins_service,
			new Telepilot_Taxonomies_Service( $this->confirmation_service ),
			new Telepilot_Notifications_Command_Service(),
			new Telepilot_Site_Settings_Command_Service(),
			$this->confirmation_service
		);
	}

	public function handle_update( $update, $transport = 'webhook' ) {
		return $this->queue_or_process_update( $update, $transport );
	}

	public function handle_webhook_update( $update ) {
		return $this->queue_or_process_update( $update, 'webhook' );
	}

	private function queue_or_process_update( $update, $transport ) {
		$command = $this->command_router->parse_command( $update );

		if ( ! $this->should_queue_update( $command, $update, $transport ) ) {
			return $this->process_update( $update, $transport );
		}

		$placeholder  = $this->send_processing_placeholder( $update, $command );
		$is_callback  = ! empty( $update['callback_query'] );
		$is_replaceable = $this->is_replaceable_command( $command, $update );
		$job_group    = $this->build_job_group( $command, $placeholder, $is_replaceable );
		$queue_result = Telepilot_Jobs_Repository::enqueue(
			isset( $update['update_id'] ) ? (int) $update['update_id'] : 0,
			$transport,
			$update,
			! empty( $command['name'] ) ? $command['name'] : '',
			$placeholder,
			array(
				'priority'       => $this->get_job_priority( $command, $update ),
				'stale_after'    => $this->get_job_stale_after( $command, $update ),
				'job_group'      => $job_group,
				'is_replaceable' => $is_replaceable,
			)
		);

		if ( is_wp_error( $queue_result ) ) {
			return $this->process_update(
				$update,
				$transport,
				array(
					'callback_already_answered' => $is_callback,
					'edit_message_id'           => ! empty( $placeholder['message_id'] ) ? (int) $placeholder['message_id'] : 0,
					'forced_chat_id'            => ! empty( $placeholder['chat_id'] ) ? (string) $placeholder['chat_id'] : '',
				)
			);
		}

		if ( $is_replaceable && is_int( $queue_result ) ) {
			$this->maybe_cleanup_superseded_jobs(
				Telepilot_Jobs_Repository::supersede_replaceable_jobs(
					$queue_result,
					! empty( $placeholder['chat_id'] ) ? (string) $placeholder['chat_id'] : '',
					$job_group,
					! empty( $placeholder['message_id'] ) ? (int) $placeholder['message_id'] : 0
				),
				$placeholder
			);
		}

		$this->update_diagnostics(
			array(
				'last_queued_at'       => time(),
				'last_queued_command'  => ! empty( $command['name'] ) ? $command['name'] : '',
				'last_queue_transport' => (string) $transport,
			)
		);
		$this->refresh_queue_diagnostics();

		if ( 'duplicate' !== $queue_result ) {
			$this->schedule_job_processing();
		}

		return Telepilot_Telegram_Response_Builder::success(
			__( 'Update queued for background processing.', 'wp-telepilot' ),
			array(
				'command'       => ! empty( $command['name'] ) ? $command['name'] : '',
				'skip_dispatch' => true,
				'queued'        => true,
			)
		);
	}

	private function should_queue_update( $command, $update, $transport ) {
		if ( empty( $command['name'] ) || ! is_array( $command ) ) {
			return false;
		}

		if ( ! empty( $transport ) && 'worker' === $transport ) {
			return false;
		}

		return ! empty( $update['callback_query'] ) || ! empty( $update['message']['text'] );
	}

	private function send_processing_placeholder( $update, $command ) {
		$identity = $this->user_resolver->resolve_from_update( $update );
		$chat_id  = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '';

		if ( '' === $chat_id ) {
			return array();
		}

		$message = $this->get_processing_message( $command );
		$args    = array( 'parse_mode' => 'HTML' );

		if ( ! empty( $update['callback_query'] ) ) {
			if ( ! empty( $update['callback_query']['id'] ) ) {
				$this->client->answer_callback_query( (string) $update['callback_query']['id'], __( 'Processing…', 'wp-telepilot' ) );
			}

			if ( ! empty( $update['callback_query']['message']['message_id'] ) ) {
				$message_id = (int) $update['callback_query']['message']['message_id'];
				$result     = $this->client->edit_message_text( $chat_id, $message_id, $message, $args );

				if ( ! is_wp_error( $result ) ) {
					return array(
						'chat_id'    => $chat_id,
						'message_id' => $message_id,
					);
				}
			}

			$result = $this->client->send_message( $chat_id, $message, $args );

			if ( is_wp_error( $result ) || empty( $result['result']['message_id'] ) ) {
				return array(
					'chat_id' => $chat_id,
				);
			}

			return array(
				'chat_id'    => $chat_id,
				'message_id' => (int) $result['result']['message_id'],
			);
		}

		$this->client->send_chat_action( $chat_id, 'typing' );

		if ( ! empty( $update['message']['message_id'] ) ) {
			$args['reply_to_message_id'] = (int) $update['message']['message_id'];
		}

		$result = $this->client->send_message( $chat_id, $message, $args );

		if ( is_wp_error( $result ) || empty( $result['result']['message_id'] ) ) {
			return array(
				'chat_id' => $chat_id,
			);
		}

		return array(
			'chat_id'    => $chat_id,
			'message_id' => (int) $result['result']['message_id'],
		);
	}

	private function get_processing_message( $command ) {
		$command_name = ! empty( $command['name'] ) ? (string) $command['name'] : '';

		$messages = array(
			'/site'       => __( '<b>Working on it...</b>' . "\n" . 'Building your site overview now.', 'wp-telepilot' ),
			'/dashboard'  => __( '<b>Working on it...</b>' . "\n" . 'Gathering dashboard data now.', 'wp-telepilot' ),
			'/comments'   => __( '<b>Working on it...</b>' . "\n" . 'Fetching comment moderation data now.', 'wp-telepilot' ),
			'/posts'      => __( '<b>Working on it...</b>' . "\n" . 'Loading posts now.', 'wp-telepilot' ),
			'/pages'      => __( '<b>Working on it...</b>' . "\n" . 'Loading pages now.', 'wp-telepilot' ),
			'/media'      => __( '<b>Working on it...</b>' . "\n" . 'Fetching media items now.', 'wp-telepilot' ),
			'/users'      => __( '<b>Working on it...</b>' . "\n" . 'Loading users now.', 'wp-telepilot' ),
			'/plugins'    => __( '<b>Working on it...</b>' . "\n" . 'Checking installed plugins now.', 'wp-telepilot' ),
			'/notifications' => __( '<b>Working on it...</b>' . "\n" . 'Loading notification controls now.', 'wp-telepilot' ),
			'/categories' => __( '<b>Working on it...</b>' . "\n" . 'Loading categories now.', 'wp-telepilot' ),
			'/tags'       => __( '<b>Working on it...</b>' . "\n" . 'Loading tags now.', 'wp-telepilot' ),
		);

		if ( isset( $messages[ $command_name ] ) ) {
			return $messages[ $command_name ];
		}

		return __( '<b>Working on it...</b>' . "\n" . 'Processing your request now.', 'wp-telepilot' );
	}

	private function schedule_job_processing() {
		$worker_result = $this->trigger_async_worker();
		$fallback_scheduled = false;

		$this->update_diagnostics(
			array(
				'last_worker_trigger_at'     => time(),
				'last_worker_trigger_status' => is_wp_error( $worker_result ) ? 'failed' : 'success',
				'last_worker_trigger_error'  => is_wp_error( $worker_result ) ? $worker_result->get_error_message() : '',
			)
		);

		if ( ! wp_next_scheduled( 'telepilot_process_jobs' ) ) {
			wp_schedule_single_event( time() + self::QUEUE_FALLBACK_DELAY, 'telepilot_process_jobs' );
			$fallback_scheduled = true;
		}

		if ( is_wp_error( $worker_result ) ) {
			$this->update_diagnostics(
				array(
					'last_worker_fallback_at'    => time(),
					'last_worker_fallback_delay' => self::QUEUE_FALLBACK_DELAY,
				)
			);
		}

		if ( ( $fallback_scheduled || is_wp_error( $worker_result ) ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public function process_jobs( $limit = 5 ) {
		$jobs = Telepilot_Jobs_Repository::claim_pending_jobs( $limit, self::JOB_LOCK_TIMEOUT );

		if ( empty( $jobs ) ) {
			$this->refresh_queue_diagnostics();
			return array();
		}

		foreach ( $jobs as $job ) {
			$payload = ! empty( $job['payload'] ) ? json_decode( $job['payload'], true ) : array();

			if ( empty( $payload ) || ! is_array( $payload ) ) {
				Telepilot_Jobs_Repository::mark_failed( (int) $job['id'], __( 'Queued payload could not be decoded.', 'wp-telepilot' ) );
				$this->update_diagnostics(
					array(
						'last_background_job_at'     => time(),
						'last_background_job_status' => 'failed',
					)
				);
				continue;
			}

			$chat_id = ! empty( $job['placeholder_chat_id'] ) ? (string) $job['placeholder_chat_id'] : '';
			if ( '' !== $chat_id ) {
				$this->client->send_chat_action( $chat_id, 'typing' );
			}

			$result = $this->process_update(
				$payload,
				! empty( $job['transport'] ) ? (string) $job['transport'] : 'webhook',
				array(
					'skip_duplicate_check'      => true,
					'edit_message_id'           => ! empty( $job['placeholder_message_id'] ) ? (int) $job['placeholder_message_id'] : 0,
					'forced_chat_id'            => $chat_id,
					'callback_already_answered' => ! empty( $payload['callback_query']['id'] ),
				)
			);

			if ( ! empty( $result['dispatch_error'] ) ) {
				Telepilot_Jobs_Repository::mark_failed( (int) $job['id'], (string) $result['dispatch_error'] );
				$this->update_diagnostics(
					array(
						'last_background_job_at'     => time(),
						'last_background_job_status' => 'failed',
						'last_background_job_error'  => (string) $result['dispatch_error'],
					)
				);
				continue;
			}

			Telepilot_Jobs_Repository::mark_complete( (int) $job['id'] );
			$this->update_diagnostics(
				array(
					'last_background_job_at'     => time(),
					'last_background_job_status' => 'success',
					'last_background_job_error'  => '',
				)
			);
		}

		$this->refresh_queue_diagnostics();

		if ( Telepilot_Jobs_Repository::pending_count() > 0 ) {
			$this->schedule_job_processing();
		}

		return $jobs;
	}

	public function run_transport_self_test() {
		$settings      = get_option( 'telepilot_settings', array() );
		$webhook_url   = rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::ROUTE );
		$worker_url    = rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::WORKER_ROUTE );
		$webhook_test  = $this->perform_route_self_test(
			$webhook_url,
			array(
				'headers'       => array(
					'Content-Type'                        => 'application/json',
					'X-Telegram-Bot-Api-Secret-Token'     => isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '',
				),
				'body'          => wp_json_encode(
					array(
						'update_id' => time(),
					)
				),
				'success_codes' => array( 200, 202 ),
			)
		);
		$worker_secret = isset( $settings['worker_secret'] ) ? (string) $settings['worker_secret'] : '';
		$worker_test   = '' === $worker_secret
			? array(
				'ok'          => false,
				'status_code' => 0,
				'duration_ms' => 0,
				'message'     => __( 'Worker secret is missing.', 'wp-telepilot' ),
			)
			: $this->perform_route_self_test(
				$worker_url,
				array(
					'headers'       => array(
						'X-Telepilot-Worker-Secret' => $worker_secret,
					),
					'body'          => array(
						'limit' => 1,
					),
					'success_codes' => array( 200 ),
				)
			);

		$overall_ok = ! empty( $webhook_test['ok'] ) && ! empty( $worker_test['ok'] );

		$this->update_diagnostics(
			array(
				'last_transport_self_test_at'          => time(),
				'last_transport_self_test_status'      => $overall_ok ? 'success' : 'failed',
				'last_transport_self_test_webhook'     => isset( $webhook_test['message'] ) ? (string) $webhook_test['message'] : '',
				'last_transport_self_test_worker'      => isset( $worker_test['message'] ) ? (string) $worker_test['message'] : '',
				'last_webhook_self_test_duration_ms'   => isset( $webhook_test['duration_ms'] ) ? (int) $webhook_test['duration_ms'] : 0,
				'last_worker_self_test_duration_ms'    => isset( $worker_test['duration_ms'] ) ? (int) $worker_test['duration_ms'] : 0,
				'last_webhook_self_test_status_code'   => isset( $webhook_test['status_code'] ) ? (int) $webhook_test['status_code'] : 0,
				'last_worker_self_test_status_code'    => isset( $worker_test['status_code'] ) ? (int) $worker_test['status_code'] : 0,
			)
		);

		return array(
			'ok'      => $overall_ok,
			'results' => array(
				'webhook' => $webhook_test,
				'worker'  => $worker_test,
			),
		);
	}

	private function process_update( $update, $transport = 'webhook', $options = array() ) {
		$start_time = microtime( true );

		if ( empty( $options['skip_duplicate_check'] ) && ! empty( $update['update_id'] ) && Telepilot_Processed_Updates_Repository::has_processed( (int) $update['update_id'] ) ) {
			$this->update_diagnostics(
				array(
					'last_duplicate_update_at' => time(),
					'last_duplicate_update_id' => (int) $update['update_id'],
					'last_transport'           => $transport,
				),
				array(
					'duplicate_updates_ignored' => 1,
				)
			);

			return Telepilot_Telegram_Response_Builder::success(
				__( 'Duplicate Telegram update ignored.', 'wp-telepilot' ),
				array(
					'command'       => '',
					'skip_dispatch' => true,
					'code'          => 'telepilot_duplicate_update',
				)
			);
		}

		$this->record_transport_activity( $transport, $update );

		if ( $this->is_stale_update( $update ) ) {
			$this->record_stale_update( $transport, $update );
			$this->mark_update_processed( $update, $transport, 'stale' );
			return Telepilot_Telegram_Response_Builder::error(
				__( 'Skipped a stale Telegram update.', 'wp-telepilot' ),
				array(
					'code' => 'telepilot_stale_update',
				)
			);
		}

		$identity = $this->user_resolver->resolve_from_update( $update );
		$chat_id  = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '';

		if ( '' !== $chat_id && ! $this->is_chat_allowed( $chat_id ) ) {
			$result = Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Chat Not Authorized', 'wp-telepilot' ) ),
						sprintf( __( 'Current chat ID: %s', 'wp-telepilot' ), Telepilot_Telegram_Response_Builder::code( $chat_id ) ),
						__( 'Add this chat ID to Allowed Chat IDs in WP Telepilot settings, then send /start again.', 'wp-telepilot' ),
					)
				),
				array(
					'command' => '/chatid',
					'code'    => 'telepilot_chat_not_allowed',
				)
			);

			Telepilot_Audit_Log_Repository::log(
				array(
					'chat_id'         => $chat_id,
					'action_name'     => 'telegram_rejected_chat',
					'resource_type'   => 'telegram_chat',
					'resource_id'     => $chat_id,
					'was_successful'  => 0,
					'failure_reason'  => 'Chat ID is not in the allowed list.',
					'context'         => array( 'update' => $update ),
				)
			);

			$result = $this->apply_contextual_reply_markup( $result, $identity );
			$this->dispatch_response( $identity, $update, $result, $transport );
			$this->mark_update_processed( $update, $transport, 'rejected' );

			return $result;
		}

		$settings          = get_option( 'telepilot_settings', array() );
		$limit_per_minute  = isset( $settings['rate_limit_per_minute'] ) ? (int) $settings['rate_limit_per_minute'] : 20;
		$rate_limit_result = $this->rate_limiter->check( $identity, $limit_per_minute );

		if ( true !== $rate_limit_result ) {
			$this->log_command_event( 'telegram_rate_limited', $identity, $update, $rate_limit_result );
			$this->mark_update_processed( $update, $transport, 'rate_limited' );

			return $rate_limit_result;
		}

		$command = $this->command_router->parse_command( $update );

		$this->maybe_send_chat_action( $identity, $command, $update, $options );

		if ( ! empty( $command['name'] ) ) {
			$this->log_command_event(
				'telegram_command_received',
				$identity,
				$update,
				array(
					'command'     => $this->describe_command_for_log( $command ),
					'raw_command' => ! empty( $command['raw'] ) ? (string) $command['raw'] : '',
				)
			);
		}

		try {
			$result = $this->get_cached_command_response( $identity, $command, $update );

			if ( null === $result ) {
				$result = $this->handle_media_upload( $update, $identity, $command );

				if ( null === $result ) {
					$result = $this->command_router->route( $update, $identity );
				}

				$this->maybe_cache_command_response( $identity, $command, $update, $result );
			}
		} catch ( Throwable $throwable ) {
			$result = Telepilot_Telegram_Response_Builder::error(
				__( 'WP Telepilot hit an internal error while processing that command.', 'wp-telepilot' ),
				array(
					'command' => ! empty( $command['name'] ) ? $command['name'] : '',
					'code'    => 'telepilot_command_exception',
				)
			);

			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
					'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
					'chat_id'          => ! empty( $identity['chat_id'] ) ? $identity['chat_id'] : null,
					'action_name'      => 'telegram_command_exception',
					'resource_type'    => 'telegram_command',
					'resource_id'      => ! empty( $command['name'] ) ? $command['name'] : null,
					'was_successful'   => 0,
					'failure_reason'   => $throwable->getMessage(),
					'context'          => array(
						'command' => ! empty( $command['name'] ) ? $command['name'] : '',
						'file'    => $throwable->getFile(),
						'line'    => $throwable->getLine(),
					),
				)
			);
		}

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			update_user_meta( $identity['wp_user']->ID, '_telepilot_last_command_at', time() );
		}

		$result = $this->apply_contextual_reply_markup( $result, $identity );
		$dispatch_error = $this->dispatch_response( $identity, $update, $result, $transport, $options );

		if ( ! empty( $result['code'] ) && 'telepilot_capability_denied' === $result['code'] ) {
			$this->log_command_event( 'telegram_permission_denied', $identity, $update, $result );
		}

		$this->mark_update_processed( $update, $transport, ! empty( $result['ok'] ) ? 'processed' : 'failed' );
		$this->record_command_timing(
			! empty( $command['name'] ) ? $command['name'] : 'non_command',
			$transport,
			$start_time,
			$dispatch_error
		);

		if ( $dispatch_error ) {
			$result['dispatch_error'] = $dispatch_error;
		}

		return $result;
	}

	public function poll_updates() {
		if ( $this->poll_lock_exists() ) {
			$this->update_diagnostics(
				array(
					'last_poll_at'     => time(),
					'last_poll_status' => 'locked',
					'last_poll_error'  => __( 'A previous polling worker is still active.', 'wp-telepilot' ),
				)
			);

			return new WP_Error( 'telepilot_poll_locked', __( 'WP Telepilot polling is already running.', 'wp-telepilot' ) );
		}

		$this->acquire_poll_lock();

		$offset   = (int) get_option( 'telepilot_telegram_poll_offset', 0 );
		$response = $this->client->get_updates( $offset, 10, 0 );
		$this->update_diagnostics(
			array(
				'last_poll_at' => time(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->update_diagnostics(
				array(
					'last_poll_status' => 'failed',
					'last_poll_error'  => $response->get_error_message(),
				)
			);
			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'     => 'telegram_poll_failed',
					'resource_type'   => 'telegram_poll',
					'was_successful'  => 0,
					'failure_reason'  => $response->get_error_message(),
				)
			);
			$this->release_poll_lock();
			return $response;
		}

		if ( empty( $response['result'] ) || ! is_array( $response['result'] ) ) {
			$this->update_diagnostics(
				array(
					'last_poll_status' => 'idle',
					'last_poll_count'  => 0,
				)
			);
			$this->release_poll_lock();
			return array();
		}

		$last_offset = $offset;

		foreach ( $response['result'] as $update ) {
			if ( isset( $update['update_id'] ) ) {
				$last_offset = max( $last_offset, (int) $update['update_id'] + 1 );
			}

			$this->handle_update( $update, 'polling' );
		}

		update_option( 'telepilot_telegram_poll_offset', $last_offset, false );
		$this->update_diagnostics(
			array(
				'last_poll_status' => 'success',
				'last_poll_count'  => count( $response['result'] ),
				'last_poll_error'  => '',
			)
		);
		$this->release_poll_lock();

		return $response['result'];
	}

	public function flush_pending_updates( $transport_mode = 'polling' ) {
		$transport_mode = 'webhook' === $transport_mode ? 'webhook' : 'polling';

		if ( 'webhook' === $transport_mode ) {
			$delete_result = $this->client->delete_webhook( true );

			if ( is_wp_error( $delete_result ) ) {
				return $delete_result;
			}

			$settings      = get_option( 'telepilot_settings', array() );
			$webhook_url   = rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::ROUTE );
			$secret_token  = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
			$webhook_reset = $this->client->set_webhook( $webhook_url, $secret_token );

			if ( is_wp_error( $webhook_reset ) ) {
				return $webhook_reset;
			}

			$this->update_diagnostics(
				array(
					'last_flush_at'     => time(),
					'last_flush_status' => 'success',
					'last_flush_mode'   => 'webhook',
					'last_flush_error'  => '',
				)
			);

			return array( 'flushed' => true, 'mode' => 'webhook' );
		}

		$flushed = 0;
		$offset  = (int) get_option( 'telepilot_telegram_poll_offset', 0 );

		while ( true ) {
			$response = $this->client->get_updates( $offset, 100, 0 );

			if ( is_wp_error( $response ) ) {
				$this->update_diagnostics(
					array(
						'last_flush_at'     => time(),
						'last_flush_status' => 'failed',
						'last_flush_mode'   => 'polling',
						'last_flush_error'  => $response->get_error_message(),
					)
				);
				return $response;
			}

			if ( empty( $response['result'] ) || ! is_array( $response['result'] ) ) {
				break;
			}

			foreach ( $response['result'] as $update ) {
				$flushed++;
				if ( isset( $update['update_id'] ) ) {
					$offset = max( $offset, (int) $update['update_id'] + 1 );
				}
			}

			if ( count( $response['result'] ) < 100 ) {
				break;
			}
		}

		update_option( 'telepilot_telegram_poll_offset', $offset, false );
		$this->update_diagnostics(
			array(
				'last_flush_at'     => time(),
				'last_flush_status' => 'success',
				'last_flush_mode'   => 'polling',
				'last_flush_error'  => '',
				'last_flush_count'  => $flushed,
			)
		);

		return array(
			'flushed' => true,
			'mode'    => 'polling',
			'count'   => $flushed,
		);
	}

	private function is_chat_allowed( $chat_id ) {
		$settings = get_option( 'telepilot_settings', array() );
		$allowed  = isset( $settings['allowed_chat_ids'] ) ? $settings['allowed_chat_ids'] : '';
		$chat_ids = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $allowed ) ) ) );

		if ( empty( $chat_ids ) ) {
			return true;
		}

		return in_array( (string) $chat_id, $chat_ids, true );
	}

	private function dispatch_response( $identity, $update, $result, $transport, $options = array() ) {
		$chat_id = ! empty( $options['forced_chat_id'] ) ? $options['forced_chat_id'] : ( ! empty( $identity['chat_id'] ) ? $identity['chat_id'] : null );

		if ( ! empty( $result['skip_dispatch'] ) || empty( $chat_id ) || empty( $result['message'] ) ) {
			return '';
		}

		if ( ! empty( $update['callback_query']['id'] ) && empty( $options['callback_already_answered'] ) ) {
			$this->client->answer_callback_query( (string) $update['callback_query']['id'] );
		}

		$args = array();

		if ( array_key_exists( 'reply_markup', $result ) ) {
			$args['reply_markup'] = $result['reply_markup'];
		}

		if ( ! empty( $result['parse_mode'] ) ) {
			$args['parse_mode'] = (string) $result['parse_mode'];
		}

		if ( ! empty( $options['edit_message_id'] ) ) {
			$response = $this->client->edit_message_text(
				$chat_id,
				(int) $options['edit_message_id'],
				$result['message'],
				$args
			);
		} elseif ( ! empty( $update['callback_query']['message']['message_id'] ) ) {
			$response = $this->client->edit_message_text(
				$chat_id,
				(int) $update['callback_query']['message']['message_id'],
				$result['message'],
				$args
			);
		} else {
			$response = $this->client->send_message( $chat_id, $result['message'], $args );
		}

		if ( is_wp_error( $response ) ) {
			if ( ! empty( $options['edit_message_id'] ) || ! empty( $update['callback_query']['message']['message_id'] ) ) {
				$response = $this->client->send_message( $chat_id, $result['message'], $args );
			}
		}

		if ( is_wp_error( $response ) ) {
			$this->update_diagnostics(
				array(
					'last_send_error_at'      => time(),
					'last_send_error_message' => $response->get_error_message(),
					'last_delivery_transport' => $transport,
				)
			);
			Telepilot_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
					'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
					'chat_id'          => $chat_id,
					'action_name'      => 'telegram_response_failed',
					'resource_type'    => 'telegram_message',
					'was_successful'   => 0,
					'failure_reason'   => $response->get_error_message(),
				)
			);
			return $response->get_error_message();
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
				'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
				'chat_id'          => $chat_id,
				'action_name'      => 'telegram_response_sent',
				'resource_type'    => 'telegram_message',
				'resource_id'      => ! empty( $result['command'] ) ? $result['command'] : null,
				'after_state'      => array(
					'result' => isset( $response['result'] ) ? $response['result'] : null,
				),
			)
		);
		$this->update_diagnostics(
			array(
				'last_send_success_at'    => time(),
				'last_send_error_message' => '',
				'last_delivery_transport' => $transport,
			)
		);

		return '';
	}

	private function maybe_send_chat_action( $identity, $command, $update, $options = array() ) {
		if ( ! empty( $update['callback_query'] ) ) {
			return;
		}

		if ( ! empty( $options['edit_message_id'] ) ) {
			return;
		}

		if ( empty( $identity['chat_id'] ) ) {
			return;
		}

		if ( empty( $command['name'] ) ) {
			return;
		}

		$this->client->send_chat_action( (string) $identity['chat_id'], 'typing' );
	}

	private function trigger_async_worker( $limit = 5 ) {
		$settings = get_option( 'telepilot_settings', array() );
		$secret   = isset( $settings['worker_secret'] ) ? (string) $settings['worker_secret'] : '';

		if ( '' === $secret ) {
			return new WP_Error( 'telepilot_missing_worker_secret', __( 'WP Telepilot worker secret is not configured.', 'wp-telepilot' ) );
		}

		$response = wp_remote_post(
			rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::WORKER_ROUTE ),
			$this->build_loopback_request_args(
				rest_url( Telepilot_REST_Webhook_Controller::REST_NAMESPACE . Telepilot_REST_Webhook_Controller::WORKER_ROUTE ),
				1,
				false,
				array(
					'X-Telepilot-Worker-Secret' => $secret,
				),
				array(
					'limit' => max( 1, absint( $limit ) ),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'     => 'worker_trigger_failed',
					'resource_type'   => 'worker',
					'was_successful'  => 0,
					'failure_reason'  => $response->get_error_message(),
				)
			);

			return $response;
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'action_name'    => 'worker_triggered',
				'resource_type'  => 'worker',
				'after_state'    => array(
					'limit' => max( 1, absint( $limit ) ),
				),
				'was_successful' => 1,
			)
		);

		return true;
	}

	private function log_command_event( $action_name, $identity, $update, $context = array() ) {
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		if ( ! empty( $identity['telegram_username'] ) ) {
			$context['telegram_username'] = (string) $identity['telegram_username'];
		}

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
				'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
				'chat_id'          => ! empty( $identity['chat_id'] ) ? $identity['chat_id'] : null,
				'action_name'      => $action_name,
				'resource_type'    => 'telegram_update',
				'resource_id'      => isset( $update['update_id'] ) ? (string) $update['update_id'] : null,
				'context'          => $context,
			)
		);
	}

	private function handle_media_upload( $update, $identity, $command ) {
		if ( ! empty( $command['name'] ) ) {
			return null;
		}

		if ( empty( $update['message']['photo'] ) && empty( $update['message']['document'] ) ) {
			return null;
		}

		return Telepilot_Telegram_Response_Builder::error_html(
			Telepilot_Telegram_Response_Builder::join_blocks(
				array(
					Telepilot_Telegram_Response_Builder::bold( __( 'Direct Uploads Disabled', 'wp-telepilot' ) ),
					__( 'Media is read-only in this release, so files sent to the bot are not imported into WordPress.', 'wp-telepilot' ),
					Telepilot_Telegram_Response_Builder::italic( __( 'Use wp-admin for uploads, then return to Telegram for list, search, details, and open actions.', 'wp-telepilot' ) ),
				)
			),
			array(
				'command' => '/media',
			)
		);
	}

	private function describe_command_for_log( $command ) {
		if ( empty( $command['raw'] ) ) {
			return ! empty( $command['name'] ) ? (string) $command['name'] : '';
		}

		$raw = (string) $command['raw'];

		if ( 0 === strpos( $raw, 'tp:' ) ) {
			return $this->humanize_callback_command( $raw );
		}

		return $raw;
	}

	private function humanize_callback_command( $raw ) {
		$parts = explode( ':', (string) $raw );
		$type  = isset( $parts[1] ) ? (string) $parts[1] : '';

		switch ( $type ) {
			case 'comment':
				return trim( sprintf( '/comments %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'post':
				return trim( sprintf( '/posts %1$s %2$s', isset( $parts[2] ) ? $parts[2] : '', isset( $parts[3] ) ? $parts[3] : '' ) );
			case 'postflow':
				return '/posts categories';
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

		return $raw;
	}

	private function apply_contextual_reply_markup( $result, $identity ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$code = ! empty( $result['code'] ) ? (string) $result['code'] : '';

		switch ( $code ) {
			case 'telepilot_chat_not_allowed':
				$result['reply_markup'] = Telepilot_Telegram_Response_Builder::empty_keyboard();
				break;

			case 'telepilot_link_required':
			case 'telepilot_capability_denied':
			case 'telepilot_private_chat_required':
				$result['reply_markup'] = $this->command_router->get_home_keyboard( $identity );
				break;
		}

		return $result;
	}

	private function get_job_priority( $command, $update ) {
		$name       = ! empty( $command['name'] ) ? (string) $command['name'] : '';
		$is_read    = $this->is_read_only_command( $command, $update );
		$is_callback = ! empty( $update['callback_query'] );

		if ( $is_callback && $is_read ) {
			return 5;
		}

		if ( $is_callback ) {
			return 10;
		}

		if ( in_array( $name, array( '/start', '/help', '/menu', '/chatid', '/link', '/unlink' ), true ) ) {
			return 15;
		}

		if ( $is_read ) {
			return 35;
		}

		return 25;
	}

	private function get_job_stale_after( $command, $update ) {
		$ttl = $this->is_read_only_command( $command, $update ) ? 120 : 300;

		if ( ! empty( $update['callback_query'] ) ) {
			$ttl = min( $ttl, 120 );
		}

		return gmdate( 'Y-m-d H:i:s', time() + $ttl );
	}

	private function build_job_group( $command, $placeholder, $is_replaceable ) {
		if ( empty( $placeholder['chat_id'] ) ) {
			return '';
		}

		if ( $is_replaceable ) {
			return 'replaceable:' . md5( (string) $placeholder['chat_id'] );
		}

		return 'command:' . md5( (string) $placeholder['chat_id'] . ':' . ( ! empty( $command['name'] ) ? (string) $command['name'] : 'unknown' ) );
	}

	private function maybe_cleanup_superseded_jobs( $jobs, $current_placeholder ) {
		if ( empty( $jobs ) || ! is_array( $jobs ) ) {
			return;
		}

		foreach ( $jobs as $job ) {
			if ( empty( $job['placeholder_chat_id'] ) || empty( $job['placeholder_message_id'] ) ) {
				continue;
			}

			if (
				! empty( $current_placeholder['chat_id'] ) &&
				! empty( $current_placeholder['message_id'] ) &&
				(string) $job['placeholder_chat_id'] === (string) $current_placeholder['chat_id'] &&
				(int) $job['placeholder_message_id'] === (int) $current_placeholder['message_id']
			) {
				continue;
			}

			$this->client->edit_message_text(
				(string) $job['placeholder_chat_id'],
				(int) $job['placeholder_message_id'],
				Telepilot_Telegram_Response_Builder::italic( __( 'Replaced by a newer request.', 'wp-telepilot' ) ),
				array(
					'parse_mode' => 'HTML',
				)
			);
		}
	}

	private function is_replaceable_command( $command, $update ) {
		return $this->is_read_only_command( $command, $update );
	}

	private function is_read_only_command( $command, $update ) {
		$name       = ! empty( $command['name'] ) ? (string) $command['name'] : '';
		$subcommand = $this->get_command_subcommand( $command );

		if ( in_array( $name, array( '/start', '/help', '/menu', '/chatid', '/site', '/dashboard' ), true ) ) {
			return true;
		}

		switch ( $name ) {
			case '/settings':
				return in_array( $subcommand, array( '', 'summary', 'list', 'help' ), true );
			case '/notifications':
				return in_array( $subcommand, array( '', 'default', 'list', 'status', 'help' ), true );
			case '/comments':
				return in_array( $subcommand, array( '', 'default', 'pending', 'approved', 'spam', 'trash', 'help', 'details' ), true );
			case '/posts':
				return in_array( $subcommand, array( '', 'default', 'list', 'latest', 'drafts', 'search', 'stats', 'trashed', 'help' ), true );
			case '/pages':
				return in_array( $subcommand, array( '', 'default', 'list', 'latest', 'drafts', 'search', 'trashed', 'help' ), true );
			case '/media':
				return in_array( $subcommand, array( '', 'default', 'list', 'recent', 'search', 'details', 'help' ), true );
			case '/users':
				return in_array( $subcommand, array( '', 'default', 'list', 'search', 'details', 'help' ), true );
			case '/plugins':
				return in_array( $subcommand, array( '', 'default', 'list', 'search', 'updates', 'details', 'help' ), true );
			case '/categories':
			case '/tags':
				return in_array( $subcommand, array( '', 'default', 'list', 'search', 'details', 'help' ), true );
			default:
				return false;
		}
	}

	private function get_command_subcommand( $command ) {
		$args = ! empty( $command['args'] ) && is_array( $command['args'] ) ? $command['args'] : array();

		return ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : '';
	}

	private function get_cached_command_response( $identity, $command, $update ) {
		if ( ! $this->should_cache_command( $command, $update ) ) {
			return null;
		}

		$cached = get_transient( $this->build_response_cache_key( $identity, $command ) );

		return is_array( $cached ) ? $cached : null;
	}

	private function maybe_cache_command_response( $identity, $command, $update, $result ) {
		if ( ! $this->should_cache_command( $command, $update ) ) {
			return;
		}

		if ( empty( $result['ok'] ) || empty( $result['message'] ) || ! is_array( $result ) ) {
			return;
		}

		set_transient(
			$this->build_response_cache_key( $identity, $command ),
			$result,
			self::RESPONSE_CACHE_TTL
		);
	}

	private function should_cache_command( $command, $update ) {
		return $this->is_read_only_command( $command, $update );
	}

	private function build_response_cache_key( $identity, $command ) {
		$subject = ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User
			? 'user:' . $identity['wp_user']->ID
			: 'chat:' . ( ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : 'unknown' );

		return 'telepilot_resp_' . md5(
			wp_json_encode(
				array(
					$subject,
					! empty( $command['raw'] ) ? (string) $command['raw'] : '',
					get_locale(),
				)
			)
		);
	}

	private function get_stale_update_window() {
		$settings = get_option( 'telepilot_settings', array() );
		$window   = isset( $settings[ self::STALE_UPDATE_WINDOW_OPTION ] ) ? (int) $settings[ self::STALE_UPDATE_WINDOW_OPTION ] : self::DEFAULT_STALE_WINDOW;

		return max( 30, $window );
	}

	private function is_stale_update( $update ) {
		$timestamp = $this->extract_update_timestamp( $update );

		if ( ! $timestamp ) {
			return false;
		}

		return ( current_time( 'timestamp', true ) - $timestamp ) > $this->get_stale_update_window();
	}

	private function extract_update_timestamp( $update ) {
		if ( ! empty( $update['message']['date'] ) ) {
			return (int) $update['message']['date'];
		}

		if ( ! empty( $update['callback_query']['id'] ) ) {
			return current_time( 'timestamp', true );
		}

		return 0;
	}

	private function record_stale_update( $transport, $update ) {
		$this->update_diagnostics(
			array(
				'last_stale_update_at' => time(),
				'last_stale_update_id' => isset( $update['update_id'] ) ? (int) $update['update_id'] : 0,
				'last_transport'       => $transport,
			),
			array(
				'stale_updates_dropped' => 1,
			)
		);

		Telepilot_Audit_Log_Repository::log(
			array(
				'action_name'     => 'telegram_update_stale',
				'resource_type'   => 'telegram_update',
				'resource_id'     => isset( $update['update_id'] ) ? (string) $update['update_id'] : null,
				'was_successful'  => 0,
				'failure_reason'  => 'Skipped stale Telegram update.',
				'context'         => array(
					'transport' => $transport,
					'date'      => $this->extract_update_timestamp( $update ),
				),
			)
		);
	}

	private function record_transport_activity( $transport, $update ) {
		$data = array(
			'last_transport'      => $transport,
			'last_processed_at'   => time(),
			'last_update_id'      => isset( $update['update_id'] ) ? (int) $update['update_id'] : 0,
			'last_update_message' => isset( $update['message']['text'] ) ? sanitize_text_field( $update['message']['text'] ) : ( isset( $update['callback_query']['data'] ) ? sanitize_text_field( $update['callback_query']['data'] ) : '' ),
		);

		if ( 'webhook' === $transport ) {
			$data['last_webhook_received_at'] = time();
		}

		$this->update_diagnostics( $data );
	}

	private function update_diagnostics( $data, $increments = array() ) {
		$diagnostics = get_option( self::DIAGNOSTICS_OPTION, array() );

		foreach ( $increments as $key => $amount ) {
			$diagnostics[ $key ] = isset( $diagnostics[ $key ] ) ? (int) $diagnostics[ $key ] + (int) $amount : (int) $amount;
			if ( $diagnostics[ $key ] < 0 ) {
				$diagnostics[ $key ] = 0;
			}
		}

		foreach ( $data as $key => $value ) {
			$diagnostics[ $key ] = $value;
		}

		update_option( self::DIAGNOSTICS_OPTION, $diagnostics, false );
	}

	private function refresh_queue_diagnostics() {
		$counts = Telepilot_Jobs_Repository::status_counts();

		$this->update_diagnostics(
			array(
				'queued_updates'  => (int) $counts['pending'] + (int) $counts['processing'],
				'failed_jobs'     => (int) $counts['failed'],
				'processing_jobs' => (int) $counts['processing'],
				'stale_jobs'      => ! empty( $counts['stale'] ) ? (int) $counts['stale'] : 0,
			)
		);
	}

	private function perform_route_self_test( $url, $args ) {
		$start_time   = microtime( true );
		$response     = wp_remote_post(
			$url,
			$this->build_loopback_request_args(
				$url,
				8,
				true,
				! empty( $args['headers'] ) ? $args['headers'] : array(),
				isset( $args['body'] ) ? $args['body'] : array()
			)
		);
		$duration_ms  = (int) round( max( 0, microtime( true ) - $start_time ) * 1000 );
		$success_codes = ! empty( $args['success_codes'] ) && is_array( $args['success_codes'] ) ? $args['success_codes'] : array( 200 );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'status_code' => 0,
				'duration_ms' => $duration_ms,
				'message'     => $response->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$message     = is_array( $body ) && ! empty( $body['message'] )
			? (string) $body['message']
			: sprintf( __( 'HTTP %d response received.', 'wp-telepilot' ), $status_code );

		return array(
			'ok'          => in_array( $status_code, $success_codes, true ),
			'status_code' => $status_code,
			'duration_ms' => $duration_ms,
			'message'     => $message,
		);
	}

	private function build_loopback_request_args( $url, $timeout, $blocking, $headers, $body ) {
		$args = array(
			'timeout'  => max( 1, (int) $timeout ),
			'blocking' => (bool) $blocking,
			'headers'  => is_array( $headers ) ? $headers : array(),
			'body'     => $body,
		);

		if ( $this->is_local_loopback_url( $url ) ) {
			$args['sslverify'] = (bool) apply_filters( 'https_local_ssl_verify', false );
		}

		return $args;
	}

	private function is_local_loopback_url( $url ) {
		$target_host = wp_parse_url( (string) $url, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return ! empty( $target_host ) && ! empty( $site_host ) && strtolower( (string) $target_host ) === strtolower( (string) $site_host );
	}

	private function record_command_timing( $command_name, $transport, $start_time, $dispatch_error = '' ) {
		$duration_ms = (int) round( max( 0, microtime( true ) - (float) $start_time ) * 1000 );
		$history     = get_option( self::COMMAND_DIAGNOSTICS_OPTION, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'command'        => (string) $command_name,
			'transport'      => (string) $transport,
			'duration_ms'    => $duration_ms,
			'dispatch_error' => '' !== $dispatch_error ? (string) $dispatch_error : '',
			'recorded_at'    => time(),
		);

		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}

		update_option( self::COMMAND_DIAGNOSTICS_OPTION, $history, false );

		$this->update_diagnostics(
			array(
				'last_command_name'        => (string) $command_name,
				'last_command_transport'   => (string) $transport,
				'last_command_duration_ms' => $duration_ms,
				'last_command_error'       => '' !== $dispatch_error ? (string) $dispatch_error : '',
			),
			$duration_ms >= 2000
				? array( 'slow_commands' => 1 )
				: array()
		);
	}

	private function mark_update_processed( $update, $transport, $result ) {
		if ( empty( $update['update_id'] ) ) {
			return;
		}

		Telepilot_Processed_Updates_Repository::mark_processed( (int) $update['update_id'], $transport, $result );
	}

	private function poll_lock_exists() {
		return (bool) get_transient( self::POLL_LOCK_TRANSIENT );
	}

	private function acquire_poll_lock() {
		set_transient( self::POLL_LOCK_TRANSIENT, time(), MINUTE_IN_SECONDS );
	}

	private function release_poll_lock() {
		delete_transient( self::POLL_LOCK_TRANSIENT );
	}
}
