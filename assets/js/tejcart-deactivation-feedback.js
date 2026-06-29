/**
 * TejCart deactivation feedback modal.
 *
 * Intercepts the "Deactivate" click on the Plugins screen, asks the user for
 * an optional reason, sends it via AJAX, then proceeds to the real deactivate
 * URL. Deactivation is never blocked.
 */
( function ( $ ) {
    'use strict';

    var cfg = window.tejcartFeedback || {};

    function ajaxUrl() {
        return cfg.ajaxUrl || ( window.ajaxurl || '' );
    }

    function nonce() {
        return cfg.nonce || '';
    }

    function action() {
        return cfg.action || 'tejcart_send_deactivation';
    }

    function showLoading( $el ) {
        if ( $el && typeof $el.block === 'function' ) {
            $el.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
        }
    }

    function sendFeedback( reason, details ) {
        return $.post( ajaxUrl(), {
            action: action(),
            nonce: nonce(),
            reason: reason,
            reason_details: details || ''
        } );
    }

    $( function () {
        var $modal = $( '.tejcart-deactivation-Modal' );
        if ( $modal.length ) {
            new TejCartDeactivationModal( $modal );
        }

        // "I'd rather not say" — send a generic reason, then deactivate.
        $( '#tejcart-deactivation-no-reason' ).on( 'click', function ( e ) {
            e.preventDefault();
            var url = $( this ).attr( 'href' );
            showLoading( $modal );
            sendFeedback( 'No reason given', '' ).always( function () {
                window.location.replace( url );
            } );
        } );

        // "Send & Deactivate" — send the selected reason + details, then deactivate.
        $( '#tejcart-send-deactivation' ).on( 'click', function ( e ) {
            e.preventDefault();

            var $button = $( this );
            if ( $button.hasClass( 'tejcart-deactivation-isDisabled' ) ) {
                return;
            }

            var $selected = $( "input[name='tejcart-reason']:checked" );
            var reason = $selected.val();
            if ( ! reason ) {
                window.alert( cfg.i18n && cfg.i18n.selectReason ? cfg.i18n.selectReason : 'Please select a reason before deactivating.' );
                return;
            }

            var details = $selected
                .closest( 'li' )
                .find( '.tejcart-deactivation-Modal-fieldHidden textarea' )
                .val();

            showLoading( $modal );
            $button.addClass( 'tejcart-deactivation-isDisabled' );

            sendFeedback( reason, details || '' ).always( function () {
                window.location.replace( $button.attr( 'href' ) );
            } );
        } );
    } );

    function TejCartDeactivationModal( $elem ) {
        this.$elem = $elem;
        // The overlay is a sibling of the modal, not a child.
        this.$overlay = $( '.tejcart-deactivation-Modal-overlay' );
        this.$radio = $( "input[name='tejcart-reason']", $elem );
        this.$closer = $( '.tejcart-deactivation-Modal-close', $elem );
        this.$returnBtn = $( '.tejcart-deactivation-Modal-return', $elem );
        this.$question = $( '.tejcart-deactivation-Modal-question', $elem );
        this.$button = $( '#tejcart-send-deactivation', $elem );
        this.$opener = this.findOpener();
        this.bindEvents();
    }

    // Locate this plugin's Deactivate link. Prefer the reliable data-plugin
    // attribute (the plugin basename), then fall back to the folder slug.
    TejCartDeactivationModal.prototype.findOpener = function () {
        var $opener = $();
        if ( cfg.pluginFile ) {
            $opener = $( '.plugins tr[data-plugin="' + cfg.pluginFile + '"] .deactivate a' );
        }
        if ( ! $opener.length && cfg.slug ) {
            $opener = $( '.plugins [data-slug="' + cfg.slug + '"] .deactivate a' );
        }
        return $opener;
    };

    TejCartDeactivationModal.prototype.bindEvents = function () {
        var self = this;

        this.$opener.on( 'click', function ( e ) {
            e.preventDefault();
            self.open();
        } );

        this.$closer.on( 'click', function ( e ) {
            e.preventDefault();
            self.close();
        } );

        this.$returnBtn.on( 'click', function ( e ) {
            e.preventDefault();
            self.reset();
        } );

        $( document ).on( 'keyup', function ( event ) {
            if ( 27 === event.keyCode ) {
                self.close();
            }
        } );

        this.$overlay.on( 'click', function () {
            self.close();
        } );

        this.$radio.on( 'change', function () {
            self.onSelect( $( this ) );
        } );
    };

    TejCartDeactivationModal.prototype.onSelect = function ( $radio ) {
        $( '.tejcart-deactivation-Modal-fieldHidden', this.$elem ).removeClass( 'tejcart-deactivation-isOpen' );

        var $field = $radio.closest( 'li' ).find( '.tejcart-deactivation-Modal-fieldHidden' );
        if ( $field.length ) {
            $field.addClass( 'tejcart-deactivation-isOpen' );
            $field.find( 'textarea' ).trigger( 'focus' );
        }

        this.$button.removeClass( 'tejcart-deactivation-isDisabled' );
    };

    TejCartDeactivationModal.prototype.reset = function () {
        $( '.tejcart-deactivation-Modal-fieldHidden', this.$elem )
            .removeClass( 'tejcart-deactivation-isOpen' )
            .find( 'textarea' )
            .val( '' );
        this.$radio.prop( 'checked', false );
        this.$button.addClass( 'tejcart-deactivation-isDisabled' );
    };

    TejCartDeactivationModal.prototype.open = function () {
        this.$elem.addClass( 'tejcart-deactivation-isVisible' ).attr( 'aria-hidden', 'false' );
        this.$overlay.addClass( 'tejcart-deactivation-isVisible' );
    };

    TejCartDeactivationModal.prototype.close = function () {
        this.reset();
        this.$elem.removeClass( 'tejcart-deactivation-isVisible' ).attr( 'aria-hidden', 'true' );
        this.$overlay.removeClass( 'tejcart-deactivation-isVisible' );
        if ( typeof this.$elem.unblock === 'function' ) {
            this.$elem.unblock();
        }
    };
} )( jQuery );
