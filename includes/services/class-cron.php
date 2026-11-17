<?php
/**
 * Contains the Cron class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

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
		if ( ! wp_next_scheduled( 'logify_wp_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'logify_wp_cleanup' );
		}
	}

	/**
	 * Cleanup old records from the events table.
	 *
	 * @throws UnexpectedValueException If the unit is invalid.
	 */
	public static function cleanup_old_records() {
		global $wpdb;

		// Calculate the number of days to keep records.
		$quantity = Plugin_Settings::get_keep_period_quantity();
		$units    = Plugin_Settings::get_keep_period_units();
		$days     = match ( $units ) {
			'day'   => $quantity,
			'week'  => $quantity * DateTimes::DAYS_PER_WEEK,
			'month' => $quantity * DateTimes::DAYS_PER_MONTH,
			'year'  => $quantity * DateTimes::DAYS_PER_YEAR,
			default => throw new UnexpectedValueException( esc_html( "Invalid unit: $units" ) ),
		};
		$days = absint( ceil( $days ) );

		$timestamp    = current_time( 'timestamp' );
		$cutoff_time  = $timestamp - (int) $days * DAY_IN_SECONDS;
		$cutoff_date  = gmdate( 'Y-m-d H:i:s', $cutoff_time );

		// Now delete rows older than the cutoff.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE when_happened < %s',
				Event_Repository::get_table_name(),
				$cutoff_date
			)
		);
	}
}
