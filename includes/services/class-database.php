<?php
/**
 * Contains the Database class.
 *
 * @package Logify_WP;
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database stuff.
 */
class Database {

	/**
	 * Create all tables.
	 */
	public static function create_all_tables() {
		Event_Repository::create_table();
		Eventmeta_Repository::create_table();
		Property_Repository::create_table();
		Note_Repository::create_table();
	}

	/**
	 * Drop all tables.
	 */
	public static function drop_all_tables() {
		Event_Repository::drop_table();
		Eventmeta_Repository::drop_table();
		Property_Repository::drop_table();
		Note_Repository::drop_table();
	}

	/**
	 * Build a placeholder list for a SQL IN () clause.
	 *
	 * $wpdb->prepare() has no placeholder for a variable-length list, so the only way to bind an
	 * IN clause is to build the placeholder string from the number of values and interpolate
	 * that string into the query. This helper is the single place in the plugin where that
	 * happens, so the safety argument only has to be made - and checked - once:
	 *
	 * - The return value is composed exclusively of '%d' or '%s' tokens and commas. It contains
	 *   no caller data of any kind; only the *count* of values influences it.
	 * - The values themselves are never interpolated. They are passed to prepare() as arguments
	 *   and bound by wpdb, exactly like any other placeholder.
	 *
	 * Static analysers flag the interpolation because they cannot see that the interpolated
	 * string is placeholders rather than data. Concentrating it here means one annotated
	 * occurrence instead of one per query.
	 *
	 * Usage:
	 *     $placeholders = Database::in_placeholders( count( $ids ) );
	 *     $wpdb->prepare( "... WHERE id IN ($placeholders)", $ids );
	 *
	 * @param int    $count The number of values to bind. Must be at least 1.
	 * @param string $type  The placeholder to repeat: '%d' for integers, '%s' for strings.
	 * @return string The comma-separated placeholder list, e.g. "%d,%d,%d".
	 */
	public static function in_placeholders( int $count, string $type = '%d' ): string {
		if ( $count < 1 ) {
			return '';
		}

		// Never accept an arbitrary token here, whatever a caller passes.
		$type = in_array( $type, array( '%d', '%s', '%f' ), true ) ? $type : '%d';

		return implode( ',', array_fill( 0, $count, $type ) );
	}

	/**
	 * Truncate all tables.
	 */
	public static function truncate_all_tables() {
		Event_Repository::truncate_table();
		Eventmeta_Repository::truncate_table();
		Property_Repository::truncate_table();
		Note_Repository::truncate_table();
	}
}
