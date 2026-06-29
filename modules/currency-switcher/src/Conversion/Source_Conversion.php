<?php
/**
 * Source-side currency conversion.
 *
 * Converts every *base-currency monetary source* (product prices, the
 * cart line snapshot, shipping rates, fixed-amount coupons) into the
 * currency the current request actually transacts in, BEFORE those
 * amounts flow into the cart calculator, the order row, and the payment
 * gateway.
 *
 * This is the half {@see Price_Filters} never implemented. Price_Filters
 * converts only what is *displayed* (`tejcart_price_amount`); the cart
 * total, the persisted order, and the PayPal request all read the raw
 * stored amounts, so in Mode A they ended up denominated in the base
 * currency while wearing the display currency's *code* — the buyer saw
 * €112.53 but PayPal charged €127.96 (the USD figure relabelled EUR).
 *
 * With source conversion every amount is genuinely in the active
 * currency from the moment it enters the pricing pipeline, so cart,
 * order, gateway, admin order screen, and the FX-meta writer all agree.
 * Once this runs, `Price_Filters::filter_amount()` becomes a passthrough
 * (the amount it receives is already converted) — converting there too
 * would double-apply the rate.
 *
 * Industry baseline: this mirrors how Aelia / WPML Multicurrency / WOOCS
 * convert at the price getters and treat the formatter as a pure
 * formatter (see docs/money-representation.md).
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
 * Wires the {@see Converter} into TejCart's monetary *source* filters.
 *
 * All hooks share one guard via {@see self::target_currency()}:
 *
 *  - Admin renders (`is_admin_render()`) resolve to the base currency so
 *    reports / dashboards / the product edit form reconcile to the books
 *    of record — never the cookie-driven storefront currency.
 *  - Mode B at a checkout context (`is_force_base_request()`) resolves to
 *    the base currency so the order is charged in the base currency even
 *    when the buyer was browsing in another.
 *  - Otherwise the active display currency.
 *
 * When the resolved target equals the base currency every hook is an
 * exact passthrough, so the module imposes zero behavioural change on a
 * single-currency store.
 */
final class Source_Conversion {
    private Converter $converter;
    private Currency_Resolver $resolver;

    public function __construct( ?Converter $converter = null, ?Currency_Resolver $resolver = null ) {
        $this->resolver  = $resolver ?? new Currency_Resolver();
        $this->converter = $converter ?? new Converter( $this->resolver );
    }

    public function register(): void {
        // Product prices — the effective price plus the regular/sale
        // figures used for the struck-through "was" price and sale badges.
        add_filter( 'tejcart_product_get_price',         array( $this, 'filter_product_price' ), 20, 2 );
        add_filter( 'tejcart_product_get_regular_price', array( $this, 'filter_product_price' ), 20, 2 );
        add_filter( 'tejcart_product_get_sale_price',    array( $this, 'filter_product_price' ), 20, 2 );

        // Cart line snapshot: stamp the currency the snapshot is captured
        // in, then re-base / re-convert it whenever the request's target
        // currency differs (currency switch mid-session, or a Mode-B
        // force-base checkout reached after adding in the display currency).
        add_filter( 'tejcart_cart_item_data',  array( $this, 'stamp_currency_at_add' ), 20, 3 );
        add_filter( 'tejcart_cart_item_price', array( $this, 'filter_cart_item_price' ), 20, 3 );

        // Shipping rates. Cart_Calculator applies TWO filters in sequence
        // to the running rate: `tejcart_calculated_shipping` (the method
        // rate, in base currency) and then `tejcart_calculated_shipping_with_classes`
        // (after the base-currency global class-fee surcharges are added).
        // Converter::convert() is NOT idempotent, so we must hook only ONE
        // of them or the rate is converted twice (rate²). We hook the FINAL
        // one so the base rate AND the base class fees are converted together,
        // exactly once. Per-rate DISPLAY surfaces (the checkout method picker,
        // the cart shipping estimator, the PayPal wallet option list) apply
        // this same final filter to their bare method cost so what the buyer
        // sees matches the converted cart total.
        add_filter( 'tejcart_calculated_shipping_with_classes', array( $this, 'filter_shipping' ), 20, 2 );

        // Free-shipping threshold (a base-currency monetary GATE). The cart
        // net it is compared against is already in the target currency, so the
        // threshold must be converted too or the free-shipping boundary is
        // wrong by the FX rate for every non-base currency.
        add_filter( 'tejcart_free_shipping_threshold', array( $this, 'filter_threshold' ), 20 );

        // Coupon minimum/maximum spend gates (base-currency), compared against
        // the converted cart subtotal during validation — convert them too.
        add_filter( 'tejcart_coupon_min_spend', array( $this, 'filter_threshold' ), 20 );
        add_filter( 'tejcart_coupon_max_spend', array( $this, 'filter_threshold' ), 20 );

        // Cart fees (gift wrap, handling, etc.). Each fee's `amount` is in the
        // base currency; convert it to the active currency. Priority 30 so it
        // runs after fee PRODUCERS (e.g. Gift_Wrap at priority 10) have added
        // their entries to the array.
        add_filter( 'tejcart_cart_fees', array( $this, 'filter_fees' ), 30, 2 );

        // Fixed-amount coupons (percent coupons are a rate, left untouched).
        add_filter( 'tejcart_coupon_amount', array( $this, 'filter_coupon_amount' ), 20, 4 );

        // Explicit-target conversion seam: convert a base-currency amount into
        // a SPECIFIC currency passed by the caller (not the cookie-driven
        // active currency). Used by headless/REST flows that price an order in
        // a currency the request states rather than the visitor's session
        // (e.g. Orders_Controller's fixed-coupon discount).
        add_filter( 'tejcart_amount_to_currency', array( $this, 'filter_amount_to_currency' ), 20, 2 );

        // Reverse seam: convert an amount the visitor entered in the ACTIVE
        // currency back into the base currency. Used where a storefront input
        // (e.g. the price-filter min/max the shopper types in their currency)
        // must be compared against base-currency columns in SQL.
        add_filter( 'tejcart_amount_to_base', array( $this, 'filter_amount_to_base' ), 20 );
    }

