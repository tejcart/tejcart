/**
 * TejCart Tier-2 frontend bundle.
 *
 * Wires the AJAX mini-cart endpoints to existing DOM nodes:
 *   - [data-tejcart-add]    : add-to-cart buttons
 *   - [data-tejcart-update] : qty inputs in the drawer
 *   - [data-tejcart-remove] : remove links in the drawer
 *
 * Replaces the cart drawer fragment in place after every mutation and
 * dispatches a `tejcart:cart-updated` event so other scripts can react.
 */
( function () {
    'use strict';

    if ( typeof window.tejcart_params === 'undefined' ) {
        return;
    }

    var params = window.tejcart_params;

    function request( action, body ) {
        var data = new FormData();
        data.append( 'action', action );
        data.append( 'nonce', params.nonce );
        Object.keys( body || {} ).forEach( function ( k ) {
            data.append( k, body[ k ] );
        } );

        return fetch( params.ajax_url, {
            method:      'POST',
            credentials: 'same-origin',
            body:        data,
        } ).then( function ( res ) {
            return res.json();
        } );
    }

    function applyFragments( fragments ) {
        if ( ! fragments ) {
            return;
        }
        Object.keys( fragments ).forEach( function ( selector ) {
            var nodes = document.querySelectorAll( selector );
            nodes.forEach( function ( node ) {
                var wrapper = document.createElement( 'div' );
                wrapper.innerHTML = fragments[ selector ];
                if ( wrapper.firstElementChild ) {
                    node.replaceWith( wrapper.firstElementChild );
                } else {
                    node.innerHTML = fragments[ selector ];
                }
            } );
        } );
    }

    function dispatch( payload ) {
        document.dispatchEvent( new CustomEvent( 'tejcart:cart-updated', { detail: payload } ) );
    }

    function handle( promise ) {
        return promise.then( function ( res ) {
            if ( res && res.success ) {
                applyFragments( res.data && res.data.fragments );
                dispatch( res.data );
            } else if ( res && res.data && res.data.message ) {
                console.warn( '[tejcart]', res.data.message );
            }
            return res;
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var addBtn = e.target.closest( '[data-tejcart-add]' );
        if ( addBtn ) {
            e.preventDefault();
            handle( request( 'tejcart_mini_cart_add', {
                product_id: addBtn.getAttribute( 'data-product-id' ) || addBtn.getAttribute( 'data-tejcart-add' ),
                quantity:   addBtn.getAttribute( 'data-quantity' ) || 1,
            } ) );
            return;
        }

        var rmBtn = e.target.closest( '[data-tejcart-remove]' );
        if ( rmBtn ) {
            e.preventDefault();
            handle( request( 'tejcart_mini_cart_remove', { key: rmBtn.getAttribute( 'data-tejcart-remove' ) } ) );
        }
    } );

    document.addEventListener( 'change', function ( e ) {
        var qty = e.target.closest( '[data-tejcart-update]' );
        if ( ! qty ) {
            return;
        }
        handle( request( 'tejcart_mini_cart_update', {
            key:      qty.getAttribute( 'data-tejcart-update' ),
            quantity: qty.value,
        } ) );
    } );
} )();
