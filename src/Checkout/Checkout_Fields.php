<?php
/**
 * Checkout field definitions.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

use TejCart\Tax\Tax_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Defines and manages checkout form fields for billing and shipping.
 */
class Checkout_Fields {
    /**
     * Per-request memo of the `tejcart_checkout_default_address` filter
     * result, keyed by user id. The filter listeners (Address_Book in
     * Tier-2, Customer_Sync in core) each hit the DB; the template path
     * calls `get_billing_fields()` and `get_shipping_fields()`
     * independently, so without a cache the listeners would run twice
     * per page render.
     *
     * @var array<int, array<string, string>>
     */
    private $saved_address_defaults_cache = array();

    /**
     * Build the Country / Region options list.
     *
     * Alphabetised by label with the configured store country pinned to
     * the top so the most common selection is a single click away.
     *
     * @return array Country code => country label.
     */
    protected function get_country_options() {
        $countries = class_exists( Tax_Manager::class ) ? Tax_Manager::get_countries() : array();
        asort( $countries );

        $store_country = (string) get_option( 'tejcart_store_country', 'US' );
        if ( isset( $countries[ $store_country ] ) ) {
            $countries = array( $store_country => $countries[ $store_country ] ) + $countries;
        }

        /**
         * Filters the Country / Region options rendered in the checkout.
         *
         * @param array $countries Country code => label.
         */
        return apply_filters( 'tejcart_checkout_country_options', $countries );
    }

    /**
     * Whether the checkout phone field is mandatory.
     *
     * Defaults to optional — matching Shopify and Baymard guidance, where
     * a required phone field is a documented top cause of checkout
     * abandonment. Merchants who genuinely need a phone number (e.g. for
     * carrier delivery notifications) can opt back in via the
     * `tejcart_require_phone` setting or the filter below.
     *
     * The format check in {@see Checkout_Validator::validate_phone()} still
     * runs whenever a value is supplied, so an optional field that *is*
     * filled in is still validated.
     *
     * @return bool
     */
    protected function is_phone_required() {
        $required = 'yes' === get_option( 'tejcart_require_phone', 'no' );

        /**
         * Filters whether the checkout phone field is required.
         *
         * @param bool $required Default false (optional).
         */
        return (bool) apply_filters( 'tejcart_checkout_phone_required', $required );
    }

    /**
     * Get billing address field definitions.
     *
     * Field order follows the modern industry-standard checkout flow
     * (per Baymard Institute usability research): email first,
     * then country (drives state dropdown + postcode validation), name,
     * optional company (progressively disclosed by JS), street address,
     * optional apartment (progressively disclosed by JS), city / state,
     * postcode, and finally phone with a "for delivery updates only"
     * helper that addresses Baymard's 39% failure rate on that guideline.
     *
     * @return array Associative array of field_key => field config.
     */
    public function get_billing_fields() {
        $default_country = (string) get_option( 'tejcart_store_country', 'US' );
        $phone_required  = $this->is_phone_required();

        $fields = array(
            'billing_email'      => array(
                'type'     => 'email',
                'label'    => __( 'Email address', 'tejcart' ),
                'required' => true,
                'priority' => 10,
                'class'    => array( 'form-row-wide' ),
            ),
            'billing_country'    => array(
                'type'     => 'select',
                'label'    => __( 'Country / Region', 'tejcart' ),
                'required' => true,
                'priority' => 20,
                'class'    => array( 'form-row-wide', 'tejcart-field-country' ),
                'options'  => $this->get_country_options(),
                'default'  => $default_country,
            ),
            'billing_first_name' => array(
                'type'     => 'text',
                'label'    => __( 'First name', 'tejcart' ),
                'required' => true,
                'priority' => 30,
                'class'    => array( 'form-row-first' ),
            ),
            'billing_last_name'  => array(
                'type'     => 'text',
                'label'    => __( 'Last name', 'tejcart' ),
                'required' => true,
                'priority' => 40,
                'class'    => array( 'form-row-last' ),
            ),
            'billing_company'    => array(
                'type'     => 'text',
                'label'    => __( 'Company name', 'tejcart' ),
                'required' => false,
                'priority' => 50,
                'class'    => array( 'form-row-wide', 'tejcart-field-progressive', 'tejcart-field-progressive--company' ),
            ),
            'billing_address_1'  => array(
                'type'     => 'text',
                'label'    => __( 'Street address', 'tejcart' ),
                'required' => true,
                'priority' => 60,
                'class'    => array( 'form-row-wide' ),
            ),
            'billing_address_2'  => array(
                'type'     => 'text',
                'label'    => __( 'Apartment, suite, unit, etc. (optional)', 'tejcart' ),
                'required' => false,
                'priority' => 70,
                'class'    => array( 'form-row-wide' ),
            ),
            'billing_city'       => array(
                'type'     => 'text',
                'label'    => __( 'City', 'tejcart' ),
                'required' => true,
                'priority' => 80,
                'class'    => array( 'form-row-wide' ),
            ),
            'billing_state'      => array(
                'type'     => 'state',
                'label'    => __( 'State / Province', 'tejcart' ),
                'required' => true,
                'priority' => 90,
                'class'    => array( 'form-row-first', 'tejcart-field-state' ),
                'options'  => Tax_Manager::get_states( $default_country ),
                // N-L3 (sibling of F-H6): pre-select the store-default
                // state on first paint so the form is submittable
                // without a click. The dynamic country picker still
                // re-populates options client-side when the buyer
                // changes country.
                'default'  => (string) get_option( 'tejcart_store_state', '' ),
            ),
            'billing_postcode'   => array(
                'type'     => 'text',
                'label'    => __( 'Postcode / ZIP', 'tejcart' ),
                'required' => true,
                'priority' => 100,
                'class'    => array( 'form-row-wide' ),
            ),
            'billing_phone'      => array(
                'type'        => 'tel',
                'label'       => __( 'Phone', 'tejcart' ),
                'required'    => $phone_required,
                'priority'    => 110,
                'class'       => array( 'form-row-wide' ),
                'description' => __( 'For delivery updates only', 'tejcart' ),
            ),
        );

        return $this->apply_saved_address_defaults( $fields );
    }

