<?php
/**
 * Contains the Main class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ReflectionClass;

/**
 * Class Logify_WP\Main
 *
 * Contains functions to perform actions on the plugin itself.
 */
class Main {

	const DB_VERSION = '1.5.0'; // Event date index added through a deferred, online migration.

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		// Skip initialization if the plugin is being deleted. This duplicated the request
		// inspection from the main plugin file, and hardcoded the plugin path rather than using
		// plugin_basename(), so it missed installs in a differently named directory.
		if ( function_exists( 'Logify_WP\\logify_wp_is_being_deleted' ) && logify_wp_is_being_deleted() ) {
			return;
		}

		// Note: PHP error capture was removed in 1.5.0. It read wp-content/debug.log and wrote
		// one database row per error occurrence, which is unbounded on a site that is actually
		// erroring, and it called ini_set() to redirect the site's error log and suppress
		// on-screen errors. None of that belongs in an activity log. See the changelog.

		add_action('upgrader_process_complete', [__CLASS__, 'maybe_upgrade_db'], 10, 2);
		add_action( 'logify_wp_add_event_date_index', [ __CLASS__, 'install_event_date_index' ] );

		// Multisite: create this plugin's tables when a new sub-site is added to the network.
		// Without this, a new sub-site had no tables until somebody happened to load an admin
		// page on it, so any activity logged before that was lost.
		add_action( 'wp_initialize_site', [ __CLASS__, 'on_new_multisite_site' ], 99, 1 );
    
		// Only check DB upgrade when loading admin area (to avoid unnecessary checks on frontend).
		if (is_admin()) {
			add_action('admin_init', [__CLASS__, 'maybe_upgrade_db']);
		}

		// Get all declared classes.
		$classes = get_declared_classes();

