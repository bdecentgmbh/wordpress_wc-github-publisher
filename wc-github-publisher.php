<?php
/**
 * Plugin Name:       WC GitHub Publisher
 * Plugin URI:        https://github.com/bdecentgmbh/wordpress_wc-github-publisher
 * Description:       Publish GitHub release assets (including from private repositories) as native WooCommerce downloadable product files. The plugin only handles the publish step — WooCommerce handles entitlement, the My Account downloads page, and secure serving.
 * Version:           0.5.0
 * Author:            bdecent gmbh
 * Author URI:        https://bdecent.de
 * Text Domain:       wc-github-publisher
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WCGP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCGP_VERSION', '0.5.0' );
define( 'WCGP_FILE', __FILE__ );
define( 'WCGP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCGP_URL', plugin_dir_url( __FILE__ ) );

// Autoloader: prefer a bundled Composer autoloader, otherwise a lightweight PSR-4 loader
// so the plugin runs out of the box without a `composer install` step.
if ( file_exists( WCGP_PATH . 'vendor/autoload.php' ) ) {
	require WCGP_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		function ( $class ) {
			$prefix = 'WCGP\\';
			$len    = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}
			$relative = substr( $class, $len );
			$file     = WCGP_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	);
}

// Declare High-Performance Order Storage (HPOS) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCGP_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'wc-github-publisher', false, dirname( plugin_basename( WCGP_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'WC GitHub Publisher requires WooCommerce to be installed and active.', 'wc-github-publisher' ) .
						'</p></div>';
				}
			);
			return;
		}

		\WCGP\Plugin::instance()->boot();
	}
);
