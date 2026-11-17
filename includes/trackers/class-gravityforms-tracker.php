<?php
namespace Logify_WP;

/**
 * Tracks Gravity Forms form creations, updates and deletions.
 */
final class GravityForms_Tracker {

    /** @var bool */
    private static bool $hooks_registered = false;

    public static function init(): void {
        // Register immediately if GF is already loaded, otherwise wait for it.
        if ( self::is_gravityforms_available() ) {
            self::register_hooks();
            return;
        }

        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_register_hooks' ], 20 );
        add_action( 'gform_loaded', [ __CLASS__, 'register_hooks' ], 20 );
    }

    private static function maybe_register_hooks(): void {
        if ( self::is_gravityforms_available() ) {
            self::register_hooks();
        }
    }

    private static function is_gravityforms_available(): bool {
        return class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
    }

    private static function register_hooks(): void {
        if ( self::$hooks_registered || ! self::is_gravityforms_available() ) {
            return;
        }

        self::$hooks_registered = true;

        // Form saved (new or updated).
        // gform_after_save_form provides ($form, $is_new)
        add_action( 'gform_after_save_form', array( __CLASS__, 'on_form_saved' ), 10, 2 );

        // Form deleted. Gravity Forms exposes a hook when a form is deleted; use a common name
        // and guard if it doesn't exist in the running GF version.
        add_action( 'gform_after_delete_form', array( __CLASS__, 'on_form_deleted' ), 10, 1 );

        // Track Gravity Forms-related option updates (global GF settings).
        // We'll register a generic updated_option handler that filters for GF option names.
        add_action( 'updated_option', array( __CLASS__, 'on_option_updated' ), 10, 3 );

        // Form duplication.
        add_action( 'gform_post_form_duplicated', array( __CLASS__, 'on_form_duplicated' ), 10, 2 );

        // Field add/delete.
        add_action( 'gform_post_add_field', array( __CLASS__, 'on_field_added' ), 10, 3 );
        add_action( 'gform_post_delete_field', array( __CLASS__, 'on_field_deleted' ), 10, 3 );

        // Entry submission + updates + deletion + status changes.
        add_action( 'gform_after_submission', array( __CLASS__, 'on_entry_submitted' ), 10, 2 );
        add_action( 'gform_post_update_entry', array( __CLASS__, 'on_entry_updated' ), 10, 3 );
        add_action( 'gform_entry_deleted', array( __CLASS__, 'on_entry_deleted' ), 10, 2 );
        add_action( 'gform_post_status_change', array( __CLASS__, 'on_entry_status_changed' ), 10, 4 );

        // Notification save/delete.
        add_action( 'gform_notification_saved', array( __CLASS__, 'on_notification_saved' ), 10, 2 );
        add_action( 'gform_pre_notification_deleted', array( __CLASS__, 'on_notification_deleted' ), 10, 2 );
    }

    /**
     * Get acting user context and IP for logging.
     *
     * @return array{0:?int,1:?string}
     */
    private static function get_context(): array {
        $acting_user_id = get_current_user_id() ?: null;
        return [ $acting_user_id, User_Utility::get_ip() ];
    }

    /**
     * Convenience wrapper to create events with user/IP context.
     *
     * @param string                   $event_type Event label.
     * @param null|object|array|string $object_ref Event object reference.
     * @param ?array                   $eventmetas Event meta entries.
     * @param ?array                   $properties Event properties.
     */
    private static function create_event( string $event_type, $object_ref, ?array $eventmetas = null, ?array $properties = null ): ?Event {
        [ $acting_user_id, $client_ip ] = self::get_context();
        return Event::create( $event_type, $object_ref, $eventmetas, $properties, $acting_user_id, false, $client_ip );
    }

