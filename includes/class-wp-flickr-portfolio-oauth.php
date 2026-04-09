<?php
/**
 * OAuth 1.0a implementation for Flickr API calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_OAuth {

	/**
	 * Flickr REST base URL.
	 */
	const REST_URL = 'https://www.flickr.com/services/rest';

	/**
	 * Flickr request token URL.
	 */
	const REQUEST_TOKEN_URL = 'https://www.flickr.com/services/oauth/request_token';

	/**
	 * Flickr authorize URL.
	 */
	const AUTHORIZE_URL = 'https://www.flickr.com/services/oauth/authorize';

	/**
	 * Flickr access token URL.
	 */
	const ACCESS_TOKEN_URL = 'https://www.flickr.com/services/oauth/access_token';

	/**
	 * Build a signed request package.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url Endpoint URL.
	 * @param string               $consumer_key Flickr API key.
	 * @param string               $consumer_secret Flickr API secret.
	 * @param array<string,string> $request_params Non-OAuth parameters.
	 * @param string               $token OAuth token if available.
	 * @param string               $token_secret OAuth token secret if available.
	 * @param array<string,string> $extra_oauth Additional OAuth parameters.
	 * @return array<string,mixed>
	 */
	public static function signed_request( $method, $url, $consumer_key, $consumer_secret, array $request_params = array(), $token = '', $token_secret = '', array $extra_oauth = array() ) {
		$oauth = array_merge(
			array(
				'oauth_consumer_key'     => $consumer_key,
				'oauth_nonce'            => wp_generate_password( 24, false, false ),
				'oauth_signature_method' => 'HMAC-SHA1',
				'oauth_timestamp'        => (string) time(),
				'oauth_version'          => '1.0',
			),
			$extra_oauth
		);

		if ( $token ) {
			$oauth['oauth_token'] = $token;
		}

		$signature_params            = array_merge( $request_params, $oauth );
		$oauth['oauth_signature']    = self::build_signature( $method, $url, $signature_params, $consumer_secret, $token_secret );
		$authorization_header        = self::build_authorization_header( $oauth );
		$signed                      = array(
			'headers' => array(
				'Authorization' => $authorization_header,
			),
			'query'   => $request_params,
			'oauth'   => $oauth,
		);

		return $signed;
	}

	/**
	 * Build Authorization header value.
	 *
	 * @param array<string,string> $oauth_params OAuth parameters.
	 * @return string
	 */
	public static function build_authorization_header( array $oauth_params ) {
		ksort( $oauth_params );

		$parts = array();
		foreach ( $oauth_params as $key => $value ) {
			$parts[] = rawurlencode( $key ) . '="' . rawurlencode( (string) $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $parts );
	}

	/**
	 * Build OAuth signature.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url Endpoint URL.
	 * @param array<string,string> $params All request parameters.
	 * @param string               $consumer_secret API secret.
	 * @param string               $token_secret Token secret.
	 * @return string
	 */
	public static function build_signature( $method, $url, array $params, $consumer_secret, $token_secret = '' ) {
		$normalized = self::normalize_parameters( $params );
		$base       = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $normalized );
		$key        = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $token_secret );

		return base64_encode( hash_hmac( 'sha1', $base, $key, true ) );
	}

	/**
	 * Normalize parameters for OAuth signing.
	 *
	 * @param array<string,string> $params Parameters.
	 * @return string
	 */
	private static function normalize_parameters( array $params ) {
		$encoded = array();

		foreach ( $params as $key => $value ) {
			$encoded[] = array(
				'key'   => rawurlencode( (string) $key ),
				'value' => rawurlencode( (string) $value ),
			);
		}

		usort(
			$encoded,
			static function( $left, $right ) {
				if ( $left['key'] === $right['key'] ) {
					return strcmp( $left['value'], $right['value'] );
				}

				return strcmp( $left['key'], $right['key'] );
			}
		);

		$pairs = array();
		foreach ( $encoded as $pair ) {
			$pairs[] = $pair['key'] . '=' . $pair['value'];
		}

		return implode( '&', $pairs );
	}
}
