<?php
/**
 * Cart Totals Calculator
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculates all cart totals: subtotal, discounts, shipping, tax, and grand total.
 */
class Cart_Calculator {
    /**
     * Reference to the cart being calculated.
     *
     * @var Cart
     */
    private $cart;

    /**
     * Calculated totals in integer minor units of the cart currency.
     *
     * Source-of-truth for the float-API back-compat accessors on Cart;
     * Cart::get_total() / get_subtotal() / etc. read from here and
     * convert int → float at the boundary via Currency::from_minor_units.
     * The Money mirrors below carry the same values; both are kept in
     * lock-step so any consumer can read whichever shape it needs.
     *
     * @var array<string, int>
     */
    private $totals = array(
        'subtotal'       => 0,
        'discount_total' => 0,
        'fees_total'     => 0,
        'shipping_total' => 0,
        'tax_total'      => 0,
        'total'          => 0,
    );

    /**
     * Money mirror of $totals['subtotal'], cached so calculate_discounts /
     * calculate_shipping / calculate_tax can compute against an exact
     * integer-minor-unit basis instead of re-deriving from the float.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $subtotal_money = null;

    /**
     * Money mirror of $totals['discount_total']; cached so downstream
     * stages (shipping free-threshold, tax basis) see the exact value.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $discount_money = null;

    /**
     * Money mirror of $totals['shipping_total'] from the most recent
     * calculate() pass. Survives after calculate() returns so callers
     * (notably Cart::get_shipping_total_money()) can read the exact
     * integer-minor-units value without round-tripping through the
     * float in $totals — that round trip is currency-precision-safe
     * via Currency::to_minor_units(), but reading the original Money
     * is one less hop and removes any chance of cumulative drift if
     * the same calculator is re-used across many invocations.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $shipping_money = null;

    /**
     * Money mirror of $totals['tax_total'] from the most recent
     * calculate() pass. See {@see self::$shipping_money} for the
     * rationale.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $tax_money = null;

    /**
     * Money mirror of $totals['fees_total'] (gift wrap + any other
     * tejcart_cart_fees producers) from the most recent calculate() pass.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $fees_money = null;

    /**
     * Subset of $fees_money flagged taxable, folded into the tax basis.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $taxable_fees_money = null;

    /**
     * Per-fee detail rows from the most recent calculate() pass, each
     * {id, label, amount (minor units, active currency), taxable}.
     *
     * @var array<int, array{id:string,label:string,amount:int,taxable:bool}>
     */
    private array $fees = array();

    /**
     * Money mirror of $totals['total'] from the most recent calculate()
     * pass. Computed via {@see self::compute_grand_total_money()} which
     * sums the four Money mirrors in integer minor units; the float
     * projection in $totals['total'] is a JSON-friendly view of this
     * canonical value, never the source of truth.
     *
     * @var \TejCart\Money\Money|null
     */
    private ?\TejCart\Money\Money $total_money = null;

    /**
     * Provenance of the tax figure produced by the most recent
     * {@see self::calculate_tax()} pass. Recorded so the order can persist
     * *which* engine actually priced the tax, making month-end
     * reconciliation queryable instead of guesswork:
     *
     *  - `disabled`        — store-wide tax calculation is off.
     *  - `manual`          — built-in {@see \TejCart\Tax\Tax_Manager} rate
     *                        table (no live provider active).
     *  - `live:<id>`       — an active live provider (e.g. `live:taxjar`)
     *                        answered authoritatively for this cart.
     *  - `manual_fallback` — a live provider was active but could not answer
     *                        (timeout, error, throttle, incomplete address),
     *                        so the manual rate table was used instead. This
     *                        is the value that flags potential
     *                        under/over-collection for an operator to audit.
     *  - `filter`          — the `tejcart_pre_calculate_tax` filter
     *                        short-circuited the pipeline.
     *
     * @var string
     */
    private string $tax_source = 'manual';

    /**
     * Cached resolved currency for the running calculation.
     *
     * @var string
     */
    private string $currency = 'USD';

    /**
     * Re-entrancy guard. Set while {@see self::calculate()} is in flight so a
     * recursive call (typically a shipping method's `is_available()` asking
     * the cart for its subtotal mid-calculation) returns the partially
     * computed totals instead of triggering another full pass — which would
     * recurse without bound and overflow the stack.
     *
     * @var bool
     */
    private bool $calculating = false;

    /**
     * Memoised Tax_Manager for the lifetime of this calculator instance.
     *
     * Cart_Calculator is owned 1:1 by the per-request Cart singleton, so
     * caching the manager here is request-scoped — exactly the granularity
     * we want. The manager's constructor decodes JSON-blob options on every
     * call ({@see Tax_Manager::__construct()}); reusing one instance saves
     * that work whenever a single request triggers calculate() more than
     * once (e.g. submit handler reading totals after Cart::recalculate()).
     *
     * Long-running CLI processes that mutate tax-rate options mid-run can
     * call {@see self::invalidate_caches()} to drop the cache.
     *
     * @var \TejCart\Tax\Tax_Manager|null
     */
    private ?\TejCart\Tax\Tax_Manager $tax_manager_cache = null;

    /**
     * Memoised Shipping_Manager for the lifetime of this calculator instance.
     *
     * Same lifetime reasoning as {@see self::$tax_manager_cache}; see the
     * docblock there for the invalidation contract.
     *
     * @var \TejCart\Shipping\Shipping_Manager|null
     */
    private ?\TejCart\Shipping\Shipping_Manager $shipping_manager_cache = null;

    /**
     * Memoised store currency for the lifetime of this calculator instance.
     *
     * `tejcart_currency` is autoloaded so each `get_option()` is in-process,
     * but the lookup chain (helper → option → default) still costs a few
     * function calls per calculate(). Caching once means subtotal /
     * discount / shipping / tax all read the same value without re-resolving.
     *
     * @var string|null
     */
    private ?string $currency_cache = null;

    /**
     * Calculate all totals for the given cart.
     *
     * Returns a float-projected, major-unit view of the canonical
     * integer minor-units `$totals` for back-compat with the public
     * `tejcart_after_calculate_totals` filter chain and any extension
     * that reads the array directly. New code that wants the exact
     * integer representation should use {@see self::get_totals_minor()}
     * or {@see self::get_money_totals()}.
     *
     * @param Cart $cart The cart instance.
     * @return array<string, float> Associative array of totals in
     *                              major units of the cart's currency.
     */
    public function calculate( Cart $cart ) {
        if ( $this->calculating ) {
            // Re-entry: a downstream consumer (e.g. Free_Shipping::is_available
            // calling $cart->get_subtotal() while we're still inside
            // calculate_shipping) asked for totals while this calculator is
            // already running. Return the in-flight figures so the caller
            // reads the live subtotal/discount that have already been
            // computed in this pass, without launching another nested
            // calculate() that would loop forever.
            return $this->project_totals_as_float();
        }

        $this->calculating = true;

        try {
            $this->run_calculation( $cart );
            return $this->project_totals_as_float();
        } finally {
            $this->calculating = false;
        }
    }

    /**
     * Inner calculation pipeline. Split out from {@see self::calculate()} so
     * the re-entrancy guard can wrap it cleanly.
     *
     * @param Cart $cart The cart instance.
     * @return array
     */
    private function run_calculation( Cart $cart ) {
        $this->cart = $cart;

        $this->totals = array(
            'subtotal'       => 0,
            'discount_total' => 0,
            'fees_total'     => 0,
            'shipping_total' => 0,
            'tax_total'      => 0,
            'total'          => 0,
        );
        $this->subtotal_money     = null;
        $this->discount_money     = null;
        $this->fees_money         = null;
        $this->taxable_fees_money = null;
        $this->fees               = array();
        $this->shipping_money     = null;
        $this->tax_money          = null;
        $this->total_money        = null;
        $this->tax_source         = 'manual';
        $this->currency           = $this->resolve_currency();

        /**
         * Fires before cart totals are calculated.
         *
         * @param Cart $cart The cart instance.
         */
        do_action( 'tejcart_before_calculate_totals', $this->cart );

        $this->calculate_subtotal();
        $this->calculate_discounts();
        $this->calculate_fees();
        $this->calculate_shipping();
        $this->calculate_tax();

        // shipping_money and tax_money are now set at the end of each
        // calculate_shipping() / calculate_tax() call respectively. Just
        // need to compute the grand total Money and project it into the
        // int $totals['total'] slot.
        if ( null === $this->shipping_money ) {
            $this->shipping_money = \TejCart\Money\Money::from_minor_units( (int) $this->totals['shipping_total'], $this->currency );
        }
        if ( null === $this->tax_money ) {
            $this->tax_money = \TejCart\Money\Money::from_minor_units( (int) $this->totals['tax_total'], $this->currency );
        }
        $this->total_money     = $this->compute_grand_total_money();
        $this->totals['total'] = $this->total_money->as_minor_units();

        /**
         * Fires after cart totals have been calculated.
         *
         * @param Cart  $cart   The cart instance.
         * @param array $totals The calculated totals.
         */
        do_action( 'tejcart_after_calculate_totals', $this->cart, $this->totals );

        return $this->totals;
    }

