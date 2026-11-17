<?php
namespace Logify_WP;

/**
 * Utility helpers for Gravity Forms notifications.
 */
class Gravitynotification_Utility extends Object_Utility {
    /**
     * Notifications do not have a direct API lookup identifier, so we provide
     * lightweight metadata and fallbacks that keep rendering safe.
     */
    private static function normalize_id( int|string $notification_id ): string {
        return (string) $notification_id;
    }

    /**
     * {@inheritDoc}
     */
    public static function exists( int|string $object_key ): bool {
        $id = self::normalize_id( $object_key );

        // Without a deterministic lookup, assume a non-empty identifier denotes a stored notification.
        return $id !== '';
    }

    /**
     * {@inheritDoc}
     */
    public static function load( int|string $object_key ): mixed {
        // Gravity Forms notifications are stored inside form definitions; without context we cannot
        // hydrate the full notification. Returning null keeps downstream logic defensive.
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public static function get_name( int|string $object_key ): ?string {
        $notification_id = self::normalize_id( $object_key );
        if ( $notification_id === '' ) {
            return null;
        }

        return 'Notification ' . $notification_id;
    }

    /**
     * {@inheritDoc}
     */
    public static function get_core_properties( int|string $object_key ): ?array {
        $id = self::normalize_id( $object_key );
        if ( $id === '' ) {
            return null;
        }

        return array(
            new Property( 'id', null, $id ),
        );
    }

    /**
     * {@inheritDoc}
     */
    public static function get_tag( int|string $object_key, ?string $old_name = null ): string {
        $label = $old_name ?: self::get_name( $object_key );
        if ( ! $label ) {
            return '';
        }

        return esc_html( $label );
    }
}
