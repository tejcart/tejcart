/**
 * Single-product tab switcher.
 *
 * Progressive enhancement for templates/product/product-tabs.php.
 *
 * Without JS the tab panels render as stacked sections (CSS keeps them
 * visible). On init we mark the container `.is-enhanced`, hide the
 * non-active panels via the `hidden` attribute, and wire up click +
 * arrow-key navigation per the WAI-ARIA tabs pattern.
 *
 * Hash links such as #tejcart-tab-panel-reviews are honoured so the
 * "X reviews" anchor in the price header still scrolls to (and opens)
 * the reviews tab.
 */
( function () {
    'use strict';

    function activate( container, tabKey ) {
        var tabs = container.querySelectorAll( '[data-tejcart-tab]' );
        var panels = container.querySelectorAll( '[data-tejcart-tab-panel]' );

        tabs.forEach( function ( tab ) {
            var isActive = tab.getAttribute( 'data-tejcart-tab' ) === tabKey;
            tab.classList.toggle( 'is-active', isActive );
            tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
            tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
        } );

        panels.forEach( function ( panel ) {
            var isActive = panel.getAttribute( 'data-tejcart-tab-panel' ) === tabKey;
            panel.classList.toggle( 'is-active', isActive );
            if ( isActive ) {
                panel.removeAttribute( 'hidden' );
            } else {
                panel.setAttribute( 'hidden', '' );
            }
        } );
    }

    function focusTab( container, index ) {
        var tabs = container.querySelectorAll( '[data-tejcart-tab]' );
        if ( ! tabs.length ) {
            return;
        }
        var bounded = ( index + tabs.length ) % tabs.length;
        var target = tabs[ bounded ];
        activate( container, target.getAttribute( 'data-tejcart-tab' ) );
        target.focus();
    }

    function init( container ) {
        if ( container.dataset.tejcartTabsReady === '1' ) {
            return;
        }
        container.dataset.tejcartTabsReady = '1';
        container.classList.add( 'is-enhanced' );

        var tabs = container.querySelectorAll( '[data-tejcart-tab]' );
        if ( ! tabs.length ) {
            return;
        }

        var hash = ( window.location.hash || '' ).replace( /^#/, '' );
        var initialKey = null;

        if ( hash ) {
            container.querySelectorAll( '[data-tejcart-tab-panel]' ).forEach( function ( panel ) {
                if ( null !== initialKey ) {
                    return;
                }
                if ( panel.id === hash || panel.querySelector( '#' + CSS.escape( hash ) ) ) {
                    initialKey = panel.getAttribute( 'data-tejcart-tab-panel' );
                }
            } );
        }

        if ( null === initialKey ) {
            tabs.forEach( function ( tab ) {
                if ( null === initialKey && tab.classList.contains( 'is-active' ) ) {
                    initialKey = tab.getAttribute( 'data-tejcart-tab' );
                }
            } );
        }

        if ( null === initialKey ) {
            initialKey = tabs[0].getAttribute( 'data-tejcart-tab' );
        }

        activate( container, initialKey );

        tabs.forEach( function ( tab, index ) {
            tab.addEventListener( 'click', function ( event ) {
                event.preventDefault();
                activate( container, tab.getAttribute( 'data-tejcart-tab' ) );
            } );

            tab.addEventListener( 'keydown', function ( event ) {
                switch ( event.key ) {
                    case 'ArrowRight':
                    case 'ArrowDown':
                        event.preventDefault();
                        focusTab( container, index + 1 );
                        break;
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        event.preventDefault();
                        focusTab( container, index - 1 );
                        break;
                    case 'Home':
                        event.preventDefault();
                        focusTab( container, 0 );
                        break;
                    case 'End':
                        event.preventDefault();
                        focusTab( container, tabs.length - 1 );
                        break;
                    default:
                        break;
                }
            } );
        } );
    }

    function boot() {
        document.querySelectorAll( '[data-tejcart-product-tabs]' ).forEach( init );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
} )();
