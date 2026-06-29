<?php
/**
 * Listens for `tejcart_order_status_changed` and reacts.
 *
 * - When an order moves to "delivered" or "completed" without an
 *   explicit per-shipment `delivered_at`, mark all in-flight shipments
 *   on that order as delivered too. This keeps reports accurate even
 *   for merchants who manually mark orders complete from the admin
 *   without touching individual parcels.
 *
 * - When an order moves to "cancelled" or "refunded", we do NOT delete
 *   the tracking rows — the audit history is part of dispute evidence.
 *   We just emit a `tejcart_order_tracking_order_cancelled` action so
 *   sibling plugins (e.g. tejcart-disputes) can link the event.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Status_Listener {
    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'tejcart_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 3 );
    }

    /**
     * @param string $old_status
     * @param string $new_status
     * @param mixed  $order
     */
    public function on_order_status_changed( $old_status, $new_status, $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }

        if ( in_array( (string) $new_status, array( 'delivered', 'completed' ), true ) ) {
            $this->mark_in_flight_delivered( $order_id );
            return;
        }

        if ( in_array( (string) $new_status, array( 'cancelled', 'refunded' ), true ) ) {
            /**
             * Fires when the underlying order is cancelled or refunded
             * while shipments exist. Listeners can void labels, etc.
             *
             * @param int    $order_id
             * @param string $new_status
             */
            do_action( 'tejcart_order_tracking_order_cancelled', $order_id, (string) $new_status );
        }
    }

    private function mark_in_flight_delivered( int $order_id ): void {
        $shipments = $this->service->repository()->find_for_order( $order_id );
        foreach ( $shipments as $row ) {
            $status = (string) ( $row['status'] ?? '' );
            if ( Shipment_Status::is_terminal( $status ) ) {
                continue;
            }
            // Walk the state machine: from intermediate states, jump
            // directly to delivered. From "pending" / "label_created"
            // we go via in_transit so the transition graph stays valid.
            $sequence = match ( $status ) {
                Shipment_Status::PENDING, Shipment_Status::LABEL_CREATED => array( Shipment_Status::IN_TRANSIT, Shipment_Status::DELIVERED ),
                default => array( Shipment_Status::DELIVERED ),
            };
            foreach ( $sequence as $next ) {
                $result = $this->service->update( (int) $row['id'], array( 'status' => $next ) );
                if ( is_wp_error( $result ) ) {
                    break;
                }
            }
        }
    }
}
