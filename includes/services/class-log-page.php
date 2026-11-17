<?php
/**
 * Contains the Log_Page class.
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

/**
 * Class Logify_WP\Log_Page
 *
 * Contains methods for formatting event log entries for display in the admin area.
 */
class Log_Page {

	/**
	 * Initialize the log page.
	 */
	public static function init() {
		add_action( 'wp_ajax_logify_wp_fetch_logs', array( __CLASS__, 'fetch_logs' ) );
		add_action( 'wp_ajax_logify_update_note', array( __CLASS__, 'logify_update_note' ) );
		add_action( 'wp_ajax_logify_add_note', array( __CLASS__, 'logify_add_note' ) );
		add_action( 'wp_ajax_logify_delete_note', array( __CLASS__, 'logify_delete_note' ) );
		add_action( 'wp_ajax_logify_wp_save_filters_state', array( __CLASS__, 'save_filters_state' ) );
		add_action( 'wp_ajax_logify_wp_save_column_preference', array( __CLASS__, 'save_column_preference' ) );
	}

	/**
	 * Refuse the request if the Notes feature is switched off in settings.
	 *
	 * Notes are a global setting: when it is off, nothing note-related should exist -
	 * no menu item, no columns, no queries, and no write endpoints. The Notes_Page
	 * handlers already did this; the Log_Page twins did not, so notes could still be
	 * created and edited through the log page while the feature was disabled.
	 *
	 * @return void
	 */
	private static function ensure_notes_enabled(): void {
		if ( ! Plugin_Settings::get_enable_notes() ) {
			wp_send_json_error( array( 'message' => __( 'Notes feature is not enabled in settings.', 'logify-wp' ) ), 403 );
		}
	}

