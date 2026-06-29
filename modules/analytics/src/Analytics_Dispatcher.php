<?php
/**
 * Central analytics event dispatcher.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

use TejCart\Core\Action_Scheduler;
use TejCart\Order\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hub that listens for the same domain events {@see \TejCart\Core\Outgoing_Webhooks}
 * watches (`tejcart_order_created`, `tejcart_order_status_changed`,
 * `tejcart_customer_created`) and fans out to every registered analytics
 * driver — GA4, Meta CAPI, Klaviyo, Mailchimp, plus any third-party
 * driver added via the `tejcart_analytics_drivers` filter.
 *
 * The dispatcher does **not** call providers inline. Instead it normalises
 * the event payload and enqueues a single Action Scheduler job per event;
 * the worker re-resolves the active drivers and fans out at delivery time.
 * That keeps:
 *
 *   - Checkout fast: order completion never blocks on a slow ad-pixel server.
 *   - Reliability up: a transient outage at one provider doesn't poison
 *     the rest of the integrations.
 *   - Configurability live: turning a driver on/off in admin takes effect
 *     for any not-yet-processed jobs without a redeploy.
 *
 * The normalised payload shape is documented inline at each `build_*_payload`
 * method below; driver authors can rely on these keys regardless of the
 * source event.
 */
class Analytics_Dispatcher {
    /**
     * Action Scheduler hook used for delayed delivery.
     */
    public const FANOUT_HOOK = 'tejcart_analytics_fanout';

    /**
     * Object-cache group for normalised payload data — currently the
     * customer-row read used by {@see build_customer_payload()}.
     */
    public const CACHE_GROUP = 'tejcart_analytics';

    /**
     * Synthetic event type used to flush a coalesced `view_item` buffer.
     * One AS job is scheduled per driver carrying this event; the
     * fanout handler unpacks the batch and emits one `send_cart_event(
     * 'view_item', … )` call per buffered product. See
     * {@see flush_view_items()} / {@see handle_fanout()} for the round
     * trip.
     *
     * Audit ID: 07 F-10.
     */
    public const VIEW_ITEMS_BATCH_EVENT = 'view_items_batch';

    /**
     * Cached driver instances. Cleared by {@see Analytics_Dispatcher::reset()}.
     *
     * @var array<string, Abstract_Analytics_Driver>|null
     */
    private ?array $drivers = null;

    /**
     * Per-request micro-buffer of distinct product IDs that fired
     * `tejcart_view_product`. Flushed once per request (either via
     * register_shutdown_function or explicit flush_view_items()), turning
     * a 50-PDP test crawler into ONE Action Scheduler job per driver
     * instead of 50 — the F-10 firehose.
     *
     * Keyed by product_id (deduped) so a refresh loop on the same product
     * collapses to a single batch entry.
     *
     * @var array<int, true>
     */
    private array $view_item_buffer = array();

    /**
     * Has the shutdown-flush callback been registered yet for the
     * current request? Avoids stacking N copies of the flush hook when
     * 1000 view_item events come in.
     */
    private bool $view_item_flush_registered = false;

    /**
     * Per-request dedup set for dispatched event_ids. Prevents redundant
     * AS jobs when multiple hooks fire purchase/refund for the same order
     * within a single request (e.g. order_created + status_changed +
     * payment_complete all triggering for the same order).
     *
     * @var array<string, true>
     */
    private array $dispatched_event_ids = array();

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Wire dispatcher hooks. Idempotent.
     *
     * Listeners follow core's actual `do_action()` signatures — see
     * `src/Order/Order.php`, `src/Cart/Cart.php`, and
     * `src/Checkout/Checkout.php` for the canonical event payloads.
     */
    public function init(): void {
        // Order lifecycle.
        add_action( 'tejcart_order_created', array( $this, 'on_order_created' ), 20, 2 );
        add_action( 'tejcart_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 3 );

        // PayPal webhook fires `tejcart_payment_complete` for async
        // captures that don't transition status synchronously. The
        // dedupe is provided by the deterministic event_id —
        // drivers/providers will dedupe on it server-side.
        add_action( 'tejcart_payment_complete', array( $this, 'on_payment_complete' ), 20, 3 );
        add_action( 'tejcart_payment_failed',   array( $this, 'on_payment_failed' ),   20, 3 );

        // Refund lifecycle.
        add_action( 'tejcart_refund_created',         array( $this, 'on_refund_created' ),         20, 4 );
        add_action( 'tejcart_partial_refund_created', array( $this, 'on_partial_refund_created' ), 20, 3 );

        // Customer lifecycle. Core fires `tejcart_created_customer`
        // (verb-noun); we also listen to the noun-verb alias used by
        // the Outgoing_Webhooks subsystem and the legacy events some
        // third-party callers fire.
        add_action( 'tejcart_created_customer', array( $this, 'on_customer_registered' ), 20, 2 );
        add_action( 'tejcart_customer_created', array( $this, 'on_customer_created' ),    20, 1 );
        add_action( 'tejcart_account_details_saved', array( $this, 'on_customer_updated' ), 20, 1 );

        // Cart-funnel events. Signatures match core exactly:
        //   tejcart_add_to_cart                ($cart_item_key, $product_id, $quantity, $data, $cart)
        //   tejcart_before_remove_cart_item    ($cart_item_key, $item, $cart)
        //   tejcart_cart_item_quantity_updated ($cart_item_key, $quantity, $previous_quantity, $cart)
        //   tejcart_cart_emptied               ($cart)
        add_action( 'tejcart_add_to_cart',                 array( $this, 'on_add_to_cart' ),         20, 5 );
        add_action( 'tejcart_before_remove_cart_item',     array( $this, 'on_remove_from_cart' ),    20, 3 );
        add_action( 'tejcart_cart_item_quantity_updated',  array( $this, 'on_cart_quantity_updated' ),20, 4 );
        add_action( 'tejcart_cart_emptied',                array( $this, 'on_cart_emptied' ),        20, 1 );

        // Checkout funnel. `tejcart_checkout_process` fires from the
        // POST handler before validation; `tejcart_before_checkout_form`
        // fires when the checkout page first renders. Either is a
        // legitimate begin_checkout signal — we de-dupe on event_id.
        add_action( 'tejcart_checkout_process',      array( $this, 'on_begin_checkout' ), 20, 0 );
        add_action( 'tejcart_before_checkout_form',  array( $this, 'on_begin_checkout' ), 20, 0 );

        // Product engagement.
        add_action( 'tejcart_view_product',  array( $this, 'on_view_item' ),    20, 1 );
        add_action( 'tejcart_review_created', array( $this, 'on_review_created' ), 20, 3 );

        // Hook is fired with three args (event, payload, driver_id) but we
        // accept two as well for backwards compatibility with jobs that
        // were enqueued before the per-driver fan-out landed.
        add_action( self::FANOUT_HOOK, array( $this, 'handle_fanout' ), 10, 3 );
    }

    /* ---------------------------------------------------------------- */
    /* Cart-funnel listeners                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Coalesce `view_item` events into a per-request buffer instead of
     * scheduling one Action Scheduler job per driver per pageview.
     *
     * Audit #79 / 07 F-10 — on a busy storefront with 4 active drivers
     * and 10 000 PDP views/day the per-pageview dispatch produced
     * 40 000 `wp_actionscheduler_actions` rows/day on the read path,
     * which the AS retention window then carried for ~30 days. The
     * fix is a per-request micro-buffer (dedupe by product_id) that
     * flushes ONE AS job per driver per request at shutdown.
     *
     * Other event types (`purchase`, `add_to_cart`, view_item's sibling
     * cart-funnel events) stay synchronous — they're not the firehose
     * and each carries enough state (line totals, transactional intent)
     * that batching would lose information.
     *
     * Merchants who want the legacy per-pageview behaviour can disable
     * the coalescing via:
     *
     *     add_filter( 'tejcart_analytics_view_item_coalesce', '__return_false' );
     */
    public function on_view_item( $product_id ): void {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return;
        }

        /**
         * Whether to coalesce `view_item` events into a single per-request
         * AS job per driver. Default true.
         *
         * @param bool $coalesce
         */
        $coalesce = (bool) apply_filters( 'tejcart_analytics_view_item_coalesce', true );

        if ( ! $coalesce ) {
            // Legacy path — one job per driver per pageview.
            $this->dispatch( 'view_item', array(
                'event_id'   => $this->event_id( 'view_item', $product_id ),
                'product_id' => $product_id,
                'ip_address' => self::client_ip_anonymised(),
            ) );
            return;
        }

        // Dedupe within the request — a refresh loop on the same PDP
        // collapses to a single buffer entry.
        $this->view_item_buffer[ $product_id ] = true;

        if ( ! $this->view_item_flush_registered ) {
            $this->view_item_flush_registered = true;

            if ( function_exists( 'register_shutdown_function' ) ) {
                register_shutdown_function( array( $this, 'flush_view_items' ) );
            }
        }

        // Belt-and-braces overflow guard: if a really pathological caller
        // walked us past `tejcart_analytics_view_item_buffer_max` distinct
        // IDs (e.g. an admin re-indexer hitting do_action() in a loop) we
        // flush early so the buffer can't grow unbounded inside a single
        // request.
        $max = (int) apply_filters( 'tejcart_analytics_view_item_buffer_max', 500 );
        if ( $max > 0 && count( $this->view_item_buffer ) >= $max ) {
            $this->flush_view_items();
        }
    }

