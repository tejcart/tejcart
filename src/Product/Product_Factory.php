<?php
/**
 * Product Factory.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

use TejCart\Product\Product_Types\Physical_Product;
use TejCart\Product\Product_Types\Digital_Product;
use TejCart\Product\Product_Types\Virtual_Product;
use TejCart\Product\Product_Types\Bundle_Product;
use TejCart\Product\Product_Types\Variable_Product;
use TejCart\Product\Product_Types\Variation;
use TejCart\Product\Product_Types\Grouped_Product;
use TejCart\Product\Product_Types\External_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Factory class for creating product instances based on type.
 */
class Product_Factory {
    /**
     * Default product type to class map.
     *
     * @var array
     */
    private static $default_class_map = array(
        'physical'  => Physical_Product::class,
        'digital'   => Digital_Product::class,
        'virtual'   => Virtual_Product::class,
        'bundle'    => Bundle_Product::class,
        'variable'  => Variable_Product::class,
        'variation' => Variation::class,
        'grouped'   => Grouped_Product::class,
        'external'  => External_Product::class,
    );

    /**
     * Per-request memo of resolved product instances.
     *
     * Layered above the object cache so two callers asking for the same
     * product within a single request collapse to one DB hit, even when
     * `wp_using_ext_object_cache()` is false (in-process cache only).
     *
     * Keyed by product ID. Stores instance or null sentinel for "looked
     * up but missing".
     *
     * @var array<int, \TejCart\Product\Product_Types\Abstract_Product|null>
     */
    private static $request_memo = array();

    /**
     * Clear the per-request memo.
     *
     * Tests and long-running processes (CLI imports) call this to avoid
     * stale instances after a write.
     *
     * @return void
     */
    public static function clear_request_memo(): void {
        self::$request_memo = array();
    }

    /**
     * Drop the cached instance for a single product across both layers.
     *
     * Called when a write makes the previously cached instance stale —
     * e.g. a variation's stock changed and the parent Variable_Product's
     * memoised variations array would otherwise return outdated stock.
     *
     * @param int $product_id Product ID to forget.
     * @return void
     */
    public static function forget( int $product_id ): void {
        if ( $product_id <= 0 ) {
            return;
        }

        unset( self::$request_memo[ $product_id ] );
        wp_cache_delete( 'tejcart_product_' . $product_id, 'tejcart' );
    }

    /**
     * Get a product instance by ID.
     *
     * Performs a single query, caches the result, and passes the full row
     * to the product constructor to avoid a second query.
     *
     * @param int $product_id Product ID.
     * @return \TejCart\Product\Product_Types\Abstract_Product|null Product instance or null on failure.
     */
    public static function get_product( $product_id ) {
        $product_id = absint( $product_id );

        if ( ! $product_id ) {
            return null;
        }

        if ( array_key_exists( $product_id, self::$request_memo ) ) {
            return self::$request_memo[ $product_id ];
        }

        $cache_key = 'tejcart_product_' . $product_id;
        $cached    = wp_cache_get( $cache_key, 'tejcart' );

        if ( false !== $cached ) {
            self::$request_memo[ $product_id ] = $cached;
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $product_id ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $row ) {
            self::$request_memo[ $product_id ] = null;
            return null;
        }

        $type = $row['type'] ?? 'physical';

        // Pulls from Product_Type_Registry so addons can register new types
        // via the `tejcart_product_types` filter. The legacy
        // `tejcart_product_class_map` filter is still fired inside the
        // registry for backwards compatibility.
        $class_map = Product_Type_Registry::get_class_map( $type, $product_id );

        $class_name = $class_map[ $type ] ?? ( $class_map['physical'] ?? '' );
        if ( '' === $class_name || ! class_exists( $class_name ) ) {
            return null;
        }

        $product = new $class_name( null, $row );

        wp_cache_set( $cache_key, $product, 'tejcart', HOUR_IN_SECONDS );
        self::$request_memo[ $product_id ] = $product;

        // Warm the per-product meta bucket in one grouped SELECT. Without this,
        // a single product render (which the batch get_products() path primes)
        // would fall back to Abstract_Product::get_meta()'s key-by-key reads —
        // 8-12 separate queries per product detail view for sale dates, tax /
        // shipping class, visibility, min/max qty, upsells/crosssells/related.
        self::prime_meta_cache( array( $product_id ) );

        return $product;
    }

