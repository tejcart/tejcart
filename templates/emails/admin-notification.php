<?php
/**
 * Admin new-order notification email template (gold-grade, table-based,
 * inline styles).
 *
 * Sent to the store admin when a new order is placed. Header and
 * footer are supplied by Abstract_Email::send().
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
$billing_email    = $order->get_billing_email();
$payment_method   = $order->get_payment_method_title();
$billing_address  = $order->get_formatted_billing_address();
$shipping_address = $order->get_formatted_shipping_address();
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: order number, 2: order date */
            __( 'A new order <strong>#%1$s</strong> has been placed on <strong>%2$s</strong>.', 'tejcart' ),
            esc_html( $order_number ),
            esc_html( $order_date )
        )
    );
    ?>
</p>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Order details', 'tejcart' ); ?>
</h3>

<?php include __DIR__ . '/parts/order-items.php'; ?>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Customer information', 'tejcart' ); ?>
</h3>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" class="nx-table-row nx-text" style="<?php echo esc_attr( $nx_table_style ); ?>">
    <tr>
        <td style="<?php echo esc_attr( $nx_th_style ); ?>width:38%;"><?php esc_html_e( 'Email', 'tejcart' ); ?></td>
        <td style="<?php echo esc_attr( $nx_td_style ); ?>">
            <a href="mailto:<?php echo esc_attr( $billing_email ); ?>" class="nx-link" style="<?php echo esc_attr( $nx_link_style ); ?>"><?php echo esc_html( $billing_email ); ?></a>
        </td>
    </tr>
    <tr>
        <td style="<?php echo esc_attr( $nx_th_style ); ?>width:38%;"><?php esc_html_e( 'Payment method', 'tejcart' ); ?></td>
        <td style="<?php echo esc_attr( $nx_td_style ); ?>"><?php echo esc_html( $payment_method ); ?></td>
    </tr>
</table>

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
