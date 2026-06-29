<?php
/**
 * Upsell Products template.
 *
 * Displays "You may also like" products on the single product page.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The current product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$upsell_ids = $product->get_upsell_ids();

if ( empty( $upsell_ids ) ) {
    return;
}

$upsell_products = \TejCart\Product\Product_Factory::get_products( $upsell_ids );

if ( empty( $upsell_products ) ) {
    return;
}
?>

<div class="tejcart-upsell-products">

    <h3 class="tejcart-upsell-products-title"><?php esc_html_e( 'You may also like', 'tejcart' ); ?></h3>

    <div class="tejcart-product-grid">
        <?php foreach ( $upsell_ids as $upsell_id ) :
            $upsell_product = $upsell_products[ $upsell_id ] ?? null;
            if ( ! $upsell_product ) {
                continue;
            }
            $product_backup = $product;
            $product        = $upsell_product;
            $context        = 'upsell';
            include __DIR__ . '/product-box.php';
            $product = $product_backup;
            unset( $context );
        endforeach; ?>
    </div>

</div>
