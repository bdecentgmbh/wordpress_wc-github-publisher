<?php
/**
 * Main plugin bootstrap.
 *
 * @package WCGP
 */

namespace WCGP;

use WCGP\Admin\SettingsPage;
use WCGP\Admin\ProductGitHubTab;
use WCGP\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's pieces together.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin. Admin-only — there is no front-end surface; WooCommerce
	 * owns everything customer-facing once a file is published.
	 */
	public function boot() {
		if ( is_admin() ) {
			( new SettingsPage() )->register();
			( new ProductGitHubTab() )->register();
			( new Notices() )->register();
		}
	}
}
