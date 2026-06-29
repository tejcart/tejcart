<?php
/**
 * TejCart Global Helper Functions
 *
 * These functions are loaded directly by the main plugin file and are available
 * globally without any namespace.
 *
 * @package TejCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the main TejCart singleton instance.
 *
 * @since 1.0.0
 * @return \TejCart\Core\TejCart
 */
function tejcart() {
	return \TejCart\Core\TejCart::instance();
}

/**
 * Retrieve the current cart instance.
 *
 * @since 1.0.0
 * @return \TejCart\Cart\Cart
 */
function tejcart_get_cart() {
	return tejcart()->cart();
}

/**
 * Retrieve an order by its ID.
 *
 * @since 1.0.0
 * @param int $id Order ID.
 * @return \TejCart\Order\Order
 */
function tejcart_get_order( int $id ) {
	return new \TejCart\Order\Order( $id );
}

/**
 * Retrieve a product by its ID (simple or variable via factory).
 *
 * @since 1.0.0
 * @param int $id Product (post) ID.
 * @return \TejCart\Product\Product_Types\Abstract_Product|null
 */
function tejcart_get_product( int $id ) {
	return \TejCart\Product\Product_Factory::get_product( $id );
}

/**
 * Check whether the current user can perform a TejCart action.
 *
 * Thin alias for Capabilities::check() so callers don't need to import
 * the class. Accepts either a TejCart cap constant (EDIT_PRODUCTS,
 * DELETE_PRODUCTS, ...) or any string `current_user_can()` accepts.
 *
 * @since 1.0.0
 * @param string $cap     Capability name.
 * @param mixed  ...$args Extra arguments forwarded to current_user_can.
 * @return bool
 */
function tejcart_can( string $cap, ...$args ): bool {
	return \TejCart\Core\Capabilities::check( $cap, ...$args );
}

/**
 * Render a card-style flash notice on a TejCart admin page.
 *
 * Thin wrapper around \TejCart\Admin\Flash_Notice::render() — meant to
 * be called from the same scope where the legacy
 * `<div class="notice notice-success is-dismissible"><p>…</p></div>`
 * markup used to be emitted. Replaces all four WP notice tones with the
 * on-brand card-style banner (filled tone-coloured icon medallion, bold
 * title plus an optional secondary detail line, dismiss button, slide-in
 * animation that respects prefers-reduced-motion).
 *
 *   tejcart_admin_flash( 'Settings saved.' );
 *   tejcart_admin_flash( 'Refund failed.', 'Check the gateway log.', 'error' );
 *
 * @since 1.0.0
 * @param string $title       Primary message line. Plain text, escaped on output.
 * @param string $detail      Optional secondary detail line. Plain text, escaped on output.
 * @param string $tone        One of: success | error | warning | info. Defaults to success.
 * @param bool   $dismissible Whether to render the dismiss X button. Defaults to true.
 */
function tejcart_admin_flash( string $title, string $detail = '', string $tone = 'success', bool $dismissible = true ): void {
	\TejCart\Admin\Flash_Notice::render( $title, $detail, $tone, $dismissible );
}

/**
 * Locate and include a template file with override support.
 *
 * Template override priority:
 *  1. child-theme/tejcart/{template}
 *  2. parent-theme/tejcart/{template}
 *  3. Custom path via `tejcart_template_paths` filter
 *  4. tejcart/templates/{template}  (plugin default)
 *
 * @since 1.0.0
 * @param string $template_name Relative template path (e.g. "cart/cart.php").
 * @param array  $args          Variables to extract into the template scope.
 */
function tejcart_get_template( string $template_name, array $args = [] ) {
	$template_name = str_replace( "\0", '', $template_name );
	$template_name = (string) preg_replace( '#\.{2,}#', '', $template_name );
	$template_name = ltrim( $template_name, '/' );

	if ( empty( $template_name ) ) {
		return;
	}

	$located = '';

	$child_theme = get_stylesheet_directory() . '/tejcart/' . $template_name;
	if ( file_exists( $child_theme ) ) {
		$located = $child_theme;
	}

	if ( ! $located ) {
		$parent_theme = get_template_directory() . '/tejcart/' . $template_name;
		if ( file_exists( $parent_theme ) ) {
			$located = $parent_theme;
		}
	}

	if ( ! $located ) {
		$custom_paths = apply_filters( 'tejcart_template_paths', [], $template_name );
		foreach ( $custom_paths as $path ) {
			if ( file_exists( $path ) ) {
				$located = $path;
				break;
			}
		}
	}

	if ( ! $located ) {
		$located = TEJCART_TEMPLATE_DIR . $template_name;
	}

	/**
	 * Filter the resolved template path before inclusion.
	 *
	 * @since 1.0.0
	 * @param string $located       Absolute path to the located template.
	 * @param string $template_name Relative template name originally requested.
	 * @param array  $args          Template arguments.
	 */
	$located = apply_filters( 'tejcart_locate_template', $located, $template_name, $args );

	if ( $located && is_file( $located ) ) {
		if ( ! empty( $args ) && is_array( $args ) ) {
			foreach ( $args as $tejcart_tpl_key => $tejcart_tpl_value ) {
				if ( is_string( $tejcart_tpl_key ) && preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tejcart_tpl_key ) ) {
					${$tejcart_tpl_key} = $tejcart_tpl_value;
				}
			}
			unset( $tejcart_tpl_key, $tejcart_tpl_value );
		}
		include $located;
	}
}

/**
 * Retrieve the active store currency code (ISO 4217).
 *
 * The Multi-Currency companion plugin (and any geo / cookie based switcher)
 * should hook the `tejcart_currency` filter to override this per-request.
 *
 * @since 1.0.0
 * @return string Three-letter ISO 4217 currency code.
 */
function tejcart_get_currency(): string {
	$currency = (string) get_option( 'tejcart_currency', 'USD' );

	/**
	 * Filter the active store currency code.
	 *
	 * @since 1.0.0
	 * @param string $currency Three-letter ISO 4217 currency code.
	 */
	return apply_filters( 'tejcart_currency', $currency );
}

/**
 * Translate an internal order-status slug to its human-readable label.
 *
 * Centralises the slug → translatable label map that previously only
 * lived in `Admin\Orders_Table::column_status()`, so customer-facing
 * templates (account/orders.php, account/dashboard.php,
 * account/view-order.php) can render the same labels via `__()`
 * instead of `ucfirst()`. Falls back to a title-cased version of the
 * slug for unknown statuses so addon-defined states still render
 * sensibly. The fallback is intentionally NOT wrapped in `_()` — the
 * slug isn't a known translation key and would yield an unhelpful
 * "missing translation" warning in poedit.
 *
 * @since 1.0.0
 * @param string $status Internal status slug (e.g. `processing`, `on-hold`).
 * @return string Translated, capitalised label suitable for display.
 */
function tejcart_get_order_status_label( string $status ): string {
	$labels = array(
		'pending'    => __( 'Pending payment', 'tejcart' ),
		'processing' => __( 'Processing', 'tejcart' ),
		'on-hold'    => __( 'On hold', 'tejcart' ),
		'completed'  => __( 'Completed', 'tejcart' ),
		'cancelled'  => __( 'Cancelled', 'tejcart' ),
		'refunded'   => __( 'Refunded', 'tejcart' ),
		'failed'     => __( 'Failed', 'tejcart' ),
	);

	/**
	 * Filter the map of order-status slugs to display labels.
	 *
	 * Sibling plugins that add a new order status (e.g. `awaiting-stock`)
	 * use this filter to register a translatable label without forking
	 * the helper. Returning a non-array short-circuits to the default
	 * map.
	 *
	 * @since 1.0.0
	 * @param array<string,string> $labels Slug → translated label.
	 */
	$labels = apply_filters( 'tejcart_order_status_labels', $labels );
	if ( ! is_array( $labels ) ) {
		$labels = array();
	}

	return $labels[ $status ] ?? ucfirst( str_replace( '-', ' ', $status ) );
}

/**
 * Resolve the store's customer-facing contact email.
 *
 * Falls back through `tejcart_store_email` → `tejcart_from_email` →
 * `admin_email` so templates and email footers always have a sensible
 * address to render, even on stores that never ran the setup wizard.
 *
 * @since 1.0.0
 * @return string Sanitised email address, or '' when none is configured.
 */
function tejcart_get_store_email(): string {
	$candidates = array(
		(string) get_option( 'tejcart_store_email', '' ),
		(string) get_option( 'tejcart_from_email', '' ),
		(string) get_option( 'admin_email', '' ),
	);

	foreach ( $candidates as $email ) {
		$email = sanitize_email( $email );
		if ( '' !== $email ) {
			/** This filter documented immediately below. */
			return (string) apply_filters( 'tejcart_store_email', $email );
		}
	}

	/**
	 * Filter the store's customer-facing contact email.
	 *
	 * @since 1.0.0
	 * @param string $email Resolved email address (may be empty).
	 */
	return (string) apply_filters( 'tejcart_store_email', '' );
}

/**
 * Build a documentation URL on docs.tejcart.com for the given slug.
 *
 * Used by admin notices, settings hints, and onboarding screens so
 * non-technical merchants can click straight from the warning to a
 * step-by-step guide. Hand back the URL — never the rendered anchor —
 * so callers stay in control of placement, label, and surrounding copy.
 *
 * Filter the base host via `tejcart_doc_base_url` to point at a mirror
 * (e.g. for white-labelled distributions), or filter the resolved URL
 * per-slug via `tejcart_doc_url`.
 *
 * @since 1.0.0
 * @param string $slug Path under docs.tejcart.com (with or without
 *                     leading/trailing slash). Empty resolves to the
 *                     docs root.
 * @return string Absolute URL.
 */
function tejcart_doc_url( string $slug = '' ): string {
	$base = (string) apply_filters( 'tejcart_doc_base_url', 'https://docs.tejcart.com' );
	$base = rtrim( $base, '/' );
	$slug = trim( $slug, "/ \t\n\r\0\x0B" );

	$url = '' === $slug ? $base . '/' : $base . '/' . $slug . '/';

	/**
	 * Filter the resolved documentation URL.
	 *
	 * @since 1.0.0
	 * @param string $url  Absolute documentation URL.
	 * @param string $slug Slug originally requested.
	 */
	return (string) apply_filters( 'tejcart_doc_url', $url, $slug );
}

/**
 * Render a "Learn more" anchor to a docs.tejcart.com page.
 *
 * Output is fully escaped HTML, safe to echo directly inside an
 * admin notice. Always opens in a new tab so the merchant doesn't
 * lose their place in wp-admin, with the screen-reader cue WordPress
 * conventionally pairs with `target="_blank"`.
 *
 * @since 1.0.0
 * @param string      $slug  Docs slug (see {@see tejcart_doc_url()}).
 * @param string|null $label Visible link text. Defaults to "Learn more".
 * @return string Escaped HTML anchor.
 */
function tejcart_doc_link( string $slug, ?string $label = null ): string {
	$label = null === $label ? __( 'Learn more', 'tejcart' ) : $label;
	$url   = tejcart_doc_url( $slug );

	return sprintf(
		'<a class="tejcart-doc-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span><span aria-hidden="true">&nbsp;&#x2197;</span></a>',
		esc_url( $url ),
		esc_html( $label ),
		esc_html__( '(opens in a new tab)', 'tejcart' )
	);
}

