<?php
/**
 * Single package within a rate request.
 *
 * Dimensions are SI: grams + millimetres. Drivers convert to whatever the
 * carrier API expects. Declared value is integer cents in the request
 * currency, consistent with TejCart core's money convention.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Package {
    public function __construct(
        public int $weight_grams,
        public int $length_mm = 0,
        public int $height_mm = 0,
        public int $depth_mm = 0,
        public int $declared_value_cents = 0
    ) {
        if ( $weight_grams < 0 ) {
            throw new \InvalidArgumentException( 'Package weight cannot be negative.' );
        }
        if ( $length_mm < 0 || $height_mm < 0 || $depth_mm < 0 ) {
            throw new \InvalidArgumentException( 'Package dimensions cannot be negative.' );
        }
        if ( $declared_value_cents < 0 ) {
            throw new \InvalidArgumentException( 'Package declared value cannot be negative.' );
        }
    }
}
