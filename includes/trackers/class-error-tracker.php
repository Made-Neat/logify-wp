<?php
/**
 * Contains the Core_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

use DateTime;
use Error_Repository;

class Error_Tracker
{
    private static $start_time = null; // Start time for the error capturing process

    private static $log_file = WP_CONTENT_DIR . "/debug.log"; // Path to the debug log file
    private static $last_position_file = WP_CONTENT_DIR . "/last_log_position.txt"; // Path to the last log position file
    private static array $selected_error_types = array(); // Selected error types to track
    private static string $errors_capture_period = '10mins'; // Period for which errors are captured

    /**
     * Initializes the error tracker.
     */
    public static function init()
    {
        self::load_settings();

        // Hook to schedule background task
        add_action('schedule_process', array(__CLASS__, 'schedule_background_task'));

        // Hook to process errors
        add_action('logify_wp_process_errors', array(__CLASS__, 'process_new_errors'));

        // Ensure the background job is running when tracking is enabled.
        add_action('init', array(__CLASS__, 'ensure_tracking_schedule'), 15);
    }

    private static function load_settings(): void
    {
        self::$selected_error_types = Plugin_Settings::get_php_error_types();
        self::$errors_capture_period = Plugin_Settings::get_keep_period_errors();
    }

    private static function is_tracking_enabled(): bool
    {
        return ! empty( self::$selected_error_types );
    }

    public static function ensure_tracking_schedule(): void
    {
        self::load_settings();

        if ( ! self::is_tracking_enabled() ) {
            self::stop_all_scheduled_actions();
            return;
        }

        if ( function_exists('as_has_scheduled_action') && as_has_scheduled_action('logify_wp_process_errors', array(), 'logify-wp') ) {
            return;
        }

        self::schedule_background_task();
    }

	/**
	 * Start the background task and set the start time.
	 * 
	 * PURPOSE: When PHP error tracking is enabled in settings, this schedules a recurring job
	 * every 60 seconds to read new lines from debug.log and save them to the error repository.
	 * The job has no arguments because it reads from a shared file position tracker.
	 * It auto-stops when the capture period expires (10min/30min/1hr based on settings).
	 */
	public static function schedule_background_task()
	{
		// Unschedule previous actions to avoid duplication
		as_unschedule_all_actions('logify_wp_process_errors', [], 'logify-wp');


		if (count(self::$selected_error_types) > 0) {
			// Schedule a recurring action every 60 seconds to process errors (reduce queue noise)
			as_schedule_recurring_action(time()+10, 60, 'logify_wp_process_errors', [], 'logify-wp');
		} else {
			return;
		}
	}    /**
     * Stop all scheduled actions from the Action Scheduler.
     */
    public static function stop_all_scheduled_actions()
    {
        // Unschedule all instances of the scheduled action
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions('logify_wp_process_errors', [], 'logify-wp');
        }

        delete_option( 'logify_wp_capture_start_time' );

    }

    private static function record_capture_start_time(): void
    {
        $existing = (int) get_option( 'logify_wp_capture_start_time', 0 );
        if ( $existing <= 0 ) {
            update_option( 'logify_wp_capture_start_time', time() );
        }
    }

    /**
     * Retrieve last processed byte offset from the debug log.
     */
    private static function get_last_position(): int
    {
        $last_position_file = self::$last_position_file;

        if ( ! file_exists( $last_position_file ) ) {
            $dir = dirname( $last_position_file );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            file_put_contents( $last_position_file, '0' );
            return 0;
        }

        $contents = file_get_contents( $last_position_file );
        if ( $contents === false ) {
            return 0;
        }

        return (int) $contents;
    }

    /**
     * Update the last read position to a file.
     *
     * @param int $position
     */
    private static function update_last_position($position)
    {
        // Write the updated position to the last position file
        $last_position_file = WP_CONTENT_DIR . "/last_log_position.txt";
        file_put_contents($last_position_file, $position);
    }

    /**
     * Check if a log entry is a PHP error.
     *
     * @param string $log_entry
     * @return bool
     */
    private static function is_php_error($log_entry)
    {
        // Match the PHP error pattern
        return preg_match('/\[(.*?)\] (PHP (Fatal error|Parse error|Warning|Notice|Error)): (.*) in (.*?) on line (\d+)/', $log_entry);
    }

    /**
     * Process new errors from the log file.
     *
     * @param int $start_time
     */
    public static function process_new_errors()
    {
        global $wp_filesystem;

        self::load_settings();

        if ( ! self::is_tracking_enabled() ) {
            self::stop_all_scheduled_actions();
            return;
        }

        // Initialize WP_Filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        self::$log_file = WP_CONTENT_DIR . "/debug.log"; // Define the log file

        // Initialize error repository
        $error_repo = new \Logify_WP\Error_Repository();

        // If the log file does not exist, return early
        if (!$wp_filesystem->exists(self::$log_file)) {
            return;
        }

        // Get the last read position
        $last_position = self::get_last_position();

        // Read the log file content
        $log_content = $wp_filesystem->get_contents(self::$log_file);
        if ($log_content === false) {
            return;
        }

        // Extract new log entries
        $new_logs = explode("\n", substr($log_content, $last_position));

        // Update last position after reading the new logs
        self::update_last_position(strlen($log_content));

        $capture_started = (int) get_option('logify_wp_capture_start_time', 0);
        if ( $capture_started <= 0 ) {
            self::record_capture_start_time();
            $capture_started = (int) get_option('logify_wp_capture_start_time', 0);
        }

        // Stop the scheduled task if the capture period has passed
        $elapsed_time = time() - $capture_started;

        if ( self::has_capture_period_expired( $elapsed_time ) ) {
            self::stop_all_scheduled_actions();
            return;
        }

        // Process each selected error type
        foreach (self::$selected_error_types as $selected_error_type) {
            foreach ($new_logs as $new_log) {
                if (
                    ($selected_error_type === 'Fatal_Errors') &&
                    (strpos($new_log, 'PHP Fatal error') !== false)
                ) {
                    // Extract just the error message part
                    $error_message = '';
                    if (preg_match('/PHP Fatal error:\s*(.*?)(?:\s+in\s+.*?on\s+line\s+\d+)?$/', $new_log, $matches)) {
                        $error_message = trim($matches[1]);
                    } else {
                        $error_message = $new_log;
                    }
                    
                    $error_repo->save((object) [
                        'error_type' => "Fatal Error",
                        'error_content' => esc_html($error_message)
                    ]);

                } elseif (
                    ($selected_error_type === 'Warnings') &&
                    (strpos($new_log, 'PHP Warning') !== false)
                ) {
                    // Extract just the error message part
                    $error_message = '';
                    if (preg_match('/PHP Warning:\s*(.*?)(?:\s+in\s+.*?on\s+line\s+\d+)?$/', $new_log, $matches)) {
                        $error_message = trim($matches[1]);
                    } else {
                        $error_message = $new_log;
                    }
                    
                    $error_repo->save((object) [
                        'error_type' => "Warning",
                        'error_content' => esc_html($error_message)
                    ]);
                    
                } elseif (
                    ($selected_error_type === 'Notices') &&
                    (strpos($new_log, 'PHP Notice') !== false)
                ) {
                    // Extract just the error message part
                    $error_message = '';
                    if (preg_match('/PHP Notice:\s*(.*?)(?:\s+in\s+.*?on\s+line\s+\d+)?$/', $new_log, $matches)) {
                        $error_message = trim($matches[1]);
                    } else {
                        $error_message = $new_log;
                    }

                    $error_repo->save((object) [
                        'error_type' => "Notice",
                        'error_content' => esc_html($error_message)
                    ]);
                }
            }
        }
    }

    /**
     * Determine if selected capture window elapsed to stop scheduling.
     */
    private static function has_capture_period_expired(int $elapsed_time): bool
    {
        if ( self::$errors_capture_period === 'until_deactive' ) {
            return false;
        }

        $limits = array(
            '10mins' => 600,
            '30mins' => 1800,
            '1hour'  => 3600,
        );

        if ( isset( $limits[ self::$errors_capture_period ] ) ) {
            return $elapsed_time > $limits[ self::$errors_capture_period ];
        }

        return true; // Fail-safe: unknown period should stop processing.
    }
}