/**
 * Resolve the store's customer support email.
 *
 * Falls back through `tejcart_support_email` → {@see tejcart_get_store_email()}
 * so order emails and the My Account page can show a "need help?" address
 * without merchants having to configure every option.
 *
 * @since 1.0.0
 * @return string Sanitised email address, or '' when none is configured.
 */
function tejcart_get_support_email(): string {
	$support = sanitize_email( (string) get_option( 'tejcart_support_email', '' ) );
	if ( '' === $support ) {
		$support = tejcart_get_store_email();
	}

	/**
	 * Filter the customer support email.
	 *
	 * @since 1.0.0
	 * @param string $support Resolved support address.
	 */
	return (string) apply_filters( 'tejcart_support_email', $support );
}

/**
 * Retrieve the currency symbol for a given ISO 4217 currency code.
 *
 * @since 1.0.0
 * @param string $currency ISO 4217 currency code. Defaults to store currency.
 * @return string Currency symbol (e.g. "$", "€").
 */
function tejcart_get_currency_symbol( string $currency = '' ): string {
	if ( ! $currency ) {
		$currency = tejcart_get_currency();
	}

	$symbol = \TejCart\Money\Currencies::get_symbol( $currency );

	/**
	 * Filter the currency symbol.
	 *
	 * @since 1.0.0
	 * @param string $symbol   The currency symbol.
	 * @param string $currency The ISO 4217 currency code.
	 */
	return apply_filters( 'tejcart_currency_symbol', $symbol, $currency );
}

/**
 * Format a monetary amount for display.
 *
 * Accepts either a legacy `float` amount in major units or a Money
 * value object (#1220). When a Money is passed, the currency
 * parameter is ignored — the Money's own currency wins, and the
 * decimal projection comes from the integer minor units so the
 * display value is integer-accurate without float drift.
 *
 * @since 1.0.0
 * @param float|int|\TejCart\Money\Money $amount   Raw numeric amount in major units, OR a Money value object.
 * @param string                          $currency ISO 4217 currency code. Defaults to store currency. Ignored when `$amount` is Money.
 * @return string Formatted price string (e.g. "$12.99").
 */
function tejcart_price( $amount, string $currency = '' ): string {
	if ( $amount instanceof \TejCart\Money\Money ) {
		$currency = $amount->currency();
		$amount   = (float) $amount->as_decimal_string();
	} else {
		$amount = (float) $amount;
	}

	if ( ! $currency ) {
		$currency = tejcart_get_currency();
	}

	/**
	 * Filter the raw amount before formatting.
	 *
	 * Multi-currency plugins hook here to convert the base-currency amount
	 * into the active display currency. Return a float (major units) or a
	 * {@see \TejCart\Money\Money} value object. Do NOT return a Money object
	 * for a different currency than the current $currency argument — doing so
	 * will render with the wrong symbol. Numeric strings are accepted.
	 *
	 * @since 1.0.0
	 * @param float  $amount   Raw numeric amount in store base currency.
	 * @param string $currency Display currency code.
	 */
	// F-CORE-010: handle a Money object returned by the filter so the
	// Money-safe path is not silently discarded by a blind (float) cast.
	$filtered = apply_filters( 'tejcart_price_amount', $amount, $currency );
	if ( $filtered instanceof \TejCart\Money\Money ) {
		$currency = $filtered->currency();
		$amount   = (float) $filtered->as_decimal_string();
	} else {
		$amount = (float) $filtered;
	}
	unset( $filtered );

	$symbol   = tejcart_get_currency_symbol( $currency );
	$decimals = (int) get_option( 'tejcart_num_decimals', 2 );
	$dec_sep  = (string) get_option( 'tejcart_decimal_separator', '' );
	$thou_sep = (string) get_option( 'tejcart_thousand_separator', '' );
	$position = get_option( 'tejcart_currency_position', 'left' );

	// Audit 09 F-020 — when the merchant hasn't set explicit
	// separators, fall back to the active locale's defaults rather
	// than hardcoded `.` / `,`. A German store with empty separators
	// previously rendered USD-style.
	if ( '' === $dec_sep || '' === $thou_sep ) {
		$wp_locale = function_exists( 'is_object' ) && isset( $GLOBALS['wp_locale'] ) ? $GLOBALS['wp_locale'] : null;
		if ( is_object( $wp_locale ) && isset( $wp_locale->number_format ) && is_array( $wp_locale->number_format ) ) {
			if ( '' === $dec_sep ) {
				$dec_sep = isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
			}
			if ( '' === $thou_sep ) {
				$thou_sep = isset( $wp_locale->number_format['thousands_sep'] ) ? (string) $wp_locale->number_format['thousands_sep'] : ',';
			}
		} else {
			if ( '' === $dec_sep ) {
				$dec_sep = '.';
			}
			if ( '' === $thou_sep ) {
				$thou_sep = ',';
			}
		}
	}

	$formatted = number_format( $amount, $decimals, $dec_sep, $thou_sep );

	$price = match ( $position ) {
		'left'        => $symbol . $formatted,
		'right'       => $formatted . $symbol,
		'left_space'  => $symbol . ' ' . $formatted,
		'right_space' => $formatted . ' ' . $symbol,
		default       => $symbol . $formatted,
	};

	/**
	 * Filter the final formatted price string.
	 *
	 * @since 1.0.0
	 * @param string $price    Formatted price with currency symbol.
	 * @param float  $amount   Raw numeric amount.
	 * @param string $currency ISO 4217 currency code.
	 */
	return apply_filters( 'tejcart_price_format', $price, $amount, $currency );
}

/**
 * Gold-grade edge helper: format a raw minor-unit integer as a price.
 *
 * Use this everywhere a money column is read straight from `$wpdb` —
 * order totals, refund amounts, customer LTV aggregates, line-item
 * subtotals, gift-card balances, and so on. All those columns store
 * **integer minor units** (cents) and {@see tejcart_price()} expects
 * **major units** (dollars). Passing raw cents to `tejcart_price()`
 * renders 100× too large for two-decimal currencies, 1000× for
 * three-decimal currencies (KWD / BHD / OMR), and unchanged for
 * zero-decimal currencies (JPY / KRW) — a hidden currency-dependent
 * bug. This helper closes that gap by routing through
 * {@see \TejCart\Money\Currency::from_minor_units()} which uses the
 * ISO 4217 subunit count for the supplied code.
 *
 * Pattern at the boundary:
 *
 *     $row = $wpdb->get_row( "SELECT total, currency FROM ..." );
 *     echo esc_html(
 *         tejcart_price_from_minor_units( (int) $row->total, $row->currency )
 *     );
 *
 * Prefer this over hand-rolled `$cents / 100` arithmetic — it's the
 * single sanctioned edge between the cents-everywhere arithmetic
 * domain and the major-unit display domain.
 *
 * @since 1.0.0
 * @param int    $minor    Integer minor units (e.g. 3180 for $31.80 USD).
 * @param string $currency ISO 4217 currency code. Defaults to the store's currency.
 * @return string Formatted price string (e.g. "$31.80").
 */
function tejcart_price_from_minor_units( int $minor, string $currency = '' ): string {
	if ( '' === $currency ) {
		$currency = tejcart_get_currency();
	}
	$major = \TejCart\Money\Currency::from_minor_units( $minor, $currency );
	return tejcart_price( $major, $currency );
}

/**
 * Read the itemised cart-fee rows (gift wrap, handling, …) stamped on an
 * order at checkout via the `_tejcart_fees` meta.
 *
 * Cart-level fees are folded into the order `total` column (there is no
 * dedicated fees column), so order-side surfaces — thank-you page, admin
 * order screen, transactional emails — read them back here to render an
 * itemised line so the displayed total reconciles. Each row's `amount` is
 * in minor units of the ORDER's currency (already converted at checkout).
 *
 * @since 1.0.0
 * @param object $order Order instance exposing get_meta().
 * @return array<int, array{id:string,label:string,amount:int,taxable:bool}>
 */
function tejcart_get_order_fee_lines( $order ): array {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return array();
	}
	$json = (string) $order->get_meta( '_tejcart_fees' );
	if ( '' === $json ) {
		return array();
	}
	$decoded = json_decode( $json, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	$rows = array();
	foreach ( $decoded as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$amount = isset( $row['amount'] ) ? (int) $row['amount'] : 0;
		if ( $amount <= 0 ) {
			continue;
		}
		$rows[] = array(
			'id'      => isset( $row['id'] ) ? (string) $row['id'] : '',
			'label'   => ( isset( $row['label'] ) && '' !== (string) $row['label'] )
				? (string) $row['label']
				: __( 'Fee', 'tejcart' ),
			'amount'  => $amount,
			'taxable' => ! empty( $row['taxable'] ),
		);
	}
	return $rows;
}

/**
 * Build a screen-reader-friendly label for a price cell.
 *
 * When a product is on sale, we want assistive tech to read "Sale price:
 * $75, was $100" rather than fighting the struck-through regular price.
 * When not on sale we just return the plain amount in words.
 *
 * The return value is intended for use in an `aria-label` attribute, so
 * HTML in the rendered price is stripped.
 *
 * @since 1.0.0
 *
 * @param float      $sale    Current sale price (or the sole price when $regular is null).
 * @param float|null $regular Original / pre-sale price. Null when not on sale.
 * @return string Plain-text label, safe for aria-label.
 */
function tejcart_format_price_aria( float $sale, ?float $regular = null ): string {
	$sale_label = trim( wp_strip_all_tags( tejcart_price( $sale ) ) );

	if ( null === $regular || $regular <= $sale ) {
		/**
		 * Filter the aria-label for a non-sale price.
		 *
		 * @param string $label Rendered label ("$75").
		 * @param float  $amount Raw price.
		 */
		return (string) apply_filters( 'tejcart_format_price_aria', $sale_label, $sale, null );
	}

	$regular_label = trim( wp_strip_all_tags( tejcart_price( $regular ) ) );
	$label         = sprintf(
		/* translators: 1: sale price, 2: original/regular price */
		__( 'Sale price: %1$s, was %2$s', 'tejcart' ),
		$sale_label,
		$regular_label
	);

	/**
	 * Filter the aria-label for a sale price.
	 *
	 * @param string $label   Rendered label ("Sale price: $75, was $100").
	 * @param float  $sale    Sale amount.
	 * @param float  $regular Original amount.
	 */
	return (string) apply_filters( 'tejcart_format_price_aria', $label, $sale, $regular );
}

/**
 * Return the configured price-display suffix with placeholders resolved.
 *
 * Supports the same two standard placeholders:
 *  - {price_including_tax}
 *  - {price_excluding_tax}
 *
 * Returns an empty string when no suffix is configured or when neither
 * variant is passed and no placeholders appear in the template.
 *
 * @since 1.0.0
 *
 * @param float|null $price_incl  Price including tax (raw amount), or null.
 * @param float|null $price_excl  Price excluding tax (raw amount), or null.
 * @return string Rendered suffix fragment, HTML-safe.
 */
function tejcart_get_price_display_suffix( ?float $price_incl = null, ?float $price_excl = null ): string {
	$template = (string) get_option( 'tejcart_price_display_suffix', '' );

	if ( '' === trim( $template ) ) {
		return '';
	}

	$replacements = array();

	if ( false !== strpos( $template, '{price_including_tax}' ) ) {
		$replacements['{price_including_tax}'] = null !== $price_incl ? tejcart_price( (float) $price_incl ) : '';
	}

	if ( false !== strpos( $template, '{price_excluding_tax}' ) ) {
		$replacements['{price_excluding_tax}'] = null !== $price_excl ? tejcart_price( (float) $price_excl ) : '';
	}

	$rendered = strtr( $template, $replacements );

	/**
	 * Filter the rendered price-display suffix.
	 *
	 * @since 1.0.0
	 * @param string     $rendered    Resolved suffix text.
	 * @param string     $template    Raw template from settings.
	 * @param float|null $price_incl  Supplied tax-inclusive price.
	 * @param float|null $price_excl  Supplied tax-exclusive price.
	 */
	return (string) apply_filters( 'tejcart_price_display_suffix', $rendered, $template, $price_incl, $price_excl );
}

