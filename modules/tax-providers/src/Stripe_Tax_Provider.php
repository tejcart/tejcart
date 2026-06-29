<?php
/**
 * Stripe Tax provider driver.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculate tax through Stripe Tax (POST /v1/tax/calculations).
 *
 * Stripe Tax bills per calculation, so the base class's transient cache is
 * essential — we never want to be charged twice for the same cart line on a
 * page reload.
 *
 * Configuration:
 *   - secret_key: Stripe restricted key with `tax:write` scope. The
 *     publishable key is *not* used here because tax calculations are
 *     server-only.
 *   - tax_behavior: 'exclusive' (prices are net; tax is added) or
 *     'inclusive' (prices already include tax). Defaults to whichever the
 *     cart says it is using via `prices_include_tax`.
 *
 * Stripe's `currency` parameter must be lowercase ISO-4217. We pull the
 * cart currency from {@see tejcart_get_currency()} and uppercase/lowercase
 * as needed. Amounts go to Stripe in the smallest currency unit (integer
 * cents for USD, EUR, etc.). Zero-decimal currencies (JPY, KRW) skip the
 * ×100 multiplication.
 */
class Stripe_Tax_Provider extends Abstract_Live_Tax_Provider {
    /**
     * @inheritDoc
     */
    public const CREDENTIAL_KEYS = array(
        array(
            'id'          => 'enabled',
            'label'       => 'Enabled',
            'type'        => 'checkbox',
            'description' => 'Use Stripe Tax for live calculations.',
            'required'    => false,
        ),
        array(
            'id'          => 'secret_key',
            'label'       => 'Secret API key',
            'type'        => 'password',
            'description' => 'Stripe restricted key with the tax:write scope. Stored encrypted.',
            'required'    => true,
        ),
        array(
            'id'          => 'tax_behavior',
            'label'       => 'Tax behavior',
            'type'        => 'select',
            'options'     => array(
                'exclusive' => 'Exclusive (prices are net of tax)',
                'inclusive' => 'Inclusive (prices include tax)',
            ),
            'description' => 'How your catalog prices are stored. Defaults to the store-wide setting.',
            'required'    => false,
            'allow_empty' => true,
        ),
    );

    /**
     * @inheritDoc
     */
    public const SECRET_KEYS = array( 'secret_key' );

    public function __construct() {
        $this->id         = 'stripe_tax';
        $this->title      = 'Stripe Tax';
        $this->option_key = 'tejcart_tax_provider_stripe';
    }

    /**
     * Stripe restricted/secret keys carry an unambiguous test-mode marker in
     * the prefix: `sk_test_`, `rk_test_`. We detect that so the admin card
     * can warn the merchant — Stripe Tax bills $0.05 per call after the
     * monthly free quota and a developer testing against real keys racks up
     * a real bill in a hurry.
     */
    public function is_test_mode(): bool {
        $key = (string) $this->get_setting( 'secret_key', '' );
        return 1 === preg_match( '/^(?:sk|rk)_test_/', $key );
    }

