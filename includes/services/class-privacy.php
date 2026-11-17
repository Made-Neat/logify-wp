<?php
/**
 * WordPress privacy tools for Logify WP.
 *
 * @package Logify_WP
 */
namespace Logify_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export and erase Logify records linked to an account or attempted login.
 * Note: free-text references to a person in unrelated content cannot be
 * reliably attributed to an email address by automated tools.
 */
class Privacy {
	private const PAGE_SIZE = 20;

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	public static function register_exporters( $exporters ) {
		$exporters['logify-wp'] = array(
			'exporter_friendly_name' => __( 'Logify WP activity log', 'logify-wp' ),
			'callback' => array( __CLASS__, 'export_user_data' ),
		);
		return $exporters;
	}

	public static function register_erasers( $erasers ) {
		$erasers['logify-wp'] = array(
			'eraser_friendly_name' => __( 'Logify WP activity log', 'logify-wp' ),
			'callback' => array( __CLASS__, 'erase_user_data' ),
		);
		return $erasers;
	}

	/**
	 * Query events by actor, event subject or failed-login credential. The latter
	 * matters even if the account has been deleted or never existed.
	 */
	private static function events_for( string $email, int $user_id, string $login, int $offset ): array {
		global $wpdb;
		$values = array( Event_Repository::get_table_name() );
		$clauses = array();
		if ( $user_id ) {
			$clauses[] = 'e.user_id = %d';
			$values[] = $user_id;
			$clauses[] = '(e.object_type = %s AND e.object_key = %s)';
			$values[] = 'user';
			$values[] = (string) $user_id;
		}
		$logins = array_unique( array_filter( array( $email, $login ) ) );
		foreach ( $logins as $entered ) {
			// Logify serializes event metadata before storing it.
			$clauses[] = 'EXISTS (SELECT 1 FROM %i em WHERE em.event_id = e.event_id AND em.meta_key = %s AND em.meta_value = %s)';
			$values[] = Eventmeta_Repository::get_table_name();
			$values[] = 'username_entered';
			$values[] = Serialization::serialize( $entered );
		}
		if ( ! $clauses ) {
			return array();
		}
		$values[] = self::PAGE_SIZE;
		$values[] = $offset;
		// Every clause is a static string defined in this method. User inputs,
		// identifiers, LIMIT and OFFSET are supplied as $values to prepare().
		$sql = 'SELECT e.* FROM %i e WHERE ' . implode( ' OR ', $clauses ) . ' ORDER BY e.event_id ASC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql consists only of fixed fragments; $values are bound through prepare().
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
	}