		// Iterate over each class.
		foreach ( $classes as $class ) {

			// Check if the class is in the Logify_WP namespace. Ignore the Main class (this class).
			if ( $class !== 'Logify_WP\\Main' && str_starts_with( $class, 'Logify_WP\\' ) ) {

				// Use reflection to check for the init method.
				$reflection = new ReflectionClass( $class );

				if ( $reflection->hasMethod( 'init' ) ) {
					$method = $reflection->getMethod( 'init' );

					// Check if the init method is static and not abstract.
					if ( $method->isStatic() && ! $method->isAbstract() ) {
						// Call the init method.
						$method->invoke( null );
					}
				}
			}
		}
	}

	/**
	 * Run on activation.
	 */
	public static function activate() {
		// Create the database tables used by the plugin.
		Database::create_all_tables();
		update_option('logify_wp_db_version', self::DB_VERSION);
		update_option( 'logify_wp_php_error_cleanup_complete', 1, false );
		update_option( 'logify_wp_legacy_error_actions_cleaned', 1, false );
	}

    /**
     * Run on plugin update to check if a database upgrade is needed.
     */
    public static function maybe_upgrade_db($upgrader_object = null, $options = null) {
        $installed_version = get_option( 'logify_wp_db_version', '1.0' );
        // Legacy installs still need their original tables. Never rerun dbDelta
        // on an established installation just to add the new date index.
        if ( version_compare( $installed_version, '1.3.0', '<' ) ) {
            Database::create_all_tables();
            update_option( 'logify_wp_db_version', '1.3.0' );
            $installed_version = '1.3.0';
        }
        if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
            self::schedule_event_date_index();
        }

        // An older PHP-error reader used the *plural* action hook name, which
        // some installations retained after the one-time 1.5 cleanup. Unqueue
        // it independently even when that earlier migration has completed.
        if ( ! get_option( 'logify_wp_legacy_error_actions_cleaned', false )
            && function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'logify_wp_process_errors' );
            update_option( 'logify_wp_legacy_error_actions_cleaned', 1, false );
        }

        // Run removed PHP error feature cleanup once, without running dbDelta
        // on existing installations merely because a feature was removed.
        if ( ! get_option( 'logify_wp_php_error_cleanup_complete', false ) ) {
            self::remove_php_error_feature();
            update_option( 'logify_wp_php_error_cleanup_complete', 1, false );
        }
    }

	/**
	 * Schedule the date-index migration separately from the admin request.
	 * On failure it stays deferred, with a diagnostic option for an administrator
	 * to inspect; the schema version is never marked complete prematurely.
	 */
	private static function schedule_event_date_index(): void {
		if ( get_option( 'logify_wp_date_index_migration_error', false ) ) {
			return; // Avoid retrying an incompatible ALTER on every page view.
		}
		$hook = 'logify_wp_add_event_date_index';
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'logify' ) ) {
				as_schedule_single_action( time() + 60, $hook, array(), 'logify', true );
			}
		} elseif ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + 60, $hook );
		}
	}

	/**
	 * Add a covering chronological index using a non-blocking InnoDB operation.
	 * Do not trigger an uncontrolled dbDelta table rebuild from admin_init.
	 * Sites whose MySQL/MariaDB backend cannot honor LOCK=NONE should have a
	 * database administrator install the index during a maintenance window.
	 */
	public static function install_event_date_index(): void {
		global $wpdb;
		$table = Event_Repository::get_table_name();
		$found = $wpdb->get_var( $wpdb->prepare(
			'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'when_happened'
		) );
		if ( ! $found ) {
			$result = $wpdb->query( $wpdb->prepare(
				'ALTER TABLE %i ADD INDEX when_happened (when_happened), ALGORITHM=INPLACE, LOCK=NONE', $table
			) );
			if ( false === $result ) {
				update_option( 'logify_wp_date_index_migration_error',
					substr( (string) $wpdb->last_error, 0, 250 ), false );
				Debug::warning( 'Logify WP date index upgrade deferred; schedule manual database migration.' );
				return;
			}
		}
		delete_option( 'logify_wp_date_index_migration_error' );
		update_option( 'logify_wp_db_version', self::DB_VERSION );
	}

	/**
	 * Remove what the PHP error capture feature left behind.
	 *
	 * Runs once, independently of the database schema version. The errors table held one row per
	 * error occurrence and could be very large on a site that had the feature switched on
	 * while something was erroring, so leaving it in place is not a kindness.
	 *
	 * @return void
	 */
	private static function remove_php_error_feature(): void {
		global $wpdb;

		// Drop the table the feature wrote to.
		$errors_table = $wpdb->prefix . 'logify_wp_errors';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $errors_table ) );

		// Remove its settings.
		delete_option( 'logify_wp_php_error_types' );
		delete_option( 'logify_wp_keep_period_errors' );
		delete_option( 'logify_wp_capture_start_time' );
		delete_option( 'logify_wp_last_log_position' );

		// Cancel any background work it had queued. The feature scheduled its reader through
		// Action Scheduler, and an action pointing at a class that no longer exists would
		// fail on every run.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'logify_wp_async_schedule_process', 'logify_wp_process_error_log', 'logify_wp_process_errors' ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		wp_clear_scheduled_hook( 'logify_wp_process_error_log' );
	}

	/**
	 * Create the plugin's tables on a newly created Multisite sub-site.
	 *
	 * @param mixed $new_site The new site object (WP_Site).
	 * @return void
	 */
	public static function on_new_multisite_site( $new_site ) {
		if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
			return;
		}

		// Only relevant when the plugin is active across the network; on a single-site
		// activation the new sub-site will not be running it.
		if ( function_exists( 'is_plugin_active_for_network' ) && defined( 'LOGIFY_WP_PLUGIN_BASENAME' ) ) {
			if ( ! is_plugin_active_for_network( LOGIFY_WP_PLUGIN_BASENAME ) ) {
				return;
			}
		}

		switch_to_blog( (int) $new_site->blog_id );

		try {
			Database::create_all_tables();
			update_option( 'logify_wp_db_version', self::DB_VERSION );
		} catch ( \Throwable $e ) {
			Debug::error( 'Logify WP: failed to create tables for new site', $e->getMessage() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {

		wp_clear_scheduled_hook( 'logify_wp_cleanup' );
		wp_clear_scheduled_hook( 'logify_wp_cleanup_continue' );

		// The Action Scheduler bloat cleaner was left scheduled on deactivation, so it kept
		// firing (and doing nothing useful) until the site was cleaned up by hand.
		wp_clear_scheduled_hook( 'logify_cleanup_bloat_event' );
	}

	/**
	 * Run on uninstallation.
	 */
	public static function uninstall() {
		// On Multisite, tables and options live on every site in the network, so uninstalling
		// from the network admin has to visit each one. Previously only the site the uninstall
		// happened to run on was cleaned up, leaving orphaned tables and options behind on
		// every other site - and leaving the user's "delete all data" choice unhonoured.
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => 0,
					'update_site_meta_cache' => false,
				)
			);

			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				try {
					self::uninstall_current_site();
				} catch ( \Throwable $e ) {
					Debug::error( 'Logify WP: uninstall failed for site ' . (int) $site_id, $e->getMessage() );
				} finally {
					restore_current_blog();
				}
			}

			return;
		}

		self::uninstall_current_site();
	}

	/**
	 * Remove the plugin's data from the current site.
	 *
	 * @return void
	 */
	private static function uninstall_current_site() {
		// Drop the tables if the option is set to do so.
		if ( Plugin_Settings::get_delete_on_uninstall() ) {
			Database::drop_all_tables();
		}

		// Remove scheduled work, so nothing is left pointing at code that no longer exists.
		wp_clear_scheduled_hook( 'logify_wp_cleanup' );
		wp_clear_scheduled_hook( 'logify_wp_cleanup_continue' );
		wp_clear_scheduled_hook( 'logify_cleanup_bloat_event' );

		// Delete settings.
		Plugin_Settings::delete_all();
	}

	/**
	 * Create plugin action links and attach to existing array.
	 *
	 * @param array $links Existing links.
	 * @return array The modified array of links.
	 */
	public static function add_action_links( array $links ) {
		// Link to settings.
		$settings_page_link = '<a href="' . admin_url( 'admin.php?page=logify-wp-settings' ) . '">' . __( 'Settings', 'logify-wp' ) . '</a>';
		array_unshift( $links, $settings_page_link );

		// Link to view the log.
		$log_page_link = '<a href="' . admin_url( 'admin.php?page=logify-wp' ) . '">' . __( 'View log', 'logify-wp' ) . '</a>';
		array_unshift( $links, $log_page_link );

		return $links;
	}
}
