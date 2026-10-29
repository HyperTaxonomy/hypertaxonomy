/**
 * HyperTaxonomy Advanced Search Widget JavaScript
 * @package HyperTaxonomy
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Check for required global objects
    if (typeof HTAX_Search === 'undefined') {
        console.error('HTAX_Search object not loaded. Search widget cannot initialize.');
        return;
    }

    // Custom easings: ease-out starts fast and decelerates (makes dim-start visible immediately);
    // ease-in starts slow and accelerates (makes restore finish crisply).
    $.easing.htaxEaseOut = (x) => 1 - Math.pow(1 - x, 2);
    $.easing.htaxEaseIn  = (x) => x * x;

    // Main controller object
    window.HTAXSearchWidget = {
        forms: {},
        
        /**
         * Initialize a search form
         */
        initForm: function(formEl) {
            const $form = $(formEl);
            const formId = $form.attr('id');
            
            if (!formId || this.forms[formId]) return;
            
            // Create form instance
            this.forms[formId] = new SearchForm($form);
        }
    };
    
    /**
     * SearchForm class
     */
    class SearchForm {
        constructor($form) {
            this.$form = $form;
            this.id = $form.attr('id');
            this.state = $form.data('state') || 'collapsed';
            // Use .attr() instead of .data() to avoid jQuery's automatic type conversion
            this.isAjax = $form.attr('data-ajax') === 'true';
            this.fields = {};
            this.controllers = {};

            // Member terms of the groups named by controller group actions, per target field
            // (rendered by PHP at the end of the form; see render_group_members_map()).
            this.groupMembers = {};
            try {
                this.groupMembers = JSON.parse($form.children('script.htax-group-members').text() || '{}');
            } catch (e) {
                this.groupMembers = {};
            }

            this.init();
        }
        
        init() {
            // Initialize components in correct order
            this.initFields();
            this.initControllers();
            this.applyInitialState();
            this.initToggle();
            this.initSubmit();
            this.initDistanceField();

            // Apply initial form state classes and toggle button text
            this.syncFormState();
        }

        /**
         * Initialize ATCF distance field geolocation support.
         *
         * Delegates entirely to window.atcfGeo.initGeoBtn (exposed by ATCF's
         * frontend.js). ATCF handles the permission request, Nominatim reverse
         * geocode, icon states, and re-enable-on-edit. HT only needs to wire up
         * what to do with the resulting coordinates: store them in the shared
         * _atcf_lat / _atcf_lng hidden fields that the server reads.
         */
        initDistanceField() {
            const $form = this.$form;

            // Inject shared lat/lng hidden fields once per form.
            if (!$form.find('input[name="_atcf_lat"]').length) {
                $form.append(
                    '<input type="hidden" name="_atcf_lat" value="">' +
                    '<input type="hidden" name="_atcf_lng" value="">'
                );
            }

            $form.find('.atcf-geo-btn').each((i, btn) => {
                const $wrap  = $(btn).closest('.atcf-postal-wrap');
                const $input = $wrap.find('.atcf-postal-input');

                if (typeof window.atcfGeo === 'undefined') { return; }

                window.atcfGeo.initGeoBtn({
                    btn:   btn,
                    input: $input[0] || null,
                    label: HTAX_Search.i18n.currentLocation || 'Current Location',
                    onSuccess: (lat, lng) => {
                        $form.find('input[name="_atcf_lat"]').val(lat);
                        $form.find('input[name="_atcf_lng"]').val(lng);
                    },
                    onReset: () => {
                        $form.find('input[name="_atcf_lat"]').val('');
                        $form.find('input[name="_atcf_lng"]').val('');
                    }
                });
            });
        }
        
        /**
         * Initialize all fields
         */
        initFields() {
            this.$form.find('.htax-field-wrapper').each((i, el) => {
                const $wrapper = $(el);
                const name = $wrapper.data('field-name');
                const type = $wrapper.data('field-type');
                
                if (!name) return;
                
                // Create appropriate field instance
                switch (type) {
                    case 'pills':
                        this.fields[name] = new PillsField($wrapper, this);
                        break;
                    case 'checklist':
                        this.fields[name] = new ChecklistField($wrapper, this);
                        break;
                    case 'hidden':
                        this.fields[name] = new HiddenField($wrapper, this);
                        break;
                }
            });
        }
        
        /**
         * Initialize all controllers
         */
        initControllers() {
            this.$form.find('.htax-controller-wrapper').each((i, el) => {
                const $wrapper = $(el);
                const name = $wrapper.data('controller-name');
                const type = $wrapper.data('controller-type');

                if (!name) return;

                // Instantiate the appropriate controller class based on type
                switch (type) {
                    case 'link':
                        this.controllers[name] = new LinkController($wrapper, this);
                        break;
                    case 'button':
                        this.controllers[name] = new ButtonController($wrapper, this);
                        break;
                    default:
                        this.controllers[name] = new Controller($wrapper, this);
                }
            });
        }
        
        /**
         * Initialize toggle button
         */
        initToggle() {
            this.$form.on('click', '.htax-toggle-button', (e) => {
                e.preventDefault();
                this.toggleState();
            });
        }
        
        /**
         * Initialize submit handling
         */
        initSubmit() {
            this.$form.on('submit', (e) => {
                if (this.isAjax) {
                    e.preventDefault();
                    this.performSearch();
                } else {
                    // Validate before POST submit
                    if (!this.validate()) {
                        e.preventDefault();
                        return;
                    }

                    // Serialize field data to JSON for POST submission
                    this.preparePostSubmission();

                    // near:me: get the browser location first and send it with the form
                    // (unless a distance field already supplies coordinates).
                    if (!this._geoAttached && !this.$form.find('input[name="_atcf_lat"]').val() && this.wantsLocation()) {
                        e.preventDefault();
                        HTAX_Geo.get().then((loc) => {
                            if (loc) {
                                this.$form.append($('<input type="hidden" name="_atcf_lat">').val(loc.lat));
                                this.$form.append($('<input type="hidden" name="_atcf_lng">').val(loc.lng));
                            }
                            this._geoAttached = true;
                            this.$form[0].submit();
                        });
                    }
                }
            });

            // Clear text field validation error as the user corrects their input
            this.$form.on('input', '.htax-text-input', (e) => {
                const $wrapper = $(e.target).closest('.htax-field-wrapper');
                const value = $(e.target).val().trim();
                const minLength = parseInt($(e.target).data('min-length')) || 0;
                if (value.length === 0 || value.length >= minLength) {
                    $wrapper.removeClass('htax-error');
                    $wrapper.find('.htax-validation-message').hide().text('');
                }
            });

            // Also add native event listener as backup (capture phase)
            // This ensures field data is added even if jQuery handler doesn't fire
            if (this.$form[0] && !this.isAjax) {
                this.$form[0].addEventListener('submit', (e) => {
                    this.preparePostSubmission();
                }, true);
            }
        }

        /**
         * Serialize the current show_when and enabled state of all controllers and fields
         * into the htax_ui_state hidden input so PHP can restore it on the next page load.
         */
        saveUiState() {
            const state = {};

            Object.keys(this.controllers).forEach(name => {
                const ctrl = this.controllers[name];
                state['controller:' + name] = ctrl.showWhen;
                state['enabled:controller:' + name] = ctrl.enabled ? 'true' : 'false';
            });

            Object.keys(this.fields).forEach(name => {
                const field = this.fields[name];
                if (field.showWhen !== undefined) {
                    state['field:' + name] = field.showWhen;
                }
                state['enabled:field:' + name] = field.enabled ? 'true' : 'false';
            });

            this.$form.find('input[name="htax_ui_state"]')
                .val(JSON.stringify(state));
        }

        /**
         * Prepare JSON field data for POST submission
         * This preserves the full hierarchical group logic
         */
        preparePostSubmission() {
            // Remove any previously added field data
            this.$form.find('input[name="htax_field_data"]').remove();
            this.$form.find('input.htax-locked-proxy').remove();

            this.saveUiState();

            // Browsers omit disabled inputs from POST. Controller checkboxes locked by
            // min_items enforcement are disabled while checked — add hidden proxy inputs
            // so their values reach PHP's get_post_controller_values().
            this.$form.find('input[type="checkbox"].htax-locked').each(function() {
                const $cb = $(this);
                $('<input type="hidden">')
                    .addClass('htax-locked-proxy')
                    .attr('name', $cb.attr('name'))
                    .val($cb.val())
                    .appendTo($cb.closest('form'));
            });

            const fieldData = this.collectFieldDataHierarchical(this.$form, 'AND', true);

            // Add as JSON hidden field
            $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'htax_field_data')
                .attr('value', JSON.stringify(fieldData))
                .appendTo(this.$form);
        }
        
        /**
         * Toggle form state
         */
        toggleState() {
            if (this.state === 'collapsed') {
                this.expand();
            } else {
                this.collapse();
            }
        }
        
        /**
         * Expand form
         */
        expand() {
            this.state = 'expanded';
            this.syncFormState();

            // Trigger event
            this.$form.trigger('htax:expanded');
        }

        /**
         * Collapse form
         */
        collapse() {
            this.state = 'collapsed';
            this.syncFormState();

            // Trigger event
            this.$form.trigger('htax:collapsed');
        }
        
        /**
         * Sync form state - apply classes and update toggle button accessibility
         */
        syncFormState() {
            // Apply state classes
            this.$form.removeClass('htax-state-collapsed htax-state-expanded');
            this.$form.addClass('htax-state-' + this.state);

            // Update toggle button aria-expanded; CSS handles the text swap via state class
            const $toggle = this.$form.find('.htax-toggle-button');
            if ($toggle.length) {
                $toggle.attr('aria-expanded', this.state === 'expanded' ? 'true' : 'false');
            }

            // Update visibility
            this.updateVisibility();
        }

        /**
         * Update element visibility based on show_when
         */
        updateVisibility() {
            this.$form.find('[class*="htax-show-"]').each((i, el) => {
                const $el = $(el);
                const classes = $el.attr('class').split(' ');
                classes.forEach(cls => {
                    if (cls.startsWith('htax-show-')) {
                        const showWhen = cls.replace('htax-show-', '');
                        this.updateElementVisibility($el, showWhen);
                    }
                });
            });
        }
        
        /**
         * Update element visibility based on show_when rule
         */
        updateElementVisibility($el, showWhen) {
            switch (showWhen) {
                case 'always':
                    $el.show();
                    break;
                case 'expanded':
                    $el.toggle(this.state === 'expanded');
                    break;
                case 'collapsed':
                    $el.toggle(this.state === 'collapsed');
                    break;
            }
        }
        
        /**
         * Apply initial state
         */
        applyInitialState() {
            // First, set up base field configurations
            Object.values(this.fields).forEach(field => {
                if (field.loadInitialConfiguration) {
                    field.loadInitialConfiguration();
                }
            });
            
            // Process each controller's initial actions sequentially
            // This maintains the intuitive order from the widget configuration
            Object.values(this.controllers).forEach(controller => {
                const actions = this.getControllerInitialActions(controller);
                if (actions.length > 0) {
                    // Apply THIS controller's actions in priority order
                    this.executeActionsByPriority(actions);
                }
            });

        }
        
        /**
         * Get initial actions from a controller based on what's checked/selected
         */
        getControllerInitialActions(controller) {
            const actions = [];
            const $wrapper = controller.$wrapper;
            
            switch (controller.type) {
                case 'radio':
                    const $checked = $wrapper.find('input[type="radio"]:checked');
                    if ($checked.length) {
                        const elementActions = this.getActionsFromElement($checked);
                        if (elementActions.length) {
                            actions.push(...elementActions);
                        }
                        // Mark for tracking changes later
                        $checked.attr('data-was-checked', 'true');
                    }
                    break;
                    
                case 'checkbox':
                    $wrapper.find('input[type="checkbox"]:checked').each((i, el) => {
                        const elementActions = this.getActionsFromElement($(el));
                        if (elementActions.length) {
                            actions.push(...elementActions);
                        }
                    });
                    break;
                    
                case 'dropdown':
                    const $selected = $wrapper.find('option:selected');
                    if ($selected.length && $selected.val()) {
                        const elementActions = this.getActionsFromElement($selected);
                        if (elementActions.length) {
                            actions.push(...elementActions);
                        }
                        // Mark for tracking changes later
                        $selected.attr('data-was-selected', 'true');
                    }
                    break;
            }
            
            return actions;
        }
        
        /**
         * Validate form
         */
        validate() {
            let isValid = true;

            // Validate each field
            Object.values(this.fields).forEach(field => {
                if (!field.validate()) {
                    isValid = false;
                }
            });

            // Validate text field min_length
            const $textWrapper = this.$form.find('.htax-field-wrapper[data-field-type="text"]').first();
            if ($textWrapper.length) {
                const $input = $textWrapper.find('.htax-text-input');
                const value = $input.val().trim();
                const minLength = parseInt($input.data('min-length')) || 0;
                if (value.length > 0 && minLength > 0 && value.length < minLength) {
                    const msg = $textWrapper.data('min-length-message') ||
                        HTAX_Search.i18n.min_length_message.replace('{n}', minLength);
                    $textWrapper.addClass('htax-error');
                    $textWrapper.find('.htax-validation-message').text(msg).show();
                    isValid = false;
                }
            }

            return isValid;
        }

        /**
         * Reset all controllers and fields to their initial state
         */
        resetAll() {
            // First reset all controllers to their defaults
            Object.values(this.controllers).forEach(controller => {
                if (typeof controller.reset === 'function') {
                    controller.reset();
                }
            });

            // Then reset all fields to their defaults
            Object.values(this.fields).forEach(field => {
                if (typeof field.reset === 'function') {
                    field.reset();
                }
            });

            // Update visibility
            this.updateVisibility();
        }

        /**
         * Get actions targeting a specific field from all controllers in their current state
         */
        getControllerActionsForField(fieldName) {
            const actions = [];

            Object.values(this.controllers).forEach(controller => {
                const controllerActions = this.getControllerCurrentActions(controller);
                // Filter actions that target this field
                const fieldActions = controllerActions.filter(action =>
                    action.target === fieldName && action.target_type !== 'controller'
                );
                actions.push(...fieldActions);
            });

            return actions;
        }

        /**
         * Get actions from a controller's current state
         */
        getControllerCurrentActions(controller) {
            const actions = [];

            switch (controller.type) {
                case 'radio':
                    const $checkedRadio = controller.$wrapper.find('input[type="radio"]:checked');
                    if ($checkedRadio.length) {
                        const elementActions = this.getActionsFromElement($checkedRadio);
                        actions.push(...elementActions);
                    }
                    break;

                case 'checkbox':
                    controller.$wrapper.find('input[type="checkbox"]:checked').each((i, el) => {
                        const elementActions = this.getActionsFromElement($(el));
                        actions.push(...elementActions);
                    });
                    break;

                case 'dropdown':
                    const $selected = controller.$wrapper.find('option:selected');
                    if ($selected.length && $selected.val()) {
                        const elementActions = this.getActionsFromElement($selected);
                        actions.push(...elementActions);
                    }
                    break;
            }

            return actions;
        }

        /**
         * Parse actions JSON from a DOM element's data-actions attribute.
         * Shared utility used by SearchForm and Controller.
         */
        getActionsFromElement($element) {
            const actionsJson = $element.data('actions');
            if (!actionsJson) return [];
            const actions = typeof actionsJson === 'string' ? JSON.parse(actionsJson) : actionsJson;
            if (Array.isArray(actions)) return actions;
            if (actions.actions) return actions.actions;
            return [];
        }

        /**
         * Execute a prioritized list of actions on this form's fields.
         * Delegates to a Controller instance so that executeAction() has correct
         * access to this.form.fields / this.form.controllers.
         */
        executeActionsByPriority(actions) {
            if (!actions || actions.length === 0) return;
            const anyController = Object.values(this.controllers)[0];
            if (anyController) {
                anyController.executeActionsByPriority(actions);
            }
        }

        /**
         * Perform AJAX search
         */
        performSearch(page = 1) {
            const fieldData = this.collectFieldDataHierarchical(this.$form, 'AND', true);

            // Find results container
            // 1. Check if form specifies a results container ID
            // 2. Fall back to sibling with class htax-search-results
            // 3. Fall back to any element with class htax-search-results (first one)
            let $results;
            const resultsId = this.$form.data('results');
            if (resultsId) {
                $results = $('#' + resultsId);
            }
            if (!$results || $results.length === 0) {
                $results = this.$form.siblings('.htax-search-results').first();
            }
            if (!$results || $results.length === 0) {
                $results = $('.htax-search-results').first();
            }
            if ($results.length === 0) return;

            // Register this instance on the container so the unified sort-change handler can find us
            $results.data('htax-form-instance', this);

            // Show loading
            $results.find('.htax-loading').show();
            $results.find('.htax-results-container').empty();

            // Perform AJAX request — use standalone endpoint to avoid WP metadata overhead
            const filterState = $results.data('htax-filter') || {};
            const postData = {
                action: 'htax_search_posts',
                nonce: this.$form.find('#htax_search_nonce').val(),
                fields: fieldData,
                page: page,
                posts_per_page: this.$form.data('posts-per-page') || 20,
                max_results: this.$form.data('max-results') || 0,
                orderby: $results.data('orderby'),
                min_score: this.$form.data('min-score') || 0,
                image_size: $results.data('image-size') || 'medium',
                htax_filter: Object.keys(filterState).length ? JSON.stringify(filterState) : ''
            };
            const atcfLat = this.$form.find('input[name="_atcf_lat"]').val();
            const atcfLng = this.$form.find('input[name="_atcf_lng"]').val();
            if (atcfLat && atcfLng) {
                postData._atcf_lat = atcfLat;
                postData._atcf_lng = atcfLng;
            }
            const location = !postData._atcf_lat && this.wantsLocation() ? HTAX_Geo.get() : Promise.resolve(null);
            location.then((loc) => {
            if (loc) {
                postData._atcf_lat = loc.lat;
                postData._atcf_lng = loc.lng;
            }
            $.post(HTAX_Search.ajax_url, postData, (response) => {
                $results.find('.htax-loading').hide();

                if (response.success) {
                    this.renderResults(response.data, $results);
                } else {
                    $results.find('.htax-results-container').html(
                        '<div class="htax-error">' + HTAX_Search.i18n.error + '</div>'
                    );
                }
            });
            });
        }

        /**
         * Whether the form's text field uses near:me (and so needs the browser's location).
         */
        wantsLocation() {
            if (!window.HTAX_Geo) return false;
            return this.$form.find('.htax-text-input').toArray().some(el => HTAX_Geo.wantsLocation($(el).val()));
        }

        /**
         * HTML for the server's search notices (e.g. near:me without a known location).
         */
        noticesHtml(notices) {
            return (notices || []).map(n => '<p class="htax-search-notice" role="status">' + this.escapeHtml(n) + '</p>').join('');
        }

        /**
         * Collect field data as a hierarchical group structure.
         * All taxonomy fields (pills, checklist, hidden) at the top level are placed into
         * an implicit root AND group alongside any declared [ht_field_group] children.
         * The text field (if present at the top level) is collected separately under
         * text_search — it is not part of the AND/OR taxonomy group logic.
         */
        collectFieldDataHierarchical($container, defaultRelation = 'AND', isRoot = false) {
            const structure = {
                relation: $container.data('group-relation') || defaultRelation,
                fields: {},
                groups: []
            };

            // At root level only: collect the top-level text field into a separate key.
            // Text fields are not part of the AND/OR taxonomy group logic — they filter
            // the result set as a whole. Only one text field per form is used.
            if (isRoot) {
                const $textWrapper = $container.children('.htax-field-wrapper[data-field-type="text"]').first();
                if ($textWrapper.length) {
                    const $input = $textWrapper.find('.htax-text-input');
                    const value = $input.val() ? $input.val().trim() : '';
                    if (value.length > 0) {
                        structure.text_search = {
                            value: value,
                            search_in: $textWrapper.data('search-in') || 'all',
                            min_length: parseInt($input.data('min-length')) || 3
                        };
                    }
                }
            }

            // Collect direct child fields (not inside nested groups)
            $container.children('.htax-field-wrapper').each((i, el) => {
                const $field = $(el);
                // Skip filter fields hidden by show_when="never" — values are preserved but
                // should not participate in search. ht_hidden_field (type="hidden") is always included.
                if ($field.hasClass('htax-hidden') && $field.data('field-type') !== 'hidden') return;
                const fieldName = $field.data('field-name');
                const field = this.fields[fieldName];

                if (field) {
                    const value = field.getValue();
                    if (value && value.length > 0) {
                        const entry = {
                            terms: value,
                            taxonomy: field.taxonomy || 'post_tag',
                            match: field.match || 'any'
                        };
                        if (field.filterby) entry.filterby = field.filterby;
                        const expandTypes = $field.data('expand-types');
                        if (expandTypes) entry.expand_types = String(expandTypes);
                        structure.fields[fieldName] = entry;
                    }
                } else if ($field.data('atcf-distance')) {
                    const $postalInput  = $field.find('.atcf-postal-input');
                    const $radiusSelect = $field.find('.atcf-radius-select');
                    const postalCode    = ($postalInput.val() || '').trim();
                    if (!postalCode) return;
                    const postalName = ($postalInput.attr('name') || '').replace('[postal_code]', '') || 'htax_distance';
                    const entry = {
                        type:        'distance',
                        taxonomy:    $field.data('taxonomy')    || '',
                        unit:        $field.data('unit')        || 'miles',
                        country:     $field.data('country')     || 'US',
                        no_location: $field.data('no-location') || 'include',
                        postal_code: postalCode,
                        radius:      parseFloat($radiusSelect.val()) || 10,
                    };
                    ['include-term-options','exclude-term-options','include-group-options','exclude-group-options','include-descendant-options','exclude-descendant-options','expand-types'].forEach(attr => {
                        const val = $field.data(attr);
                        if (val) entry[attr.replace(/-/g, '_')] = String(val);
                    });
                    structure.fields[postalName] = entry;
                }
            });

            // Recursively collect nested groups
            $container.children('.htax-field-group').each((i, el) => {
                if ($(el).hasClass('htax-hidden')) return;
                const groupData = this.collectFieldDataHierarchical($(el));
                // Only include group if it has fields or sub-groups
                if (Object.keys(groupData.fields).length > 0 || groupData.groups.length > 0) {
                    structure.groups.push(groupData);
                }
            });

            return structure;
        }
        
        /**
         * Render search results
         */
        renderResults(data, $container) {
            const template = $container.data('template');
            // jQuery .data() converts "true"/"false" attribute strings to booleans.
            const isOn = (value) => value === true || value === 'true';
            const showThumbnail = isOn($container.data('show-thumbnail'));
            const showExcerpt = isOn($container.data('show-excerpt'));
            const showMeta = isOn($container.data('show-meta'));
            const format = $container.data('format') || 'div';
            const prependTpl = $container.data('prepend') || '';
            const appendTpl  = $container.data('append')  || '';

            const notices = this.noticesHtml(data.notices);

            if (data.posts.length === 0) {
                const noResultsMsg = $container.data('no-results-message') || HTAX_Search.i18n.no_results;
                $container.find('.htax-results-container').html(
                    notices + '<div class="htax-no-results">' + this.escapeHtml(noResultsMsg) + '</div>'
                );
                return;
            }

            let html = notices;
            if (format === 'ul' || format === 'ol') {
                html += '<' + format + ' class="htax-result-list">';
                data.posts.forEach(post => {
                    html += '<li class="htax-result-item">';
                    if (prependTpl) html += this.processPostPlaceholders(prependTpl, post);
                    html += '<a href="' + post.permalink + '">' + this.escapeHtml(post.title) + '</a>';
                    if (appendTpl)  html += this.processPostPlaceholders(appendTpl, post);
                    html += '</li>';
                });
                html += '</' + format + '>';
            } else {
                data.posts.forEach(post => {
                    html += this.renderPost(post, template, showThumbnail, showExcerpt, showMeta, prependTpl, appendTpl);
                });
            }

            $container.find('.htax-results-container').html(html);
            this.renderPaginationNav(data.current_page, data.max_pages, $container);
        }

        processPostPlaceholders(tpl, post) {
            return tpl
                .replace(/\{id\}/g,            post.id            || '')
                .replace(/\{title\}/g,          this.escapeHtml(post.title   || ''))
                .replace(/\{url\}/g,            post.permalink     || '')
                .replace(/\{date\}/g,           this.escapeHtml(post.date    || ''))
                .replace(/\{modified\}/g,       this.escapeHtml(post.modified || ''))
                .replace(/\{author\}/g,         this.escapeHtml(post.author  || ''))
                .replace(/\{excerpt\}/g,        this.escapeHtml(post.excerpt || ''))
                .replace(/\{comment_count\}/g,  post.comment_count != null ? post.comment_count : '');
        }

        /**
         * Render prev/next pagination nav into the results container's nav element.
         */
        renderPaginationNav(currentPage, maxPages, $container) {
            const $nav = $container.find('.htax-search-results-nav');
            if (!$nav.length) return;

            if (maxPages <= 1) {
                $nav.empty();
                return;
            }

            let html = '';
            if (currentPage > 1) {
                html += '<button type="button" class="htax-results-prev" data-page="' + (currentPage - 1) + '">'
                      + '&#171; ' + HTAX_Search.i18n.prev
                      + '</button>';
            }
            html += '<span class="htax-results-page-info">'
                  + HTAX_Search.i18n.page_of.replace('%1$d', currentPage).replace('%2$d', maxPages)
                  + '</span>';
            if (currentPage < maxPages) {
                html += '<button type="button" class="htax-results-next" data-page="' + (currentPage + 1) + '">'
                      + HTAX_Search.i18n.next + ' &#187;'
                      + '</button>';
            }
            $nav.html(html);
        }
        
        /**
         * Render single post
         */
        renderPost(post, template, showThumbnail, showExcerpt, showMeta, prependTpl, appendTpl) {
            let html = '<article class="htax-result-item htax-result-' + template + '">';

            if (prependTpl) html += this.processPostPlaceholders(prependTpl, post);

            if (showThumbnail && post.thumbnail) {
                html += '<div class="htax-result-thumbnail">';
                html += '<a href="' + post.permalink + '">';
                // post.thumbnail is an image URL.
                html += '<img src="' + this.escapeHtml(post.thumbnail) + '" alt="" loading="lazy" />';
                html += '</a></div>';
            }

            html += '<div class="htax-result-content">';
            html += '<h3 class="htax-result-title">';
            html += '<a href="' + post.permalink + '">' + this.escapeHtml(post.title) + '</a>';
            html += '</h3>';

            if (showExcerpt && post.excerpt) {
                html += '<div class="htax-result-excerpt">' + this.escapeHtml(post.excerpt) + '</div>';
            }

            if (showMeta) {
                html += '<div class="htax-result-meta">';
                html += '<span class="htax-meta-date">' + this.escapeHtml(post.date) + '</span>';
                html += ' <span class="htax-meta-author">' + HTAX_Search.i18n.by_author.replace('%s', this.escapeHtml(post.author)) + '</span>';
                html += '</div>';
            }

            html += '</div>';
            if (appendTpl) html += this.processPostPlaceholders(appendTpl, post);
            html += '</article>';

            return html;
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    /**
     * Base Field class
     */
    class FieldBase {
        constructor($wrapper, form) {
            this.$wrapper = $wrapper;
            this.form = form;
            this.name = $wrapper.data('field-name');

            // Derive initial enabled state from PHP-rendered class
            this.enabled = !$wrapper.hasClass('htax-disabled');
        }
        
        /**
         * Parse a comma-separated string of numeric IDs into an integer array.
         */
        parseIdList(value) {
            if (value === null || value === undefined || value === '') return [];
            return String(value).split(',').map(id => parseInt(id.trim(), 10)).filter(id => !isNaN(id));
        }

        
        /**
         * Selection actions (field-level add_* / remove_* attributes and controller actions).
         * Each returns how many requested items were skipped because they are not in the
         * field's option pool. Overridden by the field types that support them.
         */
        addTerms(termIds) { return 0; }
        removeTerms(termIds) { return 0; }
        addGroups(groupIds) { return 0; }
        removeGroups(groupIds) { return 0; }

        /**
         * This field's entry in the form's controller group map: { groups: {gid: [termIds]}, terms: {id: data} }.
         */
        _groupMap() {
            return (this.form && this.form.groupMembers && this.form.groupMembers[this.name]) || { groups: {}, terms: {} };
        }

        /**
         * Member term IDs of these groups from the group map, and how many groups it doesn't know.
         */
        _groupMemberIds(groupIds) {
            const groups = this._groupMap().groups || {};
            const ids = new Set();
            let unknown = 0;
            (groupIds || []).forEach(g => {
                const members = groups[parseInt(g, 10)];
                if (members) {
                    members.forEach(id => ids.add(parseInt(id, 10)));
                } else {
                    unknown++;
                }
            });
            return { ids: [...ids], unknown };
        }

        /**
         * Narrow the active pool to these groups. Overridden by PillsField and HiddenField.
         */
        includeGroupOptions(groupIds) {}

        /**
         * Remove these groups from the active-group constraint, widening the pool. Overridden by PillsField and HiddenField.
         */
        excludeGroupOptions(groupIds) {}

        /**
         * Check/select all items that are descendants of the given group IDs.
         * Overridden by ChecklistField (DOM toggle) and PillsField (pill selection).
         */
        addDescendants(groupIds) {}

        /**
         * Uncheck/deselect all items that are descendants of the given group IDs.
         */
        removeDescendants(groupIds) {}

        /**
         * Returns true if id is in the field's declared complete option pool.
         * Overridden by field types that have a pool (HiddenField, PillsField, ChecklistField).
         */
        _isKnownTerm(id) { return true; }

        /**
         * Show field
         */
        show() {
            this.$wrapper.show();
        }
        
        /**
         * Hide field
         */
        hide() {
            this.$wrapper.hide();
        }
        
        /**
         * Validate field (to be implemented by subclasses)
         */
        validate() {
            return true;
        }

        /**
         * Clear current selections (to be implemented by subclasses)
         */
        clear() {
            // Default implementation - subclasses should override
        }

        /**
         * Clear all options from the field (to be implemented by subclasses)
         */
        clearOptions() {
            // Default implementation - subclasses should override
        }

        /**
         * Reset options to field's initial defaults (to be implemented by subclasses)
         */
        resetOptions() {
            // Default implementation - subclasses should override
        }

        /**
         * Reset selection to default AND reset options (to be implemented by subclasses)
         */
        reset() {
            // Default implementation - subclasses should override
        }

        /**
         * Set field show/when visibility state
         */
        setShowWhen(value) {
            const validValues = ['always', 'expanded', 'collapsed', 'never'];
            if (!validValues.includes(value)) return;

            this.showWhen = value;
            this.$wrapper.removeClass('htax-show-expanded htax-show-collapsed htax-hidden');

            switch (value) {
                case 'never':
                    this.$wrapper.addClass('htax-hidden');
                    break;
                case 'expanded':
                    this.$wrapper.addClass('htax-show-expanded');
                    break;
                case 'collapsed':
                    this.$wrapper.addClass('htax-show-collapsed');
                    break;
                // 'always' needs no special class
            }

            // Fields that start hidden defer applyMaxRows until first revealed.
            if (value !== 'never' && typeof this.applyMaxRows === 'function') {
                this.applyMaxRows();
            }
        }

        /**
         * Enable field
         */
        enable() {
            this.enabled = true;
            this.$wrapper.removeClass('htax-disabled');
            this.$wrapper.find('input, select, textarea').prop('disabled', false);
        }

        /**
         * Disable field
         */
        disable() {
            this.enabled = false;
            this.$wrapper.addClass('htax-disabled');
            this.$wrapper.find('input, select, textarea').prop('disabled', true);
        }
    }

    /**
     * PillsField class
     */
    class PillsField extends FieldBase {
        constructor($wrapper, form) {
            super($wrapper, form);
            
            // Configuration
            this.taxonomy = $wrapper.data('taxonomy') || 'post_tag';
            this.taxonomyType = $wrapper.data('taxonomy-type') || 'post';
            this.filterby = $wrapper.data('filterby') || '';
            this.showIcons = $wrapper.data('show-icons') || 'when_available';
            this.minItems = parseInt($wrapper.data('min-items')) || 0;
            this.maxItems = parseInt($wrapper.data('max-items')) || 0;
            this.showWhen = $wrapper.data('show-when') || 'always';
            this.match = $wrapper.data('match') || 'any';
            this.prefetch        = $wrapper.data('prefetch') !== false;
            this.suggestOnFocus  = $wrapper.data('suggest-on-focus') !== false;

            // Internal state management
            this._availableTerms = new Set();  // Pool of selectable terms (for specific term IDs)
            this._selectedTerms = new Set();   // Currently selected terms
            this._termData = {}; // Store term info including icons

            // Prefetch-based option pool (for group-declared universes)
            this._allOptionsById    = new Map(); // termId → {id, name, icon_url, placeholder_color}
            this._allOptionsByGroup = new Map(); // groupId → Set<termId>
            this._activeGroups      = new Set(); // group IDs currently active (from controller actions)
            this._activeOptions     = [];        // computed from _activeGroups ∩ _allOptionsById
            this._prefetchDone      = false;
            this._pendingAddDescendants    = []; // group IDs queued before prefetch completes
            this._pendingRemoveDescendants = [];

            this._hasValidationError = false;
            this.form = form;

            this.$container = $wrapper.find('.htax-pills-container');
            this.$input = $wrapper.find('.htax-pills-input');
            this._originalPlaceholder = this.$input.attr('placeholder') || '';
            this.$hidden = $wrapper.find('.htax-pills-value');
            this.$availableHidden = $wrapper.find('.htax-pills-available');
            this.$termDataHidden = $wrapper.find('.htax-pills-termdata');
            this.$validationMessage = $wrapper.find('.htax-validation-message');

            this.init();
        }
        
        init() {
            this.loadInitialConfiguration();
            this.setupAutocomplete();
            this.bindEvents();
            this.applyMaxRows();
        }

        /**
         * Apply max-rows height constraint to the pills container.
         * Pill height derived from our CSS constants (font-size:13px on .htax-pill,
         * line-height:1.4, padding:1px, border:1px). Input height measured from the DOM.
         */
        applyMaxRows() {
            const maxRows = this.$container.data('max-rows');
            if (!maxRows || maxRows <= 0) return;
            // .htax-pill has explicit font-size:13px — do not use container's inherited size.
            const pillH = Math.ceil(13 * 1.4) + 4; // text(19) + padding(2) + border(2) = 23px
            const gapPx = parseFloat(this.$container.css('row-gap')) || 4;
            const padV  = parseFloat(this.$container.css('padding-top') || 0)
                        + parseFloat(this.$container.css('padding-bottom') || 0);
            const maxH = maxRows * (pillH + gapPx) + padV;
            const el = this.$container[0];
            el.style.setProperty('max-height', maxH + 'px', 'important');
            el.style.setProperty('overflow-y', 'auto', 'important');
            el.style.setProperty('overflow-x', 'hidden', 'important');
        }

        /**
         * Load initial configuration in correct order
         */
        loadInitialConfiguration() {
            // Prevent duplicate initialization
            if (this._configurationLoaded) {
                return;
            }
            this._configurationLoaded = true;

            // 1. Restore available terms and term data from POST if available (takes precedence)
            this.restoreAvailableTerms();
            this.restoreTermData();

            // 2. Build option pool from data attributes.
            // Always call processInitialOptions so the group-based prefetch fires and
            // populates _allOptionsById / _activeOptions for autocomplete suggestions.
            // The specific-term calls (includeTermOptions/excludeTermOptions) inside are
            // idempotent (Set operations), so calling them again after POST restore is safe.
            this.processInitialOptions();

            // 3. Apply initial selection
            this.processInitialSelection();

            // 4. Validate and clean up
            this.validateSelection();
        }

        /**
         * Restore available terms from hidden field (POST preservation)
         */
        restoreAvailableTerms() {
            const availableValue = this.$availableHidden.val();
            if (availableValue) {
                const termIds = availableValue.split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                termIds.forEach(id => this._availableTerms.add(id));
            }
        }

        /**
         * Restore term metadata from hidden field (POST preservation)
         */
        restoreTermData() {
            const termDataValue = this.$termDataHidden.val();
            const trimmedValue = termDataValue ? termDataValue.trim() : '';

            // Only attempt to parse if we have actual JSON content (starts with { or [)
            if (trimmedValue && (trimmedValue.startsWith('{') || trimmedValue.startsWith('['))) {
                try {
                    // Decode HTML entities that were encoded by htmlspecialchars()
                    const decodedValue = this.decodeHTMLEntities(trimmedValue);
                    const termData = JSON.parse(decodedValue);
                    // Merge restored data into _termData
                    Object.assign(this._termData, termData);
                } catch (e) {
                    console.error('Pills: Failed to parse term data JSON:', e, 'Value was:', termDataValue);
                    console.error('Pills: Decoded value was:', this.decodeHTMLEntities(trimmedValue).substring(0, 100));
                }
            }
        }

        /**
         * Decode HTML entities (reverse of htmlspecialchars)
         */
        decodeHTMLEntities(text) {
            const textarea = document.createElement('textarea');
            textarea.innerHTML = text;
            return textarea.value;
        }

        /**
         * Escape HTML special characters to prevent XSS
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Save available terms and term data to hidden fields (before form submission)
         */
        saveAvailableTerms() {
            const availableArray = Array.from(this._availableTerms);
            this.$availableHidden.val(availableArray.join(','));

            // Save term data as JSON
            const termDataJson = JSON.stringify(this._termData);
            this.$termDataHidden.val(termDataJson);
        }

        /**
         * Process option pool configuration.
         * For group-based fields with prefetch=true (default), triggers one AJAX call to load
         * the full option universe; subsequent filtering happens client-side via _activeGroups.
         * With prefetch=false, group loading is skipped — autocomplete fetches from the server
         * on demand. Specific-term lists are always populated synchronously.
         */
        processInitialOptions() {
            if (this.prefetch) {
                const includeGroups = this.$wrapper.data('include-group-options');
                if (includeGroups) {
                    this.prefetchOptions(this.parseIdList(includeGroups));
                }

                const includeDescendants = this.$wrapper.data('include-descendant-options');
                if (includeDescendants) {
                    this.prefetchOptions(this.parseIdList(includeDescendants), true);
                }
            }

            const includeTerms = this.$wrapper.data('include-term-options');
            if (includeTerms) {
                this.includeTermOptions(this.parseIdList(includeTerms));
            }

            const excludeTerms = this.$wrapper.data('exclude-term-options');
            if (excludeTerms) {
                this.excludeTermOptions(this.parseIdList(excludeTerms));
            }
        }

        /**
         * Fetch the full option universe for this field in one AJAX call.
         * Populates _allOptionsById and _allOptionsByGroup; also seeds _termData
         * so pills added later (POST restore, addPill) find their name/icon cached.
         */
        prefetchOptions(groupIds, descendantMode = false) {
            if (!groupIds || groupIds.length === 0) return;
            const showIcons = this.showIcons || 'never';
            const nonce = HTAX_Search.nonce || (this.form ? this.form.$form.find('#htax_search_nonce').val() : '');
            let requestData;
            if (descendantMode) {
                requestData = { action: 'htax_prefetch_options', taxonomy: this.taxonomy, roots: groupIds, taxonomy_type: this.taxonomyType, show_icons: showIcons, nonce };
                const excludeDesc = this.$wrapper.data('exclude-descendant-options');
                if (excludeDesc) {
                    requestData.exclude_descendants = this.parseIdList(String(excludeDesc));
                }
            } else {
                requestData = { action: 'htax_prefetch_options', taxonomy: this.taxonomy, groups: groupIds, show_icons: showIcons, nonce };
                if (this.taxonomyType === 'term') {
                    requestData.taxonomy_type = 'term';
                }
            }
            $.ajax({
                url: HTAX_Search.ajax_url,
                type: 'GET',
                dataType: 'json',
                data: requestData,
                success: (response) => {
                    if (!response.success) return;
                    const { terms, groups } = response.data;
                    this._allOptionsById.clear();
                    this._allOptionsByGroup.clear();
                    for (const [idStr, data] of Object.entries(terms)) {
                        const id = parseInt(idStr);
                        const option = { id, name: data.name, count: data.count || 0, icon_url: data.icon_url || null, placeholder_color: data.placeholder_color || null };
                        this._allOptionsById.set(id, option);
                        this._termData[id] = { name: data.name, count: data.count || 0, icon_url: data.icon_url || null, placeholder_color: data.placeholder_color || null };
                    }
                    for (const [gidStr, termIds] of Object.entries(groups)) {
                        this._allOptionsByGroup.set(parseInt(gidStr), new Set(termIds.map(Number)));
                    }
                    // Groups named only by controller actions: membership from the form's
                    // group map, so include/exclude_group_options can narrow the pool to them.
                    for (const [gidStr, termIds] of Object.entries(this._groupMap().groups || {})) {
                        const gid = parseInt(gidStr, 10);
                        if (!this._allOptionsByGroup.has(gid)) {
                            this._allOptionsByGroup.set(gid, new Set(termIds.map(Number)));
                        }
                    }
                    this._prefetchDone = true;
                    this._computeActiveOptions();
                    // Initial pills (add_terms) may have been created before term names arrived.
                    this._refreshPillLabels();
                    if (this._pendingAddDescendants.length > 0) {
                        this._applyDescendantsToSelection(this._pendingAddDescendants, true);
                        this._pendingAddDescendants = [];
                    }
                    if (this._pendingRemoveDescendants.length > 0) {
                        this._applyDescendantsToSelection(this._pendingRemoveDescendants, false);
                        this._pendingRemoveDescendants = [];
                    }
                }
            });
        }

        /**
         * Re-render the label and icon of existing pills from _termData.
         * Pills added before the prefetch completed carry a placeholder label.
         */
        _refreshPillLabels() {
            this.$container.find('.htax-pill').each((_, el) => {
                const $pill = $(el);
                const id = parseInt($pill.data('id'));
                const data = this._termData[id];
                if (!data) return;
                $pill.find('.htax-pill-text').text(data.name);
                $pill.find('.htax-pill-icon, .htax-pill-placeholder').remove();
                const iconHtml = this.getTermIconHtml(id, data);
                if (iconHtml) {
                    $pill.prepend(iconHtml);
                }
            });
            this.sortPillsAlphabetically();
        }

        /**
         * Recompute _activeOptions from the current _activeGroups set and the prefetched universe.
         * Empty _activeGroups = no restriction = all universe terms are active.
         */
        _computeActiveOptions() {
            if (!this._prefetchDone) return;
            if (this._activeGroups.size === 0) {
                this._activeOptions = Array.from(this._allOptionsById.values());
            } else {
                const activeSet = new Set();
                for (const gid of this._activeGroups) {
                    const terms = this._allOptionsByGroup.get(gid);
                    if (terms) terms.forEach(tid => activeSet.add(tid));
                }
                this._activeOptions = Array.from(activeSet)
                    .map(tid => this._allOptionsById.get(tid))
                    .filter(Boolean);
            }
        }

        /**
         * Process initial selection
         */
        processInitialSelection() {
            const $wrapper = this.$wrapper;

            // Check if hidden field has a value (from POST data)
            const hiddenValue = this.$hidden.val();

            if (hiddenValue) {
                // Clear selected terms before POST restoration to avoid orphaned selections
                // (terms that were in _selectedTerms but didn't have pills created)
                this._selectedTerms.clear();

                // Hidden field value takes precedence (from POST submission)
                // Parse comma-separated IDs into array of integers
                const termIds = hiddenValue.split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                this.addTerms(termIds);
            } else {
                // Use data attributes for initial configuration

                // Add terms
                const addTerms = $wrapper.data('add-terms');
                if (addTerms) {
                    const termIds = String(addTerms).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                    this.addTerms(termIds);
                }

                // Remove terms
                const removeTerms = $wrapper.data('remove-terms');
                if (removeTerms) {
                    const termIds = String(removeTerms).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                    this.removeTerms(termIds);
                }

                // Add descendants
                const addDescendants = $wrapper.data('add-descendants');
                if (addDescendants) {
                    const groupIds = String(addDescendants).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                    this.addDescendants(groupIds);
                }

                // Remove descendants
                const removeDescendants = $wrapper.data('remove-descendants');
                if (removeDescendants) {
                    const groupIds = String(removeDescendants).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                    this.removeDescendants(groupIds);
                }
            }
        }

        /**
         * Include terms in option pool
         */
        includeTermOptions(termIds) {
            termIds.forEach(id => this._availableTerms.add(id));
            this.updateAutocomplete();
        }
        
        /**
         * Exclude terms from option pool
         */
        excludeTermOptions(termIds) {
            termIds.forEach(id => {
                this._availableTerms.delete(id);
                // Auto-cleanup: remove from selection if no longer available
                if (this._selectedTerms.has(id)) {
                    this.removeTerm(id);
                }
            });
            this.updateAutocomplete();
        }

        /**
         * Add groups to the active set, recompute available options.
         * Called by controller actions; field-level universe is declared via prefetchOptions().
         */
        includeGroupOptions(groupIds) {
            if (!groupIds || groupIds.length === 0) return;
            groupIds.forEach(id => this._activeGroups.add(id));
            this._computeActiveOptions();
        }

        addDescendants(groupIds) {
            if (!groupIds || groupIds.length === 0) return 0;
            if (!this._prefetchDone) { this._pendingAddDescendants.push(...groupIds); return 0; }
            return this._applyDescendantsToSelection(groupIds, true);
        }

        removeDescendants(groupIds) {
            if (!groupIds || groupIds.length === 0) return 0;
            if (!this._prefetchDone) { this._pendingRemoveDescendants.push(...groupIds); return 0; }
            return this._applyDescendantsToSelection(groupIds, false);
        }

        _applyDescendantsToSelection(groupIds, add) {
            const termIds = [];
            groupIds.forEach(gid => {
                const terms = this._allOptionsByGroup.get(gid);
                if (terms) terms.forEach(tid => termIds.push(tid));
            });
            return add ? this.addTerms(termIds) : this.removeTerms(termIds);
        }

        _isKnownTerm(id) {
            if (!this.prefetch) return true;
            return !this._prefetchDone || this._allOptionsById.has(id);
        }

        /**
         * Remove groups from the active set, recompute available options,
         * and animate-remove any selected pills that are no longer in the active set.
         */
        excludeGroupOptions(groupIds) {
            if (!groupIds || groupIds.length === 0) return;
            groupIds.forEach(id => this._activeGroups.delete(id));
            this._computeActiveOptions();

            if (this._prefetchDone && this._selectedTerms.size > 0) {
                const activeIds = new Set(this._activeOptions.map(o => o.id));
                const toRemove = [...this._selectedTerms].filter(id => !activeIds.has(id));
                if (toRemove.length > 0) {
                    this._animateRemovePills(toRemove);
                }
            }
        }

        _animateRemovePills(termIds) {
            termIds.forEach(termId => {
                const $pill = this.$container.find(`.htax-pill[data-id="${termId}"]`);
                if (!$pill.length) return;
                $pill.addClass('htax-pill-removing');
                setTimeout(() => {
                    $pill.fadeOut(400, () => {
                        this.removePill($pill);
                    });
                }, 2400);
            });
        }

        /**
         * Add terms to selection. Only terms in the declared option pool are accepted;
         * unknown terms are counted as skipped and returned.
         */
        addTerms(termIds) {
            let skipped = 0;
            termIds.forEach(id => {
                if (!this._isKnownTerm(id)) { skipped++; return; }
                this._availableTerms.add(id);
                this.addPill(id);
            });
            this.updateValue();
            return skipped;
        }

        /**
         * Add the member terms of these groups (term mode: the groups themselves), resolved
         * from the form's group map. Returns skipped terms plus groups the map doesn't know.
         */
        addGroups(groupIds) {
            const { ids, unknown } = this._groupMemberIds(groupIds);
            for (const [id, data] of Object.entries(this._groupMap().terms || {})) {
                if (!this._termData[id]) {
                    this._termData[id] = data;
                }
            }
            return unknown + (ids.length ? this.addTerms(ids) : 0);
        }

        /**
         * Remove the member terms of these groups from the selection. Always succeeds; returns 0.
         */
        removeGroups(groupIds) {
            const { ids } = this._groupMemberIds(groupIds);
            if (ids.length) {
                this.removeTerms(ids);
            }
            return 0;
        }

        /**
         * Remove terms from selection. Always succeeds; returns 0.
         */
        removeTerms(termIds) {
            termIds.forEach(id => {
                if (this._selectedTerms.has(id)) {
                    this.removePill(this.$container.find(`.htax-pill[data-id="${id}"]`));
                }
            });
            this.updateValue();
            return 0;
        }
        
        /**
         * Validate selection against available pool
         */
        validateSelection() {
            const invalidTerms = [];
            this._selectedTerms.forEach(id => {
                if (!this._availableTerms.has(id)) {
                    invalidTerms.push(id);
                }
            });
            
            // Silently remove invalid selections
            invalidTerms.forEach(id => {
                this._selectedTerms.delete(id);
                this.removePill(id);
            });
        }
    
        
        /**
         * Get existing pill IDs (matching admin UI htax-term-edit.js:361-367)
         */
        getExistingIds() {
            const ids = [];
            this.$container.find('.htax-pill').each(function() {
                ids.push($(this).data('id'));
            });
            return ids;
        }

        /**
         * Setup autocomplete.
         * prefetch=true (default): client-side filtering against the prefetched universe.
         * prefetch=false: AJAX search-as-you-type via htax_autocomplete_options; active groups
         *   are passed as a server-side filter so controller narrowing still works.
         */
        setupAutocomplete() {
            const self = this;

            // Returns the position of needle in haystack only when preceded by a non-letter
            // (or at position 0). Mirrors htax_find_after_non_letter() in ajax.php.
            function findAfterNonLetter(haystack, needle) {
                let pos = 0;
                while ((pos = haystack.indexOf(needle, pos)) !== -1) {
                    if (pos === 0 || !/[a-zA-Z]/.test(haystack[pos - 1])) return pos;
                    pos++;
                }
                return -1;
            }

            function buildItem(o) {
                return {
                    id: o.id,
                    value: o.name,
                    label: self.escapeHtml(o.name),
                    icon_url: o.icon_url || null,
                    placeholder_color: o.placeholder_color || null,
                    taxonomy: self.taxonomy
                };
            }

            const prefetchSource = function(request, response) {
                if (!self._prefetchDone) { response([]); return; }

                const len = request.term.length;
                const existingIds = new Set(self.getExistingIds());
                let candidates = self._activeOptions.filter(o => !existingIds.has(o.id));

                if (len === 0) {
                    candidates = candidates.slice().sort((a, b) => b.count - a.count);
                } else {
                    // 1 char: three buckets, count-ordered within each bucket.
                    // 2+ chars: three buckets, alphabetical within each bucket.
                    const needle  = request.term.toLowerCase();
                    const byCount = (a, b) => b.count - a.count;
                    const alpha   = (a, b) => a.name.localeCompare(b.name);
                    const rank    = len === 1 ? byCount : alpha;
                    const starts = [], boundary = [], contains = [];
                    candidates.forEach(o => {
                        const name = o.name.toLowerCase();
                        if (name.startsWith(needle)) {
                            starts.push(o);
                        } else if (findAfterNonLetter(name, needle) !== -1) {
                            boundary.push(o);
                        } else if (name.includes(needle)) {
                            contains.push(o);
                        }
                    });
                    candidates = [...starts.sort(rank), ...boundary.sort(rank), ...contains.sort(rank)];
                }

                response(candidates.slice(0, 10).map(buildItem));
            };

            const ajaxSource = function(request, response) {
                const nonce = HTAX_Search.nonce || (self.form ? self.form.$form.find('#htax_search_nonce').val() : '');
                const existingIds = new Set(self.getExistingIds());

                // Use active groups if a controller has narrowed the pool; otherwise
                // fall back to the field's base groups from its shortcode declaration.
                const groupIds = self._activeGroups.size > 0
                    ? [...self._activeGroups]
                    : self.parseIdList(self.$wrapper.data('include-group-options'));

                $.ajax({
                    url: HTAX_Search.ajax_url,
                    method: 'GET',
                    data: {
                        action: 'htax_autocomplete_options',
                        taxonomy: self.taxonomy,
                        groups: groupIds.join(','),
                        search: request.term,
                        show_icons: self.showIcons,
                        nonce
                    },
                    success: function(resp) {
                        if (!resp.success) { response([]); return; }
                        const items = resp.data
                            .filter(t => !existingIds.has(t.id))
                            .map(t => {
                                // Cache so addPill() finds the name/icon immediately.
                                self._termData[t.id] = { name: t.name, count: t.count || 0, icon_url: t.icon_url || null, placeholder_color: t.placeholder_color || null };
                                return buildItem(t);
                            });
                        response(items);
                    },
                    error: function() { response([]); }
                });
            };

            this.$input.autocomplete({
                source: this.prefetch ? prefetchSource : ajaxSource,
                minLength: this.suggestOnFocus ? 0 : 1,
                delay: this.prefetch ? 0 : 300,
                appendTo: 'body',
                select: function(event, ui) {
                    event.preventDefault();
                    self.addPill(ui.item.id);
                    self.$input.val('');
                    return false;
                },
                focus: function(event, ui) {
                    event.preventDefault();
                    return false;
                },
                open: function() {
                    const $menu = self.$input.data('ui-autocomplete').menu.element;
                    $menu.off('menufocus.htax menublur.htax')
                        .on('menufocus.htax', function(e, data) {
                            $menu.find('.htax-autocomplete-item').each(function() {
                                this.style.removeProperty('background');
                                this.style.removeProperty('color');
                            });
                            if (data && data.item) {
                                data.item.find('.htax-autocomplete-item').each(function() {
                                    this.style.setProperty('background', '#0073aa', 'important');
                                    this.style.setProperty('color', '#fff', 'important');
                                });
                            }
                        })
                        .on('menublur.htax', function() {
                            $menu.find('.htax-autocomplete-item').each(function() {
                                this.style.removeProperty('background');
                                this.style.removeProperty('color');
                            });
                        });
                },
                close: function() {
                    const ac = self.$input.data('ui-autocomplete');
                    if (ac && ac.menu) {
                        ac.menu.element.find('.htax-autocomplete-item').each(function() {
                            this.style.removeProperty('background');
                            this.style.removeProperty('color');
                        });
                    }
                }
            }).data('ui-autocomplete')._renderItem = function(ul, item) {
                const $li = $('<li>').css({'padding': '0', 'margin': '0'});
                const $div = $('<div>').addClass('htax-autocomplete-item').css({'display': 'flex', 'align-items': 'center'});
                $div[0].style.setProperty('padding', '2px 4px', 'important');
                if (self.showIcons !== 'never') {
                    if (item.icon_url) {
                        $div.append(`<img src="${item.icon_url}" class="htax-autocomplete-icon" alt="" data-taxonomy="${item.taxonomy || self.taxonomy || ''}" />`);
                    } else if (self.showIcons === 'always' && item.placeholder_color) {
                        $div.append(`<span class="htax-autocomplete-placeholder" style="background-color: ${item.placeholder_color}" data-taxonomy="${item.taxonomy || self.taxonomy || ''}"></span>`);
                    }
                }
                $div.append(`<span class="htax-autocomplete-text">${item.label}</span>`);
                return $li.append($div).appendTo(ul);
            };

            // Open dropdown on focus. When suggestOnFocus is false, only trigger
            // the search if the input already has text (so the user must type first).
            this.$input.on('focus', function() {
                if (self.suggestOnFocus || $(this).val().length > 0) {
                    $(this).autocomplete('search', $(this).val());
                }
            });

        }

        /**
         * Bind events
         */
        bindEvents() {
            // Save available terms before form submission
            this.$wrapper.closest('form').on('submit', () => {
                this.saveAvailableTerms();
            });

            // Remove pill (matching admin UI class)
            this.$container.on('click', '.htax-remove', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const $pill = $(e.target).closest('.htax-pill');
                this.removePill($pill);
            });

            // Pill selection
            this.$container.on('click', '.htax-pill', (e) => {
                if ($(e.target).hasClass('htax-remove') || $(e.target).closest('.htax-remove').length) {
                    return;
                }
                this.$container.find('.htax-pill').removeClass('selected');
                $(e.currentTarget).addClass('selected');
            });

            // Container click to focus input
            this.$container.on('click', (e) => {
                if (!$(e.target).hasClass('htax-pill') &&
                    !$(e.target).closest('.htax-pill').length &&
                    !$(e.target).hasClass('htax-pills-input')) {
                    this.$container.find('.htax-pill').removeClass('selected');
                    this.$input.focus();
                }
            });

            // Keyboard navigation (matching admin UI)
            this.$input.on('keydown', (e) => {
                const $pills = this.$container.find('.htax-pill');
                const inputValue = this.$input.val();

                if (e.which === 8) { // Backspace
                    if (inputValue === '' && $pills.length > 0) {
                        const $selected = $pills.filter('.selected');
                        if ($selected.length > 0) {
                            // Pill is selected - delete it and select previous/next
                            const $prev = $selected.prev('.htax-pill');
                            const $next = $selected.next('.htax-pill');
                            $selected.find('.htax-remove').trigger('click');

                            // After removal, select previous pill (or next if no previous)
                            setTimeout(() => {
                                if ($prev.length > 0) {
                                    $prev.addClass('selected');
                                } else if ($next.length > 0) {
                                    $next.addClass('selected');
                                }
                            }, 0);
                        } else {
                            // No selection - select last pill
                            $pills.removeClass('selected');
                            $pills.last().addClass('selected');
                        }
                        e.preventDefault();
                    }
                } else if (e.which === 37) { // Left arrow
                    // Only allow navigation when input is empty
                    if (inputValue === '' && $pills.length > 0) {
                        const $selected = this.$container.find('.htax-pill.selected');
                        if ($selected.length === 0) {
                            $pills.last().addClass('selected');
                        } else {
                            const $prev = $selected.prev('.htax-pill');
                            if ($prev.length > 0) {
                                $selected.removeClass('selected');
                                $prev.addClass('selected');
                            }
                        }
                        e.preventDefault();
                    }
                } else if (e.which === 39) { // Right arrow
                    const $selected = this.$container.find('.htax-pill.selected');
                    if ($selected.length > 0) {
                        const $next = $selected.next('.htax-pill');
                        if ($next.length > 0) {
                            $selected.removeClass('selected');
                            $next.addClass('selected');
                        } else {
                            // At the last pill, deselect and return focus to input
                            $selected.removeClass('selected');
                            this.$input.focus();
                        }
                        e.preventDefault();
                    }
                } else if (e.which === 46) { // Delete
                    const $selected = this.$container.find('.htax-pill.selected');
                    if ($selected.length > 0) {
                        // Select previous pill (or next if no previous) after deletion
                        const $prev = $selected.prev('.htax-pill');
                        const $next = $selected.next('.htax-pill');
                        $selected.find('.htax-remove').trigger('click');

                        setTimeout(() => {
                            if ($prev.length > 0) {
                                $prev.addClass('selected');
                            } else if ($next.length > 0) {
                                $next.addClass('selected');
                            }
                        }, 0);
                        e.preventDefault();
                    }
                } else {
                    // Any other key clears selection
                    this.$container.find('.htax-pill').removeClass('selected');
                }
            });
        }
        
        /**
         * Load default terms
         */
        /**
         * Set minimum items constraint
         */
        setMinItems(value) {
            this.minItems = parseInt(value) || 0;
            this.enforceConstraints();
        }
        
        /**
         * Set maximum items constraint
         */
        setMaxItems(value) {
            this.maxItems = parseInt(value) || 0;
            this.enforceConstraints();
        }
        
        /**
         * Set visibility mode
         */
        setShowWhen(value) {
            const validValues = ['always', 'expanded', 'collapsed', 'never'];
            if (!validValues.includes(value)) return;
            
            this.showWhen = value;
            this.$wrapper.removeClass('htax-show-expanded htax-show-collapsed htax-hidden');
            
            if (value === 'never') {
                this.$wrapper.addClass('htax-hidden');
            } else if (value === 'expanded') {
                this.$wrapper.addClass('htax-show-expanded');
            } else if (value === 'collapsed') {
                this.$wrapper.addClass('htax-show-collapsed');
            }
            // 'always' needs no special class
        }
        
        /**
         * Add a pill
         */
        addPill(termId) {
            // Normalize termId to integer for consistent comparison
            termId = typeof termId === 'string' ? parseInt(termId) : termId;

            // Check constraints
            if (this.maxItems > 0 && this._selectedTerms.size >= this.maxItems) {
                return false;
            }

            // Check if already selected
            if (this._selectedTerms.has(termId)) {
                return false;
            }

            // Get term data (from cache or fetch)
            const termData = this._termData[termId] || { name: 'Term ' + termId };

            // Create pill element matching admin UI structure
            const $pill = $('<span class="htax-pill">')
                .attr('data-id', termId)
                .attr('data-taxonomy', termData.taxonomy || this.taxonomy || '');

            // Add icon or placeholder if enabled
            const iconHtml = this.getTermIconHtml(termId, termData);
            if (iconHtml) {
                $pill.append(iconHtml);
            }

            // Add term name
            $pill.append('<span class="htax-pill-text">' + this.escapeHtml(termData.name) + '</span>');

            // Always add remove button - search widgets should never have locked pills
            $pill.append(
                '<button type="button" class="htax-remove" aria-label="Remove">' +
                '<span class="screen-reader-text">Remove term:</span>' +
                '<span aria-hidden="true">&times;</span>' +
                '</button>'
            );

            // Insert before input with fade in (matching admin UI)
            $pill.hide();
            $pill.insertBefore(this.$input);
            $pill.fadeIn(200);

            this._selectedTerms.add(termId);
            this.updateValue();
            this.enforceConstraints();

            // Sort pills alphabetically (matching admin UI - called immediately, not in callback)
            this.sortPillsAlphabetically();

            return true;
        }

        /**
         * Sort pills alphabetically (matching admin UI htax-term-edit.js:343-359)
         */
        sortPillsAlphabetically() {
            // Detach all pills and filter to only those in _selectedTerms
            const self = this;
            const $allPills = this.$container.find('.htax-pill').detach();
            const pillsArray = $allPills.toArray().filter(function(pill) {
                const pillId = $(pill).data('id');
                // Ensure consistent type comparison (data() may return string or number)
                const pillIdNormalized = typeof pillId === 'string' ? parseInt(pillId) : pillId;
                return self._selectedTerms.has(pillIdNormalized);
            });

            // Sort alphabetically
            pillsArray.sort((a, b) => {
                const aName = $(a).find('.htax-pill-text').text() || $(a).text().replace('×', '').trim();
                const bName = $(b).find('.htax-pill-text').text() || $(b).text().replace('×', '').trim();
                return aName.localeCompare(bName);
            });

            // Re-insert sorted pills before search input
            this.$input.before(pillsArray);
        }

        /**
         * Remove a pill
         */
        removePill($pill) {
            let termId = $pill.data('id');
            // Normalize termId to integer for consistent comparison
            termId = typeof termId === 'string' ? parseInt(termId) : termId;

            // Remove immediately from selectedTerms and DOM
            this._selectedTerms.delete(termId);

            // Fade out and remove
            $pill.fadeOut(200, function() {
                $(this).remove();
            });

            this.updateValue();
            this.enforceConstraints();
        }
        
        /**
         * Get term icon HTML based on settings
         */
        getTermIconHtml(termId, termData) {
            if (this.showIcons === 'never') {
                return '';
            }
            
            // Check if term has an icon
            if (termData.icon_url) {
                return `<img src="${termData.icon_url}" class="htax-pill-icon" alt="" data-taxonomy="${termData.taxonomy || this.taxonomy || ''}" />`;
            }

            // Show placeholder if "always"
            if (this.showIcons === 'always' && termData.placeholder_color) {
                return `<span class="htax-pill-placeholder" style="background-color: ${termData.placeholder_color}" data-taxonomy="${termData.taxonomy || this.taxonomy || ''}"></span>`;
            }

            // "when_available" - no icon, no placeholder
            return '';
        }
        
        /**
         * Update hidden field value
         */
        updateValue() {
            this.$hidden.val(Array.from(this._selectedTerms).join(','));
            if (this.form) {
                this.form.$form.trigger('htax:field:changed', [this.name, this._selectedTerms]);
            }
            // Clear error state as soon as the constraint is satisfied
            if (this._hasValidationError) {
                const count = this._selectedTerms.size;
                const minOk = this.minItems <= 0 || count >= this.minItems;
                const maxOk = this.maxItems <= 0 || count <= this.maxItems;
                if (minOk && maxOk) {
                    this._clearValidationError();
                }
            }
        }
        
        /**
         * Get field value
         */
        getValue() {
            return Array.from(this._selectedTerms);
        }
        
        /**
         * Set field value
         */
        setValue(value) {
            // Clear current pills
            this.$container.find('.htax-pill').remove();
            this._selectedTerms.clear();
            
            if (value) {
                const termIds = String(value).split(',').map(id => parseInt(id)).filter(id => !isNaN(id));
                termIds.forEach(id => {
                    // Would need to fetch term names
                    this.addPill(id);
                });
            }
            
            this.updateValue();
        }
        
        /**
         * Validate field
         */
        validate() {
            const count = this._selectedTerms.size;
            let message = '';

            if (this.minItems > 0 && count < this.minItems) {
                message = this.$wrapper.data('min-items-message') ||
                    (this.minItems === 1 ? 'Please select at least one item.' :
                        `Please select at least ${this.minItems} items.`);
                this.$wrapper.addClass('htax-error');
                this._hasValidationError = true;
                this._showValidationMessage(message);
                return false;
            }

            if (this.maxItems > 0 && count > this.maxItems) {
                message = this.$wrapper.data('max-items-message') ||
                    `Please select no more than ${this.maxItems} items.`;
                this.$wrapper.addClass('htax-error');
                this._hasValidationError = true;
                this._showValidationMessage(message);
                return false;
            }

            this._clearValidationError();
            return true;
        }

        _showValidationMessage(text) {
            if (this.$validationMessage.length) {
                this.$validationMessage.text(text).show();
            }
        }

        _clearValidationError() {
            this._hasValidationError = false;
            this.$wrapper.removeClass('htax-error');
            if (this.$validationMessage.length) {
                this.$validationMessage.hide().text('');
            }
        }
        
        /**
         * Enforce min/max constraints
         */
        enforceConstraints() {
            const count = this._selectedTerms.size;

            // No locked pills in search widgets - users should always be able to remove terms
            // (minItems is only used for validation, not to prevent removal)

            // Disable input if at maximum
            if (this.maxItems > 0 && count >= this.maxItems) {
                this.$input.prop('disabled', true)
                          .attr('placeholder', 'Maximum items reached');
            } else {
                this.$input.prop('disabled', false)
                          .attr('placeholder', this._originalPlaceholder || 'Type to search...');
            }
        }
        
        /**
         * Clear current pill selections
         */
        clear() {
            // Remove all pills
            this.$container.find('.htax-pill').each((i, pill) => {
                this.removePill($(pill));
            });
            this._selectedTerms.clear();
            this.updateValue();
        }

        /**
         * Clear all available options from pills input
         */
        clearOptions() {
            this._availableTerms.clear();
            this.validTerms = [];
            this.updateAutocomplete();
        }

        /**
         * Reset options to initial defaults
         */
        resetOptions() {
            // Reload from initial configuration
            this._availableTerms.clear();
            this.loadInitialOptionConfiguration();
            this.loadValidTerms();
        }

        /**
         * Reset both selection and options to defaults
         */
        reset() {
            this.clear();          // Clear current selection
            this.resetOptions();   // Reset options to defaults

            // Reapply controller actions targeting this field
            this.reapplyControllerActions();
        }

        /**
         * Reapply actions from all controllers that target this field
         */
        reapplyControllerActions() {
            const fieldName = this.$wrapper.data('field-name');
            if (!fieldName || !this.form) return;

            if (this.form) {
                const actions = this.form.getControllerActionsForField(fieldName);
                if (actions.length > 0) {
                    this.form.executeActionsByPriority(actions);
                }
            }
        }
    }

    /**
     * ChecklistField class
     */
    class ChecklistField extends FieldBase {
        constructor($wrapper, form) {
            super($wrapper, form);
            
            this.$container = $wrapper.find('.htax-checklist-container');
            this.minItems = parseInt(this.$container.data('min-items')) || 0;
            this.maxItems = parseInt(this.$container.data('max-items')) || 0;
            this.taxonomy = $wrapper.data('taxonomy') || 'post_tag';
            this.match = $wrapper.data('match') || 'any';
            this.filterby = $wrapper.data('filterby') || '';

            this.init();
        }

        init() {
            this._savedStates = {};
            this._allOptionTermIds = new Set();
            this.$container.find('.htax-checklist-item').each((i, el) => {
                this._allOptionTermIds.add(parseInt($(el).data('term-id')));
            });
            this.bindEvents();
            this.initHierarchy();
            this.processInitialSelection();
            this.enforceConstraints();
            this.applyMaxRows();
        }

        processInitialSelection() {
            const addDescendants = this.$wrapper.data('add-descendants');
            if (addDescendants) {
                const groupIds = String(addDescendants).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                this.addDescendants(groupIds);
            }

            const removeDescendants = this.$wrapper.data('remove-descendants');
            if (removeDescendants) {
                const groupIds = String(removeDescendants).split(',').map(id => parseInt(id.trim())).filter(id => !isNaN(id));
                this.removeDescendants(groupIds);
            }
        }

        /**
         * Apply max-rows height constraint to the checklist container.
         * Uses scrollHeight / itemCount for exact per-item height (accounts for margin
         * collapse and any theme overrides without relying on CSS value parsing).
         * Skips silently when the field is hidden (scrollHeight would be 0); the field's
         * setShowWhen re-calls this method the first time the field becomes visible.
         */
        applyMaxRows() {
            const maxRows = this.$container.data('max-rows');
            if (!maxRows || maxRows <= 0) return;
            if (!this.$wrapper.is(':visible')) return;
            const $items = this.$container.find('.htax-checklist-item');
            if (!$items.length) {
                this.$container.css('max-height', (maxRows * 32 + 20) + 'px');
                return;
            }
            // Temporarily lift any max-height so scrollHeight reflects full content.
            this.$container.css('max-height', 'none');
            const totalH   = this.$container[0].scrollHeight;
            const perItemH = totalH / $items.length;
            this.$container.css('max-height', Math.round(maxRows * perItemH) + 'px');
        }

        bindEvents() {
            this.$container.on('change', 'input[type="checkbox"]', (e) => {
                if (this.match === 'any') {
                    const $item = $(e.target).closest('.htax-checklist-item');
                    if ($(e.target).prop('checked')) {
                        this._applyImplied($item.data('term-id'));
                    } else {
                        this._restoreChildren($item.data('term-id'));
                    }
                }
                this.enforceConstraints();
                this.updateValue();
            });
        }

        /**
         * On init, apply implied state for any pre-checked items (match=any only).
         */
        initHierarchy() {
            if (this.match !== 'any') return;
            // Iterate top-to-bottom so ancestor saves are recorded before descendants are processed.
            this.$container.find('.htax-checklist-item').each((i, el) => {
                const $cb = $(el).find('> label > input[type="checkbox"]');
                if ($cb.prop('checked') && !$(el).hasClass('htax-implied-by-parent')) {
                    this._applyImplied($(el).data('term-id'));
                }
            });
        }

        /**
         * Mark all descendants of parentId as implied: uncheck, disable, fade.
         * Save each descendant's checked state before overriding so it can be
         * restored when the parent is unchecked.  The first save wins — if an
         * ancestor already saved a descendant's state, don't overwrite it.
         */
        _applyImplied(parentId) {
            this.$container
                .find('.htax-checklist-item[data-parent-id="' + parentId + '"]')
                .each((i, el) => {
                    const $item = $(el);
                    const childId = $item.data('term-id');
                    const $cb = $item.find('> label > input[type="checkbox"]');

                    if (!(childId in this._savedStates)) {
                        this._savedStates[childId] = $cb.prop('checked');
                    }
                    $cb.prop({ checked: false, disabled: true });
                    $item.addClass('htax-implied-by-parent');

                    this._applyImplied(childId); // recurse to grandchildren
                });
        }

        /**
         * Restore direct children of parentId from saved state, then re-evaluate
         * each child: if it was checked, re-apply implied to its own children;
         * if it was not checked, recurse to restore its children too.
         */
        _restoreChildren(parentId) {
            this.$container
                .find('.htax-checklist-item[data-parent-id="' + parentId + '"]')
                .each((i, el) => {
                    const $item = $(el);
                    const childId = $item.data('term-id');
                    const $cb = $item.find('> label > input[type="checkbox"]');
                    const wasChecked = this._savedStates[childId] || false;

                    delete this._savedStates[childId];
                    $item.removeClass('htax-implied-by-parent');
                    $cb.prop({ checked: wasChecked, disabled: false });

                    if (wasChecked) {
                        // Child is restored to checked — re-apply implied to its children.
                        this._applyImplied(childId);
                    } else {
                        // Child is unchecked — recurse to restore its children from saved state.
                        this._restoreChildren(childId);
                    }
                });
        }

        /**
         * Enforce min/max constraints, skipping implied (disabled-by-parent) items.
         */
        enforceConstraints() {
            // Only count and control non-implied checkboxes.
            const $active = this.$container
                .find('input[type="checkbox"]')
                .filter((i, el) => !$(el).closest('.htax-checklist-item').hasClass('htax-implied-by-parent'));
            const checkedCount = $active.filter(':checked').length;

            if (this.maxItems > 0 && checkedCount >= this.maxItems) {
                $active.not(':checked').prop('disabled', true);
            } else {
                $active.prop('disabled', false);
            }
        }
        
        /**
         * Update value
         */
        updateValue() {
            const values = [];
            this.$container.find('input:checked').each((i, el) => {
                values.push($(el).val());
            });

            if (this.form) {
                this.form.$form.trigger('htax:field:changed', [this.name, values]);
            }
        }
        
        /**
         * Get field value
         */
        getValue() {
            const values = [];
            this.$container.find('input:checked').each((i, el) => {
                values.push(parseInt($(el).val()));
            });
            return values;
        }
        
        validate() {
            const checkedCount = this.$container.find('input:checked').length;
            
            if (this.minItems > 0 && checkedCount < this.minItems) {
                this.$wrapper.addClass('htax-error');
                return false;
            }
            
            this.$wrapper.removeClass('htax-error');
            return true;
        }

        /**
         * Clear all checked items in checklist
         */
        clear() {
            this.$container.find('input[type="checkbox"]:checked').prop('checked', false);
            this.updateValue();
        }

        /**
         * Clear all options from checklist (not applicable for checklist)
         */
        clearOptions() {
            // Checklist options are fixed in HTML — no-op
        }

        resetOptions() {
            // Checklist options are fixed in HTML — no-op
        }

        /**
         * Reset selection to default
         */
        reset() {
            this.clear();  // Just clear selections, options can't be reset

            // Reapply controller actions targeting this field
            this.reapplyControllerActions();
        }

        /**
         * Reapply actions from all controllers that target this field
         */
        reapplyControllerActions() {
            const fieldName = this.$wrapper.data('field-name');
            if (!fieldName || !this.form) return;

            if (this.form) {
                const actions = this.form.getControllerActionsForField(fieldName);
                if (actions.length > 0) {
                    this.form.executeActionsByPriority(actions);
                }
            }
        }

        _isKnownTerm(id) {
            return this._allOptionTermIds.has(id);
        }

        addTerms(termIds) {
            let skipped = 0;
            let changed = false;
            termIds.forEach(id => {
                if (!this._isKnownTerm(id)) { skipped++; return; }
                this.$container.find(`.htax-checklist-item[data-term-id="${id}"] > label > input`).prop('checked', true);
                changed = true;
            });
            if (changed) {
                this._resetImpliedState();
                this.initHierarchy();
                this.enforceConstraints();
                this.updateValue();
            }
            return skipped;
        }

        removeTerms(termIds) {
            let changed = false;
            termIds.forEach(id => {
                if (!this._isKnownTerm(id)) return;
                this.$container.find(`.htax-checklist-item[data-term-id="${id}"] > label > input`).prop('checked', false);
                changed = true;
            });
            if (changed) {
                this._resetImpliedState();
                this.initHierarchy();
                this.enforceConstraints();
                this.updateValue();
            }
            return 0;
        }

        /**
         * Check the member terms of these groups (term mode: the groups themselves), resolved
         * from the form's group map. Checklist options are fixed at render time, so only
         * selection changes — not pool actions — apply to a checklist.
         */
        addGroups(groupIds) {
            const { ids, unknown } = this._groupMemberIds(groupIds);
            return unknown + (ids.length ? this.addTerms(ids) : 0);
        }

        removeGroups(groupIds) {
            const { ids } = this._groupMemberIds(groupIds);
            return ids.length ? this.removeTerms(ids) : 0;
        }

        addDescendants(groupIds) {
            if (!groupIds || groupIds.length === 0) return 0;
            groupIds.forEach(gid => {
                this._getDescendantItemIds(gid).forEach(id => {
                    this.$container.find(`.htax-checklist-item[data-term-id="${id}"] > label > input`).prop('checked', true);
                });
            });
            this._resetImpliedState();
            this.initHierarchy();
            this.enforceConstraints();
            this.updateValue();
            return 0;
        }

        removeDescendants(groupIds) {
            if (!groupIds || groupIds.length === 0) return 0;
            groupIds.forEach(gid => {
                this._getDescendantItemIds(gid).forEach(id => {
                    this.$container.find(`.htax-checklist-item[data-term-id="${id}"] > label > input`).prop('checked', false);
                });
            });
            this._resetImpliedState();
            this.initHierarchy();
            this.enforceConstraints();
            this.updateValue();
            return 0;
        }

        _getDescendantItemIds(parentId) {
            const ids = [];
            this.$container.find(`.htax-checklist-item[data-parent-id="${parentId}"]`).each((i, el) => {
                const childId = $(el).data('term-id');
                ids.push(childId);
                ids.push(...this._getDescendantItemIds(childId));
            });
            return ids;
        }

        _resetImpliedState() {
            this._savedStates = {};
            this.$container.find('.htax-implied-by-parent').each((i, el) => {
                const $item = $(el);
                $item.removeClass('htax-implied-by-parent');
                $item.find('> label > input[type="checkbox"]').prop('disabled', false);
            });
        }
    }

    /**
     * HiddenField — no UI; submits a computed term ID list.
     *
     * Submitted value = (constant ∪ active-pool ∪ controller-added) − (permanent-exclusions ∪ controller-removed)
     *
     * Constant additions / permanent exclusions come from shortcode add_terms / remove_terms
     * (PHP expands add_groups, add_descendants, remove_groups, remove_descendants to IDs).
     * Pool is defined by data-groups-map; starts fully active. Controller
     * include_group_options / exclude_group_options narrow the active group set.
     * Controller add_terms / remove_terms / add_groups / remove_groups /
     * add_descendants / remove_descendants dynamically adjust the value.
     */
    class HiddenField extends FieldBase {
        constructor($wrapper, form) {
            super($wrapper, form);

            this.$input            = $wrapper;
            this.taxonomy          = $wrapper.data('taxonomy');
            this.match             = $wrapper.data('match') || 'any';
            this.filterby          = $wrapper.data('filterby') || '';
            this.minItems          = parseInt($wrapper.data('min-items')) || 0;
            this.maxItems          = parseInt($wrapper.data('max-items')) || 0;
            this.$validationMessage = $wrapper.next('.htax-hidden-field-message');

            // Pool: group-based universe (from data-groups-map) plus individual overrides.
            // Mirrors the pool model of PillsField / ChecklistField — just not displayed.
            this._allTermsByGroup = new Map();  // groupId → Set<termId>; for group-based controller actions
            this._includedTermIds = new Set();  // individual terms: from include_term_options shortcode + controller includeTermOptions/excludeTermOptions
            this._excludedTermIds = new Set();  // individual terms removed via excludeTermOptions controller
            this._activeGroups    = new Set();  // empty = all declared groups active

            const groupsMap = $wrapper.data('groups-map') || {};
            for (const [gidStr, termIds] of Object.entries(groupsMap)) {
                this._allTermsByGroup.set(parseInt(gidStr), new Set(termIds.map(Number)));
            }

            // include_term_options shortcode param: individual terms not belonging to any group.
            const includeTermOpts = $wrapper.data('include-term-options');
            if (includeTermOpts) {
                this.parseIdList(String(includeTermOpts)).forEach(id => this._includedTermIds.add(id));
            }

            // Selection: invisible checked state, same model as pills/checklist.
            // PHP resolves add_groups / add_descendants to flat term IDs before rendering.
            this._selectedTerms = new Set();
            const addIds    = this.parseIdList(String($wrapper.data('add-terms')    || ''));
            const removeIds = new Set(this.parseIdList(String($wrapper.data('remove-terms') || '')));
            addIds.forEach(id => { if (!removeIds.has(id)) this._selectedTerms.add(id); });

            this._initialSelectedTerms = new Set(this._selectedTerms); // snapshot for reset

            this._computeValue();
        }

        // ---- Pool management (same semantics as PillsField / ChecklistField) ----

        includeTermOptions(termIds) {
            termIds.forEach(id => { this._includedTermIds.add(id); this._excludedTermIds.delete(id); });
            this._computeValue();
        }

        excludeTermOptions(termIds) {
            termIds.forEach(id => { this._excludedTermIds.add(id); this._includedTermIds.delete(id); });
            this._computeValue();
        }

        includeGroupOptions(groupIds) {
            groupIds.forEach(id => this._activeGroups.add(id));
            this._computeValue();
        }

        excludeGroupOptions(groupIds) {
            groupIds.forEach(id => this._activeGroups.delete(id));
            this._computeValue();
        }

        // ---- Pool membership check ----

        _isKnownTerm(id) {
            if (this._includedTermIds.has(id)) return true;
            for (const termSet of this._allTermsByGroup.values()) {
                if (termSet.has(id)) return true;
            }
            return false;
        }

        // ---- Selection management (same semantics as PillsField / ChecklistField) ----

        addTerms(termIds) {
            let skipped = 0;
            termIds.forEach(id => {
                if (!this._isKnownTerm(id)) { skipped++; return; }
                this._selectedTerms.add(id);
            });
            this._computeValue();
            return skipped;
        }

        removeTerms(termIds) {
            termIds.forEach(id => this._selectedTerms.delete(id));
            this._computeValue();
            return 0;
        }

        addGroups(groupIds) {
            let skipped = 0;
            groupIds.forEach(gid => {
                const terms = this._allTermsByGroup.get(gid);
                if (terms) {
                    terms.forEach(id => this._selectedTerms.add(id));
                } else {
                    skipped++;
                }
            });
            this._computeValue();
            return skipped;
        }

        removeGroups(groupIds) {
            groupIds.forEach(gid => {
                const terms = this._allTermsByGroup.get(gid);
                if (terms) terms.forEach(id => this._selectedTerms.delete(id));
            });
            this._computeValue();
            return 0;
        }

        addDescendants(groupIds)    { return this.addGroups(groupIds); }
        removeDescendants(groupIds) { return this.removeGroups(groupIds); }

        // ---- Value computation ----

        _computeActivePool() {
            const pool = new Set();
            if (this._allTermsByGroup.size === 0 && this._includedTermIds.size === 0) {
                // No pool declared — use the selection itself as the universe so the
                // intersection in _computeValue() is a no-op (all selected terms pass).
                this._selectedTerms.forEach(id => pool.add(id));
            } else if (this._activeGroups.size === 0) {
                for (const termSet of this._allTermsByGroup.values()) {
                    termSet.forEach(id => pool.add(id));
                }
            } else {
                for (const gid of this._activeGroups) {
                    const terms = this._allTermsByGroup.get(gid);
                    if (terms) terms.forEach(id => pool.add(id));
                }
            }
            this._includedTermIds.forEach(id => pool.add(id));
            this._excludedTermIds.forEach(id => pool.delete(id));
            return pool;
        }

        _computeValue() {
            const activePool = this._computeActivePool();
            this.$input.val([...this._selectedTerms].filter(id => activePool.has(id)).join(','));
        }

        getValue() {
            const activePool = this._computeActivePool();
            return [...this._selectedTerms].filter(id => activePool.has(id));
        }

        validate() {
            if (this.minItems <= 0 && this.maxItems <= 0) return true;
            const activePool = this._computeActivePool();
            const count = [...this._selectedTerms].filter(id => activePool.has(id)).length;
            if (this.minItems > 0 && count < this.minItems) {
                if (this.$validationMessage.length) {
                    const msg = this.$validationMessage.data('min-items-message') || '';
                    if (msg) this.$validationMessage.text(msg).show();
                }
                return false;
            }
            if (this.maxItems > 0 && count > this.maxItems) {
                if (this.$validationMessage.length) {
                    const msg = this.$validationMessage.data('max-items-message') || '';
                    if (msg) this.$validationMessage.text(msg).show();
                }
                return false;
            }
            if (this.$validationMessage.length) this.$validationMessage.hide();
            return true;
        }

        clearOptions() {}
        resetOptions() {}

        reset() {
            this._includedTermIds.clear();
            this._excludedTermIds.clear();
            this._activeGroups.clear();
            this._selectedTerms = new Set(this._initialSelectedTerms);
            this._computeValue();
            this.reapplyControllerActions();
        }

        reapplyControllerActions() {
            const fieldName = this.$input.data('field-name');
            if (!fieldName || !this.form) return;
            const actions = this.form.getControllerActionsForField(fieldName);
            if (actions.length > 0) {
                this.form.executeActionsByPriority(actions);
            }
        }
    }

    /**
     * Controller class with automatic action reversal
     */
    class Controller {
        static actionReversals = {
            'include_term_options': 'exclude_term_options',
            'exclude_term_options': 'include_term_options',
            'include_group_options': 'exclude_group_options',
            'exclude_group_options': 'include_group_options',
            'add_terms': 'remove_terms',
            'remove_terms': 'add_terms',
            'add_groups': 'remove_groups',
            'remove_groups': 'add_groups',
            'add_descendants': 'remove_descendants',
            'remove_descendants': 'add_descendants',
            'enable': 'disable',
            'disable': 'enable'
            // Note: set_min_items, set_max_items, set_show_when don't have reversals
        };
        
        constructor($wrapper, form) {
            this.$wrapper = $wrapper;
            this.form = form;
            this.name = $wrapper.data('controller-name');
            // toggle is behaviorally identical to checkbox
            this.type = $wrapper.data('controller-type') === 'toggle' ? 'checkbox' : $wrapper.data('controller-type');
            this.minItems = parseInt($wrapper.data('min-items')) || 0;
            this.maxItems = parseInt($wrapper.data('max-items')) || 0;
            this.minItemsMessage = $wrapper.data('min-items-message') || '';
            this.maxItemsMessage = $wrapper.data('max-items-message') || '';
            this.$validationMessage = $wrapper.find('.htax-validation-message');

            // Derive initial showWhen and enabled from PHP-rendered classes
            if ($wrapper.hasClass('htax-show-expanded')) {
                this.showWhen = 'expanded';
            } else if ($wrapper.hasClass('htax-show-collapsed')) {
                this.showWhen = 'collapsed';
            } else if ($wrapper.hasClass('htax-hidden') || $wrapper.hasClass('htax-show-never')) {
                this.showWhen = 'never';
            } else {
                this.showWhen = 'always';
            }
            this.enabled = !$wrapper.hasClass('htax-disabled');

            // Store initial state for reset functionality
            this.saveInitialState();

            this.init();
        }

        init() {
            this.bindEvents();

            if (this.type === 'checkbox') {
                this.enforceConstraints();
            }
        }

        /**
         * Save the initial state of the controller for later reset
         */
        saveInitialState() {
            this.initialState = this.getCurrentState();
        }

        /**
         * Get the current state of the controller
         */
        getCurrentState() {
            const state = {};

            switch (this.type) {
                case 'radio':
                    const $checkedRadio = this.$wrapper.find('input[type="radio"]:checked');
                    state.selectedValue = $checkedRadio.length ? $checkedRadio.val() : null;
                    break;

                case 'checkbox':
                    state.checkedValues = [];
                    this.$wrapper.find('input[type="checkbox"]:checked').each((i, el) => {
                        state.checkedValues.push($(el).val());
                    });
                    break;

                case 'dropdown':
                    const $selected = this.$wrapper.find('option:selected');
                    state.selectedValue = $selected.length ? $selected.val() : null;
                    break;
            }

            return state;
        }

        /**
         * Set controller visibility (mirrors FieldBase.setShowWhen).
         * Clears htax-show-never so a previously PHP-hidden controller can be shown.
         */
        setShowWhen(value) {
            const validValues = ['always', 'expanded', 'collapsed', 'never'];
            if (!validValues.includes(value)) return;
            this.showWhen = value;
            this.$wrapper.removeClass('htax-show-expanded htax-show-collapsed htax-hidden htax-show-never');
            if (value === 'never') {
                this.$wrapper.addClass('htax-hidden');
            } else if (value === 'expanded') {
                this.$wrapper.addClass('htax-show-expanded');
            } else if (value === 'collapsed') {
                this.$wrapper.addClass('htax-show-collapsed');
            }
        }

        /**
         * Reset controller to its initial state
         */
        reset() {
            if (!this.initialState) return;

            // First, reverse all current actions
            this.reverseCurrentState();

            // Then, restore initial state
            this.restoreState(this.initialState);

            // Apply actions from the restored state
            this.applyStateActions(this.initialState);
        }

        /**
         * Reverse actions from current state
         */
        reverseCurrentState() {
            const allActions = [];

            switch (this.type) {
                case 'radio':
                    const $currentRadio = this.$wrapper.find('input[type="radio"]:checked');
                    if ($currentRadio.length) {
                        const actions = this.getActionsFromElement($currentRadio);
                        allActions.push(...this.getReverseActions(actions));
                    }
                    break;

                case 'checkbox':
                    this.$wrapper.find('input[type="checkbox"]:checked').each((i, el) => {
                        const actions = this.getActionsFromElement($(el));
                        allActions.push(...this.getReverseActions(actions));
                    });
                    break;

                case 'dropdown':
                    const $currentOption = this.$wrapper.find('option:selected');
                    if ($currentOption.length && $currentOption.val()) {
                        const actions = this.getActionsFromElement($currentOption);
                        allActions.push(...this.getReverseActions(actions));
                    }
                    break;
            }

            if (allActions.length && this.form) {
                this.form.executeActionsByPriority(allActions);
            }
        }

        /**
         * Restore controller to a given state
         */
        restoreState(state) {
            switch (this.type) {
                case 'radio':
                    // Uncheck all first
                    this.$wrapper.find('input[type="radio"]').prop('checked', false).removeAttr('data-was-checked');
                    // Check the initial one
                    if (state.selectedValue) {
                        const $radio = this.$wrapper.find(`input[type="radio"][value="${state.selectedValue}"]`);
                        $radio.prop('checked', true).attr('data-was-checked', 'true');
                    }
                    break;

                case 'checkbox':
                    // Uncheck all first
                    this.$wrapper.find('input[type="checkbox"]').prop('checked', false);
                    // Check the initial ones
                    if (state.checkedValues && state.checkedValues.length) {
                        state.checkedValues.forEach(value => {
                            this.$wrapper.find(`input[type="checkbox"][value="${value}"]`).prop('checked', true);
                        });
                    }
                    this.enforceConstraints();
                    break;

                case 'dropdown':
                    // Reset to initial selection
                    const $select = this.$wrapper.find('select');
                    $select.find('option').removeAttr('data-was-selected');
                    if (state.selectedValue) {
                        $select.val(state.selectedValue);
                        $select.find('option:selected').attr('data-was-selected', 'true');
                    } else {
                        $select.val('');
                    }
                    break;
            }
        }

        /**
         * Apply actions from a given state
         */
        applyStateActions(state) {
            const allActions = [];

            switch (this.type) {
                case 'radio':
                    if (state.selectedValue) {
                        const $radio = this.$wrapper.find(`input[type="radio"][value="${state.selectedValue}"]`);
                        if ($radio.length) {
                            const actions = this.getActionsFromElement($radio);
                            allActions.push(...actions);
                        }
                    }
                    break;

                case 'checkbox':
                    if (state.checkedValues && state.checkedValues.length) {
                        state.checkedValues.forEach(value => {
                            const $checkbox = this.$wrapper.find(`input[type="checkbox"][value="${value}"]`);
                            if ($checkbox.length) {
                                const actions = this.getActionsFromElement($checkbox);
                                allActions.push(...actions);
                            }
                        });
                    }
                    break;

                case 'dropdown':
                    if (state.selectedValue) {
                        const $option = this.$wrapper.find(`option[value="${state.selectedValue}"]`);
                        if ($option.length) {
                            const actions = this.getActionsFromElement($option);
                            allActions.push(...actions);
                        }
                    }
                    break;
            }

            if (allActions.length && this.form) {
                this.form.executeActionsByPriority(allActions);
            }
        }

        bindEvents() {
            switch (this.type) {
                case 'radio':
                    this.$wrapper.on('change', 'input[type="radio"]', (e) => {
                        const $newOption = $(e.target);
                        const $oldOption = this.$wrapper.find('input[type="radio"][data-was-checked]');
                        
                        // Process old option's reversal first
                        if ($oldOption.length && $oldOption[0] !== $newOption[0]) {
                            const oldActions = this.getActionsFromElement($oldOption);
                            const reversedActions = this.getReverseActions(oldActions);
                            this.form.executeActionsByPriority(reversedActions);
                            $oldOption.removeAttr('data-was-checked');
                        }
                        
                        // Then process new option's actions
                        const newActions = this.getActionsFromElement($newOption);
                        this.form.executeActionsByPriority(newActions);
                        $newOption.attr('data-was-checked', 'true');
                    });
                    break;
                    
                case 'checkbox':
                    this.$wrapper.on('change', 'input[type="checkbox"]', (e) => {
                        const $checkbox = $(e.target);
                        const actions = this.getActionsFromElement($checkbox);
                        
                        if ($checkbox.is(':checked')) {
                            // Apply actions in priority order
                            this.form.executeActionsByPriority(actions)
                        } else {
                            // Reverse actions in priority order
                            const reversedActions = this.getReverseActions(actions);
                            this.form.executeActionsByPriority(reversedActions);
                        }
                        
                        this.enforceConstraints();
                    });
                    break;
                    
                case 'dropdown':
                    this.$wrapper.on('change', 'select', (e) => {
                        const $select = $(e.target);
                        const $newOption = $select.find('option:selected');
                        const $oldOption = $select.find('option[data-was-selected]');
                        
                        // Process old option's reversal first
                        if ($oldOption.length && $oldOption[0] !== $newOption[0]) {
                            const oldActions = this.getActionsFromElement($oldOption);
                            const reversedActions = this.getReverseActions(oldActions);
                            this.form.executeActionsByPriority(reversedActions);
                            $oldOption.removeAttr('data-was-selected');
                        }
                        
                        // Then process new option's actions
                        const newActions = this.getActionsFromElement($newOption);
                        this.form.executeActionsByPriority(newActions);
                        $newOption.attr('data-was-selected', 'true');
                    });
                    break;
            }
        }
        
        /**
         * Get reverse actions maintaining priority order
         */
        getReverseActions(actions) {
            const reversed = [];
            actions.forEach(action => {
                if (action.type === 'set_show_when') {
                    // Restore to the field's PHP-rendered initial show_when value
                    const field = this.form && this.form.fields && this.form.fields[action.target];
                    if (field) {
                        const initialShowWhen = field.$wrapper.data('show-when') || 'always';
                        reversed.push({ ...action, value: initialShowWhen });
                    }
                } else {
                    const reverseType = Controller.actionReversals[action.type];
                    if (reverseType) {
                        reversed.push({ ...action, type: reverseType, isReverse: true });
                    }
                }
            });
            return reversed;
        }

        /**
         * Show or clear a not_found_message on this controller's validation area.
         * Called after add_* selection actions to report pool misses.
         * Reverse actions always clear; forward actions show only when skipped > 0.
         */
        _handleNotFoundMessage(action, skipped) {
            if (!action.notFoundMessage || !this.$validationMessage.length) return;
            if (action.isReverse || skipped === 0) {
                this.$validationMessage.hide();
            } else {
                this.$validationMessage.text(action.notFoundMessage).show();
            }
        }

        /**
         * Enforce min/max constraints for checkboxes
         */
        enforceConstraints() {
            if (this.type !== 'checkbox') return;

            const $checkboxes = this.$wrapper.find('input[type="checkbox"]');
            const checkedCount = $checkboxes.filter(':checked').length;

            // Min constraint
            if (this.minItems > 0 && checkedCount <= this.minItems) {
                $checkboxes.filter(':checked').each(function() {
                    const $checkbox = $(this);
                    const $item = $checkbox.closest('.htax-option-item');
                    if (!$item.hasClass('htax-locked-item')) {
                        $item.addClass('htax-locked-item').css('pointer-events', 'none');
                        $checkbox.prop('disabled', true).addClass('htax-locked');
                        $item.css('pointer-events', '');
                    }
                });
                if (this.minItemsMessage && this.$validationMessage.length) {
                    this.$validationMessage.text(this.minItemsMessage).show();
                }
            } else {
                $checkboxes.filter('.htax-locked').each(function() {
                    $(this).prop('disabled', false).removeClass('htax-locked');
                    $(this).closest('.htax-option-item').removeClass('htax-locked-item');
                });
                if (this.$validationMessage.length) {
                    this.$validationMessage.hide().text('');
                }
            }

            // Max constraint
            if (this.maxItems > 0 && checkedCount >= this.maxItems) {
                $checkboxes.not(':checked').prop('disabled', true);
                if (this.maxItemsMessage && this.$validationMessage.length) {
                    this.$validationMessage.text(this.maxItemsMessage).show();
                }
            } else if (this.maxItems > 0) {
                $checkboxes.not('.htax-locked').not(':checked').prop('disabled', false);
            }
        }
        
        
        /**
         * Get actions from element
         */
        getActionsFromElement($element) {
            const actionsJson = $element.data('actions');
            if (!actionsJson) return [];
            
            const actions = typeof actionsJson === 'string' ? JSON.parse(actionsJson) : actionsJson;
            
            if (Array.isArray(actions)) {
                return actions;
            } else if (actions.actions) {
                return actions.actions;
            }
            return [];
        }
        
        /**
         * Execute actions sorted by priority (but only within a single controller's scope)
         */
        executeActionsByPriority(actions) {
            if (!actions || actions.length === 0) return;
            
            const actionPriority = {
                'add_groups': 1,
                'remove_groups': 2,
                'add_descendants': 1,
                'remove_descendants': 2,
                'add_terms': 3,
                'remove_terms': 4,
                'show': 5,
                'hide': 6,
                'enable': 7,
                'disable': 8
            };
            
            actions.sort((a, b) => {
                const priorityA = actionPriority[a.type] || 99;
                const priorityB = actionPriority[b.type] || 99;
                return priorityA - priorityB;
            });
            
            // Execute actions in order
            actions.forEach(action => {
                this.executeAction(action);
            });
        }
                
                
        /**
         * Execute single action
         */
        executeAction(action, isReverse = false) {
            // Handle resetAll action (no specific target)
            if (action.type === 'resetAll') {
                this.resetAll();
                return;
            }

            // Check if targeting a group
            if (action.target_type === 'group') {
                this.handleGroupAction(action);
                return;
            }

            // Check if targeting a controller
            if (action.target_type === 'controller') {
                const controller = this.form.controllers[action.target];
                if (controller) {
                    this.handleControllerAction(action, controller);
                }
                return;
            }

            const field = this.form.fields[action.target];
            if (!field) {
                console.warn(`Target field not found: ${action.target}`);
                return;
            }
            
            switch (action.type) {
                // Option pool actions
                case 'include_term_options':
                    field.includeTermOptions(action.terms || []);
                    break;
                case 'exclude_term_options':
                    field.excludeTermOptions(action.terms || []);
                    break;
                case 'include_group_options':
                    field.includeGroupOptions(action.groups || []);
                    break;
                case 'exclude_group_options':
                    field.excludeGroupOptions(action.groups || []);
                    break;
                    
                // Selection actions
                case 'add_terms': {
                    const skipped = field.addTerms(action.terms || []);
                    this._handleNotFoundMessage(action, skipped);
                    break;
                }
                case 'remove_terms':
                    field.removeTerms(action.terms || []);
                    this._handleNotFoundMessage(action, 0);
                    break;
                case 'add_groups': {
                    const skipped = field.addGroups(action.groups || []);
                    this._handleNotFoundMessage(action, skipped);
                    break;
                }
                case 'remove_groups':
                    field.removeGroups(action.groups || []);
                    this._handleNotFoundMessage(action, 0);
                    break;
                case 'add_descendants': {
                    const skipped = field.addDescendants(action.groups || []);
                    this._handleNotFoundMessage(action, skipped);
                    break;
                }
                case 'remove_descendants':
                    field.removeDescendants(action.groups || []);
                    this._handleNotFoundMessage(action, 0);
                    break;
                    
                // Constraint actions
                case 'set_min_items':
                    field.setMinItems(action.value);
                    break;
                case 'set_max_items':
                    field.setMaxItems(action.value);
                    break;
                    
                // Visibility actions
                case 'set_show_when':
                    field.setShowWhen(action.value);
                    break;
                case 'enable':
                    field.enable();
                    break;
                case 'disable':
                    field.disable();
                    break;

                // Clear/Reset actions
                case 'clear':
                    if (typeof field.clear === 'function') {
                        field.clear();
                    }
                    break;
                case 'clearOptions':
                    if (typeof field.clearOptions === 'function') {
                        field.clearOptions();
                    }
                    break;
                case 'resetOptions':
                    if (typeof field.resetOptions === 'function') {
                        field.resetOptions();
                    }
                    break;
                case 'reset':
                    if (typeof field.reset === 'function') {
                        field.reset();
                    }
                    break;
            }
        }

        /**
         * Handle controller-specific actions
         */
        handleControllerAction(action, controller) {
        switch (action.type) {
            case 'set_show_when':
                controller.setShowWhen(action.value);
                break;
            case 'enable':
                controller.enable();
                break;
            case 'disable':
                controller.disable();
                break;
            case 'reset':
                if (typeof controller.reset === 'function') {
                    controller.reset();
                }
                break;
        }
    }

    handleGroupAction(action) {
        const groupName = action.target;

        // Find the group wrapper
        const $group = this.$form.find(`[data-group-name="${groupName}"]`).first();
        if (!$group.length) {
            console.warn(`Target group not found: ${groupName}`);
            return;
        }

        // Find all fields in this group
        const $fieldsInGroup = $group.find('.htax-field-wrapper');

        // Find all controllers in this group (both inside and at group level)
        const $controllersInGroup = this.$form.find(`.htax-controller-wrapper[data-group-name="${groupName}"]`);

        switch (action.type) {
            case 'set_show_when':
                // Apply visibility to group itself
                const validValues = ['always', 'expanded', 'collapsed', 'never'];
                if (!validValues.includes(action.value)) return;

                $group.removeClass('htax-show-expanded htax-show-collapsed htax-hidden');
                switch (action.value) {
                    case 'never':
                        $group.addClass('htax-hidden');
                        break;
                    case 'expanded':
                        $group.addClass('htax-show-expanded');
                        break;
                    case 'collapsed':
                        $group.addClass('htax-show-collapsed');
                        break;
                }
                break;

            case 'enable':
                // Enable all fields in group
                $fieldsInGroup.each((_, el) => {
                    const fieldName = $(el).data('field-name');
                    const field = this.form.fields[fieldName];
                    if (field && typeof field.enable === 'function') {
                        field.enable();
                    }
                });
                // Enable all controllers in group
                $controllersInGroup.each((_, el) => {
                    const controllerName = $(el).data('controller-name');
                    const controller = this.form.controllers[controllerName];
                    if (controller && typeof controller.enable === 'function') {
                        controller.enable();
                    }
                });
                break;

            case 'disable':
                // Disable all fields in group
                $fieldsInGroup.each((_, el) => {
                    const fieldName = $(el).data('field-name');
                    const field = this.form.fields[fieldName];
                    if (field && typeof field.disable === 'function') {
                        field.disable();
                    }
                });
                // Disable all controllers in group
                $controllersInGroup.each((_, el) => {
                    const controllerName = $(el).data('controller-name');
                    const controller = this.form.controllers[controllerName];
                    if (controller && typeof controller.disable === 'function') {
                        controller.disable();
                    }
                });
                break;

            case 'reset':
                // Reset all fields in group
                $fieldsInGroup.each((_, el) => {
                    const fieldName = $(el).data('field-name');
                    const field = this.form.fields[fieldName];
                    if (field && typeof field.reset === 'function') {
                        field.reset();
                    }
                });
                // Reset all controllers in group
                $controllersInGroup.each((_, el) => {
                    const controllerName = $(el).data('controller-name');
                    const controller = this.form.controllers[controllerName];
                    if (controller && typeof controller.reset === 'function') {
                        controller.reset();
                    }
                });
                break;
        }
    }
    }

    /**
     * Link controller handler
     */
    class LinkController extends Controller {
        bindEvents() {
            this.$wrapper.on('click', 'a[data-actions]', (e) => {
                e.preventDefault();
                const $link = $(e.currentTarget);
                const actions = this.getActionsFromElement($link);
                this.executeActionsByPriority(actions);
            });
        }
    }

    /**
     * Button controller handler (similar to link but for buttons)
     */
    class ButtonController extends Controller {
        bindEvents() {
            this.$wrapper.on('click', 'button[data-actions]', (e) => {
                e.preventDefault();
                const $button = $(e.currentTarget);
                const actions = this.getActionsFromElement($button);
                this.executeActionsByPriority(actions);
            });
        }
    }

    // Initialize on document ready
    $(document).ready(() => {
        // Initialize full search forms
        $('.htax-search-form').each((i, el) => {
            HTAXSearchWidget.initForm(el);
        });

        // Initialize standalone fields (not inside a form)
        $('.htax-field-wrapper').each((i, el) => {
            const $wrapper = $(el);
            // Skip if already inside a form (will be initialized by form)
            if ($wrapper.closest('.htax-search-form').length > 0) {
                return;
            }

            // Initialize standalone field
            const fieldType = $wrapper.data('field-type');
            const fieldName = $wrapper.data('field-name');

            if (!fieldName) return;

            // Create appropriate field instance
            switch (fieldType) {
                case 'pills':
                    new PillsField($wrapper, null);
                    break;
                case 'checklist':
                    new ChecklistField($wrapper, null);
                    break;
            }
        });

        // Handle pagination prev/next buttons inside search result containers.
        // Works for both search widget and PowerSearch (both use .htax-search-results).
        $(document).on('click', '.htax-search-results .htax-results-prev, .htax-search-results .htax-results-next', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const page = parseInt($btn.data('page'), 10);
            const $results = $btn.closest('.htax-search-results');
            const instance = $results.data('htax-form-instance');
            if (page && instance) {
                instance.performSearch(page);
            }
        });

        // Unified sort-change handler for all .htax-search-results containers.
        // Works for search widget forms AND PowerSearch forms — both store their
        // instance via $results.data('htax-form-instance') on first search.
        $(document).on('htax:sort-change', '.htax-search-results [data-htax-sort-root], .htax-search-results-block [data-htax-sort-root]', function(_e, newValue) {
            let $results = $(this).closest('.htax-search-results');
            if (!$results.length) {
                $results = $(this).closest('.htax-search-results-block').find('.htax-search-results').first();
            }
            if (!$results.length) { return; }
            $results.data('orderby', newValue || 'relevance:desc,date:desc');
            const instance = $results.data('htax-form-instance');
            if (instance) {
                instance.performSearch(1);
            }
        });
    });
    
})(jQuery);