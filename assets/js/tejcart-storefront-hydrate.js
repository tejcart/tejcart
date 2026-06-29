/**
 * TejCart storefront hydrator.
 *
 * Tiny vanilla-JS that fills `data-tejcart-fragment` placeholders in
 * the page with the current visitor's cart / login state. The page
 * itself is edge-cached and identical for every visitor; this script
 * is what makes the cart icon say "(3)" instead of "(0)" after the
 * page has loaded.
 *
 * No deps. No jQuery. < 2 KB minified. Runs once per page load.
 *
 * Contract:
 *   - window.tejcartStorefront = { endpoint, maxAgeMs } is injected
 *     server-side via wp_add_inline_script in Edge_Hydrate.php.
 *   - The endpoint returns the JSON shape documented on
 *     Storefront_State_Controller (PR #6).
 *   - We send `credentials: 'same-origin'` so the WP cart cookie
 *     reaches the REST endpoint; the endpoint reads the cart from
 *     that session and returns the per-visitor state.
 *   - We pass `If-None-Match` from sessionStorage so a back/forward
 *     navigation gets a 304 + stays cached client-side.
 *
 * Failure handling:
 *   - Any error (network, JSON parse, missing endpoint) is swallowed
 *     silently. The placeholder shows zero-state defaults; the
 *     visitor gets a worse experience than ideal but the storefront
 *     never breaks just because the hydration call failed.
 */
