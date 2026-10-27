<?php
/**
 * Stores the dashboard connection (URL, Site ID, Secret) decoded from the key.
 *
 * @package WP_Siteherd
 */

class Siteherd_Connection {

	const OPTION = 'siteherd_connection';

	/**
	 * @return array{url: string, id: string, secret: string}|null
	 */
	public static function get() {
		$conn = get_option( self::OPTION );
		return is_array( $conn ) && ! empty( $conn['url'] ) && ! empty( $conn['id'] ) && ! empty( $conn['secret'] ) ? $conn : null;
	}

	/**
	 * Validate and store a connection key.
	 *
	 * @param string $key Key pasted by the user.
	 * @return true|WP_Error
	 */
	public static function save_from_key( $key ) {
		$conn = Siteherd_Signer::decode_key( $key );
		if ( ! $conn ) {
			return new WP_Error( 'siteherd_bad_key', __( 'That doesn’t look like a WP Siteherd connection key. Copy it again from the site’s page in your dashboard.', 'wp-siteherd' ) );
		}
		if ( ! Siteherd_Signer::is_allowed_url( $conn['url'] ) ) {
			return new WP_Error( 'siteherd_insecure_url', __( 'The dashboard address in this key must use HTTPS.', 'wp-siteherd' ) );
		}
		// Not autoloaded: the secret is only needed when reporting or verifying a request.
		update_option( self::OPTION, $conn, false );
		return true;
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
