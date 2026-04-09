<?php
/**
 * Media library sync support for Flickr images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Media_Sync {

	/**
	 * Timeout for downloading large original Flickr files.
	 */
	const IMPORT_DOWNLOAD_TIMEOUT = 300;

	/**
	 * Maximum redirects to follow when downloading Flickr originals.
	 */
	const IMPORT_DOWNLOAD_REDIRECTS = 5;

	/**
	 * Number of photos to process in one background worker batch.
	 */
	const SCHEDULE_BATCH_SIZE = 3;

	/**
	 * Maximum number of deferred retries after Flickr rate limits an original download.
	 */
	const MAX_RATE_LIMIT_RESCHEDULES = 6;

	/**
	 * Background worker cron hook for queued scheduled-source batches.
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
		add_action( self::MANUAL_SCHEDULE_HOOK, array( $this, 'handle_scheduled_source_worker' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron_scheduled_source' ), 10, 1 );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
	}

	/**
	 * Return the list of valid cron schedule keys.
	 *
	 * @return string[]
	 */
	public static function get_valid_schedules() {
		return array( 'hourly', 'twicedaily', 'daily', 'every_6_hours', 'every_12_hours', 'weekly', 'biweekly', 'monthly' );
	}

	/**
	 * Return a human-readable label for a schedule key.
	 *
	 * @param string $schedule Schedule key.
	 * @return string
	 */
	public static function get_schedule_label( $schedule ) {
		$labels = array(
			'hourly'        => __( 'Hourly', 'wp-flickr-portfolio' ),
			'twicedaily'    => __( 'Twice Daily', 'wp-flickr-portfolio' ),
			'daily'         => __( 'Daily', 'wp-flickr-portfolio' ),
			'every_6_hours' => __( 'Every 6 Hours', 'wp-flickr-portfolio' ),
			'every_12_hours' => __( 'Every 12 Hours', 'wp-flickr-portfolio' ),
			'weekly'        => __( 'Weekly', 'wp-flickr-portfolio' ),
			'biweekly'      => __( 'Biweekly', 'wp-flickr-portfolio' ),
			'monthly'       => __( 'Monthly', 'wp-flickr-portfolio' ),
		);

		return isset( $labels[ $schedule ] ) ? $labels[ $schedule ] : $schedule;
	}

	/**
	 * Register custom cron schedules.
	 *
	 * @param array<string,mixed> $schedules Existing schedules.
	 * @return array<string,mixed>
	 */
	public static function register_cron_schedules( $schedules ) {
		$schedules['every_6_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours', 'wp-flickr-portfolio' ),
		);
		$schedules['every_12_hours'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours', 'wp-flickr-portfolio' ),
		);
		$schedules['weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Weekly', 'wp-flickr-portfolio' ),
		);
		$schedules['biweekly'] = array(
			'interval' => 15 * DAY_IN_SECONDS,
			'display'  => __( 'Biweekly', 'wp-flickr-portfolio' ),
		);
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly', 'wp-flickr-portfolio' ),
		);

		return $schedules;
	}

	/**
	 * Sanitize and validate a posted schedule value.
	 *
	 * @return string
	 */
	private function sanitize_schedule() {
		$raw = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : '';
		return in_array( $raw, self::get_valid_schedules(), true ) ? $raw : 'daily';
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
			$this->logger->error( 'media_sync', $source->get_error_message(), array( 'code' => $source->get_error_code() ), 'schedule_save_failed' );
			$this->redirect_with_notice( 'schedule_error', $source->get_error_message() );
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
				'schedule'          => $this->sanitize_schedule(),
				'refresh_existing'  => ! empty( $_POST['refresh_existing'] ),
				'last_run_at'       => isset( $current['last_run_at'] ) ? absint( $current['last_run_at'] ) : 0,
				'last_message'      => isset( $current['last_message'] ) ? (string) $current['last_message'] : '',
				'last_status'       => isset( $current['last_status'] ) ? (string) $current['last_status'] : '',
				'last_pages_synced' => isset( $current['last_pages_synced'] ) ? absint( $current['last_pages_synced'] ) : 0,
				'last_total_pages'  => isset( $current['last_total_pages'] ) ? absint( $current['last_total_pages'] ) : 0,
			)
		);

		$this->save_scheduled_sources( $records );
		$schedule = $this->sanitize_schedule();
		$this->schedule_source( $base_key, $schedule );
		$this->logger->info( 'media_sync', 'Scheduled source saved.', array_merge( $this->source_log_context( $source ), array( 'base_key' => $base_key, 'refresh_existing' => ! empty( $_POST['refresh_existing'] ), 'schedule' => $schedule ) ), 'schedule_saved' );

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
			$this->logger->error( 'media_sync', 'Missing scheduled source key for removal.', array(), 'schedule_remove_failed' );
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
			$this->logger->error( 'media_sync', 'Missing scheduled source key for toggle.', array(), 'schedule_toggle_failed' );
			$this->redirect_with_notice( 'schedule_error' );
		}

		$records = $this->get_scheduled_sources();
		if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
			$this->logger->error( 'media_sync', 'Scheduled source not found for toggle.', array( 'base_key' => $base_key ), 'schedule_toggle_failed' );
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

		$result = $this->queue_scheduled_source_run( $base_key, 'manual' );
		if ( is_wp_error( $result ) ) {
			$this->logger->warning( 'media_sync', $result->get_error_message(), array( 'base_key' => $base_key, 'code' => $result->get_error_code() ), 'schedule_run_error' );
			$this->redirect_with_notice( 'schedule_error', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'schedule_run_queued' );
	}

	/**
	 * Handle recurring scheduled cron runs by queueing a background worker.
	 *
	 * @param string $base_key Scheduled source key.
	 * @return void
	 */
	public function handle_cron_scheduled_source( $base_key ) {
		$result = $this->queue_scheduled_source_run( (string) $base_key, 'schedule' );
		if ( is_wp_error( $result ) && 'wpfp_schedule_running' !== $result->get_error_code() ) {
			$this->logger->error( 'media_sync', $result->get_error_message(), array( 'base_key' => (string) $base_key, 'code' => $result->get_error_code() ), 'schedule_queue_failed' );
		}
	}

	/**
	 * Handle one queued background batch for a scheduled source.
	 *
	 * @param string $base_key Scheduled source key.
	 * @return void
	 */
	public function handle_scheduled_source_worker( $base_key ) {
		$result = $this->process_scheduled_source_batch( (string) $base_key );
		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'media_sync', $result->get_error_message(), array( 'base_key' => (string) $base_key, 'code' => $result->get_error_code() ), 'schedule_worker_failed' );
		}
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

		$prefer_original        = ! isset( $options['prefer_original'] ) || (bool) $options['prefer_original'];
		$allow_resized_fallback = array_key_exists( 'allow_resized_fallback', $options )
			? (bool) $options['allow_resized_fallback']
			: (bool) $this->settings->get( 'allow_resized_fallback', false );
		$import_source          = $this->select_import_source( $photo, $prefer_original, $allow_resized_fallback );
		if ( empty( $import_source['url'] ) ) {
			if ( $prefer_original && ! $allow_resized_fallback ) {
				return new WP_Error( 'wpfp_missing_original_photo_url', __( 'Flickr did not provide an original image URL for this photo.', 'wp-flickr-portfolio' ) );
			}

			return new WP_Error( 'wpfp_missing_photo_url', __( 'Flickr did not provide a downloadable image URL for this photo.', 'wp-flickr-portfolio' ) );
		}

		$this->ensure_media_import_dependencies();
		$download = $this->download_import_source( $photo, $import_source );

		if ( is_wp_error( $download ) && $allow_resized_fallback && 'full_url' === ( $import_source['variant'] ?? '' ) && ! empty( $photo['display_url'] ) && (string) $photo['display_url'] !== (string) $import_source['url'] ) {
			$this->logger->warning(
				'media_sync',
				'Flickr original download failed, retrying with display URL.',
				array(
					'photo_id' => (string) $photo['id'],
					'code'     => $download->get_error_code(),
					'error'    => $download->get_error_message(),
				),
				'original_download_fallback'
			);

			$import_source = array(
				'variant' => 'display_url',
				'url'     => (string) $photo['display_url'],
			);
			$download      = $this->download_import_source( $photo, $import_source );
		}

		if ( is_wp_error( $download ) ) {
			if ( 'full_url' === ( $import_source['variant'] ?? '' ) && ! $allow_resized_fallback ) {
				$this->logger->error(
					'media_sync',
					'Flickr original download failed and resized fallback is disabled.',
					array(
						'photo_id' => (string) $photo['id'],
						'code'     => $download->get_error_code(),
						'error'    => $download->get_error_message(),
					),
					'original_download_required'
				);
			}

			$this->logger->error(
				'media_sync',
				$download->get_error_message(),
				array(
					'photo_id' => (string) $photo['id'],
					'code'     => $download->get_error_code(),
				),
				'import_failed'
			);

			return new WP_Error(
				$download->get_error_code(),
				sprintf(
					/* translators: 1: Flickr photo ID, 2: download failure detail. */
					__( 'Photo %1$s failed: %2$s', 'wp-flickr-portfolio' ),
					(string) $photo['id'],
					$download->get_error_message()
				)
			);
		}

		$file_name = isset( $download['file_name'] ) ? (string) $download['file_name'] : $this->build_import_file_name( (string) $photo['id'], (string) $import_source['url'] );
		$file      = array(
			'name'     => $file_name,
			'tmp_name' => (string) $download['tmp_name'],
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
				'bytes'          => isset( $download['bytes_written'] ) ? absint( $download['bytes_written'] ) : 0,
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
		$this->prepare_long_running_runtime( self::IMPORT_DOWNLOAD_TIMEOUT + 60 );

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
			$this->cleanup_import_temp_file( (string) $download['tmp_name'] );
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
		if ( ! function_exists( 'wp_tempnam' ) ) {
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
	 * Download one Flickr image variant to a validated temporary file.
	 *
	 * @param array<string,mixed>  $photo Flickr photo payload.
	 * @param array<string,string> $import_source Selected import source.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function download_import_source( array $photo, array $import_source ) {
		$photo_id       = (string) $photo['id'];
		$requested_url  = (string) ( $import_source['url'] ?? '' );
		$import_variant = (string) ( $import_source['variant'] ?? '' );
		$file_name      = $this->build_import_file_name( $photo_id, $requested_url );
		$temp_file      = wp_tempnam( $file_name );

		if ( ! $temp_file || ! is_string( $temp_file ) ) {
			return new WP_Error( 'wpfp_import_temp_file_failed', __( 'Could not create a temporary file for the Flickr import.', 'wp-flickr-portfolio' ) );
		}

		$this->logger->info(
			'media_sync',
			'Downloading Flickr photo for Media Library import.',
			array(
				'photo_id'       => $photo_id,
				'import_variant' => $import_variant,
				'url'            => $requested_url,
				'timeout'        => self::IMPORT_DOWNLOAD_TIMEOUT,
			),
			'import_download_start'
		);

		$this->prepare_long_running_runtime( self::IMPORT_DOWNLOAD_TIMEOUT + 60 );
		$this->api->pace_flickr_request();
		$result = $this->download_with_wordpress_http( $requested_url, $temp_file );
		if ( is_wp_error( $result ) && function_exists( 'curl_init' ) ) {
			$this->cleanup_import_temp_file( $temp_file );
			$this->logger->warning(
				'media_sync',
				'WordPress HTTP download failed, retrying with cURL.',
				array(
					'photo_id'       => $photo_id,
					'import_variant' => $import_variant,
					'url'            => $requested_url,
					'code'           => $result->get_error_code(),
					'error'          => $result->get_error_message(),
				),
				'import_transport_retry'
			);
			$this->api->pace_flickr_request();
			$result = $this->download_with_curl( $requested_url, $temp_file );
		}

		if ( is_wp_error( $result ) ) {
			$this->cleanup_import_temp_file( $temp_file );
			return $result;
		}

		$result['tmp_name']      = $temp_file;
		$result['file_name']     = $file_name;
		$result['photo_id']      = $photo_id;
		$result['requested_url'] = $requested_url;
		$result['variant']       = $import_variant;

		$this->logger->info(
			'media_sync',
			'Received Flickr import HTTP response.',
			$this->download_log_context( $result ),
			'import_http_response'
		);

		if ( $this->is_rate_limited_download( $result ) ) {
			$retry_delay = $this->download_rate_limit_retry_delay( $result );
			$this->logger->warning(
				'media_sync',
				'Flickr original download hit a rate limit.',
				array_merge(
					$this->download_log_context( $result ),
					array(
						'retry_after' => $retry_delay,
					)
				),
				'import_rate_limited'
			);
			$this->cleanup_import_temp_file( $temp_file );

			return new WP_Error(
				'wpfp_original_transport_rate_limited',
				sprintf(
					/* translators: %d: seconds until retry. */
					__( 'Flickr rate limit reached while downloading the original file. Retrying in about %d seconds.', 'wp-flickr-portfolio' ),
					$retry_delay
				),
				array(
					'retry_after' => $retry_delay,
					'photo_id'    => $photo_id,
				)
			);
		}

		$validation = $this->validate_import_download( $result );
		if ( is_wp_error( $validation ) ) {
			$this->logger->error(
				'media_sync',
				$validation->get_error_message(),
				array_merge(
					$this->download_log_context( $result ),
					array(
						'code' => $validation->get_error_code(),
					)
				),
				'import_validation_failed'
			);
			$this->cleanup_import_temp_file( $temp_file );
			return $validation;
		}

		$this->logger->info(
			'media_sync',
			'Downloaded Flickr photo for Media Library import.',
			$this->download_log_context( $result ),
			'import_download_complete'
		);

		return $result;
	}

	/**
	 * Download a Flickr file using the WordPress HTTP API with explicit streaming.
	 *
	 * @param string $url Flickr URL.
	 * @param string $temp_file Temporary destination path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function download_with_wordpress_http( $url, $temp_file ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::IMPORT_DOWNLOAD_TIMEOUT,
				'redirection' => self::IMPORT_DOWNLOAD_REDIRECTS,
				'stream'      => true,
				'filename'    => $temp_file,
				'decompress'  => false,
				'user-agent'  => $this->import_user_agent(),
				'headers'     => $this->import_request_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpfp_original_transport_request_failed',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Flickr original download request failed: %s', 'wp-flickr-portfolio' ),
					$response->get_error_message()
				)
			);
		}

		$result = array(
			'transport'       => 'wordpress_http',
			'response_code'   => absint( wp_remote_retrieve_response_code( $response ) ),
			'content_type'    => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'content_length'  => absint( wp_remote_retrieve_header( $response, 'content-length' ) ),
			'retry_after'     => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
			'final_url'       => $this->extract_final_response_url( $response, $url ),
			'bytes_written'   => file_exists( $temp_file ) ? (int) @filesize( $temp_file ) : 0,
		);

		return $result;
	}

	/**
	 * Retry a Flickr file download with cURL when the default HTTP stack fails.
	 *
	 * @param string $url Flickr URL.
	 * @param string $temp_file Temporary destination path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function download_with_curl( $url, $temp_file ) {
		$handle = fopen( $temp_file, 'w+b' );
		if ( false === $handle ) {
			return new WP_Error( 'wpfp_original_transport_temp_open_failed', __( 'Could not open the temporary Flickr import file for writing.', 'wp-flickr-portfolio' ) );
		}

		$headers = array();
		foreach ( $this->import_request_headers() as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

		$curl = curl_init( $url );
		if ( false === $curl ) {
			fclose( $handle );
			return new WP_Error( 'wpfp_original_transport_curl_init_failed', __( 'Could not initialize the cURL downloader for the Flickr import.', 'wp-flickr-portfolio' ) );
		}

		$response_headers = array();
		$final_url        = $url;
		$content_type     = '';
		$content_length   = 0;

		curl_setopt( $curl, CURLOPT_FILE, $handle );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $curl, CURLOPT_MAXREDIRS, self::IMPORT_DOWNLOAD_REDIRECTS );
		curl_setopt( $curl, CURLOPT_TIMEOUT, self::IMPORT_DOWNLOAD_TIMEOUT );
		curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, 30 );
		curl_setopt( $curl, CURLOPT_FAILONERROR, false );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $curl, CURLOPT_USERAGENT, $this->import_user_agent() );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt(
			$curl,
			CURLOPT_HEADERFUNCTION,
			static function( $curl_handle, $header_line ) use ( &$response_headers ) {
				$length = strlen( $header_line );
				$header = explode( ':', $header_line, 2 );
				if ( 2 === count( $header ) ) {
					$response_headers[ strtolower( trim( $header[0] ) ) ] = trim( $header[1] );
				}

				return $length;
			}
		);

		$executed = curl_exec( $curl );
		$error_no = curl_errno( $curl );
		$error    = curl_error( $curl );
		$code     = absint( curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) );
		$final    = curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL );
		$type     = curl_getinfo( $curl, CURLINFO_CONTENT_TYPE );

		if ( is_string( $final ) && $final ) {
			$final_url = $final;
		}

		if ( is_string( $type ) && $type ) {
			$content_type = $type;
		} elseif ( ! empty( $response_headers['content-type'] ) ) {
			$content_type = (string) $response_headers['content-type'];
		}

		if ( ! empty( $response_headers['content-length'] ) ) {
			$content_length = absint( $response_headers['content-length'] );
		}

		curl_close( $curl );
		fflush( $handle );
		fclose( $handle );

		$result = array(
			'transport'      => 'curl',
			'response_code'  => $code,
			'content_type'   => $content_type,
			'content_length' => $content_length,
			'retry_after'    => isset( $response_headers['retry-after'] ) ? (string) $response_headers['retry-after'] : '',
			'final_url'      => $final_url,
			'bytes_written'  => file_exists( $temp_file ) ? (int) @filesize( $temp_file ) : 0,
		);

		if ( false === $executed || $error_no ) {
			return new WP_Error(
				'wpfp_original_transport_request_failed',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Flickr original download request failed: %s', 'wp-flickr-portfolio' ),
					$error ? $error : __( 'Unknown cURL error.', 'wp-flickr-portfolio' )
				)
			);
		}

		return $result;
	}

	/**
	 * Validate a completed import download before sideloading it into WordPress.
	 *
	 * @param array<string,mixed> $download Download result.
	 * @return true|\WP_Error
	 */
	private function validate_import_download( array $download ) {
		$temp_file = isset( $download['tmp_name'] ) ? (string) $download['tmp_name'] : '';

		if ( ! $temp_file || ! file_exists( $temp_file ) ) {
			return new WP_Error( 'wpfp_original_transport_missing_file', __( 'Flickr original download did not produce a local file.', 'wp-flickr-portfolio' ) );
		}

		$response_code = isset( $download['response_code'] ) ? absint( $download['response_code'] ) : 0;
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wpfp_original_transport_http_status',
				sprintf(
					/* translators: %d: HTTP response code. */
					__( 'Flickr original download returned HTTP %d.', 'wp-flickr-portfolio' ),
					$response_code
				)
			);
		}

		$bytes_written = isset( $download['bytes_written'] ) ? absint( $download['bytes_written'] ) : 0;
		if ( $bytes_written <= 0 ) {
			return new WP_Error( 'wpfp_original_transport_empty_file', __( 'Flickr original download produced an empty file.', 'wp-flickr-portfolio' ) );
		}

		$content_length = isset( $download['content_length'] ) ? absint( $download['content_length'] ) : 0;
		if ( $content_length > 0 && $bytes_written < $content_length ) {
			return new WP_Error(
				'wpfp_original_transport_truncated',
				sprintf(
					/* translators: 1: written bytes, 2: expected bytes. */
					__( 'Flickr original download appears truncated (%1$d of %2$d bytes).', 'wp-flickr-portfolio' ),
					$bytes_written,
					$content_length
				)
			);
		}

		$content_type = strtolower( (string) ( $download['content_type'] ?? '' ) );
		if ( $content_type && 0 !== strpos( $content_type, 'image/' ) && false === strpos( $content_type, 'application/octet-stream' ) ) {
			return new WP_Error(
				'wpfp_original_transport_invalid_type',
				sprintf(
					/* translators: %s: response content type. */
					__( 'Flickr original download returned an unexpected content type: %s', 'wp-flickr-portfolio' ),
					$content_type
				)
			);
		}

		if ( function_exists( 'wp_get_image_mime' ) ) {
			$image_mime = wp_get_image_mime( $temp_file );
			if ( ! $image_mime || 0 !== strpos( (string) $image_mime, 'image/' ) ) {
				return new WP_Error( 'wpfp_original_transport_invalid_image', __( 'Flickr original download did not produce a valid image file.', 'wp-flickr-portfolio' ) );
			}
		}

		return true;
	}

	/**
	 * Build a normalized file name for a Flickr import.
	 *
	 * @param string $photo_id Flickr photo ID.
	 * @param string $source_url Download URL.
	 * @return string
	 */
	private function build_import_file_name( $photo_id, $source_url ) {
		$path     = wp_parse_url( $source_url, PHP_URL_PATH );
		$basename = $path ? basename( (string) $path ) : $photo_id . '.jpg';

		return sanitize_file_name( $photo_id . '-' . $basename );
	}

	/**
	 * Build consistent headers for Flickr import requests.
	 *
	 * @return array<string,string>
	 */
	private function import_request_headers() {
		return array(
			'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
		);
	}

	/**
	 * Build a deterministic user agent for Flickr import requests.
	 *
	 * @return string
	 */
	private function import_user_agent() {
		return sprintf(
			'WP Flickr Portfolio/%1$s; WordPress/%2$s; %3$s',
			defined( 'WPFLICKRPORTFOLIO_VERSION' ) ? WPFLICKRPORTFOLIO_VERSION : 'dev',
			get_bloginfo( 'version' ),
			home_url( '/' )
		);
	}

	/**
	 * Extract the effective response URL from a WordPress HTTP response when available.
	 *
	 * @param array<string,mixed> $response Response array.
	 * @param string              $fallback Requested URL.
	 * @return string
	 */
	private function extract_final_response_url( array $response, $fallback ) {
		if ( empty( $response['http_response'] ) || ! is_object( $response['http_response'] ) || ! method_exists( $response['http_response'], 'get_response_object' ) ) {
			return (string) $fallback;
		}

		$response_object = $response['http_response']->get_response_object();
		if ( is_object( $response_object ) && ! empty( $response_object->url ) && is_string( $response_object->url ) ) {
			return $response_object->url;
		}

		return (string) $fallback;
	}

	/**
	 * Build a standard logging payload for a completed download attempt.
	 *
	 * @param array<string,mixed> $download Download result.
	 * @return array<string,mixed>
	 */
	private function download_log_context( array $download ) {
		return array(
			'photo_id'       => isset( $download['photo_id'] ) ? (string) $download['photo_id'] : '',
			'import_variant' => isset( $download['variant'] ) ? (string) $download['variant'] : '',
			'url'            => isset( $download['requested_url'] ) ? (string) $download['requested_url'] : '',
			'final_url'      => isset( $download['final_url'] ) ? (string) $download['final_url'] : '',
			'response_code'  => isset( $download['response_code'] ) ? absint( $download['response_code'] ) : 0,
			'content_type'   => isset( $download['content_type'] ) ? (string) $download['content_type'] : '',
			'content_length' => isset( $download['content_length'] ) ? absint( $download['content_length'] ) : 0,
			'retry_after'    => isset( $download['retry_after'] ) ? (string) $download['retry_after'] : '',
			'bytes_written'  => isset( $download['bytes_written'] ) ? absint( $download['bytes_written'] ) : 0,
			'file_name'      => isset( $download['file_name'] ) ? (string) $download['file_name'] : '',
			'transport'      => isset( $download['transport'] ) ? (string) $download['transport'] : '',
		);
	}

	/**
	 * Determine whether a download response hit Flickr throttling.
	 *
	 * @param array<string,mixed> $download Download result.
	 * @return bool
	 */
	private function is_rate_limited_download( array $download ) {
		return 429 === absint( $download['response_code'] ?? 0 );
	}

	/**
	 * Calculate a bounded retry delay for a throttled Flickr download.
	 *
	 * @param array<string,mixed> $download Download result.
	 * @return int
	 */
	private function download_rate_limit_retry_delay( array $download ) {
		return $this->api->rate_limit_retry_delay_from_header( (string) ( $download['retry_after'] ?? '' ), 1 );
	}

	/**
	 * Delete a temporary import file when it is no longer needed.
	 *
	 * @param string $temp_file Temporary path.
	 * @return void
	 */
	private function cleanup_import_temp_file( $temp_file ) {
		if ( $temp_file && file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}
	}

	/**
	 * Ask PHP and WordPress for a longer runtime budget during large imports.
	 *
	 * @param int $seconds Desired execution window.
	 * @return void
	 */
	private function prepare_long_running_runtime( $seconds ) {
		$seconds = max( 60, absint( $seconds ) );

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( function_exists( 'wp_set_time_limit' ) ) {
			wp_set_time_limit( $seconds );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds );
		}
	}


	/**
	 * Pick the safest Flickr image URL variant for WordPress import work.
	 *
	 * Prefers the original Flickr file and only falls back to derived Flickr sizes when allowed.
	 *
	 * @param array<string,mixed> $photo Flickr photo payload.
	 * @return array<string,string>
	 */
	private function select_import_source( array $photo, $prefer_original = true, $allow_resized_fallback = false ) {
		if ( $prefer_original && ! $allow_resized_fallback ) {
			$candidates = array( 'full_url' );
		} else {
			$candidates = $prefer_original
				? array( 'full_url', 'display_url', 'thumbnail_url' )
				: array( 'display_url', 'full_url', 'thumbnail_url' );
		}

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
	 * Queue a scheduled source to continue in the background.
	 *
	 * @param string $base_key Source key.
	 * @param string $trigger Trigger label for logging.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function queue_scheduled_source_run( $base_key, $trigger = 'manual' ) {
		if ( ! $base_key ) {
			return new WP_Error( 'wpfp_missing_schedule_key', __( 'Missing scheduled source key.', 'wp-flickr-portfolio' ) );
		}

		$records = $this->get_scheduled_sources();
		if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
			return new WP_Error( 'wpfp_missing_schedule_source', __( 'Scheduled source not found.', 'wp-flickr-portfolio' ) );
		}

		$record = $records[ $base_key ];
		if ( 'schedule' === $trigger && empty( $record['enabled'] ) ) {
			return array(
				'status' => 'ignored',
			);
		}

		if ( in_array( (string) ( $record['last_status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return new WP_Error( 'wpfp_schedule_running', __( 'This scheduled source is already running.', 'wp-flickr-portfolio' ) );
		}

		$record                  = $this->reset_scheduled_progress( $record );
		$record['last_status']   = 'queued';
		$record['last_message']  = __( 'Queued to run in the background.', 'wp-flickr-portfolio' );
		$record['last_run_at']   = time();
		$records[ $base_key ]    = $record;
		$this->save_scheduled_sources( $records );
		$this->schedule_source_worker( $base_key, 0 );
		$this->trigger_background_worker();
		$this->logger->info( 'media_sync', 'Scheduled source queued for background sync.', array( 'base_key' => $base_key, 'trigger' => $trigger ), 'schedule_queued' );

		return array(
			'status' => 'queued',
		);
	}

	/**
	 * Process one background batch for a scheduled source.
	 *
	 * @param string $base_key Source key.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function process_scheduled_source_batch( $base_key ) {
		if ( ! $base_key ) {
			return new WP_Error( 'wpfp_missing_schedule_key', __( 'Missing scheduled source key.', 'wp-flickr-portfolio' ) );
		}

		$lock_key = $this->scheduled_source_lock_key( $base_key );
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'wpfp_schedule_running', __( 'This scheduled source is already running.', 'wp-flickr-portfolio' ) );
		}

		set_transient( $lock_key, time(), self::SCHEDULE_LOCK_TTL );
		$this->prepare_long_running_runtime( self::IMPORT_DOWNLOAD_TIMEOUT + 60 );

		try {
			$records = $this->get_scheduled_sources();
			if ( empty( $records[ $base_key ] ) || ! is_array( $records[ $base_key ] ) ) {
				return new WP_Error( 'wpfp_missing_schedule_source', __( 'Scheduled source not found.', 'wp-flickr-portfolio' ) );
			}

			$record = $records[ $base_key ];
			$record['last_status']  = 'running';
			$record['last_message'] = $this->build_progress_message( $record, __( 'Starting background sync.', 'wp-flickr-portfolio' ) );
			$records[ $base_key ]   = $record;
			$this->save_scheduled_sources( $records );
			$this->logger->info( 'media_sync', 'Scheduled source batch started.', array( 'base_key' => $base_key ), 'schedule_running' );

			$page               = max( 1, absint( $record['progress_current_page'] ?? 1 ) );
			$current_index      = max( 0, absint( $record['progress_current_index'] ?? 0 ) );
			$source             = $this->scheduled_source_args( $record, $page );
			$should_bypass_cache = 0 === absint( $record['progress_processed_items'] ?? 0 ) && 0 === absint( $record['progress_rate_limit_attempts'] ?? 0 );
			$gallery            = $this->api->get_gallery_data( $source, $should_bypass_cache );

			if ( is_wp_error( $gallery ) ) {
				return $this->mark_scheduled_source_failed( $base_key, $gallery );
			}

			$record['progress_total_pages'] = isset( $gallery['pages'] ) ? max( 1, absint( $gallery['pages'] ) ) : max( 1, absint( $record['progress_total_pages'] ?? 1 ) );
			$record['progress_total_items'] = isset( $gallery['total'] ) ? max( count( $gallery['photos'] ?? array() ), absint( $gallery['total'] ) ) : max( absint( $record['progress_total_items'] ?? 0 ), count( $gallery['photos'] ?? array() ) );

			$photos = isset( $gallery['photos'] ) && is_array( $gallery['photos'] ) ? array_values( $gallery['photos'] ) : array();
			if ( empty( $photos ) ) {
				return $this->mark_scheduled_source_failed( $base_key, new WP_Error( 'wpfp_empty_sync', __( 'No Flickr photos were returned for this source.', 'wp-flickr-portfolio' ) ) );
			}

			$collection_key = $this->api->build_collection_key( isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : $source );
			$source_key     = isset( $gallery['source_key'] ) ? (string) $gallery['source_key'] : $this->api->build_source_key( $source );
			$limit          = self::SCHEDULE_BATCH_SIZE;
			$processed_now  = 0;

			while ( $processed_now < $limit && isset( $photos[ $current_index ] ) && is_array( $photos[ $current_index ] ) ) {
				$photo                           = $photos[ $current_index ];
				$record['progress_current_photo_id'] = isset( $photo['id'] ) ? (string) $photo['id'] : '';
				$records[ $base_key ]            = $record;
				$this->save_scheduled_sources( $records );

				$import_result = $this->sync_gallery_photo(
					isset( $gallery['source'] ) && is_array( $gallery['source'] ) ? $gallery['source'] : $source,
					$collection_key,
					$source_key,
					$page,
					$photo,
					$current_index,
					! empty( $record['refresh_existing'] ),
					true
				);

				if ( is_wp_error( $import_result ) ) {
					if ( 'wpfp_original_transport_rate_limited' === $import_result->get_error_code() ) {
						return $this->reschedule_scheduled_source_after_rate_limit( $base_key, $record, $import_result );
					}

					return $this->mark_scheduled_source_failed( $base_key, $import_result );
				}

				$record['progress_processed_items'] = absint( $record['progress_processed_items'] ?? 0 ) + 1;
				$record['progress_rate_limit_attempts'] = 0;
				if ( 'created' === ( $import_result['status'] ?? '' ) ) {
					$record['progress_created'] = absint( $record['progress_created'] ?? 0 ) + 1;
				} elseif ( 'updated' === ( $import_result['status'] ?? '' ) ) {
					$record['progress_updated'] = absint( $record['progress_updated'] ?? 0 ) + 1;
				} else {
					$record['progress_reused'] = absint( $record['progress_reused'] ?? 0 ) + 1;
				}

				$current_index++;
				$processed_now++;
			}

			if ( $current_index >= count( $photos ) ) {
				$record['progress_pages_synced'] = max( absint( $record['progress_pages_synced'] ?? 0 ), $page );
				$page++;
				$current_index = 0;
			}

			$record['progress_current_page']  = $page;
			$record['progress_current_index'] = $current_index;
			$record['progress_current_photo_id'] = '';
			$records[ $base_key ]             = $record;

			if ( $page > absint( $record['progress_total_pages'] ?? 1 ) ) {
				$record['last_run_at']          = time();
				$record['last_status']          = 'success';
				$record['last_pages_synced']    = absint( $record['progress_total_pages'] ?? 0 );
				$record['last_total_pages']     = absint( $record['progress_total_pages'] ?? 0 );
				$record['last_message']         = sprintf(
					/* translators: 1: synced pages, 2: total pages. */
					__( 'Synced %1$d of %2$d pages.', 'wp-flickr-portfolio' ),
					absint( $record['progress_total_pages'] ?? 0 ),
					absint( $record['progress_total_pages'] ?? 0 )
				);
				$records[ $base_key ]           = $record;
				$this->save_scheduled_sources( $records );
				$this->logger->info(
					'media_sync',
					'Scheduled source sync completed.',
					array(
						'base_key'     => $base_key,
						'pages_synced' => absint( $record['last_pages_synced'] ?? 0 ),
						'total_pages'  => absint( $record['last_total_pages'] ?? 0 ),
						'photos'       => absint( $record['progress_processed_items'] ?? 0 ),
					),
					'schedule_complete'
				);

				return array(
					'status'      => 'success',
					'pages_synced' => absint( $record['last_pages_synced'] ?? 0 ),
					'total_pages' => absint( $record['last_total_pages'] ?? 0 ),
				);
			}

			$record['last_status']  = 'queued';
			$record['last_message'] = $this->build_progress_message( $record, __( 'Continuing in the background.', 'wp-flickr-portfolio' ) );
			$records[ $base_key ]   = $record;
			$this->save_scheduled_sources( $records );
			$this->schedule_source_worker( $base_key, 0 );
			$this->trigger_background_worker();
			$this->logger->info(
				'media_sync',
				'Scheduled source batch completed and queued the next batch.',
				array(
					'base_key'   => $base_key,
					'next_page'  => $page,
					'next_index' => $current_index,
					'processed'  => absint( $record['progress_processed_items'] ?? 0 ),
					'total'      => absint( $record['progress_total_items'] ?? 0 ),
				),
				'schedule_progress'
			);

			return array(
				'status' => 'queued',
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Build normalized source arguments for a scheduled source record.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @param int                 $page Flickr page to fetch.
	 * @return array<string,mixed>
	 */
	private function scheduled_source_args( array $record, $page ) {
		return array(
			'source'         => isset( $record['source'] ) ? (string) $record['source'] : 'photoset',
			'user_id'        => isset( $record['user_id'] ) ? (string) $record['user_id'] : '',
			'photoset_id'    => isset( $record['photoset_id'] ) ? (string) $record['photoset_id'] : '',
			'per_page'       => isset( $record['per_page'] ) ? absint( $record['per_page'] ) : 24,
			'page'           => max( 1, absint( $page ) ),
			'privacy_filter' => isset( $record['privacy_filter'] ) ? absint( $record['privacy_filter'] ) : 1,
		);
	}

	/**
	 * Reset persisted progress state for a scheduled source.
	 *
	 * @param array<string,mixed> $record Scheduled source record.
	 * @return array<string,mixed>
	 */
	private function reset_scheduled_progress( array $record ) {
		$record['progress_started_at']      = time();
		$record['progress_current_page']    = 1;
		$record['progress_current_index']   = 0;
		$record['progress_pages_synced']    = 0;
		$record['progress_total_pages']     = 0;
		$record['progress_total_items']     = 0;
		$record['progress_processed_items'] = 0;
		$record['progress_created']         = 0;
		$record['progress_updated']         = 0;
		$record['progress_reused']          = 0;
		$record['progress_rate_limit_attempts'] = 0;
		$record['progress_current_photo_id'] = '';

		return $record;
	}

	/**
	 * Mark a scheduled source as failed and persist the failure message.
	 *
	 * @param string    $base_key Source key.
	 * @param \WP_Error $error Failure.
	 * @return \WP_Error
	 */
	private function mark_scheduled_source_failed( $base_key, WP_Error $error ) {
		$records = $this->get_scheduled_sources();
		if ( ! empty( $records[ $base_key ] ) && is_array( $records[ $base_key ] ) ) {
			$records[ $base_key ]['last_run_at']  = time();
			$records[ $base_key ]['last_status']  = 'error';
			$records[ $base_key ]['last_message'] = $error->get_error_message();
			$this->save_scheduled_sources( $records );
		}

		$this->logger->error( 'media_sync', $error->get_error_message(), array( 'base_key' => $base_key, 'code' => $error->get_error_code() ), 'schedule_failed' );
		return $error;
	}

	/**
	 * Reschedule a scheduled source after Flickr rate limiting.
	 *
	 * @param string    $base_key Source key.
	 * @param array     $record Scheduled source record.
	 * @param \WP_Error $error Rate-limit error.
	 * @return array<string,mixed>
	 */
	private function reschedule_scheduled_source_after_rate_limit( $base_key, array $record, WP_Error $error ) {
		$error_data                   = $error->get_error_data();
		$error_data                   = is_array( $error_data ) ? $error_data : array();
		$attempts                     = absint( $record['progress_rate_limit_attempts'] ?? 0 ) + 1;
		$record['progress_rate_limit_attempts'] = $attempts;
		$retry_delay                  = $this->scheduled_rate_limit_delay( $error_data, $attempts );
		$photo_id                     = (string) ( $error_data['photo_id'] ?? ( $record['progress_current_photo_id'] ?? '' ) );

		if ( $attempts > self::MAX_RATE_LIMIT_RESCHEDULES ) {
			return $this->mark_scheduled_source_failed(
				$base_key,
				new WP_Error(
					'wpfp_original_transport_rate_limited_persistent',
					sprintf(
						/* translators: 1: Flickr photo ID, 2: number of deferred retries. */
						__( 'Flickr kept throttling original downloads for photo %1$s after %2$d deferred retries. This host may be blocked from server-side original fetches.', 'wp-flickr-portfolio' ),
						$photo_id ? $photo_id : __( 'unknown', 'wp-flickr-portfolio' ),
						$attempts - 1
					)
				)
			);
		}

		$record['last_status']        = 'queued';
		$record['last_message']       = sprintf(
			/* translators: 1: Flickr photo ID, 2: seconds until retry, 3: deferred retry attempt. */
			__( 'Flickr asked to slow down on photo %1$s. Retrying in %2$d seconds (attempt %3$d).', 'wp-flickr-portfolio' ),
			$photo_id ? $photo_id : __( 'unknown', 'wp-flickr-portfolio' ),
			$retry_delay,
			$attempts
		);

		$records            = $this->get_scheduled_sources();
		$records[ $base_key ] = $record;
		$this->save_scheduled_sources( $records );
		$this->schedule_source_worker( $base_key, $retry_delay );
		$this->trigger_background_worker();
		$this->logger->warning( 'media_sync', $error->get_error_message(), array( 'base_key' => $base_key, 'photo_id' => $photo_id, 'retry_after' => $retry_delay, 'attempt' => $attempts ), 'schedule_rate_limited' );

		return array(
			'status'      => 'queued',
			'retry_after' => $retry_delay,
		);
	}

	/**
	 * Calculate the next background delay after Flickr throttles a source.
	 *
	 * @param array<string,mixed> $error_data Rate-limit error data.
	 * @param int                 $attempt Deferred retry attempt number starting at 1.
	 * @return int
	 */
	private function scheduled_rate_limit_delay( array $error_data, $attempt ) {
		$hint   = max( 1, absint( $error_data['retry_after'] ?? 0 ) );
		$delays = array( 30, 60, 120, 300, 600, 900 );
		$index  = min( max( 0, absint( $attempt ) - 1 ), count( $delays ) - 1 );

		return max( $hint, $delays[ $index ] );
	}

	/**
	 * Build a compact background-progress message for the UI.
	 *
	 * @param array<string,mixed> $record Scheduled source record.
	 * @param string              $suffix Message suffix.
	 * @return string
	 */
	private function build_progress_message( array $record, $suffix ) {
		$processed = absint( $record['progress_processed_items'] ?? 0 );
		$total     = absint( $record['progress_total_items'] ?? 0 );

		if ( $total > 0 ) {
			return sprintf(
				/* translators: 1: processed photos, 2: total photos, 3: suffix message. */
				__( 'Processed %1$d of %2$d photos. %3$s', 'wp-flickr-portfolio' ),
				$processed,
				$total,
				$suffix
			);
		}

		return (string) $suffix;
	}

	/**
	 * Schedule one background worker run for a source.
	 *
	 * @param string $base_key Source key.
	 * @param int    $delay Delay in seconds.
	 * @return void
	 */
	private function schedule_source_worker( $base_key, $delay ) {
		$delay     = max( 0, absint( $delay ) );
		$timestamp = wp_next_scheduled( self::MANUAL_SCHEDULE_HOOK, array( $base_key ) );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::MANUAL_SCHEDULE_HOOK, array( $base_key ) );
			$timestamp = wp_next_scheduled( self::MANUAL_SCHEDULE_HOOK, array( $base_key ) );
		}

		wp_schedule_single_event( time() + $delay, self::MANUAL_SCHEDULE_HOOK, array( $base_key ) );
	}

	/**
	 * Ask WordPress to trigger queued cron work without blocking the admin page.
	 *
	 * @return void
	 */
	private function trigger_background_worker() {
		if ( ! function_exists( 'spawn_cron' ) ) {
			require_once ABSPATH . WPINC . '/cron.php';
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
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
		$schedule = $schedule ? $schedule : 'daily';
		$next_run = time() + $this->get_schedule_interval( $schedule );
		wp_schedule_event( $next_run, $schedule, self::CRON_HOOK, array( $base_key ) );
	}

	/**
	 * Resolve a cron schedule key to its interval in seconds.
	 *
	 * @param string $schedule Schedule key.
	 * @return int
	 */
	private function get_schedule_interval( $schedule ) {
		$schedules = wp_get_schedules();
		$interval  = isset( $schedules[ $schedule ]['interval'] ) ? absint( $schedules[ $schedule ]['interval'] ) : DAY_IN_SECONDS;

		return max( MINUTE_IN_SECONDS, $interval );
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
	private function redirect_with_notice( $notice, $detail = '' ) {
		$args = array(
			'wpfp_notice' => $notice,
		);
		if ( $detail ) {
			$args['wpfp_notice_detail'] = $detail;
		}
		wp_safe_redirect( $this->settings->admin_page_url( $args ) );
		exit;
	}
}
