<?php
/**
 * Variation class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

use TejCart\Product\Product_Factory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single variation of a Variable_Product.
 */
class Variation extends Abstract_Product {
    /**
     * Parent variable product ID.
     *
     * @var int
     */
    protected $parent_id = 0;

    /**
     * Variation-specific attribute values.
     *
     * @var array|null
     */
    protected $attributes = null;

    /**
     * Constructor.
     *
     * Loads variation data and sets the parent ID from meta.
     *
     * @param int $id Variation product ID.
     */
    public function __construct( $id = 0, $data = null ) {
        parent::__construct( $id, $data );

        if ( $this->id > 0 ) {
            $parent = $this->get_meta( '_variation_parent_id' );

            if ( $parent ) {
                $this->parent_id = absint( $parent );
            }
        }
    }

    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'variation';
    }

    /**
     * Get the parent Variable_Product.
     *
     * @return Variable_Product|false Parent product or false on failure.
     */
    public function get_parent() {
        if ( ! $this->parent_id ) {
            return false;
        }

        $parent = Product_Factory::get_product( $this->parent_id );

        if ( ! $parent instanceof Variable_Product ) {
            return false;
        }

        return $parent;
    }

    /**
     * Get the parent product ID.
     *
     * @return int
     */
    public function get_parent_id() {
        return $this->parent_id;
    }

    /**
     * Get the variation-specific attribute values.
     *
     * Returns the specific combination of attribute values for this
     * variation (e.g., {color: 'red', size: 'large'}).
     *
     * @return array Associative array of attribute name => value.
     */
    public function get_attributes() {
        if ( null === $this->attributes ) {
            $raw = $this->get_meta( '_variation_attributes' );

            if ( is_string( $raw ) ) {
                $raw = json_decode( $raw, true );
            }

            $this->attributes = is_array( $raw ) ? $raw : array();
        }

        return $this->attributes;
    }

    /**
     * Set the variation-specific attribute values and persist to meta.
     *
     * @param array $attrs Associative array of attribute name => value.
     * @return bool True on success, false on failure.
     */
    public function set_attributes( $attrs ) {
        $this->attributes = array_map( 'sanitize_text_field', (array) $attrs );

        return $this->update_meta( '_variation_attributes', wp_json_encode( $this->attributes ) );
    }

    /**
     * Get the variation display name.
     *
     * Returns the parent product name followed by the attribute values
     * joined with a comma.
     *
     * @return string
     */
    public function get_name() {
        $parent = $this->get_parent();

        if ( ! $parent ) {
            return parent::get_name();
        }

        $attrs = $this->get_attributes();

        if ( empty( $attrs ) ) {
            return $parent->get_name();
        }

        return $parent->get_name() . ' - ' . implode( ', ', array_values( $attrs ) );
    }

    /**
     * Check if the variation is purchasable.
     *
     * A variation is purchasable if it has a price, is in stock, and its
     * parent variable product has a published status.
     *
     * @return bool
     */
    public function is_purchasable() {
        $parent = $this->get_parent();

        if ( ! $parent || 'publish' !== $parent->get_status() ) {
            return apply_filters( 'tejcart_product_is_purchasable', false, $this );
        }

        $purchasable = $this->get_price() !== '' && $this->is_in_stock();

        return apply_filters( 'tejcart_product_is_purchasable', $purchasable, $this );
    }

    /**
     * Check if the variation needs shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        return ! $this->is_virtual();
    }

    /**
     * Save the variation and bust the parent's cached price range so
     * the next /shop and single-product render reflects this change.
     *
     * @return int|false
     */
    public function save() {
        $result = parent::save();

        if ( false !== $result ) {
            $parent = $this->get_parent();
            if ( $parent ) {
                $parent->invalidate_price_cache();
            }
        }

        return $result;
    }

    /**
     * Delete the variation and bust the parent's price-range cache.
     *
     * @return bool
     */
    public function delete() {
        $parent = $this->get_parent();
        $result = parent::delete();

        if ( $result && $parent ) {
            $parent->invalidate_price_cache();
        }

        return $result;
    }
}
