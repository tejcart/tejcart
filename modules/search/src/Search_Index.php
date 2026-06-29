<?php
/**
 * Search index management.
 *
 * Maintains the `tejcart_search_index` table — a denormalised
 * projection of product title, SKU, descriptions and attributes
 * with per-column FULLTEXT indexes for weighted scoring.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Search_Index {
    public const TABLE = 'tejcart_search_index';

    public const BATCH_SIZE = 500;

    /**
     * Create the search index table if it does not exist.
     */
    public static function install(): void {
        global $wpdb;

        $table           = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            product_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            sku VARCHAR(100) NOT NULL DEFAULT '',
            short_description TEXT NOT NULL,
            description LONGTEXT NOT NULL,
            attributes TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id),
            FULLTEXT INDEX ft_search_all (title, sku, short_description, description, attributes),
            FULLTEXT INDEX ft_search_title (title),
            FULLTEXT INDEX ft_search_sku (sku),
            FULLTEXT INDEX ft_search_short_desc (short_description),
            FULLTEXT INDEX ft_search_desc (description),
            FULLTEXT INDEX ft_search_attrs (attributes)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( TEJCART_SEARCH_DB_VERSION_OPTION, TEJCART_SEARCH_DB_VERSION );

        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            $scheduler = \TejCart\Core\Action_Scheduler::instance();
            if ( ! $scheduler->is_scheduled( 'tejcart_search_reindex_batch' ) ) {
                $scheduler->schedule_single( time(), 'tejcart_search_reindex_batch' );
            }
        }
    }

    /**
     * Index a single product into the search table.
     */
    public function index_product( int $product_id ): bool {
        global $wpdb;

        $product = $this->load_product_row( $product_id );
        if ( ! $product ) {
            return false;
        }

        $status = $product->status ?? '';
        if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
            $this->remove_product( $product_id );
            return false;
        }

        if ( ( $product->catalog_visibility ?? '' ) === 'hidden' ) {
            $this->remove_product( $product_id );
            return false;
        }

        $attributes = $this->gather_attributes( $product_id );

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->replace(
            $table,
            array(
                'product_id'        => $product_id,
                'title'             => $product->name ?? '',
                'sku'               => $product->sku ?? '',
                'short_description' => $product->short_description ?? '',
                'description'       => wp_strip_all_tags( $product->description ?? '' ),
                'attributes'        => $attributes,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $wpdb->last_error === '';
    }

    /**
     * Remove a product from the search index.
     */
    public function remove_product( int $product_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );
    }

    /**
     * Full reindex — truncates and rebuilds the search table.
     *
     * @param callable|null $progress Called with (int $indexed, int $total) per batch.
     * @return int Number of products indexed.
     */
    public function reindex_all( ?callable $progress = null ): int {
        global $wpdb;

        $index_table   = $wpdb->prefix . self::TABLE;
        $product_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "TRUNCATE TABLE {$index_table}" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$product_table} WHERE status IN ('publish','draft') AND catalog_visibility IN ('visible','search','catalog','')" );

        $indexed = 0;
        $offset  = 0;

        while ( $offset < $total ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, sku, short_description, description FROM {$product_table} WHERE status IN ('publish','draft') AND catalog_visibility IN ('visible','search','catalog','') ORDER BY id ASC LIMIT %d OFFSET %d",
                    self::BATCH_SIZE,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $attributes = $this->gather_attributes( (int) $row->id );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->replace(
                    $index_table,
                    array(
                        'product_id'        => (int) $row->id,
                        'title'             => $row->name ?? '',
                        'sku'               => $row->sku ?? '',
                        'short_description' => $row->short_description ?? '',
                        'description'       => wp_strip_all_tags( $row->description ?? '' ),
                        'attributes'        => $attributes,
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s' )
                );

                ++$indexed;
            }

            $offset += self::BATCH_SIZE;

            if ( $progress ) {
                $progress( $indexed, $total );
            }
        }

        return $indexed;
    }

    /**
     * Load a raw product row.
     *
     * @return object|null
     */
    private function load_product_row( int $product_id ): ?object {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, name, sku, short_description, description, catalog_visibility, status FROM {$table} WHERE id = %d", $product_id )
        );

        return $row ?: null;
    }

    /**
     * Gather product attributes as a single searchable text blob.
     *
     * Reads the `_product_attributes` meta key and flattens
     * attribute names and values into "Color: Red Blue | Size: S M L".
     */
    private function gather_attributes( int $product_id ): string {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE product_id = %d AND meta_key = '_product_attributes'",
                $product_id
            )
        );

        if ( ! $raw ) {
            return '';
        }

        $decoded = maybe_unserialize( $raw );
        if ( ! is_array( $decoded ) ) {
            return '';
        }

        $parts = array();
        foreach ( $decoded as $attr ) {
            $name   = $attr['name'] ?? '';
            $values = $attr['options'] ?? $attr['value'] ?? array();
            if ( is_array( $values ) ) {
                $values = implode( ' ', $values );
            }
            if ( $name || $values ) {
                $parts[] = trim( $name . ': ' . $values );
            }
        }

        return implode( ' | ', $parts );
    }
}
