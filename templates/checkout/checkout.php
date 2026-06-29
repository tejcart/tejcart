<?php
/**
 * Checkout page template.
 *
 * Modern, single-page checkout — two columns on desktop (form +
 * sticky order summary), collapsible summary on mobile. Preserves
 * all original action hooks so extensions keep working.
 *
 * @package TejCart\Templates\Checkout
 *
 * @var \TejCart\Cart\Cart                $cart               The cart instance.
 * @var \TejCart\Checkout\Checkout_Fields $checkout_fields    Checkout fields manager.
 * @var \TejCart\Gateways\Abstract_Gateway[] $available_gateways Available payment gateways.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fires before the checkout form.
 *
 * @param \TejCart\Cart\Cart $cart The cart instance.
 */
do_action( 'tejcart_before_checkout_form', $cart );

$tejcart_checkout_paypal = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_paypal' ) : null;
$tejcart_checkout_ready  = $tejcart_checkout_paypal && $tejcart_checkout_paypal->is_available();
$tejcart_show_express    = $tejcart_checkout_ready
    && 'yes' === $tejcart_checkout_paypal->get_option( 'button_express_checkout', 'yes' );
$tejcart_show_express_gp = $tejcart_show_express
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' );
$tejcart_show_express_ap = $tejcart_show_express
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' );
$tejcart_show_express_vm = $tejcart_show_express
    && 'yes' === $tejcart_checkout_paypal->get_option( 'enable_venmo', 'yes' );
$tejcart_paylater_enabled = $tejcart_checkout_ready
    && 'yes' === $tejcart_checkout_paypal->get_option( 'enable_paylater', 'yes' );
$tejcart_show_express_pl  = $tejcart_paylater_enabled && $tejcart_show_express
    && 'yes' === $tejcart_checkout_paypal->get_option( 'paylater_express_checkout', 'no' );

$tejcart_pl_layout      = $tejcart_paylater_enabled
    ? (string) $tejcart_checkout_paypal->get_option( 'paylater_style_layout', 'text' )
    : 'text';
$tejcart_pl_logo_type   = $tejcart_paylater_enabled
    ? (string) $tejcart_checkout_paypal->get_option( 'paylater_style_logo_type', 'primary' )
    : 'primary';
$tejcart_pl_text_color  = $tejcart_paylater_enabled
    ? (string) $tejcart_checkout_paypal->get_option( 'paylater_style_text_color', 'black' )
    : 'black';

$tejcart_total = (float) $cart->get_total();

$tejcart_block_reason   = '';
$tejcart_block_redirect = '';
if ( ! is_user_logged_in() && 'yes' !== get_option( 'tejcart_guest_checkout', 'yes' ) ) {
    $tejcart_block_reason   = __( 'Please sign in or create an account to place an order.', 'tejcart' );
    $tejcart_block_redirect = wp_login_url( get_permalink() );
} else {
    $tejcart_subtotal = (float) ( method_exists( $cart, 'get_subtotal' ) ? $cart->get_subtotal() : 0 );
    $tejcart_min      = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
    $tejcart_max      = (float) get_option( 'tejcart_cart_maximum_amount', 0 );
    if ( $tejcart_min > 0 && $tejcart_subtotal < $tejcart_min ) {
        $tejcart_block_reason = sprintf(
            /* translators: %s: formatted minimum order amount */
            __( 'A minimum order of %s is required to checkout. Please add more items to your cart.', 'tejcart' ),
            function_exists( 'tejcart_price' ) ? tejcart_price( $tejcart_min ) : (string) $tejcart_min
        );
    } elseif ( $tejcart_max > 0 && $tejcart_subtotal > $tejcart_max ) {
        $tejcart_block_reason = sprintf(
            /* translators: %s: formatted maximum order amount */
            __( 'Orders cannot exceed %s. Please remove items from your cart before checking out.', 'tejcart' ),
            function_exists( 'tejcart_price' ) ? tejcart_price( $tejcart_max ) : (string) $tejcart_max
        );
    }
}
?>