    /**
     * Handle saved form.
     * @param array|object $form Gravity Forms form array/object
     * @param bool $is_new
     */
    public static function on_form_saved( $form, $is_new = false ): void {
        // Normalize to array if object.
        if ( is_object( $form ) ) {
            $form = (array) $form;
        }

        $form_id    = isset( $form['id'] ) ? (int) $form['id'] : null;
        $form_title = isset( $form['title'] ) ? sanitize_text_field( $form['title'] ) : ( $form_id ? "Form {$form_id}" : '' );

        $event_type = $is_new ? 'Gravity Form Created' : 'Gravity Form Updated';

        // Construct a lightweight object reference so event storage knows what this is.
        $object_ref = new Object_Reference( 'gravityform', $form_id, $form_title );

        // Minimal properties to avoid large payloads. Do NOT serialize entire form.
        $properties = array(
            new Property( 'id', null, (string) $form_id ),
            new Property( 'title', null, $form_title ),
        );

        if ( isset( $form['is_active'] ) ) {
            $properties[] = new Property( 'is_active', null, $form['is_active'] ? '1' : '0' );
        }

        if ( isset( $form['version'] ) ) {
            $properties[] = new Property( 'version', null, (string) $form['version'] );
        }

        $event = self::create_event( $event_type, $object_ref, null, $properties );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Handle form deletion.
     * @param int $form_id
     */
    public static function on_form_deleted( $form_id ): void {
        $form_id = (int) $form_id;

        // The form may no longer exist; create an Object_Reference with the id only.
        $object_ref = new Object_Reference( 'gravityform', $form_id, null );

        $event = self::create_event( 'Gravity Form Deleted', $object_ref );
        if ( $event ) {
            $event->save();
        }
    }

    /**
     * Handle option updates and capture basic Gravity Forms settings changes.
     * We do not store the full option value; only a tiny summary.
     *
     * @param string $option
     * @param mixed  $old_value
     * @param mixed  $value
     */
    public static function on_option_updated( $option, $old_value, $value ): void {
        // Only care about Gravity Forms-related option keys.
        if ( ! is_string( $option ) ) {
            return;
        }

        // Skip noisy/large transient-like options that provide no audit value.
        // These contain version info, license data, or cached API responses.
        $skip_patterns = array(
            'gform_version_info',      // Huge JSON blob with all addon versions (~50KB+)
            'gform_update_info',       // Update check cache
            'gform_pending_installation', // Installation state
            '_transient',              // Any transients
            '_site_transient',         // Site transients
            'gf_digest_',              // Digest notifications
            'gform_sticky_',           // Sticky form values (per-user/session)
            'gf_api_',                 // API response caches
        );
        foreach ( $skip_patterns as $skip ) {
            if ( stripos( $option, $skip ) !== false ) {
                return;
            }
        }

        $needle_patterns = array( 'gform', 'gravityform', 'gravityforms', 'rg_gforms', 'gf_' );
        $found = false;
        foreach ( $needle_patterns as $pat ) {
            if ( stripos( $option, $pat ) !== false ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            return;
        }

        // Build a tiny properties summary; avoid serializing large structures.
        $properties = array(
            new Property( 'option', null, $option ),
            new Property( 'value_type', null, gettype( $value ) ),
        );

        if ( is_scalar( $value ) ) {
            $properties[] = new Property( 'value_summary', null, mb_substr( (string) $value, 0, 200 ) );
            $properties[] = new Property( 'value_length', null, (string) mb_strlen( (string) $value ) );
        } elseif ( is_array( $value ) ) {
            $properties[] = new Property( 'value_items', null, (string) count( $value ) );
        } else {
            $properties[] = new Property( 'value_summary', null, 'non-scalar' );
        }

        // Use null for the ID since this is a setting name, not a numeric form ID.
        // This prevents Gravityform_Utility from trying to load it as a form.
        $object_ref = new Object_Reference( 'gravityform', null, "Setting: $option" );

        $event = self::create_event( 'Gravity Forms Settings Updated', $object_ref, null, $properties );
        if ( $event ) {
            $event->save();
        }
    }

    // =============================================================================================
    // Additional Gravity Forms handlers

    public static function on_form_duplicated( $form_id, $new_form_id ): void {
        $object_ref = new Object_Reference( 'gravityform', (int) $new_form_id, 'Duplicated From ' . (int) $form_id );
        $event = self::create_event( 'Gravity Form Duplicated', $object_ref, null, array( new Property( 'source_form_id', null, (string) $form_id ), new Property( 'new_form_id', null, (string) $new_form_id ) ) );
        if ( $event ) { $event->save(); }
    }

    public static function on_field_added( $form_id, $field_id, $form ): void {
        $object_ref = new Object_Reference( 'gravityform', (int) $form_id );
        $props = array( new Property( 'field_id', null, (string) $field_id ), new Property( 'change', null, 'added' ) );
        $event = self::create_event( 'Gravity Form Field Added', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_field_deleted( $form_id, $field_id, $form ): void {
        $object_ref = new Object_Reference( 'gravityform', (int) $form_id );
        $props = array( new Property( 'field_id', null, (string) $field_id ), new Property( 'change', null, 'deleted' ) );
        $event = self::create_event( 'Gravity Form Field Deleted', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_entry_submitted( $entry, $form ): void {
        if ( is_object( $entry ) ) { $entry = (array) $entry; }
        $entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
        $form_id  = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
        $object_ref = new Object_Reference( 'gravityentry', $entry_id, 'Entry #' . $entry_id );
        $props = array( new Property( 'form_id', null, (string) $form_id ), new Property( 'status', null, isset( $entry['status'] ) ? (string) $entry['status'] : '' ) );
        $event = self::create_event( 'Gravity Entry Submitted', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_entry_updated( $entry_id, $updated_fields, $original_entry ): void {
        $object_ref = new Object_Reference( 'gravityentry', (int) $entry_id, 'Entry #' . (int) $entry_id );
        $props = array( new Property( 'updated_fields', null, is_array( $updated_fields ) ? implode( ',', array_keys( $updated_fields ) ) : 'unknown' ) );
        $event = self::create_event( 'Gravity Entry Updated', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_entry_deleted( $entry_id, $form_id ): void {
        $object_ref = new Object_Reference( 'gravityentry', (int) $entry_id, 'Entry #' . (int) $entry_id );
        $props = array( new Property( 'form_id', null, (string) $form_id ) );
        $event = self::create_event( 'Gravity Entry Deleted', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_entry_status_changed( $entry_id, $new_status, $old_status, $form ): void {
        if ( $new_status === $old_status ) { return; }
        $object_ref = new Object_Reference( 'gravityentry', (int) $entry_id, 'Entry #' . (int) $entry_id );
        $props = array( new Property( 'old_status', null, (string) $old_status ), new Property( 'new_status', null, (string) $new_status ) );
        $event = self::create_event( 'Gravity Entry Status Changed', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_notification_saved( $notification, $form ): void {
        if ( is_object( $notification ) ) { $notification = (array) $notification; }
        $id   = isset( $notification['id'] ) ? (string) $notification['id'] : 'unknown';
        $name = isset( $notification['name'] ) ? (string) $notification['name'] : ( 'Notification ' . $id );
        $type = isset( $notification['event'] ) ? (string) $notification['event'] : '';
        $object_ref = new Object_Reference( 'gravitynotification', $id, $name );
        $props = array( new Property( 'type', null, $type ) );
        $event = self::create_event( 'Gravity Notification Saved', $object_ref, null, $props );
        if ( $event ) { $event->save(); }
    }

    public static function on_notification_deleted( $notification_id, $form ): void {
        $object_ref = new Object_Reference( 'gravitynotification', (string) $notification_id, 'Notification ' . (string) $notification_id );
        $event = self::create_event( 'Gravity Notification Deleted', $object_ref );
        if ( $event ) { $event->save(); }
    }
}
