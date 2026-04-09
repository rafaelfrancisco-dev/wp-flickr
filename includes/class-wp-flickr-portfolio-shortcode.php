<?php
/**
 * Shortcode rendering for Flickr galleries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Shortcode {

	/**
	 * Per-request gallery render counter.
	 *
	 * @var int
	 */
	private static $gallery_instance = 0;

	/**
	 * Settings manager.
	 *
	 * @var WP_Flickr_Portfolio_Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var WP_Flickr_Portfolio_API
	 */
	private $api;

	/**
	 * Media sync service.
	 *
	 * @var WP_Flickr_Portfolio_Media_Sync
	 */
	private $media_sync;

	/**
	 * Shared logger.
	 *
	 * @var WP_Flickr_Portfolio_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WP_Flickr_Portfolio_Settings   $settings Settings manager.
	 * @param WP_Flickr_Portfolio_API        $api API client.
	 * @param WP_Flickr_Portfolio_Media_Sync $media_sync Media sync service.
	 * @param WP_Flickr_Portfolio_Logger     $logger Logger service.
	 */
	public function __construct( WP_Flickr_Portfolio_Settings $settings, WP_Flickr_Portfolio_API $api, WP_Flickr_Portfolio_Media_Sync $media_sync, WP_Flickr_Portfolio_Logger $logger ) {
		$this->settings   = $settings;
		$this->api        = $api;
		$this->media_sync = $media_sync;
		$this->logger     = $logger;

		add_shortcode( 'flickr_portfolio', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ), 20 );
	}

	/**
	 * Return default gallery attributes.
	 *
	 * @return array<string,mixed>
	 */
	private function default_attributes() {
		return array(
			'source'         => 'photoset',
			'user_id'        => '',
			'photoset_id'    => '',
			'per_page'       => (string) $this->settings->get( 'default_per_page', 24 ),
			'page'           => '1',
			'privacy_filter' => '1',
			'mode'           => (string) $this->settings->get( 'default_mode', 'remote' ),
			'layout'         => (string) $this->settings->get( 'default_layout', 'grid' ),
			'columns'        => '3',
			'captions'       => 'true',
			'class'          => '',
			'title'          => '',
		);
	}

	/**
	 * Register the Gutenberg block.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'wp-flickr-portfolio/gallery',
			array(
				'api_version'     => 2,
				'editor_script'   => 'wp-flickr-portfolio-block',
				'style'           => 'wp-flickr-portfolio',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'source'         => array(
						'type'    => 'string',
						'default' => 'photoset',
					),
					'user_id'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'photoset_id'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'per_page'       => array(
						'type'    => 'number',
						'default' => (int) $this->settings->get( 'default_per_page', 24 ),
					),
					'page'           => array(
						'type'    => 'number',
						'default' => 1,
					),
					'privacy_filter' => array(
						'type'    => 'number',
						'default' => 1,
					),
					'mode'           => array(
						'type'    => 'string',
						'default' => (string) $this->settings->get( 'default_mode', 'remote' ),
					),
					'layout'         => array(
						'type'    => 'string',
						'default' => (string) $this->settings->get( 'default_layout', 'grid' ),
					),
					'columns'        => array(
						'type'    => 'number',
						'default' => 3,
					),
					'captions'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'title'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'class'          => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
	}

	/**
	 * Render the Gutenberg block via the shortcode renderer.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		if ( isset( $attributes['captions'] ) ) {
			$attributes['captions'] = $attributes['captions'] ? 'true' : 'false';
		}

		$markup = $this->render_shortcode( $attributes );

		if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
			return $markup;
		}

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes(),
			$markup
		);
	}

	/**
	 * Render the gallery shortcode.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( $this->default_attributes(), $atts, 'flickr_portfolio' );
		$instance = $this->next_gallery_instance();
		$query_arg = $this->pagination_query_arg( $instance );
		$atts['page'] = (string) $this->resolve_current_page( $atts, $query_arg );

		$source = $this->api->normalize_source_args( $atts );
		if ( is_wp_error( $source ) ) {
			$this->logger->error( 'shortcode', $source->get_error_message(), array( 'atts' => $atts ), 'normalize_error' );
			return $this->render_error( $source->get_error_message() );
		}

		$mode       = in_array( $atts['mode'], array( 'remote', 'media', 'auto', 'lazy' ), true ) ? $atts['mode'] : 'remote';
		$layout     = $this->settings->normalize_layout( $atts['layout'] );
		$captions   = filter_var( $atts['captions'], FILTER_VALIDATE_BOOLEAN );
		$captions   = 'masonry' === $layout ? false : $captions;
		$synced_ids = $this->media_sync->get_synced_attachment_ids( $source );
		$gallery    = null;
		$expected_photo_count = 0;
		$has_synced = ! empty( $synced_ids );
		$this->logger->debug(
			'shortcode',
			'Rendering Flickr gallery shortcode.',
			array(
				'mode'       => $mode,
				'layout'     => $layout,
				'has_synced' => $has_synced,
				'source'     => isset( $source['source'] ) ? (string) $source['source'] : '',
				'page'       => isset( $source['page'] ) ? absint( $source['page'] ) : 1,
				'per_page'   => isset( $source['per_page'] ) ? absint( $source['per_page'] ) : 0,
				'source_key' => $this->api->build_source_key( $source ),
			),
			'render_start'
		);

		if ( in_array( $mode, array( 'media', 'auto', 'lazy' ), true ) ) {
			$gallery = $this->api->get_gallery_data( $source );
			if ( ! is_wp_error( $gallery ) && ! empty( $gallery['photos'] ) && is_array( $gallery['photos'] ) ) {
				$expected_photo_count = count( $gallery['photos'] );
				$has_synced          = $has_synced && count( $synced_ids ) >= $expected_photo_count;
			}
		}

		if ( 'lazy' === $mode && ! $has_synced ) {
			$this->media_sync->queue_lazy_sync(
				$source,
				array(
					'gallery'          => is_array( $gallery ) ? $gallery : null,
					'prefer_original'  => false,
					'upgrade_original' => true,
					'refresh_existing' => false,
				)
			);
			$this->logger->info(
				'shortcode',
				'Lazy mode queued a background page import and will render remote markup until local attachments are ready.',
				array(
					'source_key'     => $this->api->build_source_key( $source ),
					'expected_count' => $expected_photo_count,
				),
				'lazy_background_queued'
			);
		}

		$should_show = in_array( $mode, array( 'media', 'auto', 'lazy' ), true ) && $has_synced;

		if ( 'media' === $mode && ! $has_synced ) {
			$this->logger->warning( 'shortcode', 'Media mode blocked because the requested page is not synced.', array( 'source_key' => $this->api->build_source_key( $source ) ), 'media_unsynced' );
			return $this->render_error( __( 'This gallery page has not been synced to the Media Library yet.', 'wp-flickr-portfolio' ) );
		}

		if ( $should_show ) {
			if ( is_array( $gallery ) && empty( $atts['title'] ) && ! empty( $gallery['title'] ) ) {
				$atts['title'] = (string) $gallery['title'];
			}
		}

		if ( $should_show ) {
			$this->logger->info( 'shortcode', 'Rendering synced attachment gallery.', array( 'mode' => $mode, 'source_key' => $this->api->build_source_key( $source ), 'attachments' => count( $synced_ids ) ), 'render_synced' );
			$atts['layout']   = $layout;
			$atts['captions'] = $captions ? 'true' : 'false';
			return $this->media_sync->render_synced_gallery( $synced_ids, $atts, $this->render_pagination( $gallery, $query_arg ) );
		}

		if ( ! is_array( $gallery ) ) {
			$gallery = $this->api->get_gallery_data( $source );
		}
		if ( is_wp_error( $gallery ) ) {
			$this->logger->error( 'shortcode', $gallery->get_error_message(), array( 'mode' => $mode, 'source_key' => $this->api->build_source_key( $source ) ), 'render_remote_error' );
			return $this->render_error( $gallery->get_error_message() );
		}

		if ( empty( $gallery['photos'] ) || ! is_array( $gallery['photos'] ) ) {
			$this->logger->warning( 'shortcode', 'Remote render returned no Flickr photos.', array( 'mode' => $mode, 'source_key' => $this->api->build_source_key( $source ) ), 'render_empty' );
			return $this->render_error( __( 'No Flickr images were returned for this gallery.', 'wp-flickr-portfolio' ) );
		}

		$this->logger->info( 'shortcode', 'Rendering remote Flickr gallery.', array( 'mode' => $mode, 'source_key' => $this->api->build_source_key( $source ), 'photos' => count( $gallery['photos'] ) ), 'render_remote' );

		wp_enqueue_style( 'wp-flickr-portfolio' );
		$is_lazy_pending = 'lazy' === $mode && ! $has_synced;
		if ( $is_lazy_pending ) {
			$this->enqueue_lazy_frontend_script();
		}

		$columns  = max( 1, min( 6, absint( $atts['columns'] ) ) );
		$title    = $atts['title'] ? (string) $atts['title'] : (string) ( $gallery['title'] ?? '' );
		$pagination_markup = $this->render_pagination( $gallery, $query_arg );
		$classes  = array(
			'gallery',
			'wpfp-gallery',
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

		$classes = array_values( array_unique( array_filter( $classes ) ) );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-wpfp-source="<?php echo esc_attr( (string) $source['source'] ); ?>"<?php echo $is_lazy_pending ? ' data-wpfp-mode="lazy" data-wpfp-lazy-state="pending" data-wpfp-source-config="' . esc_attr( wp_json_encode( $source ) ) . '" data-wpfp-expected-count="' . esc_attr( (string) count( $gallery['photos'] ) ) . '"' : ''; ?>>
			<?php if ( $title ) : ?>
				<h3 class="wpfp-gallery-title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>
			<div class="wpfp-gallery-grid">
				<?php foreach ( $gallery['photos'] as $photo ) : ?>
					<?php
					if ( ! is_array( $photo ) || ( empty( $photo['thumbnail_url'] ) && empty( $photo['display_url'] ) && empty( $photo['full_url'] ) ) ) {
						continue;
					}

					$image_title  = ! empty( $photo['title'] ) ? (string) $photo['title'] : (string) $photo['id'];
					$caption      = trim( wp_strip_all_tags( (string) ( $photo['description'] ?? '' ) ) );
					$full_url     = ! empty( $photo['full_url'] ) ? (string) $photo['full_url'] : ( ! empty( $photo['display_url'] ) ? (string) $photo['display_url'] : (string) $photo['thumbnail_url'] );
					$image_url    = ! empty( $photo['display_url'] ) ? (string) $photo['display_url'] : ( ! empty( $photo['thumbnail_url'] ) ? (string) $photo['thumbnail_url'] : (string) $full_url );
					$image_width  = ! empty( $photo['width'] ) ? absint( $photo['width'] ) : 0;
					$image_height = ! empty( $photo['height'] ) ? absint( $photo['height'] ) : 0;
					?>
					<figure class="gallery-item wpfp-gallery-item">
						<div class="gallery-icon landscape">
							<a href="<?php echo esc_url( $full_url ); ?>" data-flickr-id="<?php echo esc_attr( (string) $photo['id'] ); ?>" data-caption="<?php echo esc_attr( $caption ? $caption : $image_title ); ?>" data-title="<?php echo esc_attr( $image_title ); ?>">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $image_title ); ?>" loading="lazy"<?php echo $image_width ? ' width="' . esc_attr( (string) $image_width ) . '"' : ''; ?><?php echo $image_height ? ' height="' . esc_attr( (string) $image_height ) . '"' : ''; ?>>
							</a>
						</div>
						<?php if ( $captions && ( $image_title || $caption ) ) : ?>
							<figcaption class="wp-caption-text gallery-caption">
								<strong><?php echo esc_html( $image_title ); ?></strong>
								<?php if ( $caption ) : ?>
									<span><?php echo esc_html( $caption ); ?></span>
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
	 * Enqueue the frontend lazy-sync updater.
	 *
	 * @return void
	 */
	private function enqueue_lazy_frontend_script() {
		wp_enqueue_script( 'wp-flickr-portfolio-lazy' );
		wp_localize_script(
			'wp-flickr-portfolio-lazy',
			'WPFlickrPortfolioLazy',
			array(
				'statusUrl'    => admin_url( 'admin-ajax.php' ),
				'pollInterval' => 2500,
			)
		);
	}

	/**
	 * Return the next gallery instance ID for the current page render.
	 *
	 * @return int
	 */
	private function next_gallery_instance() {
		self::$gallery_instance++;
		return self::$gallery_instance;
	}

	/**
	 * Build the request query arg used for one gallery instance.
	 *
	 * @param int $instance Gallery instance.
	 * @return string
	 */
	private function pagination_query_arg( $instance ) {
		return 'wpfp_gallery_' . max( 1, absint( $instance ) ) . '_page';
	}

	/**
	 * Resolve the current page from request query args or shortcode defaults.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @param string              $query_arg Gallery query arg.
	 * @return int
	 */
	private function resolve_current_page( array $atts, $query_arg ) {
		if ( isset( $_GET[ $query_arg ] ) ) {
			return max( 1, absint( wp_unslash( $_GET[ $query_arg ] ) ) );
		}

		return isset( $atts['page'] ) ? max( 1, absint( $atts['page'] ) ) : 1;
	}

	/**
	 * Render pagination links for a gallery payload.
	 *
	 * @param array<string,mixed>|null $gallery Gallery payload.
	 * @param string                   $query_arg Gallery query arg.
	 * @return string
	 */
	private function render_pagination( $gallery, $query_arg ) {
		if ( ! is_array( $gallery ) ) {
			return '';
		}

		$total_pages  = isset( $gallery['pages'] ) ? max( 1, absint( $gallery['pages'] ) ) : 1;
		$current_page = isset( $gallery['page'] ) ? max( 1, absint( $gallery['page'] ) ) : 1;

		if ( $total_pages < 2 ) {
			return '';
		}

		$current_page = min( $current_page, $total_pages );
		$base_url     = $this->current_request_url();

		if ( ! $base_url ) {
			return '';
		}

		ob_start();
		?>
		<nav class="wpfp-pagination" aria-label="<?php esc_attr_e( 'Gallery pagination', 'wp-flickr-portfolio' ); ?>">
			<div class="wpfp-pagination-list">
				<?php if ( $current_page > 1 ) : ?>
					<a class="wpfp-page-link wpfp-page-prev" href="<?php echo esc_url( $this->build_page_url( $base_url, $query_arg, $current_page - 1 ) ); ?>">
						<?php esc_html_e( 'Previous', 'wp-flickr-portfolio' ); ?>
					</a>
				<?php endif; ?>

				<?php for ( $page = 1; $page <= $total_pages; $page++ ) : ?>
					<?php if ( $page === $current_page ) : ?>
						<span class="wpfp-page-link wpfp-page-current" aria-current="page"><?php echo esc_html( (string) $page ); ?></span>
					<?php else : ?>
						<a class="wpfp-page-link" href="<?php echo esc_url( $this->build_page_url( $base_url, $query_arg, $page ) ); ?>"><?php echo esc_html( (string) $page ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>

				<?php if ( $current_page < $total_pages ) : ?>
					<a class="wpfp-page-link wpfp-page-next" href="<?php echo esc_url( $this->build_page_url( $base_url, $query_arg, $current_page + 1 ) ); ?>">
						<?php esc_html_e( 'Next', 'wp-flickr-portfolio' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</nav>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Return the current request URL.
	 *
	 * @return string
	 */
	private function current_request_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}

	/**
	 * Build a pagination URL for one gallery instance.
	 *
	 * @param string $base_url Current request URL.
	 * @param string $query_arg Gallery query arg.
	 * @param int    $page Page number.
	 * @return string
	 */
	private function build_page_url( $base_url, $query_arg, $page ) {
		$page = max( 1, absint( $page ) );

		if ( 1 === $page ) {
			return (string) remove_query_arg( $query_arg, $base_url );
		}

		return (string) add_query_arg( $query_arg, $page, $base_url );
	}

	/**
	 * Render a user-facing error block.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function render_error( $message ) {
		return sprintf(
			'<div class="wpfp-error">%s</div>',
			esc_html( $message )
		);
	}
}
