<?php
/**
 * Tag Groups Migrate UI Section
 *
 * Renders the Migrate subtab within the Export / Import settings tab: the
 * one-time or repeated import of Tag Groups data. Sync behavior (integration
 * mode, removal handling) is configured on the Sync subtab.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Tag Groups stores groups in WordPress options: term_groups, term_group_labels
// Term assignments are stored in termmeta with key '_cm_term_group_array'
$tag_groups_ids = array_filter( get_option( 'term_groups', array() ), function( $id ) {
    return $id > 0;
} );

$tag_groups_count = count( $tag_groups_ids );

$terms_count = $wpdb->get_var(
    "SELECT COUNT(DISTINCT term_id)
    FROM {$wpdb->termmeta}
    WHERE meta_key = '_cm_term_group_array'"
);

$last_import = get_option( 'htax_tag_groups_last_import' );
$sync_mode   = get_option( 'htax_tag_groups_sync_mode', 'keep' );
$sync_mode   = in_array( $sync_mode, array( 'mirror', 'keep' ), true ) ? $sync_mode : 'keep';

// Pre-selects the removal handling the continuous sync uses when it is on, otherwise the default.
$run_mode_default = htax_tag_groups_live_sync_enabled() ? $sync_mode : 'keep';

$integration_mode = htax_tag_groups_integration_mode();
$mapped_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_htax_tag_groups_source_id'" );

// Set by the Sync subtab after enabling continuous sync, which needs one full resync first.
$auto_resync = isset( $_GET['resync'] ) && $tag_groups_count > 0;
?>
<h3><?php esc_html_e( 'Migrate from Tag Groups', 'hypertaxonomy' ); ?></h3>

<p class="description" style="margin: 0 0 15px 0; font-style: italic;">
    <?php esc_html_e( 'Tag Groups was rebranded "PublishPress Tag Groups" in October 2026; both names refer to the same plugin and database format, so this tool works with data from either.', 'hypertaxonomy' ); ?>
</p>

<p class="description" style="margin: 0 0 15px 0;">
    <?php esc_html_e( 'Migrates term group data from Tag Groups plugin database tables to HyperTaxonomy. Tag Groups does not need to be active or installed - only the database data needs to be present (can be migrated via database backup tools).', 'hypertaxonomy' ); ?>
</p>

<p class="description" style="margin: 0 0 15px 0; font-style: italic;">
    <?php esc_html_e( 'Note: Although Tag Groups provides an export feature, it does not contain enough information for a complete migration. This tool reads directly from the WordPress database tables where Tag Groups stores its data.', 'hypertaxonomy' ); ?>
</p>

<details style="margin-bottom: 15px;">
    <summary style="cursor: pointer; color: #2271b1; text-decoration: underline;">
        <?php esc_html_e( 'Database Tables Used', 'hypertaxonomy' ); ?>
    </summary>
    <ul style="list-style: disc; margin: 8px 0 0 25px; font-family: monospace; font-size: 13px;">
        <li><code>wp_options</code> - <code>term_groups</code> (group IDs)</li>
        <li><code>wp_options</code> - <code>term_group_labels</code> (group names)</li>
        <li><code>wp_termmeta</code> - <code>_cm_term_group_array</code> (term assignments)</li>
    </ul>
</details>

<?php if ( $tag_groups_count > 0 ) : ?>
    <div style="background: #e7f7e7; border-left: 4px solid #46b450; padding: 12px; margin-bottom: 15px;">
        <p style="margin: 0 0 8px 0;">
            <strong><?php esc_html_e( 'Tag Groups Data Found!', 'hypertaxonomy' ); ?></strong>
        </p>
        <p style="margin: 0;">
            <?php
            printf(
                /* translators: 1: number of groups, 2: number of terms */
                esc_html__( 'Found %1$d Tag Groups containing %2$d terms in the database.', 'hypertaxonomy' ),
                (int) $tag_groups_count,
                (int) $terms_count
            );
            ?>
            <?php
            if ( is_array( $last_import ) ) :
                $group_diff = (int) $tag_groups_count - (int) $last_import['groups'];
                $term_diff  = (int) $terms_count - (int) $last_import['terms'];
                if ( $group_diff > 0 || $term_diff > 0 ) :
                    ?>
                    <br />
                    <?php
                    printf(
                        /* translators: 1: count of new groups, e.g. "3 new groups", 2: count of new term assignments, e.g. "12 new term assignments" */
                        esc_html__( '%1$s and %2$s since your last migration.', 'hypertaxonomy' ),
                        esc_html( sprintf( _n( '%d new group', '%d new groups', max( 0, $group_diff ), 'hypertaxonomy' ), max( 0, $group_diff ) ) ),
                        esc_html( sprintf( _n( '%d new term assignment', '%d new term assignments', max( 0, $term_diff ), 'hypertaxonomy' ), max( 0, $term_diff ) ) )
                    );
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>

    <p style="margin-bottom: 8px;"><strong><?php esc_html_e( 'What will be migrated:', 'hypertaxonomy' ); ?></strong></p>
    <ul style="list-style: disc; margin: 8px 0 15px 25px;">
        <li><?php esc_html_e( 'All Tag Groups will be converted to HyperTaxonomy Term Groups', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Group names will be preserved (or auto-generated if necessary), and renames on the Tag Groups side are detected and propagated on subsequent re-run', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Tag Groups Pro parent group hierarchy is applied to the corresponding Term Groups, where present, once when each group is migrated initially', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'All term-to-group assignments will be preserved', 'hypertaxonomy' ); ?></li>
        <li><?php esc_html_e( 'Existing groups with the same name will not be duplicated', 'hypertaxonomy' ); ?></li>
    </ul>

    <p class="description" style="margin-bottom: 15px;">
        <?php esc_html_e( 'This process does not modify any Tag Groups data. You can run it multiple times safely — if Tag Groups is still active, re-run this migration before deactivating it to pick up any changes made since your last run, or turn on the option to sync continuously.', 'hypertaxonomy' ); ?>
    </p>

    <?php if ( 'independent' === $integration_mode && $mapped_count > 0 ) : ?>
        <p style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin: 0 0 15px 0;">
            <?php esc_html_e( 'Groups from Tag Groups are not locked. Running the migration again can overwrite any edits made to them in HyperTaxonomy. Switch to Locked on the Sync subtab if you will keep re-running the migration.', 'hypertaxonomy' ); ?>
        </p>
    <?php endif; ?>

    <button type="button" id="htax-open-import-modal" class="button button-primary" style="margin-top: 5px;">
        <?php esc_html_e( 'Migrate Tag Groups Now', 'hypertaxonomy' ); ?>
    </button>

