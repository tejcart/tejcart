/**
 * TejCart Product Gallery & Lightbox
 *
 * Vanilla JS — no dependencies.
 *
 * @package TejCart
 */

( function () {
    'use strict';

    /**
     * Initialise all galleries on the page.
     */
    function initGalleries() {
        var galleries = document.querySelectorAll( '.tejcart-product-gallery' );

        galleries.forEach( function ( gallery ) {
            initSingleGallery( gallery );
        } );

        initLightbox();
        initFilters();
        initSort();
        initStickyAddToCart();
        initSingleProductQty();
    }

    /**
     * Single-product quantity stepper.
     *
     * +/- clicks are handled by the global stepper in tejcart-cart.js,
     * which updates the input and fires a `change` event. We only need
     * to mirror the current value into every Add-to-Cart button's
     * `data-quantity` so the global add-to-cart handler posts the
     * chosen qty.
     */
    function initSingleProductQty() {
        var scopes = document.querySelectorAll( '[data-tejcart-single-qty]' );
        if ( ! scopes.length ) { return; }

        scopes.forEach( function ( scope ) {
            var input = scope.querySelector( '.tejcart-qty-input' );
            if ( ! input ) { return; }

            var root = scope.closest( '.tejcart-single-product' ) || document;

            function sync() {
                var value = parseInt( input.value, 10 );
                if ( isNaN( value ) || value < 1 ) { value = 1; }
                input.value = value;
                root.querySelectorAll( '.tejcart-add-to-cart-btn' ).forEach( function ( btn ) {
                    btn.setAttribute( 'data-quantity', String( value ) );
                } );
            }

            input.addEventListener( 'input', sync );
            input.addEventListener( 'change', sync );

            sync();
        } );
    }

    /**
     * Mobile sticky Add-to-Cart bar on single product.
     *
     * When the real CTA scrolls out of the viewport on a narrow screen
     * the template-level .tejcart-single-product-sticky-cta is revealed
     * via the .is-visible class. The bar's button carries
     * .tejcart-add-to-cart-btn, so clicks are handled by the global
     * tejcart-cart.js listener — no extra wiring.
     */
    function initStickyAddToCart() {
        var sticky = document.querySelector( '[data-tejcart-sticky-atc]' );
        if ( ! sticky || typeof IntersectionObserver === 'undefined' ) { return; }

        var actions = document.querySelector( '.tejcart-single-product-actions' );
        if ( ! actions ) { return; }

        var stickyBtn = sticky.querySelector( '.tejcart-add-to-cart-btn' );

        var observer = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    sticky.classList.remove( 'is-visible' );
                    sticky.setAttribute( 'aria-hidden', 'true' );
                    if ( stickyBtn ) {
                        stickyBtn.setAttribute( 'tabindex', '-1' );
                    }
                } else {
                    sticky.classList.add( 'is-visible' );
                    sticky.setAttribute( 'aria-hidden', 'false' );
                    if ( stickyBtn ) {
                        stickyBtn.removeAttribute( 'tabindex' );
                    }
                }
            } );
        }, { threshold: 0, rootMargin: '0px 0px -10% 0px' } );

        observer.observe( actions );
    }

    /**
     * Wire up thumbnail click events for a single gallery.
     *
     * @param {HTMLElement} gallery Gallery wrapper element.
     */
    function initSingleGallery( gallery ) {
        var mainImage  = gallery.querySelector( '.tejcart-gallery-main-image' );
        var thumbs     = gallery.querySelectorAll( '.tejcart-gallery-thumb' );
        var mainWrap   = gallery.querySelector( '.tejcart-gallery-main' );

        if ( ! mainImage ) {
            return;
        }

        function switchToThumb( thumb ) {
            var largeSrc = thumb.getAttribute( 'data-large' );
            var fullSrc  = thumb.getAttribute( 'data-full' );

            if ( largeSrc || fullSrc ) {
                mainImage.removeAttribute( 'srcset' );
                mainImage.removeAttribute( 'sizes' );
            }

            if ( largeSrc ) {
                mainImage.src = largeSrc;
            } else if ( fullSrc ) {
                mainImage.src = fullSrc;
            }
            if ( fullSrc ) {
                mainImage.setAttribute( 'data-full', fullSrc );
            }

            thumbs.forEach( function ( t ) {
                t.classList.remove( 'active' );
            } );
            thumb.classList.add( 'active' );
        }

        thumbs.forEach( function ( thumb ) {
            thumb.addEventListener( 'click', function () {
                switchToThumb( this );
            } );
        } );

        mainImage.addEventListener( 'click', function () {
            openLightbox( gallery );
        } );

        mainImage.setAttribute( 'tabindex', '0' );
        mainImage.setAttribute( 'role', 'button' );
        mainImage.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                openLightbox( gallery );
            }
        } );

        if ( mainWrap ) {
            initGalleryZoom( gallery, mainWrap, mainImage );
        }
        if ( thumbs.length > 1 ) {
            initGallerySwipe( gallery, mainWrap || mainImage, thumbs, switchToThumb );
        }
    }

    /**
     * Desktop hover-to-zoom on the gallery main image.
     *
     * Shows the full-resolution image inside a positioned lens overlay
     * that follows the pointer. The lens is purely CSS-driven (a
     * background-image on the main wrapper) so there is no extra DOM
     * except the single overlay div. On touch-only devices the hover
     * path never fires; pinch-to-zoom is handled separately.
     */
    function initGalleryZoom( gallery, mainWrap, mainImage ) {
        var zoomOverlay = document.createElement( 'div' );
        zoomOverlay.className = 'tejcart-gallery-zoom-overlay';
        zoomOverlay.setAttribute( 'aria-hidden', 'true' );
        mainWrap.appendChild( zoomOverlay );

        var isTouch = false;
        var ZOOM_FACTOR = 2.5;
        var isZoomActive = false;
        var fullSrcCached = '';

        mainWrap.addEventListener( 'touchstart', function () {
            isTouch = true;
        }, { passive: true, once: true } );

        function startZoom( e ) {
            if ( isTouch ) { return; }
            var fullSrc = mainImage.getAttribute( 'data-full' ) || mainImage.src;
            if ( ! fullSrc ) { return; }
            fullSrcCached = fullSrc;
            zoomOverlay.style.backgroundImage = 'url(' + fullSrc + ')';
            zoomOverlay.classList.add( 'is-active' );
            isZoomActive = true;
            moveZoom( e );
        }

        function moveZoom( e ) {
            if ( ! isZoomActive ) { return; }
            var rect = mainWrap.getBoundingClientRect();
            var x = ( e.clientX - rect.left ) / rect.width;
            var y = ( e.clientY - rect.top ) / rect.height;
            x = Math.max( 0, Math.min( 1, x ) );
            y = Math.max( 0, Math.min( 1, y ) );
            zoomOverlay.style.backgroundSize = ( ZOOM_FACTOR * 100 ) + '% ' + ( ZOOM_FACTOR * 100 ) + '%';
            zoomOverlay.style.backgroundPosition = ( x * 100 ) + '% ' + ( y * 100 ) + '%';
        }

        function endZoom() {
            if ( ! isZoomActive ) { return; }
            zoomOverlay.classList.remove( 'is-active' );
            isZoomActive = false;
        }

        mainWrap.addEventListener( 'mouseenter', startZoom );
        mainWrap.addEventListener( 'mousemove', moveZoom );
        mainWrap.addEventListener( 'mouseleave', endZoom );

        var zoomBtn = gallery.querySelector( '.tejcart-gallery-zoom-btn' );
        if ( zoomBtn ) {
            zoomBtn.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                openLightbox( gallery );
            } );
        }

        initPinchZoom( mainWrap, mainImage );
    }

    /**
     * Mobile pinch-to-zoom on the gallery main image.
     *
     * Uses touch events to detect two-finger pinch gestures and applies
     * CSS transform scale + translate to zoom into the image. Resets on
     * pinch end if scale returns below 1.1x.
     */
    function initPinchZoom( container, image ) {
        var scale = 1;
        var startDist = 0;
        var startScale = 1;
        var translateX = 0;
        var translateY = 0;
        var startMidX = 0;
        var startMidY = 0;
        var startTranslateX = 0;
        var startTranslateY = 0;
        var isPinching = false;

        function dist( t1, t2 ) {
            var dx = t1.clientX - t2.clientX;
            var dy = t1.clientY - t2.clientY;
            return Math.sqrt( dx * dx + dy * dy );
        }

        function applyTransform() {
            image.style.transform = 'scale(' + scale + ') translate(' + translateX + 'px, ' + translateY + 'px)';
            image.style.transformOrigin = '0 0';
        }

        container.addEventListener( 'touchstart', function ( e ) {
            if ( e.touches.length === 2 ) {
                e.preventDefault();
                isPinching = true;
                startDist = dist( e.touches[0], e.touches[1] );
                startScale = scale;
                startMidX = ( e.touches[0].clientX + e.touches[1].clientX ) / 2;
                startMidY = ( e.touches[0].clientY + e.touches[1].clientY ) / 2;
                startTranslateX = translateX;
                startTranslateY = translateY;
                container.classList.add( 'is-pinching' );
            }
        }, { passive: false } );

        container.addEventListener( 'touchmove', function ( e ) {
            if ( ! isPinching || e.touches.length < 2 ) { return; }
            e.preventDefault();
            var currentDist = dist( e.touches[0], e.touches[1] );
            scale = Math.max( 1, Math.min( 4, startScale * ( currentDist / startDist ) ) );

            var midX = ( e.touches[0].clientX + e.touches[1].clientX ) / 2;
            var midY = ( e.touches[0].clientY + e.touches[1].clientY ) / 2;
            translateX = startTranslateX + ( midX - startMidX ) / scale;
            translateY = startTranslateY + ( midY - startMidY ) / scale;

            applyTransform();
        }, { passive: false } );

        function endPinch() {
            if ( ! isPinching ) { return; }
            isPinching = false;
            container.classList.remove( 'is-pinching' );
            if ( scale < 1.1 ) {
                scale = 1;
                translateX = 0;
                translateY = 0;
                image.style.transform = '';
                image.style.transformOrigin = '';
            }
        }

        container.addEventListener( 'touchend', endPinch, { passive: true } );
        container.addEventListener( 'touchcancel', endPinch, { passive: true } );
    }

    /**
     * Touch-swipe navigation on the gallery main image.
     *
     * A horizontal swipe > 40px switches to the prev/next thumbnail.
     * Ignored while a pinch-zoom gesture is active (the is-pinching
     * class gates both paths).
     */
    function initGallerySwipe( gallery, swipeTarget, thumbs, switchFn ) {
        var startX = 0;
        var startY = 0;
        var tracking = false;

        function activeIndex() {
            for ( var i = 0; i < thumbs.length; i++ ) {
                if ( thumbs[i].classList.contains( 'active' ) ) { return i; }
            }
            return 0;
        }

        swipeTarget.addEventListener( 'touchstart', function ( e ) {
            if ( e.touches.length !== 1 || swipeTarget.classList.contains( 'is-pinching' ) ) { return; }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            tracking = true;
        }, { passive: true } );

        swipeTarget.addEventListener( 'touchend', function ( e ) {
            if ( ! tracking ) { return; }
            tracking = false;
            if ( swipeTarget.classList.contains( 'is-pinching' ) ) { return; }
            var endX = e.changedTouches[0].clientX;
            var endY = e.changedTouches[0].clientY;
            var dx = endX - startX;
            var dy = endY - startY;
            if ( Math.abs( dx ) < 40 || Math.abs( dy ) > Math.abs( dx ) ) { return; }

            var idx = activeIndex();
            if ( dx < 0 && idx < thumbs.length - 1 ) {
                switchFn( thumbs[ idx + 1 ] );
            } else if ( dx > 0 && idx > 0 ) {
                switchFn( thumbs[ idx - 1 ] );
            }
        }, { passive: true } );
    }

    var lightboxEl       = null;
    var lightboxImage    = null;
    var currentImages    = [];
    var currentIndex     = 0;
    var previousFocus    = null;
    var lightboxKeyTrap  = null;

    /**
     * Initialise lightbox close/nav events (once).
     */
    function initLightbox() {
        lightboxEl = document.querySelector( '.tejcart-lightbox' );
        if ( ! lightboxEl ) {
            return;
        }

        lightboxImage = lightboxEl.querySelector( '.tejcart-lightbox-image' );

        var closeBtn = lightboxEl.querySelector( '.tejcart-lightbox-close' );
        if ( closeBtn ) {
            closeBtn.addEventListener( 'click', closeLightbox );
        }

        var overlay = lightboxEl.querySelector( '.tejcart-lightbox-overlay' );
        if ( overlay ) {
            overlay.addEventListener( 'click', closeLightbox );
        }

        var prevBtn = lightboxEl.querySelector( '.tejcart-lightbox-prev' );
        var nextBtn = lightboxEl.querySelector( '.tejcart-lightbox-next' );

        if ( prevBtn ) {
            prevBtn.addEventListener( 'click', function () {
                navigateLightbox( -1 );
            } );
        }
        if ( nextBtn ) {
            nextBtn.addEventListener( 'click', function () {
                navigateLightbox( 1 );
            } );
        }

        document.addEventListener( 'keydown', function ( e ) {
            if ( ! lightboxEl.classList.contains( 'active' ) ) {
                return;
            }
            if ( e.key === 'Escape' ) {
                closeLightbox();
            } else if ( e.key === 'ArrowLeft' ) {
                navigateLightbox( -1 );
            } else if ( e.key === 'ArrowRight' ) {
                navigateLightbox( 1 );
            }
        } );

        initLightboxSwipe();
    }

    /**
     * Touch-swipe navigation inside the lightbox overlay.
     */
    function initLightboxSwipe() {
        if ( ! lightboxEl ) { return; }
        var content = lightboxEl.querySelector( '.tejcart-lightbox-content' );
        if ( ! content ) { return; }

        var startX = 0;
        var tracking = false;

        content.addEventListener( 'touchstart', function ( e ) {
            if ( e.touches.length !== 1 ) { return; }
            startX = e.touches[0].clientX;
            tracking = true;
        }, { passive: true } );

        content.addEventListener( 'touchend', function ( e ) {
            if ( ! tracking ) { return; }
            tracking = false;
            var dx = e.changedTouches[0].clientX - startX;
            if ( Math.abs( dx ) < 50 ) { return; }
            navigateLightbox( dx < 0 ? 1 : -1 );
        }, { passive: true } );
    }

    /**
     * Open the lightbox showing images from a given gallery.
     *
     * @param {HTMLElement} gallery The gallery element.
     */
    function openLightbox( gallery ) {
        if ( ! lightboxEl || ! lightboxImage ) {
            return;
        }

        currentImages = [];
        var thumbs = gallery.querySelectorAll( '.tejcart-gallery-thumb' );

        if ( thumbs.length > 0 ) {
            thumbs.forEach( function ( thumb ) {
                var fullSrc = thumb.getAttribute( 'data-full' );
                if ( fullSrc ) {
                    currentImages.push( fullSrc );
                }
            } );
        } else {
            var mainImg = gallery.querySelector( '.tejcart-gallery-main-image' );
            if ( mainImg ) {
                var fullSrc = mainImg.getAttribute( 'data-full' ) || mainImg.src;
                currentImages.push( fullSrc );
            }
        }

        if ( currentImages.length === 0 ) {
            return;
        }

        var activeThumb = gallery.querySelector( '.tejcart-gallery-thumb.active' );
        currentIndex = 0;
        if ( activeThumb ) {
            var activeFull = activeThumb.getAttribute( 'data-full' );
            var idx = currentImages.indexOf( activeFull );
            if ( idx !== -1 ) {
                currentIndex = idx;
            }
        }

        lightboxImage.src = currentImages[ currentIndex ];
        lightboxEl.classList.add( 'active' );
        lightboxEl.setAttribute( 'aria-hidden', 'false' );
        document.body.style.overflow = 'hidden';

        previousFocus = document.activeElement;

        var closeBtn = lightboxEl.querySelector( '.tejcart-lightbox-close' );
        if ( closeBtn ) { closeBtn.focus(); }

        if ( ! lightboxKeyTrap ) {
            lightboxKeyTrap = function ( e ) {
                if ( e.key !== 'Tab' || ! lightboxEl.classList.contains( 'active' ) ) { return; }

                var focusables = Array.prototype.slice.call(
                    lightboxEl.querySelectorAll( '.tejcart-lightbox-close, .tejcart-lightbox-prev, .tejcart-lightbox-next' )
                ).filter( function ( el ) { return el.offsetParent !== null; } );

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
            document.addEventListener( 'keydown', lightboxKeyTrap );
        }
    }

    /**
     * Close the lightbox.
     */
    function closeLightbox() {
        if ( ! lightboxEl ) {
            return;
        }
        lightboxEl.classList.remove( 'active' );
        lightboxEl.setAttribute( 'aria-hidden', 'true' );
        document.body.style.overflow = '';

        if ( lightboxKeyTrap ) {
            document.removeEventListener( 'keydown', lightboxKeyTrap );
            lightboxKeyTrap = null;
        }

        if ( previousFocus && typeof previousFocus.focus === 'function' ) {
            try { previousFocus.focus(); } catch ( err ) {  }
        }
        previousFocus = null;
    }

    /**
     * Navigate within the lightbox.
     *
     * @param {number} direction -1 for previous, 1 for next.
     */
    function navigateLightbox( direction ) {
        if ( currentImages.length <= 1 ) {
            return;
        }
        currentIndex += direction;
        if ( currentIndex < 0 ) {
            currentIndex = currentImages.length - 1;
        } else if ( currentIndex >= currentImages.length ) {
            currentIndex = 0;
        }
        if ( lightboxImage ) {
            lightboxImage.src = currentImages[ currentIndex ];
        }
    }

    var filterAbortController = null;

    /**
     * Initialise the AJAX product filter form.
     */
    function initFilters() {
        var filterWrappers = document.querySelectorAll( '.tejcart-product-filter' );

        filterWrappers.forEach( function ( wrapper ) {
            var form    = wrapper.querySelector( '.tejcart-filter-form' );
            var results = wrapper.querySelector( '.tejcart-filter-results' );
            var nonce   = wrapper.getAttribute( 'data-nonce' );

            if ( ! form || ! results ) {
                return;
            }

            if ( ! nonce ) {
                console.error( 'TejCart filter: missing security nonce.' );
                return;
            }

            form.addEventListener( 'submit', function ( e ) {
                e.preventDefault();
                runFilter( form, results, nonce, 1 );
            } );

            form.addEventListener( 'reset', function () {
                setTimeout( function () {
                    runFilter( form, results, nonce, 1 );
                }, 50 );
            } );
        } );
    }

    /**
     * Send an AJAX request with the current filter values.
     * Cancels any in-flight request to prevent race conditions / stale data.
     *
     * @param {HTMLFormElement} form    The filter form.
     * @param {HTMLElement}     results The results container.
     * @param {string}          nonce   The security nonce.
     * @param {number}          page    The page number.
     */
    function runFilter( form, results, nonce, page ) {
        if ( typeof tejcart_params === 'undefined' ) {
            return;
        }

        if ( filterAbortController ) {
            filterAbortController.abort();
        }
        filterAbortController = new AbortController();

        var data = new FormData();
        data.append( 'action', 'tejcart_filter_products' );
        data.append( 'nonce', nonce );
        data.append( 'page', page );

        var cats = form.querySelectorAll( 'input[name="tejcart_category[]"]:checked' );
        cats.forEach( function ( cb ) {
            data.append( 'category[]', cb.value );
        } );

        var minInput = form.querySelector( 'input[name="tejcart_price_min"]' );
        var maxInput = form.querySelector( 'input[name="tejcart_price_max"]' );
        if ( minInput && minInput.value ) {
            data.append( 'price_min', minInput.value );
        }
        if ( maxInput && maxInput.value ) {
            data.append( 'price_max', maxInput.value );
        }

        var ratingInput = form.querySelector( 'input[name="tejcart_rating"]:checked' );
        if ( ratingInput ) {
            data.append( 'rating', ratingInput.value );
        }

        var stockInput = form.querySelector( 'input[name="tejcart_in_stock"]:checked' );
        if ( stockInput ) {
            data.append( 'in_stock', '1' );
        }

        var attrInputs = form.querySelectorAll( 'input[type="checkbox"][name^="tejcart_attr["]:checked' );
        attrInputs.forEach( function ( cb ) {
            data.append( cb.name, cb.value );
        } );

        var sortSelect = document.querySelector( '.tejcart-sort-select' );
        if ( sortSelect ) {
            data.append( 'sort', sortSelect.value );
        }

        results.classList.add( 'loading' );

        fetch( tejcart_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: data,
            signal: filterAbortController.signal,
        } )
        .then( function ( response ) {
            if ( ! response.ok ) {
                throw new Error( 'Server error: ' + response.status );
            }
            return response.json();
        } )
        .then( function ( json ) {
            results.classList.remove( 'loading' );
            if ( json.success && json.data ) {
                var sanitiser = window.TejCartSanitiser;
                var rawHtml   = json.data.html || '';
                var safeHtml  = ( sanitiser && typeof sanitiser.sanitiseDrawer === 'function' )
                    ? sanitiser.sanitiseDrawer( rawHtml )
                    : rawHtml;
                results.innerHTML = safeHtml || '<p>' + ( tejcart_params.i18n_no_products || 'No products found.' ) + '</p>';
            }
        } )
        .catch( function ( error ) {
            if ( error.name === 'AbortError' ) return;
            results.classList.remove( 'loading' );
        } );
    }

    /**
     * Initialise the product sort dropdown.
     */
    function initSort() {
        var sortSelects = document.querySelectorAll( '.tejcart-sort-select' );

        sortSelects.forEach( function ( select ) {
            select.addEventListener( 'change', function () {
                var filterForm = document.querySelector( '.tejcart-filter-form' );
                if ( filterForm ) {
                    filterForm.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
                    return;
                }

                var url = new URL( window.location.href );
                url.searchParams.set( 'tejcart_sort', this.value );
                window.location.href = url.toString();
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initGalleries );
    } else {
        initGalleries();
    }
} )();
