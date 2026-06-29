<?php
/**
 * Main checkout handler.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orchestrates the checkout flow: field collection, validation,
 * order creation, payment processing, and redirect.
 */
class Checkout {
    /**
     * Checkout field definitions.
     *
     * @var Checkout_Fields
     */
    public $fields;

    /**
     * Checkout validator.
     *
     * @var Checkout_Validator
     */
    public $validator;

    /**
     * Whether the authoritative checkout-form nonce
     * (`tejcart_process_checkout`) was successfully verified during THIS
     * request, before any side effects ran.
     *
     * Request-scoped — PHP statics reset between requests, so this never
     * leaks across HTTP requests. Consumed by the PayPal-family gateways'
     * Verifies_Checkout_Nonce defence-in-depth re-check: when a guest ticks
     * "create an account for faster checkout", maybe_create_account() logs
     * the brand-new user in mid-request (current user id 0 → N), which
     * changes the identity wp_verify_nonce() hashes against. That would make
     * the browser-submitted nonce fail a SECOND verification inside the
     * gateway — even though the authoritative gate at the top of process()
     * already passed — and spuriously fail the order with "Security check
     * failed." This flag lets the gateway trust that gate instead.
     *
     * @var bool
     */
    private static $checkout_nonce_verified = false;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->fields    = new Checkout_Fields();
        $this->validator = new Checkout_Validator();
    }

    /**
     * Whether process() verified the authoritative checkout-form nonce
     * during the current request.
     *
     * @return bool
     */
    public static function checkout_nonce_was_verified(): bool {
        return self::$checkout_nonce_verified;
    }

    /**
     * Main checkout processing flow.
     *
     * @return array|\WP_Error Result array with redirect URL or WP_Error on failure.
     */
    public function process() {
        // Reset the per-request "checkout nonce verified" signal; it is
        // (re)asserted only after the authoritative gate below passes, so a
        // stale value from an earlier process() call in the same long-lived
        // process (WP-CLI, tests) can never leak into the gateway re-check.
        self::$checkout_nonce_verified = false;

        if ( ! isset( $_POST['tejcart_checkout_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_checkout_nonce'] ) ), 'tejcart_process_checkout' ) ) {
            return new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please refresh the page and try again.', 'tejcart' ) );
        }

        // Authoritative gate passed: the browser-submitted checkout nonce is
        // genuine for THIS request. Record it so the PayPal-family gateways'
        // defence-in-depth re-check (Verifies_Checkout_Nonce) can trust this
        // request even after maybe_create_account() logs a brand-new user in
        // mid-flow — which changes get_current_user_id() and would otherwise
        // invalidate the same nonce on a second wp_verify_nonce() pass.
        self::$checkout_nonce_verified = true;

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();

        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'checkout', $ip, 10, 300 ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many checkout attempts. Please try again in a few minutes.', 'tejcart' ) );
        }

        /**
         * Pre-validate gate (bot/abuse filter). Listeners may return a
         * WP_Error to abort the checkout. Bot_Gate hooks this for
         * CAPTCHA enforcement on checkout submit. See F-C1 / #923.
         *
         * @param true|\WP_Error $proceed Pass-through, or WP_Error to block.
         */
        $gate = apply_filters( 'tejcart_checkout_pre_validate', true );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }

        /**
         * Fires before the checkout form is processed.
         */
        do_action( 'tejcart_before_checkout_form' );

        /**
         * Fires at the start of checkout processing.
         */
        do_action( 'tejcart_checkout_process' );

        $checkout_fields = $this->get_checkout_fields();
        $posted_data     = array();

        // Pass slash-unescaped $_POST through directly — get_field_value
        // applies the correct sanitiser per field type (sanitize_email for
        // emails, sanitize_textarea_field for notes preserving newlines,
        // raw passthrough for account_password). A blanket
        // array_map( 'sanitize_text_field', $_POST ) here would strip HTML-
        // like characters out of the password the buyer typed (so
        // `<3MyDog!` → empty), and would collapse multi-line order notes
        // into a single line before sanitize_textarea_field ever ran.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        $unslashed_post = wp_unslash( $_POST );
        foreach ( $checkout_fields as $field_key => $field ) {
            $posted_data[ $field_key ] = $this->fields->get_field_value( $field_key, $unslashed_post );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        if ( isset( $_POST['tejcart_shipping_method'] ) ) {
            $posted_data['tejcart_shipping_method'] = sanitize_text_field(
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
                wp_unslash( $_POST['tejcart_shipping_method'] )
            );
        }

        $cart_for_ship = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        $needs_shipping = ( $cart_for_ship && is_object( $cart_for_ship ) && method_exists( $cart_for_ship, 'needs_shipping' ) )
            ? (bool) $cart_for_ship->needs_shipping()
            : false;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        $raw_post = wp_unslash( $_POST );
        $posted_data['tejcart_ship_same'] = self::resolve_ship_same_flag( $raw_post );

        if ( '1' === $posted_data['tejcart_ship_same'] && $needs_shipping ) {
            $shipping_keys = array_keys( $this->fields->get_shipping_fields() );
            $posted_data   = self::mirror_address_fields( $posted_data, $shipping_keys );
        }

        /**
         * Fires so extensions can add their own validation.
         *
         * @param array $posted_data Sanitized posted data.
         */
        do_action( 'tejcart_checkout_validation', $posted_data );

        $validation = $this->validator->validate( $posted_data );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( ! is_user_logged_in() && 'yes' !== get_option( 'tejcart_guest_checkout', 'yes' ) ) {
            return new \WP_Error(
                'guest_checkout_disabled',
                __( 'Please sign in or create an account to place an order.', 'tejcart' )
            );
        }

        $cart = tejcart_get_cart();

        if ( ! $cart || ( is_object( $cart ) && method_exists( $cart, 'is_empty' ) && $cart->is_empty() ) ) {
            return new \WP_Error( 'empty_cart', __( 'Your cart is empty.', 'tejcart' ) );
        }

        if ( ! $this->validate_cart_prices( $cart ) ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Checkout aborted: cart price/availability validation failed.', 'warning' );
            }
            return new \WP_Error( 'cart_validation_failed', __( 'One or more items in your cart are no longer available. Please review your cart.', 'tejcart' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        $submitted_hash = isset( $_POST['tejcart_cart_totals_hash'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_cart_totals_hash'] ) )
            : '';
        if ( method_exists( $cart, 'get_totals_hash' ) ) {
            $expected_hash = $cart->get_totals_hash();
            if ( '' === $submitted_hash || ! hash_equals( $expected_hash, $submitted_hash ) ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( 'Checkout aborted: cart totals hash mismatch (expected %s, got %s).', $expected_hash, $submitted_hash ),
                        'warning'
                    );
                }
                return new \WP_Error(
                    'cart_totals_changed',
                    __( 'Your cart totals have changed since you opened checkout. Please review your order and try again.', 'tejcart' )
                );
            }
        }

        $idempotency_payload = wp_json_encode( array(
            'uid'   => get_current_user_id(),
            'sid'   => function_exists( 'tejcart_get_session_key' )
                ? tejcart_get_session_key()
                : ( isset( $_COOKIE['tejcart_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tejcart_session'] ) ) : '' ),
            'items' => array_map(
                function ( $i ) { return array( $i->get_product_id(), $i->get_quantity() ); },
                array_values( $cart->get_items() )
            ),
            'total' => (string) $cart->get_total(),
            'email' => sanitize_email( $posted_data['billing_email'] ?? '' ),
        ) );
        $idempotency_key    = 'tejcart_co_lock_' . hash( 'sha256', (string) $idempotency_payload );

        $idempotency_ttl     = 5 * MINUTE_IN_SECONDS;
        $now                 = time();
        $idempotency_value   = array( 'acquired' => $now, 'expires' => $now + $idempotency_ttl );

        // S-4: prefer the custom-table lock primitive (zero alloptions
        // churn). Lock::claim's INSERT IGNORE + conditional-UPDATE-on-stale
        // collapses the legacy delete+retry CAS pattern into one atomic
        // operation. Falls back to add_option for installs where the
        // tejcart_locks table has not yet been provisioned.
        $use_lock_table = class_exists( \TejCart\Core\Lock::class );
        $lock_handle    = 'co_' . hash( 'sha256', (string) $idempotency_payload );
        if ( $use_lock_table ) {
            $idempotency_locked = \TejCart\Core\Lock::claim(
                $lock_handle,
                $idempotency_ttl,
                'checkout'
            );
            if ( ! $idempotency_locked ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log( 'Checkout idempotency lock hit — duplicate submission suppressed.', 'warning' );
                }
                /**
                 * F-L12 / #962: friendlier duplicate-submission
                 * message. Filterable so merchants can match their
                 * brand voice. Default mentions another tab — the
                 * single most common cause of this code path.
                 *
                 * @param string $message
                 */
                $duplicate_msg = (string) apply_filters(
                    'tejcart_checkout_in_progress_message',
                    __( 'Your order is already being placed (likely from another tab). Please wait a moment — if you do not see a confirmation page in 10 seconds, reload and check your account orders.', 'tejcart' )
                );
                return new \WP_Error( 'checkout_in_progress', $duplicate_msg );
            }
        } else {
            $idempotency_locked = add_option( $idempotency_key, $idempotency_value, '', 'no' );
            if ( ! $idempotency_locked ) {
                $existing = get_option( $idempotency_key );
                $expires  = is_array( $existing ) && isset( $existing['expires'] ) ? (int) $existing['expires'] : 0;
                if ( $expires && $expires > $now ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( 'Checkout idempotency lock hit — duplicate submission suppressed.', 'warning' );
                    }
                    /**
                 * F-L12 / #962: friendlier duplicate-submission
                 * message. Filterable so merchants can match their
                 * brand voice. Default mentions another tab — the
                 * single most common cause of this code path.
                 *
                 * @param string $message
                 */
                $duplicate_msg = (string) apply_filters(
                    'tejcart_checkout_in_progress_message',
                    __( 'Your order is already being placed (likely from another tab). Please wait a moment — if you do not see a confirmation page in 10 seconds, reload and check your account orders.', 'tejcart' )
                );
                return new \WP_Error( 'checkout_in_progress', $duplicate_msg );
                }
                // F-CCM-004: only release the lock when this request holds it ($idempotency_locked is truthy).
                // Releasing when $idempotency_locked is false/null would free another concurrent
                // request's active lock, allowing double-order submission.
                delete_option( $idempotency_key );
                if ( $use_lock_table && $idempotency_locked ) {
                    \TejCart\Core\Lock::release( $lock_handle );
                }
                $idempotency_locked = add_option( $idempotency_key, $idempotency_value, '', 'no' );
                if ( ! $idempotency_locked ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( 'Checkout idempotency lock reclaim raced — duplicate submission suppressed.', 'warning' );
                    }
                    /**
                 * F-L12 / #962: friendlier duplicate-submission
                 * message. Filterable so merchants can match their
                 * brand voice. Default mentions another tab — the
                 * single most common cause of this code path.
                 *
                 * @param string $message
                 */
                $duplicate_msg = (string) apply_filters(
                    'tejcart_checkout_in_progress_message',
                    __( 'Your order is already being placed (likely from another tab). Please wait a moment — if you do not see a confirmation page in 10 seconds, reload and check your account orders.', 'tejcart' )
                );
                return new \WP_Error( 'checkout_in_progress', $duplicate_msg );
                }
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log( 'Checkout idempotency lock expired — reclaimed for retry.', 'info' );
                }
            }
        }

        wp_schedule_single_event( $now + $idempotency_ttl + MINUTE_IN_SECONDS, 'tejcart_cleanup_webhook_option', array( $idempotency_key ) );

        // N-H2 (follow-up to F-H7): the scheduled cleanup never fires
        // when DISABLE_WP_CRON is set and no external runner is hooking
        // wp-cron.php; the per-key cleanup we just queued would leak.
        // Boost the opportunistic sweep frequency in that environment so
        // wp_options doesn't bloat over time. Correctness is independent
        // of this sweep — the lock honours its own TTL.
        $cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $as_available   = class_exists( '\ActionScheduler' ) || function_exists( 'as_schedule_single_action' );
        $sweep_one_in_n = ( $cron_disabled && ! $as_available ) ? 20 : 200;
        if ( wp_rand( 1, $sweep_one_in_n ) === 1 ) {
            self::sweep_expired_idempotency_locks( $now );
        }

        /**
         * Fires after server-side cart price validation, allowing extensions
         * to perform additional price or availability checks.
         *
         * @param \TejCart\Cart\Cart $cart The cart instance (already recalculated).
         */
        do_action( 'tejcart_checkout_validate_cart_prices', $cart );

        if ( ! is_email( $posted_data['billing_email'] ?? '' ) ) {
            return new \WP_Error( 'invalid_email', __( 'Please provide a valid email address.', 'tejcart' ) );
        }

        if ( method_exists( $cart, 'validate_coupons_against_email' ) ) {
            $coupon_check = $cart->validate_coupons_against_email( $posted_data['billing_email'] );
            if ( is_wp_error( $coupon_check ) ) {
                return $coupon_check;
            }
        }

        // Defence-in-depth re-validation of every applied
        // coupon at submission time. `validate_coupons_against_email`
        // already checks expiry / usage_limit against the live row, but
        // this independent pass guards against a future code path that
        // skips the email-bound check (e.g. an extension overriding
        // Cart) and makes the contract explicit: the cart's discount can
        // only persist into an order if the coupon is still valid right
        // now. Picks up an expiry that crossed mid-checkout, or a
        // global usage_limit reached by another shopper between
        // apply-time and submit-time.
        if ( method_exists( $cart, 'get_coupons' ) ) {
            $applied = (array) $cart->get_coupons();
            foreach ( $applied as $code => $coupon_data ) {
                $coupon_id = isset( $coupon_data['coupon_id'] ) ? (int) $coupon_data['coupon_id'] : 0;
                if ( $coupon_id <= 0 ) {
                    continue;
                }
                $coupon = new \TejCart\Coupon\Coupon( $coupon_id );
                $valid  = $coupon->is_valid(
                    (string) ( $posted_data['billing_email'] ?? '' ),
                    method_exists( $cart, 'get_subtotal' ) ? (float) $cart->get_subtotal() : null
                );
                if ( is_wp_error( $valid ) ) {
                    return $valid;
                }
            }
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( 'START TRANSACTION' );

        $stock_check = $this->validate_stock( $cart );

        if ( is_wp_error( $stock_check ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return $stock_check;
        }

        $billing_keys  = array_keys( $this->fields->get_billing_fields() );
        $shipping_keys = array_keys( $this->fields->get_shipping_fields() );

        $billing_address = array();
        foreach ( $billing_keys as $key ) {
            if ( isset( $posted_data[ $key ] ) ) {
                $billing_address[ $key ] = $posted_data[ $key ];
            }
        }

        $shipping_address = array();
        foreach ( $shipping_keys as $key ) {
            if ( isset( $posted_data[ $key ] ) ) {
                $shipping_address[ $key ] = $posted_data[ $key ];
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at top of process()
        $selected_gateway_id = isset( $_POST['tejcart_payment_method'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_payment_method'] ) )
            : '';

        $coupon_code = '';
        if ( method_exists( $cart, 'get_coupons' ) ) {
            $applied_codes = array_keys( (array) $cart->get_coupons() );
            if ( ! empty( $applied_codes ) ) {
                $coupon_code = implode( ', ', array_map( 'sanitize_text_field', $applied_codes ) );
            }
        }

        $order_data = array(
            'status'           => 'pending',
            // Use the FILTERED active currency (tejcart_get_currency()), not
            // the raw setting: the amounts below come from the cart, which is
            // denominated in the active display currency under multi-currency.
            // Sourcing the code from the unfiltered setting would write a
            // base-currency code (e.g. USD) onto display-currency amounts
            // (EUR) — a self-inconsistent row that only happened to be
            // reconciled by the currency-switcher's Order_Meta_Writer. Writing
            // the active code here makes core self-consistent; the module still
            // stamps base_currency/fx_rate (see Order_Meta_Writer guard).
            'currency'         => tejcart_get_currency(),
            'subtotal'         => $cart->get_subtotal(),
            'discount_total'   => $cart->get_discount_total(),
            'shipping_total'   => $cart->get_shipping_total(),
            'tax_total'        => $cart->get_tax_total(),
            'total'            => $cart->get_total(),
            'customer_email'   => sanitize_email( $posted_data['billing_email'] ?? '' ),
            'customer_name'    => sanitize_text_field( trim( ( $posted_data['billing_first_name'] ?? '' ) . ' ' . ( $posted_data['billing_last_name'] ?? '' ) ) ),
            'customer_id'      => get_current_user_id(),
            'payment_method'   => $selected_gateway_id,
            'ip_address'       => \TejCart\Security\Rate_Limiter::get_client_ip(),
            'customer_note'    => sanitize_textarea_field( $posted_data['customer_note'] ?? '' ),
            'coupon_code'      => $coupon_code,
            'billing_address'  => wp_json_encode( $billing_address ),
            'shipping_address' => wp_json_encode( $shipping_address ),
            'items'            => array_map(
                function ( $item ) {
                    // Use the snapshot price the buyer agreed
                    // to at add-to-cart time, not the live product
                    // price (which may have moved between add and submit).
                    $unit_price = method_exists( $item, 'get_price' )
                        ? (float) $item->get_price()
                        : ( $item->get_product() ? (float) $item->get_product()->get_price() : 0.0 );

                    // Audit #40 / 02 M-6 — for variation lines, the
                    // order's `product_id` must point at the PARENT
                    // Variable_Product. Cart_Item::get_product_id()
                    // returns the variation's own id when the line
                    // is a variation, which made
                    // Order_Factory::variation_belongs_to_product
                    // reject the order because Variation doesn't
                    // expose `get_variation()`. Use the parent id
                    // explicitly and let `variation_id` carry the leaf.
                    $is_variation_line = method_exists( $item, 'is_variation' ) && $item->is_variation();
                    if ( $is_variation_line && method_exists( $item, 'get_parent_product_id' ) ) {
                        $product_id_for_order = (int) $item->get_parent_product_id();
                    } else {
                        $product_id_for_order = (int) $item->get_product_id();
                    }
                    $payload = array(
                        'product_id'   => $product_id_for_order,
                        'product_name' => $item->get_name(),
                        'quantity'     => $item->get_quantity(),
                        'unit_price'   => $unit_price,
                        'line_total'   => $item->get_line_total(),
                    );

                    if ( $is_variation_line && method_exists( $item, 'get_variation_id' ) ) {
                        $vid = (int) $item->get_variation_id();
                        if ( $vid > 0 ) {
                            $payload['variation_id'] = $vid;
                        }
                    }

                    if ( method_exists( $item, 'get_data' ) ) {
                        $data = (array) $item->get_data();
                        if ( ! empty( $data ) ) {
                            $attrs = array();
                            foreach ( $data as $k => $v ) {
                                if ( ! is_string( $k ) || 0 === strpos( $k, '_' ) ) {
                                    continue;
                                }
                                $attrs[ $k ] = is_scalar( $v ) ? (string) $v : $v;
                            }
                            if ( ! empty( $attrs ) ) {
                                $payload['variation_attributes'] = $attrs;
                            }
                        }
                    }

                    return $payload;
                },
                $cart->get_items()
            ),
        );

        $order = \TejCart\Order\Order_Factory::create( $order_data, false );

        if ( is_wp_error( $order ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return $order;
        }

        if ( ! $order ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return new \WP_Error( 'order_creation_failed', __( 'Could not create order. Please try again.', 'tejcart' ) );
        }

        $order_id = $order->get_id();

        // Record which engine actually priced the tax on this order so
        // reconciliation is queryable: a live provider (`live:taxjar`), the
        // manual rate table (`manual`), or a silent fallback to manual rates
        // because the live provider could not answer (`manual_fallback`).
        // Without this, an upstream tax outage that quietly downgrades a run
        // of orders to manual rates leaves no trail to find them at month-end.
        $order->update_meta( '_tejcart_tax_source', $cart->get_tax_source() );

        // Persist cart-level fees (gift wrap, handling, …). The fee is already
        // folded into the order `total` column (the cart's get_total() includes
        // it), but the order has no dedicated fees column, so we record the fee
        // total (minor units, order currency) plus the itemised rows as meta.
        // This lets the gateway add a balancing `handling` breakdown line and
        // the order/thank-you/email render an itemised "Fees" row so the total
        // visibly reconciles. Amounts are already in the order currency
        // (converted in the cart pipeline), so no conversion happens here.
        $tejcart_fees_total = method_exists( $cart, 'get_fees_total' ) ? (float) $cart->get_fees_total() : 0.0;
        if ( $tejcart_fees_total > 0 ) {
            $order->update_meta(
                '_tejcart_fees_total',
                (string) \TejCart\Money\Currency::to_minor_units( $tejcart_fees_total, (string) $order->get_currency() )
            );
            if ( method_exists( $cart, 'get_fees' ) ) {
                $order->update_meta( '_tejcart_fees', (string) wp_json_encode( $cart->get_fees() ) );
            }
        }

        if ( ! empty( $posted_data['create_account'] ) && ! is_user_logged_in() ) {
            $customer_result = $this->maybe_create_account( $posted_data, $order );

            if ( is_wp_error( $customer_result ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'ROLLBACK' );
                // F-CCM-004: only release the lock when this request holds it ($idempotency_locked is truthy).
                // Releasing when $idempotency_locked is false/null would free another concurrent
                // request's active lock, allowing double-order submission.
                delete_option( $idempotency_key );
                if ( $use_lock_table && $idempotency_locked ) {
                    \TejCart\Core\Lock::release( $lock_handle );
                }
                $this->restore_stock( $order_id );
                return $customer_result;
            }
        }

        /**
         * Fires after the order meta is stored, allowing extensions to save additional data.
         *
         * @param int   $order_id    The newly created order ID.
         * @param array $posted_data The sanitized checkout data.
         */
        do_action( 'tejcart_checkout_update_order_meta', $order_id, $posted_data );

        $gateway_id = $selected_gateway_id;
        $gateway    = tejcart()->gateways()->get_gateway( $gateway_id );

        if ( ! $gateway || ! $gateway->is_available() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            $order->update_status( 'failed', __( 'Invalid payment method selected.', 'tejcart' ) );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return new \WP_Error( 'invalid_gateway', __( 'The selected payment method is not available. Please choose another.', 'tejcart' ) );
        }

        // Decrement stock BEFORE charging the gateway so we
        // never end up in the "buyer's card was charged but the order
        // can't be fulfilled" state. The row locks taken by
        // validate_stock() above are still held inside this transaction,
        // so the decrement is guaranteed to see the same stock value
        // validate_stock checked against; the FOR UPDATE serialises any
        // racing checkout for the same product. If decrement fails (an
        // out-of-band stock mutation between validation and decrement,
        // for example) we ROLLBACK without ever calling the payment
        // gateway.
        $decremented = \TejCart\Order\Order_Stock::reduce_in_transaction( $order_id );

        if ( ! $decremented ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            $order->update_status( 'failed', __( 'Stock could not be decremented.', 'tejcart' ) );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return new \WP_Error( 'stock_decrement_failed', __( 'Could not reserve stock. Please try again.', 'tejcart' ) );
        }

        // Set the once-only guard inside the same transaction so the
        // post-COMMIT processing-status listener in Order_Stock no-ops
        // instead of re-reducing stock for this order.
        \TejCart\Order\Order_Stock::mark_reduced( $order_id );

        // Reserve per-user AND global coupon usage atomically with
        // order creation, BEFORE charging the gateway. Two tabs racing
        // the last permitted use of a per-user-limited coupon — or two
        // shoppers racing the last globally-limited slot — both pass
        // the read-only validation step and reach this point; only the
        // conditional INSERT … ON DUPLICATE KEY UPDATE inside
        // reserve_usage_for_user(), and the conditional UPDATE inside
        // increment_usage(), actually claim a slot. The loser ROLLBACKs
        // without ever calling the gateway, so the global counter is
        // never inflated by a never-paid order. On gateway failure the
        // post-COMMIT failed-status listener Order_Coupon_Rollback
        // decrements the counters again. See F-H2 / #925.
        $billing_email_for_reserve = sanitize_email( $posted_data['billing_email'] ?? '' );
        $cart_coupons_for_reserve  = method_exists( $cart, 'get_coupons' ) ? (array) $cart->get_coupons() : array();
        foreach ( $cart_coupons_for_reserve as $reserve_code => $reserve_data ) {
            $reserve_id = isset( $reserve_data['coupon_id'] ) ? (int) $reserve_data['coupon_id'] : 0;
            if ( ! $reserve_id || '' === $billing_email_for_reserve ) {
                continue;
            }
            $reserve_coupon = new \TejCart\Coupon\Coupon( $reserve_id );
            if ( ! $reserve_coupon->reserve_usage_for_user( $billing_email_for_reserve ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( 'ROLLBACK' );
                $order->update_status(
                    'failed',
                    sprintf(
                        /* translators: %s: coupon code */
                        __( 'Coupon "%s" per-user limit reached.', 'tejcart' ),
                        (string) $reserve_code
                    )
                );
                // F-CCM-004: only release the lock when this request holds it ($idempotency_locked is truthy).
                // Releasing when $idempotency_locked is false/null would free another concurrent
                // request's active lock, allowing double-order submission.
                delete_option( $idempotency_key );
                if ( $use_lock_table && $idempotency_locked ) {
                    \TejCart\Core\Lock::release( $lock_handle );
                }
                return new \WP_Error(
                    'coupon_per_user_limit',
                    sprintf(
                        /* translators: %s: coupon code */
                        __( 'You have already used coupon "%s" the maximum number of times.', 'tejcart' ),
                        (string) $reserve_code
                    )
                );
            }

            // Bump the global counter atomically inside the same
            // transaction. The conditional UPDATE in increment_usage()
            // only mutates when (usage_limit IS NULL OR usage_count <
            // usage_limit), so concurrent racers cannot exceed the cap.
            if ( ! $reserve_coupon->increment_usage() ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( 'ROLLBACK' );
                $order->update_status(
                    'failed',
                    sprintf(
                        /* translators: %s: coupon code */
                        __( 'Coupon "%s" global usage limit reached.', 'tejcart' ),
                        (string) $reserve_code
                    )
                );
                // F-CCM-004: only release the lock when this request holds it ($idempotency_locked is truthy).
                // Releasing when $idempotency_locked is false/null would free another concurrent
                // request's active lock, allowing double-order submission.
                delete_option( $idempotency_key );
                if ( $use_lock_table && $idempotency_locked ) {
                    \TejCart\Core\Lock::release( $lock_handle );
                }
                return new \WP_Error(
                    'coupon_global_limit',
                    sprintf(
                        /* translators: %s: coupon code */
                        __( 'Coupon "%s" has reached its usage limit.', 'tejcart' ),
                        (string) $reserve_code
                    )
                );
            }
        }

        // COMMIT the order/stock/coupon reservation BEFORE handing off
        // to the payment gateway. Holding the transaction across the
        // gateway's remote network call (PayPal/Stripe APIs are 1–10s
        // round-trips) keeps row-locks on tejcart_products /
        // tejcart_orders / tejcart_order_meta open the entire time, and
        // — more painfully — any wp_options writes performed by the
        // gateway or by extensions hooked into
        // `tejcart_checkout_update_order_meta` end up holding wp_options
        // row-locks across the network call too. That's what produced
        // the "Lock wait timeout exceeded" cascades on
        // `_site_transient_timeout_wp_theme_files_patterns-*` writes
        // observed in production: WP core's transient writes on
        // parallel pageloads queue behind a checkout's open transaction.
        // The order is now committed in `pending` state with stock
        // reserved; on gateway failure we explicitly reverse the
        // decrement via Order_Stock::restore_decrement() below.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( 'COMMIT' );

        // Pending-order reaper. The COMMIT above persists the order row
        // + decremented stock + coupon usage counter increments. The
        // gateway call below is a network round-trip. A PHP fatal,
        // request timeout, or worker kill between COMMIT and the
        // gateway result leaves the order in `pending` forever with
        // stock decremented and coupon counters inflated — no
        // `failed` transition ever fires, so the existing
        // Order_Stock::restore_decrement listener never runs and the
        // inventory is permanently leaked. At high volume this
        // accumulates measurable inventory loss per day per merchant.
        //
        // Schedule a single Action Scheduler event 15 minutes out.
        // The reaper checks: is the order still `pending` with no
        // stamped transaction_id and no payment intent identifier?
        // If so, transition it to `cancelled` — the existing
        // Order_Stock and Order_Coupon_Rollback listeners hooked on
        // that status will undo the inventory + coupon side-effects.
        // If the gateway did succeed (and the status has moved on),
        // the reaper no-ops.
        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            // Positional args only. WP_Hook dispatches scheduled events
            // via call_user_func_array, which in PHP 8 treats string keys
            // as named parameters — an associative payload here would
            // fatal in wp-cron with "Unknown named parameter $order_id".
            \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                time() + 15 * MINUTE_IN_SECONDS,
                'tejcart_pending_order_reaper',
                array( (int) $order_id )
            );
        }

        /**
         * Zero-total orders (free trials, 100% coupons) skip the normal
         * process_payment() gateway call. Instead, if the gateway supports
         * tokenization, we fire a filter that lets it vault a payment method
         * (e.g. via SetupIntent) without charging. This is necessary because
         * Stripe and most PSPs reject zero-amount PaymentIntents.
         */
        // F-CCM-013: use Money VO comparison to avoid floating-point == hazard on zero.
        // is_zero() compares the integer minor-unit value (=== 0), which is exact.
        $order_total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : -1.0;
        $order_is_zero = method_exists( $order, 'get_total_money' )
            ? $order->get_total_money()->is_zero()
            : ( $order_total === 0.0 );
        if ( $order_is_zero && $gateway->supports( 'tokenization' ) ) {
            /**
             * Filters the result of a zero-total checkout for gateways that
             * support tokenization. Gateways hook here to vault a payment
             * method (e.g. via SetupIntent) without creating a charge.
             *
             * Return ['result' => 'success'] to complete the checkout.
             * Return ['result' => 'failure', 'message' => '...'] to fail.
             * Return null to fall through to the normal process_payment() call.
             *
             * @param array|null              $result   Default null (fall through).
             * @param int                     $order_id The order ID.
             * @param \TejCart\Gateways\Abstract_Gateway $gateway  The active gateway.
             */
            $setup_result = apply_filters( 'tejcart_checkout_process_zero_total', null, $order_id, $gateway );

            if ( is_array( $setup_result ) ) {
                $payment_result = $setup_result;
            } else {
                $order->update_status( 'processing', __( 'Zero-total order — no payment required.', 'tejcart' ) );
                $payment_result = array( 'result' => 'success', 'redirect' => '' );
            }
        } else {
            $payment_result = $gateway->process_payment( $order_id );
        }

        if ( is_wp_error( $payment_result ) ) {
            \TejCart\Order\Order_Stock::restore_decrement( $order_id );
            $order->update_status( 'failed', $payment_result->get_error_message() );
            $this->restore_stock( $order_id );

            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }

            /**
             * Fires when a payment fails during checkout.
             *
             * @param int       $order_id       The order ID.
             * @param \WP_Error $payment_result The payment error.
             */
            do_action( 'tejcart_checkout_payment_failed', $order_id, $payment_result );

            return $payment_result;
        }

        if ( is_array( $payment_result ) && isset( $payment_result['result'] ) && 'success' !== $payment_result['result'] ) {
            $gateway_message = isset( $payment_result['message'] ) && '' !== (string) $payment_result['message']
                ? (string) $payment_result['message']
                : __( 'Payment could not be processed. Please try again.', 'tejcart' );
            \TejCart\Order\Order_Stock::restore_decrement( $order_id );
            $order->update_status( 'failed', $gateway_message );
            $this->restore_stock( $order_id );
            $error = new \WP_Error( 'payment_failed', $gateway_message );
            do_action( 'tejcart_checkout_payment_failed', $order_id, $error );
            delete_option( $idempotency_key ); if ( $use_lock_table && $idempotency_locked ) { \TejCart\Core\Lock::release( $lock_handle ); }
            return $error;
        }

        // Audit #1 / 05 F-1 — only auto-promote pending → processing when
        // the gateway completed payment synchronously (no buyer redirect).
        // PayPal wallet / Card / Apple Pay / Google Pay / Fastlane return
        // a non-empty `redirect` because the capture happens AFTER the
        // buyer approves at PayPal; promoting to processing here fired
        // receipt emails on abandoned approvals and left the orphan-
        // sweeper unable to reach the row (it only acts on `pending`).
        // The status check separately preserves COD / Bank Transfer /
        // Check, which set `on-hold` inside their own process_payment().
        $redirect_after_payment = isset( $payment_result['redirect'] ) ? (string) $payment_result['redirect'] : '';
        if ( 'pending' === $order->get_status() && '' === $redirect_after_payment ) {
            $order->update_status( 'processing', __( 'Payment received.', 'tejcart' ) );
        }

        // Coupon reservation AND global usage increment happen inside
        // the order-creation transaction (see the reserve loop above).
        // F-H2 / #925: post-COMMIT increment created a window where
        // concurrent buyers could both pass read-only validation, both
        // commit, and only one's conditional UPDATE actually claimed
        // the global slot — leaving the merchant with N+1 paid orders
        // for an N-use coupon. Moving the increment into the
        // transaction (and the rollback to Order_Coupon_Rollback on
        // failed status) keeps the counter and the order set in sync.

        /**
         * Fires after the order has been processed and payment initiated.
         *
         * @param int   $order_id    The order ID.
         * @param array $posted_data The sanitized checkout data.
         */
        do_action( 'tejcart_checkout_order_processed', $order_id, $posted_data );

        \TejCart\Security\Rate_Limiter::reset( 'checkout', $ip );

        // Release the idempotency lock on the SUCCESS path too. Every
        // failure / abort branch above frees the lock, but the happy path
        // historically returned without releasing it, so the lock lingered
        // for its full 5-minute TTL. That is invisible for synchronous
        // gateways — their success status transition empties the cart, so a
        // retry hashes to a different lock handle — but redirect / wallet
        // gateways like PayPal leave the order `pending` and deliberately
        // preserve the cart until the buyer returns from approval, leaving
        // the lock key (uid + session + cart items + total + email)
        // byte-for-byte identical. A buyer who opened the PayPal approval
        // popup, closed it without paying, and clicked the PayPal button
        // again was then wrongly told "Your order is already being placed
        // (likely from another tab)" for up to five minutes. The lock's job
        // — collapsing a near-simultaneous double-submit of the SAME cart —
        // is complete once the order is committed and the gateway has
        // returned, so free it here. Use the guarded release (only free a
        // lock this request actually holds), mirroring the abort branches.
        delete_option( $idempotency_key );
        if ( $use_lock_table && $idempotency_locked ) {
            \TejCart\Core\Lock::release( $lock_handle );
        }

        // F-M8 / #942: cart cleanup is now exclusively handled by the
        // Order_Cart_Cleanup listener bound to
        // tejcart_order_status_{processing,on-hold,completed}. The
        // status transition above (update_status 'processing') already
        // fired the listener, which destroys the session and clears
        // the cookie. The inline empty_cart() call here was redundant
        // AND it punished abandoned PayPal sessions: the cart was
        // emptied even on payment failure, so the buyer couldn't go
        // back and retry without rebuilding from scratch. The listener
        // only fires on success paths, which is the correct behaviour.

        $redirect_url = isset( $payment_result['redirect'] ) ? $payment_result['redirect'] : '';

        /**
         * Filters the URL the customer is redirected to after checkout.
         *
         * @param string $redirect_url The redirect URL.
         * @param int    $order_id     The order ID.
         */
        $redirect_url = apply_filters( 'tejcart_checkout_redirect_url', $redirect_url, $order_id );

        // Defence in depth (audit 02 L-2): the JS submit handler trusts
        // the redirect over `isSafeUrl`, but a gateway returning a URL
        // with attacker-controlled query string could still land junk
        // params on the destination page. Strip query/fragment for
        // internal redirects; pass external redirects through unchanged
        // so wallet/3DS bounces still work.
        $redirect_url = $this->sanitise_checkout_redirect( (string) $redirect_url );

        return array(
            'result'   => 'success',
            'redirect' => $redirect_url,
        );
    }

    /**
     * Strip query string + fragment from internal checkout redirects.
     *
     * External hosts (gateway-hosted wallets, 3DS challenge pages) are
     * returned unchanged because they legitimately need session and
     * return-url query parameters. The whitelist of query keys retained
     * on internal redirects can be extended via the
     * `tejcart_checkout_redirect_query_allowlist` filter.
     *
     * @param string $url Filtered redirect URL.
     * @return string
     */
    private function sanitise_checkout_redirect( string $url ): string {
        if ( '' === $url ) {
            return $url;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $url;
        }
        $home_host = wp_parse_url( (string) home_url(), PHP_URL_HOST );
        if ( '' === (string) $home_host || strcasecmp( $home_host, (string) $parts['host'] ) !== 0 ) {
            return $url;
        }

        /**
         * Filter the query-string keys preserved on internal checkout
         * redirects. Anything not in the allow-list is dropped before
         * the URL is sent to the JS submit handler.
         *
         * @param string[] $allowed Keys kept verbatim.
         */
        $allowed = (array) apply_filters(
            'tejcart_checkout_redirect_query_allowlist',
            array( 'key', 'order_id', 'order_received', 'order-received' )
        );

        $query_pairs = array();
        if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
            wp_parse_str( (string) $parts['query'], $query_pairs );
            if ( ! empty( $query_pairs ) && ! empty( $allowed ) ) {
                $query_pairs = array_intersect_key( $query_pairs, array_flip( $allowed ) );
            } else {
                $query_pairs = array();
            }
        }

        $scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
        $rebuilt = $scheme . '://' . (string) $parts['host'];
        if ( ! empty( $parts['port'] ) ) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= isset( $parts['path'] ) ? (string) $parts['path'] : '/';
        if ( ! empty( $query_pairs ) ) {
            $rebuilt = add_query_arg( $query_pairs, $rebuilt );
        }
        return $rebuilt;
    }

    /**
     * Restore stock for an order when payment fails.
     *
     * In the current design, stock is not pre-reserved before payment,
     * so this method only marks the order as failed. It exists as a
     * hook point for extensions that implement stock reservation.
     *
     * @param int $order_id The order ID.
     * @return void
     */
    private function restore_stock( $order_id ) {
        /**
         * Fires when stock restoration is requested for a failed order.
         *
         * Extensions that pre-reserve stock can hook here to release it.
         *
         * @param int $order_id The order ID.
         */
        do_action( 'tejcart_restore_stock_for_order', $order_id );
    }

    /**
     * Validate cart prices by reloading product data from the database.
     *
     * Ensures all products still exist and are purchasable, then forces
     * a recalculation of totals from authoritative DB prices.
     *
     * @param \TejCart\Cart\Cart $cart The cart instance.
     * @return bool True if all prices are valid, false otherwise.
     */
    private function validate_cart_prices( $cart ) {
        foreach ( $cart->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $product    = \TejCart\Product\Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log( sprintf( 'validate_cart_prices: product #%d no longer exists.', $product_id ), 'warning' );
                }
                return false;
            }
            if ( ! $product->is_purchasable() ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log( sprintf( 'validate_cart_prices: product #%d is not purchasable.', $product_id ), 'warning' );
                }
                return false;
            }

            // Compare the snapshot price (what the buyer agreed to)
            // against the live product price (what the catalogue says
            // right now). A small drift is expected and intended — the
            // snapshot exists precisely so a mid-flow price tweak
            // doesn't surprise-charge the buyer. But a large
            // divergence is a red flag: either the snapshot is days
            // stale (UX problem), or — combined with a future
            // regression of the C-1 hardening — an attacker has
            // forced a low-priced snapshot for a high-priced product.
            //
            // Default threshold is 50% — i.e. snapshot can't be more
            // than ±50% away from live before we abort. Filterable so
            // stores running aggressive flash sales (which legitimately
            // halve prices mid-session) can opt into a wider window.
            // See review finding M-9.
            if ( method_exists( $item, 'get_price_at_add' ) ) {
                $snapshot_price = $item->get_price_at_add();
                $live_price     = (float) $product->get_price();
                if ( null !== $snapshot_price && $live_price > 0 ) {
                    // Compute drift from the line's authoritative unit
                    // price (`Cart_Item::get_price()`) rather than the raw
                    // `_price_at_add` snapshot. Both that and `$live_price`
                    // (`$product->get_price()`) resolve in the current
                    // request currency, so a multi-currency switcher that
                    // re-bases the snapshot on a mid-session currency
                    // change — or a per-item pricing add-on — can't make
                    // the ratio diverge purely because the two sides were
                    // expressed in different currencies. Falls back to the
                    // raw snapshot when get_price() is unavailable.
                    $snapshot_price = method_exists( $item, 'get_price' )
                        ? (float) $item->get_price()
                        : (float) $snapshot_price;
                    if ( $snapshot_price > 0 ) {
                        /**
                         * Filter the maximum allowed price drift between
                         * the cart snapshot and the live product price
                         * at checkout. Expressed as a fraction in
                         * [0, 1]; default 0.5 (50%).
                         *
                         * @since 1.0.1
                         *
                         * Audit #43 / 02 M-1 — default tightened from
                         * 0.5 (50%) to 0.1 (10%). Earlier we accepted
                         * a 49% drift silently in either direction
                         * because the snapshot won, which let a flash
                         * sale silently overcharge a buyer who added
                         * before the sale started. 10% is loose
                         * enough for normal merchandising changes
                         * (currency rounding, regular promotions) but
                         * tight enough that material re-pricing
                         * triggers the abort path. Merchants who
                         * intentionally accept wider drift can lift
                         * the threshold via the filter.
                         *
                         * @param float                 $threshold Default 0.1.
                         * @param \TejCart\Cart\Cart    $cart      Cart being validated.
                         */
                        $threshold = (float) apply_filters(
                            'tejcart_validate_cart_prices_drift_threshold',
                            0.1,
                            $cart
                        );
                        $threshold = max( 0.0, min( 1.0, $threshold ) );

                        $reference  = max( $snapshot_price, $live_price );
                        $drift_pct  = $reference > 0 ? abs( $snapshot_price - $live_price ) / $reference : 0.0;

                        if ( $threshold > 0 && $drift_pct > $threshold ) {
                            if ( function_exists( 'tejcart_log' ) ) {
                                tejcart_log(
                                    sprintf(
                                        'validate_cart_prices: product #%d snapshot/live drift %.1f%% (snapshot=%.4f, live=%.4f) exceeds threshold %.1f%%; rejecting.',
                                        $product_id,
                                        $drift_pct * 100,
                                        $snapshot_price,
                                        $live_price,
                                        $threshold * 100
                                    ),
                                    'warning'
                                );
                            }
                            return false;
                        }
                    }
                }
            }

            // When the line references a Variable_Product
            // variation the parent's `is_purchasable()` is not enough:
            // a single variation can go OOS while the parent is still
            // purchasable, and the buyer would then be able to submit
            // for a variation that has 0 stock. Re-check the variation
            // explicitly.
            if ( method_exists( $item, 'is_variation' ) && $item->is_variation()
                && method_exists( $item, 'get_variation_id' )
            ) {
                $variation_id = (int) $item->get_variation_id();
                if ( $variation_id > 0 ) {
                    $variation = \TejCart\Product\Product_Factory::get_product( $variation_id );
                    if ( ! $variation || ! $variation->is_purchasable() ) {
                        if ( function_exists( 'tejcart_log' ) ) {
                            tejcart_log(
                                sprintf( 'validate_cart_prices: variation #%d no longer purchasable.', $variation_id ),
                                'warning'
                            );
                        }
                        return false;
                    }
                    if ( method_exists( $variation, 'is_in_stock' ) && ! $variation->is_in_stock() ) {
                        if ( function_exists( 'tejcart_log' ) ) {
                            tejcart_log(
                                sprintf( 'validate_cart_prices: variation #%d out of stock at submit.', $variation_id ),
                                'warning'
                            );
                        }
                        return false;
                    }
                }
            }
        }

        $cart->recalculate();
        return true;
    }

    /**
     * Validate stock levels for all cart items using row-level locking.
     *
     * Uses SELECT ... FOR UPDATE on the products table so concurrent
     * checkouts serialise on the same product row, and additionally
     * subtracts other-session active reservations (held by Stock_Reservation)
     * so a checkout cannot consume inventory that another shopper has
     * legitimately reserved while sitting on the cart page.
     *
     * @param \TejCart\Cart\Cart $cart The cart instance.
     * @return true|\WP_Error True if stock is sufficient, WP_Error otherwise.
     */
    private function validate_stock( $cart ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        $reservations = class_exists( '\\TejCart\\Cart\\Stock_Reservation' )
            ? new \TejCart\Cart\Stock_Reservation()
            : null;

        $items = $cart->get_items();
        usort(
            $items,
            static function ( $a, $b ) {
                return (int) $a->get_product_id() <=> (int) $b->get_product_id();
            }
        );

        foreach ( $items as $item ) {
            $product_id = $item->get_product_id();
            $quantity   = $item->get_quantity();

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT manage_stock, stock_quantity, stock_status, backorders FROM {$table} WHERE id = %d FOR UPDATE",
                    $product_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! $row ) {
                return new \WP_Error(
                    'product_not_found',
                    /* translators: %d: product ID */
                    sprintf( __( 'Product #%d is no longer available.', 'tejcart' ), $product_id )
                );
            }

            // Backorders: products with manage_stock=1 and backorders
            // in ('notify','yes') can be added past on_hand=0. The
            // previous code rejected on stock_status='outofstock'
            // regardless of the backorders flag, so the buyer was
            // allowed to add the item at Cart::add but blocked at
            // checkout submission — a half-implemented feature.
            $backorders_allowed = isset( $row->backorders )
                && in_array( (string) $row->backorders, array( 'yes', 'notify' ), true );

            if ( 'outofstock' === $row->stock_status && ! $backorders_allowed ) {
                return new \WP_Error(
                    'out_of_stock',
                    /* translators: %d: product ID */
                    sprintf( __( 'Product #%d is out of stock.', 'tejcart' ), $product_id )
                );
            }

            if ( empty( $row->manage_stock ) ) {
                continue;
            }

            $on_hand            = (int) $row->stock_quantity;
            $reserved_by_others = $reservations
                ? max( 0, (int) $reservations->reserved_by_others( (int) $product_id ) )
                : 0;
            $available          = $on_hand - $reserved_by_others;

            // When backorders are allowed, the stock-availability gate
            // doesn't apply — the merchant explicitly opted into
            // selling beyond on-hand. Stock will be decremented at
            // capture time per the standard flow (negative on_hand is
            // the documented signal of an open backorder).
            if ( $backorders_allowed ) {
                continue;
            }

            if ( $available < $quantity ) {
                return new \WP_Error(
                    'insufficient_stock',
                    sprintf(
                        /* translators: 1: product ID, 2: available stock quantity */
                        __( 'Product #%1$d only has %2$d items available right now.', 'tejcart' ),
                        $product_id,
                        max( 0, $available )
                    )
                );
            }
        }

        return true;
    }

    /**
     * Create a WordPress user account during checkout.
     *
     * Uses the billing email as the username and email. Sets the password
     * from the account_password field or auto-generates one. Logs the
     * customer in and links the new user ID to the order.
     *
     * @param array                  $posted_data Sanitized posted data.
     * @param \TejCart\Order\Order   $order       The order object.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private function maybe_create_account( $posted_data, $order ) {
        $email = sanitize_email( $posted_data['billing_email'] ?? '' );

        if ( ! is_email( $email ) ) {
            return new \WP_Error( 'invalid_email', __( 'A valid email address is required to create an account.', 'tejcart' ) );
        }

        // L-6 timing-equaliser. The existing-account branch runs a
        // single email_exists() call and links the order; the new-
        // account branch runs sanitize_user / username_exists /
        // wp_create_user / wp_update_user / set_role / wp_clear_auth /
        // wp_set_auth_cookie / wp_login. The two paths differ by tens
        // of milliseconds, which combined with the public POST endpoint
        // gives a differential-timing oracle for "is this email a
        // customer?". The Rate_Limiter('checkout', 10/5min) already
        // narrows the attacker's probe budget; this microsecond budget
        // squeezes the remaining differential below the network
        // jitter floor for any practical attacker. Residual leak is
        // acceptable; full removal would require a precomputed dummy
        // user pool or moving the linking out-of-band, both of which
        // are larger refactors.
        $checkout_start_ms = (int) ( microtime( true ) * 1000 );

        $existing_user_id = email_exists( $email );
        if ( $existing_user_id ) {
            $order->set( 'customer_id', (int) $existing_user_id );
            $order->save();

            /**
             * Fires when checkout encounters an existing account email so
             * themes/notices can prompt the buyer to log in next time.
             *
             * @param int   $user_id     Existing user ID.
             * @param array $posted_data Sanitized checkout data.
             */
            do_action( 'tejcart_checkout_linked_existing_user', (int) $existing_user_id, $posted_data );

            // L-6: pad the existing-account branch up to the same
            // wall-clock budget the new-account branch typically
            // consumes (~150ms incl. wp_create_user). usleep() is best-
            // effort under PHP's clock; we cap at 200ms to bound
            // worst-case latency contribution to checkout.
            $elapsed_ms = (int) ( microtime( true ) * 1000 ) - $checkout_start_ms;
            if ( $elapsed_ms < 150 ) {
                usleep( ( 150 - $elapsed_ms ) * 1000 );
            }

            return true;
        }

        $auto_password = empty( $posted_data['account_password'] );
        $password      = $auto_password ? wp_generate_password( 16, true, false ) : $posted_data['account_password'];

        $username = sanitize_user( $email, true );

        if ( username_exists( $username ) ) {
            $username = sanitize_user( $email . '_' . wp_rand( 100, 999 ), true );
        }

        /**
         * F-L9 / #959: addons may override the wp_create_user
         * arguments (e.g. SSO IDs, role assignment, display_name).
         *
         * @param array{username:string,password:string,email:string} $args
         * @param array  $posted_data  Sanitized checkout POST.
         */
        $account_args = apply_filters(
            'tejcart_checkout_create_account_args',
            array(
                'username' => $username,
                'password' => $password,
                'email'    => $email,
            ),
            $posted_data
        );
        $username = isset( $account_args['username'] ) ? (string) $account_args['username'] : $username;
        $password = isset( $account_args['password'] ) ? (string) $account_args['password'] : $password;
        $email    = isset( $account_args['email'] )    ? (string) $account_args['email']    : $email;

        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $user = new \WP_User( $user_id );
        $user->set_role( 'customer' );

        wp_update_user( array(
            'ID'         => $user_id,
            'first_name' => sanitize_text_field( $posted_data['billing_first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $posted_data['billing_last_name'] ?? '' ),
        ) );

        // Clear any pre-existing auth cookies (a previous logged-in
        // tab on a shared / kiosk browser) before we issue new ones, so
        // there is no overlap of identities, and so the cart-session
        // cookie is regenerated against the new user. Without this,
        // Cart_Session::regenerate_on_login (bound to `wp_login`) does
        // not see a fresh login and the guest cart cookie is never
        // rotated — a session-fixation foothold.
        wp_clear_auth_cookie();
        wp_set_current_user( $user_id );

        // `false` issues a session cookie (cleared on browser close),
        // not the 14-day persistent "remember me" cookie. The buyer
        // never asked to be remembered; OWASP / PCI guidance is that
        // long-lived auth cookies must be opt-in. A merchant who
        // wants opt-in remember-me on checkout should add a checkbox
        // and pass the value here.
        wp_set_auth_cookie( $user_id, false );

        // Fire `wp_login` so Cart_Session::regenerate_on_login()
        // rotates the cart cookie for the new identity (cart key
        // rotation defeats fixation), and so any other plugin that
        // reacts to logins (analytics, audit, MFA enrollment) sees
        // the new user. WP itself only fires `wp_login` when login
        // happens through a form / cookie path; checkout-driven
        // account creation does not, so we surface it here.
        $new_user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
        if ( $new_user ) {
            $login_name = isset( $new_user->user_login ) ? (string) $new_user->user_login : '';
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook, fired here so listeners on programmatic logins run.
            do_action( 'wp_login', $login_name, $new_user );
        }

        $order->set( 'customer_id', $user_id );
        $order->save();

        /**
         * Fires after a customer account is created during checkout.
         *
         * @param int   $user_id     The new user ID.
         * @param array $posted_data The sanitized checkout data.
         */
        do_action( 'tejcart_created_customer', $user_id, $posted_data );

        if ( $auto_password && function_exists( 'wp_new_user_notification' ) ) {
            wp_new_user_notification( $user_id, null, 'user' );
        }

        return true;
    }

    /**
     * Get all checkout fields, filtered.
     *
     * @return array Checkout field definitions.
     */
    public function get_checkout_fields() {
        $fields = $this->fields->get_fields();

        /**
         * Filters the checkout fields returned by the main handler.
         *
         * @param array $fields All checkout field definitions.
         */
        return apply_filters( 'tejcart_checkout_fields', $fields );
    }

    /**
     * Prune expired checkout idempotency locks from wp_options.
     *
     * Called opportunistically from the checkout entry point so the table
     * is kept clean even on hosts that disable WP-Cron. Each lock value is
     * a serialized array carrying an `expires` timestamp; we select the
     * full set matching the naming prefix (already index-friendly against
     * options.option_name) and delete rows whose expiry is older than the
     * supplied $now with a safety grace window.
     *
     * Visibility is `public static` so the Action Scheduler hourly hook
     * (M-8) can fire it without going through reflection — the
     * 0.5%-sampled call from process() guards against WP-Cron-less
     * hosts but isn't deterministic on low-traffic stores. The hourly
     * job catches them. Both paths are idempotent under
     * concurrent fire (DELETE on a missing row is a no-op).
     *
     * @param int $now Current timestamp.
     */
    public static function sweep_expired_idempotency_locks( $now = 0 ) {
        global $wpdb;

        if ( $now <= 0 ) {
            $now = time();
        }
        $cutoff = $now - MINUTE_IN_SECONDS;
        // Bound the per-call scan so the cleanup never holds a long lock
        // on wp_options. On a heavily-affected store the hourly job will
        // chip away in 500-row chunks.
        // F-CCM-006: wrap in $wpdb->prepare() per project convention (no user input here,
        // but PHPCS flags bare queries). Added ORDER BY option_id for deterministic paging
        // so concurrent cron runs chip away the same oldest-first slice.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
                'tejcart_co_lock_%',
                500
            )
        );
        if ( empty( $rows ) ) {
            return;
        }

        $expired = array();
        foreach ( $rows as $row ) {
            $value = maybe_unserialize( $row->option_value, array( 'allowed_classes' => false ) );
            $exp   = is_array( $value ) && isset( $value['expires'] ) ? (int) $value['expires'] : 0;
            if ( $exp && $exp < $cutoff ) {
                $expired[] = $row->option_name;
            }
        }

        foreach ( $expired as $name ) {
            delete_option( $name );
        }

        if ( ! empty( $expired ) && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'Checkout sweeper removed %d expired idempotency lock(s).', count( $expired ) ),
                'info'
            );
        }
    }

    /**
     * Schedule the recurring hourly sweep of expired idempotency locks.
     *
     * Belt-and-braces complement to the 0.5%-sampled in-request sweep
     * already wired into process(). The sampler is deterministic at
     * scale but unreliable on low-traffic stores; the hourly Action
     * Scheduler job catches the long tail without requiring WP-Cron
     * (Action Scheduler runs its own loopback queue). Idempotent.
     *
     * Bound to `tejcart_init` from src/Tier2/bootstrap.php-equivalent
     * registration: see the call in the plugin bootstrap below.
     *
     * @return void
     */
    public static function ensure_idempotency_lock_sweeper_scheduled(): void {
        if ( ! class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            return;
        }
        $scheduler = \TejCart\Core\Action_Scheduler::instance();
        if ( method_exists( $scheduler, 'is_scheduled' ) && $scheduler->is_scheduled( 'tejcart_co_lock_cleanup' ) ) {
            return;
        }
        if ( method_exists( $scheduler, 'schedule_recurring' ) ) {
            $scheduler->schedule_recurring( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'tejcart_co_lock_cleanup' );
        }
    }

    /**
     * Resolve the "billing same as shipping" flag from a posted-data array.
     *
     * The modern checkout posts `tejcart_billing_different` (checked = the
     * billing address differs from shipping). For backwards compatibility
     * we also accept the legacy `tejcart_ship_same` (checked = same).
     *
     * @param array $post Sanitized $_POST-shaped array.
     * @return string '1' when billing should mirror shipping, '' otherwise.
     */
    public static function resolve_ship_same_flag( array $post ): string {
        if ( array_key_exists( 'tejcart_billing_different', $post ) ) {
            return self::is_truthy_flag( $post['tejcart_billing_different'] ) ? '' : '1';
        }

        return ! empty( $post['tejcart_ship_same'] ) ? '1' : '';
    }

    /**
     * Mirror values between shipping_* and billing_* keys so downstream
     * consumers always see both sides populated.
     *
     * Modern flow: shipping is the primary visible address → copy to billing.
     * Legacy flow: billing was the primary form → copy to shipping.
     * If both sides are filled, shipping wins (matches the modern UI). If
     * neither side is filled, the keys are left untouched.
     *
     * @param array    $posted_data   Posted data keyed by field name.
     * @param string[] $shipping_keys List of shipping_* field keys to mirror.
     * @return array Updated posted data.
     */
    public static function mirror_address_fields( array $posted_data, array $shipping_keys ): array {
        foreach ( $shipping_keys as $shipping_key ) {
            $billing_key = preg_replace( '/^shipping_/', 'billing_', (string) $shipping_key );
            if ( ! $billing_key || $billing_key === $shipping_key ) {
                continue;
            }

            $shipping_value = isset( $posted_data[ $shipping_key ] ) ? trim( (string) $posted_data[ $shipping_key ] ) : '';
            $billing_value  = isset( $posted_data[ $billing_key ] ) ? trim( (string) $posted_data[ $billing_key ] ) : '';

            if ( '' !== $shipping_value ) {
                $posted_data[ $billing_key ] = $posted_data[ $shipping_key ];
            } elseif ( '' !== $billing_value ) {
                $posted_data[ $shipping_key ] = $posted_data[ $billing_key ];
            }
        }

        return $posted_data;
    }

    /**
     * Coerce common HTML form true-ish values to a real boolean.
     *
     * @param mixed $value Posted value.
     * @return bool
     */
    private static function is_truthy_flag( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        return in_array( (string) $value, array( '1', 'true', 'yes', 'on' ), true );
    }
}
