<?php
/**
 * Plugin Name:       WP Siteherd
 * Plugin URI:        https://siteherd.example.com
 * Description:       Connects this site to your WP Siteherd dashboard: versions, pending updates and failed logins, reported securely every few minutes.
 * Version:           0.7.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            slightgrey
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-siteherd
 * Domain Path:       /languages
 *
 * @package WP_Siteherd
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'SITEHERD_VERSION', '0.7.1' );
define( 'SITEHERD_FILE', __FILE__ );
define( 'SITEHERD_DIR', plugin_dir_path( __FILE__ ) );

require_once SITEHERD_DIR . 'includes/class-siteherd-activator.php';
require_once SITEHERD_DIR . 'includes/class-siteherd-deactivator.php';

register_activation_hook( __FILE__, array( 'Siteherd_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Siteherd_Deactivator', 'deactivate' ) );

require_once SITEHERD_DIR . 'includes/class-siteherd.php';

( new Siteherd() )->run();
