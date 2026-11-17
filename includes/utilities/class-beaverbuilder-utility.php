<?php
/**
 * Contains the Beaverbuilder_Utility class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

/**
 * Utility functions for working with Beaver Builder objects.
 *
 * Beaver Builder layouts are associated with posts, so we leverage WP_Post.
 */
class Beaverbuilder_Utility extends Object_Utility {

    /**
     * Check if a Beaver Builder layout exists.
     *
     * @param int|string|null $post_id Post ID associated with the layout.
     * @return bool True if the post exists.
     */
    public static function exists( $post_id ): bool {
        if ( ! is_numeric( $post_id ) ) {
            return false;
        }
        return get_post_status( (int) $post_id ) !== false;
    }

    /**
     * Load the post associated with a Beaver Builder layout.
     *
     * @param int|string|null $post_id Post ID.
     * @return ?\WP_Post Post object or null if not found.
     */
    public static function load( $post_id ): ?\WP_Post {
        if ( ! is_numeric( $post_id ) ) {
            return null;
        }
        $post = get_post( (int) $post_id );
        return $post instanceof \WP_Post ? $post : null;
    }

    /**
     * Get the name/title of the Beaver Builder layout.
     *
     * @param int $post_id Post ID.
     * @return ?string Post title or null if not found.
     */
    public static function get_name( $post_id ): ?string {
        $post = static::load( $post_id );
        return $post ? $post->post_title : null;
    }

    /**
     * Get core properties of a Beaver Builder layout.
     *
     * @param int $post_id Post ID.
     * @return ?array Properties array or null if not found.
     */
    public static function get_core_properties( $post_id ): ?array {
        $post = static::load( $post_id );
        if ( ! $post ) {
            return null;
        }

        return array(
            new Property( 'id', null, $post->ID ),
            new Property( 'title', null, $post->post_title ),
            new Property( 'post_type', null, $post->post_type ),
            new Property( 'status', null, $post->post_status ),
        );
    }

    /**
     * Get a tag (link or text) for the Beaver Builder layout.
     *
     * @param int     $post_id Post ID.
     * @param ?string $title   Optional title to use.
     * @return string HTML tag.
     */
    public static function get_tag( $post_id, ?string $title = null ): string {
        if ( ! $post_id ) {
            return '';
        }

        $post = static::load( $post_id );

        // Try to get title if not provided.
        if ( ! $title && $post ) {
            $title = $post->post_title;
        }
        if ( ! $title ) {
            $title = sprintf( 'Layout #%d', $post_id );
        }

        // If we can edit the post, link to the Beaver Builder editor.
        if ( $post && current_user_can( 'edit_post', $post_id ) ) {
            // Check if FLBuilderModel exists and get the edit URL.
            if ( class_exists( 'FLBuilderModel' ) ) {
                $edit_url = \FLBuilderModel::get_edit_url( $post_id );
                if ( $edit_url ) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        esc_url( $edit_url ),
                        esc_html( $title )
                    );
                }
            }

            // Fallback to regular edit link.
            return sprintf(
                '<a href="%s">%s</a>',
                esc_url( get_edit_post_link( $post_id ) ),
                esc_html( $title )
            );
        }

        return esc_html( $title );
    }
}
