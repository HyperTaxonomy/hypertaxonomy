/**
 * Term Edit Locking
 *
 * Manages the heartbeat-based lock refresh and the take-over / displacement dialog
 * for term edit screens and the relationship type edit form.
 *
 * Expects HTAXLock to be localised by PHP:
 *   term_id   {number|null}  - Term ID (term locks only)
 *   rt_slug   {string|null}  - Relationship type slug (RT locks only)
 *   nonce     {string}       - Per-item nonce for heartbeat verification
 *   locked    {object|false} - If another user holds the lock: { name, avatar,
 *                               take_over_url, back_url }; false otherwise
 *   list_url  {string}       - List screen URL for the "Go to list" button
 *   i18n      {object}       - Translated strings
 */
( function ( $ ) {
    'use strict';

    var lock = window.HTAXLock;
    if ( ! lock ) {
        return;
    }

    var $dialog = $( '#htax-term-lock-dialog' );

    // -------------------------------------------------------------------------
    // PAGE LOAD — another user already holds the lock
    // -------------------------------------------------------------------------

    if ( lock.locked ) {
        // Dialog and editing-section are already visible via inline PHP styles.
        // Nothing more to do: the user can click Go Back or Take Over.
        return;
    }

    // -------------------------------------------------------------------------
    // WE HOLD THE LOCK — send heartbeat refresh each tick
    // -------------------------------------------------------------------------

    $( document ).on( 'heartbeat-send', function ( e, data ) {
        var payload = { nonce: lock.nonce };

        if ( lock.term_id ) {
            payload.term_id = lock.term_id;
            data.htax_term_lock_refresh = payload;
        } else if ( lock.rt_slug ) {
            payload.slug = lock.rt_slug;
            data.htax_rt_lock_refresh = payload;
        }
    } );

    $( document ).on( 'heartbeat-tick', function ( e, data ) {
        var response = lock.term_id ? data.htax_term_lock : data.htax_rt_lock;

        if ( ! response || ! response.lock_error ) {
            return;
        }

        // Someone else has taken the lock — show displacement dialog.
        $( '#htax-lock-taken-avatar' ).attr( { src: response.avatar, alt: response.name } );
        $( '#htax-lock-taken-name' ).text( response.name );
        $( '#htax-lock-taken-text' ).text( ' ' + lock.i18n.taken_over_suffix );
        $( '#htax-lock-taken-secondary' ).text( lock.i18n.taken_over_changes );

        $( '#htax-lock-message-editing' ).hide();
        $( '#htax-lock-message-taken' ).show();
        $dialog.show();

        // Stop sending refresh ticks — we no longer hold the lock.
        $( document ).off( 'heartbeat-send' );
    } );

} )( jQuery );
