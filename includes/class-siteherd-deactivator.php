<?php
/**
 * Runs on plugin deactivation. The connection is kept so reactivating resumes
 * reporting; uninstall.php removes it.
 *
 * @package WP_Siteherd
 */

class Siteherd_Deactivator {

	public static function deactivate() {
		require_once SITEHERD_DIR . 'includes/class-siteherd-reporter.php';
		require_once SITEHERD_DIR . 'includes/class-siteherd-checks.php';
		Siteherd_Reporter::unschedule();
	}
}
