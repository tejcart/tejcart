<?php
/**
 * Single-order admin views: detail page, refund processing, and
 * manual order creation.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Money\Currency;
use TejCart\Order\Order;
use TejCart\Order\Order_Manager;
use TejCart\Order\Order_Refund;
use TejCart\Order\Order_Status;
use TejCart\Order\Invoice;
use TejCart\Product\Product_Factory;
use TejCart\Gateways\PayPal\PayPal_Gateway;
use TejCart\Tax\Tax_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the per-order admin screens that the WP_List_Table cannot:
 *
 * - Order detail view (`?action=view&order_id=...`)
 * - Partial refund form (POST inside the detail view)
 * - Manual order creation form (`?action=new`)
 *
 * Mounted from {@see Menu::render_orders()} when an action query var
 * is present.
 */
class Order_Admin {
    /**
     * Static flag so the admin_init handler is only registered once
     * regardless of how many times the class is instantiated.
     *
     * @var bool
     */
    private static bool $hooks_registered = false;

    /**
     * Test-injected Order_Manager. Production code goes through
     * {@see self::order_manager()} which lazily instantiates one.
     *
     * @var Order_Manager|null
     */
    private ?Order_Manager $order_manager = null;

    /**
     * Register POST handlers on admin_init exactly once.
     *
     * @param Order_Manager|null $order_manager Optional. Injected for tests
     *                                          that exercise the refund POST
     *                                          handler without a DB.
     */
    public function __construct( ?Order_Manager $order_manager = null ) {
        $this->order_manager = $order_manager;

        if ( self::$hooks_registered ) {
            return;
        }
        self::$hooks_registered = true;
        add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
    }

    /**
     * Resolve the Order_Manager. Prefers an injected instance (tests), then
     * the DI container (production), finally a fresh instance.
     */
    private function order_manager(): Order_Manager {
        if ( $this->order_manager instanceof Order_Manager ) {
            return $this->order_manager;
        }

        if ( function_exists( 'tejcart' ) ) {
            $container = tejcart()->container();
            if ( $container && $container->has( 'order_manager' ) ) {
                $resolved = $container->make( 'order_manager' );
                if ( $resolved instanceof Order_Manager ) {
                    $this->order_manager = $resolved;
                    return $resolved;
                }
            }
        }

        $this->order_manager = new Order_Manager();
        return $this->order_manager;
    }

