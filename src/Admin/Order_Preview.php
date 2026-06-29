<?php
/**
 * Order preview modal support.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Implements the "Preview" modal on the Orders list.
 *
 * Registers a single AJAX endpoint that returns a compact order summary
 * fragment, plus enqueues inline JS that turns the "Preview" link
 * rendered by Orders_Table::column_actions() into a modal overlay.
 */
class Order_Preview {
    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'wp_ajax_tejcart_preview_order', array( $this, 'handle_ajax' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_footer', array( $this, 'print_assets' ) );
    }

    /**
     * Enqueue the modal stylesheet on Orders admin screens.
     */
    public function enqueue_styles(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( (string) $screen->id, 'tejcart-orders' ) ) {
            return;
        }

        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        wp_enqueue_style(
            'tejcart-admin-order-preview',
            tejcart_asset_url( 'assets/css/admin/order-preview.css' ),
            array(),
            $version
        );
    }

    /**
     * AJAX: return an HTML fragment describing the order.
     */
    public function handle_ajax(): void {
        check_ajax_referer( 'tejcart_preview_order', 'nonce' );

        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing order ID.', 'tejcart' ) ), 400 );
        }

        $order = \TejCart\Order\Order_Factory::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'tejcart' ) ), 404 );
        }

        ob_start();
        $this->render_body( $order );
        $html = ob_get_clean();

        wp_send_json_success(
            array(
                'title' => sprintf(
                    /* translators: %s: order number */
                    __( 'Order #%s', 'tejcart' ),
                    (string) $order->get_order_number()
                ),
                'html'  => $html,
            )
        );
    }

    /**
     * Render the modal body HTML.
     *
     * @param \TejCart\Order\Order $order
     */
    private function render_body( $order ): void {
        $billing  = (string) $order->get_formatted_billing_address();
        $shipping = (string) $order->get_formatted_shipping_address();
        // Render every amount in the order's own currency (orders are
        // multi-currency); without this the shop-default symbol would be
        // used for orders placed in another currency.
        $preview_currency = (string) $order->get_currency();
        ?>
        <div class="tejcart-order-preview-meta">
            <p><strong><?php esc_html_e( 'Status:', 'tejcart' ); ?></strong> <?php echo esc_html( ucfirst( (string) $order->get_status() ) ); ?></p>
            <p><strong><?php esc_html_e( 'Date:', 'tejcart' ); ?></strong> <?php echo esc_html( (string) $order->get_date_created() ); ?></p>
            <p><strong><?php esc_html_e( 'Customer:', 'tejcart' ); ?></strong>
                <?php echo esc_html( (string) $order->get_customer_name() ); ?>
                &lt;<?php echo esc_html( (string) $order->get_customer_email() ); ?>&gt;
            </p>
            <p><strong><?php esc_html_e( 'Payment:', 'tejcart' ); ?></strong> <?php echo esc_html( (string) $order->get_payment_method_title() ); ?></p>
        </div>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Item', 'tejcart' ); ?></th>
                    <th><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $order->get_items() as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $item->get_name() ); ?></td>
                        <td><?php echo (int) $item->get_quantity(); ?></td>
                        <td><?php echo wp_kses_post( tejcart_price( (float) $item->get_total(), $preview_currency ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2"><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></th>
                    <td><?php echo wp_kses_post( tejcart_price( (float) $order->get_subtotal(), $preview_currency ) ); ?></td>
                </tr>
                <?php if ( $order->get_shipping_total() > 0 ) : ?>
                    <tr>
                        <th colspan="2"><?php esc_html_e( 'Shipping', 'tejcart' ); ?></th>
                        <td><?php echo wp_kses_post( tejcart_price( (float) $order->get_shipping_total(), $preview_currency ) ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( $order->get_tax_total() > 0 ) : ?>
                    <tr>
                        <th colspan="2"><?php esc_html_e( 'Tax', 'tejcart' ); ?></th>
                        <td><?php echo wp_kses_post( tejcart_price( (float) $order->get_tax_total(), $preview_currency ) ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ( tejcart_get_order_fee_lines( $order ) as $tejcart_preview_fee ) : ?>
                    <tr>
                        <th colspan="2"><?php echo esc_html( $tejcart_preview_fee['label'] ); ?></th>
                        <td><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $tejcart_preview_fee['amount'], $preview_currency ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th colspan="2"><strong><?php esc_html_e( 'Total', 'tejcart' ); ?></strong></th>
                    <td><strong><?php echo wp_kses_post( tejcart_price( (float) $order->get_total(), $preview_currency ) ); ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <?php if ( '' !== $billing ) : ?>
            <h4><?php esc_html_e( 'Billing', 'tejcart' ); ?></h4>
            <address><?php echo wp_kses_post( $billing ); ?></address>
        <?php endif; ?>

        <?php if ( '' !== $shipping ) : ?>
            <h4><?php esc_html_e( 'Shipping', 'tejcart' ); ?></h4>
            <address><?php echo wp_kses_post( $shipping ); ?></address>
        <?php endif; ?>
        <?php
    }

    /**
     * Emit the modal shell + JS/CSS on the Orders admin page only.
     */
    public function print_assets(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( (string) $screen->id, 'tejcart-orders' ) ) {
            return;
        }
        ?>
        <div id="tejcart-preview-modal" class="tejcart-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="tejcart-modal" tabindex="-1">
                <div class="tejcart-modal-header">
                    <h2 class="tejcart-modal-title"><?php esc_html_e( 'Order preview', 'tejcart' ); ?></h2>
                    <button type="button" class="tejcart-modal-close" aria-label="<?php esc_attr_e( 'Close', 'tejcart' ); ?>">&times;</button>
                </div>
                <div class="tejcart-modal-body"></div>
            </div>
        </div>

        <?php
    }
}
