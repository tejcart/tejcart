/**
 * TejCart — My Account sidebar navigation.
 *
 * Wires the mobile disclosure toggle that's hidden on desktop via
 * CSS (display:none above 960px). Keeps aria-expanded in sync with
 * the nav's data-collapsed attribute so screen readers announce
 * the state change accurately.
 *
 * Collapsed-by-default on first paint for narrow viewports.
 */
( function () {
    'use strict';

    var mqNarrow = window.matchMedia( '(max-width: 960px)' );

    function init() {
        var navs = document.querySelectorAll( '[data-tejcart-nav]' );
        Array.prototype.forEach.call( navs, bindNav );
    }

    function bindNav( nav ) {
        var toggle = nav.querySelector( '[data-tejcart-nav-toggle]' );
        if ( ! toggle ) {
            return;
        }

        setCollapsed( nav, toggle, mqNarrow.matches );

        toggle.addEventListener( 'click', function () {
            var collapsed = nav.getAttribute( 'data-collapsed' ) === 'true';
            setCollapsed( nav, toggle, ! collapsed );
        } );

        var onChange = function () {
            setCollapsed( nav, toggle, mqNarrow.matches );
        };
        if ( typeof mqNarrow.addEventListener === 'function' ) {
            mqNarrow.addEventListener( 'change', onChange );
        } else if ( typeof mqNarrow.addListener === 'function' ) {
            mqNarrow.addListener( onChange );
        }
    }

    function setCollapsed( nav, toggle, collapsed ) {
        nav.setAttribute( 'data-collapsed', collapsed ? 'true' : 'false' );
        toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
