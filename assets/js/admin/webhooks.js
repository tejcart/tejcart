/* global window, document, fetch, FormData */
( function () {
    'use strict';

    var settings = window.tejcartWebhooksSettings || {};
    var ajaxUrl  = settings.ajaxUrl || ( window.ajaxurl || '' );
    var i18n     = settings.i18n || {};

    document.addEventListener( 'DOMContentLoaded', function () {
        var links = document.querySelectorAll( '.tejcart-test-webhook' );
        if ( ! links.length || ! ajaxUrl ) {
            return;
        }

        links.forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var webhookId = this.dataset.webhookId;
                var nonce     = this.dataset.nonce;
                var el        = this;

                el.textContent = i18n.sending || 'Sending…';

                var formData = new FormData();
                formData.append( 'action', 'tejcart_test_webhook' );
                formData.append( 'webhook_id', webhookId );
                formData.append( 'nonce', nonce );

                fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( data ) {
                        if ( data && data.success ) {
                            el.textContent = data.data && data.data.message ? data.data.message : ( i18n.test || 'Test' );
                        } else {
                            var msg = data && data.data && data.data.message ? data.data.message : '';
                            el.textContent = ( i18n.failed || 'Failed' ) + ( msg ? ': ' + msg : '' );
                        }
                        setTimeout( function () { el.textContent = i18n.test || 'Test'; }, 3000 );
                    } )
                    .catch( function () {
                        el.textContent = i18n.error || 'Error';
                        setTimeout( function () { el.textContent = i18n.test || 'Test'; }, 3000 );
                    } );
            } );
        } );
    } );
}() );
