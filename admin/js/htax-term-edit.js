jQuery(document).ready(function($) {

    // ============================================================================
    // BEFOREUNLOAD — warn on unsaved changes (similar to native WP editor guard)
    // ============================================================================

    var $editForm = $('#edittag');
    var initialFormState = $editForm.length ? $editForm.serialize() : '';

    $(window).on('beforeunload.htax', function(e) {
        if (!$editForm.length) return;
        if ($editForm.serialize() !== initialFormState) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes.';
            return 'You have unsaved changes.';
        }
    });

    // Suppress the warning when the user intentionally submits the form.
    $editForm.on('submit.htax', function() {
        $(window).off('beforeunload.htax');
    });

    // Reset baseline after a successful AJAX save (quick-edit; full saves reload the page).
    $(document).on('htax:saved', function() {
        initialFormState = $editForm.serialize();
    });

    // ============================================================================
    // CUSTOM PILL TOOLTIP OVERLAY
    // ============================================================================

    function htaxEscHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var $pillTooltip = $('<div class="htax-pill-tooltip" role="tooltip" aria-hidden="true"></div>').appendTo('body');
    var tooltipHideTimer;
    var $tooltipSourcePill = null; // pill that triggered the currently-visible tooltip

    function positionTooltip($pill) {
        var rect      = $pill[0].getBoundingClientRect();
        var scrollTop  = window.pageYOffset  || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset  || document.documentElement.scrollLeft;
        var tipH      = $pillTooltip.outerHeight();
        var top       = rect.top + scrollTop - tipH - 6;
        // If tooltip would go above viewport, flip below the pill instead.
        if (top < scrollTop + 4) {
            top = rect.bottom + scrollTop + 6;
        }
        $pillTooltip.css({ top: top, left: rect.left + scrollLeft });
        // Clamp to viewport right edge.
        var overflow = (rect.left + scrollLeft + $pillTooltip.outerWidth()) -
                       (scrollLeft + document.documentElement.clientWidth - 12);
        if (overflow > 0) {
            $pillTooltip.css('left', parseFloat($pillTooltip.css('left')) - overflow);
        }
    }

    function buildTooltipHtml(entries) {
        return entries.map(function(entry) {
            if (entry.type === 'group') {
                var arrow = entry.dir === 'fwd' ? '→' : '←';
                if (entry.url) {
                    return '<div class="htax-tooltip-line">' +
                        arrow + ' via: <a href="' + htaxEscHtml(entry.url) + '">' +
                        htaxEscHtml(entry.name) + '</a></div>';
                }
                return '<div class="htax-tooltip-line">' + arrow + ' via: ' + htaxEscHtml(entry.name) + '</div>';
            } else if (entry.type === 'text') {
                return '<div class="htax-tooltip-line">' + htaxEscHtml(entry.text) + '</div>';
            } else {
                var arrow = entry.dir === 'fwd' ? '⇢' : '⇠';
                return '<div class="htax-tooltip-line"><a href="#" class="htax-tooltip-tab-link" data-tab="' +
                    htaxEscHtml(entry.slug) + '">' + arrow + ' ' + htaxEscHtml(entry.label) + '</a></div>';
            }
        }).join('');
    }

    $(document).on('mouseenter', '.htax-pill[data-tooltip]', function() {
        clearTimeout(tooltipHideTimer);
        var raw = $(this).attr('data-tooltip');
        var entries;
        try { entries = JSON.parse(raw); } catch(e) { return; }
        if (!entries || !entries.length) return;
        $tooltipSourcePill = $(this);
        $pillTooltip.html(buildTooltipHtml(entries)).attr('aria-hidden', 'false').show();
        positionTooltip($(this));
    });

    $(document).on('mouseleave', '.htax-pill[data-tooltip]', function() {
        tooltipHideTimer = setTimeout(function() {
            $pillTooltip.hide().attr('aria-hidden', 'true');
        }, 180);
    });

    $pillTooltip.on('mouseenter', function() {
        clearTimeout(tooltipHideTimer);
    }).on('mouseleave', function() {
        tooltipHideTimer = setTimeout(function() {
            $pillTooltip.hide().attr('aria-hidden', 'true');
        }, 180);
    });

    // Custom type tooltip link — switch to the corresponding relationship tab and
    // highlight the context term pill on that tab (mirrors term_context highlighting).
    $(document).on('click', '.htax-tooltip-tab-link', function(e) {
        e.preventDefault();
        var slug        = $(this).data('tab');
        var termId      = $tooltipSourcePill ? parseInt( $tooltipSourcePill.data('id') ) : 0;
        $pillTooltip.hide().attr('aria-hidden', 'true');
        if (slug) {
            $('.htax-rel-tab-btn[data-tab="' + slug + '"]').trigger('click');
            if (termId) {
                $('[data-panel="' + slug + '"]')
                    .find('.htax-pill[data-id="' + termId + '"]:not(.htax-group-pill)')
                    .addClass('htax-context-direct');
            }
        }
    });


    // Initialize HTAX_Ajax with safe defaults
    window.HTAX_Ajax = window.HTAX_Ajax || {};
    HTAX_Ajax.nonce             = HTAX_Ajax.nonce             || '';
    HTAX_Ajax.ajax_url          = HTAX_Ajax.ajax_url          || '';
    HTAX_Ajax.group_terms       = HTAX_Ajax.group_terms       || {}; // groupId => [{id,name},...]
    HTAX_Ajax.group_names       = HTAX_Ajax.group_names       || {}; // groupId => name
    HTAX_Ajax.terms_from_groups = Array.isArray(HTAX_Ajax.terms_from_groups) ? HTAX_Ajax.terms_from_groups : [];

    const AUTOCOMPLETE_CONFIG = { minSearchLength: 2, delay: 300 };

    // Enforce autocomplete dropdown height after open — overrides any CSS or inline-style
    // constraints set by jQuery UI positioning (e.g. when the container is a containing block).
    $( document ).on( 'autocompleteopen', '.htax-search, .htax-merged-search', function () {
        $( this ).autocomplete( 'widget' )[0].style.setProperty( 'max-height', '300px', 'important' );
    } );

    // ============================================================================
    // STANDARD PILL CONTAINERS  (redirect, exclusions, and the add-form containers)
    // ============================================================================

    $('.htax-tag-container').not('.htax-merged-container').each(function() {
        // Defer containers inside hidden redirect panels — jQuery UI autocomplete
        // fails silently when initialised inside a display:none ancestor.
        if ( $(this).closest('[data-htax-hides-on-redirect]').is(':hidden') ) { return; }
        try { initStandardContainer($(this)); } catch(e) { window.console && console.error('HT init error (standard):', e); }
    });

    // ============================================================================
    // MERGED PILL CONTAINERS  (inclusions + groups, edit form)
    // ============================================================================

    $('.htax-merged-container').not('.htax-sym-container').each(function() {
        if ( $(this).closest('[data-htax-hides-on-redirect]').is(':hidden') ) { return; }
        try { initMergedContainer($(this)); } catch(e) { window.console && console.error('HT init error (merged):', e); }
    });

    $('.htax-sym-container').each(function() {
        try { initSymContainer($(this)); } catch(e) { window.console && console.error('HT init error (sym):', e); }
    });

    // ============================================================================
    // TAB NAVIGATION
    // ============================================================================

    $(document).on('click', '.htax-rel-tab-btn', function() {
        var $btn  = $(this);
        var panel = $btn.data('tab');
        var $nav  = $btn.closest('.htax-rel-tabs-nav');
        var $td   = $nav.closest('td');

        // Clear any tooltip-driven context highlight from a previous tab-switch.
        $td.find('.htax-context-direct').removeClass('htax-context-direct');

        $nav.find('.htax-rel-tab-btn').removeClass('active').attr('aria-selected', 'false');
        $btn.addClass('active').attr('aria-selected', 'true');

        $td.find('.htax-rel-tab-panel').each(function() {
            if ($(this).data('panel') === panel) {
                $(this).removeAttr('hidden').show();
            } else {
                $(this).attr('hidden', '').hide();
            }
        });
    });

    // ============================================================================
    // REDIRECT TOGGLE
    // ============================================================================

    function applyRedirectToggle(isChecked) {
        var $includesFwd    = $('[data-includes-fwd]');
        var $addFormFields  = $('[data-htax-hides-on-redirect]');
        var $redirectTarget = $('.htax-redirect-target');
        var $searchInput    = $redirectTarget.find('.htax-search');

        if (isChecked) {
            $includesFwd.hide();
            $addFormFields.hide();
            $redirectTarget.show();
            $searchInput.prop('disabled', false);
        } else {
            $includesFwd.show();
            $addFormFields.show();
            $redirectTarget.hide();
            $searchInput.prop('disabled', true);
            $redirectTarget.find('.htax-pill').remove();
            $redirectTarget.find('input[type="hidden"]').val('');
            $searchInput.attr('placeholder', $searchInput.data('placeholder-choose') || 'Choose redirect target...');

            // Remove the disabled style PHP renders on the Include Tags merged container
            // when the term is a redirect (disabled:true → opacity:0.6;pointer-events:none).
            $addFormFields.find('.htax-merged-container').css({ 'opacity': '', 'pointer-events': '' });

            // Initialise containers that were deferred because they were hidden on page load.
            $addFormFields.find('.htax-merged-container').each(function() {
                var $c = $(this);
                if ( $c.data('htax-inited') ) { return; }
                $c.data('htax-inited', true);
                try { initMergedContainer($c); } catch(e) { window.console && console.error('HT deferred init (merged):', e); }
            });
            $addFormFields.find('.htax-tag-container').not('.htax-merged-container').each(function() {
                var $c = $(this);
                if ( $c.data('htax-inited') ) { return; }
                $c.data('htax-inited', true);
                try { initStandardContainer($c); } catch(e) { window.console && console.error('HT deferred init (standard):', e); }
            });
        }
    }

    $('#htax_is_redirect').on('change', function() {
        applyRedirectToggle($(this).is(':checked'));
    });

    $('#htax_redirect_taxonomy').on('change', function() {
        var $redirectContainer = $('.htax-redirect-container');
        $redirectContainer.data('taxonomy', $(this).val()).attr('data-taxonomy', $(this).val());
        $redirectContainer.find('.htax-pill').remove();
        $redirectContainer.find('input[type="hidden"]').val('');
        $redirectContainer.find('.htax-search').prop('disabled', false).autocomplete('destroy');
        initStandardContainer($redirectContainer);
    });

    // ============================================================================
    // STANDARD CONTAINER  (one hidden input, one type of item)
    // ============================================================================

    function initStandardContainer($container) {
        var $input      = $container.find('.htax-search');
        var $hidden     = $container.find('input[type="hidden"]');
        var taxonomy    = $container.data('taxonomy');
        var currentId   = $container.data('current');
        var target      = $input.data('target');
        var action      = $container.data('action') || 'htax_search_terms';

        $input.autocomplete({
            source: function(req, resp) {
                if (req.term.length < AUTOCOMPLETE_CONFIG.minSearchLength) { resp([]); return; }
                $.ajax({
                    url: HTAX_Ajax.ajax_url,
                    dataType: 'json',
                    data: {
                        action:   action,
                        term:     req.term,
                        taxonomy: taxonomy,
                        exclude:  getExistingIds($container),
                        current:  currentId,
                        nonce:    HTAX_Ajax.nonce
                    },
                    success: resp,
                    error:   function() { resp([]); }
                });
            },
            select: function(e, ui) {
                if (target === 'redirect') {
                    $container.find('.htax-pill').remove();
                }
                addStandardPill($container, ui.item.id, ui.item.value, 'htax-pill');
                $input.val('');
                if ($container.data('demand-search')) {
                    $input.hide();
                    $container.find('.htax-term-add-btn').show();
                }
                updateStandardHidden($container);
                if (target === 'redirect') {
                    $hidden.val(ui.item.id);
                    $input.val('').hide();
                    // Single-value: pill exists, keep "+" hidden.
                }
                updateSynonymHighlighting();
                if ($container.closest('.htax-exclude-panel').length) {
                    syncExcludeOverrides($container.closest('[data-panel]'));
                }
                return false;
            },
            minLength: AUTOCOMPLETE_CONFIG.minSearchLength,
            delay:     AUTOCOMPLETE_CONFIG.delay
        }).data('ui-autocomplete')._renderItem = function(ul, item) {
            return $('<li>').append('<div>' + item.label + '</div>').appendTo(ul);
        };

        setupKeyboardNav($container, $input);

        $container.on('click', '.htax-remove', function(e) {
            e.preventDefault(); e.stopPropagation();
            var $pill          = $(this).closest('.htax-pill');
            var isExcludePanel = $container.closest('.htax-exclude-panel').length > 0;
            var $panel         = isExcludePanel ? $container.closest('[data-panel]') : null;
            $pill.fadeOut(200, function() {
                $(this).remove();
                updateStandardHidden($container);
                updateSynonymHighlighting();
                if (isExcludePanel && $panel) {
                    rerenderCachedGroupMembers($panel);
                    syncExcludeOverrides($panel);
                }
                // Redirect field is single-value: pill deleted → restore "+" and hide input.
                if (target === 'redirect') {
                    $hidden.val('');
                    $input.hide().prop( 'disabled', true );
                    $container.find('.htax-term-add-btn').show();
                }
            });
        });

        $container.on('click', '.htax-pill', function(e) {
            if ($(e.target).closest('.htax-remove').length) return;
            $container.find('.htax-pill').removeClass('selected');
            $(this).addClass('selected');
        });

        $container.on('click', function(e) {
            if (!$(e.target).closest('.htax-pill').length && !$(e.target).hasClass('htax-search')) {
                $container.find('.htax-pill').removeClass('selected');
                if (!$container.data('demand-search')) {
                    $container.find('.htax-search').focus();
                }
            }
        });

        if ($container.data('demand-search')) {
            $container.on('blur', '.htax-search', function() {
                if ( ! $(this).val() ) {
                    $(this).hide();
                    $container.find('.htax-term-add-btn').show();
                }
            });
        }

        // Redirect init: PHP renders the correct initial state.
        // Pill exists  → "+" hidden (PHP), input hidden (PHP) — no JS action needed.
        // No pill      → "+" shown (PHP), input hidden (PHP) — no JS action needed.
        // The else-branch that hid "+" and showed input is intentionally removed.
    }

    function addStandardPill($container, id, name, cssClass) {
        if ($container.find('[data-id="' + id + '"]').length) return;
        var $pill = $('<span class="' + (cssClass || 'htax-pill') + '" data-id="' + id + '" tabindex="0"></span>');
        $pill.append(document.createTextNode(name));
        $pill.append('<button type="button" class="htax-remove" aria-label="Remove" tabindex="-1">' +
            '<span class="screen-reader-text">Remove term:</span>' +
            '<span aria-hidden="true">&times;</span></button>');
        $pill.hide();
        $container.find('.htax-search').before($pill);
        $pill.fadeIn(200);
        sortPillsAlphabetically($container);
        updateSynonymHighlighting();
    }

    function updateStandardHidden($container) {
        var ids = [];
        $container.find('.htax-pill').not('.redirected').each(function() {
            ids.push($(this).data('id'));
        });
        $container.find('input[type="hidden"]').val(ids.join(','));
    }

    // ============================================================================
    // SYMMETRIC CONTAINER  (single panel, fwd + rev pills with direction arrows)
    // ============================================================================

    function initSymContainer($container) {
        var $input    = $container.find('.htax-merged-search');
        var currentId = $container.data('current');
        var action    = $container.data('action') || 'htax_search_terms';
        var taxonomies = ($container.attr('data-taxonomies') || '').split(',').filter(Boolean);

        // Move the "+" button to the front so it stays fixed as pills are added.
        var $addBtn = $container.find('.htax-term-add-btn');
        if ( $addBtn.length ) { $container.prepend( $addBtn ); }

        function getSymTermIds() {
            var ids = [];
            $container.find('.htax-pill').each(function() {
                ids.push(parseInt($(this).data('id')));
            });
            return ids;
        }

        function updateSymHiddenInputs() {
            var fwdIds = [], revIds = [];
            $container.find('.htax-pill').each(function() {
                var id  = parseInt($(this).data('id'));
                var dir = $(this).data('dir');
                if (dir === 'rev') { revIds.push(id); } else { fwdIds.push(id); }
            });
            $container.find('input[type="hidden"]').each(function() {
                var name = $(this).attr('name') || '';
                if (name.indexOf('_rev_terms') !== -1) {
                    $(this).val(revIds.join(','));
                } else if (name.indexOf('_fwd_terms') !== -1) {
                    $(this).val(fwdIds.join(','));
                }
            });
        }

        function makeSymPill(termId, termName, dir) {
            var arrow = dir === 'rev' ? '\u2190' : '\u2192';
            var $pill = $('<span class="htax-pill" tabindex="0"></span>')
                .attr('data-id', termId)
                .attr('data-direct', '1')
                .attr('data-dir', dir);
            $pill.append(
                document.createTextNode(termName),
                $('<span class="htax-sym-arrow" aria-hidden="true"></span>').text(arrow),
                $('<button type="button" class="htax-remove" tabindex="-1"></button>')
                    .attr('aria-label', 'Remove ' + termName)
                    .html('<span aria-hidden="true">&times;</span>')
            );
            return $pill;
        }

        // Autocomplete: search terms, exclude all existing pills (both directions).
        $input.autocomplete({
            source: function(req, resp) {
                if (req.term.length < AUTOCOMPLETE_CONFIG.minSearchLength) { resp([]); return; }
                $.ajax({
                    url: HTAX_Ajax.ajax_url,
                    dataType: 'json',
                    data: {
                        action:         action,
                        term:           req.term,
                        'taxonomies[]': taxonomies,
                        exclude_terms:  getSymTermIds(),
                        current:        currentId,
                        nonce:          HTAX_Ajax.nonce
                    },
                    success: function(data) {
                        resp((data || []).map(function(item) {
                            return { id: item.id, value: item.value, label: item.label, item_type: item.item_type || 'term' };
                        }));
                    },
                    error: function() { resp([]); }
                });
            },
            select: function(e, ui) {
                // Duplicates rejected: same term can't appear twice (getSymTermIds covers both dirs).
                if ($container.find('.htax-pill[data-id="' + ui.item.id + '"]').length) {
                    $input.val('').hide();
                    $container.find('.htax-term-add-btn').show();
                    return false;
                }
                var $pill = makeSymPill(ui.item.id, ui.item.value, 'fwd');
                $container.find('.htax-merged-search').before($pill);
                $pill.hide().fadeIn(200);
                $input.val('').hide();
                $container.find('.htax-term-add-btn').show();
                updateSymHiddenInputs();
                return false;
            },
            minLength: AUTOCOMPLETE_CONFIG.minSearchLength,
            delay:     AUTOCOMPLETE_CONFIG.delay
        }).data('ui-autocomplete')._renderItem = function(ul, item) {
            return $('<li>').append($('<div>').html(item.label)).appendTo(ul);
        };

        setupKeyboardNav($container, $input);

        $container.on('click', '.htax-remove', function(e) {
            e.preventDefault(); e.stopPropagation();
            $(this).closest('.htax-pill').fadeOut(200, function() {
                $(this).remove();
                updateSymHiddenInputs();
            });
        });

        $container.on('blur', '.htax-merged-search', function() {
            if ( ! $(this).val() ) {
                $(this).hide();
                $container.find('.htax-term-add-btn').show();
            }
        });
    }

    // ============================================================================
    // MERGED CONTAINER  (groups section + terms section, two hidden inputs)
    // ============================================================================

    var OVERRIDDEN_TOOLTIP = 'Overridden \u2014 this term is also excluded, so this direct include has no effect on the archive.';

    function initMergedContainer($container) {
        var $input    = $container.find('.htax-merged-search');
        var currentId = $container.data('current');
        var action    = $container.data('action') || 'htax_search_terms';
        var taxonomies = ($container.attr('data-taxonomies') || '').split(',').filter(Boolean);

        // Pre-load group members for any existing group pills so badges are correct.
        loadGroupMembersForContainer($container);

        // Read-only panels (e.g. on group edit screens) render the container without a search input.
        if ( $input.length ) {
            $input.autocomplete({
                source: function(req, resp) {
                    if (req.term.length < AUTOCOMPLETE_CONFIG.minSearchLength) { resp([]); return; }

                    var existingTermIds  = getMergedTermIds($container);
                    var existingGroupIds = getMergedGroupIds($container);

                    $.ajax({
                        url: HTAX_Ajax.ajax_url,
                        dataType: 'json',
                        data: {
                            action:          action,
                            term:            req.term,
                            'taxonomies[]':  taxonomies,
                            exclude_terms:   existingTermIds,
                            exclude_groups:  existingGroupIds,
                            current:         currentId,
                            nonce:           HTAX_Ajax.nonce
                        },
                        success: function(data) {
                            // data is a flat array; label groups with a prefix in the dropdown.
                            var items = (data || []).map(function(item) {
                                return {
                                    id:        item.id,
                                    value:     item.value,
                                    label:     item.item_type === 'group'
                                                   ? '\u25a0 ' + item.label  // filled square prefix
                                                   : item.label,
                                    item_type: item.item_type || 'term'
                                };
                            });
                            resp(items);
                        },
                        error: function() { resp([]); }
                    });
                },
                select: function(e, ui) {
                    if (ui.item.item_type === 'group') {
                        addGroupPillToMerged($container, ui.item.id, ui.item.value);
                    } else {
                        addTermPillToMerged($container, ui.item.id, ui.item.value, true, []);
                    }
                    $input.val('').hide();
                    $container.find('.htax-term-add-btn').show();
                    updateMergedHiddenInputs($container);
                    updateSynonymHighlighting();
                    return false;
                },
                minLength: AUTOCOMPLETE_CONFIG.minSearchLength,
                delay:     AUTOCOMPLETE_CONFIG.delay
            }).data('ui-autocomplete')._renderItem = function(ul, item) {
                return $('<li>').append('<div>' + item.label + '</div>').appendTo(ul);
            };

            setupKeyboardNav($container, $input);
        }

        // Remove direct pill (group or term).
        $container.on('click', '.htax-remove', function(e) {
            e.preventDefault(); e.stopPropagation();
            var $pill     = $(this).closest('.htax-pill');
            var removedId = $pill.data('id');
            var isGroup   = $pill.hasClass('htax-group-pill');

            $pill.fadeOut(200, function() {
                $(this).remove();

                if (isGroup) {
                    // Remove the group from the local cache.
                    delete HTAX_Ajax.group_terms[removedId];
                    // Re-evaluate indirect pills: any that were ONLY via this group become removable
                    // if they still have other paths, or disappear if no other paths remain.
                    refreshIndirectTermPills($container);
                    toggleGroupSectionLabel($container);
                } else {
                    // Direct term removed: if this term is still a member of an included group,
                    // demote it back to an indirect pill immediately (no save needed).
                    var activeGroupIds = getMergedGroupIds($container);
                    var viaGroups = [];
                    var termName  = '';
                    activeGroupIds.forEach(function(gid) {
                        (HTAX_Ajax.group_terms[gid] || []).forEach(function(m) {
                            if (parseInt(m.id) === removedId) {
                                viaGroups.push(gid);
                                if (!termName) { termName = m.name; }
                            }
                        });
                    });
                    if (viaGroups.length) {
                        addTermPillToMerged($container, removedId, termName, false, viaGroups);
                    }
                }

                updateMergedHiddenInputs($container);
                updateSynonymHighlighting();
            });
        });

        $container.on('click', '.htax-pill', function(e) {
            if ($(e.target).closest('.htax-remove').length) return;
            $container.find('.htax-pill').removeClass('selected');
            $(this).addClass('selected');
        });

        $container.on('click', function(e) {
            if (!$(e.target).closest('.htax-pill').length) {
                $container.find('.htax-pill').removeClass('selected');
            }
        });

        // Hide the search input when focus leaves it (user clicked elsewhere or pressed Tab).
        $container.on('blur', '.htax-merged-search', function() {
            if ( ! $(this).val() ) {
                $(this).hide();
                $container.find('.htax-term-add-btn').show();
            }
        });
    }

    function addGroupPillToMerged($container, groupId, groupName) {
        groupId = parseInt(groupId);
        if ($container.find('.htax-group-pill[data-id="' + groupId + '"]').length) return;

        // Register name so tooltip lookups work for dynamically added indirect pills.
        HTAX_Ajax.group_names[groupId] = groupName;

        var $pill = $('<span class="htax-pill htax-group-pill" data-id="' + groupId + '" tabindex="0"></span>');
        $pill.append(document.createTextNode(groupName));
        $pill.append('<button type="button" class="htax-remove" aria-label="Remove group" tabindex="-1">' +
            '<span class="screen-reader-text">Remove group:</span>' +
            '<span aria-hidden="true">&times;</span></button>');
        $pill.hide();

        // Insert after existing group pills but before the terms section label.
        var $termLabel = $container.find('.htax-section-terms');
        $termLabel.before($pill);
        $pill.fadeIn(200);

        toggleGroupSectionLabel($container);

        // Fetch and display members.
        fetchGroupMembers($container, groupId);
    }

    function addTermPillToMerged($container, termId, termName, isDirect, viaGroupIds, viaContainerGroupIds) {
        termId               = parseInt(termId);
        viaGroupIds          = (viaGroupIds || []).map(Number);
        viaContainerGroupIds = (viaContainerGroupIds || []).map(Number);

        var $existing = $container.find('.htax-pill[data-id="' + termId + '"]:not(.htax-group-pill)');
        if ($existing.length) {
            // Already present — merge path information.
            var curDirect    = !!$existing.data('direct');
            var curVia       = ($existing.data('via-groups') || '').toString().split(',').filter(Boolean).map(Number);
            var curContVia   = ($existing.data('via-container-groups') || '').toString().split(',').filter(Boolean).map(Number);

            if (isDirect && !curDirect) {
                $existing.attr('data-direct', '1').data('direct', 1);
                $existing.removeClass('htax-indirect');
                // Promote to direct: indirect pills have no remove button, add one now.
                $existing.append('<button type="button" class="htax-remove" aria-label="Remove" tabindex="-1">' +
                    '<span class="screen-reader-text">Remove term:</span>' +
                    '<span aria-hidden="true">&times;</span></button>');
                // Mark as overridden if the term is also excluded.
                if (isTermExcluded(termId, $container)) {
                    $existing.addClass('htax-overridden').attr('data-overridden', '1');
                    rebuildDataTooltip($existing);
                }
                // Move pill from indirect section to direct section.
                var $indirectLabelForMove = $container.find('.htax-section-indirect');
                $indirectLabelForMove.before($existing);
                sortSectionPills($container.find('.htax-section-terms'), $indirectLabelForMove);
                toggleIndirectSectionLabel($container);
            }
            viaGroupIds.forEach(function(gid) {
                if (curVia.indexOf(gid) === -1) curVia.push(gid);
            });
            viaContainerGroupIds.forEach(function(gid) {
                if (curContVia.indexOf(gid) === -1) curContVia.push(gid);
            });
            var newPaths    = (isDirect || curDirect ? 1 : 0) + curVia.length + curContVia.length;
            var pathsChanged = newPaths !== parseInt($existing.data('paths') || 1);
            $existing.attr('data-paths', newPaths).data('paths', newPaths);
            $existing.attr('data-via-groups', curVia.join(',')).data('via-groups', curVia.join(','));
            $existing.attr('data-via-container-groups', curContVia.join(',')).data('via-container-groups', curContVia.join(','));
            // Force tooltip refresh only when path count changed (new group added/removed).
            // Otherwise preserve the PHP-rendered title.
            refreshBadge($existing, pathsChanged);
            return;
        }

        var totalPaths   = (isDirect ? 1 : 0) + viaGroupIds.length + viaContainerGroupIds.length;
        var isOverridden = isDirect && isTermExcluded(termId, $container);

        // Don't show an indirect pill for an excluded term — it's not in the archive.
        if (!isDirect && isTermExcluded(termId, $container)) {
            return;
        }

        var $pill = $('<span class="htax-pill' + (isDirect ? '' : ' htax-indirect') + (isOverridden ? ' htax-overridden' : '') + '" ' +
            'data-id="' + termId + '" ' +
            'data-paths="' + totalPaths + '" ' +
            (isDirect ? 'data-direct="1" ' : '') +
            (isOverridden ? 'data-overridden="1" ' : '') +
            (viaGroupIds.length ? 'data-via-groups="' + viaGroupIds.join(',') + '" ' : '') +
            (viaContainerGroupIds.length ? 'data-via-container-groups="' + viaContainerGroupIds.join(',') + '" ' : '') +
            'tabindex="0"></span>');

        // Set badge (count) and tooltip for this freshly created pill.
        refreshBadge($pill, true);
        $pill.append(document.createTextNode(termName));

        if (isDirect) {
            $pill.append('<button type="button" class="htax-remove" aria-label="Remove" tabindex="-1">' +
                '<span class="screen-reader-text">Remove term:</span>' +
                '<span aria-hidden="true">&times;</span></button>');
        }

        $pill.hide();
        var $indirectLabel = $container.find('.htax-section-indirect');
        var $searchAnchor  = $container.find('.htax-merged-search');
        if (isDirect) {
            $indirectLabel.before($pill);
            $pill.fadeIn(200);
            sortSectionPills($container.find('.htax-section-terms'), $indirectLabel);
        } else {
            $indirectLabel.after($pill);
            sortIndirectPills($container);
            $pill.fadeIn(200);
        }
        toggleIndirectSectionLabel($container);
    }

    // Sort all .htax-indirect pills into alphabetical order after the indirect section label.
    // Uses .find() across the whole container so pills are found regardless of where they
    // currently sit — needed because insertion and demotion can leave them scattered.
    function sortIndirectPills($container) {
        var $label = $container.find('.htax-section-indirect');
        if (!$label.length) { return; }
        var $pills = $container.find('.htax-pill.htax-indirect').detach();
        var arr    = $pills.toArray();
        arr.sort(function(a, b) {
            var aName = $(a).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            var bName = $(b).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            return aName.localeCompare(bName);
        });
        $label.after(arr);
    }

    function refreshBadge($pill, forceTooltip) {
        var paths        = parseInt($pill.data('paths') || 1);
        var isOverridden = !!parseInt($pill.data('overridden') || 0);

        // Badge: count indicator, only when multiple paths exist.
        $pill.find('.htax-badge').remove();
        if (paths > 1) {
            $pill.prepend('<span class="htax-badge">' + paths + '</span>');
        }

        // Rebuild data-tooltip JSON when paths change (new pill or group added/removed).
        // PHP-rendered pills already have correct data-tooltip on page load.
        if (forceTooltip) {
            rebuildDataTooltip($pill);
        }
    }

    function rebuildDataTooltip($pill) {
        var viaGroups     = ($pill.data('via-groups') || '').toString().split(',').filter(Boolean).map(Number);
        var viaContGroups = ($pill.data('via-container-groups') || '').toString().split(',').filter(Boolean).map(Number);
        var isOverridden  = !!parseInt($pill.data('overridden') || 0);

        // Preserve custom-type entries (tab-switch links) — JS can't reconstruct them.
        var existing;
        try { existing = JSON.parse($pill.attr('data-tooltip') || '[]'); } catch(e) { existing = []; }
        var customEntries = existing.filter(function(e) { return e.type === 'custom'; });

        var entries = [];
        viaGroups.forEach(function(gid) {
            entries.push({ type: 'group', dir: 'fwd',
                name: HTAX_Ajax.group_names[gid] || ('#' + gid),
                url:  HTAX_Ajax.group_edit_base_url ? (HTAX_Ajax.group_edit_base_url + gid) : '' });
        });
        viaContGroups.forEach(function(gid) {
            entries.push({ type: 'group', dir: 'rev',
                name: HTAX_Ajax.group_names[gid] || ('#' + gid),
                url:  HTAX_Ajax.group_edit_base_url ? (HTAX_Ajax.group_edit_base_url + gid) : '' });
        });
        entries = entries.concat(customEntries);
        if (isOverridden) {
            entries.push({ type: 'text', text: OVERRIDDEN_TOOLTIP });
        }

        if (entries.length) {
            $pill.attr('data-tooltip', JSON.stringify(entries));
        } else {
            $pill.removeAttr('data-tooltip');
        }
        $pill.removeAttr('title');
    }


    function escAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function refreshIndirectTermPills($container) {
        // After a group is removed, recalculate via-groups data for each term pill.
        // via-container-groups are static (based on term's own memberships) — preserve them.
        var activeGroupIds = getMergedGroupIds($container);

        $container.find('.htax-pill').not('.htax-group-pill').each(function() {
            var $pill        = $(this);
            var termId       = parseInt($pill.data('id'));
            var isDirect     = !!parseInt($pill.data('direct') || 0);
            var viaContGroups = ($pill.data('via-container-groups') || '').toString().split(',').filter(Boolean).map(Number);

            // Determine which of the current active groups still contain this term.
            var stillVia = [];
            activeGroupIds.forEach(function(gid) {
                var members = HTAX_Ajax.group_terms[gid] || [];
                var found   = members.some(function(m) { return parseInt(m.id) === termId; });
                if (found) stillVia.push(gid);
            });

            if (!isDirect && stillVia.length === 0 && viaContGroups.length === 0) {
                // No longer reachable via any path — remove.
                $pill.remove();
                return;
            }

            var newPaths = (isDirect ? 1 : 0) + stillVia.length + viaContGroups.length;
            $pill.attr('data-paths', newPaths).data('paths', newPaths);
            $pill.attr('data-via-groups', stillVia.join(',')).data('via-groups', stillVia.join(','));
            refreshBadge($pill);
        });

        // Also update section label visibility.
        toggleGroupSectionLabel($container);
        toggleIndirectSectionLabel($container);
    }

    function toggleGroupSectionLabel($container) {
        var $groupLabel = $container.find('.htax-section-groups');
        var hasGroups   = $container.find('.htax-group-pill').length > 0;
        $groupLabel.toggle(hasGroups);
    }

    function toggleIndirectSectionLabel($container) {
        var $label      = $container.find('.htax-section-indirect');
        var hasIndirect = $container.find('.htax-pill.htax-indirect').length > 0;
        $label.toggle(hasIndirect);
    }

    function sortSectionPills($sectionStart, $sectionEnd) {
        var $pills = $sectionStart.nextUntil($sectionEnd, '.htax-pill').detach();
        var arr    = $pills.toArray();
        arr.sort(function(a, b) {
            var aName = $(a).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            var bName = $(b).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            return aName.localeCompare(bName);
        });
        $sectionEnd.before(arr);
    }

    function isTermExcluded(termId, $context) {
        return $context.closest('.htax-rel-tab-panel')
            .find('.htax-exclude-panel .htax-tag-container .htax-pill[data-id="' + termId + '"]')
            .length > 0;
    }

    function syncExcludeOverrides($panel) {
        var excludedIds = {};
        $panel.find('.htax-exclude-panel .htax-tag-container .htax-pill').each(function() {
            excludedIds[parseInt($(this).data('id'))] = true;
        });

        $panel.find('.htax-merged-container').each(function() {
            var $mc = $(this);
            $mc.find('.htax-pill').not('.htax-group-pill').each(function() {
                var $pill      = $(this);
                var termId     = parseInt($pill.data('id'));
                var isDirect   = !!parseInt($pill.data('direct') || 0);
                var isExcluded = !!excludedIds[termId];

                if (!isDirect) {
                    if (isExcluded) { $pill.remove(); }
                } else {
                    $pill.toggleClass('htax-overridden', isExcluded);
                    $pill.attr('data-overridden', isExcluded ? '1' : null);
                    rebuildDataTooltip($pill);
                }
            });
            toggleIndirectSectionLabel($mc);
        });
    }

    function rerenderCachedGroupMembers($panel) {
        $panel.find('.htax-merged-container').each(function() {
            var $container = $(this);
            getMergedGroupIds($container).forEach(function(gid) {
                if (HTAX_Ajax.group_terms[gid]) {
                    renderGroupMemberPills($container, gid, HTAX_Ajax.group_terms[gid]);
                }
            });
        });
    }

    function fetchGroupMembers($container, groupId) {
        var taxonomy = $container.data('taxonomy');
        groupId = parseInt(groupId);

        if (HTAX_Ajax.group_terms[groupId]) {
            // Already cached — render immediately.
            renderGroupMemberPills($container, groupId, HTAX_Ajax.group_terms[groupId]);
            return;
        }

        $.ajax({
            url:      HTAX_Ajax.ajax_url,
            dataType: 'json',
            data: {
                action:    'htax_get_group_terms',
                group_ids: [groupId],
                taxonomy:  taxonomy,
                nonce:     HTAX_Ajax.nonce
            },
            success: function(data) {
                if (data && data.success && data.data && data.data[groupId]) {
                    HTAX_Ajax.group_terms[groupId] = data.data[groupId];
                    renderGroupMemberPills($container, groupId, data.data[groupId]);
                    updateMergedHiddenInputs($container);
                    updateSynonymHighlighting();
                }
            }
        });
    }

    function renderGroupMemberPills($container, groupId, members) {
        var currentId = parseInt($container.data('current') || 0);
        (members || []).forEach(function(member) {
            var memberId   = parseInt(member.id);
            if (currentId && memberId === currentId) { return; }
            var memberName = member.name;
            var isDirect = !!$container.find('.htax-pill[data-id="' + memberId + '"][data-direct]').length;
            addTermPillToMerged($container, memberId, memberName, isDirect, [groupId]);
        });
    }

    function loadGroupMembersForContainer($container) {
        var groupIds = getMergedGroupIds($container);
        if (!groupIds.length) return;

        var taxonomy = $container.data('taxonomy');
        var toFetch  = groupIds.filter(function(gid) { return !HTAX_Ajax.group_terms[gid]; });

        if (!toFetch.length) {
            // All cached — just refresh indirect pills.
            groupIds.forEach(function(gid) {
                renderGroupMemberPills($container, gid, HTAX_Ajax.group_terms[gid]);
            });
            return;
        }

        $.ajax({
            url:      HTAX_Ajax.ajax_url,
            dataType: 'json',
            data: {
                action:    'htax_get_group_terms',
                group_ids: toFetch,
                taxonomy:  taxonomy,
                nonce:     HTAX_Ajax.nonce
            },
            success: function(data) {
                if (data && data.success && data.data) {
                    $.extend(HTAX_Ajax.group_terms, data.data);
                    groupIds.forEach(function(gid) {
                        if (HTAX_Ajax.group_terms[gid]) {
                            renderGroupMemberPills($container, gid, HTAX_Ajax.group_terms[gid]);
                        }
                    });
                    updateMergedHiddenInputs($container);
                    updateSynonymHighlighting();
                }
            }
        });
    }

    function getMergedTermIds($container) {
        var ids = [];
        $container.find('.htax-pill').not('.htax-group-pill').each(function() {
            ids.push(parseInt($(this).data('id')));
        });
        return ids;
    }

    function getMergedGroupIds($container) {
        var ids = [];
        $container.find('.htax-group-pill').each(function() {
            ids.push(parseInt($(this).data('id')));
        });
        return ids;
    }

    function updateMergedHiddenInputs($container) {
        // Terms hidden input: only DIRECT term pills.
        var directTermIds = [];
        $container.find('.htax-pill').not('.htax-group-pill').each(function() {
            if ($(this).data('direct')) {
                directTermIds.push($(this).data('id'));
            }
        });

        // Groups hidden input.
        var groupIds = getMergedGroupIds($container);

        // Find both hidden inputs.
        $container.find('input[type="hidden"]').each(function() {
            var name = $(this).attr('name') || '';
            if (name.indexOf('_groups') !== -1) {
                $(this).val(groupIds.join(','));
            } else {
                $(this).val(directTermIds.join(','));
            }
        });
    }

    // ============================================================================
    // SYNONYM HIGHLIGHTING  (cross-container bidirectionality)
    // ============================================================================

    function updateSynonymHighlighting() {
        // Collect all direct term IDs in the "includes" forward container.
        var $incl = $('[name="htax_includes"]').closest('.htax-merged-container, .htax-tag-container');
        var $cont = $('[name="htax_containers"]').closest('.htax-merged-container, .htax-tag-container');

        var inclIds = [];
        $incl.find('.htax-pill').not('.htax-group-pill').each(function() {
            inclIds.push($(this).data('id').toString());
        });
        var contIds = [];
        $cont.find('.htax-pill').not('.htax-group-pill').each(function() {
            contIds.push($(this).data('id').toString());
        });

        // Also gather terms implied via included groups.
        // wp_localize_script may encode as object ({0:id,...}) instead of array when keys are non-sequential.
        var incl_via_group_ids = [];
        if (HTAX_Ajax.terms_from_groups) {
            var _tfg = HTAX_Ajax.terms_from_groups;
            var _tfgArr = Array.isArray(_tfg) ? _tfg : Object.values(_tfg);
            incl_via_group_ids = _tfgArr.map(function(id) { return id.toString(); });
        }
        $incl.find('.htax-group-pill').each(function() {
            var gid = parseInt($(this).data('id'));
            (HTAX_Ajax.group_terms[gid] || []).forEach(function(m) {
                incl_via_group_ids.push(m.id.toString());
            });
        });

        var allInclIds = inclIds.concat(incl_via_group_ids.filter(function(id) { return inclIds.indexOf(id) === -1; }));

        // Apply synonym class and icon.
        $incl.find('.htax-pill').not('.htax-group-pill').each(function() {
            var id = $(this).data('id').toString();
            var isSynonym = contIds.indexOf(id) !== -1;
            $(this).toggleClass('synonym', isSynonym);
            toggleSynonymIcon($(this), isSynonym);
        });
        $cont.find('.htax-pill').not('.htax-group-pill').each(function() {
            var id = $(this).data('id').toString();
            var isSynonym = allInclIds.indexOf(id) !== -1;
            $(this).toggleClass('synonym', isSynonym);
            toggleSynonymIcon($(this), isSynonym);
        });
    }

    function toggleSynonymIcon($pill, isSynonym) {
        $pill.find('.htax-synonym-icon').remove();
        // A pill with a redirect indicator (↩/↪) has no active archive; synonym is meaningless.
        if (isSynonym && !$pill.find('.redirect-indicator').length) {
            var $btn = $pill.find('.htax-remove').first();
            var $icon = $('<span class="htax-synonym-icon dashicons dashicons-update" aria-label="Synonym (bidirectional)"></span>');
            if ($btn.length) {
                $icon.insertBefore($btn);
            } else {
                $pill.append($icon);
            }
        }
    }

    // ============================================================================
    // SHARED HELPERS
    // ============================================================================

    function getExistingIds($container) {
        var ids = [];
        $container.find('.htax-pill').each(function() {
            ids.push($(this).data('id'));
        });
        return ids;
    }

    function sortPillsAlphabetically($container) {
        var $pills = $container.find('.htax-pill').not('.redirected').detach();
        var arr    = $pills.toArray();
        arr.sort(function(a, b) {
            var aName = $(a).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            var bName = $(b).contents().filter(function() { return this.nodeType === 3; }).first().text().trim();
            return aName.localeCompare(bName);
        });
        $container.find('.htax-search').before(arr);
    }

    function setupKeyboardNav($container, $input) {
        $input.on('keydown', function(e) {
            var $pills = $container.find('.htax-pill').not('.redirected');
            var val    = $input.val();

            if (e.which === 8 && val === '' && $pills.length) {         // Backspace
                var $last = $pills.last();
                if ($last.hasClass('selected')) {
                    $last.find('.htax-remove').trigger('click');
                } else {
                    $pills.removeClass('selected');
                    $last.addClass('selected');
                }
                e.preventDefault();
            } else if (e.which === 37 && val === '' && $pills.length) { // Left
                var $sel = $container.find('.htax-pill.selected');
                if (!$sel.length) {
                    $pills.last().addClass('selected');
                } else {
                    var $prev = $sel.prev('.htax-pill').not('.redirected');
                    if ($prev.length) { $sel.removeClass('selected'); $prev.addClass('selected'); }
                }
                e.preventDefault();
            } else if (e.which === 39) {                                 // Right
                var $selR = $container.find('.htax-pill.selected');
                if ($selR.length) {
                    var $next = $selR.next('.htax-pill').not('.redirected');
                    if ($next.length) { $selR.removeClass('selected'); $next.addClass('selected'); }
                    else { $selR.removeClass('selected'); }
                    e.preventDefault();
                }
            } else if (e.which === 46) {                                 // Delete
                var $selD = $container.find('.htax-pill.selected');
                if ($selD.length && !$selD.hasClass('redirected')) {
                    $selD.find('.htax-remove').trigger('click');
                    e.preventDefault();
                }
            } else {
                $container.find('.htax-pill').removeClass('selected');
            }
        });
    }

    // ============================================================================
    // MAKE VISIBLE CASCADE PROMPT
    // ============================================================================

    if (HTAX_Ajax.isHierarchical && HTAX_Ajax.visibilityNonce &&
            HTAX_Ajax.isCurrentlyHidden && HTAX_Ajax.hiddenDescendantCount > 0) {

        $('#edittag').on('submit.htaxmv', function(e) {
            var $form = $(this);
            if ($form.data('htax-mv-ready')) {
                $form.removeData('htax-mv-ready');
                return;
            }
            if ($('#htax_is_hidden').prop('checked')) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            var count    = parseInt(HTAX_Ajax.hiddenDescendantCount, 10) || 0;
            var descText = count === 1
                ? '1 descendant term is also hidden. Make it visible too?'
                : count + ' descendant terms are also hidden. Make them visible too?';

            showTermEditMakeVisibleModal(descText, function(cascade) {
                if (cascade) {
                    $form.find('input[name="htax_cascade_visible"]').remove();
                    $form.append('<input type="hidden" name="htax_cascade_visible" value="1">');
                }
                $form.data('htax-mv-ready', true).submit();
            });
        });
    }

    function showTermEditMakeVisibleModal(descText, callback) {
        if (!$('#htax-te-mv-modal').length) {
            $('body').append(
                '<div id="htax-te-mv-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100001;overflow:auto;">' +
                    '<div style="position:relative;background:#fff;margin:100px auto;max-width:520px;border-radius:4px;box-shadow:0 5px 15px rgba(0,0,0,0.5);">' +
                        '<div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">' +
                            '<h2 style="margin:0;font-size:18px;">Make Visible</h2>' +
                            '<button type="button" class="htax-te-mv-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0;width:30px;height:30px;">&times;</button>' +
                        '</div>' +
                        '<div style="padding:20px;"><p style="margin-top:0;" id="htax-te-mv-body"></p></div>' +
                        '<div style="padding:12px 20px;border-top:1px solid #ddd;text-align:right;background:#f5f5f5;">' +
                            '<button type="button" class="button button-secondary htax-te-mv-no" style="margin-right:8px;">No, this term only</button>' +
                            '<button type="button" class="button button-primary htax-te-mv-yes">Yes, also make descendants visible</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        }
        $('#htax-te-mv-body').text(descText);
        $('#htax-te-mv-modal .htax-te-mv-close, #htax-te-mv-modal .htax-te-mv-no')
            .off('click.htaxtemv').on('click.htaxtemv', function() { $('#htax-te-mv-modal').fadeOut(150); callback(false); });
        $('#htax-te-mv-modal .htax-te-mv-yes')
            .off('click.htaxtemv').on('click.htaxtemv', function() { $('#htax-te-mv-modal').fadeOut(150); callback(true); });
        $('#htax-te-mv-modal').fadeIn(200);
    }

    // ============================================================================
    // INITIAL STATE
    // ============================================================================

    // On page load, expand group members for merged containers that have groups pre-rendered.
    // (PHP already renders the group pills and initial direct term pills; this call adds
    //  the read-only indirect pills for each pre-existing group.)
    updateSynonymHighlighting();

    // ============================================================================
    // GROUP CHECKLIST DROPDOWNS — floating pill+checklist for all group fields
    // ============================================================================

    var $activeDropdown = null;

    // Open/close the floating dropdown near the + button.
    $(document).on('click', '.htax-group-add-btn', function ( e ) {
        e.stopPropagation();
        var $btn = $( this );
        var ddId = $btn.data( 'dropdown' );
        var $dd  = $( '#' + ddId );
        if ( ! $dd.length ) { return; }

        if ( $dd.is( ':visible' ) ) {
            $dd.hide();
            $activeDropdown = null;
            return;
        }

        // Close any other open dropdown.
        if ( $activeDropdown ) { $activeDropdown.hide(); }

        // Position fixed near the button.
        var rect = $btn[0].getBoundingClientRect();
        $dd.css( {
            top:  ( rect.bottom + 4 ) + 'px',
            left: Math.max( 4, rect.left ) + 'px'
        } ).show();
        $activeDropdown = $dd;

        // Focus the filter input so the user can type immediately.
        setTimeout( function () {
            $dd.find( '.htax-checklist-search' ).val( '' ).focus();
        }, 50 );
    });

    // Close on outside click or Escape — skip the + button that just opened the dropdown and
    // skip clicks inside any open dropdown (stopPropagation on a document-delegated
    // handler can't reliably prevent this plain handler from running first).
    $(document).on('click', function ( e ) {
        if ( $activeDropdown ) {
            if ( $( e.target ).closest( '.htax-group-add-btn, .htax-group-dropdown' ).length ) {
                return;
            }
            $activeDropdown.hide();
            $activeDropdown = null;
        }
    });
    $(document).on('keydown.htaxTermEditEscape', function ( e ) {
        if ( ( e.key === 'Escape' || e.keyCode === 27 ) && $activeDropdown ) {
            // Return focus to the + button that opened this dropdown.
            var ddId = $activeDropdown.attr( 'id' );
            $activeDropdown.hide();
            $activeDropdown = null;
            if ( ddId ) {
                $( '.htax-group-add-btn[data-dropdown="' + ddId + '"]' ).trigger( 'focus' );
            }
        }
    });

    // Term + button — swap itself for the search input (prevents layout-shift height jump).
    // For multi-value containers the + is restored after each selection.
    // For single-value containers (redirect) the + stays hidden while a pill exists.
    $(document).on('click', '.htax-term-add-btn', function () {
        var $btn       = $( this );
        var $container = $btn.closest( '.htax-merged-container, .htax-tag-container' );
        $btn.hide();
        $container.find( '.htax-search' ).prop( 'disabled', false ).show().focus();
    });

    // ---- Helpers ----

    // Build a green member-group pill element.
    function makeMemberGroupPill( groupId, label ) {
        return $( '<span class="htax-pill htax-member-group-pill" tabindex="0"></span>' )
            .attr( 'data-id', groupId )
            .append( document.createTextNode( label ) )
            .append(
                $( '<button type="button" class="htax-remove" tabindex="-1"></button>' )
                    .attr( 'aria-label', 'Remove from group ' + label )
                    .append( '<span class="screen-reader-text">Remove:</span>' )
                    .append( '<span aria-hidden="true">\xd7</span>' )
            );
    }

    // Build a blue htax-group-pill element (for merged container and container-groups field).
    function makeGroupPill( groupId, label ) {
        return $( '<span class="htax-pill htax-group-pill" tabindex="0"></span>' )
            .attr( 'data-id', groupId )
            .append( document.createTextNode( label ) )
            .append(
                $( '<button type="button" class="htax-remove" tabindex="-1"></button>' )
                    .attr( 'aria-label', 'Remove group ' + label )
                    .append( '<span class="screen-reader-text">Remove:</span>' )
                    .append( '<span aria-hidden="true">\xd7</span>' )
            );
    }

    // Update a merged container's hidden group input from its current group pills.
    function syncMergedGroupInput( $container ) {
        var ids = [];
        $container.find( '.htax-group-pill' ).each( function () {
            ids.push( $( this ).data( 'id' ) );
        } );
        var fieldName = $container.find( 'input[type="hidden"][name$="_groups"]' ).attr( 'name' );
        if ( fieldName ) {
            $container.find( 'input[type="hidden"][name="' + fieldName + '"]' ).val( ids.join( ',' ) );
        }
    }

    // ---- Checkbox change — sync pills with parent-child dedup ----
    $(document).on('change', '.htax-group-dropdown input[type="checkbox"]', function () {
        var $cb     = $( this );
        var groupId = $cb.val();
        if ( groupId === '0' ) { return; } // sentinel
        var label   = $.trim( $cb.closest( 'label' ).text() );
        var ddId    = $cb.closest( '.htax-group-dropdown' ).attr( 'id' );

        if ( $cb.is( ':checked' ) ) {
            syncGroupPillAdd( ddId, groupId, label );
        } else {
            syncGroupPillRemove( ddId, groupId );
        }
    });

    // Add a group pill to whichever container owns this dropdown.
    function syncGroupPillAdd( ddId, groupId, label ) {
        // Member groups field (#htax-member-grp-dd → .htax-member-pills-container).
        if ( ddId === 'htax-member-grp-dd' ) {
            var $container = $( '.htax-member-pills-container' );
            if ( ! $container.find( '.htax-member-group-pill[data-id="' + groupId + '"]' ).length ) {
                $container.find( '.htax-group-add-btn' ).before( makeMemberGroupPill( groupId, label ) );
            }
            return;
        }

        // Container groups field (#htax-cont-grp-dd → .htax-container-groups-section .htax-member-pills-container).
        if ( ddId === 'htax-cont-grp-dd' ) {
            var $container = $( '.htax-container-groups-section .htax-member-pills-container' );
            if ( ! $container.find( '.htax-group-pill[data-id="' + groupId + '"]' ).length ) {
                $container.find( '.htax-group-add-btn' ).before( makeGroupPill( groupId, label ) );
            }
            return;
        }

        // Merged container (data-dropdown matches ddId via button).
        var $btn = $( '.htax-group-add-btn[data-dropdown="' + ddId + '"]' );
        if ( $btn.length ) {
            var $mergedContainer = $btn.closest( '.htax-merged-container' );
            if ( $mergedContainer.length && ! $mergedContainer.find( '.htax-group-pill[data-id="' + groupId + '"]' ).length ) {
                $btn.before( makeGroupPill( groupId, label ) );
                syncMergedGroupInput( $mergedContainer );
                // Trigger indirect pill refresh.
                if ( typeof addGroupPillToMerged === 'function' ) {
                    addGroupPillToMerged( $mergedContainer, parseInt( groupId ), label );
                }
                updateSynonymHighlighting();
            }
        }
    }

    function syncGroupPillRemove( ddId, groupId ) {
        var selector = '.htax-member-group-pill[data-id="' + groupId + '"], .htax-group-pill[data-id="' + groupId + '"]';

        if ( ddId === 'htax-member-grp-dd' ) {
            $( '.htax-member-pills-container' ).find( '.htax-member-group-pill[data-id="' + groupId + '"]' ).fadeOut( 150, function () { $( this ).remove(); } );
            return;
        }

        if ( ddId === 'htax-cont-grp-dd' ) {
            $( '.htax-container-groups-section .htax-member-pills-container' ).find( '.htax-group-pill[data-id="' + groupId + '"]' ).fadeOut( 150, function () { $( this ).remove(); } );
            return;
        }

        var $btn = $( '.htax-group-add-btn[data-dropdown="' + ddId + '"]' );
        if ( $btn.length ) {
            var $mergedContainer = $btn.closest( '.htax-merged-container' );
            $mergedContainer.find( '.htax-group-pill[data-id="' + groupId + '"]' ).fadeOut( 150, function () { $( this ).remove(); } );
            syncMergedGroupInput( $mergedContainer );
        }
    }

    // Remove pill via × on member-group or group pill.
    $(document).on('click', '.htax-member-group-pill .htax-remove', function () {
        var $pill   = $( this ).closest( '.htax-member-group-pill' );
        var groupId = $pill.data( 'id' ).toString();
        $( '#htax-member-grp-dd input[type="checkbox"][value="' + groupId + '"]' ).prop( 'checked', false );
        $pill.fadeOut( 150, function () { $( this ).remove(); } );
    });

    // ============================================================================
    // MERGE-TERMS PAGE — simple autocomplete for .htax-term-autocomplete (single)
    // and .htax-term-autocomplete-multi (source terms list).
    // ============================================================================

    function htaxInitMergeAutocomplete( $input ) {
        var taxFieldId = $input.data('taxonomy-field');
        var idFieldId  = $input.data('id-field');
        var isMulti    = $input.hasClass('htax-term-autocomplete-multi');

        function getTaxonomy() {
            return taxFieldId ? $('#' + taxFieldId).val() : '';
        }

        $input.autocomplete({
            minLength: HTAX_Ajax.min_search_length || 2,
            source: function( request, response ) {
                var tax = getTaxonomy();
                if ( ! tax ) { response([]); return; }
                $.ajax({
                    url:      HTAX_Ajax.ajax_url,
                    dataType: 'json',
                    data:     { action: 'htax_search_terms', taxonomy: tax, term: request.term, nonce: HTAX_Ajax.nonce },
                    success:  function( data ) { response( $.isArray(data) ? data : [] ); },
                    error:    function() { response([]); }
                });
            },
            select: function( event, ui ) {
                event.preventDefault();
                var termId   = ui.item.id   || ui.item.value;
                var termName = ui.item.value || ( $('<div>').html(ui.item.label).text() );

                if ( isMulti ) {
                    var $hidden = $('#' + idFieldId);
                    var existing = $hidden.val() ? $hidden.val().split(',') : [];
                    var existingNames = $input.data('htax-names') || [];
                    if ( existing.indexOf( String(termId) ) === -1 ) {
                        existing.push( String(termId) );
                        existingNames.push( termName );
                        $hidden.val( existing.join(',') );
                        $input.data('htax-names', existingNames);
                    }
                    var _placeholder = existingNames.join(', ');
                    // Use setTimeout so our clear runs after jQuery UI sets the value.
                    setTimeout( function() {
                        $input.val('').attr('placeholder', _placeholder);
                    }, 0 );
                } else {
                    $input.val( termName );
                    $('#' + idFieldId).val( termId );
                }
            },
            focus: function( event ) { event.preventDefault(); }
        }).data('ui-autocomplete')._renderItem = function( ul, item ) {
            return $('<li>').append( $('<div>').html( item.label ) ).appendTo( ul );
        };
    }

    // Initialize on page load.
    $('.htax-term-autocomplete, .htax-term-autocomplete-multi').each(function() {
        htaxInitMergeAutocomplete( $(this) );
    });

    // Re-initialize when taxonomy changes (clearing any prior selection).
    $(document).on('change', '#htax_merge_taxonomy', function() {
        $('.htax-term-autocomplete, .htax-term-autocomplete-multi').each(function() {
            var $i = $(this);
            if ( $i.data('ui-autocomplete') ) { $i.autocomplete('destroy'); }
            $i.val('').removeAttr('placeholder').removeData('htax-names');
            $('#' + $i.data('id-field')).val('');
            htaxInitMergeAutocomplete( $i );
        });
    });

    // ============================================================================
    // VIA-GROUP SECTION TOGGLE — single eye-icon button in "Term Relationships" <th>
    // Hides all htax-indirect pills across all tabs; group pills stay visible as summary.
    // ============================================================================

    $('.htax-via-section-toggle').each(function() {
        var $btn   = $(this);
        var termId = $btn.data('term');
        var key    = 'htax-via-hidden-section-' + termId;
        var hidden = false;
        try { hidden = localStorage.getItem(key) === '1'; } catch(e) {}

        function applyState(on) {
            $btn.closest('.htax-inclusions-field').toggleClass('htax-via-hidden', on);
            $btn.attr('aria-pressed', on ? 'true' : 'false')
                .find('.dashicons')
                .toggleClass('dashicons-hidden',     on)
                .toggleClass('dashicons-visibility', !on);
        }

        applyState(hidden);

        $btn.on('click', function() {
            var newState = $btn.attr('aria-pressed') !== 'true';
            applyState(newState);
            try { localStorage.setItem(key, newState ? '1' : '0'); } catch(e) {}
        });
    });

    // Remove via × on blue htax-group-pill (merged container or container groups field).
    $(document).on('click', '.htax-group-pill .htax-remove', function () {
        var $pill    = $( this ).closest( '.htax-group-pill' );
        var groupId  = $pill.data( 'id' ).toString();
        // Uncheck in whichever dropdown owns this pill.
        $( '.htax-group-dropdown input[type="checkbox"][value="' + groupId + '"]' ).each( function () {
            // Only uncheck the one whose dropdown is associated with a + button in the same container.
            var ddId = $( this ).closest( '.htax-group-dropdown' ).attr( 'id' );
            var $btn = $( '.htax-group-add-btn[data-dropdown="' + ddId + '"]' );
            if ( $btn.closest( '.htax-merged-container, .htax-member-pills-container' ).find( $pill ).length || $btn.length ) {
                $( this ).prop( 'checked', false );
            }
        } );
        $pill.fadeOut( 150, function () {
            var $mc = $( this ).closest( '.htax-merged-container' );
            $( this ).remove();
            if ( $mc.length ) { syncMergedGroupInput( $mc ); }
        } );
    });

    // Update native Description helper text on the term edit form.
    var $descHelper = $( '#description-description' );
    if ( $descHelper.length ) {
        $descHelper.text(
            'The term description can be used for autocomplete suggestions and PowerSearch relevance matching, supplementing term names.'
        );
    }

    // Edit form collapsible — mirrors "More options" on the Add New Term form.
    // Normally Term Groups (priority 8) is always visible and everything else
    // collapses. When Tag Groups' own native field is promoted instead (Locked
    // mode, Tag Groups active — see data-promote-native-groups), that field takes
    // the always-visible spot and Term Groups (now demoted to priority 16) stays
    // in the collapsible content, paired with the native Description either way.
    var $toggleRow  = $( 'tr.htax-edit-form-toggle-row' );
    var $endMarker  = $( 'tr.htax-edit-form-collapse-end' );
    var $termGroups = $( 'tr.htax-term-groups-section' );
    var $descRow    = $( 'tr.term-description-wrap' );
    var $tagGroupsNativeRow = $( '#term-group' ).closest( 'tr' );
    var promoteNativeGroups = $toggleRow.data( 'promote-native-groups' ) == 1;

    if ( $toggleRow.length ) {
        // 1. Move Term Groups before native Description — only when it keeps the
        // always-visible spot; when demoted it stays where priority 16 put it.
        if ( ! promoteNativeGroups && $termGroups.length && $descRow.length ) {
            $descRow.before( $termGroups );
        }
        // 2. Move native Description into the collapsible zone (just after toggle row).
        if ( $descRow.length ) {
            $toggleRow.after( $descRow );
        }
        // 3. Pair Tag Groups' own field with the Description it sits next to — promoted
        // to the always-visible spot (replacing Term Groups there) or just moved to
        // directly follow Description within the collapsible content.
        if ( $tagGroupsNativeRow.length ) {
            if ( promoteNativeGroups ) {
                $toggleRow.before( $tagGroupsNativeRow );
            } else if ( $descRow.length ) {
                $descRow.after( $tagGroupsNativeRow );
            }
        }
        // 4. All rows between toggle and end-marker are the collapsible content.
        var $collapsibleRows = $toggleRow.nextUntil( $endMarker );
        $collapsibleRows.hide();

        // Re-capture the form baseline after all DOM manipulation (hiding rows,
        // moving Description) so the beforeunload check doesn't false-positive.
        initialFormState = $editForm.length ? $editForm.serialize() : '';

        // 5. Toggle on button click.
        var $editToggleBtn = $toggleRow.find( '.htax-edit-form-toggle' );
        $editToggleBtn.on( 'click', function() {
            var $btn     = $( this );
            var expanded = $btn.attr( 'aria-expanded' ) === 'true';
            if ( expanded ) {
                $collapsibleRows.hide();
                $btn.attr( 'aria-expanded', 'false' );
                $btn.find( '.htax-add-form-toggle-label' ).text( 'More options' );
                $btn.find( '.htax-add-form-toggle-icon' )
                    .removeClass( 'dashicons-arrow-up-alt2' )
                    .addClass( 'dashicons-arrow-down-alt2' );
            } else {
                $collapsibleRows.show();
                $btn.attr( 'aria-expanded', 'true' );
                $btn.find( '.htax-add-form-toggle-label' ).text( 'Fewer options' );
                $btn.find( '.htax-add-form-toggle-icon' )
                    .removeClass( 'dashicons-arrow-down-alt2' )
                    .addClass( 'dashicons-arrow-up-alt2' );
            }
        } );
    }
});
