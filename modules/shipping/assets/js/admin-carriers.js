/**
 * TejCart Shipping — Carriers admin UI.
 *
 * Wires the carriers list (AJAX enable/disable, search filter) and the
 * configure page (segmented control, password show/hide, test
 * connection AJAX). Plain jQuery on purpose — tejcart-admin already
 * pulls jQuery in and this avoids dragging a build pipeline into a
 * module that ships pre-minified for wordpress.org.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.tejcartShippingCarriers ) {
		return;
	}

	var settings = window.tejcartShippingCarriers;
	var i18n     = settings.i18n || {};

	function flash( $row, message, tone ) {
		var $status = $row.find( '.tejcart-carrier-row__status-text' );
		if ( $status.length ) {
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
	 * Inline AJAX enable/disable for carriers. Bound on `change` so
	 * keyboard activation works too.
	 */
	$( document ).on( 'change', '.tejcart-carrier-toggle-input', function () {
		var $input  = $( this );
		var $label  = $input.closest( '.tejcart-carrier-toggle' );
		var $row    = $input.closest( '.tejcart-payment-method-row' );
		var $wrap   = $input.closest( '[data-toggle-nonce]' );
		var carrier = $input.data( 'carrier-id' );
		var enabled = $input.is( ':checked' );
		var nonce   = $wrap.data( 'toggle-nonce' );

		if ( ! carrier || ! nonce ) {
			return;
		}

		// Confirm before silently breaking checkout for a carrier that's
		// wired into one or more shipping zones. Data attributes are
		// populated by Carriers_List::render_carrier_row.
		if ( ! enabled ) {
			var usage = parseInt( $row.attr( 'data-zone-usage' ) || '0', 10 );
			if ( usage > 0 ) {
				var zoneNames = $row.attr( 'data-zone-names' ) || '';
				var brand     = $row.attr( 'data-carrier-label' ) || carrier;
				var template  = i18n.disableConfirm || 'Disable %1$s? It is currently configured in: %2$s. Rates from this carrier will stop being quoted at checkout immediately.';
				var message   = template.replace( '%1$s', brand ).replace( '%2$s', zoneNames );
				if ( ! window.confirm( message ) ) {
					$input.prop( 'checked', true );
					return;
				}
			}
		}

		$label.addClass( 'is-loading' );

		$.ajax( {
			url:      settings.ajaxUrl,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:     settings.toggleAction,
				carrier_id: carrier,
				enabled:    enabled ? '1' : '0',
				nonce:      nonce
			}
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$row.toggleClass( 'is-enabled', enabled );
				if ( $row.length ) {
					flash(
						$row,
						enabled ? ( i18n.enabled || 'Carrier enabled.' )
						        : ( i18n.disabled || 'Carrier disabled.' ),
						'success'
					);
				}
			} else {
				$input.prop( 'checked', ! enabled );
				if ( $row.length ) {
					flash(
						$row,
						( response && response.data && response.data.message ) || ( i18n.toggleError || 'Could not update carrier.' ),
						'error'
					);
				}
			}
		} ).fail( function () {
			$input.prop( 'checked', ! enabled );
			if ( $row.length ) {
				flash( $row, i18n.toggleError || 'Network error. Please retry.', 'error' );
			}
		} ).always( function () {
			$label.removeClass( 'is-loading' );
		} );
	} );

	/**
	 * Carriers list search — filters rows in place, hides regions that
	 * end up with no matching rows, and shows an empty-state line when
	 * nothing matches.
	 */
	function initSearchFilter() {
		var input = document.getElementById( 'tejcart-carriers-search' );
		if ( ! input ) {
			return;
		}

		var wrap    = input.closest( '.tejcart-carriers-list-wrap' );
		var regions = wrap ? wrap.querySelectorAll( '.tejcart-carriers-region' ) : [];
		var empty   = wrap ? wrap.querySelector( '.tejcart-carriers-list__empty' ) : null;

		function apply() {
			var query   = input.value.trim().toLowerCase();
			var matches = 0;

			regions.forEach( function ( region ) {
				var rows         = region.querySelectorAll( '.tejcart-carrier-row' );
				var regionHas    = 0;

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

	/**
	 * Segmented control (Live / Sandbox). Clicking a label flips the
	 * .is-active hint on its siblings — the underlying radio button
	 * still owns form state, this is purely visual feedback so the
	 * control responds instantly without waiting on a save.
	 */
	$( document ).on( 'change', '.tejcart-carrier-segmented input[type="radio"]', function () {
		var $input = $( this );
		var $group = $input.closest( '.tejcart-carrier-segmented' );
		$group.find( '.tejcart-carrier-segmented__option' ).removeClass( 'is-active' );
		$input.closest( '.tejcart-carrier-segmented__option' ).addClass( 'is-active' );
	} );

	/**
	 * Show / hide secret fields. Toggles input[type] between password
	 * and text and flips an aria-pressed attribute on the button.
	 */
	$( document ).on( 'click', '.tejcart-carrier-field__reveal', function ( e ) {
		e.preventDefault();
		var $button = $( this );
		var target  = $button.data( 'target' );
		if ( ! target ) {
			return;
		}
		var $input  = $( document.getElementById( target ) );
		if ( ! $input.length ) {
			return;
		}
		var isSecret = $input.attr( 'type' ) === 'password';
		$input.attr( 'type', isSecret ? 'text' : 'password' );
		$button.attr( 'aria-pressed', isSecret ? 'true' : 'false' );
		$button.text( isSecret ? ( i18n.hideSecret || 'Hide' ) : ( i18n.showSecret || 'Show' ) );
	} );

	/**
	 * Test connection — calls the driver-specific probe via AJAX and
	 * renders the structured result next to the button.
	 */
	$( document ).on( 'click', '.tejcart-carrier-test-button', function ( e ) {
		e.preventDefault();
		var $button  = $( this );
		var $wrap    = $button.closest( '[data-test-nonce]' );
		var $result  = $wrap.find( '.tejcart-carrier-configure__test-result' );
		var carrier  = $button.data( 'carrier-id' );
		var nonce    = $wrap.data( 'test-nonce' );

		if ( ! carrier || ! nonce ) {
			return;
		}

		$button.prop( 'disabled', true );
		$result.removeClass( 'is-success is-error' );
		$result.text( i18n.testing || 'Testing connection…' );

		$.ajax( {
			url:      settings.ajaxUrl,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:     settings.testAction,
				carrier_id: carrier,
				nonce:      nonce
			}
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$result.addClass( 'is-success' );
				$result.text( ( response.data && response.data.message ) || 'OK' );
			} else {
				$result.addClass( 'is-error' );
				$result.text( ( response && response.data && response.data.message ) || 'Connection test failed.' );
			}
		} ).fail( function () {
			$result.addClass( 'is-error' );
			$result.text( i18n.testNetwork || 'Network error during connection test.' );
		} ).always( function () {
			$button.prop( 'disabled', false );
		} );
	} );

	$( function () {
		initSearchFilter();

		// Initialise segmented control active state from the checked radio.
		$( '.tejcart-carrier-segmented input[type="radio"]:checked' ).each( function () {
			$( this ).closest( '.tejcart-carrier-segmented__option' ).addClass( 'is-active' );
		} );
	} );

} )( jQuery );
