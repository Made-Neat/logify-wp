<?php

declare(strict_types=1);

/**
 * Async tracker for Logify.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Comment;
use WP_Error;
use WP_Post;
use WP_Theme;
use WP_Upgrader;
use WP_User;

/**
 * Class Async_Tracker
 *
 * Enqueues asynchronous tasks for various WordPress events in a safe, deduplicated way.
 * All actions are placed into the "logify" Action Scheduler group for easier management.
 */
class Async_Tracker
{
    /**
     * Action Scheduler group slug for all Logify jobs.
     */
    private const GROUP = 'logify';

    /**
     * Byte margin below the AS payload cap to treat options as large.
     */
    private const LARGE_OPTION_MARGIN = 600;

    /**
     * Maximum size in bytes for individual option values before skipping.
     */
    private const MAX_OPTION_VALUE_SIZE = 10000;

    /**
     * Tracks whether any changes occurred during the request that need logging
     */
    private static array $changes = [
        'media' => false,
        'option' => false,
        'post' => false,
        'user' => false,
        'widget' => false
    ];

    /**
     * Request-scoped identifier so option updates and their shutdown flush stay grouped together.
     */
    private static ?string $optionBatchId = null;

    /**
     * Check if an option value is too large to safely pass through async scheduling.
     *
     * @param mixed $value The option value to check.
     * @return bool True if the value exceeds the safe size limit.
     */
    private static function isOptionValueTooLarge(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        try {
            $size = is_string($value) ? strlen($value) : strlen(serialize($value));
            return $size > self::MAX_OPTION_VALUE_SIZE;
        } catch (\Throwable $e) {
            // If serialization fails, assume it's problematic.
            return true;
        }
    }

    /**
     * Mark a specific type as having changes that need logging
     */
    public static function mark_changed(string $type): void
    {
        if (isset(self::$changes[$type])) {
            self::$changes[$type] = true;
        }
    }

    /**
     * Schedule an async action.
     *
     * Keep this low-level and side effect free. Do not append request-unique values here unless required.
     *
     * @param string $hook       Action Scheduler hook name.
     * @param array  $args       Arguments passed to the action callback.
     * @param bool   $includeIp  Whether to append the client IP as the last argument.
     *
     * @return void
     */
    private static function scheduleAction(string $hook, array $args = [], bool $includeIp = false): void
    {
        if ($includeIp) {
            $args[] = User_Utility::get_ip_context_token();
        }

        // Unique flag is false here. Dedupe is handled by scheduleUnique.
        AS_Guard::enqueue_async($hook, $args, self::GROUP, false);
    }

    /**
     * Schedule an async action only if an identical one is not already pending.
     *
     * The IP address is captured at enqueue time but is NOT part of the deduplication key.
     * This ensures that identical actions triggered at different times/IPs are deduplicated.
     *
     * @param string $hook      Action Scheduler hook name.
     * @param array  $args      Arguments that define uniqueness for this action.
     * @param bool   $includeIp Whether to append the client IP as the last argument.
     *
     * @return void
     */
    private static function scheduleUnique(string $hook, array $args = [], bool $includeIp = false): void
    {
        if (function_exists('as_next_scheduled_action')) {
            if (as_next_scheduled_action($hook, $args, self::GROUP)) {
                return;
            }
        }

        // Add IP after dedup check so it doesn't affect uniqueness matching.
        if ($includeIp) {
            $args[] = User_Utility::get_ip_context_token();
        }

        AS_Guard::enqueue_async($hook, $args, self::GROUP, true);
    }

    /**
     * Error-isolating adapter for WordPress / third-party hooks. Some plugins
     * invoke core hooks with fewer arguments or different runtime types.
     * Preserve a filter's original value if logging fails.
     */
    public static function safe_callback(string $method, bool $filter = false): \Closure
    {
        // Return the SAME callable for hooks registered by multiple trackers.
        // WordPress previously deduplicated identical [Class, method] callbacks.
        static $callbacks = array();
        $key = $method . ( $filter ? ':filter' : ':action' );
        if ( isset( $callbacks[ $key ] ) ) {
            return $callbacks[ $key ];
        }
        $callbacks[ $key ] = static function (...$args) use ($method, $filter) {
            try {
                return self::$method(...$args);
            } catch (\Throwable $e) {
                Debug::warning('Logify WP tracker callback failed', $method, $e->getMessage());
                return $filter ? ($args[0] ?? null) : null;
            }
        };
        return $callbacks[ $key ];
    }

