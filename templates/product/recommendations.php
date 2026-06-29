<?php
/**
 * Recommendations template — "Customers also bought".
 *
 * Displays a product strip driven by the co-occurrence index. Falls
 * back to category/attribute matches for new stores without enough
 * order data.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The current product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\TejCart\\Product\\Recommendations' ) ) {
    return;
}

$recommendations = new \TejCart\Product\Recommendations();
$recommended_ids = $recommendations->get_also_bought( $product->get_id(), 4 );

if ( empty( $recommended_ids ) ) {
    return;
}

$recommended_products = \TejCart\Product\Product_Factory::get_products( $recommended_ids );

if ( empty( $recommended_products ) ) {
    return;
}

/**
 * Filter the "Customers also bought" heading text.
 *
 * @param string $title      The section heading.
 * @param int    $product_id The product being viewed.
 */
$section_title = (string) apply_filters(
    'tejcart_recommendations_title',
    __( 'Customers also bought', 'tejcart' ),
    $product->get_id()
);
?>

<div class="tejcart-recommendations tejcart-also-bought">

    <h3 class="tejcart-recommendations-title"><?php echo esc_html( $section_title ); ?></h3>

    <div class="tejcart-product-grid">
        <?php foreach ( $recommended_ids as $rec_id ) :
            $rec_product = $recommended_products[ $rec_id ] ?? null;
            if ( ! $rec_product ) {
                continue;
            }
            $product_backup = $product;
            $product        = $rec_product;
            $context        = 'recommendation';
            include __DIR__ . '/product-box.php';
            $product = $product_backup;
            unset( $context );
        endforeach; ?>
    </div>

</div>
