/**
 * TejCart Admin Pages
 *
 * Client-side behaviour for admin pages whose UI was previously inlined
 * via `<script>` tags inside PHP renderers. Each init block is gated by
 * element presence so this single file is safe to enqueue on every
 * TejCart admin screen.
 *
 * Localised data:
 *   tejcart_admin_pages = {
 *     shippingZones: { methodTypes: { handle: label, ... } },
 *     orderPreview:  { loading, error, network },
 *     systemStatus:  { copied },
 *   }
 *
 * @package TejCart
 */

( function () {
	'use strict';

	var data = ( typeof window.tejcart_admin_pages === 'object' && window.tejcart_admin_pages ) || {};

	function initRegionPicker( root, i18n, regions ) {
		if ( ! root || root.__nxRegionInit ) {
			return;
		}
		root.__nxRegionInit = true;

		var chipsHost = root.querySelector( '[data-region-chips]' );
		var input     = root.querySelector( '[data-region-input]' );
		var dropdown  = root.querySelector( '[data-region-dropdown]' );
		if ( ! chipsHost || ! input || ! dropdown ) {
			return;
		}

		var countries = ( regions && regions.countries ) || {};
		var states    = ( regions && regions.states )    || {};
		// drillCountry is null when we're showing the country list, or
		// an ISO code (e.g. "US") when the user has clicked a country
		// with states and we're now offering its states as suggestions.
		var drillCountry  = null;
		var activeIndex   = -1;

		function escHtml( v ) {
			return String( v == null ? '' : v )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' );
		}

		function fmt( template, value ) {
			return String( template || '' ).replace( '%s', value );
		}

		function currentValues() {
			return Array.prototype.map.call(
				chipsHost.querySelectorAll( '[data-region-chip]' ),
				function ( el ) { return el.getAttribute( 'data-region-value' ) || ''; }
			);
		}

		function hasValue( value ) {
			return currentValues().indexOf( value ) !== -1;
		}

		// "Adding US implies the whole country, so any saved US:CA / US:NY
		// state-specific rows become redundant" — keep the chip list tidy
		// by pruning narrower selections when a broader one is added.
		function pruneRedundantStates( countryCode ) {
			Array.prototype.slice.call(
				chipsHost.querySelectorAll( '[data-region-chip]' )
			).forEach( function ( chip ) {
				var v = chip.getAttribute( 'data-region-value' ) || '';
				if ( v.indexOf( countryCode + ':' ) === 0 ) {
					chip.parentNode.removeChild( chip );
				}
			} );
		}

		function addChip( value, label ) {
			value = String( value || '' ).trim();
			if ( ! value || hasValue( value ) ) {
				return false;
			}

			// Block adding "US:CA" when "US" already covers the country.
			if ( value.indexOf( ':' ) !== -1 ) {
				var parent = value.split( ':' )[ 0 ];
				if ( hasValue( parent ) ) {
					return false;
				}
			} else {
				pruneRedundantStates( value );
			}

			var chip = document.createElement( 'span' );
			chip.className = 'tejcart-region-chip';
			chip.setAttribute( 'data-region-chip', '' );
			chip.setAttribute( 'data-region-value', value );

			var removeLabel = fmt( i18n.regionRemove || 'Remove %s', label );
			chip.innerHTML =
				'<span class="tejcart-region-chip-label"></span>' +
				'<button type="button" class="tejcart-region-chip-x" data-region-remove aria-label="' +
				escHtml( removeLabel ) + '">&times;</button>' +
				'<input type="hidden" name="zone_countries[]" />';
			chip.querySelector( '.tejcart-region-chip-label' ).textContent = label;
			chip.querySelector( 'input[type="hidden"]' ).value = value;
			chipsHost.appendChild( chip );
			return true;
		}

		function countryLabel( code ) {
			return countries[ code ] || code;
		}

		function stateLabel( country, state ) {
			var map = states[ country ] || {};
			return countryLabel( country ) + ' — ' + ( map[ state ] || state );
		}

		// Build the suggestion list. Two modes:
		// (a) drillCountry === null: countries matching the query, with
		//     a "States & provinces" affordance on each country that has
		//     a state dataset (lets the merchant drill in without typing).
		// (b) drillCountry !== null: states of that country matching the
		//     query, with a "← Back" header so the merchant can escape.
		function suggestionItems( query ) {
			query = String( query || '' ).trim().toLowerCase();
			var items = [];

			if ( drillCountry ) {
				items.push( {
					kind: 'back',
					label: i18n.regionBack || '← Back to all countries',
				} );

				var allValue = drillCountry;
				if ( ! hasValue( allValue ) ) {
					items.push( {
						kind: 'country',
						value: allValue,
						label: countryLabel( drillCountry ),
						sub: i18n.regionAll || 'Entire country',
					} );
				}

				var map = states[ drillCountry ] || {};
				Object.keys( map ).forEach( function ( code ) {
					var name = map[ code ];
					var hay  = ( name + ' ' + code ).toLowerCase();
					if ( query && hay.indexOf( query ) === -1 ) {
						return;
					}
					var value = drillCountry + ':' + code;
					if ( hasValue( value ) ) {
						return;
					}
					items.push( {
						kind: 'state',
						value: value,
						label: name,
						sub: code,
					} );
				} );
				return items;
			}

			Object.keys( countries ).forEach( function ( code ) {
				var name = countries[ code ];
				var hay  = ( name + ' ' + code ).toLowerCase();
				if ( query && hay.indexOf( query ) === -1 ) {
					return;
				}
				if ( hasValue( code ) ) {
					return;
				}
				var hasStates = states[ code ] && Object.keys( states[ code ] ).length > 0;
				items.push( {
					kind: 'country',
					value: code,
					label: name,
					sub: code,
					drill: hasStates,
				} );
			} );

			// Cap rendered options so a one-character query doesn't paint
			// 250 DOM nodes; the user can refine to surface more.
			return items.slice( 0, 50 );
		}

		function renderSuggestions() {
			var items = suggestionItems( input.value );
			dropdown.innerHTML = '';

			if ( ! items.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'tejcart-region-picker-empty';
				empty.textContent = i18n.regionNoResults || 'No matches.';
				dropdown.appendChild( empty );
				dropdown.hidden = false;
				return;
			}

			items.forEach( function ( item, i ) {
				var el = document.createElement( 'div' );
				el.className = 'tejcart-region-picker-option';
				el.setAttribute( 'role', 'option' );
				el.setAttribute( 'data-region-option-kind', item.kind );
				if ( item.value ) { el.setAttribute( 'data-region-option-value', item.value ); }
				if ( item.drill ) { el.setAttribute( 'data-region-option-drill', '1' ); }
				el.dataset.index = String( i );

				var labelEl = document.createElement( 'span' );
				labelEl.className = 'tejcart-region-picker-option-label';
				labelEl.textContent = item.label;
				el.appendChild( labelEl );

				if ( item.sub ) {
					var subEl = document.createElement( 'span' );
					subEl.className = 'tejcart-region-picker-option-sub';
					subEl.textContent = item.sub;
					el.appendChild( subEl );
				}

				if ( item.drill ) {
					var drillEl = document.createElement( 'button' );
					drillEl.type = 'button';
					drillEl.className = 'tejcart-region-picker-option-drill';
					drillEl.setAttribute( 'data-region-drill', '1' );
					drillEl.textContent = i18n.regionStates || 'States ›';
					el.appendChild( drillEl );
				}

				dropdown.appendChild( el );
			} );

			activeIndex = -1;
			dropdown.hidden = false;
		}

		function hideDropdown() {
			dropdown.hidden = true;
			activeIndex = -1;
			dropdown.querySelectorAll( '.is-active' ).forEach( function ( el ) {
				el.classList.remove( 'is-active' );
			} );
		}

		function setActive( i ) {
			var opts = dropdown.querySelectorAll( '.tejcart-region-picker-option' );
			if ( ! opts.length ) {
				return;
			}
			if ( i < 0 ) { i = opts.length - 1; }
			if ( i >= opts.length ) { i = 0; }
			activeIndex = i;
			opts.forEach( function ( el, idx ) {
				el.classList.toggle( 'is-active', idx === i );
			} );
			var active = opts[ i ];
			if ( active && active.scrollIntoView ) {
				active.scrollIntoView( { block: 'nearest' } );
			}
		}

		function applyOption( el, opts ) {
			if ( ! el ) { return; }
			opts = opts || {};
			var kind = el.getAttribute( 'data-region-option-kind' );
			if ( 'back' === kind ) {
				drillCountry = null;
				input.value  = '';
				renderSuggestions();
				input.focus();
				return;
			}

			var value = el.getAttribute( 'data-region-option-value' );
			if ( ! value ) { return; }

			// "States ›" affordance drills into the country's state list
			// instead of adding the whole country.
			if ( 'country' === kind && opts.drill && el.hasAttribute( 'data-region-option-drill' ) ) {
				drillCountry = value;
				input.value  = '';
				renderSuggestions();
				input.focus();
				return;
			}

			var label = 'country' === kind ? countryLabel( value ) : stateLabel( value.split( ':' )[ 0 ], value.split( ':' )[ 1 ] );
			if ( addChip( value, label ) ) {
				input.value = '';
				// Stay in the drill view if the merchant is adding several
				// states in a row, but return to the country list when they
				// just picked the whole country.
				if ( 'country' === kind ) {
					drillCountry = null;
				}
				renderSuggestions();
				input.focus();
			}
		}

		input.addEventListener( 'input', renderSuggestions );

		input.addEventListener( 'focus', renderSuggestions );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				if ( dropdown.hidden ) { renderSuggestions(); }
				setActive( activeIndex + 1 );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				if ( dropdown.hidden ) { renderSuggestions(); }
				setActive( activeIndex - 1 );
			} else if ( e.key === 'Enter' ) {
				if ( ! dropdown.hidden ) {
					e.preventDefault();
					var opts = dropdown.querySelectorAll( '.tejcart-region-picker-option' );
					var idx  = activeIndex >= 0 ? activeIndex : 0;
					if ( opts[ idx ] ) {
						applyOption( opts[ idx ] );
					}
				}
			} else if ( e.key === 'Escape' ) {
				if ( drillCountry ) {
					drillCountry = null;
					renderSuggestions();
				} else {
					hideDropdown();
				}
			} else if ( e.key === 'Backspace' && input.value === '' ) {
				if ( drillCountry ) {
					drillCountry = null;
					renderSuggestions();
					return;
				}
				var last = chipsHost.querySelector( '[data-region-chip]:last-child' );
				if ( last ) {
					last.parentNode.removeChild( last );
					renderSuggestions();
				}
			}
		} );

		dropdown.addEventListener( 'mousedown', function ( e ) {
			// mousedown (not click) so the input doesn't lose focus and
			// hide the dropdown before our handler runs.
			var drill  = e.target.closest && e.target.closest( '[data-region-drill]' );
			var option = e.target.closest && e.target.closest( '.tejcart-region-picker-option' );
			if ( ! option ) { return; }
			e.preventDefault();
			applyOption( option, { drill: !! drill } );
		} );

		dropdown.addEventListener( 'mouseover', function ( e ) {
			var option = e.target.closest && e.target.closest( '.tejcart-region-picker-option' );
			if ( ! option ) { return; }
			var opts = dropdown.querySelectorAll( '.tejcart-region-picker-option' );
			opts.forEach( function ( el, idx ) {
				if ( el === option ) {
					activeIndex = idx;
					el.classList.add( 'is-active' );
				} else {
					el.classList.remove( 'is-active' );
				}
			} );
		} );

		chipsHost.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest && e.target.closest( '[data-region-remove]' );
			if ( ! btn ) { return; }
			var chip = btn.closest( '[data-region-chip]' );
			if ( chip && chip.parentNode ) {
				chip.parentNode.removeChild( chip );
				if ( ! dropdown.hidden ) {
					renderSuggestions();
				}
				input.focus();
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				hideDropdown();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			if ( e.target === root || e.target.classList.contains( 'tejcart-region-picker-control' ) || e.target.classList.contains( 'tejcart-region-picker-chips' ) ) {
				input.focus();
			}
		} );
	}

	function initShippingZoneForm() {
		var form = document.getElementById( 'tejcart-shipping-zone-form' );
		if ( ! form ) {
			return;
		}

		var i18n     = ( data.shippingZones && data.shippingZones.i18n ) || {};
		var regions  = ( data.shippingZones && data.shippingZones.regions ) || {};
		var picker   = form.querySelector( '[data-tejcart-region-picker]' );
		if ( picker ) {
			initRegionPicker( picker, i18n, regions );
		}

		var types  = ( data.shippingZones && data.shippingZones.methodTypes ) || {};
		var i18n   = ( data.shippingZones && data.shippingZones.i18n ) || {};
		var addBtn = document.getElementById( 'tejcart-add-method-row' );
		var tbody  = form.querySelector( '#tejcart-methods-table tbody' );
		if ( ! addBtn || ! tbody ) {
			return;
		}
		var counter = tbody.querySelectorAll( 'tr' ).length;

		function isCarrierType( id ) {
			return typeof id === 'string' && id.indexOf( 'carrier_' ) === 0;
		}

		function escAttr( v ) {
			return String( v == null ? '' : v )
				.replace( /&/g, '&amp;' )
				.replace( /"/g, '&quot;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' );
		}

		function escHtml( v ) {
			return String( v == null ? '' : v )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' );
		}

		// Build the three "middle" cells (Cost / Min / Brackets) for either
		// a built-in or a carrier-driven method. Carrier rows collapse to a
		// single colspan="3" cell with a service-code input because rates
		// are quoted live from the carrier API.
		function middleCellsHtml( index, type, prev ) {
			prev = prev || {};
			if ( isCarrierType( type ) ) {
				return '<td colspan="3" class="tejcart-carrier-row__live">' +
					'<input type="hidden" name="methods[' + index + '][cost]" value="0" />' +
					'<input type="hidden" name="methods[' + index + '][min_amount]" value="0" />' +
					'<label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">' +
					escHtml( i18n.serviceCodeLabel || 'Service code (optional)' ) +
					'</label>' +
					'<input type="text" name="methods[' + index + '][service_code]" value="' +
					escAttr( prev.serviceCode || '' ) +
					'" placeholder="' + escAttr( i18n.serviceCodeHint || '' ) + '" style="width:100%;max-width:320px;" />' +
					'<p class="description" style="margin-top:6px;">' +
					escHtml( i18n.carrierNote || '' ) +
					'</p>' +
					'</td>';
			}
			return '<td><input type="number" step="0.01" name="methods[' + index + '][cost]" value="' +
					escAttr( prev.cost != null ? prev.cost : 0 ) + '" /></td>' +
				'<td><input type="number" step="0.01" name="methods[' + index + '][min_amount]" value="' +
					escAttr( prev.minAmount != null ? prev.minAmount : 0 ) + '" /></td>' +
				'<td><textarea name="methods[' + index + '][rates_text]" rows="3" placeholder="from|to|cost">' +
					escHtml( prev.ratesText || '' ) +
					'</textarea><br /><small>' + escHtml( i18n.bracketHint || '' ) + '</small></td>';
		}

		function applyRowVariant( row ) {
			var select = row.querySelector( 'select.tejcart-shipping-method-type' );
			if ( ! select ) {
				return;
			}
			var type = select.value;
			var carrier = isCarrierType( type );
			row.classList.toggle( 'is-carrier-row', carrier );

			// Preserve any values the user has already typed so that toggling
			// the dropdown back and forth doesn't wipe their input.
			var prev = {};
			var costEl     = row.querySelector( 'input[name$="[cost]"]' );
			var minEl      = row.querySelector( 'input[name$="[min_amount]"]' );
			var ratesEl    = row.querySelector( 'textarea[name$="[rates_text]"]' );
			var serviceEl  = row.querySelector( 'input[name$="[service_code]"]' );
			if ( costEl )    { prev.cost        = costEl.value; }
			if ( minEl )     { prev.minAmount   = minEl.value; }
			if ( ratesEl )   { prev.ratesText   = ratesEl.value; }
			if ( serviceEl ) { prev.serviceCode = serviceEl.value; }

			// Strip the existing middle cells (everything between Title and
			// the Remove action) and rebuild them for the chosen variant.
			var cells = Array.prototype.slice.call( row.children );
			// 0: Method Type, 1: Title, last: Remove. Drop indices 2..last-1.
			for ( var i = cells.length - 2; i >= 2; i-- ) {
				row.removeChild( cells[ i ] );
			}
			var name = select.getAttribute( 'name' ) || '';
			var idx  = ( name.match( /methods\[(\d+)\]/ ) || [ '', '0' ] )[ 1 ];
			var html = middleCellsHtml( idx, type, prev );

			// Insert before the trailing Remove cell.
			var remove = row.lastElementChild;
			var tpl    = document.createElement( 'tbody' );
			tpl.innerHTML = '<tr>' + html + '</tr>';
			var newCells = tpl.firstChild.children;
			while ( newCells.length ) {
				row.insertBefore( newCells[ 0 ], remove );
			}
		}

		addBtn.addEventListener( 'click', function () {
			var tr   = document.createElement( 'tr' );
			tr.className = 'tejcart-shipping-method-row';
			var opts = '';
			var firstType = '';
			Object.keys( types ).forEach( function ( k ) {
				if ( '' === firstType ) { firstType = k; }
				var optEl = document.createElement( 'option' );
				optEl.value       = k;
				optEl.textContent = types[ k ];
				opts += optEl.outerHTML;
			} );
			tr.innerHTML =
				'<td><select name="methods[' + counter + '][type]" class="tejcart-shipping-method-type">' + opts + '</select></td>' +
				'<td><input type="text" name="methods[' + counter + '][title]" /></td>' +
				middleCellsHtml( counter, firstType, {} ) +
				'<td><a href="#" class="tejcart-remove-row">' + escHtml( i18n.remove || 'Remove' ) + '</a></td>';
			tbody.appendChild( tr );
			if ( isCarrierType( firstType ) ) {
				tr.classList.add( 'is-carrier-row' );
			}
			counter++;
		} );

		tbody.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'tejcart-shipping-method-type' ) ) {
				var row = e.target.closest( 'tr' );
				if ( row ) {
					applyRowVariant( row );
				}
			}
		} );

		tbody.addEventListener( 'click', function ( e ) {
			if ( e.target && e.target.classList.contains( 'tejcart-remove-row' ) ) {
				e.preventDefault();
				var row = e.target.closest( 'tr' );
				if ( row ) {
					row.parentNode.removeChild( row );
				}
			}
		} );
	}

	function initNewOrderForm() {
		var addBtn = document.getElementById( 'tejcart-add-item-row' );
		var tbody  = document.querySelector( '#tejcart-new-order-items tbody' );
		if ( ! addBtn || ! tbody ) {
			return;
		}

		var idx         = 1;
		var firstSelect = tbody.querySelector( 'select' );
		var optionsHTML = firstSelect ? firstSelect.innerHTML : '';

		addBtn.addEventListener( 'click', function () {
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td><select name="items[' + idx + '][product_id]">' + optionsHTML + '</select></td>' +
				'<td><input type="number" min="1" name="items[' + idx + '][quantity]" value="1" /></td>' +
				'<td><input type="number" step="0.01" name="items[' + idx + '][unit_price]" /></td>' +
				'<td><a href="#" class="tejcart-remove-row">Remove</a></td>';
			tbody.appendChild( tr );
			idx++;
		} );

		tbody.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'tejcart-remove-row' ) ) {
				e.preventDefault();
				var row = e.target.closest( 'tr' );
				if ( tbody.querySelectorAll( 'tr' ).length > 1 ) {
					row.parentNode.removeChild( row );
				}
			}
		} );
	}

	function initOrderPreview() {
		var overlay = document.getElementById( 'tejcart-preview-modal' );
		if ( ! overlay ) {
			return;
		}

		var i18n  = ( data.orderPreview ) || {};
		var title = overlay.querySelector( '.tejcart-modal-title' );
		var body  = overlay.querySelector( '.tejcart-modal-body' );
		var close = overlay.querySelector( '.tejcart-modal-close' );

		function closeModal() {
			overlay.classList.remove( 'is-open' );
			overlay.setAttribute( 'aria-hidden', 'true' );
			body.innerHTML = '';
		}

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeModal(); }
		} );
		if ( close ) {
			close.addEventListener( 'click', closeModal );
		}
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { closeModal(); }
		} );

		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.tejcart-order-preview' );
			if ( ! link ) {
				return;
			}
			e.preventDefault();

			var loading = document.createElement( 'p' );
			loading.textContent = i18n.loading || 'Loading…';
			body.innerHTML = '';
			body.appendChild( loading );
			overlay.classList.add( 'is-open' );
			overlay.setAttribute( 'aria-hidden', 'false' );

			var form = new FormData();
			form.append( 'action', 'tejcart_preview_order' );
			form.append( 'order_id', link.dataset.orderId );
			form.append( 'nonce', link.dataset.nonce );

			fetch( window.ajaxurl, { method: 'POST', body: form, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						title.textContent = res.data.title;
						body.innerHTML    = res.data.html;
					} else {
						body.textContent = ( res && res.data && res.data.message ) || ( i18n.error || 'Preview failed.' );
					}
				} )
				.catch( function () {
					body.textContent = i18n.network || 'Network error.';
				} );
		} );
	}

	function initSystemStatusCopy() {
		var btn = document.getElementById( 'tejcart-copy-status' );
		if ( ! btn ) {
			return;
		}

		var i18n = ( data.systemStatus ) || {};

		btn.addEventListener( 'click', function () {
			var report = document.getElementById( 'tejcart-status-report' );
			if ( ! report ) {
				return;
			}
			var tables = report.querySelectorAll( 'table' );
			var text   = '';
			tables.forEach( function ( table ) {
				var heading = table.previousElementSibling;
				if ( heading && heading.tagName && heading.tagName.match( /^H[23]$/ ) ) {
					text += '\n### ' + heading.textContent.trim() + '\n\n';
				}
				var rows = table.querySelectorAll( 'tr' );
				rows.forEach( function ( row ) {
					var cells = row.querySelectorAll( 'td, th' );
					var line  = [];
					cells.forEach( function ( cell ) { line.push( cell.textContent.trim() ); } );
					text += line.join( ': ' ) + '\n';
				} );
				text += '\n';
			} );

			var notify = function () {
				if ( window.alert ) {
					window.alert( i18n.copied || 'System status copied to clipboard.' );
				}
			};

			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( text.trim() ).then( notify );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text.trim();
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
				notify();
			}
		} );
	}

	function initRefundForm() {
		var form = document.querySelector( '.tejcart-refund-form' );
		if ( ! form ) {
			return;
		}

		var amountInput = form.querySelector( '#refund_amount' );
		var maxBtn      = form.querySelector( '.tejcart-refund-max' );
		var setStatus   = form.querySelector( '.tejcart-refund-set-status' );
		var submitBtn   = form.querySelector( '.tejcart-refund-submit' );
		var reasonSel   = form.querySelector( '.tejcart-refund-reason-select' );
		var reasonText  = form.querySelector( '.tejcart-refund-reason-text' );

		var remaining     = parseFloat( form.getAttribute( 'data-remaining' ) || '0' );
		var currency      = form.getAttribute( 'data-currency' ) || '';
		var gatewayTitle  = form.getAttribute( 'data-gateway-title' ) || '';
		var initialLabel  = submitBtn ? submitBtn.textContent : '';

		// Best-effort currency formatter. Falls back to "X.XX CCY" when
		// Intl.NumberFormat or the currency code are not available — the
		// server-rendered initial label remains the source of truth.
		function formatMoney( amount ) {
			if ( typeof amount !== 'number' || isNaN( amount ) ) {
				return '';
			}
			try {
				if ( currency && window.Intl && Intl.NumberFormat ) {
					return new Intl.NumberFormat( undefined, {
						style:    'currency',
						currency: currency,
					} ).format( amount );
				}
			} catch ( e ) {
				// fall through
			}
			return amount.toFixed( 2 ) + ( currency ? ' ' + currency : '' );
		}

		function syncSubmitLabel() {
			if ( ! submitBtn || ! amountInput ) {
				return;
			}
			var v = parseFloat( amountInput.value || '0' );
			if ( isNaN( v ) || v <= 0 ) {
				submitBtn.textContent = initialLabel;
				return;
			}
			var money = formatMoney( v );
			if ( ! money ) {
				submitBtn.textContent = initialLabel;
				return;
			}
			if ( gatewayTitle ) {
				submitBtn.textContent = 'Refund ' + money + ' via ' + gatewayTitle;
			} else {
				submitBtn.textContent = 'Record refund of ' + money;
			}
		}

		function syncSetStatus() {
			if ( ! setStatus || ! amountInput ) {
				return;
			}
			var v = parseFloat( amountInput.value || '0' );
			// Auto-tick "Mark refunded" when the input fully drains the
			// remaining balance — the merchant almost always wants this on
			// for a full refund. Manual override is preserved if the
			// admin un-ticks after typing.
			if ( ! setStatus.dataset.touched && Math.abs( v - remaining ) < 0.01 ) {
				setStatus.checked = true;
			}
		}

		if ( amountInput ) {
			amountInput.addEventListener( 'input', function () {
				syncSubmitLabel();
				syncSetStatus();
			} );
		}

		if ( maxBtn && amountInput ) {
			maxBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				amountInput.value = remaining.toFixed( 2 );
				syncSubmitLabel();
				syncSetStatus();
				amountInput.focus();
			} );
		}

		if ( setStatus ) {
			setStatus.addEventListener( 'change', function () {
				setStatus.dataset.touched = '1';
			} );
		}

		// "Other" reveals the free-text input; preset slugs hide it but keep
		// any text the admin already typed so they can swap freely.
		if ( reasonSel && reasonText ) {
			var syncReason = function () {
				if ( reasonSel.value === 'other' || reasonSel.value === '' ) {
					reasonText.style.display = '';
				} else {
					reasonText.style.display = '';
				}
			};
			reasonSel.addEventListener( 'change', syncReason );
			syncReason();
		}

		syncSetStatus();
	}

	function initRefundLineTable() {
		var form = document.querySelector( '.tejcart-refund-form' );
		if ( ! form ) {
			return;
		}
		var table = form.querySelector( '.tejcart-refund-line-table' );
		if ( ! table ) {
			return;
		}

		var rows           = Array.prototype.slice.call( table.querySelectorAll( 'tr.tejcart-refund-line:not(.tejcart-refund-line--shipping):not(.tejcart-refund-line--tax)' ) );
		var shippingInput  = table.querySelector( '.tejcart-refund-shipping-amount' );
		var shippingCheck  = table.querySelector( '.tejcart-refund-shipping-check' );
		var shippingRow    = table.querySelector( 'tr.tejcart-refund-line--shipping' );
		var taxInput       = table.querySelector( '.tejcart-refund-tax-amount' );
		var taxCheck       = table.querySelector( '.tejcart-refund-tax-check' );
		var taxRow         = table.querySelector( 'tr.tejcart-refund-line--tax' );
		var lineTotalEl    = table.querySelector( '.tejcart-refund-line-total' );
		var taxTotalEl     = table.querySelector( '.tejcart-refund-tax-total' );
		var grandTotalEl   = table.querySelector( '.tejcart-refund-grand-total' );
		var amountInput    = form.querySelector( '#refund_amount' );
		var amountRow      = form.querySelector( '.tejcart-refund-amount-row' );
		var toggleAll      = table.querySelector( '.tejcart-refund-line-toggle-all' );
		var currency       = ( lineTotalEl && lineTotalEl.getAttribute( 'data-currency' ) ) || form.getAttribute( 'data-currency' ) || '';
		var orderSubtotal  = parseFloat( form.getAttribute( 'data-order-subtotal' ) || '0' );
		var orderTax       = parseFloat( form.getAttribute( 'data-order-tax' ) || '0' );
		var remaining      = parseFloat( form.getAttribute( 'data-remaining' ) || '0' );

		function fmt( n ) {
			if ( typeof n !== 'number' || isNaN( n ) ) {
				return '0.00';
			}
			try {
				if ( currency && window.Intl && Intl.NumberFormat ) {
					return new Intl.NumberFormat( undefined, { style: 'currency', currency: currency } ).format( n );
				}
			} catch ( e ) {
				// fall through
			}
			return n.toFixed( 2 );
		}

		function recompute() {
			var itemSubtotal = 0;
			var anyLine = false;
			rows.forEach( function ( row ) {
				var qty  = parseFloat( row.querySelector( '.tejcart-refund-line-qty' ).value || '0' );
				var amt  = parseFloat( row.querySelector( '.tejcart-refund-line-amount' ).value || '0' );
				if ( ( qty > 0 || amt > 0 ) ) {
					row.classList.add( 'is-active' );
					anyLine = true;
				} else {
					row.classList.remove( 'is-active' );
				}
				if ( !isNaN( amt ) && amt > 0 ) {
					itemSubtotal += amt;
				}
			} );

			// Shipping counts only when its row is ticked, mirroring the item
			// rows. Ticking auto-fills the refundable amount (see handlers).
			var ship = ( shippingInput && ( ! shippingCheck || shippingCheck.checked ) ) ? parseFloat( shippingInput.value || '0' ) : 0;
			if ( isNaN( ship ) || ship < 0 ) {
				ship = 0;
			}
			if ( ship > 0 ) {
				anyLine = true;
			}

			var linesTotal = itemSubtotal + ship;

			// Tax is merchant-controlled via its own line: read the explicit
			// amount when the tax row is ticked. The backend refunds exactly
			// this figure (no proportional auto-tax on the admin path), so the
			// preview and the charge always agree.
			var tax = ( taxInput && ( ! taxCheck || taxCheck.checked ) ) ? parseFloat( taxInput.value || '0' ) : 0;
			if ( isNaN( tax ) || tax < 0 ) {
				tax = 0;
			}
			if ( tax > 0 ) {
				anyLine = true;
			}

			var grand = Math.round( ( linesTotal + tax ) * 100 ) / 100;

			if ( lineTotalEl ) {
				lineTotalEl.textContent = fmt( linesTotal );
			}
			if ( taxTotalEl ) {
				taxTotalEl.textContent = fmt( tax );
			}
			if ( grandTotalEl ) {
				grandTotalEl.textContent = fmt( grand );
			}

			if ( amountRow ) {
				if ( anyLine ) {
					amountRow.classList.add( 'is-superseded' );
					amountRow.style.opacity = '0.5';
					if ( amountInput ) {
						amountInput.disabled = true;
						// Mirror the tax-inclusive grand total into the global
						// Amount field so the merchant always sees the figure
						// that will actually be refunded. The field stays
						// disabled to signal that line totals win in this mode.
						amountInput.value = grand.toFixed( 2 );
					}
				} else {
					amountRow.classList.remove( 'is-superseded' );
					amountRow.style.opacity = '';
					if ( amountInput ) {
						amountInput.disabled = false;
					}
				}
			}

			// Update the submit button label from the tax-inclusive grand
			// total when in per-line mode; otherwise let the existing
			// amount-only handler in initRefundForm drive it.
			var submit = form.querySelector( '.tejcart-refund-submit' );
			if ( submit && anyLine ) {
				var gw = form.getAttribute( 'data-gateway-title' ) || '';
				if ( gw ) {
					submit.textContent = 'Refund ' + fmt( grand ) + ' via ' + gw;
				} else {
					submit.textContent = 'Record refund of ' + fmt( grand );
				}
			}
		}

		rows.forEach( function ( row ) {
			var check    = row.querySelector( '.tejcart-refund-line-check' );
			var qtyInput = row.querySelector( '.tejcart-refund-line-qty' );
			var amtInput = row.querySelector( '.tejcart-refund-line-amount' );
			var restock  = row.querySelector( '.tejcart-refund-line-restock' );
			var unitPrice = parseFloat( row.getAttribute( 'data-unit-price' ) || '0' );
			var availQty  = parseInt( row.getAttribute( 'data-available-qty' ) || '0', 10 );
			var availAmt  = parseFloat( row.getAttribute( 'data-available-amount' ) || '0' );

			if ( check ) {
				check.addEventListener( 'change', function () {
					if ( check.checked ) {
						if ( qtyInput && availQty > 0 && parseInt( qtyInput.value, 10 ) === 0 ) {
							qtyInput.value = availQty;
						}
						if ( amtInput && parseFloat( amtInput.value ) === 0 ) {
							amtInput.value = availAmt.toFixed( 2 );
						}
						if ( restock ) {
							restock.checked = true;
						}
					} else {
						if ( qtyInput ) { qtyInput.value = 0; }
						if ( amtInput ) { amtInput.value = ( 0 ).toFixed( 2 ); }
						if ( restock ) { restock.checked = false; }
					}
					recompute();
				} );
			}

			if ( qtyInput ) {
				qtyInput.addEventListener( 'input', function () {
					var q = parseInt( qtyInput.value || '0', 10 );
					if ( amtInput && unitPrice > 0 && !amtInput.dataset.touched ) {
						amtInput.value = ( Math.max( 0, q ) * unitPrice ).toFixed( 2 );
					}
					if ( check ) {
						check.checked = q > 0;
					}
					recompute();
				} );
			}

			if ( amtInput ) {
				amtInput.addEventListener( 'input', function () {
					amtInput.dataset.touched = '1';
					recompute();
				} );
			}
		} );

		// Shipping and tax behave like the item rows: ticking the checkbox
		// auto-fills the row's refundable amount ("auto fill-up"), unticking
		// zeroes it, and editing the amount keeps the row ticked.
		function wireSyntheticRow( row, check, input ) {
			if ( ! row || ! input ) {
				return;
			}
			var availAmt = parseFloat( row.getAttribute( 'data-available-amount' ) || '0' );
			if ( check ) {
				check.addEventListener( 'change', function () {
					if ( check.checked ) {
						if ( ! ( parseFloat( input.value ) > 0 ) ) {
							input.value = availAmt.toFixed( 2 );
						}
					} else {
						input.value = ( 0 ).toFixed( 2 );
					}
					recompute();
				} );
			}
			input.addEventListener( 'input', function () {
				if ( check ) {
					check.checked = parseFloat( input.value || '0' ) > 0;
				}
				recompute();
			} );
		}

		wireSyntheticRow( shippingRow, shippingCheck, shippingInput );
		wireSyntheticRow( taxRow, taxCheck, taxInput );

		if ( toggleAll ) {
			toggleAll.addEventListener( 'change', function () {
				var checks = [];
				rows.forEach( function ( row ) {
					checks.push( row.querySelector( '.tejcart-refund-line-check' ) );
				} );
				checks.push( shippingCheck );
				checks.push( taxCheck );
				checks.forEach( function ( check ) {
					if ( check && ! check.disabled && check.checked !== toggleAll.checked ) {
						check.checked = toggleAll.checked;
						check.dispatchEvent( new Event( 'change' ) );
					}
				} );
			} );
		}

		recompute();
	}

	function initCopyButtons() {
		var btns = document.querySelectorAll( '.tejcart-copy[data-copy]' );
		if ( ! btns.length ) {
			return;
		}
		btns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var v = btn.getAttribute( 'data-copy' ) || '';
				if ( ! v ) {
					return;
				}
				var done = function () {
					btn.classList.add( 'is-copied' );
					setTimeout( function () { btn.classList.remove( 'is-copied' ); }, 1500 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( v ).then( done );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = v;
					document.body.appendChild( ta );
					ta.select();
					try { document.execCommand( 'copy' ); } catch ( err ) {}
					document.body.removeChild( ta );
					done();
				}
			} );
		} );
	}

	function initCaptchaProviderToggle() {
		var select = document.querySelector( '[data-tejcart-captcha-provider]' );
		if ( ! select ) {
			return;
		}
		var sections = document.querySelectorAll( '[data-tejcart-captcha-section]' );
		if ( ! sections.length ) {
			return;
		}

		function applyVisibility() {
			var current = select.value;
			sections.forEach( function ( section ) {
				var key = section.getAttribute( 'data-tejcart-captcha-section' );
				// Toggle the `hidden` HTML attribute (CSP-safe) instead of
				// mutating style.display. The PHP template renders the
				// non-current sections with `hidden` so initial render
				// already matches; this just keeps them in sync after a
				// provider-select change.
				if ( key === current ) {
					section.removeAttribute( 'hidden' );
				} else {
					section.setAttribute( 'hidden', '' );
				}
			} );
		}

		select.addEventListener( 'change', applyVisibility );
		applyVisibility();
	}

	function init() {
		initShippingZoneForm();
		initNewOrderForm();
		initOrderPreview();
		initSystemStatusCopy();
		initRefundForm();
		initRefundLineTable();
		initCopyButtons();
		initCaptchaProviderToggle();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
