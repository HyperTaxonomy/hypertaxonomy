/**
 * HyperTaxonomy Settings Page JavaScript
 *
 * Handles all interactive functionality for the plugin settings page including:
 * - Unsaved changes detection and navigation warnings
 * - Tab and subtab navigation with session persistence
 * - Taxonomy feature cascading dependencies
 * - Redirect settings dependencies
 * - Icon preview and styling
 * - AJAX index rebuilding
 * - Relevance weight defaults reset
 *
 * @package HyperTaxonomy
 */

(function($) {
    'use strict';

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    // Track unsaved changes
    var originalFormData = null;

    $(document).ready(function() {
        initUnsavedChangesTracking();
        initSubtabNavigation();
        initRedirectDependencies();
        initIconPreview();
        initIndexRebuild();
        initStrengthModel();
        initDeleteAllIcons();
        initRelevanceWeightReset();
        initContributingToggle();
    });

    // =========================================================================
    // UNSAVED CHANGES DETECTION
    // =========================================================================

    /**
     * Initialize unsaved changes tracking
     */
    function initUnsavedChangesTracking() {
        // Capture original form state on page load
        originalFormData = serializeFormData();

        // Reset original form data when form is submitted
        $('.htax-settings-form').on('submit', function() {
            originalFormData = serializeFormData();
        });

        // Sync settings save over AJAX rather than form submit; re-baseline after success
        $(document).on('htax:sync-settings-saved', function() {
            originalFormData = serializeFormData();
        });

        // Warn before leaving page with unsaved changes
        $(window).on('beforeunload', function(e) {
            if (hasFormChanged()) {
                var message = 'You have unsaved changes. Are you sure you want to leave?';
                e.returnValue = message;
                return message;
            }
        });
    }

    /**
     * Serialize form data into a comparable format
     * Handles checkboxes, radios, selects, and text inputs
     *
     * @return {object} Object with field names as keys and values
     */
    function serializeFormData() {
        var data = {};

        $('.htax-settings-form').find('input, select, textarea').each(function() {
            var $field = $(this);
            var name = $field.attr('name');

            if (!name) {
                return; // Skip fields without names
            }

            // Export/Import subtabs hold transient selections, not saved settings —
            // only the Sync subtab's controls are persisted, so only those are tracked.
            if ($field.closest('.htax-tab-export-import').length && !$field.closest('.htax-sync-settings').length) {
                return;
            }

            if ($field.is(':checkbox')) {
                // For checkboxes, track checked state
                if (!data[name]) {
                    data[name] = [];
                }
                if ($field.is(':checked')) {
                    data[name].push($field.val());
                }
            } else if ($field.is(':radio')) {
                // For radio buttons, only store if checked
                if ($field.is(':checked')) {
                    data[name] = $field.val();
                }
            } else {
                // For text inputs, textareas, selects
                data[name] = $field.val();
            }
        });

        return data;
    }

    /**
     * Check if form has changed from original state
     *
     * @return {boolean} True if form has changes, false otherwise
     */
    function hasFormChanged() {
        if (!originalFormData) {
            return false;
        }

        var currentData = serializeFormData();

        // Compare current data with original data
        return JSON.stringify(currentData) !== JSON.stringify(originalFormData);
    }

    /**
     * Show confirmation dialog before navigation if there are unsaved changes
     *
     * @param {string} targetUrl - The URL to navigate to
     * @return {boolean} True to proceed with navigation, false to cancel
     */
    function confirmNavigation(targetUrl) {
        if (!hasFormChanged()) {
            return true;
        }

        var message = 'You have unsaved changes that will be lost if you leave this page.\n\nDo you want to continue without saving?';
        if (confirm(message)) {
            // Update original form data so we don't get double-prompted
            originalFormData = serializeFormData();
            return true;
        }
        return false;
    }

    // =========================================================================
    // SUBTAB NAVIGATION & SESSION PERSISTENCE
    // =========================================================================

    /**
     * Subtab config per top-level tab: URL query param, nav link selector, and
     * the tab's storage key stem. The URL param names aren't derivable from the
     * tab slug (features uses general_subtab, export-import uses export_import_subtab).
     */
    var SUBTABS = {
        'features':      { param: 'general_subtab',       nav: '.htax-general-subtabs a' },
        'search':        { param: 'search_subtab',        nav: '.htax-search-subtabs a' },
        'icons':         { param: 'icons_subtab',         nav: '.htax-icons-subtabs a' },
        'export-import': { param: 'export_import_subtab', nav: '.htax-export-import-subtabs a' }
    };

    /**
     * Initialize subtab navigation with session storage persistence
     */
    function initSubtabNavigation() {
        var currentUrl = new URL(window.location.href);
        var currentSubtabs = {};

        // Initialize from sessionStorage, overridden by URL when present (URL takes precedence)
        Object.keys(SUBTABS).forEach(function(tab) {
            var stored = sessionStorage.getItem('htax_' + tab + '_subtab') || '';
            var fromUrl = currentUrl.searchParams.get(SUBTABS[tab].param);
            if (fromUrl) {
                stored = fromUrl;
                sessionStorage.setItem('htax_' + tab + '_subtab', stored);
            }
            currentSubtabs[tab] = stored;
        });

        // Validate on page load
        if (!validateCurrentSubtab(currentUrl, currentSubtabs)) {
            return; // Redirecting, stop further execution
        }

        // When clicking a subtab link, remember which subtab for that tab
        var allNavLinks = Object.keys(SUBTABS).map(function(tab) { return SUBTABS[tab].nav; }).join(', ');
        $(allNavLinks).on('click', function(e) {
            var href = $(this).attr('href');

            // Check for unsaved changes before navigating
            if (!confirmNavigation(href)) {
                e.preventDefault();
                return false;
            }

            var url = new URL(href, window.location.origin);
            var tab = url.searchParams.get('tab');

            if (tab && SUBTABS[tab]) {
                var subtab = url.searchParams.get(SUBTABS[tab].param);
                if (subtab) {
                    currentSubtabs[tab] = subtab;
                    sessionStorage.setItem('htax_' + tab + '_subtab', subtab);
                }
            }
        });

        // When clicking a main tab, add the last active subtab to the URL
        $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
            var href = $(this).attr('href');

            // Handle relative URLs by using window.location as base
            var url;
            if (href.startsWith('?')) {
                url = new URL(window.location.pathname + href, window.location.origin);
            } else {
                url = new URL(href, window.location.origin);
            }

            var tab = url.searchParams.get('tab');

            // Check for unsaved changes before navigating
            if (!confirmNavigation(url.toString())) {
                e.preventDefault();
                return false;
            }

            if (SUBTABS[tab] && currentSubtabs[tab]) {
                e.preventDefault();
                url.searchParams.set(SUBTABS[tab].param, currentSubtabs[tab]);
                window.location.href = url.toString();
            }
        });
    }

    /**
     * Validate current subtab exists - if not, redirect to first available subtab
     *
     * @param {URL} currentUrl - Current page URL
     * @param {Object} currentSubtabs - Current subtab selections, keyed by tab slug
     * @return {boolean} True if valid, false if redirecting
     */
    function validateCurrentSubtab(currentUrl, currentSubtabs) {
        var currentTab    = currentUrl.searchParams.get('tab') || 'features';
        var config        = SUBTABS[currentTab];
        var currentSubtab = currentSubtabs[currentTab];

        if (!config || !currentSubtab) {
            return true;
        }

        var $links = $(config.nav);
        var subtabExists = $links.filter('[href*="' + config.param + '=' + currentSubtab + '"]').length > 0;

        if (!subtabExists && $links.length > 0) {
            var firstSubtabUrl = new URL($links.first().attr('href'), window.location.origin);
            var firstSubtab = firstSubtabUrl.searchParams.get(config.param);
            if (firstSubtab) {
                currentUrl.searchParams.set(config.param, firstSubtab);
                window.location.href = currentUrl.toString();
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // REDIRECT SEARCH DEPENDENCIES
    // =========================================================================

    /**
     * Initialize redirect settings dependencies for search functionality
     * Handles dependencies for:
     * - Per-taxonomy redirect settings
     * - Custom flat taxonomy redirect defaults
     * - Custom hierarchical taxonomy redirect defaults
     */
    function initRedirectDependencies() {
        // Custom flat and hierarchical taxonomy redirect dependencies share identical logic.
        bindRedirectDependency('custom-flat');
        bindRedirectDependency('custom-hierarchical');
    }

    /**
     * Bind redirect checkbox dependencies for a given CSS class suffix
     * (e.g. 'custom-flat' or 'custom-hierarchical').
     */
    function bindRedirectDependency(suffix) {
        var $follow             = $('.htax-follow-redirects-' + suffix);
        var $include            = $('.htax-include-redirect-inclusions-' + suffix);
        var $targetThreshold    = $('.htax-redirect-target-threshold-' + suffix);
        var $inclusionsThreshold = $('.htax-redirect-inclusions-threshold-' + suffix);

        $follow.on('change', function() {
            if ($(this).is(':checked')) {
                $include.prop('disabled', false);
                $targetThreshold.prop('disabled', false);
                if ($include.is(':checked')) {
                    $inclusionsThreshold.prop('disabled', false);
                }
            } else {
                $include.prop('disabled', true).prop('checked', false);
                $targetThreshold.prop('disabled', true);
                $inclusionsThreshold.prop('disabled', true);
            }
        });

        $include.on('change', function() {
            $inclusionsThreshold.prop('disabled', !$(this).is(':checked'));
        });
    }

    // =========================================================================
    // ICON PREVIEW & STYLING
    // =========================================================================

    /**
     * Initialize icon preview functionality
     * Handles border radius preview and custom corner sliders
     */
    function initIconPreview() {
        // Bind change event to radio buttons
        $('input.htax-border-style').on('change', function() {
            toggleCustomCorners();
            updateIconPreview();
        });

        // Bind input event to corner sliders
        $('.htax-corner-slider').on('input', function() {
            // Update the displayed percentage value
            $(this).next('.htax-corner-value').text($(this).val() + '%');
            // Update preview
            updateIconPreview();
        });

        // Bind input event to width/height and size-percentage fields
        $('input[name*="[icon_settings]"][name*="[width]"], input[name*="[icon_settings]"][name*="[height]"], input[name*="[icon_settings]"][name*="[medium_pct]"], input[name*="[icon_settings]"][name*="[small_pct]"]').on('input', function() {
            updateIconPreview();
        });

        // Initialize on page load
        toggleCustomCorners();
        updateIconPreview();

        // Re-initialize when switching to Icons tab
        $('.nav-tab[href*="tab=icons"]').on('click', function() {
            setTimeout(function() {
                updateIconPreview();
            }, 100);
        });
    }

    /**
     * Update icon preview based on border style settings and dimensions
     */
    function updateIconPreview() {
        var $checked = $('input.htax-border-style:checked');
        var style = $checked.val() || 'rounded';
        var borderRadius = '';

        switch(style) {
            case 'square':
                borderRadius = '0';
                break;
            case 'rounded':
                borderRadius = '4px';
                break;
            case 'extra_rounded':
                borderRadius = '8px';
                break;
            case 'circular':
                borderRadius = '50%';
                break;
            case 'custom':
                var tl = $('#htax-corner-tl').val() || '20';
                var tr = $('#htax-corner-tr').val() || '20';
                var bl = $('#htax-corner-bl').val() || '20';
                var br = $('#htax-corner-br').val() || '20';
                borderRadius = tl + '% ' + tr + '% ' + br + '% ' + bl + '%';
                break;
        }

        // Get current values from visible inputs in the active tab
        var width      = parseInt($('input[name*="[icon_settings]"][name*="[width]"]:visible').first().val())      || 96;
        var height     = parseInt($('input[name*="[icon_settings]"][name*="[height]"]:visible').first().val())     || 96;
        var mediumPct  = parseInt($('input[name*="[icon_settings]"][name*="[medium_pct]"]:visible').first().val()) || 50;
        var smallPct   = parseInt($('input[name*="[icon_settings]"][name*="[small_pct]"]:visible').first().val())  || 25;

        // large = configured size; medium and small are percentages of large
        var smallWidth  = Math.round(width  * smallPct  / 100);
        var smallHeight = Math.round(height * smallPct  / 100);
        var mediumWidth  = Math.round(width  * mediumPct / 100);
        var mediumHeight = Math.round(height * mediumPct / 100);

        // Update small preview
        $('.htax-preview-small').css({
            'width': smallWidth + 'px',
            'height': smallHeight + 'px',
            'border-radius': borderRadius,
            'transition': 'all 0.3s ease-in-out'
        }).siblings('p').text('Small (' + smallWidth + '×' + smallHeight + ')');

        // Update medium preview
        $('.htax-preview-medium').css({
            'width': mediumWidth + 'px',
            'height': mediumHeight + 'px',
            'border-radius': borderRadius,
            'transition': 'all 0.3s ease-in-out'
        }).siblings('p').text('Medium (' + mediumWidth + '×' + mediumHeight + ')');

        // Update full-size preview
        $('.htax-preview-large').css({
            'width': width + 'px',
            'height': height + 'px',
            'border-radius': borderRadius,
            'transition': 'all 0.3s ease-in-out'
        }).siblings('p').text('Full (' + width + '×' + height + ')');

        // Update colored placeholder squares at small size
        $('.htax-placeholder-square').css({
            'width': smallWidth + 'px',
            'height': smallHeight + 'px',
            'border-radius': borderRadius,
            'transition': 'all 0.3s ease-in-out'
        });

        // Update the line-height of the "+ 15 more colors" text
        $('.htax-placeholder-square').siblings('span').css('line-height', smallHeight + 'px');
    }

    /**
     * Toggle visibility of custom corner controls
     */
    function toggleCustomCorners() {
        if ($('input.htax-border-style:checked').val() === 'custom') {
            $('#htax-custom-corners').slideDown(200);
        } else {
            $('#htax-custom-corners').slideUp(200);
        }
    }

    // =========================================================================
    // REBUILD INDEX (AJAX)
    // =========================================================================

    /**
     * Initialize rebuild index button with AJAX functionality
     * Requires localized data: htaxSettings.rebuildIndexNonce
     */
    function initIndexRebuild() {
        $('#htax-rebuild-index').on('click', function() {
            var $button   = $(this);
            var $progress = $('#htax-rebuild-progress');
            var $bar      = $('#htax-rebuild-bar');
            var $label    = $('#htax-rebuild-label');

            function setProgress(pct, text) {
                $bar.css('width', pct + '%').attr('aria-valuenow', pct);
                $label.text(text);
            }

            function showError(msg) {
                $label.html('<span style="color:#d63638">' + msg + '</span>');
                $button.prop('disabled', false).text(htaxSettings.rebuildIndexBtn || 'Rebuild Search Index');
            }

            $button.prop('disabled', true);
            $progress.show();
            setProgress(0, '0%');

            $.post(ajaxurl, {
                action: 'htax_rebuild_indexes',
                nonce:  htaxSettings.rebuildIndexNonce
            }, function(response) {
                if (!response.success) {
                    showError(htaxSettings.indexRebuildError);
                    return;
                }

                var totalPosts = response.data.total || 0;
                var processed  = 0;

                function processBatch(offset) {
                    setProgress(
                        totalPosts > 0 ? Math.round(processed / totalPosts * 100) : 0,
                        processed + ' / ' + totalPosts
                    );

                    $.post(ajaxurl, {
                        action: 'htax_bulk_rebuild',
                        nonce:  htaxSettings.rebuildIndexNonce,
                        offset: offset
                    }, function(batchResponse) {
                        if (!batchResponse.success) {
                            showError(htaxSettings.indexRebuildError);
                            return;
                        }
                        processed += batchResponse.data.processed;
                        if (batchResponse.data.done) {
                            setProgress(100, htaxSettings.indexRebuildSuccess || 'Complete');
                            $button.prop('disabled', false);
                        } else {
                            processBatch(batchResponse.data.next_offset);
                        }
                    }).fail(function() { showError(htaxSettings.indexRebuildError); });
                }

                processBatch(0);

            }).fail(function() {
                showError(htaxSettings.indexRebuildError);
                $button.prop('disabled', false);
            });
        });
    }

    // =========================================================================
    // STRENGTH MODEL (panel switching + Clear Scores AJAX)
    // =========================================================================

    /**
     * Show the Index Coverage / Index Rebuild panels of the model chosen in the
     * Strength Model dropdown, and wire the Clear Scores buttons of external models.
     * Requires localized data: htaxSettings.clearStrengthModelNonce,
     * htaxSettings.confirmClearStrengthModel, htaxSettings.clearingStrengthModel,
     * htaxSettings.clearStrengthModelError
     */
    function initStrengthModel() {
        var $select = $('#htax-strength-model');
        if (!$select.length) {
            return;
        }

        $select.on('change', function() {
            var model = $(this).val();
            // Tells the sanitizer the selection was deliberately changed.
            $('#htax-strength-model-changed').val('1');
            $('.htax-strength-model-panel').each(function() {
                $(this).toggle($(this).data('model') === model);
            });
        });

        $('.htax-clear-strength-model').on('click', function() {
            var $button = $(this);
            var $status = $button.siblings('.htax-clear-strength-model-status');

            if (!confirm(htaxSettings.confirmClearStrengthModel)) {
                return;
            }

            $button.prop('disabled', true);
            $status.text(htaxSettings.clearingStrengthModel);

            $.post(ajaxurl, {
                action: 'htax_clear_strength_model',
                nonce:  htaxSettings.clearStrengthModelNonce,
                model:  $button.data('model')
            }, function(response) {
                if (!response.success) {
                    var msg = response.data && response.data.message ? response.data.message : htaxSettings.clearStrengthModelError;
                    $status.html('<span style="color:#d63638"></span>').find('span').text(msg);
                    $button.prop('disabled', false);
                    return;
                }
                // Reload so coverage and the (possibly reverted) selection reflect the cleared model.
                $(window).off('beforeunload');
                window.location.reload();
            }).fail(function() {
                $status.html('<span style="color:#d63638"></span>').find('span').text(htaxSettings.clearStrengthModelError);
                $button.prop('disabled', false);
            });
        });
    }

    // =========================================================================
    // DELETE ALL ICONS (AJAX)
    // =========================================================================

    /**
     * Initialize delete all icons button with AJAX functionality
     * Requires localized data: htaxSettings.deleteAllIconsNonce
     */
    function initDeleteAllIcons() {
        $('.htax-delete-all-icons').on('click', function() {
            var $button = $(this);
            var $statusDiv = $button.closest('td').find('.htax-delete-icons-status');
            var taxonomy = $button.data('taxonomy');
            var taxonomyLabel = $button.data('taxonomy-label');

            // Confirm deletion
            var confirmMessage = htaxSettings.confirmDeleteAllIcons.replace('%s', taxonomyLabel.toLowerCase());
            if (!confirm(confirmMessage)) {
                return;
            }

            $button.prop('disabled', true);
            $statusDiv.html('<span class="spinner is-active" style="float:none;margin:0;"></span> ' +
                          htaxSettings.deletingIcons);

            $.post(ajaxurl, {
                action: 'htax_delete_all_icons',
                nonce: htaxSettings.deleteAllIconsNonce,
                taxonomy: taxonomy
            }, function(response) {
                if (response.success) {
                    $statusDiv.html('<span class="notice notice-success" style="display:inline-block;margin:0;padding:5px 10px;"></span>')
                              .find('span').text(response.data.message);
                } else {
                    var errMsg = response.data && response.data.message ? response.data.message : htaxSettings.iconsDeleteError;
                    $statusDiv.html('<span class="notice notice-error" style="display:inline-block;margin:0;padding:5px 10px;"></span>')
                              .find('span').text(errMsg);
                }
                $button.prop('disabled', false);

                // Remove status after 5 seconds
                setTimeout(function() {
                    $statusDiv.fadeOut(function() {
                        $statusDiv.html('').show();
                    });
                }, 5000);
            }).fail(function() {
                $statusDiv.html('<span class="notice notice-error" style="display:inline-block;margin:0;padding:5px 10px;">' +
                              htaxSettings.iconsDeleteError + '</span>');
                $button.prop('disabled', false);

                setTimeout(function() {
                    $statusDiv.fadeOut(function() {
                        $statusDiv.html('').show();
                    });
                }, 5000);
            });
        });
    }

    // =========================================================================
    // RELEVANCE WEIGHT RESET
    // =========================================================================

    /**
     * Initialize relevance weight reset functionality
     * Handles resetting weights to default values for each taxonomy
     * Requires localized data: htaxSettings.confirmResetWeights, htaxSettings.weightsResetSuccess
     */
    function initRelevanceWeightReset() {
        $('.htax-reset-weights').on('click', function() {
            if (!confirm(htaxSettings.confirmResetWeights)) {
                return;
            }

            var $button = $(this);
            var taxonomy = $button.data('taxonomy');
            var isHierarchical = $button.data('hierarchical') === true || $button.data('hierarchical') === 'true';
            var hasInclusion = $button.data('inclusion') === true || $button.data('inclusion') === 'true';
            var hasTagGroups = $button.data('tag-groups') === true || $button.data('tag-groups') === 'true';
            var showTextColumn = $button.data('show-text') === true || $button.data('show-text') === 'true';

            // Default values (0-1.0 range)
            var defaults = {
                direct: { explicit: 1.0, text: 0.6 },
                hierarchy: { explicit: 0.7, text: 0.4 },
                container_hierarchical: { explicit: 0.6, text: 0.35 },
                container_flat: { explicit: 0.75, text: 0.5 }
            };

            // Reset direct assignment
            $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][direct][explicit]"]').val(defaults.direct.explicit);
            if (showTextColumn) {
                $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][direct][text]"]').val(defaults.direct.text);
            }

            // Reset hierarchy (hierarchical taxonomies only)
            if (isHierarchical) {
                $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][hierarchy][explicit]"]').val(defaults.hierarchy.explicit);
                if (showTextColumn) {
                    $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][hierarchy][text]"]').val(defaults.hierarchy.text);
                }
            }

            // Reset container/inclusion
            if (hasInclusion) {
                var containerDefaults = isHierarchical ? defaults.container_hierarchical : defaults.container_flat;
                $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][container][explicit]"]').val(containerDefaults.explicit);
                if (showTextColumn) {
                    $('input[name="htax_settings[relevance_multipliers][taxonomies][' + taxonomy + '][container][text]"]').val(containerDefaults.text);
                }
            }

            alert(htaxSettings.weightsResetSuccess);
        });
    }

    // =========================================================================
    // MISCELLANEOUS UI TOGGLES
    // =========================================================================

    /**
     * Initialize contributing template field toggle
     */
    function initContributingToggle() {
        $('#htax-show-contributing').on('change', function() {
            if ($(this).is(':checked')) {
                $('#htax-template-field').slideDown();
            } else {
                $('#htax-template-field').slideUp();
            }
        });
    }

})(jQuery);
