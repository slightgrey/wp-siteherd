<?php
/**
 * Counts failed logins in hourly buckets. Only counts are kept, never
 * usernames or IP addresses.
 *
 * @package WP_Siteherd
 */

class Siteherd_Login_Monitor {

	const OPTION = 'siteherd_failed_logins';
	const HOURS  = 24;

	public function record_failure() {
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		$buckets = self::recent_buckets( $hour );

		$buckets[ $hour ] = ( isset( $buckets[ $hour ] ) ? $buckets[ $hour ] : 0 ) + 1;
		update_option( self::OPTION, $buckets, false );
	}

	/**
	 * @return int Failed logins in the last 24 hours.
	 */
	public static function count_last_24h() {
		return (int) array_sum( self::recent_buckets( (int) floor( time() / HOUR_IN_SECONDS ) ) );
	}

	/**
	 * @param int $current_hour Hours since the Unix epoch.
	 * @return array<int, int> Buckets from the last 24 hours, keyed by hour.
	 */
	private static function recent_buckets( $current_hour ) {
		$buckets = get_option( self::OPTION, array() );
		if ( ! is_array( $buckets ) ) {
			return array();
		}
		return array_filter(
			$buckets,
			function ( $hour ) use ( $current_hour ) {
				return $hour > $current_hour - self::HOURS;
			},
			ARRAY_FILTER_USE_KEY
		);
	}
}
