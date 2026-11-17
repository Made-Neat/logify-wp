<?php
/**
 * Contains the Plugin_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Upgrader;

/**
 * Class Logify_WP\Plugin_Tracker
 *
 * Provides tracking of events related to plugins.
 */
class Plugin_Tracker
{
	/**
	 * Whether the auto-update option tracking hook has been registered.
	 *
	 * @var bool
	 */
	private static bool $auto_update_hook_registered = false;

	/**
	 * Set up hooks for the events we want to log.
	 */
	public static function init()
	{
		// Plugin install and update.
		add_action('upgrader_process_complete', Async_Tracker::safe_callback( 'async_upgrader_process_complete_plugin', false ), 10, 2);
		add_action('middle_upgrader_process_complete_plugin', array(__CLASS__, 'on_upgrader_process_complete'), 10, 4);
		// add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_process_complete' ), 10, 2 );

		// Plugin activation and deactivation.
		add_action('activate_plugin', Async_Tracker::safe_callback( 'async_activate_plugin', false ), 10, 2);
		add_action('middle_activate_plugin', array(__CLASS__, 'on_activate_plugin'), 10, 4);

		add_action('deactivate_plugin', Async_Tracker::safe_callback( 'async_deactivate_plugin', false ), 10, 2);
		add_action('middle_deactivate_plugin', array(__CLASS__, 'on_deactivate_plugin'), 10, 4);

		// Plugin deletion and uninstall.
		add_action('delete_plugin', Async_Tracker::safe_callback( 'async_delete_plugin', false ), 10, 1);
		add_action('middle_delete_plugin', array(__CLASS__, 'on_delete_plugin'), 10, 3);

		add_action('pre_uninstall_plugin', Async_Tracker::safe_callback( 'async_pre_uninstall_plugin', false ), 10, 2);
		add_action('middle_pre_uninstall_plugin', array(__CLASS__, 'on_pre_uninstall_plugin'), 10, 4);

		// Enabling and disabling auto-updates - only on plugins page.
		add_action('load-plugins.php', array(__CLASS__, 'register_auto_update_hook'));
		add_action('wp_ajax_toggle-auto-updates', array(__CLASS__, 'register_auto_update_hook'), 1);

		add_action('middle_update_option_plugin', array(__CLASS__, 'on_update_option'), 10, 5);
	}

	/**
	 * Register the auto-update option tracking hook when on plugins page.
	 */
	public static function register_auto_update_hook(): void
	{
		if (self::$auto_update_hook_registered) {
			return;
		}

		self::$auto_update_hook_registered = true;
		add_action('update_option', Async_Tracker::safe_callback( 'async_update_option_plugin', false ), 10, 3);
	}

	/**
	 * Fires when the upgrader process is complete.
	 *
	 * See also {@see 'upgrader_package_options'}.
	 *
	 * @param WP_Upgrader $upgrader   WP_Upgrader instance. In other contexts this might be a
	 *                                Theme_Upgrader, Plugin_Upgrader, Core_Upgrade, or Language_Pack_Upgrader instance.
	 * @param array       $hook_extra {
	 *     Array of bulk item update data.
	 *
	 *     @type string $action       Type of action. Default 'update'.
	 *     @type string $type         Type of update process. Accepts 'plugin', 'theme', 'translation', or 'core'.
	 *     @type bool   $bulk         Whether the update process is a bulk update. Default true.
	 *     @type array  $plugins      Array of the basename paths of the plugins' main files.
	 *     @type array  $themes       The theme slugs.
	 *     @type array  $translations {
	 *         Array of translations update data.
	 *
	 *         @type string $language The locale the translation is for.
	 *         @type string $type     Type of translation. Accepts 'plugin', 'theme', or 'core'.
	 *         @type string $slug     Text domain the translation is for. The slug of a theme/plugin or
	 *                                'default' for core translations.
	 *         @type string $version  The version of a theme, plugin, or core.
	 *     }
	 * }
	 */	 
	 