    /**
     * @inheritDoc
     */
    protected function request_tax( float $taxable_amount, array $context ): ?float {
        $secret = (string) $this->get_setting( 'secret_key', '' );
        if ( '' === $secret ) {
            $this->debug( 'request_tax: secret_key missing → returning null' );
            return null;
        }

        $currency = strtolower( $this->resolve_currency() );
        if ( '' === $currency ) {
            $this->debug( 'request_tax: currency could not be resolved → returning null' );
            return null;
        }

        $multiplier = \TejCart\Money\Currency::multiplier( $currency );
        $decimals   = \TejCart\Money\Currency::decimals( $currency );

        $taxable_unit  = (int) round( $taxable_amount * $multiplier );
        $shipping_cost = isset( $context['shipping_total'] ) ? (float) $context['shipping_total'] : 0.0;
        $shipping_unit = (int) round( $shipping_cost * $multiplier );

        if ( $taxable_unit <= 0 ) {
            return 0.0;
        }

        $address = $this->destination_address( $context );

        $configured_behavior = (string) $this->get_setting( 'tax_behavior', '' );
        $tax_behavior        = '' !== $configured_behavior
            ? $configured_behavior
            : ( ! empty( $context['prices_include_tax'] ) ? 'inclusive' : 'exclusive' );

        $body = array(
            'currency'                          => $currency,
            'line_items[0][amount]'             => (string) $taxable_unit,
            'line_items[0][reference]'          => 'tejcart-cart',
            'line_items[0][tax_behavior]'       => $tax_behavior,
            'customer_details[address][country]' => $address['country'],
            'customer_details[address_source]'   => 'shipping',
        );

        if ( '' !== $address['state'] ) {
            $body['customer_details[address][state]'] = $address['state'];
        }
        if ( '' !== $address['postcode'] ) {
            $body['customer_details[address][postal_code]'] = $address['postcode'];
        }
        if ( '' !== $address['city'] ) {
            $body['customer_details[address][city]'] = $address['city'];
        }
        if ( $shipping_unit > 0 ) {
            $body['shipping_cost[amount]']        = (string) $shipping_unit;
            $body['shipping_cost[tax_behavior]']  = $tax_behavior;
        }

        $request_args = array(
            'timeout' => $this->http_timeout(),
            'headers' => array(
                'Authorization'              => 'Bearer ' . $secret,
                'Stripe-Version'             => '2024-06-20',
                'Content-Type'               => 'application/x-www-form-urlencoded',
                // Stripe honours an idempotency key per request; same cart
                // hash inside a 24h window is a no-op retry.
                'Idempotency-Key'            => substr( hash( 'sha256', wp_json_encode( $body ) ), 0, 32 ),
            ),
            'body'    => http_build_query( $body, '', '&' ),
        );

        /**
         * Filter the wp_remote_post args used for the Stripe Tax calculation
         * request. Useful for adding `Stripe-Account` for Connect platforms,
         * custom user agents, or proxying through a staging URL.
         *
         * @param array  $request_args wp_remote_post args.
         * @param array  $context      Cart context.
         * @param string $provider_id  Provider ID.
         */
        $request_args = (array) apply_filters(
            'tejcart_tax_provider_request_args',
            $request_args,
            $context,
            $this->get_id()
        );

        $url = 'https://api.stripe.com/v1/tax/calculations';
        $this->debug( 'request_tax: POST → Stripe Tax', array(
            'url'          => $url,
            'currency'     => $currency,
            'tax_behavior' => $tax_behavior,
            'body'         => $body,
        ) );

        $response = $this->remote_post( $url, $request_args, 'tax/calculations' );

        $decoded = $this->decode_json_response( $response, 'tax/calculations' );
        if ( null === $decoded ) {
            return null;
        }

        // Stripe always returns both `tax_amount_exclusive` and
        // `tax_amount_inclusive`. They are two views on the SAME calculation:
        //   - tax_amount_exclusive: tax that should be ADDED to the line
        //     amount (correct when the catalog price is net of tax).
        //   - tax_amount_inclusive: tax already BAKED INTO the line amount
        //     (correct when the catalog price already includes tax).
        // Picking the wrong field returns 0 on inclusive carts and the
        // merchant under-collects every order.
        $is_inclusive = 'inclusive' === $tax_behavior;
        $field        = $is_inclusive ? 'tax_amount_inclusive' : 'tax_amount_exclusive';
        if ( ! isset( $decoded[ $field ] ) ) {
            $this->debug( 'request_tax: Stripe response missing ' . $field . ' → returning null', array(
                'top_level_keys' => array_keys( $decoded ),
            ) );
            return null;
        }

        $tax_unit = (int) $decoded[ $field ];
        $tax      = round( $tax_unit / $multiplier, $decimals );
        $this->debug( 'request_tax: Stripe Tax success', array(
            'field'        => $field,
            'tax_amount'   => $tax_unit,
            'tax'          => $tax,
            'tax_behavior' => $tax_behavior,
        ) );
        return $tax;
    }

    /**
     * Resolve the cart currency. Falls back to USD when nothing is configured.
     */
    private function resolve_currency(): string {
        if ( function_exists( 'tejcart_get_currency' ) ) {
            $code = (string) tejcart_get_currency();
            if ( '' !== $code ) {
                return $code;
            }
        }
        return (string) get_option( 'tejcart_currency', 'USD' );
    }
}
