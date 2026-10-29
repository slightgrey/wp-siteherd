<?php
/**
 * Login lockout: after too many failed logins from one IP, refuse logins from
 * it for a while, without checking the password. Covers wp-login.php, XML-RPC
 * and anything else that goes through wp_authenticate(). One-click login from
 * the dashboard sets the auth cookie directly, so it always gets through.
 *
 * IPs never leave the site: each one's record is a transient keyed by a salted
 * hash, and the dashboard only gets counts. Off until turned on.
 *
 * @package WP_Siteherd
 */

class Siteherd_Lockout {

	const OPTION     = 'siteherd_lockout';
	const STATS      = 'siteherd_lockout_stats';
	const GENERATION = 'siteherd_lockout_generation';

	// Plugins that already lock out IPs; ours stays off next to them so nobody is locked out twice.
	const OTHER_LOCKOUTS = array(
		'wordfence'                           => 'Wordfence',
		'better-wp-security'                  => 'Solid Security',
		'ithemes-security-pro'                => 'Solid Security Pro',
		'limit-login-attempts-reloaded'       => 'Limit Login Attempts Reloaded',
		'loginizer'                           => 'Loginizer',
		'wp-cerber'                           => 'WP Cerber',
		'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
		'wp-simple-firewall'                  => 'Shield Security',
		'defender-security'                   => 'Defender',
	);

	const PROXIES = array( 'auto', 'x-forwarded-for', 'x-real-ip', 'none' );

	/** @var int Seconds the current request's IP is locked out for, once checked. */
	private $blocked = 0;

	/**
	 * @return array{enabled: bool, max_retries: int, lockout_minutes: int, proxy: string}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'enabled'         => ! empty( $saved['enabled'] ),
			'max_retries'     => isset( $saved['max_retries'] ) ? (int) $saved['max_retries'] : 5,
			'lockout_minutes' => isset( $saved['lockout_minutes'] ) ? (int) $saved['lockout_minutes'] : 15,
			'proxy'           => isset( $saved['proxy'] ) && in_array( $saved['proxy'], self::PROXIES, true ) ? $saved['proxy'] : 'auto',
		);
	}

	/**
	 * Save settings, keeping values outside the allowed ranges out.
	 *
	 * @param array<string, mixed> $changes Any of enabled, max_retries (3–20), lockout_minutes (5–1440), proxy.
	 */
	public static function save( array $changes ) {
		$s = self::settings();
		if ( isset( $changes['enabled'] ) ) {
			$s['enabled'] = (bool) $changes['enabled'];
		}
		if ( isset( $changes['max_retries'] ) ) {
			$s['max_retries'] = max( 3, min( 20, (int) $changes['max_retries'] ) );
		}
		if ( isset( $changes['lockout_minutes'] ) ) {
			$s['lockout_minutes'] = max( 5, min( 1440, (int) $changes['lockout_minutes'] ) );
		}
		if ( isset( $changes['proxy'] ) && in_array( $changes['proxy'], self::PROXIES, true ) ) {
			$s['proxy'] = $changes['proxy'];
		}
		// Autoloaded: read on every login attempt.
		update_option( self::OPTION, $s, true );
	}

