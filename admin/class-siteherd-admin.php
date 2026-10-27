<?php
/**
 * Settings → WP Siteherd: paste the connection key, send a report, disconnect.
 *
 * @package WP_Siteherd
 */

class Siteherd_Admin {

	const PAGE_SLUG = 'wp-siteherd';

	/** @var Siteherd_Reporter */
	private $reporter;

	/** @var Siteherd_Checks */
	private $checks;

	/** @var string|false Hook suffix of the settings page, used to load CSS only there. */
	private $page_hook = false;

	public function __construct( Siteherd_Reporter $reporter, Siteherd_Checks $checks ) {
		$this->reporter = $reporter;
		$this->checks   = $checks;
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wp-siteherd', false, dirname( plugin_basename( SITEHERD_FILE ) ) . '/languages' );
	}

	public function add_menu() {
		$this->page_hook = add_options_page(
			__( 'WP Siteherd', 'wp-siteherd' ),
			__( 'WP Siteherd', 'wp-siteherd' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}
		wp_enqueue_style( 'wp-siteherd-admin', plugins_url( 'admin/css/siteherd-admin.css', SITEHERD_FILE ), array(), SITEHERD_VERSION );
	}

	public function add_settings_link( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'wp-siteherd' ) )
		);
		return $links;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$connection = Siteherd_Connection::get();
		// Self-heal a lost schedule (e.g. cron data wiped by another plugin).
		if ( $connection && ! Siteherd_Reporter::next_run() ) {
			Siteherd_Reporter::schedule();
		}
		$next_run   = $connection ? Siteherd_Reporter::next_run() : false;
		$overdue    = $connection && Siteherd_Reporter::is_overdue();
		$checks_at  = Siteherd_Checks::last_run();
		$sso        = array(
			'enabled' => Siteherd_Sso::is_enabled(),
			'user_id' => Siteherd_Sso::fixed_user_id(),
			'admins'  => Siteherd_Sso::admins(),
			'recent'  => array_slice( Siteherd_Sso::recent_logins(), 0, 5 ),
		);
		$last       = Siteherd_Reporter::last_result();
		$notice     = $this->take_notice();
		require SITEHERD_DIR . 'admin/partials/siteherd-admin-display.php';
	}

	public function handle_save_key() {
		$this->authorize( 'siteherd_save_key' );

		$key   = isset( $_POST['siteherd_key'] ) ? sanitize_text_field( wp_unslash( $_POST['siteherd_key'] ) ) : '';
		$saved = Siteherd_Connection::save_from_key( $key );
		if ( is_wp_error( $saved ) ) {
			$this->finish( 'error', $saved->get_error_message() );
		}

		Siteherd_Reporter::schedule();
		$result = $this->reporter->send();
		if ( $result['error'] ) {
			/* translators: %s: reason the first report failed */
			$this->finish( 'warning', sprintf( __( 'Key saved, but the first report failed: %s', 'wp-siteherd' ), $result['error'] ) );
		}
		$this->finish( 'success', __( 'Connected. The first report reached your dashboard.', 'wp-siteherd' ) );
	}

	public function handle_send_now() {
		$this->authorize( 'siteherd_send_now' );

		$result = $this->reporter->send();
		if ( $result['error'] ) {
			$this->finish( 'error', $result['error'] );
		}
		$this->finish( 'success', __( 'Report sent.', 'wp-siteherd' ) );
	}

	public function handle_run_checks() {
		$this->authorize( 'siteherd_run_checks' );

		// Runs inline (can take a minute on large sites), then reports the results.
		$this->checks->run();
		$result = Siteherd_Reporter::last_result();
		if ( $result && $result['error'] ) {
			/* translators: %s: reason the report failed */
			$this->finish( 'warning', sprintf( __( 'Checks finished, but sending the results failed: %s', 'wp-siteherd' ), $result['error'] ) );
		}
		$this->finish( 'success', __( 'Site Health and file checks finished and were sent to your dashboard.', 'wp-siteherd' ) );
	}

	public function handle_save_sso() {
		$this->authorize( 'siteherd_save_sso' );

		$user_id = isset( $_POST['siteherd_sso_user'] ) ? absint( $_POST['siteherd_sso_user'] ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( $user_id && ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) ) ) {
			$this->finish( 'error', __( 'Choose an administrator account.', 'wp-siteherd' ) );
		}

		update_option( Siteherd_Sso::OPTION_ENABLED, isset( $_POST['siteherd_sso_enabled'] ) ? '1' : '0', false );
		update_option( Siteherd_Sso::OPTION_USER, $user_id, false );
		// Tell the dashboard right away, so its WP Admin button matches.
		if ( Siteherd_Connection::get() ) {
			$this->reporter->send();
		}
		$this->finish( 'success', __( 'One-click login settings saved.', 'wp-siteherd' ) );
	}

	public function handle_disconnect() {
		$this->authorize( 'siteherd_disconnect' );

		Siteherd_Reporter::unschedule();
		Siteherd_Connection::clear();
		delete_option( Siteherd_Reporter::RESULT_OPTION );
		delete_option( Siteherd_Checks::LAST_RUN );
		delete_option( Siteherd_Site_Health::OPTION );
		delete_option( Siteherd_Integrity::OPTION );
		$this->finish( 'success', __( 'Disconnected. This site no longer reports to the dashboard.', 'wp-siteherd' ) );
	}

	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-siteherd' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Store a one-time notice for the current user and go back to the settings page.
	 *
	 * @param string $type    success|warning|error.
	 * @param string $message Plain text.
	 */
	private function finish( $type, $message ) {
		set_transient( $this->notice_key(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	private function take_notice() {
		$notice = get_transient( $this->notice_key() );
		delete_transient( $this->notice_key() );
		return is_array( $notice ) ? $notice : null;
	}

	private function notice_key() {
		return 'siteherd_notice_' . get_current_user_id();
	}

	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}
}
