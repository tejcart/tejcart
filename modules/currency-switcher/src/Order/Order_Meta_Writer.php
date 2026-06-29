<?php
/**
 * Records FX metadata on every new order so refunds + financial
 * reports can recover the base-currency totals.
 *
 * @package TejCart\Currency_Switcher\Order
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Order;

use TejCart\Currency_Switcher\Checkout\Checkout_Controller;
use TejCart\Currency_Switcher\Conversion\Converter;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Options;
use TejCart\Order\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hooks `tejcart_new_order` (fires once per insert in Order_Factory)
 * and `tejcart_order_created` (post-checkout) and writes:
 *
 *   _tejcart_csw_fx_rate            effective rate at order creation
 *   _tejcart_csw_order_currency     the display currency the order was placed in
 *   _tejcart_csw_base_currency      the store base currency
 *   _tejcart_csw_base_total         total / rate
 *   _tejcart_csw_base_tax_total     tax / rate
 *   _tejcart_csw_base_shipping_total shipping / rate
 *   _tejcart_csw_base_net_total     (total - tax - shipping) / rate
 *
 * Idempotent via a static per-request guard so the two hooks together
 * never produce duplicate meta rows.
 */
final class Order_Meta_Writer {
    /**
     * FIFO-evicting per-request idempotency guard.
     *
     * A long-running CLI import (e.g. wp tejcart import that crunches
     * 50k orders in a single PHP process) would otherwise grow this
     * array unbounded for the whole run. Cap at 4096 entries with FIFO
     * eviction — well above any single web request's working set and
     * still tiny in memory (≈48 bytes per int key under PHP 8.2).
     *
     * @var array<int, true>
     */
    private static array $processed = array();
    private const PROCESSED_MAX = 4096;

    public function register(): void {
        add_filter( 'tejcart_order_data_before_insert', array( $this, 'filter_order_data' ), 20, 2 );
        add_action( 'tejcart_new_order',     array( $this, 'record_for_order' ), 20, 2 );
        add_action( 'tejcart_order_created', array( $this, 'record_for_order' ), 20, 2 );
        add_action( 'tejcart_order_refund_created', array( $this, 'inherit_for_refund' ), 20, 3 );
    }

    /**
     * Make the order row's `currency` column match the currency in
     * which `total` (and friends) are denominated.
     *
     * Cart_Calculator computes totals through `tejcart_get_currency()`,
     * which our `tejcart_currency` filter swaps to the active display
     * currency in Mode A, and {@see \TejCart\Currency_Switcher\Conversion\Source_Conversion}
     * converts the underlying line/shipping/coupon amounts so the total
     * is genuinely an EUR-denominated value (not the base figure wearing
     * an EUR label). Core's `Checkout::process()` then writes the
     * `currency` column directly from the option (bypassing the filter)
     * — so without this filter the row reads `currency='USD'` while the
     * `total` is an EUR amount. Refunds, gateways, and reports all read
     * `Order::get_currency()` and would silently disagree with the stored
     * amount. With both halves in place, `base_total = total / rate` in
     * {@see self::record_for_order()} reconciles back to the base figure.
     *
     * In Mode B (force base at checkout) the cart totals are already in
     * the base currency, so the row's currency is correct as-is.
     *
     * Only intervenes when `currency` is missing or matches the base
     * currency — never overwrites a caller that explicitly set its own.
     *
     * @param array $data  Column-level order data.
     * @param array $items Line items (unused; kept for filter signature).
     */
    public function filter_order_data( $data, $items = array() ): array {
        unset( $items );
        if ( ! is_array( $data ) ) {
            return array();
        }
        if ( Checkout_Controller::is_force_base_request() ) {
            return $data;
        }

        $converter = new Converter();
        $base      = $converter->repository()->base_currency();
        $cfg       = $converter->active_config();
        $code      = $cfg?->code ?? $base;
        if ( $code === $base ) {
            return $data;
        }

        // Stamp FX columns when the order currency is missing, equals the
        // base currency (a caller that left it at the default), OR already
        // equals the active display currency (core now sets this directly via
        // tejcart_get_currency() in Checkout::process()). Only bail when a
        // caller explicitly set some OTHER currency we have no rate for —
        // overwriting that would be wrong.
        $existing = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : '';
        if ( '' !== $existing && $existing !== $base && $existing !== $code ) {
            return $data;
        }

        $data['currency'] = $code;

        // Stamp the base currency + effective rate so core's
        // Order::sync_base_amounts() can derive the consolidated
        // base-currency settlement columns (base_total, base_subtotal, …)
        // for cross-currency reporting. `fx_rate` is the base→display rate,
        // matching Currency::to_base_minor()'s convention (base = display /
        // fx_rate).
        $data['base_currency'] = $base;
        $rate                  = $cfg?->effective_rate() ?? 1.0;
        if ( $rate > 0.0 ) {
            $data['fx_rate'] = (string) $rate;
        }

        return $data;
    }

