<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Post_Editor_Service {
	const TOKEN_PREFIX      = 'telepilot_post_editor_';
	const DEFAULT_EXPIRY    = 3600;
	const HANDLER_ACTION    = 'telepilot_post_editor';

	public function create_edit_link( $post_id, WP_User $wp_user, $telegram_user_id = '', $expiration = self::DEFAULT_EXPIRY ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
		}

		if ( ! user_can( $wp_user, 'edit_post', $post_id ) ) {
			return new WP_Error( 'telepilot_post_edit_denied', __( 'You do not have permission to edit that post.', 'wp-telepilot' ) );
		}

		$identity = array( 'wp_user' => $wp_user, 'telegram_user_id' => (string) $telegram_user_id, 'chat_id' => (string) get_user_meta( $wp_user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ), 'chat_type' => 'private' );
		$access = Telepilot_Actor_Access::check( $identity, 'edit_post', $post_id );
		if ( is_wp_error( $access ) ) { return $access; }
		$expiration = min( self::DEFAULT_EXPIRY, max( 300, absint( $expiration ) ) );
		$expires_at = time() + $expiration;
		$token = Telepilot_Security_Records::issue(
			'post_editor',
			array(
				'post_id'          => (int) $post_id,
				'wp_user_id'       => (int) $wp_user->ID,
				'telegram_user_id' => (string) $telegram_user_id,
				'created_at'       => time(),
				'expires_at'       => $expires_at,
				'revision'         => self::revision( $post ),
			),
			$wp_user->ID,
			$expiration
		);
		if ( ! $token ) { return new WP_Error( 'telepilot_editor_storage_failed', __( 'Unable to create a secure editor link.', 'wp-telepilot' ) ); }

		return array(
			'token'      => $token,
			'url'        => $this->get_handler_url( $token ),
			'expires_at' => $expires_at,
			'post'       => $post,
		);
	}

	public function handle_request() {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: frame-ancestors 'none'; form-action 'self'; base-uri 'none'" );

		$token   = $this->get_request_token();
		$session = $this->get_session( $token );

		if ( empty( $token ) || empty( $session ) ) {
			$this->render_error_page(
				__( 'Link Expired', 'wp-telepilot' ),
				__( 'That post editor link is invalid, expired, or has already been used. Generate a fresh link from Telegram and try again.', 'wp-telepilot' ),
				410
			);
		}

		if ( $this->is_session_expired( $session ) ) {
			$this->delete_session( $token );
			$this->render_error_page(
				__( 'Link Expired', 'wp-telepilot' ),
				__( 'That post editor link has expired. Generate a fresh link from Telegram and try again.', 'wp-telepilot' ),
				410
			);
		}

		$post = get_post( (int) $session['post_id'] );
		$user = get_user_by( 'id', (int) $session['wp_user_id'] );

		if ( ! $post || 'post' !== $post->post_type || ! $user instanceof WP_User || ! user_can( $user, 'edit_post', $post->ID ) ) {
			$this->delete_session( $token );
			$this->render_error_page(
				__( 'Editor Unavailable', 'wp-telepilot' ),
				__( 'That post can no longer be edited with this link.', 'wp-telepilot' ),
				403
			);
		}

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			$this->handle_save_request( $token, $session, $post, $user );
		}

		$this->render_editor_page( $post, $token );
	}

	private function handle_save_request( $token, $session, $post, $user ) {
		check_admin_referer( 'telepilot_post_editor_' . $token );

		$title   = isset( $_POST['telepilot_post_title'] ) && is_string( $_POST['telepilot_post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['telepilot_post_title'] ) ) : $post->post_title;
		$excerpt = isset( $_POST['telepilot_post_excerpt'] ) && is_string( $_POST['telepilot_post_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['telepilot_post_excerpt'] ) ) : $post->post_excerpt;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw rich content is filtered below according to the linked user's unfiltered_html capability.
		$content = isset( $_POST['telepilot_post_content'] ) && is_string( $_POST['telepilot_post_content'] ) ? wp_unslash( $_POST['telepilot_post_content'] ) : $post->post_content;

		if ( ! user_can( $user, 'unfiltered_html' ) ) {
			$content = wp_kses_post( $content );
		}

		$before_state = array(
			'title'          => (string) $post->post_title,
			'excerpt_length' => strlen( (string) $post->post_excerpt ),
			'content_length' => strlen( (string) $post->post_content ),
			'status'         => (string) $post->post_status,
		);

		$result = $this->save( $token, array( 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => $content ) );

		if ( is_wp_error( $result ) ) {
			$post->post_title   = $title;
			$post->post_excerpt = $excerpt;
			$post->post_content = $content;
			$this->render_error_page( __( 'Save Not Completed', 'wp-telepilot' ), $result->get_error_message(), 409 );
		}

		$updated_post = get_post( $post->ID );
		$this->delete_session( $token );

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $user->ID,
				'telegram_user_id' => ! empty( $session['telegram_user_id'] ) ? (string) $session['telegram_user_id'] : null,
				'action_name'      => 'post_browser_editor_saved',
				'resource_type'    => 'post',
				'resource_id'      => (string) $post->ID,
				'before_state'     => $before_state,
				'after_state'      => array(
					'title'          => (string) $updated_post->post_title,
					'excerpt_length' => strlen( (string) $updated_post->post_excerpt ),
					'content_length' => strlen( (string) $updated_post->post_content ),
					'status'         => (string) $updated_post->post_status,
				),
			)
		);

		$this->render_success_page( $updated_post );
	}

	private function render_editor_page( $post, $token, $notice = '', $notice_type = 'info' ) {
		$preview_url  = get_permalink( $post );
		$status_label = ucfirst( (string) $post->post_status );
		$title        = get_the_title( $post );
		$logo_url     = $this->get_logo_url();
		$expires_at   = time() + self::DEFAULT_EXPIRY;

		$session = $this->get_session( $token );
		if ( ! empty( $session['expires_at'] ) ) {
			$expires_at = (int) $session['expires_at'];
		}

		status_header( 200 );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( sprintf( /* translators: %s: display title. */ __( 'Edit Post: %s', 'wp-telepilot' ), $title ) ); ?></title>
			<style>
				:root{--tp-ink:#1f2933;--tp-ink-strong:#111827;--tp-cyan:#0ea5d3;--tp-cyan-deep:#0b88af;--tp-cyan-soft:#e7f8fd;--tp-border:#d5dde3;--tp-panel:#ffffff;--tp-surface:#f3f7f9;--tp-muted:#52606d;--tp-danger:#b42318;--tp-danger-bg:#fef3f2;--tp-shadow:0 24px 70px rgba(15,23,42,.08)}
				*{box-sizing:border-box}
				body{margin:0;background:
					radial-gradient(circle at top left,rgba(14,165,211,.14),transparent 26%),
					radial-gradient(circle at top right,rgba(31,41,51,.1),transparent 30%),
					linear-gradient(180deg,#f8fbfd 0%,#eef4f7 100%);
					color:var(--tp-ink);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:1080px;margin:0 auto;padding:32px 20px 48px}
				.card{background:var(--tp-panel);border:1px solid var(--tp-border);border-radius:28px;box-shadow:var(--tp-shadow);padding:28px}
				.hero{margin-bottom:20px;padding:28px;border-radius:30px;background:
					linear-gradient(135deg,rgba(14,165,211,.14),rgba(14,165,211,0) 42%),
					linear-gradient(135deg,var(--tp-ink),var(--tp-ink-strong));color:#fff;position:relative;overflow:hidden}
				.hero:after{content:"";position:absolute;right:-80px;top:-80px;width:240px;height:240px;border-radius:50%;background:rgba(14,165,211,.12)}
				.brand{display:flex;align-items:center;gap:14px;margin-bottom:16px;position:relative;z-index:1}
				.brand img{width:52px;height:52px;object-fit:contain;border-radius:16px;background:rgba(255,255,255,.08);padding:8px}
				.brand-copy{display:flex;flex-direction:column;gap:4px}
				.kicker{margin:0;font-size:11px;font-weight:800;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.7)}
				.product{margin:0;font-size:13px;font-weight:700;color:#d5eef7}
				h1{margin:0;font-size:34px;line-height:1.1;position:relative;z-index:1}
				p{line-height:1.7}
				.hero p{position:relative;z-index:1;max-width:780px}
				.meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;position:relative;z-index:1}
				.pill{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;background:var(--tp-cyan-soft);color:var(--tp-cyan-deep);border:1px solid rgba(14,165,211,.18)}
				.notice{margin-bottom:18px;padding:14px 16px;border-radius:18px;border:1px solid var(--tp-border);background:#f8fbff}
				.notice.error{background:var(--tp-danger-bg);border-color:#f3c1bc;color:var(--tp-danger)}
				.grid{display:grid;gap:18px}
				label{display:block;font-weight:700;margin-bottom:8px}
				input[type=text],textarea{width:100%;border:1px solid var(--tp-border);border-radius:18px;background:var(--tp-surface);padding:14px 16px;color:var(--tp-ink);font:inherit;transition:border-color .2s ease, box-shadow .2s ease, background-color .2s ease}
				input[type=text]:focus,textarea:focus{outline:none;border-color:var(--tp-cyan);background:#fff;box-shadow:0 0 0 4px rgba(14,165,211,.14)}
				textarea.content{min-height:420px;line-height:1.7}
				textarea.excerpt{min-height:120px}
				.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
				button,a.button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;border:0;text-decoration:none;font-weight:700;cursor:pointer}
				button.primary{background:linear-gradient(135deg,var(--tp-cyan),var(--tp-cyan-deep));color:#fff;box-shadow:0 14px 30px rgba(14,165,211,.22)}
				button.primary:hover,a.primary:hover{filter:brightness(1.03)}
				a.secondary{background:#fff;color:var(--tp-ink);border:1px solid var(--tp-border)}
				.help{margin-top:20px;color:var(--tp-muted)}
				.expiry{margin-top:14px;font-size:13px;color:#d5eef7}
				.expiry strong{color:#fff}
				@media (max-width: 720px){.shell{padding:20px 14px 36px}.card,.hero{padding:22px;border-radius:24px}h1{font-size:28px}.brand{align-items:flex-start}.brand img{width:44px;height:44px}}
			</style>
		</head>
		<body>
			<div class="shell">
				<section class="hero">
					<div class="brand">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WP Telepilot logo', 'wp-telepilot' ); ?>">
						<?php endif; ?>
						<div class="brand-copy">
							<p class="kicker"><?php esc_html_e( 'WP Telepilot Long-Form Editor', 'wp-telepilot' ); ?></p>
							<p class="product"><?php esc_html_e( 'Secure browser bridge for Telegram-managed post editing', 'wp-telepilot' ); ?></p>
						</div>
					</div>
					<h1><?php echo esc_html( $title ); ?></h1>
					<div class="meta">
						<span class="pill"><?php echo esc_html( sprintf( /* translators: %d: post ID. */ __( 'Post [%d]', 'wp-telepilot' ), $post->ID ) ); ?></span>
						<span class="pill"><?php echo esc_html( $status_label ); ?></span>
					</div>
					<p><?php esc_html_e( 'This secure browser editor is designed for longer post changes that are awkward to make in Telegram chat. Save here, then continue your workflow back in Telegram.', 'wp-telepilot' ); ?></p>
					<p class="expiry"><strong><?php esc_html_e( 'Session expiry:', 'wp-telepilot' ); ?></strong> <?php echo esc_html( wp_date( 'Y-m-d H:i:s T', $expires_at ) ); ?></p>
				</section>

				<section class="card">
					<?php if ( '' !== $notice ) : ?>
						<div class="notice <?php echo 'error' === $notice_type ? 'error' : ''; ?>"><?php echo esc_html( $notice ); ?></div>
					<?php endif; ?>

					<form method="post">
						<?php wp_nonce_field( 'telepilot_post_editor_' . $token ); ?>
						<input type="hidden" name="telepilot_post_editor_token" value="<?php echo esc_attr( $token ); ?>">
						<div class="grid">
							<div>
								<label for="telepilot-post-title"><?php esc_html_e( 'Title', 'wp-telepilot' ); ?></label>
								<input id="telepilot-post-title" type="text" name="telepilot_post_title" value="<?php echo esc_attr( $post->post_title ); ?>">
							</div>
							<div>
								<label for="telepilot-post-excerpt"><?php esc_html_e( 'Excerpt', 'wp-telepilot' ); ?></label>
								<textarea id="telepilot-post-excerpt" class="excerpt" name="telepilot_post_excerpt"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
							</div>
							<div>
								<label for="telepilot-post-content"><?php esc_html_e( 'Content', 'wp-telepilot' ); ?></label>
								<textarea id="telepilot-post-content" class="content" name="telepilot_post_content"><?php echo esc_textarea( $post->post_content ); ?></textarea>
							</div>
						</div>

						<div class="actions">
							<button class="primary" type="submit"><?php esc_html_e( 'Save Changes', 'wp-telepilot' ); ?></button>
							<?php if ( 'publish' === $post->post_status && $preview_url ) : ?>
								<a class="button secondary" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview Post', 'wp-telepilot' ); ?></a>
							<?php endif; ?>
						</div>
					</form>

					<p class="help"><?php esc_html_e( 'This link expires automatically and is intended only for this editing session. After saving, return to Telegram to publish, review, or continue managing the post.', 'wp-telepilot' ); ?></p>
				</section>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function render_success_page( $post ) {
		$preview_url = get_permalink( $post );
		$logo_url    = $this->get_logo_url();

		status_header( 200 );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Post Saved', 'wp-telepilot' ); ?></title>
			<style>
				:root{--tp-ink:#1f2933;--tp-ink-strong:#111827;--tp-cyan:#0ea5d3;--tp-cyan-deep:#0b88af;--tp-cyan-soft:#e7f8fd;--tp-border:#d5dde3;--tp-panel:#ffffff;--tp-shadow:0 24px 70px rgba(15,23,42,.08)}
				*{box-sizing:border-box}
				body{margin:0;background:
					radial-gradient(circle at top left,rgba(14,165,211,.14),transparent 26%),
					linear-gradient(180deg,#f8fbfd 0%,#eef4f7 100%);
					color:var(--tp-ink);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:760px;margin:0 auto;padding:40px 20px}
				.card{background:var(--tp-panel);border:1px solid var(--tp-border);border-radius:28px;box-shadow:var(--tp-shadow);padding:32px}
				.brand{display:flex;align-items:center;gap:14px}
				.brand img{width:48px;height:48px;object-fit:contain;border-radius:14px;background:#f3f7f9;padding:8px}
				.badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;background:var(--tp-cyan-soft);color:var(--tp-cyan-deep);font-size:11px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;border:1px solid rgba(14,165,211,.18)}
				h1{margin:16px 0 12px;font-size:34px;line-height:1.1;color:var(--tp-ink-strong)}
				p{line-height:1.8}
				.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
				a.button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700}
				a.primary{background:linear-gradient(135deg,var(--tp-cyan),var(--tp-cyan-deep));color:#fff;box-shadow:0 14px 30px rgba(14,165,211,.22)}
				a.secondary{background:#fff;color:var(--tp-ink);border:1px solid var(--tp-border)}
			</style>
		</head>
		<body>
			<div class="shell">
				<div class="card">
					<div class="brand">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WP Telepilot logo', 'wp-telepilot' ); ?>">
						<?php endif; ?>
						<span class="badge"><?php esc_html_e( 'Saved', 'wp-telepilot' ); ?></span>
					</div>
					<h1><?php esc_html_e( 'Post updated successfully', 'wp-telepilot' ); ?></h1>
					<p><?php esc_html_e( 'Your long-form changes were saved. You can now return to Telegram to keep working with WP Telepilot.', 'wp-telepilot' ); ?></p>
					<div class="actions">
						<?php if ( 'publish' === $post->post_status && $preview_url ) : ?>
							<a class="button primary" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview Post', 'wp-telepilot' ); ?></a>
						<?php endif; ?>
						<a class="button secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Site', 'wp-telepilot' ); ?></a>
					</div>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function render_error_page( $title, $message, $status_code = 403 ) {
		$logo_url = $this->get_logo_url();

		status_header( (int) $status_code );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ); ?></title>
			<style>
				:root{--tp-ink:#1f2933;--tp-danger:#b42318;--tp-danger-bg:#fef3f2;--tp-danger-border:#f3c1bc}
				*{box-sizing:border-box}
				body{margin:0;background:
					radial-gradient(circle at top left,rgba(180,35,24,.08),transparent 24%),
					linear-gradient(180deg,#f8fbfd 0%,#eef4f7 100%);
					color:var(--tp-ink);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:760px;margin:0 auto;padding:48px 20px}
				.card{background:#fff;border:1px solid var(--tp-danger-border);border-radius:28px;box-shadow:0 24px 70px rgba(15,23,42,.06);padding:32px}
				.brand{display:flex;align-items:center;gap:14px;margin-bottom:18px}
				.brand img{width:44px;height:44px;object-fit:contain;border-radius:14px;background:#f3f7f9;padding:8px}
				.label{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;background:var(--tp-danger-bg);color:var(--tp-danger);font-size:11px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
				h1{margin:0 0 12px;font-size:34px;line-height:1.1;color:var(--tp-danger)}
				p{line-height:1.8}
			</style>
		</head>
		<body>
			<div class="shell">
				<div class="card">
					<div class="brand">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WP Telepilot logo', 'wp-telepilot' ); ?>">
						<?php endif; ?>
						<span class="label"><?php esc_html_e( 'Unavailable', 'wp-telepilot' ); ?></span>
					</div>
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function get_request_token() {
		// Token lookup precedes session validation and the token-bound save nonce. GET only displays the editor.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The save handler verifies the token-bound nonce before processing content.
		if ( isset( $_POST['telepilot_post_editor_token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only read the token needed to locate the session and verify its nonce.
			return is_string( $_POST['telepilot_post_editor_token'] ) ? $this->normalize_token( sanitize_text_field( wp_unslash( $_POST['telepilot_post_editor_token'] ) ) ) : '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display uses an expiring, actor-bound editor token.
		if ( isset( $_GET['token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saving separately requires a token-bound nonce.
			return is_string( $_GET['token'] ) ? $this->normalize_token( sanitize_text_field( wp_unslash( $_GET['token'] ) ) ) : '';
		}

		return '';
	}

	private function get_session( $token ) {
		if ( '' === $token ) {
			return array();
		}

		$session = Telepilot_Security_Records::read( $token, 'post_editor' );
		if ( is_array( $session ) ) {
			$identity = $this->session_identity( $session );
			if ( true !== Telepilot_Actor_Access::check( $identity, 'edit_post', (int) $session['post_id'] ) ) { return array(); }
		}

		return is_array( $session ) ? $session : array();
	}

	private function delete_session( $token ) {
		if ( '' === $token ) {
			return;
		}

		Telepilot_Security_Records::claim( $token, 'post_editor' );
	}

	private function get_handler_url( $token ) {
		return add_query_arg(
			array(
				'action' => self::HANDLER_ACTION,
				'token'  => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private function normalize_token( $token ) {
		return is_string( $token ) && preg_match( '/^[a-f0-9]{24}$/D', $token ) ? $token : '';
	}

	private function is_session_expired( $session ) {
		if ( empty( $session['expires_at'] ) ) {
			return true;
		}

		return (int) $session['expires_at'] <= time();
	}

	private function get_logo_url() {
		$logo_path = TELEPILOT_PATH . 'assets/images/telepilot-logo.png';

		if ( ! file_exists( $logo_path ) ) {
			return '';
		}

		return TELEPILOT_URL . 'assets/images/telepilot-logo.png';
	}

	public static function revision( $post ) {
		return hash( 'sha256', wp_json_encode( array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status, $post->post_modified_gmt, $post->post_author ) ) );
	}

	private function session_identity( $session ) {
		$user = get_user_by( 'id', (int) $session['wp_user_id'] );
		return array( 'wp_user' => $user, 'telegram_user_id' => (string) $session['telegram_user_id'], 'chat_id' => (string) get_user_meta( $session['wp_user_id'], Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true ), 'chat_type' => 'private', 'linkage_generation' => $session['_security_binding']['generation'] ?? '' );
	}

	/** The HTTP handler verifies the nonce; the bearer token authorizes this operation. */
	public function save( $token, $fields ) {
		global $wpdb;
		$session = $this->get_session( $token );
		if ( ! $session || $this->is_session_expired( $session ) ) {
			return new WP_Error( 'telepilot_editor_expired', __( 'This link is expired, revoked, or already used. Request a new link.', 'wp-telepilot' ) );
		}
		if ( ! Telepilot_Security_Records::claim( $token, 'post_editor' ) ) {
			return new WP_Error( 'telepilot_editor_used', __( 'This link is already being used. Do not submit again.', 'wp-telepilot' ) );
		}
		$identity = $this->session_identity( $session );
		$check = Telepilot_Actor_Access::check( $identity, 'edit_post', (int) $session['post_id'] );
		if ( is_wp_error( $check ) ) { return $check; }
		Telepilot_DB_Transaction::begin();
		try {
			// Lock the actual row, not a possibly stale object-cache snapshot.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock the current post row while comparing its revision and atomically consuming its editor token.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID=%d FOR UPDATE', $wpdb->posts, $session['post_id'] ) );
			if ( ! $row || 'post' !== $row->post_type || ! hash_equals( (string) $session['revision'], self::revision( $row ) ) ) {
				Telepilot_DB_Transaction::rollback();
				return new WP_Error( 'telepilot_editor_conflict', __( 'This post changed after the link was created. Your changes were not saved. Request a fresh link to review the latest version.', 'wp-telepilot' ) );
			}
			$values = array( 'ID' => (int) $row->ID );
			foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $key ) {
				if ( ! isset( $fields[ $key ] ) || ! is_string( $fields[ $key ] ) ) { continue; }
				$value = $fields[ $key ];
				if ( 'post_title' === $key ) { $value = sanitize_text_field( $value ); }
				elseif ( 'post_excerpt' === $key ) { $value = sanitize_textarea_field( $value ); }
				elseif ( ! user_can( $identity['wp_user'], 'unfiltered_html' ) ) { $value = wp_kses_post( $value ); }
				$values[ $key ] = $value;
			}
			$result = Telepilot_Actor_Access::as_actor( $identity, static function () use ( $values ) { return wp_update_post( wp_slash( $values ), true ); } );
			if ( is_wp_error( $result ) || ! is_int( $result ) ) {
				Telepilot_DB_Transaction::rollback();
				clean_post_cache( $row->ID );
				return is_wp_error( $result ) ? $result : new WP_Error( 'telepilot_editor_denied', __( 'Access changed before saving. Request a new link.', 'wp-telepilot' ) );
			}
			Telepilot_DB_Transaction::commit();
			return $result;
		} catch ( Throwable $error ) {
			Telepilot_DB_Transaction::rollback();
			clean_post_cache( (int) $session['post_id'] );
			throw $error;
		}
	}
}
