<?php
/**
 * Runs on plugin activation.
 *
 * @package WP_Siteherd
 */

class Siteherd_Activator {

	/**
	 * Resume reporting if a connection was kept from before a deactivation.
	 * Our cron_schedules filter isn't registered yet when this runs, so register
	 * the interval first or wp_schedule_event() would reject it.
	 */
	public static function activate() {
		require_once SITEHERD_DIR . 'includes/class-siteherd-connection.php';
		require_once SITEHERD_DIR . 'includes/class-siteherd-reporter.php';
		require_once SITEHERD_DIR . 'includes/class-siteherd-checks.php';

		if ( Siteherd_Connection::get() ) {
			add_filter( 'cron_schedules', array( new Siteherd_Reporter(), 'add_cron_schedule' ) );
			Siteherd_Reporter::schedule();
		}
	}
}
