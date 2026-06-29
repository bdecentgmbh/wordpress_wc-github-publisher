<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's settings and cached metadata. Product meta and already
 * published files are intentionally left in place so existing customers keep
 * their downloads after the plugin is removed.
 *
 * @package WCGP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcgp_settings' );
delete_option( 'wcgp_last_error' );
delete_option( 'wcgp_rate' );

global $wpdb;

// Remove per-repo ETag options, fetch-meta options, and cached release transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcgp_etag_%' OR option_name LIKE 'wcgp_meta_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcgp_releases_%' OR option_name LIKE '_transient_timeout_wcgp_releases_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
