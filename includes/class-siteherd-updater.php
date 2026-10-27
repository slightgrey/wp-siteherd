<?php
/**
 * Updates plugins, themes and WordPress core on request from the dashboard.
 *
 * Flow: the dashboard POSTs a signed job to /wp-json/siteherd/v1/update. The
 * job is stored and run in WP-Cron straight away (spawn_cron). Running inside
 * cron matters: WordPress's upgraders then keep active plugins active and use
 * maintenance mode, instead of deactivating them for a browser to re-enable.
 *
 * Each plugin/theme is backed up first, updated with WordPress's own upgrader,
 * then the site is loaded over HTTP. If it no longer loads, the backup is
 * restored. Core is updated last and checked, but not rolled back (backing up
 * all of core isn't practical). Progress is pushed to the dashboard after each
 * item, and a fresh report is sent at the end.
 *
 * @package WP_Siteherd
 */

class Siteherd_Updater {

	const JOB_OPTION  = 'siteherd_update_job';
	const CRON_HOOK   = 'siteherd_run_update_job';
	const MAX_ITEMS   = 50;
	// Inside wp-content. Not under wp-content/upgrade/: WP_Upgrader empties that
	// folder before unpacking every package, which would delete our backups.
	const BACKUP_DIR  = 'siteherd-backups';
	// How long the copy of a replaced version is kept for rollback.
	const KEEP_DAYS   = 30;
	// A job still unfinished after this long is assumed dead (e.g. PHP was killed).
	const STALE_AFTER = 1800;

	/** @var Siteherd_Reporter */
	private $reporter;

	public function __construct( Siteherd_Reporter $reporter ) {
		$this->reporter = $reporter;
	}

