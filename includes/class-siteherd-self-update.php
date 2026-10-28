<?php
/**
 * Updates for WP Siteherd itself, from GitHub releases.
 *
 * The plugin isn't on wordpress.org, so WordPress never offers updates for it.
 * This adds the latest GitHub release (tag "v1.2.3" with a "wp-siteherd.zip"
 * asset) to WordPress's plugin update data. From there it shows up like any
 * other update: in wp-admin, in auto-updates, and in the dashboard's reports.
 *
 * Development copies (with composer.json, which release zips leave out) are
 * never updated, so a linked checkout can't be overwritten.
 *
 * @package WP_Siteherd
 */

class Siteherd_Self_Update {

	const RELEASE_URL = 'https://api.github.com/repos/slightgrey/wp-siteherd/releases/latest';
	const REPO_URL    = 'https://github.com/slightgrey/wp-siteherd';
	const ASSET_NAME  = 'wp-siteherd.zip';
	const CACHE_KEY   = 'siteherd_latest_release';
	const SLUG        = 'wp-siteherd';

	public static function enabled() {
		return ! file_exists( SITEHERD_DIR . 'composer.json' ) && ! ( defined( 'SITEHERD_DISABLE_SELF_UPDATE' ) && SITEHERD_DISABLE_SELF_UPDATE );
	}

	/**
	 * Filter for pre_set_site_transient_update_plugins: add our update (or "no update").
	 *
	 * @param object|false $transient WordPress's plugin update data.
	 */
	public function add_update( $transient ) {
		if ( ! is_object( $transient ) || ! self::enabled() ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $transient;
		}
		$file  = plugin_basename( SITEHERD_FILE );
		$offer = (object) array(
			'id'           => self::REPO_URL,
			'slug'         => self::SLUG,
			'plugin'       => $file,
			'new_version'  => $release['version'],
			'url'          => self::REPO_URL,
			'package'      => $release['package'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( $release['version'], SITEHERD_VERSION, '>' ) ) {
			$transient->response[ $file ] = $offer;
			unset( $transient->no_update[ $file ] );
		} else {
			// Listed as "no update" so WordPress offers the auto-update toggle.
			$transient->no_update[ $file ] = $offer;
		}
		return $transient;
	}

	/**
	 * Filter for plugins_api: the "View details" popup in wp-admin.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Siteherd',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'slightgrey',
			'homepage'      => self::REPO_URL,
			'requires_php'  => '7.4',
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'changelog' => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : esc_html__( 'See the release notes on GitHub.', 'wp-siteherd' ),
			),
		);
	}

	/**
	 * The latest release, cached for 12 hours (1 hour after a failed lookup).
	 *
	 * @return array{version: string, package: string, notes: string, published: string}|null
	 */
	public static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$release  = null;
		$response = wp_remote_get(
			self::RELEASE_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = is_array( $data ) && isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'vV' ) : '';
			$package = '';
			foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET_NAME === $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
				}
			}
			if ( preg_match( '/^\d+(\.\d+)*$/', $version ) && 0 === strpos( $package, 'https://github.com/' ) ) {
				$release = array(
					'version'   => $version,
					'package'   => $package,
					'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
					'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
				);
			}
		}

		// An empty array marks a failed lookup, so it isn't retried on every page load.
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Forget the cached release (e.g. when the dashboard asks for a resync).
	 */
	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}
}
