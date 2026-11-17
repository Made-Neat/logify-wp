<?php
/**
 * Contains the BeaverBuilder_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

/**
 * Tracks Beaver Builder layout saves, template saves, drafts, and settings changes.
 */
final class BeaverBuilder_Tracker {

    /**
     * Whether hooks have been registered.
     *
     * @var bool
     */
    private static bool $hooks_registered = false;

    /**
     * Initialize the tracker.
     */
    public static function init(): void {
        // Register immediately if Beaver Builder is already loaded, otherwise wait for it.
        if ( self::is_beaver_builder_available() ) {
            self::register_hooks();
            return;
        }

        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_register_hooks' ), 20 );
        add_action( 'fl_builder_init', array( __CLASS__, 'register_hooks' ), 20 );
    }

    /**
     * Check if Beaver Builder is available.
     *
     * @return bool
     */
    private static function is_beaver_builder_available(): bool {
        return class_exists( 'FLBuilder' ) || class_exists( 'FLBuilderModel' );
    }

    /**
     * Maybe register hooks if Beaver Builder is available.
     */
    public static function maybe_register_hooks(): void {
        if ( self::is_beaver_builder_available() ) {
            self::register_hooks();
        }
    }

    /**
     * Register Beaver Builder action hooks.
     */
    public static function register_hooks(): void {
        if ( self::$hooks_registered || ! self::is_beaver_builder_available() ) {
            return;
        }

        self::$hooks_registered = true;

        // Layout saved (published).
        add_action( 'fl_builder_after_save_layout', array( __CLASS__, 'on_layout_saved' ), 10, 4 );

        // User template saved.
        add_action( 'fl_builder_after_save_user_template', array( __CLASS__, 'on_template_saved' ), 10, 1 );

        // Draft saved.
        add_action( 'fl_builder_after_save_draft', array( __CLASS__, 'on_draft_saved' ), 10, 2 );

        // Admin settings saved.
        add_action( 'fl_builder_admin_settings_save', array( __CLASS__, 'on_admin_settings_saved' ), 10, 0 );

        // Global settings saved.
        add_action( 'fl_builder_after_save_global_settings', array( __CLASS__, 'on_global_settings_saved' ), 10, 0 );
    }

    /**
     * Get acting user context and IP for logging.
     *
     * @return array{0:?int,1:?string}
     */
    private static function get_context(): array {
        $acting_user_id = get_current_user_id() ?: null;
        return array( $acting_user_id, User_Utility::get_ip() );
    }

    /**
     * Convenience wrapper to create events with user/IP context.
     *
     * @param string                   $event_type Event label.
     * @param null|object|array|string $object_ref Event object reference.
     * @param ?array                   $eventmetas Event meta entries.
     * @param ?array                   $properties Event properties.
     * @return ?Event
     */
    private static function create_event( string $event_type, $object_ref, ?array $eventmetas = null, ?array $properties = null ): ?Event {
        list( $acting_user_id, $client_ip ) = self::get_context();
        return Event::create( $event_type, $object_ref, $eventmetas, $properties, $acting_user_id, false, $client_ip );
    }

    /**
     * Called when a Beaver Builder layout is saved (published).
     *
     * Parameters are deliberately untyped. This is a THIRD-PARTY hook: Beaver Builder
     * decides what it passes, and it can change between their releases. It fires
     *   do_action( 'fl_builder_after_save_layout', $post_id, $publish, $data, $settings )
     * where $settings comes from FLBuilderModel::get_layout_settings() and is a stdClass,
     * not an array. Declaring `array $settings` turned that into an uncaught TypeError
     * inside Beaver Builder's save AJAX request, which killed the editor's save response
     * on a live client site.
     *
     * Never type-hint parameters on hooks owned by other plugins or by core.
     *
     * $data and $settings are not used here; they are accepted only to match the hook's
     * arity, and defaulted so a future Beaver Builder release passing fewer arguments
     * cannot fatal either.
     *
     * @param mixed $post_id  ID of the post.
     * @param mixed $publish  Whether the layout was published.
     * @param mixed $data     Layout data. Unused.
     * @param mixed $settings Layout settings. Unused, and not necessarily an array.
     */
    public static function on_layout_saved( $post_id, $publish = false, $data = null, $settings = null ): void {
        // Only log if the layout was actually published.
        if ( ! $publish ) {
            return;
        }

        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $object_ref = new Object_Reference( 'beaverbuilder', $post_id, $post->post_title );

        $properties = array(
            new Property( 'post_id', null, (string) $post_id ),
            new Property( 'post_title', null, $post->post_title ),
            new Property( 'post_type', null, $post->post_type ),
        );

        $event = self::create_event( 'Beaver Builder Layout Published', $object_ref, null, $properties );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Called when a Beaver Builder user template is saved.
     *
     * @param int $post_id ID of the template post.
     */
    public static function on_template_saved( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $object_ref = new Object_Reference( 'beaverbuilder', $post_id, $post->post_title ?: $post->post_name );

        $properties = array(
            new Property( 'post_id', null, (string) $post_id ),
            new Property( 'template_name', null, $post->post_title ?: $post->post_name ),
        );

        $event = self::create_event( 'Beaver Builder Template Saved', $object_ref, null, $properties );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Called when a Beaver Builder draft is saved.
     *
     * @param int  $post_id ID of the post.
     * @param bool $publish Whether the draft was published (usually false for drafts).
     */
    public static function on_draft_saved( $post_id, $publish = false ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $object_ref = new Object_Reference( 'beaverbuilder', $post_id, $post->post_title );

        $properties = array(
            new Property( 'post_id', null, (string) $post_id ),
            new Property( 'post_title', null, $post->post_title ),
        );

        $event = self::create_event( 'Beaver Builder Draft Saved', $object_ref, null, $properties );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Called when Beaver Builder admin settings are saved.
     */
    public static function on_admin_settings_saved(): void {
        // No specific object reference for global settings.
        $object_ref = new Object_Reference( 'beaverbuilder', null, 'Admin Settings' );

        $event = self::create_event( 'Beaver Builder Admin Settings Saved', $object_ref );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Called when Beaver Builder global settings are saved.
     */
    public static function on_global_settings_saved(): void {
        $object_ref = new Object_Reference( 'beaverbuilder', null, 'Global Settings' );

        $event = self::create_event( 'Beaver Builder Global Settings Saved', $object_ref );
        if ( $event ) {
            $event->save();
        }
    }
}
