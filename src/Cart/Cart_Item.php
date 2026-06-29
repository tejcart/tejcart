<?php
/**
 * Cart Item Value Object
 *
 * @package TejCart\Cart
 */

declare( strict_types = 1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single item in the shopping cart.
 *
 * Acts as a value object holding product reference, quantity,
 * and any extra data associated with the cart line.
 */
class Cart_Item {
    /**
     * Unique key identifying this cart item.
     *
     * @var string
     */
    private $key;

    /**
     * The product ID for this item.
     *
     * @var int
     */
    private $product_id;

    /**
     * Quantity of this item in the cart.
     *
     * @var int
     */
    private $quantity;

    /**
     * Extra data associated with this cart item (e.g. variations, custom fields).
     *
     * @var array
     */
    private $data;

    /**
     * Lazy-loaded product instance.
     *
     * @var \TejCart\Product\Product|null
     */
    private $product = null;

    /**
     * Constructor.
     *
     * @param string $key        Unique cart item key.
     * @param int    $product_id Product ID.
     * @param int    $quantity   Quantity.
     * @param array  $data       Optional extra data.
     */
    public function __construct( $key, $product_id, $quantity, array $data = array() ) {
        $this->key        = $key;
        $this->product_id = (int) $product_id;
        $this->quantity   = max( 1, (int) $quantity );
        $this->data       = $data;
    }

    /**
     * Get the product instance, lazy-loading via Product_Factory.
     *
     * @return \TejCart\Product\Product|null
     */
    public function get_product() {
        if ( null === $this->product ) {
            if ( class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
                $this->product = \TejCart\Product\Product_Factory::get_product( $this->product_id );
            }
        }
        return $this->product;
    }

    /**
     * Drop the lazy-loaded product reference.
     *
     * Cart::recalculate() calls this after warming Product_Factory in one
     * batched query, so each item picks up the fresh product on its next
     * get_product() call without the caller having to replace Cart_Item
     * instances. Keeping object identity matters: filters, gateway state,
     * and e2e fixtures may hold references to the same Cart_Item across
     * the recalc.
     */
    public function reset_product_cache(): void {
        $this->product = null;
    }

    /**
     * Get the cart item key.
     *
     * @return string
     */
    public function get_key() {
        return $this->key;
    }

    /**
     * Get the product ID.
     *
     * @return int
     */
    public function get_product_id() {
        return $this->product_id;
    }

    /**
     * Get the current quantity.
     *
     * @return int
     */
    public function get_quantity() {
        return $this->quantity;
    }

    /**
     * Set the quantity.
     *
     * @param int $quantity New quantity (minimum 1).
     */
    public function set_quantity( $quantity ) {
        $this->quantity = max( 1, (int) $quantity );
    }

    /**
     * Get the effective unit price for this cart item.
     *
     * Reads the underlying product's price and runs it through the
     * `tejcart_cart_item_price` filter so themes / add-ons can override
     * pricing per-item (bulk discounts, role-based pricing, etc.)
     * without having to touch the product row. Returns a non-negative
     * float; negative or non-numeric filter results coerce to 0.
     *
     * This is the authoritative unit price used by both
     * {@see self::get_line_total()} and Cart::get_totals_hash().
     *
     * @return float
     */
    public function get_price() {
        $product = $this->get_product();

        // Prefer the price the customer agreed to when they
        // added the item. Falls back to the current product price for
        // legacy cart rows (no snapshot) and during the
        // `tejcart_cart_item_price` filter pass so themes that rewrite
        // pricing dynamically still win.
        if ( isset( $this->data['_price_at_add'] ) && is_numeric( $this->data['_price_at_add'] ) ) {
            $price = (float) $this->data['_price_at_add'];
        } else {
            $price = $product ? (float) $product->get_price() : 0.0;
        }

        /**
         * Filters the unit price for a cart item.
         *
         * @param float     $price   The unit price.
         * @param Cart_Item $item    The cart item instance.
         * @param \TejCart\Product\Product|null $product The product instance.
         */
        $filtered = apply_filters( 'tejcart_cart_item_price', $price, $this, $product );
        $price    = is_numeric( $filtered ) ? (float) $filtered : 0.0;

        return $price < 0 ? 0.0 : $price;
    }

    /**
     * Returns the snapshot price recorded at add-to-cart time, or null
     * when none was recorded (legacy cart rows from before the
     * price snapshot was added).
     *
     * Order_Factory and Checkout use this to lock in the agreed-upon
     * unit price on the order line so a mid-flow product price edit
     * cannot silently re-charge the buyer.
     */
    public function get_price_at_add(): ?float {
        if ( ! isset( $this->data['_price_at_add'] ) ) {
            return null;
        }
        $value = $this->data['_price_at_add'];
        return is_numeric( $value ) ? (float) $value : null;
    }

    /**
     * Get the line total as a {@see \TejCart\Money\Money}.
     *
     * Authoritative integer-minor-unit calculation: the unit price
     * float is rounded once to the currency's precision via banker's
     * rounding (PHP_ROUND_HALF_EVEN, matching the Money class's own
     * convention), converted to {@see Money::from_minor_units}, and
     * multiplied by quantity in exact integer arithmetic. No float
     * lives past this method.
     *
     * Currency precision is per-currency (JPY = 0 minor digits,
     * USD/EUR = 2, KWD/BHD = 3), so unlike the legacy `× 100`
     * shortcut this is correct under multi-currency stores.
     *
     * @param string $currency Optional ISO 4217 code. Defaults to the
     *                         current store currency.
     * @return \TejCart\Money\Money
     */
    public function get_line_total_money( string $currency = '' ): \TejCart\Money\Money {
        if ( '' === $currency ) {
            $currency = function_exists( 'tejcart_get_currency' )
                ? tejcart_get_currency()
                : (string) ( function_exists( 'get_option' ) ? get_option( 'tejcart_currency', 'USD' ) : 'USD' );
        }

        $multiplier = \TejCart\Money\Currency::multiplier( $currency );
        $minor      = (int) round( $this->get_price() * $multiplier, 0, PHP_ROUND_HALF_EVEN );
        $unit       = \TejCart\Money\Money::from_minor_units( $minor, $currency );

        return $unit->multiply( (int) $this->quantity );
    }

    /**
     * Get the line total (unit price × quantity) as a major-unit
     * float for backward compatibility.
     *
     * The float facade is preserved because the public REST/AJAX
     * payload, the `tejcart_cart_item_price` filter, and most
     * existing tests assert on floats. Internally the calculation
     * runs through {@see self::get_line_total_money()} so the
     * currency-precision and integer-arithmetic guarantees apply
     * even when a caller still consumes the float.
     *
     * @return float
     */
    public function get_line_total() {
        return (float) $this->get_line_total_money()->as_decimal_string();
    }

    /**
     * Money-typed alias for {@see self::get_line_total_money()}.
     *
     * @param string $currency Optional ISO 4217 code.
     * @return \TejCart\Money\Money
     */
    public function get_subtotal_money( string $currency = '' ): \TejCart\Money\Money {
        return $this->get_line_total_money( $currency );
    }

    /**
     * Alias for get_line_total().
     *
     * Cart_Calculator's non-sale subtotal and fixed_product discount helpers
     * look for `get_subtotal()` first when walking cart items; keep both
     * names wired so those discount paths work against real Cart_Item
     * instances (not just array-shaped items).
     *
     * @return float
     */
    public function get_subtotal() {
        return $this->get_line_total();
    }

    /**
     * Get the display name for this cart item.
     *
     * Applies the tejcart_cart_item_name filter.
     *
     * @return string
     */
    public function get_name() {
        $product = $this->get_product();
        $name    = $product ? $product->get_name() : __( 'Unknown Product', 'tejcart' );

        /**
         * Filters the cart item display name.
         *
         * @param string    $name The product name.
         * @param Cart_Item $item The cart item instance.
         */
        return apply_filters( 'tejcart_cart_item_name', $name, $this );
    }

    /**
     * Get the extra data array.
     *
     * @return array
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Whether this cart item refers to a product variation.
     *
     * Variations are stored as their own row in tejcart_products and are
     * referenced via product_id; the original parent (Variable_Product)
     * id is preserved in $data['parent_id'] so renderers can show the
     * parent name + chosen attributes.
     */
    public function is_variation(): bool {
        return ! empty( $this->data['parent_id'] );
    }

    /**
     * Variation id if this line references one, else 0.
     */
    public function get_variation_id(): int {
        return $this->is_variation() ? $this->product_id : 0;
    }

    /**
     * Parent (Variable_Product) id if this line references a variation,
     * else the product_id itself.
     */
    public function get_parent_product_id(): int {
        return $this->is_variation() ? (int) $this->data['parent_id'] : $this->product_id;
    }

    /**
     * Resolve the shipping class slug for this line, or '' when none.
     *
     * Shipping methods consume this to apply per-class rate adjustments.
     *
     * @return string
     */
    public function get_shipping_class(): string {
        $product = $this->get_product();
        if ( ! $product || ! method_exists( $product, 'get_shipping_class' ) ) {
            return '';
        }
        return (string) $product->get_shipping_class();
    }

    /**
     * Convert this cart item to a serializable array.
     *
     * @return array
     */
    public function to_array() {
        return array(
            'key'        => $this->key,
            'product_id' => $this->product_id,
            'quantity'   => $this->quantity,
            'data'       => $this->data,
            'line_total' => $this->get_line_total(),
            'name'       => $this->get_name(),
        );
    }
}
