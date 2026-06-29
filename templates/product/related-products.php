<?php
/**
 * Related Products template.
 *
 * Displays a grid of related products based on shared categories.
 * Hooked into the `tejcart_after_product_box` action.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The current product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$related_ids = $product->get_related_products( 4 );

if ( empty( $related_ids ) ) {
    return;
}

$related_products = \TejCart\Product\Product_Factory::get_products( $related_ids );

if ( empty( $related_products ) ) {
    return;
}
?>

<div class="tejcart-related-products">

    <h3 class="tejcart-related-products-title"><?php esc_html_e( 'Related products', 'tejcart' ); ?></h3>

    <div class="tejcart-product-grid">
        <?php foreach ( $related_ids as $related_id ) :
            $related_product = $related_products[ $related_id ] ?? null;
            if ( ! $related_product ) {
                continue;
            }
            $product_backup = $product;
            $product        = $related_product;
            $context        = 'related';
            include __DIR__ . '/product-box.php';
            $product = $product_backup;
            unset( $context );
        endforeach; ?>
    </div>

</div>
