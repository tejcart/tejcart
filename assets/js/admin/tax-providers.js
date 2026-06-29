/**
 * TejCart Tax Providers admin UI.
 *
 * Wires the providers list — AJAX enable/disable, AJAX make-active,
 * and the in-page search filter. Plain jQuery on purpose, mirroring
 * how `modules/shipping/assets/js/admin-carriers.js` is built: jQuery
 * is already pulled in by core, and avoiding a build pipeline keeps
 * the asset map in sync with the no-Composer-runtime posture for the
 * wp.org core zip.
 */
( function ( $ ) {
    'use strict';

    if ( ! window.tejcartTaxProviders ) {
        return;
    }

    var settings = window.tejcartTaxProviders;
    var i18n     = settings.i18n || {};

    function flash( $row, message, tone ) {
        var $status = $row.find( '.tejcart-tax-provider-row__status-text' );
        if ( $status.length && message ) {
            $status.text( message );
        }
        if ( $row.data( 'flashTimer' ) ) {
            clearTimeout( $row.data( 'flashTimer' ) );
        }
        $row.addClass( 'is-flashing is-flashing--' + tone );
        var timer = setTimeout( function () {
            $row.removeClass( 'is-flashing is-flashing--success is-flashing--error' );
        }, 1600 );
        $row.data( 'flashTimer', timer );
    }

    /**
     * Inline AJAX enable/disable for tax providers. Bound on `change`
     * so keyboard activation works too.
     */
    $( document ).on( 'change', '.tejcart-tax-provider-toggle-input', function () {
        var $input   = $( this );
        var $label   = $input.closest( '.tejcart-tax-provider-toggle' );
        var $row     = $input.closest( '.tejcart-payment-method-row' );
        var $wrap    = $input.closest( '[data-toggle-nonce]' );
        var provider = $input.data( 'provider-id' );
        var enabled  = $input.is( ':checked' );
        var nonce    = $wrap.data( 'toggle-nonce' );

        if ( ! provider || ! nonce ) {
            return;
        }

        $label.addClass( 'is-loading' );

        $.ajax( {
            url:      settings.ajaxUrl,
            type:     'POST',
            dataType: 'json',
            data:     {
                action:      settings.toggleAction,
                provider_id: provider,
                enabled:     enabled ? '1' : '0',
                nonce:       nonce
            }
        } ).done( function ( response ) {
            if ( response && response.success ) {
                if ( $row.length ) {
                    $row.toggleClass( 'is-enabled', enabled );
                    if ( response.data && response.data.surrendered_active ) {
                        $row.removeClass( 'is-active' );
                    }
                    flash(
                        $row,
                        enabled ? ( i18n.enabled || 'Provider enabled.' )
                                : ( i18n.disabled || 'Provider paused.' ),
                        'success'
                    );
                }
            } else {
                $input.prop( 'checked', ! enabled );
                if ( $row.length ) {
                    flash(
                        $row,
                        ( response && response.data && response.data.message ) || ( i18n.toggleError || 'Could not update provider.' ),
                        'error'
                    );
                }
            }
        } ).fail( function () {
            $input.prop( 'checked', ! enabled );
            if ( $row.length ) {
                flash( $row, i18n.networkError || 'Network error. Please retry.', 'error' );
            }
        } ).always( function () {
            $label.removeClass( 'is-loading' );
        } );
    } );

    /**
     * "Make active" action — promotes a provider to the active live
     * calculator. Confirms before sending so an accidental click doesn't
     * change which provider is doing tax math during checkout.
     */
    $( document ).on( 'click', '.tejcart-tax-provider-make-active', function ( e ) {
        e.preventDefault();
        var $button  = $( this );
        var $row     = $button.closest( '.tejcart-payment-method-row' );
        var $wrap    = $button.closest( '[data-active-nonce]' );
        var provider = $button.data( 'provider-id' );
        var nonce    = $wrap.data( 'active-nonce' );

        if ( ! provider || ! nonce ) {
            return;
        }

        if ( i18n.confirmActivate && ! window.confirm( i18n.confirmActivate ) ) {
            return;
        }

        $button.prop( 'disabled', true );

        $.ajax( {
            url:      settings.ajaxUrl,
            type:     'POST',
            dataType: 'json',
            data:     {
                action:      settings.setActiveAction,
                provider_id: provider,
                nonce:       nonce
            }
        } ).done( function ( response ) {
            if ( response && response.success ) {
                // Move the "active" hint over to this row.
                $row.closest( 'tbody' ).find( '.tejcart-payment-method-row' ).removeClass( 'is-active' );
                $row.addClass( 'is-active' );
                flash( $row, ( response.data && response.data.message ) || ( i18n.activated || 'Provider is now active.' ), 'success' );
                // Reload so the status pills repaint authoritatively
                // (other rows lose their Active pill; this row gains it).
                setTimeout( function () { window.location.reload(); }, 600 );
            } else {
                $button.prop( 'disabled', false );
                flash(
                    $row,
                    ( response && response.data && response.data.message ) || ( i18n.activateError || 'Could not promote provider.' ),
                    'error'
                );
            }
        } ).fail( function () {
            $button.prop( 'disabled', false );
            flash( $row, i18n.networkError || 'Network error. Please retry.', 'error' );
        } );
    } );

    /**
     * Providers list search — filters rows in place and shows an
     * empty-state line when nothing matches.
     */
    function initSearchFilter() {
        var input = document.getElementById( 'tejcart-tax-providers-search' );
        if ( ! input ) {
            return;
        }

        var wrap    = input.closest( '.tejcart-tax-providers-list-wrap' );
        var regions = wrap ? wrap.querySelectorAll( '.tejcart-tax-providers-region' ) : [];
        var empty   = wrap ? wrap.querySelector( '.tejcart-tax-providers-list__empty' ) : null;

        function apply() {
            var query   = input.value.trim().toLowerCase();
            var matches = 0;

            regions.forEach( function ( region ) {
                var rows      = region.querySelectorAll( '.tejcart-tax-provider-row' );
                var regionHas = 0;

                rows.forEach( function ( row ) {
                    var blob = row.getAttribute( 'data-search' ) || '';
                    var hit  = ( '' === query ) || blob.indexOf( query ) !== -1;
                    row.hidden = ! hit;
                    if ( hit ) {
                        regionHas++;
                    }
                } );

                region.hidden = ( regionHas === 0 );
                matches += regionHas;
            } );

            if ( empty ) {
                empty.hidden = ( matches !== 0 );
            }
        }

        input.addEventListener( 'input', apply );
    }

    $( function () {
        initSearchFilter();
    } );

} )( jQuery );
