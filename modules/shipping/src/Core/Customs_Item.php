<?php
/**
 * Single line on a customs declaration / commercial invoice.
 *
 * Required by every cross-border carrier (FedEx International,
 * UPS WorldShip, DHL Express, USPS GXG, Royal Mail International,
 * etc.). The schema is the union of fields those carriers ask for —
 * each driver maps the relevant subset.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Customs_Item {
    public function __construct(
        public string $description,
        public int $quantity,
        public int $value_cents,
        public string $currency,
        public int $weight_grams,
        public string $hs_tariff_code = '',
        public string $origin_country = '',
        public string $sku = ''
    ) {
        if ( $quantity <= 0 ) {
            throw new \InvalidArgumentException( 'Customs_Item: quantity must be positive.' );
        }
        if ( $value_cents < 0 ) {
            throw new \InvalidArgumentException( 'Customs_Item: value_cents cannot be negative.' );
        }
        if ( $weight_grams < 0 ) {
            throw new \InvalidArgumentException( 'Customs_Item: weight_grams cannot be negative.' );
        }
        if ( '' === $description ) {
            throw new \InvalidArgumentException( 'Customs_Item: description is required.' );
        }
    }
}
