/**
 * TejCart Cart — front-end JavaScript.
 *
 * Powers the customer-facing cart surfaces:
 *
 *   • Add to cart button (animated confirmed state, no page reload).
 *   • Cart page quantity stepper (debounced, accessible).
 *   • Item remove with undo toast (5-second window).
 *   • Side-cart drawer (focus trap, ESC to close, focus return).
 *   • Mini-cart trigger.
 *   • aria-live region for cart additions.
 *   • Coupon apply / remove inline feedback (no window.alert).
 *
 * Vanilla ES6, no jQuery, no global pollution. All listeners are
 * delegated from document so dynamically inserted DOM works.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    var params = window.tejcart_params || {};

    var QTY_DEBOUNCE_MS    = 400;
    var UNDO_WINDOW_MS     = 5000;
    var CONFIRMED_RESET_MS = 2000;
    var REQUEST_TIMEOUT_MS = 15000;
    var FOCUSABLE_SELECTOR = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join( ',' );

    var qtyTimers      = {};
    var pendingUndo    = null;
    var lastFocusedEl  = null;
    var drawerKeyTrap  = null;

    function isSafeUrl( url ) {
        if ( ! url ) { return false; }
        if ( url.charAt( 0 ) === '/' && url.charAt( 1 ) !== '/' ) { return true; }
        try {
            var parsed = new URL( url );
            return parsed.origin === window.location.origin;
        } catch ( e ) {
            return false;
        }
    }

    var PRICE_ALLOWED_TAGS = { SPAN: 1, BDI: 1, SUP: 1, SUB: 1, SMALL: 1 };
    var DRAWER_ALLOWED_TAGS = {
        DIV: 1, SECTION: 1, ARTICLE: 1, ASIDE: 1, HEADER: 1, FOOTER: 1, NAV: 1,
        UL: 1, OL: 1, LI: 1, P: 1, SPAN: 1, BDI: 1, SMALL: 1, STRONG: 1, EM: 1,
        H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1,
        DL: 1, DT: 1, DD: 1,
        A: 1, BUTTON: 1, FORM: 1, INPUT: 1, LABEL: 1, SELECT: 1, OPTION: 1, TEXTAREA: 1,
        IMG: 1, PICTURE: 1, SOURCE: 1, FIGURE: 1, FIGCAPTION: 1,
        SVG: 1, PATH: 1, CIRCLE: 1, RECT: 1, LINE: 1, POLYLINE: 1, POLYGON: 1, G: 1,
        BR: 1, HR: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TD: 1, TH: 1
    };
    var ATTR_DENY_PREFIX = /^on/i;
    var ATTR_HREF_LIKE   = /^(href|src|action|formaction|xlink:href)$/i;

    function isSafeAttrValue( name, value ) {
        if ( ATTR_DENY_PREFIX.test( name ) ) { return false; }
        if ( ATTR_HREF_LIKE.test( name ) ) {
            var v = String( value || '' ).trim().toLowerCase();

            if ( v.indexOf( 'javascript:' ) === 0 ) { return false; }
            if ( v.indexOf( 'vbscript:' )   === 0 ) { return false; }

            if ( v.indexOf( 'data:' ) === 0 ) { return false; }
        }
        return true;
    }

    function sanitiseFragment( html, allowedTags ) {
        if ( typeof html !== 'string' || html.length === 0 ) { return ''; }
        var template = document.createElement( 'template' );
        template.innerHTML = html;

        var walker = document.createTreeWalker( template.content, NodeFilter.SHOW_ELEMENT, null );
        var toRemove = [];
        var node;
        while ( ( node = walker.nextNode() ) ) {
            if ( ! Object.prototype.hasOwnProperty.call( allowedTags, node.tagName ) ) {
                toRemove.push( node );
                continue;
            }

            for ( var i = node.attributes.length - 1; i >= 0; i-- ) {
                var attr = node.attributes[ i ];
                if ( ! isSafeAttrValue( attr.name, attr.value ) ) {
                    node.removeAttribute( attr.name );
                }
            }
        }

        toRemove.forEach( function ( el ) {
            var parent = el.parentNode;
            if ( ! parent ) { return; }
            while ( el.firstChild ) {
                if ( el.firstChild.nodeType === Node.TEXT_NODE ) {
                    parent.insertBefore( el.firstChild, el );
                } else {
                    el.removeChild( el.firstChild );
                }
            }
            parent.removeChild( el );
        } );

        var div = document.createElement( 'div' );
        div.appendChild( template.content );
        return div.innerHTML;
    }

    function sanitisePrice( html )  { return sanitiseFragment( html, PRICE_ALLOWED_TAGS ); }
    function sanitiseDrawer( html ) { return sanitiseFragment( html, DRAWER_ALLOWED_TAGS ); }

    window.TejCartSanitiser = {
        sanitisePrice:  sanitisePrice,
        sanitiseDrawer: sanitiseDrawer,
        isSafeUrl:      isSafeUrl
    };

    /**
     * Build the empty-cart state into the given section using DOM nodes
     * rather than innerHTML, so localised strings from window.tejcart_params
     * can never escape into markup even if a translation file is hostile.
     *
     * @param {HTMLElement} section
     */
    function renderEmptyCartInto( section ) {
        while ( section.firstChild ) { section.removeChild( section.firstChild ); }

        var wrap = document.createElement( 'div' );
        wrap.className = 'tejcart-cart-empty';

        var illus = document.createElement( 'div' );
        illus.className = 'tejcart-cart-empty-illustration';
        illus.setAttribute( 'aria-hidden', 'true' );

        illus.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<circle cx="36" cy="78" r="5"/>' +
            '<circle cx="70" cy="78" r="5"/>' +
            '<path d="M10 14h10l8 46a6 6 0 0 0 6 5h34a6 6 0 0 0 6-5l5-28H28"/>' +
            '<path d="M44 34l6 6 14-14" opacity="0.4"/>' +
        '</svg>';

        var title = document.createElement( 'h1' );
        title.className = 'tejcart-cart-empty-title';
        title.textContent = params.i18n_empty_cart || 'Your cart is empty';

        var desc = document.createElement( 'p' );
        desc.className = 'tejcart-cart-empty-desc';
        desc.textContent = params.i18n_empty_cart_desc
            || "Looks like you haven't added anything yet. Browse the shop to find something you'll love.";

        var cta = document.createElement( 'a' );
        cta.className = 'tejcart-button tejcart-button--lg tejcart-continue-shopping-btn';

        var shopUrl = params.shop_url || '/shop/';
        cta.href = isSafeUrl( shopUrl ) ? shopUrl : '/shop/';
        cta.textContent = params.i18n_start_shopping || 'Start shopping';

        wrap.appendChild( illus );
        wrap.appendChild( title );
        wrap.appendChild( desc );
        wrap.appendChild( cta );
        section.appendChild( wrap );
    }

    function debounce( key, fn, delay ) {
        if ( qtyTimers[ key ] ) {
            clearTimeout( qtyTimers[ key ] );
        }
        qtyTimers[ key ] = setTimeout( function () {
            delete qtyTimers[ key ];
            fn();
        }, delay );
    }

    function ajax( action, data ) {
        return ajaxOnce( action, data, true );
    }

    /**
     * Once-retrying AJAX wrapper. The drawer + cart pages
     * cache the nonce that was minted when the page first rendered.
     * After a login or logout the WordPress cookie changes and that
     * nonce becomes stale; the next mutation 403s with a "security
     * check failed" payload. We detect that response, hit the
     * dedicated `tejcart_refresh_nonce` endpoint to rotate the nonce
     * in place, and replay the original request once. The
     * allowRetry flag guards against retry loops if the refresh
     * itself fails.
     */
    function ajaxOnce( action, data, allowRetry ) {
        return new Promise( function ( resolve ) {
            var nonce = params.nonce;
            if ( ! nonce ) {
                resolve( {
                    success: false,
                    data: { message: 'Security token missing. Please reload the page.' }
                } );
                return;
            }

            var formData = new FormData();
            formData.append( 'action', action );
            formData.append( '_wpnonce', nonce );

            if ( data && typeof data === 'object' ) {
                Object.keys( data ).forEach( function ( key ) {
                    formData.append( key, data[ key ] );
                } );
            }

            // Attach a bot-gate token on the surfaces the optional captcha
            // module gates (add-to-cart velocity + coupon-apply). The
            // server only enforces it past a per-IP threshold, but sending
            // it unconditionally keeps the flow seamless once enforcement
            // engages. When the module is disabled `window.tejcartCaptcha`
            // is absent and this resolves immediately with no token.
            var botAction = action === 'tejcart_apply_coupon'
                ? 'coupon_apply'
                : ( action === 'tejcart_add_to_cart' ? 'cart_add' : '' );
            var prep = ( botAction && window.tejcartCaptcha && window.tejcartCaptcha.isActive() )
                ? window.tejcartCaptcha.appendTo( formData, botAction )
                : Promise.resolve();

            prep.then( function () {
            var controller = new AbortController();
            var timeout    = setTimeout( function () { controller.abort(); }, REQUEST_TIMEOUT_MS );

            fetch( params.ajax_url || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                signal: controller.signal
            } )
                .then( function ( response ) {
                    clearTimeout( timeout );

                    return response.json().then(
                        function ( body ) { return { ok: response.ok, status: response.status, body: body }; },
                        function () { return { ok: response.ok, status: response.status, body: null }; }
                    );
                } )
                .then( function ( wrapped ) {
                    if ( allowRetry && wrapped.status === 403 && action !== 'tejcart_refresh_nonce' ) {
                        refreshTejCartNonce().then( function ( ok ) {
                            if ( ! ok ) {
                                resolve( wrapped.body || {
                                    success: false,
                                    data: { message: 'Security check failed. Please reload the page.' }
                                } );
                                return;
                            }
                            ajaxOnce( action, data, false ).then( resolve );
                        } );
                        return;
                    }

                    if ( wrapped.body && typeof wrapped.body === 'object' ) {
                        resolve( wrapped.body );
                        return;
                    }
                    resolve( {
                        success: false,
                        data: {
                            message: wrapped.ok
                                ? ''
                                : 'Server error: ' + wrapped.status
                        }
                    } );
                } )
                .catch( function ( error ) {
                    clearTimeout( timeout );
                    var message = error && error.name === 'AbortError'
                        ? 'The request timed out. Please try again.'
                        : 'A network error occurred. Please try again.';
                    resolve( { success: false, data: { message: message } } );
                } );
            } );
        } );
    }

    /**
     * Rotate `params.nonce` against `tejcart_refresh_nonce`. Resolves
     * `true` when the rotation succeeded so the caller can replay the
     * original request, `false` otherwise (rate-limited, network
     * error, server rejection — at which point we fall through to the
     * existing 403 handling).
     */
    function refreshTejCartNonce() {
        return new Promise( function ( resolve ) {
            var formData = new FormData();
            formData.append( 'action', 'tejcart_refresh_nonce' );

            fetch( params.ajax_url || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            } )
                .then( function ( r ) { return r.ok ? r.json() : null; } )
                .then( function ( body ) {
                    if ( body && body.success && body.data && typeof body.data.nonce === 'string' && body.data.nonce ) {
                        params.nonce = body.data.nonce;
                        // Propagate the fresh PayPal nonce so the PayPal
                        // module's cached `ppParams.nonce` rotates alongside
                        // the cart nonce. Both went stale on the same cookie
                        // change, and the PayPal `auth-changed` listener
                        // reads from `window.tejcart_paypal_params` so a
                        // direct write here is what the listener picks up.
                        if ( typeof body.data.paypal_nonce === 'string' && body.data.paypal_nonce
                             && window.tejcart_paypal_params && typeof window.tejcart_paypal_params === 'object' ) {
                            window.tejcart_paypal_params.nonce = body.data.paypal_nonce;
                        }
                        document.dispatchEvent( new CustomEvent( 'tejcart:auth-changed', {
                            detail: { logged_in: !! body.data.logged_in, user_id: body.data.current_user_id || 0 }
                        } ) );
                        resolve( true );
                        return;
                    }
                    resolve( false );
                } )
                .catch( function () { resolve( false ); } );
        } );
    }

    // Audit #39 / 09 F-008 — translated string lookup with English
    // fallbacks. Reads from `params.i18n_cart.<key>` when available
    // (Frontend::get_script_params() ships the map), else returns the
    // hardcoded English so block-theme / fragment-loaded contexts that
    // missed the localize still announce something.
    function tcI18n( key, fallback ) {
        try {
            if ( typeof params === 'object' && params && params.i18n_cart && typeof params.i18n_cart[ key ] === 'string' ) {
                return params.i18n_cart[ key ];
            }
        } catch ( e ) { /* fall through */ }
        return fallback;
    }
    function tcI18nFmt( key, fallback, name ) {
        var raw = tcI18n( key, fallback );
        return raw.replace( '%s', String( name ) );
    }

    function announce( message ) {
        var region = document.querySelector( '[data-tejcart-live-region]' );
        if ( ! region ) { return; }

        region.textContent = '';

        window.requestAnimationFrame( function () {
            region.textContent = message;
        } );
    }

    function getToastRegion() {
        var region = document.querySelector( '[data-tejcart-toast-region]' );
        if ( region ) { return region; }

        region = document.createElement( 'div' );
        region.className = 'tejcart-toast-region';
        region.setAttribute( 'data-tejcart-toast-region', '' );
        region.setAttribute( 'role', 'region' );
        region.setAttribute( 'aria-label', 'Notifications' );
        document.body.appendChild( region );
        return region;
    }

    /**
     * Show a toast. Returns the toast element so the caller can attach
     * listeners (e.g. an Undo action).
     *
     * @param {string} message
     * @param {Object} [options]
     * @param {string} [options.type='info'] — 'success' | 'error' | 'info'
     * @param {number} [options.duration=4000] — auto-dismiss after N ms; 0 = sticky.
     * @param {string} [options.actionLabel] — label for an action button.
     * @param {Function} [options.onAction] — invoked when the action button is clicked.
     */
    function showToast( message, options ) {
        options = options || {};
        var region = getToastRegion();
        var toast  = document.createElement( 'div' );
        toast.className = 'tejcart-toast tejcart-toast--' + ( options.type || 'info' );
        toast.setAttribute( 'role', options.type === 'error' ? 'alert' : 'status' );

        var icon = document.createElement( 'span' );
        icon.className = 'tejcart-toast-icon';
        icon.setAttribute( 'aria-hidden', 'true' );
        icon.innerHTML = options.type === 'error'
            ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="14" r=".75" fill="currentColor"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M6 10l3 3 5-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        var body = document.createElement( 'span' );
        body.className = 'tejcart-toast-body';
        body.textContent = message;

        toast.appendChild( icon );
        toast.appendChild( body );

        var actionBtn = null;
        if ( options.actionLabel && typeof options.onAction === 'function' ) {
            actionBtn = document.createElement( 'button' );
            actionBtn.type = 'button';
            actionBtn.className = 'tejcart-toast-action';
            actionBtn.textContent = options.actionLabel;
            actionBtn.addEventListener( 'click', function () {
                options.onAction();
                dismiss();
            } );
            toast.appendChild( actionBtn );
        }

        region.appendChild( toast );

        var dismissed = false;
        function dismiss() {
            if ( dismissed ) { return; }
            dismissed = true;
            toast.classList.add( 'is-leaving' );
            setTimeout( function () {
                if ( toast.parentNode ) { toast.parentNode.removeChild( toast ); }
            }, 250 );
        }

        var duration = typeof options.duration === 'number' ? options.duration : 4000;
        if ( duration > 0 ) {
            var remaining = duration;
            var startedAt = Date.now();
            var timerId   = setTimeout( dismiss, remaining );

            var pauseTimer = function () {
                if ( timerId === null ) { return; }
                clearTimeout( timerId );
                remaining = Math.max( 0, remaining - ( Date.now() - startedAt ) );
                timerId = null;
            };
            var resumeTimer = function () {
                if ( timerId !== null ) { return; }
                startedAt = Date.now();

                timerId = setTimeout( dismiss, Math.max( remaining, 1500 ) );
            };

            toast.addEventListener( 'focusin',  pauseTimer );
            toast.addEventListener( 'focusout', function ( ev ) {
                if ( ! toast.contains( ev.relatedTarget ) ) { resumeTimer(); }
            } );
            toast.addEventListener( 'mouseenter', pauseTimer );
            toast.addEventListener( 'mouseleave', resumeTimer );
        }

        return { element: toast, dismiss: dismiss };
    }

    function highlightJustAdded( productId ) {
        if ( ! productId ) { return; }
        var selector = '.tejcart-cart-drawer-item[data-product-id="' + String( productId ).replace( /"/g, '' ) + '"]';
        var rows     = document.querySelectorAll( selector );
        rows.forEach( function ( row ) {
            row.classList.remove( 'is-just-added' );

            void row.offsetWidth;
            row.classList.add( 'is-just-added' );
        } );
    }

    /**
     * F-H9 / #932: cross-tab cart-state sync. Bump a shared
     * localStorage key after every successful cart mutation so
     * `tejcart-storefront-hydrate.js` listening in other tabs picks
     * up the change via the `storage` event and re-fetches state.
     * localStorage (not session) is shared across same-origin tabs.
     * Silent on any failure (private mode, disabled storage).
     */
    function broadcastCartMutation() {
        try {
            window.localStorage.setItem( 'tejcart_cart_bump_v1', String( Date.now() ) );
        } catch ( _e ) { /* ignore */ }
    }

    function replaceCartDrawer( html ) {
        // Broadcast first so other tabs are notified even when the
        // drawer html is missing (we still mutated cart state).
        broadcastCartMutation();
        if ( typeof html !== 'string' || html.length === 0 ) { return; }

        var existingDrawer  = document.querySelector( '.tejcart-cart-drawer' );
        var existingOverlay = document.querySelector( '.tejcart-cart-drawer-overlay' );
        var wasOpen         = existingDrawer && existingDrawer.classList.contains( 'is-open' );

        var focusWasInDrawer = wasOpen && existingDrawer && existingDrawer.contains( document.activeElement );

        var wrapper = document.createElement( 'div' );

        wrapper.innerHTML = sanitiseDrawer( html );

        var newDrawer  = wrapper.querySelector( '.tejcart-cart-drawer' );
        var newOverlay = wrapper.querySelector( '.tejcart-cart-drawer-overlay' );

        if ( ! newDrawer ) { return; }

        if ( existingDrawer && existingDrawer.parentNode ) {
            existingDrawer.parentNode.replaceChild( newDrawer, existingDrawer );
        } else {
            document.body.appendChild( newDrawer );
        }

        if ( newOverlay ) {
            if ( existingOverlay && existingOverlay.parentNode ) {
                existingOverlay.parentNode.replaceChild( newOverlay, existingOverlay );
            } else {
                document.body.appendChild( newOverlay );
            }
        }

        if ( wasOpen ) {
            newDrawer.classList.add( 'is-open' );
            newDrawer.classList.add( 'open' );
            newDrawer.setAttribute( 'aria-hidden', 'false' );
            if ( newOverlay ) {
                newOverlay.classList.add( 'is-active' );
                newOverlay.classList.add( 'active' );
            }
            if ( focusWasInDrawer ) {
                var newCloseBtn = newDrawer.querySelector( '.tejcart-cart-drawer-close' );
                if ( newCloseBtn ) { try { newCloseBtn.focus(); } catch ( err ) {  } }
            }
        }

        try {
            document.dispatchEvent( new CustomEvent( 'tejcart:drawer-updated', {
                detail: { drawer: newDrawer }
            } ) );
        } catch ( e ) {
            var evt = document.createEvent( 'Event' );
            evt.initEvent( 'tejcart:drawer-updated', true, true );
            document.dispatchEvent( evt );
        }
    }

    function updateMiniCart( count, totalHtml ) {
        var badges = document.querySelectorAll( '.tejcart-mini-cart-count' );
        badges.forEach( function ( badge ) {
            badge.textContent = String( count );
            badge.style.display = count > 0 ? 'flex' : 'none';
        } );

        var totals = document.querySelectorAll( '.tejcart-mini-cart-total' );
        if ( totals.length === 0 ) { return; }
        var hasTotal = typeof totalHtml === 'string' && totalHtml.length > 0 && count > 0;
        var safe     = hasTotal ? sanitisePrice( totalHtml ) : '';
        totals.forEach( function ( total ) {
            if ( hasTotal ) {
                total.innerHTML     = safe;
                total.style.display = '';
            } else {
                total.innerHTML     = '';
                total.style.display = 'none';
            }
        } );
    }

    /**
     * Show one toast per coupon that the server silently dropped during
     * recalculation (expired promo, exhausted usage limit, etc). The
     * server returns each entry under data.removed_coupons; this is a
     * no-op when the cart didn't drop anything.
     */
    function announceRemovedCoupons( data ) {
        if ( ! data || ! Array.isArray( data.removed_coupons ) ) { return; }
        data.removed_coupons.forEach( function ( entry ) {
            if ( ! entry || ! entry.message ) { return; }
            showToast( entry.message, { type: 'error', duration: 6000 } );
            announce( entry.message );
        } );
    }

    /**
     * Surface server-queued one-shot notices (e.g. "sold out under
     * concurrent shoppers") that came back in the cart_state payload.
     * Each notice is shown once and not repeated on the next response —
     * the server clears its queue when it builds the payload.
     */
    function showServerNotices( data ) {
        if ( ! data || ! Array.isArray( data.notices ) ) { return; }
        data.notices.forEach( function ( entry ) {
            if ( ! entry || ! entry.message ) { return; }
            showToast( entry.message, {
                type: entry.type || 'info',
                duration: entry.type === 'error' ? 6000 : 5000
            } );
            announce( entry.message );
        } );
    }

    function updateCartTotals( data ) {
        if ( ! data ) { return; }
        announceRemovedCoupons( data );
        showServerNotices( data );

        var map = {
            '.tejcart-subtotal-value': data.subtotal_html,
            '.tejcart-shipping-value': data.shipping_html,
            '.tejcart-tax-value':      data.tax_html,
            '.tejcart-discount-value': data.discount_html,
            '.tejcart-total-value':    data.total_html
        };

        Object.keys( map ).forEach( function ( selector ) {
            if ( typeof map[ selector ] !== 'string' ) { return; }
            var safe = sanitisePrice( map[ selector ] );
            document.querySelectorAll( selector ).forEach( function ( el ) {
                el.innerHTML = safe;
            } );
        } );

        toggleTotalsRow(
            '.tejcart-cart-totals-discount, .tejcart-cart-drawer-summary-row--discount',
            !! data.has_discount
        );
        toggleTotalsRow( '.tejcart-cart-totals-tax', !! data.has_tax );

        var savingsLine = document.querySelector( '[data-tejcart-savings-line]' );
        if ( savingsLine ) {
            if ( data.has_discount && typeof data.savings_html === 'string' ) {
                var savingsAmount = savingsLine.querySelector( '.tejcart-cart-savings-amount' );
                if ( savingsAmount ) {
                    savingsAmount.innerHTML = sanitisePrice( data.savings_html );
                }
                savingsLine.hidden = false;
            } else {
                savingsLine.hidden = true;
            }
        }

        if ( data.free_shipping && typeof data.free_shipping === 'object' && data.free_shipping.enabled ) {
            updateFreeShippingBar( data.free_shipping );
        }

        if ( data.line_totals && typeof data.line_totals === 'object' ) {
            Object.keys( data.line_totals ).forEach( function ( key ) {
                if ( ! /^[a-f0-9]{32,128}$/.test( key ) ) { return; }
                var safeLine = sanitisePrice( data.line_totals[ key ] );
                var rows = document.querySelectorAll(
                    '.tejcart-cart-item[data-cart-item-key="' + key + '"] .tejcart-cart-item-line-total'
                );
                rows.forEach( function ( el ) {
                    el.innerHTML = safeLine;
                } );
            } );
        }

        try {
            var rawTotal = ( typeof data.cart_total === 'number' )
                ? data.cart_total
                : parseFloat( data.cart_total );
            if ( ! isNaN( rawTotal ) ) {
                document.dispatchEvent( new CustomEvent( 'tejcart:totals-updated', {
                    detail: {
                        total:    rawTotal,
                        subtotal: ( typeof data.cart_subtotal === 'number' )
                            ? data.cart_subtotal
                            : parseFloat( data.cart_subtotal ),
                        currency: typeof data.cart_currency === 'string'
                            ? data.cart_currency
                            : ''
                    }
                } ) );
            }
        } catch ( e ) {
            try {
                var evt = document.createEvent( 'Event' );
                evt.initEvent( 'tejcart:totals-updated', true, true );
                document.dispatchEvent( evt );
            } catch ( e2 ) {  }
        }
    }

    function updateFreeShippingBar( payload ) {
        var bars = document.querySelectorAll( '[data-tejcart-free-ship-bar]' );
        if ( ! bars.length ) { return; }
        var unlocked = !! payload.unlocked;

        var msgAllowed = {
            STRONG: 1, SPAN: 1, BDI: 1, SUP: 1, SUB: 1, SMALL: 1
        };
        var safeMsg = sanitiseFragment( String( payload.message_html || '' ), msgAllowed );
        var pct     = Math.max( 0, Math.min( 100, parseFloat( payload.percent ) || 0 ) );

        bars.forEach( function ( bar ) {
            bar.classList.toggle( 'is-unlocked', unlocked );
            var msgEl  = bar.querySelector( '[data-tejcart-free-ship-msg]' );
            var fillEl = bar.querySelector( '[data-tejcart-free-ship-fill]' );
            if ( msgEl )  { msgEl.innerHTML = safeMsg; }
            if ( fillEl ) { fillEl.style.width = pct + '%'; }
        } );
    }

    function toggleTotalsRow( selector, show ) {
        document.querySelectorAll( selector ).forEach( function ( row ) {
            row.hidden = ! show;
        } );
    }

    function getVariationScope( el ) {
        return el.closest( '.tejcart-single-product' ) || document;
    }

    function parseVariationMap( container ) {
        if ( container._tejcartVariations ) { return container._tejcartVariations; }
        var raw = container.getAttribute( 'data-variations' ) || '[]';
        try {
            var parsed = JSON.parse( raw );
            container._tejcartVariations = Array.isArray( parsed ) ? parsed : [];
        } catch ( e ) {
            container._tejcartVariations = [];
        }
        return container._tejcartVariations;
    }

    function formatHintTpl( tpl, values ) {
        var out = tpl.replace( /%(\d+)\$d/g, function ( _, i ) {
            var idx = parseInt( i, 10 ) - 1;
            return values[ idx ] !== undefined ? String( values[ idx ] ) : '';
        } );
        var next = 0;
        out = out.replace( /%d/g, function () {
            return values[ next++ ] !== undefined ? String( values[ next - 1 ] ) : '';
        } );
        return out;
    }

    function updateQtyHint( scope, newMin, newMax ) {
        var hint = scope.querySelector( '[data-tejcart-qty-hint]' );
        if ( ! hint ) { return; }
        var text = '';
        if ( newMin > 1 && newMax !== null ) {
            text = formatHintTpl( hint.getAttribute( 'data-tpl-min-max' ) || '', [ newMin, newMax ] );
        } else if ( newMin > 1 ) {
            text = formatHintTpl( hint.getAttribute( 'data-tpl-min' ) || '', [ newMin ] );
        } else if ( newMax !== null && newMax > 1 ) {
            text = formatHintTpl( hint.getAttribute( 'data-tpl-max' ) || '', [ newMax ] );
        }
        hint.textContent = text;
        if ( text ) { hint.removeAttribute( 'hidden' ); } else { hint.setAttribute( 'hidden', '' ); }
    }

    function swapGalleryForVariation( scope, match ) {
        var mainImage = scope.querySelector( '.tejcart-gallery-main-image' );
        if ( ! mainImage ) { return; }

        if ( ! mainImage._tejcartSrcsetSaved ) {
            mainImage._tejcartSrcsetSaved = true;
            mainImage._tejcartDefaultSrcset = mainImage.getAttribute( 'srcset' ) || '';
            mainImage._tejcartDefaultSizes  = mainImage.getAttribute( 'sizes' ) || '';
        }

        if ( match && match.image_id ) {
            var thumb = scope.querySelector(
                '.tejcart-gallery-thumb[data-image-id="' + String( match.image_id ).replace( /"/g, '' ) + '"]'
            );
            if ( thumb ) {
                scope.querySelectorAll( '.tejcart-gallery-thumb' ).forEach( function ( t ) {
                    t.classList.remove( 'active' );
                } );
                thumb.classList.add( 'active' );
            }

            if ( match.image_large ) {
                mainImage.removeAttribute( 'srcset' );
                mainImage.removeAttribute( 'sizes' );
                mainImage.src = match.image_large;
            }
            if ( match.image_full ) {
                mainImage.setAttribute( 'data-full', match.image_full );
            }
        } else {
            var defaultSrc  = mainImage.getAttribute( 'data-default-src' );
            var defaultFull = mainImage.getAttribute( 'data-default-full' );
            if ( defaultSrc && mainImage.src !== defaultSrc ) {
                mainImage.src = defaultSrc;
            }
            if ( defaultFull ) {
                mainImage.setAttribute( 'data-full', defaultFull );
            }

            if ( mainImage._tejcartDefaultSrcset ) {
                mainImage.setAttribute( 'srcset', mainImage._tejcartDefaultSrcset );
            }
            if ( mainImage._tejcartDefaultSizes ) {
                mainImage.setAttribute( 'sizes', mainImage._tejcartDefaultSizes );
            }

            var firstThumb = scope.querySelector( '.tejcart-gallery-thumb' );
            if ( firstThumb ) {
                scope.querySelectorAll( '.tejcart-gallery-thumb' ).forEach( function ( t ) {
                    t.classList.remove( 'active' );
                } );
                firstThumb.classList.add( 'active' );
            }
        }
    }

    function findMatchingVariation( variations, selected ) {
        for ( var i = 0; i < variations.length; i++ ) {
            var v     = variations[ i ];
            var attrs = v && v.attributes ? v.attributes : {};
            var match = true;
            for ( var key in selected ) {
                if ( ! Object.prototype.hasOwnProperty.call( selected, key ) ) { continue; }

                var want = String( selected[ key ] ).trim();
                var have = attrs[ key ] !== undefined ? String( attrs[ key ] ).trim() : '';
                if ( want !== have ) { match = false; break; }
            }
            if ( match ) { return v; }
        }
        return null;
    }

    function syncVariationState( container ) {
        var selects = container.querySelectorAll( '.tejcart-variation-select' );
        var selected = {};
        var allPicked = true;
        selects.forEach( function ( sel ) {
            var name = sel.getAttribute( 'data-attribute-name' ) || '';
            var val  = sel.value || '';
            if ( ! val ) { allPicked = false; }
            if ( name ) { selected[ name ] = val; }
        } );

        var variations = parseVariationMap( container );
        var match      = allPicked ? findMatchingVariation( variations, selected ) : null;

        var scope      = getVariationScope( container );
        var buttons    = scope.querySelectorAll( '.tejcart-add-to-cart-btn[data-variation-required="1"]' );
        var errorEl    = container.querySelector( '[data-tejcart-variation-error]' );

        if ( errorEl ) {
            errorEl.hidden      = true;
            errorEl.textContent = '';
        }

        var smartButtonsEnabled = !! ( match && match.is_purchasable && match.in_stock );

        buttons.forEach( function ( btn ) {
            if ( match && match.is_purchasable ) {
                btn.setAttribute( 'data-variation-id', String( match.id ) );
                btn.classList.remove( 'is-disabled' );
                btn.setAttribute( 'aria-disabled', 'false' );
            } else {
                btn.removeAttribute( 'data-variation-id' );

                btn.classList.add( 'is-disabled' );
                btn.setAttribute( 'aria-disabled', 'true' );
            }
        } );

        scope.querySelectorAll(
            '.tejcart-product-smart-buttons, .tejcart-paylater-product'
        ).forEach( function ( el ) {
            el.hidden = ! smartButtonsEnabled;
        } );

        swapGalleryForVariation( scope, match );

        var qtyInput = scope.querySelector( '.tejcart-single-product-qty .tejcart-qty-input' );
        if ( qtyInput && match ) {
            var newMin = Math.max( 1, parseInt( match.min_purchase_quantity, 10 ) || 1 );
            var newMax = null;

            if ( match.sold_individually ) {
                newMax = 1;
            }
            if ( match.max_purchase_quantity && parseInt( match.max_purchase_quantity, 10 ) > 0 ) {
                var pmax = parseInt( match.max_purchase_quantity, 10 );
                if ( newMax === null || pmax < newMax ) { newMax = pmax; }
            }

            var parentMax = parseInt( container.getAttribute( 'data-parent-max-purchase-qty' ), 10 );
            if ( ! isNaN( parentMax ) && parentMax > 0 && ( newMax === null || parentMax < newMax ) ) {
                newMax = parentMax;
            }
            if ( match.manage_stock && ! match.backorders_allowed ) {
                var stk = parseInt( match.stock_quantity, 10 );
                if ( ! isNaN( stk ) && stk > 0 && ( newMax === null || stk < newMax ) ) {
                    newMax = stk;
                }
            }

            qtyInput.min = String( newMin );
            if ( newMax !== null ) {
                qtyInput.max      = String( newMax );
                qtyInput.readOnly = ( newMax === 1 );
            } else {
                qtyInput.removeAttribute( 'max' );
                qtyInput.readOnly = false;
            }

            var cur = parseInt( qtyInput.value, 10 ) || 1;
            if ( cur < newMin ) { qtyInput.value = String( newMin ); }
            if ( newMax !== null && cur > newMax ) { qtyInput.value = String( newMax ); }

            updateQtyHint( scope, newMin, newMax );
        }

        if ( allPicked && ! match && errorEl ) {
            errorEl.textContent = tcI18n( 'variation_unavailable', 'Sorry, this combination is not available.' );
            errorEl.hidden      = false;
        } else if ( allPicked && match && ! match.in_stock && errorEl ) {
            errorEl.textContent = tcI18n( 'variation_out_of_stock', 'Sorry, this variation is out of stock.' );
            errorEl.hidden      = false;
        }

        var clearBtn = container.querySelector( '[data-tejcart-variation-clear]' );
        if ( clearBtn ) {
            var anyPicked = false;
            selects.forEach( function ( sel ) { if ( sel.value ) { anyPicked = true; } } );
            clearBtn.hidden = ! anyPicked;
        }

        var priceEl = container.querySelector( '[data-tejcart-variation-price]' );
        if ( priceEl ) {
            if ( match && match.is_purchasable && match.price_html ) {
                priceEl.innerHTML = sanitisePrice( match.price_html );
                priceEl.hidden    = false;
            } else {
                priceEl.innerHTML = '';
                priceEl.hidden    = true;
            }
        }
    }

    function resetVariations( container ) {
        container.querySelectorAll( '.tejcart-variation-select' ).forEach( function ( sel ) {
            sel.value = '';
        } );
        syncVariationState( container );
    }

    function initVariationForms() {
        document.querySelectorAll( '[data-tejcart-variations]' ).forEach( function ( container ) {
            if ( container._tejcartVariationInit ) { return; }
            container._tejcartVariationInit = true;
            container.addEventListener( 'change', function ( e ) {
                if ( ! e.target.classList || ! e.target.classList.contains( 'tejcart-variation-select' ) ) { return; }
                syncVariationState( container );
            } );

            container.addEventListener( 'click', function ( e ) {
                var clearBtn = e.target.closest( '[data-tejcart-variation-clear]' );
                if ( ! clearBtn || ! container.contains( clearBtn ) ) { return; }
                e.preventDefault();
                resetVariations( container );
            } );

            var scope = getVariationScope( container );
            scope.querySelectorAll( '.tejcart-gallery-thumb--variation' ).forEach( function ( thumb ) {
                if ( thumb._tejcartVariationThumbInit ) { return; }
                thumb._tejcartVariationThumbInit = true;
                thumb.addEventListener( 'click', function () {
                    var raw = thumb.getAttribute( 'data-variation-attrs' ) || '{}';
                    var attrs;
                    try { attrs = JSON.parse( raw ); } catch ( err ) { attrs = {}; }
                    if ( ! attrs || typeof attrs !== 'object' ) { return; }

                    container.querySelectorAll( '.tejcart-variation-select' ).forEach( function ( sel ) {
                        var name = sel.getAttribute( 'data-attribute-name' ) || '';
                        if ( name && Object.prototype.hasOwnProperty.call( attrs, name ) ) {
                            sel.value = String( attrs[ name ] );
                        }
                    } );
                    syncVariationState( container );
                } );
            } );

            syncVariationState( container );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initVariationForms );
    } else {
        initVariationForms();
    }

    // Re-init when AJAX content is injected (e.g. the quick-view modal),
    // so variation forms inside the new markup wire up. initVariationForms()
    // is idempotent — already-initialised containers are skipped.
    document.addEventListener( 'tejcart_content_loaded', initVariationForms );

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-add-to-cart-btn' );
        if ( ! btn ) { return; }

        e.preventDefault();

        if ( btn.classList.contains( 'is-loading' ) || btn.disabled ) { return; }

        var productId = btn.getAttribute( 'data-product-id' );
        var quantity  = btn.getAttribute( 'data-quantity' ) || '1';
        var rawRedirect = btn.getAttribute( 'data-redirect' ) || '';
        var redirect    = isSafeUrl( rawRedirect ) ? rawRedirect : '';

        var labelEl = btn.querySelector( '.tejcart-add-to-cart-label' );
        var defaultText   = btn.getAttribute( 'data-default-text' ) || ( labelEl ? labelEl.textContent : '' );
        var confirmedText = btn.getAttribute( 'data-confirmed-text' ) || 'Added';

        var payload = {
            product_id: productId,
            quantity:   quantity
        };

        var needsVariation = btn.getAttribute( 'data-variation-required' ) === '1';
        if ( needsVariation ) {
            var scope         = getVariationScope( btn );
            var variationForm = scope.querySelector( '[data-tejcart-variations]' );
            var variationId   = btn.getAttribute( 'data-variation-id' ) || '';

            if ( ! variationId || ! variationForm ) {
                var errorEl = variationForm && variationForm.querySelector( '[data-tejcart-variation-error]' );
                if ( errorEl ) {
                    errorEl.textContent = tcI18n( 'choose_options_first', 'Please choose product options before adding to cart.' );
                    errorEl.hidden      = false;
                }
                showToast( tcI18n( 'choose_options_first', 'Please choose product options before adding to cart.' ), { type: 'error', duration: 4000 } );
                return;
            }

            payload.variation_id = variationId;
            variationForm.querySelectorAll( '.tejcart-variation-select' ).forEach( function ( sel ) {
                var name = sel.getAttribute( 'data-attribute-name' ) || '';
                if ( name ) {
                    payload[ 'data[' + name + ']' ] = sel.value || '';
                }
            } );
        }

        // Generic extension hook: any input/select/textarea inside the
        // current product scope tagged `data-tejcart-cart-input="<name>"`
        // is appended to the cart payload as `data[<name>]`. Used by the
        // gift-cards module for recipient email, message, delivery date
        // etc.; theme/3rd-party code can attach the same attribute to
        // surface custom line-item meta without forking the cart JS.
        var extraScope  = btn.closest( '.tejcart-single-product' ) || document;
        var extraInputs = extraScope.querySelectorAll( '[data-tejcart-cart-input]' );
        var cancelEvent = false;
        if ( extraInputs.length ) {
            extraInputs.forEach( function ( input ) {
                var name = input.getAttribute( 'data-tejcart-cart-input' ) || '';
                if ( ! name ) { return; }
                payload[ 'data[' + name + ']' ] = input.value == null ? '' : String( input.value );
            } );
        }

        // Cancellable JS event so a module's form can short-circuit the
        // add (e.g. inline validation failure on the gift-card form).
        var preEvent = new CustomEvent( 'tejcart:add-to-cart', {
            cancelable: true,
            detail: { productId: productId, payload: payload, scope: extraScope }
        } );
        cancelEvent = ! document.dispatchEvent( preEvent );
        if ( cancelEvent ) { return; }

        btn.classList.add( 'is-loading' );
        btn.disabled = true;

        ajax( 'tejcart_add_to_cart', payload ).then( function ( result ) {
            btn.classList.remove( 'is-loading' );
            btn.disabled = false;

            if ( result.success ) {
                btn.classList.add( 'tejcart-button--confirmed', 'is-confirmed' );
                if ( labelEl ) {
                    labelEl.textContent = confirmedText + ' ✓';
                }
                setTimeout( function () {
                    btn.classList.remove( 'tejcart-button--confirmed', 'is-confirmed' );
                    if ( labelEl ) { labelEl.textContent = defaultText; }
                }, CONFIRMED_RESET_MS );

                replaceCartDrawer( result.data && result.data.drawer_html );
                updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
                updateCartTotals( result.data );

                highlightJustAdded( productId );

                announce( result.data && result.data.message
                    ? result.data.message
                    : 'Item added to cart.' );

                if ( redirect ) {
                    window.location.href = redirect;
                } else if ( params.redirect_after_add && params.cart_url && isSafeUrl( params.cart_url ) ) {
                    window.location.href = params.cart_url;
                } else if ( params.enable_cart_drawer === false && params.cart_url && isSafeUrl( params.cart_url ) ) {
                    window.location.href = params.cart_url;
                } else {
                    openCartDrawer( btn );
                }
            } else {
                showToast(
                    ( result.data && result.data.message ) || 'Could not add item to cart.',
                    { type: 'error', duration: 5000 }
                );
            }
        } );
    } );

    function performRemove( itemKey, snapshot ) {
        ajax( 'tejcart_remove_cart_item', { cart_item_key: itemKey } ).then( function ( result ) {
            if ( ! result.success ) {
                showToast(
                    ( result.data && result.data.message ) || 'That item could not be removed.',
                    { type: 'error' }
                );

                document.querySelectorAll( '[data-cart-item-key="' + itemKey + '"]' ).forEach( function ( row ) {
                    row.classList.remove( 'is-removing' );
                } );
                return;
            }

            // Audit #94 / 09 F-015 — capture a focus target BEFORE the
            // row is animated out so keyboard users don't drop to body.
            // Preference order: next-sibling cart row → previous-sibling
            // cart row → continue-shopping link → empty-cart heading.
            var nextFocus = null;
            document.querySelectorAll( '.tejcart-cart-items [data-cart-item-key="' + itemKey + '"]' ).forEach( function ( row ) {
                if ( nextFocus ) { return; }
                var sibling = row.nextElementSibling;
                while ( sibling && ! sibling.matches( '[data-cart-item-key]' ) ) {
                    sibling = sibling.nextElementSibling;
                }
                if ( ! sibling ) {
                    sibling = row.previousElementSibling;
                    while ( sibling && ! sibling.matches( '[data-cart-item-key]' ) ) {
                        sibling = sibling.previousElementSibling;
                    }
                }
                if ( sibling ) {
                    nextFocus = sibling.querySelector(
                        '.tejcart-qty-dec, .tejcart-cart-remove, .tejcart-cart-item-remove, button, a[href]'
                    );
                }
            } );

            document.querySelectorAll( '[data-cart-item-key="' + itemKey + '"]' ).forEach( function ( row ) {
                row.classList.add( 'is-removing' );
                setTimeout( function () {
                    if ( row.parentNode ) { row.parentNode.removeChild( row ); }
                }, 320 );
            } );

            // Focus shift happens after the animation so the screen-
            // reader's cursor lands on the new target, not the
            // about-to-be-removed row. If nothing left, hand focus to
            // the continue-shopping link (full-page cart) or the cart
            // drawer's close button (drawer).
            setTimeout( function () {
                if ( ! nextFocus ) {
                    nextFocus = document.querySelector( '.tejcart-cart-continue' )
                        || document.querySelector( '.tejcart-cart-drawer-close' );
                }
                if ( nextFocus && typeof nextFocus.focus === 'function' ) {
                    try { nextFocus.focus( { preventScroll: true } ); } catch ( e ) { nextFocus.focus(); }
                }
            }, 340 );

            replaceCartDrawer( result.data && result.data.drawer_html );
            updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
            updateCartTotals( result.data );

            pendingUndo = {
                key:     itemKey,
                product: snapshot.productId,
                qty:     snapshot.quantity,
                name:    snapshot.name
            };

            showToast( tcI18nFmt( 'item_removed_short', '%s removed', snapshot.name || tcI18n( 'item_fallback_name', 'Item' ) ), {
                type: 'info',
                duration: UNDO_WINDOW_MS,
                actionLabel: 'Undo',
                onAction: function () {
                    if ( ! pendingUndo ) { return; }
                    var undo = pendingUndo;
                    pendingUndo = null;
                    ajax( 'tejcart_add_to_cart', {
                        product_id: undo.product,
                        quantity:   undo.qty
                    } ).then( function ( res ) {
                        if ( res.success ) {
                            replaceCartDrawer( res.data && res.data.drawer_html );
                            updateMiniCart( ( res.data && res.data.cart_count ) || 0, res.data && res.data.total_html );

                            if ( document.body.classList.contains( 'tejcart-cart-page' )
                                || document.querySelector( '.tejcart-cart.has-items' ) ) {
                                window.location.reload();
                            } else {
                                announce( tcI18nFmt( 'item_restored', '%s restored.', undo.name || tcI18n( 'item_fallback_name', 'Item' ) ) );
                            }
                        } else {
                            showToast( tcI18n( 'undo_failed', 'Could not undo. Please try again.' ), { type: 'error' } );
                        }
                    } );
                }
            } );

            announce( tcI18nFmt( 'item_removed', '%s removed from cart.', snapshot.name || tcI18n( 'item_fallback_name', 'Item' ) ) );

            if ( result.data && result.data.cart_empty ) {
                var cartSection = document.querySelector( '.tejcart-cart.has-items' );
                if ( cartSection ) {
                    cartSection.classList.remove( 'has-items' );
                    cartSection.classList.add( 'tejcart-cart--empty' );
                    renderEmptyCartInto( cartSection );
                }
            }
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-cart-remove, .tejcart-cart-item-remove, .tejcart-cart-drawer-item-remove' );
        if ( ! btn ) { return; }

        e.preventDefault();

        var itemKey = btn.getAttribute( 'data-cart-item-key' );
        if ( ! itemKey ) { return; }

        // Resolve the item container, NOT the remove control itself. Both the
        // full-cart remove link and the drawer remove button carry their own
        // data-cart-item-key, so closest( '[data-cart-item-key]' ) would match
        // the button and the lookups below would search an empty subtree —
        // leaving productId null and breaking Undo (it POSTs product_id=null →
        // "Missing product."). Match the known row classes instead.
        var row = btn.closest( '.tejcart-cart-item, .tejcart-cart-drawer-item' );
        // data-product-id sits on a descendant in the full cart
        // (.tejcart-cart-item-info) but on the row element itself in the
        // drawer (.tejcart-cart-drawer-item) — handle both.
        var pidEl = row
            ? ( row.matches( '[data-product-id]' ) ? row : row.querySelector( '[data-product-id]' ) )
            : null;
        var snapshot = {
            productId: pidEl ? pidEl.getAttribute( 'data-product-id' ) : null,
            quantity:  row ? parseInt( ( row.querySelector( '.tejcart-qty-input, .tejcart-cart-item-qty' ) || {} ).value || '1', 10 )
                            : 1,
            name:      row ? ( row.querySelector( '.tejcart-cart-item-name, .tejcart-cart-drawer-item-name' ) || {} ).textContent || '' : ''
        };

        performRemove( itemKey, snapshot );
    } );

    function updateQty( itemKey, quantity, row, previousQuantity ) {
        if ( row ) { row.classList.add( 'is-updating' ); }
        ajax( 'tejcart_update_cart_item', {
            cart_item_key: itemKey,
            quantity:      quantity
        } ).then( function ( result ) {
            if ( row ) { row.classList.remove( 'is-updating' ); }
            if ( result.success ) {
                if ( row ) {
                    var okInputs = row.querySelectorAll( '.tejcart-qty-input, .tejcart-cart-item-qty' );
                    okInputs.forEach( function ( inp ) {
                        inp.setAttribute( 'data-last-qty', String( quantity ) );
                    } );
                    row.classList.toggle( 'is-single-qty', quantity === 1 );
                }
                replaceCartDrawer( result.data && result.data.drawer_html );
                updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
                updateCartTotals( result.data );
                announce( tcI18n( 'cart_updated', 'Cart updated.' ) );
            } else {
                if ( row && previousQuantity !== undefined ) {
                    var inputs = row.querySelectorAll( '.tejcart-qty-input, .tejcart-cart-item-qty' );
                    inputs.forEach( function ( inp ) {
                        inp.value = String( previousQuantity );
                    } );
                }
                showToast(
                    ( result.data && result.data.message ) || tcI18n( 'update_failed', 'Could not update quantity.' ),
                    { type: 'error' }
                );
            }
        } );
    }

    function syncStepperDisabled( stepper ) {
        if ( ! stepper ) { return; }
        var input = stepper.querySelector( '.tejcart-qty-input' );
        if ( ! input ) { return; }
        var current = parseInt( input.value, 10 ) || 1;
        var minAttr = parseInt( input.getAttribute( 'min' ) || '1', 10 );
        if ( isNaN( minAttr ) || minAttr < 1 ) { minAttr = 1; }
        var maxAttr = parseInt( input.getAttribute( 'max' ) || '', 10 );
        var dec = stepper.querySelector( '.tejcart-qty-decrement' );
        var inc = stepper.querySelector( '.tejcart-qty-increment' );
        if ( dec ) { dec.disabled = current <= minAttr; }
        if ( inc ) { inc.disabled = ! isNaN( maxAttr ) && maxAttr > 0 && current >= maxAttr; }
    }

    document.querySelectorAll( '.tejcart-qty-stepper' ).forEach( syncStepperDisabled );

    document.addEventListener( 'click', function ( e ) {
        var stepBtn = e.target.closest( '.tejcart-qty-btn' );
        if ( ! stepBtn ) { return; }
        var stepper = stepBtn.closest( '.tejcart-qty-stepper' );
        if ( ! stepper ) { return; }

        var input = stepper.querySelector( '.tejcart-qty-input' );
        if ( ! input ) { return; }

        var current = parseInt( input.value, 10 ) || 1;
        var next    = stepBtn.classList.contains( 'tejcart-qty-increment' ) ? current + 1 : current - 1;
        if ( next < 1 ) { next = 1; }

        var maxAttr = parseInt( input.getAttribute( 'max' ) || '', 10 );
        if ( ! isNaN( maxAttr ) && maxAttr > 0 && next > maxAttr ) { next = maxAttr; }

        if ( next === current ) { return; }

        input.value = String( next );
        syncStepperDisabled( stepper );
        input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    } );

    document.addEventListener( 'change', function ( e ) {
        if ( ! e.target.matches || ! e.target.matches( '.tejcart-qty-input' ) ) { return; }
        var stepper = e.target.closest( '.tejcart-qty-stepper' );
        if ( stepper ) { syncStepperDisabled( stepper ); }
    } );

    document.addEventListener( 'change', function ( e ) {
        var input = e.target;
        if ( ! input.matches || ! input.matches( '.tejcart-grouped-products__qty-input' ) ) { return; }
        var targetId = input.getAttribute( 'data-target' );
        if ( ! targetId ) { return; }
        var btn = document.getElementById( targetId );
        if ( ! btn ) { return; }
        var qty = Math.max( 1, parseInt( input.value, 10 ) || 1 );
        btn.setAttribute( 'data-quantity', String( qty ) );
    } );

    document.addEventListener( 'change', function ( e ) {
        var input = e.target;
        if ( ! input.matches( '.tejcart-qty-input, .tejcart-cart-item-qty' ) ) { return; }

        var itemKey  = input.getAttribute( 'data-cart-item-key' )
            || ( input.closest( '[data-cart-item-key]' ) || {} ).getAttribute &&
               ( input.closest( '[data-cart-item-key]' ).getAttribute( 'data-cart-item-key' ) );
        if ( ! itemKey ) { return; }

        var quantity = parseInt( input.value, 10 );
        if ( isNaN( quantity ) || quantity < 0 ) { return; }

        var row = input.closest( '.tejcart-cart-item, .tejcart-cart-drawer-item' );

        if ( quantity === 0 ) {
            var removeLink = row && row.querySelector( '.tejcart-cart-remove, .tejcart-cart-item-remove, .tejcart-cart-drawer-item-remove' );
            if ( removeLink ) { removeLink.click(); }
            return;
        }

        var previousQty = parseInt( input.getAttribute( 'data-last-qty' ) || '', 10 );
        if ( isNaN( previousQty ) ) {
            previousQty = parseInt( input.defaultValue, 10 );
        }
        if ( isNaN( previousQty ) ) { previousQty = 1; }

        debounce( itemKey, function () {
            updateQty( itemKey, quantity, row, previousQty );
        }, QTY_DEBOUNCE_MS );
    } );

    document.addEventListener( 'click', function ( e ) {
        var toggle = e.target.closest( '.tejcart-coupon-toggle' );
        if ( toggle ) {
            e.preventDefault();
            var wrapper = toggle.closest( '.tejcart-coupon' );
            if ( wrapper ) {
                var open = wrapper.classList.toggle( 'is-open' );
                toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
                if ( open ) {
                    var input = wrapper.querySelector( '.tejcart-coupon-input, #tejcart_coupon_code' );
                    if ( input ) { input.focus(); }
                }
            }
        }
    } );

    function setCouponFeedback( type, message ) {
        var box = document.querySelector( '[data-tejcart-coupon-feedback]' );
        if ( ! box ) { return; }
        box.classList.remove( 'is-success', 'is-error' );
        if ( ! type || ! message ) {
            box.hidden = true;
            box.textContent = '';
            return;
        }
        box.classList.add( type === 'success' ? 'is-success' : 'is-error' );
        box.textContent = message;
        box.hidden = false;
    }

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-apply-coupon-btn' );
        if ( ! btn ) { return; }
        e.preventDefault();

        var input = document.querySelector( 'input[name="tejcart_coupon_code"]' );
        if ( ! input || ! input.value.trim() ) { return; }

        var code = input.value.trim();
        setCouponFeedback( null );

        ajax( 'tejcart_apply_coupon', {
            coupon_code: code
        } ).then( function ( result ) {
            if ( result.success ) {
                var msg = ( result.data && result.data.message ) || ( 'Coupon "' + code.toUpperCase() + '" applied.' );
                setCouponFeedback( 'success', msg );
                replaceCartDrawer( result.data && result.data.drawer_html );
                updateCartTotals( result.data );
                input.value = '';

                // Broadcast a cart-mutation event so any
                // express-checkout module that has rendered (PayPal,
                // Stripe Express) can invalidate per-render cached
                // state and rebuild from the live cart at the next
                // click. Server-side createOrder already rebuilds
                // line items from the cart fresh; this event covers
                // any future callers that might cache client-side.
                document.dispatchEvent( new CustomEvent( 'tejcart:cart-updated', {
                    detail: { reason: 'coupon-applied', code: code }
                } ) );

                if ( document.querySelector( '.tejcart-cart.has-items' ) ) {
                    window.location.reload();
                }
            } else {
                setCouponFeedback(
                    'error',
                    ( result.data && result.data.message ) || 'That coupon code is not valid.'
                );
            }
        } );
    } );

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-cart-item-wishlist' );
        if ( ! btn ) { return; }
        e.preventDefault();

        var productId = parseInt( btn.getAttribute( 'data-product-id' ), 10 );
        var itemKey   = btn.getAttribute( 'data-cart-item-key' );
        var nonce     = btn.getAttribute( 'data-wishlist-nonce' );
        if ( ! productId || ! itemKey || ! nonce ) { return; }

        btn.setAttribute( 'aria-busy', 'true' );

        var fd = new FormData();
        fd.append( 'action', 'tejcart_wishlist_toggle' );
        fd.append( 'product_id', String( productId ) );
        fd.append( 'nonce', nonce );

        var ajaxUrl = ( params && params.ajax_url ) || ( window.ajaxurl || '/wp-admin/admin-ajax.php' );

        fetch( ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        } ).then( function ( res ) {
            return res.json().catch( function () { return null; } );
        } ).then( function ( payload ) {
            btn.removeAttribute( 'aria-busy' );
            if ( ! payload || ! payload.success ) {
                showToast(
                    ( payload && payload.data && payload.data.message ) || 'Could not move to wishlist.',
                    { type: 'error' }
                );
                return;
            }

            // Resolve the item row by its container class, NOT [data-cart-item-key]:
            // the wishlist button itself carries data-cart-item-key, so
            // closest( '[data-cart-item-key]' ) would match the button and the
            // remove-control lookup would search an empty subtree — leaving the
            // item in the cart after it was added to the wishlist.
            var row    = btn.closest( '.tejcart-cart-item, .tejcart-cart-drawer-item' );
            var remove = row ? row.querySelector( '.tejcart-cart-item-remove, .tejcart-cart-remove, .tejcart-cart-drawer-item-remove' ) : null;
            announce( tcI18n( 'moved_to_wishlist', 'Moved to wishlist.' ) );
            if ( remove ) {
                remove.click();
            }
        } ).catch( function () {
            btn.removeAttribute( 'aria-busy' );
            showToast( 'Could not move to wishlist.', { type: 'error' } );
        } );
    } );

    function getDrawer() { return document.querySelector( '.tejcart-cart-drawer' ); }
    function getOverlay() { return document.querySelector( '.tejcart-cart-drawer-overlay' ); }

    var paypalLazyLoaded = false;

    function maybeLoadPayPalLazy() {
        if ( paypalLazyLoaded ) { return; }
        var lazy = params.paypal_lazy;
        if ( ! lazy || ! lazy.script_url ) { return; }
        paypalLazyLoaded = true;

        window.tejcart_paypal_params = lazy.params;

        if ( lazy.googlepay_sdk && ! document.querySelector( 'script[src*="pay.google.com"]' ) ) {
            var gScript = document.createElement( 'script' );
            gScript.src = 'https://pay.google.com/gp/p/js/pay.js';
            gScript.async = true;
            document.head.appendChild( gScript );
        }

        var ppScript = document.createElement( 'script' );
        ppScript.src = lazy.script_url;
        ppScript.async = true;
        document.head.appendChild( ppScript );
    }

    function openCartDrawer( triggerEl ) {
        var drawer  = getDrawer();
        var overlay = getOverlay();
        if ( ! drawer ) { return; }

        maybeLoadPayPalLazy();

        lastFocusedEl = triggerEl || document.activeElement;

        drawer.classList.add( 'is-open' );
        drawer.classList.add( 'open' );
        drawer.setAttribute( 'aria-hidden', 'false' );
        drawer.removeAttribute( 'inert' );
        if ( overlay ) {
            overlay.classList.add( 'is-active' );
            overlay.classList.add( 'active' );
        }
        document.body.style.overflow = 'hidden';

        var closeBtn = drawer.querySelector( '.tejcart-cart-drawer-close' );
        if ( closeBtn ) { closeBtn.focus(); }

        if ( ! drawerKeyTrap ) {
            drawerKeyTrap = function ( e ) {
                if ( e.key === 'Escape' ) {
                    closeCartDrawer();
                    return;
                }
                if ( e.key !== 'Tab' ) { return; }

                var d = getDrawer();
                if ( ! d ) { return; }
                var focusables = Array.prototype.slice.call(
                    d.querySelectorAll( FOCUSABLE_SELECTOR )
                ).filter( function ( el ) { return ! el.hasAttribute( 'disabled' ) && el.offsetParent !== null; } );

                if ( focusables.length === 0 ) {
                    e.preventDefault();
                    return;
                }

                var first = focusables[ 0 ];
                var last  = focusables[ focusables.length - 1 ];

                if ( e.shiftKey && document.activeElement === first ) {
                    e.preventDefault();
                    last.focus();
                } else if ( ! e.shiftKey && document.activeElement === last ) {
                    e.preventDefault();
                    first.focus();
                }
            };
            document.addEventListener( 'keydown', drawerKeyTrap );
        }
    }

    function closeCartDrawer() {
        var drawer  = getDrawer();
        var overlay = getOverlay();
        if ( drawer ) {
            drawer.classList.remove( 'is-open' );
            drawer.classList.remove( 'open' );
            drawer.setAttribute( 'aria-hidden', 'true' );
            drawer.setAttribute( 'inert', '' );
        }
        if ( overlay ) {
            overlay.classList.remove( 'is-active' );
            overlay.classList.remove( 'active' );
        }
        document.body.style.overflow = '';

        if ( drawerKeyTrap ) {
            document.removeEventListener( 'keydown', drawerKeyTrap );
            drawerKeyTrap = null;
        }

        if ( lastFocusedEl && typeof lastFocusedEl.focus === 'function' ) {
            window.requestAnimationFrame( function () {
                try { lastFocusedEl.focus(); } catch ( err ) {  }
                lastFocusedEl = null;
            } );
        }
    }

    function activateMiniCart( trigger, e ) {
        if ( getDrawer() ) {
            e.preventDefault();
            openCartDrawer( trigger );
            return;
        }
        if ( params.cart_url && isSafeUrl( params.cart_url ) ) {
            e.preventDefault();
            window.location.href = params.cart_url;
        }
    }

    document.addEventListener( 'click', function ( e ) {
        var trigger = e.target.closest( '.tejcart-mini-cart' );
        if ( trigger ) { activateMiniCart( trigger, e ); }
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
        var trigger = e.target.closest( '.tejcart-mini-cart' );
        if ( trigger ) { activateMiniCart( trigger, e ); }
    } );

    document.addEventListener( 'click', function ( e ) {
        if ( e.target.closest( '.tejcart-cart-drawer-close' ) ) {
            closeCartDrawer();
            return;
        }
        var overlay = e.target.closest( '.tejcart-cart-drawer-overlay' );
        if ( overlay ) { closeCartDrawer(); }
    } );

    /* ───────────────────────────────────────────────
     * Gift wrap toggle
     * ─────────────────────────────────────────────── */
    document.addEventListener( 'change', function ( e ) {
        if ( ! e.target.matches || ! e.target.matches( '[data-tejcart-gift-wrap-toggle]' ) ) { return; }
        var checkbox = e.target;
        var wrapper  = checkbox.closest( '[data-tejcart-gift-wrap]' );
        var msgBox   = wrapper ? wrapper.querySelector( '.tejcart-cart-drawer-gift-wrap-message' ) : null;
        var textarea = wrapper ? wrapper.querySelector( '[data-tejcart-gift-message]' ) : null;
        var enabled  = checkbox.checked;

        if ( msgBox ) { msgBox.hidden = ! enabled; }
        if ( wrapper ) { wrapper.classList.toggle( 'is-active', enabled ); }

        var message = textarea ? textarea.value : '';

        ajax( 'tejcart_toggle_gift_wrap', {
            enabled: enabled ? '1' : '0',
            gift_message: message
        } ).then( function ( result ) {
            if ( result.success ) {
                replaceCartDrawer( result.data && result.data.drawer_html );
                updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
                updateCartTotals( result.data );
                announce( enabled
                    ? tcI18n( 'gift_wrap_added', 'Gift wrap added.' )
                    : tcI18n( 'gift_wrap_removed', 'Gift wrap removed.' )
                );
            }
        } );
    } );

    var giftMsgTimer = null;
    document.addEventListener( 'input', function ( e ) {
        if ( ! e.target.matches || ! e.target.matches( '[data-tejcart-gift-message]' ) ) { return; }
        var textarea = e.target;
        if ( giftMsgTimer ) { clearTimeout( giftMsgTimer ); }
        giftMsgTimer = setTimeout( function () {
            giftMsgTimer = null;
            var wrapper  = textarea.closest( '[data-tejcart-gift-wrap]' );
            var checkbox = wrapper ? wrapper.querySelector( '[data-tejcart-gift-wrap-toggle]' ) : null;
            if ( ! checkbox || ! checkbox.checked ) { return; }
            ajax( 'tejcart_toggle_gift_wrap', {
                enabled: '1',
                gift_message: textarea.value
            } );
        }, 800 );
    } );

    /* ───────────────────────────────────────────────
     * Save for later
     * ─────────────────────────────────────────────── */
    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-save-for-later-btn' );
        if ( ! btn ) { return; }
        e.preventDefault();

        var itemKey = btn.getAttribute( 'data-cart-item-key' );
        if ( ! itemKey ) { return; }

        var row  = btn.closest( '[data-cart-item-key]' );
        var name = row ? ( row.querySelector( '.tejcart-cart-drawer-item-name' ) || {} ).textContent || '' : '';
        if ( row ) { row.classList.add( 'is-removing' ); }

        ajax( 'tejcart_save_for_later', { cart_item_key: itemKey } ).then( function ( result ) {
            if ( result.success ) {
                // On the full cart page the item row and the saved-for-later
                // section both need to change membership — reload so the
                // server-rendered markup stays authoritative (same pattern as
                // coupon / undo flows).
                if ( document.body.classList.contains( 'tejcart-cart-page' )
                    || document.querySelector( '.tejcart-cart.has-items' ) ) {
                    window.location.reload();
                    return;
                }
                replaceCartDrawer( result.data && result.data.drawer_html );
                updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
                updateCartTotals( result.data );
                showToast(
                    tcI18nFmt( 'saved_for_later', '%s saved for later', name.trim() || tcI18n( 'item_fallback_name', 'Item' ) ),
                    { type: 'success', duration: 4000 }
                );
                announce( tcI18nFmt( 'saved_for_later', '%s saved for later.', name.trim() || tcI18n( 'item_fallback_name', 'Item' ) ) );
            } else {
                if ( row ) { row.classList.remove( 'is-removing' ); }
                showToast(
                    ( result.data && result.data.message ) || 'Could not save item.',
                    { type: 'error' }
                );
            }
        } );
    } );

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-saved-item-restore' );
        if ( ! btn ) { return; }
        e.preventDefault();

        var index = btn.getAttribute( 'data-saved-index' );
        if ( index === null || index === '' ) { return; }

        var card = btn.closest( '.tejcart-saved-item' );
        if ( card ) { card.classList.add( 'is-restoring' ); }

        ajax( 'tejcart_restore_from_saved', { saved_index: index } ).then( function ( result ) {
            if ( result.success ) {
                if ( document.body.classList.contains( 'tejcart-cart-page' )
                    || document.querySelector( '.tejcart-cart.has-items' ) ) {
                    window.location.reload();
                    return;
                }
                replaceCartDrawer( result.data && result.data.drawer_html );
                updateMiniCart( ( result.data && result.data.cart_count ) || 0, result.data && result.data.total_html );
                updateCartTotals( result.data );
                showToast(
                    tcI18n( 'item_restored_to_cart', 'Item restored to cart.' ),
                    { type: 'success', duration: 3000 }
                );
                announce( tcI18n( 'item_restored_to_cart', 'Item restored to cart.' ) );
            } else {
                if ( card ) { card.classList.remove( 'is-restoring' ); }
                showToast(
                    ( result.data && result.data.message ) || 'Could not restore item.',
                    { type: 'error' }
                );
            }
        } );
    } );

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.tejcart-saved-item-remove' );
        if ( ! btn ) { return; }
        e.preventDefault();

        var index = btn.getAttribute( 'data-saved-index' );
        if ( index === null || index === '' ) { return; }

        var card = btn.closest( '.tejcart-saved-item' );
        if ( card ) {
            card.style.opacity = '0';
            card.style.transform = 'translateX(20px)';
        }

        ajax( 'tejcart_remove_saved_item', { saved_index: index } ).then( function ( result ) {
            if ( result.success ) {
                if ( document.body.classList.contains( 'tejcart-cart-page' )
                    || document.querySelector( '.tejcart-cart.has-items' ) ) {
                    window.location.reload();
                    return;
                }
                replaceCartDrawer( result.data && result.data.drawer_html );
                announce( tcI18n( 'saved_item_removed', 'Saved item removed.' ) );
            } else {
                if ( card ) {
                    card.style.opacity = '';
                    card.style.transform = '';
                }
            }
        } );
    } );

    /* Save-for-later section toggle */
    document.addEventListener( 'click', function ( e ) {
        var toggle = e.target.closest( '.tejcart-saved-for-later-toggle' );
        if ( ! toggle ) { return; }
        e.preventDefault();
        var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
        toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
        var targetId = toggle.getAttribute( 'aria-controls' );
        var target   = targetId ? document.getElementById( targetId ) : null;
        if ( target ) { target.hidden = expanded; }
    } );

    /* ───────────────────────────────────────────────
     * Keyboard stepper — arrow up/down inside qty input
     * ─────────────────────────────────────────────── */
    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'ArrowUp' && e.key !== 'ArrowDown' ) { return; }
        var input = e.target;
        if ( ! input.matches || ! input.matches( '.tejcart-qty-input' ) ) { return; }

        e.preventDefault();

        var stepper = input.closest( '.tejcart-qty-stepper' );
        var current = parseInt( input.value, 10 ) || 1;
        var next    = e.key === 'ArrowUp' ? current + 1 : current - 1;

        var minAttr = parseInt( input.getAttribute( 'min' ) || '1', 10 );
        if ( isNaN( minAttr ) || minAttr < 1 ) { minAttr = 1; }
        if ( next < minAttr ) { next = minAttr; }

        var maxAttr = parseInt( input.getAttribute( 'max' ) || '', 10 );
        if ( ! isNaN( maxAttr ) && maxAttr > 0 && next > maxAttr ) { next = maxAttr; }

        if ( next === current ) { return; }

        input.value = String( next );
        if ( stepper ) { syncStepperDisabled( stepper ); }
        input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    } );

    /*
     * Audit #98 / 01 #8 — in-cart shipping calculator widget.
     * Posts to tejcart_calculate_shipping; on success the cart totals
     * are refreshed via updateCartTotals() and the available methods
     * are listed under the form.
     */
    document.addEventListener( 'click', function ( e ) {
        var toggle = e.target.closest( '[data-tejcart-shipping-calculator-toggle]' );
        if ( toggle ) {
            e.preventDefault();
            var wrapper = toggle.closest( '[data-tejcart-shipping-calculator]' );
            if ( ! wrapper ) { return; }
            var form = wrapper.querySelector( '[data-tejcart-shipping-calculator-form]' );
            if ( ! form ) { return; }
            var willOpen = form.hasAttribute( 'hidden' );
            if ( willOpen ) { form.removeAttribute( 'hidden' ); } else { form.setAttribute( 'hidden', '' ); }
            toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
            return;
        }

        var submit = e.target.closest( '[data-tejcart-calc-submit]' );
        if ( ! submit ) { return; }
        e.preventDefault();

        var wrap = submit.closest( '[data-tejcart-shipping-calculator]' );
        if ( ! wrap ) { return; }
        var countryEl  = wrap.querySelector( '[data-tejcart-calc-country]' );
        var stateEl    = wrap.querySelector( '[data-tejcart-calc-state]' );
        var postcodeEl = wrap.querySelector( '[data-tejcart-calc-postcode]' );
        var feedback   = wrap.querySelector( '[data-tejcart-calc-feedback]' );

        var country  = countryEl  ? String( countryEl.value || '' ).trim() : '';
        var state    = stateEl    ? String( stateEl.value || '' ).trim()    : '';
        var postcode = postcodeEl ? String( postcodeEl.value || '' ).trim() : '';

        if ( feedback ) {
            feedback.textContent = '';
            feedback.classList.remove( 'is-error', 'is-success' );
        }
        if ( ! country ) {
            if ( feedback ) {
                feedback.textContent = tcI18n( 'shipping_calc_pick_country', 'Please select a country.' );
                feedback.classList.add( 'is-error' );
            }
            return;
        }

        submit.disabled = true;
        ajax( 'tejcart_calculate_shipping', {
            country:  country,
            state:    state,
            postcode: postcode
        } ).then( function ( result ) {
            submit.disabled = false;
            if ( ! result.success ) {
                if ( feedback ) {
                    feedback.textContent = ( result.data && result.data.message ) || tcI18n( 'shipping_calc_failed', 'Could not estimate shipping.' );
                    feedback.classList.add( 'is-error' );
                }
                return;
            }

            updateCartTotals( result.data );
            replaceCartDrawer( result.data && result.data.drawer_html );

            var methods = ( result.data && result.data.methods ) || [];
            if ( feedback ) {
                if ( methods.length === 0 ) {
                    feedback.textContent = tcI18n( 'shipping_calc_no_options', 'No shipping options found for that address.' );
                    feedback.classList.add( 'is-error' );
                } else {
                    var first = methods[0];
                    feedback.textContent = first.title + ' — ' + first.cost_html;
                    feedback.classList.add( 'is-success' );
                }
            }
        } ).catch( function () {
            submit.disabled = false;
            if ( feedback ) {
                feedback.textContent = tcI18n( 'shipping_calc_failed', 'Could not estimate shipping.' );
                feedback.classList.add( 'is-error' );
            }
        } );
    } );
} )();
