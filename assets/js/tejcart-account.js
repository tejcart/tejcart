/**
 * TejCart — My Account scripts.
 *
 * Handlers for the saved payment methods list (delete / set-default).
 * Localised data is read from `window.tejcart_account_params`:
 *   - ajax_url: admin-ajax endpoint
 *   - i18n.confirm_delete, i18n.delete_error, i18n.default_error
 */
( function () {
	'use strict';

	var params = window.tejcart_account_params || {};
	var ajaxUrl = params.ajax_url || '';
	var i18n = params.i18n || {};

	function postAjax( action, methodId, nonce ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'method_id', methodId );
		formData.append( 'nonce', nonce );
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
			.then( function ( r ) { return r.json(); } );
	}

	function onDeleteClick( e ) {
		var btn = e.currentTarget;
		var message = i18n.confirm_delete || 'Are you sure you want to remove this payment method?';
		if ( ! window.confirm( message ) ) {
			return;
		}
		var methodId = btn.dataset.methodId;
		var nonce = btn.dataset.nonce;
		var card = btn.closest( '.tejcart-account-method-card' );

		btn.disabled = true;
		postAjax( 'tejcart_delete_payment_method', methodId, nonce )
			.then( function ( data ) {
				if ( data && data.success ) {
					if ( card ) {
						card.classList.add( 'is-removing' );
						setTimeout( function () { card.remove(); }, 200 );
					}
				} else {
					btn.disabled = false;
					var msg = ( data && data.data && data.data.message ) || i18n.delete_error || 'Error removing payment method.';
					window.alert( msg );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				window.alert( i18n.delete_error || 'Error removing payment method.' );
			} );
	}

	function onSetDefaultClick( e ) {
		var btn = e.currentTarget;
		var methodId = btn.dataset.methodId;
		var nonce = btn.dataset.nonce;

		btn.disabled = true;
		postAjax( 'tejcart_set_default_payment_method', methodId, nonce )
			.then( function ( data ) {
				if ( data && data.success ) {
					window.location.reload();
				} else {
					btn.disabled = false;
					var msg = ( data && data.data && data.data.message ) || i18n.default_error || 'Error updating default method.';
					window.alert( msg );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				window.alert( i18n.default_error || 'Error updating default method.' );
			} );
	}

	function init() {
		var deleteButtons = document.querySelectorAll( '.tejcart-delete-method' );
		for ( var i = 0; i < deleteButtons.length; i++ ) {
			deleteButtons[ i ].addEventListener( 'click', onDeleteClick );
		}
		var defaultButtons = document.querySelectorAll( '.tejcart-set-default-method' );
		for ( var j = 0; j < defaultButtons.length; j++ ) {
			defaultButtons[ j ].addEventListener( 'click', onSetDefaultClick );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
