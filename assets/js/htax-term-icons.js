jQuery( document ).ready( function ( $ ) {
    var targetW    = parseInt( HTAX_Icons.target_w, 10 );
    var targetH    = parseInt( HTAX_Icons.target_h, 10 );
    var mediaFrame = null;
    var libraryFilterApplied = false;

    // ── Preview ───────────────────────────────────────────────────────────────

    function setIconPreview( url, attachmentId ) {
        $( '#htax-icon-id' ).val( attachmentId );
        $( '#htax-icon-preview' ).show().empty().append(
            $( '<img>' ).attr( {
                src:    url,
                alt:    '',
                width:  HTAX_Icons.preview_w,
                height: HTAX_Icons.preview_h,
                'data-taxonomy': HTAX_Icons.taxonomy || '',
                'class': 'htax-term-icon'
            } )
        );
        $( '#htax-upload-icon' ).text( HTAX_Icons.replace_label );
        $( '#htax-remove-icon' ).show();
        $( '#htax-icon-size-error' ).hide();
    }

    // ── Error below the upload button ─────────────────────────────────────────

    function showError( message ) {
        var $err = $( '#htax-icon-size-error' );
        if ( ! $err.length ) {
            $err = $( '<p id="htax-icon-size-error" class="htax-icon-error" role="alert"></p>' )
                .insertAfter( '#htax-remove-icon' );
        }
        $err.text( message ).show();
    }

    // ── Upload icon button ────────────────────────────────────────────────────

    $( '#htax-upload-icon' ).on( 'click', function ( e ) {
        e.preventDefault();

        if ( mediaFrame ) {
            libraryFilterApplied = false; // re-apply filter on next open
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media( {
            title:    HTAX_Icons.upload_title,
            button:   { text: HTAX_Icons.upload_button },
            library:  { type: [ 'image', 'image/svg+xml' ] },
            multiple: false,
        } );

        // Filter the library: intercept additions before Backbone renders them.
        // Override library.add so too-small images never enter the collection.
        mediaFrame.on( 'open', function () {
            if ( libraryFilterApplied ) {
                return;
            }
            libraryFilterApplied = true;

            var state = mediaFrame.state();
            if ( ! state || ! state.get ) {
                return;
            }
            var library = state.get( 'library' );
            if ( ! library ) {
                return;
            }

            var _origAdd = library.add.bind( library );
            library.add = function ( models, options ) {
                var arr = Array.isArray( models ) ? models : [ models ];
                arr = arr.filter( function ( m ) {
                    var attrs = m.attributes || m;
                    var mime  = attrs.mime || attrs.mime_type || '';
                    // SVGs have no raster dimensions — always allowed.
                    if ( mime === 'image/svg+xml' ) {
                        return true;
                    }
                    var w = attrs.width  || 0;
                    var h = attrs.height || 0;
                    // Dimensions unknown (0) — allow through; catch at selection time.
                    if ( w === 0 && h === 0 ) {
                        return true;
                    }
                    return w >= targetW && h >= targetH;
                } );
                return _origAdd( arr, options );
            };
        } );

        mediaFrame.on( 'select', function () {
            var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
            handleSelection( attachment );
        } );

        mediaFrame.open();
    } );

    // ── Selection handler ─────────────────────────────────────────────────────

    function handleSelection( attachment ) {
        var mime = attachment.mime || attachment.mime_type || '';

        // SVG — no size requirement (resolution-independent), but check aspect ratio
        // when WordPress can read the SVG's natural dimensions from viewBox/attributes.
        if ( mime === 'image/svg+xml' ) {
            var svgW = attachment.width  || 0;
            var svgH = attachment.height || 0;
            if ( svgW > 0 && svgH > 0 ) {
                var svgRatio    = svgW / svgH;
                var targetRatio = targetW / targetH;
                if ( Math.abs( svgRatio - targetRatio ) > 0.02 ) {
                    var warning = HTAX_Icons.svg_ratio_warning
                        .replace( '%1$d', svgW ).replace( '%2$d', svgH )
                        .replace( '%3$d', targetW ).replace( '%4$d', targetH );
                    if ( ! window.confirm( warning ) ) {
                        return; // cancelled — previous icon unchanged
                    }
                }
            }
            setIconPreview( attachment.url, attachment.id );
            return;
        }

        var w = attachment.width  || 0;
        var h = attachment.height || 0;

        // Too small — reject.
        if ( ( w > 0 && w < targetW ) || ( h > 0 && h < targetH ) ) {
            if ( mediaFrame ) {
                mediaFrame.close();
            }
            showError( HTAX_Icons.too_small );
            return;
        }

        // Exact match — accept as-is.
        if ( w === targetW && h === targetH ) {
            var thumbUrl = ( attachment.sizes && attachment.sizes.thumbnail )
                ? attachment.sizes.thumbnail.url
                : attachment.url;
            setIconPreview( thumbUrl, attachment.id );
            return;
        }

        // Larger in at least one dimension — open crop dialog.
        // Previous icon is left untouched until the user confirms the crop.
        if ( mediaFrame ) {
            mediaFrame.close();
        }
        openCropDialog( attachment );
    }

    // ── Crop dialog ───────────────────────────────────────────────────────────

    function buildCropModal() {
        if ( $( '#htax-crop-modal' ).length ) {
            return;
        }
        $( 'body' ).append(
            '<div id="htax-crop-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="htax-crop-title">' +
                '<div id="htax-crop-backdrop"></div>' +
                '<div id="htax-crop-dialog">' +
                    '<h2 id="htax-crop-title">' + HTAX_Icons.crop_title + '</h2>' +
                    '<p id="htax-crop-instructions"></p>' +
                    '<div id="htax-crop-img-wrap"><img id="htax-crop-img" alt="" /></div>' +
                    '<div id="htax-crop-actions">' +
                        '<button type="button" class="button button-primary" id="htax-crop-confirm">' + HTAX_Icons.crop_button + '</button>' +
                        ' <button type="button" class="button" id="htax-crop-cancel">' + HTAX_Icons.crop_cancel + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        $( '#htax-crop-cancel, #htax-crop-backdrop' ).on( 'click', closeCropDialog );
        $( document ).on( 'keydown.htaxcrop', function ( e ) {
            if ( e.key === 'Escape' && $( '#htax-crop-modal' ).is( ':visible' ) ) {
                closeCropDialog();
            }
        } );
    }

    function openCropDialog( attachment ) {
        buildCropModal();

        var actualW  = attachment.width;
        var actualH  = attachment.height;
        var cropData = null;

        var $img = $( '#htax-crop-img' );
        $img.removeAttr( 'width height' ).attr( 'src', attachment.url );

        var instructions = HTAX_Icons.crop_instructions
            .replace( '%1$d', targetW )
            .replace( '%2$d', targetH );
        $( '#htax-crop-instructions' ).text( instructions );

        function initSelection() {
            var displayW = $img.width();
            var displayH = $img.height();

            if ( ! displayW || ! displayH ) {
                return;
            }

            var scaleX = displayW / actualW;
            var scaleY = displayH / actualH;
            var selW   = Math.round( targetW * scaleX );
            var selH   = Math.round( targetH * scaleY );
            var initX  = Math.round( ( displayW - selW ) / 2 );
            var initY  = Math.round( ( displayH - selH ) / 2 );

            // Clamp initial position so the fixed rectangle stays within bounds.
            initX = Math.max( 0, Math.min( initX, displayW - selW ) );
            initY = Math.max( 0, Math.min( initY, displayH - selH ) );

            cropData = {
                x: Math.round( initX / scaleX ),
                y: Math.round( initY / scaleY ),
                w: targetW,
                h: targetH,
            };

            $img.imgAreaSelect( {
                handles:        false,
                minWidth:       selW,
                maxWidth:       selW,
                minHeight:      selH,
                maxHeight:      selH,
                x1:             initX,
                y1:             initY,
                x2:             initX + selW,
                y2:             initY + selH,
                onSelectChange: function ( img, sel ) {
                    cropData = {
                        x: Math.round( sel.x1 / scaleX ),
                        y: Math.round( sel.y1 / scaleY ),
                        w: targetW,
                        h: targetH,
                    };
                },
            } );
        }

        // Re-bind load handler; remove any previous.
        $img.off( 'load.htaxcrop' ).on( 'load.htaxcrop', initSelection );

        // Image may already be cached.
        if ( $img[0].complete && $img[0].naturalWidth ) {
            initSelection();
        }

        $( '#htax-crop-confirm' ).off( 'click.htaxcrop' ).on( 'click.htaxcrop', function () {
            if ( ! cropData ) {
                return;
            }
            var $btn = $( this );
            $btn.prop( 'disabled', true ).text( HTAX_Icons.crop_saving );

            $.post( HTAX_Icons.ajax_url, {
                action: 'htax_crop_term_icon',
                nonce:  HTAX_Icons.nonce,
                id:     attachment.id,
                x:      cropData.x,
                y:      cropData.y,
                w:      cropData.w,
                h:      cropData.h,
                dst_w:  targetW,
                dst_h:  targetH,
            } )
            .done( function ( response ) {
                closeCropDialog();
                if ( response.success ) {
                    setIconPreview( response.data.thumbnail_url, response.data.id );
                } else {
                    showError( ( response.data && response.data.message ) || HTAX_Icons.crop_error );
                }
            } )
            .fail( function () {
                closeCropDialog();
                showError( HTAX_Icons.crop_error );
            } );
        } );

        $( '#htax-crop-modal' ).show();
        $( '#htax-crop-confirm' ).focus();
    }

    function closeCropDialog() {
        $( '#htax-crop-img' ).imgAreaSelect( { remove: true } );
        $( '#htax-crop-modal' ).hide();
        $( '#htax-crop-confirm' ).prop( 'disabled', false ).text( HTAX_Icons.crop_button );
        $( document ).off( 'keydown.htaxcrop' );
    }

    // ── Remove icon ───────────────────────────────────────────────────────────

    $( '#htax-remove-icon' ).on( 'click', function ( e ) {
        e.preventDefault();
        if ( confirm( HTAX_Icons.remove_confirm ) ) {
            $( '#htax-icon-id' ).val( '' );
            $( '#htax-icon-preview' ).empty().hide();
            $( '#htax-icon-size-error' ).hide();
            $( '#htax-upload-icon' ).text( HTAX_Icons.upload_label );
            $( this ).hide();
        }
    } );
} );
