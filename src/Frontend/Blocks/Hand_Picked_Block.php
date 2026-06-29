<?php
/**
 * Hand-Picked Products Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hand_Picked_Block {
    public function get_attributes(): array {
        return array(
            'product_ids' => array(
                'type'    => 'string',
                'default' => '',
            ),
            'columns'     => array(
                'type'    => 'number',
                'default' => 4,
            ),
            'orderby'     => array(
                'type'    => 'string',
                'default' => 'manual',
                'enum'    => array( 'manual', 'name', 'price_asc', 'price_desc' ),
            ),
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'product_ids' => '',
            'columns'     => 4,
            'orderby'     => 'manual',
        ) );

        $raw_ids = sanitize_text_field( $attributes['product_ids'] );
        if ( '' === $raw_ids ) {
            return '';
        }

        $product_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) ) );
        if ( empty( $product_ids ) ) {
            return '';
        }

        $columns = max( 2, min( 4, absint( $attributes['columns'] ) ) );

        $products = \TejCart\Product\Product_Factory::get_products( $product_ids );
        if ( empty( $products ) ) {
            return '';
        }

        $ordered = array();
        foreach ( $product_ids as $id ) {
            if ( isset( $products[ $id ] ) && 'publish' === $products[ $id ]->get_status() ) {
                $ordered[] = $products[ $id ];
            }
        }

        if ( empty( $ordered ) ) {
            return '';
        }

        $orderby = in_array( $attributes['orderby'], array( 'manual', 'name', 'price_asc', 'price_desc' ), true )
            ? $attributes['orderby']
            : 'manual';

        if ( 'name' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => strcasecmp( $a->get_name(), $b->get_name() ) );
        } elseif ( 'price_asc' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => (float) $a->get_price() <=> (float) $b->get_price() );
        } elseif ( 'price_desc' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => (float) $b->get_price() <=> (float) $a->get_price() );
        }

        ob_start();
        tejcart_get_template( 'blocks/product-grid.php', array(
            'products'   => $ordered,
            'columns'    => $columns,
            'block_name' => 'hand-picked',
            'heading'    => __( 'Hand-Picked Products', 'tejcart' ),
        ) );
        return ob_get_clean();
    }
}
