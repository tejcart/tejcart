<?php
/**
 * Local Pickup shipping method.
 *
 * @package TejCart\Shipping\Shipping_Methods
 */

declare( strict_types=1 );

namespace TejCart\Shipping\Shipping_Methods;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Allows customers to pick up their order locally.
 *
 * Settings:
 *  - cost (decimal): Optional pickup fee, defaults to 0.
 */
class Local_Pickup extends Abstract_Shipping_Method {
    /**
     * Method identifier.
     *
     * @var string
     */
    protected $id = 'local_pickup';

    /**
     * Method title.
     *
     * @var string
     */
    protected $title = 'Local Pickup';

    /**
     * Calculate shipping cost.
     *
     * Returns 0 or a small configured fee.
     *
     * @param mixed $cart Cart instance.
     * @return float
     */
    public function calculate( $cart ) {
        $cost = isset( $this->settings['cost'] ) ? (float) $this->settings['cost'] : 0.0;

        return $this->round_cost( $cost );
    }
}
