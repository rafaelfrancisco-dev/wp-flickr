<?php
/**
 * Background importer: downloads Flickr photos into the WordPress media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Importer {

	/**
	 * WordPress option that holds the current import job state.
	 */
	const JOB_OPTION = 'wpfp_import_job';

	/**
	 * Transient key used as a mutex so only one worker batch runs at a time.
	 */
	const LOCK_KEY = 'wpfp_import_lock';

	/**
	 * Lock TTL in seconds. Long enough for one full batch to complete.
	 */
	const LOCK_TTL = 120;

	/**
	 * WP-Cron hook name (fallback only — primary dispatch is via HTTP self-request).
	 */
	const WORKER_HOOK = 'wpfp_run_import_worker';

	/**
	 * AJAX action name used for the non-blocking self-request tick.
	 */
	const TICK_ACTION = 'wpfp_worker_tick';

	/**
	 * Number of photos processed per worker invocation.
	 */
	const BATCH_SIZE = 3;

	/**
	 * Attachment post-meta key that stores the Flickr photo ID.
	 */
	const PHOTO_META = '_wpfp_flickr_photo_id';

	/**
	 * Photos per Flickr API page (maximum allowed).
	 */
	const PHOTOS_PER_PAGE = 500;

	/**
	 * Shared settings manager.
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
	 * @param WP_Flickr_Portfolio_API      $api      Flickr API client.
	 * @param WP_Flickr_Portfolio_Logger   $logger   Logger service.
	 */
	public function __construct(
		WP_Flickr_Portfolio_Settings $settings,
		WP_Flickr_Portfolio_API $api,
		WP_Flickr_Portfolio_Logger $logger
	) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;

		add_action( 'wp_ajax_wpfp_start_import',        array( $this, 'handle_start_import' ) );
		add_action( 'wp_ajax_wpfp_stop_import',         array( $this, 'handle_stop_import' ) );
		add_action( 'wp_ajax_wpfp_get_import_progress', array( $this, 'handle_get_import_progress' ) );
		add_action( 'wp_ajax_' . self::TICK_ACTION,     array( $this, 'handle_worker_tick' ) );
		add_action( self::WORKER_HOOK,                  array( $this, 'run_worker' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: start a new import job.
	 *
	 * @return void
	 */
	public function handle_start_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-flickr-portfolio' ) ), 403 );
		}

		check_ajax_referer( 'wpfp_start_import', 'nonce' );

		$existing = $this->get_job();
		if ( 'running' === ( $existing['status'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'An import is already running.', 'wp-flickr-portfolio' ) ), 409 );
		}

		$source         = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'photostream';
		$photoset_id    = isset( $_POST['photoset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['photoset_id'] ) ) : '';
		$privacy_filter = isset( $_POST['privacy_filter'] ) ? min( 5, max( 1, absint( $_POST['privacy_filter'] ) ) ) : 1;

		if ( ! in_array( $source, array( 'photostream', 'photoset' ), true ) ) {
			$source = 'photostream';
		}

		if ( 'photoset' === $source && empty( $photoset_id ) ) {
			wp_send_json_error( array( 'message' => __( 'A photoset_id is required for source="photoset".', 'wp-flickr-portfolio' ) ), 400 );
		}

		$user_id = (string) $this->settings->get( 'user_nsid' );

		$job = array(
			'status'         => 'running',
			'source'         => $source,
			'user_id'        => $user_id,
			'photoset_id'    => $photoset_id,
			'privacy_filter' => $privacy_filter,
			'started_at'     => time(),
			'current_page'   => 1,
			'current_index'  => 0,
			'total_pages'    => 0,
			'total_photos'   => 0,
			'processed'      => 0,
			'imported'       => 0,
			'skipped'        => 0,
			'failed_items'   => 0,
			'last_error'     => '',
			'completed_at'   => 0,
		);

		update_option( self::JOB_OPTION, $job, false );

		$this->logger->info(
			'importer',
			'Import job started.',
			array(
				'source'      => $source,
				'user_id'     => $user_id,
				'photoset_id' => $photoset_id,
			),
			'import_start'
		);

		// Dispatch: non-blocking self-request (immediate) + WP-Cron (fallback).
		$this->spawn_worker();

		wp_send_json_success( array( 'job' => $this->job_for_response( $job ) ) );
	}

	/**
	 * AJAX: stop the running import.
	 *
	 * @return void
	 */
	public function handle_stop_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-flickr-portfolio' ) ), 403 );
		}

		check_ajax_referer( 'wpfp_stop_import', 'nonce' );

		$job = $this->get_job();
		if ( 'running' === ( $job['status'] ?? '' ) ) {
			$job['status'] = 'stopped';
			update_option( self::JOB_OPTION, $job, false );
			$this->logger->info( 'importer', 'Import job stopped by user.', array(), 'import_stopped' );
		}

		wp_send_json_success( array( 'job' => $this->job_for_response( $job ) ) );
	}

	/**
	 * AJAX: return the current job progress.
	 *
	 * @return void
	 */
	public function handle_get_import_progress() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-flickr-portfolio' ) ), 403 );
		}

		check_ajax_referer( 'wpfp_import_progress', 'nonce' );

		wp_send_json_success( array( 'job' => $this->job_for_response( $this->get_job() ) ) );
	}

	/**
	 * AJAX: worker tick triggered by the non-blocking self-request.
	 *
	 * Runs in the background (non-blocking from the caller's perspective).
	 * Processes one batch and reschedules via spawn_worker() if needed.
	 *
	 * @return void
	 */
	public function handle_worker_tick() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		check_ajax_referer( 'wpfp_worker_tick', 'nonce' );

		// Prevent the response from waiting for this to finish.
		$this->close_http_connection();

		$this->process_batch();

		wp_die();
	}

	// -------------------------------------------------------------------------
	// WP-Cron fallback worker
	// -------------------------------------------------------------------------

	/**
	 * WP-Cron hook callback (fallback in case the HTTP self-request fails).
	 *
	 * @return void
	 */
	public function run_worker() {
		$this->process_batch();
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	/**
	 * Render the "Import from Flickr" admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$job             = $this->get_job();
		$status          = $job['status'] ?? 'idle';
		$is_running      = 'running' === $status;
		$is_connected    = $this->settings->is_connected();
		$has_credentials = $this->settings->has_api_credentials();

		// Fetch photosets for the dropdown (only when connected).
		$photosets = array();
		if ( $is_connected && $has_credentials ) {
			$photosets_result = $this->api->get_photosets();
			if ( ! is_wp_error( $photosets_result ) ) {
				$photosets = $photosets_result;
			}
		}

		$start_nonce    = wp_create_nonce( 'wpfp_start_import' );
		$stop_nonce     = wp_create_nonce( 'wpfp_stop_import' );
		$progress_nonce = wp_create_nonce( 'wpfp_import_progress' );
		$ajax_url       = admin_url( 'admin-ajax.php' );

		$pct = 0;
		if ( $job['total_photos'] > 0 && $job['processed'] > 0 ) {
			$pct = (int) min( 100, round( $job['processed'] / $job['total_photos'] * 100 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import from Flickr', 'wp-flickr-portfolio' ); ?></h1>

			<?php if ( ! $has_credentials ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Add your Flickr API credentials on the Settings page before importing.', 'wp-flickr-portfolio' ); ?></p></div>
			<?php elseif ( ! $is_connected ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Connect your Flickr account on the Settings page to import private photos or photosets.', 'wp-flickr-portfolio' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $is_running ) : ?>
			<div id="wpfp-import-start-section">
				<p><?php esc_html_e( 'Download photos from your connected Flickr account into the WordPress media library. Already-imported photos are automatically skipped.', 'wp-flickr-portfolio' ); ?></p>

				<?php if ( $job['completed_at'] > 0 ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php
						printf(
							/* translators: 1: date/time, 2: imported count, 3: skipped count */
							esc_html__( 'Last import completed on %1$s — %2$d imported, %3$d skipped.', 'wp-flickr-portfolio' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $job['completed_at'] ) ),
							(int) $job['imported'],
							(int) $job['skipped']
						);
						?>
						<?php if ( $job['failed_items'] > 0 ) : ?>
							<?php printf( esc_html__( ' %d failed.', 'wp-flickr-portfolio' ), (int) $job['failed_items'] ); ?>
						<?php endif; ?>
					</p>
				</div>
				<?php endif; ?>

				<?php if ( 'failed' === $status && $job['last_error'] ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $job['last_error'] ); ?></p></div>
				<?php endif; ?>

				<?php if ( 'stopped' === $status ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Last import was stopped. You can start a new one below.', 'wp-flickr-portfolio' ); ?></p></div>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wpfp-import-source"><?php esc_html_e( 'Source', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<select id="wpfp-import-source">
									<option value="photostream"><?php esc_html_e( 'Photostream (all photos)', 'wp-flickr-portfolio' ); ?></option>
									<option value="photoset"><?php esc_html_e( 'Photoset', 'wp-flickr-portfolio' ); ?></option>
								</select>
							</td>
						</tr>
						<tr id="wpfp-photoset-row" style="display:none">
							<th scope="row"><label for="wpfp-import-photoset"><?php esc_html_e( 'Photoset', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<?php if ( ! empty( $photosets ) ) : ?>
									<select id="wpfp-import-photoset">
										<?php foreach ( $photosets as $set ) : ?>
											<option value="<?php echo esc_attr( $set['id'] ); ?>">
												<?php echo esc_html( $set['title'] ); ?> (<?php echo esc_html( (string) $set['photos'] ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" id="wpfp-import-photoset" class="regular-text" placeholder="<?php esc_attr_e( 'Photoset ID', 'wp-flickr-portfolio' ); ?>">
									<p class="description"><?php esc_html_e( 'Enter the Flickr photoset ID manually, or connect your account to see a dropdown.', 'wp-flickr-portfolio' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp-import-privacy"><?php esc_html_e( 'Privacy', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<select id="wpfp-import-privacy">
									<option value="1"><?php esc_html_e( 'Public only', 'wp-flickr-portfolio' ); ?></option>
									<?php if ( $is_connected ) : ?>
									<option value="2"><?php esc_html_e( 'Friends', 'wp-flickr-portfolio' ); ?></option>
									<option value="3"><?php esc_html_e( 'Family', 'wp-flickr-portfolio' ); ?></option>
									<option value="4"><?php esc_html_e( 'Friends &amp; Family', 'wp-flickr-portfolio' ); ?></option>
									<option value="5"><?php esc_html_e( 'Private', 'wp-flickr-portfolio' ); ?></option>
									<?php endif; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<button id="wpfp-start-btn" class="button button-primary" <?php echo $has_credentials ? '' : 'disabled'; ?>>
						<?php esc_html_e( 'Start Import', 'wp-flickr-portfolio' ); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>

			<div id="wpfp-import-progress-section" style="<?php echo $is_running ? '' : 'display:none'; ?>">
				<p id="wpfp-progress-text">
					<?php
					if ( $is_running ) {
						esc_html_e( 'Import in progress…', 'wp-flickr-portfolio' );
					}
					?>
				</p>
				<progress id="wpfp-progress-bar" max="100" value="<?php echo esc_attr( (string) $pct ); ?>" style="width:100%;max-width:500px;display:block;margin-bottom:8px"></progress>
				<p id="wpfp-progress-counts" style="color:#666;">
					<?php if ( $is_running && $job['total_photos'] > 0 ) : ?>
						<?php
						printf(
							/* translators: 1: processed, 2: total */
							esc_html__( '%1$d of %2$d photos processed', 'wp-flickr-portfolio' ),
							(int) $job['processed'],
							(int) $job['total_photos']
						);
						?>
					<?php endif; ?>
				</p>
				<p>
					<button id="wpfp-stop-btn" class="button button-secondary">
						<?php esc_html_e( 'Stop Import', 'wp-flickr-portfolio' ); ?>
					</button>
				</p>
			</div>
		</div>

		<script>
		(function() {
			var ajaxUrl       = <?php echo wp_json_encode( $ajax_url ); ?>;
			var startNonce    = <?php echo wp_json_encode( $start_nonce ); ?>;
			var stopNonce     = <?php echo wp_json_encode( $stop_nonce ); ?>;
			var progressNonce = <?php echo wp_json_encode( $progress_nonce ); ?>;
			var pollTimer     = null;

			function showSection(which) {
				var startSec    = document.getElementById('wpfp-import-start-section');
				var progressSec = document.getElementById('wpfp-import-progress-section');
				if (startSec)    { startSec.style.display    = ('start'    === which) ? '' : 'none'; }
				if (progressSec) { progressSec.style.display = ('progress' === which) ? '' : 'none'; }
			}

			function updateProgressUI(job) {
				var bar    = document.getElementById('wpfp-progress-bar');
				var text   = document.getElementById('wpfp-progress-text');
				var counts = document.getElementById('wpfp-progress-counts');
				if (!bar) return;

				var pct = 0;
				if (job.total_photos > 0) {
					pct = Math.min(100, Math.round(job.processed / job.total_photos * 100));
				}
				bar.value = pct;

				if (text) {
					text.textContent = <?php echo wp_json_encode( __( 'Import in progress\u2026', 'wp-flickr-portfolio' ) ); ?>;
				}
				if (counts) {
					if (job.total_photos > 0) {
						counts.textContent = job.processed + ' / ' + job.total_photos + ' ' + <?php echo wp_json_encode( __( 'photos processed', 'wp-flickr-portfolio' ) ); ?>;
					} else {
						counts.textContent = job.processed + ' ' + <?php echo wp_json_encode( __( 'photos processed', 'wp-flickr-portfolio' ) ); ?>;
					}
				}
			}

			function pollProgress() {
				var body = new FormData();
				body.append('action', 'wpfp_get_import_progress');
				body.append('nonce', progressNonce);

				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						if (!resp.success) return;
						var job = resp.data.job;
						updateProgressUI(job);
						if ('running' !== job.status) {
							clearInterval(pollTimer);
							pollTimer = null;
							window.location.reload();
						}
					})
					.catch(function() {});
			}

			function startPolling() {
				if (pollTimer) return;
				pollTimer = setInterval(pollProgress, 2000);
			}

			// Start polling immediately if already running on page load.
			<?php if ( $is_running ) : ?>
			showSection('progress');
			startPolling();
			<?php endif; ?>

			var startBtn = document.getElementById('wpfp-start-btn');
			if (startBtn) {
				startBtn.addEventListener('click', function() {
					var source       = document.getElementById('wpfp-import-source');
					var photosetEl   = document.getElementById('wpfp-import-photoset');
					var privacyEl    = document.getElementById('wpfp-import-privacy');
					var sourceVal    = source ? source.value : 'photostream';
					var photosetVal  = photosetEl ? photosetEl.value : '';
					var privacyVal   = privacyEl ? privacyEl.value : '1';

					var body = new FormData();
					body.append('action', 'wpfp_start_import');
					body.append('nonce', startNonce);
					body.append('source', sourceVal);
					body.append('photoset_id', photosetVal);
					body.append('privacy_filter', privacyVal);

					startBtn.disabled = true;

					fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(resp) {
							if (resp.success) {
								showSection('progress');
								startPolling();
							} else {
								startBtn.disabled = false;
								var msg = (resp.data && resp.data.message) ? resp.data.message : <?php echo wp_json_encode( __( 'Could not start import.', 'wp-flickr-portfolio' ) ); ?>;
								alert(msg);
							}
						})
						.catch(function() {
							startBtn.disabled = false;
						});
				});
			}

			var stopBtn = document.getElementById('wpfp-stop-btn');
			if (stopBtn) {
				stopBtn.addEventListener('click', function() {
					var body = new FormData();
					body.append('action', 'wpfp_stop_import');
					body.append('nonce', stopNonce);

					stopBtn.disabled = true;
					fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function() {
							if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
							window.location.reload();
						})
						.catch(function() { stopBtn.disabled = false; });
				});
			}

			// Toggle photoset row based on source selector.
			var sourceSelect = document.getElementById('wpfp-import-source');
			if (sourceSelect) {
				sourceSelect.addEventListener('change', function() {
					var row = document.getElementById('wpfp-photoset-row');
					if (row) { row.style.display = ('photoset' === this.value) ? '' : 'none'; }
				});
			}
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Core batch processing (shared by HTTP tick and WP-Cron fallback)
	// -------------------------------------------------------------------------

	/**
	 * Process one batch of photos and reschedule if more remain.
	 *
	 * @return void
	 */
	private function process_batch() {
		// Acquire mutex.
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$job = $this->get_job();

			if ( 'running' !== ( $job['status'] ?? '' ) ) {
				delete_transient( self::LOCK_KEY );
				return;
			}

			// Fetch the current Flickr page.
			$page_data = $this->api->get_photos_page(
				array(
					'source'         => $job['source'],
					'user_id'        => $job['user_id'],
					'photoset_id'    => $job['photoset_id'],
					'per_page'       => self::PHOTOS_PER_PAGE,
					'page'           => $job['current_page'],
					'privacy_filter' => $job['privacy_filter'],
				)
			);

			if ( is_wp_error( $page_data ) ) {
				$job['status']     = 'failed';
				$job['last_error'] = $page_data->get_error_message();
				update_option( self::JOB_OPTION, $job, false );
				$this->logger->error(
					'importer',
					'Import worker failed to fetch Flickr page.',
					array(
						'page'  => $job['current_page'],
						'error' => $page_data->get_error_message(),
					),
					'worker_fetch_error'
				);
				delete_transient( self::LOCK_KEY );
				return;
			}

			$page_photos = isset( $page_data['photos'] ) && is_array( $page_data['photos'] )
				? $page_data['photos']
				: array();

			// Populate totals from first page response.
			if ( 0 === $job['total_pages'] && isset( $page_data['pages'] ) ) {
				$job['total_pages'] = (int) $page_data['pages'];
			}
			if ( 0 === $job['total_photos'] && isset( $page_data['total'] ) ) {
				$job['total_photos'] = (int) $page_data['total'];
			}

			// Handle empty page (no photos in source).
			if ( empty( $page_photos ) ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				update_option( self::JOB_OPTION, $job, false );
				$this->logger->info( 'importer', 'Import completed (no photos on page).', array(), 'import_complete' );
				delete_transient( self::LOCK_KEY );
				return;
			}

			// Process up to BATCH_SIZE photos starting at current_index.
			$batch_count = 0;
			while ( $batch_count < self::BATCH_SIZE ) {
				$idx   = $job['current_index'];
				$photo = isset( $page_photos[ $idx ] ) ? $page_photos[ $idx ] : null;

				if ( null === $photo ) {
					// Exhausted this page — advance to the next.
					$job['current_page']++;
					$job['current_index'] = 0;
					break;
				}

				$photo_id = isset( $photo['id'] ) ? (string) $photo['id'] : '';

				if ( $this->photo_exists( $photo_id ) ) {
					$job['skipped']++;
					$this->logger->debug(
						'importer',
						'Photo already in media library, skipping.',
						array( 'photo_id' => $photo_id ),
						'photo_skip'
					);
				} else {
					$result = $this->import_photo( $photo );
					if ( is_wp_error( $result ) ) {
						$job['failed_items']++;
						$this->logger->warning(
							'importer',
							'Failed to import photo.',
							array(
								'photo_id' => $photo_id,
								'error'    => $result->get_error_message(),
							),
							'photo_import_error'
						);
					} else {
						$job['imported']++;
						$this->logger->debug(
							'importer',
							'Photo imported.',
							array(
								'photo_id'      => $photo_id,
								'attachment_id' => $result,
							),
							'photo_imported'
						);
					}
				}

				$job['processed']++;
				$job['current_index']++;
				$batch_count++;
			}

			// Check if we've moved past the last page.
			$all_done = $job['total_pages'] > 0 && $job['current_page'] > $job['total_pages'];

			if ( $all_done ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				update_option( self::JOB_OPTION, $job, false );
				$this->logger->info(
					'importer',
					'Import job completed.',
					array(
						'imported'     => $job['imported'],
						'skipped'      => $job['skipped'],
						'failed_items' => $job['failed_items'],
					),
					'import_complete'
				);
				delete_transient( self::LOCK_KEY );
				return;
			}

			// More work remains — persist progress, release lock, then dispatch next batch.
			update_option( self::JOB_OPTION, $job, false );
			delete_transient( self::LOCK_KEY );
			$this->spawn_worker();

		} catch ( \Exception $e ) {
			$job               = $this->get_job();
			$job['status']     = 'failed';
			$job['last_error'] = $e->getMessage();
			update_option( self::JOB_OPTION, $job, false );
			$this->logger->error( 'importer', 'Unexpected exception in import worker.', array( 'error' => $e->getMessage() ), 'worker_exception' );
			delete_transient( self::LOCK_KEY );
		}
	}

	// -------------------------------------------------------------------------
	// Worker dispatch
	// -------------------------------------------------------------------------

	/**
	 * Dispatch the next worker batch.
	 *
	 * Primary method: non-blocking HTTP POST to admin-ajax.php with the current
	 * user's session cookies so WordPress can authenticate the request.
	 * Fallback: WP-Cron single event, in case the loopback request is blocked.
	 *
	 * @return void
	 */
	private function spawn_worker() {
		// WP-Cron fallback (fires on next page load if HTTP request fails).
		wp_schedule_single_event( time(), self::WORKER_HOOK );

		// Primary dispatch: immediate non-blocking self-request.
		$nonce = wp_create_nonce( 'wpfp_worker_tick' );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'cookies'   => isset( $_COOKIE ) ? $_COOKIE : array(),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => self::TICK_ACTION,
					'nonce'  => $nonce,
				),
			)
		);
	}

	/**
	 * Send HTTP 200 and close the connection immediately so the caller is not
	 * blocked waiting for this request to finish.
	 *
	 * @return void
	 */
	private function close_http_connection() {
		if ( headers_sent() ) {
			return;
		}

		// Disable output buffering so the headers go out immediately.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Connection: close' );
		header( 'Content-Length: 0' );
		header( 'HTTP/1.1 200 OK' );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			flush();
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Load the current job state from the database.
	 *
	 * @return array<string,mixed>
	 */
	private function get_job() {
		$job = get_option( self::JOB_OPTION, array() );
		if ( ! is_array( $job ) ) {
			$job = array();
		}

		return array_merge(
			array(
				'status'         => 'idle',
				'source'         => 'photostream',
				'user_id'        => '',
				'photoset_id'    => '',
				'privacy_filter' => 1,
				'started_at'     => 0,
				'current_page'   => 1,
				'current_index'  => 0,
				'total_pages'    => 0,
				'total_photos'   => 0,
				'processed'      => 0,
				'imported'       => 0,
				'skipped'        => 0,
				'failed_items'   => 0,
				'last_error'     => '',
				'completed_at'   => 0,
			),
			$job
		);
	}

	/**
	 * Produce a sanitised subset of job data safe to send to the browser.
	 *
	 * @param array<string,mixed> $job Full job state.
	 * @return array<string,mixed>
	 */
	private function job_for_response( array $job ) {
		$total     = (int) ( $job['total_photos'] ?? 0 );
		$processed = (int) ( $job['processed'] ?? 0 );
		$pct       = ( $total > 0 ) ? (int) min( 100, round( $processed / $total * 100 ) ) : 0;

		return array(
			'status'       => (string) ( $job['status'] ?? 'idle' ),
			'total_photos' => $total,
			'processed'    => $processed,
			'imported'     => (int) ( $job['imported'] ?? 0 ),
			'skipped'      => (int) ( $job['skipped'] ?? 0 ),
			'failed_items' => (int) ( $job['failed_items'] ?? 0 ),
			'percent'      => $pct,
			'last_error'   => wp_strip_all_tags( (string) ( $job['last_error'] ?? '' ) ),
			'completed_at' => (int) ( $job['completed_at'] ?? 0 ),
		);
	}

	/**
	 * Check whether a Flickr photo is already in the media library.
	 *
	 * @param string $flickr_photo_id Flickr photo ID.
	 * @return bool
	 */
	private function photo_exists( $flickr_photo_id ) {
		if ( '' === $flickr_photo_id ) {
			return false;
		}

		$results = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'any',
				'meta_key'      => self::PHOTO_META,
				'meta_value'    => $flickr_photo_id,
				'fields'        => 'ids',
				'numberposts'   => 1,
				'no_found_rows' => true,
			)
		);

		return ! empty( $results );
	}

	/**
	 * Download a Flickr photo and add it to the WordPress media library.
	 *
	 * @param array<string,mixed> $photo Normalised photo data from get_photos_page().
	 * @return int|\WP_Error Attachment post ID on success.
	 */
	private function import_photo( array $photo ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$url = ! empty( $photo['full_url'] ) ? $photo['full_url'] : ( $photo['display_url'] ?? '' );
		if ( empty( $url ) ) {
			return new WP_Error( 'wpfp_no_url', __( 'No downloadable URL available for this photo.', 'wp-flickr-portfolio' ) );
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$photo_id = isset( $photo['id'] ) ? (string) $photo['id'] : uniqid( 'flickr_', true );
		$ext      = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$filename = $photo_id . ( $ext ? '.' . $ext : '.jpg' );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$title       = ! empty( $photo['title'] ) ? $photo['title'] : $photo_id;
		$description = ! empty( $photo['description'] ) ? wp_strip_all_tags( $photo['description'] ) : '';

		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			$title,
			array(
				'post_title'   => $title,
				'post_content' => $description,
				'post_excerpt' => $description,
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmp );
			}
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::PHOTO_META, $photo_id );

		if ( ! empty( $photo['owner'] ) ) {
			update_post_meta( $attachment_id, '_wpfp_flickr_owner', sanitize_text_field( $photo['owner'] ) );
		}
		if ( ! empty( $photo['date_taken'] ) ) {
			update_post_meta( $attachment_id, '_wpfp_flickr_date_taken', sanitize_text_field( $photo['date_taken'] ) );
		}
		if ( ! empty( $photo['tags'] ) ) {
			update_post_meta( $attachment_id, '_wpfp_flickr_tags', sanitize_text_field( $photo['tags'] ) );
		}

		return $attachment_id;
	}
}