<a class="tejcart-skip-link screen-reader-text" href="#tejcart-checkout-main">
    <?php esc_html_e( 'Skip to checkout form', 'tejcart' ); ?>
</a>

<section id="tejcart-checkout-main" class="tejcart-checkout">

    <?php
    /*
     * Audit #91 / 09 F-012 — explicit <h1> on the checkout page so
     * the heading hierarchy never starts at <h2>, particularly under
     * the `tejcart-minimal-chrome` body class which can strip the
     * theme's page title. Replaces the earlier aria-label on the
     * section.
     */
    ?>
    <h1 class="tejcart-sr-only"><?php esc_html_e( 'Checkout', 'tejcart' ); ?></h1>

    <?php

    ?>
    <div
        class="tejcart-checkout-notices"
        data-tejcart-checkout-notices
        role="region"
        aria-live="polite"
        aria-label="<?php esc_attr_e( 'Checkout notifications', 'tejcart' ); ?>"
    ></div>

    <?php if ( '' !== $tejcart_block_reason ) : ?>
        <div class="tejcart-notice tejcart-notice--error tejcart-notice--banner tejcart-checkout-block" role="alert">
            <span class="tejcart-notice-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><line x1="10" y1="6" x2="10" y2="11"/><line x1="10" y1="14" x2="10" y2="14.01"/></svg>
            </span>
            <div class="tejcart-notice-body">
                <p><?php echo esc_html( $tejcart_block_reason ); ?></p>
                <?php if ( '' !== $tejcart_block_redirect ) : ?>
                    <p>
                        <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $tejcart_block_redirect ); ?>">
                            <?php esc_html_e( 'Sign in to continue', 'tejcart' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php

        do_action( 'tejcart_after_checkout_form', $cart );
        return;
    endif; ?>

    <?php  ?>
    <div class="tejcart-checkout-order-summary-mobile" data-tejcart-summary-mobile>
        <button
            type="button"
            class="tejcart-checkout-order-summary-toggle"
            aria-expanded="false"
            aria-controls="tejcart-checkout-summary-body"
        >
            <span class="tejcart-checkout-order-summary-toggle-label">
                <span class="tejcart-checkout-order-summary-toggle-text">
                    <?php esc_html_e( 'View order summary', 'tejcart' ); ?>
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </button>
        <div class="tejcart-checkout-order-summary-body" id="tejcart-checkout-summary-body">
            <div class="tejcart-checkout-order-summary-content" data-tejcart-summary>
                <?php
                $tejcart_summary_context = 'mobile';
                include __DIR__ . '/_order-summary.php';
                ?>
            </div>
        </div>
    </div>

    <form id="tejcart-checkout-form" class="tejcart-checkout-form" action="" method="post" novalidate>

        <?php wp_nonce_field( 'tejcart_process_checkout', 'tejcart_checkout_nonce' ); ?>
        <?php

        $tejcart_render_cart = tejcart_get_cart();
        if ( $tejcart_render_cart && method_exists( $tejcart_render_cart, 'get_totals_hash' ) ) :
            ?>
            <input type="hidden" name="tejcart_cart_totals_hash" value="<?php echo esc_attr( $tejcart_render_cart->get_totals_hash() ); ?>" />
        <?php endif; ?>

        <div class="tejcart-checkout-columns">

            <!-- LEFT COLUMN: form fields -->
            <div class="tejcart-checkout-col-fields">

                <?php if ( $tejcart_show_express ) : ?>
                    <div class="tejcart-express-checkout tejcart-express-checkout--top">
                        <!-- F-FE-002: role=separator is structural/presentational and must not carry
                             meaningful text. Use role=heading (aria-level matches visual hierarchy). -->
                        <div class="tejcart-express-checkout-header" role="heading" aria-level="3">
                            <span class="tejcart-express-checkout-title">
                                <?php esc_html_e( 'Express checkout', 'tejcart' ); ?>
                            </span>
                        </div>

                        <div class="tejcart-express-checkout-buttons" data-tejcart-express-checkout="checkout">
                            <div class="tejcart-express-checkout-skeleton" aria-hidden="true"></div>
                            <div id="tejcart-express-paypal"></div>
                            <?php if ( $tejcart_show_express_vm ) : ?>
                                <div id="tejcart-express-venmo"></div>
                            <?php endif; ?>
                            <?php if ( $tejcart_show_express_gp ) : ?>
                                <div id="tejcart-express-googlepay"></div>
                            <?php endif; ?>
                            <?php if ( $tejcart_show_express_ap ) : ?>
                                <div id="tejcart-express-applepay"></div>
                            <?php endif; ?>
                        </div>

                        <?php if ( $tejcart_show_express_pl ) : ?>
                            <paypal-message class="tejcart-paylater-message tejcart-paylater-express"
                                 amount="<?php echo esc_attr( $tejcart_total ); ?>"
                                 currency-code="<?php echo esc_attr( tejcart_get_currency() ); ?>"
                                 data-pp-placement="payment"
                                 data-pp-style-layout="<?php echo esc_attr( $tejcart_pl_layout ); ?>"
                                 data-pp-style-logo-type="<?php echo esc_attr( $tejcart_pl_logo_type ); ?>"
                                 data-pp-style-text-color="<?php echo esc_attr( $tejcart_pl_text_color ); ?>"
                                 data-pp-style-text-size="12"
                                 data-pp-style-text-align="left">
                            </paypal-message>
                        <?php endif; ?>
                    </div>

                    <!-- F-FE-002: role=separator must not contain visible meaningful text.
                         Use role=presentation with a screen-reader alternative. -->
                    <div class="tejcart-express-divider" role="presentation">
                        <span aria-hidden="true"><?php esc_html_e( 'Or', 'tejcart' ); ?></span>
                        <span class="tejcart-sr-only"><?php esc_html_e( 'Or fill in your details below', 'tejcart' ); ?></span>
                    </div>
                <?php endif; ?>

                <?php
                /**
                 * Fires after the express checkout buttons.
                 */
                do_action( 'tejcart_after_express_checkout' );
                ?>

                <?php

                $tejcart_needs_shipping = $cart->needs_shipping();

                $billing_fields = $checkout_fields->get_billing_fields();
                /** This filter is documented in the legacy template. */
                $billing_fields = apply_filters( 'tejcart_checkout_billing_fields', $billing_fields );

                $tejcart_contact_field_keys  = array( 'billing_email' );
                $tejcart_contact_fields      = array();
                $tejcart_billing_fields      = array();
                foreach ( $billing_fields as $tejcart_bf_key => $tejcart_bf_field ) {
                    if ( in_array( $tejcart_bf_key, $tejcart_contact_field_keys, true ) ) {
                        $tejcart_contact_fields[ $tejcart_bf_key ] = $tejcart_bf_field;
                    } else {
                        $tejcart_billing_fields[ $tejcart_bf_key ] = $tejcart_bf_field;
                    }
                }

                $tejcart_shipping_fields = array();
                if ( $tejcart_needs_shipping ) {
                    $tejcart_shipping_fields = $checkout_fields->get_shipping_fields();
                    /** This filter is documented in the legacy template. */
                    $tejcart_shipping_fields = apply_filters( 'tejcart_checkout_shipping_fields', $tejcart_shipping_fields );
                }

                $additional_fields = $checkout_fields->get_additional_fields();
                /** This filter is documented in the legacy template. */
                $additional_fields = apply_filters( 'tejcart_checkout_additional_fields', $additional_fields );

                $tejcart_notes_fields   = array();
                $tejcart_account_fields = array();
                $tejcart_terms_fields   = array();
                foreach ( $additional_fields as $tejcart_af_key => $tejcart_af_field ) {
                    $tejcart_af_section = isset( $tejcart_af_field['section'] ) ? (string) $tejcart_af_field['section'] : '';
                    if ( 'additional' === $tejcart_af_section ) {
                        $tejcart_notes_fields[ $tejcart_af_key ] = $tejcart_af_field;
                    } elseif ( 'account' === $tejcart_af_section ) {
                        $tejcart_account_fields[ $tejcart_af_key ] = $tejcart_af_field;
                    } else {
                        $tejcart_terms_fields[ $tejcart_af_key ] = $tejcart_af_field;
                    }
                }
                ?>

                <section class="tejcart-checkout-section tejcart-checkout-contact" aria-labelledby="tejcart-contact-heading" data-tejcart-section="contact" data-tejcart-address-scope="billing" data-tejcart-step="1">
                    <h2 class="tejcart-checkout-section-heading" id="tejcart-contact-heading">
                        <?php esc_html_e( 'Contact', 'tejcart' ); ?>
                    </h2>
                    <?php
                    $fields = $tejcart_contact_fields;
                    include __DIR__ . '/checkout-fields.php';
                    ?>

                    <?php if ( ! empty( $tejcart_account_fields ) ) : ?>
                        <?php

                        $tejcart_email_value     = isset( $tejcart_contact_fields['billing_email']['value'] )
                            ? (string) $tejcart_contact_fields['billing_email']['value']
                            : '';
                        $tejcart_account_revealed = '' !== trim( $tejcart_email_value );
                        ?>
                        <div
                            class="tejcart-checkout-account tejcart-checkout-account--inline<?php echo $tejcart_account_revealed ? ' is-revealed' : ''; ?>"
                            data-tejcart-account
                            data-tejcart-account-disclosure="billing_email"
                            <?php echo $tejcart_account_revealed ? '' : 'hidden'; ?>
                        >
                            <?php

                            $fields = $tejcart_account_fields;
                            include __DIR__ . '/checkout-fields.php';
                            ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ( $tejcart_needs_shipping ) : ?>
                <section class="tejcart-checkout-section tejcart-checkout-shipping tejcart-checkout-delivery" aria-labelledby="tejcart-delivery-heading" data-tejcart-section="shipping" data-tejcart-address-scope="shipping" data-tejcart-step="2">
                    <h2 class="tejcart-checkout-section-heading" id="tejcart-delivery-heading">
                        <?php esc_html_e( 'Shipping address', 'tejcart' ); ?>
                    </h2>
                    <div class="tejcart-shipping-fields" data-tejcart-shipping-fields data-tejcart-address-scope="shipping">
                        <?php
                        $fields = $tejcart_shipping_fields;
                        include __DIR__ . '/checkout-fields.php';
                        ?>
                    </div>

                    <div class="tejcart-checkout-billing-inline" data-tejcart-billing-toggle>
                        <input type="hidden" name="tejcart_billing_different" value="0" />
                        <label class="tejcart-field-checkbox tejcart-checkout-billing-different">
                            <input type="checkbox" id="tejcart-billing-different" name="tejcart_billing_different" value="1" />
                            <span><?php esc_html_e( 'Use a different billing address', 'tejcart' ); ?></span>
                        </label>
                        <div class="tejcart-billing-fields" data-tejcart-billing-fields data-tejcart-address-scope="billing" hidden>
                            <h3 class="tejcart-checkout-billing-inline-heading">
                                <?php esc_html_e( 'Billing address', 'tejcart' ); ?>
                            </h3>
                            <?php
                            $fields = $tejcart_billing_fields;
                            include __DIR__ . '/checkout-fields.php';
                            ?>
                        </div>
                    </div>

                    <?php if ( ! empty( $tejcart_notes_fields ) ) : ?>
                        <div class="tejcart-checkout-notes tejcart-checkout-notes--inline" data-tejcart-notes>
                            <button
                                type="button"
                                class="tejcart-checkout-notes-toggle"
                                aria-expanded="false"
                                aria-controls="tejcart-checkout-notes-body-shipping"
                            >
                                <?php esc_html_e( 'Add a note for the seller', 'tejcart' ); ?>
                            </button>
                            <div class="tejcart-checkout-notes-body" id="tejcart-checkout-notes-body-shipping" hidden>
                                <?php
                                $fields = $tejcart_notes_fields;
                                include __DIR__ . '/checkout-fields.php';
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php else : ?>
                <section class="tejcart-checkout-section tejcart-checkout-billing" aria-labelledby="tejcart-billing-heading" data-tejcart-section="billing" data-tejcart-address-scope="billing" data-tejcart-step="2">
                    <h2 class="tejcart-checkout-section-heading" id="tejcart-billing-heading">
                        <?php esc_html_e( 'Billing address', 'tejcart' ); ?>
                    </h2>
                    <?php
                    $fields = $tejcart_billing_fields;
                    include __DIR__ . '/checkout-fields.php';
                    ?>

                    <?php if ( ! empty( $tejcart_notes_fields ) ) : ?>
                        <div class="tejcart-checkout-notes tejcart-checkout-notes--inline" data-tejcart-notes>
                            <button
                                type="button"
                                class="tejcart-checkout-notes-toggle"
                                aria-expanded="false"
                                aria-controls="tejcart-checkout-notes-body-billing"
                            >
                                <?php esc_html_e( 'Add a note for the seller', 'tejcart' ); ?>
                            </button>
                            <div class="tejcart-checkout-notes-body" id="tejcart-checkout-notes-body-billing" hidden>
                                <?php
                                $fields = $tejcart_notes_fields;
                                include __DIR__ . '/checkout-fields.php';
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ( $cart->needs_shipping() && class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) :
                    $tejcart_shipping_manager = new \TejCart\Shipping\Shipping_Manager();
                    $tejcart_country          = '';
                    $tejcart_state            = '';
                    $tejcart_postcode         = '';
                    if ( method_exists( $cart, 'get_customer' ) ) {
                        $tejcart_customer = $cart->get_customer();
                        if ( is_object( $tejcart_customer ) ) {
                            if ( method_exists( $tejcart_customer, 'get_shipping_country' ) ) {
                                $tejcart_country = (string) $tejcart_customer->get_shipping_country();
                            }
                            if ( method_exists( $tejcart_customer, 'get_shipping_state' ) ) {
                                $tejcart_state = (string) $tejcart_customer->get_shipping_state();
                            }
                            if ( method_exists( $tejcart_customer, 'get_shipping_postcode' ) ) {
                                $tejcart_postcode = (string) $tejcart_customer->get_shipping_postcode();
                            }
                        }
                    }

                    $has_address = ( '' !== $tejcart_state ) || ( '' !== $tejcart_postcode );
                    if ( '' === $tejcart_country ) {
                        $tejcart_country = (string) get_option( 'tejcart_store_country', 'US' );
                    }
                    $methods = $tejcart_shipping_manager->get_available_methods( $tejcart_country, $tejcart_state, $cart );
                    $chosen  = $cart->get_chosen_shipping_method();
                    ?>
                    <section class="tejcart-checkout-section tejcart-checkout-shipping-methods" aria-labelledby="tejcart-shipping-method-heading" data-tejcart-section="shipping" data-tejcart-step="3">
                        <h2 class="tejcart-checkout-section-heading" id="tejcart-shipping-method-heading">
                            <?php esc_html_e( 'Shipping method', 'tejcart' ); ?>
                        </h2>
                        <?php  ?>
                        <div class="tejcart-shipping-methods-wrap" data-tejcart-shipping-methods>
                            <?php include __DIR__ . '/_shipping-methods.php'; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tejcart-checkout-section tejcart-checkout-payment" aria-labelledby="tejcart-payment-heading" data-tejcart-section="payment" data-tejcart-step="<?php echo $cart->needs_shipping() ? '4' : '3'; ?>">
                    <h2 class="tejcart-checkout-section-heading" id="tejcart-payment-heading">
                        <?php esc_html_e( 'Payment', 'tejcart' ); ?>
                    </h2>

                    <?php
                    /**
                     * Reassurance line rendered directly under the Payment
                     * heading — the moment a buyer's anxiety peaks. Shopify
                     * places identical copy here ("All transactions are
                     * secure and encrypted"). Return an empty string to
                     * hide it entirely.
                     *
                     * @param string $text Security reassurance copy.
                     */
                    $tejcart_payment_security_note = (string) apply_filters(
                        'tejcart_checkout_payment_security_note',
                        __( 'All transactions are secure and encrypted.', 'tejcart' )
                    );
                    ?>
                    <?php if ( '' !== $tejcart_payment_security_note ) : ?>
                        <p class="tejcart-checkout-payment-security">
                            <span class="tejcart-payment-security-lock" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="8" height="6" rx="1"/><path d="M5 6V4a2 2 0 0 1 4 0v2"/></svg>
                            </span>
                            <?php echo esc_html( $tejcart_payment_security_note ); ?>
                        </p>
                    <?php endif; ?>

                    <?php

                    $gateways = $available_gateways;
                    include __DIR__ . '/payment-methods.php';
                    ?>
                </section>

                <div class="tejcart-checkout-place-order">
                    <?php if ( ! empty( $tejcart_terms_fields ) ) : ?>
                        <div class="tejcart-checkout-terms" data-tejcart-terms>
                            <?php
                            $fields = $tejcart_terms_fields;
                            include __DIR__ . '/checkout-fields.php';
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    /** This action is documented in the legacy template. */
                    do_action( 'tejcart_before_place_order_button' );
                    ?>

                    <button
                        type="submit"
                        class="tejcart-button tejcart-button--block tejcart-button--lg tejcart-place-order-btn"
                        name="tejcart_place_order"
                        value="1"
                        data-default-label="<?php echo esc_attr( apply_filters( 'tejcart_place_order_button_text', __( 'Complete order', 'tejcart' ) ) ); ?>"
                        data-loading-label="<?php esc_attr_e( 'Processing…', 'tejcart' ); ?>"
                    >
                        <span class="tejcart-place-order-label">
                            <?php echo esc_html( apply_filters( 'tejcart_place_order_button_text', __( 'Complete order', 'tejcart' ) ) ); ?>
                        </span>
                        <span class="tejcart-place-order-amount tejcart-total-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( $tejcart_total ) ); ?></span>
                    </button>

                    <?php
                    /** This action is documented in the legacy template. */
                    do_action( 'tejcart_after_place_order_button' );
                    ?>

                    <?php

                    $tejcart_trust_fragments = array(
                        'ssl'      => __( 'SSL secured', 'tejcart' ),
                        'returns'  => __( '30-day returns', 'tejcart' ),
                    );

                    /**
                     * Filter the trust-footer fragments rendered under
                     * the Place Order button. Keys are arbitrary; the
                     * order of the array determines the rendered order.
                     * Return an empty array to hide the footer entirely.
                     *
                     * @param array $fragments       Trust copy fragments.
                     * @param array $available_gateways Active gateways.
                     */
                    $tejcart_trust_fragments = (array) apply_filters( 'tejcart_checkout_trust_footer', $tejcart_trust_fragments, $available_gateways );
                    ?>

                    <?php if ( ! empty( $tejcart_trust_fragments ) ) : ?>
                        <div class="tejcart-checkout-trust tejcart-checkout-trust--inline">
                            <span class="tejcart-trust-lock" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="8" height="6" rx="1"/><path d="M5 6V4a2 2 0 0 1 4 0v2"/></svg>
                            </span>
                            <?php
                            $tejcart_trust_first = true;
                            foreach ( $tejcart_trust_fragments as $tejcart_trust_key => $tejcart_trust_text ) :
                                $tejcart_trust_text = (string) $tejcart_trust_text;
                                if ( '' === $tejcart_trust_text ) { continue; }
                                if ( ! $tejcart_trust_first ) :
                                    ?>
                                    <span class="tejcart-trust-sep" aria-hidden="true">·</span>
                                    <?php
                                endif;
                                ?>
                                <span class="tejcart-trust-fragment tejcart-trust-fragment--<?php echo esc_attr( sanitize_html_class( (string) $tejcart_trust_key ) ); ?>">
                                    <?php echo esc_html( $tejcart_trust_text ); ?>
                                </span>
                                <?php
                                $tejcart_trust_first = false;
                            endforeach;
                            ?>
                        </div>

                        <div class="tejcart-checkout-trust-links">
                            <?php
                            $tejcart_privacy = (int) get_option( 'wp_page_for_privacy_policy', 0 );
                            if ( $tejcart_privacy > 0 ) :
                                ?>
                                <a href="<?php echo esc_url( get_permalink( $tejcart_privacy ) ); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e( 'Privacy', 'tejcart' ); ?>
                                </a>
                            <?php endif; ?>
                            <?php
                            $tejcart_terms_id = (int) get_option( 'tejcart_terms_page_id', 0 );
                            if ( $tejcart_terms_id > 0 ) :
                                ?>
                                <a href="<?php echo esc_url( get_permalink( $tejcart_terms_id ) ); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e( 'Terms', 'tejcart' ); ?>
                                </a>
                            <?php endif; ?>
                            <?php
                            $tejcart_refund_id = (int) get_option( 'tejcart_refund_page_id', 0 );
                            if ( $tejcart_refund_id > 0 ) :
                                ?>
                                <a href="<?php echo esc_url( get_permalink( $tejcart_refund_id ) ); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e( 'Refunds', 'tejcart' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- RIGHT COLUMN: order summary (sticky on desktop, hidden on mobile) -->
            <aside class="tejcart-checkout-col-review" aria-labelledby="tejcart-checkout-review-title">
                <div class="tejcart-checkout-order-review">
                    <h2 class="tejcart-checkout-section-heading tejcart-checkout-order-review-heading" id="tejcart-checkout-review-title">
                        <?php esc_html_e( 'Order summary', 'tejcart' ); ?>
                    </h2>
                    <div class="tejcart-checkout-order-summary-content" data-tejcart-summary>
                        <?php
                        $tejcart_summary_context = 'desktop';
                        include __DIR__ . '/_order-summary.php';
                        ?>
                    </div>
                </div>
            </aside>

        </div>

        <?php  ?>
        <?php
        $tejcart_sticky_continue_label = apply_filters( 'tejcart_sticky_continue_button_text', __( 'Continue', 'tejcart' ) );
        $tejcart_sticky_pay_label      = apply_filters( 'tejcart_sticky_pay_button_text', __( 'Pay', 'tejcart' ) );
        ?>
        <div
            class="tejcart-checkout-place-order-sticky"
            data-tejcart-sticky-place-order
            aria-hidden="true"
        >
            <span class="tejcart-checkout-place-order-sticky-total tejcart-total-value" aria-hidden="true">
                <?php echo wp_kses_post( tejcart_price( $tejcart_total ) ); ?>
            </span>
            <button
                type="submit"
                class="tejcart-button tejcart-button--lg tejcart-place-order-btn tejcart-place-order-btn--sticky is-pending"
                name="tejcart_place_order"
                value="1"
                tabindex="-1"
                data-tejcart-sticky-button
                data-continue-label="<?php echo esc_attr( $tejcart_sticky_continue_label ); ?>"
                data-pay-label="<?php echo esc_attr( $tejcart_sticky_pay_label ); ?>"
                aria-describedby="tejcart-sticky-cta-hint"
            >
                <span class="tejcart-place-order-lock" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="8" height="6" rx="1"/><path d="M5 6V4a2 2 0 0 1 4 0v2"/></svg>
                </span>
                <span class="tejcart-place-order-label">
                    <?php echo esc_html( $tejcart_sticky_continue_label ); ?>
                </span>
            </button>
            <span id="tejcart-sticky-cta-hint" class="tejcart-sr-only">
                <?php esc_html_e( 'Continues to the next checkout step. Becomes Pay when the form is complete.', 'tejcart' ); ?>
            </span>
        </div>

    </form>

</section>

<?php
/**
 * Fires after the checkout form.
 *
 * @param \TejCart\Cart\Cart $cart The cart instance.
 */
do_action( 'tejcart_after_checkout_form', $cart );