    /**
     * Persist FX meta for an order. Safe to call multiple times — the
     * static guard short-circuits and the writer deletes existing meta
     * first to keep HPOS-style repeated rows out.
     *
     * @param int       $order_id
     * @param Order|null $order
     */
    public function record_for_order( int $order_id, $order = null ): void {
        if ( $order_id <= 0 ) {
            return;
        }
        if ( isset( self::$processed[ $order_id ] ) ) {
            return;
        }
        // FIFO eviction once the cap is hit. array_shift() on the
        // first key drops the oldest entry; PHP preserves insertion
        // order so this gives us a bounded LRU-ish cache for free.
        if ( count( self::$processed ) >= self::PROCESSED_MAX ) {
            array_shift( self::$processed );
        }
        self::$processed[ $order_id ] = true;

        if ( ! $order instanceof Order ) {
            $order = function_exists( 'tejcart_get_order' )
                ? tejcart_get_order( $order_id )
                : new Order( $order_id );
        }
        if ( ! $order instanceof Order ) {
            return;
        }

        // Persistent guard: skip if the rate was already stamped on a
        // prior request. The static $processed cache only guards
        // re-entry within a single PHP request; a second request that
        // fires tejcart_order_created (typically PayPal webhook
        // reconciliation re-running the order lifecycle when a capture
        // confirms server-side after the buyer left the original
        // request) would otherwise re-enter here and overwrite
        // _tejcart_csw_fx_rate with the CURRENT rate. Refunds then
        // reverse at the wrong rate.
        $stamped = (string) $order->get_meta( Options::ORDER_META_RATE );
        if ( '' !== $stamped ) {
            self::$processed[ $order_id ] = true;
            return;
        }

        $converter = new Converter();
        $base      = $converter->repository()->base_currency();

        // Mode B (force base at checkout) means the order was actually
        // charged in the base currency even if the customer was browsing
        // in EUR. Stamp rate=1.0 / order_currency=base so refunds reverse
        // through the right rate rather than dividing by the EUR rate.
        if ( Checkout_Controller::is_force_base_request() ) {
            $cfg  = null;
            $code = $base;
            $rate = 1.0;
        } else {
            $cfg  = $converter->active_config();
            $code = $cfg?->code ?? $base;
            $rate = $cfg?->effective_rate() ?? 1.0;
        }
        unset( $cfg );

        if ( $rate <= 0.0 ) {
            // Pathological config — refuse to divide by zero. Fall back
            // to base currency without FX scaling so the order at least
            // has consistent meta.
            $rate = 1.0;
            $code = $base;
        }

        $total    = $this->order_total( $order );
        $tax      = $this->order_tax( $order );
        $shipping = $this->order_shipping( $order );
        $net      = $total - $tax - $shipping;

        // Delete first to avoid duplicate-row drift on HPOS-style stores
        // that key meta by (order_id, meta_key) without UNIQUE indexing.
        foreach ( $this->meta_keys() as $key ) {
            $order->delete_meta( $key );
        }

        $order->update_meta( Options::ORDER_META_RATE,            (string) $rate );
        $order->update_meta( Options::ORDER_META_ORDER_CURRENCY,  $code );
        $order->update_meta( Options::ORDER_META_BASE_CURRENCY,   $base );
        $order->update_meta( Options::ORDER_META_BASE_TOTAL,      $this->fmt( $total / $rate, $base ) );
        $order->update_meta( Options::ORDER_META_BASE_TAX_TOTAL,  $this->fmt( $tax / $rate, $base ) );
        $order->update_meta( Options::ORDER_META_BASE_SHIP_TOTAL, $this->fmt( $shipping / $rate, $base ) );
        $order->update_meta( Options::ORDER_META_BASE_NET_TOTAL,  $this->fmt( $net / $rate, $base ) );
    }

