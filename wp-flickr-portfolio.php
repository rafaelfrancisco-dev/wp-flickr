<?php
/**
 * Plugin Name: WP Flickr Portfolio
 * Plugin URI: https://github.com/rafaelfrancisco/wp-flickr
 * Description: Fetch Flickr albums and photostream images into WordPress, render remote galleries with a built-in EXIF lightbox.
 * Version: 0.16.1
 * Author: Rafael Francisco
 * Text Domain: wp-flickr-portfolio
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFLICKRPORTFOLIO_VERSION', '0.16.1' );
define( 'WPFLICKRPORTFOLIO_FILE', __FILE__ );
define( 'WPFLICKRPORTFOLIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFLICKRPORTFOLIO_URL', plugin_dir_url( __FILE__ ) );

require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-oauth.php';
require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-logger.php';
require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-api.php';
require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-shortcode.php';
require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-settings.php';
require_once WPFLICKRPORTFOLIO_DIR . 'includes/class-wp-flickr-portfolio-plugin.php';

register_activation_hook(
	WPFLICKRPORTFOLIO_FILE,
	static function() {
		$defaults = array(
			'cache_ttl'        => 86400,
			'default_per_page' => 24,
			'default_layout'   => 'grid',
		);

		$options = get_option( 'wpfp_settings', array() );
		update_option( 'wpfp_settings', wp_parse_args( $options, $defaults ) );
	}
);

WP_Flickr_Portfolio_Plugin::instance();
