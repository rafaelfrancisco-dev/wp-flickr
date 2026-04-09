<?php
/**
 * Plugin settings and admin interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Settings {

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'wpfp_settings';

	/**
	 * Temporary request token option name.
	 */
	const REQUEST_TOKEN_OPTION = 'wpfp_request_token';

	/**
	 * Flickr auth option name.
	 */
	const AUTH_OPTION_NAME = 'wpfp_auth';

	/**
	 * Synced source map option name.
	 */
	const SYNCED_SOURCES_OPTION = 'wpfp_synced_sources';

	/**
	 * Scheduled source registry option name.
	 */
	const SCHEDULED_SOURCES_OPTION = 'wpfp_scheduled_sources';

	/**
	 * Shared logger.
	 *
	 * @var WP_Flickr_Portfolio_Logger
	 */
	private $logger;

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		return array(
			'api_key'            => '',
			'api_secret'         => '',
			'oauth_token'        => '',
			'oauth_token_secret' => '',
			'user_nsid'          => '',
			'username'           => '',
			'fullname'           => '',
			'cache_ttl'          => 900,
			'default_mode'       => 'remote',
			'default_per_page'   => 24,
			'default_layout'     => 'grid',
			'allow_resized_fallback' => false,
			'lightbox_selector'  => '.entry-content, .gallery, .mgl-gallery, .wp-block-gallery',
		);
	}

	/**
	 * Normalize a layout slug.
	 *
	 * Legacy `tiles` values are mapped to masonry so older content still renders.
	 *
	 * @param string $layout Requested layout.
	 * @return string
	 */
	public function normalize_layout( $layout ) {
		$layout = sanitize_key( (string) $layout );

		if ( 'tiles' === $layout ) {
			return 'masonry';
		}

		return in_array( $layout, array( 'grid', 'masonry' ), true ) ? $layout : 'grid';
	}

	/**
	 * Constructor.
	 */
	public function __construct( WP_Flickr_Portfolio_Logger $logger ) {
		$this->logger = $logger;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wpfp_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_wpfp_export_logs', array( $this, 'handle_export_logs' ) );
	}

	/**
	 * Get settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function get_all() {
		$settings = get_option( self::OPTION_NAME, array() );
		$auth     = get_option( self::AUTH_OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! is_array( $auth ) ) {
			$auth = array();
		}

		$options                   = array_merge( $this->defaults(), $settings, $auth );
		$options['default_layout'] = $this->normalize_layout( $options['default_layout'] );

		return $options;
	}

	/**
	 * Get a setting by key.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$options = $this->get_all();
		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Update a subset of settings.
	 *
	 * @param array<string,mixed> $values Partial settings.
	 * @return void
	 */
	public function update( array $values ) {
		$current = $this->get_all();
		update_option( self::OPTION_NAME, array_merge( $current, $values ) );
	}

	/**
	 * Update Flickr auth values in a dedicated option.
	 *
	 * @param array<string,mixed> $values Auth values.
	 * @return void
	 */
	public function update_auth( array $values ) {
		$defaults = array(
			'oauth_token'        => '',
			'oauth_token_secret' => '',
			'user_nsid'          => '',
			'username'           => '',
			'fullname'           => '',
		);

		$current = get_option( self::AUTH_OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$auth = array_merge( $defaults, $current, $values );
		update_option( self::AUTH_OPTION_NAME, $auth, false );

		// Mirror into the main settings array for backward compatibility.
		$this->update( $auth );
	}

	/**
	 * Delete stored OAuth credentials.
	 *
	 * @return void
	 */
	public function clear_auth() {
		$this->update_auth(
			array(
				'oauth_token'        => '',
				'oauth_token_secret' => '',
				'user_nsid'          => '',
				'username'           => '',
				'fullname'           => '',
			)
		);
	}

	/**
	 * Whether Flickr is connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return (bool) ( $this->get( 'oauth_token' ) && $this->get( 'oauth_token_secret' ) );
	}

	/**
	 * Whether the plugin has API credentials.
	 *
	 * @return bool
	 */
	public function has_api_credentials() {
		return (bool) ( $this->get( 'api_key' ) && $this->get( 'api_secret' ) );
	}

	/**
	 * Register settings screen.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'WP Flickr Portfolio', 'wp-flickr-portfolio' ),
			__( 'WP Flickr Portfolio', 'wp-flickr-portfolio' ),
			'manage_options',
			'wp-flickr-portfolio',
			array( $this, 'render_settings_page' ),
			'dashicons-format-gallery',
			58
		);

		add_submenu_page(
			'wp-flickr-portfolio',
			__( 'WP Flickr Portfolio Logs', 'wp-flickr-portfolio' ),
			__( 'Logs', 'wp-flickr-portfolio' ),
			'manage_options',
			'wp-flickr-portfolio-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Register option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'wpfp_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Sanitize settings before persistence.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		$current = $this->get_all();

		$sanitized = array(
			'api_key'            => isset( $input['api_key'] ) ? sanitize_text_field( (string) $input['api_key'] ) : $current['api_key'],
			'api_secret'         => isset( $input['api_secret'] ) ? sanitize_text_field( (string) $input['api_secret'] ) : $current['api_secret'],
			'oauth_token'        => $current['oauth_token'],
			'oauth_token_secret' => $current['oauth_token_secret'],
			'user_nsid'          => $current['user_nsid'],
			'username'           => $current['username'],
			'fullname'           => $current['fullname'],
			'cache_ttl'          => isset( $input['cache_ttl'] ) ? max( 60, absint( $input['cache_ttl'] ) ) : (int) $current['cache_ttl'],
			'default_mode'       => isset( $input['default_mode'] ) && in_array( $input['default_mode'], array( 'remote', 'media', 'auto', 'lazy' ), true ) ? $input['default_mode'] : $current['default_mode'],
			'default_per_page'   => isset( $input['default_per_page'] ) ? min( 500, max( 1, absint( $input['default_per_page'] ) ) ) : (int) $current['default_per_page'],
			'default_layout'     => isset( $input['default_layout'] ) ? $this->normalize_layout( $input['default_layout'] ) : $this->normalize_layout( $current['default_layout'] ),
			'allow_resized_fallback' => ! empty( $input['allow_resized_fallback'] ),
			'lightbox_selector'  => isset( $input['lightbox_selector'] ) ? sanitize_text_field( (string) $input['lightbox_selector'] ) : $current['lightbox_selector'],
		);

		return $sanitized;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options           = $this->get_all();
		$layout_value      = $this->normalize_layout( $options['default_layout'] );
		$connected_user    = $options['fullname'] ? $options['fullname'] : $options['username'];
		$callback_url      = $this->admin_page_url( array( 'wpfp_oauth_callback' => '1' ) );
		$sync_examples     = array(
			'[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001"]',
			'[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18"]',
			'[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" mode="auto"]',
		);
		$scheduled_sources = $this->get_scheduled_sources();
		$source_defaults   = array(
			'source'         => 'photoset',
			'user_id'        => (string) $options['user_nsid'],
			'photoset_id'    => '',
			'per_page'       => (int) $options['default_per_page'],
			'privacy_filter' => 1,
		);
		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'WP Flickr Portfolio', 'wp-flickr-portfolio' ); ?></h1>
			<?php $this->render_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpfp_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wpfp_api_key"><?php esc_html_e( 'Flickr API Key', 'wp-flickr-portfolio' ); ?></label></th>
							<td><input class="regular-text" id="wpfp_api_key" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]" type="text" value="<?php echo esc_attr( $options['api_key'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_api_secret"><?php esc_html_e( 'Flickr API Secret', 'wp-flickr-portfolio' ); ?></label></th>
							<td><input class="regular-text" id="wpfp_api_secret" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_secret]" type="password" value="<?php echo esc_attr( $options['api_secret'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'wp-flickr-portfolio' ); ?></label></th>
							<td><input id="wpfp_cache_ttl" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cache_ttl]" type="number" min="60" step="60" value="<?php echo esc_attr( (string) $options['cache_ttl'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_default_per_page"><?php esc_html_e( 'Default items per gallery', 'wp-flickr-portfolio' ); ?></label></th>
							<td><input id="wpfp_default_per_page" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_per_page]" type="number" min="1" max="500" value="<?php echo esc_attr( (string) $options['default_per_page'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_default_mode"><?php esc_html_e( 'Default gallery mode', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<select id="wpfp_default_mode" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_mode]">
									<option value="remote" <?php selected( $options['default_mode'], 'remote' ); ?>><?php esc_html_e( 'Remote Flickr URLs', 'wp-flickr-portfolio' ); ?></option>
									<option value="media" <?php selected( $options['default_mode'], 'media' ); ?>><?php esc_html_e( 'Imported media only', 'wp-flickr-portfolio' ); ?></option>
									<option value="auto" <?php selected( $options['default_mode'], 'auto' ); ?>><?php esc_html_e( 'Imported media fallback to remote', 'wp-flickr-portfolio' ); ?></option>
									<option value="lazy" <?php selected( $options['default_mode'], 'lazy' ); ?>><?php esc_html_e( 'Lazy sync attachments on first view', 'wp-flickr-portfolio' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_default_layout"><?php esc_html_e( 'Default remote layout', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<select id="wpfp_default_layout" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_layout]">
									<option value="grid" <?php selected( $layout_value, 'grid' ); ?>><?php esc_html_e( 'Grid', 'wp-flickr-portfolio' ); ?></option>
									<option value="masonry" <?php selected( $layout_value, 'masonry' ); ?>><?php esc_html_e( 'Masonry', 'wp-flickr-portfolio' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_allow_resized_fallback"><?php esc_html_e( 'Media import fallback', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<label for="wpfp_allow_resized_fallback">
									<input id="wpfp_allow_resized_fallback" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allow_resized_fallback]" type="checkbox" value="1" <?php checked( ! empty( $options['allow_resized_fallback'] ) ); ?>>
									<?php esc_html_e( 'Allow importing Flickr display-size images when the original file cannot be downloaded.', 'wp-flickr-portfolio' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Leave this disabled to preserve full resolution and EXIF by requiring the original Flickr file for Media Library imports.', 'wp-flickr-portfolio' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_lightbox_selector"><?php esc_html_e( 'Expected Meow Lightbox selector', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<input class="regular-text" id="wpfp_lightbox_selector" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[lightbox_selector]" type="text" value="<?php echo esc_attr( $options['lightbox_selector'] ); ?>">
								<p class="description"><?php esc_html_e( 'This plugin renders galleries with the .gallery class so Meow Lightbox can detect them using its default selector.', 'wp-flickr-portfolio' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Flickr Authorization', 'wp-flickr-portfolio' ); ?></h2>
			<p>
				<?php
				if ( $this->is_connected() ) {
					printf(
						/* translators: %s is the connected Flickr identity. */
						esc_html__( 'Connected as %s.', 'wp-flickr-portfolio' ),
						esc_html( $connected_user ? $connected_user : $options['user_nsid'] )
					);
				} else {
					esc_html_e( 'No Flickr account connected yet. Public galleries will still work if your API key is valid.', 'wp-flickr-portfolio' );
				}
				?>
			</p>
			<p>
				<?php esc_html_e( 'OAuth callback URL for your Flickr app:', 'wp-flickr-portfolio' ); ?>
				<br>
				<code><?php echo esc_html( $callback_url ); ?></code>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_begin_oauth' ), 'wpfp_begin_oauth' ) ); ?>"><?php esc_html_e( 'Connect Flickr', 'wp-flickr-portfolio' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_disconnect_oauth' ), 'wpfp_disconnect_oauth' ) ); ?>"><?php esc_html_e( 'Disconnect Flickr', 'wp-flickr-portfolio' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_clear_cache' ), 'wpfp_clear_cache' ) ); ?>"><?php esc_html_e( 'Clear Cached Flickr Data', 'wp-flickr-portfolio' ); ?></a>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Scheduled Sync', 'wp-flickr-portfolio' ); ?></h2>
			<p><?php esc_html_e( 'Keep selected Flickr sources up to date with a scheduled WP-Cron sync. Flickr documents a shared limit of 3600 queries per hour per API key, so this plugin spaces uncached sync requests and runs Run Now in the background.', 'wp-flickr-portfolio' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpfp_save_scheduled_source">
				<?php wp_nonce_field( 'wpfp_save_scheduled_source' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->render_source_fields( $source_defaults, 'scheduled' ); ?>
						<tr>
							<th scope="row"><label for="wpfp_scheduled_refresh_existing"><?php esc_html_e( 'Refresh existing imports', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<label for="wpfp_scheduled_refresh_existing">
									<input id="wpfp_scheduled_refresh_existing" name="refresh_existing" type="checkbox" value="1">
									<?php esc_html_e( 'Update metadata on attachments that are already in the Media Library during scheduled runs.', 'wp-flickr-portfolio' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpfp_scheduled_schedule"><?php esc_html_e( 'Schedule', 'wp-flickr-portfolio' ); ?></label></th>
							<td>
								<select id="wpfp_scheduled_schedule" name="schedule">
									<?php foreach ( WP_Flickr_Portfolio_Media_Sync::get_valid_schedules() as $key ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( WP_Flickr_Portfolio_Media_Sync::get_schedule_label( $key ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Add Scheduled Source', 'wp-flickr-portfolio' ), 'secondary' ); ?>
			</form>

			<?php if ( ! empty( $scheduled_sources ) ) : ?>
				<h3><?php esc_html_e( 'Scheduled Sources', 'wp-flickr-portfolio' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Items / Page', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Schedule', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Refresh Existing', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Last Run', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-flickr-portfolio' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-flickr-portfolio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scheduled_sources as $record ) : ?>
							<?php $base_key = isset( $record['base_key'] ) ? (string) $record['base_key'] : ''; ?>
							<?php if ( ! $base_key ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<tr>
								<td><?php echo esc_html( $this->format_source_label( $record ) ); ?></td>
								<td><?php echo esc_html( (string) ( isset( $record['per_page'] ) ? absint( $record['per_page'] ) : 0 ) ); ?></td>
								<td><?php echo esc_html( WP_Flickr_Portfolio_Media_Sync::get_schedule_label( $record['schedule'] ?? '' ) ); ?></td>
								<td><?php echo ! empty( $record['refresh_existing'] ) ? esc_html__( 'Yes', 'wp-flickr-portfolio' ) : esc_html__( 'No', 'wp-flickr-portfolio' ); ?></td>
								<td><?php echo esc_html( $this->format_last_run( $record ) ); ?></td>
								<td><?php echo esc_html( $this->format_status( $record ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_run_scheduled_source&base_key=' . rawurlencode( $base_key ) ), 'wpfp_run_scheduled_source_' . $base_key ) ); ?>"><?php esc_html_e( 'Run Now', 'wp-flickr-portfolio' ); ?></a>
									<?php if ( ! empty( $record['enabled'] ) ) : ?>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_toggle_scheduled_source&base_key=' . rawurlencode( $base_key ) . '&enabled=0' ), 'wpfp_toggle_scheduled_source_' . $base_key ) ); ?>"><?php esc_html_e( 'Disable', 'wp-flickr-portfolio' ); ?></a>
									<?php else : ?>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_toggle_scheduled_source&base_key=' . rawurlencode( $base_key ) . '&enabled=1' ), 'wpfp_toggle_scheduled_source_' . $base_key ) ); ?>"><?php esc_html_e( 'Enable', 'wp-flickr-portfolio' ); ?></a>
									<?php endif; ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_remove_scheduled_source&base_key=' . rawurlencode( $base_key ) ), 'wpfp_remove_scheduled_source_' . $base_key ) ); ?>"><?php esc_html_e( 'Remove', 'wp-flickr-portfolio' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Shortcode Examples', 'wp-flickr-portfolio' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;">
				<?php foreach ( $sync_examples as $example ) : ?>
					<li><code><?php echo esc_html( $example ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the plugin log viewer.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$level       = isset( $_GET['wpfp_log_level'] ) ? sanitize_key( wp_unslash( $_GET['wpfp_log_level'] ) ) : '';
		$component   = isset( $_GET['wpfp_log_component'] ) ? sanitize_key( wp_unslash( $_GET['wpfp_log_component'] ) ) : '';
		$per_page    = 25;
		$current_page = isset( $_GET['wpfp_paged'] ) ? max( 1, absint( $_GET['wpfp_paged'] ) ) : 1;
		$filters     = array(
			'level'     => $level,
			'component' => $component,
		);

		$total_items = $this->logger->get_total_count( $filters );
		$total_pages = (int) ceil( $total_items / $per_page );

		if ( $current_page > $total_pages && $total_pages > 0 ) {
			$current_page = $total_pages;
		}

		$offset    = ( $current_page - 1 ) * $per_page;
		$entries   = $this->logger->get_entries(
			array_merge(
				$filters,
				array(
					'limit'  => $per_page,
					'offset' => $offset,
				)
			)
		);
		$components = $this->logger->get_components();

		$showing_from = $total_items > 0 ? $offset + 1 : 0;
		$showing_to   = min( $offset + $per_page, $total_items );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Flickr Portfolio Logs', 'wp-flickr-portfolio' ); ?></h1>
			<?php $this->render_notice(); ?>

			<p><?php esc_html_e( 'Recent plugin activity is stored in WordPress so sync jobs, Flickr API retries, gallery mode decisions, and import failures can be inspected without server log access.', 'wp-flickr-portfolio' ); ?></p>

			<p>
				<a class="button" href="<?php echo esc_url( $this->admin_page_url( array( 'page' => 'wp-flickr-portfolio-logs' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'wp-flickr-portfolio' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->admin_page_url() ); ?>"><?php esc_html_e( 'Back to Settings', 'wp-flickr-portfolio' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_clear_logs' ), 'wpfp_clear_logs' ) ); ?>" onclick="return window.confirm('<?php echo esc_js( __( 'Clear all retained WP Flickr Portfolio log entries?', 'wp-flickr-portfolio' ) ); ?>');"><?php esc_html_e( 'Clear Logs', 'wp-flickr-portfolio' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpfp_export_logs&wpfp_log_level=' . rawurlencode( $level ) . '&wpfp_log_component=' . rawurlencode( $component ) ), 'wpfp_export_logs' ) ); ?>"><?php esc_html_e( 'Export Logs', 'wp-flickr-portfolio' ); ?></a>
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
				<input type="hidden" name="page" value="wp-flickr-portfolio-logs">
				<label for="wpfp_log_level" style="margin-right:8px;"><?php esc_html_e( 'Level', 'wp-flickr-portfolio' ); ?></label>
				<select id="wpfp_log_level" name="wpfp_log_level" style="margin-right:16px;">
					<option value=""><?php esc_html_e( 'All', 'wp-flickr-portfolio' ); ?></option>
					<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $candidate ) : ?>
						<option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $level, $candidate ); ?>><?php echo esc_html( ucfirst( $candidate ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="wpfp_log_component" style="margin-right:8px;"><?php esc_html_e( 'Component', 'wp-flickr-portfolio' ); ?></label>
				<select id="wpfp_log_component" name="wpfp_log_component" style="margin-right:16px;">
					<option value=""><?php esc_html_e( 'All', 'wp-flickr-portfolio' ); ?></option>
					<?php foreach ( $components as $candidate ) : ?>
						<option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $component, $candidate ); ?>><?php echo esc_html( $candidate ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'wp-flickr-portfolio' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( $total_items > 0 ) : ?>
				<p class="wpfp-showing-entries">
					<?php
					printf(
						/* translators: 1: starting entry number, 2: ending entry number, 3: total entries. */
						esc_html__( 'Showing %1$d - %2$d of %3$d entries', 'wp-flickr-portfolio' ),
						$showing_from,
						$showing_to,
						$total_items
					);
					?>
				</p>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'wp-flickr-portfolio' ); ?></th>
						<th><?php esc_html_e( 'Level', 'wp-flickr-portfolio' ); ?></th>
						<th><?php esc_html_e( 'Component', 'wp-flickr-portfolio' ); ?></th>
						<th><?php esc_html_e( 'Message', 'wp-flickr-portfolio' ); ?></th>
						<th><?php esc_html_e( 'Context', 'wp-flickr-portfolio' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No log entries matched the current filters.', 'wp-flickr-portfolio' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $entry['timestamp'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) ( $entry['level'] ?? 'info' ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['component'] ?? '' ) ); ?></td>
								<td>
									<?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?>
									<?php if ( ! empty( $entry['event'] ) ) : ?>
										<br>
										<code><?php echo esc_html( (string) $entry['event'] ); ?></code>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $this->format_log_context( isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array() ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %d: total number of log entries. */
								esc_html( _n( '%d item', '%d items', $total_items, 'wp-flickr-portfolio' ) ),
								$total_items
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							echo paginate_links(
								array(
									'base'      => add_query_arg( 'wpfp_paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $current_page,
									'add_args'  => array(
										'page'              => 'wp-flickr-portfolio-logs',
										'wpfp_log_level'    => $level,
										'wpfp_log_component' => $component,
									),
								)
							);
							?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Clear retained plugin logs.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}

		check_admin_referer( 'wpfp_clear_logs' );
		$this->logger->clear();

		wp_safe_redirect(
			$this->admin_page_url(
				array(
					'page'        => 'wp-flickr-portfolio-logs',
					'wpfp_notice' => 'logs_cleared',
				)
			)
		);
		exit;
	}

	/**
	 * Export all log entries as a downloadable text file.
	 *
	 * @return void
	 */
	public function handle_export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}

		check_admin_referer( 'wpfp_export_logs' );

		$level     = isset( $_GET['wpfp_log_level'] ) ? sanitize_key( wp_unslash( $_GET['wpfp_log_level'] ) ) : '';
		$component = isset( $_GET['wpfp_log_component'] ) ? sanitize_key( wp_unslash( $_GET['wpfp_log_component'] ) ) : '';

		$entries = $this->logger->get_entries(
			array(
				'level'     => $level,
				'component' => $component,
				'limit'     => WP_Flickr_Portfolio_Logger::MAX_ENTRIES,
			)
		);

		$lines = array();
		$date_format = 'Y-m-d H:i:s';

		foreach ( $entries as $entry ) {
			$timestamp = isset( $entry['timestamp'] ) ? gmdate( $date_format, absint( $entry['timestamp'] ) ) : '';
			$log_level = isset( $entry['level'] ) ? strtoupper( $entry['level'] ) : 'INFO';
			$comp      = isset( $entry['component'] ) ? $entry['component'] : '';
			$message   = isset( $entry['message'] ) ? $entry['message'] : '';
			$context   = '';
			$event     = isset( $entry['event'] ) ? $entry['event'] : '';

			if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) {
				$json = wp_json_encode( $entry['context'], JSON_UNESCAPED_SLASHES );
				if ( is_string( $json ) ) {
					$context = $json;
				}
			}

			$line = "[{$timestamp}] [{$log_level}] [{$comp}] {$message}";
			if ( $event ) {
				$line .= " | event: {$event}";
			}
			if ( $context ) {
				$line .= " | {$context}";
			}

			$lines[] = $line;
		}

		$filename = 'wp-flickr-portfolio-logs.txt';
		$content  = implode( PHP_EOL, $lines );

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content;
		exit;
	}

	/**
	 * Format structured log context for the admin table.
	 *
	 * @param array<string,mixed> $context Structured context.
	 * @return string
	 */
	private function format_log_context( array $context ) {
		if ( empty( $context ) ) {
			return '';
		}

		$json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Render shared source fields.
	 *
	 * @param array<string,mixed> $values Form defaults.
	 * @param string              $prefix DOM prefix.
	 * @return void
	 */
	private function render_source_fields( array $values, $prefix ) {
		?>
		<tr>
			<th scope="row"><label for="wpfp_<?php echo esc_attr( $prefix ); ?>_source"><?php esc_html_e( 'Source type', 'wp-flickr-portfolio' ); ?></label></th>
			<td>
				<select id="wpfp_<?php echo esc_attr( $prefix ); ?>_source" name="source">
					<option value="photoset" <?php selected( $values['source'], 'photoset' ); ?>><?php esc_html_e( 'Photoset / Album', 'wp-flickr-portfolio' ); ?></option>
					<option value="photostream" <?php selected( $values['source'], 'photostream' ); ?>><?php esc_html_e( 'Photostream', 'wp-flickr-portfolio' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wpfp_<?php echo esc_attr( $prefix ); ?>_user"><?php esc_html_e( 'User ID', 'wp-flickr-portfolio' ); ?></label></th>
			<td><input class="regular-text" id="wpfp_<?php echo esc_attr( $prefix ); ?>_user" name="user_id" type="text" value="<?php echo esc_attr( (string) $values['user_id'] ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpfp_<?php echo esc_attr( $prefix ); ?>_photoset"><?php esc_html_e( 'Photoset ID', 'wp-flickr-portfolio' ); ?></label></th>
			<td>
				<input class="regular-text" id="wpfp_<?php echo esc_attr( $prefix ); ?>_photoset" name="photoset_id" type="text" value="<?php echo esc_attr( (string) $values['photoset_id'] ); ?>">
				<p class="description"><?php esc_html_e( 'Required when the source type is Photoset / Album.', 'wp-flickr-portfolio' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wpfp_<?php echo esc_attr( $prefix ); ?>_limit"><?php esc_html_e( 'Items per page', 'wp-flickr-portfolio' ); ?></label></th>
			<td><input id="wpfp_<?php echo esc_attr( $prefix ); ?>_limit" name="per_page" type="number" min="1" max="500" value="<?php echo esc_attr( (string) absint( $values['per_page'] ) ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wpfp_<?php echo esc_attr( $prefix ); ?>_privacy"><?php esc_html_e( 'Privacy filter', 'wp-flickr-portfolio' ); ?></label></th>
			<td>
				<select id="wpfp_<?php echo esc_attr( $prefix ); ?>_privacy" name="privacy_filter">
					<option value="1" <?php selected( (string) $values['privacy_filter'], '1' ); ?>><?php esc_html_e( 'Public', 'wp-flickr-portfolio' ); ?></option>
					<option value="2" <?php selected( (string) $values['privacy_filter'], '2' ); ?>><?php esc_html_e( 'Friends', 'wp-flickr-portfolio' ); ?></option>
					<option value="3" <?php selected( (string) $values['privacy_filter'], '3' ); ?>><?php esc_html_e( 'Family', 'wp-flickr-portfolio' ); ?></option>
					<option value="4" <?php selected( (string) $values['privacy_filter'], '4' ); ?>><?php esc_html_e( 'Friends & family', 'wp-flickr-portfolio' ); ?></option>
					<option value="5" <?php selected( (string) $values['privacy_filter'], '5' ); ?>><?php esc_html_e( 'Private', 'wp-flickr-portfolio' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Return scheduled source records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_scheduled_sources() {
		$records = get_option( self::SCHEDULED_SOURCES_OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$scheduled = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) ) {
				$scheduled[] = $record;
			}
		}

		return $scheduled;
	}

	/**
	 * Format a source label for admin tables.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @return string
	 */
	private function format_source_label( array $record ) {
		$type = isset( $record['source'] ) && 'photostream' === $record['source']
			? __( 'Photostream', 'wp-flickr-portfolio' )
			: __( 'Photoset', 'wp-flickr-portfolio' );

		$pieces = array( $type );

		if ( ! empty( $record['photoset_id'] ) ) {
			$pieces[] = sprintf(
				/* translators: %s is a Flickr photoset ID. */
				__( 'Set %s', 'wp-flickr-portfolio' ),
				(string) $record['photoset_id']
			);
		}

		if ( ! empty( $record['user_id'] ) ) {
			$pieces[] = sprintf(
				/* translators: %s is a Flickr user NSID. */
				__( 'User %s', 'wp-flickr-portfolio' ),
				(string) $record['user_id']
			);
		}

		return implode( ' · ', $pieces );
	}

	/**
	 * Format last run timestamp.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @return string
	 */
	private function format_last_run( array $record ) {
		if ( empty( $record['last_run_at'] ) ) {
			return __( 'Never', 'wp-flickr-portfolio' );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $record['last_run_at'] ) );
	}

	/**
	 * Format status text for a scheduled source.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @return string
	 */
	private function format_status( array $record ) {
		$state = ! empty( $record['enabled'] ) ? __( 'Enabled', 'wp-flickr-portfolio' ) : __( 'Disabled', 'wp-flickr-portfolio' );

		if ( ! empty( $record['last_status'] ) ) {
			if ( 'queued' === $record['last_status'] ) {
				$state = __( 'Queued', 'wp-flickr-portfolio' );
			} elseif ( 'running' === $record['last_status'] ) {
				$state = __( 'Running', 'wp-flickr-portfolio' );
			}
		}

		if ( ! empty( $record['last_message'] ) ) {
			return $state . ' · ' . (string) $record['last_message'];
		}

		return $state;
	}

	/**
	 * Render query-string notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		if ( empty( $_GET['wpfp_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['wpfp_notice'] ) );
		$type   = 'success';
		$text   = '';

		switch ( $notice ) {
			case 'oauth_connected':
				$text = __( 'Flickr authorization completed.', 'wp-flickr-portfolio' );
				break;
			case 'oauth_disconnected':
				$text = __( 'Flickr authorization removed.', 'wp-flickr-portfolio' );
				break;
			case 'cache_cleared':
				$text = __( 'Cached Flickr responses cleared.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_saved':
				$text = __( 'Scheduled source saved.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_removed':
				$text = __( 'Scheduled source removed.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_enabled':
				$text = __( 'Scheduled source enabled.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_disabled':
				$text = __( 'Scheduled source disabled.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_run_complete':
				$text = __( 'Scheduled source synced successfully.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_run_queued':
				$text = __( 'Scheduled source queued to run in the background.', 'wp-flickr-portfolio' );
				break;
			case 'logs_cleared':
				$text = __( 'Plugin logs cleared.', 'wp-flickr-portfolio' );
				break;
			case 'oauth_error':
				$type = 'error';
				$text = __( 'Flickr authorization failed. Check your API credentials and callback URL.', 'wp-flickr-portfolio' );
				break;
			case 'sync_error':
				$type = 'error';
				$text = __( 'Media sync failed. Check the source values and Flickr authorization state.', 'wp-flickr-portfolio' );
				break;
			case 'schedule_error':
				$type = 'error';
				$text = __( 'Scheduled sync failed. Review the source settings and Flickr authorization state.', 'wp-flickr-portfolio' );
				break;
			case 'missing_credentials':
				$type = 'error';
				$text = __( 'Add a Flickr API key and secret before connecting.', 'wp-flickr-portfolio' );
				break;
		}

		if ( ! $text ) {
			return;
		}

		$detail = isset( $_GET['wpfp_notice_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['wpfp_notice_detail'] ) ) : '';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?><?php if ( $detail ) : ?> <strong><?php echo esc_html( $detail ); ?></strong><?php endif; ?></p></div>
		<?php
	}

	/**
	 * Return admin page URL.
	 *
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	public function admin_page_url( array $args = array() ) {
		if ( empty( $args['page'] ) ) {
			$args['page'] = 'wp-flickr-portfolio';
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
