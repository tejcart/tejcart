/**
 * TejCart Faceted Filters — Progressive Enhancement
 *
 * When JavaScript is available, this module:
 *  1. Intercepts the filter form submission and uses History API + fetch
 *     to update the product grid without a full page reload.
 *  2. Syncs checkbox/radio/price changes to URL params in real time.
 *  3. Drives the dual-thumb price range slider.
 *  4. Manages the mobile filter drawer (open/close/overlay/focus trap).
 *  5. Makes active-filter chip clicks use AJAX instead of navigation.
 *  6. Syncs result count and loading states across sidebar and content.
 *
 * The server-side rendered HTML is the baseline — this script only
 * enhances; removing it should leave a fully functional filter form.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    var DEBOUNCE_MS  = 500;
    var MOBILE_BP    = 960;
    var container    = document.querySelector( '.tejcart-shop-layout' );
    var sidebar      = document.querySelector( '.tejcart-facets' );
    var form         = document.querySelector( '.tejcart-facets-form' );
    var content      = document.querySelector( '.tejcart-shop-content' );
    var toggleBtn    = document.querySelector( '.tejcart-facets-mobile-toggle' );

    if ( ! form || ! content ) {
        return;
    }

    // Mark form as JS-enhanced (hides the submit button via CSS).
    form.classList.add( 'is-enhanced' );

    var pendingController = null;
    var debounceTimer     = null;

    // Detect clean URL support: check if the page loaded via /filter/ path.
    var FILTER_PREFIX = 'filter';
    var shopBase      = form.action ? form.action.replace( /\/$/, '' ) : '';

    function isCleanUrlPage() {
        var path = window.location.pathname;
        var base = shopBase.replace( /^https?:\/\/[^/]+/, '' ).replace( /\/$/, '' );
        return path.indexOf( base + '/' + FILTER_PREFIX + '/' ) !== -1;
    }

    // ── Collect form state into URL params ──

    function collectParams() {
        var params = new URLSearchParams();
        var data   = new FormData( form );

        // Group checkbox arrays: name[] → comma-separated value.
        var arrays = {};

        for ( var pair of data.entries() ) {
            var key = pair[0];
            var val = pair[1];

            if ( '' === val ) {
                continue;
            }

            if ( key.endsWith( '[]' ) ) {
                var base = key.slice( 0, -2 );
                if ( ! arrays[ base ] ) {
                    arrays[ base ] = [];
                }
                arrays[ base ].push( val );
            } else {
                params.set( key, val );
            }
        }

        for ( var k in arrays ) {
            if ( arrays.hasOwnProperty( k ) ) {
                params.set( k, arrays[ k ].join( ',' ) );
            }
        }

        // Remove empty price params.
        if ( ! params.get( 'min_price' ) ) { params.delete( 'min_price' ); }
        if ( ! params.get( 'max_price' ) ) { params.delete( 'max_price' ); }

        return params;
    }

    /**
     * Build a clean filter path from current form state.
     * Returns '' if no filters are active.
     */
    function buildFilterPath() {
        var data     = new FormData( form );
        var segments = [];
        var arrays   = {};

        for ( var pair of data.entries() ) {
            var key = pair[0];
            var val = pair[1];
            if ( '' === val ) { continue; }

            if ( key.endsWith( '[]' ) ) {
                var base = key.slice( 0, -2 );
                if ( ! arrays[ base ] ) { arrays[ base ] = []; }
                arrays[ base ].push( val );
            }
        }

        // Taxonomy/attribute segments — each group becomes one path segment.
        var facetOrder = [ 'filter_cat', 'filter_brand', 'filter_tag' ];
        facetOrder.forEach( function( fKey ) {
            if ( arrays[ fKey ] && arrays[ fKey ].length ) {
                segments.push( arrays[ fKey ].join( ',' ) );
            }
        } );

        // Attribute params (pa_*).
        for ( var aKey in arrays ) {
            if ( arrays.hasOwnProperty( aKey ) && aKey.indexOf( 'pa_' ) === 0 ) {
                segments.push( arrays[ aKey ].join( ',' ) );
            }
        }

        // Special tokens.
        var formData = new FormData( form );
        if ( formData.get( 'in_stock' ) ) {
            segments.push( 'in-stock' );
        }
        var rating = formData.get( 'filter_rating' );
        if ( rating && parseInt( rating, 10 ) >= 1 ) {
            segments.push( 'rating-' + parseInt( rating, 10 ) );
        }
        var minP = formData.get( 'min_price' );
        var maxP = formData.get( 'max_price' );
        if ( minP || maxP ) {
            var mn = parseFloat( minP ) || 0;
            var mx = parseFloat( maxP ) || 0;
            if ( mn > 0 || mx > 0 ) {
                var mnStr = mn === Math.floor( mn ) ? String( Math.floor( mn ) ) : String( mn );
                var mxStr = mx === Math.floor( mx ) ? String( Math.floor( mx ) ) : String( mx );
                segments.push( 'price-' + mnStr + '-' + mxStr );
            }
        }

        return segments.join( '/' );
    }

    function buildUrl() {
        var params    = collectParams();
        var path      = buildFilterPath();
        var useClean  = shopBase && path;

        if ( useClean ) {
            var cleanUrl = shopBase + '/' + FILTER_PREFIX + '/' + path + '/';
            // Preserve non-filter params (sort, search) as query string.
            var extra = new URLSearchParams();
            if ( params.get( 'tejcart_sort' ) ) { extra.set( 'tejcart_sort', params.get( 'tejcart_sort' ) ); }
            if ( params.get( 'tejcart_s' ) )    { extra.set( 'tejcart_s', params.get( 'tejcart_s' ) ); }
            return extra.toString() ? cleanUrl + '?' + extra.toString() : cleanUrl;
        }

        return form.action + ( params.toString() ? '?' + params.toString() : '' );
    }

    // ── Loading state helpers ──

    function setLoading( loading ) {
        if ( loading ) {
            content.classList.add( 'is-loading' );
            if ( sidebar ) { sidebar.classList.add( 'is-filtering' ); }
        } else {
            content.classList.remove( 'is-loading' );
            if ( sidebar ) { sidebar.classList.remove( 'is-filtering' ); }
        }
    }

    // ── Result count sync ──

    function syncResultCount() {
        var meta = content.querySelector( '.tejcart-shop-meta' );
        if ( ! meta ) { return; }
        var text = meta.textContent || '';
        var match = text.match( /(\d+)\s*results?/i );
        if ( ! match ) { return; }
        var countEl = sidebar ? sidebar.querySelector( '[data-tejcart-result-count]' ) : null;
        if ( countEl ) {
            countEl.textContent = match[1] + ' results';
        }
    }

    // ── Fetch filtered page via AJAX and swap content ──

    function fetchFiltered( pushState, overrideUrl ) {
        if ( pendingController ) {
            pendingController.abort();
        }

        pendingController = new AbortController();

        var url = overrideUrl || buildUrl();

        setLoading( true );

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

            // Swap shop content (grid + meta + pagination).
            var newContent = doc.querySelector( '.tejcart-shop-content' );
            if ( newContent ) {
                // Keep the active-filters chips in sync.
                var oldChips = content.querySelector( '.tejcart-active-filters' );
                var newChips = newContent.querySelector( '.tejcart-active-filters' );
                if ( oldChips && newChips ) {
                    oldChips.replaceWith( newChips );
                } else if ( newChips ) {
                    var meta = content.querySelector( '.tejcart-shop-meta' );
                    if ( meta ) { meta.before( newChips ); }
                } else if ( oldChips ) {
                    oldChips.remove();
                }

                // Swap the grid and meta.
                var oldGrid = content.querySelector( '.tejcart-product-grid' );
                var newGrid = newContent.querySelector( '.tejcart-product-grid' );
                if ( oldGrid && newGrid ) { oldGrid.replaceWith( newGrid ); }

                var oldMeta = content.querySelector( '.tejcart-shop-meta' );
                var newMeta = newContent.querySelector( '.tejcart-shop-meta' );
                if ( oldMeta && newMeta ) { oldMeta.replaceWith( newMeta ); }

                // Swap pagination.
                var oldPag = content.querySelector( '.tejcart-pagination' );
                var newPag = newContent.querySelector( '.tejcart-pagination' );
                if ( oldPag && newPag ) { oldPag.replaceWith( newPag ); }
                else if ( newPag ) { content.appendChild( newPag ); }
                else if ( oldPag ) { oldPag.remove(); }
            }

            // Update the sidebar facet counts from the fetched page.
            var newSidebar = doc.querySelector( '.tejcart-facets' );
            if ( sidebar && newSidebar ) {
                sidebar.replaceWith( newSidebar );
                sidebar = document.querySelector( '.tejcart-facets' );
                form    = document.querySelector( '.tejcart-facets-form' );
                if ( form ) {
                    form.classList.add( 'is-enhanced' );
                    bindFormEvents();
                    initPriceSlider();
                    initFacetControls();
                    initDetailsToggle();
                    reinitMobileDrawer();
                }
            }

            // Update mobile toggle badge.
            updateMobileBadge();

            // Sync result count into sidebar header.
            syncResultCount();

            setLoading( false );
            pendingController = null;

            // Re-bind pagination links.
            bindPaginationLinks();

            // Re-bind active filter chip links.
            bindChipLinks();

            // Notify other scripts (e.g. quick-view) that the grid changed.
            document.dispatchEvent( new CustomEvent( 'tejcart:grid-updated' ) );
        } )
        .catch( function ( err ) {
            if ( err.name !== 'AbortError' ) {
                setLoading( false );
            }
        } );
    }

    function debouncedFetch() {
        clearTimeout( debounceTimer );
        debounceTimer = setTimeout( function () { fetchFiltered(); }, DEBOUNCE_MS );
    }

    // ── Bind form change events ──

    function bindFormEvents() {
        if ( ! form ) { return; }

        form.addEventListener( 'change', function ( e ) {
            var target = e.target;
            if ( ! target ) { return; }

            if ( target.matches( 'input[type="checkbox"], input[type="radio"]' ) ) {
                // Sync toggle switch aria-checked.
                var toggleLabel = target.closest( '[role="switch"]' );
                if ( toggleLabel ) {
                    toggleLabel.setAttribute( 'aria-checked', target.checked ? 'true' : 'false' );
                }
                debouncedFetch();
            }
        } );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            fetchFiltered();
        } );

        // Price inputs: debounce on input.
        var priceInputs = form.querySelectorAll( '.tejcart-facet-price-input' );
        priceInputs.forEach( function ( input ) {
            input.addEventListener( 'input', debouncedFetch );
        } );
    }

    // ── Details/summary aria-expanded sync ──

    function initDetailsToggle() {
        var sections = document.querySelectorAll( '.tejcart-facet-section' );
        sections.forEach( function ( section ) {
            var summary = section.querySelector( '.tejcart-facet-heading' );
            if ( ! summary ) { return; }
            summary.setAttribute( 'aria-expanded', section.open ? 'true' : 'false' );
            section.addEventListener( 'toggle', function () {
                summary.setAttribute( 'aria-expanded', section.open ? 'true' : 'false' );
            } );
        } );
    }

    // ── Price range slider ──

    function initPriceSlider() {
        var wrap = document.querySelector( '.tejcart-facet-price' );
        if ( ! wrap ) { return; }

        var minThumb   = wrap.querySelector( '.tejcart-facet-price-thumb--min' );
        var maxThumb   = wrap.querySelector( '.tejcart-facet-price-thumb--max' );
        var fill       = wrap.querySelector( '.tejcart-facet-price-fill' );
        var minHandle  = wrap.querySelector( '.tejcart-facet-price-handle--min' );
        var maxHandle  = wrap.querySelector( '.tejcart-facet-price-handle--max' );
        var minInput   = wrap.querySelector( 'input[name="min_price"]' );
        var maxInput   = wrap.querySelector( 'input[name="max_price"]' );

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
            if ( ! maxInput.value ) {
                maxInput.value = maxThumb.value;
            }
            updateVisuals();
        }

        function onMaxSlide() {
            if ( parseFloat( maxThumb.value ) < parseFloat( minThumb.value ) ) {
                maxThumb.value = minThumb.value;
            }
            maxInput.value = maxThumb.value;
            if ( ! minInput.value ) {
                minInput.value = minThumb.value;
            }
            updateVisuals();
        }

        minThumb.addEventListener( 'input', function () {
            onMinSlide();
            debouncedFetch();
        } );

        maxThumb.addEventListener( 'input', function () {
            onMaxSlide();
            debouncedFetch();
        } );

        minInput.addEventListener( 'input', function () {
            minThumb.value = minInput.value || rangeMin;
            onMinSlide();
        } );

        maxInput.addEventListener( 'input', function () {
            maxThumb.value = maxInput.value || rangeMax;
            onMaxSlide();
        } );

        // ── Histogram bar highlighting ──

        var histogramBars = wrap.querySelectorAll( '.tejcart-facet-price-bar' );

        function updateHistogramBars() {
            if ( ! histogramBars.length ) { return; }

            var lo = parseFloat( minThumb.value );
            var hi = parseFloat( maxThumb.value );
            var bucketW = ( rangeMax - rangeMin ) / histogramBars.length;

            histogramBars.forEach( function( bar, idx ) {
                var barMin = rangeMin + ( idx * bucketW );
                var barMax = barMin + bucketW;
                if ( barMax >= lo && barMin <= hi ) {
                    bar.classList.add( 'is-active' );
                } else {
                    bar.classList.remove( 'is-active' );
                }
            } );
        }

        if ( histogramBars.length ) {
            minThumb.addEventListener( 'input', updateHistogramBars );
            maxThumb.addEventListener( 'input', updateHistogramBars );
            minInput.addEventListener( 'input', function() {
                setTimeout( updateHistogramBars, 0 );
            } );
            maxInput.addEventListener( 'input', function() {
                setTimeout( updateHistogramBars, 0 );
            } );
        }

        updateVisuals();
        updateHistogramBars();
    }

    // ── Mobile drawer ──

    var drawerOverlay = null;

    function closeDrawer() {
        if ( sidebar ) {
            sidebar.classList.add( 'is-closing' );
            sidebar.classList.remove( 'is-open' );
        }
        if ( drawerOverlay ) {
            drawerOverlay.classList.add( 'is-closing' );
            drawerOverlay.classList.remove( 'is-open' );
        }
        document.body.style.overflow = '';

        if ( toggleBtn ) {
            toggleBtn.setAttribute( 'aria-expanded', 'false' );
        }

        setTimeout( function () {
            if ( sidebar ) { sidebar.classList.remove( 'is-closing' ); }
            if ( drawerOverlay ) { drawerOverlay.classList.remove( 'is-closing' ); }
        }, 200 );
    }

    function openDrawer() {
        if ( sidebar ) { sidebar.classList.add( 'is-open' ); }
        if ( drawerOverlay ) { drawerOverlay.classList.add( 'is-open' ); }
        document.body.style.overflow = 'hidden';

        if ( toggleBtn ) {
            toggleBtn.setAttribute( 'aria-expanded', 'true' );
        }

        // Focus the close button for keyboard users.
        var closeBtn = sidebar ? sidebar.querySelector( '.tejcart-facets-close' ) : null;
        if ( closeBtn ) {
            requestAnimationFrame( function () { closeBtn.focus(); } );
        }
    }

    function addCloseButton() {
        if ( ! sidebar ) { return; }
        var header = sidebar.querySelector( '.tejcart-facets-header' );
        if ( ! header || header.querySelector( '.tejcart-facets-close' ) ) { return; }
        var closeBtn = document.createElement( 'button' );
        closeBtn.className = 'tejcart-facets-close';
        closeBtn.setAttribute( 'aria-label', 'Close filters' );
        closeBtn.innerHTML = '&times;';
        closeBtn.type = 'button';
        header.appendChild( closeBtn );
        closeBtn.addEventListener( 'click', closeDrawer );
    }

    function initMobileDrawer() {
        if ( ! sidebar || ! toggleBtn ) { return; }

        drawerOverlay = document.createElement( 'div' );
        drawerOverlay.className = 'tejcart-facets-overlay';
        document.body.appendChild( drawerOverlay );

        addCloseButton();

        toggleBtn.addEventListener( 'click', function () {
            openDrawer();
        } );

        drawerOverlay.addEventListener( 'click', closeDrawer );

        // ESC to close.
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && sidebar && sidebar.classList.contains( 'is-open' ) ) {
                closeDrawer();
                if ( toggleBtn ) { toggleBtn.focus(); }
            }
        } );

        // Focus trap inside drawer.
        sidebar.addEventListener( 'keydown', function ( e ) {
            if ( e.key !== 'Tab' || ! sidebar.classList.contains( 'is-open' ) ) { return; }

            var focusable = sidebar.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            if ( focusable.length === 0 ) { return; }

            var first = focusable[0];
            var last  = focusable[ focusable.length - 1 ];

            if ( e.shiftKey ) {
                if ( document.activeElement === first ) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if ( document.activeElement === last ) {
                    e.preventDefault();
                    first.focus();
                }
            }
        } );
    }

    function reinitMobileDrawer() {
        addCloseButton();
    }

    function updateMobileBadge() {
        var btn = document.querySelector( '.tejcart-facets-mobile-toggle' );
        if ( ! btn ) { return; }

        var badge = btn.querySelector( '.tejcart-facets-badge' );
        var count = document.querySelectorAll( '.tejcart-active-filter-chip' ).length;

        if ( count > 0 ) {
            if ( ! badge ) {
                badge = document.createElement( 'span' );
                badge.className = 'tejcart-facets-badge';
                btn.appendChild( badge );
            }
            badge.textContent = count;
        } else if ( badge ) {
            badge.remove();
        }
    }

    // ── Show more / Search within facet ──

    function initFacetControls() {
        var toggles = document.querySelectorAll( '.tejcart-facet-toggle-more' );
        toggles.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var section = btn.closest( '.tejcart-facet-section' );
                if ( ! section ) { return; }
                var isExpanded = section.classList.toggle( 'is-expanded' );
                // Update the text, preserving the chevron element.
                var chevron = btn.querySelector( '.tejcart-facet-toggle-chevron' );
                var textNode = document.createTextNode( isExpanded ? btn.dataset.less : btn.dataset.more );
                while ( btn.firstChild && btn.firstChild !== chevron ) {
                    btn.removeChild( btn.firstChild );
                }
                btn.insertBefore( textNode, chevron );
            } );
        } );

        var searches = document.querySelectorAll( '.tejcart-facet-search' );
        searches.forEach( function ( input ) {
            var section  = input.closest( '.tejcart-facet-section' );
            if ( ! section ) { return; }
            var noResult = section.querySelector( '.tejcart-facet-no-results' );

            if ( ! noResult ) {
                noResult = document.createElement( 'p' );
                noResult.className = 'tejcart-facet-no-results';
                noResult.textContent = 'No matches';
                var list = section.querySelector( '.tejcart-facet-list' );
                if ( list ) { list.after( noResult ); }
            }

            input.addEventListener( 'input', function () {
                var query  = input.value.toLowerCase().trim();
                var items  = section.querySelectorAll( '.tejcart-facet-item' );
                var toggle = section.querySelector( '.tejcart-facet-toggle-more' );

                if ( '' === query ) {
                    section.classList.remove( 'is-searching' );
                    items.forEach( function ( item ) { item.style.display = ''; } );
                    if ( toggle ) { toggle.style.display = ''; }
                    noResult.classList.remove( 'is-visible' );
                    return;
                }

                section.classList.add( 'is-searching' );
                if ( toggle ) { toggle.style.display = 'none'; }

                var anyVisible = false;
                items.forEach( function ( item ) {
                    var text  = item.querySelector( '.tejcart-facet-text' );
                    var match = text && text.textContent.toLowerCase().indexOf( query ) !== -1;
                    item.style.display = match ? '' : 'none';
                    if ( match ) { anyVisible = true; }
                } );

                if ( noResult ) {
                    noResult.classList.toggle( 'is-visible', ! anyVisible );
                }
            } );
        } );
    }

    // ── Pagination: AJAX instead of page load ──

    function bindPaginationLinks() {
        var pagLinks = content.querySelectorAll( '.tejcart-pagination a.page-numbers' );
        pagLinks.forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = link.getAttribute( 'href' );
                if ( href ) {
                    fetchFiltered( true, href );
                    window.scrollTo( { top: container ? container.offsetTop - 20 : 0, behavior: 'smooth' } );
                }
            } );
        } );
    }

    // ── Active filter chip removal via AJAX ──

    function bindChipLinks() {
        var chips = document.querySelectorAll( '.tejcart-active-filter-chip' );
        chips.forEach( function ( chip ) {
            chip.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = chip.getAttribute( 'href' );
                if ( href ) {
                    history.pushState( null, '', href );
                    fetchFiltered( false, href );
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
                    fetchFiltered( false, href );
                }
            } );
        }

        var sidebarClear = document.querySelector( '.tejcart-facets-clear' );
        if ( sidebarClear ) {
            sidebarClear.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                var href = sidebarClear.getAttribute( 'href' );
                if ( href ) {
                    history.pushState( null, '', href );
                    fetchFiltered( false, href );
                }
            } );
        }
    }

    // ── Back/forward navigation ──

    window.addEventListener( 'popstate', function () {
        fetchFiltered( false, window.location.href );
    } );

    // ── Init ──

    bindFormEvents();
    initPriceSlider();
    initMobileDrawer();
    bindPaginationLinks();
    bindChipLinks();
    initFacetControls();
    initDetailsToggle();
    updateMobileBadge();
    syncResultCount();

} )();
