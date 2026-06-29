<?php
/**
 * Product display card template.
 *
 * Renders a product box with image, name, short description,
 * price (with sale strikethrough), stock badges, and add-to-cart button.
 * Used by the [tejcart_product], [tejcart_products] shortcodes and the
 * shop / cross-sells / related / upsells templates.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 * @var bool $is_lcp_candidate Optional. When true, the primary image loads eagerly
 *     with `fetchpriority="high"` — used for the first card in a catalog grid to
 *     optimise LCP. Defaults to false.
 * @var string $context Optional. Where the box is rendered: 'shop', 'related',
 *     'upsell', 'cross-sell', 'search', etc. Used to scope merchandising
 *     decisions (e.g. the Featured badge is suppressed in related/upsell rails
 *     so it does not compete with the recommendation itself). Defaults to 'shop'.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_lcp_candidate = isset( $is_lcp_candidate ) ? (bool) $is_lcp_candidate : false;
$context          = isset( $context ) && '' !== $context ? (string) $context : 'shop';

$product_id      = $product->get_id();
$product_name    = $product->get_name();
$product_url     = $product->get_permalink();
$short_desc      = $product->get_short_description();
$image_id        = (int) $product->get_image_id();
$gallery_ids     = method_exists( $product, 'get_gallery_ids' )
    ? array_values( array_filter( array_map( 'intval', (array) $product->get_gallery_ids() ) ) )
    : array();

/*
 * Determine the image shown in the card's primary slot. Prefer the featured
 * image, but fall back to the first gallery image when no featured image is
 * set — this mirrors the single-product gallery (templates/product/product-gallery.php),
 * which also promotes gallery[0] to the main image. Without this fallback a
 * product that has gallery images but no featured image renders the
 * "No image available" placeholder in the loop while the real image only
 * appears as the hover swap.
 */
$primary_image_id = $image_id;
if ( $primary_image_id <= 0 && ! empty( $gallery_ids ) ) {
    $primary_image_id = (int) $gallery_ids[0];
}

$hover_image_id  = 0;
foreach ( $gallery_ids as $gallery_id ) {
    if ( $gallery_id > 0 && $gallery_id !== $primary_image_id ) {
        $hover_image_id = $gallery_id;
        break;
    }
}
$price           = (float) $product->get_price();
$regular_price   = (float) $product->get_regular_price();
$sale_price      = $product->get_sale_price();
$is_on_sale      = $product->is_on_sale();
$is_in_stock     = $product->is_in_stock();
$stock_quantity  = $product->get_stock_quantity();
$manage_stock    = $product->get_manage_stock();

$primary_category = null;
if ( class_exists( '\\TejCart\\Product\\Product_Taxonomy' ) ) {
    $product_categories = \TejCart\Product\Product_Taxonomy::get_product_categories( $product_id );
    if ( ! empty( $product_categories ) && isset( $product_categories[0]->name ) ) {
        $primary_category = $product_categories[0];
    }
}

$discount_percent = 0;
if ( $is_on_sale && $regular_price > 0 && $price < $regular_price ) {
    $discount_percent = (int) floor( ( ( $regular_price - $price ) / $regular_price ) * 100 );
}

$box_classes = array( 'tejcart-product-box' );
if ( ! $is_in_stock ) {
    $box_classes[] = 'is-out-of-stock';
}

/**
 * Filter the maximum quantity at which the "Low Stock" badge appears.
 *
 * @param int                                             $threshold Default 5.
 * @param \TejCart\Product\Product_Types\Abstract_Product $product   The product instance.
 */
$low_stock_threshold = (int) apply_filters( 'tejcart_low_stock_threshold', 5, $product );

$show_low_stock = $is_in_stock
    && $manage_stock
    && null !== $stock_quantity
    && $stock_quantity > 0
    && $stock_quantity <= $low_stock_threshold;

$is_featured = (bool) $product->is_featured();

/**
 * Filter whether the Featured badge renders on a product box. Defaults
 * to suppressing the badge inside related-product, upsell, and cross-sell
 * rails because the recommendation itself is already the merchandising
 * decision — surfacing "Featured" there competes with that signal.
 *
 * @param bool                                            $show     Whether to render the badge.
 * @param \TejCart\Product\Product_Types\Abstract_Product $product  The product.
 * @param string                                          $context  The render context, e.g. 'shop' or 'related'.
 */
$is_featured = $is_featured && (bool) apply_filters(
    'tejcart_product_box_show_featured_badge',
    ! in_array( $context, array( 'related', 'upsell', 'cross-sell' ), true ),
    $product,
    $context
);

$is_grouped        = $product instanceof \TejCart\Product\Product_Types\Grouped_Product;
$grouped_prefix    = $is_grouped ? _x( 'From', 'price prefix for grouped products', 'tejcart' ) . ' ' : '';

/**
 * Fires before the product box.
 *
 * @param \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 */
do_action( 'tejcart_before_product_box', $product );
?>

