<?php
/**
 * Printable order invoice.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders a print-friendly HTML invoice for an order.
 *
 * The browser's "Save as PDF" / "Print to PDF" feature is used to
 * produce a real PDF without bringing in a heavy server-side
 * dependency such as dompdf or mPDF. The same template can later be
 * piped through a PDF library by overriding the `tejcart_invoice_html`
 * filter.
 *
 * URL contract:
 *   /?tejcart_invoice=<order_id>&key=<order_key>
 *
 * Admins (manage_options) can view any invoice. Other users must
 * supply the order key that matches the order.
 */
class Invoice {
    /**
     * Hook into WordPress.
     */
    public function init(): void {
        add_action( 'init', array( $this, 'maybe_render' ) );
    }

    /**
     * Build a permalink that renders this invoice.
     */
    public static function get_url( $order ): string {
        $id  = is_object( $order ) ? (int) $order->get_id() : (int) $order;
        $key = '';
        if ( is_object( $order ) && method_exists( $order, 'get_order_key' ) ) {
            $key = (string) $order->get_order_key();
        }

        return add_query_arg(
            array(
                'tejcart_invoice' => $id,
                'key'             => $key,
            ),
            home_url( '/' )
        );
    }

    /**
     * Detect the query var, authorise the request and render the invoice.
     */
    public function maybe_render(): void {
        if ( empty( $_GET['tejcart_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $order_id = absint( $_GET['tejcart_invoice'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! class_exists( '\\TejCart\\Order\\Order' ) ) {
            return;
        }

        $order = new Order( $order_id );
        if ( ! $order->get_id() ) {
            wp_die( esc_html__( 'Order not found.', 'tejcart' ), '', array( 'response' => 404 ) );
        }

        $is_admin = current_user_can( 'manage_options' );
        $key_ok   = method_exists( $order, 'get_order_key' ) && hash_equals( (string) $order->get_order_key(), $key );

        if ( ! $is_admin && ! $key_ok ) {
            wp_die( esc_html__( 'You do not have permission to view this invoice.', 'tejcart' ), '', array( 'response' => 403 ) );
        }

        $html = $this->render_html( $order );

        /**
         * Filter the rendered invoice HTML, allowing themes/extensions
         * to swap in a real PDF (e.g. via dompdf) before it is output.
         *
         * @param string $html  Rendered HTML.
         * @param Order  $order Order instance.
         */
        $html = apply_filters( 'tejcart_invoice_html', $html, $order );

        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Build the invoice HTML.
     */
    private function render_html( Order $order ): string {
        $store_name    = (string) get_option( 'blogname' );
        $store_address = (string) get_option( 'tejcart_store_address', '' );
        $billing       = $order->get_billing_address();
        $shipping      = $order->get_shipping_address();
        $items         = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
        $currency      = method_exists( $order, 'get_currency' ) ? $order->get_currency() : '';

        // The invoice is a self-contained HTML page (rendered as a
        // direct response, not through wp_head/wp_footer). Even so we
        // register and print the stylesheet/script through the normal
        // WordPress enqueue API so wordpress.org's "no inline
        // <style>/<script>" rule is satisfied.
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        wp_register_style(
            'tejcart-invoice',
            tejcart_asset_url( 'assets/css/order/invoice.css' ),
            array(),
            $version
        );
        wp_enqueue_style( 'tejcart-invoice' );

        wp_register_script(
            'tejcart-invoice',
            tejcart_asset_url( 'assets/js/order/invoice.js' ),
            array(),
            $version,
            true
        );
        wp_enqueue_script( 'tejcart-invoice' );

        ob_start();
        ?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
<title><?php
/* translators: %s: order number. */
printf( esc_html__( 'Invoice %s', 'tejcart' ), esc_html( $order->get_order_number() ) );
?></title>
<?php wp_print_styles( array( 'tejcart-invoice' ) ); ?>
</head>
<body>
    <div class="nx-print"><button type="button" id="tejcart-invoice-print"><?php esc_html_e( 'Print / Save as PDF', 'tejcart' ); ?></button></div>

    <h1><?php esc_html_e( 'Invoice', 'tejcart' ); ?></h1>
    <div class="nx-meta">
        <?php echo esc_html( $order->get_order_number() ); ?> &middot;
        <?php echo esc_html( mysql2date( get_option( 'date_format' ), $order->get_created_at() ) ); ?>
    </div>

    <div class="nx-row">
        <div>
            <h3><?php esc_html_e( 'From', 'tejcart' ); ?></h3>
            <strong><?php echo esc_html( $store_name ); ?></strong><br />
            <?php echo nl2br( esc_html( $store_address ) ); ?>
        </div>
        <div>
            <h3><?php esc_html_e( 'Bill To', 'tejcart' ); ?></h3>
            <?php echo wp_kses_post( $this->format_address( $billing, $order->get_customer_email() ) ); ?>
        </div>
        <?php if ( ! empty( $shipping ) ) : ?>
            <div>
                <h3><?php esc_html_e( 'Ship To', 'tejcart' ); ?></h3>
                <?php echo wp_kses_post( $this->format_address( $shipping ) ); ?>
            </div>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php esc_html_e( 'Item', 'tejcart' ); ?></th>
                <th class="num"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                <th class="num"><?php esc_html_e( 'Unit', 'tejcart' ); ?></th>
                <th class="num"><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        // F-CCM-011: line_total is stored as BIGINT minor units in wp_tejcart_order_items.
        // Passing the raw minor-unit integer to tejcart_price() would display $1000.00
        // instead of $10.00 for a USD line. Convert via Currency::from_minor_units().
        // unit_price is DECIMAL(20,4) (major units) — no conversion needed.
        $invoice_currency = $order->get_currency();
        foreach ( $items as $item ) :
            $name        = isset( $item->product_name ) ? $item->product_name : ( isset( $item['product_name'] ) ? $item['product_name'] : '' );
            $qty         = isset( $item->quantity )   ? (int) $item->quantity   : (int) ( $item['quantity']   ?? 0 );
            $unit        = isset( $item->unit_price ) ? (float) $item->unit_price : (float) ( $item['unit_price'] ?? 0 );
            $total_minor = isset( $item->line_total ) ? (int) $item->line_total  : (int) ( $item['line_total']  ?? 0 );
            $total       = \TejCart\Money\Currency::from_minor_units( $total_minor, $invoice_currency );
            ?>
            <tr>
                <td><?php echo esc_html( $name ); ?></td>
                <td class="num"><?php echo (int) $qty; ?></td>
                <td class="num"><?php echo wp_kses_post( tejcart_price( $unit, $invoice_currency ) ); ?></td>
                <td class="num"><?php echo wp_kses_post( tejcart_price( $total, $invoice_currency ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="num"><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></td><td class="num"><?php echo wp_kses_post( tejcart_price( $order->get_subtotal(), $invoice_currency ) ); ?></td></tr>
            <?php if ( $order->get_discount_total() > 0 ) : ?>
                <tr><td colspan="3" class="num"><?php esc_html_e( 'Discount', 'tejcart' ); ?></td><td class="num">-<?php echo wp_kses_post( tejcart_price( $order->get_discount_total(), $invoice_currency ) ); ?></td></tr>
            <?php endif; ?>
            <?php if ( $order->get_shipping_total() > 0 ) : ?>
                <tr><td colspan="3" class="num"><?php esc_html_e( 'Shipping', 'tejcart' ); ?></td><td class="num"><?php echo wp_kses_post( tejcart_price( $order->get_shipping_total(), $invoice_currency ) ); ?></td></tr>
            <?php endif; ?>
            <?php if ( $order->get_tax_total() > 0 ) : ?>
                <tr><td colspan="3" class="num"><?php esc_html_e( 'Tax', 'tejcart' ); ?></td><td class="num"><?php echo wp_kses_post( tejcart_price( $order->get_tax_total(), $invoice_currency ) ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( tejcart_get_order_fee_lines( $order ) as $tejcart_invoice_fee ) : ?>
                <tr><td colspan="3" class="num"><?php echo esc_html( $tejcart_invoice_fee['label'] ); ?></td><td class="num"><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $tejcart_invoice_fee['amount'], $invoice_currency ) ); ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td colspan="3" class="num"><?php esc_html_e( 'Total', 'tejcart' ); ?></td><td class="num"><?php echo wp_kses_post( tejcart_price( $order->get_total(), $invoice_currency ) ); ?></td></tr>
        </tfoot>
    </table>

    <p class="nx-meta" style="margin-top:30px;">
        <?php echo esc_html( apply_filters( 'tejcart_invoice_footer', __( 'Thank you for your business.', 'tejcart' ), $order ) ); ?>
    </p>
<?php wp_print_scripts( array( 'tejcart-invoice' ) ); ?>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Format an address array as HTML.
     */
    private function format_address( $address, string $email = '' ): string {
        if ( ! is_array( $address ) || empty( $address ) ) {
            return $email ? esc_html( $email ) : '';
        }

        $parts = array();
        $name  = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
        if ( $name ) {
            $parts[] = '<strong>' . esc_html( $name ) . '</strong>';
        }
        if ( ! empty( $address['company'] ) ) {
            $parts[] = esc_html( $address['company'] );
        }
        foreach ( array( 'address_1', 'address_2' ) as $line ) {
            if ( ! empty( $address[ $line ] ) ) {
                $parts[] = esc_html( $address[ $line ] );
            }
        }
        $city_line = trim( ( $address['city'] ?? '' ) . ' ' . ( $address['state'] ?? '' ) . ' ' . ( $address['postcode'] ?? '' ) );
        if ( $city_line ) {
            $parts[] = esc_html( $city_line );
        }
        if ( ! empty( $address['country'] ) ) {
            $parts[] = esc_html( $address['country'] );
        }
        if ( $email ) {
            $parts[] = esc_html( $email );
        }

        return implode( '<br />', $parts );
    }
}
