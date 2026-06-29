<?php
/**
 * Pay-for-order endpoint.
 *
 * Lets a customer complete payment for an order that is `pending`,
 * `failed`, or `on-hold` via a unique link (e.g. after a declined card)
 * instead of re-placing the order.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

use TejCart\Order\Order;
use TejCart\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the `[tejcart_order_pay]` shortcode and renders the payment
 * form for an existing order identified by `order_id` + `order_key`.
 */
class Pay_For_Order {
    /**
     * Statuses for which pay-for-order is allowed.
     *
     * @var string[]
     */
    private const PAYABLE_STATUSES = array( 'pending', 'failed', 'on-hold' );

    /**
     * Register hooks + shortcode.
     *
     * @return void
     */
    public function init(): void {
        add_shortcode( 'tejcart_order_pay', array( $this, 'render_shortcode' ) );

        // POST handler. Runs early so the gateway redirect emitted by
        // process_payment() can fire before any template output starts.
        // template_redirect is the canonical hook for this in WP — it's
        // after the query is resolved (so is_singular() / page checks
        // work) and before headers are committed.
        add_action( 'template_redirect', array( $this, 'maybe_handle_post' ), 5 );
    }

    /**
     * Generic POST handler for the [tejcart_order_pay] shortcode.
     *
     * The shortcode previously rendered a `<form method="post">` with
     * no listener — the default "Pay now" button reloaded the page
     * without doing anything. Gateways could hook
     * `tejcart_pay_for_order_form` to inject their own widgets (the
     * PayPal Smart Buttons addon does this), but the bare radio-group
     * + submit form had no server side.
     *
     * This handler covers the bare case: it verifies the nonce + order
     * key, resolves the chosen payment_method against the gateway
     * registry, and dispatches to `$gateway->process_payment( $order_id )`.
     * Gateways with their own JS-driven form (PayPal, Stripe) continue
     * to short-circuit via the legacy action hook — the handler is
     * skipped when their `data-tejcart-paypal-button` or equivalent
     * JS handler has already taken over the submit.
     */
    public function maybe_handle_post(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['tejcart_pay_nonce'], $_POST['order_id'], $_POST['order_key'], $_POST['payment_method'] ) ) {
            return;
        }

        $order_id       = absint( $_POST['order_id'] );
        $order_key      = sanitize_text_field( wp_unslash( (string) $_POST['order_key'] ) );
        $payment_method = sanitize_key( (string) $_POST['payment_method'] );
        $nonce          = sanitize_text_field( wp_unslash( (string) $_POST['tejcart_pay_nonce'] ) );

        if ( ! $order_id || '' === $order_key || '' === $payment_method ) {
            return;
        }
        if ( ! wp_verify_nonce( $nonce, 'tejcart_pay_for_order_' . $order_id ) ) {
            wp_die( esc_html__( 'Your session has expired. Please reload the page and try again.', 'tejcart' ), '', array( 'response' => 403 ) );
        }

        $order = new Order( $order_id );

        // Constant-time order_key check matched against the real format
        // width (see render_shortcode for the same dummy-key pattern).
        $expected_key = (string) ( $order->get_id() ? $order->get_order_key() : 'nxc_' . str_repeat( '0', 48 ) );
        if ( ! $order->get_id() || ! hash_equals( $expected_key, $order_key ) ) {
            wp_die( esc_html__( 'This payment link is not valid.', 'tejcart' ), '', array( 'response' => 403 ) );
        }
        if ( ! in_array( (string) $order->get_status(), self::PAYABLE_STATUSES, true ) ) {
            wp_die( esc_html__( 'This order is not awaiting payment.', 'tejcart' ), '', array( 'response' => 400 ) );
        }

        // Logged-in customers must own the order. Guest orders fall back
        // to the order_key as the bearer credential (consistent with the
        // shortcode's render_shortcode flow).
        if ( $order->get_customer_id() && is_user_logged_in() ) {
            if ( (int) $order->get_customer_id() !== get_current_user_id()
                && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) )
            ) {
                wp_die( esc_html__( 'You do not have permission to pay for this order.', 'tejcart' ), '', array( 'response' => 403 ) );
            }
        }

        if ( ! function_exists( 'tejcart' ) ) {
            return;
        }
        $registry = tejcart()->gateways();
        if ( ! $registry ) {
            return;
        }
        $gateway = $registry->get_gateway( $payment_method );
        if ( ! $gateway ) {
            wp_die( esc_html__( 'The chosen payment method is no longer available.', 'tejcart' ), '', array( 'response' => 400 ) );
        }

        // Stamp the chosen method so order-created listeners (e.g.
        // Order_Manager refund-routing) know which gateway to call.
        $order->set_payment_method( $payment_method );
        $order->save();

        // Fire the same validation hook the canonical
        // Checkout::process() pipeline does, so addons that depend on
        // it can react to the (possibly new) gateway choice. The
        // Subscriptions vault bridges hook this to force the
        // gateway-specific save-method flag; without it a buyer who
        // switches from a non-recurring gateway (COD / Bank Transfer)
        // to a recurring-capable one (Stripe / PayPal / Auth.Net) at
        // pay-time gets a successful charge but no vaulted token, so
        // the first renewal silently fails. Posted-data is minimal —
        // the order already carries billing/shipping addresses.
        $posted_data = array(
            'payment_method' => $payment_method,
        );
        do_action( 'tejcart_checkout_validation', $posted_data );

        $result = $gateway->process_payment( $order_id );

        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 502 ) );
        }
        if ( is_array( $result ) ) {
            if ( ! empty( $result['redirect'] ) ) {
                wp_safe_redirect( (string) $result['redirect'] );
                exit;
            }
            // Gateway accepted; fall through to the order-received page.
            $order_received_page = (int) get_option( 'tejcart_order_received_page_id', 0 );
            if ( $order_received_page ) {
                wp_safe_redirect( add_query_arg(
                    array(
                        'order_id'  => $order_id,
                        'order_key' => $order_key,
                    ),
                    (string) get_permalink( $order_received_page )
                ) );
                exit;
            }
        }
        // Result is true / null / unrecognised — re-render the form
        // and let the gateway's own JS take over if it's hooked in.
    }

    /**
     * Build the customer-facing pay URL for an order.
     *
     * @param Order $order Order instance.
     * @return string
     */
    public static function get_pay_url( Order $order ): string {
        $page_id = (int) get_option( 'tejcart_order_pay_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : home_url( '/order-pay/' );

        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ),
            $base
        );
    }

    /**
     * Shortcode entry point.
     *
     * @return string
     */
    public function render_shortcode(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

        if ( ! $order_id || '' === $order_key ) {
            return '<p>' . esc_html__( 'Invalid payment link. Please check the URL and try again.', 'tejcart' ) . '</p>';
        }

        // Defence-in-depth against order_key brute-force: cap per-IP attempts.
        // The 32-char hex order_key is too large to brute-force in practice,
        // but rate-limiting failed lookups makes scanning detectable and
        // protects against weakly-generated keys on legacy installs.
        // Audit #61 / 04 M-9 — defer IP resolution to Rate_Limiter
        // so the trusted-proxy gate (tejcart_trusted_proxies)
        // honours X-Forwarded-For correctly. Reading raw REMOTE_ADDR
        // bucketed every buyer under the load-balancer IP behind a
        // reverse proxy, collapsing the per-buyer ceiling.
        $remote_ip = (string) Rate_Limiter::get_client_ip();
        if ( '' !== $remote_ip
            && ! Rate_Limiter::check( 'pay_for_order', $remote_ip, 30, 60 )
        ) {
            if ( ! headers_sent() ) {
                status_header( 429 );
            }
            return '<p>' . esc_html__( 'Too many attempts. Please try again in a minute.', 'tejcart' ) . '</p>';
        }

        $order = new Order( $order_id );

        // Single opaque message for both "order missing" and "wrong key" so
        // an attacker cannot enumerate which order ids exist by walking the
        // integer space. Constant-time hash_equals against a dummy key when
        // the order is missing keeps the wall-clock cost matched (L-1).
        //
        // Dummy MUST match the real key width — Order::save() generates
        // `nxc_` + bin2hex(random_bytes(24)) = 52 chars. The previous
        // 32-char dummy let hash_equals short-circuit on length mismatch,
        // exposing a timing oracle that distinguished "order missing"
        // from "wrong key".
        $expected_key = (string) ( $order->get_id() ? $order->get_order_key() : 'nxc_' . str_repeat( '0', 48 ) );

        if ( ! $order->get_id() || ! hash_equals( $expected_key, $order_key ) ) {
            if ( '' !== $remote_ip ) {
                Rate_Limiter::record( 'pay_for_order', $remote_ip, 60 );
            }
            return '<p>' . esc_html__( 'This payment link is not valid. Please check the URL and try again.', 'tejcart' ) . '</p>';
        }

        // `order_key` alone is not a sufficient capability: anyone
        // holding (or guessing) the `order_id` + `order_key` pair could
        // otherwise render the order's
        // contents and submit a payment under their own card. For orders
        // owned by a registered customer we additionally require the
        // current logged-in user to match the order's customer_id (or be
        // a shop manager). Guest orders (customer_id === 0) keep relying
        // on the order_key as the bearer credential, which mirrors the
        // industry convention for guest pay-for-order links.
        $owner_id = (int) $order->get_customer_id();
        if ( $owner_id > 0 ) {
            $current = (int) get_current_user_id();
            $is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
            if ( $current !== $owner_id && ! $is_admin ) {
                return '<p>' . esc_html__( 'You are not authorised to pay for this order. Please log in to the account that placed the order.', 'tejcart' ) . '</p>';
            }
        }

        if ( ! in_array( $order->get_status(), self::PAYABLE_STATUSES, true ) ) {
            return '<p>' . esc_html(
                sprintf(
                    /* translators: %s: human-readable order status */
                    __( 'This order is already %s and cannot be paid again.', 'tejcart' ),
                    $order->get_status()
                )
            ) . '</p>';
        }

        ob_start();
        $this->render_form( $order );
        return (string) ob_get_clean();
    }

    /**
     * Render the payment form. Reuses the Gateway_Registry UI so every
     * configured gateway is available.
     *
     * @param Order $order Order instance.
     * @return void
     */
    private function render_form( Order $order ): void {
        $gateways = tejcart()->gateways()->get_available_gateways();
        ?>
        <div class="tejcart-order-pay">
            <header class="tejcart-order-pay__header">
                <h2>
                    <?php
                    printf(
                        /* translators: %s: order number */
                        esc_html__( 'Pay for order %s', 'tejcart' ),
                        esc_html( $order->get_order_number() )
                    );
                    ?>
                </h2>
            </header>

            <table class="tejcart-order-pay__summary">
                <tr>
                    <th><?php esc_html_e( 'Order number', 'tejcart' ); ?></th>
                    <td><?php echo esc_html( $order->get_order_number() ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                    <td><?php echo wp_kses_post( tejcart_price( $order->get_total(), (string) $order->get_currency() ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                    <td><?php echo esc_html( ucfirst( $order->get_status() ) ); ?></td>
                </tr>
            </table>

            <form method="post" class="tejcart-order-pay__form" data-tejcart-order-pay>
                <?php wp_nonce_field( 'tejcart_pay_for_order_' . $order->get_id(), 'tejcart_pay_nonce' ); ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
                <input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>" />

                <h3><?php esc_html_e( 'Choose a payment method', 'tejcart' ); ?></h3>
                <ul class="tejcart-order-pay__methods">
                    <?php
                    // N-L3 (sibling of F-H6): pre-check the first
                    // gateway so the radio group is submittable on the
                    // very first paint. Without a default the buyer has
                    // to click before "Pay" becomes meaningful — an
                    // unforced UX click for a stranded order. The
                    // $gateways array may be associative (keyed by
                    // gateway id), so we use a local counter.
                    $tejcart_pay_seen = 0;
                    foreach ( $gateways as $gateway ) :
                        $tejcart_pay_is_default = ( 0 === $tejcart_pay_seen );
                        $tejcart_pay_seen++;
                    ?>
                        <li>
                            <label>
                                <input type="radio" name="payment_method" value="<?php echo esc_attr( $gateway->get_id() ); ?>" <?php checked( $tejcart_pay_is_default, true ); ?> />
                                <span><?php echo esc_html( $gateway->get_title() ); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                /**
                 * Fires inside the pay-for-order form so gateways can inject
                 * their own UI (card fields, PayPal buttons, etc.) against
                 * the pre-built order.
                 *
                 * @param Order $order The order being paid.
                 */
                do_action( 'tejcart_pay_for_order_form', $order );
                ?>

                <button type="submit" class="tejcart-btn tejcart-btn--primary">
                    <?php esc_html_e( 'Pay now', 'tejcart' ); ?>
                </button>
            </form>
        </div>
        <?php
    }
}