    /**
     * Look up a product instance by its slug.
     *
     * Used to resolve SEO-friendly product permalinks like
     * /shop/my-product/ back to a product record. Only returns
     * published products for front-end callers; pass $include_all = true
     * to also return draft/pending rows (e.g. for admins).
     *
     * @param string $slug        The product slug to match.
     * @param bool   $include_all When true, match any status.
     * @return \TejCart\Product\Product_Types\Abstract_Product|null
     */
    public static function get_by_slug( string $slug, bool $include_all = false ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        if ( $include_all ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $id = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $id = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE slug = %s AND status = %s LIMIT 1",
                    $slug,
                    'publish'
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        if ( ! $id ) {
            return null;
        }

        return self::get_product( (int) $id );
    }

    /**
     * Build a product slug that does not collide with any other row in
     * the products table.
     *
     * If the desired slug is taken we append -2, -3, ... until we find
     * one that's free. $exclude_id allows the current product to keep
     * its own slug on updates without triggering a bump.
     *
     * @param string $desired    Desired slug (will be sanitized). If
     *                           blank, caller should pass the product
     *                           name pre-sanitized.
     * @param int    $exclude_id Product ID to ignore when checking for
     *                           collisions (use on updates).
     * @return string
     */
    public static function generate_unique_slug( string $desired, int $exclude_id = 0 ): string {
        $slug = sanitize_title( $desired );
        if ( '' === $slug ) {
            $slug = 'product';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        $original = $slug;
        $counter  = 1;

        while ( true ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $conflict = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
                    $slug,
                    $exclude_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( 0 === $conflict ) {
                return $slug;
            }

            $counter++;
            $slug = $original . '-' . $counter;
        }
    }

    /**
     * Clone an existing product as a new draft.
     *
     * Copies the product row, every meta row, gallery/image references,
     * variation children (for variable parents), and bundled-item lists
     * (for bundle parents). The resulting product has:
     *   - name suffixed with " (Copy)"
     *   - status = draft
     *   - slug regenerated via generate_unique_slug()
     *   - sku cleared (enforced-unique SKUs would otherwise collide)
     *
     * Fires `tejcart_product_duplicated` with the old/new IDs so add-ons
     * can mirror their own meta.
     *
     * @param int $source_id Source product ID.
     * @return int New product ID, or 0 on failure.
     */
    public static function duplicate( int $source_id ): int {
        global $wpdb;

        $source_id = absint( $source_id );
        if ( $source_id <= 0 ) {
            return 0;
        }

        $table      = $wpdb->prefix . 'tejcart_products';
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $source = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $source_id ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( ! is_array( $source ) ) {
            return 0;
        }

        unset( $source['id'] );
        $source['status']     = 'draft';
        $source['name']       = trim( (string) ( $source['name'] ?? '' ) ) . ' (Copy)';
        $source['sku']        = '';
        $source['slug']       = self::generate_unique_slug(
            (string) ( $source['slug'] ?? $source['name'] )
        );
        if ( array_key_exists( 'total_sales', $source ) ) {
            $source['total_sales'] = 0;
        }
        if ( array_key_exists( 'created_at', $source ) ) {
            $source['created_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->insert( $table, $source );
        if ( false === $result ) {
            return 0;
        }
        $new_id = (int) $wpdb->insert_id;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $meta_rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$meta_table} WHERE product_id = %d",
                $source_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        if ( is_array( $meta_rows ) ) {
            foreach ( $meta_rows as $row ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                $wpdb->insert(
                    $meta_table,
                    array(
                        'product_id' => $new_id,
                        'meta_key'   => (string) $row['meta_key'],
                        'meta_value' => (string) $row['meta_value'],
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            }
        }

        if ( 'variable' === (string) ( $source['type'] ?? '' ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $variation_ids = (array) $wpdb->get_col(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT product_id FROM {$meta_table} WHERE meta_key = '_variation_parent_id' AND meta_value = %s",
                    (string) $source_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            foreach ( $variation_ids as $vid ) {
                $new_vid = self::duplicate( (int) $vid );
                if ( $new_vid > 0 ) {
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    $wpdb->update(
                        $meta_table,
                        array( 'meta_value' => (string) $new_id ),
                        array( 'product_id' => $new_vid, 'meta_key' => '_variation_parent_id' )
                    );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                }
            }
        }

        /**
         * Fires after a product has been duplicated.
         *
         * @param int $new_id    New product ID.
         * @param int $source_id Source product ID.
         */
        do_action( 'tejcart_product_duplicated', $new_id, $source_id );

        return $new_id;
    }

    /**
     * Pre-fetch every meta row for a set of products in a single query
     * and push the results into the object cache under the per-product
     * key Abstract_Product::get_meta reads from.
     *
     * Callers batch-prime before a grid render so every product's
     * subsequent get_meta() call is served from cache — without this
     * a shop page of N products would issue N*K meta queries.
     *
     * @param int[] $product_ids
     * @return int Number of meta rows hydrated.
     */
    public static function prime_meta_cache( array $product_ids ): int {
        $product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
        if ( empty( $product_ids ) ) {
            return 0;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'tejcart_product_meta';
        $placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT product_id, meta_key, meta_value FROM {$table} WHERE product_id IN ({$placeholders})",
                $product_ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        $buckets = array();
        foreach ( $rows as $row ) {
            $pid = (int) ( $row['product_id'] ?? 0 );
            if ( $pid <= 0 ) {
                continue;
            }
            $buckets[ $pid ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
        }

        foreach ( $product_ids as $pid ) {
            wp_cache_set(
                'tejcart_product_meta_all_' . $pid,
                $buckets[ $pid ] ?? array(),
                'tejcart',
                HOUR_IN_SECONDS
            );
        }

        return count( $rows );
    }

    /**
     * Find the ID of a product that already uses the given SKU, if any.
     *
     * Returns 0 when the SKU is unused. `$exclude_id` skips the current row
     * during updates. Blank/NULL SKUs are never considered duplicates — not
     * every product has an SKU and forcing one would be a breaking change;
     * the check applies to non-empty SKUs only.
     *
     * @param string $sku        SKU to look up.
     * @param int    $exclude_id Product ID to ignore (use on updates).
     * @return int Product ID of existing row, or 0 if free.
     */
    public static function sku_exists( string $sku, int $exclude_id = 0 ): int {
        $sku = trim( $sku );
        if ( '' === $sku ) {
            return 0;
        }

        // Per-request memo (audit 08 #16). Hot on bulk-import paths
        // where the same SKU is checked twice (uniqueness preflight +
        // insert validation). Keyed on the (sku, exclude_id) pair so a
        // mid-request product save still sees the correct id.
        static $cache = array();
        $cache_key = $sku . '|' . (int) $exclude_id;
        if ( array_key_exists( $cache_key, $cache ) ) {
            return (int) $cache[ $cache_key ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $id = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE sku = %s AND id <> %d LIMIT 1",
                $sku,
                $exclude_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $cache[ $cache_key ] = $id;
        return $id;
    }

    /**
     * Insert or update a product from an import payload.
     *
     * Provides a documented API for bulk-import tools (store migration,
     * CSV import) so they don't need direct $wpdb access against the
     * products table. Handles slug uniqueness, SKU conflict detection,
     * JSON-field encoding, cache invalidation, and fires hooks.
     *
     * @param array<string,mixed> $data   Column data matching the tejcart_products schema.
     * @param int                 $id     Existing product ID for update, or 0 for insert.
     * @return int|\WP_Error Product ID on success, WP_Error on failure.
     */
    public static function import( array $data, int $id = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        $allowed_columns = [
            'name', 'slug', 'type', 'status', 'description', 'short_description',
            'sku', 'price', 'sale_price', 'stock_quantity', 'stock_status',
            'manage_stock', 'backorders', 'sold_individually', 'min_purchase_quantity',
            'max_purchase_quantity', 'weight', 'dimensions', 'tax_class',
            'shipping_class', 'catalog_visibility', 'featured', 'image_id',
            'gallery_ids', 'downloadable', 'virtual', 'total_sales',
            'created_at', 'updated_at',
        ];

        $row = array_intersect_key( $data, array_flip( $allowed_columns ) );

        foreach ( [ 'dimensions', 'gallery_ids' ] as $json_key ) {
            if ( array_key_exists( $json_key, $row ) && null !== $row[ $json_key ] && ! is_string( $row[ $json_key ] ) ) {
                $row[ $json_key ] = (string) wp_json_encode( $row[ $json_key ] );
            }
        }

        if ( $id > 0 ) {
            $row = apply_filters( 'tejcart_product_import_data', $row, $id, $data );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update( $table, $row, [ 'id' => $id ] );
            if ( false === $result ) {
                return new \WP_Error( 'db_update_failed', (string) $wpdb->last_error );
            }
            self::forget( $id );
            do_action( 'tejcart_product_imported', $id, $data, 'update' );
            return $id;
        }

        if ( ! empty( $row['slug'] ) ) {
            $row['slug'] = self::generate_unique_slug( (string) $row['slug'] );
        } elseif ( ! empty( $row['name'] ) ) {
            $row['slug'] = self::generate_unique_slug( sanitize_title( (string) $row['name'] ) );
        }

        if ( ! empty( $row['sku'] ) ) {
            $conflict = self::sku_exists( (string) $row['sku'], 0 );
            if ( $conflict > 0 ) {
                return new \WP_Error(
                    'sku_exists',
                    sprintf( 'SKU %s already exists on product #%d.', (string) $row['sku'], $conflict )
                );
            }
        }

        $row = apply_filters( 'tejcart_product_import_data', $row, 0, $data );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $wpdb->insert( $table, $row );
        if ( false === $ok ) {
            return new \WP_Error( 'db_insert_failed', (string) $wpdb->last_error );
        }

        $new_id = (int) $wpdb->insert_id;
        do_action( 'tejcart_product_imported', $new_id, $data, 'insert' );
        return $new_id;
    }

    /**
     * Batch-load multiple products in a single query.
     *
     * Checks the object cache first, then loads any uncached products
     * with one SELECT ... WHERE id IN (...) query. Each loaded product
     * is stored in the object cache for subsequent get_product() calls.
     *
     * @param int[] $product_ids Array of product IDs.
     * @return \TejCart\Product\Product_Types\Abstract_Product[] Associative array keyed by product ID.
     */
    public static function get_products( array $product_ids ): array {
        if ( empty( $product_ids ) ) {
            return [];
        }

        $product_ids = array_map( 'absint', $product_ids );
        $product_ids = array_filter( $product_ids );

        $products     = [];
        $uncached_ids = [];

        foreach ( $product_ids as $id ) {
            if ( array_key_exists( $id, self::$request_memo ) ) {
                if ( null !== self::$request_memo[ $id ] ) {
                    $products[ $id ] = self::$request_memo[ $id ];
                }
                continue;
            }
            $cached = wp_cache_get( 'tejcart_product_' . $id, 'tejcart' );
            if ( false !== $cached ) {
                $products[ $id ]          = $cached;
                self::$request_memo[ $id ] = $cached;
            } else {
                $uncached_ids[] = $id;
            }
        }

        if ( ! empty( $uncached_ids ) ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'tejcart_products';
            $placeholders = implode( ',', array_fill( 0, count( $uncached_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows         = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
                    ...$uncached_ids
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Hydrate the meta cache for the same batch in one query so
            // any downstream get_meta() call is served from cache.
            self::prime_meta_cache( $uncached_ids );

            $class_map = Product_Type_Registry::get_class_map();

            foreach ( $rows as $row ) {
                $type  = $row['type'] ?? 'physical';
                $class = $class_map[ $type ] ?? ( $class_map['physical'] ?? '' );

                if ( '' === (string) $class || ! class_exists( $class ) ) {
                    continue;
                }

                $product                        = new $class( null, $row );
                $products[ (int) $row['id'] ]   = $product;

                wp_cache_set( 'tejcart_product_' . $row['id'], $product, 'tejcart', HOUR_IN_SECONDS );
                self::$request_memo[ (int) $row['id'] ] = $product;
            }
        }

        return $products;
    }
}
