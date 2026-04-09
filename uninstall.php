<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Cleans up all plugin-owned data: options, postmeta, and cron events.
 * Imported attachment posts are intentionally kept (user's media library content).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
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

// Delete known transients.
delete_transient( 'wpfp_flickr_request_pace' );

// Remove Flickr-specific postmeta from all attachments, processed in batches
// to avoid memory issues on large media libraries.
$meta_keys = array(
	'_wpfp_flickr_photo_id',
	'_wpfp_flickr_owner',
	'_wpfp_flickr_owner_name',
	'_wpfp_flickr_full_url',
	'_wpfp_flickr_tags',
	'_wpfp_flickr_taken_at',
	'_wpfp_flickr_import_variant',
	'_wpfp_source_collection',
	'_wpfp_source_page',
	'_wpfp_source_order',
);

$offset = 0;
$batch  = 100;

do {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_wpfp_source_collection',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	foreach ( $ids as $id ) {
		foreach ( $meta_keys as $key ) {
			delete_post_meta( $id, $key );
		}
	}

	$offset += $batch;
} while ( count( $ids ) === $batch );

// Unschedule all cron events (safety net if deactivation hook was not triggered).
wp_clear_scheduled_hook( 'wpfp_run_lazy_source_event' );
wp_clear_scheduled_hook( 'wpfp_run_manual_scheduled_source_event' );
wp_clear_scheduled_hook( 'wpfp_run_scheduled_source_event' );
wp_clear_scheduled_hook( 'wpfp_upgrade_flickr_attachment_original_event' );
