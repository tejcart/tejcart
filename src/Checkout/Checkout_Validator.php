<?php
/**
 * Checkout form validation.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates checkout form data including billing, shipping, email, and coupons.
 */
class Checkout_Validator {
    /**
     * Collected validation errors.
     *
     * @var \WP_Error
     */
    private $errors;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->errors = new \WP_Error();
    }

    /**
     * Validate all required checkout fields.
     *
     * @param array $data Posted checkout data.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function validate( $data ) {
        $this->errors = new \WP_Error();

        // Note: validate_billing() already runs validate_email() against
        // billing_email when present, so we don't call it again here —
        // duplicating it would emit two identical `invalid_email` rows
        // into the WP_Error and surface the same toast twice.
        $this->validate_billing( $data );
        $this->validate_shipping( $data );
        $this->validate_shipping_method( $data );
        $this->validate_additional( $data );
        $this->validate_cart_totals();
        $this->validate_address_formats( $data );

        if ( ! empty( $data['billing_phone'] ) ) {
            $this->validate_phone( $data['billing_phone'], 'billing_phone' );
        }

        if ( $this->errors->has_errors() ) {
            return $this->errors;
        }

        return true;
    }

    /**
     * Validate the "additional" checkout fields registered in
     * Checkout_Fields::get_additional_fields() — terms acceptance, account
     * creation toggle, etc.
     *
     * Without this pass the `terms` checkbox is purely cosmetic: HTML5
     * `required` blocks the form on modern browsers but a hand-crafted
     * POST goes straight through. That breaks compliance for any merchant
     * who selects a Terms & Conditions page in settings.
     *
     * Checkbox fields are validated against truthy presence rather than
     * non-empty string, since unchecked checkboxes are simply absent from
     * the POST payload (their value is never an empty string).
     *
     * @param array $data Posted checkout data.
     * @return void
     */
    public function validate_additional( $data ) {
        $fields_instance   = new Checkout_Fields();
        $additional_fields = $fields_instance->get_additional_fields();

        foreach ( $additional_fields as $key => $field ) {
            if ( empty( $field['required'] ) ) {
                continue;
            }

            $type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

            if ( 'checkbox' === $type ) {
                if ( empty( $data[ $key ] ) ) {
                    if ( 'terms' === $key ) {
                        $this->add_error(
                            'terms_required',
                            __( 'Please read and accept the terms and conditions to proceed with your order.', 'tejcart' )
                        );
                    } else {
                        $this->add_error(
                            $key . '_required',
                            /* translators: %s: field label */
                            sprintf( __( '%s is a required field.', 'tejcart' ), wp_strip_all_tags( (string) $field['label'] ) )
                        );
                    }
                }
                continue;
            }

            if ( ! isset( $data[ $key ] ) || '' === trim( (string) $data[ $key ] ) ) {
                $this->add_error(
                    $key . '_required',
                    /* translators: %s: field label */
                    sprintf( __( '%s is a required field.', 'tejcart' ), wp_strip_all_tags( (string) $field['label'] ) )
                );
            }
        }
    }

    /**
     * Validate country, state and postcode formats for billing and shipping.
     *
     * Countries are required to be valid ISO 3166-1 alpha-2 codes. Postcodes
     * are checked against a per-country regex when one is known — this keeps
     * typo'd zip codes (and payment declines that would follow) out of the
     * order pipeline without pretending to enforce full address validity.
     *
     * @param array $data Sanitized posted data.
     * @return void
     */
    public function validate_address_formats( $data ) {
        $prefixes = array( 'billing' );

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        $needs_shipping = ( $cart && is_object( $cart ) && method_exists( $cart, 'needs_shipping' ) )
            ? $cart->needs_shipping()
            : false;
        if ( $needs_shipping ) {
            $prefixes[] = 'shipping';
        }

        foreach ( $prefixes as $prefix ) {
            $country  = isset( $data[ $prefix . '_country' ] ) ? strtoupper( trim( (string) $data[ $prefix . '_country' ] ) ) : '';
            $state    = isset( $data[ $prefix . '_state' ] ) ? trim( (string) $data[ $prefix . '_state' ] ) : '';
            $postcode = isset( $data[ $prefix . '_postcode' ] ) ? trim( (string) $data[ $prefix . '_postcode' ] ) : '';

            if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
                $this->add_error(
                    $prefix . '_country_invalid',
                    __( 'Please select a valid country.', 'tejcart' )
                );

                continue;
            }

            if ( '' !== $postcode && ! $this->postcode_matches_country( $postcode, $country ) ) {
                $this->add_error(
                    $prefix . '_postcode_invalid',
                    sprintf(
                        /* translators: %s: country code */
                        __( 'The postcode format is not valid for %s.', 'tejcart' ),
                        $country
                    )
                );
            }

            if ( '' !== $state && strlen( $state ) > 64 ) {
                $this->add_error(
                    $prefix . '_state_invalid',
                    __( 'The state/region value is too long.', 'tejcart' )
                );
            }
        }
    }

    /**
     * Per-country postcode patterns.
     *
     * Limited to the most common storefront markets — countries not in the
     * list pass through as valid (we don't want to reject orders from
     * territories we haven't encoded a pattern for).
     *
     * @param string $postcode Sanitized postcode string.
     * @param string $country  ISO 3166-1 alpha-2 country code.
     * @return bool
     */
    private function postcode_matches_country( $postcode, $country ) {
        $patterns = array(
            'US' => '/^\d{5}(-\d{4})?$/',
            'CA' => '/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z][ -]?\d[ABCEGHJ-NPRSTV-Z]\d$/i',
            'GB' => '/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i',
            'DE' => '/^\d{5}$/',
            'FR' => '/^\d{5}$/',
            'ES' => '/^\d{5}$/',
            'IT' => '/^\d{5}$/',
            'NL' => '/^\d{4}\s*[A-Z]{2}$/i',
            'AU' => '/^\d{4}$/',
            'NZ' => '/^\d{4}$/',
            'JP' => '/^\d{3}-?\d{4}$/',
            'IN' => '/^\d{6}$/',
            'BR' => '/^\d{5}-?\d{3}$/',
            'MX' => '/^\d{5}$/',
            'IE' => '/^[A-Z0-9]{3}\s*[A-Z0-9]{4}$/i',
        );

        /**
         * Filter the per-country postcode patterns used for checkout validation.
         *
         * @param array $patterns Map of ISO country code to regex.
         */
        $patterns = (array) apply_filters( 'tejcart_postcode_patterns', $patterns );

        if ( ! isset( $patterns[ $country ] ) ) {
            return true;
        }

        return (bool) preg_match( $patterns[ $country ], $postcode );
    }

    /**
     * Validate that a phone number contains only digits, spaces, and the
     * punctuation commonly allowed in international formats.
     *
     * @param string $phone Phone number.
     * @param string $code  Error code to emit.
     * @return void
     */
    public function validate_phone( $phone, $code = 'invalid_phone' ) {
        $phone = trim( (string) $phone );
        if ( '' === $phone ) {
            return;
        }

        $digits = preg_replace( '/[\s().+\-]/', '', $phone );

        if ( null === $digits || ! preg_match( '/^\d{7,15}$/', (string) $digits ) ) {
            $this->add_error(
                $code,
                __( 'Please enter a valid phone number.', 'tejcart' )
            );
        }
    }

    /**
     * Enforce the configured minimum and maximum order amounts.
     *
     * Reads `tejcart_cart_minimum_amount` and `tejcart_cart_maximum_amount`
     * settings and adds validation errors if the cart subtotal falls
     * outside the allowed range.
     */
    public function validate_cart_totals() {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! $cart || ! method_exists( $cart, 'get_total' ) ) {
            return;
        }

        // C-M7: the min/max order thresholds gate the actual "order value"
        // the buyer will pay, so compare against the payable grand total
        // (get_total(): subtotal − discounts + shipping + tax) rather than
        // the pre-discount subtotal. The previous subtotal-based check
        // could reject a buyer whose discounted total was below the
        // minimum's intent, or pass one whose payable total exceeded the
        // maximum.
        $amount = (float) $cart->get_total();
        $min    = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
        $max    = (float) get_option( 'tejcart_cart_maximum_amount', 0 );

        if ( $min > 0 && $amount < $min ) {
            $this->add_error(
                'cart_below_minimum',
                sprintf(
                    /* translators: %s: formatted price */
                    __( 'A minimum order of %s is required to checkout.', 'tejcart' ),
                    function_exists( 'tejcart_price' ) ? tejcart_price( $min ) : $min
                )
            );
        }

        if ( $max > 0 && $amount > $max ) {
            $this->add_error(
                'cart_above_maximum',
                sprintf(
                    /* translators: %s: formatted price */
                    __( 'Orders cannot exceed %s. Please remove items from your cart.', 'tejcart' ),
                    function_exists( 'tejcart_price' ) ? tejcart_price( $max ) : $max
                )
            );
        }
    }

    /**
     * Validate billing fields.
     *
     * @param array $data Posted checkout data.
     * @return void
     */
    public function validate_billing( $data ) {
        $fields_instance = new Checkout_Fields();
        $billing_fields  = $fields_instance->get_billing_fields();

        foreach ( $billing_fields as $key => $field ) {
            if ( ! empty( $field['required'] ) && ( ! isset( $data[ $key ] ) || '' === trim( (string) $data[ $key ] ) ) ) {
                $this->add_error(
                    $key . '_required',
                    /* translators: %s: field label */
                    sprintf( __( '%s is a required field.', 'tejcart' ), wp_strip_all_tags( (string) $field['label'] ) )
                );
            }
        }

        // The required-check above already emits billing_email_required
        // when the field is empty; only run the format check when there is
        // an actual value to validate so we don't double-report.
        if ( ! empty( $data['billing_email'] ) ) {
            $this->validate_email( $data['billing_email'] );
        }
    }

    /**
     * Validate shipping fields if the cart needs shipping.
     *
     * @param array $data Posted checkout data.
     * @return void
     */
    public function validate_shipping( $data ) {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;

        if ( null === $cart || ( is_object( $cart ) && method_exists( $cart, 'needs_shipping' ) && ! $cart->needs_shipping() ) ) {
            return;
        }

        $fields_instance  = new Checkout_Fields();
        $shipping_fields  = $fields_instance->get_shipping_fields();

        foreach ( $shipping_fields as $key => $field ) {
            if ( ! empty( $field['required'] ) && ( ! isset( $data[ $key ] ) || '' === trim( (string) $data[ $key ] ) ) ) {
                $this->add_error(
                    $key . '_required',
                    /* translators: %s: field label */
                    sprintf( __( '%s is a required field.', 'tejcart' ), wp_strip_all_tags( (string) $field['label'] ) )
                );
            }
        }
    }

    /**
     * Require a shipping method selection when — and only when — the
     * cart contains physical products. Digital-only carts bypass this
     * check entirely, mirroring the gating used in the checkout
     * template and {@see validate_shipping()}.
     *
     * @param array $data Posted checkout data.
     * @return void
     */
    public function validate_shipping_method( $data ) {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;

        if ( null === $cart || ! is_object( $cart ) ) {
            return;
        }

        if ( method_exists( $cart, 'needs_shipping' ) && ! $cart->needs_shipping() ) {
            return;
        }

        if ( ! class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            return;
        }

        // Audit #3 / 02 H-2 — address resolution must match
        // Shipping_Method_Capture's. Prefer POSTed fields first
        // because that's what the buyer is committing to right now;
        // the session customer object may carry stale country/state
        // from a prior visit, which would silently let the validator
        // accept a method the POSTed address doesn't actually support.
        $country = '';
        $state   = '';
        if ( isset( $data['shipping_country'] ) && '' !== (string) $data['shipping_country'] ) {
            $country = (string) $data['shipping_country'];
        } elseif ( isset( $data['billing_country'] ) && '' !== (string) $data['billing_country'] ) {
            $country = (string) $data['billing_country'];
        }
        if ( isset( $data['shipping_state'] ) && '' !== (string) $data['shipping_state'] ) {
            $state = (string) $data['shipping_state'];
        } elseif ( isset( $data['billing_state'] ) && '' !== (string) $data['billing_state'] ) {
            $state = (string) $data['billing_state'];
        }
        if ( '' === $country && method_exists( $cart, 'get_customer' ) ) {
            $customer = $cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_shipping_country' ) ) {
                $country = (string) $customer->get_shipping_country();
            }
        }
        if ( '' === $state && method_exists( $cart, 'get_customer' ) ) {
            $customer = $cart->get_customer();
            if ( is_object( $customer ) && method_exists( $customer, 'get_shipping_state' ) ) {
                $state = (string) $customer->get_shipping_state();
            }
        }

        $postcode = isset( $data['shipping_postcode'] ) ? (string) $data['shipping_postcode'] : '';
        if ( '' === $postcode ) {
            $postcode = isset( $data['billing_postcode'] ) ? (string) $data['billing_postcode'] : '';
        }

        $manager = new \TejCart\Shipping\Shipping_Manager();
        $methods = $manager->get_available_methods( $country, $state, $cart, $postcode );

        if ( empty( $methods ) ) {
            $this->add_error(
                'no_shipping_methods',
                __( 'No shipping methods are available for your address. Please update your address.', 'tejcart' )
            );
            return;
        }

        $chosen = isset( $data['tejcart_shipping_method'] ) ? trim( (string) $data['tejcart_shipping_method'] ) : '';

        if ( '' === $chosen ) {
            $this->add_error(
                'shipping_method_required',
                __( 'Please choose a shipping method.', 'tejcart' )
            );
            return;
        }

        $valid_ids = array();
        foreach ( $methods as $method ) {
            if ( is_object( $method ) && method_exists( $method, 'get_id' ) ) {
                $valid_ids[] = (string) $method->get_id();
            }
        }

        if ( ! in_array( $chosen, $valid_ids, true ) ) {
            $this->add_error(
                'shipping_method_invalid',
                __( 'The selected shipping method is not available for your address.', 'tejcart' )
            );
        }
    }

    /**
     * Validate an email address format.
     *
     * @param string $email Email address to validate.
     * @return void
     */
    public function validate_email( $email ) {
        if ( ! is_email( $email ) ) {
            $this->add_error(
                'invalid_email',
                __( 'Please provide a valid email address.', 'tejcart' )
            );
        }
    }

    /**
     * Validate that a coupon code is valid and applicable to the cart.
     *
     * @param string $code Coupon code.
     * @param object $cart Cart instance.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function validate_coupon( $code, $cart ) {
        if ( empty( $code ) ) {
            $this->add_error(
                'empty_coupon',
                __( 'Please enter a coupon code.', 'tejcart' )
            );
            return $this->errors;
        }

        /**
         * Allow plugins to validate a coupon before applying it.
         *
         * @param bool   $valid Whether the coupon is valid.
         * @param string $code  The coupon code.
         * @param object $cart  The cart instance.
         */
        $valid = apply_filters( 'tejcart_validate_coupon', true, $code, $cart );

        if ( true !== $valid ) {
            $message = is_string( $valid )
                ? $valid
                : __( 'This coupon is not valid.', 'tejcart' );

            $this->add_error( 'invalid_coupon', $message );
        }

        if ( $this->errors->has_errors() ) {
            return $this->errors;
        }

        return true;
    }

    /**
     * Add a validation error.
     *
     * @param string $code    Error code.
     * @param string $message Human-readable error message.
     * @return void
     */
    private function add_error( $code, $message ) {
        $this->errors->add( $code, $message );
    }
}
