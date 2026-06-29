/**
 * Single-product reviews — progressive enhancements.
 *
 * Wires:
 *   1. "Write a review" CTA scroll/focus + tab activation.
 *   2. Star selector live feedback labels.
 *   3. Photo preview before upload.
 *   4. Photo lightbox on thumbnail click.
 *   5. Helpful vote AJAX (Yes/No buttons).
 *   6. Sort-by dropdown navigation.
 *   7. Video URL preview (YouTube/Vimeo).
 *
 * The block degrades to plain anchor + radio inputs without this script.
 */
( function () {
    'use strict';

    function activateReviewsTab( panelId ) {
        var panel = document.getElementById( panelId );
        if ( ! panel ) {
            return;
        }
        var container = panel.closest( '[data-tejcart-product-tabs]' );
        if ( ! container ) {
            return;
        }
        var key = panel.getAttribute( 'data-tejcart-tab-panel' );
        if ( ! key ) {
            return;
        }
        var trigger = container.querySelector(
            '[data-tejcart-tab="' + key.replace( /"/g, '\\"' ) + '"]'
        );
        if ( trigger ) {
            trigger.click();
        }
    }

    function wireWriteReviewCta() {
        var ctas = document.querySelectorAll( '[data-tejcart-write-review]' );
        ctas.forEach( function ( cta ) {
            cta.addEventListener( 'click', function ( event ) {
                var href = cta.getAttribute( 'href' ) || '';
                var targetId = href.charAt( 0 ) === '#' ? href.slice( 1 ) : '';
                if ( ! targetId ) {
                    return;
                }
                var target = document.getElementById( targetId );
                if ( ! target ) {
                    return;
                }

                var hiddenPanel = target.closest( '[data-tejcart-tab-panel][hidden]' );
                if ( hiddenPanel ) {
                    activateReviewsTab( hiddenPanel.id );
                }

                event.preventDefault();
                target.scrollIntoView( { behavior: 'smooth', block: 'start' } );

                var firstInput = target.querySelector(
                    'input[type="radio"], textarea, input[type="text"], input[type="email"]'
                );
                if ( firstInput ) {
                    window.setTimeout( function () {
                        firstInput.focus( { preventScroll: true } );
                    }, 80 );
                }
            } );
        } );
    }

    function wireStarFeedback() {
        var forms = document.querySelectorAll( '[data-tejcart-review-form]' );
        forms.forEach( function ( form ) {
            var selector = form.querySelector( '.tejcart-star-selector' );
            var feedback = form.querySelector( '[data-tejcart-rating-feedback]' );
            if ( ! selector || ! feedback ) {
                return;
            }

            var defaultText = feedback.textContent;

            function describe( radio ) {
                if ( ! radio ) {
                    return '';
                }
                return radio.getAttribute( 'data-rating-word' ) || '';
            }

            function paint( word ) {
                if ( word ) {
                    feedback.textContent = word;
                    feedback.setAttribute( 'data-active', 'true' );
                } else {
                    feedback.textContent = defaultText;
                    feedback.removeAttribute( 'data-active' );
                }
            }

            selector.addEventListener( 'change', function () {
                var checked = selector.querySelector( 'input[type="radio"]:checked' );
                paint( describe( checked ) );
            } );

            selector.querySelectorAll( 'label.tejcart-star-label' ).forEach( function ( label ) {
                var radio = label.querySelector( 'input[type="radio"]' );
                label.addEventListener( 'mouseenter', function () {
                    paint( describe( radio ) );
                } );
            } );

            selector.addEventListener( 'mouseleave', function () {
                var checked = selector.querySelector( 'input[type="radio"]:checked' );
                paint( describe( checked ) );
            } );
        } );
    }

    function wirePhotoPreview() {
        var inputs = document.querySelectorAll( '#tejcart-review-photos' );
        inputs.forEach( function ( input ) {
            var field = input.closest( '.tejcart-review-photos-field' );
            if ( ! field ) { return; }
            var preview = field.querySelector( '[data-tejcart-photos-preview]' );
            if ( ! preview ) { return; }
            var maxFiles = parseInt( input.getAttribute( 'data-max-files' ), 10 ) || 5;
            var maxSize  = parseInt( input.getAttribute( 'data-max-size' ), 10 ) || ( 5 * 1024 * 1024 );

            input.addEventListener( 'change', function () {
                preview.innerHTML = '';
                var files = Array.prototype.slice.call( input.files || [] );
                if ( files.length === 0 ) {
                    preview.hidden = true;
                    return;
                }
                preview.hidden = false;
                files.slice( 0, maxFiles ).forEach( function ( file ) {
                    if ( file.size > maxSize ) { return; }
                    if ( ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) { return; }
                    var reader = new FileReader();
                    reader.onload = function ( e ) {
                        var img = document.createElement( 'img' );
                        img.src = e.target.result;
                        img.alt = file.name;
                        preview.appendChild( img );
                    };
                    reader.readAsDataURL( file );
                } );
            } );
        } );
    }

    function wireReviewPhotoLightbox() {
        document.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.tejcart-review-photo' );
            if ( ! btn ) { return; }
            var fullUrl = btn.getAttribute( 'data-full' );
            if ( ! fullUrl ) { return; }
            var lightbox = document.querySelector( '.tejcart-lightbox' );
            if ( ! lightbox ) { return; }
            var img = lightbox.querySelector( '.tejcart-lightbox-image' );
            if ( ! img ) { return; }
            img.src = fullUrl;
            lightbox.classList.add( 'active' );
            lightbox.setAttribute( 'aria-hidden', 'false' );
            document.body.style.overflow = 'hidden';
            var closeBtn = lightbox.querySelector( '.tejcart-lightbox-close' );
            if ( closeBtn ) { closeBtn.focus(); }
        } );
    }

    function wireHelpfulVotes() {
        var voteContainers = document.querySelectorAll( '[data-tejcart-review-vote]' );
        voteContainers.forEach( function ( container ) {
            var commentId = container.getAttribute( 'data-comment-id' );
            var buttons = container.querySelectorAll( '.tejcart-review-vote-btn' );

            buttons.forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    if ( container.classList.contains( 'tejcart-review-vote--voted' ) ) {
                        return;
                    }

                    var vote = parseInt( btn.getAttribute( 'data-vote' ), 10 );

                    var formData = new FormData();
                    formData.append( 'action', 'tejcart_review_vote' );
                    formData.append( 'comment_id', commentId );
                    formData.append( 'vote', vote.toString() );
                    formData.append( 'nonce', ( window.tejcart_reviews_params || {} ).vote_nonce || '' );

                    container.classList.add( 'tejcart-review-vote--loading' );

                    fetch( ( window.tejcart_reviews_params || {} ).ajax_url || '/wp-admin/admin-ajax.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                    } )
                        .then( function ( response ) { return response.json(); } )
                        .then( function ( data ) {
                            container.classList.remove( 'tejcart-review-vote--loading' );

                            if ( data.success ) {
                                container.classList.add( 'tejcart-review-vote--voted' );
                                btn.classList.add( 'tejcart-review-vote-btn--selected' );

                                var yesCount = container.querySelector( '[data-vote-count="helpful"]' );
                                var noCount  = container.querySelector( '[data-vote-count="not_helpful"]' );

                                if ( vote === 1 ) {
                                    if ( yesCount ) {
                                        yesCount.textContent = '(' + data.data.helpful + ')';
                                    } else {
                                        var span = document.createElement( 'span' );
                                        span.className = 'tejcart-review-vote-count';
                                        span.setAttribute( 'data-vote-count', 'helpful' );
                                        span.textContent = '(' + data.data.helpful + ')';
                                        container.querySelector( '.tejcart-review-vote-yes' ).appendChild( span );
                                    }
                                } else {
                                    if ( noCount ) {
                                        noCount.textContent = '(' + data.data.not_helpful + ')';
                                    } else {
                                        var span2 = document.createElement( 'span' );
                                        span2.className = 'tejcart-review-vote-count';
                                        span2.setAttribute( 'data-vote-count', 'not_helpful' );
                                        span2.textContent = '(' + data.data.not_helpful + ')';
                                        container.querySelector( '.tejcart-review-vote-no' ).appendChild( span2 );
                                    }
                                }

                                buttons.forEach( function ( b ) { b.disabled = true; } );
                            } else {
                                var msg = ( data.data || {} ).message || '';
                                if ( msg ) {
                                    container.classList.add( 'tejcart-review-vote--voted' );
                                    buttons.forEach( function ( b ) { b.disabled = true; } );
                                }
                            }
                        } )
                        .catch( function () {
                            container.classList.remove( 'tejcart-review-vote--loading' );
                        } );
                } );
            } );
        } );
    }

    function wireSortDropdown() {
        var select = document.querySelector( '[data-tejcart-review-sort]' );
        if ( ! select ) { return; }

        select.addEventListener( 'change', function () {
            var url = new URL( window.location.href );
            url.searchParams.set( 'review_sort', select.value );
            url.hash = 'tejcart-reviews';
            window.location.href = url.toString();
        } );
    }

    function wireVideoPreview() {
        var input = document.getElementById( 'tejcart-review-video-url' );
        if ( ! input ) { return; }
        var preview = input.closest( '.tejcart-review-video-field' ).querySelector( '[data-tejcart-video-preview]' );
        if ( ! preview ) { return; }

        var ytRegex = /^https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{11})/i;
        var vimeoRegex = /^https?:\/\/(?:www\.)?vimeo\.com\/(\d+)/i;

        function getEmbedUrl( url ) {
            var m;
            m = url.match( ytRegex );
            if ( m ) { return 'https://www.youtube-nocookie.com/embed/' + m[1]; }
            m = url.match( vimeoRegex );
            if ( m ) { return 'https://player.vimeo.com/video/' + m[1] + '?dnt=1'; }
            return '';
        }

        var debounceTimer;
        input.addEventListener( 'input', function () {
            clearTimeout( debounceTimer );
            debounceTimer = setTimeout( function () {
                var embedUrl = getEmbedUrl( input.value.trim() );
                if ( embedUrl ) {
                    preview.hidden = false;
                    preview.innerHTML = '<iframe src="' + embedUrl + '" allowfullscreen loading="lazy"></iframe>';
                } else {
                    preview.hidden = true;
                    preview.innerHTML = '';
                }
            }, 400 );
        } );
    }

    function boot() {
        wireWriteReviewCta();
        wireStarFeedback();
        wirePhotoPreview();
        wireReviewPhotoLightbox();
        wireHelpfulVotes();
        wireSortDropdown();
        wireVideoPreview();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
} )();