/**
 * Format a monetary amount with the configured price-display suffix appended.
 *
 * Themes and templates should prefer this helper whenever the store may
 * be configured with a suffix like "incl. VAT" so the suffix is shown
 * consistently across product, cart, and checkout.
 *
 * @since 1.0.0
 *
 * @param float      $amount      Raw numeric amount.
 * @param float|null $price_incl  Matching tax-inclusive amount when different from $amount.
 * @param float|null $price_excl  Matching tax-exclusive amount when different from $amount.
 * @return string
 */
function tejcart_price_with_suffix( float $amount, ?float $price_incl = null, ?float $price_excl = null ): string {
	$price  = tejcart_price( $amount );
	$suffix = tejcart_get_price_display_suffix( $price_incl, $price_excl );

	if ( '' === $suffix ) {
		return $price;
	}

	return $price . ' <small class="tejcart-price-suffix">' . $suffix . '</small>';
}

/**
 * Format a product weight with the store's configured weight unit.
 *
 * Consumers (single-product templates, shipping labels, invoice extras,
 * Schema.org markup) should call this rather than echoing the raw number
 * so the "kg" / "lb" / "g" / "oz" unit stays consistent across surfaces.
 *
 * @since 1.0.0
 *
 * @param string|float $weight Raw weight value.
 * @return string Formatted "12.5 kg" — empty string if weight is blank.
 */
function tejcart_format_weight( $weight ): string {
	if ( '' === $weight || null === $weight ) {
		return '';
	}
	$unit = (string) get_option( 'tejcart_weight_unit', 'kg' );
	if ( '' === $unit ) {
		return trim( (string) $weight );
	}
	// Locale-aware ordering (audit 09 F-019). RTL languages may need
	// to swap value/unit; the placeholder pattern lets translators do
	// that without forking the template.
	return sprintf(
		/* translators: 1: numeric value, 2: unit (kg/lb/g/oz). */
		_x( '%1$s %2$s', 'weight value, unit', 'tejcart' ),
		(string) $weight,
		$unit
	);
}

/**
 * Format product dimensions as "L × W × H unit".
 *
 * Accepts any array that exposes length / width / height keys — matches
 * the shape Abstract_Product::get_dimensions() returns. Missing or empty
 * dimensions are elided so a product with only length + width does not
 * render "2 × 3 ×  cm".
 *
 * @since 1.0.0
 *
 * @param array{length?:string|float,width?:string|float,height?:string|float}|mixed $dimensions
 * @return string Formatted "10 × 5 × 2 cm" — empty string if all dims blank.
 */
function tejcart_format_dimensions( $dimensions ): string {
	if ( ! is_array( $dimensions ) ) {
		return '';
	}
	$parts = array();
	foreach ( array( 'length', 'width', 'height' ) as $key ) {
		$val = isset( $dimensions[ $key ] ) ? trim( (string) $dimensions[ $key ] ) : '';
		if ( '' !== $val && '0' !== $val ) {
			$parts[] = $val;
		}
	}
	if ( empty( $parts ) ) {
		return '';
	}
	$unit = (string) get_option( 'tejcart_dimension_unit', 'cm' );
	$value = implode( ' × ', $parts );
	if ( '' === $unit ) {
		return $value;
	}
	// Locale-aware ordering (audit 09 F-019).
	return sprintf(
		/* translators: 1: "L × W × H" tuple, 2: unit (cm/in/m/mm). */
		_x( '%1$s %2$s', 'dimension value, unit', 'tejcart' ),
		$value,
		$unit
	);
}

/**
 * Render a product price with the configured price-display suffix.
 *
 * This is the canonical entry point for every catalog surface — shop
 * listing, single product page, cart drawer, cart page, checkout line
 * items. It resolves which side of the {price_including_tax} /
 * {price_excluding_tax} placeholder pair to fill from the raw product
 * amount based on the `tejcart_prices_include_tax` setting, so merchants
 * who configure a suffix like "incl. VAT ({price_excluding_tax} ex.)"
 * get the expected rendering without every template needing to know
 * how prices are stored.
 *
 * @since 1.0.0
 *
 * @param float $amount Raw product price as stored (matches the
 *                      `prices_include_tax` configuration).
 * @return string
 */
function tejcart_product_price_html( float $amount, string $context = 'shop' ): string {
	$prices_include_tax = 'yes' === get_option( 'tejcart_prices_include_tax', 'no' );

	$price_incl = $prices_include_tax ? $amount : null;
	$price_excl = $prices_include_tax ? null   : $amount;

	$tax_rate_pct = null;
	if ( class_exists( '\\TejCart\\Tax\\Tax_Manager' ) ) {
		$tm = new \TejCart\Tax\Tax_Manager();
		if ( method_exists( $tm, 'is_tax_enabled' ) && $tm->is_tax_enabled() ) {
			$country = (string) get_option( 'tejcart_store_country', 'US' );
			$state   = (string) get_option( 'tejcart_store_state', '' );
			$rate    = method_exists( $tm, 'get_tax_rate' ) ? $tm->get_tax_rate( $country, $state ) : null;
			if ( is_array( $rate ) && isset( $rate['rate'] ) ) {
				$tax_rate_pct = (float) $rate['rate'];
			} elseif ( is_numeric( $rate ) ) {
				$tax_rate_pct = (float) $rate;
			}
		}
	}

	if ( null !== $tax_rate_pct && $tax_rate_pct > 0 ) {
		// F-CORE-009: avoid float division drift by working in integer minor
		// units. Convert the major-unit display price to minor units, apply
		// the tax direction using Money::strip_inclusive_tax() /
		// Money::inclusive_tax_portion() (banker's-rounded integer arithmetic),
		// then convert back to major units for the display layer.
		// Tax rate in basis points (e.g. 20% → 2000).
		$currency     = function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : 'USD';
		$rate_bp      = (int) round( $tax_rate_pct * 100 );
		$money_gross  = \TejCart\Money\Money::from_decimal_string( number_format( $amount, \TejCart\Money\Currency::decimals( $currency ), '.', '' ), $currency );
		if ( $prices_include_tax ) {
			// Stored price includes tax; compute the exclusive (net) price.
			$money_excl  = $money_gross->strip_inclusive_tax( $rate_bp );
			$price_excl  = \TejCart\Money\Currency::from_minor_units( $money_excl->as_minor_units(), $currency );
		} else {
			// Stored price excludes tax; compute the inclusive (gross) price.
			$tax_portion = $money_gross->inclusive_tax_portion( $rate_bp );
			$money_incl  = $money_gross->add( $tax_portion );
			$price_incl  = \TejCart\Money\Currency::from_minor_units( $money_incl->as_minor_units(), $currency );
		}
	}

	$args = apply_filters(
		'tejcart_product_price_html_args',
		array(
			'incl'    => $price_incl,
			'excl'    => $price_excl,
			'context' => $context,
			'amount'  => $amount,
		),
		$amount,
		$context
	);

	$display_mode = 'cart' === $context
		? (string) get_option( 'tejcart_tax_display_cart', 'exclusive' )
		: (string) get_option( 'tejcart_tax_display_shop', 'exclusive' );

	$headline = $amount;
	if ( 'inclusive' === $display_mode && isset( $args['incl'] ) && null !== $args['incl'] ) {
		$headline = (float) $args['incl'];
	} elseif ( 'exclusive' === $display_mode && isset( $args['excl'] ) && null !== $args['excl'] ) {
		$headline = (float) $args['excl'];
	}

	return tejcart_price_with_suffix(
		$headline,
		isset( $args['incl'] ) ? ( null === $args['incl'] ? null : (float) $args['incl'] ) : null,
		isset( $args['excl'] ) ? ( null === $args['excl'] ? null : (float) $args['excl'] ) : null
	);
}

/**
 * Allowed-HTML tag/attribute allowlist for inline payment-method
 * icon SVGs rendered in the checkout payment gateway cards. The set
 * is deliberately tight — no scripting, no event handlers, just the
 * geometry + accessibility primitives needed to paint a brand mark.
 *
 * @since 1.0.0
 * @return array<string,array<string,bool>> wp_kses-style schema.
 */
function tejcart_payment_icon_allowed_html(): array {
	$attrs_common = array(
		'xmlns'        => true,
		'viewbox'      => true,
		'width'        => true,
		'height'       => true,
		'fill'         => true,
		'stroke'       => true,
		'stroke-width' => true,
		'role'         => true,
		'aria-label'   => true,
		'focusable'    => true,
		'class'        => true,
	);

	return array(
		'svg'    => $attrs_common,
		'g'      => $attrs_common,
		'path'   => array_merge( $attrs_common, array( 'd' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ) ),
		'rect'   => array_merge( $attrs_common, array( 'x' => true, 'y' => true, 'rx' => true, 'ry' => true ) ),
		'circle' => array_merge( $attrs_common, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
		'ellipse'=> array_merge( $attrs_common, array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ) ),
		'line'   => array_merge( $attrs_common, array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ) ),
		'polygon' => array_merge( $attrs_common, array( 'points' => true ) ),
		'polyline' => array_merge( $attrs_common, array( 'points' => true ) ),
		'text'   => array_merge( $attrs_common, array( 'x' => true, 'y' => true, 'font-family' => true, 'font-size' => true, 'font-weight' => true, 'text-anchor' => true, 'letter-spacing' => true ) ),
		'title'  => array(),
		'desc'   => array(),
	);
}

/**
 * Build the thank-you (order received) page URL.
 *
 * @since 1.0.0
 * @param int $order_id Order ID.
 * @return string Full URL to the thank-you page.
 */
function tejcart_get_thankyou_url( int $order_id ): string {
	$page_id = (int) get_option( 'tejcart_thankyou_page_id', 0 );
	$url     = $page_id ? get_permalink( $page_id ) : '';

	// get_permalink() returns false when the configured page is missing or
	// trashed. Guard against an empty base here: add_query_arg() silently
	// falls back to the current $_SERVER['REQUEST_URI'] (e.g. the
	// /wp-admin/admin-ajax.php order-creation request) when handed an empty
	// URL, which produces a relative return_url that PayPal rejects with a
	// 400 INVALID_PARAMETER_SYNTAX. Always anchor to an absolute URL.
	if ( ! is_string( $url ) || '' === $url ) {
		$url = home_url( '/' );
	}

	$order     = tejcart_get_order( $order_id );
	$order_key = $order ? $order->get_order_key() : '';

	$url = add_query_arg( array(
		'order_id'  => $order_id,
		'order_key' => $order_key,
	), $url );

	return apply_filters( 'tejcart_thankyou_url', $url, $order_id );
}

/**
 * Determine whether the current page is the cart page.
 *
 * @since 1.0.0
 * @return bool
 */
function tejcart_is_cart_page(): bool {
	$page_id = (int) get_option( 'tejcart_cart_page_id', 0 );
	return $page_id && is_page( $page_id );
}