    /**
     * Calculate the subtotal by summing all line totals.
     *
     * Uses {@see Cart_Item::get_line_total_money()} so the running
     * sum is integer minor units in the cart's currency — no float
     * accumulation, so a 1k-line cart with KWD pricing produces the
     * same total as a 1-line cart with the same pre-aggregated value.
     * Supersedes the previous integer-cents-with-`× 100` fix landed
     * on main as C-2: same intent, but currency-precision-aware
     * (JPY zero-decimal, KWD/BHD three-decimal) instead of hard-coded
     * to USD/EUR-shaped two-decimal currencies.
     *
     * `$this->totals['subtotal']` holds the integer minor-units value;
     * Cart's float accessors convert int → float at the boundary via
     * Currency::from_minor_units. The `tejcart_after_calculate_totals`
     * filter still sees the same wire shape because Cart::get_subtotal()
     * does the int → float hop at the call site.
     */
    private function calculate_subtotal() {
        $this->currency = $this->resolve_currency();
        $subtotal       = \TejCart\Money\Money::zero( $this->currency );

        foreach ( $this->cart->get_items() as $item ) {
            $subtotal = $subtotal->add( $this->item_subtotal_money( $item, $this->currency ) );
        }

        $this->subtotal_money     = $subtotal;
        $this->totals['subtotal'] = $subtotal->as_minor_units();
    }

    /**
     * Resolve the cart's currency for Money operations.
     *
     * Cart_Calculator doesn't carry currency state today (it lives on
     * the Cart and on the global option). The resolved value is cached
     * for the lifetime of this calculator instance — `tejcart_currency`
     * is autoloaded so the lookup is in-process, but caching once means
     * subtotal / discount / shipping / tax all read the same value
     * without re-resolving on every stage. Long-running processes that
     * mutate the option mid-run can call {@see self::invalidate_caches()}.
     *
     * @return string ISO 4217 code.
     */
    private function resolve_currency(): string {
        if ( null !== $this->currency_cache ) {
            return $this->currency_cache;
        }

        if ( function_exists( 'tejcart_get_currency' ) ) {
            $resolved = (string) tejcart_get_currency();
        } elseif ( function_exists( 'get_option' ) ) {
            $resolved = (string) get_option( 'tejcart_currency', 'USD' );
        } else {
            $resolved = 'USD';
        }

        return $this->currency_cache = $resolved;
    }

    /**
     * Decimal places for the active cart currency. Routes through
     * Money\Currency::decimals so JPY/KRW/VND get 0, KWD/BHD/OMR/TND
     * get 3, everything else gets 2. Safe to call from any path that
     * was previously hardcoded to `round(..., 2, ...)`.
     */
    private function currency_decimals(): int {
        if ( ! class_exists( '\\TejCart\\Money\\Currency' ) ) {
            return 2;
        }
        return max( 0, (int) \TejCart\Money\Currency::decimals( $this->resolve_currency() ) );
    }

    /**
     * Memoised Tax_Manager accessor.
     *
     * Returns null when the Tax_Manager class is not available — preserves
     * the historical defensive `class_exists()` guard used by the legacy
     * direct-`new` call sites so calculate_tax()'s "no manager → use the
     * flat tejcart_tax_rate option" fallback still kicks in on stripped-down
     * test harnesses.
     *
     * @return \TejCart\Tax\Tax_Manager|null
     */
    private function tax_manager(): ?\TejCart\Tax\Tax_Manager {
        if ( null === $this->tax_manager_cache ) {
            if ( ! class_exists( '\\TejCart\\Tax\\Tax_Manager' ) ) {
                return null;
            }
            $this->tax_manager_cache = new \TejCart\Tax\Tax_Manager();
        }
        return $this->tax_manager_cache;
    }

    /**
     * Memoised Shipping_Manager accessor.
     *
     * Same null-on-missing-class semantics as {@see self::tax_manager()}.
     *
     * @return \TejCart\Shipping\Shipping_Manager|null
     */
    private function shipping_manager(): ?\TejCart\Shipping\Shipping_Manager {
        if ( null === $this->shipping_manager_cache ) {
            if ( ! class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
                return null;
            }
            $this->shipping_manager_cache = new \TejCart\Shipping\Shipping_Manager();
        }
        return $this->shipping_manager_cache;
    }

    /**
     * Drop the memoised Tax_Manager, Shipping_Manager, and currency.
     *
     * Each web request gets a fresh Cart_Calculator (it lives on the
     * per-request Cart singleton), so option changes between requests are
     * picked up automatically and most callers never need to touch this.
     *
     * Long-running processes (CLI scripts, daemons, REST consumers running
     * batch updates) that mutate `tejcart_tax_rates`,
     * `tejcart_shipping_zones`, `tejcart_tax_classes`, or `tejcart_currency`
     * mid-run and need the next calculate() call to see the new state must
     * call this method explicitly. Doing so is cheap — the next stage that
     * needs a manager will lazy-construct a fresh one.
     *
     * Intentionally has no auto-hook on `update_option_*`: registering a
     * listener from the constructor would accumulate handlers across
     * test runs and require an unhook discipline that's easy to forget.
     * An explicit call from the (rare) call site that needs it is safer
     * and self-documenting.
     */
    public function invalidate_caches(): void {
        $this->tax_manager_cache      = null;
        $this->shipping_manager_cache = null;
        $this->currency_cache         = null;
    }

    /**
     * Integer minor-units view of the most recent calculate() pass.
     *
     * This is the canonical, type-safe shape — preferred over the
     * float-projected return value of {@see self::calculate()}.
     *
     * @return array{subtotal: int, discount_total: int, shipping_total: int, tax_total: int, total: int}
     */
    public function get_totals_minor(): array {
        return array(
            'subtotal'       => (int) $this->totals['subtotal'],
            'discount_total' => (int) $this->totals['discount_total'],
            'shipping_total' => (int) $this->totals['shipping_total'],
            'tax_total'      => (int) $this->totals['tax_total'],
            'total'          => (int) $this->totals['total'],
        );
    }

    /**
     * Provenance of the tax figure from the most recent calculate() pass.
     *
     * See {@see self::$tax_source} for the vocabulary. Consumed by the
     * checkout when persisting the order so reconciliation can tell a
     * live-priced order from one that silently fell back to manual rates.
     */
    public function get_tax_source(): string {
        return $this->tax_source;
    }

    /**
     * Project the integer minor-units $totals into major-unit floats
     * for back-compat consumers. Currency-aware (JPY/KRW zero decimal,
     * KWD/BHD three decimal) via the Money decimal-string formatter.
     *
     * @return array<string, float>
     */
    private function project_totals_as_float(): array {
        $currency = $this->currency;
        $out      = array();
        foreach ( $this->totals as $slot => $minor ) {
            $out[ $slot ] = (float) \TejCart\Money\Money::from_minor_units(
                (int) $minor,
                $currency
            )->as_decimal_string();
        }
        return $out;
    }

    /**
     * Snapshot of the most recent calculate() pass as Money values.
     *
     * Returns null for any slot that has not yet been computed (e.g. if
     * called before the first calculate(), or for currencies where a
     * stage was skipped). Cart::get_*_money() reads from here so the
     * exact integer-minor-units values flow to the public Money API
     * surface — the float `$totals` array stays as a JSON-friendly
     * projection but is never the source of truth.
     *
     * @return array{subtotal: ?\TejCart\Money\Money, discount: ?\TejCart\Money\Money, shipping: ?\TejCart\Money\Money, tax: ?\TejCart\Money\Money, total: ?\TejCart\Money\Money}
     */
    public function get_money_totals(): array {
        return array(
            'subtotal' => $this->subtotal_money,
            'discount' => $this->discount_money,
            'fees'     => $this->fees_money,
            'shipping' => $this->shipping_money,
            'tax'      => $this->tax_money,
            'total'    => $this->total_money,
        );
    }

    /**
     * Per-fee detail rows from the most recent calculate() pass, each
     * {id, label, amount (minor units, active currency), taxable}. Used by
     * the cart templates to render an itemised fee line.
     *
     * @return array<int, array{id:string,label:string,amount:int,taxable:bool}>
     */
    public function get_fee_lines(): array {
        return $this->fees;
    }

