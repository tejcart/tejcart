/* global window, document, fetch, FormData */
( function () {
    'use strict';

    var settings = window.tejcartOrderTrackingSettings || {};
    var ajax     = settings.ajaxUrl || '';
    var nonce    = settings.nonce || '';
    var i18n     = settings.i18n || {};

    function init() {
        if ( ! ajax || ! nonce ) {
            return;
        }
        document.querySelectorAll( '[data-tejcart-ot-test]' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var slug    = btn.getAttribute( 'data-tejcart-ot-test' );
                // Mode is optional on the button — when absent the server
                // falls back to whichever mode is currently saved.
                var mode    = btn.getAttribute( 'data-tejcart-ot-test-mode' ) || '';
                // Result span is keyed `<slug>-<mode>` when a mode is set,
                // otherwise the legacy `<slug>` selector still works for
                // 3rd-party renderers that haven't adopted the toggle.
                var resKey  = mode ? slug + '-' + mode : slug;
                var result  = document.querySelector( '[data-tejcart-ot-test-result="' + resKey + '"]' )
                              || document.querySelector( '[data-tejcart-ot-test-result="' + slug + '"]' );
                if ( result ) { result.textContent = '…'; result.style.color = ''; }
                btn.disabled = true;
                var fd = new FormData();
                fd.append( 'action', 'tejcart_tracking_test_provider' );
                fd.append( 'nonce', nonce );
                fd.append( 'provider', slug );
                if ( mode ) { fd.append( 'mode', mode ); }
                var inputs = document.querySelectorAll( 'input[name^="' + slug + '_"]' );
                inputs.forEach( function ( i ) { fd.append( i.name, i.value ); } );
                fetch( ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( payload ) {
                        btn.disabled = false;
                        if ( ! result ) { return; }
                        if ( payload && payload.success ) {
                            result.textContent = payload.data && payload.data.message ? payload.data.message : ( i18n.ok || 'OK' );
                            result.style.color = '#15803d';
                        } else {
                            result.textContent = payload && payload.data && payload.data.message ? payload.data.message : ( i18n.failed || 'Failed' );
                            result.style.color = '#b91c1c';
                        }
                    } )
                    .catch( function () {
                        btn.disabled = false;
                        if ( result ) {
                            result.textContent = i18n.network_error || 'Network error';
                            result.style.color = '#b91c1c';
                        }
                    } );
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
