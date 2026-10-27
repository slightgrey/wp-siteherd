<?php
/**
 * Connection-key decoding and HMAC signing. Pure PHP with no WordPress calls,
 * so it can be unit tested on its own.
 *
 * Must stay in sync with the dashboard:
 *   dashboard/src/lib/connection-key.ts  key = "siteherd1_" + base64url(JSON {url, id, secret})
 *   dashboard/src/lib/hmac.ts            signature = hex(HMAC-SHA256(secret, "{timestamp}.{raw_body}"))
 *
 * @package WP_Siteherd
 */

class Siteherd_Signer {

	const KEY_PREFIX         = 'siteherd1_';
	const MAX_CLOCK_SKEW     = 300;
	const HEADER_SITE        = 'X-Siteherd-Site';
	const HEADER_TIMESTAMP   = 'X-Siteherd-Timestamp';
	const HEADER_SIGNATURE   = 'X-Siteherd-Signature';
	// One-click login links are accepted for this long after the dashboard issues them.
	const SSO_MAX_AGE        = 120;

	/**
	 * Decode a connection key into its parts.
	 *
	 * @param string $key Key as copied from the dashboard.
	 * @return array{url: string, id: string, secret: string}|null Null if malformed.
	 */
	public static function decode_key( $key ) {
		$key = trim( (string) $key );
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			return null;
		}

		$json = self::base64url_decode( substr( $key, strlen( self::KEY_PREFIX ) ) );
		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'url', 'id', 'secret' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === $data[ $field ] ) {
				return null;
			}
		}

		return array(
			'url'    => rtrim( $data['url'], '/' ),
			'id'     => $data['id'],
			'secret' => $data['secret'],
		);
	}

	/**
	 * @param string $secret    Site secret.
	 * @param string $timestamp Unix seconds, as sent in the header.
	 * @param string $raw_body  Exact request body ("" for GET).
	 * @return string Lowercase hex signature.
	 */
	public static function sign( $secret, $timestamp, $raw_body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	}

	/**
	 * @param string   $secret    Site secret.
	 * @param string   $timestamp Header value.
	 * @param string   $raw_body  Exact request body.
	 * @param string   $signature Header value.
	 * @param int|null $now       Current Unix time; defaults to time().
	 * @return true|string True when valid, otherwise a reason code.
	 */
	public static function verify( $secret, $timestamp, $raw_body, $signature, $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$timestamp = (string) $timestamp;
		$signature = (string) $signature;

		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return 'bad_timestamp';
		}
		if ( abs( $now - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return 'stale_timestamp';
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return 'bad_signature';
		}
		return hash_equals( self::sign( $secret, $timestamp, $raw_body ), $signature ) ? true : 'bad_signature';
	}

	/**
	 * Verify a one-click login token issued by the dashboard (dashboard/src/lib/sso.ts):
	 *   token = base64url(JSON {site, user, iat, nonce}) + "." + hex(HMAC-SHA256(secret, "sso." + base64url part))
	 * The "sso." prefix keeps these signatures distinct from report signatures.
	 * Single use (the nonce) is enforced by the caller, which has storage.
	 *
	 * @param string   $secret Site secret.
	 * @param string   $token  Token from the login URL.
	 * @param int|null $now    Current Unix time; defaults to time().
	 * @return array{site: string, user: int, iat: int, nonce: string}|string Payload, or a reason code.
	 */
	public static function verify_sso_token( $secret, $token, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $parts[0] ) || ! preg_match( '/^[0-9a-f]{64}$/', $parts[1] ) ) {
			return 'malformed';
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'sso.' . $parts[0], $secret ), $parts[1] ) ) {
			return 'bad_signature';
		}

		$data = json_decode( (string) self::base64url_decode( $parts[0] ), true );
		if (
			! is_array( $data )
			|| ! isset( $data['site'], $data['user'], $data['iat'], $data['nonce'] )
			|| ! is_string( $data['site'] )
			|| ! is_int( $data['user'] ) || $data['user'] < 1
			|| ! is_int( $data['iat'] )
			|| ! is_string( $data['nonce'] ) || ! preg_match( '/^[0-9a-f]{32}$/', $data['nonce'] )
		) {
			return 'malformed';
		}
		if ( abs( $now - $data['iat'] ) > self::SSO_MAX_AGE ) {
			return 'expired';
		}
		return array(
			'site'  => $data['site'],
			'user'  => $data['user'],
			'iat'   => $data['iat'],
			'nonce' => $data['nonce'],
		);
	}

	/**
	 * @param string $value Unpadded base64url.
	 * @return string|false
	 */
	private static function base64url_decode( $value ) {
		$b64 = strtr( $value, '-_', '+/' );
		$pad = strlen( $b64 ) % 4;
		if ( $pad ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $b64, true );
	}

	/**
	 * Dashboard URLs must be HTTPS, except plain HTTP to this machine for local development.
	 *
	 * @param string $url Dashboard URL from the key.
	 * @return bool
	 */
	public static function is_allowed_url( $url ) {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		$host = trim( strtolower( $parts['host'] ), '[]' );
		return 'http' === $parts['scheme'] && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}
}