    /* =======================================================================
     * Authentication
     * ======================================================================= */

    /**
     * Track successful login.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     *
     * @return void
     */
    public static function async_wp_login(string $user_login, WP_User $user): void
    {
        self::scheduleAction('middle_wp_login', [$user_login, $user->ID], true);
    }

    /**
     * Track failed login.
     *
     * @param string   $username Attempted username.
     * @param WP_Error $error    Login error.
     *
     * @return void
     */
    public static function async_wp_login_failed($username, $error = null): void
    {
        // A third-party authentication plugin's WP_Error can contain arbitrary
        // error data, request payloads or credential strings. Queue only a
        // bounded code; the failed-login event does not need raw auth messages.
        $code = $error instanceof WP_Error ? sanitize_key((string) $error->get_error_code()) : 'unknown';
        $code = substr($code ?: 'unknown', 0, 80);
        $safeError = new WP_Error($code, 'Login failed');
        self::scheduleAction('middle_wp_login_failed', [(string) $username, serialize($safeError)], true);
    }

    /**
     * Track logout.
     *
     * @param int $user_id User ID.
     *
     * @return void
     */
    public static function async_wp_logout(int $user_id): void
    {
        self::scheduleAction('middle_wp_logout', [$user_id], true);
    }

    /**
     * Track WordPress loaded event once.
     *
     * @return void
     */
    public static function async_wp_loaded(): void
    {
        if (did_action('init') && function_exists('as_has_scheduled_action') && !as_has_scheduled_action('middle_wp_loaded', [], self::GROUP)) {
            $actingUserId = get_current_user_id();
            self::scheduleUnique('middle_wp_loaded', [$actingUserId], true);
        }
    }

    /* =======================================================================
     * Shutdown events, previously the main source of queue flood
     * ======================================================================= */

    /** @return void */
    public static function async_shutdown_media(): void
    {
        if (self::$changes['media']) {
            // Media_Tracker::on_shutdown runs in the same PHP process as the
            // media event. A separate queued shutdown is not useful.
        }
    }

    /** @return void */
    public static function async_shutdown_option(): void
    {
        if (self::$changes['option']) {
            // The option batch is already safely stored by the original request.
            // Do not schedule a separate flush that may run before its writers.
            Option_Tracker::on_shutdown(self::getOptionBatchId());
            self::$changes['option'] = false;
        }
    }

    /** @return void */
    public static function async_shutdown_post(): void
    {
        if (self::$changes['post']) {
            // The originating request and Action Scheduler after-execute hook
            // already flush post events. A separate job loses static state.
        }
    }

    /** @return void */
    public static function async_shutdown_user(): void
    {
        if (self::$changes['user']) {
            // User_Tracker is flushed in its own request / action callback.
        }
    }

    /** @return void */
    public static function async_shutdown_widget(): void
    {
        if (self::$changes['widget']) {
            self::scheduleUnique('middle_shutdown_widget', [], true);
        }
    }

    /* =======================================================================
     * Users
     * ======================================================================= */

    /**
     * Track user registration.
     *
     * @param int   $user_id  User ID.
     * @param array $userdata User data.
     *
     * @return void
     */
    public static function async_user_register(int $user_id, $userdata = array()): void
    {
        // Core's user_register hook may supply the original password in userdata.
        // Strip credentials BEFORE serializing action arguments to Action Scheduler.
        $userdata = is_array($userdata) ? $userdata : array();
        foreach (array_keys($userdata) as $key) {
            if (Property_Repository::is_sensitive_key((string) $key)) {
                unset($userdata[$key]);
            }
        }
        // On front-end self-registration the current user is 0. The consumer falls back
        // to treating the newly registered user as the actor in that case.
        self::scheduleAction('middle_user_register', [$user_id, Sensitive_Data::sanitize($userdata), get_current_user_id()], true);
    }

