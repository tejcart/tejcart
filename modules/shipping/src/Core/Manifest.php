<?php
/**
 * End-of-day manifest result (e.g. USPS SCAN form, UPS pickup confirmation).
 *
 * Returned by `Abstract_Carrier_Driver::manifest()`. The `manifest_url`
 * is the carrier-supplied URL or data-URL that the merchant prints
 * once and hands to the driver / drops at the depot.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Manifest {
    /**
     * @param string[]            $tracking_numbers
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public string $carrier_id,
        public string $manifest_id,
        public string $manifest_url,
        public string $manifest_format,
        public array $tracking_numbers,
        public array $meta = array()
    ) {}
}
