<?php
/**
 * Builds the report payload sent to the dashboard (and returned by the status
 * endpoint). Update info comes from WordPress's own cached update checks, so
 * collecting never makes remote calls.
 *
 * @package WP_Siteherd
 */

class Siteherd_Collector {

	// 2: adds `health` and `integrity` (results of the latest daily checks, or null).
	// 3: adds `sso` (one-click login settings and the site's administrators).
	// 4: adds `rollbacks` (versions saved at the last update, available for rollback).
	// 5: adds `translations` (pending language pack updates) and accepts "translations" update items,
	//    and `site_icon` (the Site Icon URL from Settings → General, or null).
	// 6: no new fields; accepts the install / delete / activate / deactivate job actions (plugin 0.7.0).
	// 7: adds `failed_logins_by_source` (login / xmlrpc / rest / other counts for the last 24 hours).
	// 8: adds `failed_logins_hourly` (per-hour counts by source) and `hardening` ({xmlrpc_off}),
	//    and accepts POST /settings (plugin 0.9.0).
	// 9: adds `lockout` (login lockout settings and 24 h counts) and accepts lockout settings
	//    and `unlock_all` in POST /settings (plugin 0.10.0).
	const SCHEMA = 9;

	/**
	 * @return array<string, mixed>
	 */
	public static function collect() {
		global $wpdb;

		return array(
			'schema'                  => self::SCHEMA,
			// From the file on disk, so the report sent right after a self-update shows the new version.
			'plugin_version'          => Siteherd_Self_Update::installed_version(),
			'site_url'                => home_url(),
			'site_icon'               => get_site_icon_url( 64 ) ?: null,
			'wp_version'              => get_bloginfo( 'version' ),
			'php_version'             => PHP_VERSION,
			'db_version'              => $wpdb->db_version(),
			'is_multisite'            => is_multisite(),
			'locale'                  => get_locale(),
			'core_update'             => self::core_update(),
			'plugins'                 => self::plugins(),
			'themes'                  => self::themes(),
			'translations'            => self::translations(),
			'failed_logins_24h'       => Siteherd_Login_Monitor::count_last_24h(),
			'failed_logins_by_source' => Siteherd_Login_Monitor::by_source_last_24h(),
			'failed_logins_hourly'    => Siteherd_Login_Monitor::hourly_last_24h(),
			'hardening'               => Siteherd_Hardening::summary(),
			'lockout'                 => Siteherd_Lockout::summary(),
			'health'                  => get_option( Siteherd_Site_Health::OPTION, null ),
			'integrity'               => get_option( Siteherd_Integrity::OPTION, null ),
			'sso'                     => Siteherd_Sso::summary(),
			'rollbacks'               => Siteherd_Updater::kept_backups(),
			'generated_at'            => gmdate( 'c' ),
		);
	}

	/**
	 * @return string|null Newer core version on offer, if any.
	 */
	private static function core_update() {
		$updates = get_site_transient( 'update_core' );
		if ( empty( $updates->updates ) || ! is_array( $updates->updates ) ) {
			return null;
		}
		foreach ( $updates->updates as $offer ) {
			if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
				return $offer->current;
			}
		}
		return null;
	}

	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		$pending = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();

		$list = array();
		foreach ( get_plugins() as $file => $data ) {
			$list[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => isset( $pending[ $file ]->new_version ) ? $pending[ $file ]->new_version : null,
			);
		}
		return $list;
	}

	private static function themes() {
		$updates = get_site_transient( 'update_themes' );
		$pending = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$active  = get_stylesheet();

		$list = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$list[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => $stylesheet === $active,
				'update'     => isset( $pending[ $stylesheet ]['new_version'] ) ? $pending[ $stylesheet ]['new_version'] : null,
			);
		}
		return $list;
	}

	/**
	 * Language packs WordPress can update (core, plugins and themes), from the
	 * same cached update checks as above.
	 *
	 * @return array<int, array{type: string, slug: string, language: string, version: string, updated: string}>
	 */
	private static function translations() {
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		$list = array();
		foreach ( wp_get_translation_updates() as $pack ) {
			$list[] = array(
				'type'     => isset( $pack->type ) ? (string) $pack->type : '',
				'slug'     => isset( $pack->slug ) ? (string) $pack->slug : '',
				'language' => isset( $pack->language ) ? (string) $pack->language : '',
				'version'  => isset( $pack->version ) ? (string) $pack->version : '',
				'updated'  => isset( $pack->updated ) ? (string) $pack->updated : '',
			);
		}
		return $list;
	}
}