    /**
     * Get shipping address field definitions.
     *
     * @return array Associative array of field_key => field config.
     */
    public function get_shipping_fields() {
        $default_country = (string) get_option( 'tejcart_store_country', 'US' );
        $phone_required  = $this->is_phone_required();

        $fields = array(
            'shipping_country'    => array(
                'type'     => 'select',
                'label'    => __( 'Country / Region', 'tejcart' ),
                'required' => true,
                'priority' => 10,
                'class'    => array( 'form-row-wide', 'tejcart-field-country' ),
                'options'  => $this->get_country_options(),
                'default'  => $default_country,
            ),
            'shipping_first_name' => array(
                'type'     => 'text',
                'label'    => __( 'First name', 'tejcart' ),
                'required' => true,
                'priority' => 20,
                'class'    => array( 'form-row-first' ),
            ),
            'shipping_last_name'  => array(
                'type'     => 'text',
                'label'    => __( 'Last name', 'tejcart' ),
                'required' => true,
                'priority' => 30,
                'class'    => array( 'form-row-last' ),
            ),
            'shipping_company'    => array(
                'type'     => 'text',
                'label'    => __( 'Company name', 'tejcart' ),
                'required' => false,
                'priority' => 40,
                'class'    => array( 'form-row-wide', 'tejcart-field-progressive', 'tejcart-field-progressive--company' ),
            ),
            'shipping_address_1'  => array(
                'type'     => 'text',
                'label'    => __( 'Street address', 'tejcart' ),
                'required' => true,
                'priority' => 50,
                'class'    => array( 'form-row-wide' ),
            ),
            'shipping_address_2'  => array(
                'type'     => 'text',
                'label'    => __( 'Apartment, suite, unit, etc. (optional)', 'tejcart' ),
                'required' => false,
                'priority' => 60,
                'class'    => array( 'form-row-wide' ),
            ),
            'shipping_city'       => array(
                'type'     => 'text',
                'label'    => __( 'City', 'tejcart' ),
                'required' => true,
                'priority' => 70,
                'class'    => array( 'form-row-wide' ),
            ),
            'shipping_state'      => array(
                'type'     => 'state',
                'label'    => __( 'State / Province', 'tejcart' ),
                'required' => true,
                'priority' => 80,
                'class'    => array( 'form-row-first', 'tejcart-field-state' ),
                'options'  => Tax_Manager::get_states( $default_country ),
                'default'  => (string) get_option( 'tejcart_store_state', '' ),
            ),
            'shipping_postcode'   => array(
                'type'     => 'text',
                'label'    => __( 'Postcode / ZIP', 'tejcart' ),
                'required' => true,
                'priority' => 90,
                'class'    => array( 'form-row-wide' ),
            ),
            'shipping_phone'      => array(
                'type'        => 'tel',
                'label'       => __( 'Phone', 'tejcart' ),
                'required'    => $phone_required,
                'priority'    => 100,
                'class'       => array( 'form-row-wide' ),
                'description' => __( 'For delivery updates only', 'tejcart' ),
            ),
        );

        return $this->apply_saved_address_defaults( $fields );
    }

