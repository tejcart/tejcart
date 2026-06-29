/**
 * TejCart front-end CAPTCHA / bot-gate token provider.
 *
 * Supplies the client-side half of the optional `captcha` module: it loads
 * the configured provider's API (Cloudflare Turnstile, hCaptcha or Google
 * reCAPTCHA v3), obtains a verification token on demand and exposes a small
 * promise-based API the cart / checkout scripts call to attach the token
 * (as `tejcart_bot_token`) to their AJAX submissions. On the wp-login.php
 * screen it self-wires the login form so the same token rides the login
 * POST that {@see \TejCart\Captcha\Bot_Gate::gate_login_action} validates.
 *
 * All three providers are driven in their "invisible / execute" mode so a
 * legitimate buyer never sees an interstitial unless the provider itself
 * decides a challenge is warranted. When no provider is configured the
 * module never enqueues this file, so `window.tejcartCaptcha` is simply
 * absent and every consumer falls back to sending no token (which the
 * server-side gate treats as "provider = none → pass").
 *
 * The config object is injected by PHP via wp_localize_script as
 * `window.tejcartCaptchaConfig = { provider, sitekey }`.
 */
( function () {
	'use strict';

	var cfg      = window.tejcartCaptchaConfig || { provider: 'none', sitekey: '' };
	var PROVIDER = cfg.provider || 'none';
	var SITEKEY  = cfg.sitekey || '';

	// Per-call safety ceiling: if a provider never resolves (blocked
	// network, ad-blocker eating the API script) we resolve with an empty
	// token rather than hanging the buyer's submit forever. An empty token
	// means the server-side gate fails closed for that request, which is
	// the correct posture for a bot-protection layer.
	var TOKEN_TIMEOUT_MS = 20000;
	var API_READY_MS     = 8000;

	// Lazily-created widget handle for the providers that need an explicit
	// render (Turnstile / hCaptcha). reCAPTCHA v3 is render-less.
	var widgetId         = null;
	var turnstilePending = null;

	function isActive() {
		return 'none' !== PROVIDER && '' !== SITEKEY;
	}

	/**
	 * Inject a third-party <script> once, resolving when it has loaded.
	 *
	 * @param {string} src Absolute script URL.
	 * @return {Promise}
	 */
	function loadScript( src ) {
		return new Promise( function ( resolve, reject ) {
			var selector = 'script[data-tejcart-captcha-src="' + src + '"]';
			var existing = document.querySelector( selector );
			if ( existing ) {
				if ( '1' === existing.getAttribute( 'data-loaded' ) ) {
					resolve();
				} else {
					existing.addEventListener( 'load', function () { resolve(); } );
					existing.addEventListener( 'error', function () { reject( new Error( 'captcha script error' ) ); } );
				}
				return;
			}
			var s = document.createElement( 'script' );
			s.src   = src;
			s.async = true;
			s.defer = true;
			s.setAttribute( 'data-tejcart-captcha-src', src );
			s.addEventListener( 'load', function () {
				s.setAttribute( 'data-loaded', '1' );
				resolve();
			} );
			s.addEventListener( 'error', function () { reject( new Error( 'captcha script error' ) ); } );
			( document.head || document.body || document.documentElement ).appendChild( s );
		} );
	}

	/**
	 * Poll until `predicate()` is truthy or the timeout elapses.
	 *
	 * @param {Function} predicate
	 * @param {number}   timeoutMs
	 * @return {Promise}
	 */
	function waitFor( predicate, timeoutMs ) {
		return new Promise( function ( resolve, reject ) {
			var start = Date.now();
			( function poll() {
				if ( predicate() ) { resolve(); return; }
				if ( Date.now() - start > timeoutMs ) { reject( new Error( 'captcha api timeout' ) ); return; }
				setTimeout( poll, 50 );
			} )();
		} );
	}

	/**
	 * Off-screen container the Turnstile / hCaptcha widgets render into.
	 * Invisible widgets render no visible chrome; the element only needs to
	 * exist in the DOM so the provider can mount and (when it decides a
	 * challenge is needed) pop its own modal.
	 *
	 * @return {HTMLElement}
	 */
	function ensureContainer() {
		var el = document.getElementById( 'tejcart-captcha-host' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'tejcart-captcha-host';
			el.className = 'tejcart-captcha-host';
			( document.body || document.documentElement ).appendChild( el );
		}
		return el;
	}

	function getTokenRecaptcha( action ) {
		return loadScript( 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent( SITEKEY ) )
			.then( function () {
				return waitFor( function () {
					return window.grecaptcha && window.grecaptcha.execute && window.grecaptcha.ready;
				}, API_READY_MS );
			} )
			.then( function () {
				return new Promise( function ( resolve ) {
					window.grecaptcha.ready( function () {
						window.grecaptcha.execute( SITEKEY, { action: action || 'submit' } )
							.then( function ( token ) { resolve( token || '' ); }, function () { resolve( '' ); } );
					} );
				} );
			} );
	}

	function getTokenTurnstile( action ) {
		return loadScript( 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' )
			.then( function () {
				return waitFor( function () {
					return window.turnstile && window.turnstile.render && window.turnstile.execute;
				}, API_READY_MS );
			} )
			.then( function () {
				return new Promise( function ( resolve ) {
					var done   = false;
					var finish = function ( token ) {
						if ( done ) { return; }
						done = true;
						turnstilePending = null;
						resolve( token || '' );
					};
					var container = ensureContainer();

					if ( null === widgetId ) {
						widgetId = window.turnstile.render( container, {
							sitekey: SITEKEY,
							execution: 'execute',
							callback: function ( token ) { if ( turnstilePending ) { turnstilePending( token ); } },
							'error-callback': function () { if ( turnstilePending ) { turnstilePending( '' ); } },
							'timeout-callback': function () { if ( turnstilePending ) { turnstilePending( '' ); } }
						} );
					} else {
						try { window.turnstile.reset( widgetId ); } catch ( e ) {}
					}

					turnstilePending = finish;
					try {
						window.turnstile.execute( widgetId, { action: action || 'submit' } );
					} catch ( e ) {
						finish( '' );
					}
					setTimeout( function () { finish( '' ); }, TOKEN_TIMEOUT_MS );
				} );
			} );
	}

	function getTokenHcaptcha( action ) {
		return loadScript( 'https://js.hcaptcha.com/1/api.js?render=explicit' )
			.then( function () {
				return waitFor( function () {
					return window.hcaptcha && window.hcaptcha.render && window.hcaptcha.execute;
				}, API_READY_MS );
			} )
			.then( function () {
				var container = ensureContainer();
				if ( null === widgetId ) {
					widgetId = window.hcaptcha.render( container, { sitekey: SITEKEY, size: 'invisible' } );
				} else {
					try { window.hcaptcha.reset( widgetId ); } catch ( e ) {}
				}
				return window.hcaptcha.execute( widgetId, { async: true } ).then(
					function ( res ) { return ( res && res.response ) || ''; },
					function () { return ''; }
				);
			} );
	}

	/**
	 * Obtain a fresh verification token for the given gate action.
	 * Always resolves (never rejects); an empty string signals "no token"
	 * and the server gate fails closed for that request.
	 *
	 * @param {string} action One of: checkout, cart_add, coupon_apply, login.
	 * @return {Promise<string>}
	 */
	function getToken( action ) {
		if ( ! isActive() ) { return Promise.resolve( '' ); }

		var p;
		switch ( PROVIDER ) {
			case 'recaptcha_v3':
				p = getTokenRecaptcha( action );
				break;
			case 'turnstile':
				p = getTokenTurnstile( action );
				break;
			case 'hcaptcha':
				p = getTokenHcaptcha( action );
				break;
			default:
				return Promise.resolve( '' );
		}

		return p.catch( function () { return ''; } );
	}

	/**
	 * Convenience: fetch a token and append it to a FormData instance as
	 * `tejcart_bot_token`. Resolves with the token (or '' on failure).
	 *
	 * @param {FormData} formData
	 * @param {string}   action
	 * @return {Promise<string>}
	 */
	function appendTo( formData, action ) {
		return getToken( action ).then( function ( token ) {
			if ( token && formData && typeof formData.append === 'function' ) {
				formData.append( 'tejcart_bot_token', token );
			}
			return token;
		} );
	}

	/**
	 * On wp-login.php, intercept the login form submit, fetch a token and
	 * carry it on the POST as a hidden `tejcart_bot_token` field. The gate
	 * only enforces the token after repeated failures, but attaching it on
	 * every attempt keeps the flow seamless once enforcement kicks in.
	 */
	function wireLoginForm() {
		if ( ! isActive() ) { return; }
		var form = document.getElementById( 'loginform' );
		if ( ! form ) { return; }

		var released = false;
		form.addEventListener( 'submit', function ( e ) {
			if ( released ) { return; }
			e.preventDefault();

			var input = form.querySelector( 'input[name="tejcart_bot_token"]' );
			if ( ! input ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = 'tejcart_bot_token';
				form.appendChild( input );
			}

			getToken( 'login' ).then( function ( token ) {
				input.value = token || '';
				released = true;
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			} );
		} );
	}

	window.tejcartCaptcha = {
		isActive: isActive,
		getToken: getToken,
		appendTo: appendTo
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', wireLoginForm );
	} else {
		wireLoginForm();
	}
} )();
