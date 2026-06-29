/**
 * TejCart Account — billing / shipping address edit form.
 *
 * Mirrors the checkout's dynamic Country → State sync on the My Account
 * "Addresses" tab so the customer gets the same dropdown experience
 * (with subdivisions populated from the same dataset) when editing a
 * saved billing or shipping address.
 *
 * Vanilla ES6, no jQuery. Operates on any [data-tejcart-address-scope]
 * container so the wiring is self-contained per form.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    var params = window.tejcart_account_address_params || {};

    var STATELESS_FALLBACK = [ 'SG', 'HK', 'MO', 'BH', 'QA', 'KW', 'LU', 'MT', 'AD', 'MC' ];

    function getStatesFor( countryCode ) {
        if ( ! countryCode ) { return {}; }
        var all = params.states || {};
        return all[ countryCode ] || {};
    }

    function getCountryLabel( countrySelect, countryCode ) {
        if ( ! countryCode || ! countrySelect ) { return ''; }
        var match = countrySelect.querySelector( 'option[value="' + countryCode + '"]' );
        return match ? match.textContent : '';
    }

    function replaceStateField( wrapper, countrySelect, countryCode ) {
        if ( ! wrapper ) { return; }
        var existing = wrapper.querySelector( 'input, select' );
        if ( ! existing ) { return; }

        var fieldKey        = wrapper.getAttribute( 'data-tejcart-state-field' ) || existing.name;
        var states          = getStatesFor( countryCode );
        var stateCodes      = Object.keys( states );
        var currentVal      = existing.value || '';
        var required        = existing.hasAttribute( 'required' );
        var autocomplete    = existing.getAttribute( 'autocomplete' ) || '';
        var ariaDescribedBy = existing.getAttribute( 'aria-describedby' ) || '';
        var className       = existing.className || 'tejcart-account-input';

        if ( countryCode && stateCodes.length === 0 && STATELESS_FALLBACK.indexOf( countryCode ) !== -1 ) {
            wrapper.setAttribute( 'hidden', 'hidden' );
            wrapper.classList.add( 'is-stateless-hidden' );
            existing.removeAttribute( 'required' );
            existing.removeAttribute( 'aria-required' );
            return;
        }
        wrapper.removeAttribute( 'hidden' );
        wrapper.classList.remove( 'is-stateless-hidden' );

        var countryLabel = getCountryLabel( countrySelect, countryCode );
        var placeholderText;
        if ( countryLabel && params.i18n && params.i18n.select_country_state ) {
            placeholderText = params.i18n.select_country_state.replace( '%s', countryLabel );
        } else if ( params.i18n && params.i18n.select_state ) {
            placeholderText = params.i18n.select_state;
        } else {
            placeholderText = 'Select a state / province';
        }

        if ( stateCodes.length > 0 ) {
            var select;
            if ( existing.tagName.toLowerCase() === 'select' ) {
                select = existing;
                select.innerHTML = '';
            } else {
                select = document.createElement( 'select' );
                select.id        = existing.id || fieldKey;
                select.name      = fieldKey;
                select.className = className.replace( /tejcart-account-input/, 'tejcart-account-select' );
                if ( ! /tejcart-account-select/.test( select.className ) ) {
                    select.className = ( select.className + ' tejcart-account-select' ).trim();
                }
                if ( required ) {
                    select.setAttribute( 'required', 'required' );
                    select.setAttribute( 'aria-required', 'true' );
                }
                if ( autocomplete ) { select.setAttribute( 'autocomplete', autocomplete ); }
                if ( ariaDescribedBy ) { select.setAttribute( 'aria-describedby', ariaDescribedBy ); }
                existing.parentNode.replaceChild( select, existing );
            }

            var placeholder = document.createElement( 'option' );
            placeholder.value       = '';
            placeholder.textContent = placeholderText;
            select.appendChild( placeholder );

            stateCodes.forEach( function ( code ) {
                var opt = document.createElement( 'option' );
                opt.value       = code;
                opt.textContent = states[ code ];
                if ( code === currentVal ) { opt.selected = true; }
                select.appendChild( opt );
            } );
            return;
        }

        if ( existing.tagName.toLowerCase() === 'select' ) {
            var input = document.createElement( 'input' );
            input.type      = 'text';
            input.id        = existing.id || fieldKey;
            input.name      = fieldKey;
            input.className = className.replace( /tejcart-account-select/, 'tejcart-account-input' );
            if ( ! /tejcart-account-input/.test( input.className ) ) {
                input.className = ( input.className + ' tejcart-account-input' ).trim();
            }
            input.value = currentVal;
            if ( required ) {
                input.setAttribute( 'required', 'required' );
                input.setAttribute( 'aria-required', 'true' );
            }
            if ( autocomplete ) { input.setAttribute( 'autocomplete', autocomplete ); }
            if ( ariaDescribedBy ) { input.setAttribute( 'aria-describedby', ariaDescribedBy ); }
            existing.parentNode.replaceChild( input, existing );
        }
    }

    function initScope( scope ) {
        var countryWrapper = scope.querySelector( '[data-tejcart-country-field]' );
        var stateWrapper   = scope.querySelector( '[data-tejcart-state-field]' );
        if ( ! countryWrapper || ! stateWrapper ) { return; }

        var countrySelect = countryWrapper.querySelector( 'select' );
        if ( ! countrySelect ) { return; }

        replaceStateField( stateWrapper, countrySelect, countrySelect.value );

        countrySelect.addEventListener( 'change', function () {
            replaceStateField( stateWrapper, countrySelect, countrySelect.value );
        } );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        var scopes = document.querySelectorAll( '[data-tejcart-address-scope]' );
        scopes.forEach( initScope );
    } );
} )();
