<?php
/**
 * Products by Category Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Products_By_Category_Block {
    public function get_attributes(): array {
        return array(
            'category_id' => array(
                'type'    => 'number',
                'default' => 0,
            ),
            'columns'     => array(
                'type'    => 'number',
                'default' => 4,
            ),
            'rows'        => array(
                'type'    => 'number',
                'default' => 1,
            ),
            'orderby'     => array(
                'type'    => 'string',
                'default' => 'date',
                'enum'    => array( 'date', 'name', 'price_asc', 'price_desc', 'sales' ),
            ),
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'category_id' => 0,
            'columns'     => 4,
            'rows'        => 1,
            'orderby'     => 'date',
        ) );

        $category_id = absint( $attributes['category_id'] );
        if ( 0 === $category_id ) {
            return '';
        }

        $columns = max( 2, min( 4, absint( $attributes['columns'] ) ) );
        $rows    = max( 1, min( 6, absint( $attributes['rows'] ) ) );
        $limit   = $columns * $rows;

        $product_ids = \TejCart\Product\Product_Taxonomy::get_products_by_category(
            $category_id,
            array( 'limit' => $limit, 'status' => 'publish' )
        );

        if ( empty( $product_ids ) ) {
            return '';
        }

        $products = \TejCart\Product\Product_Factory::get_products( $product_ids );
        if ( empty( $products ) ) {
            return '';
        }

        $ordered = array();
        foreach ( $product_ids as $id ) {
            if ( isset( $products[ $id ] ) ) {
                $ordered[] = $products[ $id ];
            }
        }

        if ( empty( $ordered ) ) {
            return '';
        }

        $orderby = in_array( $attributes['orderby'], array( 'date', 'name', 'price_asc', 'price_desc', 'sales' ), true )
            ? $attributes['orderby']
            : 'date';

        if ( 'name' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => strcasecmp( $a->get_name(), $b->get_name() ) );
        } elseif ( 'price_asc' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => (float) $a->get_price() <=> (float) $b->get_price() );
        } elseif ( 'price_desc' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => (float) $b->get_price() <=> (float) $a->get_price() );
        } elseif ( 'sales' === $orderby ) {
            usort( $ordered, static fn( $a, $b ) => $b->get_total_sales() <=> $a->get_total_sales() );
        }

        $term = get_term( $category_id, \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY );
        $heading = ( $term && ! is_wp_error( $term ) )
            ? $term->name
            : __( 'Products', 'tejcart' );

        ob_start();
        tejcart_get_template( 'blocks/product-grid.php', array(
            'products'   => $ordered,
            'columns'    => $columns,
            'block_name' => 'products-by-category',
            'heading'    => $heading,
        ) );
        return ob_get_clean();
    }
}
