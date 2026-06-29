<?php
/**
 * Product Meta manager.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages product meta CRUD operations.
 *
 * All queries use $wpdb->prepare for SQL safety.
 */
class Product_Meta {
    /**
     * Object cache group.
     */
    const CACHE_GROUP = 'tejcart_product_meta';

    /**
     * Get product meta.
     *
     * @param int    $product_id Product ID.
     * @param string $key        Meta key.
     * @param bool   $single     Whether to return a single value. Default true.
     * @return mixed Single meta value, array of values, or null if not found.
     */
    public static function get( $product_id, $key, $single = true ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'tejcart_product_meta';
        $product_id = absint( $product_id );

        if ( ! $product_id || empty( $key ) ) {
            return $single ? null : array();
        }

        if ( $single ) {
            $cache_key = $product_id . ':' . $key;
            $found     = null;
            $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
            if ( $found ) {
                return $cached;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $value = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE product_id = %d AND meta_key = %s LIMIT 1",
                    $product_id,
                    $key
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

            $unserialized = $value !== null ? maybe_unserialize( $value, array( 'allowed_classes' => false ) ) : null;
            wp_cache_set( $cache_key, $unserialized, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
            return $unserialized;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $results = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE product_id = %d AND meta_key = %s",
                $product_id,
                $key
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return array_map(
            static fn( $value ) => maybe_unserialize( $value, array( 'allowed_classes' => false ) ),
            $results
        );
    }

    /**
     * Update or insert product meta.
     *
     * If the meta key already exists for the given product, it is updated.
     * Otherwise, a new row is inserted.
     *
     * @param int    $product_id Product ID.
     * @param string $key        Meta key.
     * @param mixed  $value      Meta value (will be serialized if non-scalar).
     * @return bool True on success, false on failure.
     */
    public static function update( $product_id, $key, $value ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'tejcart_product_meta';
        $product_id = absint( $product_id );

        if ( ! $product_id || empty( $key ) ) {
            return false;
        }

        $serialized = maybe_serialize( $value );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $existing = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_id FROM {$table} WHERE product_id = %d AND meta_key = %s LIMIT 1",
                $product_id,
                $key
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( $existing ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $result = $wpdb->update(
                $table,
                array( 'meta_value' => $serialized ),
                array(
                    'product_id' => $product_id,
                    'meta_key'   => $key,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $result = $wpdb->insert(
                $table,
                array(
                    'product_id' => $product_id,
                    'meta_key'   => $key,
                    'meta_value' => $serialized,
                ),
                array( '%d', '%s', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        }

        if ( false !== $result ) {
            wp_cache_delete( $product_id . ':' . $key, self::CACHE_GROUP );
        }

        return false !== $result;
    }

    /**
     * Prime the in-request / persistent object cache with all meta rows for a
     * set of product IDs in a single query.
     *
     * Call this before iterating a product collection (product grid, cart,
     * order items) to eliminate N+1 meta lookups. Subsequent get() calls for
     * these products will be served from wp_cache.
     *
     * @param int[] $product_ids Product IDs to prime.
     * @return void
     */
    public static function prime( array $product_ids ): void {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
        if ( empty( $ids ) ) {
            return;
        }

        $table        = $wpdb->prefix . 'tejcart_product_meta';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT product_id, meta_key, meta_value FROM {$table} WHERE product_id IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        foreach ( (array) $rows as $row ) {
            wp_cache_set(
                (int) $row['product_id'] . ':' . $row['meta_key'],
                maybe_unserialize( $row['meta_value'], array( 'allowed_classes' => false ) ),
                self::CACHE_GROUP,
                5 * MINUTE_IN_SECONDS
            );
        }
    }

    /**
     * Delete product meta.
     *
     * @param int    $product_id Product ID.
     * @param string $key        Meta key.
     * @return bool True on success, false on failure.
     */
    public static function delete( $product_id, $key ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'tejcart_product_meta';
        $product_id = absint( $product_id );

        if ( ! $product_id || empty( $key ) ) {
            return false;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $result = $wpdb->delete(
            $table,
            array(
                'product_id' => $product_id,
                'meta_key'   => $key,
            ),
            array( '%d', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if ( false !== $result ) {
            wp_cache_delete( $product_id . ':' . $key, self::CACHE_GROUP );
        }

        return false !== $result;
    }
}