	/**
	 * @return string|null Name of another active plugin that locks out IPs.
	 */
	public static function conflict() {
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$slug = strtok( (string) $file, '/' );
			if ( isset( self::OTHER_LOCKOUTS[ $slug ] ) ) {
				return self::OTHER_LOCKOUTS[ $slug ];
			}
		}
		return null;
	}

	/** Turned on and not standing down for another lockout plugin. */
	public static function is_active() {
		return self::settings()['enabled'] && null === self::conflict();
	}

	/** Forget every lockout and failure count (e.g. when a real user got locked out). */
	public static function unlock_all() {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
	}

	public static function current_ip() {
		return Siteherd_Lockout_Policy::client_ip( $_SERVER, self::settings()['proxy'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP address.
	}

	/**
	 * `authenticate`, priority 1: refuse a locked-out IP before any password is checked.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Submitted username or email.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function block_early( $user, $username = '', $password = '' ) {
		// wp-login.php runs this on every page view with empty credentials; only real attempts count.
		if ( ( '' === (string) $username && '' === (string) $password ) || ! self::is_active() ) {
			return $user;
		}
		$ip = self::current_ip();
		if ( '' === $ip ) {
			return $user;
		}
		$this->blocked = Siteherd_Lockout_Policy::locked_for( get_transient( self::key( $ip ) ), time() );
		if ( ! $this->blocked ) {
			return $user;
		}
		// Skip the password checks for this request; block_late() makes sure nothing else lets it in.
		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		remove_filter( 'authenticate', 'wp_authenticate_application_password', 20 );
		self::bump( 'blocked' );
		return $this->error();
	}

	/**
	 * `authenticate`, last priority: other plugins' handlers can't log a locked-out IP in.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public function block_late( $user ) {
		return $this->blocked ? $this->error() : $user;
	}

	/** `wp_login_failed`: count the failure and lock the IP out when it has too many. */
	public function record_failure() {
		if ( $this->blocked || ! self::is_active() ) {
			return;
		}
		$ip = self::current_ip();
		if ( '' === $ip ) {
			return;
		}
		$s      = self::settings();
		$key    = self::key( $ip );
		$result = Siteherd_Lockout_Policy::after_failure( get_transient( $key ), time(), $s['max_retries'], $s['lockout_minutes'] );
		set_transient( $key, $result['record'], Siteherd_Lockout_Policy::LONG_WINDOW + Siteherd_Lockout_Policy::LONG_SECONDS );
		if ( $result['locked'] ) {
			self::bump( 'lockouts' );
		}
	}

	/**
	 * @return array{enabled: bool, active: bool, conflict: string|null, max_retries: int, lockout_minutes: int, lockouts_24h: int, blocked_24h: int}
	 */
	public static function summary() {
		$s      = self::settings();
		$totals = array(
			'lockouts' => 0,
			'blocked'  => 0,
		);
		foreach ( self::recent_stats() as $bucket ) {
			foreach ( $totals as $name => $count ) {
				$totals[ $name ] = $count + ( isset( $bucket[ $name ] ) ? (int) $bucket[ $name ] : 0 );
			}
		}
		return array(
			'enabled'         => $s['enabled'],
			'active'          => self::is_active(),
			'conflict'        => self::conflict(),
			'max_retries'     => $s['max_retries'],
			'lockout_minutes' => $s['lockout_minutes'],
			'lockouts_24h'    => $totals['lockouts'],
			'blocked_24h'     => $totals['blocked'],
		);
	}

	private function error() {
		$minutes = max( 1, (int) ceil( $this->blocked / 60 ) );
		return new WP_Error(
			'siteherd_locked_out',
			sprintf(
				/* translators: %d: minutes until logins are allowed again */
				_n( '<strong>Error:</strong> Too many failed login attempts. Try again in %d minute.', '<strong>Error:</strong> Too many failed login attempts. Try again in %d minutes.', $minutes, 'wp-siteherd' ),
				$minutes
			)
		);
	}

	/** Transient key for an IP: a salted hash, so the database never holds the address itself. */
	private static function key( $ip ) {
		$hash = substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 24 );
		return 'siteherd_lo_' . (int) get_option( self::GENERATION, 0 ) . '_' . $hash;
	}

	/**
	 * Add one to this hour's lockouts or blocked count.
	 *
	 * @param string $name "lockouts" or "blocked".
	 */
	private static function bump( $name ) {
		$hour  = (int) floor( time() / HOUR_IN_SECONDS );
		$stats = self::recent_stats();
		$stats[ $hour ][ $name ] = ( isset( $stats[ $hour ][ $name ] ) ? $stats[ $hour ][ $name ] : 0 ) + 1;
		update_option( self::STATS, $stats, false );
	}

	/**
	 * @return array<int, array<string, int>> Hourly counts from the last 24 hours.
	 */
	private static function recent_stats() {
		$stats = get_option( self::STATS, array() );
		$hour  = (int) floor( time() / HOUR_IN_SECONDS );
		return is_array( $stats )
			? array_filter(
				$stats,
				function ( $bucket, $h ) use ( $hour ) {
					return is_array( $bucket ) && $h > $hour - 24;
				},
				ARRAY_FILTER_USE_BOTH
			)
			: array();
	}
}
