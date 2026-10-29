<?php
/**
 * Pure rules for the login lockout: which IP a request comes from, and how a
 * failed login changes an IP's record. No WordPress functions, so it can be
 * unit tested (tests/LockoutPolicyTest.php).
 *
 * @package WP_Siteherd
 */

class Siteherd_Lockout_Policy {

	/** Failures older than this no longer count toward a lockout. */
	const FAIL_WINDOW = 3600;
	/** Lockouts within this window add up to a long lockout. */
	const LONG_WINDOW = 86400;
	/** Lockouts in LONG_WINDOW that trigger a long lockout. */
	const LONG_AFTER = 4;
	/** Length of a long lockout. */
	const LONG_SECONDS = 86400;

	// Cloudflare's published ranges (https://www.cloudflare.com/ips/). Only requests
	// arriving from these may set the client IP through CF-Connecting-IP.
	const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The visitor's IP address.
	 *
	 * @param array<string, mixed> $server $_SERVER.
	 * @param string               $proxy  "auto": trust CF-Connecting-IP only from Cloudflare's ranges;
	 *                                     "x-forwarded-for" / "x-real-ip": the site is behind a proxy that sets it;
	 *                                     anything else: REMOTE_ADDR only.
	 * @return string Empty when no valid address was found.
	 */
	public static function client_ip( array $server, $proxy ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? self::valid_ip( $server['REMOTE_ADDR'] ) : '';

		if ( 'auto' === $proxy ) {
			if ( $remote && isset( $server['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CLOUDFLARE ) ) {
				$cf = self::valid_ip( $server['HTTP_CF_CONNECTING_IP'] );
				if ( $cf ) {
					return $cf;
				}
			}
			return $remote;
		}

		if ( 'x-forwarded-for' === $proxy && isset( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			// The proxy appends the address it saw, so the last entry is the one it vouches for.
			$parts = array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) );
			$last  = self::valid_ip( end( $parts ) );
			return $last ? $last : $remote;
		}

		if ( 'x-real-ip' === $proxy && isset( $server['HTTP_X_REAL_IP'] ) ) {
			$real = self::valid_ip( $server['HTTP_X_REAL_IP'] );
			return $real ? $real : $remote;
		}

		return $remote;
	}

	/**
	 * @param mixed $ip Candidate address.
	 * @return string The address, or '' if it isn't a valid IPv4/IPv6 address.
	 */
	public static function valid_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * @param string   $ip     Valid IPv4 or IPv6 address.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function in_ranges( $ip, array $ranges ) {
		$bin = inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			list( $net, $bits ) = explode( '/', $range );
			$net_bin            = inet_pton( $net );
			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, int>|null $record An IP's record, or null.
	 * @param int                     $now    Unix time.
	 * @return int Seconds the IP is still locked out for (0 = not locked).
	 */
	public static function locked_for( $record, $now ) {
		return is_array( $record ) && ! empty( $record['until'] ) && $record['until'] > $now ? $record['until'] - $now : 0;
	}

	/**
	 * Apply one failed login to an IP's record.
	 *
	 * @param array<string, int>|null $record      Current record: fails, fails_since, lockouts, lockouts_since, until.
	 * @param int                     $now         Unix time.
	 * @param int                     $max_retries Failures allowed before a lockout.
	 * @param int                     $minutes     Length of a normal lockout.
	 * @return array{record: array<string, int>, locked: int} The new record, and the lockout length started (0 = none).
	 */
	public static function after_failure( $record, $now, $max_retries, $minutes ) {
		$r = array_merge(
			array(
				'fails'          => 0,
				'fails_since'    => $now,
				'lockouts'       => 0,
				'lockouts_since' => $now,
				'until'          => 0,
			),
			is_array( $record ) ? $record : array()
		);

		if ( $now - $r['fails_since'] > self::FAIL_WINDOW ) {
			$r['fails']       = 0;
			$r['fails_since'] = $now;
		}
		if ( $now - $r['lockouts_since'] > self::LONG_WINDOW ) {
			$r['lockouts']       = 0;
			$r['lockouts_since'] = $now;
		}

		++$r['fails'];
		if ( $r['fails'] < $max_retries ) {
			return array(
				'record' => $r,
				'locked' => 0,
			);
		}

		++$r['lockouts'];
		$length           = $r['lockouts'] >= self::LONG_AFTER ? self::LONG_SECONDS : $minutes * 60;
		$r['until']       = $now + $length;
		$r['fails']       = 0;
		$r['fails_since'] = $now;
		return array(
			'record' => $r,
			'locked' => $length,
		);
	}
}
