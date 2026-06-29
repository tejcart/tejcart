<?php
/**
 * Bridge between the checkout/order pipeline and Selected_Quote_Registry.
 *
 * When a Carrier_Driven_Method prices a cart it records the chosen Rate_Quote
 * in Selected_Quote_Registry — but core's Abstract_Shipping_Method::calculate()
 * only returns a float price, so the carrier-specific `rate_id` token never
 * reaches the order without an explicit bridge.
 *
 * This class:
 *
 *  - On `tejcart_checkout_update_order_meta` (fired right after the order
 *    is created and the chosen shipping method is on the cart session) it
 *    looks up the recorded quote for the order's chosen method and writes
 *    the carrier_id, service_code, rate_id, cost, currency and ETA onto
 *    the order's meta. Subsequent label-purchase calls read that meta to
 *    redeem the right token without re-quoting.
 *
 *  - On `tejcart_order_status_changed` to a configurable "ready to ship"
 *    status it fires `tejcart_shipping_order_ready_to_ship` so merchants
 *    (or other plugins) can auto-purchase labels via Action Scheduler.
 *
 * The persisted meta keys are public:
 *
 *   _tejcart_shipping_carrier_id   string  ("fedex")
 *   _tejcart_shipping_service_code string  ("FEDEX_GROUND")
 *   _tejcart_shipping_rate_id      string  ("rate_abc123" — empty for
 *                                            rate-card-only carriers)
 *   _tejcart_shipping_cost_cents   int     (rate cost in minor units)
 *   _tejcart_shipping_currency     string  (ISO-4217)
 *   _tejcart_shipping_eta_days     int|null
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Integration {
    public const META_CARRIER_ID   = '_tejcart_shipping_carrier_id';
    public const META_SERVICE_CODE = '_tejcart_shipping_service_code';
    public const META_RATE_ID      = '_tejcart_shipping_rate_id';
    public const META_COST_CENTS   = '_tejcart_shipping_cost_cents';
    public const META_CURRENCY     = '_tejcart_shipping_currency';
    public const META_ETA_DAYS     = '_tejcart_shipping_eta_days';
    public const META_METHOD_ID    = '_tejcart_shipping_method_id';

    private Selected_Quote_Registry $selected_quotes;

    public function __construct( Selected_Quote_Registry $selected_quotes ) {
        $this->selected_quotes = $selected_quotes;
    }

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'tejcart_checkout_update_order_meta', array( $this, 'persist_quote_to_order' ), 20, 2 );
        // Express-checkout paths (PayPal Express, Auth.Net Google Pay
        // Express) build orders via `Order_Factory::create()` directly
        // and bypass `tejcart_checkout_update_order_meta`. Bind the
        // same persistence on `tejcart_new_order` too so carrier-quote
        // meta (rate_id, carrier_id, service_code, cost_cents) lands
        // on express orders too — otherwise the label-purchase flow
        // re-quotes (extra carrier API hit) or fails entirely.
        add_action( 'tejcart_new_order', array( $this, 'persist_quote_for_new_order' ), 20, 2 );
        add_action( 'tejcart_order_status_changed', array( $this, 'fire_ready_to_ship' ), 10, 3 );
    }

    /**
     * Wrapper for `tejcart_new_order` whose signature is
     * `( int $order_id, Order $order )` — adapts it to the
     * `persist_quote_to_order( $order_id, $posted_data )` shape with
     * an empty posted_data (express paths have no canonical
     * posted_data; the method-id is read from order meta written by
     * the express handler).
     *
     * Idempotent: writing the same meta twice (when both this hook
     * and `tejcart_checkout_update_order_meta` fire for a single
     * canonical-pipeline order) is a no-op.
     *
     * @param int   $order_id
     * @param mixed $order    Unused — `persist_quote_to_order` hydrates from the id.
     */
    public function persist_quote_for_new_order( $order_id, $order = null ): void {
        unset( $order );
        $this->persist_quote_to_order( (int) $order_id, array() );
    }

    /**
     * Look up the quote selected for the order's shipping method and
     * write it onto the order's meta table. Called on the
     * `tejcart_checkout_update_order_meta` hook after core's
     * Shipping_Method_Capture has already saved `_shipping_method`.
     *
     * @param int   $order_id    Order ID.
     * @param array $posted_data Sanitized POST data (unused).
     */
    public function persist_quote_to_order( $order_id, $posted_data ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $order = $this->load_order( $order_id );
        if ( null === $order ) {
            return;
        }

        $method_id = $this->resolve_method_id( $order, $posted_data );
        if ( '' === $method_id ) {
            return;
        }

        // Only carrier-driven methods are interesting here.
        if ( 0 !== strpos( $method_id, 'carrier_' ) ) {
            return;
        }

        $quote = $this->selected_quotes->get( $method_id );
        if ( null === $quote ) {
            return;
        }

        $this->write_meta( $order, self::META_METHOD_ID,    $method_id );
        $this->write_meta( $order, self::META_CARRIER_ID,   $quote->carrier_id );
        $this->write_meta( $order, self::META_SERVICE_CODE, $quote->service_code );
        $this->write_meta( $order, self::META_RATE_ID,      (string) ( $quote->rate_id ?? '' ) );
        $this->write_meta( $order, self::META_COST_CENTS,   (int) $quote->cost_cents );
        $this->write_meta( $order, self::META_CURRENCY,     $quote->currency );
        $this->write_meta( $order, self::META_ETA_DAYS,     null === $quote->eta_days ? '' : (int) $quote->eta_days );

        /**
         * Fires after a carrier-driven quote has been persisted on an order.
         *
         * @param int        $order_id
         * @param Rate_Quote $quote
         * @param string     $method_id
         */
        do_action( 'tejcart_shipping_quote_persisted', $order_id, $quote, $method_id );
    }

    /**
     * Fire a "ready to ship" hook when an order moves into a status that
     * the merchant has configured as the trigger for label purchase.
     *
     * Defaults to `processing` (matches core's "paid, awaiting fulfilment"
     * state). Filterable so a merchant who fulfils on `completed` or via
     * a manual gate can override.
     *
     * @param string $old_status
     * @param string $new_status
     * @param mixed  $order
     */
    public function fire_ready_to_ship( $old_status, $new_status, $order ): void {
        $trigger = (string) apply_filters(
            'tejcart_shipping_ready_to_ship_status',
            'processing'
        );
        if ( (string) $new_status !== $trigger ) {
            return;
        }
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }

        $carrier_id = (string) $this->read_meta( $order, self::META_CARRIER_ID );
        if ( '' === $carrier_id ) {
            return;
        }

        /**
         * Fires when an order with a carrier-driven shipping method has
         * reached its "ready to ship" status. Listeners typically enqueue
         * a background job that calls Label_Service::purchase() with the
         * persisted rate_id.
         *
         * @param int    $order_id
         * @param string $carrier_id
         * @param mixed  $order
         */
        do_action( 'tejcart_shipping_order_ready_to_ship', $order_id, $carrier_id, $order );
    }

    /**
     * Resolve the chosen shipping method id for an order. Prefers the
     * `_shipping_method` meta written by core's Shipping_Method_Capture;
     * falls back to express-path-specific meta keys (PayPal Express
     * writes `_shipping_method_id`, Auth.Net Google Pay writes
     * `_tejcart_authnet_express_shipping_option`) and finally the
     * `tejcart_shipping_method` field in posted data.
     *
     * @param mixed $order
     * @param mixed $posted_data
     */
    private function resolve_method_id( $order, $posted_data ): string {
        $stored = $this->read_meta( $order, '_shipping_method' );
        if ( is_string( $stored ) && '' !== $stored ) {
            return $stored;
        }
        // Fallback meta keys used by express-checkout paths that
        // bypass Shipping_Method_Capture.
        foreach ( array( '_shipping_method_id', '_tejcart_authnet_express_shipping_option' ) as $express_key ) {
            $express = $this->read_meta( $order, $express_key );
            if ( is_string( $express ) && '' !== $express ) {
                return $express;
            }
        }
        if ( is_array( $posted_data ) && ! empty( $posted_data['tejcart_shipping_method'] ) ) {
            return (string) $posted_data['tejcart_shipping_method'];
        }
        return '';
    }

    /**
     * Load the order for `$order_id`. Protected so the unit tests can
     * override it with a fake without needing to spin up a wpdb.
     *
     * @return mixed
     */
    protected function load_order( int $order_id ) {
        // F-MODL-009: Use tejcart_get_order() instead of newing up \TejCart\Order\Order.
        if ( ! function_exists( 'tejcart_get_order' ) ) {
            return null;
        }
        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || (int) $order->get_id() <= 0 ) {
            return null;
        }
        return $order;
    }

    private function write_meta( $order, string $key, $value ): void {
        if ( is_object( $order ) && method_exists( $order, 'update_meta' ) ) {
            $order->update_meta( $key, $value );
        }
    }

    private function read_meta( $order, string $key ) {
        if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
            return $order->get_meta( $key );
        }
        return null;
    }
}
