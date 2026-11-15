<?php
/**
 * Plugin Name:       Light Show Car Counter
 * Description:       Receives nightly car counts from the FPP "Car Counter" plugin and shows them on your site with shortcodes, charts and an admin dashboard.
 * Version:           1.3.2
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       light-show-car-counter
 */

defined( 'ABSPATH' ) || exit;

define( 'LSCC_VERSION', '1.3.2' );
define( 'LSCC_FILE', __FILE__ );
define( 'LSCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSCC_URL', plugin_dir_url( __FILE__ ) );

require_once LSCC_DIR . 'includes/class-lscc-settings.php';
require_once LSCC_DIR . 'includes/class-lscc-db.php';
require_once LSCC_DIR . 'includes/class-lscc-rest.php';
require_once LSCC_DIR . 'includes/class-lscc-render.php';
require_once LSCC_DIR . 'includes/class-lscc-shortcodes.php';
require_once LSCC_DIR . 'includes/class-lscc-admin.php';
require_once LSCC_DIR . 'includes/class-lscc-updater.php';

register_activation_hook( __FILE__, array( 'LSCC_DB', 'activate' ) );

add_action( 'plugins_loaded', function () {
	LSCC_DB::maybe_upgrade();
	LSCC_REST::init();
	LSCC_Updater::init();
	LSCC_Shortcodes::init();
	if ( is_admin() ) {
		LSCC_Admin::init();
	}
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lscc-settings' ) ) . '">' . esc_html__( 'Settings', 'light-show-car-counter' ) . '</a>' );
	return $links;
} );
