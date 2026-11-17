<?php
/**
 * Contains the Plugin_Settings class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logify_WP\Plugin_Settings
 *
 * This class encapsulates properties and methods relating to plugin settings.
 */
class Plugin_Settings {

	// =============================================================================================
	// Default values for settings.

	/**
	 * The default value for the 'delete on uninstall' setting.
	 *
	 * @var bool
	 */
	private const DEFAULT_DELETE_ON_UNINSTALL = false;

	/**
	 * The default value for the 'enable notes' setting.
	 *
	 * @var bool
	 */
	private const DEFAULT_ENABLE_NOTES = true;

	/**
	 * The default value for the 'roles with access' setting.
	 *
	 * @var array
	 */
	private const DEFAULT_ROLES_WITH_ACCESS = array( 'administrator' );

	/**
	 * The default value for the 'users with access' setting.
	 *
	 * @var array
	 */
	private const DEFAULT_USERS_WITH_ACCESS = array();

	/**
	 * The default value for the 'show in admin bar' setting.
	 *
	 * @var bool
	 */
	private const DEFAULT_SHOW_IN_ADMIN_BAR = true;

	/**
	 * The default value for the 'keep period quantity' setting.
	 *
	 * @var int
	 */
	private const DEFAULT_KEEP_PERIOD_QUANTITY = 12;

	/**
	 * The default value for the 'keep period units' setting.
	 *
	 * @var string
	 */
	private const DEFAULT_KEEP_PERIOD_UNITS = 'month';
	private const DEFAULT_COMMENT_TRACKING_STATE = false;

	/**
	 * The default value for the 'IP geolocation' setting.
	 *
	 * Default false until a licensed HTTPS geolocation provider is configured.
	 *
	 * @var bool
	 */
	private const DEFAULT_IP_GEOLOCATION = false;

	/**
	 * The default value for the 'trust proxy headers' setting.
	 *
	 * Off by default: proxy headers are client-supplied and forgeable, so they are only used
	 * when the site confirms it sits behind a proxy or CDN.
	 *
	 * @var bool
	 */
	private const DEFAULT_TRUST_PROXY_HEADERS = false;

	/**
	 * Get the names of all current roles.
	 * These will be the default value for the 'roles to track' setting.
	 *
	 * @return array
	 */
	private static function get_roles() {
		return array_keys( wp_roles()->roles );
	}

	// ---------------------------------------------------------------------------------------------
	// Functions associated with action hooks.

