/**
 * Customer "Track my order" form.
 *
 * Vanilla JS, no jQuery dependency. Talks to the public AJAX endpoint
 * `tejcart_tracking_list` which is double-rate-limited and requires a
 * matching (order_number, email) pair. Surfaces 429 / 404 / 400 to the
 * user via a polite live region.
 */
( function () {
    'use strict';

    if ( typeof window.tejcartOrderTrackingPublic === 'undefined' ) {
        return;
    }
    var cfg   = window.tejcartOrderTrackingPublic;
    var roots = document.querySelectorAll( '[data-tejcart-track-root]' );
    if ( ! roots.length ) {
        return;
    }

    function statusLabel( slug ) {
        return ( cfg.statusLabels && cfg.statusLabels[ slug ] ) || slug;
    }

    function buildShipmentBlock( row ) {
        var wrap = document.createElement( 'div' );
        wrap.className = 'tejcart-track-order__shipment';

        var head = document.createElement( 'div' );
        head.className = 'tejcart-track-order__shipment-head';

        var carrier = document.createElement( 'div' );
        carrier.className = 'tejcart-track-order__shipment-carrier';
        carrier.textContent = row.carrier_label || row.carrier;
        head.appendChild( carrier );

        var num = document.createElement( 'div' );
        num.className = 'tejcart-track-order__shipment-number';
        if ( row.tracking_url ) {
            var a = document.createElement( 'a' );
            a.href = row.tracking_url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = row.tracking_number;
            num.appendChild( a );
        } else {
            num.textContent = row.tracking_number;
        }
        head.appendChild( num );

        var pill = document.createElement( 'span' );
        pill.className = 'tejcart-track-order__pill tejcart-track-order__pill--' + row.status;
        pill.textContent = statusLabel( row.status );
        head.appendChild( pill );

        wrap.appendChild( head );

        var events = Array.isArray( row.events ) ? row.events : [];
        if ( events.length ) {
            var ol = document.createElement( 'ol' );
            ol.className = 'tejcart-track-order__timeline';
            events.forEach( function ( ev ) {
                var li = document.createElement( 'li' );
                li.className = 'tejcart-track-order__event tejcart-track-order__event--' + ev.status;

                var when = document.createElement( 'time' );
                when.className = 'tejcart-track-order__event-time';
                if ( ev.time ) {
                    when.dateTime    = ev.time;
                    when.textContent = ev.time.replace( ' ', ' · ' );
                }
                li.appendChild( when );

                var body = document.createElement( 'div' );
                body.className = 'tejcart-track-order__event-body';
                var statusLine = document.createElement( 'strong' );
                statusLine.textContent = statusLabel( ev.status );
                body.appendChild( statusLine );
                if ( ev.location ) {
                    var loc = document.createElement( 'span' );
                    loc.className   = 'tejcart-track-order__event-location';
                    loc.textContent = ' · ' + ev.location;
                    body.appendChild( loc );
                }
                if ( ev.message ) {
                    var msg = document.createElement( 'p' );
                    msg.className   = 'tejcart-track-order__event-message';
                    msg.textContent = ev.message;
                    body.appendChild( msg );
                }
                li.appendChild( body );
                ol.appendChild( li );
            } );
            wrap.appendChild( ol );
        }
        return wrap;
    }

    function buildResultsTable( rows ) {
        if ( ! rows.length ) {
            var empty = document.createElement( 'div' );
            empty.className   = 'tejcart-track-order__empty';
            empty.textContent = cfg.i18n.noShipments;
            return empty;
        }

        var container = document.createElement( 'div' );
        container.className = 'tejcart-track-order__shipments';
        rows.forEach( function ( row ) {
            container.appendChild( buildShipmentBlock( row ) );
        } );
        return container;
    }

    function setFeedback( el, message, kind ) {
        el.textContent = message || '';
        el.className   = 'tejcart-track-order__feedback';
        if ( kind ) {
            el.classList.add( 'tejcart-track-order__feedback--' + kind );
        }
    }

    function isValidEmail( s ) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( s );
    }

    function attach( root ) {
        var form     = root.querySelector( '[data-tejcart-track-form]' );
        var feedback = root.querySelector( '[data-tejcart-track-feedback]' );
        var results  = root.querySelector( '[data-tejcart-track-results]' );
        if ( ! form || ! feedback || ! results ) {
            return;
        }

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();

            var orderNumber = form.elements.order_number.value.trim();
            var email       = form.elements.email.value.trim();

            if ( ! orderNumber || ! email ) {
                setFeedback( feedback, cfg.i18n.missingFields, 'error' );
                return;
            }
            if ( ! isValidEmail( email ) ) {
                setFeedback( feedback, cfg.i18n.invalidEmail, 'error' );
                return;
            }

            setFeedback( feedback, '', null );
            results.hidden = true;
            while ( results.firstChild ) { results.removeChild( results.firstChild ); }

            var btn = form.querySelector( 'button[type="submit"]' );
            if ( btn ) { btn.disabled = true; }

            var action = cfg.ajaxAction || 'tejcart_tracking_lookup';
            var url = cfg.ajaxUrl
                + '?action=' + encodeURIComponent( action )
                + '&nonce='        + encodeURIComponent( cfg.nonce )
                + '&order_number=' + encodeURIComponent( orderNumber )
                + '&email='        + encodeURIComponent( email );

            fetch( url, { credentials: 'same-origin' } )
                .then( function ( r ) {
                    return r.json().then( function ( payload ) {
                        return { status: r.status, payload: payload };
                    } );
                } )
                .then( function ( wrapped ) {
                    if ( btn ) { btn.disabled = false; }
                    if ( wrapped.payload && wrapped.payload.success ) {
                        var rows = Array.isArray( wrapped.payload.data ) ? wrapped.payload.data : [];
                        results.appendChild( buildResultsTable( rows ) );
                        results.hidden = false;
                        return;
                    }
                    if ( wrapped.status === 429 ) {
                        setFeedback( feedback, cfg.i18n.tooMany, 'error' );
                        return;
                    }
                    if ( wrapped.status === 404 ) {
                        setFeedback( feedback, cfg.i18n.noResults, 'error' );
                        return;
                    }
                    var msg = wrapped.payload && wrapped.payload.data && wrapped.payload.data.message
                        ? wrapped.payload.data.message
                        : cfg.i18n.genericError;
                    setFeedback( feedback, msg, 'error' );
                } )
                .catch( function () {
                    if ( btn ) { btn.disabled = false; }
                    setFeedback( feedback, cfg.i18n.genericError, 'error' );
                } );
        } );
    }

    Array.prototype.forEach.call( roots, attach );
} )();
