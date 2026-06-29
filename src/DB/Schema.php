<?php
/**
 * Schema introspection helpers used by migrations to stay idempotent.
 *
 * Every helper queries `information_schema`, so each call is a single
 * indexed lookup against MySQL's metadata catalog — not a DDL probe.
 * Safe to call on every migration `up()` even when the change is
 * already on disk.
 *
 * Table arguments are passed UNPREFIXED (e.g. `tejcart_orders`); the
 * helpers prepend `$wpdb->prefix` internally.
 *
 * @package TejCart\DB
 */

declare( strict_types=1 );

namespace TejCart\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Schema {

    /**
     * F-CORE-012: in-memory memo caches to avoid repeated information_schema
     * round-trips during a single migration run. Migrations run once per
     * deployment so cross-request caching is not needed; static memo is
     * sufficient and avoids the overhead of wp_cache_get on catalog tables.
     *
     * Keys: "$full_table" → bool (table_exists)
     *       "$full_table.$column" → bool (column_exists)
     *       "$full_table.$index" → bool (index_exists)
     *       "fk.$constraint_name" → bool (foreign_key_exists)
     *
     * @var array<string, bool>
     */
    private static array $memo = array();

    /**
     * Reset the in-process memo cache. Used in tests to clear state between
     * test cases that create / drop tables within a single process.
     */
    public static function reset_memo(): void {
        self::$memo = array();
    }

    /**
     * True when the named table exists in the current database.
     */
    public static function table_exists( string $table ): bool {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        $key  = $full;
        if ( array_key_exists( $key, self::$memo ) ) {
            return self::$memo[ $key ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
            DB_NAME,
            $full
        ) );
        return self::$memo[ $key ] = ! empty( $found );
    }

    /**
     * True when the named column exists on the named table.
     */
    public static function column_exists( string $table, string $column ): bool {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        $key  = $full . '.' . $column;
        if ( array_key_exists( $key, self::$memo ) ) {
            return self::$memo[ $key ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
            DB_NAME,
            $full,
            $column
        ) );
        return self::$memo[ $key ] = ! empty( $found );
    }

    /**
     * True when the named index exists on the named table.
     *
     * Works for regular, UNIQUE, and FULLTEXT indexes since
     * information_schema.STATISTICS rolls them up into one view.
     */
    public static function index_exists( string $table, string $index ): bool {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        $key  = $full . '.idx.' . $index;
        if ( array_key_exists( $key, self::$memo ) ) {
            return self::$memo[ $key ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
            DB_NAME,
            $full,
            $index
        ) );
        return self::$memo[ $key ] = ! empty( $found );
    }

    /**
     * Ordered list of the column names forming the PRIMARY KEY of the
     * named table, or an empty array when the table has no primary key
     * (or does not exist).
     *
     * Unlike the boolean helpers above, this returns the column tuple in
     * key order (information_schema.STATISTICS.SEQ_IN_INDEX) so callers can
     * tell a *changed* primary key apart from a missing one. dbDelta() can
     * ADD an index but never DROP or MODIFY one, so a primary key whose
     * definition changed between releases has to be detected and rebuilt
     * explicitly — see Installer::maybe_reconcile_primary_key().
     *
     * Deliberately not memoized: it is called once per table during
     * activation / migration and its answer changes the instant the key is
     * rebuilt within the same run.
     *
     * @return list<string>
     */
    public static function primary_key_columns( string $table ): array {
        global $wpdb;
        $full = $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY'
              ORDER BY SEQ_IN_INDEX ASC",
            DB_NAME,
            $full
        ) );
        return array_values( array_map( 'strval', (array) $columns ) );
    }

    /**
     * True when the named FOREIGN KEY constraint exists in the schema.
     *
     * Constraint names are schema-unique, so the table argument is not
     * needed.
     */
    public static function foreign_key_exists( string $constraint_name ): bool {
        global $wpdb;
        $key = 'fk.' . $constraint_name;
        if ( array_key_exists( $key, self::$memo ) ) {
            return self::$memo[ $key ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = %s AND CONSTRAINT_NAME = %s
                AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            DB_NAME,
            $constraint_name
        ) );
        return self::$memo[ $key ] = ! empty( $found );
    }
}