	/**
	 * Initializes the class by adding WordPress actions.
	 */
	public static function init() {
		// Register the settings and verify nonce for form submissions.
		add_action( 'admin_init', array( __CLASS__, 'verify_settings_nonce' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// User search for the "Additional users with access" field.
		add_action( 'wp_ajax_logify_wp_search_users', array( __CLASS__, 'ajax_search_users' ) );

	}

	/**
	 * Verifies the settings form nonce before processing settings.
	 *
	 * @return void
	 */
	public static function verify_settings_nonce() {
		// Only verify on settings form submission.
		if ( ! isset( $_POST['option_page'] ) || 'logify_wp_settings_group' !== sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) ) {
			return;
		}

		// Check the explicit nonce field for additional CSRF protection.
		if ( isset( $_POST['logify_wp_settings_nonce_field'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['logify_wp_settings_nonce_field'] ) );
			if ( ! wp_verify_nonce( $nonce, 'logify_wp_settings_nonce' ) ) {
				wp_die( esc_html__( 'Security check failed. Please try again.', 'logify-wp' ) );
			}
		}
	}

	/**
	 * Registers the settings for the Logify WP plugin.
	 */
	public static function register_settings() {
		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_trust_proxy_headers',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_TRUST_PROXY_HEADERS,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_enable_notes',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enable_notes' ),
				'default'           => self::DEFAULT_ENABLE_NOTES,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_delete_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_DELETE_ON_UNINSTALL,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_roles_to_track',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => self::get_roles(),
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_roles_with_access',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => self::DEFAULT_ROLES_WITH_ACCESS,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_users_with_access',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_users' ),
				'default'           => self::DEFAULT_USERS_WITH_ACCESS,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_show_in_admin_bar',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_SHOW_IN_ADMIN_BAR,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_keep_period_quantity',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_keep_period_quantity' ),
				'default'           => self::DEFAULT_KEEP_PERIOD_QUANTITY,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_keep_period_units',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_keep_period_units' ),
				'default'           => self::DEFAULT_KEEP_PERIOD_UNITS,
			)
		);

		// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingDynamic
		register_setting(
			'logify_wp_settings_group',
			'logify_wp_comment_tracking',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_COMMENT_TRACKING_STATE,
			)
		);

	}

	/**
	 * Deletes all settings options for the Logify WP plugin.
	 */
	public static function delete_all() {
		// Delete all options that start with logify_wp.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				'logify_wp%'
			)
		);
		foreach ( $options as $option ) {
			delete_option( $option->option_name );
		}
	}

	/**
	 * Displays the settings page for the Logify WP plugin.
	 */
	public static function display_settings_page() {
		if ( ! Access_Control::can_access_settings_page() ) {
			// Disallow access.
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'logify-wp' ), 403 );
		}

		// Include the settings-page.php template file to render the settings page.
		include LOGIFY_WP_PLUGIN_DIR . 'templates/settings-page.php';
	}

	// ---------------------------------------------------------------------------------------------
	// Sanitize functions.

	/**
	 * Sanitizes the given array of roles by filtering out any invalid roles.
	 *
	 * @param ?array $roles The array of roles to be sanitized.
	 * @return array The sanitized array of roles.
	 */
	/**
	 * Search users for the settings page access picker.
	 *
	 * The settings page used to render a checkbox for every user account on the site. On a
	 * WooCommerce or LearnDash site with thousands of customers or students that meant a very
	 * slow query, a very large page, and a browser asked to lay out thousands of form
	 * controls. This endpoint returns a small page of matches instead, so the number of users
	 * on the site no longer affects the settings page at all.
	 *
	 * @return void
	 */
	public static function ajax_search_users() {
		check_ajax_referer( 'logify_wp_search_users', 'nonce' );

		if ( ! Access_Control::can_access_settings_page() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'logify-wp' ) ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'users' => array() ) );
		}

		$query = new \WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'fields'         => array( 'ID', 'display_name', 'user_login' ),
				'number'         => 20,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				// No pagination total needed, and skipping it avoids a second COUNT query
				// across a large user table.
				'count_total'    => false,
			)
		);

		$users = array();

		foreach ( (array) $query->get_results() as $user ) {
			$user_id = (int) $user->ID;
			$name    = $user->display_name !== '' ? $user->display_name : $user->user_login;

			$users[] = array(
				'id'      => $user_id,
				'name'    => $name,
				'login'   => $user->user_login,
				'viaRole' => Access_Control::user_has_access_via_role( $user_id ),
			);
		}

		wp_send_json_success( array( 'users' => $users ) );
	}

	public static function sanitize_roles( $roles ): array {
		// This is a settings callback, so it receives whatever was posted. It used to be typed
		// ?array, which meant a request posting a string for one of the role settings caused a
		// TypeError rather than being rejected.
		if ( empty( $roles ) || ! is_array( $roles ) ) {
			return array();
		}

		$roles       = array_map( 'sanitize_key', array_filter( $roles, 'is_scalar' ) );
		$valid_roles = self::get_roles();

		// array_values(): array_intersect() preserves the original keys, which left gaps in the
		// stored array.
		return array_values( array_intersect( $roles, $valid_roles ) );
	}

	/**
	 * Sanitize the log retention quantity.
	 *
	 * Clamped to the 1-12 range the settings page offers. absint() alone allowed 0 through,
	 * which would have meant a retention period of zero days: the cleanup job would then delete
	 * every log record on its next run.
	 *
	 * @param mixed $value The posted value.
	 * @return int A quantity between 1 and 12.
	 */
	public static function sanitize_keep_period_quantity( $value ): int {
		$value = absint( $value );

		return max( 1, min( 12, $value ) );
	}

	/**
	 * Sanitize the log retention unit.
	 *
	 * An allow-list rather than sanitize_text_field(). Cron::cleanup_old_records() matches on
	 * this value and previously threw an UnexpectedValueException on anything unrecognised,
	 * which would have stopped log cleanup running at all.
	 *
	 * @param mixed $value The posted value.
	 * @return string One of 'day', 'week' or 'month'.
	 */
	public static function sanitize_keep_period_units( $value ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return in_array( $value, array( 'day', 'week', 'month', 'year' ), true )
			? $value
			: self::DEFAULT_KEEP_PERIOD_UNITS;
	}

	/**
	 * Sanitizes the given array of user IDs by filtering out any invalid user IDs.
	 *
	 * @param ?array $user_ids The array of user IDs to be sanitized.
	 * @return array The sanitized array of user IDs.
	 */
	public static function sanitize_users( $user_ids ) {
		// Handle null, empty, or a non-array payload.
		if ( empty( $user_ids ) || ! is_array( $user_ids ) ) {
			return array();
		}

		// Make sure the array of user IDs only contains integers.
		$user_ids = array_map( 'intval', $user_ids );

		$user_ids = array_values( array_unique( array_filter( $user_ids, static fn( $id ) => $id > 0 ) ) );
		// Validate only the supplied IDs instead of loading all 50,000+ site users.
		return array_values( array_filter( $user_ids, static fn( $id ) => get_userdata( $id ) instanceof \WP_User ) );
	}

	/**
	 * Sanitizes the enable notes setting and clears Notes column preferences when disabled.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return bool The sanitized boolean value.
	 */
	public static function sanitize_enable_notes( $value ): bool {
		$notes_enabled = rest_sanitize_boolean( $value );

		// Get the current setting
		$current_enabled = self::get_enable_notes();

		// Notes is a global toggle. Never write thousands of usermeta rows inside
		// a settings sanitization callback. Existing column choices remain intact
		// and are ignored while Notes is disabled.

		return $notes_enabled;
	}

	// ---------------------------------------------------------------------------------------------
	// Functions to get the current values of settings.

	/**
	 * Retrieves the value of the 'delete on uninstall' setting.
	 *
	 * @return bool The delete on uninstall setting.
	 */
	public static function get_delete_on_uninstall(): bool {
		return get_option( 'logify_wp_delete_on_uninstall', self::DEFAULT_DELETE_ON_UNINSTALL );
	}

	/**
	 * Retrieves the value of the 'enable notes' setting.
	 *
	 * @return bool Enabled or disable notes feature.
	 */
	public static function get_enable_notes(): bool {
		return get_option( 'logify_wp_enable_notes', self::DEFAULT_ENABLE_NOTES );
	}

	/**
	 * Whether to look up the geographic location of logged IP addresses.
	 *
	 * Automatic location lookups were retired. Kept only as a compatibility method
	 * for existing integrations; it always returns false.
	 *
	 * @return bool
	 */
	public static function get_ip_geolocation(): bool {
		return false;
	}

	/**
	 * Whether to read the visitor's IP address from proxy or CDN request headers.
	 *
	 * Only switch this on if the site sits behind a proxy or CDN (Cloudflare, a load balancer,
	 * a reverse proxy) that overwrites these headers. On a site with no proxy, the headers are
	 * whatever the client chose to send, so trusting them lets a visitor forge the source IP
	 * recorded in the log.
	 *
	 * @return bool
	 */
	public static function get_trust_proxy_headers(): bool {
		return (bool) get_option( 'logify_wp_trust_proxy_headers', self::DEFAULT_TRUST_PROXY_HEADERS );
	}

	/**
	 * Retrieves the value of the 'roles to track' setting.
	 *
	 * @return array The roles to track.
	 */
	public static function get_roles_to_track(): array {
		return get_option( 'logify_wp_roles_to_track', self::get_roles() );
	}

	/**
	 * Retrieves the value of the 'roles with access' setting.
	 *
	 * @return array The roles with access to the log page.
	 */
	public static function get_roles_with_access(): array {
		return get_option( 'logify_wp_roles_with_access', self::DEFAULT_ROLES_WITH_ACCESS );
	}

	/**
	 * Retrieves the value of the 'users with access' setting.
	 *
	 * @return array The users with access to the log page.
	 */
	public static function get_users_with_access(): array {
		// Cast to integers: Access_Control compares these strictly against a user ID, so a
		// legacy value stored as a string would silently never match.
		$user_ids = get_option( 'logify_wp_users_with_access', self::DEFAULT_USERS_WITH_ACCESS );

		return is_array( $user_ids ) ? array_map( 'intval', $user_ids ) : array();
	}

	/**
	 * Retrieves the value of the 'show in admin bar' setting.
	 *
	 * @return array If the Logify WP submenu should be shown in the admin bar.
	 */
	public static function get_show_in_admin_bar(): bool {
		return get_option( 'logify_wp_show_in_admin_bar', self::DEFAULT_SHOW_IN_ADMIN_BAR );
	}

	/**
	 * Retrieves the value of the 'keep period quantity' setting.
	 *
	 * @return int The keep period quantity setting.
	 */
	public static function get_keep_period_quantity(): int {
		return get_option( 'logify_wp_keep_period_quantity', self::DEFAULT_KEEP_PERIOD_QUANTITY );
	}

	/**
	 * Retrieves the keep period units setting for the Logify WP plugin.
	 *
	 * @return string The keep period units setting.
	 */
	public static function get_keep_period_units(): string {
		return get_option( 'logify_wp_keep_period_units', self::DEFAULT_KEEP_PERIOD_UNITS );
	}
	public static function get_comment_tracking_state(): bool {
		return get_option( 'logify_wp_comment_tracking', self::DEFAULT_COMMENT_TRACKING_STATE );
	}
}