	/**
	 * REST callback: POST /siteherd/v1/update with
	 *   {job_id, action?: "update"|"rollback", items: [{type, id, version?}]}
	 * Rollback items are plugins/themes with the version to go back to.
	 * Signature checks happen in the route's permission_callback.
	 */
	public function queue( WP_REST_Request $request ) {
		$data   = json_decode( $request->get_body(), true );
		$job_id = is_array( $data ) && isset( $data['job_id'] ) && is_string( $data['job_id'] ) ? $data['job_id'] : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $job_id ) ) {
			return new WP_Error( 'siteherd_bad_job', 'job_id must be a UUID', array( 'status' => 400 ) );
		}
		$action = isset( $data['action'] ) && 'rollback' === $data['action'] ? 'rollback' : 'update';
		$items  = self::sanitize_items( isset( $data['items'] ) ? $data['items'] : null, $action );
		if ( ! $items ) {
			return new WP_Error( 'siteherd_bad_items', 'items must list 1-50 valid items', array( 'status' => 400 ) );
		}

		$current = self::current_job();
		if ( $current && ! self::is_stale( $current ) ) {
			return new WP_Error( 'siteherd_busy', 'Another update is already running on this site.', array( 'status' => 409 ) );
		}

		$run_key = wp_generate_password( 32, false );
		update_option(
			self::JOB_OPTION,
			array(
				'id'        => $job_id,
				'action'    => $action,
				'items'     => $items,
				'status'    => 'queued',
				'queued_at' => time(),
				'run_key'   => $run_key,
			),
			false
		);

		// Start now with a background request to ourselves. Not spawn_cron():
		// WordPress starts cron at most once a minute, so right after another
		// cron run it silently does nothing and the job would wait for the next
		// visitor. The cron event stays as a fallback if loopbacks are blocked.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $job_id ) );
		wp_remote_post(
			rest_url( Siteherd_Rest::NAMESPACE_V1 . '/update/run' ),
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'job_id' => $job_id,
					'key'    => $run_key,
				),
			)
		);

		return new WP_REST_Response( array( 'accepted' => true ), 202 );
	}

	/**
	 * Permission for the loopback that starts a job: the one-time key stored with it.
	 */
	public function verify_run_request( WP_REST_Request $request ) {
		$job = self::current_job();
		$key = (string) $request->get_param( 'key' );
		return $job && ! empty( $job['run_key'] ) && $job['id'] === $request->get_param( 'job_id' ) && hash_equals( $job['run_key'], $key );
	}

	/**
	 * REST callback for the loopback: run the job in this request.
	 */
	public function run_now( WP_REST_Request $request ) {
		// The upgraders keep active plugins active (and use maintenance mode)
		// only during cron; behave the same here.
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->run( (string) $request->get_param( 'job_id' ) );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Run the queued job (from the loopback request, or the cron fallback).
	 *
	 * @param string $job_id Job to run; ignored if it isn't the queued one.
	 */
	public function run( $job_id ) {
		$job = self::current_job();
		if ( ! $job || $job['id'] !== $job_id || 'queued' !== $job['status'] ) {
			return;
		}
		// Loopback and cron can both fire. add_option() fails if the row exists,
		// so only one of them claims the job.
		if ( ! add_option( 'siteherd_update_claim_' . $job_id, time(), '', false ) ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $job_id ) );
		$job['status']     = 'running';
		$job['started_at'] = time();
		update_option( self::JOB_OPTION, $job, false );

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled on some hosts.
		}

		$results = array();
		$this->push( $job, 'running', $results );

		$ready = $this->prepare();
		if ( is_wp_error( $ready ) ) {
			$this->finish( $job, 'failed', $results, $ready->get_error_message() );
			return;
		}

		// Only trust "the update broke the site" if the site loaded fine beforehand.
		$baseline     = self::check_site();
		$check_health = true === $baseline;
		$note         = $check_health ? null : sprintf(
			/* translators: %s: why the site couldn't be loaded */
			__( 'The site couldn’t load itself before updating (%s), so updates were not health-checked or rolled back.', 'wp-siteherd' ),
			$baseline
		);

		$rollback = isset( $job['action'] ) && 'rollback' === $job['action'];
		foreach ( self::ordered( $job['items'] ) as $item ) {
			if ( $rollback ) {
				$results[] = $this->rollback_item( $item, $check_health );
			} elseif ( 'plugin' === $item['type'] ) {
				$results[] = $this->update_plugin( $item['id'], $check_health );
			} elseif ( 'theme' === $item['type'] ) {
				$results[] = $this->update_theme( $item['id'], $check_health );
			} elseif ( 'translations' === $item['type'] ) {
				$results[] = $this->update_translations();
			} else {
				$results[] = $this->update_core( $check_health );
			}
			$this->push( $job, 'running', $results, null, $note );
		}

		$this->finish( $job, 'completed', $results, null, $note );
	}

	/**
	 * @return array|null The stored job, if any.
	 */
	public static function current_job() {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) && isset( $job['id'], $job['status'] ) ? $job : null;
	}

	private static function is_stale( $job ) {
		$since = isset( $job['started_at'] ) ? $job['started_at'] : ( isset( $job['queued_at'] ) ? $job['queued_at'] : 0 );
		return time() - (int) $since > self::STALE_AFTER;
	}

	private static function sanitize_items( $items, $action = 'update' ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		// Core and translations can be updated but not rolled back.
		$types = 'rollback' === $action ? array( 'plugin', 'theme' ) : array( 'plugin', 'theme', 'core', 'translations' );
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['id'] ) || ! is_string( $item['id'] ) ) {
				continue;
			}
			if ( ! in_array( $item['type'], $types, true ) ) {
				continue;
			}
			// Plugin files look like "dir/file.php"; theme stylesheets are folder names. No traversal.
			if ( '' === $item['id'] || false !== strpos( $item['id'], '..' ) || strlen( $item['id'] ) > 200 ) {
				continue;
			}
			// Core and translations are single items with a fixed id.
			$fixed = array(
				'core'         => 'core',
				'translations' => 'translations',
			);
			$entry = array(
				'type' => $item['type'],
				'id'   => isset( $fixed[ $item['type'] ] ) ? $fixed[ $item['type'] ] : $item['id'],
			);
			if ( 'rollback' === $action ) {
				// Version strings end up in a download URL, so keep them plain.
				if ( ! isset( $item['version'] ) || ! is_string( $item['version'] ) || ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z.\-+_]{0,39}$/', $item['version'] ) ) {
					continue;
				}
				$entry['version'] = $item['version'];
			}
			$clean[ $entry['type'] . ':' . $entry['id'] ] = $entry;
		}
		return count( $clean ) <= self::MAX_ITEMS ? array_values( $clean ) : array();
	}

	/**
	 * Plugins, then themes, then core (so core runs on updated code), then
	 * translations (a core update can bring new language packs).
	 */
	private static function ordered( $items ) {
		$rank = array(
			'plugin'       => 0,
			'theme'        => 1,
			'core'         => 2,
			'translations' => 3,
		);
		usort(
			$items,
			function ( $a, $b ) use ( $rank ) {
				return $rank[ $a['type'] ] - $rank[ $b['type'] ];
			}
		);
		return $items;
	}

	/**
	 * Load the admin code the upgraders need and make sure files can be written.
	 *
	 * @return true|WP_Error
	 */
	private function prepare() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( ! wp_is_file_mod_allowed( 'siteherd_update' ) ) {
			return new WP_Error( 'siteherd_file_mods', __( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so it can’t be updated remotely.', 'wp-siteherd' ) );
		}
		// Backups and rollbacks use plain paths, which needs direct file access.
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return new WP_Error( 'siteherd_filesystem', __( 'WordPress can’t write files directly on this site (it would need FTP credentials), so it can’t be updated remotely.', 'wp-siteherd' ) );
		}
		$this->purge_old_backups();
		return true;
	}

	private function update_plugin( $file, $check_health ) {
		$result = self::result( 'plugin', $file );
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return self::outcome( $result, 'skipped', __( 'Plugin is not installed.', 'wp-siteherd' ) );
		}
		$result['name'] = $plugins[ $file ]['Name'];
		$result['from'] = $plugins[ $file ]['Version'];

		$offer = self::plugin_offer( $file );
		if ( ! $offer ) {
			wp_update_plugins(); // Cached info may be stale; ask wordpress.org once.
			$offer = self::plugin_offer( $file );
		}
		if ( ! $offer ) {
			return self::outcome( $result, 'skipped', __( 'No update available.', 'wp-siteherd' ) );
		}
		$result['to'] = $offer->new_version;
		if ( empty( $offer->package ) ) {
			return self::outcome( $result, 'failed', __( 'No download is available. Premium plugins usually need an active licence to update.', 'wp-siteherd' ) );
		}

		$source = false === strpos( $file, '/' ) ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . dirname( $file );
		$backup = $this->backup( $source, 'plugin-' . sanitize_file_name( dirname( $file ) === '.' ? $file : dirname( $file ) ) );
		if ( is_wp_error( $backup ) ) {
			return self::outcome( $result, 'failed', $backup->get_error_message() );
		}
		$was_active = is_plugin_active( $file );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->upgrade( $file );

		if ( true !== $outcome ) {
			// A failed install can leave the plugin half-removed; put the old one back.
			$present = file_exists( WP_PLUGIN_DIR . '/' . $file );
			return $this->install_failed( $result, self::upgrader_error( $upgrader, $outcome ), $present, $backup, $source );
		}
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', false, true );
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		return $this->verify( $result, $data['Version'], $check_health, $backup, $source );
	}

	private function update_theme( $stylesheet, $check_health ) {
		$result = self::result( 'theme', $stylesheet );
		$theme  = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return self::outcome( $result, 'skipped', __( 'Theme is not installed.', 'wp-siteherd' ) );
		}
		$result['name'] = $theme->get( 'Name' );
		$result['from'] = $theme->get( 'Version' );

		$offer = self::theme_offer( $stylesheet );
		if ( ! $offer ) {
			wp_update_themes();
			$offer = self::theme_offer( $stylesheet );
		}
		if ( ! $offer ) {
			return self::outcome( $result, 'skipped', __( 'No update available.', 'wp-siteherd' ) );
		}
		$result['to'] = $offer['new_version'];
		if ( empty( $offer['package'] ) ) {
			return self::outcome( $result, 'failed', __( 'No download is available. Premium themes usually need an active licence to update.', 'wp-siteherd' ) );
		}

		$source = $theme->get_stylesheet_directory();
		$backup = $this->backup( $source, 'theme-' . sanitize_file_name( $stylesheet ) );
		if ( is_wp_error( $backup ) ) {
			return self::outcome( $result, 'failed', $backup->get_error_message() );
		}

		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->upgrade( $stylesheet );

		if ( true !== $outcome ) {
			$present = file_exists( $source . '/style.css' );
			return $this->install_failed( $result, self::upgrader_error( $upgrader, $outcome ), $present, $backup, $source );
		}

		wp_clean_themes_cache();
		return $this->verify( $result, wp_get_theme( $stylesheet )->get( 'Version' ), $check_health, $backup, $source );
	}

	private function update_core( $check_health ) {
		$result         = self::result( 'core', 'core' );
		$result['name'] = 'WordPress';
		$result['from'] = get_bloginfo( 'version' );

		$offer = self::core_offer();
		if ( ! $offer ) {
			wp_version_check( array(), true );
			$offer = self::core_offer();
		}
		if ( ! $offer ) {
			return self::outcome( $result, 'skipped', __( 'No WordPress update available.', 'wp-siteherd' ) );
		}
		$result['to'] = $offer->current;

		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		// attempt_rollback: if copying files fails midway, core reinstalls the current version.
		$outcome = $upgrader->upgrade( $offer, array( 'attempt_rollback' => true ) );
		if ( is_wp_error( $outcome ) || ! is_string( $outcome ) ) {
			return self::outcome( $result, 'failed', self::upgrader_error( $upgrader, $outcome ) );
		}
		$result['to'] = $outcome;

		if ( $check_health ) {
			$health = self::check_site();
			if ( true !== $health ) {
				return self::outcome(
					$result,
					'failed',
					/* translators: 1: WordPress version, 2: what failed */
					sprintf( __( 'WordPress was updated to %1$s but the site isn’t loading (%2$s). Core updates can’t be rolled back automatically. Check the site now.', 'wp-siteherd' ), $outcome, $health )
				);
			}
		}
		return self::outcome( $result, 'updated' );
	}

	/**
	 * Install every pending language pack (core, plugins and themes), as the
	 * Translations section of Dashboard → Updates does. Language files can't
	 * break the site, so there's no backup or health check.
	 */
	private function update_translations() {
		$result         = self::result( 'translations', 'translations' );
		$result['name'] = __( 'Translations', 'wp-siteherd' );

		$packs = wp_get_translation_updates();
		if ( ! $packs ) {
			return self::outcome( $result, 'skipped', __( 'No translation updates available.', 'wp-siteherd' ) );
		}

		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->bulk_upgrade( $packs, array( 'clear_update_cache' => true ) );
		if ( ! is_array( $outcome ) ) {
			return self::outcome( $result, 'failed', self::upgrader_error( $upgrader, $outcome ) );
		}

		$failed = count(
			array_filter(
				$outcome,
				function ( $one ) {
					return ! $one || is_wp_error( $one );
				}
			)
		);
		$done   = count( $packs ) - $failed;
		if ( 0 === $done ) {
			return self::outcome( $result, 'failed', self::upgrader_error( $upgrader, null ) );
		}
		$message = sprintf(
			/* translators: %d: number of language packs */
			_n( '%d translation updated.', '%d translations updated.', $done, 'wp-siteherd' ),
			$done
		);
		if ( $failed ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of language packs */
				_n( '%d couldn’t be updated.', '%d couldn’t be updated.', $failed, 'wp-siteherd' ),
				$failed
			);
		}
		return self::outcome( $result, 'updated', $message );
	}

	/**
	 * The upgrader itself failed. If it left the old version missing, restore it.
	 */
	private function install_failed( $result, $error, $still_present, $backup, $source ) {
		if ( ! $still_present && ! $this->restore( $backup, $source ) ) {
			/* translators: 1: upgrade error, 2: backup folder path */
			return self::outcome( $result, 'failed', sprintf( __( '%1$s The previous version couldn’t be restored automatically. A backup is in %2$s.', 'wp-siteherd' ), $error, $backup['path'] ) );
		}
		$this->discard( $backup );
		return self::outcome( $result, 'failed', $error );
	}

	/**
	 * After a successful plugin/theme update: health-check, roll back if broken.
	 */
	private function verify( $result, $new_version, $check_health, $backup, $source ) {
		$result['to'] = $new_version;
		if ( $check_health ) {
			$health = self::check_site();
			if ( true !== $health ) {
				if ( ! $this->restore( $backup, $source ) ) {
					// Keep the backup so it can be restored by hand.
					return self::outcome(
						$result,
						'failed',
						/* translators: 1: what failed after the update, 2: backup folder path */
						sprintf( __( 'The site stopped loading after this update (%1$s) and the previous version couldn’t be restored automatically. A backup is in %2$s.', 'wp-siteherd' ), $health, $backup['path'] )
					);
				}
				$this->discard( $backup );
				$still_broken = true !== self::check_site();
				$message      = sprintf(
					/* translators: 1: what failed after the update, 2: version that was restored */
					__( 'The site stopped loading after this update (%1$s), so version %2$s was restored.', 'wp-siteherd' ),
					$health,
					$result['from']
				);
				if ( $still_broken ) {
					$message .= ' ' . __( 'The site still isn’t loading. Check it now.', 'wp-siteherd' );
				}
				return self::outcome( $result, 'rolled_back', $message );
			}
		}
		// Keep the replaced version so it can be rolled back to later.
		$this->keep( $backup, $result['type'], $result['id'], $result['from'] );
		return self::outcome( $result, 'updated' );
	}

	/**
	 * Roll a plugin or theme back to an earlier version: from the copy kept at
	 * its last update if that's the version wanted, otherwise from wordpress.org.
	 * The current version is backed up first and restored if the site breaks.
	 *
	 * @param array{type: string, id: string, version: string} $item What to roll back.
	 */
	private function rollback_item( $item, $check_health ) {
		$type   = $item['type'];
		$id     = $item['id'];
		$result = self::result( $type, $id );
		$result['to'] = $item['version'];

		if ( 'plugin' === $type ) {
			$plugins = get_plugins();
			if ( ! isset( $plugins[ $id ] ) ) {
				return self::outcome( $result, 'skipped', __( 'Plugin is not installed.', 'wp-siteherd' ) );
			}
			$result['name'] = $plugins[ $id ]['Name'];
			$result['from'] = $plugins[ $id ]['Version'];
			$source         = false === strpos( $id, '/' ) ? WP_PLUGIN_DIR . '/' . $id : WP_PLUGIN_DIR . '/' . dirname( $id );
		} else {
			$theme = wp_get_theme( $id );
			if ( ! $theme->exists() ) {
				return self::outcome( $result, 'skipped', __( 'Theme is not installed.', 'wp-siteherd' ) );
			}
			$result['name'] = $theme->get( 'Name' );
			$result['from'] = $theme->get( 'Version' );
			$source         = $theme->get_stylesheet_directory();
		}
		if ( $result['from'] === $item['version'] ) {
			/* translators: %s: version number */
			return self::outcome( $result, 'skipped', sprintf( __( 'Already on version %s.', 'wp-siteherd' ), $item['version'] ) );
		}

		$current = $this->backup( $source, 'rollback-' . sanitize_file_name( $type . '-' . $id ) );
		if ( is_wp_error( $current ) ) {
			return self::outcome( $result, 'failed', $current->get_error_message() );
		}

		$kept = self::find_kept( $type, $id );
		if ( $kept && $kept['version'] === $item['version'] ) {
			if ( ! $this->restore( $kept['backup'], $source ) ) {
				$this->restore( $current, $source );
				$this->discard( $current );
				return self::outcome( $result, 'failed', __( 'Couldn’t restore the saved copy. Nothing was changed.', 'wp-siteherd' ) );
			}
			$via = __( 'a copy saved at the last update', 'wp-siteherd' );
		} else {
			$installed = $this->install_from_wporg( $type, $id, $item['version'] );
			if ( is_wp_error( $installed ) ) {
				// A failed install can leave the folder half-removed.
				$present = 'plugin' === $type ? file_exists( WP_PLUGIN_DIR . '/' . $id ) : file_exists( $source . '/style.css' );
				return $this->install_failed( $result, $installed->get_error_message(), $present, $current, $source );
			}
			$via = 'wordpress.org';
		}

		if ( $check_health ) {
			$health = self::check_site();
			if ( true !== $health ) {
				$this->restore( $current, $source );
				$this->discard( $current );
				return self::outcome(
					$result,
					'rolled_back',
					/* translators: 1: what failed, 2: version that was restored */
					sprintf( __( 'The site stopped loading after rolling back (%1$s), so version %2$s was restored.', 'wp-siteherd' ), $health, $result['from'] )
				);
			}
		}

		// Keep the version we just left, so the rollback can itself be undone.
		$this->keep( $current, $type, $id, $result['from'] );
		/* translators: %s: where the old version came from */
		return self::outcome( $result, 'updated', sprintf( __( 'Restored from %s.', 'wp-siteherd' ), $via ) );
	}

	/**
	 * Install a specific version from wordpress.org over the current one.
	 *
	 * @return true|WP_Error
	 */
	private function install_from_wporg( $type, $id, $version ) {
		$slug = 'plugin' === $type ? dirname( $id ) : $id;
		if ( 'plugin' === $type && '.' === $slug ) {
			return new WP_Error( 'siteherd_no_package', __( 'Single-file plugins can only be rolled back from a saved copy, and there isn’t one for that version.', 'wp-siteherd' ) );
		}
		$url  = sprintf( 'https://downloads.wordpress.org/%s/%s.%s.zip', $type, rawurlencode( $slug ), rawurlencode( $version ) );
		$head = wp_remote_head( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $head ) ) {
			return new WP_Error(
				'siteherd_no_package',
				/* translators: %s: version number */
				sprintf( __( 'There’s no saved copy of version %s, and wordpress.org doesn’t offer it (premium or custom plugins and themes can only be rolled back from a saved copy).', 'wp-siteherd' ), $version )
			);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = 'plugin' === $type ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		$upgrader->init();
		$upgrader->upgrade_strings();
		$outcome = $upgrader->run(
			array(
				'package'           => $url,
				'destination'       => 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $id ),
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array(
					$type    => $id,
					'type'   => $type,
					'action' => 'update',
				),
			)
		);
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		if ( is_wp_error( $outcome ) || ! $outcome ) {
			return new WP_Error( 'siteherd_install', self::upgrader_error( $upgrader, $outcome ) );
		}
		return true;
	}

	/**
	 * Keep a backup as the saved copy of $version for this item, replacing any
	 * earlier one. Stored as kept/<key>/ plus kept/<key>.json.
	 */
	private function keep( $backup, $type, $id, $version ) {
		global $wp_filesystem;
		$dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '/kept';
		if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			$this->discard( $backup );
			return;
		}
		$key = self::kept_key( $type, $id );
		$wp_filesystem->delete( $dir . '/' . $key, true );
		if ( ! $wp_filesystem->move( $backup['path'], $dir . '/' . $key, true ) ) {
			$this->discard( $backup );
			return;
		}
		$wp_filesystem->put_contents(
			$dir . '/' . $key . '.json',
			wp_json_encode(
				array(
					'type'        => $type,
					'id'          => $id,
					'version'     => (string) $version,
					'single_file' => $backup['single_file'],
					'kept_at'     => time(),
				)
			),
			FS_CHMOD_FILE
		);
	}

	private static function kept_key( $type, $id ) {
		return $type . '-' . md5( $id );
	}

	/**
	 * @return array{version: string, kept_at: int, backup: array}|null
	 */
	private static function find_kept( $type, $id ) {
		$dir  = WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '/kept/' . self::kept_key( $type, $id );
		$meta = is_readable( $dir . '.json' ) ? json_decode( (string) file_get_contents( $dir . '.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file.
		if ( ! is_array( $meta ) || ! is_dir( $dir ) ) {
			return null;
		}
		return array(
			'version' => (string) $meta['version'],
			'kept_at' => (int) $meta['kept_at'],
			'backup'  => array(
				'path'        => $dir,
				'single_file' => ! empty( $meta['single_file'] ),
			),
		);
	}

	/**
	 * Saved copies that can be rolled back to, for the report.
	 *
	 * @return array<int, array{type: string, id: string, version: string, kept_at: string}>
	 */
	public static function kept_backups() {
		$dir  = WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '/kept';
		$list = array();
		foreach ( glob( $dir . '/*.json' ) ?: array() as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file.
			if ( is_array( $meta ) && isset( $meta['type'], $meta['id'], $meta['version'] ) && is_dir( substr( $file, 0, -5 ) ) ) {
				$list[] = array(
					'type'    => (string) $meta['type'],
					'id'      => (string) $meta['id'],
					'version' => (string) $meta['version'],
					'kept_at' => gmdate( 'c', (int) $meta['kept_at'] ),
				);
			}
		}
		return $list;
	}

	/**
	 * Load the homepage and the login page like a visitor would.
	 *
	 * @return true|string True if both load, otherwise what went wrong.
	 */
	public static function check_site() {
		foreach ( array( home_url( '/' ), wp_login_url() ) as $url ) {
			$response = wp_remote_get(
				add_query_arg( 'siteherd_health', time(), $url ), // Skip page caches.
				array(
					'timeout'     => 20,
					'redirection' => 0,
					// Loopback to this same server; core does the same for its own loopback requests.
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response->get_error_message();
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $code >= 500 ) {
				return sprintf( 'HTTP %d on %s', $code, $path ? $path : '/' );
			}
			$body = wp_remote_retrieve_body( $response );
			if ( false !== stripos( $body, 'There has been a critical error on this website' ) || preg_match( '#<b>(Fatal|Parse) error</b>#i', $body ) ) {
				return sprintf( 'PHP fatal error on %s', $path ? $path : '/' );
			}
		}
		return true;
	}

	/**
	 * Copy a plugin/theme folder (or single-file plugin) aside before updating it.
	 *
	 * @return array{path: string, single_file: bool}|WP_Error
	 */
	private function backup( $source, $name ) {
		global $wp_filesystem;
		$root = WP_CONTENT_DIR . '/' . self::BACKUP_DIR;
		$dest = $root . '/' . $name . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
		foreach ( array( $root, $dest ) as $dir ) {
			if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
				return new WP_Error( 'siteherd_backup', __( 'Couldn’t create a backup folder, so the update was not attempted.', 'wp-siteherd' ) );
			}
		}
		// Backed-up code shouldn't be reachable over the web.
		if ( ! $wp_filesystem->exists( $root . '/.htaccess' ) ) {
			$wp_filesystem->put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n", FS_CHMOD_FILE );
			$wp_filesystem->put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
		$single = is_file( $source );
		$copied = $single
			? $wp_filesystem->copy( $source, $dest . '/' . basename( $source ), true, FS_CHMOD_FILE )
			: copy_dir( $source, $dest );
		$backup = array(
			'path'        => $dest,
			'single_file' => $single,
		);
		if ( true !== $copied ) {
			$this->discard( $backup );
			return new WP_Error( 'siteherd_backup', __( 'Couldn’t back up the current version, so the update was not attempted.', 'wp-siteherd' ) );
		}
		return $backup;
	}

	/**
	 * Put the backed-up version back.
	 *
	 * @return bool Whether the files are back in place.
	 */
	private function restore( $backup, $source ) {
		global $wp_filesystem;
		if ( $backup['single_file'] ) {
			$restored = $wp_filesystem->copy( $backup['path'] . '/' . basename( $source ), $source, true, FS_CHMOD_FILE );
		} else {
			$wp_filesystem->delete( $source, true );
			$wp_filesystem->mkdir( $source, FS_CHMOD_DIR );
			$restored = true === copy_dir( $backup['path'], $source ) && (bool) $wp_filesystem->dirlist( $source );
		}
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		return (bool) $restored;
	}

	private function discard( $backup ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $backup['path'], true );
	}

	/**
	 * Remove temporary backups left by jobs that died midway (after a day) and
	 * saved copies for rollback that are older than KEEP_DAYS.
	 */
	private function purge_old_backups() {
		global $wp_filesystem;
		$root = WP_CONTENT_DIR . '/' . self::BACKUP_DIR;
		$list = $wp_filesystem->is_dir( $root ) ? $wp_filesystem->dirlist( $root ) : array();
		foreach ( (array) $list as $name => $info ) {
			if ( in_array( $name, array( 'kept', '.htaccess', 'index.php' ), true ) ) {
				continue;
			}
			if ( isset( $info['lastmodunix'] ) && $info['lastmodunix'] < time() - DAY_IN_SECONDS ) {
				$wp_filesystem->delete( $root . '/' . $name, true );
			}
		}
		foreach ( glob( $root . '/kept/*.json' ) ?: array() as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file.
			if ( ! is_array( $meta ) || (int) $meta['kept_at'] < time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				$wp_filesystem->delete( substr( $file, 0, -5 ), true );
				$wp_filesystem->delete( $file );
			}
		}
	}

	private static function plugin_offer( $file ) {
		$updates = get_site_transient( 'update_plugins' );
		return isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ] : null;
	}

	private static function theme_offer( $stylesheet ) {
		$updates = get_site_transient( 'update_themes' );
		return isset( $updates->response[ $stylesheet ]['new_version'] ) ? $updates->response[ $stylesheet ] : null;
	}

	private static function core_offer() {
		foreach ( (array) get_core_updates() as $offer ) {
			if ( isset( $offer->response ) && 'upgrade' === $offer->response ) {
				return $offer;
			}
		}
		return null;
	}

	private static function upgrader_error( $upgrader, $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			return $outcome->get_error_message();
		}
		if ( is_wp_error( $upgrader->skin->result ) ) {
			return $upgrader->skin->result->get_error_message();
		}
		$messages = $upgrader->skin->get_upgrade_messages();
		return $messages ? wp_strip_all_tags( end( $messages ) ) : __( 'The update failed for an unknown reason.', 'wp-siteherd' );
	}

	private static function result( $type, $id ) {
		return array(
			'type'    => $type,
			'id'      => $id,
			'name'    => $id,
			'from'    => null,
			'to'      => null,
			'status'  => 'failed',
			'message' => null,
		);
	}

	private static function outcome( $result, $status, $message = null ) {
		$result['status']  = $status;
		$result['message'] = $message;
		return $result;
	}

	private function push( $job, $status, $results, $error = null, $note = null ) {
		$conn = Siteherd_Connection::get();
		if ( $conn ) {
			Siteherd_Reporter::post_signed(
				$conn,
				'/api/sites/update-result',
				array(
					'job_id'  => $job['id'],
					'status'  => $status,
					'results' => $results,
					'error'   => $error,
					'note'    => $note,
				)
			);
		}
	}

	private function finish( $job, $status, $results, $error = null, $note = null ) {
		delete_option( self::JOB_OPTION );
		delete_option( 'siteherd_update_claim_' . $job['id'] );
		$this->push( $job, $status, $results, $error, $note );
		// So the dashboard's pending-updates list reflects the new versions.
		$this->reporter->send();
	}
}
