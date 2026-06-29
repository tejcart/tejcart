<?php
/**
 * Reviews tab body.
 *
 * Thin wrapper that defers to product-reviews.php so themes that already
 * override the reviews template keep working unchanged.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product    Product instance.
 * @var int                                             $product_id Product ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

tejcart_get_template(
    'product/product-reviews.php',
    array( 'product_id' => (int) $product_id )
);
