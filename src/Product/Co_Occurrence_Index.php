<?php
/**
 * Co-occurrence index for product recommendations.
 *
 * Rebuilds the `tejcart_product_cooccurrence` table from order items,
 * tracking how often products are bought together. The rebuild runs
 * nightly via the Action Scheduler.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Co_Occurrence_Index {

    private const HOOK = 'tejcart_rebuild_cooccurrence';

    /**
     * @var int Look-back window in days for co-occurrence data.
     */
    private const DEFAULT_LOOKBACK_DAYS = 90;

    /**
     * @var int Maximum number of co-occurrence pairs to store.
     */
    private const MAX_PAIRS = 50000;

    /**
     * @var int Batch size for processing orders.
     */
    private const BATCH_SIZE = 500;

    public function init(): void {
        add_action( self::HOOK, array( $this, 'rebuild' ) );
        add_action( 'init', array( $this, 'schedule' ) );
    }

    public function schedule(): void {
        if ( ! class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            return;
        }

        $scheduler = \TejCart\Core\Action_Scheduler::instance();
        if ( ! $scheduler->is_scheduled( self::HOOK ) ) {
            $scheduler->schedule_recurring(
                time() + DAY_IN_SECONDS,
                DAY_IN_SECONDS,
                self::HOOK
            );
        }
    }

    /**
     * Rebuild the co-occurrence index from order items.
     *
     * Scans completed orders within the lookback window, counts how
     * many orders contain each product pair, and writes the results
     * to `tejcart_product_cooccurrence`. The rebuild is atomic — a
     * temporary table is populated first, then swapped in.
     */
    public function rebuild(): void {
        global $wpdb;

        /**
         * Number of days to look back when building co-occurrence data.
         *
         * @param int $days Default 90.
         */
        $lookback_days = (int) apply_filters( 'tejcart_cooccurrence_lookback_days', self::DEFAULT_LOOKBACK_DAYS );
        $lookback_days = max( 7, $lookback_days );

        $table       = $wpdb->prefix . 'tejcart_product_cooccurrence';
        $items_table = $wpdb->prefix . 'tejcart_order_items';
        $orders_table = $wpdb->prefix . 'tejcart_orders';

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $lookback_days * DAY_IN_SECONDS ) );

        // Collect co-occurrence pairs in batches to avoid memory issues
        // on stores with large order volumes.
        $pairs = array();
        $offset = 0;

        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $order_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$orders_table}
                     WHERE status IN ('completed', 'processing')
                     AND created_at >= %s
                     ORDER BY id ASC
                     LIMIT %d OFFSET %d",
                    $cutoff,
                    self::BATCH_SIZE,
                    $offset
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if ( empty( $order_ids ) ) {
                break;
            }

            $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT order_id, product_id FROM {$items_table}
                     WHERE order_id IN ({$placeholders})",
                    ...$order_ids
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // Group product IDs by order.
            $order_products = array();
            foreach ( $rows as $row ) {
                $order_products[ (int) $row->order_id ][] = (int) $row->product_id;
            }

            // Count co-occurrences.
            foreach ( $order_products as $product_ids ) {
                $product_ids = array_unique( $product_ids );
                sort( $product_ids );
                $count = count( $product_ids );
                for ( $i = 0; $i < $count; $i++ ) {
                    for ( $j = $i + 1; $j < $count; $j++ ) {
                        $key = $product_ids[ $i ] . ':' . $product_ids[ $j ];
                        $pairs[ $key ] = ( $pairs[ $key ] ?? 0 ) + 1;
                    }
                }
            }

            $offset += self::BATCH_SIZE;
        } while ( count( $order_ids ) === self::BATCH_SIZE );

        if ( empty( $pairs ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "TRUNCATE TABLE {$table}" );
            return;
        }

        // Sort by count descending and cap at MAX_PAIRS.
        arsort( $pairs );
        $pairs = array_slice( $pairs, 0, self::MAX_PAIRS, true );

        // Find the max count for frequency normalisation.
        $max_count = max( $pairs );

        // Atomic swap: truncate then bulk insert.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $insert_batch = array();
        $batch_count  = 0;

        foreach ( $pairs as $key => $count ) {
            [ $a, $b ] = explode( ':', $key );
            $frequency = $max_count > 0 ? round( $count / $max_count, 4 ) : 0;
            $insert_batch[] = $wpdb->prepare(
                '(%d, %d, %d, %f)',
                (int) $a,
                (int) $b,
                $count,
                $frequency
            );
            $batch_count++;

            if ( $batch_count >= 500 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    "INSERT INTO {$table} (product_a_id, product_b_id, cooccurrence_count, frequency)
                     VALUES " . implode( ',', $insert_batch )
                );
                $insert_batch = array();
                $batch_count  = 0;
            }
        }

        if ( ! empty( $insert_batch ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "INSERT INTO {$table} (product_a_id, product_b_id, cooccurrence_count, frequency)
                 VALUES " . implode( ',', $insert_batch )
            );
        }

        wp_cache_delete( 'tejcart_cooccurrence_last_rebuild', 'tejcart' );
        update_option( 'tejcart_cooccurrence_last_rebuild', time(), false );
    }

    /**
     * Get products frequently bought with the given product.
     *
     * @param int $product_id The product to find co-occurrences for.
     * @param int $limit      Maximum results.
     * @return array<int, int> Product ID => co-occurrence count, ordered by count desc.
     */
    public function get_cooccurrences( int $product_id, int $limit = 8 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_product_cooccurrence';
        $cache_key = "tejcart_cooccur_{$product_id}_{$limit}";
        $cached = wp_cache_get( $cache_key, 'tejcart' );

        if ( false !== $cached ) {
            return (array) $cached;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    CASE WHEN product_a_id = %d THEN product_b_id ELSE product_a_id END AS related_id,
                    cooccurrence_count
                 FROM {$table}
                 WHERE product_a_id = %d OR product_b_id = %d
                 ORDER BY cooccurrence_count DESC
                 LIMIT %d",
                $product_id,
                $product_id,
                $product_id,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $result = array();
        foreach ( $rows as $row ) {
            $result[ (int) $row->related_id ] = (int) $row->cooccurrence_count;
        }

        wp_cache_set( $cache_key, $result, 'tejcart', HOUR_IN_SECONDS );

        return $result;
    }
}
