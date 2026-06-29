<?php
/**
 * Product Box Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles registration and rendering of the tejcart/product-box block.
 */
class Product_Box_Block {
    /**
     * Return the block attribute schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_attributes(): array {
        return array(
            'product_id'       => array(
                'type'    => 'number',
                'default' => 0,
            ),
            'layout'           => array(
                'type'    => 'string',
                'default' => 'vertical',
                'enum'    => array( 'vertical', 'horizontal' ),
            ),
            'show_image'       => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'show_description' => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'show_price'       => array(
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
            'product_id'       => 0,
            'layout'           => 'vertical',
            'show_image'       => true,
            'show_description' => true,
            'show_price'       => true,
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
            'product'    => $product,
            'layout'     => in_array( $attributes['layout'], array( 'vertical', 'horizontal' ), true )
                            ? $attributes['layout']
                            : 'vertical',
            'show_image' => (bool) $attributes['show_image'],
            'show_desc'  => (bool) $attributes['show_description'],
            'show_price' => (bool) $attributes['show_price'],
        );

        ob_start();
        tejcart_get_template( 'product/product-box.php', $args );
        return ob_get_clean();
    }
}
