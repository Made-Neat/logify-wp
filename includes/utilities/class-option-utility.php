<?php
/**
 * Contains the Option_Utility class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

/**
 * Class Logify_WP\Option_Utility
 *
 * Provides methods for working with options.
 */
class Option_Utility extends Object_Utility {

	/**
	 * Noisy options that should never be tracked.
	 *
	 * @var string[]
	 */
	private const NOISY_OPTIONS = array(
		'cron',
		'rewrite_rules',
		'wp_user_roles',
		'__uagb_asset_version',
		'bsf_last_update_check',
		// Gravity Forms transient-like options (huge JSON blobs with version/license info).
		'gform_version_info',
		'gform_update_info',
		'gform_pending_installation',
	);

	/**
	 * Prefixes for options that are either transient or internal noise.
	 *
	 * @var string[]
	 */
	private const NOISY_PREFIXES = array(
		'_transient_',
		'_site_transient_',
		// Gravity Forms session/cache prefixes.
		'gf_digest_',
		'gform_sticky_',
		'gf_api_',
	);

	/**
	 * Prefixes for options already handled elsewhere (widgets, theme mods, etc.).
	 *
	 * @var string[]
	 */
	private const NON_SETTING_PREFIXES = array(
		'widget_',
		'theme_mods_',
		'sidebars_widgets',
	);

	/**
	 * Options that must always be tracked, even if heuristics would normally skip them.
	 *
	 * @var string[]
	 */
	private const FORCE_TRACK_OPTIONS = array(
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'timezone_string',
		'date_format',
		'time_format',
		'start_of_week',
		'WPLANG',
		'default_category',
		'default_post_format',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_public',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'require_name_email',
		'comment_registration',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'comments_notify',
		'moderation_notify',
		'comment_moderation',
		'spam_clearly_marked',
		'blacklist_keys',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'permalink_structure',
		'category_base',
		'tag_base',
		'wp_page_for_privacy_policy',
	);

	/**
	 * Common substrings that usually indicate a bundled plugin settings array.
	 *
	 * @var string[]
	 */
	private const PLUGIN_SETTING_HINTS = array(
		'settings',
		'options',
		'config',
		'woocommerce',
		'wc_',
		'edd',
		'gravityforms',
		'gf_',
		'wpforms',
		'ninja_forms',
		'give_',
		'learndash',
		'elementor',
		'wpseo',
		'yoast',
		'rank_math',
		'acf',
		'bricks',
		'brx_',
		'logify',
	);

	// =============================================================================================
	// Implementations of base class methods.

