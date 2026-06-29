/**
 * TejCart Filter Blocks — Frontend AJAX Integration
 *
 * Bridges standalone filter block forms with the product grid.
 * Each filter block renders its own <form class="tejcart-filter-block-form">.
 * This script:
 *  1. Collects state from ALL filter block forms on the page.
 *  2. On any change, builds a combined URL and fetches the filtered page.
 *  3. Swaps the product grid and updates all block forms with fresh counts.
 *  4. Syncs URL via History API for shareable filtered URLs.
 *  5. Initialises price slider for any price block on the page.
 *
 * If the full sidebar filters.js is also loaded (sidebar layout), this
 * script defers to it and does not double-bind.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    if ( document.querySelector( '.tejcart-facets-form:not(.tejcart-filter-block-form)' ) ) {
        return;
    }

    var DEBOUNCE_MS = 400;
    var blockForms  = document.querySelectorAll( '.tejcart-filter-block-form' );
    var content     = document.querySelector( '.tejcart-shop-content' );
    var container   = document.querySelector( '.tejcart-shop-layout' );

    if ( ! blockForms.length || ! content ) {
        return;
    }

    var pendingController = null;
    var debounceTimer     = null;
    var shopBase          = blockForms[0].action ? blockForms[0].action.replace( /\/$/, '' ) : '';

    blockForms.forEach( function ( f ) {
        f.classList.add( 'is-enhanced' );
    } );

    function collectAllParams() {
        var params = new URLSearchParams();
        var arrays = {};

        blockForms.forEach( function ( form ) {
            var data = new FormData( form );
            for ( var pair of data.entries() ) {
                var key = pair[0];
                var val = pair[1];
                if ( '' === val ) { continue; }

                if ( key.endsWith( '[]' ) ) {
                    var base = key.slice( 0, -2 );
                    if ( ! arrays[ base ] ) { arrays[ base ] = []; }
                    if ( arrays[ base ].indexOf( val ) === -1 ) {
                        arrays[ base ].push( val );
                    }
                } else {
                    params.set( key, val );
                }
            }
        } );

        for ( var k in arrays ) {
            if ( arrays.hasOwnProperty( k ) ) {
                params.set( k, arrays[ k ].join( ',' ) );
            }
        }

        if ( ! params.get( 'min_price' ) ) { params.delete( 'min_price' ); }
        if ( ! params.get( 'max_price' ) ) { params.delete( 'max_price' ); }

        return params;
    }

    function buildUrl() {
        var params = collectAllParams();
        return params.toString()
            ? shopBase + '?' + params.toString()
            : shopBase;
    }

    function fetchFiltered( pushState ) {
        if ( pendingController ) {
            pendingController.abort();
        }
        pendingController = new AbortController();

        var url = buildUrl();
        content.classList.add( 'is-loading' );

        if ( false !== pushState ) {
            history.pushState( null, '', url );
        }

        fetch( url, {
            signal:  pendingController.signal,
            headers: { 'X-TejCart-Facet': '1' }
        } )
        .then( function ( res ) { return res.text(); } )
        .then( function ( html ) {
            var parser = new DOMParser();
            var doc    = parser.parseFromString( html, 'text/html' );

            var newContent = doc.querySelector( '.tejcart-shop-content' );
            if ( newContent ) {
                var oldGrid = content.querySelector( '.tejcart-product-grid' );
                var newGrid = newContent.querySelector( '.tejcart-product-grid' );
                if ( oldGrid && newGrid ) { oldGrid.replaceWith( newGrid ); }

                var oldMeta = content.querySelector( '.tejcart-shop-meta' );
                var newMeta = newContent.querySelector( '.tejcart-shop-meta' );
                if ( oldMeta && newMeta ) { oldMeta.replaceWith( newMeta ); }

                var oldChips = content.querySelector( '.tejcart-active-filters' );
                var newChips = newContent.querySelector( '.tejcart-active-filters' );
                if ( oldChips && newChips ) { oldChips.replaceWith( newChips ); }
                else if ( newChips ) {
                    var meta = content.querySelector( '.tejcart-shop-meta' );
                    if ( meta ) { meta.before( newChips ); }
                } else if ( oldChips ) { oldChips.remove(); }

                var oldPag = content.querySelector( '.tejcart-pagination' );
                var newPag = newContent.querySelector( '.tejcart-pagination' );
                if ( oldPag && newPag ) { oldPag.replaceWith( newPag ); }
                else if ( newPag ) { content.appendChild( newPag ); }
                else if ( oldPag ) { oldPag.remove(); }
            }

            var newBlocks = doc.querySelectorAll( '.tejcart-filter-block' );
            newBlocks.forEach( function ( newBlock ) {
                var filterType = newBlock.dataset.tejcartFilter;
                var existing   = document.querySelector(
                    '.tejcart-filter-block[data-tejcart-filter="' + filterType + '"]'
                );
                if ( existing ) {
                    existing.replaceWith( newBlock );
                }
            } );

            blockForms = document.querySelectorAll( '.tejcart-filter-block-form' );
            blockForms.forEach( function ( f ) {
                f.classList.add( 'is-enhanced' );
            } );
            bindAllForms();
            initPriceSliders();

            content.classList.remove( 'is-loading' );
            pendingController = null;

            bindPaginationLinks();
            bindChipLinks();

            document.dispatchEvent( new CustomEvent( 'tejcart:grid-updated' ) );
        } )
        .catch( function ( err ) {
            if ( err.name !== 'AbortError' ) {
                content.classList.remove( 'is-loading' );
            }
        } );
    }

    function debouncedFetch() {
        clearTimeout( debounceTimer );
        debounceTimer = setTimeout( function () { fetchFiltered(); }, DEBOUNCE_MS );
    }

    function bindAllForms() {
        blockForms.forEach( function ( form ) {
            form.addEventListener( 'change', function ( e ) {
                if ( e.target && e.target.matches( 'input[type="checkbox"], input[type="radio"]' ) ) {
                    debouncedFetch();
                }
            } );

            form.addEventListener( 'submit', function ( e ) {
                e.preventDefault();
                fetchFiltered();
            } );

            var priceInputs = form.querySelectorAll( '.tejcart-facet-price-input' );
            priceInputs.forEach( function ( input ) {
                input.addEventListener( 'input', debouncedFetch );
            } );
        } );
    }

    function initPriceSliders() {
        var wraps = document.querySelectorAll( '.tejcart-filter-block .tejcart-facet-price' );
        wraps.forEach( function ( wrap ) {
            var minThumb  = wrap.querySelector( '.tejcart-facet-price-thumb--min' );
            var maxThumb  = wrap.querySelector( '.tejcart-facet-price-thumb--max' );
            var fill      = wrap.querySelector( '.tejcart-facet-price-fill' );
            var minHandle = wrap.querySelector( '.tejcart-facet-price-handle--min' );
            var maxHandle = wrap.querySelector( '.tejcart-facet-price-handle--max' );
            var minInput  = wrap.querySelector( 'input[name="min_price"]' );
            var maxInput  = wrap.querySelector( 'input[name="max_price"]' );

            if ( ! minThumb || ! maxThumb || ! fill || ! minInput || ! maxInput ) { return; }

            var rangeMin = parseFloat( wrap.dataset.min ) || 0;
            var rangeMax = parseFloat( wrap.dataset.max ) || 100;

            function toPct( val ) {
                return ( ( val - rangeMin ) / ( rangeMax - rangeMin ) ) * 100;
            }

            function updateVisuals() {
                var lo    = parseFloat( minThumb.value );
                var hi    = parseFloat( maxThumb.value );
                var pctLo = toPct( lo );
                var pctHi = toPct( hi );
                fill.style.left  = pctLo + '%';
                fill.style.width = ( pctHi - pctLo ) + '%';
                if ( minHandle ) { minHandle.style.left = pctLo + '%'; }
                if ( maxHandle ) { maxHandle.style.left = pctHi + '%'; }
            }

            function onMinSlide() {
                if ( parseFloat( minThumb.value ) > parseFloat( maxThumb.value ) ) {
                    minThumb.value = maxThumb.value;
                }
                minInput.value = minThumb.value;
                updateVisuals();
            }

            function onMaxSlide() {
                if ( parseFloat( maxThumb.value ) < parseFloat( minThumb.value ) ) {
                    maxThumb.value = minThumb.value;
                }
                maxInput.value = maxThumb.value;
                updateVisuals();
            }

            minThumb.addEventListener( 'input', function () { onMinSlide(); debouncedFetch(); } );
            maxThumb.addEventListener( 'input', function () { onMaxSlide(); debouncedFetch(); } );
            minInput.addEventListener( 'input', function () {
                minThumb.value = minInput.value || rangeMin;
                onMinSlide();
            } );
            maxInput.addEventListener( 'input', function () {
                maxThumb.value = maxInput.value || rangeMax;
                onMaxSlide();
            } );

            var bars = wrap.querySelectorAll( '.tejcart-facet-price-bar' );
            function updateBars() {
                if ( ! bars.length ) { return; }
                var lo = parseFloat( minThumb.value );
                var hi = parseFloat( maxThumb.value );
                var bw = ( rangeMax - rangeMin ) / bars.length;
                bars.forEach( function ( bar, idx ) {
                    var bMin = rangeMin + ( idx * bw );
                    var bMax = bMin + bw;
                    bar.classList.toggle( 'is-active', bMax >= lo && bMin <= hi );
                } );
            }

            if ( bars.length ) {
                minThumb.addEventListener( 'input', updateBars );
                maxThumb.addEventListener( 'input', updateBars );
            }

            updateVisuals();
            updateBars();
        } );
    }

    function bindPaginationLinks() {
        var links = content.querySelectorAll( '.tejcart-pagination a.page-numbers' );
        links.forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = link.getAttribute( 'href' );
                if ( href ) {
                    history.pushState( null, '', href );
                    content.classList.add( 'is-loading' );
                    fetch( href, { headers: { 'X-TejCart-Facet': '1' } } )
                        .then( function ( r ) { return r.text(); } )
                        .then( function ( html ) {
                            var doc = new DOMParser().parseFromString( html, 'text/html' );
                            var nc  = doc.querySelector( '.tejcart-shop-content' );
                            if ( nc ) { content.innerHTML = nc.innerHTML; }
                            content.classList.remove( 'is-loading' );
                            bindPaginationLinks();
                            bindChipLinks();
                            window.scrollTo( { top: container ? container.offsetTop - 20 : 0, behavior: 'smooth' } );
                            document.dispatchEvent( new CustomEvent( 'tejcart:grid-updated' ) );
                        } );
                }
            } );
        } );
    }

    function bindChipLinks() {
        document.querySelectorAll( '.tejcart-active-filter-chip' ).forEach( function ( chip ) {
            chip.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = chip.getAttribute( 'href' );
                if ( href ) {
                    history.pushState( null, '', href );
                    fetchFiltered( false );
                }
            } );
        } );

        var clearAll = document.querySelector( '.tejcart-active-filters-clear' );
        if ( clearAll ) {
            clearAll.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = clearAll.getAttribute( 'href' );
                if ( href ) {
                    history.pushState( null, '', href );
                    fetchFiltered( false );
                }
            } );
        }
    }

    window.addEventListener( 'popstate', function () { fetchFiltered( false ); } );

    bindAllForms();
    initPriceSliders();
    bindPaginationLinks();
    bindChipLinks();
} )();
