<?php
/**
 * Contains the Media_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

use WP_Post;

/**
 * Class Logify_WP\Media_Tracker
 *
 * Provides tracking of events related to media.
 */
class Media_Tracker {

	/**
	 * Event for creating or updating a media object.
	 *
	 * @var ?Event
	 */
	private static ?Event $update_media_event = null;

	/**
	 * The media type of the object being created or updated.
	 *
	 * @var ?string
	 */
	private static ?string $media_type = null;

	/**
	 * Whether the event being developed is for creating a media object, or updating it.
	 *
	 * @var bool
	 */
	private static bool $creating = false;

	/**
	 * Whether we are re-using a previous "Attachment Updated" event.
	 *
	 * @var bool
	 */
	private static bool $reusing = false;

	/**
	 * Cached post ID for the current update_media_event.
	 *
	 * @var ?int
	 */
	private static ?int $update_media_post_id = null;

	/**
	 * Set up hooks for the events we want to log.
	 */
	public static function init() {
		// Add or update media.
		add_action( 'add_attachment', array(__NAMESPACE__.'\\Async_Tracker','async_add_attachment'), 10, 1 );
		add_action( 'middle_add_attachment', array( __CLASS__, 'on_add_attachment' ), 10, 3 );
		
		add_action( 'add_post_meta', array(__NAMESPACE__.'\Async_Tracker','async_add_post_meta'), 10, 3 );
		add_action( 'middle_add_post_meta', array( __CLASS__, 'on_add_post_meta' ), 10, 5 );
		
		add_action( 'update_post_meta', array(__NAMESPACE__.'\Async_Tracker','async_update_post_meta'), 10, 4 );
		add_action( 'middle_update_post_meta', array( __CLASS__, 'on_update_post_meta' ), 10, 6 );
		
		add_action( 'attachment_updated', array(__NAMESPACE__.'\\Async_Tracker','async_attachment_updated'), 10, 3 );
		add_action( 'middle_attachment_updated', array( __CLASS__, 'on_attachment_updated' ), 10, 5 );
		
		// Delete media.
		add_action( 'delete_attachment', array(__NAMESPACE__.'\\Async_Tracker','async_delete_attachment'), 10, 2 );
		add_action( 'middle_delete_attachment', array( __CLASS__, 'on_delete_attachment' ), 10, 4 );
		
		// Flush pending media event changes at the end of the current request.
		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), 10, 0 );
	}

	/**
	 * Get the update or create media event. If it hasn't been created yet, do it now.
	 *
	 * @param int|WP_Post $post        The attachment post the event is about.
	 * @param int         $acting_user Acting user ID.
	 * @param string|null $client_ip   Client IP address.
	 * @return ?Event The event, if it could be found or created.
	 */
	private static function get_update_media_event( int|WP_Post $post, int $acting_user = 0, ?string $client_ip = null ): ?Event {

		// Normalize to post_id and WP_Post.
		if ( is_int( $post ) ) {
			$post_id = $post;
			$post    = Post_Utility::load( $post_id );
		} else {
			$post_id = (int) $post->ID;
		}

		// If we could not load a valid post, bail.
		if ( ! $post instanceof WP_Post ) {
			Debug::warning( __METHOD__ . ' could not load WP_Post for ID ' . $post_id );
			return null;
		}

		// If we already have an event for this SAME post in this request, reuse it.
		if ( self::$update_media_event && self::$update_media_post_id === $post_id ) {
			return self::$update_media_event;
		}

		// Resolve media type.
		self::$media_type = Media_Utility::get_media_type( $post_id );

		if ( ! self::$media_type ) {
			Debug::warning( __METHOD__ . ' no media_type for post ' . $post_id );
			return null;
		}

		$event_type = ucfirst( self::$media_type ) . ' Updated';

		// Look at the most recent event in the log to decide reuse.
		$event = Event_Repository::get_most_recent_event();

		self::$reusing = $event
			&& $event->event_type  === $event_type
			&& $event->object_type === 'post'
			&& (int) $event->object_key === $post_id
			&& $event->when_happened->getTimestamp() > time() - 300;

		if ( ! self::$reusing ) {
			// Create a fresh event.
			$event = Event::create(
				$event_type,
				$post,
				null,
				null,
				$acting_user ?: null,
				false,
				$client_ip
			);

			if ( ! $event ) {
				Debug::warning( __METHOD__ . ' Event::create failed for post ' . $post_id );
				return null;
			}
		}

		self::$update_media_event   = $event;
		self::$update_media_post_id = $post_id;

		return $event;
	}


	/**
	 * Fires once an attachment has been added.
	 *
	 * NOTE: Not sure if this is actually being triggered. It may depend on how the attachment is added.
	 *
	 * @param int         $post_id      Attachment ID.
	 * @param int         $acting_user  Acting user ID.
	 * @param string|null $client_ip    Client IP address.
	 */
	public static function on_add_attachment( int $post_id, int $acting_user = 0, ?string $client_ip = null ) {
		Debug::info( __CLASS__, __FUNCTION__ );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_add_attachment called for post_id: ' . $post_id );
		}

		// Get the event.
		$event = self::get_update_media_event( $post_id, $acting_user, $client_ip );
		if ( ! $event ) {
			return;
		}

		// Update the event type. If this method is called, we're creating.
		$event->event_type = ucfirst( self::$media_type ) . ' Added';

		// Note that we're adding an attachment, rather than updating one.
		self::$creating = true;

		// It's possible some property changes have already been added to the event, for example 'filepath'.
		// We don't want these to be considered changes, as they're just the initial values.
		foreach ( $event->properties as $prop ) {
			if ( ! $prop->val && $prop->new_val ) {
				$prop->val     = $prop->new_val;
				$prop->new_val = null;
			}
		}
	}

	/**
	 * Fires immediately before a post meta is added.
	 *
	 * @param int    $post_id    ID of the post the metadata is for.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 */
	public static function on_add_post_meta( int $post_id, string $meta_key, mixed $meta_value, ?int $acting_user_id = null, ?string $client_ip = null ) {
		Debug::info( __CLASS__, __FUNCTION__ );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_add_post_meta called for post_id: ' . $post_id . ' | meta_key: ' . $meta_key . ' | meta_value: ' . var_export($meta_value, true) );
		}

		return self::on_update_post_meta( 0, $post_id, $meta_key, $meta_value, $acting_user_id, $client_ip );
	}

	/**
	 * Fires immediately before a post meta is updated.
	 *
	 * @param int    $meta_id    The ID of the meta data.
	 * @param int    $post_id    The ID of the post.
	 * @param string $meta_key   The key of the meta data.
	 * @param mixed  $meta_value The new value of the meta data.
	 */
	public static function on_update_post_meta( int $meta_id, int $post_id, string $meta_key, mixed $meta_value, ?int $acting_user_id = null, ?string $client_ip = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_update_post_meta called for meta_id: ' . $meta_id . ' | post_id: ' . $post_id . ' | meta_key: ' . $meta_key . ' | meta_value: ' . var_export($meta_value, true) );
		}
		global $wpdb;

		// Some changes are uninteresting.
		if ( in_array( $meta_key, array( '_edit_lock', '_edit_last' ) ) ) {
			return;
		}

		// Get the media type.
		$media_type = Media_Utility::get_media_type( $post_id );

		// This method is only for media.
		if ( ! $media_type ) {
			return;
		}

		Debug::info( __CLASS__, __FUNCTION__, $media_type );

		// Get the event.
		$event = self::get_update_media_event( $post_id, (int) ($acting_user_id ?? 0), $client_ip );
		if ( ! $event ) {
			return;
		}

		// Process the new value.
		$new_val = Types::process_database_value( $meta_key, $meta_value );

		if ( self::$creating ) {
			// If we're adding a new attachment, add the new value as the current value.
			$event->set_prop( $meta_key, $wpdb->postmeta, $new_val );
		} else {
			// Get the old or current value.
			$prop = $event->get_prop( $meta_key );
			if ( $prop ) {
				$val = $prop->val;
			} else {
				$meta_val = get_post_meta( $post_id, $meta_key, true );
				$val      = Types::process_database_value( $meta_key, $meta_val );
			}

			if ( ! Types::are_equal( $val, $new_val ) ) {
				// If there is a change, log the changed value.
				if ( $prop ) {
					$prop->new_val = $new_val;
				} else {
					$event->set_prop( $meta_key, $wpdb->postmeta, $val, $new_val );
				}
			} elseif ( $prop ) {
				// If there's now no change, remove the property from the event, if there is one.
				$event->remove_prop( $meta_key );
			}
		}
	}

	/**
	 * Fires once an existing attachment has been updated.
	 *
	 * @param int         $post_id             Post ID.
	 * @param mixed       $serialize_post_after Post object following the update.
	 * @param mixed       $serialize_post_before Post object before the update.
	 * @param int         $acting_user         Acting user ID.
	 * @param string|null $client_ip           Client IP address.
	 */
	public static function on_attachment_updated( int $post_id, $serialize_post_after, $serialize_post_before, int $acting_user = 0, ?string $client_ip = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_attachment_updated called for post_id: ' . $post_id );
		}

		//unserialize post objects safely
		if (is_string($serialize_post_after)) {
			$post_after = null;
			Serialization::try_unserialize($serialize_post_after, $post_after);
		} elseif (is_array($serialize_post_after)) {
			$post_after = (object) $serialize_post_after;
		} else {
			$post_after = $serialize_post_after;
		}
		if (is_string($serialize_post_before)) {
			$post_before = null;
			Serialization::try_unserialize($serialize_post_before, $post_before);
		} elseif (is_array($serialize_post_before)) {
			$post_before = (object) $serialize_post_before;
		} else {
			$post_before = $serialize_post_before;
		}
		if (!is_object($post_after) || !is_object($post_before)) {
			Debug::warning('logify-debug: on_attachment_updated got unexpected types: post_after=' . gettype($post_after) . ' post_before=' . gettype($post_before));
		}
		
		// Get the media type.

		$media_type = Media_Utility::get_media_type( $post_id );

		// This method is only for media.
		if ( ! $media_type ) {
			return;
		}

		Debug::info( __CLASS__, __FUNCTION__, $media_type );

		// Get the changes.
		// This will find both core and meta property changes, but no meta changes should be found
		// since they are handled separately in on_add_post_meta() and on_update_post_meta().
		$changed_props = Post_Utility::get_changes( $post_before, $post_after );

		// If there are no changes, we're done.
		if ( ! $changed_props ) {
			Debug::info( 'No changed properties found.' );
			return;
		}

		// Get the event.
		$event = self::get_update_media_event( $post_id, $acting_user, $client_ip );
		if ( ! $event ) {
			return;
		}

		if ( ! self::$reusing ) {
			// Add the changed properties.
			$event->add_props( $changed_props );
			return;
		}

		// The event already existede, so update the properties with the new changes.
		foreach ( $changed_props as $changed_prop ) {
			$key = $changed_prop->key;

			// Get the current/old value.
			// If we are re-using an event and there was already a property for this key, use
			// that value. Otherwise, use the changed value from this event.
			$existing_prop = self::$update_media_event->get_prop( $key );
			$val           = $existing_prop ? $existing_prop->val : $changed_prop->val;

			// Get the new value.
			$new_val = $changed_prop->new_val;

			// Check if there's a difference between the original value and the new one.
			if ( ! Types::are_equal( $val, $new_val ) ) {
				// They are different, so update/add the property.
				$event->set_prop( $key, $changed_prop->table_name, $val, $new_val );
			} else {
				// There is no difference now, so remove the property, if it's not a core property.
				if ( ! Post_Utility::is_core_property( $key ) ) {
					$event->remove_prop( $key );
				} else {
					$event->set_prop_new_val( $key, null );
				}
			}
		}
	}

	// =============================================================================================
	// Media deletion.

	/**
	 * Log the deletion of an attachment.
	 * This hook is triggered at the start of the deletion process.
	 *
	 * @param int         $post_id      The ID of the post to be deleted.
	 * @param mixed       $serialize_post The post object to be deleted.
	 * @param int         $acting_user  Acting user ID.
	 * @param string|null $client_ip    Client IP address.
	 */
	public static function on_delete_attachment( int $post_id, $serialize_post, int $acting_user = 0, ?string $client_ip = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_delete_attachment called for post_id: ' . $post_id );
		}
		// This method is only for attachments.
		if (is_string($serialize_post)) {
			$post = null;
			Serialization::try_unserialize($serialize_post, $post);
		} elseif (is_array($serialize_post)) {
			$post = (object) $serialize_post;
		} else {
			$post = $serialize_post;
		}
		if (!is_object($post)) {
			Debug::warning('logify-debug: on_delete_attachment expected object, got ' . gettype($post));
		}

		$post_type = null;
		if ( is_object( $post ) && isset( $post->post_type ) ) {
			$post_type = $post->post_type;
		} elseif ( is_array( $post ) && isset( $post['post_type'] ) ) {
			$post_type = $post['post_type'];
		} else {
			$post_type = get_post_type( $post_id );
		}

		if ( $post_type !== 'attachment' ) {
			return;
		}

		// Get the event type.
		$event_type = 'Media Deleted';

		// Create the event with acting user and client IP.
		$event = Event::create( $event_type, $post, null, null, $acting_user ?: null, false, $client_ip );

		// If the event could not be created, we aren't tracking this user.
		if ( ! $event ) {
			return;
		}

		// Add all the object's properties (including metadata), in case we want to restore it later.
		// NOTE: This will probably have to change when we update the data model to distinguish
		// NOTE: between properties we want to show for user information, vs. those we want to keep
		// NOTE: for undo actions.
		$event->add_props( Post_Utility::get_properties( $post ) );

		// Save the event to the log.
		$event->save();
	}

	// =============================================================================================
	// Shutdown.

	/**
	 * Fires on shutdown, after PHP execution.
	 */
	public static function on_shutdown() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[Logify WP] Media_Tracker::on_shutdown called. Event exists: ' . ( self::$update_media_event ? 'YES' : 'NO' ) . ' | Creating: ' . ( self::$creating ? 'YES' : 'NO' ) );
		}
		// Save the media updated or added event, if it exists.
		if ( self::$update_media_event ) {
			if ( self::$creating || self::$update_media_event->has_changes() ) {
				// If adding a new media object, or the event includes changes, save the event.
				self::$update_media_event->save();
			} elseif ( ! self::$update_media_event->is_new() && ! self::$update_media_event->has_changes() ) {
				// If this is an existing event, but there are no changes anymore, delete the event.
				self::$update_media_event->delete();
			}
		}
	}
}
