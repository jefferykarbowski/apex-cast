<?php
/**
 * Plugin Name:       Apex Cast for WooCommerce
 * Plugin URI:        https://github.com/jefferykarbowski/apex-cast
 * Description:       Generate AI social posts from any WooCommerce product, review them inline, and broadcast to Postiz, Buffer, or your own scheduler — without leaving your product editor.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Apex Chute LLC
 * Author URI:        https://apexchute.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       apex-cast
 * Domain Path:       /languages
 * Network:           true
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

defined( 'ABSPATH' ) || exit;

// Constants.
define( 'APEX_CAST_VERSION', '0.1.0' );
define( 'APEX_CAST_FILE', __FILE__ );
define( 'APEX_CAST_PATH', plugin_dir_path( __FILE__ ) );
define( 'APEX_CAST_URL', plugin_dir_url( __FILE__ ) );
define( 'APEX_CAST_MIN_PHP', '8.1' );
define( 'APEX_CAST_MIN_WP', '6.0' );
define( 'APEX_CAST_MIN_WC', '7.0' );

// Composer autoload.
if ( file_exists( APEX_CAST_PATH . 'vendor/autoload.php' ) ) {
	require APEX_CAST_PATH . 'vendor/autoload.php';
}

/**
 * Pre-flight environment checks. Bails out gracefully with admin notice if requirements unmet.
 */
function apex_cast_check_requirements(): bool {
	if ( version_compare( PHP_VERSION, APEX_CAST_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p><strong>Apex Cast:</strong> requires PHP ' . esc_html( APEX_CAST_MIN_PHP ) . ' or higher.</p></div>';
			}
		);
		return false;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p><strong>Apex Cast:</strong> requires WooCommerce to be active.</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Declare WooCommerce HPOS (custom order tables) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once WordPress + WooCommerce are ready.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! apex_cast_check_requirements() ) {
			return;
		}

		Plugin::instance()->init();
	},
	20
);

/**
 * Activation hook — create custom tables, set default options.
 */
register_activation_hook(
	__FILE__,
	function () {
		Installer::activate();
	}
);

/**
 * Deactivation hook — clean up scheduled actions, transients.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		Installer::deactivate();
	}
);

/**
 * Uninstall is handled by uninstall.php for proper cleanup of options + tables.
 */
