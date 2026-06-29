<?php
/**
 * Cart Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles registration and rendering of the tejcart/cart block.
 */
class Cart_Block {
    /**
     * Return the block attribute schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'show_thumbnails' => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'show_totals'     => array(
                'type'    => 'boolean',
                'default' => true,
            ),
        );
    }

    /**
     * Server-side render callback for the block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Inner block content.
     * @return string
     */
    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'show_thumbnails' => true,
            'show_totals'     => true,
        ) );

        // Pre-fix this passed only show_thumbnail / show_total to the
        // cart template — but cart/cart.php requires a `$cart`
        // variable on its first line (`do_action( 'tejcart_before_cart',
        // $cart )`). Inserting the block on a page rendered the cart
        // empty (when $cart was an unset variable that coerced to
        // null) or fataled in strict-mode hosts. Audit H — frontend.
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! is_object( $cart ) ) {
            // Cart wasn't bootable (very early page render, or a stripped
            // shortcode environment). Return an empty string so the block
            // doesn't render half a cart.
            return '';
        }

        $args = array(
            'cart'           => $cart,
            'show_thumbnail' => (bool) $attributes['show_thumbnails'],
            'show_total'     => (bool) $attributes['show_totals'],
            'class'          => '',
        );

        ob_start();
        tejcart_get_template( 'cart/cart.php', $args );
        return (string) ob_get_clean();
    }
}
