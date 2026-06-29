/**
 * TejCart PayPal "Connect with PayPal" onboarding.
 *
 * Drives the Partner Referrals flow via PayPal's official MiniBrowser SDK
 * (partner.js):
 *
 *   1. The Connect control is rendered server-side as an <a> tag with
 *      data-paypal-button="true" and data-paypal-onboard-complete="tejcartPayPalOnboardComplete".
 *      Its href is the pre-generated Partner Referrals signup URL, with
 *      displayMode=minibrowser appended.
 *   2. partner.js intercepts the click and opens the PayPal MiniBrowser
 *      popup at the signup URL — we do not open the popup ourselves.
 *   3. When the merchant finishes onboarding inside the popup, PayPal
 *      invokes window.tejcartPayPalOnboardComplete(authCode, sharedId) on
 *      this window.
 *   4. The callback POSTs those credentials to the login-seller endpoint
 *      and reloads the page so the server-rendered card, capability pills,
 *      and Payment Methods list all reflect the new connected state.
 *
 * @package TejCart
 */
( function ( $ ) {
	'use strict';

	var settings = window.tejcart_paypal_onboarding || {};
	var adminGlobals = window.tejcart_admin || {};

	if ( ! settings.ajax_url ) {
		settings.ajax_url = adminGlobals.ajax_url || window.ajaxurl || '';
	}

	var onboardingInProgress = false;

	/**
	 * Locate the currently rendered connect row, if any.
	 */
	function getCard() {
		return document.querySelector( '.tejcart-paypal-connect' );
	}

	/**
	 * Write a status message into the connect row.
	 */
	function setStatus( message, type ) {
		var card = getCard();
		if ( ! card ) {
			return;
		}
		var el = card.querySelector( '.tejcart-paypal-connection-status-msg' );
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.classList.remove( 'is-error', 'is-success' );
		if ( type === 'error' ) {
			el.classList.add( 'is-error' );
		} else if ( type === 'success' ) {
			el.classList.add( 'is-success' );
		}
	}

	/**
	 * Read the environment the merchant selected in the Environment dropdown.
	 * Falls back to whatever the connect row was rendered with so stale JS
	 * doesn't break the Connect button when the dropdown is missing.
	 */
	function getSelectedEnvironment() {
		var radio = document.querySelector(
			'input[name="tejcart_gateway_tejcart_paypal_sandbox_mode"]:checked'
		);
		if ( radio ) {
			return ( radio.value === 'no' ) ? 'live' : 'sandbox';
		}
		var select = document.getElementById( 'tejcart_gateway_tejcart_paypal_sandbox_mode' );
		if ( select && 'value' in select ) {
			return ( select.value === 'no' ) ? 'live' : 'sandbox';
		}
		var card = getCard();
		if ( card ) {
			return card.getAttribute( 'data-environment' ) || 'sandbox';
		}
		return 'sandbox';
	}

	/**
	 * POST to a TejCart AJAX endpoint. Returns a jqXHR.
	 */
	function post( action, data ) {
		var payload = $.extend(
			{},
			{
				action:  action,
				_wpnonce: settings.nonce
			},
			data || {}
		);
		return $.ajax( {
			url:      settings.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     payload
		} );
	}

	/**
	 * PayPal Partner Referrals onboarding completion callback. Invoked by
	 * partner.js from inside the MiniBrowser popup once the merchant has
	 * finished granting permissions — passes the shared client id and auth
	 * code we need to exchange for REST credentials.
	 *
	 * Exposed on the window so PayPal's SDK can find it via the
	 * data-paypal-onboard-complete="tejcartPayPalOnboardComplete" attribute
	 * on the Connect anchor.
	 */
	window.tejcartPayPalOnboardComplete = function ( authCode, sharedId ) {
		if ( onboardingInProgress ) {
			return;
		}
		onboardingInProgress = true;

		window.onbeforeunload = null;

		try {
			if (
				typeof window.PAYPAL !== 'undefined' &&
				window.PAYPAL.apps &&
				window.PAYPAL.apps.Signup &&
				window.PAYPAL.apps.Signup.MiniBrowser &&
				typeof window.PAYPAL.apps.Signup.MiniBrowser.closeFlow === 'function'
			) {
				window.PAYPAL.apps.Signup.MiniBrowser.closeFlow();
			}
		} catch ( e ) {  }

		finalizeOnboarding( sharedId, authCode );
	};

	/**
	 * Exchange sharedId + authCode for merchant credentials, then reload the
	 * settings page to re-render the (now connected) card server-side.
	 */
	function finalizeOnboarding( sharedId, authCode ) {
		setStatus( settings.i18n.finalising || 'Finalising connection…', 'info' );

		var $body = $( 'body' ).addClass( 'tejcart-paypal-onboarding-busy' );
		var environment = getSelectedEnvironment();

		post( 'tejcart_paypal_onboarding_login_seller', {
			shared_id:   sharedId,
			auth_code:   authCode,
			environment: environment
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					setStatus( ( response.data && response.data.message ) || settings.i18n.connected || 'Connected!', 'success' );

					window.location.reload();
				} else {
					onboardingInProgress = false;
					$body.removeClass( 'tejcart-paypal-onboarding-busy' );
					var message = ( response && response.data && response.data.message ) || settings.i18n.error || 'Could not complete connection.';
					setStatus( message, 'error' );
				}
			} )
			.fail( function ( xhr ) {
				onboardingInProgress = false;
				$body.removeClass( 'tejcart-paypal-onboarding-busy' );
				var data    = xhr && xhr.responseJSON && xhr.responseJSON.data;
				var message = ( data && data.message ) || settings.i18n.network || 'Network error. Please try again.';
				setStatus( message, 'error' );
			} );
	}

	/**
	 * Test-connection handler — forces a fresh OAuth token exchange against
	 * the active environment so the merchant can verify saved credentials
	 * still work without running a real checkout.
	 */
	function testConnection( $btn ) {
		if ( ! settings.test_nonce ) {
			return;
		}
		$btn.prop( 'disabled', true ).addClass( 'is-loading' );
		setStatus( settings.i18n.testing || 'Testing credentials…', 'info' );

		$.ajax( {
			url:      settings.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:   'tejcart_paypal_test_connection',
				_wpnonce: settings.test_nonce
			}
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					setStatus(
						( response.data && response.data.message ) || settings.i18n.testSuccess || 'Connection successful.',
						'success'
					);
				} else {
					var message = ( response && response.data && response.data.message )
						|| settings.i18n.testError
						|| 'Connection failed.';
					setStatus( message, 'error' );
				}
			} )
			.fail( function ( xhr ) {
				var data    = xhr && xhr.responseJSON && xhr.responseJSON.data;
				var message = ( data && data.message ) || settings.i18n.network || 'Network error. Please try again.';
				setStatus( message, 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).removeClass( 'is-loading' );
			} );
	}

	/**
	 * Disconnect handler.
	 */
	function disconnect() {
		if ( ! window.confirm( settings.i18n.confirmDisconnect || 'Disconnect this PayPal environment?' ) ) {
			return;
		}
		var environment = getSelectedEnvironment();
		setStatus( settings.i18n.disconnecting || 'Disconnecting…', 'info' );

		post( 'tejcart_paypal_onboarding_disconnect', {
			environment: environment
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					setStatus( ( response.data && response.data.message ) || settings.i18n.disconnected || 'Disconnected.', 'success' );
					setTimeout( function () { window.location.reload(); }, 500 );
				} else {
					setStatus( ( response && response.data && response.data.message ) || settings.i18n.error || 'Could not disconnect.', 'error' );
				}
			} )
			.fail( function ( xhr ) {
				var data    = xhr && xhr.responseJSON && xhr.responseJSON.data;
				var message = ( data && data.message ) || settings.i18n.network || 'Network error. Please try again.';
				setStatus( message, 'error' );
			} );
	}

	/**
	 * Open a collapsible heading + show all of its member rows.
	 */
	function openCollapsibleSection( $heading ) {
		var target = $heading.data( 'collapse-target' );
		if ( ! target ) {
			return;
		}
		$heading.removeClass( 'is-collapsed' );
		$heading.find( '.tejcart-collapse-toggle' ).attr( 'aria-expanded', 'true' );
		$( 'tr.tejcart-collapse-member[data-collapse-group="' + target + '"]' ).removeClass( 'is-hidden' );
	}

	/**
	 * Toggle the Advanced manual-credentials section.
	 */
	function toggleCollapsibleSection( $heading ) {
		var target = $heading.data( 'collapse-target' );
		if ( ! target ) {
			return;
		}
		var nowCollapsed = ! $heading.hasClass( 'is-collapsed' );
		$heading.toggleClass( 'is-collapsed', nowCollapsed );
		$heading.find( '.tejcart-collapse-toggle' ).attr( 'aria-expanded', nowCollapsed ? 'false' : 'true' );
		$( 'tr.tejcart-collapse-member[data-collapse-group="' + target + '"]' ).toggleClass( 'is-hidden', nowCollapsed );
	}

	var paypalSdkPromise     = null;
	var buttonPreviewLoading = false;
	var currentButtonsHandle = null;
	var buttonPreviewTimer   = null;

	/**
	 * Locate the button-preview wrapper. Only exists on the PayPal
	 * Settings tab when the merchant has a saved client_id for the
	 * currently-active environment.
	 */
	function getButtonPreviewWrap() {
		return document.querySelector( '.tejcart-paypal-button-preview[data-client-id]' );
	}

	/**
	 * Lazy-load the PayPal Smart Button SDK exactly once per page. The
	 * SDK is env/client-id specific, so the first call wins — if the
	 * merchant reloads after changing the active environment, the new
	 * client_id + env are baked into the next page render.
	 */
	function loadPayPalSmartButtonSdk( clientId, currency, partnerAttributionId ) {
		if ( paypalSdkPromise ) {
			return paypalSdkPromise;
		}

		paypalSdkPromise = new Promise( function ( resolve, reject ) {
			if ( window.paypal && window.paypal.Buttons ) {
				resolve();
				return;
			}

			var script = document.createElement( 'script' );
			script.id  = 'tejcart-paypal-sdk-preview';
			var params = [
				'client-id=' + encodeURIComponent( clientId ),
				'components=buttons',
				'intent=capture',
				'currency=' + encodeURIComponent( currency || 'USD' ),
				'enable-funding=venmo,paylater',
				'disable-funding=card'
			];
			script.src   = 'https://www.paypal.com/sdk/js?' + params.join( '&' );
			script.async = true;
			if ( partnerAttributionId ) {
				script.setAttribute( 'data-partner-attribution-id', partnerAttributionId );
			}
			script.onload  = function () { resolve(); };
			script.onerror = function () {
				paypalSdkPromise = null;
				reject( new Error( 'PayPal SDK failed to load' ) );
			};
			document.head.appendChild( script );
		} );

		return paypalSdkPromise;
	}

	/**
	 * Read the four style fields and normalise them into a paypal.Buttons
	 * style object. Clamps height to the SDK's 25-55 range and folds in
	 * the layout + tagline fields from the PayPal Settings tab.
	 */
	function readButtonPreviewStyle() {
		var wrap = getButtonPreviewWrap();
		var $color   = $( '#tejcart_gateway_tejcart_paypal_button_color' );
		var $shape   = $( '#tejcart_gateway_tejcart_paypal_button_shape' );
		var $label   = $( '#tejcart_gateway_tejcart_paypal_button_label' );
		var $layout  = $( '#tejcart_gateway_tejcart_paypal_button_layout' );
		var $tagline = $( '#tejcart_gateway_tejcart_paypal_button_tagline' );
		var $height  = $( '#tejcart_gateway_tejcart_paypal_button_height' );

		var color  = $color.length  ? String( $color.val()  || 'gold'     ) : ( wrap && wrap.getAttribute( 'data-color' )  || 'gold' );
		var shape  = $shape.length  ? String( $shape.val()  || 'rect'     ) : ( wrap && wrap.getAttribute( 'data-shape' )  || 'rect' );
		var label  = $label.length  ? String( $label.val()  || 'paypal'   ) : ( wrap && wrap.getAttribute( 'data-label' )  || 'paypal' );
		var layout = $layout.length ? String( $layout.val() || 'vertical' ) : ( wrap && wrap.getAttribute( 'data-layout' ) || 'vertical' );

		var allowedColors = [ 'gold', 'blue', 'silver', 'white', 'black' ];
		if ( allowedColors.indexOf( color ) === -1 ) { color = 'gold'; }
		shape  = ( 'pill' === shape ) ? 'pill' : 'rect';
		layout = ( 'horizontal' === layout ) ? 'horizontal' : 'vertical';

		var height;
		if ( $height.length ) {
			height = parseInt( $height.val(), 10 );
		} else if ( wrap ) {
			height = parseInt( wrap.getAttribute( 'data-height' ), 10 );
		}
		if ( isNaN( height ) || height < 25 || height > 55 ) {
			height = 45;
		}

		var taglineRaw;
		if ( $tagline.length ) {
			taglineRaw = $tagline.is( ':checked' );
		} else if ( wrap ) {
			taglineRaw = ( wrap.getAttribute( 'data-tagline' ) === 'true' );
		} else {
			taglineRaw = false;
		}
		var tagline = !! taglineRaw && layout === 'horizontal' && color === 'gold';

		return {
			layout:  layout,
			color:   color,
			shape:   shape,
			label:   label,
			height:  height,
			tagline: tagline
		};
	}

	/**
	 * Render (or re-render) the Smart Button inside the preview frame.
	 * Closes any existing Buttons instance first so the SDK can cleanly
	 * tear down its iframe before we mount a new one.
	 */
	function renderButtonPreview() {
		if ( buttonPreviewLoading ) {
			return;
		}
		var wrap = getButtonPreviewWrap();
		if ( ! wrap || ! window.paypal || ! window.paypal.Buttons ) {
			return;
		}
		var container = document.getElementById( 'tejcart-paypal-button-preview-container' );
		if ( ! container ) {
			return;
		}

		if ( currentButtonsHandle && typeof currentButtonsHandle.close === 'function' ) {
			try { currentButtonsHandle.close(); } catch ( e ) {  }
		}
		currentButtonsHandle = null;
		container.innerHTML  = '';

		var style = readButtonPreviewStyle();

		try {
			currentButtonsHandle = window.paypal.Buttons( {
				style: style,

				createOrder: function () {
					return Promise.reject( new Error( 'tejcart_preview' ) );
				},

				onClick: function () { return false; },
				onError: function () {  }
			} );

			if ( currentButtonsHandle.isEligible && ! currentButtonsHandle.isEligible() ) {
				container.innerHTML = '<div class="tejcart-paypal-button-preview__empty">'
					+ 'This style is not eligible in the current environment.'
					+ '</div>';
				return;
			}

			currentButtonsHandle.render( '#tejcart-paypal-button-preview-container' );
		} catch ( err ) {
			container.innerHTML = '<div class="tejcart-paypal-button-preview__empty">'
				+ 'Could not render the PayPal Smart Button preview.'
				+ '</div>';
		}
	}

	/**
	 * Debounced wrapper so rapid input events (typing in the height
	 * number field) don't thrash the SDK's render / close cycle.
	 */
	function scheduleButtonPreviewRender() {
		if ( buttonPreviewTimer ) {
			clearTimeout( buttonPreviewTimer );
		}
		buttonPreviewTimer = setTimeout( renderButtonPreview, 300 );
	}

	/**
	 * Initial boot — load the SDK and kick off the first render if the
	 * preview wrapper is present on this page.
	 */
	function initButtonPreview() {
		var wrap = getButtonPreviewWrap();
		if ( ! wrap ) {
			return;
		}
		var clientId  = wrap.getAttribute( 'data-client-id' ) || '';
		var currency  = wrap.getAttribute( 'data-currency' )  || 'USD';
		var bnCode    = wrap.getAttribute( 'data-partner-attribution-id' ) || '';
		if ( ! clientId ) {
			return;
		}

		buttonPreviewLoading = true;
		loadPayPalSmartButtonSdk( clientId, currency, bnCode )
			.then( function () {
				buttonPreviewLoading = false;
				renderButtonPreview();
			} )
			.catch( function () {
				buttonPreviewLoading = false;
				var container = document.getElementById( 'tejcart-paypal-button-preview-container' );
				if ( container ) {
					container.innerHTML = '<div class="tejcart-paypal-button-preview__empty">'
						+ 'Could not load the PayPal JS SDK. Check your network and reload.'
						+ '</div>';
				}
			} );
	}

	$( document ).on(
		'change input',
		'#tejcart_gateway_tejcart_paypal_button_color,' +
		'#tejcart_gateway_tejcart_paypal_button_shape,' +
		'#tejcart_gateway_tejcart_paypal_button_label,' +
		'#tejcart_gateway_tejcart_paypal_button_layout,' +
		'#tejcart_gateway_tejcart_paypal_button_tagline,' +
		'#tejcart_gateway_tejcart_paypal_button_height',
		scheduleButtonPreviewRender
	);

	$( document ).on( 'click', '.tejcart-paypal-button-preview__chip', function ( e ) {
		e.preventDefault();
		var $chip = $( this );
		if ( $chip.hasClass( 'is-active' ) ) {
			return;
		}

		var viewport = $chip.attr( 'data-viewport' ) || 'desktop';
		var widthRaw = $chip.attr( 'data-width' ) || '';
		var width    = parseInt( widthRaw, 10 );

		$chip.closest( '.tejcart-paypal-button-preview__chips' )
			.find( '.tejcart-paypal-button-preview__chip' )
			.removeClass( 'is-active' )
			.attr( 'aria-pressed', 'false' );
		$chip.addClass( 'is-active' ).attr( 'aria-pressed', 'true' );

		var $device = $( '.tejcart-paypal-button-preview__device' );
		if ( ! isNaN( width ) && width > 0 ) {
			$device.css( 'max-width', 'min(' + width + 'px, 100%)' );
		} else {
			$device.css( 'max-width', 'none' );
		}
		$device.attr( 'data-viewport', viewport );

		var $dim = $( '.tejcart-paypal-button-preview__dimensions' );
		if ( ! isNaN( width ) && width > 0 ) {
			$dim.text( width + ' × auto' );
		} else {
			$dim.text( 'Full width' );
		}

		scheduleButtonPreviewRender();
	} );

	$( initButtonPreview );

	/**
	 * Show only the credential rows that belong to the given environment,
	 * hiding the rest. Rows opt in by declaring data-env="sandbox" or
	 * data-env="live" server-side.
	 */
	function filterEnvScopedRows( env ) {
		$( 'tr[data-env]' ).each( function () {
			var rowEnv = this.getAttribute( 'data-env' );
			$( this ).toggleClass( 'is-env-hidden', rowEnv !== env );
		} );
	}

	/**
	 * Hide the Manual credentials heading + all its member rows whenever
	 * the currently-displayed environment is already connected — a
	 * connected env has no reason to expose manual credential inputs.
	 * Reads the per-env connected flags from the connect row's data
	 * attributes so no extra AJAX call is needed on dropdown changes.
	 */
	function filterManualSectionByConnection( env ) {
		var card = getCard();
		if ( ! card ) {
			return;
		}
		var key       = ( env === 'live' ) ? 'live-connected' : 'sandbox-connected';
		var connected = card.getAttribute( 'data-' + key ) === 'yes';
		$( '.tejcart-paypal-manual-section' ).toggleClass( 'is-connected-hidden', connected );
	}

	$( document ).on( 'click', '.tejcart-paypal-disconnect-btn', function ( e ) {
		e.preventDefault();
		disconnect();
	} );

	$( document ).on( 'click', '.tejcart-paypal-test-btn', function ( e ) {
		e.preventDefault();
		testConnection( $( this ) );
	} );

	$( document ).on(
		'change',
		'#tejcart_gateway_tejcart_paypal_sandbox_mode, input[name="tejcart_gateway_tejcart_paypal_sandbox_mode"]',
		function () {
		var env  = ( this.value === 'no' ) ? 'live' : 'sandbox';

		var $group = $( this ).closest( '.tejcart-segmented' );
		if ( $group.length ) {
			$group.find( '.tejcart-segmented__option' ).removeClass( 'is-selected' );
			$( this ).closest( '.tejcart-segmented__option' ).addClass( 'is-selected' );
		}
		var card = getCard();
		if ( card ) {
			card.setAttribute( 'data-environment', env );
		}
		var $link = $( '.tejcart-paypal-connect-btn' );
		if ( $link.is( 'a' ) ) {
			var nextHref = ( env === 'live' )
				? $link.data( 'signup-live' )
				: $link.data( 'signup-sandbox' );
			if ( nextHref ) {
				$link.attr( 'href', nextHref );
			}
		}
		filterEnvScopedRows( env );
		filterManualSectionByConnection( env );
	} );

	$( document ).on( 'click', '.tejcart-paypal-manual-toggle', function ( e ) {
		e.preventDefault();
		var $heading = $( '.tejcart-field-heading.is-collapsible' ).first();
		if ( ! $heading.length ) {
			return;
		}
		openCollapsibleSection( $heading );
		if ( $heading[0].scrollIntoView ) {
			$heading[0].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} );

	$( document ).on( 'click', '.tejcart-field-heading.is-collapsible .tejcart-collapse-toggle', function ( e ) {
		e.preventDefault();
		toggleCollapsibleSection( $( this ).closest( '.tejcart-field-heading' ) );
	} );

	$( function () {
		$( '.tejcart-field-heading.is-collapsible.is-collapsed' ).each( function () {
			var target = $( this ).data( 'collapse-target' );
			if ( target ) {
				$( 'tr.tejcart-collapse-member[data-collapse-group="' + target + '"]' ).addClass( 'is-hidden' );
			}
		} );
	} );

	/**
	 * Sync visibility of every dependent row whose data-parent matches the
	 * given parent input id. When the parent checkbox is unchecked the
	 * dependent rows are hidden via the is-parent-hidden class.
	 */
	function syncParentDependentRows( parentId ) {
		if ( ! parentId ) {
			return;
		}
		var parentEl = document.getElementById( parentId );
		if ( ! parentEl ) {
			return;
		}
		var enabled = ( parentEl.type === 'checkbox' )
			? !! parentEl.checked
			: ( parentEl.value === 'yes' );
		$( 'tr[data-parent="' + parentId + '"]' ).toggleClass( 'is-parent-hidden', ! enabled );
	}

	$( document ).on( 'change', 'input[type="checkbox"]', function () {
		var id = this.id;
		if ( ! id ) {
			return;
		}
		if ( ! $( 'tr[data-parent="' + id + '"]' ).length ) {
			return;
		}
		syncParentDependentRows( id );
	} );

	$( function () {
		var parents = {};
		$( 'tr[data-parent]' ).each( function () {
			var p = $( this ).attr( 'data-parent' );
			if ( p ) {
				parents[ p ] = true;
			}
		} );
		Object.keys( parents ).forEach( syncParentDependentRows );
	} );

	$( document ).on( 'click', '.tejcart-paypal-manage__sidebar-toggle', function ( e ) {
		e.preventDefault();
		var $btn     = $( this );
		var $sidebar = $btn.closest( '.tejcart-paypal-manage__sidebar' );
		var isOpen   = $sidebar.toggleClass( 'is-open' ).hasClass( 'is-open' );
		$btn.attr( 'aria-expanded', isOpen ? 'true' : 'false' );
	} );

	function getManageForm() {
		return document.querySelector( '.tejcart-paypal-manage__form' );
	}

	function getSaveBar( form ) {
		if ( ! form ) { return null; }
		return form.querySelector( '.tejcart-paypal-manage-save-bar' );
	}

	function setDirty( form, dirty ) {
		var bar = getSaveBar( form );
		if ( ! bar ) { return; }
		bar.classList.toggle( 'is-dirty', !! dirty );

		form.classList.toggle( 'is-dirty', !! dirty );
	}

	function isFormDirty( form ) {
		return !! form && form.classList.contains( 'is-dirty' );
	}

	$( function () {
		var form = getManageForm();
		if ( ! form || form.classList.contains( 'is-locked' ) ) {
			return;
		}

		var baseline = {};
		var fields   = form.querySelectorAll( 'input, select, textarea' );

		Array.prototype.forEach.call( fields, function ( field ) {
			if ( ! field.name ) { return; }
			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				baseline[ field.name + '::' + field.value ] = field.checked;
			} else {
				baseline[ field.name ] = field.value;
			}
		} );

		function recomputeDirty() {
			var dirty   = false;
			var current = form.querySelectorAll( 'input, select, textarea' );
			for ( var i = 0; i < current.length; i++ ) {
				var f = current[ i ];
				if ( ! f.name ) { continue; }
				if ( f.type === 'checkbox' || f.type === 'radio' ) {
					var key = f.name + '::' + f.value;
					if ( baseline[ key ] !== f.checked ) { dirty = true; break; }
				} else {
					if ( baseline[ f.name ] !== f.value ) { dirty = true; break; }
				}
			}
			setDirty( form, dirty );
		}

		$( form ).on( 'input change', 'input, select, textarea', recomputeDirty );

		$( form ).on( 'reset', function () {
			setTimeout( recomputeDirty, 0 );
		} );

		$( form ).on( 'click', '[data-action="discard"]', function ( e ) {
			e.preventDefault();
			var fields = form.querySelectorAll( 'input, select, textarea' );
			Array.prototype.forEach.call( fields, function ( field ) {
				if ( ! field.name ) { return; }
				if ( field.type === 'checkbox' || field.type === 'radio' ) {
					var key      = field.name + '::' + field.value;
					var baseline_ = baseline.hasOwnProperty( key ) ? baseline[ key ] : field.defaultChecked;
					field.checked = !! baseline_;
				} else if ( baseline.hasOwnProperty( field.name ) ) {
					field.value = baseline[ field.name ];
				}
			} );

			$( form ).find( '.tejcart-segmented' ).each( function () {
				var $group = $( this );
				$group.find( '.tejcart-segmented__option' ).removeClass( 'is-selected' );
				$group.find( 'input:checked' ).closest( '.tejcart-segmented__option' ).addClass( 'is-selected' );
			} );

			$( fields ).trigger( 'change' );
			recomputeDirty();
		} );

		$( form ).on( 'submit', function () {
			setDirty( form, false );
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( isFormDirty( form ) ) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		} );
	} );

	$( document ).on( 'change', '.tejcart-multicheck input[type="checkbox"]', function () {
		var $option = $( this ).closest( '.tejcart-multicheck__option' );
		$option.toggleClass( 'is-selected', this.checked );
	} );

	$( document ).on( 'click', '.tejcart-paypal-manage__nav-item', function ( e ) {
		var form = getManageForm();
		if ( ! isFormDirty( form ) ) { return; }
		var msg = settings.i18n && settings.i18n.unsavedChanges
			? settings.i18n.unsavedChanges
			: 'You have unsaved changes. Leave this section without saving?';
		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	} );
} )( jQuery );
