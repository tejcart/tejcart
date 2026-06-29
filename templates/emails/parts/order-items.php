<?php
/**
 * Shared order items + totals table for transactional emails.
 *
 * Included from a content template after `parts/styles.php` so that
 * `$nx_*_style` and `$order` are already in scope. Centralising the
 * items table here keeps every order-related email visually
 * consistent and isolates future changes (e.g. SKU column, image
 * thumbnails) to a single file.
 *
 * @package TejCart\Templates\Emails\Parts
 *
 * @var \TejCart\Order\Order $order
 * @var string               $nx_table_style
 * @var string               $nx_th_style
 * @var string               $nx_th_center
 * @var string               $nx_th_right
 * @var string               $nx_td_style
 * @var string               $nx_tdc_style
 * @var string               $nx_tdr_style
 * @var string               $nx_tot_label
 * @var string               $nx_tot_value
 * @var string               $nx_total_label Optional override for the total row label.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $nx_*_style values are static, pre-built attribute payloads.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$nx_total_label = isset( $nx_total_label ) ? (string) $nx_total_label : __( 'Total', 'tejcart' );
$nx_order_currency = (string) $order->get_currency();
?>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" class="nx-table-row nx-text" style="<?php echo esc_attr( $nx_table_style ); ?>">
    <thead>
        <tr>
            <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
            <th class="nx-th" style="<?php echo esc_attr( $nx_th_center ); ?>"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
            <th class="nx-th" style="<?php echo esc_attr( $nx_th_right ); ?>"><?php esc_html_e( 'Price', 'tejcart' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $order->get_items() as $item ) :
            // Items can be Order_Item objects (newer flow) or plain arrays/stdClass (legacy).
            if ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
                $item_name  = (string) $item->get_name();
                $item_qty   = (int) $item->get_quantity();
                $item_total = (float) $item->get_total();
            } else {
                $item_name      = isset( $item->product_name ) ? (string) $item->product_name : (string) ( $item['product_name'] ?? '' );
                $item_qty       = isset( $item->quantity )     ? (int) $item->quantity        : (int) ( $item['quantity']     ?? 0 );
                $item_total_raw = isset( $item->line_total )   ? (int) $item->line_total      : (int) ( $item['line_total'] ?? 0 );
                // line_total is BIGINT minor units in the order's currency — convert for display.
                $item_total     = \TejCart\Money\Currency::from_minor_units( $item_total_raw, $nx_order_currency );
            }
            ?>
            <tr class="nx-table-row">
                <td style="<?php echo esc_attr( $nx_td_style ); ?>"><?php echo esc_html( $item_name ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdc_style ); ?>"><?php echo esc_html( (string) $item_qty ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo wp_kses_post( tejcart_price( $item_total, $nx_order_currency ) ); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2" style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></td>
            <td style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo wp_kses_post( tejcart_price( $order->get_subtotal(), $nx_order_currency ) ); ?></td>
        </tr>
        <?php if ( $order->get_shipping_total() > 0 ) : ?>
            <tr>
                <td colspan="2" style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php esc_html_e( 'Shipping', 'tejcart' ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo wp_kses_post( tejcart_price( $order->get_shipping_total(), $nx_order_currency ) ); ?></td>
            </tr>
        <?php endif; ?>
        <?php if ( $order->get_tax_total() > 0 ) : ?>
            <tr>
                <td colspan="2" style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php esc_html_e( 'Tax', 'tejcart' ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo wp_kses_post( tejcart_price( $order->get_tax_total(), $nx_order_currency ) ); ?></td>
            </tr>
        <?php endif; ?>
        <?php if ( $order->get_discount_total() > 0 ) : ?>
            <tr>
                <td colspan="2" style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php esc_html_e( 'Discount', 'tejcart' ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdr_style ); ?>">-<?php echo wp_kses_post( tejcart_price( $order->get_discount_total(), $nx_order_currency ) ); ?></td>
            </tr>
        <?php endif; ?>
        <?php foreach ( tejcart_get_order_fee_lines( $order ) as $nx_order_fee ) : ?>
            <tr>
                <td colspan="2" style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo esc_html( $nx_order_fee['label'] ); ?></td>
                <td style="<?php echo esc_attr( $nx_tdr_style ); ?>"><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $nx_order_fee['amount'], $nx_order_currency ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="2" style="<?php echo esc_attr( $nx_tot_label ); ?>"><?php echo esc_html( $nx_total_label ); ?></td>
            <td style="<?php echo esc_attr( $nx_tot_value ); ?>"><?php echo wp_kses_post( tejcart_price( $order->get_total(), $nx_order_currency ) ); ?></td>
        </tr>
    </tfoot>
</table>
<?php
/**
 * F-M10 / #944: extension slot fired right after the order-items
 * summary table in every transactional email that includes this
 * partial. The order-tracking module listens here to append a
 * tracking-numbers block (the listener existed but the hook was
 * never fired anywhere, leaving the feature inert).
 *
 * @param \TejCart\Order\Order $order
 */
do_action( 'tejcart_order_email_after_summary', $order );
