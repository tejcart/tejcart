<?php
/**
 * Best Sellers Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Best_Sellers_Block {
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
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'columns' => 4,
            'rows'    => 1,
        ) );

        $columns = max( 2, min( 4, absint( $attributes['columns'] ) ) );
        $rows    = max( 1, min( 6, absint( $attributes['rows'] ) ) );
        $limit   = $columns * $rows;

        $product_ids = $this->query_best_sellers( $limit );
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
            'block_name' => 'best-sellers',
            'heading'    => __( 'Best Sellers', 'tejcart' ),
        ) );
        return ob_get_clean();
    }

    private function query_best_sellers( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE status = %s
                   AND total_sales > 0
                   AND type != 'variation'
                 ORDER BY total_sales DESC, created_at DESC
                 LIMIT %d",
                'publish',
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( 'absint', $ids ?: array() );
    }
}
