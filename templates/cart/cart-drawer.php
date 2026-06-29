<?php
/**
 * Side cart (drawer) template.
 *
 * Outputs the overlay and drawer HTML. Opens/closes via
 * tejcart-cart.js which also traps focus, restores focus to the
 * trigger, announces cart additions via an aria-live region, and
 * provides an undo toast after each remove.
 *
 * @package TejCart\Templates\Cart
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cart                   = tejcart_get_cart();
$is_empty               = $cart->is_empty();
$item_count             = (int) $cart->get_item_count();
$tejcart_subtotal       = (float) $cart->get_subtotal();
$tejcart_discount_total = (float) $cart->get_discount_total();
$tejcart_total          = (float) $cart->get_total();

$tejcart_drawer_paypal   = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_paypal' ) : null;
$tejcart_drawer_ready    = $tejcart_drawer_paypal && $tejcart_drawer_paypal->is_available();

// Hide express buttons when the cart is below the configured minimum order
// amount; PayPal_AJAX::create_express_order() would reject the click anyway.
$tejcart_drawer_min_amount = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
$tejcart_drawer_meets_min  = $tejcart_drawer_min_amount <= 0 || $tejcart_subtotal >= $tejcart_drawer_min_amount;

$tejcart_drawer_show_btn = $tejcart_drawer_ready
    && $tejcart_drawer_meets_min
    && 'yes' === $tejcart_drawer_paypal->get_option( 'button_side_cart', 'yes' );
$tejcart_drawer_show_gp  = $tejcart_drawer_show_btn
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' );
$tejcart_drawer_show_ap  = $tejcart_drawer_show_btn
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' );
$tejcart_drawer_show_vm  = $tejcart_drawer_show_btn
    && 'yes' === $tejcart_drawer_paypal->get_option( 'enable_venmo', 'yes' );
$tejcart_drawer_show_pl  = $tejcart_drawer_ready
    && 'yes' === $tejcart_drawer_paypal->get_option( 'enable_paylater', 'yes' )
    && 'yes' === $tejcart_drawer_paypal->get_option( 'paylater_side_cart', 'no' );

$tejcart_drawer_pl_layout     = $tejcart_drawer_show_pl
    ? (string) $tejcart_drawer_paypal->get_option( 'paylater_style_layout', 'text' )
    : 'text';
$tejcart_drawer_pl_logo_type  = $tejcart_drawer_show_pl
    ? (string) $tejcart_drawer_paypal->get_option( 'paylater_style_logo_type', 'primary' )
    : 'primary';
$tejcart_drawer_pl_text_color = $tejcart_drawer_show_pl
    ? (string) $tejcart_drawer_paypal->get_option( 'paylater_style_text_color', 'black' )
    : 'black';

/**
 * Free shipping progress bar threshold (0 disables the bar).
 *
 * Reads the canonical option first (same source as Cart_Calculator
 * and Cart_Ajax), then applies the filter so extensions can override.
 *
 * @param float $threshold Amount in store currency.
 */
$tejcart_free_ship_threshold = (float) get_option( 'tejcart_shipping_free_threshold', 0 );
$tejcart_free_ship_threshold = (float) apply_filters( 'tejcart_free_shipping_threshold', $tejcart_free_ship_threshold );
$tejcart_free_ship_enabled   = $tejcart_free_ship_threshold > 0 && ! $is_empty;
// Eligibility mirrors Cart_Calculator::calculate_shipping(): post-discount
// subtotal, never the grand total (which already includes shipping/tax).
$tejcart_free_ship_eligible  = max( 0.0, $tejcart_subtotal - $tejcart_discount_total );
$tejcart_free_ship_remaining = $tejcart_free_ship_enabled
    ? max( 0, $tejcart_free_ship_threshold - $tejcart_free_ship_eligible )
    : 0;
$tejcart_free_ship_percent   = $tejcart_free_ship_enabled
    ? min( 100, ( $tejcart_free_ship_eligible / $tejcart_free_ship_threshold ) * 100 )
    : 0;
