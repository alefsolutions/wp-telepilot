<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_Job_Recovery_Page {
	public static function register() { add_action( 'admin_post_telepilot_reconcile_job', array( __CLASS__, 'handle' ) ); }
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'wp-telepilot' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'telepilot_reconcile_job' );
		$id = absint( $_POST['job_id'] ?? 0 );
		$job = Telepilot_Jobs_Repository::get( $id );
		if ( ! $job || empty( $_POST['reviewed'] ) || ! Telepilot_Jobs_Repository::cancel( $id, 'Administrator reviewed the target and cancelled this job. No action was replayed.' ) ) {
			wp_die( esc_html__( 'Review the WordPress target before cancelling. A live worker cannot be cancelled.', 'wp-telepilot' ), '', array( 'response' => 409 ) );
		}
		Telepilot_Audit_Log_Repository::log( array( 'wp_user_id' => get_current_user_id(), 'action_name' => 'job_reconciled_and_cancelled', 'resource_type' => 'operation', 'resource_id' => (string) $id ) );
		wp_safe_redirect( admin_url( 'admin.php?page=telepilot' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery must show current quarantined jobs and execution outcomes for administrator reconciliation.
		$jobs = $wpdb->get_results( $wpdb->prepare( 'SELECT id,command_name,status,last_error FROM %i WHERE status IN (\'pending\',\'failed\',\'uncertain\') ORDER BY id DESC LIMIT 25', Telepilot_Jobs_Repository::table_name() ), ARRAY_A );
		echo '<section class="telepilot-panel telepilot-panel-wide"><h2>' . esc_html__( 'Queue Recovery', 'wp-telepilot' ) . '</h2><p>' . esc_html__( 'An uncertain execution is never replayed automatically. Inspect its WordPress target, then cancel it to unblock that chat. Delivery failures do not repeat WordPress actions. Showing the latest 25 outstanding jobs.', 'wp-telepilot' ) . '</p><div style="max-height:360px;overflow:auto">';
		foreach ( $jobs as $job ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p><strong>' . esc_html( '[' . $job['id'] . '] ' . $job['command_name'] . ' | ' . $job['status'] ) . '</strong><br>' . esc_html( Telepilot_Data_Redactor::redact( $job['last_error'] ) ) . '</p>';
			wp_nonce_field( 'telepilot_reconcile_job' );
			echo '<input type="hidden" name="action" value="telepilot_reconcile_job"><input type="hidden" name="job_id" value="' . esc_attr( $job['id'] ) . '"><label><input type="checkbox" name="reviewed" value="1" required> ' . esc_html__( 'I reviewed the target and understand cancellation does not undo an action.', 'wp-telepilot' ) . '</label> <button class="button" type="submit">' . esc_html__( 'Cancel Job', 'wp-telepilot' ) . '</button><hr></form>';
		}
		if ( ! $jobs ) { echo '<p>' . esc_html__( 'No outstanding jobs.', 'wp-telepilot' ) . '</p>'; }
		echo '</div></section>';
	}
}