    /**
     * Track user deletion.
     *
     * @param int       $user_id  Deleted user ID.
     * @param int|null  $reassign Reassigned user ID.
     * @param WP_User   $user     User object snapshot.
     *
     * @return void
     */
    /** Remove credential fields from queued WP_User snapshots. */
    private static function safe_user_snapshot(WP_User $user): string
    {
        $copy = clone $user;
        if (isset($copy->data) && is_object($copy->data)) {
            $copy->data = clone $copy->data;
            unset($copy->data->user_pass, $copy->data->user_activation_key);
        }
        return serialize($copy);
    }

    public static function async_delete_user(int $user_id, ?int $reassign, WP_User $user): void
    {
        self::scheduleAction('middle_delete_user', [$user_id, $reassign, self::safe_user_snapshot($user), get_current_user_id()], true);
    }

    /**
     * Track profile update.
     *
     * WordPress passes the pre-update snapshot; we fetch the current DB row to capture new values.
     *
     * @param int     $user_id User ID.
     * @param WP_User $user    User object before the update.
     *
     * @return void
     */
    public static function async_profile_update(int $user_id, WP_User $user): void
    {
        $actingUserId = get_current_user_id();
        $new_user     = get_userdata($user_id);
        $userdata     = $new_user instanceof WP_User ? (array) $new_user->data : array();
        unset($userdata['user_pass'], $userdata['user_activation_key']);

        self::scheduleAction('middle_profile_update', [$user_id, self::safe_user_snapshot($user), Sensitive_Data::sanitize($userdata), $actingUserId], true);
    }

    /**
     * Track user meta update.
     *
     * @param int    $meta_id    Meta row ID.
     * @param int    $user_id    User ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     *
     * @return void
     */
    public static function async_update_user_meta(int $meta_id, int $user_id, string $meta_key, mixed $meta_value): void
    {
        if (in_array($meta_key, array('session_tokens', 'wp_user_level'), true) || str_ends_with($meta_key, 'capabilities')) {
            // Dedicated user-security events cover roles and sessions.
            return;
        }
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_update_user_meta', [$meta_id, $user_id, $meta_key, Sensitive_Data::sanitize($meta_value, $meta_key), $actingUserId], true);
    }

    /* =======================================================================
     * Options and themes
     * ======================================================================= */

    /**
     * Track option update once per value change triplet.
     *
     * @param string $option           Option name.
     * @param mixed  $old_option_value Old value.
     * @param mixed  $new_option_value New value.
     *
     * @return void
     */
    public static function async_updated_option(string $option, mixed $old_option_value, mixed $new_option_value): void
    {
        if (!str_starts_with($option, 'widget_') && !Option_Utility::should_track_option($option, $old_option_value, $new_option_value)) {
            return;
        }

        // Unknown option payloads are represented by tiny metadata-only markers.
        [$old_option_value, $new_option_value] = Sensitive_Data::option_values($option, $old_option_value, $new_option_value);
        self::mark_changed('widget');
        $actingUserId = get_current_user_id();
        $args         = [$option, $old_option_value, $new_option_value, $actingUserId];

        // Every genuine option update must be queued independently, even if the
        // queue still contains a previous redacted change with identical markers.
        self::scheduleAction('middle_updated_option', $args, true);
    }

