<?php
/**
 * Shared PayPal presentment-currency declaration.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Declares the set of currencies the PayPal Commerce Platform can
 * actually transact in. Mixed into every PayPal-family gateway
 * (PayPal, Card, Apple Pay, Google Pay, Fastlane) so that callers —
 * notably the Currency Switcher module's gateway filter — can hide a
 * PayPal method when the shopper's selected display currency is one
 * PayPal would reject at capture time, instead of letting the order
 * fail at the API.
 *
 * The list is PayPal's documented presentment currencies. It is
 * filterable via `tejcart_paypal_supported_currencies` so addons /
 * private builds can adjust it (e.g. a region that gains/loses
 * support) without forking core.
 */
trait Supports_PayPal_Currencies {
    /**
     * PayPal Commerce Platform presentment currencies (ISO-4217).
     *
     * @return string[] Upper-case currency codes.
     */
    public function supported_currencies(): array {
        $currencies = array(
            'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD',
            'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK',
            'PHP', 'PLN', 'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'THB',
            'USD',
        );

        /**
         * Filter the PayPal-supported presentment currencies.
         *
         * @param string[] $currencies Upper-case ISO-4217 codes.
         * @param object   $gateway    The gateway instance.
         */
        $currencies = (array) apply_filters( 'tejcart_paypal_supported_currencies', $currencies, $this );

        return array_values( array_unique( array_map( 'strtoupper', array_map( 'strval', $currencies ) ) ) );
    }
}
