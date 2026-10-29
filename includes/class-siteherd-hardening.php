<?php
/**
 * Login hardening switches, set from the dashboard or the settings page.
 *
 * @package WP_Siteherd
 */

class Siteherd_Hardening {

	const OPTION_XMLRPC_OFF = 'siteherd_xmlrpc_off';

	/**
	 * With XML-RPC turned off, WordPress rejects every XML-RPC call that needs a
	 * login before checking the password, so password guessing through
	 * xmlrpc.php (including system.multicall) stops. Jetpack and the WordPress
	 * mobile app need it on.
	 */
	public static function is_xmlrpc_off() {
		return '1' === get_option( self::OPTION_XMLRPC_OFF, '0' );
	}

	/**
	 * @param bool $off Whether XML-RPC logins are blocked.
	 */
	public static function set_xmlrpc_off( $off ) {
		// Autoloaded: read on every XML-RPC request.
		update_option( self::OPTION_XMLRPC_OFF, $off ? '1' : '0', true );
	}

	/**
	 * @param bool $enabled Value from other filters.
	 * @return bool
	 */
	public function filter_xmlrpc_enabled( $enabled ) {
		return self::is_xmlrpc_off() ? false : $enabled;
	}

	/**
	 * @return array{xmlrpc_off: bool}
	 */
	public static function summary() {
		return array( 'xmlrpc_off' => self::is_xmlrpc_off() );
	}
}
