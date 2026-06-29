
( function () {
    'use strict';

    var cfg = window.tejcartSetupWizard;
    if ( ! cfg || ! cfg.ajaxUrl ) {
        return;
    }

    var root = document.querySelector( '.tejcart-wizard' );
    if ( ! root ) {
        return;
    }

    var steps         = Array.isArray( cfg.steps ) ? cfg.steps.slice() : [];
    var skippedSteps  = Array.isArray( cfg.skippedSteps ) ? cfg.skippedSteps.slice() : [];
    var completed     = [];
    var activeStep    = cfg.currentStep && steps.indexOf( cfg.currentStep ) !== -1
        ? cfg.currentStep
        : steps[0];

    ( function seedCompleted() {
        var i = steps.indexOf( activeStep );
        if ( i <= 0 ) {
            return;
        }
        for ( var s = 0; s < i; s++ ) {
            if ( skippedSteps.indexOf( steps[ s ] ) === -1 ) {
                completed.push( steps[ s ] );
            }
        }
    }() );

    /**
     * Reveal the card for the given step, hide the others, scroll to top.
     */
    function showStep( id ) {
        if ( steps.indexOf( id ) === -1 ) {
            return;
        }
        activeStep = id;
        root.setAttribute( 'data-active-step', id );

        var cards = root.querySelectorAll( '.tejcart-wizard__card' );
        cards.forEach( function ( card ) {
            if ( card.getAttribute( 'data-step' ) === id ) {
                card.hidden = false;
            } else {
                card.hidden = true;
            }
        } );

        syncStepper();
        clearError();
        applyConditionals( root.querySelector( '.tejcart-wizard__card[data-step="' + id + '"]' ) );

        if ( window.innerWidth < 720 ) {
            window.scrollTo( { top: 0, behavior: 'smooth' } );
        }
    }

    /**
     * Update stepper marker states + the progress-fill width.
     */
    function syncStepper() {
        var items = root.querySelectorAll( '.tejcart-wizard__step' );
        var activeIdx = steps.indexOf( activeStep );
        items.forEach( function ( el ) {
            var id = el.getAttribute( 'data-step' );
            el.classList.remove( 'is-active', 'is-complete' );
            if ( id === activeStep ) {
                el.classList.add( 'is-active' );
                return;
            }
            if ( completed.indexOf( id ) !== -1 ) {
                el.classList.add( 'is-complete' );
            }
        } );

        var max = steps.length - 1;
        var pct = max > 0 ? Math.round( ( activeIdx / max ) * 100 ) : 0;
        root.setAttribute( 'data-progress', '1' );
        root.style.setProperty( '--nc-progress', pct + '%' );
    }

    /**
     * Convert an HTML input name into a PHP array path nested under `fields`.
     *
     *   store_name → fields[store_name]
     *   foo[bar]   → fields[foo][bar]
     *
     * PHP's $_POST parser requires each key segment to be delimited by `][`;
     * naively wrapping a name that already contains brackets breaks nesting.
     */
    function toFieldsKey( name ) {
        var firstBracket = name.indexOf( '[' );
        if ( firstBracket === -1 ) {
            return 'fields[' + name + ']';
        }
        var head = name.slice( 0, firstBracket );
        var tail = name.slice( firstBracket );
        return 'fields[' + head + ']' + tail;
    }

    /**
     * Gather every named form control inside a step card into a `fields[...]`
     * POST payload. Unchecked checkboxes still post an empty value so the
     * server can distinguish "turned off" from "field absent".
     */
    function collectFields( form ) {
        var data = new FormData();
        var inputs = form.querySelectorAll( 'input, select, textarea' );
        inputs.forEach( function ( el ) {
            var name = el.getAttribute( 'name' );
            if ( ! name ) {
                return;
            }
            if ( el.type === 'radio' && ! el.checked ) {
                return;
            }
            var key = toFieldsKey( name );

            if ( el.type === 'checkbox' ) {
                data.append( key, el.checked ? ( el.value || '1' ) : '' );
                return;
            }
            data.append( key, el.value );
        } );
        return data;
    }

    /**
     * POST a step to admin-ajax. Resolves with the parsed JSON response.
     */
    function saveStep( stepId, skipped ) {
        var form = root.querySelector( '.tejcart-wizard__form[data-step="' + stepId + '"]' );
        var data = skipped ? new FormData() : ( form ? collectFields( form ) : new FormData() );

        data.append( 'action', cfg.action );
        data.append( 'nonce', cfg.nonce );
        data.append( 'step', stepId );
        data.append( 'skipped', skipped ? '1' : '0' );

        return fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data,
        } ).then( function ( res ) {
            return res.json().catch( function () {
                throw new Error( 'parse' );
            } );
        } ).then( function ( payload ) {
            if ( ! payload || ! payload.success ) {
                var msg = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : cfg.i18n.saveError;
                throw new Error( msg );
            }
            return payload.data;
        } );
    }

    function markComplete( id ) {
        if ( completed.indexOf( id ) === -1 ) {
            completed.push( id );
        }
    }
    function unmarkComplete( id ) {
        var i = completed.indexOf( id );
        if ( i !== -1 ) {
            completed.splice( i, 1 );
        }
    }

    function pillSet( stepId, skipped ) {
        var card = root.querySelector( '.tejcart-wizard__card[data-step="' + stepId + '"]' );
        if ( ! card ) {
            return;
        }
        var heading = card.querySelector( '.tejcart-wizard__card-header' );
        var existing = card.querySelector( '.tejcart-wizard__card-pill' );
        if ( skipped && ! existing && heading ) {
            var pill = document.createElement( 'span' );
            pill.className = 'tejcart-wizard__card-pill';
            pill.textContent = 'Skipped';
            heading.appendChild( pill );
        } else if ( ! skipped && existing ) {
            existing.remove();
        }
    }

    function showError( stepId, message ) {
        var card = root.querySelector( '.tejcart-wizard__card[data-step="' + stepId + '"]' );
        if ( ! card ) {
            return;
        }
        clearError();
        var banner = document.createElement( 'div' );
        banner.className = 'tejcart-wizard__error';
        banner.setAttribute( 'role', 'alert' );
        banner.textContent = message;
        card.appendChild( banner );
    }
    function clearError() {
        root.querySelectorAll( '.tejcart-wizard__error' ).forEach( function ( el ) {
            el.remove();
        } );
    }

    function setBusy( btn, busy ) {
        if ( ! btn ) {
            return;
        }
        if ( busy ) {
            btn.setAttribute( 'aria-busy', 'true' );
            btn.dataset.originalText = btn.textContent;
            btn.textContent = cfg.i18n.saving;
        } else {
            btn.removeAttribute( 'aria-busy' );
            if ( btn.dataset.originalText ) {
                btn.textContent = btn.dataset.originalText;
                delete btn.dataset.originalText;
            }
        }
    }

    /**
     * Advance the wizard: persist the step, then move on or finish.
     */
    function advance( stepId, skipped, triggerBtn ) {
        setBusy( triggerBtn, true );
        saveStep( stepId, skipped ).then( function ( data ) {
            setBusy( triggerBtn, false );

            if ( skipped ) {
                unmarkComplete( stepId );
                pillSet( stepId, true );
            } else {
                markComplete( stepId );
                pillSet( stepId, false );
            }

            if ( data.completed ) {
                window.location.href = cfg.dashboardUrl;
                return;
            }
            if ( data.nextStep ) {
                showStep( data.nextStep );
            }
        } ).catch( function ( err ) {
            setBusy( triggerBtn, false );
            showError( stepId, ( err && err.message ) || cfg.i18n.saveError );
        } );
    }

    root.addEventListener( 'click', function ( ev ) {
        var target = ev.target;
        if ( ! ( target instanceof Element ) ) {
            return;
        }

        var back = target.closest( '.tejcart-wizard__back' );
        if ( back ) {
            ev.preventDefault();
            var idx = steps.indexOf( activeStep );
            if ( idx > 0 ) {
                showStep( steps[ idx - 1 ] );
            }
            return;
        }

        var skip = target.closest( '.tejcart-wizard__skip' );
        if ( skip ) {
            ev.preventDefault();
            advance( activeStep, true, skip );
        }
    } );

    /**
     * Swap the State/Region field to match the chosen country. Renders a
     * <select> when Tax_Manager exposes a state list for that country,
     * otherwise a free-text input.
     */
    function refreshStateField( country ) {
        var current = root.querySelector( '.tejcart-wizard__state' );
        if ( ! current ) {
            return;
        }
        var parent = current.parentNode;
        var states = ( cfg.states && cfg.states[ country ] ) || null;
        var next;
        if ( states ) {
            next = document.createElement( 'select' );
            next.name = 'store_state';
            next.className = 'tejcart-wizard__state';
            Object.keys( states ).forEach( function ( code ) {
                var opt = document.createElement( 'option' );
                opt.value = code;
                opt.textContent = states[ code ];
                next.appendChild( opt );
            } );
        } else {
            next = document.createElement( 'input' );
            next.type = 'text';
            next.name = 'store_state';
            next.className = 'tejcart-wizard__state';
            next.value = '';
        }
        parent.replaceChild( next, current );
    }

    /**
     * Auto-set decimals when the currency changes (JPY=0, KWD=3, etc.).
     * Operators can override manually; we only steer the default.
     */
    function syncCurrencyDecimals( code ) {
        if ( ! cfg.currencyDecimals || typeof cfg.currencyDecimals[ code ] === 'undefined' ) {
            return;
        }
        var input = root.querySelector( '.tejcart-wizard__decimals' );
        if ( ! input || input.dataset.userTouched === '1' ) {
            return;
        }
        input.value = String( cfg.currencyDecimals[ code ] );
    }

    /**
     * Apply every conditional inside the given card scope. Called once on
     * step show and again on each relevant change event so the UI starts in
     * the right state when an operator resumes a half-finished wizard.
     */
    function applyConditionals( scope ) {
        if ( ! scope ) {
            return;
        }
        // Lead-toggle → controlled section.
        scope.querySelectorAll( '.tejcart-wizard__lead-toggle' ).forEach( function ( toggle ) {
            var key = toggle.getAttribute( 'data-controls' );
            if ( ! key ) {
                return;
            }
            var input = toggle.querySelector( 'input[type="checkbox"]' );
            var target = scope.querySelector( '.tejcart-wizard__conditional[data-conditional="' + key + '"]' );
            if ( ! input || ! target ) {
                return;
            }
            target.hidden = ! input.checked;
        } );
        // Shipping method → cost field visibility.
        var methodEl = scope.querySelector( '.tejcart-wizard__shipping-method' );
        var costEl   = scope.querySelector( '.tejcart-wizard__shipping-cost' );
        if ( methodEl && costEl ) {
            costEl.hidden = ( methodEl.value !== 'flat_rate' );
        }
    }

    root.addEventListener( 'change', function ( ev ) {
        var t = ev.target;
        if ( ! t || ! t.classList ) {
            return;
        }
        if ( t.classList.contains( 'tejcart-wizard__country' ) ) {
            refreshStateField( t.value );
            return;
        }
        if ( t.classList.contains( 'tejcart-wizard__currency' ) ) {
            syncCurrencyDecimals( t.value );
            return;
        }
        if ( t.closest && t.closest( '.tejcart-wizard__lead-toggle' ) ) {
            applyConditionals( t.closest( '.tejcart-wizard__card' ) );
            return;
        }
        if ( t.classList.contains( 'tejcart-wizard__shipping-method' ) ) {
            applyConditionals( t.closest( '.tejcart-wizard__card' ) );
        }
    } );

    // Track operator-touched decimals so syncCurrencyDecimals stops nudging it.
    root.addEventListener( 'input', function ( ev ) {
        var t = ev.target;
        if ( t && t.classList && t.classList.contains( 'tejcart-wizard__decimals' ) ) {
            t.dataset.userTouched = '1';
        }
    } );

    root.addEventListener( 'submit', function ( ev ) {
        var form = ev.target;
        if ( ! ( form instanceof HTMLFormElement ) ) {
            return;
        }
        if ( ! form.classList.contains( 'tejcart-wizard__form' ) ) {
            return;
        }
        ev.preventDefault();
        var submitBtn = form.querySelector( 'button[type="submit"]' );
        advance( form.getAttribute( 'data-step' ), false, submitBtn );
    } );

    showStep( activeStep );
}() );
