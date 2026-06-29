<?php
/**
 * Abstract shipping method.
 *
 * @package TejCart\Shipping\Shipping_Methods
 */

declare( strict_types=1 );

namespace TejCart\Shipping\Shipping_Methods;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base class for all shipping methods.
 *
 * Concrete implementations must provide a calculate() method that returns
 * the shipping cost for a given cart.
 */
abstract class Abstract_Shipping_Method {
    /**
     * Unique method identifier.
     *
     * @var string
     */
    protected $id = '';

    /**
     * Human-readable title.
     *
     * @var string
     */
    protected $title = '';

    /**
     * Whether the method is enabled.
     *
     * @var bool
     */
    protected $enabled = true;

    /**
     * Method settings.
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Admin form-field definitions for this method's settings UI.
     *
     * Schema mirrors Abstract_Gateway::$form_fields — keyed by setting ID,
     * each value is an array with at least `type` and optional `default`,
     * `title`, `description`, `options`, etc. Live-rate carrier plugins
     * (UPS, FedEx, DHL) declare credential fields here.
     *
     * @var array
     */
    protected $form_fields = array();

    /**
     * Calculate the shipping cost for the given cart.
     *
     * @param mixed $cart Cart instance.
     * @return float Shipping cost.
     */
    abstract public function calculate( $cart );

    /**
     * Determine whether this method is available for the given cart.
     *
     * @param mixed $cart Cart instance (may be null).
     * @return bool
     */
    public function is_available( $cart ) {
        $available = $this->enabled;

        /**
         * Filter whether a shipping method is available for the current cart.
         *
         * Live-rate carriers (UPS, FedEx, DHL, etc.) can short-circuit here
         * when the destination is unsupported, the cart is missing weights,
         * or the carrier API is offline.
         *
         * @param bool                    $available Current availability.
         * @param Abstract_Shipping_Method $method    Method instance.
         * @param mixed                   $cart      Cart instance (may be null).
         */
        return (bool) apply_filters( 'tejcart_shipping_method_available', $available, $this, $cart );
    }

    /**
     * Return the method title.
     *
     * @return string
     */
    public function get_title() {
        return $this->title;
    }

    /**
     * Return the method ID.
     *
     * @return string
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Check if the method is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->enabled;
    }

    /**
     * Set the method settings from a configuration array.
     *
     * @param array $settings Key-value settings.
     */
    public function set_settings( $settings ) {
        if ( is_array( $settings ) ) {
            $this->settings = $settings;
        }

        if ( isset( $this->settings['enabled'] ) ) {
            $this->enabled = 'yes' === $this->settings['enabled'];
        }

        if ( isset( $this->settings['title'] ) && '' !== $this->settings['title'] ) {
            $this->title = sanitize_text_field( $this->settings['title'] );
        }
    }

    /**
     * Return all settings.
     *
     * @return array
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get a single setting value with a default fallback.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default returned when the key is unset.
     * @return mixed
     */
    public function get_option( $key, $default = '' ) {
        if ( isset( $this->settings[ $key ] ) ) {
            return $this->settings[ $key ];
        }

        if ( isset( $this->form_fields[ $key ]['default'] ) ) {
            return $this->form_fields[ $key ]['default'];
        }

        return $default;
    }

    /**
     * Round a computed shipping cost to the store currency's decimal precision.
     *
     * @param float $cost Raw cost.
     * @return float
     */
    protected function round_cost( float $cost ): float {
        $currency = (string) get_option( 'tejcart_currency', 'USD' );
        return round( $cost, \TejCart\Money\Currency::decimals( $currency ) );
    }

    /**
     * Initialise admin form-field definitions for this method.
     *
     * Override in subclasses to declare credential / configuration fields
     * (e.g. FedEx account number, UPS API key). Default is no fields.
     */
    public function init_form_fields() {
        $this->form_fields = array();
    }

    /**
     * Return the admin form-field definitions.
     *
     * @return array
     */
    public function get_form_fields() {
        if ( empty( $this->form_fields ) ) {
            $this->init_form_fields();
        }

        /**
         * Filter the form-field schema for a shipping method.
         *
         * @param array                    $form_fields Field definitions.
         * @param Abstract_Shipping_Method $method      Method instance.
         */
        return apply_filters( 'tejcart_shipping_method_form_fields', $this->form_fields, $this );
    }
}