    /**
     * Get the line subtotal for a cart item as a Money in the given
     * currency. Tolerates the historical mixed shapes (Cart_Item
     * instance, plain stdClass with a get_subtotal()/get_line_total(),
     * raw array) so the migration is safe to land before every caller
     * is rewritten.
     *
     * @param mixed  $item     Cart item.
     * @param string $currency ISO 4217 code.
     * @return \TejCart\Money\Money
     */
    private function item_subtotal_money( $item, string $currency ): \TejCart\Money\Money {
        if ( is_object( $item ) && method_exists( $item, 'get_line_total_money' ) ) {
            $money = $item->get_line_total_money( $currency );
            if ( $money instanceof \TejCart\Money\Money ) {
                return $money;
            }
        }

        // Float-shaped fallback. Round once at the boundary using
        // banker's rounding to match Money's convention.
        $line_total = (float) $this->get_item_line_total( $item );
        $multiplier = \TejCart\Money\Currency::multiplier( $currency );
        $minor      = (int) round( $line_total * $multiplier, 0, PHP_ROUND_HALF_EVEN );

        return \TejCart\Money\Money::from_minor_units( $minor, $currency );
    }

    /**
     * Whether a free-shipping coupon has been applied.
     *
     * @var bool
     */
    private $has_free_shipping_coupon = false;

    /**
     * Calculate discount totals from applied coupons.
     */
    private function calculate_discounts() {
        $this->has_free_shipping_coupon = false;
        $subtotal_money                 = $this->subtotal_money ?? \TejCart\Money\Money::zero( $this->currency );
        $discount                       = \TejCart\Money\Money::zero( $this->currency );
        $coupons                        = $this->cart->get_coupons();

        foreach ( $coupons as $coupon ) {
            if ( ! is_array( $coupon ) ) {
                continue;
            }

            $type          = isset( $coupon['discount_type'] ) ? $coupon['discount_type'] : 'fixed_cart';
            $amount        = isset( $coupon['amount'] ) ? (float) $coupon['amount'] : 0.0;
            $exclude_sales = ! empty( $coupon['exclude_sale_items'] );

            /**
             * Filter a coupon's discount amount before it is applied.
             *
             * The amount is denominated in the store base currency as
             * stored on the coupon. A multi-currency switcher hooks this to
             * convert fixed-amount coupons (`fixed_cart` / `fixed_product`)
             * into the active cart currency ($currency) so a "10 off"
             * coupon discounts the right magnitude after FX conversion.
             *
             * Implementations MUST leave `percent` coupons untouched — for
             * those the value is a rate (e.g. 10 = 10%), not a monetary
             * amount, and is applied against the already-converted subtotal.
             * The $type and $currency are passed so hooks can branch safely.
             *
             * @since 1.0.0
             * @param float  $amount   Coupon amount in base currency.
             * @param string $type     Discount type slug.
             * @param array  $coupon   Full coupon data array.
             * @param string $currency Active cart currency code.
             */
            $amount = (float) apply_filters( 'tejcart_coupon_amount', $amount, $type, $coupon, $this->currency );

            if ( 'free_shipping' === $type || ! empty( $coupon['free_shipping'] ) ) {
                $this->has_free_shipping_coupon = true;

                if ( 'free_shipping' === $type ) {
                    continue;
                }
            }

            /**
             * Filter the discount Money for a single coupon.
             *
             * Third-party plugins implementing custom coupon types (BOGO,
             * tiered percentage, geographic) return a non-null
             * `\TejCart\Money\Money` instance to compute the discount
             * themselves. Returning null falls through to the built-in
             * percent / fixed_product / fixed_cart logic.
             *
             * @param \TejCart\Money\Money|null $custom        Plugin-computed discount, or null.
             * @param array                     $coupon        Coupon data array.
             * @param \TejCart\Money\Money      $subtotal_money Cart subtotal.
             * @param Cart                      $cart          Cart instance.
             */
            $custom = apply_filters(
                'tejcart_calculate_coupon_discount',
                null,
                $coupon,
                $subtotal_money,
                $this->cart
            );

            if ( $custom instanceof \TejCart\Money\Money ) {
                $discount = $discount->add( $custom );
                continue;
            }

            if ( 'percent' === $type ) {
                $basis    = $this->get_coupon_basis_subtotal_money( $coupon, $exclude_sales, $subtotal_money );
                $discount = $discount->add( $basis->multiply_percent( $this->percent_to_rate_string( $amount ) ) );
                continue;
            }

            if ( 'fixed_product' === $type ) {
                $discount = $discount->add( $this->calculate_fixed_product_discount_money( $amount, $exclude_sales, $coupon ) );
                continue;
            }

            $discount = $discount->add( $this->amount_to_money( $amount ) );
        }

        $exceeds = $discount->as_minor_units() > $subtotal_money->as_minor_units();
        if ( $exceeds && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf(
                    'Cart discount (%s) exceeded subtotal (%s); clamped to prevent negative total.',
                    $discount->as_decimal_string(),
                    $subtotal_money->as_decimal_string()
                ),
                'info'
            );
        }

