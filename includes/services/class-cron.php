<?php
/**
 * Contains the Cron class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UnexpectedValueException;

/**
 * Class Logify_WP\Cron
 *
 * Encapsulates all cron-related methods.
 */
class Cron {
	/**
	 * Initializes the class.
	 */
	public static function init() {
		add_action( 'logify_wp_cleanup', array( __CLASS__, 'cleanup_old_records' ) );
		add_action( 'logify_wp_cleanup_continue', array( __CLASS__, 'cleanup_old_records' ) );
		if ( ! wp_next_scheduled( 'logify_wp_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'logify_wp_cleanup' );
		}
	}

	/**
	 * Cleanup old records from the events table.
	 *
	 * @throws UnexpectedValueException If the unit is invalid.
	 */
	/**
	 * Days to keep records when the retention unit is not recognised.
	 *
	 * @param int    $quantity The configured quantity.
	 * @param string $units    The unrecognised unit.
	 * @return float|int The number of days, treating the unit as months.
	 */
	private static function fallback_days( int $quantity, string $units ) {
		Debug::warning( 'Logify WP: unrecognised log retention unit, treating it as months', $units );

		return $quantity * DateTimes::DAYS_PER_MONTH;
	}

	public static function cleanup_old_records() {
		global $wpdb;

		// Calculate the number of days to keep records.
		$quantity = Plugin_Settings::get_keep_period_quantity();
		$units    = Plugin_Settings::get_keep_period_units();
		$days     = match ( $units ) {
			'day'   => $quantity,
			'week'  => $quantity * DateTimes::DAYS_PER_WEEK,
			'year'  => $quantity * DateTimes::DAYS_PER_YEAR,
			// An unrecognised unit used to throw, which meant an uncaught exception inside a
			// cron callback and log cleanup never running again. Fall back to months, the
			// default, and record why.
			default => self::fallback_days( $quantity, $units ),
			'month' => $quantity * DateTimes::DAYS_PER_MONTH,
		};
		$days = max( 1, absint( ceil( $days ) ) );

		// Stored event DATETIME values are site-local (Event::create uses wp_timezone()).
		// Compare site-local strings; never compare a UTC cutoff to a site-local column.
		$cutoff_date = DateTimes::current_datetime()
			->modify( '-' . $days . ' days' )
			->format( 'Y-m-d H:i:s' );

		delete_transient( 'logify_wp_events_total' );

		// Always delete child rows before deleting their parent events. The previous
		// direct DELETE permanently orphaned properties, metadata and notes.
		// Limit work per cron request, with a continuation when a large backlog exists.
		$batch_size = 250;
		$max_batches = 20;
		for ( $batch = 0; $batch < $max_batches; ++$batch ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT event_id FROM %i WHERE when_happened < %s ORDER BY when_happened ASC, event_id ASC LIMIT %d',
					Event_Repository::get_table_name(), $cutoff_date, $batch_size
				)
			) );
			if ( ! $ids ) {
				return;
			}

			$placeholders = Database::in_placeholders( count( $ids ) );
			foreach ( array(
				Property_Repository::get_table_name(),
				Eventmeta_Repository::get_table_name(),
				Note_Repository::get_table_name(),
				Event_Repository::get_table_name(),
			) as $table ) {
				$column = $table === Event_Repository::get_table_name() ? 'event_id'
					: ( $table === Note_Repository::get_table_name() ? 'activity_id' : 'event_id' );
				// Both identifiers are bound via %i. The IN placeholders contain only
				// generated %d tokens; every event ID is bound independently.
				$result = $wpdb->query( $wpdb->prepare(
					"DELETE FROM %i WHERE %i IN ($placeholders)",
					array_merge( array( $table, $column ), $ids )
				) );
				if ( false === $result ) {
					Debug::error( 'Logify WP: retention deletion failed for table', $table );
					return; // Do not delete parent events if children failed.
				}
			}
			if ( count( $ids ) < $batch_size ) {
				return;
			}
		}

		if ( ! wp_next_scheduled( 'logify_wp_cleanup_continue' ) ) {
			wp_schedule_single_event( time() + 300, 'logify_wp_cleanup_continue' );
		}

	}
}
