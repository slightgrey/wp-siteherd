<?php
/**
 * Sends signed reports to the dashboard on a schedule and shortly after changes.
 *
 * @package WP_Siteherd
 */

class Siteherd_Reporter {

	const CRON_HOOK     = 'siteherd_send_report';
	// Separate hook for one-off sends: WordPress drops a single event that
	// duplicates an already-scheduled hook within 10 minutes.
	const SOON_HOOK     = 'siteherd_send_report_soon';
	const INTERVAL      = 'siteherd_every_10_minutes';
	const INTERVAL_SECS = 600;
	const RESULT_OPTION = 'siteherd_last_result';

	public function add_cron_schedule( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => self::INTERVAL_SECS,
			'display'  => __( 'Every 10 minutes (WP Siteherd)', 'wp-siteherd' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the 10-minute report and the daily checks.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::CRON_HOOK );
		}
		Siteherd_Checks::schedule();
	}

	/**
	 * @return int|false Unix time of the next automatic report, or false if none is scheduled.
	 */
	public static function next_run() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * True when the next automatic report should have run more than one interval
	 * ago, which means WP-Cron isn't firing (usually a site with few visits, or
	 * DISABLE_WP_CRON without a server cron to replace it).
	 *
	 * @return bool
	 */
	public static function is_overdue() {
		$next = self::next_run();
		return $next && $next < time() - self::INTERVAL_SECS;
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::SOON_HOOK );
		Siteherd_Checks::unschedule();
		wp_unschedule_hook( 'siteherd_run_update_job' ); // Siteherd_Updater::CRON_HOOK; not loaded during deactivation.
	}

	/**
	 * Queue a report ~30 s from now, collapsing bursts (e.g. bulk updates) into one.
	 */
	public function schedule_soon() {
		if ( Siteherd_Connection::get() && ! wp_next_scheduled( self::SOON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::SOON_HOOK );
		}
	}

	/**
	 * Build, sign and send a report now.
	 *
	 * @return array{time: int, code: int, error: string|null} The stored result.
	 */
	public function send( $force_check = false ) {
		$conn = Siteherd_Connection::get();
		if ( ! $conn ) {
			return $this->store_result( 0, __( 'Not connected.', 'wp-siteherd' ) );
		}

		if ( $force_check ) {
			self::expire_update_info();
		}
		self::refresh_update_info();

		// A unique ID keeps two reports with identical data in the same second from
		// producing the same signature, which the dashboard would reject as a replay.
		$payload              = Siteherd_Collector::collect();
		$payload['report_id'] = wp_generate_uuid4();

		$response = self::post_signed( $conn, '/api/sites/report', $payload );
		if ( is_wp_error( $response ) ) {
			return $this->store_result( 0, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 201 === $code ) {
			return $this->store_result( $code, null );
		}
		return $this->store_result( $code, self::describe_failure( $code ) );
	}

	/**
	 * REST callback: POST /siteherd/v1/sync ("Resync" in the dashboard). Sends a
	 * report now, with update info checked again on wordpress.org, and answers
	 * once the dashboard has it. Signature checks happen in the route's
	 * permission_callback.
	 */
	public function sync_now() {
		$result = $this->send( true );
		return new WP_REST_Response(
			array(
				'ok'    => 201 === $result['code'],
				'code'  => $result['code'],
				'error' => $result['error'],
			),
			200
		);
	}

	/**
	 * Mark the cached plugin/theme/core update info as old, so the refresh below
	 * asks wordpress.org again, like "Check again" in Dashboard → Updates. The
	 * cached data stays, so a failed check doesn't read as "up to date". At most
	 * once a minute, however often Resync is clicked.
	 */
	private static function expire_update_info() {
		if ( get_transient( 'siteherd_forced_check' ) ) {
			return;
		}
		set_transient( 'siteherd_forced_check', 1, MINUTE_IN_SECONDS );
		foreach ( array( 'update_plugins', 'update_themes', 'update_core' ) as $name ) {
			$cache = get_site_transient( $name );
			if ( is_object( $cache ) ) {
				$cache->last_checked = 0;
				set_site_transient( $name, $cache );
			}
		}
	}

	/**
	 * Make sure WordPress's cached update info isn't missing or old before
	 * reporting it; otherwise an emptied cache reads as "everything up to date".
	 * WordPress throttles the plugin/theme checks itself (every 2 h in cron,
	 * 12 h otherwise), so most calls return without contacting wordpress.org.
	 * Its core check only waits a minute, so that one is throttled here.
	 */
	private static function refresh_update_info() {
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();
		wp_update_themes();
		$core = get_site_transient( 'update_core' );
		if ( empty( $core->last_checked ) || time() - $core->last_checked > 12 * HOUR_IN_SECONDS ) {
			wp_version_check();
		}
	}

	/**
	 * POST a JSON payload to a dashboard endpoint, signed with the site secret.
	 *
	 * @param array  $conn    Connection from Siteherd_Connection::get().
	 * @param string $path    Dashboard path, e.g. "/api/sites/report".
	 * @param array  $payload Data to send.
	 * @return array|WP_Error wp_remote_post() response.
	 */
	public static function post_signed( $conn, $path, $payload ) {
		// Encode once and sign exactly the bytes that are sent.
		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();

		return wp_remote_post(
			$conn['url'] . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'                   => 'application/json',
					Siteherd_Signer::HEADER_SITE      => $conn['id'],
					Siteherd_Signer::HEADER_TIMESTAMP => $timestamp,
					Siteherd_Signer::HEADER_SIGNATURE => Siteherd_Signer::sign( $conn['secret'], $timestamp, $body ),
				),
				'body'    => $body,
			)
		);
	}

	/**
	 * @return array{time: int, code: int, error: string|null}|null
	 */
	public static function last_result() {
		$result = get_option( self::RESULT_OPTION );
		return is_array( $result ) ? $result : null;
	}

	private function store_result( $code, $error ) {
		$result = array(
			'time'  => time(),
			'code'  => $code,
			'error' => $error,
		);
		update_option( self::RESULT_OPTION, $result, false );
		return $result;
	}

	private static function describe_failure( $code ) {
		if ( 401 === $code ) {
			return __( 'The dashboard rejected the report. The site may have been deleted there, or this server’s clock is off by more than 5 minutes. Try pasting a fresh connection key.', 'wp-siteherd' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'The dashboard answered with HTTP %d.', 'wp-siteherd' ), $code );
	}
}
