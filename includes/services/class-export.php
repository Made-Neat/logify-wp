<?php
/**
 * Contains the Export class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logify_WP\Export
 *
 * Exports the currently filtered events as CSV.
 *
 * The design constraint here is shared hosting. An activity log can hold hundreds of thousands
 * of rows, and the obvious implementations - build an array of every row, or assemble a string
 * and echo it at the end - use memory in proportion to the export and will exhaust a 128MB
 * limit long before they finish. So:
 *
 * - Rows are read in batches and written straight to the output stream. Memory stays flat
 *   whether the export is 20 rows or 200,000.
 * - Output buffering and compression are turned off first, so nothing accumulates a copy of
 *   the response on the way out.
 * - Only columns that come from the events table itself are exported. Including each event's
 *   properties and metadata would mean extra queries per row, which is exactly the N+1 problem
 *   the log page was fixed for.
 * - The row count is checked first and capped, so a mis-clicked export on an unfiltered log
 *   cannot run for minutes.
 */
class Export {

	/**
	 * Rows fetched per query.
	 *
	 * Small enough that one batch is never large in memory, big enough that the query count
	 * stays sensible: 100,000 rows is 200 queries at this size.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Default maximum number of rows in a single export.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_ROWS = 50000;

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_post_logify_wp_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * The maximum number of rows one export may contain.
	 *
	 * @return int
	 */
	public static function get_max_rows(): int {
		/**
		 * Filters the maximum number of rows in a single CSV export.
		 *
		 * @param int $max_rows Default 50,000.
		 */
		return max( 1, (int) apply_filters( 'logify_wp_export_max_rows', self::DEFAULT_MAX_ROWS ) );
	}

	/**
	 * Stream the filtered events as a CSV download.
	 *
	 * @return void
	 */
	public static function export_csv() {
		// Verify the request.
		$nonce = isset( $_GET['logify_wp_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['logify_wp_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'logify_wp_export_csv' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'logify-wp' ), esc_html__( 'Error', 'logify-wp' ), array( 'response' => 403 ) );
		}

