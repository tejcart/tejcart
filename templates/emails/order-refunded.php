<?php
/**
 * Order refunded email template (gold-grade, table-based, inline styles).
 *
 * Sent to the customer when their order status changes to refunded.
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
$payment_method   = $order->get_payment_method_title();
$billing_address  = $order->get_formatted_billing_address();
$shipping_address = $order->get_formatted_shipping_address();

$nx_total_label = __( 'Refund total', 'tejcart' );
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: order number, 2: order date */
            __( 'Your order <strong>#%1$s</strong> placed on <strong>%2$s</strong> has been refunded. The refund details are shown below.', 'tejcart' ),
            esc_html( $order_number ),
            esc_html( $order_date )
        )
    );
    ?>
</p>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Refund details', 'tejcart' ); ?>
</h3>

<?php include __DIR__ . '/parts/order-items.php'; ?>

<?php if ( $payment_method ) : ?>
    <p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: payment method */
                __( 'The refund will be returned via your original payment method: <strong>%s</strong>.', 'tejcart' ),
                esc_html( $payment_method )
            )
        );
        ?>
    </p>
<?php endif; ?>

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
    <?php esc_html_e( 'If you have any questions about this refund, please contact us.', 'tejcart' ); ?>
</p>
