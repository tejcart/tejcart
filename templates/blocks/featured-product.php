<?php
/**
 * Featured Product hero block template.
 *
 * Renders a full-width hero card with background image, overlay,
 * product title, price, short description, and a CTA button.
 *
 * @package TejCart\Templates\Blocks
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product          The product instance.
 * @var string                                          $image_url        Full-size image URL.
 * @var bool                                            $show_price       Whether to show the price.
 * @var bool                                            $show_description Whether to show the short description.
 * @var string                                          $button_text      CTA button label.
 * @var int                                             $min_height       Minimum block height in px.
 * @var int                                             $overlay_opacity  Overlay opacity 0-100.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$product_url   = $product->get_permalink();
$product_name  = $product->get_name();
$short_desc    = $product->get_short_description();
$is_on_sale    = $product->is_on_sale();
$price         = (float) $product->get_price();
$regular_price = (float) $product->get_regular_price();
$sale_price    = $product->get_sale_price();

$opacity_decimal = round( $overlay_opacity / 100, 2 );
$style           = sprintf(
    'min-height:%dpx;--tejcart-featured-overlay-opacity:%.2f',
    $min_height,
    $opacity_decimal
);

if ( '' !== $image_url ) {
    $style .= sprintf( ';background-image:url(%s)', esc_url( $image_url ) );
}
?>
<div class="tejcart-featured-product" style="<?php echo esc_attr( $style ); ?>">
    <div class="tejcart-featured-product__overlay"></div>
    <div class="tejcart-featured-product__content">
        <?php if ( $is_on_sale ) : ?>
            <span class="tejcart-featured-product__badge"><?php esc_html_e( 'Sale', 'tejcart' ); ?></span>
        <?php endif; ?>

        <h2 class="tejcart-featured-product__title">
            <a href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $product_name ); ?></a>
        </h2>

        <?php if ( $show_description && '' !== $short_desc ) : ?>
            <p class="tejcart-featured-product__desc"><?php echo esc_html( wp_strip_all_tags( $short_desc ) ); ?></p>
        <?php endif; ?>

        <?php if ( $show_price ) : ?>
            <div class="tejcart-featured-product__price">
                <?php if ( $is_on_sale && $regular_price > 0 ) : ?>
                    <span class="tejcart-price-group">
                        <del class="tejcart-price-regular"><?php echo esc_html( tejcart_price( $regular_price ) ); ?></del>
                        <ins class="tejcart-price-sale"><?php echo esc_html( tejcart_price( $price ) ); ?></ins>
                    </span>
                <?php else : ?>
                    <span class="tejcart-price"><?php echo esc_html( tejcart_price( $price ) ); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( $product_url ); ?>" class="tejcart-button tejcart-featured-product__cta">
            <?php echo esc_html( $button_text ); ?>
        </a>
    </div>
</div>
