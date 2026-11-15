/**
 * HyperTaxonomy Standalone PowerSearch Widget JavaScript
 * @package HyperTaxonomy
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Check for required global objects
    if (typeof HTAX_PowerSearch === 'undefined') {
        console.error('HTAX_PowerSearch object not loaded. PowerSearch widget cannot initialize.');
        return;
    }

    /**
     * Initialize all PowerSearch forms on the page
     */
    $(document).ready(function() {
        $('.htax-power-search-form').each(function() {
            const instance = new PowerSearchForm($(this));
            $(this).data('htax-instance', instance);
        });
    });

    /**
     * PowerSearchForm class
     */
    class PowerSearchForm {
        constructor($container) {
            this.$container = $container;
            this.$form = $container.find('.htax-power-search-input-wrapper');
            this.$input = $container.find('.htax-power-search-input');

            // In AJAX mode the form must specify a results container ID via ajax="id".
            // POST mode needs no results container — htax_handle_power_post_search handles it.
            const resultsId = this.$form.data('results');
            this.$results = resultsId ? $('#' + resultsId) : $();

            this.$resultsContainer = this.$results.find('.htax-results-container');
            this.$loading = this.$results.find('.htax-loading');
            this.currentPage = 1;

            // Seed results container with the form's configured orderby so performSearch()
            // can read it from one place regardless of how the sort is later changed.
            if (this.$results.length && !this.$results.data('orderby')) {
                this.$results.data('orderby', this.$form.data('orderby') || 'relevance:desc,date:desc');
            }

            // Register this instance on the container so the unified sort-change and
            // pagination handlers in htax-search-widget.js can find it.
            if (this.$results.length) {
                this.$results.data('htax-form-instance', this);
            }

            this.init();
        }

        init() {
            // Check if AJAX mode is enabled
            const isAjax = !!this.$form.data('ajax');

            // POST mode: a near:me query asks the browser for its location first and sends
            // it with the form; everything else is handled by the server.
            if (!isAjax) {
                this.$form.on('submit', (e) => {
                    if (this._geoAttached || !window.HTAX_Geo || !HTAX_Geo.wantsLocation(this.$input.val())) {
                        return;
                    }
                    e.preventDefault();
                    HTAX_Geo.get().then((location) => {
                        if (location) {
                            this.$form.append($('<input type="hidden" name="_atcf_lat">').val(location.lat));
                            this.$form.append($('<input type="hidden" name="_atcf_lng">').val(location.lng));
                        }
                        this._geoAttached = true;
                        this.$form[0].submit();
                    });
                });
                return;
            }

            // Handle form submission (AJAX mode only)
            this.$form.on('submit', (e) => {
                e.preventDefault();
                // Enter supersedes a pending live search for the same text.
                clearTimeout(this._debounceTimer);
                this.performSearch(1);
            });

            // Search on input change with debounce (AJAX mode only)
            this.$input.on('input', () => {
                clearTimeout(this._debounceTimer);
                this._debounceTimer = setTimeout(() => {
                    const query = this.$input.val().trim();
                    const minLength = parseInt(this.$input.data('min-length')) || 3;

                    if (query.length >= minLength) {
                        this.performSearch(1);
                    } else if (query.length === 0) {
                        // Clear results when input is empty
                        this.$resultsContainer.empty();
                        this._shownKey = null;
                    }
                }, 500); // 500ms debounce
            });
        }

        performSearch(page = 1) {
            const query = this.$input.val().trim();
            const minLength = parseInt(this.$input.data('min-length')) || 3;

            // Validate minimum length
            if (query.length < minLength) {
                this.$resultsContainer.html(
                    '<div class="htax-message">' +
                    HTAX_PowerSearch.i18n.min_length.replace('%d', minLength) +
                    '</div>'
                );
                return;
            }

            // Perform AJAX request
            const filterState = this.$results.data('htax-filter') || {};

            // A search identical to the one in flight, or to the one whose results are shown
            // (e.g. Enter right after the live search ran), is not sent again; of overlapping
            // searches (typing), only the latest one's response is rendered.
            const searchKey = JSON.stringify([query, page, this.$results.data('orderby') || '', filterState]);
            if (this._inFlightKey === searchKey || this._shownKey === searchKey) {
                return;
            }
            this._inFlightKey = searchKey;
            const seq = this._searchSeq = (this._searchSeq || 0) + 1;

            this._shownKey = null;

            // Show loading
            this.$loading.show();
            this.$resultsContainer.empty();

            // Search settings from the shortcode/block, rendered on the input. Score
            // thresholds are only rendered when set, so absent ones keep server defaults.
            const settings = {
                match: this.$input.data('match') || '',
                near_country: this.$input.data('near-country') || 'US'
            };
            ['min-score', 'min-title-score', 'min-content-score', 'min-excerpt-score',
             'min-tags-score', 'min-categories-score', 'min-terms-score'].forEach((key) => {
                const value = this.$input.data(key);
                if (value !== undefined && value !== '') {
                    settings[key.replace(/-/g, '_')] = value;
                }
            });
            const location = window.HTAX_Geo && HTAX_Geo.wantsLocation(query) ? HTAX_Geo.get() : Promise.resolve(null);
            location.then((loc) => {
                if (loc) {
                    settings._atcf_lat = loc.lat;
                    settings._atcf_lng = loc.lng;
                }
                $.post(HTAX_PowerSearch.ajax_url, {
                    ...settings,
                    action: 'htax_power_search_posts',
                    nonce: this.$form.find('#htax_power_search_nonce').val(),
                    query: query,
                    page: page,
                    posts_per_page: this.$form.data('posts-per-page') || 20,
                    max_results: this.$form.data('max-results') || 0,
                    orderby: this.$results.data('orderby'),
                    search_in: this.$input.data('search-in'),
                    search_mode: this.$input.data('search-mode'),
                    min_length: minLength,
                    image_size: this.$results.data('image-size') || 'medium',
                    htax_filter: Object.keys(filterState).length ? JSON.stringify(filterState) : ''
                }, (response) => {
                    if (seq !== this._searchSeq) {
                        return; // A newer search has started.
                    }
                    this.$loading.hide();

                    if (response.success) {
                        this.renderResults(response.data);
                        this._shownKey = searchKey;
                    } else {
                        this.$resultsContainer.html(
                            '<div class="htax-error">' + HTAX_PowerSearch.i18n.error + '</div>'
                        );
                    }
                }).fail(() => {
                    if (seq !== this._searchSeq) {
                        return;
                    }
                    this.$loading.hide();
                    this.$resultsContainer.html(
                        '<div class="htax-error">' + HTAX_PowerSearch.i18n.error + '</div>'
                    );
                }).always(() => {
                    if (seq === this._searchSeq) {
                        this._inFlightKey = null;
                    }
                });
            });
        }

        /**
         * HTML for the server's search notices (e.g. near:me without a known location).
         */
        noticesHtml(notices) {
            return (notices || []).map(n => '<p class="htax-search-notice" role="status">' + this.escapeHtml(n) + '</p>').join('');
        }

        renderResults(data) {
            const showThumbnail = [true, 'true'].includes(this.$results.data('show-thumbnail'));
            const showExcerpt = [true, 'true'].includes(this.$results.data('show-excerpt'));
            const showMeta = [true, 'true'].includes(this.$results.data('show-meta'));
            const template = this.$results.data('template') || 'default';
            const format = this.$results.data('format') || 'div';
            const prependTpl = this.$results.data('prepend') || '';
            const appendTpl  = this.$results.data('append')  || '';

            const notices = this.noticesHtml(data.notices);

            if (data.posts.length === 0) {
                const message = data.message || this.$results.data('no-results-message') || HTAX_PowerSearch.i18n.no_results;
                this.$resultsContainer.html(
                    notices + '<div class="htax-no-results">' + message + '</div>'
                );
                this.renderPaginationNav(0, 0);
                return;
            }

            let html = notices + '<div class="htax-results-count">' + this.escapeHtml(data.results_label || String(data.found_posts)) + '</div>';

            if (format === 'ul' || format === 'ol') {
                html += '<' + format + ' class="htax-result-list">';
                data.posts.forEach(post => {
                    html += '<li class="htax-result-item">';
                    if (prependTpl) html += this.processPostPlaceholders(prependTpl, post);
                    html += '<a href="' + post.permalink + '">' + this.escapeHtml(post.title) + '</a>';
                    if (appendTpl)  html += this.processPostPlaceholders(appendTpl,  post);
                    html += '</li>';
                });
                html += '</' + format + '>';
            } else {
                data.posts.forEach(post => {
                    html += this.renderPost(post, template, showThumbnail, showExcerpt, showMeta, prependTpl, appendTpl);
                });
            }

            this.$resultsContainer.html(html);
            this.renderPaginationNav(data.current_page, data.max_pages);
        }

        renderPaginationNav(currentPage, maxPages) {
            const $nav = this.$results.find('.htax-search-results-nav');
            if (!$nav.length) return;

            if (maxPages <= 1) {
                $nav.empty();
                return;
            }

            let html = '';
            if (currentPage > 1) {
                html += '<button type="button" class="htax-results-prev" data-page="' + (currentPage - 1) + '">' +
                    '&#171; ' + HTAX_PowerSearch.i18n.prev + '</button>';
            }
            html += '<span class="htax-results-page-info">' +
                HTAX_PowerSearch.i18n.page_of.replace('%1$d', currentPage).replace('%2$d', maxPages) +
                '</span>';
            if (currentPage < maxPages) {
                html += '<button type="button" class="htax-results-next" data-page="' + (currentPage + 1) + '">' +
                    HTAX_PowerSearch.i18n.next + ' &#187;</button>';
            }
            $nav.html(html);
        }

        processPostPlaceholders(tpl, post) {
            return tpl
                .replace(/\{id\}/g,            post.id            || '')
                .replace(/\{title\}/g,          this.escapeHtml(post.title   || ''))
                .replace(/\{url\}/g,            post.permalink     || '')
                .replace(/\{date\}/g,           this.escapeHtml(post.date    || ''))
                .replace(/\{modified\}/g,       this.escapeHtml(post.modified || ''))
                .replace(/\{author\}/g,         this.escapeHtml(post.author  || ''))
                .replace(/\{excerpt\}/g,        this.escapeHtml(post.excerpt  || ''))
                .replace(/\{comment_count\}/g,  post.comment_count != null ? post.comment_count : '');
        }

        renderPost(post, template, showThumbnail, showExcerpt, showMeta, prependTpl, appendTpl) {
            let html = '<article class="htax-result-item htax-result-' + template + '">';

            if (prependTpl) html += this.processPostPlaceholders(prependTpl, post);

            if (showThumbnail && post.thumbnail) {
                html += '<div class="htax-result-thumbnail">';
                html += '<a href="' + post.permalink + '">';
                html += '<img src="' + post.thumbnail + '" alt="' + this.escapeHtml(post.title) + '">';
                html += '</a>';
                html += '</div>';
            }

            html += '<div class="htax-result-content">';

            html += '<h3 class="htax-result-title">';
            html += '<a href="' + post.permalink + '">' + this.escapeHtml(post.title) + '</a>';
            html += '</h3>';

            if (showMeta) {
                html += '<div class="htax-result-meta">';
                html += '<span class="htax-result-date">' + this.escapeHtml(post.date) + '</span>';
                html += ' <span class="htax-meta-separator">•</span> ';
                html += '<span class="htax-result-author">' + this.escapeHtml(post.author) + '</span>';
                html += '</div>';
            }

            if (showExcerpt && post.excerpt) {
                html += '<div class="htax-result-excerpt">' + this.escapeHtml(post.excerpt) + '</div>';
            }

            html += '</div>'; // .htax-result-content

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

    // Sort-change and pagination are handled by the unified delegated listeners in
    // htax-search-widget.js, which work for any .htax-search-results container.
    // PowerSearch stores its instance via $results.data('htax-form-instance') in the
    // constructor so those handlers can call instance.performSearch().

})(jQuery);
