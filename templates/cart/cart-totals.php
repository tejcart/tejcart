<?php
/**
 * Cart totals sidebar template.
 *
 * Renders the order summary card: subtotal → shipping → taxes → discount
 * → total, followed by a collapsible coupon form, the primary checkout
 * button, express-checkout buttons, and an inline trust footer.
 *
 * @package TejCart\Templates\Cart
 *
 * @var \TejCart\Cart\Cart $cart The cart instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$subtotal       = (float) $cart->get_subtotal();
$shipping_total = (float) $cart->get_shipping_total();
$tax_total      = (float) $cart->get_tax_total();
$discount_total = (float) $cart->get_discount_total();
$total          = (float) $cart->get_total();
$needs_shipping = $cart->needs_shipping();
$coupons        = $cart->get_coupons();

$tejcart_paypal_gateway  = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_paypal' ) : null;
$tejcart_paypal_ready    = $tejcart_paypal_gateway && $tejcart_paypal_gateway->is_available();

// Hide express buttons when the cart is below the configured minimum order
// amount; PayPal_AJAX::create_express_order() would reject the click anyway.
$tejcart_cart_min_amount   = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
$tejcart_meets_cart_min    = $tejcart_cart_min_amount <= 0 || $subtotal >= $tejcart_cart_min_amount;

$tejcart_show_cart_button = $tejcart_paypal_ready
    && $tejcart_meets_cart_min
    && 'yes' === $tejcart_paypal_gateway->get_option( 'button_cart_page', 'yes' );
$tejcart_show_cart_venmo = $tejcart_show_cart_button
    && 'yes' === $tejcart_paypal_gateway->get_option( 'enable_venmo', 'yes' );
$tejcart_show_cart_paylater = $tejcart_paypal_ready
    && 'yes' === $tejcart_paypal_gateway->get_option( 'enable_paylater', 'yes' )
    && 'yes' === $tejcart_paypal_gateway->get_option( 'paylater_cart_page', 'yes' );
$tejcart_show_cart_gp = $tejcart_show_cart_button
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_googlepay' );
$tejcart_show_cart_ap = $tejcart_show_cart_button
    && \TejCart\Gateways\PayPal\PayPal_Gateway::is_sibling_gateway_enabled( 'tejcart_applepay' );

$tejcart_pl_layout     = $tejcart_show_cart_paylater
    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_layout', 'text' )
    : 'text';
$tejcart_pl_logo_type  = $tejcart_show_cart_paylater
    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_logo_type', 'primary' )
    : 'primary';
$tejcart_pl_text_color = $tejcart_show_cart_paylater
    ? (string) $tejcart_paypal_gateway->get_option( 'paylater_style_text_color', 'black' )
    : 'black';

/**
 * Free-shipping progress threshold (in store currency, 0 disables the bar).
 *
 * Mirrors the cart drawer's filter so a single setting drives both surfaces.
 *
 * @param float $threshold Amount in store currency.
 */
$tejcart_free_ship_threshold = (float) apply_filters( 'tejcart_free_shipping_threshold', 0 );
$tejcart_free_ship_enabled   = $tejcart_free_ship_threshold > 0;
// Eligibility mirrors Cart_Calculator::calculate_shipping(): post-discount
// subtotal, never the grand total (which already includes shipping/tax).
$tejcart_free_ship_eligible  = max( 0.0, $subtotal - $discount_total );
$tejcart_free_ship_remaining = $tejcart_free_ship_enabled
    ? max( 0, $tejcart_free_ship_threshold - $tejcart_free_ship_eligible )
    : 0;
$tejcart_free_ship_percent   = $tejcart_free_ship_enabled
    ? min( 100, ( $tejcart_free_ship_eligible / $tejcart_free_ship_threshold ) * 100 )
    : 0;
?>

