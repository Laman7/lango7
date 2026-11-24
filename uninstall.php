<?php
/**
 * Uninstall: keeps translations unless the "delete" option was chosen.
 *
 * @package Lango7
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'lango7_process_queue' );

$lango7_settings = get_option( 'lango7_settings', array() );
if ( ! is_array( $lango7_settings ) || 'drop' !== ( $lango7_settings['uninstall'] ?? 'keep' ) ) {
	return;
}

do_action( 'lango7_uninstall_data' );

global $wpdb;
foreach ( array( 'strings', 'translations', 'string_urls', 'glossary', 'slugs', 'activity' ) as $lango7_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'lango7_' . $lango7_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
foreach ( array( 'lango7_settings', 'lango7_languages', 'lango7_removed_prefixes', 'lango7_switcher_seen', 'lango7_keys', 'lango7_usage', 'lango7_log', 'lango7_log_off', 'lango7_queue', 'lango7_redo', 'lango7_cache_version', 'lango7_db_version' ) as $lango7_option ) {
	delete_option( $lango7_option );
}
foreach ( array( 'administrator', 'editor' ) as $lango7_role ) {
	$lango7_role_obj = get_role( $lango7_role );
	if ( $lango7_role_obj ) {
		$lango7_role_obj->remove_cap( 'lango7_edit_translations' );
	}
}
