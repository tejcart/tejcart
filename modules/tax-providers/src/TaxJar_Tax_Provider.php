<?php
/**
 * TaxJar SmartCalcs tax provider driver.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculate tax through TaxJar's SmartCalcs API (POST /v2/taxes).
 *
 * Most useful for US merchants with multi-state nexus — TaxJar's data is
 * the de-facto standard for US sales-tax sourcing rules. Outside the US
 * this driver still works but Stripe Tax is usually the cheaper choice.
 *
 * Configuration:
 *   - api_token: TaxJar API token (Plus plan or higher). Stored encrypted.
 *   - sandbox:   When 'yes', point at api.sandbox.taxjar.com. Sandbox uses
 *                a separate token issued in the TaxJar dashboard.
 *   - origin_country / origin_state / origin_zip: store ship-from. Required
 *     by TaxJar to determine sourcing.
 */
class TaxJar_Tax_Provider extends Abstract_Live_Tax_Provider {
    /**
     * @inheritDoc
     */
    public const CREDENTIAL_KEYS = array(
        array(
            'id'          => 'enabled',
            'label'       => 'Enabled',
            'type'        => 'checkbox',
            'description' => 'Use TaxJar SmartCalcs for live calculations.',
            'required'    => false,
        ),
        array(
            'id'          => 'api_token',
            'label'       => 'API token',
            'type'        => 'password',
            'description' => 'TaxJar API token. Stored encrypted.',
            'required'    => true,
        ),
        array(
            'id'          => 'sandbox',
            'label'       => 'Sandbox mode',
            'type'        => 'checkbox',
            'description' => 'Send requests to api.sandbox.taxjar.com (separate token required).',
            'required'    => false,
        ),
        array(
            'id'          => 'origin_country',
            'label'       => 'Origin country',
            'type'        => 'text',
            'description' => 'ISO-3166 alpha-2 country code that goods ship from.',
            'required'    => true,
        ),
        array(
            'id'          => 'origin_state',
            'label'       => 'Origin state',
            'type'        => 'text',
            'description' => 'ISO state/province code (US merchants: required).',
            'required'    => false,
        ),
        array(
            'id'          => 'origin_zip',
            'label'       => 'Origin postcode',
            'type'        => 'text',
            'description' => 'Origin ZIP / postal code.',
            'required'    => false,
        ),
    );

    /**
     * @inheritDoc
     */
    public const SECRET_KEYS = array( 'api_token' );

    public function __construct() {
        $this->id         = 'taxjar';
        $this->title      = 'TaxJar';
        $this->option_key = 'tejcart_tax_provider_taxjar';
    }

    /**
     * TaxJar's sandbox mode is explicit — a separate token issued from
     * the TaxJar dashboard, pointed at api.sandbox.taxjar.com.
     */
    public function is_test_mode(): bool {
        return 'yes' === $this->get_setting( 'sandbox', 'no' );
    }

