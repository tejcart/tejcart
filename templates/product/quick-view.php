<?php
/**
 * Quick-view modal body template.
 *
 * Rendered server-side via the `tejcart_quick_view` AJAX endpoint and
 * pulled into the modal by `tejcart-quick-view.js`. Themes can override
 * by placing a sibling `product/quick-view.php` under `tejcart/templates/`.
 *
 * Available variables:
 *   - $product  TejCart\Product\Product_Types\Abstract_Product
 *
 * @package TejCart\Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var \TejCart\Product\Product_Types\Abstract_Product $product */
if ( ! isset( $product ) || ! $product instanceof \TejCart\Product\Product_Types\Abstract_Product ) {
    return;
}

$product_id    = (int) $product->get_id();
$name          = (string) $product->get_name();
$short         = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
$permalink     = method_exists( $product, 'get_permalink' ) ? (string) $product->get_permalink() : '';
$is_in_stock   = method_exists( $product, 'is_in_stock' ) ? (bool) $product->is_in_stock() : true;
$image_id      = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;

$price         = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0;
$regular_price = method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : 0;
$is_on_sale    = method_exists( $product, 'is_on_sale' ) ? (bool) $product->is_on_sale() : false;

$manage_stock   = $product->get_manage_stock();
$stock_quantity = $product->get_stock_quantity();

$low_stock_threshold = (int) apply_filters( 'tejcart_low_stock_threshold', 5, $product );
$show_low_stock      = $is_in_stock
    && $manage_stock
    && null !== $stock_quantity
    && $stock_quantity > 0
    && $stock_quantity <= $low_stock_threshold;

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

$is_variable       = $product instanceof \TejCart\Product\Product_Types\Variable_Product;
$qv_variation_attrs = array();
$qv_variation_map   = array();

if ( $is_variable ) {
    foreach ( $product->get_attributes() as $attr ) {
        if ( ! empty( $attr['used_for_variations'] ) && ! empty( $attr['name'] ) && ! empty( $attr['values'] ) ) {
            $qv_variation_attrs[] = $attr;
        }
    }

    foreach ( $product->get_variations() as $variation ) {
        $v_price      = $variation->get_price();
        $v_regular    = $variation->get_regular_price();
        $v_is_on_sale = $variation->is_on_sale();
        $v_image_id   = method_exists( $variation, 'get_image_id' ) ? (int) $variation->get_image_id() : 0;
        $v_image_url  = $v_image_id ? wp_get_attachment_image_url( $v_image_id, 'large' ) : '';

        $qv_variation_map[] = array(
            'id'             => (int) $variation->get_id(),
            'attributes'     => $variation->get_attributes(),
            'in_stock'       => $variation->is_in_stock(),
            'is_purchasable' => $variation->is_purchasable(),
            'price'          => '' !== $v_price ? (float) $v_price : null,
            'regular_price'  => '' !== $v_regular ? (float) $v_regular : null,
            'is_on_sale'     => (bool) $v_is_on_sale,
            'price_html'     => tejcart_price( '' !== $v_price ? (float) $v_price : 0 ),
            'image_url'      => $v_image_url ? esc_url_raw( $v_image_url ) : '',
        );
    }

    if ( empty( $qv_variation_attrs ) && ! empty( $qv_variation_map ) ) {
        $derived = array();
        foreach ( $qv_variation_map as $entry ) {
            foreach ( (array) $entry['attributes'] as $aname => $avalue ) {
                if ( '' === $aname || '' === $avalue ) { continue; }
                if ( ! isset( $derived[ $aname ] ) ) { $derived[ $aname ] = array(); }
                if ( ! in_array( $avalue, $derived[ $aname ], true ) ) { $derived[ $aname ][] = $avalue; }
            }
        }
        foreach ( $derived as $aname => $avalues ) {
            $qv_variation_attrs[] = array(
                'name'                => $aname,
                'values'              => $avalues,
                'used_for_variations' => true,
            );
        }
    }

    if ( ! empty( $qv_variation_map ) && method_exists( $product, 'get_price_range' ) ) {
        $range = $product->get_price_range();
        if ( is_array( $range ) && isset( $range[0], $range[1] ) && (float) $range[0] !== (float) $range[1] ) {
            $price         = (float) $range[0];
            $regular_price = (float) $range[1];
            $is_on_sale    = false;
        }
    }
}
?>

