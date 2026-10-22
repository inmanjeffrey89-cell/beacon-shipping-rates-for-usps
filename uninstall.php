<?php
/**
 * Clean uninstall: every option and transient this plugin created, including per-zone method settings.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
delete_option( 'busr_settings' );
delete_option( 'busr_stats' );
delete_option( 'busr_cache_gen' );
delete_transient( 'busr_token' );
delete_metadata( 'user', 0, 'busr_setup_dismissed', '', true );
foreach ( array( '_transient_busr_%', '_transient_timeout_busr_%', 'woocommerce_beacon_usps_%' ) as $busr_pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $busr_pattern ) );
}
