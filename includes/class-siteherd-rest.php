<?php
/**
 * REST endpoints the dashboard calls ("pull"). Every request must carry the
 * same HMAC headers the plugin uses when pushing reports.
 *
 * @package WP_Siteherd
 */

class Siteherd_Rest {

	const NAMESPACE_V1 = 'siteherd/v1';

	/** @var Siteherd_Updater */
	private $updater;

	/** @var Siteherd_Reporter */
	private $reporter;

	public function __construct( Siteherd_Updater $updater, Siteherd_Reporter $reporter ) {
		$this->updater  = $updater;
		$this->reporter = $reporter;
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
		// "Resync": send a fresh report to the dashboard now.
		register_rest_route(
			self::NAMESPACE_V1,
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->reporter, 'sync_now' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->updater, 'queue' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
		// The dashboard confirmed a job's results from a report: drop the job lock (0.7.3+).
		register_rest_route(
			self::NAMESPACE_V1,
			'/update/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->updater, 'release' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
		// Loopback from this site to itself to start a queued job (see Siteherd_Updater::queue).
		register_rest_route(
			self::NAMESPACE_V1,
			'/update/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->updater, 'run_now' ),
				'permission_callback' => array( $this->updater, 'verify_run_request' ),
			)
		);
	}

	public function status() {
		return rest_ensure_response( Siteherd_Collector::collect() );
	}

	/**
	 * Every failure gets the same 401 so callers can't tell which check failed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function verify_request( WP_REST_Request $request ) {
		$denied = new WP_Error( 'siteherd_unauthorized', 'unauthorized', array( 'status' => 401 ) );

		$conn = Siteherd_Connection::get();
		if ( ! $conn ) {
			return $denied;
		}

		$site_id   = (string) $request->get_header( Siteherd_Signer::HEADER_SITE );
		$timestamp = (string) $request->get_header( Siteherd_Signer::HEADER_TIMESTAMP );
		$signature = (string) $request->get_header( Siteherd_Signer::HEADER_SIGNATURE );
		if ( ! hash_equals( $conn['id'], $site_id ) ) {
			return $denied;
		}

		if ( true !== Siteherd_Signer::verify( $conn['secret'], $timestamp, $request->get_body(), $signature ) ) {
			return $denied;
		}

		// A valid signature may only be used once. It expires on its own after
		// the timestamp window, so 10 minutes of memory is enough.
		$seen_key = 'siteherd_sig_' . $signature;
		if ( get_transient( $seen_key ) ) {
			return $denied;
		}
		set_transient( $seen_key, 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}
}
