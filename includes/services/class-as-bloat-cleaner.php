<?php
/**
 * Contains the AS_Bloat_Cleaner class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

/**
 * Class AS_Bloat_Cleaner
 *
 * Manages periodic cleanup of Action Scheduler jobs and logs to prevent database bloat.
 * Deletes old completed/failed actions and their logs in batches with time limits.
 */
final class AS_Bloat_Cleaner {
	/**
	 * Cron hook name for cleanup events.
	 */
	private const CRON_HOOK = 'logify_cleanup_bloat_event';

	/**
	 * Cron interval name.
	 */
	private const CRON_INTERVAL = 'ten_minutes';

	/**
	 * Cron interval in seconds (10 minutes).
	 */
	private const CRON_INTERVAL_SECS = 600;

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
	 * Max args size (in bytes) before action is considered bloated.
	 */
	private const MAX_ARGS_SIZE = 8192;

	/**
	 * Prefix used for error_log output.
	 */
	private const LOG_PREFIX = '[Logify WP][BloatCleaner] ';

	/**
	 * Initialize the cleaner by registering hooks.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Schedule the cleanup cron job if not already scheduled.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
	}

	/**
	 * Register custom cron interval.
	 *
	 * @param array $schedules List of existing cron schedules.
	 * @return array Modified list with new interval added.
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => self::CRON_INTERVAL_SECS,
			'display'  => esc_html__( 'Every 10 Minutes', 'logify-wp' ),
		);
		return $schedules;
	}

	/**
	 * Main cleanup routine. Runs in phases with time limits to avoid long locks.
	 */
	public static function run(): void {
		global $wpdb;

		$start = microtime( true );

		// Calculate cutoff date (keep only recent actions/logs).
		$cutoff_date = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		self::log( sprintf( 'Run started; cutoff=%s', $cutoff_date ) );

		// Phase 1: Delete actions with oversized or "middle_" hooks.
		self::delete_bloated_actions( $wpdb, $start, $cutoff_date );

		// Phase 2: Delete completed/failed actions older than cutoff.
		self::delete_aged_actions( $wpdb, $start, $cutoff_date );

		// Phase 3: Delete aged logs.
		self::delete_aged_logs( $wpdb, $start, $cutoff_date );

		// Phase 4: Delete orphan logs (action deleted but log remains).
		self::delete_orphan_logs( $wpdb, $start );

		self::log( 'Run finished.' );
	}

	/**
	 * Delete actions with oversized args or "middle_" hooks (internal plugin cleanup).
	 *
	 * @param \wpdb   $wpdb        Database object.
	 * @param float   $start       Start microtime.
	 * @param string  $cutoff_date Cutoff date for retention.
	 */
	private static function delete_bloated_actions( $wpdb, $start, $cutoff_date ): void {
		do {
			if ( self::time_exceeded( $start ) ) {
				break;
			}

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE (hook LIKE %s OR hook = %s OR LENGTH(args) > %d) LIMIT %d",
					'middle\\\_%',
					'logify_wp_process_errors',
					self::MAX_ARGS_SIZE,
					self::ACTION_BATCH_SIZE
				)
			);
			if ( empty( $ids ) ) {
				self::log( 'Phase1: no bloated actions found.' );
				break;
			}

			$deleted = self::delete_action_ids( $ids, $start );
			self::log( sprintf( 'Phase1: deleted %d bloated actions (batch size=%d).', $deleted, count( $ids ) ) );
		} while ( count( $ids ) >= self::ACTION_BATCH_SIZE );
	}

	/**
	 * Delete completed/failed actions older than cutoff date.
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
					"SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('complete','failed') AND COALESCE(last_attempt_gmt, scheduled_date_gmt) < %s LIMIT %d",
					$cutoff_date,
					self::ACTION_BATCH_SIZE
				)
			);
			if ( empty( $ids ) ) {
				self::log( 'Phase2: no aged actions found.' );
				break;
			}

			$deleted = self::delete_action_ids( $ids, $start );
			self::log( sprintf( 'Phase2: deleted %d aged actions (batch size=%d).', $deleted, count( $ids ) ) );
		} while ( count( $ids ) >= self::ACTION_BATCH_SIZE );
	}

	/**
	 * Delete logs older than cutoff date.
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

			$log_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT log_id FROM {$wpdb->prefix}actionscheduler_logs WHERE log_date_gmt < %s LIMIT %d",
					$cutoff_date,
					self::LOG_BATCH_SIZE
				)
			);
			if ( empty( $log_ids ) ) {
				self::log( 'Phase3: no aged logs found.' );
				break;
			}

			$deleted = self::delete_log_ids( $wpdb, $logs_table, $log_ids, $start );
			self::log( sprintf( 'Phase3: deleted %d aged logs (batch size=%d).', $deleted, count( $log_ids ) ) );
		} while ( count( $log_ids ) >= self::LOG_BATCH_SIZE );
	}

	/**
	 * Delete orphan logs (logs for actions that have been deleted).
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
				self::log( 'Phase4: no orphan logs found.' );
				break;
			}

			$deleted = self::delete_log_ids( $wpdb, $logs_table, $log_ids, $start );
			self::log( sprintf( 'Phase4: deleted %d orphan logs (batch size=%d).', $deleted, count( $log_ids ) ) );
		} while ( count( $log_ids ) >= self::LOG_BATCH_SIZE );
	}

	/**
	 * Delete a batch of action IDs using the Action Scheduler store.
	 *
	 * @param array $action_ids Array of action IDs to delete.
	 * @param float $start      Start microtime for time limit check.
	 */
	private static function delete_action_ids( $action_ids, $start ): int {
		$deleted = 0;
		foreach ( $action_ids as $action_id ) {
			try {
				\ActionScheduler::store()->delete_action( $action_id );
				$deleted++;
			} catch ( \Exception $e ) {
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
		$deleted = 0;
		foreach ( $log_ids as $log_id ) {
			$wpdb->delete( $logs_table, array( 'log_id' => $log_id ), array( '%d' ) );
			$deleted++;

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
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Debug::info( self::LOG_PREFIX . $message );
		}
	}
}