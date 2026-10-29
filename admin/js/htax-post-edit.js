(function($) {
    'use strict';

    // Store term metadata
    const termMetadata = window.htaxTermMeta || {};

    // Per-user display preferences from the Gutenberg core/preferences store.
    // Defaults match the previous global-setting defaults.
    var HTAX_PREF_SCOPE     = 'htax/post-edit';
    var HTAX_PREF_DEFAULTS  = { inclusions: false, containers: false, redirects: true, visibility: true, sticky: false };
    function getEditorPref( key ) {
        if ( window.wp && wp.data ) {
            var store = wp.data.select( 'core/preferences' );
            if ( store && store.get ) {
                var val = store.get( HTAX_PREF_SCOPE, key );
                if ( val !== undefined ) { return !! val; }
            }
        }
        return HTAX_PREF_DEFAULTS[ key ];
    }
    // Compact signature of all five prefs; appended to per-item sigs so that
    // toggling a preference immediately triggers a re-render of all markers.
    function prefSig() {
        return ( getEditorPref( 'inclusions' ) ? 'i' : '' ) +
               ( getEditorPref( 'containers' ) ? 'c' : '' ) +
               ( getEditorPref( 'redirects'  ) ? 'r' : '' ) +
               ( getEditorPref( 'visibility' ) ? 'v' : '' ) +
               ( getEditorPref( 'sticky'     ) ? 's' : '' );
    }

    // Pre-build name→entry indices once at load time so mark* functions run in O(N+M)
    // instead of O(N×M). Decoded names are computed here, not inside the DOM loop.
    // Flat index: used by markTagPills() for token pills (non-hierarchical taxonomies).
    // Hier index: used by markCategoryChecklist() Gutenberg path (hierarchical taxonomies).
    const metaByFlatName = {};
    const metaByHierName = {};
    for (const termId in termMetadata) {
        const meta        = termMetadata[termId];
        const decodedName = $('<span>').html(meta.name).text();
        if (meta.is_hierarchical) {
            metaByHierName[decodedName] = meta;
        } else {
            metaByFlatName[decodedName] = meta;
        }
    }

    /**
     * Build a multiline title-attribute tooltip for a badge.
     * Browsers render \n as line breaks in native tooltips.
     */
    function badgeTooltip(names, prefix) {
        if (!names || !names.length) { return ''; }
        var lines = [prefix + ':'];
        names.slice(0, 7).forEach(function(name) { lines.push('• ' + name); });
        if (names.length > 7) { lines.push('and ' + (names.length - 7) + ' more…'); }
        return lines.join('\n');
    }


    // Sticky pin glyphs.
    // Filled emoji: used on light backgrounds (tag pills) where its native colours show well.
    // Filled checkbox: same thumbtack shape but drawn with currentColor (white, from the
    //   pin-in-checkbox CSS) so it matches the native white checkmark on the Gutenberg blue box.
    // Outline: faded emoji used as the hover hint before pinning.
    var PIN_FILLED  = '📌';
    // Same emoji as PIN_OUTLINE but rendered white via CSS filter so it contrasts
    // against the Gutenberg blue checkbox background, matching the native white checkmark.
    var PIN_FILLED_CHECKBOX = '<span style="filter:brightness(0) invert(1)">📌</span>';
    var PIN_OUTLINE = '<span style="opacity:0.3;filter:grayscale(1)">📌</span>';

    // Returns true if the term is currently marked sticky for this post.
    // No saved/unsaved distinction — the REST API saves term assignments before
    // the htax_sticky_terms field, so any assigned term can be made sticky in
    // a single save regardless of whether it was saved in a previous session.
    function getStickyState(termId, taxonomy) {
        if (!window.wp || !window.wp.data) { return false; }
        var editor = wp.data.select('core/editor');
        if (!editor) { return false; }
        var sticky = editor.getEditedPostAttribute('htax_sticky_terms') || {};
        return ((sticky[taxonomy] || []).map(Number)).indexOf(termId) !== -1;
    }

    function toggleStickyState(termId, taxonomy) {
        if (!window.wp || !window.wp.data) { return; }
        var editor = wp.data.select('core/editor');
        if (!editor) { return; }
        var sticky  = Object.assign({}, editor.getEditedPostAttribute('htax_sticky_terms') || {});
        var current = ((sticky[taxonomy] || []).map(Number));
        var idx     = current.indexOf(termId);
        sticky[taxonomy] = idx !== -1
            ? current.filter(function(id) { return id !== termId; })
            : current.concat([termId]);
        wp.data.dispatch('core/editor').editPost({htax_sticky_terms: sticky});
    }

    // Returns a clickable pin element, or null when pins are disabled or term is a redirect.
    function makePinEl(termId, taxonomy, isRedirect) {
        if (isRedirect || !window.htaxStickyConfig || !window.htaxStickyConfig.length) { return null; }
        var eligible = window.htaxStickyConfig.some(function(t) { return t.name === taxonomy; });
        if (!eligible) { return null; }
        var isSticky = getStickyState(termId, taxonomy);
        var $pin = $('<span class="htax-sticky-pin">')
            .toggleClass('is-sticky', isSticky)
            .html(isSticky ? PIN_FILLED : PIN_OUTLINE)
            .attr('title', isSticky ? 'Unpin from this term\'s archive' : 'Pin post to this term\'s archive');
        $pin.on('mousedown', function(e) {
            // Prevent the browser from focusing the nearest input ancestor on mousedown,
            // which would scroll the token list away before the click fires.
            e.stopPropagation();
            e.preventDefault();
        }).on('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            toggleStickyState(termId, taxonomy);
        });
        return $pin;
    }

    // Remove stale sticky IDs for terms no longer assigned to the post.
    function cleanupStickyTerms() {
        if (!window.wp || !window.wp.data || !window.htaxStickyConfig) { return; }
        var editor = wp.data.select('core/editor');
        if (!editor) { return; }
        var sticky  = editor.getEditedPostAttribute('htax_sticky_terms') || {};
        var changed = false;
        var cleaned = {};
        window.htaxStickyConfig.forEach(function(t) {
            var assigned = (editor.getEditedPostAttribute(t.restBase) || []).map(Number);
            var current  = (sticky[t.name] || []).map(Number);
            var kept     = current.filter(function(id) { return assigned.indexOf(id) !== -1; });
            if (kept.length !== current.length) { changed = true; }
            cleaned[t.name] = kept;
        });
        if (changed) { wp.data.dispatch('core/editor').editPost({htax_sticky_terms: cleaned}); }
    }

    /**
     * Mark tag pills with redirect/hidden attributes and inclusion/container badges.
     *
     * Badge side rule: badges go on the OPPOSITE side from the × remove button.
     *   Gutenberg pills — × is outside token-text on the RIGHT  → badges on the LEFT.
     *   Classic tagchecklist — × is on the LEFT of the label    → badges on the RIGHT.
     *
     * Order (reading away from the label toward the outer edge):
     *   Gutenberg: [↪ or ⇉[N]] [⇇[N]] Name ×
     *   Classic:   × Name [⇇[N]] [↪ or ⇉[N]]
     *
     * ↪ and ⇉ are mutually exclusive: a redirecting term has no inclusions.
     */
    function markTagPills() {
        // Classic Editor — × is on the left, so badges append after the label (right side).
        $('.tagchecklist li').each(function() {
            const $li      = $(this);
            const termName = $li.find('span').text().trim();
            const meta     = metaByFlatName[termName];
            if (!meta) { return; }
            const clRedir = meta.is_redirect && getEditorPref('redirects');
            const clHide  = meta.is_hidden   && getEditorPref('visibility');
            if (clRedir) { $li.attr('data-htax-redirect', '1'); } else { $li.removeAttr('data-htax-redirect'); }
            if (clHide)  { $li.attr('data-htax-hidden',   '1'); } else { $li.removeAttr('data-htax-hidden'); }

            const cont = getEditorPref('containers') && typeof meta.container_count === 'number' && meta.container_count > 0 ? meta.container_count : 0;
            const inc  = getEditorPref('inclusions') && typeof meta.inclusion_count === 'number' && meta.inclusion_count > 0 ? meta.inclusion_count : 0;
            const sig  = cont + '|' + (inc > 0 ? inc : (clRedir ? 'r' : '0')) + '|' + prefSig();
            if ($li.attr('data-htax-badges') === sig) { return; }
            $li.find('.htax-badge-group, .redirect-indicator').remove();
            $li.attr('data-htax-badges', sig);

            // Append in order: × Name [↪ or ⇉[N]] [⇇[N]]
            if (inc > 0) {
                $('<span class="htax-badge-group"></span>')
                    .attr('title', badgeTooltip(meta.included_names, 'Includes'))
                    .append($('<span class="htax-arrow">').text('⇉ '), $('<span class="htax-badge htax-badge--inc">').text(inc))
                    .appendTo($li);
            } else if (clRedir) {
                $('<span class="redirect-indicator" aria-label="Redirects elsewhere">↪</span>')
                    .attr('title', meta.redirect_target_name ? 'Redirects to:\n' + meta.redirect_target_name : '')
                    .appendTo($li);
            }
            if (cont > 0) {
                $('<span class="htax-badge-group"></span>')
                    .attr('title', badgeTooltip(meta.container_names, 'Included by'))
                    .append($('<span class="htax-arrow">').text('⇇ '), $('<span class="htax-badge htax-badge--cont">').text(cont))
                    .appendTo($li);
            }
        });

        // Gutenberg — × is outside token-text on the right, so badges prepend (left side).
        // React fiber only tracks children it rendered, so injected siblings survive re-renders.
        $('.components-form-token-field__token').each(function() {
            const $token  = $(this);
            const $textEl = $token.find('.components-form-token-field__token-text');

            // WP 6.9+: token-text wraps all text in child spans (last = visible name).
            const $children = $textEl.children();
            const termName  = $children.length
                ? $children.last().text().trim()
                : $textEl.text().trim();

            const meta = metaByFlatName[termName];
            if (!meta) { return; }
            const showRedir = meta.is_redirect && getEditorPref('redirects');
            const showHide  = meta.is_hidden   && getEditorPref('visibility');
            if (showRedir) { $token.attr('data-htax-redirect', '1'); } else { $token.removeAttr('data-htax-redirect'); }
            if (showHide)  { $token.attr('data-htax-hidden',   '1'); } else { $token.removeAttr('data-htax-hidden'); }

            const cont = getEditorPref('containers') && typeof meta.container_count === 'number' && meta.container_count > 0 ? meta.container_count : 0;
            const inc  = getEditorPref('inclusions') && typeof meta.inclusion_count === 'number' && meta.inclusion_count > 0 ? meta.inclusion_count : 0;
            var stickyCode = (meta.id && !meta.is_redirect && window.htaxStickyConfig && getEditorPref('sticky'))
                ? (getStickyState(meta.id, meta.taxonomy) ? 'S' : '')
                : '';
            const sig  = cont + '|' + (inc > 0 ? inc : (showRedir ? 'r' : '0')) + (stickyCode ? '|' + stickyCode : '') + '|' + prefSig();

            if ($token.attr('data-htax-badges') !== sig || !$textEl.find('.htax-badge-group, .redirect-indicator, .htax-sticky-pin').length) {
                $textEl.find('.htax-badge-group, .redirect-indicator, .htax-sticky-pin').remove();
                $token.attr('data-htax-badges', sig);

                // Prepend in reverse so DOM reads: [⇇[N]] [↪ or ⇉[N]] Name ×
                if (inc > 0) {
                    $('<span class="htax-badge-group">')
                        .attr('title', badgeTooltip(meta.included_names, 'Includes'))
                        .append($('<span class="htax-arrow">').text('⇉ '), $('<span class="htax-badge htax-badge--inc">').text(inc))
                        .prependTo($textEl);
                } else if (showRedir) {
                    $('<span class="redirect-indicator" aria-label="Redirects elsewhere">↪</span>')
                        .attr('title', meta.redirect_target_name ? 'Redirects to:\n' + meta.redirect_target_name : '')
                        .prependTo($textEl);
                }
                if (cont > 0) {
                    $('<span class="htax-badge-group">')
                        .attr('title', badgeTooltip(meta.container_names, 'Included by'))
                        .append($('<span class="htax-arrow">').text('⇇ '), $('<span class="htax-badge htax-badge--cont">').text(cont))
                        .prependTo($textEl);
                }
                // Pin: prepended last so it is leftmost in the pill.
                var $pin = (meta.id && getEditorPref('sticky')) ? makePinEl(meta.id, meta.taxonomy, meta.is_redirect) : null;
                if ($pin) { $pin.prependTo($textEl); }
            }
        });
    }

    /**
     * Mark hierarchical-term checklist items (categories etc.).
     *
     * Categories have no × button; they follow the same side as terms for consistency.
     *   Classic: badges on the RIGHT (inside the label, after the name text).
     *   Gutenberg: badges on the LEFT (in the HStack, between input-container and label).
     *
     * Gutenberg uses CheckboxControl whose inputs always have value="1"; match by label text.
     * ↪ goes into the HStack (not inside the label) so label text stays clean for matching.
     */
    function markCategoryChecklist() {
        // Classic Editor — follow Classic tags: badges on the right of the label.
        $('.categorychecklist input[type="checkbox"]').each(function() {
            const $cb  = $(this);
            const tid  = parseInt($cb.val(), 10);
            const meta = termMetadata[tid];
            if (!meta) { return; }

            const $li = $cb.closest('li');
            const clRedir2 = meta.is_redirect && getEditorPref('redirects');
            const clHide2  = meta.is_hidden   && getEditorPref('visibility');
            if (clRedir2) { $li.attr('data-htax-redirect', '1'); } else { $li.removeAttr('data-htax-redirect'); }
            if (clHide2)  { $li.attr('data-htax-hidden',   '1'); } else { $li.removeAttr('data-htax-hidden'); }

            const cont = getEditorPref('containers') && typeof meta.container_count === 'number' && meta.container_count > 0 ? meta.container_count : 0;
            const inc  = getEditorPref('inclusions') && typeof meta.inclusion_count === 'number' && meta.inclusion_count > 0 ? meta.inclusion_count : 0;
            const sig  = cont + '|' + (inc > 0 ? inc : (clRedir2 ? 'r' : '0')) + '|' + prefSig();
            if ($li.attr('data-htax-badges') === sig) { return; }
            $li.find('.htax-badge-group, .redirect-indicator').remove();
            $li.attr('data-htax-badges', sig);

            // Append in order inside the label: ☐ Name [↪ or ⇉[N]] [⇇[N]]
            const $target = $cb.closest('label').length ? $cb.closest('label') : $li;
            if (inc > 0) {
                $('<span class="htax-badge-group">')
                    .attr('title', badgeTooltip(meta.included_names, 'Includes'))
                    .append($('<span class="htax-arrow">').text('⇉ '), $('<span class="htax-badge htax-badge--inc">').text(inc))
                    .appendTo($target);
            } else if (clRedir2) {
                $('<span class="redirect-indicator" aria-label="Redirects elsewhere">↪</span>')
                    .attr('title', meta.redirect_target_name ? 'Redirects to:\n' + meta.redirect_target_name : '')
                    .appendTo($target);
            }
            if (cont > 0) {
                $('<span class="htax-badge-group">')
                    .attr('title', badgeTooltip(meta.container_names, 'Included by'))
                    .append($('<span class="htax-arrow">').text('⇇ '), $('<span class="htax-badge htax-badge--cont">').text(cont))
                    .appendTo($target);
            }
        });

        // Gutenberg — follow Gutenberg tags: badges on the left of the label.
        // Inject into the HStack row between the input-container and the label.
        // Exclude checkboxes that belong to the Sticky for Terms panel.
        $('.components-checkbox-control__label').not('.htax-sticky-terms-panel *').each(function() {
            const $label = $(this);
            const meta   = metaByHierName[$label.text().trim()];
            if (!meta) { return; }

            const $row    = $label.parent(); // HStack
            const $anchor = $row.find('.components-checkbox-control__input-container');

            const gbRedir = meta.is_redirect && getEditorPref('redirects');
            const gbHide  = meta.is_hidden   && getEditorPref('visibility');
            if (gbRedir) { $label.attr('data-htax-redirect', '1'); } else { $label.removeAttr('data-htax-redirect'); }
            if (gbHide) {
                if ($row.length) { $row.attr('data-htax-hidden', '1'); } else { $row.removeAttr('data-htax-hidden'); }
                $label.attr('data-htax-hidden', '1');
            } else {
                $row.removeAttr('data-htax-hidden');
                $label.removeAttr('data-htax-hidden');
            }

            const cont = getEditorPref('containers') && typeof meta.container_count === 'number' && meta.container_count > 0 ? meta.container_count : 0;
            const inc  = getEditorPref('inclusions') && typeof meta.inclusion_count === 'number' && meta.inclusion_count > 0 ? meta.inclusion_count : 0;
            var catStickyCode = (meta.id && !meta.is_redirect && window.htaxStickyConfig && getEditorPref('sticky'))
                ? (getStickyState(meta.id, meta.taxonomy) ? 'S' : '')
                : '';
            const sig  = cont + '|' + (inc > 0 ? inc : (gbRedir ? 'r' : '0')) + (catStickyCode ? '|' + catStickyCode : '') + '|' + prefSig();

            if ($row.attr('data-htax-badges') !== sig || !$row.find('.htax-badge-group, .redirect-indicator, .htax-sticky-pin, .htax-tristate-intercept').length) {
                $row.find('.htax-badge-group, .redirect-indicator').remove();
                $row.attr('data-htax-badges', sig);

                // Insert in reverse after input-container so DOM reads:
                // [input] [⇇[N]] [↪ or ⇉[N]] Label
                if (inc > 0) {
                    $('<span class="htax-badge-group">')
                        .attr('title', badgeTooltip(meta.included_names, 'Includes'))
                        .append($('<span class="htax-arrow">').text('⇉ '), $('<span class="htax-badge htax-badge--inc">').text(inc))
                        .insertAfter($anchor);
                } else if (gbRedir) {
                    $('<span class="redirect-indicator" aria-label="Redirects elsewhere">↪</span>')
                        .attr('title', meta.redirect_target_name ? 'Redirects to:\n' + meta.redirect_target_name : '')
                        .insertAfter($anchor);
                }
                if (cont > 0) {
                    $('<span class="htax-badge-group">')
                        .attr('title', badgeTooltip(meta.container_names, 'Included by'))
                        .append($('<span class="htax-arrow">').text('⇇ '), $('<span class="htax-badge htax-badge--cont">').text(cont))
                        .insertAfter($anchor);
                }
                // Three-state checkbox: unchecked ☐ → checked ☑ → sticky 📌 → unchecked ☐
                // State 2 (checked, not sticky): transparent interceptor overlay shows
                //   a faint outline pin on hover; clicking advances to sticky.
                // State 3 (sticky): filled pin replaces checkmark; clicking unassigns + unpins.
                $anchor.find('.htax-sticky-pin, .htax-tristate-intercept').remove();
                var isTriEligible = meta.id && !meta.is_redirect && window.htaxStickyConfig && getEditorPref('sticky') &&
                    window.htaxStickyConfig.some(function(t) { return t.name === meta.taxonomy; });
                if (isTriEligible && $row.find('input[type="checkbox"]').prop('checked')) {
                    var isSticky3 = getStickyState(meta.id, meta.taxonomy);
                    $anchor.css('position', 'relative');
                    if (isSticky3) {
                        // State 3: filled pin; click → unpin + unassign in one dispatch.
                        var $pin3 = $('<span class="htax-sticky-pin pin-in-checkbox is-sticky">')
                            .html(PIN_FILLED_CHECKBOX)
                            .attr('title', 'Unpin and uncheck this term');
                        $pin3.on('click', function(e) {
                            e.stopPropagation();
                            e.preventDefault();
                            var taxCfg3 = (window.htaxStickyConfig || []).filter(function(t) { return t.name === meta.taxonomy; })[0];
                            var restBase3 = taxCfg3 ? taxCfg3.restBase : meta.taxonomy;
                            var editor3  = wp.data.select('core/editor');
                            if (!editor3) { return; }
                            var sticky3  = Object.assign({}, editor3.getEditedPostAttribute('htax_sticky_terms') || {});
                            sticky3[meta.taxonomy] = ((sticky3[meta.taxonomy] || []).map(Number)).filter(function(id) { return id !== meta.id; });
                            var assigned3 = (editor3.getEditedPostAttribute(restBase3) || []).map(Number).filter(function(id) { return id !== meta.id; });
                            var upd3 = {htax_sticky_terms: sticky3};
                            upd3[restBase3] = assigned3;
                            wp.data.dispatch('core/editor').editPost(upd3);
                        });
                        $anchor.append($pin3);
                    } else {
                        // State 2: transparent interceptor — click advances to sticky.
                        // Faint outline pin appears on hover so the affordance is visible.
                        var $int = $('<span class="htax-tristate-intercept">')
                            .html(PIN_OUTLINE)
                            .attr('title', 'Pin this post to this term\'s archive');
                        $int.on('click', function(e) {
                            e.stopPropagation();
                            e.preventDefault();
                            toggleStickyState(meta.id, meta.taxonomy);
                        });
                        $anchor.append($int);
                    }
                }
            }
        });
    }

    /**
     * Mark autocomplete suggestions
     */
    function markAutocomplete(ul, item) {
        const termId = item.id;

        if (termMetadata[termId]) {
            const $menuItem = ul.find(`[data-term-id="${termId}"]`).parent();

            if (termMetadata[termId].is_redirect) {
                $menuItem.find('div').attr('data-htax-redirect', '1');
            }
            if (termMetadata[termId].is_hidden) {
                $menuItem.find('div').attr('data-htax-hidden', '1');
            }
        }
    }

    // Initialize on page load
    $(document).ready(function() {
        markTagPills();
        markCategoryChecklist();

        // Use MutationObserver for better performance and browser compatibility
        const DEBOUNCE_DELAY = 100;
        let debounceTimer;

        const tagChecklistObserver = new MutationObserver(function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(markTagPills, DEBOUNCE_DELAY);
        });

        const tagChecklistNode = document.querySelector('.tagchecklist');
        if (tagChecklistNode) {
            tagChecklistObserver.observe(tagChecklistNode, { childList: true, subtree: true });
        }

        // For Gutenberg editor: wp.data.subscribe is reliable regardless of DOM structure.
        if (window.wp && window.wp.data) {
            let gutenbergDebounce;
            const hasTermMeta = Object.keys(termMetadata).length > 0;
            function markAll() {
                if (!hasTermMeta) { return; }
                markTagPills();
                markCategoryChecklist();
                markSuggestions();
            }
            wp.data.subscribe(function() {
                clearTimeout(gutenbergDebounce);
                gutenbergDebounce = setTimeout(function() { markAll(); cleanupStickyTerms(); }, 150);
            });
            if (window.wp.domReady) {
                wp.domReady(markAll);
            }
        }
            // Detect terms added via autocomplete mid-session and fetch their badge
            // metadata on demand. htaxFlatTaxonomies lists the flat HT-enabled taxonomies
            // with their slug and REST base so we can watch the right store attributes.
            if (window.htaxFlatTaxonomies && window.htaxFlatTaxonomies.length) {
                var prevTermIds  = {};
                var checkedIds   = {};   // ids already fetched (or confirmed empty)
                var pendingByTax = {};
                var metaFetchTimer;

                // Seed baseline from PHP-rendered preloaded terms so the subscriber
                // never treats existing assigned tags as newly added (the Redux store
                // may not have post data yet when the first subscribe fire happens).
                htaxFlatTaxonomies.forEach(function(tax) {
                    var preloaded = window.htaxPreloadedTerms && window.htaxPreloadedTerms[tax.slug];
                    prevTermIds[tax.slug] = preloaded
                        ? preloaded.map(function(t) { return Number(t.id); })
                        : [];
                });

                function fetchPendingMeta() {
                    var snapshot = {};
                    htaxFlatTaxonomies.forEach(function(tax) {
                        if (pendingByTax[tax.slug] && pendingByTax[tax.slug].length) {
                            snapshot[tax.slug]      = pendingByTax[tax.slug].slice();
                            pendingByTax[tax.slug]  = [];
                        }
                    });
                    Object.keys(snapshot).forEach(function(taxSlug) {
                        var ids = snapshot[taxSlug];
                        ids.forEach(function(id) { checkedIds[id] = true; });
                        wp.apiFetch({
                            path: '/htax/v1/term-meta?taxonomy=' + taxSlug + '&ids=' + ids.join(',')
                        }).then(function(data) {
                            if (!data || !data.terms || !data.terms.length) { return; }
                            // Build entry using taxonomy-level feature gates from PHP;
                            // user display preferences are applied at render time in markTagPills().
                            var taxFeats = (window.htaxTaxonomyFeatures && window.htaxTaxonomyFeatures[taxSlug]) || {};
                            var taxHasSticky = window.htaxStickyConfig && window.htaxStickyConfig.some(function(t) { return t.name === taxSlug; });
                            var changed = false;
                            data.terms.forEach(function(item) {
                                var entry = {
                                    id:                   item.id,
                                    name:                 item.name,
                                    slug:                 item.slug,
                                    taxonomy:             taxSlug,
                                    is_redirect:          taxFeats.redirects  ? !!item.is_redirect : false,
                                    is_hidden:            taxFeats.visibility ? !!item.is_hidden   : false,
                                    is_hierarchical:      false,
                                    inclusion_count:      taxFeats.inclusions ? (item.inc_count  || 0) : 0,
                                    container_count:      taxFeats.containers ? (item.cont_count || 0) : 0,
                                    included_names:       taxFeats.inclusions ? (item.included_names  || []) : [],
                                    container_names:      taxFeats.containers ? (item.container_names || []) : [],
                                    redirect_target_name: taxFeats.redirects  ? (item.redirect_target_name || '') : '',
                                };
                                if (entry.inclusion_count || entry.container_count || entry.is_redirect || entry.is_hidden || taxHasSticky) {
                                    termMetadata[item.id]     = entry;
                                    metaByFlatName[item.name] = entry;
                                    changed = true;
                                }
                            });
                            if (changed) { markTagPills(); }
                        });
                    });
                }

                wp.data.subscribe(function() {
                    var editor = wp.data.select('core/editor');
                    if (!editor) { return; }
                    var hasPending = false;

                    htaxFlatTaxonomies.forEach(function(tax) {
                        var currentIds = editor.getEditedPostAttribute(tax.restBase) || [];
                        var prev       = prevTermIds[tax.slug];

                        currentIds.forEach(function(id) {
                            if (prev.indexOf(id) !== -1 || termMetadata[id] || checkedIds[id]) {
                                return;
                            }
                            if (!pendingByTax[tax.slug]) { pendingByTax[tax.slug] = []; }
                            if (pendingByTax[tax.slug].indexOf(id) === -1) {
                                pendingByTax[tax.slug].push(id);
                                hasPending = true;
                            }
                        });

                        prevTermIds[tax.slug] = currentIds.slice();
                    });

                    if (hasPending) {
                        clearTimeout(metaFetchTimer);
                        metaFetchTimer = setTimeout(fetchPendingMeta, 200);
                    }
                });
            }


        // Cleanup observers on page unload
        window.addEventListener('beforeunload', function() {
            if (tagChecklistObserver) tagChecklistObserver.disconnect();
        });
    });

    // Style Gutenberg token-field autocomplete suggestions: redirect terms in
    // italics, hidden terms faded. htaxAutocompleteMeta is a name→{r,h} map
    // pre-loaded at page load (only redirect/hidden terms, typically O(10s) of
    // entries). The cache key includes pref state so toggling a preference
    // immediately re-marks any open suggestions list.
    var acMeta = window.htaxAutocompleteMeta || {};
    function markSuggestions() {
        if ( ! Object.keys( acMeta ).length ) { return; }
        var showR = getEditorPref( 'redirects' );
        var showH = getEditorPref( 'visibility' );
        var cacheKey = ( showR ? 'r' : '' ) + ( showH ? 'h' : '' );
        var items = document.querySelectorAll(
            '.components-form-token-field__suggestions-list .components-form-token-field__suggestion'
        );
        for ( var i = 0; i < items.length; i++ ) {
            var el   = items[ i ];
            var name = el.textContent.trim();
            if ( el.dataset.htaxAcMarked === name + cacheKey ) { continue; }
            el.dataset.htaxAcMarked = name + cacheKey;
            var m = acMeta[ name ];
            el.style.fontStyle = ( m && m.r && showR ) ? 'italic' : '';
            el.style.opacity   = ( m && m.h && showH ) ? '0.45'   : '';
        }
    }
    if ( Object.keys( acMeta ).length ) {
        var acObserver = new MutationObserver( markSuggestions );
        acObserver.observe( document.body, { childList: true, subtree: true } );
    }

    // When new tag creation is prevented, hide the FormTokenField hint text
    // "Separate with commas or the Enter key." — it is rendered as a visible
    // <p class="components-form-token-field__help"> below the input, and is
    // inaccurate when only existing tags can be assigned.
    if ( window.htaxPreventTagCreation ) {
        function hideTagHint() {
            document.querySelectorAll( '.components-form-token-field__help' ).forEach( function ( el ) {
                if ( el.style.display !== 'none' ) {
                    el.style.display = 'none';
                }
            } );
        }
        var hintObserver = new MutationObserver( hideTagHint );
        hintObserver.observe( document.body, { childList: true, subtree: true } );
        hideTagHint();
    }

    // Export for autocomplete integration and manual testing
    window.htaxMarkAutocomplete = markAutocomplete;
    window.htaxMarkTagPills     = markTagPills;
    window.htaxMarkSuggestions  = markSuggestions;

})(jQuery);

