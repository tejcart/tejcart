<?php
/**
 * Hooks into TejCart's price-display filters to swap base currency
 * amounts and formatting for the visitor's active currency.
 *
 * @package TejCart\Currency_Switcher\Conversion
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Conversion;

use TejCart\Currency_Switcher\Checkout\Checkout_Controller;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires the Converter into the four TejCart filters that govern how
 * prices look:
 *
 *  - `tejcart_currency`        — the currency code returned by helpers
 *  - `tejcart_currency_symbol` — the symbol used by `tejcart_price()`
 *  - `tejcart_price_amount`    — the numeric amount before formatting
 *  - `tejcart_price_format`    — the final formatted string (re-renders
 *                                with the active currency's decimals
 *                                / separators / position)
 *
 * Filters run at priority 20 and PHP_INT_MAX - 1 to layer over any
 * core/tier-2 filter that may have already converted.
 */
final class Price_Filters {
    private Converter $converter;
    private Currency_Resolver $resolver;

    public function __construct( ?Converter $converter = null, ?Currency_Resolver $resolver = null ) {
        $this->resolver  = $resolver ?? new Currency_Resolver();
        $this->converter = $converter ?? new Converter( $this->resolver );
    }

    public function register(): void {
        // We register only at priority 20. The Nexa WC plugin doubled
        // up at PHP_INT_MAX-1 because WooCommerce filter input includes
        // *already-converted* prices from compounding hooks; the WC
        // implementation defends against that by reading raw prices
        // via `$product->get_*_price('edit')`. TejCart's
        // `tejcart_price_amount` filter always receives the raw base
        // amount, so registering twice would double-convert without
        // a similar re-read guard. Themes / tier-2 modules that need
        // to override us can hook later via their own filter.
        add_filter( 'tejcart_currency',         array( $this, 'filter_currency' ), 20 );
        add_filter( 'tejcart_currency_symbol',  array( $this, 'filter_symbol' ), 20, 2 );
        add_filter( 'tejcart_price_amount',     array( $this, 'filter_amount' ), 20, 2 );
        add_filter( 'tejcart_price_format',     array( $this, 'filter_format' ), 20, 3 );
    }

    /**
     * Swap the reported currency code for the active display currency.
     *
     * Mode B (force base at checkout) intentionally short-circuits here
     * so order totals are stored & charged in the base currency.
     *
     * In admin context the resolver returns base currency (or the
     * `tejcart_csw_admin_display_currency` override, if a screen has
     * installed one — typically the order detail screen pinning the
     * order's transacted currency). Either way, the cookie-driven
     * front-end currency never reaches admin reports.
     */
    public function filter_currency( $code ) {
        if ( Checkout_Controller::is_force_base_request() ) {
            return $code;
        }
        return $this->resolver->current();
    }

    /**
     * Replace the symbol with the active currency's symbol. We delegate
     * to TejCart core's built-in symbol map by re-reading
     * `tejcart_get_currency_symbol` with an explicit code — that gives
     * us the right symbol without duplicating the map.
     */
    public function filter_symbol( $symbol, $currency ) {
        if ( Checkout_Controller::is_force_base_request() ) {
            return $symbol;
        }
        // The active currency's per-row symbol is governed by core's
        // tejcart_get_currency_symbol() — `$currency` already reflects
        // our `tejcart_currency` filter above, so the default symbol
        // is correct in nearly all cases. We only need to override
        // when an admin has explicitly bound a custom symbol via the
        // filter; that's left as a future enhancement.
        return $symbol;
    }

    /**
     * Display-amount passthrough.
     *
     * Conversion has moved to the *source* — {@see Source_Conversion}
     * converts product prices, the cart snapshot, shipping rates, and
     * fixed-amount coupons into the active currency before they ever
     * reach the cart calculator, the order row, or the gateway. By the
     * time a value reaches `tejcart_price()` it is therefore already
     * denominated in the currency reported by {@see self::filter_currency()},
     * so this formatter must NOT apply the rate again — doing so would
     * double-convert (the classic "€95.36 shown / €108.44 charged" split
     * this module exists to prevent, just inverted).
     *
     * The currency *code*, *symbol*, and *number formatting* are still
     * swapped — see {@see self::filter_currency()},
     * {@see self::filter_symbol()}, and {@see self::filter_format()}.
     *
     * @param mixed $amount   Amount already in the active currency.
     * @param mixed $currency Active currency code (unused).
     * @return float
     */
    public function filter_amount( $amount, $currency ) {
        unset( $currency );
        return (float) $amount;
    }

    /**
     * Re-render the final formatted price using the active currency's
     * decimal/thousand separators and symbol position. This runs after
     * core has formatted with the *store* options — we replace the
     * output entirely so a EUR price comes out as "1.234,56 €" not
     * "1,234.56 €".
     */
    public function filter_format( $rendered, $amount, $currency ) {
        if ( Checkout_Controller::is_force_base_request() ) {
            return $rendered;
        }

        $cfg = $this->converter->repository()->get( (string) $currency );
        if ( null === $cfg ) {
            return $rendered;
        }

        $symbol    = function_exists( 'tejcart_get_currency_symbol' )
            ? tejcart_get_currency_symbol( (string) $currency )
            : (string) $currency;
        $formatted = number_format(
            (float) $amount,
            $cfg->num_decimals,
            $cfg->decimal_sep,
            $cfg->thousand_sep
        );

        return match ( $cfg->currency_pos ) {
            'right'       => $formatted . $symbol,
            'left_space'  => $symbol . ' ' . $formatted,
            'right_space' => $formatted . ' ' . $symbol,
            default       => $symbol . $formatted,
        };
    }
}
