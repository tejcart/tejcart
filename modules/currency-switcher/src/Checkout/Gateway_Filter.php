<?php
/**
 * Per-currency payment-gateway whitelist filter.
 *
 * @package TejCart\Currency_Switcher\Checkout
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Checkout;

use TejCart\Currency_Switcher\Options;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * When checkout-in-different-currency is on, merchants can restrict
 * which gateways accept which currency. The map is stored under
 * {@see Options::CHECKOUT_GATEWAYS}:
 *
 *   [
 *     'EUR' => [ 'paypal', 'stripe' ],
 *     'GBP' => [ 'stripe' ],
 *   ]
 *
 * Currencies with no entry (or an empty list) accept every gateway —
 * subject to the programmatic safety net below.
 *
 * Independently of that optional merchant map, any gateway that
 * declares it cannot transact in the active display currency (via the
 * core `Abstract_Gateway::supports_currency()` capability — e.g. a
 * PayPal method against a currency outside PayPal's presentment list)
 * is always removed. This keeps a mis-configured exotic currency from
 * reaching a gateway that would only reject the order at capture time.
 */
final class Gateway_Filter {
    public function register(): void {
        add_filter( 'tejcart_available_payment_gateways', array( $this, 'filter_gateways' ), 20 );
    }

    /**
     * @param array<string, mixed> $gateways Gateway-id => gateway instance.
     * @return array<string, mixed>
     */
    public function filter_gateways( $gateways ): array {
        if ( ! is_array( $gateways ) || empty( $gateways ) ) {
            return is_array( $gateways ) ? $gateways : array();
        }
        if ( ! Checkout_Controller::diff_currency_allowed() ) {
            return $gateways;
        }
        if ( Checkout_Controller::is_force_base_request() ) {
            return $gateways;
        }

        $resolver = new Currency_Resolver();
        $code     = $resolver->current();

        // 1. Safety net: drop any gateway that cannot transact in the
        //    active currency, regardless of the merchant map. This is
        //    the only programmatic guard against charging in a currency
        //    the gateway (e.g. PayPal) does not actually support.
        $gateways = $this->drop_currency_incompatible( $gateways, $code );

        // 2. Optional per-currency merchant whitelist, narrowing further.
        $map = get_option( Options::CHECKOUT_GATEWAYS, array() );
        if ( ! is_array( $map ) || empty( $map[ $code ] ) || ! is_array( $map[ $code ] ) ) {
            return $gateways;
        }

        $allowed = array_map( 'strval', $map[ $code ] );
        $out     = array();
        foreach ( $gateways as $id => $gateway ) {
            if ( in_array( (string) $id, $allowed, true ) ) {
                $out[ $id ] = $gateway;
            }
        }
        return $out;
    }

    /**
     * Remove gateways whose declared currency support excludes the
     * active currency. Gateways that don't declare support (the
     * default `supported_currencies() === []`) are kept untouched.
     *
     * @param array<string, mixed> $gateways Gateway-id => instance.
     * @param string               $code     Active display currency.
     * @return array<string, mixed>
     */
    private function drop_currency_incompatible( array $gateways, string $code ): array {
        $out = array();
        foreach ( $gateways as $id => $gateway ) {
            if ( is_object( $gateway )
                && method_exists( $gateway, 'supports_currency' )
                && ! $gateway->supports_currency( $code ) ) {
                continue;
            }
            $out[ $id ] = $gateway;
        }
        return $out;
    }
}
