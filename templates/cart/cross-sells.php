<?php
/**
 * Cross-sell Products template.
 *
 * Displays cross-sell products below the cart table, gathered from
 * all products currently in the cart.
 *
 * @package TejCart\Templates\Cart
 *
 * @var \TejCart\Cart\Cart $cart The cart instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cart_items    = $cart->get_items();
$crosssell_ids = array();
$cart_product_ids = array();

foreach ( $cart_items as $item ) {
    $cart_product_id    = $item->get_product_id();
    $cart_product_ids[] = $cart_product_id;

    $cart_product = \TejCart\Product\Product_Factory::get_product( $cart_product_id );

    if ( $cart_product ) {
        $crosssell_ids = array_merge( $crosssell_ids, $cart_product->get_crosssell_ids() );
    }
}

$crosssell_ids = array_unique( $crosssell_ids );
$crosssell_ids = array_diff( $crosssell_ids, $cart_product_ids );
$crosssell_ids = array_slice( $crosssell_ids, 0, 4 );

if ( empty( $crosssell_ids ) ) {
    return;
}

$crosssell_products = \TejCart\Product\Product_Factory::get_products( $crosssell_ids );

if ( empty( $crosssell_products ) ) {
    return;
}
?>

<div class="tejcart-cross-sells">

    <h3 class="tejcart-cross-sells-title"><?php esc_html_e( 'Frequently bought together', 'tejcart' ); ?></h3>
    <p class="tejcart-cross-sells-subtitle"><?php esc_html_e( 'Customers who bought what’s in your bag also picked these up.', 'tejcart' ); ?></p>

    <div class="tejcart-product-grid">
        <?php foreach ( $crosssell_ids as $crosssell_id ) :
            $product = $crosssell_products[ $crosssell_id ] ?? null;
            if ( ! $product ) {
                continue;
            }
            $context = 'cross-sell';
            include plugin_dir_path( __DIR__ ) . 'product/product-box.php';
            unset( $context );
        endforeach; ?>
    </div>

</div>
