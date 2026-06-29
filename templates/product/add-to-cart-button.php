<?php
/**
 * Add to cart button template.
 *
 * Renders a button to add a product to the cart, with product ID as a
 * data attribute and a per-product nonce for security. The tejcart-cart.js
 * click handler intercepts the click, POSTs to wp-ajax, and flips the
 * button into its confirmed state for ~2 seconds on success.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$product_id     = $product->get_id();
$product_name   = $product->get_name();
$is_in_stock    = $product->is_in_stock();
$is_purchasable = $product->is_purchasable();

if ( $product instanceof \TejCart\Product\Product_Types\External_Product ) {
    $external_url = $product->get_product_url();
    if ( $external_url ) {
        printf(
            '<a class="tejcart-button tejcart-button--block tejcart-button--primary tejcart-external-product-btn" href="%s" target="_blank" rel="noopener noreferrer sponsored" aria-label="%s">%s</a>',
            esc_url( $external_url ),
            esc_attr( sprintf(
                /* translators: %s: product name. */
                __( 'Buy %s on the external site', 'tejcart' ),
                $product_name
            ) ),
            esc_html( $product->get_button_text() )
        );
    }
    return;
}

if ( $product instanceof \TejCart\Product\Product_Types\Grouped_Product ) {
    include __DIR__ . '/grouped-add-to-cart.php';
    return;
}

if ( $product instanceof \TejCart\Product\Product_Types\Variable_Product
    && empty( $variation_required ) ) {
    $product_permalink = $product->get_permalink();
    if ( $product_permalink ) {
        $select_label = apply_filters(
            'tejcart_select_options_button_text',
            __( 'Select options', 'tejcart' ),
            $product
        );
        printf(
            '<a class="tejcart-button tejcart-button--block tejcart-select-options-btn" href="%s" aria-label="%s">%s</a>',
            esc_url( $product_permalink ),
            esc_attr( sprintf(
                /* translators: %s: product name. */
                __( 'Select options for %s', 'tejcart' ),
                $product->get_name()
            ) ),
            esc_html( $select_label )
        );
        return;
    }
}

/**
 * Filters the add-to-cart button text.
 *
 * @param string $text    The button text.
 * @param object $product The product instance.
 */
$button_text = apply_filters( 'tejcart_add_to_cart_button_text', __( 'Add to cart', 'tejcart' ), $product );

/**
 * Filters the add-to-cart confirmed state text ("Added ✓").
 *
 * @param string $text    The confirmed text.
 * @param object $product The product instance.
 */
$confirmed_text = apply_filters( 'tejcart_add_to_cart_confirmed_text', __( 'Added', 'tejcart' ), $product );

$button_classes = array( 'tejcart-button', 'tejcart-button--block', 'tejcart-add-to-cart-btn' );

$needs_variation = ! empty( $variation_required );

if ( ! $is_in_stock || ! $is_purchasable ) {
    $button_classes[] = 'is-disabled';
} elseif ( $needs_variation ) {
    $button_classes[] = 'is-disabled';
}
?>

<button
    type="button"
    class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>"
    data-product-id="<?php echo esc_attr( $product_id ); ?>"
    data-quantity="<?php echo esc_attr( isset( $quantity ) ? absint( $quantity ) : 1 ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'tejcart_add_to_cart_' . $product_id ) ); ?>"
    data-confirmed-text="<?php echo esc_attr( $confirmed_text ); ?>"
    data-default-text="<?php echo esc_attr( $button_text ); ?>"
    <?php if ( $needs_variation ) : ?>data-variation-required="1" aria-disabled="true"<?php endif; ?>
    aria-label="<?php
    /* translators: %s: product name. */
    echo esc_attr( sprintf( __( 'Add %s to cart', 'tejcart' ), $product_name ) );
    ?>"
    <?php if ( ! $is_in_stock || ! $is_purchasable ) : ?>disabled<?php endif; ?>
>
    <span class="tejcart-add-to-cart-label"><?php echo esc_html( $button_text ); ?></span>
</button>
