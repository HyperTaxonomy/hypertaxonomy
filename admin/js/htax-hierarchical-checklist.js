/**
 * Hierarchical Checklist Component - JavaScript
 *
 * Handles search/filter functionality and tab switching for hierarchical term checklists.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

(function($, window) {
	'use strict';

	var HtaxHierarchicalChecklist = {
		init: function() {
			this.initTabs();
			this.initSearch();
			this.initAccessibility();
		},

		initTabs: function() {
			$('.htax-hierarchical-checklist-wrapper').each(function() {
				var $wrapper = $(this);
				var $tabs = $wrapper.find('.htax-checklist-tabs');

				if ($tabs.length === 0) {
					return;
				}

				var $tab  = $tabs.find('li').first();
				var $link = $tab.find('a');
				if (!$link.hasClass('htax-has-toggle')) {
					var linkText = $link.text();
					$link.addClass('htax-has-toggle').html('<span class="htax-toggle-arrow-tabs"></span>' + linkText);
				}

				$link.on('click', function(e) {
					e.preventDefault();
					var targetPanel = $(this).attr('href');
					$tab.toggleClass('htax-collapsed');
					$wrapper.find(targetPanel).slideToggle(200);
					return false;
				});
			});
		},

		initSearch: function() {
			var searchTimer;

			$(document).on('input', '.htax-checklist-search', function() {
				var $input      = $(this);
				var searchTerm  = $input.val().toLowerCase();
				var $checklist  = $input.siblings('.htax-checklist-container').find('.htax-checklist');

				if ($checklist.length === 0) {
					$checklist = $input.siblings('.htax-checklist');
				}

				var $items = $checklist.find('li');

				clearTimeout(searchTimer);

				if (!searchTerm) {
					$items.show();
					return;
				}

				searchTimer = setTimeout(function() {
					HtaxHierarchicalChecklist.filterItems($items, searchTerm);
				}, 200);
			});
		},

		filterItems: function($items, searchTerm) {
			$items.each(function() {
				var $item    = $(this);
				var itemText = $item.children('label').first().text().toLowerCase();

				if (itemText.indexOf(searchTerm) !== -1) {
					$item.show();
					$item.parents('li').show();
					$item.find('li').show();
				} else {
					var hasMatchingChild = false;
					$item.find('label').each(function() {
						if ($(this).text().toLowerCase().indexOf(searchTerm) !== -1) {
							hasMatchingChild = true;
							return false;
						}
					});
					$item.toggle(hasMatchingChild);
				}
			});
		},

		initAccessibility: function() {
			$(document).on('keydown', '.htax-checklist input[type="checkbox"]', function(e) {
				var $parent = $(this).closest('li');
				var $next, $prev;

				if (e.which === 40) { // Down
					e.preventDefault();
					$next = $parent.next('li').find('input[type="checkbox"]').first();
					if (!$next.length) { $next = $parent.find('li input[type="checkbox"]').first(); }
					if ($next.length) { $next.trigger('focus'); }
				} else if (e.which === 38) { // Up
					e.preventDefault();
					$prev = $parent.prev('li').find('input[type="checkbox"]').last();
					if (!$prev.length) { $prev = $parent.parent().closest('li').find('> label input[type="checkbox"]'); }
					if ($prev.length) { $prev.trigger('focus'); }
				}
			});

			$(document).on('focus', '.htax-checklist input[type="checkbox"]', function() {
				$(this).closest('label').addClass('htax-focused');
			});

			$(document).on('blur', '.htax-checklist input[type="checkbox"]', function() {
				$(this).closest('label').removeClass('htax-focused');
			});
		}
	};

	$(document).ready(function() {
		HtaxHierarchicalChecklist.init();
	});

	window.HtaxHierarchicalChecklist = HtaxHierarchicalChecklist;

})(jQuery, window);
