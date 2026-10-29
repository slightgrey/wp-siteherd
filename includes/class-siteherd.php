<?php
/**
 * Core plugin class: loads dependencies and registers every hook.
 *
 * @package WP_Siteherd
 */

class Siteherd {

	/** @var Siteherd_Loader */
	protected $loader;

	public function __construct() {
		$this->load_dependencies();
		$this->loader = new Siteherd_Loader();
		$this->define_hooks();
	}

	private function load_dependencies() {
		$dir = SITEHERD_DIR . 'includes/';
		require_once $dir . 'class-siteherd-loader.php';
		require_once $dir . 'class-siteherd-signer.php';
		require_once $dir . 'class-siteherd-connection.php';
		require_once $dir . 'class-siteherd-branding.php';
		require_once $dir . 'class-siteherd-login-monitor.php';
		require_once $dir . 'class-siteherd-hardening.php';
		require_once $dir . 'class-siteherd-lockout-policy.php';
		require_once $dir . 'class-siteherd-lockout.php';
		require_once $dir . 'class-siteherd-collector.php';
		require_once $dir . 'class-siteherd-reporter.php';
		require_once $dir . 'class-siteherd-site-health.php';
		require_once $dir . 'class-siteherd-integrity.php';
		require_once $dir . 'class-siteherd-checks.php';
		require_once $dir . 'class-siteherd-sso.php';
		require_once $dir . 'class-siteherd-updater.php';
		require_once $dir . 'class-siteherd-self-update.php';
		require_once $dir . 'class-siteherd-rest.php';
		require_once SITEHERD_DIR . 'admin/class-siteherd-admin.php';
	}

	private function define_hooks() {
		$reporter = new Siteherd_Reporter();
		$this->loader->add_filter( 'cron_schedules', $reporter, 'add_cron_schedule' );
		$this->loader->add_action( Siteherd_Reporter::CRON_HOOK, $reporter, 'send' );
		$this->loader->add_action( Siteherd_Reporter::SOON_HOOK, $reporter, 'send' );
		// Report shortly after anything that changes versions or the active set.
		foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'switch_theme', '_core_updated_successfully' ) as $hook ) {
			$this->loader->add_action( $hook, $reporter, 'schedule_soon' );
		}

		$checks = new Siteherd_Checks( $reporter );
		$this->loader->add_action( Siteherd_Checks::CRON_HOOK, $checks, 'run' );

		$logins = new Siteherd_Login_Monitor();
		$this->loader->add_action( 'wp_login_failed', $logins, 'record_failure' );

		$hardening = new Siteherd_Hardening();
		$this->loader->add_filter( 'xmlrpc_enabled', $hardening, 'filter_xmlrpc_enabled', 99 );

		$lockout = new Siteherd_Lockout();
		$this->loader->add_filter( 'authenticate', $lockout, 'block_early', 1, 3 );
		$this->loader->add_filter( 'authenticate', $lockout, 'block_late', PHP_INT_MAX );
		$this->loader->add_action( 'wp_login_failed', $lockout, 'record_failure' );

		$sso = new Siteherd_Sso();
		$this->loader->add_action( 'login_form_siteherd_sso', $sso, 'handle_login' );

		$this->loader->add_action( 'init', $this, 'maybe_upgrade' );

		$updater = new Siteherd_Updater( $reporter );
		$this->loader->add_action( Siteherd_Updater::CRON_HOOK, $updater, 'run' );

		// Updates for this plugin itself, from GitHub releases.
		$self_update = new Siteherd_Self_Update();
		$this->loader->add_filter( 'pre_set_site_transient_update_plugins', $self_update, 'add_update' );
		$this->loader->add_filter( 'plugins_api', $self_update, 'plugin_info', 10, 3 );

		$rest = new Siteherd_Rest( $updater, $reporter );
		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );

		if ( is_admin() ) {
			$admin = new Siteherd_Admin( $reporter, $checks );
			$this->loader->add_action( 'init', $admin, 'load_textdomain' );
			$this->loader->add_action( 'admin_menu', $admin, 'add_menu' );
			$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_assets' );
			$this->loader->add_action( 'admin_post_siteherd_save_key', $admin, 'handle_save_key' );
			$this->loader->add_action( 'admin_post_siteherd_send_now', $admin, 'handle_send_now' );
			$this->loader->add_action( 'admin_post_siteherd_run_checks', $admin, 'handle_run_checks' );
			$this->loader->add_action( 'admin_post_siteherd_disconnect', $admin, 'handle_disconnect' );
			$this->loader->add_action( 'admin_post_siteherd_save_sso', $admin, 'handle_save_sso' );
			$this->loader->add_action( 'admin_post_siteherd_save_hardening', $admin, 'handle_save_hardening' );
			$this->loader->add_action( 'admin_post_siteherd_unlock_all', $admin, 'handle_unlock_all' );
			$this->loader->add_filter( 'plugin_action_links_' . plugin_basename( SITEHERD_FILE ), $admin, 'add_settings_link' );
			$this->loader->add_filter( 'all_plugins', $admin, 'brand_plugin_list' );
		}
	}

	/**
	 * After the plugin is updated, schedule anything the new version added
	 * (activation hooks don't run on updates). The version option is
	 * autoloaded, so this costs nothing on normal requests.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'siteherd_version' ) === SITEHERD_VERSION ) {
			return;
		}
		if ( Siteherd_Connection::get() ) {
			Siteherd_Reporter::schedule();
		}
		update_option( 'siteherd_version', SITEHERD_VERSION );
	}

	public function run() {
		$this->loader->run();
	}
}
