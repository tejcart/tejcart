<?php
/**
 * Avalara AvaTax tax provider driver.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculate tax through Avalara's AvaTax REST API
 * (POST /api/v2/transactions/create).
 *
 * The transaction here is created with `type=SalesOrder`, which is a
 * non-committal quote — AvaTax does *not* record this as a sale. The
 * committed sale should be re-posted as `type=SalesInvoice` from the
 * order-completion path; that wiring is left to a future iteration so
 * existing merchants can opt in to AvaTax for live rate quotes without
 * also signing up for posting.
 *
 * Configuration:
 *   - account_id:   Avalara account ID.
 *   - license_key:  Avalara license key. Stored encrypted.
 *   - company_code: Company code defined in the AvaTax dashboard.
 *   - sandbox:      When 'yes', use sandbox-rest.avatax.com. Required for
 *                   development accounts.
 *   - origin_*: Ship-from address (country, state, postcode, line1, city).
 */
class Avalara_Tax_Provider extends Abstract_Live_Tax_Provider {
    /**
     * @inheritDoc
     */
    public const CREDENTIAL_KEYS = array(
        array(
            'id'          => 'enabled',
            'label'       => 'Enabled',
            'type'        => 'checkbox',
            'description' => 'Use Avalara AvaTax for live calculations.',
            'required'    => false,
        ),
        array(
            'id'          => 'account_id',
            'label'       => 'Account ID',
            'type'        => 'text',
            'description' => 'Avalara account number.',
            'required'    => true,
        ),
        array(
            'id'          => 'license_key',
            'label'       => 'License key',
            'type'        => 'password',
            'description' => 'Avalara license key. Stored encrypted.',
            'required'    => true,
        ),
        array(
            'id'          => 'company_code',
            'label'       => 'Company code',
            'type'        => 'text',
            'description' => 'Company code as configured in AvaTax.',
            'required'    => true,
        ),
        array(
            'id'          => 'sandbox',
            'label'       => 'Sandbox mode',
            'type'        => 'checkbox',
            'description' => 'Send requests to sandbox-rest.avatax.com.',
            'required'    => false,
        ),
        array(
            'id'          => 'origin_country',
            'label'       => 'Origin country',
            'type'        => 'text',
            'required'    => true,
        ),
        array(
            'id'          => 'origin_state',
            'label'       => 'Origin state',
            'type'        => 'text',
            'required'    => false,
        ),
        array(
            'id'          => 'origin_postcode',
            'label'       => 'Origin postcode',
            'type'        => 'text',
            'required'    => true,
        ),
        array(
            'id'          => 'origin_city',
            'label'       => 'Origin city',
            'type'        => 'text',
            'required'    => false,
        ),
        array(
            'id'          => 'origin_line1',
            'label'       => 'Origin street',
            'type'        => 'text',
            'required'    => false,
        ),
    );

    /**
     * @inheritDoc
     */
    public const SECRET_KEYS = array( 'license_key' );

    public function __construct() {
        $this->id         = 'avalara';
        $this->title      = 'Avalara AvaTax';
        $this->option_key = 'tejcart_tax_provider_avalara';
    }

    /**
     * Avalara's sandbox mode uses a separate host (sandbox-rest.avatax.com)
     * and a development-account credential set.
     */
    public function is_test_mode(): bool {
        return 'yes' === $this->get_setting( 'sandbox', 'no' );
    }

    /**
     * @inheritDoc
     */
    protected function request_tax( float $taxable_amount, array $context ): ?float {
        $account_id  = (string) $this->get_setting( 'account_id', '' );
        $license_key = (string) $this->get_setting( 'license_key', '' );
        $company     = (string) $this->get_setting( 'company_code', '' );

        if ( '' === $account_id || '' === $license_key || '' === $company ) {
            $this->debug( 'request_tax: account_id / license_key / company_code missing → returning null', array(
                'has_account_id'  => '' !== $account_id,
                'has_license_key' => '' !== $license_key,
                'has_company'     => '' !== $company,
            ) );
            return null;
        }

        $address = $this->destination_address( $context );
        if ( '' === $address['country'] ) {
            $this->debug( 'request_tax: destination country empty → returning null', $address );
            return null;
        }

        $shipping        = isset( $context['shipping_total'] ) ? (float) $context['shipping_total'] : 0.0;
        $tax_included    = ! empty( $context['prices_include_tax'] );
        $currency_code   = strtoupper( $this->resolve_currency() );
        $dp              = \TejCart\Money\Currency::decimals( $currency_code );

        $subtotal_line = array(
            'number'      => '1',
            'amount'      => round( $taxable_amount, $dp ),
            'taxCode'     => 'P0000000',
            'description' => 'Cart subtotal',
        );
        if ( $tax_included ) {
            // AvaTax honours per-line taxIncluded — when set, AvaTax derives
            // the net by reverse-calculating the destination rate. We pass
            // it through so inclusive-pricing stores compute correctly
            // without us having to know the rate up front.
            $subtotal_line['taxIncluded'] = true;
        }
        $lines = array( $subtotal_line );

        if ( $shipping > 0 ) {
            $shipping_line = array(
                'number'      => '2',
                'amount'      => round( $shipping, $dp ),
                'taxCode'     => 'FR020100',
                'description' => 'Shipping',
            );
            if ( $tax_included ) {
                $shipping_line['taxIncluded'] = true;
            }
            $lines[] = $shipping_line;
        }

        $payload = array(
            'type'        => 'SalesOrder',
            'companyCode' => $company,
            'date'        => gmdate( 'Y-m-d' ),
            'customerCode' => 'tejcart-cart',
            'currencyCode' => $currency_code,
            'addresses'    => array(
                'shipFrom' => array(
                    'country'    => (string) $this->get_setting( 'origin_country', '' ),
                    'region'     => (string) $this->get_setting( 'origin_state', '' ),
                    'postalCode' => (string) $this->get_setting( 'origin_postcode', '' ),
                    'city'       => (string) $this->get_setting( 'origin_city', '' ),
                    'line1'      => (string) $this->get_setting( 'origin_line1', '' ),
                ),
                'shipTo'   => array(
                    'country'    => $address['country'],
                    'region'     => $address['state'],
                    'postalCode' => $address['postcode'],
                    'city'       => $address['city'],
                    'line1'      => $address['line1'],
                ),
            ),
            'lines'        => $lines,
        );

        $sandbox  = 'yes' === $this->get_setting( 'sandbox', 'no' );
        $base_url = $sandbox ? 'https://sandbox-rest.avatax.com' : 'https://rest.avatax.com';
        $url      = $base_url . '/api/v2/transactions/create';

        $this->debug( 'request_tax: POST → Avalara AvaTax', array(
            'url'     => $url,
            'sandbox' => $sandbox,
            'payload' => $payload,
        ) );

        $response = $this->remote_post(
            $url,
            array(
                'timeout' => $this->http_timeout(),
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $account_id . ':' . $license_key ),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => wp_json_encode( $payload ),
            ),
            'transactions/create'
        );

        $decoded = $this->decode_json_response( $response, 'transactions/create' );
        if ( null === $decoded ) {
            return null;
        }

        if ( ! isset( $decoded['totalTax'] ) ) {
            $this->debug( 'request_tax: Avalara response missing totalTax → returning null', array(
                'top_level_keys' => array_keys( $decoded ),
            ) );
            return null;
        }

        $tax = (float) $decoded['totalTax'];
        $this->debug( 'request_tax: Avalara success', array( 'totalTax' => $tax ) );
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
