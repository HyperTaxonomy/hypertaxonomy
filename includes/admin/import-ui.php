<?php
/**
 * HyperTaxonomy Import UI Section
 *
 * Renders the Import subtab within the Export / Import settings tab.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<h3><?php esc_html_e( 'Import HyperTaxonomy Data', 'hypertaxonomy' ); ?></h3>

<p class="description" style="margin: 0 0 15px 0;">
    <?php esc_html_e( 'Import term groups, relationships, and metadata from a HyperTaxonomy export JSON file.', 'hypertaxonomy' ); ?>
</p>

<div style="margin-bottom: 15px;">
    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
        <?php esc_html_e( 'Select Export File:', 'hypertaxonomy' ); ?>
    </label>
    <input type="file"
           id="htax-import-file"
           accept=".json,application/json"
           style="margin-bottom: 10px;" />
</div>

<div style="margin-bottom: 15px;">
    <label>
        <input type="checkbox"
               id="htax-import-update-existing"
               name="htax_import_update_existing"
               value="1"
               checked />
        <?php esc_html_e( 'Update existing terms (recommended)', 'hypertaxonomy' ); ?>
    </label>
    <p class="description" style="margin: 5px 0 0 25px;">
        <?php esc_html_e( 'If unchecked, existing terms will be skipped and only new terms will be created.', 'hypertaxonomy' ); ?>
    </p>
</div>

<div style="margin-bottom: 15px;">
    <label>
        <input type="checkbox"
               id="htax-import-term-posts"
               name="htax_import_term_posts"
               value="1" />
        <?php esc_html_e( 'Import term-to-post assignments (if included in export file)', 'hypertaxonomy' ); ?>
    </label>
</div>

<button type="button" id="htax-preview-import" class="button">
    <?php esc_html_e( 'Preview Import', 'hypertaxonomy' ); ?>
</button>
<button type="button" id="htax-import-button" class="button button-primary" style="display: none;">
    <?php esc_html_e( 'Start Import', 'hypertaxonomy' ); ?>
</button>

<div id="htax-import-preview" style="margin-top: 15px;"></div>

<script>
jQuery(document).ready(function($) {
    // Preview import
    $('#htax-preview-import').on('click', function() {
        var $button = $(this);
        var $preview = $('#htax-import-preview');
        var $importButton = $('#htax-import-button');
        var fileInput = document.getElementById('htax-import-file');

        if (!fileInput.files || !fileInput.files[0]) {
            $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;"><?php esc_html_e( 'Please select a file to import', 'hypertaxonomy' ); ?></p></div>');
            return;
        }

        $button.prop('disabled', true).text('<?php esc_html_e( 'Loading...', 'hypertaxonomy' ); ?>');
        $preview.html('<p><?php esc_html_e( 'Reading import file...', 'hypertaxonomy' ); ?></p>');

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var importData = JSON.parse(e.target.result);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'htax_preview_import',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'htax_import' ) ); ?>',
                        import_data: JSON.stringify(importData)
                    },
                    success: function(response) {
                        if (response.success && response.data.preview) {
                            var preview = response.data.preview;

                            var html = '<div style="background: #e7f7e7; border-left: 4px solid #46b450; padding: 12px; margin-bottom: 15px;">';
                            html += '<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Import Preview', 'hypertaxonomy' ); ?></strong></p>';
                            html += '<table style="width: 100%; border-collapse: collapse;">';
                            html += '<tr><td style="padding: 2px 10px 2px 0;"><strong><?php esc_html_e( 'Exported from:', 'hypertaxonomy' ); ?></strong></td><td>' + preview.exported_from + '</td></tr>';
                            html += '<tr><td style="padding: 2px 10px 2px 0;"><strong><?php esc_html_e( 'Exported at:', 'hypertaxonomy' ); ?></strong></td><td>' + preview.exported_at + '</td></tr>';
                            html += '<tr><td style="padding: 2px 10px 2px 0;"><strong><?php esc_html_e( 'Total Terms:', 'hypertaxonomy' ); ?></strong></td><td>' + preview.total_terms + '</td></tr>';
                            html += '<tr><td style="padding: 2px 10px 2px 0;"><strong><?php esc_html_e( 'Total Relationships:', 'hypertaxonomy' ); ?></strong></td><td>' + preview.total_relationships + '</td></tr>';
                            html += '</table></div>';

                            if (Object.keys(preview.taxonomies).length > 0) {
                                html += '<p style="margin-bottom: 8px;"><strong><?php esc_html_e( 'Terms by Taxonomy:', 'hypertaxonomy' ); ?></strong></p>';
                                html += '<ul style="list-style: disc; margin: 0 0 15px 25px;">';
                                $.each(preview.taxonomies, function(tax, counts) {
                                    html += '<li>' + tax + ': ' + counts.new + ' new, ' + counts.existing + ' existing</li>';
                                });
                                html += '</ul>';
                            }

                            $preview.html(html);
                            $importButton.show();
                        } else {
                            $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;">' + (response.data.error || '<?php esc_html_e( 'Invalid import file', 'hypertaxonomy' ); ?>') + '</p></div>');
                            $importButton.hide();
                        }

                        $button.prop('disabled', false).text('<?php esc_html_e( 'Preview Import', 'hypertaxonomy' ); ?>');
                    },
                    error: function() {
                        $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;"><?php esc_html_e( 'Error communicating with server', 'hypertaxonomy' ); ?></p></div>');
                        $button.prop('disabled', false).text('<?php esc_html_e( 'Preview Import', 'hypertaxonomy' ); ?>');
                        $importButton.hide();
                    }
                });

            } catch (err) {
                $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;"><?php esc_html_e( 'Invalid JSON file', 'hypertaxonomy' ); ?></p></div>');
                $button.prop('disabled', false).text('<?php esc_html_e( 'Preview Import', 'hypertaxonomy' ); ?>');
                $importButton.hide();
            }
        };

        reader.readAsText(fileInput.files[0]);
    });

    // Start import
    $('#htax-import-button').on('click', function() {
        var $button = $(this);
        var $preview = $('#htax-import-preview');
        var fileInput = document.getElementById('htax-import-file');

        if (!fileInput.files || !fileInput.files[0]) {
            return;
        }

        if (!confirm('<?php esc_html_e( 'Are you sure you want to import this data? This will create/update terms and relationships.', 'hypertaxonomy' ); ?>')) {
            return;
        }

        $button.prop('disabled', true).text('<?php esc_html_e( 'Importing...', 'hypertaxonomy' ); ?>');
        $('#htax-preview-import').prop('disabled', true);
        $preview.html('<p><?php esc_html_e( 'Import in progress...', 'hypertaxonomy' ); ?></p>');

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var importData = JSON.parse(e.target.result);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'htax_import',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'htax_import' ) ); ?>',
                        import_data: JSON.stringify(importData),
                        update_existing: $('#htax-import-update-existing').is(':checked') ? '1' : '0',
                        import_term_posts: $('#htax-import-term-posts').is(':checked') ? '1' : '0'
                    },
                    success: function(response) {
                        if (response.success && response.data.html) {
                            $preview.html(response.data.html);
                            $button.hide();
                            $('#htax-preview-import').prop('disabled', false).text('<?php esc_html_e( 'Import Another File', 'hypertaxonomy' ); ?>');
                        } else {
                            $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;">' + (response.data.error || '<?php esc_html_e( 'Import failed', 'hypertaxonomy' ); ?>') + '</p></div>');
                            $button.prop('disabled', false).text('<?php esc_html_e( 'Start Import', 'hypertaxonomy' ); ?>');
                            $('#htax-preview-import').prop('disabled', false);
                        }
                    },
                    error: function() {
                        $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;"><?php esc_html_e( 'Error communicating with server', 'hypertaxonomy' ); ?></p></div>');
                        $button.prop('disabled', false).text('<?php esc_html_e( 'Start Import', 'hypertaxonomy' ); ?>');
                        $('#htax-preview-import').prop('disabled', false);
                    }
                });

            } catch (err) {
                $preview.html('<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px;"><p style="margin: 0;"><?php esc_html_e( 'Invalid JSON file', 'hypertaxonomy' ); ?></p></div>');
                $button.prop('disabled', false).text('<?php esc_html_e( 'Start Import', 'hypertaxonomy' ); ?>');
                $('#htax-preview-import').prop('disabled', false);
            }
        };

        reader.readAsText(fileInput.files[0]);
    });
});
</script>
