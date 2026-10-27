<?php
/**
 * Runs WordPress's own Site Health tests, the same way core's weekly
 * background check does (WP_Site_Health::wp_cron_scheduled_check), but keeps
 * the details of each issue instead of only the counts.
 *
 * @package WP_Siteherd
 */

class Siteherd_Site_Health {

	const OPTION = 'siteherd_health';

	/**
	 * @return array{checked_at: string, counts: array<string, int>, issues: array<int, array>, skipped: int}
	 */
	public static function run() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$health  = WP_Site_Health::get_instance();
		$tests   = WP_Site_Health::get_tests();
		$results = array();
		$skipped = 0;

		// Like core: the HTTPS test is meaningless on local/dev sites.
		if ( $health->is_development_environment() ) {
			unset( $tests['async']['https_status'] );
		}

		foreach ( $tests['direct'] as $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			$callback = null;
			if ( is_string( $test['test'] ) && is_callable( array( $health, 'get_test_' . $test['test'] ) ) ) {
				$callback = array( $health, 'get_test_' . $test['test'] );
			} elseif ( is_callable( $test['test'] ) ) {
				$callback = $test['test'];
			}
			if ( $callback ) {
				$results[] = self::perform( $callback );
			}
		}

		// Async tests normally run from the browser; only those with a direct
		// runner can run here. The rest would need loopback requests.
		foreach ( $tests['async'] as $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			if ( ! empty( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
				$results[] = self::perform( $test['async_direct_test'] );
			} else {
				++$skipped;
			}
		}

		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);
		$issues = array();
		foreach ( $results as $result ) {
			$status = isset( $result['status'] ) && isset( $counts[ $result['status'] ] ) ? $result['status'] : 'recommended';
			++$counts[ $status ];
			if ( 'good' !== $status ) {
				$issues[] = array(
					'test'        => isset( $result['test'] ) ? (string) $result['test'] : '',
					'label'       => isset( $result['label'] ) ? wp_strip_all_tags( $result['label'] ) : '',
					'status'      => $status,
					'badge'       => isset( $result['badge']['label'] ) ? wp_strip_all_tags( $result['badge']['label'] ) : '',
					'description' => isset( $result['description'] ) ? self::plain_text( $result['description'] ) : '',
				);
			}
		}

		// Critical first.
		usort(
			$issues,
			function ( $a, $b ) {
				return strcmp( $a['status'], $b['status'] );
			}
		);

		return array(
			'checked_at' => gmdate( 'c' ),
			'counts'     => $counts,
			'issues'     => $issues,
			'skipped'    => $skipped,
		);
	}

	private static function perform( $callback ) {
		// Same filter core applies, so plugins that adjust results are respected.
		$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) );
		return is_array( $result ) ? $result : array();
	}

	private static function plain_text( $html ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
	}
}