    /**
     * The currency the current request prices and charges in.
     */
    private function target_currency(): string {
        $base = $this->converter->repository()->base_currency();

        if ( $this->resolver->is_admin_render() ) {
            return $base;
        }
        if ( Checkout_Controller::is_force_base_request() ) {
            return $base;
        }
        return $this->resolver->current();
    }

    /**
     * Convert a base-currency amount into the request's target currency.
     */
    private function to_target( float $base_amount ): float {
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $base_amount;
        }
        return $this->converter->convert( $base_amount, $target );
    }

    /**
     * Convert a product price source (effective / regular / sale).
     *
     * Non-numeric values pass through untouched so the
     * "empty sale_price means no sale" contract of
     * {@see \TejCart\Product\Product_Types\Abstract_Product::get_sale_price()}
     * is preserved.
     *
     * @param mixed $price Base-currency price (numeric string/float or '').
     * @param mixed $product Product instance (unused).
     * @return mixed
     */
    public function filter_product_price( $price, $product = null ) {
        unset( $product );
        if ( ! is_numeric( $price ) ) {
            return $price;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $price;
        }
        return $this->to_target( (float) $price );
    }

    /**
     * Record the currency the add-to-cart price snapshot is captured in.
     *
     * Core writes `_price_at_add` from `$product->get_price()` immediately
     * after the `tejcart_cart_item_data` filter — and our
     * {@see self::filter_product_price()} has already converted that into
     * {@see self::target_currency()} — so the snapshot and this stamp
     * always agree. {@see self::filter_cart_item_price()} reads the stamp
     * to re-base the line if the request currency later changes.
     *
     * @param mixed $data          Cart line data array.
     * @param mixed $product_id    Product ID (unused).
     * @param mixed $cart_item_key Cart line key (unused).
     * @return mixed
     */
    public function stamp_currency_at_add( $data, $product_id = 0, $cart_item_key = '' ) {
        unset( $product_id, $cart_item_key );
        if ( ! is_array( $data ) ) {
            return $data;
        }
        $data['_currency_at_add'] = $this->target_currency();
        return $data;
    }

    /**
     * Re-denominate the cart line unit price into the request's target
     * currency.
     *
     * The incoming `$price` is whatever {@see \TejCart\Cart\Cart_Item::get_price()}
     * resolved:
     *
     *  - With a snapshot (`_price_at_add` present): the price is in the
     *    currency recorded in `_currency_at_add` (or, for legacy carts
     *    created before this module, the base currency). We reverse it to
     *    base and re-convert to the target so a currency switch or a
     *    Mode-B force-base checkout re-prices the line correctly.
     *  - Without a snapshot: the price came straight from
     *    `$product->get_price()`, which {@see self::filter_product_price()}
     *    already converted to the current target — so it is a passthrough.
     *
     * @param mixed $price   Unit price as resolved by Cart_Item.
     * @param mixed $item    Cart_Item instance.
     * @param mixed $product Product instance (unused).
     * @return float
     */
    public function filter_cart_item_price( $price, $item = null, $product = null ) {
        unset( $product );
        $price = (float) $price;
        $base  = $this->converter->repository()->base_currency();
        $target = $this->target_currency();

        $from = $target;
        if ( is_object( $item ) && method_exists( $item, 'get_data' ) ) {
            $data = (array) $item->get_data();
            if ( array_key_exists( '_price_at_add', $data ) ) {
                $from = isset( $data['_currency_at_add'] ) && is_string( $data['_currency_at_add'] ) && '' !== $data['_currency_at_add']
                    ? strtoupper( $data['_currency_at_add'] )
                    : $base;
            }
        }

        if ( $from === $target ) {
            return $price < 0 ? 0.0 : $price;
        }

        $base_val  = ( $from === $base ) ? $price : $this->converter->reverse( $price, $from );
        $converted = ( $target === $base ) ? $base_val : $this->converter->convert( $base_val, $target );

        return $converted < 0 ? 0.0 : $converted;
    }

    /**
     * Convert a calculated shipping rate into the target currency.
     *
     * @param mixed $rate Base-currency shipping rate.
     * @param mixed $cart Cart instance (unused).
     * @return mixed
     */
    public function filter_shipping( $rate, $cart = null ) {
        unset( $cart );
        if ( ! is_numeric( $rate ) ) {
            return $rate;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $rate;
        }
        return $this->to_target( (float) $rate );
    }

    /**
     * Convert each cart fee's `amount` from base into the target currency.
     *
     * Fee producers (Gift_Wrap, …) add entries with a base-currency `amount`;
     * we re-denominate them so the fee that reaches the cart total — and the
     * order charged through it — is in the active currency, exactly once.
     * Non-array fees, and fees without a numeric `amount`, pass through.
     *
     * @param mixed $fees Array of fee rows.
     * @param mixed $cart Cart instance (unused).
     * @return mixed
     */
    public function filter_fees( $fees, $cart = null ) {
        unset( $cart );
        if ( ! is_array( $fees ) ) {
            return $fees;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $fees;
        }
        foreach ( $fees as $i => $fee ) {
            if ( is_array( $fee ) && isset( $fee['amount'] ) && is_numeric( $fee['amount'] ) ) {
                $fees[ $i ]['amount'] = $this->to_target( (float) $fee['amount'] );
            }
        }
        return $fees;
    }

    /**
     * Convert a base-currency monetary THRESHOLD (free-shipping threshold,
     * coupon min/max spend) into the request's target currency.
     *
     * These are gates compared against cart totals that are already in the
     * target currency, so the gate must move with the FX rate or the
     * eligibility boundary shifts for every non-base currency (e.g. a $100
     * free-shipping threshold must become €92, not stay €100). Unlike money
     * that is added to the order, a threshold is never persisted, so this is
     * a pure read-time conversion with no double-conversion risk.
     *
     * @param mixed $amount Base-currency threshold (numeric) or anything else.
     * @return mixed Converted float, or the input unchanged when non-numeric
     *               / target is the base currency.
     */
    public function filter_threshold( $amount ) {
        if ( ! is_numeric( $amount ) ) {
            return $amount;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $amount;
        }
        return $this->to_target( (float) $amount );
    }

    /**
     * Convert a fixed-amount coupon into the target currency.
     *
     * `percent` coupons carry a rate, not money — leave them alone so the
     * percentage applies against the already-converted subtotal.
     *
     * @param mixed $amount   Coupon amount in base currency.
     * @param mixed $type     Discount type slug.
     * @param mixed $coupon   Coupon data array (unused).
     * @param mixed $currency Active cart currency (unused; we resolve it ourselves).
     * @return mixed
     */
    public function filter_coupon_amount( $amount, $type = '', $coupon = array(), $currency = '' ) {
        unset( $coupon, $currency );
        if ( 'fixed_cart' !== $type && 'fixed_product' !== $type ) {
            return $amount;
        }
        if ( ! is_numeric( $amount ) ) {
            return $amount;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $amount;
        }
        return $this->to_target( (float) $amount );
    }

    /**
     * Convert a base-currency amount into an EXPLICIT target currency.
     *
     * Unlike the other hooks, the target here is supplied by the caller
     * rather than resolved from the visitor's session — for headless/REST
     * flows that transact in a currency the request states. Returns the
     * amount unchanged when the target is empty, equals the base currency,
     * or the amount is non-numeric.
     *
     * @param mixed $amount   Base-currency amount.
     * @param mixed $currency Explicit ISO target currency code.
     * @return mixed
     */
    public function filter_amount_to_currency( $amount, $currency = '' ) {
        if ( ! is_numeric( $amount ) || ! is_string( $currency ) || '' === $currency ) {
            return $amount;
        }
        $target = strtoupper( $currency );
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $amount;
        }
        return $this->converter->convert( (float) $amount, $target );
    }

    /**
     * Convert an amount expressed in the request's ACTIVE currency back into
     * the base currency (the inverse of the source conversion).
     *
     * Storefront inputs (e.g. the price-filter min/max the shopper types) are
     * in the active currency, but they must be compared against base-currency
     * columns in SQL — so reverse them here. Returns the amount unchanged when
     * the active currency is the base currency, or the value is non-numeric.
     *
     * @param mixed $amount Active-currency amount.
     * @return mixed Base-currency float, or the input unchanged.
     */
    public function filter_amount_to_base( $amount ) {
        if ( ! is_numeric( $amount ) ) {
            return $amount;
        }
        $target = $this->target_currency();
        if ( $target === $this->converter->repository()->base_currency() ) {
            return $amount;
        }
        return $this->converter->reverse( (float) $amount, $target );
    }
}
