<?php
namespace Logify_WP;

/**
 * Utility functions for working with Gravity Forms objects.
 */
class Gravityform_Utility extends Object_Utility {
    /**
     * Check if a form exists.
     *
     * @param int $form_id Form ID to check.
     * @return bool True if form exists.
     */
    public static function exists( $form_id ): bool {
        if ( ! class_exists( 'GFAPI' ) ) {
            return false;
        }
        return ! is_wp_error( \GFAPI::get_form( $form_id ) );
    }

    /**
     * Load a form by ID.
     *
     * @param int $form_id Form ID to load.
     * @return ?array Form array or null if not found.
     */
    public static function load( $form_id ): ?array {
        if ( ! class_exists( 'GFAPI' ) ) {
            return null;
        }
        $form = \GFAPI::get_form( $form_id );
        return is_wp_error( $form ) ? null : $form;
    }

    /**
     * Get form name/title.
     *
     * @param int $form_id Form ID.
     * @return ?string Form title or null if not found.
     */
    public static function get_name( $form_id ): ?string {
        $form = static::load( $form_id );
        return $form ? $form['title'] : null;
    }

    /**
     * Get core properties of a form.
     *
     * @param int $form_id Form ID.
     * @return ?array Properties array or null if form not found.
     */
    public static function get_core_properties( $form_id ): ?array {
        $form = static::load( $form_id );
        if ( ! $form ) {
            return null;
        }

        return array(
            new Property( 'id', $form_id ),
            new Property( 'title', $form['title'] ),
            new Property( 'is_active', isset( $form['is_active'] ) ? ($form['is_active'] ? '1' : '0') : null ),
            new Property( 'version', isset( $form['version'] ) ? (string) $form['version'] : null ),
        );
    }

    /**
     * Get a tag (link or text) for the form.
     *
     * @param int     $form_id Form ID.
     * @param ?string $title   Optional title to use.
     * @return string HTML tag.
     */
    public static function get_tag( $form_id, ?string $title = null ): string {
        if ( ! $form_id ) {
            return '';
        }

        // Try to get title if not provided.
        if ( ! $title ) {
            $title = static::get_name( $form_id );
        }
        if ( ! $title ) {
            $title = sprintf( 'Form %d', $form_id );
        }

        // If we can edit forms, link to the form editor.
        if ( current_user_can( 'gravityforms_edit_forms' ) ) {
            return sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=gf_edit_forms&id=' . $form_id ) ),
                esc_html( $title )
            );
        }

        return esc_html( $title );
    }
}