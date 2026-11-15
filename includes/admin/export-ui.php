<?php
/**
 * HyperTaxonomy Export UI Section
 *
 * Renders the Export subtab within the Export / Import settings tab.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
?>

<h3><?php esc_html_e( 'Export HyperTaxonomy Data', 'hypertaxonomy' ); ?></h3>

<p class="description" style="margin: 0 0 15px 0;">
    <?php esc_html_e( 'Export term groups, relationships, and metadata to a JSON file for migration between WordPress instances.', 'hypertaxonomy' ); ?>
</p>

<details style="margin-bottom: 15px;">
    <summary style="cursor: pointer; color: #2271b1; text-decoration: underline;">
        <?php esc_html_e( 'What will be exported?', 'hypertaxonomy' ); ?>
    </summary>
    <ul style="list-style: disc; margin: 8px 0 0 25px;">
        <li><?php esc_html_e( 'Term Groups and their members', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Direct inclusion/container relationships', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Group inclusion and container group relationships', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Term redirects (including cross-taxonomy redirects)', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Term metadata (visibility, icons, archive settings, sticky posts)', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'All data uses slug-based matching for cross-site compatibility', 'hypertaxonomy' ); ?></li>
    </ul>
</details>

<div style="margin-bottom: 15px;">
    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
        <?php esc_html_e( 'Select Taxonomies to Export:', 'hypertaxonomy' ); ?>
    </label>
    <?php foreach ( $all_taxonomies as $tax_slug => $tax_obj ) : ?>
        <label style="display: block; margin-bottom: 5px;">
            <input type="checkbox"
                   name="htax_export_taxonomies[]"
                   value="<?php echo esc_attr( $tax_slug ); ?>"
                   class="htax-export-taxonomy"
                   <?php checked( in_array( $tax_slug, array( 'post_tag', 'category', 'group' ), true ) ); ?> />
            <?php echo esc_html( $tax_obj->labels->name ); ?>
            <span style="color: #666; font-size: 12px;">(<?php echo esc_html( $tax_slug ); ?>)</span>
        </label>
    <?php endforeach; ?>
</div>

<div style="margin-bottom: 15px;">
    <label>
        <input type="checkbox"
               id="htax-export-term-posts"
               name="htax_export_term_posts"
               value="1" />
        <?php esc_html_e( 'Include term-to-post assignments (optional - WordPress export already handles this)', 'hypertaxonomy' ); ?>
    </label>
</div>

<button type="button" id="htax-export-button" class="button button-primary">
    <?php esc_html_e( 'Download Export File', 'hypertaxonomy' ); ?>
</button>
<span id="htax-export-message" style="margin-left: 10px;"></span>

<script>
jQuery(document).ready(function($) {
    $('#htax-export-button').on('click', function() {
        var $button = $(this);
        var $message = $('#htax-export-message');

        // Get selected taxonomies
        var taxonomies = [];
        $('.htax-export-taxonomy:checked').each(function() {
            taxonomies.push($(this).val());
        });

        if (taxonomies.length === 0) {
            $message.html('<span style="color: #d63638;"><?php esc_html_e( 'Please select at least one taxonomy', 'hypertaxonomy' ); ?></span>');
            return;
        }

        var includeTermPosts = $('#htax-export-term-posts').is(':checked');

        $button.prop('disabled', true).text('<?php esc_html_e( 'Generating...', 'hypertaxonomy' ); ?>');
        $message.html('<span style="color: #666;"><?php esc_html_e( 'Generating export file...', 'hypertaxonomy' ); ?></span>');

        // Create form and submit to trigger download
        var $form = $('<form>', {
            method: 'POST',
            action: ajaxurl
        });

        $form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'htax_export'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: '<?php echo esc_js( wp_create_nonce( 'htax_export' ) ); ?>'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'taxonomies',
            value: JSON.stringify(taxonomies)
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'include_term_posts',
            value: includeTermPosts ? '1' : '0'
        }));

        // Use iframe to trigger download without page navigation
        var iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        document.body.appendChild(iframe);

        iframe.contentDocument.body.appendChild($form[0]);
        $form.submit();

        setTimeout(function() {
            $button.prop('disabled', false).text('<?php esc_html_e( 'Download Export File', 'hypertaxonomy' ); ?>');
            $message.html('<span style="color: #46b450;">✓ <?php esc_html_e( 'Export file downloaded', 'hypertaxonomy' ); ?></span>');
            setTimeout(function() {
                $message.empty();
            }, 3000);
        }, 1000);
    });
});
</script>
