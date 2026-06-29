/**
 * TejCart Order Tracking — admin metabox controller.
 *
 * Vanilla JS, no jQuery. Drives:
 *   - Add tracking form (POST tejcart_tracking_add)
 *   - Inline status select (POST tejcart_tracking_update)
 *   - Inline edit-tracking button (POST tejcart_tracking_update)
 *   - Re-poll button (POST tejcart_tracking_repoll)
 *   - Delete button (POST tejcart_tracking_delete)
 *
 * Refreshes the list after every mutation. Surfaces errors in a
 * polite live region.
 */
( function () {
    'use strict';

    if ( typeof window.tejcartOrderTracking === 'undefined' ) {
        return;
    }
    var cfg  = window.tejcartOrderTracking;
    var card = document.getElementById( 'tejcart-order-tracking-card' );
    if ( ! card ) {
        return;
    }

    var form     = card.querySelector( '[data-tejcart-tracking-form]' );
    var rowsBody = card.querySelector( '[data-tejcart-tracking-rows]' );
    var feedback = card.querySelector( '[data-tejcart-tracking-feedback]' );

    function setFeedback( message, kind ) {
        if ( ! feedback ) { return; }
        feedback.textContent = message || '';
        feedback.className   = 'tejcart-tracking-feedback';
        if ( kind ) {
            feedback.classList.add( 'tejcart-tracking-feedback--' + kind );
        }
        if ( ! message ) { return; }
        clearTimeout( feedback._timer );
        feedback._timer = setTimeout( function () {
            feedback.textContent = '';
            feedback.className   = 'tejcart-tracking-feedback';
        }, 4000 );
    }

    function carrierLabel( slug ) {
        for ( var i = 0; i < cfg.carriers.length; i++ ) {
            if ( cfg.carriers[ i ].slug === slug ) {
                return cfg.carriers[ i ].label;
            }
        }
        return slug.toUpperCase();
    }

    function statusLabel( slug ) {
        for ( var i = 0; i < cfg.statuses.length; i++ ) {
            if ( cfg.statuses[ i ].slug === slug ) {
                return cfg.statuses[ i ].label;
            }
        }
        return slug;
    }

    function buildStatusSelect( id, status ) {
        var sel = document.createElement( 'select' );
        sel.className = 'tejcart-tracking-status-select';
        sel.setAttribute( 'data-tejcart-tracking-status', '' );
        sel.setAttribute( 'data-shipment-id', String( id ) );
        sel.setAttribute( 'data-current-status', status );
        cfg.statuses.forEach( function ( s ) {
            var opt = document.createElement( 'option' );
            opt.value       = s.slug;
            opt.textContent = s.label;
            if ( s.slug === status ) { opt.selected = true; }
            sel.appendChild( opt );
        } );
        return sel;
    }

    function renderRow( row ) {
        var tr = document.createElement( 'tr' );
        tr.setAttribute( 'data-tejcart-tracking-row', '' );
        tr.setAttribute( 'data-shipment-id', String( row.id ) );
        tr.setAttribute( 'data-carrier', row.carrier || '' );

        var tdCarrier = document.createElement( 'td' );
        tdCarrier.setAttribute( 'data-tejcart-tracking-col', 'carrier' );
        tdCarrier.textContent = row.carrier_label || carrierLabel( row.carrier );
        tr.appendChild( tdCarrier );

        var tdNumber = document.createElement( 'td' );
        tdNumber.setAttribute( 'data-tejcart-tracking-col', 'number' );
        if ( row.tracking_url ) {
            var a = document.createElement( 'a' );
            a.href = row.tracking_url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = row.tracking_number;
            tdNumber.appendChild( a );
        } else {
            tdNumber.textContent = row.tracking_number;
        }
        tr.appendChild( tdNumber );

        var tdStatus = document.createElement( 'td' );
        tdStatus.setAttribute( 'data-tejcart-tracking-col', 'status' );
        tdStatus.appendChild( buildStatusSelect( row.id, row.status ) );
        var pill = document.createElement( 'span' );
        pill.className   = 'tejcart-status-pill tejcart-status-pill--' + row.status;
        pill.textContent = statusLabel( row.status );
        pill.setAttribute( 'data-tejcart-tracking-pill', '' );
        tdStatus.appendChild( pill );
        tr.appendChild( tdStatus );

        var tdActions = document.createElement( 'td' );
        tdActions.className = 'tejcart-tracking-row__actions';

        [
            { attr: 'data-tejcart-tracking-edit',   label: cfg.i18n.editLabel    || 'Edit' },
            { attr: 'data-tejcart-tracking-repoll', label: cfg.i18n.repollLabel  || 'Re-poll' },
            { attr: 'data-tejcart-tracking-delete', label: cfg.i18n.deleteLabel  || 'Delete', cls: 'button-link-delete' },
        ].forEach( function ( spec ) {
            var b = document.createElement( 'button' );
            b.type = 'button';
            b.className = 'button-link' + ( spec.cls ? ' ' + spec.cls : '' );
            b.setAttribute( spec.attr, '' );
            b.setAttribute( 'data-shipment-id', String( row.id ) );
            if ( spec.attr === 'data-tejcart-tracking-edit' ) {
                b.setAttribute( 'data-current-carrier', row.carrier || '' );
                b.setAttribute( 'data-current-number',  row.tracking_number || '' );
            }
            b.textContent = spec.label;
            tdActions.appendChild( b );
            tdActions.appendChild( document.createTextNode( ' ' ) );
        } );
        tr.appendChild( tdActions );

        return tr;
    }

    function renderEmptyRow() {
        var tr = document.createElement( 'tr' );
        tr.setAttribute( 'data-tejcart-tracking-empty', '' );
        var td = document.createElement( 'td' );
        td.colSpan = 4;
        td.textContent = cfg.i18n.noShipments;
        tr.appendChild( td );
        return tr;
    }

    function refreshList() {
        var url = cfg.ajaxUrl + '?action=tejcart_tracking_list&nonce=' +
            encodeURIComponent( cfg.nonce ) + '&order_id=' + encodeURIComponent( cfg.orderId );
        return fetch( url, { credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( payload ) {
                if ( ! payload || ! payload.success || ! Array.isArray( payload.data ) ) {
                    return;
                }
                while ( rowsBody.firstChild ) {
                    rowsBody.removeChild( rowsBody.firstChild );
                }
                if ( payload.data.length === 0 ) {
                    rowsBody.appendChild( renderEmptyRow() );
                    return;
                }
                payload.data.forEach( function ( row ) {
                    rowsBody.appendChild( renderRow( row ) );
                } );
            } );
    }

    function postForm( action, body ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce', cfg.nonce );
        Object.keys( body || {} ).forEach( function ( key ) {
            fd.append( key, body[ key ] );
        } );
        return fetch( cfg.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body:        fd,
        } ).then( function ( r ) { return r.json(); } );
    }

    function showError( payload, fallback ) {
        var msg = payload && payload.data && payload.data.message
            ? payload.data.message
            : ( fallback || cfg.i18n.genericError );
        setFeedback( msg, 'error' );
    }

    if ( form ) {
        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            var data = {
                order_id:        cfg.orderId,
                carrier:         form.elements.carrier.value,
                tracking_number: form.elements.tracking_number.value,
                service:         form.elements.service ? form.elements.service.value : '',
                status:          form.elements.status  ? form.elements.status.value  : 'shipped',
            };
            var submit = form.querySelector( 'button[type="submit"]' );
            if ( submit ) { submit.disabled = true; }
            postForm( 'tejcart_tracking_add', data ).then( function ( payload ) {
                if ( submit ) { submit.disabled = false; }
                if ( payload && payload.success ) {
                    setFeedback( cfg.i18n.addedOk, 'ok' );
                    form.reset();
                    return refreshList();
                }
                showError( payload );
            } ).catch( function () {
                if ( submit ) { submit.disabled = false; }
                setFeedback( cfg.i18n.genericError, 'error' );
            } );
        } );
    }

    if ( rowsBody ) {
        // Status select change → update.
        rowsBody.addEventListener( 'change', function ( e ) {
            var sel = e.target.closest( '[data-tejcart-tracking-status]' );
            if ( ! sel ) { return; }
            var id      = sel.getAttribute( 'data-shipment-id' );
            var current = sel.getAttribute( 'data-current-status' );
            var next    = sel.value;
            if ( ! id || next === current ) { return; }
            sel.disabled = true;
            postForm( 'tejcart_tracking_update', { id: id, status: next } ).then( function ( payload ) {
                sel.disabled = false;
                if ( payload && payload.success ) {
                    setFeedback( cfg.i18n.updatedOk, 'ok' );
                    return refreshList();
                }
                // Roll the select back if the server refused.
                sel.value = current;
                showError( payload );
            } ).catch( function () {
                sel.disabled = false;
                sel.value = current;
                setFeedback( cfg.i18n.genericError, 'error' );
            } );
        } );

        rowsBody.addEventListener( 'click', function ( e ) {
            // Delete
            var del = e.target.closest( '[data-tejcart-tracking-delete]' );
            if ( del ) {
                e.preventDefault();
                var did = del.getAttribute( 'data-shipment-id' );
                if ( ! did ) { return; }
                if ( ! window.confirm( cfg.i18n.confirmDelete ) ) { return; }
                del.disabled = true;
                postForm( 'tejcart_tracking_delete', { id: did } ).then( function ( payload ) {
                    if ( payload && payload.success ) {
                        setFeedback( cfg.i18n.deletedOk, 'ok' );
                        return refreshList();
                    }
                    del.disabled = false;
                    showError( payload );
                } ).catch( function () {
                    del.disabled = false;
                    setFeedback( cfg.i18n.genericError, 'error' );
                } );
                return;
            }

            // Edit existing
            var edit = e.target.closest( '[data-tejcart-tracking-edit]' );
            if ( edit ) {
                e.preventDefault();
                var eid    = edit.getAttribute( 'data-shipment-id' );
                var carNow = edit.getAttribute( 'data-current-carrier' ) || '';
                var numNow = edit.getAttribute( 'data-current-number' )  || '';
                var newCar = window.prompt( cfg.i18n.editCarrierPrompt + ' (e.g. usps, ups, fedex)', carNow );
                if ( null === newCar ) { return; }
                var newNum = window.prompt( cfg.i18n.editNumberPrompt, numNow );
                if ( null === newNum ) { return; }
                postForm( 'tejcart_tracking_update', {
                    id:              eid,
                    carrier:         newCar,
                    tracking_number: newNum,
                } ).then( function ( payload ) {
                    if ( payload && payload.success ) {
                        setFeedback( cfg.i18n.updatedOk, 'ok' );
                        return refreshList();
                    }
                    showError( payload );
                } ).catch( function () {
                    setFeedback( cfg.i18n.genericError, 'error' );
                } );
                return;
            }

            // Re-poll
            var rp = e.target.closest( '[data-tejcart-tracking-repoll]' );
            if ( rp ) {
                e.preventDefault();
                var rid = rp.getAttribute( 'data-shipment-id' );
                if ( ! rid ) { return; }
                rp.disabled = true;
                postForm( 'tejcart_tracking_repoll', { id: rid } ).then( function ( payload ) {
                    rp.disabled = false;
                    if ( payload && payload.success ) {
                        setFeedback( cfg.i18n.repollOk || cfg.i18n.updatedOk, 'ok' );
                        return refreshList();
                    }
                    showError( payload );
                } ).catch( function () {
                    rp.disabled = false;
                    setFeedback( cfg.i18n.genericError, 'error' );
                } );
            }
        } );
    }
} )();
