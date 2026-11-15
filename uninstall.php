<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$settings = get_option( 'lscc_settings', array() );
if ( ! empty( $settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'lscc_days' );
	foreach ( array( 'lscc_settings', 'lscc_db_version', 'lscc_cache_ver', 'lscc_last_sync', 'lscc_sync_log', 'lscc_blocked' ) as $opt ) {
		delete_option( $opt );
	}
}
