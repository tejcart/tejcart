<?php
/**
 * Cart page template.
 *
 * Renders the full shopping cart: item list, coupon field, order summary,
 * primary checkout CTA, and express-checkout zone. Rewritten from the
 * legacy table layout into a responsive two-column grid. Preserves all
 * original hooks so extensions keep working.
 *
 * @package TejCart\Templates\Cart
 *
 * @var \TejCart\Cart\Cart $cart Cart instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fires before the cart template output.
 *
 * @param \TejCart\Cart\Cart $cart The cart instance.
 */
do_action( 'tejcart_before_cart', $cart );

if ( $cart->is_empty() ) {
    include __DIR__ . '/cart-empty.php';
    do_action( 'tejcart_after_cart', $cart );
    return;
}

$tejcart_removed_coupons = method_exists( $cart, 'get_removed_coupons' )
    ? $cart->get_removed_coupons()
    : array();
?>

<a class="tejcart-skip-link screen-reader-text" href="#tejcart-cart-main">
    <?php esc_html_e( 'Skip to cart contents', 'tejcart' ); ?>
</a>

<section id="tejcart-cart-main" class="tejcart-cart has-items">

    <?php
    /*
     * Audit #91 / 09 F-012 — explicit <h1> on the cart page so the
     * heading hierarchy never starts at <h2>. Visually hidden by
     * `.tejcart-sr-only` because mainstream themes already render the
     * page title; this is a no-loss safety net for minimal-chrome themes
     * and assistive tech that walks the heading outline. Replaces the
     * earlier aria-label on the section.
     */
    ?>
    <h1 class="tejcart-sr-only"><?php esc_html_e( 'Your cart', 'tejcart' ); ?></h1>

    <header class="tejcart-cart-header">
        <a class="tejcart-cart-continue" href="<?php echo esc_url( apply_filters( 'tejcart_continue_shopping_url', home_url( '/shop/' ) ) ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M16 10H4m6-5l-5 5 5 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php esc_html_e( 'Continue shopping', 'tejcart' ); ?>
        </a>
    </header>

    <?php if ( ! empty( $tejcart_removed_coupons ) ) : ?>
        <div class="tejcart-cart-notices" role="alert">
            <?php foreach ( $tejcart_removed_coupons as $tejcart_removed ) : ?>
                <div class="tejcart-cart-notice tejcart-cart-notice--warning">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: coupon code, 2: reason */
                        __( 'Coupon "%1$s" was removed: %2$s', 'tejcart' ),
                        strtoupper( (string) ( $tejcart_removed['code'] ?? '' ) ),
                        (string) ( $tejcart_removed['reason'] ?? '' )
                    ) );
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form class="tejcart-cart-form" action="" method="post" novalidate>

        <?php wp_nonce_field( 'tejcart_update_cart', 'tejcart_cart_nonce' ); ?>

        <div class="tejcart-cart-columns">

            <div class="tejcart-cart-items-wrap">
                <div class="tejcart-cart-items-head" aria-hidden="true">
                    <span class="tejcart-cart-col-head tejcart-cart-col-head--product"><?php esc_html_e( 'Product', 'tejcart' ); ?></span>
                    <span class="tejcart-cart-col-head tejcart-cart-col-head--qty"><?php esc_html_e( 'Quantity', 'tejcart' ); ?></span>
                    <span class="tejcart-cart-col-head tejcart-cart-col-head--total"><?php esc_html_e( 'Total', 'tejcart' ); ?></span>
                </div>

                <div class="tejcart-cart-items" role="list" aria-label="<?php esc_attr_e( 'Cart items', 'tejcart' ); ?>">
                <?php
                foreach ( $cart->get_items() as $cart_item_key => $cart_item ) :
                    $product = $cart_item->get_product();

                    if ( ! $product ) {
                        continue;
                    }

                    /** This action is documented in templates/cart/cart.php */
                    do_action( 'tejcart_before_cart_item', $cart_item_key, $cart_item, $cart );

                    include __DIR__ . '/cart-item.php';

                    /** This action is documented in templates/cart/cart.php */
                    do_action( 'tejcart_after_cart_item', $cart_item_key, $cart_item, $cart );

                endforeach;
                ?>
                </div>
            </div>

            <aside class="tejcart-cart-summary" aria-labelledby="tejcart-cart-summary-title">
                <?php include __DIR__ . '/cart-totals.php'; ?>
            </aside>

        </div>

    </form>

    <?php
    // Save-for-later section — mirrors the cart drawer, shown below the
    // item list / summary on the full cart page.
    if ( tejcart_save_for_later_enabled() ) :
        $tejcart_sfl         = new \TejCart\Cart\Save_For_Later();
        $tejcart_saved_items = $tejcart_sfl->get_items();
        if ( ! empty( $tejcart_saved_items ) ) :
            ?>
            <div class="tejcart-cart-saved-section">
                <?php tejcart_get_template( 'cart/save-for-later.php', array( 'saved_items' => $tejcart_saved_items ) ); ?>
            </div>
            <?php
        endif;
    endif;
    ?>

    <?php
    /*
     * Drawer-aligned design: there is one Checkout CTA, rendered inside
     * the order summary card with the total inline on the right (see
     * cart-totals.php). The previous viewport-fixed bottom bar is gone —
     * it duplicated the in-card CTA and overlapped the last cart row on
     * tall, multi-item mobile carts.
     */
    ?>
</section>

<?php

if ( file_exists( __DIR__ . '/cross-sells.php' ) ) {
    include __DIR__ . '/cross-sells.php';
}

/**
 * Fires after the cart template output.
 *
 * @param \TejCart\Cart\Cart $cart The cart instance.
 */
do_action( 'tejcart_after_cart', $cart );
