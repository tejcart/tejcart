/**
 * TejCart Admin — dynamic Country → State sync (multi-pair).
 *
 * Tagging convention: each pair shares a `data-tejcart-state-pair` token.
 * Apply it to the country `<select>` AND to the state field (either an
 * `<input type="text">` or a `<select>`). The script wires the country's
 * `change` event to swap the matching state field between:
 *   - a `<select>` populated from the bundled states dataset, when the
 *     newly-selected country has entries; or
 *   - the original `<input type="text">`, when it does not (free text so
 *     merchants can still type "Yorkshire" for a country we don't list).
 *
 * The dataset arrives via `tejcart_admin_settings_locale.states`
 * (country code => state code => state name), localised by
 * Admin::enqueue_assets().
 *
 * Multiple pairs can coexist on one page (e.g. Tax Rates editor plus the
 * order-admin "billing address" form) without leaking state between them
 * because the swap is scoped by the `data-tejcart-state-pair` token.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    var data       = window.tejcart_admin_settings_locale || {};
    var STATES     = data.states || {};
    var TEXT_CLASS = 'tejcart-settings-state-text';
    var SEL_CLASS  = 'tejcart-settings-state-select';

    function statesFor( countryCode ) {
        if ( ! countryCode || typeof STATES !== 'object' ) { return {}; }
        return STATES[ countryCode ] || {};
    }

    function swapStateField( current, countryCode ) {
        if ( ! current || ! current.parentNode ) { return current; }

        var parent      = current.parentNode;
        var states      = statesFor( countryCode );
        var stateKeys   = Object.keys( states );
        var currentVal  = current.value || '';
        var labelSelect = data.i18n && data.i18n.selectState ? data.i18n.selectState : '— Select a state —';
        var pairToken   = current.getAttribute( 'data-tejcart-state-pair' ) || '';

        // Carry forward identifiers, accessibility hooks and any caller-
        // supplied placeholder so the rebuilt control behaves the same to
        // surrounding code (form serialisation, labels, validators).
        var nextId       = current.id || '';
        var nextName     = current.getAttribute( 'name' ) || '';
        var ariaLabelled = current.getAttribute( 'aria-labelledby' ) || '';
        var placeholder  = current.getAttribute( 'placeholder' ) || '';
        var existingCls  = ( current.getAttribute( 'class' ) || '' )
            .split( /\s+/ )
            .filter( function ( c ) { return c && c !== TEXT_CLASS && c !== SEL_CLASS; } )
            .join( ' ' );

        var next;
        if ( stateKeys.length > 0 ) {
            next = document.createElement( 'select' );
            next.className = ( existingCls + ' ' + SEL_CLASS ).trim();

            var placeholderOpt = document.createElement( 'option' );
            placeholderOpt.value       = '';
            placeholderOpt.textContent = labelSelect;
            next.appendChild( placeholderOpt );

            stateKeys.forEach( function ( code ) {
                var opt = document.createElement( 'option' );
                opt.value       = code;
                opt.textContent = states[ code ];
                if ( code === currentVal ) { opt.selected = true; }
                next.appendChild( opt );
            } );
        } else {
            next = document.createElement( 'input' );
            next.type        = 'text';
            next.className   = ( existingCls + ' ' + TEXT_CLASS ).trim();
            // Drop the value if it was a code from the previous country's
            // state list — it would be nonsense in the new country.
            next.value       = currentVal;
            next.placeholder = placeholder;
        }

        if ( nextId )       { next.id   = nextId; }
        if ( nextName )     { next.name = nextName; }
        if ( ariaLabelled ) { next.setAttribute( 'aria-labelledby', ariaLabelled ); }
        if ( pairToken )    { next.setAttribute( 'data-tejcart-state-pair', pairToken ); }

        parent.replaceChild( next, current );
        return next;
    }

    function bindPair( countrySelect ) {
        var pair = countrySelect.getAttribute( 'data-tejcart-state-pair' );
        if ( ! pair ) { return; }

        countrySelect.addEventListener( 'change', function () {
            // Re-query each time so we always pick up the live element
            // (the swap above replaces it on every country change).
            var stateField = document.querySelector(
                '[data-tejcart-state-pair="' + cssEscape( pair ) + '"]:not(select.tejcart-country-select)'
            );
            // Filter out the country select itself — same pair token,
            // distinguished by element role.
            if ( stateField === countrySelect ) {
                var siblings = document.querySelectorAll(
                    '[data-tejcart-state-pair="' + cssEscape( pair ) + '"]'
                );
                stateField = null;
                siblings.forEach( function ( el ) {
                    if ( el !== countrySelect ) { stateField = el; }
                } );
            }
            if ( stateField ) {
                swapStateField( stateField, countrySelect.value );
            }
        } );
    }

    // Minimal CSS.escape polyfill so we can use attribute selectors with
    // values that may contain quotes / colons safely. Native CSS.escape
    // is widely supported but defensive-code the fallback. The pair
    // tokens we emit are simple ASCII (e.g. "store", "tax_rate", "new_order_billing")
    // so a minimal escape of `"` and `\` is sufficient.
    function cssEscape( s ) {
        if ( window.CSS && typeof window.CSS.escape === 'function' ) {
            return window.CSS.escape( s );
        }
        return String( s ).replace( /["\\]/g, '\\$&' );
    }

    function init() {
        // New convention: any country select tagged with data-tejcart-state-pair.
        document.querySelectorAll( 'select[data-tejcart-state-pair]' ).forEach( function ( sel ) {
            // Country selects are tagged with `.tejcart-country-select`.
            // State <select>s share the same pair token but lack that
            // class, which is how we tell them apart.
            if ( sel.classList.contains( 'tejcart-country-select' ) ) {
                bindPair( sel );
            }
        } );

        // Backward compatibility: the General settings tab pre-dates the
        // data-attribute pattern and tags its country select with
        // .tejcart-settings-country-select. If it's still on the page
        // without a pair token, treat it as the implicit "store" pair.
        var legacy = document.querySelector( 'select.tejcart-settings-country-select:not([data-tejcart-state-pair])' );
        var legacyState = document.getElementById( 'tejcart_store_state' );
        if ( legacy && legacyState ) {
            legacy.setAttribute( 'data-tejcart-state-pair', 'store' );
            legacy.classList.add( 'tejcart-country-select' );
            if ( ! legacyState.hasAttribute( 'data-tejcart-state-pair' ) ) {
                legacyState.setAttribute( 'data-tejcart-state-pair', 'store' );
            }
            bindPair( legacy );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
