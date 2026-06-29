/**
 * TejCart Store Insights — Dashboard Charts.
 *
 * Reads report data from a <script type="application/json"> block
 * rendered by Dashboard.php and draws Chart.js visualisations above
 * the data tables. The tables remain as accessible fallback.
 */
( function () {
	'use strict';

	// Rebuild Now button — must bind before any early return so it works
	// even when there is no chart data (e.g. empty Cohorts tab).
	var rebuildBtn = document.querySelector( '.tejcart-aa-rebuild-btn' );
	if ( rebuildBtn ) {
		rebuildBtn.addEventListener( 'click', function () {
			var btn   = this;
			var nonce = btn.getAttribute( 'data-nonce' );
			if ( ! nonce || btn.disabled ) {
				return;
			}
			btn.disabled    = true;
			btn.textContent = btn.textContent.replace( /\S+/, '…' );

			var body = new FormData();
			body.append( 'action', 'tejcart_analytics_advanced_rebuild' );
			body.append( '_ajax_nonce', nonce );

			fetch( window.ajaxurl || '/wp-admin/admin-ajax.php', {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res.success ) {
					location.reload();
				} else {
					btn.disabled    = false;
					btn.textContent = 'Rebuild Now';
					window.alert( ( res.data && res.data.message ) || 'Rebuild failed.' );
				}
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = 'Rebuild Now';
			} );
		} );
	}

	var dataEl = document.getElementById( 'tejcart-aa-chart-data' );
	if ( ! dataEl ) {
		return;
	}

	var payload;
	try {
		payload = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}

	var Chart = window.Chart;
	if ( ! Chart ) {
		return;
	}

	var COLORS = {
		revenue:   '#2271b1',
		orders:    '#00875a',
		aov:       '#9b59b6',
		customers: '#d63638',
	};

	var SEGMENT_COLORS = {
		vip:       '#00875a',
		loyal:     '#2ea043',
		active:    '#2271b1',
		'at-risk': '#dba617',
		lapsed:    '#d63638',
		'new':     '#72aee6',
	};

	function createTooltipCurrency( decimals, symbol ) {
		return function ( ctx ) {
			var raw = ctx.parsed.y;
			if ( raw === undefined || raw === null ) {
				return '';
			}
			return symbol + raw.toLocaleString( undefined, {
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals,
			} );
		};
	}

	function sharedOptions() {
		return {
			responsive:          true,
			maintainAspectRatio: false,
			animation:           { duration: 300 },
			interaction:         { mode: 'index', intersect: false },
			plugins:             {
				legend: {
					position: 'bottom',
					labels:   { usePointStyle: true, padding: 16 },
				},
			},
		};
	}

	function renderTrends( data ) {
		var canvas = document.getElementById( 'tejcart-aa-trends-chart' );
		if ( ! canvas ) {
			return;
		}

		var labels    = [];
		var revenue   = [];
		var orders    = [];
		var aov       = [];
		var customers = [];
		var decimals  = data.currency_decimals || 2;
		var symbol    = data.currency_symbol || '$';
		var multiplier = Math.pow( 10, decimals );

		for ( var i = 0; i < data.rows.length; i++ ) {
			var row = data.rows[ i ];
			labels.push( row.month );
			revenue.push( row.revenue / multiplier );
			orders.push( row.order_count );
			aov.push( row.aov / multiplier );
			customers.push( row.customer_count );
		}

		var opts = sharedOptions();
		opts.scales = {
			x: {
				grid:  { display: false },
				ticks: { maxRotation: 45 },
			},
			yRevenue: {
				type:     'linear',
				position: 'left',
				title:    { display: true, text: data.labels.revenue || 'Revenue' },
				ticks:    {
					callback: function ( v ) {
						return symbol + v.toLocaleString();
					},
				},
				grid: { color: 'rgba(0,0,0,0.04)' },
			},
			yCount: {
				type:     'linear',
				position: 'right',
				title:    { display: true, text: data.labels.orders || 'Orders' },
				grid:     { drawOnChartArea: false },
				beginAtZero: true,
			},
		};

		opts.plugins.tooltip = {
			callbacks: {
				label: function ( ctx ) {
					var dsLabel = ctx.dataset.label || '';
					if ( ctx.dataset.yAxisID === 'yRevenue' ) {
						return dsLabel + ': ' + createTooltipCurrency( decimals, symbol )( ctx );
					}
					return dsLabel + ': ' + ctx.parsed.y.toLocaleString();
				},
			},
		};

		new Chart( canvas, {
			type: 'line',
			data: {
				labels:   labels,
				datasets: [
					{
						label:           data.labels.revenue || 'Revenue',
						data:            revenue,
						borderColor:     COLORS.revenue,
						backgroundColor: COLORS.revenue + '18',
						fill:            true,
						tension:         0.3,
						yAxisID:         'yRevenue',
						pointRadius:     3,
						pointHoverRadius: 5,
					},
					{
						label:           data.labels.aov || 'AOV',
						data:            aov,
						borderColor:     COLORS.aov,
						borderDash:      [ 5, 3 ],
						tension:         0.3,
						yAxisID:         'yRevenue',
						pointRadius:     2,
						pointHoverRadius: 4,
					},
					{
						label:           data.labels.orders || 'Orders',
						data:            orders,
						borderColor:     COLORS.orders,
						tension:         0.3,
						yAxisID:         'yCount',
						pointRadius:     3,
						pointHoverRadius: 5,
					},
					{
						label:           data.labels.customers || 'Customers',
						data:            customers,
						borderColor:     COLORS.customers,
						tension:         0.3,
						yAxisID:         'yCount',
						pointRadius:     2,
						pointHoverRadius: 4,
					},
				],
			},
			options: opts,
		} );
	}

	function renderSegments( data ) {
		var donutCanvas = document.getElementById( 'tejcart-aa-segments-donut' );
		var barCanvas   = document.getElementById( 'tejcart-aa-segments-bar' );
		if ( ! donutCanvas && ! barCanvas ) {
			return;
		}

		var segLabels  = [];
		var counts     = [];
		var revenues   = [];
		var colors     = [];
		var decimals   = data.currency_decimals || 2;
		var symbol     = data.currency_symbol || '$';
		var multiplier = Math.pow( 10, decimals );

		for ( var key in data.segments ) {
			if ( ! data.segments.hasOwnProperty( key ) ) {
				continue;
			}
			var seg = data.segments[ key ];
			segLabels.push( data.segment_labels[ key ] || key );
			counts.push( seg.customer_count );
			revenues.push( seg.revenue / multiplier );
			colors.push( SEGMENT_COLORS[ key ] || '#999' );
		}

		if ( donutCanvas ) {
			var donutOpts = sharedOptions();
			donutOpts.cutout = '55%';
			donutOpts.plugins.tooltip = {
				callbacks: {
					label: function ( ctx ) {
						var total = 0;
						for ( var j = 0; j < ctx.dataset.data.length; j++ ) {
							total += ctx.dataset.data[ j ];
						}
						var pct = total > 0 ? ( ctx.parsed * 100 / total ).toFixed( 1 ) : '0';
						return ctx.label + ': ' + ctx.parsed.toLocaleString() + ' (' + pct + '%)';
					},
				},
			};

			new Chart( donutCanvas, {
				type: 'doughnut',
				data: {
					labels:   segLabels,
					datasets: [ {
						data:            counts,
						backgroundColor: colors,
						borderWidth:     2,
						borderColor:     '#fff',
					} ],
				},
				options: donutOpts,
			} );
		}

		if ( barCanvas ) {
			var barOpts = sharedOptions();
			barOpts.indexAxis = 'y';
			barOpts.scales   = {
				x: {
					ticks: {
						callback: function ( v ) {
							return symbol + v.toLocaleString();
						},
					},
					grid: { color: 'rgba(0,0,0,0.04)' },
				},
				y: {
					grid: { display: false },
				},
			};
			barOpts.plugins.tooltip = {
				callbacks: {
					label: function ( ctx ) {
						return ctx.label + ': ' + symbol + ctx.parsed.x.toLocaleString( undefined, {
							minimumFractionDigits: decimals,
							maximumFractionDigits: decimals,
						} );
					},
				},
			};
			barOpts.plugins.legend = { display: false };

			new Chart( barCanvas, {
				type: 'bar',
				data: {
					labels:   segLabels,
					datasets: [ {
						data:            revenues,
						backgroundColor: colors,
						borderRadius:    3,
					} ],
				},
				options: barOpts,
			} );
		}
	}

	if ( payload.tab === 'trends' && payload.trends ) {
		renderTrends( payload.trends );
	}
	if ( payload.tab === 'segments' && payload.segments ) {
		renderSegments( payload.segments );
	}

} )();
