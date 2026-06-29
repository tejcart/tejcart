<?php
/**
 * Single cart item row template.
 *
 * Drawer-aligned markup: thumbnail + info column with a head row (name +
 * line total + remove icon), variant / unit metadata, optional wishlist
 * action, and a controls row with the quantity stepper. Mobile renders as
 * the side-cart drawer does. Desktop promotes the info-column children
 * back into a 4-column grid via `display: contents`.
 *
 * @package TejCart\Templates\Cart
 *
 * @var string                   $cart_item_key The cart item key.
 * @var \TejCart\Cart\Cart_Item   $cart_item     The cart item.
 * @var object                    $product       The product instance.
 * @var \TejCart\Cart\Cart        $cart          The cart instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$product_id   = $cart_item->get_product_id();
$quantity     = (int) $cart_item->get_quantity();
$unit_price   = (float) $product->get_price();
$line_total   = $cart_item->get_line_total();
$product_name = $cart_item->get_name();
$product_url  = method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : '';
$image_id     = $product->get_image_id();

$cart_item_min_qty = method_exists( $product, 'get_min_purchase_quantity' ) ? max( 1, (int) $product->get_min_purchase_quantity() ) : 1;
$cart_item_max_qty = null;

if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
    $cart_item_max_qty = 1;
}

if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
    $product_max = (int) $product->get_max_purchase_quantity();
    if ( $product_max > 0 && ( null === $cart_item_max_qty || $product_max < $cart_item_max_qty ) ) {
        $cart_item_max_qty = $product_max;
    }
}

if ( method_exists( $cart_item, 'is_variation' ) && $cart_item->is_variation() ) {
    $parent_product = \TejCart\Product\Product_Factory::get_product( $cart_item->get_parent_product_id() );
    if ( $parent_product && method_exists( $parent_product, 'get_max_purchase_quantity' ) ) {
        $parent_max = (int) $parent_product->get_max_purchase_quantity();
        if ( $parent_max > 0 && ( null === $cart_item_max_qty || $parent_max < $cart_item_max_qty ) ) {
            $cart_item_max_qty = $parent_max;
        }
    }
}

if ( method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock() ) {
    $on_hand = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
    $allows_backorder = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();
    if ( $on_hand > 0 && ! $allows_backorder && ( null === $cart_item_max_qty || $on_hand < $cart_item_max_qty ) ) {
        $cart_item_max_qty = $on_hand;
    }
}

$cart_item_show_backorder_notice = false;
if ( method_exists( $product, 'backorders_require_notification' ) && $product->backorders_require_notification() ) {
    $cart_item_stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
    $cart_item_on_hand      = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
    $cart_item_show_backorder_notice = (
        'onbackorder' === $cart_item_stock_status
        || (
            method_exists( $product, 'get_manage_stock' ) && $product->get_manage_stock()
            && null !== $cart_item_on_hand && (int) $cart_item_on_hand <= 0
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

$remove_url = wp_nonce_url(
    add_query_arg( array( 'tejcart_remove_item' => $cart_item_key ) ),
    'tejcart_remove_item_' . $cart_item_key,
    'tejcart_remove_nonce'
);

$qty_input_id = 'tejcart-qty-' . sanitize_html_class( $cart_item_key );

$cart_item_qty_locked   = ( 1 === $cart_item_max_qty );
$cart_item_dec_disabled = $quantity <= $cart_item_min_qty;
$cart_item_inc_disabled = ( null !== $cart_item_max_qty && $quantity >= $cart_item_max_qty );

$cart_item_classes = array( 'tejcart-cart-item' );
if ( 1 === $quantity ) {
    // Used by mobile CSS to suppress the duplicated "$X each" line.
    $cart_item_classes[] = 'is-single-qty';
}

$tejcart_show_wishlist_move = is_user_logged_in() && tejcart_wishlist_enabled();
$tejcart_wishlist_nonce     = $tejcart_show_wishlist_move ? wp_create_nonce( 'tejcart_wishlist' ) : '';

$tejcart_show_save_later = tejcart_save_for_later_enabled();
?>

<div class="<?php echo esc_attr( implode( ' ', $cart_item_classes ) ); ?>" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>" role="listitem">

    <?php if ( $product_url ) : ?>
        <a class="tejcart-cart-item-thumbnail" href="<?php echo esc_url( $product_url ); ?>" aria-hidden="true" tabindex="-1">
    <?php else : ?>
        <div class="tejcart-cart-item-thumbnail">
    <?php endif; ?>
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
            <span class="tejcart-cart-item-thumbnail-placeholder" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M3 5h18v14H3z"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/></svg>
            </span>
        <?php endif; ?>
    <?php if ( $product_url ) : ?>
        </a>
    <?php else : ?>
        </div>
    <?php endif; ?>

    <div class="tejcart-cart-item-info" data-product-id="<?php echo esc_attr( (int) $product_id ); ?>">

        <div class="tejcart-cart-item-head">
            <?php if ( $product_url ) : ?>
                <a class="tejcart-cart-item-name" href="<?php echo esc_url( $product_url ); ?>">
                    <?php echo esc_html( apply_filters( 'tejcart_cart_item_name', $product_name, $cart_item ) ); ?>
                </a>
            <?php else : ?>
                <span class="tejcart-cart-item-name">
                    <?php echo esc_html( apply_filters( 'tejcart_cart_item_name', $product_name, $cart_item ) ); ?>
                </span>
            <?php endif; ?>

            <span class="tejcart-cart-item-line-total" aria-label="<?php esc_attr_e( 'Line total', 'tejcart' ); ?>">
                <?php echo wp_kses_post( tejcart_price( $line_total ) ); ?>
            </span>

            <a
                href="<?php echo esc_url( $remove_url ); ?>"
                class="tejcart-cart-item-remove tejcart-cart-remove"
                data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                aria-label="<?php
                /* translators: %s: product name. */
                echo esc_attr( sprintf( __( 'Remove %s from cart', 'tejcart' ), $product_name ) );
                ?>"
            >
                <svg class="tejcart-cart-item-remove-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span class="tejcart-cart-item-remove-label"><?php esc_html_e( 'Remove', 'tejcart' ); ?></span>
            </a>
        </div>

        <?php if ( $variation_text ) : ?>
            <span class="tejcart-cart-item-variant"><?php echo esc_html( $variation_text ); ?></span>
        <?php endif; ?>

        <?php if ( $cart_item_show_backorder_notice ) : ?>
            <span class="tejcart-cart-item-backorder">
                <?php esc_html_e( 'Available on backorder — may take longer to ship.', 'tejcart' ); ?>
            </span>
        <?php endif; ?>

        <span class="tejcart-cart-item-unit" aria-label="<?php esc_attr_e( 'Unit price', 'tejcart' ); ?>">
            <?php echo wp_kses_post( tejcart_product_price_html( (float) $unit_price, 'cart' ) ); ?>
            <span class="tejcart-cart-item-unit-label"><?php esc_html_e( 'each', 'tejcart' ); ?></span>
        </span>

        <?php if ( $tejcart_show_wishlist_move || $tejcart_show_save_later ) : ?>
            <div class="tejcart-cart-item-actions">
                <?php if ( $tejcart_show_save_later ) : ?>
                    <button
                        type="button"
                        class="tejcart-cart-item-save-later tejcart-save-for-later-btn"
                        data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                        aria-label="<?php
                        /* translators: %s: product name. */
                        echo esc_attr( sprintf( __( 'Save %s for later', 'tejcart' ), $product_name ) );
                        ?>"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M4 2h8a1 1 0 0 1 1 1v11l-5-3-5 3V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        <?php esc_html_e( 'Save for later', 'tejcart' ); ?>
                    </button>
                <?php endif; ?>

                <?php if ( $tejcart_show_wishlist_move ) : ?>
                    <button
                        type="button"
                        class="tejcart-cart-item-wishlist"
                        data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                        data-product-id="<?php echo esc_attr( (int) $product_id ); ?>"
                        data-wishlist-nonce="<?php echo esc_attr( $tejcart_wishlist_nonce ); ?>"
                        aria-label="<?php
                        /* translators: %s: product name. */
                        echo esc_attr( sprintf( __( 'Move %s to wishlist', 'tejcart' ), $product_name ) );
                        ?>"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 17s-6-3.5-6-8a3.5 3.5 0 0 1 6-2.5A3.5 3.5 0 0 1 16 9c0 4.5-6 8-6 8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        <?php esc_html_e( 'Move to wishlist', 'tejcart' ); ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="tejcart-cart-item-controls">
            <label class="tejcart-sr-only" for="<?php echo esc_attr( $qty_input_id ); ?>">
                <?php
                echo esc_html( sprintf(
                    /* translators: %s: product name */
                    __( 'Quantity for %s', 'tejcart' ),
                    $product_name
                ) );
                ?>
            </label>
            <div class="tejcart-qty-stepper<?php echo $cart_item_qty_locked ? ' is-locked' : ''; ?>" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"<?php echo $cart_item_qty_locked ? ' aria-readonly="true"' : ''; ?>>
                <button type="button" class="tejcart-qty-btn tejcart-qty-decrement"<?php echo $cart_item_dec_disabled ? ' disabled' : ''; ?> aria-label="<?php esc_attr_e( 'Decrease quantity', 'tejcart' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 7h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <input
                    type="number"
                    id="<?php echo esc_attr( $qty_input_id ); ?>"
                    class="tejcart-qty-input tejcart-cart-item-qty"
                    name="cart_item_qty[<?php echo esc_attr( $cart_item_key ); ?>]"
                    value="<?php echo esc_attr( $quantity ); ?>"
                    min="<?php echo esc_attr( $cart_item_min_qty ); ?>"
                    <?php if ( null !== $cart_item_max_qty ) : ?>max="<?php echo esc_attr( $cart_item_max_qty ); ?>"<?php endif; ?>
                    step="1"
                    inputmode="numeric"
                    <?php if ( $cart_item_qty_locked ) : ?>readonly aria-readonly="true"<?php endif; ?>
                    data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
                />
                <button type="button" class="tejcart-qty-btn tejcart-qty-increment"<?php echo $cart_item_inc_disabled ? ' disabled' : ''; ?> aria-label="<?php esc_attr_e( 'Increase quantity', 'tejcart' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M7 2v10M2 7h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            </div>
        </div>

    </div>

</div>
