<?php
/**
 * Physical Product class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a physical product that requires shipping.
 */
class Physical_Product extends Abstract_Product {
    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'physical';
    }

    /**
     * Physical products are not virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        return false;
    }

    /**
     * Physical products need shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        return true;
    }
}
