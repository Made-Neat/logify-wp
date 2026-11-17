<?php
/**
 * Contains the Security_Tracker class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logify_WP\Security_Tracker
 *
 * Records the events that matter most when investigating an incident, and which the other
 * trackers either missed entirely or recorded in a form nobody could read.
 *
 * Added in 1.5.0 to close five gaps:
 *
 * 1. Role and capability changes. User_Tracker::on_profile_update() only compares the columns
 *    of the wp_users row, and roles are not there - they live in usermeta. A demotion was at
 *    best a serialized wp_capabilities blob in a meta diff, and a role set programmatically
 *    (WooCommerce assigning "customer", a membership plugin, WP-CLI) never fired
 *    profile_update at all, so it was not recorded in any form.
 * 2. Password resets, which had no hook of any kind.
 * 3. Email address changes, which were only visible as a property diff inside a generic
 *    "User Updated" event.
 * 4. Failed and automatic updates. Only successful core updates were recorded, so an update
 *    that failed - the thing you go looking for when a site breaks - left no trace. This also
 *    captures WordPress's automatic rollback of a plugin update that caused a fatal error.
 * 5. Edits made with the built-in plugin and theme file editors, which are among the highest
 *    risk actions available in the admin and left no trace at all.
 *
 * Everything here is a low-frequency event, so it is logged synchronously rather than being
 * queued through Action Scheduler.
 */
class Security_Tracker {

	/**
	 * Roles a user held before a change, keyed by user ID.
	 *
	 * @var array
	 */
	private static array $roles_before = array();

	/** Standalone add_role/remove_role events pending until shutdown. */
	private static array $pending_role_events = array();

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// --- 1. Role changes -------------------------------------------------------------
		// set_user_role fires for every role assignment however it was made, including ones
		// that never touch the profile screen.
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_remove_user_role' ), 10, 2 );
		// WP_User::set_role() fires remove/add before set_user_role. Defer those
		// events and discard them if a completed set_user_role is observed.
		add_action( 'shutdown', array( __CLASS__, 'flush_pending_role_events' ), 9 );

		// --- 2 and 3. Credentials --------------------------------------------------------
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update_credentials' ), 10, 2 );

		// --- 4. Failed, automatic and rolled-back updates --------------------------------
		add_action( 'automatic_updates_complete', array( __CLASS__, 'on_automatic_updates_complete' ), 10, 1 );
		add_filter( 'upgrader_install_package_result', array( __CLASS__, 'on_install_package_result' ), 10, 2 );