    /**
     * Get additional checkout fields such as terms & conditions and account creation.
     *
     * @return array Associative array of field_key => field config.
     */
    public function get_additional_fields() {
        $fields = array();

        if ( 'yes' === get_option( 'tejcart_enable_order_notes', 'yes' ) ) {
            $fields['customer_note'] = array(
                'type'        => 'textarea',
                'label'       => __( 'Order notes', 'tejcart' ),
                'placeholder' => __( 'Notes about your order, e.g. special delivery instructions', 'tejcart' ),
                'required'    => false,
                'priority'    => 100,
                'section'     => 'additional',
                'class'       => array( 'form-row-wide' ),
            );
        }

        $registration_enabled = 'yes' === get_option( 'tejcart_enable_registration', 'yes' );

        if ( $registration_enabled && ! is_user_logged_in() ) {
            $default_checked = 'yes' === get_option( 'tejcart_create_account_default', 'no' );

            $fields['create_account'] = array(
                'type'     => 'checkbox',
                'label'    => __( 'Create an account for faster checkout', 'tejcart' ),
                'required' => false,
                'default'  => $default_checked ? '1' : '',
                'priority' => 110,
                'section'  => 'account',
                'class'    => array( 'form-row-wide' ),
            );

            $fields['account_password'] = array(
                'type'        => 'password',
                'label'       => __( 'Account password (optional)', 'tejcart' ),
                'placeholder' => __( 'Leave blank to have one emailed to you', 'tejcart' ),
                'required'    => false,
                'priority'    => 120,
                'section'     => 'account',
                'class'       => array( 'form-row-wide' ),
            );
        }

        $terms_page_id = get_option( 'tejcart_terms_page_id', '' );

        if ( ! empty( $terms_page_id ) ) {
            $terms_url = get_permalink( (int) $terms_page_id );
            $fields['terms'] = array(
                'type'     => 'checkbox',
                'label'    => sprintf(
                    /* translators: %s: link to terms and conditions page */
                    __( 'I have read and agree to the <a href="%s" target="_blank">Terms and Conditions</a>', 'tejcart' ),
                    esc_url( $terms_url )
                ),
                'required' => true,
                'priority' => 999,
                'class'    => array( 'form-row-wide' ),
            );
        }

        return $fields;
    }

    /**
     * Get all checkout fields (billing + shipping + additional), filtered.
     *
     * The billing and shipping subsets have already had their saved-address
     * defaults applied by {@see apply_saved_address_defaults()} inside the
     * individual getters, so the merge here doesn't need to redo that work.
     *
     * @return array Merged field definitions.
     */
    public function get_fields() {
        $fields = array_merge(
            $this->get_billing_fields(),
            $this->get_shipping_fields(),
            $this->get_additional_fields()
        );

        /**
         * Filters the complete set of checkout fields.
         *
         * @param array $fields All checkout field definitions.
         */
        return apply_filters( 'tejcart_checkout_fields', $fields );
    }

