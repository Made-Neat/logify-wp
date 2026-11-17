<?php
/**
 * Contains the Log_Filters class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTime;
use DateTimeZone;
use DateTimeImmutable;

/**
 * Class Logify_WP\Log_Filters
 *
 * Turns the filter state into a SQL WHERE clause.
 *
 * The events table and the CSV export both need to apply the same filters, and when each had
 * its own copy they drifted immediately - the export compared a site-formatted date straight
 * against the site-local timestamp column rather than parsing and converting it, so an exported range could be
 * a day out. One implementation, used by both.
 *
 * The filter state is read from cookies, which are client-controlled, so every value here is
 * either matched against an allow-list or bound as a query parameter. Nothing is interpolated
 * into SQL.
 */
class Log_Filters {

	/**
	 * Build the WHERE clause for the current filter state.
	 *
	 * @param string $search_value Optional search term. The table passes DataTables' search
	 *                             value; the export passes the term from the request.
	 * @return array{0:string,1:array} The WHERE clause (including the WHERE keyword, or an
	 *                                 empty string) and the arguments to bind to it.
	 */
	public static function build_where_clause( string $search_value = '' ): array {
		global $wpdb;

		// Get the object types to show events for.
		$valid_object_types = array_keys( Logger::VALID_OBJECT_TYPES );
		if ( ! empty( $_COOKIE['object_types'] ) ) {
			$cookie_value          = sanitize_text_field( wp_unslash( $_COOKIE['object_types'] ) );
			$selected_object_types = json_decode( stripslashes( $cookie_value ), true );

			// A cookie that will not decode is corrupt, not a deliberate choice to deselect
			// every type, so it falls back to showing everything. Only a valid cookie that
			// says "none selected" produces an empty result.
			if ( ! is_array( $selected_object_types ) ) {
				$object_types = $valid_object_types;
			} else {
				$object_types = array_keys( array_filter( $selected_object_types ) );
			}

			// Only ever accept object types we recognise. The cookie is client-controlled
			// and these values reach the WHERE clause, so an allow-list is mandatory here.
			$object_types = array_values( array_intersect( $object_types, $valid_object_types ) );

			// Deliberately not falling back to "all" here. The cookie records an explicit
			// choice per type, so if the person has unchecked every box the honest answer is
			// that nothing can match - falling back to every type meant unchecking everything
			// showed everything, which is the opposite of what the control says. The log page
			// explains the empty result and offers a way back.
		} else {
			$object_types = $valid_object_types;
		}

		// Get the date range.
		$start_date = isset( $_COOKIE['start_date'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['start_date'] ) )
			: null;
		$end_date   = isset( $_COOKIE['end_date'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['end_date'] ) )
			: null;

		// Get the post type and taxonomy.
		$post_type = isset( $_COOKIE['post_type'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['post_type'] ) )
			: null;
		$taxonomy  = isset( $_COOKIE['taxonomy'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['taxonomy'] ) )
			: null;

		// Get the event type.
		$event_type = isset( $_COOKIE['event_type'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['event_type'] ) )
			: null;

		// Get the user.
		$user_id = isset( $_COOKIE['user_id'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['user_id'] ) )
			: null;

		// Get the role.
		$role = isset( $_COOKIE['role'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['role'] ) )
			: null;


		// Build the where clause.
		$where_parts = array();
		$where_args  = array();
		

		// Filter by search string if specified.
		if ( $search_value !== '' ) {
			$search_sql = '(when_happened LIKE %s
                    OR user_role LIKE %s
                    OR user_ip LIKE %s
                    OR user_location LIKE %s
                    OR user_agent LIKE %s
                    OR event_type LIKE %s
                    OR object_type LIKE %s
                    OR object_name LIKE %s
                    OR user_login LIKE %s
                    OR user_email LIKE %s
                    OR display_name LIKE %s';
			// Full IP searches should also find candidate addresses displayed in the
			// Source IP column. Use an exact serialized value, not a full metadata
			// scan for general text searches.
			$match_reported_ip = (bool) filter_var( $search_value, FILTER_VALIDATE_IP );
			if ( $match_reported_ip ) {
				$search_sql .= ' OR EXISTS (SELECT 1 FROM %i ipm WHERE ipm.event_id = e.event_id AND ipm.meta_key = %s AND ipm.meta_value LIKE %s)';
			}
			$where_parts[] = $search_sql . ')';
			$like_value    = '%' . $wpdb->esc_like( $search_value ) . '%';
			$where_args    = array_merge(
				$where_args,
				array(
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
					$like_value,
				)
			);
			if ( $match_reported_ip ) {
				$where_args[] = Eventmeta_Repository::get_table_name();
				$where_args[] = 'logify_ip_attribution';
				$where_args[] = '%' . $wpdb->esc_like( serialize( $search_value ) ) . '%';
			}
		}

		// Filter by object type and subtype if specified.
		$all_object_types_selected = empty( array_diff( $valid_object_types, $object_types ) );

		if ( ! $object_types ) {
			// No object type is selected, so no event can match.
			$where_parts[] = '1 = 0';
		} elseif ( ! $all_object_types_selected || $post_type || $taxonomy ) {
			// Assemble the OR parts.
			$or_parts = array();

			// Filter by post type if specified.
			if ( in_array( 'post', $object_types, true ) && $post_type ) {
				// Remove 'post' from object_types.
				$object_types = array_diff( $object_types, array( 'post' ) );
				$or_parts[]   = "(object_type = 'post' AND object_subtype = %s)";
				$where_args[] = $post_type;
			}

			// Filter by taxonomy if specified.
			if ( in_array( 'term', $object_types, true ) && $taxonomy ) {
				// Remove 'term' from object_types.
				$object_types = array_diff( $object_types, array( 'term' ) );
				$or_parts[]   = "(object_type = 'term' AND object_subtype = %s)";
				$where_args[] = $taxonomy;
			}

			// Filter by the remaining object types.
			if ( $object_types ) {
				// Bind as parameters. Never interpolate these values, even though they are
				// allow-listed above; prepare() treats the assembled WHERE fragment as
				// literal SQL, so interpolation here would be an injection point.
				$placeholders = Database::in_placeholders( count( $object_types ), '%s' );
				$or_parts[]   = "object_type IN ($placeholders)";
				$where_args   = array_merge( $where_args, $object_types );
			}

			// Add the OR parts to the where clause.
			if ( count( $or_parts ) === 1 ) {
				$where_parts[] = $or_parts[0];
			} elseif ( count( $or_parts ) > 1 ) {
				$where_parts[] = '(' . implode( ' OR ', $or_parts ) . ')';
			}
		}

		// Filter by date range if specified.
		if ( $start_date || $end_date ) {
			// Get the date format and time zone from the site settings.
			$date_format = get_option( 'date_format' );
			$timezone_string = get_option('timezone_string');
			if (!$timezone_string) {
				// Fallback to gmt_offset if timezone_string is empty
				$gmt_offset = get_option('gmt_offset', 0);
				$timezone_string = timezone_name_from_abbr('', $gmt_offset * 3600, false);
				if (!$timezone_string) {
					$timezone_string = 'UTC'; // Fallback to UTC if both are invalid
				}
			}
			$time_zone = new DateTimeZone($timezone_string);
			// Start date.
			if ( $start_date ) {
				// Check the provided string is valid by converting it to a DateTime object.
				$start_date = DateTime::createFromFormat( $date_format, $start_date, $time_zone );
				if ( $start_date ) {
					$where_parts[] = 'when_happened >= %s';
					$where_args[]  = $start_date->format( 'Y-m-d 00:00:00' );
				}
			}

			// End date.
			if ( $end_date ) {
				// Check the provided string is valid by converting it to a DateTime object.
				$end_date = DateTime::createFromFormat( $date_format, $end_date, $time_zone );
				if ( $end_date ) {
					// Add a day, and look for events before this.
					$end_date      = DateTimes::add_days( $end_date, 1 );
					$where_parts[] = 'when_happened < %s';
					$where_args[]  = $end_date->format( 'Y-m-d 00:00:00' );
				}
			}
		}

		// Filter by event type if specified.
		if ( $event_type ) {
			$where_parts[] = 'event_type = %s';
			$where_args[]  = $event_type;
		}

		// Filter by user if specified.
		if ( $user_id !== '' && $user_id !== null ) {
			$where_parts[] = 'user_id = %d';
			$where_args[]  = $user_id;
		}

		// Filter by role if specified.
		if ( $role ) {
			$where_parts[] = 'user_role LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( $role ) . '%';
		}

		// Complete building of the where clause.
		$where = $where_parts ? ( 'WHERE ' . implode( ' AND ', $where_parts ) ) : '';

		return array( $where, $where_args );
	}
}