/**
 * Determine whether the current page is the checkout page.
 *
 * @since 1.0.0
 * @return bool
 */
function tejcart_is_checkout_page(): bool {
	$page_id = (int) get_option( 'tejcart_checkout_page_id', 0 );
	return $page_id && is_page( $page_id );
}

/**
 * Determine whether the current request renders a single TejCart product.
 *
 * TejCart products live in a custom table, not in `wp_posts`, so
 * `is_singular('tejcart_product')` never matches. The canonical signal
 * is the `tejcart_product_slug` query var populated by the rewrite
 * rules in {@see \TejCart\Frontend\Product_Permalinks}.
 *
 * Returns true on the canonical single-product URL path; also returns
 * true when a `tejcart_product` post type is registered (legacy /
 * extension) and a singular query of that type is the current request.
 *
 * @since 1.0.0
 * @return bool
 */
function tejcart_is_single_product(): bool {
	if ( function_exists( 'get_query_var' ) ) {
		$slug = (string) get_query_var( 'tejcart_product_slug', '' );
		if ( '' !== $slug ) {
			return true;
		}
	}
	if ( function_exists( 'is_singular' ) ) {
		$types = apply_filters( 'tejcart_product_post_types', array( 'tejcart_product' ) );
		if ( is_singular( $types ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Retrieve the URL of a TejCart page (cart, checkout, myaccount, thankyou, shop).
 *
 * @since 1.0.0
 * @param string $page Page slug (cart, checkout, myaccount, thankyou, shop).
 * @return string Page URL or home URL as fallback.
 */
function tejcart_get_page_url( string $page ): string {
	$option_map = array(
		'cart'      => 'tejcart_cart_page_id',
		'checkout'  => 'tejcart_checkout_page_id',
		'myaccount' => 'tejcart_myaccount_page_id',
		'thankyou'  => 'tejcart_thankyou_page_id',
		'shop'      => 'tejcart_shop_page_id',
	);

	$option_key = $option_map[ $page ] ?? '';

	if ( $option_key ) {
		$page_id = (int) get_option( $option_key, 0 );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
	}

	return home_url( '/' );
}

/**
 * Retrieve a TejCart setting value.
 *
 * All TejCart options are stored with the `tejcart_` prefix. This helper
 * lets you omit the prefix for brevity.
 *
 * @since 1.0.0
 * @param string $key     Setting key (without `tejcart_` prefix).
 * @param mixed  $default Default value if the option does not exist.
 * @return mixed
 */
function tejcart_get_setting( string $key, $default = '' ) {
	return get_option( 'tejcart_' . $key, $default );
}

/**
 * Whether the "Save for later" cart feature is active.
 *
 * Gated by both the underlying feature class (which can be removed via the
 * `tejcart_feature_classes` filter) and the `Enable Save for Later` admin
 * toggle on the Cart settings tab (`tejcart_enable_save_for_later` option).
 *
 * @since 1.0.0
 * @return bool
 */
function tejcart_save_for_later_enabled(): bool {
	$enabled = class_exists( '\\TejCart\\Cart\\Save_For_Later' )
		&& 'yes' === tejcart_get_setting( 'enable_save_for_later', 'yes' );

	return (bool) apply_filters( 'tejcart_save_for_later_enabled', $enabled );
}

/**
 * Whether the wishlist ("Move to wishlist") feature is active.
 *
 * Gated by both the underlying feature class and the `Enable Wishlist` admin
 * toggle on the Cart settings tab (`tejcart_enable_wishlist` option). Note the
 * cart-item button additionally requires a logged-in customer.
 *
 * @since 1.0.0
 * @return bool
 */
function tejcart_wishlist_enabled(): bool {
	$enabled = class_exists( '\\TejCart\\Wishlist\\Wishlist' )
		&& 'yes' === tejcart_get_setting( 'enable_wishlist', 'yes' );

	return (bool) apply_filters( 'tejcart_wishlist_enabled', $enabled );
}

/**
 * Update an order meta value.
 *
 * @since 1.0.0
 * @param int    $order_id Order ID.
 * @param string $key      Meta key.
 * @param mixed  $value    Meta value.
 * @return bool True on success, false on failure.
 */
function tejcart_update_order_meta( int $order_id, string $key, $value ): bool {
	return \TejCart\Order\Order_Meta::update( $order_id, $key, $value );
}

/**
 * Retrieve an order meta value.
 *
 * @since 1.0.0
 * @param int    $order_id Order ID.
 * @param string $key      Meta key.
 * @param bool   $single   Whether to return a single value. Default true.
 * @return mixed
 */
function tejcart_get_order_meta( int $order_id, string $key, bool $single = true ) {
	return \TejCart\Order\Order_Meta::get( $order_id, $key, $single );
}

/**
 * Format an order address array into a multi-line HTML-ready string.
 *
 * Order::get_billing_address() and get_shipping_address() return
 * associative arrays of raw address fields. The thank-you template,
 * order emails, and admin order detail screens all need a single
 * rendered string wrapped in an <address> tag, so this helper
 * flattens the array into the standard "Name / Company / Line 1 /
 * Line 2 / City, State ZIP / Country" form. Empty fields are skipped
 * so sparse addresses don't render blank lines.
 *
 * @since 1.0.0
 * @param array|null $address Address array from Order::get_*_address().
 * @return string Newline-joined address, escaped for output inside
 *                wp_kses_post(). Empty string when $address has no
 *                printable content.
 */
function tejcart_format_order_address( $address ): string {
	if ( ! is_array( $address ) || empty( $address ) ) {
		return '';
	}

	$name_parts = array_filter(
		array(
			(string) ( $address['first_name'] ?? '' ),
			(string) ( $address['last_name'] ?? '' ),
		),
		'strlen'
	);

	$city_state_zip = array_filter(
		array(
			(string) ( $address['city'] ?? '' ),
			trim( (string) ( $address['state'] ?? '' ) . ' ' . (string) ( $address['postcode'] ?? '' ) ),
		),
		'strlen'
	);

	$lines = array_filter(
		array(
			trim( implode( ' ', $name_parts ) ),
			(string) ( $address['company'] ?? '' ),
			(string) ( $address['address_1'] ?? '' ),
			(string) ( $address['address_2'] ?? '' ),
			trim( implode( ', ', $city_state_zip ) ),
			(string) ( $address['country'] ?? '' ),
		),
		'strlen'
	);

	if ( empty( $lines ) ) {
		return '';
	}

	return implode( "<br>\n", array_map( 'esc_html', $lines ) );
}

/**
 * Retrieve a customer's orders.
 *
 * Uses a single SELECT * query via Order_Manager to avoid the N+1 pattern of
 * loading each order individually.
 *
 * @since 1.0.0
 * @param int $customer_id WordPress user ID.
 * @param int $limit       Maximum number of orders to return.
 * @return array Array of Order objects.
 */
function tejcart_get_customer_orders( int $customer_id, int $limit = 20 ): array {
	$manager = new \TejCart\Order\Order_Manager();

	return $manager->get_orders( array(
		'customer_id' => $customer_id,
		'per_page'    => $limit,
		'page'        => 1,
		'orderby'     => 'created_at',
		'order'       => 'DESC',
	) );
}

/**
 * Retrieve a customer's billing and shipping addresses from user meta.
 *
 * @since 1.0.0
 * @param int $customer_id WordPress user ID.
 * @return array Associative array with 'billing' and 'shipping' sub-arrays.
 */
function tejcart_get_customer_addresses( int $customer_id ): array {
	$address_fields = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'phone',
	);

	$addresses = array(
		'billing'  => array(),
		'shipping' => array(),
	);

	foreach ( array( 'billing', 'shipping' ) as $type ) {
		foreach ( $address_fields as $field ) {
			$meta_key = 'tejcart_' . $type . '_' . $field;
			$addresses[ $type ][ $field ] = get_user_meta( $customer_id, $meta_key, true );
		}
	}

	return $addresses;
}

/**
 * Retrieve a customer's available digital downloads.
 *
 * Queries the customer's completed or processing orders for downloadable
 * products and returns an array of simple download objects. When the
 * optional `$order_id` is supplied, the result is restricted to that
 * single order — used by the order-completed email to list only the
 * downloads the customer just unlocked.
 *
 * @since 1.0.0
 * @param int      $customer_id WordPress user ID.
 * @param int|null $order_id    Optional order ID to restrict results to.
 * @return array Array of download objects with get_id(), get_product_name(),
 *               get_date(), get_downloads_remaining(), get_url() and
 *               get_expires() methods.
 */
function tejcart_get_customer_downloads( int $customer_id, ?int $order_id = null ): array {
	global $wpdb;

	$orders_table = $wpdb->prefix . 'tejcart_orders';
	$items_table  = $wpdb->prefix . 'tejcart_order_items';
	$products_table = $wpdb->prefix . 'tejcart_products';

	if ( null !== $order_id && $order_id > 0 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				"SELECT oi.id AS item_id, oi.order_id, oi.product_id, oi.product_name,
				        o.created_at AS order_date, p.downloadable
				 FROM {$items_table} AS oi
				 INNER JOIN {$orders_table} AS o ON o.id = oi.order_id
				 INNER JOIN {$products_table} AS p ON p.id = oi.product_id
				 WHERE o.customer_id = %d
				   AND o.id = %d
				   AND o.status IN ('completed', 'processing')
				   AND p.downloadable = 1
				 ORDER BY o.created_at DESC",
				$customer_id,
				$order_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	} else {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				"SELECT oi.id AS item_id, oi.order_id, oi.product_id, oi.product_name,
				        o.created_at AS order_date, p.downloadable
				 FROM {$items_table} AS oi
				 INNER JOIN {$orders_table} AS o ON o.id = oi.order_id
				 INNER JOIN {$products_table} AS p ON p.id = oi.product_id
				 WHERE o.customer_id = %d
				   AND o.status IN ('completed', 'processing')
				   AND p.downloadable = 1
				 ORDER BY o.created_at DESC",
				$customer_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	if ( ! $rows ) {
		return array();
	}

	$download_manager = new \TejCart\Download\Download_Manager();
	$expiry_hours     = absint( get_option( 'tejcart_download_expiry_hours', 48 ) );
	$downloads        = array();

	foreach ( $rows as $row ) {
		$product = \TejCart\Product\Product_Factory::get_product( (int) $row->product_id );
		$files   = array();
		if ( $product && method_exists( $product, 'get_download_files' ) ) {
			$files = (array) $product->get_download_files();
		}

		if ( empty( $files ) ) {
			$files = array( array( 'name' => $row->product_name, 'file' => '' ) );
		}

		foreach ( $files as $file_index => $file ) {
			$remaining    = $download_manager->get_remaining_downloads(
				(int) $row->order_id,
				(int) $row->product_id,
				(int) $file_index
			);
			$download_url = $download_manager->generate_download_url(
				(int) $row->order_id,
				(int) $row->product_id,
				(int) $file_index
			);
			$file_name    = ! empty( $file['name'] ) ? (string) $file['name'] : (string) $row->product_name;

			$downloads[] = new class( $row, $remaining, $download_url, $file_name, (int) $file_index, $expiry_hours ) {
				private \stdClass $row;
				private int|string $remaining;
				private string $url;
				private string $file_name;
				private int $file_index;
				private int $expiry_hours;

				public function __construct( \stdClass $row, int|string $remaining, string $url, string $file_name, int $file_index, int $expiry_hours ) {
					$this->row          = $row;
					$this->remaining    = $remaining;
					$this->url          = $url;
					$this->file_name    = $file_name;
					$this->file_index   = $file_index;
					$this->expiry_hours = $expiry_hours;
				}

				public function get_id(): int {
					return (int) ( $this->row->item_id * 1000 + $this->file_index );
				}

				public function get_product_name(): string {
					return $this->file_name;
				}

				public function get_date(): string {
					return date_i18n( get_option( 'date_format' ), strtotime( $this->row->order_date ) );
				}

				public function get_downloads_remaining(): ?int {
					return 'unlimited' === $this->remaining ? null : (int) $this->remaining;
				}

				public function get_url(): string {
					return $this->url;
				}

				public function get_expires(): string {
					if ( $this->expiry_hours <= 0 ) {
						return __( 'Never', 'tejcart' );
					}
					$expires = time() + ( $this->expiry_hours * HOUR_IN_SECONDS );
					return date_i18n(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						$expires
					);
				}
			};
		}
	}

	return $downloads;
}

/**
 * Whether the current visitor has granted cookie consent.
 *
 * The optional `$category` argument identifies the consent purpose the
 * caller is asking about (e.g. `'marketing'`, `'analytics'`,
 * `'functional'`). TejCart's built-in cookie storage is currently a
 * single global flag in the `tejcart_consent` cookie, so the value is
 * the same across categories; the category is forwarded to the
 * `tejcart_cookie_consent_given` filter so consent-management plugins
 * that DO partition consent per purpose can make category-aware
 * decisions. Pre-existing callers that pass no argument keep the
 * single-bucket behaviour they have today.
 *
 * @since 1.0.0
 *
 * @param string $category Optional consent category. Defaults to ''.
 */
function tejcart_has_cookie_consent( string $category = '' ): bool {
	if ( 'yes' !== get_option( 'tejcart_require_cookie_consent', 'no' ) ) {
		return true;
	}

	$given = ! empty( $_COOKIE['tejcart_consent'] );

	/**
	 * Filter whether the visitor has granted cookie consent.
	 *
	 * Return true from an integration with a dedicated consent plugin to
	 * bypass TejCart's own cookie inspection. The `$category` argument
	 * lets consent plugins that partition consent per purpose return
	 * different answers for `'marketing'` vs `'analytics'` etc.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $given    Whether consent has been granted.
	 * @param string $category Consent category being checked. Empty
	 *                         when the caller didn't specify one.
	 */
	return (bool) apply_filters( 'tejcart_cookie_consent_given', $given, $category );
}

/**
 * Emit a schema.org Product JSON-LD <script> for a product.
 *
 * Output is wrapped in a function rather than inlined into the template
 * so themes can `remove_action()` or replace it with a different
 * structured-data implementation. The shape is intentionally narrow —
 * only fields Google's Rich Results validator recognises — to avoid
 * polluting search-snippet caches with optional metadata.
 *
 * @since 1.0.0
 *
 * @param \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
 * @return void
 */
function tejcart_product_json_ld( $product ): void {
	if ( ! $product || ! is_object( $product ) ) {
		return;
	}

	$product_id = (int) $product->get_id();
	if ( ! $product_id ) {
		return;
	}

	$image_ids = array();
	$main_id   = (int) $product->get_image_id();
	if ( $main_id ) {
		$image_ids[] = $main_id;
	}
	foreach ( (array) $product->get_gallery_ids() as $gid ) {
		$gid = (int) $gid;
		if ( $gid && ! in_array( $gid, $image_ids, true ) ) {
			$image_ids[] = $gid;
		}
	}
	$images = array_values( array_filter( array_map(
		static function ( $id ) {
			$url = wp_get_attachment_image_url( (int) $id, 'full' );
			return $url ? $url : null;
		},
		$image_ids
	) ) );

	$description = wp_strip_all_tags( (string) $product->get_description() );
	if ( '' === $description ) {
		$description = wp_strip_all_tags( (string) $product->get_short_description() );
	}
	if ( strlen( $description ) > 5000 ) {
		$description = mb_substr( $description, 0, 4997 ) . '...';
	}

	$price = (string) $product->get_price();
	if ( '' === $price ) {
		$price = '0';
	}

	$availability = 'https://schema.org/InStock';
	if ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
		$availability = 'https://schema.org/OutOfStock';
	}
	if ( method_exists( $product, 'get_stock_status' ) && 'onbackorder' === (string) $product->get_stock_status() ) {
		$availability = 'https://schema.org/PreOrder';
	}

	$data = array(
		'@context'    => 'https://schema.org/',
		'@type'       => 'Product',
		'name'        => (string) $product->get_name(),
		'description' => $description,
		'url'         => $product->get_permalink(),
	);

	if ( ! empty( $images ) ) {
		$data['image'] = $images;
	}

	$sku = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
	if ( '' !== $sku ) {
		$data['sku'] = $sku;
	}

	if ( class_exists( '\\TejCart\\Product\\Product_Taxonomy' ) ) {
		$brands = (array) \TejCart\Product\Product_Taxonomy::get_product_brands( $product_id );
		if ( ! empty( $brands ) && isset( $brands[0]->name ) ) {
			$data['brand'] = array(
				'@type' => 'Brand',
				'name'  => (string) $brands[0]->name,
			);
		}
	}

	$data['offers'] = array(
		'@type'         => 'Offer',
		'price'         => $price,
		'priceCurrency' => function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : (string) get_option( 'tejcart_currency', 'USD' ),
		'availability'  => $availability,
		'url'           => $product->get_permalink(),
	);

	if ( class_exists( '\\TejCart\\Product\\Product_Reviews' ) ) {
		$count = (int) \TejCart\Product\Product_Reviews::get_review_count( $product_id );
		if ( $count > 0 ) {
			$average = (float) \TejCart\Product\Product_Reviews::get_average_rating( $product_id );
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( $average, 1, '.', '' ),
				'reviewCount' => $count,
			);

			$reviews = \TejCart\Product\Product_Reviews::get_reviews( $product_id, array( 'number' => 5 ) );
			$entries = array();
			foreach ( (array) $reviews as $review ) {
				$rating = method_exists( '\\TejCart\\Product\\Product_Reviews', 'get_review_rating' )
					? (int) \TejCart\Product\Product_Reviews::get_review_rating( $review->comment_ID )
					: 0;
				$entries[] = array_filter( array(
					'@type'         => 'Review',
					'author'        => array(
						'@type' => 'Person',
						'name'  => (string) $review->comment_author,
					),
					'datePublished' => mysql2date( 'c', $review->comment_date_gmt ?: $review->comment_date, false ),
					'reviewRating'  => $rating > 0 ? array(
						'@type'       => 'Rating',
						'ratingValue' => $rating,
						'bestRating'  => 5,
						'worstRating' => 1,
					) : null,
					'reviewBody'    => wp_strip_all_tags( (string) $review->comment_content ),
				) );
			}
			if ( ! empty( $entries ) ) {
				$data['review'] = $entries;
			}
		}
	}

	/**
	 * Filter the assembled JSON-LD payload before it is emitted.
	 *
	 * @param array            $data    Schema.org Product payload.
	 * @param object           $product Product instance.
	 */
	$data = apply_filters( 'tejcart_product_json_ld', $data, $product );

	if ( empty( $data ) ) {
		return;
	}

	// JSON-LD lives inside a <script type="application/ld+json"> block — a
	// JSON context, not HTML text. The four JSON_HEX_* flags ensure that
	// `<`, `>`, `&`, `'`, and `"` appearing inside string values are
	// hex-escaped, which is the documented WP coding-standards mitigation
	// for embedding JSON inside HTML and the correct alternative to
	// esc_html() in this context.
	$json = wp_json_encode(
		$data,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
	);

	if ( function_exists( 'wp_print_inline_script_tag' ) ) {
		wp_print_inline_script_tag( (string) $json, array( 'type' => 'application/ld+json' ) );
		echo "\n";
		return;
	}

	echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON context, escaped via JSON_HEX_* flags above.
}

// CSV helpers moved to src/functions/csv.php (#1203 slice 1).
require_once __DIR__ . '/functions/csv.php';

/**
 * Allowlist of SVG tags and attributes TejCart emits for inline icons.
 *
 * Inline icon markup is built from hardcoded literals scattered across the
 * admin and account templates. Rather than trusting each `echo` with a
 * bare `phpcs:ignore`, the icons are funnelled through {@see tejcart_kses_svg()}
 * which enforces this allowlist — so the output is provably limited to drawing
 * primitives and can never carry a `<script>` or an event handler.
 *
 * @since 1.0.1
 *
 * @return array<string, array<string, bool>> wp_kses-shaped allowlist.
 */
function tejcart_kses_svg_allowed_html(): array {
	$attr = array(
		'xmlns'           => true,
		'xmlns:xlink'     => true,
		'width'           => true,
		'height'          => true,
		'viewbox'         => true,
		'preserveaspectratio' => true,
		'fill'            => true,
		'fill-rule'       => true,
		'fill-opacity'    => true,
		'clip-rule'       => true,
		'clip-path'       => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linecap'  => true,
		'stroke-linejoin' => true,
		'stroke-miterlimit' => true,
		'stroke-opacity'  => true,
		'stroke-dasharray' => true,
		'stroke-dashoffset' => true,
		'vector-effect'   => true,
		'opacity'         => true,
		'transform'       => true,
		'class'           => true,
		'style'           => true,
		'role'            => true,
		'aria-hidden'     => true,
		'aria-label'      => true,
		'focusable'       => true,
		'd'               => true,
		'points'          => true,
		'cx'              => true,
		'cy'              => true,
		'r'               => true,
		'rx'              => true,
		'ry'              => true,
		'x'               => true,
		'y'               => true,
		'x1'              => true,
		'y1'              => true,
		'x2'              => true,
		'y2'              => true,
		'offset'          => true,
		'stop-color'      => true,
		'stop-opacity'    => true,
		'gradientunits'   => true,
		'gradienttransform' => true,
		'id'              => true,
		'href'            => true,
		'xlink:href'      => true,
	);

	return array(
		'svg'            => $attr,
		'g'              => $attr,
		'path'           => $attr,
		'circle'         => $attr,
		'ellipse'        => $attr,
		'rect'           => $attr,
		'line'           => $attr,
		'polyline'       => $attr,
		'polygon'        => $attr,
		'defs'           => $attr,
		'lineargradient' => $attr,
		'radialgradient' => $attr,
		'stop'           => $attr,
		'use'            => $attr,
		'title'          => $attr,
	);
}

/**
 * Escape an inline SVG string against the TejCart SVG allowlist.
 *
 * Safe to `echo` directly. Strips anything outside the drawing-primitive
 * allowlist (scripts, event handlers, foreignObject, etc.).
 *
 * @since 1.0.1
 *
 * @param string $svg Raw inline SVG markup (typically a hardcoded literal).
 * @return string Sanitised SVG markup.
 */
function tejcart_kses_svg( string $svg ): string {
	return wp_kses( $svg, tejcart_kses_svg_allowed_html() );
}

/**
 * Escape variation / form-control markup against a form allowlist.
 *
 * Used for the `tejcart_variation_attribute_input` filter result on the
 * single-product template: third parties may swap the default `<select>`
 * for a swatch UI, so the output legitimately contains form controls that
 * `wp_kses_post()` would strip. This allowlist permits the form elements a
 * swatch implementation needs (select/option/input/label/button/…) while
 * still removing `<script>`, event handlers, and `javascript:` URLs.
 *
 * @since 1.0.1
 *
 * @param string $html Markup to sanitise.
 * @return string Sanitised markup.
 */
function tejcart_kses_form_control( string $html ): string {
	$common = array(
		'id'       => true,
		'class'    => true,
		'style'    => true,
		'title'    => true,
		'role'     => true,
		'tabindex' => true,
		'hidden'   => true,
		'data-*'   => true,
		'aria-*'   => true,
	);

	$allowed = array(
		'select'   => $common + array( 'name' => true, 'multiple' => true, 'required' => true, 'disabled' => true, 'size' => true, 'autocomplete' => true ),
		'option'   => $common + array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true ),
		'optgroup' => $common + array( 'label' => true, 'disabled' => true ),
		'input'    => $common + array( 'name' => true, 'type' => true, 'value' => true, 'checked' => true, 'disabled' => true, 'required' => true, 'placeholder' => true, 'min' => true, 'max' => true, 'step' => true, 'readonly' => true, 'maxlength' => true, 'pattern' => true, 'autocomplete' => true ),
		'label'    => $common + array( 'for' => true ),
		'button'   => $common + array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ),
		'fieldset' => $common + array( 'disabled' => true ),
		'legend'   => $common,
		'span'     => $common,
		'div'      => $common,
		'p'        => $common,
		'br'       => $common,
		'strong'   => $common,
		'em'       => $common,
		'small'    => $common,
		'img'      => $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true, 'srcset' => true, 'sizes' => true ),
		'a'        => $common + array( 'href' => true, 'target' => true, 'rel' => true ),
		'ul'       => $common,
		'li'       => $common + array( 'value' => true ),
	);

	return wp_kses( $html, $allowed );
}

