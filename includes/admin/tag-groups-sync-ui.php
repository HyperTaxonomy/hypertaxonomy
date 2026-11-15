<?php
/**
 * Tag Groups Sync UI Section
 *
 * Renders the Sync subtab within the Export / Import settings tab: how
 * HyperTaxonomy keeps Tag Groups data in step after the initial migration —
 * whether mapped groups are locked against edits, whether changes are synced
 * continuously, and how removed assignments are treated.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sync_mode         = get_option( 'htax_tag_groups_sync_mode', 'keep' );
$sync_mode         = in_array( $sync_mode, array( 'mirror', 'keep' ), true ) ? $sync_mode : 'keep';
$integration_mode  = htax_tag_groups_integration_mode();
$live_sync         = 'locked' === $integration_mode && (bool) get_option( 'htax_tag_groups_live_sync', 0 );
$tag_groups_active = function_exists( 'tag_groups_init' );
?>
<div class="htax-sync-settings">
<h3><?php esc_html_e( 'Tag Groups Sync', 'hypertaxonomy' ); ?></h3>

<p class="description" style="margin: 0 0 15px 0;">
    <?php esc_html_e( 'Controls how HyperTaxonomy and Tag Groups stay in step after migration. If groups have not been migrated yet, do that on the Migrate subtab first.', 'hypertaxonomy' ); ?>
</p>

<?php if ( $tag_groups_active ) : ?>
    <div style="background: #e7f7e7; border-left: 4px solid #46b450; padding: 12px; margin-bottom: 15px;">
        <p style="margin: 0;">
            <strong><?php esc_html_e( 'Tag Groups Is Active', 'hypertaxonomy' ); ?></strong>
            <?php esc_html_e( 'Locking and continuous sync both take effect immediately.', 'hypertaxonomy' ); ?>
        </p>
    </div>
<?php else : ?>
    <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 12px; margin-bottom: 15px;">
        <p style="margin: 0;">
            <strong><?php esc_html_e( 'Tag Groups Is Not Active', 'hypertaxonomy' ); ?></strong>
            <?php esc_html_e( 'These settings are saved either way. Locking takes effect immediately; continuous sync starts once Tag Groups is active again.', 'hypertaxonomy' ); ?>
        </p>
    </div>
<?php endif; ?>

<p style="margin-bottom: 8px;"><strong><?php esc_html_e( 'Mapped group editing:', 'hypertaxonomy' ); ?></strong></p>
<p style="margin: 0 0 8px 0;">
    <label style="display:block; margin-bottom: 4px;">
        <input type="radio" name="htax_tag_groups_integration_mode" value="independent" <?php checked( $integration_mode, 'independent' ); ?> />
        <?php esc_html_e( 'Independent (default) — group names and membership stay fully editable in HyperTaxonomy. However, if they were migrated from Tag Groups, or if a group with the same name exists there, a subsequent migration can overwrite edits made here.', 'hypertaxonomy' ); ?>
    </label>
    <label style="display:block; margin-bottom: 4px;">
        <input type="radio" name="htax_tag_groups_integration_mode" value="locked" <?php checked( $integration_mode, 'locked' ); ?> />
        <?php esc_html_e( 'Locked — make changes to group names or membership in Tag Groups instead — recommended for site admins who plan to use Tag Groups (rather than HyperTaxonomy) as their primary group management tool. Only mapped groups\' names and term membership are locked in HyperTaxonomy, to prevent edits made here from getting lost during subsequent migration — while unmapped groups (that were created manually or imported rather than migrated, and with names do not clash with migrated groups\' names) will not be locked and will not be affected during future migration.', 'hypertaxonomy' ); ?>
    </label>
</p>
<p class="description" style="margin: 0 0 15px 24px;">
    <?php esc_html_e( 'Tag Groups Pro parent group hierarchy is not kept in sync. It is applied only once, during the initial migration.', 'hypertaxonomy' ); ?>
</p>

<p style="margin: 0 0 8px 24px;">
    <label style="display:block;">
        <input type="checkbox" id="htax-live-sync" name="htax_tag_groups_live_sync" value="1" <?php checked( $live_sync ); ?> <?php disabled( 'locked' !== $integration_mode ); ?> />
        <?php esc_html_e( 'Sync continuously — apply Tag Groups changes as they are detected, without the need to re-run migration manually.', 'hypertaxonomy' ); ?>
    </label>
    <span class="description" style="display:block; margin: 4px 0 0 24px;"><?php esc_html_e( 'Requires Locked option to be selected', 'hypertaxonomy' ); ?></span>
</p>

<div id="htax-removal-options" style="margin: 0 0 15px 24px;"<?php echo $live_sync ? '' : ' hidden'; ?>>
    <p style="margin: 0 0 4px 0;"><strong><?php esc_html_e( 'When Tag Groups changes are synced, removed term assignments are:', 'hypertaxonomy' ); ?></strong></p>
    <label style="display:block; margin-bottom: 4px;">
        <input type="radio" name="htax_tag_groups_sync_mode" value="keep" <?php checked( $sync_mode, 'keep' ); ?> />
        <?php esc_html_e( 'Keep (recommended, default) — never remove a Term Groups assignment, even if it no longer exists in Tag Groups', 'hypertaxonomy' ); ?>
    </label>
    <label style="display:block; margin-bottom: 4px;">
        <input type="radio" name="htax_tag_groups_sync_mode" value="mirror" <?php checked( $sync_mode, 'mirror' ); ?> />
        <?php esc_html_e( 'Mirror — make Term Groups match Tag Groups\' current state exactly, including removals', 'hypertaxonomy' ); ?>
    </label>
    <span class="description" style="display:block;">
        <?php esc_html_e( 'A different choice may be made for each manual migration run. Either way, any Term Groups assignment that Tag Groups has no knowledge of, is always preserved.', 'hypertaxonomy' ); ?>
    </span>
</div>

<p style="margin-bottom: 8px;">
    <button type="button" id="htax-save-sync-settings" class="button button-primary">
        <?php esc_html_e( 'Save Sync Settings', 'hypertaxonomy' ); ?>
    </button>
    <span id="htax-sync-settings-status" style="margin-left: 8px;"></span>
</p>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js( wp_create_nonce( 'htax_import_tag_groups' ) ); ?>';

    // Continuous sync is only meaningful under Locked, and removal handling only applies to it.
    function syncRemovalOptions() {
        var show = 'locked' === $('input[name="htax_tag_groups_integration_mode"]:checked').val()
            && $('#htax-live-sync').is(':checked');
        $('#htax-removal-options').toggle(show);
    }

    $('input[name="htax_tag_groups_integration_mode"]').on('change', function() {
        var locked = 'locked' === $(this).val();
        $('#htax-live-sync').prop('disabled', !locked);
        if (!locked) {
            $('#htax-live-sync').prop('checked', false);
        }
        syncRemovalOptions();
    });

    $('#htax-live-sync').on('change', syncRemovalOptions);

    $('#htax-save-sync-settings').on('click', function() {
        var $btn    = $(this);
        var $status = $('#htax-sync-settings-status');

        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js( __( 'Saving…', 'hypertaxonomy' ) ); ?>');

        $.post(ajaxurl, {
            action:           'htax_save_tag_groups_sync_settings',
            nonce:            nonce,
            integration_mode: $('input[name="htax_tag_groups_integration_mode"]:checked').val(),
            live_sync:        $('#htax-live-sync').is(':checked') ? '1' : '0',
            sync_mode:        $('input[name="htax_tag_groups_sync_mode"]:checked').val()
        })
        .done(function(r) {
            $btn.prop('disabled', false);
            if (!r.success) {
                $status.text(r.data || '<?php echo esc_js( __( 'Failed to save.', 'hypertaxonomy' ) ); ?>');
                return;
            }
            $(document).trigger('htax:sync-settings-saved');

            if (r.data && r.data.needs_resync) {
                $status.text('<?php echo esc_js( __( 'Saved. Redirecting to run the one-time full migration ahead of enabling continuous sync…', 'hypertaxonomy' ) ); ?>');
                window.location.href = '?page=hypertaxonomy&tab=export-import&export_import_subtab=migrate&resync=1';
                return;
            }
            $status.text('<?php echo esc_js( __( 'Saved.', 'hypertaxonomy' ) ); ?>');
        })
        .fail(function(xhr) {
            $btn.prop('disabled', false);
            $status.text(xhr.statusText);
        });
    });
});
</script>
