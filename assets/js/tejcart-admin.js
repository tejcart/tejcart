/**
 * TejCart Admin Scripts.
 *
 * Handles admin-side interactions: bulk actions, dismiss notices,
 * smooth transitions, and AJAX operations within TejCart admin pages.
 *
 * @package TejCart
 */

( function ( $ ) {
	'use strict';

	var tejcartAdmin = window.tejcart_admin || {};

	/**
	 * Initialize page entrance animations.
	 */
	function initAnimations() {
		var cards = document.querySelectorAll( '.tejcart-card, .tejcart-settings-panel, .tejcart-status-card' );
		cards.forEach( function ( card, index ) {
			card.style.opacity = '0';
			card.style.transform = 'translateY(12px)';
			setTimeout( function () {
				card.style.transition = 'opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), transform 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
				card.style.opacity = '1';
				card.style.transform = 'translateY(0)';
			}, 60 * index );
		} );
	}

	/**
	 * Confirm before bulk delete actions.
	 */
	$( document ).on( 'click', '.tejcart-bulk-action-delete', function ( e ) {
		if ( ! window.confirm( 'Are you sure you want to delete the selected items?' ) ) {
			e.preventDefault();
		}
	} );

	/**
	 * Dismiss admin notices with smooth fade.
	 */
	$( document ).on( 'click', '.tejcart-notice .notice-dismiss, .tejcart-admin-wrap .notice .notice-dismiss', function () {
		var $notice = $( this ).closest( '.notice, .tejcart-notice' );
		var noticeId = $notice.data( 'notice-id' );

		$notice.css( { transition: 'opacity 0.3s, transform 0.3s', opacity: 0, transform: 'translateY(-8px)' } );
		setTimeout( function () { $notice.remove(); }, 300 );

		if ( noticeId ) {
			$.post( tejcartAdmin.ajax_url, {
				action: 'tejcart_dismiss_notice',
				notice_id: noticeId,
				nonce: tejcartAdmin.nonce
			} );
		}
	} );

	/**
	 * Toggle settings sections with smooth animation.
	 */
	$( document ).on( 'click', '.tejcart-settings-toggle', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.tejcart-settings-section' ).find( '.tejcart-settings-content' ).slideToggle( 250 );
		$( this ).toggleClass( 'active' );
	} );

	/**
	 * Confirm single-item delete links.
	 */
	$( document ).on( 'click', '.tejcart-delete-link, .tejcart-delete', function ( e ) {
		if ( ! window.confirm( 'Are you sure you want to delete this item? This cannot be undone.' ) ) {
			e.preventDefault();
		}
	} );

	/**
	 * Image upload via WordPress media library.
	 */
	$( document ).on( 'click', '.tejcart-upload-image-btn', function ( e ) {
		e.preventDefault();

		var $button  = $( this );
		var $wrap    = $button.closest( '.tejcart-product-image-wrap' );
		var $input   = $wrap.find( '.tejcart-image-id' );
		var $preview = $wrap.find( '.tejcart-image-preview' );

		var frame = wp.media( {
			title: 'Select Image',
			button: { text: 'Use Image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$input.val( attachment.id );

			var img = document.createElement( 'img' );
			var thumbUrl = attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url
				? attachment.sizes.thumbnail.url
				: attachment.url;
			img.src = thumbUrl;
			img.alt = '';
			$preview.empty().append( img );
			$wrap.find( '.tejcart-remove-image-btn' ).show();
			$button.text( tejcartAdmin.i18n_replace || 'Replace' );

			var $altRow   = $wrap.parent().find( '[data-tejcart-image-alt-row]' );
			var $altInput = $altRow.find( 'input[name="product_image_alt"]' );
			$altRow.prop( 'hidden', false );
			if ( $altInput.length && ! $altInput.val() && attachment.alt ) {
				$altInput.val( attachment.alt );
			}
		} );

		frame.open();
	} );

	/**
	 * Remove uploaded image.
	 */
	$( document ).on( 'click', '.tejcart-remove-image-btn', function ( e ) {
		e.preventDefault();
		var $button = $( this );
		var $wrap   = $button.closest( '.tejcart-product-image-wrap' );
		$wrap.find( '.tejcart-image-id' ).val( '' );
		$wrap.find( '.tejcart-image-preview' ).empty();
		$button.hide();
		$wrap.find( '.tejcart-upload-image-btn' ).text( tejcartAdmin.i18n_upload || 'Upload' );

		var $altRow = $wrap.parent().find( '[data-tejcart-image-alt-row]' );
		$altRow.prop( 'hidden', true );
		$altRow.find( 'input[name="product_image_alt"]' ).val( '' );
	} );

	/**
	 * Product gallery: add multiple images via WordPress media library.
	 */
	$( document ).on( 'click', '#tejcart-add-gallery-btn', function ( e ) {
		e.preventDefault();

		var $container = $( '#tejcart-gallery-images' );
		var $input     = $( '#product_gallery_ids' );

		var frame = wp.media( {
			title: 'Add Gallery Images',
			button: { text: 'Add to Gallery' },
			multiple: true
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' );
			var existing  = $input.val() ? $input.val().split( ',' ).filter( Boolean ) : [];

			selection.each( function ( attachment ) {
				attachment = attachment.toJSON();
				existing.push( String( attachment.id ) );

				var $thumb = $( '<div class="tejcart-gallery-thumb"></div>' ).attr( 'data-id', attachment.id );
				var img    = document.createElement( 'img' );
				img.src    = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;
				img.alt    = '';
				$thumb.append( img );

				var removeBtn       = document.createElement( 'button' );
				removeBtn.type      = 'button';
				removeBtn.className = 'tejcart-remove-gallery-image';
				removeBtn.setAttribute( 'aria-label', 'Remove' );
				removeBtn.textContent = '\u00d7';
				$thumb.append( removeBtn );

				$container.append( $thumb );
			} );

			$input.val( existing.join( ',' ) );
		} );

		frame.open();
	} );

	/**
	 * Product gallery: remove individual image.
	 */
	$( document ).on( 'click', '.tejcart-remove-gallery-image', function () {
		var $thumb = $( this ).closest( '.tejcart-gallery-thumb' );
		var id     = String( $thumb.data( 'id' ) );
		var $input = $( '#product_gallery_ids' );
		var ids    = $input.val() ? $input.val().split( ',' ) : [];

		ids = ids.filter( function ( existingId ) {
			return existingId !== id;
		} );

		$input.val( ids.join( ',' ) );
		$thumb.remove();
	} );

	/**
	 * Downloadable files: upload file via WordPress media library.
	 */
	$( document ).on( 'click', '.tejcart-upload-download-btn', function ( e ) {
		e.preventDefault();

		var $button   = $( this );
		var $urlInput = $button.siblings( 'input[name="download_file_url[]"]' );

		var frame = wp.media( {
			title: 'Select File',
			button: { text: 'Use File' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$urlInput.val( attachment.url );
		} );

		frame.open();
	} );

	/**
	 * Initialize WordPress color picker on settings color fields.
	 *
	 * Non-theme color pickers get the plain wpColorPicker init — passing
	 * `change`/`clear` options makes wpColorPicker re-enter iris's internal
	 * change handler on every pick, which can leave the widget stuck until
	 * the user clears and repicks. Leaving the widget stock avoids that.
	 *
	 * Theme-color inputs (Design tab) need extra wiring: they drive the
	 * live-preview card *and* the settings save-bar. The save-bar listens
	 * via native `addEventListener('change', ...)`, but wpColorPicker
	 * uses jQuery `.change()` which does not reach native listeners. We
	 * dispatch a single native `input` event after iris's own call stack
	 * has unwound (via `setTimeout(..., 0)`) so the save-bar's dirty
	 * tracker fires without looping back through `change.iris`.
	 */
	$( '.tejcart-color-picker' ).each( function () {
		var $el          = $( this );
		var el           = this;
		var isThemeColor = $el.hasClass( 'tejcart-theme-color-input' );

		if ( ! isThemeColor ) {
			$el.wpColorPicker();
			return;
		}

		var flagDirty = function () {
			setTimeout( function () {
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}, 0 );
		};

		$el.wpColorPicker( {
			change: function ( _event, ui ) {
				updateThemePreview( $el.attr( 'name' ), ui.color.toString() );
				flagDirty();
			},
			clear: function () {
				updateThemePreview( $el.attr( 'name' ), '' );
				flagDirty();
			}
		} );
	} );

	/**
	 * Default hex values used when a theme-color input is empty — these
	 * must mirror the fallbacks in Theme_Colors.php so the admin preview
	 * matches exactly what the storefront will render on an unset field.
	 */
	var THEME_PREVIEW_DEFAULTS = {
		tejcart_theme_color_primary: '#111827',
		tejcart_theme_color_accent:  '',
		tejcart_theme_color_sale:    '#d72c0d'
	};

	/**
	 * Map a picker input's `name` attribute to the CSS custom property
	 * consumed by the preview card. Centralised so the preview markup
	 * and the JS updater agree on a single source of truth.
	 */
	var THEME_PREVIEW_VARS = {
		tejcart_theme_color_primary: '--preview-primary',
		tejcart_theme_color_accent:  '--preview-accent',
		tejcart_theme_color_sale:    '--preview-sale'
	};

	/**
	 * Propagate a newly-picked color to the live-preview card and
	 * refresh the button-contrast readout.
	 *
	 * @param {string} name  The `name` attribute of the color input.
	 * @param {string} value The new hex value, or '' when cleared.
	 */
	function updateThemePreview( name, value ) {
		var $preview = $( '[data-tejcart-theme-preview]' );
		if ( ! $preview.length ) {
			return;
		}

		var cssVar = THEME_PREVIEW_VARS[ name ];
		if ( ! cssVar ) {
			return;
		}

		var effective = value || THEME_PREVIEW_DEFAULTS[ name ] || '';

		if ( 'tejcart_theme_color_accent' === name && '' === effective ) {
			effective = getThemePreviewVar( $preview, '--preview-primary' ) || '#111827';
		}

		if ( effective ) {
			$preview[ 0 ].style.setProperty( cssVar, effective );
		}

		if ( 'tejcart_theme_color_primary' === name ) {
			var fg = readableTextFor( effective );
			$preview[ 0 ].style.setProperty( '--preview-primary-fg', fg );
			updateContrastReport( effective, fg );

			var accentInput = $( 'input[name="tejcart_theme_color_accent"]' );
			if ( accentInput.length && ! accentInput.val() ) {
				$preview[ 0 ].style.setProperty( '--preview-accent', effective );
			}
		}
	}

	/**
	 * Read the current computed value of a CSS variable on the preview
	 * element. Used to resolve the "accent inherits primary" fallback.
	 */
	function getThemePreviewVar( $preview, varName ) {
		return ( $preview[ 0 ].style.getPropertyValue( varName ) || '' ).trim();
	}

	/**
	 * Repaint the contrast badge (ratio + AA pass/fail) for the current
	 * primary/foreground pair.
	 */
	function updateContrastReport( bg, fg ) {
		var ratio = contrastRatio( bg, fg );
		var pass  = ratio >= 4.5;

		var $report = $( '[data-tejcart-contrast-report]' );
		$report.attr( 'data-aa-pass', pass ? '1' : '0' );
		$( '[data-tejcart-contrast-ratio]' ).text( ratio.toFixed( 2 ) + ':1' );
		$( '[data-tejcart-contrast-badge]' ).text(
			pass ? 'AA pass' : 'Below AA — may be hard to read'
		);
	}

	function hexToRgb( hex ) {
		hex = String( hex || '' ).trim().replace( /^#/, '' );
		if ( 3 === hex.length ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {
			return null;
		}
		return [
			parseInt( hex.substr( 0, 2 ), 16 ),
			parseInt( hex.substr( 2, 2 ), 16 ),
			parseInt( hex.substr( 4, 2 ), 16 )
		];
	}

	function relativeLuminance( hex ) {
		var rgb = hexToRgb( hex );
		if ( ! rgb ) { return 0; }
		var channel = function ( c ) {
			c = c / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * channel( rgb[ 0 ] ) + 0.7152 * channel( rgb[ 1 ] ) + 0.0722 * channel( rgb[ 2 ] );
	}

	function contrastRatio( a, b ) {
		var la = relativeLuminance( a );
		var lb = relativeLuminance( b );
		var light = Math.max( la, lb );
		var dark  = Math.min( la, lb );
		return ( light + 0.05 ) / ( dark + 0.05 );
	}

	function readableTextFor( hex ) {
		return relativeLuminance( hex ) > 0.5 ? '#202223' : '#ffffff';
	}

	/**
	 * Enhanced table row hover for better UX.
	 */
	$( document ).on( 'mouseenter', '.tejcart-admin-wrap .wp-list-table tbody tr', function () {
		$( this ).find( '.row-actions' ).css( 'opacity', '1' );
	} ).on( 'mouseleave', '.tejcart-admin-wrap .wp-list-table tbody tr', function () {
		$( this ).find( '.row-actions' ).css( 'opacity', '' );
	} );

	/**
	 * Settings tab smooth transition.
	 */
	$( document ).on( 'click', '.tejcart-nav-tab-wrapper .nav-tab', function () {
		var $content = $( '.tejcart-settings-form, .tejcart-reports-filters' );
		$content.css( { opacity: 0.5 } );
	} );

	/**
	 * Show a transient flash message in the top-right corner.
	 *
	 * @param {string} message Text to display.
	 * @param {string} type    'success' or 'error'.
	 */
	function showPaymentMethodFlash( message, type ) {
		var $flash = $( '<div class="tejcart-payment-method-flash"></div>' )
			.addClass( 'is-' + ( type === 'error' ? 'error' : 'success' ) )
			.text( message )
			.appendTo( document.body );

		// eslint-disable-next-line no-unused-expressions
		$flash[ 0 ].offsetHeight;
		$flash.addClass( 'is-visible' );

		setTimeout( function () {
			$flash.removeClass( 'is-visible' );
			setTimeout( function () { $flash.remove(); }, 250 );
		}, 2400 );
	}

	/**
	 * Inline AJAX enable/disable for payment methods.
	 *
	 * Bound on change, not click, so keyboard activation also works.
	 */
	$( document ).on( 'change', '.tejcart-payment-method-toggle-input', function () {
		var $input    = $( this );
		var $label    = $input.closest( '.tejcart-payment-method-toggle' );
		var $row      = $input.closest( '.tejcart-payment-method-row' );
		var $wrap     = $input.closest( '.tejcart-payments-list-wrap' );
		var gatewayId = $input.data( 'gateway-id' );
		var enabled   = $input.is( ':checked' );
		var nonce     = $wrap.data( 'toggle-nonce' );

		if ( ! gatewayId || ! nonce ) {
			return;
		}

		$label.addClass( 'is-loading' );

		$.ajax( {
			url:      tejcartAdmin.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:     'tejcart_toggle_payment_method',
				gateway_id: gatewayId,
				enabled:    enabled ? '1' : '0',
				nonce:      nonce
			}
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$row.toggleClass( 'is-enabled', enabled );

				showPaymentMethodFlash(
					( response.data && response.data.message ) || 'Saved.',
					'success'
				);
			} else {
				$input.prop( 'checked', ! enabled );
				showPaymentMethodFlash(
					( response && response.data && response.data.message ) || 'Could not update payment method.',
					'error'
				);
			}
		} ).fail( function () {
			$input.prop( 'checked', ! enabled );
			showPaymentMethodFlash( 'Network error. Please try again.', 'error' );
		} ).always( function () {
			$label.removeClass( 'is-loading' );
		} );
	} );

	/**
	 * Settings search — hybrid renderer wiring both an inline sidebar
	 * dropdown and a Cmd/Ctrl+K floating palette against the same
	 * localised index.
	 *
	 * - Sidebar input (default, discoverable): typing renders a compact
	 *   result list right below the input, replacing the tab nav. Clear
	 *   to restore the nav. ↓/↑/Enter/Esc work as a combobox.
	 * - Cmd/Ctrl+K (or `/` from outside an input): opens the centred
	 *   modal palette with bigger rows + breadcrumb + description.
	 *
	 * Both share the same scorer (label exact > prefix > substring >
	 * desc > keywords) and both deep-link to the exact field row with
	 * a flash highlight on arrival.
	 */
	function initSettingsSearchPalette() {
		var data    = window.tejcartSettingsSearch || { entries: [], i18n: {} };
		var entries = Array.isArray( data.entries ) ? data.entries : [];
		var i18n    = data.i18n || {};

		// ---- shared helpers ----

		function escapeHtml( str ) {
			return String( str || '' )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#39;' );
		}

		function q( query ) {
			return String( query || '' ).trim().toLowerCase();
		}

		function truncate( str, max ) {
			str = String( str || '' );
			if ( str.length <= max ) { return str; }
			return str.slice( 0, max - 1 ) + '…';
		}

		function highlight( text, query ) {
			var safe = escapeHtml( text );
			if ( ! query ) { return safe; }
			var lower = String( text || '' ).toLowerCase();
			var idx   = lower.indexOf( query );
			if ( idx === -1 ) { return safe; }
			var len = query.length;
			return (
				escapeHtml( text.slice( 0, idx ) ) +
				'<mark>' + escapeHtml( text.slice( idx, idx + len ) ) + '</mark>' +
				escapeHtml( text.slice( idx + len ) )
			);
		}

		function currentTabFromUrl() {
			var match = /[?&]tab=([^&#]+)/.exec( window.location.search || '' );
			return match ? decodeURIComponent( match[ 1 ] ) : 'general';
		}

		function flashHighlight( node ) {
			node.classList.add( 'tejcart-field-highlight' );
			setTimeout( function () {
				node.classList.remove( 'tejcart-field-highlight' );
			}, 1800 );
		}

		/**
		 * Score an entry against a lowercase query. Higher is better.
		 * Strategy: exact label > label-prefix > label-substring > desc
		 * substring > keywords haystack. Cheap substring scoring is fast
		 * enough at the ~150-entry scale tejcart ships with and avoids
		 * the wp.org B2 no-runtime-deps rule (no Fuse.js).
		 */
		function score( entry, query ) {
			if ( ! query ) { return 1; }
			var label = String( entry.label || '' ).toLowerCase();
			var desc  = String( entry.desc  || '' ).toLowerCase();
			var keys  = String( entry.keywords || '' );
			if ( label === query )              { return 1000; }
			if ( label.indexOf( query ) === 0 ) { return 800; }
			var labelIdx = label.indexOf( query );
			if ( labelIdx !== -1 ) { return 600 - labelIdx; }
			var descIdx = desc.indexOf( query );
			if ( descIdx !== -1 )  { return 400 - Math.min( descIdx, 200 ); }
			if ( keys.indexOf( query ) !== -1 ) { return 200; }
			return -1;
		}

		function search( query, limit ) {
			var lq = q( query );
			var matches = [];
			for ( var i = 0; i < entries.length; i++ ) {
				var s = score( entries[ i ], lq );
				if ( s >= 0 ) {
					matches.push( { entry: entries[ i ], score: s } );
				}
			}
			matches.sort( function ( a, b ) {
				if ( b.score !== a.score ) { return b.score - a.score; }
				return ( a.entry.label || '' ).localeCompare( b.entry.label || '' );
			} );
			return matches.slice( 0, limit || 50 );
		}

		/**
		 * Navigate to an entry from either renderer. Same-tab anchored
		 * picks scroll + flash without a page load; cross-tab picks let
		 * the href take over so the browser persists scroll position.
		 */
		function goToEntry( entry ) {
			if ( entry.tab && entry.tab === currentTabFromUrl() && entry.anchor ) {
				var node = document.getElementById( entry.anchor );
				if ( node ) {
					node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					flashHighlight( node );
					return;
				}
			}
			window.location.href = entry.url;
		}

		// ---- renderer factory ----
		//
		// Creates an isolated dropdown/listbox bound to its own input +
		// results container. `opts.compact` controls whether result rows
		// include the description and badge (modal: false, sidebar: true)
		// so the inline list fits the narrow sidebar without truncating.

		function createRenderer( opts ) {
			var state = { activeIdx: -1, rendered: [], lastQuery: '' };

			function buildResultHtml( entry, idx, queryLc ) {
				var crumb = [];
				if ( entry.groupLabel ) { crumb.push( escapeHtml( entry.groupLabel ) ); }
				if ( entry.tabLabel )   { crumb.push( escapeHtml( entry.tabLabel ) ); }
				if ( entry.section && entry.section !== entry.label ) {
					crumb.push( escapeHtml( entry.section ) );
				}

				var badgeText = i18n.fieldLabel || 'Setting';
				if ( entry.kind === 'section' ) { badgeText = i18n.sectionLabel || 'Section'; }
				if ( entry.kind === 'tab' )     { badgeText = i18n.tabLabel || 'Tab'; }

				var icon = entry.tabIcon
					? '<span class="dashicons ' + escapeHtml( entry.tabIcon ) + '" aria-hidden="true"></span>'
					: '';

				var labelHtml = highlight( entry.label, queryLc );
				var crumbHtml = crumb.length
					? '<span class="' + opts.resultClass + '-crumb">' + crumb.join( ' <span aria-hidden="true">›</span> ' ) + '</span>'
					: '';
				var descHtml = ( ! opts.compact && entry.desc )
					? '<span class="' + opts.resultClass + '-desc">' + highlight( truncate( entry.desc, 120 ), queryLc ) + '</span>'
					: '';
				var badgeHtml = opts.compact
					? ''
					: '<span class="' + opts.resultClass + '-badge">' + escapeHtml( badgeText ) + '</span>';

				return (
					'<a class="' + opts.resultClass + '"' +
					( idx === state.activeIdx ? ' aria-selected="true"' : '' ) +
					' role="option" id="' + opts.idPrefix + idx + '"' +
					' href="' + escapeHtml( entry.url ) + '"' +
					' data-tejcart-result-idx="' + idx + '"' +
					'>' +
						'<span class="' + opts.resultClass + '-icon">' + icon + '</span>' +
						'<span class="' + opts.resultClass + '-main">' +
							'<span class="' + opts.resultClass + '-label">' + labelHtml + '</span>' +
							crumbHtml +
							descHtml +
						'</span>' +
						badgeHtml +
					'</a>'
				);
			}

			function render( query ) {
				var lq = q( query );
				state.lastQuery = lq;

				if ( '' === lq && opts.hideOnEmpty ) {
					state.rendered = [];
					state.activeIdx = -1;
					opts.resultsEl.innerHTML = '';
					opts.resultsEl.hidden = true;
					if ( opts.emptyEl ) { opts.emptyEl.hidden = true; }
					if ( opts.onHide )  { opts.onHide(); }
					if ( opts.input )   {
						opts.input.setAttribute( 'aria-expanded', 'false' );
						opts.input.removeAttribute( 'aria-activedescendant' );
					}
					return;
				}

				var matches = search( lq, opts.limit );
				state.rendered = matches;
				state.activeIdx = matches.length ? 0 : -1;

				opts.resultsEl.hidden = false;
				if ( opts.input ) { opts.input.setAttribute( 'aria-expanded', 'true' ); }
				if ( opts.onShow ) { opts.onShow(); }

				if ( ! matches.length ) {
					opts.resultsEl.innerHTML = '';
					if ( opts.emptyEl ) { opts.emptyEl.hidden = false; }
					if ( opts.input )   { opts.input.removeAttribute( 'aria-activedescendant' ); }
					return;
				}
				if ( opts.emptyEl ) { opts.emptyEl.hidden = true; }

				var html = '';
				for ( var i = 0; i < matches.length; i++ ) {
					html += buildResultHtml( matches[ i ].entry, i, lq );
				}
				opts.resultsEl.innerHTML = html;
				updateActiveDescendant();
			}

			function updateActiveDescendant() {
				var nodes = opts.resultsEl.querySelectorAll( '.' + opts.resultClass );
				for ( var i = 0; i < nodes.length; i++ ) {
					if ( i === state.activeIdx ) {
						nodes[ i ].setAttribute( 'aria-selected', 'true' );
						nodes[ i ].classList.add( 'is-active' );
						if ( nodes[ i ].scrollIntoView ) {
							nodes[ i ].scrollIntoView( { block: 'nearest' } );
						}
					} else {
						nodes[ i ].removeAttribute( 'aria-selected' );
						nodes[ i ].classList.remove( 'is-active' );
					}
				}
				if ( opts.input && state.activeIdx >= 0 ) {
					opts.input.setAttribute( 'aria-activedescendant', opts.idPrefix + state.activeIdx );
				} else if ( opts.input ) {
					opts.input.removeAttribute( 'aria-activedescendant' );
				}
			}

			function move( delta ) {
				if ( ! state.rendered.length ) { return; }
				var n = state.rendered.length;
				state.activeIdx = ( state.activeIdx + delta + n ) % n;
				updateActiveDescendant();
			}

			function selectActive() {
				if ( state.activeIdx < 0 || state.activeIdx >= state.rendered.length ) {
					return;
				}
				var entry = state.rendered[ state.activeIdx ].entry;
				if ( opts.onSelect ) { opts.onSelect( entry ); }
			}

			function clear() {
				if ( opts.input ) { opts.input.value = ''; }
				render( '' );
			}

			// Wire input.
			if ( opts.input ) {
				opts.input.addEventListener( 'input', function () {
					render( opts.input.value );
				} );
				opts.input.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'ArrowDown' ) {
						e.preventDefault();
						move( 1 );
					} else if ( e.key === 'ArrowUp' ) {
						e.preventDefault();
						move( -1 );
					} else if ( e.key === 'Enter' ) {
						if ( state.activeIdx >= 0 ) {
							e.preventDefault();
							selectActive();
						}
					} else if ( e.key === 'Escape' ) {
						e.preventDefault();
						if ( opts.onEscape ) { opts.onEscape(); }
					}
				} );
			}

			// Wire results container.
			opts.resultsEl.addEventListener( 'click', function ( e ) {
				var link = e.target.closest ? e.target.closest( '.' + opts.resultClass ) : null;
				if ( ! link ) { return; }
				var idx = parseInt( link.getAttribute( 'data-tejcart-result-idx' ), 10 );
				if ( ! isNaN( idx ) ) {
					state.activeIdx = idx;
					e.preventDefault();
					selectActive();
				}
			} );

			opts.resultsEl.addEventListener( 'mouseover', function ( e ) {
				var link = e.target.closest ? e.target.closest( '.' + opts.resultClass ) : null;
				if ( ! link ) { return; }
				var idx = parseInt( link.getAttribute( 'data-tejcart-result-idx' ), 10 );
				if ( ! isNaN( idx ) && idx !== state.activeIdx ) {
					state.activeIdx = idx;
					updateActiveDescendant();
				}
			} );

			return { render: render, clear: clear, move: move, selectActive: selectActive, state: state };
		}

		// ---- inline (sidebar) renderer ----

		var inlineInput   = document.getElementById( 'tejcart-settings-search-input' );
		var inlineResults = document.getElementById( 'tejcart-settings-inline-results' );
		var inlineEmpty   = document.querySelector( '[data-tejcart-inline-empty]' );
		var sidebarNav    = document.querySelector( '.tejcart-settings-sidebar .tejcart-settings-nav' );

		function showSidebarNav() {
			if ( sidebarNav ) { sidebarNav.classList.remove( 'is-search-active' ); }
		}
		function hideSidebarNav() {
			if ( sidebarNav ) { sidebarNav.classList.add( 'is-search-active' ); }
		}

		if ( inlineInput && inlineResults ) {
			createRenderer( {
				input: inlineInput,
				resultsEl: inlineResults,
				emptyEl: inlineEmpty,
				resultClass: 'tejcart-settings-inline-result',
				idPrefix: 'tejcart-inline-result-',
				limit: 30,
				compact: true,
				hideOnEmpty: true,
				onShow: hideSidebarNav,
				onHide: showSidebarNav,
				onSelect: function ( entry ) { goToEntry( entry ); },
				onEscape: function () {
					inlineInput.value = '';
					inlineResults.hidden = true;
					if ( inlineEmpty ) { inlineEmpty.hidden = true; }
					showSidebarNav();
					inlineInput.setAttribute( 'aria-expanded', 'false' );
					inlineInput.blur();
				},
			} );
		}

		// ---- modal (Cmd-K) renderer ----

		var modal        = document.getElementById( 'tejcart-settings-palette' );
		var modalInput   = modal && modal.querySelector( '#tejcart-settings-palette-input' );
		var modalResults = modal && modal.querySelector( '#tejcart-settings-palette-results' );
		var modalEmpty   = modal && modal.querySelector( '[data-tejcart-palette-empty]' );
		var modalLastFocus = null;
		var modalRenderer  = null;

		function openModal() {
			if ( ! modal ) { return; }
			modalLastFocus = document.activeElement;
			modal.hidden = false;
			document.body.classList.add( 'tejcart-settings-palette-open' );

			// Dismiss the mobile sidebar drawer (if open) so the palette
			// is the sole modal surface above the page.
			var drawer   = document.getElementById( 'tejcart-settings-sidebar' );
			var backdrop = document.querySelector( '.tejcart-settings-sidebar-backdrop' );
			var toggle   = document.querySelector( '.tejcart-settings-mobile-toggle' );
			if ( drawer && drawer.classList.contains( 'is-open' ) ) {
				drawer.classList.remove( 'is-open' );
				if ( backdrop ) {
					backdrop.classList.remove( 'is-open' );
					backdrop.hidden = true;
				}
				if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
				document.body.classList.remove( 'tejcart-settings-lock' );
			}

			if ( modalRenderer ) {
				modalRenderer.clear();
				modalRenderer.render( '' );
			}
			if ( modalInput ) {
				modalInput.value = '';
				setTimeout( function () { modalInput.focus(); }, 0 );
			}
		}

		function closeModal() {
			if ( ! modal ) { return; }
			modal.hidden = true;
			document.body.classList.remove( 'tejcart-settings-palette-open' );
			if ( modalLastFocus && modalLastFocus.focus ) {
				modalLastFocus.focus();
			}
		}

		if ( modal && modalInput && modalResults ) {
			modalRenderer = createRenderer( {
				input: modalInput,
				resultsEl: modalResults,
				emptyEl: modalEmpty,
				resultClass: 'tejcart-settings-palette__result',
				idPrefix: 'tejcart-palette-result-',
				limit: 50,
				compact: false,
				hideOnEmpty: false,
				onSelect: function ( entry ) {
					closeModal();
					goToEntry( entry );
				},
				onEscape: function () { closeModal(); },
			} );

			var closers = modal.querySelectorAll( '[data-tejcart-palette-close]' );
			for ( var c = 0; c < closers.length; c++ ) {
				closers[ c ].addEventListener( 'click', closeModal );
			}
		}

		// ---- global keyboard shortcuts ----

		document.addEventListener( 'keydown', function ( e ) {
			// Cmd/Ctrl+K from anywhere toggles the modal.
			var isModK = ( e.key === 'k' || e.key === 'K' ) && ( e.metaKey || e.ctrlKey );
			if ( isModK ) {
				e.preventDefault();
				if ( ! modal || modal.hidden ) { openModal(); }
				else                           { closeModal(); }
				return;
			}

			// `/` opens the modal only when the user is not already in
			// a text-entry field — the sidebar input has its own combobox
			// so we don't want `/` to hijack it.
			if ( e.key === '/' && ! e.metaKey && ! e.ctrlKey && ! e.altKey ) {
				var tag = ( e.target && e.target.tagName ) || '';
				if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' ) {
					return;
				}
				if ( e.target && e.target.isContentEditable ) {
					return;
				}
				e.preventDefault();
				if ( modal && modal.hidden ) { openModal(); }
				return;
			}

			if ( e.key === 'Escape' && modal && ! modal.hidden ) {
				e.preventDefault();
				closeModal();
			}
		} );

		// ---- deep-link arrivals ----
		// If the page loaded with #tejcart-field-…, scroll + flash once
		// the table is laid out so the user can spot where they landed.
		var hash = ( window.location.hash || '' ).replace( /^#/, '' );
		if ( hash && hash.indexOf( 'tejcart-field-' ) === 0 ) {
			setTimeout( function () {
				var node = document.getElementById( hash );
				if ( node ) {
					node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					flashHighlight( node );
				}
			}, 100 );
		}
	}

	/**
	 * Settings sidebar: mobile open/close with backdrop, Esc-to-close
	 * and body scroll lock while open.
	 */
	function initSettingsSidebarToggle() {
		var toggle   = document.querySelector( '.tejcart-settings-mobile-toggle' );
		var sidebar  = document.getElementById( 'tejcart-settings-sidebar' );
		var backdrop = document.querySelector( '.tejcart-settings-sidebar-backdrop' );
		if ( ! toggle || ! sidebar ) {
			return;
		}

		function setOpen( open ) {
			sidebar.classList.toggle( 'is-open', open );
			if ( backdrop ) {
				backdrop.classList.toggle( 'is-open', open );
				backdrop.hidden = ! open;
			}
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.classList.toggle( 'tejcart-settings-lock', open );
		}

		toggle.addEventListener( 'click', function () {
			setOpen( ! sidebar.classList.contains( 'is-open' ) );
		} );

		if ( backdrop ) {
			backdrop.addEventListener( 'click', function () {
				setOpen( false );
			} );
		}

		sidebar.addEventListener( 'click', function ( e ) {
			var link = e.target.closest ? e.target.closest( '.tejcart-settings-nav-item' ) : null;
			if ( link ) {
				setOpen( false );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && sidebar.classList.contains( 'is-open' ) ) {
				setOpen( false );
			}
		} );
	}

	/**
	 * Settings form: dirty-state tracking, sticky save bar status,
	 * discard button, beforeunload guard.
	 */
	function initSettingsDirtyState() {
		var form = document.querySelector(
			'[data-tejcart-settings-form], [data-tejcart-product-form]'
		);
		if ( ! form ) {
			return;
		}
		var bar      = form.querySelector( '[data-tejcart-save-bar]' );
		var discard  = form.querySelector( '[data-tejcart-discard]' );
		var initial  = new FormData( form );
		var dirty    = false;

		function serialize( fd ) {
			var pairs = [];
			fd.forEach( function ( value, key ) {
				pairs.push( key + '=' + ( typeof value === 'string' ? value : '' ) );
			} );
			pairs.sort();
			return pairs.join( '&' );
		}

		var initialSerialized = serialize( initial );

		function compare() {
			var current = serialize( new FormData( form ) );
			setDirty( current !== initialSerialized );
		}

		function setDirty( value ) {
			dirty = !! value;
			if ( bar ) {
				bar.classList.toggle( 'is-dirty', dirty );
			}
		}

		form.addEventListener( 'input', compare );
		form.addEventListener( 'change', compare );

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				if ( ! dirty ) {
					return;
				}
				if ( ! window.confirm( 'Discard unsaved changes?' ) ) {
					return;
				}
				form.reset();

				setTimeout( function () {
					initialSerialized = serialize( new FormData( form ) );
					setDirty( false );
				}, 0 );
			} );
		}

		form.addEventListener( 'submit', function () {
			setDirty( false );
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! dirty ) {
				return undefined;
			}
			e.preventDefault();
			e.returnValue = '';
			return '';
		} );
	}

	/**
	 * Show a transient toast when a save just completed. Reads
	 * settings_errors() output that WP rendered at the top of the page.
	 */
	function initSettingsSaveToast() {
		if ( ! document.querySelector( '.tejcart-settings-wrap' ) ) {
			return;
		}
		var notice = document.querySelector(
			'.tejcart-settings-wrap .notice-success, .tejcart-settings-wrap .updated'
		);
		var errorNotice = document.querySelector(
			'.tejcart-settings-wrap .notice-error, .tejcart-settings-wrap .error'
		);
		var target = errorNotice || notice;
		if ( ! target ) {
			return;
		}
		var message = ( target.textContent || '' ).replace( /Dismiss this notice\.?/, '' ).trim();
		if ( ! message ) {
			return;
		}
		var toast = document.createElement( 'div' );
		toast.className = 'tejcart-settings-toast' + ( errorNotice ? ' is-error' : '' );
		toast.setAttribute( 'role', 'status' );
		toast.textContent = message;
		document.body.appendChild( toast );
		requestAnimationFrame( function () {
			toast.classList.add( 'is-visible' );
		} );
		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () {
				toast.parentNode && toast.parentNode.removeChild( toast );
			}, 300 );
		}, 3200 );
	}

	/**
	 * Run on DOM ready.
	 */
	$( function () {
		initAnimations();
		initSettingsSearchPalette();
		initSettingsSidebarToggle();
		initSettingsDirtyState();
		initSettingsSaveToast();
	} );
} )( jQuery );

