<?php
/**
 * Customer note email template (gold-grade, table-based, inline styles).
 *
 * Sent to the customer when the merchant adds a customer-visible note
 * to their order. Header and footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var \TejCart\Order\Order $order     The order instance.
 * @var string               $note_body Plain-text body of the note.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$order_number = $order->get_order_number();
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: %s: order number */
            __( 'A note has been added to your order <strong>#%s</strong>:', 'tejcart' ),
            esc_html( $order_number )
        )
    );
    ?>
</p>

<blockquote class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>margin:16px 0;padding:12px 16px;border-left:4px solid #d0d7de;background:#f6f8fa;">
    <?php echo nl2br( esc_html( $note_body ) ); ?>
</blockquote>

<p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
    <?php esc_html_e( 'If you have any questions, simply reply to this email.', 'tejcart' ); ?>
</p>