<div class="tejcart-quick-view-body" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
    <div class="tejcart-quick-view-body__media">
        <?php if ( $image_id && function_exists( 'wp_get_attachment_image' ) ) : ?>
            <?php
            echo wp_get_attachment_image(
                $image_id,
                'large',
                false,
                array(
                    'class'    => 'tejcart-quick-view-body__image',
                    'loading'  => 'eager',
                    'decoding' => 'async',
                )
            );
            ?>
        <?php else : ?>
            <div class="tejcart-quick-view-body__image-placeholder" aria-hidden="true"
                 data-placeholder-text="<?php esc_attr_e( 'No image', 'tejcart' ); ?>"></div>
        <?php endif; ?>

        <?php if ( $is_on_sale && $discount_percent > 0 ) : ?>
            <span class="tejcart-quick-view-body__sale-badge" aria-label="<?php esc_attr_e( 'On sale', 'tejcart' ); ?>">
                <?php echo esc_html( '-' . $discount_percent . '%' ); ?>
            </span>
        <?php elseif ( $is_on_sale ) : ?>
            <span class="tejcart-quick-view-body__sale-badge" aria-label="<?php esc_attr_e( 'On sale', 'tejcart' ); ?>">
                <?php esc_html_e( 'Sale', 'tejcart' ); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="tejcart-quick-view-body__info">
        <?php if ( $primary_category ) : ?>
            <span class="tejcart-quick-view-body__category">
                <?php echo esc_html( $primary_category->name ); ?>
            </span>
        <?php endif; ?>

        <h2 class="tejcart-quick-view-body__title">
            <?php if ( '' !== $permalink ) : ?>
                <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a>
            <?php else : ?>
                <?php echo esc_html( $name ); ?>
            <?php endif; ?>
        </h2>

        <div class="tejcart-quick-view-body__price" data-tejcart-qv-price>
            <?php if ( $is_variable && ! empty( $qv_variation_map ) ) : ?>
                <?php
                $qv_range = method_exists( $product, 'get_price_range' ) ? $product->get_price_range() : null;
                if ( is_array( $qv_range ) && isset( $qv_range[0], $qv_range[1] ) && (float) $qv_range[0] !== (float) $qv_range[1] ) : ?>
                    <span class="tejcart-price tejcart-price-range">
                        <?php echo wp_kses_post( tejcart_product_price_html( (float) $qv_range[0] ) ); ?>
                        <span class="tejcart-price-range-sep" aria-hidden="true"> &ndash; </span>
                        <?php echo wp_kses_post( tejcart_product_price_html( (float) $qv_range[1] ) ); ?>
                    </span>
                <?php else : ?>
                    <span class="tejcart-price"><?php echo wp_kses_post( tejcart_product_price_html( $price ) ); ?></span>
                <?php endif; ?>
            <?php elseif ( $is_on_sale && $regular_price > 0 && $price < $regular_price ) : ?>
                <span class="tejcart-price-group" aria-label="<?php echo esc_attr( tejcart_format_price_aria( (float) $price, (float) $regular_price ) ); ?>">
                    <span class="tejcart-price-sale"><?php echo wp_kses_post( tejcart_product_price_html( $price ) ); ?></span>
                    <s class="tejcart-price-regular" aria-hidden="true"><?php echo wp_kses_post( tejcart_product_price_html( $regular_price ) ); ?></s>
                </span>
            <?php elseif ( $price > 0 ) : ?>
                <span class="tejcart-price"><?php echo wp_kses_post( tejcart_product_price_html( $price ) ); ?></span>
            <?php endif; ?>
        </div>

        <?php if ( ! $is_in_stock ) : ?>
            <p class="tejcart-quick-view-body__stock tejcart-quick-view-body__stock--out">
                <?php esc_html_e( 'Out of stock', 'tejcart' ); ?>
            </p>
        <?php elseif ( $show_low_stock ) : ?>
            <p class="tejcart-quick-view-body__stock tejcart-quick-view-body__stock--low">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: remaining stock count */
                    _n( 'Only %d left in stock', 'Only %d left in stock', (int) $stock_quantity, 'tejcart' ),
                    (int) $stock_quantity
                ) );
                ?>
            </p>
        <?php else : ?>
            <p class="tejcart-quick-view-body__stock tejcart-quick-view-body__stock--in">
                <?php esc_html_e( 'In stock', 'tejcart' ); ?>
            </p>
        <?php endif; ?>

        <?php if ( '' !== trim( $short ) ) : ?>
            <hr class="tejcart-quick-view-body__divider" aria-hidden="true">
            <div class="tejcart-quick-view-body__excerpt">
                <?php echo wp_kses_post( wpautop( $short ) ); ?>
            </div>
        <?php endif; ?>

        <?php
        /**
         * Fires inside the quick-view modal, before the add-to-cart form.
         *
         * @param \TejCart\Product\Product_Types\Abstract_Product $product
         */
        do_action( 'tejcart_quick_view_before_add_to_cart', $product );
        ?>

        <?php if ( $is_variable && ! empty( $qv_variation_attrs ) ) : ?>
            <div class="tejcart-quick-view-body__variations tejcart-variations"
                 data-tejcart-variations
                 data-product-id="<?php echo esc_attr( $product_id ); ?>"
                 data-variations="<?php echo esc_attr( wp_json_encode( $qv_variation_map ) ); ?>">
                <?php foreach ( $qv_variation_attrs as $attr ) :
                    $attr_name = (string) $attr['name'];
                    $attr_key  = sanitize_key( $attr_name );
                    $field_id  = 'tejcart-qv-attr-' . $product_id . '-' . $attr_key;
                    ?>
                    <?php
                    ob_start();
                    ?>
                        <select
                            id="<?php echo esc_attr( $field_id ); ?>"
                            name="attribute_<?php echo esc_attr( $attr_key ); ?>"
                            class="tejcart-variation-select"
                            data-tejcart-variation-select
                            data-attribute-name="<?php echo esc_attr( $attr_name ); ?>"
                        >
                            <option value=""><?php esc_html_e( 'Choose an option', 'tejcart' ); ?></option>
                            <?php foreach ( (array) $attr['values'] as $value ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $value ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php
                    $qv_default_input = (string) ob_get_clean();

                    /** This filter is documented in templates/product/single-product.php */
                    $qv_variation_input_html = (string) apply_filters(
                        'tejcart_variation_attribute_input',
                        $qv_default_input,
                        $attr,
                        $field_id,
                        $attr_key,
                        $product
                    );
                    ?>
                    <div class="tejcart-variation-option">
                        <label for="<?php echo esc_attr( $field_id ); ?>" class="tejcart-variation-label">
                            <?php echo esc_html( $attr_name ); ?>
                        </label>
                        <?php echo tejcart_kses_form_control( $qv_variation_input_html ); ?>
                    </div>
                <?php endforeach; ?>
                <p class="tejcart-variation-error" data-tejcart-variation-error hidden role="alert"></p>
                <button type="button" class="tejcart-variation-clear" data-tejcart-variation-clear hidden>
                    <?php esc_html_e( 'Clear', 'tejcart' ); ?>
                </button>
                <p class="tejcart-variation-price" data-tejcart-variation-price role="status" aria-live="polite" hidden></p>
            </div>
        <?php endif; ?>

        <div class="tejcart-quick-view-body__actions">
            <?php
            tejcart_get_template(
                'product/add-to-cart-button.php',
                array(
                    'product'            => $product,
                    'variation_required' => $is_variable && ! empty( $qv_variation_attrs ),
                )
            );
            ?>

            <?php if ( '' !== $permalink ) : ?>
                <a class="tejcart-quick-view-body__details-link" href="<?php echo esc_url( $permalink ); ?>">
                    <?php esc_html_e( 'View full details', 'tejcart' ); ?>
                </a>
            <?php endif; ?>
        </div>

        <?php
        /**
         * Fires inside the quick-view modal, after the add-to-cart form.
         *
         * @param \TejCart\Product\Product_Types\Abstract_Product $product
         */
        do_action( 'tejcart_quick_view_after_add_to_cart', $product );
        ?>
    </div>
</div>
