<?php
/**
 * Order completed email template (gold-grade, table-based, inline styles).
 *
 * Sent to the customer when their order status changes to completed.
 * Includes a downloads block when the order has digital products.
 * Header and footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var \TejCart\Order\Order $order The order instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$order_number     = $order->get_order_number();
$order_date       = $order->get_date_created();
$billing_address  = $order->get_formatted_billing_address();
$shipping_address = $order->get_formatted_shipping_address();

$customer_id = (int) $order->get_customer_id();
$downloads   = ( $customer_id > 0 && function_exists( 'tejcart_get_customer_downloads' ) )
    ? tejcart_get_customer_downloads( $customer_id, (int) $order->get_id() )
    : array();
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: order number, 2: order date */
            __( 'Your order <strong>#%1$s</strong> placed on <strong>%2$s</strong> has been completed. Your order details are shown below for your reference.', 'tejcart' ),
            esc_html( $order_number ),
            esc_html( $order_date )
        )
    );
    ?>
</p>

<?php if ( ! empty( $downloads ) ) : ?>
    <h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
        <?php esc_html_e( 'Downloads', 'tejcart' ); ?>
    </h3>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="nx-table-row nx-text" style="<?php echo esc_attr( $nx_table_style ); ?>">
        <thead>
            <tr>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_right ); ?>"><?php esc_html_e( 'Download', 'tejcart' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $downloads as $download ) :
                $download_name = $download->get_product_name();
                $download_url  = $download->get_url();
            ?>
                <tr class="nx-table-row">
                    <td style="<?php echo esc_attr( $nx_td_style ); ?>"><?php echo esc_html( $download_name ); ?></td>
                    <td style="<?php echo esc_attr( $nx_tdr_style ); ?>">
                        <a href="<?php echo esc_url( $download_url ); ?>" class="nx-link" style="<?php echo esc_attr( $nx_link_style ); ?>">
                            <?php esc_html_e( 'Download', 'tejcart' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Order details', 'tejcart' ); ?>
</h3>

<?php include __DIR__ . '/parts/order-items.php'; ?>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Billing address', 'tejcart' ); ?>
</h3>
<?php if ( $billing_address ) : ?>
    <address class="nx-text" style="<?php echo esc_attr( $nx_addr_style ); ?>"><?php echo wp_kses_post( $billing_address ); ?></address>
<?php else : ?>
    <p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>"><?php esc_html_e( 'N/A', 'tejcart' ); ?></p>
<?php endif; ?>

<?php if ( $shipping_address && method_exists( $order, 'shipping_matches_billing' ) && ! $order->shipping_matches_billing() ) : ?>
    <h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
        <?php esc_html_e( 'Shipping address', 'tejcart' ); ?>
    </h3>
    <address class="nx-text" style="<?php echo esc_attr( $nx_addr_style ); ?>"><?php echo wp_kses_post( $shipping_address ); ?></address>
<?php endif; ?>

<p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
    <?php esc_html_e( 'Thank you for shopping with us!', 'tejcart' ); ?>
</p>
