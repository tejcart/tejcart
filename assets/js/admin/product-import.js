/* global window, document */
( function () {
    'use strict';

    var settings = window.tejcartImportSettings || {};
    var ajaxUrl  = settings.ajaxUrl;
    var nonce    = settings.nonce;
    var i18n     = settings.i18n || {};

    var app = document.getElementById( 'tejcart-import-app' );
    if ( ! app || ! ajaxUrl || ! nonce ) {
        return;
    }

    /* --------------------------------------------------------------------
     * State
     * ------------------------------------------------------------------ */

    var state = {
        token:        null,
        headers:      [],   // raw CSV headers, in order.
        samples:      [],   // up to N sample rows, each padded to header length.
        suggestion:   [],   // suggested mapping returned by the preview endpoint.
        fields:       [],   // [{key,label,required,description}, …].
        fieldKeySet:  {},
        requiredKeys: [],
        dryRun:       false,
        skipImages:   false,
        rowCount:     0,
        filename:     '',
        cancelled:    false,
        polling:      false,
    };

    /* --------------------------------------------------------------------
     * DOM lookups
     * ------------------------------------------------------------------ */

    var stepNav       = app.querySelectorAll( '.tejcart-import-step' );
    var panels        = app.querySelectorAll( '.tejcart-import-step-panel' );

    var uploadForm    = document.getElementById( 'tejcart-import-form' );
    var fileInput     = document.getElementById( 'tejcart_import_file' );
    var dryRunInput   = document.getElementById( 'tejcart_import_dry_run' );
    var skipImgInput  = document.getElementById( 'tejcart_import_skip_images' );
    var continueBtn   = document.getElementById( 'tejcart-import-continue' );
    var spinner       = document.getElementById( 'tejcart-import-spinner' );
    var uploadError   = document.getElementById( 'tejcart-import-upload-error' );

    var mapBody       = document.getElementById( 'tejcart-import-map-body' );
    var fileNameEl    = document.getElementById( 'tejcart-import-file-name' );
    var fileRowsEl    = document.getElementById( 'tejcart-import-file-rows' );
    var mapError      = document.getElementById( 'tejcart-import-map-error' );
    var backBtn       = document.getElementById( 'tejcart-import-back' );
    var runBtn        = document.getElementById( 'tejcart-import-run' );

    var progressBox   = document.getElementById( 'tejcart-import-progress' );
    var progressBar   = progressBox ? progressBox.querySelector( '.tejcart-import-progress-bar' ) : null;
    var progressFill  = document.getElementById( 'tejcart-import-progress-fill' );
    var progressPct   = document.getElementById( 'tejcart-import-progress-pct' );
    var progressLab   = document.getElementById( 'tejcart-import-progress-label' );
    var progressPass  = document.getElementById( 'tejcart-import-progress-pass' );
    var progressCnt   = document.getElementById( 'tejcart-import-progress-counts' );
    var cancelBtn     = document.getElementById( 'tejcart-import-cancel' );
    var resultBox     = document.getElementById( 'tejcart-import-result' );
    var doneActions   = document.getElementById( 'tejcart-import-done-actions' );
    var restartBtn    = document.getElementById( 'tejcart-import-restart' );

    /* --------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    function gotoStep ( step ) {
        for ( var i = 0; i < stepNav.length; i++ ) {
            var s = stepNav[ i ].getAttribute( 'data-step' );
            stepNav[ i ].classList.toggle( 'is-active', s === step );
            stepNav[ i ].classList.toggle( 'is-done',
                ( step === 'map' && s === 'upload' ) ||
                ( step === 'run' && ( s === 'upload' || s === 'map' ) )
            );
        }
        for ( var j = 0; j < panels.length; j++ ) {
            var p = panels[ j ].getAttribute( 'data-step-panel' );
            panels[ j ].hidden = ( p !== step );
        }
    }

    function showError ( el, msg ) {
        if ( ! el ) { return; }
        el.textContent = msg || '';
        el.hidden = ! msg;
    }

    function parseJsonOrStatus ( r ) {
        return r.text().then( function ( body ) {
            var json = null;
            try { json = JSON.parse( body ); } catch ( e ) {
                var m = body && body.match( /[\{\[][\s\S]*[\}\]]/ );
                if ( m ) { try { json = JSON.parse( m[0] ); } catch ( e2 ) {} }
            }
            return { ok: r.ok, status: r.status, json: json, body: body || '' };
        } );
    }

    function buildHttpErrorMessage ( res ) {
        var template = res.status >= 500 ? i18n.error_http_5xx
                     : res.status >= 400 ? i18n.error_http_4xx
                     : i18n.error_http;
        var msg = ( template || '' ).replace( '%1$d', String( res.status ) );
        var snippet = '';
        if ( res.body ) {
            snippet = res.body
                .replace( /<style[\s\S]*?<\/style>/gi, ' ' )
                .replace( /<script[\s\S]*?<\/script>/gi, ' ' )
                .replace( /<[^>]+>/g, ' ' )
                .replace( /\s+/g, ' ' )
                .trim();
            if ( snippet.length > 200 ) { snippet = snippet.slice( 0, 200 ) + '…'; }
        }
        if ( snippet && i18n.error_response ) {
            msg += ' ' + i18n.error_response.replace( '%s', snippet );
        }
        return msg;
    }

    function setProgress ( pct ) {
        pct = Math.max( 0, Math.min( 100, Math.round( pct * 100 ) ) );
        if ( progressFill ) { progressFill.style.width = pct + '%'; }
        if ( progressPct  ) { progressPct.textContent  = pct + '%'; }
        if ( progressBar  ) { progressBar.setAttribute( 'aria-valuenow', String( pct ) ); }
    }

    function setPassLabel ( pass ) {
        if ( ! progressPass ) { return; }
        if ( pass === 'parents' )         { progressPass.textContent = i18n.pass_parents; }
        else if ( pass === 'variations' ) { progressPass.textContent = i18n.pass_variations; }
        else if ( pass === 'references' ) { progressPass.textContent = i18n.pass_references; }
        else if ( pass === 'done' )       { progressPass.textContent = i18n.done; }
    }

    function formatCounts ( s ) {
        if ( ! s ) { return ''; }
        return ( i18n.counts || '' )
            .replace( '%1$d', s.created || 0 )
            .replace( '%2$d', s.updated || 0 )
            .replace( '%3$d', s.skipped || 0 )
            .replace( '%4$d', s.errors  || 0 );
    }

    function showResult ( ok, summary, dry, message ) {
        if ( ! resultBox ) { return; }
        resultBox.hidden = false;
        resultBox.classList.remove( 'is-success', 'is-error' );
        resultBox.classList.add( ok ? 'is-success' : 'is-error' );
        var head = ok
            ? ( dry ? i18n.done_dry : i18n.done )
            : ( message || i18n.error_generic );
        var html = '<p><strong>' + head + '</strong></p>';
        if ( summary ) {
            html += '<p>' + formatCounts( summary ) + '</p>';
            if ( summary.error_messages && summary.error_messages.length ) {
                html += '<ul>';
                for ( var i = 0; i < summary.error_messages.length && i < 50; i++ ) {
                    var li = document.createElement( 'li' );
                    li.textContent = summary.error_messages[ i ];
                    html += li.outerHTML;
                }
                html += '</ul>';
            }
        }
        resultBox.innerHTML = html;
        if ( doneActions ) { doneActions.hidden = false; }
    }

    /* --------------------------------------------------------------------
     * Step 1: Upload → request preview
     * ------------------------------------------------------------------ */

    if ( uploadForm ) {
        uploadForm.addEventListener( 'submit', function ( evt ) {
            evt.preventDefault();
            showError( uploadError, '' );

            if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
                showError( uploadError, i18n.no_file );
                return;
            }

            state.dryRun     = !! ( dryRunInput && dryRunInput.checked );
            state.skipImages = !! ( skipImgInput && skipImgInput.checked );

            continueBtn.disabled = true;
            if ( spinner ) { spinner.classList.add( 'is-active' ); }

            var fd = new FormData();
            fd.append( 'action',  'tejcart_import_preview' );
            fd.append( '_wpnonce', nonce );
            fd.append( 'tejcart_import_file', fileInput.files[0] );

            fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
                .then( parseJsonOrStatus )
                .then( function ( res ) {
                    continueBtn.disabled = false;
                    if ( spinner ) { spinner.classList.remove( 'is-active' ); }
                    var json = res.json;
                    if ( ! json || ! json.success ) {
                        var msg = ( json && json.data && json.data.message )
                                || ( ! res.ok ? buildHttpErrorMessage( res ) : i18n.error_generic );
                        showError( uploadError, msg );
                        return;
                    }
                    var d = json.data;
                    state.token        = d.token;
                    state.headers      = d.headers      || [];
                    state.samples      = d.samples      || [];
                    state.suggestion   = d.suggestion   || [];
                    state.fields       = d.fields       || [];
                    state.requiredKeys = d.required     || [];
                    state.rowCount     = d.row_count    || 0;
                    state.filename     = d.filename     || '';

                    state.fieldKeySet = {};
                    for ( var k = 0; k < state.fields.length; k++ ) {
                        state.fieldKeySet[ state.fields[ k ].key ] = state.fields[ k ];
                    }

                    if ( ! state.headers.length ) {
                        showError( uploadError, i18n.no_columns );
                        return;
                    }

                    renderMapping();
                    gotoStep( 'map' );
                } )
                .catch( function () {
                    continueBtn.disabled = false;
                    if ( spinner ) { spinner.classList.remove( 'is-active' ); }
                    showError( uploadError, i18n.error_network );
                } );
        } );
    }

    /* --------------------------------------------------------------------
     * Step 2: Mapping table
     * ------------------------------------------------------------------ */

    function renderMapping () {
        showError( mapError, '' );
        if ( fileNameEl ) {
            fileNameEl.textContent = ( i18n.file_label || '%s' ).replace( '%s', state.filename );
        }
        if ( fileRowsEl ) {
            fileRowsEl.textContent = ( i18n.rows_label || '%d' ).replace( '%d', String( state.rowCount ) );
        }

        var html = '';
        for ( var i = 0; i < state.headers.length; i++ ) {
            var header     = state.headers[ i ];
            var suggested  = state.suggestion[ i ] || '';
            var sampleHtml = '';
            for ( var s = 0; s < state.samples.length; s++ ) {
                var cell = ( state.samples[ s ][ i ] != null ) ? String( state.samples[ s ][ i ] ) : '';
                if ( cell !== '' ) {
                    sampleHtml += '<div class="tejcart-import-sample"></div>';
                }
            }

            html += '<tr data-col="' + i + '">';
            html += '<td><strong class="tejcart-import-csv-col"></strong></td>';
            html += '<td class="tejcart-import-samples-cell"></td>';
            html += '<td>'
                  + '<select class="tejcart-import-field-select" data-col="' + i + '"></select>'
                  + '<span class="tejcart-import-required-tag" hidden>' + escapeHtml( i18n.required_label || 'Required' ) + '</span>'
                  + '</td>';
            html += '</tr>';
        }
        mapBody.innerHTML = html;

        // Fill in raw text + sample values + selects (avoid raw HTML for user-controlled data).
        var rows = mapBody.querySelectorAll( 'tr' );
        for ( var r = 0; r < rows.length; r++ ) {
            var row    = rows[ r ];
            var col    = parseInt( row.getAttribute( 'data-col' ), 10 );
            var head   = state.headers[ col ] || '';
            var sCell  = row.querySelector( '.tejcart-import-samples-cell' );
            var nameEl = row.querySelector( '.tejcart-import-csv-col' );
            nameEl.textContent = head;

            if ( sCell ) {
                sCell.innerHTML = '';
                var seen = 0;
                for ( var k = 0; k < state.samples.length; k++ ) {
                    var v = state.samples[ k ][ col ];
                    if ( v == null || String( v ) === '' ) { continue; }
                    var div = document.createElement( 'div' );
                    div.className = 'tejcart-import-sample';
                    div.textContent = String( v );
                    sCell.appendChild( div );
                    seen++;
                    if ( seen >= 3 ) { break; }
                }
                if ( ! seen ) {
                    var em = document.createElement( 'em' );
                    em.className = 'tejcart-import-sample-empty';
                    em.textContent = '—';
                    sCell.appendChild( em );
                }
            }

            var sel = row.querySelector( 'select.tejcart-import-field-select' );
            if ( sel ) {
                var skipOpt = document.createElement( 'option' );
                skipOpt.value = '';
                skipOpt.textContent = i18n.do_not_import || '— Do not import —';
                sel.appendChild( skipOpt );
                for ( var f = 0; f < state.fields.length; f++ ) {
                    var fd = state.fields[ f ];
                    var opt = document.createElement( 'option' );
                    opt.value = fd.key;
                    opt.textContent = fd.label + ( fd.required ? ' *' : '' );
                    opt.title = fd.description || '';
                    sel.appendChild( opt );
                }
                var pre = state.suggestion[ col ] || '';
                if ( pre && state.fieldKeySet[ pre ] ) {
                    sel.value = pre;
                }
                sel.addEventListener( 'change', updateRequiredTagsAndDupes );
            }
        }

        updateRequiredTagsAndDupes();
    }

    function escapeHtml ( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }

    function currentMapping () {
        var out  = [];
        var sels = mapBody.querySelectorAll( 'select.tejcart-import-field-select' );
        for ( var i = 0; i < sels.length; i++ ) {
            out.push( sels[ i ].value || '' );
        }
        return out;
    }

    function updateRequiredTagsAndDupes () {
        var mapping = currentMapping();
        var counts  = {};
        var i;
        for ( i = 0; i < mapping.length; i++ ) {
            if ( mapping[ i ] ) {
                counts[ mapping[ i ] ] = ( counts[ mapping[ i ] ] || 0 ) + 1;
            }
        }
        var rows = mapBody.querySelectorAll( 'tr' );
        for ( i = 0; i < rows.length; i++ ) {
            var sel = rows[ i ].querySelector( 'select' );
            var tag = rows[ i ].querySelector( '.tejcart-import-required-tag' );
            var dup = counts[ sel.value ] > 1 && sel.value !== '';
            rows[ i ].classList.toggle( 'is-duplicate', dup );
            var required = sel.value && state.fieldKeySet[ sel.value ] && state.fieldKeySet[ sel.value ].required;
            if ( tag ) { tag.hidden = ! required; }
        }
    }

    if ( backBtn ) {
        backBtn.addEventListener( 'click', function () {
            // Treat "Back" as a cancel of the preview job so the staged file doesn't linger.
            cancelStagedJob();
            gotoStep( 'upload' );
        } );
    }

    if ( runBtn ) {
        runBtn.addEventListener( 'click', function () {
            showError( mapError, '' );
            var mapping = currentMapping();

            // Validate required fields
            var mapped = {};
            for ( var i = 0; i < mapping.length; i++ ) {
                if ( mapping[ i ] ) { mapped[ mapping[ i ] ] = ( mapped[ mapping[ i ] ] || 0 ) + 1; }
            }
            var missing = [];
            for ( var r = 0; r < state.requiredKeys.length; r++ ) {
                if ( ! mapped[ state.requiredKeys[ r ] ] ) {
                    missing.push( state.fieldKeySet[ state.requiredKeys[ r ] ].label );
                }
            }
            if ( missing.length ) {
                showError( mapError, ( i18n.missing_required || '%s' ).replace( '%s', missing.join( ', ' ) ) );
                return;
            }
            var dupes = [];
            for ( var key in mapped ) {
                if ( mapped[ key ] > 1 ) { dupes.push( state.fieldKeySet[ key ].label ); }
            }
            if ( dupes.length ) {
                showError( mapError, ( i18n.duplicate_field || '%s' ).replace( '%s', dupes.join( ', ' ) ) );
                return;
            }

            runBtn.disabled = true;
            backBtn.disabled = true;

            var fd = new FormData();
            fd.append( 'action',   'tejcart_import_start' );
            fd.append( '_wpnonce',  nonce );
            fd.append( 'token',    state.token );
            if ( state.dryRun )     { fd.append( 'tejcart_import_dry_run',     '1' ); }
            if ( state.skipImages ) { fd.append( 'tejcart_import_skip_images', '1' ); }
            for ( var idx = 0; idx < mapping.length; idx++ ) {
                fd.append( 'mapping[' + idx + ']', mapping[ idx ] );
            }

            fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
                .then( parseJsonOrStatus )
                .then( function ( res ) {
                    var json = res.json;
                    if ( ! json || ! json.success ) {
                        runBtn.disabled = false;
                        backBtn.disabled = false;
                        var msg = ( json && json.data && json.data.message )
                                || ( ! res.ok ? buildHttpErrorMessage( res ) : i18n.error_generic );
                        showError( mapError, msg );
                        return;
                    }
                    state.cancelled = false;
                    gotoStep( 'run' );
                    if ( resultBox ) { resultBox.hidden = true; resultBox.innerHTML = ''; }
                    if ( doneActions ) { doneActions.hidden = true; }
                    progressBox.hidden = false;
                    progressBox.classList.remove( 'is-error' );
                    if ( progressLab ) { progressLab.textContent = i18n.progress_label; }
                    setProgress( 0 );
                    setPassLabel( 'parents' );
                    pollChunk();
                } )
                .catch( function () {
                    runBtn.disabled = false;
                    backBtn.disabled = false;
                    showError( mapError, i18n.error_network );
                } );
        } );
    }

    /* --------------------------------------------------------------------
     * Step 3: Chunked progress
     * ------------------------------------------------------------------ */

    function pollChunk () {
        if ( state.cancelled || ! state.token ) { return; }
        var fd = new FormData();
        fd.append( 'action',  'tejcart_import_chunk' );
        fd.append( '_wpnonce', nonce );
        fd.append( 'token',    state.token );
        state.polling = true;
        fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( parseJsonOrStatus )
            .then( function ( res ) {
                state.polling = false;
                if ( state.cancelled ) { return; }
                var json = res.json;
                if ( ! json || ! json.success ) {
                    var msg = ( json && json.data && json.data.message )
                            || ( ! res.ok ? buildHttpErrorMessage( res ) : i18n.error_generic );
                    progressBox.classList.add( 'is-error' );
                    showResult( false, null, false, msg );
                    return;
                }
                var d = json.data;
                setProgress( d.progress || 0 );
                setPassLabel( d.pass );
                if ( progressCnt && d.summary ) { progressCnt.textContent = formatCounts( d.summary ); }
                if ( d.complete ) {
                    setProgress( 1 );
                    showResult( true, d.summary, ( d.summary && d.summary.dry_run ), '' );
                    state.token = null;
                    return;
                }
                setTimeout( pollChunk, 250 );
            } )
            .catch( function () {
                state.polling = false;
                progressBox.classList.add( 'is-error' );
                showResult( false, null, false, i18n.error_network );
            } );
    }

    function cancelStagedJob () {
        if ( ! state.token ) { return; }
        var fd = new FormData();
        fd.append( 'action',   'tejcart_import_cancel' );
        fd.append( '_wpnonce',  nonce );
        fd.append( 'token',    state.token );
        // Fire-and-forget; the operator already moved on.
        fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).catch( function () {} );
        state.token = null;
    }

    if ( cancelBtn ) {
        cancelBtn.addEventListener( 'click', function () {
            state.cancelled = true;
            cancelStagedJob();
            progressBox.hidden = true;
            showResult( false, null, false, i18n.cancelled );
        } );
    }

    if ( restartBtn ) {
        restartBtn.addEventListener( 'click', function () {
            state.cancelled = false;
            state.token = null;
            if ( resultBox ) { resultBox.hidden = true; resultBox.innerHTML = ''; }
            if ( doneActions ) { doneActions.hidden = true; }
            progressBox.classList.remove( 'is-error' );
            progressBox.hidden = false;
            setProgress( 0 );
            if ( fileInput ) { fileInput.value = ''; }
            gotoStep( 'upload' );
        } );
    }

    // Initial state.
    gotoStep( 'upload' );
}() );
