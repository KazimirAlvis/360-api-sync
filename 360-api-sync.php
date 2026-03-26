<?php
/**
 * Plugin Name: 360 API Sync
 * Plugin URI: https://github.com/KazimirAlvis/360-api-sync
 * Description: Synchronizes clinic and doctor data from the PR360 API into WordPress custom post types used by the 360 medical site network.
 * Version: 1.3.4
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

define( 'API360_SYNC_VERSION', '1.3.4' );
define( 'THREESIXTY_API_SYNC_VERSION', API360_SYNC_VERSION );
define( 'THREESIXTY_API_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'THREESIXTY_API_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once THREESIXTY_API_SYNC_PATH . 'plugin-update-checker/plugin-update-checker.php';

$api360_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/KazimirAlvis/360-api-sync/',
	__FILE__,
	'360-api-sync'
);

$api360_update_checker->setBranch( 'main' );

$api360_github_token = defined( 'API360_SYNC_GITHUB_TOKEN' ) ? (string) constant( 'API360_SYNC_GITHUB_TOKEN' ) : '';
$api360_github_token = apply_filters( 'api360_sync_github_token', $api360_github_token );
if ( '' === trim( (string) $api360_github_token ) ) {
	$api360_settings = get_option( '360_api_sync_settings', array() );
	if ( is_array( $api360_settings ) ) {
		$api360_github_token = (string) ( $api360_settings['github_token'] ?? '' );
	}
}
if ( ! empty( $api360_github_token ) && method_exists( $api360_update_checker, 'setAuthentication' ) ) {
	$api360_update_checker->setAuthentication( sanitize_text_field( (string) $api360_github_token ) );
}

require_once THREESIXTY_API_SYNC_PATH . 'includes/api-client.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/image-importer.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/clinic-sync.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/doctor-sync.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/sync-log.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/cron.php';
require_once THREESIXTY_API_SYNC_PATH . 'includes/settings-page.php';

add_action(
	'plugins_loaded',
	static function () {
		\ThreeSixty\ApiSync\Cron::init();
		\ThreeSixty\ApiSync\Settings_Page::init();
	}
);

register_activation_hook( __FILE__, array( '\\ThreeSixty\\ApiSync\\Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\ThreeSixty\\ApiSync\\Cron', 'deactivate' ) );