( function () {
    'use strict';

    var cfg = window.tejcartStorefront;
    if ( ! cfg || ! cfg.endpoint ) {
        return;
    }

    // Bail early on browsers without fetch / Promise. Don't
    // polyfill — those visitors fall back to the un-hydrated zero-
    // state placeholder, which is correct (an empty cart icon) and
    // avoids loading bytes for a vanishing slice of traffic.
    if ( typeof window.fetch !== 'function' || typeof window.Promise !== 'function' ) {
        return;
    }

    var placeholders = document.querySelectorAll( '[data-tejcart-fragment]' );
    if ( ! placeholders.length ) {
        return;
    }

    var STORAGE_KEY      = 'tejcart_sfx_v1';
    // F-H9 / #932: cross-tab cart-state sync. Other tabs that mutate
    // the cart bump this localStorage key; we listen on the `storage`
    // event for changes and re-fetch state. localStorage is shared
    // across same-origin tabs (sessionStorage is not).
    var BUMP_KEY         = 'tejcart_cart_bump_v1';
    var maxAgeMs         = ( typeof cfg.maxAgeMs === 'number' && cfg.maxAgeMs > 0 ) ? cfg.maxAgeMs : 30000;

    /**
     * Read a previously cached state object. Returns null when the
     * cache is missing, expired, or unparseable.
     */
    function readCachedState() {
        try {
            var raw = window.sessionStorage.getItem( STORAGE_KEY );
            if ( ! raw ) { return null; }
            var parsed = JSON.parse( raw );
            if ( ! parsed || typeof parsed !== 'object' ) { return null; }
            if ( ! parsed.savedAt || ( Date.now() - parsed.savedAt ) > maxAgeMs ) {
                return null;
            }
            return parsed;
        } catch ( _e ) {
            return null;
        }
    }

    /**
     * Persist the latest state for back/forward navigations + the
     * 304 fast path. Failure is silent — sessionStorage being
     * unavailable (Safari incognito, locked-down browser) just
     * disables this client-side cache, doesn't break hydration.
     */
    function writeCachedState( state, etag ) {
        try {
            window.sessionStorage.setItem(
                STORAGE_KEY,
                JSON.stringify( {
                    state:   state,
                    etag:    etag || '',
                    savedAt: Date.now(),
                } )
            );
        } catch ( _e ) { /* ignore */ }
    }

    /**
     * Walk the page's placeholders and fill them with the given
     * state. Pure DOM writes; no further network calls.
     */
    function applyState( state ) {
        if ( ! state || typeof state !== 'object' || ! state.cart ) {
            return;
        }
        var count    = ( state.cart.item_count | 0 );
        var subtotal = state.cart.subtotal_formatted || '';
        var loggedIn = !! ( state.user && state.user.is_logged_in );

        // querySelectorAll returns a static NodeList — safe to
        // iterate even if hydration triggers DOM mutations.
        for ( var i = 0; i < placeholders.length; i++ ) {
            var el     = placeholders[ i ];
            var region = el.getAttribute( 'data-tejcart-fragment' );
            if ( region === 'cart-icon' ) {
                el.textContent = count > 0 ? String( count ) : '';
                el.setAttribute( 'data-cart-count', String( count ) );
                if ( count > 0 ) {
                    el.classList.remove( 'tejcart-cart-icon__count--empty' );
                } else {
                    el.classList.add( 'tejcart-cart-icon__count--empty' );
                }
            } else if ( region === 'cart-subtotal' ) {
                el.textContent = subtotal;
            } else if ( region === 'user-state' ) {
                el.setAttribute( 'data-logged-in', loggedIn ? '1' : '0' );
            }
        }

        // Public event so themes / extensions can layer on top
        // without us having to enumerate every fragment region.
        try {
            document.dispatchEvent( new CustomEvent( 'tejcart:storefront-hydrated', {
                detail: { state: state }
            } ) );
        } catch ( _e ) { /* IE-style CustomEvent fallback unnecessary in 2026+ */ }
    }

    // Apply cached state immediately if we have a fresh copy. Avoids
    // a brief "0" flicker on back/forward navigations within the
    // same session.
    var cached = readCachedState();
    if ( cached && cached.state ) {
        applyState( cached.state );
    }

    var headers = { Accept: 'application/json' };
    if ( cached && cached.etag ) {
        headers[ 'If-None-Match' ] = cached.etag;
    }

    /**
     * Fire the hydration request and apply the result. Reused by
     * the initial paint and by the cross-tab bump listener.
     */
    function fetchAndApply( extraHeaders ) {
        var requestHeaders = { Accept: 'application/json' };
        if ( extraHeaders ) {
            for ( var k in extraHeaders ) {
                if ( Object.prototype.hasOwnProperty.call( extraHeaders, k ) ) {
                    requestHeaders[ k ] = extraHeaders[ k ];
                }
            }
        }
        return window.fetch( cfg.endpoint, {
            method:      'GET',
            credentials: 'same-origin',
            headers:     requestHeaders,
        } ).then( function ( response ) {
            if ( response.status === 304 ) {
                return null;
            }
            if ( ! response.ok ) {
                return null;
            }
            var etag = response.headers.get( 'ETag' ) || '';
            return response.json().then( function ( body ) {
                return { body: body, etag: etag };
            } );
        } ).then( function ( payload ) {
            if ( ! payload || ! payload.body ) {
                return;
            }
            applyState( payload.body );
            writeCachedState( payload.body, payload.etag );
        } )[ 'catch' ]( function () { /* silent */ } );
    }

    // Cross-tab cart-state sync. When tab A mutates the cart it bumps
    // BUMP_KEY in localStorage; tab B's storage event listener picks
    // it up and forces a refetch. Skip our own writes (storage event
    // does not fire in the originating tab, but be defensive against
    // future browser changes).
    try {
        window.addEventListener( 'storage', function ( ev ) {
            if ( ! ev || ev.key !== BUMP_KEY ) {
                return;
            }
            // Force a no-etag refetch so we don't get a 304 against
            // the stale cached state.
            try { window.sessionStorage.removeItem( STORAGE_KEY ); } catch ( _e ) { /* ignore */ }
            fetchAndApply( null );
        } );
    } catch ( _e ) { /* ignore */ }

    window.fetch( cfg.endpoint, {
        method:      'GET',
        credentials: 'same-origin',
        headers:     headers,
    } ).then( function ( response ) {
        if ( response.status === 304 && cached && cached.state ) {
            // Server confirmed our cached body is still current. No
            // further work — applyState already ran above.
            return null;
        }
        if ( ! response.ok ) {
            return null;
        }
        var etag = response.headers.get( 'ETag' ) || '';
        return response.json().then( function ( body ) {
            return { body: body, etag: etag };
        } );
    } ).then( function ( payload ) {
        if ( ! payload || ! payload.body ) {
            return;
        }
        applyState( payload.body );
        writeCachedState( payload.body, payload.etag );
    } )[ 'catch' ]( function () {
        // Silent: network failure / CORS / blocked-by-extension. The
        // un-hydrated zero-state placeholder remains, which is
        // strictly worse but never broken.
    } );
} )();