		if ( ! Access_Control::can_export_logs() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'logify-wp' ), esc_html__( 'Error', 'logify-wp' ), array( 'response' => 403 ) );
		}

		global $wpdb;

		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		// The filters are the same cookies the log table reads, so the export matches what is
		// on screen without the browser having to describe the filter state again.
		// Log_Filters returns fixed SQL fragments and placeholders only. Untrusted
		// cookie/search values are in $where_args, not the SQL fragment.
		list( $where, $where_args ) = Log_Filters::build_where_clause( $search );

		$events_table = Event_Repository::get_table_name();
		$users_table  = $wpdb->users;
		$base_args    = array( $events_table, $users_table );

		// How many rows are we about to write?
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON e.user_id = u.ID $where",
				array_merge( $base_args, $where_args )
			)
		);

		// Take a high-water mark before writing the export audit event; newer events
		// must not slip into this download or change pagination boundaries.
		$max_event_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(e.event_id) FROM %i e LEFT JOIN %i u ON e.user_id = u.ID $where",
			array_merge( $base_args, $where_args )
		) );

		$max_rows  = self::get_max_rows();
		$truncated = $total > $max_rows;
		$to_export = $truncated ? $max_rows : $total;

		// Record that an export happened, and by whom, before sending anything. An export is a
		// bulk read of personal data - names, email addresses, IP addresses - so it is exactly
		// the kind of action an audit log exists to capture.
		self::log_export( $to_export, $total, $truncated, $search );

		self::send_headers();

		$output = fopen( 'php://output', 'w' );

		if ( $output === false ) {
			wp_die( esc_html__( 'The export could not be started.', 'logify-wp' ) );
		}

		// UTF-8 BOM, so Excel opens the file in the right encoding rather than mangling
		// non-ASCII names and locations.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal UTF-8 BOM bytes required by Excel; this is not user input.
		echo "\xEF\xBB\xBF"; // UTF-8 BOM sent directly to the HTTP output stream.

		fputcsv(
			$output,
			array(
				__( 'ID', 'logify-wp' ),
				__( 'Date and time (site timezone)', 'logify-wp' ),
				__( 'Event', 'logify-wp' ),
				__( 'Object type', 'logify-wp' ),
				__( 'Object subtype', 'logify-wp' ),
				__( 'Object name', 'logify-wp' ),
				__( 'User', 'logify-wp' ),
				__( 'User ID', 'logify-wp' ),
				__( 'Role', 'logify-wp' ),
				__( 'Connection or verified IP', 'logify-wp' ),
				__( 'Reported visitor IP (unverified)', 'logify-wp' ),
				__( 'IP attribution source', 'logify-wp' ),
				__( 'Location', 'logify-wp' ),
				__( 'User agent', 'logify-wp' ),
			)
		);

		$written = 0;
		$last_id = $max_event_id + 1;

		while ( $written < $to_export ) {
			$limit = min( self::BATCH_SIZE, $to_export - $written );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared on the next line with a fixed SQL builder and bound values.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare(
					"SELECT e.* FROM %i e LEFT JOIN %i u ON e.user_id = u.ID $where "
						. ( $where ? 'AND' : 'WHERE' ) . " e.event_id < %d ORDER BY e.event_id DESC LIMIT %d",
					array_merge( $base_args, $where_args, array( $last_id, $limit ) )
				),
				ARRAY_A
			);

			if ( ! $rows ) {
				break;
			}

			// One bounded query for just the IP evidence used in this export
			// batch (not every event's possibly large metadata), avoiding N+1.
			$ids = array_map( 'intval', array_column( $rows, 'event_id' ) );
			$ip_meta = array();
			if ( $ids ) {
				$placeholders = Database::in_placeholders( count( $ids ) );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN() contains generated %d placeholders; event IDs are bound by prepare().
			$ip_records = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT event_id, meta_value FROM %i WHERE event_id IN ($placeholders) AND meta_key = 'logify_ip_attribution'",
						array_merge( array( Eventmeta_Repository::get_table_name() ), $ids )
					),
					ARRAY_A
				);
				foreach ( (array) $ip_records as $meta_row ) {
					if ( Serialization::try_unserialize( $meta_row['meta_value'], $meta_value ) && is_array( $meta_value ) ) {
						$reported = $meta_value['reported'] ?? null;
						$source = $meta_value['source'] ?? null;
						$ip_meta[ (int) $meta_row['event_id'] ] = array(
							'reported' => is_string( $reported ) && filter_var( $reported, FILTER_VALIDATE_IP ) ? $reported : '',
							'source' => is_string( $source ) && strlen( $source ) <= 30 ? $source : '',
						);
					}
				}
			}

			foreach ( $rows as $row ) {
				fputcsv(
					$output,
					array(
						...array_map( array( __CLASS__, 'csv_safe' ), array(
							$row['event_id'], $row['when_happened'], $row['event_type'],
							$row['object_type'], $row['object_subtype'], $row['object_name'],
							$row['user_name'], $row['user_id'], $row['user_role'],
							$row['user_ip'],
							$ip_meta[ (int) $row['event_id'] ]['reported'] ?? '',
							$ip_meta[ (int) $row['event_id'] ]['source'] ?? '',
							$row['user_location'], $row['user_agent'],
						) ),
					)
				);
			}

			$written += count( $rows );
			$last_id = (int) $rows[ count( $rows ) - 1 ]['event_id'];

			// Push this batch to the browser and let PHP reclaim the memory rather than
			// holding the whole export in the output buffer.
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();

			// $wpdb keeps the last result set around; drop it before the next batch.
			$wpdb->flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is a php://output HTTP stream, not a filesystem write; WP_Filesystem cannot close it.
		fclose( $output );
		exit;
	}

	/**
	 * Send the download headers.
	 *
	 * @return void
	 */
	/** Prevent spreadsheet formula evaluation of visitor-controlled CSV text. */
	private static function csv_safe( mixed $value ): string {
		$value = (string) ( $value ?? '' );
		return preg_match( '/^[\x00-\x20]*[=+@-]/', $value ) ? "'" . $value : $value;
	}

	private static function send_headers(): void {
		// Discard anything already buffered, and stop PHP or the server adding compression,
		// both of which would hold the whole response in memory before sending it.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Compression would hold the whole response in memory before sending it, which is
		// exactly what this export is written to avoid. The @ suppressor has been removed:
		// if the host does not allow this to be changed there is nothing to be done about it,
		// but the failure is worth recording rather than hiding.
		if ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky
			$compression_off = ini_set( 'zlib.output_compression', '0' );

			if ( $compression_off === false ) {
				Debug::verbose( 'Logify WP: could not disable output compression for the export.' );
			}
		}

		$filename = 'logify-wp-events-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Log the export as an event.
	 *
	 * @param int    $exported  Rows written.
	 * @param int    $matched   Rows the filters matched.
	 * @param bool   $truncated Whether the export hit the row cap.
	 * @param string $search    The search term in force, if any.
	 * @return void
	 */
	private static function log_export( int $exported, int $matched, bool $truncated, string $search ): void {
		$metas = array(
			new Eventmeta( null, 'rows_exported', $exported ),
			new Eventmeta( null, 'rows_matched', $matched ),
		);

		if ( $truncated ) {
			$metas[] = new Eventmeta( null, 'truncated_at', self::get_max_rows() );
		}

		if ( $search !== '' ) {
			$metas[] = new Eventmeta( null, 'search_term', $search );
		}

		$filters = self::describe_filters();

		if ( $filters !== '' ) {
			$metas[] = new Eventmeta( null, 'filters', $filters );
		}

		try {
			// log_event( $event_type, $wp_object, $eventmetas, $properties ) - the metas are
			// the third argument, not the fourth.
			Logger::log_event( 'Activity Log Exported', null, $metas, null, null, true );
		} catch ( \Throwable $e ) {
			// An export should still succeed even if logging it fails.
			Debug::error( 'Logify WP: could not log the export', $e->getMessage() );
		}
	}

	/**
	 * A human-readable description of the filters in force.
	 *
	 * @return string
	 */
	private static function describe_filters(): string {
		$parts = array();

		$cookies = array(
			'start_date'   => __( 'From', 'logify-wp' ),
			'end_date'     => __( 'To', 'logify-wp' ),
			'event_type'   => __( 'Event type', 'logify-wp' ),
			'user_id'      => __( 'User ID', 'logify-wp' ),
			'role'         => __( 'Role', 'logify-wp' ),
			'post_type'    => __( 'Post type', 'logify-wp' ),
			'taxonomy'     => __( 'Taxonomy', 'logify-wp' ),
			'object_types' => __( 'Object types', 'logify-wp' ),
		);

		foreach ( $cookies as $key => $label ) {
			if ( empty( $_COOKIE[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );

			if ( $key === 'object_types' ) {
				$decoded = json_decode( stripslashes( $value ), true );

				if ( ! is_array( $decoded ) ) {
					continue;
				}

				$selected = array_keys( array_filter( $decoded ) );

				// Everything selected is the default, so it is not worth recording.
				if ( count( $selected ) === count( Logger::VALID_OBJECT_TYPES ) ) {
					continue;
				}

				$value = implode( ', ', $selected );
			}

			if ( $value === '' ) {
				continue;
			}

			$parts[] = $label . ': ' . $value;
		}

		return implode( '; ', $parts );
	}

}
