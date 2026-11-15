/**
 * Sort Order Picker Component
 *
 * A pill-based, drag-and-drop sort criterion selector. Used in:
 *   - Admin: term edit screen (configure per-term archive sort order)
 *   - Frontend: archives, post lists, search results (user-selectable sort)
 *
 * HTML contract (must be present in the container's DOM subtree):
 *   [data-htax-sort-container]   — wrapper for active pills + add button
 *   [data-htax-sort-picker]      — popover listing available field pills
 *   [data-htax-sort-add]         — "+" button that opens the picker
 *   [data-htax-sort-value]       — hidden/text input holding serialized value
 *   .htax-sort-picker-pill[data-field] — one element per available field
 *
 * Root element data attributes:
 *   data-htax-sort-action  — "navigate" | "submit" | omit for admin-only mode
 *                            navigate: rewrites URL with ?htax_orderby=… and navigates
 *                            submit:   finds ancestor <form>, resets paged to 1, submits
 *
 * Serialized format: "field:dir,field:dir"  e.g. "date:desc,title:asc"
 *
 * Events fired on the root element (bubbles):
 *   htax:sort-change — jQuery event, second arg is the new serialized value string
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */
window.htaxSortOrder = (function($) {
    'use strict';

    // Unique counter for namespacing document-level click handlers per instance
    var instanceCount = 0;

    /**
     * Initialize a sort order picker within a root element.
     *
     * @param {HTMLElement|string} root  Container element or selector wrapping all component parts.
     * @param {Object}             opts
     * @param {Function}           [opts.onChange]  Called with the new serialized value on any change.
     * @returns {Object|null} Instance with { getValue, setValue, destroy } methods, or null if invalid.
     */
    function init(root, opts) {
        opts = opts || {};
        var $root       = $(root);
        var $container  = $root.find('[data-htax-sort-container]');
        var $picker     = $root.find('[data-htax-sort-picker]');
        var $addBtn     = $root.find('[data-htax-sort-add]');
        var $valueInput = $root.find('[data-htax-sort-value]');
        var $dragging   = null;
        var initialized = false;
        var ns          = 'htax-sort-' + (++instanceCount);

        if (!$container.length) return null;

        // ----------------------------------------------------------------
        // Collapse / expand
        // ----------------------------------------------------------------

        function syncToggleText() {
            var $toggle = $root.find('.htax-sort-toggle');
            if (!$toggle.length) return;
            var isCollapsed = $root.attr('data-state') === 'collapsed';
            $toggle.find('.htax-sort-toggle-text').text(
                isCollapsed ? $toggle.data('text-collapsed') : $toggle.data('text-expanded')
            );
        }

        $root.on('click.' + ns, '.htax-sort-toggle', function(e) {
            e.stopPropagation();
            var next = $root.attr('data-state') === 'collapsed' ? 'expanded' : 'collapsed';
            $root.attr('data-state', next).data('state', next);
            syncToggleText();
            if (next === 'collapsed') $picker.hide();
        });

        // ----------------------------------------------------------------
        // Helpers
        // ----------------------------------------------------------------

        function getLabel(field) {
            var $p = $picker.find('.htax-sort-picker-pill[data-field="' + field + '"]');
            return $p.length ? $p.text().trim() : field;
        }

        /**
         * Build a clean URL for the navigate action.
         * Strips /page/N/ pagination, removes paged= and htax_orderby= query params,
         * then appends the new htax_orderby value (omits the param when val is empty).
         */
        function buildNavigateUrl(val) {
            var href       = window.location.href;
            var queryStart = href.indexOf('?');
            var path       = queryStart !== -1 ? href.substring(0, queryStart) : href;
            var query      = queryStart !== -1 ? href.substring(queryStart + 1) : '';

            // Strip WordPress pretty-URL pagination: /page/N/ at end of path
            path = path.replace(/\/page\/\d+\/?$/, '/');

            // Rebuild query without paged and htax_orderby
            var params = [];
            if (query) {
                query.split('&').forEach(function(pair) {
                    if (pair && !/^(paged|htax_orderby)=/.test(pair)) {
                        params.push(pair);
                    }
                });
            }

            if (val) {
                params.push('htax_orderby=' + encodeURIComponent(val));
            }

            return path + (params.length ? '?' + params.join('&') : '');
        }

        /**
         * Execute the configured action (navigate or submit) after a user change.
         * No-op in admin mode (no data-htax-sort-action).
         */
        function handleAction(val) {
            var action = $root.data('htax-sort-action');
            if (!action) return;

            if (action === 'navigate') {
                window.location.href = buildNavigateUrl(val);

            } else if (action === 'submit') {
                var $form = $root.closest('form');
                if (!$form.length) return;

                // Reset pagination to page 1 before re-submitting
                var $paged = $form.find('input[name="paged"]');
                if ($paged.length) {
                    $paged.val(1);
                } else {
                    $form.append('<input type="hidden" name="paged" value="1">');
                }

                $form.submit();
            }
        }

        function serialize() {
            var pairs = [];
            $container.find('.htax-sort-order-pill').each(function() {
                pairs.push($(this).data('field') + ':' + $(this).data('dir'));
            });
            var val = pairs.join(',');
            $valueInput.val(val);

            // Only fire events and actions after initialization is complete,
            // not while building the initial pill state from the stored value.
            if (initialized) {
                $root.trigger('htax:sort-change', [val]);
                handleAction(val);
                if (opts.onChange) opts.onChange(val);
            }
        }

        function makePill(field, dir) {
            var label    = getLabel(field);
            var dirIcon  = dir === 'asc' ? '&#8593;' : '&#8595;';
            var dirLabel = dir === 'asc'
                ? ($container.data('label-asc')  || 'Ascending \u2014 click to toggle')
                : ($container.data('label-desc') || 'Descending \u2014 click to toggle');

            return $('<div class="htax-sort-order-pill" draggable="true"></div>')
                .attr('data-field', field)
                .attr('data-dir',   dir)
                .append('<span class="htax-sort-drag-handle" aria-hidden="true">&#8942;</span>')
                .append($('<span class="htax-sort-label"></span>').text(label))
                .append(
                    $('<span class="htax-sort-dir" aria-hidden="true"></span>')
                        .html(dirIcon)
                        .attr('title', dirLabel)
                )
                .append(
                    $('<button type="button" class="htax-remove"></button>')
                        .attr('aria-label', $container.data('label-remove') || 'Remove')
                        .html('<span aria-hidden="true">&times;</span>')
                );
        }

        function syncAddBtn() {
            // Use inline style check — can't use :visible because the picker container
            // itself is display:none, which would make all children report as not visible.
            var hasOptions = $picker.find('.htax-sort-picker-pill').filter(function() {
                return this.style.display !== 'none';
            }).length > 0;
            $addBtn.toggle(hasOptions);
        }

        function addField(field, dir) {
            $container.find('.htax-sort-empty-hint').remove();
            $addBtn.before(makePill(field, dir || 'desc'));
            $picker.find('.htax-sort-picker-pill[data-field="' + field + '"]').hide();
            syncAddBtn();
            serialize();
        }

        function removeField($pill) {
            var field = $pill.data('field');
            $pill.remove();
            $picker.find('.htax-sort-picker-pill[data-field="' + field + '"]').show();
            syncAddBtn();
            serialize();
        }

        function setValue(val) {
            // Remove all existing active pills and restore all picker pills
            $container.find('.htax-sort-order-pill').remove();
            $picker.find('.htax-sort-picker-pill').show();

            if (!val) {
                syncAddBtn();
                return;
            }
            val.split(',').forEach(function(part) {
                part = part.trim();
                if (!part) return;
                var colon = part.indexOf(':');
                var field = colon !== -1 ? part.substring(0, colon).trim() : part;
                var dir   = colon !== -1 ? part.substring(colon + 1).trim().toLowerCase() : 'desc';
                if ($picker.find('.htax-sort-picker-pill[data-field="' + field + '"]').length) {
                    addField(field, dir === 'asc' ? 'asc' : 'desc');
                }
            });
            syncAddBtn();
        }

        function getValue() {
            return $valueInput.val();
        }

        // ----------------------------------------------------------------
        // Build initial state from existing input value (before events fire)
        // ----------------------------------------------------------------
        setValue($valueInput.val());
        initialized = true;

        // ----------------------------------------------------------------
        // Event: toggle sort direction (click anywhere on pill except remove)
        // ----------------------------------------------------------------
        $container.on('click.' + ns, '.htax-sort-order-pill', function(e) {
            if ($(e.target).closest('.htax-remove').length) return;
            var $pill    = $(this);
            var newDir   = $pill.data('dir') === 'asc' ? 'desc' : 'asc';
            var dirIcon  = newDir === 'asc' ? '&#8593;' : '&#8595;';
            var dirLabel = newDir === 'asc'
                ? ($container.data('label-asc')  || 'Ascending \u2014 click to toggle')
                : ($container.data('label-desc') || 'Descending \u2014 click to toggle');
            $pill.data('dir', newDir).attr('data-dir', newDir);
            $pill.find('.htax-sort-dir').html(dirIcon).attr('title', dirLabel);
            serialize();
        });

        // ----------------------------------------------------------------
        // Event: remove pill
        // ----------------------------------------------------------------
        $container.on('click.' + ns, '.htax-sort-order-pill .htax-remove', function(e) {
            e.stopPropagation();
            removeField($(this).closest('.htax-sort-order-pill'));
        });

        // ----------------------------------------------------------------
        // Event: open/close picker
        // ----------------------------------------------------------------
        $addBtn.on('click.' + ns, function(e) {
            e.stopPropagation();
            $picker.toggle();
        });

        $(document).on('click.' + ns, function(e) {
            if (!$(e.target).closest($picker).length && !$(e.target).closest($addBtn).length) {
                $picker.hide();
            }
        });

        $(document).on('keydown.' + ns, function(e) {
            if (e.key === 'Escape') $picker.hide();
        });

        // ----------------------------------------------------------------
        // Event: pick a field from the picker
        // ----------------------------------------------------------------
        $picker.on('click.' + ns, '.htax-sort-picker-pill', function() {
            addField($(this).data('field'), 'desc');
            // addField → syncAddBtn hides the + button when no options remain;
            // also close the picker if it is now empty.
            if (!$addBtn.is(':visible')) {
                $picker.hide();
            }
        });

        // ----------------------------------------------------------------
        // Drag-and-drop reordering (HTML5)
        // ----------------------------------------------------------------
        $container.on('dragstart.' + ns, '.htax-sort-order-pill', function(e) {
            $dragging = $(this);
            $(this).addClass('htax-sort-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', ''); // required by Firefox
        });

        $container.on('dragend.' + ns, '.htax-sort-order-pill', function() {
            $(this).removeClass('htax-sort-dragging');
            $dragging = null;
            serialize();
        });

        // Reorder when hovering over another pill
        $container.on('dragover.' + ns, '.htax-sort-order-pill', function(e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            if (!$dragging || $(this).is($dragging)) return;
            var rect = this.getBoundingClientRect();
            if (e.originalEvent.clientX < rect.left + rect.width / 2) {
                $dragging.insertBefore($(this));
            } else {
                $dragging.insertAfter($(this));
            }
        });

        // Hovering over the "+" button places the pill last
        $container.on('dragover.' + ns, '[data-htax-sort-add]', function(e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            if ($dragging) $dragging.insertBefore($(this));
        });

        $container.on('drop.' + ns, function(e) {
            e.preventDefault();
        });

        // ----------------------------------------------------------------
        // Public API
        // ----------------------------------------------------------------
        return {
            getValue: getValue,
            setValue: setValue,
            destroy: function() {
                $container.off('.' + ns);
                $addBtn.off('.' + ns);
                $picker.off('.' + ns);
                $(document).off('.' + ns);
            }
        };
    }

    // Auto-initialize any [data-htax-sort-root] elements present on DOMReady
    $(function() {
        $('[data-htax-sort-root]').each(function() {
            init(this);
        });
    });

    return { init: init };

})(jQuery);
