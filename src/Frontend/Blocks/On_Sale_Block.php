<?php
/**
 * On Sale Products Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class On_Sale_Block {
    public function get_attributes(): array {
        return array(
            'columns' => array(
                'type'    => 'number',
                'default' => 4,
            ),
            'rows'    => array(
                'type'    => 'number',
                'default' => 1,
            ),
            'orderby' => array(
                'type'    => 'string',
                'default' => 'date',
                'enum'    => array( 'date', 'price_asc', 'price_desc', 'name', 'discount' ),
            ),
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'columns' => 4,
            'rows'    => 1,
            'orderby' => 'date',
        ) );

        $columns = $this->clamp_columns( absint( $attributes['columns'] ) );
        $rows    = max( 1, min( 6, absint( $attributes['rows'] ) ) );
        $limit   = $columns * $rows;
        $orderby = in_array( $attributes['orderby'], array( 'date', 'price_asc', 'price_desc', 'name', 'discount' ), true )
            ? $attributes['orderby']
            : 'date';

        $product_ids = $this->query_on_sale_products( $limit, $orderby );
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

        ob_start();
        tejcart_get_template( 'blocks/product-grid.php', array(
            'products'   => $ordered,
            'columns'    => $columns,
            'block_name' => 'on-sale',
            'heading'    => __( 'On Sale', 'tejcart' ),
        ) );
        return ob_get_clean();
    }

    private function query_on_sale_products( int $limit, string $orderby ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $order_clause = match ( $orderby ) {
            'price_asc'  => 'CAST(p.sale_price AS DECIMAL(10,2)) ASC',
            'price_desc' => 'CAST(p.sale_price AS DECIMAL(10,2)) DESC',
            'name'       => 'p.name ASC',
            'discount'   => '((CAST(p.price AS DECIMAL(10,2)) - CAST(p.sale_price AS DECIMAL(10,2))) / CAST(p.price AS DECIMAL(10,2))) DESC',
            default      => 'p.created_at DESC',
        };

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT p.id FROM {$table} AS p
                 WHERE p.status = %s
                   AND p.sale_price != ''
                   AND p.sale_price IS NOT NULL
                   AND CAST(p.sale_price AS DECIMAL(10,2)) > 0
                   AND CAST(p.sale_price AS DECIMAL(10,2)) < CAST(p.price AS DECIMAL(10,2))
                   AND p.type != 'variation'
                 ORDER BY {$order_clause}
                 LIMIT %d",
                'publish',
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( 'absint', $ids ?: array() );
    }

    private function clamp_columns( int $columns ): int {
        return max( 2, min( 4, $columns ) );
    }
}
