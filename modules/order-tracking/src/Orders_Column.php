<?php
/**
 * Add a "Tracking" column to the admin orders list.
 *
 * Binds to core's `tejcart_orders_table_columns` filter (added at the
 * same time as the metabox hook) to surface a one-line tracking
 * summary per order — the shipment count, plus a status pill if the
 * order has at least one shipment.
 *
 * Operators can scan a thousand-row orders list and immediately spot
 * the orders that still need tracking, without opening each one.
 *
 * Read path is fully cached: each cell calls the cached
 * `Tracking_Service::for_order()`, so a 50-row admin page is at most
 * 50 cache hits (which itself is one DB query when the cache is cold,
 * via the per-order key).
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Orders_Column {
    public const COLUMN_KEY = 'tejcart_tracking';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        if ( ! self::display_enabled() ) {
            return;
        }
        add_filter( 'tejcart_orders_table_columns',      array( $this, 'add_column' ) );
        add_filter( 'tejcart_orders_table_column_value', array( $this, 'render_cell' ), 10, 3 );
    }

    /**
     * Mirror the "Admin Orders list" toggle exposed on the Display
     * sub-tab. Defaults ON so existing installs keep the column without
     * needing a one-time settings save.
     */
    private static function display_enabled(): bool {
        if ( ! class_exists( Settings::class ) ) {
            return true;
        }
        return (bool) Settings::get( 'display_orders_column', 1 );
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_column( $columns ) {
        if ( ! is_array( $columns ) ) {
            return array( self::COLUMN_KEY => __( 'Tracking', 'tejcart' ) );
        }
        // Insert before created_at when present, else append.
        $insert_at = 'created_at';
        $new       = array();
        $inserted  = false;
        foreach ( $columns as $key => $label ) {
            if ( $key === $insert_at ) {
                $new[ self::COLUMN_KEY ] = __( 'Tracking', 'tejcart' );
                $inserted = true;
            }
            $new[ $key ] = $label;
        }
        if ( ! $inserted ) {
            $new[ self::COLUMN_KEY ] = __( 'Tracking', 'tejcart' );
        }
        return $new;
    }

    /**
     * @param mixed                $value
     * @param string               $column_name
     * @param array<string, mixed> $item
     * @return mixed
     */
    public function render_cell( $value, $column_name, $item ) {
        if ( self::COLUMN_KEY !== $column_name ) {
            return $value;
        }
        $order_id  = (int) ( $item['id'] ?? 0 );
        if ( $order_id <= 0 ) {
            return '<span class="nxc-muted">—</span>';
        }
        $shipments = $this->service->for_order( $order_id );
        if ( empty( $shipments ) ) {
            return '<span class="nxc-muted">—</span>';
        }
        // Show the dominant status (most-recent non-terminal, or
        // delivered if all are delivered) plus a count badge.
        $dominant = $this->dominant_status( $shipments );
        $count    = count( $shipments );

        return sprintf(
            '<span class="tejcart-status-pill tejcart-status-pill--%s">%s</span>%s',
            esc_attr( $dominant ),
            esc_html( Admin_Metabox::status_label( $dominant ) ),
            $count > 1 ? ' <small class="nxc-muted">×' . esc_html( (string) $count ) . '</small>' : ''
        );
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     */
    private function dominant_status( array $shipments ): string {
        // If any shipment is non-terminal, that's what the operator
        // cares about — show the latest (highest id) one. Otherwise
        // all are terminal and we fall back to delivered.
        $latest = null;
        foreach ( $shipments as $row ) {
            $status = (string) ( $row['status'] ?? Shipment_Status::PENDING );
            if ( ! Shipment_Status::is_terminal( $status ) ) {
                if ( null === $latest || (int) $row['id'] > (int) $latest['id'] ) {
                    $latest = $row;
                }
            }
        }
        if ( null !== $latest ) {
            return (string) $latest['status'];
        }
        return Shipment_Status::DELIVERED;
    }
}
