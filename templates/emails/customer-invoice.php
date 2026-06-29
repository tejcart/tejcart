<?php
/**
 * Customer invoice / pay-link email template (gold-grade).
 *
 * Manual resend of the order summary. When the order is still awaiting
 * payment, $pay_url renders a bulletproof pay-now CTA. Header and
 * footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var \TejCart\Order\Order $order
 * @var string               $pay_url Pay-now URL, empty when paid.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$order_number = $order->get_order_number();
$order_date   = $order->get_date_created();
$pay_url      = isset( $pay_url ) ? (string) $pay_url : '';
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: order number, 2: date */
            __( 'Here is a copy of your order <strong>#%1$s</strong>, placed on <strong>%2$s</strong>.', 'tejcart' ),
            esc_html( $order_number ),
            esc_html( $order_date )
        )
    );
    ?>
</p>

<?php
if ( '' !== $pay_url && function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( $pay_url, __( 'Pay for this order', 'tejcart' ) );
}
?>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Order details', 'tejcart' ); ?>
</h3>

<?php include __DIR__ . '/parts/order-items.php'; ?>
