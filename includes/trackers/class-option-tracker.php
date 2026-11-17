<?php
/**
 * Contains the Option_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

/**
 * Class Logify_WP\Option_Tracker
 *
 * Provides tracking of events related to settings.
 *
 * The reason this is called Option_Tracker is because originally we were tracking changes to all
 * options, not just settings. Settings are options that can be changed by a user via a form, which
 * are the only options we want to track changes to.
 *
 * Following Simple History's approach: we only hook into updated_option when we're on an actual
 * settings page. This prevents tracking of background option updates (cron, API, plugin internals).
 */
class Option_Tracker {

	/**
	 * Persistent storage prefix for queued option batches.
	 */
	private const BATCH_OPTION_PREFIX = 'logify_wp_option_batch_';

	/**
	 * Whether the option tracking hooks have been registered for this request.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Set up hooks for the events we want to log.
	 */
	public static function init() {
		// Only hook into option updates when we're on a settings page.
		// This follows Simple History's approach to avoid tracking background/cron option updates.
		add_action( 'load-options.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-permalink.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-general.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-writing.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-reading.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-discussion.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-media.php', array( __CLASS__, 'on_load_settings_page' ) );
		add_action( 'load-options-privacy.php', array( __CLASS__, 'on_load_settings_page' ) );

		// Also track when plugins register their own settings pages.
		// These typically post to options.php with an option_page parameter.
		add_action( 'admin_init', array( __CLASS__, 'maybe_register_on_plugin_settings' ), 99 );

		// Accept batch identifier plus client IP from the async callback.
		add_action( 'middle_update_option_option', array( __CLASS__, 'on_update_option' ), 10, 6 );

		add_action( 'shutdown', [ __NAMESPACE__ . '\Async_Tracker', 'async_shutdown_option' ], 10, 0 );
		add_action( 'middle_shutdown_option', array( __CLASS__, 'on_shutdown' ), 10, 1 );
	}

	/**
	 * Called when a WordPress settings page is loaded.
	 * Registers the option update tracking hooks.
	 */
	public static function on_load_settings_page(): void {
		self::register_option_hooks();
	}

