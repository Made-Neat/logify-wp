<?php
/**
 * Plugin Name: Logify WP - Activity Log & User Audit Log
 * Plugin URI: https://logifywp.com
 * Description: Logify WP features advanced tracking to ensure awareness of all changes made to your WordPress website, including who made them and when.
 * Version: 1.4.0
 * Author: Made Neat
 * Author URI: https://madeneat.com
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: logify-wp
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Logify WP is a plugin that logs all changes made to your WordPress website, including who made them
 * and when. It features advanced tracking to ensure awareness of all changes made to your WordPress
 * website.
 *
 * This plugin was created by Made Neat, a web development agency based in Australia. We specialize in
 * creating custom WordPress websites and plugins for businesses of all sizes. If you need help with
 * your WordPress website, please get in touch with us at https://madeneat.com.
 *
 * This plugin is released under the GPL2 license. You are free to use, modify, and distribute this
 * plugin as you see fit. We hope you find it useful!
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

if (!defined('WP_PLUGIN_DIR')) {
    require_once(ABSPATH . 'wp-settings.php');
}

// Some useful globals.
define('LOGIFY_WP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LOGIFY_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Detect if plugin is being deleted
$logify_wp_is_deleting = false;
if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'delete-plugin' ) {
	if ( isset( $_REQUEST['plugin'] ) && $_REQUEST['plugin'] === plugin_basename( __FILE__ ) ) {
		$logify_wp_is_deleting = true;
	}
}

// =================================================================================================
// Include all classes.

// Helper classes, mostly for working with data and types.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-arrays.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-datetimes.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-object-reference.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-serialization.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-set.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-strings.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-types.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-urls.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/helpers/class-as-guard.php';

// Classes that encapsulate the core plugin data types.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/models/class-error.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/models/class-event.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/models/class-eventmeta.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/models/class-property.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/models/class-note.php';

// Classes for interacting with the database.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-repository.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-event-repository.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-eventmeta-repository.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-property-repository.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-note-repository.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/repositories/class-error-repository.php';

// Classes that provide plugin functionality.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-access-control.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-admin.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-cron.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-dashboard-widget.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-data-migration.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-database.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-debug.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-log-page.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-notes-page.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-php-error-log.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-logger.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-main.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-plugin-settings.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/services/class-as-bloat-cleaner.php';

// Classes for tracking events.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-async-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-error-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-comment-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-core-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-media-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-option-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-plugin-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-post-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-term-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-theme-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-user-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-widget-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-gravityforms-tracker.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-beaverbuilder-tracker.php';
// Setting tracker was redundant with Option_Tracker; not loading to avoid duplicate logs
// require_once LOGIFY_WP_PLUGIN_DIR . 'includes/trackers/class-setting-tracker.php';

// Classes for working with application objects.
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-object-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-comment-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-core-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-media-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-menu-item-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-option-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-plugin-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-post-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-taxonomy-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-term-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-theme-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-user-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-widget-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-gravityform-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-gravityentry-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-gravitynotification-utility.php';
require_once LOGIFY_WP_PLUGIN_DIR . 'includes/utilities/class-beaverbuilder-utility.php';

//check website already has ActionScheduler library
if(!class_exists('ActionScheduler')){
    // Only load Action Scheduler if plugin is not being deleted
    if (!$logify_wp_is_deleting) {
        // Require action scheduler library
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'action-scheduler' . DIRECTORY_SEPARATOR . 'action-scheduler.php';
    }
}

// Only initialize bloat cleaner if plugin is not being deleted
if (!$logify_wp_is_deleting) {
    \Logify_WP\AS_Bloat_Cleaner::init();
}

// =================================================================================================

// Register plugin hooks.
add_action( 'init', array( 'Logify_WP\Main', 'init' ) );
register_activation_hook( __FILE__, array( 'Logify_WP\Main', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Logify_WP\Main', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Logify_WP\Main', 'uninstall' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( 'Logify_WP\Main', 'add_action_links' ) );

// NOTE: this plugin previously disabled WordPress's comment flood protection
// site-wide via add_filter( 'comment_flood_filter', '__return_false' ).
// That was a development convenience that removed a core anti-abuse control for
// every visitor on every install. Removed in 1.3.5. Do not reinstate it.
