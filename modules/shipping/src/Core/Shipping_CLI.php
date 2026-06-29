<?php
/**
 * WP-CLI commands for the TejCart Shipping addon.
 *
 * Registered as `wp tejcart-shipping <subcommand>` when WP-CLI is loaded.
 *
 * Subcommands:
 *   list-carriers    Show every registered driver, region, and credential status.
 *   poll-tracking    Run a single Tracking_Poller tick synchronously.
 *   buy-label        Purchase a label for an order using its persisted rate token.
 *   void-label       Void a previously purchased shipment.
 *   track            Refresh tracking for one shipment.
 *   shipments        Print shipments, optionally filtered by order.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Shipping_CLI {
    /**
     * List every registered carrier driver and whether credentials are
     * present for it.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping list-carriers
     *
     * @subcommand list-carriers
     */
    public function list_carriers( $args, $assoc_args ): void {
        $plugin = Plugin::instance();
        $rows   = array();
        $vault  = $plugin->vault();

        foreach ( $plugin->registry()->all() as $driver ) {
            $creds      = $vault->get( $driver->id() );
            $configured = array() !== array_filter(
                $creds,
                static fn ( $v ): bool => '' !== (string) $v
            );

            $rows[] = array(
                'id'         => $driver->id(),
                'label'      => $driver->label(),
                'region'     => $driver->region(),
                'configured' => $configured ? 'yes' : 'no',
            );
        }

        \WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'label', 'region', 'configured' ) );
    }

    /**
     * Run one Tracking_Poller tick now (synchronous).
     *
     * Useful when validating a deployment or when Action Scheduler is
     * misbehaving and a manual nudge is needed.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping poll-tracking
     *
     * @subcommand poll-tracking
     */
    public function poll_tracking( $args, $assoc_args ): void {
        $plugin  = Plugin::instance();
        $service = $plugin->label_service();
        ( new Tracking_Poller( $service ) )->tick();
        \WP_CLI::success( 'Tracking poll completed.' );
    }

    /**
     * Purchase a label for an order using the rate token persisted at
     * checkout. Idempotent — a second call returns the same shipment.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : ID of the order to ship.
     *
     * [--carrier=<carrier-id>]
     * : Override the carrier id. Defaults to the order's persisted carrier.
     *
     * [--rate=<rate-id>]
     * : Override the rate token. Defaults to the order's persisted rate.
     *
     * [--service=<service-code>]
     * : Override the service code. Defaults to the order's persisted service.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping buy-label 4321
     *     wp tejcart-shipping buy-label 4321 --carrier=easypost --rate=rate_abc123 --service=USPSPriority
     *
     * @subcommand buy-label
     */
    public function buy_label( $args, $assoc_args ): void {
        $order_id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( $order_id <= 0 ) {
            \WP_CLI::error( 'order_id is required.' );
            return;
        }

        $plugin = Plugin::instance();
        $order  = $this->load_order( $order_id );
        if ( null === $order ) {
            \WP_CLI::error( sprintf( 'Order %d not found.', $order_id ) );
            return;
        }

        $carrier_id   = isset( $assoc_args['carrier'] )
            ? (string) $assoc_args['carrier']
            : (string) $order->get_meta( Order_Integration::META_CARRIER_ID );
        $rate_id      = isset( $assoc_args['rate'] )
            ? (string) $assoc_args['rate']
            : (string) $order->get_meta( Order_Integration::META_RATE_ID );
        $service_code = isset( $assoc_args['service'] )
            ? (string) $assoc_args['service']
            : (string) $order->get_meta( Order_Integration::META_SERVICE_CODE );

        if ( '' === $carrier_id || '' === $rate_id ) {
            \WP_CLI::error( 'Order has no persisted carrier or rate token. Pass --carrier= and --rate= explicitly.' );
            return;
        }

        try {
            $shipment = $plugin->label_service()->purchase( $carrier_id, $order_id, $rate_id, $service_code );
        } catch ( Carrier_Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
            return;
        }

        \WP_CLI::success( sprintf(
            'Shipment #%d created — %s tracking %s.',
            $shipment->id,
            $shipment->carrier_id,
            '' === $shipment->tracking_number ? '(pending)' : $shipment->tracking_number
        ) );
    }

    /**
     * Void a previously purchased label.
     *
     * ## OPTIONS
     *
     * <shipment_id>
     * : ID of the shipment row to void.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping void-label 17
     *
     * @subcommand void-label
     */
    public function void_label( $args, $assoc_args ): void {
        $shipment_id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( $shipment_id <= 0 ) {
            \WP_CLI::error( 'shipment_id is required.' );
            return;
        }

        try {
            $shipment = Plugin::instance()->label_service()->void( $shipment_id );
        } catch ( Carrier_Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
            return;
        }

        \WP_CLI::success( sprintf( 'Shipment #%d status: %s.', $shipment->id, $shipment->status ) );
    }

    /**
     * Refresh tracking for one shipment.
     *
     * ## OPTIONS
     *
     * <shipment_id>
     * : ID of the shipment row to refresh.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping track 17
     *
     * @subcommand track
     */
    public function track( $args, $assoc_args ): void {
        $shipment_id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( $shipment_id <= 0 ) {
            \WP_CLI::error( 'shipment_id is required.' );
            return;
        }

        $plugin   = Plugin::instance();
        $shipment = $plugin->shipments()->find_by_id( $shipment_id );
        if ( null === $shipment ) {
            \WP_CLI::error( sprintf( 'Shipment %d not found.', $shipment_id ) );
            return;
        }
        if ( '' === $shipment->tracking_number ) {
            \WP_CLI::error( 'Shipment has no tracking number yet.' );
            return;
        }

        try {
            $tracking = $plugin->label_service()->track( $shipment->carrier_id, $shipment->tracking_number, $shipment->id );
        } catch ( Carrier_Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
            return;
        }

        \WP_CLI::success( sprintf( 'Status: %s — %d events.', $tracking->status, count( $tracking->events ) ) );
    }

    /**
     * List shipments — optionally filtered by order.
     *
     * ## OPTIONS
     *
     * [--order=<order-id>]
     * : Restrict to one order.
     *
     * ## EXAMPLES
     *
     *     wp tejcart-shipping shipments
     *     wp tejcart-shipping shipments --order=4321
     *
     * @subcommand shipments
     */
    public function shipments( $args, $assoc_args ): void {
        $plugin   = Plugin::instance();
        $repo     = $plugin->shipments();
        $order_id = isset( $assoc_args['order'] ) ? (int) $assoc_args['order'] : 0;

        if ( $order_id > 0 ) {
            $shipments = $repo->find_by_order( $order_id );
        } else {
            $shipments = $this->all_shipments();
        }

        if ( array() === $shipments ) {
            \WP_CLI::log( 'No shipments found.' );
            return;
        }

        $rows = array();
        foreach ( $shipments as $shipment ) {
            $rows[] = array(
                'id'        => $shipment->id,
                'order'     => $shipment->order_id,
                'carrier'   => $shipment->carrier_id,
                'service'   => $shipment->service_code,
                'tracking'  => $shipment->tracking_number,
                'status'    => $shipment->status,
                'cost'      => sprintf( '%s %s', number_format( $shipment->cost_cents / \TejCart\Money\Currency::multiplier( $shipment->currency ), \TejCart\Money\Currency::decimals( $shipment->currency ) ), $shipment->currency ),
            );
        }

        \WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'order', 'carrier', 'service', 'tracking', 'status', 'cost' ) );
    }

    /**
     * @return Shipment[]
     */
    private function all_shipments(): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return array();
        }
        $schema = new Schema();
        $table  = $schema->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; no user input.
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", \ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_map( static fn ( $row ): Shipment => Shipment::from_row( $row ), $rows );
    }

    private function load_order( int $order_id ) {
        // F-MODL-009: Use tejcart_get_order() instead of newing up \TejCart\Order\Order.
        if ( ! function_exists( 'tejcart_get_order' ) ) {
            return null;
        }
        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || (int) $order->get_id() <= 0 ) {
            return null;
        }
        return $order;
    }
}
