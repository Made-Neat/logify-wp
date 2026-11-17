<?php
/**
 * Conservative privacy boundary for new audit records.
 *
 * @package Logify_WP
 */
namespace Logify_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redact nested structured values without running user-controlled objects' methods.
 * Never send opaque third-party option payloads into Action Scheduler.
 */
final class Sensitive_Data {
	public const REDACTED = '[Logify WP: sensitive value redacted]';
	public const OMITTED_BEFORE = '[Logify WP: previous setting value omitted]';
	public const OMITTED_AFTER  = '[Logify WP: updated setting value omitted]';

	public static function is_secret_key( string $key ): bool {
		// Match meaningful key segments, not substrings in harmless words such as
		// passenger_count, bypass_cache, secretary_name or token_balance.
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) ?? $key;
		$key = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', $key ) ?? $key, '_' ) );
		if ( in_array( $key, array( 'token_balance', 'tokens_used', 'token_count', 'token_used', 'token_total', 'token_usage', 'authorization_level', 'public_key_display', 'password_protected', 'passenger_count', 'bypass_cache' ), true ) ) {
			return false;
		}
		if ( preg_match( '/(?:^|_)(?:tokens?|authorization)_(?:balance|count|used|usage|total|level|label|display)$/', $key ) ) {
			return false;
		}
		if ( in_array( $key, array( 'user_activation_key', 'session_tokens', 'application_passwords', 'wpnonce', 'nonce', 'user_pass', 'post_password', 'private_key' ), true ) ) {
			return true;
		}
		// Standalone sensitive segments, or compound secret names such as api_key.
		return (bool) preg_match( '/(?:^|_)(?:password|passphrase|pass|pwd|secret|secrets|credential|credentials|token|tokens|bearer|authorization|privatekey|nonce)(?:_|$)/', $key )
			|| (bool) preg_match( '/(?:^|_)(?:api|auth|access|refresh|client|consumer|webhook|private)_(?:key|token|secret)(?:_|$)/', $key );
	}

	/**
	 * Safe immutable copy of arrays and scalar values. Fail closed on excessive depth.
	 * Objects are not traversed or cast; their state is considered opaque.
	 */
	public static function sanitize( mixed $value, string $key = '', int $depth = 0 ): mixed {
		if ( self::is_secret_key( $key ) ) {
			return self::REDACTED;
		}
		if ( $depth >= 12 ) {
			return self::REDACTED;
		}
		if ( is_array( $value ) ) {
			if ( count( $value ) > 250 ) {
				return '[Logify WP: large array omitted]';
			}
			$result = array();
			foreach ( $value as $child_key => $child_value ) {
				$result[ $child_key ] = self::sanitize( $child_value, (string) $child_key, $depth + 1 );
			}
			return $result;
		}
		if ( $value instanceof Object_Reference ) {
			return new Object_Reference( $value->type, $value->key, $value->name === null ? null : self::sanitize( $value->name ) );
		}
		if ( $value instanceof \DateTimeInterface ) {
			return clone $value;
		}
		if ( is_object( $value ) || is_resource( $value ) ) {
			return '[Logify WP: object value omitted]';
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		// Inspect JSON-formatted third-party metadata, not just plain PHP arrays.
		if ( strlen( $value ) < 65536 && preg_match( '/^\\s*[\\[{]/', $value ) ) {
			$decoded = json_decode( $value, true, 16 );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$encoded = json_encode( self::sanitize( $decoded, '', $depth + 1 ) );
				return $encoded === false ? self::REDACTED : $encoded;
			}
		}
		// An opaque PHP serialized payload cannot be safely inspected here.
		if ( preg_match( '/^(?:a|O|C):\\d+:/', $value ) ) {
			return '[Logify WP: serialized value omitted]';
		}
		// Redact common credential-bearing URLs and authorization strings.
		$value = preg_replace( '/\\b(Bearer|Basic)\\s+[^\\s<"\']+/i', '$1 ' . self::REDACTED, $value ) ?? self::REDACTED;
		$value = preg_replace( '/([?&](?:api[_-]?key|token|access[_-]?token|secret|password|client[_-]?secret|authorization)=)[^&#\\s]+/i', '$1' . rawurlencode( self::REDACTED ), $value ) ?? self::REDACTED;
		return $value;
	}

	/**
	 * Preserve only structural information needed for widget events. Widget content
	 * is never transported to Action Scheduler. A keyed HMAC detects edits without
	 * disclosing or storing content, including in low-entropy widget settings.
	 */
	private static function widget_summary( mixed $value ): mixed {
		if ( ! is_array( $value ) || count( $value ) > 75 ) {
			return self::REDACTED;
		}
		$summary = array();
		foreach ( $value as $id => $widget ) {
			if ( ! ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) ) {
				continue;
			}
			if ( ! is_array( $widget ) ) {
				return self::REDACTED;
			}
			// Widget values should be scalar/array; serialize no arbitrary objects.
			if ( self::contains_object( $widget ) ) {
				return self::REDACTED;
			}
			$summary[ $id ] = array( '__logify_digest' => hash_hmac( 'sha256', serialize( $widget ), wp_salt( 'auth' ) ) );
		}
		return $summary;
	}

	private static function contains_object( mixed $value, int $depth = 0 ): bool {
		if ( is_object( $value ) || is_resource( $value ) || $depth > 12 ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( self::contains_object( $item, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sidebar assignments contain only sidebar and widget identifiers. */
	private static function sidebar_structure( mixed $value ): mixed {
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			return self::REDACTED;
		}
		$result = array();
		foreach ( $value as $sidebar => $widgets ) {
			// WordPress stores array_version alongside actual sidebar arrays.
			if ( $sidebar === 'array_version' && is_int( $widgets ) ) {
				$result[ $sidebar ] = $widgets;
				continue;
			}
			if ( ! is_string( $sidebar ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/', $sidebar ) || ! is_array( $widgets ) || count( $widgets ) > 150 ) {
				return self::REDACTED;
			}
			foreach ( $widgets as $widget ) {
				if ( ! is_string( $widget ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/', $widget ) ) {
					return self::REDACTED;
				}
			}
			$result[ $sidebar ] = array_values( $widgets );
		}
		return $result;
	}

	/** Only a reviewed small subset of scalar core settings may retain before/after values. */
	public static function option_values( string $option, mixed $old, mixed $new ): array {
		// Auto-update plugin identifiers are non-secret metadata used by the plugin
		// tracker to identify what was enabled/disabled. Never pass arbitrary arrays.
		if ( $option === 'auto_update_plugins' && is_array( $old ) && is_array( $new ) && count( $old ) <= 500 && count( $new ) <= 500 ) {
			foreach ( array_merge( $old, $new ) as $plugin_file ) {
				if ( ! is_string( $plugin_file ) || ! preg_match( '~^[A-Za-z0-9._/-]{1,255}$~', $plugin_file ) ) {
					return array( self::OMITTED_BEFORE, self::OMITTED_AFTER );
				}
			}
			return array( array_values( $old ), array_values( $new ) );
		}
		if ( str_starts_with( $option, 'widget_' ) ) {
			return array( self::widget_summary( $old ), self::widget_summary( $new ) );
		}
		if ( $option === 'sidebars_widgets' ) {
			return array( self::sidebar_structure( $old ), self::sidebar_structure( $new ) );
		}
		if ( Option_Utility::is_safe_value_option( $option ) && is_scalar( $old ) && is_scalar( $new ) ) {
			return array( self::sanitize( $old ), self::sanitize( $new ) );
		}
		return array( self::OMITTED_BEFORE, self::OMITTED_AFTER );
	}
}