    /**
     * Overlay the logged-in user's saved billing / shipping address onto
     * a field map by mutating each field's `default` key in place.
     *
     * The checkout template renders the billing and shipping subsets by
     * calling {@see get_billing_fields()} and {@see get_shipping_fields()}
     * directly (not via {@see get_fields()}), so the prefill MUST run inside
     * those individual getters or it never reaches the rendered form.
     *
     * Field values supplied by `tejcart_checkout_default_address` always
     * win over the hardcoded defaults (store country / store state) so a
     * customer whose saved address lives in a different region sees their
     * own country/state pre-selected, not the store's home region.
     *
     * Each prefilled field is also flagged with `from_saved_address = true`
     * so the renderer can surface the source to the client (the checkout
     * JS reads this on the country-field wrapper and suppresses the
     * browser-locale auto-pick that would otherwise overwrite a saved
     * country a few ms after first paint).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    protected function apply_saved_address_defaults( array $fields ) {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return $fields;
        }

        $defaults = $this->get_saved_address_defaults();
        if ( empty( $defaults ) ) {
            return $fields;
        }

        foreach ( $defaults as $field_key => $value ) {
            if ( ! isset( $fields[ $field_key ] ) ) {
                continue;
            }
            $value = (string) $value;
            if ( '' === $value ) {
                continue;
            }
            $fields[ $field_key ]['default']             = $value;
            $fields[ $field_key ]['from_saved_address'] = true;
        }

        // When the saved address overrides the country, rebuild the matching
        // state field's options against the saved country. The field map is
        // seeded with `Tax_Manager::get_states( $store_country )` (US states
        // by default), so a saved Indian state like "GJ" would not match any
        // row in the dropdown and the buyer would land on an empty state
        // select even though their saved country was applied correctly.
        foreach ( array( 'billing', 'shipping' ) as $section ) {
            $country_key = $section . '_country';
            $state_key   = $section . '_state';
            if ( ! isset( $fields[ $country_key ], $fields[ $state_key ] ) ) {
                continue;
            }
            if ( empty( $fields[ $country_key ]['from_saved_address'] ) ) {
                continue;
            }
            $saved_country = isset( $fields[ $country_key ]['default'] )
                ? (string) $fields[ $country_key ]['default']
                : '';
            if ( '' === $saved_country ) {
                continue;
            }
            $fields[ $state_key ]['options'] = Tax_Manager::get_states( $saved_country );
        }

        return $fields;
    }

    /**
     * Fetch the logged-in user's saved billing+shipping address defaults
     * via the `tejcart_checkout_default_address` filter, memoised for the
     * lifetime of this object so a single page render — which calls
     * `get_billing_fields()` and `get_shipping_fields()` separately —
     * only triggers the underlying address-book / customer-row DB lookups
     * once.
     *
     * @return array<string, string> Map of checkout field_key => value.
     */
    protected function get_saved_address_defaults() {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return array();
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id <= 0 ) {
            return array();
        }

        if ( array_key_exists( $user_id, $this->saved_address_defaults_cache ) ) {
            return $this->saved_address_defaults_cache[ $user_id ];
        }

        $context = array(
            'user_id'     => $user_id,
            'is_billing'  => true,
            'is_shipping' => true,
        );

        /**
         * Filter the default address values used to pre-fill the checkout
         * form for a logged-in customer.
         *
         * Tier2/Address_Book and Customer/Customer_Sync both listen here
         * to inject the user's saved default address.
         *
         * @param array $defaults Map of field_key => value. Empty by default.
         * @param int   $user_id  Current user ID.
         * @param array $context  Render context (is_billing / is_shipping).
         */
        $defaults = (array) apply_filters( 'tejcart_checkout_default_address', array(), $user_id, $context );

        $this->saved_address_defaults_cache[ $user_id ] = $defaults;

        return $defaults;
    }

    /**
     * Get a sanitized field value from posted data.
     *
     * Accepts raw, slash-unescaped $_POST values: per-field sanitization
     * runs here so callers no longer need to (and MUST not — see
     * Checkout::process for why a blanket sanitize_text_field corrupts the
     * password and customer_note fields).
     *
     * @param string $field_key   The field key to retrieve.
     * @param array  $posted_data The submitted form data (unslashed).
     * @return string Sanitized value or empty string.
     */
    public function get_field_value( $field_key, $posted_data ) {
        $value = $posted_data[ $field_key ] ?? '';

        // Reject array / object payloads (e.g. `billing_first_name[]=foo`)
        // before they reach the sanitisers — `sanitize_text_field( array )`
        // would emit a PHP 8 "Array to string" notice and yield the literal
        // string "Array" as a field value.
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = (string) $value;

        if ( 'billing_email' === $field_key || 'shipping_email' === $field_key ) {
            return sanitize_email( $value );
        }

        if ( 'terms' === $field_key || 'create_account' === $field_key ) {
            return ! empty( $value ) ? '1' : '';
        }

        if ( 'account_password' === $field_key ) {
            return $value;
        }

        if ( 'customer_note' === $field_key ) {
            return sanitize_textarea_field( $value );
        }

        return sanitize_text_field( $value );
    }
}
