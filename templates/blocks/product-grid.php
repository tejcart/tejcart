<?php
/**
 * Shared product grid template for discovery blocks.
 *
 * Renders a responsive product grid reusing the existing
 * product-box card template for each item.
 *
 * @package TejCart\Templates\Blocks
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product[] $products   Array of product instances.
 * @var int                                                $columns    Number of columns (2-4).
 * @var string                                             $block_name Block identifier for CSS scoping.
 * @var string                                             $heading    Optional heading text.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $products ) ) {
    return;
}

$columns    = isset( $columns ) ? (int) $columns : 4;
$block_name = isset( $block_name ) ? sanitize_html_class( $block_name ) : 'discovery';
$heading    = isset( $heading ) ? (string) $heading : '';

$wrapper_classes = array(
    'tejcart-block-grid',
    'tejcart-block-grid--' . $block_name,
);
?>
<div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" style="--tejcart-block-columns: <?php echo esc_attr( (string) $columns ); ?>">
    <ul class="tejcart-product-grid tejcart-block-grid__list" role="list">
        <?php
        $index = 0;
        foreach ( $products as $product ) :
            ?>
            <li class="tejcart-block-grid__item">
                <?php
                tejcart_get_template( 'product/product-box.php', array(
                    'product'          => $product,
                    'is_lcp_candidate' => 0 === $index,
                    'context'          => 'block',
                ) );
                ?>
            </li>
            <?php
            $index++;
        endforeach;
        ?>
    </ul>
</div>
