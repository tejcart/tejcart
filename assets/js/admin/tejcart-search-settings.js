( function () {
    'use strict';

    if ( typeof tejcartSearchSettings === 'undefined' ) {
        return;
    }

    var i18n = tejcartSearchSettings.i18n;

    var noteRows = document.querySelectorAll( '.tejcart-field-note' );
    var lastNote = noteRows.length ? noteRows[ noteRows.length - 1 ] : null;
    if ( ! lastNote ) {
        return;
    }

    var td = lastNote.querySelector( 'td' );
    if ( ! td ) {
        return;
    }

    var btn = document.createElement( 'button' );
    btn.type = 'button';
    btn.className = 'button button-secondary';
    btn.textContent = i18n.reindex || 'Reindex Now';

    var status = document.createElement( 'span' );
    status.style.marginLeft = '10px';

    var wrap = document.createElement( 'p' );
    wrap.appendChild( btn );
    wrap.appendChild( status );
    td.appendChild( wrap );

    var activePoll = null;

    function clearActivePoll() {
        if ( activePoll ) {
            clearInterval( activePoll );
            activePoll = null;
        }
    }

    function pollStatus() {
        clearActivePoll();
        activePoll = setInterval( function () {
            var xhr2 = new XMLHttpRequest();
            xhr2.open( 'POST', tejcartSearchSettings.ajaxUrl, true );
            xhr2.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
            xhr2.onload = function () {
                try {
                    var res = JSON.parse( xhr2.responseText );
                    if ( res.success && ! res.data.pending ) {
                        clearActivePoll();
                        status.textContent = i18n.complete || 'Reindex complete.';
                        btn.disabled = false;
                    }
                } catch ( e ) {
                    // keep polling
                }
            };
            xhr2.send(
                'action=tejcart_search_reindex_status&nonce=' + encodeURIComponent( tejcartSearchSettings.nonce )
            );
        }, 3000 );
    }

    btn.addEventListener( 'click', function () {
        btn.disabled = true;
        status.textContent = i18n.reindexing;

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', tejcartSearchSettings.ajaxUrl, true );
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
        xhr.onload = function () {
            var data;
            try {
                data = JSON.parse( xhr.responseText );
            } catch ( ex ) {
                status.textContent = i18n.error.replace( '%s', 'Invalid response' );
                btn.disabled = false;
                return;
            }
            if ( data.success ) {
                if ( data.data.status === 'queued' ) {
                    status.textContent = i18n.queued || data.data.message;
                    pollStatus();
                } else {
                    status.textContent = i18n.done.replace( '%d', data.data.indexed );
                    btn.disabled = false;
                }
            } else {
                status.textContent = i18n.error.replace( '%s', data.data || 'Unknown error' );
                btn.disabled = false;
            }
        };
        xhr.onerror = function () {
            status.textContent = i18n.error.replace( '%s', 'Network error' );
            btn.disabled = false;
        };
        xhr.send(
            'action=tejcart_search_reindex&nonce=' + encodeURIComponent( tejcartSearchSettings.nonce )
        );
    } );

    window.addEventListener( 'pagehide', clearActivePoll );
}() );
