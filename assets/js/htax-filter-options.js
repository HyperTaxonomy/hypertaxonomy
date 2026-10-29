/**
 * HyperTaxonomy Filter Options JavaScript
 *
 * Manages filter panel interactions for [ht_filter_options] and the inline
 * filter panels rendered adjacent to [ht_post_list] / [ht_search_results].
 *
 * Each element with [data-htax-filter-root] gets its own FilterPanel instance.
 *
 * Action modes
 * ------------
 *   navigate  — builds htax_filter[...] URL params and navigates.
 *   submit    — updates hidden inputs and submits the nearest ancestor <form>.
 *   (empty)   — fires 'htax:filter-change' jQuery event on the root element,
 *               carrying the new state. Used for AJAX-mode result shortcodes.
 *
 * Filter state format (sent via htax:filter-change and stored in data-filter)
 * ---------------------------------------------------------------------------
 * {
 *   "post_tag":  { "match": "any",  "terms": [5, 12] },
 *   "category":  { "match": "all",  "terms": [] },
 *   "date_from": "2024-01-01",
 *   "date_to":   "2024-12-31",
 *   "date_modified_from": "",
 *   "date_modified_to":   "",
 *   "author":    [1, 2]
 * }
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        $('[data-htax-filter-root]').each(function () {
            new FilterPanel($(this));
        });
    });

    /**
     * FilterPanel — manages one filter panel root element.
     */
    class FilterPanel {
        constructor($root) {
            this.$root    = $root;
            this.action   = $root.data('htax-filter-action') || '';
            this.state    = JSON.parse($root.attr('data-htax-filter-state')  || '{}');
            this.initial  = JSON.parse($root.attr('data-htax-filter-initial') || '{}');

            this.$toggle  = $root.find('.htax-filter-toggle').first();
            this.$clear   = $root.find('.htax-filter-clear').first();
            this.$body    = $root.find('.htax-filter-body').first();

            this.debounceTimer = null;

            this.bindEvents();
        }

        bindEvents() {
            const self = this;

            // Toggle collapsed / expanded
            this.$toggle.on('click', function () {
                const isCollapsed = self.$root.hasClass('htax-filter-state-collapsed');
                self.$root
                    .toggleClass('htax-filter-state-collapsed', !isCollapsed)
                    .toggleClass('htax-filter-state-expanded',   isCollapsed);
                self.$toggle.text(
                    isCollapsed
                        ? self.$toggle.data('text-expanded')
                        : self.$toggle.data('text-collapsed')
                );
            });

            // Clear all filters
            this.$clear.on('click', function () {
                self.resetToInitial();
                self.applyState();
            });

            // Pill clicks (term filter)
            this.$root.on('click', '.htax-filter-pill', function () {
                const $pill = $(this);
                const $group = $pill.closest('.htax-filter-group');
                const taxonomy = $group.data('taxonomy');
                const termId = parseInt($pill.data('term-id'), 10);

                $pill.toggleClass('htax-active');

                self.state[taxonomy] = self.state[taxonomy] || { match: $group.data('match') || 'any', terms: [] };
                self.state[taxonomy].match = $group.data('match') || 'any';

                const terms = self.state[taxonomy].terms;
                const idx   = terms.indexOf(termId);
                if (idx === -1) {
                    terms.push(termId);
                } else {
                    terms.splice(idx, 1);
                }

                self.syncHiddenInput($group);
                self.syncClearButton();
                self.scheduleApply();
            });

            // Checkbox changes (checklist term filter + author)
            this.$root.on('change', '.htax-filter-checkbox', function () {
                const $cb    = $(this);
                const $group = $cb.closest('.htax-filter-group');
                const type   = $group.data('type');

                if (type === 'terms') {
                    const taxonomy = $group.data('taxonomy');
                    self.state[taxonomy] = self.state[taxonomy] || { match: $group.data('match') || 'any', terms: [] };
                    self.state[taxonomy].match = $group.data('match') || 'any';
                    self.state[taxonomy].terms = self.collectCheckedValues($group);
                } else if (type === 'author') {
                    self.state.author = self.collectCheckedValues($group);
                }

                self.syncClearButton();
                self.scheduleApply();
            });

            // Date input changes (debounced)
            this.$root.on('change', '.htax-filter-date-from, .htax-filter-date-to', function () {
                const $input = $(this);
                const $group = $input.closest('.htax-filter-group');
                const dateType = $group.data('type'); // 'date_published' or 'date_modified'
                const isModified = (dateType === 'date_modified');

                const fromVal = $group.find('.htax-filter-date-from').val() || '';
                const toVal   = $group.find('.htax-filter-date-to').val()   || '';

                if (isModified) {
                    self.state.date_modified_from = fromVal;
                    self.state.date_modified_to   = toVal;
                } else {
                    self.state.date_from = fromVal;
                    self.state.date_to   = toVal;
                }

                self.syncClearButton();
                self.scheduleApply(600); // longer debounce for date inputs
            });
        }

        /**
         * Collect checked checkbox values within a group as an array of ints.
         */
        collectCheckedValues($group) {
            const vals = [];
            $group.find('.htax-filter-checkbox:checked').each(function () {
                vals.push(parseInt($(this).val(), 10));
            });
            return vals;
        }

        /**
         * Sync the hidden value input for a pill-mode term group.
         */
        syncHiddenInput($group) {
            if (this.action === '') return; // no hidden inputs in event mode
            const taxonomy = $group.data('taxonomy');
            const terms    = (this.state[taxonomy] && this.state[taxonomy].terms) ? this.state[taxonomy].terms : [];
            $group.find('.htax-filter-value-input').val(terms.join(','));
        }

        /**
         * Show/hide the clear button based on whether state differs from initial.
         */
        syncClearButton() {
            this.$clear.toggle(this.isDirty());
        }

        /**
         * Return true when current state differs from initial state.
         */
        isDirty() {
            const cur = this.state;
            const ini = this.initial;

            // Check term filters
            const taxKeys = new Set([
                ...Object.keys(cur).filter(k => typeof cur[k] === 'object' && cur[k] !== null && 'terms' in cur[k]),
                ...Object.keys(ini).filter(k => typeof ini[k] === 'object' && ini[k] !== null && 'terms' in ini[k]),
            ]);
            for (const tax of taxKeys) {
                const curTerms = (cur[tax] && cur[tax].terms) ? [...cur[tax].terms].sort().join(',') : '';
                const iniTerms = (ini[tax] && ini[tax].terms) ? [...ini[tax].terms].sort().join(',') : '';
                if (curTerms !== iniTerms) return true;
            }

            // Check date fields
            for (const key of ['date_from', 'date_to', 'date_modified_from', 'date_modified_to']) {
                if ((cur[key] || '') !== (ini[key] || '')) return true;
            }

            // Check author
            const curAuth = (cur.author || []).map(Number).sort().join(',');
            const iniAuth = (ini.author || []).map(Number).sort().join(',');
            if (curAuth !== iniAuth) return true;

            return false;
        }

        /**
         * Reset UI and state to initial values.
         */
        resetToInitial() {
            const ini = this.initial;

            // Restore pill states
            this.$root.find('.htax-filter-group[data-type="terms"]').each(function () {
                const $group   = $(this);
                const taxonomy = $group.data('taxonomy');
                const iniTerms = (ini[taxonomy] && ini[taxonomy].terms) ? ini[taxonomy].terms : [];

                $group.find('.htax-filter-pill').each(function () {
                    const termId = parseInt($(this).data('term-id'), 10);
                    $(this).toggleClass('htax-active', iniTerms.indexOf(termId) !== -1);
                });

                $group.find('.htax-filter-checkbox').each(function () {
                    const val = parseInt($(this).val(), 10);
                    this.checked = iniTerms.indexOf(val) !== -1;
                });
            });

            // Restore date inputs
            this.$root.find('.htax-filter-date-group').each(function () {
                const $group    = $(this);
                const dateType  = $group.data('type');
                const isModified = (dateType === 'date_modified');
                $group.find('.htax-filter-date-from').val(
                    isModified ? (ini.date_modified_from || '') : (ini.date_from || '')
                );
                $group.find('.htax-filter-date-to').val(
                    isModified ? (ini.date_modified_to || '') : (ini.date_to || '')
                );
            });

            // Restore author checkboxes
            this.$root.find('.htax-filter-author-group .htax-filter-checkbox').each(function () {
                const val = parseInt($(this).val(), 10);
                this.checked = (ini.author || []).indexOf(val) !== -1;
            });

            // Deep-copy initial into state
            this.state = JSON.parse(JSON.stringify(ini));
        }

        /**
         * Schedule an apply() call, cancelling any pending debounce.
         */
        scheduleApply(delay) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this.applyState(), delay || 0);
        }

        /**
         * Apply the current filter state according to the action mode.
         */
        applyState() {
            if (this.action === 'navigate') {
                this.navigateWithFilter();
            } else if (this.action === 'submit') {
                this.submitForm();
            } else {
                // Event mode — notify result shortcodes
                this.$root.trigger('htax:filter-change', [this.state]);
            }
        }

        /**
         * Build the filtered URL and navigate.
         */
        navigateWithFilter() {
            const url    = new URL(window.location.href);
            const params = url.searchParams;

            // Remove existing htax_filter params
            const toDelete = [];
            for (const [k] of params) {
                if (k.startsWith('htax_filter')) {
                    toDelete.push(k);
                }
            }
            toDelete.forEach(k => params.delete(k));

            // Reset to page 1
            params.delete('paged');

            // Add term filters
            for (const [taxonomy, data] of Object.entries(this.state)) {
                if (typeof data === 'object' && data !== null && 'terms' in data && data.terms.length > 0) {
                    params.set('htax_filter[' + taxonomy + ']', data.terms.join(','));
                }
            }

            // Add date filters
            if (this.state.date_from)          params.set('htax_filter[date_from]', this.state.date_from);
            if (this.state.date_to)            params.set('htax_filter[date_to]',   this.state.date_to);
            if (this.state.date_modified_from) params.set('htax_filter[date_modified_from]', this.state.date_modified_from);
            if (this.state.date_modified_to)   params.set('htax_filter[date_modified_to]',   this.state.date_modified_to);

            // Add author filter
            if (this.state.author && this.state.author.length > 0) {
                params.set('htax_filter[author]', this.state.author.join(','));
            }

            window.location.href = url.toString();
        }

        /**
         * Submit the nearest ancestor form.
         */
        submitForm() {
            const $form = this.$root.closest('form');
            if ($form.length) {
                $form.trigger('submit');
            }
        }
    }

    // =========================================================================
    // Post list AJAX integration
    // =========================================================================

    // Listen for filter changes on filter panels that are siblings of
    // .htax-post-list-wrapper (inside .htax-post-list-block).
    $(document).on('htax:filter-change', '[data-htax-filter-root]', function (_e, newState) {
        const $root    = $(this);
        let $wrapper   = $root.siblings('.htax-post-list-wrapper');
        if (!$wrapper.length) {
            // The filters= inline shorthand on [ht_post_list] renders the filter panel
            // inside its own .htax-controls-bar, which is itself a sibling of
            // .htax-post-list-wrapper — not a parent of it — so parent().find() (which
            // only searches descendants) never finds it. Scope to the shared
            // .htax-post-list-block ancestor instead.
            $wrapper = $root.closest('.htax-post-list-block').find('.htax-post-list-wrapper').first();
        }
        if ($wrapper.length && typeof HTAX_PostList !== 'undefined') {
            $wrapper.data('htax-filter', newState || {});
            // loadPage is defined in htax-post-list.js; trigger it via custom event
            $wrapper.trigger('htax:load-page', [1]);
        }
    });

    // =========================================================================
    // Search widget AJAX integration
    // =========================================================================

    $(document).on('htax:filter-change', '[data-htax-filter-root]', function (_e, newState) {
        const $root    = $(this);
        const $results = $root.siblings('.htax-search-results');
        if (!$results.length) return;
        $results.data('htax-filter', newState || {});
        const formId = $results.siblings('.htax-search-form').attr('id');
        if (formId && typeof HTAXSearchWidget !== 'undefined' && HTAXSearchWidget.forms && HTAXSearchWidget.forms[formId]) {
            HTAXSearchWidget.forms[formId].performSearch(1);
        }
    });

    // =========================================================================
    // PowerSearch AJAX integration
    // =========================================================================

    $(document).on('htax:filter-change', '[data-htax-filter-root]', function (_e, newState) {
        const $root    = $(this);
        const $results = $root.siblings('.htax-power-search-results');
        if (!$results.length) return;
        $results.data('htax-filter', newState || {});
        const resultsId = $results.attr('id');
        let $form = null;
        if (resultsId) {
            $form = $('[data-results="' + resultsId + '"]').closest('.htax-power-search-form');
        }
        if (!$form || !$form.length) {
            $form = $results.closest('.htax-power-search-form');
        }
        if ($form && $form.length) {
            const formInstance = $form.data('htax-instance');
            if (formInstance) {
                formInstance.performSearch(1);
            }
        }
    });

})(jQuery);
