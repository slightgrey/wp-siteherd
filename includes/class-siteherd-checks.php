<?php
/**
 * Daily heavier checks (Site Health, file integrity). Results are stored in
 * options and included in every report until the next run.
 *
 * @package WP_Siteherd
 */

class Siteherd_Checks {

	const CRON_HOOK = 'siteherd_daily_checks';
	const LAST_RUN  = 'siteherd_checks_last_run';

	/** @var Siteherd_Reporter */
	private $reporter;

	public function __construct( Siteherd_Reporter $reporter ) {
		$this->reporter = $reporter;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// First run shortly after connecting, so the dashboard has data the same day.
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run all checks, store the results, then report so the dashboard sees them.
	 */
	public function run() {
		update_option( Siteherd_Site_Health::OPTION, Siteherd_Site_Health::run(), false );
		update_option( Siteherd_Integrity::OPTION, Siteherd_Integrity::run(), false );
		update_option( self::LAST_RUN, time(), false );
		$this->reporter->send();
	}

	/**
	 * @return int|null Unix time of the last completed run.
	 */
	public static function last_run() {
		$time = get_option( self::LAST_RUN );
		return $time ? (int) $time : null;
	}
}