	public static function logify_update_note() {
		
		check_ajax_referer('logify-wp-log-page', 'security');

		// Notes are a global setting, and read access to the log is not permission to
		// write notes. Both checks were missing here; the Notes_Page twins had them.
		self::ensure_notes_enabled();
		if ( ! Access_Control::can_manage_notes() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'logify-wp' ) ), 403 );
		}
		$note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
		$event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

		if ( !$event_id && !$note_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid note data.', 'logify-wp' ) ) );
		}
		if(empty($_POST['note_content'])){
			wp_send_json_error( array( 'message' => __( 'Note cannot be empty.', 'logify-wp' ) ) );
		}
	
		// Fetch the note to validate ownership
		$note_repo = new \Logify_WP\Note_Repository();

		// A single event can have several notes: only the explicit note ID is unique.
		$existing_note = $note_id ? $note_repo->load( $note_id ) : null;

		if (!$existing_note) {
			wp_send_json_error( array( 'message' => __( 'Note not found.', 'logify-wp' ) ) );
		}
	

		// load_by_event_id() returns a raw database row (string columns, note_id) while load()
		// returns a Note object (int properties, id). Normalise before comparing or saving:
		// the previous strict comparison of a string user_id against an int always failed, so
		// editing a note found by event ID was always refused, and a note_id of 0 reached the
		// repository and threw an uncaught exception.
		$existing_user_id = (int) ( $existing_note->user_id ?? 0 );
		$existing_note_id = (int) ( $existing_note->note_id ?? $existing_note->id ?? 0 );

		// Check if the current user is the owner of the note
		if ( $existing_user_id !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You are not authorized to edit this note.', 'logify-wp' ) ), 403 );
		}

		if ( ! $existing_note_id ) {
			wp_send_json_error( array( 'message' => __( 'Note not found.', 'logify-wp' ) ) );
		}

		try {
			$success = $note_repo->save(
				(object) array(
					'id'   => $existing_note_id,
					'note' => isset( $_POST['note_content'] )
						? wp_kses_post( wp_unslash( $_POST['note_content'] ) )
						: '',
				)
			);
		} catch ( \Throwable $e ) {
			Debug::error( 'Logify WP: failed to update note', $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Failed to update note.', 'logify-wp' ) ) );
		}

		if ($success) {
			wp_send_json_success( array( 'message' => __( 'Note updated successfully.', 'logify-wp' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update note.', 'logify-wp' ) ) );
		}
	}

	/**
	 * Delete a note.
	 *
	 * Notes could be added and edited but never removed, so a note written in error had to be
	 * blanked rather than deleted. Only the note's author can delete it, matching the rule
	 * already applied to editing.
	 *
	 * @return void
	 */
	/**
	 * Remember whether the filter panel was left open.
	 *
	 * Stored per user, so one person collapsing the filters does not change the screen for
	 * everybody else.
	 *
	 * @return void
	 */
	/** Check the existing page-specific nonce used by both log and Notes screens. */
	private static function verify_log_or_notes_nonce(): void {
		$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'logify-wp-log-page' )
			&& ! wp_verify_nonce( $nonce, 'logify-wp-notes-page' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'logify-wp' ) ), 403 );
		}
	}

	public static function save_filters_state() {
		self::verify_log_or_notes_nonce();

		if ( ! Access_Control::can_access_log_page() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'logify-wp' ) ), 403 );
		}

		$open = ! empty( $_POST['open'] );

		update_user_meta( get_current_user_id(), 'logify_wp_filters_open', $open ? 'open' : 'closed' );

		wp_send_json_success();
	}

	public static function logify_delete_note() {
		self::verify_log_or_notes_nonce();

		if ( ! Access_Control::can_manage_notes() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'logify-wp' ) ), 403 );
		}

		if ( ! Plugin_Settings::get_enable_notes() ) {
			wp_send_json_error( array( 'message' => __( 'Notes feature is not enabled in settings.', 'logify-wp' ) ), 403 );
		}

		$note_id = isset( $_POST['note_id'] ) ? absint( wp_unslash( $_POST['note_id'] ) ) : 0;

		if ( ! $note_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid note.', 'logify-wp' ) ) );
		}

		$note_repo     = new Note_Repository();
		$existing_note = $note_repo->load( $note_id );

		if ( ! $existing_note ) {
			wp_send_json_error( array( 'message' => __( 'Note not found.', 'logify-wp' ) ) );
		}

		if ( (int) ( $existing_note->user_id ?? 0 ) !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You are not authorized to delete this note.', 'logify-wp' ) ), 403 );
		}

		try {
			$deleted = $note_repo->delete( $note_id );
		} catch ( \Throwable $e ) {
			Debug::error( 'Logify WP: failed to delete note', $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Failed to delete note.', 'logify-wp' ) ) );
		}

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => __( 'Note deleted.', 'logify-wp' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to delete note.', 'logify-wp' ) ) );
	}

	public static function logify_add_note() {
		
		check_ajax_referer('logify-wp-log-page', 'security');

		// Notes are a global setting, and read access to the log is not permission to
		// write notes. Both checks were missing here; the Notes_Page twins had them.
		self::ensure_notes_enabled();
		if ( ! Access_Control::can_manage_notes() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'logify-wp' ) ), 403 );
		}
	
		// Validate input
		if (empty($_POST['note_content'])) {
			wp_send_json_error( array( 'message' => __( 'Note content cannot be empty.', 'logify-wp' ) ) );
		}

		$event_id = isset($_POST['event_id']) 
		? absint( wp_unslash( $_POST['event_id'] ) ) 
		: 0;
		
		if ( $event_id > 0 && ! Event_Repository::load( $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected activity no longer exists.', 'logify-wp' ) ), 404 );
		}

		// Create note repository instance
		$note_repo = new \Logify_WP\Note_Repository();
		$user = wp_get_current_user();
		// Save new note
		$success = $note_repo->create( [
			'note'        => isset($_POST['note_content']) 
			? wp_kses_post( wp_unslash( $_POST['note_content'] ) )
			: '',
			'user_id'     => get_current_user_id(), // Assuming user ID is required
			'user_role' => !empty($user->roles) ? implode(', ', $user->roles) : 'No role assigned', // Get user roles
    		'user_name'  => $user->user_login, // Get username
			'activity_id' => ($event_id) ? $event_id : 0,
			'created_at'  => current_time('mysql'), // Assuming created_at is required
			'ip_address' => User_Utility::get_ip() ?: '',
		]);
	
		if ($success) {
			wp_send_json_success( array( 'message' => __( 'Note added successfully.', 'logify-wp' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to add note.', 'logify-wp' ) ) );
		}
	}	

	/**
	 * Display the log page.
	 */
	public static function display_log_page() {
		if ( ! Access_Control::can_access_log_page() ) {
			// Disallow access.
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'logify-wp' ), 403 );
		}

		// Get all the data required for the log page.
		$post_types  = Event_Repository::get_post_types();
		$taxonomies  = Event_Repository::get_taxonomies();
		$event_types = Event_Repository::get_event_types();
		$users       = Event_Repository::get_users();
		$roles       = Event_Repository::get_roles();

		// Include the log page template.
		include LOGIFY_WP_PLUGIN_DIR . 'templates/log-page.php';
	}

	/**
	 * Save column visibility preferences to user meta (called via AJAX).
	 *
	 * @return void
	 */
	public static function save_column_preference() {
		// Verify the nonce.
		check_ajax_referer( 'logify-wp-log-page', 'security' );

		// Column preferences are only available to users permitted to view the log.
		if ( ! Access_Control::can_access_log_page() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view activity logs.', 'logify-wp' ) ), 403 );
		}

		// Get and validate the column name.
		$column = isset( $_POST['column'] ) ? sanitize_key( wp_unslash( $_POST['column'] ) ) : '';
		$visible = isset( $_POST['visible'] ) ? (bool) $_POST['visible'] : false;

		// Validate column name.
		$allowed_columns = array( 'logify_wp_col_user', 'logify_wp_col_ip', 'logify_wp_col_object', 'logify_wp_col_notes' );
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid column.', 'logify-wp' ) ), 400 );
		}

		$user_id = get_current_user_id();

		// Persist both states explicitly. A missing value means the new default:
		// User/IP/Notes visible, Object hidden. Saving an explicit hidden state
		// prevents previously customized users losing their choice on refresh.
		update_user_meta( $user_id, $column, $visible ? 'visible' : 'hidden' );

		wp_send_json_success( array( 'message' => __( 'Column preference saved.', 'logify-wp' ) ) );
	}

	/**
	 * Get the user's preference for the number of items to show per page.
	 *
	 * @return int The number of items to show per page.
	 */
	public static function get_items_per_page(): int {
		$page_length = (int) get_user_option( 'logify_wp_events_per_page', get_current_user_id() );
		return $page_length ? $page_length : 20;
	}

	/**
	 * Batch-load notes for multiple event IDs to avoid N+1 query problem.
	 *
	 * @param array $event_ids Array of event IDs.
	 * @return array Associative array with event_id as key and note object as value.
	 */
	public static function batch_load_notes( array $event_ids ): array {
		if ( empty( $event_ids ) ) {
			return array();
		}

		global $wpdb;

		// Check if notes table exists.
		$notes_table = Note_Repository::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $notes_table )
		) === $notes_table;

		if ( ! $table_exists ) {
			return array();
		}

		// Build the IN clause for the query.
		$placeholders = Database::in_placeholders( count( $event_ids ) );
		// The table name is bound with %i; only the placeholder list built by
		// Database::in_placeholders() is interpolated. Previously the table name itself was
		// interpolated into the query string here.
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list only; see Database::in_placeholders().
			"SELECT * FROM %i WHERE activity_id IN ($placeholders) ORDER BY note_id DESC",
			array_merge( array( $notes_table ), $event_ids )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is the result of $wpdb->prepare() above (table and integer IDs bound).
		$results = $wpdb->get_results( $query );

		// Map notes by event ID, keeping only the most recent note per event.
		$notes_map = array();
		foreach ( $results as $note_row ) {
			$event_id = (int) $note_row->activity_id;
			if ( ! isset( $notes_map[ $event_id ] ) ) {
				$notes_map[ $event_id ] = $note_row;
			}
		}

		return $notes_map;
	}

	/**
	 * Fetches logs from the database based on the provided search criteria.
	 */
	public static function fetch_logs() {
		// Verify the nonce
		check_ajax_referer( 'logify-wp-log-page', 'security' );

		// Check user capabilities.
		if ( ! Access_Control::can_access_log_page() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to access this data.', 'logify-wp' ) ), 403 );
		}

		global $wpdb;

		// Get table names.
		$events_table_name = Event_Repository::get_table_name();
		$users_table_name  = $wpdb->users;

		// These should match the columns in admin.js.
		$columns = array(
			'event_id',
			'when_happened',
			'display_name',
			'user_ip',
			'event_type',
			'object_name',
			'object_type',
			'note_content',
			'edit_link',
		);

		// -----------------------------------------------------------------------------------------
		// Extract parameters from the request.

		// Get the number of items per page. We're using the screen options rather than the length
		// argument in the request.
		$page_length = self::get_items_per_page();

		// Get the start item index. Default to 0.
		$start = isset( $_POST['start'] ) ? (int) $_POST['start'] : 0;
		if ( $start < 0 ) {
			$start = 0;
		}
		
		// Get the order-by column. Default to when_happened.
		$order_by_columns = array( 'when_happened' );
		if ( isset( $_POST['order'][0]['column'] ) ) {
			$column_number = (int) $_POST['order'][0]['column'];
			if ( key_exists( $column_number, $columns ) ) {
				$order_by_columns = array( $columns[ $column_number ] );
			}
		}
		// Include ordering by event ID, if not already included.
		if ( ! in_array( 'event_id', $order_by_columns, true ) ) {
			$order_by_columns[] = 'event_id';
		}

		// Get the order-by direction. Check it's valid. Default to DESC.
		$order_by_direction = isset( $_POST['order'][0]['dir'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_POST['order'][0]['dir'] ) ) )
			: 'DESC';
		if ( ! in_array( $order_by_direction, array( 'ASC', 'DESC' ), true ) ) {
			$order_by_direction = 'DESC';
		}

		// Get the search value.
		$search_value = isset( $_POST['search']['value'] )
			? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) )
			: '';

		
		// Note: the filter values (object types, dates, post type, taxonomy, event type, user
		// and role) are read from the same cookies inside Log_Filters::build_where_clause().

		// -----------------------------------------------------------------------------------------
		// Get the total number of events in the database.
		//
		// COUNT(*) with no WHERE clause has to walk the whole index, and this figure is only
		// used for DataTables' "of N entries" label. On a large log that was a needless full
		// count on every draw - every sort, every page, every keystroke in the search box - so
		// it is cached briefly. A count that is a few minutes stale in a label is a fair trade
		// for not hammering a shared-hosting database.
		$total_cache_key   = 'logify_wp_events_total';
		$num_total_records = get_transient( $total_cache_key );

		if ( $num_total_records === false ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$num_total_records = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $events_table_name )
			);

			/**
			 * Filters how long the total event count is cached, in seconds.
			 *
			 * @param int $ttl Default five minutes.
			 */
			$ttl = (int) apply_filters( 'logify_wp_total_count_cache_ttl', 5 * MINUTE_IN_SECONDS );

			set_transient( $total_cache_key, $num_total_records, max( 30, $ttl ) );
		}

		$num_total_records = (int) $num_total_records;

		// -----------------------------------------------------------------------------------------
		// Get the number of filtered records.

		// Select clause.
		$select_count = 'SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON e.user_id = u.ID';

		// Build the where clause.
		//
		// This lives in Log_Filters so that the CSV export applies exactly the same filters as
		// the table. When the two had their own copies, the export's date handling was already
		// wrong: it compared the site-formatted date straight against the UTC column instead of
		// parsing and converting it, so an export could span the wrong day.
		list( $where, $where_args ) = Log_Filters::build_where_clause( $search_value );

		// Construct and run the SQL statement.
		$select_args          = array( $events_table_name, $users_table_name );
		$args                 = array_merge( $select_args, $where_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$num_filtered_records = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "$select_count $where", $args )
		);
		// -----------------------------------------------------------------------------------------
		// Get the requested records.
		
		// Select clause. The full event row is selected here so that the loop below does not have
		// to re-query each event individually (see the batch loading note further down).
		$select = 'SELECT e.* FROM %i e LEFT JOIN %i u ON e.user_id = u.ID';
		
		// Order-by clause.
		$order_by      = '';
		$order_by_args = array();
		if ( ! empty( $order_by_columns ) ) {
			$order_by_parts = array();
			foreach ( $order_by_columns as $order_by_col ) {
				$order_by_parts[] = "%i $order_by_direction";
				$order_by_args[]  = $order_by_col;
			}
			$order_by = 'ORDER BY ' . implode( ', ', $order_by_parts );
			// Debug::info( $order_by_columns, $order_by_direction, $order_by_parts, $order_by_args, $order_by );
		}

		// Limit clause.
		$limit      = 'LIMIT %d OFFSET %d';
		$limit_args = array( $page_length, $start );

		// Construct and run the SQL statement.
		$args = array_merge( $select_args, $where_args, $order_by_args, $limit_args );
		// Debug::sql( $wpdb->prepare( "$select $where $order_by $limit", $args ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recordset = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "$select $where $order_by $limit", $args ),
			ARRAY_A
		);
		//echo $wpdb->last_query;die;
		// -----------------------------------------------------------------------------------------
		// Construct the data array to return to the client.
		$data = array();

		// -----------------------------------------------------------------------------------------
		// Batch-load all notes for the events to avoid N+1 queries.
		// Notes are a global setting. With it off, skip the note queries entirely rather
		// than loading rows for a column that will not be rendered.
		$notes_enabled = Plugin_Settings::get_enable_notes();
		$event_ids     = array_column( $recordset, 'event_id' );
		$notes_map     = $notes_enabled ? self::batch_load_notes( $event_ids ) : array();

		// Batch-load properties and metadata for the whole page in one query each.
		// Up to 1.4.0 this loop called Event_Repository::load() per row, which ran four more
		// queries for every row displayed (event, properties, eventmeta, note) plus a
		// SHOW TABLES check. A 20-row page cost well over a hundred queries; it now costs about
		// five regardless of page length.
		$properties_map = Property_Repository::load_by_event_ids( $event_ids );
		$eventmetas_map = Eventmeta_Repository::load_by_event_ids( $event_ids );

		foreach ( $recordset as $record ) {
			// Construct the Event object from the row we already have.
			$event = Event_Repository::record_to_object( $record );

			$event->properties = $properties_map[ $event->id ] ?? null;
			$event->eventmetas = $eventmetas_map[ $event->id ] ?? null;
			$event->eventnotes = $notes_map[ $event->id ] ?? null;

			// Create a new data item.
			$item             = array();
			$item['event_id'] = $event->id;

			// Date and time of the event.
			$formatted_datetime    = DateTimes::format_datetime_site( $event->when_happened );
			$time_ago              = DateTimes::get_ago_string( $event->when_happened );
			$item['when_happened'] = '<div>' . wp_kses_post( $formatted_datetime ) . ' (' . esc_html( $time_ago ) . ')</div>';

			// User details.
			$user_tag             = User_Utility::get_tag( $event->user_id, $event->user_name );
			$user_role            = ucwords( $event->user_role ?? 'none' );
			$item['display_name'] = get_avatar( $event->user_id, 32 ) . ' <div class="logify-wp-user-info">' . wp_kses_post( $user_tag ) . '<br><span class="logify-wp-user-role">' . esc_html( $user_role ) . '</span></div>';

			// Source IP.
			$item['user_ip'] = wp_kses_post( User_Utility::format_event_ip( $event ) );

			// Event type.
			$item['event_type'] = '<span class="' . esc_attr( "logify-wp-event-type logify-wp-object-type-$event->object_type" ) . '">' . esc_html( $event->event_type ) . '</span>';

			// Get the HTML for the object name.
			$item['object_name'] = wp_kses_post( $event->get_object_tag() );

			// Include the object type.
			$item['object_type'] = $event->object_type;

			// Format the details.
			$item['details'] = self::format_details( $event );

			// Get the note for this event (from pre-loaded notes_map).
			$note = $notes_enabled && isset( $notes_map[ $record['event_id'] ] ) ? $notes_map[ $record['event_id'] ] : null;

			// Handle note content.
			if ( $note && ! empty( $note->note ) ) {
				// Strip HTML tags and escape for safe display.
				$note_text = wp_strip_all_tags( $note->note );
				$item['note_content'] = esc_html( $note_text );
			} else {
				$item['note_content'] = '';
			}

			// Handle edit link for notes.
			if ( $notes_enabled && Access_Control::can_manage_notes() ) {
				// Context for the note dialog. Someone who opens a note, gets interrupted and
				// comes back to it needs to know which event they were writing about, so the
				// event is described on the link and shown in the dialog.
				$note_event_summary = $event->event_type;

				if ( ! empty( $event->object_name ) ) {
					$note_event_summary .= ' — ' . $event->object_name;
				}

				$note_event_date = DateTimes::format_datetime_site( $event->when_happened );

				$note_data_attrs = ' data-event-id="' . intval( $event->id ) . '"'
					. ' data-event-summary="' . esc_attr( $note_event_summary ) . '"'
					. ' data-event-date="' . esc_attr( $note_event_date ) . '"';

				if ( $note && isset( $note->user_id ) && (int) $note->user_id === (int) get_current_user_id() ) {
					// Current user owns this note, allow edit.
					$item['edit_link'] = '<a href="#" class="edit-note-link" data-note-id="' . intval( $note->note_id ) . '"'
						. ' data-note-content="' . esc_attr( $note->note ) . '"'
						. $note_data_attrs . '>' . esc_html__( 'View/Edit Note', 'logify-wp' ) . '</a>';
				} else {
					// Either no note exists or current user doesn't own it, allow adding/creating.
					$item['edit_link'] = '<a href="#" class="edit-note-link"' . $note_data_attrs . '>'
						. esc_html__( 'Add Note', 'logify-wp' ) . '</a>';
				}
			} else {
				$item['edit_link'] = '';
			}

			// Add the item to the data array.
			$data[] = $item;
		}
		$draw = isset( $_POST['draw'] ) ? intval( wp_unslash( $_POST['draw'] ) ) : 0;

		wp_send_json(
			array(
				'draw'            => $draw,
				'recordsTotal'    => $num_total_records,
				'recordsFiltered' => $num_filtered_records,
				'data'            => $data,
			)
		);
	}

	/**
	 * Formats user details for a log entry.
	 *
	 * @param Event $event The event.
	 * @return string The formatted user details as an HTML table.
	 */
	public static function format_user_details( Event $event ): string {
		// Construct the HTML.
		$html  = "<div class='logify-wp-details-section logify-wp-user-details-section'>\n";
		$html .= '<h4>' . esc_html__( 'User Details', 'logify-wp' ) . "</h4>\n";
		$html .= "<div class='logify-wp-details-table-wrapper'>\n";
		$html .= "<table class='logify-wp-details-table logify-wp-user-details-table'>\n";

		// User tag.
		$user_tag = User_Utility::get_tag( $event->user_id, $event->user_name );
		$html    .= '<tr><th>' . esc_html__( 'User', 'logify-wp' ) . '</th>' . "<td>$user_tag</td></tr>\n";

		// Role.
		$user_role = esc_html( ucwords( $event->user_role ) );
		$html     .= '<tr><th>' . esc_html__( 'Role', 'logify-wp' ) . '</th>' . "<td>$user_role</td></tr>\n";

		// User ID.
		$html .= '<tr><th>' . esc_html__( 'ID', 'logify-wp' ) . '</th>' . '<td>' . esc_html( (string) $event->user_id ) . '</td></tr>';

		// IP address.
		$ip_link = User_Utility::format_event_ip( $event );
		$html   .= '<tr><th>' . esc_html__( 'Visitor IP', 'logify-wp' ) . '</th>' . "<td>$ip_link</td></tr>\n";
		$reported = User_Utility::get_reported_event_ip( $event );
		if ( $reported ) {
			$html .= '<tr><th>' . esc_html__( 'Connection IP (audited)', 'logify-wp' ) . '</th><td>' . esc_html( (string) $event->user_ip ) . "</td></tr>\n";
		}
		$ip_source = User_Utility::get_event_ip_attribution( $event )['source'] ?? null;
		if ( is_string( $ip_source ) ) {
			$html .= '<tr><th>' . esc_html__( 'IP source', 'logify-wp' ) . '</th><td>' . esc_html( $ip_source ) . "</td></tr>\n";
		}


		// User location.
		$user_location = empty( $event->user_location ) ? esc_html__( 'Unknown', 'logify-wp' ) : esc_html( $event->user_location );
		$html         .= '<tr><th>' . esc_html__( 'Location', 'logify-wp' ) . '</th>' . "<td>$user_location</td></tr>\n";

		// User agent.
		$user_agent = esc_html( User_Utility::describe_user_agent( $event->user_agent ) );
		$html      .= '<tr><th>' . esc_html__( 'User agent', 'logify-wp' ) . '</th>' . "<td>$user_agent</td></tr>\n";

		// If the user has been deleted, we won't have all their details. Although, we could
		// try looking up the log entry for when the user was deleted, and see if we can
		// get their details from there.

		// Get some extra details if the user has not been deleted.
		if ( User_Utility::exists( $event->user_id ) ) {
			// Load the user.
			$user = User_Utility::load( $event->user_id );

			// User email.
			$user_email = empty( $user->user_email ) ? esc_html__( 'Unknown', 'logify-wp' ) : esc_html( $user->user_email );
			$html      .= '<tr><th>' . esc_html__( 'Email', 'logify-wp' ) . '</th><td>' . User_Utility::get_email_link( $user_email ) . "</td></tr>\n";

			// Get the registration datetime.
			$registration_datetime        = DateTimes::create_datetime( $user->data->user_registered, 'UTC' );
			$registration_datetime_string = DateTimes::format_datetime_site( $registration_datetime );
			$html                        .= '<tr><th>' . esc_html__( 'Registered', 'logify-wp' ) . '</th>' . "<td>$registration_datetime_string</td></tr>\n";

			// Get the last login datetime.
			$last_login_datetime        = User_Utility::get_last_login_datetime( $event->user_id );
			$last_login_datetime_string = $last_login_datetime !== null ? DateTimes::format_datetime_site( $last_login_datetime ) : esc_html__( 'Unknown', 'logify-wp' );
			$html                      .= '<tr><th>' . esc_html__( 'Last login', 'logify-wp' ) . '</th>' . "<td>$last_login_datetime_string</td></tr>\n";

			// Get the last active datetime.
			$last_active_datetime        = User_Utility::get_last_active_datetime( $event->user_id );
			$last_active_datetime_string = $last_active_datetime !== null ? DateTimes::format_datetime_site( $last_active_datetime ) : esc_html__( 'Unknown', 'logify-wp' );
			$html                       .= '<tr><th>' . esc_html__( 'Last active', 'logify-wp' ) . '</th>' . "<td>$last_active_datetime_string</td></tr>\n";
		}

		$html .= "</table>\n";
		$html .= "</div>\n";
		$html .= "</div>\n";

		return $html;
	}

	/**
	 * Formats the eventmetas of a log entry.
	 *
	 * @param Event $event The event.
	 * @return string The formatted eventmetas as an HTML table.
	 */
	public static function format_eventmetas( Event $event ): string {
		// Handle the null case.
		if ( empty( $event->eventmetas ) ) {
			return '';
		}

		// IP attribution is rendered in User Details with verification labels.
		// Do not show the raw internal attribution array a second time here.
		$visible_metas = array_filter( $event->eventmetas, static function ( $eventmeta ) {
			return $eventmeta->meta_key !== 'logify_ip_attribution';
		} );
		if ( ! $visible_metas ) {
			return '';
		}

		// Convert eventmetas to a table of key-value pairs.
		$html  = "<div class='logify-wp-details-section logify-wp-eventmeta-section'>\n";
		$html .= '<h4>' . esc_html__( 'Event Details', 'logify-wp' ) . "</h4>\n";
		$html .= "<div class='logify-wp-details-table-wrapper'>\n";
		$html .= "<table class='logify-wp-details-table logify-wp-eventmeta-table'>\n";
		foreach ( $visible_metas as $eventmeta ) {
			// Meta keys are attacker-influenced (e.g. username_entered). Escape the
			// label; the value is escaped inside Types::value_to_html().
			$label = esc_html( Strings::key_to_label( $eventmeta->meta_key ) );
			$value = Types::value_to_html( $eventmeta->meta_key, $eventmeta->meta_value );
			$html .= "<tr><th>$label</th><td>$value</td></tr>";
		}
		$html .= "</table>\n";
		$html .= "</div>\n";
		$html .= "</div>\n";
		return $html;
	}

	/**
	 * Get the title for a post type event.
	 *
	 * @param Event $event The event object.
	 * @return string The post type title.
	 */
	public static function get_post_type_title( $event ) {
		// Get the post type. It might be in the properties.
		$post_type = $event->get_prop( 'post_type' )?->val;

		// If not, we can get it from the post.
		if ( ! $post_type ) {
			$post_type = $event->get_object()?->post_type;
		}

		// If we have a post type, get the singular name.
		if ( $post_type ) {
			return Post_Utility::get_post_type_singular_name( $post_type );
		} else {
			// Otherwise, default to 'Post'.
			return 'Post';
		}
	}

	/**
	 * Get the title for a term type event.
	 *
	 * @param Event $event The event object.
	 * @return string The term type title.
	 */
	public static function get_term_type_title( $event ) {
		// Get the taxonomy. It might be in the properties.
		$taxonomy = $event->get_prop( 'taxonomy' )?->val;

		// If not, we can get it from the term.
		if ( ! $taxonomy ) {
			$taxonomy = $event->get_object()?->taxonomy;
		}

		// If we have a taxonomy, get the singular name.
		if ( $taxonomy ) {
			return Taxonomy_Utility::get_singular_name( $taxonomy );
		} else {
			// Otherwise, default to 'Term'.
			return 'Term';
		}
	}

	/**
	 * Format object properties.
	 *
	 * @param Event $event The event.
	 * @return string The object properties formatted as an HTML table.
	 */
	public static function format_properties( Event $event ): string {
		// Handle the null case.
		if ( empty( $event->properties ) ) {
			return '';
		}

		// Check which columns to show.
		$show_new_vals = false;
		foreach ( $event->properties as $prop ) {
			// Check if we want to show the 'After' column.
			if ( $prop->new_val !== null ) {
				$show_new_vals = true;
				break;
			}
		}

		// Convert JSON string to a table showing the changes.
		$html = "<div class='logify-wp-details-section logify-wp-properties-section'>\n";

		// Get the title given the object type.
		$object_type_title = match ( $event->object_type ) {
			'user'   => 'Account',
			'option' => 'Setting',
			'post'   => self::get_post_type_title( $event ),
			'term'   => self::get_term_type_title( $event ),
			default  => Strings::key_to_label( $event->object_type, true ),
		};

		$html .= '<h4>' . esc_html( sprintf( /* translators: %s: object type, for example Post or User. */ __( '%s Details', 'logify-wp' ), $object_type_title ) ) . "</h4>\n";

		// Start scrollable wrapper.
		$html .= "<div class='logify-wp-details-table-wrapper'>\n";

		// Start table.
		$class = 'logify-wp-properties-table-' . ( $show_new_vals ? 3 : 2 ) . '-column';
		$html .= "<table class='logify-wp-details-table logify-wp-properties-table $class'>\n";

		// Header row is only needed if both old and new value columns are shown.
		if ( $show_new_vals ) {
			$html .= "<tr><th></th><th>Before</th><th>After</th></tr>\n";
		}

		// Property rows.
		foreach ( $event->properties as $prop ) {
			// Some info we don't care about. Maybe we shouldn't even store these.
			if ( $prop->key === 'session_tokens' || $prop->key === 'wp_user_level' ) {
				continue;
			}

			// Start row.
			$html .= '<tr>';

			// Property key. Keys are derived from logged option/meta names, so escape.
			$key   = esc_html( Strings::key_to_label( $prop->key ) );
			$html .= "<th>$key</th>";

			// Current or old value.
			$val   = Types::value_to_html( $prop->key, $prop->val );
			$html .= "<td>$val</td>";

			// New value.
			if ( $show_new_vals ) {
				$new_val = Types::value_to_html( $prop->key, $prop->new_val );

				// Get the CSS class to show if the value has been changed or not.
				$new_value_exists = $prop->new_val !== null;
				$class            = $new_value_exists ? 'logify-wp-value-changed' : 'logify-wp-value-unchanged';

				$html .= "<td class='$class'>$new_val</td>";
			}

			// End row.
			$html .= "</tr>\n";
		}

		// End table.
		$html .= "</table>\n";

		// End scrollable wrapper.
		$html .= "</div>\n";

		// End section.
		$html .= "</div>\n";

		return $html;
	}

	/**
	 * Formats the details of a log entry.
	 *
	 * @param Event $event The event.
	 * @return string The formatted details as HTML.
	 */
	public static function format_details( Event $event ): string {
		$html  = "<div class='logify-wp-details'>\n";
		$html .= self::format_user_details( $event );
		$html .= self::format_properties( $event );
		$html .= self::format_eventmetas( $event );
		$html .= "</div>\n";
		return $html;
	}
}
