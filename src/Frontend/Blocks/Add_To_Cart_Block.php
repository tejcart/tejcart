<?php
/**
 * Add to Cart Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles registration and rendering of the tejcart/add-to-cart block.
 */
class Add_To_Cart_Block {
    /**
     * Return the block attribute schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'product_id'   => array(
                'type'    => 'number',
                'default' => 0,
            ),
            'button_text'  => array(
                'type'    => 'string',
                'default' => __( 'Add to Cart', 'tejcart' ),
            ),
            'button_class' => array(
                'type'    => 'string',
                'default' => '',
            ),
            'redirect_url' => array(
                'type'    => 'string',
                'default' => '',
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
            'product_id'   => 0,
            'button_text'  => __( 'Add to Cart', 'tejcart' ),
            'button_class' => '',
            'redirect_url' => '',
        ) );

        $product_id = absint( $attributes['product_id'] );
        if ( 0 === $product_id ) {
            return '';
        }

        $product = tejcart_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $args = array(
            'product'  => $product,
            'label'    => sanitize_text_field( $attributes['button_text'] ),
            'class'    => sanitize_html_class( $attributes['button_class'] ),
            'quantity' => 1,
            'redirect' => esc_url( $attributes['redirect_url'] ),
        );

        ob_start();
        tejcart_get_template( 'product/add-to-cart-button.php', $args );
        return ob_get_clean();
    }
}
