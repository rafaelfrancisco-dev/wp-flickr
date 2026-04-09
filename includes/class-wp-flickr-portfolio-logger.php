<?php
/**
 * Plugin-owned operational logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Flickr_Portfolio_Logger {

	/**
	 * Option name used for persisted log entries.
	 */
	const OPTION_NAME = 'wpfp_logs';

	/**
	 * Maximum number of retained entries.
	 */
	const MAX_ENTRIES = 400;

	/**
	 * Maximum string length for persisted values.
	 */
	const MAX_STRING_LENGTH = 200;

	/**
	 * Add one log entry.
	 *
	 * @param string              $level Log level.
	 * @param string              $component Component name.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Structured context.
	 * @param string              $event Event key.
	 * @return void
	 */
	public function log( $level, $component, $message, array $context = array(), $event = '' ) {
		$entries   = $this->get_raw_entries();
		$entries[] = array(
			'timestamp' => time(),
			'level'     => $this->normalize_level( $level ),
			'component' => sanitize_key( (string) $component ),
			'event'     => sanitize_key( (string) $event ),
			'message'   => $this->sanitize_string( $message ),
			'context'   => $this->sanitize_context( $context ),
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -1 * self::MAX_ENTRIES );
		}

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Add a debug entry.
	 *
	 * @param string              $component Component name.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Structured context.
	 * @param string              $event Event key.
	 * @return void
	 */
	public function debug( $component, $message, array $context = array(), $event = '' ) {
		$this->log( 'debug', $component, $message, $context, $event );
	}

	/**
	 * Add an info entry.
	 *
	 * @param string              $component Component name.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Structured context.
	 * @param string              $event Event key.
	 * @return void
	 */
	public function info( $component, $message, array $context = array(), $event = '' ) {
		$this->log( 'info', $component, $message, $context, $event );
	}

	/**
	 * Add a warning entry.
	 *
	 * @param string              $component Component name.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Structured context.
	 * @param string              $event Event key.
	 * @return void
	 */
	public function warning( $component, $message, array $context = array(), $event = '' ) {
		$this->log( 'warning', $component, $message, $context, $event );
	}

	/**
	 * Add an error entry.
	 *
	 * @param string              $component Component name.
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Structured context.
	 * @param string              $event Event key.
	 * @return void
	 */
	public function error( $component, $message, array $context = array(), $event = '' ) {
		$this->log( 'error', $component, $message, $context, $event );
	}

	/**
	 * Return the total number of entries matching the given filters.
	 *
	 * @param array<string,mixed> $filters Optional filters.
	 * @return int
	 */
	public function get_total_count( array $filters = array() ) {
		return count( $this->get_filtered_entries( $filters ) );
	}

	/**
	 * Return filtered log entries in reverse chronological order.
	 *
	 * @param array<string,mixed> $filters Optional filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries( array $filters = array() ) {
		$entries = $this->get_filtered_entries( $filters );
		$limit   = isset( $filters['limit'] ) ? max( 1, absint( $filters['limit'] ) ) : self::MAX_ENTRIES;
		$offset  = isset( $filters['offset'] ) ? max( 0, absint( $filters['offset'] ) ) : 0;

		return array_slice( $entries, $offset, $limit );
	}

	/**
	 * Return all filtered entries (before offset/limit) in reverse chronological order.
	 *
	 * @param array<string,mixed> $filters Optional filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_filtered_entries( array $filters = array() ) {
		$entries   = array_reverse( $this->get_raw_entries() );
		$level     = isset( $filters['level'] ) ? $this->normalize_level( $filters['level'] ) : '';
		$component = isset( $filters['component'] ) ? sanitize_key( (string) $filters['component'] ) : '';

		if ( $level ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function( $entry ) use ( $level ) {
						return is_array( $entry ) && isset( $entry['level'] ) && $level === $entry['level'];
					}
				)
			);
		}

		if ( $component ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function( $entry ) use ( $component ) {
						return is_array( $entry ) && isset( $entry['component'] ) && $component === $entry['component'];
					}
				)
			);
		}

		return $entries;
	}

	/**
	 * Return known component names.
	 *
	 * @return array<int,string>
	 */
	public function get_components() {
		$components = array();

		foreach ( $this->get_raw_entries() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['component'] ) ) {
				$components[] = (string) $entry['component'];
			}
		}

		$components = array_values( array_unique( $components ) );
		sort( $components, SORT_STRING );

		return $components;
	}

	/**
	 * Delete all retained log entries.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Return normalized stored entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_raw_entries() {
		$entries = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$normalized[] = array(
				'timestamp' => isset( $entry['timestamp'] ) ? absint( $entry['timestamp'] ) : time(),
				'level'     => isset( $entry['level'] ) ? $this->normalize_level( $entry['level'] ) : 'info',
				'component' => isset( $entry['component'] ) ? sanitize_key( (string) $entry['component'] ) : '',
				'event'     => isset( $entry['event'] ) ? sanitize_key( (string) $entry['event'] ) : '',
				'message'   => isset( $entry['message'] ) ? $this->sanitize_string( $entry['message'] ) : '',
				'context'   => isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array(),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize a log level.
	 *
	 * @param string $level Requested level.
	 * @return string
	 */
	private function normalize_level( $level ) {
		$level = sanitize_key( (string) $level );

		return in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info';
	}

	/**
	 * Sanitize a human-readable string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_string( $value ) {
		$string = sanitize_text_field( (string) $value );

		if ( strlen( $string ) > self::MAX_STRING_LENGTH ) {
			$string = substr( $string, 0, self::MAX_STRING_LENGTH ) . '...';
		}

		return $string;
	}

	/**
	 * Sanitize structured context recursively.
	 *
	 * @param mixed       $value Raw context.
	 * @param string|null $key Current key.
	 * @param int         $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_context( $value, $key = null, $depth = 0 ) {
		if ( $depth > 3 ) {
			return '[truncated]';
		}

		if ( null !== $key && $this->should_redact( $key ) ) {
			return '[redacted]';
		}

		if ( is_array( $value ) ) {
			$sanitized = array();
			$count     = 0;

			foreach ( $value as $child_key => $child_value ) {
				$sanitized_key               = is_string( $child_key ) ? sanitize_key( $child_key ) : $child_key;
				$sanitized[ $sanitized_key ] = $this->sanitize_context( $child_value, is_string( $child_key ) ? $child_key : null, $depth + 1 );
				$count++;

				if ( $count >= 12 ) {
					$sanitized['truncated'] = true;
					break;
				}
			}

			return $sanitized;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_null( $value ) ) {
			return null;
		}

		if ( is_scalar( $value ) ) {
			return $this->sanitize_string( $value );
		}

		return $this->sanitize_string( wp_json_encode( $value ) );
	}

	/**
	 * Whether a context key should be redacted.
	 *
	 * @param string $key Context key.
	 * @return bool
	 */
	private function should_redact( $key ) {
		$key = strtolower( (string) $key );

		foreach ( array( 'secret', 'token', 'authorization', 'api_key', 'api_secret', 'oauth' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
