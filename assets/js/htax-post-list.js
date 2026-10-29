/**
 * HyperTaxonomy Post List AJAX Pagination
 *
 * Handles prev/next page navigation for [ht_post_list] and [ht_related_posts]
 * shortcodes. Pages load via AJAX and replace the posts and nav within the output
 * block — no URL change or full page reload occurs.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    if (typeof HTAX_PostList === 'undefined') {
        return;
    }

    /**
     * Load a page of posts into the given wrapper element.
     *
     * Reads all query parameters from the wrapper's data attributes (written by
     * the PHP shortcode renderer), posts them to admin-ajax.php, and replaces the
     * inner .htax-post-list content and .htax-post-list-nav on success.
     *
     * @param {jQuery} $wrapper  The .htax-post-list-wrapper element.
     * @param {number} page      1-based page number to fetch.
     */
    function loadPage($wrapper, page) {
        $wrapper.addClass('htax-loading');

        var filterState = $wrapper.data('htax-filter') || {};

        var data = {
            action:         'htax_post_list_page',
            nonce:          HTAX_PostList.nonce,
            page:           page,
            term_ids:       $wrapper.data('term-ids')       || '',
            exclude_ids:    $wrapper.data('exclude-ids')    || '',
            source_post_id: $wrapper.data('source-post-id') || 0,
            taxonomy:       $wrapper.data('taxonomy')       || 'post_tag',
            posts_per_page: $wrapper.data('posts-per-page') || 10,
            max_results:    $wrapper.data('max-results')    || 0,
            post_type:      $wrapper.data('post-type')      || 'post',
            orderby:        $wrapper.data('orderby')        || 'date:desc',
            filter_terms:   $wrapper.data('filter-terms')   || '',
            filter_groups:  $wrapper.data('filter-groups')  || '',
            show_thumbnail: $wrapper.data('show-thumbnail') || 'true',
            show_excerpt:   $wrapper.data('show-excerpt')   || 'true',
            meta:           $wrapper.data('meta')           !== undefined ? $wrapper.data('meta') : '{date} by {author}',
            template:       $wrapper.data('template')       || 'default',
            format:         $wrapper.data('format')         || 'ul',
            class:          $wrapper.data('class')          || '',
            image_size:     $wrapper.data('image-size')     || 'medium',
            columns:        $wrapper.data('columns')        || 1,
            htax_filter:    Object.keys(filterState).length ? JSON.stringify(filterState) : ''
        };

        $.post(HTAX_PostList.ajax_url, data, function (response) {
            $wrapper.removeClass('htax-loading');

            if (!response || !response.success) {
                return;
            }

            // Replace post articles inside the .htax-post-list element
            if (response.data.posts) {
                $wrapper.find('.htax-post-list').html(response.data.posts);
            } else {
                var noResultsMsg = $wrapper.data('no-results-message') || '';
                $wrapper.find('.htax-post-list').html(
                    noResultsMsg ? '<div class="htax-no-results">' + noResultsMsg + '</div>' : ''
                );
            }

            // Replace pagination nav: update if it exists, append if missing,
            // remove if the new response has no nav (single page of results).
            var $nav = $wrapper.find('.htax-post-list-nav');
            if (response.data.nav) {
                if ($nav.length) {
                    $nav.replaceWith(response.data.nav);
                } else {
                    $wrapper.append(response.data.nav);
                }
            } else {
                $nav.remove();
            }

            // Scroll to the top of the wrapper so the new page is immediately visible
            $('html, body').animate({ scrollTop: $wrapper.offset().top - 32 }, 300);
        });
    }

    // Listen for load-page requests from the filter options panel
    // (fired as a custom event by htax-filter-options.js).
    $(document).on('htax:load-page', '.htax-post-list-wrapper', function (_e, page) {
        loadPage($(this), page || 1);
    });

    // Listen for sort order changes emitted by an adjacent sort picker
    // ([data-htax-sort-root] with data-htax-sort-action omitted).
    // Finds the nearest .htax-post-list-wrapper, updates its orderby, and
    // reloads page 1 so the new order takes effect immediately.
    $(document).on('htax:sort-change', '[data-htax-sort-root]', function(_e, newValue) {
        var $sortRoot = $(this);
        var $wrapper  = $sortRoot.siblings('.htax-post-list-wrapper');
        if (!$wrapper.length) {
            $wrapper = $sortRoot.parent().find('.htax-post-list-wrapper').first();
        }
        if (!$wrapper.length) {
            // Picker is inside .htax-controls-bar; wrapper is a sibling of the bar.
            $wrapper = $sortRoot.closest('.htax-post-list-block').find('.htax-post-list-wrapper').first();
        }
        if ($wrapper.length) {
            $wrapper.data('orderby', newValue || 'date:desc');
            loadPage($wrapper, 1);
        }
    });

    // Delegate click handling to the document so it works for any number of
    // shortcode instances on the same page.
    $(document).on(
        'click',
        '.htax-post-list-wrapper .htax-post-list-prev, .htax-post-list-wrapper .htax-post-list-next',
        function (e) {
            e.preventDefault();
            var $btn     = $(this);
            var page     = parseInt($btn.data('page'), 10);
            var $wrapper = $btn.closest('.htax-post-list-wrapper');
            if (page && $wrapper.length) {
                loadPage($wrapper, page);
            }
        }
    );

})(jQuery);