/**
 * Resolve a plugin-relative asset path to a full URL, swapping in a
 * pre-minified `.min.css` / `.min.js` companion when one is shipped.
 *
 * The minified files are produced at release time by `bin/minify-assets.mjs`
 * (`npm run build:assets`) and committed alongside the originals so the
 * runtime never has to minify on the fly. Two cases fall back to the
 * unminified source: `SCRIPT_DEBUG` mode (so plugin authors get readable
 * stack traces in dev), and any path where the `.min` companion isn't
 * present on disk (so a partial bundle never 404s on the storefront).
 *
 * @since 2.0.0
 *
 * @param string $relative_path Plugin-relative asset path,
 *                              e.g. 'assets/js/tejcart-cart.js'.
 * @return string Absolute URL to the asset to enqueue.
 */
function tejcart_asset_url( string $relative_path ): string {
	$plugin_dir = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) . '/tejcart.php' );
	$plugin_url = defined( 'TEJCART_PLUGIN_URL' ) ? TEJCART_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) . '/tejcart.php' );

	$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	if ( ! $debug && preg_match( '/\.(css|js)$/', $relative_path ) ) {
		$min_relative = preg_replace( '/\.(css|js)$/', '.min.$1', $relative_path );
		if ( $min_relative && file_exists( $plugin_dir . $min_relative ) ) {
			return $plugin_url . $min_relative;
		}
	}

	return $plugin_url . $relative_path;
}

