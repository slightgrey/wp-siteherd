<?php
/**
 * White label: the agency name set in the dashboard (Settings → White label).
 * It arrives in the signed answer to every report, so a change reaches the
 * site with its next report or a Resync.
 *
 * @package WP_Siteherd
 */

class Siteherd_Branding {

	const OPTION   = 'siteherd_branding';
	const NAME_MAX = 60;

	/**
	 * Store the branding from a verified dashboard answer. An empty name, or
	 * none, goes back to the defaults.
	 *
	 * @param mixed $branding `branding` from the answer, e.g. {agency_name: "My Agency"}.
	 */
	public static function store( $branding ) {
		$name = is_array( $branding ) && isset( $branding['agency_name'] ) && is_string( $branding['agency_name'] )
			? trim( mb_substr( sanitize_text_field( $branding['agency_name'] ), 0, self::NAME_MAX ) )
			: '';
		if ( '' === $name ) {
			delete_option( self::OPTION );
		} elseif ( get_option( self::OPTION ) !== array( 'agency_name' => $name ) ) {
			update_option( self::OPTION, array( 'agency_name' => $name ), false );
		}
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * The agency name while connected, else null.
	 *
	 * @return string|null
	 */
	public static function agency() {
		if ( ! Siteherd_Connection::get() ) {
			return null;
		}
		$branding = get_option( self::OPTION );
		return is_array( $branding ) && ! empty( $branding['agency_name'] ) ? (string) $branding['agency_name'] : null;
	}

	/**
	 * What the plugin calls itself in the Settings menu and on its page.
	 *
	 * @return string
	 */
	public static function label() {
		$agency = self::agency();
		/* translators: %s: agency name set in the dashboard. */
		return $agency ? sprintf( __( '%s WordPress Manager', 'wp-siteherd' ), $agency ) : __( 'WP Siteherd', 'wp-siteherd' );
	}
}