<div class="tejcart-cart-totals" role="region" aria-labelledby="tejcart-cart-summary-title">

    <h2 class="tejcart-cart-totals-heading" id="tejcart-cart-summary-title">
        <?php esc_html_e( 'Order summary', 'tejcart' ); ?>
    </h2>

    <?php if ( $tejcart_free_ship_enabled ) : ?>
        <div class="tejcart-cart-shipping-bar <?php echo $tejcart_free_ship_remaining <= 0 ? 'is-unlocked' : ''; ?>"
             role="status"
             aria-live="polite"
             data-tejcart-free-ship-bar
             data-threshold="<?php echo esc_attr( $tejcart_free_ship_threshold ); ?>">
            <p class="tejcart-cart-shipping-bar-text">
                <span class="tejcart-cart-shipping-bar-icon" aria-hidden="true">
                    <?php if ( $tejcart_free_ship_remaining <= 0 ) : ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php else : ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M2 5h10v8H2zM12 8h4l2 3v2h-6zM6 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM15 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    <?php endif; ?>
                </span>
                <span class="tejcart-cart-shipping-bar-msg" data-tejcart-free-ship-msg>
                    <?php if ( $tejcart_free_ship_remaining <= 0 ) : ?>
                        <?php esc_html_e( "You've unlocked free shipping!", 'tejcart' ); ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: formatted amount remaining */
                            wp_kses_post( __( 'Add <strong>%s</strong> more for free shipping', 'tejcart' ) ),
                            wp_kses_post( tejcart_price( $tejcart_free_ship_remaining ) )
                        );
                        ?>
                    <?php endif; ?>
                </span>
            </p>
            <div class="tejcart-cart-shipping-bar-track" aria-hidden="true">
                <span class="tejcart-cart-shipping-bar-fill"
                      data-tejcart-free-ship-fill
                      style="width: <?php echo esc_attr( $tejcart_free_ship_percent ); ?>%;"></span>
            </div>
        </div>
    <?php endif; ?>

    <?php
    /**
     * Fires before the cart totals list.
     *
     * @param \TejCart\Cart\Cart $cart The cart instance.
     */
    do_action( 'tejcart_before_cart_totals', $cart );
    ?>

    <dl class="tejcart-cart-totals-list">

        <div class="tejcart-cart-totals-row tejcart-cart-totals-subtotal">
            <dt><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></dt>
            <dd class="tejcart-cart-totals-row-value tejcart-subtotal-value"><?php echo wp_kses_post( tejcart_price( $subtotal ) ); ?></dd>
        </div>

        <?php

        ?>
        <div class="tejcart-cart-totals-row tejcart-cart-totals-shipping"<?php echo $needs_shipping ? '' : ' hidden'; ?>>
            <dt><?php esc_html_e( 'Shipping', 'tejcart' ); ?></dt>
            <dd class="tejcart-cart-totals-row-value tejcart-shipping-value" aria-live="polite" aria-atomic="true">
                <?php
                if ( $shipping_total > 0 ) {
                    echo wp_kses_post( tejcart_price( $shipping_total ) );
                } else {
                    esc_html_e( 'Calculated at checkout', 'tejcart' );
                }
                ?>
            </dd>
        </div>

        <?php
        /*
         * Audit #98 / 01 #8 — in-cart shipping calculator widget.
         * Renders a country/state/postcode tri-field that asks the
         * server for available shipping rates for the current cart.
         * Hidden on shipping-free carts (digital-only, virtual, …).
         */
        if ( $needs_shipping ) :
            $tejcart_shipping_calc_open = false;
            $tejcart_cart_destination   = method_exists( $cart, 'get_shipping_destination' ) ? (array) $cart->get_shipping_destination() : array();
            $tejcart_calc_country        = isset( $tejcart_cart_destination['country'] ) ? (string) $tejcart_cart_destination['country'] : '';
            $tejcart_calc_state          = isset( $tejcart_cart_destination['state'] ) ? (string) $tejcart_cart_destination['state'] : '';
            $tejcart_calc_postcode       = isset( $tejcart_cart_destination['postcode'] ) ? (string) $tejcart_cart_destination['postcode'] : '';
            if ( '' === $tejcart_calc_country ) {
                $tejcart_calc_country = (string) get_option( 'tejcart_store_country', 'US' );
            }
            $tejcart_calc_countries = class_exists( '\\TejCart\\Tax\\Tax_Manager' )
                ? (array) \TejCart\Tax\Tax_Manager::get_countries()
                : array( $tejcart_calc_country => $tejcart_calc_country );
            ?>
            <div class="tejcart-shipping-calculator" data-tejcart-shipping-calculator>
                <button
                    type="button"
                    class="tejcart-shipping-calculator__toggle"
                    aria-expanded="<?php echo $tejcart_shipping_calc_open ? 'true' : 'false'; ?>"
                    aria-controls="tejcart-shipping-calculator-form"
                    data-tejcart-shipping-calculator-toggle
                >
                    <span class="tejcart-shipping-calculator__toggle-label">
                        <svg class="tejcart-shipping-calculator__toggle-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M2 5h10v8H2zM12 8h4l2 3v2h-6zM6 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM15 16a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                        <?php esc_html_e( 'Estimate shipping', 'tejcart' ); ?>
                    </span>
                    <svg class="tejcart-shipping-calculator__toggle-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div
                    class="tejcart-shipping-calculator__form"
                    id="tejcart-shipping-calculator-form"
                    <?php echo $tejcart_shipping_calc_open ? '' : 'hidden'; ?>
                    data-tejcart-shipping-calculator-form
                >
                    <div class="tejcart-shipping-calculator__field">
                        <label class="tejcart-sr-only" for="tejcart-shipping-calc-country">
                            <?php esc_html_e( 'Country', 'tejcart' ); ?>
                        </label>
                        <select
                            id="tejcart-shipping-calc-country"
                            name="tejcart_calc_country"
                            class="tejcart-field-input"
                            data-tejcart-calc-country
                        >
                            <?php foreach ( $tejcart_calc_countries as $tejcart_calc_code => $tejcart_calc_name ) : ?>
                                <option value="<?php echo esc_attr( (string) $tejcart_calc_code ); ?>"<?php selected( $tejcart_calc_country, $tejcart_calc_code ); ?>>
                                    <?php echo esc_html( (string) $tejcart_calc_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tejcart-shipping-calculator__field">
                        <label class="tejcart-sr-only" for="tejcart-shipping-calc-state">
                            <?php esc_html_e( 'State / Province', 'tejcart' ); ?>
                        </label>
                        <input
                            type="text"
                            id="tejcart-shipping-calc-state"
                            name="tejcart_calc_state"
                            class="tejcart-field-input"
                            placeholder="<?php esc_attr_e( 'State', 'tejcart' ); ?>"
                            autocomplete="address-level1"
                            maxlength="32"
                            value="<?php echo esc_attr( $tejcart_calc_state ); ?>"
                            data-tejcart-calc-state
                        />
                    </div>
                    <div class="tejcart-shipping-calculator__field">
                        <label class="tejcart-sr-only" for="tejcart-shipping-calc-postcode">
                            <?php esc_html_e( 'Postcode / ZIP', 'tejcart' ); ?>
                        </label>
                        <input
                            type="text"
                            id="tejcart-shipping-calc-postcode"
                            name="tejcart_calc_postcode"
                            class="tejcart-field-input"
                            placeholder="<?php esc_attr_e( 'Postcode', 'tejcart' ); ?>"
                            autocomplete="postal-code"
                            maxlength="32"
                            value="<?php echo esc_attr( $tejcart_calc_postcode ); ?>"
                            data-tejcart-calc-postcode
                        />
                    </div>
                    <div class="tejcart-shipping-calculator__actions">
                        <button
                            type="button"
                            class="tejcart-button tejcart-button--secondary"
                            data-tejcart-calc-submit
                        >
                            <?php esc_html_e( 'Calculate', 'tejcart' ); ?>
                        </button>
                    </div>
                    <p
                        class="tejcart-shipping-calculator__feedback"
                        data-tejcart-calc-feedback
                        role="status"
                        aria-live="polite"
                    ></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="tejcart-cart-totals-row tejcart-cart-totals-tax"<?php echo $tax_total > 0 ? '' : ' hidden'; ?>>
            <dt><?php esc_html_e( 'Taxes', 'tejcart' ); ?></dt>
            <dd class="tejcart-cart-totals-row-value tejcart-tax-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( $tax_total ) ); ?></dd>
        </div>

        <div class="tejcart-cart-totals-row tejcart-cart-totals-discount"<?php echo $discount_total > 0 ? '' : ' hidden'; ?>>
            <dt><?php esc_html_e( 'Discount', 'tejcart' ); ?></dt>
            <dd class="tejcart-cart-totals-row-value tejcart-discount-value" aria-live="polite" aria-atomic="true">−<?php echo wp_kses_post( tejcart_price( $discount_total ) ); ?></dd>
        </div>

        <?php
        // Cart-level fees (gift wrap, handling, …). Amounts are minor units in
        // the active currency (already converted in the cart pipeline);
        // tejcart_price_from_minor_units formats them without re-converting.
        $tejcart_fee_rows = method_exists( $cart, 'get_fees' ) ? (array) $cart->get_fees() : array();
        foreach ( $tejcart_fee_rows as $tejcart_fee_row ) :
            $tejcart_fee_amount = (int) ( $tejcart_fee_row['amount'] ?? 0 );
            if ( $tejcart_fee_amount <= 0 ) {
                continue;
            }
            $tejcart_fee_label = '' !== (string) ( $tejcart_fee_row['label'] ?? '' )
                ? (string) $tejcart_fee_row['label']
                : __( 'Fee', 'tejcart' );
            ?>
            <div class="tejcart-cart-totals-row tejcart-cart-totals-fee">
                <dt><?php echo esc_html( $tejcart_fee_label ); ?></dt>
                <dd class="tejcart-cart-totals-row-value"><?php echo wp_kses_post( tejcart_price_from_minor_units( $tejcart_fee_amount ) ); ?></dd>
            </div>
        <?php endforeach; ?>

        <div class="tejcart-cart-totals-row tejcart-cart-totals-grand">
            <dt><?php esc_html_e( 'Total', 'tejcart' ); ?></dt>
            <dd class="tejcart-cart-totals-row-value tejcart-total-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( $total ) ); ?></dd>
        </div>

    </dl>

    <?php

    ?>
    <p class="tejcart-cart-savings-line"<?php echo $discount_total > 0 ? '' : ' hidden'; ?> data-tejcart-savings-line>
        <span class="tejcart-cart-savings-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M4 10l4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <span>
            <?php

            printf(
                /* translators: %s: formatted savings amount */
                wp_kses_post( __( "You're saving <strong class=\"tejcart-cart-savings-amount\">%s</strong> today", 'tejcart' ) ),
                wp_kses_post( tejcart_price( $discount_total ) )
            );
            ?>
        </span>
    </p>

    <?php if ( ! empty( $coupons ) ) : ?>
        <?php foreach ( $coupons as $code => $coupon ) : ?>
            <div class="tejcart-coupon-applied" role="status">
                <span>
                    <?php
                    /* translators: %s: coupon code */
                    echo esc_html( __( 'Applied:', 'tejcart' ) );
                    ?>
                    <span class="tejcart-coupon-applied-code"><?php echo esc_html( strtoupper( $code ) ); ?></span>
                </span>
                <a
                    class="tejcart-coupon-remove"
                    href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'tejcart_remove_coupon', rawurlencode( $code ) ), 'tejcart_remove_coupon_' . $code, 'tejcart_coupon_nonce' ) ); ?>"
                    aria-label="<?php
                    /* translators: %s: coupon code (uppercase). */
                    echo esc_attr( sprintf( __( 'Remove coupon %s', 'tejcart' ), strtoupper( $code ) ) );
                    ?>"
                >
                    <?php esc_html_e( 'Remove', 'tejcart' ); ?>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="tejcart-coupon">
        <button type="button" class="tejcart-coupon-toggle" aria-expanded="false" aria-controls="tejcart-coupon-form">
            <span class="tejcart-coupon-toggle-label">
                <svg class="tejcart-coupon-toggle-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3 11l8 8 8-8L11 3H3v8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="7.5" cy="7.5" r="1" fill="currentColor"/></svg>
                <?php esc_html_e( 'Have a coupon code?', 'tejcart' ); ?>
            </span>
            <svg class="tejcart-coupon-toggle-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="tejcart-coupon-form" id="tejcart-coupon-form">
            <label class="tejcart-sr-only" for="tejcart_coupon_code"><?php esc_html_e( 'Coupon code', 'tejcart' ); ?></label>
            <input
                type="text"
                id="tejcart_coupon_code"
                name="tejcart_coupon_code"
                class="tejcart-field-input tejcart-coupon-input"
                autocomplete="off"
                value=""
                aria-describedby="tejcart-coupon-feedback"
            />
            <button type="submit" class="tejcart-button tejcart-button--secondary tejcart-apply-coupon-btn" name="tejcart_apply_coupon" value="1">
                <?php esc_html_e( 'Apply', 'tejcart' ); ?>
            </button>
        </div>
        <p
            class="tejcart-coupon-feedback"
            id="tejcart-coupon-feedback"
            data-tejcart-coupon-feedback
            role="status"
            aria-live="polite"
            hidden
        ></p>
    </div>

    <?php

    $tejcart_cart_min         = (float) get_option( 'tejcart_cart_minimum_amount', 0 );
    $tejcart_cart_max         = (float) get_option( 'tejcart_cart_maximum_amount', 0 );
    $tejcart_below_minimum    = $tejcart_cart_min > 0 && $subtotal < $tejcart_cart_min;
    $tejcart_above_maximum    = $tejcart_cart_max > 0 && $subtotal > $tejcart_cart_max;
    $tejcart_checkout_blocked = $tejcart_below_minimum || $tejcart_above_maximum;
    ?>
    <?php if ( $tejcart_below_minimum ) : ?>
        <p class="tejcart-cart-limit-notice tejcart-cart-limit-notice--min" role="status">
            <?php
            printf(
                /* translators: %s: minimum order amount with currency */
                esc_html__( 'Minimum order amount is %s.', 'tejcart' ),
                wp_kses_post( tejcart_price( $tejcart_cart_min ) )
            );
            ?>
        </p>
    <?php elseif ( $tejcart_above_maximum ) : ?>
        <p class="tejcart-cart-limit-notice tejcart-cart-limit-notice--max" role="status">
            <?php
            printf(
                /* translators: %s: maximum order amount with currency */
                esc_html__( 'Maximum order amount is %s. Please remove some items.', 'tejcart' ),
                wp_kses_post( tejcart_price( $tejcart_cart_max ) )
            );
            ?>
        </p>
    <?php endif; ?>

    <div class="tejcart-cart-cta-stack">
        <?php if ( $tejcart_checkout_blocked ) : ?>
            <button
                type="button"
                class="tejcart-button tejcart-button--block tejcart-button--lg tejcart-cart-checkout-btn"
                disabled
            >
                <span class="tejcart-cart-checkout-btn-label"><?php esc_html_e( 'Checkout', 'tejcart' ); ?></span>
                <span class="tejcart-cart-checkout-btn-total tejcart-total-value"><?php echo wp_kses_post( tejcart_price( $total ) ); ?></span>
            </button>
        <?php else : ?>
            <a
                class="tejcart-button tejcart-button--block tejcart-button--lg tejcart-cart-checkout-btn tejcart-checkout-btn"
                href="<?php echo esc_url( apply_filters( 'tejcart_checkout_url', home_url( '/checkout/' ) ) ); ?>"
            >
                <span class="tejcart-cart-checkout-btn-label"><?php esc_html_e( 'Checkout', 'tejcart' ); ?></span>
                <span class="tejcart-cart-checkout-btn-total tejcart-total-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( $total ) ); ?></span>
            </a>
        <?php endif; ?>

        <?php if ( $tejcart_show_cart_button ) : ?>
            <div class="tejcart-express-checkout tejcart-express-checkout--cart">
                <span class="tejcart-express-checkout-title tejcart-express-checkout-title--inline"><?php esc_html_e( 'Or pay with', 'tejcart' ); ?></span>
                <div class="tejcart-express-checkout-buttons tejcart-cart-express-zone tejcart-cart-smart-buttons">
                    <div class="tejcart-express-checkout-skeleton" aria-hidden="true"></div>
                    <div id="tejcart-cart-paypal-btn" class="tejcart-product-paypal-btn"></div>
                    <?php if ( $tejcart_show_cart_venmo ) : ?>
                        <div id="tejcart-cart-venmo-btn" class="tejcart-product-venmo-btn"></div>
                    <?php endif; ?>
                    <?php if ( $tejcart_show_cart_gp ) : ?>
                        <div id="tejcart-cart-googlepay-btn" class="tejcart-product-googlepay-btn"></div>
                    <?php endif; ?>
                    <?php if ( $tejcart_show_cart_ap ) : ?>
                        <div id="tejcart-cart-applepay-btn" class="tejcart-product-applepay-btn"></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( $tejcart_show_cart_paylater ) : ?>
        <paypal-message class="tejcart-paylater-message tejcart-paylater-cart"
             amount="<?php echo esc_attr( $total ); ?>"
             currency-code="<?php echo esc_attr( tejcart_get_currency() ); ?>"
             data-pp-placement="cart"
             data-pp-style-layout="<?php echo esc_attr( $tejcart_pl_layout ); ?>"
             data-pp-style-logo-type="<?php echo esc_attr( $tejcart_pl_logo_type ); ?>"
             data-pp-style-text-color="<?php echo esc_attr( $tejcart_pl_text_color ); ?>"
             data-pp-style-text-size="12"
             data-pp-style-text-align="left">
        </paypal-message>
    <?php endif; ?>

    <div class="tejcart-trust-row">
        <span class="tejcart-trust-lock">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="6" width="8" height="6" rx="1"/><path d="M5 6V4a2 2 0 0 1 4 0v2"/></svg>
            <?php esc_html_e( 'Secure payment processing', 'tejcart' ); ?>
        </span>
        <ul class="tejcart-trust-payments" aria-label="<?php esc_attr_e( 'Accepted payment methods', 'tejcart' ); ?>">
            <li class="tejcart-trust-payment" data-payment="visa">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" aria-hidden="true" focusable="false"><rect width="38" height="24" rx="3" fill="#1a1f71"/><path d="M16.8 15.4h-2.3l1.44-8.8h2.3l-1.44 8.8zm7.9-8.6c-.45-.17-1.17-.36-2.06-.36-2.27 0-3.87 1.18-3.88 2.87-.01 1.25 1.17 1.94 2.06 2.36.91.42 1.22.69 1.22 1.07-.01.57-.72.83-1.39.83-.92 0-1.42-.13-2.18-.46l-.3-.14-.33 1.95c.55.24 1.58.45 2.65.46 2.41 0 3.99-1.17 4.01-2.97.01-.99-.61-1.74-1.94-2.36-.81-.4-1.3-.67-1.3-1.08.01-.37.42-.76 1.33-.76.76-.01 1.3.15 1.73.32l.21.1.3-1.83zm3.7 5.47c.19-.49.92-2.43.92-2.43-.01.02.19-.5.31-.83l.16.75s.44 2.08.53 2.51h-1.92zm2.7-5.67h-1.78c-.55 0-.96.15-1.2.71l-3.42 8.09h2.41l.48-1.32h2.95c.07.31.28 1.32.28 1.32h2.13l-1.85-8.8zm-17.5 0-2.24 5.99-.24-1.2c-.42-1.39-1.72-2.89-3.17-3.64l2.05 7.65h2.43l3.61-8.8h-2.44z" fill="#fff"/></svg>
            </li>
            <li class="tejcart-trust-payment" data-payment="mastercard">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" aria-hidden="true" focusable="false"><rect width="38" height="24" rx="3" fill="#fff"/><rect width="38" height="24" rx="3" fill="none" stroke="#e1e3e5"/><circle cx="15" cy="12" r="6" fill="#eb001b"/><circle cx="23" cy="12" r="6" fill="#f79e1b"/><path d="M19 7.6a6 6 0 0 0 0 8.8 6 6 0 0 0 0-8.8z" fill="#ff5f00"/></svg>
            </li>
            <li class="tejcart-trust-payment" data-payment="amex">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" aria-hidden="true" focusable="false"><rect width="38" height="24" rx="3" fill="#1f72cd"/><text x="19" y="16" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="900" fill="#fff" text-anchor="middle" letter-spacing="0.6">AMEX</text></svg>
            </li>
            <li class="tejcart-trust-payment" data-payment="paypal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" aria-hidden="true" focusable="false"><rect width="38" height="24" rx="3" fill="#003087"/><text x="19" y="16" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="700" fill="#fff" text-anchor="middle" font-style="italic" letter-spacing="-0.2">PayPal</text></svg>
            </li>
        </ul>
    </div>

    <?php
    /**
     * Fires after the cart totals list.
     *
     * @param \TejCart\Cart\Cart $cart The cart instance.
     */
    do_action( 'tejcart_after_cart_totals', $cart );
    ?>

</div>
