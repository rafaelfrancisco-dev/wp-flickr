<?php
/**
 * Flickr API client and caching logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_API {

	/**
	 * Maximum number of Flickr request attempts for transient rate limits.
	 */
	const MAX_REQUEST_ATTEMPTS = 3;

	/**
	 * Conservative delay between Flickr requests made by this plugin.
	 *
	 * Flickr documents a shared limit of 3600 queries per hour per API key,
	 * and original-file imports can also trigger throttling when requests are
	 * fired too close together, so the plugin spaces both REST and media-import
	 * traffic through the same shared slot.
	 */
	const REQUEST_PACE_INTERVAL = 3;

	/**
	 * Maximum Retry-After delay we will honor in a synchronous request.
	 */
	const MAX_RETRY_AFTER = 15;

	/**
	 * Shared transient key for site-wide Flickr request pacing.
	 */
	const REQUEST_PACE_KEY = 'wpfp_flickr_request_pace';

	/**
	 * Shared settings manager.
	 *
	 * @var WP_Flickr_Portfolio_Settings
	 */
	private $settings;

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
	 * @param WP_Flickr_Portfolio_Logger   $logger Logger service.
	 */
	public function __construct( WP_Flickr_Portfolio_Settings $settings, WP_Flickr_Portfolio_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		add_action( 'admin_post_wpfp_begin_oauth', array( $this, 'handle_begin_oauth' ) );
		add_action( 'admin_post_wpfp_disconnect_oauth', array( $this, 'handle_disconnect_oauth' ) );
		add_action( 'admin_post_wpfp_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );
		add_action( 'wp_ajax_wpfp_get_exif', array( $this, 'handle_get_exif' ) );
		add_action( 'wp_ajax_nopriv_wpfp_get_exif', array( $this, 'handle_get_exif' ) );
	}

	/**
	 * Return a normalized gallery payload for a shortcode source.
	 *
	 * @param array<string,mixed> $args Shortcode or sync arguments.
	 * @param bool                $bypass_cache Whether to bypass transients.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_gallery_data( array $args, $bypass_cache = false ) {
		$normalized = $this->normalize_source_args( $args );

		if ( is_wp_error( $normalized ) ) {
			$this->logger->error( 'api', $normalized->get_error_message(), array( 'args' => $args ), 'normalize_error' );
			return $normalized;
		}

		$cache_key = $this->cache_key( $normalized );
		$ttl       = (int) $this->settings->get( 'cache_ttl', 86400 );

		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				$this->logger->debug( 'api', 'Using cached Flickr gallery payload.', $this->source_log_context( $normalized ), 'cache_hit' );
				return $cached;
			}
		}

		$this->logger->debug(
			'api',
			$bypass_cache ? 'Fetching Flickr gallery without cache.' : 'Fetching Flickr gallery after cache miss.',
			$this->source_log_context( $normalized ),
			$bypass_cache ? 'cache_bypass' : 'cache_miss'
		);

		$payload = $this->fetch_gallery_data( $normalized );
		if ( is_wp_error( $payload ) ) {
			$this->logger->error(
				'api',
				$payload->get_error_message(),
				array_merge( $this->source_log_context( $normalized ), array( 'code' => $payload->get_error_code() ) ),
				'fetch_error'
			);
			return $payload;
		}

		set_transient( $cache_key, $payload, max( 60, $ttl ) );

		$known_keys   = get_option( 'wpfp_cache_keys', array() );
		$known_keys[] = $cache_key;
		update_option( 'wpfp_cache_keys', array_values( array_unique( $known_keys ) ), false );
		$this->logger->info(
			'api',
			'Fetched Flickr gallery payload.',
			array_merge(
				$this->source_log_context( $normalized ),
				array(
					'total' => isset( $payload['total'] ) ? absint( $payload['total'] ) : 0,
					'pages' => isset( $payload['pages'] ) ? absint( $payload['pages'] ) : 1,
				)
			),
			'fetch_success'
		);

		return $payload;
	}

	/**
	 * Wait until the next shared Flickr request slot is available.
	 *
	 * @return void
	 */
	public function pace_flickr_request() {
		$this->pace_request();
	}

	/**
	 * Calculate a bounded retry delay for a Flickr rate-limit header value.
	 *
	 * @param string|int $retry_after Retry-After header value.
	 * @param int        $attempt Attempt number starting at 1.
	 * @return int
	 */
	public function rate_limit_retry_delay_from_header( $retry_after, $attempt ) {
		return $this->bounded_retry_delay( $retry_after, $attempt );
	}

	/**
	 * Fetch a single page of photos without reading or writing the transient cache.
	 *
	 * Intended for use by the background importer which pages through all results
	 * and must not pollute the display cache with import-specific arguments.
	 *
	 * @param array<string,mixed> $args Source arguments (source, user_id, photoset_id, per_page, page, privacy_filter).
	 * @return array<string,mixed>|\WP_Error Payload with 'photos', 'total', 'pages', 'page', 'per_page'.
	 */
	public function get_photos_page( array $args ) {
		$normalized = $this->normalize_source_args( $args );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return $this->fetch_gallery_data( $normalized );
	}

	/**
	 * Fetch the list of photosets for a Flickr user.
	 *
	 * Requires the plugin to be connected via OAuth. Falls back to the
	 * authenticated user's NSID when $user_id is empty.
	 *
	 * @param string $user_id Flickr NSID. Leave empty to use the connected account.
	 * @return array<int,array<string,mixed>>|\WP_Error Each item: {id, title, photos}.
	 */
	public function get_photosets( $user_id = '' ) {
		$user = sanitize_text_field( (string) $user_id );
		if ( empty( $user ) ) {
			$user = (string) $this->settings->get( 'user_nsid' );
		}

		if ( empty( $user ) ) {
			return new WP_Error( 'wpfp_missing_user', __( 'A Flickr user_id is required to list photosets.', 'wp-flickr-portfolio' ) );
		}

		$params = array(
			'method'         => 'flickr.photosets.getList',
			'format'         => 'json',
			'nojsoncallback' => '1',
			'user_id'        => $user,
			'per_page'       => '500',
		);

		$response = $this->request( $params, $this->settings->is_connected() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_sets = isset( $response['photosets']['photoset'] ) && is_array( $response['photosets']['photoset'] )
			? $response['photosets']['photoset']
			: array();

		$sets = array();
		foreach ( $raw_sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			$title = '';
			if ( isset( $set['title'] ) ) {
				$title = is_array( $set['title'] ) ? (string) ( $set['title']['_content'] ?? '' ) : (string) $set['title'];
			}

			$sets[] = array(
				'id'     => isset( $set['id'] ) ? (string) $set['id'] : '',
				'title'  => $title,
				'photos' => isset( $set['photos'] ) ? (int) $set['photos'] : 0,
			);
		}

		usort( $sets, static function ( $a, $b ) {
			return strcmp( $a['title'], $b['title'] );
		} );

		return $sets;
	}

	/**
	 * Normalize source attributes.
	 *
	 * @param array<string,mixed> $args Source arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize_source_args( array $args ) {
		$source = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'photoset';
		$user   = isset( $args['user_id'] ) ? sanitize_text_field( (string) $args['user_id'] ) : '';

		if ( empty( $user ) && $this->settings->get( 'user_nsid' ) ) {
			$user = (string) $this->settings->get( 'user_nsid' );
		}

		$normalized = array(
			'source'         => in_array( $source, array( 'photoset', 'photostream' ), true ) ? $source : 'photoset',
			'user_id'        => $user,
			'photoset_id'    => isset( $args['photoset_id'] ) ? sanitize_text_field( (string) $args['photoset_id'] ) : '',
			'per_page'       => isset( $args['per_page'] ) ? min( 500, max( 1, absint( $args['per_page'] ) ) ) : (int) $this->settings->get( 'default_per_page', 24 ),
			'page'           => isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1,
			'privacy_filter' => isset( $args['privacy_filter'] ) ? min( 5, max( 1, absint( $args['privacy_filter'] ) ) ) : 1,
		);

		if ( 'photoset' === $normalized['source'] && empty( $normalized['photoset_id'] ) ) {
			return new WP_Error( 'wpfp_missing_photoset', __( 'A photoset_id is required for source="photoset".', 'wp-flickr-portfolio' ) );
		}

		if ( empty( $normalized['user_id'] ) ) {
			return new WP_Error( 'wpfp_missing_user', __( 'A Flickr user_id is required unless you have authenticated the plugin against your own account.', 'wp-flickr-portfolio' ) );
		}

		return $normalized;
	}

	/**
	 * Build a stable key for a source.
	 *
	 * @param array<string,mixed> $source Source args.
	 * @return string
	 */
	public function build_source_key( array $source ) {
		$parts = array(
			isset( $source['source'] ) ? (string) $source['source'] : '',
			isset( $source['user_id'] ) ? (string) $source['user_id'] : '',
			isset( $source['photoset_id'] ) ? (string) $source['photoset_id'] : '',
			isset( $source['per_page'] ) ? (string) $source['per_page'] : '',
			isset( $source['page'] ) ? (string) $source['page'] : '',
			isset( $source['privacy_filter'] ) ? (string) $source['privacy_filter'] : '',
		);

		return 'wpfp_source_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Build a stable key for a logical source independent of page.
	 *
	 * @param array<string,mixed> $source Source args.
	 * @return string
	 */
	public function build_collection_key( array $source ) {
		$parts = array(
			isset( $source['source'] ) ? (string) $source['source'] : '',
			isset( $source['user_id'] ) ? (string) $source['user_id'] : '',
			isset( $source['photoset_id'] ) ? (string) $source['photoset_id'] : '',
			isset( $source['per_page'] ) ? (string) $source['per_page'] : '',
			isset( $source['privacy_filter'] ) ? (string) $source['privacy_filter'] : '',
		);

		return 'wpfp_collection_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Clear all known transients.
	 *
	 * @return void
	 */
	public function clear_cache() {
		$keys = get_option( 'wpfp_cache_keys', array() );
		if ( ! is_array( $keys ) ) {
			return;
		}

		foreach ( $keys as $key ) {
			delete_transient( $key );
		}

		delete_option( 'wpfp_cache_keys' );
		$this->logger->info( 'api', 'Cleared cached Flickr responses.', array( 'keys' => count( $keys ) ), 'cache_cleared' );
	}

	/**
	 * Handle Flickr OAuth start.
	 *
	 * @return void
	 */
	public function handle_begin_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}

		check_admin_referer( 'wpfp_begin_oauth' );

		if ( ! $this->settings->has_api_credentials() ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'missing_credentials' ) ) );
			exit;
		}

		$callback = $this->settings->admin_page_url(
			array(
				'wpfp_oauth_callback' => '1',
			)
		);
		$signed   = WP_Flickr_Portfolio_OAuth::signed_request(
			'POST',
			WP_Flickr_Portfolio_OAuth::REQUEST_TOKEN_URL,
			(string) $this->settings->get( 'api_key' ),
			(string) $this->settings->get( 'api_secret' ),
			array(),
			'',
			'',
			array(
				'oauth_callback' => $callback,
			)
		);

		$response = wp_remote_post(
			WP_Flickr_Portfolio_OAuth::REQUEST_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => $signed['headers'],
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_error' ) ) );
			exit;
		}

		$body = wp_remote_retrieve_body( $response );
		parse_str( $body, $token_data );

		if ( empty( $token_data['oauth_token'] ) || empty( $token_data['oauth_token_secret'] ) ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_error' ) ) );
			exit;
		}

		update_option( WP_Flickr_Portfolio_Settings::REQUEST_TOKEN_OPTION, $token_data, false );

		$url = add_query_arg(
			array(
				'oauth_token' => $token_data['oauth_token'],
				'perms'       => 'read',
			),
			WP_Flickr_Portfolio_OAuth::AUTHORIZE_URL
		);

		// Flickr authorization is an external redirect, so wp_safe_redirect() would reject it.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @return void
	 */
	public function maybe_handle_oauth_callback() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_GET['page'] ) || 'wp-flickr-portfolio' !== $_GET['page'] || empty( $_GET['wpfp_oauth_callback'] ) ) {
			return;
		}

		$stored_request = get_option( WP_Flickr_Portfolio_Settings::REQUEST_TOKEN_OPTION, array() );
		$verifier       = isset( $_GET['oauth_verifier'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_verifier'] ) ) : '';
		$token          = isset( $_GET['oauth_token'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_token'] ) ) : '';

		if ( empty( $stored_request['oauth_token'] ) || empty( $stored_request['oauth_token_secret'] ) || $token !== $stored_request['oauth_token'] || ! $verifier ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_error' ) ) );
			exit;
		}

		$signed = WP_Flickr_Portfolio_OAuth::signed_request(
			'POST',
			WP_Flickr_Portfolio_OAuth::ACCESS_TOKEN_URL,
			(string) $this->settings->get( 'api_key' ),
			(string) $this->settings->get( 'api_secret' ),
			array(),
			$stored_request['oauth_token'],
			$stored_request['oauth_token_secret'],
			array(
				'oauth_verifier' => $verifier,
			)
		);

		$response = wp_remote_post(
			WP_Flickr_Portfolio_OAuth::ACCESS_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => $signed['headers'],
			)
		);

		delete_option( WP_Flickr_Portfolio_Settings::REQUEST_TOKEN_OPTION );

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_error' ) ) );
			exit;
		}

		parse_str( wp_remote_retrieve_body( $response ), $token_data );

		if ( empty( $token_data['oauth_token'] ) || empty( $token_data['oauth_token_secret'] ) ) {
			wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_error' ) ) );
			exit;
		}

		$this->settings->update_auth(
			array(
				'oauth_token'        => sanitize_text_field( (string) $token_data['oauth_token'] ),
				'oauth_token_secret' => sanitize_text_field( (string) $token_data['oauth_token_secret'] ),
				'user_nsid'          => isset( $token_data['user_nsid'] ) ? sanitize_text_field( (string) $token_data['user_nsid'] ) : '',
				'username'           => isset( $token_data['username'] ) ? sanitize_text_field( (string) $token_data['username'] ) : '',
				'fullname'           => isset( $token_data['fullname'] ) ? sanitize_text_field( (string) $token_data['fullname'] ) : '',
			)
		);

		wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_connected' ) ) );
		exit;
	}

	/**
	 * Disconnect Flickr OAuth credentials.
	 *
	 * @return void
	 */
	public function handle_disconnect_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}

		check_admin_referer( 'wpfp_disconnect_oauth' );
		$this->settings->clear_auth();

		wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'oauth_disconnected' ) ) );
		exit;
	}

	/**
	 * Clear plugin cache from admin.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-flickr-portfolio' ) );
		}

		check_admin_referer( 'wpfp_clear_cache' );
		$this->clear_cache();

		wp_safe_redirect( $this->settings->admin_page_url( array( 'wpfp_notice' => 'cache_cleared' ) ) );
		exit;
	}

	/**
	 * AJAX handler for fetching EXIF data for a single Flickr photo.
	 *
	 * @return void
	 */
	public function handle_get_exif() {
		check_ajax_referer( 'wpfp_get_exif', 'nonce' );

		$photo_id = isset( $_POST['photo_id'] ) ? sanitize_text_field( wp_unslash( $_POST['photo_id'] ) ) : '';
		if ( empty( $photo_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing photo ID.', 'wp-flickr-portfolio' ) ), 400 );
		}

		$cache_key = 'wpfp_exif_' . md5( $photo_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$exif = $this->fetch_photo_exif( $photo_id );
		if ( is_wp_error( $exif ) ) {
			wp_send_json_error( array( 'message' => $exif->get_error_message() ), 500 );
		}

		set_transient( $cache_key, $exif, max( 3600, (int) $this->settings->get( 'cache_ttl', 86400 ) ) );

		$known_keys   = get_option( 'wpfp_cache_keys', array() );
		$known_keys[] = $cache_key;
		update_option( 'wpfp_cache_keys', array_values( array_unique( $known_keys ) ), false );

		wp_send_json_success( $exif );
	}

	/**
	 * Fetch EXIF data from Flickr for a single photo.
	 *
	 * @param string $photo_id Flickr photo ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function fetch_photo_exif( $photo_id ) {
		$params = array(
			'method'         => 'flickr.photos.getExif',
			'format'         => 'json',
			'nojsoncallback' => '1',
			'photo_id'       => (string) $photo_id,
		);

		$response = $this->request( $params, $this->settings->is_connected() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$exif_tags  = isset( $response['photo']['exif'] ) && is_array( $response['photo']['exif'] ) ? $response['photo']['exif'] : array();
		$owner      = isset( $response['photo']['owner'] ) ? (string) $response['photo']['owner'] : '';
		$location   = $this->fetch_photo_location( $photo_id );

		$result = array(
			'camera'    => $this->find_exif_tag( $exif_tags, 'Camera' )
				?: rtrim( trim( $this->find_exif_tag( $exif_tags, 'Make' ) . ' ' . $this->find_exif_tag( $exif_tags, 'Model' ) ) ),
			'lens'      => $this->find_exif_tag( $exif_tags, 'LensModel' ),
			'aperture'  => $this->find_exif_tag( $exif_tags, 'FNumber' ) ?: $this->find_exif_tag( $exif_tags, 'Aperture' ),
			'shutter'   => $this->find_exif_tag( $exif_tags, 'ExposureTime' ),
			'iso'       => $this->find_exif_tag( $exif_tags, 'ISO' ),
			'focal'     => $this->find_exif_tag( $exif_tags, 'FocalLength' ),
			'latitude'  => '',
			'longitude' => '',
			'flickrUrl' => $owner ? 'https://www.flickr.com/photos/' . $owner . '/' . $photo_id . '/' : '',
		);

		if ( ! is_wp_error( $location ) && is_array( $location ) ) {
			$result['latitude']  = (string) ( $location['latitude'] ?? '' );
			$result['longitude'] = (string) ( $location['longitude'] ?? '' );
		}

		return array_filter( $result, static function( $value ) {
			return '' !== $value;
		});
	}

	/**
	 * Fetch geo location for a photo.
	 *
	 * @param string $photo_id Flickr photo ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function fetch_photo_location( $photo_id ) {
		$params = array(
			'method'         => 'flickr.photos.geo.getLocation',
			'format'         => 'json',
			'nojsoncallback' => '1',
			'photo_id'       => (string) $photo_id,
		);

		$response = $this->request( $params, $this->settings->is_connected() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$location = isset( $response['photo']['location'] ) && is_array( $response['photo']['location'] ) ? $response['photo']['location'] : array();

		return array(
			'latitude'  => isset( $location['latitude'] ) ? (string) $location['latitude'] : '',
			'longitude' => isset( $location['longitude'] ) ? (string) $location['longitude'] : '',
		);
	}

	/**
	 * Find a tag value from Flickr EXIF response.
	 *
	 * @param array<int,array<string,mixed>> $tags EXIF tags array.
	 * @param string                         $tag_name Tag name to find.
	 * @return string
	 */
	private function find_exif_tag( array $tags, $tag_name ) {
		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}

			$raw_tag = isset( $tag['tag'] ) ? (string) $tag['tag'] : '';
			if ( strtolower( $raw_tag ) === strtolower( $tag_name ) ) {
				return isset( $tag['raw']['_content'] ) ? (string) $tag['raw']['_content'] : '';
			}
		}

		return '';
	}

	/**
	 * Fetch gallery data from Flickr.
	 *
	 * @param array<string,mixed> $source Normalized source arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function fetch_gallery_data( array $source ) {
		if ( $source['privacy_filter'] > 1 && ! $this->settings->is_connected() ) {
			return new WP_Error(
				'wpfp_auth_required',
				__( 'Private Flickr content requires a connected Flickr account with read permission.', 'wp-flickr-portfolio' )
			);
		}

		$method = 'photostream' === $source['source'] && ! $this->settings->is_connected()
			? 'flickr.people.getPublicPhotos'
			: ( 'photostream' === $source['source'] ? 'flickr.people.getPhotos' : 'flickr.photosets.getPhotos' );

		$params = array(
			'method'         => $method,
			'format'         => 'json',
			'nojsoncallback' => '1',
			'extras'         => implode(
				',',
				array(
					'description',
					'date_upload',
					'date_taken',
					'owner_name',
					'geo',
					'tags',
					'url_sq',
					'url_q',
					'url_t',
					'url_s',
					'url_m',
					'url_n',
					'url_z',
					'url_c',
					'url_l',
					'url_o',
				)
			),
			'per_page'       => (string) $source['per_page'],
			'page'           => (string) $source['page'],
			'user_id'        => (string) $source['user_id'],
		);

		if ( 'photoset' === $source['source'] ) {
			$params['photoset_id']    = (string) $source['photoset_id'];
			$params['privacy_filter'] = (string) $source['privacy_filter'];
		} elseif ( $this->settings->is_connected() ) {
			$params['privacy_filter'] = (string) $source['privacy_filter'];
		}

		$response = $this->request( $params, $this->settings->is_connected() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$collection = 'photoset' === $source['source'] ? ( $response['photoset'] ?? array() ) : ( $response['photos'] ?? array() );
		$items      = isset( $collection['photo'] ) && is_array( $collection['photo'] ) ? $collection['photo'] : array();

		$photos = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$description = '';
			if ( isset( $item['description'] ) ) {
				if ( is_array( $item['description'] ) && isset( $item['description']['_content'] ) ) {
					$description = (string) $item['description']['_content'];
				} else {
					$description = (string) $item['description'];
				}
			}

			$dimensions = $this->pick_image_dimensions( $item, array( 'o', 'q', 's', 't', 'sq', 'z', 'c', 'l', 'm', 'n' ) );

			$photos[] = array(
				'id'            => isset( $item['id'] ) ? (string) $item['id'] : '',
				'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
				'description'   => $description,
				'owner'         => isset( $item['owner'] ) ? (string) $item['owner'] : (string) $source['user_id'],
				'owner_name'    => isset( $item['ownername'] ) ? (string) $item['ownername'] : '',
				'thumbnail_url' => $this->pick_image_url( $item, array( 'url_q', 'url_s', 'url_t', 'url_sq' ) ),
				'display_url'   => $this->pick_image_url( $item, array( 'url_z', 'url_c', 'url_l', 'url_m', 'url_n' ) ),
				'full_url'      => $this->pick_image_url( $item, array( 'url_o', 'url_l', 'url_c', 'url_z', 'url_m' ) ),
				'date_taken'    => isset( $item['datetaken'] ) ? (string) $item['datetaken'] : '',
				'tags'          => isset( $item['tags'] ) ? (string) $item['tags'] : '',
				'latitude'      => isset( $item['latitude'] ) ? (string) $item['latitude'] : '',
				'longitude'     => isset( $item['longitude'] ) ? (string) $item['longitude'] : '',
				'width'         => $dimensions['width'],
				'height'        => $dimensions['height'],
			);
		}

		return array(
			'source'      => $source,
			'source_key'  => $this->build_source_key( $source ),
			'title'       => $this->extract_text_value( $collection, 'title' ),
			'page'        => isset( $collection['page'] ) ? (int) $collection['page'] : (int) $source['page'],
			'pages'       => isset( $collection['pages'] ) ? (int) $collection['pages'] : 1,
			'per_page'    => isset( $collection['perpage'] ) ? (int) $collection['perpage'] : (int) $source['per_page'],
			'total'       => isset( $collection['total'] ) ? (int) $collection['total'] : count( $photos ),
			'photos'      => $photos,
			'fetched_at'  => time(),
			'is_private'  => $source['privacy_filter'] > 1,
		);
	}

	/**
	 * Perform a Flickr REST request.
	 *
	 * @param array<string,string> $params Request parameters.
	 * @param bool                 $authenticated Whether to sign with OAuth token.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( array $params, $authenticated = false ) {
		$api_key    = (string) $this->settings->get( 'api_key' );
		$api_secret = (string) $this->settings->get( 'api_secret' );

		if ( ! $api_key || ! $api_secret ) {
			return new WP_Error( 'wpfp_missing_credentials', __( 'Missing Flickr API credentials.', 'wp-flickr-portfolio' ) );
		}

		$params['api_key'] = $api_key;

		$request_args = array(
			'timeout' => 20,
		);

		if ( $authenticated ) {
			$signed = WP_Flickr_Portfolio_OAuth::signed_request(
				'GET',
				WP_Flickr_Portfolio_OAuth::REST_URL,
				$api_key,
				$api_secret,
				$params,
				(string) $this->settings->get( 'oauth_token' ),
				(string) $this->settings->get( 'oauth_token_secret' )
			);

			$request_args['headers'] = $signed['headers'];
		}

		$url     = add_query_arg( $params, WP_Flickr_Portfolio_OAuth::REST_URL );
		$attempt = 0;
		$this->logger->debug(
			'api',
			'Starting Flickr REST request.',
			array(
				'method'        => isset( $params['method'] ) ? (string) $params['method'] : '',
				'page'          => isset( $params['page'] ) ? absint( $params['page'] ) : 1,
				'per_page'      => isset( $params['per_page'] ) ? absint( $params['per_page'] ) : 0,
				'authenticated' => (bool) $authenticated,
			),
			'request_start'
		);

		while ( $attempt < self::MAX_REQUEST_ATTEMPTS ) {
			$this->pace_request();
			$response = wp_remote_get( $url, $request_args );
			if ( is_wp_error( $response ) ) {
				$this->logger->error(
					'api',
					$response->get_error_message(),
					array(
						'method' => isset( $params['method'] ) ? (string) $params['method'] : '',
						'code'   => $response->get_error_code(),
					),
					'request_transport_error'
				);
				return $response;
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $this->is_rate_limited_response( $response, $decoded ) ) {
				$attempt++;
				$retry_delay = $this->rate_limit_retry_delay( $response, $attempt );
				$this->defer_next_request( $retry_delay );
				$this->logger->warning(
					'api',
					'Flickr rate limit detected.',
					array(
						'method'      => isset( $params['method'] ) ? (string) $params['method'] : '',
						'attempt'     => $attempt,
						'retry_after' => $retry_delay,
						'status_code' => absint( wp_remote_retrieve_response_code( $response ) ),
					),
					'rate_limited'
				);

				if ( $attempt < self::MAX_REQUEST_ATTEMPTS ) {
					sleep( $retry_delay );
					continue;
				}

				return new WP_Error(
					'wpfp_rate_limited',
					$this->rate_limit_error_message( $response ),
					is_array( $decoded ) ? $decoded : array()
				);
			}

			if ( ! is_array( $decoded ) ) {
				$this->logger->error(
					'api',
					'Flickr returned an unreadable response.',
					array( 'method' => isset( $params['method'] ) ? (string) $params['method'] : '' ),
					'bad_response'
				);
				return new WP_Error( 'wpfp_bad_response', __( 'Flickr returned an unreadable response.', 'wp-flickr-portfolio' ) );
			}

			if ( isset( $decoded['stat'] ) && 'fail' === $decoded['stat'] ) {
				$message = isset( $decoded['message'] ) ? (string) $decoded['message'] : __( 'Flickr returned an unknown API error.', 'wp-flickr-portfolio' );
				$this->logger->error(
					'api',
					$message,
					array(
						'method' => isset( $params['method'] ) ? (string) $params['method'] : '',
						'code'   => isset( $decoded['code'] ) ? absint( $decoded['code'] ) : '',
					),
					'api_error'
				);
				return new WP_Error( 'wpfp_api_error', $message, $decoded );
			}

			$this->logger->debug(
				'api',
				'Flickr REST request completed.',
				array(
					'method'      => isset( $params['method'] ) ? (string) $params['method'] : '',
					'status_code' => absint( wp_remote_retrieve_response_code( $response ) ),
					'attempts'    => $attempt + 1,
				),
				'request_success'
			);

			return $decoded;
		}

		return new WP_Error( 'wpfp_request_failed', __( 'Flickr request failed.', 'wp-flickr-portfolio' ) );
	}

	/**
	 * Wait until the next shared Flickr request slot is available.
	 *
	 * @return void
	 */
	private function pace_request() {
		$next_allowed_at = absint( get_transient( self::REQUEST_PACE_KEY ) );
		$now             = time();

		if ( $next_allowed_at > $now ) {
			sleep( min( self::MAX_RETRY_AFTER, $next_allowed_at - $now ) );
			$now = time();
		}

		set_transient( self::REQUEST_PACE_KEY, $now + self::REQUEST_PACE_INTERVAL, HOUR_IN_SECONDS );
	}

	/**
	 * Push the next shared Flickr request slot into the future.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private function defer_next_request( $delay ) {
		$delay           = max( 1, absint( $delay ) );
		$current_allowed = absint( get_transient( self::REQUEST_PACE_KEY ) );
		$next_allowed_at = max( time(), $current_allowed ) + $delay;

		set_transient( self::REQUEST_PACE_KEY, $next_allowed_at, HOUR_IN_SECONDS );
	}

	/**
	 * Determine whether a Flickr response hit a rate limit.
	 *
	 * @param array<string,mixed>|WP_Error $response Raw HTTP response.
	 * @param mixed                        $decoded Decoded response body.
	 * @return bool
	 */
	private function is_rate_limited_response( $response, $decoded ) {
		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( 429 === $status_code ) {
			return true;
		}

		if ( ! is_array( $decoded ) || empty( $decoded['stat'] ) || 'fail' !== $decoded['stat'] ) {
			return false;
		}

		if ( isset( $decoded['code'] ) && 429 === absint( $decoded['code'] ) ) {
			return true;
		}

		$message = isset( $decoded['message'] ) ? strtolower( (string) $decoded['message'] ) : '';

		return false !== strpos( $message, 'too many requests' ) || false !== strpos( $message, 'rate limit' );
	}

	/**
	 * Calculate a bounded retry delay for Flickr rate limits.
	 *
	 * @param array<string,mixed>|WP_Error $response Raw HTTP response.
	 * @param int                          $attempt Attempt number starting at 1.
	 * @return int
	 */
	private function rate_limit_retry_delay( $response, $attempt ) {
		return $this->bounded_retry_delay( wp_remote_retrieve_header( $response, 'retry-after' ), $attempt );
	}

	/**
	 * Calculate a bounded retry delay for Flickr rate limits.
	 *
	 * @param string|int $retry_after Retry-After header value.
	 * @param int        $attempt Attempt number starting at 1.
	 * @return int
	 */
	private function bounded_retry_delay( $retry_after, $attempt ) {
		if ( is_string( $retry_after ) && '' !== trim( $retry_after ) ) {
			$retry_after = trim( $retry_after );

			if ( ctype_digit( $retry_after ) ) {
				return min( self::MAX_RETRY_AFTER, max( 1, absint( $retry_after ) ) );
			}

			$retry_at = strtotime( $retry_after );
			if ( false !== $retry_at ) {
				return min( self::MAX_RETRY_AFTER, max( 1, $retry_at - time() ) );
			}
		}

		$delays = array( 3, 6, 12 );
		$index  = min( max( 0, $attempt - 1 ), count( $delays ) - 1 );

		return $delays[ $index ];
	}

	/**
	 * Build a user-facing Flickr rate-limit message.
	 *
	 * @param array<string,mixed>|WP_Error $response Raw HTTP response.
	 * @return string
	 */
	private function rate_limit_error_message( $response ) {
		$retry_after = $this->rate_limit_retry_delay( $response, self::MAX_REQUEST_ATTEMPTS );

		return sprintf(
			/* translators: %d: seconds until retry. */
			__( 'Flickr rate limit reached. Wait about %d seconds and try again.', 'wp-flickr-portfolio' ),
			$retry_after
		);
	}

	/**
	 * Pick the best available size URL from an item.
	 *
	 * @param array<string,mixed> $item Flickr photo payload.
	 * @param array<int,string>   $candidates Candidate keys.
	 * @return string
	 */
	private function pick_image_url( array $item, array $candidates ) {
		foreach ( $candidates as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				return (string) $item[ $key ];
			}
		}

		return '';
	}

	/**
	 * Pick the first available width and height pair for a photo.
	 *
	 * @param array<string,mixed> $item Flickr photo payload.
	 * @param array<int,string>   $candidates Candidate Flickr size suffixes.
	 * @return array<string,int>
	 */
	private function pick_image_dimensions( array $item, array $candidates ) {
		foreach ( $candidates as $suffix ) {
			$width  = isset( $item[ 'width_' . $suffix ] ) ? absint( $item[ 'width_' . $suffix ] ) : 0;
			$height = isset( $item[ 'height_' . $suffix ] ) ? absint( $item[ 'height_' . $suffix ] ) : 0;

			if ( $width > 0 && $height > 0 ) {
				return array(
					'width'  => $width,
					'height' => $height,
				);
			}
		}

		return array(
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * Extract a text value from a Flickr response field.
	 *
	 * @param array<string,mixed> $payload Parent payload.
	 * @param string              $key Field key.
	 * @return string
	 */
	private function extract_text_value( array $payload, $key ) {
		if ( ! isset( $payload[ $key ] ) ) {
			return '';
		}

		if ( is_array( $payload[ $key ] ) && isset( $payload[ $key ]['_content'] ) ) {
			return (string) $payload[ $key ]['_content'];
		}

		return (string) $payload[ $key ];
	}

	/**
	 * Generate transient key.
	 *
	 * @param array<string,mixed> $normalized Source args.
	 * @return string
	 */
	private function cache_key( array $normalized ) {
		return 'wpfp_gallery_' . md5( wp_json_encode( $normalized ) . '|' . ( $this->settings->is_connected() ? 'auth' : 'public' ) );
	}

	/**
	 * Build compact source context for logs.
	 *
	 * @param array<string,mixed> $source Normalized source arguments.
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
			'source_key'     => $this->build_source_key( $source ),
		);
	}
}