	public static function on_upgrader_process_complete( $upgrader, array $hook_extra, $acting_user_id, ?string $client_ip = null ) {
		// Cast to object for consistent property access.
		$upgrader = (object) $upgrader;

		// Determine whether this is a plugin install or update.
		$installing = isset( $hook_extra['action'] ) && $hook_extra['action'] === 'install';
		$updating   = isset( $hook_extra['action'] ) && $hook_extra['action'] === 'update';

		if ( ! $installing && ! $updating ) {
			return;
		}

		/**
		 * In a bulk update (e.g. Dashboard → Updates), WordPress fires
		 * `upgrader_process_complete` only once and populates `$hook_extra['plugins']`
		 * with every plugin file updated. In this situation, `$upgrader->new_plugin_data`
		 * contains information only about the last plugin updated, so we must loop over
		 * the entire list of plugins to log each one.
		 */
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $plugin_file ) {
				// Load the plugin by its file path (e.g. 'plugin-folder/plugin-main-file.php').
				$plugin = Plugin_Utility::load_by_file( $plugin_file );
				if ( ! $plugin ) {
					continue; // Skip if the plugin cannot be resolved.
				}

				$slug        = $plugin['slug'];
				$new_version = $plugin['Version'] ?? null;
				$old_version = null;
				$verb        = $installing ? 'Installed' : 'Upgraded';

				if ( $installing ) {
					// If this is an install triggered from the updates page, see if an old version was stored.
					$versions    = get_option( 'logify_wp_plugin_versions', [] );
					$old_version = $versions[ $slug ] ?? null;

					// Determine whether this is actually an upgrade, downgrade or re‑install.
					$clear_destination = $upgrader->result['clear_destination'] ?? null;
					if ( $clear_destination === 'downgrade-plugin' ) {
						$verb = 'Downgraded';
					} elseif ( $clear_destination === 'update-plugin' ) {
						$verb = ( $old_version === $new_version ) ? 'Re-installed' : 'Upgraded';
					}
				} elseif ( isset( $upgrader->skin->plugin_info['Version'] ) ) {
					// For standard updates from the install page, the skin contains the old version.
					$old_version = $upgrader->skin->plugin_info['Version'];
				}

				// Create and store the event for this plugin.
				$event = Event::create( "Plugin {$verb}", $plugin, null, null, $acting_user_id, false, $client_ip );
				if ( $event ) {
					if ( $old_version && $new_version && $old_version !== $new_version ) {
						$event->set_prop( 'version', null, $old_version, $new_version );
					}
					$event->save();
				}
			}
			return;
		}

		/*
		 * Fall back to single-plugin behaviour if `$hook_extra['plugins']` is empty.
		 * This path is hit when updating a single plugin from its own update screen.
		 */

		// Ensure we have data about the updated plugin.
		if ( empty( $upgrader->new_plugin_data['Name'] ) ) {
			return;
		}

		// Load the plugin by name.
		$plugin_name = $upgrader->new_plugin_data['Name'];
		$plugin      = Plugin_Utility::load_by_name( $plugin_name );
		if ( ! $plugin ) {
			return;
		}

		$slug = $plugin['slug'];

		// If the result is null, we are on the confirmation screen (no actual update yet).
		if ( $upgrader->result === null ) {
			$old_version = $plugin['Version'] ?? null;
			if ( $old_version ) {
				$versions         = get_option( 'logify_wp_plugin_versions', [] );
				$versions[ $slug ] = $old_version;
				update_option( 'logify_wp_plugin_versions', $versions );
			}
			return;
		}

		$old_version = null;
		$new_version = $upgrader->new_plugin_data['Version'] ?? null;
		$verb        = 'Upgraded';

		if ( $installing ) {
			$verb         = 'Installed';
			$versions     = get_option( 'logify_wp_plugin_versions', [] );
			$old_version  = $versions[ $slug ] ?? null;
			$clear_destination = $upgrader->result['clear_destination'] ?? null;
			if ( $clear_destination === 'downgrade-plugin' ) {
				$verb = 'Downgraded';
			} elseif ( $clear_destination === 'update-plugin' ) {
				$verb = ( $old_version === $new_version ) ? 'Re-installed' : 'Upgraded';
			}
		} else {
			// Retrieve the old version from the skin when updating from the install page.
			$old_version = $upgrader->skin->plugin_info['Version'] ?? null;
		}

		$event = Event::create( "Plugin {$verb}", $plugin, null, null, $acting_user_id, false, $client_ip );
		if ( ! $event ) {
			return;
		}

		if ( $old_version && $new_version && $old_version !== $new_version ) {
			$event->set_prop( 'version', null, $old_version, $new_version );
		}
		$event->save();
	}
 
	/**
	 * Fires before a plugin is activated.
	 *
	 * If a plugin is silently activated (such as during an update),
	 * this hook does not fire.
	 *
	 * @param string $plugin_file  Path to the plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether to enable the plugin for all sites in the network
	 *                             or just the current site. Multisite only. Default false.
	 */
	public static function on_activate_plugin(string $plugin_file, bool $network_wide, ?int $acting_user_id = null, ?string $client_ip = null)
	{
		// Load the plugin.
		$plugin = Plugin_Utility::load_by_file($plugin_file);

		// If this is a multisite, record if the plugin activation was network-wide.
		$metas = array();
		if (is_multisite()) {
			Eventmeta::update_array($metas, 'network_wide', $network_wide);
		}

		Logger::log_event('Plugin Activated', $plugin, $metas, null, $acting_user_id, false, $client_ip);
	}

	/**
	 * Fires before a plugin is deactivated.
	 *
	 * If a plugin is silently deactivated (such as during an update),
	 * this hook does not fire.
	 *
	 * @param string $plugin_file          Path to the plugin file relative to the plugins directory.
	 * @param bool   $network_deactivating Whether the plugin is deactivated for all sites in the network
	 *                                     or just the current site. Multisite only. Default false.
	 */
	public static function on_deactivate_plugin(string $plugin_file, bool $network_deactivating, ?int $acting_user_id = null, ?string $client_ip = null)
	{
		// Load the plugin.
		$plugin = Plugin_Utility::load_by_file($plugin_file);

		// If this is a multisite, record if the plugin deactivation was network-wide.
		$metas = array();
		if (is_multisite()) {
			Eventmeta::update_array($metas, 'network_wide', $network_deactivating);
		}

		Logger::log_event('Plugin Deactivated', $plugin, $metas, null, $acting_user_id, false, $client_ip);
	}

	/**
	 * Fires immediately before a plugin deletion attempt.
	 *
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 */
	public static function on_delete_plugin($plugin, ?int $acting_user_id = null, ?string $client_ip = null)
	{
		Logger::log_event('Plugin Deleted', $plugin, null, null, $acting_user_id, false, $client_ip);
	}

	/**
	 * Fires in uninstall_plugin() immediately before the plugin is uninstalled.
	 *
	 * @param string $plugin_file           Path to the plugin file relative to the plugins directory.
	 * @param array  $uninstallable_plugins Uninstallable plugins.
	 */
	public static function on_pre_uninstall_plugin(string $plugin, array $uninstallable_plugins, ?int $acting_user_id = null, ?string $client_ip = null)
	{

		// Log the event.
		Logger::log_event('Plugin Uninstalled', $plugin, null, null, $acting_user_id, false, $client_ip);
	}

	/**
	 * Fires after a plugin has been enabled or disabled for auto-updates.
	 *
	 * @param string $option    Name of the option to update.
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 */
	public static function on_update_option(string $option, mixed $old_value, mixed $value, ?int $acting_user_id = null, ?string $client_ip = null)
	{
		// Check if the changed option is the auto_update_plugins option.
		if ($option !== 'auto_update_plugins') {
			return;
		}

		// Log an event for each plugin for which auto-update has been enabled.
		$enabled = array_diff($value, $old_value);
		foreach ($enabled as $plugin_file) {
			// Load the plugin.
			$plugin = Plugin_Utility::load_by_file($plugin_file);

			// If the plugin wasn't found, don't log the event.
			if (!$plugin) {
				continue;
			}

			Logger::log_event('Plugin Auto-Update Enabled', $plugin, null, null, $acting_user_id, false, $client_ip);
		}

		// Log an event for each plugin for which auto-update has been enabled.
		$disabled = array_diff($old_value, $value);
		foreach ($disabled as $plugin_file) {
			// Load the plugin.
			$plugin = Plugin_Utility::load_by_file($plugin_file);

			// If the plugin wasn't found, don't log the event.
			if (!$plugin) {
				continue;
			}

			Logger::log_event('Plugin Auto-Update Disabled', $plugin, null, null, $acting_user_id, false, $client_ip);
		}
	}
}
