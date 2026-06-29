<?php
/**
 * PayPal AJAX Handlers
 *
 * Backend handlers for the PayPal SDK v6 JavaScript flow used by all
 * PayPal-family gateways (Wallet, Card Fields, Google Pay, Apple Pay,
 * Venmo, Fastlane). The frontend script `tejcart-paypal.js` posts to
 * these endpoints to create and capture PayPal orders.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Checkout\Checkout;

/**
 * Registers and handles AJAX endpoints for PayPal SDK v6 flows.
 */
class PayPal_AJAX {
    /**
     * Sentinel value persisted onto an order's `_shipping_method_id` meta when
     * the order does not need a shipping method (digital-only / virtual-only
     * cart). Lets reports and downstream readers distinguish "no method
     * required" from "buyer abandoned the wallet without selecting a method"
     * without re-deriving order_needs_shipping() at every read site.
     */
    public const SHIPPING_METHOD_NOT_REQUIRED = 'not_required';

    /**
     * Whether the merchant has shipping enabled globally.
     *
     * Mirrors the symmetric tax flag (`tejcart_enable_tax`) which defaults to
     * `'no'` — fresh installs ship with shipping off until the merchant has
     * actually set up zones. Called from every PayPal flow that would
     * otherwise enumerate shipping methods or expect the buyer to pick one.
     */
    public static function shipping_globally_enabled(): bool {
        return 'yes' === (string) get_option( 'tejcart_enable_shipping', 'no' );
    }

    /**
     * Read the buyer's chosen shipping-method id from an order, honouring
     * both meta keys that the various TejCart flows write.
     *
     * Two keys can carry this value:
     *
     *  - `_shipping_method_id` — written by the PayPal-internal flow when
     *    the buyer interacts with PayPal's wallet-side shipping picker
     *    (express checkout / Apple Pay / Google Pay). Also receives the
     *    {@see SHIPPING_METHOD_NOT_REQUIRED} sentinel for digital-only
     *    orders so the capture guard short-circuits.
     *  - `_shipping_method` — written by the standard checkout flow via
     *    {@see \TejCart\Checkout\Shipping_Method_Capture::save_to_order()}.
     *    This is the canonical TejCart key (Order_Admin renders it; the
     *    bundled `shipping` module reads it for label printing).
     *
     * The capture-time shipping-required guard and the wallet-sheet
     * selected-option highlight both need to recognise a method that was
     * picked on TejCart's checkout page before the PayPal lightbox opened
     * — the PPCP standard-checkout flow never visits `update_shipping()`,
     * so `_shipping_method_id` stays empty even though the buyer chose
     * "Flat Rate" (or similar) on the checkout form.
     *
     * @param int $order_id Local TejCart order id.
     * @return string Method id, {@see SHIPPING_METHOD_NOT_REQUIRED} sentinel,
     *                or '' when neither meta key has been set.
     */
    public static function chosen_shipping_method_for_order( int $order_id ): string {
        if ( $order_id <= 0 || ! function_exists( 'tejcart_get_order_meta' ) ) {
            return '';
        }
        $id = (string) tejcart_get_order_meta( $order_id, '_shipping_method_id' );
        if ( '' !== $id ) {
            return $id;
        }
        return (string) tejcart_get_order_meta( $order_id, '_shipping_method' );
    }

    /**
     * Register WordPress AJAX hooks.
     */
    public function register(): void {
        add_action( 'wp_ajax_tejcart_paypal_create_order', array( $this, 'create_order' ) );
        add_action( 'wp_ajax_nopriv_tejcart_paypal_create_order', array( $this, 'create_order' ) );
        add_action( 'wp_ajax_tejcart_paypal_capture_order', array( $this, 'capture_order' ) );
        add_action( 'wp_ajax_nopriv_tejcart_paypal_capture_order', array( $this, 'capture_order' ) );
        add_action( 'wp_ajax_tejcart_paypal_update_shipping', array( $this, 'update_shipping' ) );
        add_action( 'wp_ajax_nopriv_tejcart_paypal_update_shipping', array( $this, 'update_shipping' ) );
        // Canonical shipping-options endpoint shared by Google Pay
        // (onPaymentDataChanged) and Apple Pay (onshippingcontactselected /
        // onshippingmethodselected). Returns options in three shapes so each
        // wallet can render its own picker without an extra round trip.
        add_action( 'wp_ajax_tejcart_paypal_wallet_shipping', array( $this, 'wallet_shipping' ) );
        add_action( 'wp_ajax_nopriv_tejcart_paypal_wallet_shipping', array( $this, 'wallet_shipping' ) );
        // Buyer-cancel hop so abandoned express orders don't
        // accumulate as `pending` rows forever.
        add_action( 'wp_ajax_tejcart_paypal_cancel_order', array( $this, 'cancel_order' ) );
        add_action( 'wp_ajax_nopriv_tejcart_paypal_cancel_order', array( $this, 'cancel_order' ) );
        add_action( 'wp_ajax_tejcart_paypal_create_setup_token', array( $this, 'create_setup_token' ) );
        add_action( 'wp_ajax_tejcart_paypal_save_payment_token', array( $this, 'save_payment_token' ) );
        add_action( 'wp_ajax_tejcart_paypal_test_connection', array( $this, 'test_connection' ) );
        add_action( 'wp_ajax_tejcart_paypal_register_webhook', array( $this, 'register_webhook' ) );

        // Periodic sweep that cancels any pending PayPal-Express
        // order older than the configured threshold (default 30 minutes).
        // Buyer-side cancel covers the common case; this is the safety net
        // for closed tabs, dropped network, etc.
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
        add_action( 'init', array( __CLASS__, 'maybe_schedule_orphan_sweep' ) );
        add_action( 'tejcart_paypal_orphan_order_sweep', array( __CLASS__, 'sweep_orphan_orders' ) );
    }

    /**
     * Schedule the orphan-order sweeper once per install.
     *
     * F-H8 / #931: default cadence tightened from hourly to every
     * 10 minutes so PayPal-abandoned stock is released within
     * ~10 minutes instead of up to an hour. Override via the
     * `tejcart_paypal_orphan_sweep_recurrence` filter (e.g. return
     * 'hourly' for legacy behaviour, or any WP-Cron recurrence slug).
     */
    public static function maybe_schedule_orphan_sweep(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return;
        }

        $recurrence = (string) apply_filters( 'tejcart_paypal_orphan_sweep_recurrence', 'tejcart_ten_minutes' );

        // Fall back to hourly if the chosen recurrence isn't registered.
        $schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
        if ( ! isset( $schedules[ $recurrence ] ) ) {
            // Audit L-32: log the rejected value so a misconfigured
            // filter is visible in the debug log.
            if ( function_exists( 'tejcart_log' ) && 'tejcart_ten_minutes' !== $recurrence ) {
                tejcart_log( sprintf( 'PayPal orphan sweep recurrence "%s" is not a registered schedule — falling back to hourly.', $recurrence ), 'warning' );
            }
            $recurrence = 'hourly';
        }

