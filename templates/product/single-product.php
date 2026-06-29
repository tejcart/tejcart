<?php
/**
 * Single product detail template.
 *
 * Rendered by the [tejcart_products] shortcode when the request carries
 * ?product=<id>. Shows the full product detail surface: gallery, name,
 * price, description, add-to-cart button, and related products.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$product_id     = $product->get_id();
$product_name   = $product->get_name();
$description    = $product->get_description();
$short_desc     = $product->get_short_description();
$price          = (float) $product->get_price();
$regular_price  = (float) $product->get_regular_price();
$is_on_sale     = $product->is_on_sale();
$is_in_stock    = $product->is_in_stock();
$stock_quantity = $product->get_stock_quantity();
$manage_stock   = $product->get_manage_stock();
$sku            = (string) $product->get_sku();

$is_sold_individually = (bool) $product->is_sold_individually();

$min_purchase_qty = method_exists( $product, 'get_min_purchase_quantity' ) ? max( 1, (int) $product->get_min_purchase_quantity() ) : 1;
$max_purchase_qty = method_exists( $product, 'get_max_purchase_quantity' ) ? (int) $product->get_max_purchase_quantity() : 0;

$max_qty = null;
if ( $is_sold_individually ) {
    $max_qty = 1;
} elseif ( $manage_stock && null !== $stock_quantity && $stock_quantity > 0
    && ! ( method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed() ) ) {
    $max_qty = (int) $stock_quantity;
}

if ( $max_purchase_qty > 0 && ( null === $max_qty || $max_purchase_qty < $max_qty ) ) {
    $max_qty = $max_purchase_qty;
}

if ( null !== $max_qty && $min_purchase_qty > $max_qty ) {
    $min_purchase_qty = $max_qty;
}

$show_backorder_notice = false;
if ( method_exists( $product, 'backorders_require_notification' ) && $product->backorders_require_notification() ) {
    $stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
    $needs_backorder_now = 'onbackorder' === $stock_status
        || ( $manage_stock && null !== $stock_quantity && (int) $stock_quantity <= 0 );
    $show_backorder_notice = $is_in_stock && $needs_backorder_now;
}

$sale_ends_at = 0;
if ( $is_on_sale && method_exists( $product, 'get_sale_date_to' ) ) {
    $sale_ends_at = (int) $product->get_sale_date_to();
}

$primary_category = null;
if ( class_exists( '\\TejCart\\Product\\Product_Taxonomy' ) ) {
    $product_categories = \TejCart\Product\Product_Taxonomy::get_product_categories( $product_id );
    if ( ! empty( $product_categories ) && isset( $product_categories[0]->name ) ) {
        $primary_category = $product_categories[0];
    }
}

$product_brands = array();
$product_tags   = array();
if ( class_exists( '\\TejCart\\Product\\Product_Taxonomy' ) ) {
    $product_brands = (array) \TejCart\Product\Product_Taxonomy::get_product_brands( $product_id );
    $product_tags   = (array) \TejCart\Product\Product_Taxonomy::get_product_tags( $product_id );
}

$discount_percent = 0;
if ( $is_on_sale && $regular_price > 0 && $price < $regular_price ) {
    $discount_percent = (int) floor( ( ( $regular_price - $price ) / $regular_price ) * 100 );
}

$shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
$shop_url     = $shop_page_id ? get_permalink( $shop_page_id ) : '';

$is_variable        = $product instanceof \TejCart\Product\Product_Types\Variable_Product;
$variation_attrs    = array();
$variation_map      = array();
$variation_required = false;

if ( $is_variable ) {
    foreach ( $product->get_attributes() as $attr ) {
        if ( ! empty( $attr['used_for_variations'] ) && ! empty( $attr['name'] ) && ! empty( $attr['values'] ) ) {
            $variation_attrs[] = $attr;
        }
    }

    // Audit 08 #21 — prime the postmeta cache once for every variation
    // image id so the per-variation `wp_get_attachment_image_url` calls
    // below resolve from cache instead of issuing N×postmeta SELECTs.
    $variation_image_ids = array();
    foreach ( $product->get_variations() as $variation ) {
        if ( method_exists( $variation, 'get_image_id' ) ) {
            $iid = (int) $variation->get_image_id();
            if ( $iid > 0 ) {
                $variation_image_ids[ $iid ] = $iid;
            }
        }
    }
    if ( ! empty( $variation_image_ids ) && function_exists( 'update_postmeta_cache' ) ) {
        update_postmeta_cache( array_values( $variation_image_ids ) );
    }

    foreach ( $product->get_variations() as $variation ) {
        $v_attrs       = $variation->get_attributes();
        $v_price       = $variation->get_price();
        $v_regular     = $variation->get_regular_price();
        $v_is_on_sale  = $variation->is_on_sale();
        $v_stock_qty   = method_exists( $variation, 'get_stock_quantity' ) ? $variation->get_stock_quantity() : null;
        $v_image_id    = method_exists( $variation, 'get_image_id' ) ? (int) $variation->get_image_id() : 0;
        $v_image_large = $v_image_id ? wp_get_attachment_image_url( $v_image_id, 'large' ) : '';
        $v_image_full  = $v_image_id ? wp_get_attachment_image_url( $v_image_id, 'full' ) : '';

        $variation_map[] = array(
            'id'                    => (int) $variation->get_id(),
            'attributes'            => $v_attrs,
            'in_stock'              => $variation->is_in_stock(),
            'is_purchasable'        => $variation->is_purchasable(),
            'price'                 => '' !== $v_price ? (float) $v_price : null,
            'regular_price'         => '' !== $v_regular ? (float) $v_regular : null,
            'is_on_sale'            => (bool) $v_is_on_sale,
            'price_html'            => tejcart_price( '' !== $v_price ? (float) $v_price : 0 ),
            'sku'                   => method_exists( $variation, 'get_sku' ) ? (string) $variation->get_sku() : '',
            'stock_quantity'        => null === $v_stock_qty ? null : (int) $v_stock_qty,
            'manage_stock'          => method_exists( $variation, 'get_manage_stock' ) ? (bool) $variation->get_manage_stock() : false,
            'min_purchase_quantity' => method_exists( $variation, 'get_min_purchase_quantity' ) ? (int) $variation->get_min_purchase_quantity() : 1,
            'max_purchase_quantity' => method_exists( $variation, 'get_max_purchase_quantity' ) ? (int) $variation->get_max_purchase_quantity() : 0,
            'sold_individually'     => method_exists( $variation, 'is_sold_individually' ) ? (bool) $variation->is_sold_individually() : false,
            'backorders_allowed'    => method_exists( $variation, 'backorders_allowed' ) ? (bool) $variation->backorders_allowed() : false,
            'image_id'              => $v_image_id,
            'image_large'           => $v_image_large ? esc_url_raw( $v_image_large ) : '',
            'image_full'            => $v_image_full ? esc_url_raw( $v_image_full ) : '',
        );
    }

    if ( empty( $variation_attrs ) && ! empty( $variation_map ) ) {
        $derived = array();
        foreach ( $variation_map as $entry ) {
            foreach ( (array) $entry['attributes'] as $name => $value ) {
                if ( '' === $name || '' === $value ) {
                    continue;
                }
                if ( ! isset( $derived[ $name ] ) ) {
                    $derived[ $name ] = array();
                }
                if ( ! in_array( $value, $derived[ $name ], true ) ) {
                    $derived[ $name ][] = $value;
                }
            }
        }
        foreach ( $derived as $name => $values ) {
            $variation_attrs[] = array(
                'name'                => $name,
                'values'              => $values,
                'visible'             => true,
                'used_for_variations' => true,
            );
        }
    }

    $variation_required = ! empty( $variation_attrs );
}
?>

<a class="tejcart-skip-link screen-reader-text" href="#tejcart-single-product-main">
    <?php esc_html_e( 'Skip to product details', 'tejcart' ); ?>
</a>

<div id="tejcart-single-product-main" class="tejcart-single-product" data-product-id="<?php echo esc_attr( $product_id ); ?>">

    <?php
    /**
     * schema.org Product JSON-LD. Themes can suppress with
     *   remove_action( 'tejcart_single_product_json_ld', 'tejcart_product_json_ld' );
     * or filter the payload via `tejcart_product_json_ld`.
     */
    if ( function_exists( 'tejcart_product_json_ld' ) ) {
        tejcart_product_json_ld( $product );
    }
    ?>

    <?php
    /**
     * Filter whether the single-product template renders breadcrumbs by
     * default. Themes that already emit their own breadcrumb trail can
     * return false to opt out; the "Back to shop" pill stays available as
     * a fallback when breadcrumbs are suppressed.
     *
     * @param bool                                            $show    Whether to render breadcrumbs. Default true.
     * @param \TejCart\Product\Product_Types\Abstract_Product $product The product being rendered.
     */
    $tejcart_show_breadcrumbs = (bool) apply_filters( 'tejcart_show_single_product_breadcrumbs', true, $product );
    ?>

    <div class="tejcart-single-product-layout">

        <div class="tejcart-single-product-media">
            <?php tejcart_get_template( 'product/product-gallery.php', array( 'product' => $product ) ); ?>
        </div>

        <div class="tejcart-single-product-summary">

            <?php
            if ( $tejcart_show_breadcrumbs && class_exists( '\\TejCart\\Frontend\\Breadcrumbs' ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumbs::render returns escaped HTML + JSON-LD.
                echo ( new \TejCart\Frontend\Breadcrumbs() )->render( $product_id );
            } elseif ( $shop_url ) {
                printf(
                    '<p class="tejcart-single-product-back"><a href="%s">%s</a></p>',
                    esc_url( $shop_url ),
                    esc_html__( 'Back to shop', 'tejcart' )
                );
            }
            ?>

            <?php
            /**
             * Fires immediately before the single-product title.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_before_product_title', $product );
            ?>
            <h1 class="tejcart-single-product-title"><?php echo esc_html( $product_name ); ?></h1>
            <?php
            /**
             * Fires immediately after the single-product title.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_after_product_title', $product );
            ?>

            <?php
            /**
             * Filter whether the inline rating summary renders on the
             * single-product page. The summary itself returns silently
             * when reviews are disabled or the product has none, so the
             * filter only matters for themes that want to relocate the
             * summary elsewhere.
             *
             * @param bool                                            $show    Whether to render the summary. Default true.
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product being rendered.
             */
            if ( apply_filters( 'tejcart_show_inline_rating_summary', true, $product ) ) {
                tejcart_get_template( 'product/rating-summary.php', array( 'product_id' => $product_id ) );
            }
            ?>

            <?php
            $is_grouped     = $product instanceof \TejCart\Product\Product_Types\Grouped_Product;
            $grouped_prefix = $is_grouped ? _x( 'From', 'price prefix for grouped products', 'tejcart' ) . ' ' : '';

            $variable_price_range = null;
            if ( $is_variable && method_exists( $product, 'get_price_range' ) ) {
                $range = $product->get_price_range();
                if ( is_array( $range ) && isset( $range[0], $range[1] ) && (float) $range[0] !== (float) $range[1] ) {
                    $variable_price_range = array( (float) $range[0], (float) $range[1] );
                }
            }
            ?>
            <?php
            /**
             * Fires immediately before the single-product price block.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_before_product_price', $product );
            ?>
            <div class="tejcart-single-product-price">
                <?php if ( '' !== $grouped_prefix ) : ?>
                    <span class="tejcart-price-prefix"><?php echo esc_html( $grouped_prefix ); ?></span>
                <?php endif; ?>
                <?php if ( null !== $variable_price_range ) : ?>
                    <span class="tejcart-price tejcart-price-range">
                        <?php echo wp_kses_post( tejcart_product_price_html( $variable_price_range[0] ) ); ?>
                        <span class="tejcart-price-range-sep" aria-hidden="true"> &ndash; </span>
                        <?php echo wp_kses_post( tejcart_product_price_html( $variable_price_range[1] ) ); ?>
                    </span>
                <?php elseif ( $is_on_sale ) : ?>
                    <span class="tejcart-price-group" aria-label="<?php echo esc_attr( tejcart_format_price_aria( (float) $price, (float) $regular_price ) ); ?>">
                        <span class="tejcart-price-sale"><?php echo wp_kses_post( tejcart_product_price_html( (float) $price ) ); ?></span>
                        <s aria-hidden="true" class="tejcart-price-regular"><?php echo wp_kses_post( tejcart_product_price_html( (float) $regular_price ) ); ?></s>
                        <?php if ( $discount_percent > 0 ) : ?>
                            <span class="tejcart-single-product-saved-badge">
                                <?php
                                /* translators: %d: discount percentage */
                                echo esc_html( sprintf( __( '%d%% SAVED', 'tejcart' ), $discount_percent ) );
                                ?>
                            </span>
                        <?php endif; ?>
                    </span>
                <?php else : ?>
                    <span class="tejcart-price"><?php echo wp_kses_post( tejcart_product_price_html( (float) $price ) ); ?></span>
                <?php endif; ?>
            </div>
            <?php
            /**
             * Fires immediately after the single-product price block.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_after_product_price', $product );
            ?>

            <?php
            $stock_format    = \TejCart\Product\Stock_Display::display_format();
            $low_stock_limit = \TejCart\Product\Stock_Display::low_stock_threshold();
            $qty             = (int) ( null !== $stock_quantity ? $stock_quantity : 0 );
            $show_qty        = $is_in_stock
                && $manage_stock
                && $qty > 0
                && 'never' !== $stock_format
                && ( 'always' === $stock_format || $qty <= $low_stock_limit );
            ?>
            <?php if ( ! $is_in_stock ) : ?>
                <p class="tejcart-stock-pill tejcart-stock-pill--out">
                    <?php esc_html_e( 'Sold out', 'tejcart' ); ?>
                </p>
            <?php elseif ( $show_qty ) : ?>
                <p class="tejcart-stock-pill tejcart-stock-pill--in">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: remaining stock count */
                        _n( '%d in stock', '%d in stock', $qty, 'tejcart' ),
                        $qty
                    ) );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ( $sale_ends_at > 0 ) : ?>
                <p class="tejcart-sale-ends" data-tejcart-sale-ends data-ends-at="<?php echo esc_attr( $sale_ends_at ); ?>">
                    <?php
                    $sale_ends_format = get_option( 'date_format', 'F j, Y' );
                    echo esc_html( sprintf(
                        /* translators: %s: human-readable sale end date */
                        __( 'Sale ends %s', 'tejcart' ),
                        wp_date( $sale_ends_format, $sale_ends_at )
                    ) );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ( $short_desc ) : ?>
                <div class="tejcart-single-product-short-description">
                    <?php echo wp_kses_post( wpautop( $short_desc ) ); ?>
                </div>
            <?php endif; ?>

            <?php

            if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) :
                $bundled_items = $product->get_bundled_items();
                if ( ! empty( $bundled_items ) ) :
                    ?>
                    <div class="tejcart-bundle-contents">
                        <h2 class="tejcart-bundle-contents-title"><?php esc_html_e( "What's in this bundle", 'tejcart' ); ?></h2>
                        <ul class="tejcart-bundle-contents-list">
                            <?php foreach ( $bundled_items as $bundled_item ) :
                                $bundled_product = $product->get_bundled_product( $bundled_item['product_id'] ?? 0 );
                                if ( ! $bundled_product ) {
                                    continue;
                                }
                                $bundled_qty      = max( 1, (int) ( $bundled_item['quantity'] ?? 1 ) );
                                $bundled_discount = max( 0.0, (float) ( $bundled_item['discount'] ?? 0 ) );
                                ?>
                                <li class="tejcart-bundle-contents-item">
                                    <span class="tejcart-bundle-contents-name">
                                        <?php echo esc_html( $bundled_product->get_name() ); ?>
                                        <?php if ( $bundled_qty > 1 ) : ?>
                                            <span class="tejcart-bundle-contents-qty">
                                                <?php echo esc_html( sprintf( '× %d', $bundled_qty ) ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ( $bundled_discount > 0 ) : ?>
                                        <span class="tejcart-bundle-contents-discount">
                                            <?php
                                            echo esc_html( sprintf(
                                                /* translators: %s: discount percentage */
                                                __( 'Save %s%%', 'tejcart' ),
                                                rtrim( rtrim( number_format_i18n( $bundled_discount, 2 ), '0' ), '.' )
                                            ) );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php
                endif;
            endif;
            ?>

            <?php
            /**
             * Fires immediately before the single-product add-to-cart / actions block.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_before_product_cart', $product );
            ?>
            <div class="tejcart-single-product-actions">
                <?php if ( $is_variable && ! empty( $variation_attrs ) ) : ?>
                    <div class="tejcart-variations"
                         data-tejcart-variations
                         data-product-id="<?php echo esc_attr( $product_id ); ?>"
                         data-parent-max-purchase-qty="<?php echo esc_attr( $max_purchase_qty ); ?>"
                         data-variations="<?php echo esc_attr( wp_json_encode( $variation_map ) ); ?>">
                        <?php foreach ( $variation_attrs as $attr ) :
                            $attr_name = (string) $attr['name'];
                            $attr_key  = sanitize_key( $attr_name );
                            $field_id  = 'tejcart-attr-' . $product_id . '-' . $attr_key;

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
                            $tejcart_default_input = (string) ob_get_clean();

                            /**
                             * Filter the markup for a single variation attribute input.
                             *
                             * Lets themes and plugins replace the default `<select>`
                             * with a swatch grid, color chips, size pills, etc.,
                             * without forking the template. The replacement must
                             * still emit a control whose `name` is
                             * `attribute_{$attr_key}` and that the variation JS
                             * (data-tejcart-variation-select) can read — most
                             * implementations keep a hidden `<select>` and layer
                             * a swatch UI that updates it.
                             *
                             * @param string                                          $markup    The default `<select>` markup.
                             * @param array                                           $attr      The attribute definition (name, values, used_for_variations).
                             * @param string                                          $field_id  The DOM id assigned to the control.
                             * @param string                                          $attr_key  The sanitized attribute key used in the form name.
                             * @param \TejCart\Product\Product_Types\Abstract_Product $product   The variable product.
                             */
                            $tejcart_variation_input_html = (string) apply_filters(
                                'tejcart_variation_attribute_input',
                                $tejcart_default_input,
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
                                <?php

                                // Filter result may be a third-party swatch UI; allow
                                // form controls but strip scripts/handlers.
                                echo tejcart_kses_form_control( $tejcart_variation_input_html );
                                ?>
                            </div>
                        <?php endforeach; ?>
                        <p class="tejcart-variation-error" data-tejcart-variation-error hidden role="alert"></p>
                        <button
                            type="button"
                            class="tejcart-variation-clear"
                            data-tejcart-variation-clear
                            hidden
                        >
                            <?php esc_html_e( 'Clear', 'tejcart' ); ?>
                        </button>
                        <?php

                        ?>
                        <p class="tejcart-variation-price"
                           data-tejcart-variation-price
                           role="status"
                           aria-live="polite"
                           hidden></p>
                    </div>
                <?php endif; ?>

                <?php if ( $show_backorder_notice ) : ?>
                    <p class="tejcart-stock-pill tejcart-stock-pill--backorder">
                        <?php esc_html_e( 'Available on backorder — may take longer to ship.', 'tejcart' ); ?>
                    </p>
                <?php elseif ( $is_sold_individually ) : ?>
                    <p class="tejcart-qty-hint tejcart-qty-hint--individual">
                        <?php esc_html_e( 'Only one of this item can be in your cart at a time.', 'tejcart' ); ?>
                    </p>
                <?php endif; ?>

                <div class="tejcart-single-product-buy-row">
                <?php if ( $is_in_stock && 1 !== $max_qty ) : ?>
                    <div class="tejcart-single-product-qty" data-tejcart-single-qty>
                        <label class="tejcart-single-product-qty-label tejcart-sr-only" for="tejcart-single-qty-<?php echo esc_attr( $product_id ); ?>">
                            <?php esc_html_e( 'Quantity', 'tejcart' ); ?>
                        </label>
                        <div class="tejcart-qty-stepper">
                            <button type="button" class="tejcart-qty-btn tejcart-qty-decrement" aria-label="<?php esc_attr_e( 'Decrease quantity', 'tejcart' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 7h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <input
                                type="number"
                                id="tejcart-single-qty-<?php echo esc_attr( $product_id ); ?>"
                                class="tejcart-qty-input"
                                value="<?php echo esc_attr( $min_purchase_qty ); ?>"
                                min="<?php echo esc_attr( $min_purchase_qty ); ?>"
                                <?php if ( null !== $max_qty ) : ?>max="<?php echo esc_attr( $max_qty ); ?>"<?php endif; ?>
                                step="1"
                                inputmode="numeric"
                            />
                            <button type="button" class="tejcart-qty-btn tejcart-qty-increment" aria-label="<?php esc_attr_e( 'Increase quantity', 'tejcart' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M7 2v10M2 7h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <?php

                        /* translators: 1: minimum order quantity, 2: maximum order quantity. */
                        $hint_tpl_min_max = __( 'Minimum %1$d, maximum %2$d per order.', 'tejcart' );
                        /* translators: %d: minimum order quantity. */
                        $hint_tpl_min     = __( 'Minimum order quantity: %d.', 'tejcart' );
                        /* translators: %d: maximum order quantity. */
                        $hint_tpl_max     = __( 'Maximum %d per order.', 'tejcart' );

                        $hint_initial = '';
                        if ( $min_purchase_qty > 1 && null !== $max_qty ) {
                            $hint_initial = sprintf( $hint_tpl_min_max, $min_purchase_qty, $max_qty );
                        } elseif ( $min_purchase_qty > 1 ) {
                            $hint_initial = sprintf( $hint_tpl_min, $min_purchase_qty );
                        } elseif ( null !== $max_qty && $max_qty < ( $stock_quantity ?: PHP_INT_MAX ) ) {
                            $hint_initial = sprintf( $hint_tpl_max, $max_qty );
                        }
                        ?>
                        <p class="tejcart-qty-hint"
                           data-tejcart-qty-hint
                           data-tpl-min-max="<?php echo esc_attr( $hint_tpl_min_max ); ?>"
                           data-tpl-min="<?php echo esc_attr( $hint_tpl_min ); ?>"
                           data-tpl-max="<?php echo esc_attr( $hint_tpl_max ); ?>"
                           <?php if ( '' === $hint_initial ) : ?>hidden<?php endif; ?>>
                            <?php echo esc_html( $hint_initial ); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php
                tejcart_get_template(
                    'product/add-to-cart-button.php',
                    array(
                        'product'             => $product,
                        'label'               => __( 'Add to Cart', 'tejcart' ),
                        'class'               => '',
                        'quantity'            => $min_purchase_qty,
                        'redirect'            => '',
                        'variation_required'  => $variation_required,
                    )
                );
                ?>
                </div>
                <?php

                $tejcart_paypal_gateway = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_paypal' ) : null;
                $tejcart_paypal_ready   = $tejcart_paypal_gateway && $tejcart_paypal_gateway->is_available();

                // Hide express buttons when the product's express subtotal
                // would fall below the configured minimum order amount;
                // PayPal_AJAX::create_express_order() would reject the click.
                $tejcart_product_min_amount  = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
                $tejcart_product_express_amt = (float) $product->get_price() * max( 1, (int) $min_purchase_qty );
                $tejcart_product_meets_min   = $tejcart_product_min_amount <= 0
                    || $tejcart_product_express_amt >= $tejcart_product_min_amount;

                $tejcart_show_product_buttons = $tejcart_paypal_ready
                    && $tejcart_product_meets_min
                    && 'yes' === $tejcart_paypal_gateway->get_option( 'button_product_page', 'yes' );
                $tejcart_show_product_paylater = $tejcart_paypal_ready
                    && 'yes' === $tejcart_paypal_gateway->get_option( 'enable_paylater', 'yes' )
                    && 'yes' === $tejcart_paypal_gateway->get_option( 'paylater_product_page', 'yes' );

                /**
                 * Filter whether the product-detail-page PayPal smart-button
                 * stack (PayPal / Venmo / Google Pay / Apple Pay) is rendered.
                 *
                 * Addons that mandate explicit cart-and-checkout review for
                 * certain product types — most notably the Subscriptions
                 * addon, which needs the buyer to see the recurring schedule
                 * before committing — return `false` here to suppress the
                 * one-click PDP path while leaving cart-page express buttons
                 * untouched (where the recurring totals are visible).
                 *
                 * @param bool                                            $show    Default from the PayPal gateway settings.
                 * @param \TejCart\Product\Product_Types\Abstract_Product $product The product being rendered.
                 */
                $tejcart_show_product_buttons = (bool) apply_filters(
                    'tejcart_show_product_smart_buttons',
                    $tejcart_show_product_buttons,
                    $product
                );

                /**
                 * Filter whether the product-detail-page Pay Later message
                 * is rendered. Mirrors `tejcart_show_product_smart_buttons`
                 * so an addon can suppress both with a single audience.
                 *
                 * @param bool                                            $show    Default from the PayPal gateway settings.
                 * @param \TejCart\Product\Product_Types\Abstract_Product $product The product being rendered.
                 */
                $tejcart_show_product_paylater = (bool) apply_filters(
                    'tejcart_show_product_paylater',
                    $tejcart_show_product_paylater,
                    $product
                );

                $tejcart_pl_layout     = $tejcart_show_product_paylater
                    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_layout', 'text' )
                    : 'text';
                $tejcart_pl_logo_type  = $tejcart_show_product_paylater
                    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_logo_type', 'primary' )
                    : 'primary';
                $tejcart_pl_text_color = $tejcart_show_product_paylater
                    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_text_color', 'black' )
                    : 'black';

                $tejcart_smart_buttons_disabled = ! $is_in_stock
                    || ! $product->is_purchasable()
                    || $variation_required;
                ?>

                <?php if ( $tejcart_show_product_buttons ) : ?>
                    <div class="tejcart-product-smart-buttons" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-product-price="<?php echo esc_attr( $product->get_price() ); ?>"<?php echo $tejcart_smart_buttons_disabled ? ' hidden' : ''; ?>>
                        <div id="tejcart-product-paypal-<?php echo esc_attr( $product_id ); ?>" class="tejcart-product-paypal-btn"></div>
                        <?php if ( 'yes' === $tejcart_paypal_gateway->get_option( 'enable_venmo', 'yes' ) ) : ?>
                            <div id="tejcart-product-venmo-<?php echo esc_attr( $product_id ); ?>" class="tejcart-product-venmo-btn"></div>
                        <?php endif; ?>
                        <?php if ( \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' ) ) : ?>
                            <div id="tejcart-product-googlepay-<?php echo esc_attr( $product_id ); ?>" class="tejcart-product-googlepay-btn"></div>
                        <?php endif; ?>
                        <?php if ( \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' ) ) : ?>
                            <div id="tejcart-product-applepay-<?php echo esc_attr( $product_id ); ?>" class="tejcart-product-applepay-btn"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( $tejcart_show_product_paylater ) : ?>
                    <paypal-message class="tejcart-paylater-message tejcart-paylater-product"
                         amount="<?php echo esc_attr( $product->get_price() ); ?>"
                         currency-code="<?php echo esc_attr( tejcart_get_currency() ); ?>"
                         data-pp-placement="product"
                         data-pp-style-layout="<?php echo esc_attr( $tejcart_pl_layout ); ?>"
                         data-pp-style-logo-type="<?php echo esc_attr( $tejcart_pl_logo_type ); ?>"
                         data-pp-style-text-color="<?php echo esc_attr( $tejcart_pl_text_color ); ?>"
                         data-pp-style-text-size="12"
                         data-pp-style-text-align="left"
                         <?php if ( $tejcart_smart_buttons_disabled ) : ?>hidden<?php endif; ?>>
                    </paypal-message>
                <?php endif; ?>

                <?php
                /**
                 * Fires under the product Add-to-Cart / smart-button
                 * stack. Lets merchants surface trust signals (free
                 * shipping, return policy, guarantee) without editing
                 * the template. Intentionally emits no default markup
                 * so shops without those policies don't render
                 * placeholder copy.
                 *
                 * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
                 */
                do_action( 'tejcart_after_product_cta_trust', $product );
                ?>
            </div>
            <?php
            /**
             * Fires immediately after the single-product add-to-cart / actions block.
             *
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product.
             */
            do_action( 'tejcart_after_product_cart', $product );
            ?>

            <?php
            /**
             * Optional info accordion (shipping, returns, support, etc.).
             *
             * Three policy textareas under Settings → Products auto-build
             * panels here when filled in: Shipping, Returns, and
             * Warranty / support. Empty settings render nothing — placeholder
             * defaults would misrepresent shops that do not match them.
             *
             * Advanced merchants can still override or extend panels via the
             * `tejcart_single_product_info_panels` filter, returning an
             * associative array of panels with `title`, `icon` (SVG markup),
             * and `body` (escaped HTML). Open-by-default panels carry
             * `open => true` so they expand on initial render without JS.
             */
            $tejcart_info_panel_defaults = array();

            $tejcart_shipping_policy = trim( (string) get_option( 'tejcart_product_shipping_policy', '' ) );
            if ( '' !== $tejcart_shipping_policy ) {
                $tejcart_info_panel_defaults['shipping'] = array(
                    'title' => __( 'Shipping', 'tejcart' ),
                    // F-FE-007: SVG 1.1/2 defines viewBox (camelCase); lowercase viewbox is ignored
                    // by Firefox/Safari, rendering icons as blank squares.
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M1 4h9v6H1zM10 6h3l2 2v2h-5z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M4 12.5a1 1 0 1 0 2 0 1 1 0 0 0-2 0zM11 12.5a1 1 0 1 0 2 0 1 1 0 0 0-2 0z" fill="currentColor"/></svg>',
                    'body'  => wpautop( $tejcart_shipping_policy ),
                );
            }

            $tejcart_returns_policy = trim( (string) get_option( 'tejcart_product_returns_policy', '' ) );
            if ( '' !== $tejcart_returns_policy ) {
                $tejcart_info_panel_defaults['returns'] = array(
                    'title' => __( 'Returns', 'tejcart' ),
                    // F-FE-007: viewBox (camelCase) — see shipping icon comment above.
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 8a6 6 0 1 0 1.6-4.1" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M2 2v4h4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'body'  => wpautop( $tejcart_returns_policy ),
                );
            }

            $tejcart_warranty_policy = trim( (string) get_option( 'tejcart_product_warranty_policy', '' ) );
            if ( '' !== $tejcart_warranty_policy ) {
                $tejcart_info_panel_defaults['warranty'] = array(
                    'title' => __( 'Warranty & support', 'tejcart' ),
                    // F-FE-007: viewBox (camelCase) — see shipping icon comment above.
                    'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 1.5 13 4v4c0 3-2 5.2-5 6.5C5 13.2 3 11 3 8V4z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M5.5 8.2 7.2 10l3.3-3.4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    'body'  => wpautop( $tejcart_warranty_policy ),
                );
            }

            $tejcart_info_panels = apply_filters(
                'tejcart_single_product_info_panels',
                $tejcart_info_panel_defaults,
                $product
            );

            if ( ! empty( $tejcart_info_panels ) && is_array( $tejcart_info_panels ) ) :
                ?>
                <div class="tejcart-single-product-info" data-tejcart-info-accordion>
                    <?php foreach ( $tejcart_info_panels as $tejcart_panel_key => $tejcart_panel ) :
                        if ( empty( $tejcart_panel['title'] ) || empty( $tejcart_panel['body'] ) ) {
                            continue;
                        }
                        $tejcart_panel_open = ! empty( $tejcart_panel['open'] );
                        $tejcart_panel_id   = 'tejcart-info-panel-' . sanitize_html_class( (string) $tejcart_panel_key );
                        ?>
                        <details
                            class="tejcart-single-product-info-panel"
                            <?php if ( $tejcart_panel_open ) : ?>open<?php endif; ?>
                        >
                            <summary class="tejcart-single-product-info-summary" aria-controls="<?php echo esc_attr( $tejcart_panel_id ); ?>">
                                <span class="tejcart-single-product-info-summary-label">
                                    <?php if ( ! empty( $tejcart_panel['icon'] ) ) : ?>
                                        <span class="tejcart-single-product-info-icon" aria-hidden="true"><?php echo wp_kses( (string) $tejcart_panel['icon'], array(
                                            // F-FE-007: SVG attributes are case-sensitive in XML context. wp_kses
                                            // must allow 'viewBox' (camelCase) — not 'viewbox' (lowercase).
                                            'svg'  => array( 'xmlns' => true, 'viewBox' => true, 'aria-hidden' => true, 'focusable' => true ),
                                            'path' => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ),
                                        ) ); ?></span>
                                    <?php endif; ?>
                                    <span><?php echo esc_html( (string) $tejcart_panel['title'] ); ?></span>
                                </span>
                                <svg class="tejcart-single-product-info-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </summary>
                            <div class="tejcart-single-product-info-body" id="<?php echo esc_attr( $tejcart_panel_id ); ?>">
                                <?php echo wp_kses_post( (string) $tejcart_panel['body'] ); ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( '' !== $sku || $primary_category || ! empty( $product_brands ) ) : ?>
                <p class="tejcart-single-product-meta">
                    <?php if ( '' !== $sku ) : ?>
                        <span class="tejcart-meta-group">
                            <span class="tejcart-meta-label"><?php esc_html_e( 'SKU:', 'tejcart' ); ?></span>
                            <span class="tejcart-meta-value"><?php echo esc_html( $sku ); ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ( $primary_category ) :
                        $primary_cat_link = get_term_link( $primary_category );
                        ?>
                        <span class="tejcart-meta-group">
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Category:', 'tejcart' ); ?></span>
                            <span class="tejcart-meta-value">
                                <?php if ( ! is_wp_error( $primary_cat_link ) ) : ?>
                                    <a href="<?php echo esc_url( $primary_cat_link ); ?>"><?php echo esc_html( $primary_category->name ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $primary_category->name ); ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    <?php endif; ?>
                    <?php if ( ! empty( $product_brands ) ) :
                        $primary_brand = $product_brands[0];
                        $brand_link    = get_term_link( $primary_brand );
                        ?>
                        <span class="tejcart-meta-group">
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Brand:', 'tejcart' ); ?></span>
                            <span class="tejcart-meta-value tejcart-single-product-brand">
                                <?php if ( ! is_wp_error( $brand_link ) ) : ?>
                                    <a href="<?php echo esc_url( $brand_link ); ?>"><?php echo esc_html( $primary_brand->name ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $primary_brand->name ); ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php
            /**
             * Filter whether the tag row renders on the single-product
             * meta. Defaults to false so the page mirrors the classic Astra
             * reference (which only shows SKU + Category). Merchants who
             * want tags surfaced inline can opt in with:
             *
             *     add_filter( 'tejcart_single_product_show_tags', '__return_true' );
             *
             * Tags remain accessible via the product taxonomy archive and
             * the JSON-LD payload regardless of this filter.
             *
             * @param bool                                            $show    Whether to render the tags row. Default false.
             * @param \TejCart\Product\Product_Types\Abstract_Product $product The product being rendered.
             */
            $tejcart_show_tags = (bool) apply_filters( 'tejcart_single_product_show_tags', false, $product );
            ?>
            <?php if ( $tejcart_show_tags && ! empty( $product_tags ) ) : ?>
                <p class="tejcart-single-product-meta tejcart-single-product-tags">
                    <span class="tejcart-meta-label"><?php esc_html_e( 'Tags:', 'tejcart' ); ?></span>
                    <span class="tejcart-meta-value">
                        <?php
                        $tag_links = array();
                        foreach ( $product_tags as $tag ) {
                            $tag_url = get_term_link( $tag );
                            if ( is_wp_error( $tag_url ) ) {
                                $tag_links[] = esc_html( $tag->name );
                            } else {
                                $tag_links[] = sprintf(
                                    '<a href="%s" rel="tag">%s</a>',
                                    esc_url( $tag_url ),
                                    esc_html( $tag->name )
                                );
                            }
                        }
                        echo wp_kses(
                            implode( ', ', $tag_links ),
                            array( 'a' => array( 'href' => true, 'rel' => true ) )
                        );
                        ?>
                    </span>
                </p>
            <?php endif; ?>

        </div>

    </div>

    <?php
    /**
     * The Description, Additional information and Reviews sections render
     * as a tabbed block. The tab list itself is filterable via
     * `tejcart_single_product_tabs` — see
     * \TejCart\Frontend\Product_Tabs::get(). Reviews still respect the
     * `tejcart_show_single_product_reviews` filter and the
     * `tejcart_enable_reviews` option.
     */
    tejcart_get_template( 'product/product-tabs.php', array( 'product' => $product ) );
    ?>

    <?php
    /*
     * Audit #97 / 01 #6 — upsells. The template existed since 1.0 but
     * was never included anywhere. Renders after the tabbed
     * description, before related products, so the "you may also like"
     * grid sits closer to the purchase intent than the
     * shared-category fallback.
     */
    tejcart_get_template( 'product/upsells.php', array( 'product' => $product ) );
    ?>

    <?php tejcart_get_template( 'product/frequently-bought-together.php', array( 'product' => $product ) ); ?>

    <?php tejcart_get_template( 'product/recommendations.php', array( 'product' => $product ) ); ?>

    <?php tejcart_get_template( 'product/related-products.php', array( 'product' => $product ) ); ?>

    <?php if ( $is_in_stock ) : ?>
        <?php  ?>
        <div
            class="tejcart-single-product-sticky-cta"
            data-tejcart-sticky-atc
            aria-hidden="true"
        >
            <span class="tejcart-single-product-sticky-cta-info">
                <span class="tejcart-single-product-sticky-cta-name">
                    <?php echo esc_html( $product_name ); ?>
                </span>
                <span class="tejcart-single-product-sticky-cta-price">
                    <?php echo wp_kses_post( tejcart_price( $price ) ); ?>
                </span>
            </span>
            <button
                type="button"
                class="tejcart-button tejcart-button--primary tejcart-add-to-cart-btn tejcart-single-product-sticky-cta-btn"
                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                data-quantity="1"
                <?php if ( $variation_required ) : ?>data-variation-required="1"<?php endif; ?>
                tabindex="-1"
            >
                <span class="tejcart-add-to-cart-label"><?php esc_html_e( 'Add to Cart', 'tejcart' ); ?></span>
            </button>
        </div>
    <?php endif; ?>

</div>
