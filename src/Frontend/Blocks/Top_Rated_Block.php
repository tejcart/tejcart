<?php
/**
 * Top Rated Products Gutenberg block.
 *
 * @package TejCart\Frontend\Blocks
 */

declare( strict_types=1 );

namespace TejCart\Frontend\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Top_Rated_Block {
    public function get_attributes(): array {
        return array(
            'columns'    => array(
                'type'    => 'number',
                'default' => 4,
            ),
            'rows'       => array(
                'type'    => 'number',
                'default' => 1,
            ),
            'min_rating' => array(
                'type'    => 'number',
                'default' => 4,
            ),
        );
    }

    public function render( array $attributes, string $content = '' ): string {
        $attributes = wp_parse_args( $attributes, array(
            'columns'    => 4,
            'rows'       => 1,
            'min_rating' => 4,
        ) );

        $columns    = max( 2, min( 4, absint( $attributes['columns'] ) ) );
        $rows       = max( 1, min( 6, absint( $attributes['rows'] ) ) );
        $limit      = $columns * $rows;
        $min_rating = max( 1, min( 5, absint( $attributes['min_rating'] ) ) );

        $product_ids = $this->query_top_rated( $limit, $min_rating );
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
            'block_name' => 'top-rated',
            'heading'    => __( 'Top Rated', 'tejcart' ),
        ) );
        return ob_get_clean();
    }

    private function query_top_rated( int $limit, int $min_rating ): array {
        global $wpdb;

        $products_table = $wpdb->prefix . 'tejcart_products';
        $reviews_table  = $wpdb->prefix . \TejCart\Product\Product_Reviews::TABLE;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT p.id
                 FROM {$products_table} AS p
                 INNER JOIN {$reviews_table} AS r
                     ON r.product_id = p.id
                     AND r.status = 'approved'
                     AND r.rating BETWEEN 1 AND 5
                     AND r.parent_id IS NULL
                 WHERE p.status = %s
                   AND p.type != 'variation'
                 GROUP BY p.id
                 HAVING AVG(r.rating) >= %d
                 ORDER BY AVG(r.rating) DESC, COUNT(r.id) DESC
                 LIMIT %d",
                'publish',
                $min_rating,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( 'absint', $ids ?: array() );
    }
}
