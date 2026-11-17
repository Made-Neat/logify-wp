<?php
/**
 * Contains the Types class.
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
 * Miscellaneous general-purpose static methods for working with values of different types.
 */
class Types {

	/**
	 * Process a database value, converting it to a more usable type if necessary.
	 *
	 * @param string $key The database key (like a column name or meta key).
	 * @param mixed  $value The value.
	 * @return mixed The converted or original value.
	 */
	public static function process_database_value( string $key, mixed $value ): mixed {
		// Handle nulls.
		if ( $value === null ) {
			return null;
		}

		// Default result is the unaltered value.
		$result = $value;

		// If the value is a PHP-serialized array, deserialize it.
		if ( is_string( $result ) && Serialization::try_unserialize( $result, $unserialized_result ) ) {
			$result = $unserialized_result;
		}

		// Convert non-string values stored as strings into their correct types.
		if ( is_string( $result ) ) {
			if ( Strings::looks_like_null( $result ) ) {
				$result = null;
			} elseif ( Strings::looks_like_bool( $result ) ) {
				$result = $result === 'true' ? true : false;
			} elseif ( Strings::looks_like_int( $result ) ) {
				$result = (int) $result;
			} elseif ( Strings::looks_like_float( $result ) ) {
				$result = (float) $result;
			} elseif ( Strings::looks_like_datetime( $result ) ) {
				$tz     = substr( $key, -4 ) === '_gmt' ? 'UTC' : 'site';
				$result = DateTimes::create_datetime( $result, $tz );
			}
		}

		return $result;
	}

	/**
	 * Check if two values are equal by value and type.
	 *
	 * Solves the problem of two object references being considered unequal when they refer to
	 * different objects with equal property values.
	 *
	 * @param mixed $value1 The first value.
	 * @param mixed $value2 The second value.
	 * @return bool Whether the values are equal.
	 */
	public static function are_equal( mixed $value1, mixed $value2 ): bool {
		// Object comparison using JSON encoding.
		if ( is_object( $value1 ) && is_object( $value2 ) ) {
			return get_class( $value1 ) === get_class( $value2 ) && wp_json_encode( $value1 ) === wp_json_encode( $value2 );
		}

		// If not objects, compare directly.
		return $value1 === $value2;
	}

	/**
	 * Convert a value to a string for display.
	 *
	 * @param mixed $value The value to convert.
	 * @return string The value as a string.
	 */
	public static function value_to_string( mixed $value ) {
		if ( $value === null ) {
			return '';
		} elseif ( is_string( $value ) ) {
			return $value;
		} elseif ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		} elseif ( is_scalar( $value ) ) {
			return (string) $value;
		} elseif ( $value instanceof DateTime ) {
			return DateTimes::format_datetime_site( $value );
		} elseif ( $value instanceof Object_Reference ) {
			return $value->get_tag();
		} else {
			// Value is an array or another type of object.
			return wp_json_encode( $value );
		}
	}

	/**
	 * Convert a value to an HTML string for display.
	 *
	 * @param string $key The key of the value.
	 * @param mixed  $value The value to convert.
	 * @return string The value as an HTML string.
	 */
	public static function value_to_html( string $key, mixed $value ): string {
		// Hide passwords.
		if ( $key === 'user_pass' ) {
			return empty( $value ) ? '' : '(hidden)';
		}

		// Expand arrays into mini-tables.
		if ( is_array( $value ) ) {

			// Handle empty arrays.
			if ( count( $value ) === 0 ) {
				return '';
			}

			// Start the table.
			$html = "<table>\n";

			// Check if the array is a list.
			$is_list = Arrays::is_list( $value );

			foreach ( $value as $key2 => $value2 ) {
				// Start the table row.
				$html .= '<tr>';

				// If it's not a list, show the key. Array keys can come from logged
				// option or meta values, so they are attacker-influenced: escape.
				if ( ! $is_list ) {
					$html .= '<th>' . esc_html( Strings::key_to_label( $key2 ) ) . '</th>';
				}

				// Show the value.
				$html .= '<td>' . self::value_to_html( $key2, $value2 ) . '</td>';

				// End the table row.
				$html .= '</tr>';
			}

			// End the table.
			$html .= "</table>\n";

			return $html;
		}

		// Object_Reference::get_tag() and the utility get_tag() methods return
		// intentional HTML (a link or a span) which escapes its own text content, so
		// it must pass through unescaped. Everything else is plain text derived from
		// logged data - post titles, option values, usernames, user agents - and is
		// escaped here. This is the render-time boundary for the details pane: the
		// log stores exactly what was submitted, and escaping happens on the way out.
		if ( $value instanceof Object_Reference ) {
			return $value->get_tag();
		}

		$string = self::value_to_string( $value );

		// Some stored property values are a single anchor built by the plugin's own utilities -
		// a plugin's "Link" and "Author" headers, for example. Escaping those printed the raw
		// markup on screen instead of a link, which is what happened from 1.4.0 onward when
		// output escaping was tightened here. Anything that is exactly one anchor is passed
		// through wp_kses() with a minimal allow-list, so a link renders while any script,
		// event handler or javascript: URI in it is stripped. Everything else stays escaped.
		$link = self::maybe_anchor( $string );

		if ( $link !== null ) {
			return $link;
		}

		return esc_html( $string );
	}

	/**
	 * If a string is exactly one anchor tag, return a sanitised version of it.
	 *
	 * Deliberately narrow: the whole string has to be a single <a> element, so an arbitrary
	 * logged value containing markup is still escaped as text rather than rendered. wp_kses()
	 * then strips everything but the handful of attributes a link needs, and rejects
	 * javascript: and data: URIs.
	 *
	 * @param string $string The candidate string.
	 * @return ?string The sanitised anchor, or null if the string is not a lone anchor.
	 */
	private static function maybe_anchor( string $string ): ?string {
		$trimmed = trim( $string );

		if ( $trimmed === '' || stripos( $trimmed, '<a ' ) !== 0 ) {
			return null;
		}

		// One opening tag, one closing tag, nothing else outside them.
		if ( ! preg_match( '#^<a\s[^>]*>[^<]*</a>$#i', $trimmed ) ) {
			return null;
		}

		$allowed = array(
			'a' => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
		);

		$safe = wp_kses( $trimmed, $allowed );

		// If kses removed the anchor entirely - a javascript: URI, for instance - fall back to
		// escaping so the value is still visible in the log.
		return stripos( $safe, '<a' ) === 0 ? $safe : null;
	}
}
