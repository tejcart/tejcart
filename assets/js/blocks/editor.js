/**
 * TejCart Gutenberg Block Editor Scripts.
 *
 * Registers block types for the WordPress block editor.
 *
 * @package TejCart
 */

( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var __                = wp.i18n.__;
	var TextControl       = wp.components.TextControl;
	var ToggleControl     = wp.components.ToggleControl;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var Placeholder       = wp.components.Placeholder;
	var Dashicon          = wp.components.Dashicon;

	registerBlockType( 'tejcart/add-to-cart', {
		title: __( 'TejCart Add to Cart', 'tejcart' ),
		icon: 'cart',
		category: 'widgets',
		attributes: {
			product_id:   { type: 'number',  default: 0 },
			button_text:  { type: 'string',  default: __( 'Add to Cart', 'tejcart' ) },
			button_class: { type: 'string',  default: '' },
			redirect_url: { type: 'string',  default: '' }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Product ID', 'tejcart' ),
							type: 'number',
							value: props.attributes.product_id || '',
							onChange: function ( val ) {
								props.setAttributes( { product_id: parseInt( val, 10 ) || 0 } );
							}
						} ),
						el( TextControl, {
							label: __( 'Button Text', 'tejcart' ),
							value: props.attributes.button_text,
							onChange: function ( val ) {
								props.setAttributes( { button_text: val } );
							}
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'cart' } ),
					label: __( 'TejCart Add to Cart', 'tejcart' ),
					instructions: props.attributes.product_id
						? __( 'Product ID: ', 'tejcart' ) + props.attributes.product_id
						: __( 'Select a product ID in the block settings.', 'tejcart' )
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	registerBlockType( 'tejcart/cart', {
		title: __( 'TejCart Cart', 'tejcart' ),
		icon: 'cart',
		category: 'widgets',
		attributes: {
			show_thumbnails: { type: 'boolean', default: true },
			show_totals:     { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( ToggleControl, {
							label: __( 'Show Thumbnails', 'tejcart' ),
							checked: props.attributes.show_thumbnails,
							onChange: function ( val ) {
								props.setAttributes( { show_thumbnails: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Totals', 'tejcart' ),
							checked: props.attributes.show_totals,
							onChange: function ( val ) {
								props.setAttributes( { show_totals: val } );
							}
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'cart' } ),
					label: __( 'TejCart Cart', 'tejcart' )
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	registerBlockType( 'tejcart/product-box', {
		title: __( 'TejCart Product Box', 'tejcart' ),
		icon: 'products',
		category: 'widgets',
		attributes: {
			product_id:       { type: 'number',  default: 0 },
			layout:           { type: 'string',  default: 'vertical' },
			show_image:       { type: 'boolean', default: true },
			show_description: { type: 'boolean', default: true },
			show_price:       { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Product ID', 'tejcart' ),
							type: 'number',
							value: props.attributes.product_id || '',
							onChange: function ( val ) {
								props.setAttributes( { product_id: parseInt( val, 10 ) || 0 } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Image', 'tejcart' ),
							checked: props.attributes.show_image,
							onChange: function ( val ) {
								props.setAttributes( { show_image: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Description', 'tejcart' ),
							checked: props.attributes.show_description,
							onChange: function ( val ) {
								props.setAttributes( { show_description: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Price', 'tejcart' ),
							checked: props.attributes.show_price,
							onChange: function ( val ) {
								props.setAttributes( { show_price: val } );
							}
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'products' } ),
					label: __( 'TejCart Product Box', 'tejcart' ),
					instructions: props.attributes.product_id
						? __( 'Product ID: ', 'tejcart' ) + props.attributes.product_id
						: __( 'Select a product ID in the block settings.', 'tejcart' )
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	registerBlockType( 'tejcart/mini-cart', {
		title: __( 'TejCart Mini Cart', 'tejcart' ),
		icon: 'cart',
		category: 'widgets',
		attributes: {
			show_count: { type: 'boolean', default: true },
			show_total: { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( ToggleControl, {
							label: __( 'Show Count', 'tejcart' ),
							checked: props.attributes.show_count,
							onChange: function ( val ) {
								props.setAttributes( { show_count: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Total', 'tejcart' ),
							checked: props.attributes.show_total,
							onChange: function ( val ) {
								props.setAttributes( { show_total: val } );
							}
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'cart' } ),
					label: __( 'TejCart Mini Cart', 'tejcart' )
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	/* ---------------------------------------------------------------
	 * Discovery blocks
	 * --------------------------------------------------------------- */

	var RangeControl  = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );

	registerBlockType( 'tejcart/featured-product', {
		title: __( 'TejCart Featured Product', 'tejcart' ),
		icon: 'star-filled',
		category: 'widgets',
		keywords: [ 'hero', 'featured', 'product' ],
		attributes: {
			product_id:       { type: 'number',  default: 0 },
			show_price:       { type: 'boolean', default: true },
			show_description: { type: 'boolean', default: true },
			button_text:      { type: 'string',  default: '' },
			min_height:       { type: 'number',  default: 400 },
			overlay_opacity:  { type: 'number',  default: 50 }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Product', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Product ID', 'tejcart' ),
							type: 'number',
							value: a.product_id || '',
							onChange: function ( v ) { props.setAttributes( { product_id: parseInt( v, 10 ) || 0 } ); }
						} ),
						el( TextControl, {
							label: __( 'Button Text', 'tejcart' ),
							value: a.button_text,
							onChange: function ( v ) { props.setAttributes( { button_text: v } ); }
						} )
					),
					el( PanelBody, { title: __( 'Display', 'tejcart' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Show Price', 'tejcart' ),
							checked: a.show_price,
							onChange: function ( v ) { props.setAttributes( { show_price: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show Description', 'tejcart' ),
							checked: a.show_description,
							onChange: function ( v ) { props.setAttributes( { show_description: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Minimum Height (px)', 'tejcart' ),
							value: a.min_height,
							min: 200,
							max: 800,
							step: 50,
							onChange: function ( v ) { props.setAttributes( { min_height: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Overlay Opacity (%)', 'tejcart' ),
							value: a.overlay_opacity,
							min: 0,
							max: 100,
							onChange: function ( v ) { props.setAttributes( { overlay_opacity: v } ); }
						} )
					)
				),
				a.product_id && ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/featured-product', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'star-filled' } ),
						label: __( 'TejCart Featured Product', 'tejcart' ),
						instructions: __( 'Enter a Product ID in the block settings.', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/on-sale', {
		title: __( 'TejCart On Sale', 'tejcart' ),
		icon: 'tag',
		category: 'widgets',
		keywords: [ 'sale', 'discount', 'products' ],
		attributes: {
			columns: { type: 'number', default: 4 },
			rows:    { type: 'number', default: 1 },
			orderby: { type: 'string', default: 'date' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'tejcart' ) },
						el( RangeControl, {
							label: __( 'Columns', 'tejcart' ),
							value: a.columns,
							min: 2, max: 4,
							onChange: function ( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Rows', 'tejcart' ),
							value: a.rows,
							min: 1, max: 6,
							onChange: function ( v ) { props.setAttributes( { rows: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Order By', 'tejcart' ),
							value: a.orderby,
							options: [
								{ label: __( 'Date', 'tejcart' ),     value: 'date' },
								{ label: __( 'Price: Low to High', 'tejcart' ), value: 'price_asc' },
								{ label: __( 'Price: High to Low', 'tejcart' ), value: 'price_desc' },
								{ label: __( 'Name', 'tejcart' ),     value: 'name' },
								{ label: __( 'Biggest Discount', 'tejcart' ), value: 'discount' }
							],
							onChange: function ( v ) { props.setAttributes( { orderby: v } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/on-sale', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'tag' } ),
						label: __( 'TejCart On Sale', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/best-sellers', {
		title: __( 'TejCart Best Sellers', 'tejcart' ),
		icon: 'awards',
		category: 'widgets',
		keywords: [ 'best', 'sellers', 'popular', 'products' ],
		attributes: {
			columns: { type: 'number', default: 4 },
			rows:    { type: 'number', default: 1 }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'tejcart' ) },
						el( RangeControl, {
							label: __( 'Columns', 'tejcart' ),
							value: a.columns,
							min: 2, max: 4,
							onChange: function ( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Rows', 'tejcart' ),
							value: a.rows,
							min: 1, max: 6,
							onChange: function ( v ) { props.setAttributes( { rows: v } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/best-sellers', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'awards' } ),
						label: __( 'TejCart Best Sellers', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/top-rated', {
		title: __( 'TejCart Top Rated', 'tejcart' ),
		icon: 'star-half',
		category: 'widgets',
		keywords: [ 'top', 'rated', 'reviews', 'products' ],
		attributes: {
			columns:    { type: 'number', default: 4 },
			rows:       { type: 'number', default: 1 },
			min_rating: { type: 'number', default: 4 }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'tejcart' ) },
						el( RangeControl, {
							label: __( 'Columns', 'tejcart' ),
							value: a.columns,
							min: 2, max: 4,
							onChange: function ( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Rows', 'tejcart' ),
							value: a.rows,
							min: 1, max: 6,
							onChange: function ( v ) { props.setAttributes( { rows: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Minimum Rating', 'tejcart' ),
							value: a.min_rating,
							min: 1, max: 5,
							onChange: function ( v ) { props.setAttributes( { min_rating: v } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/top-rated', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'star-half' } ),
						label: __( 'TejCart Top Rated', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/hand-picked', {
		title: __( 'TejCart Hand-Picked Products', 'tejcart' ),
		icon: 'screenoptions',
		category: 'widgets',
		keywords: [ 'hand', 'picked', 'curated', 'products' ],
		attributes: {
			product_ids: { type: 'string',  default: '' },
			columns:     { type: 'number',  default: 4 },
			orderby:     { type: 'string',  default: 'manual' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Products', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Product IDs (comma-separated)', 'tejcart' ),
							help: __( 'Enter product IDs separated by commas, e.g. 12,45,78', 'tejcart' ),
							value: a.product_ids,
							onChange: function ( v ) { props.setAttributes( { product_ids: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'tejcart' ),
							value: a.columns,
							min: 2, max: 4,
							onChange: function ( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Order By', 'tejcart' ),
							value: a.orderby,
							options: [
								{ label: __( 'Manual (as entered)', 'tejcart' ), value: 'manual' },
								{ label: __( 'Name', 'tejcart' ),               value: 'name' },
								{ label: __( 'Price: Low to High', 'tejcart' ), value: 'price_asc' },
								{ label: __( 'Price: High to Low', 'tejcart' ), value: 'price_desc' }
							],
							onChange: function ( v ) { props.setAttributes( { orderby: v } ); }
						} )
					)
				),
				a.product_ids && ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/hand-picked', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'screenoptions' } ),
						label: __( 'TejCart Hand-Picked Products', 'tejcart' ),
						instructions: __( 'Enter product IDs in the block settings.', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/products-by-category', {
		title: __( 'TejCart Products by Category', 'tejcart' ),
		icon: 'category',
		category: 'widgets',
		keywords: [ 'category', 'products', 'taxonomy' ],
		attributes: {
			category_id: { type: 'number', default: 0 },
			columns:     { type: 'number', default: 4 },
			rows:        { type: 'number', default: 1 },
			orderby:     { type: 'string', default: 'date' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Category', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Category ID', 'tejcart' ),
							help: __( 'Enter the product category term ID.', 'tejcart' ),
							type: 'number',
							value: a.category_id || '',
							onChange: function ( v ) { props.setAttributes( { category_id: parseInt( v, 10 ) || 0 } ); }
						} )
					),
					el( PanelBody, { title: __( 'Layout', 'tejcart' ), initialOpen: false },
						el( RangeControl, {
							label: __( 'Columns', 'tejcart' ),
							value: a.columns,
							min: 2, max: 4,
							onChange: function ( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Rows', 'tejcart' ),
							value: a.rows,
							min: 1, max: 6,
							onChange: function ( v ) { props.setAttributes( { rows: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Order By', 'tejcart' ),
							value: a.orderby,
							options: [
								{ label: __( 'Date', 'tejcart' ),     value: 'date' },
								{ label: __( 'Name', 'tejcart' ),     value: 'name' },
								{ label: __( 'Price: Low to High', 'tejcart' ), value: 'price_asc' },
								{ label: __( 'Price: High to Low', 'tejcart' ), value: 'price_desc' },
								{ label: __( 'Best Selling', 'tejcart' ),       value: 'sales' }
							],
							onChange: function ( v ) { props.setAttributes( { orderby: v } ); }
						} )
					)
				),
				a.category_id && ServerSideRender
					? el( ServerSideRender, { block: 'tejcart/products-by-category', attributes: a } )
					: el( Placeholder, {
						icon: el( Dashicon, { icon: 'category' } ),
						label: __( 'TejCart Products by Category', 'tejcart' ),
						instructions: __( 'Enter a Category ID in the block settings.', 'tejcart' )
					} )
			);
		},
		save: function () { return null; }
	} );

	/* ── Filter Blocks ── */

	registerBlockType( 'tejcart/filter-by-price', {
		title: __( 'TejCart Filter: Price', 'tejcart' ),
		icon: 'money-alt',
		category: 'widgets',
		keywords: [ __( 'price', 'tejcart' ), __( 'filter', 'tejcart' ), __( 'range', 'tejcart' ) ],
		attributes: {
			heading:       { type: 'string',  default: '' },
			showHistogram: { type: 'boolean', default: true },
			showInputs:    { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Heading', 'tejcart' ),
							value: props.attributes.heading,
							onChange: function ( val ) { props.setAttributes( { heading: val } ); },
							help: __( 'Leave blank for default ("Price").', 'tejcart' )
						} ),
						el( ToggleControl, {
							label: __( 'Show Histogram', 'tejcart' ),
							checked: props.attributes.showHistogram,
							onChange: function ( val ) { props.setAttributes( { showHistogram: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show Number Inputs', 'tejcart' ),
							checked: props.attributes.showInputs,
							onChange: function ( val ) { props.setAttributes( { showInputs: val } ); }
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'money-alt' } ),
					label: __( 'TejCart Filter: Price', 'tejcart' ),
					instructions: __( 'Displays a price range slider with histogram.', 'tejcart' )
				} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/filter-by-attribute', {
		title: __( 'TejCart Filter: Attribute', 'tejcart' ),
		icon: 'tag',
		category: 'widgets',
		keywords: [ __( 'attribute', 'tejcart' ), __( 'filter', 'tejcart' ), __( 'color', 'tejcart' ), __( 'size', 'tejcart' ) ],
		attributes: {
			heading:       { type: 'string',  default: '' },
			attributeName: { type: 'string',  default: '' },
			showCounts:    { type: 'boolean', default: true },
			displayStyle:  { type: 'string',  default: 'list' }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Heading', 'tejcart' ),
							value: props.attributes.heading,
							onChange: function ( val ) { props.setAttributes( { heading: val } ); },
							help: __( 'Leave blank to use the attribute name.', 'tejcart' )
						} ),
						el( TextControl, {
							label: __( 'Attribute Slug', 'tejcart' ),
							value: props.attributes.attributeName,
							onChange: function ( val ) { props.setAttributes( { attributeName: val } ); },
							help: __( 'e.g. "color", "size". Leave blank to show all attributes.', 'tejcart' )
						} ),
						el( SelectControl, {
							label: __( 'Display Style', 'tejcart' ),
							value: props.attributes.displayStyle,
							options: [
								{ label: __( 'List', 'tejcart' ),   value: 'list' },
								{ label: __( 'Inline', 'tejcart' ), value: 'inline' }
							],
							onChange: function ( val ) { props.setAttributes( { displayStyle: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show Counts', 'tejcart' ),
							checked: props.attributes.showCounts,
							onChange: function ( val ) { props.setAttributes( { showCounts: val } ); }
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'tag' } ),
					label: __( 'TejCart Filter: Attribute', 'tejcart' ),
					instructions: props.attributes.attributeName
						? __( 'Attribute: ', 'tejcart' ) + props.attributes.attributeName
						: __( 'Displays checkboxes for product attributes (color, size, etc.).', 'tejcart' )
				} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/filter-by-rating', {
		title: __( 'TejCart Filter: Rating', 'tejcart' ),
		icon: 'star-filled',
		category: 'widgets',
		keywords: [ __( 'rating', 'tejcart' ), __( 'filter', 'tejcart' ), __( 'stars', 'tejcart' ), __( 'review', 'tejcart' ) ],
		attributes: {
			heading:    { type: 'string',  default: '' },
			showCounts: { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Heading', 'tejcart' ),
							value: props.attributes.heading,
							onChange: function ( val ) { props.setAttributes( { heading: val } ); },
							help: __( 'Leave blank for default ("Rating").', 'tejcart' )
						} ),
						el( ToggleControl, {
							label: __( 'Show Counts', 'tejcart' ),
							checked: props.attributes.showCounts,
							onChange: function ( val ) { props.setAttributes( { showCounts: val } ); }
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'star-filled' } ),
					label: __( 'TejCart Filter: Rating', 'tejcart' ),
					instructions: __( 'Displays star-rating filter (4+, 3+, etc.).', 'tejcart' )
				} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'tejcart/filter-by-stock', {
		title: __( 'TejCart Filter: Stock', 'tejcart' ),
		icon: 'visibility',
		category: 'widgets',
		keywords: [ __( 'stock', 'tejcart' ), __( 'filter', 'tejcart' ), __( 'availability', 'tejcart' ) ],
		attributes: {
			heading:    { type: 'string',  default: '' },
			showCounts: { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			return el( 'div', {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Settings', 'tejcart' ) },
						el( TextControl, {
							label: __( 'Heading', 'tejcart' ),
							value: props.attributes.heading,
							onChange: function ( val ) { props.setAttributes( { heading: val } ); },
							help: __( 'Leave blank for default ("Availability").', 'tejcart' )
						} ),
						el( ToggleControl, {
							label: __( 'Show Counts', 'tejcart' ),
							checked: props.attributes.showCounts,
							onChange: function ( val ) { props.setAttributes( { showCounts: val } ); }
						} )
					)
				),
				el( Placeholder, {
					icon: el( Dashicon, { icon: 'visibility' } ),
					label: __( 'TejCart Filter: Stock', 'tejcart' ),
					instructions: __( 'Displays an in-stock / out-of-stock toggle.', 'tejcart' )
				} )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
