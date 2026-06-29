/**
 * TejCart PayPal JS SDK v6 Integration
 *
 * Uses PayPal Web SDK v6 with modular architecture.
 * Each payment method renders independently based on container divs.
 * Server-side order creation ensures price integrity.
 *
 * @package TejCart
 * @see https://developer.paypal.com/docs/checkout/advanced/integrate/
 */

( function () {
    'use strict';

    // Gateway redirect URLs may be cross-origin (e.g. PayPal approve URL), so allow any http(s) and reject only dangerous schemes.
    function isSafeUrl( url ) {
        if ( ! url ) return false;
        if ( url.charAt( 0 ) === '/' && url.charAt( 1 ) !== '/' ) return true;
        try {
            var parsed = new URL( url );
            return parsed.protocol === 'https:' || parsed.protocol === 'http:';
        } catch ( e ) {
            return false;
        }
    }

    var ppParams = window.tejcart_paypal_params || {};
    var sdkInstance = null;

    // Google Pay lifecycle diagnostics. OFF by default so a shopper's console
    // stays clean; every step (1 → 11) logs through gpLog()/gpWarn() under the
    // "[TejCart GooglePay]" prefix only when diagnostics are enabled. Turn it
    // on for support either server-side via the `tejcart_paypal_debug` filter
    // (sets ppParams.debug, defaults to WP_DEBUG) or, without a reload, from
    // the browser console with:
    //   window.tejcartGooglePayDebug = true
    // The flag is re-read on every call so it can be toggled live.
    function gpDebugEnabled() {
        return !! ( ppParams && ppParams.debug ) ||
            ( typeof window !== 'undefined' && window.tejcartGooglePayDebug === true );
    }

    function gpLog( step, data ) {
        if ( ! gpDebugEnabled() ) return;
        var label = '[TejCart GooglePay] ' + step;
        if ( arguments.length < 2 ) {
            console.log( label );
        } else {
            console.log( label, data );
        }
    }

    function gpWarn( step, data ) {
        if ( ! gpDebugEnabled() ) return;
        var label = '[TejCart GooglePay] ' + step;
        if ( arguments.length < 2 ) {
            console.warn( label );
        } else {
            console.warn( label, data );
        }
    }

    // The cart-drawer code (tejcart-cart.js) rotates its own
    // `tejcart_nonce` against `tejcart_refresh_nonce` and announces the
    // new value via a `tejcart:auth-changed` custom event. The PayPal
    // module reads `ppParams.cart_nonce` (a `tejcart_nonce`) for
    // `addProductToCartForExpress`, so subscribe to the same event and
    // pull the fresh value out of the global params object that
    // tejcart-cart.js has already mutated. This keeps the PDP express
    // buttons in sync after a login/logout in another tab without
    // reloading the page.
    if ( typeof document !== 'undefined' && document.addEventListener ) {
        document.addEventListener( 'tejcart:auth-changed', function () {
            var globalParams = window.tejcart_params;
            if ( globalParams && typeof globalParams.nonce === 'string' && globalParams.nonce ) {
                ppParams.cart_nonce = globalParams.nonce;
            }
        } );
    }

    // Module-level double-click guard. The Smart Buttons
    // and the standalone wallet buttons (Venmo, Apple Pay, Google Pay,
    // Card Fields) all open a wallet sheet via `createOrder` → `start()`,
    // which is non-idempotent server side: each call inserts a pending
    // order row and reserves stock. Guard against rapid double-clicks
    // and a stuck loading state by tracking whether a wallet flow is
    // currently in flight, and by remembering which button element
    // triggered the flow so we can re-enable it on every terminal
    // callback (success/error/cancel).
    var pendingWalletFlow = false;
    var lastTriggerEl = null;

    /**
     * Run the checkout-page form validator if the host page exposes one
     * via `window.tejcartCheckout.validate` (see `tejcart-checkout.js`).
     * Returns true when the wallet flow is allowed to proceed and false
     * when validation surfaced inline errors that the buyer must fix
     * before the popup opens. Fails open when the API is absent so
     * non-checkout placements (PDP / cart / drawer / express strip)
     * behave exactly as before.
     */
    function runCheckoutPreflight() {
        var api = window.tejcartCheckout;
        if ( ! api || typeof api.validate !== 'function' ) {
            return true;
        }
        try {
            return api.validate() !== false;
        } catch ( e ) {
            return true;
        }
    }

    /**
     * Wallet-flow preflight: Shopify-style implicit terms acceptance.
     *
     * Wallet / express buttons (PayPal, Venmo, Apple Pay, Google Pay)
     * render a single visible "By continuing, you agree to the Terms
     * and Conditions" disclaimer beneath the button row (see
     * `appendWalletDisclaimer()`). The click itself is the buyer's
     * affirmative consent, so we auto-tick the required terms checkbox
     * here before running the standard preflight — otherwise the form
     * validator surfaces an unchecked-terms error every time the buyer
     * clicks a wallet button (the checkbox sits below the button so
     * the buyer never sees it before clicking).
     *
     * Falls back to plain `runCheckoutPreflight()` when no terms
     * checkbox is present (the merchant has not enabled one) or when
     * the host page hasn't loaded the checkout JS yet.
     */
    function runWalletPreflight() {
        var api = window.tejcartCheckout;
        if ( api && typeof api.acceptTerms === 'function' ) {
            try { api.acceptTerms(); } catch ( _e ) { /* ignore */ }
        }
        return runCheckoutPreflight();
    }

    /**
     * Render the single "By continuing, you agree to the Terms" legal
     * line for a wallet-button zone.
     *
     * Every wallet surface (express checkout, cart, side-cart drawer,
     * product page) lays its per-wallet button containers out as a flex
     * row/stack inside one shared zone. The disclaimer is hung off that
     * shared zone — on its own full-width line beneath the buttons — so a
     * row of express buttons (PayPal + Venmo + Google Pay + Apple Pay)
     * shows ONE shared "you agree to the Terms" line instead of repeating
     * it under every button. The wording is wallet-agnostic precisely
     * because one line now covers the whole stack.
     *
     * Idempotent and order-independent: whichever wallet button renders
     * first creates the line, and every later button in the same zone is
     * a no-op. No-op entirely when the merchant has not enabled a terms
     * checkbox on this page (nothing to disclaim — the line would be
     * misleading). The text and link mirror the merchant's Terms page
     * from the existing `[data-tejcart-terms]` checkbox so the disclaimer
     * stays in sync with whatever the merchant configured.
     */
    function appendWalletDisclaimer( container ) {
        if ( ! container ) return;
        // The zone is the shared flex row/stack the button containers sit
        // in — fall back to the immediate parent (then the container) for
        // any bespoke placement that isn't one of the known wallet zones.
        var zone = ( typeof container.closest === 'function' &&
            container.closest( '.tejcart-express-checkout-buttons, .tejcart-product-smart-buttons' ) ) ||
            container.parentElement || container;
        if ( zone.querySelector( '.tejcart-wallet-button-disclaimer' ) ) return;
        var termsScope = document.querySelector( '[data-tejcart-terms]' );
        if ( ! termsScope ) return;
        var termsBox = termsScope.querySelector( 'input[type="checkbox"]' );
        if ( ! termsBox ) return;

        var termsLinkHref = '';
        var termsLinkEl   = termsScope.querySelector( 'a[href]' );
        if ( termsLinkEl ) {
            termsLinkHref = termsLinkEl.getAttribute( 'href' ) || '';
        }
        var termsLabel = ( termsLinkEl && termsLinkEl.textContent && termsLinkEl.textContent.trim() ) || 'Terms and Conditions';

        var p = document.createElement( 'p' );
        p.className = 'tejcart-wallet-button-disclaimer';
        p.appendChild( document.createTextNode( 'By continuing, you agree to the ' ) );
        if ( termsLinkHref ) {
            var a = document.createElement( 'a' );
            a.href = termsLinkHref;
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = termsLabel;
            p.appendChild( a );
        } else {
            p.appendChild( document.createTextNode( termsLabel ) );
        }
        p.appendChild( document.createTextNode( '.' ) );
        zone.appendChild( p );
    }

    /**
     * Mark a wallet flow as starting. Returns true if the caller should
     * proceed; false when another flow is already running.
     *
     * Disables the supplied trigger element (the Smart Button shadow root
     * or the standalone DOM element the user clicked) so the buyer's
     * second click is ignored at the browser level too.
     */
    function beginWalletFlow( triggerEl ) {
        if ( pendingWalletFlow ) return false;
        pendingWalletFlow = true;
        lastTriggerEl = triggerEl || null;
        if ( triggerEl ) {
            try { triggerEl.disabled = true; } catch ( e ) {}
            if ( triggerEl.classList && triggerEl.classList.add ) {
                triggerEl.classList.add( 'is-processing' );
            }
        }
        if ( typeof window !== 'undefined' ) {
            try { window.addEventListener( 'beforeunload', warnUnloadDuringPayment ); } catch ( e ) {}
        }
        return true;
    }

    /**
     * Terminal callback for every wallet flow. Resets the guard, re-enables
     * the trigger button, and clears the beforeunload warning.
     */
    function endWalletFlow() {
        pendingWalletFlow = false;
        if ( lastTriggerEl ) {
            try { lastTriggerEl.disabled = false; } catch ( e ) {}
            if ( lastTriggerEl.classList && lastTriggerEl.classList.remove ) {
                lastTriggerEl.classList.remove( 'is-processing' );
            }
            lastTriggerEl = null;
        }
        if ( typeof window !== 'undefined' ) {
            try { window.removeEventListener( 'beforeunload', warnUnloadDuringPayment ); } catch ( e ) {}
        }
    }

    function warnUnloadDuringPayment( e ) {
        if ( ! pendingWalletFlow ) return;
        // Modern browsers ignore the custom message and show their own
        // generic "Leave site?" prompt — but they only show it when the
        // event has a returnValue / preventDefault.
        if ( e ) { e.preventDefault(); e.returnValue = ''; }
        return '';
    }

    /**
     * Returns true when Google Pay is enabled for the given placement key.
     *
     * Placement keys come from the Google Pay gateway settings:
     *   button_product_page, button_cart_page, button_express_checkout,
     *   button_side_cart, button_checkout
     *
     * Falls back to true when the style payload is missing (older installs
     * or the gateway not yet saved) so we do not silently drop buttons that
     * the merchant previously relied on via the parent PayPal toggles.
     */
    function googlePayPlacementEnabled( key ) {
        if ( ! ppParams.enable_google_pay ) return false;
        var style = ppParams.google_pay_style;
        if ( ! style || typeof style !== 'object' ) return true;
        if ( ! ( key in style ) ) return true;
        return !! style[ key ];
    }

    /**
     * Build the option bag passed to `paymentsClient.createButton()`. Reads
     * style preferences from the Google Pay gateway settings; defaults match
     * Google Pay brand guidelines (black, "buy", fill, 6px radius).
     */
    function buildGooglePayButtonOptions( onClick ) {
        var style = ( ppParams.google_pay_style && typeof ppParams.google_pay_style === 'object' )
            ? ppParams.google_pay_style
            : {};

        var color = style.color || 'black';
        if ( color !== 'default' && color !== 'black' && color !== 'white' ) {
            color = 'black';
        }

        var allowedTypes = [ 'book', 'buy', 'checkout', 'donate', 'order', 'pay', 'plain', 'subscribe' ];
        var type = style.type || 'buy';
        if ( allowedTypes.indexOf( type ) === -1 ) type = 'buy';

        var sizeMode = style.size_mode || 'fill';
        if ( sizeMode !== 'fill' && sizeMode !== 'static' ) sizeMode = 'fill';

        var radius = parseInt( style.radius, 10 );
        if ( ! isFinite( radius ) || radius < 0 || radius > 100 ) radius = 6;

        var options = {
            onClick:        onClick,
            buttonColor:    color,
            buttonType:     type,
            buttonSizeMode: sizeMode,
            buttonRadius:   radius,
        };

        if ( style.locale && typeof style.locale === 'string' && style.locale.length ) {
            options.buttonLocale = style.locale;
        }

        return options;
    }

    function initPayPalWhenReady() {
        populateExpressSkeletons();

        if ( ! ppParams.client_id ) return;

        var hasContainer = document.getElementById( 'tejcart-paypal-button-container' )
            || document.getElementById( 'tejcart-card-fields-container' )
            || document.getElementById( 'tejcart-googlepay-container' )
            || document.getElementById( 'tejcart-applepay-container' )
            || document.getElementById( 'tejcart-fastlane-container' )
            || document.querySelector( '[id^="tejcart-express-"]' )
            || document.querySelector( '[id^="tejcart-drawer-express-"]' )
            || document.querySelector( '.tejcart-product-smart-buttons' )
            || document.querySelector( '.tejcart-paylater-message' )
            || document.getElementById( 'tejcart-cart-paypal-btn' );

        if ( ! hasContainer ) return;

        loadSDKv6();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initPayPalWhenReady );
    } else {
        initPayPalWhenReady();
    }

    /**
     * Load PayPal Web SDK v6 core script.
     */
    function loadSDKv6() {
        if ( window.paypal && window.paypal.createInstance ) {
            initSDKInstance();
            return;
        }

        // PayPal's Web SDK v6 is a rolling "core" runtime
        // (similar in posture to js.stripe.com); the documented base URL
        // is /web-sdk/v6/core and there is no documented minor pin. We
        // expose `ppParams.sdk_url` so a release runbook (or a filter
        // on the PHP side via `tejcart_paypal_sdk_url`) can pin to a
        // specific revision when PayPal publishes one, and we set
        // crossorigin="anonymous" so the browser refuses cached
        // responses without proper CORS headers — mitigating a hostile
        // intermediate cache.
        var defaultSdk = ppParams.is_sandbox
            ? 'https://www.sandbox.paypal.com/web-sdk/v6/core'
            : 'https://www.paypal.com/web-sdk/v6/core';
        var baseUrl = ppParams.sdk_url || defaultSdk;

        var script = document.createElement( 'script' );
        script.src = baseUrl;
        script.async = true;
        script.crossOrigin = 'anonymous';

        script.onload = function () {
            initSDKInstance();
        };

        script.onerror = function () {
            showGlobalError( 'Failed to load payment SDK. Please refresh and try again.' );
        };

        document.head.appendChild( script );
    }

    /**
     * Initialize SDK v6 instance with createInstance().
     */
    function initSDKInstance() {
        if ( ! window.paypal || ! window.paypal.createInstance ) {
            showGlobalError( 'Payment SDK not available.' );
            return;
        }

        var components = [ 'paypal-payments' ];

        if ( document.getElementById( 'tejcart-card-fields-container' ) ) {
            components.push( 'card-fields' );
        }
        if ( ppParams.enable_venmo ) {
            components.push( 'venmo-payments' );
        }
        if ( ppParams.enable_paylater ) {
            components.push( 'paypal-messages' );
        }

        if ( ppParams.enable_fastlane ) {
            components.push( 'fastlane' );
        }

        var googlePayContainerPresent = !! (
            document.getElementById( 'tejcart-googlepay-container' )
            || document.getElementById( 'tejcart-cart-googlepay-btn' )
            || document.getElementById( 'tejcart-express-googlepay' )
            || document.getElementById( 'tejcart-drawer-express-googlepay' )
            || document.querySelector( '.tejcart-product-googlepay-btn' )
        );
        gpLog( 'Step 1 — SDK component check', {
            enable_google_pay: !! ppParams.enable_google_pay,
            is_sandbox: !! ppParams.is_sandbox,
            container_present: googlePayContainerPresent
        } );
        if ( ppParams.enable_google_pay && googlePayContainerPresent ) {
            components.push( 'googlepay-payments' );
            gpLog( 'Step 1 — requesting "googlepay-payments" SDK component' );
        } else if ( ppParams.enable_google_pay ) {
            gpWarn( 'Step 1 — Google Pay enabled but no button container found on this page; component not requested' );
        }

        if ( ppParams.enable_apple_pay
            && ( document.getElementById( 'tejcart-applepay-container' )
                || document.getElementById( 'tejcart-cart-applepay-btn' )
                || document.getElementById( 'tejcart-drawer-express-applepay' )
                || document.querySelector( '#tejcart-express-applepay, .tejcart-product-applepay-btn' ) ) ) {
            components.push( 'applepay-payments' );
        }

        var config = {
            clientId:   ppParams.client_id,
            components: components,
            pageType:   'checkout',
        };

        if ( ppParams.locale ) {
            config.locale = ppParams.locale;
        }

        if ( ppParams.disable_funding && ppParams.disable_funding.length ) {
            config.disableFunding = ppParams.disable_funding;
        }

        window.paypal.createInstance( config ).then( function ( instance ) {
            sdkInstance = instance;
            onSDKReady();
        } ).catch( function ( err ) {
            console.error( 'TejCart PayPal SDK init error:', err );
            showGlobalError( 'Payment initialization failed. Please refresh.' );
        } );
    }

    /**
     * Called once SDK v6 instance is ready. Renders each payment method.
     */
    function onSDKReady() {
        if ( ! sdkInstance ) return;

        var currencyCode = String( ppParams.currency || 'USD' ).toUpperCase();

        sdkInstance.findEligibleMethods( {
            currencyCode: currencyCode,
        } ).then( function ( methods ) {
            if (
                ppParams.button_express_checkout
                || ppParams.button_side_cart
                || googlePayPlacementEnabled( 'button_express_checkout' )
                || googlePayPlacementEnabled( 'button_side_cart' )
            ) {
                renderExpressCheckout( methods );
            }

            if ( ppParams.button_product_page || googlePayPlacementEnabled( 'button_product_page' ) ) {
                renderProductPageButtons( methods );
            }

            try { renderPayLaterMessages(); } catch ( e ) {}

            if ( document.getElementById( 'tejcart-paypal-button-container' ) && methods.isEligible( 'paypal' ) ) {
                renderPayPalButtons();
            }

            if ( ppParams.button_cart_page && methods.isEligible( 'paypal' ) ) {
                var cartBtn = document.getElementById( 'tejcart-cart-paypal-btn' );
                if ( cartBtn && ! cartBtn.hasChildNodes() ) {
                    renderPayPalButtonInContainer(
                        cartBtn,
                        buildButtonStyle( { layout: 'horizontal', defaultHeight: 40 } ),
                        expressCreateOrder,
                        expressOnApprove
                    );
                }
            }
            var cartVm = document.getElementById( 'tejcart-cart-venmo-btn' );
            if ( cartVm && ! cartVm.hasChildNodes() ) {
                if ( ppParams.enable_venmo && methods.isEligible( 'venmo' ) ) {
                    renderVenmoButton( cartVm, expressCreateOrder, expressOnApprove );
                } else {
                    removeContainerIfEmpty( cartVm );
                }
            }

            var googlepayDetails = null;
            if ( ppParams.enable_google_pay ) {
                var gpEligible = false;
                try {
                    gpEligible = !! methods.isEligible( 'googlepay' );
                } catch ( eligErr ) {
                    gpWarn( 'Step 2 — methods.isEligible("googlepay") threw', eligErr );
                }
                gpLog( 'Step 2 — eligibility', { isEligible: gpEligible } );
                if ( gpEligible ) {
                    try {
                        googlepayDetails = methods.getDetails( 'googlepay' );
                        gpLog( 'Step 2 — getDetails("googlepay") returned', {
                            hasDetails: !! googlepayDetails,
                            hasConfig: !! ( googlepayDetails && googlepayDetails.config )
                        } );
                    } catch ( e ) {
                        googlepayDetails = null;
                        gpWarn( 'Step 2 — getDetails("googlepay") threw — Google Pay will not render', e );
                    }
                } else {
                    gpWarn( 'Step 2 — Google Pay not eligible (PayPal SDK). Common causes: Google Pay not approved on the PayPal account, unsupported device/browser, or wrong environment.' );
                }
            }

            var applepayDetails = null;
            if ( ppParams.enable_apple_pay && methods.isEligible( 'applepay' ) ) {
                try {
                    applepayDetails = methods.getDetails( 'applepay' );
                } catch ( e ) {
                    applepayDetails = null;
                }
            }

            var cartGp = document.getElementById( 'tejcart-cart-googlepay-btn' );
            if ( cartGp && ! cartGp.hasChildNodes() ) {
                if ( googlepayDetails && googlePayPlacementEnabled( 'button_cart_page' ) ) {
                    renderGooglePayButton( cartGp, googlepayDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( cartGp );
                }
            }
            var cartAp = document.getElementById( 'tejcart-cart-applepay-btn' );
            if ( cartAp && ! cartAp.hasChildNodes() ) {
                if ( applepayDetails ) {
                    renderApplePayButton( cartAp, applepayDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( cartAp );
                }
            }

            if ( document.getElementById( 'tejcart-card-fields-container' ) ) {
                renderCardFields();
            }

            var checkoutGpContainer = document.getElementById( 'tejcart-googlepay-container' );
            if ( checkoutGpContainer && googlepayDetails && googlePayPlacementEnabled( 'button_checkout' ) ) {
                renderGooglePay( googlepayDetails );
            } else if ( checkoutGpContainer && ! checkoutGpContainer.hasChildNodes() ) {
                bailGooglePayButton( checkoutGpContainer );
            }

            if ( document.getElementById( 'tejcart-applepay-container' ) && applepayDetails ) {
                renderApplePay( applepayDetails );
            }

            markExpressContainersReady();
        } ).catch( function ( err ) {
            console.warn( 'TejCart PayPal eligibility check failed, falling back to PayPal-only rendering:', {
                name:         err && err.name,
                message:      err && err.message,
                code:         err && err.code,
                details:      err && err.details,
                debug_id:     err && err.debug_id,
                response:     err && err.response,
                currencyCode: currencyCode,
                merchantId:   ppParams.merchant_id,
                isSandbox:    ppParams.is_sandbox,
            } );

            var fallbackMethods = {
                isEligible: function ( method ) {
                    return method === 'paypal';
                },
            };

            if ( ppParams.button_express_checkout || ppParams.button_side_cart ) {
                try { renderExpressCheckout( fallbackMethods ); } catch ( e ) {}
            }
            if ( ppParams.button_product_page ) {
                try { renderProductPageButtons( fallbackMethods ); } catch ( e ) {}
            }
            try { renderPayLaterMessages(); } catch ( e ) {}

            if ( document.getElementById( 'tejcart-paypal-button-container' ) ) {
                try { renderPayPalButtons(); } catch ( e ) {}
            }
            if ( ppParams.button_cart_page ) {
                var cartBtn = document.getElementById( 'tejcart-cart-paypal-btn' );
                if ( cartBtn && ! cartBtn.hasChildNodes() ) {
                    try {
                        renderPayPalButtonInContainer(
                            cartBtn,
                            buildButtonStyle( { layout: 'horizontal', defaultHeight: 40 } ),
                            expressCreateOrder,
                            expressOnApprove
                        );
                    } catch ( e ) {}
                }
            }

            if ( document.getElementById( 'tejcart-card-fields-container' ) ) {
                try { renderCardFields(); } catch ( e ) {
                    hideCardPaymentMethod();
                }
            }

            var fallbackGpContainer = document.getElementById( 'tejcart-googlepay-container' );
            if ( fallbackGpContainer && ! fallbackGpContainer.hasChildNodes() ) {
                bailGooglePayButton( fallbackGpContainer );
            }

            markExpressContainersReady();
        } );
    }

    /**
     * Flip every express-checkout button container into its .is-ready
     * state so the skeleton shimmer overlay hides and the real button
     * surfaces become the only visible elements. Called after the SDK
     * has finished its render pass on both the success and fallback
     * paths inside onSDKReady().
     *
     * Also drops any wrapper that ended up without a single rendered
     * button, so the surrounding "Or pay with" / "Or pay quickly with"
     * label and the skeleton shimmer don't leave a visible empty
     * block on the page.
     */
    function safeGetDetails( methods, name ) {
        if ( ! methods || typeof methods.getDetails !== 'function' ) return null;
        try {
            var d = methods.getDetails( name );
            return d || null;
        } catch ( e ) {
            console.warn( 'TejCart PayPal: getDetails(' + name + ') threw', e );
            return null;
        }
    }

    function markExpressContainersReady() {
        var containers = document.querySelectorAll( '.tejcart-express-checkout-buttons' );
        containers.forEach( function ( el ) {
            el.classList.add( 'is-ready' );

            // Sweep any button slots that ended up empty — the SDK fallback
            // path skips per-slot cleanup, and a wallet that the eligibility
            // call marked OK can still fail to draw. Each leftover slot has
            // a 45px min-height in the cart sidebar, so left in place they
            // visibly reserve blank rows below the wallets that did render.
            var slots = el.querySelectorAll(
                ':scope > div:not(.tejcart-express-checkout-skeleton)'
            );
            slots.forEach( function ( slot ) {
                // Skip slots whose wallet button is still rendering
                // asynchronously (Google Pay's isReadyToPay round-trip):
                // removing them now would detach the container before the
                // button is appended, so it would render into a node that is
                // no longer in the document. renderGooglePayButton() clears the
                // flag and tidies its own slot if the wallet turns out
                // ineligible.
                if ( ! slot.hasChildNodes()
                    && ! slot.hasAttribute( 'data-tejcart-wallet-pending' )
                    && slot.parentNode ) {
                    slot.parentNode.removeChild( slot );
                }
            } );

            // Sweeping ineligible slots above leaves the skeleton holding
            // more placeholder rows than there are button slots — a wallet
            // can be enabled (some default to 'yes') yet stay ineligible on
            // the shopper's browser, so its slot renders empty and is swept.
            // Drop the orphaned rows so the loading state never shows more
            // placeholders than wallets that will actually render.
            syncExpressSkeleton( el );

            // populateExpressSkeletons() pre-sized this zone for the FULL
            // configured wallet count so the absolutely-positioned skeleton
            // overlay could show every placeholder row. With the skeleton
            // now hidden (`is-ready`) and ineligible slots swept above, the
            // rendered buttons define the natural height — keeping the
            // pre-sized min-height would reserve dead space below them.
            if ( el.style && el.style.minHeight ) {
                el.style.minHeight = '';
            }

            removeWrapperIfNoButtons( el );
        } );
    }

    /**
     * Reconcile a zone's skeleton placeholder rows to its current button
     * slot count, adding or removing rows so the loading shimmer never
     * shows more (or fewer) placeholders than wallets that will render.
     * Safe to call repeatedly as ineligible slots are swept out.
     */
    function syncExpressSkeleton( zone ) {
        if ( ! zone ) return;
        var skel = zone.querySelector( ':scope > .tejcart-express-checkout-skeleton' );
        if ( ! skel ) return;
        var count = zone.querySelectorAll(
            ':scope > div:not(.tejcart-express-checkout-skeleton)'
        ).length;
        while ( skel.childElementCount > count ) {
            skel.removeChild( skel.lastElementChild );
        }
        while ( skel.childElementCount < count ) {
            var row = document.createElement( 'div' );
            row.className = 'tejcart-express-checkout-skeleton-row';
            skel.appendChild( row );
        }
    }

    /**
     * Inject one skeleton placeholder row per rendered button container
     * inside every `.tejcart-express-checkout-buttons` zone, so the
     * loading state always matches the wallet count the merchant has
     * enabled. Templates render an empty `.tejcart-express-checkout-skeleton`
     * wrapper and rely on this to populate it before paint.
     */
    function populateExpressSkeletons() {
        var zones = document.querySelectorAll( '.tejcart-express-checkout-buttons' );
        zones.forEach( function ( zone ) {
            var skel = zone.querySelector( ':scope > .tejcart-express-checkout-skeleton' );
            if ( ! skel || skel.childElementCount > 0 ) return;
            var slots = zone.querySelectorAll(
                ':scope > div:not(.tejcart-express-checkout-skeleton)'
            );
            syncExpressSkeleton( zone );

            // Cart-page and drawer zones stack wallets vertically. The
            // skeleton overlay is absolutely positioned, so the parent
            // needs an explicit min-height that matches the stacked
            // placeholder count or the overlay would clip to the
            // parent's natural height (~45px) and only show one row.
            var isColumn = zone.classList.contains( 'tejcart-cart-express-zone' )
                || zone.classList.contains( 'tejcart-cart-smart-buttons' )
                || zone.classList.contains( 'tejcart-cart-drawer-express' );
            if ( isColumn && slots.length > 1 ) {
                var rowH = 45;
                var gap  = 8;
                zone.style.minHeight = ( slots.length * rowH + ( slots.length - 1 ) * gap ) + 'px';
            }
        } );
    }

    /**
     * Drop a button slot from the DOM when nothing was rendered into
     * it (PayPal / Google Pay / Apple Pay ineligible, SDK unavailable,
     * or device missing the required APIs). Leaving these divs in
     * place reserves 45px of empty space next to the buttons that did
     * load — the shopper sees a gap where a button should be.
     */
    function removeContainerIfEmpty( container ) {
        if ( container && ! container.hasChildNodes() && container.parentNode ) {
            container.parentNode.removeChild( container );
        }
    }

    /**
     * If a `.tejcart-express-checkout-buttons` zone has no rendered
     * button slots left (only the aria-hidden skeleton remains), drop
     * the closest enclosing wrapper so the title label and surrounding
     * chrome don't leave a hollow block on the page.
     */
    function removeWrapperIfNoButtons( buttonsEl ) {
        if ( ! buttonsEl ) return;

        var slots = buttonsEl.querySelectorAll(
            ':scope > div:not(.tejcart-express-checkout-skeleton)'
        );
        var hasRendered = false;
        slots.forEach( function ( slot ) {
            // A slot pending an async wallet render (Google Pay) counts as
            // "will render" so the wrapper isn't dropped before it resolves.
            if ( slot.hasChildNodes() || slot.hasAttribute( 'data-tejcart-wallet-pending' ) ) {
                hasRendered = true;
            }
        } );

        if ( hasRendered ) return;

        var wrapper = buttonsEl.closest(
            '.tejcart-express-checkout, .tejcart-cart-drawer-express-wrap'
        ) || buttonsEl;

        if ( wrapper && wrapper.parentNode ) {
            wrapper.parentNode.removeChild( wrapper );
        }
    }

    document.addEventListener( 'tejcart:totals-updated', function ( e ) {
        if ( ! ppParams.enable_paylater ) {
            return;
        }
        var detail = ( e && e.detail ) || {};
        if ( typeof detail.total !== 'number' || isNaN( detail.total ) ) {
            return;
        }
        var amountStr = detail.total.toFixed( 2 );

        var messages = document.querySelectorAll(
            '.tejcart-paylater-message:not(.tejcart-paylater-product)'
        );
        if ( ! messages.length ) {
            return;
        }
        messages.forEach( function ( el ) {
            el.setAttribute( 'amount', amountStr );
            el.setAttribute( 'data-pp-amount', amountStr );
            while ( el.firstChild ) {
                el.removeChild( el.firstChild );
            }
        } );

        if ( sdkInstance ) {
            try { renderPayLaterMessages(); } catch ( err ) {  }
        }
    } );

    document.addEventListener( 'tejcart:drawer-updated', function () {
        var drawerPp = document.getElementById( 'tejcart-drawer-express-paypal' );
        var drawerVm = document.getElementById( 'tejcart-drawer-express-venmo' );
        var drawerGp = document.getElementById( 'tejcart-drawer-express-googlepay' );
        var drawerAp = document.getElementById( 'tejcart-drawer-express-applepay' );

        if ( ! sdkInstance ) {
            if ( drawerPp || drawerVm || drawerGp || drawerAp ) {
                if ( ppParams.client_id ) {
                    loadSDKv6();
                }
            }
            return;
        }

        var currencyCode = String( ppParams.currency || 'USD' ).toUpperCase();
        sdkInstance.findEligibleMethods( { currencyCode: currencyCode } ).then( function ( methods ) {
            if ( drawerPp && ! drawerPp.hasChildNodes() ) {
                if ( methods.isEligible( 'paypal' ) ) {
                    renderPayPalButtonInContainer( drawerPp, buildButtonStyle( { layout: 'horizontal', defaultHeight: 40 } ), expressCreateOrder, expressOnApprove );
                } else {
                    removeContainerIfEmpty( drawerPp );
                }
            }
            if ( drawerVm && ! drawerVm.hasChildNodes() ) {
                if ( ppParams.enable_venmo && methods.isEligible( 'venmo' ) ) {
                    renderVenmoButton( drawerVm, expressCreateOrder, expressOnApprove );
                } else {
                    removeContainerIfEmpty( drawerVm );
                }
            }
            if ( drawerGp && ! drawerGp.hasChildNodes() ) {
                var drawerGpDetails = ( googlePayPlacementEnabled( 'button_side_cart' ) && methods.isEligible( 'googlepay' ) )
                    ? safeGetDetails( methods, 'googlepay' )
                    : null;
                if ( drawerGpDetails ) {
                    renderGooglePayButton( drawerGp, drawerGpDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( drawerGp );
                }
            }
            if ( drawerAp && ! drawerAp.hasChildNodes() ) {
                var drawerApDetails = ( ppParams.enable_apple_pay && methods.isEligible( 'applepay' ) )
                    ? safeGetDetails( methods, 'applepay' )
                    : null;
                if ( drawerApDetails ) {
                    renderApplePayButton( drawerAp, drawerApDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( drawerAp );
                }
            }

            var drawerMsg = document.querySelector( '.tejcart-cart-drawer .tejcart-paylater-message' );
            if ( drawerMsg && ! drawerMsg.hasChildNodes() ) {
                try { renderPayLaterMessages(); } catch ( e ) {}
            }

            markExpressContainersReady();
        } ).catch( function () {
            if ( drawerPp && ! drawerPp.hasChildNodes() ) {
                try {
                    renderPayPalButtonInContainer( drawerPp, buildButtonStyle( { layout: 'horizontal', defaultHeight: 40 } ), expressCreateOrder, expressOnApprove );
                } catch ( e ) {}
            }
            removeContainerIfEmpty( drawerVm );
            removeContainerIfEmpty( drawerGp );
            removeContainerIfEmpty( drawerAp );
            markExpressContainersReady();
        } );
    } );

    function renderExpressCheckout( methods ) {
        var locations = [
            { paypal: '#tejcart-express-paypal', venmo: '#tejcart-express-venmo', googlepay: '#tejcart-express-googlepay', applepay: '#tejcart-express-applepay', gpPlacement: 'button_express_checkout' },
            { paypal: '#tejcart-drawer-express-paypal', venmo: '#tejcart-drawer-express-venmo', googlepay: '#tejcart-drawer-express-googlepay', applepay: '#tejcart-drawer-express-applepay', gpPlacement: 'button_side_cart' },
        ];

        locations.forEach( function ( loc ) {
            var ppEl = document.querySelector( loc.paypal );
            if ( ppEl && ! ppEl.hasChildNodes() ) {
                if ( methods.isEligible( 'paypal' ) ) {
                    renderPayPalButtonInContainer( ppEl, buildButtonStyle( { layout: 'horizontal', defaultHeight: 40 } ), expressCreateOrder, expressOnApprove );
                } else {
                    removeContainerIfEmpty( ppEl );
                }
            }

            var vmEl = document.querySelector( loc.venmo );
            if ( vmEl && ! vmEl.hasChildNodes() ) {
                if ( ppParams.enable_venmo && methods.isEligible( 'venmo' ) ) {
                    renderVenmoButton( vmEl, expressCreateOrder, expressOnApprove );
                } else {
                    removeContainerIfEmpty( vmEl );
                }
            }

            var gpEl = document.querySelector( loc.googlepay );
            if ( gpEl && ! gpEl.hasChildNodes() ) {
                var expGpDetails = ( googlePayPlacementEnabled( loc.gpPlacement ) && methods.isEligible( 'googlepay' ) )
                    ? safeGetDetails( methods, 'googlepay' )
                    : null;
                if ( expGpDetails ) {
                    renderGooglePayButton( gpEl, expGpDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( gpEl );
                }
            }

            var apEl = document.querySelector( loc.applepay );
            if ( apEl && ! apEl.hasChildNodes() ) {
                var expApDetails = ( ppParams.enable_apple_pay && methods.isEligible( 'applepay' ) )
                    ? safeGetDetails( methods, 'applepay' )
                    : null;
                if ( expApDetails ) {
                    renderApplePayButton( apEl, expApDetails, expressCreateOrder );
                } else {
                    removeContainerIfEmpty( apEl );
                }
            }
        } );
    }

    // Per-order wallet-shipping nonce, indexed by PayPal order id.
    // The server returns this in the create_order response so subsequent
    // wallet_shipping AJAX calls can present an order-bound nonce instead
    // of the page-scoped ppParams.nonce. Closes the cross-order replay
    // window where a stolen page nonce could mutate any of the buyer's
    // pending PayPal orders within the WP nonce lifetime.
    var walletShippingNonces = {};

    function rememberWalletShippingNonce( orderId, nonce ) {
        if ( orderId && nonce ) walletShippingNonces[ String( orderId ) ] = String( nonce );
    }

    function getWalletShippingNonce( orderId ) {
        return orderId ? ( walletShippingNonces[ String( orderId ) ] || '' ) : '';
    }

    function expressCreateOrder() {
        return ajaxRequest( 'tejcart_paypal_create_order', { express: '1' } )
            .then( function ( result ) {
                if ( result.success && result.data && result.data.order_id ) {
                    rememberWalletShippingNonce( result.data.order_id, result.data.wallet_shipping_nonce );
                    return result.data.order_id;
                }
                throw new Error( ( result.data && result.data.message ) || 'Could not create order.' );
            } );
    }

    /**
     * Fire-and-forget cancel hop. The server marks the pending
     * TejCart order cancelled and releases its stock reservation. Errors
     * are intentionally swallowed: a cancel that fails to round-trip is
     * eventually picked up by the periodic cleanup cron, so we never
     * block the buyer's UI on it.
     */
    function cancelPendingPayPalOrder( paypalOrderId ) {
        if ( ! paypalOrderId ) return;
        try {
            ajaxRequest( 'tejcart_paypal_cancel_order', { paypal_order_id: paypalOrderId } )
                .catch( function () {} );
        } catch ( e ) {}
    }

    /**
     * Capture an approved express PayPal order.
     *
     * The optional `errSink(message)` callback is how the side-cart
     * drawer (and any other host that owns its own container) gets the
     * failure surface to render the error inline. When omitted, errors
     * fall through to the page-wide notice surface — which now also
     * covers the drawer button containers via showGlobalError().
     */
    function expressOnApprove( data, errSink ) {
        showLoading();
        // When the wallet returned address details on the
        // approval payload, mirror them into the visible checkout fields
        // so a buyer who later edits the form sees PayPal's data instead
        // of stale empty inputs. Mirrors what Fastlane does at sign-in.
        applyPayPalAddressToFields( data );
        var renderError = function ( message ) {
            if ( typeof errSink === 'function' ) {
                errSink( message );
                return;
            }
            showGlobalError( message );
        };
        return ajaxRequest( 'tejcart_paypal_capture_order', { paypal_order_id: data.orderId, express: '1' } )
            .then( function ( result ) {
                hideLoading();
                endWalletFlow();
                if ( result.success && result.data && result.data.redirect ) {
                    if ( isSafeUrl( result.data.redirect ) ) {
                        window.location.href = result.data.redirect;
                    } else {
                        window.location.href = '/';
                    }
                } else {
                    renderError( formatGatewayError( ( result && result.data ) || {}, 'PayPal' ) );
                }
            } )
            .catch( function ( err ) {
                hideLoading();
                endWalletFlow();
                console.error( 'Express capture failed:', err );
                renderError( formatGatewayError( err, 'PayPal' ) );
            } );
    }

    /**
     * Extract billing/shipping fields from a PayPal
     * `onApprove` data payload (which includes `payer.shipping_address`,
     * `payer.email_address`, etc.) and seed the corresponding form
     * inputs. Fastlane already does this at identity time; standard
     * PayPal historically did not.
     *
     * Defensive across SDK shapes: data may be flat (data.shipping_address)
     * or nested (data.payer.shipping_address). Falls through silently when
     * neither shape is present rather than blocking the capture.
     */
    function applyPayPalAddressToFields( data ) {
        if ( ! data || typeof data !== 'object' ) return;

        var payer    = ( data.payer && typeof data.payer === 'object' ) ? data.payer : data;
        var name     = ( payer.name && typeof payer.name === 'object' ) ? payer.name : {};
        var email    = payer.email_address || payer.emailAddress || payer.email || '';
        var shipping = payer.shipping_address || payer.shippingAddress
            || ( data.shipping && data.shipping.address )
            || data.shipping_address
            || null;

        if ( name.given_name || name.givenName ) {
            setField( 'billing_first_name', name.given_name || name.givenName );
            setField( 'shipping_first_name', name.given_name || name.givenName );
        }
        if ( name.surname || name.familyName ) {
            setField( 'billing_last_name', name.surname || name.familyName );
            setField( 'shipping_last_name', name.surname || name.familyName );
        }
        if ( typeof email === 'string' && email.indexOf( '@' ) !== -1 ) {
            setField( 'billing_email', email );
        }

        if ( shipping && typeof shipping === 'object' ) {
            var line1   = shipping.address_line_1 || shipping.addressLine1 || shipping.line1 || '';
            var line2   = shipping.address_line_2 || shipping.addressLine2 || shipping.line2 || '';
            var city    = shipping.admin_area_2  || shipping.adminArea2  || shipping.city  || '';
            var region  = shipping.admin_area_1  || shipping.adminArea1  || shipping.state || '';
            var postal  = shipping.postal_code   || shipping.postalCode  || shipping.postcode || '';
            var country = shipping.country_code  || shipping.countryCode || shipping.country || '';

            if ( line1 )   { setField( 'billing_address_1', line1 );  setField( 'shipping_address_1', line1 ); }
            if ( line2 )   { setField( 'billing_address_2', line2 );  setField( 'shipping_address_2', line2 ); }
            if ( city )    { setField( 'billing_city', city );        setField( 'shipping_city', city ); }
            if ( region )  { setField( 'billing_state', region );     setField( 'shipping_state', region ); }
            if ( postal )  { setField( 'billing_postcode', postal );  setField( 'shipping_postcode', postal ); }
            if ( country ) { setField( 'billing_country', country );  setField( 'shipping_country', country ); }
        }
    }

    /**
     * Build the Smart Button style object from the merchant's saved
     * PayPal settings (Button Color / Shape / Label / Layout / Height).
     *
     * Every storefront placement funnels through this so the live
     * buttons match the PayPal Settings → Smart Button Style panel,
     * instead of the hardcoded gold button some express slots used to
     * render regardless of the saved settings.
     *
     * @param {Object} [opts]
     * @param {string} [opts.layout]        Force a layout ('horizontal'|
     *     'vertical'). The express / cart / PDP slots pass 'horizontal'
     *     because they render Venmo in a dedicated sibling container and
     *     must not also stack it inside the PayPal button container (see
     *     renderPayPalButtonInContainer).
     * @param {number} [opts.defaultHeight] Fallback height (px) used only
     *     when the merchant left the Button Height field blank.
     * @return {Object}
     */
    function buildButtonStyle( opts ) {
        opts = opts || {};
        var style = {
            layout: opts.layout || ppParams.button_layout || 'vertical',
            color:  ppParams.button_color || 'gold',
            shape:  ppParams.button_shape || 'rect',
            label:  ppParams.button_label || 'paypal',
        };

        if ( ppParams.button_tagline ) {
            style.tagline = true;
        }

        var h = parseInt( ppParams.button_height, 10 );
        if ( ! isNaN( h ) && h >= 25 && h <= 55 ) {
            style.height = h;
        } else if ( opts.defaultHeight ) {
            style.height = opts.defaultHeight;
        }
        return style;
    }

    function renderPayPalButtons() {
        var container = document.getElementById( 'tejcart-paypal-button-container' );
        renderPayPalButtonInContainer( container, buildButtonStyle(), function () {
            return getCheckoutDataWithToken().then( function ( cd ) { return ajaxRequest( 'tejcart_paypal_create_order', cd ); } )
                .then( function ( r ) {
                    if ( r.success && r.data && r.data.order_id ) {
                        rememberWalletShippingNonce( r.data.order_id, r.data.wallet_shipping_nonce );
                        return r.data.order_id;
                    }
                    throw new Error( ( r.data && r.data.message ) || 'Could not create order.' );
                } );
        }, function ( data ) {
            showLoading();
            ajaxRequest( 'tejcart_paypal_capture_order', { paypal_order_id: data.orderId } )
                .then( function ( result ) {
                    hideLoading();
                    endWalletFlow();
                    if ( result.success && result.data && result.data.redirect ) {
                        if ( isSafeUrl( result.data.redirect ) ) {
                            window.location.href = result.data.redirect;
                        } else {
                            window.location.href = '/';
                        }
                    } else {
                        showError( container, formatGatewayError( ( result && result.data ) || {}, 'PayPal' ) );
                    }
                } )
                .catch( function ( err ) {
                    hideLoading();
                    endWalletFlow();
                    console.error( 'PayPal capture failed:', err );
                    showError( container, formatGatewayError( err, 'PayPal' ) );
                } );
        }, runWalletPreflight );
    }

    // Bound the wait so a stalled `update_shipping` server
    // response can't leave the PayPal sheet hanging indefinitely.
    var SHIPPING_UPDATE_TIMEOUT_MS = 30000;

    // PayPal v6 SDK reject reason codes accepted by `actions.reject(reason)`.
    // Any value outside this set is silently ignored by the SDK, which is
    // how the empty-list bug used to surface as a no-op.
    var PAYPAL_REJECT_REASONS = [
        'COUNTRY_ERROR',
        'ADDRESS_ERROR',
        'POSTAL_CODE_ERROR',
        'STATE_ERROR',
        'METHOD_UNAVAILABLE',
    ];

    function safeRejectReason( reason ) {
        var str = String( reason || '' ).toUpperCase();
        return PAYPAL_REJECT_REASONS.indexOf( str ) !== -1 ? str : 'METHOD_UNAVAILABLE';
    }

    function buildAddressPayload( data ) {
        var payload = {};
        var addr = data && ( data.shippingAddress || data.shipping_address );
        if ( ! addr ) return payload;
        // PayPal v6 SDK exposes shippingAddress as { city, state,
        // countryCode, postalCode }. The legacy v5 SDK used snake_case
        // (country_code, postal_code) and the REST API uses adminArea1 /
        // adminArea2 — accept all three so we don't depend on which surface
        // fed the callback.
        var country = addr.countryCode || addr.country_code;
        var state   = addr.state || addr.adminArea1 || addr.admin_area_1;
        var city    = addr.city  || addr.adminArea2 || addr.admin_area_2;
        var postal  = addr.postalCode || addr.postal_code;
        if ( country ) payload.shipping_country  = String( country );
        if ( state )   payload.shipping_state    = String( state );
        if ( city )    payload.shipping_city     = String( city );
        if ( postal )  payload.shipping_postcode = String( postal );
        return payload;
    }

    function buildOptionPayload( data ) {
        var payload = {};
        var opt = data && ( data.selectedShippingOption || data.selected_shipping_option );
        if ( opt && opt.id ) {
            payload.selected_shipping_option_id = String( opt.id );
        }
        return payload;
    }

    function runShippingUpdate( data, actions, extraPayload, container ) {
        if ( ! data || ! data.orderId ) {
            return actions && actions.resolve ? actions.resolve() : undefined;
        }
        var timeoutId;
        var timeoutPromise = new Promise( function ( _resolve, reject ) {
            timeoutId = setTimeout( function () {
                reject( new Error( 'shipping_update_timeout' ) );
            }, SHIPPING_UPDATE_TIMEOUT_MS );
        } );
        var payload = { paypal_order_id: data.orderId };
        if ( extraPayload ) {
            for ( var k in extraPayload ) {
                if ( Object.prototype.hasOwnProperty.call( extraPayload, k ) ) {
                    payload[ k ] = extraPayload[ k ];
                }
            }
        }
        // Resolve the rejection value the PayPal v6 SDK needs to render
        // a buyer-visible error in its popup. Per the official v6
        // reference and community examples, the localised-error enum is
        // on `data.errors` (NOT `actions.errors`). We prefer that enum
        // because the SDK then renders its own localised copy
        // ("We can't ship to this address" etc.). If the requested code
        // isn't in the SDK's enum we fall back to ADDRESS_ERROR (the
        // safest documented generic), then to the server-supplied
        // message as a literal string — the SDK accepts a plain
        // Error(message) and renders the string in the popup.
        var resolveRejectValue = function ( code, message ) {
            var safe = safeRejectReason( code );
            if ( data && data.errors ) {
                if ( data.errors[ safe ] ) return data.errors[ safe ];
                if ( data.errors.ADDRESS_ERROR ) return data.errors.ADDRESS_ERROR;
            }
            if ( actions && actions.errors ) {
                if ( actions.errors[ safe ] ) return actions.errors[ safe ];
                if ( actions.errors.ADDRESS_ERROR ) return actions.errors.ADDRESS_ERROR;
            }
            return message || safe;
        };
        // PayPal v6 SDK contract: the callback returns a Promise. When
        // that Promise REJECTS (via throw or Promise.reject), the SDK
        // both rejects the address change AND renders the rejection
        // value inside the popup so the buyer can see what went wrong.
        // Returning `actions.reject(reason)` works for *some* SDK builds
        // but is silent in others (see paypal-checkout-components#1555)
        // — that silence is what kept the popup blank across PRs
        // #1567-#1570. Throwing is the documented, reliable pattern.
        // We also call actions.reject() as a belt-and-braces signal so
        // our session.onError suppression bookkeeping (#1568) still
        // stamps the rejection timestamp.
        // Normalise the inner promise so any failure — server-rejection,
        // network drop, timeout — funnels into the same catch() block
        // below, with the catch handler being the *only* place that
        // converts the failure into a thrown Error for the SDK to
        // render.
        var requestPromise = ajaxRequest( 'tejcart_paypal_update_shipping', payload );
        return Promise.race( [ requestPromise, timeoutPromise ] )
            .then( function ( r ) {
                clearTimeout( timeoutId );
                if ( r && r.success ) {
                    return actions && actions.resolve ? actions.resolve() : undefined;
                }
                // Server-side rejection — funnel into catch() with the
                // server's reason + message so the popup shows them.
                var reason  = ( r && r.data && r.data.reject_reason ) || 'METHOD_UNAVAILABLE';
                var message = ( r && r.data && r.data.message ) || '';
                var rejectionErr = new Error( 'tejcart_shipping_rejection' );
                rejectionErr.__tejcart_reason = reason;
                rejectionErr.__tejcart_message = message;
                throw rejectionErr;
            } )
            .catch( function ( err ) {
                clearTimeout( timeoutId );
                var isServerRejection = !! ( err && err.__tejcart_reason );
                var reason  = isServerRejection ? err.__tejcart_reason : 'METHOD_UNAVAILABLE';
                var message = isServerRejection ? err.__tejcart_message : '';
                var rejectValue = resolveRejectValue( reason, message );
                // Belt-and-braces: also fire actions.reject() so SDK
                // builds that listen to it still register the rejection
                // AND so renderPayPalButtonInContainer's session.onError
                // suppression timer (#1568) is stamped.
                if ( actions && typeof actions.reject === 'function' ) {
                    try { actions.reject( rejectValue ); } catch ( _e ) {}
                }
                // For server-rejection paths the popup must show the
                // error — throw so the SDK surfaces it. For non-
                // rejection faults (network / timeout without a
                // server response), the actions.reject() above has
                // already informed the SDK; throw a generic message so
                // the popup shows a recoverable notice.
                throw new Error( rejectValue );
            } );
    }

    /**
     * Render a PayPal button in a container using v6 session pattern.
     */
    function renderPayPalButtonInContainer( container, style, createOrderFn, onApproveFn, preflight ) {
        if ( ! container || container.hasChildNodes() ) return;

        var lastOrderId = null;
        // Track when the PayPal SDK most recently received a shipping
        // rejection via actions.reject() inside our onShippingAddressChange /
        // onShippingOptionsChange callbacks. Returning the rejected promise
        // back to the SDK can — depending on the SDK build — also bubble
        // through onError, which would re-render the same message via
        // showError() and clobber the express PayPal button on the checkout
        // page. The wallet sheet has already surfaced the reject_reason to
        // the buyer; the checkout page must stay clean.
        var lastShippingRejectionAt = 0;
        var SHIPPING_REJECTION_SUPPRESS_MS = 5000;
        // Container-bound error sink so capture failures land in the
        // originating button slot — critical for the side-cart drawer,
        // whose container ID isn't covered by showGlobalError's page-wide
        // selector. expressOnApprove will route its messages through this
        // when given.
        var renderErrorInContainer = function ( message ) {
            showError( container, message );
        };
        var sessionOptions = {
            onApprove: function ( data ) {
                if ( data && data.orderId ) lastOrderId = data.orderId;
                onApproveFn( data, renderErrorInContainer );
            },
            onCancel: function ( data ) {
                hideLoading();
                endWalletFlow();
                // Without a server-side cancel hop, the pending
                // TejCart order minted at create_express_order leaks
                // forever. Notify the server so it can mark the row
                // cancelled and release the stock reservation.
                cancelPendingPayPalOrder( ( data && data.orderId ) || lastOrderId );
                showNotice( container, 'Payment cancelled. You can try again.', 'info' );
            },
            onError: function ( err ) {
                hideLoading();
                endWalletFlow();
                console.error( 'PayPal session error:', err );
                cancelPendingPayPalOrder( lastOrderId );
                // Suppress redundant checkout-page notices when the SDK
                // is reporting the shipping rejection we already handed it.
                // The wallet sheet renders that reason on its own — see
                // PAYPAL_REJECT_REASONS for the documented set.
                if ( ( Date.now() - lastShippingRejectionAt ) < SHIPPING_REJECTION_SUPPRESS_MS ) {
                    return;
                }
                showError( container, formatGatewayError( err, 'PayPal' ) );
            },
        };
        // Wrap `actions.reject` so every shipping rejection updates the
        // suppression timestamp before flowing through to the SDK. Keeps
        // the contract identical (we still return whatever actions.reject
        // returns) while letting onError tell shipping rejections apart
        // from other session faults.
        function wrapShippingActions( actions ) {
            if ( ! actions || typeof actions.reject !== 'function' ) return actions;
            var origReject = actions.reject.bind( actions );
            return {
                resolve:     ( typeof actions.resolve === 'function' ) ? actions.resolve.bind( actions ) : actions.resolve,
                updateOrder: ( typeof actions.updateOrder === 'function' ) ? actions.updateOrder.bind( actions ) : actions.updateOrder,
                reject:      function ( reason ) {
                    lastShippingRejectionAt = Date.now();
                    return origReject( reason );
                },
            };
        }
        // Only wire the shipping callbacks when the store actually needs
        // shipping. With `needs_shipping = false` the PayPal SDK skips
        // address collection entirely (server-side `shipping_preference`
        // is forced to `NO_SHIPPING` by the matching gate in
        // PayPal_API::create_order), so registering the handlers would
        // either be a no-op or — worse, on a stale page — fire a
        // spurious update_shipping POST that fails when the merchant has
        // shipping turned off and no zones configured.
        if ( ppParams.needs_shipping ) {
            sessionOptions.onShippingAddressChange = function ( data, actions ) {
                return runShippingUpdate( data, wrapShippingActions( actions ), buildAddressPayload( data ), container );
            };
            sessionOptions.onShippingOptionsChange = function ( data, actions ) {
                return runShippingUpdate( data, wrapShippingActions( actions ), buildOptionPayload( data ), container );
            };
        }
        var session = sdkInstance.createPayPalOneTimePaymentSession( sessionOptions );

        var button = document.createElement( 'paypal-button' );
        button.setAttribute( 'type', 'pay' );

        var allowedColors = [ 'gold', 'blue', 'silver', 'white', 'black' ];
        if ( style.color && allowedColors.indexOf( style.color ) !== -1 ) {
            button.setAttribute( 'class', 'paypal-' + style.color );
        }
        if ( style.height ) button.style.setProperty( '--paypal-button-height', style.height + 'px' );
        // Button Shape — the PayPal Web SDK v6 <paypal-button> honours the
        // --paypal-button-border-radius custom property (the same host CSS
        // hook as --paypal-button-height). We ALWAYS set it so the SDK's
        // square 0px default can never win: 'pill' uses a large radius that
        // CSS clamps to a perfect pill; every other shape ('rect', blank, or
        // an unexpected value) uses a small 4px radius — the same corner the
        // PayPal SDK gives its native 'rect' button and the Venmo / Apple Pay
        // / Google Pay buttons rendered beside it. Leaving the property unset
        // let the 0px default through and made the PayPal button look broken
        // (perfectly square) next to those slightly-rounded wallet buttons,
        // most visibly on the single product page.
        if ( 'pill' === style.shape ) {
            button.style.setProperty( '--paypal-button-border-radius', '9999px' );
        } else {
            button.style.setProperty( '--paypal-button-border-radius', '4px' );
        }

        button.addEventListener( 'click', function ( ev ) {
            // Validate the host checkout form (when present) BEFORE
            // session.start() opens the PayPal popup. The preflight
            // surfaces inline field errors and returns false to abort
            // the wallet flow — without this gate the popup would open
            // even when required fields like email or shipping address
            // are blank, leaking a pending order + stock reservation
            // and degrading the buyer's experience.
            if ( typeof preflight === 'function' && preflight() === false ) {
                return;
            }
            // Single-flight guard with click-target tracking.
            if ( ! beginWalletFlow( ev && ev.currentTarget ? ev.currentTarget : button ) ) {
                return;
            }
            var orderPromise = createOrderFn().then( function ( orderId ) {
                lastOrderId = orderId;
                return { orderId: orderId };
            } ).catch( function ( err ) {
                hideLoading();
                endWalletFlow();
                showError( container, ( err && err.message ) || 'Could not create order.' );
                throw err;
            } );
            session.start( { presentationMode: 'auto' }, orderPromise );
        } );

        container.appendChild( button );

        // When Venmo stacks INTO the PayPal container (vertical layout),
        // render it first and suppress its own disclaimer. The single
        // zone-level disclaimer appended below already covers every
        // wallet button in the stack, so a per-Venmo line would just be
        // a duplicate.
        if ( ppParams.enable_venmo && style.layout === 'vertical' ) {
            renderVenmoButton( container, createOrderFn, onApproveFn, preflight, { suppressDisclaimer: true } );
        }

        appendWalletDisclaimer( container );
    }

    /**
     * Render a Venmo button into a dedicated container.
     *
     * Used by the PDP / cart / express / drawer slots so Venmo surfaces
     * as a sibling express button instead of a stacked funding source.
     *
     * `opts.suppressDisclaimer` skips this button's terms disclaimer —
     * set by the PayPal Smart Button renderer when it stacks Venmo into
     * the same container, since the PayPal render then adds the single
     * shared zone-level disclaimer that already covers both buttons.
     */
    function renderVenmoButton( container, createOrderFn, onApproveFn, preflight, opts ) {
        if ( ! container || ! sdkInstance || ! sdkInstance.createVenmoOneTimePaymentSession ) {
            return;
        }

        var venmoLastOrderId = null;
        var venmoSession;
        var venmoRenderErrorInContainer = function ( message ) {
            showError( container, message );
        };
        try {
            venmoSession = sdkInstance.createVenmoOneTimePaymentSession( {
                onApprove: function ( data ) {
                    if ( data && data.orderId ) venmoLastOrderId = data.orderId;
                    onApproveFn( data, venmoRenderErrorInContainer );
                },
                onCancel:  function ( data ) {
                    // Match PayPal Smart Buttons:
                    // surface a notice and re-enable the trigger button so
                    // the buyer can retry without a page refresh.
                    hideLoading();
                    endWalletFlow();
                    cancelPendingPayPalOrder( ( data && data.orderId ) || venmoLastOrderId );
                    showNotice( container, 'Payment cancelled. You can try again.', 'info' );
                },
                onError:   function ( err ) {
                    hideLoading();
                    endWalletFlow();
                    console.error( 'Venmo session error:', err );
                    cancelPendingPayPalOrder( venmoLastOrderId );
                    showError( container, formatGatewayError( err, 'Venmo' ) );
                },
            } );
        } catch ( e ) {
            removeContainerIfEmpty( container );
            return;
        }

        var venmoBtn = document.createElement( 'venmo-button' );
        venmoBtn.addEventListener( 'click', function ( ev ) {
            if ( typeof preflight === 'function' && preflight() === false ) {
                return;
            }
            if ( ! beginWalletFlow( ev && ev.currentTarget ? ev.currentTarget : venmoBtn ) ) {
                return;
            }
            var venmoOrderPromise = createOrderFn().then( function ( orderId ) {
                venmoLastOrderId = orderId;
                return { orderId: orderId };
            } ).catch( function ( err ) {
                hideLoading();
                endWalletFlow();
                showError( container, ( err && err.message ) || 'Could not create order.' );
                throw err;
            } );
            venmoSession.start( { presentationMode: 'auto' }, venmoOrderPromise );
        } );
        container.appendChild( venmoBtn );
        if ( ! ( opts && opts.suppressDisclaimer ) ) {
            appendWalletDisclaimer( container );
        }
    }

    /**
     * Constrain the keyboard tab order inside a PayPal-SDK-managed
     * container so that ONLY the cross-origin iframe(s) remain tab
     * stops — every wrapper element the SDK injects around the iframe
     * gets `tabindex="-1"`.
     *
     * Why this exists: `cardSession.createCardFieldsComponent()` appends
     * a custom-element host (typically `<paypal-card-field>`) that wraps
     * the hosted-card iframe. On the SDK builds we ship to (and any build
     * where the shadow root doesn't set `delegatesFocus: true`), the host
     * is itself tabbable AS WELL AS the iframe inside it, so a buyer
     * presses Tab once to land on the empty wrapper, then a SECOND time
     * to land on the iframe where typing actually works — three card
     * fields × two stops = six Tab presses to traverse the card form
     * when only three are real inputs.
     *
     * Setting `tabindex="-1"` on every non-iframe descendant removes the
     * wrapper(s) from the Tab cycle while leaving them reachable via
     * click and programmatic `.focus()` (the SDK's own `.focus()` and the
     * `focusField()` auto-advance below both ignore tabindex). The iframe
     * remains the SOLE keyboard tab stop per field. A MutationObserver
     * re-applies the policy if the SDK swaps its wrapper structure later
     * (different SDK versions render different markup; we want the fix
     * to survive that without code changes).
     *
     * @param {Element}  container        Element to lock down.
     * @param {Object}   [opts]
     * @param {boolean}  [opts.includeIframes=false]
     *     When true, also set `tabindex="-1"` on iframes inside the
     *     container. Use for informational iframes (e.g. the Pay Later
     *     `<paypal-message>` content) where the iframe is marketing
     *     copy, not a form control, and should not be a tab stop at all.
     */
    function restrictTabStopsToIframes( container, opts ) {
        if ( ! container || typeof container.querySelectorAll !== 'function' ) return;

        var includeIframes = !! ( opts && opts.includeIframes );

        function apply() {
            var nodes = container.querySelectorAll( '*' );
            for ( var i = 0; i < nodes.length; i++ ) {
                var el = nodes[ i ];
                if ( el.tagName === 'IFRAME' && ! includeIframes ) continue;
                // Skip custom elements (tag name contains a hyphen) and any
                // element with an open shadow root: the PayPal SDK ships its
                // hosted-card iframe inside the shadow DOM of a custom host
                // (e.g. <paypal-card-field>). Forcing `tabindex="-1"` on the
                // host removes BOTH the host AND its delegated focus target
                // from sequential focus navigation in browsers that honour
                // `delegatesFocus: true`, which leaves the expiry and CVV
                // iframes unreachable via Tab and makes focus skip straight
                // to the next light-DOM tab stop (the Terms checkbox).
                if ( el.tagName && el.tagName.indexOf( '-' ) !== -1 ) continue;
                if ( el.shadowRoot ) continue;
                if ( el.getAttribute( 'tabindex' ) === '-1' ) continue;
                try { el.setAttribute( 'tabindex', '-1' ); } catch ( e ) {}
            }
        }

        apply();

        if ( typeof MutationObserver === 'undefined' ) return;
        try {
            var observer = new MutationObserver( apply );
            observer.observe( container, { childList: true, subtree: true } );
        } catch ( e ) {}
    }

    function renderCardFields() {
        var container = document.getElementById( 'tejcart-card-fields-container' );
        if ( ! container ) return;

        var cardSession;
        try {
            // PayPal Web SDK v6 contract:
            //   sdkInstance.createCardFieldsOneTimePaymentSession()
            // takes NO callback options. The outcome of a card payment
            // is delivered as the resolved value of submit():
            //   { data: { orderId, ... }, state: 'succeeded'|'canceled'|'failed' }
            // The onApprove / onCancel / onError shape that this code
            // used to pass was lifted from the v5 Smart Buttons surface
            // and is silently ignored at best — at worst it is what
            // gets structured-cloned into the cross-origin iframe and
            // surfaces the DOMException("Function object could not be
            // cloned") that strands the buyer on a permanent spinner.
            cardSession = sdkInstance.createCardFieldsOneTimePaymentSession();
        } catch ( err ) {
            console.warn( 'TejCart: card fields session could not be created — hiding Credit / Debit Card option.', err );
            hideCardPaymentMethod();
            return;
        }

        // Each hosted-card-field iframe is cross-origin from PayPal, so
        // the browser cannot bubble keydown events from inside the
        // iframe to the parent document. We listen for the SDK's
        // `inputEvents.onChange` and call the SDK component's own
        // `.focus()` method on the next field the moment the current
        // one reports `isValid`. The focus call is deferred via
        // setTimeout( 0 ) so it runs AFTER the browser has finished
        // any user-driven Tab press — without that, a buyer who
        // finishes the card number and presses Tab races our SDK
        // `.focus()` call against the browser's natural focus shift
        // to the next iframe wrapper. When the browser wins that race,
        // the iframe element is focused but the input inside it never
        // receives keyboard focus, so the caret moves visually but
        // typing produces nothing until the buyer clicks the field.
        var numberField, expiryField, cvvField;

        function focusField( component ) {
            if ( ! component ) return;
            setTimeout( function () {
                try {
                    if ( typeof component.focus === 'function' ) {
                        component.focus();
                        return;
                    }
                } catch ( e ) {}
                // Last-resort fallback for SDK builds that return a
                // bare wrapper without a `.focus()` method. Focusing
                // the iframe element does not propagate to its inner
                // input on every browser, but is still better than a
                // silent no-op.
                try {
                    var iframe = component.querySelector && component.querySelector( 'iframe' );
                    if ( iframe && typeof iframe.focus === 'function' ) {
                        iframe.focus();
                    }
                } catch ( e ) {}
            }, 0 );
        }

        function buildComponent( type, placeholder, getNext ) {
            var fieldKey = ( type === 'number' )
                ? 'cardNumberField'
                : ( type === 'expiry' ? 'cardExpiryField' : 'cardCvvField' );
            // One-shot advance per validity transition: don't re-fire
            // focus on every onChange tick after the field reports
            // valid. Reset on invalid so backspace → re-type still
            // advances once.
            var advanced = false;

            // The actual <input> lives inside a cross-origin PayPal iframe, so
            // it cannot inherit the parent page's CSS. The Web SDK v6
            // card-fields component only honours a small CSS allow-list passed
            // through `style`; font-family/font-size/font-weight and the
            // :focus / ::placeholder selectors are rejected with a console
            // DevError and must NOT be sent. Only `color` and `padding` on the
            // base input (plus the `.invalid` colour) are accepted. `padding`
            // is the one that matters here — it aligns the card text with the
            // rest of the form. The CVV field gets extra right padding so typed
            // digits never slide under the card-back icon pinned at right:12px
            // (32px wide). Font/placeholder colour fall back to PayPal's
            // defaults, which are visually neutral.
            var style = {
                input: {
                    color:   '#202223',
                    padding: ( 'cvv' === type ) ? '0 52px 0 16px' : '0 16px',
                },
                '.invalid': { color: '#d72c0d' },
            };

            var inputEvents = {
                onChange: function ( data ) {
                    if ( ! data || ! data.fields ) return;
                    var fieldState = data.fields[ fieldKey ];
                    if ( ! fieldState ) return;
                    if ( ! fieldState.isValid ) {
                        advanced = false;
                        return;
                    }
                    if ( advanced || ! getNext ) return;
                    advanced = true;
                    focusField( getNext() );
                },
            };

            try {
                return cardSession.createCardFieldsComponent( {
                    type:        type,
                    placeholder: placeholder,
                    style:       style,
                    inputEvents: inputEvents,
                } );
            } catch ( err ) {
                // Older SDK builds may reject `style` and/or `inputEvents`.
                // Drop the styling first (keep auto-advance), then fall back
                // to the minimal call so the field always renders.
                try {
                    return cardSession.createCardFieldsComponent( {
                        type:        type,
                        placeholder: placeholder,
                        inputEvents: inputEvents,
                    } );
                } catch ( err2 ) {
                    return cardSession.createCardFieldsComponent( { type: type, placeholder: placeholder } );
                }
            }
        }

        numberField = buildComponent( 'number', '1234 1234 1234 1234', function () { return expiryField; } );
        expiryField = buildComponent( 'expiry', 'MM / YY', function () { return cvvField; } );
        cvvField    = buildComponent( 'cvv', 'CVC', null );

        var numEl = document.getElementById( 'tejcart-card-number' );
        var expEl = document.getElementById( 'tejcart-card-expiry' );
        var cvvEl = document.getElementById( 'tejcart-card-cvv' );

        if ( numEl ) { numEl.appendChild( numberField ); restrictTabStopsToIframes( numEl ); }
        if ( expEl ) { expEl.appendChild( expiryField ); restrictTabStopsToIframes( expEl ); }
        if ( cvvEl ) { cvvEl.appendChild( cvvField );    restrictTabStopsToIframes( cvvEl ); }

        // The standard Place Order button submits the checkout form to
        // `tejcart_checkout`, whose gateway return value carries the
        // PayPal wallet approval URL. Advanced Card Fields don't use
        // that URL — the buyer's PAN never leaves PayPal's iframes, so
        // the payment must be driven from the client via
        // `cardSession.submit()`. Without this interceptor the buyer
        // gets bounced to the wallet picker (checkoutnow?token=...)
        // even though they typed their card details inline.
        var checkoutForm = document.getElementById( 'tejcart-checkout-form' );
        if ( ! checkoutForm ) return;

        checkoutForm.addEventListener( 'submit', function ( e ) {
            var selected = checkoutForm.querySelector( 'input[name="tejcart_payment_method"]:checked' );
            if ( ! selected || selected.value !== 'tejcart_card' ) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();

            if ( ! runCheckoutPreflight() ) {
                return;
            }

            var placeBtn = checkoutForm.querySelector( '.tejcart-place-order-btn' );
            if ( ! beginWalletFlow( placeBtn ) ) {
                return;
            }
            showLoading();

            submitCardPayment( container, cardSession );
        }, true );
    }

    /**
     * Drive the Card Fields submit flow end-to-end:
     *   1. Create a PayPal order on the server (price-of-record stays
     *      with the merchant — the client never names a dollar amount).
     *   2. Call cardSession.submit( orderId, { billingAddress } ) with
     *      the resolved STRING orderId. PayPal v6 requires this exact
     *      shape — passing a function for the first arg trips
     *      "Function object could not be cloned" because the SDK
     *      structured-clones the args across postMessage into a
     *      cross-origin iframe, and Function values are not cloneable.
     *   3. Inspect the resolved `{ data, state }` and either capture
     *      the order, show a retryable cancel notice, or surface a
     *      failure message.
     *
     * Every terminal branch releases the loading overlay and the
     * double-click guard before returning so the buyer is never
     * stranded on a permanent spinner.
     */
    function submitCardPayment( containerEl, cardSession ) {
        function finish() {
            hideLoading();
            endWalletFlow();
        }

        getCheckoutDataWithToken().then( function ( cd ) { return ajaxRequest( 'tejcart_paypal_create_order', cd ); } )
            .then( function ( r ) {
                if ( ! r || ! r.success || ! r.data || ! r.data.order_id ) {
                    throw new Error( ( r && r.data && r.data.message ) || 'Could not create order.' );
                }
                rememberWalletShippingNonce( r.data.order_id, r.data.wallet_shipping_nonce );
                return cardSession.submit( String( r.data.order_id ), buildCardSubmitOptions() );
            } )
            .then( function ( result ) {
                var state = ( result && result.state ) || '';
                var data  = ( result && result.data )  || {};

                if ( state === 'succeeded' ) {
                    var capturedOrderId = data.orderId || data.order_id || '';
                    if ( ! capturedOrderId ) {
                        finish();
                        showCardError( containerEl, 'Card payment succeeded but the order ID is missing. Please contact support.' );
                        return null;
                    }
                    return ajaxRequest( 'tejcart_paypal_capture_order', {
                        paypal_order_id: capturedOrderId,
                    } ).then( function ( capture ) {
                        finish();
                        if ( capture && capture.success && capture.data && capture.data.redirect ) {
                            window.location.href = isSafeUrl( capture.data.redirect )
                                ? capture.data.redirect
                                : '/';
                            return;
                        }
                        showCardError( containerEl, ( capture && capture.data && capture.data.message ) || 'Card payment failed.' );
                    } );
                }

                finish();

                if ( state === 'canceled' ) {
                    showNotice( containerEl, 'Card authentication cancelled. You can try again.', 'info' );
                    return null;
                }

                // state === 'failed' or any unexpected future state. The v6
                // SDK reports the reason in the resolved `data` payload
                // instead of throwing, so log it (otherwise the merchant
                // only ever sees the flat fallback). A common cause is a
                // card that isn't enrolled in 3-D Secure while the gateway
                // is set to SCA_ALWAYS: the forced challenge can't complete.
                console.warn( 'TejCart card payment not completed:', { state: state, data: data } );

                var msg = describeCardFailure( data );
                if ( state && state !== 'failed' ) {
                    msg += ' (' + state + ')';
                }
                showCardError( containerEl, msg );
                return null;
            } )
            .catch( function ( err ) {
                finish();
                console.error( 'TejCart card payment error:', err );
                showCardError( containerEl, formatGatewayError( err, 'Card' ) );
            } );
    }

    /**
     * Derive a human-readable reason from the payload that Card Fields
     * `submit()` resolves with when `state` is `failed`. The PayPal v6
     * SDK uses a few shapes depending on the failure class:
     *   - { message }                            plain string reason
     *   - { error: { message } }                 nested error object
     *   - { details: [ { issue, description } ] } Orders-v2 style
     *   - { name } / { code }                    bare error code
     * When none are present the most common cause is a card that could
     * not complete 3-D Secure under an SCA_ALWAYS policy, so return that
     * as the actionable default rather than a flat "Card payment failed."
     *
     * @param {Object} data Resolved `result.data` from cardSession.submit().
     * @returns {string}
     */
    function describeCardFailure( data ) {
        var fallback = 'Card payment could not be authenticated. If 3-D Secure is required, this card may not be enrolled. Please try a different card.';
        if ( ! data || typeof data !== 'object' ) {
            return fallback;
        }
        if ( typeof data.message === 'string' && data.message ) {
            return data.message;
        }
        if ( data.error && typeof data.error.message === 'string' && data.error.message ) {
            return data.error.message;
        }
        if ( Array.isArray( data.details ) && data.details.length ) {
            var parts = [];
            for ( var i = 0; i < data.details.length; i++ ) {
                var detail = data.details[ i ] || {};
                var text   = detail.description || detail.issue || '';
                if ( text ) {
                    parts.push( String( text ) );
                }
            }
            if ( parts.length ) {
                return parts.join( ' ' );
            }
        }
        if ( typeof data.name === 'string' && data.name ) {
            return fallback + ' (' + data.name + ')';
        }
        if ( typeof data.code === 'string' && data.code ) {
            return fallback + ' (' + data.code + ')';
        }
        return fallback;
    }

    /**
     * Pull the billing address out of the checkout form to feed PayPal's
     * AVS / 3DS decisioning during cardSession.submit(). Falls back to
     * the shipping address when "Same as shipping" is in effect (TejCart
     * hides billing inputs in that mode). Empty values are simply
     * omitted — the SDK only forwards keys that are present.
     */
    function buildCardSubmitOptions() {
        var form = document.getElementById( 'tejcart-checkout-form' );
        if ( ! form ) return {};

        function val( name ) {
            var el = form.querySelector( '[name="' + name + '"]' );
            return ( el && typeof el.value === 'string' ) ? el.value.trim() : '';
        }
        function pick( billingName, shippingName ) {
            return val( billingName ) || val( shippingName );
        }

        var addr    = {};
        var postal  = pick( 'billing_postcode',   'shipping_postcode' );
        var country = pick( 'billing_country',    'shipping_country' );
        var region  = pick( 'billing_state',      'shipping_state' );
        var city    = pick( 'billing_city',       'shipping_city' );
        var line1   = pick( 'billing_address_1',  'shipping_address_1' );
        var line2   = pick( 'billing_address_2',  'shipping_address_2' );

        if ( postal )  addr.postalCode   = postal;
        if ( country ) addr.countryCode  = country.toUpperCase();
        if ( region )  addr.adminArea1   = region;
        if ( city )    addr.adminArea2   = city;
        if ( line1 )   addr.addressLine1 = line1;
        if ( line2 )   addr.addressLine2 = line2;

        var hasAny = false;
        for ( var k in addr ) {
            if ( Object.prototype.hasOwnProperty.call( addr, k ) ) { hasAny = true; break; }
        }
        return hasAny ? { billingAddress: addr } : {};
    }

    /**
     * Hide the Credit / Debit Card payment method from the checkout
     * accordion when card fields cannot be rendered (e.g. the merchant's
     * PayPal account is not approved for Advanced Card Payments).
     *
     * If the hidden method was the currently selected one, auto-select
     * the first remaining visible payment method so checkout stays usable.
     */
    function hideCardPaymentMethod() {
        var li = document.querySelector( '.tejcart-payment-method-tejcart_card' );
        if ( ! li ) return;

        var wasActive = li.classList.contains( 'is-active' ) || li.classList.contains( 'active' );
        li.style.display = 'none';

        var radio = li.querySelector( 'input[name="tejcart_payment_method"]' );
        if ( radio ) radio.checked = false;

        if ( wasActive ) {
            var nextVisible = document.querySelector( '.tejcart-payment-method:not([style*="display: none"]) input[name="tejcart_payment_method"]' );
            if ( nextVisible ) {
                nextVisible.checked = true;
                nextVisible.dispatchEvent( new Event( 'change' ) );
            }
        }
    }

    /**
     * Hide the Google Pay payment method from the checkout accordion when
     * Google Pay cannot actually be offered to this shopper — for example
     * when PayPal eligibility says the merchant isn't approved for Google
     * Pay, when the googlepay-payments SDK component fails to load, or
     * when google.com's `isReadyToPay()` reports the device or browser
     * does not support it.
     *
     * If the hidden method was the currently selected one, auto-select
     * the first remaining visible payment method so the generic Place
     * Order button reappears and checkout stays usable.
     */
    function hideGooglePayPaymentMethod() {
        var li = document.querySelector( '.tejcart-payment-method-tejcart_googlepay' );
        if ( ! li ) return;

        var wasActive = li.classList.contains( 'is-active' ) || li.classList.contains( 'active' );
        li.style.display = 'none';

        var radio = li.querySelector( 'input[name="tejcart_payment_method"]' );
        if ( radio ) radio.checked = false;

        if ( wasActive ) {
            var nextVisible = document.querySelector( '.tejcart-payment-method:not([style*="display: none"]) input[name="tejcart_payment_method"]' );
            if ( nextVisible ) {
                nextVisible.checked = true;
                nextVisible.dispatchEvent( new Event( 'change' ) );
            }
        }
    }

    /**
     * The Google Pay button renderer is shared between the checkout
     * accordion, the cart page, the side cart drawer, the product page
     * and the express-checkout strip. Only on the checkout accordion is
     * there a corresponding payment-method radio to hide; for the other
     * placements we just clean up the empty container.
     */
    function bailGooglePayButton( container ) {
        var zone = container && container.parentNode;
        removeContainerIfEmpty( container );
        if ( container && container.id === 'tejcart-googlepay-container' ) {
            hideGooglePayPaymentMethod();
        }
        // Google Pay resolves its eligibility asynchronously, after the
        // skeleton has already been sized for it. If the slot just bailed,
        // reconcile the placeholder rows so the count matches the wallets
        // that survived.
        if ( zone && zone.classList && zone.classList.contains( 'tejcart-express-checkout-buttons' ) ) {
            syncExpressSkeleton( zone );
        }
    }

    function renderGooglePay( details ) {
        var container = document.getElementById( 'tejcart-googlepay-container' );
        renderGooglePayButton( container, details, function () {
            return getCheckoutDataWithToken().then( function ( cd ) { return ajaxRequest( 'tejcart_paypal_create_order', cd ); } )
                .then( function ( r ) {
                    if ( r.success && r.data && r.data.order_id ) {
                        rememberWalletShippingNonce( r.data.order_id, r.data.wallet_shipping_nonce );
                        return r.data.order_id;
                    }
                    throw new Error( ( r.data && r.data.message ) || 'Could not create order.' );
                } );
        }, runWalletPreflight );
    }

    function renderGooglePayButton( container, details, createOrderFn, preflight ) {
        gpLog( 'Step 3 — renderGooglePayButton() called', {
            container_id: container && container.id,
            already_rendered: !! ( container && container.hasChildNodes() )
        } );
        if ( ! container || container.hasChildNodes() ) return;
        if ( typeof google === 'undefined' || ! google.payments || ! google.payments.api ) {
            gpWarn( 'Step 3 — FAILED: google.payments.api not available — Google pay.js failed to load (check CSP / network).' );
            console.warn( 'TejCart Google Pay: google.payments.api not available — pay.js failed to load.' );
            bailGooglePayButton( container );
            return;
        }
        if ( ! sdkInstance || typeof sdkInstance.createGooglePayOneTimePaymentSession !== 'function' ) {
            gpWarn( 'Step 3 — FAILED: sdkInstance.createGooglePayOneTimePaymentSession missing — "googlepay-payments" component not loaded.' );
            console.warn( 'TejCart Google Pay: sdkInstance.createGooglePayOneTimePaymentSession missing — googlepay-payments component not loaded.' );
            bailGooglePayButton( container );
            return;
        }
        if ( ! details || ! details.config ) {
            gpWarn( 'Step 3 — FAILED: missing eligibility details.config from PayPal SDK.' );
            console.warn( 'TejCart Google Pay: missing eligibility details.config from PayPal SDK.' );
            bailGooglePayButton( container );
            return;
        }
        gpLog( 'Step 3 — guards passed; PayPal-supplied details.config', details.config );

        var googlePaySession = sdkInstance.createGooglePayOneTimePaymentSession();
        gpLog( 'Step 4 — createGooglePayOneTimePaymentSession() created' );

        // Flag the slot as pending synchronously (see the Step 7 note) BEFORE
        // the async config fetch below, so markExpressContainersReady()'s sweep
        // cannot detach the container while getGooglePayConfig() is in flight.
        container.setAttribute( 'data-tejcart-wallet-pending', '1' );

        // Per the PayPal v6 Web SDK Google Pay docs, the config comes from
        // googlePaySession.getGooglePayConfig() — { allowedPaymentMethods,
        // merchantInfo, apiVersion, apiVersionMinor, countryCode } — used as-is.
        // An earlier build derived it instead from
        // findEligibleMethods().getDetails('googlepay').config piped through
        // formatConfigForPaymentRequest(); that undocumented path produced a
        // sandbox config (gateway "paypalsb" + PayPal's live Google-Pay
        // merchantId) that Google rejected with OR_BIBED_06. Prefer the
        // documented getGooglePayConfig() and fall back to the old method only
        // for SDK builds that don't expose it. getGooglePayConfig() may return
        // a value or a Promise, so normalise with Promise.resolve().
        var gpConfigSource = ( typeof googlePaySession.getGooglePayConfig === 'function' )
            ? 'getGooglePayConfig'
            : 'formatConfigForPaymentRequest';
        Promise.resolve(
            'getGooglePayConfig' === gpConfigSource
                ? googlePaySession.getGooglePayConfig()
                : googlePaySession.formatConfigForPaymentRequest( details.config )
        ).then( function ( googlePayConfig ) {
            gpLog( 'Step 4 — ' + gpConfigSource + '() returned', {
                apiVersion: googlePayConfig && googlePayConfig.apiVersion,
                apiVersionMinor: googlePayConfig && googlePayConfig.apiVersionMinor,
                countryCode: googlePayConfig && googlePayConfig.countryCode,
                merchantInfo: googlePayConfig && googlePayConfig.merchantInfo,
                allowedPaymentMethods: googlePayConfig && googlePayConfig.allowedPaymentMethods
            } );

        // merchantInfo comes straight from the documented getGooglePayConfig()
        // and is used unmodified (it now carries the store's own merchantOrigin
        // and the Google-Pay merchantId, exactly as the v6 docs / v5 plugin
        // expect). We do NOT strip it — an earlier build did, which broke the
        // PayPal⇄Google merchant association.
        if ( googlePayConfig && googlePayConfig.merchantInfo ) {
            gpLog( 'Step 5 — merchantInfo passed through unchanged (per v6 docs / mirrors v5)', googlePayConfig.merchantInfo );
        } else {
            gpWarn( 'Step 5 — no merchantInfo present on the formatted config', { is_sandbox: !! ppParams.is_sandbox } );
        }

        // We use the gateway / tokenizationSpecification from PayPal's config
        // verbatim (including "paypalsb" in sandbox) — no modification.

        // Pre-create the PayPal order before the Google Pay sheet opens so
        // the shipping callbacks have a `paypal_order_id` to PATCH against.
        // We cache it on the closure so onPaymentDataChanged + the final
        // confirmOrder both see the same id.
        var pendingOrderIdPromise = null;
        function ensureOrderId() {
            if ( pendingOrderIdPromise ) return pendingOrderIdPromise;
            pendingOrderIdPromise = createOrderFn().catch( function ( err ) {
                pendingOrderIdPromise = null;
                throw err;
            } );
            return pendingOrderIdPromise;
        }

        // Tracks whether the shipping address the buyer currently has selected
        // in the wallet is serviceable. onPaymentDataChanged flips it on every
        // address change; onPaymentAuthorized refuses to capture when it is
        // false, so a buyer cannot pay with an out-of-zone address even though
        // Google Pay still renders the Pay button after showing the error.
        var lastShippingServiceable = true;

        var needsShipping = !! ppParams.needs_shipping;
        var allowedCountries = Array.isArray( ppParams.shipping_allowed_countries )
            ? ppParams.shipping_allowed_countries
            : [];

        // The buyer email Google Pay returns in the authorized paymentData
        // (we request it via emailRequired:true on the paymentDataRequest).
        // PayPal's capture response routinely omits the email for Google Pay,
        // so we stash it here from onPaymentAuthorized and forward it on the
        // capture request as `wallet_email` for the server to persist.
        var gpBuyerEmail = '';

        function googlePayError( reason, intent, message ) {
            return {
                error: {
                    reason:  reason,
                    message: message,
                    intent:  intent,
                },
            };
        }

        function onPaymentDataChanged( intermediatePaymentData ) {
            gpLog( 'Step 9b — onPaymentDataChanged (shipping)', {
                callbackTrigger: intermediatePaymentData && intermediatePaymentData.callbackTrigger
            } );
            return new Promise( function ( resolve ) {
                ensureOrderId().then( function ( orderId ) {
                    var addr = intermediatePaymentData.shippingAddress || {};
                    var payload = {
                        paypal_order_id:   orderId,
                        shipping_country:  String( addr.countryCode || '' ),
                        shipping_state:    String( addr.administrativeArea || '' ),
                        shipping_city:     String( addr.locality || '' ),
                        shipping_postcode: String( addr.postalCode || '' ),
                    };
                    if ( intermediatePaymentData.shippingOptionData
                         && intermediatePaymentData.shippingOptionData.id
                         && intermediatePaymentData.shippingOptionData.id !== 'pending' ) {
                        payload.selected_shipping_option_id = String( intermediatePaymentData.shippingOptionData.id );
                    }
                    var perOrderNonce = getWalletShippingNonce( orderId );
                    if ( perOrderNonce ) payload._wpnonce = perOrderNonce;

                    return ajaxRequest( 'tejcart_paypal_wallet_shipping', payload );
                } ).then( function ( r ) {
                    if ( ! r || ! r.success || ! r.data ) {
                        var rejReason = ( r && r.data && r.data.reject_reason ) || 'COUNTRY_ERROR';
                        var msg = ( r && r.data && r.data.message ) || 'Shipping is unavailable for this address.';
                        gpWarn( 'Step 9b — shipping rejected', { reject_reason: rejReason, message: msg } );
                        var intent = ( intermediatePaymentData.callbackTrigger === 'SHIPPING_OPTION' )
                            ? 'SHIPPING_OPTION'
                            : 'SHIPPING_ADDRESS';
                        var googleReason = ( rejReason === 'COUNTRY_ERROR' || rejReason === 'POSTAL_CODE_ERROR' )
                            ? 'SHIPPING_ADDRESS_UNSERVICEABLE'
                            : 'SHIPPING_ADDRESS_INVALID';
                        // Mark the current address unserviceable so
                        // onPaymentAuthorized blocks the capture if the buyer
                        // hits Pay anyway (Google keeps the button enabled).
                        lastShippingServiceable = false;
                        resolve( googlePayError( googleReason, intent, msg ) );
                        return;
                    }

                    var d = r.data;
                    var newShippingOptionParameters = {
                        defaultSelectedOptionId: d.selected_id,
                        shippingOptions:         ( d.google_pay_options || [] ).map( function ( opt ) {
                            return {
                                id:          String( opt.id ),
                                label:       String( opt.label ),
                                description: String( opt.description || '' ),
                            };
                        } ),
                    };

                    var displayItems = [
                        { label: 'Subtotal', type: 'SUBTOTAL', price: d.subtotal },
                        { label: 'Shipping', type: 'LINE_ITEM', price: d.shipping },
                    ];
                    if ( parseFloat( d.tax ) > 0 ) {
                        displayItems.push( { label: 'Tax', type: 'TAX', price: d.tax } );
                    }
                    if ( parseFloat( d.discount ) > 0 ) {
                        displayItems.push( { label: 'Discount', type: 'LINE_ITEM', price: '-' + d.discount } );
                    }

                    var newTransactionInfo = {
                        currencyCode:     d.currency,
                        countryCode:      googlePayConfig.countryCode,
                        totalPriceStatus: 'FINAL',
                        totalPrice:       d.total,
                        totalPriceLabel:  String( ppParams.store_name || 'Total' ),
                        displayItems:     displayItems,
                    };

                    // Address is serviceable again — clear the block.
                    lastShippingServiceable = true;
                    var response = { newTransactionInfo: newTransactionInfo };
                    if ( needsShipping ) {
                        response.newShippingOptionParameters = newShippingOptionParameters;
                    }
                    resolve( response );
                } ).catch( function ( err ) {
                    console.error( 'TejCart Google Pay onPaymentDataChanged error:', err );
                    var intent = ( intermediatePaymentData.callbackTrigger === 'SHIPPING_OPTION' )
                        ? 'SHIPPING_OPTION'
                        : 'SHIPPING_ADDRESS';
                    // Could not confirm serviceability — block capture until a
                    // later change validates successfully.
                    lastShippingServiceable = false;
                    resolve( googlePayError( 'OTHER_ERROR', intent, 'Shipping options could not be loaded.' ) );
                } );
            } );
        }

        // Capture an approved (or post-3DS authenticated) PayPal order and
        // resolve the Google Pay sheet. Shared by the normal APPROVED path
        // and the PAYER_ACTION_REQUIRED path below so both surface capture
        // failures identically.
        function captureGooglePayOrder( orderId ) {
            gpLog( 'Step 11 — capturing PayPal order server-side', orderId );
            // Report the wallet so the order records "Google Pay" rather than a
            // generic "PayPal" / "Card" (PayPal often settles a Google Pay token
            // as a card, so the client is the reliable funding signal).
            return ajaxRequest( 'tejcart_paypal_capture_order', { paypal_order_id: orderId, express: '1', funding_source: 'google_pay', wallet_email: gpBuyerEmail } )
                .then( function ( result ) {
                    if ( result.success && result.data && result.data.redirect ) {
                        gpLog( 'Step 11 — capture SUCCESS; redirecting', result.data.redirect );
                        var target = isSafeUrl( result.data.redirect ) ? result.data.redirect : '/';
                        setTimeout( function () { window.location.href = target; }, 0 );
                        return { transactionState: 'SUCCESS' };
                    }
                    gpWarn( 'Step 11 — capture FAILED', result && result.data );
                    return {
                        transactionState: 'ERROR',
                        error: { message: ( result.data && result.data.message ) || 'Google Pay capture failed.' },
                    };
                } );
        }

        // Run PayPal's documented payer-action (3-D Secure / SCA) step when
        // the SDK build actually exposes it on the wallet session (where
        // confirmOrder lives). Returns a Promise when the method is available,
        // or null when it is not, so the caller can fail safe instead of
        // guessing at an unavailable API. The orderId-object argument mirrors
        // the v6 docs ("pass the orderId to initiatePayerAction"); the typeof
        // guard keeps this a no-op on SDK builds (and the official sample)
        // that do not expose the method.
        function initiatePayerActionIfSupported( orderId ) {
            if ( ! googlePaySession || typeof googlePaySession.initiatePayerAction !== 'function' ) {
                return null;
            }
            return Promise.resolve().then( function () {
                return googlePaySession.initiatePayerAction( { orderId: orderId } );
            } );
        }

        var onPaymentAuthorized = function ( paymentData ) {
            gpLog( 'Step 10 — onPaymentAuthorized fired (buyer confirmed in sheet)' );
            // Capture the buyer email here — this is the only place Google Pay
            // exposes it (requested via emailRequired on the paymentDataRequest).
            // captureGooglePayOrder() forwards it as `wallet_email` so the order
            // records an email even though PayPal's capture response omits it.
            // Google returns it at paymentData.email; some builds nest it under
            // paymentMethodData.info — accept either so the email is never lost
            // to a shape difference.
            gpBuyerEmail = String(
                ( paymentData && paymentData.email )
                || ( paymentData && paymentData.paymentMethodData && paymentData.paymentMethodData.info && paymentData.paymentMethodData.info.email )
                || ''
            );
            // Diagnostic (only emitted when window.tejcartGooglePayDebug = true):
            // surfaces exactly which contact fields Google handed back so a
            // store stuck on "no email on file" can confirm whether the wallet
            // returned an email at all. Pairs with the server-side
            // `email backfill` log in persist_express_addresses().
            gpLog( 'Step 10 — wallet contact fields returned by Google Pay', {
                hasEmail:           !! ( paymentData && paymentData.email ),
                resolvedEmailEmpty: '' === gpBuyerEmail,
                hasShippingAddress: !! ( paymentData && paymentData.shippingAddress ),
                topLevelKeys:       paymentData ? Object.keys( paymentData ) : []
            } );
            // Safety net: Google Pay leaves the Pay button enabled even after an
            // unserviceable-address error, so refuse to capture when the
            // currently-selected address has no shipping zone. Returning a
            // SHIPPING_ADDRESS_UNSERVICEABLE error re-shows the in-sheet message
            // and prompts the buyer to pick a different address — no charge.
            if ( needsShipping && ! lastShippingServiceable ) {
                gpWarn( 'Step 10 — blocked: selected shipping address is unserviceable' );
                return Promise.resolve( {
                    transactionState: 'ERROR',
                    error: {
                        reason:  'SHIPPING_ADDRESS_UNSERVICEABLE',
                        message: 'We do not ship to the selected address. Please choose a different shipping address.',
                        intent:  'SHIPPING_ADDRESS',
                    },
                } );
            }
            return ensureOrderId().then( function ( orderId ) {
                // Authoritatively re-apply the FINAL shipping address + method
                // the buyer confirmed (paymentData carries the full final
                // address and the chosen option). The dynamic onPaymentDataChanged
                // flow runs on every change and apply_posted_shipping_address
                // persists each one, so an out-of-zone address the buyer tried
                // then switched away from can otherwise stick on the order. This
                // final sync overrides any stale intermediate address so the
                // order, PayPal total, and thank-you page all match what was
                // authorized.
                var applyFinal = Promise.resolve( null );
                if ( needsShipping && paymentData && paymentData.shippingAddress ) {
                    var fa = paymentData.shippingAddress;
                    var fp = {
                        paypal_order_id:    orderId,
                        shipping_country:   String( fa.countryCode || '' ),
                        shipping_state:     String( fa.administrativeArea || '' ),
                        shipping_city:      String( fa.locality || '' ),
                        shipping_postcode:  String( fa.postalCode || '' ),
                        // The final authorised paymentData is the only place
                        // Google Pay reveals the full street line + recipient
                        // name, so forward them here to complete the order's
                        // shipping address (the recalculation callbacks above
                        // only ever see country/state/city/postcode).
                        shipping_address_1: String( fa.address1 || '' ),
                        shipping_address_2: String( fa.address2 || '' ),
                        shipping_name:      String( fa.name || '' ),
                    };
                    if ( paymentData.shippingOptionData
                         && paymentData.shippingOptionData.id
                         && paymentData.shippingOptionData.id !== 'pending' ) {
                        fp.selected_shipping_option_id = String( paymentData.shippingOptionData.id );
                    }
                    var fn = getWalletShippingNonce( orderId );
                    if ( fn ) fp._wpnonce = fn;
                    applyFinal = ajaxRequest( 'tejcart_paypal_wallet_shipping', fp ).then( function ( fr ) {
                        if ( ! fr || ! fr.success ) {
                            var fmsg = ( fr && fr.data && fr.data.message ) || 'We do not ship to the selected address. Please choose a different shipping address.';
                            gpWarn( 'Step 10 — final shipping apply rejected', fr && fr.data );
                            throw new Error( fmsg );
                        }
                        gpLog( 'Step 10 — final shipping address applied to order' );
                        return fr;
                    } );
                }
                return applyFinal.then( function () {
                gpLog( 'Step 10 — confirmOrder against PayPal order', orderId );
                return googlePaySession.confirmOrder( {
                    orderId:           orderId,
                    paymentMethodData: paymentData.paymentMethodData,
                } ).then( function ( cr ) {
                    gpLog( 'Step 10 — confirmOrder resolved', { status: cr && cr.status } );
                    // PAYER_ACTION_REQUIRED means the order still needs an
                    // extra buyer authentication step (3-D Secure / SCA)
                    // before it can be captured. PayPal's official v6 sample
                    // just returns SUCCESS here, which silently leaves the
                    // order uncaptured in SCA markets — the buyer sees a
                    // success sheet but is never charged. Run the documented
                    // initiatePayerAction challenge when the SDK exposes it,
                    // then capture; if it is unavailable or the challenge does
                    // not complete, release the pre-minted order and surface an
                    // honest error instead of a false success.
                    if ( cr && cr.status === 'PAYER_ACTION_REQUIRED' ) {
                        var paPromise = initiatePayerActionIfSupported( orderId );
                        if ( paPromise ) {
                            return paPromise
                                .then( function () {
                                    return captureGooglePayOrder( orderId );
                                } )
                                .catch( function ( paErr ) {
                                    cancelPendingPayPalOrder( orderId );
                                    console.error( 'Google Pay payer-action / 3DS not completed:', paErr );
                                    return {
                                        transactionState: 'ERROR',
                                        error: { message: ( paErr && paErr.message ) || 'Additional authentication was not completed. Please try again or use another payment method.' },
                                    };
                                } );
                        }
                        cancelPendingPayPalOrder( orderId );
                        return {
                            transactionState: 'ERROR',
                            error: { message: 'This card requires additional authentication that Google Pay could not complete here. Please use another payment method.' },
                        };
                    }
                    return captureGooglePayOrder( orderId );
                } );
                } );
            } ).catch( function ( err ) {
                gpWarn( 'Step 10 — onPaymentAuthorized / confirmOrder REJECTED', err );
                console.error( 'Google Pay payment authorization error:', err );
                return {
                    transactionState: 'ERROR',
                    error: { message: ( err && err.message ) || 'Google Pay could not be completed.' },
                };
            } );
        };

        var callbacks = { onPaymentAuthorized: onPaymentAuthorized };
        if ( needsShipping ) {
            // Dynamic shipping: Google calls onPaymentDataChanged when the buyer
            // picks an address / shipping option in the sheet, so we can return
            // live rates and an updated total (matches the standard flow).
            callbacks.onPaymentDataChanged = onPaymentDataChanged;
        }

        var gpEnvironment = ppParams.is_sandbox ? 'TEST' : 'PRODUCTION';
        gpLog( 'Step 6 — creating PaymentsClient', {
            environment: gpEnvironment,
            needs_shipping: needsShipping,
            callbacks: Object.keys( callbacks )
        } );
        if ( ! ppParams.is_sandbox && googlePayConfig && googlePayConfig.merchantInfo && googlePayConfig.merchantInfo.merchantOrigin ) {
            gpWarn( 'Step 6 — LIVE merchantOrigin vs page origin (must match a domain registered with Google Pay via PayPal, or you get OR_BIBED_06)', {
                merchantOrigin: googlePayConfig.merchantInfo.merchantOrigin,
                page_origin: ( typeof window !== 'undefined' && window.location ) ? window.location.origin : 'unknown'
            } );
        }
        var paymentsClient;
        try {
            paymentsClient = new google.payments.api.PaymentsClient( {
                environment:          gpEnvironment,
                paymentDataCallbacks: callbacks,
            } );
        } catch ( e ) {
            gpWarn( 'Step 6 — FAILED: PaymentsClient constructor threw', e );
            bailGooglePayButton( container );
            return;
        }

        // markExpressContainersReady() runs synchronously at the end of
        // onSDKReady() — before this isReadyToPay() promise resolves — and
        // sweeps any express slot still empty at that point. Google Pay is the
        // only wallet whose button is appended asynchronously (after this
        // round-trip), so flag the slot as pending render to stop the sweep
        // from detaching the container out from under us; otherwise the button
        // appends to a removed node and never appears. This is exactly why
        // Google Pay survived only on the product page, whose container lives
        // outside the swept express zone.
        container.setAttribute( 'data-tejcart-wallet-pending', '1' );

        gpLog( 'Step 7 — calling isReadyToPay', {
            apiVersion: googlePayConfig.apiVersion,
            apiVersionMinor: googlePayConfig.apiVersionMinor,
            allowedPaymentMethods: googlePayConfig.allowedPaymentMethods
        } );
        paymentsClient.isReadyToPay( {
            allowedPaymentMethods: googlePayConfig.allowedPaymentMethods,
            apiVersion:            googlePayConfig.apiVersion,
            apiVersionMinor:       googlePayConfig.apiVersionMinor,
        } ).then( function ( r ) {
            container.removeAttribute( 'data-tejcart-wallet-pending' );
            gpLog( 'Step 7 — isReadyToPay resolved', r );
            if ( ! r || ! r.result ) {
                gpWarn( 'Step 7 — isReadyToPay returned false — device/browser does not support Google Pay; button will not render.' );
                console.info( 'TejCart Google Pay: isReadyToPay returned false — device or browser does not support Google Pay.' );
                bailGooglePayButton( container );
                return;
            }
            var totalPriceRaw  = parseFloat( ppParams.order_total );
            var totalPriceStr  = ( isFinite( totalPriceRaw ) && totalPriceRaw > 0 )
                ? totalPriceRaw.toFixed( 2 )
                : '0.01';
            var priceStatus    = needsShipping
                ? 'ESTIMATED'
                : ( ( isFinite( totalPriceRaw ) && totalPriceRaw > 0 ) ? 'FINAL' : 'ESTIMATED' );

            // Dynamic shipping in the wallet: ask Google to call
            // onPaymentDataChanged on address/option changes so we can return
            // live rates, an updated total, and an unserviceable-address error
            // (which Google shows in-sheet, prompting the buyer to pick another
            // address). Mirrors the standard flow.
            var callbackIntents = [ 'PAYMENT_AUTHORIZATION' ];
            if ( needsShipping ) {
                callbackIntents.push( 'SHIPPING_ADDRESS', 'SHIPPING_OPTION' );
            }

            // Build the Google Pay PaymentDataRequest EXPLICITLY with only the
            // fields Google's schema accepts — exactly like the reference v5
            // integration (PayPal Payments). Do NOT spread the
            // whole getGooglePayConfig() object in: it carries extra properties
            // (isEligible, eligible, merchantCountry, a top-level countryCode)
            // that are not valid PaymentDataRequest fields, and shipping them to
            // loadPaymentData() is what made the sheet die with OR_BIBED_06.
            //
            // Also mirror v5 by requiring a billing address on the payment
            // method (billingAddressRequired + phoneNumberRequired) so the
            // wallet returns the billing contact.
            var gpAllowedPaymentMethods = ( googlePayConfig.allowedPaymentMethods || [] ).map( function ( method ) {
                var m = Object.assign( {}, method );
                m.parameters = Object.assign( {}, method.parameters || {} );
                m.parameters.billingAddressRequired   = true;
                m.parameters.billingAddressParameters = Object.assign(
                    {},
                    m.parameters.billingAddressParameters || {},
                    { phoneNumberRequired: true }
                );
                return m;
            } );

            var paymentDataRequest = {
                apiVersion:            2,
                apiVersionMinor:       0,
                allowedPaymentMethods: gpAllowedPaymentMethods,
                transactionInfo: {
                    totalPriceStatus: priceStatus,
                    totalPrice:       totalPriceStr,
                    currencyCode:     String( ppParams.currency || 'USD' ).toUpperCase(),
                    countryCode:      googlePayConfig.countryCode || String( ppParams.country || 'US' ).toUpperCase(),
                    // totalPriceLabel + checkoutOption mirror the working
                    // reference request. checkoutOption 'DEFAULT' is required
                    // for the multi-step (shipping-selection) flow so Google
                    // renders a "Continue" sheet instead of an immediate
                    // purchase — omitting it is the most likely reason an
                    // earlier dynamic-shipping attempt was rejected.
                    totalPriceLabel:  String( ppParams.store_name || 'Total' ),
                    checkoutOption:   'DEFAULT',
                },
                merchantInfo:    googlePayConfig.merchantInfo,
                emailRequired:   true,
                callbackIntents: callbackIntents,
            };


            if ( needsShipping ) {
                // Collect the address AND render the shipping-method picker in
                // the wallet. onPaymentDataChanged (registered above) returns
                // live rates per address and an updated total; an out-of-zone
                // address resolves to SHIPPING_ADDRESS_UNSERVICEABLE so Google
                // asks the buyer to pick another address in-sheet. Mirrors the
                // standard flow, with the classic 'pending' placeholder
                // option that the first onPaymentDataChanged call replaces.
                paymentDataRequest.shippingAddressRequired   = true;
                paymentDataRequest.shippingAddressParameters = {
                    phoneNumberRequired: true,
                };
                if ( allowedCountries.length ) {
                    paymentDataRequest.shippingAddressParameters.allowedCountryCodes = allowedCountries;
                }
                paymentDataRequest.shippingOptionRequired   = true;
                paymentDataRequest.shippingOptionParameters = {
                    defaultSelectedOptionId: 'pending',
                    shippingOptions: [
                        {
                            id:          'pending',
                            label:       'Shipping',
                            description: 'Calculated after address selection',
                        },
                    ],
                };
            }

            gpLog( 'Step 8 — paymentDataRequest assembled', paymentDataRequest );
            var btn = paymentsClient.createButton( buildGooglePayButtonOptions( function () {
                gpLog( 'Step 9 — Google Pay button clicked' );
                if ( typeof preflight === 'function' && preflight() === false ) {
                    gpWarn( 'Step 9 — preflight() returned false; aborting before opening the sheet' );
                    return;
                }
                if ( needsShipping ) {
                    ensureOrderId().catch( function () {} );
                }
                gpLog( 'Step 9 — calling loadPaymentData() (opens the Google Pay sheet)' );
                paymentsClient.loadPaymentData( paymentDataRequest ).then( function ( pd ) {
                    gpLog( 'Step 9 — loadPaymentData resolved (buyer authorized in sheet)', {
                        hasPaymentMethodData: !! ( pd && pd.paymentMethodData )
                    } );
                    return pd;
                } ).catch( function ( err ) {
                    gpWarn( 'Step 9 — loadPaymentData REJECTED', {
                        statusCode:    err && err.statusCode,
                        statusMessage: err && err.statusMessage,
                        error:         err
                    } );
                    // Always release the pre-minted PayPal order on a
                    // dismissed sheet — both the explicit CANCELED path
                    // and any other terminal failure in the wallet.
                    // The order was created on click for shipping
                    // callbacks; leaving it pending leaks a row + a
                    // stock reservation per dismissed sheet.
                    if ( pendingOrderIdPromise ) {
                        pendingOrderIdPromise.then( function ( id ) {
                            cancelPendingPayPalOrder( id );
                        } ).catch( function () {} );
                        pendingOrderIdPromise = null;
                    }
                    if ( err && err.statusCode === 'CANCELED' ) return;
                    console.error( 'Google Pay loadPaymentData error:', err );
                    showError( container, 'Google Pay could not be completed. Please try again.' );
                } );
            } ) );
            container.appendChild( btn );
            appendWalletDisclaimer( container );
            gpLog( 'Step 8 — Google Pay button rendered into', container && container.id );
        } ).catch( function ( err ) {
            container.removeAttribute( 'data-tejcart-wallet-pending' );
            gpWarn( 'Step 7 — isReadyToPay REJECTED', err );
            console.error( 'Google Pay isReadyToPay error:', err );
            bailGooglePayButton( container );
        } );
        } ).catch( function ( gpConfigErr ) {
            container.removeAttribute( 'data-tejcart-wallet-pending' );
            gpWarn( 'Step 4 — FAILED: Google Pay config acquisition rejected', gpConfigErr );
            console.error( 'TejCart Google Pay: getGooglePayConfig/formatConfigForPaymentRequest failed', gpConfigErr );
            bailGooglePayButton( container );
        } );
    }

    function renderApplePay( details ) {
        var container = document.getElementById( 'tejcart-applepay-container' );
        renderApplePayButton( container, details, function () {
            return getCheckoutDataWithToken().then( function ( cd ) { return ajaxRequest( 'tejcart_paypal_create_order', cd ); } )
                .then( function ( r ) {
                    if ( r.success && r.data && r.data.order_id ) {
                        rememberWalletShippingNonce( r.data.order_id, r.data.wallet_shipping_nonce );
                        return r.data.order_id;
                    }
                    throw new Error( ( r.data && r.data.message ) || 'Could not create order.' );
                } );
        }, runWalletPreflight );
    }

    function renderApplePayButton( container, details, createOrderFn, preflight ) {
        if ( ! container || container.hasChildNodes() ) return;
        if ( ! window.ApplePaySession ) {
            console.warn( 'TejCart Apple Pay: ApplePaySession not available — browser does not support Apple Pay (Apple Pay requires Safari on macOS / iOS).' );
            removeContainerIfEmpty( container );
            return;
        }
        try {
            if ( ! ApplePaySession.canMakePayments() ) {
                console.info( 'TejCart Apple Pay: ApplePaySession.canMakePayments() returned false — no Apple Pay capable card on this device.' );
                removeContainerIfEmpty( container );
                return;
            }
        } catch ( e ) {
            console.warn( 'TejCart Apple Pay: canMakePayments threw (likely insecure context)', e );
            removeContainerIfEmpty( container );
            return;
        }
        if ( ! sdkInstance || typeof sdkInstance.createApplePayOneTimePaymentSession !== 'function' ) {
            console.warn( 'TejCart Apple Pay: sdkInstance.createApplePayOneTimePaymentSession missing — applepay-payments component not loaded.' );
            removeContainerIfEmpty( container );
            return;
        }
        if ( ! details || ! details.config ) {
            console.warn( 'TejCart Apple Pay: missing eligibility details.config from PayPal SDK.' );
            removeContainerIfEmpty( container );
            return;
        }

        var paypalSession = sdkInstance.createApplePayOneTimePaymentSession();
        var baseRequest;
        try {
            baseRequest = paypalSession.formatConfigForPaymentRequest( details.config );
        } catch ( e ) {
            console.error( 'TejCart Apple Pay: formatConfigForPaymentRequest threw', e );
            removeContainerIfEmpty( container );
            return;
        }

        var apStyle = ppParams.apple_pay_style || {};
        var btn = document.createElement( 'apple-pay-button' );
        btn.setAttribute( 'buttonstyle', String( apStyle.style || 'black' ) );
        btn.setAttribute( 'type', String( apStyle.type || 'plain' ) );
        if ( apStyle.locale ) btn.setAttribute( 'locale', String( apStyle.locale ) );
        btn.style.width  = '100%';
        var heightPx     = parseInt( apStyle.height, 10 );
        btn.style.height = ( isFinite( heightPx ) && heightPx >= 30 && heightPx <= 64 ? heightPx : 44 ) + 'px';
        var radiusPx     = parseInt( apStyle.radius, 10 );
        if ( isFinite( radiusPx ) && radiusPx >= 0 && radiusPx <= 50 ) {
            btn.style.setProperty( '-apple-pay-button-border-radius', radiusPx + 'px' );
        }

        var needsShipping = !! ppParams.needs_shipping;

        btn.addEventListener( 'click', function () {
            if ( typeof preflight === 'function' && preflight() === false ) {
                return;
            }
            var totalRaw = parseFloat( ppParams.order_total );
            var totalStr = ( isFinite( totalRaw ) && totalRaw > 0 ) ? totalRaw.toFixed( 2 ) : '0.01';

            // When shipping is required we must:
            //   1. Ask Apple Pay to collect the shipping postalAddress so
            //      onshippingcontactselected fires with countryCode +
            //      postalCode + admin area; the buyer's street is masked
            //      until confirmation, which is fine for shipping math.
            //   2. Seed at least one shippingMethod, otherwise the picker
            //      doesn't render at all in the sheet — we use a neutral
            //      placeholder that's replaced on the first contact-change
            //      callback.
            var requiredShippingFields = Array.isArray( apStyle.requiredShippingFields )
                ? apStyle.requiredShippingFields.slice()
                : [];
            if ( needsShipping ) {
                [ 'postalAddress', 'name', 'email', 'phone' ].forEach( function ( f ) {
                    if ( requiredShippingFields.indexOf( f ) === -1 ) {
                        requiredShippingFields.push( f );
                    }
                } );
            }

            // Build the Apple Pay PaymentRequest EXPLICITLY with only the
            // fields Apple's schema accepts — per the v6 docs (countryCode,
            // currencyCode, merchantCapabilities, supportedNetworks, required*
            // ContactFields, total [+ shipping*]). merchantCapabilities and
            // supportedNetworks come from the SDK config (the docs' config(),
            // surfaced here via formatConfigForPaymentRequest). Do NOT spread
            // the whole config object in, mirroring the Google Pay fix.
            var paymentRequest = {
                countryCode:                   ( baseRequest && baseRequest.countryCode ) || 'US',
                currencyCode:                  String( ppParams.currency || 'USD' ).toUpperCase(),
                // Prefer the SDK config values; fall back to the same defaults
                // the working reference PayPal integration hardcodes, so the
                // sheet always has valid networks/capabilities even when the
                // SDK config omits them.
                merchantCapabilities:          ( baseRequest && baseRequest.merchantCapabilities ) || [ 'supports3DS' ],
                supportedNetworks:             ( baseRequest && baseRequest.supportedNetworks ) || [ 'visa', 'masterCard', 'amex', 'discover' ],
                requiredBillingContactFields:  Array.isArray( apStyle.requiredBillingFields )
                    ? apStyle.requiredBillingFields
                    : ( needsShipping ? [ 'postalAddress', 'name' ] : [] ),
                requiredShippingContactFields: requiredShippingFields,
                total: {
                    label:  String( ppParams.store_name || 'Store' ),
                    amount: totalStr,
                    type:   'final',
                },
            };

            if ( needsShipping ) {
                paymentRequest.shippingType    = 'shipping';
                paymentRequest.shippingMethods = [
                    {
                        identifier: 'shipping_option_unselected',
                        label:      'Calculating…',
                        detail:     'Choose an address to see options',
                        amount:     '0.00',
                    },
                ];
            }

            var session;
            try {
                session = new ApplePaySession( 4, paymentRequest );
            } catch ( e ) {
                console.error( 'TejCart Apple Pay: ApplePaySession constructor threw', e );
                showError( container, 'Apple Pay could not be started.' );
                return;
            }

            // Pre-mint the PayPal order so shipping callbacks have an id.
            var pendingOrderIdPromise = null;
            function ensureOrderId() {
                if ( pendingOrderIdPromise ) return pendingOrderIdPromise;
                pendingOrderIdPromise = createOrderFn().catch( function ( err ) {
                    pendingOrderIdPromise = null;
                    throw err;
                } );
                return pendingOrderIdPromise;
            }
            if ( needsShipping ) {
                ensureOrderId().catch( function () {} );
            }

            function buildAppleErrorList( reason ) {
                if ( typeof ApplePayError !== 'function' ) return [];
                var code = 'addressUnserviceable';
                var field = 'country';
                if ( reason === 'POSTAL_CODE_ERROR' ) {
                    field = 'postalCode';
                } else if ( reason === 'STATE_ERROR' ) {
                    field = 'administrativeArea';
                }
                try {
                    return [ new ApplePayError( code, field, 'Shipping is unavailable for this address.' ) ];
                } catch ( e ) {
                    return [];
                }
            }

            // Apple's onshippingmethodselected only forwards the chosen
            // method, not the address — so we cache the most recent
            // shippingContact in a closure and re-send it when the
            // buyer switches between methods. Without this the server
            // would see an empty country and reject with COUNTRY_ERROR,
            // and the sheet's total would never update on method change.
            var lastShippingContact = null;

            function fetchWalletShipping( shippingContact, selectedMethodId ) {
                var contact = shippingContact || lastShippingContact;
                return ensureOrderId().then( function ( orderId ) {
                    var payload = {
                        paypal_order_id:   orderId,
                        shipping_country:  String( ( contact && contact.countryCode ) || '' ),
                        shipping_state:    String( ( contact && contact.administrativeArea ) || '' ),
                        shipping_city:     String( ( contact && contact.locality ) || '' ),
                        shipping_postcode: String( ( contact && contact.postalCode ) || '' ),
                    };
                    if ( selectedMethodId && selectedMethodId !== 'shipping_option_unselected' ) {
                        payload.selected_shipping_option_id = String( selectedMethodId );
                    }
                    var perOrderNonce = getWalletShippingNonce( orderId );
                    if ( perOrderNonce ) payload._wpnonce = perOrderNonce;
                    return ajaxRequest( 'tejcart_paypal_wallet_shipping', payload );
                } );
            }

            session.onvalidatemerchant = function ( ev ) {
                paypalSession.validateMerchant( { validationUrl: ev.validationURL } )
                    .then( function ( p ) {
                        session.completeMerchantValidation( p.merchantSession );
                    } )
                    .catch( function ( err ) {
                        console.error( 'TejCart Apple Pay: validateMerchant failed', err );
                        session.abort();
                    } );
            };

            session.onpaymentmethodselected = function () {
                session.completePaymentMethodSelection( {
                    newTotal:     paymentRequest.total,
                    newLineItems: [],
                } );
            };

            if ( needsShipping ) {
                session.onshippingcontactselected = function ( ev ) {
                    var pendingContact = ev.shippingContact || null;
                    lastShippingContact = pendingContact;
                    fetchWalletShipping( pendingContact, '' ).then( function ( r ) {
                        if ( ! r || ! r.success || ! r.data ) {
                            // Server rejected the address. Drop the cached
                            // contact so the next onshippingmethodselected
                            // hop doesn't replay this same failed payload —
                            // without this the buyer is locked into a loop
                            // of identical 400s until they dismiss the sheet.
                            lastShippingContact = null;
                            var reason = ( r && r.data && r.data.reject_reason ) || 'COUNTRY_ERROR';
                            session.completeShippingContactSelection( {
                                newTotal:           paymentRequest.total,
                                newLineItems:       [],
                                newShippingMethods: paymentRequest.shippingMethods || [],
                                errors:             buildAppleErrorList( reason ),
                            } );
                            return;
                        }
                        var d = r.data;
                        paymentRequest.total = d.apple_pay_total;
                        session.completeShippingContactSelection( {
                            newTotal:           d.apple_pay_total,
                            newLineItems:       d.apple_pay_line_items || [],
                            newShippingMethods: d.apple_pay_methods || [],
                            errors:             [],
                        } );
                    } ).catch( function ( err ) {
                        // Same reasoning — a transport failure leaves the
                        // server state ambiguous; clear the cache so the
                        // method-select hop starts from a clean slate.
                        lastShippingContact = null;
                        console.error( 'TejCart Apple Pay shipping-contact error:', err );
                        session.completeShippingContactSelection( {
                            newTotal:           paymentRequest.total,
                            newLineItems:       [],
                            newShippingMethods: paymentRequest.shippingMethods || [],
                            errors:             buildAppleErrorList( 'COUNTRY_ERROR' ),
                        } );
                    } );
                };

                session.onshippingmethodselected = function ( ev ) {
                    var selectedId = ev.shippingMethod && ev.shippingMethod.identifier
                        ? ev.shippingMethod.identifier
                        : '';
                    // Apple only re-sends the chosen method's metadata,
                    // not the address, so re-use whatever the previous
                    // contact-selected callback persisted on the server
                    // by sending an empty contact.
                    fetchWalletShipping( null, selectedId ).then( function ( r ) {
                        if ( ! r || ! r.success || ! r.data ) {
                            session.completeShippingMethodSelection( {
                                newTotal:     paymentRequest.total,
                                newLineItems: [],
                            } );
                            return;
                        }
                        var d = r.data;
                        paymentRequest.total = d.apple_pay_total;
                        session.completeShippingMethodSelection( {
                            newTotal:     d.apple_pay_total,
                            newLineItems: d.apple_pay_line_items || [],
                        } );
                    } ).catch( function ( err ) {
                        console.error( 'TejCart Apple Pay shipping-method error:', err );
                        session.completeShippingMethodSelection( {
                            newTotal:     paymentRequest.total,
                            newLineItems: [],
                        } );
                    } );
                };
            }

            session.onpaymentauthorized = function ( ev ) {
                // Capture the order id eagerly so the failure branches below
                // can release the pre-minted PayPal order without re-entering
                // ensureOrderId() (which may itself reject if the order id was
                // already lost). cancel_order is idempotent server-side: it
                // refuses to act on anything but a `pending` order, so calling
                // it after a successful capture is a safe no-op.
                var capturedOrderId = null;
                function releasePending() {
                    if ( capturedOrderId ) {
                        cancelPendingPayPalOrder( capturedOrderId );
                        capturedOrderId = null;
                    }
                }
                ensureOrderId().then( function ( orderId ) {
                    capturedOrderId = orderId;
                    // Persist the FINAL shipping contact the buyer authorized,
                    // overriding any stale intermediate address that an earlier
                    // onshippingcontactselected saved, before confirm/capture —
                    // same rationale as the Google Pay final-address sync. If
                    // the final address is unserviceable the call throws and
                    // capture is aborted.
                    var apFinal = Promise.resolve( null );
                    if ( needsShipping && ev.payment && ev.payment.shippingContact ) {
                        var apc = ev.payment.shippingContact;
                        var apcLines = Array.isArray( apc.addressLines ) ? apc.addressLines : [];
                        var apcName  = String( ( ( apc.givenName || '' ) + ' ' + ( apc.familyName || '' ) ).trim() );
                        var app = {
                            paypal_order_id:   orderId,
                            shipping_country:  String( apc.countryCode || '' ),
                            shipping_state:    String( apc.administrativeArea || '' ),
                            shipping_city:     String( apc.locality || '' ),
                            shipping_postcode: String( apc.postalCode || '' ),
                            // Apple Pay's authorized shippingContact carries the
                            // full street + recipient name; forward them so the
                            // order records a complete, deliverable address (the
                            // onshippingcontactselected callbacks only ever see
                            // country/state/city/postcode — same as Google Pay).
                            shipping_address_1: String( apcLines[0] || '' ),
                            shipping_address_2: String( apcLines[1] || '' ),
                            shipping_name:      apcName,
                        };
                        var apn = getWalletShippingNonce( orderId );
                        if ( apn ) app._wpnonce = apn;
                        apFinal = ajaxRequest( 'tejcart_paypal_wallet_shipping', app ).then( function ( fr ) {
                            if ( ! fr || ! fr.success ) {
                                throw new Error( ( fr && fr.data && fr.data.message ) || 'We do not ship to the selected address. Please choose a different shipping address.' );
                            }
                            return fr;
                        } );
                    }
                    return apFinal.then( function () {
                    return paypalSession.confirmOrder( {
                        orderId:         orderId,
                        token:           ev.payment.token,
                        billingContact:  ev.payment.billingContact,
                        shippingContact: ev.payment.shippingContact,
                    } ).then( function () {
                        // Tag the wallet so the order records "Apple Pay"
                        // rather than a generic "PayPal" (see the Google Pay
                        // capture for the same rationale).
                        // Apple Pay exposes the buyer email on the shipping /
                        // billing contact; forward it as `wallet_email` so the
                        // order records an email when PayPal's capture omits it.
                        var apEmail = String(
                            ( ev.payment.shippingContact && ev.payment.shippingContact.emailAddress )
                            || ( ev.payment.billingContact && ev.payment.billingContact.emailAddress )
                            || ''
                        );
                        return ajaxRequest( 'tejcart_paypal_capture_order', { paypal_order_id: orderId, express: '1', funding_source: 'apple_pay', wallet_email: apEmail } );
                    } );
                    } );
                } ).then( function ( result ) {
                    if ( result.success && result.data && result.data.redirect ) {
                        session.completePayment( { status: ApplePaySession.STATUS_SUCCESS } );
                        var target = isSafeUrl( result.data.redirect ) ? result.data.redirect : '/';
                        setTimeout( function () { window.location.href = target; }, 0 );
                    } else {
                        // Server returned !success — the capture did not land,
                        // so the pending PayPal order and its stock reservation
                        // would otherwise leak until the orphan-sweep cron runs.
                        releasePending();
                        session.completePayment( { status: ApplePaySession.STATUS_FAILURE } );
                        showError( container, ( result.data && result.data.message ) || 'Apple Pay capture failed.' );
                    }
                } ).catch( function ( err ) {
                    // Network drop, gateway 5xx, lock contention surfacing as
                    // 409, or confirmOrder threw. In every case the capture
                    // did not land — release the pre-minted order to free
                    // its stock reservation. Mirrors Google Pay's
                    // loadPaymentData.catch() cleanup.
                    releasePending();
                    console.error( 'TejCart Apple Pay: payment authorization error', err );
                    session.completePayment( { status: ApplePaySession.STATUS_FAILURE } );
                    showError( container, 'Apple Pay could not be completed. Please try again.' );
                } );
            };

            session.oncancel = function () {
                // Buyer dismissed the sheet. Cancel the pending PayPal
                // order we pre-minted on click so we don't leak a row
                // for every closed wallet. The existing orphan-sweep
                // cron is a safety net, not the happy path.
                if ( pendingOrderIdPromise ) {
                    pendingOrderIdPromise.then( function ( id ) {
                        cancelPendingPayPalOrder( id );
                    } ).catch( function () {} );
                    pendingOrderIdPromise = null;
                }
            };

            session.begin();
        } );
        container.appendChild( btn );
        appendWalletDisclaimer( container );
    }

    var FASTLANE_V6_ENABLED = false;

    function initFastlane() {
        var flContainer = document.getElementById( 'tejcart-fastlane-container' );
        if ( ! flContainer ) return;
        if ( ! FASTLANE_V6_ENABLED ) return;

        sdkInstance.createFastlane().then( function ( fastlane ) {
            var watermark = document.getElementById( 'tejcart-fastlane-watermark' );
            if ( watermark ) {
                fastlane.FastlaneWatermarkComponent( { includeAdditionalInfo: true } )
                    .then( function ( component ) { component.render( '#tejcart-fastlane-watermark' ); } );
            }

            var emailField = document.querySelector( 'input[name="billing_email"]' );
            if ( ! emailField ) return;

            emailField.addEventListener( 'blur', function () {
                var email = emailField.value.trim();

                if ( ! email || email.length > 254 || ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( email ) ) return;

                fastlane.identity.lookupCustomerByEmail( email ).then( function ( result ) {
                    if ( ! result.customerContextId ) return;
                    return fastlane.identity.triggerAuthenticationFlow( result.customerContextId );
                } ).then( function ( auth ) {
                    if ( ! auth || auth.authenticationState !== 'succeeded' ) return;

                    var profile = auth.profileData;
                    if ( profile && profile.name ) {
                        setField( 'billing_first_name', profile.name.firstName );
                        setField( 'billing_last_name', profile.name.lastName );
                    }
                    if ( profile && profile.shippingAddress ) {
                        var a = profile.shippingAddress;
                        setField( 'billing_address_1', a.addressLine1 );
                        if ( a.addressLine2 ) setField( 'billing_address_2', a.addressLine2 );
                        setField( 'billing_city', a.adminArea2 );
                        setField( 'billing_state', a.adminArea1 );
                        setField( 'billing_postcode', a.postalCode );
                        setField( 'billing_country', a.countryCode );

                        setField( 'shipping_first_name', profile.name && profile.name.firstName );
                        setField( 'shipping_last_name', profile.name && profile.name.lastName );
                        setField( 'shipping_address_1', a.addressLine1 );
                        if ( a.addressLine2 ) setField( 'shipping_address_2', a.addressLine2 );
                        setField( 'shipping_city', a.adminArea2 );
                        setField( 'shipping_state', a.adminArea1 );
                        setField( 'shipping_postcode', a.postalCode );
                        setField( 'shipping_country', a.countryCode );
                    }
                    if ( profile && profile.card && profile.card.phoneNumber ) {
                        var phone = profile.card.phoneNumber;
                        var full  = ( phone.countryCode ? '+' + phone.countryCode : '' ) + ( phone.nationalNumber || '' );
                        if ( full ) setField( 'billing_phone', full );
                    }
                    showNotice( flContainer, 'Welcome back! Your details have been auto-filled.', 'success' );
                } ).catch( function () {  } );
            } );
        } );
    }

    /**
     * Rotate `ppParams.cart_nonce` against the `tejcart_refresh_nonce`
     * AJAX endpoint. Mirrors the recovery path in tejcart-cart.js so a
     * stale nonce on the product detail page (page sat open past the WP
     * tick window, or a login/logout in another tab changed the cookie
     * identity) can be self-healed without forcing the buyer to reload.
     * Resolves true on success so the caller can replay the original
     * request, false otherwise.
     */
    function refreshCartNonce() {
        return new Promise( function ( resolve ) {
            var formData = new FormData();
            formData.append( 'action', 'tejcart_refresh_nonce' );

            fetch( ppParams.ajax_url || '/wp-admin/admin-ajax.php', {
                method:      'POST',
                credentials: 'same-origin',
                body:        formData,
            } ).then( function ( r ) {
                return r.ok ? r.json() : null;
            } ).then( function ( body ) {
                if ( body && body.success && body.data && typeof body.data.nonce === 'string' && body.data.nonce ) {
                    ppParams.cart_nonce = body.data.nonce;
                    resolve( true );
                    return;
                }
                resolve( false );
            } ).catch( function () { resolve( false ); } );
        } );
    }

    /**
     * Express buttons on the product detail page must seed
     * the session cart before the PayPal flow runs, otherwise an
     * `onCancel` from the wallet leaves the buyer with a transient pending
     * order and an empty `/cart/` page. We POST to the cart's add-to-cart
     * endpoint with the cart nonce, await success, then route to the same
     * cart-backed express endpoint the side-cart and cart-page buttons use.
     *
     * On a 403 we rotate the cart nonce against `tejcart_refresh_nonce`
     * and replay once; `allowRetry` guards against retry loops if the
     * refreshed nonce itself is rejected.
     */
    function addProductToCartForExpress( wrapper, productId, allowRetry ) {
        if ( typeof allowRetry === 'undefined' ) allowRetry = true;

        var cartNonce = ppParams.cart_nonce;
        if ( ! cartNonce ) {
            return Promise.reject( new Error( 'Cart security token unavailable. Reload and try again.' ) );
        }

        // Resolve the product scope. The PDP isn't wrapped in a <form>, so
        // fall back to the single-product container (then document).
        var scope = ( wrapper && wrapper.closest
            && ( wrapper.closest( '.tejcart-single-product' ) || wrapper.closest( 'form' ) ) ) || document;

        var qty = 1;
        var qtyInput = scope.querySelector( 'input[name="quantity"], .tejcart-qty-input' );
        if ( qtyInput && qtyInput.value ) {
            var parsed = parseInt( qtyInput.value, 10 );
            if ( ! isNaN( parsed ) && parsed > 0 ) qty = parsed;
        }

        var formData = new FormData();
        formData.append( 'action', 'tejcart_add_to_cart' );
        formData.append( '_wpnonce', cartNonce );
        formData.append( 'product_id', String( productId ) );
        formData.append( 'quantity', String( qty ) );

        // Variable products must not start the express flow until the buyer
        // has selected a variation. TejCart's variation UI tracks the matched
        // variation on the add-to-cart button's data-variation-id (set by
        // tejcart-cart.js syncVariationState) and marks the form with
        // [data-tejcart-variations]; a legacy hidden input[name="variation_id"]
        // and Woo-style markup are also honoured for compatibility. If a
        // variation is required but unselected we refuse to start the PayPal
        // session — otherwise the server falls through to the parent product
        // (or errors), which historically charged the buyer for the wrong SKU.
        var variationInput = scope.querySelector( 'input[name="variation_id"]' );
        var variationValue = variationInput && variationInput.value ? parseInt( variationInput.value, 10 ) : 0;
        if ( ! variationValue ) {
            var atcBtn = scope.querySelector( '.tejcart-add-to-cart-btn[data-variation-id]' );
            if ( atcBtn ) {
                variationValue = parseInt( atcBtn.getAttribute( 'data-variation-id' ), 10 ) || 0;
            }
        }

        var hasVariationsUI = !! (
            scope.querySelector( '[data-tejcart-variations], .tejcart-variations-form, [data-product-type="variable"], table.tejcart-variations, .variations_form' )
        );

        if ( hasVariationsUI && ( ! variationValue || variationValue <= 0 ) ) {
            return Promise.reject( new Error( 'Please choose a product option before continuing with PayPal.' ) );
        }

        if ( variationValue > 0 ) {
            formData.append( 'variation_id', String( variationValue ) );

            // Mirror the regular add-to-cart submit: forward each chosen
            // attribute as data[<attribute name>] (using the variation
            // select's human-readable name) so the line item records the
            // same attributes, plus the raw attribute_* fields for
            // legacy/Woo-style consumers.
            scope.querySelectorAll( '.tejcart-variation-select' ).forEach( function ( sel ) {
                var aname = sel.getAttribute( 'data-attribute-name' ) || '';
                if ( aname && sel.value ) {
                    formData.append( 'data[' + aname + ']', sel.value );
                }
            } );
            scope.querySelectorAll( 'select[name^="attribute_"], input[name^="attribute_"]' ).forEach( function ( input ) {
                if ( input.name && input.value ) {
                    formData.append( input.name, input.value );
                }
            } );
        }

        return fetch( ppParams.ajax_url || '/wp-admin/admin-ajax.php', {
            method:      'POST',
            credentials: 'same-origin',
            body:        formData,
        } ).then( function ( response ) {
            return response.json().then(
                function ( body ) { return { status: response.status, body: body }; },
                function () { return { status: response.status, body: null }; }
            );
        } ).then( function ( wrapped ) {
            if ( allowRetry && wrapped.status === 403 ) {
                return refreshCartNonce().then( function ( ok ) {
                    if ( ! ok ) {
                        var msg = ( wrapped.body && wrapped.body.data && wrapped.body.data.message )
                            || 'Security check failed. Please reload the page and try again.';
                        throw new Error( msg );
                    }
                    return addProductToCartForExpress( wrapper, productId, false );
                } );
            }

            var result = wrapped.body;
            if ( ! result || ! result.success ) {
                throw new Error( ( result && result.data && result.data.message ) || 'Could not add product to cart.' );
            }
            return result;
        } );
    }

    function pdpExpressCreateOrder( wrapper, productId ) {
        return addProductToCartForExpress( wrapper, productId ).then( expressCreateOrder );
    }

    function renderProductPageButtons( methods ) {
        var containers = document.querySelectorAll( '.tejcart-product-smart-buttons' );
        if ( ! containers.length ) return;

        containers.forEach( function ( wrapper ) {
            var productId = wrapper.getAttribute( 'data-product-id' );

            var ppBtn = wrapper.querySelector( '.tejcart-product-paypal-btn' );
            if ( ppBtn && ! ppBtn.hasChildNodes() ) {
                if ( methods.isEligible( 'paypal' ) ) {
                    renderPayPalButtonInContainer( ppBtn, buildButtonStyle( { layout: 'horizontal', defaultHeight: 35 } ), function () {
                        return pdpExpressCreateOrder( wrapper, productId );
                    }, expressOnApprove );
                } else {
                    removeContainerIfEmpty( ppBtn );
                }
            }

            var vmBtn = wrapper.querySelector( '.tejcart-product-venmo-btn' );
            if ( vmBtn && ! vmBtn.hasChildNodes() ) {
                if ( ppParams.enable_venmo && methods.isEligible( 'venmo' ) ) {
                    renderVenmoButton( vmBtn, function () {
                        return pdpExpressCreateOrder( wrapper, productId );
                    }, expressOnApprove );
                } else {
                    removeContainerIfEmpty( vmBtn );
                }
            }

            var gpBtn = wrapper.querySelector( '.tejcart-product-googlepay-btn' );
            if ( gpBtn && ! gpBtn.hasChildNodes() ) {
                var pdpGpDetails = ( googlePayPlacementEnabled( 'button_product_page' ) && methods.isEligible( 'googlepay' ) )
                    ? safeGetDetails( methods, 'googlepay' )
                    : null;
                if ( pdpGpDetails ) {
                    renderGooglePayButton( gpBtn, pdpGpDetails, function () {
                        return pdpExpressCreateOrder( wrapper, productId );
                    } );
                } else {
                    removeContainerIfEmpty( gpBtn );
                }
            }

            var apBtn = wrapper.querySelector( '.tejcart-product-applepay-btn' );
            if ( apBtn && ! apBtn.hasChildNodes() ) {
                var pdpApDetails = ( ppParams.enable_apple_pay && methods.isEligible( 'applepay' ) )
                    ? safeGetDetails( methods, 'applepay' )
                    : null;
                if ( pdpApDetails ) {
                    renderApplePayButton( apBtn, pdpApDetails, function () {
                        return pdpExpressCreateOrder( wrapper, productId );
                    } );
                } else {
                    removeContainerIfEmpty( apBtn );
                }
            }
        } );
    }

    function renderPayLaterMessages() {
        var allMessages = document.querySelectorAll( '.tejcart-paylater-message' );

        if ( ! ppParams.enable_paylater || ! allMessages.length ) {
            allMessages.forEach( removeContainerIfEmpty );
            return;
        }

        if ( ! sdkInstance || typeof sdkInstance.createPayPalMessages !== 'function' ) {
            allMessages.forEach( removeContainerIfEmpty );
            return;
        }

        var messagesInstance;
        try {
            messagesInstance = sdkInstance.createPayPalMessages();
        } catch ( e ) {
            allMessages.forEach( removeContainerIfEmpty );
            return;
        }

        if ( ! messagesInstance || typeof messagesInstance.fetchContent !== 'function' ) {
            allMessages.forEach( removeContainerIfEmpty );
            return;
        }

        var logoTypeMap = {
            primary:     'PRIMARY',
            alternative: 'ALTERNATIVE',
            inline:      'INLINE',
            none:        'NONE',
        };
        var textColorMap = {
            black:      'BLACK',
            white:      'WHITE',
            monochrome: 'MONOCHROME',
            grayscale:  'GRAYSCALE',
        };
        var textAlignMap = {
            left:   'LEFT',
            center: 'CENTER',
            right:  'RIGHT',
        };

        allMessages.forEach( function ( el ) {
            var logoType = ( el.getAttribute( 'data-pp-style-logo-type' ) || 'primary' ).toLowerCase();
            var textColor = ( el.getAttribute( 'data-pp-style-text-color' ) || 'black' ).toLowerCase();
            var textSizeAttr = el.getAttribute( 'data-pp-style-text-size' );
            var textAlignAttr = ( el.getAttribute( 'data-pp-style-text-align' ) || '' ).toLowerCase();

            var elementOpts = ( typeof el.getFetchContentOptions === 'function' )
                ? ( el.getFetchContentOptions() || {} )
                : {};

            var options = Object.assign( {}, elementOpts, {
                logoType:  logoTypeMap[ logoType ] || 'PRIMARY',
                textColor: textColorMap[ textColor ] || 'BLACK',
                onReady:   function ( content ) {
                    if ( typeof el.setContent === 'function' ) {
                        try { el.setContent( content ); } catch ( e ) {}
                    }
                },
            } );

            var textSizeNum = parseInt( textSizeAttr, 10 );
            if ( ! isNaN( textSizeNum ) && textSizeNum >= 10 && textSizeNum <= 16 ) {
                options.textSize = textSizeNum;
            }
            if ( textAlignMap[ textAlignAttr ] ) {
                options.textAlign = textAlignMap[ textAlignAttr ];
            }

            // Mirror style options into a nested style object as well, since
            // some PayPal SDK versions expect the nested shape:
            //   style: { logo: { type }, text: { color, size, align } }.
            options.style = Object.assign( {}, options.style || {} );
            options.style.logo = Object.assign( {}, options.style.logo || {}, {
                type: logoTypeMap[ logoType ] || 'PRIMARY',
            } );
            options.style.text = Object.assign( {}, options.style.text || {}, {
                color: textColorMap[ textColor ] || 'BLACK',
            } );
            if ( ! isNaN( textSizeNum ) && textSizeNum >= 10 && textSizeNum <= 16 ) {
                options.style.text.size = textSizeNum;
            }
            if ( textAlignMap[ textAlignAttr ] ) {
                options.style.text.align = textAlignMap[ textAlignAttr ];
            }

            if ( ! options.amount ) {
                var amtAttr = el.getAttribute( 'amount' );
                if ( amtAttr ) options.amount = amtAttr;
            }
            if ( ! options.currencyCode ) {
                var ccAttr = el.getAttribute( 'currency-code' );
                if ( ccAttr ) options.currencyCode = ccAttr;
            }

            try {
                var result = messagesInstance.fetchContent( options );
                if ( result && typeof result.catch === 'function' ) {
                    result.catch( function () {} );
                }
            } catch ( e ) {
            }

            // The Pay Later `<paypal-message>` is informational marketing,
            // not an interactive form control — the iframe inside should
            // never sit between a buyer and the next form field in the
            // Tab cycle. Lock it (and any wrapper the SDK injects) out of
            // the keyboard tab order. Screen reader users can still read
            // the content visually / in browse mode; only the Tab key is
            // suppressed.
            restrictTabStopsToIframes( el, { includeIframes: true } );
        } );
    }

    function getCheckoutData() {
        var form = document.getElementById( 'tejcart-checkout-form' );
        if ( ! form ) return {};

        var formData = {};
        var inputs = form.querySelectorAll( 'input, select, textarea' );
        inputs.forEach( function ( input ) {
            if ( input.name && input.type !== 'submit' ) {
                if ( input.type === 'checkbox' ) {
                    formData[ input.name ] = input.checked ? input.value : '';
                } else if ( input.type === 'radio' ) {
                    if ( input.checked ) formData[ input.name ] = input.value;
                } else {
                    formData[ input.name ] = input.value;
                }
            }
        } );
        return formData;
    }

    /**
     * Resolve the checkout payload, augmented with a fresh bot-gate token
     * when the optional captcha module is active.
     *
     * The standard PayPal create-order path runs `Checkout::process()`
     * server-side, which fires the same `tejcart_checkout_pre_validate`
     * gate as the regular checkout submit. The plain checkout form attaches
     * its token via `tejcartCaptcha.appendTo()`, but the wallet/card buttons
     * build their payload from `getCheckoutData()` instead — so without this
     * a configured provider rejects every PayPal order with
     * "Bot-protection token missing." When the module is disabled
     * `window.tejcartCaptcha` is absent and this resolves to the plain data.
     *
     * @param {string} action Gate action label (default 'checkout').
     * @return {Promise<Object>}
     */
    function getCheckoutDataWithToken( action ) {
        var data = getCheckoutData();
        if ( window.tejcartCaptcha && window.tejcartCaptcha.isActive() ) {
            return window.tejcartCaptcha.getToken( action || 'checkout' ).then( function ( token ) {
                if ( token ) {
                    data.tejcart_bot_token = token;
                }
                return data;
            } );
        }
        return Promise.resolve( data );
    }

    /**
     * Make an AJAX request with proper error handling.
     *
     * Mirrors the cart-JS once-retrying pattern: a 403 response from
     * the page-scoped `tejcart_paypal` nonce is treated as the cookie
     * having rotated under us (page cache, login/logout in another
     * tab, session expiry) — hit `tejcart_refresh_nonce` to mint a
     * fresh pair of nonces and replay the original request once. The
     * retry is suppressed when the caller supplied its own
     * `_wpnonce` override (the per-order wallet_shipping nonce) since
     * those nonces are order-scoped and can't be refreshed from a
     * generic endpoint.
     *
     * @param {string} action  WordPress AJAX action.
     * @param {Object} data    Additional POST data.
     * @return {Promise}
     */
    function ajaxRequest( action, data ) {
        return ajaxRequestOnce( action, data, true );
    }

    function ajaxRequestOnce( action, data, allowRetry ) {
        // Allow callers to override the page nonce for endpoints that use
        // an action-bound nonce (see wallet_shipping). Pop the
        // override out of `data` so it doesn't get appended twice.
        var overrideNonce = '';
        if ( data && typeof data === 'object' && data._wpnonce ) {
            overrideNonce = String( data._wpnonce );
            delete data._wpnonce;
        }
        var nonce = overrideNonce || ppParams.nonce;
        if ( ! nonce ) {
            return Promise.reject( new Error( 'TejCart PayPal: missing security nonce.' ) );
        }

        var formData = new FormData();
        formData.append( 'action', action );
        formData.append( '_wpnonce', nonce );

        if ( data && typeof data === 'object' ) {
            Object.keys( data ).forEach( function ( key ) {
                formData.append( key, data[ key ] );
            } );
        }

        // Explicit timeout via AbortController. Without it a
        // stalled connection leaves the loading overlay sticky forever.
        // 30s matches the limit tejcart-checkout.js uses on the main
        // checkout submit. If AbortController isn't available (very old
        // browsers) we fall through to a plain fetch — better to ship
        // the request than to refuse the buyer's payment.
        var controller = ( typeof AbortController === 'function' ) ? new AbortController() : null;
        var timeoutMs  = ( ppParams && parseInt( ppParams.ajax_timeout_ms, 10 ) ) || 30000;
        var timer      = controller ? setTimeout( function () { controller.abort(); }, timeoutMs ) : null;

        var fetchOpts = {
            method:      'POST',
            credentials: 'same-origin',
            body:        formData,
        };
        if ( controller ) {
            fetchOpts.signal = controller.signal;
        }

        var clearTimer = function () { if ( timer ) { clearTimeout( timer ); timer = null; } };

        return fetch( ppParams.ajax_url || '/wp-admin/admin-ajax.php', fetchOpts )
            .then( function ( response ) {
                clearTimer();
                var status = response.status;
                return response.json().then(
                    function ( body ) { return { status: status, body: body }; },
                    function () {
                        if ( ! response.ok ) {
                            throw new Error( 'Server error: ' + status );
                        }
                        throw new Error( 'Invalid server response.' );
                    }
                );
            } )
            .then( function ( wrapped ) {
                // 403 + page-scoped nonce ⇒ refresh and retry once.
                // We don't retry when the caller supplied its own
                // _wpnonce (per-order wallet_shipping nonce — refreshing
                // the page nonce won't help there), and we never retry
                // the refresh endpoint itself to keep the recursion
                // bounded.
                if ( allowRetry
                     && wrapped.status === 403
                     && ! overrideNonce
                     && action !== 'tejcart_refresh_nonce' ) {
                    return refreshPayPalNonce().then( function ( ok ) {
                        if ( ! ok ) {
                            return wrapped.body;
                        }
                        return ajaxRequestOnce( action, data, false );
                    } );
                }
                return wrapped.body;
            } )
            .catch( function ( err ) {
                clearTimer();
                // Translate the AbortError (timeout) into something the UI
                // layer can show without leaking the AbortController detail.
                if ( err && err.name === 'AbortError' ) {
                    throw new Error( 'The payment request timed out. Please try again.' );
                }
                throw err;
            } );
    }

    /**
     * Hit the shared `tejcart_refresh_nonce` endpoint to mint a fresh
     * `tejcart_paypal` nonce (and a fresh `tejcart_nonce` for the cart
     * surface, which we forward in case the cart module hasn't already
     * rotated). Resolves `true` when `ppParams.nonce` was successfully
     * rotated so the caller can replay; `false` on any failure
     * (rate-limited, network error, server rejection) so the caller
     * falls back to surfacing the original 403 message to the buyer.
     */
    function refreshPayPalNonce() {
        return new Promise( function ( resolve ) {
            var formData = new FormData();
            formData.append( 'action', 'tejcart_refresh_nonce' );

            fetch( ppParams.ajax_url || '/wp-admin/admin-ajax.php', {
                method:      'POST',
                credentials: 'same-origin',
                body:        formData,
            } )
                .then( function ( r ) { return r.ok ? r.json() : null; } )
                .then( function ( body ) {
                    if ( ! body || ! body.success || ! body.data ) {
                        resolve( false );
                        return;
                    }
                    var rotated = false;
                    if ( typeof body.data.paypal_nonce === 'string' && body.data.paypal_nonce ) {
                        ppParams.nonce = body.data.paypal_nonce;
                        rotated = true;
                    }
                    if ( typeof body.data.nonce === 'string' && body.data.nonce ) {
                        ppParams.cart_nonce = body.data.nonce;
                        // Keep the cart-JS global in lockstep so its
                        // own subsequent calls reuse the freshly minted
                        // value instead of re-refreshing.
                        if ( window.tejcart_params && typeof window.tejcart_params === 'object' ) {
                            window.tejcart_params.nonce = body.data.nonce;
                        }
                    }
                    resolve( rotated );
                } )
                .catch( function () { resolve( false ); } );
        } );
    }

    /**
     * Set a form field value safely using name attribute lookup.
     *
     * @param {string} name  Field name attribute.
     * @param {string} value Value to set.
     */
    function setField( name, value ) {
        if ( ! value || typeof value !== 'string' ) return;

        var selector;
        if ( typeof CSS !== 'undefined' && CSS.escape ) {
            selector = 'input[name="' + CSS.escape( name ) + '"], select[name="' + CSS.escape( name ) + '"]';
        } else {
            if ( ! /^[\w_-]+$/.test( name ) ) return;
            selector = 'input[name="' + name + '"], select[name="' + name + '"]';
        }
        var el = document.querySelector( selector );
        if ( el ) el.value = value;
    }

    /**
     * Request a vault setup token from the server. The caller hands the
     * returned id to the SDK's vault session, then calls savePaymentToken()
     * with the resulting setup-token id once the buyer has approved.
     */
    function createSetupToken( source ) {
        return ajaxRequest( 'tejcart_paypal_create_setup_token', { source: source || 'paypal' } )
            .then( function ( r ) {
                if ( r && r.success && r.data && r.data.id ) return r.data.id;
                throw new Error( ( r && r.data && r.data.message ) || 'Could not create setup token.' );
            } );
    }

    /**
     * Exchange an approved setup token for a permanent vault token and
     * persist it on the customer's saved methods list.
     */
    function savePaymentToken( setupTokenId ) {
        return ajaxRequest( 'tejcart_paypal_save_payment_token', { setup_token: setupTokenId } )
            .then( function ( r ) {
                if ( r && r.success ) return r.data;
                throw new Error( ( r && r.data && r.data.message ) || 'Could not save payment method.' );
            } );
    }

    window.tejcartPayPal = window.tejcartPayPal || {};
    window.tejcartPayPal.createSetupToken = createSetupToken;
    window.tejcartPayPal.savePaymentToken = savePaymentToken;

    function showLoading() {
        var overlay = document.querySelector( '.tejcart-loading-overlay' );
        if ( overlay ) overlay.classList.add( 'active' );
    }

    function hideLoading() {
        var overlay = document.querySelector( '.tejcart-loading-overlay' );
        if ( overlay ) overlay.classList.remove( 'active' );
    }

    /**
     * Build a classic top-of-page notice banner (icon + body +
     * dismiss). Used when a checkout-level notice surface is available;
     * inline fallbacks render a stripped-down version of the same markup.
     *
     * @param {string} message   Plain-text message (rendered via textContent).
     * @param {string} type      'info' | 'success' | 'warning' | 'error'.
     * @param {object} [options] { dismissible: boolean, autoHideMs: number }.
     * @returns {HTMLElement}
     */
    function buildNoticeBanner( message, type, options ) {
        type = type || 'info';
        options = options || {};

        var banner = document.createElement( 'div' );
        banner.className = 'tejcart-notice tejcart-notice--banner tejcart-notice--' + type + ' tejcart-notice-' + type;
        banner.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );

        var iconWrap = document.createElement( 'span' );
        iconWrap.className = 'tejcart-notice-icon';
        iconWrap.setAttribute( 'aria-hidden', 'true' );
        var iconPaths = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><polyline points="6 10.5 9 13.5 14 7.5"/></svg>',
            error:   '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><line x1="7" y1="7" x2="13" y2="13"/><line x1="13" y1="7" x2="7" y2="13"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.5 18.5 17h-17z"/><line x1="10" y1="8" x2="10" y2="12"/><line x1="10" y1="14.5" x2="10" y2="14.51"/></svg>',
            info:    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><line x1="10" y1="9" x2="10" y2="14"/><line x1="10" y1="6" x2="10" y2="6.01"/></svg>'
        };
        iconWrap.innerHTML = iconPaths[ type ] || iconPaths.info;

        var body = document.createElement( 'div' );
        body.className = 'tejcart-notice-body';
        var p = document.createElement( 'p' );
        p.className = 'tejcart-notice-message';
        p.textContent = message;
        body.appendChild( p );

        banner.appendChild( iconWrap );
        banner.appendChild( body );

        if ( options.dismissible !== false ) {
            var btn = document.createElement( 'button' );
            btn.type = 'button';
            btn.className = 'tejcart-notice-dismiss';
            btn.setAttribute( 'aria-label', 'Dismiss this notice' );
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="3" x2="11" y2="11"/><line x1="11" y1="3" x2="3" y2="11"/></svg>';
            btn.addEventListener( 'click', function () {
                banner.classList.add( 'is-leaving' );
                setTimeout( function () { if ( banner.parentNode ) banner.parentNode.removeChild( banner ); }, 220 );
            } );
            banner.appendChild( btn );
        }

        if ( options.autoHideMs && options.autoHideMs > 0 ) {
            setTimeout( function () {
                if ( ! banner.parentNode ) return;
                banner.classList.add( 'is-leaving' );
                setTimeout( function () { if ( banner.parentNode ) banner.parentNode.removeChild( banner ); }, 220 );
            }, options.autoHideMs );
        }

        return banner;
    }

    /**
     * Resolve or lazily create a top-of-page notice surface. Prefers the
     * dedicated checkout container (`[data-tejcart-checkout-notices]`) when
     * the checkout template renders it, and otherwise mounts a fixed-position
     * banner host at the top of the page so wallet-button errors raised from
     * the cart page, product page, or mini-cart drawer surface above the fold
     * instead of inline next to the button.
     *
     * @returns {HTMLElement}
     */
    function getTopNoticeSurface() {
        var surface = document.querySelector( '[data-tejcart-checkout-notices]' );
        if ( surface ) return surface;

        surface = document.querySelector( '[data-tejcart-page-notices]' );
        if ( surface ) return surface;

        surface = document.createElement( 'div' );
        surface.className = 'tejcart-page-notices';
        surface.setAttribute( 'data-tejcart-page-notices', '' );
        surface.setAttribute( 'role', 'region' );
        surface.setAttribute( 'aria-live', 'polite' );
        surface.setAttribute( 'aria-label', 'Notifications' );
        if ( document.body.firstChild ) {
            document.body.insertBefore( surface, document.body.firstChild );
        } else {
            document.body.appendChild( surface );
        }
        return surface;
    }

    /**
     * Render a notice into the top-of-page notice surface. Always returns a
     * banner element — the surface is created on demand when no dedicated
     * checkout container is present, so wallet-button errors never fall back
     * to inline display next to the button.
     *
     * @param {string} message
     * @param {string} type
     * @param {object} [options]
     * @returns {HTMLElement|null}
     */
    function renderTopNotice( message, type, options ) {
        var surface;
        try {
            surface = getTopNoticeSurface();
        } catch ( e ) {
            return null;
        }
        if ( ! surface ) return null;

        var existing = surface.querySelectorAll( '.tejcart-notice--banner' );
        for ( var i = 0; i < existing.length; i++ ) {
            if ( existing[ i ].textContent.trim() === String( message ).trim() ) {
                existing[ i ].parentNode.removeChild( existing[ i ] );
            }
        }

        var banner = buildNoticeBanner( message, type, options );
        surface.appendChild( banner );

        try {
            var rect = surface.getBoundingClientRect();
            if ( rect.top < 0 || rect.top > ( window.innerHeight * 0.6 ) ) {
                surface.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            }
        } catch ( e ) {  }

        return banner;
    }

    /**
     * Show a global error in every PayPal-family payment container the
     * page might be rendering. Includes the side-cart drawer slots and
     * the cart / product-page express buttons so a capture failure
     * triggered from the drawer never silently disappears — that was
     * the missing surface that left buyers stranded after a shipping
     * rejection on stores with no zones configured.
     * Uses textContent to prevent XSS.
     *
     * @param {string} message Error message.
     */
    function showGlobalError( message ) {
        if ( renderTopNotice( message, 'error' ) ) {
            return;
        }

        var containers = document.querySelectorAll(
            [
                '#tejcart-paypal-button-container',
                '#tejcart-card-fields-container',
                '#tejcart-googlepay-container',
                '#tejcart-applepay-container',
                '#tejcart-fastlane-container',
                '#tejcart-drawer-express-paypal',
                '#tejcart-drawer-express-venmo',
                '#tejcart-drawer-express-googlepay',
                '#tejcart-drawer-express-applepay',
                '#tejcart-cart-paypal-btn',
                '#tejcart-cart-venmo-btn',
                '#tejcart-cart-googlepay-btn',
                '#tejcart-cart-applepay-btn',
                '#tejcart-express-paypal',
                '#tejcart-express-venmo',
                '#tejcart-express-googlepay',
                '#tejcart-express-applepay'
            ].join( ', ' )
        );
        containers.forEach( function ( el ) {
            var p = document.createElement( 'p' );
            p.className = 'tejcart-notice tejcart-notice-error';
            p.textContent = message;
            el.innerHTML = '';
            el.appendChild( p );
        } );
    }

    /**
     * Show an inline error near a specific payment container.
     * Uses textContent to prevent XSS.
     *
     * @param {HTMLElement} container Payment container.
     * @param {string}      message   Error message.
     */
    function showError( container, message ) {
        if ( renderTopNotice( message, 'error' ) ) {
            return;
        }

        // When the container is gone (DOM was re-rendered between
        // create and approve / cancel) fall back to the global notice surface
        // so the buyer still sees the failure instead of the message vanishing.
        if ( ! container || ! container.parentElement || ( typeof container.isConnected === 'boolean' && ! container.isConnected ) ) {
            showGlobalError( message );
            return;
        }
        var existing = container.parentElement.querySelector( '.tejcart-paypal-error' );
        if ( existing ) existing.remove();

        var div = document.createElement( 'div' );
        div.className = 'tejcart-notice tejcart-notice-error tejcart-paypal-error';
        div.textContent = message;
        container.parentElement.insertBefore( div, container );
    }

    /**
     * Card Fields-specific error: prefer the dedicated card-error slot
     * when present so 3DS / decline messages land where the merchant
     * styled them, falling back to the generic showError.
     */
    function showCardError( container, message ) {
        // Defend against the container being detached between
        // session creation and approve/error. Fall through to the global
        // notice surface rather than silently no-op'ing.
        if ( ! container || ( typeof container.isConnected === 'boolean' && ! container.isConnected ) ) {
            showGlobalError( message );
            return;
        }
        var slot = document.querySelector( '#tejcart-card-errors, .tejcart-card-errors' );
        if ( slot ) {
            slot.textContent = message;
            return;
        }
        showError( container, message );
    }

    /**
     * Surface the gateway name + a short correlation token in
     * the user-visible error so support can map a complaint to a log
     * line. PayPal returns a `paypal-debug-id` on most errors; Stripe
     * returns `requestId`. We accept either and trim to 8 chars.
     */
    function formatGatewayError( err, gateway ) {
        var msg = ( err && typeof err.message === 'string' && err.message ) || 'Payment could not be completed. Please try again.';
        var correlationId = '';
        if ( err && typeof err === 'object' ) {
            correlationId = err.debug_id || err.debugId || err.correlation_id || err.correlationId || err.requestId || '';
        }
        if ( correlationId ) {
            correlationId = String( correlationId ).slice( -8 );
            msg += ' [' + ( gateway || 'Gateway' ) + ' #' + correlationId + ']';
        }
        return msg;
    }

    function showNotice( container, message, type ) {
        type = type || 'info';

        // Prefer the dedicated checkout-level notice surface so cancellation /
        // info messages render as a prominent top-of-page banner instead of
        // a small inline blob next to an express-checkout button.
        if ( renderTopNotice( message, type, { autoHideMs: type === 'info' ? 7000 : 0 } ) ) {
            return;
        }

        if ( ! container || ! container.parentElement ) return;
        var div = document.createElement( 'div' );
        div.className = 'tejcart-notice tejcart-notice-' + type;
        div.textContent = message;
        container.parentElement.insertBefore( div, container );
        setTimeout( function () { div.remove(); }, 5000 );
    }
} )();
