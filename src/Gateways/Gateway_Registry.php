<?php
/**
 * Payment Gateway Registry
 *
 * @package TejCart\Gateways
 */

declare( strict_types=1 );

namespace TejCart\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central registry that manages all registered payment gateways.
 */
class Gateway_Registry {
    /**
     * Instantiated gateway objects keyed by gateway ID.
     *
     * @var Abstract_Gateway[]
     */
    private array $gateways = array();

    /**
     * Initialise the registry: collect gateway classes, instantiate them, and fire
     * the initialisation action.
     */
    public function init(): void {
        $default_gateways = array(
            \TejCart\Gateways\PayPal\PayPal_Gateway::class,
            \TejCart\Gateways\PayPal\Card_Gateway::class,
            \TejCart\Gateways\PayPal\GooglePay_Gateway::class,
            \TejCart\Gateways\PayPal\ApplePay_Gateway::class,
            \TejCart\Gateways\PayPal\Fastlane_Gateway::class,
            \TejCart\Gateways\Offline\COD_Gateway::class,
            \TejCart\Gateways\Offline\Bank_Transfer_Gateway::class,
            \TejCart\Gateways\Offline\Check_Gateway::class,
            \TejCart\Gateways\Offline\Net_Terms_Gateway::class,
            \TejCart\Gateways\Offline\Purchase_Order_Gateway::class,
        );

        /**
         * Filter the list of payment gateway class names.
         *
         * @param string[] $gateway_classes Array of fully-qualified gateway class names.
         */
        $gateway_classes = apply_filters( 'tejcart_payment_gateways', $default_gateways );

        foreach ( $gateway_classes as $class ) {
            if ( ! class_exists( $class ) ) {
                continue;
            }

            try {
                $gateway = new $class();
            } catch ( \Throwable $e ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( 'Gateway %s failed to instantiate: %s', $class, $e->getMessage() ),
                        'error'
                    );
                }
                continue;
            }

            if ( $gateway instanceof Abstract_Gateway ) {
                $this->gateways[ $gateway->get_id() ] = $gateway;
            }
        }

        /**
         * Fires after all payment gateways have been instantiated and registered.
         *
         * @param Gateway_Registry $registry The gateway registry instance.
         */
        do_action( 'tejcart_payment_gateways_initialized', $this );
    }

    /**
     * Get all gateways that are currently available for checkout.
     *
     * @return Abstract_Gateway[]
     */
    public function get_available_gateways(): array {
        $available = array();

        foreach ( $this->gateways as $id => $gateway ) {
            if ( $gateway->is_available() ) {
                $available[ $id ] = $gateway;
            }
        }

        /**
         * Filter the available payment gateways.
         *
         * @param Abstract_Gateway[] $available Available gateway instances.
         */
        return apply_filters( 'tejcart_available_payment_gateways', $available );
    }

    /**
     * Get a specific gateway by its ID.
     *
     * @param string $id Gateway ID.
     * @return Abstract_Gateway|null Gateway instance or null if not found.
     */
    public function get_gateway( string $id ): ?Abstract_Gateway {
        return $this->gateways[ $id ] ?? null;
    }

    /**
     * Get all registered gateways.
     *
     * @return Abstract_Gateway[]
     */
    public function get_gateways(): array {
        return $this->gateways;
    }
}