<article class="<?php echo esc_attr( implode( ' ', $box_classes ) ); ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>">

    <?php
    /*
     * F-FE-006: Two adjacent links to the same $product_url cause screen readers to
     * announce "View {name}" then "{name}" as separate focusable items (WCAG 2.4.4).
     * The image link is purely presentational — the <h3> link is the named navigation
     * target. Set tabindex="-1" and aria-hidden="true" on the image link so keyboard
     * and AT users only encounter the title link.
     */
    ?>
    <a class="tejcart-product-box-image<?php echo $hover_image_id ? ' has-hover-image' : ''; ?>" href="<?php echo esc_url( $product_url ); ?>" tabindex="-1" aria-hidden="true">
        <?php
        $tejcart_primary_img_attrs = array(
            'alt'      => esc_attr( $product_name ),
            'loading'  => $is_lcp_candidate ? 'eager' : 'lazy',
            'decoding' => 'async',
            'class'    => 'tejcart-product-box-image-primary',
        );
        if ( $is_lcp_candidate ) {
            $tejcart_primary_img_attrs['fetchpriority'] = 'high';
        }

        $tejcart_primary_img_html = $primary_image_id
            ? wp_get_attachment_image( $primary_image_id, 'tejcart-product-card', false, $tejcart_primary_img_attrs )
            : '';

        if ( '' === $tejcart_primary_img_html ) {
            $tejcart_primary_img_html = tejcart_get_placeholder_image_html(
                'tejcart-product-card',
                array_merge(
                    $tejcart_primary_img_attrs,
                    array( 'class' => trim( ( $tejcart_primary_img_attrs['class'] ?? '' ) . ' tejcart-placeholder-image' ) )
                )
            );
        }

        echo $tejcart_primary_img_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image / tejcart_get_placeholder_image_html are pre-escaped.
        ?>

        <?php if ( $hover_image_id ) : ?>
            <?php
            echo wp_get_attachment_image(
                $hover_image_id,
                'tejcart-product-card',
                false,
                array(
                    /* translators: decorative hover image; alt intentionally empty. */
                    'alt'         => '',
                    'loading'     => 'lazy',
                    'decoding'    => 'async',
                    'aria-hidden' => 'true',
                    'class'       => 'tejcart-product-box-image-hover',
                )
            );
            ?>
        <?php endif; ?>

        <?php
        /**
         * Fires inside the product card image stack, after the sale
         * badge and stock pill would render. Theme authors can hook
         * icon-buttons here (wishlist, quick-view) without touching the
         * template. Receives the product instance.
         *
         * The built-in quick-view trigger binds at priority 20 via
         * `TejCart\Frontend\Quick_View::render_trigger()`.
         *
         * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
         */
        do_action( 'tejcart_product_card_actions', $product );
        ?>

        <?php if ( $is_on_sale ) : ?>
            <?php
            /**
             * Filter the on-sale flash text rendered in the product card.
             *
             * Defaults to "Sale!". Themes that prefer the discount-percent
             * variant can return e.g. "-{$discount_percent}%" from this
             * filter.
             *
             * @param string                                          $text             The badge text.
             * @param int                                             $discount_percent The computed discount percentage (0 when none).
             * @param \TejCart\Product\Product_Types\Abstract_Product $product          The product instance.
             */
            $sale_badge_text = apply_filters(
                'tejcart_sale_badge_text',
                __( 'Sale!', 'tejcart' ),
                $discount_percent,
                $product
            );
            ?>
            <span class="tejcart-sale-badge" aria-label="<?php esc_attr_e( 'On sale', 'tejcart' ); ?>">
                <?php echo esc_html( $sale_badge_text ); ?>
            </span>
        <?php endif; ?>

        <?php if ( $is_featured ) : ?>
            <span class="tejcart-featured-badge" aria-label="<?php esc_attr_e( 'Featured product', 'tejcart' ); ?>">
                <?php esc_html_e( 'Featured', 'tejcart' ); ?>
            </span>
        <?php endif; ?>

        <?php if ( ! $is_in_stock ) : ?>
            <span class="tejcart-stock-pill tejcart-stock-pill--out">
                <?php esc_html_e( 'Sold out', 'tejcart' ); ?>
            </span>
        <?php elseif ( $show_low_stock ) : ?>
            <span class="tejcart-stock-pill tejcart-stock-pill--low">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: remaining stock count */
                    _n( 'Only %d left', 'Only %d left', (int) $stock_quantity, 'tejcart' ),
                    (int) $stock_quantity
                ) );
                ?>
            </span>
        <?php endif; ?>
    </a>

    <div class="tejcart-product-box-content">

        <?php if ( $primary_category ) : ?>
            <span class="tejcart-product-box-category">
                <?php echo esc_html( $primary_category->name ); ?>
            </span>
        <?php endif; ?>

        <h3 class="tejcart-product-box-name">
            <a href="<?php echo esc_url( $product_url ); ?>">
                <?php echo esc_html( $product_name ); ?>
            </a>
        </h3>

        <?php if ( $short_desc ) : ?>
            <p class="tejcart-product-box-description">
                <?php echo esc_html( wp_strip_all_tags( $short_desc ) ); ?>
            </p>
        <?php endif; ?>

        <div class="tejcart-product-box-price">
            <?php if ( '' !== $grouped_prefix ) : ?>
                <span class="tejcart-price-prefix"><?php echo esc_html( $grouped_prefix ); ?></span>
            <?php endif; ?>
            <?php if ( $is_on_sale ) : ?>
                <span class="tejcart-price-group" aria-label="<?php echo esc_attr( tejcart_format_price_aria( (float) $price, (float) $regular_price ) ); ?>">
                    <s aria-hidden="true" class="tejcart-price-regular"><?php echo wp_kses_post( tejcart_product_price_html( (float) $regular_price ) ); ?></s>
                    <span class="tejcart-price-sale"><?php echo wp_kses_post( tejcart_product_price_html( (float) $price ) ); ?></span>
                </span>
            <?php else : ?>
                <span class="tejcart-price"><?php echo wp_kses_post( tejcart_product_price_html( (float) $price ) ); ?></span>
            <?php endif; ?>
        </div>

        <div class="tejcart-product-box-actions">
            <?php include __DIR__ . '/add-to-cart-button.php'; ?>
        </div>

    </div>

</article>

<?php
/**
 * Fires after the product box.
 *
 * @param \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 */
do_action( 'tejcart_after_product_box', $product );
