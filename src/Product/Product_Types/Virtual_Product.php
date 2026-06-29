<?php
/**
 * Virtual Product class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a virtual product (service, membership, etc.) that does not require shipping.
 */
class Virtual_Product extends Abstract_Product {
    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'virtual';
    }

    /**
     * Virtual products are always virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        return true;
    }

    /**
     * Virtual products do not need shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        return false;
    }
}
