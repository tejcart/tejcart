<?php
/**
 * Shipment status state machine.
 *
 * The lifecycle a tracked parcel can move through. We deliberately mirror
 * the canonical EasyPost / Shippo / AfterShip vocabulary so that future
 * carrier-driver siblings can map their own statuses 1:1.
 *
 *   pending → label_created → in_transit → out_for_delivery → delivered
 *                                              ↘ exception ↘ returned
 *
 * Once `delivered` or `returned` is reached the parcel is terminal and
 * further updates are rejected (so a stale carrier webhook can't reopen
 * it). `exception` is recoverable — a carrier may move back to
 * `in_transit` once a delivery attempt is retried.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Shipment_Status {
    public const PENDING          = 'pending';
    public const LABEL_CREATED    = 'label_created';
    public const SHIPPED          = 'shipped';
    public const IN_TRANSIT       = 'in_transit';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED        = 'delivered';
    public const EXCEPTION        = 'exception';
    public const RETURNED         = 'returned';

    /**
     * @return array<int, string>
     */
    public static function all(): array {
        return array(
            self::PENDING,
            self::LABEL_CREATED,
            self::SHIPPED,
            self::IN_TRANSIT,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
            self::EXCEPTION,
            self::RETURNED,
        );
    }

    public static function is_valid( string $status ): bool {
        return in_array( $status, self::all(), true );
    }

    public static function is_terminal( string $status ): bool {
        return self::DELIVERED === $status || self::RETURNED === $status;
    }

    /**
     * Allowed transitions from → to.
     *
     * Filterable so that bespoke carrier integrations can teach the state
     * machine new edges (e.g. a regional postal API that emits
     * `held_at_customs`).
     *
     * @return array<string, array<int, string>>
     */
    public static function transitions(): array {
        $defaults = array(
            self::PENDING          => array( self::LABEL_CREATED, self::SHIPPED, self::IN_TRANSIT, self::EXCEPTION ),
            self::LABEL_CREATED    => array( self::SHIPPED, self::IN_TRANSIT, self::EXCEPTION ),
            self::SHIPPED          => array( self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::DELIVERED, self::EXCEPTION, self::RETURNED ),
            self::IN_TRANSIT       => array( self::OUT_FOR_DELIVERY, self::DELIVERED, self::EXCEPTION, self::RETURNED ),
            self::OUT_FOR_DELIVERY => array( self::DELIVERED, self::EXCEPTION, self::RETURNED ),
            self::EXCEPTION        => array( self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::DELIVERED, self::RETURNED ),
            self::DELIVERED        => array(),
            self::RETURNED         => array(),
        );

        /**
         * Filter the shipment status transition graph.
         *
         * @param array<string, array<int, string>> $defaults
         */
        return (array) apply_filters( 'tejcart_shipment_status_transitions', $defaults );
    }

    public static function can_transition( string $from, string $to ): bool {
        if ( $from === $to ) {
            return false;
        }
        $graph   = self::transitions();
        $allowed = $graph[ $from ] ?? array();
        return in_array( $to, $allowed, true );
    }
}
