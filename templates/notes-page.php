<?php
/**
 * Notes log page template.
 *
 * Deliberately the same structure as templates/log-page.php: the same filter panel, the same
 * table card, the same note dialog, and the same CSS classes. The two screens do the same job
 * and used to be built completely differently, which meant every interface change had to be
 * made twice and they drifted apart anyway.
 *
 * @package Logify_WP
 */

namespace Logify_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$logify_filters_open = get_user_meta( get_current_user_id(), 'logify_wp_filters_open', true ) !== 'closed';
?>

<div class="wrap logify-wp-log logify-wp-notes">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Logify WP Notes Log', 'logify-wp' ); ?></h1>

	<details id="logify-wp-filters-panel" class="logify-wp-filters-panel" <?php echo $logify_filters_open ? 'open' : ''; ?>>
		<summary class="logify-wp-filters-summary">
			<span class="logify-wp-filters-summary__label"><?php esc_html_e( 'Search and filters', 'logify-wp' ); ?></span>
		</summary>

		<div id="logify-wp-filters" class="logify-wp-filters-grid">

			<div class="logify-wp-filter-group logify-wp-filter-group--search">
				<label class="logify-wp-filter-label" for="logify-wp-search-filter"><?php esc_html_e( 'Search', 'logify-wp' ); ?></label>
				<div class="logify-wp-filter">
					<input type="search" id="logify-wp-search-filter" value=""
						placeholder="<?php echo esc_attr__( 'Note text, user…', 'logify-wp' ); ?>"
						aria-describedby="logify-wp-search-hint">
					<p class="logify-wp-filter-hint" id="logify-wp-search-hint"><?php esc_html_e( 'Three characters or more.', 'logify-wp' ); ?></p>
				</div>
			</div>

			<div class="logify-wp-filter-group logify-wp-filter-group--date">
				<span class="logify-wp-filter-label" id="logify-wp-date-label"><?php esc_html_e( 'Date', 'logify-wp' ); ?></span>
				<div class="logify-wp-filter logify-wp-filter--dates" role="group" aria-labelledby="logify-wp-date-label">
					<label class="screen-reader-text" for="logify-wp-start-date"><?php esc_html_e( 'From date', 'logify-wp' ); ?></label>
					<input type="text" id="logify-wp-start-date" value="" placeholder="<?php echo esc_attr__( 'From', 'logify-wp' ); ?>"/>
					<span aria-hidden="true"><?php esc_html_e( 'to', 'logify-wp' ); ?></span>
					<label class="screen-reader-text" for="logify-wp-end-date"><?php esc_html_e( 'To date', 'logify-wp' ); ?></label>
					<input type="text" id="logify-wp-end-date" value="" placeholder="<?php echo esc_attr__( 'To', 'logify-wp' ); ?>"/>
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

			<div class="logify-wp-filter-actions" id="logify-wp-reset-filters-wrapper">
				<button type="button" id="logify-wp-reset-filters" class="button"><?php esc_html_e( 'Reset all filters', 'logify-wp' ); ?></button>
				<?php if ( Access_Control::can_manage_notes() ) : ?><button type="button" id="logify-wp-add-note" class="button button-primary logify-wp-add-note"><?php esc_html_e( 'Add note', 'logify-wp' ); ?></button><?php endif; ?>
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

	<div class="logify-wp-table-wrapper">
		<table id="logify-wp-activity-log" class="widefat fixed table-logify-wp" cellspacing="0">
			<thead>
				<tr>
					<th class="column-id"><?php esc_html_e( 'ID', 'logify-wp' ); ?></th>
					<th class="column-date"><?php esc_html_e( 'Date', 'logify-wp' ); ?></th>
					<th class="column-user"><?php esc_html_e( 'User', 'logify-wp' ); ?></th>
					<th class="column-note"><?php esc_html_e( 'Note', 'logify-wp' ); ?></th>
					<th class="column-event-id"><?php esc_html_e( 'Event ID', 'logify-wp' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'logify-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<!-- Data will be loaded via AJAX -->
			</tbody>
		</table>
	</div>

	<!-- Note dialog. Identical markup to the events log, opened by common-page.js. -->
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
				<textarea id="edit-note-content" name="note_content"></textarea>
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
