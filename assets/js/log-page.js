jQuery(($) => {

    // Get the event ID from the query string.
    let urlParams = new URLSearchParams(window.location.search);
    const eventIdToExpand = urlParams.get('event_id');

    // Helper function to find column index by data attribute using DataTables API.
    let getColumnIndexByData = (dataName) => {
        let columnIndex = null;
        let columns = eventsTable.settings()[0].aoColumns;
        columns.forEach((col, idx) => {
            if (col.data === dataName) {
                columnIndex = idx;
            }
        });
        return columnIndex;
    };

    // Helper function to escape HTML attributes.
    let esc_attr = (text) => {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    // Get column preferences from PHP localization.
    let getColumnPreferences = () => {
        if (logifyWpLogPage && logifyWpLogPage.columnPreferences) {
            return logifyWpLogPage.columnPreferences;
        }
        return {
            logify_wp_col_user: '',
            logify_wp_col_ip: '',
            logify_wp_col_notes: ''
        };
    };

    let columnPrefs = getColumnPreferences();

    // Set up the datatable.
    let eventsTable = $('#logify-wp-activity-log').DataTable({
        processing: true,
        language: {
            processing: 'Please wait...'
        },
        serverSide: true,
        ajax: {
            url: logifyWpLogPage.ajaxUrl,
            type: "POST",
            data: (d) => {
                d.action = 'logify_wp_fetch_logs';
                d.security = logifyWpLogPage.ajaxNonce;
            },
            error: (xhr, error, code) => {
                if (window.console && window.console.error) {
                    console.error('AJAX error loading logs:', error);
                }
            }
        },
        columns: [
            {
                data: "event_id",
                width: '70px'
            },
            { data: "when_happened" },
            { data: "display_name" },
            { data: "user_ip" },
            { data: "event_type" },
            { data: "object_name" },
            {
                data: "object_type",
                visible: false
            },
            {
                data: "note_content",
                // NOTE: visibility is set in columnDefs (targets 7). Do NOT add a
                // `visible` key here. DataTables applies columnDefs first and the
                // columns array second, so anything declared here silently overrides
                // the computed value - which is what hid the Notes column entirely.
                render: (data, type, row) => {
                    if (!data) {
                        return '<span class="logify-wp-no-note">No notes</span>';
                    }
                    // Strip HTML tags for display preview
                    let plainText = data.replace(/<[^>]*>/g, '');
                    // Truncate long notes to 100 characters
                    let truncated = plainText.length > 100 ? plainText.substring(0, 100) + '...' : plainText;
                    // Escape HTML entities for safe display
                    truncated = esc_attr(truncated);
                    let fullText = esc_attr(plainText);
                    return '<span class="logify-wp-note-preview" title="' + fullText + '">' + truncated + '</span>';
                },
                orderable: false,
                searchable: false
            },
            {
                data: "edit_link",
                // Visibility set in columnDefs (targets 8). See the note above.
                render: (data, type, row) => {
                    return data ? data : '';
                },
                orderable: false,
                searchable: false
            }
        ],
        order: [[1, 'desc']],
        layout: {
            topStart: null,
            topEnd: null,
        },
        columnDefs: [
            {
                targets: 2, // User column
                visible: columnPrefs.logify_wp_col_user === 'visible'
            },
            {
                targets: 3, // IP column
                visible: columnPrefs.logify_wp_col_ip === 'visible'
            },
            {
                targets: 7, // Notes column
                visible: logifyWpLogPage && logifyWpLogPage.notesEnabled && columnPrefs.logify_wp_col_notes !== 'hidden'
            },
            {
                targets: 8, // Actions column (only contains note actions)
                visible: logifyWpLogPage && logifyWpLogPage.notesEnabled
            }
        ],
        paging: true,
        lengthChange: false,
        pageLength: +$('#logify_wp_events_per_page').val(),
        createdRow: (row, data, dataIndex) => {
            const $row = $(row);
            const eventId = $row.find('td:first-child').text();
            $row.addClass('logify-wp-summary-row').attr('data-event-id', eventId);
        },
        drawCallback: function (settings) {
            if (!eventIdToExpand) {
                return;
            }
            let $row = $(`tr[data-event-id="${eventIdToExpand}"]`);
            if ($row.length) {
                showDetailsRow($row);
                $row.get(0).scrollIntoView({ behavior: 'smooth' });
            }
        },
        initComplete: function() {
            initColumnVisibilityToggles();
        }
    });

    // Show the details row for a given summary row.
    let showDetailsRow = $tr => {
        let row = eventsTable.row($tr);
        let classes = 'logify-wp-details-row shown';
        row.child(row.data().details, classes).show();
        $tr.addClass('shown');
    };

    // Initialize column visibility toggles
    let initColumnVisibilityToggles = () => {
        // Get current column preferences.
        let displayUser = columnPrefs.logify_wp_col_user === 'visible';
        let displayIp = columnPrefs.logify_wp_col_ip === 'visible';
        let displayNotes = columnPrefs.logify_wp_col_notes !== 'hidden';
        let notesEnabled = logifyWpLogPage && logifyWpLogPage.notesEnabled;

        // Create column toggle UI (moved to CSS instead of inline styles).
        // Only show notes checkbox if notes feature is enabled
        let notesCheckboxHtml = '';
        if (notesEnabled) {
            notesCheckboxHtml = `
                    <label class="logify-wp-toggle-label-item">
                        <input type="checkbox" class="logify-wp-col-toggle" data-column-name="logify_wp_col_notes" aria-label="Toggle Notes column visibility" ${displayNotes ? 'checked' : ''}>
                        Notes
                    </label>
            `;
        }

        let toggleHtml = `
            <div id="logify-wp-column-toggle" class="logify-wp-column-toggle-container">
                <strong class="logify-wp-toggle-label">Show/Hide Columns:</strong>
                <div class="logify-wp-toggle-options">
                    <label class="logify-wp-toggle-label-item">
                        <input type="checkbox" class="logify-wp-col-toggle" data-column-name="logify_wp_col_user" aria-label="Toggle User column visibility" ${displayUser ? 'checked' : ''}>
                        User
                    </label>
                    <label class="logify-wp-toggle-label-item">
                        <input type="checkbox" class="logify-wp-col-toggle" data-column-name="logify_wp_col_ip" aria-label="Toggle Source IP column visibility" ${displayIp ? 'checked' : ''}>
                        Source IP
                    </label>
                    ${notesCheckboxHtml}
                </div>
            </div>
        `;
        
        // Insert before the table.
        $(toggleHtml).insertBefore('#logify-wp-activity-log');
        
        // Handle column toggle clicks.
        $(document).on('change', '.logify-wp-col-toggle', function() {
            let columnName = $(this).data('column-name');
            let isChecked = $(this).is(':checked');
            
            // Convert meta name to data name and look up column index using DataTables API.
            let dataName = getColumnDataFromName(columnName);
            let columnIndex = getColumnIndexByData(dataName);
            
            if (columnIndex !== null) {
                eventsTable.column(columnIndex).visible(isChecked);
                eventsTable.draw(); // Redraw table to apply visibility changes
            }
            
            // Save preference via AJAX.
            $.ajax({
                url: logifyWpLogPage.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'logify_wp_save_column_preference',
                    security: logifyWpLogPage.ajaxNonce,
                    column: columnName,
                    visible: isChecked ? 1 : 0
                },
                error: (xhr, status, error) => {
                    if (window.console && window.console.error) {
                        console.error('Failed to save column preference:', error);
                    }
                }
            });
        });
    };

    // Helper function to convert column meta name to DataTables column data name.
    let getColumnDataFromName = (metaName) => {
        const mapping = {
            'logify_wp_col_user': 'display_name',
            'logify_wp_col_ip': 'user_ip',
            'logify_wp_col_notes': 'note_content'
        };
        return mapping[metaName] || null;
    };

    // Add event listener for opening and closing details
    eventsTable.on('click', 'tr', function (e) {
        // Ignore link clicks.
        if ($(e.target).is('a, a *')) {
            return;
        }

        // Get the row.
        let $tr = $(this);

        // Ignore clicks on the header row.
        if ($tr.parent().is('thead')) {
            return;
        }

        // Check and see which type of row was clicked.
        let isSummaryRow = $tr.hasClass('logify-wp-summary-row');
        let isDetailsRow = $tr.hasClass('logify-wp-details-row');

        // If neither (for example, clicking a row in a details table), bail.
        if (!isSummaryRow && !isDetailsRow) {
            return;
        }

        // Get the datatable row object.
        let row = eventsTable.row($tr);

        if (isSummaryRow) {
            // Summary row clicked.
            if (row.child.isShown()) {
                // Remove the shown class from the summary row.
                $tr.removeClass('shown');
                // Hide the child details row.
                row.child.hide();
            }
            else {
                showDetailsRow($tr);
            }
        }
        else {
            // Details row clicked.
            // Remove the shown class from the summary row.
            $tr.prev().removeClass('shown');
            // Hide the details row.
            row.child.hide();
        }
    });

    // Setup behavior for the search box.
    $('#logify-wp-search-filter').on('keyup', function () {
        // Remember the search string.
        let searchString = this.value;
        wpCookies.set('search', searchString, false, false, false, false);

        // If there are 3 or more characters in the field, or 0, search.
        if (searchString.length >= 3 || searchString.length === 0) {
            eventsTable.search(searchString);
            eventsTable.ajax.reload();
        }
    });

    // Get the selected object types.
    let getSelectedObjectTypes = () => {
        let objectTypes = {};
        $('#logify-wp-object-type-checkboxes input[type="checkbox"]').each((index, element) => {
            objectTypes[element.value] = element.checked;
        });
        return objectTypes;
    }

    // Check if all object types are selected.
    let allObjectTypesSelected = () => {
        return $('#logify-wp-object-type-checkboxes input[type="checkbox"]:not(:checked)').length === 0;
    }

    // Get a value from a cookie; then, in the specified select list, select the option with the
    // matching value if present, otherwise select the option with the empty string value.
    let selectCookieValue = (selectId, cookieName) => {
        // Get the cookie value.
        let value = wpCookies.get(cookieName);

        // Get the select element.
        let $select = $(selectId);

        // Get the option element with the matching value, if present.
        let $option = typeof value === 'string' ? $select.find(`option[value='${value}']`) : null;

        // Check if the option element with the matching value exists.
        if ($option && $option.length) {
            // If it does, select the post type.
            $option.prop('selected', true);
        } else {
            // If not, select the 'All' option.
            $select.val('');
            // Update the cookie to match.
            wpCookies.set(cookieName, '', false, false, false, false);
        }
    }

    // Initialize the search filters from the cookies.
    let initSearchForm = () => {
        // Search string.
        let search = wpCookies.get('search');
        $('#logify-wp-search-filter').val(search);

        // Object types.
        let selectedObjectTypes = wpCookies.get('object_types');
        if (selectedObjectTypes) {
            selectedObjectTypes = JSON.parse(selectedObjectTypes);
        }
        $('#logify-wp-object-type-checkboxes input[type="checkbox"]').each((index, element) => {
            let checked = (selectedObjectTypes && typeof selectedObjectTypes === 'object'
                && element.value in selectedObjectTypes)
                ? Boolean(selectedObjectTypes[element.value]) : true;
            $(element).prop('checked', checked);
        });
        // Update state of 'All' checkbox to match state of others.
        $('#logify-wp-show-all-events').prop('checked', allObjectTypesSelected());

        // Start date.
        let startDate = wpCookies.get('start_date');
        $('#logify-wp-start-date').val(startDate);
        $('#logify-wp-end-date').datepicker("option", "minDate", startDate);

        // End date.
        let endDate = wpCookies.get('end_date');
        $('#logify-wp-end-date').val(endDate);
        $('#logify-wp-start-date').datepicker("option", "maxDate", endDate);

        // Post type.
        selectCookieValue('#logify-wp-post-type-filter', 'post_type');

        // Taxonomy.
        selectCookieValue('#logify-wp-taxonomy-filter', 'taxonomy');

        // Event type.
        selectCookieValue('#logify-wp-event-type-filter', 'event_type');

        // User.
        selectCookieValue('#logify-wp-user-filter', 'user_id');

        // Role.
        selectCookieValue('#logify-wp-role-filter', 'role');
    };

    // Setup behavior of object type checkboxes.
    $('.logify-wp-object-type-filter-item input').on('change', function (event) {
        // Cancel the default behavior of the checkbox.
        event.preventDefault();

        // Click the surrounding div.
        $(this).parent().click();
    });

    // Setup behavior of object type containers (i.e. the colored divs wrapping the checkbox and label).
    $('.logify-wp-object-type-filter-item').on('click', function (event) {
        // Toggle the checkbox.
        let checkbox = $(this).find('input[type="checkbox"]');
        let isChecked = checkbox.is(':checked');
        checkbox.prop('checked', !isChecked);
        isChecked = !isChecked;

        // If this is the 'All' checkbox, check or uncheck all others.
        if (checkbox.is('#logify-wp-show-all-events')) {
            if (isChecked) {
                // All is checked, so check all others.
                $('#logify-wp-object-type-checkboxes input').prop('checked', true);
            }
            else {
                // Otherwise, uncheck all others.
                $('#logify-wp-object-type-checkboxes input').prop('checked', false);
            }
        }
        else {
            // Update state of 'All' checkbox to match state of others.
            $('#logify-wp-show-all-events').prop('checked', allObjectTypesSelected());
        }

        // Update cookie.
        let selectedObjectTypes = getSelectedObjectTypes();
        // console.log(selectedObjectTypes);
        wpCookies.set('object_types', JSON.stringify(selectedObjectTypes), false, false, false, false);

        // Reload the page.
        eventsTable.ajax.reload();
    });

    // Extract the date from a datepicker element.
    let getDate = element => {
        var date;
        try {
            date = $.datepicker.parseDate(logifyWpLogPage.dateFormat, element.value);
            console.log(date);
        } catch (error) {
            date = null;
        }

        return date;
    };

    // Setup the start datepicker.
    let startDatePicker = $('#logify-wp-start-date').datepicker({
        dateFormat: logifyWpLogPage.dateFormat,
        onSelect: function (date) {
            // console.log('start date selected:', date);

            // Update the cookie.
            wpCookies.set('start_date', date, false, false, false, false);

            // Set the min date of the end datepicker.
            endDatePicker.datepicker("option", "minDate", getDate(this));

            // Reload the table.
            eventsTable.ajax.reload();
        }
    });

    // Setup the end datepicker.
    let endDatePicker = $('#logify-wp-end-date').datepicker({
        dateFormat: logifyWpLogPage.dateFormat,
        onSelect: function (date) {
            // console.log('end date selected:', date);

            // Update the cookie.
            wpCookies.set('end_date', date, false, false, false, false);

            // Set the max date of the start datepicker.
            startDatePicker.datepicker("option", "maxDate", getDate(this));

            // Reload the table.
            eventsTable.ajax.reload();
        }
    });

    // Set up the post type selector.
    $('#logify-wp-post-type-filter').on('change', function () {
        // Update the cookie.
        wpCookies.set('post_type', this.value, false, false, false, false);

        // Reload the table.
        eventsTable.ajax.reload();
    });

    // Set up the taxonomy selector.
    $('#logify-wp-taxonomy-filter').on('change', function () {
        // Update the cookie.
        wpCookies.set('taxonomy', this.value, false, false, false, false);

        // Reload the table.
        eventsTable.ajax.reload();
    });

    // Set up the event type selector.
    $('#logify-wp-event-type-filter').on('change', function () {
        // Update the cookie.
        wpCookies.set('event_type', this.value, false, false, false, false);

        // Reload the table.
        eventsTable.ajax.reload();
    });

    // Set up the user selector.
    $('#logify-wp-user-filter').on('change', function () {
        // Update the cookie.
        wpCookies.set('user_id', this.value, false, false, false, false);

        // Reload the table.
        eventsTable.ajax.reload();
    });

    // Set up the role selector.
    $('#logify-wp-role-filter').on('change', function () {
        // Update the cookie.
        wpCookies.set('role', this.value, false, false, false, false);

        // Reload the table.
        eventsTable.ajax.reload();
    });

    // Submit form to save or update the note
    $('#edit-note-form').on('submit', function (e) {
        e.preventDefault();

        const noteId = $('#edit-note-id').val();
        const eventId = $('#edit-event-id').val();
        const noteContent = tinyMCE.get('edit-note-content')
            ? tinyMCE.get('edit-note-content').getContent() // Get TinyMCE content
            : $('#edit-note-content').val(); // Fallback for textarea

        // Validate content before submitting
        if (!noteContent || noteContent.trim() === '') {
            alert('Note content cannot be empty.');
            return;
        }

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: noteId ? 'logify_update_note' : 'logify_add_note', // Choose action based on note ID
                security: logifyWpLogPage.ajaxNonce,
                note_id: noteId,
                event_id: eventId,
                note_content: noteContent,
            },
            success: function (response) {
                if (response.success) {
                    // Display success message
                    $('<div class="success notice updated"><p>Note saved successfully!</p></div>')
                        .appendTo('#edit-note-form')
                        .fadeIn()
                        .delay(2000) // Show the message for 2 seconds
                        .fadeOut(2000, function () {
                            $(this).remove(); // Remove the message after fading out
                            eventsTable.ajax.reload();
                            $('#edit-note-modal').dialog('close');
                        });
                    
                } else {
                    // Display error message inline
                    $('<div id="message" class="error notice updated"><p>Failed to save note: ' + response.data.message + '</p></div>')
                        .appendTo('#edit-note-form')
                        .fadeIn()
                        .delay(2000)
                        .fadeOut(500, function () {
                            $(this).remove();
                        });
                }
            },
        });
    });

    // Set up the button to reset search filters.
    $('#logify-wp-reset-filters').on('click', function () {
        // Clear the search text.
        $('#logify-wp-search-filter').val('');
        wpCookies.set('search', '', false, false, false, false);

        // Clear the object type checkboxes.
        $('#logify-wp-show-all-events').prop('checked', true);
        $('#logify-wp-object-type-checkboxes input').prop('checked', true);
        let selectedObjectTypes = getSelectedObjectTypes();
        wpCookies.set('object_types', JSON.stringify(selectedObjectTypes), false, false, false, false);

        // Clear the start date.
        $('#logify-wp-start-date').val('');
        wpCookies.set('start_date', '', false, false, false, false);

        // Clear the end date.
        $('#logify-wp-end-date').val('');
        wpCookies.set('end_date', '', false, false, false, false);

        // Clear the post type.
        $('#logify-wp-post-type-filter').val('');
        wpCookies.set('post_type', '', false, false, false, false);

        // Clear the taxonomy.
        $('#logify-wp-taxonomy-filter').val('');
        wpCookies.set('taxonomy', '', false, false, false, false);

        // Clear the event type.
        $('#logify-wp-event-type-filter').val('');
        wpCookies.set('event_type', '', false, false, false, false);

        // Clear the user.
        $('#logify-wp-user-filter').val('');
        wpCookies.set('user_id', '', false, false, false, false);

        // Clear the role.
        $('#logify-wp-role-filter').val('');
        wpCookies.set('role', '', false, false, false, false);

        // Reload the table.
        eventsTable.search('');
        eventsTable.ajax.reload();
    });

    // Initialize the search form.
    initSearchForm();    
});
