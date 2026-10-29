/**
 * List Table JavaScript
 * Client-side functionality for term and post list tables in wp-admin
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
	// Escape a string for safe insertion into HTML attributes and text content.
	function htaxEscHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// Only run if filter config is available
	if (typeof htaxTablesConfig === 'undefined') {
		return;
	}

	var filterConfig = htaxTablesConfig;
	var $filterContainer = null;

	// WordPress term table structure:
	// The page has two columns:
	//   Left: "Add New Term" form
	//   Right: Term table with search form ABOVE the table navigation
	//
	// <div class="wrap">
	//   <div id="col-left">
	//     <form>...</form>  // Add new term form
	//   </div>
	//   <div id="col-right">
	//     <form class="search-form">
	//       <p class="search-box">...</p>  // Search box - floats right
	//     </form>
	//     <form id="posts-filter">
	//       <div class="tablenav top">
	//         <div class="alignleft actions bulkactions">...</div>  // Bulk actions
	//       </div>
	//       <table class="wp-list-table">...</table>
	//     </form>
	//   </div>
	// </div>
	//
	// We want filters in the search-form, before the search box

	// Find the search form - it's in the right column, above the table
	var $searchForm = $('#col-right .search-form, .wrap .search-form').first();

	if (!$searchForm.length) {
		return;
	}

	// Insert filters at the beginning of the search form (they'll float left, search floats right)
	// Use line-height to match search box height for vertical alignment
	$filterContainer = $('<div class="htax-filters"></div>');
	$searchForm.prepend($filterContainer);

	// Build filters in the correct order: Term Groups, Redirect, Icon, Visibility

	// 1. Build term groups filter if configured
	if (filterConfig.groupFilter) {
		// Create button with dropdown menu
		var selectedGroups = [];

		// Parse currently selected groups from URL
		var urlParams = new URLSearchParams(window.location.search);
		var groupParam = urlParams.get('htax_group_filter');
		if (groupParam) {
			selectedGroups = groupParam.split(',').filter(function(id) { return id !== ''; });
		}

		// Button label
		var buttonLabel = 'Groups';
		if (selectedGroups.length > 0) {
			buttonLabel = selectedGroups.length + ' group' + (selectedGroups.length === 1 ? '' : 's') + ' selected';
		}

		var groupHtml =
			'<div class="htax-group-filter-wrapper" style="position: relative; display: inline-block;">' +
				'<button type="button" class="button htax-group-filter-button" id="htax_group_filter_button">' +
					buttonLabel + ' <span class="dashicons dashicons-arrow-down-alt2"></span>' +
				'</button>' +
				'<div class="htax-group-filter-dropdown" id="htax_group_filter_dropdown" style="display: none; position: absolute; top: 100%; left: 0; z-index: 1000; background: #fff; border: 1px solid #8c8f94; box-shadow: 0 2px 5px rgba(0,0,0,0.1); min-width: 250px; max-height: 400px; overflow-y: auto;">' +
					'<div class="htax-group-filter-search" style="padding: 8px; border-bottom: 1px solid #ddd;">' +
						'<input type="search" id="htax_group_search" placeholder="Search groups..." style="width: 100%; padding: 3px 6px; border: 1px solid #dcdcde; box-sizing: border-box; font-size: 13px;" />' +
					'</div>' +
					'<div class="htax-group-filter-list" style="padding: 8px;">';

		// Build hierarchical checkboxes from config
		for (var i = 0; i < filterConfig.groupFilter.options.length; i++) {
			var option = filterConfig.groupFilter.options[i];
			var indent = option.depth * 20; // 20px per level
			var isChecked = selectedGroups.indexOf(option.value) >= 0;

			groupHtml +=
				'<div class="htax-group-option" data-depth="' + option.depth + '" data-value="' + option.value + '" data-label="' + htaxEscHtml(option.label.toLowerCase()) + '" style="padding: 4px 4px 4px ' + indent + 'px;">' +
					'<label style="display: block; cursor: pointer;">' +
						'<input type="checkbox" value="' + option.value + '"' + (isChecked ? ' checked' : '') + ' /> ' +
						htaxEscHtml(option.label) +
					'</label>' +
				'</div>';
		}

		groupHtml +=
					'</div>' +
					'<div class="htax-group-filter-actions" style="padding: 8px; border-top: 1px solid #ddd; text-align: right;">' +
						'<button type="button" class="button button-secondary htax-group-clear">Clear</button> ' +
						'<button type="button" class="button button-primary htax-group-apply">Apply</button>' +
					'</div>' +
				'</div>' +
			'</div>';

		// Append to filter container
		$filterContainer.append(groupHtml);

		// Toggle dropdown visibility
		$('#htax_group_filter_button').on('click', function(e) {
			e.stopPropagation();
			$('#htax_group_filter_dropdown').toggle();
		});

		// Close dropdown when clicking outside or pressing Escape.
		$(document).on('click', function(e) {
			if (!$(e.target).closest('.htax-group-filter-wrapper').length) {
				$('#htax_group_filter_dropdown').hide();
			}
		});
		$(document).on('keydown.htaxGroupFilter', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && $('#htax_group_filter_dropdown').is(':visible')) {
				$('#htax_group_filter_dropdown').hide();
				$('#htax_group_filter_button').trigger('focus');
			}
		});

		// Search / clear: type="search" provides the native × button; both input and
		// search events fire so the list updates when the native clear is clicked.
		$('#htax_group_search').on('input search', function() {
			var searchText = $(this).val().toLowerCase();
			$('.htax-group-option').each(function() {
				var label = $(this).attr('data-label');
				$(this).toggle(searchText === '' || label.indexOf(searchText) >= 0);
			});
		});

		// Clear button
		$('.htax-group-clear').on('click', function() {
			$('.htax-group-option input[type="checkbox"]').prop('checked', false);
		});

		// Apply button - update filters
		$('.htax-group-apply').on('click', function() {
			var selected = [];
			$('.htax-group-option input[type="checkbox"]:checked').each(function() {
				selected.push($(this).val());
			});

			// Join with commas for multiple selection
			var groupValue = selected.join(',');
			updateFilters({ group: groupValue });
		});

		// Hide the Tag Groups plugin's filter if present (rebranded "PublishPress Tag Groups"
		// in Oct 2026 — its filter markup, including this field name, is unchanged)
		$('select[name="term-group-top"]').closest('.actions').hide();
	}

	// 2. Build redirect filter if configured
	if (filterConfig.redirectFilter) {
		var redirectHtml = '<select name="htax_redirect_filter" id="htax_redirect_filter">' +
			'<option value="" ' + filterConfig.redirectFilter.selected.all + '>' + filterConfig.redirectFilter.labels.all + '</option>' +
			'<option value="standard" ' + filterConfig.redirectFilter.selected.standard + '>' + filterConfig.redirectFilter.labels.standard + '</option>' +
			'<option value="redirects" ' + filterConfig.redirectFilter.selected.redirects + '>' + filterConfig.redirectFilter.labels.redirects + '</option>' +
			'<option value="auto_redirects" ' + filterConfig.redirectFilter.selected.auto_redirects + '>' + filterConfig.redirectFilter.labels.auto_redirects + '</option>' +
			'<option value="targets" ' + filterConfig.redirectFilter.selected.targets + '>' + filterConfig.redirectFilter.labels.targets + '</option>' +
			'</select>';

		// Append to filter container
		$filterContainer.append(redirectHtml);

		$('#htax_redirect_filter').on('change', function() {
			updateFilters({ redirect: $(this).val() });
		});
	}

	// 3. Build icon filter if configured
	if (filterConfig.iconFilter) {
		var iconHtml = '<select name="htax_icon_filter" id="htax_icon_filter">' +
			'<option value="" ' + filterConfig.iconFilter.selected.all + '>' + filterConfig.iconFilter.labels.all + '</option>' +
			'<option value="with_icons" ' + filterConfig.iconFilter.selected.with_icons + '>' + filterConfig.iconFilter.labels.with_icons + '</option>' +
			'<option value="without_icons" ' + filterConfig.iconFilter.selected.without_icons + '>' + filterConfig.iconFilter.labels.without_icons + '</option>' +
			'</select>';

		// Append to filter container
		$filterContainer.append(iconHtml);

		$('#htax_icon_filter').on('change', function() {
			updateFilters({ icon: $(this).val() });
		});
	}

	// 4. Build visibility filter if configured
	if (filterConfig.visibilityFilter) {
		var visibilityHtml = '<select name="htax_visibility_filter" id="htax_visibility_filter">' +
			'<option value="" ' + filterConfig.visibilityFilter.selected.all + '>' + filterConfig.visibilityFilter.labels.all + '</option>' +
			'<option value="visible" ' + filterConfig.visibilityFilter.selected.visible + '>' + filterConfig.visibilityFilter.labels.visible + '</option>' +
			'<option value="hidden" ' + filterConfig.visibilityFilter.selected.hidden + '>' + filterConfig.visibilityFilter.labels.hidden + '</option>' +
			'</select>';

		// Append to filter container
		$filterContainer.append(visibilityHtml);

		$('#htax_visibility_filter').on('change', function() {
			updateFilters({ visibility: $(this).val() });
		});
	}

	// Position filters to align with bulk actions and search box
	// This needs to be done after filters are built
	function positionFilters() {
		var $searchBox = $('.search-box');
		var $colLeft = $('#col-left');

		if ($filterContainer && $filterContainer.length && $searchBox.length) {
			// Calculate left position to align with bulk actions below
			var leftOffset = 0;

			// Fine-tune alignment by checking actual bulk actions position
			var $bulkActions = $('.tablenav.top .bulkactions').first();
			if ($bulkActions.length) {
				var bulkActionsLeft = $bulkActions.offset().left;
				var searchFormLeft = $searchForm.offset().left;
				// Adjust leftOffset to match bulk actions exactly
				leftOffset = bulkActionsLeft - searchFormLeft;
			} else if ($colLeft.length) {
				// Fallback to col-left width calculation
				leftOffset = $colLeft.outerWidth(true) + 20;
			}

			// Apply styling to position it correctly
			$filterContainer.css({
				'display': 'inline-block',
				'float': 'left',
				'position': 'relative',
				'left': leftOffset + 'px',
				'margin': '0 10px 0 0',
				'padding-top': '11px',
				'line-height': '28px',
				'max-width': 'calc(100% - 300px - ' + leftOffset + 'px)'
			});
		}

		// Reorder filter elements to match the visual column order in the table header.
		// Maps column key (from th class) to the filter child inside $filterContainer.
		if ($filterContainer && $filterContainer.length) {
			var columnFilterMap = {
				'htax_term_groups': function() { return $filterContainer.children('.htax-group-filter-wrapper').first(); },
				'htax_redirect':    function() { return $filterContainer.find('#htax_redirect_filter').first(); },
				'htax_icon':        function() { return $filterContainer.find('#htax_icon_filter').first(); },
				'htax_hidden':      function() { return $filterContainer.find('#htax_visibility_filter').first(); },
				'atcf_locations':   function() { return $filterContainer.find('#atcf_has_locations').first(); },
				'tc_ext_links':     function() { return $filterContainer.find('#tc_ext_links_filter').first(); },
				'tc_di_issues':     function() { return $filterContainer.find('#tc_di_issues_filter').first(); }
			};
			$('.wp-list-table thead th').each(function() {
				var m = this.className.match(/\bcolumn-(\S+)/);
				if (!m || !columnFilterMap[m[1]]) { return; }
				var $el = columnFilterMap[m[1]]();
				if ($el.length) { $filterContainer.append($el); }
			});
		}
	}

	// Helper: absorb an ATCF filter container into the shared HT filter row and
	// re-position immediately so the container max-width accounts for the new element.
	function absorbAtcfFilter($atcfContainer) {
		if (!$atcfContainer || !$atcfContainer.length || !$filterContainer) {
			return;
		}
		$filterContainer.append($atcfContainer.children().detach());
		$atcfContainer.remove();
		// Re-run positioning now that the filter row has one more element.
		positionFilters();
	}

	// Absorb any ATCF location filter into the shared HT filter row.
	// Uses setTimeout(0) so all $(function(){}) blocks have run regardless of
	// script load order (handles both ATCF-first and HT-first scenarios).
	setTimeout(function() {
		absorbAtcfFilter($searchForm.find('.atcf-term-filters'));
	}, 0);

	// Also handle the case where ATCF fires its event after HT is already set up.
	$(document).on('atcf:termFilterInserted', function(e, $container) {
		absorbAtcfFilter($container);
	});

	// Initial positioning
	setTimeout(positionFilters, 100);

	// Reposition on window resize
	$(window).on('resize', function() {
		positionFilters();
	});

	// Check filter relevance based on visible table data
	function checkFilterRelevance() {
		var $tableRows = $('.wp-list-table tbody tr:not(.no-items)');

		// If no rows, keep all filters visible
		if (!$tableRows.length) {
			return;
		}

		// Check redirect filter relevance
		if ($('#htax_redirect_filter').length) {
			var hasStandard = false;
			var hasRedirects = false;
			var hasAutoRedirects = false;
			var hasTargets = false;

			$tableRows.each(function() {
				var $row = $(this);
				// Check the redirect column for indicators
				var $redirectCol = $row.find('.column-htax_redirect');
				if ($redirectCol.length) {
					var text = $redirectCol.text().trim();
					if (text === '—' || text === '') {
						hasStandard = true;
					} else if (text.indexOf('→') >= 0) {
						hasRedirects = true;
					} else if (text.indexOf('Auto') >= 0) {
						hasAutoRedirects = true;
					}
					// Check if this row is a redirect target
					if ($row.hasClass('htax-redirect-target')) {
						hasTargets = true;
					}
				}
			});

			// Hide filter if only one type exists or none exist
			var typeCount = [hasStandard, hasRedirects, hasAutoRedirects, hasTargets].filter(Boolean).length;
			if (typeCount <= 1) {
				$('#htax_redirect_filter').hide();
			} else {
				$('#htax_redirect_filter').show();
			}
		}

		// Check icon filter relevance
		if ($('#htax_icon_filter').length) {
			var hasIcons = false;
			var hasNoIcons = false;

			$tableRows.each(function() {
				var $iconCol = $(this).find('.column-htax_icon');
				if ($iconCol.length) {
					var text = $iconCol.text().trim();
					if (text === '—' || text === '') {
						hasNoIcons = true;
					} else {
						hasIcons = true;
					}
				}
			});

			// Hide filter if all same state
			if (!hasIcons || !hasNoIcons) {
				$('#htax_icon_filter').hide();
			} else {
				$('#htax_icon_filter').show();
			}
		}

		// Check visibility filter relevance
		if ($('#htax_visibility_filter').length) {
			var hasVisible = false;
			var hasHidden = false;

			$tableRows.each(function() {
				var $hiddenCol = $(this).find('.column-htax_hidden');
				if ($hiddenCol.length) {
					var text = $hiddenCol.text().trim();
					if (text.indexOf('Hidden') >= 0 || text.indexOf('Editors') >= 0) {
						hasHidden = true;
					} else {
						hasVisible = true;
					}
				}
			});

			// Hide filter if all same state
			if (!hasVisible || !hasHidden) {
				$('#htax_visibility_filter').hide();
			} else {
				$('#htax_visibility_filter').show();
			}
		}

		// Term Groups filter always stays visible (needed for bulk assignment)
	}

	// Run on page load
	checkFilterRelevance();

	// Helper function to update URL with filter changes
	function updateFilters(changes) {
		var url = new URL(window.location.href);

		// Update redirect filter
		if (changes.redirect !== undefined) {
			if (changes.redirect) {
				url.searchParams.set('htax_redirect_filter', changes.redirect);
			} else {
				url.searchParams.delete('htax_redirect_filter');
			}
		}

		// Update icon filter
		if (changes.icon !== undefined) {
			if (changes.icon) {
				url.searchParams.set('htax_icon_filter', changes.icon);
			} else {
				url.searchParams.delete('htax_icon_filter');
			}
		}

		// Update visibility filter
		if (changes.visibility !== undefined) {
			if (changes.visibility) {
				url.searchParams.set('htax_visibility_filter', changes.visibility);
			} else {
				url.searchParams.delete('htax_visibility_filter');
			}
		}

		// Update group filter
		if (changes.group !== undefined) {
			if (changes.group) {
				url.searchParams.set('htax_group_filter', changes.group);
			} else {
				url.searchParams.delete('htax_group_filter');
			}
		}

		// Reset to page 1 when filter changes
		url.searchParams.delete('paged');

		// Ensure taxonomy parameter is preserved
		if (!url.searchParams.has('taxonomy')) {
			url.searchParams.set('taxonomy', filterConfig.taxonomy);
		}

		// Navigate to the new URL
		window.location.href = url.toString();
	}

	// Move the Tag Groups (now PublishPress Tag Groups) filter to align with search box
	// vertically and bulk actions horizontally. Wait a bit for it to add their filter
	setTimeout(function() {
		var $filterContainer = $('#filter_container');
		var $searchBox = $('.search-box');
		var $colLeft = $('#col-left');

		if ($filterContainer.length && $searchBox.length) {
			// Insert filter before the search box (they're in the same container/row)
			$filterContainer.insertBefore($searchBox);

			// Calculate left position to align with bulk actions below
			// Use the width of the "Add Tag/Category" section (#col-left) if it exists
			var leftOffset = 0;
			if ($colLeft.length) {
				leftOffset = $colLeft.outerWidth(true) + 20; // Add some spacing
			}

			// Apply styling to position it correctly
			$filterContainer.css({
				'float': 'left',
				'margin-right': '8px',
				'margin-top': '1px',  // Small adjustment to align with search input
				'position': 'relative',
				'left': leftOffset + 'px',
				'display': 'inline-block'
			});

			// After a brief delay, calculate and adjust based on actual search input position
			setTimeout(function() {
				var $searchInput = $searchBox.find('input[type="search"]');
				if ($searchInput.length && $filterContainer.find('select').length) {
					// Get actual positions
					var searchTop = $searchInput.offset().top;
					var filterTop = $filterContainer.offset().top;
					var adjustment = searchTop - filterTop;

					// Apply adjustment if needed
					if (Math.abs(adjustment) > 1) {
						var currentMargin = parseInt($filterContainer.css('margin-top')) || 0;
						$filterContainer.css('margin-top', (currentMargin + adjustment) + 'px');
					}
				}
			}, 50);
		}
	}, 100);

	// Make "Add Tag" and "Add Category" forms narrower (30% instead of default 35-40%)
	// This leaves more room for the term list table
	var $colLeft = $('#col-left');
	var $colRight = $('#col-right');

	if ($colLeft.length && $colRight.length) {
		$colLeft.css('width', '30%');
		$colRight.css('width', '68%');
	}

	// ============================================================================
	// SYNC FILTER VISIBILITY WITH SCREEN OPTIONS
	// ============================================================================

	/**
	 * Hide/show filter dropdowns based on column visibility in Screen Options
	 */
	function syncFilterVisibility() {
		// Map column classes to filter elements
		var columnFilterMap = {
			'column-htax_redirect': '#htax_redirect_filter',
			'column-htax_icon': '#htax_icon_filter',
			'column-htax_hidden': '#htax_visibility_filter',
			'column-htax_term_groups': '.htax-group-filter-wrapper' // Group filter is now a wrapper div
		};

		// Check each column's visibility
		$.each(columnFilterMap, function(columnClass, filterSelector) {
			var $column = $('.wp-list-table thead th.' + columnClass);
			var $filter = $(filterSelector);

			// If filter exists but column doesn't, keep filter visible (table might be empty)
			if ($filter.length) {
				if ($column.length) {
					// Column exists - check if it's hidden
					var isHidden = $column.hasClass('hidden') || $column.css('display') === 'none';

					if (isHidden) {
						$filter.hide();
					} else {
						$filter.show();
					}
				} else {
					// Column doesn't exist (table might be empty) - keep filter visible
					$filter.show();
				}
			}
		});
	}

	// Initial sync on page load
	syncFilterVisibility();

	// Watch for Screen Options changes
	// WordPress uses MutationObserver-compatible changes when toggling columns
	var $tableHead = $('.wp-list-table thead');
	if ($tableHead.length && window.MutationObserver) {
		var observer = new MutationObserver(function(mutations) {
			// Check if any mutation affected column visibility
			var shouldSync = false;
			mutations.forEach(function(mutation) {
				if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
					shouldSync = true;
				}
			});

			if (shouldSync) {
				syncFilterVisibility();
			}
		});

		// Observe all th elements in thead for class changes
		$tableHead.find('th').each(function() {
			observer.observe(this, {
				attributes: true,
				attributeFilter: ['class']
			});
		});
	}

	// Also sync when Screen Options panel is closed (as a backup)
	$(document).on('click', '#screen-options-wrap .button', function() {
		setTimeout(syncFilterVisibility, 100);
	});

	// ============================================================================
	// QUICK EDIT SUPPORT
	// ============================================================================

	// Build group checklist for Quick Edit from the existing groupFilter config.
	// The checklist container is rendered by htax_quick_edit_fields() in PHP.
	if (filterConfig.groupFilter && $('#htax-qe-group-list').length) {
		var qeGroupHtml  = '';
		var qeLockedIds  = (filterConfig.groupFilter.lockedGroupIds || []).map(String);
		var qeLockedTitle = filterConfig.groupFilter.lockedTitle || '';
		for (var qi = 0; qi < filterConfig.groupFilter.options.length; qi++) {
			var qopt = filterConfig.groupFilter.options[qi];
			if (qopt.value === '-1') continue; // Skip "Ungrouped" pseudo-option.
			var qindent = qopt.depth * 16;
			var qLocked = qeLockedIds.indexOf(String(qopt.value)) >= 0;
			qeGroupHtml +=
				'<label style="display:block;padding:2px 0 2px ' + qindent + 'px;"' +
					(qLocked ? ' class="htax-checklist-item-locked" title="' + htaxEscHtml(qeLockedTitle) + '"' : '') + '>' +
					'<input type="checkbox" name="htax_groups[]" value="' + qopt.value + '"' + (qLocked ? ' disabled="disabled"' : '') + '/> ' +
					htaxEscHtml(qopt.label) +
				'</label>';
		}
		$('#htax-qe-group-list').html(qeGroupHtml || '<em style="color:#999;">No groups defined.</em>');
	}

	// Populate Quick Edit fields with the current term's values when a row opens.
	// WordPress reuses a single Quick Edit row for all terms; we restore per-term
	// state from hidden data spans written into each column cell by PHP.
	if (typeof inlineEditTax !== 'undefined' && typeof inlineEditTax.edit === 'function') {
		var _htaxOrigTaxEdit = inlineEditTax.edit;
		inlineEditTax.edit = function(id) {
			// Let WordPress populate its own fields first.
			_htaxOrigTaxEdit.call(this, id);

			var termId = (typeof id === 'object') ? parseInt(this.getId(id), 10) : parseInt(id, 10);
			if (!termId) return;

			var $row = $('#tag-' + termId);
			if (!$row.length) return;

			// Populate visibility checkbox from the data span in the visibility column.
			var $visData = $row.find('.htax-qe-vis-data');
			if ($visData.length) {
				var $cb          = $('#htax_qe_is_hidden');
				var parentHidden = !!parseInt($visData.data('parentHidden') || 0, 10);
				$cb.prop('checked', !!$visData.data('hidden'));
				if (parentHidden) {
					$cb.prop('disabled', true);
					$cb.closest('label').attr('title', 'A parent term is hidden — make the parent visible first.');
				} else {
					$cb.prop('disabled', false);
					$cb.closest('label').removeAttr('title');
				}
			}

			// Populate group checkboxes from the data span in the groups column.
			var $grpData = $row.find('.htax-qe-grp-data');
			if ($grpData.length) {
				var rawIds = $grpData.data('group-ids');
				// jQuery may parse a single numeric ID as an integer; toString() normalizes it.
				var groupIds = rawIds ? rawIds.toString().split(',').filter(Boolean) : [];
				$('#htax-qe-group-list input[type="checkbox"]').each(function() {
					$(this).prop('checked', groupIds.indexOf($(this).val()) >= 0);
				});
			}
		};
	}

	// ============================================================================
	// BULK GROUP ASSIGNMENT MODAL
	// ============================================================================

	/**
	 * Handle "Assign to Term Groups..." bulk action
	 * Intercept form submission and show modal instead
	 */
	// Show a clearer message when Apply is clicked with no action selected but items are checked.
	// WP core's handler returns (not return false) for action='-1', so ours fires next.
	$('#doaction, #doaction2').on('click', function() {
		var n   = this.id === 'doaction' ? '' : '2';
		var act = $('select[name="action' + n + '"]').val();
		var alt = $('select[name="action' + ( n ? '' : '2' ) + '"]').val();
		if ( act === '-1' && alt === '-1' && $('input[name="delete_tags[]"]:checked').length ) {
			alert( 'Please select a bulk action from the dropdown.' );
			return false;
		}
	} );

	$('#posts-filter, #edittag').on('submit', function(e) {
		var $form = $(this);
		var action = $form.find('select[name="action"]').val();
		var action2 = $form.find('select[name="action2"]').val();

		// Check if "Assign to Term Groups..." is selected
		if (action === 'htax_assign_to_groups' || action2 === 'htax_assign_to_groups') {
			e.preventDefault();

			// Get selected term IDs
			var selectedTerms = [];
			$form.find('input[name="delete_tags[]"]:checked').each(function() {
				selectedTerms.push($(this).val());
			});

			if (selectedTerms.length === 0) {
				alert('Please select at least one term.');
				return false;
			}

			// Show modal
			showBulkGroupAssignmentModal(selectedTerms);
			return false;
		}
	});

	/**
	 * Read the group IDs a term is currently in from the row's DOM data attribute.
	 * Returns an array of string IDs.
	 */
	function htaxGetTermGroupIds(termId) {
		var $grpData = $('#tag-' + termId).find('.htax-qe-grp-data');
		if (!$grpData.length) return [];
		var raw = $grpData.data('group-ids');
		if (raw === undefined || raw === '') return [];
		return raw.toString().split(',').filter(Boolean);
	}

	/**
	 * Apply a state ('checked', 'unchecked', 'indeterminate') to a checkbox.
	 */
	function htaxSetCbState($cb, state) {
		$cb.data('htax-state', state);
		if (state === 'checked') {
			$cb.prop({ checked: true, indeterminate: false });
		} else if (state === 'indeterminate') {
			$cb.prop({ checked: false, indeterminate: true });
		} else {
			$cb.prop({ checked: false, indeterminate: false });
		}
	}

	/**
	 * Cycle a checkbox through its three-state sequence:
	 *   unchecked      → checked → unchecked   (groups no term has)
	 *   checked        → unchecked → checked    (groups all terms have)
	 *   indeterminate  → checked → unchecked → indeterminate  (mixed)
	 */
	function htaxCycleCbState($cb) {
		var state   = $cb.data('htax-state');
		var initial = $cb.data('htax-initial');
		var next;
		if (state === 'indeterminate') {
			next = 'checked';
		} else if (state === 'checked') {
			next = 'unchecked';
		} else {
			next = (initial === 'indeterminate') ? 'indeterminate' : 'checked';
		}
		htaxSetCbState($cb, next);
	}

	/**
	 * Enable Assign only when at least one checkbox differs from its initial state.
	 */
	function htaxUpdateAssignBtn() {
		var hasChange = false;
		$('#htax-modal-group-list input[type="checkbox"]').each(function() {
			if ($(this).data('htax-state') !== $(this).data('htax-initial')) {
				hasChange = true;
				return false;
			}
		});
		$('.htax-modal-assign').prop('disabled', !hasChange);
	}

	/**
	 * Show the bulk group assignment modal
	 */
	function showBulkGroupAssignmentModal(termIds) {
		// Create modal HTML once
		if ($('#htax-bulk-assign-modal').length === 0) {
			var modalHtml =
				'<div id="htax-bulk-assign-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; overflow: auto;">' +
					'<div class="htax-modal-content" style="position: relative; background: #fff; margin: 50px auto; max-width: 500px; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.5);">' +
						'<div class="htax-modal-header" style="padding: 16px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">' +
							'<h2 style="margin: 0; font-size: 18px;">Assign to Term Groups</h2>' +
							'<button type="button" class="htax-modal-close" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666; padding: 0; width: 30px; height: 30px;">&times;</button>' +
						'</div>' +
						'<div class="htax-modal-body" style="padding: 20px; max-height: 400px; overflow-y: auto;">' +
							'<p style="margin-top: 0;">Select groups for <strong><span id="htax-selected-count">0</span> terms</strong>:</p>' +
							'<div class="htax-group-search-wrapper" style="margin-bottom: 12px;">' +
								'<input type="search" id="htax-modal-group-search" placeholder="Search groups..." style="width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box; font-size: 13px;" />' +
							'</div>' +
							'<div id="htax-modal-group-list" style="border: 1px solid #ddd; padding: 12px; border-radius: 3px; background: #fafafa; max-height: 300px; overflow-y: auto;"></div>' +
						'</div>' +
						'<div class="htax-modal-footer" style="padding: 12px 20px; border-top: 1px solid #ddd; text-align: right; background: #f5f5f5;">' +
							'<button type="button" class="button button-secondary htax-modal-cancel" style="margin-right: 8px;">Cancel</button>' +
							'<button type="button" class="button button-primary htax-modal-assign">Apply</button>' +
						'</div>' +
					'</div>' +
				'</div>';

			$('body').append(modalHtml);

			// Build group checklist from config
			if (filterConfig.groupFilter && filterConfig.groupFilter.options) {
				var groupListHtml = '';
				for (var i = 0; i < filterConfig.groupFilter.options.length; i++) {
					var opt = filterConfig.groupFilter.options[i];
					var indent = opt.depth * 20;

					if (opt.value === '' || opt.value === '-1') {
						continue;
					}

					groupListHtml +=
						'<div class="htax-modal-group-option" data-depth="' + opt.depth + '" data-value="' + opt.value + '" data-label="' + htaxEscHtml(opt.label.toLowerCase()) + '" style="padding: 4px 4px 4px ' + indent + 'px;">' +
							'<label style="display: block; cursor: pointer;">' +
								'<input type="checkbox" value="' + opt.value + '" /> ' +
								htaxEscHtml(opt.label) +
							'</label>' +
						'</div>';
				}
				$('#htax-modal-group-list').html(groupListHtml);
			}

			$('#htax-bulk-assign-modal').on('click', function(e) {
				if ($(e.target).is('#htax-bulk-assign-modal')) {
					closeBulkGroupAssignmentModal();
				}
			});

			$('.htax-modal-close, .htax-modal-cancel').on('click', function() {
				closeBulkGroupAssignmentModal();
			});

			$('#htax-modal-group-search').on('input search', function() {
				var searchText = $(this).val().toLowerCase();
				$('.htax-modal-group-option').each(function() {
					$(this).toggle(searchText === '' || $(this).attr('data-label').indexOf(searchText) >= 0);
				});
			});

			$('.htax-modal-assign').on('click', function() {
				performBulkGroupAssignment();
			});

			// Three-state cycling — let the browser toggle, then apply our state machine
			$('#htax-modal-group-list').on('click', 'input[type="checkbox"]', function() {
				htaxCycleCbState($(this));
				htaxUpdateAssignBtn();
			});
		}

		// Compute per-group membership counts across selected terms
		var memberCount = {};
		for (var t = 0; t < termIds.length; t++) {
			var gids = htaxGetTermGroupIds(termIds[t]);
			for (var g = 0; g < gids.length; g++) {
				memberCount[gids[g]] = (memberCount[gids[g]] || 0) + 1;
			}
		}

		// Set initial checkbox state for each group
		$('#htax-modal-group-list input[type="checkbox"]').each(function() {
			var gid   = $(this).val();
			var count = memberCount[gid] || 0;
			var state = count === 0 ? 'unchecked'
			          : count === termIds.length ? 'checked'
			          : 'indeterminate';
			htaxSetCbState($(this), state);
			$(this).data('htax-initial', state);
		});

		$('#htax-selected-count').text(termIds.length);
		$('#htax-bulk-assign-modal').data('term-ids', termIds);
		$('#htax-modal-group-search').val('');
		$('.htax-modal-group-option').show();
		htaxUpdateAssignBtn();
		$('#htax-bulk-assign-modal').fadeIn(200);
	}

	/**
	 * Close the modal
	 */
	function closeBulkGroupAssignmentModal() {
		$('#htax-bulk-assign-modal').fadeOut(200);
	}

	// ============================================================================
	// MAKE VISIBLE CASCADE PROMPT
	// ============================================================================

	if (filterConfig.isHierarchical && filterConfig.visibilityNonce) {

		// Intercept "Make Visible" bulk action before the form submits.
		$('#posts-filter').on('submit.htaxmv', function(e) {
			var $form   = $(this);
			var action  = $form.find('select[name="action"]').val();
			var action2 = $form.find('select[name="action2"]').val();

			if (action !== 'htax_make_visible' && action2 !== 'htax_make_visible') {
				return;
			}

			// Guard: let re-submitted form through after user has chosen.
			if ($form.data('htax-mv-ready')) {
				$form.removeData('htax-mv-ready');
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			var termIds = [];
			$form.find('input[name="delete_tags[]"]:checked').each(function() {
				termIds.push(parseInt($(this).val(), 10));
			});

			if (!termIds.length) {
				return;
			}

			$.post(ajaxurl, {
				action:   'htax_count_hidden_descendants',
				nonce:    filterConfig.visibilityNonce,
				term_ids: termIds,
				taxonomy: filterConfig.taxonomy
			})
			.done(function(response) {
				var totalHidden = 0;
				if (response.success) {
					$.each(response.data.counts, function(_, cnt) { totalHidden += cnt; });
				}

				if (totalHidden > 0) {
					showMakeVisibleModal(totalHidden, function(cascade) {
						if (cascade) {
							$form.append('<input type="hidden" name="htax_cascade_visible" value="1">');
						}
						$form.data('htax-mv-ready', true).submit();
					});
				} else {
					$form.data('htax-mv-ready', true).submit();
				}
			})
			.fail(function() {
				$form.data('htax-mv-ready', true).submit();
			});
		});

		// Intercept Quick Edit save when unchecking "is hidden".
		if (typeof inlineEditTax !== 'undefined' && typeof inlineEditTax.save === 'function') {
			var _htaxOrigTaxSave = inlineEditTax.save;

			inlineEditTax.save = function(id) {
				var self   = this;
				var termId = (typeof id === 'object') ? parseInt(this.getId(id), 10) : parseInt(id, 10);

				if (!termId) {
					return _htaxOrigTaxSave.call(this, id);
				}

				var $row     = $('#tag-' + termId);
				var $visData = $row.find('.htax-qe-vis-data');

				if (!$visData.length) {
					return _htaxOrigTaxSave.call(this, id);
				}

				var $hiddenCb   = $('#htax_qe_is_hidden');
				var wasHidden   = !!$visData.data('hidden');
				var isNowHidden = !!$hiddenCb.prop('checked');

				// If the checkbox is disabled the parent is hidden — pass through;
				// the server will silently reject any visibility change.
				if ($hiddenCb.prop('disabled')) {
					return _htaxOrigTaxSave.call(this, id);
				}

				// Only intercept a hidden → visible transition.
				if (!wasHidden || isNowHidden) {
					return _htaxOrigTaxSave.call(this, id);
				}

				// Guard: cascade field already injected means we already asked.
				var $qeRow = $('#edit-' + termId);
				if ($qeRow.find('input[name="htax_cascade_visible"]').length) {
					return _htaxOrigTaxSave.call(this, id);
				}

				$.post(ajaxurl, {
					action:   'htax_count_hidden_descendants',
					nonce:    filterConfig.visibilityNonce,
					term_ids: [termId],
					taxonomy: filterConfig.taxonomy
				})
				.done(function(response) {
					var count = 0;
					if (response.success && response.data.counts) {
						count = response.data.counts[termId] || 0;
					}

					if (count > 0) {
						showMakeVisibleModal(count, function(cascade) {
							if (cascade) {
								$qeRow.find('input[name="htax_cascade_visible"]').remove();
								$qeRow.append('<input type="hidden" name="htax_cascade_visible" value="1">');
							}
							_htaxOrigTaxSave.call(self, id);
						});
					} else {
						_htaxOrigTaxSave.call(self, id);
					}
				})
				.fail(function() {
					_htaxOrigTaxSave.call(self, id);
				});
			};
		}
	}

	/**
	 * Show the make-visible cascade confirmation modal.
	 *
	 * @param {number}   count    Number of hidden descendants that would be affected.
	 * @param {Function} callback Called with true (cascade) or false (selected terms only).
	 */
	function showMakeVisibleModal(count, callback) {
		if ($('#htax-make-visible-modal').length === 0) {
			var modalHtml =
				'<div id="htax-make-visible-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100001;overflow:auto;">' +
					'<div style="position:relative;background:#fff;margin:100px auto;max-width:520px;border-radius:4px;box-shadow:0 5px 15px rgba(0,0,0,0.5);">' +
						'<div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">' +
							'<h2 style="margin:0;font-size:18px;">Make Visible</h2>' +
							'<button type="button" class="htax-mv-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0;width:30px;height:30px;">&times;</button>' +
						'</div>' +
						'<div style="padding:20px;">' +
							'<p style="margin-top:0;" id="htax-mv-body"></p>' +
						'</div>' +
						'<div style="padding:12px 20px;border-top:1px solid #ddd;text-align:right;background:#f5f5f5;">' +
							'<button type="button" class="button button-secondary htax-mv-no" style="margin-right:8px;">No, selected terms only</button>' +
							'<button type="button" class="button button-primary htax-mv-yes">Yes, also make descendants visible</button>' +
						'</div>' +
					'</div>' +
				'</div>';
			$('body').append(modalHtml);
		}

		var descText = count === 1
			? '1 descendant term is also hidden. Make it visible too?'
			: count + ' descendant terms are also hidden. Make them visible too?';
		$('#htax-mv-body').text(descText);

		$('#htax-make-visible-modal .htax-mv-close, #htax-make-visible-modal .htax-mv-no')
			.off('click.htaxmv')
			.on('click.htaxmv', function() {
				$('#htax-make-visible-modal').fadeOut(150);
				callback(false);
			});

		$('#htax-make-visible-modal .htax-mv-yes')
			.off('click.htaxmv')
			.on('click.htaxmv', function() {
				$('#htax-make-visible-modal').fadeOut(150);
				callback(true);
			});

		$('#htax-make-visible-modal').fadeIn(200);
	}

	/**
	 * Perform the bulk group assignment via AJAX.
	 * Sends add_groups (newly checked) and remove_groups (newly unchecked).
	 */
	function performBulkGroupAssignment() {
		var termIds      = $('#htax-bulk-assign-modal').data('term-ids');
		var addGroups    = [];
		var removeGroups = [];

		$('#htax-modal-group-list input[type="checkbox"]').each(function() {
			var state   = $(this).data('htax-state');
			var initial = $(this).data('htax-initial');
			if (state === initial) return;
			if (state === 'checked') {
				addGroups.push($(this).val());
			} else if (state === 'unchecked') {
				removeGroups.push($(this).val());
			}
		});

		if (!addGroups.length && !removeGroups.length) {
			return;
		}

		$('.htax-modal-assign').prop('disabled', true).text('Applying...');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action:        'htax_bulk_assign_groups',
				nonce:         htaxTablesConfig.bulkAssignNonce,
				term_ids:      termIds,
				add_groups:    addGroups,
				remove_groups: removeGroups,
				taxonomy:      htaxTablesConfig.taxonomy
			},
			success: function(response) {
				if (response.success) {
					closeBulkGroupAssignmentModal();
					var url = new URL(window.location.href);
					url.searchParams.set('htax_bulk_action', 'htax_assign_to_groups');
					url.searchParams.set('htax_processed', response.data.processed);
					window.location.href = url.toString();
				} else {
					alert('Error: ' + (response.data.message || 'Unknown error'));
					$('.htax-modal-assign').prop('disabled', false).text('Apply');
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
				$('.htax-modal-assign').prop('disabled', false).text('Apply');
			}
		});
	}

	// Add New Term form: move native Description into our collapsible and update its helper text.
	var $descWrap        = $('.term-description-wrap');
	var $collapsible     = $('.htax-add-form-collapsible');
	var $collapsibleSect = $('.htax-add-form-collapsible-section');
	if ( $descWrap.length && $collapsible.length ) {
		$collapsible.prepend( $descWrap );
		var $helper = $descWrap.find('#description-description');
		if ( $helper.length ) {
			$helper.text(
				'The term description can be used for autocomplete suggestions and PowerSearch relevance matching, supplementing term names.'
			);
		}
	}

	// Pair Tag Groups' own native field with the Description it sits next to — or,
	// when Tag Groups is the admin's active primary tool (Locked mode, Tag Groups
	// active — see data-promote-native-groups), promote it to the always-visible
	// spot that Term Groups (now demoted into the collapsible) normally occupies.
	var $tagGroupsNativeField = $('#term-group').closest('.form-field');
	if ( $tagGroupsNativeField.length ) {
		if ( $collapsibleSect.data('promote-native-groups') == 1 ) {
			$collapsibleSect.before( $tagGroupsNativeField );
		} else if ( $descWrap.length ) {
			$descWrap.after( $tagGroupsNativeField );
		}
	}

	// Add New Term form — collapsible "More options" section
	$(document).on('click', '.htax-add-form-toggle', function() {
		var $btn        = $(this);
		var $collapsible = $btn.siblings('.htax-add-form-collapsible');
		var expanded    = $btn.attr('aria-expanded') === 'true';

		if (expanded) {
			$collapsible.attr('hidden', '');
			$btn.attr('aria-expanded', 'false');
			$btn.find('.htax-add-form-toggle-label').text('More options');
			$btn.find('.htax-add-form-toggle-icon')
				.removeClass('dashicons-arrow-up-alt2')
				.addClass('dashicons-arrow-down-alt2');
		} else {
			$collapsible.removeAttr('hidden');
			$btn.attr('aria-expanded', 'true');
			$btn.find('.htax-add-form-toggle-label').text('Fewer options');
			$btn.find('.htax-add-form-toggle-icon')
				.removeClass('dashicons-arrow-down-alt2')
				.addClass('dashicons-arrow-up-alt2');
		}
	});
});

