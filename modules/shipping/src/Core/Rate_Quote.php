<?php
/**
 * Immutable rate quote returned by a carrier driver.
 *
 * Costs are integer cents in the quote's currency (matches TejCart core's
 * money convention — never floats at the boundary).
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rate_Quote {
    public function __construct(
        public string $carrier_id,
        public string $service_code,
        public string $service_label,
        public int $cost_cents,
        public string $currency = 'USD',
        public ?int $eta_days = null,
        public ?string $rate_id = null,
        public array $meta = array()
    ) {
        if ( $cost_cents < 0 ) {
            throw new \InvalidArgumentException( 'Rate_Quote cost cannot be negative.' );
        }
        if ( '' === $carrier_id || '' === $service_code ) {
            throw new \InvalidArgumentException( 'Rate_Quote requires carrier_id and service_code.' );
        }
    }

    /**
     * Compose a stable, human-readable identifier suitable for use as the
     * checkout method id (e.g. "easypost:USPSPriority").
     */
    public function method_key(): string {
        return $this->carrier_id . ':' . $this->service_code;
    }
}