	/**
	 * Check if an option exists.
	 *
	 * @param int|string $option_name The name of the option.
	 * @return bool True if the option exists, false otherwise.
	 */
	public static function exists( int|string $option_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(option_name) FROM %i WHERE option_name = %d', $wpdb->options, $option_name )
		);
		return $count > 0;
	}

	/**
	 * Get a option value.
	 *
	 * @param int|string $option_name The name of the option.
	 * @return mixed The option value or null if not found.
	 */
	public static function load( int|string $option_name ): mixed {
		// Get the option value, defaulting to null.
		return get_option( $option_name, null );
	}

	/**
	 * Get a readable name for an option.
	 *
	 * @param int|string $option_name The name of the option.
	 * @return ?string A more readable name of the option.
	 */
	public static function get_name( int|string $option_name ): ?string {
		return Strings::key_to_label( $option_name );
	}

	/**
	 * Get the core properties of a option.
	 *
	 * @param int|string $option_name The option name.
	 * @return ?Property[] The core properties of the option, or null if not found.
	 */
	public static function get_core_properties( int|string $option_name ): ?array {
		global $wpdb;

		// Load the option.
		$option_value = self::load( $option_name );

		// Handle the case where the option no longer exists.
		if ( $option_value === null ) {
			return null;
		}

		// Build the array of properties.
		$props = array();

		// Name.
		Property::update_array( $props, 'name', $wpdb->options, $option_name );

		// Value.
		$option_value = Types::process_database_value( $option_name, $option_value );
		Property::update_array( $props, 'value', $wpdb->options, $option_value );

		return $props;
	}

	/**
	 * There's no way to get a link to an option, so we just return a span either way.
	 *
	 * If the option isn't present, then the "(deleted)" text will be appended to the name.
	 *
	 * @param int|string $option_name The name of the option.
	 * @param ?string    $old_name    The fallback display name of the option if it's been deleted.
	 * @return string The span HTML.
	 */
	public static function get_tag( int|string $option_name, ?string $old_name = null ): string {
		// Load the option.
		$option_value = self::load( $option_name );

		// If the option exists, get a span.
		if ( $option_value !== null ) {
			$name = self::get_name( $option_name );
			return "<span class='logify-wp-object'>" . esc_html( $name ) . "</span>";
		}

		// Make a backup name.
		if ( ! $old_name ) {
			$old_name = Strings::key_to_label( $option_name );
		}

		// The option no longer exists. Construct the 'deleted' span element.
		return "<span class='logify-wp-deleted-object'>" . esc_html( $old_name ) . " (deleted)</span>";
	}

	// =============================================================================================
	// Additional methods.

	/**
	 * Check if an option is a registered setting.
	 *
	 * @param string $option_name The name of the option.
	 * @return bool True if the option is a registered setting, false otherwise.
	 */
	public static function is_setting( string $option_name ): bool {
		global $allowed_options, $wp_registered_settings;

		// Check if the option is a WordPress allowed option.
		if ( is_array( $allowed_options ) ) {
			foreach ( $allowed_options as $options_group => $options ) {
				if ( in_array( $option_name, $options, true ) ) {
					return true;
				}
			}
		}

		// Check if the option is registered as a setting by a plugin.
		if ( isset( $wp_registered_settings[ $option_name ] ) ) {
			return true;
		}

		$wp_core_settings = self::FORCE_TRACK_OPTIONS;

		if ( in_array( $option_name, $wp_core_settings, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether an option update should be logged.
	 *
	 * This centralizes noisy-option filtering and adds heuristics so plugin settings that do not
	 * register with the Settings API can still be tracked.
	 *
	 * @param string $option_name Option key.
	 * @param mixed  $old_value   Previous value.
	 * @param mixed  $new_value   New value.
	 * @return bool True when the option should be logged.
	 */
	public static function should_track_option( string $option_name, mixed $old_value = null, mixed $new_value = null ): bool {
		$option_name = (string) $option_name;

		if ( in_array( $option_name, self::NOISY_OPTIONS, true ) ) {
			return false;
		}

		foreach ( self::NOISY_PREFIXES as $prefix ) {
			if ( str_starts_with( $option_name, $prefix ) ) {
				return false;
			}
		}

		// Respect the existing Settings API whitelist first.
		$track = in_array( $option_name, self::FORCE_TRACK_OPTIONS, true );

		if ( ! $track ) {
			$track = self::is_setting( $option_name );
		}

		if ( ! $track ) {
			$track = self::looks_like_plugin_setting( $option_name, $old_value, $new_value );
		}

		/**
		 * Filter to control whether an option update should be logged.
		 *
		 * @param bool   $track       Default decision.
		 * @param string $option_name Option key.
		 * @param mixed  $old_value   Previous value.
		 * @param mixed  $new_value   New value.
		 */
		return (bool) apply_filters( 'logify_wp_should_track_option', $track, $option_name, $old_value, $new_value );
	}

	/**
	 * Lightweight heuristic to detect plugin settings stored in custom option keys.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $old_value   Previous value.
	 * @param mixed  $new_value   New value.
	 * @return bool
	 */
	private static function looks_like_plugin_setting( string $option_name, mixed $old_value, mixed $new_value ): bool {
		$lower = strtolower( $option_name );

		foreach ( self::NON_SETTING_PREFIXES as $prefix ) {
			if ( str_starts_with( $lower, $prefix ) ) {
				return false;
			}
		}

		// Many plugins store their entire settings page in a serialized array.
		if ( is_array( $old_value ) || is_array( $new_value ) ) {
			return true;
		}

		foreach ( self::PLUGIN_SETTING_HINTS as $hint ) {
			if ( str_contains( $lower, $hint ) ) {
				return true;
			}
		}

		return false;
	}
}
