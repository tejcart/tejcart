<?php
/**
 * Order Meta manager.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages order meta CRUD operations.
 *
 * All queries use $wpdb->prepare for SQL safety.
 */
class Order_Meta {
    /**
     * Get order meta.
     *
     * @param int    $order_id Order ID.
     * @param string $key      Meta key.
     * @param bool   $single   Whether to return a single value. Default true.
     * @return mixed Single meta value, array of values, or null if not found.
     */
    public static function get( $order_id, $key, $single = true ) {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_order_meta';
        $order_id = absint( $order_id );

        if ( ! $order_id || empty( $key ) ) {
            return $single ? null : array();
        }

        if ( $single ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $value = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s LIMIT 1",
                    $order_id,
                    $key
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

            return $value !== null ? maybe_unserialize( $value, array( 'allowed_classes' => false ) ) : null;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $results = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s",
                $order_id,
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
     * Update or insert order meta.
     *
     * If the meta key already exists for the given order, it is updated.
     * Otherwise, a new row is inserted.
     *
     * @param int    $order_id Order ID.
     * @param string $key      Meta key.
     * @param mixed  $value    Meta value (will be serialized if non-scalar).
     * @return bool True on success, false on failure.
     */
    public static function update( $order_id, $key, $value ) {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_order_meta';
        $order_id = absint( $order_id );

        if ( ! $order_id || empty( $key ) ) {
            return false;
        }

        $serialized = maybe_serialize( $value );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $existing = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_id FROM {$table} WHERE order_id = %d AND meta_key = %s LIMIT 1",
                $order_id,
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
                    'order_id' => $order_id,
                    'meta_key' => $key,
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
                    'order_id'   => $order_id,
                    'meta_key'   => $key,
                    'meta_value' => $serialized,
                ),
                array( '%d', '%s', '%s' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        }

        return false !== $result;
    }

    /**
     * Delete order meta.
     *
     * @param int    $order_id Order ID.
     * @param string $key      Meta key.
     * @return bool True on success, false on failure.
     */
    public static function delete( $order_id, $key ) {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_order_meta';
        $order_id = absint( $order_id );

        if ( ! $order_id || empty( $key ) ) {
            return false;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $result = $wpdb->delete(
            $table,
            array(
                'order_id' => $order_id,
                'meta_key' => $key,
            ),
            array( '%d', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return false !== $result;
    }
}