/**
 * Cache-busting version string for a bundled asset.
 *
 * Returns TEJCART_VERSION suffixed with the asset's file-modification time,
 * so any edit to a committed `.js` / `.css` (or its `.min` counterpart)
 * automatically changes the enqueued `?ver=` and invalidates browser / CDN
 * caches — without bumping the plugin version on every asset tweak. Falls
 * back to the bare plugin version when the file can't be stat'd.
 *
 * Mirrors {@see tejcart_asset_url()}'s min-resolution so the version tracks
 * the file that is actually served.
 *
 * @param string $relative_path Plugin-root-relative asset path.
 * @return string Version string for wp_enqueue_*'s $ver argument.
 */
function tejcart_asset_version( string $relative_path ): string {
	$base       = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
	$plugin_dir = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) . '/tejcart.php' );

	$candidate = $relative_path;
	$debug     = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	if ( ! $debug && preg_match( '/\.(css|js)$/', $relative_path ) ) {
		$min = preg_replace( '/\.(css|js)$/', '.min.$1', $relative_path );
		if ( $min && file_exists( $plugin_dir . $min ) ) {
			$candidate = $min;
		}
	}

	$mtime = @filemtime( $plugin_dir . $candidate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file is a non-fatal fallback path.

	return $mtime ? ( $base . '.' . (string) $mtime ) : $base;
}


/**
 * Resolve the placeholder image URL used when a product has no image.
 *
 * Resolution order:
 *   1. The `tejcart_placeholder_image_src` filter (short-circuits everything).
 *   2. The `tejcart_placeholder_image_id` option — an admin-selected
 *      attachment ID; we ask WordPress for the requested image size and,
 *      if that size isn't generated, fall back to 'full'.
 *   3. The bundled SVG shipped under `assets/images/placeholder.svg`.
 *
 * The function is intentionally side-effect free and safe to call on
 * archive grids — it never performs a remote request and only touches
 * the attachment metadata cache for the admin-selected override path.
 *
 * @since 2.1.0
 *
 * @param string $size Optional. WordPress image size keyword
 *                     (e.g. 'tejcart-product-card', 'full'). Defaults
 *                     to 'tejcart-product-card'.
 * @return string Absolute URL to the placeholder image.
 */
function tejcart_get_placeholder_image_src( string $size = 'tejcart-product-card' ): string {
	$pre = apply_filters( 'tejcart_placeholder_image_src', '', $size );
	if ( is_string( $pre ) && '' !== $pre ) {
		return $pre;
	}

	$attachment_id = (int) apply_filters(
		'tejcart_placeholder_image_id',
		(int) get_option( 'tejcart_placeholder_image_id', 0 )
	);
	if ( $attachment_id > 0 ) {
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		}
		if ( $url ) {
			return $url;
		}
	}

	return tejcart_asset_url( 'assets/images/placeholder.svg' );
}

/**
 * Render an `<img>` tag for the product placeholder image.
 *
 * Used by `product-box.php`, `product-gallery.php`, and any other
 * surface that needs to show a fallback when an attachment lookup
 * fails or no image is set. Output is HTML-escaped and safe to echo
 * directly. The class always contains `tejcart-placeholder-image` so
 * themes can target it.
 *
 * @since 2.1.0
 *
 * @param string               $size  WordPress image size keyword.
 * @param array<string,string> $attrs Optional. Extra `<img>` attributes
 *                                    (alt, class, loading, decoding,
 *                                    fetchpriority, aria-hidden, …).
 *                                    `src` is always overridden.
 * @return string Escaped `<img>` markup.
 */
function tejcart_get_placeholder_image_html( string $size = 'tejcart-product-card', array $attrs = array() ): string {
	$src = tejcart_get_placeholder_image_src( $size );

	$defaults = array(
		'alt'      => '',
		'class'    => 'tejcart-placeholder-image',
		'loading'  => 'lazy',
		'decoding' => 'async',
	);
	$attrs = array_merge( $defaults, $attrs );

	if ( isset( $attrs['class'] ) && false === strpos( $attrs['class'], 'tejcart-placeholder-image' ) ) {
		$attrs['class'] = trim( $attrs['class'] . ' tejcart-placeholder-image' );
	}

	$html = '<img src="' . esc_url( $src ) . '"';
	foreach ( $attrs as $key => $value ) {
		if ( null === $value || '' === $value ) {
			if ( 'alt' !== $key ) {
				continue;
			}
		}
		$html .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
	}
	$html .= ' />';

	return $html;
}

/**
 * Single source of truth for the express-checkout button manifest.
 *
 * Returns the buttons that should render at a given surface (`product`,
 * `cart`, `side_cart`, `mini_cart`, `checkout`). Each entry is keyed by
 * a stable id and carries the gateway slug, the DOM mount-point id, and
 * a per-surface enabled flag derived from gateway settings.
 *
 * Themes, Tier-2 modules, and sibling plugins can hook
 * `tejcart_express_buttons` to add, remove, or reorder buttons for any
 * surface without forking the templates.
 *
 * @param string $surface Render surface key.
 * @return array<string, array{gateway:string, mount:string, enabled:bool}>
 */
function tejcart_get_express_buttons( string $surface ): array {
	$paypal  = null;
	$tejcart = function_exists( 'tejcart' ) ? tejcart() : null;
	if ( is_object( $tejcart ) && method_exists( $tejcart, 'gateways' ) ) {
		$registry = $tejcart->gateways();
		if ( is_object( $registry ) && method_exists( $registry, 'get_gateway' ) ) {
			$paypal = $registry->get_gateway( 'tejcart_paypal' );
		}
	}
	$ready = $paypal && method_exists( $paypal, 'is_available' ) && $paypal->is_available();

	$placement_option = array(
		'product'   => 'button_product_page',
		'cart'      => 'button_cart_page',
		'side_cart' => 'button_side_cart',
		'mini_cart' => 'button_side_cart',
		'checkout'  => 'button_express_checkout',
	);
	$option    = $placement_option[ $surface ] ?? '';
	$paypal_on = $ready && '' !== $option && 'yes' === (string) $paypal->get_option( $option, 'yes' );

	// Venmo is a PayPal-gateway option, but Google Pay and Apple Pay are
	// their own sibling gateways — mirror PayPal_Gateway's localized
	// `enable_google_pay` / `enable_apple_pay` flags so the manifest, the
	// rendered slots, and the JS layer agree on what is enabled.
	$venmo_on = $paypal_on && 'yes' === (string) $paypal->get_option( 'enable_venmo', 'yes' );
	$gp_on    = $paypal_on && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' );
	$ap_on    = $paypal_on && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' );

	$buttons = array(
		'paypal'    => array(
			'gateway' => 'tejcart_paypal',
			'mount'   => 'tejcart-' . $surface . '-express-paypal',
			'enabled' => $paypal_on,
		),
		'venmo'     => array(
			'gateway' => 'tejcart_paypal',
			'mount'   => 'tejcart-' . $surface . '-express-venmo',
			'enabled' => $venmo_on,
		),
		'googlepay' => array(
			'gateway' => 'tejcart_paypal',
			'mount'   => 'tejcart-' . $surface . '-express-googlepay',
			'enabled' => $gp_on,
		),
		'applepay'  => array(
			'gateway' => 'tejcart_paypal',
			'mount'   => 'tejcart-' . $surface . '-express-applepay',
			'enabled' => $ap_on,
		),
	);

	/**
	 * Filter the express-checkout button manifest for a given surface.
	 *
	 * @param array  $buttons Manifest keyed by button id.
	 * @param string $surface One of: product, cart, side_cart, mini_cart, checkout.
	 */
	return (array) apply_filters( 'tejcart_express_buttons', $buttons, $surface );
}

/**
 * PSR-3 severity for a level name. Higher = more severe. 0 means unknown.
 *
 * @internal
 * @param string $level PSR-3 level name (case-insensitive).
 * @return int
 */
function tejcart_log_level_severity( string $level ): int {
	static $map = array(
		'emergency' => 800,
		'alert'     => 700,
		'critical'  => 600,
		'error'     => 500,
		'warning'   => 400,
		'notice'    => 300,
		'info'      => 200,
		'debug'     => 100,
	);
	$level = strtolower( $level );
	return $map[ $level ] ?? 0;
}

