<?php
/**
 * Shipment repository.
 *
 * The only class that talks to the `tejcart_shipments` table directly.
 * Everything else (Service, REST, AJAX, CLI, Privacy) goes through this
 * façade so caching and audit fields stay consistent.
 *
 * Caching strategy:
 *  - Per-order shipment list cached under
 *    `tejcart_order_tracking:order:{order_id}` in the
 *    `tejcart_order_tracking` cache group.
 *  - Per-(carrier, tracking_number) reverse lookup cached under
 *    `tejcart_order_tracking:track:{md5}` for inbound carrier webhooks.
 *  - Both keys are invalidated on any write/delete to the affected order
 *    or tracking number.
 *
 * Soft delete:
 *  - `delete()` sets `deleted_at` rather than removing the row, so admin
 *    audit and dispute workflows can reconstruct what was shipped when.
 *  - `purge()` is the hard-delete path used by GDPR erasers and the
 *    retention cron.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shipment_Repository {
    public const CACHE_GROUP = 'tejcart_order_tracking';

    /**
     * Insert a new shipment row. Caller is responsible for sanitising the
     * payload — Service is the public entry point.
     *
     * @param array<string, mixed> $data
     * @return int|\WP_Error  New shipment id, or WP_Error on duplicate / DB failure.
     */
    public function insert( array $data ): int|\WP_Error {
        global $wpdb;

        $now    = current_time( 'mysql', true );
        $row    = array(
            'order_id'        => (int) ( $data['order_id'] ?? 0 ),
            'carrier'         => (string) ( $data['carrier'] ?? '' ),
            'service'         => (string) ( $data['service'] ?? '' ),
            'tracking_number' => (string) ( $data['tracking_number'] ?? '' ),
            'tracking_url'    => (string) ( $data['tracking_url'] ?? '' ),
            'label_url'       => (string) ( $data['label_url'] ?? '' ),
            'status'          => (string) ( $data['status'] ?? Shipment_Status::PENDING ),
            'cost'            => (float)  ( $data['cost'] ?? 0 ),
            'shipped_at'      => $data['shipped_at']   ?? null,
            'delivered_at'    => $data['delivered_at'] ?? null,
            'meta'            => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
            'created_by'      => (int) ( $data['created_by'] ?? 0 ),
            'created_at'      => $now,
            'updated_at'      => $now,
        );

        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert( Schema_Migrator::table_name(), $row, $formats );

        if ( false === $result ) {
            $error = is_string( $wpdb->last_error ) ? $wpdb->last_error : '';
            if ( '' !== $error && false !== stripos( $error, 'duplicate' ) ) {
                return new \WP_Error(
                    'duplicate_tracking',
                    __( 'This tracking number is already attached to the order.', 'tejcart' )
                );
            }
            return new \WP_Error( 'db_error', __( 'Could not save shipment.', 'tejcart' ) );
        }

        $id = (int) $wpdb->insert_id;
        $this->invalidate_order_cache( $row['order_id'] );
        $this->invalidate_tracking_cache( $row['carrier'], $row['tracking_number'] );

        return $id;
    }

    /**
     * Update an existing shipment row by id. Sets `updated_at` to NOW.
     *
     * @param array<string, mixed> $data Whitelisted columns.
     */
    public function update( int $id, array $data ): bool {
        if ( $id <= 0 || empty( $data ) ) {
            return false;
        }

        global $wpdb;

        $existing = $this->find( $id );
        if ( null === $existing ) {
            return false;
        }

        $allowed = array(
            'carrier', 'service', 'tracking_number', 'tracking_url', 'label_url',
            'status', 'cost', 'shipped_at', 'delivered_at', 'meta',
        );
        $row     = array();
        $formats = array();

        foreach ( $allowed as $col ) {
            if ( ! array_key_exists( $col, $data ) ) {
                continue;
            }
            $row[ $col ] = 'meta' === $col && null !== $data[ $col ] && ! is_string( $data[ $col ] )
                ? wp_json_encode( $data[ $col ] )
                : $data[ $col ];
            $formats[]   = match ( $col ) {
                'cost'                 => '%f',
                default                => '%s',
            };
        }

        if ( empty( $row ) ) {
            return false;
        }

        $row['updated_at'] = current_time( 'mysql', true );
        $formats[]         = '%s';

        // Compare-and-swap on status: when the caller is changing the
        // status column we constrain the WHERE clause to the previously
        // observed value. That makes the update monotonic — a slow
        // poller cannot revert a status that a concurrent webhook just
        // advanced (e.g. webhook applies DELIVERED at 14:00:00.100,
        // poll fetches stale IN_TRANSIT and tries to overwrite at
        // 14:00:00.150 → the CAS WHERE no longer matches and the
        // overwrite is a no-op). At high volume this race is otherwise
        // certain. Updates that do not touch `status` keep the simple
        // id-only WHERE so column-only fixes (e.g. backfilling
        // tracking_url) are not blocked.
        $where         = array( 'id' => $id );
        $where_formats = array( '%d' );
        if ( isset( $row['status'] ) && (string) $row['status'] !== (string) $existing['status'] ) {
            $where['status']  = (string) $existing['status'];
            $where_formats[]  = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update( Schema_Migrator::table_name(), $row, $where, $formats, $where_formats );

        if ( false === $result ) {
            return false;
        }

        // Zero affected rows + a CAS clause means a concurrent writer
        // beat us; signal failure so the caller can re-fetch and decide.
        // Without a CAS clause, zero rows-affected just means the values
        // were already current — that is still success from the caller's
        // perspective.
        if ( 0 === (int) $result && isset( $where['status'] ) ) {
            return false;
        }

        $this->invalidate_order_cache( (int) $existing['order_id'] );
        $this->invalidate_tracking_cache( (string) $existing['carrier'], (string) $existing['tracking_number'] );
        if ( isset( $row['carrier'] ) || isset( $row['tracking_number'] ) ) {
            $this->invalidate_tracking_cache(
                (string) ( $row['carrier'] ?? $existing['carrier'] ),
                (string) ( $row['tracking_number'] ?? $existing['tracking_number'] )
            );
        }

        return true;
    }

    /**
     * Soft-delete: set `deleted_at` so reads filter the row out without
     * losing the audit trail.
     */
    public function delete( int $id ): bool {
        if ( $id <= 0 ) {
            return false;
        }

        $existing = $this->find( $id );
        if ( null === $existing ) {
            return false;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            Schema_Migrator::table_name(),
            array(
                'deleted_at' => current_time( 'mysql', true ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            return false;
        }

        $this->invalidate_order_cache( (int) $existing['order_id'] );
        $this->invalidate_tracking_cache( (string) $existing['carrier'], (string) $existing['tracking_number'] );

        return true;
    }

    /**
     * Hard delete (used by GDPR eraser and retention purge).
     */
    public function purge( int $id ): bool {
        if ( $id <= 0 ) {
            return false;
        }
        $existing = $this->find( $id );
        if ( null === $existing ) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete( Schema_Migrator::table_name(), array( 'id' => $id ), array( '%d' ) );
        if ( false === $deleted ) {
            return false;
        }
        $this->invalidate_order_cache( (int) $existing['order_id'] );
        $this->invalidate_tracking_cache( (string) $existing['carrier'], (string) $existing['tracking_number'] );
        return true;
    }

    /**
     * Look up a single shipment by id, including soft-deleted rows. Used
     * by update/delete/purge so internal callers can act on archived rows.
     *
     * @return array<string, mixed>|null
     */
    public function find( int $id ): ?array {
        if ( $id <= 0 ) {
            return null;
        }
        global $wpdb;
        $table = Schema_Migrator::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Default cap on how many shipments per order we'll return from a
     * single `find_for_order()` call. The metabox + frontend templates
     * never legitimately need more than a handful; the cap protects
     * the page render against pathological orders (1000+ shipments
     * from a buggy CSV import or a stuck webhook loop).
     *
     * Override per-call by passing a different `$limit`, or globally
     * via the `tejcart_order_tracking_for_order_limit` filter.
     */
    public const FOR_ORDER_DEFAULT_LIMIT = 100;

    /**
     * All shipments for an order, oldest first, soft-deleted excluded.
     *
     * Cached per order in the `tejcart_order_tracking` cache group.
     * Capped to `FOR_ORDER_DEFAULT_LIMIT` rows by default — see the
     * constant for the rationale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function find_for_order( int $order_id, int $limit = 0, int $offset = 0 ): array {
        if ( $order_id <= 0 ) {
            return array();
        }

        if ( $limit <= 0 ) {
            $limit = self::FOR_ORDER_DEFAULT_LIMIT;
            if ( function_exists( 'apply_filters' ) ) {
                $limit = (int) apply_filters( 'tejcart_order_tracking_for_order_limit', $limit, $order_id );
            }
        }
        $limit  = max( 1, min( 1000, $limit ) );
        $offset = max( 0, $offset );

        // Only the un-paginated default-limit path uses the cache.
        // Custom limit/offset combinations bypass it to keep the
        // cache-key surface bounded.
        $use_cache = ( 0 === $offset && self::FOR_ORDER_DEFAULT_LIMIT === $limit );

        if ( $use_cache ) {
            $key    = self::cache_key_for_order( $order_id );
            $cached = wp_cache_get( $key, self::CACHE_GROUP );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        global $wpdb;
        $table = Schema_Migrator::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d OFFSET %d", $order_id, $limit, $offset ), ARRAY_A );
        $rows  = is_array( $rows ) ? $rows : array();
        if ( ! $use_cache ) {
            return $rows;
        }
        $key = self::cache_key_for_order( $order_id );

        wp_cache_set( $key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
        return $rows;
    }

    /**
     * Reverse lookup: find a shipment by (carrier, tracking_number). Used
     * by carrier webhook receivers. Cached so a high-frequency webhook
     * stream doesn't hammer the DB.
     *
     * @return array<string, mixed>|null
     */
    public function find_by_tracking( string $carrier, string $tracking_number ): ?array {
        $carrier         = sanitize_key( $carrier );
        $tracking_number = trim( $tracking_number );
        if ( '' === $carrier || '' === $tracking_number ) {
            return null;
        }

        $key    = self::cache_key_for_tracking( $carrier, $tracking_number );
        $found  = false;
        $cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
        if ( $found ) {
            // Distinguish "looked up and not found" (stored `false`) from
            // "never looked up" via the WP-supplied `$found` flag —
            // without it, a vanilla cache miss is indistinguishable from
            // a stored `false` and would silently skip the DB lookup.
            return is_array( $cached ) ? $cached : null;
        }

        global $wpdb;
        $table = Schema_Migrator::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE carrier = %s AND tracking_number = %s AND deleted_at IS NULL LIMIT 1", $carrier, $tracking_number ), ARRAY_A );

        if ( ! is_array( $row ) ) {
            wp_cache_set( $key, false, self::CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
            return null;
        }

        wp_cache_set( $key, $row, self::CACHE_GROUP, HOUR_IN_SECONDS );
        return $row;
    }

    public function invalidate_order_cache( int $order_id ): void {
        if ( $order_id > 0 ) {
            wp_cache_delete( self::cache_key_for_order( $order_id ), self::CACHE_GROUP );
        }
    }

    public function invalidate_tracking_cache( string $carrier, string $tracking_number ): void {
        if ( '' !== $carrier && '' !== $tracking_number ) {
            wp_cache_delete( self::cache_key_for_tracking( $carrier, $tracking_number ), self::CACHE_GROUP );
        }
    }

    public static function cache_key_for_order( int $order_id ): string {
        return 'order:' . $order_id;
    }

    public static function cache_key_for_tracking( string $carrier, string $tracking_number ): string {
        return 'track:' . md5( $carrier . '|' . $tracking_number );
    }
}
