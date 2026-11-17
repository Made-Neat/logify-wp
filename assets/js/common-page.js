/**
 * Shared behaviour for the Logify WP list screens.
 *
 * The events log and the Notes log are the same kind of screen: a collapsible filter panel
 * over a DataTable. They used to have their own copies of the filter chips, the loading
 * overlay and the pagination bar, which is how they drifted apart. Both now call into here.
 */
( function ( $ ) {
	'use strict';

	const strings = () => ( window.logifyWpLogPage && logifyWpLogPage.i18n ) || {};

	/**
	 * Every filter the list screens know about.
	 *
	 * A screen only gets the filters whose controls are actually on the page, so the Notes
	 * log picks up search, date, user and role without having to declare anything.
	 */
	const filterDefinitions = () => [
		{
			id: 'search',
			cookie: 'search',
			label: () => strings().filterSearch || 'Search',
			el: '#logify-wp-search-filter',
			value: () => $( '#logify-wp-search-filter' ).val(),
			display: ( v ) => '"' + v + '"',
			clear: ( table ) => {
				$( '#logify-wp-search-filter' ).val( '' );
				setCookie( 'search', '' );

				if ( table.search ) {
					table.search( '' );
				}
			}
		},
		{
			id: 'date',
			label: () => strings().filterDate || 'Date',
			el: '#logify-wp-start-date, #logify-wp-end-date',
			value: () => {
				const from = $( '#logify-wp-start-date' ).val();
				const to = $( '#logify-wp-end-date' ).val();

				return from || to ? { from: from, to: to } : '';
			},
			display: ( v ) => ( v.from || '…' ) + ' – ' + ( v.to || '…' ),
			clear: () => {
				$( '#logify-wp-start-date' ).val( '' );
				$( '#logify-wp-end-date' ).val( '' );
				setCookie( 'start_date', '' );
				setCookie( 'end_date', '' );
			}
		},
		selectFilter( 'event-type', 'event_type', () => strings().filterEvent || 'Event type' ),
		selectFilter( 'user', 'user_id', () => strings().filterUser || 'User' ),
		selectFilter( 'role', 'role', () => strings().filterRole || 'Role' ),
		selectFilter( 'post-type', 'post_type', () => strings().filterPostType || 'Post type' ),
		selectFilter( 'taxonomy', 'taxonomy', () => strings().filterTaxonomy || 'Taxonomy' ),
		{
			id: 'object-types',
			label: () => strings().filterObjectTypes || 'Object types',
			el: '#logify-wp-object-type-checkboxes',
			value: () => {
				const $boxes = $( '#logify-wp-object-type-checkboxes input[type="checkbox"]' );

				if ( ! $boxes.length ) {
					return '';
				}

				const unchecked = $boxes.not( ':checked' ).length;

				return unchecked > 0 ? $boxes.filter( ':checked' ).length + '/' + $boxes.length : '';
			},
			display: ( v ) => v,
			clear: () => {
				// Clearing this filter means showing every type again.
				$( '#logify-wp-show-all-events' ).prop( 'checked', true );
				$( '#logify-wp-object-type-checkboxes input[type="checkbox"]' ).prop( 'checked', true );

				const selected = {};
				$( '#logify-wp-object-type-checkboxes input[type="checkbox"]' ).each( function () {
					selected[ this.value ] = true;
				} );

				setCookie( 'object_types', JSON.stringify( selected ) );
			}
		}
	];

	function selectFilter( slug, cookie, label ) {
		const selector = '#logify-wp-' + slug + '-filter';

		return {
			id: slug,
			cookie: cookie,
			label: label,
			el: selector,
			value: () => $( selector ).val(),
			display: () => $( selector ).find( 'option:selected' ).text(),
			clear: () => {
				$( selector ).val( '' );
				setCookie( cookie, '' );
			}
		};
	}

	function setCookie( name, value ) {
		if ( window.wpCookies ) {
			wpCookies.set( name, value, false, false, false, false );
		}
	}

	/**
	 * Render the active filters as removable chips.
	 *
	 * Each chip is a button rather than a span: a filter you can see is a filter you should
	 * be able to remove, and clearing one at a time previously meant finding the control
	 * again or resetting everything.
	 *
	 * @param {Object} table The DataTables instance.
	 */
	function renderChips( table ) {
		const $summary = $( '#logify-wp-active-filters' );

		if ( ! $summary.length ) {
			return;
		}

		const active = filterDefinitions().filter( ( def ) => $( def.el ).length && def.value() );

		$summary.empty();

		if ( ! active.length ) {
			$( '<span>', {
				'class': 'logify-wp-active-filters__none',
				text: strings().filterNone || 'No filters applied'
			} ).appendTo( $summary );

			return;
		}

		active.forEach( ( def ) => {
			const text = def.label() + ': ' + def.display( def.value() );

			const $chip = $( '<span>', { 'class': 'logify-wp-filter-chip' } );

			$( '<span>', { 'class': 'logify-wp-filter-chip__text', text: text } ).appendTo( $chip );

			$( '<button>', {
				type: 'button',
				'class': 'logify-wp-filter-chip__remove',
				// The visible label is the filter name; the accessible name says what the
				// button does, which is what a screen reader user needs.
				'aria-label': ( strings().removeFilter || 'Remove filter' ) + ': ' + text,
				title: ( strings().removeFilter || 'Remove filter' ) + ': ' + text,
				html: '<span aria-hidden="true">&times;</span>'
			} )
				.data( 'filterId', def.id )
				.appendTo( $chip );

			$chip.appendTo( $summary );
		} );
	}

	/**
	 * Wire up the chips, the panel state and the filter controls.
	 *
	 * @param {Object} options table, and optionally onClear.
	 */
	window.logifyWpInitFilters = function ( options ) {
		const table = options.table;
		const $summary = $( '#logify-wp-active-filters' );

		if ( ! $summary.length ) {
			return;
		}

		const refresh = () => renderChips( table );

		$summary.on( 'click', '.logify-wp-filter-chip__remove', function ( event ) {
			event.preventDefault();
			event.stopPropagation();

			const id = $( this ).data( 'filterId' );
			const def = filterDefinitions().find( ( d ) => d.id === id );

			if ( ! def ) {
				return;
			}

			def.clear( table );

			if ( typeof options.onClear === 'function' ) {
				options.onClear( id );
			}

			table.ajax.reload();
			refresh();

			// Focus would otherwise be lost to the body when the chip disappears. Move it to
			// the next chip, or to the panel heading when that was the last one.
			const $next = $summary.find( '.logify-wp-filter-chip__remove' ).first();

			if ( $next.length ) {
				$next.trigger( 'focus' );
			} else {
				$( '#logify-wp-filters-panel' ).find( 'summary' ).trigger( 'focus' );
			}

			// Announce the change for anyone not watching the chips disappear.
			announce( ( strings().filterRemoved || 'Filter removed.' ) + ' ' + summaryText() );
		} );

		// Keep the chips current as the controls themselves change.
		//
		// The draw event is the reliable one: every filter ends in table.ajax.reload(), so a
		// redraw means the filter state has changed however it was changed. The change event
		// alone was not enough - jQuery UI's datepicker only fires change when it has no
		// onSelect callback, and both date fields use one, so picking a date updated the
		// results but never the chips until the page was reloaded.
		table.on( 'draw.dt', refresh );

		// Still listen to the controls directly so the chips keep up with a select or a typed
		// search without waiting for the request to come back.
		$( document ).on( 'change', '#logify-wp-filters select, #logify-wp-filters input', () => {
			window.setTimeout( refresh, 0 );
		} );

		$( '#logify-wp-search-filter' ).on( 'input', () => window.setTimeout( refresh, 0 ) );

		// Remember whether the panel was left open, per user.
		$( '#logify-wp-filters-panel' ).on( 'toggle', function () {
			$.post( logifyWpLogPage.ajaxUrl, {
				action: 'logify_wp_save_filters_state',
				security: logifyWpLogPage.ajaxNonce,
				open: this.open ? 1 : 0
			} );
		} );

		refresh();

		window.logifyWpRefreshChips = refresh;
	};

	function summaryText() {
		const active = filterDefinitions().filter( ( def ) => $( def.el ).length && def.value() );

		return active.length
			? ( strings().filtersActive || '%d filters applied.' ).replace( '%d', active.length )
			: ( strings().filterNone || 'No filters applied' );
	}

	function announce( message ) {
		let $region = $( '#logify-wp-filter-announcer' );

		if ( ! $region.length ) {
			$region = $( '<div>', {
				id: 'logify-wp-filter-announcer',
				'class': 'screen-reader-text',
				'aria-live': 'polite'
			} ).appendTo( 'body' );
		}

		$region.text( message );
	}

	/**
	 * Loading overlay and pagination bar, shared by both list screens.
	 *
	 * @param {Object} table The DataTables instance.
	 */
	window.logifyWpInitTableChrome = function ( table ) {
		const $wrapper = $( '.logify-wp-table-wrapper' );
		const i18n = strings();

		table.on( 'processing.dt', function ( e, settings, processing ) {
			$wrapper.toggleClass( 'is-loading', processing );

			$wrapper.find( 'div.dt-processing' )
				.attr( 'role', 'status' )
				.attr( 'aria-live', 'polite' );
		} );

		let $jump = null;

		const build = () => {
			const $paging = $( '.logify-wp-log .dt-paging' ).first();

			if ( ! $paging.length ) {
				return;
			}

			$paging.closest( '.dt-layout-row' ).addClass( 'logify-wp-tablenav' );

			if ( $jump && $jump.closest( 'body' ).length ) {
				return;
			}

			$jump = $( '<div>', { 'class': 'logify-wp-pagination-jump', id: 'logify-wp-pagination-jump' } );

			const inputId = 'logify-wp-page-number';

			$( '<label>', { for: inputId, text: i18n.goToPage || 'Go to page' } ).appendTo( $jump );

			const $input = $( '<input>', {
				type: 'number',
				id: inputId,
				'class': 'logify-wp-page-input',
				min: 1,
				step: 1,
				value: 1,
				'aria-describedby': 'logify-wp-page-total'
			} ).appendTo( $jump );

			$( '<span>', { 'class': 'logify-wp-pagination-total', id: 'logify-wp-page-total' } ).appendTo( $jump );

			const $go = $( '<button>', {
				type: 'button',
				'class': 'button logify-wp-page-go',
				text: i18n.go || 'Go'
			} ).appendTo( $jump );

			const goToPage = () => {
				const info = table.page.info();
				let requested = parseInt( $input.val(), 10 );

				if ( isNaN( requested ) ) {
					return;
				}

				requested = Math.max( 1, Math.min( info.pages, requested ) );
				$input.val( requested );
				table.page( requested - 1 ).draw( 'page' );
			};

			$go.on( 'click', goToPage );

			$input.on( 'keydown', ( event ) => {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					goToPage();
				}
			} );

			$jump.insertBefore( $paging );
		};

		const sync = () => {
			if ( ! $jump ) {
				return;
			}

			const info = table.page.info();
			const pages = Math.max( 1, info.pages );

			$( '#logify-wp-page-number' ).attr( 'max', pages ).val( info.page + 1 );
			$( '#logify-wp-page-total' ).text( ( i18n.ofPages || 'of %d' ).replace( '%d', pages ) );
			$jump.toggle( info.pages > 1 );
		};

		table.on( 'draw.dt', function () {
			try {
				build();
				sync();
			} catch ( error ) {
				if ( window.console && window.console.error ) {
					console.error( 'Logify WP: pagination controls failed', error );
				}
			}

			$wrapper.removeClass( 'is-loading' );
		} );
	};

	/**
	 * The DataTables language block, so both screens read identically.
	 *
	 * @return {Object} The language configuration.
	 */
	window.logifyWpTableLanguage = function () {
		const i18n = strings();

		return {
			processing: i18n.loading || 'Loading…',
			info: i18n.info || '_START_–_END_ of _TOTAL_',
			infoFiltered: i18n.infoFiltered || ' (of _MAX_)',
			infoEmpty: i18n.infoEmpty || 'No results',
			emptyTable: i18n.emptyTable || 'Nothing matches these filters.',
			zeroRecords: i18n.emptyTable || 'Nothing matches these filters.',
			paginate: {
				first: '\u00AB',
				previous: '\u2039',
				next: '\u203A',
				last: '\u00BB'
			},
			aria: {
				paginate: {
					first: i18n.first || 'First page',
					previous: i18n.previous || 'Previous page',
					next: i18n.next || 'Next page',
					last: i18n.last || 'Last page'
				}
			}
		};
	};
}( jQuery ) );

