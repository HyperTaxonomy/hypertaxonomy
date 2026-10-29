<?php
/**
 * PublishPress Taxonomies (TaxoPress) integration.
 *
 * TaxoPress's own "Merge Terms" tool (Manage Terms screen, admin.php?page=st_manage
 * &tab=merge-terms) has no knowledge of HyperTaxonomy's per-term data — relationships
 * (inclusions, containers, redirects, groups), hidden-term status, icons, sticky
 * posts, or the data TermConnect/ATCF/Social Hashtags & Mentions attach to a term.
 * Merging through TaxoPress silently drops all of it; HyperTaxonomy's own merge
 * (Terms → select two or more → Merge) carries it across to the surviving term.
 *
 * This integration shows a dismissible notice on that screen, only when the
 * taxonomy TaxoPress currently has selected for merging is HyperTaxonomy-enabled,
 * pointing the admin at HyperTaxonomy's own merge tool instead.
 *
 * Detected via defined('TAXOPRESS_VERSION'), set unconditionally near the top of
 * the plugin's main file.
 *
 * @package HyperTaxonomy
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_TaxoPress_Integration {

    /**
     * User meta key recording that a user has permanently dismissed the notice.
     *
     * @since 1.0.0
     */
    const DISMISSED_META_KEY = '_htax_dismissed_taxopress_merge_notice';

    /**
     * Nonce action for the dismiss AJAX request.
     *
     * @since 1.0.0
     */
    const NONCE_ACTION = 'htax_dismiss_taxopress_merge_notice';

    /**
     * Registers hooks, if TaxoPress is active.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        if ( ! defined( 'TAXOPRESS_VERSION' ) ) {
            return;
        }

        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_merge_notice' ) );
        add_action( 'wp_ajax_htax_dismiss_taxopress_merge_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
        add_action( 'deactivated_plugin', array( __CLASS__, 'clear_dismissal_on_deactivate' ) );
    }

    /**
     * Shows the "use HyperTaxonomy's merge instead" notice on TaxoPress's Manage
     * Terms screen, only when the taxonomy it currently has selected for merging
     * is HyperTaxonomy-enabled.
     *
     * TaxoPress's taxonomy dropdown on this screen is client-side; the taxonomy
     * it last had selected is persisted server-side as get_option('merge-terms_taxo'),
     * which is what we read here. If the admin switches taxonomies without a full
     * page reload, this can briefly lag by one taxonomy — acceptable for an
     * informational notice, not worth a tighter (JS-based) coupling to TaxoPress's
     * own UI state.
     *
     * @since 1.0.0
     */
    public static function maybe_show_merge_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['page'] ) || 'st_manage' !== $_GET['page'] ) {
            return;
        }
        if ( get_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, true ) ) {
            return;
        }

        $taxonomy = get_option( 'merge-terms_taxo', 'post_tag' );
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            return;
        }

        $tax_object = get_taxonomy( $taxonomy );
        $tax_label  = $tax_object ? $tax_object->labels->name : $taxonomy;
        $nonce      = wp_create_nonce( self::NONCE_ACTION );
        // TaxoPress's own admin.css hides any ".notice" on its screens via
        // ".notice:not(.taxopress-notice){display:none}" (suppressing other
        // plugins' nag notices to keep its own UI clean) — "taxopress-notice"
        // is the exemption class it checks for, so this notice needs it too.
        ?>
        <div class="notice notice-warning is-dismissible taxopress-notice htax-taxopress-merge-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <p>
                <?php
                printf(
                    /* translators: 1: taxonomy label (e.g. "Tags"), 2: link to HyperTaxonomy's own term-merge tool */
                    esc_html__( 'HyperTaxonomy is enabled for %1$s. Merging terms here will not carry over HyperTaxonomy relationships, hidden-term status, icons, or data from connected plugins (TermConnect, As the Crow Flies, Social Hashtags & Mentions) — use %2$s instead to preserve them.', 'hypertaxonomy' ),
                    '<strong>' . esc_html( $tax_label ) . '</strong>',
                    '<a href="' . esc_url( admin_url( 'admin.php?page=htax-merge-terms&taxonomy=' . $taxonomy ) ) . '">' . esc_html__( "HyperTaxonomy's own merge tool", 'hypertaxonomy' ) . '</a>'
                );
                ?>
            </p>
        </div>
        <script>
        jQuery( document ).on( 'click', '.htax-taxopress-merge-notice .notice-dismiss', function() {
            jQuery.post( ajaxurl, {
                action: 'htax_dismiss_taxopress_merge_notice',
                nonce: jQuery( '.htax-taxopress-merge-notice' ).data( 'nonce' )
            } );
        } );
        </script>
        <?php
    }

    /**
     * AJAX handler: permanently dismisses the notice for the current user.
     *
     * @since 1.0.0
     */
    public static function ajax_dismiss_notice(): void {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'hypertaxonomy' ) );
        }

        update_user_meta( get_current_user_id(), self::DISMISSED_META_KEY, 1 );
        wp_send_json_success();
    }

    /**
     * Clears every user's dismissal when TaxoPress is deactivated, so the notice
     * is shown again (until re-dismissed) if it's reactivated later.
     *
     * @since 1.0.0
     * @param string $plugin Basename of the deactivated plugin, e.g. 'simple-tags/simple-tags.php'.
     */
    public static function clear_dismissal_on_deactivate( string $plugin ): void {
        if ( 0 !== strpos( $plugin, 'simple-tags' ) ) {
            return;
        }
        delete_metadata( 'user', 0, self::DISMISSED_META_KEY, '', true );
    }
}

// Initialize on plugins_loaded after all plugins have had a chance to load.
add_action( 'plugins_loaded', array( 'HyperTaxonomy_TaxoPress_Integration', 'init' ), 20 );
