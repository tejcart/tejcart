<?php
/**
 * Persisted audit log for every shipment write.
 *
 * The action-hook surface (`tejcart_order_tracking_added`,
 * `_updated`, `_deleted`, `tejcart_shipment_status_changed`) is good
 * for sibling integration but vanishes after the request ends. This
 * class durably records each event in `wp_tejcart_shipment_audit` so
 * SOC2/PCI-style "who did what when" queries are answerable later.
 *
 * Each row captures:
 *  - `event`      added | updated | deleted | status_changed
 *  - `actor_id`   user id (0 for system / poller / webhook)
 *  - `actor_kind` 'user' | 'system' (poller, webhook, CLI)
 *  - `payload`    JSON, kind-specific (the new row, the diff, etc.)
 *
 * The audit table is hard-cap pruned by the retention cron — same
 * horizon as soft-deleted rows.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Audit_Log {
    public const EVENT_ADDED          = 'added';
    public const EVENT_UPDATED        = 'updated';
    public const EVENT_DELETED        = 'deleted';
    public const EVENT_STATUS_CHANGED = 'status_changed';

    public function register(): void {
        add_action( 'tejcart_order_tracking_added',     array( $this, 'on_added' ),    10, 3 );
        add_action( 'tejcart_order_tracking_updated',   array( $this, 'on_updated' ),  10, 4 );
        add_action( 'tejcart_order_tracking_deleted',   array( $this, 'on_deleted' ),  10, 3 );
        add_action( 'tejcart_shipment_status_changed',  array( $this, 'on_status' ),   10, 4 );
    }

    /**
     * @param int                  $order_id
     * @param int                  $shipment_id
     * @param array<string, mixed> $data
     */
    public function on_added( $order_id, $shipment_id, $data ): void {
        $this->record( self::EVENT_ADDED, (int) $order_id, (int) $shipment_id, array(
            'data' => $this->scrub( is_array( $data ) ? $data : array() ),
        ) );
    }

    /**
     * @param int                  $order_id
     * @param int                  $shipment_id
     * @param array<string, mixed> $new_row
     * @param array<string, mixed> $old_row
     */
    public function on_updated( $order_id, $shipment_id, $new_row, $old_row ): void {
        $this->record( self::EVENT_UPDATED, (int) $order_id, (int) $shipment_id, array(
            'before' => $this->scrub( is_array( $old_row ) ? $old_row : array() ),
            'after'  => $this->scrub( is_array( $new_row ) ? $new_row : array() ),
        ) );
    }

    /**
     * @param int                  $order_id
     * @param int                  $shipment_id
     * @param array<string, mixed> $row
     */
    public function on_deleted( $order_id, $shipment_id, $row ): void {
        $this->record( self::EVENT_DELETED, (int) $order_id, (int) $shipment_id, array(
            'row' => $this->scrub( is_array( $row ) ? $row : array() ),
        ) );
    }

    /**
     * @param int    $order_id
     * @param int    $shipment_id
     * @param string $from
     * @param string $to
     */
    public function on_status( $order_id, $shipment_id, $from, $to ): void {
        $this->record( self::EVENT_STATUS_CHANGED, (int) $order_id, (int) $shipment_id, array(
            'from' => (string) $from,
            'to'   => (string) $to,
        ) );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record( string $event, int $order_id, int $shipment_id, array $payload ): void {
        global $wpdb;

        $actor_id   = (int) ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        $actor_kind = $actor_id > 0 ? 'user' : 'system';
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $actor_kind = 'cli';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST && 0 === $actor_id ) {
            $actor_kind = 'webhook';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            Schema_Migrator::audit_table_name(),
            array(
                'shipment_id' => $shipment_id,
                'order_id'    => $order_id,
                'event'       => $event,
                'actor_id'    => $actor_id,
                'actor_kind'  => $actor_kind,
                'payload'     => wp_json_encode( $payload ),
                'created_at'  => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Strip PII-equivalent fields before persisting. Tracking numbers
     * themselves are kept (they're the whole point of the audit), but
     * the carrier's raw `meta` blob can hold arbitrary nested data we
     * don't want indefinitely retained.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function scrub( array $row ): array {
        unset( $row['meta'] );
        return $row;
    }

    /**
     * Prune audit rows older than `$horizon_seconds`. Called from the
     * retention cron. Returns the number of rows removed.
     */
    public static function prune_older_than( int $horizon_seconds, int $batch = 1000 ): int {
        global $wpdb;
        if ( $horizon_seconds <= 0 ) {
            return 0;
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $horizon_seconds );
        $table  = Schema_Migrator::audit_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, $batch ) );
        return $deleted;
    }
}
