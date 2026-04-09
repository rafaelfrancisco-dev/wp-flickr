<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Cleans up all plugin-owned data: options and transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'wpfp_settings',
	'wpfp_auth',
	'wpfp_request_token',
	'wpfp_cache_keys',
	'wpfp_logs',
	'wpfp_synced_sources',
	'wpfp_scheduled_sources',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'wpfp_flickr_request_pace' );
