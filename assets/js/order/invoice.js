/* global window, document */
( function () {
    'use strict';

    function init() {
        var btn = document.getElementById( 'tejcart-invoice-print' );
        if ( ! btn ) {
            return;
        }
        btn.addEventListener( 'click', function () {
            window.print();
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
