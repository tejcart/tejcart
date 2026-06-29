/**
 * Quick-view modal client.
 *
 * Listens for clicks on `.tejcart-quick-view-trigger` buttons rendered
 * by `Quick_View::render_trigger()`, fetches the product card HTML from
 * the `tejcart_quick_view` AJAX endpoint, and opens a focus-trapping
 * dialog that returns focus to the trigger on close.
 *
 * No dependencies. Works alongside the existing `tejcart-cart.js`
 * AJAX add-to-cart wiring — the rendered add-to-cart form is the
 * canonical single-product form, so submit handlers from tejcart-cart
 * pick it up automatically.
 */
(function () {
    'use strict';

    var data = (typeof window !== 'undefined' && window.TejCartQuickView) || null;
    if ( ! data || ! data.ajaxUrl ) {
        return;
    }

    var i18n = data.i18n || {};
    var dialog = null;
    var dialogBody = null;
    var lastTrigger = null;
    var keydownBound = false;

    function ensureDialog() {
        if ( dialog ) {
            return dialog;
        }
        dialog = document.createElement( 'div' );
        dialog.className = 'tejcart-quick-view-dialog';
        dialog.setAttribute( 'role', 'dialog' );
        dialog.setAttribute( 'aria-modal', 'true' );
        dialog.setAttribute( 'aria-label', i18n.dialog || 'Quick product view' );
        dialog.setAttribute( 'hidden', 'hidden' );
        dialog.innerHTML =
            '<div class="tejcart-quick-view-dialog__backdrop" data-close="1"></div>' +
            '<div class="tejcart-quick-view-dialog__shell">' +
                '<button type="button" class="tejcart-quick-view-dialog__close" aria-label="' + escapeAttr( i18n.close || 'Close' ) + '" data-close="1"></button>' +
                '<div class="tejcart-quick-view-dialog__body" role="document" tabindex="-1"></div>' +
            '</div>';
        document.body.appendChild( dialog );
        dialogBody = dialog.querySelector( '.tejcart-quick-view-dialog__body' );

        dialog.addEventListener( 'click', function ( evt ) {
            var target = evt.target;
            if ( target && target.getAttribute && target.getAttribute( 'data-close' ) === '1' ) {
                closeDialog();
            }
        } );

        return dialog;
    }

    function escapeAttr( value ) {
        return String( value ).replace( /[&<>"']/g, function ( ch ) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ ch ];
        } );
    }

    function openDialog( trigger ) {
        ensureDialog();
        lastTrigger = trigger || null;
        dialog.removeAttribute( 'hidden' );
        document.documentElement.classList.add( 'tejcart-quick-view-open' );

        requestAnimationFrame( function () {
            requestAnimationFrame( function () {
                dialog.classList.add( 'is-visible' );
            } );
        } );

        if ( ! keydownBound ) {
            document.addEventListener( 'keydown', onKeydown );
            keydownBound = true;
        }
        var closeBtn = dialog.querySelector( '.tejcart-quick-view-dialog__close' );
        if ( closeBtn ) {
            closeBtn.focus();
        }
    }

    function closeDialog() {
        if ( ! dialog || dialog.hasAttribute( 'hidden' ) ) {
            return;
        }
        dialog.classList.remove( 'is-visible' );

        var shell = dialog.querySelector( '.tejcart-quick-view-dialog__shell' );
        var cleanup = function () {
            dialog.setAttribute( 'hidden', 'hidden' );
            document.documentElement.classList.remove( 'tejcart-quick-view-open' );
            if ( dialogBody ) {
                dialogBody.innerHTML = '';
            }
            if ( lastTrigger && typeof lastTrigger.focus === 'function' ) {
                lastTrigger.focus();
            }
            lastTrigger = null;
        };

        if ( shell ) {
            shell.addEventListener( 'transitionend', function handler( evt ) {
                if ( evt.target === shell ) {
                    shell.removeEventListener( 'transitionend', handler );
                    cleanup();
                }
            } );
            setTimeout( cleanup, 400 );
        } else {
            cleanup();
        }

        if ( keydownBound ) {
            document.removeEventListener( 'keydown', onKeydown );
            keydownBound = false;
        }
    }

    function onKeydown( evt ) {
        if ( evt.key === 'Escape' ) {
            evt.preventDefault();
            closeDialog();
            return;
        }
        if ( evt.key === 'Tab' && dialog && ! dialog.hasAttribute( 'hidden' ) ) {
            trapFocus( evt );
        }
    }

    function trapFocus( evt ) {
        var focusables = dialog.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if ( ! focusables.length ) {
            return;
        }
        var first = focusables[ 0 ];
        var last = focusables[ focusables.length - 1 ];
        if ( evt.shiftKey && document.activeElement === first ) {
            evt.preventDefault();
            last.focus();
        } else if ( ! evt.shiftKey && document.activeElement === last ) {
            evt.preventDefault();
            first.focus();
        }
    }

    function setBodyMessage( message ) {
        ensureDialog();
        dialogBody.innerHTML = '<p class="tejcart-quick-view-dialog__status">' + escapeAttr( message ) + '</p>';
    }

    function fetchQuickView( productId, trigger ) {
        openDialog( trigger );
        setBodyMessage( i18n.loading || 'Loading…' );

        var body = 'action=tejcart_quick_view&nonce=' + encodeURIComponent( data.nonce || '' ) + '&product_id=' + encodeURIComponent( productId );

        fetch( data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        } ).then( function ( res ) {
            return res.json().catch( function () { return { success: false }; } );
        } ).then( function ( payload ) {
            if ( ! payload || ! payload.success || ! payload.data || typeof payload.data.html !== 'string' ) {
                setBodyMessage( ( payload && payload.data && payload.data.message ) || i18n.error || 'Could not load this product.' );
                return;
            }
            dialogBody.innerHTML = payload.data.html;

            // Let scripts that wire up dynamic markup (variation forms,
            // swatches, etc.) initialise the freshly injected content.
            document.dispatchEvent( new CustomEvent( 'tejcart_content_loaded', {
                bubbles: true,
                detail: { container: dialogBody }
            } ) );

            var focusables = dialogBody.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled])'
            );
            if ( focusables.length ) {
                focusables[ 0 ].focus();
            }
        } ).catch( function () {
            setBodyMessage( i18n.error || 'Could not load this product.' );
        } );
    }

    document.addEventListener( 'click', function ( evt ) {
        var target = evt.target;
        while ( target && target !== document ) {
            if ( target.classList && target.classList.contains( 'tejcart-quick-view-trigger' ) ) {
                evt.preventDefault();
                var productId = parseInt( target.getAttribute( 'data-product-id' ), 10 );
                if ( productId > 0 ) {
                    fetchQuickView( productId, target );
                }
                return;
            }
            target = target.parentNode;
        }
    } );
})();
