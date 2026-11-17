<?php
/**
 * Contains the Debug class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTime;

/**
 * Class Logify_WP\DEbug
 *
 * Contains debugging functions.
 */
class Debug {

	public const SEVERITY_INFO    = 1;
	public const SEVERITY_WARNING = 2;
	public const SEVERITY_ERROR   = 3;

	/**
	 * Convert a variable to a string representation suitable for the debug log.
	 *
	 * @param mixed $value The variable to convert.
	 * @return string The string representation of the variable.
	 */
	public static function var_to_string( mixed $value ): string {
		if ( $value === null ) {
			return 'null';
		} elseif ( is_string( $value ) ) {
			return $value;
		} elseif ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		} elseif ( is_scalar( $value ) ) {
			return (string) $value;
		} elseif ( $value instanceof DateTime ) {
			return DateTimes::format_datetime_site( $value );
		} elseif ( is_object( $value ) ) {
			return get_class( $value ) . ' ' . wp_json_encode( $value, JSON_PRETTY_PRINT );
		} else {
			return wp_json_encode( $value, JSON_PRETTY_PRINT );
		}
	}

	/**
	 * Dump one or more variables into the error log.
	 *
	 * @param mixed ...$args The variable(s) to dump.
	 */
	public static function log( int $severity, ...$args ) {
		// Only log when WP_DEBUG is enabled and error_log exists.
		if ( ! self::can_log() ) {
			return;
		}

		// If no arguments are provided, do nothing.
		if ( empty( $args ) ) {
			return;
		}

		// Convert each argument to a string representation.
		$strings = array_map( fn( $arg ): string => self::var_to_string( $arg ), $args );

		// Convert the severity constant into a string.
		$severity_string = match ( $severity ) {
			self::SEVERITY_INFO => 'INF',
			self::SEVERITY_WARNING => 'WRN',
			self::SEVERITY_ERROR => 'ERR',
			default => '???',
		};

		// Log the strings.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $severity_string . ': ' . implode( ', ', $strings ) );
	}

	/**
	 * Dump one or more variables into the error log with severity INFO.
	 *
	 * @param mixed ...$args The variable(s) to dump.
	 */
	public static function info( ...$args ) {
		self::log( self::SEVERITY_INFO, ...$args );
	}

	/**
	 * Dump one or more variables into the error log with severity WARNING.
	 *
	 * @param mixed ...$args The variable(s) to dump.
	 */
	public static function warning( ...$args ) {
		self::log( self::SEVERITY_WARNING, ...$args );
	}

	/**
	 * Dump one or more variables into the error log with severity ERROR.
	 *
	 * @param mixed ...$args The variable(s) to dump.
	 */
	public static function error( ...$args ) {
		self::log( self::SEVERITY_ERROR, ...$args );
	}

	/**
	 * Dump one or more variables into the error log, but only when verbose logging is on.
	 *
	 * Use this for the high-frequency tracing that used to be written on every post meta update
	 * whenever WP_DEBUG was on. It is off unless the site sets
	 * define( 'LOGIFY_WP_DEBUG', true ), so a site running WP_DEBUG for its own development is
	 * no longer flooded with Logify WP output, and the arguments are only converted to strings
	 * if something is actually going to be written.
	 *
	 * @param mixed ...$args The variable(s) to dump.
	 */
	public static function verbose( ...$args ) {
		if ( ! defined( 'LOGIFY_WP_DEBUG' ) || ! LOGIFY_WP_DEBUG ) {
			return;
		}

		self::log( self::SEVERITY_INFO, ...$args );
	}

	/**
	 * Dump an SQL query into the error log.
	 *
	 * @param string $sql The SQL query to dump.
	 */
	public static function sql( string $sql ) {
		if ( ! self::can_log() ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'SQL: ' . $wpdb->remove_placeholder_escape( $sql ) );
	}

	/**
	 * Determine whether logging should occur.
	 */
	private static function can_log(): bool {
		if ( ! function_exists( 'error_log' ) ) {
			return false;
		}

		return defined( 'LOGIFY_WP_DEBUG' ) && (bool) LOGIFY_WP_DEBUG;
	}
}