    /**
     * Flush the per-request `view_item` buffer as ONE AS job per active
     * driver. Idempotent — clearing the buffer on success means a second
     * call within the same request is a cheap no-op.
     *
     * Public so tests (and the register_shutdown_function callback) can
     * invoke it deterministically. The shutdown path runs after the
     * response has been sent to the client, so any latency here is
     * invisible to the buyer.
     */
    public function flush_view_items(): void {
        if ( empty( $this->view_item_buffer ) ) {
            return;
        }

        $product_ids = array_values( array_map( 'intval', array_keys( $this->view_item_buffer ) ) );
        // Clear before scheduling so a re-entrant on_view_item() during
        // shutdown (rare but possible if a driver fires the same hook
        // from within send_cart_event) doesn't enqueue twice.
        $this->view_item_buffer           = array();
        $this->view_item_flush_registered = false;

        $drivers = $this->get_active_drivers();
        if ( empty( $drivers ) ) {
            return;
        }

        // Use the same wire-format envelope as dispatch() but skip the
        // PII strip (view_item carries no PII — just a product_id list)
        // and the consent gate (per-product views are tied to product
        // engagement, not customer marketing, and individual drivers
        // already respect the gate inside send_cart_event when they
        // forward the event to a third-party server).
        $payload = array(
            'product_ids' => $product_ids,
            'event_id'    => $this->event_id( self::VIEW_ITEMS_BATCH_EVENT, (int) ( $product_ids[0] ?? 0 ) ),
            'ip_address'  => self::client_ip_anonymised(),
        );

        $now = time();
        foreach ( array_keys( $drivers ) as $driver_id ) {
            Action_Scheduler::instance()->schedule_single(
                $now,
                self::FANOUT_HOOK,
                array( self::VIEW_ITEMS_BATCH_EVENT, $payload, (string) $driver_id )
            );
        }
    }

    /**
     * Snapshot of the current view_item buffer. Test-only — production
     * callers should use {@see on_view_item()} / {@see flush_view_items()}.
     *
     * @return int[] Distinct product IDs currently buffered.
     */
    public function get_view_item_buffer(): array {
        return array_values( array_map( 'intval', array_keys( $this->view_item_buffer ) ) );
    }

