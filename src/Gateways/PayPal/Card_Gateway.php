<?php
/**
 * Advanced Credit / Debit Card Payment Gateway (PPCP Hosted Fields)
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Gateways\PayPal\Concerns\Verifies_Checkout_Nonce;

/**
 * Credit / Debit Card gateway powered by PayPal PPCP hosted card fields.
 */
class Card_Gateway extends Abstract_Gateway {
    use PayPal_Refund_Capture;
    use Supports_PayPal_Currencies;
    use Verifies_Checkout_Nonce;
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'tejcart_card';
        $this->title       = 'Credit / Debit Card';
        $this->description = 'Pay securely with your credit or debit card.';
        $this->supports    = array( 'products', 'refunds' );

        parent::__construct();
    }

    /**
     * Define admin settings fields.
     *
     * Only includes options that are actually controlled by the merchant via the
     * PayPal Orders v2 API or the Advanced Card Payments hosted-fields SDK.
     * Card brand acceptance is determined by the merchant's PayPal account
     * eligibility — there is no API option to whitelist individual brands at
     * payment time, so per-brand checkboxes are intentionally NOT exposed here.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'              => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Credit / Debit Card payments via PayPal Advanced Card Processing. Requires the parent PayPal gateway to be configured and Advanced Card Payments to be approved on your PayPal account.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'                => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Credit / Debit Card', 'tejcart' ),
            ),
            'description'          => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay securely with your credit or debit card.', 'tejcart' ),
            ),

            'behavior_heading'     => array(
                'type'        => 'heading',
                'title'       => __( 'Order Behavior', 'tejcart' ),
                'description' => __( 'Configure how card orders are processed by PayPal.', 'tejcart' ),
            ),
            'payment_action'       => array(
                'type'        => 'select',
                'title'       => __( 'Payment Action', 'tejcart' ),
                'description' => __( 'Choose whether to charge customer immediately or authorize and capture later. Leave on Inherit to use the main PayPal setting.', 'tejcart' ),
                'default'     => 'inherit',
                'options'     => array(
                    'inherit'   => __( 'Inherit from PayPal Gateway', 'tejcart' ),
                    'capture'   => __( 'Charge Immediately', 'tejcart' ),
                    'authorize' => __( 'Authorize & Capture Later', 'tejcart' ),
                ),
            ),
            'three_d_secure'       => array(
                'type'        => 'select',
                'title'       => __( '3D Secure Authentication', 'tejcart' ),
                'description' => __( 'Strong Customer Authentication for card payments. Required for PSD2 compliance in the European Economic Area.', 'tejcart' ),
                'default'     => 'SCA_WHEN_REQUIRED',
                'options'     => array(
                    'SCA_ALWAYS'        => __( 'Always Require 3D Secure Authentication', 'tejcart' ),
                    'SCA_WHEN_REQUIRED' => __( 'Require When Needed (Recommended)', 'tejcart' ),
                    'NONE'              => __( 'Never Require 3D Secure', 'tejcart' ),
                ),
            ),
            'vault_cards'          => array(
                'type'        => 'checkbox',
                'title'       => __( 'Save Cards for Future Purchases', 'tejcart' ),
                'description' => __( 'Allow logged-in customers to securely save their card details for faster future purchases. Requires Vaulting to be enabled on your PayPal account.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'soft_descriptor'      => array(
                'type'        => 'text',
                'title'       => __( 'Statement Descriptor', 'tejcart' ),
                'description' => __( 'Text shown on the buyer\'s card statement. Maximum 22 characters. Allowed: letters, numbers, dot, comma, dash, and space. Leave blank to inherit from the main PayPal gateway.', 'tejcart' ),
                'default'     => '',
            ),
        );
    }

    /**
     * Check whether the gateway is available for use.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ( ! $this->enabled ) {
            return false;
        }

        if ( ! PayPal_Gateway::is_onboarded() ) {
            return false;
        }

        return true;
    }

    /**
     * Hide this gateway from the admin list until PayPal seller onboarding
     * is complete — the hosted card fields cannot work without PayPal
     * credentials, so exposing the row just confuses merchants.
     *
     * @return bool
     */
    public function is_admin_visible(): bool {
        if ( ! PayPal_Gateway::is_onboarded() ) {
            return false;
        }

        return parent::is_admin_visible();
    }

    /**
     * Output the hosted card fields container on the checkout page.
     *
     * Markup mirrors the industry-standard card-input layout used by
     * Stripe / PayPal / Adyen reference flows: a full-width Card number
     * row with its own label, then a two-column Expiration date / Security
     * code row. A small inline card-back glyph sits inside the CVV
     * column as a non-interactive decoration (PayPal's hosted-fields
     * iframe still owns input — the SVG is purely visual chrome).
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p class="tejcart-card-fields-description">' . esc_html( $this->description ) . '</p>';
        }

        ?>
        <div id="tejcart-card-fields-container" class="tejcart-card-fields">
            <div class="tejcart-card-field tejcart-card-field--number">
                <label class="tejcart-card-field-label" for="tejcart-card-number">
                    <?php esc_html_e( 'Card number', 'tejcart' ); ?>
                </label>
                <div id="tejcart-card-number" class="tejcart-card-field-control"></div>
            </div>

            <div class="tejcart-card-fields-row">
                <div class="tejcart-card-field tejcart-card-field--expiry">
                    <label class="tejcart-card-field-label" for="tejcart-card-expiry">
                        <?php esc_html_e( 'Expiration date', 'tejcart' ); ?>
                    </label>
                    <div id="tejcart-card-expiry" class="tejcart-card-field-control"></div>
                </div>

                <div class="tejcart-card-field tejcart-card-field--cvv">
                    <label class="tejcart-card-field-label" for="tejcart-card-cvv">
                        <?php esc_html_e( 'Security code', 'tejcart' ); ?>
                    </label>
                    <div class="tejcart-card-field-control-wrap">
                        <div id="tejcart-card-cvv" class="tejcart-card-field-control"></div>
                        <span class="tejcart-card-field-cvv-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 20" focusable="false">
                                <rect x="0.5" y="0.5" width="31" height="19" rx="2.5" fill="#f1f2f3" stroke="#6d7175" stroke-width="1"/>
                                <rect x="2" y="3.5" width="28" height="4" fill="#202223"/>
                                <rect x="3.5" y="11" width="18" height="4.5" rx="0.6" fill="#ffffff" stroke="#c9cccf" stroke-width="0.6"/>
                                <rect x="23" y="11.25" width="6" height="4" rx="0.6" fill="#202223"/>
                            </svg>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Resolve the configured 3-D Secure policy for card payments.
     *
     * When the card gateway is set to `SCA_WHEN_REQUIRED` / `SCA_ALWAYS` /
     * `NONE` we pass that value through directly. An empty / unknown value
     * falls back to the safest default for the merchant region —
     * `SCA_WHEN_REQUIRED`, which PayPal documents as suitable for PSD2.
     *
     * @return string One of SCA_ALWAYS, SCA_WHEN_REQUIRED, NONE.
     */
    public function get_3ds_policy(): string {
        $allowed = array( 'SCA_ALWAYS', 'SCA_WHEN_REQUIRED', 'NONE' );
        $value   = strtoupper( (string) $this->get_option( 'three_d_secure', 'SCA_WHEN_REQUIRED' ) );
        return in_array( $value, $allowed, true ) ? $value : 'SCA_WHEN_REQUIRED';
    }

    /**
     * Process a payment for the given order.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        // Audit #11 / 05 F-2 — defence-in-depth nonce check
        // mirroring PayPal_Gateway. Upstream callers verify a nonce
        // before reaching here; this layer protects future callers
        // that might forget the gate.
        $nonce_failure = $this->require_checkout_nonce();
        if ( null !== $nonce_failure ) {
            return $nonce_failure;
        }

        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order could not be loaded. Please refresh the page and try again.', 'tejcart' ),
            );
        }

        /** @var PayPal_API $api */
        $api = PayPal_Gateway::get_shared_api();

        do_action( 'tejcart_before_payment', $order_id, $order );

        $paypal_order = $api->create_order(
            $order,
            '',
            false,
            array(
                'funding_source' => 'card',
                'three_d_secure' => $this->get_3ds_policy(),
            )
        );

        if ( is_wp_error( $paypal_order ) ) {
            $message = $paypal_order->get_error_message();
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( sprintf( 'Card create_order failed for order #%d: %s', $order_id, $message ), 'error' );
            }
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => $message,
            );
        }

        $approval_url = '';

        if ( ! empty( $paypal_order['links'] ) ) {
            foreach ( $paypal_order['links'] as $link ) {
                if ( isset( $link['rel'], $link['href'] ) && ( 'approve' === $link['rel'] || 'payer-action' === $link['rel'] ) ) {
                    $approval_url = (string) $link['href'];
                    break;
                }
            }
        }

        if ( empty( $approval_url ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'PayPal did not return a card approval URL.', 'tejcart' ),
            );
        }

        if ( empty( $paypal_order['id'] ) || ! preg_match( '/^[A-Za-z0-9-]+$/', (string) $paypal_order['id'] ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'PayPal returned an invalid order identifier.', 'tejcart' ),
            );
        }

        \TejCart\Gateways\PayPal\PayPal_Gateway::record_paypal_id_meta( $order_id, '_paypal_order_id', (string) $paypal_order['id'] );

        return array(
            'result'   => 'success',
            'redirect' => $approval_url,
        );
    }

    /**
     * Refund body lives in {@see PayPal_Refund_Capture::process_refund}.
     * These hooks share the curl-pooled PayPal_API instance with the
     * other PayPal-family gateways and tag the audit log row so
     * reconciliation can distinguish a card refund from a wallet
     * refund.
     */
    protected function get_paypal_api(): PayPal_API {
        return PayPal_Gateway::get_shared_api();
    }

    protected function paypal_refund_source(): string {
        return 'paypal_card';
    }
}
