<?php
/**
 * Purchased shipping label.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Label {
    public function __construct(
        public string $carrier_id,
        public string $tracking_number,
        public string $label_url,
        public string $label_format = 'PDF',
        public int $cost_cents = 0,
        public string $currency = 'USD',
        public array $meta = array()
    ) {}
}
