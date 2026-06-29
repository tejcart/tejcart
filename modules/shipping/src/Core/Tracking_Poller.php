<?php
/**
 * Action-Scheduler-driven background tracking poller.
 *
 * For carriers that don't push webhooks (USPS, UPS, FedEx via their
 * standard tracking endpoints, etc.), poll every active shipment at a
 * filterable cadence and update the local row.
 *
 * Schedules a recurring `tejcart_shipping_poll_tracking` action every
 * 6 hours (filterable). Each tick scans up to N still-in-flight
 * shipments, calls `Label_Service::track()` per shipment, and
 * persists the new status. Rate-limited via the
 * `tejcart_shipping_poll_batch_size` filter so a 50k-shipments backlog
 * doesn't fan out a million carrier calls in one tick.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tracking_Poller {
    public const HOOK = 'tejcart_shipping_poll_tracking';

    private Label_Service $service;

    public function __construct( Label_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( self::HOOK, array( $this, 'tick' ) );
        add_action( 'init', array( $this, 'maybe_schedule' ), 20 );
    }

    public function maybe_schedule(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }
        if ( as_has_scheduled_action( self::HOOK ) ) {
            return;
        }
        $interval = (int) apply_filters( 'tejcart_shipping_poll_interval', 6 * HOUR_IN_SECONDS );
        as_schedule_recurring_action( time() + 60, max( 300, $interval ), self::HOOK, array(), 'tejcart' );
    }

    public function tick(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return;
        }

        $batch  = (int) apply_filters( 'tejcart_shipping_poll_batch_size', 100 );
        $batch  = max( 1, min( $batch, 1000 ) );
        $schema = new Schema();
        $table  = $schema->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $sql = $wpdb->prepare( "SELECT id, carrier_id, tracking_number FROM {$table} WHERE tracking_number != '' AND status IN (%s, %s) ORDER BY updated_at ASC LIMIT %d", Shipment::STATUS_PURCHASED, Shipment::STATUS_IN_TRANSIT, $batch );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; prepared above.
        $rows = $wpdb->get_results( $sql, \ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            try {
                $this->service->track(
                    (string) ( $row['carrier_id'] ?? '' ),
                    (string) ( $row['tracking_number'] ?? '' ),
                    (int) ( $row['id'] ?? 0 )
                );
            } catch ( \Throwable $e ) {
                // Swallow — one bad shipment must not abort the batch.
                do_action( 'tejcart_shipping_poll_error', $e, $row );
            }
        }
    }
}
