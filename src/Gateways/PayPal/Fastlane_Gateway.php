<?php
/**
 * Fastlane by PayPal Payment Gateway
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
 * Fastlane gateway for quick checkout of returning customers.
 */
class Fastlane_Gateway extends Abstract_Gateway {
    use Verifies_Checkout_Nonce;
    use PayPal_Refund_Capture;
    use Supports_PayPal_Currencies;
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'tejcart_fastlane';
        $this->title       = 'Fastlane by PayPal';
        $this->description = 'Quick checkout for returning customers.';
        $this->supports    = array( 'products', 'refunds' );

        parent::__construct();
    }

    /**
     * Define admin settings fields.
     *
     * Fastlane's watermark and form-style options are owned by the SDK at
     * runtime — there is no merchant-facing wiring for them yet, so the
     * settings panel exposes only the basic checkout-display fields.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable PayPal Fastlane for one-click checkout of returning guest customers. Requires the parent PayPal gateway to be configured and Fastlane to be enabled on your PayPal account.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'       => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Fastlane by PayPal', 'tejcart' ),
            ),
            'description' => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Quick checkout for returning customers — no account required.', 'tejcart' ),
            ),
        );
    }

    /**
     * Check whether the gateway is available for use.
     *
     * @return bool
     */
    public function is_available(): bool {
        // @since 1.0.0
        if ( ! apply_filters( 'tejcart_fastlane_enabled', false ) ) {
            return false;
        }

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
     * is complete. Fastlane is a PayPal product and cannot function without
     * PayPal credentials, so there is nothing the merchant can usefully
     * configure on the settings page before onboarding.
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
     * Output the Fastlane container on the checkout page.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }

        echo '<div id="tejcart-fastlane-container"></div>';
        echo '<div id="tejcart-fastlane-watermark"></div>';
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
        return 'paypal_fastlane';
    }
}
