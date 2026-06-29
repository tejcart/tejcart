<?php
/**
 * Order on-hold email template (gold-grade, table-based, inline styles).
 *
 * Sent to the customer when their order status changes to on-hold.
 * Header and footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var \TejCart\Order\Order $order
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$order_number = $order->get_order_number();
$order_date   = $order->get_date_created();
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: order number, 2: order date */
            __( 'Your order <strong>#%1$s</strong> placed on <strong>%2$s</strong> has been placed on hold. We will contact you if we need any further information &mdash; there is nothing you need to do right now.', 'tejcart' ),
            esc_html( $order_number ),
            esc_html( $order_date )
        )
    );
    ?>
</p>

<h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
    <?php esc_html_e( 'Order summary', 'tejcart' ); ?>
</h3>

<?php include __DIR__ . '/parts/order-items.php'; ?>