/**
 * Severity threshold below which `tejcart_log()` discards entries.
 *
 * Reads the `tejcart_log_level` option (PSR-3 level name, or `off` to
 * disable logging entirely). Default is `error`. The `tejcart_log_level`
 * filter wraps the resolved level so hosts can pin it via `wp-config.php`.
 *
 * @return int Severity number (PHP_INT_MAX when logging is `off`).
 */
function tejcart_log_level_threshold(): int {
	$configured = strtolower( (string) get_option( 'tejcart_log_level', 'error' ) );
	/**
	 * Filter the active TejCart log level.
	 *
	 * @since 1.0.0
	 * @param string $level One of: off, emergency, alert, critical, error,
	 *                      warning, notice, info, debug.
	 */
	$configured = strtolower( (string) apply_filters( 'tejcart_log_level', $configured ) );

	if ( 'off' === $configured ) {
		return PHP_INT_MAX;
	}

	$severity = tejcart_log_level_severity( $configured );
	return $severity > 0 ? $severity : tejcart_log_level_severity( 'error' );
}

/**
 * Whether a message at `$level` clears the configured threshold.
 *
 * @param string $level PSR-3 level.
 * @return bool
 */
function tejcart_log_level_passes( string $level ): bool {
	$severity = tejcart_log_level_severity( $level );
	if ( 0 === $severity ) {
		$severity = tejcart_log_level_severity( 'info' );
	}
	return $severity >= tejcart_log_level_threshold();
}

/**
 * Write a message to the TejCart log.
 *
 * File handler conventions:
 *   - Stored under `{uploads}/tejcart-logs/`, locked down by `.htaccess`
 *     (Apache), `web.config` (IIS) and an empty `index.html`.
 *   - Filenames are `{source}-{YYYY-MM-DD}-{wp_hash}.log` so external
 *     guessing of the URL is impractical even on a misconfigured host.
 *   - Active line format is one of:
 *       text (default): `{ISO-8601 UTC} {LEVEL} {message} [{context-json}]`
 *       json (opt-in)  : `{"ts":"…","level":"…","msg":"…","source":"…","context":{…}}`
 *     Switch via the `tejcart_log_format` option / filter.
 *   - Writes are atomic — `file_put_contents( ..., FILE_APPEND | LOCK_EX )`.
 *   - Rotation: size-based at `tejcart_log_max_bytes` (default 10 MiB),
 *     keeping `tejcart_log_max_rotations` siblings (default 5), in
 *     addition to the YYYY-MM-DD filename rollover.
 *
 * Pipeline applied to every entry:
 *   1. Level gate via `tejcart_log_level` (dropped below threshold,
 *      no I/O at all when off).
 *   2. `tejcart_log_writer` filter — short-circuit to a custom sink
 *      (Datadog / CloudWatch / 12-factor STDOUT).
 *   3. `TEJCART_LOG_TO_STDERR` constant / env — 12-factor STDERR sink.
 *   4. PSR-3 `{placeholder}` interpolation from `$context`.
 *   5. `$context['exception']` Throwable formatting (class + frames).
 *   6. PCI / PII / secret redaction via {@see TejCart\Logging\Redactor}.
 *   7. Control-character escaping in the rendered message (prevents
 *      log injection from buyer-supplied strings).
 *   8. Rotation + atomic write to disk.
 *
 * Pass `array( 'source' => 'paypal' )` (etc.) in `$context` to route
 * an entry to a dedicated per-channel file; remaining context keys
 * are JSON-appended to the line (or nested under `context` in JSON
 * mode).
 *
 * @since 1.0.0
 * @param string               $message Log message. May contain `{placeholders}`
 *                                      resolved from `$context`.
 * @param string               $level   PSR-3 level: emergency, alert, critical,
 *                                      error, warning, notice, info, debug.
 * @param array<string, mixed> $context Optional context. `source` selects the
 *                                      log channel; `exception` receives PSR-3
 *                                      special handling; other keys are appended.
 */
function tejcart_log( string $message, string $level = 'info', array $context = array() ): void {
	// Single source of truth: the central writer handles every
	// step — level gate, writer filter, STDERR sink, redaction,
	// PSR-3 placeholder interpolation, exception formatting,
	// rotation, atomic append-with-lock, and opportunistic
	// retention. The legacy `error_log( $entry, 3, $file )` code
	// path is gone (it was not atomic under concurrent writes).
	\TejCart\Logging\Log_Writer::write( $message, $level, $context );
}

/**
 * Write a tax-pipeline diagnostic line. Always-on (bypasses
 * `tejcart_log_level`) because a misconfigured live tax provider that
 * silently falls through to the manual rate table is the single most
 * common "tax doesn't show up" merchant report — and the merchant has no
 * way to know they need to enable a separate debug toggle to see why.
 *
 * Routes to a per-channel file under `{uploads}/tejcart-logs/` named
 * `<source>-<YYYY-MM-DD>-<wp_hash>.log` so each provider (`tax_taxjar`,
 * `tax_avalara`, `tax_stripe_tax`) and the pipeline itself
 * (`tax_registry`) get their own file.
 *
 * @since 1.0.0
 * @param string               $source  Channel id, e.g. `tax_taxjar`.
 * @param string               $event   Short human-readable description.
 * @param array<string, mixed> $context Optional structured fields, JSON-appended.
 */
function tejcart_tax_log( string $source, string $event, array $context = array() ): void {
	$source = sanitize_key( $source );
	if ( '' === $source ) {
		$source = 'tax_registry';
	}

	$context['source'] = $source;

	// Bypass the severity threshold so this remains "always-on at
	// debug level" — Log_Writer still honours the `off` kill-switch
	// internally, so a merchant who has explicitly disabled logging
	// never sees writes from this channel.
	\TejCart\Logging\Log_Writer::write( $event, 'debug', $context, true );
}

/**
 * Write a discount / coupon diagnostic line.
 *
 * Mirrors {@see tejcart_tax_log()} and {@see tejcart_shipping_log()}: a
 * coupon that silently fails to apply (excluded product, expired rule,
 * usage cap hit, customer ineligible) is a high-volume merchant
 * support ticket, and the merchant has no way to know which gate
 * dropped it without a dedicated, always-on channel.
 *
 * Routes to a per-channel file under `{uploads}/tejcart-logs/` named
 * `<source>-<YYYY-MM-DD>-<wp_hash>.log`. Use `discount` for the
 * pipeline itself; pass any other slug for a per-rule sub-channel.
 *
 * @since 1.0.0
 * @param string               $source  Channel id, e.g. `discount`, `discount_bogo`.
 * @param string               $event   Short human-readable description.
 * @param array<string, mixed> $context Optional structured fields, JSON-appended.
 */
function tejcart_discount_log( string $source, string $event, array $context = array() ): void {
	$source = sanitize_key( $source );
	if ( '' === $source ) {
		$source = 'discount';
	}

	$context['source'] = $source;
	// Bypass severity gate — see {@see tejcart_tax_log()}.
	\TejCart\Logging\Log_Writer::write( $event, 'debug', $context, true );
}

/**
 * Resolve a per-module log channel.
 *
 * Resolves through the DI container (`logger` binding) so a test or
 * sibling plugin that rebinds the key affects every call site. Falls
 * back to {@see \TejCart\Logging\Logger::instance()} when called before
 * the TejCart singleton has been built. Channels are PSR-3 compatible
 * (`info()`, `warning()`, `error()`, ...) and respect the global
 * `tejcart_log_level` gate the same way {@see tejcart_log()} does.
 *
 * Each channel writes to its own file under `{uploads}/tejcart-logs/`,
 * named `<channel>-<YYYY-MM-DD>-<wp_hash>.log`. The canonical channels
 * shipped by core are listed in
 * {@see \TejCart\Logging\Logger::canonical_channels()}; addons may
 * register additional channels by name without any prior registration.
 *
 * Use the channel's {@see \TejCart\Logging\Log_Channel::request()} and
 * {@see \TejCart\Logging\Log_Channel::response()} helpers to log paired
 * outbound HTTP calls — both sides share a correlation id so a full
 * round trip can be reconstructed from the log file alone.
 *
 * @since 1.0.0
 * @param string $channel Channel name, e.g. `payment`, `tax`, `shipping`,
 *                        `discount`, `payment_paypal`.
 * @return \TejCart\Logging\Log_Channel
 */
function tejcart_logger( string $channel = 'tejcart' ): \TejCart\Logging\Log_Channel {
	if ( function_exists( 'tejcart' ) ) {
		$tejcart = tejcart();
		if ( $tejcart && $tejcart->container()->has( 'logger' ) ) {
			return $tejcart->logger()->get( $channel );
		}
	}
	return \TejCart\Logging\Logger::instance()->get( $channel );
}

/**
 * Write a shipping-pipeline diagnostic line. Always-on (bypasses both
 * `WP_DEBUG_LOG` and `tejcart_log_level`) for the same reason
 * {@see tejcart_tax_log()} is: a misconfigured carrier that silently
 * declines to quote (missing credentials, an origin the carrier can't
 * ship from, a destination outside the service area) is the single most
 * common "my carrier isn't showing at checkout" merchant report — and
 * the merchant has no way to know they need to enable a separate debug
 * toggle to see why.
 *
 * Routes to a per-channel file under `{uploads}/tejcart-logs/` named
 * `<source>-<YYYY-MM-DD>-<wp_hash>.log` so each driver
 * (`shipping_shiprocket`, `shipping_fedex`, ...) and the pipeline
 * itself (`shipping`) gets its own file.
 *
 * @since 1.0.0
 * @param string               $source  Channel id, e.g. `shipping_shiprocket`.
 * @param string               $event   Short human-readable description.
 * @param array<string, mixed> $context Optional structured fields, JSON-appended.
 */
function tejcart_shipping_log( string $source, string $event, array $context = array() ): void {
	$source = sanitize_key( $source );
	if ( '' === $source ) {
		$source = 'shipping';
	}

	$context['source'] = $source;
	// Bypass severity gate — see {@see tejcart_tax_log()}.
	\TejCart\Logging\Log_Writer::write( $event, 'debug', $context, true );
}

/**
 * Write a captcha / bot-gate diagnostic line.
 *
 * Mirrors {@see tejcart_tax_log()} and {@see tejcart_shipping_log()}: a
 * captcha provider that silently blocks checkout (missing/invalid token,
 * wrong secret key, provider outage, reCAPTCHA score under the threshold)
 * is a high-impact merchant report — the store appears to "stop taking
 * orders" with no visible cause — and the merchant has no way to know
 * which surface the bot gate dropped, or why, without a dedicated,
 * always-on channel.
 *
 * Routes to a per-channel file under `{uploads}/tejcart-logs/` named
 * `captcha-<YYYY-MM-DD>-<wp_hash>.log`. Callers should pass already
 * redacted identifiers (e.g. via {@see \TejCart\Security\Log_Redactor::ip()});
 * the Log_Writer redactor still scrubs the payload as a backstop.
 *
 * @since 1.0.1
 * @param string               $event   Short human-readable description.
 * @param array<string, mixed> $context Optional structured fields, JSON-appended.
 */
function tejcart_captcha_log( string $event, array $context = array() ): void {
	$context['source'] = isset( $context['source'] )
		? ( sanitize_key( (string) $context['source'] ) ?: 'captcha' )
		: 'captcha';
	// Bypass severity gate — see {@see tejcart_tax_log()}.
	\TejCart\Logging\Log_Writer::write( $event, 'debug', $context, true );
}