<?php else : ?>
    <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 12px;">
        <p style="margin: 0 0 8px 0;">
            <strong><?php esc_html_e( 'No Tag Groups Data Found', 'hypertaxonomy' ); ?></strong>
        </p>
        <p style="margin: 0;">
            <?php esc_html_e( 'No terms with Tag Groups assignments were found in the database.', 'hypertaxonomy' ); ?>
        </p>
    </div>
<?php endif; ?>

<!-- Import Modal -->
<div id="htax-import-modal" style="display: none;">
    <div id="htax-import-modal-backdrop" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000;"></div>
    <div id="htax-import-modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border-radius: 4px; box-shadow: 0 3px 15px rgba(0,0,0,0.3); z-index: 100001; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Migrate Tag Groups', 'hypertaxonomy' ); ?></h2>
        <div id="htax-run-mode" style="margin-bottom: 15px;">
            <p style="margin-bottom: 8px;"><strong><?php esc_html_e( 'How removed assignments are handled on this run:', 'hypertaxonomy' ); ?></strong></p>
            <label style="display:block; margin-bottom: 4px;">
                <input type="radio" name="htax_run_mode" value="keep" <?php checked( $run_mode_default, 'keep' ); ?> />
                <?php esc_html_e( 'Keep (recommended, default) — never remove a Term Groups assignment, even if it no longer exists in Tag Groups', 'hypertaxonomy' ); ?>
            </label>
            <label style="display:block;">
                <input type="radio" name="htax_run_mode" value="mirror" <?php checked( $run_mode_default, 'mirror' ); ?> />
                <?php esc_html_e( 'Mirror — make Term Groups match Tag Groups\' current state exactly, including removals', 'hypertaxonomy' ); ?>
            </label>
        </div>
        <div id="htax-import-progress">
            <p><?php esc_html_e( 'Click "Start Migration" to begin migrating Tag Groups data...', 'hypertaxonomy' ); ?></p>
        </div>
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
            <button type="button" id="htax-start-import" class="button button-primary"><?php esc_html_e( 'Start Migration', 'hypertaxonomy' ); ?></button>
            <button type="button" id="htax-close-modal" class="button" style="margin-left: 10px;"><?php esc_html_e( 'Close', 'hypertaxonomy' ); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce      = '<?php echo esc_js( wp_create_nonce( 'htax_import_tag_groups' ) ); ?>';
    var BATCH_SIZE = 50;

    $('#htax-open-import-modal').on('click', function() {
        $('#htax-import-modal').show();
    });

    $('#htax-close-modal, #htax-import-modal-backdrop').on('click', function() {
        $('#htax-import-modal').hide();
    });

    function showError(msg) {
        $('#htax-import-progress').html(
            '<div style="background:#f8d7da;border-left:4px solid #d63638;padding:12px">' +
            '<p style="margin:0"><strong><?php esc_html_e( 'Error:', 'hypertaxonomy' ); ?></strong> ' +
            $('<div>').text(msg).html() + '</p></div>'
        );
        $('#htax-start-import').prop('disabled', false).text('<?php esc_html_e( 'Retry', 'hypertaxonomy' ); ?>');
    }

    function setProgress(pct, label) {
        $('#htax-progress-bar').css('width', pct + '%').attr('aria-valuenow', pct);
        $('#htax-progress-label').text(label);
    }

    function runBatch(groupMapping, offset, totalTerms, totals, mode) {
        $.post(ajaxurl, {
            action:        'htax_import_tag_groups_batch',
            nonce:         nonce,
            group_mapping: JSON.stringify(groupMapping),
            offset:        offset,
            batch_size:    BATCH_SIZE,
            mode:          mode
        })
        .done(function(r) {
            if (!r.success) { showError(r.data || '<?php esc_html_e( 'Batch failed.', 'hypertaxonomy' ); ?>'); return; }
            var d = r.data;
            totals.assigned += d.assigned;
            totals.skipped  += d.skipped;
            totals.errors   += d.errors;

            var processed = Math.min(d.next_offset, totalTerms);
            var pct       = totalTerms > 0 ? Math.round(processed / totalTerms * 100) : 100;
            setProgress(pct, <?php echo wp_json_encode( __( '%1$d / %2$d terms', 'hypertaxonomy' ) ); ?>.replace('%1$d', processed).replace('%2$d', totalTerms));

            if (d.row_count === BATCH_SIZE && d.next_offset < totalTerms) {
                runBatch(groupMapping, d.next_offset, totalTerms, totals, mode);
            } else {
                finish(totals);
            }
        })
        .fail(function(xhr) { showError(xhr.statusText); });
    }

    function finish(totals) {
        $.post(ajaxurl, { action: 'htax_import_tag_groups_finish', nonce: nonce })
        .always(function() {
            setProgress(100, '<?php esc_html_e( 'Complete', 'hypertaxonomy' ); ?>');
            var problems = '';
            if (totals.errors > 0) {
                problems += '<p style="margin:0 0 6px 0">' + <?php echo wp_json_encode( __( '%d terms could not be updated.', 'hypertaxonomy' ) ); ?>.replace('%d', totals.errors) + '</p>';
            }
            if (totals.groupErrors && totals.groupErrors.length) {
                problems += '<p style="margin:0 0 4px 0">' + <?php echo wp_json_encode( __( 'Groups that could not be created:', 'hypertaxonomy' ) ); ?> + '</p><ul style="margin:0 0 0 18px">' +
                    totals.groupErrors.map(function(e) { return '<li>' + $('<div>').text(e).html() + '</li>'; }).join('') + '</ul>';
            }
            if (problems) {
                problems = '<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px;margin-top:12px">' + problems + '</div>';
            }
            $('#htax-import-progress').append(
                '<div style="background:#d1f0d1;border-left:4px solid #46b450;padding:12px;margin-top:16px">' +
                '<p style="margin:0 0 6px 0"><strong><?php esc_html_e( 'Migration Complete!', 'hypertaxonomy' ); ?></strong></p>' +
                '<p style="margin:0">' + <?php echo wp_json_encode( __( '%1$d assigned, %2$d skipped.', 'hypertaxonomy' ) ); ?>.replace('%1$d', totals.assigned).replace('%2$d', totals.skipped) + '</p>' +
                '</div>' +
                problems +
                '<p style="margin-top:12px"><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=group' ) ); ?>" class="button button-primary">' +
                '<?php esc_html_e( 'View Term Groups', 'hypertaxonomy' ); ?></a></p>'
            );
            $('#htax-start-import').hide();
            $('#htax-close-modal').text('<?php esc_html_e( 'Done', 'hypertaxonomy' ); ?>');
        });
    }

    $('#htax-start-import').on('click', function() {
        $(this).prop('disabled', true).text('<?php esc_html_e( 'Migrating...', 'hypertaxonomy' ); ?>');
        $('#htax-import-progress').html(
            '<p style="margin:0 0 8px 0"><strong><?php esc_html_e( 'Creating groups...', 'hypertaxonomy' ); ?></strong></p>' +
            '<div style="background:#e0e0e0;border-radius:4px;height:20px;overflow:hidden">' +
            '<div id="htax-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s" aria-valuenow="0"></div>' +
            '</div>' +
            '<p id="htax-progress-label" style="margin:6px 0 0 0;font-size:12px;color:#666">0%</p>'
        );

        $('#htax-run-mode').hide();
        var runMode = $('input[name="htax_run_mode"]:checked').val() || 'keep';

        $.post(ajaxurl, { action: 'htax_import_tag_groups_init', nonce: nonce, mode: runMode })
        .done(function(r) {
            if (!r.success) { showError(r.data || '<?php esc_html_e( 'Init failed.', 'hypertaxonomy' ); ?>'); return; }
            var d = r.data;
            setProgress(0, <?php echo wp_json_encode( __( '%1$d / %2$d terms', 'hypertaxonomy' ) ); ?>.replace('%1$d', 0).replace('%2$d', d.total_terms));

            var summary = <?php echo wp_json_encode( __( 'Created %1$d groups, %2$d already existed, %3$d renamed. Hierarchy applied to %4$d.', 'hypertaxonomy' ) ); ?>
                .replace('%1$d', d.groups_created)
                .replace('%2$d', d.groups_existing)
                .replace('%3$d', d.groups_renamed)
                .replace('%4$d', d.parents_applied);
            if (d.groups_orphaned && d.groups_orphaned.length) {
                summary += ' ' + <?php echo wp_json_encode( __( 'Group mappings cleared (deleted in Tag Groups): %1$d.', 'hypertaxonomy' ) ); ?>.replace('%1$d', d.groups_orphaned.length);
            }
            if (d.groups_adopted && d.groups_adopted.length) {
                summary += ' ' + <?php echo wp_json_encode( __( 'Matched existing groups by name, now managed by Tag Groups: %s.', 'hypertaxonomy' ) ); ?>.replace('%s', d.groups_adopted.join(', '));
            }
            if (d.groups_disambiguated && d.groups_disambiguated.length) {
                summary += ' ' + <?php echo wp_json_encode( __( 'Renamed to tell apart groups that share a label: %s.', 'hypertaxonomy' ) ); ?>.replace('%s', d.groups_disambiguated.join(', '));
            }
            $('#htax-import-progress p:first').text(summary + ' <?php esc_html_e( 'Assigning terms...', 'hypertaxonomy' ); ?>');

            if (d.total_terms === 0) {
                finish({ assigned: 0, skipped: 0, errors: 0, groupErrors: d.errors });
            } else {
                runBatch(d.group_mapping, 0, d.total_terms, { assigned: 0, skipped: 0, errors: 0, groupErrors: d.errors }, runMode);
            }
        })
        .fail(function(xhr) { showError(xhr.statusText); });
    });

    <?php if ( $auto_resync ) : ?>
    $('#htax-open-import-modal').trigger('click');
    $('#htax-start-import').trigger('click');
    <?php endif; ?>
});
</script>