    /**
     * Track themes loaded once.
     *
     * @return void
     */
    public static function async_load_themes(): void
    {
        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_load_themes', [], self::GROUP)) {
            self::scheduleUnique('middle_load_themes', [], true);
        }
    }

    /**
     * Track theme install trigger.
     *
     * @return void
     */
    public static function async_load_theme_install(): void
    {
        self::scheduleUnique('middle_load_theme_install', [], true);
    }

    /**
     * Track theme switch.
     *
     * @param string   $new_name  New theme name.
     * @param WP_Theme $new_theme New theme object.
     * @param WP_Theme $old_theme Old theme object.
     *
     * @return void
     */
    public static function async_switch_theme(string $new_name, WP_Theme $new_theme, WP_Theme $old_theme): void
    {
        // Pass small payloads instead of serializing WP_Theme objects
        $new_payload = [
            'name'       => (string) $new_theme->get('Name'),
            'stylesheet' => (string) $new_theme->get_stylesheet(),
            'template'   => (string) $new_theme->get_template(),
        ];
        $old_payload = [
            'name'       => (string) $old_theme->get('Name'),
            'stylesheet' => (string) $old_theme->get_stylesheet(),
            'template'   => (string) $old_theme->get_template(),
        ];

        self::scheduleAction(
            'middle_switch_theme',
            [$new_name, $new_payload, $old_payload, get_current_user_id()],
            true // include client IP
        );
    }

    /**
     * Track theme deletion.
     *
     * @param string $stylesheet Theme stylesheet slug.
     *
     * @return void
     */
    public static function async_delete_theme(string $stylesheet): void
    {
        self::scheduleAction('middle_delete_theme', [$stylesheet, get_current_user_id()], true);
    }

    /* =======================================================================
     * Posts and terms
     * ======================================================================= */

    /**
     * Track save post once per unique set.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  True if update, false if new.
     *
     * @return void
     */
    public static function async_save_post(int $post_id, WP_Post $post, bool $update): void
    {
        self::mark_changed('post');
        $userId     = get_current_user_id();
        $serialized = self::postFilteredData($post);

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_save_post', [$post_id, $serialized, $update, $userId], self::GROUP)) {
            self::scheduleUnique('middle_save_post', [$post_id, $serialized, $update, $userId], true);
        }
    }

    /**
     * Track pre post update once per unique set.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Update payload.
     *
     * @return void
     */
    public static function async_pre_post_update(int $post_id, array $data): void
    {
        $userId = get_current_user_id();
        $data = Sensitive_Data::sanitize($data);

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_pre_post_update', [$post_id, $data, $userId], self::GROUP)) {
            self::scheduleUnique('middle_pre_post_update', [$post_id, $data, $userId], true);
        }
    }

    /**
     * Track post updated once per unique set.
     *
     * @param int     $post_id     Post ID.
     * @param WP_Post $post_after  Post after update.
     * @param WP_Post $post_before Post before update.
     *
     * @return void
     */
    public static function async_post_updated(int $post_id, WP_Post $post_after, WP_Post $post_before): void
    {
        $after  = self::postFilteredData($post_after);
        $before = self::postFilteredData($post_before);
        $userId = get_current_user_id();

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_post_updated', [$post_id, $after, $before, $userId], self::GROUP)) {
            self::scheduleUnique('middle_post_updated', [$post_id, $after, $before, $userId], true);
        }
    }

    /**
     * Track post meta update once per unique set.
     *
     * @param int    $meta_id   Meta row ID.
     * @param int    $post_id   Post ID.
     * @param string $meta_key  Meta key.
     * @param mixed  $meta_value Meta value.
     *
     * @return void
     */
    public static function async_update_post_meta(int $meta_id, int $post_id, string $meta_key, mixed $meta_value): void
    {
        $userId = get_current_user_id();

        // A sensitive meta value may be represented by the same marker every
        // time. Do not deduplicate separate updates against pending jobs.
        self::scheduleAction('middle_update_post_meta', [$meta_id, $post_id, $meta_key, Sensitive_Data::sanitize($meta_value, $meta_key), $userId], true);
    }

    /**
     * Track post status transition once per unique set.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post object.
     *
     * @return void
     */
    public static function async_transition_post_status(string $new_status, string $old_status, WP_Post $post): void
    {
        $serialized = self::postFilteredData($post);
        $userId     = get_current_user_id();

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_transition_post_status', [$new_status, $old_status, $serialized, $userId], self::GROUP)) {
            self::scheduleUnique('middle_transition_post_status', [$new_status, $old_status, $serialized, $userId], true);
        }
    }

    /**
     * Track pre delete post once per unique set.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     *
     * @return void
     */
    public static function async_before_delete_post(int $post_id, WP_Post $post): void
    {
        $serialized = self::postFilteredData($post);
        $userId     = get_current_user_id();

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('middle_before_delete_post', [$post_id, $serialized, $userId], self::GROUP)) {
            self::scheduleUnique('middle_before_delete_post', [$post_id, $serialized, $userId], true);
        }
    }

    /**
     * Track post deletion.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     *
     * @return void
     */
    public static function async_delete_post(int $post_id, WP_Post $post): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_delete_post', [$post_id, self::postFilteredData($post), $actingUserId], true);
    }

    /**
     * Track added term relationship.
     *
     * @param int    $post_id  Post ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     *
     * @return void
     */
    public static function async_added_term_relationship(int $post_id, int $tt_id, string $taxonomy): void
    {
        self::scheduleAction('middle_added_term_relationship', [$post_id, $tt_id, $taxonomy, get_current_user_id()], true);
    }

    /**
     * Track post insert after save.
     *
     * @param int          $post_id     Post ID.
     * @param WP_Post      $post        Post object.
     * @param bool         $update      Update flag.
     * @param WP_Post|null $post_before Previous post or null.
     *
     * @return void
     */
    public static function async_wp_after_insert_post(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void
    {
        $serialized       = self::postFilteredData($post);
        $serializedBefore = self::postFilteredData($post_before);
        $userId           = get_current_user_id();

        self::scheduleAction('middle_wp_after_insert_post', [$post_id, $serialized, $update, $serializedBefore, $userId], true);
    }

    /**
     * Track deleted term relationships.
     *
     * @param int    $post_id  Post ID.
     * @param array  $tt_ids   Term taxonomy IDs.
     * @param string $taxonomy Taxonomy slug.
     *
     * @return void
     */
    public static function async_deleted_term_relationships(int $post_id, array $tt_ids, string $taxonomy): void
    {
        self::scheduleAction('middle_deleted_term_relationships', [$post_id, $tt_ids, $taxonomy, get_current_user_id()], true);
    }

    /**
     * Track term creation.
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $args     Additional args.
     *
     * @return void
     */
    public static function async_created_term(int $term_id, int $tt_id, string $taxonomy, array $args): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_created_term', [$term_id, $tt_id, $taxonomy, $args, $actingUserId], true);
    }

    /**
     * Track term edit.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $args     Updated args.
     *
     * @return void
     */
    public static function async_edit_terms(int $term_id, string $taxonomy, array $args): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_edit_terms', [$term_id, $taxonomy, $args, $actingUserId], true);
    }

    /**
     * Track pre delete term.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     *
     * @return void
     */
    public static function async_pre_delete_term(int $term_id, string $taxonomy): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_pre_delete_term', [$term_id, $taxonomy, $actingUserId], true);
    }

    /* =======================================================================
     * Media
     * ======================================================================= */

	/**
	 * Track add attachment.
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return void
	 */
	public static function async_add_attachment(int $post_id): void
	{
		self::mark_changed('media');
		$actingUserId = get_current_user_id();
		self::scheduleAction('middle_add_attachment', [$post_id, $actingUserId], true);
	}    
    
    /**
     * Track added post meta.
     *
     * @param int    $post_id   Post ID.
     * @param string $meta_key  Meta key.
     * @param mixed  $meta_value Meta value.
     *
     * @return void
     */
    public static function async_add_post_meta(int $post_id, string $meta_key, mixed $meta_value): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_add_post_meta', [$post_id, $meta_key, Sensitive_Data::sanitize($meta_value, $meta_key), $actingUserId], true);
    }

	/**
	 * Track attachment update.
	 *
	 * @param int     $post_id     Attachment ID.
	 * @param WP_Post $post_after  After update.
	 * @param WP_Post $post_before Before update.
	 *
	 * @return void
	 */
    public static function async_attachment_updated(int $post_id, WP_Post $post_after, WP_Post $post_before): void
    {
        self::mark_changed('media');
        $actingUserId = get_current_user_id();
        self::scheduleAction(
            'middle_attachment_updated',
            [$post_id, self::postFilteredData($post_after), self::postFilteredData($post_before), $actingUserId],
            true
        );
    }

    /**
     * Track attachment deletion.
     *
     * @param int     $post_id Attachment ID.
     * @param WP_Post $post    Attachment object.
     *
     * @return void
     */
    public static function async_delete_attachment(int $post_id, WP_Post $post): void
    {
        self::mark_changed('media');
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_delete_attachment', [$post_id, self::postFilteredData($post), $actingUserId], true);
    }    /* =======================================================================
     * Core, plugins, comments
     * ======================================================================= */

    /**
     * Track successful core update.
     *
     * @param string $wp_version New WP version.
     *
     * @return void
     */
    public static function async_core_updated_successfully(string $wp_version): void
    {
        // Capture the acting user HERE. Action Scheduler workers run unauthenticated
        // as user 0, so an acting user not carried across the queue is lost, and
        // Event::create() then discards the event. See F-09.
        // Core updates can also be automatic, in which case there is no user at all -
        // the consumer passes all_users:true so the event is recorded either way.
        self::scheduleAction('middle_core_updated_successfully', [$wp_version, get_current_user_id()], true);
    }

    /**
     * Track theme upgrader completion.
     *
     * @param WP_Upgrader $upgrader   Upgrader instance.
     * @param array       $hook_extra Extra data.
     *
     * @return void
     */
    public static function async_upgrader_process_complete_theme(WP_Upgrader $upgrader, array $hook_extra): void
    {
        $payload = (object) [
            'new_theme_data' => isset($upgrader->new_theme_data) ? $upgrader->new_theme_data : null,
            'result'         => isset($upgrader->result) ? [
                'clear_destination' => $upgrader->result['clear_destination'] ?? null,
            ] : null,
            'skin'           => (object) [
                'theme_info' => isset($upgrader->skin->theme_info) ? $upgrader->skin->theme_info : null,
            ],
        ];

        $userId = get_current_user_id();
        self::scheduleAction('middle_upgrader_process_complete_theme', [$payload, $hook_extra, $userId], true);
    }

    /**
     * Track plugin upgrader completion.
     *
     * @param WP_Upgrader $upgrader   Upgrader instance.
     * @param array       $hook_extra Extra data.
     *
     * @return void
     */
    public static function async_upgrader_process_complete_plugin(WP_Upgrader $upgrader, array $hook_extra): void
    {
        // WordPress can invoke updater callbacks during a failed/rolled-back
        // installation. Never queue a successful-upgrade label for an error,
        // missing install result or incomplete package operation.
        if ( ! isset( $upgrader->result ) || ! is_array( $upgrader->result )
            || is_wp_error( $upgrader->result ) ) {
            return;
        }
        $payload = (object) [
            'new_plugin_data' => isset($upgrader->new_plugin_data) ? $upgrader->new_plugin_data : null,
            'result'          => isset($upgrader->result) ? [
                'clear_destination' => $upgrader->result['clear_destination'] ?? null,
            ] : null,
            'skin'            => (object) [
                'plugin_info' => isset($upgrader->skin->plugin_info) ? $upgrader->skin->plugin_info : null,
            ],
        ];

        $userId = get_current_user_id();

        self::scheduleAction('middle_upgrader_process_complete_plugin', [$payload, $hook_extra, $userId], true);
    }

    /**
     * Track plugin activation.
     *
     * @param string $plugin_file Plugin file.
     * @param bool   $network_wide Network wide flag.
     *
     * @return void
     */
    public static function async_activate_plugin(string $plugin_file, bool $network_wide): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_activate_plugin', [$plugin_file, $network_wide, $actingUserId], true);
    }

    /**
     * Track plugin deactivation.
     *
     * @param string $plugin_file         Plugin file.
     * @param bool   $network_deactivating Network deactivating flag.
     *
     * @return void
     */
    public static function async_deactivate_plugin(string $plugin_file, bool $network_deactivating): void
    {
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_deactivate_plugin', [$plugin_file, $network_deactivating, $actingUserId], true);
    }

    /**
     * Track plugin deletion.
     *
     * @param string $plugin_file Plugin file.
     *
     * @return void
     */
    public static function async_delete_plugin(string $plugin_file): void
    {
        $plugin = Plugin_Utility::load_by_file($plugin_file);
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_delete_plugin', [$plugin, $actingUserId], true);
    }

    /**
     * Track plugin pre uninstall.
     *
     * @param string $plugin_file           Plugin file.
     * @param array  $uninstallable_plugins Uninstallable plugin list.
     *
     * @return void
     */
    public static function async_pre_uninstall_plugin(string $plugin_file, array $uninstallable_plugins): void
    {
        $plugin = Plugin_Utility::load_by_file($plugin_file);
        $actingUserId = get_current_user_id();
        self::scheduleAction('middle_pre_uninstall_plugin', [$plugin, $uninstallable_plugins, $actingUserId], true);
    }

    /**
     * Track plugin option update once per unique set.
     *
     * @param string $option Option name.
     * @param mixed  $old_value Old value.
     * @param mixed  $value New value.
     *
     * @return void
     */
    public static function async_update_option_plugin(string $option, mixed $old_value, mixed $value): void
    {
        // The plugin tracker consumes only the core auto-update plugin list.
        // Other settings are handled by Option_Tracker where appropriate.
        if ($option !== 'auto_update_plugins' || !Option_Utility::should_track_option($option, $old_value, $value)) {
            return;
        }

        // Unknown option payloads are represented by tiny metadata-only markers.
        [$old_value, $value] = Sensitive_Data::option_values($option, $old_value, $value);
        $actingUserId = get_current_user_id();
        
        // Do not deduplicate on redacted values; distinct updates have identical markers.
        self::scheduleAction('middle_update_option_plugin', [$option, $old_value, $value, $actingUserId], true);
    }

    /**
     * Track general option update once per unique set.
     *
     * Only runs for real settings, and only for options we actually care about.
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Old value.
     * @param mixed  $value     New value.
     *
     * @return void
     */
    public static function async_update_option_option(string $option, mixed $old_value, mixed $value): void
    {
        $is_widget_option = str_starts_with($option, 'widget_') || $option === 'sidebars_widgets';

        if (! $is_widget_option && !Option_Utility::should_track_option($option, $old_value, $value)) {
            return;
        }

        // Capture on the original request. Sanitization happens BEFORE the
        // batch reaches wp_options; the batch is flushed on that request's
        // shutdown, so Action Scheduler cannot run a writer after the flush.
        [$safe_old, $safe_new] = Sensitive_Data::option_values($option, $old_value, $value);
        self::mark_changed('option');
        Option_Tracker::capture_option_change_now(
            self::getOptionBatchId(), $option, $safe_old, $safe_new,
            get_current_user_id(), User_Utility::get_ip_context_token()
        );
    }

    /**
     * Decide whether an option change should bypass async transport to avoid payload truncation.
     */
    private static function shouldCaptureOptionSynchronously(string $option, mixed $old_value, mixed $new_value): bool
    {
        $lower = strtolower($option);
        if (str_contains($lower, 'bricks')) {
            return true;
        }

        $estimated = wp_json_encode([$old_value, $new_value]);
        if ($estimated === false) {
            return false;
        }

        $bytes = strlen($estimated);
        $limit = AS_Guard::max_bytes() - self::LARGE_OPTION_MARGIN;

        return $bytes > $limit;
    }

    /**
     * Generate or reuse the unique option batch identifier for this request.
     */
    private static function getOptionBatchId(): string
    {
        if (self::$optionBatchId === null) {
            self::$optionBatchId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('logify_opt_', true);
        }

        return self::$optionBatchId;
    }


    /**
     * Track widget option update once per unique set.
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Previous value.
     * @param mixed  $value     New value.
     *
     * @return void
     */
    public static function async_update_option_widget(string $option, mixed $old_value, mixed $value): void
    {
        if ($option !== 'sidebars_widgets' && !str_starts_with($option, 'widget_') && !Option_Utility::should_track_option($option, $old_value, $value)) {
            return;
        }

        // Redact even very large widget payloads; never silently lose the event.
        [$old_value, $value] = Sensitive_Data::option_values($option, $old_value, $value);
        self::mark_changed('widget');
        $userId = get_current_user_id();

        self::scheduleAction('middle_update_option_widget', [$option, $old_value, $value, $userId], true);
    }


    /* =======================================================================
     * Comments, guarded by plugin setting
     * ======================================================================= */

    /**
     * Track comment insert.
     *
     * @param int        $id      Comment ID.
     * @param WP_Comment $comment Comment object.
     *
     * @return void
     */
    public static function async_wp_insert_comment(int $id, WP_Comment $comment): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        // WordPress stores a comment-author IP; retain it only as an unverified
        // reported candidate when it differs from the verified connection.
        AS_Guard::enqueue_async('middle_wp_insert_comment', [$id, self::postFilteredData($comment), get_current_user_id(), User_Utility::get_comment_ip_context_token($comment)], self::GROUP, false);
    }

    /**
     * Track comment data update.
     *
     * @param array|WP_Error $data       New data or error.
     * @param array          $comment    Original comment array.
     * @param array          $commentarr Extra data.
     *
     * @return array|WP_Error
     */
    public static function async_wp_update_comment_data($data, $comment = [], $commentarr = [])
    {
        // Untyped on purpose. This is a CORE filter, and its value is whatever the
        // previous callback in the chain returned - which may be another plugin, and may
        // not be an array or a WP_Error. Declaring types here would turn someone else's
        // odd return value into an uncaught TypeError inside wp_update_comment(), the
        // same failure mode that took down Beaver Builder saves. See
        // tests/test-hook-signatures.php.
        //
        // This callback is observational only: it MUST return $data byte-identical to
        // what it received. wp_update_comment() runs
        //   $data = wp_array_slice_assoc( $data, $keys );
        //   $wpdb->update( $wpdb->comments, $data, ... );
        // so a filter that alters or reduces $data produces an empty SET clause and a
        // SQL error. Never modify $data here.
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return $data;
        }

        // Only enqueue when the payload is the shape we expect. Anything else is passed
        // straight through untouched.
        if (is_array($data) && is_array($comment) && is_array($commentarr)) {
            self::scheduleAction('middle_wp_update_comment_data', [$data, $comment, $commentarr, get_current_user_id()], true);
        }

        return $data;
    }

    /**
     * Track comment edit.
     *
     * @param int   $comment_id Comment ID.
     * @param array $data       Edited data.
     *
     * @return void
     */
    public static function async_edit_comment(int $comment_id, array $data): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        self::scheduleAction('middle_edit_comment', [$comment_id, $data, get_current_user_id()], true);
    }

    /**
     * Track comment deletion.
     *
     * @param string     $comment_id Comment ID.
     * @param WP_Comment $comment    Comment object.
     *
     * @return void
     */
    public static function async_delete_comment(string $comment_id, WP_Comment $comment): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        self::scheduleAction('middle_delete_comment', [$comment_id, self::postFilteredData($comment), get_current_user_id()], true);
    }

    /**
     * Track comment status transition.
     *
     * @param int|string $new_status New status.
     * @param int|string $old_status Old status.
     * @param WP_Comment $comment    Comment object.
     *
     * @return void
     */
    public static function async_transition_comment_status(int|string $new_status, int|string $old_status, WP_Comment $comment): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        self::scheduleAction('middle_transition_comment_status', [$new_status, $old_status, self::postFilteredData($comment), get_current_user_id()], true);
    }

    /**
     * Track trashed post comments.
     *
     * @param int   $post_id  Post ID.
     * @param array $statuses Status summary.
     *
     * @return void
     */
    public static function async_trashed_post_comments(int $post_id, array $statuses): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        self::scheduleAction('middle_trashed_post_comments', [$post_id, $statuses, get_current_user_id()], true);
    }

    /**
     * Track untrash post comments.
     *
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public static function async_untrash_post_comments(int $post_id): void
    {
        if (!Plugin_Settings::get_comment_tracking_state()) {
            return;
        }

        self::scheduleAction('middle_untrash_post_comments', [$post_id, get_current_user_id()], true);
    }

    /* =======================================================================
     * Internal helpers
     * ======================================================================= */

    /**
     * Reduce large WP objects to a compact serialized payload.
     *
     * @param WP_Post|WP_Comment|null $post Post, comment, or null.
     *
     * @return string Serialized snapshot.
     */
    private static function postFilteredData(WP_Post|WP_Comment|null $post)
    {   
        if ($post === null) { 
            return null;
        }

        $clone = clone $post;

        // Keep only the fields we need. The allow-list is per type: a WP_Comment has
        // none of WP_Post's properties, so applying the post list to a comment stripped
        // every field and produced O:10:"WP_Comment":0:{} - an object with no comment_ID,
        // no author and no content. That is F-10, and it is why comment logging produced
        // empty entries. Verified by round-tripping a real WP_Comment through this method.
        if ($post instanceof WP_Comment) {
            $allowed = [
                'comment_ID',
                'comment_post_ID',
                'comment_author',
                'comment_author_email',
                'comment_author_url',
                'comment_date',
                'comment_content',
                'comment_approved',
                'comment_type',
                'comment_parent',
                'user_id',
            ];
        } else {
            $allowed = [
                'ID',
                'post_title',
                'post_status',
                'post_type',
                'post_author',
                'post_date',
                'post_modified',
                'guid',
            ];
        }

        foreach (get_object_vars($clone) as $prop => $_) {
            if (!in_array($prop, $allowed, true)) {
                unset($clone->{$prop});
            }
        }

        $clone = serialize($clone);

        return $clone;
    }

}
