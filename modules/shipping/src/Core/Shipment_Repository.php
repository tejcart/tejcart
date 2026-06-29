<?php
/**
 * Repository for persisted shipments.
 *
 * Idempotency: every label purchase MUST pass an idempotency key (the
 * UNIQUE index on the column enforces this at the DB level too). On a
 * duplicate insert attempt we return the existing row so caller-side
 * retries can never double-purchase a label.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Shipment_Repository {
    private Schema $schema;

    public function __construct( ?Schema $schema = null ) {
        $this->schema = $schema ?? new Schema();
    }

    /**
     * Insert a shipment row OR return the existing row for the same
     * idempotency key. Returns the persisted Shipment.
     *
     * @param array<string,mixed> $meta
     */
    public function record_label(
        int $order_id,
        Label $label,
        string $service_code,
        string $rate_id,
        string $idempotency_key,
        array $meta = array()
    ): Shipment {
        if ( '' === $idempotency_key ) {
            throw new \InvalidArgumentException( 'Shipment_Repository: idempotency_key is required.' );
        }

        $existing = $this->find_by_idempotency( $idempotency_key );
        if ( null !== $existing ) {
            return $existing;
        }

        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        // wp_json_encode() returns false on encode failure (e.g. malformed
        // UTF-8 or recursion in driver-supplied meta). Persisting `false`
        // would write an empty string and, worse, the in-memory Shipment
        // would carry empty meta — dropping the carrier void token that
        // void() later reads from meta. Fall back to an empty JSON object.
        $meta_json = wp_json_encode( array_merge( $label->meta, $meta ) );
        if ( false === $meta_json ) {
            $meta_json = '{}';
        }
        $row = array(
            'order_id'        => $order_id,
            'carrier_id'      => $label->carrier_id,
            'service_code'    => $service_code,
            'rate_id'         => $rate_id,
            'tracking_number' => $label->tracking_number,
            'label_url'       => $label->label_url,
            'label_format'    => $label->label_format,
            'cost_cents'      => $label->cost_cents,
            'currency'        => $label->currency,
            'status'          => Shipment::STATUS_PURCHASED,
            'idempotency_key' => $idempotency_key,
            'meta'            => $meta_json,
            'created_at'      => $now,
            'updated_at'      => $now,
        );

        if ( isset( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table query; no object cache layer yet
            $inserted = $wpdb->insert( $this->schema->table_name(), $row );
            if ( false === $inserted ) {
                // The UNIQUE idempotency_key index rejected a concurrent
                // duplicate: another request inserted the same key between
                // our find() above and this insert. Honour the documented
                // idempotency contract by returning the row that won the
                // race instead of a phantom shipment with id 0.
                $winner = $this->find_by_idempotency( $idempotency_key );
                if ( null !== $winner ) {
                    return $winner;
                }
            }
            $row['id'] = isset( $wpdb->insert_id ) ? (int) $wpdb->insert_id : 0;
        } else {
            $row['id'] = 0;
        }

        return Shipment::from_row( $row );
    }

    public function find_by_idempotency( string $key ): ?Shipment {
        if ( '' === $key ) {
            return null;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
            return null;
        }
        $table = $this->schema->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; prepared above.
        $row = $wpdb->get_row( $sql, \ARRAY_A );
        return is_array( $row ) ? Shipment::from_row( $row ) : null;
    }

    public function find_by_id( int $id ): ?Shipment {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
            return null;
        }
        $table = $this->schema->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; prepared above.
        $row = $wpdb->get_row( $sql, \ARRAY_A );
        return is_array( $row ) ? Shipment::from_row( $row ) : null;
    }

    /**
     * @return Shipment[]
     */
    public function find_by_order( int $order_id ): array {
        global $wpdb;
        if ( $order_id <= 0 || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return array();
        }
        $table = $this->schema->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; prepared above.
        $rows = $wpdb->get_results( $sql, \ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_map( static fn ( $row ): Shipment => Shipment::from_row( $row ), $rows );
    }

    public function update_tracking( int $id, Tracking $tracking ): void {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table query; no object cache layer yet
        $wpdb->update(
            $this->schema->table_name(),
            array(
                'status'     => $this->map_tracking_status( $tracking->status ),
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $id )
        );
    }

    public function mark_voided( int $id ): void {
        $this->claim_void( $id );
    }

    /**
     * Atomically claim a shipment for voiding. Returns true when this caller
     * won the claim (it should now invoke the carrier's void API), false when
     * the row was already in a terminal state and the caller should treat it
     * as already voided.
     *
     * Compare-and-swap semantics: the UPDATE only matches rows whose status
     * is still PURCHASED, so two concurrent void() requests cannot both call
     * the carrier API.
     */
    public function claim_void( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
            return false;
        }
        $now      = gmdate( 'Y-m-d H:i:s' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table query; no object cache layer yet
        $affected = $wpdb->update(
            $this->schema->table_name(),
            array(
                'status'     => Shipment::STATUS_VOIDED,
                'voided_at'  => $now,
                'updated_at' => $now,
            ),
            array(
                'id'     => $id,
                'status' => Shipment::STATUS_PURCHASED,
            )
        );
        return is_int( $affected ) && $affected > 0;
    }

    private function map_tracking_status( string $tracking_status ): string {
        return match ( $tracking_status ) {
            Tracking::STATUS_DELIVERED       => Shipment::STATUS_DELIVERED,
            Tracking::STATUS_IN_TRANSIT,
            Tracking::STATUS_OUT_FOR_DELIVERY => Shipment::STATUS_IN_TRANSIT,
            Tracking::STATUS_EXCEPTION,
            Tracking::STATUS_RETURNED         => Shipment::STATUS_EXCEPTION,
            default                           => Shipment::STATUS_PURCHASED,
        };
    }
}
