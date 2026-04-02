<?php
/**
 * Plugin Name: 360 API Sync
 * Plugin URI: https://github.com/KazimirAlvis/360-api-sync
 * Description: Synchronizes clinic and doctor data from the PR360 API into WordPress custom post types used by the 360 medical site network.
 * Version: 1.4.9
 * Author: PR360
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/KazimirAlvis/360-api-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: 360-api-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'API360_SYNC_VERSION', '1.4.9' );
define( 'THREESIXTY_API_SYNC_VERSION', API360_SYNC_VERSION );
define( 'THREESIXTY_API_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'THREESIXTY_API_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once THREESIXTY_API_SYNC_PATH . 'includes/api-client.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/image-importer.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/clinic-sync.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/doctor-sync.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/sync-log.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/cron.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/settings-page.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/updater.php';

add_action(
	'plugins_loaded',
	static function () {
		\ThreeSixty\ApiSync\Cron::init();
		\ThreeSixty\ApiSync\Settings_Page::init();
		\ThreeSixty\ApiSync\Updater::init();
	}
);

register_activation_hook( __FILE__, array( '\\ThreeSixty\\ApiSync\\Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\ThreeSixty\\ApiSync\\Cron', 'deactivate' ) );
