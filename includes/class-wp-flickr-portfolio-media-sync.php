<?php
/**
 * Media library sync support for Flickr images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Media_Sync {

	/**
	 * Manual run-now cron hook.
	 */
	const MANUAL_SCHEDULE_HOOK = 'wpfp_run_manual_scheduled_source_event';

	/**
	 * Scheduled sync cron hook.
	 */
	const CRON_HOOK = 'wpfp_run_scheduled_source_event';

	/**
	 * Lock lifetime for one scheduled sync run.
	 */
	const SCHEDULE_LOCK_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Settings manager.
	 *
	 * @var WP_Flickr_Portfolio_Settings
	 */
	private $settings;

	/**
	 * Flickr API client.
	 *
	 * @var WP_Flickr_Portfolio_API
	 */
	private $api;

	/**
	 * Shared logger.
	 *
	 * @var WP_Flickr_Portfolio_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WP_Flickr_Portfolio_Settings $settings Settings manager.
	 * @param WP_Flickr_Portfolio_API      $api API client.
	 * @param WP_Flickr_Portfolio_Logger   $logger Logger service.
	 */
	public function __construct( WP_Flickr_Portfolio_Settings $settings, WP_Flickr_Portfolio_API $api, WP_Flickr_Portfolio_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;

		add_action( 'admin_post_wpfp_save_scheduled_source', array( $this, 'handle_save_scheduled_source' ) );
		add_action( 'admin_post_wpfp_remove_scheduled_source', array( $this, 'handle_remove_scheduled_source' ) );
		add_action( 'admin_post_wpfp_toggle_scheduled_source', array( $this, 'handle_toggle_scheduled_source' ) );
		add_action( 'admin_post_wpfp_run_scheduled_source', array( $this, 'handle_run_scheduled_source' ) );
		add_action( self::MANUAL_SCHEDULE_HOOK, array( $this, 'handle_cron_scheduled_source' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron_scheduled_source' ), 10, 1 );
	}

	/**
	 * Sync all Flickr pages for a logical source.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @param array<string,mixed> $options Sync options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function sync_all_pages( array $source, array $options = array() ) {
		$first_page_source         = $source;
		$first_page_source['page'] = 1;

		$first_gallery = $this->api->get_gallery_data( $first_page_source, true );
		if ( is_wp_error( $first_gallery ) ) {
			return $first_gallery;
		}

		$summary = array(
			'pages_synced'    => 0,
			'total_pages'     => isset( $first_gallery['pages'] ) ? max( 1, absint( $first_gallery['pages'] ) ) : 1,
			'attachments'     => 0,
			'created'         => 0,
			'updated'         => 0,
			'reused'          => 0,
			'base_source_key' => $this->api->build_collection_key( isset( $first_gallery['source'] ) && is_array( $first_gallery['source'] ) ? $first_gallery['source'] : $first_page_source ),
		);

		for ( $page = 1; $page <= $summary['total_pages']; $page++ ) {
			$gallery = 1 === $page ? $first_gallery : $this->api->get_gallery_data(
				array_merge(
					isset( $first_gallery['source'] ) && is_array( $first_gallery['source'] ) ? $first_gallery['source'] : $first_page_source,
					array(
						'page' => $page,
					)
				),
				true
			);

			if ( is_wp_error( $gallery ) ) {
				return $gallery;
			}

			$page_summary = $this->sync_gallery_payload( $gallery, $options );
			if ( is_wp_error( $page_summary ) ) {
				return $page_summary;
			}

			$summary['pages_synced']++;
			$summary['attachments'] += absint( $page_summary['attachments'] ?? 0 );
			$summary['created'] += absint( $page_summary['created'] ?? 0 );
			$summary['updated'] += absint( $page_summary['updated'] ?? 0 );
			$summary['reused'] += absint( $page_summary['reused'] ?? 0 );
		}

		return $summary;
	}

	/**
	 * Return synced attachment IDs for a source.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @return array<int,int>
	 */
	public function get_synced_attachment_ids( array $source ) {
		$mapping = get_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, array() );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		$source_key = $this->api->build_source_key( $source );
		$ids        = isset( $mapping[ $source_key ] ) && is_array( $mapping[ $source_key ] ) ? $mapping[ $source_key ] : array();
		$ids        = $this->filter_existing_attachment_ids( $ids );

		if ( ! empty( $ids ) ) {
			if ( ! isset( $mapping[ $source_key ] ) || $mapping[ $source_key ] !== $ids ) {
				$mapping[ $source_key ] = $ids;
				update_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, $mapping, false );
				$this->logger->debug( 'media_sync', 'Repaired synced source mapping from existing attachment IDs.', array_merge( $this->source_log_context( $source ), array( 'attachments' => count( $ids ) ) ), 'mapping_repaired' );
			}

			return $ids;
		}

		$ids = $this->query_synced_attachment_ids( $source );
		if ( ! empty( $ids ) ) {
			$mapping[ $source_key ] = $ids;
			update_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, $mapping, false );
			$this->logger->info( 'media_sync', 'Recovered synced attachments from attachment metadata.', array_merge( $this->source_log_context( $source ), array( 'attachments' => count( $ids ) ) ), 'mapping_recovered_from_meta' );
			return $ids;
		}

		if ( isset( $mapping[ $source_key ] ) ) {
			unset( $mapping[ $source_key ] );
			update_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, $mapping, false );
		}

		return array();
	}

	/**
	 * Render a synced gallery with attachment IDs.
	 *
	 * @param array<int,int>      $ids Attachment IDs.
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @param string              $pagination_markup Optional pagination markup.
	 * @return string
	 */
	public function render_synced_gallery( array $ids, array $atts, $pagination_markup = '' ) {
		if ( empty( $ids ) ) {
			return '';
		}

		$layout   = isset( $atts['layout'] ) ? $this->settings->normalize_layout( (string) $atts['layout'] ) : 'grid';
		$captions = isset( $atts['captions'] ) ? filter_var( $atts['captions'], FILTER_VALIDATE_BOOLEAN ) : true;
		$captions = 'masonry' === $layout ? false : $captions;
		$columns  = isset( $atts['columns'] ) ? max( 1, min( 6, absint( $atts['columns'] ) ) ) : 3;
		$title    = isset( $atts['title'] ) ? trim( (string) $atts['title'] ) : '';
		$classes  = array(
			'gallery',
			'wpfp-gallery',
			'wpfp-synced-gallery',
			'wpfp-layout-' . $layout,
			'wpfp-columns-' . $columns,
		);

		if ( ! $captions ) {
			$classes[] = 'wpfp-captions-hidden';
		}

		if ( ! empty( $atts['class'] ) ) {
			$extra_classes = preg_split( '/\s+/', (string) $atts['class'] );
			if ( is_array( $extra_classes ) ) {
				foreach ( $extra_classes as $extra_class ) {
					$extra_class = sanitize_html_class( $extra_class );
					if ( $extra_class ) {
						$classes[] = $extra_class;
					}
				}
			}
		}

		wp_enqueue_style( 'wp-flickr-portfolio' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', array_values( array_unique( array_filter( $classes ) ) ) ) ); ?>" data-wpfp-source="media">
			<?php if ( $title ) : ?>
				<h3 class="wpfp-gallery-title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>
			<div class="wpfp-gallery-grid">
				<?php foreach ( $ids as $attachment_id ) : ?>
					<?php
					$attachment_id = (int) $attachment_id;
					if ( $attachment_id <= 0 ) {
						continue;
					}

					$display = wp_get_attachment_image_src( $attachment_id, 'large' );
					$full    = wp_get_attachment_image_src( $attachment_id, 'full' );
					if ( empty( $display[0] ) ) {
						continue;
					}

					$image_title  = get_the_title( $attachment_id );
					$caption      = wp_get_attachment_caption( $attachment_id );
					$description  = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $attachment_id ) ) );
					$caption_text = $caption ? $caption : $description;
					$alt_text     = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
					$full_url     = ! empty( $full[0] ) ? (string) $full[0] : (string) $display[0];
					?>
					<figure class="gallery-item wpfp-gallery-item">
						<div class="gallery-icon landscape">
							<a href="<?php echo esc_url( $full_url ); ?>" data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>" data-caption="<?php echo esc_attr( $caption_text ? $caption_text : $image_title ); ?>" data-title="<?php echo esc_attr( $image_title ); ?>">
								<img src="<?php echo esc_url( (string) $display[0] ); ?>" alt="<?php echo esc_attr( $alt_text ? $alt_text : $image_title ); ?>" loading="lazy"<?php echo ! empty( $display[1] ) ? ' width="' . esc_attr( (string) absint( $display[1] ) ) . '"' : ''; ?><?php echo ! empty( $display[2] ) ? ' height="' . esc_attr( (string) absint( $display[2] ) ) . '"' : ''; ?>>
							</a>
						</div>
						<?php if ( $captions && ( $image_title || $caption_text ) ) : ?>
							<figcaption class="wp-caption-text gallery-caption">
								<strong><?php echo esc_html( $image_title ); ?></strong>
								<?php if ( $caption_text ) : ?>
									<span><?php echo esc_html( $caption_text ); ?></span>
								<?php endif; ?>
							</figcaption>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php echo $pagination_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle saving a scheduled source.
	 *
	 * @return void
	 */
	public function handle_save_scheduled_source() {
		$this->assert_manage_options();
		check_admin_referer( 'wpfp_save_scheduled_source' );

		$source = $this->api->normalize_source_args( $this->source_from_request() );
		if ( is_wp_error( $source ) ) {
			$this->redirect_with_notice( 'schedule_error' );
		}

		$base_key = $this->api->build_collection_key( $source );
		$records  = $this->get_scheduled_sources();
		$current  = isset( $records[ $base_key ] ) && is_array( $records[ $base_key ] ) ? $records[ $base_key ] : array();

		$records[ $base_key ] = array_merge(
			$current,
			array(
				'base_key'          => $base_key,
				'source'            => (string) $source['source'],
				'user_id'           => (string) $source['user_id'],
				'photoset_id'       => (string) $source['photoset_id'],
				'per_page'          => absint( $source['per_page'] ),
				'privacy_filter'    => absint( $source['privacy_filter'] ),
				'enabled'           => true,
				'schedule'          => 'daily',
				'refresh_existing'  => ! empty( $_POST['refresh_existing'] ),
				'last_run_at'       => isset( $current['last_run_at'] ) ? absint( $current['last_run_at'] ) : 0,
				'last_message'      => isset( $current['last_message'] ) ? (string) $current['last_message'] : '',
				'last_status'       => isset( $current['last_status'] ) ? (string) $current['last_status'] : '',
				'last_pages_synced' => isset( $current['last_pages_synced'] ) ? absint( $current['last_pages_synced'] ) : 0,
				'last_total_pages'  => isset( $current['last_total_pages'] ) ? absint( $current['last_total_pages'] ) : 0,
			)
		);

		$this->save_scheduled_sources( $records );
		$this->schedule_source( $base_key, 'daily' );
		$this->logger->info( 'media_sync', 'Scheduled source saved.', array_merge( $this->source_log_context( $source ), array( 'base_key' => $base_key, 'refresh_existing' => ! empty( $_POST['refresh_existing'] ) ) ), 'schedule_saved' );

		$this->redirect_with_notice( 'schedule_saved' );
	}

	/**
	 * Handle removing a scheduled source.
	 *
	 * @return void
	 */
	public function handle_remove_scheduled_source() {
		$this->assert_manage_options();

		$base_key = isset( $_GET['base_key'] ) ? sanitize_text_field( wp_unslash( $_GET['base_key'] ) ) : '';
		check_admin_referer( 'wpfp_remove_scheduled_source_' . $base_key );

		if ( ! $base_key ) {
			$this->redirect_with_notice( 'schedule_error' );
		}

		$records = $this->get_scheduled_sources();
		unset( $records[ $base_key ] );
		$this->save_scheduled_sources( $records );
		$this->unschedule_source( $base_key );
		$this->logger->info( 'media_sync', 'Scheduled source removed.', array( 'base_key' => $base_key ), 'schedule_removed' );

		$this->redirect_with_notice( 'schedule_removed' );
	}

	/**
	 * Handle enable/disable for a scheduled source.
	 *
	 * @return void
	 */
	public function handle_toggle_scheduled_source() {
		$this->assert_manage_options();

		$base_key = isset( $_GET['base_key'] ) ? sanitize_text_field( wp_unslash( $_GET['base_key'] ) ) : '';
		$enabled  = isset( $_GET['enabled'] ) ? (bool) absint( wp_unslash( $_GET['enabled'] ) ) : false;
		check_admin_referer( 'wpfp_toggle_scheduled_source_' . $base_key );

		if ( ! $base_key ) {
			$this->redirect_with_notice( 'schedule_error' );
		}

		$records = $this->get_scheduled_sources();
		if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
			$this->redirect_with_notice( 'schedule_error' );
		}

		$records[ $base_key ]['enabled'] = $enabled;
		$this->save_scheduled_sources( $records );
		$this->logger->info( 'media_sync', $enabled ? 'Scheduled source enabled.' : 'Scheduled source disabled.', array( 'base_key' => $base_key ), $enabled ? 'schedule_enabled' : 'schedule_disabled' );

		if ( $enabled ) {
			$this->schedule_source( $base_key, isset( $records[ $base_key ]['schedule'] ) ? (string) $records[ $base_key ]['schedule'] : 'daily' );
			$this->redirect_with_notice( 'schedule_enabled' );
		}

		$this->unschedule_source( $base_key );
		$this->redirect_with_notice( 'schedule_disabled' );
	}

	/**
	 * Handle manual run-now action for a scheduled source.
	 *
	 * @return void
	 */
	public function handle_run_scheduled_source() {
		$this->assert_manage_options();

		$base_key = isset( $_GET['base_key'] ) ? sanitize_text_field( wp_unslash( $_GET['base_key'] ) ) : '';
		check_admin_referer( 'wpfp_run_scheduled_source_' . $base_key );

		$result = $this->queue_scheduled_source_run( $base_key );
		if ( is_wp_error( $result ) ) {
			$this->logger->warning( 'media_sync', $result->get_error_message(), array( 'base_key' => $base_key, 'code' => $result->get_error_code() ), 'schedule_run_queue_error' );
			if ( in_array( $result->get_error_code(), array( 'wpfp_schedule_running', 'wpfp_schedule_queued' ), true ) ) {
				$this->redirect_with_notice( 'schedule_run_queued' );
			}

			$this->redirect_with_notice( 'schedule_error' );
		}

		$this->redirect_with_notice( 'schedule_run_queued' );
	}

	/**
	 * Handle scheduled cron runs.
	 *
	 * @param string $base_key Scheduled source key.
	 * @return void
	 */
	public function handle_cron_scheduled_source( $base_key ) {
		$this->run_scheduled_source( (string) $base_key );
	}

	/**
	 * Sync a normalized gallery payload into the mapping store.
	 *
	 * @param array<string,mixed> $gallery Gallery payload.
	 * @param array<string,mixed> $options Sync options.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function sync_gallery_payload( array $gallery, array $options = array() ) {
		if ( empty( $gallery['photos'] ) || ! is_array( $gallery['photos'] ) ) {
			return new WP_Error( 'wpfp_empty_sync', __( 'No Flickr photos were returned for this source.', 'wp-flickr-portfolio' ) );
		}

		$refresh_existing = ! empty( $options['refresh_existing'] );
		$prefer_original  = ! isset( $options['prefer_original'] ) || (bool) $options['prefer_original'];
		$attachment_ids   = array();
		$created          = 0;
		$updated          = 0;
		$reused           = 0;
		$page             = isset( $gallery['page'] ) ? absint( $gallery['page'] ) : 1;
		$collection_key   = $this->api->build_collection_key( isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : array() );
		$this->logger->info(
			'media_sync',
			'Syncing normalized Flickr gallery payload into the Media Library.',
			array_merge(
				$this->source_log_context( isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : array() ),
				array(
					'photos'           => count( $gallery['photos'] ),
					'refresh_existing' => $refresh_existing,
				)
			),
			'sync_payload_start'
		);

		foreach ( $gallery['photos'] as $index => $photo ) {
			if ( ! is_array( $photo ) ) {
				continue;
			}

			$import_result = $this->sync_gallery_photo(
				isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : array(),
				$collection_key,
				isset( $gallery['source_key'] ) ? (string) $gallery['source_key'] : '',
				$page,
				$photo,
				$index,
				$refresh_existing,
				$prefer_original
			);
			if ( is_wp_error( $import_result ) ) {
				return $import_result;
			}

			$attachment_id = isset( $import_result['attachment_id'] ) ? (int) $import_result['attachment_id'] : 0;
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$attachment_ids[] = $attachment_id;

			if ( 'created' === $import_result['status'] ) {
				$created++;
			} elseif ( 'updated' === $import_result['status'] ) {
				$updated++;
			} else {
				$reused++;
			}
		}

		$source_key = isset( $gallery['source_key'] ) ? (string) $gallery['source_key'] : $this->api->build_source_key( isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : array() );
		$mapping    = get_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, array() );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		$mapping[ $source_key ] = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		update_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, $mapping, false );
		$this->logger->info(
			'media_sync',
			'Gallery payload synced into the Media Library.',
			array(
				'source_key'  => $source_key,
				'collection'  => $collection_key,
				'page'        => $page,
				'attachments' => count( $mapping[ $source_key ] ),
				'created'     => $created,
				'updated'     => $updated,
				'reused'      => $reused,
			),
			'sync_payload_complete'
		);

		return array(
			'attachments'     => count( $mapping[ $source_key ] ),
			'created'         => $created,
			'updated'         => $updated,
			'reused'          => $reused,
			'source_key'      => $source_key,
			'base_source_key' => $collection_key,
			'page'            => $page,
		);
	}

	/**
	 * Import one Flickr photo and store its page/source mapping immediately.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @param string              $collection_key Logical source key without page.
	 * @param string              $source_key Page-specific source key.
	 * @param int                 $page Source page number.
	 * @param array<string,mixed> $photo Flickr photo payload.
	 * @param int                 $index Zero-based order on the page.
	 * @param bool                $refresh_existing Whether to refresh existing attachments.
	 * @param bool                $prefer_original Whether to prefer the original/full Flickr URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function sync_gallery_photo( array $source, $collection_key, $source_key, $page, array $photo, $index, $refresh_existing, $prefer_original ) {
		$this->logger->debug(
			'media_sync',
			'Importing Flickr photo into the Media Library.',
			array(
				'photo_id'          => isset( $photo['id'] ) ? (string) $photo['id'] : '',
				'refresh_existing'  => (bool) $refresh_existing,
				'prefer_original'   => (bool) $prefer_original,
				'has_full_url'      => ! empty( $photo['full_url'] ),
				'has_display_url'   => ! empty( $photo['display_url'] ),
				'has_thumbnail_url' => ! empty( $photo['thumbnail_url'] ),
			),
			'import_start'
		);

		try {
			$import_result = $this->import_photo(
				$photo,
				$refresh_existing,
				array(
					'prefer_original' => (bool) $prefer_original,
				)
			);
		} catch ( Throwable $throwable ) {
			$this->logger->error(
				'media_sync',
				$throwable->getMessage(),
				array(
					'photo_id' => isset( $photo['id'] ) ? (string) $photo['id'] : '',
					'file'     => $throwable->getFile(),
					'line'     => $throwable->getLine(),
					'type'     => get_class( $throwable ),
				),
				'import_throwable'
			);
			return new WP_Error(
				'wpfp_import_throwable',
				sprintf(
					/* translators: %s: original PHP error message. */
					__( 'Media import failed unexpectedly: %s', 'wp-flickr-portfolio' ),
					$throwable->getMessage()
				)
			);
		}

		if ( is_wp_error( $import_result ) ) {
			$this->logger->error(
				'media_sync',
				$import_result->get_error_message(),
				array(
					'photo_id' => isset( $photo['id'] ) ? (string) $photo['id'] : '',
					'code'     => $import_result->get_error_code(),
				),
				'import_error'
			);
			return $import_result;
		}

		$attachment_id = isset( $import_result['attachment_id'] ) ? (int) $import_result['attachment_id'] : 0;
		if ( $attachment_id > 0 ) {
			$this->store_synced_attachment_reference( $source, $collection_key, $source_key, $page, $index, $attachment_id );
		}

		return $import_result;
	}

	/**
	 * Persist one attachment as part of a synced Flickr page.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @param string              $collection_key Logical source key without page.
	 * @param string              $source_key Page-specific source key.
	 * @param int                 $page Page number.
	 * @param int                 $index Photo order within the page.
	 * @param int                 $attachment_id Attachment ID.
	 * @return void
	 */
	private function store_synced_attachment_reference( array $source, $collection_key, $source_key, $page, $index, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		update_post_meta( $attachment_id, '_wpfp_source_order', (int) $index );
		update_post_meta( $attachment_id, '_wpfp_source_page', (int) $page );
		update_post_meta( $attachment_id, '_wpfp_source_collection', (string) $collection_key );

		$mapping = get_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, array() );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		$current_mapping = isset( $mapping[ $source_key ] ) && is_array( $mapping[ $source_key ] ) ? $mapping[ $source_key ] : array();
		$current_mapping[ (int) $index ] = $attachment_id;
		ksort( $current_mapping, SORT_NUMERIC );
		$mapping[ $source_key ] = array_values( array_unique( array_filter( array_map( 'intval', $current_mapping ) ) ) );
		update_option( WP_Flickr_Portfolio_Settings::SYNCED_SOURCES_OPTION, $mapping, false );

		$this->logger->debug(
			'media_sync',
			'Stored synced attachment reference for a Flickr page item.',
			array_merge(
				$this->source_log_context( $source ),
				array(
					'photo_order'   => (int) $index,
					'attachment_id' => $attachment_id,
					'attachments'   => count( $mapping[ $source_key ] ),
				)
			),
			'sync_item_stored'
		);
	}

	/**
	 * Filter a list down to existing attachment IDs while preserving order.
	 *
	 * @param array<int,mixed> $ids Candidate IDs.
	 * @return array<int,int>
	 */
	private function filter_existing_attachment_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$existing = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => count( $ids ),
				'fields'                 => 'ids',
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_map( 'intval', is_array( $existing ) ? $existing : array() ) );
	}

	/**
	 * Query synced attachments by stored source metadata.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @return array<int,int>
	 */
	private function query_synced_attachment_ids( array $source ) {
		$page = isset( $source['page'] ) ? max( 1, absint( $source['page'] ) ) : 1;

		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'meta_key'               => '_wpfp_source_order',
				'orderby'                => 'meta_value_num',
				'order'                  => 'ASC',
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => '_wpfp_source_collection',
						'value' => $this->api->build_collection_key( $source ),
					),
					array(
						'key'     => '_wpfp_source_page',
						'value'   => $page,
						'type'    => 'NUMERIC',
						'compare' => '=',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_map( 'intval', is_array( $attachments ) ? $attachments : array() ) );
	}

	/**
	 * Import a Flickr photo into the Media Library.
	 *
	 * @param array<string,mixed> $photo Flickr photo payload.
	 * @param bool                $refresh_existing Whether to refresh an existing attachment's metadata.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function import_photo( array $photo, $refresh_existing, array $options = array() ) {
		if ( empty( $photo['id'] ) ) {
			return new WP_Error( 'wpfp_missing_photo_id', __( 'Cannot import a Flickr photo without an ID.', 'wp-flickr-portfolio' ) );
		}

		$existing = $this->find_attachment_by_flickr_id( (string) $photo['id'] );
		if ( $existing ) {
			if ( $refresh_existing ) {
				$this->update_attachment_meta( $existing, $photo );
				$this->logger->info( 'media_sync', 'Refreshed existing imported Flickr attachment.', array( 'photo_id' => (string) $photo['id'], 'attachment_id' => $existing ), 'import_updated' );
				return array(
					'attachment_id' => $existing,
					'status'        => 'updated',
				);
			}

			$this->logger->debug( 'media_sync', 'Reused existing imported Flickr attachment.', array( 'photo_id' => (string) $photo['id'], 'attachment_id' => $existing ), 'import_reused' );
			return array(
				'attachment_id' => $existing,
				'status'        => 'reused',
			);
		}

		$prefer_original = ! isset( $options['prefer_original'] ) || (bool) $options['prefer_original'];
		$import_source   = $this->select_import_source( $photo, $prefer_original );
		if ( empty( $import_source['url'] ) ) {
			return new WP_Error( 'wpfp_missing_photo_url', __( 'Flickr did not provide a downloadable image URL for this photo.', 'wp-flickr-portfolio' ) );
		}

		$source_url = (string) $import_source['url'];

		$this->logger->info(
			'media_sync',
			'Downloading Flickr photo for Media Library import.',
			array(
				'photo_id'       => (string) $photo['id'],
				'import_variant' => isset( $import_source['variant'] ) ? (string) $import_source['variant'] : '',
				'url'            => $source_url,
			),
			'import_download_start'
		);

		$this->ensure_media_import_dependencies();

		$temp_file = download_url( $source_url, 60 );

		// If the primary URL failed and it was the original, fall back to display URL.
		if ( is_wp_error( $temp_file ) && 'full_url' === ( $import_source['variant'] ?? '' ) && ! empty( $photo['display_url'] ) && (string) $photo['display_url'] !== $source_url ) {
			$this->logger->warning(
				'media_sync',
				'Flickr original download failed, retrying with display URL.',
				array(
					'photo_id' => (string) $photo['id'],
					'code'     => $temp_file->get_error_code(),
					'error'    => $temp_file->get_error_message(),
				),
				'original_download_fallback'
			);
			$source_url    = (string) $photo['display_url'];
			$import_source = array( 'variant' => 'display_url', 'url' => $source_url );
			$temp_file     = download_url( $source_url, 60 );
		}

		if ( is_wp_error( $temp_file ) ) {
			$this->logger->error( 'media_sync', $temp_file->get_error_message(), array( 'photo_id' => (string) $photo['id'], 'code' => $temp_file->get_error_code() ), 'download_failed' );
			return $temp_file;
		}

		$path      = wp_parse_url( $source_url, PHP_URL_PATH );
		$basename  = $path ? basename( (string) $path ) : (string) $photo['id'] . '.jpg';
		$file_name = sanitize_file_name( (string) $photo['id'] . '-' . $basename );
		$file      = array(
			'name'     => $file_name,
			'tmp_name' => $temp_file,
		);

		$attachment_args = array(
			'post_title'   => ! empty( $photo['title'] ) ? (string) $photo['title'] : (string) $photo['id'],
			'post_content' => (string) ( $photo['description'] ?? '' ),
			'post_excerpt' => (string) ( $photo['description'] ?? '' ),
		);

		$this->logger->info(
			'media_sync',
			'Creating WordPress attachment from downloaded Flickr file.',
			array(
				'photo_id'       => (string) $photo['id'],
				'import_variant' => isset( $import_source['variant'] ) ? (string) $import_source['variant'] : '',
				'file_name'      => $file_name,
			),
			'import_sideload_start'
		);

		$disable_sizes = static function() {
			return array();
		};
		$disable_big_image = static function() {
			return false;
		};

		add_filter( 'intermediate_image_sizes_advanced', $disable_sizes, 9999 );
		add_filter( 'big_image_size_threshold', $disable_big_image, 9999 );

		try {
			$attachment_id = media_handle_sideload(
				$file,
				0,
				(string) ( $photo['description'] ?? '' ),
				$attachment_args
			);
		} finally {
			remove_filter( 'intermediate_image_sizes_advanced', $disable_sizes, 9999 );
			remove_filter( 'big_image_size_threshold', $disable_big_image, 9999 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			$this->logger->error( 'media_sync', $attachment_id->get_error_message(), array( 'photo_id' => (string) $photo['id'], 'code' => $attachment_id->get_error_code() ), 'sideload_failed' );
			return $attachment_id;
		}

		$this->update_attachment_meta( (int) $attachment_id, $photo );
		update_post_meta( (int) $attachment_id, '_wpfp_flickr_import_variant', isset( $import_source['variant'] ) ? (string) $import_source['variant'] : '' );
		$this->logger->info( 'media_sync', 'Created new Media Library attachment from Flickr photo.', array( 'photo_id' => (string) $photo['id'], 'attachment_id' => (int) $attachment_id ), 'import_created' );

		return array(
			'attachment_id' => (int) $attachment_id,
			'status'        => 'created',
		);
	}

	/**
	 * Load the WordPress media-import dependencies used outside wp-admin.
	 *
	 * @return void
	 */
	private function ensure_media_import_dependencies() {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}


	/**
	 * Pick the safest Flickr image URL variant for WordPress import work.
	 *
	 * Prefers the original Flickr file but falls back to display or thumbnail URL when unavailable.
	 *
	 * @param array<string,mixed> $photo Flickr photo payload.
	 * @return array<string,string>
	 */
	private function select_import_source( array $photo, $prefer_original = true ) {
		$candidates = $prefer_original
			? array( 'full_url', 'display_url', 'thumbnail_url' )
			: array( 'display_url', 'full_url', 'thumbnail_url' );

		foreach ( $candidates as $field ) {
			if ( ! empty( $photo[ $field ] ) ) {
				return array(
					'variant' => (string) $field,
					'url'     => (string) $photo[ $field ],
				);
			}
		}

		return array(
			'variant' => '',
			'url'     => '',
		);
	}

	/**
	 * Find an existing attachment for a Flickr photo.
	 *
	 * @param string $photo_id Flickr photo ID.
	 * @return int
	 */
	private function find_attachment_by_flickr_id( $photo_id ) {
		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_wpfp_flickr_photo_id',
				'meta_value'             => $photo_id,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return empty( $attachments ) ? 0 : (int) $attachments[0];
	}

	/**
	 * Update attachment metadata.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $photo Photo payload.
	 * @return void
	 */
	private function update_attachment_meta( $attachment_id, array $photo ) {
		update_post_meta( $attachment_id, '_wpfp_flickr_photo_id', (string) $photo['id'] );
		update_post_meta( $attachment_id, '_wpfp_flickr_owner', (string) ( $photo['owner'] ?? '' ) );
		update_post_meta( $attachment_id, '_wpfp_flickr_owner_name', (string) ( $photo['owner_name'] ?? '' ) );
		update_post_meta( $attachment_id, '_wpfp_flickr_full_url', (string) ( $photo['full_url'] ?? '' ) );
		update_post_meta( $attachment_id, '_wpfp_flickr_tags', (string) ( $photo['tags'] ?? '' ) );
		update_post_meta( $attachment_id, '_wpfp_flickr_taken_at', (string) ( $photo['date_taken'] ?? '' ) );

		if ( ! empty( $photo['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => sanitize_text_field( (string) $photo['title'] ),
				)
			);
		}

		if ( isset( $photo['description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_content' => wp_kses_post( (string) $photo['description'] ),
					'post_excerpt' => wp_strip_all_tags( (string) $photo['description'] ),
				)
			);
		}

		if ( ! empty( $photo['title'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $photo['title'] ) );
		}
	}

	/**
	 * Parse a source record from a request.
	 *
	 * @return array<string,mixed>
	 */
	private function source_from_request() {
		return array(
			'source'         => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'photoset',
			'user_id'        => isset( $_POST['user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : '',
			'photoset_id'    => isset( $_POST['photoset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['photoset_id'] ) ) : '',
			'per_page'       => isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 24,
			'page'           => 1,
			'privacy_filter' => isset( $_POST['privacy_filter'] ) ? absint( wp_unslash( $_POST['privacy_filter'] ) ) : 1,
		);
	}

	/**
	 * Run one scheduled source.
	 *
	 * @param string $base_key Source key.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_scheduled_source( $base_key ) {
		if ( ! $base_key ) {
			return new WP_Error( 'wpfp_missing_schedule_key', __( 'Missing scheduled source key.', 'wp-flickr-portfolio' ) );
		}

		$lock_key = $this->scheduled_source_lock_key( $base_key );
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'wpfp_schedule_running', __( 'This scheduled source is already running.', 'wp-flickr-portfolio' ) );
		}

		set_transient( $lock_key, time(), self::SCHEDULE_LOCK_TTL );

		$records = $this->get_scheduled_sources();
		try {
			if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
				return new WP_Error( 'wpfp_missing_schedule_source', __( 'Scheduled source not found.', 'wp-flickr-portfolio' ) );
			}

			$records[ $base_key ]['last_status']  = 'running';
			$records[ $base_key ]['last_message'] = __( 'Running background sync.', 'wp-flickr-portfolio' );
			$this->save_scheduled_sources( $records );
			$this->logger->info( 'media_sync', 'Scheduled source background sync started.', array( 'base_key' => $base_key ), 'schedule_running' );

			$record = $records[ $base_key ];
			$source = array(
				'source'         => isset( $record['source'] ) ? (string) $record['source'] : 'photoset',
				'user_id'        => isset( $record['user_id'] ) ? (string) $record['user_id'] : '',
				'photoset_id'    => isset( $record['photoset_id'] ) ? (string) $record['photoset_id'] : '',
				'per_page'       => isset( $record['per_page'] ) ? absint( $record['per_page'] ) : 24,
				'page'           => 1,
				'privacy_filter' => isset( $record['privacy_filter'] ) ? absint( $record['privacy_filter'] ) : 1,
			);

			$result = $this->sync_all_pages(
				$source,
				array(
					'refresh_existing' => ! empty( $record['refresh_existing'] ),
				)
			);

			if ( is_wp_error( $result ) ) {
				$records[ $base_key ]['last_run_at'] = time();
				$records[ $base_key ]['last_status'] = 'error';
				$records[ $base_key ]['last_message'] = $result->get_error_message();
				$this->save_scheduled_sources( $records );
				$this->logger->error( 'media_sync', $result->get_error_message(), array( 'base_key' => $base_key, 'code' => $result->get_error_code() ), 'schedule_failed' );
				return $result;
			}

			$records[ $base_key ]['last_run_at'] = time();
			$records[ $base_key ]['last_status'] = 'success';
			$records[ $base_key ]['last_pages_synced'] = absint( $result['pages_synced'] ?? 0 );
			$records[ $base_key ]['last_total_pages'] = absint( $result['total_pages'] ?? 0 );
			$records[ $base_key ]['last_message'] = sprintf(
				/* translators: 1: synced pages, 2: total pages. */
				__( 'Synced %1$d of %2$d pages.', 'wp-flickr-portfolio' ),
				absint( $result['pages_synced'] ?? 0 ),
				absint( $result['total_pages'] ?? 0 )
			);
			$this->save_scheduled_sources( $records );
			$this->logger->info( 'media_sync', 'Scheduled source background sync completed.', array( 'base_key' => $base_key, 'pages_synced' => absint( $result['pages_synced'] ?? 0 ), 'total_pages' => absint( $result['total_pages'] ?? 0 ) ), 'schedule_complete' );

			return $result;
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Queue a background run-now execution for one scheduled source.
	 *
	 * @param string $base_key Source key.
	 * @return bool|\WP_Error
	 */
	private function queue_scheduled_source_run( $base_key ) {
		if ( ! $base_key ) {
			return new WP_Error( 'wpfp_missing_schedule_key', __( 'Missing scheduled source key.', 'wp-flickr-portfolio' ) );
		}

		$records = $this->get_scheduled_sources();
		if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
			return new WP_Error( 'wpfp_missing_schedule_source', __( 'Scheduled source not found.', 'wp-flickr-portfolio' ) );
		}

		if ( get_transient( $this->scheduled_source_lock_key( $base_key ) ) ) {
			return new WP_Error( 'wpfp_schedule_running', __( 'This scheduled source is already running.', 'wp-flickr-portfolio' ) );
		}

		if ( wp_next_scheduled( self::MANUAL_SCHEDULE_HOOK, array( $base_key ) ) ) {
			return new WP_Error( 'wpfp_schedule_queued', __( 'This scheduled source is already queued to run.', 'wp-flickr-portfolio' ) );
		}

		$records[ $base_key ]['last_status']  = 'queued';
		$records[ $base_key ]['last_message'] = __( 'Queued to run in the background.', 'wp-flickr-portfolio' );
		$this->save_scheduled_sources( $records );

		wp_schedule_single_event( time(), self::MANUAL_SCHEDULE_HOOK, array( $base_key ) );
		$this->maybe_spawn_cron();
		$this->logger->info( 'media_sync', 'Queued scheduled source to run in the background.', array( 'base_key' => $base_key ), 'schedule_queued' );

		return true;
	}

	/**
	 * Build a transient key used to lock a scheduled source while it runs.
	 *
	 * @param string $base_key Source key.
	 * @return string
	 */
	private function scheduled_source_lock_key( $base_key ) {
		return 'wpfp_schedule_lock_' . md5( (string) $base_key );
	}

	/**
	 * Trigger WP-Cron as soon as possible for queued background jobs.
	 *
	 * @return void
	 */
	private function maybe_spawn_cron() {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
	}

	/**
	 * Build compact source context for logging.
	 *
	 * @param array<string,mixed> $source Source arguments.
	 * @return array<string,mixed>
	 */
	private function source_log_context( array $source ) {
		return array(
			'source'         => isset( $source['source'] ) ? (string) $source['source'] : '',
			'user_id'        => isset( $source['user_id'] ) ? (string) $source['user_id'] : '',
			'photoset_id'    => isset( $source['photoset_id'] ) ? (string) $source['photoset_id'] : '',
			'page'           => isset( $source['page'] ) ? absint( $source['page'] ) : 1,
			'per_page'       => isset( $source['per_page'] ) ? absint( $source['per_page'] ) : 0,
			'privacy_filter' => isset( $source['privacy_filter'] ) ? absint( $source['privacy_filter'] ) : 1,
			'source_key'     => $this->api->build_source_key( $source ),
			'collection_key' => $this->api->build_collection_key( $source ),
		);
	}

	/**
	 * Return the scheduled source registry keyed by base source key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_scheduled_sources() {
		$records = get_option( WP_Flickr_Portfolio_Settings::SCHEDULED_SOURCES_OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $records as $base_key => $record ) {
			if ( is_string( $base_key ) && is_array( $record ) ) {
				$sanitized[ $base_key ] = $record;
			}
		}

		return $sanitized;
	}

	/**
	 * Persist scheduled source registry.
	 *
	 * @param array<string,array<string,mixed>> $records Scheduled records.
	 * @return void
	 */
	private function save_scheduled_sources( array $records ) {
		update_option( WP_Flickr_Portfolio_Settings::SCHEDULED_SOURCES_OPTION, $records, false );
	}

	/**
	 * Schedule a WP-Cron event for a source.
	 *
	 * @param string $base_key Source key.
	 * @param string $schedule Cron schedule.
	 * @return void
	 */
	private function schedule_source( $base_key, $schedule ) {
		$this->unschedule_source( $base_key );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $schedule ? $schedule : 'daily', self::CRON_HOOK, array( $base_key ) );
	}

	/**
	 * Remove scheduled WP-Cron events for a source.
	 *
	 * @param string $base_key Source key.
	 * @return void
	 */
	private function unschedule_source( $base_key ) {
		foreach ( array( self::CRON_HOOK, self::MANUAL_SCHEDULE_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook, array( $base_key ) );

			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook, array( $base_key ) );
				$timestamp = wp_next_scheduled( $hook, array( $base_key ) );
			}
		}
	}

	/**
	 * Ensure the current user can manage plugin settings.
	 *
	 * @return void
	 */
	private function assert_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}
	}

	/**
	 * Redirect back to the admin page with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			$this->settings->admin_page_url(
				array(
					'wpfp_notice' => $notice,
				)
			)
		);
		exit;
	}
}