( function () {
	'use strict';

	var root = document.querySelector( '.tejcart-product-form-wrap' );
	if ( ! root ) {
		return;
	}

	var productForm = root.querySelector( '[data-tejcart-product-form]' );
	var header      = root.querySelector( '[data-tejcart-header]' );
	var saveState   = root.querySelector( '[data-tejcart-save-state]' );
	var saveButton  = root.querySelector( '[data-tejcart-save-button]' );

	var modal = root.querySelector( '[data-tejcart-modal]' );
	if ( modal ) {
		var modalTitle   = modal.querySelector( '[data-modal-title]' );
		var modalMsg     = modal.querySelector( '[data-modal-message]' );
		var modalConfirm = modal.querySelector( '[data-modal-confirm]' );
		var modalIcon    = modal.querySelector( '[data-modal-icon]' );
		var modalCloseEls = modal.querySelectorAll( '[data-modal-close]' );
		var lastTrigger  = null;

		function openModal( trigger ) {
			if ( ! trigger ) { return; }
			lastTrigger = trigger;
			modalTitle.textContent   = trigger.getAttribute( 'data-confirm-title' )   || 'Are you sure?';
			modalMsg.textContent     = trigger.getAttribute( 'data-confirm-message' ) || '';
			modalConfirm.textContent = trigger.getAttribute( 'data-confirm-button' )  || 'Confirm';
			modalConfirm.href        = trigger.getAttribute( 'href' ) || '#';

			var tone = trigger.getAttribute( 'data-confirm-tone' ) || 'default';
			modal.setAttribute( 'data-tone', tone );

			modal.hidden = false;

			setTimeout( function () {
				if ( modalConfirm.focus ) { modalConfirm.focus(); }
			}, 40 );
			document.body.classList.add( 'tejcart-modal-open' );
		}

		function closeModal() {
			modal.hidden = true;
			document.body.classList.remove( 'tejcart-modal-open' );
			if ( lastTrigger && lastTrigger.focus ) { lastTrigger.focus(); }
			lastTrigger = null;
		}

		modalCloseEls.forEach( function ( el ) {
			el.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeModal();
			} );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( modal.hidden ) { return; }
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				closeModal();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest && e.target.closest( '[data-tejcart-confirm]' );
			if ( ! trigger ) { return; }
			e.preventDefault();
			openModal( trigger );
		} );
	}

	var isMac = /(Mac|iPhone|iPad|iPod)/i.test( navigator.platform || navigator.userAgent || '' );
	root.querySelectorAll( '[data-tejcart-kbd-save]' ).forEach( function ( el ) {
		el.textContent = isMac ? '⌘S' : 'Ctrl+S';
	} );

	root.querySelectorAll( '[data-tejcart-copy]' ).forEach( function ( btn ) {
		var label = btn.querySelector( '.tejcart-btn-copy-text' );
		var origText = label ? label.textContent : '';
		var copiedText = btn.getAttribute( 'data-copied-label' ) || 'Copied!';

		function flashCopied() {
			if ( label ) {
				label.textContent = copiedText;
			}
			btn.classList.add( 'is-copied' );
			setTimeout( function () {
				if ( label ) { label.textContent = origText; }
				btn.classList.remove( 'is-copied' );
			}, 1500 );
		}

		btn.addEventListener( 'click', function () {
			var value = btn.getAttribute( 'data-copy-value' ) || '';
			if ( ! value ) { return; }

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( value ).then( flashCopied ).catch( function () {
					legacyCopy( value );
				} );
			} else {
				legacyCopy( value );
			}
		} );

		function legacyCopy( value ) {
			var ta = document.createElement( 'textarea' );
			ta.value = value;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); flashCopied(); } catch ( err ) {}
			document.body.removeChild( ta );
		}
	} );

	if ( header ) {
		var setHeaderShadow = function () {
			if ( window.scrollY > 4 ) {
				header.classList.add( 'is-scrolled' );
			} else {
				header.classList.remove( 'is-scrolled' );
			}
		};
		setHeaderShadow();
		window.addEventListener( 'scroll', setHeaderShadow, { passive: true } );
	}

	if ( productForm && saveState ) {
		var isDirty       = false;
		var formSubmitted = false;

		var markDirty = function () {
			if ( isDirty ) { return; }
			isDirty = true;
			saveState.hidden = false;
		};

		productForm.addEventListener( 'input',  markDirty );
		productForm.addEventListener( 'change', markDirty );

		productForm.addEventListener( 'submit', function () {
			formSubmitted = true;
			isDirty       = false;
			if ( saveButton ) {
				saveButton.classList.add( 'is-busy' );
			}
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( isDirty && ! formSubmitted ) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! isDirty || formSubmitted ) { return; }
			var a = e.target.closest && e.target.closest( 'a[href]' );
			if ( ! a ) { return; }

			if ( productForm.contains( a ) && a.getAttribute( 'target' ) !== '_blank' ) {
				return;
			}

			var href = a.getAttribute( 'href' ) || '';
			if ( href.charAt( 0 ) === '#' ) { return; }
			if ( a.target === '_blank' ) { return; }
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1 ) { return; }

			if ( a.hasAttribute( 'data-tejcart-confirm' ) ) { return; }

			var msg = 'You have unsaved changes. Leave anyway?';
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		}, true );

		document.addEventListener( 'keydown', function ( e ) {
			var isSave = ( e.metaKey || e.ctrlKey ) && ( 's' === e.key.toLowerCase() );
			if ( ! isSave ) { return; }
			e.preventDefault();
			if ( saveButton ) {
				saveButton.click();
			} else {
				productForm.submit();
			}
		} );
	}

	root.querySelectorAll( '[data-tejcart-auto-dismiss]' ).forEach( function ( notice ) {
		setTimeout( function () {
			notice.style.transition = 'opacity 200ms ease, max-height 200ms ease, margin 200ms ease, padding 200ms ease';
			notice.style.opacity    = '0';
			setTimeout( function () {
				if ( notice.parentNode ) { notice.parentNode.removeChild( notice ); }
			}, 220 );
		}, 3500 );
	} );

	function friendlyValidityMessage( field ) {
		var v = field.validity;
		if ( ! v ) { return field.validationMessage || 'Invalid value'; }
		if ( v.valueMissing )    { return 'This field is required.'; }
		if ( v.patternMismatch ) {
			if ( field.id === 'product_sku' ) {
				return 'Use letters, numbers, hyphens and underscores only.';
			}
			return field.title || 'Please match the requested format.';
		}
		if ( v.typeMismatch ) {
			if ( field.type === 'url' )   { return 'Please enter a valid URL (e.g. https://example.com).'; }
			if ( field.type === 'email' ) { return 'Please enter a valid email address.'; }
		}
		if ( v.rangeUnderflow ) { return 'Must be ' + field.min + ' or higher.'; }
		if ( v.rangeOverflow )  { return 'Must be ' + field.max + ' or lower.'; }
		if ( v.stepMismatch )   { return 'Please enter a valid number.'; }
		return field.validationMessage || 'Invalid value';
	}

	if ( productForm ) {
		productForm.addEventListener( 'submit', function ( e ) {
			var firstInvalid = null;
			productForm.querySelectorAll( '[required], [pattern], [type="number"]' ).forEach( function ( field ) {
				clearFieldError( field );
				if ( field.checkValidity && ! field.checkValidity() ) {
					showFieldError( field, friendlyValidityMessage( field ) );
					if ( ! firstInvalid ) {
						firstInvalid = field;
					}
				}
			} );
			if ( firstInvalid ) {
				e.preventDefault();

				var section = firstInvalid.closest( '.tejcart-section, .tejcart-tab-panel' );
				if ( section && section.scrollIntoView ) {
					section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
				setTimeout( function () {
					if ( firstInvalid.focus ) { firstInvalid.focus( { preventScroll: true } ); }
				}, 250 );

				if ( saveState ) { saveState.hidden = false; }
				if ( saveButton ) { saveButton.classList.remove( 'is-busy' ); }
			}
		} );

		productForm.addEventListener( 'input', function ( e ) {
			var field = e.target;
			if ( field && field.matches && field.matches( '[required], [pattern], [type="number"]' ) ) {
				if ( field.checkValidity && field.checkValidity() ) {
					clearFieldError( field );
				}
			}
		} );
	}

	function showFieldError( field, message ) {
		var wrap = field.closest( '.tejcart-field' ) || field.parentNode;
		if ( ! wrap ) {
			return;
		}
		wrap.classList.add( 'is-error' );
		var hint = wrap.querySelector( '.tejcart-field-error' );
		if ( ! hint ) {
			hint = document.createElement( 'p' );
			hint.className = 'tejcart-field-error';
			hint.setAttribute( 'role', 'alert' );
			wrap.appendChild( hint );
		}
		hint.textContent = message;
	}

	function clearFieldError( field ) {
		var wrap = field.closest( '.tejcart-field' ) || field.parentNode;
		if ( ! wrap ) {
			return;
		}
		wrap.classList.remove( 'is-error' );
		var hint = wrap.querySelector( '.tejcart-field-error' );
		if ( hint ) {
			hint.remove();
		}
	}

	root.querySelectorAll( '[data-tejcart-product-picker]' ).forEach( function ( picker ) {
		var search    = picker.querySelector( '[data-picker-search]' );
		var results   = picker.querySelector( '[data-picker-results]' );
		var hidden    = picker.querySelector( '[data-picker-hidden]' );
		var chipsBox  = picker.querySelector( '[data-picker-chips]' );
		var ajaxUrl   = picker.getAttribute( 'data-ajax-url' );
		var ajaxAction = picker.getAttribute( 'data-ajax-action' );
		var ajaxNonce = picker.getAttribute( 'data-ajax-nonce' );

		if ( ! search || ! results || ! hidden || ! chipsBox || ! ajaxUrl || ! ajaxAction ) {
			return;
		}

		function selectedIds() {
			var raw = ( hidden.value || '' ).trim();
			return raw ? raw.split( ',' ).map( function ( s ) { return parseInt( s, 10 ); } ).filter( Boolean ) : [];
		}

		function syncHidden( ids ) {
			hidden.value = ids.join( ',' );
		}

		function addChip( product ) {
			var ids = selectedIds();
			if ( ids.indexOf( product.id ) !== -1 ) { return; }
			ids.push( product.id );
			syncHidden( ids );

			var chip = document.createElement( 'span' );
			chip.className = 'tejcart-product-chip';
			chip.setAttribute( 'data-id', product.id );
			chip.innerHTML =
				'<span class="tejcart-product-chip-label">' +
				escapeHtml( product.name || ( '#' + product.id ) ) +
				( product.sku ? ' <span class="tejcart-muted">· ' + escapeHtml( product.sku ) + '</span>' : '' ) +
				'</span>' +
				'<button type="button" class="tejcart-product-chip-x" data-picker-remove aria-label="Remove">&times;</button>';
			chipsBox.appendChild( chip );
		}

		function escapeHtml( str ) {
			return String( str ).replace( /[&<>"']/g, function ( c ) {
				return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
			} );
		}

		function buildSearchUrl( term ) {
			var separator = ajaxUrl.indexOf( '?' ) === -1 ? '?' : '&';
			var qs = 'action=' + encodeURIComponent( ajaxAction )
				+ '&nonce=' + encodeURIComponent( ajaxNonce || '' )
				+ '&per_page=8'
				+ '&search=' + encodeURIComponent( term );
			var exclude = selectedIds();
			var selfId = parseInt( picker.getAttribute( 'data-self-id' ) || '0', 10 );
			if ( selfId > 0 ) { exclude.push( selfId ); }
			if ( exclude.length ) {
				qs += '&exclude=' + encodeURIComponent( exclude.join( ',' ) );
			}
			return ajaxUrl + separator + qs;
		}

		function showEmpty( message ) {
			results.innerHTML = '';
			var li = document.createElement( 'li' );
			li.className = 'tejcart-product-picker-result is-empty';
			li.setAttribute( 'aria-disabled', 'true' );
			li.textContent = message;
			results.appendChild( li );
			results.hidden = false;
		}

		var debounceTimer = null;
		var requestSeq    = 0;
		search.addEventListener( 'input', function () {
			var term = search.value.trim();
			if ( debounceTimer ) { clearTimeout( debounceTimer ); }
			if ( term.length < 2 ) {
				results.hidden = true;
				results.innerHTML = '';
				return;
			}
			debounceTimer = setTimeout( function () {
				var seq = ++requestSeq;
				fetch( buildSearchUrl( term ), {
					headers: { 'Accept': 'application/json' },
					credentials: 'same-origin'
				} )
					.then( function ( r ) {
						if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
						return r.json();
					} )
					.then( function ( list ) {
						if ( seq !== requestSeq ) { return; }
						if ( ! Array.isArray( list ) || list.length === 0 ) {
							showEmpty( 'No products match.' );
							return;
						}
						results.innerHTML = '';
						list.forEach( function ( prod ) {
							var li = document.createElement( 'li' );
							li.className = 'tejcart-product-picker-result';
							li.setAttribute( 'data-id', prod.id );
							li.innerHTML =
								'<strong>' + escapeHtml( prod.name ) + '</strong>' +
								( prod.sku ? ' <span class="tejcart-muted">· ' + escapeHtml( prod.sku ) + '</span>' : '' );
							function pick( e ) {
								if ( e ) { e.preventDefault(); }
								addChip( prod );
								search.value = '';
								results.hidden = true;
								results.innerHTML = '';
							}
							li.addEventListener( 'mousedown', pick );
							li.addEventListener( 'click', pick );
							results.appendChild( li );
						} );
						results.hidden = false;
					} )
					.catch( function () {
						if ( seq !== requestSeq ) { return; }
						showEmpty( 'Could not load suggestions.' );
					} );
			}, 220 );
		} );

		search.addEventListener( 'blur', function () {
			setTimeout( function () { results.hidden = true; }, 150 );
		} );

		chipsBox.addEventListener( 'click', function ( e ) {
			if ( ! e.target || ! e.target.matches( '[data-picker-remove]' ) ) { return; }
			var chip = e.target.closest( '.tejcart-product-chip' );
			if ( ! chip ) { return; }
			var id   = parseInt( chip.getAttribute( 'data-id' ), 10 );
			syncHidden( selectedIds().filter( function ( x ) { return x !== id; } ) );
			chip.parentNode.removeChild( chip );
		} );
	} );

	var typeSwitchers = root.querySelectorAll( '[data-tejcart-type-switch]' );
	typeSwitchers.forEach( function ( el ) {
		el.addEventListener( 'change', function () {
			var newVal = el.value;
			if ( ! newVal ) { return; }

			root.querySelectorAll( '.tejcart-type-option' ).forEach( function ( opt ) {
				var radio = opt.querySelector( 'input[type="radio"]' );
				opt.classList.toggle( 'is-selected', !! ( radio && radio.checked ) );
			} );

			var url = new URL( window.location.href );
			url.searchParams.set( 'type', newVal );
			window.location.href = url.toString();
		} );
	} );

	var attrList   = root.querySelector( '#tejcart-variation-attrs' );
	var addAttrBtn = root.querySelector( '#tejcart-add-attr-row' );

	function initChipEditor( editor ) {
		if ( editor.__nxInit ) { return; }
		editor.__nxInit = true;

		var list   = editor.querySelector( '[data-chip-list]' );
		var input  = editor.querySelector( '[data-chip-input]' );
		var source = editor.querySelector( '[data-chip-source]' );
		if ( ! list || ! input || ! source ) { return; }

		function escape( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
			} );
		}

		function currentValues() {
			return Array.prototype.map.call(
				list.querySelectorAll( '.tejcart-chip' ),
				function ( chip ) { return chip.getAttribute( 'data-chip-val' ) || ''; }
			).filter( Boolean );
		}

		function syncSource() {
			source.value = currentValues().join( '\n' );
		}

		function addValue( raw ) {
			var val = String( raw || '' ).trim();
			if ( ! val ) { return; }

			var lower = val.toLowerCase();
			var dup   = currentValues().some( function ( v ) { return v.toLowerCase() === lower; } );
			if ( dup ) { return; }

			var chip = document.createElement( 'span' );
			chip.className = 'tejcart-chip';
			chip.setAttribute( 'data-chip-val', val );
			chip.innerHTML =
				'<span class="tejcart-chip-text">' + escape( val ) + '</span>' +
				'<button type="button" class="tejcart-chip-x" data-chip-remove aria-label="Remove">&times;</button>';
			list.appendChild( chip );
			syncSource();
		}

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ',' ) {
				e.preventDefault();

				input.value.split( /[\n,|]+/ ).forEach( addValue );
				input.value = '';
			} else if ( e.key === 'Backspace' && input.value === '' ) {
				var last = list.querySelector( '.tejcart-chip:last-child' );
				if ( last ) {
					last.parentNode.removeChild( last );
					syncSource();
				}
			}
		} );

		input.addEventListener( 'blur', function () {
			if ( input.value.trim() !== '' ) {
				input.value.split( /[\n,|]+/ ).forEach( addValue );
				input.value = '';
			}
		} );

		input.addEventListener( 'paste', function ( e ) {
			var txt = ( e.clipboardData || window.clipboardData ).getData( 'text' );
			if ( txt && /[\n,|]/.test( txt ) ) {
				e.preventDefault();
				txt.split( /[\n,|]+/ ).forEach( addValue );
			}
		} );

		list.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest && e.target.closest( '[data-chip-remove]' );
			if ( ! btn ) { return; }
			var chip = btn.closest( '.tejcart-chip' );
			if ( chip && chip.parentNode ) {
				chip.parentNode.removeChild( chip );
				syncSource();
			}
		} );

		editor.addEventListener( 'click', function ( e ) {
			if ( e.target === editor || e.target.classList.contains( 'tejcart-chip-editor-input-wrap' ) || e.target.classList.contains( 'tejcart-chip-list' ) ) {
				input.focus();
			}
		} );
	}

	root.querySelectorAll( '[data-tejcart-chip-editor]' ).forEach( initChipEditor );

	if ( attrList && addAttrBtn ) {
		addAttrBtn.addEventListener( 'click', function () {
			var existing = attrList.querySelectorAll( '.tejcart-attr-card' ).length;

			attrList.querySelectorAll( '.tejcart-attr-card[data-attr-index]' ).forEach( function ( c ) {
				var n = parseInt( c.getAttribute( 'data-attr-index' ), 10 );
				if ( ! isNaN( n ) && n >= existing ) { existing = n + 1; }
			} );
			var idx  = existing;
			var card = document.createElement( 'div' );
			card.className = 'tejcart-attr-card tejcart-variation-attr-row';
			card.setAttribute( 'data-attr-index', String( idx ) );
			card.innerHTML =
				'<div class="tejcart-attr-card-head">' +
					'<div class="tejcart-field tejcart-attr-name-field">' +
						'<label>Attribute name</label>' +
						'<input type="text" name="variation_attr_name[' + idx + ']" class="tejcart-input" placeholder="e.g. Size, Colour" />' +
					'</div>' +
					'<button type="button" class="tejcart-icon-btn tejcart-remove-attr-row" aria-label="Remove attribute" title="Remove attribute">' +
						'<span class="dashicons dashicons-trash" aria-hidden="true"></span>' +
					'</button>' +
				'</div>' +
				'<div class="tejcart-field">' +
					'<label>Values</label>' +
					'<div class="tejcart-chip-editor" data-tejcart-chip-editor>' +
						'<div class="tejcart-chip-editor-input-wrap">' +
							'<div class="tejcart-chip-list" data-chip-list></div>' +
							'<input type="text" class="tejcart-chip-input" data-chip-input placeholder="Type a value and press Enter" />' +
						'</div>' +
						'<textarea name="variation_attr_values[' + idx + ']" data-chip-source hidden></textarea>' +
					'</div>' +
					'<p class="description">Press Enter or comma to add each value. Click the × on a chip to remove it.</p>' +
				'</div>' +
				'<div class="tejcart-attr-card-toggles">' +
					'<label class="tejcart-toggle">' +
						'<input type="checkbox" name="variation_attr_visible[' + idx + ']" value="1" checked />' +
						'<span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>' +
						'<span class="tejcart-toggle-label">Show on product page</span>' +
					'</label>' +
					'<label class="tejcart-toggle">' +
						'<input type="checkbox" name="variation_attr_used[' + idx + ']" value="1" />' +
						'<span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>' +
						'<span class="tejcart-toggle-label">Use for variations</span>' +
					'</label>' +
				'</div>';
			attrList.appendChild( card );
			initChipEditor( card.querySelector( '[data-tejcart-chip-editor]' ) );
			card.querySelector( 'input[name="variation_attr_name[' + idx + ']"]' ).focus();
		} );

		attrList.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest && e.target.closest( '.tejcart-remove-attr-row' );
			if ( ! btn ) { return; }
			var card = btn.closest( '.tejcart-attr-card' );
			if ( card && card.parentNode ) {
				card.parentNode.removeChild( card );
			}
		} );
	}

	var bundleBuilder = root.querySelector( '[data-tejcart-bundle-builder]' );
	if ( bundleBuilder ) {
		var bundleSearch  = bundleBuilder.querySelector( '[data-bundle-search]' );
		var bundleResults = bundleBuilder.querySelector( '[data-bundle-results]' );
		var bundleTbody2  = bundleBuilder.querySelector( '[data-bundle-tbody]' );
		var bundleEmpty   = bundleBuilder.querySelector( '[data-bundle-empty]' );
		var bundleWrap    = bundleBuilder.querySelector( '.tejcart-bundle-items-wrap' );
		var bundleRest    = bundleBuilder.getAttribute( 'data-rest-root' );
		var bundleNonce   = bundleBuilder.getAttribute( 'data-rest-nonce' );
		var currentId     = parseInt( bundleBuilder.getAttribute( 'data-current-id' ), 10 ) || 0;

		function bundleSelectedIds() {
			var ids = [];
			bundleTbody2.querySelectorAll( '[data-bundle-row]' ).forEach( function ( row ) {
				var id = parseInt( row.getAttribute( 'data-product-id' ), 10 );
				if ( id ) { ids.push( id ); }
			} );
			return ids;
		}

		function syncBundleEmpty() {
			var hasRows = bundleTbody2.querySelectorAll( '[data-bundle-row]' ).length > 0;
			if ( bundleWrap ) {
				bundleWrap.classList.toggle( 'is-empty', ! hasRows );
			}
		}
		syncBundleEmpty();

		function addBundleProduct( product ) {
			var existing = bundleSelectedIds();
			if ( existing.indexOf( product.id ) !== -1 ) { return; }

			var tr = document.createElement( 'tr' );
			tr.className = 'tejcart-bundle-row';
			tr.setAttribute( 'data-bundle-row', '' );
			tr.setAttribute( 'data-product-id', product.id );

			var name  = product.name || ( '#' + product.id );
			var sku   = product.sku || '';

			var thumb = ( product.image && product.image.thumbnail ) ? product.image.thumbnail : '';

			var thumbHtml = thumb
				? '<img class="tejcart-bundle-product-thumb is-img" src="' + escapeHtml( thumb ) + '" alt="" />'
				: '<span class="tejcart-bundle-product-thumb dashicons dashicons-products" aria-hidden="true"></span>';

			tr.innerHTML =
				'<td class="tejcart-bundle-product-cell">' +
					'<input type="hidden" name="bundled_product_id[]" value="' + product.id + '" />' +
					'<div class="tejcart-bundle-product-info">' +
						thumbHtml +
						'<span class="tejcart-bundle-product-text">' +
							'<strong>' + escapeHtml( name ) + '</strong>' +
							( sku ? '<span class="tejcart-muted">' + escapeHtml( sku ) + '</span>' : '' ) +
						'</span>' +
					'</div>' +
				'</td>' +
				'<td><input type="number" name="bundled_quantity[]" class="tejcart-input" value="1" min="1" step="1" /></td>' +
				'<td>' +
					'<div class="tejcart-input-with-suffix">' +
						'<input type="number" name="bundled_discount[]" class="tejcart-input" value="0" min="0" max="100" step="0.01" />' +
						'<span class="tejcart-input-suffix">%</span>' +
					'</div>' +
				'</td>' +
				'<td>' +
					'<button type="button" class="tejcart-icon-btn tejcart-remove-bundle-row" ' +
					'aria-label="Remove" title="Remove">' +
					'<span class="dashicons dashicons-trash" aria-hidden="true"></span>' +
					'</button>' +
				'</td>';

			bundleTbody2.appendChild( tr );
			syncBundleEmpty();
		}

		function escapeHtml( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
			} );
		}

		if ( bundleSearch && bundleResults && bundleTbody2 && bundleRest ) {
			var bundleDebounce = null;
			bundleSearch.addEventListener( 'input', function () {
				var term = bundleSearch.value.trim();
				if ( bundleDebounce ) { clearTimeout( bundleDebounce ); }
				if ( term.length < 2 ) {
					bundleResults.hidden = true;
					bundleResults.innerHTML = '';
					return;
				}
				bundleDebounce = setTimeout( function () {
					fetch( bundleRest + '?per_page=8&search=' + encodeURIComponent( term ), {
						headers: { 'X-WP-Nonce': bundleNonce, 'Accept': 'application/json' }
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( list ) {
							bundleResults.innerHTML = '';
							if ( ! Array.isArray( list ) ) { list = []; }

							var added = bundleSelectedIds();
							list = list.filter( function ( p ) {
								return p.id !== currentId && added.indexOf( p.id ) === -1;
							} );
							if ( list.length === 0 ) {
								var empty = document.createElement( 'li' );
								empty.className = 'tejcart-bundle-result is-empty';
								empty.textContent = ( window.tejcartL10n && window.tejcartL10n.no_results ) || 'No matching products.';
								bundleResults.appendChild( empty );
							} else {
								list.forEach( function ( prod ) {
									var li = document.createElement( 'li' );
									li.className = 'tejcart-bundle-result';
									li.innerHTML =
										'<strong>' + escapeHtml( prod.name || ( '#' + prod.id ) ) + '</strong>' +
										( prod.sku ? ' <span class="tejcart-muted">· ' + escapeHtml( prod.sku ) + '</span>' : '' );
									li.addEventListener( 'mousedown', function ( ev ) {
										ev.preventDefault();
										addBundleProduct( prod );
										bundleSearch.value = '';
										bundleResults.hidden = true;
										bundleResults.innerHTML = '';
										bundleSearch.focus();
									} );
									bundleResults.appendChild( li );
								} );
							}
							bundleResults.hidden = false;
						} )
						.catch( function () {
							bundleResults.hidden = true;
						} );
				}, 220 );
			} );

			bundleSearch.addEventListener( 'blur', function () {
				setTimeout( function () { bundleResults.hidden = true; }, 150 );
			} );
		}

		bundleBuilder.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest && e.target.closest( '.tejcart-remove-bundle-row' );
			if ( ! btn ) { return; }
			var row = btn.closest( '[data-bundle-row]' );
			if ( row && row.parentNode ) {
				row.parentNode.removeChild( row );
				syncBundleEmpty();
			}
		} );
	}

	var downloadTbody  = root.querySelector( '#tejcart-download-files tbody' );
	var addDownloadBtn = root.querySelector( '#tejcart-add-download-row' );
	if ( downloadTbody && addDownloadBtn ) {
		addDownloadBtn.addEventListener( 'click', function () {
			var tr = document.createElement( 'tr' );
			tr.className = 'tejcart-download-row';
			tr.innerHTML =
				'<td><input type="text" name="download_file_name[]" class="tejcart-input" /></td>' +
				'<td><span class="tejcart-download-url-wrap">' +
				'<input type="text" name="download_file_url[]" class="tejcart-input" placeholder="https://..." />' +
				'<button type="button" class="button tejcart-upload-download-btn">Upload</button></span></td>' +
				'<td><button type="button" class="button-link-delete tejcart-remove-download-row">' +
				( ( window.tejcartL10n && window.tejcartL10n.remove ) || 'Remove' ) +
				'</button></td>';
			downloadTbody.appendChild( tr );
		} );
	}

	root.addEventListener( 'click', function ( e ) {
		if ( ! e.target ) { return; }
		var target = e.target.closest ? e.target.closest( '.tejcart-remove-download-row' ) : null;
		if ( target ) {
			var row = target.closest( 'tr' );
			if ( row && row.parentNode ) {
				row.parentNode.removeChild( row );
			}
		}
	} );

	var bulkWrap = root.querySelector( '[data-tejcart-variations-bulk]' );
	if ( bulkWrap ) {
		var applyBtn    = bulkWrap.querySelector( '[data-bulk-apply]' );
		var applyLabel  = bulkWrap.querySelector( '[data-bulk-apply-label]' );
		var bulkTitle   = bulkWrap.querySelector( '[data-bulk-title]' );
		var feedback    = bulkWrap.querySelector( '[data-bulk-feedback]' );
		var table       = root.querySelector( '.tejcart-variations-table' );
		var countLabel  = applyBtn ? applyBtn.getAttribute( 'data-count-label' ) || 'Apply to %d variations' : 'Apply to %d variations';
		var totalRows   = table ? table.querySelectorAll( 'tbody [data-variation-row]' ).length : 0;
		var checkAll    = table ? table.querySelector( '[data-bulk-check-all]' ) : null;
		var rowChecks   = table ? table.querySelectorAll( '[data-bulk-check-row]' ) : [];

		function getBulkValue( key ) {
			var el = bulkWrap.querySelector( '[data-bulk-field="' + key + '"]' );
			return el ? String( el.value || '' ).trim() : '';
		}

		function clearBulkFields() {
			bulkWrap.querySelectorAll( '[data-bulk-field]' ).forEach( function ( el ) {
				el.value = '';
			} );
		}

		function checkedRows() {
			if ( ! table ) { return []; }
			return Array.prototype.filter.call(
				table.querySelectorAll( '[data-variation-row]' ),
				function ( row ) {
					var cb = row.querySelector( '[data-bulk-check-row]' );
					return cb && cb.checked;
				}
			);
		}

		function syncBulkLabel() {
			var sel = checkedRows().length;
			var n   = sel > 0 ? sel : totalRows;
			if ( applyLabel ) {
				applyLabel.textContent = countLabel.replace( '%d', n );
			}
			if ( bulkTitle ) {
				bulkTitle.textContent = sel > 0
					? ( sel === 1 ? 'Bulk edit 1 selected variation' : 'Bulk edit ' + sel + ' selected variations' )
					: 'Bulk edit all variations';
			}
			bulkWrap.classList.toggle( 'has-selection', sel > 0 );
		}

		function flashFeedback( msg, tone ) {
			if ( ! feedback ) { return; }
			feedback.textContent = msg;
			feedback.className = 'tejcart-variations-bulk-feedback is-' + ( tone || 'ok' );
			feedback.hidden = false;
			setTimeout( function () { feedback.hidden = true; }, 2800 );
		}

		function reflectRowSelection( cb ) {
			var row = cb.closest( '[data-variation-row]' );
			if ( row ) { row.classList.toggle( 'is-selected', cb.checked ); }
		}

		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				rowChecks.forEach( function ( cb ) {
					cb.checked = checkAll.checked;
					reflectRowSelection( cb );
				} );
				syncBulkLabel();
			} );
		}
		rowChecks.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				reflectRowSelection( cb );
				if ( checkAll ) {
					var all = Array.prototype.every.call( rowChecks, function ( c ) { return c.checked; } );
					var any = Array.prototype.some.call( rowChecks, function ( c ) { return c.checked; } );
					checkAll.checked = all;
					checkAll.indeterminate = any && ! all;
				}
				syncBulkLabel();
			} );
		} );
		syncBulkLabel();

		if ( applyBtn && table ) {
			applyBtn.addEventListener( 'click', function () {
				var price  = getBulkValue( 'price' );
				var sale   = getBulkValue( 'sale' );
				var stock  = getBulkValue( 'stock' );
				var status = getBulkValue( 'status' );

				if ( price === '' && sale === '' && stock === '' && status === '' ) {
					flashFeedback( ( window.tejcartL10n && window.tejcartL10n.bulk_empty ) || 'Fill at least one field to apply.', 'warn' );
					return;
				}

				var selected = checkedRows();
				var targets  = selected.length > 0
					? selected
					: Array.prototype.filter.call(
						table.querySelectorAll( 'tbody > tr[data-variation-row]' ),
						function ( row ) { return !! row.querySelector( 'input[name="variation_id[]"]' ); }
					);

				var count = 0;
				targets.forEach( function ( row ) {
					var hidden = row.querySelector( 'input[name="variation_id[]"]' );
					if ( ! hidden ) { return; }

					if ( price !== '' ) {
						var pInput = row.querySelector( 'input[name="variation_price[]"]' );
						if ( pInput ) { pInput.value = price; }
					}
					if ( sale !== '' ) {
						var sInput = row.querySelector( 'input[name="variation_sale_price[]"]' );
						if ( sInput ) { sInput.value = sale; }
					}
					if ( stock !== '' ) {
						var stInput = row.querySelector( 'input[name="variation_stock_quantity[]"]' );
						if ( stInput ) { stInput.value = stock; }
					}
					if ( status !== '' ) {
						var statusSel = row.querySelector( 'select[name="variation_status[]"]' );
						if ( statusSel ) { statusSel.value = status; }
					}
					count++;
				} );

				flashFeedback(
					count === 1
						? ( 'Applied to 1 variation.' )
						: ( 'Applied to ' + count + ' variations.' ),
					'ok'
				);
				clearBulkFields();
				markDirtyIfWatching();
			} );
		}
	}

	root.querySelectorAll( '[data-tejcart-generate-variations]' ).forEach( function ( link ) {
		var label    = link.querySelector( '.tejcart-generate-btn-label' );
		var origText = label ? label.textContent : '';
		var feedback = link.parentNode ? link.parentNode.querySelector( '[data-generate-feedback]' ) : null;

		link.addEventListener( 'click', function ( e ) {
			if ( e.metaKey || e.ctrlKey || e.button === 1 ) { return; }
			e.preventDefault();
			if ( link.classList.contains( 'is-busy' ) ) { return; }

			var url = link.getAttribute( 'href' );

			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + '_tejcart_json=1';

			link.classList.add( 'is-busy' );
			if ( label ) { label.textContent = 'Generating…'; }
			if ( feedback ) {
				feedback.hidden = true;
				feedback.textContent = '';
				feedback.className   = 'tejcart-generate-feedback';
			}

			var form = document.getElementById( 'tejcart-product-form' );

			if ( window.tinymce && typeof window.tinymce.triggerSave === 'function' ) {
				window.tinymce.triggerSave();
			}
			var body = form ? new FormData( form ) : new FormData();

			fetch( url, {
				method: 'POST',
				headers: { 'Accept': 'application/json' },
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
				.then( function ( res ) {
					if ( ! res.ok ) {
						var msg = ( res.data && res.data.data && res.data.data.message )
							|| ( res.data && res.data.message )
							|| 'Could not generate variations.';
						throw new Error( msg );
					}

					var payload = ( res.data && res.data.data ) || {};
					if ( feedback ) {
						feedback.className = 'tejcart-generate-feedback is-ok';
						feedback.textContent = payload.message || 'Done.';
						feedback.hidden = false;
					}

					setTimeout( function () {
						var u = new URL( window.location.href );
						u.searchParams.set( 'variations_done', '1' );
						u.searchParams.set( 'created', String( payload.created || 0 ) );
						u.searchParams.set( 'skipped', String( payload.skipped || 0 ) );
						u.hash = '#tejcart-panel-variations';
						window.location.href = u.toString();
					}, 600 );
				} )
				.catch( function ( err ) {
					link.classList.remove( 'is-busy' );
					if ( label ) { label.textContent = origText; }
					if ( feedback ) {
						feedback.className = 'tejcart-generate-feedback is-warn';
						feedback.textContent = ( err && err.message ) ? err.message : 'Network error.';
						feedback.hidden = false;
					}
				} );
		} );
	} );

	root.querySelectorAll( '[data-tejcart-variation-thumb]' ).forEach( function ( btn ) {
		var hidden = btn.parentNode.querySelector( 'input[name="variation_image_id[]"]' );
		if ( ! hidden ) { return; }

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( typeof window.wp === 'undefined' || ! window.wp.media ) { return; }

			var frame = window.wp.media( {
				title: 'Select variation image',
				button: { text: 'Use this image' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att.sizes && att.sizes.thumbnail )
					? att.sizes.thumbnail.url
					: att.url;

				hidden.value = att.id;
				btn.classList.add( 'has-image' );
				btn.innerHTML = '<img src="' + url + '" alt="" />';
				markDirtyIfWatching();
			} );

			frame.open();
		} );

		btn.addEventListener( 'click', function ( e ) {
			if ( ! e.altKey ) { return; }
			e.preventDefault();
			e.stopImmediatePropagation();
			hidden.value = '0';
			btn.classList.remove( 'has-image' );
			btn.innerHTML = '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>';
			markDirtyIfWatching();
		}, true );
	} );

	root.querySelectorAll( '[data-tejcart-term-filter]' ).forEach( function ( wrap ) {
		var input = wrap.querySelector( '[data-term-filter-input]' );
		var empty = wrap.querySelector( '[data-term-empty]' );
		var items = wrap.querySelectorAll( '.tejcart-term-item' );
		if ( ! input || items.length === 0 ) { return; }

		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			var shown = 0;
			items.forEach( function ( item ) {
				var name = item.getAttribute( 'data-term-name' ) || '';

				var checkbox = item.querySelector( 'input[type="checkbox"]' );
				var isChecked = !! ( checkbox && checkbox.checked );
				var match = q === '' || name.indexOf( q ) !== -1 || isChecked;
				item.hidden = ! match;
				if ( match ) { shown++; }
			} );
			if ( empty ) { empty.hidden = shown > 0; }
		} );
	} );

	root.querySelectorAll( '[data-tejcart-term-quick-add]' ).forEach( function ( wrap ) {
		var toggle = wrap.parentNode.querySelector( '[data-quick-add-toggle]' )
			|| wrap.querySelector( '[data-quick-add-toggle]' );

		if ( ! toggle ) {
			var field = wrap.closest( '.tejcart-field' );
			if ( field ) { toggle = field.querySelector( '[data-quick-add-toggle]' ); }
		}
		var panel  = wrap.querySelector( '[data-term-quick-add]' );
		var input  = wrap.querySelector( '[data-quick-add-input]' );
		var submit = wrap.querySelector( '[data-quick-add-submit]' );
		var cancel = wrap.querySelector( '[data-quick-add-cancel]' );
		var error  = wrap.querySelector( '[data-quick-add-error]' );
		var list   = wrap.querySelector( '[data-term-list]' );
		var rest   = wrap.getAttribute( 'data-rest-root' );
		var nonce  = wrap.getAttribute( 'data-rest-nonce' );
		var fieldBase = wrap.getAttribute( 'data-field-base' );
		if ( ! toggle || ! panel || ! input || ! submit || ! list || ! rest ) { return; }

		function showPanel() {
			panel.hidden = false;
			if ( error ) { error.hidden = true; }
			input.value = '';
			input.focus();
		}
		function hidePanel() {
			panel.hidden = true;
			input.value  = '';
			if ( error ) { error.hidden = true; }
		}

		toggle.addEventListener( 'click', function () {
			if ( panel.hidden ) { showPanel(); } else { hidePanel(); }
		} );
		if ( cancel ) { cancel.addEventListener( 'click', hidePanel ); }

		function showError( msg ) {
			if ( ! error ) { return; }
			error.textContent = msg;
			error.hidden = false;
		}

		function createTerm() {
			var name = input.value.trim();
			if ( ! name ) { return; }
			submit.disabled = true;
			submit.classList.add( 'is-busy' );
			if ( error ) { error.hidden = true; }

			fetch( rest, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept':       'application/json',
					'X-WP-Nonce':   nonce
				},
				body: JSON.stringify( { name: name } )
			} )
				.then( function ( r ) { return r.json().then( function ( data ) { return { ok: r.ok, data: data }; } ); } )
				.then( function ( res ) {
					if ( ! res.ok || ! res.data || ! res.data.id ) {
						var msg = ( res.data && res.data.message ) ? res.data.message : 'Could not create.';
						showError( msg );
						return;
					}

					var term = res.data;
					var label = document.createElement( 'label' );
					label.className = 'tejcart-term-item is-new';
					label.setAttribute( 'data-term-name', String( term.name || '' ).toLowerCase() );
					label.innerHTML =
						'<input type="checkbox" name="' + fieldBase + '[]" value="' + term.id + '" checked />' +
						'<span>' + ( term.name || ( '#' + term.id ) ).replace( /[&<>]/g, function ( c ) {
							return { '&':'&amp;', '<':'&lt;', '>':'&gt;' }[ c ];
						} ) + '</span>';
					list.insertBefore( label, list.firstChild );
					list.scrollTop = 0;
					hidePanel();
					markDirtyIfWatching();
				} )
				.catch( function () {
					showError( 'Network error. Try again.' );
				} )
				.finally( function () {
					submit.disabled = false;
					submit.classList.remove( 'is-busy' );
				} );
		}

		submit.addEventListener( 'click', createTerm );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				createTerm();
			} else if ( e.key === 'Escape' ) {
				hidePanel();
			}
		} );
	} );

	var priceReg  = root.querySelector( '#product_price' );
	var priceSale = root.querySelector( '#product_sale_price' );
	var productType = root.getAttribute( 'data-tejcart-type' ) || '';
	var derivedPriceTypes = [ 'variable', 'bundle', 'grouped' ];
	if ( priceReg && priceSale && derivedPriceTypes.indexOf( productType ) === -1 ) {
		var saleBadge = document.createElement( 'span' );
		saleBadge.className = 'tejcart-discount-badge';
		saleBadge.setAttribute( 'aria-live', 'polite' );
		saleBadge.hidden = true;

		var saleWrap = priceSale.closest( '.tejcart-field' );
		if ( saleWrap ) {
			var saleLabel = saleWrap.querySelector( 'label' );
			if ( saleLabel ) {
				saleLabel.appendChild( saleBadge );
			}
		}

		function updateDiscountBadge() {
			var r = parseFloat( priceReg.value );
			var s = parseFloat( priceSale.value );
			if ( isFinite( r ) && isFinite( s ) && r > 0 && s > 0 && s < r ) {
				var pct = Math.round( ( 1 - s / r ) * 100 );
				saleBadge.textContent = '−' + pct + '%';
				saleBadge.hidden = false;
			} else {
				saleBadge.hidden = true;
			}
		}
		updateDiscountBadge();
		priceReg.addEventListener( 'input',  updateDiscountBadge );
		priceSale.addEventListener( 'input', updateDiscountBadge );
	}

	var galleryContainer = root.querySelector( '#tejcart-gallery-images' );
	var galleryHidden    = root.querySelector( '#product_gallery_ids' );
	if ( galleryContainer && galleryHidden ) {
		var syncGalleryOrder = function () {
			var ids = Array.prototype.map.call(
				galleryContainer.querySelectorAll( '.tejcart-gallery-thumb' ),
				function ( t ) { return t.getAttribute( 'data-id' ); }
			).filter( Boolean );
			galleryHidden.value = ids.join( ',' );
		};

		var prepThumb = function ( thumb ) {
			if ( thumb.getAttribute( 'draggable' ) === 'true' ) { return; }
			thumb.setAttribute( 'draggable', 'true' );
		};

		galleryContainer.querySelectorAll( '.tejcart-gallery-thumb' ).forEach( prepThumb );

		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( function ( records ) {
				records.forEach( function ( rec ) {
					rec.addedNodes.forEach( function ( node ) {
						if ( node.nodeType === 1 && node.classList.contains( 'tejcart-gallery-thumb' ) ) {
							prepThumb( node );
						}
					} );
				} );
			} ).observe( galleryContainer, { childList: true } );
		}

		var draggedEl = null;

		galleryContainer.addEventListener( 'dragstart', function ( e ) {
			var thumb = e.target.closest && e.target.closest( '.tejcart-gallery-thumb' );
			if ( ! thumb ) { return; }
			draggedEl = thumb;
			thumb.classList.add( 'is-dragging' );
			if ( e.dataTransfer ) {
				e.dataTransfer.effectAllowed = 'move';

				try { e.dataTransfer.setData( 'text/plain', thumb.getAttribute( 'data-id' ) || '' ); } catch ( err ) {}
			}
		} );

		galleryContainer.addEventListener( 'dragend', function () {
			if ( draggedEl ) { draggedEl.classList.remove( 'is-dragging' ); }
			galleryContainer.querySelectorAll( '.tejcart-gallery-thumb.is-drop-before, .tejcart-gallery-thumb.is-drop-after' )
				.forEach( function ( el ) { el.classList.remove( 'is-drop-before', 'is-drop-after' ); } );
			draggedEl = null;
		} );

		galleryContainer.addEventListener( 'dragover', function ( e ) {
			if ( ! draggedEl ) { return; }
			var target = e.target.closest && e.target.closest( '.tejcart-gallery-thumb' );
			if ( ! target || target === draggedEl ) { return; }
			e.preventDefault();
			if ( e.dataTransfer ) { e.dataTransfer.dropEffect = 'move'; }

			var rect   = target.getBoundingClientRect();
			var midX   = rect.left + rect.width / 2;
			var before = e.clientX < midX;

			galleryContainer.querySelectorAll( '.is-drop-before, .is-drop-after' )
				.forEach( function ( el ) { el.classList.remove( 'is-drop-before', 'is-drop-after' ); } );
			target.classList.add( before ? 'is-drop-before' : 'is-drop-after' );
		} );

		galleryContainer.addEventListener( 'drop', function ( e ) {
			if ( ! draggedEl ) { return; }
			var target = e.target.closest && e.target.closest( '.tejcart-gallery-thumb' );
			if ( ! target || target === draggedEl ) { return; }
			e.preventDefault();
			var rect   = target.getBoundingClientRect();
			var before = e.clientX < ( rect.left + rect.width / 2 );

			if ( before ) {
				target.parentNode.insertBefore( draggedEl, target );
			} else {
				target.parentNode.insertBefore( draggedEl, target.nextSibling );
			}
			syncGalleryOrder();
			markDirtyIfWatching();
		} );
	}

	function markDirtyIfWatching() {
		if ( saveState && saveState.hidden ) {
			saveState.hidden = false;
		}
	}

	// Refund-inconsistency clear button — posts to the admin-AJAX
	// handler at `wp_ajax_tejcart_clear_refund_inconsistency`. The
	// per-order nonce + capability check live server-side; this is
	// just the UI wire-up.
	$( document ).on( 'click', '.tejcart-clear-refund-inconsistency', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var orderId = $btn.data( 'order-id' );
		var nonce   = $btn.data( 'nonce' );
		if ( ! orderId || ! nonce || $btn.is( ':disabled' ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		$.post( window.ajaxurl, {
			action:   'tejcart_clear_refund_inconsistency',
			order_id: orderId,
			_wpnonce: nonce,
		} )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					window.location.reload();
				} else {
					var msg = ( resp && resp.data && resp.data.message ) || '';
					window.alert( msg || 'Unable to clear inconsistency flag.' );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				window.alert( 'Unable to clear inconsistency flag.' );
				$btn.prop( 'disabled', false );
			} );
	} );
} )();
