<?php
/**
 * Abstract coupon type.
 *
 * @package TejCart\Coupon
 */

declare( strict_types=1 );

namespace TejCart\Coupon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base class for third-party coupon discount types.
 *
 * Built-in types are handled directly inside `Cart_Calculator::calculate_discounts()`:
 * `percent`, `fixed_cart`, `fixed_product`, `free_shipping`. To add a new type
 * (BOGO, tiered percentage, geographic, first-order, etc.) a plugin:
 *
 *   1. Subclasses this abstract.
 *   2. Registers the type ID with `tejcart_coupon_types` so {@see Coupon::set_type()}
 *      accepts and persists the new value.
 *   3. Hooks `tejcart_calculate_coupon_discount` to return a Money instance
 *      whenever it sees its own type ID.
 *   4. Optionally hooks `tejcart_validate_coupon` to reject the coupon when
 *      the cart doesn't satisfy the type's preconditions.
 *
 * This abstract bundles the registration plumbing — subclasses only need to
 * implement {@see self::matches()} and {@see self::calculate()}.
 */
abstract class Abstract_Coupon_Type {
    /**
     * Unique type identifier (e.g. "bogo", "tiered_percentage").
     *
     * @var string
     */
    protected string $id = '';

    /**
     * Human-readable label for admin UI.
     *
     * @var string
     */
    protected string $label = '';

    /**
     * Register the type with TejCart. Call once at plugin bootstrap.
     */
    public function register(): void {
        add_filter( 'tejcart_coupon_types', array( $this, 'register_type_id' ) );
        add_filter( 'tejcart_calculate_coupon_discount', array( $this, 'maybe_calculate' ), 10, 4 );
    }

    /**
     * Append this type's ID to the registered whitelist.
     *
     * @param string[] $types
     * @return string[]
     */
    public function register_type_id( array $types ): array {
        if ( '' !== $this->id && ! in_array( $this->id, $types, true ) ) {
            $types[] = $this->id;
        }
        return $types;
    }

    /**
     * Filter callback: dispatch to {@see self::calculate()} when the coupon
     * matches this type.
     *
     * @param \TejCart\Money\Money|null $current Existing discount, if any.
     * @param array                     $coupon  Coupon data.
     * @param \TejCart\Money\Money      $subtotal Cart subtotal.
     * @param mixed                     $cart    Cart instance.
     * @return \TejCart\Money\Money|null
     */
    public function maybe_calculate( $current, array $coupon, \TejCart\Money\Money $subtotal, $cart ): ?\TejCart\Money\Money {
        if ( $current instanceof \TejCart\Money\Money ) {
            return $current; // Earlier filter already produced a result.
        }

        if ( ! $this->matches( $coupon ) ) {
            return null;
        }

        return $this->calculate( $coupon, $subtotal, $cart );
    }

    /**
     * Whether the given coupon row belongs to this type.
     *
     * Default implementation matches on `discount_type === $this->id`. Override
     * if your type uses a different keying strategy.
     *
     * @param array $coupon
     * @return bool
     */
    protected function matches( array $coupon ): bool {
        return ( $coupon['discount_type'] ?? '' ) === $this->id;
    }

    /**
     * Compute the discount for one coupon row.
     *
     * @param array                $coupon   Coupon data.
     * @param \TejCart\Money\Money $subtotal Cart subtotal.
     * @param mixed                $cart     Cart instance.
     * @return \TejCart\Money\Money
     */
    abstract protected function calculate( array $coupon, \TejCart\Money\Money $subtotal, $cart ): \TejCart\Money\Money;

    /**
     * Type identifier.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Type label.
     *
     * @return string
     */
    public function get_label(): string {
        return $this->label;
    }
}
