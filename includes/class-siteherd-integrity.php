<?php
/**
 * Compares WordPress core and wordpress.org plugin files against the official
 * checksums published by wordpress.org. Checksums are only fetched over HTTPS;
 * if that fails the check reports an error rather than trusting plain HTTP.
 *
 * @package WP_Siteherd
 */

class Siteherd_Integrity {

	const OPTION     = 'siteherd_integrity';
	const LIST_LIMIT = 50;

	// Commonly deleted on purpose for hardening; not worth flagging when missing.
	const OPTIONAL_CORE_FILES = array( 'readme.html', 'license.txt', 'wp-config-sample.php' );

	/**
	 * @return array{checked_at: string, core: array, plugins: array<int, array>}
	 */
	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled on some hosts.
		}
		return array(
			'checked_at' => gmdate( 'c' ),
			'core'       => self::check_core(),
			'plugins'    => self::check_plugins(),
		);
	}

	private static function check_core() {
		$version   = get_bloginfo( 'version' );
		$checksums = self::fetch_core_checksums( $version, get_locale() );
		if ( is_wp_error( $checksums ) && 'en_US' !== get_locale() ) {
			$checksums = self::fetch_core_checksums( $version, 'en_US' );
		}
		if ( is_wp_error( $checksums ) ) {
			return array(
				'status' => 'error',
				'error'  => $checksums->get_error_message(),
			);
		}

		$modified = array();
		$missing  = array();
		foreach ( $checksums as $file => $md5 ) {
			// Bundled themes/plugins are updated and removed independently of core.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) {
				if ( ! in_array( $file, self::OPTIONAL_CORE_FILES, true ) ) {
					$missing[] = $file;
				}
			} elseif ( md5_file( $path ) !== $md5 ) {
				$modified[] = $file;
			}
		}

		$unexpected = self::unexpected_core_files( $checksums );

		return array_merge(
			array( 'status' => ( $modified || $missing || $unexpected ) ? 'issues' : 'ok' ),
			self::file_list( 'modified', $modified ),
			self::file_list( 'missing', $missing ),
			self::file_list( 'unexpected', $unexpected )
		);
	}

	/**
	 * Files inside wp-admin/ and wp-includes/, plus PHP files in the site root,
	 * that aren't part of core. Malware often hides in these places.
	 */
	private static function unexpected_core_files( $checksums ) {
		$found = array();
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			$base = ABSPATH . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$relative = $dir . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) + 1 ) );
					if ( ! isset( $checksums[ $relative ] ) ) {
						$found[] = $relative;
					}
				}
			}
		}
		foreach ( glob( ABSPATH . '*.php' ) ?: array() as $path ) {
			$name = basename( $path );
			if ( 'wp-config.php' !== $name && ! isset( $checksums[ $name ] ) ) {
				$found[] = $name;
			}
		}
		sort( $found );
		return $found;
	}

	/**
	 * @return array<string, string>|WP_Error Map of relative path => md5.
	 */
	private static function fetch_core_checksums( $version, $locale ) {
		$url  = add_query_arg(
			array(
				'version' => $version,
				'locale'  => $locale,
			),
			'https://api.wordpress.org/core/checksums/1.0/'
		);
		$data = self::fetch_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			/* translators: 1: WordPress version, 2: locale */
			return new WP_Error( 'siteherd_no_checksums', sprintf( __( 'wordpress.org has no checksums for WordPress %1$s (%2$s).', 'wp-siteherd' ), $version, $locale ) );
		}
		return $data['checksums'];
	}

	private static function check_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$results = array();
		foreach ( get_plugins() as $file => $data ) {
			$results[] = array_merge( array( 'file' => $file ), self::check_plugin( $file, $data ) );
		}
		return $results;
	}

	private static function check_plugin( $file, $data ) {
		$not_checkable = array( 'status' => 'not_checkable' );

		// Single-file plugins (e.g. hello.php) have no folder to match a slug.
		if ( false === strpos( $file, '/' ) ) {
			return $not_checkable;
		}
		// Plugins that declare a non-wordpress.org update source aren't from the directory.
		if ( ! empty( $data['UpdateURI'] ) && ! preg_match( '#^(https?://)?(w\.org|wordpress\.org)/#i', $data['UpdateURI'] ) ) {
			return $not_checkable;
		}

		$slug = dirname( $file );
		$url  = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode( $slug ), rawurlencode( $data['Version'] ) );
		$json = self::fetch_json( $url );
		if ( is_wp_error( $json ) ) {
			// 404: not in the directory (premium/custom) or that version isn't published.
			return 'siteherd_http_404' === $json->get_error_code()
				? $not_checkable
				: array(
					'status' => 'error',
					'error'  => $json->get_error_message(),
				);
		}
		if ( empty( $json['files'] ) || ! is_array( $json['files'] ) ) {
			return $not_checkable;
		}

		$dir      = WP_PLUGIN_DIR . '/' . $slug . '/';
		$modified = array();
		$missing  = array();
		foreach ( $json['files'] as $path => $sums ) {
			$full = $dir . $path;
			if ( ! file_exists( $full ) ) {
				$missing[] = $path;
				continue;
			}
			// md5 is a string, or a list when a file differs between builds.
			$allowed = isset( $sums['md5'] ) ? (array) $sums['md5'] : array();
			if ( ! in_array( md5_file( $full ), $allowed, true ) ) {
				$modified[] = $path;
			}
		}

		return array_merge(
			array( 'status' => ( $modified || $missing ) ? 'issues' : 'ok' ),
			self::file_list( 'modified', $modified ),
			self::file_list( 'missing', $missing )
		);
	}

	/**
	 * @return array|WP_Error Decoded JSON body.
	 */
	private static function fetch_json( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: 1: HTTP status code, 2: URL */
			return new WP_Error( 'siteherd_http_' . $code, sprintf( __( 'HTTP %1$d from %2$s', 'wp-siteherd' ), $code, $url ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'siteherd_bad_json', __( 'Unexpected response from wordpress.org.', 'wp-siteherd' ) );
	}

	/**
	 * Keep payloads small: the first LIST_LIMIT paths plus the full count.
	 */
	private static function file_list( $name, $files ) {
		return array(
			$name            => array_slice( $files, 0, self::LIST_LIMIT ),
			$name . '_count' => count( $files ),
		);
	}
}