        $existing = wp_next_scheduled( 'tejcart_paypal_orphan_order_sweep' );
        if ( $existing && function_exists( 'wp_get_scheduled_event' ) ) {
            $event = wp_get_scheduled_event( 'tejcart_paypal_orphan_order_sweep' );
            if ( $event && isset( $event->schedule ) && $event->schedule !== $recurrence ) {
                wp_unschedule_event( $existing, 'tejcart_paypal_orphan_order_sweep' );
                $existing = false;
            }
        }
        if ( ! $existing ) {
            wp_schedule_event( time() + 60, $recurrence, 'tejcart_paypal_orphan_order_sweep' );
        }
    }

    /**
     * Register the 10-minute WP-Cron recurrence used for the orphan
     * sweep. Hooked on `cron_schedules` from {@see self::init()} so
     * the slug is available before {@see self::maybe_schedule_orphan_sweep()}
     * tries to consume it.
     *
     * @param array $schedules
     * @return array
     */
    public static function register_cron_schedules( $schedules ) {
        if ( ! is_array( $schedules ) ) {
            $schedules = array();
        }
        if ( ! isset( $schedules['tejcart_ten_minutes'] ) ) {
            $schedules['tejcart_ten_minutes'] = array(
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 10 minutes (TejCart)', 'tejcart' ),
            );
        }
        return $schedules;
    }

    /**
     * Cancel pending PayPal-Express orders older than the threshold and
     * release their stock reservations. Bounded to a small batch per run
     * so a long backlog can't lock the orders table.
     */
    public static function sweep_orphan_orders(): void {
        global $wpdb;

        /**
         * Filter the age threshold (seconds) at which an abandoned
         * PayPal-Express pending order is treated as orphan.
         *
         * @param int $seconds Default 1800 (30 minutes).
         */
        $threshold = (int) apply_filters( 'tejcart_paypal_orphan_threshold_seconds', 30 * MINUTE_IN_SECONDS );
        if ( $threshold <= 0 ) {
            return;
        }

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $meta_table   = $wpdb->prefix . 'tejcart_order_meta';
        $cutoff       = gmdate( 'Y-m-d H:i:s', time() - $threshold );

        // Audit #1 / 05 F-1 — also pick up legacy orphans that were
        // wrongly auto-promoted to `processing` by the pre-fix
        // Checkout::process() path. Genuine captured orders write
        // `_paypal_capture_id` BEFORE the status flip (see
        // record_transaction_meta() in PayPal_AJAX::capture_order()),
        // so the LEFT JOIN filter excludes them.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT o.id
                   FROM {$orders_table} o
                   JOIN {$meta_table} m_exp ON m_exp.order_id = o.id
                                           AND m_exp.meta_key = '_paypal_express'
                                           AND m_exp.meta_value = 'yes'
                   LEFT JOIN {$meta_table} m_cap ON m_cap.order_id = o.id
                                                AND m_cap.meta_key = '_paypal_capture_id'
                  WHERE o.status IN ('pending', 'processing')
                    AND o.payment_method = 'tejcart_paypal'
                    AND o.created_at <= %s
                    AND ( m_cap.meta_value IS NULL OR m_cap.meta_value = '' )
                  ORDER BY o.id ASC
                  LIMIT 100",
                $cutoff
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        foreach ( (array) $rows as $row ) {
            $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( (int) $row->id ) : null;
            if ( ! $order || ! method_exists( $order, 'update_status' ) ) {
                continue;
            }
            $current = $order->get_status();
            if ( 'pending' !== $current && 'processing' !== $current ) {
                continue;
            }
            // Re-check capture id at the application layer in case a
            // late webhook wrote it between the SELECT and now.
            $capture_id = function_exists( 'tejcart_get_order_meta' )
                ? (string) tejcart_get_order_meta( (int) $order->get_id(), '_paypal_capture_id' )
                : '';
            if ( '' !== $capture_id ) {
                continue;
            }
            $order->update_status( 'cancelled', __( 'Auto-cancelled: abandoned PayPal Express checkout.', 'tejcart' ) );

            do_action( 'tejcart_restore_stock_for_order', (int) $order->get_id(), $order );
        }
    }

    /**
     * Create a PayPal order.
     *
     * Two flows share this endpoint:
     *
     *   1. Standard checkout flow — invoked by the wallet / card buttons
     *      inside the checkout payment-methods section. The JS posts the
     *      full checkout form (including `tejcart_checkout_nonce` and
     *      `tejcart_cart_totals_hash`) and we run `Checkout::process()` so
     *      the buyer's billing / shipping / validation all apply.
     *
     *   2. Express flow — invoked by product-page "Buy Now", cart-page
     *      "Buy with PayPal", the side-cart drawer, and the top-of-
     *      checkout express buttons. These buttons fire before the buyer
     *      has filled in (or even seen) the checkout form, so we create
     *      a minimal order directly from either the current session cart
     *      or a single product id and let PayPal collect the real
     *      billing / shipping on its approval sheet (shipping_preference
     *      = GET_FROM_FILE). The real addresses are persisted onto the
     *      TejCart order by `capture_order()` from the capture response.
     */
    public function create_order(): void {
        $this->require_nonce();
        $this->require_rate_limit( 'create_order', 10 );

        // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked above
        $is_express = ! empty( $_POST['express'] );
        if ( $is_express ) {
            $this->create_express_order();
            return;
        }

        $new_order_id = 0;
        $listener     = function ( $order_id ) use ( &$new_order_id ) {
            $new_order_id = (int) $order_id;
        };
        add_action( 'tejcart_checkout_order_processed', $listener, 10, 1 );

        try {
            $result = ( new Checkout() )->process();
        } catch ( \Throwable $e ) {
            remove_action( 'tejcart_checkout_order_processed', $listener, 10 );
            tejcart_log( 'PayPal create_order exception: ' . $e->getMessage(), 'error' );
            wp_send_json_error( array( 'message' => __( 'Could not create order.', 'tejcart' ) ), 500 );
        }
        remove_action( 'tejcart_checkout_order_processed', $listener, 10 );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        if ( ! $new_order_id ) {
            wp_send_json_error( array( 'message' => __( 'Order could not be created.', 'tejcart' ) ), 500 );
        }

        $paypal_order_id = tejcart_get_order_meta( $new_order_id, '_paypal_order_id' );
        if ( empty( $paypal_order_id ) ) {
            wp_send_json_error( array( 'message' => __( 'PayPal order could not be created.', 'tejcart' ) ), 500 );
        }

        // H-2 / H-3: bind the order to the session that created it so
        // later buyer-driven mutations can be checked against ownership.
        self::persist_session_owner( (int) $new_order_id );

        wp_send_json_success(
            array(
                'order_id'              => $paypal_order_id,
                'tejcart_order_id'      => $new_order_id,
                'wallet_shipping_nonce' => self::wallet_shipping_nonce( (string) $paypal_order_id ),
            )
        );
    }

    /**
     * Express-checkout order creation path.
     *
     * Called from {@see create_order()} when `$_POST['express']` is set.
     * Builds a minimal pending order either from the session cart or
     * from a single `product_id` + `quantity` (the product-page Buy Now
     * flow), then hands the resulting order to PayPal_API::create_order()
     * so the SDK can render its approval UI.
     */
    private function create_express_order(): void {
        // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked in create_order()
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked in create_order()
        $raw_quantity = isset( $_POST['quantity'] ) ? trim( (string) wp_unslash( $_POST['quantity'] ) ) : '1';
        // Default to 1 when omitted, otherwise the raw value must be a
        // positive integer string. absint() would silently turn "-5"
        // into 5 (and 0 into 0); reject those upstream so forged values
        // surface as 400s rather than being rewritten.
        if ( '' === $raw_quantity ) {
            $quantity = 1;
        } elseif ( preg_match( '/^[1-9][0-9]*$/', $raw_quantity ) ) {
            $quantity = (int) $raw_quantity;
        } else {
            wp_send_json_error( array( 'message' => __( 'Requested quantity is not allowed.', 'tejcart' ) ), 400 );
        }
        $shipping_total = 0.0;
        $tax_total      = 0.0;

        if ( $quantity > 999 ) {
            wp_send_json_error( array( 'message' => __( 'Requested quantity is not allowed.', 'tejcart' ) ), 400 );
        }

        // Layered ceilings on top of the per-IP `create_order` rate
        // limit (10/min) so an attacker botnet cannot mint thousands
        // of pending PayPal orders per hour for the same SKU. Each
        // pending order persists a `tejcart_orders` row, a stock
        // reservation, and a PayPal-side order resource — the orphan
        // sweeper runs hourly and prunes after 30 minutes, so absent
        // these ceilings ~600 dummy orders accumulate before the first
        // sweep, multiplied by the botnet IP count. See finding H-6.
        //
        //  - Per-(product_id, ip): 50 / day. A genuine buyer doesn't
        //    "Buy Now" the same SKU 50 times in 24h.
        //  - Per-session: 30 / hour. A buyer creating 30 distinct
        //    express orders in an hour is a bot.
        $this->require_express_ceilings( $product_id );

        $items          = array();
        $subtotal       = 0.0;
        $discount_total = 0.0;
        $coupon_code    = '';
        // Use the FILTERED active currency: the PDP express branch prices the
        // product via $product->get_price(), which is already converted to the
        // active display currency. Quantizing those amounts on the raw-setting
        // (base) currency's minor-unit grid would mis-round for 0/3-decimal
        // display currencies (JPY/KWD). The cart-mode branch below already
        // resolves the active currency; mirror it here.
        $currency       = (string) tejcart_get_currency();

        if ( $product_id > 0 ) {
            $product = tejcart_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                wp_send_json_error( array( 'message' => __( 'This product cannot be purchased.', 'tejcart' ) ), 400 );
            }

            // The single-product express branch only knows how
            // to price a leaf SKU. Variable products need a chosen
            // variation; bundles need their children enumerated; grouped
            // and external products cannot be purchased directly. Routing
            // these through the cart-mode flow (or the standard checkout)
            // is the supported path. Without this gate we'd build an order
            // at the parent's placeholder price (often 0) and lock stock
            // on the wrong SKU.
            if ( $product instanceof \TejCart\Product\Product_Types\Variable_Product
                 || $product instanceof \TejCart\Product\Product_Types\Bundle_Product
                 || $product instanceof \TejCart\Product\Product_Types\Grouped_Product
                 || $product instanceof \TejCart\Product\Product_Types\External_Product ) {
                wp_send_json_error(
                    array(
                        'message' => __(
                            'This product needs to be added to your cart before paying with PayPal.',
                            'tejcart'
                        ),
                    ),
                    400
                );
            }

            if ( method_exists( $product, 'manage_stock' ) && $product->manage_stock() ) {
                $available = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
                if ( $available < $quantity ) {
                    wp_send_json_error(
                        array(
                            'message' => sprintf(
                                /* translators: %d: available stock quantity */
                                __( 'Only %d of this item are in stock.', 'tejcart' ),
                                max( 0, $available )
                            ),
                        ),
                        400
                    );
                }
            }

            $unit_price = (float) $product->get_price();
            if ( $unit_price <= 0 ) {
                wp_send_json_error( array( 'message' => __( 'This product cannot be purchased at the current price.', 'tejcart' ) ), 400 );
            }
            // Quantize to the currency's minor-unit precision (not a
            // hardcoded 2) so JPY / KWD / BHD / OMR don't drift between
            // the cart row, the PayPal items[] payload, and the
            // AMOUNT_MISMATCH detector. Going via integer minor units
            // mirrors Cart_Calculator and keeps format_amount()
            // deterministic.
            $unit_minor = \TejCart\Money\Currency::to_minor_units( $unit_price, $currency );
            $line_minor = $unit_minor * (int) $quantity;
            $unit_price = (float) \TejCart\Money\Currency::from_minor_units( $unit_minor, $currency );
            $line_total = (float) \TejCart\Money\Currency::from_minor_units( $line_minor, $currency );

            $items[]  = array(
                'product_id'   => (int) $product->get_id(),
                'product_name' => (string) $product->get_name(),
                'quantity'     => (int) $quantity,
                'unit_price'   => $unit_price,
                'line_total'   => $line_total,
            );
            $subtotal = $line_total;
        } else {
            $cart = tejcart_get_cart();
            if ( ! $cart || $cart->is_empty() ) {
                wp_send_json_error( array( 'message' => __( 'Your cart is empty.', 'tejcart' ) ), 400 );
            }

            // Re-derive the cart-totals hash and compare against
            // the value the page mounted with. A cross-tab mutation between
            // PDP/cart render and the express-button click would otherwise
            // mean the buyer pays a different total than the one they saw.
            // We only enforce the hash when the JS side actually supplied
            // one (so the PDP single-product branch above is unaffected).
            // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked in create_order()
            $submitted_hash = isset( $_POST['tejcart_cart_totals_hash'] )
                // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked in create_order()
                ? sanitize_text_field( wp_unslash( $_POST['tejcart_cart_totals_hash'] ) )
                : '';
            if ( '' !== $submitted_hash && method_exists( $cart, 'get_totals_hash' ) ) {
                $expected_hash = $cart->get_totals_hash();
                if ( ! hash_equals( $expected_hash, $submitted_hash ) ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf(
                                'PayPal express order rejected: cart totals hash mismatch (expected %s, got %s).',
                                $expected_hash,
                                $submitted_hash
                            ),
                            'warning'
                        );
                    }
                    wp_send_json_error(
                        array( 'message' => __( 'Your cart totals have changed since you opened this page. Please review your order and try again.', 'tejcart' ) ),
                        409
                    );
                }
            }

            // Resolve currency before building amounts so to_minor_units
            // / from_minor_units quantize to the right precision per
            // currency (JPY=0, USD=2, KWD/BHD/OMR=3) instead of a
            // hardcoded 2-decimal round() (SEC-001).
            if ( method_exists( $cart, 'get_currency' ) ) {
                $cart_currency = (string) $cart->get_currency();
                if ( '' !== $cart_currency ) {
                    $currency = $cart_currency;
                }
            }

            foreach ( $cart->get_items() as $cart_item ) {
                $prod = $cart_item->get_product();
                if ( ! $prod || ! $prod->is_purchasable() ) {
                    wp_send_json_error(
                        array( 'message' => __( 'One or more items in your cart are no longer available.', 'tejcart' ) ),
                        400
                    );
                }
                // Cart-mode mirror of the PDP-branch product-type guard
                // above. Without this, a Bundle / Grouped / External
                // landed in the cart would be priced at whatever
                // `Cart_Item::get_price()` returns — Bundles depend on
                // their child items being added as separate cart rows,
                // and Grouped / External products are not directly
                // purchasable at all. Routing these through the
                // standard checkout (or fixing the cart contents)
                // is the supported path.
                if ( $prod instanceof \TejCart\Product\Product_Types\Variable_Product
                     || $prod instanceof \TejCart\Product\Product_Types\Bundle_Product
                     || $prod instanceof \TejCart\Product\Product_Types\Grouped_Product
                     || $prod instanceof \TejCart\Product\Product_Types\External_Product ) {
                    wp_send_json_error(
                        array(
                            'message' => __(
                                'One of the products in your cart cannot be paid for via PayPal express. Please use the standard checkout.',
                                'tejcart'
                            ),
                        ),
                        400
                    );
                }
                // Pre-round per-line so the PayPal sheet, the
                // TejCart order row, and the post-capture comparator agree
                // to the cent. See Cart_Calculator for the canonical
                // integer-cent pattern.
                //
                // Audit #2 / 02 H-1 — read the unit price from the
                // Cart_Item snapshot (`_price_at_add`), not the LIVE
                // product price. A merchant edit between add-to-cart and
                // the cart-page PayPal Express click would otherwise
                // silently charge the buyer the new price without
                // consent. Cart_Item::get_price() honours `_price_at_add`
                // and falls back to the live product price for legacy
                // rows; this is the same source Checkout::process() uses.
                // Quantize per the cart currency, not hardcoded 2 decimals.
                $unit_minor  = \TejCart\Money\Currency::to_minor_units( (float) $cart_item->get_price(), $currency );
                $qty         = (int) $cart_item->get_quantity();
                $line_minor  = $unit_minor * $qty;
                $unit        = (float) \TejCart\Money\Currency::from_minor_units( $unit_minor, $currency );
                $items[]     = array(
                    'product_id'   => (int) $prod->get_id(),
                    'product_name' => (string) $cart_item->get_name(),
                    'quantity'     => $qty,
                    'unit_price'   => $unit,
                    'line_total'   => (float) \TejCart\Money\Currency::from_minor_units( $line_minor, $currency ),
                );
            }

            $subtotal       = (float) \TejCart\Money\Currency::from_minor_units( \TejCart\Money\Currency::to_minor_units( (float) $cart->get_subtotal(), $currency ), $currency );
            $discount_total = method_exists( $cart, 'get_discount_total' )
                ? (float) \TejCart\Money\Currency::from_minor_units( \TejCart\Money\Currency::to_minor_units( (float) $cart->get_discount_total(), $currency ), $currency )
                : 0.0;

            // Carry the cart's shipping and tax onto the express order so
            // PayPal_API::create_order() can surface them in its breakdown.
            // The cart-page Smart Buttons fire onto an already-priced cart
            // (the order summary above renders these same numbers); hard-
            // coding them to zero here is what dropped the "Tax" line out
            // of the PayPal wallet sheet. The wallet's onShippingChange
            // callback PATCHes any subsequent recalc through
            // {@see self::update_shipping()}, so this seed is only the
            // initial state — it does not pin tax for the buyer's eventual
            // PayPal-side address.
            $shipping_total = method_exists( $cart, 'get_shipping_total' )
                ? (float) \TejCart\Money\Currency::from_minor_units( \TejCart\Money\Currency::to_minor_units( (float) $cart->get_shipping_total(), $currency ), $currency )
                : 0.0;
            $tax_total      = method_exists( $cart, 'get_tax_total' )
                ? (float) \TejCart\Money\Currency::from_minor_units( \TejCart\Money\Currency::to_minor_units( (float) $cart->get_tax_total(), $currency ), $currency )
                : 0.0;

            if ( method_exists( $cart, 'get_coupons' ) ) {
                $applied_codes = array_keys( (array) $cart->get_coupons() );
                $coupon_code   = implode( ', ', array_map( 'sanitize_text_field', $applied_codes ) );
            }

            // Cart currency was resolved before the items loop above
            // so to_minor_units() quantized to the right precision.
        }

        if ( empty( $items ) || $subtotal <= 0 ) {
            tejcart_log(
                sprintf(
                    'PayPal express order rejected: empty items or non-positive subtotal (product_id=%d, items=%d, subtotal=%s).',
                    $product_id,
                    count( $items ),
                    (string) $subtotal
                ),
                'error'
            );
            wp_send_json_error(
                array( 'message' => __( 'Your cart has no payable items.', 'tejcart' ) ),
                400
            );
        }

        /**
         * Filter whether the express PayPal flow can serve this set of
         * items. Listeners return a `WP_Error` to refuse the click; the
         * error message is surfaced to the buyer and the in-form
         * checkout PayPal button remains available.
         *
         * Express now fires `tejcart_checkout_validation` and
         * `tejcart_checkout_order_processed` so the Subscriptions
         * PayPal_Bridge can mint a vault block + create the
         * Subscription rows — sibling addons that still need to refuse
         * specific item shapes (e.g. country lock-outs, age gates) can
         * keep wiring into this filter, but the recurring-charge use
         * case no longer needs it.
         *
         * @param true|\WP_Error                    $allowed    True to allow, WP_Error to refuse.
         * @param array<int, array<string, mixed>>  $items      Express-order items (product_id, quantity, ...).
         * @param int                               $product_id PDP product id for "Buy Now", 0 for cart-mode.
         */
        $express_allowed = apply_filters( 'tejcart_paypal_express_allowed', true, $items, $product_id );
        if ( is_wp_error( $express_allowed ) ) {
            wp_send_json_error( array( 'message' => $express_allowed->get_error_message() ), 400 );
        }

        if ( ! preg_match( '/^[A-Z]{3}$/', strtoupper( $currency ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Store currency is not valid.', 'tejcart' ) ), 400 );
        }

        $min = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
        $max = (float) get_option( 'tejcart_cart_maximum_amount', 0 );
        if ( $min > 0 && $subtotal < $min ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: formatted price */
                        __( 'A minimum order of %s is required to checkout.', 'tejcart' ),
                        function_exists( 'tejcart_price' ) ? tejcart_price( $min ) : $min
                    ),
                ),
                400
            );
        }
        if ( $max > 0 && $subtotal > $max ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: formatted price */
                        __( 'Orders cannot exceed %s. Please remove items.', 'tejcart' ),
                        function_exists( 'tejcart_price' ) ? tejcart_price( $max ) : $max
                    ),
                ),
                400
            );
        }

        // Cart-level fees (gift wrap, …) for the cart-mode express flow. The
        // PDP "Buy Now" branch ($product_id > 0) prices a single product and
        // has no cart fees, so it is intentionally excluded. The cart's
        // get_fees_total() is already in the active currency.
        $express_fees_minor = 0;
        $express_cart       = null;
        if ( $product_id <= 0 ) {
            $express_cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
            if ( $express_cart && method_exists( $express_cart, 'get_fees_total' ) ) {
                $express_fees = (float) $express_cart->get_fees_total();
                if ( $express_fees > 0 ) {
                    $express_fees_minor = \TejCart\Money\Currency::to_minor_units( $express_fees, $currency );
                }
            }
        }

        // Quantize the final total to the cart's currency precision
        // (not a fixed 2 decimals) so JPY / KWD / BHD / OMR don't drift
        // between the displayed total, the PayPal payload, and the
        // capture-time AMOUNT_MISMATCH detector.
        $total_minor = max(
            0,
            \TejCart\Money\Currency::to_minor_units( $subtotal - $discount_total + $shipping_total + $tax_total, $currency ) + $express_fees_minor
        );
        $total       = (float) \TejCart\Money\Currency::from_minor_units( $total_minor, $currency );

        $order_data = array(
            'status'           => 'pending',
            'currency'         => $currency,
            'subtotal'         => $subtotal,
            'discount_total'   => $discount_total,
            'shipping_total'   => $shipping_total,
            'tax_total'        => $tax_total,
            'total'            => $total,
            'coupon_code'      => $coupon_code,
            'customer_email'   => is_user_logged_in() ? (string) wp_get_current_user()->user_email : '',
            'customer_name'    => '',
            'customer_id'      => get_current_user_id(),
            'payment_method'   => 'tejcart_paypal',
            'ip_address'       => \TejCart\Security\Rate_Limiter::get_client_ip(),
            'customer_note'    => '',
            'billing_address'  => wp_json_encode( array() ),
            'shipping_address' => wp_json_encode( array() ),
            'items'            => $items,
        );

        $order = \TejCart\Order\Order_Factory::create( $order_data );
        if ( is_wp_error( $order ) || ! $order ) {
            global $wpdb;
            $factory_error = is_wp_error( $order ) ? $order->get_error_message() : __( 'Order_Factory returned no order.', 'tejcart' );
            // Order_Factory captures wpdb->last_error BEFORE its ROLLBACK
            // and stuffs it into the WP_Error's data payload. Read it from
            // there first; fall back to the live wpdb->last_error for
            // non-Order_Factory failure paths.
            $error_data = is_wp_error( $order ) ? (array) $order->get_error_data() : array();
            $db_error   = '';
            if ( isset( $error_data['db_error'] ) && '' !== (string) $error_data['db_error'] ) {
                $db_error = (string) $error_data['db_error'];
            } elseif ( isset( $wpdb->last_error ) ) {
                $db_error = (string) $wpdb->last_error;
            }
            tejcart_log(
                sprintf(
                    'PayPal express order creation failed: %s%s',
                    $factory_error,
                    '' !== $db_error ? ' DB error: ' . $db_error : ''
                ),
                'error'
            );
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: underlying error message */
                        __( 'Could not create order: %s', 'tejcart' ),
                        $factory_error
                    ),
                ),
                500
            );
        }

        // Persist cart-level fees (gift wrap, …) onto the express order so the
        // gateway breakdown balances and the order/email itemise them — same
        // contract as the standard Checkout flow.
        if ( $express_fees_minor > 0 ) {
            $order->update_meta( '_tejcart_fees_total', (string) $express_fees_minor );
            if ( $express_cart && method_exists( $express_cart, 'get_fees' ) ) {
                $order->update_meta( '_tejcart_fees', (string) wp_json_encode( $express_cart->get_fees() ) );
            }
        }

        // Atomically reserve inventory against the freshly
        // minted order before we hand control to PayPal. Two buyers
        // racing for the last unit cannot both pass this check; the
        // loser sees a "sold out" error rather than an oversold capture
        // 30 seconds later. Reservation rows are released by
        // Stock_Reservation::release_for_order on cancel / fail / capture.
        $reservation_ok = ( new \TejCart\Cart\Stock_Reservation() )
            ->reserve_for_order( (int) $order->get_id(), $items );
        if ( ! $reservation_ok ) {
            if ( method_exists( $order, 'update_status' ) ) {
                $order->update_status( 'cancelled', __( 'Stock no longer available for express checkout.', 'tejcart' ) );
            }
            wp_send_json_error(
                array( 'message' => __( 'Sorry, one of the items in this order just sold out. Please refresh the page and try again.', 'tejcart' ) ),
                409
            );
        }

        /**
         * Fires when an express checkout flow has built a
         * pending order and reserved stock for it but has not yet
         * contacted the wallet provider. Listeners (analytics, abandoned
         * cart, mini-cart counter) can subscribe here for parity with the
         * `tejcart_add_to_cart` event that the cart-based flow emits, since
         * the express path intentionally bypasses Cart::add_item().
         *
         * @param int   $order_id TejCart pending order ID.
         * @param array $items    Item lines (product_id, quantity, unit_price, line_total).
         * @param array $context  Free-form context (gateway, source, ...).
         */
        do_action(
            'tejcart_express_purchase_started',
            (int) $order->get_id(),
            $items,
            array(
                'gateway' => 'tejcart_paypal',
                'source'  => $product_id > 0 ? 'product' : 'cart',
            )
        );

        // Fire the same pre-payment validation hook the in-form checkout
        // does so addons can mutate $_POST in lock-step with the standard
        // flow. The Subscriptions PayPal_Bridge consumes this to force
        // `tejcart_paypal_save_method=1` whenever the express cart
        // contains a Subscription_Product — without that nudge the vault
        // block below is never asked for and the resulting capture leaves
        // PayPal with no renewable token. Posted data is empty: the real
        // billing/shipping arrive later via persist_express_addresses().
        do_action( 'tejcart_checkout_validation', array() );

        // Mirror PayPal_Gateway::process_payment()'s save_method rule so
        // the express path vault-on-success behaves the same as the
        // in-form path. No vault-token picker on the express button, so
        // we skip the `$vault_token_id` clause from the in-form version.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in create_order()
        $paypal_settings = (array) get_option( 'tejcart_gateway_tejcart_paypal', array() );
        $vault_enabled   = ( $paypal_settings['save_payment_methods'] ?? 'yes' ) === 'yes';
        $save_method     = is_user_logged_in()
            && ! empty( $_POST['tejcart_paypal_save_method'] )
            && $vault_enabled;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $api          = PayPal_Gateway::get_shared_api();
        $paypal_order = $api->create_order( $order, '', $save_method );

        if ( is_wp_error( $paypal_order ) || empty( $paypal_order['id'] ) ) {
            $err = is_wp_error( $paypal_order )
                ? $paypal_order->get_error_message()
                : __( 'PayPal order could not be created.', 'tejcart' );
            if ( method_exists( $order, 'update_status' ) ) {
                $order->update_status( 'failed', $err );
            }
            wp_send_json_error( array( 'message' => $err ), 500 );
        }

        // M-7: refuse the write when another order already owns this PayPal id.
        \TejCart\Gateways\PayPal\PayPal_Gateway::record_paypal_id_meta(
            (int) $order->get_id(),
            '_paypal_order_id',
            (string) $paypal_order['id']
        );
        tejcart_update_order_meta( (int) $order->get_id(), '_paypal_express', 'yes' );

        // Mirror PayPal_Gateway::process_payment(): persist the intent
        // so maybe_save_vault_token() saves the minted token to the
        // buyer's payment methods after capture. Required for the
        // Subscriptions PayPal_Bridge to pick up the vault token via
        // capture_token_from_order / capture_token_on_order_paid.
        if ( $save_method ) {
            tejcart_update_order_meta( (int) $order->get_id(), '_paypal_save_method_intent', 'yes' );
            tejcart_update_order_meta( (int) $order->get_id(), '_paypal_save_method_user', (int) get_current_user_id() );
        }
        // H-2 / H-3: bind the order to the session that created it so
        // later buyer-driven mutations can be checked against ownership.
        self::persist_session_owner( (int) $order->get_id() );
        // Sign the express flag at order-creation time so a
        // later mutation (sibling plugin, admin tool, accidental DB edit)
        // can't silently flip a non-express order into "express" and
        // re-trigger the address overwrite path. The signature is
        // verified before persist_express_addresses() runs.
        tejcart_update_order_meta(
            (int) $order->get_id(),
            '_paypal_express_sig',
            self::sign_express_flag( (int) $order->get_id() )
        );

        wp_send_json_success(
            array(
                'order_id'              => (string) $paypal_order['id'],
                'tejcart_order_id'      => (int) $order->get_id(),
                'wallet_shipping_nonce' => self::wallet_shipping_nonce( (string) $paypal_order['id'] ),
            )
        );
    }

    /**
     * Apply the per-product / per-session create-order ceilings the
     * Express flow needs on top of the per-IP rate limiter.
     *
     * The per-IP `create_order` rate limit (10/min) is set by
     * {@see require_rate_limit()} earlier in the request. These
     * additional ceilings catch botnet floods that the per-IP gate
     * alone cannot:
     *
     *  - Per-(product_id, ip): 50 / day. A genuine buyer doesn't
     *    "Buy Now" the same SKU 50 times in 24h; an attacker
     *    enumerating SKU prices does.
     *  - Per-session-id (or per-IP if no session cookie): 30 / hour.
     *    Catches the bot-driven cart-mode flood that the per-product
     *    gate misses (cart-mode posts product_id=0).
     *
     * On limit-hit the response is HTTP 429 with a `Retry-After`
     * header and a JSON error body. See review finding H-6.
     *
     * @param int $product_id Product id when the express click came
     *                        from the PDP "Buy Now" surface, 0 for
     *                        the cart-mode express buttons.
     */
    private function require_express_ceilings( int $product_id ): void {
        if ( ! class_exists( \TejCart\Security\Rate_Limiter::class ) ) {
            return;
        }

        $ip = (string) \TejCart\Security\Rate_Limiter::get_client_ip();

        if ( $product_id > 0 ) {
            /**
             * Filter the per-product, per-IP daily ceiling on PayPal
             * Express order creation.
             *
             * @since 1.0.1
             *
             * @param int $limit Max express orders per (product, IP) per day.
             */
            $product_limit = (int) apply_filters(
                'tejcart_paypal_express_per_product_daily_limit',
                50
            );
            // Audit M-49 (PPCP F-018): log when a filter misconfigures
            // the ceiling to a non-positive value so operators notice.
            if ( $product_limit <= 0 && function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'PayPal express per-product daily limit resolved to ' . $product_limit . ' (disabled). Check tejcart_paypal_express_per_product_daily_limit filter.', 'warning' );
            }
            if ( $product_limit > 0 ) {
                $bucket = 'pp_express_p' . $product_id . '|' . $ip;
                if ( \TejCart\Security\Rate_Limiter::check_and_record(
                    'pp_express_per_product',
                    $bucket,
                    $product_limit,
                    DAY_IN_SECONDS
                ) ) {
                    if ( ! headers_sent() ) {
                        header( 'Retry-After: ' . DAY_IN_SECONDS );
                    }
                    wp_send_json_error(
                        array(
                            'message' => __( 'Too many express checkout attempts for this product. Please try again later.', 'tejcart' ),
                        ),
                        429
                    );
                }
            }
        }

        $session_id = '';
        // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked in create_order()
        if ( ! empty( $_COOKIE['tejcart_session'] ) ) {
            $session_id = sanitize_text_field( wp_unslash( (string) $_COOKIE['tejcart_session'] ) );
        }
        $session_identity = '' !== $session_id
            ? 's' . substr( hash( 'sha256', $session_id ), 0, 32 )
            : 'ip' . $ip;

        /**
         * Filter the per-session hourly ceiling on PayPal Express
         * order creation.
         *
         * @since 1.0.1
         *
         * @param int $limit Max express orders per session per hour.
         */
        $session_limit = (int) apply_filters(
            'tejcart_paypal_express_per_session_hourly_limit',
            30
        );
        if ( $session_limit > 0 ) {
            $bucket = 'pp_express_sess|' . $session_identity;
            if ( \TejCart\Security\Rate_Limiter::check_and_record(
                'pp_express_per_session',
                $bucket,
                $session_limit,
                HOUR_IN_SECONDS
            ) ) {
                if ( ! headers_sent() ) {
                    header( 'Retry-After: ' . HOUR_IN_SECONDS );
                }
                wp_send_json_error(
                    array(
                        'message' => __( 'Too many express checkout attempts. Please try again in an hour.', 'tejcart' ),
                    ),
                    429
                );
            }
        }
    }

    /**
     * Capture (or authorize) the approved PayPal order, persist capture /
     * payer / fraud metadata, and return the thank-you redirect.
     */
    public function capture_order(): void {
        $this->require_nonce();
        $this->require_rate_limit( 'capture_order', 10 );

        $paypal_order_id = $this->read_paypal_id( 'paypal_order_id' );
        $order_id        = PayPal_Gateway::find_order_id_by_paypal_id( $paypal_order_id );
        if ( PayPal_Gateway::PAYPAL_ID_COLLISION === $order_id ) {
            // Collision already logged + tejcart_paypal_id_collision
            // action fired inside find_order_id_by_paypal_id().
            wp_send_json_error( array( 'message' => __( 'PayPal order id is ambiguous; capture refused.', 'tejcart' ) ), 409 );
        }
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Order not found for the given PayPal payment.', 'tejcart' ) ), 404 );
        }

        // H-2 / H-3 family: only the buyer who initiated the order can
        // capture against it. The PayPal-side amount-mismatch guard
        // already protects the merchant, but capture-by-stranger could
        // still race-spend a stale approval the legitimate buyer had
        // intended to abandon.
        $this->require_order_ownership( (int) $order_id );

        // Refuse capture when shipping is enabled and the order needs shipping
        // but the buyer never picked a method on the PayPal sheet. Without
        // this guard, express buttons (PDP / cart / mini-cart) capture
        // payment for an order whose `shipping_total` is still 0 — the
        // merchant gets paid but has no chosen carrier. Runs before the
        // capture lock so the buyer can retry after picking a method.
        //
        // The chosen-method lookup honours both `_shipping_method_id` (the
        // PayPal-internal key written by the wallet-side picker) and
        // `_shipping_method` (the canonical TejCart key written by the
        // standard checkout flow). Without the second fallback, a PPCP
        // checkout-page buyer who picked "Flat Rate" on the form but never
        // touched the PayPal sheet's shipping picker would be rejected at
        // capture, even though the order already has a method, shipping
        // total, and carrier persisted.
        $pre_capture_order = tejcart_get_order( $order_id );
        if ( $pre_capture_order && self::order_needs_shipping( $pre_capture_order ) ) {
            $chosen_method = self::chosen_shipping_method_for_order( (int) $order_id );
            if ( '' === $chosen_method ) {
                wp_send_json_error(
                    array( 'message' => __( 'Please choose a shipping method in the PayPal checkout before completing payment.', 'tejcart' ) ),
                    400
                );
            }
        }

        $capture_lock_key = 'tejcart_pp_cap_lock_' . md5( $paypal_order_id );
        $now              = time();
        // Audit #47 / 02 M-5 — TTL bumped from 2 min to 5 min and
        // made filterable. The previous 2 min was too short for
        // buyers on poor networks: a slow capture exceeded the
        // window, the buyer's retry minted a new attempt, and the
        // original capture's PayPal callback collided (409 to a
        // buyer whose payment had succeeded).
        $lock_ttl         = (int) apply_filters( 'tejcart_paypal_capture_lock_ttl', 5 * MINUTE_IN_SECONDS );
        if ( $lock_ttl <= 0 ) {
            $lock_ttl = 5 * MINUTE_IN_SECONDS;
        }
        $lock_value       = array( 'acquired' => $now, 'expires' => $now + $lock_ttl );

        // S-4: try the custom-table lock first to avoid alloptions churn.
        // Falls back to legacy add_option for installs where the
        // tejcart_locks table has not yet been provisioned.
        $use_lock_table = class_exists( \TejCart\Core\Lock::class );
        $acquired = $use_lock_table
            ? \TejCart\Core\Lock::claim( 'pp_cap_' . md5( $paypal_order_id ), $lock_ttl, 'paypal_capture' )
            : add_option( $capture_lock_key, $lock_value, '', 'no' );
        if ( ! $acquired ) {
            $existing_capture = (string) tejcart_get_order_meta( $order_id, '_paypal_capture_id' );
            if ( '' !== $existing_capture ) {
                /**
                 * PAY-005 — opt-in PayPal-side verification of the
                 * stored capture id before responding success on a
                 * retry. Default false keeps the historical "echo
                 * what we already stored" fast path. Stores that have
                 * seen stale-meta drift can flip this on so the
                 * response is only sent when PayPal still owns the
                 * capture.
                 *
                 * @since 1.0.1
                 *
                 * @param bool   $verify     Default false.
                 * @param int    $order_id   TejCart order id.
                 * @param string $capture_id Stored PayPal capture id.
                 */
                $verify = (bool) apply_filters(
                    'tejcart_paypal_verify_stored_capture_on_retry',
                    false,
                    $order_id,
                    $existing_capture
                );
                if ( $verify ) {
                    $api          = PayPal_Gateway::get_shared_api();
                    $remote_check = $api->get_capture( $existing_capture );
                    if ( is_wp_error( $remote_check ) || ! is_array( $remote_check ) || empty( $remote_check['id'] ) ) {
                        wp_send_json_error(
                            array( 'message' => __( 'Stored payment record is out of sync with PayPal. Please retry the checkout.', 'tejcart' ) ),
                            409
                        );
                    }
                }
                wp_send_json_success(
                    array(
                        'redirect'   => $this->return_url_for( $order_id ),
                        'capture_id' => $existing_capture,
                    )
                );
            }

            // Refuse the retry unconditionally. The scheduled cleanup
            // (and the shorter TTL above) will free the lock; racing two
            // update_option() re-acquires would let both racers fall
            // through to /capture and double-fire post-capture side
            // effects.
            wp_send_json_error(
                array( 'message' => __( 'This payment is already being finalised. Please wait a moment before retrying.', 'tejcart' ) ),
                409
            );
        }
        // Prefer Action Scheduler when available — survives
        // DISABLE_WP_CRON because AS has its own runner. Fall back to
        // WP-Cron so the cleanup still gets scheduled in stripped-down
        // installs (the opportunistic sweep below remains the
        // belt-and-braces against missed runs).
        $cleanup_run_at = $now + $lock_ttl + MINUTE_IN_SECONDS;
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                $cleanup_run_at,
                'tejcart_cleanup_webhook_option',
                array( $capture_lock_key ),
                'tejcart'
            );
        } else {
            wp_schedule_single_event( $cleanup_run_at, 'tejcart_cleanup_webhook_option', array( $capture_lock_key ) );
        }

        // Opportunistic batched sweep — mirrors the M-3 pattern for
        // webhook dedup options. Runs ~0.5% of the time normally so the
        // wp_options table doesn't grow unbounded on hosts where
        // WP-Cron is jammed (the per-event cleanup we scheduled above
        // never fires). N-H3 (follow-up to F-H7): when DISABLE_WP_CRON
        // is set and Action Scheduler is unavailable, none of the
        // per-event cleanups will ever fire, so boost the sweep
        // probability ~10x. Correctness is independent of the sweep —
        // the lock honours its own TTL.
        $cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $as_available   = class_exists( '\ActionScheduler' ) || function_exists( 'as_schedule_single_action' );
        $sweep_one_in_n = ( $cron_disabled && ! $as_available ) ? 20 : 200;
        if ( wp_rand( 1, $sweep_one_in_n ) === 1 ) {
            self::sweep_expired_capture_locks( $now );
        }

        $gateway = PayPal_Gateway::get_shared_instance();
        $api     = $gateway->get_api();
        $intent  = strtoupper( (string) $gateway->get_option( 'payment_action', 'capture' ) );

        // Gold-grade tax correctness for express buttons across every product
        // type. Pure-digital express carts never hit the
        // onShippingAddressChange callback, so reconcile tax against the
        // PayPal-collected address and PATCH the order amount before capture.
        // No-ops when the total is already correct (the common case) and is
        // resilient to a rejected PATCH so a sale is never lost.
        $this->reconcile_express_tax_before_capture( $paypal_order_id, (int) $order_id, $api );

        // Deterministic idempotency key per (PP order id, intent). A
        // transport-level retry of the same logical capture (e.g.
        // FastCGI restart, Action Scheduler re-fire) reuses the key
        // and PayPal returns the original response instead of double-
        // capturing. Operators who deliberately want a fresh attempt
        // (manual recapture after refund) bump the attempt counter via
        // the `tejcart_paypal_capture_attempt` filter.
        $capture_attempt = (int) apply_filters(
            'tejcart_paypal_capture_attempt',
            1,
            $paypal_order_id,
            $order_id,
            $intent
        );
        if ( $capture_attempt < 1 ) {
            $capture_attempt = 1;
        }
        $request_id = ( 'AUTHORIZE' === $intent )
            ? Idempotency_Key::for_authorize( $paypal_order_id, $capture_attempt )
            : Idempotency_Key::for_capture( $paypal_order_id, $capture_attempt );

        $result = ( 'AUTHORIZE' === $intent )
            ? $api->authorize_order( $paypal_order_id, $request_id )
            : $api->capture_order( $paypal_order_id, $request_id );

        if ( is_wp_error( $result ) ) {
            tejcart_log( 'PayPal capture/authorize failed: ' . $result->get_error_message(), 'error' );
            delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }

            // DUPLICATE_INVOICE_ID at capture time means PayPal has seen
            // this invoice_id on a previous successful capture in the same
            // merchant account. The stale `_paypal_order_id` on this TejCart
            // order points at a PayPal order that can never be captured, so
            // we drop it — the next button click triggers a fresh
            // create_order which mints a new invoice_id (attempt counter +
            // random nonce) and recovers cleanly.
            $err_data = (array) $result->get_error_data();
            $issue    = strtoupper( (string) ( $err_data['issue'] ?? '' ) );
            if ( 'DUPLICATE_INVOICE_ID' === $issue ) {
                tejcart_update_order_meta( $order_id, '_paypal_order_id', '' );
            }

            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        if ( 'AUTHORIZE' === $intent ) {
            $auth_id = sanitize_text_field( (string) $this->array_dig( $result, array( 'purchase_units', 0, 'payments', 'authorizations', 0, 'id' ) ) );
            if ( $auth_id ) {
                tejcart_update_order_meta( $order_id, '_paypal_auth_id', $auth_id );
            }

            // Pure-digital stores get a "manual capture" friction
            // surprise on first install otherwise. When the store has no
            // shippable products the sensible default flips to "yes".
            $has_shippable        = (bool) apply_filters( 'tejcart_store_has_shippable_products', true );
            $virtual_default      = $has_shippable ? 'no' : 'yes';
            $auto_capture_virtual = 'yes' === (string) $gateway->get_option( 'capture_virtual_only', $virtual_default );
            $order                = tejcart_get_order( $order_id );
            $capture_id           = '';

            if ( $auto_capture_virtual && '' !== $auth_id && $order && $this->is_virtual_only_order( $order ) ) {
                $captured = $api->capture_authorization( $auth_id );
                if ( is_wp_error( $captured ) ) {
                    // P-M3: the authorization succeeded but the immediate
                    // capture failed, so funds are NOT settled. Do not fall
                    // through to wp_send_json_success (which would send the
                    // buyer to the thank-you page for an uncaptured order).
                    // Mirror the synchronous-capture decline branch: place
                    // the order on-hold and surface a non-success response.
                    tejcart_log( 'PayPal virtual-only auto-capture failed: ' . $captured->get_error_message(), 'error' );
                    $msg = __( 'Your payment was authorized but could not be captured. Please contact us before re-ordering.', 'tejcart' );
                    if ( method_exists( $order, 'update_status' ) && 'on-hold' !== $order->get_status() ) {
                        $order->update_status( 'on-hold', $msg );
                    }
                    delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
                    wp_send_json_error(
                        array(
                            'message'  => $msg,
                            'redirect' => method_exists( $order, 'get_view_order_url' ) ? (string) $order->get_view_order_url() : '',
                        ),
                        402
                    );
                } else {
                    $capture_id = sanitize_text_field( (string) ( $captured['id'] ?? '' ) );
                    // Only a genuine capture-id collision diverts to on-hold;
                    // an absent id preserves the prior behaviour (promote), so
                    // this change can never hold an order it didn't hold before.
                    if ( '' !== $capture_id ) {
                        $meta_recorded = PayPal_Gateway::record_transaction_meta( $order_id, $capture_id );
                        if ( is_wp_error( $meta_recorded ) ) {
                            // The funds settled but the capture id is owned by
                            // another order. Hold for manual review rather than
                            // promoting a paid-but-unrefundable order.
                            $msg = __( 'Your payment was captured but needs manual review before completion. Please contact us.', 'tejcart' );
                            if ( method_exists( $order, 'update_status' ) && 'on-hold' !== $order->get_status() ) {
                                $order->update_status( 'on-hold', $msg );
                            }
                            delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
                            wp_send_json_error(
                                array(
                                    'message'  => $msg,
                                    'redirect' => method_exists( $order, 'get_view_order_url' ) ? (string) $order->get_view_order_url() : '',
                                ),
                                402
                            );
                        }
                    }
                    if ( method_exists( $order, 'update_status' ) && 'processing' !== $order->get_status() ) {
                        $order->update_status(
                            'processing',
                            __( 'Virtual-only order auto-captured after PayPal authorization.', 'tejcart' )
                        );
                    }
                }
            }

            delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }

            wp_send_json_success(
                array(
                    'redirect'         => $this->return_url_for( $order_id ),
                    'authorization_id' => $auth_id,
                    'capture_id'       => $capture_id,
                )
            );
        }

        $captures   = $this->array_dig( $result, array( 'purchase_units', 0, 'payments', 'captures' ) );
        $captures   = is_array( $captures ) ? $captures : array();
        $capture    = is_array( $captures[0] ?? null ) ? $captures[0] : array();
        $capture_id = sanitize_text_field( (string) ( $capture['id'] ?? '' ) );

        $card_gateway = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_card' ) : null;
        if ( $card_gateway instanceof Card_Gateway ) {
            $sca_check = PayPal_API::validate_card_3ds_outcome( $result, $card_gateway->get_3ds_policy() );
            if ( is_wp_error( $sca_check ) ) {
                tejcart_log(
                    sprintf( 'PayPal SCA check failed on order #%d: %s', $order_id, $sca_check->get_error_message() ),
                    'error'
                );
                $order_obj = tejcart_get_order( $order_id );
                if ( $order_obj && method_exists( $order_obj, 'update_status' ) ) {
                    $order_obj->update_status( 'on-hold', $sca_check->get_error_message() );
                }
                delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
                wp_send_json_error( array( 'message' => $sca_check->get_error_message() ), 402 );
            }
        }

        if ( $capture_id ) {
            $meta_recorded = PayPal_Gateway::record_transaction_meta( $order_id, $capture_id );
            if ( is_wp_error( $meta_recorded ) ) {
                // M-7: another order already owns this capture id. The funds
                // settled, but we could not bind the capture id to THIS order,
                // so promoting it to processing would leave a paid order with
                // no `_paypal_capture_id` (un-refundable, mis-swept as orphan).
                // Hold for manual review instead.
                $order_obj = tejcart_get_order( $order_id );
                $msg       = __( 'Payment received but requires manual review — you will be contacted shortly.', 'tejcart' );
                if ( $order_obj && method_exists( $order_obj, 'update_status' ) && 'on-hold' !== $order_obj->get_status() ) {
                    $order_obj->update_status( 'on-hold', $msg );
                }
                delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
                wp_send_json_error(
                    array(
                        'message'  => $msg,
                        'redirect' => ( $order_obj && method_exists( $order_obj, 'get_view_order_url' ) ) ? (string) $order_obj->get_view_order_url() : '',
                    ),
                    402
                );
            }
        }

        $capture_status = strtoupper( (string) ( $capture['status'] ?? '' ) );
        if ( in_array( $capture_status, array( 'DECLINED', 'FAILED' ), true ) ) {
            $reason = (string) ( $capture['status_details']['reason'] ?? '' );
            $msg    = '' !== $reason
                ? sprintf(
                    /* translators: %s: PayPal reason code */
                    __( 'Your payment was declined (%s). Please try a different method.', 'tejcart' ),
                    $reason
                )
                : __( 'Your payment was declined. Please try a different method.', 'tejcart' );
            tejcart_log( sprintf( 'PayPal capture status %s on order #%d: %s', $capture_status, $order_id, $reason ), 'error' );
            $order_obj = tejcart_get_order( $order_id );
            if ( $order_obj && method_exists( $order_obj, 'update_status' ) ) {
                $order_obj->update_status( 'failed', $msg );
            }
            delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
            wp_send_json_error( array( 'message' => $msg ), 402 );
        }

        $this->persist_payer_meta( $order_id, $result );
        $this->persist_wallet_funding_source( $order_id, $result );
        $this->persist_fraud_meta( $order_id, $capture );
        $this->maybe_save_vault_token( $order_id, $result );

        if ( 'yes' === (string) tejcart_get_order_meta( $order_id, '_paypal_express' )
             && self::express_flag_signature_valid( $order_id ) ) {
            $this->persist_express_addresses( $order_id, $result );
        }

        $order = tejcart_get_order( $order_id );

        if ( $order && method_exists( $order, 'get_total' ) ) {
            $captured_amount     = isset( $capture['amount']['value'] ) ? (float) $capture['amount']['value'] : 0.0;
            $raw_captured_currency = isset( $capture['amount']['currency_code'] ) ? strtoupper( (string) $capture['amount']['currency_code'] ) : '';
            // Reject anything that doesn't match the ISO-4217 shape so a
            // malformed PayPal response ("XYZ123" / numeric / lowercase)
            // can't propagate downstream as if it were a real currency.
            $captured_currency = preg_match( '/^[A-Z]{3}$/', $raw_captured_currency ) ? $raw_captured_currency : '';
            $order_total       = (float) $order->get_total();
            $order_currency    = strtoupper( (string) $order->get_currency() );

            // Compare in integer minor units against the order's
            // currency. Float subtraction with a 0.01 tolerance is
            // unsafe in three-decimal currencies (KWD, BHD, OMR) and
            // meaningless in zero-decimal ones (JPY, KRW). When the
            // captured currency is empty we still compute against the
            // order's currency — but treat that as a mismatch (we
            // received no currency to authoritatively reconcile against).
            $captured_minor    = \TejCart\Money\Currency::to_minor_units( $captured_amount, $order_currency );
            $expected_minor    = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );
            $amount_mismatch   = $captured_minor !== $expected_minor;
            $currency_mismatch = '' === $captured_currency || $captured_currency !== $order_currency;

            if ( $amount_mismatch || $currency_mismatch ) {
                if ( method_exists( $order, 'update_status' ) && 'on-hold' !== $order->get_status() ) {
                    $order->update_status(
                        'on-hold',
                        sprintf(
                            /* translators: 1: captured amount, 2: captured currency, 3: expected total, 4: expected currency */
                            __( 'PayPal capture mismatch: received %1$s %2$s, expected %3$s %4$s. Order placed on hold for manual review.', 'tejcart' ),
                            $captured_amount,
                            $captured_currency,
                            $order_total,
                            $order_currency
                        )
                    );
                }

                tejcart_log(
                    sprintf(
                        'PayPal capture mismatch on order #%d: captured %s %s (%d minor), expected %s %s (%d minor).',
                        $order_id,
                        $captured_amount,
                        $captured_currency,
                        $captured_minor,
                        $order_total,
                        $order_currency,
                        $expected_minor
                    ),
                    'error'
                );

                delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }
                // F-PPCP-001: Return error so the JS layer does NOT navigate
                // to the thank-you page for an order that is on-hold. The
                // buyer is shown a review message; the merchant sees the
                // on-hold order with the mismatch note already attached above.
                wp_send_json_error(
                    array(
                        'message'  => __( 'Payment received but requires manual review — you will be contacted shortly.', 'tejcart' ),
                        'redirect' => method_exists( $order, 'get_view_order_url' ) ? (string) $order->get_view_order_url() : '',
                    ),
                    402
                );
            }
        }

        if ( $order && method_exists( $order, 'add_note' ) ) {
            // Always log the capture, even if the webhook beat the AJAX
            // round-trip and already flipped the order to 'processing'.
            // Otherwise the timeline silently swallows the payment event.
            // The funding source was persisted above, so name the actual
            // wallet (Google Pay / Apple Pay / Venmo) the buyer used rather
            // than the generic "PayPal".
            $captured_via = method_exists( $order, 'get_payment_method_title' ) && '' !== (string) $order->get_payment_method_title()
                ? (string) $order->get_payment_method_title()
                : __( 'PayPal', 'tejcart' );
            $order->add_note(
                sprintf(
                    /* translators: 1: payment method / wallet name, 2: PayPal capture ID. */
                    __( 'Payment captured via %1$s. Capture ID: %2$s.', 'tejcart' ),
                    $captured_via,
                    '' !== (string) $capture_id ? (string) $capture_id : __( '(unknown)', 'tejcart' )
                )
            );
        }

        // Mirror Checkout::process()'s post-payment hook for the express
        // path. The in-form Smart-Buttons flow already fires this hook
        // via Checkout::process() at create_order time, so gate on the
        // signed `_paypal_express` order meta to avoid a double-fire
        // (which would make the Subscriptions Checkout_Integration
        // create duplicate Subscription rows). Fired BEFORE the status
        // moves to `processing` so listeners that create child records
        // — most notably maybe_create_subscriptions — finish before
        // activate_for_order runs on tejcart_order_status_processing.
        if ( $order
            && 'yes' === (string) tejcart_get_order_meta( $order_id, '_paypal_express' )
            && self::express_flag_signature_valid( $order_id )
        ) {
            /** This duplicates the action documented at Checkout::process(). */
            do_action( 'tejcart_checkout_order_processed', (int) $order_id, array() );
        }

        if ( $order && method_exists( $order, 'update_status' ) && 'processing' !== $order->get_status() ) {
            // Status note here would duplicate the explicit "Payment
            // captured via PayPal." note above, so pass an empty reason
            // and let Order::update_status() synthesise its own
            // "Order status changed from … to processing." entry.
            $order->update_status( 'processing' );
        }

        // Every other gateway empties the cart in its own
        // process_payment() success path; PayPal Express historically did
        // not, so a buyer who completed checkout via the wallet sheet
        // would still see the same items in /cart/ on the next visit.
        // Mirror the COD/Stripe behaviour here so the synchronous capture
        // path converges with the rest of the gateways. The webhook /
        // async path is handled by Order_Manager's listener on
        // tejcart_order_status_processing.
        $this->maybe_empty_cart_for_buyer();

        delete_option( $capture_lock_key ); if ( $use_lock_table ) { \TejCart\Core\Lock::release( 'pp_cap_' . md5( $paypal_order_id ) ); }

        wp_send_json_success(
            array(
                'redirect'   => $this->return_url_for( $order_id ),
                'capture_id' => $capture_id,
            )
        );
    }

    /**
     * Buyer-side cancel hop.
     *
     * Triggered from tejcart-paypal.js `onCancel` / `onError` so a
     * pending PayPal-Express order does not linger in the database after
     * the buyer abandons the wallet sheet. Idempotent: a stale or
     * already-completed order is silently acknowledged so a duplicate
     * cancel from a slow client cannot reverse a successful capture.
     *
     * Stock reservations recorded against the pending order at create
     * time are released here via the
     * `tejcart_restore_stock_for_order` action listener Stock_Reservation
     * already exposes.
     */
    public function cancel_order(): void {
        $this->require_nonce();
        $this->require_rate_limit( 'cancel_order', 30 );

        $paypal_order_id = $this->read_paypal_id( 'paypal_order_id' );
        $order_id        = PayPal_Gateway::find_order_id_by_paypal_id( $paypal_order_id );
        if ( ! $order_id ) {
            wp_send_json_success( array( 'cancelled' => false, 'reason' => 'unknown_order' ) );
        }

        // H-3: only the buyer who initiated the order may cancel it.
        $this->require_order_ownership( (int) $order_id );

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_success( array( 'cancelled' => false, 'reason' => 'load_failed' ) );
        }

        $status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
        if ( 'pending' !== $status ) {
            // Race with the success path; acknowledge silently. The internal
            // status is intentionally NOT echoed back: the endpoint accepts a
            // page-scoped nonce so any visitor can call it, and revealing the
            // current status enables order-state enumeration (M-4).
            wp_send_json_success( array( 'cancelled' => false ) );
        }

        if ( method_exists( $order, 'update_status' ) ) {
            $order->update_status( 'cancelled', __( 'Buyer cancelled the PayPal payment.', 'tejcart' ) );
        }

        do_action( 'tejcart_restore_stock_for_order', (int) $order_id, $order );

        wp_send_json_success( array( 'cancelled' => true ) );
    }

    /**
     * Empty the current buyer's session cart, when one exists.
     *
     * Called from {@see capture_order()} after a successful PayPal
     * capture transitions the order into `processing`. Defensively guarded
     * so a unit-test request without a session, or a wp-cli invocation,
     * does not fatal.
     */
    private function maybe_empty_cart_for_buyer(): void {
        if ( ! function_exists( 'tejcart' ) ) {
            return;
        }
        $tejcart = tejcart();
        if ( ! $tejcart || ! method_exists( $tejcart, 'cart' ) ) {
            return;
        }
        $cart = $tejcart->cart();
        if ( $cart && method_exists( $cart, 'empty_cart' ) ) {
            $cart->empty_cart();
        }
    }

    /**
     * Resync PayPal's amount breakdown with the TejCart order's
     * authoritative totals after an onShippingChange event.
     *
     * When the buyer's address is known (either
     * pulled from the live PayPal order or POSTed alongside the
     * callback), we also rebuild `shipping.options[]` for the order so
     * the PayPal wallet renders a method picker on subsequent re-renders.
     */
    public function update_shipping(): void {
        $this->require_nonce();
        $this->require_rate_limit( 'update_shipping', 60 );

        $paypal_order_id = $this->read_paypal_id( 'paypal_order_id' );
        $order_id        = PayPal_Gateway::find_order_id_by_paypal_id( $paypal_order_id );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'tejcart' ) ), 404 );
        }

        // H-2: only the buyer who initiated the order may rewrite its
        // shipping address / chosen method.
        $this->require_order_ownership( (int) $order_id );

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Could not load order.', 'tejcart' ) ), 500 );
        }

        // Global shipping kill-switch. When the merchant has shipping
        // disabled (the new default for fresh installs), a stale page that
        // still has the SDK's onShippingAddressChange wired up must not
        // hit Shipping_Manager. Persist the "not required" sentinel so
        // capture_order()'s shipping-required guard short-circuits and
        // return success with the order's current totals so the wallet
        // sheet keeps rendering with no method picker. Mirrors the
        // symmetric tax flag: when tax is off, the tax pipeline is skipped
        // wholesale rather than running with an empty rate table.
        if ( ! self::shipping_globally_enabled() ) {
            tejcart_update_order_meta( (int) $order_id, '_shipping_method_id', self::SHIPPING_METHOD_NOT_REQUIRED );
            $total_no_ship    = (float) $order->get_total();
            $currency_no_ship = strtoupper( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' );
            $subtotal_no_ship = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total_no_ship;
            $tax_no_ship      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
            $discount_no_ship = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
            wp_send_json_success(
                array(
                    'total'    => PayPal_API::format_amount( $total_no_ship, $currency_no_ship ),
                    'currency' => $currency_no_ship,
                    'subtotal' => PayPal_API::format_amount( $subtotal_no_ship, $currency_no_ship ),
                    'shipping' => PayPal_API::format_amount( 0.0, $currency_no_ship ),
                    'tax'      => PayPal_API::format_amount( $tax_no_ship, $currency_no_ship ),
                    'discount' => PayPal_API::format_amount( $discount_no_ship, $currency_no_ship ),
                    'options'  => array(),
                )
            );
        }

        // On-page checkout flow: when this order was created with the buyer's
        // address locked (SET_PROVIDED_ADDRESS — see
        // PayPal_API::create_order), PayPal renders NO shipping-method picker
        // in the wallet sheet and rejects any shipping.options[] PATCH with
        // HTTP 422 SHIPPING_OPTIONS_NOT_SUPPORTED. The v6 SDK still fires
        // onShippingAddressChange once for the provided address, so this
        // endpoint is reached even though there is nothing to choose. The
        // shipping method the buyer already selected on our checkout page is
        // charged via amount.breakdown.shipping, so echo the order's existing
        // totals straight back (empty options, no PATCH) instead of issuing a
        // PATCH that can only fail and surface "Your order can't be shipped to
        // this address." in the wallet popup.
        $created_shipping_preference = strtoupper(
            (string) tejcart_get_order_meta( (int) $order_id, '_paypal_shipping_preference' )
        );
        if ( 'SET_PROVIDED_ADDRESS' === $created_shipping_preference ) {
            $total_locked    = (float) $order->get_total();
            $currency_locked = strtoupper( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' );
            $subtotal_locked = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total_locked;
            $shipping_locked = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
            $tax_locked      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
            $discount_locked = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
            wp_send_json_success(
                array(
                    'total'    => PayPal_API::format_amount( $total_locked, $currency_locked ),
                    'currency' => $currency_locked,
                    'subtotal' => PayPal_API::format_amount( $subtotal_locked, $currency_locked ),
                    'shipping' => PayPal_API::format_amount( $shipping_locked, $currency_locked ),
                    'tax'      => PayPal_API::format_amount( $tax_locked, $currency_locked ),
                    'discount' => PayPal_API::format_amount( $discount_locked, $currency_locked ),
                    'options'  => array(),
                )
            );
        }

        $api = PayPal_Gateway::get_shared_api();

        // Consume the address from the JS payload first.
        // PayPal v6 fires onShippingAddressChange BEFORE the address is
        // committed to the order resource, so a GET on the order returns an
        // empty `purchase_units[0].shipping.address`. Falling back to that
        // is what made the wallet sheet render zero options. The PayPal v6
        // SDK exposes shippingAddress as { city, state, countryCode,
        // postalCode } — the JS client copies those into our POST under
        // shipping_country / shipping_state / shipping_city /
        // shipping_postcode (see buildAddressPayload in tejcart-paypal.js).
        $applied_post_address = $this->apply_posted_shipping_address( $order );
        if ( ! $applied_post_address ) {
            // Fallback for the rare case the JS dropped the payload — keep
            // the PayPal-side address lookup as a safety net.
            $this->maybe_seed_shipping_address_from_paypal( $order, $paypal_order_id, $api );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() above
        $selected_option_id = isset( $_POST['selected_shipping_option_id'] )
            ? sanitize_key( wp_unslash( (string) $_POST['selected_shipping_option_id'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( '' !== $selected_option_id ) {
            $this->apply_chosen_shipping_method( $order, $selected_option_id );
        }

        // Recompute tax now that the buyer's destination is known. Without
        // this the order keeps the tax computed at creation time (before any
        // address existed), so address-based rules and live providers like
        // TaxJar are never consulted and a stale/zero tax is patched to and
        // captured by PayPal.
        $this->recalculate_express_order_tax( $order );

        // Re-load any totals the chosen method updated.
        $total    = (float) $order->get_total();
        $currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD';
        $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total;
        $shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
        $tax      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
        $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;

        $shipping_options = $api->build_shipping_options_for_order( $order );

        if ( empty( $shipping_options ) ) {
            // Digital-only express orders pass through the same v6
            // callback (the gateway's shipping_preference is GET_FROM_FILE
            // by default), but no Shipping_Manager method matches because
            // none of the items need shipping. Resolve silently in that
            // case — the capture-side guard at order_needs_shipping()
            // already gates capture for orders that DO need a method.
            // Only reject as COUNTRY_ERROR when the order needs shipping
            // but the address has no available methods.
            if ( ! self::order_needs_shipping( $order ) ) {
                // Persist a sentinel so downstream readers can distinguish
                // "no method required" from "buyer abandoned the wallet".
                tejcart_update_order_meta( (int) $order_id, '_shipping_method_id', self::SHIPPING_METHOD_NOT_REQUIRED );
                $currency_upper = strtoupper( $currency );
                wp_send_json_success(
                    array(
                        'total'    => PayPal_API::format_amount( $total, $currency_upper ),
                        'currency' => $currency_upper,
                        'subtotal' => PayPal_API::format_amount( $subtotal, $currency_upper ),
                        'shipping' => PayPal_API::format_amount( $shipping, $currency_upper ),
                        'tax'      => PayPal_API::format_amount( $tax, $currency_upper ),
                        'discount' => PayPal_API::format_amount( $discount, $currency_upper ),
                        'options'  => array(),
                    )
                );
            }
            // The PayPal v6 SDK lets us reject the change with a documented
            // reason code so the buyer sees a real error in the wallet
            // sheet instead of a silently empty list. The JS side maps
            // `reject_reason` onto `actions.reject(reason)`.
            //
            // The `message` is preserved for devtools / log visibility
            // (audit, support diagnostics, future telemetry), but the
            // frontend never renders it on the checkout page — the JS
            // routes the rejection through actions.reject() into the
            // wallet popup, see tejcart-paypal.js runShippingUpdate.
            tejcart_log(
                sprintf( 'PayPal shipping rejected for order #%d: COUNTRY_ERROR (no methods for posted address).', (int) $order_id ),
                'info'
            );
            wp_send_json_error(
                array(
                    'message'       => __( 'No shipping methods available for this address.', 'tejcart' ),
                    'reject_reason' => 'COUNTRY_ERROR',
                ),
                400
            );
        }

        // Default-select the first option when the buyer hasn't picked one
        // yet, then re-sync totals so the PATCH amount matches what the
        // sheet will display.
        if ( '' === $selected_option_id ) {
            $first_id = isset( $shipping_options[0]['id'] ) ? (string) $shipping_options[0]['id'] : '';
            if ( '' !== $first_id ) {
                $this->apply_chosen_shipping_method( $order, $first_id );
                $this->recalculate_express_order_tax( $order );
                $total    = (float) $order->get_total();
                $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total;
                $shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
                $tax      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
                $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
                $shipping_options = $api->build_shipping_options_for_order( $order );
            }
        }

        // Combine amount + shipping-options into a single PATCH. Issuing
        // them separately 422s with PREFERRED_SHIPPING_OPTION_AMOUNT_MISMATCH
        // when the buyer switches options mid-flow, because each PATCH is
        // validated against the not-yet-updated other side of the order.
        $reference_id   = (string) $order_id;
        $patch_result   = $api->patch_order_amount_with_shipping_options( $paypal_order_id, $reference_id, $total, $subtotal, $shipping, $tax, $currency, $discount, $shipping_options );
        if ( is_wp_error( $patch_result ) ) {
            tejcart_log(
                sprintf( 'PayPal shipping PATCH failed on order #%d: %s', $order_id, $patch_result->get_error_message() ),
                'warning'
            );
            wp_send_json_error(
                array(
                    'message'       => $patch_result->get_error_message(),
                    'reject_reason' => 'METHOD_UNAVAILABLE',
                ),
                400
            );
        }

        $currency_upper = strtoupper( $currency );
        wp_send_json_success(
            array(
                'total'    => PayPal_API::format_amount( $total, $currency_upper ),
                'currency' => $currency_upper,
                'subtotal' => PayPal_API::format_amount( $subtotal, $currency_upper ),
                'shipping' => PayPal_API::format_amount( $shipping, $currency_upper ),
                'tax'      => PayPal_API::format_amount( $tax, $currency_upper ),
                'discount' => PayPal_API::format_amount( $discount, $currency_upper ),
                'options'  => $shipping_options,
            )
        );
    }

    /**
     * Canonical shipping-options endpoint for the Google Pay and Apple Pay
     * wallet sheets.
     *
     * Both wallets need to display a method picker that recalculates totals
     * inside their own popup, separate from the PayPal sheet's PATCH-driven
     * flow. They re-use the same `build_shipping_options_for_order()`
     * computation but ask for it in their own preferred shape:
     *
     *  - Google Pay: `shippingOptions[]` → { id, label, description }
     *  - Apple Pay:  `shippingMethods[]` → { identifier, label, detail, amount }
     *
     * Both also need an updated total + line items breakdown so the sheet's
     * order summary stays in sync with what we'll capture.
     */
    public function wallet_shipping(): void {
        $paypal_order_id = $this->read_paypal_id( 'paypal_order_id' );
        $this->require_wallet_shipping_nonce( $paypal_order_id );
        $this->require_rate_limit( 'wallet_shipping', 60 );

        $order_id = PayPal_Gateway::find_order_id_by_paypal_id( $paypal_order_id );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'tejcart' ) ), 404 );
        }

        // Audit H-12 (PPCP F-004): previously missing — the nonce
        // alone doesn't prove the caller owns the order. Without this
        // check an attacker who learns a victim's paypal_order_id can
        // persist arbitrary shipping addresses/methods.
        $this->require_order_ownership( (int) $order_id );

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Could not load order.', 'tejcart' ) ), 500 );
        }

        // Global shipping kill-switch — same reasoning as update_shipping().
        // Apple Pay / Google Pay sheets MUST NOT prompt for a shipping
        // method when the merchant has shipping turned off. Return an
        // empty options/methods payload so the sheet renders an
        // address-only flow that goes straight to confirm.
        if ( ! self::shipping_globally_enabled() ) {
            tejcart_update_order_meta( (int) $order_id, '_shipping_method_id', self::SHIPPING_METHOD_NOT_REQUIRED );
            $currency_no_ship = strtoupper( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' );
            $total_no_ship    = (float) $order->get_total();
            $subtotal_no_ship = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total_no_ship;
            $tax_no_ship      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
            $discount_no_ship = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
            // F-PPCP-002: pass $currency_no_ship to every format_amount()
            // call so JPY/KWD/BHD/OMR merchants get the correct decimal
            // precision instead of the USD-defaulted 2 decimal places.
            wp_send_json_success(
                array(
                    'currency'             => $currency_no_ship,
                    'total'                => PayPal_API::format_amount( $total_no_ship, $currency_no_ship ),
                    'subtotal'             => PayPal_API::format_amount( $subtotal_no_ship, $currency_no_ship ),
                    'shipping'             => '0.00',
                    'tax'                  => PayPal_API::format_amount( $tax_no_ship, $currency_no_ship ),
                    'discount'             => PayPal_API::format_amount( $discount_no_ship, $currency_no_ship ),
                    'selected_id'          => '',
                    'paypal_options'       => array(),
                    'google_pay_options'   => array(),
                    'apple_pay_methods'    => array(),
                    'apple_pay_total'      => array(
                        'label'  => (string) get_bloginfo( 'name' ),
                        'amount' => PayPal_API::format_amount( $total_no_ship, $currency_no_ship ),
                        'type'   => 'final',
                    ),
                    'apple_pay_line_items' => array(),
                )
            );
        }

        $applied = $this->apply_posted_shipping_address( $order );
        if ( ! $applied ) {
            // Apple Pay's onshippingmethodselected only forwards the
            // chosen method — not the address — so on the second hop
            // the JS posts an empty country. Fall through to the
            // address the previous onshippingcontactselected call
            // already persisted onto the order rather than rejecting.
            $existing = (array) $order->get_shipping_address();
            $stored_country = strtoupper( (string) ( $existing['shipping_country'] ?? $existing['country'] ?? '' ) );
            if ( ! preg_match( '/^[A-Z]{2}$/', $stored_country ) ) {
                wp_send_json_error(
                    array(
                        'message'       => __( 'A shipping country is required.', 'tejcart' ),
                        'reject_reason' => 'COUNTRY_ERROR',
                    ),
                    400
                );
            }
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() above
        $selected_option_id = isset( $_POST['selected_shipping_option_id'] )
            ? sanitize_key( wp_unslash( (string) $_POST['selected_shipping_option_id'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( '' !== $selected_option_id ) {
            $this->apply_chosen_shipping_method( $order, $selected_option_id );
        }

        $api              = PayPal_Gateway::get_shared_api();
        $shipping_options = $api->build_shipping_options_for_order( $order );

        if ( empty( $shipping_options ) ) {
            // Digital-only orders surface no methods — same reasoning as
            // update_shipping(). Return an empty options list rather than
            // a hard COUNTRY_ERROR so the wallet sheet can still confirm.
            if ( ! self::order_needs_shipping( $order ) ) {
                // Persist a sentinel so downstream readers can distinguish
                // "no method required" from "buyer abandoned the wallet".
                tejcart_update_order_meta( (int) $order_id, '_shipping_method_id', self::SHIPPING_METHOD_NOT_REQUIRED );
                $currency = strtoupper( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' );
                $total    = (float) $order->get_total();
                // Surface the real breakdown lines: a digital-only order can
                // still carry tax (and a coupon discount), so reporting the
                // gross total as "subtotal" with tax/discount hardcoded to
                // 0.00 misrepresented the wallet sheet. Pull the persisted
                // order figures instead so subtotal + tax − discount == total.
                $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total;
                $tax      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
                $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
                // F-PPCP-002: pass $currency to format_amount() — digital-only
                // early return path must honour JPY/KWD/BHD/OMR precision.
                wp_send_json_success(
                    array(
                        'currency'             => $currency,
                        'total'                => PayPal_API::format_amount( $total, $currency ),
                        'subtotal'             => PayPal_API::format_amount( $subtotal, $currency ),
                        'shipping'             => '0.00',
                        'tax'                  => PayPal_API::format_amount( $tax, $currency ),
                        'discount'             => PayPal_API::format_amount( $discount, $currency ),
                        'selected_id'          => '',
                        'paypal_options'       => array(),
                        'google_pay_options'   => array(),
                        'apple_pay_methods'    => array(),
                        'apple_pay_total'      => array(
                            'label'  => (string) get_bloginfo( 'name' ),
                            'amount' => PayPal_API::format_amount( $total, $currency ),
                            'type'   => 'final',
                        ),
                        'apple_pay_line_items' => array(),
                    )
                );
            }

            // Wallet sheet (Google Pay / Apple Pay) renders its own
            // human-readable copy from `reject_reason`. The `message`
            // here is preserved for devtools / log visibility only — the
            // frontend never renders it on the checkout page.
            tejcart_log(
                sprintf( 'PayPal wallet shipping rejected for order #%d: COUNTRY_ERROR (no methods for posted address).', (int) $order_id ),
                'info'
            );
            wp_send_json_error(
                array(
                    'message'       => __( 'No shipping methods available for this address.', 'tejcart' ),
                    'reject_reason' => 'COUNTRY_ERROR',
                ),
                400
            );
        }

        if ( '' === $selected_option_id ) {
            $first_id = isset( $shipping_options[0]['id'] ) ? (string) $shipping_options[0]['id'] : '';
            if ( '' !== $first_id ) {
                $this->apply_chosen_shipping_method( $order, $first_id );
                $shipping_options = $api->build_shipping_options_for_order( $order );
            }
        }

        $currency = strtoupper( method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' );
        $total    = (float) $order->get_total();
        $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : $total;
        $shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
        $tax      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
        $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;

        // Patch the live PayPal order too, so the eventual confirmOrder call
        // reconciles against the same totals the wallet sheet displayed.
        // Combine amount + shipping-options into a single PATCH so the
        // selected-option amount and breakdown.shipping are validated
        // together (separate PATCHes 422 with
        // PREFERRED_SHIPPING_OPTION_AMOUNT_MISMATCH when the buyer switches
        // options mid-flow).
        $reference_id = (string) $order_id;
        $patch_result = $api->patch_order_amount_with_shipping_options( $paypal_order_id, $reference_id, $total, $subtotal, $shipping, $tax, $currency, $discount, $shipping_options );

        if ( is_wp_error( $patch_result ) ) {
            tejcart_log(
                sprintf( 'PayPal wallet-shipping PATCH failed on order #%d: %s', $order_id, $patch_result->get_error_message() ),
                'warning'
            );
        }

        $store_label = (string) get_bloginfo( 'name' );
        $shipping_label = (string) ( method_exists( $order, 'get_shipping_method' ) ? $order->get_shipping_method() : '' );

        $google_options = array();
        $apple_options  = array();
        $selected_id    = '';
        foreach ( $shipping_options as $option ) {
            $id    = isset( $option['id'] ) ? (string) $option['id'] : '';
            $label = isset( $option['label'] ) ? (string) $option['label'] : $id;
            $value = isset( $option['amount']['value'] ) ? (string) $option['amount']['value'] : '0.00';
            if ( '' === $id ) {
                continue;
            }
            if ( ! empty( $option['selected'] ) ) {
                $selected_id = $id;
            }
            $cost_display = function_exists( 'tejcart_price' ) ? wp_strip_all_tags( (string) tejcart_price( (float) $value ) ) : $value;

            $google_options[] = array(
                'id'          => $id,
                'label'       => $label,
                'description' => $cost_display,
            );
            $apple_options[] = array(
                'identifier' => $id,
                'label'      => $label,
                'detail'     => $cost_display,
                // F-PPCP-002: pass $currency so JPY/KWD/BHD/OMR get correct
                // decimal precision on per-method shipping amount.
                'amount'     => PayPal_API::format_amount( (float) $value, $currency ),
            );
        }

        if ( '' === $selected_id && ! empty( $google_options ) ) {
            $selected_id = $google_options[0]['id'];
        }

        // F-PPCP-002: pass $currency to every format_amount() call in the main
        // wallet_shipping() response so JPY (0 dp) and KWD/BHD/OMR (3 dp)
        // merchants get correct decimal precision instead of the USD default.
        $line_items = array(
            array(
                'label'  => __( 'Subtotal', 'tejcart' ),
                'amount' => PayPal_API::format_amount( $subtotal, $currency ),
                'type'   => 'final',
            ),
            array(
                'label'  => __( 'Shipping', 'tejcart' ),
                'amount' => PayPal_API::format_amount( $shipping, $currency ),
                'type'   => 'final',
            ),
        );
        if ( $tax > 0 ) {
            $line_items[] = array(
                'label'  => __( 'Tax', 'tejcart' ),
                'amount' => PayPal_API::format_amount( $tax, $currency ),
                'type'   => 'final',
            );
        }
        if ( $discount > 0 ) {
            $line_items[] = array(
                'label'  => __( 'Discount', 'tejcart' ),
                'amount' => '-' . PayPal_API::format_amount( $discount, $currency ),
                'type'   => 'final',
            );
        }

        wp_send_json_success(
            array(
                'currency'              => $currency,
                'total'                 => PayPal_API::format_amount( $total, $currency ),
                'subtotal'              => PayPal_API::format_amount( $subtotal, $currency ),
                'shipping'              => PayPal_API::format_amount( $shipping, $currency ),
                'tax'                   => PayPal_API::format_amount( $tax, $currency ),
                'discount'              => PayPal_API::format_amount( $discount, $currency ),
                'selected_id'           => $selected_id,
                'paypal_options'        => $shipping_options,
                'google_pay_options'    => $google_options,
                'apple_pay_methods'     => $apple_options,
                'apple_pay_total'       => array(
                    'label'  => '' !== $store_label ? $store_label : 'Store',
                    'amount' => PayPal_API::format_amount( $total, $currency ),
                    'type'   => 'final',
                ),
                'apple_pay_line_items'  => $line_items,
                'shipping_method_label' => $shipping_label,
            )
        );
    }

    /**
     * Read shipping_country / shipping_state / shipping_city /
     * shipping_postcode from the AJAX payload, validate, and merge them
     * into the order's persisted shipping_address blob.
     *
     * Returns true when a valid ISO-3166 alpha-2 country was applied, false
     * when nothing usable was posted.
     */
    private function apply_posted_shipping_address( $order ): bool {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_address' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $country_raw = isset( $_POST['shipping_country'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_country'] ) )  : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $state_raw   = isset( $_POST['shipping_state'] )    ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_state'] ) )    : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $city_raw    = isset( $_POST['shipping_city'] )     ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_city'] ) )     : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $postal_raw  = isset( $_POST['shipping_postcode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_postcode'] ) ) : '';
        // Optional full-address fields. Wallet shipping pickers only reveal
        // the street line / recipient name to the merchant once the buyer
        // authorises the payment (Google Pay's final `paymentData`), so the
        // dynamic recalculation callbacks post only country/state/city/
        // postcode while the final apply also forwards these. They are
        // merged in only when present so an earlier full address is never
        // clobbered by a later partial recalculation (or vice versa).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $line1_raw   = isset( $_POST['shipping_address_1'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_address_1'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $line2_raw   = isset( $_POST['shipping_address_2'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_address_2'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in every caller
        $name_raw    = isset( $_POST['shipping_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shipping_name'] ) ) : '';

        $country = strtoupper( $country_raw );
        if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return false;
        }

        $updates = array(
            'shipping_country'  => $country,
            'shipping_state'    => mb_substr( $state_raw, 0, 64 ),
            'shipping_city'     => mb_substr( $city_raw, 0, 100 ),
            'shipping_postcode' => mb_substr( $postal_raw, 0, 32 ),
        );
        if ( '' !== $line1_raw ) {
            $updates['shipping_address_1'] = mb_substr( $line1_raw, 0, 200 );
        }
        if ( '' !== $line2_raw ) {
            $updates['shipping_address_2'] = mb_substr( $line2_raw, 0, 200 );
        }
        if ( '' !== $name_raw ) {
            $parts = preg_split( '/\s+/', trim( $name_raw ), 2 );
            $updates['shipping_first_name'] = sanitize_text_field( (string) ( $parts[0] ?? '' ) );
            $updates['shipping_last_name']  = sanitize_text_field( (string) ( $parts[1] ?? '' ) );
        }

        $existing = (array) $order->get_shipping_address();
        $merged   = array_merge( $existing, $updates );

        if ( method_exists( $order, 'set' ) ) {
            $order->set( 'shipping_address', wp_json_encode( $merged ) );
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
        }

        return true;
    }

    /**
     * If the local order has no shipping country recorded yet — typical
     * for express flows that mint an empty pending order before PayPal
     * collects the address — pull the latest address from PayPal and
     * persist it onto the order so build_shipping_options_for_order()
     * has something to enumerate methods against.
     */
    private function maybe_seed_shipping_address_from_paypal( $order, string $paypal_order_id, PayPal_API $api ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_address' ) ) {
            return;
        }
        $existing = (array) $order->get_shipping_address();
        $country  = strtoupper( (string) ( $existing['shipping_country'] ?? $existing['country'] ?? '' ) );
        if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return;
        }

        $details = $api->get_order_details( $paypal_order_id );
        if ( is_wp_error( $details ) || ! is_array( $details ) ) {
            return;
        }

        $shipping = $details['purchase_units'][0]['shipping']['address'] ?? array();
        if ( ! is_array( $shipping ) ) {
            return;
        }

        $merged = array(
            'shipping_country'  => sanitize_text_field( strtoupper( (string) ( $shipping['country_code'] ?? '' ) ) ),
            'shipping_state'    => sanitize_text_field( (string) ( $shipping['admin_area_1'] ?? '' ) ),
            'shipping_city'     => sanitize_text_field( (string) ( $shipping['admin_area_2'] ?? '' ) ),
            'shipping_postcode' => sanitize_text_field( (string) ( $shipping['postal_code'] ?? '' ) ),
        );

        if ( ! preg_match( '/^[A-Z]{2}$/', $merged['shipping_country'] ) ) {
            return;
        }

        $combined = array_merge( $existing, $merged );

        if ( method_exists( $order, 'set' ) ) {
            $order->set( 'shipping_address', wp_json_encode( $combined ) );
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
        }
    }

    /**
     * Request a vault setup token the SDK can hand to the buyer so they
     * can save a payment instrument without charging it.
     */
    public function create_setup_token(): void {
        $this->require_nonce();
        $this->require_login();
        $this->require_rate_limit( 'setup_token', 5 );

        // Per-user daily ceiling on top of the per-IP limit (M-8). PayPal's
        // setup-token quota is the merchant's real concern: 5/min × 60 × 24
        // × N IPs = thousands of unused tokens at PayPal even when each IP
        // stays below the per-IP cap. Bound the same logged-in identity
        // regardless of which IP they connect from.
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 ) {
            $user_key = 'setup_token_user_' . $user_id;
            if ( \TejCart\Security\Rate_Limiter::check_and_record( $user_key, '*', 20, DAY_IN_SECONDS ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Too many setup tokens issued today. Please try again later.', 'tejcart' ),
                    ),
                    429
                );
            }
        }

        // Nonce verified by require_nonce() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'paypal';
        $result = PayPal_Gateway::get_shared_api()->create_setup_token( $source );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $token_id = (string) ( $result['id'] ?? '' );

        // H-4: bind the issued setup_token to the WP user that minted it.
        // Setup tokens are merchant-scoped on PayPal's side, not user-scoped,
        // so without this binding a logged-in attacker who learned another
        // user's setup_token (XSS, MITM, shared machine) could submit it
        // under their own session and have the victim's payment instrument
        // vaulted on the attacker's account.
        if ( '' !== $token_id ) {
            // Audit M-5 (F-019): tightened from 1 hour to 15 minutes.
            // Most vault setups complete in under a minute.
            set_transient(
                'tejcart_pp_setup_' . hash( 'sha256', $token_id ),
                (int) get_current_user_id(),
                15 * MINUTE_IN_SECONDS
            );
        }

        wp_send_json_success(
            array(
                'id'     => $token_id,
                'status' => $result['status'] ?? '',
            )
        );
    }

    /**
     * Exchange an approved setup token for a permanent vault token and
     * persist it on the current user's saved methods list.
     */
    public function save_payment_token(): void {
        $this->require_nonce();
        $this->require_login();

        // Nonce verified by require_nonce() above.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $setup_token = isset( $_POST['setup_token'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_token'] ) ) : '';
        if ( '' === $setup_token ) {
            wp_send_json_error( array( 'message' => __( 'Missing setup token.', 'tejcart' ) ), 400 );
        }

        // H-4: refuse to consume a setup_token that wasn't minted by the
        // current user. Otherwise an attacker who learned another user's
        // setup_token (XSS/MITM/shared device) could submit it under their
        // own session and vault the victim's payment instrument on the
        // attacker's account, then charge it from any future checkout.
        $bind_key    = 'tejcart_pp_setup_' . hash( 'sha256', $setup_token );
        $raw_binding = get_transient( $bind_key );
        // Audit M-5 (F-020): distinguish expired (false) from mismatched.
        if ( false === $raw_binding ) {
            wp_send_json_error(
                array( 'message' => __( 'Your save-payment session has expired. Please try again.', 'tejcart' ) ),
                403
            );
        }
        $bound_user = (int) $raw_binding;
        if ( (int) get_current_user_id() !== $bound_user ) {
            wp_send_json_error( array( 'message' => __( 'Invalid setup token.', 'tejcart' ) ), 403 );
        }
        // Single-use: clear the binding before the API call so a duplicate
        // submission cannot replay the token.
        delete_transient( $bind_key );

        $result = PayPal_Gateway::get_shared_api()->create_payment_token( $setup_token );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $token_id = sanitize_text_field( $result['id'] ?? '' );
        if ( '' === $token_id ) {
            wp_send_json_error( array( 'message' => __( 'PayPal did not return a vault token.', 'tejcart' ) ), 500 );
        }

        [ $type, $label ] = $this->derive_vault_label( $result['payment_source'] ?? array() );

        $saved = \TejCart\Customer\Payment_Methods::instance()->save_method(
            get_current_user_id(),
            array( 'token_id' => $token_id, 'type' => $type, 'label' => $label )
        );

        wp_send_json_success( $saved );
    }

    /**
     * Validate the configured PayPal credentials by forcing a fresh
     * access-token request. Admin-only.
     */
    public function test_connection(): void {
        $this->require_admin_nonce( 'tejcart_paypal_test_connection' );

        $gateway = PayPal_Gateway::get_shared_instance();
        // Audit H-14 (PPCP F-007): the old key was un-fingerprinted;
        // PayPal_API writes fingerprinted transients. Wildcard-delete
        // the matching env prefix so the stale token is actually cleared.
        global $wpdb;
        $env = $gateway->is_sandbox() ? 'sandbox' : 'live';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_tejcart_paypal_access_token_' . $env . '_%',
                '_transient_timeout_tejcart_paypal_access_token_' . $env . '_%'
            )
        );

        $token = $gateway->get_api()->get_access_token();
        if ( is_wp_error( $token ) ) {
            // Audit L-17 (PPCP F-028): map raw PayPal error strings
            // to operator-friendly hints instead of echoing verbatim.
            $raw_msg = $token->get_error_message();
            $hint    = $raw_msg;
            if ( str_contains( $raw_msg, 'Client Authentication failed' ) ) {
                $hint = __( 'Client ID or Secret is incorrect. Double-check the credentials in your PayPal Developer Dashboard.', 'tejcart' );
            } elseif ( str_contains( $raw_msg, 'invalid_client' ) ) {
                $hint = __( 'Invalid client credentials. Make sure you are using the correct environment (sandbox vs live).', 'tejcart' );
            }
            wp_send_json_error( array( 'message' => $hint ), 400 );
        }

        wp_send_json_success(
            array(
                'message'     => __( 'Connection successful — credentials are valid.', 'tejcart' ),
                'environment' => $gateway->is_sandbox() ? 'sandbox' : 'live',
            )
        );
    }

    /**
     * Register the site's REST webhook URL with PayPal, reusing any
     * existing subscription for the same URL to prevent duplicates, and
     * persist the returned webhook id into the gateway settings.
     */
    public function register_webhook(): void {
        $this->require_admin_nonce( 'tejcart_paypal_register_webhook' );

        $webhook_url = rest_url( 'tejcart/v1/webhook/paypal' );
        if ( 0 !== strpos( $webhook_url, 'https://' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'PayPal requires an HTTPS webhook URL. Enable HTTPS on this site before registering.', 'tejcart' ) ),
                400
            );
        }

        // `home_url` / `site_url` could already have been mutated
        // by a compromised admin to point at an internal host. Refuse to
        // hand PayPal a URL whose host resolves to a private/loopback
        // range; the SDK would otherwise be instructed to deliver signed
        // event payloads inside the trust boundary.
        $parsed_host = wp_parse_url( $webhook_url, PHP_URL_HOST );
        $host        = is_string( $parsed_host ) ? strtolower( $parsed_host ) : '';
        if ( '' === $host ) {
            wp_send_json_error(
                array( 'message' => __( 'Webhook URL host could not be parsed.', 'tejcart' ) ),
                400
            );
        }
        $loopback_hosts = array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback' );
        if ( in_array( $host, $loopback_hosts, true ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Webhook URL must use a public, externally-reachable host.', 'tejcart' ) ),
                400
            );
        }
        // Reject literal IPs that fall inside private or reserved ranges.
        // For hostnames, also reject any A/AAAA lookup that resolves to
        // such a range — this is what stops a poisoned `siteurl` whose
        // host is e.g. `internal.example.com` → 10.0.0.x.
        $host_for_check = trim( $host, '[]' );
        $candidates     = array();
        if ( filter_var( $host_for_check, FILTER_VALIDATE_IP ) ) {
            $candidates[] = $host_for_check;
        } elseif ( function_exists( 'gethostbynamel' ) ) {
            $resolved = @gethostbynamel( $host_for_check ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ( is_array( $resolved ) ) {
                $candidates = $resolved;
            }
        }
        foreach ( $candidates as $candidate ) {
            if ( ! filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'Webhook URL must resolve to a public IP address.', 'tejcart' ) ),
                    400
                );
            }
        }

        $gateway  = PayPal_Gateway::get_shared_instance();
        $api      = $gateway->get_api();
        $existing = $api->list_webhooks();
        if ( is_wp_error( $existing ) ) {
            wp_send_json_error( array( 'message' => $existing->get_error_message() ), 400 );
        }

        $webhook_id = '';
        $reused     = false;
        foreach ( (array) ( $existing['webhooks'] ?? array() ) as $hook ) {
            if ( isset( $hook['url'], $hook['id'] ) && $hook['url'] === $webhook_url ) {
                $webhook_id = (string) $hook['id'];
                $reused     = true;
                break;
            }
        }

        if ( '' === $webhook_id ) {
            $created = $api->create_webhook( $webhook_url );
            if ( is_wp_error( $created ) ) {
                wp_send_json_error( array( 'message' => $created->get_error_message() ), 400 );
            }
            $webhook_id = (string) ( $created['id'] ?? '' );
        }

        if ( '' === $webhook_id ) {
            wp_send_json_error( array( 'message' => __( 'PayPal did not return a webhook ID.', 'tejcart' ) ), 500 );
        }

        $gateway->update_option( 'webhook_id', $webhook_id );

        wp_send_json_success(
            array(
                'webhook_id'  => $webhook_id,
                'webhook_url' => $webhook_url,
                'reused'      => $reused,
            )
        );
    }

    /**
     * Abort the request unless the AJAX nonce verifies. The defensive
     * `exit` after wp_send_json_error matters because Brain\Monkey
     * (and unit-test stubs in general) often turn wp_send_json_error
     * into a normal return — without the explicit exit, a test
     * scaffold that intercepts wp_send_json_error would silently let
     * the caller continue past a failed nonce check. In production
     * wp_send_json_error calls wp_die() and the exit is unreachable.
     */
    private function require_nonce(): void {
        if ( ! check_ajax_referer( 'tejcart_paypal', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
            exit;
        }
    }

    /**
     * Build the action string used for the per-order wallet-shipping nonce.
     *
     * Binding the nonce to the paypal_order_id closes the cross-order replay
     * window that the page-scoped nonce alone leaves open: a stolen page
     * nonce only works for the specific PayPal order it was issued for.
     */
    public static function wallet_shipping_nonce_action( string $paypal_order_id ): string {
        return 'tejcart_paypal_wallet_shipping_' . $paypal_order_id;
    }

    /**
     * Mint the per-order wallet-shipping nonce. Returned in `create_order`
     * responses and consumed by the JS `fetchWalletShipping()` helper.
     */
    public static function wallet_shipping_nonce( string $paypal_order_id ): string {
        return wp_create_nonce( self::wallet_shipping_nonce_action( $paypal_order_id ) );
    }

    /**
     * Prune expired `tejcart_pp_cap_lock_*` rows from `wp_options`.
     *
     * Called opportunistically from `capture_order()` so the table
     * is kept clean even on hosts that disable WP-Cron. Each value
     * is a `['acquired' => int, 'expires' => int]` array; rows whose
     * `expires` is older than the supplied $now (with a 1-minute
     * grace) are deleted in a bounded batch (limit 200) to keep
     * the sweep cheap. Mirrors Checkout::sweep_expired_idempotency_locks.
     * See review finding M-5.
     *
     * @internal
     * @param int $now Current timestamp.
     * @return int Number of rows deleted.
     */
    public static function sweep_expired_capture_locks( int $now ): int {
        global $wpdb;

        $cutoff = $now - MINUTE_IN_SECONDS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
                $wpdb->esc_like( 'tejcart_pp_cap_lock_' ) . '%'
            )
        );
        if ( empty( $rows ) ) {
            return 0;
        }

        $deleted = 0;
        foreach ( $rows as $row ) {
            $value   = maybe_unserialize( $row->option_value, array( 'allowed_classes' => false ) );
            $expires = is_array( $value ) && isset( $value['expires'] ) ? (int) $value['expires'] : 0;
            if ( $expires > 0 && $expires < $cutoff ) {
                delete_option( $row->option_name );
                $deleted++;
            }
        }

        if ( $deleted > 0 && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'PayPal capture-lock sweeper removed %d expired row(s).', $deleted ),
                'info'
            );
        }

        return $deleted;
    }

    /**
     * Verify the order-bound wallet-shipping nonce.
     *
     * Audit H-12 (PPCP F-004): removed the page-scoped
     * `tejcart_paypal` fallback that defeated the per-order binding.
     * The page nonce was valid for ANY PayPal order id the attacker
     * learned, making the per-order nonce pointless. The ownership
     * check added to wallet_shipping() is the real authz gate now;
     * the per-order nonce is defence-in-depth.
     */
    private function require_wallet_shipping_nonce( string $paypal_order_id ): void {
        $action = self::wallet_shipping_nonce_action( $paypal_order_id );
        if ( check_ajax_referer( $action, '_wpnonce', false ) ) {
            return;
        }
        wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
    }

    /**
     * Enforce a per-IP rate limit on a PayPal AJAX endpoint.
     *
     * Aborts the request with HTTP 429 and a Retry-After header when the
     * limit is exceeded. The limit is filterable via
     * tejcart_rate_limit_paypal_{endpoint} so merchants can tune thresholds
     * without code edits.
     *
     * @since 1.0.0
     *
     * @param string $endpoint       Endpoint key (used in cache bucket + filter name).
     * @param int    $limit          Requests per minute.
     * @param int    $window_seconds Window size in seconds.
     */
    private function require_rate_limit( string $endpoint, int $limit, int $window_seconds = 60 ): void {
        if ( ! class_exists( \TejCart\Security\Rate_Limiter::class ) ) {
            return;
        }

        /**
         * Filter the per-IP rate limit on a PayPal AJAX endpoint.
         *
         * @since 1.0.0
         *
         * @param int    $limit    Max requests per window.
         * @param string $endpoint Endpoint key.
         */
        $limit = (int) apply_filters( 'tejcart_rate_limit_paypal_' . $endpoint, $limit, $endpoint );

        if ( $limit <= 0 ) {
            return;
        }

        $ip       = \TejCart\Security\Rate_Limiter::get_client_ip();
        $limited  = \TejCart\Security\Rate_Limiter::check_and_record(
            'paypal_' . $endpoint,
            $ip,
            $limit,
            $window_seconds
        );

        if ( $limited ) {
            if ( ! headers_sent() ) {
                header( 'Retry-After: ' . $window_seconds );
            }
            wp_send_json_error(
                array( 'message' => __( 'Too many requests. Please slow down and try again.', 'tejcart' ) ),
                429
            );
        }
    }

    private function require_admin_nonce( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tejcart' ) ), 403 );
        }
        if ( ! check_ajax_referer( $action, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'tejcart' ) ), 403 );
        }
    }

    private function require_login(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to save a payment method.', 'tejcart' ) ), 401 );
        }
    }

    /**
     * Compute a stable owner hash for the current request context.
     *
     * For logged-in users we hash `user_id`. For guests we hash the
     * `tejcart_session` cookie value so the same browser session can
     * later be re-identified without needing the raw cookie value
     * stored on the order. Returns '' when neither identity is present
     * (rare — both cart and checkout already require a session).
     */
    private static function compute_session_owner_hash(): string {
        // Audit M-4 (PPCP F-012): switched from plain hash() to
        // hash_hmac() keyed by wp_salt('auth') so the digest is not
        // reproducible from public inputs (user_id / session cookie).
        $salt    = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'fallback';
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 ) {
            return 'u:' . hash_hmac( 'sha256', 'user|' . $user_id, $salt );
        }
        if ( isset( $_COOKIE['tejcart_session'] ) ) {
            $sess = sanitize_text_field( wp_unslash( (string) $_COOKIE['tejcart_session'] ) );
            if ( '' !== $sess ) {
                return 's:' . hash_hmac( 'sha256', 'session|' . $sess, $salt );
            }
        }
        return '';
    }

    /**
     * Persist the session-owner hash onto an order at PayPal-create time
     * so subsequent buyer-driven AJAX calls (update_shipping, cancel,
     * capture) can verify the caller is the same buyer who initiated
     * the order — and not another visitor who simply learned the
     * PayPal order id (H-2 / H-3 / IDOR class).
     *
     * Public so the standard-checkout redirect flow can bind the same
     * hash: PayPal_Gateway::process_payment() reaches PayPal without going
     * through create_order() above, so without this call a guest (or a
     * "create account" buyer whose email matched an existing customer)
     * would have no stored hash and 403 at capture. Idempotent — re-binding
     * with the same identity in the same request writes the same value.
     */
    public static function persist_session_owner( int $order_id ): void {
        if ( $order_id <= 0 ) {
            return;
        }
        $hash = self::compute_session_owner_hash();
        if ( '' === $hash ) {
            return;
        }
        tejcart_update_order_meta( $order_id, '_session_owner_hash', $hash );
    }

    /**
     * Reject the AJAX request unless the caller owns the target order.
     *
     * Ownership is satisfied by EITHER of two independent proofs (admins
     * with manage_options / shop managers with tejcart_manage_orders pass
     * through before either is evaluated):
     *
     *   1. Authenticated owner match — the order carries a customer_id and
     *      the current session is logged in as that same customer.
     *   2. Session-owner-hash match — the caller presents the same identity
     *      (logged-in user OR guest cart session) that created the order,
     *      verified against the `_session_owner_hash` bound at create time
     *      by {@see persist_session_owner()} (PayPal_AJAX::create_order for
     *      express buttons, PayPal_Gateway::process_payment for the standard
     *      checkout redirect flow).
     *
     * Proof #2 is required for guest orders (customer_id 0) and for the
     * "create an account" checkout where the email already belongs to an
     * existing customer: the order is linked to that customer_id but the
     * buyer's session is never authenticated, so only the create-time hash
     * proves the still-guest caller is the legitimate buyer. An empty stored
     * hash means the order was created outside the normal PayPal flow (or
     * the meta was tampered with) and only proof #1 can satisfy it.
     */
    private function require_order_ownership( int $order_id ): void {
        if ( $order_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'tejcart' ) ), 404 );
        }
        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'tejcart' ) ), 404 );
        }

        // F-PPCP-007: extend the ownership bypass to shop managers holding
        // tejcart_manage_orders so legitimate support workflows (e.g., retry
        // a stale capture) are possible without requiring full site-admin
        // access. manage_options (Administrator) always passes through.
        if ( function_exists( 'current_user_can' )
            && ( current_user_can( 'manage_options' ) || current_user_can( 'tejcart_manage_orders' ) )
        ) {
            return;
        }

        $current_user_id = (int) get_current_user_id();
        $owner_user_id   = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

        // Proof #1: authenticated owner match. The order is owned by a
        // logged-in customer and the current session is that same customer.
        // Strongest signal, and keeps a logged-in buyer working across
        // devices (where the session-owner hash would differ).
        if ( $owner_user_id > 0 && $current_user_id === $owner_user_id ) {
            return;
        }

        // Proof #2: the caller presents the same identity that created the
        // order, verified against the `_session_owner_hash` bound at create
        // time. This is the fallback for two legitimate flows that proof #1
        // alone rejects:
        //   * a guest-initiated order (customer_id 0), and
        //   * a "create an account" checkout whose email already belongs to
        //     an existing customer — Checkout::maybe_create_account() links
        //     the order to that customer_id but deliberately does NOT
        //     authenticate the still-guest session (auto-login from a typed
        //     email would be account takeover). The buyer finishing the
        //     PayPal approval is the same browser that created the order yet
        //     is not logged in as the linked customer, so the user-id check
        //     would 403 their own capture (`user_mismatch`) and strand the
        //     approved order `pending`.
        // The hash is an HMAC keyed by wp_salt('auth'); a guest's `s:` hash
        // can never equal an authenticated order's `u:` hash and distinct
        // sessions/users never collide, so this stays safe against the IDOR
        // class (H-2/H-3) the user-id check was originally added for.
        $stored  = (string) tejcart_get_order_meta( $order_id, '_session_owner_hash' );
        $current = self::compute_session_owner_hash();
        if ( '' !== $stored && '' !== $current && hash_equals( $stored, $current ) ) {
            return;
        }

        self::log_ownership_rejection(
            $order_id,
            $owner_user_id > 0 ? 'user_mismatch' : 'session_mismatch',
            array(
                'order_owner_user_id' => $owner_user_id,
                'current_user_id'     => $current_user_id,
                'stored_hash_kind'    => self::owner_hash_kind( $stored ),
                'current_hash_kind'   => self::owner_hash_kind( $current ),
                'has_session_cookie'  => isset( $_COOKIE['tejcart_session'] ),
            )
        );
        wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
    }

    /**
     * Classify an owner-hash value without leaking the digest itself.
     *
     * Returns one of: 'user' (logged-in session at hash-time), 'guest'
     * (cookie-based session hash), or 'none' for an empty value.
     */
    private static function owner_hash_kind( string $hash ): string {
        if ( '' === $hash ) {
            return 'none';
        }
        if ( 0 === strncmp( $hash, 'u:', 2 ) ) {
            return 'user';
        }
        if ( 0 === strncmp( $hash, 's:', 2 ) ) {
            return 'guest';
        }
        return 'unknown';
    }

    /**
     * Warning-level log line for an ownership rejection.
     *
     * Helps merchants root-cause customer-reported "Forbidden." 403s on
     * /wp-admin/admin-ajax.php?action=tejcart_paypal_capture_order
     * (and the cancel / update-shipping siblings) without dumping the
     * raw owner-hash digest into log files.
     *
     * @param int                  $order_id Order under check.
     * @param string               $reason   `user_mismatch` or `session_mismatch`.
     * @param array<string, mixed> $context  Diagnostic fields (no PII, no raw hashes).
     */
    private static function log_ownership_rejection( int $order_id, string $reason, array $context ): void {
        if ( ! function_exists( 'tejcart_log' ) ) {
            return;
        }
        $context['order_id'] = $order_id;
        $context['reason']   = $reason;
        tejcart_log(
            sprintf( 'PayPal ownership rejected on order %d (%s).', $order_id, $reason ),
            'warning',
            $context
        );
    }

    /**
     * Read a PayPal resource id from $_POST, validate it, and reject
     * anything that isn't `[A-Za-z0-9-]+`.
     *
     * The regex runs against the raw unslashed value rather than the
     * sanitize_text_field() output — sanitize_text_field strips some
     * characters (e.g. invisible / control chars) before validation,
     * which would otherwise let a value that the regex would have
     * rejected sneak through after stripping.
     */
    private function read_paypal_id( string $field ): string {
        // Nonce verified by require_nonce() in every caller before this runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';
        if ( '' === $raw || ! preg_match( '/^[A-Za-z0-9-]+$/', $raw ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid PayPal order ID.', 'tejcart' ) ), 400 );
        }
        // The regex above already constrains the shape to a safe ASCII
        // alphabet — sanitize_text_field is a belt-and-braces no-op
        // here, but kept so the return value still conforms to the
        // sanitisation convention the rest of the codebase expects.
        return sanitize_text_field( $raw );
    }

    private function return_url_for( int $order_id ): string {
        $order = tejcart_get_order( $order_id );
        if ( $order && method_exists( $order, 'get_checkout_return_url' ) ) {
            return (string) $order->get_checkout_return_url();
        }
        return (string) tejcart_get_thankyou_url( $order_id );
    }

    /**
     * Safely walk a deeply-nested PayPal API response without fataling on
     * unexpected non-array intermediates. The chained `$result['a']['b']['c']`
     * idiom returns NULL with a PHP 8.2 warning if any intermediate level is
     * a string/null, and a TypeError under stricter modes.
     *
     * @param mixed         $payload Source array or any other value.
     * @param array<int|string> $path Sequence of keys to drill into.
     * @return mixed The leaf value when every level is an array containing
     *               the next key, otherwise NULL.
     */
    private function array_dig( $payload, array $path ) {
        $cursor = $payload;
        foreach ( $path as $key ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
                return null;
            }
            $cursor = $cursor[ $key ];
        }
        return $cursor;
    }

    /**
     * Produce an HMAC over the order id that we stash alongside
     * the `_paypal_express` flag. Verified before any later code path uses
     * the flag to authorise address overwrites, so a sibling plugin (or a
     * compromised low-privileged role) can't flip a regular order into
     * "express" and have the buyer's checkout-form address silently
     * replaced by whatever PayPal returns.
     *
     * Keyed off `wp_salt('auth')` so it survives WP cookie-key rotations
     * less aggressively than nonces, but still rotates with the rest of
     * the install's secrets.
     */
    public static function sign_express_flag( int $order_id ): string {
        // Bind the signature to the active PayPal environment AND the
        // currently-connected merchant id. Without these inputs a
        // signature minted in sandbox would survive a sandbox→live
        // flip, and a merchant onboarding into a different PayPal
        // account would inherit signatures minted under the previous
        // account. See review finding M-8.
        //
        // Both inputs are read directly from the persisted gateway
        // option (the same source PayPal_Gateway::current_environment
        // uses) rather than via the gateway's `get_shared_instance`
        // accessor — that accessor caches its settings in a static
        // singleton, so back-to-back signs after a setting change in
        // the same request would mistakenly use the stale cached
        // value.
        $settings = get_option( 'tejcart_gateway_tejcart_paypal', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $is_sandbox  = ( ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes' );
        $env         = $is_sandbox ? 'sandbox' : 'live';
        $merchant_id = $is_sandbox
            ? (string) ( $settings['sandbox_merchant_id'] ?? '' )
            : (string) ( $settings['merchant_id'] ?? '' );

        $payload = implode( '|', array(
            'paypal_express',
            (string) $order_id,
            $env,
            $merchant_id,
        ) );

        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    /**
     * Verify the signature minted by {@see self::sign_express_flag()}.
     *
     * Behaviour:
     *  - Signature present and matches → return true.
     *  - Signature present and DOES NOT match → return false. The flag
     *    has been tampered with; the address-overwrite path is refused.
     *  - Signature missing entirely → return true and log a warning. This
     *    keeps in-flight express orders that were created before the fix
     *    rolled out from losing their PayPal-supplied address. The
     *    `existing_*_empty` defence in `persist_express_addresses()`
     *    still prevents overwrite of any non-empty address.
     */
    public static function express_flag_signature_valid( int $order_id ): bool {
        $stored = (string) tejcart_get_order_meta( $order_id, '_paypal_express_sig' );
        if ( '' === $stored ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'PayPal express flag missing HMAC signature on order #%d; allowing for backward compatibility.', $order_id ),
                    'warning'
                );
            }
            return true;
        }
        return hash_equals( self::sign_express_flag( $order_id ), $stored );
    }

    /**
     * Whether the given order needs a shipping method.
     *
     * Mirrors `Cart::needs_shipping()` but runs against the persisted
     * order so the PayPal-express flow (which mints an order before the
     * buyer touches the standard checkout) can enforce shipping
     * selection at capture time. Public + static so PayPal_API can
     * consult it when deciding the `shipping_preference` it sends to
     * PayPal — a digital-only order must never trigger PayPal's
     * address-collection sheet, even on a store with shipping enabled
     * for its physical SKUs.
     */
    public static function order_needs_shipping( $order ): bool {
        if ( ! self::shipping_globally_enabled() ) {
            return false;
        }
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return false;
        }

        $items = (array) $order->get_items();
        if ( empty( $items ) ) {
            return false;
        }

        if ( ! function_exists( 'tejcart_get_product' ) ) {
            return false;
        }

        foreach ( $items as $item ) {
            $product_id = is_object( $item )
                ? (int) ( $item->product_id ?? 0 )
                : (int) ( $item['product_id'] ?? 0 );
            if ( $product_id <= 0 ) {
                continue;
            }
            $product = tejcart_get_product( $product_id );
            if ( $product && method_exists( $product, 'needs_shipping' ) && $product->needs_shipping() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the buyer's PayPal-side shipping selection onto the local
     * order so totals patched back to PayPal — and the capture-time
     * shipping-required guard — see the chosen method.
     *
     * Validates the option id against the methods Shipping_Manager
     * actually offers for the order's address; an unknown id is dropped
     * silently rather than trusted.
     */
    /**
     * Recompute tax for an express order against the destination address
     * persisted on the order, then refresh the stored total.
     *
     * The express flow mints the order *before* any address is known, so the
     * tax captured at creation time is computed without the buyer's
     * destination — wrong for any address-based rule (manual per-state rates,
     * or a live provider such as TaxJar / Avalara / Stripe Tax whose
     * address-completeness gate rejects the empty address outright and falls
     * back to the manual table or zero).
     *
     * This computes tax **from the order itself**, not the session cart. The
     * single-product "Buy Now" express button builds the order directly from
     * the product and never touches the cart (the session cart may be empty or
     * hold unrelated items), so a cart-based recompute would tax the wrong
     * basis. We reuse the same two engines the cart uses — the active
     * {@see \TejCart\Tax\Tax_Provider_Registry} provider first, then the
     * {@see \TejCart\Tax\Tax_Manager} rate table — against the order's subtotal,
     * discount, shipping and destination, so the result matches the standard
     * checkout path without standing up a second tax engine.
     *
     * Call this AFTER the shipping method has been applied so the order's
     * shipping total (which can itself be taxable) is part of the tax base.
     * Leaves the existing tax untouched when no destination is known yet.
     */
    private function recalculate_express_order_tax( $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'set_tax_total' ) ) {
            return;
        }

        // Master tax switch — mirror Cart_Calculator: no tax when disabled.
        if ( 'yes' !== (string) get_option( 'tejcart_enable_tax', 'no' ) ) {
            return;
        }

        $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0.0;
        $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
        $shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
        $taxable  = max( 0.0, $subtotal - $discount );
        $currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : (string) get_option( 'tejcart_currency', 'USD' );

        [ $country, $state, $postcode, $city ] = $this->order_tax_destination( $order );
        if ( '' === $country ) {
            // No destination resolved yet — don't zero a previously-correct
            // tax just because the address hasn't arrived.
            return;
        }

        $tax = null;

        if ( class_exists( '\\TejCart\\Tax\\Tax_Provider_Registry' ) ) {
            $provider = \TejCart\Tax\Tax_Provider_Registry::get_active();
            if ( null !== $provider ) {
                $context = array(
                    'country'            => $country,
                    'state'              => $state,
                    'postcode'           => $postcode,
                    'city'               => $city,
                    'shipping_total'     => $shipping,
                    'prices_include_tax' => 'yes' === (string) get_option( 'tejcart_prices_include_tax', 'no' ),
                    // Finalisation event — always allowed past the page-context
                    // gate (which exists to suppress billable calls on cart
                    // renders, not at checkout/capture).
                    'page'               => 'checkout',
                );
                try {
                    $tax = $provider->calculate( $taxable, $context );
                } catch ( \Throwable $e ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf( 'PayPal express tax provider %s threw: %s — falling back to rate table.', $provider->get_id(), $e->getMessage() ),
                            'warning'
                        );
                    }
                    $tax = null;
                }
            }
        }

        if ( null === $tax && class_exists( '\\TejCart\\Tax\\Tax_Manager' ) ) {
            $manager   = new \TejCart\Tax\Tax_Manager();
            $rate_data = $manager->get_tax_rate( $country, $state );

            // Base tax on (subtotal − discount), as before.
            $tax = (float) $manager->calculate_tax( $taxable, $country, $state, '', $currency );

            // F-PPCP tax parity: mirror Cart_Calculator::calculate_tax() and
            // tax shipping when the matched rate is configured to. Previously
            // this method only ever taxed (subtotal − discount), so any store
            // with a shipping-taxable rate (the Tax_Manager default) showed a
            // tax-on-shipping total in the wallet sheet — seeded from the cart
            // at express-order creation — that this capture-time recompute then
            // silently stripped back out, leaving the buyer's approved Google
            // Pay / Apple Pay total higher than the final recorded order total.
            if ( ! empty( $rate_data ) && $shipping > 0.0 ) {
                $rate_pct    = (float) $rate_data['rate'];
                $is_compound = ! empty( $rate_data['compound'] ) && 'yes' === $rate_data['compound'];
                $tax_ship    = ! empty( $rate_data['shipping'] ) && 'yes' === $rate_data['shipping'];

                if ( $is_compound ) {
                    // Compound rate taxes (taxable + shipping) together.
                    $tax = \TejCart\Tax\Tax_Manager::round_tax( ( $taxable + $shipping ) * ( $rate_pct / 100 ), $currency );
                } elseif ( $tax_ship ) {
                    // Non-compound: add tax on shipping to the base tax.
                    $tax += \TejCart\Tax\Tax_Manager::round_tax( $shipping * ( $rate_pct / 100 ), $currency );
                }
            }
        }

        $tax = max( 0.0, (float) $tax );
        $order->set_tax_total( $tax );

        if ( method_exists( $order, 'set_total' ) ) {
            $order->set_total( max( 0.0, $subtotal + $shipping + $tax - $discount ) );
        }
        if ( method_exists( $order, 'save' ) ) {
            $order->save();
        }

        if ( function_exists( 'tejcart_tax_log' ) ) {
            tejcart_tax_log(
                'tax_registry',
                'paypal_express: tax recomputed from order for destination address',
                array(
                    'order_id'  => (int) ( method_exists( $order, 'get_id' ) ? $order->get_id() : 0 ),
                    'country'   => $country,
                    'state'     => $state,
                    'tax_total' => $tax,
                )
            );
        }
    }

    /**
     * Resolve the destination [country, state, postcode, city] to tax an
     * express order against.
     *
     * For `store_address`-based tax we use the configured store origin. For
     * billing- or shipping-based tax we read the order's shipping_address
     * blob — the express paths (wallet `onShippingAddressChange` and the
     * pre-capture payer-address seed) deliberately persist the buyer's actual
     * location there regardless of which mode is configured, so a single read
     * covers both. Returns an empty country when nothing usable is recorded.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function order_tax_destination( $order ): array {
        $based = (string) get_option( 'tejcart_tax_based_on', 'billing_address' );

        if ( 'billing_address' !== $based && 'shipping_address' !== $based ) {
            $country = (string) get_option( 'tejcart_shipping_origin_country', (string) get_option( 'tejcart_store_country', '' ) );
            return array(
                strtoupper( $country ),
                (string) get_option( 'tejcart_store_state', '' ),
                (string) get_option( 'tejcart_store_postcode', '' ),
                '',
            );
        }

        if ( ! method_exists( $order, 'get_shipping_address' ) ) {
            return array( '', '', '', '' );
        }

        $addr     = (array) $order->get_shipping_address();
        $country  = strtoupper( (string) ( $addr['shipping_country'] ?? $addr['country'] ?? '' ) );
        $state    = (string) ( $addr['shipping_state'] ?? $addr['state'] ?? '' );
        $postcode = (string) ( $addr['shipping_postcode'] ?? $addr['postcode'] ?? '' );
        $city     = (string) ( $addr['shipping_city'] ?? $addr['city'] ?? '' );

        if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return array( '', '', '', '' );
        }

        return array( $country, $state, $postcode, $city );
    }

    /**
     * Seed the order's destination address from the address PayPal collected
     * when the order has none of its own yet.
     *
     * Physical / mixed express carts already carry the wallet shipping address
     * (persisted during `onShippingAddressChange`). Pure-digital carts collect
     * no shipping address and fire no shipping callback, so the only signal of
     * the buyer's location is the **payer address** PayPal records at approval.
     * We pull the order resource and prefer the shipping address, falling back
     * to the payer address, writing whichever we find into the shipping_address
     * blob so {@see order_tax_destination()} can read it uniformly.
     */
    private function maybe_seed_address_for_tax( $order, string $paypal_order_id, PayPal_API $api ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_address' ) ) {
            return;
        }

        $existing = (array) $order->get_shipping_address();
        $country  = strtoupper( (string) ( $existing['shipping_country'] ?? $existing['country'] ?? '' ) );
        if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return; // Already have a usable destination.
        }

        $details = $api->get_order_details( $paypal_order_id );
        if ( is_wp_error( $details ) || ! is_array( $details ) ) {
            return;
        }

        $shipping = $details['purchase_units'][0]['shipping']['address'] ?? array();
        $payer    = $details['payer']['address'] ?? array();
        $source   = ( is_array( $shipping ) && '' !== (string) ( $shipping['country_code'] ?? '' ) )
            ? $shipping
            : ( is_array( $payer ) ? $payer : array() );

        $cc = strtoupper( (string) ( $source['country_code'] ?? '' ) );
        if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
            return;
        }

        $merged = array_merge(
            $existing,
            array(
                'shipping_country'  => $cc,
                'shipping_state'    => sanitize_text_field( (string) ( $source['admin_area_1'] ?? '' ) ),
                'shipping_city'     => sanitize_text_field( (string) ( $source['admin_area_2'] ?? '' ) ),
                'shipping_postcode' => sanitize_text_field( (string) ( $source['postal_code'] ?? '' ) ),
            )
        );

        if ( method_exists( $order, 'set' ) ) {
            $order->set( 'shipping_address', wp_json_encode( $merged ) );
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
        }
    }

    /**
     * Final tax reconciliation immediately before capturing an express order.
     *
     * Physical / mixed express carts have tax corrected during the
     * `onShippingAddressChange` callback ({@see update_shipping()}), but a
     * pure-digital express cart collects no shipping address and fires no such
     * callback — the buyer's location is only known from the payer address
     * PayPal records at approval. This runs at capture for every express
     * order: seed the destination from PayPal when the order has none,
     * recompute tax against it, and — only when the total actually changed —
     * PATCH the PayPal order to the corrected amount before the capture call.
     *
     * Resilient by design: if PayPal rejects the amount change (e.g. an
     * increase that would need re-approval) we log it, restore the order's
     * recorded totals to the amount PayPal will actually capture, and let the
     * sale proceed rather than stranding the buyer.
     */
    private function reconcile_express_tax_before_capture( string $paypal_order_id, int $order_id, PayPal_API $api ): void {
        if ( 'yes' !== (string) tejcart_get_order_meta( $order_id, '_paypal_express' ) ) {
            return;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) || ! method_exists( $order, 'set_tax_total' ) ) {
            return;
        }

        $this->maybe_seed_address_for_tax( $order, $paypal_order_id, $api );

        $currency  = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD';
        $old_tax   = (float) $order->get_tax_total();
        $old_total = (float) $order->get_total();

        $this->recalculate_express_order_tax( $order );

        $new_total = (float) $order->get_total();

        if ( $this->amounts_equal( $old_total, $new_total, $currency ) ) {
            return; // Buyer already approved the correct amount.
        }

        $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0.0;
        $shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
        $tax      = (float) $order->get_tax_total();
        $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;

        $patch = $api->patch_order_amount( $paypal_order_id, (string) $order_id, $new_total, $subtotal, $shipping, $tax, $currency, $discount );

        if ( is_wp_error( $patch ) ) {
            // Resilient fallback: capture the buyer-approved amount. Restore
            // the order record so it matches what PayPal will charge, and log
            // the delta for the merchant to reconcile.
            $order->set_tax_total( $old_tax );
            if ( method_exists( $order, 'set_total' ) ) {
                $order->set_total( $old_total );
            }
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
            tejcart_log(
                sprintf(
                    'PayPal express order #%d: pre-capture tax PATCH rejected (%s). Capturing the buyer-approved %s instead of the recomputed %s; tax may need manual reconciliation.',
                    $order_id,
                    $patch->get_error_message(),
                    PayPal_API::format_amount( $old_total, strtoupper( $currency ) ),
                    PayPal_API::format_amount( $new_total, strtoupper( $currency ) )
                ),
                'warning'
            );
            return;
        }

        if ( function_exists( 'tejcart_tax_log' ) ) {
            tejcart_tax_log(
                'tax_registry',
                'paypal_express: pre-capture tax reconciled and order PATCHed',
                array(
                    'order_id'  => $order_id,
                    'old_total' => $old_total,
                    'new_total' => $new_total,
                    'tax_total' => $tax,
                )
            );
        }
    }

    /**
     * Compare two major-unit amounts at the currency's minor-unit precision
     * so float noise (0.1 + 0.2) never reads as a spurious change.
     */
    private function amounts_equal( float $a, float $b, string $currency ): bool {
        $multiplier = class_exists( \TejCart\Money\Currency::class )
            ? (int) \TejCart\Money\Currency::multiplier( strtoupper( $currency ) )
            : 100;
        return (int) round( $a * $multiplier ) === (int) round( $b * $multiplier );
    }

    private function apply_chosen_shipping_method( $order, string $selected_option_id ): void {
        if ( ! is_object( $order ) || '' === $selected_option_id ) {
            return;
        }

        $api     = PayPal_Gateway::get_shared_api();
        $options = $api->build_shipping_options_for_order( $order );
        if ( empty( $options ) ) {
            return;
        }

        $matched = null;
        foreach ( $options as $option ) {
            if ( isset( $option['id'] ) && (string) $option['id'] === $selected_option_id ) {
                $matched = $option;
                break;
            }
        }
        if ( null === $matched ) {
            return;
        }

        $cost = isset( $matched['amount']['value'] ) ? (float) $matched['amount']['value'] : 0.0;

        if ( method_exists( $order, 'set_shipping_total' ) ) {
            $order->set_shipping_total( $cost );
        }
        if ( method_exists( $order, 'set_total' ) ) {
            $subtotal = method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0.0;
            $tax      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
            $discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
            $order->set_total( max( 0.0, $subtotal + $cost + $tax - $discount ) );
        }
        if ( method_exists( $order, 'save' ) ) {
            $order->save();
        }

        $order_id = (int) $order->get_id();
        tejcart_update_order_meta( $order_id, '_shipping_method_id', $selected_option_id );
        if ( isset( $matched['label'] ) ) {
            tejcart_update_order_meta( $order_id, '_shipping_method_title', (string) $matched['label'] );
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'set_chosen_shipping_method' ) ) {
            $cart->set_chosen_shipping_method( $selected_option_id );
        }
    }

    /**
     * Return true when every line on the given order points at a virtual
     * product (digital goods, services, downloads). Gates the post-authorize
     * auto-capture behaviour — merchants fulfilling virtual orders shouldn't
     * have to capture each authorization manually because the product is
     * delivered immediately.
     *
     * Conservative on unknowns: any item we can't resolve to a product with
     * an `is_virtual()` method bails to `false`, so we never auto-capture a
     * physical order by mistake.
     */
    private function is_virtual_only_order( $order ): bool {
        if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
            return false;
        }

        $items = (array) $order->get_items();
        if ( empty( $items ) ) {
            return false;
        }

        if ( ! function_exists( 'tejcart_get_product' ) ) {
            return false;
        }

        foreach ( $items as $item ) {
            $product_id = 0;
            if ( is_object( $item ) ) {
                $product_id = isset( $item->product_id ) ? (int) $item->product_id : 0;
            } elseif ( is_array( $item ) ) {
                $product_id = (int) ( $item['product_id'] ?? 0 );
            }
            if ( $product_id <= 0 ) {
                return false;
            }

            $product = tejcart_get_product( $product_id );
            if ( ! $product || ! method_exists( $product, 'is_virtual' ) || ! $product->is_virtual() ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record the wallet the buyer actually used (Google Pay / Apple Pay /
     * Venmo) when the express JS reports it on capture. PayPal's capture
     * response frequently reports a wallet payment as a plain `card` — Google
     * Pay / Apple Pay tokens settle as cards — so the client-reported funding
     * source is the reliable signal for the order's displayed payment method.
     * Overrides the value persist_payer_meta() derived from the response.
     *
     * @param int   $order_id TejCart order id.
     * @param array $result   Capture response (used to recover the wallet
     *                        server-side when the client didn't post one).
     */
    private function persist_wallet_funding_source( int $order_id, array $result = array() ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_nonce() in capture_order()
        $raw     = isset( $_POST['funding_source'] ) ? sanitize_key( wp_unslash( (string) $_POST['funding_source'] ) ) : '';
        $allowed = array( 'google_pay', 'apple_pay', 'venmo', 'paypal', 'card' );
        if ( ! in_array( $raw, $allowed, true ) ) {
            // Fall back to the wallet key PayPal reported on the payment
            // source so a wallet checkout that didn't post a funding_source
            // (or a webhook-driven capture, which never carries one) still
            // records "Google Pay" / "Apple Pay" / "Venmo" instead of the
            // generic gateway title.
            $source = is_array( $result['payment_source'] ?? null ) ? $result['payment_source'] : array();
            foreach ( array( 'google_pay', 'apple_pay', 'venmo' ) as $wallet ) {
                if ( ! empty( $source[ $wallet ] ) ) {
                    $raw = $wallet;
                    break;
                }
            }
        }
        if ( in_array( $raw, $allowed, true ) ) {
            tejcart_update_order_meta( $order_id, '_paypal_funding_source', $raw );
        }
    }

    private function persist_payer_meta( int $order_id, array $result ): void {
        $payer = $result['payer'] ?? array();
        if ( ! empty( $payer['email_address'] ) ) {
            tejcart_update_order_meta( $order_id, '_paypal_payer_email', sanitize_email( $payer['email_address'] ) );
        }
        if ( ! empty( $payer['payer_id'] ) ) {
            tejcart_update_order_meta( $order_id, '_paypal_payer_id', sanitize_text_field( $payer['payer_id'] ) );
        }
        if ( is_array( $result['payment_source'] ?? null ) ) {
            $funding = array_key_first( $result['payment_source'] );
            if ( $funding ) {
                tejcart_update_order_meta( $order_id, '_paypal_funding_source', sanitize_text_field( (string) $funding ) );
            }
        }
    }

    /**
     * Pull the buyer's contact details and billing address out of a PayPal
     * `payment_source` node.
     *
     * Wallet funding sources (Google Pay, Apple Pay) and hosted card fields
     * carry the buyer's name, email, phone, and the card's billing address
     * under `payment_source.<source>` (with the address nested on
     * `card.billing_address`) — the top-level `payer` object stays empty for
     * these flows because the buyer never signs into a PayPal account. This
     * is what lets a Google Pay order record a full billing address and
     * phone number instead of just a country code.
     *
     * @param array $payment_source Decoded `payment_source` from the capture/order response.
     * @return array{given:string,surname:string,full_name:string,email:string,phone:string,address:array}
     */
    private function extract_wallet_contact( array $payment_source ): array {
        $out = array(
            'given'     => '',
            'surname'   => '',
            'full_name' => '',
            'email'     => '',
            'phone'     => '',
            'address'   => array(),
        );

        // Explicit wallets first, then a plain card, then a PayPal account.
        foreach ( array( 'google_pay', 'apple_pay', 'venmo', 'card', 'paypal' ) as $key ) {
            if ( empty( $payment_source[ $key ] ) || ! is_array( $payment_source[ $key ] ) ) {
                continue;
            }
            $node = $payment_source[ $key ];
            $card = is_array( $node['card'] ?? null ) ? $node['card'] : array();

            // Name can be a structured object (PayPal), a flat string on the
            // wallet node (Google Pay / Apple Pay), or on the card.
            if ( is_array( $node['name'] ?? null ) ) {
                $out['given']   = (string) ( $node['name']['given_name'] ?? '' );
                $out['surname'] = (string) ( $node['name']['surname'] ?? '' );
                $out['full_name'] = trim( $out['given'] . ' ' . $out['surname'] );
            } else {
                $name = '';
                if ( ! empty( $node['name'] ) ) {
                    $name = (string) $node['name'];
                } elseif ( ! empty( $card['name'] ) ) {
                    $name = (string) $card['name'];
                }
                if ( '' !== $name ) {
                    $parts          = preg_split( '/\s+/', trim( $name ), 2 );
                    $out['given']   = (string) ( $parts[0] ?? '' );
                    $out['surname'] = (string) ( $parts[1] ?? '' );
                    $out['full_name'] = trim( $name );
                }
            }

            if ( ! empty( $node['email_address'] ) ) {
                $out['email'] = (string) $node['email_address'];
            }

            $out['phone'] = $this->extract_wallet_phone( $node['phone_number'] ?? ( $node['phone'] ?? null ) );

            // Billing address lives on the card for wallets / hosted card,
            // directly on the node for some shapes, or on `address` (PayPal).
            if ( is_array( $card['billing_address'] ?? null ) ) {
                $out['address'] = $card['billing_address'];
            } elseif ( is_array( $node['billing_address'] ?? null ) ) {
                $out['address'] = $node['billing_address'];
            } elseif ( is_array( $node['address'] ?? null ) ) {
                $out['address'] = $node['address'];
            }

            // The first funding source that yields any usable data wins.
            if ( '' !== $out['full_name'] || '' !== $out['email'] || ! empty( $out['address'] ) ) {
                break;
            }
        }

        return $out;
    }

    /**
     * Normalise a PayPal phone payload to an "+CCnational" string.
     *
     * Handles the structured PPCP shapes
     * (`{ country_code, national_number }` and
     * `{ country_code, phone_number: { national_number } }`) as well as a
     * plain string, returning '' when nothing usable is present.
     *
     * @param mixed $phone Raw phone value from the payment source / payer.
     */
    private function extract_wallet_phone( $phone ): string {
        if ( is_array( $phone ) ) {
            $cc  = (string) ( $phone['country_code'] ?? '' );
            $num = '';
            if ( isset( $phone['phone_number']['national_number'] ) ) {
                $num = (string) $phone['phone_number']['national_number'];
            } elseif ( isset( $phone['national_number'] ) ) {
                $num = (string) $phone['national_number'];
            }
            if ( '' === $num ) {
                return '';
            }
            return ( '' !== $cc ? '+' . $cc : '' ) . $num;
        }
        return is_string( $phone ) ? $phone : '';
    }

    /**
     * Backfill the TejCart order's billing / shipping / customer fields
     * from a PayPal capture response. Only called for express orders
     * (product-page Buy Now, cart page, side-cart, top-of-checkout),
     * which were created before the buyer entered any address data.
     */
    private function persist_express_addresses( int $order_id, array $result ): void {
        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $payer      = is_array( $result['payer'] ?? null ) ? $result['payer'] : array();
        $payer_name = is_array( $payer['name'] ?? null ) ? $payer['name'] : array();
        $payer_addr = is_array( $payer['address'] ?? null ) ? $payer['address'] : array();

        // Wallet checkouts (Google Pay, Apple Pay) and hosted card fields
        // leave the top-level `payer` object empty because the buyer never
        // signs into a PayPal account — their name, email, phone, and the
        // card's billing address arrive under
        // `payment_source.<wallet>.card.billing_address` instead. Pull that
        // out so the order records a complete billing address + phone for
        // Google Pay (and friends) rather than just a country.
        $payment_source = is_array( $result['payment_source'] ?? null ) ? $result['payment_source'] : array();
        $wallet         = $this->extract_wallet_contact( $payment_source );

        $given    = sanitize_text_field( (string) ( $payer_name['given_name'] ?? '' ) );
        $surname  = sanitize_text_field( (string) ( $payer_name['surname'] ?? '' ) );
        if ( '' === $given && '' === $surname ) {
            $given   = sanitize_text_field( $wallet['given'] );
            $surname = sanitize_text_field( $wallet['surname'] );
        }
        $email    = sanitize_email( (string) ( $payer['email_address'] ?? '' ) );
        if ( '' === $email && '' !== $wallet['email'] ) {
            $email = sanitize_email( $wallet['email'] );
        }
        // Google Pay (and Apple Pay) capture responses routinely omit the
        // buyer email entirely: there is no `payer` object and the wallet
        // node carries only name / phone / card. The wallet sheet *did*
        // collect it (the JS sets `emailRequired: true` on the Google Pay
        // paymentDataRequest and reads `shippingContact.emailAddress` on
        // Apple Pay) and forwards it on the capture AJAX request as
        // `wallet_email`. Fall back to that so the order records a customer
        // email it can send the receipt / invoice to instead of "no email
        // on file". The capture_order() caller already verified the nonce.
        if ( '' === $email ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by capture_order()'s require_nonce()
            $wallet_email_present = isset( $_POST['wallet_email'] );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by capture_order()'s require_nonce()
            $forwarded_email = $wallet_email_present
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by capture_order()'s require_nonce()
                ? sanitize_email( wp_unslash( (string) $_POST['wallet_email'] ) )
                : '';
            if ( '' !== $forwarded_email && is_email( $forwarded_email ) ) {
                $email = $forwarded_email;
            }

            // Diagnostic: the buyer email for a guest, card-funded wallet
            // payment can ONLY come from the wallet sheet (Google Pay's
            // paymentData.email / Apple Pay's shippingContact.emailAddress),
            // forwarded by the JS as `wallet_email`. PayPal carries no payer
            // email for these. Record exactly what arrived so a store seeing
            // "no email on file" can tell whether the wallet returned no email
            // (nothing we can do server-side) versus the request never
            // forwarding one (stale JS / wrong flow). Logs presence only — not
            // the address — to keep PII out of the debug log.
            if ( '' === $email && function_exists( 'tejcart_log' ) ) {
                $state = ! $wallet_email_present
                    ? 'absent (JS did not forward wallet_email — likely stale cached bundle or non-wallet flow)'
                    : ( '' === $forwarded_email
                        ? 'present-but-empty (wallet sheet returned no email — nothing to persist server-side)'
                        : 'present-but-invalid' );
                tejcart_log(
                    sprintf(
                        'PayPal express email backfill on order #%d: no payer/wallet email in capture response; forwarded wallet_email %s.',
                        $order_id,
                        $state
                    ),
                    'info'
                );
            }
        }
        $phone    = '';
        if ( isset( $payer['phone']['phone_number']['national_number'] ) ) {
            $cc   = (string) ( $payer['phone']['country_code'] ?? '' );
            $num  = (string) $payer['phone']['phone_number']['national_number'];
            $phone = sanitize_text_field( ( '' !== $cc ? '+' . $cc : '' ) . $num );
        }
        if ( '' === $phone && '' !== $wallet['phone'] ) {
            $phone = sanitize_text_field( $wallet['phone'] );
        }

        // Prefer the PayPal-account payer address; fall back to the wallet
        // card's billing address when the payer address has no street line
        // (the common Google Pay / Apple Pay / hosted-card case).
        $billing_addr = $payer_addr;
        if ( empty( $billing_addr['address_line_1'] ) && ! empty( $wallet['address']['address_line_1'] ) ) {
            $billing_addr = $wallet['address'];
        }

        $billing = array(
            'billing_first_name' => $given,
            'billing_last_name'  => $surname,
            'billing_email'      => $email,
            'billing_phone'      => $phone,
            'billing_address_1'  => sanitize_text_field( (string) ( $billing_addr['address_line_1'] ?? '' ) ),
            'billing_address_2'  => sanitize_text_field( (string) ( $billing_addr['address_line_2'] ?? '' ) ),
            'billing_city'       => sanitize_text_field( (string) ( $billing_addr['admin_area_2'] ?? '' ) ),
            'billing_state'      => sanitize_text_field( (string) ( $billing_addr['admin_area_1'] ?? '' ) ),
            'billing_postcode'   => sanitize_text_field( (string) ( $billing_addr['postal_code'] ?? '' ) ),
            'billing_country'    => sanitize_text_field( (string) ( $billing_addr['country_code'] ?? '' ) ),
        );

        $shipping_src   = $result['purchase_units'][0]['shipping'] ?? array();
        $shipping_name  = is_array( $shipping_src['name'] ?? null ) ? $shipping_src['name'] : array();
        $shipping_addr  = is_array( $shipping_src['address'] ?? null ) ? $shipping_src['address'] : array();
        $full_name      = sanitize_text_field( (string) ( $shipping_name['full_name'] ?? '' ) );
        $shipping_first = $given;
        $shipping_last  = $surname;
        if ( '' !== $full_name ) {
            $parts          = preg_split( '/\s+/', $full_name, 2 );
            $shipping_first = sanitize_text_field( (string) ( $parts[0] ?? $given ) );
            $shipping_last  = sanitize_text_field( (string) ( $parts[1] ?? $surname ) );
        }

        $shipping = array(
            'shipping_first_name' => $shipping_first,
            'shipping_last_name'  => $shipping_last,
            'shipping_address_1'  => sanitize_text_field( (string) ( $shipping_addr['address_line_1'] ?? '' ) ),
            'shipping_address_2'  => sanitize_text_field( (string) ( $shipping_addr['address_line_2'] ?? '' ) ),
            'shipping_city'       => sanitize_text_field( (string) ( $shipping_addr['admin_area_2'] ?? '' ) ),
            'shipping_state'      => sanitize_text_field( (string) ( $shipping_addr['admin_area_1'] ?? '' ) ),
            'shipping_postcode'   => sanitize_text_field( (string) ( $shipping_addr['postal_code'] ?? '' ) ),
            'shipping_country'    => sanitize_text_field( (string) ( $shipping_addr['country_code'] ?? '' ) ),
        );

        $shipping_ok = $this->express_address_is_valid( $shipping, 'shipping' );

        // PayPal's `payer.address` for a PayPal-account funding source often
        // contains only `country_code` (no street/city/postcode). For express
        // orders the buyer never filled a billing form, so when the payer
        // address is too thin to validate we fall back to the shipping
        // address as the billing address — preserving the payer's email,
        // phone, and name on the billing side. This keeps invoices, refunds,
        // and tax calculations from operating on a country-only billing
        // address.
        if ( ! $this->express_address_is_valid( $billing, 'billing' ) && $shipping_ok ) {
            $billing = array(
                'billing_first_name' => '' !== $shipping['shipping_first_name'] ? $shipping['shipping_first_name'] : $given,
                'billing_last_name'  => '' !== $shipping['shipping_last_name']  ? $shipping['shipping_last_name']  : $surname,
                'billing_email'      => $email,
                'billing_phone'      => $phone,
                'billing_address_1'  => $shipping['shipping_address_1'],
                'billing_address_2'  => $shipping['shipping_address_2'],
                'billing_city'       => $shipping['shipping_city'],
                'billing_state'      => $shipping['shipping_state'],
                'billing_postcode'   => $shipping['shipping_postcode'],
                'billing_country'    => $shipping['shipping_country'],
            );
        }
        $billing_ok = $this->express_address_is_valid( $billing, 'billing' );

        // Reverse of the billing←shipping fallback above. Wallet checkouts
        // (Google Pay / Apple Pay) frequently capture a complete card
        // *billing* address but return no shipping address in the capture
        // response — PayPal echoes only the chosen shipping *option*, not the
        // address (see purchase_units[0].shipping = { options: [...] } with no
        // `address`/`name`). The dynamic shipping callbacks only ever seed
        // country/state/city/postcode, so the order is left with no recipient
        // name or street line. When we have a valid wallet billing address but
        // no usable shipping address, treat the billing address as the
        // ship-to: the buyer authorised a single address in the wallet sheet,
        // so this records a deliverable name + street instead of a partial
        // stub. The `existing_shipping_empty` guard below still protects any
        // customer-entered shipping address (which always carries line 1).
        if ( ! $shipping_ok && $billing_ok ) {
            $shipping = array(
                'shipping_first_name' => '' !== $billing['billing_first_name'] ? $billing['billing_first_name'] : $shipping_first,
                'shipping_last_name'  => '' !== $billing['billing_last_name']  ? $billing['billing_last_name']  : $shipping_last,
                'shipping_address_1'  => $billing['billing_address_1'],
                'shipping_address_2'  => $billing['billing_address_2'],
                'shipping_city'       => $billing['billing_city'],
                'shipping_state'      => $billing['billing_state'],
                'shipping_postcode'   => $billing['billing_postcode'],
                'shipping_country'    => $billing['billing_country'],
            );
            $shipping_ok = $this->express_address_is_valid( $shipping, 'shipping' );
        }

        // Defence-in-depth: only overwrite billing/shipping if the existing
        // order address has not been customer-supplied. The `_paypal_express`
        // meta + HMAC signature are the primary gate; this check guards
        // against the meta being flipped on an order whose buyer already
        // submitted a checkout form. A real customer-supplied address always
        // carries `*_address_1`; the onShippingChange seed paths
        // (apply_posted_shipping_address, maybe_seed_shipping_address_from_paypal)
        // only populate country/state/city/postcode, so an order whose line 1
        // is still blank cannot have customer-entered data to lose.
        $existing_billing_empty  = $this->order_address_is_empty( $order, 'billing' );
        $existing_shipping_empty = $this->order_address_is_empty( $order, 'shipping' );

        if ( method_exists( $order, 'set' ) ) {
            $customer_name = trim( $given . ' ' . $surname );
            if ( '' !== $customer_name ) {
                $order->set( 'customer_name', $customer_name );
            }
            if ( '' !== $email ) {
                $order->set( 'customer_email', $email );
            }
            if ( $billing_ok && $existing_billing_empty ) {
                $order->set( 'billing_address', wp_json_encode( $billing ) );
            } elseif ( $billing_ok && ! $existing_billing_empty ) {
                tejcart_log(
                    sprintf( 'PayPal express billing address skipped on order #%d; existing address present.', $order_id ),
                    'warning'
                );
            } else {
                tejcart_log(
                    sprintf( 'PayPal express billing address validation failed on order #%d; left unchanged.', $order_id ),
                    'warning'
                );
            }
            if ( $shipping_ok && $existing_shipping_empty ) {
                $order->set( 'shipping_address', wp_json_encode( $shipping ) );
            } elseif ( $shipping_ok && ! $existing_shipping_empty ) {
                tejcart_log(
                    sprintf( 'PayPal express shipping address skipped on order #%d; existing address present.', $order_id ),
                    'warning'
                );
            } else {
                tejcart_log(
                    sprintf( 'PayPal express shipping address validation failed on order #%d; left unchanged.', $order_id ),
                    'warning'
                );
            }
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
        }
    }

    /**
     * Defence-in-depth helper for {@see persist_express_addresses()}.
     *
     * Returns true when the order has no customer-supplied billing/shipping
     * address recorded yet, i.e. when overwriting it cannot lose data the
     * buyer typed into a checkout form. Used to refuse overwriting
     * customer-supplied addresses on a non-express order even if
     * `_paypal_express` meta is set unexpectedly.
     *
     * "Empty" here means "no street line". The express seed paths
     * ({@see self::apply_posted_shipping_address()} and
     * {@see self::maybe_seed_shipping_address_from_paypal()}) populate only
     * country/state/city/postcode so the shipping zone resolver has
     * something to match — they never fill `*_address_1`. A real
     * customer-supplied address, by contrast, always carries line 1: the
     * checkout form requires it (see Checkout_Validator). Treating "country
     * present, line 1 missing" as overwriteable lets the capture-time
     * persistence write the complete address PayPal returns instead of
     * silently dropping the street and recipient name.
     *
     * @param object $order  Order instance.
     * @param string $prefix `billing` or `shipping`.
     */
    private function order_address_is_empty( $order, string $prefix ): bool {
        $getter = 'get_' . $prefix . '_address';
        if ( ! is_object( $order ) || ! method_exists( $order, $getter ) ) {
            return true;
        }
        $existing = (array) $order->{$getter}();
        if ( empty( $existing ) ) {
            return true;
        }
        $line1 = trim( (string) ( $existing[ $prefix . '_address_1' ] ?? $existing['address_1'] ?? '' ) );
        return '' === $line1;
    }

    /**
     * Loosely validate an express-order address. Express buyers never see
     * the checkout form, so we skip the usual field-by-field validation but
     * still require the structural fields PayPal hands us: a country code,
     * city, and postcode.
     *
     * @param array  $address Flattened billing_* / shipping_* array.
     * @param string $prefix  `billing` or `shipping`.
     */
    private function express_address_is_valid( array $address, string $prefix ): bool {
        $country  = strtoupper( (string) ( $address[ $prefix . '_country' ] ?? '' ) );
        $city     = trim( (string) ( $address[ $prefix . '_city' ] ?? '' ) );
        $postcode = trim( (string) ( $address[ $prefix . '_postcode' ] ?? '' ) );
        $line1    = trim( (string) ( $address[ $prefix . '_address_1' ] ?? '' ) );

        if ( '' === $country || ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return false;
        }

        $postcode_optional_countries = array( 'HK', 'AE', 'IE', 'PA' );
        if ( '' === $postcode && ! in_array( $country, $postcode_optional_countries, true ) ) {
            return false;
        }

        if ( '' === $city ) {
            return false;
        }

        // PayPal's payer-info payload occasionally lacks line 1
        // for some buyer profiles (e.g. business accounts that only
        // captured a postal code). Without an explicit street, the order
        // ships to an undeliverable address. Reject so the caller can
        // fall through to the standard checkout flow that prompts for
        // the missing line.
        if ( '' === $line1 ) {
            return false;
        }

        return true;
    }

    private function persist_fraud_meta( int $order_id, array $capture ): void {
        $processor = $capture['processor_response'] ?? array();
        foreach ( array(
            '_paypal_avs_code'           => $processor['avs_code'] ?? '',
            '_paypal_cvv_code'           => $processor['cvv_code'] ?? '',
            '_paypal_processor_response' => $processor['response_code'] ?? '',
            '_paypal_seller_protection'  => $capture['seller_protection']['status'] ?? '',
        ) as $meta_key => $value ) {
            if ( '' !== (string) $value ) {
                tejcart_update_order_meta( $order_id, $meta_key, sanitize_text_field( (string) $value ) );
            }
        }
    }

    /**
     * If the buyer ticked "save this payment method" at checkout, the
     * order body sent to PayPal carried `vault.store_in_vault = ON_SUCCESS`
     * and the capture response now contains the freshly minted vault token
     * under `payment_source.{paypal|card|venmo}.attributes.vault.id`.
     * Persist it on the buyer's saved methods list so subsequent checkouts
     * can charge it directly via the existing saved-methods picker.
     *
     * No-ops when the intent flag isn't set, or when PayPal didn't return
     * a vault token (vaulting is best-effort and PayPal may decline it).
     */
    private function maybe_save_vault_token( int $order_id, array $result ): void {
        $intent = tejcart_get_order_meta( $order_id, '_paypal_save_method_intent' );
        if ( 'yes' !== $intent ) {
            return;
        }

        tejcart_update_order_meta( $order_id, '_paypal_save_method_intent', '' );

        $user_id = (int) tejcart_get_order_meta( $order_id, '_paypal_save_method_user' );
        if ( $user_id <= 0 ) {
            return;
        }

        $payment_source = $result['payment_source'] ?? array();
        if ( ! is_array( $payment_source ) || empty( $payment_source ) ) {
            return;
        }

        $vault_id = '';
        $type     = 'paypal';
        $label    = '';

        foreach ( array( 'paypal', 'card', 'venmo' ) as $source ) {
            if ( empty( $payment_source[ $source ] ) || ! is_array( $payment_source[ $source ] ) ) {
                continue;
            }
            $node = $payment_source[ $source ];
            $vid  = $node['attributes']['vault']['id'] ?? '';
            if ( ! empty( $vid ) ) {
                $vault_id = sanitize_text_field( (string) $vid );
                $type     = $source;
                if ( 'paypal' === $source && ! empty( $node['email_address'] ) ) {
                    $label = sanitize_email( (string) $node['email_address'] );
                } elseif ( 'card' === $source && ! empty( $node['last_digits'] ) ) {
                    $brand = (string) ( $node['brand'] ?? 'Card' );
                    $label = sprintf( '%s •••• %s', $brand, sanitize_text_field( $node['last_digits'] ) );
                } elseif ( 'venmo' === $source && ! empty( $node['email_address'] ) ) {
                    $label = sanitize_email( (string) $node['email_address'] );
                }
                break;
            }
        }

        if ( '' === $vault_id ) {
            return;
        }

        $saved = \TejCart\Customer\Payment_Methods::instance()->save_method(
            $user_id,
            array(
                'token_id' => $vault_id,
                'type'     => $type,
                'label'    => $label,
            )
        );

        if ( ! empty( $saved['id'] ) ) {
            tejcart_update_order_meta( $order_id, '_paypal_saved_method_id', (string) $saved['id'] );
        }
    }

    /**
     * @return array{0:string,1:string} [ type, label ]
     */
    private function derive_vault_label( array $payment_source ): array {
        if ( ! empty( $payment_source['paypal']['email_address'] ) ) {
            return array( 'paypal', sanitize_email( $payment_source['paypal']['email_address'] ) );
        }
        if ( ! empty( $payment_source['card']['last_digits'] ) ) {
            $brand = $payment_source['card']['brand'] ?? 'Card';
            return array( 'card', sprintf( '%s •••• %s', $brand, $payment_source['card']['last_digits'] ) );
        }
        if ( ! empty( $payment_source['venmo']['email_address'] ) ) {
            return array( 'venmo', sanitize_email( $payment_source['venmo']['email_address'] ) );
        }
        return array( 'paypal', '' );
    }
}