// ============================================================================
// Reposition tag-suggestion panels below their taxonomy section in the sidebar.
// PluginDocumentSettingPanel always appends plugin panels below native panels,
// so we move each one once after it appears. Panel order is stable after the
// initial React render — preferences only affect open/closed accordion state.
// Stale panel IDs that disrupted ordering are cleaned up by the PHP upgrader.
// ============================================================================
(function() {
    'use strict';

    var taxCfgs = window.htaxTagSuggestions ? window.htaxTagSuggestions.taxonomies : [];
    if ( ! taxCfgs || ! taxCfgs.length ) { return; }
    if ( ! window.wp || ! wp.domReady ) { return; }

    wp.domReady( function() {
        function reposition() {
            var anyPanel = document.querySelector( '.htax-tag-suggestions-panel' );
            if ( ! anyPanel || ! anyPanel.parentElement ) { return; }
            var container = anyPanel.parentElement;

            var panelByLabel = {};
            Array.from( container.querySelectorAll( '.components-panel__body' ) ).forEach( function( panel ) {
                var btn = panel.querySelector( '.components-panel__body-title button' );
                if ( btn ) { panelByLabel[ btn.textContent.trim() ] = panel; }
            } );

            taxCfgs.forEach( function( tax ) {
                var suggestPanel = document.querySelector( '.htax-tag-suggestions-panel-' + tax.slug );
                if ( ! suggestPanel ) { return; }
                var taxPanel = panelByLabel[ tax.label ];
                if ( taxPanel && taxPanel.nextElementSibling !== suggestPanel ) {
                    taxPanel.after( suggestPanel );
                }
            } );
        }

        // Watch for suggestion panels to appear, then keep watching the sidebar
        // for ~4s to catch the async preference re-render (Gutenberg fetches
        // persisted panel state via REST after initial mount and re-renders).
        // After that window the panel order is stable and we stop observing.
        var bodyObserver = new MutationObserver( function( mutations, obs ) {
            if ( ! document.querySelector( '.htax-tag-suggestions-panel' ) ) { return; }
            obs.disconnect();
            reposition();

            var debounceTimer;
            var sidebar = document.querySelector(
                '.interface-interface-skeleton__sidebar, .edit-post-sidebar'
            ) || document.body;
            var sidebarObserver = new MutationObserver( function() {
                clearTimeout( debounceTimer );
                debounceTimer = setTimeout( reposition, 80 );
            } );
            sidebarObserver.observe( sidebar, { childList: true, subtree: true } );
            setTimeout( function() { sidebarObserver.disconnect(); }, 4000 );
        } );
        bodyObserver.observe( document.body, { childList: true, subtree: true } );
    } );
} )();