	/**
	 * Check if we're on a plugin settings page that posts to options.php.
	 * This catches custom plugin settings pages.
	 */
	public static function maybe_register_on_plugin_settings(): void {
		// Already registered via load-options.php hook.
		if ( self::$hooks_registered ) {
			return;
		}

		// Check if this is a POST to options.php (settings form submission).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['option_page'] ) && ! empty( $_POST['option_page'] ) ) {
			self::register_option_hooks();
		}
	}

	/**
	 * Register the actual option tracking hooks.
	 */
	private static function register_option_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		// Track settings updates - only when we know we're on a settings page.
		add_action( 'update_option', [ __NAMESPACE__ . '\Async_Tracker', 'async_update_option_option' ], 10, 3 );
	}

	/**
	 * Fires immediately before an option value is updated.
	 *
	 * @param string $option_name Name of the option to update.
	 * @param mixed  $old_value   The old option value.
	 * @param mixed  $new_value   The new option value.
	 */
	public static function on_update_option( string $batch_id, string $option_name, mixed $old_value, mixed $new_value, int $acting_user_id, ?string $client_ip = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( function_exists( 'error_log' ) ) {
				//error_log( '[Logify WP] on_update_option batch ' . $batch_id . ' for: ' . $option_name . ' | old: ' . var_export( $old_value, true ) . ' | new: ' . var_export( $new_value, true ) . ' | user: ' . $acting_user_id );
			}
		}

		self::record_option_change( $batch_id, $option_name, $old_value, $new_value, $acting_user_id, $client_ip );
	}

	/**
	 * Capture and persist a change immediately without waiting for the async callback.
	 */
	public static function capture_option_change_now( string $batch_id, string $option_name, mixed $old_value, mixed $new_value, int $acting_user_id, ?string $client_ip = null ): void {
		self::record_option_change( $batch_id, $option_name, $old_value, $new_value, $acting_user_id, $client_ip );
	}

	/**
	 * Shared logic for validating and persisting option changes.
	 */
	private static function record_option_change( string $batch_id, string $option_name, mixed $old_value, mixed $new_value, int $acting_user_id, ?string $client_ip ): void {
		global $wp_registered_settings;

		if ( ! Option_Utility::should_track_option( $option_name, $old_value, $new_value ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				error_log( '[Logify WP] Option not a setting, skipping: ' . $option_name );
			}
			return;
		}

		if ( isset( $wp_registered_settings[ $option_name ] ) && $wp_registered_settings[ $option_name ]['type'] === 'boolean' ) {
			$val     = rest_sanitize_boolean( $old_value );
			$new_val = rest_sanitize_boolean( $new_value );
		} else {
			$val     = Types::process_database_value( $option_name, $old_value );
			$new_val = Types::process_database_value( $option_name, $new_value );
		}

		if ( Types::are_equal( $val, $new_val ) ) {
			return;
		}

		if ( str_ends_with( $option_name, '_category' ) ) {
			$val     = new Object_Reference( 'term', $val );
			$new_val = new Object_Reference( 'term', $new_val );
		} elseif ( $option_name === 'wp_page_for_privacy_policy' ) {
			$val     = new Object_Reference( 'post', $val );
			$new_val = new Object_Reference( 'post', $new_val );
		}

		self::store_option_change( $batch_id, $option_name, $val, $new_val, $acting_user_id, $client_ip );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Queued option change for batch ' . $batch_id . ': ' . $option_name );
		}
	}

	/**
	 * Fires on shutdown, after PHP execution.
	 */
	public static function on_shutdown( string $batch_id ) {
		$batch = self::get_batch_changes( $batch_id );
		if ( empty( $batch['changes'] ) ) {
			self::delete_batch( $batch_id );
			return;
		}

		$object_ref = new Object_Reference( 'option', null, null );
		$event      = Event::create( 'Settings Updated', $object_ref, null, null, $batch['acting_user_id'] ?? 0, false, $batch['client_ip'] ?? null );
		if ( ! $event ) {
			self::delete_batch( $batch_id );
			return;
		}

		global $wpdb;
		foreach ( $batch['changes'] as $option => $values ) {
			$event->set_prop( $option, $wpdb->options, $values['old'], $values['new'] );
		}

		self::set_event_object_name( $event );
		$event->save();
		self::delete_batch( $batch_id );
	}

	/**
	 * Persist a single option change until the batch is flushed.
	 */
	private static function store_option_change( string $batch_id, string $option_name, mixed $val, mixed $new_val, int $acting_user_id, ?string $client_ip ): void {
		$key   = self::get_batch_storage_key( $batch_id );
		$batch = self::get_batch_changes( $batch_id );

		if ( empty( $batch ) ) {
			$batch = array(
				'acting_user_id' => $acting_user_id,
				'client_ip'      => $client_ip,
				'changes'        => array(),
			);
		} else {
			if ( empty( $batch['acting_user_id'] ) ) {
				$batch['acting_user_id'] = $acting_user_id;
			}
			if ( empty( $batch['client_ip'] ) ) {
				$batch['client_ip'] = $client_ip;
			}
		}

		$batch['changes'][ $option_name ] = array(
			'old' => $val,
			'new' => $new_val,
		);

		update_option( $key, $batch, false );
	}

	/**
	 * Retrieve stored changes for a batch.
	 */
	private static function get_batch_changes( string $batch_id ): array {
		$key  = self::get_batch_storage_key( $batch_id );
		$data = get_option( $key, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Delete the stored batch payload.
	 */
	private static function delete_batch( string $batch_id ): void {
		delete_option( self::get_batch_storage_key( $batch_id ) );
	}

	/**
	 * Build the option summary for the event title.
	 */
	private static function set_event_object_name( Event $event ): void {
		if ( empty( $event->properties ) ) {
			return;
		}

		$option_names = '';
		foreach ( $event->properties as $option => $prop ) {
			$option_name   = Option_Utility::get_name( $option );
			$option_names2 = ( $option_names ? ( $option_names . ', ' ) : '' ) . $option_name;
			if ( strlen( $option_names2 ) <= Logger::MAX_OBJECT_NAME_LENGTH - 6 ) {
				$option_names = $option_names2;
			} else {
				$option_names .= ', etc.';
				break;
			}
		}

		$event->object_name = $option_names;
	}

	/**
	 * Generate a deterministic storage key for an option batch.
	 */
	private static function get_batch_storage_key( string $batch_id ): string {
		return self::BATCH_OPTION_PREFIX . hash( 'sha1', $batch_id );
	}
}
