<?php
/**
 * `wp tejcart search …` WP-CLI subcommands.
 *
 * Provides a full reindex command plus a quick single-product
 * index helper for debugging.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\WP_CLI' ) ) {
    return;
}

/**
 * Manage TejCart product search index from the command line.
 */
class CLI_Command {
    /**
     * Rebuild the entire search index.
     *
     * Truncates the index table and re-indexes every published and
     * draft product. Safe to run on a live site — the index is rebuilt
     * in batches.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Products per batch. Default 500.
     *
     * ## EXAMPLES
     *
     *     wp tejcart search reindex
     *     wp tejcart search reindex --batch-size=1000
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function reindex( $args, $assoc ): void {
        $index = new Search_Index();

        $last_reported = 0;

        $count = $index->reindex_all( static function ( int $indexed, int $total ) use ( &$last_reported ): void {
            if ( $total > 0 && ( $indexed === $total || $indexed - $last_reported >= 100 ) ) {
                \WP_CLI::log( sprintf( 'Indexed %d / %d …', $indexed, $total ) );
                $last_reported = $indexed;
            }
        } );

        \WP_CLI::success( sprintf( 'Indexed %d products.', $count ) );
    }

    /**
     * Index a single product by ID.
     *
     * ## OPTIONS
     *
     * <product_id>
     * : Product ID to index.
     *
     * ## EXAMPLES
     *
     *     wp tejcart search index-product 42
     *
     * @subcommand index-product
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function index_product( $args, $assoc ): void {
        $product_id = (int) ( $args[0] ?? 0 );
        if ( $product_id <= 0 ) {
            \WP_CLI::error( 'Please provide a valid product ID.' );
        }

        $index  = new Search_Index();
        $result = $index->index_product( $product_id );

        if ( $result ) {
            \WP_CLI::success( sprintf( 'Product #%d indexed.', $product_id ) );
        } else {
            \WP_CLI::error( sprintf( 'Product #%d not found or could not be indexed.', $product_id ) );
        }
    }

    /**
     * Show index statistics.
     *
     * ## EXAMPLES
     *
     *     wp tejcart search stats
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function stats( $args, $assoc ): void {
        global $wpdb;

        $index_table   = $wpdb->prefix . Search_Index::TABLE;
        $product_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index_table}" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$product_table} WHERE status IN ('publish','draft')" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $oldest = $wpdb->get_var( "SELECT MIN(updated_at) FROM {$index_table}" );

        \WP_CLI::log( sprintf( 'Indexed products: %d / %d', $indexed, $total ) );
        \WP_CLI::log( sprintf( 'Coverage: %s%%', $total > 0 ? round( $indexed / $total * 100, 1 ) : '0' ) );
        \WP_CLI::log( sprintf( 'Oldest entry: %s', $oldest ?? 'n/a' ) );

        if ( $indexed < $total ) {
            \WP_CLI::warning( sprintf( '%d products are not indexed. Run `wp tejcart search reindex`.', $total - $indexed ) );
        }
    }
}
