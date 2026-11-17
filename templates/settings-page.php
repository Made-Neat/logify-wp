<?php
/**
 * Settings page template.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Show a success message if settings were saved.
$settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
if ( $settings_updated ) {
	add_settings_error(
		'logify_wp_messages',
		'logify_wp_settings_saved',
		esc_html__( 'Settings saved.', 'logify-wp' ),
		'updated'
	);
}

// Retrieve and sanitize the nonce from $_GET.
$nonce = isset( $_GET['logify_wp_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['logify_wp_nonce'] ) ) : '';
if ( $nonce && wp_verify_nonce( $nonce, 'logify_wp_messages_nonce' ) ) {

	// Show a message if the log records were successfully deleted.
	$logs_reset = isset( $_GET['reset'] ) ? sanitize_text_field( wp_unslash( $_GET['reset'] ) ) : '';
	if ( $logs_reset === 'success' ) {
		add_settings_error(
			'logify_wp_messages',
			'logify_wp_reset_done',
			esc_html__( 'Log records have been deleted.', 'logify-wp' ),
			'updated'
		);
	}

	// Show a message if the data was successfully migrated.
	$data_migrated = isset( $_GET['migrated'] ) ? sanitize_text_field( wp_unslash( $_GET['migrated'] ) ) : '';
	if ( $data_migrated === 'success' ) {
		add_settings_error(
			'logify_wp_messages',
			'logify_wp_migration_done',
			esc_html__( 'Data migrated successfully. You should verify the data in the new plugin, then deactivate and delete the old WP Logify plugin.', 'logify-wp' ),
			'updated'
		);
	}
}

// ---------------------------------------------------------------------------------------------
// Gather everything the form needs before any markup is written.

$roles                      = wp_roles()->roles;
$selected_roles_with_access = Plugin_Settings::get_roles_with_access();
$selected_roles_to_track    = Plugin_Settings::get_roles_to_track();
$keep_quantity              = Plugin_Settings::get_keep_period_quantity();
$keep_units                 = Plugin_Settings::get_keep_period_units();
$users_with_access          = Plugin_Settings::get_users_with_access();

$unit_labels = array(
	'day'   => __( 'days', 'logify-wp' ),
	'week'  => __( 'weeks', 'logify-wp' ),
	'month' => __( 'months', 'logify-wp' ),
);

// Only the users who have already been granted individual access are queried. Everyone else is
// found on demand through the search field, so the size of the user table has no bearing on this
// page: a WooCommerce or LearnDash site with tens of thousands of accounts renders exactly the
// same handful of rows as a site with three.
$granted_users = $users_with_access
	? get_users(
		array(
			'fields'  => array( 'ID', 'display_name', 'user_login' ),
			'include' => $users_with_access,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		)
	)
	: array();

// Reset-logs URL.
$reset_logs_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=logify_wp_reset_logs' ),
	'logify_wp_reset_logs_action',
	'logify_wp_nonce'
);
?>

<div class="wrap logify-wp-settings">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Logify WP Settings', 'logify-wp' ); ?></h1>

	<?php
	// Display any settings errors or messages, in the place WordPress puts them.
	settings_errors( 'logify_wp_messages' );
	?>

	<form method="post" action="options.php" class="logify-wp-settings-form">

		<?php
		// CSRF Protection: settings_fields() outputs the nonce field automatically.
		settings_fields( 'logify_wp_settings_group' );
		// Additional explicit nonce field for enhanced security.
		wp_nonce_field( 'logify_wp_settings_nonce', 'logify_wp_settings_nonce_field' );
		?>

		<div class="logify-wp-settings-grid">

			<!-- ======================================================================= -->
			<section class="logify-wp-card">
				<h2 class="logify-wp-card__title"><?php esc_html_e( 'What to track', 'logify-wp' ); ?></h2>
				<p class="logify-wp-card__intro"><?php esc_html_e( 'Choose whose activity is recorded, and whether comments are included.', 'logify-wp' ); ?></p>

				<div class="logify-wp-field">
					<fieldset>
						<legend class="logify-wp-field__label"><?php esc_html_e( 'Roles to track', 'logify-wp' ); ?></legend>
						<p class="description" id="logify-wp-roles-to-track-desc">
							<?php esc_html_e( 'Activity by users in the selected roles is written to the log. Unselected roles are ignored.', 'logify-wp' ); ?>
						</p>
						<div class="logify-wp-checklist" role="group" aria-describedby="logify-wp-roles-to-track-desc">
							<?php foreach ( $roles as $logify_role_key => $logify_role ) : ?>
								<label class="logify-wp-check">
									<input type="checkbox" name="logify_wp_roles_to_track[]"
										value="<?php echo esc_attr( $logify_role_key ); ?>"
										<?php checked( in_array( $logify_role_key, $selected_roles_to_track, true ) ); ?>>
									<span><?php echo esc_html( $logify_role['name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="logify-wp-bulk">
							<button type="button" class="button-link logify-wp-select-all" data-target="logify_wp_roles_to_track[]"><?php esc_html_e( 'Select all', 'logify-wp' ); ?></button>
							<span aria-hidden="true">·</span>
							<button type="button" class="button-link logify-wp-select-none" data-target="logify_wp_roles_to_track[]"><?php esc_html_e( 'Select none', 'logify-wp' ); ?></button>
						</p>
					</fieldset>
				</div>

				<div class="logify-wp-field logify-wp-field--toggle">
					<input type="checkbox" id="logify_wp_comment_tracking" name="logify_wp_comment_tracking" value="1"
						aria-describedby="logify-wp-comment-tracking-desc"
						<?php checked( Plugin_Settings::get_comment_tracking_state(), 1 ); ?>>
					<div>
						<label for="logify_wp_comment_tracking" class="logify-wp-field__label"><?php esc_html_e( 'Track comments', 'logify-wp' ); ?></label>
						<p class="description" id="logify-wp-comment-tracking-desc">
							<?php esc_html_e( 'Record when comments are posted, edited, approved, spammed or deleted. Busy comment sections can produce a lot of log entries.', 'logify-wp' ); ?>
						</p>
					</div>
				</div>
			</section>

			<!-- ======================================================================= -->
			<section class="logify-wp-card">
				<h2 class="logify-wp-card__title"><?php esc_html_e( 'Who can see the log', 'logify-wp' ); ?></h2>
				<p class="logify-wp-card__intro"><?php esc_html_e( 'Administrators always have access. Grant access to other roles, or to individual people.', 'logify-wp' ); ?></p>

				<div class="logify-wp-field">
					<fieldset>
						<legend class="logify-wp-field__label"><?php esc_html_e( 'Roles with access', 'logify-wp' ); ?></legend>
						<div class="logify-wp-checklist" role="group">
							<?php
							// Hidden field so the administrator role is always saved: its checkbox is
							// disabled, so the browser does not submit it.
							?>
							<input type="hidden" name="logify_wp_roles_with_access[]" value="administrator">
							<?php foreach ( $roles as $logify_role_key => $logify_role ) : ?>
								<label class="logify-wp-check">
									<input type="checkbox" name="logify_wp_roles_with_access[]"
										value="<?php echo esc_attr( $logify_role_key ); ?>"
										<?php checked( in_array( $logify_role_key, $selected_roles_with_access, true ) ); ?>
										<?php disabled( $logify_role_key === 'administrator' ); ?>>
									<span class="logify-wp-check__text">
										<span class="logify-wp-check__name"><?php echo esc_html( $logify_role['name'] ); ?></span>
										<?php if ( $logify_role_key === 'administrator' ) : ?>
											<span class="logify-wp-hint"><?php esc_html_e( '(always has access)', 'logify-wp' ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				</div>

				<div class="logify-wp-field">
					<fieldset class="logify-wp-user-picker">
						<legend class="logify-wp-field__label"><?php esc_html_e( 'Additional users with access', 'logify-wp' ); ?></legend>
						<p class="description" id="logify-wp-users-desc">
							<?php esc_html_e( 'Give a specific person access without changing their role. Search by name, username or email address.', 'logify-wp' ); ?>
						</p>

						<p class="logify-wp-user-search">
							<label class="screen-reader-text" for="logify-wp-user-search"><?php esc_html_e( 'Search for a user to grant access to', 'logify-wp' ); ?></label>
							<input type="search" id="logify-wp-user-search" class="regular-text"
								placeholder="<?php echo esc_attr__( 'Search users…', 'logify-wp' ); ?>"
								autocomplete="off" role="combobox" aria-expanded="false"
								aria-controls="logify-wp-user-results" aria-autocomplete="list"
								aria-describedby="logify-wp-users-desc">
						</p>

						<div id="logify-wp-user-results" class="logify-wp-user-results" role="listbox"
							aria-label="<?php echo esc_attr__( 'Search results', 'logify-wp' ); ?>" hidden></div>

						<div class="logify-wp-user-status screen-reader-text" aria-live="polite"></div>

						<ul id="logify-wp-granted-users" class="logify-wp-granted">
							<?php foreach ( $granted_users as $granted_user ) : ?>
								<?php
								$granted_id   = (int) $granted_user->ID;
								$granted_name = $granted_user->display_name !== '' ? $granted_user->display_name : $granted_user->user_login;
								$has_via_role = Access_Control::user_has_access_via_role( $granted_id );
								?>
								<li class="logify-wp-granted__item" data-user-id="<?php echo esc_attr( $granted_id ); ?>">
									<input type="hidden" name="logify_wp_users_with_access[]" value="<?php echo esc_attr( $granted_id ); ?>">
									<span class="logify-wp-check__text">
										<span class="logify-wp-check__name"><?php echo esc_html( $granted_name ); ?></span>
										<?php if ( $has_via_role ) : ?>
											<span class="logify-wp-hint"><?php esc_html_e( '(has access via role)', 'logify-wp' ); ?></span>
										<?php endif; ?>
									</span>
									<button type="button" class="button-link logify-wp-granted__remove"
										aria-label="<?php
										echo esc_attr(
											sprintf(
												/* translators: %s: user's name. */
												__( 'Remove access for %s', 'logify-wp' ),
												$granted_name
											)
										);
										?>"><?php esc_html_e( 'Remove', 'logify-wp' ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>

						<p class="logify-wp-granted-empty description" <?php echo $granted_users ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'No individual users have been granted access.', 'logify-wp' ); ?>
						</p>
					</fieldset>
				</div>
			</section>

			<!-- ======================================================================= -->
			<section class="logify-wp-card">
				<h2 class="logify-wp-card__title"><?php esc_html_e( 'Log retention', 'logify-wp' ); ?></h2>
				<p class="logify-wp-card__intro"><?php esc_html_e( 'How long records are kept before being removed automatically.', 'logify-wp' ); ?></p>

				<div class="logify-wp-field">
					<label class="logify-wp-field__label" for="logify_wp_keep_period_quantity"><?php esc_html_e( 'Keep log records for', 'logify-wp' ); ?></label>
					<div class="logify-wp-inline-controls">
						<select id="logify_wp_keep_period_quantity" name="logify_wp_keep_period_quantity">
							<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $keep_quantity, $i ); ?>><?php echo esc_html( $i ); ?></option>
							<?php endfor; ?>
						</select>
						<label class="screen-reader-text" for="logify_wp_keep_period_units"><?php esc_html_e( 'Retention unit', 'logify-wp' ); ?></label>
						<select id="logify_wp_keep_period_units" name="logify_wp_keep_period_units">
							<?php foreach ( $unit_labels as $unit_key => $unit_label ) : ?>
								<option value="<?php echo esc_attr( $unit_key ); ?>" <?php selected( $keep_units, $unit_key ); ?>><?php echo esc_html( $unit_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<p class="description">
						<?php esc_html_e( 'Anything older than this is deleted by a daily background task. Longer periods mean larger database tables.', 'logify-wp' ); ?>
					</p>
				</div>

				<div class="logify-wp-field logify-wp-field--toggle">
					<input type="checkbox" id="logify_wp_delete_on_uninstall" name="logify_wp_delete_on_uninstall" value="1"
						aria-describedby="logify-wp-uninstall-desc"
						<?php checked( Plugin_Settings::get_delete_on_uninstall(), 1 ); ?>>
					<div>
						<label for="logify_wp_delete_on_uninstall" class="logify-wp-field__label"><?php esc_html_e( 'Delete all data when uninstalling', 'logify-wp' ); ?></label>
						<p class="description" id="logify-wp-uninstall-desc">
							<?php esc_html_e( 'Drops the plugin\'s database tables when it is deleted. Leave this off if you want to keep your log history through a reinstall.', 'logify-wp' ); ?>
						</p>
					</div>
				</div>
			</section>

			<!-- ======================================================================= -->
			<section class="logify-wp-card">
				<h2 class="logify-wp-card__title"><?php esc_html_e( 'IP addresses', 'logify-wp' ); ?></h2>
				<p class="logify-wp-card__intro"><?php esc_html_e( 'How the visitor IP recorded against each event is worked out.', 'logify-wp' ); ?></p>

				<p class="description"><?php esc_html_e( 'Visitor IPs are recorded with events. Logify WP does not look up their location or automatically send them to a geolocation service.', 'logify-wp' ); ?></p>

				<div class="logify-wp-field logify-wp-field--toggle">
					<input type="checkbox" id="logify_wp_trust_proxy_headers" name="logify_wp_trust_proxy_headers" value="1"
						aria-describedby="logify-wp-proxy-desc"
						<?php checked( Plugin_Settings::get_trust_proxy_headers(), 1 ); ?>>
					<div>
						<label for="logify_wp_trust_proxy_headers" class="logify-wp-field__label"><?php esc_html_e( 'Trust proxy headers', 'logify-wp' ); ?></label>
						<p class="description" id="logify-wp-proxy-desc">
							<?php esc_html_e( 'Cloudflare visitor IPs are detected automatically when the incoming connection is verified as a Cloudflare edge. Leave this option off unless using another trusted proxy or load balancer. For those setups, configure LOGIFY_WP_TRUSTED_PROXY_IPS in wp-config.php with the immediate proxy IP/CIDR. The default reads X-Forwarded-For from right to left; other headers can be selected using logify_wp_trusted_proxy_headers. Unknown proxy headers are never trusted automatically.', 'logify-wp' ); ?>
						</p>

					</div>
				</div>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<?php $ip_diagnostic = User_Utility::get_ip_diagnostics(); ?>
					<div class="logify-wp-field" style="margin-top: 16px;">
						<h3 class="logify-wp-field__label"><?php esc_html_e( 'Current request IP diagnostics', 'logify-wp' ); ?></h3>
						<p class="description"><?php esc_html_e( 'For unknown hosting proxies, Logify also captures a reported visitor IP when available, clearly marked as unverified in the activity log. The connection IP remains separately recorded for audit purposes.', 'logify-wp' ); ?></p>
						<p class="description"><?php esc_html_e( 'These values come from the current admin page request. Forwarded headers are informational until the connecting proxy is verified.', 'logify-wp' ); ?></p>
						<table class="widefat striped" style="margin-top: 10px; max-width: 640px;">
							<tbody>
								<?php
								$ip_rows = array(
									'REMOTE_ADDR'         => $ip_diagnostic['remote_addr'],
									'SERVER_ADDR'         => $ip_diagnostic['server_addr'],
									'CF-Connecting-IP'    => $ip_diagnostic['cf_connecting_ip'],
									'CF-Connecting-IPv6'  => $ip_diagnostic['cf_connecting_ipv6'],
								'X-Real-IP'           => $ip_diagnostic['x_real_ip'],
									'X-Forwarded-For'     => $ip_diagnostic['x_forwarded_for'],
									'IP selected by Logify' => $ip_diagnostic['logify_ip'],
									'Reported visitor IP (unverified)' => $ip_diagnostic['reported_ip'],
								);
								foreach ( $ip_rows as $label => $value ) :
									?>
									<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( '' !== $value ? $value : '—' ); ?></code></td></tr>
								<?php endforeach; ?>
								<tr><th scope="row"><?php esc_html_e( 'Connection trust', 'logify-wp' ); ?></th><td>
									<?php
									if ( 'cloudflare' === $ip_diagnostic['trust'] ) {
										esc_html_e( 'Verified Cloudflare connection', 'logify-wp' );
									} elseif ( 'configured_proxy' === $ip_diagnostic['trust'] ) {
										esc_html_e( 'Verified against configured trusted proxies', 'logify-wp' );
									} else {
										esc_html_e( 'Unverified proxy headers are not trusted; a reported IP may be shown separately', 'logify-wp' );
									}
									?>
								</td></tr>
							</tbody>
						</table>
						<?php if ( $ip_diagnostic['remote_is_server'] && 'unverified' === $ip_diagnostic['trust'] ) : ?>
							<p class="description"><strong><?php esc_html_e( 'PHP sees the hosting server as the connection source. Logify can display a separately reported IP where available, but only a verified proxy can establish that the reported address is authentic.', 'logify-wp' ); ?></strong></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Do not trust CF-Connecting-IP or X-Forwarded-For merely because they contain your public IP. Your proxy must authenticate and overwrite those headers at the network boundary.', 'logify-wp' ); ?></p>
					</div>
				<?php endif; ?>
			</section>

			<!-- ======================================================================= -->
			<section class="logify-wp-card">
				<h2 class="logify-wp-card__title"><?php esc_html_e( 'Display', 'logify-wp' ); ?></h2>

				<div class="logify-wp-field logify-wp-field--toggle">
					<input type="checkbox" id="logify_wp_enable_notes" name="logify_wp_enable_notes" value="1"
						aria-describedby="logify-wp-notes-desc"
						<?php checked( Plugin_Settings::get_enable_notes(), 1 ); ?>>
					<div>
						<label for="logify_wp_enable_notes" class="logify-wp-field__label"><?php esc_html_e( 'Enable notes', 'logify-wp' ); ?></label>
						<p class="description" id="logify-wp-notes-desc">
							<?php esc_html_e( 'Adds a Notes column to the log and a Notes page, so you can record why something was changed.', 'logify-wp' ); ?>
						</p>
					</div>
				</div>

				<div class="logify-wp-field logify-wp-field--toggle">
					<input type="checkbox" id="logify_wp_show_in_admin_bar" name="logify_wp_show_in_admin_bar" value="1"
						aria-describedby="logify-wp-adminbar-desc"
						<?php checked( Plugin_Settings::get_show_in_admin_bar(), 1 ); ?>>
					<div>
						<label for="logify_wp_show_in_admin_bar" class="logify-wp-field__label"><?php esc_html_e( 'Show in the admin bar', 'logify-wp' ); ?></label>
						<p class="description" id="logify-wp-adminbar-desc">
							<?php esc_html_e( 'Adds a Logify WP menu to the toolbar for quick access to the log.', 'logify-wp' ); ?>
						</p>
					</div>
				</div>
			</section>

		</div><!-- /.logify-wp-settings-grid -->

		<div class="logify-wp-actions">
			<?php submit_button( __( 'Save changes', 'logify-wp' ), 'primary', 'logify-wp-submit-button', false ); ?>

			<?php
			// Display the 'Migrate data from WP Logify' button if the old plugin is active.
			if ( is_plugin_active( 'wp-logify/wp-logify.php' ) ) :
				$migrate_data_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=logify_wp_migrate_data' ),
					'logify_wp_migrate_data_action',
					'logify_wp_nonce'
				);
				?>
				<a id="logify-wp-migrate-data-button" class="button button-secondary"
					href="<?php echo esc_url( $migrate_data_url ); ?>"
					data-logify-confirm="<?php echo esc_attr__( 'Are you sure you want to migrate the data from the old WP Logify plugin to the new Logify WP plugin? This will remove all log records in the new plugin.', 'logify-wp' ); ?>"><?php esc_html_e( 'Migrate data from WP Logify', 'logify-wp' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<!-- Destructive action, kept outside the settings form so it cannot be triggered by a save. -->
	<section class="logify-wp-card logify-wp-card--danger">
		<h2 class="logify-wp-card__title"><?php esc_html_e( 'Delete log records', 'logify-wp' ); ?></h2>
		<p class="logify-wp-card__intro">
			<?php esc_html_e( 'Deletes all activity logs and all Notes, including standalone Notes, immediately. Settings are kept. This cannot be undone.', 'logify-wp' ); ?>
		</p>
		<a id="logify-wp-delete-logs-button" class="button logify-wp-button--danger"
			href="<?php echo esc_url( $reset_logs_url ); ?>"
			data-logify-confirm="<?php echo esc_attr__( 'Are you sure you want to permanently delete all activity logs AND ALL NOTES? This cannot be undone.', 'logify-wp' ); ?>"><?php esc_html_e( 'Delete all log records now', 'logify-wp' ); ?></a>
	</section>

</div>
