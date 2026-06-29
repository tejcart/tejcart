/**
 * TejCart Checkout — front-end JavaScript.
 *
 * Powers the customer-facing checkout page:
 *
 *   • Inline field validation with aria-describedby + aria-invalid.
 *   • Payment-method accordion behaviour.
 *   • "Use a different billing address" toggle (smoothly reveals billing
 *     fields when the customer wants billing to differ from shipping).
 *   • Mobile order-summary accordion toggle.
 *   • Form submission with mandatory double-submit prevention,
 *     processing label, and inline error rendering — never window.alert.
 *
 * Vanilla ES6, no jQuery. All listeners are scoped to the form so
 * the script does nothing on pages where the form isn't present.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    var params         = window.tejcart_params || {};
    var checkoutParams = window.tejcart_checkout_params || {};
    var form           = null;
    var overlay        = null;
    var isSubmitting   = false;

    var REQUEST_TIMEOUT_MS = 30000;
    var EMAIL_RE           = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var SHIPPING_REFRESH_DEBOUNCE_MS = 500;
    var checkoutCouponDocBound = false;

    // Show the browser's "Leave site?" prompt while a checkout
    // submission is in flight. Modern browsers ignore custom strings.
    function warnUnloadDuringCheckout( e ) {
        if ( ! isSubmitting ) { return; }
        if ( e ) { e.preventDefault(); e.returnValue = ''; }
        return '';
    }

    // Gateway redirect URLs may be cross-origin (e.g. PayPal approve URL), so allow any http(s) and reject only dangerous schemes.
    function isSafeUrl( url ) {
        if ( ! url ) { return false; }
        if ( url.charAt( 0 ) === '/' && url.charAt( 1 ) !== '/' ) { return true; }
        try {
            var parsed = new URL( url );
            return parsed.protocol === 'https:' || parsed.protocol === 'http:';
        } catch ( e ) {
            return false;
        }
    }

    // Public API surface for sibling modules (notably `tejcart-paypal.js`)
    // that need to gate a wallet popup behind the same client-side
    // checkout-form validation as the generic Place Order button. Both
    // entry points fail open (return true) when the checkout form is not
    // on the page so non-checkout placements — express buttons on the
    // PDP / cart / drawer — are unaffected.
    window.tejcartCheckout = window.tejcartCheckout || {};
    window.tejcartCheckout.validate = function () {
        if ( ! form ) { return true; }
        return validateForm();
    };
    window.tejcartCheckout.isReady = function () {
        return !! form;
    };

    /**
     * Implicit terms acceptance for express / wallet flows.
     *
     * Shopify-style: a buyer clicking a wallet button (PayPal, Venmo,
     * Apple Pay, Google Pay) is treated as having agreed to the
     * merchant's Terms & Conditions — a corresponding disclaimer is
     * rendered next to each wallet button. This helper auto-checks the
     * required terms checkbox so the standard preflight passes for the
     * wallet flow without the buyer having to scroll down to a
     * checkbox that sits below the button. Returns true if a terms
     * checkbox was found and ticked (or was already ticked), false if
     * the page has no terms checkbox.
     */
    window.tejcartCheckout.acceptTerms = function () {
        var scope = form || document;
        var box   = scope.querySelector( '[data-tejcart-terms] input[type="checkbox"]' );
        if ( ! box ) { return false; }
        if ( ! box.checked ) {
            box.checked = true;
            try {
                box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
            } catch ( _e ) { /* IE11-era fallback not needed on PHP 8.2+ stack */ }
        }
        var wrap = box.closest( '.tejcart-form-row, .tejcart-checkout-terms' );
        if ( wrap ) {
            wrap.classList.remove( 'has-error', 'is-error' );
            var msg = wrap.querySelector( '.tejcart-field-error, .tejcart-field-error-text' );
            if ( msg ) { msg.textContent = ''; }
        }
        box.removeAttribute( 'aria-invalid' );
        return true;
    };

    document.addEventListener( 'DOMContentLoaded', function () {
        form = document.getElementById( 'tejcart-checkout-form' );
        if ( ! form ) { return; }

        initBrowserLocaleCountry();
        initPaymentMethods();
        initPayPalSavedMethodToggle();
        initShippingToggle();
        initFieldValidation();
        initCountryStateSync();
        initAddressAutocomplete();
        initShippingMethodRefresh();
        initShippingMethodSelection();
        initProgressiveFields();
        initOrderNotesDisclosure();
        initAccountPasswordReveal();
        initFormSubmit();
        initMobileSummaryToggle();
        initStickyPlaceOrder();
        initStickyButtonState();
        initAccountDisclosure();
        initCheckoutCoupon();
        createOverlay();
        initCrossTabDriftDetect();
        initFormPersistence();
    } );

    /**
     * F-M13 / #947: persist non-sensitive checkout form values to
     * sessionStorage so an accidental reload doesn't empty the form.
     *
     * Strict skip-list for PCI / secrets — we never persist anything
     * matching /password|card|cvv|cvc|cvc2|cc[-_]?num|security[-_]?code/i
     * or any field flagged data-tejcart-skip-persist. Plain string
     * inputs and select values are stored; sessionStorage scoping
     * means values are cleared automatically when the tab closes.
     */
    function initFormPersistence() {
        if ( ! form ) { return; }
        // N-M5: when the merchant requires cookie consent and the buyer
        // has not granted it, do NOT persist form values (email, phone,
        // postal address) to sessionStorage. The server-side
        // `tejcart_has_cookie_consent()` decision is forwarded as
        // `persistForm` on `tejcart_checkout_params`. The default is
        // `true` for stores that don't require consent — matching the
        // F-M13 behaviour. Also proactively clear any pre-existing
        // snapshot so toggling consent OFF cleans up leftover data.
        if ( window.tejcart_checkout_params && window.tejcart_checkout_params.persistForm === false ) {
            try { window.sessionStorage.removeItem( 'tejcart_checkout_fields_v1' ); } catch ( _e ) { /* ignore */ }
            return;
        }
        var STORAGE_KEY = 'tejcart_checkout_fields_v1';
        var SKIP        = /^(password|cvv|cvc|cvc2|cc[-_]?num|security[-_]?code|account_password|tejcart_checkout_nonce|tejcart_cart_totals_hash|_wpnonce)$/i;

        function shouldPersistField( el ) {
            if ( ! el || ! el.name ) { return false; }
            if ( el.type === 'password' || el.type === 'file' || el.type === 'hidden' ) { return false; }
            if ( el.getAttribute( 'data-tejcart-skip-persist' ) ) { return false; }
            if ( SKIP.test( el.name ) ) { return false; }
            // Card / cvv / cvc — skip any field whose name OR id contains those tokens.
            var token = ( ( el.name || '' ) + ' ' + ( el.id || '' ) ).toLowerCase();
            if ( /card|cvv|cvc|security[-_]?code|cc[-_]?num/.test( token ) ) { return false; }
            return true;
        }

        function readState() {
            try {
                var raw = window.sessionStorage.getItem( STORAGE_KEY );
                return raw ? JSON.parse( raw ) : null;
            } catch ( _e ) { return null; }
        }

        function writeState() {
            try {
                var snapshot = {};
                var inputs = form.querySelectorAll( 'input, select, textarea' );
                for ( var i = 0; i < inputs.length; i++ ) {
                    var el = inputs[ i ];
                    if ( ! shouldPersistField( el ) ) { continue; }
                    if ( el.type === 'checkbox' || el.type === 'radio' ) {
                        // For radios, record per-name selected value;
                        // for checkboxes, record per-name checked state.
                        if ( el.type === 'radio' && el.checked ) {
                            snapshot[ el.name ] = el.value;
                        } else if ( el.type === 'checkbox' ) {
                            snapshot[ '__chk:' + el.name + ':' + el.value ] = el.checked ? '1' : '';
                        }
                    } else {
                        snapshot[ el.name ] = String( el.value || '' );
                    }
                }
                window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( snapshot ) );
            } catch ( _e ) { /* ignore */ }
        }

        function restoreState() {
            var state = readState();
            if ( ! state || typeof state !== 'object' ) { return; }
            var inputs = form.querySelectorAll( 'input, select, textarea' );
            for ( var i = 0; i < inputs.length; i++ ) {
                var el = inputs[ i ];
                if ( ! shouldPersistField( el ) ) { continue; }
                if ( el.type === 'radio' ) {
                    if ( state[ el.name ] !== undefined && state[ el.name ] === el.value && ! el.checked ) {
                        el.checked = true;
                        // The payment-method accordion, PayPal saved-method
                        // toggle and shipping summary only re-sync on a
                        // `change` event (see initPaymentMethods). Restoring
                        // a radio silently left the previously auto-selected
                        // method's panel expanded, so two payment methods
                        // (e.g. PayPal + Google Pay) appeared active at once.
                        // Fire the same synthetic change the auto-select path
                        // uses. The `! el.checked` guard above means this only
                        // runs when the selection actually changed, so a
                        // restore that agrees with the server doesn't re-fire
                        // the shipping-method AJAX.
                        try {
                            el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                        } catch ( _e ) { /* ignore */ }
                    }
                } else if ( el.type === 'checkbox' ) {
                    var key = '__chk:' + el.name + ':' + el.value;
                    if ( state[ key ] !== undefined ) {
                        el.checked = state[ key ] === '1';
                    }
                } else if ( state[ el.name ] !== undefined && el.value === '' ) {
                    // Only restore when the server-rendered value is
                    // empty so server-side defaults still win (e.g. a
                    // logged-in user with a saved Address Book entry).
                    el.value = state[ el.name ];
                }
            }
        }

        // Restore first, then start tracking changes.
        try { restoreState(); } catch ( _e ) { /* ignore */ }

        try {
            form.addEventListener( 'input', writeState, { passive: true } );
            form.addEventListener( 'change', writeState, { passive: true } );
            // Wipe on successful submit so the next checkout starts clean.
            form.addEventListener( 'submit', function () {
                try { window.sessionStorage.removeItem( STORAGE_KEY ); } catch ( _e ) { /* ignore */ }
            } );
        } catch ( _e ) { /* ignore */ }
    }

    /**
     * F-M3 / #937: detect cart drift from another tab while the
     * customer sits on the checkout page. We piggyback on the
     * tejcart_cart_bump_v1 localStorage key written by tejcart-cart.js
     * after every cart mutation (F-H9). When it changes (storage event)
     * or when the tab regains visibility, refresh the order summary
     * via the existing shipping-methods endpoint and show a
     * non-blocking banner so the buyer knows their total changed.
     */
    function initCrossTabDriftDetect() {
        if ( ! form ) { return; }
        if ( ! checkoutParams || ! checkoutParams.ajax_url ) { return; }

        var BUMP_KEY = 'tejcart_cart_bump_v1';
        var lastSeen = '';
        try { lastSeen = window.localStorage.getItem( BUMP_KEY ) || ''; } catch ( _e ) { /* ignore */ }

        function refreshSummary( reason ) {
            // Fire a shipping-methods refresh with the current form's
            // address so the server recomputes totals from the live
            // cart. If the JS module that owns refreshShippingMethods
            // is loaded it will pick up the order_summary html in its
            // response and patch the DOM.
            try {
                if ( typeof window.tejcartRefreshShippingMethods === 'function' ) {
                    window.tejcartRefreshShippingMethods( { reason: reason } );
                    showDriftBanner( reason );
                }
            } catch ( _e ) { /* ignore */ }
        }

        function showDriftBanner( reason ) {
            // Best-effort visual cue. If the page has a dedicated
            // banner region we use it; otherwise we add a small
            // dismissible strip above the order summary.
            try {
                var existing = document.querySelector( '.tejcart-checkout-drift-banner' );
                if ( existing ) { return; }
                var host = document.querySelector( '[data-tejcart-order-summary]' )
                        || document.querySelector( '.tejcart-checkout__summary' );
                if ( ! host ) { return; }
                var banner = document.createElement( 'div' );
                banner.className = 'tejcart-checkout-drift-banner';
                banner.setAttribute( 'role', 'status' );
                banner.textContent = 'Your cart was updated in another tab — please review your order before placing it.';
                host.parentNode.insertBefore( banner, host );
            } catch ( _e ) { /* ignore */ }
        }

        try {
            window.addEventListener( 'storage', function ( ev ) {
                if ( ! ev || ev.key !== BUMP_KEY ) { return; }
                if ( ev.newValue === lastSeen ) { return; }
                lastSeen = ev.newValue || '';
                refreshSummary( 'cross-tab-mutation' );
            } );
        } catch ( _e ) { /* ignore */ }

        try {
            document.addEventListener( 'visibilitychange', function () {
                if ( document.visibilityState !== 'visible' ) { return; }
                var current = '';
                try { current = window.localStorage.getItem( BUMP_KEY ) || ''; } catch ( _e ) { /* ignore */ }
                if ( current && current !== lastSeen ) {
                    lastSeen = current;
                    refreshSummary( 'visibility-resync' );
                }
            } );
        } catch ( _e ) { /* ignore */ }
    }

    /*
     * IANA time-zone → ISO-3166-1 alpha-2 country.
     *
     * The browser time zone reflects where the visitor physically is,
     * independent of their UI language — unlike navigator.language, which
     * is a *language* preference (an en-US Chrome install in India would
     * otherwise resolve to "US"). This is the primary, most accurate signal
     * for the buyer's country and runs entirely in the browser, so it stays
     * compatible with full-page caching (the server still renders the stable
     * store default; this just corrects it client-side).
     *
     * Countries that span multiple sub-zones (Argentina, US Indiana/Kentucky/
     * North_Dakota, all of Australia) are handled by prefix in
     * countryFromTimeZone() so the table below stays single-entry per zone.
     */
    var TZ_COUNTRY = {
        'Africa/Abidjan': 'CI', 'Africa/Accra': 'GH', 'Africa/Addis_Ababa': 'ET', 'Africa/Algiers': 'DZ',
        'Africa/Asmara': 'ER', 'Africa/Bamako': 'ML', 'Africa/Bangui': 'CF', 'Africa/Banjul': 'GM',
        'Africa/Bissau': 'GW', 'Africa/Blantyre': 'MW', 'Africa/Brazzaville': 'CG', 'Africa/Bujumbura': 'BI',
        'Africa/Cairo': 'EG', 'Africa/Casablanca': 'MA', 'Africa/Ceuta': 'ES', 'Africa/Conakry': 'GN',
        'Africa/Dakar': 'SN', 'Africa/Dar_es_Salaam': 'TZ', 'Africa/Djibouti': 'DJ', 'Africa/Douala': 'CM',
        'Africa/El_Aaiun': 'EH', 'Africa/Freetown': 'SL', 'Africa/Gaborone': 'BW', 'Africa/Harare': 'ZW',
        'Africa/Johannesburg': 'ZA', 'Africa/Juba': 'SS', 'Africa/Kampala': 'UG', 'Africa/Khartoum': 'SD',
        'Africa/Kigali': 'RW', 'Africa/Kinshasa': 'CD', 'Africa/Lagos': 'NG', 'Africa/Libreville': 'GA',
        'Africa/Lome': 'TG', 'Africa/Luanda': 'AO', 'Africa/Lubumbashi': 'CD', 'Africa/Lusaka': 'ZM',
        'Africa/Malabo': 'GQ', 'Africa/Maputo': 'MZ', 'Africa/Maseru': 'LS', 'Africa/Mbabane': 'SZ',
        'Africa/Mogadishu': 'SO', 'Africa/Monrovia': 'LR', 'Africa/Nairobi': 'KE', 'Africa/Ndjamena': 'TD',
        'Africa/Niamey': 'NE', 'Africa/Nouakchott': 'MR', 'Africa/Ouagadougou': 'BF', 'Africa/Porto-Novo': 'BJ',
        'Africa/Sao_Tome': 'ST', 'Africa/Tripoli': 'LY', 'Africa/Tunis': 'TN', 'Africa/Windhoek': 'NA',
        'America/Adak': 'US', 'America/Anchorage': 'US', 'America/Anguilla': 'AI', 'America/Antigua': 'AG',
        'America/Araguaina': 'BR', 'America/Aruba': 'AW', 'America/Asuncion': 'PY', 'America/Atikokan': 'CA',
        'America/Bahia': 'BR', 'America/Bahia_Banderas': 'MX', 'America/Barbados': 'BB', 'America/Belem': 'BR',
        'America/Belize': 'BZ', 'America/Blanc-Sablon': 'CA', 'America/Boa_Vista': 'BR', 'America/Bogota': 'CO',
        'America/Boise': 'US', 'America/Cambridge_Bay': 'CA', 'America/Campo_Grande': 'BR', 'America/Cancun': 'MX',
        'America/Caracas': 'VE', 'America/Cayenne': 'GF', 'America/Cayman': 'KY', 'America/Chicago': 'US',
        'America/Chihuahua': 'MX', 'America/Ciudad_Juarez': 'MX', 'America/Costa_Rica': 'CR', 'America/Creston': 'CA',
        'America/Cuiaba': 'BR', 'America/Curacao': 'CW', 'America/Danmarkshavn': 'GL', 'America/Dawson': 'CA',
        'America/Dawson_Creek': 'CA', 'America/Denver': 'US', 'America/Detroit': 'US', 'America/Dominica': 'DM',
        'America/Edmonton': 'CA', 'America/Eirunepe': 'BR', 'America/El_Salvador': 'SV', 'America/Fort_Nelson': 'CA',
        'America/Fortaleza': 'BR', 'America/Glace_Bay': 'CA', 'America/Goose_Bay': 'CA', 'America/Grand_Turk': 'TC',
        'America/Grenada': 'GD', 'America/Guadeloupe': 'GP', 'America/Guatemala': 'GT', 'America/Guayaquil': 'EC',
        'America/Guyana': 'GY', 'America/Halifax': 'CA', 'America/Havana': 'CU', 'America/Hermosillo': 'MX',
        'America/Inuvik': 'CA', 'America/Iqaluit': 'CA', 'America/Jamaica': 'JM', 'America/Juneau': 'US',
        'America/Kralendijk': 'BQ', 'America/La_Paz': 'BO', 'America/Lima': 'PE', 'America/Los_Angeles': 'US',
        'America/Lower_Princes': 'SX', 'America/Maceio': 'BR', 'America/Managua': 'NI', 'America/Manaus': 'BR',
        'America/Marigot': 'MF', 'America/Martinique': 'MQ', 'America/Matamoros': 'MX', 'America/Mazatlan': 'MX',
        'America/Menominee': 'US', 'America/Merida': 'MX', 'America/Metlakatla': 'US', 'America/Mexico_City': 'MX',
        'America/Miquelon': 'PM', 'America/Moncton': 'CA', 'America/Monterrey': 'MX', 'America/Montevideo': 'UY',
        'America/Montserrat': 'MS', 'America/Nassau': 'BS', 'America/New_York': 'US', 'America/Nome': 'US',
        'America/Noronha': 'BR', 'America/Nuuk': 'GL', 'America/Ojinaga': 'MX', 'America/Panama': 'PA',
        'America/Paramaribo': 'SR', 'America/Phoenix': 'US', 'America/Port-au-Prince': 'HT', 'America/Port_of_Spain': 'TT',
        'America/Porto_Velho': 'BR', 'America/Puerto_Rico': 'PR', 'America/Punta_Arenas': 'CL', 'America/Rankin_Inlet': 'CA',
        'America/Recife': 'BR', 'America/Regina': 'CA', 'America/Resolute': 'CA', 'America/Rio_Branco': 'BR',
        'America/Santarem': 'BR', 'America/Santiago': 'CL', 'America/Santo_Domingo': 'DO', 'America/Sao_Paulo': 'BR',
        'America/Scoresbysund': 'GL', 'America/Sitka': 'US', 'America/St_Barthelemy': 'BL', 'America/St_Johns': 'CA',
        'America/St_Kitts': 'KN', 'America/St_Lucia': 'LC', 'America/St_Thomas': 'VI', 'America/St_Vincent': 'VC',
        'America/Swift_Current': 'CA', 'America/Tegucigalpa': 'HN', 'America/Thule': 'GL', 'America/Tijuana': 'MX',
        'America/Toronto': 'CA', 'America/Tortola': 'VG', 'America/Vancouver': 'CA', 'America/Whitehorse': 'CA',
        'America/Winnipeg': 'CA', 'America/Yakutat': 'US',
        'Arctic/Longyearbyen': 'SJ',
        'Asia/Aden': 'YE', 'Asia/Almaty': 'KZ', 'Asia/Amman': 'JO', 'Asia/Anadyr': 'RU', 'Asia/Aqtau': 'KZ',
        'Asia/Aqtobe': 'KZ', 'Asia/Ashgabat': 'TM', 'Asia/Atyrau': 'KZ', 'Asia/Baghdad': 'IQ', 'Asia/Bahrain': 'BH',
        'Asia/Baku': 'AZ', 'Asia/Bangkok': 'TH', 'Asia/Barnaul': 'RU', 'Asia/Beirut': 'LB', 'Asia/Bishkek': 'KG',
        'Asia/Brunei': 'BN', 'Asia/Calcutta': 'IN', 'Asia/Chita': 'RU', 'Asia/Choibalsan': 'MN', 'Asia/Colombo': 'LK',
        'Asia/Damascus': 'SY', 'Asia/Dhaka': 'BD', 'Asia/Dili': 'TL', 'Asia/Dubai': 'AE', 'Asia/Dushanbe': 'TJ',
        'Asia/Famagusta': 'CY', 'Asia/Gaza': 'PS', 'Asia/Hebron': 'PS', 'Asia/Ho_Chi_Minh': 'VN', 'Asia/Hong_Kong': 'HK',
        'Asia/Hovd': 'MN', 'Asia/Irkutsk': 'RU', 'Asia/Jakarta': 'ID', 'Asia/Jayapura': 'ID', 'Asia/Jerusalem': 'IL',
        'Asia/Kabul': 'AF', 'Asia/Kamchatka': 'RU', 'Asia/Karachi': 'PK', 'Asia/Kathmandu': 'NP', 'Asia/Katmandu': 'NP',
        'Asia/Khandyga': 'RU', 'Asia/Kolkata': 'IN', 'Asia/Krasnoyarsk': 'RU', 'Asia/Kuala_Lumpur': 'MY',
        'Asia/Kuching': 'MY', 'Asia/Kuwait': 'KW', 'Asia/Macau': 'MO', 'Asia/Magadan': 'RU', 'Asia/Makassar': 'ID',
        'Asia/Manila': 'PH', 'Asia/Muscat': 'OM', 'Asia/Nicosia': 'CY', 'Asia/Novokuznetsk': 'RU', 'Asia/Novosibirsk': 'RU',
        'Asia/Omsk': 'RU', 'Asia/Oral': 'KZ', 'Asia/Phnom_Penh': 'KH', 'Asia/Pontianak': 'ID', 'Asia/Pyongyang': 'KP',
        'Asia/Qatar': 'QA', 'Asia/Qostanay': 'KZ', 'Asia/Qyzylorda': 'KZ', 'Asia/Rangoon': 'MM', 'Asia/Riyadh': 'SA',
        'Asia/Saigon': 'VN', 'Asia/Sakhalin': 'RU', 'Asia/Samarkand': 'UZ', 'Asia/Seoul': 'KR', 'Asia/Shanghai': 'CN',
        'Asia/Singapore': 'SG', 'Asia/Srednekolymsk': 'RU', 'Asia/Taipei': 'TW', 'Asia/Tashkent': 'UZ', 'Asia/Tbilisi': 'GE',
        'Asia/Tehran': 'IR', 'Asia/Thimphu': 'BT', 'Asia/Tokyo': 'JP', 'Asia/Tomsk': 'RU', 'Asia/Ulaanbaatar': 'MN',
        'Asia/Urumqi': 'CN', 'Asia/Ust-Nera': 'RU', 'Asia/Vientiane': 'LA', 'Asia/Vladivostok': 'RU', 'Asia/Yakutsk': 'RU',
        'Asia/Yangon': 'MM', 'Asia/Yekaterinburg': 'RU', 'Asia/Yerevan': 'AM',
        'Atlantic/Azores': 'PT', 'Atlantic/Bermuda': 'BM', 'Atlantic/Canary': 'ES', 'Atlantic/Cape_Verde': 'CV',
        'Atlantic/Faroe': 'FO', 'Atlantic/Madeira': 'PT', 'Atlantic/Reykjavik': 'IS', 'Atlantic/South_Georgia': 'GS',
        'Atlantic/St_Helena': 'SH', 'Atlantic/Stanley': 'FK',
        'Europe/Amsterdam': 'NL', 'Europe/Andorra': 'AD', 'Europe/Astrakhan': 'RU', 'Europe/Athens': 'GR',
        'Europe/Belgrade': 'RS', 'Europe/Berlin': 'DE', 'Europe/Bratislava': 'SK', 'Europe/Brussels': 'BE',
        'Europe/Bucharest': 'RO', 'Europe/Budapest': 'HU', 'Europe/Busingen': 'DE', 'Europe/Chisinau': 'MD',
        'Europe/Copenhagen': 'DK', 'Europe/Dublin': 'IE', 'Europe/Gibraltar': 'GI', 'Europe/Guernsey': 'GG',
        'Europe/Helsinki': 'FI', 'Europe/Isle_of_Man': 'IM', 'Europe/Istanbul': 'TR', 'Europe/Jersey': 'JE',
        'Europe/Kaliningrad': 'RU', 'Europe/Kiev': 'UA', 'Europe/Kyiv': 'UA', 'Europe/Lisbon': 'PT',
        'Europe/Ljubljana': 'SI', 'Europe/London': 'GB', 'Europe/Luxembourg': 'LU', 'Europe/Madrid': 'ES',
        'Europe/Malta': 'MT', 'Europe/Mariehamn': 'AX', 'Europe/Minsk': 'BY', 'Europe/Monaco': 'MC',
        'Europe/Moscow': 'RU', 'Europe/Oslo': 'NO', 'Europe/Paris': 'FR', 'Europe/Podgorica': 'ME',
        'Europe/Prague': 'CZ', 'Europe/Riga': 'LV', 'Europe/Rome': 'IT', 'Europe/Samara': 'RU',
        'Europe/San_Marino': 'SM', 'Europe/Sarajevo': 'BA', 'Europe/Saratov': 'RU', 'Europe/Simferopol': 'UA',
        'Europe/Skopje': 'MK', 'Europe/Sofia': 'BG', 'Europe/Stockholm': 'SE', 'Europe/Tallinn': 'EE',
        'Europe/Tirane': 'AL', 'Europe/Ulyanovsk': 'RU', 'Europe/Vaduz': 'LI', 'Europe/Vatican': 'VA',
        'Europe/Vienna': 'AT', 'Europe/Vilnius': 'LT', 'Europe/Volgograd': 'RU', 'Europe/Warsaw': 'PL',
        'Europe/Zagreb': 'HR', 'Europe/Zurich': 'CH',
        'Indian/Antananarivo': 'MG', 'Indian/Chagos': 'IO', 'Indian/Christmas': 'CX', 'Indian/Cocos': 'CC',
        'Indian/Comoro': 'KM', 'Indian/Kerguelen': 'TF', 'Indian/Mahe': 'SC', 'Indian/Maldives': 'MV',
        'Indian/Mauritius': 'MU', 'Indian/Mayotte': 'YT', 'Indian/Reunion': 'RE',
        'Pacific/Apia': 'WS', 'Pacific/Auckland': 'NZ', 'Pacific/Bougainville': 'PG', 'Pacific/Chatham': 'NZ',
        'Pacific/Chuuk': 'FM', 'Pacific/Easter': 'CL', 'Pacific/Efate': 'VU', 'Pacific/Fakaofo': 'TK',
        'Pacific/Fiji': 'FJ', 'Pacific/Funafuti': 'TV', 'Pacific/Galapagos': 'EC', 'Pacific/Gambier': 'PF',
        'Pacific/Guadalcanal': 'SB', 'Pacific/Guam': 'GU', 'Pacific/Honolulu': 'US', 'Pacific/Kanton': 'KI',
        'Pacific/Kiritimati': 'KI', 'Pacific/Kosrae': 'FM', 'Pacific/Kwajalein': 'MH', 'Pacific/Majuro': 'MH',
        'Pacific/Marquesas': 'PF', 'Pacific/Midway': 'UM', 'Pacific/Nauru': 'NR', 'Pacific/Niue': 'NU',
        'Pacific/Norfolk': 'NF', 'Pacific/Noumea': 'NC', 'Pacific/Pago_Pago': 'AS', 'Pacific/Palau': 'PW',
        'Pacific/Pitcairn': 'PN', 'Pacific/Port_Moresby': 'PG', 'Pacific/Rarotonga': 'CK', 'Pacific/Saipan': 'MP',
        'Pacific/Tahiti': 'PF', 'Pacific/Tarawa': 'KI', 'Pacific/Tongatapu': 'TO', 'Pacific/Wake': 'UM',
        'Pacific/Wallis': 'WF'
    };

    // Multi-zone countries: matched by zone prefix so the table above can stay
    // one row per zone (every Australian zone is AU; the Argentina / US-state
    // sub-zone families resolve to a single country).
    var TZ_COUNTRY_PREFIX = [
        [ 'America/Argentina/', 'AR' ],
        [ 'America/Indiana/', 'US' ],
        [ 'America/Kentucky/', 'US' ],
        [ 'America/North_Dakota/', 'US' ],
        [ 'Australia/', 'AU' ]
    ];

    function countryFromTimeZone() {
        var zone = '';
        try {
            if ( window.Intl && Intl.DateTimeFormat ) {
                zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            }
        } catch ( e ) { return ''; }

        if ( ! zone ) { return ''; }

        if ( TZ_COUNTRY[ zone ] ) {
            return TZ_COUNTRY[ zone ];
        }

        for ( var i = 0; i < TZ_COUNTRY_PREFIX.length; i++ ) {
            if ( zone.indexOf( TZ_COUNTRY_PREFIX[ i ][ 0 ] ) === 0 ) {
                return TZ_COUNTRY_PREFIX[ i ][ 1 ];
            }
        }

        return '';
    }

    // Last-resort country guess from the UI language tag (e.g. en-GB → GB).
    // Only used when the time zone is unknown, because a language tag reflects
    // preferred language, not location (en-US is the default on countless
    // browsers worldwide and must not be treated as "United States").
    function countryFromLanguage() {
        if ( ! navigator || ! navigator.language ) { return ''; }
        var match = navigator.language.match( /-([A-Za-z]{2})$/ );
        return match ? match[ 1 ].toUpperCase() : '';
    }

    function detectBuyerCountry() {
        return countryFromTimeZone() || countryFromLanguage();
    }

    function initBrowserLocaleCountry() {
        if ( ! form ) { return; }
        if ( checkoutParams && checkoutParams.disableLocaleCountry ) { return; }

        var countryCode = detectBuyerCountry();
        if ( ! countryCode ) { return; }

        form.querySelectorAll( '[data-tejcart-country-field] select' ).forEach( function ( select ) {
            if ( select.dataset.tejcartLocaleApplied === '1' ) { return; }
            if ( select.getAttribute( 'data-tejcart-touched' ) === '1' ) { return; }

            // The server marks wrappers whose default came from the
            // logged-in customer's saved address. A browser-locale guess
            // must NEVER overwrite an address the customer explicitly
            // saved — without this guard a buyer with a saved Indian
            // address sees "India" flash, then snap to "United States"
            // on first paint.
            var wrapper = select.closest( '[data-tejcart-country-field]' );
            if ( wrapper && wrapper.getAttribute( 'data-tejcart-default-source' ) === 'saved-address' ) {
                return;
            }

            var hasOption = !! select.querySelector( 'option[value="' + countryCode + '"]' );
            if ( ! hasOption ) { return; }
            if ( select.value === countryCode ) { return; }

            select.value = countryCode;
            select.dataset.tejcartLocaleApplied = '1';
        } );
    }

    function initOrderNotesDisclosure() {
        if ( ! form ) { return; }

        var wrapper = form.querySelector( '[data-tejcart-notes]' );
        if ( ! wrapper ) { return; }

        var toggle  = wrapper.querySelector( '.tejcart-checkout-notes-toggle' );
        var body    = wrapper.querySelector( '.tejcart-checkout-notes-body' );
        if ( ! toggle || ! body ) { return; }

        var existing = body.querySelector( 'textarea, input' );
        if ( existing && existing.value && existing.value.trim() !== '' ) {
            body.removeAttribute( 'hidden' );
            toggle.setAttribute( 'aria-expanded', 'true' );
            toggle.style.display = 'none';
            return;
        }

        toggle.addEventListener( 'click', function () {
            body.removeAttribute( 'hidden' );
            toggle.setAttribute( 'aria-expanded', 'true' );
            toggle.style.display = 'none';
            var input = body.querySelector( 'textarea, input' );
            if ( input ) {
                try { input.focus( { preventScroll: true } ); } catch ( e ) { input.focus(); }
            }
        } );
    }

    function initAccountPasswordReveal() {
        if ( ! form ) { return; }

        var checkbox = form.querySelector( '#create_account' );
        var pwRow    = form.querySelector( '#account_password_field' );
        if ( ! checkbox || ! pwRow ) { return; }

        function syncVisibility() {
            if ( checkbox.checked ) {
                pwRow.removeAttribute( 'hidden' );
                pwRow.classList.remove( 'is-hidden' );
            } else {
                pwRow.setAttribute( 'hidden', 'hidden' );
                pwRow.classList.add( 'is-hidden' );
                var pwInput = pwRow.querySelector( 'input[type="password"]' );
                if ( pwInput ) { pwInput.value = ''; }
            }
        }

        checkbox.addEventListener( 'change', syncVisibility );
        syncVisibility();
    }

    var progressiveConfig = [
        { key: 'billing_company',  label: 'Add company name' },
        { key: 'shipping_company', label: 'Add company name' }
    ];

    function initProgressiveFields() {
        if ( ! form ) { return; }

        progressiveConfig.forEach( function ( cfg ) {
            var wrapper = document.getElementById( cfg.key + '_field' );
            if ( ! wrapper ) { return; }

            var input = wrapper.querySelector( '[name="' + cfg.key + '"]' );
            var hasValue = !! ( input && input.value && input.value.trim() );

            var toggle = document.createElement( 'button' );
            toggle.type = 'button';
            toggle.className = 'tejcart-progressive-toggle';
            toggle.setAttribute( 'aria-expanded', hasValue ? 'true' : 'false' );
            toggle.setAttribute( 'aria-controls', cfg.key + '_field' );

            toggle.textContent = cfg.label;

            wrapper.parentNode.insertBefore( toggle, wrapper );

            function expand() {
                wrapper.style.display = '';
                wrapper.removeAttribute( 'hidden' );
                toggle.style.display = 'none';
                toggle.setAttribute( 'aria-expanded', 'true' );
                var focusEl = wrapper.querySelector( 'input, select, textarea' );
                if ( focusEl ) {
                    try { focusEl.focus( { preventScroll: true } ); } catch ( e ) { focusEl.focus(); }
                }
            }

            toggle.addEventListener( 'click', expand );

            if ( hasValue ) {
                toggle.style.display = 'none';
                wrapper.style.display = '';
            } else {
                wrapper.style.display = 'none';
            }
        } );
    }

    function getStatesFor( countryCode ) {
        if ( ! countryCode ) { return {}; }
        var all = checkoutParams.states || {};
        return all[ countryCode ] || {};
    }

    function replaceStateField( wrapper, countryCode ) {
        if ( ! wrapper ) { return; }
        var existing = wrapper.querySelector( 'input, select' );
        if ( ! existing ) { return; }

        var fieldKey = wrapper.getAttribute( 'data-tejcart-state-field' ) || existing.name;
        var states   = getStatesFor( countryCode );
        var stateCodes = Object.keys( states );
        var currentVal = existing.value || '';
        var required = existing.hasAttribute( 'required' );
        var autocomplete = existing.getAttribute( 'autocomplete' ) || '';
        var ariaDescribedBy = existing.getAttribute( 'aria-describedby' ) || '';
        var className = 'tejcart-input tejcart-field-input';

        var STATELESS = wrapper.getAttribute( 'data-tejcart-stateless-countries' );
        var statelessList = STATELESS ? STATELESS.split( ',' ) : [ 'SG', 'HK', 'MO', 'BH', 'QA', 'KW', 'LU', 'MT', 'AD', 'MC' ];
        if ( countryCode && stateCodes.length === 0 && statelessList.indexOf( countryCode ) !== -1 ) {
            wrapper.setAttribute( 'hidden', 'hidden' );
            wrapper.classList.add( 'is-stateless-hidden' );
            existing.removeAttribute( 'required' );
            existing.removeAttribute( 'aria-required' );
            return;
        }
        wrapper.removeAttribute( 'hidden' );
        wrapper.classList.remove( 'is-stateless-hidden' );

        // Keep the state placeholder short and country-agnostic. Embedding
        // the country label here produced overflowing strings like
        // "Select United States (US) state / province" that looked broken;
        // the linked country field already makes the context obvious.
        var placeholderText = ( checkoutParams.i18n && checkoutParams.i18n.select_state )
            ? checkoutParams.i18n.select_state
            : 'Select a state / province';

        if ( stateCodes.length > 0 ) {
            if ( existing.tagName.toLowerCase() === 'select' ) {
                existing.innerHTML = '';
            } else {
                var select = document.createElement( 'select' );
                select.id = fieldKey;
                select.name = fieldKey;
                select.className = className + ' tejcart-input-select tejcart-field-select';
                if ( required ) { select.setAttribute( 'required', 'required' ); select.setAttribute( 'aria-required', 'true' ); }
                if ( autocomplete ) { select.setAttribute( 'autocomplete', autocomplete ); }
                if ( ariaDescribedBy ) { select.setAttribute( 'aria-describedby', ariaDescribedBy ); }
                existing.parentNode.replaceChild( select, existing );
                existing = select;
            }

            var placeholder = document.createElement( 'option' );
            placeholder.value = '';
            placeholder.textContent = placeholderText;
            existing.appendChild( placeholder );

            stateCodes.forEach( function ( code ) {
                var opt = document.createElement( 'option' );
                opt.value = code;
                opt.textContent = states[ code ];
                if ( code === currentVal ) { opt.selected = true; }
                existing.appendChild( opt );
            } );
        } else if ( existing.tagName.toLowerCase() === 'select' ) {
            var input = document.createElement( 'input' );
            input.type = 'text';
            input.id = fieldKey;
            input.name = fieldKey;
            input.className = className + ' tejcart-input-text';
            input.value = currentVal;
            if ( required ) { input.setAttribute( 'required', 'required' ); input.setAttribute( 'aria-required', 'true' ); }
            if ( autocomplete ) { input.setAttribute( 'autocomplete', autocomplete ); }
            if ( ariaDescribedBy ) { input.setAttribute( 'aria-describedby', ariaDescribedBy ); }
            existing.parentNode.replaceChild( input, existing );
        }
    }

    function initCountryStateSync() {
        if ( ! form ) { return; }

        var countryFields = form.querySelectorAll( '[data-tejcart-country-field]' );
        countryFields.forEach( function ( countryWrapper ) {
            var select = countryWrapper.querySelector( 'select' );
            if ( ! select ) { return; }

            var scope = countryWrapper.closest( '[data-tejcart-address-scope]' );
            var stateWrapper = scope
                ? scope.querySelector( '[data-tejcart-state-field]' )
                : null;
            if ( ! stateWrapper ) { return; }

            replaceStateField( stateWrapper, select.value );

            select.addEventListener( 'change', function () {
                replaceStateField( stateWrapper, select.value );
                queueShippingRefresh();
            } );
        } );
    }

    /*
     * ---------------------------------------------------------------------
     * Address autocomplete (provider-neutral driver).
     *
     * Core ships only this inert consumer. It does nothing unless something
     * (the bundled `address-autocomplete` optional module, or a third-party
     * `tejcart_address_autocomplete_config` filter) supplies a provider
     * config via the localized `addressAutocomplete` param. Today the driver
     * understands the 'google' provider.
     *
     * Google integration uses the Places API (New) — AutocompleteSuggestion
     * + the Place class — rendered into our own styled dropdown so it matches
     * the checkout and returns every place type (street addresses, societies,
     * commercial buildings). The legacy google.maps.places.Autocomplete
     * widget was deprecated by Google in 2025 and returns incomplete
     * predictions on projects created afterwards.
     *
     * The driver lives in core — rather than the module — because filling the
     * address fields has to cooperate with core internals: the country→state
     * rebuild (replaceStateField) and the debounced shipping/tax refresh
     * (queueShippingRefresh). Keeping it here avoids a module reaching across
     * the boundary into private checkout state.
     * ---------------------------------------------------------------------
     */
    var googleMapsLoading   = false;
    var googleMapsReady     = false;
    var googleMapsCallbacks = [];

    function initAddressAutocomplete() {
        if ( ! form ) { return; }
        var cfg = checkoutParams.addressAutocomplete;
        if ( ! cfg || ! cfg.provider ) { return; }

        if ( 'google' === cfg.provider && cfg.apiKey ) {
            loadGoogleMaps( cfg.apiKey, setupGooglePlaces );
        }
    }

    function getAddressLine1Inputs() {
        if ( ! form ) { return []; }
        return Array.prototype.slice.call(
            form.querySelectorAll( 'input[name$="_address_1"]' )
        );
    }

    function loadGoogleMaps( apiKey, onReady ) {
        if ( googleMapsReady ) { onReady(); return; }
        if ( ! getAddressLine1Inputs().length ) { return; }

        googleMapsCallbacks.push( onReady );
        if ( googleMapsLoading ) { return; }
        googleMapsLoading = true;

        // Google's loader requires a global named callback.
        window.tejcartGoogleMapsReady = function () {
            googleMapsReady = true;
            var pending = googleMapsCallbacks.slice();
            googleMapsCallbacks = [];
            pending.forEach( function ( cb ) {
                try { cb(); } catch ( e ) {}
            } );
        };

        var script = document.createElement( 'script' );
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' +
            encodeURIComponent( apiKey ) +
            '&libraries=places&loading=async&v=weekly&callback=tejcartGoogleMapsReady';
        script.async = true;
        script.defer = true;
        script.onerror = function () {
            // Leave the manual fields fully usable if the SDK fails to load.
            googleMapsLoading = false;
        };
        document.head.appendChild( script );
    }

    function setupGooglePlaces() {
        if ( ! window.google || ! window.google.maps || ! window.google.maps.places ) { return; }
        var places = window.google.maps.places;

        // Requires the Places API (New). If unavailable (very old SDK), leave
        // the field as a plain manual input rather than throwing.
        if ( ! places.AutocompleteSuggestion || ! places.AutocompleteSessionToken ) { return; }

        getAddressLine1Inputs().forEach( function ( input ) {
            attachPlacesAutocomplete( input, places );
        } );
    }

    function attachPlacesAutocomplete( input, places ) {
        if ( input.getAttribute( 'data-tejcart-autocomplete-bound' ) ) { return; }
        input.setAttribute( 'data-tejcart-autocomplete-bound', '1' );

        var scope = input.closest( '[data-tejcart-address-scope]' );
        var wrap  = input.closest( '.tejcart-field-input-wrap' ) || input.parentNode;
        if ( wrap && wrap.style ) { wrap.style.position = 'relative'; }

        var dropdown = document.createElement( 'div' );
        dropdown.className = 'tejcart-ac-dropdown';
        dropdown.setAttribute( 'role', 'listbox' );
        dropdown.hidden = true;
        wrap.appendChild( dropdown );

        // Native browser autofill would fight our custom list, so turn it off
        // on this field and wire the ARIA combobox roles instead.
        input.setAttribute( 'autocomplete', 'off' );
        input.setAttribute( 'role', 'combobox' );
        input.setAttribute( 'aria-autocomplete', 'list' );
        input.setAttribute( 'aria-expanded', 'false' );

        var token       = new places.AutocompleteSessionToken();
        var suggestions = [];
        var activeIndex = -1;
        var debounce    = null;
        // True while we programmatically write the chosen address back into
        // the field. Selecting a suggestion sets input.value, which fires an
        // `input` event — without this guard our own handler would treat that
        // as the buyer typing and immediately re-open the dropdown.
        var filling     = false;

        function regionCodes() {
            var c = scope ? readScopeField( scope, '_country' ) : '';
            return c ? [ c.toLowerCase() ] : undefined;
        }

        function close() {
            clearTimeout( debounce );
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            input.setAttribute( 'aria-expanded', 'false' );
            input.removeAttribute( 'aria-activedescendant' );
            suggestions = [];
            activeIndex = -1;
        }

        function setActive( idx ) {
            var items = dropdown.querySelectorAll( '.tejcart-ac-item' );
            for ( var i = 0; i < items.length; i++ ) {
                items[ i ].classList.toggle( 'is-active', i === idx );
            }
            activeIndex = idx;
            if ( idx >= 0 && items[ idx ] ) {
                input.setAttribute( 'aria-activedescendant', items[ idx ].id );
                items[ idx ].scrollIntoView( { block: 'nearest' } );
            } else {
                input.removeAttribute( 'aria-activedescendant' );
            }
        }

        function render() {
            dropdown.innerHTML = '';
            if ( ! suggestions.length ) { close(); return; }

            suggestions.forEach( function ( s, i ) {
                var pred = s.placePrediction;
                var item = document.createElement( 'div' );
                item.className = 'tejcart-ac-item';
                item.id = 'tejcart-ac-' + ( input.id || 'addr' ) + '-' + i;
                item.setAttribute( 'role', 'option' );

                var main = document.createElement( 'span' );
                main.className = 'tejcart-ac-item-main';
                main.textContent = ( pred.mainText && pred.mainText.text )
                    ? pred.mainText.text
                    : ( pred.text && pred.text.text ? pred.text.text : '' );
                item.appendChild( main );

                if ( pred.secondaryText && pred.secondaryText.text ) {
                    var sec = document.createElement( 'span' );
                    sec.className = 'tejcart-ac-item-secondary';
                    sec.textContent = pred.secondaryText.text;
                    item.appendChild( sec );
                }

                // mousedown (not click) so the selection runs before the
                // input's blur handler closes the list.
                item.addEventListener( 'mousedown', function ( e ) {
                    e.preventDefault();
                    choose( i );
                } );
                item.addEventListener( 'mousemove', function () { setActive( i ); } );

                dropdown.appendChild( item );
            } );

            // Places API policy: attribution is required when predictions are
            // shown without an accompanying Google map.
            var credit = document.createElement( 'div' );
            credit.className = 'tejcart-ac-powered';
            credit.textContent = 'Powered by Google';
            dropdown.appendChild( credit );

            dropdown.hidden = false;
            input.setAttribute( 'aria-expanded', 'true' );
            setActive( -1 );
        }

        function fetchSuggestions( query ) {
            var request = { input: query, sessionToken: token };
            var codes = regionCodes();
            if ( codes ) { request.includedRegionCodes = codes; }

            places.AutocompleteSuggestion.fetchAutocompleteSuggestions( request ).then( function ( res ) {
                if ( ( input.value || '' ).trim() !== query ) { return; } // stale response
                suggestions = ( res && res.suggestions )
                    ? res.suggestions.filter( function ( s ) { return !! s.placePrediction; } )
                    : [];
                render();
            } ).catch( function () { close(); } );
        }

        function choose( idx ) {
            var s = suggestions[ idx ];
            if ( ! s || ! s.placePrediction ) { return; }
            var place = s.placePrediction.toPlace();
            place.fetchFields( { fields: [ 'addressComponents', 'displayName', 'types' ] } ).then( function () {
                // Close first so the synchronous `input` events fired while
                // writing the fields can't re-open the list, and flag the
                // write so the input handler ignores them.
                filling = true;
                close();
                fillScopeFromPlace( scope, place );
                filling = false;
                // A selection ends the billing session; start a fresh token.
                token = new places.AutocompleteSessionToken();
            } ).catch( function () { close(); } );
        }

        input.addEventListener( 'input', function () {
            if ( filling ) { return; }
            var q = ( input.value || '' ).trim();
            clearTimeout( debounce );
            if ( q.length < 3 ) { close(); return; }
            debounce = setTimeout( function () { fetchSuggestions( q ); }, 200 );
        } );

        input.addEventListener( 'keydown', function ( e ) {
            if ( dropdown.hidden ) { return; }
            if ( 'ArrowDown' === e.key ) {
                e.preventDefault();
                setActive( Math.min( activeIndex + 1, suggestions.length - 1 ) );
            } else if ( 'ArrowUp' === e.key ) {
                e.preventDefault();
                setActive( Math.max( activeIndex - 1, 0 ) );
            } else if ( 'Enter' === e.key ) {
                if ( activeIndex >= 0 ) { e.preventDefault(); choose( activeIndex ); }
            } else if ( 'Escape' === e.key ) {
                close();
            }
        } );

        // Delay the close so a mousedown on an item is processed first.
        input.addEventListener( 'blur', function () {
            setTimeout( close, 150 );
        } );
    }

    function fillScopeFromPlace( scope, place ) {
        if ( ! scope || ! place ) { return; }

        // Places API (New) Place objects expose `addressComponents` with
        // `longText` / `shortText` / `types` (camelCase), unlike the legacy
        // `address_components` / `long_name` shape.
        var components = place.addressComponents || [];

        var pick = function ( type, useShort ) {
            for ( var i = 0; i < components.length; i++ ) {
                var types = components[ i ].types || [];
                if ( types.indexOf( type ) !== -1 ) {
                    return useShort
                        ? ( components[ i ].shortText || '' )
                        : ( components[ i ].longText || '' );
                }
            }
            return '';
        };

        var streetNumber = pick( 'street_number', false );
        var route        = pick( 'route', false );
        var city         = pick( 'locality', false ) || pick( 'postal_town', false ) ||
            pick( 'sublocality', false ) || pick( 'sublocality_level_1', false ) ||
            pick( 'administrative_area_level_2', false );
        var stateCode    = pick( 'administrative_area_level_1', true );
        var postcode     = pick( 'postal_code', false );
        var country      = pick( 'country', true );
        var street       = ( streetNumber + ' ' + route ).trim();

        // The premise / business name (e.g. "Gala Haven") lives in
        // `displayName`, outside the address components. Keep it on the street
        // line so it isn't lost: prepend it to the road when one exists
        // ("Gala Haven, Sola Road"), or use it alone when the premise has no
        // street number/road. Plain street addresses aren't flagged as a named
        // place, so this never duplicates a normal house-number line.
        var displayName = '';
        if ( place.displayName ) {
            displayName = ( 'object' === typeof place.displayName && place.displayName.text )
                ? place.displayName.text
                : String( place.displayName );
        }
        var isNamedPlace = false;
        var placeTypes = place.types || [];
        for ( var t = 0; t < placeTypes.length; t++ ) {
            if ( 'establishment' === placeTypes[ t ] || 'point_of_interest' === placeTypes[ t ] ||
                 'premise' === placeTypes[ t ] || 'subpremise' === placeTypes[ t ] ) {
                isNamedPlace = true;
                break;
            }
        }
        if ( displayName && isNamedPlace ) {
            street = street ? ( displayName + ', ' + street ) : displayName;
        } else if ( ! street && displayName ) {
            street = displayName;
        }

        // Country first: setting it rebuilds the state field against the
        // right option list (via the country select's change handler) before
        // we try to write the state value into it.
        if ( country ) { setScopeField( scope, '_country', country.toUpperCase() ); }
        if ( street ) { setScopeField( scope, '_address_1', street ); }
        if ( city ) { setScopeField( scope, '_city', city ); }
        if ( postcode ) { setScopeField( scope, '_postcode', postcode ); }
        if ( stateCode ) { setScopeField( scope, '_state', stateCode ); }

        // A named premise with no precise house number (common in markets like
        // India: the society is in Google but individual flats are not). The
        // street/city/state/postcode are filled, so drop the buyer straight
        // into the apartment / unit line to add their flat or house number
        // without hunting for the field.
        if ( isNamedPlace && ! streetNumber ) {
            var unit = scope.querySelector( '[name$="_address_2"]' );
            if ( unit ) {
                try { unit.focus(); } catch ( e ) {}
            }
        }

        queueShippingRefresh();
    }

    function setScopeField( scope, suffix, value ) {
        if ( ! scope ) { return; }
        var field = scope.querySelector( '[name$="' + suffix + '"]' );
        if ( ! field ) { return; }
        field.value = value;
        field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    }

    function initStickyPlaceOrder() {
        var sticky = document.querySelector( '[data-tejcart-sticky-place-order]' );
        var real   = form && form.querySelector( '.tejcart-place-order-btn:not(.tejcart-place-order-btn--sticky)' );
        if ( ! sticky || ! real || typeof IntersectionObserver === 'undefined' ) { return; }

        function show() {
            sticky.setAttribute( 'aria-hidden', 'false' );
            sticky.classList.add( 'is-visible' );
        }
        function hide() {
            sticky.classList.remove( 'is-visible' );
            sticky.setAttribute( 'aria-hidden', 'true' );
        }

        var observer = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    hide();
                } else {
                    show();
                }
            } );
        }, { threshold: 0, rootMargin: '0px 0px -10% 0px' } );

        observer.observe( real );
    }

    /**
     * Reveal the "Create an account for faster checkout" row
     * only after the customer has typed a syntactically valid
     * email — placing the offer at the moment it's actually
     * relevant, instead of pre-empting them with a checkbox they
     * can't yet act on.
     */
    function initAccountDisclosure() {
        if ( ! form ) { return; }

        var account = form.querySelector( '[data-tejcart-account-disclosure]' );
        if ( ! account ) { return; }

        var emailKey = account.getAttribute( 'data-tejcart-account-disclosure' ) || 'billing_email';
        var email    = form.querySelector( '[name="' + emailKey + '"]' );
        if ( ! email ) { return; }

        function reveal() {
            account.removeAttribute( 'hidden' );
            account.classList.add( 'is-revealed' );
        }
        function hide() {
            if ( account.classList.contains( 'is-revealed' ) ) { return; }
            account.setAttribute( 'hidden', 'hidden' );
        }

        function sync() {
            var v = ( email.value || '' ).trim();
            if ( v && EMAIL_RE.test( v ) ) {
                reveal();
            } else {
                hide();
            }
        }

        email.addEventListener( 'input', sync );
        email.addEventListener( 'blur', sync );
        sync();
    }

    /**
     * The sticky pay bar lives above the fold even while the form
     * is empty. Show "Continue" (and visually disabled) until the
     * customer has filled every required field — then swap to
     * "Pay" so the action matches what tapping it actually does.
     * If they tap while still incomplete, focus the first invalid
     * field instead of letting the browser dump them at the top
     * of the form with a generic native validation popup.
     *
     * Performance notes:
     *  - The sticky bar is mobile-only (CSS hides it ≥ 768px), so
     *    we bail out early on desktop and never attach a single
     *    listener there. Most traffic pays zero cost.
     *  - Sync runs on `change` (i.e. blur), not `input` — one
     *    pass per completed field instead of one pass per
     *    keystroke. The label transition still feels instant
     *    because users only need to see it after leaving a field.
     *  - Bursts are coalesced into a single requestAnimationFrame
     *    tick (e.g. country-change which rebuilds the state field
     *    fires multiple change events).
     */
    function initStickyButtonState() {
        if ( ! form ) { return; }

        var sticky = document.querySelector( '[data-tejcart-sticky-place-order]' );
        if ( ! sticky ) { return; }

        var btn = sticky.querySelector( '[data-tejcart-sticky-button]' );
        if ( ! btn ) { return; }

        // Skip entirely on desktop. matchMedia is supported
        // everywhere we care about; the fallback path also skips.
        var mq = ( typeof window.matchMedia === 'function' )
            ? window.matchMedia( '(max-width: 767px)' )
            : null;
        if ( ! mq || ! mq.matches ) { return; }

        var labelEl       = btn.querySelector( '.tejcart-place-order-label' );
        var continueLabel = btn.getAttribute( 'data-continue-label' ) || 'Continue';
        var payLabel      = btn.getAttribute( 'data-pay-label' )      || 'Pay';

        function isVisible( el ) {
            if ( ! el ) { return false; }
            if ( el.hasAttribute( 'hidden' ) ) { return false; }
            // offsetParent === null catches `display: none` ancestors.
            if ( el.offsetParent === null && el.type !== 'hidden' ) { return false; }
            return true;
        }

        function firstInvalid() {
            var inputs = form.querySelectorAll( 'input[required], select[required], textarea[required]' );
            for ( var i = 0; i < inputs.length; i++ ) {
                var el = inputs[ i ];
                if ( ! isVisible( el ) ) { continue; }

                if ( el.type === 'checkbox' ) {
                    if ( ! el.checked ) { return el; }
                    continue;
                }

                var v = ( el.value || '' ).trim();
                if ( ! v ) { return el; }

                if ( el.type === 'email' && ! EMAIL_RE.test( v ) ) {
                    return el;
                }

                if ( typeof el.checkValidity === 'function' && ! el.checkValidity() ) {
                    return el;
                }
            }
            return null;
        }

        function sync() {
            var invalid = firstInvalid();
            var pending = !! invalid;
            var nextLabel = pending ? continueLabel : payLabel;

            // Only mutate the DOM when state actually changes.
            // Without this guard the MutationObserver below would
            // observe our own textContent write, re-trigger sync(),
            // and spin forever.
            if ( pending !== btn.classList.contains( 'is-pending' ) ) {
                if ( pending ) {
                    btn.classList.add( 'is-pending' );
                    btn.setAttribute( 'aria-disabled', 'true' );
                } else {
                    btn.classList.remove( 'is-pending' );
                    btn.removeAttribute( 'aria-disabled' );
                }
            }

            if ( labelEl && labelEl.textContent !== nextLabel ) {
                labelEl.textContent = nextLabel;
            }
        }

        btn.addEventListener( 'click', function ( ev ) {
            var invalid = firstInvalid();
            if ( ! invalid ) { return; }

            ev.preventDefault();
            ev.stopPropagation();

            try { invalid.focus( { preventScroll: true } ); } catch ( e ) { invalid.focus(); }
            if ( typeof invalid.scrollIntoView === 'function' ) {
                invalid.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            }
        } );

        // Coalesce repeated triggers into one frame so a burst of
        // change events (e.g. country swap rebuilds the state
        // field, firing extra change events) only re-syncs once.
        var rafToken = null;
        function scheduleSync() {
            if ( rafToken !== null ) { return; }
            rafToken = ( window.requestAnimationFrame || window.setTimeout )( function () {
                rafToken = null;
                sync();
            }, 0 );
        }

        // Listen on `change` only — fires once per completed
        // field, not per keystroke. Passive listeners avoid
        // blocking the scroll thread on touch devices.
        form.addEventListener( 'change', scheduleSync, { passive: true } );

        sync();
    }

    function createOverlay() {
        overlay = document.querySelector( '.tejcart-loading-overlay' );
        if ( overlay ) { return; }
        overlay = document.createElement( 'div' );
        overlay.className = 'tejcart-loading-overlay';
        overlay.setAttribute( 'aria-hidden', 'true' );

        overlay.innerHTML = '<div class="tejcart-spinner" role="status" aria-label="' + checkoutI18n( 'loading', 'Loading' ) + '"></div>';
        document.body.appendChild( overlay );
    }

    function showOverlay() {
        if ( overlay ) {
            overlay.classList.add( 'is-active' );
            overlay.classList.add( 'active' );
        }
    }

    function hideOverlay() {
        if ( overlay ) {
            overlay.classList.remove( 'is-active' );
            overlay.classList.remove( 'active' );
        }
    }

    function initCheckoutCoupon() {
        var containers = document.querySelectorAll( '[data-tejcart-checkout-coupon]' );
        if ( ! containers.length ) { return; }

        containers.forEach( function ( wrapper ) {
            // Guard against duplicate bindings when the order summary is
            // re-rendered (e.g. after the customer types a new address).
            if ( wrapper.dataset.tejcartCouponBound === '1' ) { return; }
            wrapper.dataset.tejcartCouponBound = '1';

            // Toggle (open/close) is handled by tejcart-cart.js via a
            // document-delegated listener on `.tejcart-coupon-toggle` —
            // binding it here too caused two toggles per click (net no-op).
            var applyEl = wrapper.querySelector( '[data-tejcart-apply-coupon]' );
            var input   = wrapper.querySelector( '[data-tejcart-coupon-input]' );
            var feedback = wrapper.querySelector( '[data-tejcart-coupon-feedback]' );

            if ( applyEl && input ) {
                applyEl.addEventListener( 'click', function ( e ) {
                    e.preventDefault();
                    var code = ( input.value || '' ).trim();
                    if ( ! code ) {
                        setCouponFeedback( feedback, checkoutI18n( 'coupon_missing', 'Please enter a discount code.' ), 'error' );
                        return;
                    }
                    sendCouponRequest( 'tejcart_apply_coupon', { coupon_code: code }, feedback, input );
                } );

                input.addEventListener( 'keydown', function ( e ) {
                    if ( e.key === 'Enter' ) {
                        e.preventDefault();
                        applyEl.click();
                    }
                } );
            }
        } );

        if ( checkoutCouponDocBound ) { return; }
        checkoutCouponDocBound = true;
        document.addEventListener( 'click', function ( e ) {
            var removeBtn = e.target.closest && e.target.closest( '[data-tejcart-remove-coupon]' );
            if ( ! removeBtn ) { return; }
            e.preventDefault();
            var code = removeBtn.getAttribute( 'data-tejcart-remove-coupon' );
            if ( ! code ) { return; }
            var wrapper  = removeBtn.closest( '[data-tejcart-checkout-coupon]' );
            var feedback = wrapper ? wrapper.querySelector( '[data-tejcart-coupon-feedback]' ) : null;
            sendCouponRequest( 'tejcart_remove_coupon', { coupon_code: code }, feedback, null );
        } );
    }

    function checkoutI18n( key, fallback ) {
        var strings = ( checkoutParams && checkoutParams.i18n ) || {};
        return typeof strings[ key ] === 'string' && strings[ key ] ? strings[ key ] : fallback;
    }

    function setCouponFeedback( el, message, tone ) {
        if ( ! el ) { return; }
        el.textContent = message || '';
        el.classList.remove( 'is-error', 'is-success' );
        if ( tone === 'error' )   { el.classList.add( 'is-error' ); }
        if ( tone === 'success' ) { el.classList.add( 'is-success' ); }
    }

    function sendCouponRequest( action, body, feedback, input ) {
        var ajaxUrl = ( params && params.ajax_url ) || '/wp-admin/admin-ajax.php';
        var nonce   = ( params && params.nonce ) || ( checkoutParams && checkoutParams.nonce ) || '';

        if ( ! nonce ) {
            setCouponFeedback( feedback, checkoutI18n( 'coupon_error_generic', 'Unable to update coupon. Please reload the page.' ), 'error' );
            return;
        }

        var formData = new FormData();
        formData.append( 'action', action );
        formData.append( '_wpnonce', nonce );
        Object.keys( body ).forEach( function ( key ) {
            formData.append( key, body[ key ] );
        } );

        setCouponFeedback( feedback, checkoutI18n( 'coupon_applying', 'Working…' ), null );

        fetch( ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } )
            .then( function ( response ) {
                // wp_send_json_error() ships a useful `data.message` with
                // an HTTP 400/403/429. Parse the body regardless of status
                // so that server-side error text surfaces instead of being
                // replaced with the generic "network error" fallback.
                return response.json().catch( function () { return null; } );
            } )
            .then( function ( result ) {
                if ( ! result ) {
                    setCouponFeedback( feedback, checkoutI18n( 'coupon_error_network', 'A network error occurred. Please try again.' ), 'error' );
                    return;
                }
                if ( ! result.success ) {
                    var errMsg = ( result.data && result.data.message )
                        || checkoutI18n( 'coupon_error_generic', 'Unable to apply that code.' );
                    setCouponFeedback( feedback, errMsg, 'error' );
                    return;
                }
                setCouponFeedback( feedback, ( result.data && result.data.message ) || '', 'success' );
                if ( input ) { input.value = ''; }
                applyCouponTotalsPatch( result.data || {} );
            } )
            .catch( function () {
                setCouponFeedback( feedback, checkoutI18n( 'coupon_error_network', 'A network error occurred. Please try again.' ), 'error' );
            } );
    }

    function applyCouponTotalsPatch( data ) {
        var sanitiser = window.TejCartSanitiser;
        var sanitise  = function ( html ) {
            if ( typeof html !== 'string' ) { return ''; }
            return ( sanitiser && typeof sanitiser.sanitiseDrawer === 'function' )
                ? sanitiser.sanitiseDrawer( html )
                : html;
        };

        var map = {
            '.tejcart-subtotal-value': data.subtotal_html,
            '.tejcart-shipping-value': data.shipping_html,
            '.tejcart-tax-value':      data.tax_html,
            '.tejcart-discount-value': data.discount_html,
            '.tejcart-total-value':    data.total_html
        };

        Object.keys( map ).forEach( function ( selector ) {
            if ( typeof map[ selector ] !== 'string' ) { return; }
            var safe = sanitise( map[ selector ] );
            document.querySelectorAll( selector ).forEach( function ( el ) {
                el.innerHTML = safe;
            } );
        } );

        document.querySelectorAll( '.tejcart-checkout-discount' ).forEach( function ( row ) {
            row.hidden = ! data.has_discount;
        } );

        if ( Array.isArray( data.applied_coupons ) ) {
            document.querySelectorAll( '[data-tejcart-coupon-applied-list]' ).forEach( function ( list ) {
                while ( list.firstChild ) { list.removeChild( list.firstChild ); }
                if ( data.applied_coupons.length === 0 ) {
                    list.setAttribute( 'hidden', '' );
                    return;
                }
                list.removeAttribute( 'hidden' );
                data.applied_coupons.forEach( function ( code ) {
                    if ( typeof code !== 'string' || ! code ) { return; }
                    var upper = code.toUpperCase();
                    var pill  = document.createElement( 'div' );
                    pill.className = 'tejcart-coupon-applied';
                    pill.setAttribute( 'role', 'status' );
                    var label = document.createElement( 'span' );
                    label.appendChild( document.createTextNode( checkoutI18n( 'coupon_applied_prefix', 'Applied:' ) + ' ' ) );
                    var codeSpan = document.createElement( 'span' );
                    codeSpan.className = 'tejcart-coupon-applied-code';
                    codeSpan.textContent = upper;
                    label.appendChild( codeSpan );
                    pill.appendChild( label );
                    var removeBtn = document.createElement( 'button' );
                    removeBtn.type = 'button';
                    removeBtn.className = 'tejcart-coupon-remove';
                    removeBtn.setAttribute( 'data-tejcart-remove-coupon', code );
                    removeBtn.setAttribute( 'aria-label', checkoutI18n( 'coupon_remove_aria', 'Remove coupon' ) + ' ' + upper );
                    removeBtn.textContent = checkoutI18n( 'coupon_remove', 'Remove' );
                    pill.appendChild( removeBtn );
                    list.appendChild( pill );
                } );
            } );
        } else {
            window.location.reload();
        }
    }

    function initMobileSummaryToggle() {
        var container = document.querySelector( '[data-tejcart-summary-mobile]' );
        if ( ! container ) { return; }

        var button = container.querySelector( '.tejcart-checkout-order-summary-toggle' );
        if ( ! button ) { return; }

        button.addEventListener( 'click', function () {
            var isOpen = container.classList.toggle( 'is-open' );
            button.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
        } );
    }

    /**
     * Gateways that render their own smart / hosted buttons and do
     * NOT need the generic "Place order" button + order-total row.
     */
    var SMART_BUTTON_GATEWAYS = [ 'tejcart_paypal', 'tejcart_googlepay' ];

    function getSelectedGateway() {
        if ( ! form ) { return ''; }
        var checked = form.querySelector( 'input[name="tejcart_payment_method"]:checked' );
        return checked ? checked.value : '';
    }

    function getSelectedPayPalSavedMethodId() {
        if ( ! form ) { return ''; }
        var selected = form.querySelector( 'input[name="tejcart_paypal_saved_method"]:checked' );
        return selected ? selected.value : '';
    }

    /**
     * Decide which checkout-CTA chrome to show based on the active
     * gateway AND, for PayPal, whether a vaulted saved method is
     * selected.
     *
     *   • COD / Bank Transfer / Check / Card — Place Order button only.
     *   • PayPal (new method) / Google Pay   — Smart Buttons only; the
     *     generic Place Order row is hidden because the wallet UI owns
     *     submission.
     *   • PayPal with a saved vault token    — the Smart Buttons cannot
     *     create a vault-token order (that flow lives in the server-side
     *     `process_payment()`), so we hide the Smart Buttons container
     *     and restore the generic Place Order button. The buyer clicks
     *     Place Order → checkout AJAX → gateway charges the vault token.
     */
    function updatePaymentChrome() {
        if ( ! form ) { return; }

        var placeOrderSection     = form.querySelector( '.tejcart-checkout-place-order' );
        var paypalButtonContainer = document.getElementById( 'tejcart-paypal-button-container' );
        var gateway               = getSelectedGateway();
        var usingSavedPayPal      = ( gateway === 'tejcart_paypal' && getSelectedPayPalSavedMethodId() !== '' );
        var isSmartButtonGateway  = SMART_BUTTON_GATEWAYS.indexOf( gateway ) !== -1;
        var hidePlaceOrder        = isSmartButtonGateway && ! usingSavedPayPal;

        if ( placeOrderSection ) {
            placeOrderSection.style.display = hidePlaceOrder ? 'none' : '';
        }

        if ( paypalButtonContainer ) {
            paypalButtonContainer.style.display = usingSavedPayPal ? 'none' : '';
        }
    }

    /**
     * Mirror the active-method class onto the `inert` attribute of each
     * gateway's collapsed payment-method-fields div. The CSS hides the
     * inactive sections via `max-height: 0; overflow: hidden;` — which
     * does NOT remove focusable descendants (PayPal Smart Buttons,
     * vaulted-method radios, the "Save this method" checkbox) from the
     * keyboard tab order. Without `inert` a buyer who has selected
     * Credit / Debit Card still has to Tab through every invisible
     * control of every other gateway before reaching the card number
     * field. `inert` skips them in both tab order and the a11y tree
     * without disturbing the height transition.
     */
    function syncPaymentMethodInert( methods ) {
        methods.forEach( function ( m ) {
            var fields = m.querySelector( '.tejcart-payment-method-fields' );
            if ( ! fields ) { return; }
            if ( m.classList.contains( 'is-active' ) ) {
                fields.removeAttribute( 'inert' );
            } else {
                fields.setAttribute( 'inert', '' );
            }
        } );
    }

    function initPaymentMethods() {
        var methods = form.querySelectorAll( '.tejcart-payment-method' );
        var radios  = form.querySelectorAll( 'input[name="tejcart_payment_method"]' );

        radios.forEach( function ( radio ) {
            radio.addEventListener( 'change', function () {
                methods.forEach( function ( m ) {
                    m.classList.remove( 'is-active' );
                    m.classList.remove( 'active' );
                } );
                var parent = radio.closest( '.tejcart-payment-method' );
                if ( parent ) {
                    parent.classList.add( 'is-active' );
                    parent.classList.add( 'active' );
                }
                syncPaymentMethodInert( methods );
                updatePaymentChrome();
                // Audit #63 / 05 F-6 — namespaced event for addons
                // (Stripe Elements, Authorize.Net Accept.js) so they
                // can react to method switches without scraping the
                // DOM or duplicating the radio-tracking logic.
                try {
                    form.dispatchEvent( new CustomEvent( 'tejcart:payment_method_changed', {
                        detail:  { method: radio.value },
                        bubbles: true
                    } ) );
                } catch ( e ) { /* CustomEvent unsupported on ancient browsers */ }
            } );
        } );

        if ( radios.length > 0 && ! form.querySelector( 'input[name="tejcart_payment_method"]:checked' ) ) {
            radios[ 0 ].checked = true;
            radios[ 0 ].dispatchEvent( new Event( 'change' ) );
        }

        syncPaymentMethodInert( methods );
        updatePaymentChrome();
    }

    /**
     * Keep the "Save this payment method for future purchases" row AND
     * the checkout CTA chrome in sync with the saved-method radiogroup
     * rendered by the PayPal gateway (`PayPal_Gateway::payment_fields()`):
     *
     *   - "Use a new payment method" selected → save row visible +
     *     checkbox enabled, Smart Buttons own submission, Place Order
     *     row stays hidden.
     *   - Any saved vault token selected → save row hidden + checkbox
     *     unchecked and disabled (the token already lives in the vault,
     *     so "save again" is meaningless — `process_payment()` enforces
     *     the same rule). The Smart Buttons container is hidden and the
     *     generic Place Order button is restored so the buyer can submit
     *     through the standard checkout AJAX flow, which calls the
     *     gateway with the chosen vault token.
     *
     * No-ops on pages where the PayPal gateway is absent or the buyer
     * has zero saved methods (the radiogroup is not rendered at all).
     */
    function initPayPalSavedMethodToggle() {
        if ( ! form ) { return; }

        var radios = form.querySelectorAll( 'input[name="tejcart_paypal_saved_method"]' );
        if ( ! radios.length ) { return; }

        var saveRow      = form.querySelector( '[data-tejcart-paypal-save-row]' );
        var saveCheckbox = form.querySelector( 'input[name="tejcart_paypal_save_method"]' );

        function sync() {
            var usingSaved = getSelectedPayPalSavedMethodId() !== '';

            if ( saveRow && saveCheckbox ) {
                if ( usingSaved ) {
                    saveRow.setAttribute( 'hidden', 'hidden' );
                    saveRow.setAttribute( 'aria-hidden', 'true' );
                    saveCheckbox.checked  = false;
                    saveCheckbox.disabled = true;
                } else {
                    saveRow.removeAttribute( 'hidden' );
                    saveRow.removeAttribute( 'aria-hidden' );
                    saveCheckbox.disabled = false;
                }
            }

            updatePaymentChrome();
        }

        radios.forEach( function ( radio ) {
            radio.addEventListener( 'change', sync );
        } );

        sync();
    }

    var shippingRefreshTimer = null;
    var shippingRefreshController = null;

    function getActiveAddressScope() {
        if ( ! form ) { return null; }

        // The shipping section (when present) is always the active scope for
        // delivery / shipping-method calculations — that's the address the
        // package is going to. If no shipping section is rendered (digital
        // cart), fall back to the billing scope.
        var shippingScope = form.querySelector( 'section[data-tejcart-section="shipping"][data-tejcart-address-scope="shipping"]' );
        if ( shippingScope ) { return shippingScope; }

        return form.querySelector( '[data-tejcart-address-scope="billing"]' );
    }

    function readScopeField( scope, suffix ) {
        if ( ! scope ) { return ''; }
        var field = scope.querySelector( '[name$="' + suffix + '"]' );
        return field ? ( field.value || '' ).trim() : '';
    }

    // True when there is anything on the page that an address change should
    // refresh: the shipping-method picker (only rendered when the cart needs
    // shipping) OR the order summary (always rendered, and the row that shows
    // tax). Tax recalculation must NOT be gated on the shipping picker — a
    // digital/virtual cart, or a store with shipping disabled, has no
    // shipping container but still needs tax recomputed when the destination
    // changes.
    function hasAddressRefreshTargets() {
        if ( ! form ) { return false; }
        if ( form.querySelector( '[data-tejcart-shipping-methods]' ) ) { return true; }
        return !! document.querySelector( '[data-tejcart-summary]' );
    }

    function queueShippingRefresh() {
        if ( ! hasAddressRefreshTargets() ) { return; }
        if ( ! checkoutParams.ajax_url || ! checkoutParams.nonce ) { return; }

        clearTimeout( shippingRefreshTimer );
        shippingRefreshTimer = setTimeout( refreshShippingMethods, SHIPPING_REFRESH_DEBOUNCE_MS );
    }

    function refreshShippingMethods() {
        if ( ! hasAddressRefreshTargets() ) { return; }

        // The shipping-method container is optional: present only when the
        // cart needs shipping. The order-summary refresh (which carries the
        // recomputed tax total) happens either way.
        var container = form && form.querySelector( '[data-tejcart-shipping-methods]' );

        var scope = getActiveAddressScope();
        if ( ! scope ) { return; }

        var country  = readScopeField( scope, '_country' );
        var state    = readScopeField( scope, '_state' );
        var postcode = readScopeField( scope, '_postcode' );
        var city     = readScopeField( scope, '_city' );
        var address  = readScopeField( scope, '_address' );

        if ( ! country ) { return; }

        if ( container ) {
            container.classList.add( 'is-loading' );
            container.setAttribute( 'aria-busy', 'true' );
        }

        if ( shippingRefreshController ) {
            try { shippingRefreshController.abort(); } catch ( e ) {  }
        }
        shippingRefreshController = new AbortController();

        var body = new FormData();
        body.append( 'action', 'tejcart_refresh_shipping_methods' );
        body.append( 'nonce', checkoutParams.nonce );
        body.append( 'country', country );
        body.append( 'state', state );
        body.append( 'postcode', postcode );
        body.append( 'city', city );
        body.append( 'address', address );

        fetch( checkoutParams.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            signal: shippingRefreshController.signal
        } )
            .then( function ( response ) {
                return response.json().catch( function () { return null; } );
            } )
            .then( function ( result ) {
                if ( container ) {
                    container.classList.remove( 'is-loading' );
                    container.removeAttribute( 'aria-busy' );
                }
                if ( ! result || ! result.success || ! result.data ) { return; }
                var sanitiser = window.TejCartSanitiser;
                if ( container && typeof result.data.html === 'string' ) {
                    container.innerHTML = ( sanitiser && typeof sanitiser.sanitiseDrawer === 'function' )
                        ? sanitiser.sanitiseDrawer( result.data.html )
                        : result.data.html;
                }
                if ( typeof result.data.summary_html === 'string' && result.data.summary_html.length ) {
                    updateOrderSummary( result.data.summary_html );
                }
            } )
            .catch( function ( error ) {
                if ( error && error.name === 'AbortError' ) { return; }
                if ( container ) {
                    container.classList.remove( 'is-loading' );
                    container.removeAttribute( 'aria-busy' );
                }
            } );
    }

    function updateOrderSummary( html ) {
        var targets = document.querySelectorAll( '[data-tejcart-summary]' );
        if ( ! targets.length ) { return; }

        var sanitiser = window.TejCartSanitiser;
        var safeHtml  = ( sanitiser && typeof sanitiser.sanitiseDrawer === 'function' )
            ? sanitiser.sanitiseDrawer( html )
            : html;

        targets.forEach( function ( target ) {
            target.innerHTML = safeHtml;
        } );

        // Coupon toggle/apply handlers are attached at element level inside
        // initCheckoutCoupon(); the previous bindings died with the old DOM.
        // Re-init is idempotent thanks to the data-tejcart-coupon-bound guard.
        initCheckoutCoupon();
    }

    function initShippingMethodRefresh() {
        if ( ! form ) { return; }
        // Bind whenever an address change has something to refresh — the
        // shipping picker and/or the order summary. Previously this returned
        // early when no shipping-method container was present, which meant
        // digital/virtual carts (and stores with shipping disabled) never
        // recalculated tax on an address change because the listeners were
        // never attached.
        if ( ! hasAddressRefreshTargets() ) { return; }

        var watched = 'input[name$="_country"], select[name$="_country"], '
            + 'input[name$="_state"], select[name$="_state"], '
            + 'input[name$="_postcode"], input[name$="_city"]';

        form.addEventListener( 'change', function ( e ) {
            if ( e.target && e.target.matches && e.target.matches( watched + ', #tejcart-billing-different' ) ) {
                queueShippingRefresh();
            }
        } );

        form.addEventListener( 'input', function ( e ) {
            if ( e.target && e.target.matches && e.target.matches( 'input[name$="_postcode"], input[name$="_city"], input[name$="_state"]' ) ) {
                queueShippingRefresh();
            }
        } );

        // Pre-filled addresses (a logged-in customer's saved address,
        // browser autofill, or restored form-persistence values) populate
        // the fields without firing input/change events, so the debounced
        // refresh above never runs on first paint. The server-side render
        // only saw the cart session's destination — which lacks the
        // postcode — so postcode-dependent methods (live carrier rates)
        // can't quote and silently drop off the picker. Sync the pre-filled
        // destination once on load so those methods appear.
        var prefilledScope = getActiveAddressScope();
        if ( prefilledScope
            && readScopeField( prefilledScope, '_country' )
            && readScopeField( prefilledScope, '_postcode' ) ) {
            queueShippingRefresh();
        }
    }

    function persistShippingMethod( methodId ) {
        if ( ! methodId ) { return; }
        if ( ! checkoutParams.ajax_url || ! checkoutParams.nonce ) { return; }

        var body = new FormData();
        body.append( 'action', 'tejcart_set_shipping_method' );
        body.append( 'nonce', checkoutParams.nonce );
        body.append( 'method', methodId );

        fetch( checkoutParams.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        } )
            .then( function ( response ) {
                return response.json().catch( function () { return null; } );
            } )
            .then( function ( result ) {
                if ( ! result || ! result.success || ! result.data ) { return; }
                if ( typeof result.data.summary_html === 'string' && result.data.summary_html.length ) {
                    updateOrderSummary( result.data.summary_html );
                }
            } )
            .catch( function () {} );
    }

    function initShippingMethodSelection() {
        if ( ! form ) { return; }

        form.addEventListener( 'change', function ( e ) {
            if ( ! e.target || ! e.target.matches ) { return; }
            if ( e.target.matches( 'input[name="tejcart_shipping_method"]' ) ) {
                persistShippingMethod( e.target.value );
            }
        } );
    }

    function initShippingToggle() {
        // Modern checkout: shipping is the primary visible address. The
        // toggle reveals separate billing-address fields when the customer
        // wants billing to differ from shipping.
        var checkbox = form.querySelector( '#tejcart-billing-different' );
        var fields   = form.querySelector( '[data-tejcart-billing-fields], .tejcart-billing-fields' );
        if ( ! checkbox || ! fields ) { return; }

        function sync() {
            var billingDiffers = checkbox.checked;
            if ( billingDiffers ) {
                fields.removeAttribute( 'hidden' );
                fields.style.display = '';
            } else {
                fields.setAttribute( 'hidden', '' );
                fields.style.display = 'none';
            }
        }

        checkbox.addEventListener( 'change', sync );
        sync();
    }

    function setFieldError( field, message ) {
        var row = field.closest( '.tejcart-form-row, .tejcart-field' );
        if ( ! row ) { return; }
        row.classList.add( 'has-error', 'is-error' );
        field.setAttribute( 'aria-invalid', 'true' );

        var errorId = field.getAttribute( 'aria-describedby' );
        var errorEl = null;
        if ( errorId ) {
            errorEl = document.getElementById( errorId.split( ' ' ).pop() );
        }
        if ( ! errorEl ) {
            errorEl = row.querySelector( '.tejcart-field-error, .tejcart-field-error-text' );
        }
        if ( ! errorEl ) {
            errorEl = document.createElement( 'span' );
            errorEl.className = 'tejcart-field-error tejcart-field-error-text';
            row.appendChild( errorEl );
        }
        errorEl.textContent = message;
    }

    function clearFieldError( field ) {
        var row = field.closest( '.tejcart-form-row, .tejcart-field' );
        if ( ! row ) { return; }
        row.classList.remove( 'has-error', 'is-error' );
        field.removeAttribute( 'aria-invalid' );
        var errorEl = row.querySelector( '.tejcart-field-error, .tejcart-field-error-text' );
        if ( errorEl ) { errorEl.textContent = ''; }
    }

    function initFieldValidation() {
        form.addEventListener( 'input', function ( e ) {
            var field = e.target;
            if ( field.matches( '.tejcart-field-input, .tejcart-input, select, textarea' ) ) {
                if ( field.value && field.value.trim() ) {
                    clearFieldError( field );
                }
            }
        } );

        form.addEventListener( 'change', function ( e ) {
            var field = e.target;
            if ( field && field.type === 'checkbox' && field.checked ) {
                clearFieldError( field );
            }
        } );

        form.addEventListener( 'blur', function ( e ) {
            var field = e.target;
            if ( field.type === 'email' && field.value && ! EMAIL_RE.test( field.value ) ) {
                setFieldError( field, checkoutI18n( 'email_invalid', 'Please enter a valid email address.' ) );
            }
        }, true );
    }

    function validateForm() {
        var firstError = null;
        var errorCount = 0;

        form.querySelectorAll( '.has-error, .is-error' ).forEach( function ( row ) {
            row.classList.remove( 'has-error', 'is-error' );
            var msg = row.querySelector( '.tejcart-field-error, .tejcart-field-error-text' );
            if ( msg ) { msg.textContent = ''; }
        } );
        form.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( el ) {
            el.removeAttribute( 'aria-invalid' );
        } );

        form.querySelectorAll( '[required]' ).forEach( function ( field ) {
            var hidden = field.closest( '[hidden], [style*="display: none"], [style*="display:none"]' );
            if ( hidden ) { return; }

            var isCheckbox = field.type === 'checkbox';
            var missing    = isCheckbox
                ? ! field.checked
                : ( ! field.value || ! field.value.trim() );

            if ( missing ) {
                var label = field.closest( '.tejcart-form-row, .tejcart-field' );
                var labelText = label ? ( label.querySelector( 'label' ) || {} ).textContent : '';
                labelText = ( labelText || 'This field' ).replace( '*', '' ).trim();
                var message = isCheckbox
                    ? checkoutI18n( 'checkbox_required', 'Please check this box to continue.' )
                    : labelText + ' ' + checkoutI18n( 'field_required_suffix', 'is required.' );
                setFieldError( field, message );
                errorCount++;
                if ( ! firstError ) { firstError = field; }
            }
        } );

        var emailField = form.querySelector( 'input[type="email"]' );
        if ( emailField && emailField.value && ! EMAIL_RE.test( emailField.value ) ) {
            setFieldError( emailField, checkoutI18n( 'email_invalid', 'Please enter a valid email address.' ) );
            errorCount++;
            if ( ! firstError ) { firstError = emailField; }
        }

        var shippingContainer = form.querySelector( '[data-tejcart-shipping-methods]' );
        if ( shippingContainer ) {
            var methodRadios  = shippingContainer.querySelectorAll( 'input[name="tejcart_shipping_method"]' );
            var methodChecked = shippingContainer.querySelector( 'input[name="tejcart_shipping_method"]:checked' );
            if ( methodRadios.length === 0 ) {
                showCheckoutError( 'No shipping methods are available for your address. Please update your address details.' );
                errorCount++;
                if ( ! firstError ) { firstError = shippingContainer; }
            } else if ( ! methodChecked ) {
                showCheckoutError( checkoutI18n( 'shipping_method_required', 'Please choose a shipping method.' ) );
                errorCount++;
                if ( ! firstError ) { firstError = methodRadios[ 0 ]; }
            }
        }

        if ( firstError ) {
            firstError.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            try { firstError.focus( { preventScroll: true } ); } catch ( e ) { firstError.focus(); }
        }

        return errorCount === 0;
    }

    function initFormSubmit() {
        var submitBtn = form.querySelector( '.tejcart-place-order-btn' );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();

            if ( isSubmitting ) { return; }
            if ( ! validateForm() ) { return; }

            var nonce = params.nonce;
            if ( ! nonce ) {
                showCheckoutError( checkoutI18n( 'nonce_missing', 'Security token missing. Please reload the page.' ) );
                return;
            }

            isSubmitting = true;
            if ( submitBtn ) {
                submitBtn.disabled = true;
                submitBtn.classList.add( 'is-loading' );
                var loadingLabel = submitBtn.getAttribute( 'data-loading-label' ) || 'Processing…';
                var labelEl = submitBtn.querySelector( '.tejcart-place-order-label' );
                if ( labelEl ) { labelEl.textContent = loadingLabel; }
                submitBtn.setAttribute( 'aria-busy', 'true' );
            }
            // Warn the buyer if they try to navigate away while a
            // payment is in flight. Cleared in resetSubmitState() and on
            // successful redirect (the unload itself is the redirect).
            try { window.addEventListener( 'beforeunload', warnUnloadDuringCheckout ); } catch ( ex ) {}
            showOverlay();

            var formData = new FormData( form );
            formData.append( 'action', 'tejcart_checkout' );

            // The optional captcha module gates every checkout submit. Fetch
            // a fresh bot-gate token and attach it before sending; when the
            // module is disabled `window.tejcartCaptcha` is absent and this
            // resolves immediately with no token (server gate = pass).
            var captchaPrep = ( window.tejcartCaptcha && window.tejcartCaptcha.isActive() )
                ? window.tejcartCaptcha.appendTo( formData, 'checkout' )
                : Promise.resolve();

            captchaPrep.then( function () {
            var controller = new AbortController();
            var timeout    = setTimeout( function () { controller.abort(); }, REQUEST_TIMEOUT_MS );

            fetch( params.ajax_url || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                signal: controller.signal
            } )
                .then( function ( response ) {
                    // The server (Place_Order_Handler::dispatch) returns
                    // every validation/gateway error via wp_send_json_error
                    // with HTTP 400. Parse the JSON body regardless of
                    // status so the user sees the actual `data.message`
                    // ("Please choose a shipping method", "Your card was
                    // declined", "Out of stock", …) instead of a generic
                    // "A network error occurred" fallback.
                    clearTimeout( timeout );
                    return response.json().catch( function () { return null; } );
                } )
                .then( function ( result ) {
                    if ( ! result ) {
                        resetSubmitState();
                        showCheckoutError( checkoutI18n( 'network_error', 'A network error occurred. Please try again.' ) );
                        return;
                    }
                    var redirect = result.data && result.data.redirect;
                    if ( result.success && redirect ) {
                        // Drop the beforeunload guard before navigating; otherwise
                        // the browser fires its generic "Leave site?" prompt as
                        // the success redirect unloads the page.
                        isSubmitting = false;
                        try { window.removeEventListener( 'beforeunload', warnUnloadDuringCheckout ); } catch ( ex ) {}
                        window.location.href = isSafeUrl( redirect ) ? redirect : '/';
                        return;
                    }
                    resetSubmitState();
                    var message = ( result.data && result.data.message ) || checkoutI18n( 'generic_error', 'An error occurred. Please try again.' );
                    showCheckoutError( message );

                    if ( result.data && result.data.field_errors && typeof result.data.field_errors === 'object' ) {
                        Object.keys( result.data.field_errors ).forEach( function ( key ) {
                            var field = form.querySelector( '[name="' + key + '"]' );
                            if ( field ) {
                                setFieldError( field, result.data.field_errors[ key ] );
                            }
                        } );
                    }
                } )
                .catch( function ( error ) {
                    clearTimeout( timeout );
                    resetSubmitState();
                    var message = error && error.name === 'AbortError'
                        ? checkoutI18n( 'timeout_error', 'The request timed out. Please try again.' )
                        : 'A network error occurred. Please try again.';
                    showCheckoutError( message );
                } );
            } );
        } );

        function resetSubmitState() {
            hideOverlay();
            isSubmitting = false;
            try { window.removeEventListener( 'beforeunload', warnUnloadDuringCheckout ); } catch ( ex ) {}
            if ( submitBtn ) {
                submitBtn.disabled = false;
                submitBtn.classList.remove( 'is-loading' );
                submitBtn.removeAttribute( 'aria-busy' );
                var defaultLabel = submitBtn.getAttribute( 'data-default-label' ) || 'Complete order';
                var labelEl = submitBtn.querySelector( '.tejcart-place-order-label' );
                if ( labelEl ) { labelEl.textContent = defaultLabel; }
            }
        }
    }

    function showCheckoutError( message ) {
        var existing = form.querySelector( '.tejcart-checkout-error' );
        if ( existing ) { existing.remove(); }

        var errorDiv = document.createElement( 'div' );
        errorDiv.className = 'tejcart-checkout-error tejcart-notice tejcart-notice--error';
        errorDiv.setAttribute( 'role', 'alert' );

        var icon = document.createElement( 'span' );
        icon.className = 'tejcart-notice-icon';
        icon.setAttribute( 'aria-hidden', 'true' );
        icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="14" r=".75" fill="currentColor"/></svg>';

        var body = document.createElement( 'span' );
        body.className = 'tejcart-notice-body';
        body.textContent = message;

        errorDiv.appendChild( icon );
        errorDiv.appendChild( body );

        // `.tejcart-checkout-place-order` is nested several levels deep
        // inside `.tejcart-checkout-columns > .tejcart-checkout-col-fields`,
        // not a direct child of `form` — so `form.insertBefore(errorDiv,
        // reference)` throws `DOMException: Child to insert before is not
        // a child of this node`, which swallows the rejection-message UI
        // (the buyer sees no error at all). Insert as a sibling of the
        // reference node — i.e. parented by the same container — so the
        // banner still lands directly above the Place Order button.
        var reference = form.querySelector( '.tejcart-checkout-place-order' );
        if ( reference && reference.parentNode ) {
            reference.parentNode.insertBefore( errorDiv, reference );
        } else if ( form.firstChild ) {
            form.insertBefore( errorDiv, form.firstChild );
        } else {
            form.appendChild( errorDiv );
        }
        errorDiv.scrollIntoView( { behavior: 'smooth', block: 'center' } );
    }
} )();
