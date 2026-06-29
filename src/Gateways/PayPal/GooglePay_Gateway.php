<?php
/**
 * Google Pay Payment Gateway (via PayPal PPCP)
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
 * Google Pay gateway powered by PayPal PPCP.
 */
class GooglePay_Gateway extends Abstract_Gateway {
    use PayPal_Refund_Capture;
    use Supports_PayPal_Currencies;
    use Verifies_Checkout_Nonce;
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'tejcart_googlepay';
        $this->title       = 'Google Pay';
        $this->description = 'Pay quickly with Google Pay.';
        $this->supports    = array( 'products', 'refunds' );

        parent::__construct();
    }

    /**
     * Define admin settings fields.
     *
     * PayPal's Googlepay().config() endpoint supplies allowed card networks, auth
     * methods and gateway merchant identity automatically based on the merchant's
     * PayPal account eligibility. The button style and per-page placement options
     * exposed here drive `paymentsClient.createButton()` on the storefront, so the
     * merchant can match Google Pay's button to their theme and choose where it
     * shows up alongside the other express-checkout wallets.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'                 => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Google Pay via PayPal PPCP. Requires the parent PayPal gateway to be configured and Google Pay to be enabled on your PayPal account.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'                   => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Google Pay', 'tejcart' ),
            ),
            'description'             => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay quickly with Google Pay.', 'tejcart' ),
            ),

            'placement_heading'       => array(
                'type'        => 'heading',
                'title'       => __( 'Google Pay Button Placement', 'tejcart' ),
                'description' => __( 'Choose where the Google Pay button appears on your store. The button only renders on devices and browsers that Google Pay supports.', 'tejcart' ),
            ),
            'button_product_page'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Product Page', 'tejcart' ),
                'description' => __( 'Show the Google Pay button on individual product pages.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_cart_page'        => array(
                'type'        => 'checkbox',
                'title'       => __( 'Cart Page', 'tejcart' ),
                'description' => __( 'Show the Google Pay button on the cart page.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_express_checkout' => array(
                'type'        => 'checkbox',
                'title'       => __( 'Express Checkout (Top of Checkout)', 'tejcart' ),
                'description' => __( 'Show the Google Pay button at the top of the checkout page for quick payment.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_side_cart'        => array(
                'type'        => 'checkbox',
                'title'       => __( 'Side Cart (Cart Drawer)', 'tejcart' ),
                'description' => __( 'Show the Google Pay button in the slide-out cart drawer.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_checkout'         => array(
                'type'        => 'checkbox',
                'title'       => __( 'Checkout Payment Section', 'tejcart' ),
                'description' => __( 'Show the Google Pay button in the payment methods section at checkout.', 'tejcart' ),
                'default'     => 'yes',
            ),

            'style_heading'           => array(
                'type'        => 'heading',
                'title'       => __( 'Google Pay Button Style', 'tejcart' ),
                'description' => __( 'Customize the appearance of the Google Pay button. These options map directly to Google Pay\'s `createButton` API.', 'tejcart' ),
            ),
            'button_color'            => array(
                'type'        => 'select',
                'title'       => __( 'Button Color', 'tejcart' ),
                'description' => __( 'Google Pay button color. Use "white" only on dark backgrounds; per Google\'s brand guidelines white buttons require a visible outline.', 'tejcart' ),
                'default'     => 'black',
                'options'     => array(
                    'default' => __( 'Default (Google Default)', 'tejcart' ),
                    'black'   => __( 'Black (Recommended)', 'tejcart' ),
                    'white'   => __( 'White (For Dark Backgrounds)', 'tejcart' ),
                ),
            ),
            'button_type'             => array(
                'type'        => 'select',
                'title'       => __( 'Button Type', 'tejcart' ),
                'description' => __( 'Text/intent shown on the Google Pay button.', 'tejcart' ),
                'default'     => 'buy',
                'options'     => array(
                    'book'      => __( 'Book with Google Pay', 'tejcart' ),
                    'buy'       => __( 'Buy with Google Pay (Recommended)', 'tejcart' ),
                    'checkout'  => __( 'Checkout with Google Pay', 'tejcart' ),
                    'donate'    => __( 'Donate with Google Pay', 'tejcart' ),
                    'order'     => __( 'Order with Google Pay', 'tejcart' ),
                    'pay'       => __( 'Pay with Google Pay', 'tejcart' ),
                    'plain'     => __( 'Plain (Logo Only)', 'tejcart' ),
                    'subscribe' => __( 'Subscribe with Google Pay', 'tejcart' ),
                ),
            ),
            'button_size_mode'        => array(
                'type'        => 'select',
                'title'       => __( 'Button Size Mode', 'tejcart' ),
                'description' => __( '"Fill" stretches the button to its container; "Static" uses Google Pay\'s default fixed size.', 'tejcart' ),
                'default'     => 'fill',
                'options'     => array(
                    'fill'   => __( 'Fill Container (Recommended)', 'tejcart' ),
                    'static' => __( 'Static (Fixed Width)', 'tejcart' ),
                ),
            ),
            'button_radius'           => array(
                'type'        => 'number',
                'title'       => __( 'Button Corner Radius (px)', 'tejcart' ),
                'description' => __( 'Corner radius of the Google Pay button. Allowed range: 0-100. Use 0 for sharp corners.', 'tejcart' ),
                'default'     => '6',
                'min'         => 0,
                'max'         => 100,
                'step'        => 1,
            ),
            'button_locale'           => array(
                'type'        => 'text',
                'title'       => __( 'Button Locale', 'tejcart' ),
                'description' => __( 'Optional ISO-639 / IETF BCP-47 locale code for the button text (e.g. "en", "es", "fr-CA"). Leave blank to use the visitor\'s browser locale.', 'tejcart' ),
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
     * is complete. Google Pay via PayPal PPCP cannot function without PayPal
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
     * Output the Google Pay button container on the checkout page.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }

        echo '<div id="tejcart-googlepay-container"></div>';
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
        return 'paypal_google';
    }
}
