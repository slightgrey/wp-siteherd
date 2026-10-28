<?php
/**
 * Removes everything the plugin stored. Runs when the plugin is deleted.
 *
 * @package WP_Siteherd
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

$siteherd_options = array(
	'siteherd_connection',
	'siteherd_last_result',
	'siteherd_failed_logins',
	'siteherd_health',
	'siteherd_integrity',
	'siteherd_checks_last_run',
	'siteherd_version',
	'siteherd_sso_enabled',
	'siteherd_sso_user',
	'siteherd_sso_log',
	'siteherd_sso_skip_2fa',
	'siteherd_update_job',
);
foreach ( $siteherd_options as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'siteherd_send_report' );
wp_clear_scheduled_hook( 'siteherd_send_report_soon' );
wp_clear_scheduled_hook( 'siteherd_daily_checks' );
wp_unschedule_hook( 'siteherd_run_update_job' );

// Update backups and copies saved for rollback.
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( WP_Filesystem() ) {
	global $wp_filesystem;
	$wp_filesystem->delete( WP_CONTENT_DIR . '/siteherd-backups', true );
}
// Replay-protection transients (siteherd_sig_*) expire on their own within 10 minutes.
