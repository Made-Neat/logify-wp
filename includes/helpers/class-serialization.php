<?php
/**
 * Contains the Serialization class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;

/**
 * This class provides serialization and unserialization functions.
 *
 * It wraps the core PHP functions serialize() and unserialize() to provide support for nullable
 * strings, and better handling of unserialization errors.
 *
 * Unserialization is restricted to a known list of classes (see get_allowed_classes()) so that a
 * tampered or corrupted payload cannot instantiate arbitrary objects. See
 * https://www.owasp.org/index.php/PHP_Object_Injection
 */
class Serialization {

	/**
	 * Classes that may be reconstructed when unserializing.
	 *
	 * These are the only object types the plugin ever serializes: WordPress entities captured by
	 * the trackers, date objects, and the plugin's own value objects. Anything else in a payload
	 * is refused rather than instantiated.
	 *
	 * @return string[] The list of permitted class names.
	 */
	public static function get_allowed_classes(): array {
		$allowed = array(
			'stdClass',
			'DateTime',
			'DateTimeImmutable',
			'DateTimeZone',
			'DateInterval',
			'WP_Post',
			'WP_User',
			'WP_Comment',
			'WP_Term',
			'WP_Error',
			'WP_Theme',
			'WP_Role',
			'WP_Roles',
			'WP_Taxonomy',
			'WP_Post_Type',
			'WP_User_Meta_Session_Tokens',
			'Logify_WP\Object_Reference',
			'Logify_WP\Property',
			'Logify_WP\Eventmeta',
			'Logify_WP\Event',
			'Logify_WP\Note',
			'Logify_WP\Error',
			'Logify_WP\Set',
		);

		/**
		 * Filters the classes Logify WP will reconstruct when unserializing stored values.
		 *
		 * Only add classes here if you have extended the plugin and are storing your own objects
		 * as event properties or metadata.
		 *
		 * @param string[] $allowed The list of permitted class names.
		 */
		$filtered = apply_filters( 'logify_wp_unserialize_allowed_classes', $allowed );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $allowed;
	}

	/**
	 * Serialize a value, or return null if the value is null.
	 *
	 * @param mixed $value The value to serialize.
	 * @return ?string The serialized value or null if the value is null.
	 */
	public static function serialize( mixed $value ): ?string {
		return $value === null ? null : serialize( $value );
	}

	/**
	 * Attempts to unserialize a nullable string.
	 *
	 * If the provided value is null, the unserialized value will be null, and the return value will
	 * be true.
	 *
	 * If the provided value is a string with value PHP-serialized data, it will be unserialized,
	 * the unserialized value will be stored in the reference parameter, and the return value will
	 * be true.
	 *
	 * If it doesn't, the unserialized value will be unaltered, and the return value will be false.
	 *
	 * @param ?string $serialized_value The input value which may contain serialized data.
	 * @param mixed   $unserialized_value The unserialized value if the string represents serialized data.
	 * @return bool True if the provided value was null or successfully unserialized; otherwise false.
	 */
	public static function try_unserialize( ?string $serialized_value, mixed &$unserialized_value ): bool {
		if ( $serialized_value === null ) {
			$unserialized_value = null;
			return true;
		}

		if ( ! self::is_valid_serialized( $serialized_value ) ) {
			return false; // Invalid serialized string.
		}

		try {
			// allowed_classes restricts object instantiation. Anything not on the list becomes
			// __PHP_Incomplete_Class, which cannot execute code (no __wakeup/__destruct is run).
			$result = unserialize(
				$serialized_value,
				array( 'allowed_classes' => self::get_allowed_classes() )
			);

			// unserialize() returns false both for genuine boolean false and for failure, so the
			// literal 'b:0;' case is checked explicitly.
			if ( $result === false && $serialized_value !== 'b:0;' ) {
				return false;
			}

			if ( self::contains_incomplete_class( $result ) ) {
				Debug::warning( 'Logify WP: refused to unserialize a disallowed class in stored data.' );
				return false;
			}

			$unserialized_value = $result;
			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Turn a tracker payload into an object, whatever form it arrived in.
	 *
	 * Payloads reach the trackers through Action Scheduler, which stores its arguments as JSON,
	 * so an object serialised on the way in can come back as a serialized string, as the
	 * original object, or as an array. Each tracker previously repeated the same three-way
	 * branch, with an empty first branch, eight times over.
	 *
	 * @param mixed  $value       The payload: a serialized string, an object, or an array.
	 * @param string $context     Calling context, used in the warning if the payload is unusable.
	 * @param bool   $cast_arrays Whether an array payload should be cast to an object. Pass false
	 *                            where the caller needs a real class instance, such as a
	 *                            WP_Error, since a cast array would only fatal later on.
	 * @return ?object The object, or null if the payload could not be interpreted.
	 */
	public static function to_object( mixed $value, string $context = '', bool $cast_arrays = true ): ?object {
		if ( is_string( $value ) ) {
			$unserialized = null;

			if ( self::try_unserialize( $value, $unserialized ) ) {
				if ( is_object( $unserialized ) ) {
					return $unserialized;
				}

				// A serialized array is still usable, provided the caller accepts a cast.
				if ( $cast_arrays && is_array( $unserialized ) ) {
					return (object) $unserialized;
				}
			}
		}

		if ( is_object( $value ) ) {
			return $value;
		}

		if ( $cast_arrays && is_array( $value ) ) {
			return (object) $value;
		}

		Debug::warning(
			sprintf(
				'Logify WP: could not interpret payload in %s; type was %s.',
				$context !== '' ? $context : 'an unknown context',
				gettype( $value )
			)
		);

		return null;
	}

	/**
	 * Check whether an unserialized value contains a refused class placeholder.
	 *
	 * @param mixed $value The value to inspect.
	 * @param int   $depth Current recursion depth.
	 * @return bool True if a disallowed class was encountered.
	 */
	private static function contains_incomplete_class( mixed $value, int $depth = 0 ): bool {
		if ( $depth > 5 ) {
			return false;
		}

		if ( $value instanceof \__PHP_Incomplete_Class ) {
			return true;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::contains_incomplete_class( $item, $depth + 1 ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Basic check that a string looks like PHP-serialized data.
	 *
	 * This is a cheap pre-filter so that ordinary strings are never handed to unserialize().
	 * It accepts every scalar form PHP emits, including negative and exponent-notation numbers,
	 * which an earlier version of this check rejected.
	 *
	 * @param string $data The candidate string.
	 * @return bool True if the string looks like serialized data.
	 */
	private static function is_valid_serialized( string $data ): bool {
		return preg_match(
			'/^(?:N;|b:[01];|i:-?\d+;|d:(?:-?(?:\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|INF)|NAN);|s:\d+:|a:\d+:|O:\d+:|E:\d+:|C:\d+:)/',
			$data
		) === 1;
	}
}
