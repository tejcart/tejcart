<?php
/**
 * Abandoned cart recovery email template (gold-grade, table-based,
 * inline styles).
 *
 * Sent to customers who left items in their cart. Header and footer
 * are supplied by Abstract_Email::send() — this template emits the
 * body content only.
 *
 * @package TejCart\Templates\Emails
 *
 * @var string $recovery_url  The cart recovery URL.
 * @var string $template_key  Sequence step: 'first', 'second', 'final'.
 * @var array  $cart_items    Cart items array.
 * @var float  $cart_total    Cart total.
 * @var string $currency      Currency code.
 * @var array  $row           Full abandoned cart row.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $nx_*_style values are static, pre-built attribute payloads.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

switch ( $template_key ) {
    case 'second':
        $intro_text = __( 'We noticed you still have items in your cart. They\'re reserved for you — complete your purchase before they\'re gone.', 'tejcart' );
        break;
    case 'final':
        $intro_text = __( 'This is your last reminder — your cart will expire soon. Don\'t miss out on the items you selected.', 'tejcart' );
        break;
    default:
        $intro_text = __( 'You left some great items in your cart. We\'ve saved them for you — click below to pick up where you left off.', 'tejcart' );
        break;
}
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php echo esc_html( $intro_text ); ?>
</p>

<?php if ( ! empty( $cart_items ) ) : ?>
    <h3 class="nx-h2 nx-text" style="<?php echo esc_attr( $nx_h3_style ); ?>">
        <?php esc_html_e( 'Items in your cart', 'tejcart' ); ?>
    </h3>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="nx-text" style="<?php echo esc_attr( $nx_table_style ); ?>">
        <thead>
            <tr>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_center ); ?>"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ( $cart_items as $item ) :
                $product_id   = (int) ( $item['product_id'] ?? 0 );
                $quantity     = (int) ( $item['quantity'] ?? 1 );
                $product_name = $product_id > 0 ? (string) get_the_title( $product_id ) : '';
                if ( '' === $product_name ) {
                    /* translators: %d: product ID */
                    $product_name = sprintf( __( 'Product #%d', 'tejcart' ), $product_id );
                }
                ?>
                <tr class="nx-table-row" style="border-bottom:1px solid #e5e7eb;">
                    <td class="nx-text" style="<?php echo esc_attr( $nx_td_style ); ?>"><?php echo esc_html( $product_name ); ?></td>
                    <td class="nx-text" style="<?php echo esc_attr( $nx_tdc_style ); ?>"><?php echo esc_html( (string) $quantity ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ( $cart_total > 0 ) : ?>
            <tfoot>
                <tr>
                    <td style="<?php echo esc_attr( $nx_tot_label ); ?>"><?php esc_html_e( 'Total', 'tejcart' ); ?></td>
                    <td style="<?php echo esc_attr( $nx_tot_value ); ?>">
                        <?php
                        echo wp_kses_post(
                            function_exists( 'tejcart_price' )
                                ? tejcart_price( $cart_total, $currency )
                                : number_format( $cart_total, 2 )
                        );
                        ?>
                    </td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
<?php endif; ?>

<?php
if ( '' !== $recovery_url && function_exists( 'tejcart_email_button' ) ) {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_email_button() handles its own escaping internally.
    echo tejcart_email_button( $recovery_url, __( 'Complete your purchase', 'tejcart' ) );
}
?>

<p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
    <?php esc_html_e( 'If you have any questions, simply reply to this email.', 'tejcart' ); ?>
</p>