        $this->discount_money           = $exceeds ? $subtotal_money : $discount;
        $this->totals['discount_total'] = $this->discount_money->as_minor_units();
    }

    /**
     * Compute the grand total in Money: subtotal − discount + shipping
     * + tax, clamped at zero.
     *
     * Subtotal and discount are already exact Money values from
     * {@see self::calculate_subtotal()} / {@see self::calculate_discounts()}.
     * Shipping and tax are still computed in floats (separate follow-up
     * commits in the Money migration); they cross the boundary here via
     * {@see self::amount_to_money()} so the final sum is exact integer
     * minor units rather than a four-way float addition with a final
     * round( …, 2 ).
     *
     * @return \TejCart\Money\Money
     */
    private function compute_grand_total_money(): \TejCart\Money\Money {
        $subtotal = $this->subtotal_money ?? \TejCart\Money\Money::zero( $this->currency );
        $discount = $this->discount_money ?? \TejCart\Money\Money::zero( $this->currency );
        // Prefer the Money mirrors set just before this method is called;
        // fall back to the float projection so callers that invoke this
        // outside the standard calculate() pipeline (tests, partial
        // recomputes) still get a sane answer.
        $shipping = $this->shipping_money ?? $this->amount_to_money( (float) $this->totals['shipping_total'] );
        $tax      = $this->tax_money ?? $this->amount_to_money( (float) $this->totals['tax_total'] );
        $fees     = $this->fees_money ?? \TejCart\Money\Money::zero( $this->currency );

        $total = $subtotal->subtract( $discount )->add( $fees )->add( $shipping )->add( $tax );

        if ( $total->is_negative() ) {
            return \TejCart\Money\Money::zero( $this->currency );
        }

        return $total;
    }

    /**
     * Format a percent figure (e.g. 20 = 20%, 12.5 = 12.5%) as a Money
     * rate string that {@see \TejCart\Money\Money::multiply_percent}
     * accepts. Uses fixed 10-decimal precision then trims trailing
     * zeros — enough headroom for any realistic coupon rate without
     * carrying float drift into the rate itself.
     *
     * @param float $percent Coupon percent (0..100, possibly fractional).
     * @return string Decimal rate string.
     */
    private function percent_to_rate_string( float $percent ): string {
        $rate    = sprintf( '%.10F', $percent / 100 );
        $trimmed = rtrim( rtrim( $rate, '0' ), '.' );
        if ( '' === $trimmed || '-' === $trimmed ) {
            return '0';
        }
        return $trimmed;
    }

    /**
     * Convert a coupon amount float to a Money in the resolved currency.
     *
     * @param float $amount
     * @return \TejCart\Money\Money
     */
    private function amount_to_money( float $amount ): \TejCart\Money\Money {
        $multiplier = \TejCart\Money\Currency::multiplier( $this->currency );
        $minor      = (int) round( $amount * $multiplier, 0, PHP_ROUND_HALF_EVEN );

        return \TejCart\Money\Money::from_minor_units( $minor, $this->currency );
    }

    /**
     * Return the subtotal of only non-sale line items, as a Money in
     * the running currency.
     *
     * @return \TejCart\Money\Money
     */
    private function get_non_sale_subtotal_money(): \TejCart\Money\Money {
        if ( ! is_object( $this->cart ) || ! method_exists( $this->cart, 'get_items' ) ) {
            return $this->subtotal_money ?? \TejCart\Money\Money::zero( $this->currency );
        }

        $subtotal = \TejCart\Money\Money::zero( $this->currency );

        foreach ( $this->cart->get_items() as $item ) {
            if ( $this->item_is_on_sale( $item ) ) {
                continue;
            }

            $subtotal = $subtotal->add( $this->item_subtotal_money( $item, $this->currency ) );
        }

        return $subtotal;
    }

    /**
     * Float-returning facade kept for any external caller that may have
     * relied on the previous shape. Internally delegates to the Money
     * computation so callers see the same number from either entry.
     *
     * @return float
     */
    private function get_non_sale_subtotal(): float {
        return (float) $this->get_non_sale_subtotal_money()->as_decimal_string();
    }

    /**
     * Compute a fixed_product discount as Money: $amount off each
     * matching line item, capped per-line at the line's own subtotal
     * so a single coupon never produces a negative line.
     *
     * @param float $amount        Per-item discount amount.
     * @param bool  $exclude_sales When true, skip sale-priced line items.
     * @param array $coupon        Coupon data array (carries 'coupon_id', 'code').
     * @return \TejCart\Money\Money
     */
    private function calculate_fixed_product_discount_money( float $amount, bool $exclude_sales, array $coupon = array() ): \TejCart\Money\Money {
        $discount = \TejCart\Money\Money::zero( $this->currency );

        if ( ! is_object( $this->cart ) || ! method_exists( $this->cart, 'get_items' ) ) {
            return $discount;
        }

        $per_item = $this->amount_to_money( $amount );

        foreach ( $this->cart->get_items() as $item ) {
            if ( $exclude_sales && $this->item_is_on_sale( $item ) ) {
                continue;
            }

            // Honour product/category restrictions: a restricted coupon must
            // only discount the lines it actually applies to, not every line.
            if ( ! $this->coupon_line_applies( $item, $coupon ) ) {
                continue;
            }

            $qty = 0;
            if ( is_object( $item ) && method_exists( $item, 'get_quantity' ) ) {
                $qty = (int) $item->get_quantity();
            } elseif ( is_array( $item ) && isset( $item['quantity'] ) ) {
                $qty = (int) $item['quantity'];
            }
            $qty = max( 0, $qty );

            $line_total      = $this->item_subtotal_money( $item, $this->currency );
            $proposed        = $per_item->multiply( $qty );
            $allocated_minor = min( $proposed->as_minor_units(), $line_total->as_minor_units() );

            $discount = $discount->add(
                \TejCart\Money\Money::from_minor_units( $allocated_minor, $this->currency )
            );
        }

        return $discount;
    }

    /**
     * Float-returning facade. Delegates to the Money computation.
     *
     * @param float $amount         Per-item discount amount.
     * @param bool  $exclude_sales  When true, skip sale-priced line items.
     * @param array $coupon         Coupon data array (carries 'coupon_id', 'code').
     * @return float
     */
    private function calculate_fixed_product_discount( float $amount, bool $exclude_sales, array $coupon = array() ): float {
        return (float) $this->calculate_fixed_product_discount_money( $amount, $exclude_sales, $coupon )->as_decimal_string();
    }

    /**
     * Whether a coupon discounts a given cart line.
     *
     * Default true (no restriction). Product/category-restricted coupons —
     * e.g. Advanced Coupons — hook `tejcart_coupon_line_applies` to return
     * false for lines outside the coupon's include/exclude scope, so the
     * discount is confined to the lines it genuinely applies to instead of
     * spraying across the whole cart.
     *
     * @param mixed $item   Cart line item.
     * @param array $coupon Coupon data array.
     * @return bool
     */
    private function coupon_line_applies( $item, array $coupon ): bool {
        /**
         * Filter whether a coupon's discount applies to a single cart line.
         *
         * @param bool  $applies Whether the coupon discounts this line. Default true.
         * @param mixed $item    Cart line item.
         * @param array $coupon  Coupon data array (carries 'coupon_id', 'code').
         * @param mixed $cart    Cart instance.
         */
        return (bool) apply_filters( 'tejcart_coupon_line_applies', true, $item, $coupon, $this->cart );
    }

    /**
     * Subtotal that a percentage coupon should discount, as Money.
     *
     * Sums only the lines the coupon applies to (respecting the
     * sale-exclusion flag). When no restriction trims any line — the common
     * unrestricted case — it returns the precomputed subtotal mirrors so
     * existing rounding is preserved exactly.
     *
     * @param array                $coupon        Coupon data array.
     * @param bool                 $exclude_sales When true, skip sale-priced lines.
     * @param \TejCart\Money\Money  $subtotal      Full cart subtotal.
     * @return \TejCart\Money\Money
     */
    private function get_coupon_basis_subtotal_money( array $coupon, bool $exclude_sales, \TejCart\Money\Money $subtotal ): \TejCart\Money\Money {
        $unrestricted = $exclude_sales ? $this->get_non_sale_subtotal_money() : $subtotal;

        if ( ! is_object( $this->cart ) || ! method_exists( $this->cart, 'get_items' ) ) {
            return $unrestricted;
        }

        $basis      = \TejCart\Money\Money::zero( $this->currency );
        $restricted = false;

        foreach ( $this->cart->get_items() as $item ) {
            if ( $exclude_sales && $this->item_is_on_sale( $item ) ) {
                continue;
            }
            if ( ! $this->coupon_line_applies( $item, $coupon ) ) {
                $restricted = true;
                continue;
            }
            $basis = $basis->add( $this->item_subtotal_money( $item, $this->currency ) );
        }

        // No line was trimmed — keep the precomputed subtotal's exact rounding.
        return $restricted ? $basis : $unrestricted;
    }

    /**
     * Detect whether a cart item's product is currently on sale.
     *
     * @param mixed $item Cart item.
     * @return bool
     */
    private function item_is_on_sale( $item ): bool {
        $product = null;

        if ( is_object( $item ) && method_exists( $item, 'get_product' ) ) {
            $product = $item->get_product();
        }

        if ( ! is_object( $product ) || ! method_exists( $product, 'is_on_sale' ) ) {
            return false;
        }

        return (bool) $product->is_on_sale();
    }

    /**
     * Get the line total for a cart item, falling back across shapes.
     *
     * @param mixed $item Cart item.
     * @return float
     */
    private function get_item_line_total( $item ): float {
        if ( is_object( $item ) && method_exists( $item, 'get_subtotal' ) ) {
            return (float) $item->get_subtotal();
        }

        if ( is_array( $item ) ) {
            if ( isset( $item['line_total'] ) ) {
                return (float) $item['line_total'];
            }

            if ( isset( $item['price'], $item['quantity'] ) ) {
                return (float) $item['price'] * (int) $item['quantity'];
            }
        }

        return 0.0;
    }

    /**
     * Collect cart-level fees (gift wrap, handling, …) via the
     * `tejcart_cart_fees` filter and fold them into the totals.
     *
     * Each fee row carries an `amount` already in the active currency — the
     * currency switcher converts the base-currency amounts at the filter
     * (priority 30) before we see them, so we only quantize to the currency's
     * minor-unit grid here (no second conversion). Taxable fees are mirrored
     * into $taxable_fees_money so {@see self::calculate_tax()} can fold them
     * into the basis; the fee total is added to the grand total in
     * {@see self::compute_grand_total_money()}.
     */
    private function calculate_fees(): void {
        $this->fees               = array();
        $this->fees_money         = \TejCart\Money\Money::zero( $this->currency );
        $this->taxable_fees_money = \TejCart\Money\Money::zero( $this->currency );

        /**
         * Filter the cart-level fees. Producers append rows shaped
         * array{id:string,label:string,amount:float,taxable:bool}; `amount`
         * is in the store (base) currency at the point of addition and is
         * converted to the active currency by the currency switcher.
         *
         * @param array $fees Fee rows (default empty).
         * @param Cart  $cart The cart instance.
         */
        $fees = apply_filters( 'tejcart_cart_fees', array(), $this->cart );
        if ( ! is_array( $fees ) ) {
            $this->totals['fees_total'] = 0;
            return;
        }

        foreach ( $fees as $fee ) {
            if ( ! is_array( $fee ) || ! isset( $fee['amount'] ) || ! is_numeric( $fee['amount'] ) ) {
                continue;
            }
            $amount = (float) $fee['amount'];
            if ( $amount <= 0 ) {
                continue;
            }
            $money            = $this->amount_to_money( $amount );
            $this->fees_money = $this->fees_money->add( $money );
            $taxable          = ! empty( $fee['taxable'] );
            if ( $taxable ) {
                $this->taxable_fees_money = $this->taxable_fees_money->add( $money );
            }
            $this->fees[] = array(
                'id'      => isset( $fee['id'] ) ? (string) $fee['id'] : '',
                'label'   => isset( $fee['label'] ) ? (string) $fee['label'] : '',
                'amount'  => $money->as_minor_units(),
                'taxable' => $taxable,
            );
        }

        $this->totals['fees_total'] = $this->fees_money->as_minor_units();
    }

    /**
     * Calculate shipping costs using Shipping_Manager zones and methods.
     *
     * Falls back to the flat-rate option when no zone matches or the
     * Shipping_Manager class is not available.
     */
    private function calculate_shipping() {
        if ( ! $this->cart->needs_shipping() ) {
            $this->set_shipping_money_zero();
            return;
        }

        if ( 'yes' !== get_option( 'tejcart_enable_shipping', 'no' ) ) {
            $this->set_shipping_money_zero();
            return;
        }

        if ( $this->has_free_shipping_coupon ) {
            $this->set_shipping_money_zero();
            return;
        }

        // Free-shipping threshold check stays in float because the option
        // is stored as a free-form float. The threshold is stored in the
        // shop (base) currency, but the (subtotal − discount) net it is
        // compared against (the Money mirrors below) is in the ACTIVE
        // currency — so the threshold is routed through the
        // `tejcart_free_shipping_threshold` filter, which the currency
        // switcher hooks to convert base→active. Without that, a $100
        // threshold would be compared against a €92 net and wrongly withhold
        // free shipping for every non-base currency. On a single-currency
        // store the filter is a passthrough.
        $free_threshold = (float) apply_filters(
            'tejcart_free_shipping_threshold',
            (float) get_option( 'tejcart_shipping_free_threshold', 0 ),
            $this->cart
        );
        if ( $free_threshold > 0 ) {
            $net_money    = ( $this->subtotal_money ?? \TejCart\Money\Money::zero( $this->currency ) )
                ->subtract( $this->discount_money ?? \TejCart\Money\Money::zero( $this->currency ) );
            $net_major    = (float) $net_money->as_decimal_string();
            if ( $net_major >= $free_threshold ) {
                $this->set_shipping_money_zero();
                return;
            }
        }

        $rate            = null;
        $default_method  = get_option( 'tejcart_default_shipping_method', 'flat_rate' );
        $default_flat    = (float) get_option( 'tejcart_shipping_flat_rate', 0 );

        $shipping_manager = $this->shipping_manager();
        if ( null !== $shipping_manager ) {
            $country          = $this->get_customer_shipping_country();
            $state            = $this->get_customer_shipping_state();
            $postcode         = $this->get_customer_shipping_postcode();
            $chosen_method    = $this->cart->get_chosen_shipping_method();

            $methods = $shipping_manager->get_available_methods( $country, $state, $this->cart, $postcode );

            if ( ! empty( $chosen_method ) && ! empty( $methods ) ) {
                foreach ( $methods as $method_instance ) {
                    if ( method_exists( $method_instance, 'get_id' )
                         && $method_instance->get_id() === $chosen_method ) {
                        $rate = $method_instance->calculate( $this->cart );
                        break;
                    }
                }

                if ( null === $rate && method_exists( $this->cart, 'set_chosen_shipping_method' ) ) {
                    $this->cart->set_chosen_shipping_method( '' );
                }
            }

            if ( null === $rate && ! empty( $methods ) ) {
                $first_method = reset( $methods );
                $rate         = $first_method->calculate( $this->cart );
            }

            // The strict (country + state + postcode) lookup above can
            // return no methods even when the merchant has clearly set
            // up a zone for the buyer's country — e.g. the zone has
            // postcode rules that exclude the buyer's postcode, the
            // buyer hasn't typed a postcode yet, or the only matching
            // zone is state-prefixed and the cart's destination is in
            // a different state. The radio-method picker in
            // templates/checkout/checkout.php renders against a
            // country-only lookup, so without a relaxation pass here
            // the buyer sees e.g. "Flat Rate $5.00" selected in the
            // picker while the order-summary totals row charges the
            // global $10 "Default Shipping Cost" — exactly the
            // mismatch reported by merchants. Scan the configured
            // zones for ANY zone whose countries cover the buyer's
            // country (ignoring postcode rules), and use its first
            // method so the totals row tracks what the buyer is
            // already seeing selected. The global default stays
            // reserved for the genuine "no zone configured for this
            // country" case.
            if ( null === $rate && '' !== $country ) {
                foreach ( $shipping_manager->get_zones() as $zone ) {
                    if ( ! is_array( $zone ) || empty( $zone['countries'] ) || ! is_array( $zone['countries'] ) ) {
                        continue;
                    }
                    $country_in_zone = false;
                    foreach ( $zone['countries'] as $entry ) {
                        $entry = (string) $entry;
                        if ( $entry === $country || strpos( $entry, $country . ':' ) === 0 ) {
                            $country_in_zone = true;
                            break;
                        }
                    }
                    if ( ! $country_in_zone || empty( $zone['methods'] ) || ! is_array( $zone['methods'] ) ) {
                        continue;
                    }
                    foreach ( $zone['methods'] as $method_config ) {
                        $instance = $this->build_zone_method_instance( $method_config );
                        if ( null === $instance ) {
                            continue;
                        }
                        if ( method_exists( $instance, 'is_available' ) && ! $instance->is_available( $this->cart ) ) {
                            continue;
                        }
                        $rate = (float) $instance->calculate( $this->cart );
                        break 2;
                    }
                }
            }
        }

        if ( null === $rate ) {
            if ( 'free' === $default_method || 'local_pickup' === $default_method ) {
                $rate = 0.0;
            } else {
                $rate = $default_flat;
            }
        }

        /**
         * Filters the calculated shipping total.
         *
         * @param float $rate The shipping rate.
         * @param Cart  $cart The cart instance.
         */
        $rate = (float) apply_filters( 'tejcart_calculated_shipping', $rate, $this->cart );

        $class_fees = get_option( 'tejcart_shipping_class_fees', array() );
        if ( is_string( $class_fees ) ) {
            $class_fees = json_decode( $class_fees, true );
        }
        if ( is_array( $class_fees ) && ! empty( $class_fees ) && method_exists( $this->cart, 'get_items' ) ) {
            foreach ( $this->cart->get_items() as $item ) {
                if ( ! method_exists( $item, 'get_shipping_class' ) ) {
                    continue;
                }
                $class = $item->get_shipping_class();
                if ( '' === $class || ! isset( $class_fees[ $class ] ) ) {
                    continue;
                }
                // Audit L-22: validate numeric before applying; log
                // bad entries so admin debugging is easier.
                if ( ! is_numeric( $class_fees[ $class ] ) ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( sprintf( 'Shipping class fee for "%s" is non-numeric (%s) — skipped.', $class, gettype( $class_fees[ $class ] ) ), 'warning' );
                    }
                    continue;
                }
                $rate += (float) $class_fees[ $class ] * (int) $item->get_quantity();
            }
        }

        /**
         * Filter the shipping total after shipping-class surcharges.
         *
         * @param float $rate
         * @param Cart  $cart
         */
        $rate = (float) apply_filters( 'tejcart_calculated_shipping_with_classes', $rate, $this->cart );

        // Shipping providers return float rates in major units of the
        // shop currency; route through Currency::to_minor_units so the
        // banker's-rounding and currency-decimal handling lives in one
        // place. JPY/KRW/VND get 0 dp, KWD/BHD/OMR/TND get 3 dp, most
        // currencies get 2 dp — the rounding mode matches the Money
        // value object so a long shipping fee tail rounds identically
        // across the cart pipeline.
        $shipping_minor      = \TejCart\Money\Currency::to_minor_units( (float) $rate, $this->currency );
        $this->shipping_money = \TejCart\Money\Money::from_minor_units( $shipping_minor, $this->currency );
        $this->totals['shipping_total'] = $shipping_minor;
    }

    /**
     * Build a shipping-method instance from a raw zone-method config
     * row for the country-relaxation pass inside calculate_shipping().
     *
     * Shipping_Manager keeps its own `create_method_instance()` private
     * (it's an internal implementation detail of the strict
     * get_available_methods() pipeline), so this helper mirrors just
     * enough of that wiring — class map + setting hydration + the
     * `tejcart_shipping_method_classes` and `tejcart_shipping_method_instance`
     * filters — to instantiate a zone's method without re-importing
     * the strict address-matching code path. Returns null when the
     * config row has no id, the id has no registered class, or the
     * class is unavailable.
     *
     * @param mixed $config Method config row from a zone definition.
     * @return \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method|null
     */
    private function build_zone_method_instance( $config ): ?\TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method {
        if ( ! is_array( $config ) ) {
            return null;
        }
        $id = isset( $config['id'] ) ? (string) $config['id'] : '';
        if ( '' === $id ) {
            return null;
        }

        $map = array(
            'flat_rate'     => '\\TejCart\\Shipping\\Shipping_Methods\\Flat_Rate',
            'free_shipping' => '\\TejCart\\Shipping\\Shipping_Methods\\Free_Shipping',
            'local_pickup'  => '\\TejCart\\Shipping\\Shipping_Methods\\Local_Pickup',
            'weight_based'  => '\\TejCart\\Shipping\\Shipping_Methods\\Weight_Based',
        );

        /** This filter is documented in src/Shipping/Shipping_Manager.php */
        $map = apply_filters( 'tejcart_shipping_method_classes', $map );

        if ( ! is_array( $map ) || ! isset( $map[ $id ] ) || ! class_exists( $map[ $id ] ) ) {
            return null;
        }

        $class    = $map[ $id ];
        $settings = isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();
        $instance = new $class();
        if ( method_exists( $instance, 'set_settings' ) ) {
            $instance->set_settings( $settings );
        }

        /** This filter is documented in src/Shipping/Shipping_Manager.php */
        $instance = apply_filters( 'tejcart_shipping_method_instance', $instance, $id, $config );

        return $instance instanceof \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method ? $instance : null;
    }

    /**
     * Helper: set shipping to zero in both projections.
     */
    private function set_shipping_money_zero(): void {
        $this->shipping_money           = \TejCart\Money\Money::zero( $this->currency );
        $this->totals['shipping_total'] = 0;
    }

    /**
     * Calculate tax using Tax_Manager for per-country/state rates.
     *
     * Supports compound taxes and tax on shipping when configured.
     * Falls back to the flat tejcart_tax_rate option when Tax_Manager
     * is not available.
     */
    private function calculate_tax() {
        $tax_enabled = get_option( 'tejcart_enable_tax', 'no' );

        if ( 'yes' !== $tax_enabled ) {
            $this->tax_debug( 'tax_registry', 'calculate_tax: tejcart_enable_tax=no → tax_total=0' );
            $this->tax_source          = 'disabled';
            $this->totals['tax_total'] = 0;
            $this->tax_money           = \TejCart\Money\Money::zero( $this->currency );
            return;
        }

        // Compute the taxable basis from the Money mirrors so the
        // integer source-of-truth is used (the $totals projection is
        // already the same int value, but this makes the read explicit).
        // Tax_Manager and the public `tejcart_pre_calculate_tax` /
        // `tejcart_calculated_tax` filters are float-typed (legacy API),
        // so we expose float at those boundaries and round back to int
        // at the final assignment.
        $subtotal_money = $this->subtotal_money ?? \TejCart\Money\Money::zero( $this->currency );
        $discount_money = $this->discount_money ?? \TejCart\Money\Money::zero( $this->currency );
        $taxable_money  = $subtotal_money->subtract( $discount_money );
        // Fold any taxable cart fees (e.g. a taxable gift-wrap surcharge) into
        // the basis. Non-taxable fees — the gift-wrap default — leave this a
        // no-op since $taxable_fees_money is then zero.
        if ( null !== $this->taxable_fees_money ) {
            $taxable_money = $taxable_money->add( $this->taxable_fees_money );
        }
        $taxable_amount = (float) $taxable_money->as_decimal_string();
        $shipping_float = (float) ( $this->shipping_money
            ?? \TejCart\Money\Money::from_minor_units( (int) $this->totals['shipping_total'], $this->currency )
        )->as_decimal_string();

        $this->tax_debug( 'tax_registry', 'calculate_tax: starting', array(
            'taxable_amount' => $taxable_amount,
            'subtotal'       => $taxable_amount + (float) $discount_money->as_decimal_string(),
            'discount_total' => (float) $discount_money->as_decimal_string(),
        ) );

        /**
         * Pre-calculation tax filter — lets a third-party provider (TaxJar,
         * Avalara, Stripe Tax) compute the tax amount and short-circuit the
         * default Tax_Manager rate-table logic.
         *
         * Return a non-null float (the tax amount in cart currency) to take
         * over. Return null to fall through to Tax_Manager.
         *
         * @param float|null $tax            Pre-computed tax amount, or null.
         * @param float      $taxable_amount Subtotal minus discounts.
         * @param Cart       $cart           Current cart instance.
         */
        $pre = apply_filters( 'tejcart_pre_calculate_tax', null, $taxable_amount, $this->cart );

        if ( null !== $pre ) {
            $this->tax_source = 'filter';
            $this->tax_debug( 'tax_registry', 'calculate_tax: tejcart_pre_calculate_tax filter short-circuited the pipeline', array( 'pre' => (float) $pre ) );
        }

        if ( null === $pre && class_exists( '\\TejCart\\Tax\\Tax_Provider_Registry' ) ) {
            $provider = \TejCart\Tax\Tax_Provider_Registry::get_active();
            if ( null !== $provider ) {
                $tax_based_on = get_option( 'tejcart_tax_based_on', 'billing_address' );
                if ( 'billing_address' === $tax_based_on ) {
                    $ctx_country = $this->get_customer_billing_country();
                    $ctx_state   = $this->get_customer_billing_state();
                } elseif ( 'shipping_address' === $tax_based_on ) {
                    $ctx_country = $this->get_customer_shipping_country();
                    $ctx_state   = $this->get_customer_shipping_state();
                } else {
                    $ctx_country = (string) get_option( 'tejcart_shipping_origin_country', get_option( 'tejcart_store_country', 'US' ) );
                    $ctx_state   = (string) get_option( 'tejcart_store_state', '' );
                }

                if ( 'shipping_address' === $tax_based_on ) {
                    $ctx_postcode = $this->get_customer_shipping_postcode();
                } elseif ( 'billing_address' === $tax_based_on ) {
                    $ctx_postcode = $this->get_customer_billing_postcode();
                } else {
                    $ctx_postcode = (string) get_option( 'tejcart_store_postcode', '' );
                }

                $ctx_city  = '';
                $ctx_line1 = '';
                if ( method_exists( $this->cart, 'get_shipping_destination' ) ) {
                    $destination = $this->cart->get_shipping_destination();
                    $ctx_city    = (string) ( $destination['city'] ?? '' );
                    $ctx_line1   = (string) ( $destination['line1'] ?? '' );
                }

                // Detect the request type so the provider can gate billable
                // upstream calls — by default we only call through on the
                // checkout page. See {@see \TejCart\Tax_Providers\Page_Context},
                // which lives in the bundled `tax-providers` module and is
                // only present when the module is enabled. Captured here (not
                // just inline below) because the fallback classification needs
                // it too — see the null branch.
                $detected_page = class_exists( '\\TejCart\\Tax_Providers\\Page_Context' )
                    ? \TejCart\Tax_Providers\Page_Context::detect()
                    : '';

                $context = array(
                    'country'            => $ctx_country,
                    'state'              => $ctx_state,
                    'postcode'           => $ctx_postcode,
                    'city'               => $ctx_city,
                    'line1'              => $ctx_line1,
                    'shipping_total'     => $shipping_float,
                    // Audit #5 / 03 #1 — checkbox values are stored
                    // as the strings 'yes'/'no'. A plain `(bool)`
                    // cast returned true for BOTH values because
                    // any non-empty string is truthy in PHP. Match
                    // the canonical readers in src/functions.php:505
                    // and src/Tax/Tax_Manager.php:427.
                    'prices_include_tax' => 'yes' === get_option( 'tejcart_prices_include_tax', 'no' ),
                    'cart'               => $this->cart,
                    'page'               => $detected_page,
                );

                try {
                    $pre = $provider->calculate( $taxable_amount, $context );
                } catch ( \Throwable $e ) {
                    $this->tax_debug(
                        'tax_' . $provider->get_id(),
                        'calculate_tax: provider->calculate() threw → falling back to Tax_Manager',
                        array(
                            'exception' => get_class( $e ),
                            'message'   => $e->getMessage(),
                        )
                    );
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf( 'Tax provider %s threw: %s — falling back to Tax_Manager.', $provider->get_id(), $e->getMessage() ),
                            'warning'
                        );
                    }
                    $pre = null;
                }

                if ( null === $pre ) {
                    // A live provider returns null both when it genuinely
                    // could not answer (timeout, error, throttle, incomplete
                    // address) AND when it is deliberately page-gated off for
                    // this request (the default config only calls through at
                    // checkout, so cart-page calcs always come back null by
                    // design). Only the former is a reconciliation concern.
                    //
                    // Mirror the default provider gate (Page_Context::is_allowed
                    // under `checkout_only`, which clears `checkout` and the
                    // unclassified-`ajax` checkout recalc) so we flag a failover
                    // exactly when the provider was actually expected to price
                    // this cart. This excludes cart-page renders (the dominant
                    // false positive) while still catching checkout placements
                    // that arrive as an AJAX POST. Stores on a non-default
                    // `cart_*` setting that fail on the cart page are not
                    // flagged — under-alarming is safer than crying wolf daily.
                    $is_real_failover = in_array( $detected_page, array( 'checkout', 'ajax' ), true );

                    if ( $is_real_failover ) {
                        $this->tax_source = 'manual_fallback';
                        $this->tax_debug(
                            'tax_' . $provider->get_id(),
                            'calculate_tax: provider returned null at checkout → falling back to Tax_Manager rate table'
                        );

                        /**
                         * Fires when an active live tax provider could not price
                         * a checkout cart and the built-in manual rate table was
                         * used instead.
                         *
                         * This is the operator's signal that tax may be
                         * mis-collected for the affected orders: the manual table
                         * rarely matches a live engine's jurisdiction logic. Hook
                         * it to alerting, or rely on the bundled tax-providers
                         * module which raises a deduplicated admin notice.
                         *
                         * @param string $provider_id Active provider id (e.g. `taxjar`).
                         * @param Cart   $cart        The cart being priced.
                         */
                        do_action( 'tejcart_tax_provider_fell_back', $provider->get_id(), $this->cart );
                    } else {
                        // By-design skip (e.g. cart page under checkout-only):
                        // this is a normal manual display calc, not a failover.
                        $this->tax_source = 'manual';
                        $this->tax_debug(
                            'tax_' . $provider->get_id(),
                            'calculate_tax: provider not consulted on this page → using Tax_Manager rate table',
                            array( 'page' => $detected_page )
                        );
                    }
                } else {
                    $this->tax_source = 'live:' . $provider->get_id();
                    $this->tax_debug(
                        'tax_' . $provider->get_id(),
                        'calculate_tax: provider returned tax amount',
                        array( 'tax' => (float) $pre )
                    );
                }
            } else {
                $this->tax_debug( 'tax_registry', 'calculate_tax: no active live provider — using Tax_Manager rate table' );
            }
        }

        if ( null !== $pre ) {
            $tax = (float) $pre;
            /** Documented below in the default-path branch. */
            $tax                       = (float) apply_filters( 'tejcart_calculated_tax', $tax, $taxable_amount, $this->cart );
            $tax                       = max( 0.0, $tax );
            $this->tax_money           = $this->amount_to_money( $tax );
            $this->totals['tax_total'] = $this->tax_money->as_minor_units();
            $this->tax_debug( 'tax_registry', 'calculate_tax: final tax_total set from live provider', array( 'tax_total' => $this->totals['tax_total'] ) );
            return;
        }

        $tax_manager = $this->tax_manager();
        if ( null !== $tax_manager ) {

            /**
             * Filter whether line-item prices are tax-inclusive for this cart.
             *
             * Lets integrations override the site-wide
             * tejcart_prices_include_tax option on a per-cart basis (e.g.
             * B2B checkouts where prices are entered gross but displayed
             * net to a verified business customer).
             *
             * @since 1.0.0
             *
             * @param bool $inclusive Whether prices are tax-inclusive.
             * @param Cart $cart      Current cart instance.
             */
            $prices_include_tax = (bool) apply_filters(
                'tejcart_line_item_tax_inclusive',
                $tax_manager->prices_include_tax(),
                $this->cart
            );

            $tax_based_on = get_option( 'tejcart_tax_based_on', 'billing_address' );

            if ( 'billing_address' === $tax_based_on ) {
                $country = $this->get_customer_billing_country();
                $state   = $this->get_customer_billing_state();
            } elseif ( 'shipping_address' === $tax_based_on ) {
                $country = $this->get_customer_shipping_country();
                $state   = $this->get_customer_shipping_state();
            } else {
                $country = (string) get_option( 'tejcart_shipping_origin_country', '' );
                if ( '' === $country ) {
                    $country = (string) get_option( 'tejcart_store_country', 'US' );
                }
                $state   = get_option( 'tejcart_store_state', '' );
            }

            $rate_data = $tax_manager->get_tax_rate( $country, $state );

            if ( ! empty( $rate_data ) ) {
                $tax_rate_pct = (float) $rate_data['rate'];

                if ( $prices_include_tax && $tax_rate_pct > 0 ) {
                    // Tax-inclusive: catalogue prices already contain the
                    // tax, so we strip it back out in integer arithmetic
                    // via Money::strip_inclusive_tax. The rate is held in
                    // basis points (1 bp = 0.01%) so 8.875% becomes 888
                    // bp after banker-rounding. The 0.005% loss at this
                    // scale is well below the cent for any realistic
                    // subtotal; an exact rate would need a rational-
                    // arithmetic library which violates B2.
                    //
                    // CRITICAL: use Money::inclusive_tax_portion to derive
                    // the tax, NOT a float recompute of `net * rate%`.
                    // The integer-derived net rounds via banker's, which
                    // can land 1 minor unit away from `floor(net * rate%)`
                    // — recomputing tax in float would then make the
                    // grand total (net + tax) differ from the gross the
                    // customer saw on the catalogue page. The companion
                    // `inclusive_tax_portion` uses the same banker's
                    // denominator so `gross == net + tax` exactly.
                    $rate_bp = (int) round( $tax_rate_pct * 100, 0, PHP_ROUND_HALF_EVEN );

                    // Honour per-product tax classes on the inclusive path.
                    // Stripping a mixed-rate cart (e.g. 20% standard beside a
                    // 5% reduced line) at one store-wide rate reports the
                    // wrong embedded tax. Tax_Calculator allocates the
                    // authoritative gross totals across lines and strips each
                    // at its own class rate; it returns null — and we keep the
                    // exact single-rate strip below — when every line uses the
                    // default rate, so the common single-class cart is
                    // byte-identical to before.
                    $per_line = Tax_Calculator::per_line_inclusive(
                        $this->cart,
                        $subtotal_money,
                        $discount_money,
                        $rate_bp,
                        $tax_manager,
                        $country,
                        $state
                    );

                    if ( null !== $per_line ) {
                        $tax_portion_money    = $per_line['tax'];
                        $this->subtotal_money = $per_line['net_subtotal'];
                        $this->discount_money = $per_line['net_discount'];
                    } else {
                        $gross_taxable_money  = $subtotal_money->subtract( $discount_money );
                        $tax_portion_money    = $gross_taxable_money->inclusive_tax_portion( $rate_bp );
                        $this->subtotal_money = $subtotal_money->strip_inclusive_tax( $rate_bp );
                        $this->discount_money = $discount_money->strip_inclusive_tax( $rate_bp );
                    }

                    $this->totals['subtotal']       = $this->subtotal_money->as_minor_units();
                    $this->totals['discount_total'] = $this->discount_money->as_minor_units();

                    // SEC-003: stay in Money / minor-unit land for the
                    // entire inclusive-tax branch. Previously we cast
                    // $tax_portion_money to float here and then re-
                    // applied float math for the compound / shipping
                    // tax, which silently lost precision on JPY (0 dp)
                    // and KWD (3 dp). Compute shipping tax via
                    // Money::multiply_percent (banker's rounding inside
                    // Money) and add as Money before converting once at
                    // the very end of the branch.
                    if ( ( ! empty( $rate_data['compound'] ) && 'yes' === $rate_data['compound'] )
                         || ( ! empty( $rate_data['shipping'] ) && 'yes' === $rate_data['shipping'] ) ) {
                        $shipping_money    = $this->shipping_money
                            ?? \TejCart\Money\Money::from_minor_units( (int) $this->totals['shipping_total'], $this->currency );
                        $rate_decimal      = sprintf( '%.6f', $tax_rate_pct / 100 );
                        $shipping_tax      = $shipping_money->multiply_percent( $rate_decimal );
                        $tax_portion_money = $tax_portion_money->add( $shipping_tax );
                    }

                    $tax = (float) $tax_portion_money->as_decimal_string();
                } elseif ( ! empty( $rate_data['compound'] ) && 'yes' === $rate_data['compound'] ) {
                    // F-CCM-002: compound-tax base (taxable + shipping) via Money VO so
                    // JPY (0 dp) and KWD/BHD/OMR (3 dp) round correctly.
                    $compound_base_money = $taxable_money->add(
                        $this->shipping_money
                            ?? \TejCart\Money\Money::from_minor_units( (int) $this->totals['shipping_total'], $this->currency )
                    );
                    $rate_str = sprintf( '%.10F', $tax_rate_pct / 100 );
                    $tax      = (float) $compound_base_money->multiply_percent( $rate_str )->as_decimal_string();
                } else {
                    // Audit #6 / 03 #2 — fallback aligned with the UI
                    // checkbox default ('no'/unchecked) so a fresh
                    // install rounds per-line (matches what the admin
                    // sees in Settings → Tax). Installer now seeds
                    // 'no' too, eliminating the no-default fork.
                    if ( 'yes' === get_option( 'tejcart_tax_round_at_subtotal', 'no' ) ) {
                        // F-CCM-002: exclusive-tax subtotal path via Money VO.
                        $rate_str = sprintf( '%.10F', $tax_rate_pct / 100 );
                        $tax      = (float) $taxable_money->multiply_percent( $rate_str )->as_decimal_string();
                    } else {
                        $tax = $this->calculate_per_line_tax( $tax_rate_pct, $tax_manager, $country, $state );
                    }
                }

                if ( ! $prices_include_tax
                     && ! empty( $rate_data['shipping'] ) && 'yes' === $rate_data['shipping']
                     && ( empty( $rate_data['compound'] ) || 'yes' !== $rate_data['compound'] ) ) {
                    // F-CCM-002: shipping-tax path via Money VO (same fix — no float multiply).
                    $shipping_tax_money = (
                        $this->shipping_money
                            ?? \TejCart\Money\Money::from_minor_units( (int) $this->totals['shipping_total'], $this->currency )
                    )->multiply_percent( sprintf( '%.10F', $tax_rate_pct / 100 ) );
                    $tax += (float) $shipping_tax_money->as_decimal_string();
                }
            } else {
                $tax = 0.0;

                /**
                 * Filter: rate (%) used to strip embedded tax from a gross
                 * subtotal when the customer's destination has no matching
                 * tax rule. Return `0` (default) to leave the gross subtotal
                 * untouched; return e.g. `20.0` to subtract a 20 % VAT
                 * that was baked into the advertised prices.
                 *
                 * @since 1.0.0
                 *
                 * @param float $rate_pct Percentage rate to strip. Default 0.
                 * @param Cart  $cart     Current cart instance.
                 */
                $strip_rate = (float) apply_filters(
                    'tejcart_strip_embedded_tax_when_untaxed',
                    0.0,
                    $this->cart
                );

                if ( $prices_include_tax && $strip_rate > 0 ) {
                    // Same strip path as the matched-rate branch above but
                    // sourced from the user-supplied filter rate. Integer
                    // arithmetic via Money::strip_inclusive_tax keeps the
                    // VAT-strip exact across multi-decimal currencies.
                    $strip_bp                       = (int) round( $strip_rate * 100, 0, PHP_ROUND_HALF_EVEN );
                    $this->subtotal_money           = $subtotal_money->strip_inclusive_tax( $strip_bp );
                    $this->discount_money           = $discount_money->strip_inclusive_tax( $strip_bp );
                    $this->totals['subtotal']       = $this->subtotal_money->as_minor_units();
                    $this->totals['discount_total'] = $this->discount_money->as_minor_units();
                }
            }
        } else {
            $tax_rate = (float) get_option( 'tejcart_tax_rate', 0.0 );
            // H-6: banker's rounding (matches Money VO subtotal/discount).
            // Currency-aware decimal count — see shipping rate comment.
            $tax = round(
                $taxable_amount * ( $tax_rate / 100 ),
                $this->currency_decimals(),
                PHP_ROUND_HALF_EVEN
            );
        }

        /**
         * Filters the calculated tax total.
         *
         * @param float $tax  The tax amount.
         * @param float $taxable_amount The taxable amount.
         * @param Cart  $cart The cart instance.
         */
        $tax = (float) apply_filters( 'tejcart_calculated_tax', $tax, $taxable_amount, $this->cart );
        $tax = max( 0.0, $tax );

        // Convert the final float tax to int minor units once, at the
        // boundary. tax_money is the canonical source of truth for
        // compute_grand_total_money(); the int $totals slot is the
        // back-compat projection that Cart's float accessors read from.
        $this->tax_money           = $this->amount_to_money( $tax );
        $this->totals['tax_total'] = $this->tax_money->as_minor_units();
    }

    /**
     * Apply a tax rate per-line-item, rounding each line before summing.
     *
     * Used when the "Round tax at subtotal level" setting is disabled —
     * tiny rounding differences versus the subtotal approach are the
     * point of this mode. When a Tax_Manager and location are supplied,
     * each line's product tax class is resolved against the rate table
     * so reduced/zero-rate products tax correctly next to standard items.
     *
     * @param float                             $tax_rate_pct Default rate as percentage (fallback for lines with no class match).
     * @param \TejCart\Tax\Tax_Manager|null     $tax_manager  Optional manager for per-class lookup.
     * @param string                            $country      Customer country for lookup.
     * @param string                            $state        Customer state for lookup.
     * @return float Summed per-line tax.
     */
    private function calculate_per_line_tax( float $tax_rate_pct, $tax_manager = null, string $country = '', string $state = '' ): float {
        // #1202: delegated to Tax_Calculator. The pure math now lives
        // in src/Cart/Tax_Calculator.php so future tax refactors
        // (#1244) only have one body to touch.
        //
        // H-10: pass the cart currency through so per-line rounding
        // honours JPY (0 decimals) and KWD/BHD/OMR (3 decimals).
        return Tax_Calculator::per_line_tax(
            $this->cart,
            (float) $this->totals['subtotal'],
            (float) $this->totals['discount_total'],
            $tax_rate_pct,
            $tax_manager,
            $country,
            $state,
            $this->currency
        );
    }

    /**
     * Get the customer billing country from the session or posted data.
     *
     * @return string Country code.
     */
    private function get_customer_billing_country() {
        $country = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_billing_country' ) ) {
                $country = $customer->get_billing_country();
            }
        }

        if ( empty( $country ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $country     = (string) ( $destination['country'] ?? '' );
        }

        return ! empty( $country ) ? sanitize_text_field( $country ) : get_option( 'tejcart_store_country', 'US' );
    }

    /**
     * Get the customer billing state from the session or posted data.
     *
     * @return string State code.
     */
    private function get_customer_billing_state() {
        $state = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_billing_state' ) ) {
                $state = $customer->get_billing_state();
            }
        }

        if ( empty( $state ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $state       = (string) ( $destination['state'] ?? '' );
        }

        return sanitize_text_field( $state );
    }

    /**
     * Get the customer billing postcode from the session or posted data.
     *
     * @return string Postcode / ZIP.
     */
    private function get_customer_billing_postcode() {
        $postcode = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_billing_postcode' ) ) {
                $postcode = $customer->get_billing_postcode();
            }
        }

        if ( empty( $postcode ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $postcode    = (string) ( $destination['postcode'] ?? '' );
        }

        return sanitize_text_field( $postcode );
    }

    /**
     * Get the customer shipping country from the session or posted data.
     *
     * @return string Country code.
     */
    private function get_customer_shipping_country() {
        $country = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_shipping_country' ) ) {
                $country = $customer->get_shipping_country();
            }
        }

        if ( empty( $country ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $country     = (string) ( $destination['country'] ?? '' );
        }

        return ! empty( $country ) ? sanitize_text_field( $country ) : get_option( 'tejcart_store_country', 'US' );
    }

    /**
     * Get the customer shipping state from the session or posted data.
     *
     * @return string State code.
     */
    private function get_customer_shipping_state() {
        $state = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_shipping_state' ) ) {
                $state = $customer->get_shipping_state();
            }
        }

        if ( empty( $state ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $state       = (string) ( $destination['state'] ?? '' );
        }

        return sanitize_text_field( $state );
    }

    /**
     * Get the customer shipping postcode from the session or posted data.
     *
     * @return string Postcode / ZIP.
     */
    private function get_customer_shipping_postcode() {
        $postcode = '';

        if ( method_exists( $this->cart, 'get_customer' ) ) {
            $customer = $this->cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_shipping_postcode' ) ) {
                $postcode = $customer->get_shipping_postcode();
            }
        }

        if ( empty( $postcode ) && method_exists( $this->cart, 'get_shipping_destination' ) ) {
            $destination = $this->cart->get_shipping_destination();
            $postcode    = (string) ( $destination['postcode'] ?? '' );
        }

        return sanitize_text_field( $postcode );
    }

    /**
     * Emit a debug-level tax-pipeline log line to a per-channel file.
     *
     * Routes to `{uploads}/tejcart-logs/<source>-<date>-<hash>.log`. The
     * `tax_registry` channel captures pipeline-level decisions (filter
     * short-circuits, provider selection, fallbacks); per-provider channels
     * (`tax_taxjar`, `tax_avalara`, `tax_stripe_tax`) capture the decisions
     * made by `Abstract_Live_Tax_Provider::calculate()`.
     *
     * Always-on — `tejcart_tax_log()` bypasses `tejcart_log_level` so tax
     * pipeline anomalies stay visible without flipping a global toggle.
     *
     * @param string               $source  Log channel (e.g. `tax_taxjar`).
     * @param string               $event   Short human-readable description.
     * @param array<string, mixed> $context Additional structured fields.
     */
    private function tax_debug( string $source, string $event, array $context = array() ): void {
        if ( ! function_exists( 'tejcart_tax_log' ) ) {
            return;
        }
        tejcart_tax_log( $source, $event, $context );
    }
}