    /**
     * @inheritDoc
     */
    protected function request_tax( float $taxable_amount, array $context ): ?float {
        $token = (string) $this->get_setting( 'api_token', '' );
        if ( '' === $token ) {
            $this->debug( 'request_tax: api_token missing → returning null' );
            return null;
        }

        $address = $this->destination_address( $context );
        if ( '' === $address['country'] ) {
            $this->debug( 'request_tax: destination country empty → returning null', $address );
            return null;
        }

        $shipping = isset( $context['shipping_total'] ) ? (float) $context['shipping_total'] : 0.0;
        $currency = function_exists( 'tejcart_get_currency' ) ? (string) tejcart_get_currency() : '';
        if ( '' === $currency ) {
            $currency = (string) get_option( 'tejcart_currency', 'USD' );
        }
        $dp = \TejCart\Money\Currency::decimals( strtoupper( $currency ) );

        // TaxJar's /v2/taxes always treats `amount` as tax-exclusive; there's
        // no inclusive flag like Stripe's tax_behavior. If the merchant
        // stores prices including tax we have to net them out before posting,
        // otherwise TaxJar applies tax on top of an already-tax-inclusive
        // figure and the merchant collects too much.
        if ( ! empty( $context['prices_include_tax'] ) ) {
            $rate = (float) get_option( 'tejcart_tax_rate', 0 );
            if ( $rate > 0 ) {
                $taxable_amount = $taxable_amount / ( 1 + ( $rate / 100 ) );
                $shipping       = $shipping / ( 1 + ( $rate / 100 ) );
            } else {
                $this->debug(
                    'request_tax: prices_include_tax is set but tejcart_tax_rate is 0 — cannot reverse-calculate the net amount. Tax will be computed on the gross figure, which over-collects. Set a fallback tax rate under Settings → Tax → Rates, or switch to Stripe Tax / Avalara which handle inclusive pricing natively.',
                    array( 'taxable_amount' => $taxable_amount )
                );
            }
        }

        $payload = array(
            'from_country' => (string) $this->get_setting( 'origin_country', '' ),
            'from_state'   => (string) $this->get_setting( 'origin_state', '' ),
            'from_zip'     => (string) $this->get_setting( 'origin_zip', '' ),
            'to_country'   => $address['country'],
            'to_state'     => $address['state'],
            'to_zip'       => $address['postcode'],
            'to_city'      => $address['city'],
            'to_street'    => $address['line1'],
            'amount'       => round( $taxable_amount, $dp ),
            'shipping'     => round( $shipping, $dp ),
        );

        if ( '' === $payload['to_city'] ) {
            unset( $payload['to_city'] );
        }
        if ( '' === $payload['to_street'] ) {
            unset( $payload['to_street'] );
        }
        if ( '' === $payload['from_country'] ) {
            unset( $payload['from_country'], $payload['from_state'], $payload['from_zip'] );
        }

        $sandbox  = 'yes' === $this->get_setting( 'sandbox', 'no' );
        $base_url = $sandbox ? 'https://api.sandbox.taxjar.com' : 'https://api.taxjar.com';
        $url      = $base_url . '/v2/taxes';

        $this->debug( 'request_tax: POST → TaxJar', array(
            'url'     => $url,
            'sandbox' => $sandbox,
            'payload' => $payload,
        ) );

        $response = $this->remote_post(
            $url,
            array(
                'timeout' => $this->http_timeout(),
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $payload ),
            ),
            'v2/taxes'
        );

        $decoded = $this->decode_json_response( $response, 'v2/taxes' );
        if ( null === $decoded ) {
            return null;
        }

        if ( ! isset( $decoded['tax']['amount_to_collect'] ) ) {
            $this->debug( 'request_tax: TaxJar response missing tax.amount_to_collect → returning null', array(
                'top_level_keys' => array_keys( $decoded ),
            ) );
            return null;
        }

        $amount    = (float) $decoded['tax']['amount_to_collect'];
        $has_nexus = isset( $decoded['tax']['has_nexus'] ) ? (bool) $decoded['tax']['has_nexus'] : true;

        // TaxJar returns 200 OK with amount_to_collect=0 when the destination
        // is outside the merchant's configured nexus. That's the legally-
        // correct answer (no obligation to collect), but it's also the most
        // common "tax shows $0 in checkout" support case — surface it
        // explicitly so the merchant can see the configuration issue
        // without having to hand-decode the response body.
        if ( ! $has_nexus && 0.0 === $amount ) {
            $this->debug(
                'request_tax: TaxJar reports has_nexus=false for destination — no tax due. Configure nexus in your TaxJar dashboard for this state if you have collection obligation.',
                array(
                    'to_country' => $address['country'],
                    'to_state'   => $address['state'],
                    'to_zip'     => $address['postcode'],
                )
            );
        }

        $this->debug( 'request_tax: TaxJar success', array(
            'amount_to_collect' => $amount,
            'has_nexus'         => $has_nexus,
            'rate'              => isset( $decoded['tax']['rate'] ) ? (float) $decoded['tax']['rate'] : null,
        ) );
        return $amount;
    }
}