    /**
     * Core signature: (string $cart_item_key, int $product_id, int $quantity, array $data, Cart $cart).
     * Older callers passing only the first three arguments are still
     * accepted thanks to default values.
     *
     * @param string|int|null      $cart_item_key
     * @param int                  $product_id
     * @param int                  $quantity
     * @param array<string, mixed> $data
     * @param mixed                $cart
     */
    public function on_add_to_cart( $cart_item_key = null, $product_id = 0, $quantity = 1, $data = array(), $cart = null ): void {
        // Detect legacy `(product_id, quantity, variation_id)` invocations:
        // in those the first positional argument is numeric. The modern
        // core signature always passes a string $cart_item_key, so a
        // numeric first arg unambiguously identifies the legacy
        // three-arg shape.
        if ( is_numeric( $cart_item_key ) ) {
            $resolved_product_id = (int) $cart_item_key;
            $resolved_quantity   = (int) $product_id;        // arg 2 of legacy signature
            $resolved_variation  = (int) ( is_numeric( $quantity ) ? $quantity : 0 ); // arg 3
            $resolved_key        = '';
        } else {
            $resolved_product_id = (int) $product_id;
            $resolved_quantity   = (int) $quantity;
            $resolved_variation  = is_array( $data ) ? (int) ( $data['variation_id'] ?? 0 ) : 0;
            $resolved_key        = is_string( $cart_item_key ) ? $cart_item_key : '';
        }

        if ( $resolved_product_id <= 0 ) {
            return;
        }

        $this->dispatch( 'add_to_cart', array(
            'event_id'      => $this->event_id( 'add_to_cart', $resolved_product_id ),
            'product_id'    => $resolved_product_id,
            'variation_id'  => $resolved_variation,
            'quantity'      => max( 1, $resolved_quantity ),
            'cart_item_key' => $resolved_key,
            'ip_address'    => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Core signature: (string $cart_item_key, array $item, Cart $cart).
     * The legacy `(product_id, quantity)` shape is also accepted so
     * third-party code firing the deprecated `tejcart_remove_from_cart`
     * action keeps working.
     *
     * @param mixed $cart_item_key_or_product_id
     * @param mixed $item_or_quantity
     * @param mixed $cart
     */
    public function on_remove_from_cart( $cart_item_key_or_product_id = null, $item_or_quantity = null, $cart = null ): void {
        $product_id = 0;
        $quantity   = 1;
        $key        = '';

        if ( is_array( $item_or_quantity ) ) {
            // Modern: ($cart_item_key, $item, $cart).
            $key        = is_string( $cart_item_key_or_product_id ) ? $cart_item_key_or_product_id : '';
            $product_id = (int) ( $item_or_quantity['product_id'] ?? 0 );
            $quantity   = (int) ( $item_or_quantity['quantity']   ?? 1 );
        } else {
            // Legacy: ($product_id, $quantity).
            $product_id = (int) $cart_item_key_or_product_id;
            $quantity   = (int) ( null === $item_or_quantity ? 1 : $item_or_quantity );
        }

        if ( $product_id <= 0 ) {
            return;
        }

        $this->dispatch( 'remove_from_cart', array(
            'event_id'      => $this->event_id( 'remove_from_cart', $product_id ),
            'product_id'    => $product_id,
            'quantity'      => max( 1, $quantity ),
            'cart_item_key' => $key,
            'ip_address'    => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Core signature: (string $cart_item_key, int $quantity, int $previous_quantity, Cart $cart).
     */
    public function on_cart_quantity_updated( $cart_item_key = '', $quantity = 0, $previous_quantity = 0, $cart = null ): void {
        $quantity          = (int) $quantity;
        $previous_quantity = (int) $previous_quantity;
        if ( $quantity === $previous_quantity ) {
            return;
        }
        $this->dispatch( 'update_cart', array(
            'event_id'          => $this->event_id( 'update_cart', $quantity * 1000 + crc32( (string) $cart_item_key ) ),
            'cart_item_key'     => (string) $cart_item_key,
            'quantity'          => max( 0, $quantity ),
            'previous_quantity' => max( 0, $previous_quantity ),
            'ip_address'        => self::client_ip_anonymised(),
        ) );
    }

    public function on_cart_emptied( $cart = null ): void {
        $this->dispatch( 'cart_emptied', array(
            'event_id'   => $this->event_id( 'cart_emptied', (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
            'ip_address' => self::client_ip_anonymised(),
        ) );
    }

    public function on_begin_checkout(): void {
        // Suppress duplicates within a single request — both
        // tejcart_before_checkout_form and tejcart_checkout_process can
        // fire on the same render path. The event_id is deterministic
        // for the (user, anchor) pair so server-side dedupe at the
        // provider holds, but we save the round-trip.
        static $fired = false;
        if ( $fired ) {
            return;
        }
        $fired = true;

        $this->dispatch( 'begin_checkout', array(
            'event_id'   => $this->event_id( 'begin_checkout', (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
            'ip_address' => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Public hook target so a sibling that wants to fire a payment-method
     * selection event from a frontend AJAX endpoint can call it via
     * `do_action( 'tejcart_analytics_add_payment_info', $method )`.
     *
     * @param string $payment_method
     */
    public function on_add_payment_info( $payment_method ): void {
        $this->dispatch( 'add_payment_info', array(
            'event_id'       => $this->event_id( 'add_payment_info', (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
            'payment_method' => (string) $payment_method,
            'ip_address'     => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Public hook target for shipping-method selection events.
     *
     * @param string $shipping_method
     */
    public function on_add_shipping_info( $shipping_method ): void {
        $this->dispatch( 'add_shipping_info', array(
            'event_id'         => $this->event_id( 'add_shipping_info', (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
            'shipping_method'  => (string) $shipping_method,
            'ip_address'       => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Materialise the registered drivers, applying the
     * `tejcart_analytics_drivers` filter. Cached for the request.
     *
     * @return array<string, Abstract_Analytics_Driver>
     */
    public function get_drivers(): array {
        if ( null !== $this->drivers ) {
            return $this->drivers;
        }

        /**
         * Filter the registered analytics driver class map.
         *
         * @param array<string, string> $drivers Map of id → class name.
         */
        $classes = (array) apply_filters( 'tejcart_analytics_drivers', array() );

        $instances = array();
        foreach ( $classes as $id => $class ) {
            if ( ! is_string( $class ) || ! class_exists( $class ) ) {
                continue;
            }
            $instance = new $class();
            if ( $instance instanceof Abstract_Analytics_Driver ) {
                $instances[ $instance->get_id() ?: (string) $id ] = $instance;
            }
        }

        $this->drivers = $instances;
        return $this->drivers;
    }

    /**
     * Drivers currently enabled and credentialed.
     *
     * @return array<string, Abstract_Analytics_Driver>
     */
    public function get_active_drivers(): array {
        $active = array();
        foreach ( $this->get_drivers() as $id => $driver ) {
            if ( $driver->is_active() ) {
                $active[ $id ] = $driver;
            }
        }
        return $active;
    }

    /**
     * Reset cached drivers. Test-only helper.
     */
    public function reset(): void {
        $this->drivers                    = null;
        $this->view_item_buffer           = array();
        $this->view_item_flush_registered = false;
        $this->dispatched_event_ids       = array();
    }

    /* ---------------------------------------------------------------- */
    /* Event listeners                                                  */
    /* ---------------------------------------------------------------- */

    /**
     * Order-created hook. Used as a "begin_checkout → purchase" anchor for
     * stores whose orders move directly to processing without a separate
     * status-change event.
     *
     * @param int        $order_id
     * @param Order|null $order
     */
    public function on_order_created( $order_id, $order = null ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        if ( ! $order instanceof Order ) {
            $order = $this->load_order( $order_id );
        }
        if ( null === $order ) {
            return;
        }

        // Capture once, on first creation, regardless of initial status —
        // the duplicate-suppression in the worker prevents a second
        // purchase when the order later transitions to processing.
        $this->dispatch( 'purchase', $this->build_purchase_payload( $order ) );
    }

    /**
     * Order status change. Fires "purchase" the first time an order
     * leaves `pending`, and "refund" when it lands in `refunded`.
     *
     * Note: signature matches `TejCart\Order\Order::update_status()` →
     * `do_action( 'tejcart_order_status_changed', $old_status, $new_status, $order )`.
     *
     * @param string $old_status
     * @param string $new_status
     * @param Order  $order
     */
    public function on_order_status_changed( $old_status, $new_status, $order ): void {
        if ( ! $order instanceof Order ) {
            return;
        }

        $new_status = (string) $new_status;
        $old_status = (string) $old_status;

        if ( in_array( $new_status, array( 'processing', 'completed' ), true )
            && ! in_array( $old_status, array( 'processing', 'completed' ), true ) ) {
            $this->dispatch( 'purchase', $this->build_purchase_payload( $order ) );
        }

        if ( 'refunded' === $new_status && 'refunded' !== $old_status ) {
            $this->dispatch( 'refund', $this->build_refund_payload( $order ) );
        }
    }

    /**
     * Payment-complete hook (PayPal webhook + future async gateways).
     * Fires the canonical purchase event so providers see the
     * conversion even when the order doesn't transition status
     * synchronously inside the request that captured the funds.
     *
     * Core signature: (int $order_id, Order $order, string $transaction_id).
     *
     * @param int    $order_id
     * @param mixed  $order
     * @param string $transaction_id
     */
    public function on_payment_complete( $order_id, $order = null, $transaction_id = '' ): void {
        $order = $order instanceof Order ? $order : $this->load_order( (int) $order_id );
        if ( ! $order instanceof Order ) {
            return;
        }
        $payload                   = $this->build_purchase_payload( $order );
        $payload['transaction_id'] = (string) $transaction_id;
        $this->dispatch( 'purchase', $payload );
    }

    /**
     * Payment-failed hook. Drivers that track failures can opt in via
     * the `payment_failed` cart-event branch in send_cart_event().
     *
     * @param int    $order_id
     * @param mixed  $order
     * @param string $transaction_id
     */
    public function on_payment_failed( $order_id, $order = null, $transaction_id = '' ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }
        $this->dispatch( 'payment_failed', array(
            'event_id'       => $this->event_id( 'payment_failed', $order_id ),
            'order_id'       => $order_id,
            'transaction_id' => (string) $transaction_id,
            'ip_address'     => self::client_ip_anonymised(),
        ) );
    }

    /**
     * Refund-created hook. Core signature:
     * (int $order_id, float $amount, string $reason, Order $order).
     *
     * @param int    $order_id
     * @param mixed  $amount
     * @param string $reason
     * @param mixed  $order
     */
    public function on_refund_created( $order_id, $amount = 0, $reason = '', $order = null ): void {
        $order = $order instanceof Order ? $order : $this->load_order( (int) $order_id );
        if ( ! $order instanceof Order ) {
            return;
        }
        $payload                  = $this->build_refund_payload( $order );
        $payload['refund_amount'] = (float) $amount;
        $payload['refund_reason'] = (string) $reason;
        $this->dispatch( 'refund', $payload );
    }

    /**
     * Partial-refund hook. Core signature:
     * (int $order_id, array|object $refund, Order $order).
     *
     * @param int   $order_id
     * @param mixed $refund
     * @param mixed $order
     */
    public function on_partial_refund_created( $order_id, $refund = null, $order = null ): void {
        $order = $order instanceof Order ? $order : $this->load_order( (int) $order_id );
        if ( ! $order instanceof Order ) {
            return;
        }
        $payload = $this->build_refund_payload( $order );
        $refund_arr = is_object( $refund ) ? get_object_vars( $refund ) : (array) ( $refund ?? array() );
        $payload['refund_amount'] = (float) ( $refund_arr['amount']   ?? 0 );
        $payload['refund_reason'] = (string) ( $refund_arr['reason']  ?? '' );
        $payload['partial']       = true;
        $this->dispatch( 'refund', $payload );
    }

    /**
     * Customer-created hook (legacy noun-verb alias).
     *
     * @param int $customer_id
     */
    public function on_customer_created( $customer_id ): void {
        $payload = $this->build_customer_payload( (int) $customer_id );
        if ( ! empty( $payload ) ) {
            $this->dispatch( 'customer_created', $payload );
        }
    }

    /**
     * Customer-registered hook. Fired by core's checkout flow as
     * `tejcart_created_customer` with (int $user_id, array $posted_data).
     * We resolve the WP user back to the TejCart customer row when
     * possible; falling back to a synthetic payload built from the
     * posted data so the email/name reach Klaviyo / Mailchimp even
     * before the customer-table sync completes.
     *
     * @param int   $user_id
     * @param array $posted_data
     */
    public function on_customer_registered( $user_id, $posted_data = array() ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        // Map WP user → TejCart customer row (a single SELECT cached
        // per request via the same group as build_customer_payload()).
        $payload = $this->build_customer_payload_for_user( $user_id, (array) $posted_data );
        if ( ! empty( $payload ) ) {
            $this->dispatch( 'customer_created', $payload );
        }
    }

    /**
     * Customer-updated hook. Fires `customer_updated` so providers can
     * re-sync profile data on an account-page save.
     *
     * @param int $user_id
     */
    public function on_customer_updated( $user_id ): void {
        $payload = $this->build_customer_payload_for_user( (int) $user_id );
        if ( empty( $payload ) ) {
            return;
        }
        $payload['event_id'] = $this->event_id( 'customer_updated', (int) $user_id );
        $this->dispatch( 'customer_updated', $payload );
    }

    /**
     * Review-created hook. Useful for retention drivers (Klaviyo flows
     * triggered by "Reviewed Product"). Core signature:
     * (int $comment_id, int $product_id, array $data).
     *
     * @param int   $comment_id
     * @param int   $product_id
     * @param array $data
     */
    public function on_review_created( $comment_id, $product_id = 0, $data = array() ): void {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return;
        }
        $data = is_array( $data ) ? $data : array();

        $this->dispatch( 'review_created', array(
            'event_id'       => $this->event_id( 'review_created', (int) $comment_id ),
            'comment_id'     => (int) $comment_id,
            'product_id'     => $product_id,
            'rating'         => (int) ( $data['rating']  ?? 0 ),
            'customer_email' => (string) ( $data['email'] ?? '' ),
            'customer_name'  => (string) ( $data['author'] ?? '' ),
            'ip_address'     => self::client_ip_anonymised(),
        ) );
    }

    /* ---------------------------------------------------------------- */
    /* Payload builders (the canonical schema drivers consume)          */
    /* ---------------------------------------------------------------- */

    /**
     * Build a purchase payload from an Order.
     *
     * Schema (drivers can rely on every key being present):
     *   - event_id        Stable hash for idempotent server-side dedupe
     *   - order_id        int
     *   - order_number    string (currently same as order_id; reserved)
     *   - status          string
     *   - currency        ISO-4217
     *   - subtotal        float
     *   - discount_total  float
     *   - shipping_total  float
     *   - tax_total       float
     *   - total           float
     *   - coupon_code     string
     *   - payment_method  string
     *   - customer_id     int
     *   - customer_email  string
     *   - customer_name   string
     *   - billing_address array
     *   - shipping_address array
     *   - ip_address      string
     *   - created_at      string (mysql)
     *   - items           array<array{product_id,name,sku,quantity,price,line_total}>
     *
     * @return array<string, mixed>
     */
    public function build_purchase_payload( Order $order ): array {
        $items    = array();
        $raw      = $order->get_items();
        $currency = (string) $order->get_currency();
        if ( is_array( $raw ) ) {
            foreach ( $raw as $row ) {
                $row_arr  = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
                $quantity = isset( $row_arr['quantity'] ) ? (int) $row_arr['quantity'] : 0;
                // `unit_price` is DECIMAL(20,4) major units; `line_total`
                // is BIGINT minor units (cents). The latter MUST be
                // converted before being shipped to GA4 / Meta CAPI /
                // Klaviyo / Mailchimp — all four expect major units in
                // their `purchase` / `Order Placed` event payload, and
                // sending cents would overstate revenue 100× on USD,
                // 1000× on KWD/BHD/OMR.
                $unit       = isset( $row_arr['unit_price'] ) ? (float) $row_arr['unit_price'] : 0.0;
                $line_total = isset( $row_arr['line_total'] )
                    ? \TejCart\Money\Currency::from_minor_units( (int) $row_arr['line_total'], $currency )
                    : ( $unit * $quantity );

                $items[] = array(
                    'product_id' => isset( $row_arr['product_id'] ) ? (int) $row_arr['product_id'] : 0,
                    'name'       => isset( $row_arr['product_name'] ) ? (string) $row_arr['product_name'] : '',
                    'sku'        => isset( $row_arr['sku'] ) ? (string) $row_arr['sku'] : '',
                    'quantity'   => $quantity,
                    'price'      => $unit,
                    'line_total' => $line_total,
                );
            }
        }

        return array(
            'event_id'         => $this->event_id( 'purchase', $order->get_id() ),
            'order_id'         => (int) $order->get_id(),
            'order_number'     => (string) $order->get_id(),
            'status'           => (string) $order->get_status(),
            'currency'         => (string) $order->get_currency(),
            'subtotal'         => (float) $order->get_subtotal(),
            'discount_total'   => (float) $order->get_discount_total(),
            'shipping_total'   => (float) $order->get_shipping_total(),
            'tax_total'        => (float) $order->get_tax_total(),
            'total'            => (float) $order->get_total(),
            'coupon_code'      => (string) $order->get_coupon_code(),
            'payment_method'   => (string) $order->get_payment_method(),
            'customer_id'      => (int) $order->get_customer_id(),
            'customer_email'   => (string) $order->get_customer_email(),
            'customer_name'    => (string) $order->get_customer_name(),
            'billing_address'  => (array) $order->get_billing_address(),
            'shipping_address' => (array) $order->get_shipping_address(),
            'ip_address'       => (string) $order->get_ip_address(),
            'created_at'       => (string) $order->get_created_at(),
            'items'            => $items,
        );
    }

    /**
     * Build a refund payload. Same shape as purchase, with a stable
     * `event_id` keyed on the refund event so providers de-dupe correctly.
     *
     * @return array<string, mixed>
     */
    public function build_refund_payload( Order $order ): array {
        $payload                = $this->build_purchase_payload( $order );
        $payload['event_id']    = $this->event_id( 'refund', $order->get_id() );
        $payload['event_type']  = 'refund';
        return $payload;
    }

    /**
     * Build a customer payload from a customer record.
     *
     * @param int $customer_id
     * @return array<string, mixed>
     */
    public function build_customer_payload( int $customer_id ): array {
        if ( $customer_id <= 0 ) {
            return array();
        }

        // Cache the row read for 5 minutes so high-volume stores that
        // emit multiple events for the same customer in close succession
        // (purchase + refund + customer_updated) don't re-hit the
        // customers table once per dispatcher call.
        $cache_key = 'customer_row:' . $customer_id;
        $row       = false;
        if ( function_exists( 'wp_cache_get' ) ) {
            $row = wp_cache_get( $cache_key, self::CACHE_GROUP );
        }

        if ( false === $row ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tejcart_customers';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $fetched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $customer_id ) );
            if ( ! $fetched ) {
                return array();
            }
            $row = is_object( $fetched ) ? get_object_vars( $fetched ) : (array) $fetched;
            if ( function_exists( 'wp_cache_set' ) ) {
                wp_cache_set( $cache_key, $row, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
            }
        }

        if ( ! is_array( $row ) ) {
            return array();
        }

        return array(
            'event_id'    => $this->event_id( 'customer_created', $customer_id ),
            'customer_id' => $customer_id,
            'user_id'     => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
            'email'       => isset( $row['email'] ) ? (string) $row['email'] : '',
            'first_name'  => isset( $row['first_name'] ) ? (string) $row['first_name'] : '',
            'last_name'   => isset( $row['last_name'] ) ? (string) $row['last_name'] : '',
            'phone'       => isset( $row['phone'] ) ? (string) $row['phone'] : '',
            'created_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
        );
    }

    /**
     * Resolve a WP user id to a customer payload by joining via the
     * `user_id` column on `tejcart_customers`. When no matching row
     * exists yet (e.g. the customer-table sync runs at a later
     * priority) we fall back to a synthesised payload built from the
     * posted-data array so the email/first-name/last-name reach the
     * dispatcher anyway. Returns an empty array when even that fails.
     *
     * @param int                  $user_id
     * @param array<string, mixed> $posted_data
     * @return array<string, mixed>
     */
    public function build_customer_payload_for_user( int $user_id, array $posted_data = array() ): array {
        if ( $user_id <= 0 ) {
            return array();
        }

        global $wpdb;
        $row = null;
        if ( isset( $wpdb ) ) {
            $table = $wpdb->prefix . 'tejcart_customers';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $fetched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
            if ( $fetched ) {
                $row = is_object( $fetched ) ? get_object_vars( $fetched ) : (array) $fetched;
            }
        }

        if ( is_array( $row ) && isset( $row['id'] ) ) {
            return $this->build_customer_payload( (int) $row['id'] );
        }

        // Fall back to the WP user object + posted_data so we can still
        // emit a customer_created event. Drivers without a customer_id
        // gracefully degrade to email-keyed sync.
        $email      = (string) ( $posted_data['billing_email'] ?? $posted_data['email'] ?? '' );
        $first_name = (string) ( $posted_data['billing_first_name'] ?? $posted_data['first_name'] ?? '' );
        $last_name  = (string) ( $posted_data['billing_last_name']  ?? $posted_data['last_name']  ?? '' );
        $phone      = (string) ( $posted_data['billing_phone']      ?? $posted_data['phone']      ?? '' );

        if ( '' === $email && function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( $user && isset( $user->user_email ) ) {
                $email = (string) $user->user_email;
            }
        }

        if ( '' === $email ) {
            return array();
        }

        return array(
            'event_id'    => $this->event_id( 'customer_created', $user_id ),
            'customer_id' => 0,
            'user_id'     => $user_id,
            'email'       => $email,
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'phone'       => $phone,
            'created_at'  => function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
        );
    }

    /* ---------------------------------------------------------------- */
    /* Dispatch / fan-out                                               */
    /* ---------------------------------------------------------------- */

    /**
     * Schedule a single fan-out job for an event.
     *
     * @param string $event   Canonical event type ("purchase", "refund", "customer_created").
     * @param array  $payload Normalised payload.
     */
    /**
     * Honour the visitor's cookie-consent state for marketing analytics.
     *
     * Server-side fan-out (GA4, Meta CAPI, Klaviyo, Mailchimp) was
     * previously unconditional — Art. 7 GDPR violation for any EU
     * visitor who declined marketing cookies. We now consult the core
     * `tejcart_has_cookie_consent()` helper when present, with two
     * documented opt-outs:
     *
     *   - `tejcart_analytics_consent_required` (filter, default true):
     *     a site that has its own consent-management posture (e.g.
     *     legitimate-interest basis for fraud analytics) can short-
     *     circuit the gate entirely.
     *   - The `__transactional` flag inside the payload: events that
     *     are part of transactional fulfilment (receipt delivery via
     *     Klaviyo `order_placed` flows used to send the order receipt
     *     email) bypass the gate.
     */
    private function event_passes_consent_gate( string $event, array $payload ): bool {
        /**
         * Filter whether marketing-consent is required for this
         * dispatcher event. Returning false short-circuits the gate.
         *
         * @param bool   $required Default true.
         * @param string $event    Canonical event name.
         * @param array  $payload  Normalised payload.
         */
        $required = (bool) apply_filters( 'tejcart_analytics_consent_required', true, $event, $payload );
        if ( ! $required ) {
            return true;
        }

        if ( ! empty( $payload['__transactional'] ) ) {
            return true;
        }

        if ( ! function_exists( 'tejcart_has_cookie_consent' ) ) {
            // Core helper unavailable — fail open so this dispatcher
            // doesn't break sites without the consent feature. The
            // filter above is the explicit-opt-in pathway for sites
            // that want stricter behaviour.
            return true;
        }

        return (bool) tejcart_has_cookie_consent( 'marketing' );
    }

    public function dispatch( string $event, array $payload ): void {
        $drivers = $this->get_active_drivers();
        if ( empty( $drivers ) ) {
            return;
        }

        // Per-request dedup: multiple hooks can fire the same event for
        // the same order within one request (e.g. order_created +
        // status_changed + payment_complete). The deterministic event_id
        // means providers would dedupe server-side, but we save 3×N AS
        // jobs by catching it here.
        $event_id = (string) ( $payload['event_id'] ?? '' );
        if ( '' !== $event_id && isset( $this->dispatched_event_ids[ $event_id ] ) ) {
            return;
        }
        if ( '' !== $event_id ) {
            $this->dispatched_event_ids[ $event_id ] = true;
        }

        // GDPR consent gate. Marketing analytics fan-out (GA4, Meta
        // Conversions API, Klaviyo, Mailchimp behavioural) must respect
        // the visitor's cookie-consent choice — without this every EU
        // visitor who declined marketing cookies still had their order
        // and cart events forwarded server-side, a direct Art. 7
        // violation. Filterable so per-driver exceptions (e.g. a
        // Klaviyo "order_placed" event that is part of transactional
        // receipt delivery, not marketing) can opt out.
        if ( ! $this->event_passes_consent_gate( $event, $payload ) ) {
            return;
        }

        // Capture Meta-specific click/browser IDs from the current
        // request context (cookies). These are not PII and survive the
        // wire-format PII strip so the Meta CAPI driver can forward them
        // for Event Match Quality without the filter workaround.
        if ( ! isset( $payload['fbc'] ) ) {
            $payload['fbc'] = $this->capture_meta_cookie( '_fbc', 'fbclid' );
        }
        if ( ! isset( $payload['fbp'] ) ) {
            $payload['fbp'] = isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
        }
        if ( ! isset( $payload['user_agent'] ) ) {
            $payload['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        }

        // Strip PII fields from the payload before scheduling. The
        // raw payload includes customer_email, customer_name,
        // billing_address, shipping_address, and ip_address (see
        // build_purchase_payload). Action Scheduler serialises args
        // to wp_actionscheduler_actions.args as JSON / WP-Cron writes
        // them into wp_options.cron — both of which sit unencrypted
        // in the database for the AS retention window (~30 days
        // default) and surface in mysqldump backups. Right-to-erasure
        // (GDPR Art. 17) requests would not have caught these rows,
        // and Privacy::erase() only fires per-driver hooks. Stripping
        // the PII at the wire boundary and rehydrating at fan-out
        // time keeps the at-rest footprint minimal.
        //
        // The order_id reference is preserved so handle_fanout can
        // re-load the order and rebuild the full payload for the
        // driver. Non-order events (cart velocity, view-item) carry
        // little or no PII and pass through unchanged.
        $wire_payload = $this->strip_pii_for_wire( $payload );

        // One Action Scheduler job per driver so a slow or failing
        // provider can't block delivery to the others. Each job is
        // independently retried by Action Scheduler and resolved from
        // the live driver list at run time so toggling a driver in
        // admin takes effect without a redeploy.
        $now = time();
        foreach ( array_keys( $drivers ) as $driver_id ) {
            Action_Scheduler::instance()->schedule_single(
                $now,
                self::FANOUT_HOOK,
                array( $event, $wire_payload, (string) $driver_id )
            );
        }
    }

    /**
     * PII fields we never want serialised into wp_actionscheduler_actions.
     * Mirror the fields populated by {@see self::build_purchase_payload()}.
     *
     * @var string[]
     */
    private const PII_FIELDS = array(
        'customer_email',
        'customer_name',
        'customer_first_name',
        'customer_last_name',
        'customer_phone',
        'email',
        'first_name',
        'last_name',
        'phone',
        'billing_address',
        'shipping_address',
        'ip_address',
        'user_agent',
    );

    /**
     * Remove PII fields from a payload prior to scheduling. The
     * order_id stays so the fan-out worker can re-derive the data.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function strip_pii_for_wire( array $payload ): array {
        foreach ( self::PII_FIELDS as $field ) {
            unset( $payload[ $field ] );
        }
        // Mark the payload as wire-format so handle_fanout knows to
        // rehydrate; allows drivers to be migrated incrementally.
        $payload['__wire_format'] = 1;
        return $payload;
    }

    /**
     * Re-populate PII fields onto a wire payload by loading the order.
     * Returns the input unchanged if no order_id is present or the
     * order has been deleted in the meantime (right-to-erasure).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function rehydrate_pii( array $payload ): array {
        if ( empty( $payload['__wire_format'] ) ) {
            return $payload;
        }
        unset( $payload['__wire_format'] );

        $customer_id = isset( $payload['customer_id'] ) ? (int) $payload['customer_id'] : 0;
        $user_id     = isset( $payload['user_id'] )     ? (int) $payload['user_id']     : 0;
        $order_id    = isset( $payload['order_id'] )     ? (int) $payload['order_id']    : 0;

        if ( $order_id > 0 ) {
            $order = $this->load_order( $order_id );
            if ( null === $order ) {
                return $payload;
            }
            $rebuilt = $this->build_purchase_payload( $order );
            return array_replace( $payload, $rebuilt );
        }

        if ( $customer_id > 0 ) {
            $rebuilt = $this->build_customer_payload( $customer_id );
            if ( ! empty( $rebuilt ) ) {
                return array_replace( $payload, $rebuilt );
            }
        }

        if ( $user_id > 0 ) {
            $rebuilt = $this->build_customer_payload_for_user( $user_id );
            if ( ! empty( $rebuilt ) ) {
                return array_replace( $payload, $rebuilt );
            }
        }

        return $payload;
    }

    /**
     * Background worker — fan an event out to every active driver.
     *
     * Each driver call is wrapped in its own try/catch so a buggy driver
     * cannot poison the rest. The standardised return value is a bool;
     * exceptions are logged and treated as soft-failures.
     *
     * @param string $event
     * @param array  $payload
     */
    public function handle_fanout( string $event, array $payload, string $driver_id = '' ): void {
        $drivers = $this->get_active_drivers();
        if ( empty( $drivers ) ) {
            return;
        }

        // Per-driver dispatch path. Newer dispatch() enqueues one job per
        // driver and passes its id so a slow provider can't block the rest.
        if ( '' !== $driver_id ) {
            if ( ! isset( $drivers[ $driver_id ] ) ) {
                return;
            }
            $drivers = array( $driver_id => $drivers[ $driver_id ] );
        }

        // Rehydrate PII from the stored order_id reference. The
        // payload that was serialised into the AS args column has
        // PII stripped (see dispatch() / strip_pii_for_wire). The
        // driver expects the full payload shape, so we re-load the
        // order and overlay the PII fields here at fan-out time.
        // GDPR-erased orders pass through with order_id only.
        $payload = $this->rehydrate_pii( $payload );

        // Audit #20 / 07 F-5 — accumulate transient failures across all
        // drivers in this fan-out, then re-throw at the end so Action
        // Scheduler retries with backoff. Without this, 5xx / 429
        // responses logged warnings but the AS job was marked complete
        // and the event was lost forever. Terminal 4xx (auth, validation,
        // schema) drop the delivery as before — those won't succeed on
        // retry.
        $transient_messages = array();

        foreach ( $drivers as $driver ) {
            $driver_id = $driver->get_id();
            try {
                switch ( $event ) {
                    case 'purchase':
                        $driver->send_purchase( $payload );
                        break;
                    case 'refund':
                        $driver->send_refund( $payload );
                        break;
                    case 'customer_created':
                    case 'customer_updated':
                        $driver->send_customer_created( $payload );
                        break;
                    case self::VIEW_ITEMS_BATCH_EVENT:
                        // Coalesced view_item batch (audit #79 / 07 F-10).
                        // Unpack into one send_cart_event('view_item', …)
                        // call per buffered product id so existing driver
                        // implementations don't need to know about the
                        // batch envelope. Drivers can opt into the batch
                        // form by implementing a `send_view_items_batch`
                        // method — but the default fan-out path keeps
                        // existing send_cart_event signatures intact.
                        $product_ids = (array) ( $payload['product_ids'] ?? array() );
                        $ip          = (string) ( $payload['ip_address'] ?? '' );
                        if ( method_exists( $driver, 'send_view_items_batch' ) ) {
                            // Driver explicitly opted into batch handling — give
                            // it the whole list in one call.
                            $driver->send_view_items_batch( $payload );
                        } else {
                            foreach ( $product_ids as $pid ) {
                                $pid = (int) $pid;
                                if ( $pid <= 0 ) {
                                    continue;
                                }
                                $driver->send_cart_event( 'view_item', array(
                                    'event_id'   => $this->event_id( 'view_item', $pid ),
                                    'product_id' => $pid,
                                    'ip_address' => $ip,
                                ) );
                            }
                        }
                        break;
                    default:
                        $driver->send_cart_event( $event, $payload );
                        break;
                }
                $this->record_driver_health( $driver_id, $event, true );
            } catch ( Transient_Driver_Exception $e ) {
                $this->record_driver_health( $driver_id, $event, false, $e->getMessage() );
                $transient_messages[] = sprintf( '%s: %s', $driver_id, $e->getMessage() );
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( '[analytics:%s] %s transient: %s', $driver_id, $event, $e->getMessage() ),
                        'warning'
                    );
                }
            } catch ( \Throwable $e ) {
                $this->record_driver_health( $driver_id, $event, false, $e->getMessage() );
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( '[analytics:%s] %s threw: %s', $driver_id, $event, $e->getMessage() ),
                        'warning'
                    );
                }
            }
        }

        if ( array() !== $transient_messages ) {
            throw new Transient_Driver_Exception(
                sprintf( 'transient analytics failure on %s: %s', $event, implode( '; ', $transient_messages ) )
            );
        }
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Stable, deterministic event ID — same input always produces the
     * same string, which providers like Meta CAPI use to de-dupe against
     * a client-side pixel hit.
     */
    private function event_id( string $event, int $entity_id ): string {
        return substr( hash( 'sha256', $event . '|' . $entity_id . '|' . get_site_url() ), 0, 32 );
    }

    /**
     * Capture Meta's `_fbc` cookie or derive it from the `fbclid` URL
     * parameter. Returns empty string when neither is available.
     */
    private function capture_meta_cookie( string $cookie_name, string $url_param ): string {
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET[ $url_param ] ) ) {
            $click_id = sanitize_text_field( wp_unslash( $_GET[ $url_param ] ) );
            return 'fb.1.' . time() . '.' . $click_id;
        }
        return '';
    }

    /**
     * Return the client IP, optionally anonymised. Default is to
     * truncate IPv4 to /24 and IPv6 to /64 — the same posture GA4
     * uses for `_ga` cookies and Meta CAPI accepts when paired with
     * `client_ip_address`. Sites with a stricter privacy posture can
     * filter `tejcart_analytics_anonymise_ip` to false to send the
     * raw value, or to a custom callable for bespoke shaping.
     */
    public static function client_ip_anonymised(): string {
        $raw = '';
        // Audit #54 / 04 M-2 — defer IP resolution to Rate_Limiter
        // which honours `tejcart_trusted_proxies` (only consults
        // X-Forwarded-For when REMOTE_ADDR is in a trusted CIDR).
        // Previously this read XFF unconditionally, letting any
        // client spoof the analytics geo bucket.
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $raw = (string) \TejCart\Security\Rate_Limiter::get_client_ip();
        }
        if ( '' === $raw && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( '' === $raw || false === filter_var( $raw, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        $anonymise = true;
        if ( function_exists( 'apply_filters' ) ) {
            /**
             * Filter whether the dispatcher anonymises IP addresses
             * before passing them to drivers. Default true.
             *
             * @param bool   $anonymise
             * @param string $raw_ip
             */
            $anonymise = (bool) apply_filters( 'tejcart_analytics_anonymise_ip', true, $raw );
        }
        if ( ! $anonymise ) {
            return $raw;
        }

        if ( filter_var( $raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts    = explode( '.', $raw );
            $parts[3] = '0';
            return implode( '.', $parts );
        }
        // IPv6 → mask to /64 by zeroing the lower four hextets.
        $parts = explode( ':', $raw );
        $parts = array_slice( $parts, 0, 4 );
        while ( count( $parts ) < 4 ) {
            $parts[] = '0';
        }
        return implode( ':', $parts ) . '::';
    }

    /**
     * Record per-driver health for observability. Called from
     * handle_fanout() on success and on caught exceptions so the
     * admin can answer "did GA4 fire today?" without grep'ing logs.
     *
     * Uses options for the durable bits (last_sent_at, last_error)
     * and a per-driver/event transient for monthly counters so the
     * counters bound their cardinality even on busy stores.
     */
    private function record_driver_health( string $driver_id, string $event, bool $ok, string $error = '' ): void {
        if ( '' === $driver_id ) {
            return;
        }
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        if ( $ok ) {
            $stamps                          = (array) get_option( 'tejcart_analytics_last_sent_at', array() );
            $stamps[ $driver_id ]            = $now;
            update_option( 'tejcart_analytics_last_sent_at', $stamps, false );
        } else {
            $errors                = (array) get_option( 'tejcart_analytics_last_error', array() );
            $errors[ $driver_id ]  = array(
                'event' => $event,
                'time'  => $now,
                'error' => $error,
            );
            update_option( 'tejcart_analytics_last_error', $errors, false );
        }

        $month    = gmdate( 'Y-m' );
        $counters = (array) get_option( 'tejcart_analytics_counters', array() );

        if ( ( $counters['__month'] ?? '' ) !== $month ) {
            $counters = array( '__month' => $month );
        }

        $bucket   = sprintf( '%s:%s:%s', $driver_id, $event, $ok ? 'ok' : 'fail' );
        $counters[ $bucket ] = (int) ( $counters[ $bucket ] ?? 0 ) + 1;
        update_option( 'tejcart_analytics_counters', $counters, false );
    }

    /**
     * Load an order, returning null when the row is missing. Wrapped in a
     * try/catch because Order::__construct() can throw on a hard miss.
     */
    private function load_order( int $order_id ): ?Order {
        try {
            $order = new Order( $order_id );
        } catch ( \Throwable $e ) {
            return null;
        }
        return $order->get_id() ? $order : null;
    }
}