?>

<div class="tejcart-cart-drawer-overlay" data-tejcart-drawer-overlay tabindex="-1"></div>

<aside
    class="tejcart-cart-drawer<?php echo $is_empty ? ' is-empty' : ''; ?>"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tejcart-cart-drawer-title"
    aria-hidden="true"
    inert
    tabindex="-1"
>

    <header class="tejcart-cart-drawer-header">
        <div class="tejcart-cart-drawer-heading">
            <h2 class="tejcart-cart-drawer-title" id="tejcart-cart-drawer-title">
                <?php esc_html_e( 'Your Cart', 'tejcart' ); ?>
            </h2>
            <?php if ( ! $is_empty ) : ?>
                <span class="tejcart-cart-drawer-badge" aria-hidden="true">
                    <?php echo esc_html( $item_count ); ?>
                </span>
                <span class="tejcart-sr-only">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: item count */
                        _n( '%d item in cart', '%d items in cart', $item_count, 'tejcart' ),
                        $item_count
                    ) );
                    ?>
                </span>
            <?php endif; ?>
        </div>
        <button
            type="button"
            class="tejcart-cart-drawer-close"
            aria-label="<?php esc_attr_e( 'Close cart', 'tejcart' ); ?>"
        ></button>
    </header>

    <?php if ( $tejcart_free_ship_enabled ) : ?>
        <div class="tejcart-cart-drawer-shipping <?php echo $tejcart_free_ship_remaining <= 0 ? 'is-unlocked' : ''; ?>"
             role="status" aria-live="polite">
            <p class="tejcart-cart-drawer-shipping-text">
                <?php if ( $tejcart_free_ship_remaining <= 0 ) : ?>
                    <span class="tejcart-cart-drawer-shipping-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <?php esc_html_e( 'Congrats! You unlocked free shipping.', 'tejcart' ); ?>
                <?php else : ?>
                    <span class="tejcart-cart-drawer-shipping-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M2 5h10v8H2zM12 8h4l2 3v2h-6zM6 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM15 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    </span>
                    <?php
                    printf(
                        /* translators: %s: formatted amount remaining */
                        wp_kses_post( __( 'You&rsquo;re <strong>%s</strong> away from free shipping', 'tejcart' ) ),
                        wp_kses_post( tejcart_price( $tejcart_free_ship_remaining ) )
                    );
                    ?>
                <?php endif; ?>
            </p>
            <div class="tejcart-cart-drawer-shipping-bar" aria-hidden="true">
                <span class="tejcart-cart-drawer-shipping-bar-fill" style="width: <?php echo esc_attr( $tejcart_free_ship_percent ); ?>%;"></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="tejcart-cart-drawer-items" role="list" aria-label="<?php esc_attr_e( 'Cart items', 'tejcart' ); ?>">

        <?php if ( $is_empty ) : ?>

            <div class="tejcart-cart-drawer-empty">
                <div class="tejcart-cart-drawer-empty-illustration" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="36" cy="80" r="5"/>
                        <circle cx="70" cy="80" r="5"/>
                        <path d="M10 14h10l8 46a6 6 0 0 0 6 5h34a6 6 0 0 0 6-5l5-28H28"/>
                    </svg>
                </div>
                <h3 class="tejcart-cart-drawer-empty-title">
                    <?php esc_html_e( 'Your cart is empty', 'tejcart' ); ?>
                </h3>
                <p class="tejcart-cart-drawer-empty-desc">
                    <?php esc_html_e( 'Discover something you&rsquo;ll love and add it to your cart.', 'tejcart' ); ?>
                </p>
                <a class="tejcart-cart-drawer-empty-cta" href="<?php echo esc_url( apply_filters( 'tejcart_continue_shopping_url', home_url( '/shop/' ) ) ); ?>">
                    <?php esc_html_e( 'Start shopping', 'tejcart' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12m-5-5l5 5-5 5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>

            <?php
            // Empty cart — show recommended products.
            $tejcart_empty_recs = (array) apply_filters( 'tejcart_empty_cart_product_ids', array() );
            if ( empty( $tejcart_empty_recs ) && class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
                global $wpdb;
                if ( $wpdb ) {
                    $table = $wpdb->prefix . 'tejcart_products';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $tejcart_empty_recs = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE status = %s AND stock_status IN ('instock','onbackorder') ORDER BY total_sales DESC, id DESC LIMIT 4",
                            'publish'
                        )
                    );
                    $tejcart_empty_recs = array_map( 'intval', $tejcart_empty_recs );
                }
            }
            $tejcart_empty_recs = array_slice( $tejcart_empty_recs, 0, 4 );
            if ( ! empty( $tejcart_empty_recs ) && class_exists( '\\TejCart\\Product\\Product_Factory' ) ) :
                $tejcart_rec_products = \TejCart\Product\Product_Factory::get_products( $tejcart_empty_recs );
                if ( ! empty( $tejcart_rec_products ) ) :
            ?>
                <div class="tejcart-cart-drawer-recommendations">
                    <h4 class="tejcart-cart-drawer-recommendations-title">
                        <?php esc_html_e( 'Popular right now', 'tejcart' ); ?>
                    </h4>
                    <div class="tejcart-cart-drawer-recommendations-grid">
                        <?php foreach ( $tejcart_empty_recs as $rec_id ) :
                            $rec_product = $tejcart_rec_products[ $rec_id ] ?? null;
                            if ( ! $rec_product ) { continue; }
                            $rec_name     = $rec_product->get_name();
                            $rec_price    = (float) $rec_product->get_price();
                            $rec_image_id = $rec_product->get_image_id();
                            $rec_url      = get_permalink( $rec_id );
                        ?>
                            <a class="tejcart-cart-drawer-rec-card" href="<?php echo esc_url( $rec_url ); ?>">
                                <span class="tejcart-cart-drawer-rec-thumb">
                                    <?php if ( $rec_image_id ) : ?>
                                        <?php echo wp_get_attachment_image( $rec_image_id, 'thumbnail', false, array( 'alt' => esc_attr( $rec_name ), 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="tejcart-cart-drawer-rec-name"><?php echo esc_html( $rec_name ); ?></span>
                                <?php if ( $rec_price > 0 ) : ?>
                                    <span class="tejcart-cart-drawer-rec-price"><?php echo wp_kses_post( tejcart_price( $rec_price ) ); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; endif; ?>

        <?php else : ?>

            <?php
            foreach ( $cart->get_items() as $cart_item_key => $cart_item ) :
                $product = $cart_item->get_product();

                if ( ! $product ) {
                    continue;
                }

                $product_name = $cart_item->get_name();
                $product_url  = method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : '';
                $quantity     = (int) $cart_item->get_quantity();
                $unit_price   = (float) $product->get_price();
                $line_total   = (float) $cart_item->get_line_total();
                $image_id     = $product->get_image_id();
                $qty_input_id = 'tejcart-drawer-qty-' . sanitize_html_class( $cart_item_key );

                $drawer_min_qty = method_exists( $product, 'get_min_purchase_quantity' ) ? max( 1, (int) $product->get_min_purchase_quantity() ) : 1;
                $drawer_max_qty = null;
                if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
                    $drawer_max_qty = 1;
                }
                if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
                    $product_max = (int) $product->get_max_purchase_quantity();
                    if ( $product_max > 0 && ( null === $drawer_max_qty || $product_max < $drawer_max_qty ) ) {
                        $drawer_max_qty = $product_max;
                    }
                }

                if ( method_exists( $cart_item, 'is_variation' ) && $cart_item->is_variation() ) {
                    $parent_product = \TejCart\Product\Product_Factory::get_product( $cart_item->get_parent_product_id() );
                    if ( $parent_product && method_exists( $parent_product, 'get_max_purchase_quantity' ) ) {
                        $parent_max = (int) $parent_product->get_max_purchase_quantity();
                        if ( $parent_max > 0 && ( null === $drawer_max_qty || $parent_max < $drawer_max_qty ) ) {
                            $drawer_max_qty = $parent_max;
                        }
                    }
                }
                if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
                    $on_hand          = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
                    $allows_backorder = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();
                    if ( $on_hand > 0 && ! $allows_backorder && ( null === $drawer_max_qty || $on_hand < $drawer_max_qty ) ) {
                        $drawer_max_qty = $on_hand;
                    }
                }

                $drawer_show_backorder_notice = false;
                if ( method_exists( $product, 'backorders_require_notification' ) && $product->backorders_require_notification() ) {
                    $drawer_stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
                    $drawer_on_hand      = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
                    $drawer_show_backorder_notice = (
                        'onbackorder' === $drawer_stock_status
                        || (
                            method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock()
                            && null !== $drawer_on_hand && (int) $drawer_on_hand <= 0
                        )
                    );
                }

                $variation_text = '';
                if ( method_exists( $cart_item, 'get_variation_attributes' ) ) {
                    $variation_attrs = $cart_item->get_variation_attributes();
                    if ( ! empty( $variation_attrs ) && is_array( $variation_attrs ) ) {
                        $bits = array();
                        foreach ( $variation_attrs as $attr_label => $attr_value ) {
                            $bits[] = esc_html( $attr_label ) . ': ' . esc_html( $attr_value );
                        }
                        $variation_text = implode( ' · ', $bits );
                    }
                }

                $drawer_dec_disabled = $quantity <= $drawer_min_qty;
                $drawer_inc_disabled = ( null !== $drawer_max_qty && $quantity >= $drawer_max_qty );
                ?>

                <div class="tejcart-cart-drawer-item" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>" data-product-id="<?php echo esc_attr( (int) $product->get_id() ); ?>" role="listitem">
                    <a
                        class="tejcart-cart-drawer-item-thumb"
                        href="<?php echo esc_url( $product_url ); ?>"
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <?php if ( $image_id ) : ?>
                            <?php
                            echo wp_get_attachment_image(
                                $image_id,
                                'thumbnail',
                                false,
                                array(
                                    'alt'      => esc_attr( $product_name ),
                                    'loading'  => 'lazy',
                                    'decoding' => 'async',
                                )
                            );
                            ?>
                        <?php else : ?>
                            <span class="tejcart-cart-drawer-item-thumb-placeholder" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5h18v14H3z"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/></svg>
                            </span>
                        <?php endif; ?>
                    </a>

                    <div class="tejcart-cart-drawer-item-info">
                        <div class="tejcart-cart-drawer-item-head">
                            <?php if ( $product_url ) : ?>
                                <a class="tejcart-cart-drawer-item-name" href="<?php echo esc_url( $product_url ); ?>">
                                    <?php echo esc_html( apply_filters( 'tejcart_cart_item_name', $product_name, $cart_item ) ); ?>
                                </a>
                            <?php else : ?>
                                <span class="tejcart-cart-drawer-item-name">
                                    <?php echo esc_html( apply_filters( 'tejcart_cart_item_name', $product_name, $cart_item ) ); ?>
                                </span>
                            <?php endif; ?>

                            <span class="tejcart-cart-drawer-item-price" aria-label="<?php esc_attr_e( 'Line total', 'tejcart' ); ?>">
                                <?php echo wp_kses_post( tejcart_price( $line_total ) ); ?>
                            </span>

                            <button
                                type="button"
                                class="tejcart-cart-drawer-item-remove tejcart-cart-remove"
                                data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                aria-label="<?php
                                /* translators: %s: product name. */
                                echo esc_attr( sprintf( __( 'Remove %s from cart', 'tejcart' ), $product_name ) );
                                ?>"
                            ></button>
                        </div>

                        <?php if ( $variation_text ) : ?>
                            <span class="tejcart-cart-drawer-item-variant"><?php echo esc_html( $variation_text ); ?></span>
                        <?php endif; ?>

                        <?php if ( $drawer_show_backorder_notice ) : ?>
                            <span class="tejcart-cart-drawer-item-backorder">
                                <?php esc_html_e( 'On backorder — ships later.', 'tejcart' ); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ( $quantity > 1 ) : ?>
                            <span class="tejcart-cart-drawer-item-unit">
                                <?php echo wp_kses_post( tejcart_price( $unit_price ) ); ?>
                                <span class="tejcart-cart-drawer-item-unit-label"><?php esc_html_e( 'each', 'tejcart' ); ?></span>
                            </span>
                        <?php endif; ?>

                        <div class="tejcart-cart-drawer-item-controls">
                            <label class="tejcart-sr-only" for="<?php echo esc_attr( $qty_input_id ); ?>">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %s: product name */
                                    __( 'Quantity for %s', 'tejcart' ),
                                    $product_name
                                ) );
                                ?>
                            </label>
                            <div class="tejcart-qty-stepper tejcart-cart-drawer-item-qty" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
                                <button type="button" class="tejcart-qty-btn tejcart-qty-decrement"<?php echo $drawer_dec_disabled ? ' disabled' : ''; ?> aria-label="<?php esc_attr_e( 'Decrease quantity', 'tejcart' ); ?>"></button>
                                <input
                                    type="number"
                                    id="<?php echo esc_attr( $qty_input_id ); ?>"
                                    class="tejcart-qty-input tejcart-cart-item-qty"
                                    name="cart_item_qty[<?php echo esc_attr( $cart_item_key ); ?>]"
                                    value="<?php echo esc_attr( $quantity ); ?>"
                                    min="<?php echo esc_attr( $drawer_min_qty ); ?>"
                                    <?php if ( null !== $drawer_max_qty ) : ?>max="<?php echo esc_attr( $drawer_max_qty ); ?>"<?php endif; ?>
                                    step="1"
                                    inputmode="numeric"
                                    <?php if ( 1 === $drawer_max_qty ) : ?>readonly<?php endif; ?>
                                    data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                />
                                <button type="button" class="tejcart-qty-btn tejcart-qty-increment"<?php echo $drawer_inc_disabled ? ' disabled' : ''; ?> aria-label="<?php esc_attr_e( 'Increase quantity', 'tejcart' ); ?>"></button>
                            </div>

                            <?php if ( tejcart_save_for_later_enabled() ) : ?>
                            <button
                                type="button"
                                class="tejcart-cart-drawer-item-save-later tejcart-save-for-later-btn"
                                data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                aria-label="<?php
                                /* translators: %s: product name. */
                                echo esc_attr( sprintf( __( 'Save %s for later', 'tejcart' ), $product_name ) ); ?>"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 2h8a1 1 0 0 1 1 1v11l-5-3-5 3V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                                <span><?php esc_html_e( 'Save', 'tejcart' ); ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

            <?php
            // Upsell strip — show 2-3 recommended products from cross-sells/upsells.
            $tejcart_upsell_ids     = array();
            $tejcart_cart_prod_ids  = array();
            foreach ( $cart->get_items() as $_ci ) {
                $ci_pid = $_ci->get_product_id();
                $tejcart_cart_prod_ids[] = $ci_pid;
                $ci_product = \TejCart\Product\Product_Factory::get_product( $ci_pid );
                if ( $ci_product ) {
                    $tejcart_upsell_ids = array_merge(
                        $tejcart_upsell_ids,
                        $ci_product->get_crosssell_ids(),
                        $ci_product->get_upsell_ids()
                    );
                }
            }
            $tejcart_upsell_ids = array_unique( array_diff( $tejcart_upsell_ids, $tejcart_cart_prod_ids ) );
            $tejcart_upsell_ids = array_slice( $tejcart_upsell_ids, 0, 3 );
            $tejcart_upsell_ids = (array) apply_filters( 'tejcart_drawer_upsell_product_ids', $tejcart_upsell_ids, $cart );

            if ( ! empty( $tejcart_upsell_ids ) ) :
                $tejcart_upsell_products = \TejCart\Product\Product_Factory::get_products( $tejcart_upsell_ids );
                if ( ! empty( $tejcart_upsell_products ) ) :
            ?>
                <div class="tejcart-cart-drawer-upsells" aria-label="<?php esc_attr_e( 'Recommended for you', 'tejcart' ); ?>">
                    <h4 class="tejcart-cart-drawer-upsells-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1l2.47 5 5.53.8-4 3.9.94 5.5L8 13.4l-4.94 2.8.94-5.5-4-3.9 5.53-.8z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                        <?php esc_html_e( 'You might also like', 'tejcart' ); ?>
                    </h4>
                    <div class="tejcart-cart-drawer-upsells-list">
                        <?php foreach ( $tejcart_upsell_ids as $up_id ) :
                            $up_product = $tejcart_upsell_products[ $up_id ] ?? null;
                            if ( ! $up_product ) { continue; }
                            $up_name     = $up_product->get_name();
                            $up_price    = (float) $up_product->get_price();
                            $up_image_id = $up_product->get_image_id();
                            $up_in_stock = method_exists( $up_product, 'is_in_stock' ) ? $up_product->is_in_stock() : true;
                        ?>
                            <div class="tejcart-cart-drawer-upsell-card">
                                <span class="tejcart-cart-drawer-upsell-thumb">
                                    <?php if ( $up_image_id ) : ?>
                                        <?php echo wp_get_attachment_image( $up_image_id, 'thumbnail', false, array( 'alt' => esc_attr( $up_name ), 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="tejcart-cart-drawer-upsell-info">
                                    <span class="tejcart-cart-drawer-upsell-name"><?php echo esc_html( $up_name ); ?></span>
                                    <?php if ( $up_price > 0 ) : ?>
                                        <span class="tejcart-cart-drawer-upsell-price"><?php echo wp_kses_post( tejcart_price( $up_price ) ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ( $up_in_stock ) : ?>
                                    <button
                                        type="button"
                                        class="tejcart-cart-drawer-upsell-add tejcart-add-to-cart-btn"
                                        data-product-id="<?php echo esc_attr( $up_id ); ?>"
                                        data-quantity="1"
                                        aria-label="<?php
                                        /* translators: %s: product name. */
                                        echo esc_attr( sprintf( __( 'Add %s to cart', 'tejcart' ), $up_name ) ); ?>"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                        <span class="tejcart-add-to-cart-label"><?php esc_html_e( 'Add', 'tejcart' ); ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; endif; ?>

        <?php endif; ?>

    </div>

    <?php
    // Save-for-later section — after items, before footer.
    if ( tejcart_save_for_later_enabled() ) :
        $tejcart_sfl = new \TejCart\Cart\Save_For_Later();
        $tejcart_saved_items = $tejcart_sfl->get_items();
        if ( ! empty( $tejcart_saved_items ) ) :
            tejcart_get_template( 'cart/save-for-later.php', array( 'saved_items' => $tejcart_saved_items ) );
        endif;
    endif;
    ?>

    <?php if ( ! $is_empty ) : ?>

        <?php

        $tejcart_show_subtotal = abs( $tejcart_subtotal - $tejcart_total ) >= 0.005;
        ?>
        <footer class="tejcart-cart-drawer-footer">
            <div class="tejcart-cart-drawer-summary">
                <div class="tejcart-cart-drawer-summary-row tejcart-cart-drawer-summary-row--line tejcart-cart-drawer-summary-row--subtotal"<?php echo $tejcart_show_subtotal ? '' : ' hidden'; ?>>
                    <span class="tejcart-cart-drawer-summary-label"><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></span>
                    <span class="tejcart-cart-drawer-summary-value tejcart-subtotal-value"><?php echo wp_kses_post( tejcart_price( $tejcart_subtotal ) ); ?></span>
                </div>
                <div class="tejcart-cart-drawer-summary-row tejcart-cart-drawer-summary-row--line tejcart-cart-drawer-summary-row--discount"<?php echo $tejcart_discount_total > 0 ? '' : ' hidden'; ?>>
                    <span class="tejcart-cart-drawer-summary-label"><?php esc_html_e( 'Discount', 'tejcart' ); ?></span>
                    <span class="tejcart-cart-drawer-summary-value tejcart-discount-value">−<?php echo wp_kses_post( tejcart_price( $tejcart_discount_total ) ); ?></span>
                </div>
                <?php
                // Cart-level fees (gift wrap, …): amounts are minor units in the
                // active currency; formatted without re-converting.
                $tejcart_drawer_fees = method_exists( $cart, 'get_fees' ) ? (array) $cart->get_fees() : array();
                foreach ( $tejcart_drawer_fees as $tejcart_drawer_fee ) :
                    $tejcart_drawer_fee_amount = (int) ( $tejcart_drawer_fee['amount'] ?? 0 );
                    if ( $tejcart_drawer_fee_amount <= 0 ) {
                        continue;
                    }
                    $tejcart_drawer_fee_label = '' !== (string) ( $tejcart_drawer_fee['label'] ?? '' )
                        ? (string) $tejcart_drawer_fee['label']
                        : __( 'Fee', 'tejcart' );
                    ?>
                    <div class="tejcart-cart-drawer-summary-row tejcart-cart-drawer-summary-row--line tejcart-cart-drawer-summary-row--fee">
                        <span class="tejcart-cart-drawer-summary-label"><?php echo esc_html( $tejcart_drawer_fee_label ); ?></span>
                        <span class="tejcart-cart-drawer-summary-value"><?php echo wp_kses_post( tejcart_price_from_minor_units( $tejcart_drawer_fee_amount ) ); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="tejcart-cart-drawer-summary-row tejcart-cart-drawer-summary-row--total">
                    <span class="tejcart-cart-drawer-summary-label"><?php esc_html_e( 'Total', 'tejcart' ); ?></span>
                    <span class="tejcart-cart-drawer-summary-value tejcart-total-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( $tejcart_total ) ); ?></span>
                </div>
                <p class="tejcart-cart-drawer-summary-note">
                    <?php esc_html_e( 'Shipping and taxes calculated at checkout.', 'tejcart' ); ?>
                </p>
            </div>

            <?php
            // Gift wrap option.
            $tejcart_gift_wrap_on     = class_exists( '\\TejCart\\Cart\\Gift_Wrap' ) && \TejCart\Cart\Gift_Wrap::is_enabled();
            $tejcart_gift_wrap_active = $tejcart_gift_wrap_on && \TejCart\Cart\Gift_Wrap::is_active_in_cart();
            // Convert the base-currency gift-wrap fee to the active currency
            // for the toggle badge so it matches what's actually charged when
            // the option is enabled. Passthrough on a single-currency store.
            $tejcart_gift_wrap_fee    = $tejcart_gift_wrap_on
                ? (float) apply_filters( 'tejcart_amount_to_currency', \TejCart\Cart\Gift_Wrap::get_fee(), tejcart_get_currency() )
                : 0;
            $tejcart_gift_wrap_label  = $tejcart_gift_wrap_on ? \TejCart\Cart\Gift_Wrap::get_label() : '';
            $tejcart_gift_message     = $tejcart_gift_wrap_active ? \TejCart\Cart\Gift_Wrap::get_message() : '';
            ?>

            <?php if ( $tejcart_gift_wrap_on ) : ?>
                <div class="tejcart-cart-drawer-gift-wrap<?php echo $tejcart_gift_wrap_active ? ' is-active' : ''; ?>" data-tejcart-gift-wrap>
                    <label class="tejcart-cart-drawer-gift-wrap-toggle">
                        <input
                            type="checkbox"
                            class="tejcart-cart-drawer-gift-wrap-checkbox"
                            data-tejcart-gift-wrap-toggle
                            <?php checked( $tejcart_gift_wrap_active ); ?>
                        />
                        <span class="tejcart-cart-drawer-gift-wrap-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M3 8h14v9H3zM3 8l7-5 7 5M10 3v14M3 12h14" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="tejcart-cart-drawer-gift-wrap-text">
                            <span class="tejcart-cart-drawer-gift-wrap-label"><?php echo esc_html( $tejcart_gift_wrap_label ); ?></span>
                            <?php if ( $tejcart_gift_wrap_fee > 0 ) : ?>
                                <span class="tejcart-cart-drawer-gift-wrap-fee">+<?php echo wp_kses_post( tejcart_price( $tejcart_gift_wrap_fee ) ); ?></span>
                            <?php endif; ?>
                        </span>
                    </label>

                    <div class="tejcart-cart-drawer-gift-wrap-message"<?php echo $tejcart_gift_wrap_active ? '' : ' hidden'; ?>>
                        <label for="tejcart-gift-message" class="tejcart-sr-only"><?php esc_html_e( 'Gift message', 'tejcart' ); ?></label>
                        <textarea
                            id="tejcart-gift-message"
                            class="tejcart-cart-drawer-gift-wrap-textarea"
                            data-tejcart-gift-message
                            placeholder="<?php esc_attr_e( 'Add a personal message (optional)', 'tejcart' ); ?>"
                            maxlength="500"
                            rows="2"
                        ><?php echo esc_textarea( $tejcart_gift_message ); ?></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $tejcart_drawer_show_pl ) : ?>
                <paypal-message class="tejcart-paylater-message tejcart-paylater-sidecart"
                     amount="<?php echo esc_attr( $tejcart_total ); ?>"
                     currency-code="<?php echo esc_attr( tejcart_get_currency() ); ?>"
                     data-pp-placement="cart"
                     data-pp-style-layout="<?php echo esc_attr( $tejcart_drawer_pl_layout ); ?>"
                     data-pp-style-logo-type="<?php echo esc_attr( $tejcart_drawer_pl_logo_type ); ?>"
                     data-pp-style-text-color="<?php echo esc_attr( $tejcart_drawer_pl_text_color ); ?>"
                     data-pp-style-text-size="12"
                     data-pp-style-text-align="left">
                </paypal-message>
            <?php endif; ?>

            <a
                class="tejcart-cart-drawer-checkout"
                href="<?php echo esc_url( apply_filters( 'tejcart_checkout_url', home_url( '/checkout/' ) ) ); ?>"
            >
                <span><?php esc_html_e( 'Checkout', 'tejcart' ); ?></span>
                <span class="tejcart-cart-drawer-checkout-total"><?php echo wp_kses_post( tejcart_price( $tejcart_total ) ); ?></span>
            </a>

            <?php if ( $tejcart_drawer_show_btn ) : ?>
                <div class="tejcart-cart-drawer-express-wrap">
                    <span class="tejcart-cart-drawer-express-label">
                        <?php esc_html_e( 'Or pay quickly with', 'tejcart' ); ?>
                    </span>
                    <div class="tejcart-express-checkout-buttons tejcart-cart-drawer-express">
                        <div class="tejcart-express-checkout-skeleton" aria-hidden="true"></div>
                        <div id="tejcart-drawer-express-paypal"></div>
                        <?php if ( $tejcart_drawer_show_vm ) : ?>
                            <div id="tejcart-drawer-express-venmo"></div>
                        <?php endif; ?>
                        <?php if ( $tejcart_drawer_show_gp ) : ?>
                            <div id="tejcart-drawer-express-googlepay"></div>
                        <?php endif; ?>
                        <?php if ( $tejcart_drawer_show_ap ) : ?>
                            <div id="tejcart-drawer-express-applepay"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <a
                class="tejcart-cart-drawer-view-cart"
                href="<?php echo esc_url( tejcart_get_page_url( 'cart' ) ); ?>"
            >
                <span class="tejcart-cart-drawer-view-cart-label"><?php esc_html_e( 'View full cart', 'tejcart' ); ?></span>
                <svg class="tejcart-cart-drawer-view-cart-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 8h10m-4-4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </footer>

    <?php endif; ?>

</aside>

<?php /* Audit #38 / 09 F-007 — live-region + toast-region moved up to Frontend::render_a11y_regions() so they emit even when the cart drawer is disabled. */ ?>
