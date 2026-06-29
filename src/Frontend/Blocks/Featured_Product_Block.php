<?php
/**
 * Featured Product Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Featured_Product_Block {
    public function get_attributes(): array {
        return array(
            'product_id'       => array(
                'type'    => 'number',
                'default' => 0,
            ),
            'show_price'       => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'show_description' => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'button_text'      => array(
                'type'    => 'string',
                'default' => '',
            ),
            'min_height'       => array(
                'type'    => 'number',
                'default' => 400,
            ),
            'overlay_opacity'  => array(
                'type'    => 'number',
                'default' => 50,
            ),
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'product_id'       => 0,
            'show_price'       => true,
            'show_description' => true,
            'button_text'      => '',
            'min_height'       => 400,
            'overlay_opacity'  => 50,
        ) );

        $product_id = absint( $attributes['product_id'] );
        if ( 0 === $product_id ) {
            return '';
        }

        $product = tejcart_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $image_url = '';
        $image_id  = $product->get_image_id();
        if ( $image_id ) {
            $src = wp_get_attachment_image_url( $image_id, 'large' );
            if ( $src ) {
                $image_url = $src;
            }
        }

        $button_text = '' !== $attributes['button_text']
            ? sanitize_text_field( $attributes['button_text'] )
            : __( 'Shop Now', 'tejcart' );

        $opacity = max( 0, min( 100, absint( $attributes['overlay_opacity'] ) ) );

        $args = array(
            'product'         => $product,
            'image_url'       => $image_url,
            'show_price'      => (bool) $attributes['show_price'],
            'show_description' => (bool) $attributes['show_description'],
            'button_text'     => $button_text,
            'min_height'      => max( 200, absint( $attributes['min_height'] ) ),
            'overlay_opacity' => $opacity,
        );

        ob_start();
        tejcart_get_template( 'blocks/featured-product.php', $args );
        return ob_get_clean();
    }
}
