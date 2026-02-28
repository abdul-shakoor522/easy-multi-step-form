<?php
/**
 * Plugin uninstall script
 *
 * @package EasyMultiStepForm
 */

// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom table
$table_name = $wpdb->prefix . 'emsf_submissions';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

// Delete plugin options (if any)
delete_option( 'emsf_settings' );