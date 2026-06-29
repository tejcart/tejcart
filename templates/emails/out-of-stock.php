<?php
/**
 * Out-of-stock alert email template (gold-grade, inline styles).
 *
 * Sent to the store admin when a product's stock_quantity reaches zero.
 * Header and footer are supplied by Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var int    $product_id
 * @var string $product_name
 * @var string $sku
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$edit_url = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $product_id );
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo wp_kses_post(
        sprintf(
            /* translators: 1: product name, 2: SKU */
            __( 'Product <strong>%1$s</strong> (SKU: %2$s) is now <strong>out of stock</strong>. Restock the product or mark it unavailable so customers do not place orders that cannot be fulfilled.', 'tejcart' ),
            esc_html( (string) $product_name ),
            esc_html( $sku ? (string) $sku : __( 'N/A', 'tejcart' ) )
        )
    );
    ?>
</p>

<?php
if ( function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( $edit_url, __( 'View product in admin', 'tejcart' ) );
}
?>
