<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Best-effort feedback scoped to active Telegram execution, never delivery. */
final class Telepilot_Telegram_Activity {
	const REFRESH_INTERVAL = 4;
	private static $current = null;
	private $client;
	private $chat_id;
	private $clock;
	private $running = false;
	private $sending = false;
	private $failed = false;
	private $last_sent = null;

	public function __construct( $client, $chat_id, $clock = null ) {
		$this->client = $client;
		$this->chat_id = (string) $chat_id;
		$this->clock = $clock ?: static function () { return microtime( true ); };
	}

	public function run( callable $callback, $start_immediately = true ) {
		$previous = self::$current;
		self::$current = $this;
		$this->running = true;
		$this->failed = false;
		$this->last_sent = null;
		add_action( 'http_api_curl', array( $this, 'attach_progress' ), 10, 3 );
		try {
			if ( $start_immediately ) { $this->refresh(); }
			return $callback();
		} finally {
			$this->running = false;
			remove_action( 'http_api_curl', array( $this, 'attach_progress' ), 10 );
			self::$current = $previous;
		}
	}

	public static function pulse() {
		if ( self::$current ) { self::$current->refresh(); }
	}

	public function refresh() {
		if ( ! $this->running || self::$current !== $this || $this->sending || $this->failed || '' === $this->chat_id ) { return; }
		$this->sending = true;
		try {
			$now = ( $this->clock )();
			if ( null !== $this->last_sent && $now - $this->last_sent < self::REFRESH_INTERVAL ) { return; }
			$this->last_sent = $now;
			$this->failed = is_wp_error( $this->client->send_chat_action( $this->chat_id, 'typing' ) );
		} catch ( Throwable $error ) {
			// Optional feedback must never interrupt or replay a WordPress operation.
			$this->failed = true;
		} finally {
			$this->sending = false;
		}
	}

	public function attach_progress( $handle, $args, $url ) {
		if ( ! $this->running || self::$current !== $this || $this->sending || $this->failed || '' === $this->chat_id || empty( $args['blocking'] ) || ! function_exists( 'curl_setopt' ) || ( ! defined( 'CURLOPT_XFERINFOFUNCTION' ) && ! defined( 'CURLOPT_PROGRESSFUNCTION' ) ) ) { return; }
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		// These calls acknowledge/deliver feedback, or spawn another local worker.
		if ( 'api.telegram.org' === $host && preg_match( '~/(sendChatAction|sendMessage|editMessageText|answerCallbackQuery)$~', (string) $path ) ) { return; }
		if ( $host === wp_parse_url( home_url(), PHP_URL_HOST ) || $host === wp_parse_url( rest_url(), PHP_URL_HOST ) ) { return; }
		try {
			$progress_option = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
			curl_setopt( $handle, $progress_option, function () {
				$this->refresh();
				return 0; // Feedback failure must not abort the underlying transfer.
			} );
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
		} catch ( Throwable $error ) {
			// Other HTTP transports retain the stage-based pulses.
		}
	}
}
