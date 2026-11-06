/**
 * Block editor support for the "prevent tag creation" setting.
 *
 * The tag field sends a create request for every entered tag it hasn't loaded, and treats
 * an "already exists" reply as a match. It waits for the whole batch, so one refused tag
 * makes it drop the valid tags entered with it, and leave their pills on screen. If the
 * post's own tags haven't loaded yet, the same path can drop those as well.
 *
 * This script watches those requests without changing them. When a batch finishes, it
 * assigns the tags the server matched, restores any of the post's tags that were dropped,
 * and removes the pills of refused tags. The field's own message explains the refusal.
 * Saving is locked while a batch is pending, because the post's tags can briefly be wrong.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */
( function () {
    'use strict';

    if ( ! window.wp || ! wp.apiFetch || ! wp.data ) { return; }

    var TAGS_PATH      = /^\/?wp\/v2\/tags(\?|$)/;
    var REFUSED_CODE   = 'tag_creation_disabled';
    var EXISTS_CODE    = 'term_exists';
    var POLL_INTERVAL  = 200;
    var POLL_ATTEMPTS  = 40;
    var LOCK_NAME      = 'htax-tag-restriction';

    var pending  = 0;
    var snapshot = [];
    var matched  = [];
    var refused  = [];

    function getPostTags() {
        return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'tags' ) || [];
    }

    function isSameSet( a, b ) {
        return a.length === b.length && a.every( function ( id ) { return b.indexOf( id ) !== -1; } );
    }

    function unique( ids ) {
        return ids.filter( function ( id, index ) { return ids.indexOf( id ) === index; } );
    }

    /**
     * Find the post_tag field. Other flat taxonomies use the same component, so it's
     * identified by its label.
     */
    function getTagField() {
        var taxonomy = wp.data.select( 'core' ).getEntityRecord( 'root', 'taxonomy', 'post_tag' );
        var label    = taxonomy && taxonomy.labels ? taxonomy.labels.add_new_item : '';
        var fields   = document.querySelectorAll( '.components-form-token-field' );

        for ( var i = 0; i < fields.length; i++ ) {
            var fieldLabel = fields[ i ].querySelector( '.components-form-token-field__label' );
            if ( fieldLabel && fieldLabel.textContent.trim() === label ) {
                return fields[ i ];
            }
        }
        return null;
    }

    /**
     * Arguments of the tag field's own request for the post's tags.
     */
    function getTermsRequest() {
        return [ 'taxonomy', 'post_tag', {
            per_page: -1,
            _fields:  'id,name',
            context:  'view',
            include:  getPostTags().join( ',' ),
        } ];
    }

    /**
     * Run a callback once the tag field's request for the post's tags has finished.
     */
    function whenTermsLoaded( callback, attempts ) {
        var finished = wp.data.select( 'core' ).hasFinishedResolution( 'getEntityRecords', getTermsRequest() );
        if ( finished || attempts <= 0 ) {
            callback();
            return;
        }
        setTimeout( function () { whenTermsLoaded( callback, attempts - 1 ); }, POLL_INTERVAL );
    }

    /**
     * Remove any refused pills still showing, one at a time because each removal redraws
     * the field. Only a post with no tags gets this far with more than one, because the
     * field can't redraw from an empty list.
     */
    function removeRefusedPills( names ) {
        var field = getTagField();
        if ( ! field ) { return; }

        var tokens = field.querySelectorAll( '.components-form-token-field__token' );
        for ( var i = 0; i < tokens.length; i++ ) {
            var text = tokens[ i ].querySelector( '.components-form-token-field__token-text span[aria-hidden="true"]' );
            var name = text ? text.textContent.trim().toLowerCase() : '';
            if ( names.indexOf( name ) !== -1 ) {
                var button = tokens[ i ].querySelector( '.components-form-token-field__remove-token' );
                if ( button ) {
                    button.click();
                    setTimeout( function () { removeRefusedPills( names ); }, 0 );
                }
                return;
            }
        }
    }

    function setSavingLocked( locked ) {
        var editor = wp.data.dispatch( 'core/editor' );
        if ( locked ) {
            editor.lockPostSaving( LOCK_NAME );
            editor.lockPostAutosaving( LOCK_NAME );
        } else {
            editor.unlockPostSaving( LOCK_NAME );
            editor.unlockPostAutosaving( LOCK_NAME );
        }
    }

    function repairBatch() {
        // Another batch started before this one's repair ran; it will repair both.
        if ( pending ) {
            return;
        }

        var current = getPostTags();
        var next    = unique( snapshot.concat( current, matched ) );
        var changed = ! isSameSet( current, next );

        if ( changed ) {
            wp.data.dispatch( 'core/editor' ).editPost( { tags: next } );
        }
        setSavingLocked( false );

        if ( ! refused.length ) {
            return;
        }

        var names = refused.slice();
        if ( ! next.length ) {
            removeRefusedPills( names );
            return;
        }

        // The field redraws its pills from the post's tags when they finish loading. If the
        // tags didn't change, ask for them again so that happens without a create request
        // for each remaining refused pill.
        if ( ! changed ) {
            wp.data.dispatch( 'core' ).invalidateResolution( 'getEntityRecords', getTermsRequest() );
        }
        setTimeout( function () {
            whenTermsLoaded( function () {
                setTimeout( function () { removeRefusedPills( names ); }, POLL_INTERVAL );
            }, POLL_ATTEMPTS );
        }, POLL_INTERVAL );
    }

    function settle() {
        pending--;
        if ( 0 === pending ) {
            // Let the tag field finish handling its own batch first.
            setTimeout( repairBatch, 0 );
        }
    }

    wp.apiFetch.use( function ( options, next ) {
        if ( 'POST' !== options.method || ! TAGS_PATH.test( options.path || '' ) ) {
            return next( options );
        }

        if ( 0 === pending ) {
            snapshot = getPostTags().slice();
            matched  = [];
            refused  = [];
            setSavingLocked( true );
        }
        pending++;

        var name   = options.data && options.data.name ? String( options.data.name ) : '';
        var result = next( options );

        result.then( settle, function ( error ) {
            if ( error && EXISTS_CODE === error.code && error.data && error.data.term_id ) {
                matched.push( error.data.term_id );
            } else if ( error && REFUSED_CODE === error.code ) {
                refused.push( name.trim().toLowerCase() );
            }
            settle();
        } );

        return result;
    } );
}() );
