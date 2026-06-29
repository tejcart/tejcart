<?php
/**
 * Abstract tax provider.
 *
 * @package TejCart\Tax
 */

declare( strict_types=1 );

namespace TejCart\Tax;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base class for third-party live-rate tax providers (TaxJar, Avalara, Vertex,
 * Stripe Tax, …).
 *
 * Subclasses implement `calculate()` and return the tax owed for a cart given
 * a destination address and a taxable amount. Register a subclass with
 * TejCart by hooking the `tejcart_tax_providers` filter:
 *
 *     add_filter( 'tejcart_tax_providers', function ( array $providers ): array {
 *         $providers['taxjar'] = MyPlugin\TaxJar_Provider::class;
 *         return $providers;
 *     } );
 *
 * Activate one provider via the `tejcart_active_tax_provider` filter (or the
 * `tejcart_active_tax_provider` option). When no provider is active, the
 * built-in `Tax_Manager` rate-table calculation runs.
 *
 * Providers MUST fail soft — return `null` (not throw) when a remote call
 * fails, so the cart falls through to the default Tax_Manager calculation.
 */
abstract class Abstract_Tax_Provider {
    /**
     * Provider unique identifier (e.g. "taxjar", "avalara").
     *
     * @var string
     */
    protected string $id = '';

    /**
     * Display title for admin UI.
     *
     * @var string
     */
    protected string $title = '';

    /**
     * Calculate the tax owed for a cart.
     *
     * @param float $taxable_amount Subtotal minus discounts (cart currency).
     * @param array $context        {
     *     @type string $country         Destination country (ISO).
     *     @type string $state           Destination state / region.
     *     @type string $postcode        Destination postcode.
     *     @type float  $shipping_total  Shipping cost (already calculated).
     *     @type bool   $prices_include_tax Whether line prices are gross.
     *     @type \TejCart\Cart\Cart $cart Cart instance.
     * }
     * @return float|null Tax amount in cart currency, or null to fall through
     *                    to the default Tax_Manager calculation.
     */
    abstract public function calculate( float $taxable_amount, array $context ): ?float;

    /**
     * Provider ID.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Provider display title.
     *
     * @return string
     */
    public function get_title(): string {
        return $this->title;
    }

    /**
     * Whether the provider is available (credentials configured, etc.).
     * Override in subclasses; default returns true.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }
}