		// --- 5. File editor --------------------------------------------------------------
		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'on_edit_file' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'on_non_ajax_file_edit' ), 1 );
	}

	// -------------------------------------------------------------------------------------
	// Role changes.
	// -------------------------------------------------------------------------------------

	/**
	 * A user's role was replaced.
	 *
	 * @param int      $user_id   The user.
	 * @param string   $role      The new role.
	 * @param string[] $old_roles The roles the user held before.
	 * @return void
	 */
	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		$user_id = (int) $user_id;
		unset( self::$pending_role_events[ $user_id ] );
		$old_roles = is_array( $old_roles ) ? $old_roles : array();

		// WordPress fires this during registration too, where there is no previous role and
		// "User Registered" already covers it.
		if ( ! $old_roles ) {
			return;
		}

		$old_role = implode( ', ', $old_roles );

		if ( $old_role === $role ) {
			return;
		}

		// Say plainly whether this was a promotion or a demotion. "Role Changed" is accurate
		// but useless when you are scanning a log for how somebody lost access.
		$event_type = self::is_demotion( $old_roles, (string) $role )
			? 'User Role Downgraded'
			: 'User Role Changed';

		$props = array();
		Property::update_array( $props, 'role', null, $old_role, $role );

		self::log_user_event( $event_type, $user_id, $props );
	}

	/**
	 * An additional role was granted to a user.
	 *
	 * @param int    $user_id The user.
	 * @param string $role    The role added.
	 * @return void
	 */
	public static function on_add_user_role( $user_id, $role ) {
		$props = array();
		Property::update_array( $props, 'role_added', null, null, $role );

		self::$pending_role_events[ (int) $user_id ][] = array( 'User Role Added', $props );
	}

	/**
	 * A role was taken away from a user.
	 *
	 * @param int    $user_id The user.
	 * @param string $role    The role removed.
	 * @return void
	 */
	public static function on_remove_user_role( $user_id, $role ) {
		$props = array();
		Property::update_array( $props, 'role_removed', null, $role, null );

		self::$pending_role_events[ (int) $user_id ][] = array( 'User Role Removed', $props );
	}

	/** Write individual add_role/remove_role events only if no set_role superseded them. */
	public static function flush_pending_role_events(): void {
		foreach ( self::$pending_role_events as $user_id => $events ) {
			foreach ( $events as $item ) {
				self::log_user_event( $item[0], (int) $user_id, $item[1] );
			}
		}
		self::$pending_role_events = array();
	}

	/**
	 * Whether a role change reduced the user's capabilities.
	 *
	 * Compares how many capabilities each role grants. It is a rough measure, but it reliably
	 * distinguishes administrator-to-subscriber from subscriber-to-editor, which is the
	 * distinction that matters when reading a log.
	 *
	 * @param string[] $old_roles The previous roles.
	 * @param string   $new_role  The new role.
	 * @return bool True if the new role grants fewer capabilities.
	 */
	private static function is_demotion( array $old_roles, string $new_role ): bool {
		$roles = wp_roles();

		$count = static function ( $role_name ) use ( $roles ) {
			$role = $roles->get_role( $role_name );

			return $role ? count( array_filter( (array) $role->capabilities ) ) : 0;
		};

		$old_max = 0;

		foreach ( $old_roles as $old_role ) {
			$old_max = max( $old_max, $count( $old_role ) );
		}

		return $count( $new_role ) < $old_max;
	}


	/**
	 * Persist an account-security event about the affected user.
	 *
	 * Use the current authenticated user as the actor. The affected user is
	 * represented separately, so an administrator changing somebody else's role
	 * does not get mislabeled as the subject of that change.
	 */
	private static function log_user_event( string $event_type, int $subject_id, array $properties ): void {
		if ( $subject_id < 1 ) {
			return;
		}
		try {
			Logger::log_event(
				$event_type,
				new Object_Reference( 'user', $subject_id ),
				null,
				$properties,
				get_current_user_id() ?: null,
				false,
				User_Utility::get_ip_context_token()
			);
		} catch ( \Throwable $error ) {
			// An audit failure must never prevent WordPress changing a user account.
			Debug::warning( 'Logify WP could not record a user security event', $event_type, $error->getMessage() );
		}
	}

	// -------------------------------------------------------------------------------------
	// Credentials.
	// -------------------------------------------------------------------------------------

	/**
	 * A password was reset through the lost-password flow.
	 *
	 * @param mixed  $user         The user object.
	 * @param string $new_password The new password. Never recorded.
	 * @return void
	 */
	public static function on_password_reset( $user, $new_password ) {
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}

		// The password itself is deliberately not passed to the event in any form.
		self::log_user_event( 'User Password Reset', (int) $user->ID, array() );
	}

	/**
	 * A profile was updated: record an email or password change as its own event.
	 *
	 * These were previously only visible as property diffs inside a generic "User Updated"
	 * event, which is not something you would find by filtering the log.
	 *
	 * @param int   $user_id       The user.
	 * @param mixed $old_user_data The user's data before the update.
	 * @return void
	 */
	public static function on_profile_update_credentials( $user_id, $old_user_data ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user || ! is_object( $old_user_data ) ) {
			return;
		}

		// Email address.
		$old_email = $old_user_data->user_email ?? '';

		if ( $old_email && $old_email !== $user->user_email ) {
			$props = array();
			Property::update_array( $props, 'user_email', null, $old_email, $user->user_email );

			self::log_user_event( 'User Email Changed', $user_id, $props );
		}

		// Password. Only the fact of the change is recorded - never the hash, and never the
		// password. A changed hash is enough to establish that it happened and when.
		$old_pass = $old_user_data->user_pass ?? '';

		if ( $old_pass && $old_pass !== $user->user_pass ) {
			self::log_user_event( 'User Password Changed', $user_id, array() );
		}
	}

	// -------------------------------------------------------------------------------------
	// Updates.
	// -------------------------------------------------------------------------------------

	/**
	 * WordPress finished a run of automatic background updates.
	 *
	 * @param array $results Results keyed by type: core, plugin, theme, translation.
	 * @return void
	 */
	public static function on_automatic_updates_complete( $results ) {
		if ( ! is_array( $results ) ) {
			return;
		}

		foreach ( $results as $type => $items ) {
			foreach ( (array) $items as $item ) {
				$failed = isset( $item->result ) && ( is_wp_error( $item->result ) || ! $item->result );
				$name   = self::describe_update_item( $item );

				$props = array();
				Property::update_array( $props, 'update_type', null, null, (string) $type );

				if ( $name !== '' ) {
					Property::update_array( $props, 'item', null, null, $name );
				}

				if ( $failed ) {
					$message = is_wp_error( $item->result )
						? $item->result->get_error_message()
						: __( 'The update did not complete.', 'logify-wp' );

					Property::update_array( $props, 'error', null, null, $message );
				}

				$event_type = $failed ? 'Automatic Update Failed' : 'Automatic Update Completed';

				// Attach an object of the right type. Without one the event has a null
				// object_type, which means it would vanish the moment somebody narrows the
				// Object type filter - exactly when they are hunting for it.
				$object_ref = self::object_reference_for( (string) $type, $name );

				// all_users: an automatic update runs with no logged-in user.
				Logger::log_event( $event_type, $object_ref, null, $props, null, true );
			}
		}
	}

	/**
	 * The result of installing an update package.
	 *
	 * This is a filter, so the value is returned untouched. It is the only point at which a
	 * failed manual update is visible - upgrader_process_complete does not fire when an update
	 * fails - and it is also where WordPress's rollback feature shows up: when an update is
	 * rolled back, the package result is an error and $hook_extra carries the temp_backup
	 * details of what was restored.
	 *
	 * @param mixed $result     The install result, or a WP_Error.
	 * @param array $hook_extra Context about what was being updated.
	 * @return mixed The unmodified result.
	 */
	public static function on_install_package_result( $result, $hook_extra ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();

		// Some errors here are a normal step, not a failure. Uploading a zip to replace a
		// plugin or theme that is already installed always produces folder_exists first:
		// WordPress uses it to decide to show the "Replace current with uploaded" screen, then
		// carries on. Recording that as a failed update is both wrong and alarming.
		$benign = array( 'folder_exists', 'up_to_date', 'no_package', 'plugin_already_installed' );

		/**
		 * Filters the install error codes that are not treated as a failed update.
		 *
		 * @param string[] $benign Error codes to ignore.
		 */
		$benign = (array) apply_filters( 'logify_wp_benign_install_errors', $benign );

		if ( in_array( $result->get_error_code(), $benign, true ) ) {
			return $result;
		}

		$item = '';

		// hook_extra['type'] is what WordPress sets for an upload, and is the most reliable
		// signal of what is being installed. Falling through to "core" when nothing matched -
		// as an earlier version did - reported a plugin upload as a failed core update, which
		// is a genuinely worrying thing to read in a log and was entirely wrong.
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$item = (string) $hook_extra['plugin'];
			$type = 'plugin';
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$item = (string) $hook_extra['theme'];
			$type = 'theme';
		} elseif ( ! empty( $hook_extra['type'] ) ) {
			$type = (string) $hook_extra['type'];
		} else {
			// Unknown is honest. It is not core unless WordPress said so.
			$type = 'unknown';
		}

		// A temp_backup entry means WordPress had taken a copy it can restore, so a failure
		// here is a rollback rather than a plain failed update.
		$rolled_back = ! empty( $hook_extra['temp_backup'] );

		$props = array();
		Property::update_array( $props, 'update_type', null, null, $type );

		if ( $item !== '' ) {
			Property::update_array( $props, 'item', null, null, $item );
		}

		Property::update_array( $props, 'error', null, null, $result->get_error_message() );

		$event_type = $rolled_back ? 'Update Rolled Back' : 'Update Failed';

		try {
			Logger::log_event( $event_type, self::object_reference_for( $type, $item ), null, $props, null, true );
		} catch ( \Throwable $e ) {
			// Never let logging interfere with the upgrade itself.
			Debug::error( 'Logify WP: could not log a failed update', $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Build an Object_Reference for an update of a given type.
	 *
	 * @param string $type The update type: core, plugin, theme or translation.
	 * @param string $item The item identifier, if known.
	 * @return ?Object_Reference The reference, or null if the type has no object type.
	 */
	private static function object_reference_for( string $type, string $item ): ?Object_Reference {
		switch ( $type ) {
			case 'plugin':
				return $item !== '' ? new Object_Reference( 'plugin', $item ) : null;

			case 'theme':
				return $item !== '' ? new Object_Reference( 'theme', $item ) : null;

			case 'core':
				return new Object_Reference( 'core', $item !== '' ? $item : get_bloginfo( 'version' ) );
		}

		// Translations, unknown types and anything else get no object rather than a
		// misleading one.
		return null;
	}

	/**
	 * A readable name for an item in an automatic update result.
	 *
	 * @param mixed $item The result item.
	 * @return string
	 */
	private static function describe_update_item( $item ): string {
		if ( ! is_object( $item ) || empty( $item->item ) ) {
			return '';
		}

		$data = $item->item;

		foreach ( array( 'plugin', 'theme', 'slug', 'version' ) as $key ) {
			if ( ! empty( $data->$key ) ) {
				return (string) $data->$key;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------------------
	// File editor.
	// -------------------------------------------------------------------------------------

	/** Check the legacy non-AJAX editor before WordPress handles the POST. */
	public static function on_non_ajax_file_edit(): void {
		global $pagenow;
		if ( in_array( $pagenow, array( 'plugin-editor.php', 'theme-editor.php' ), true )
			&& 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::on_edit_file();
		}
	}

	/**
	 * Before a file-editor request, capture only the hash of a validated existing file.
	 * At shutdown compare against the on-disk result: invalid requests and reverted
	 * PHP edits must never be labelled as successful file changes.
	 */
	public static function on_edit_file(): void {
		if ( ! isset( $_POST['file'], $_POST['nonce'] ) ) {
			return;
		}
		$file = sanitize_text_field( wp_unslash( $_POST['file'] ) );
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		if ( ! $file || validate_file( $file ) !== 0 ) {
			return;
		}
		if ( $plugin !== '' && current_user_can( 'edit_plugins' ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'edit-plugin_' . $file ) ) {
				return;
			}
			$root = realpath( WP_PLUGIN_DIR );
			$event_type = 'Plugin File Edited';
			$object = new Object_Reference( 'plugin', $plugin );
		} elseif ( $theme !== '' && current_user_can( 'edit_themes' ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'edit-theme_' . $theme . '_' . $file ) ) {
				return;
			}
			$theme_object = wp_get_theme( $theme );
			if ( ! $theme_object->exists() ) {
				return;
			}
			$root = realpath( $theme_object->get_stylesheet_directory() );
			$event_type = 'Theme File Edited';
			$object = new Object_Reference( 'theme', $theme );
		} else {
			return;
		}
		if ( ! $root ) {
			return;
		}
		// A plugin's file path includes its directory. A theme path is relative
		// to the stylesheet directory. Never follow a path outside that root.
		$path = realpath( $root . '/' . $file );
		if ( ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return;
		}
		$before = hash_file( 'sha256', $path );
		if ( ! $before ) {
			return;
		}
		add_action( 'shutdown', static function () use ( $path, $before, $event_type, $object, $file ): void {
			if ( ! is_file( $path ) || ! is_readable( $path ) || hash_file( 'sha256', $path ) === $before ) {
				return;
			}
			try {
				$props = array();
				Property::update_array( $props, 'file', null, null, $file );
				Logger::log_event( $event_type, $object, null, $props, all_users: true );
			} catch ( \Throwable $e ) {
				Debug::error( 'Logify WP: could not log a file change', $e->getMessage() );
			}
		}, 0 );
	}
}
