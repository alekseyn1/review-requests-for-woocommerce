<?php
/**
 * Removes plugin data on uninstall, but only when the operator has opted in by defining
 * RRFW_REMOVE_ALL_DATA.
 *
 * Suppression records are opt-outs. Silently dropping them would mean re-emailing people
 * who asked not to be contacted, so nothing is deleted by default.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'RRFW_REMOVE_ALL_DATA' ) || ! RRFW_REMOVE_ALL_DATA ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rrfw_suppressions" ); // phpcs:ignore

foreach ( array( 'rrfw_settings', 'rrfw_secret', 'rrfw_db_version', 'rrfw_daily_counter', 'rrfw_migrated_from_legacy' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_rrfw\_%'" ); // phpcs:ignore

$orders_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) {
	$wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key LIKE '\_rrfw\_%'" ); // phpcs:ignore
}

// Anything left from the plugin's former identity, on a site that was migrated.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lrr_suppressions" ); // phpcs:ignore

foreach ( array( 'lrr_settings', 'lrr_secret', 'lrr_db_version', 'lrr_daily_counter' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_lrr\_%'" ); // phpcs:ignore
