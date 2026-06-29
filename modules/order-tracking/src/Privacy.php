<?php
/**
 * GDPR / CCPA personal-data exporter + eraser.
 *
 * Tracking numbers and carrier deep-links are personal data — they
 * reveal where a parcel is going. WP's Tools → Export Personal Data and
 * Tools → Erase Personal Data flows surface them via these callbacks.
 *
 * Erase strategy:
 *  - We hard-purge tracking rows for the requested email's orders.
 *    Tracking numbers are not financial records; tax authorities don't
 *    require them retained, so it's safe to remove rather than anonymise.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Privacy {
    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers',   array( $this, 'register_eraser' ) );
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function register_exporter( $exporters ) {
        $exporters['tejcart_order_tracking'] = array(
            'exporter_friendly_name' => __( 'TejCart Order Tracking', 'tejcart' ),
            'callback'               => array( $this, 'export' ),
        );
        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function register_eraser( $erasers ) {
        $erasers['tejcart_order_tracking'] = array(
            'eraser_friendly_name' => __( 'TejCart Order Tracking', 'tejcart' ),
            'callback'             => array( $this, 'erase' ),
        );
        return $erasers;
    }

    /**
     * @return array{data:array<int,array<string,mixed>>,done:bool}
     */
    public function export( string $email_address, int $page = 1 ): array {
        $email = sanitize_email( $email_address );
        $page  = max( 1, $page );
        $limit = 100;

        $rows = $this->fetch_rows_for_email( $email, $limit, ( $page - 1 ) * $limit );
        $data = array();

        $dead_letter = new Dead_Letter();

        foreach ( $rows as $row ) {
            $shipment_id = (int) $row['id'];

            // Pull any dead-letter context for this shipment so GDPR
            // exports include the failure trail too (last error code,
            // count, dead-letter flag). Tracking-related error logs
            // are personal data under GDPR Article 4(1) when they
            // include the tracking number or carrier endpoint.
            $dead_entry = $dead_letter->entry( $shipment_id );

            $fields = array(
                array( 'name' => __( 'Order ID', 'tejcart' ),        'value' => (int) $row['order_id'] ),
                array( 'name' => __( 'Carrier', 'tejcart' ),         'value' => Carriers::label( (string) $row['carrier'] ) ),
                array( 'name' => __( 'Service', 'tejcart' ),         'value' => (string) $row['service'] ),
                array( 'name' => __( 'Tracking number', 'tejcart' ), 'value' => (string) $row['tracking_number'] ),
                array( 'name' => __( 'Tracking URL', 'tejcart' ),    'value' => (string) $row['tracking_url'] ),
                array( 'name' => __( 'Status', 'tejcart' ),          'value' => (string) $row['status'] ),
                array( 'name' => __( 'Shipped at', 'tejcart' ),      'value' => (string) ( $row['shipped_at'] ?? '' ) ),
                array( 'name' => __( 'Delivered at', 'tejcart' ),    'value' => (string) ( $row['delivered_at'] ?? '' ) ),
            );

            if ( null !== $dead_entry ) {
                $fields[] = array(
                    'name'  => __( 'Tracking failures (count)', 'tejcart' ),
                    'value' => (int) ( $dead_entry['count'] ?? 0 ),
                );
                $fields[] = array(
                    'name'  => __( 'Last tracking error', 'tejcart' ),
                    'value' => (string) ( $dead_entry['last_error'] ?? '' ),
                );
                $fields[] = array(
                    'name'  => __( 'Marked undeliverable', 'tejcart' ),
                    'value' => ! empty( $dead_entry['dead'] ) ? 'yes' : 'no',
                );
            }

            $data[] = array(
                'group_id'    => 'tejcart',
                'group_label' => __( 'Order tracking', 'tejcart' ),
                'item_id'     => 'shipment-' . $shipment_id,
                'data'        => $fields,
            );
        }

        return array(
            'data' => $data,
            'done' => count( $rows ) < $limit,
        );
    }

    /**
     * @return array{items_removed:int,items_retained:int,messages:array<int,string>,done:bool}
     */
    public function erase( string $email_address, int $page = 1 ): array {
        $email = sanitize_email( $email_address );
        $limit = 100;

        // We ignore $page on purpose: purge() is a hard delete, so each
        // call shrinks the result set. Paginating with OFFSET in that
        // situation skips rows ("after page 1 deletes 100, offset 100 in
        // a now-100-rows-smaller table points past the new tail"), so
        // we always pull the oldest surviving window and rely on
        // `done` going true once the email has no more matches.
        $rows        = $this->fetch_rows_for_email( $email, $limit, 0 );
        $removed     = 0;
        $dead_letter = new Dead_Letter();

        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            if ( $this->service->repository()->purge( $id ) ) {
                // Drop the audit-trail entry too so the eraser leaves
                // no residual personal data behind.
                $dead_letter->reset( $id );
                ++$removed;
            }
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => count( $rows ) < $limit,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch_rows_for_email( string $email, int $limit, int $offset ): array {
        if ( '' === $email ) {
            return array();
        }
        global $wpdb;
        $shipments_tbl = Schema_Migrator::table_name();
        $orders_tbl    = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifiers are controlled internal values; runtime values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$shipments_tbl} s INNER JOIN {$orders_tbl} o ON o.id = s.order_id WHERE o.customer_email = %s ORDER BY s.id ASC LIMIT %d OFFSET %d", $email, $limit, $offset ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}
