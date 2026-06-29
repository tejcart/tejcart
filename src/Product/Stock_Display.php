<?php
/**
 * Stock display & visibility helpers.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralises the two merchant-facing inventory display options:
 *
 *  - `tejcart_hide_out_of_stock`       (yes|no)  — exclude out-of-stock from loops.
 *  - `tejcart_stock_display_format`    (always|only_when_low|never) — stock text.
 */
class Stock_Display {
    /**
     * Whether merchant has opted to hide out-of-stock products.
     *
     * @return bool
     */
    public static function hide_out_of_stock(): bool {
        return 'yes' === get_option( 'tejcart_hide_out_of_stock', 'no' );
    }

    /**
     * Selected stock-display format.
     *
     * @return string One of: always | only_when_low | never.
     */
    public static function display_format(): string {
        $value = (string) get_option( 'tejcart_stock_display_format', 'always' );
        $allowed = array( 'always', 'only_when_low', 'never' );
        return in_array( $value, $allowed, true ) ? $value : 'always';
    }

    /**
     * Low-stock threshold (reused from low-stock alert setting).
     *
     * @return int
     */
    public static function low_stock_threshold(): int {
        return max( 0, (int) get_option( 'tejcart_low_stock_threshold', 5 ) );
    }

    /**
     * Render a human-readable stock availability string for a product.
     *
     * Honours the `tejcart_stock_display_format` setting:
     *  - `never`         — never emit stock quantity, only in/out status.
     *  - `only_when_low` — show quantity only when at/below threshold.
     *  - `always`        — show exact stock count when managed.
     *
     * @param \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
     * @return string HTML-safe text, empty string if nothing should be rendered.
     */
    public static function get_availability_text( $product ): string {
        if ( ! $product instanceof \TejCart\Product\Product_Types\Abstract_Product ) {
            return '';
        }

        if ( ! $product->is_in_stock() ) {
            return esc_html__( 'Out of stock', 'tejcart' );
        }

        $format = self::display_format();

        if ( 'never' === $format ) {
            return esc_html__( 'In stock', 'tejcart' );
        }

        if ( ! $product->get_manage_stock() ) {
            return esc_html__( 'In stock', 'tejcart' );
        }

        $qty = (int) $product->get_stock_quantity();

        if ( $qty <= 0 ) {
            return esc_html__( 'In stock', 'tejcart' );
        }

        if ( 'only_when_low' === $format && $qty > self::low_stock_threshold() ) {
            return esc_html__( 'In stock', 'tejcart' );
        }

        /* translators: %d: remaining stock quantity */
        return esc_html( sprintf( _n( '%d in stock', '%d in stock', $qty, 'tejcart' ), $qty ) );
    }

    /**
     * Append a `stock_status = 'instock'` clause to a product-query's WHERE
     * when the hide-out-of-stock setting is enabled.
     *
     * Keeps callers declarative: they pass in their existing clauses, the
     * helper returns them unchanged when the setting is off.
     *
     * @param string[] $where        Existing where clauses referencing the given alias.
     * @param array    $values       Existing prepared-statement values (mutated by reference).
     * @param string   $table_alias  Alias used for the products table (default `p`).
     * @return string[] Updated where clauses.
     */
    public static function apply_stock_where( array $where, array &$values, string $table_alias = 'p' ): array {
        if ( ! self::hide_out_of_stock() ) {
            return $where;
        }

        $alias     = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_alias );
        $where[]   = sprintf( '%s.stock_status = %%s', $alias );
        $values[]  = 'instock';

        return $where;
    }

    /**
     * Filter a list of product IDs to drop any that are out of stock.
     *
     * Used by related / upsell / cross-sell rendering, which hydrate product
     * objects post-query rather than joining on stock.
     *
     * @param int[] $ids Product IDs.
     * @return int[] Filtered IDs.
     */
    public static function filter_in_stock_ids( array $ids ): array {
        if ( ! self::hide_out_of_stock() || empty( $ids ) ) {
            return $ids;
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $table        = $wpdb->prefix . 'tejcart_products';

        $values = array_map( 'absint', $ids );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built from count only.
        $in_stock = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE stock_status = 'instock' AND id IN ({$placeholders})",
                $values
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built from count only.

        $allowed = array_map( 'absint', $in_stock );
        return array_values( array_intersect( $ids, $allowed ) );
    }
}
