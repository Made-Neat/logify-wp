<?php
namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard for Action Scheduler payloads.
 *
 * Ensures payloads stay within safe size limits to prevent memory exhaustion
 * and database bloat. Provides special handling for known large payloads like
 * Gravity Forms version info.
 */
final class AS_Guard {

    /**
     * Maximum bytes for serialized payload.
     *
     * @return int
     */
    public static function max_bytes(): int {
        return 7500; // headroom under the ~8k limit
    }

    /**
     * Enqueue an async action with payload size protection.
     *
     * @param string $hook   Action hook name.
     * @param array  $args   Arguments to pass to the action.
     * @param string $group  Action Scheduler group.
     * @param bool   $unique Whether to deduplicate.
     */
    public static function enqueue_async( string $hook, array $args = [], string $group = '', bool $unique = false ): void {
        // Check for suppressed payload patterns first (before any serialization).
        $suppressed = self::check_suppressed_payload( $args );
        if ( $suppressed !== null ) {
            Debug::info( $suppressed );
            return;
        }

        // Shrink arguments to fit within limits.
        $shrunk = self::shrink( $args, self::max_bytes() );

        // Honor $unique flag.
        if ( $unique && function_exists( 'as_has_scheduled_action' ) &&
             as_has_scheduled_action( $hook, $shrunk, $group ) ) {
            return;
        }

        as_enqueue_async_action( $hook, $shrunk, $group, $unique );
    }

    /**
     * Check if payload matches a known pattern that should be suppressed entirely.
     *
     * @param array $payload The payload to check.
     * @return ?string Suppression message if matched, null otherwise.
     */
    private static function check_suppressed_payload( array $payload ): ?string {
        // Check for Gravity Forms version info pattern.
        // Structure: ['gform_version_info', {...huge data...}, ...]
        if ( isset( $payload[0] ) && $payload[0] === 'gform_version_info' ) {
            $size = self::estimate_size( $payload );
            return "[suppressed gform_version_info payload, {$size} bytes]";
        }

        // Check for option name containing gform_version_info.
        if ( isset( $payload['option'] ) && strpos( (string) $payload['option'], 'gform_version_info' ) !== false ) {
            $size = self::estimate_size( $payload );
            return "[suppressed gform_version_info option payload, {$size} bytes]";
        }

        // Check for first element being an option name like gform_version_info.
        if ( is_array( $payload ) && count( $payload ) >= 2 ) {
            $first = $payload[0] ?? ( $payload['0'] ?? null );
            if ( is_string( $first ) && strpos( $first, 'gform_version_info' ) !== false ) {
                $size = self::estimate_size( $payload );
                return "[suppressed gform_version_info payload, {$size} bytes]";
            }
        }

        return null;
    }

    /**
     * Estimate payload size without creating large temporary strings.
     *
     * @param mixed $payload The payload to measure.
     * @return int Estimated size in bytes.
     */
    private static function estimate_size( $payload ): int {
        // Use JSON encoding with a length limit to avoid memory issues.
        // If it fails or is too large, we estimate based on structure.
        try {
            $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( $json !== false ) {
                return strlen( $json );
            }
        } catch ( \Throwable $e ) {
            Debug::verbose( 'AS_Guard: JSON size measurement failed, estimating instead', $e->getMessage() );
        }

        // Rough estimation for failed encoding.
        return self::estimate_size_recursive( $payload, 0, 100000 );
    }

    /**
     * Recursively estimate size with a hard cap to prevent runaway recursion.
     *
     * @param mixed $value   Value to estimate.
     * @param int   $current Current accumulated size.
     * @param int   $max     Maximum size before stopping.
     * @return int
     */
    private static function estimate_size_recursive( $value, int $current, int $max ): int {
        if ( $current >= $max ) {
            return $max;
        }

        if ( is_string( $value ) ) {
            return $current + strlen( $value );
        }

        if ( is_numeric( $value ) ) {
            return $current + strlen( (string) $value );
        }

        if ( is_bool( $value ) ) {
            return $current + 5;
        }

        if ( is_null( $value ) ) {
            return $current + 4;
        }

        if ( is_array( $value ) ) {
            $size = $current + 2; // []
            foreach ( $value as $k => $v ) {
                if ( $size >= $max ) {
                    return $max;
                }
                $size += strlen( (string) $k ) + 3; // key + ":"
                $size = self::estimate_size_recursive( $v, $size, $max );
            }
            return $size;
        }

        if ( is_object( $value ) ) {
            return $current + 50; // rough estimate for objects
        }

        return $current + 10;
    }

    /**
     * Shrink payload to fit within size limits.
     *
     * @param array $payload   The payload to shrink.
     * @param int   $max_bytes Maximum allowed size.
     * @return array The shrunk payload.
     */
    private static function shrink( array $payload, int $max_bytes ): array {
        // Quick check if already small enough.
        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $json !== false && strlen( $json ) <= $max_bytes ) {
            return $payload;
        }

        // Remove known heavy keys if present.
        $strip = array( 'request', 'server', 'cookies', 'headers', 'files', 'raw_data', 'session', 'environment' );
        foreach ( $strip as $k ) {
            if ( isset( $payload[ $k ] ) ) {
                unset( $payload[ $k ] );
            }
        }

        // Truncate long strings and bound array sizes.
        $payload = self::truncate_deep(
            $payload,
            array(
                'max_depth'        => 5,
                'max_items'        => 50,
                'max_string_bytes' => 500,
            )
        );

        // Check if now small enough.
        $json = wp_json_encode( $payload );
        if ( $json !== false && strlen( $json ) <= $max_bytes ) {
            return $payload;
        }

        // Log a safe, size-limited warning (no full payload dump).
        $final_size = $json !== false ? strlen( $json ) : self::estimate_size( $payload );
        Debug::warning(
            sprintf(
                'Logify WP: payload still too large after truncation (%d bytes > %d limit), using stub',
                $final_size,
                $max_bytes
            )
        );

        // Last resort stub.
        return array(
            'message' => isset( $payload['message'] ) ? substr( (string) $payload['message'], 0, 300 ) : '',
            'note'    => 'payload_truncated',
            'ts'      => time(),
        );
    }

    /**
     * Recursively truncate values to fit within limits.
     *
     * @param mixed $value  Value to truncate.
     * @param array $limits Truncation limits.
     * @param int   $depth  Current recursion depth.
     * @return mixed Truncated value.
     */
    private static function truncate_deep( $value, array $limits, int $depth = 0 ) {
        if ( $depth >= $limits['max_depth'] ) {
            return is_array( $value ) ? array( '__truncated__' => true ) : $value;
        }

        if ( is_string( $value ) ) {
            return mb_strlen( $value, '8bit' ) > $limits['max_string_bytes']
                ? mb_strcut( $value, 0, $limits['max_string_bytes'], 'UTF-8' ) . '…'
                : $value;
        }

        if ( is_array( $value ) ) {
            $out = array();
            $i   = 0;
            foreach ( $value as $k => $v ) {
                if ( $i >= $limits['max_items'] ) {
                    $out['__more__'] = true;
                    break;
                }
                $out[ $k ] = self::truncate_deep( $v, $limits, $depth + 1 );
                ++$i;
            }
            return $out;
        }

        return $value;
    }
}
