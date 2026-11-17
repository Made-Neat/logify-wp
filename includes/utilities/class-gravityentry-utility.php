<?php
namespace Logify_WP;

/**
 * Utility helpers for Gravity Forms entries.
 */
class Gravityentry_Utility extends Object_Utility {
    /**
     * Determine if the Gravity Forms API is available.
     */
    private static function is_api_ready(): bool {
        return class_exists( '\\GFAPI' );
    }

    /**
     * Fetch a Gravity Forms entry.
     *
     * @param int $entry_id Entry identifier.
     * @return ?array Entry payload or null when unavailable.
     */
    private static function get_entry( int $entry_id ): ?array {
        if ( ! $entry_id || ! self::is_api_ready() ) {
            return null;
        }

        $entry = \GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) ) {
            return null;
        }

        return is_array( $entry ) ? $entry : null;
    }

    /**
     * {@inheritDoc}
     */
    public static function exists( int|string $object_key ): bool {
        $entry_id = (int) $object_key;
        return (bool) self::get_entry( $entry_id );
    }

    /**
     * {@inheritDoc}
     */
    public static function load( int|string $object_key ): mixed {
        $entry_id = (int) $object_key;
        return self::get_entry( $entry_id );
    }

    /**
     * {@inheritDoc}
     */
    public static function get_name( int|string $object_key ): ?string {
        $entry_id = (int) $object_key;
        if ( ! $entry_id ) {
            return null;
        }

        return 'Entry #' . $entry_id;
    }

    /**
     * {@inheritDoc}
     */
    public static function get_core_properties( int|string $object_key ): ?array {
        $entry = self::get_entry( (int) $object_key );
        if ( ! $entry ) {
            return null;
        }

        $properties = array(
            new Property( 'entry_id', null, (string) ( $entry['id'] ?? $object_key ) ),
        );

        if ( isset( $entry['form_id'] ) ) {
            $properties[] = new Property( 'form_id', null, (string) $entry['form_id'] );
        }

        if ( isset( $entry['status'] ) ) {
            $properties[] = new Property( 'status', null, (string) $entry['status'] );
        }

        if ( isset( $entry['date_created'] ) ) {
            $properties[] = new Property( 'date_created', null, (string) $entry['date_created'] );
        }

        if ( isset( $entry['ip'] ) ) {
            $properties[] = new Property( 'ip', null, (string) $entry['ip'] );
        }

        if ( isset( $entry['payment_status'] ) ) {
            $properties[] = new Property( 'payment_status', null, (string) $entry['payment_status'] );
        }

        return $properties;
    }

    /**
     * {@inheritDoc}
     */
    public static function get_tag( int|string $object_key, ?string $old_name = null ): string {
        $entry_id = (int) $object_key;
        if ( ! $entry_id ) {
            return '';
        }

        $label = $old_name ?: self::get_name( $entry_id );
        $entry = self::get_entry( $entry_id );
        $form_id = $entry['form_id'] ?? null;

        if ( $form_id && current_user_can( 'gravityforms_view_entries' ) ) {
            $url = admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $form_id, $entry_id ) );
            return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
        }

        return esc_html( (string) $label );
    }
}