    /**
     * Render the page for a given action.
     */
    public function dispatch( string $action ): void {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            wp_die(
                esc_html__( 'You do not have permission to view this page.', 'tejcart' ),
                '',
                array( 'response' => 403 )
            );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'new' === $action ) {
            $this->render_new_form();
            return;
        }

        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            ?>
            <div class="wrap tejcart-admin-wrap">
                <div class="tejcart-page-header">
                    <div class="tejcart-page-header-content">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Orders', 'tejcart' ); ?></a>
                        <h1><?php esc_html_e( 'Order Not Found', 'tejcart' ); ?></h1>
                    </div>
                </div>
                <div class="tejcart-card">
                    <div class="tejcart-empty-state">
                        <span class="dashicons dashicons-cart"></span>
                        <p><?php esc_html_e( 'The requested order could not be found.', 'tejcart' ); ?></p>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $this->render_view( $order );
    }

    /**
     * Process refund and manual-order form submissions.
     *
     * Each action is gated on a TejCart-specific capability so a Shop
     * Manager can refund orders without being granted the full WP
     * `manage_options`. {@see \TejCart\Core\Capabilities::check} falls
     * back to `manage_options` when the granular cap isn't present, so
     * existing single-admin installs keep working unchanged.
     */
    public function maybe_handle_post(): void {
        if ( isset( $_POST['tejcart_refund_action'] )
            && \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::REFUND_ORDERS )
            && check_admin_referer( 'tejcart_refund_order', 'tejcart_refund_nonce' ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
            $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
            $result   = $this->process_refund_post( wp_unslash( $_POST ) );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $order_id . '&refunded=' . (int) $result ) );
            exit;
        }

        // Audit #18 / 06 F-H6 — admin trigger for the Customer_Invoice
        // email. The class was registered with the Email_Manager and
        // shipped a working template, but no caller invoked it; the
        // only way to email a buyer the pay-link was manual copy-paste.
        // Surface a "Send invoice" form action on the order detail
        // screen, gated on MANAGE_ORDERS.
        if ( isset( $_POST['tejcart_send_invoice_action'] )
            && \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS )
            && check_admin_referer( 'tejcart_send_invoice', 'tejcart_send_invoice_nonce' ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
            $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
            $sent     = $this->send_customer_invoice( $order_id );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $order_id . '&invoice_sent=' . (int) $sent ) );
            exit;
        }

        if ( isset( $_POST['tejcart_new_order_action'] )
            && \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS )
            && check_admin_referer( 'tejcart_new_order', 'tejcart_new_order_nonce' ) ) {
            // create_manual_order() sanitises every field it reads at point of
            // use (sanitize_email / sanitize_text_field / absint / Currency::to_minor_units).
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            $order_id = $this->create_manual_order( wp_unslash( $_POST ) );
            $redirect = $order_id
                ? admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $order_id . '&created=1' )
                : admin_url( 'admin.php?page=tejcart-orders&action=new&error=1' );

            wp_safe_redirect( $redirect );
            exit;
        }

        if ( isset( $_POST['tejcart_add_note_action'] )
            && \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS )
            && check_admin_referer( 'tejcart_add_order_note', 'tejcart_add_note_nonce' ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
            $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
            $result   = $this->add_note_post( wp_unslash( $_POST ) );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $order_id . '&note_added=' . (int) $result ) );
            exit;
        }
    }

    /**
     * Validate the posted "Add note" payload and persist it on the order.
     *
     * Public so the test suite can drive the body without dealing with
     * `wp_safe_redirect(); exit;`. Cap and nonce checks live on the
     * caller.
     *
     * @param array $post Already-unslashed POST payload.
     * @return int 1 on success, 0 on failure.
     */
    public function add_note_post( array $post ): int {
        $order_id = isset( $post['order_id'] ) ? absint( $post['order_id'] ) : 0;
        $content  = isset( $post['order_note_content'] )
            ? sanitize_textarea_field( (string) $post['order_note_content'] )
            : '';
        $is_customer = ! empty( $post['order_note_customer'] );

        if ( ! $order_id || '' === trim( $content ) ) {
            return 0;
        }

        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            return 0;
        }

        $saved = $order->add_note( $content, $is_customer );

        if ( $saved && $is_customer ) {
            /**
             * Fires when an admin adds a customer-visible order note.
             *
             * Listeners (e.g. an email template) may dispatch a
             * notification to the buyer. Internal notes do NOT fire this
             * action.
             *
             * @param int                  $order_id Order ID.
             * @param string               $content  Sanitised note body.
             * @param \TejCart\Order\Order $order    The order object.
             */
            do_action( 'tejcart_admin_customer_note_added', $order_id, $content, $order );
        }

        return $saved ? 1 : 0;
    }

    /**
     * Send the Customer_Invoice email for the given order. Used by the
     * "Send invoice" admin action on the order detail screen (Audit #18 /
     * 06 F-H6 — previously the email class was registered but no caller
     * ever invoked it).
     *
     * @param int $order_id Order ID.
     * @return int 1 when dispatched, 0 otherwise.
     */
    public function send_customer_invoice( int $order_id ): int {
        if ( $order_id <= 0 ) {
            return 0;
        }
        if ( ! function_exists( 'tejcart' ) ) {
            return 0;
        }
        $tejcart = tejcart();
        if ( ! is_object( $tejcart ) || ! method_exists( $tejcart, 'emails' ) ) {
            return 0;
        }
        $manager = $tejcart->emails();
        if ( ! is_object( $manager ) || ! method_exists( $manager, 'send_now' ) ) {
            return 0;
        }

        $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        if ( ! is_object( $order ) ) {
            return 0;
        }

        return $manager->send_now( 'customer_invoice', array( $order_id, $order ) ) ? 1 : 0;
    }

    /**
     * Validate posted refund data and dispatch it through Order_Manager.
     *
     * Extracted from {@see self::maybe_handle_post()} so the refund body can
     * be exercised directly in unit tests without dealing with the
     * `wp_safe_redirect(); exit;` tail of the request handler. The cap check
     * and nonce verification still live on the public entry point.
     *
     * Two refund modes are supported:
     *
     * 1. Line-item refund — when the form posts `refund_items[<order_item_id>]`
     *    rows with non-zero quantity or amount, we route through
     *    {@see Order_Manager::process_partial_refund()} so each line is
     *    validated against its original quantity and the items[] payload
     *    persists on the refund row (driving per-line restock and the
     *    timeline). Shipping is added as a synthetic line on top.
     * 2. Amount-only refund — the legacy path, kept for backward
     *    compatibility with sibling gateways and the REST API.
     *
     * @param array $post Already-unslashed POST payload.
     * @return int 1 on success, 0 on failure.
     */
    public function process_refund_post( array $post ): int {
        $order_id    = isset( $post['order_id'] ) ? absint( $post['order_id'] ) : 0;
        $reason_slug = isset( $post['refund_reason_select'] ) ? sanitize_key( (string) $post['refund_reason_select'] ) : '';
        $reason_text = isset( $post['refund_reason'] ) ? sanitize_text_field( (string) $post['refund_reason'] ) : '';
        $reason      = self::compose_refund_reason( $reason_slug, $reason_text );
        $set_status  = ! empty( $post['refund_set_status'] );
        $notify      = ! empty( $post['refund_notify_customer'] );

        if ( ! $order_id ) {
            return 0;
        }

        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            return 0;
        }

        // F-PCA-001: All refund money comparisons use integer minor units so
        // floating-point imprecision cannot produce a spurious "over-refund"
        // rejection. Currency::to_minor_units() handles JPY (×1), standard
        // (×100) and three-decimal currencies (×1000) correctly.
        $currency        = (string) $order->get_currency();
        $multiplier      = Currency::multiplier( $currency );
        $remaining_minor = Currency::to_minor_units( $order->get_total(), $currency )
            - Currency::to_minor_units( Order_Refund::get_total_refunded( $order_id ), $currency );

        if ( $remaining_minor <= 0 ) {
            return $this->fail_refund_post(
                $order,
                __( 'This order has already been fully refunded.', 'tejcart' )
            );
        }

        $line_items           = self::collect_refund_line_items( $post );
        $shipping_amount_str  = isset( $post['refund_shipping_amount'] ) ? (string) $post['refund_shipping_amount'] : '0';
        $shipping_minor       = max( 0, Currency::to_minor_units( $shipping_amount_str, $currency ) );
        $tax_amount_str       = isset( $post['refund_tax_amount'] ) ? (string) $post['refund_tax_amount'] : '0';
        $tax_minor            = max( 0, Currency::to_minor_units( $tax_amount_str, $currency ) );
        $amount_str           = isset( $post['refund_amount'] ) ? (string) $post['refund_amount'] : '0';
        $amount_minor         = Currency::to_minor_units( $amount_str, $currency );

        if ( ! empty( $line_items ) || $shipping_minor > 0 || $tax_minor > 0 ) {
            // Synthetic line for the shipping refund — uses order_item_id 0
            // so process_partial_refund's validation passes it through;
            // downstream consumers ignore zero-id lines for stock restoration
            // but the amount is still summed into the refund total. Real line
            // items always have a positive id.
            if ( $shipping_minor > 0 ) {
                $line_items[] = array(
                    'order_item_id' => 0,
                    'quantity'      => 0,
                    // Keep the amount field as a decimal string so downstream
                    // consumers that read 'amount' as a display value still work.
                    'amount'        => round( $shipping_minor / $multiplier, Currency::decimals( $currency ) ),
                );
            }

            // Synthetic line for the tax refund — uses order_item_id -1 so the
            // merchant controls exactly how much tax is returned. When this
            // line is present we tell process_partial_refund() not to also
            // auto-add proportional tax (see the $auto_tax = false below),
            // otherwise the tax would be counted twice.
            if ( $tax_minor > 0 ) {
                $line_items[] = array(
                    'order_item_id' => -1,
                    'quantity'      => 0,
                    'amount'        => round( $tax_minor / $multiplier, Currency::decimals( $currency ) ),
                );
            }

            $line_total_minor = 0;
            foreach ( $line_items as $row ) {
                $line_total_minor += Currency::to_minor_units( (string) $row['amount'], $currency );
            }
            if ( $line_total_minor <= 0 ) {
                return $this->fail_refund_post(
                    $order,
                    __( 'No refund amount was specified for the selected items.', 'tejcart' )
                );
            }
            // Integer comparison — no epsilon guard needed.
            if ( $line_total_minor > $remaining_minor ) {
                // A stale page could submit numbers that no longer fit after a
                // concurrent webhook refund. Reject with an explicit reason
                // rather than capping silently, so the merchant understands
                // why nothing was refunded.
                return $this->fail_refund_post(
                    $order,
                    __( 'The selected items exceed the order’s remaining refundable balance. Reload the order and try again.', 'tejcart' )
                );
            }

            // $auto_tax = false: tax is supplied explicitly via the synthetic
            // tax line above (or deliberately omitted), so the manager must not
            // add its own proportional tax on top.
            $result = $this->order_manager()->process_partial_refund( $order_id, $line_items, $reason, false );

            // process_partial_refund returns a WP_Error describing exactly why
            // it failed; surface that reason instead of discarding it.
            if ( is_wp_error( $result ) ) {
                return $this->fail_refund_post( $order, $result->get_error_message() );
            }

            $ok = ( true === $result );
            if ( ! $ok ) {
                return $this->fail_refund_post(
                    $order,
                    __( 'The refund could not be completed. Check the TejCart log for details.', 'tejcart' )
                );
            }

            // NOTE: do not flip the status to "refunded" here. When a refund
            // fully drains the order, process_partial_refund() has already made
            // that transition on its own (fresh) Order instance. Repeating it
            // from this handler's now-stale Order would lose the compare-and-
            // swap and log a misleading "status transition discarded —
            // concurrent writer" note for what is really the same request. The
            // $set_status toggle never gated this in line-item mode anyway, as
            // the manager always flips a fully-refunded order.

            // Convert back to display-unit float only for the action hook
            // payload (existing consumers expect a numeric amount).
            $line_total_display = $line_total_minor / $multiplier;
            if ( $ok && $notify ) {
                /** This duplicates the action below for the line-item path. */
                do_action( 'tejcart_refund_customer_notify', $order_id, $line_total_display, $reason, $order );
            }

            return $ok ? 1 : 0;
        }

        if ( $amount_minor <= 0 ) {
            return $this->fail_refund_post(
                $order,
                __( 'Enter a refund amount greater than zero.', 'tejcart' )
            );
        }

        if ( $amount_minor > $remaining_minor ) {
            $amount_minor = $remaining_minor;
        }

        if ( $amount_minor <= 0 ) {
            return 0;
        }

        // Convert back to display-unit float for the Order_Manager API
        // and the notification hook, both of which accept a float amount.
        $amount = $amount_minor / $multiplier;

        // Route through Order_Manager so the gateway's process_refund()
        // actually runs (PayPal capture refund, Stripe refund, etc.)
        // instead of merely recording a local row. The "Mark Refunded"
        // toggle gates the automatic order status flip.
        $ok = $this->order_manager()->process_refund( $order_id, $amount, $reason, $set_status );

        if ( $ok && $notify ) {
            /**
             * Fires after a successful admin-initiated refund when the
             * "Notify customer" toggle was on. Listeners (typically the
             * email subsystem) should send the buyer-facing refund
             * notification. The default TejCart email layer treats this
             * as the trigger for the customer "Refund Issued" template.
             *
             * @since 1.x.0
             *
             * @param int                  $order_id Order ID.
             * @param float                $amount   Refund amount.
             * @param string               $reason   Composed reason string.
             * @param \TejCart\Order\Order $order    The order object.
             */
            do_action( 'tejcart_refund_customer_notify', $order_id, $amount, $reason, $order );
        }

        return $ok ? 1 : 0;
    }

    /**
     * Record an admin-side refund failure as a log line and an order note.
     *
     * These validation branches return 0, which renders the "Refund failed …
     * check the order notes and the TejCart log" admin notice. For that
     * guidance to be true they must leave a trail in both places, rather than
     * failing silently as they did before.
     *
     * @param Order  $order   The order being refunded.
     * @param string $message Human-readable reason the refund was not processed.
     * @return int Always 0, so callers can `return $this->fail_refund_post( … );`.
     */
    private function fail_refund_post( Order $order, string $message ): int {
        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'Admin refund on order %d not processed: %s', $order->get_id(), $message ),
                'warning'
            );
        }

        $order->add_note(
            sprintf(
                /* translators: %s: reason the refund was not processed. */
                __( 'Refund not processed: %s', 'tejcart' ),
                $message
            )
        );

        return 0;
    }

    /**
     * Pull line-item refund rows out of the posted form.
     *
     * The refund table renders one hidden + visible input set per order
     * item, keyed by order_item_id. Rows with zero quantity AND zero
     * amount are skipped so an admin can use the form for a partial /
     * mixed refund (a few lines + free shipping, etc.) without us
     * synthesising empty refund_items entries.
     *
     * @param array<string,mixed> $post Already-unslashed POST payload.
     * @return array<int,array{order_item_id:int,quantity:int,amount:float}>
     */
    public static function collect_refund_line_items( array $post ): array {
        if ( empty( $post['refund_items'] ) || ! is_array( $post['refund_items'] ) ) {
            return array();
        }

        $items = array();
        foreach ( $post['refund_items'] as $row_id => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $item_id = absint( $row_id );
            if ( ! $item_id ) {
                continue;
            }

            $qty    = isset( $row['quantity'] ) ? max( 0, (int) $row['quantity'] ) : 0;
            $amount = isset( $row['amount'] ) ? max( 0.0, (float) $row['amount'] ) : 0.0;

            if ( $qty <= 0 && $amount <= 0.0 ) {
                continue;
            }

            $items[] = array(
                'order_item_id' => $item_id,
                'quantity'      => $qty,
                'amount'        => round( $amount, 2 ),
            );
        }

        return $items;
    }

    /**
     * Combine the dropdown slug and free-text input into a single human
     * reason string for the order note + downstream gateway. Both are kept
     * together so an admin who picks "Customer requested" *and* types extra
     * context still gets both persisted.
     *
     * @param string $slug Sanitised slug from the dropdown ('' if none).
     * @param string $text Sanitised free-text input.
     * @return string Composed reason; empty when both inputs are empty.
     */
    public static function compose_refund_reason( string $slug, string $text ): string {
        $labels = array(
            'requested_by_customer' => __( 'Customer requested', 'tejcart' ),
            'duplicate'             => __( 'Duplicate transaction', 'tejcart' ),
            'fraudulent'            => __( 'Fraudulent', 'tejcart' ),
            'item_unavailable'      => __( 'Item out of stock / unavailable', 'tejcart' ),
            'shipping_issue'        => __( 'Shipping issue', 'tejcart' ),
            'damaged'               => __( 'Item damaged or defective', 'tejcart' ),
            'other'                 => '',
        );

        $label = isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';
        $text  = trim( $text );

        if ( '' !== $label && '' !== $text ) {
            return $label . ' — ' . $text;
        }
        if ( '' !== $label ) {
            return $label;
        }
        return $text;
    }

    /**
     * Explain why the refund form is hidden for an order that has no
     * captured funds to return.
     *
     * Shown in place of the refund form for non-refundable statuses
     * (see {@see Order_Status::is_refundable()}). The message is tailored
     * per status so the merchant understands that nothing was collected
     * yet — a PayPal order sitting in `pending` was never captured, so a
     * refund would only earn a gateway rejection.
     *
     * @param string $status Order status slug.
     * @return string Human-readable explanation.
     */
    private static function refund_unavailable_message( string $status ): string {
        switch ( $status ) {
            case Order_Status::PENDING:
                return __( 'This order is awaiting payment, so there is nothing to refund yet. Once the payment is captured the refund tools will appear here.', 'tejcart' );
            case Order_Status::ON_HOLD:
                return __( 'This order is on hold and has no captured payment to refund. Move it to Processing once payment is confirmed to enable refunds.', 'tejcart' );
            case Order_Status::FAILED:
                return __( 'This order\'s payment failed, so no funds were captured and there is nothing to refund.', 'tejcart' );
            case Order_Status::CANCELLED:
                return __( 'This order was cancelled and has no captured payment to refund.', 'tejcart' );
            default:
                return __( 'This order has no captured payment to refund.', 'tejcart' );
        }
    }

    /**
     * Build a fresh order from posted form data.
     *
     * Routes through `Order_Factory::create()` so the manual-order path
     * fires the same `tejcart_new_order` and `tejcart_order_created`
     * actions every other entry point does — without this, Customer_Sync
     * never updates the customer's order count / lifetime total,
     * Outgoing_Webhooks never notifies third-party CRMs, the
     * abandoned-cart marker never clears, the currency-switcher
     * per-order context is missed, and the analytics dispatcher (GA4 /
     * Meta CAPI / Klaviyo) never sees the order. Also gains
     * Order_Factory's variation-belongs-to-parent integrity check on
     * line items.
     *
     * After the order is created we fire
     * `tejcart_checkout_order_processed` so the Subscriptions
     * Checkout_Integration creates subscription rows for any
     * Subscription_Product line item; an admin building a recurring
     * order by hand previously got an order with no subscription
     * record.
     *
     * @param array $post Raw POST array (already unslashed by caller).
     * @return int New order ID, or 0 on failure.
     */
    private function create_manual_order( array $post ): int {
        $email = isset( $post['customer_email'] ) ? sanitize_email( $post['customer_email'] ) : '';
        if ( ! $email ) {
            return 0;
        }

        $items_input = isset( $post['items'] ) && is_array( $post['items'] ) ? $post['items'] : array();
        if ( empty( $items_input ) ) {
            return 0;
        }

        $billing = array(
            'first_name' => sanitize_text_field( $post['billing_first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $post['billing_last_name'] ?? '' ),
            'address_1'  => sanitize_text_field( $post['billing_address_1'] ?? '' ),
            'city'       => sanitize_text_field( $post['billing_city'] ?? '' ),
            'state'      => sanitize_text_field( $post['billing_state'] ?? '' ),
            'postcode'   => sanitize_text_field( $post['billing_postcode'] ?? '' ),
            'country'    => sanitize_text_field( $post['billing_country'] ?? '' ),
            'phone'      => sanitize_text_field( $post['billing_phone'] ?? '' ),
        );

        // F-PCA-001: Accumulate all money in integer minor units so multi-
        // item totals never drift from float imprecision. Convert back to
        // a display-unit decimal string only when calling Order_Factory::create().
        // F-PCA-010: Skip any line item whose resolved unit price is <= 0 to
        // prevent $0.00 orders and confusing stock/revenue data.
        $shop_currency   = (string) get_option( 'tejcart_currency', 'USD' );
        $multiplier      = Currency::multiplier( $shop_currency );
        $subtotal_minor  = 0;
        $line_items      = array();

        foreach ( $items_input as $row ) {
            $product_id = absint( $row['product_id'] ?? 0 );
            $qty        = max( 1, (int) ( $row['quantity'] ?? 1 ) );
            if ( ! $product_id ) {
                continue;
            }

            $product = Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $unit_price_str = isset( $row['unit_price'] ) && '' !== $row['unit_price']
                ? (string) $row['unit_price']
                : (string) $product->get_price();

            $unit_price_minor = Currency::to_minor_units( $unit_price_str, $shop_currency );

            // F-PCA-010: Reject zero-or-negative unit prices.
            if ( $unit_price_minor <= 0 ) {
                continue;
            }

            $line_total_minor  = $unit_price_minor * $qty;
            $subtotal_minor   += $line_total_minor;

            // Store display-unit floats in line_items so Order_Factory
            // and downstream hooks receive the values they expect.
            $unit_price_display  = $unit_price_minor / $multiplier;
            $line_total_display  = $line_total_minor / $multiplier;

            $line_items[] = array(
                'product_id'   => $product_id,
                'product_name' => method_exists( $product, 'get_name' ) ? $product->get_name() : '',
                'quantity'     => $qty,
                'unit_price'   => $unit_price_display,
                'line_total'   => $line_total_display,
            );
        }

        if ( empty( $line_items ) ) {
            return 0;
        }

        $shipping_minor  = max( 0, Currency::to_minor_units( (string) ( $post['shipping_total'] ?? '0' ), $shop_currency ) );
        $tax_minor       = max( 0, Currency::to_minor_units( (string) ( $post['tax_total'] ?? '0' ), $shop_currency ) );
        $total_minor     = $subtotal_minor + $shipping_minor + $tax_minor;

        // Convert back to display-unit floats for Order_Factory::create().
        $subtotal = $subtotal_minor / $multiplier;
        $shipping = $shipping_minor / $multiplier;
        $tax      = $tax_minor     / $multiplier;
        $total    = $total_minor   / $multiplier;

        $order = \TejCart\Order\Order_Factory::create( array(
            'status'           => 'pending',
            'currency'         => $shop_currency,
            'payment_method'   => sanitize_text_field( $post['payment_method'] ?? 'manual' ),
            'customer_email'   => $email,
            'customer_name'    => sanitize_text_field( $post['customer_name'] ?? '' ),
            'billing_address'  => wp_json_encode( $billing ),
            'shipping_address' => wp_json_encode( $billing ),
            'subtotal'         => $subtotal,
            'discount_total'   => 0.0,
            'shipping_total'   => $shipping,
            'tax_total'        => $tax,
            'total'            => $total,
            'items'            => $line_items,
        ) );

        if ( is_wp_error( $order ) || ! $order ) {
            return 0;
        }

        $order_id = (int) $order->get_id();
        if ( ! $order_id ) {
            return 0;
        }

        $order->add_note( __( 'Order created manually from the admin.', 'tejcart' ) );

        // Fire the same post-order processed action the canonical
        // Checkout::process() pipeline does. Subscriptions
        // Checkout_Integration listens here to spin up a Subscription
        // row for each Subscription_Product line item.
        do_action( 'tejcart_checkout_order_processed', $order_id, array() );

        return $order_id;
    }

    private function render_view( Order $order ): void {
        $order_id        = (int) $order->get_id();
        $items           = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
        $refunds         = Order_Refund::get_refunds( $order_id );
        $total_refunded  = Order_Refund::get_total_refunded( $order_id );
        $order_total     = (float) $order->get_total();
        $remaining       = max( 0.0, $order_total - $total_refunded );
        $invoice_url     = Invoice::get_url( $order );
        $shipping_method = method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_shipping_method' ) : '';
        $status          = $order->get_status();
        $notes           = method_exists( $order, 'get_notes' ) ? $order->get_notes() : array();
        // Treat the order as partially refunded only while it still has open
        // funds and at least one refund row exists. Floating-point slop is
        // absorbed by the same epsilon Order_Manager uses elsewhere.
        $is_partially_refunded = ( $total_refunded > 0.0 )
            && ( $remaining > 0.0001 )
            && ( 'refunded' !== $status );

        // Resolve the live gateway title once so the refund button reads
        // "Refund $X via PayPal" and the meta strip says "Paid via PayPal"
        // (proper-cased) instead of falling through to the slug-derived
        // "TejCart paypal".
        $gateway_id    = $order->get_payment_method();
        $gateway_title = '';
        if ( $gateway_id && function_exists( 'tejcart' ) ) {
            $gw = tejcart()->gateways()->get_gateway( $gateway_id );
            if ( $gw && method_exists( $gw, 'get_title' ) ) {
                $gateway_title = (string) $gw->get_title();
            }
        }
        // Fallback to the order's stored title if the gateway is unavailable
        // (e.g. plugin deactivated post-purchase) so the button still reads
        // sensibly.
        if ( '' === $gateway_title && method_exists( $order, 'get_payment_method_title' ) ) {
            $gateway_title = (string) $order->get_payment_method_title();
        }

        $created_at      = method_exists( $order, 'get_created_at' ) ? (string) $order->get_created_at() : '';
        $created_label   = '' !== $created_at
            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $created_at ) )
            : '';
        $created_iso     = '' !== $created_at ? gmdate( 'c', (int) strtotime( $created_at ) ) : '';
        $currency_code   = (string) $order->get_currency();
        $ip_address      = method_exists( $order, 'get_ip_address' ) ? (string) $order->get_ip_address() : '';
        // Order::get_payment_method_title() is the single source of truth: it
        // surfaces the actual wallet the buyer used (Google Pay / Apple Pay /
        // Venmo) from the `_paypal_funding_source` meta, then falls back to the
        // live gateway title, then a properly-titled slug. Prefer it over the
        // raw gateway title ($gateway_title) so the "Paid via" strip reads
        // "Google Pay" rather than the generic "PayPal" for wallet captures.
        // ($gateway_title is still used for the "Refund $X via PayPal" button,
        // where the processor — PayPal — is the correct label.)
        $payment_title = method_exists( $order, 'get_payment_method_title' ) && '' !== (string) $order->get_payment_method_title()
            ? (string) $order->get_payment_method_title()
            : $gateway_title;

        $paypal_capture_id  = method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_paypal_capture_id' ) : '';
        $paypal_environment = method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_paypal_environment' ) : '';
        if ( '' !== $paypal_capture_id && '' === $paypal_environment ) {
            // Orders captured before the environment flag was recorded fall
            // back to the live host so the link still resolves on production
            // sites; sandbox merchants can re-capture or correct via meta.
            $paypal_environment = 'live';
        }
        $paypal_transaction_url = ( '' !== $paypal_capture_id )
            ? PayPal_Gateway::get_transaction_url( $paypal_capture_id, $paypal_environment )
            : '';
        ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Orders', 'tejcart' ); ?></a>
                    <h1>
                        <?php
                        /* translators: %s: order number. */
                        printf( esc_html__( 'Order %s', 'tejcart' ), esc_html( $order->get_order_number() ) );
                        ?>
                        <span class="tejcart-status-badge tejcart-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?></span>
                        <?php if ( $is_partially_refunded ) : ?>
                            <span class="tejcart-status-badge tejcart-status-partially-refunded" title="<?php
                                /* translators: 1: refunded amount, 2: original total. */
                                printf( esc_attr__( '%1$s of %2$s refunded.', 'tejcart' ), esc_attr( wp_strip_all_tags( (string) tejcart_price( $total_refunded, $currency_code ) ) ), esc_attr( wp_strip_all_tags( (string) tejcart_price( $order_total, $currency_code ) ) ) );
                            ?>"><?php esc_html_e( 'Partially refunded', 'tejcart' ); ?></span>
                        <?php endif; ?>
                    </h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Review items, process refunds and manage order details.', 'tejcart' ); ?></p>
                    <ul class="tejcart-order-meta-strip" aria-label="<?php esc_attr_e( 'Order metadata', 'tejcart' ); ?>">
                        <?php if ( '' !== $created_label ) : ?>
                            <li>
                                <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Created', 'tejcart' ); ?></span>
                                <time datetime="<?php echo esc_attr( $created_iso ); ?>"><?php echo esc_html( $created_label ); ?></time>
                            </li>
                        <?php endif; ?>
                        <?php if ( '' !== $currency_code ) : ?>
                            <li>
                                <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Currency', 'tejcart' ); ?></span>
                                <code><?php echo esc_html( $currency_code ); ?></code>
                            </li>
                        <?php endif; ?>
                        <?php if ( '' !== $payment_title ) : ?>
                            <li class="tejcart-order-meta-payment">
                                <span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Paid via', 'tejcart' ); ?></span>
                                <span class="tejcart-order-meta-value"><?php echo esc_html( $payment_title ); ?></span>
                                <?php if ( '' !== $paypal_capture_id ) : ?>
                                    <span class="tejcart-order-meta-sep" aria-hidden="true">·</span>
                                    <?php if ( '' !== $paypal_transaction_url ) : ?>
                                        <a href="<?php echo esc_url( $paypal_transaction_url ); ?>" target="_blank" rel="noopener noreferrer" class="tejcart-order-meta-link" title="<?php esc_attr_e( 'Open in PayPal', 'tejcart' ); ?>">
                                            <code><?php echo esc_html( $paypal_capture_id ); ?></code>
                                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                        </a>
                                    <?php else : ?>
                                        <code><?php echo esc_html( $paypal_capture_id ); ?></code>
                                    <?php endif; ?>
                                    <span class="tejcart-status-badge tejcart-paypal-env-<?php echo esc_attr( $paypal_environment ); ?>">
                                        <?php echo esc_html( 'live' === $paypal_environment ? __( 'Live', 'tejcart' ) : __( 'Sandbox', 'tejcart' ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                        <?php if ( '' !== $ip_address ) : ?>
                            <li>
                                <span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Customer IP', 'tejcart' ); ?></span>
                                <code><?php echo esc_html( $ip_address ); ?></code>
                            </li>
                        <?php endif; ?>
                        <li class="tejcart-order-meta-copy">
                            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Order ID', 'tejcart' ); ?></span>
                            <code><?php echo esc_html( $order->get_order_number() ); ?></code>
                            <button type="button" class="button-link tejcart-copy" data-copy="<?php echo esc_attr( $order->get_order_number() ); ?>" title="<?php esc_attr_e( 'Copy order ID', 'tejcart' ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Copy order ID', 'tejcart' ); ?></span></button>
                        </li>
                        <?php
                        /**
                         * Fires inside the order header meta strip after the
                         * built-in metadata items (created, currency, payment
                         * method, IP, order ID).
                         *
                         * Listeners should emit `<li>` elements that match the
                         * surrounding visual rhythm (icon + label + value).
                         * Used by the Stripe / Authorize.Net / Subscriptions
                         * siblings to surface gateway-specific identifiers.
                         *
                         * @since 1.x.0
                         * @param \TejCart\Order\Order $order The order being viewed.
                         */
                        do_action( 'tejcart_admin_order_meta_strip', $order );
                        ?>
                    </ul>
                </div>
                <div class="tejcart-page-header-actions">
                    <a href="<?php echo esc_url( $invoice_url ); ?>" target="_blank" class="button"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'View Invoice', 'tejcart' ); ?></a>
                </div>
            </div>

            <?php if ( isset( $_GET['refunded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ( '1' === sanitize_text_field( wp_unslash( (string) $_GET['refunded'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Refund processed.', 'tejcart' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Refund failed. The payment gateway rejected the refund or the order is locked. Check the order notes and the TejCart log for details.', 'tejcart' ); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( isset( $_GET['created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order created.', 'tejcart' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['note_added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ( '1' === sanitize_text_field( wp_unslash( (string) $_GET['note_added'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order note added.', 'tejcart' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not add the order note. The note body cannot be empty.', 'tejcart' ); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( isset( $_GET['invoice_sent'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ( '1' === sanitize_text_field( wp_unslash( (string) $_GET['invoice_sent'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Invoice email sent to the customer.', 'tejcart' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not send the invoice email. Check the TejCart log for details.', 'tejcart' ); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="tejcart-detail-grid">
                <!-- MAIN COLUMN -->
                <div class="tejcart-detail-main">

                    <!-- Items card -->
                    <?php
                    $item_unit_count = 0;
                    foreach ( $items as $it ) {
                        $item_unit_count += (int) ( $it->quantity ?? 0 );
                    }
                    ?>
                    <div class="tejcart-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Items', 'tejcart' ); ?></h3>
                            <?php if ( $item_unit_count > 0 ) : ?>
                                <span class="tejcart-card-summary">
                                    <?php
                                    printf(
                                        esc_html(
                                            /* translators: 1: total unit count, 2: distinct line count. */
                                            _n( '%1$s unit across %2$s line', '%1$s units across %2$s lines', $item_unit_count, 'tejcart' )
                                        ),
                                        esc_html( number_format_i18n( $item_unit_count ) ),
                                        esc_html( number_format_i18n( count( $items ) ) )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <table class="wp-list-table widefat tejcart-order-items-table">
                            <thead><tr>
                                <th class="column-thumb" aria-label="<?php esc_attr_e( 'Image', 'tejcart' ); ?>"></th>
                                <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                                <th class="column-num"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                                <th class="column-num"><?php esc_html_e( 'Unit Price', 'tejcart' ); ?></th>
                                <th class="column-num"><?php esc_html_e( 'Line Total', 'tejcart' ); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php if ( empty( $items ) ) : ?>
                                <tr><td colspan="5"><?php esc_html_e( 'No items found for this order.', 'tejcart' ); ?></td></tr>
                            <?php else : ?>
                                <?php
                                $order_currency = $order->get_currency();
                                // Warm every line item's product (and its meta
                                // bucket) in a single batched query so the
                                // per-row Product_Factory::get_product() below
                                // hits the request cache instead of issuing one
                                // SELECT per line item (1+N on large orders).
                                $item_product_ids = array();
                                foreach ( $items as $warm_item ) {
                                    $warm_pid = isset( $warm_item->product_id ) ? (int) $warm_item->product_id : 0;
                                    if ( $warm_pid > 0 ) {
                                        $item_product_ids[] = $warm_pid;
                                    }
                                }
                                if ( ! empty( $item_product_ids ) && method_exists( Product_Factory::class, 'get_products' ) ) {
                                    Product_Factory::get_products( array_values( array_unique( $item_product_ids ) ) );
                                }
                                foreach ( $items as $item ) :
                                    $name      = $item->product_name ?? '';
                                    $qty       = (int) ( $item->quantity ?? 0 );
                                    $unit      = (float) ( $item->unit_price ?? 0 );
                                    // line_total is BIGINT minor units in the
                                    // order's currency — convert for display.
                                    $total     = \TejCart\Money\Currency::from_minor_units( (int) ( $item->line_total ?? 0 ), $order_currency );
                                    $product_id = isset( $item->product_id ) ? (int) $item->product_id : 0;
                                    $sku        = '';
                                    $thumb_html = '';
                                    $product    = $product_id ? Product_Factory::get_product( $product_id ) : null;
                                    if ( $product ) {
                                        if ( method_exists( $product, 'get_sku' ) ) {
                                            $sku = (string) $product->get_sku();
                                        }
                                        if ( method_exists( $product, 'get_image_id' ) ) {
                                            $img_id = (int) $product->get_image_id();
                                            if ( $img_id && function_exists( 'wp_get_attachment_image' ) ) {
                                                $thumb_html = wp_get_attachment_image( $img_id, array( 48, 48 ), false, array( 'class' => 'tejcart-item-thumb', 'alt' => '' ) );
                                            }
                                        }
                                    }
                                    if ( '' === $thumb_html ) {
                                        $thumb_html = '<span class="tejcart-item-thumb tejcart-item-thumb--placeholder" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>';
                                    }

                                    $variation_meta = array();
                                    if ( isset( $item->meta ) && '' !== (string) $item->meta ) {
                                        $decoded = json_decode( (string) $item->meta, true );
                                        if ( is_array( $decoded ) ) {
                                            foreach ( $decoded as $mk => $mv ) {
                                                if ( is_scalar( $mv ) && '' !== (string) $mv && '_' !== substr( (string) $mk, 0, 1 ) ) {
                                                    $variation_meta[ (string) $mk ] = (string) $mv;
                                                }
                                            }
                                        }
                                    }
                                    $edit_url = $product_id ? admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . $product_id ) : '';
                                    ?>
                                    <tr>
                                        <td class="column-thumb"><?php echo wp_kses_post( $thumb_html ); ?></td>
                                        <td>
                                            <?php if ( $edit_url ) : ?>
                                                <a class="tejcart-item-name" href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $name ); ?></strong></a>
                                            <?php else : ?>
                                                <strong><?php echo esc_html( $name ); ?></strong>
                                            <?php endif; ?>
                                            <div class="tejcart-item-meta">
                                                <?php if ( '' !== $sku ) : ?>
                                                    <span class="tejcart-item-meta__sku"><?php
                                                    /* translators: %s: product SKU. */
                                                    printf( esc_html__( 'SKU: %s', 'tejcart' ), '<code>' . esc_html( $sku ) . '</code>' );
                                                    ?></span>
                                                <?php endif; ?>
                                                <?php foreach ( $variation_meta as $k => $v ) : ?>
                                                    <span class="tejcart-item-meta__attr"><?php echo esc_html( ucwords( str_replace( array( '-', '_', 'pa ' ), array( ' ', ' ', '' ), $k ) ) ); ?>: <strong><?php echo esc_html( $v ); ?></strong></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="column-num"><?php echo (int) $qty; ?></td>
                                        <td class="column-num"><?php echo wp_kses_post( tejcart_price( $unit, $currency_code ) ); ?></td>
                                        <td class="column-num"><?php echo wp_kses_post( tejcart_price( $total, $currency_code ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                        <div class="tejcart-card-body">
                            <?php
                            $discount_total = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
                            $coupon_code    = method_exists( $order, 'get_coupon_code' ) ? (string) $order->get_coupon_code() : '';
                            ?>
                            <div class="tejcart-totals">
                                <div class="tejcart-totals-row">
                                    <span><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></span>
                                    <strong><?php echo wp_kses_post( tejcart_price( $order->get_subtotal(), $currency_code ) ); ?></strong>
                                </div>
                                <?php if ( $discount_total > 0 ) : ?>
                                    <div class="tejcart-totals-row is-discount">
                                        <span>
                                            <?php esc_html_e( 'Discount', 'tejcart' ); ?>
                                            <?php if ( '' !== $coupon_code ) : ?>
                                                <code class="tejcart-coupon-tag"><?php echo esc_html( $coupon_code ); ?></code>
                                            <?php endif; ?>
                                        </span>
                                        <strong>-<?php echo wp_kses_post( tejcart_price( $discount_total, $currency_code ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $order->get_shipping_total() > 0 ) : ?>
                                    <div class="tejcart-totals-row">
                                        <span>
                                            <?php esc_html_e( 'Shipping', 'tejcart' ); ?>
                                            <?php if ( $shipping_method ) : ?>
                                                <small class="tejcart-totals-sub"><?php echo esc_html( $shipping_method ); ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <strong><?php echo wp_kses_post( tejcart_price( $order->get_shipping_total(), $currency_code ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $order->get_tax_total() > 0 ) : ?>
                                    <div class="tejcart-totals-row">
                                        <span><?php esc_html_e( 'Tax', 'tejcart' ); ?></span>
                                        <strong><?php echo wp_kses_post( tejcart_price( $order->get_tax_total(), $currency_code ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php
                                // Cart-level fees (gift wrap, …) folded into the
                                // order total; itemised from the order's meta so
                                // the breakdown adds up to Total.
                                foreach ( tejcart_get_order_fee_lines( $order ) as $tejcart_admin_fee ) :
                                    ?>
                                    <div class="tejcart-totals-row">
                                        <span><?php echo esc_html( $tejcart_admin_fee['label'] ); ?></span>
                                        <strong><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $tejcart_admin_fee['amount'], $currency_code ) ); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <div class="tejcart-totals-row is-grand">
                                    <span><?php esc_html_e( 'Total', 'tejcart' ); ?></span>
                                    <strong><?php echo wp_kses_post( tejcart_price( $order->get_total(), $currency_code ) ); ?></strong>
                                </div>
                                <?php if ( $total_refunded > 0 ) : ?>
                                    <div class="tejcart-totals-row is-refund">
                                        <span><?php esc_html_e( 'Refunded', 'tejcart' ); ?></span>
                                        <strong>-<?php echo wp_kses_post( tejcart_price( $total_refunded, $currency_code ) ); ?></strong>
                                    </div>
                                    <div class="tejcart-totals-row is-net">
                                        <span><?php echo $remaining > 0.0001 ? esc_html__( 'Remaining', 'tejcart' ) : esc_html__( 'Net paid', 'tejcart' ); ?></span>
                                        <strong><?php echo wp_kses_post( tejcart_price( $remaining, $currency_code ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Refund card -->
                    <?php $refund_inconsistent = (bool) $order->get_meta( '_tejcart_refund_inconsistent' ); ?>
                    <div class="tejcart-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Issue Refund', 'tejcart' ); ?></h3>
                        </div>
                        <div class="tejcart-card-body">
                        <?php if ( $refund_inconsistent ) : ?>
                            <div class="notice notice-error inline" style="margin:0 0 12px;">
                                <p>
                                    <strong><?php esc_html_e( 'Refund inconsistency detected.', 'tejcart' ); ?></strong>
                                    <?php esc_html_e( 'A previous gateway refund succeeded but the local refund record did not persist. Verify the refund on the gateway dashboard before retrying.', 'tejcart' ); ?>
                                </p>
                                <p>
                                    <button
                                        type="button"
                                        class="button button-secondary tejcart-clear-refund-inconsistency"
                                        data-order-id="<?php echo esc_attr( $order_id ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'tejcart_clear_refund_inconsistency_' . $order_id ) ); ?>"
                                    >
                                        <?php esc_html_e( 'Clear inconsistency flag', 'tejcart' ); ?>
                                    </button>
                                </p>
                            </div>
                        <?php endif; ?>
                        <?php if ( $remaining <= 0 ) : ?>
                            <p><?php esc_html_e( 'This order has been fully refunded.', 'tejcart' ); ?></p>
                        <?php elseif ( ! Order_Status::is_refundable( $status ) ) : ?>
                            <p class="tejcart-refund-unavailable"><?php echo esc_html( self::refund_unavailable_message( $status ) ); ?></p>
                        <?php else : ?>
                            <?php
                            /**
                             * Filter the list of refund reasons offered in the
                             * admin reason dropdown. Maps slug => label. The
                             * slug is what gets persisted; "other" reveals a
                             * free-text input. Slugs `duplicate`, `fraudulent`
                             * and `requested_by_customer` are forwarded to
                             * Stripe as native enum values.
                             *
                             * @param array $reasons Default reason map.
                             */
                            $refund_reasons = apply_filters(
                                'tejcart_admin_refund_reasons',
                                array(
                                    'requested_by_customer' => __( 'Customer requested', 'tejcart' ),
                                    'duplicate'             => __( 'Duplicate transaction', 'tejcart' ),
                                    'fraudulent'            => __( 'Fraudulent', 'tejcart' ),
                                    'item_unavailable'      => __( 'Item out of stock / unavailable', 'tejcart' ),
                                    'shipping_issue'        => __( 'Shipping issue', 'tejcart' ),
                                    'damaged'               => __( 'Item damaged or defective', 'tejcart' ),
                                    'other'                 => __( 'Other (specify below)', 'tejcart' ),
                                )
                            );
                            $remaining_attr  = number_format( $remaining, 2, '.', '' );
                            $submit_label    = '' !== $gateway_title
                                ? sprintf(
                                    /* translators: 1: refund amount, 2: gateway display name. */
                                    __( 'Refund %1$s via %2$s', 'tejcart' ),
                                    wp_strip_all_tags( (string) tejcart_price( $remaining, $currency_code ) ),
                                    $gateway_title
                                )
                                : sprintf(
                                    /* translators: %s: refund amount. */
                                    __( 'Record refund of %s', 'tejcart' ),
                                    wp_strip_all_tags( (string) tejcart_price( $remaining, $currency_code ) )
                                );
                            ?>
                            <?php
                            // Cap each line's still-refundable quantity / amount by what
                            // earlier refunds for this order have already drawn down. Stored
                            // in the per-refund items[] payload as {order_item_id, quantity,
                            // amount}. We aggregate so the per-line UI never lets an admin
                            // refund more units / dollars than were actually charged.
                            $refunded_qty_by_item    = array();
                            $refunded_amount_by_item = array();
                            $refunded_shipping       = 0.0;
                            $refunded_tax            = 0.0;
                            foreach ( $refunds as $r ) {
                                if ( empty( $r->items ) || ! is_array( $r->items ) ) {
                                    continue;
                                }
                                foreach ( $r->items as $line ) {
                                    $iid = isset( $line['order_item_id'] ) ? (int) $line['order_item_id'] : 0;
                                    if ( $iid <= 0 ) {
                                        // Synthetic lines: 0 = shipping, -1 = tax. Track how
                                        // much of each was already returned so the per-row
                                        // caps below shrink across successive refunds.
                                        if ( 0 === $iid ) {
                                            $refunded_shipping += (float) ( $line['amount'] ?? 0 );
                                        } elseif ( -1 === $iid ) {
                                            $refunded_tax += (float) ( $line['amount'] ?? 0 );
                                        }
                                        continue;
                                    }
                                    $refunded_qty_by_item[ $iid ]    = ( $refunded_qty_by_item[ $iid ] ?? 0 ) + (int) ( $line['quantity'] ?? 0 );
                                    $refunded_amount_by_item[ $iid ] = ( $refunded_amount_by_item[ $iid ] ?? 0.0 ) + (float) ( $line['amount'] ?? 0 );
                                }
                            }
                            $shipping_total      = (float) $order->get_shipping_total();
                            $shipping_refundable = max( 0.0, $shipping_total - $refunded_shipping );
                            $tax_total           = (float) $order->get_tax_total();
                            $tax_refundable      = max( 0.0, $tax_total - $refunded_tax );
                            ?>
                            <form method="post" class="tejcart-refund-form" data-remaining="<?php echo esc_attr( $remaining_attr ); ?>" data-currency="<?php echo esc_attr( $order->get_currency() ); ?>" data-gateway-title="<?php echo esc_attr( $gateway_title ); ?>" data-order-subtotal="<?php echo esc_attr( number_format( (float) $order->get_subtotal(), 2, '.', '' ) ); ?>" data-order-tax="<?php echo esc_attr( number_format( (float) $order->get_tax_total(), 2, '.', '' ) ); ?>">
                                <?php wp_nonce_field( 'tejcart_refund_order', 'tejcart_refund_nonce' ); ?>
                                <input type="hidden" name="tejcart_refund_action" value="1" />
                                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />

                                <?php if ( ! empty( $items ) ) : ?>
                                    <p class="description tejcart-refund-mode-help"><?php esc_html_e( 'Tick the items you want to refund. Quantities and amounts can be adjusted per line; the total updates automatically. Leave all rows empty to issue an amount-only refund instead.', 'tejcart' ); ?></p>
                                    <table class="wp-list-table widefat tejcart-refund-line-table">
                                        <thead><tr>
                                            <th class="column-check"><input type="checkbox" class="tejcart-refund-line-toggle-all" aria-label="<?php esc_attr_e( 'Select all lines', 'tejcart' ); ?>" /></th>
                                            <th><?php esc_html_e( 'Item', 'tejcart' ); ?></th>
                                            <th class="column-num"><?php esc_html_e( 'Available', 'tejcart' ); ?></th>
                                            <th class="column-num"><?php esc_html_e( 'Refund qty', 'tejcart' ); ?></th>
                                            <th class="column-num"><?php esc_html_e( 'Refund amount', 'tejcart' ); ?></th>
                                            <th><?php esc_html_e( 'Restock', 'tejcart' ); ?></th>
                                        </tr></thead>
                                        <tbody>
                                        <?php
                                        $order_currency_refund = $order->get_currency();
                                        foreach ( $items as $item ) :
                                            $iid          = isset( $item->id ) ? (int) $item->id : 0;
                                            if ( ! $iid ) {
                                                continue;
                                            }
                                            $name         = (string) ( $item->product_name ?? '' );
                                            $orig_qty     = (int) ( $item->quantity ?? 0 );
                                            $unit         = (float) ( $item->unit_price ?? 0 );
                                            // line_total is BIGINT minor units in the order's currency.
                                            $line_total   = \TejCart\Money\Currency::from_minor_units( (int) ( $item->line_total ?? 0 ), $order_currency_refund );
                                            $already_qty  = (int) ( $refunded_qty_by_item[ $iid ] ?? 0 );
                                            $already_amt  = (float) ( $refunded_amount_by_item[ $iid ] ?? 0.0 );
                                            $avail_qty    = max( 0, $orig_qty - $already_qty );
                                            $avail_amount = max( 0.0, $line_total - $already_amt );
                                            $unit_step    = number_format( $unit > 0 ? $unit : 0.01, 2, '.', '' );
                                            $avail_attr   = number_format( $avail_amount, 2, '.', '' );
                                            ?>
                                            <tr class="tejcart-refund-line<?php echo $avail_qty <= 0 ? ' is-disabled' : ''; ?>" data-line-id="<?php echo esc_attr( $iid ); ?>" data-unit-price="<?php echo esc_attr( $unit_step ); ?>" data-available-qty="<?php echo esc_attr( $avail_qty ); ?>" data-available-amount="<?php echo esc_attr( $avail_attr ); ?>">
                                                <td class="column-check">
                                                    <input type="checkbox" class="tejcart-refund-line-check" <?php echo $avail_qty <= 0 ? 'disabled' : ''; ?> aria-label="<?php
                                                    /* translators: %s: product name. */
                                                    printf( esc_attr__( 'Refund line: %s', 'tejcart' ), esc_attr( $name ) );
                                                    ?>" />
                                                </td>
                                                <td>
                                                    <strong><?php echo esc_html( $name ); ?></strong>
                                                    <?php if ( $already_qty > 0 ) : ?>
                                                        <div class="description"><?php
                                                        /* translators: 1: refunded qty, 2: original qty. */
                                                        printf( esc_html__( '%1$d of %2$d already refunded.', 'tejcart' ), (int) $already_qty, (int) $orig_qty );
                                                        ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="column-num"><?php echo (int) $avail_qty; ?> / <?php echo (int) $orig_qty; ?></td>
                                                <td class="column-num">
                                                    <input type="number" min="0" step="1" max="<?php echo esc_attr( $avail_qty ); ?>" class="small-text tejcart-refund-line-qty" name="refund_items[<?php echo esc_attr( $iid ); ?>][quantity]" value="0" <?php echo $avail_qty <= 0 ? 'disabled' : ''; ?> />
                                                </td>
                                                <td class="column-num">
                                                    <input type="number" min="0" step="0.01" max="<?php echo esc_attr( $avail_attr ); ?>" class="tejcart-refund-line-amount" name="refund_items[<?php echo esc_attr( $iid ); ?>][amount]" value="0.00" <?php echo $avail_qty <= 0 ? 'disabled' : ''; ?> />
                                                </td>
                                                <td>
                                                    <label class="tejcart-mini-toggle">
                                                        <input type="checkbox" name="refund_items[<?php echo esc_attr( $iid ); ?>][restock]" value="1" class="tejcart-refund-line-restock" <?php echo $avail_qty <= 0 ? 'disabled' : ''; ?> />
                                                        <span><?php esc_html_e( 'Restock', 'tejcart' ); ?></span>
                                                    </label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ( $shipping_refundable > 0 ) : ?>
                                            <tr class="tejcart-refund-line tejcart-refund-line--shipping" data-shipping-max="<?php echo esc_attr( number_format( $shipping_refundable, 2, '.', '' ) ); ?>" data-available-amount="<?php echo esc_attr( number_format( $shipping_refundable, 2, '.', '' ) ); ?>">
                                                <td class="column-check">
                                                    <input type="checkbox" class="tejcart-refund-line-check tejcart-refund-shipping-check" aria-label="<?php esc_attr_e( 'Refund shipping', 'tejcart' ); ?>" />
                                                </td>
                                                <td>
                                                    <span class="dashicons dashicons-airplane tejcart-refund-line-icon" aria-hidden="true"></span>
                                                    <strong><?php esc_html_e( 'Shipping', 'tejcart' ); ?></strong>
                                                    <?php if ( $shipping_method ) : ?>
                                                        <div class="description"><?php echo esc_html( $shipping_method ); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="column-num" aria-hidden="true"></td>
                                                <td class="column-num" aria-hidden="true"></td>
                                                <td class="column-num">
                                                    <input type="number" min="0" step="0.01" max="<?php echo esc_attr( number_format( $shipping_refundable, 2, '.', '' ) ); ?>" class="tejcart-refund-shipping-amount" name="refund_shipping_amount" value="0.00" aria-label="<?php esc_attr_e( 'Shipping refund amount', 'tejcart' ); ?>" />
                                                </td>
                                                <td aria-hidden="true"></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php if ( $tax_refundable > 0 ) : ?>
                                            <tr class="tejcart-refund-line tejcart-refund-line--tax" data-tax-max="<?php echo esc_attr( number_format( $tax_refundable, 2, '.', '' ) ); ?>" data-available-amount="<?php echo esc_attr( number_format( $tax_refundable, 2, '.', '' ) ); ?>">
                                                <td class="column-check">
                                                    <input type="checkbox" class="tejcart-refund-line-check tejcart-refund-tax-check" aria-label="<?php esc_attr_e( 'Refund tax', 'tejcart' ); ?>" />
                                                </td>
                                                <td>
                                                    <span class="dashicons dashicons-money-alt tejcart-refund-line-icon" aria-hidden="true"></span>
                                                    <strong><?php esc_html_e( 'Tax', 'tejcart' ); ?></strong>
                                                    <div class="description"><?php
                                                    /* translators: %s: total tax charged on the order, formatted with currency. */
                                                    printf( esc_html__( 'Tax charged on this order: %s', 'tejcart' ), wp_kses_post( tejcart_price( $tax_total, $currency_code ) ) );
                                                    ?></div>
                                                </td>
                                                <td class="column-num" aria-hidden="true"></td>
                                                <td class="column-num" aria-hidden="true"></td>
                                                <td class="column-num">
                                                    <input type="number" min="0" step="0.01" max="<?php echo esc_attr( number_format( $tax_refundable, 2, '.', '' ) ); ?>" class="tejcart-refund-tax-amount" name="refund_tax_amount" value="0.00" aria-label="<?php esc_attr_e( 'Tax refund amount', 'tejcart' ); ?>" />
                                                </td>
                                                <td aria-hidden="true"></td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="4"></td>
                                                <td class="column-num"><?php esc_html_e( 'Lines total', 'tejcart' ); ?></td>
                                                <td><span class="tejcart-refund-line-total" data-currency="<?php echo esc_attr( $currency_code ); ?>">0.00</span></td>
                                            </tr>
                                            <tr>
                                                <td colspan="4"></td>
                                                <td class="column-num"><?php esc_html_e( 'Tax', 'tejcart' ); ?></td>
                                                <td><span class="tejcart-refund-tax-total" data-currency="<?php echo esc_attr( $currency_code ); ?>">0.00</span></td>
                                            </tr>
                                            <tr>
                                                <td colspan="4"></td>
                                                <td class="column-num"><strong><?php esc_html_e( 'Refund total', 'tejcart' ); ?></strong></td>
                                                <td><strong class="tejcart-refund-grand-total" data-currency="<?php echo esc_attr( $currency_code ); ?>">0.00</strong></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                <?php endif; ?>

                                <div class="tejcart-refund-fields">
                                    <div class="tejcart-refund-field">
                                        <label class="tejcart-refund-field-label" for="refund_amount"><?php esc_html_e( 'Amount', 'tejcart' ); ?></label>
                                        <div class="tejcart-refund-field-control">
                                            <div class="tejcart-refund-amount-input">
                                                <input type="number" step="0.01" min="0" max="<?php echo esc_attr( $remaining_attr ); ?>" id="refund_amount" name="refund_amount" value="<?php echo esc_attr( $remaining_attr ); ?>" />
                                                <button type="button" class="button button-small tejcart-refund-max" data-target="#refund_amount" title="<?php esc_attr_e( 'Fill in the maximum refundable amount', 'tejcart' ); ?>"><?php esc_html_e( 'Refund max', 'tejcart' ); ?></button>
                                            </div>
                                            <p class="description"><?php
                                            /* translators: %s: remaining refundable amount, formatted with currency. */
                                            printf( esc_html__( 'Used when no line items are selected. Up to %s remaining.', 'tejcart' ), wp_kses_post( tejcart_price( $remaining, $currency_code ) ) );
                                            ?></p>
                                        </div>
                                    </div>
                                    <div class="tejcart-refund-field">
                                        <label class="tejcart-refund-field-label" for="refund_reason_select"><?php esc_html_e( 'Reason', 'tejcart' ); ?></label>
                                        <div class="tejcart-refund-field-control">
                                            <select id="refund_reason_select" name="refund_reason_select" class="tejcart-refund-reason-select">
                                                <option value=""><?php esc_html_e( '— Select a reason (optional) —', 'tejcart' ); ?></option>
                                                <?php foreach ( $refund_reasons as $slug => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" id="refund_reason" name="refund_reason" class="regular-text tejcart-refund-reason-text" placeholder="<?php esc_attr_e( 'Optional details — visible in the order notes', 'tejcart' ); ?>" />
                                        </div>
                                    </div>
                                    <div class="tejcart-refund-field">
                                        <span class="tejcart-refund-field-label"><?php esc_html_e( 'Notify customer', 'tejcart' ); ?></span>
                                        <div class="tejcart-refund-field-control">
                                            <label class="tejcart-toggle">
                                                <input type="checkbox" name="refund_notify_customer" value="1" checked="checked" />
                                                <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                                <span class="tejcart-toggle-label"><?php esc_html_e( 'Email the customer that a refund has been issued', 'tejcart' ); ?></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="tejcart-refund-field">
                                        <span class="tejcart-refund-field-label"><?php esc_html_e( 'Mark refunded', 'tejcart' ); ?></span>
                                        <div class="tejcart-refund-field-control">
                                            <label class="tejcart-toggle">
                                                <input type="checkbox" name="refund_set_status" value="1" class="tejcart-refund-set-status" />
                                                <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                                <span class="tejcart-toggle-label"><?php esc_html_e( 'Mark order as refunded if this fully refunds the total', 'tejcart' ); ?></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="tejcart-form-actions">
                                    <button type="submit" name="submit" class="button button-primary tejcart-refund-submit"><?php echo esc_html( $submit_label ); ?></button>
                                </div>
                            </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( ! empty( $refunds ) ) : ?>
                        <div class="tejcart-card">
                            <div class="tejcart-card-header">
                                <h3><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Refund History', 'tejcart' ); ?></h3>
                                <span class="tejcart-card-summary">
                                    <?php
                                    printf(
                                        esc_html(
                                            /* translators: 1: total refunded amount with currency, 2: count of refund events. */
                                            _n( '%1$s refunded across %2$s event', '%1$s refunded across %2$s events', count( $refunds ), 'tejcart' )
                                        ),
                                        wp_kses_post( tejcart_price( $total_refunded, $currency_code ) ),
                                        esc_html( number_format_i18n( count( $refunds ) ) )
                                    );
                                    ?>
                                </span>
                            </div>
                            <table class="wp-list-table widefat striped tejcart-refund-history">
                                <thead><tr>
                                    <th><?php esc_html_e( 'Date', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Amount', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Gateway reference', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'By', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Reason', 'tejcart' ); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ( $refunds as $refund ) :
                                    $refund_date = '' !== $refund->date
                                        ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $refund->date ) )
                                        : '';
                                    $refund_iso  = '' !== $refund->date ? gmdate( 'c', (int) strtotime( $refund->date ) ) : '';
                                    $by_user     = '';
                                    if ( $refund->refunded_by ) {
                                        $u = get_userdata( (int) $refund->refunded_by );
                                        if ( $u ) {
                                            $by_user = $u->display_name;
                                        }
                                    }
                                    $ref_url = '';
                                    if ( '' !== $refund->transaction_ref && '' !== $paypal_capture_id && str_starts_with( $refund->transaction_ref, $paypal_capture_id ) === false ) {
                                        // PayPal refund IDs are not the capture ID; only link if the
                                        // gateway is PayPal so we don't manufacture wrong URLs for
                                        // other gateways. Sibling gateways can extend with the
                                        // `tejcart_admin_refund_ref_url` filter below.
                                        if ( 'tejcart_paypal' === $order->get_payment_method() || 'paypal' === $order->get_payment_method() ) {
                                            $ref_url = PayPal_Gateway::get_transaction_url( $refund->transaction_ref, $paypal_environment );
                                        }
                                    }
                                    /**
                                     * Filter the URL used to deep-link a refund's gateway reference
                                     * out to the gateway dashboard. Sibling gateways (Stripe,
                                     * Authorize.Net, …) can return their own URL here.
                                     *
                                     * @param string                       $url    Default URL ('' for no link).
                                     * @param \TejCart\Order\Order_Refund  $refund The refund row.
                                     * @param \TejCart\Order\Order         $order  The parent order.
                                     */
                                    $ref_url = (string) apply_filters( 'tejcart_admin_refund_ref_url', $ref_url, $refund, $order );
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ( $refund_date ) : ?>
                                                <time datetime="<?php echo esc_attr( $refund_iso ); ?>"><?php echo esc_html( $refund_date ); ?></time>
                                            <?php else : ?>
                                                <span class="tejcart-text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong>-<?php echo wp_kses_post( tejcart_price( $refund->amount, $currency_code ) ); ?></strong></td>
                                        <td>
                                            <?php if ( '' !== $refund->transaction_ref ) : ?>
                                                <?php if ( '' !== $ref_url ) : ?>
                                                    <a href="<?php echo esc_url( $ref_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                        <code><?php echo esc_html( $refund->transaction_ref ); ?></code>
                                                        <span class="dashicons dashicons-external"></span>
                                                    </a>
                                                <?php else : ?>
                                                    <code><?php echo esc_html( $refund->transaction_ref ); ?></code>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="tejcart-text-muted"><?php esc_html_e( 'Manual', 'tejcart' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $by_user ? esc_html( $by_user ) : '<span class="tejcart-text-muted">—</span>'; ?></td>
                                        <td><?php echo $refund->reason ? esc_html( $refund->reason ) : '<span class="tejcart-text-muted">—</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php
                    /**
                     * Fires inside the order edit screen main column, after
                     * the built-in items / refund / refund-history cards and
                     * before the order notes timeline. Sibling plugins (e.g.
                     * tejcart-returns, tejcart-disputes, tejcart-shipping
                     * label panel) use this to inject full-width cards
                     * without forking the admin template.
                     *
                     * Implementations should render a single
                     * `<div class="tejcart-card">…</div>` to match the
                     * surrounding visual rhythm.
                     *
                     * @since 1.x.0
                     * @param \TejCart\Order\Order $order The order being viewed.
                     */
                    do_action( 'tejcart_admin_order_after_main', $order );
                    ?>

                    <div class="tejcart-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Order Notes', 'tejcart' ); ?></h3>
                        </div>
                        <div class="tejcart-card-body">
                            <form method="post" class="tejcart-add-note-form">
                                <?php wp_nonce_field( 'tejcart_add_order_note', 'tejcart_add_note_nonce' ); ?>
                                <input type="hidden" name="tejcart_add_note_action" value="1" />
                                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />
                                <label for="order_note_content" class="screen-reader-text"><?php esc_html_e( 'Add a note to this order', 'tejcart' ); ?></label>
                                <textarea
                                    id="order_note_content"
                                    name="order_note_content"
                                    rows="3"
                                    class="large-text"
                                    placeholder="<?php esc_attr_e( 'Add a note about this order…', 'tejcart' ); ?>"
                                    required
                                ></textarea>
                                <div class="tejcart-add-note-controls">
                                    <label class="tejcart-add-note-customer">
                                        <input type="checkbox" name="order_note_customer" value="1" />
                                        <?php esc_html_e( 'Note to customer', 'tejcart' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Customer notes are visible to the buyer in their account; internal notes are not.', 'tejcart' ); ?></p>
                                    <?php submit_button( __( 'Add Note', 'tejcart' ), 'secondary', 'submit', false ); ?>
                                </div>
                            </form>

                            <?php
                            $timeline = $notes;
                            // Newest first to match standard activity-feed expectations.
                            $timeline = array_reverse( $timeline );
                            ?>
                            <?php if ( empty( $timeline ) ) : ?>
                                <p class="tejcart-notes-empty"><?php esc_html_e( 'No notes for this order yet.', 'tejcart' ); ?></p>
                            <?php else : ?>
                                <ol class="tejcart-notes-timeline" aria-label="<?php esc_attr_e( 'Order activity timeline', 'tejcart' ); ?>">
                                    <?php foreach ( $timeline as $note ) :
                                        $note_date = '' !== $note['date']
                                            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $note['date'] ) )
                                            : '';
                                        $note_iso  = '' !== $note['date'] ? gmdate( 'c', (int) strtotime( $note['date'] ) ) : '';
                                        $author    = '';
                                        if ( ! empty( $note['author'] ) ) {
                                            $user   = get_userdata( (int) $note['author'] );
                                            $author = $user ? $user->display_name : '';
                                        }
                                        ?>
                                        <li class="tejcart-note <?php echo $note['is_customer_note'] ? 'tejcart-note--customer' : 'tejcart-note--internal'; ?>">
                                            <span class="tejcart-note-marker" aria-hidden="true"></span>
                                            <div class="tejcart-note-body">
                                                <div class="tejcart-note-header">
                                                    <span class="tejcart-note-badge tejcart-note-badge--<?php echo $note['is_customer_note'] ? 'customer' : 'internal'; ?>">
                                                        <?php echo $note['is_customer_note']
                                                            ? esc_html__( 'Customer note', 'tejcart' )
                                                            : esc_html__( 'Internal note', 'tejcart' ); ?>
                                                    </span>
                                                    <?php if ( $note_date ) : ?>
                                                        <time class="tejcart-note-time" datetime="<?php echo esc_attr( $note_iso ); ?>"><?php echo esc_html( $note_date ); ?></time>
                                                    <?php endif; ?>
                                                    <?php if ( $author ) : ?>
                                                        <span class="tejcart-note-author">
                                                            <?php
                                                            /* translators: %s: admin display name. */
                                                            printf( esc_html__( 'by %s', 'tejcart' ), esc_html( $author ) );
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="tejcart-note-content"><?php echo wp_kses_post( wpautop( $note['content'] ) ); ?></div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SIDEBAR -->
                <div class="tejcart-detail-side">

                    <?php
                    $customer_stats = $this->compute_customer_stats( $order );
                    $billing_phone  = '';
                    $billing        = $order->get_billing_address();
                    if ( is_array( $billing ) && ! empty( $billing['phone'] ) ) {
                        $billing_phone = (string) $billing['phone'];
                    }
                    $tel_href = self::tel_href( $billing_phone );
                    $orders_link = add_query_arg(
                        array( 'page' => 'tejcart-orders', 's' => rawurlencode( (string) $order->get_customer_email() ) ),
                        admin_url( 'admin.php' )
                    );
                    ?>
                    <?php
                    $customer_email = (string) $order->get_customer_email();
                    $customer_name  = (string) $order->get_customer_name();
                    $avatar_html    = function_exists( 'get_avatar' ) ? get_avatar( $customer_email, 56, '', $customer_name, array( 'class' => 'tejcart-customer-avatar' ) ) : '';
                    ?>
                    <div class="tejcart-card tejcart-customer-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-businessman"></span> <?php esc_html_e( 'Customer', 'tejcart' ); ?></h3>
                            <?php if ( $customer_stats['order_count'] > 1 ) : ?>
                                <span class="tejcart-card-badge tejcart-card-badge--returning"><?php esc_html_e( 'Returning', 'tejcart' ); ?></span>
                            <?php elseif ( 1 === $customer_stats['order_count'] ) : ?>
                                <span class="tejcart-card-badge tejcart-card-badge--new"><?php esc_html_e( 'First-time buyer', 'tejcart' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="tejcart-card-body">
                            <div class="tejcart-customer-identity">
                                <?php if ( '' !== $avatar_html ) : ?>
                                    <span class="tejcart-customer-avatar-wrap" aria-hidden="true"><?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() is core-escaped. ?></span>
                                <?php endif; ?>
                                <div class="tejcart-customer-identity-text">
                                    <strong class="tejcart-customer-name"><?php echo esc_html( $customer_name ); ?></strong>
                                    <a class="tejcart-customer-email" href="mailto:<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a>
                                    <?php if ( '' !== $billing_phone ) :
                                        $billing_phone_display = self::format_phone( $billing_phone );
                                        ?>
                                        <span class="tejcart-customer-phone">
                                            <?php if ( '' !== $tel_href ) : ?>
                                                <a href="<?php echo esc_attr( $tel_href ); ?>"><?php echo esc_html( $billing_phone_display ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $billing_phone_display ); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ( $customer_stats['order_count'] > 0 ) : ?>
                                <ul class="tejcart-customer-stats" aria-label="<?php esc_attr_e( 'Customer lifetime stats', 'tejcart' ); ?>">
                                    <li>
                                        <span class="tejcart-customer-stat-value"><a href="<?php echo esc_url( $orders_link ); ?>"><?php echo esc_html( number_format_i18n( $customer_stats['order_count'] ) ); ?></a></span>
                                        <span class="tejcart-customer-stat-label"><?php esc_html_e( 'Orders', 'tejcart' ); ?></span>
                                    </li>
                                    <li>
                                        <span class="tejcart-customer-stat-value"><?php echo wp_kses_post( tejcart_price( $customer_stats['lifetime_spend'] ) ); ?></span>
                                        <span class="tejcart-customer-stat-label"><?php esc_html_e( 'Lifetime spend', 'tejcart' ); ?></span>
                                    </li>
                                    <?php if ( '' !== $customer_stats['first_order_label'] ) : ?>
                                        <li>
                                            <span class="tejcart-customer-stat-value"><?php echo esc_html( $customer_stats['first_order_label'] ); ?></span>
                                            <span class="tejcart-customer-stat-label"><?php esc_html_e( 'Customer since', 'tejcart' ); ?></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ( $shipping_method ) : ?>
                                <div class="tejcart-customer-shipping">
                                    <span class="tejcart-meta-label"><?php esc_html_e( 'Shipping method', 'tejcart' ); ?></span>
                                    <span class="tejcart-customer-shipping-value"><?php echo esc_html( $shipping_method ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tejcart-card tejcart-address-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Billing Address', 'tejcart' ); ?></h3>
                        </div>
                        <div class="tejcart-card-body">
                            <?php $this->render_address_block( $order->get_billing_address() ); ?>
                        </div>
                    </div>

                    <?php
                    $billing_addr  = $order->get_billing_address();
                    $shipping_addr = $order->get_shipping_address();
                    $shipping_same = is_array( $billing_addr ) && is_array( $shipping_addr ) && $this->addresses_match( $billing_addr, $shipping_addr );
                    ?>
                    <div class="tejcart-card tejcart-address-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-location-alt"></span> <?php esc_html_e( 'Shipping Address', 'tejcart' ); ?></h3>
                            <?php if ( $shipping_same ) : ?>
                                <span class="tejcart-card-badge tejcart-card-badge--muted"><?php esc_html_e( 'Same as billing', 'tejcart' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="tejcart-card-body">
                            <?php $this->render_address_block( $shipping_addr ); ?>
                        </div>
                    </div>

                    <?php
                    // Audit #18 / 06 F-H6 — "Send invoice" action exposes
                    // the previously-unreachable Customer_Invoice email
                    // to the admin. The form posts back to
                    // maybe_handle_post() under the same redirect-after-
                    // POST pattern used for refunds / notes.
                    //
                    // The copy + button label adapt to the order's payment
                    // status: awaiting-payment orders show a "pay-link"
                    // CTA, paid orders show a "receipt copy" CTA. The
                    // underlying Customer_Invoice email is already smart
                    // about omitting the pay button when get_pay_url()
                    // returns empty, so this is purely a UX-clarity fix.
                    if ( \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) :
                        $invoice_recipient = (string) $order->get_customer_email();
                        $invoice_awaiting  = in_array(
                            (string) $order->get_status(),
                            array(
                                \TejCart\Order\Order_Status::PENDING,
                                \TejCart\Order\Order_Status::ON_HOLD,
                                \TejCart\Order\Order_Status::FAILED,
                            ),
                            true
                        );
                        $invoice_title     = $invoice_awaiting
                            ? __( 'Customer Invoice', 'tejcart' )
                            : __( 'Order Receipt', 'tejcart' );
                        $invoice_button    = $invoice_awaiting
                            ? __( 'Send invoice email', 'tejcart' )
                            : __( 'Send receipt email', 'tejcart' );
                    ?>
                    <div class="tejcart-card">
                        <div class="tejcart-card-header">
                            <h3><span class="dashicons dashicons-email-alt"></span> <?php echo esc_html( $invoice_title ); ?></h3>
                        </div>
                        <div class="tejcart-card-body">
                            <?php if ( '' === $invoice_recipient ) : ?>
                                <p class="description"><?php esc_html_e( 'This order has no customer email on file; cannot send an invoice.', 'tejcart' ); ?></p>
                            <?php else : ?>
                                <p class="description">
                                    <?php
                                    if ( $invoice_awaiting ) {
                                        echo wp_kses_post(
                                            sprintf(
                                                /* translators: %s: customer email address. */
                                                __( 'Email the buyer a pay-now link for this order. Recipient: <strong>%s</strong>.', 'tejcart' ),
                                                esc_html( $invoice_recipient )
                                            )
                                        );
                                    } else {
                                        echo wp_kses_post(
                                            sprintf(
                                                /* translators: %s: customer email address. */
                                                __( 'Email a receipt copy of this order to the customer. Recipient: <strong>%s</strong>.', 'tejcart' ),
                                                esc_html( $invoice_recipient )
                                            )
                                        );
                                    }
                                    ?>
                                </p>
                                <form method="post">
                                    <?php wp_nonce_field( 'tejcart_send_invoice', 'tejcart_send_invoice_nonce' ); ?>
                                    <input type="hidden" name="tejcart_send_invoice_action" value="1" />
                                    <input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
                                    <button type="submit" class="button button-secondary"><?php echo esc_html( $invoice_button ); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    /**
                     * Fires inside the order edit screen sidebar, after the
                     * built-in customer / billing / shipping cards. Sibling
                     * plugins (e.g. tejcart-order-tracking, tejcart-disputes)
                     * use this to inject their own cards without forking the
                     * admin template.
                     *
                     * Implementations should render a single
                     * `<div class="tejcart-card">…</div>` to match the
                     * surrounding visual rhythm.
                     *
                     * @since 1.x.0
                     * @param \TejCart\Order\Order $order The order being viewed.
                     */
                    do_action( 'tejcart_admin_order_after_sidebar', $order );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Build a `tel:` URI from a free-form phone string.
     *
     * Strips everything except digits and a leading `+`. Returns an
     * empty string if no usable digits remain so the caller can fall
     * back to a plain text label without rendering a dead link.
     */
    public static function tel_href( string $phone ): string {
        $phone = trim( $phone );
        if ( '' === $phone ) {
            return '';
        }
        $plus  = ( '+' === substr( $phone, 0, 1 ) ) ? '+' : '';
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        if ( null === $digits || '' === $digits ) {
            return '';
        }
        return 'tel:' . $plus . $digits;
    }

    /**
     * Pretty-print a phone number for display only. North American 10 / 11
     * digit numbers are grouped as `(NPA) NXX-XXXX`; everything else is
     * returned untouched. The raw value is still passed to {@see self::tel_href()}
     * so the dialer-friendly link stays intact.
     */
    public static function format_phone( string $phone ): string {
        $phone = trim( $phone );
        if ( '' === $phone ) {
            return '';
        }
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        if ( null === $digits || '' === $digits ) {
            return $phone;
        }
        if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
            return sprintf( '+1 (%s) %s-%s', substr( $digits, 1, 3 ), substr( $digits, 4, 3 ), substr( $digits, 7, 4 ) );
        }
        if ( 10 === strlen( $digits ) ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 4 ) );
        }
        return $phone;
    }

    /**
     * Resolve a 2-letter ISO-3166 country code to its English display
     * name. Falls back to the original value (typically already the
     * code) when the dataset has no entry, so unknown / legacy values
     * still render rather than vanishing from the address block.
     */
    public static function country_label( string $country ): string {
        $country = trim( $country );
        if ( '' === $country ) {
            return '';
        }
        if ( 2 !== strlen( $country ) ) {
            return $country;
        }
        $map = Tax_Manager::get_countries();
        $key = strtoupper( $country );
        return $map[ $key ] ?? $country;
    }

    /**
     * Compute past-orders / lifetime-spend / customer-since stats for the
     * buyer behind this order. Matches by registered customer_id when
     * present, otherwise by customer_email — guest checkouts still get a
     * meaningful "returning vs first-time" badge that way.
     *
     * Returns zero counts on any DB error so the customer card always
     * renders. Excludes pending / cancelled / failed orders from
     * lifetime spend so the merchant sees actual revenue, not pending
     * intent.
     *
     * @return array{order_count:int,lifetime_spend:float,first_order_label:string}
     */
    private function compute_customer_stats( Order $order ): array {
        global $wpdb;

        $email       = (string) $order->get_customer_email();
        $customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

        $blank = array(
            'order_count'       => 0,
            'lifetime_spend'    => 0.0,
            'first_order_label' => '',
        );

        if ( ! $email && ! $customer_id ) {
            return $blank;
        }

        if ( ! isset( $wpdb ) || empty( $wpdb->prefix ) ) {
            return $blank;
        }

        $table   = $wpdb->prefix . 'tejcart_orders';
        $counted = array( 'processing', 'completed', 'on_hold', 'refunded' );
        $placeholders = implode( ',', array_fill( 0, count( $counted ), '%s' ) );

        if ( $customer_id > 0 ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COUNT(*) AS c, COALESCE(SUM(base_total),0) AS s, MIN(created_at) AS first_at FROM {$table} WHERE customer_id = %d AND status IN ({$placeholders})",
                    array_merge( array( $customer_id ), $counted )
                )
            );
            // phpcs:enable
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COUNT(*) AS c, COALESCE(SUM(base_total),0) AS s, MIN(created_at) AS first_at FROM {$table} WHERE customer_email = %s AND status IN ({$placeholders})",
                    array_merge( array( $email ), $counted )
                )
            );
            // phpcs:enable
        }

        if ( ! $row ) {
            return $blank;
        }

        $first_label = '';
        if ( ! empty( $row->first_at ) ) {
            $ts = strtotime( (string) $row->first_at );
            if ( $ts ) {
                $first_label = date_i18n( get_option( 'date_format' ), $ts );
            }
        }

        // SUM(total) is BIGINT minor units. Assume shop currency for the
        // lifetime-spend roll-up — the pre-migration float pipeline also
        // summed across mixed-currency orders nominally and converted
        // here is no worse than before. New multi-currency reports
        // should bucket per-currency upstream.
        $shop_currency = function_exists( 'tejcart_get_currency' )
            ? (string) tejcart_get_currency()
            : (string) get_option( 'tejcart_currency', 'USD' );
        return array(
            'order_count'       => (int) ( $row->c ?? 0 ),
            'lifetime_spend'    => \TejCart\Money\Currency::from_minor_units( (int) ( $row->s ?? 0 ), $shop_currency ),
            'first_order_label' => $first_label,
        );
    }

    /**
     * Render a structured address block for the order detail sidebar.
     *
     * Uses semantic <address> with one logical line per row so screen
     * readers and copy-paste behave correctly, and so the visual
     * hierarchy follows the conventional admin layout (recipient name
     * bolded, then street, locality, country, phone).
     */
    private function render_address_block( $address ): void {
        if ( ! is_array( $address ) || array() === array_filter( $address, static fn( $v ) => '' !== (string) $v ) ) {
            echo '<p class="tejcart-address-empty">' . esc_html__( 'No address on file.', 'tejcart' ) . '</p>';
            return;
        }

        $name      = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
        $company   = (string) ( $address['company'] ?? '' );
        $line1     = (string) ( $address['address_1'] ?? '' );
        $line2     = (string) ( $address['address_2'] ?? '' );
        $locality  = trim( ( $address['city'] ?? '' ) . ' ' . ( $address['state'] ?? '' ) . ' ' . ( $address['postcode'] ?? '' ) );
        $country_raw = (string) ( $address['country'] ?? '' );
        $country     = self::country_label( $country_raw );
        $phone       = (string) ( $address['phone'] ?? '' );
        $tel_href    = self::tel_href( $phone );
        $phone_label = self::format_phone( $phone );
        ?>
        <address class="tejcart-address">
            <?php if ( '' !== $name ) : ?>
                <span class="tejcart-address-line tejcart-address-name"><?php echo esc_html( $name ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $company ) : ?>
                <span class="tejcart-address-line tejcart-address-company"><?php echo esc_html( $company ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $line1 ) : ?>
                <span class="tejcart-address-line"><?php echo esc_html( $line1 ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $line2 ) : ?>
                <span class="tejcart-address-line"><?php echo esc_html( $line2 ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $locality ) : ?>
                <span class="tejcart-address-line"><?php echo esc_html( $locality ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $country ) : ?>
                <span class="tejcart-address-line tejcart-address-country"><?php echo esc_html( $country ); ?></span>
            <?php endif; ?>
            <?php if ( '' !== $phone ) : ?>
                <span class="tejcart-address-line tejcart-address-phone">
                    <span class="dashicons dashicons-phone" aria-hidden="true"></span>
                    <?php if ( '' !== $tel_href ) : ?>
                        <a href="<?php echo esc_attr( $tel_href ); ?>"><?php echo esc_html( $phone_label ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $phone_label ); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </address>
        <?php
    }

    /**
     * Compare the postal-relevant fields of two address arrays. Used
     * to surface the "Same as billing" badge on the shipping card so
     * admins can tell at a glance the buyer didn't ship elsewhere.
     */
    private function addresses_match( array $a, array $b ): bool {
        $keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
        foreach ( $keys as $k ) {
            if ( strcasecmp( trim( (string) ( $a[ $k ] ?? '' ) ), trim( (string) ( $b[ $k ] ?? '' ) ) ) !== 0 ) {
                return false;
            }
        }
        return true;
    }

    private function render_new_form(): void {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $products = $wpdb->get_results( "SELECT id, name, price FROM {$products_table} WHERE status = 'publish' ORDER BY name ASC LIMIT 500" );
        $products = is_array( $products ) ? $products : array();

        // Reusable dataset for the billing country + payment-method
        // dropdowns. Sorted alphabetically so admins can hunt by name.
        $countries = Tax_Manager::get_countries();
        if ( is_array( $countries ) ) {
            asort( $countries );
        } else {
            $countries = array();
        }

        // Default-country defaults to the store country so the manual
        // order form lands somewhere sensible for most flows.
        $default_country = (string) get_option( 'tejcart_store_country', 'US' );
        $initial_states  = '' !== $default_country ? Tax_Manager::get_states( $default_country ) : array();

        $payment_methods = array( 'manual' => __( 'Manual / cash / other', 'tejcart' ) );
        if ( function_exists( 'tejcart' ) ) {
            $registry = tejcart()->gateways();
            if ( $registry && method_exists( $registry, 'get_gateways' ) ) {
                foreach ( $registry->get_gateways() as $gateway ) {
                    if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'get_id' ) ) {
                        continue;
                    }
                    $id    = (string) $gateway->get_id();
                    $title = method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : $id;
                    if ( '' !== $id && ! isset( $payment_methods[ $id ] ) ) {
                        $payment_methods[ $id ] = '' !== $title ? $title : $id;
                    }
                }
            }
        }
        ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Orders', 'tejcart' ); ?></a>
                    <h1><?php esc_html_e( 'New Order', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Create a manual order on behalf of a customer.', 'tejcart' ); ?></p>
                </div>
            </div>

            <?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Could not create the order. Make sure customer email and at least one item are provided.', 'tejcart' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'tejcart_new_order', 'tejcart_new_order_nonce' ); ?>
                <input type="hidden" name="tejcart_new_order_action" value="1" />

                <!-- Customer section -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-businessman"></span> <?php esc_html_e( 'Customer', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Who is this order for?', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="nc_customer_name"><?php esc_html_e( 'Name', 'tejcart' ); ?></label></th>
                                <td><input type="text" id="nc_customer_name" name="customer_name" class="regular-text" placeholder="<?php esc_attr_e( 'Full name', 'tejcart' ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="nc_customer_email"><?php esc_html_e( 'Email', 'tejcart' ); ?><span class="tejcart-required" aria-hidden="true">*</span></label></th>
                                <td>
                                    <input type="email" id="nc_customer_email" name="customer_email" class="regular-text" required placeholder="customer@example.com" />
                                    <p class="description"><?php esc_html_e( 'The email receives the order confirmation.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Billing Address section -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Billing Address', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Used for invoicing and tax calculation.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="nc_bill_fn"><?php esc_html_e( 'First Name', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_fn" name="billing_first_name" class="regular-text" /></td></tr>
                            <tr><th scope="row"><label for="nc_bill_ln"><?php esc_html_e( 'Last Name', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_ln" name="billing_last_name" class="regular-text" /></td></tr>
                            <tr><th scope="row"><label for="nc_bill_addr"><?php esc_html_e( 'Street Address', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_addr" name="billing_address_1" class="regular-text" /></td></tr>
                            <tr><th scope="row"><label for="nc_bill_city"><?php esc_html_e( 'City', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_city" name="billing_city" class="regular-text" /></td></tr>
                            <tr><th scope="row"><label for="nc_bill_country"><?php esc_html_e( 'Country', 'tejcart' ); ?></label></th><td>
                                <select id="nc_bill_country" name="billing_country" class="regular-text tejcart-country-select" data-tejcart-state-pair="new_order_billing">
                                    <option value=""><?php esc_html_e( '— Select a country —', 'tejcart' ); ?></option>
                                    <?php foreach ( $countries as $code => $name ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default_country, $code ); ?>><?php echo esc_html( $name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td></tr>
                            <tr><th scope="row"><label for="nc_bill_state"><?php esc_html_e( 'State / Region', 'tejcart' ); ?></label></th><td>
                                <?php if ( ! empty( $initial_states ) ) : ?>
                                    <select id="nc_bill_state" name="billing_state" class="regular-text" data-tejcart-state-pair="new_order_billing">
                                        <option value=""><?php esc_html_e( '— Select a state —', 'tejcart' ); ?></option>
                                        <?php foreach ( $initial_states as $code => $name ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="text" id="nc_bill_state" name="billing_state" class="regular-text" data-tejcart-state-pair="new_order_billing" />
                                <?php endif; ?>
                            </td></tr>
                            <tr><th scope="row"><label for="nc_bill_zip"><?php esc_html_e( 'Postcode', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_zip" name="billing_postcode" class="regular-text" /></td></tr>
                            <tr><th scope="row"><label for="nc_bill_phone"><?php esc_html_e( 'Phone', 'tejcart' ); ?></label></th><td><input type="text" id="nc_bill_phone" name="billing_phone" class="regular-text" /></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Items section -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Items', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Add one or more products to this order.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="wp-list-table widefat striped" id="tejcart-new-order-items">
                            <thead><tr>
                                <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Unit Price (override)', 'tejcart' ); ?></th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <select name="items[0][product_id]">
                                            <option value="">— <?php esc_html_e( 'Select a product', 'tejcart' ); ?> —</option>
                                            <?php foreach ( $products as $p ) : ?>
                                                <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( $p->name ); ?> (<?php echo wp_kses_post( tejcart_price( (float) $p->price ) ); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" min="1" name="items[0][quantity]" value="1" class="small-text" /></td>
                                    <td><input type="number" step="0.01" name="items[0][unit_price]" placeholder="<?php esc_attr_e( 'Use product price', 'tejcart' ); ?>" /></td>
                                    <td><a href="#" class="tejcart-remove-row button-link-danger"><?php esc_html_e( 'Remove', 'tejcart' ); ?></a></td>
                                </tr>
                            </tbody>
                        </table>
                        <p><button type="button" class="button" id="tejcart-add-item-row"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Item', 'tejcart' ); ?></button></p>
                    </div>
                </div>

                <!-- Totals section -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Totals & Payment', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Extra charges and payment method for this order.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="nc_shipping_total"><?php esc_html_e( 'Shipping', 'tejcart' ); ?></label></th><td><input type="number" id="nc_shipping_total" step="0.01" name="shipping_total" value="0" /></td></tr>
                            <tr><th scope="row"><label for="nc_tax_total"><?php esc_html_e( 'Tax', 'tejcart' ); ?></label></th><td><input type="number" id="nc_tax_total" step="0.01" name="tax_total" value="0" /></td></tr>
                            <tr><th scope="row"><label for="nc_payment_method"><?php esc_html_e( 'Payment Method', 'tejcart' ); ?></label></th><td>
                                <select id="nc_payment_method" name="payment_method" class="regular-text">
                                    <?php foreach ( $payment_methods as $id => $label ) : ?>
                                        <option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'manual', $id ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Pick the gateway / method that received this order.', 'tejcart' ); ?></p>
                            </td></tr>
                        </table>
                    </div>
                </div>

                <div class="tejcart-form-footer">
                    <?php submit_button( __( 'Create Order', 'tejcart' ), 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        <?php
    }
}
