<?php
/**
 * Main bootstrap for the WP Flickr Portfolio plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WP_Flickr_Portfolio_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Logger service.
	 *
	 * @var WP_Flickr_Portfolio_Logger
	 */
	private $logger;

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
	 * Media sync service.
	 *
	 * @var WP_Flickr_Portfolio_Media_Sync
	 */
	private $media_sync;

	/**
	 * Shortcode renderer.
	 *
	 * @var WP_Flickr_Portfolio_Shortcode
	 */
	private $shortcode;

	/**
	 * Return singleton instance.
	 *
	 * @return WP_Flickr_Portfolio_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Construct plugin services.
	 */
	private function __construct() {
		$this->logger     = new WP_Flickr_Portfolio_Logger();
		$this->settings   = new WP_Flickr_Portfolio_Settings( $this->logger );
		$this->api        = new WP_Flickr_Portfolio_API( $this->settings, $this->logger );
		$this->media_sync = new WP_Flickr_Portfolio_Media_Sync( $this->settings, $this->api, $this->logger );
		$this->shortcode  = new WP_Flickr_Portfolio_Shortcode( $this->settings, $this->api, $this->media_sync, $this->logger );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-flickr-portfolio', false, dirname( plugin_basename( WPFLICKRPORTFOLIO_FILE ) ) . '/languages' );
	}

	/**
	 * Register frontend assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'wp-flickr-portfolio',
			WPFLICKRPORTFOLIO_URL . 'assets/wp-flickr-portfolio.css',
			array(),
			WPFLICKRPORTFOLIO_VERSION
		);

		wp_register_script(
			'wp-flickr-portfolio-block',
			WPFLICKRPORTFOLIO_URL . 'assets/wp-flickr-portfolio-block.js',
			array(
				'wp-blocks',
				'wp-block-editor',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-server-side-render',
			),
			WPFLICKRPORTFOLIO_VERSION,
			true
		);

		wp_register_script(
			'wp-flickr-portfolio-lazy',
			WPFLICKRPORTFOLIO_URL . 'assets/wp-flickr-portfolio-lazy.js',
			array(),
			WPFLICKRPORTFOLIO_VERSION,
			true
		);
	}
}
