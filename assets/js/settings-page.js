/**
 * Logify WP settings page behaviour.
 *
 * Progressive enhancement only: every setting on the page works with JavaScript disabled.
 * This file adds the filter box, the select-all shortcuts, the dependency between the capture
 * period and the error types, and the confirmation prompts.
 */
jQuery(($) => {
    const $page = $('.logify-wp-settings');

    if (!$page.length) {
        return;
    }

    // ---------------------------------------------------------------------------------------
    // Tidy the URL after a save or a redirect.
    // ---------------------------------------------------------------------------------------
    if (history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('reset');
        url.searchParams.delete('migrated');
        url.searchParams.delete('logify_wp_nonce');
        window.history.replaceState({}, document.title, url);
    }

    // ---------------------------------------------------------------------------------------
    // User access picker.
    //
    // Users are searched on the server, 20 at a time, rather than every account on the site
    // being rendered into the page. A WooCommerce or LearnDash site with tens of thousands of
    // accounts costs exactly as much to render as a site with three.
    // ---------------------------------------------------------------------------------------
    const settings = window.logifyWpSettings || {};
    const i18n = settings.i18n || {};
    const $search = $('#logify-wp-user-search');
    const $results = $('#logify-wp-user-results');
    const $granted = $('#logify-wp-granted-users');
    const $grantedEmpty = $('.logify-wp-granted-empty');
    const $status = $('.logify-wp-user-status');

    if ($search.length && $results.length && $granted.length) {
        const minChars = parseInt(settings.minChars, 10) || 2;
        let searchTimer = null;
        let request = null;

        const announce = (message) => $status.text(message || '');

        const syncEmptyState = () => {
            $grantedEmpty.prop('hidden', $granted.children().length > 0);
        };

        const isGranted = (id) => $granted.find('[data-user-id="' + id + '"]').length > 0;

        const closeResults = () => {
            $results.empty().prop('hidden', true);
            $search.attr('aria-expanded', 'false');
        };

        const grantAccess = (user) => {
            if (isGranted(user.id)) {
                return;
            }

            // The hidden input is what actually saves: the form posts one
            // logify_wp_users_with_access[] value per granted user, and removing a row removes
            // the value, so absence means revoked.
            const $item = $('<li>', {
                'class': 'logify-wp-granted__item',
                'data-user-id': user.id
            });

            $('<input>', {
                type: 'hidden',
                name: 'logify_wp_users_with_access[]',
                value: user.id
            }).appendTo($item);

            const $text = $('<span>', { 'class': 'logify-wp-check__text' });
            $('<span>', { 'class': 'logify-wp-check__name', text: user.name }).appendTo($text);

            if (user.viaRole) {
                $('<span>', { 'class': 'logify-wp-hint', text: i18n.viaRole || '' }).appendTo($text);
            }

            $text.appendTo($item);

            $('<button>', {
                type: 'button',
                'class': 'button-link logify-wp-granted__remove',
                text: i18n.remove || 'Remove',
                'aria-label': (i18n.remove || 'Remove') + ': ' + user.name
            }).appendTo($item);

            $granted.append($item);
            syncEmptyState();
            announce(i18n.added || '');
        };

        const renderResults = (users) => {
            $results.empty();

            if (!users.length) {
                $('<p>', { 'class': 'logify-wp-user-results__empty', text: i18n.noResults || '' }).appendTo($results);
                $results.prop('hidden', false);
                $search.attr('aria-expanded', 'true');
                announce(i18n.noResults || '');
                return;
            }

            users.forEach((user) => {
                const already = isGranted(user.id);

                const $option = $('<button>', {
                    type: 'button',
                    'class': 'logify-wp-user-results__item',
                    role: 'option',
                    'aria-selected': 'false',
                    disabled: already
                }).data('user', user);

                $('<span>', { 'class': 'logify-wp-user-results__name', text: user.name }).appendTo($option);
                $('<span>', { 'class': 'logify-wp-hint', text: '@' + user.login }).appendTo($option);

                if (already) {
                    $('<span>', { 'class': 'logify-wp-hint', text: i18n.alreadyAdded || '' }).appendTo($option);
                } else if (user.viaRole) {
                    $('<span>', { 'class': 'logify-wp-hint', text: i18n.viaRole || '' }).appendTo($option);
                }

                $option.appendTo($results);
            });

            $results.prop('hidden', false);
            $search.attr('aria-expanded', 'true');

            if (i18n.resultCount) {
                announce(i18n.resultCount.replace('%d', users.length));
            }
        };

        const runSearch = (term) => {
            if (request) {
                request.abort();
            }

            request = $.post(settings.ajaxUrl, {
                action: 'logify_wp_search_users',
                nonce: settings.nonce,
                term: term
            })
                .done((response) => {
                    if (response && response.success) {
                        renderResults((response.data && response.data.users) || []);
                        return;
                    }

                    announce(i18n.searchError || '');
                })
                .fail((xhr, status) => {
                    // An aborted request is a superseded keystroke, not an error.
                    if (status !== 'abort') {
                        announce(i18n.searchError || '');
                    }
                });
        };

        $search.on('input search', function () {
            const term = $(this).val().trim();

            window.clearTimeout(searchTimer);

            if (term.length < minChars) {
                closeResults();
                return;
            }

            // Debounced so typing does not fire a query per keystroke.
            searchTimer = window.setTimeout(() => runSearch(term), 250);
        });

        $search.on('keydown', (event) => {
            if (event.key === 'Escape') {
                $search.val('');
                closeResults();
            }

            // Down arrow moves into the result list.
            if (event.key === 'ArrowDown' && !$results.prop('hidden')) {
                event.preventDefault();
                $results.find('.logify-wp-user-results__item').not(':disabled').first().trigger('focus');
            }
        });

        $results.on('click', '.logify-wp-user-results__item', function () {
            grantAccess($(this).data('user'));
            $search.val('');
            closeResults();
            $search.trigger('focus');
        });

        // Keyboard navigation within the results.
        $results.on('keydown', '.logify-wp-user-results__item', function (event) {
            const $items = $results.find('.logify-wp-user-results__item').not(':disabled');
            const index = $items.index(this);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                $items.eq(Math.min(index + 1, $items.length - 1)).trigger('focus');
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();

                if (index <= 0) {
                    $search.trigger('focus');
                } else {
                    $items.eq(index - 1).trigger('focus');
                }
            } else if (event.key === 'Escape') {
                closeResults();
                $search.trigger('focus');
            }
        });

        // Clicking away closes the result list.
        $(document).on('click', (event) => {
            if (!$(event.target).closest('.logify-wp-user-picker').length) {
                closeResults();
            }
        });

        $granted.on('click', '.logify-wp-granted__remove', function () {
            const $item = $(this).closest('.logify-wp-granted__item');
            const $next = $item.next().find('.logify-wp-granted__remove');

            $item.remove();
            syncEmptyState();
            announce(i18n.removed || '');

            // Keep focus somewhere sensible after the row disappears.
            if ($next.length) {
                $next.trigger('focus');
            } else {
                $search.trigger('focus');
            }
        });

        syncEmptyState();
    }

    // ---------------------------------------------------------------------------------------
    // Select all / none for a checkbox group.
    // ---------------------------------------------------------------------------------------
    $page.on('click', '.logify-wp-select-all, .logify-wp-select-none', function (event) {
        event.preventDefault();

        const $button = $(this);
        const name = $button.data('target');
        const checked = $button.hasClass('logify-wp-select-all');

        $page
            .find('input[type="checkbox"][name="' + name + '"]')
            .not(':disabled')
            .prop('checked', checked);
    });

    // ---------------------------------------------------------------------------------------
    // Confirmation prompts. The message comes from a data attribute so it can be translated.
    // ---------------------------------------------------------------------------------------
    $(document).on('click', '[data-logify-confirm]', function (event) {
        const message = $(this).data('logifyConfirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
});
