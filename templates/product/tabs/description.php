<?php
/**
 * Description tab body.
 *
 * Rendered inside the Description tab panel of the single-product page.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tejcart_description = method_exists( $product, 'get_description' )
    ? (string) $product->get_description()
    : '';

if ( '' === trim( $tejcart_description ) ) {
    return;
}
?>
<div class="tejcart-single-product-description-body">
    <?php

    echo wp_kses_post( wpautop( $tejcart_description, false ) );
    ?>
</div>
