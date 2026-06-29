<?php
/**
 * Grouped Product type.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A grouped product is a collection of child products displayed together.
 *
 * The grouped product itself is not purchasable; individual child products
 * are purchased separately. The displayed price is the lowest child price
 * (shown as "From $X").
 */
class Grouped_Product extends Abstract_Product {
    /**
     * Array of child product IDs.
     *
     * @var int[]
     */
    protected $grouped_product_ids = array();

    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'grouped';
    }

    /**
     * Get grouped child product IDs from product meta `_grouped_products`.
     *
     * @return int[]
     */
    public function get_grouped_product_ids() {
        if ( empty( $this->grouped_product_ids ) && $this->get_id() ) {
            $ids = $this->get_meta( '_grouped_products' );

            if ( is_array( $ids ) ) {
                $this->grouped_product_ids = array_map( 'absint', $ids );
            }
        }

        return $this->grouped_product_ids;
    }

    /**
     * Get child product objects.
     *
     * @return Abstract_Product[]
     */
    public function get_grouped_products() {
        $ids      = $this->get_grouped_product_ids();
        $products = array();

        if ( empty( $ids ) ) {
            return $products;
        }

        return \TejCart\Product\Product_Factory::get_products( $ids );
    }

    /**
     * Get the lowest child price for display purposes (e.g., "From $X").
     *
     * @return string
     */
    public function get_price() {
        $children = $this->get_grouped_products();

        // C-H5: the "From $X" figure must advertise a price a buyer can
        // actually act on. Prefer the lowest in-stock child price, falling
        // back to the lowest of all priced children only when none are in
        // stock (so a fully sold-out group still shows a price).
        $min_in_stock = null;
        $min_any      = null;

        foreach ( $children as $child ) {
            $child_price = $child->get_price();

            if ( '' === $child_price ) {
                continue;
            }

            $child_price = (float) $child_price;

            if ( null === $min_any || $child_price < $min_any ) {
                $min_any = $child_price;
            }

            if ( method_exists( $child, 'is_in_stock' ) && $child->is_in_stock()
                && ( null === $min_in_stock || $child_price < $min_in_stock ) ) {
                $min_in_stock = $child_price;
            }
        }

        $min_price = null !== $min_in_stock ? $min_in_stock : $min_any;

        if ( null === $min_price ) {
            return '';
        }

        return (string) $min_price;
    }

    /**
     * Grouped products are not directly purchasable — Cart::add_item
     * rejects the grouped parent on purpose. Individual children are
     * what customers actually add to the cart, via the
     * grouped-add-to-cart template.
     *
     * Use {@see has_purchasable_children()} when you want to know
     * whether the grouped page can offer ANY purchase.
     *
     * @return bool
     */
    public function is_purchasable() {
        return false;
    }

    /**
     * Whether any child product in this group is currently purchasable.
     *
     * Frontend templates short-circuit to the grouped form before checking
     * is_purchasable(); REST consumers and themes that want a single boolean
     * for "can the customer buy something from this page?" should call this.
     *
     * @return bool
     */
    public function has_purchasable_children(): bool {
        foreach ( $this->get_grouped_products() as $child ) {
            if ( method_exists( $child, 'is_purchasable' ) && $child->is_purchasable() ) {
                return true;
            }
        }
        return false;
    }
}
