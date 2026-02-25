<?php
/**
 * Uninstall script for SRLines Order Notifications
 *
 * Fired when the plugin is uninstalled.
 *
 * @package SRLines_Order_Notifications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove plugin options
delete_option( 'wc_notifications_settings' );
delete_option( 'wc_notifications_db_version' );

// Remove custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_notifications" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_notifications_context" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_notifications_responses" );

// Clear scheduled events
wp_clear_scheduled_hook( 'wc_notifications_cleanup' );
