<?php
/**
 * Mini cart widget template.
 *
 * Displays a small cart icon with an item-count badge.
 * Clicking it opens the cart drawer (handled by tejcart-cart.js).
 *
 * @package TejCart\Templates\Cart
 *
 * @var bool $show_count Whether to display the count badge.
 * @var bool $show_total Whether to display the cart total next to the icon.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cart       = tejcart_get_cart();
$item_count = $cart->get_item_count();
$total      = (float) $cart->get_total();

$show_count = isset( $show_count ) ? $show_count : true;
$show_total = isset( $show_total ) ? $show_total : true;
?>

<button type="button" class="tejcart-mini-cart" aria-label="<?php esc_attr_e( 'Open cart', 'tejcart' ); ?>">

    <span class="tejcart-mini-cart-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
    </span>

    <?php if ( $show_count ) : ?>
        <span
            class="tejcart-mini-cart-count"
            aria-live="polite"
            aria-atomic="true"
            aria-label="<?php esc_attr_e( 'Items in cart', 'tejcart' ); ?>"
            <?php echo 0 === $item_count ? 'class="is-empty"' : ''; ?>
        >
            <?php echo esc_html( $item_count ); ?>
        </span>
    <?php endif; ?>

    <?php if ( $show_total ) : ?>
        <span
            class="tejcart-mini-cart-total"
            <?php echo 0 === $item_count ? 'class="is-empty"' : ''; ?>
        >
            <?php echo $item_count > 0 ? wp_kses_post( tejcart_price( $total ) ) : ''; ?>
        </span>
    <?php endif; ?>

</button>
