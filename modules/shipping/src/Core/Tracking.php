<?php
/**
 * Tracking lookup result.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tracking {
    public const STATUS_UNKNOWN    = 'unknown';
    public const STATUS_PRE_TRANSIT = 'pre_transit';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_EXCEPTION  = 'exception';
    public const STATUS_RETURNED   = 'returned';

    /**
     * @param array<int,array{timestamp:int,status:string,description:string,location:string}> $events
     */
    public function __construct(
        public string $carrier_id,
        public string $tracking_number,
        public string $status,
        public array $events = array(),
        public ?int $estimated_delivery = null
    ) {}
}
