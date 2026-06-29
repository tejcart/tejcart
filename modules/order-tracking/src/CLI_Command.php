<?php
/**
 * `wp tejcart tracking …` WP-CLI subcommands.
 *
 * The bulk-import subcommand is the high-leverage one for high-volume
 * merchants: a 3PL drops a daily CSV of (order_number, carrier,
 * tracking_number) and the merchant runs `wp tejcart tracking import …`
 * once. Sub-second per row on a warm cache; see the in-line note about
 * batching.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\WP_CLI' ) ) {
    return;
}

/**
 * Manage TejCart shipment tracking from the command line.
 */
class CLI_Command {
    private Tracking_Service $service;

    public function __construct() {
        $this->service = new Tracking_Service();
    }

    /**
     * Add a tracking row.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Order id.
     *
     * --carrier=<slug>
     * : Carrier slug (usps, ups, fedex, dhl, …).
     *
     * --tracking_number=<number>
     * : Tracking number.
     *
     * [--service=<service>]
     * : Service level (e.g. "Priority Mail").
     *
     * [--status=<status>]
     * : One of pending, label_created, shipped, in_transit,
     *   out_for_delivery, delivered, exception, returned. Defaults to "shipped".
     *
     * ## EXAMPLES
     *
     *     wp tejcart tracking add 1234 --carrier=usps --tracking_number=9400111202555842761111
     *
     * @param array<int, string>      $args
     * @param array<string, string>   $assoc
     */
    public function add( $args, $assoc ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        $payload  = array(
            'carrier'         => (string) ( $assoc['carrier'] ?? '' ),
            'tracking_number' => (string) ( $assoc['tracking_number'] ?? '' ),
            'service'         => (string) ( $assoc['service'] ?? '' ),
            'status'          => (string) ( $assoc['status'] ?? Shipment_Status::SHIPPED ),
        );
        $result   = $this->service->add( $order_id, $payload );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
        }
        \WP_CLI::success( sprintf( 'Added shipment #%d.', $result ) );
    }

    /**
     * Update a shipment.
     *
     * ## OPTIONS
     *
     * <id>
     * : Shipment id.
     *
     * [--status=<status>]
     * : Transition the shipment to a new status.
     *
     * [--carrier=<slug>]
     * [--tracking_number=<number>]
     * [--service=<service>]
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function update( $args, $assoc ): void {
        $id      = (int) ( $args[0] ?? 0 );
        $payload = array_intersect_key(
            $assoc,
            array_flip( array( 'status', 'carrier', 'tracking_number', 'service', 'cost', 'tracking_url', 'label_url' ) )
        );
        if ( empty( $payload ) ) {
            \WP_CLI::error( 'Nothing to update; pass at least one --field.' );
        }
        $result = $this->service->update( $id, $payload );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
        }
        \WP_CLI::success( 'Updated.' );
    }

    /**
     * Soft-delete a shipment.
     *
     * ## OPTIONS
     *
     * <id>
     * : Shipment id.
     *
     * @param array<int, string> $args
     */
    public function delete( $args ): void {
        $id     = (int) ( $args[0] ?? 0 );
        $result = $this->service->delete( $id );
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
        }
        \WP_CLI::success( 'Deleted.' );
    }

    /**
     * List shipments for an order as a table.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Order id.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml).
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function list( $args, $assoc ): void {
        $order_id = (int) ( $args[0] ?? 0 );
        $rows     = $this->service->for_order( $order_id );
        if ( empty( $rows ) ) {
            \WP_CLI::log( 'No shipments.' );
            return;
        }
        \WP_CLI\Utils\format_items(
            (string) ( $assoc['format'] ?? 'table' ),
            $rows,
            array( 'id', 'carrier', 'service', 'tracking_number', 'status', 'shipped_at', 'delivered_at' )
        );
    }

    /**
     * Bulk-import tracking from a CSV.
     *
     * Expected columns: order_number, carrier, tracking_number,
     * service (optional), status (optional). Header row required.
     * Order numbers are looked up against `wp_tejcart_orders.order_number`.
     *
     * Designed for daily 3PL drops — does not pull the whole file into
     * memory, processes one row at a time, and reports a per-row
     * pass/fail summary.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a CSV file. Pass `-` to read stdin.
     *
     * [--dry-run]
     * : Validate only; don't insert.
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc
     */
    public function import( $args, $assoc ): void {
        $file = (string) ( $args[0] ?? '' );
        if ( '' === $file ) {
            \WP_CLI::error( 'CSV path required.' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV streaming requires native file handle
        $handle = '-' === $file ? fopen( 'php://stdin', 'r' ) : fopen( $file, 'r' );
        if ( ! is_resource( $handle ) ) {
            \WP_CLI::error( 'Could not open ' . $file );
        }

        $dry_run = isset( $assoc['dry-run'] );
        $header  = fgetcsv( $handle );
        if ( ! is_array( $header ) ) {
            \WP_CLI::error( 'CSV is empty.' );
        }
        $header = array_map( 'strtolower', array_map( 'trim', $header ) );
        $idx    = array_flip( $header );
        foreach ( array( 'order_number', 'carrier', 'tracking_number' ) as $required ) {
            if ( ! isset( $idx[ $required ] ) ) {
                \WP_CLI::error( "CSV missing required column: {$required}" );
            }
        }

        global $wpdb;
        $orders_tbl = $wpdb->prefix . 'tejcart_orders';

        $ok      = 0;
        $skipped = 0;
        $errors  = 0;
        $line    = 1;

        while ( false !== ( $row = fgetcsv( $handle ) ) ) {
            ++$line;
            if ( null === $row[0] && count( $row ) === 1 ) {
                continue;
            }
            $order_number    = trim( (string) ( $row[ $idx['order_number'] ] ?? '' ) );
            $carrier         = sanitize_key( (string) ( $row[ $idx['carrier'] ] ?? '' ) );
            $tracking_number = trim( (string) ( $row[ $idx['tracking_number'] ] ?? '' ) );
            $service         = isset( $idx['service'] ) ? trim( (string) ( $row[ $idx['service'] ] ?? '' ) ) : '';
            $status          = isset( $idx['status'] )  ? trim( (string) ( $row[ $idx['status'] ]  ?? '' ) ) : Shipment_Status::SHIPPED;

            if ( '' === $order_number || '' === $carrier || '' === $tracking_number ) {
                ++$skipped;
                \WP_CLI::warning( "Line {$line}: missing required field." );
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders_tbl} WHERE order_number = %s LIMIT 1", $order_number ) );
            if ( $order_id <= 0 ) {
                ++$skipped;
                \WP_CLI::warning( "Line {$line}: order {$order_number} not found." );
                continue;
            }

            if ( $dry_run ) {
                ++$ok;
                continue;
            }

            $result = $this->service->add(
                $order_id,
                array(
                    'carrier'         => $carrier,
                    'tracking_number' => $tracking_number,
                    'service'         => $service,
                    'status'          => $status,
                )
            );

            if ( is_wp_error( $result ) ) {
                ++$errors;
                \WP_CLI::warning( "Line {$line}: " . $result->get_error_message() );
                continue;
            }
            ++$ok;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streaming requires native file handle
        fclose( $handle );

        \WP_CLI::success( sprintf(
            '%s: %d ok, %d skipped, %d error(s).',
            $dry_run ? 'Validated' : 'Imported',
            $ok,
            $skipped,
            $errors
        ) );
    }
}
