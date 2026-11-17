<?php
/**
 * Contains the AS_Bloat_Cleaner class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AS_Bloat_Cleaner
 *
 * Manages periodic cleanup of Action Scheduler records created by Logify WP to prevent
 * database bloat. Runs every 10 minutes via cron to:
 * - Delete aged Logify actions (middle_* hooks, logify_wp_process_errors) older than 3 days
 * - Delete aged Action Scheduler log entries older than 3 days
 * - Delete orphan log records (logs pointing to deleted actions)
 * 
 * Uses batched queries with a 20-second runtime limit to avoid long table locks.
 * Targets actions in the 'logify' and 'logify-wp' Action Scheduler groups.
 */
final class AS_Bloat_Cleaner {
	/**
	 * Cron hook name for cleanup events.
	 */
	private const CRON_HOOK = 'logify_cleanup_bloat_event';

	/**
	 * Cron interval name.
	 *
	 * Uses WordPress's built-in hourly schedule. Earlier versions registered a custom
	 * ten-minute interval; see schedule() for the migration of existing sites.
	 */
	private const CRON_INTERVAL = 'hourly';


	/**
	 * Number of days to retain Action Scheduler actions and logs.
	 */
	private const RETENTION_DAYS = 3;

	/**
	 * Maximum runtime per cleanup run in seconds.
	 */
	private const RUNTIME_LIMIT = 20;

	/**
	 * Batch size for action deletions.
	 */
	private const ACTION_BATCH_SIZE = 500;

	/**
	 * Batch size for log deletions.
	 */
	private const LOG_BATCH_SIZE = 1000;

	/**
	 * Prefix used for conditional debug output.
	 */
	private const LOG_PREFIX = '[Logify WP][BloatCleaner] ';

