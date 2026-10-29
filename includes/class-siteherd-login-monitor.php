<?php
/**
 * Counts failed logins in hourly buckets, per source (login forms, XML-RPC,
 * REST API). Only counts are kept, never usernames or IP addresses.
 *
 * @package WP_Siteherd
 */

class Siteherd_Login_Monitor {

	const OPTION = 'siteherd_failed_logins';
	const HOURS  = 24;

	// "other" only holds counts stored before 0.8.0, when buckets had no source.
	const SOURCES = array( 'login', 'xmlrpc', 'rest', 'other' );

	public function record_failure() {
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		$buckets = self::recent_buckets( $hour );
		$source  = self::current_source();

		$bucket = isset( $buckets[ $hour ] ) ? $buckets[ $hour ] : array();
		$bucket[ $source ] = ( isset( $bucket[ $source ] ) ? $bucket[ $source ] : 0 ) + 1;
		$buckets[ $hour ]  = $bucket;
		update_option( self::OPTION, $buckets, false );
	}

	/**
	 * @return int Failed logins in the last 24 hours.
	 */
	public static function count_last_24h() {
		return (int) array_sum( self::by_source_last_24h() );
	}

	/**
	 * @return array<string, int> Failed logins in the last 24 hours, per source (every source present).
	 */
	public static function by_source_last_24h() {
		$totals = array_fill_keys( self::SOURCES, 0 );
		foreach ( self::recent_buckets( (int) floor( time() / HOUR_IN_SECONDS ) ) as $bucket ) {
			foreach ( $bucket as $source => $count ) {
				$totals[ $source ] += $count;
			}
		}
		return $totals;
	}

	/**
	 * @return array<int, array<string, int>> Hours with failed logins in the last 24 hours, oldest first:
	 *                                        `hour` (Unix time the hour starts) and a count per source.
	 */
	public static function hourly_last_24h() {
		$buckets = self::recent_buckets( (int) floor( time() / HOUR_IN_SECONDS ) );
		ksort( $buckets );
		$list = array();
		foreach ( $buckets as $hour => $bucket ) {
			$list[] = array_merge( array( 'hour' => $hour * HOUR_IN_SECONDS ), array_fill_keys( self::SOURCES, 0 ), $bucket );
		}
		return $list;
	}

	/**
	 * Where the failed attempt came from. wp_login_failed fires inside wp_authenticate(),
	 * so XML-RPC and REST requests are told apart by the request constants.
	 */
	private static function current_source() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return 'login';
	}

	/**
	 * @param int $current_hour Hours since the Unix epoch.
	 * @return array<int, array<string, int>> Buckets from the last 24 hours, keyed by hour.
	 */
	private static function recent_buckets( $current_hour ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$buckets = array();
		foreach ( $stored as $hour => $bucket ) {
			if ( $hour <= $current_hour - self::HOURS ) {
				continue;
			}
			// Before 0.8.0 a bucket was a plain count.
			if ( ! is_array( $bucket ) ) {
				$bucket = array( 'other' => (int) $bucket );
			}
			$buckets[ $hour ] = array_map( 'intval', array_intersect_key( $bucket, array_flip( self::SOURCES ) ) );
		}
		return $buckets;
	}
}