	/** Return notes written by a person, including standalone notes. */
	private static function notes_for( int $user_id, int $offset ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY note_id ASC LIMIT %d OFFSET %d',
				Note_Repository::get_table_name(), $user_id, self::PAGE_SIZE, $offset
			),
			ARRAY_A
		);
	}

	/** Load event properties or event metadata without an N+1 query. */
	private static function detail_rows( string $table, string $key_column, array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$placeholders = Database::in_placeholders( count( $ids ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders only contains generated %d placeholders; IDs are bound via prepare().
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE event_id IN ($placeholders)", array_merge( array( $table ), $ids ) ),
			ARRAY_A
		);
	}

	public static function export_user_data( $email_address, $page = 1 ) {
		$page = max( 1, (int) $page );
		$email = sanitize_email( (string) $email_address );
		$user = get_user_by( 'email', $email );
		$id = $user ? (int) $user->ID : 0;
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$events = self::events_for( $email, $id, $user ? (string) $user->user_login : '', $offset );
		$notes = self::notes_for( $id, $offset );
		$ids = array_map( static fn( $e ) => (int) $e['event_id'], $events );
		$properties = self::detail_rows( Property_Repository::get_table_name(), 'prop_key', $ids );
		$metas = self::detail_rows( Eventmeta_Repository::get_table_name(), 'meta_key', $ids );
		$data = array();

		foreach ( $events as $event ) {
			$eid = (int) $event['event_id'];
			$fields = array();
			foreach ( $event as $field => $value ) {
				if ( null !== $value && '' !== $value ) {
					$fields[] = array( 'name' => $field, 'value' => (string) $value );
				}
			}
			foreach ( $properties as $property ) {
				if ( (int) $property['event_id'] === $eid ) {
					$fields[] = array( 'name' => 'property: ' . $property['prop_key'], 'value' => (string) $property['val'] . ' -> ' . (string) $property['new_val'] );
				}
			}
			foreach ( $metas as $meta ) {
				if ( (int) $meta['event_id'] === $eid ) {
					$fields[] = array( 'name' => 'metadata: ' . $meta['meta_key'], 'value' => (string) $meta['meta_value'] );
				}
			}
			$data[] = array(
				'group_id' => 'logify-wp-events',
				'group_label' => __( 'Logify WP activities', 'logify-wp' ),
				'item_id' => 'logify-wp-event-' . $eid,
				'data' => $fields,
			);
		}
		foreach ( $notes as $note ) {
			$data[] = array(
				'group_id' => 'logify-wp-notes',
				'group_label' => __( 'Logify WP notes', 'logify-wp' ),
				'item_id' => 'logify-wp-note-' . (int) $note['note_id'],
				'data' => array(
					array( 'name' => __( 'Created', 'logify-wp' ), 'value' => $note['created_at'] ),
					array( 'name' => __( 'Note', 'logify-wp' ), 'value' => wp_strip_all_tags( $note['note'] ) ),
					array( 'name' => __( 'Author', 'logify-wp' ), 'value' => $note['user_name'] ),
					array( 'name' => __( 'IP address', 'logify-wp' ), 'value' => $note['ip_address'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $events ) < self::PAGE_SIZE && count( $notes ) < self::PAGE_SIZE );
	}

	/**
	 * Process the FIRST matching batch every time (no offset): erasing the actor
	 * and subject identifiers makes rows leave the next query's results.
	 */
	public static function erase_user_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( (string) $email_address );
		$user = get_user_by( 'email', $email );
		$id = $user ? (int) $user->ID : 0;
		$events = self::events_for( $email, $id, $user ? (string) $user->user_login : '', 0 );
		$notes = self::notes_for( $id, 0 );
		$response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		$failed = false;
		foreach ( $events as $event ) {
			$eid = (int) $event['event_id'];
			$is_subject = $id && 'user' === $event['object_type'] && (string) $event['object_key'] === (string) $id;
			$fields = array(
				'user_id' => (int) $event['user_id'] === $id ? 0 : (int) $event['user_id'],
				'user_name' => (int) $event['user_id'] === $id ? __( 'anonymised', 'logify-wp' ) : $event['user_name'],
				'user_role' => (int) $event['user_id'] === $id ? '' : $event['user_role'],
				'user_ip' => null,
				'user_location' => null,
				'user_agent' => null,
			);
			if ( $is_subject ) {
				$fields['object_name'] = __( 'anonymised', 'logify-wp' );
				$fields['object_key'] = null;
			}
			// Erase detail rows for matched records, not just visible event fields;
			// these may contain emails, profile values or entered usernames.
			$wpdb->query( 'START TRANSACTION' );
			$ok = $wpdb->delete( Property_Repository::get_table_name(), array( 'event_id' => $eid ), array( '%d' ) );
			if ( false !== $ok ) {
				$ok = $wpdb->delete( Eventmeta_Repository::get_table_name(), array( 'event_id' => $eid ), array( '%d' ) );
			}
			if ( false !== $ok ) {
				$ok = $wpdb->update( Event_Repository::get_table_name(), $fields, array( 'event_id' => $eid ) );
			}
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				$failed = true;
				break;
			}
			$wpdb->query( 'COMMIT' );
			$response['items_removed'] = true;
		}
		if ( ! $failed ) {
			foreach ( $notes as $note ) {
				$ok = $wpdb->update(
					Note_Repository::get_table_name(),
					array( 'user_id' => 0, 'user_name' => __( 'anonymised', 'logify-wp' ), 'user_role' => '', 'ip_address' => '', 'note' => __( '[Note text erased on privacy request]', 'logify-wp' ) ),
					array( 'note_id' => (int) $note['note_id'] )
				);
				if ( false === $ok ) {
					$failed = true;
					break;
				}
				$response['items_removed'] = true;
			}
		}
		// WordPress retries a page while done=false. On a persistent SQL error,
		// retrying offset zero loops forever; stop and report retained data.
		$response['done'] = $failed || ( count( $events ) < self::PAGE_SIZE && count( $notes ) < self::PAGE_SIZE );
		if ( $failed ) {
			$response['items_retained'] = true;
			$response['messages'][] = __( 'Some Logify WP records could not be anonymised due to a database error. Review the error and run a new erasure request after fixing it.', 'logify-wp' );
		} else {
			$response['messages'][] = __( 'Logify WP removed linked identifiers and detail rows while preserving event headers. Free-text references in other users\' notes or unrelated content may require manual review.', 'logify-wp' );
		}
		return $response;
	}
}
