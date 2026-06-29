<?php
/**
 * Bundle Product class.
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
 * Represents a bundle product composed of multiple other products.
 */
class Bundle_Product extends Abstract_Product {
    /**
     * Bundled items data.
     *
     * Each item is an array with keys: product_id, quantity, discount.
     *
     * @var array
     */
    protected $bundled_items = array();

    /**
     * Whether bundled items have been loaded from meta.
     *
     * @var bool
     */
    protected $bundled_items_loaded = false;

    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'bundle';
    }

    /**
     * Get bundled items.
     *
     * Loads from product meta '_bundled_items' on first call. Each entry
     * contains product_id, quantity, and an optional per-item discount.
     *
     * @return array Array of bundled item arrays with keys: product_id, quantity, discount.
     */
    public function get_bundled_items() {
        if ( ! $this->bundled_items_loaded ) {
            $raw = $this->get_meta( '_bundled_items' );

            if ( is_string( $raw ) ) {
                $raw = json_decode( $raw, true );
            }

            if ( is_array( $raw ) ) {
                $this->bundled_items = array_map( function ( $item ) {
                    return wp_parse_args( $item, array(
                        'product_id' => 0,
                        'quantity'   => 1,
                        'discount'   => 0,
                    ) );
                }, $raw );
            }

            $this->bundled_items_loaded = true;
        }

        return $this->bundled_items;
    }

    /**
     * Set bundled items and persist to product meta.
     *
     * @param array $items Array of bundled item arrays with keys: product_id, quantity, discount.
     * @return bool True on success, false on failure.
     */
    public function set_bundled_items( $items ) {
        $self_id = (int) $this->get_id();

        $this->bundled_items = array();
        foreach ( (array) $items as $item ) {
            $product_id = absint( $item['product_id'] ?? 0 );

            if ( $product_id > 0 && $product_id === $self_id ) {
                continue;
            }

            if ( $product_id > 0 && $this->contains_bundle_cycle( $product_id, array( $self_id ) ) ) {
                continue;
            }

            $this->bundled_items[] = array(
                'product_id' => $product_id,
                'quantity'   => max( 1, absint( $item['quantity'] ?? 1 ) ),
                'discount'   => max( 0.0, min( 100.0, floatval( $item['discount'] ?? 0 ) ) ),
            );
        }

        $this->bundled_items_loaded = true;

        return $this->update_meta( '_bundled_items', wp_json_encode( $this->bundled_items ) );
    }

    /**
     * Detect whether adding $product_id would create a bundle cycle.
     *
     * @param int   $product_id Candidate bundled product.
     * @param int[] $seen       IDs already visited on this traversal branch.
     * @return bool
     */
    protected function contains_bundle_cycle( int $product_id, array $seen ): bool {
        if ( in_array( $product_id, $seen, true ) ) {
            return true;
        }

        $candidate = Product_Factory::get_product( $product_id );
        if ( ! $candidate instanceof self ) {
            return false;
        }

        $seen[] = $product_id;
        foreach ( $candidate->get_bundled_items() as $child ) {
            $child_id = absint( $child['product_id'] ?? 0 );
            if ( $child_id > 0 && $this->contains_bundle_cycle( $child_id, $seen ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the bundle price.
     *
     * Calculated as the sum of each bundled item's price multiplied by its
     * quantity, with an optional per-item discount applied.
     *
     * F-PCA-011: The intermediate float accumulation is for display purposes
     * only (tejcart_price() formatting boundary). Callers must not use this
     * value for cart arithmetic — use Cart_Calculator's integer-cent path instead.
     *
     * @internal Intermediate float arithmetic; display boundary only.
     * @return string Decimal string representing the bundle price.
     */
    public function get_price() {
        $items           = $this->get_bundled_items();
        $total           = 0.0;
        $missing_child   = false;

        foreach ( $items as $item ) {
            $product = $this->get_bundled_product( $item['product_id'] );

            if ( ! $product ) {
                // N-M7: a missing / unpublished child product silently
                // drops out of the sum, which can leave a bundle priced
                // at $0 even though the merchant configured it
                // correctly. Track it so the bundle is rejected by the
                // Cart::add() zero-price gate (F-M16) instead of
                // sneaking in via the tejcart_product_is_free filter
                // for fully-free SKUs.
                $missing_child = true;
                continue;
            }

            // C-M5: a child whose price is unset returns '' from
            // get_price(); casting that to (float) yields 0.0, which would
            // silently lower the bundle total instead of flagging the
            // misconfiguration. Treat an empty price the same as a missing
            // child so the zero-price gate rejects the bundle.
            $child_price = $product->get_price();
            if ( '' === $child_price ) {
                $missing_child = true;
                continue;
            }

            $item_price = (float) $child_price;
            $discount   = floatval( $item['discount'] );
            $quantity   = max( 1, absint( $item['quantity'] ) );

            if ( $discount > 0 ) {
                $item_price = $item_price * ( 1 - ( $discount / 100 ) );
            }

            $total += $item_price * $quantity;
        }

        // N-M7: if any child is missing, force the bundle price to 0 so
        // the F-M16 zero-price gate in Cart::add() rejects it. A
        // partially-priced bundle (some children priced, others missing)
        // would otherwise let a misconfigured product land in cart at
        // the wrong price. Merchants who legitimately want free bundle
        // SKUs continue to opt in via `tejcart_product_is_free`.
        if ( $missing_child ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Bundle %d has at least one missing/unpublished child product — zero-price gate will reject it.', (int) $this->get_id() ),
                    'warning'
                );
            }
            $total = 0.0;
        }

        $price = (string) round( $total, 2 );

        /**
         * Filter the calculated bundle price.
         *
         * @param string         $price   The calculated bundle price.
         * @param array          $items   The bundled items data.
         * @param Bundle_Product $product The bundle product instance.
         */
        return apply_filters( 'tejcart_bundle_price', $price, $items, $this );
    }

    /**
     * Get the regular price (sum of bundled item regular prices without discounts).
     *
     * F-PCA-011: Intermediate float accumulation for display purposes only.
     * Do not use for cart arithmetic.
     *
     * @internal Display boundary only; float accumulation.
     * @return string Decimal string representing the sum of all bundled item regular prices.
     */
    public function get_regular_price() {
        $items = $this->get_bundled_items();
        $total = 0.0;

        foreach ( $items as $item ) {
            $product = $this->get_bundled_product( $item['product_id'] );

            if ( ! $product ) {
                continue;
            }

            $quantity = max( 1, absint( $item['quantity'] ) );
            $total   += (float) $product->get_regular_price() * $quantity;
        }

        return (string) round( $total, 2 );
    }

    /**
     * Check if the bundle is purchasable.
     *
     * A bundle is purchasable only when all of its bundled items are
     * purchasable and in stock.
     *
     * @return bool
     */
    public function is_purchasable() {
        $items = $this->get_bundled_items();

        if ( empty( $items ) ) {
            return false;
        }

        if ( 'publish' !== $this->get_status() ) {
            return false;
        }

        foreach ( $items as $item ) {
            $product = $this->get_bundled_product( $item['product_id'] );

            if ( ! $product || '' === $product->get_price() || 'publish' !== $product->get_status() ) {
                return apply_filters( 'tejcart_product_is_purchasable', false, $this );
            }

            $stock_status = $product->get_stock_status();
            if ( 'instock' !== $stock_status && 'onbackorder' !== $stock_status ) {
                return apply_filters( 'tejcart_product_is_purchasable', false, $this );
            }
        }

        return apply_filters( 'tejcart_product_is_purchasable', true, $this );
    }

    /**
     * Check if the bundle needs shipping.
     *
     * True if any bundled item needs shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        foreach ( $this->get_bundled_items() as $item ) {
            $product = $this->get_bundled_product( $item['product_id'] );

            if ( $product && $product->needs_shipping() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the bundle is virtual.
     *
     * A bundle is virtual only if ALL bundled items are virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        $items = $this->get_bundled_items();

        if ( empty( $items ) ) {
            return false;
        }

        foreach ( $items as $item ) {
            $product = $this->get_bundled_product( $item['product_id'] );

            if ( ! $product || ! $product->is_virtual() ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a product object for a bundled item.
     *
     * @param int $product_id Product ID of the bundled item.
     * @return Abstract_Product|false Product instance or false on failure.
     */
    public function get_bundled_product( $product_id ) {
        return Product_Factory::get_product( absint( $product_id ) );
    }

    /**
     * Validate the bundle.
     *
     * Checks that all bundled items exist, are valid products, and have
     * valid quantities.
     *
     * @return true|\WP_Error True if valid, WP_Error on failure.
     */
    public function validate_bundle() {
        $items = $this->get_bundled_items();

        if ( empty( $items ) ) {
            return new \WP_Error( 'empty_bundle', __( 'Bundle has no items.', 'tejcart' ) );
        }

        foreach ( $items as $index => $item ) {
            if ( empty( $item['product_id'] ) ) {
                return new \WP_Error(
                    'invalid_item',
                    sprintf(
                        /* translators: %d: item index */
                        __( 'Bundled item at position %d has no product ID.', 'tejcart' ),
                        $index
                    )
                );
            }

            $product = $this->get_bundled_product( $item['product_id'] );

            if ( ! $product ) {
                return new \WP_Error(
                    'invalid_product',
                    sprintf(
                        /* translators: %d: product ID */
                        __( 'Bundled product #%d does not exist.', 'tejcart' ),
                        $item['product_id']
                    )
                );
            }

            if ( $item['quantity'] < 1 ) {
                return new \WP_Error(
                    'invalid_quantity',
                    sprintf(
                        /* translators: %d: product ID */
                        __( 'Bundled product #%d has an invalid quantity.', 'tejcart' ),
                        $item['product_id']
                    )
                );
            }

            $discount = floatval( $item['discount'] ?? 0 );
            if ( $discount < 0 || $discount > 100 ) {
                return new \WP_Error(
                    'invalid_discount',
                    sprintf(
                        /* translators: %d: product ID */
                        __( 'Bundled product #%d has a discount outside the 0–100 range.', 'tejcart' ),
                        $item['product_id']
                    )
                );
            }
        }

        return true;
    }
}
