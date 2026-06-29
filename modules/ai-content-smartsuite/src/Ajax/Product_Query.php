<?php
/**
 * Product listing query for the Content Generator admin UI.
 *
 * @package TejCart\AI_Content_Smartsuite\Ajax
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Ajax;

use TejCart\AI_Content_Smartsuite\Content\Content_Repository;
use TejCart\AI_Content_Smartsuite\Content\Formatter;
use TejCart\Product\Product_Factory;
use TejCart\Product\Product_Meta;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Query {
    public const ALLOWED_TYPES = array( 'physical', 'digital', 'virtual', 'variable', 'bundle', 'grouped', 'external' );
    public const ALLOWED_STOCK = array( 'instock', 'outofstock', 'onbackorder' );

    /**
     * @return array{rows:array,total:int}
     */
    public static function run( array $args ): array {
        global $wpdb;

        $products_table = $wpdb->prefix . 'tejcart_products';
        $rel_table      = $wpdb->prefix . 'tejcart_term_relationships';

        $where      = array();
        $params     = array();
        $needs_rel  = false;
        $term_tt_id = 0;

        $where[]  = 'p.status = %s';
        $params[] = 'publish';

        $category = (int) ( $args['category'] ?? 0 );
        if ( $category > 0 ) {
            $term = get_term( $category, \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY );
            if ( $term && ! is_wp_error( $term ) ) {
                $needs_rel  = true;
                $term_tt_id = (int) $term->term_taxonomy_id;
            }
        }

        $type = (string) ( $args['product_type'] ?? '' );
        if ( '' !== $type && in_array( $type, self::ALLOWED_TYPES, true ) ) {
            $where[]  = 'p.type = %s';
            $params[] = $type;
        }

        $stock = (string) ( $args['stock_status'] ?? '' );
        if ( '' !== $stock && in_array( $stock, self::ALLOWED_STOCK, true ) ) {
            $where[]  = 'p.stock_status = %s';
            $params[] = $stock;
        }

        $search = trim( (string) ( $args['search'] ?? '' ) );
        if ( '' !== $search ) {
            $like      = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]   = '(p.name LIKE %s OR p.sku LIKE %s)';
            $params[]  = $like;
            $params[]  = $like;
        }

        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        if ( $needs_rel ) {
            $join_sql = "INNER JOIN {$rel_table} r ON r.product_id = p.id AND r.term_taxonomy_id = %d";
            $params   = array_merge( array( $term_tt_id ), $params );
        } else {
            $join_sql = '';
        }

        // Count.
        $count_sql = "SELECT COUNT(DISTINCT p.id) FROM {$products_table} p {$join_sql} {$where_sql}";
        if ( $params ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; SQL composed from a whitelisted snippet array; runtime values bound via prepare().
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; SQL composed from a whitelisted snippet array; runtime values bound via prepare().
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Rows.
        $rows_sql = "SELECT p.id, p.name, p.sku, p.type, p.stock_status, p.image_id
                     FROM {$products_table} p
                     {$join_sql}
                     {$where_sql}
                     GROUP BY p.id
                     ORDER BY p.id DESC
                     LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, array( $per_page, $offset ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; SQL composed from a whitelisted snippet array; runtime values bound via prepare().
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_params ), ARRAY_A );

        $ids = array();
        foreach ( $rows as $row ) {
            $ids[] = (int) $row['id'];
        }
        if ( ! empty( $ids ) ) {
            Product_Factory::prime_meta_cache( $ids );
        }

        $field = (string) ( $args['field'] ?? Content_Repository::FIELD_NAME );

        $out = array();
        foreach ( $rows as $row ) {
            $pid = (int) $row['id'];
            $out[] = array(
                'id'           => $pid,
                'name'         => (string) $row['name'],
                'sku'          => (string) $row['sku'],
                'type'         => (string) $row['type'],
                'image'        => self::image_url( (int) $row['image_id'] ),
                'existing'     => self::existing_value( $pid, $field ),
                'generated'    => self::generated_value( $pid, $field ),
                'has_snapshot' => Content_Repository::has_pre_apply_snapshot( $pid, $field ),
            );
        }

        return array( 'rows' => $out, 'total' => $total );
    }

    /**
     * @return string|array<int, array{question:string,answer:string}>
     */
    private static function existing_value( int $product_id, string $field ) {
        if ( Content_Repository::FIELD_FAQS === $field ) {
            $raw = Product_Meta::get( $product_id, Content_Repository::META_LIVE_FAQS, true );
            return Formatter::decode_faqs( $raw );
        }
        return Content_Repository::get_live( $product_id, $field );
    }

    /**
     * @return string|array<int, array{question:string,answer:string}>|null
     */
    private static function generated_value( int $product_id, string $field ) {
        return Content_Repository::get_temp( $product_id, $field );
    }

    private static function image_url( int $attachment_id ): string {
        if ( $attachment_id <= 0 ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
        return is_string( $url ) ? $url : '';
    }
}
