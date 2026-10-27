<?php
/**
 * One-click login from the dashboard. The dashboard sends the browser to
 *   wp-login.php?action=siteherd_sso&token=...
 * with a short-lived token signed with the site secret (see
 * Siteherd_Signer::verify_sso_token). A valid token logs the browser in as an
 * administrator: the one chosen in the settings, or, when the settings say
 * "ask every time", the administrator picked in the dashboard.
 *
 * @package WP_Siteherd
 */

class Siteherd_Sso {

	const OPTION_ENABLED = 'siteherd_sso_enabled';
	const OPTION_USER    = 'siteherd_sso_user';
	const OPTION_LOG     = 'siteherd_sso_log';
	const LOG_SIZE       = 20;

	public static function is_enabled() {
		// Missing option means the default: on.
		return '0' !== get_option( self::OPTION_ENABLED, '1' );
	}

	/**
	 * @return int|null The administrator to always log in as, or null for "ask every time".
	 */
	public static function fixed_user_id() {
		$id   = (int) get_option( self::OPTION_USER, 0 );
		$user = $id ? get_userdata( $id ) : false;
		return $user && self::is_admin_user( $user ) ? $id : null;
	}

	/**
	 * @return array<int, array{id: int, login: string, name: string}>
	 */
	public static function admins() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'user_login', 'display_name' ),
			)
		);
		return array_map(
			function ( $u ) {
				return array(
					'id'    => (int) $u->ID,
					'login' => $u->user_login,
					'name'  => $u->display_name,
				);
			},
			$users
		);
	}

	/**
	 * What the dashboard needs to offer the login button. Sent with every report.
	 */
	public static function summary() {
		return array(
			'enabled' => self::is_enabled(),
			'user_id' => self::fixed_user_id(),
			'admins'  => self::admins(),
		);
	}

	/**
	 * @return array<int, array{time: int, user: int}> Most recent first.
	 */
	public static function recent_logins() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Runs on wp-login.php?action=siteherd_sso (the login_form_{action} hook).
	 */
	public function handle_login() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- the signed token is the authorization.
		$user  = $this->authorize( $token );
		if ( is_wp_error( $user ) ) {
			wp_die(
				esc_html( $user->get_error_message() ),
				esc_html__( 'One-click login failed', 'wp-siteherd' ),
				array(
					'response'  => 403,
					'link_url'  => wp_login_url(),
					'link_text' => __( 'Log in with your password instead', 'wp-siteherd' ),
				)
			);
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		// Same action a normal login fires, so security/audit plugins see it.
		do_action( 'wp_login', $user->user_login, $user );

		$log = self::recent_logins();
		array_unshift(
			$log,
			array(
				'time' => time(),
				'user' => $user->ID,
			)
		);
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_SIZE ), false );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * @param string $token Token from the URL.
	 * @return WP_User|WP_Error
	 */
	private function authorize( $token ) {
		$generic = new WP_Error( 'siteherd_sso_invalid', __( 'This login link is invalid or has expired. Go back to the dashboard and click WP Admin again.', 'wp-siteherd' ) );

		if ( ! self::is_enabled() ) {
			return new WP_Error( 'siteherd_sso_disabled', __( 'One-click login is turned off for this site (Settings → WP Siteherd).', 'wp-siteherd' ) );
		}
		$conn = Siteherd_Connection::get();
		if ( ! $conn ) {
			return $generic;
		}

		$payload = Siteherd_Signer::verify_sso_token( $conn['secret'], $token );
		if ( ! is_array( $payload ) || ! hash_equals( $conn['id'], $payload['site'] ) ) {
			return $generic;
		}

		// Each link works once. Tokens expire after SSO_MAX_AGE, so remembering
		// nonces a little longer than that is enough.
		$seen_key = 'siteherd_sso_' . $payload['nonce'];
		if ( get_transient( $seen_key ) ) {
			return $generic;
		}
		set_transient( $seen_key, 1, 2 * Siteherd_Signer::SSO_MAX_AGE + MINUTE_IN_SECONDS );

		$fixed = self::fixed_user_id();
		if ( $fixed && $fixed !== $payload['user'] ) {
			return $generic;
		}
		$user = get_userdata( $payload['user'] );
		if ( ! $user || ! self::is_admin_user( $user ) ) {
			return $generic;
		}
		return $user;
	}

	private static function is_admin_user( WP_User $user ) {
		return in_array( 'administrator', (array) $user->roles, true );
	}
}
