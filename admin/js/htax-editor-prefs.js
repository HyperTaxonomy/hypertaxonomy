/**
 * Post editor display preferences — five per-user toggles in the More (···) menu.
 *
 * Preferences are stored in the Gutenberg core/preferences store under the
 * htax/post-edit scope and persist across sessions per user. Changes take
 * effect immediately because htax-post-edit.js reads preferences via
 * wp.data.select() inside its wp.data.subscribe() callback.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */
( function () {
    'use strict';

    if ( ! window.wp || ! wp.plugins || ! wp.element || ! wp.data ) { return; }

    var registerPlugin     = wp.plugins.registerPlugin;
    var el                 = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var useSelect          = wp.data.useSelect;
    var useDispatch        = wp.data.useDispatch;
    var PluginMoreMenuItem = wp.editPost && wp.editPost.PluginMoreMenuItem;

    if ( ! PluginMoreMenuItem ) { return; }

    var checkIcon = ( window.wp && wp.components && wp.icons && wp.icons.check )
        ? wp.element.createElement( wp.components.Icon, { icon: wp.icons.check, size: 24 } )
        : wp.element.createElement( 'svg', {
            xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24,
        }, wp.element.createElement( 'path', {
            d: 'm10 15.586-3.293-3.293-1.414 1.414L10 18.414l9.707-9.707-1.414-1.414z',
        } ) );

    var SCOPE = 'htax/post-edit';

    // Defaults match the previous global-setting defaults (redirects and
    // visibility were opt-out; inclusions, containers, sticky were opt-in).
    var DEFAULTS = {
        inclusions: false,
        containers: false,
        redirects:  true,
        visibility: true,
        sticky:     false,
    };

    // Register defaults so get() returns the right value before the user has
    // ever toggled a preference (setDefaults available WP 6.0+ / Gutenberg 14+).
    var prefsDispatch = wp.data.dispatch( 'core/preferences' );
    if ( prefsDispatch && prefsDispatch.setDefaults ) {
        prefsDispatch.setDefaults( SCOPE, DEFAULTS );
    }

    var ITEMS = [
        {
            key:   'inclusions',
            label: 'Inclusion Badges',
            info:  'Show count of effective included terms for a given term (⇉N)',
        },
        {
            key:   'containers',
            label: 'Container Badges',
            info:  'Show count of effective container terms for a given term (⇇N)',
        },
        {
            key:   'redirects',
            label: 'Redirect Indicators',
            info:  'Italicize redirecting terms and mark them with a ↪ symbol',
        },
        {
            key:   'visibility',
            label: 'Hidden Terms',
            info:  'Fade terms that are configured as hidden',
        },
        {
            key:   'sticky',
            label: 'Sticky Pushpins',
            info:  'Pin or unpin the post to a term’s archive without leaving the editor',
        },
    ];

    function HtaxEditorPrefs() {
        var prefs = useSelect( function ( select ) {
            var store = select( 'core/preferences' );
            if ( ! store || ! store.get ) { return DEFAULTS; }
            var result = {};
            ITEMS.forEach( function ( item ) {
                var val = store.get( SCOPE, item.key );
                result[ item.key ] = val !== undefined ? val : DEFAULTS[ item.key ];
            } );
            return result;
        } );

        var dispatch = useDispatch( 'core/preferences' );

        return el( Fragment, null,
            ITEMS.map( function ( item ) {
                var active = !! prefs[ item.key ];
                return el( PluginMoreMenuItem, {
                    key:     item.key,
                    role:    'menuitemcheckbox',
                    icon:    active ? checkIcon : null,
                    info:    item.info,
                    onClick: function () {
                        dispatch.set( SCOPE, item.key, ! active );
                    },
                }, item.label );
            } )
        );
    }

    // icon: null prevents the default plugin socket icon from appearing next
    // to each menu item; the active state is shown via the explicit 'yes' icon.
    registerPlugin( 'htax-editor-prefs', { render: HtaxEditorPrefs, icon: null } );

    // One-time callout: surface a Gutenberg notice on first visit so users
    // discover the ··· menu indicators without having to read documentation.
    var cfg = window.htaxEditorPrefsConfig;
    if ( cfg && cfg.showCallout && wp.notices && wp.domReady ) {
        wp.domReady( function () {
            var noticesDispatch = wp.data.dispatch( 'core/notices' );
            if ( ! noticesDispatch ) { return; }
            noticesDispatch.createNotice(
                'info',
                'HyperTaxonomy: relationship indicators (Inclusion Badges, Container Badges, Redirect Indicators, Hidden Terms, Sticky Pushpins) are available in the ··· menu → HyperTaxonomy group. Each toggle is per-user and takes effect immediately.',
                {
                    id:            'htax-prefs-callout',
                    isDismissible: true,
                    actions: [ {
                        label:   'Got it',
                        onClick: function () {
                            wp.data.dispatch( 'core/notices' ).removeNotice( 'htax-prefs-callout' );
                            window.fetch( cfg.ajaxUrl, {
                                method:  'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body:    'action=htax_dismiss_prefs_callout&nonce=' + encodeURIComponent( cfg.dismissNonce ),
                            } );
                        },
                    } ],
                }
            );
        } );
    }
} )();
