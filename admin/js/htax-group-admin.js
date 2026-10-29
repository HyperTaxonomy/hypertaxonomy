/**
 * Term Groups Admin JavaScript
 *
 * Handles icon upload functionality for group terms.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var mediaUploader;

        // Upload icon button
        $('.htax-upload-icon-button').on('click', function(e) {
            e.preventDefault();

            // If the uploader object has already been created, reopen it
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }

            // Create the media uploader
            mediaUploader = wp.media({
                title: 'Choose Group Icon',
                button: {
                    text: 'Use this icon'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            // When an image is selected, run a callback
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();

                // Set the hidden input value
                $('#htax-group-icon').val(attachment.id);

                // Display the image
                $('.htax-icon-preview').html(
                    $('<img>').attr({ src: attachment.url, alt: '', style: 'max-width:150px;height:auto;' })
                );

                // Update button text
                $('.htax-upload-icon-button').text('Change Icon');

                // Show remove button if not already visible
                if ($('.htax-remove-icon-button').length === 0) {
                    $('.htax-upload-icon-button').after(' <button type="button" class="button htax-remove-icon-button">Remove Icon</button>');
                }
            });

            // Open the uploader dialog
            mediaUploader.open();
        });

        // Remove icon button (using event delegation since button may be added dynamically)
        $(document).on('click', '.htax-remove-icon-button', function(e) {
            e.preventDefault();

            // Clear the hidden input
            $('#htax-group-icon').val('');

            // Remove the image preview
            $('.htax-icon-preview').html('');

            // Update button text
            $('.htax-upload-icon-button').text('Upload Icon');

            // Remove the remove button
            $('.htax-remove-icon-button').remove();
        });

        // Fix column display after inline term addition via AJAX
        $(document).ajaxSuccess(function(event, xhr, settings) {
            // Check if this is a term addition (works for all taxonomies, not just groups)
            if (settings.data && settings.data.indexOf('action=add-tag') !== -1) {

                setTimeout(function() {
                    // Find the newest row (should be first row in tbody)
                    var $newRow = $('#the-list tr:first');

                    if ($newRow.length) {
                        // Check which columns are hidden via Screen Options
                        // WordPress adds a 'hidden' class to <th> elements for hidden columns
                        var $table = $newRow.closest('table');
                        var $headerRow = $table.find('thead tr, thead tr:first');
                        var hiddenColumns = [];

                        $headerRow.find('th, td').each(function(index) {
                            var $th = $(this);
                            // Check multiple ways columns can be hidden
                            var isHidden = $th.hasClass('hidden') ||
                                          $th.css('display') === 'none' ||
                                          $th.is(':hidden');
                            var columnClass = '';

                            // Extract column class (e.g., 'column-description')
                            var classes = $th.attr('class');
                            if (classes) {
                                var match = classes.match(/column-[a-z_]+/);
                                if (match) {
                                    columnClass = match[0];
                                }
                            }


                            if (isHidden && columnClass) {
                                hiddenColumns.push({index: index, class: columnClass});
                            }
                        });

                        // Apply hidden class to corresponding cells in new row
                        if (hiddenColumns.length > 0) {
                            hiddenColumns.forEach(function(col) {
                                var $cell = $newRow.find('td.' + col.class);
                                if ($cell.length) {
                                    $cell.addClass('hidden').hide();
                                }
                            });
                        } else {
                        }

                        // Also check for column count mismatch as a backup
                        var $existingRows = $('#the-list tr').not(':first');
                        if ($existingRows.length > 0) {
                            var newRowColCount = $newRow.find('td').length;
                            var existingColCount = $existingRows.first().find('td').length;


                            if (newRowColCount !== existingColCount) {
                            }
                        }
                    }
                }, 150); // Slightly longer delay to ensure DOM is fully updated
            }
        });

        // ========================================================================
        // TERM MEMBERS AUTOCOMPLETE AND PILL MANAGEMENT
        // NOTE: All pill container functionality is handled by htax-term-edit.js
        // which is already loaded on the group edit page. The containers use the
        // standard .htax-tag-container structure with .htax-search inputs.
        // ========================================================================

        // No additional JavaScript needed - the existing code in htax-term-edit.js
        // automatically initializes all .htax-tag-container elements on page load
    });

})(jQuery);
