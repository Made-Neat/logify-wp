<?php
/**
 * Contains the Admin class.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Admin_Bar;

/**
 * Class Logify_WP\Admin
 *
 * This class handles the Logify WP admin functionality.
 */
class Admin {

	/**
	 * Initializes the Logify WP admin functionality.
	 *
	 * This method adds various actions and filters to set up the Logify WP plugin
	 * in the WordPress admin area.
	 *
	 * admin area.
	 *
	 * It registers the admin menu, settings, dashboard widget, assets, screen option,
	 * AJAX endpoint, access restriction, and log reset functionality.
	 *
	 * @return void
	 */
	public static function init() {
		// Add the admin menu.
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 10, 0 );

		// Add the Logify WP menu to the Admin bar.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_menu' ), 100, 1 );

		// Enqueue the necessary assets for the Logify WP admin pages.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 10, 1 );

		// Add screen options for the Logify WP plugin.
		add_filter( 'set-screen-option', array( __CLASS__, 'set_screen_option' ), 10, 3 );

		// Add the log reset functionality.
		add_action( 'admin_post_logify_wp_reset_logs', array( __CLASS__, 'reset_logs' ), 10, 0 );

	}

	/**
	 * Adds the admin menu for the Logify WP plugin.
	 *
	 * This method adds the main menu page and submenu pages for the Logify WP plugin
	 * in the WordPress admin dashboard.
	 */
	public static function add_admin_menu() {
		// Check if the user has access to the Logify WP log page.
		if ( ! Access_Control::can_access_log_page() ) {
			return;
		}

		// Add the main menu page and submenus.
		$hook = add_menu_page( __( 'Logify WP', 'logify-wp' ), __( 'Logify WP', 'logify-wp' ), 'read', 'logify-wp', array( 'Logify_WP\Log_Page', 'display_log_page' ), 'dashicons-list-view' );

		// Log page submenu.
		add_submenu_page( 'logify-wp', __( 'View Log', 'logify-wp' ), __( 'View Log', 'logify-wp' ), 'read', 'logify-wp', array( 'Logify_WP\Log_Page', 'display_log_page' ) );

		// Check if the notes feature is enabled
		if (Plugin_Settings::get_enable_notes()) {
			// Log page submenu.
			$noteshook = add_submenu_page( 'logify-wp', __( 'View Notes', 'logify-wp' ), __( 'View Notes', 'logify-wp' ), 'read', 'logify-wp-notes', array( 'Logify_WP\Notes_Page', 'display_notes_page' ) );
			add_action( "load-$noteshook", array( __CLASS__, 'add_screen_options' ) );
		}

		// Settings submenu (only for users with 'manage_options' capability).

		// Settings submenu (only for users with 'manage_options' capability).
		add_submenu_page( 'logify-wp', __( 'Settings', 'logify-wp' ), __( 'Settings', 'logify-wp' ), 'manage_options', 'logify-wp-settings', array( 'Logify_WP\Plugin_Settings', 'display_settings_page' ) );

		// Add screen options for the log page.
		add_action( "load-$hook", array( __CLASS__, 'add_screen_options' ) );
	}

	/**
	 * Adds Logify WP menu to the Admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The Admin bar object.
	 */
	public static function add_admin_bar_menu( WP_Admin_Bar $wp_admin_bar ) {
		// Don't show if the they don't want to see it in the admin bar, or if they don't have access.
		// If they don't have access to the log page, they also don't have access to the settings page.
		// (If they did, they could give themselves access to the log page!)
		if ( ! Plugin_Settings::get_show_in_admin_bar() || ! Access_Control::can_access_log_page() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'logify-wp',
				'title' => 'Logify WP',
				'href'  => admin_url( 'admin.php?page=logify-wp' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'logify-wp-view-log',
				'parent' => 'logify-wp',
				'title'  => __( 'View Log', 'logify-wp' ),
				'href'   => admin_url( 'admin.php?page=logify-wp' ),
			)
		);

		// Check if the notes feature is enabled
		if (Plugin_Settings::get_enable_notes()) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'logify-wp-notes',
					'parent' => 'logify-wp',
					'title'  => 'View Notes',
					'href'   => admin_url('admin.php?page=logify-wp-notes'),
				)
			);
		}

		// If the user has the manage_options capability, show the settings link.
		if ( current_user_can( 'manage_options' ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'logify-wp-settings',
					'parent' => 'logify-wp',
					'title'  => 'Settings',
					'href'   => admin_url( 'admin.php?page=logify-wp-settings' ),
				)
			);
		}
	}

	/**
	 * Adds screen options for the Logify WP plugin.
	 *
	 * This function adds a screen option for the number of activities to display per page in the
	 * Logify WP plugin.
	 *
	 * @return void
	 */
	public static function add_screen_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Log entries per page', 'logify-wp' ),
			'default' => 20,
			'option'  => 'logify_wp_events_per_page',
		);
		add_screen_option( $option, $args );

		// Column visibility belongs in Screen Options, which is where WordPress users look
		// for it. It used to be a bespoke "Show/Hide Columns" strip injected above the table.
		add_filter( 'screen_settings', array( __CLASS__, 'render_column_screen_options' ), 10, 2 );
	}

	/**
	 * Add the column checkboxes to the Screen Options panel.
	 *
	 * @param string $settings The existing screen settings markup.
	 * @param mixed  $screen   The current screen.
	 * @return string The settings markup.
	 */
	public static function render_column_screen_options( $settings, $screen ) {
		if ( ! is_object( $screen ) || strpos( (string) $screen->id, 'logify-wp' ) === false ) {
			return $settings;
		}

		// Only the events log has these columns.
		if ( strpos( (string) $screen->id, 'logify-wp-notes' ) !== false
			|| strpos( (string) $screen->id, 'logify-wp-settings' ) !== false ) {
			return $settings;
		}

		$user_id = get_current_user_id();

		$columns = array(
			'logify_wp_col_user'  => array(
				'label'   => __( 'User', 'logify-wp' ),
				'visible' => get_user_meta( $user_id, 'logify_wp_col_user', true ) !== 'hidden',
			),
			'logify_wp_col_ip'    => array(
				'label'   => __( 'Source IP', 'logify-wp' ),
				'visible' => get_user_meta( $user_id, 'logify_wp_col_ip', true ) !== 'hidden',
			),
			'logify_wp_col_object' => array(
				'label'   => __( 'Object', 'logify-wp' ),
				'visible' => get_user_meta( $user_id, 'logify_wp_col_object', true ) === 'visible',
			),
		);

		if ( Plugin_Settings::get_enable_notes() ) {
			$columns['logify_wp_col_notes'] = array(
				'label'   => __( 'Notes', 'logify-wp' ),
				'visible' => get_user_meta( $user_id, 'logify_wp_col_notes', true ) !== 'hidden',
			);
		}

		ob_start();
		?>
		<fieldset class="logify-wp-screen-options metabox-prefs">
			<legend><?php esc_html_e( 'Columns', 'logify-wp' ); ?></legend>
			<p class="description">
				<?php esc_html_e( 'ID, Date and Event are always shown. Changes apply immediately.', 'logify-wp' ); ?>
			</p>
			<?php foreach ( $columns as $meta_key => $column ) : ?>
				<label for="<?php echo esc_attr( $meta_key ); ?>">
					<input type="checkbox" class="logify-wp-col-toggle"
						id="<?php echo esc_attr( $meta_key ); ?>"
						data-column-name="<?php echo esc_attr( $meta_key ); ?>"
						<?php checked( $column['visible'] ); ?>>
					<?php echo esc_html( $column['label'] ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
		return $settings . ob_get_clean();
	}

	/**
	 * Sets the screen option value for a specific option.
	 *
	 * @param mixed  $status The current status of the screen option.
	 * @param string $option The name of the screen option.
	 * @param mixed  $value The new value to set for the screen option.
	 * @return mixed The updated status of the screen option.
	 */
	public static function set_screen_option( $status, $option, $value ) {
		if ( 'logify_wp_events_per_page' === $option ) {
			// Clamped. Each row on this page carries an expandable details pane built from the
			// event's properties and metadata, so a very large page length is slow to build
			// and heavy for the browser to lay out - on shared hosting it is the quickest way
			// to make this screen feel broken.
			return max( 5, min( 200, absint( $value ) ) );
		}
		return $status;
	}

	/**
	 * Converts a filename to a handle for Logify WP script or style.
	 *
	 * This function takes a filename and converts it into a handle by replacing dots with dashes
	 * and converting it to lowercase. The resulting handle is prefixed with 'logify-wp-'.
	 *
	 * @param string $filename The filename to convert.
	 * @return string The handle for the Logify WP plugin.
	 */
	private static function filename_to_handle( $filename ) {
		return 'logify-wp-' . strtolower( str_replace( '.', '-', pathinfo( $filename, PATHINFO_FILENAME ) ) );
	}

	/**
	 * Enqueues a stylesheet for the Logify WP plugin.
	 *
	 * @param string           $src    The source file path of the stylesheet.
	 * @param array            $deps   Optional. An array of dependencies for the stylesheet. Default is an empty array.
	 * @param string|bool|null $ver    Optional. The version number of the stylesheet.
	 *      If set to false (default), the WordPress version number will be used.
	 *      If set to 'auto', it will use the last modified time of the source file as the version.
	 *      If set to null, no version number will be added.
	 * @param string           $media  Optional. The media type for which the stylesheet is defined. Default is 'all'.
	 * @return string The handle of the enqueued stylesheet.
	 */
	public static function enqueue_style( $src, $deps = array(), $ver = 'auto', $media = 'all' ): string {
		// The handle is derived from the readable filename, so that it does not change depending
		// on whether the minified build is in use.
		$handle = self::filename_to_handle( $src );

		// Prefer the minified build unless the site is debugging front-end assets.
		$src = self::minified_src( $src );

		// If auto is specified, use the file modified time as the version number.
		$ver = $ver === 'auto' ? self::asset_version( $src ) : $ver;

		// Enqueue the stylesheet.
		$src_url = LOGIFY_WP_PLUGIN_URL . $src;
		wp_enqueue_style( $handle, $src_url, $deps, $ver, $media );

		// Return the handle.
		return $handle;
	}

	/**
	 * Swap an asset path for its minified sibling where one exists.
	 *
	 * Both builds ship, so defining SCRIPT_DEBUG - which is what WordPress core itself uses for
	 * this - gives the readable file back for debugging. If a .min file is missing for any
	 * reason the original is used, so a partial build cannot break an admin page.
	 *
	 * @param string $src Plugin-relative asset path.
	 * @return string The path to enqueue.
	 */
	private static function minified_src( string $src ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return $src;
		}

		// Already minified.
		if ( preg_match( '/\.min\.(js|css)$/', $src ) ) {
			return $src;
		}

		$min = preg_replace( '/\.(js|css)$/', '.min.$1', $src );

		if ( $min && $min !== $src && file_exists( LOGIFY_WP_PLUGIN_DIR . $min ) ) {
			return $min;
		}

		return $src;
	}

	/**
	 * Version string for an asset, based on its modified time.
	 *
	 * @param string $src Plugin-relative asset path.
	 * @return string|false The version, or false to fall back to the WordPress version.
	 */
	private static function asset_version( string $src ) {
		$path = LOGIFY_WP_PLUGIN_DIR . $src;

		// filemtime() emits a warning and returns false on a missing file; check first.
		return file_exists( $path ) ? (string) filemtime( $path ) : false;
	}

	/**
	 * Enqueues a script in WordPress.
	 *
	 * @param string           $src The source file path of the script.
	 * @param array            $deps Optional. An array of script handles that this script depends on.
	 * @param string|bool|null $ver Optional. The version number of the script.
	 *      If set to false (default), the WordPress version number will be used.
	 *      If set to 'auto', it will use the last modified time of the source file as the version.
	 *      If set to null, no version number will be added.
	 * @param array|bool       $args Optional. Additional arguments for the script.
	 * @return string The handle of the enqueued script.
	 */
	public static function enqueue_script( $src, $deps = array(), $ver = 'auto', $args = array() ): string {
		// The handle is derived from the readable filename, so that it does not change depending
		// on whether the minified build is in use.
		$handle = self::filename_to_handle( $src );

		// Prefer the minified build unless the site is debugging front-end assets.
		$src = self::minified_src( $src );

		// If auto is specified, use the file modified time as the version number.
		$ver = $ver === 'auto' ? self::asset_version( $src ) : $ver;

		// Enqueue the script.
		$src_url = LOGIFY_WP_PLUGIN_URL . $src;
		wp_enqueue_script( $handle, $src_url, $deps, $ver, $args );

		// Return the handle.
		return $handle;
	}

	/**
	 * Enqueues the necessary assets for the Logify WP admin pages.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( $hook ) { 
		// Dashboard widget.
		if ( $hook === 'index.php' ) {
			// Styles.
			self::enqueue_style( 'assets/css/dashboard-widget.css' );

			// Scripts.
			self::enqueue_script( 'assets/js/dashboard-widget.js', array( 'jquery' ), 'auto', true );
		}

		if (strpos($hook, 'logify-wp') !== false) {
			wp_enqueue_script('jquery-ui-dialog');
			wp_enqueue_style('wp-jquery-ui-dialog');
		}

		// Settings.
		if ( $hook === 'logify-wp_page_logify-wp-settings' ) {
			self::enqueue_style( 'assets/css/settings.css' );
			$settings_handle = self::enqueue_script( 'assets/js/settings-page.js', array( 'jquery' ), 'auto', true );

			// Data for the user access picker. The nonce and the strings have to come from
			// PHP so the search is authenticated and the messages are translatable.
			wp_localize_script(
				$settings_handle,
				'logifyWpSettings',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'logify_wp_search_users' ),
					'minChars'  => 2,
					'i18n'      => array(
						'searching'   => __( 'Searching…', 'logify-wp' ),
						'noResults'   => __( 'No users found.', 'logify-wp' ),
						'searchError' => __( 'The search could not be completed. Please try again.', 'logify-wp' ),
						'viaRole'     => __( '(has access via role)', 'logify-wp' ),
						'add'         => __( 'Grant access', 'logify-wp' ),
						'remove'      => __( 'Remove access', 'logify-wp' ),
						'alreadyAdded' => __( 'Already has access', 'logify-wp' ),
						/* translators: %d: number of users found. */
						'resultCount' => __( '%d users found.', 'logify-wp' ),
						'added'       => __( 'Access granted. Remember to save your changes.', 'logify-wp' ),
						'removed'     => __( 'Access removed. Remember to save your changes.', 'logify-wp' ),
						'noneYet'     => __( 'No individual users have been granted access.', 'logify-wp' ),
					),
				)
			);
		}

		// Log page
		if ($hook === 'toplevel_page_logify-wp') {
			self::setup_page($hook, 'assets/js/log-page.js', 'logify-wp-log-page');
		}

		// Notes page
		if ($hook === 'logify-wp_page_logify-wp-notes') {
			self::setup_page($hook, 'assets/js/notes-page.js', 'logify-wp-notes-page');
		}
		// PHP Error Log page
	}


	// Helper function for common setups
	private static function setup_page($hook, $script_file, $nonce_action) {
		wp_enqueue_editor();
		
		// Styles
		// Note: enqueued further down, after DataTables and jQuery UI, so that the plugin's own
		// rules are not outranked by theirs. It used to load first, which is why some pagination
		// styling silently lost to DataTables' more specific selectors.

		// Get the date format to pass to JS
		$date_format = DateTimes::convert_php_date_format_to_js(get_option('date_format'));

		// Scripts.
		// common-page.js first, and declared as a dependency of the page script, because it
		// defines the shared filter, pagination and note-dialog helpers the page scripts call.
		$common_handle = self::enqueue_script('assets/js/common-page.js', array('jquery'), 'auto', true);

		$script_handle = self::enqueue_script($script_file, array('jquery', $common_handle), 'auto', true);
		
		// Prepare localization data.
		$localization_data = array(
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'ajaxNonce'  => wp_create_nonce($nonce_action),
			'dateFormat' => $date_format,
		);

		// Add column preferences for the log page.
		if ( strpos( $script_file, 'log-page.js' ) !== false ) {
			$user_id = get_current_user_id();
			$localization_data['columnPreferences'] = array(
				'logify_wp_col_user'  => get_user_meta( $user_id, 'logify_wp_col_user', true ),
				'logify_wp_col_ip'    => get_user_meta( $user_id, 'logify_wp_col_ip', true ),
				'logify_wp_col_object' => get_user_meta( $user_id, 'logify_wp_col_object', true ),
				'logify_wp_col_notes' => get_user_meta( $user_id, 'logify_wp_col_notes', true ),
			);
			$localization_data['notesEnabled'] = Plugin_Settings::get_enable_notes();
		}

		// Strings for the note dialog and the active-filter summary.
		$localization_data['i18n'] = array(
			'addNote'           => __( 'Add note', 'logify-wp' ),
			'editNote'          => __( 'Edit note', 'logify-wp' ),
			'confirmDelete'     => __( 'Delete this note? This cannot be undone.', 'logify-wp' ),
			'deleteFailed'      => __( 'Failed to delete the note.', 'logify-wp' ),
			'filterSearch'      => __( 'Search', 'logify-wp' ),
			'filterDate'        => __( 'Date', 'logify-wp' ),
			'filterEvent'       => __( 'Event type', 'logify-wp' ),
			'filterUser'        => __( 'User', 'logify-wp' ),
			'filterRole'        => __( 'Role', 'logify-wp' ),
			'filterPostType'    => __( 'Post type', 'logify-wp' ),
			'filterTaxonomy'    => __( 'Taxonomy', 'logify-wp' ),
			'filterObjectTypes' => __( 'Object types limited', 'logify-wp' ),
			'filterNone'        => __( 'No filters applied', 'logify-wp' ),
			'eventLabel'        => __( 'Event', 'logify-wp' ),
			'noteLabel'         => __( 'Note', 'logify-wp' ),
			'loading'           => __( 'Loading events…', 'logify-wp' ),
			'goToPage'          => __( 'Go to page', 'logify-wp' ),
			'go'                => __( 'Go', 'logify-wp' ),
			/* translators: %d: total number of pages. */
			'ofPages'           => __( 'of %d', 'logify-wp' ),
			'first'             => __( 'First page', 'logify-wp' ),
			'previous'          => __( 'Previous page', 'logify-wp' ),
			'next'              => __( 'Next page', 'logify-wp' ),
			'last'              => __( 'Last page', 'logify-wp' ),
			'exporting'         => __( 'Preparing your export…', 'logify-wp' ),
			'removeFilter'      => __( 'Remove filter', 'logify-wp' ),
			'noteEmpty'         => __( 'Note content cannot be empty.', 'logify-wp' ),
			'filterRemoved'     => __( 'Filter removed.', 'logify-wp' ),
			/* translators: %d: number of filters currently applied. */
			'filtersActive'     => __( '%d filters applied.', 'logify-wp' ),
			/* translators: _START_, _END_ and _TOTAL_ are replaced with row numbers; keep them as they are. */
			'info'              => __( '_START_–_END_ of _TOTAL_', 'logify-wp' ),
			/* translators: _MAX_ is replaced with the unfiltered total; keep it as it is. */
			'infoFiltered'      => __( ' (of _MAX_)', 'logify-wp' ),
			'infoEmpty'         => __( 'No events', 'logify-wp' ),
			'emptyTable'        => __( 'No events match these filters.', 'logify-wp' ),
		);

		wp_localize_script(
			$script_handle,
			'logifyWpLogPage',
			$localization_data
		);

		// Include jQuery UI datepicker
		wp_enqueue_script('jquery-ui-datepicker');
		self::enqueue_style('assets/jquery/jquery-ui.css', array(), '1.14.0');

		// DataTables assets
		self::enqueue_style('assets/datatables/datatables.css', array(), '2.0.8');
		self::enqueue_script('assets/datatables/datatables.js', array('jquery'), '2.0.8', true);

		// The plugin's own styles load last, and declare the third-party stylesheets as
		// dependencies so the order holds however WordPress schedules them.
		self::enqueue_style(
			'assets/css/log-page.css',
			array( 'logify-wp-jquery-ui', 'logify-wp-datatables' )
		);

		// Note: common-page.js is enqueued at the top of this method, ahead of the page script
		// that depends on it.
	}


	/**
	 * Resets logs by truncating the logify_wp_events table and redirects to the settings page.
	 *
	 * @return void
	 */
	public static function reset_logs() {
		// Verify nonce.
		$nonce = isset( $_GET['logify_wp_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['logify_wp_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'logify_wp_reset_logs_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'logify-wp' ), esc_html__( 'Error', 'logify-wp' ), array( 'response' => 403 ) );
		}

		// Check user capabilities.
		if ( ! Access_Control::can_access_settings_page() ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'logify-wp' ), esc_html__( 'Error', 'logify-wp' ), array( 'response' => 403 ) );
		}

		// Do the reset. This also removes notes; tell the user explicitly in the UI.
		Database::truncate_all_tables();

		// Keep a new audit marker after the reset. Unlike the old log entries this
		// marker is created after the tables have been emptied.
		Logger::log_event( 'Activity Log Reset', null, null, null, null, true );

		// The events-log page caches the total row count; drop it so the count is not stale.
		delete_transient( 'logify_wp_events_total' );

		// After performing the action, generate a nonce for the redirect
		$redirect_nonce = wp_create_nonce( 'logify_wp_messages_nonce' );

		// Redirect back with success message and nonce
		wp_safe_redirect(
			add_query_arg(
				array(
					'reset'           => 'success',
					'logify_wp_nonce' => $redirect_nonce,
				),
				admin_url( 'admin.php?page=logify-wp-settings' )
			)
		);
		exit;
	}
}