	/**
	 * Initialize the cleaner by registering hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Schedule the cleanup cron job if not already scheduled.
	 */
	public static function schedule(): void {
		$existing = function_exists( 'wp_get_scheduled_event' )
			? wp_get_scheduled_event( self::CRON_HOOK )
			: false;

		if ( $existing ) {
			// Already on the current schedule; nothing to do.
			if ( isset( $existing->schedule ) && $existing->schedule === self::CRON_INTERVAL ) {
				return;
			}

			// Migrate sites still carrying the old ten-minute schedule. This must clear the old
			// event before WordPress tries to reschedule it: the custom interval is no longer
			// registered, so a reschedule would fail and the cleanup would stop running.
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
	}

	/**
	 * Main cleanup routine. Runs in phases with time limits to avoid long locks.
	 */
	public static function run(): void {
		global $wpdb;

		$start = microtime( true );

		// Calculate cutoff date (keep only recent actions/logs). Use UTC so comparisons match *_gmt columns.
		// time() rather than current_time( 'timestamp', true ): both return UTC, but current_time()
		// is documented as unsafe for timestamps and is filterable by other code.
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		self::log( sprintf( 'Run started; cutoff=%s', $cutoff_date ) );

		// Step 1: Delete Logify-specific actions older than the retention window.
		self::delete_aged_actions( $wpdb, $start, $cutoff_date );

		// Step 2: Delete aged logs.
		self::delete_aged_logs( $wpdb, $start, $cutoff_date );

		// Step 3: orphan-log cleanup is DISABLED as of 1.3.5.
		//
		// A log row whose action row is gone carries no group, so there is no way to
		// tell whose orphan it is. The previous implementation deleted every orphan on
		// the site, which on a WooCommerce store meant destroying that plugin's
		// Action Scheduler history. Our own orphans are already prevented: Step 1
		// removes logs alongside their actions via delete_action_ids().
		// Do not re-enable without a way to attribute the row.
		// self::delete_orphan_logs( $wpdb, $start );

		self::log( 'Run finished.' );
	}

	/**
	 * Delete Logify actions (any status) older than the cutoff date.
	 *
	 * @param \wpdb   $wpdb        Database object.
	 * @param float   $start       Start microtime.
	 * @param string  $cutoff_date Cutoff date for retention.
	 */
	private static function delete_aged_actions( $wpdb, $start, $cutoff_date ): void {
		do {
			if ( self::time_exceeded( $start ) ) {
				break;
			}

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.action_id FROM {$wpdb->prefix}actionscheduler_actions a INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id WHERE (a.hook LIKE %s OR a.hook = %s) AND g.slug IN (%s,%s) AND a.status IN (%s,%s,%s) AND COALESCE(a.last_attempt_gmt, a.scheduled_date_gmt) < %s LIMIT %d",
					'middle_%',
					'logify_wp_process_errors',
					'logify',
					'logify-wp',
					// Only ever reap actions that have reached a terminal state. Without this
					// filter a pending action - one that has never run because cron is broken
					// or throttled - is deleted once its scheduled date ages past the cutoff,
					// which means the plugin destroys its own unprocessed audit trail.
					'complete',
					'failed',
					'canceled',
					$cutoff_date,
					self::ACTION_BATCH_SIZE
				)
			);
			if ( empty( $ids ) ) {
				self::log( 'Actions: no aged items found.' );
				break;
			}

			$deleted = self::delete_action_ids( $ids, $start );
			self::log( sprintf( 'Actions: deleted %d aged items (batch size=%d).', $deleted, count( $ids ) ) );
		} while ( count( $ids ) >= self::ACTION_BATCH_SIZE );
	}

	/**
	 * Delete Action Scheduler logs older than the cutoff date.
	 *
	 * @param \wpdb   $wpdb        Database object.
	 * @param float   $start       Start microtime.
	 * @param string  $cutoff_date Cutoff date for retention.
	 */
	private static function delete_aged_logs( $wpdb, $start, $cutoff_date ): void {
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';

		do {
			if ( self::time_exceeded( $start ) ) {
				break;
			}

			// Scope to this plugin's own groups. Action Scheduler is shared infrastructure -
			// WooCommerce and others log into the same table - so an unscoped delete here
			// wipes their execution history along with ours.
			$log_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$wpdb->prefix}actionscheduler_logs l INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON l.action_id = a.action_id INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id WHERE g.slug IN (%s,%s) AND l.log_date_gmt < %s LIMIT %d",
					'logify',
					'logify-wp',
					$cutoff_date,
					self::LOG_BATCH_SIZE
				)
			);
			if ( empty( $log_ids ) ) {
				self::log( 'Logs: no aged records found.' );
				break;
			}

			$deleted = self::delete_log_ids( $wpdb, $logs_table, $log_ids, $start );
			self::log( sprintf( 'Logs: deleted %d aged records (batch size=%d).', $deleted, count( $log_ids ) ) );
		} while ( count( $log_ids ) >= self::LOG_BATCH_SIZE );
	}

	/**
	 * Delete orphan Action Scheduler logs (log rows pointing at missing actions).
	 *
	 * @param \wpdb $wpdb  Database object.
	 * @param float $start Start microtime.
	 */
	private static function delete_orphan_logs( $wpdb, $start ): void {
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';
		do {
			if ( self::time_exceeded( $start ) ) {
				break;
			}

			$log_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$wpdb->prefix}actionscheduler_logs l LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON l.action_id = a.action_id WHERE a.action_id IS NULL LIMIT %d",
					self::LOG_BATCH_SIZE
				)
			);
			if ( empty( $log_ids ) ) {
				self::log( 'Logs: no orphan records found.' );
				break;
			}

			$deleted = self::delete_log_ids( $wpdb, $logs_table, $log_ids, $start );
			self::log( sprintf( 'Logs: deleted %d orphan records (batch size=%d).', $deleted, count( $log_ids ) ) );
		} while ( count( $log_ids ) >= self::LOG_BATCH_SIZE );
	}

	/**
	 * Delete a batch of action IDs using the Action Scheduler store.
	 *
	 * @param array $action_ids Array of action IDs to delete.
	 * @param float $start      Start microtime for time limit check.
	 */
	private static function delete_action_ids( $action_ids, $start ): int {
		// Action Scheduler may not be loaded at all (another plugin's copy won
		// arbitration, or it was deactivated mid-request). Bail rather than fatal.
		if ( ! class_exists( '\ActionScheduler' ) ) {
			Debug::error( 'AS_Bloat_Cleaner: ActionScheduler not available, skipping action cleanup.' );
			return 0;
		}

		$deleted = 0;
		foreach ( $action_ids as $action_id ) {
			try {
				\ActionScheduler::store()->delete_action( $action_id );
				$deleted++;
			} catch ( \Throwable $e ) {
				// \Throwable, not \Exception: a missing class raises \Error, which the
				// previous catch let through and turned into a fatal in the cron request.
				Debug::error( "AS_Bloat_Cleaner: failed to delete action $action_id", $e->getMessage() );
			}

			if ( self::time_exceeded( $start ) ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Delete a batch of log IDs.
	 *
	 * @param \wpdb   $wpdb       Database object.
	 * @param string  $logs_table Name of the logs table.
	 * @param array   $log_ids    Array of log IDs to delete.
	 * @param float   $start      Start microtime for time limit check.
	 */
	private static function delete_log_ids( $wpdb, $logs_table, $log_ids, $start ): int {
		$log_ids = array_values( array_filter( array_map( 'intval', (array) $log_ids ) ) );
		if ( ! $log_ids ) {
			return 0;
		}

		$deleted = 0;

		// Delete in chunks with a single statement each, rather than one DELETE per row.
		// A batch of 1000 rows previously meant 1000 round trips to the database.
		foreach ( array_chunk( $log_ids, 200 ) as $chunk ) {
			$placeholders = Database::in_placeholders( count( $chunk ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare(
					"DELETE FROM %i WHERE log_id IN ($placeholders)",
					array_merge( array( $logs_table ), $chunk )
				)
			);

			if ( is_numeric( $result ) ) {
				$deleted += (int) $result;
			}

			if ( self::time_exceeded( $start ) ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Check if the operation has exceeded the runtime limit.
	 *
	 * @param float $start Start microtime.
	 * @return bool True if runtime limit exceeded.
	 */
	private static function time_exceeded( $start ): bool {
		return ( microtime( true ) - $start ) > self::RUNTIME_LIMIT;
	}

	/**
	 * Output a debug line to error_log when available.
	 */
	private static function log( string $message ): void {
		// Verbose runtime diagnostics, off unless the site opts in with
		// define( 'LOGIFY_WP_DEBUG', true ). Previously this was an empty block, so the
		// sprintf() calls at every call site did work that was always discarded.
		if ( defined( 'LOGIFY_WP_DEBUG' ) && LOGIFY_WP_DEBUG ) {
			Debug::info( self::LOG_PREFIX . $message );
		}
	}
}