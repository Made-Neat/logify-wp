<?php
/**
 * Log page template.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="wrap logify-wp-log">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Logify WP Events Log', 'logify-wp' ); ?></h1>

	<?php
	// The filter panel remembers whether it was left open. On a screen whose job is finding
	// things, the filters should be available without hunting for them, but they took up most
	// of the first screenful before any results were visible.
	$logify_filters_open = get_user_meta( get_current_user_id(), 'logify_wp_filters_open', true ) !== 'closed';
	?>

	<details id="logify-wp-filters-panel" class="logify-wp-filters-panel" <?php echo $logify_filters_open ? 'open' : ''; ?>>
		<summary class="logify-wp-filters-summary">
			<span class="logify-wp-filters-summary__label"><?php esc_html_e( 'Search and filters', 'logify-wp' ); ?></span>
		</summary>

		<div id="logify-wp-filters" class="logify-wp-filters-grid">

			<div class="logify-wp-filter-group logify-wp-filter-group--search">
				<label class="logify-wp-filter-label" for="logify-wp-search-filter"><?php esc_html_e( 'Search', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<input type="search" id="logify-wp-search-filter" value=""
						placeholder="<?php echo esc_attr__( 'User, event, object…', 'logify-wp' ); ?>"
						aria-describedby="logify-wp-search-hint">
					<p class="logify-wp-filter-hint" id="logify-wp-search-hint"><?php esc_html_e( 'Three characters or more.', 'logify-wp' ); ?></p>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--date">
				<span class="logify-wp-filter-label"><?php esc_html_e( 'Date', 'logify-wp' ); ?></span>
				<div class="logify-wp-filter logify-wp-filter--dates">
					<label class="screen-reader-text" for="logify-wp-start-date"><?php esc_html_e( 'From date', 'logify-wp' ); ?></label>
					<input type="text" id="logify-wp-start-date" value="" placeholder="<?php echo esc_attr__( 'From', 'logify-wp' ); ?>"/>
					<span aria-hidden="true"><?php esc_html_e( 'to', 'logify-wp' ); ?></span>
					<label class="screen-reader-text" for="logify-wp-end-date"><?php esc_html_e( 'To date', 'logify-wp' ); ?></label>
					<input type="text" id="logify-wp-end-date" value="" placeholder="<?php echo esc_attr__( 'To', 'logify-wp' ); ?>"/>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--event-type">
				<label class="logify-wp-filter-label" for="logify-wp-event-type-filter"><?php esc_html_e( 'Event type', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<select id="logify-wp-event-type-filter">
						<option value=""><?php esc_html_e( 'All', 'logify-wp' ); ?></option>
						<?php
						foreach ( $event_types as $event_type ) {
							echo '<option value="' . esc_attr( $event_type ) . '">' . esc_html( $event_type ) . '</option>' . PHP_EOL;
						}
						?>
					</select>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--user">
				<label class="logify-wp-filter-label" for="logify-wp-user-filter"><?php esc_html_e( 'User', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<select id="logify-wp-user-filter">
						<option value=""><?php esc_html_e( 'All', 'logify-wp' ); ?></option>
						<?php
						foreach ( $users as $user_id => $user_name ) {
							echo "<option value='" . esc_attr( $user_id ) . "'>" . esc_html( $user_name ) . '</option>' . PHP_EOL;
						}
						?>
					</select>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--role">
				<label class="logify-wp-filter-label" for="logify-wp-role-filter"><?php esc_html_e( 'Role', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<select id="logify-wp-role-filter">
						<option value=""><?php esc_html_e( 'All', 'logify-wp' ); ?></option>
						<?php
						foreach ( $roles as $logify_role ) {
							echo "<option value='" . esc_attr( $logify_role ) . "'>" . esc_html( ucwords( $logify_role ) ) . '</option>' . PHP_EOL;
						}
						?>
					</select>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--post-type">
				<label class="logify-wp-filter-label" for="logify-wp-post-type-filter"><?php esc_html_e( 'Post type', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<select id="logify-wp-post-type-filter">
						<option value=""><?php esc_html_e( 'All', 'logify-wp' ); ?></option>
						<?php
						foreach ( $post_types as $logify_post_type => $logify_post_type_label ) {
							echo '<option value="' . esc_attr( $logify_post_type ) . '">' . esc_html( $logify_post_type_label ) . '</option>' . PHP_EOL;
						}
						?>
					</select>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--taxonomy">
				<label class="logify-wp-filter-label" for="logify-wp-taxonomy-filter"><?php esc_html_e( 'Taxonomy', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<select id="logify-wp-taxonomy-filter">
						<option value=""><?php esc_html_e( 'All', 'logify-wp' ); ?></option>
						<?php
						foreach ( $taxonomies as $logify_taxonomy => $logify_taxonomy_label ) {
							echo '<option value="' . esc_attr( $logify_taxonomy ) . '">' . esc_html( $logify_taxonomy_label ) . '</option>' . PHP_EOL;
						}
						?>
					</select>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--object-types" id="logify-wp-object-type-filter">
				<span class="logify-wp-filter-label"><?php esc_html_e( 'Object type', 'logify-wp' ); ?></span>

				<div class="logify-wp-object-types">
					<label class="logify-wp-object-type-filter-item logify-wp-object-type-all" for="logify-wp-show-all-events">
						<input type="checkbox" id="logify-wp-show-all-events" value="all" checked>
						<span><?php esc_html_e( 'All', 'logify-wp' ); ?></span>
					</label>

					<div id="logify-wp-object-type-checkboxes" class="logify-wp-object-type-checkboxes">
						<?php
						foreach ( Logger::VALID_OBJECT_TYPES as $object_type => $object_type_display ) {
							// The coloured box is the label itself, wrapping its checkbox. That
							// makes the whole box a single click target with no JavaScript, and
							// keeps the checkbox on the text baseline - it used to be a sibling
							// positioned absolutely, which left it sitting too high in the box.
							$logify_input_id = "logify-wp-show-$object_type-events";

							echo '<label class="' . esc_attr( "logify-wp-object-type-filter-item logify-wp-object-type-$object_type" ) . '" for="' . esc_attr( $logify_input_id ) . '">' . PHP_EOL;
							echo '<input type="checkbox" id="' . esc_attr( $logify_input_id ) . '" value="' . esc_attr( $object_type ) . '" checked>' . PHP_EOL;
							echo '<span>' . esc_html( $object_type_display ) . '</span>' . PHP_EOL;
							echo '</label>' . PHP_EOL;
						}
						?>
					</div>
				</div>
			</div>

			<div class="logify-wp-filter-actions" id="logify-wp-reset-filters-wrapper">
				<button type="button" id="logify-wp-reset-filters" class="button"><?php esc_html_e( 'Reset all filters', 'logify-wp' ); ?></button>

				<?php
				if ( Access_Control::can_export_logs() ) :
				$logify_export_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=logify_wp_export_csv' ),
					'logify_wp_export_csv',
					'logify_wp_nonce'
				);
				?>
				<a id="logify-wp-export-csv" class="button" href="<?php echo esc_url( $logify_export_url ); ?>">
					<?php esc_html_e( 'Export CSV', 'logify-wp' ); ?>
				</a>
				<span class="logify-wp-export-hint description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: maximum number of rows, already formatted. */
							__( 'Exports the events matching the current filters, up to %s rows.', 'logify-wp' ),
							number_format_i18n( Export::get_max_rows() )
						)
					);
					?>
				</span>
				<?php endif; ?>
			</div>
		</div>
	</details>

	<?php
	// Outside the <details> on purpose: the chips are buttons, and interactive controls inside
	// a <summary> both toggle the panel when clicked and are awkward for screen readers, which
	// announce the summary itself as a single control.
	?>
	<div class="logify-wp-active-filters-bar">
		<span class="logify-wp-active-filters-bar__label"><?php esc_html_e( 'Filters:', 'logify-wp' ); ?></span>
		<span id="logify-wp-active-filters" class="logify-wp-active-filters" aria-live="polite"></span>
	</div>

	<div id="logify-wp-no-object-types" class="notice notice-warning logify-wp-empty-notice" hidden>
		<p>
			<?php esc_html_e( 'No object types are selected, so no events can match. Choose at least one type to see results.', 'logify-wp' ); ?>
			<button type="button" id="logify-wp-select-all-object-types" class="button-link"><?php esc_html_e( 'Select all object types', 'logify-wp' ); ?></button>
		</p>
	</div>

	<div class="logify-wp-table-wrapper">
		<table id="logify-wp-activity-log" class="widefat fixed table-logify-wp" cellspacing="0">
			<thead>
				<tr>
					<th class="column-id"><?php esc_html_e( 'ID', 'logify-wp' ); ?></th>
					<th class="column-date"><?php esc_html_e( 'Date', 'logify-wp' ); ?></th>
					<th class="column-user" data-column="display_name"><?php esc_html_e( 'User', 'logify-wp' ); ?></th>
					<th class="column-ip" data-column="user_ip"><?php esc_html_e( 'Source IP', 'logify-wp' ); ?></th>
					<th class="column-event"><?php esc_html_e( 'Event', 'logify-wp' ); ?></th>
					<th class="column-object"><?php esc_html_e( 'Object', 'logify-wp' ); ?></th>
					<th class="column-object-type" style="display: none;"></th>
					<th class="column-notes" data-column="note_content"><?php esc_html_e( 'Notes', 'logify-wp' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'logify-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<!-- Data will be loaded via AJAX -->
			</tbody>
		</table>
	</div>

	<!-- Note dialog. Opened by jQuery UI from common-page.js. -->
	<div id="edit-note-modal" class="logify-wp-note-modal" title="<?php echo esc_attr__( 'Note', 'logify-wp' ); ?>" style="display: none;">
		<form id="edit-note-form" class="logify-wp-note-form">
			<input type="hidden" id="edit-note-id" name="note_id">
			<input type="hidden" id="edit-event-id" name="event_id">

			<div id="logify-wp-note-context" class="logify-wp-note-form__context" hidden>
				<p class="logify-wp-note-form__context-event"></p>
				<p class="logify-wp-note-form__context-meta"></p>
			</div>

			<p class="logify-wp-note-form__hint" id="logify-wp-note-hint">
				<?php esc_html_e( 'Record why this happened, so the reason is there for whoever reads the log next.', 'logify-wp' ); ?>
			</p>

			<div id="editor-wrapper" class="logify-wp-note-form__editor">
				<label class="screen-reader-text" for="edit-note-content"><?php esc_html_e( 'Note', 'logify-wp' ); ?></label>
				<textarea id="edit-note-content" name="note_content" aria-describedby="logify-wp-note-hint"></textarea>
			</div>

			<div class="logify-wp-note-form__messages" aria-live="polite"></div>

			<div class="logify-wp-note-form__actions">
				<button type="button" id="logify-wp-delete-note" class="button-link logify-wp-note-form__delete" hidden><?php esc_html_e( 'Delete note', 'logify-wp' ); ?></button>
				<span class="logify-wp-note-form__spacer"></span>
				<button type="button" id="logify-wp-cancel-note" class="button"><?php esc_html_e( 'Cancel', 'logify-wp' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save note', 'logify-wp' ); ?></button>
			</div>
		</form>
	</div>
</div>