/**
 * Prune log files older than the configured retention.
 *
 * Walks the given log directory and deletes every `*.log` (and any
 * rotated `*.log.N`) file whose mtime is older than the retention
 * threshold. Default retention is 30 days; merchants can override with
 * the `tejcart_log_retention_days` filter or option. Setting either
 * to 0 disables age-based retention (logs are kept forever — not
 * recommended in production).
 *
 * In addition to age-based pruning, a `tejcart_log_max_files` safety
 * net trims the oldest files when the total log-file count exceeds
 * the cap (default 200). This guards against a runaway misconfiguration
 * filling the inode table on small hosts. Set the filter to 0 to
 * disable the count safety net.
 *
 * The directory's `.htaccess`, `web.config`, and `index.html` deny
 * guards are explicitly preserved so the security posture is never
 * removed by the prune.
 *
 * @internal
 * @param string $log_dir Trailing-slashed absolute path to the log dir.
 * @return int Number of files deleted.
 */
function tejcart_log_dir_prune( string $log_dir ): int {
	if ( '' === $log_dir || ! is_dir( $log_dir ) ) {
		return 0;
	}

	$retention_days = (int) get_option( 'tejcart_log_retention_days', 30 );
	/**
	 * Filter the log retention window in days.
	 *
	 * Default 30. Set to 0 to disable retention (keep logs forever
	 * — not recommended in production).
	 *
	 * @since 1.0.1
	 *
	 * @param int $days Retention window in days.
	 */
	$retention_days = (int) apply_filters( 'tejcart_log_retention_days', $retention_days );

	$deleted = 0;

	// Glob both the active `.log` files AND any rotated `.log.N`
	// siblings produced by Log_Writer's size-based rotation. Sorting
	// happens later when we apply the count safety net.
	$files = array_merge(
		(array) glob( $log_dir . '*.log' ),
		(array) glob( $log_dir . '*.log.*' )
	);
	if ( empty( $files ) ) {
		return 0;
	}

	// Age-based prune.
	if ( $retention_days > 0 ) {
		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $mtime || $mtime >= $cutoff ) {
				continue;
			}
			wp_delete_file( $file );
			if ( ! file_exists( $file ) ) {
				$deleted++;
			}
		}
	}

	// Count-based safety net — protects the inode table even when an
	// operator sets retention to 0 / a high value and a misconfigured
	// driver writes thousands of distinct channel slugs.
	$max_files = 200;
	/**
	 * Filter the maximum number of log files retained on disk. The
	 * oldest files past this cap are deleted on every prune sweep.
	 *
	 * @since 1.0.1
	 * @param int $max Default 200. Set to 0 to disable the count cap.
	 */
	$max_files = (int) apply_filters( 'tejcart_log_max_files', $max_files );
	if ( $max_files > 0 ) {
		$alive = array_values( array_filter(
			array_merge(
				(array) glob( $log_dir . '*.log' ),
				(array) glob( $log_dir . '*.log.*' )
			),
			static fn ( $f ): bool => is_string( $f ) && is_file( $f )
		) );
		if ( count( $alive ) > $max_files ) {
			usort(
				$alive,
				static function ( $a, $b ) {
					$ma = @filemtime( $a ) ?: 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$mb = @filemtime( $b ) ?: 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return $ma <=> $mb;
				}
			);
			$overflow = count( $alive ) - $max_files;
			for ( $i = 0; $i < $overflow; $i++ ) {
				$file = $alive[ $i ];
				wp_delete_file( $file );
				if ( ! file_exists( $file ) ) {
					$deleted++;
				}
			}
		}
	}

	return $deleted;
}

/**
 * Resolve the TejCart log directory.
 *
 * Logs live under the WP uploads directory (`{uploads}/tejcart-logs/`) so
 * they ride along with the writable filesystem baseline that any WordPress
 * install already provides, rather than requiring a writable wp-content root.
 *
 * @return string Trailing-slashed absolute path.
 */
function tejcart_log_dir(): string {
	// Always resolve through the uploads API so the path honours a custom
	// `UPLOADS` constant, a filtered `upload_dir`, and per-site multisite
	// roots. Prefer `wp_get_upload_dir()` (no side effects, cached) when it
	// is available, falling back to `wp_upload_dir()` on older cores.
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$uploads = wp_get_upload_dir();
	} elseif ( function_exists( 'wp_upload_dir' ) ) {
		$uploads = wp_upload_dir( null, false );
	} else {
		$uploads = array();
	}

	if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
		// Uploads API unavailable (called outside a full WP runtime). Bail
		// rather than guessing a hardcoded wp-content path — callers treat
		// an empty string as "logging unavailable" and skip the write.
		return '';
	}

	return trailingslashit( $uploads['basedir'] ) . 'tejcart-logs/';
}

// Heartbeat: when an admin changes the log level to a verbose setting,
// write a sentinel entry so a log file is created immediately and the
// operator can confirm the pipeline is wired correctly without having
// to reproduce a real failure first.
if ( function_exists( 'add_action' ) ) {
	$tejcart_log_heartbeat = static function ( $value ): void {
		$severity = tejcart_log_level_severity( (string) $value );
		// Only emit when the new level is at or below `info` — there's
		// no value writing a sentinel at `error` since real failures
		// will fill the file naturally.
		if ( $severity <= 0 || $severity > tejcart_log_level_severity( 'info' ) ) {
			return;
		}

		tejcart_log(
			sprintf(
				'Log level set to %s. TejCart %s on PHP %s, WordPress %s.',
				strtolower( (string) $value ),
				defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : 'unknown',
				PHP_VERSION,
				function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'unknown'
			),
			'info',
			array( 'source' => 'tejcart' )
		);
	};

	add_action(
		'update_option_tejcart_log_level',
		static function ( $old_value, $new_value ) use ( $tejcart_log_heartbeat ): void {
			if ( $old_value === $new_value ) {
				return;
			}
			$tejcart_log_heartbeat( $new_value );
		},
		10,
		2
	);

	add_action(
		'add_option_tejcart_log_level',
		static function ( $option, $value ) use ( $tejcart_log_heartbeat ): void {
			$tejcart_log_heartbeat( $value );
		},
		10,
		2
	);
}

/**
 * Resolve the brand colour used by transactional emails.
 *
 * Falls through `tejcart_email_background_color`, then
 * `tejcart_email_brand_color`, finally a vendor default. Returns a
 * normalised `#rrggbb` (or short hex) string suitable for embedding
 * in inline CSS and VML `fillcolor` attributes.
 *
 * @since 1.0.0
 * @return string
 */
function tejcart_email_brand_color(): string {
	$color = (string) get_option( 'tejcart_email_background_color', '' );
	if ( '' === $color ) {
		$color = (string) get_option( 'tejcart_email_brand_color', '#0073aa' );
	}

	if ( ! preg_match( '/^#([a-f0-9]{6}|[a-f0-9]{3})$/i', $color ) ) {
		$color = '#0073aa';
	}

	/**
	 * Filter the brand colour used in transactional emails.
	 *
	 * @param string $color Hex string including leading "#".
	 */
	return (string) apply_filters( 'tejcart_email_brand_color', $color );
}

/**
 * Render a bulletproof email call-to-action button.
 *
 * Outputs a hybrid VML / `<a>` button that renders identically across
 * Outlook 2007–2019 (Word engine), Outlook.com, Gmail, Apple Mail,
 * Yahoo, AOL, and mobile webviews. The Outlook-only `<v:roundrect>` is
 * gated behind `<!--[if mso]>` so non-Outlook clients ignore it; the
 * `<a>` fallback is gated behind `<!--[if !mso]><!-- -->` so Outlook
 * doesn't render both.
 *
 * @since 1.0.0
 * @param string $url    Destination URL.
 * @param string $label  Button label (already translated).
 * @param string $color  Optional fill colour. Defaults to brand colour.
 * @return string HTML markup. Safe for direct echo.
 */
function tejcart_email_button( string $url, string $label, string $color = '' ): string {
	if ( '' === $url || '' === $label ) {
		return '';
	}

	if ( '' === $color ) {
		$color = tejcart_email_brand_color();
	}

	$safe_url   = esc_url( $url );
	$safe_label = esc_html( $label );
	$safe_color = esc_attr( $color );

	$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	$html  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:24px auto;border-collapse:separate;">';
	$html .= '<tr><td align="center" style="border-radius:4px;" bgcolor="' . $safe_color . '">';
	$html .= '<!--[if mso]>';
	$html .= '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $safe_url . '" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="8%" stroke="f" fillcolor="' . $safe_color . '">';
	$html .= '<w:anchorlock/>';
	$html .= '<center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">' . $safe_label . '</center>';
	$html .= '</v:roundrect>';
	$html .= '<![endif]-->';
	$html .= '<!--[if !mso]><!-- -->';
	$html .= '<a href="' . $safe_url . '" target="_blank" rel="noopener" style="background-color:' . $safe_color . ';border:1px solid ' . $safe_color . ';border-radius:4px;color:#ffffff;display:inline-block;font-family:' . $font . ';font-size:16px;font-weight:600;line-height:48px;text-align:center;text-decoration:none;width:240px;-webkit-text-size-adjust:none;mso-hide:all;">' . $safe_label . '</a>';
	$html .= '<!--<![endif]-->';
	$html .= '</td></tr></table>';

	return $html;
}

/**
 * Sane defaults for any TejCart-issued outbound HTTP call.
 *
 * `wp_remote_*` defaults are 5s timeout, 5 redirects, no User-Agent —
 * which a lot of upstream APIs (carrier APIs, AI APIs) reject or
 * throttle. Plug this into the `args` slot of every wp_remote_get /
 * post / request inside TejCart so the timeout, redirect cap, and UA
 * are uniform and tied to a per-service identity.
 *
 * The returned array is intentionally minimal — pass it through
 * `wp_parse_args` against your call-specific args (body, headers,
 * method) so the caller can override anything.
 *
 * @since 1.0.1
 *
 * @param string               $service Short service name used in the
 *                                      User-Agent (e.g. `paypal`, `openai`,
 *                                      `easypost`). Keep alphanum so the UA
 *                                      string stays parseable.
 * @param array<string, mixed> $overrides Additional args to merge into the
 *                                      defaults (caller wins on conflict).
 * @return array<string, mixed>
 */
function tejcart_external_http_args( string $service, array $overrides = array() ): array {
	$version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '0.0.0';
	$safe_service = preg_match( '/^[a-zA-Z0-9_-]{1,32}$/', $service ) ? $service : 'tejcart';
	$site_host    = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST ) : '';
	$site_host    = '' !== $site_host ? $site_host : 'unknown.host';

	$defaults = array(
		'timeout'     => 15,
		'redirection' => 3,
		'sslverify'   => true,
		'user-agent'  => sprintf( 'TejCart/%s (+%s; service=%s)', $version, $site_host, $safe_service ),
	);

	/**
	 * Filter the per-service defaults — e.g. lift the timeout for
	 * a known-slow carrier endpoint.
	 *
	 * @since 1.0.1
	 *
	 * @param array<string, mixed> $defaults
	 * @param string               $service
	 */
	$defaults = (array) apply_filters( 'tejcart_external_http_args', $defaults, $service );

	return array_merge( $defaults, $overrides );
}

/**
 * Retrieve the current visitor's cart session key.
 *
 * @since 1.0.0
 * @return string 64-char hex session key, or empty string if unavailable.
 */
function tejcart_get_session_key(): string {
	try {
		$cart = tejcart_get_cart();
	} catch ( \Throwable $e ) {
		return '';
	}
	return $cart ? $cart->get_session_key() : '';
}