    /**
     * Mirror the parent order's effective rate onto the refund so the
     * refund row carries the same FX context. Skips silently if the
     * parent has no rate yet (e.g. an order created before the module
     * was enabled).
     *
     * Order_Refund has no meta API, so we store per-refund FX records
     * as order meta on the parent keyed by refund ID. This gives each
     * refund an independently addressable FX record without requiring
     * a schema migration.
     *
     * @param mixed $refund   Refund record returned by Order_Manager.
     * @param int   $order_id Parent order ID.
     * @param mixed $reason   Reason string (unused).
     */
    public function inherit_for_refund( $refund, int $order_id, $reason = null ): void {
        if ( $order_id <= 0 ) {
            return;
        }
        $order = function_exists( 'tejcart_get_order' )
            ? tejcart_get_order( $order_id )
            : null;
        if ( ! $order instanceof Order ) {
            return;
        }

        $rate = $order->get_meta( Options::ORDER_META_RATE );
        if ( null === $rate || '' === $rate ) {
            return;
        }

        $rate_float     = (float) $rate;
        $order_currency = (string) $order->get_meta( Options::ORDER_META_ORDER_CURRENCY );
        $base_currency  = (string) $order->get_meta( Options::ORDER_META_BASE_CURRENCY );

        $refund_id = is_object( $refund ) && isset( $refund->id ) ? (int) $refund->id : 0;
        if ( $refund_id > 0 ) {
            $prefix = '_tejcart_csw_refund_' . $refund_id;
            $order->update_meta( $prefix . '_fx_rate',       (string) $rate_float );
            $order->update_meta( $prefix . '_currency',      $order_currency );
            $order->update_meta( $prefix . '_base_currency', $base_currency );
        }

        do_action( 'tejcart_csw_refund_fx_recorded', $refund, $order_id, $rate_float );
    }

    /**
     * @return string[]
     */
    private function meta_keys(): array {
        return array(
            Options::ORDER_META_RATE,
            Options::ORDER_META_ORDER_CURRENCY,
            Options::ORDER_META_BASE_CURRENCY,
            Options::ORDER_META_BASE_TOTAL,
            Options::ORDER_META_BASE_TAX_TOTAL,
            Options::ORDER_META_BASE_SHIP_TOTAL,
            Options::ORDER_META_BASE_NET_TOTAL,
        );
    }

    private function order_total( Order $order ): float {
        return (float) ( method_exists( $order, 'get_total' ) ? $order->get_total() : 0.0 );
    }

    private function order_tax( Order $order ): float {
        if ( method_exists( $order, 'get_total_tax' ) ) {
            return (float) $order->get_total_tax();
        }
        if ( method_exists( $order, 'get_tax_total' ) ) {
            return (float) $order->get_tax_total();
        }
        return 0.0;
    }

    private function order_shipping( Order $order ): float {
        if ( method_exists( $order, 'get_shipping_total' ) ) {
            return (float) $order->get_shipping_total();
        }
        return 0.0;
    }

    /**
     * Format a base-currency float for meta storage.
     *
     * F-MODS-010: The previous implementation read `tejcart_num_decimals`
     * (the store display setting) to determine decimal precision. For 3-
     * decimal currencies (KWD/BHD/OMR) and 0-decimal currencies (JPY/KRW)
     * this produces a string with the wrong number of minor units.
     * `Currency::multiplier()` is the canonical source of minor-unit
     * precision; we derive decimal places as log10(multiplier) so JPY
     * formats as "1234" and KWD as "12.345".
     *
     * Falls back to the `tejcart_num_decimals` option when the Currency
     * class is unavailable (e.g. partial install).
     *
     * @param float  $value         Amount in base currency.
     * @param string $currency_code ISO-4217 base currency code.
     */
    private function fmt( float $value, string $currency_code = '' ): string {
        $decimals = 2; // safe default

        if ( '' !== $currency_code && class_exists( '\\TejCart\\Money\\Currency' ) ) {
            $multiplier = \TejCart\Money\Currency::multiplier( $currency_code );
            // log10(1)=0, log10(100)=2, log10(1000)=3
            $decimals = $multiplier > 0 ? (int) round( log10( $multiplier ) ) : 0;
        } else {
            $decimals = (int) get_option( 'tejcart_num_decimals', 2 );
        }

        return number_format( $value, $decimals, '.', '' );
    }

    /** Test helper — drops the per-request idempotency guard. */
    public static function reset_processed(): void {
        self::$processed = array();
    }
}
