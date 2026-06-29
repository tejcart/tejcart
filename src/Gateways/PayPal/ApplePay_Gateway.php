<?php
/**
 * Apple Pay Payment Gateway (via PayPal PPCP)
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
 * Apple Pay gateway powered by PayPal PPCP.
 */
class ApplePay_Gateway extends Abstract_Gateway {
    use PayPal_Refund_Capture;
    use Supports_PayPal_Currencies;
    use Verifies_Checkout_Nonce;
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'tejcart_applepay';
        $this->title       = 'Apple Pay';
        $this->description = 'Pay with Apple Pay.';
        $this->supports    = array( 'products', 'refunds' );

        parent::__construct();
    }

    /**
     * Define admin settings fields.
     *
     * PayPal's Applepay().config() endpoint supplies merchantCapabilities,
     * supportedNetworks, button style and merchant identity automatically based
     * on the merchant's PayPal account — none are merchant-controllable. The
     * domain status row is purely informational so the merchant knows what host
     * must be registered with Apple via the PayPal dashboard.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'        => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Apple Pay via PayPal PPCP. Requires the parent PayPal gateway to be configured and the domain to be verified with Apple through PayPal.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'          => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Apple Pay', 'tejcart' ),
            ),
            'description'    => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay with Apple Pay using Touch ID or Face ID.', 'tejcart' ),
            ),

            'domain_heading' => array(
                'type'        => 'heading',
                'title'       => __( 'Domain Registration', 'tejcart' ),
                'description' => __( 'Apple Pay requires your domain to be registered with Apple. Trigger registration from your PayPal merchant dashboard. The association file is served by PayPal.', 'tejcart' ),
            ),
            'domain_status'  => array(
                'type'        => 'readonly',
                'title'       => __( 'Domain', 'tejcart' ),
                'description' => __( 'The host that must be registered with Apple Pay via PayPal.', 'tejcart' ),
                'default'     => function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '',
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
     * is complete. Apple Pay via PayPal PPCP cannot function without PayPal
     * credentials, so there is nothing the merchant can usefully configure
     * on the settings page before onboarding.
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
     * Output the Apple Pay button container on the checkout page.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }

        echo '<div id="tejcart-applepay-container"></div>';
    }

    /**
     * Process a payment for the given order.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        // Audit #11 / 05 F-2 — defence-in-depth nonce check.
        $nonce_failure = $this->require_checkout_nonce();
        if ( null !== $nonce_failure ) {
            return $nonce_failure;
        }

        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }

        /** @var PayPal_API $api */
        $api = PayPal_Gateway::get_shared_api();

        do_action( 'tejcart_before_payment', $order_id, $order );

        $paypal_order = $api->create_order( $order );

        if ( is_wp_error( $paypal_order ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }

        $approval_url = '';

        if ( ! empty( $paypal_order['links'] ) ) {
            foreach ( $paypal_order['links'] as $link ) {
                if ( 'approve' === $link['rel'] || 'payer-action' === $link['rel'] ) {
                    $approval_url = $link['href'];
                    break;
                }
            }
        }

        if ( empty( $approval_url ) ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }

        // Audit H-13 (PPCP F-005): match the shape check used by
        // PayPal_Gateway::process_payment() and Card_Gateway.
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
     */
    protected function get_paypal_api(): PayPal_API {
        return PayPal_Gateway::get_shared_api();
    }

    protected function paypal_refund_source(): string {
        return 'paypal_apple';
    }
}
