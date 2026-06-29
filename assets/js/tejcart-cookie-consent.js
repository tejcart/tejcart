/**
 * TejCart cookie-consent banner.
 *
 * Reads its configuration from the localized `tejcartCookieConsent` object
 * (set via wp_localize_script) and writes the consent cookie on Accept/Decline.
 * No jQuery, no AJAX, no extra HTTP round-trip.
 */
( function () {
	'use strict';

	function init() {
		var cfg = window.tejcartCookieConsent || {};
		var banner = document.querySelector( '[data-tejcart-cookie-banner]' );
		if ( ! banner ) {
			return;
		}

		var accept = banner.querySelector( '[data-tejcart-cookie-accept]' );
		var decline = banner.querySelector( '[data-tejcart-cookie-decline]' );

		function setCookie( value ) {
			var maxAge = parseInt( cfg.ttl, 10 ) || 31536000;
			var path = cfg.path || '/';
			var domain = cfg.domain || '';
			var key = cfg.key || 'tejcart_consent';
			var attrs = '; path=' + path + '; max-age=' + maxAge + '; SameSite=Lax';
			if ( cfg.secure ) {
				attrs += '; Secure';
			}
			if ( domain ) {
				attrs += '; domain=' + domain;
			}
			document.cookie = key + '=' + encodeURIComponent( value ) + attrs;
			banner.setAttribute( 'hidden', '' );
		}

		if ( accept ) {
			accept.addEventListener( 'click', function () {
				setCookie( '1' );
			} );
		}
		if ( decline ) {
			decline.addEventListener( 'click', function () {
				setCookie( '0' );
			} );
		}
	}

	// Run after the DOM is parsed so the banner markup is guaranteed to exist
	// regardless of where an optimisation plugin places this script.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