/**
 * Logify WP note dialog.
 *
 * Shared by the events log and the notes page. Uses jQuery UI's dialog, which WordPress
 * already ships, with the accessibility and escape behaviour a modal needs.
 */
jQuery(($) => {

    const strings = (window.logifyWpLogPage && logifyWpLogPage.i18n) || {};
    const $modal = $('#edit-note-modal');

    if (!$modal.length) {
        return;
    }

    // The element focus should return to when the dialog closes.
    let $opener = null;

    const editorId = 'edit-note-content';

    const getContent = () => {
        const editor = window.tinyMCE && tinyMCE.get(editorId);
        return editor ? editor.getContent() : $('#' + editorId).val();
    };

    const setContent = (content) => {
        const editor = window.tinyMCE && tinyMCE.get(editorId);

        if (editor) {
            editor.setContent(content || '');
            return;
        }

        $('#' + editorId).val(content || '');
    };

    const showMessage = (text, type) => {
        $('.logify-wp-note-form__messages')
            .empty()
            .append(
                $('<div>', { 'class': 'notice notice-' + (type || 'error') + ' inline' })
                    .append($('<p>', { text: text }))
            );
    };

    const clearMessages = () => $('.logify-wp-note-form__messages').empty();

    $modal.dialog({
        autoOpen: false,
        modal: true,
        // Wider than the old 500px: a note is prose, and the editor toolbar needs the room.
        width: Math.min(680, $(window).width() - 40),
        maxHeight: $(window).height() - 80,
        closeOnEscape: true,
        draggable: false,
        dialogClass: 'logify-wp-note-dialog',
        open: function () {
            if (!(window.tinyMCE && tinyMCE.get(editorId))) {
                wp.editor.initialize(editorId, {
                    tinymce: {
                        toolbar1: 'bold italic bullist numlist link undo redo',
                        plugins: 'link lists',
                        menubar: false,
                        default_link_target: '_blank'
                    },
                    // quicktags off: the Visual/Code tabs only appear when both editors are
                    // enabled, and a note is short prose - nobody needs to hand-write HTML for
                    // it. One editor, no tabs.
                    quicktags: false,
                    mediaButtons: false
                });

                // The textarea carries `required`, which a browser cannot validate once
                // TinyMCE hides it - that blocks submission with no visible explanation.
                $('#' + editorId).removeAttr('required');
            }
        },
        close: function () {
            if (window.tinyMCE && tinyMCE.get(editorId)) {
                wp.editor.remove(editorId);
            }

            clearMessages();

            // Return focus to whatever opened the dialog.
            if ($opener && $opener.length) {
                $opener.trigger('focus');
                $opener = null;
            }
        }
    });

    /**
     * Open the dialog.
     *
     * @param {string} noteId   Existing note ID, or empty for a new note.
     * @param {string} eventId  The event the note belongs to.
     * @param {string} content  Existing note content.
     */
    const openNoteModal = (noteId = '', eventId = '', content = '', context = {}) => {
        $('#edit-note-id').val(noteId);
        $('#edit-event-id').val(eventId);
        clearMessages();

        // Show which event this note belongs to. Someone who opens the dialog, gets
        // interrupted and comes back to it has no other way of telling.
        const $context = $('#logify-wp-note-context');
        const lines = [];

        if (context.summary) {
            $context.find('.logify-wp-note-form__context-event').text(context.summary);
        } else {
            $context.find('.logify-wp-note-form__context-event').empty();
        }

        if (eventId) {
            lines.push((strings.eventLabel || 'Event') + ' #' + eventId);
        }

        if (noteId) {
            lines.push((strings.noteLabel || 'Note') + ' #' + noteId);
        }

        if (context.date) {
            lines.push(context.date);
        }

        $context.find('.logify-wp-note-form__context-meta').text(lines.join('  ·  '));
        $context.prop('hidden', !(context.summary || lines.length));

        // Delete only makes sense for a note that exists.
        $('#logify-wp-delete-note').prop('hidden', !noteId);

        $modal.dialog('option', 'title', noteId
            ? (strings.editNote || 'Edit note')
            : (strings.addNote || 'Add note'));

        $modal.dialog('open');

        // Set the content after opening, so the editor exists.
        window.setTimeout(() => {
            setContent(content);

            const editor = window.tinyMCE && tinyMCE.get(editorId);
            if (editor) {
                editor.focus();
            } else {
                $('#' + editorId).trigger('focus');
            }
        }, 200);
    };

    // Expose for the notes page, which opens the same dialog.
    window.logifyWpOpenNoteModal = openNoteModal;

    $('#logify-wp-add-note').on('click', function () {
        $opener = $(this);
        openNoteModal();
    });

    $('#logify-wp-activity-log').on('click', '.edit-note-link', function (e) {
        e.preventDefault();
        $opener = $(this);
        openNoteModal(
            $(this).data('note-id') || '',
            $(this).data('event-id') || '',
            $(this).data('note-content') || '',
            {
                summary: $(this).data('event-summary') || '',
                date: $(this).data('event-date') || ''
            }
        );
    });

    $('#logify-wp-cancel-note').on('click', () => $modal.dialog('close'));

    // Delete. Confirmed first, because it cannot be undone.
    $('#logify-wp-delete-note').on('click', function () {
        const noteId = $('#edit-note-id').val();

        if (!noteId) {
            return;
        }

        if (!window.confirm(strings.confirmDelete || 'Delete this note? This cannot be undone.')) {
            return;
        }

        const $button = $(this).prop('disabled', true);

        $.post(window.ajaxurl, {
            action: 'logify_delete_note',
            security: logifyWpLogPage.ajaxNonce,
            note_id: noteId
        })
            .done((response) => {
                if (response && response.success) {
                    $modal.dialog('close');

                    if (window.logifyWpReloadTable) {
                        window.logifyWpReloadTable();
                    }

                    return;
                }

                showMessage((response && response.data && response.data.message) || (strings.deleteFailed || 'Failed to delete the note.'), 'error');
            })
            .fail(() => showMessage(strings.deleteFailed || 'Failed to delete the note.', 'error'))
            .always(() => $button.prop('disabled', false));
    });

    // Ctrl/Cmd+Enter saves, which is the convention for a compose box.
    $modal.on('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            $('#edit-note-form').trigger('submit');
        }
    });

    // Expose the helpers the page scripts need.
    window.logifyWpNote = { getContent, setContent, showMessage, clearMessages };
});
