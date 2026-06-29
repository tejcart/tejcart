/**
 * TejCart payment-button telemetry beacon.
 *
 * Captures the moment a buyer interacts with a payment surface — a
 * click on the "Place Order" button, the moment a PayPal / Apple /
 * Google Pay button finishes rendering, a wallet returning an error to
 * JS — and posts a tiny JSON beacon to the TejCart telemetry endpoint
 * so the merchant's payment.log shows the complete journey, including
 * the steps that never reached the server.
 *
 * Loaded only when the operator has set `tejcart_log_level` to `info`
 * or `debug` (the enqueue is gated server-side); never present on
 * production-default stores.
 *
 * Exposes a single global:
 *
 *     window.tejcartPaymentTelemetry.track(event, data)
 *
 * Gateway scripts (paypal.js, stripe.js, apple-pay.js, google-pay.js)
 * can call it directly from their wallet callbacks; the auto-bind code
 * at the bottom of the file also picks up clicks on `.tejcart-place-order`
 * and `[data-tejcart-payment-button]` without explicit wiring.
 */
( function () {
	'use strict';

	var config = window.TEJCART_PAYMENT_TELEMETRY || null;
	if ( ! config || ! config.endpoint ) {
		return;
	}

	var allowedEvents = Array.isArray( config.allowedEvents ) ? config.allowedEvents : [];
	var allowed       = Object.create( null );
	for ( var i = 0; i < allowedEvents.length; i++ ) {
		allowed[ allowedEvents[ i ] ] = true;
	}

	/**
	 * Best-effort POST. Prefers sendBeacon (survives navigations, which
	 * is exactly what a "user clicked checkout" event needs) and falls
	 * back to fetch with keepalive for browsers that don't support
	 * sendBeacon for JSON payloads.
	 */
	function send( payload ) {
		try {
			var body = JSON.stringify( payload );
			if ( navigator && typeof navigator.sendBeacon === 'function' ) {
				var blob = new Blob( [ body ], { type: 'application/json' } );
				// sendBeacon ignores custom headers — the X-WP-Nonce is
				// appended as the WordPress-standard `_wpnonce` query param
				// instead, which the endpoint reads as a fallback when the
				// header is absent.
				var url  = config.endpoint;
				if ( config.nonce ) {
					url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + '_wpnonce=' + encodeURIComponent( config.nonce );
				}
				if ( navigator.sendBeacon( url, blob ) ) {
					return;
				}
			}
			if ( typeof fetch === 'function' ) {
				fetch( config.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   config.nonce || ''
					},
					body: body
				} ).catch( function () { /* drop on the floor */ } );
			}
		} catch ( e ) {
			// Never let a telemetry failure crash the page.
		}
	}

	/**
	 * Public entry point.
	 *
	 * @param {string} event   One of the allowedEvents enum values.
	 * @param {Object} [data]  Optional extras. Recognised keys:
	 *                         gateway, button_id, order_id, extra (object).
	 */
	function track( event, data ) {
		if ( ! event || ! allowed[ event ] ) {
			return;
		}
		data = data || {};
		var payload = {
			event:          event,
			gateway:        ( data.gateway || '' ).toString().toLowerCase(),
			button_id:      data.button_id || '',
			order_id:       data.order_id ? parseInt( data.order_id, 10 ) || 0 : 0,
			page_url:       window.location && window.location.pathname ? window.location.pathname : '',
			plugin_version: config.pluginVersion || '',
			extra:          data.extra && typeof data.extra === 'object' ? data.extra : {}
		};
		send( payload );
	}

	// Auto-instrument the canonical "Place Order" + named payment buttons.
	// data-tejcart-payment-button="<gateway>" on any clickable element
	// produces a single `button.click` beacon per click. Gateway script
	// can override / refine via the explicit `track()` API.
	function autoBind() {
		var bound = false;
		var binder = function () {
			if ( bound ) {
				return;
			}
			bound = true;
			document.addEventListener( 'click', function ( e ) {
				var t = e.target;
				if ( ! t || ! t.closest ) {
					return;
				}
				var trigger = t.closest( '[data-tejcart-payment-button], .tejcart-place-order, #tejcart-place-order' );
				if ( ! trigger ) {
					return;
				}
				track( 'button.click', {
					gateway:   trigger.getAttribute( 'data-tejcart-payment-button' ) || trigger.getAttribute( 'data-gateway' ) || '',
					button_id: trigger.id || trigger.getAttribute( 'data-tejcart-button-id' ) || '',
					order_id:  trigger.getAttribute( 'data-order-id' ) || 0
				} );
			}, true );

			// One page.view per pageload so we know which page the buyer
			// reached the checkout from. Fires once, on bind.
			track( 'page.view', {
				gateway: '',
				extra:   {
					title:  ( document.title || '' ).substring( 0, 120 ),
					ref:    ( document.referrer || '' ).substring( 0, 256 )
				}
			} );
		};

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', binder, { once: true } );
		} else {
			binder();
		}
	}

	window.tejcartPaymentTelemetry = {
		track:         track,
		allowedEvents: allowedEvents
	};

	autoBind();
} )();
