<?php
/**
 * Checkout order summary partial.
 *
 * Renders the line items + totals block. Loaded twice from
 * checkout.php — once into the mobile accordion at the top of the
 * page, once into the desktop sticky sidebar. The mobile/desktop
 * difference is purely the wrapper, not the content, so the markup
 * lives in this single partial.
 *
 * @package TejCart\Templates\Checkout
 *
 * @var \TejCart\Cart\Cart $cart                      Cart instance.
 * @var string             $tejcart_summary_context   Either 'mobile' or 'desktop'.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<ul class="tejcart-checkout-review-items">
    <?php foreach ( $cart->get_items() as $cart_item_key => $cart_item ) :
        $product = $cart_item->get_product();
        if ( ! $product ) {
            continue;
        }

        $cart_item_image_id = $product->get_image_id();
        $cart_item_name     = $cart_item->get_name();
        $cart_item_qty      = (int) $cart_item->get_quantity();
        $cart_item_total    = (float) $cart_item->get_line_total();

        $variation_text = '';
        if ( method_exists( $cart_item, 'get_variation_attributes' ) ) {
            $variation_attrs = $cart_item->get_variation_attributes();
            if ( ! empty( $variation_attrs ) && is_array( $variation_attrs ) ) {
                $bits = array();
                foreach ( $variation_attrs as $attr_label => $attr_value ) {
                    // Escape per attribute (mirrors cart-item.php / cart-drawer.php)
                    // so $variation_text is safe regardless of how it is later
                    // echoed — removes the trap where switching the output sink
                    // to a non-escaping one would turn this into stored XSS.
                    $bits[] = esc_html( (string) $attr_label ) . ': ' . esc_html( (string) $attr_value );
                }
                $variation_text = implode( ' · ', $bits );
            }
        }
        ?>
        <li class="tejcart-checkout-review-item">

            <span class="tejcart-checkout-review-thumbnail">
                <?php if ( $cart_item_image_id ) : ?>
                    <?php
                    echo wp_get_attachment_image(
                        $cart_item_image_id,
                        'thumbnail',
                        false,
                        array(
                            'alt'      => esc_attr( $cart_item_name ),
                            'loading'  => 'lazy',
                            'decoding' => 'async',
                        )
                    );
                    ?>
                <?php endif; ?>
                <span
                    class="tejcart-checkout-review-thumbnail-qty"
                    aria-label="<?php
                    /* translators: %d: cart item quantity. */
                    echo esc_attr( sprintf( __( 'Quantity %d', 'tejcart' ), $cart_item_qty ) );
                    ?>"
                ><?php echo esc_html( (string) $cart_item_qty ); ?></span>
            </span>

            <span>
                <span class="tejcart-checkout-review-name"><?php echo esc_html( $cart_item_name ); ?></span>
                <?php if ( $variation_text ) : ?>
                    <?php // $variation_text is assembled from per-attribute esc_html() above, so it is output as-is to avoid double-escaping. ?>
                    <span class="tejcart-checkout-review-variant"><?php echo $variation_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
            </span>

            <span class="tejcart-checkout-review-line-total">
                <?php echo wp_kses_post( tejcart_price( $cart_item_total ) ); ?>
            </span>

        </li>
    <?php endforeach; ?>
</ul>

<dl class="tejcart-checkout-order-review-totals">

    <div class="tejcart-checkout-order-review-row tejcart-checkout-subtotal">
        <dt><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></dt>
        <dd class="tejcart-checkout-order-review-row-value tejcart-subtotal-value"><?php echo wp_kses_post( tejcart_price( (float) $cart->get_subtotal() ) ); ?></dd>
    </div>

    <?php if ( $cart->needs_shipping() ) :
        $shipping_total = (float) $cart->get_shipping_total();
        ?>
        <div class="tejcart-checkout-order-review-row tejcart-checkout-shipping-total">
            <dt><?php esc_html_e( 'Shipping', 'tejcart' ); ?></dt>
            <dd class="tejcart-checkout-order-review-row-value tejcart-shipping-value<?php echo $shipping_total <= 0 ? ' is-free' : ''; ?>">
                <?php
                if ( $shipping_total > 0 ) {
                    echo wp_kses_post( tejcart_price( $shipping_total ) );
                } else {
                    esc_html_e( 'Free', 'tejcart' );
                }
                ?>
            </dd>
        </div>
    <?php endif; ?>

    <?php
    $tejcart_tax_enabled = ( 'yes' === get_option( 'tejcart_enable_tax', 'no' ) );
    if ( $tejcart_tax_enabled ) :
        $tejcart_tax_total     = (float) $cart->get_tax_total();
        $tejcart_tax_computed  = $tejcart_tax_total > 0;
        $tejcart_has_ship_addr = false;
        // Primary signal: the cart's own shipping destination, set by
        // Checkout_AJAX::refresh_shipping_methods() on every postcode/
        // city/state keystroke. State and postcode don't share country's
        // store-default fallback, so either one being non-empty means the
        // buyer has provided a real address and the calculator has run
        // against it — even if the resulting tax is a legitimate zero
        // (tax-exempt jurisdiction, no configured rate, B2B export, …).
        if ( method_exists( $cart, 'get_shipping_destination' ) ) {
            $tejcart_tax_dest = (array) $cart->get_shipping_destination();
            $tejcart_has_ship_addr = ( '' !== (string) ( $tejcart_tax_dest['state'] ?? '' ) )
                || ( '' !== (string) ( $tejcart_tax_dest['postcode'] ?? '' ) );
        }
        // Fallback for cart shims (sibling plugins, test doubles) that
        // expose the legacy get_customer() handle instead of (or in
        // addition to) get_shipping_destination().
        if ( ! $tejcart_has_ship_addr && method_exists( $cart, 'get_customer' ) ) {
            $tejcart_tax_customer = $cart->get_customer();
            if ( is_object( $tejcart_tax_customer ) && method_exists( $tejcart_tax_customer, 'get_shipping_country' ) ) {
                $tejcart_has_ship_addr = (bool) $tejcart_tax_customer->get_shipping_country();
            }
        }
        ?>
        <div class="tejcart-checkout-order-review-row tejcart-checkout-tax">
            <dt><?php esc_html_e( 'Taxes', 'tejcart' ); ?></dt>
            <dd class="tejcart-checkout-order-review-row-value tejcart-tax-value" aria-live="polite" aria-atomic="true">
                <?php
                if ( $tejcart_tax_computed ) {
                    echo wp_kses_post( tejcart_price( $tejcart_tax_total ) );
                } elseif ( $tejcart_has_ship_addr ) {
                    echo wp_kses_post( tejcart_price( 0 ) );
                } else {
                    echo '<span class="tejcart-tax-pending" title="' . esc_attr__( 'Calculated based on your shipping address.', 'tejcart' ) . '">' . esc_html__( 'Calculated at checkout', 'tejcart' ) . '</span>';
                }
                ?>
            </dd>
        </div>
    <?php endif; ?>

    <?php if ( $cart->get_discount_total() > 0 ) : ?>
        <div class="tejcart-checkout-order-review-row tejcart-checkout-discount">
            <dt><?php esc_html_e( 'Discount', 'tejcart' ); ?></dt>
            <dd class="tejcart-checkout-order-review-row-value tejcart-discount-value">−<?php echo wp_kses_post( tejcart_price( (float) $cart->get_discount_total() ) ); ?></dd>
        </div>
    <?php endif; ?>

    <div class="tejcart-checkout-order-review-row tejcart-checkout-order-review-total tejcart-checkout-total">
        <dt>
            <?php esc_html_e( 'Total', 'tejcart' ); ?>
            <span class="tejcart-checkout-currency-label"><?php echo esc_html( (string) get_option( 'tejcart_currency', 'USD' ) ); ?></span>
        </dt>
        <dd class="tejcart-checkout-order-review-row-value tejcart-total-value" aria-live="polite" aria-atomic="true"><?php echo wp_kses_post( tejcart_price( (float) $cart->get_total() ) ); ?></dd>
    </div>

</dl>

<?php
$tejcart_summary_coupons = method_exists( $cart, 'get_coupons' ) ? (array) $cart->get_coupons() : array();
$tejcart_summary_id      = 'tejcart-checkout-coupon-' . ( isset( $tejcart_summary_context ) ? sanitize_html_class( (string) $tejcart_summary_context ) : 'default' );
?>
<div class="tejcart-checkout-coupon tejcart-coupon" data-tejcart-checkout-coupon>

    <?php if ( ! empty( $tejcart_summary_coupons ) ) : ?>
        <div class="tejcart-checkout-coupon-applied-list" data-tejcart-coupon-applied-list>
            <?php foreach ( $tejcart_summary_coupons as $tejcart_summary_code => $tejcart_summary_coupon ) : ?>
                <div class="tejcart-coupon-applied" role="status">
                    <span>
                        <?php echo esc_html__( 'Applied:', 'tejcart' ); ?>
                        <span class="tejcart-coupon-applied-code"><?php echo esc_html( strtoupper( (string) $tejcart_summary_code ) ); ?></span>
                    </span>
                    <button
                        type="button"
                        class="tejcart-coupon-remove"
                        data-tejcart-remove-coupon="<?php echo esc_attr( (string) $tejcart_summary_code ); ?>"
                        aria-label="<?php
                        /* translators: %s: coupon code (uppercase). */
                        echo esc_attr( sprintf( __( 'Remove coupon %s', 'tejcart' ), strtoupper( (string) $tejcart_summary_code ) ) );
                        ?>"
                    >
                        <?php esc_html_e( 'Remove', 'tejcart' ); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <div class="tejcart-checkout-coupon-applied-list" data-tejcart-coupon-applied-list hidden></div>
    <?php endif; ?>

    <button
        type="button"
        class="tejcart-coupon-toggle tejcart-checkout-coupon-toggle"
        aria-expanded="false"
        aria-controls="<?php echo esc_attr( $tejcart_summary_id ); ?>"
    >
        <?php esc_html_e( 'Have a discount code?', 'tejcart' ); ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <div class="tejcart-coupon-form tejcart-checkout-coupon-form" id="<?php echo esc_attr( $tejcart_summary_id ); ?>">
        <label class="tejcart-sr-only" for="<?php echo esc_attr( $tejcart_summary_id ); ?>-input">
            <?php esc_html_e( 'Discount code', 'tejcart' ); ?>
        </label>
        <input
            type="text"
            id="<?php echo esc_attr( $tejcart_summary_id ); ?>-input"
            class="tejcart-field-input tejcart-coupon-input tejcart-checkout-coupon-input"
            autocomplete="off"
            data-tejcart-coupon-input
            value=""
        />
        <button
            type="button"
            class="tejcart-button tejcart-button--secondary tejcart-checkout-apply-coupon-btn"
            data-tejcart-apply-coupon
        >
            <?php esc_html_e( 'Apply', 'tejcart' ); ?>
        </button>
        <p class="tejcart-checkout-coupon-feedback" data-tejcart-coupon-feedback role="status" aria-live="polite"></p>
    </div>
</div>

<?php

if ( $cart->needs_shipping() && class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) :
    $tejcart_summary_eta = '';
    $tejcart_chosen_id   = $cart->get_chosen_shipping_method();

    $tejcart_summary_country = '';
    $tejcart_summary_state   = '';
    if ( method_exists( $cart, 'get_customer' ) ) {
        $tejcart_summary_customer = $cart->get_customer();
        if ( is_object( $tejcart_summary_customer ) ) {
            if ( method_exists( $tejcart_summary_customer, 'get_shipping_country' ) ) {
                $tejcart_summary_country = (string) $tejcart_summary_customer->get_shipping_country();
            }
            if ( method_exists( $tejcart_summary_customer, 'get_shipping_state' ) ) {
                $tejcart_summary_state = (string) $tejcart_summary_customer->get_shipping_state();
            }
        }
    }
    if ( '' === $tejcart_summary_country ) {
        $tejcart_summary_country = (string) get_option( 'tejcart_store_country', 'US' );
    }

    $tejcart_summary_mgr     = new \TejCart\Shipping\Shipping_Manager();
    $tejcart_summary_methods = $tejcart_summary_mgr->get_available_methods( $tejcart_summary_country, $tejcart_summary_state, $cart );

    foreach ( $tejcart_summary_methods as $tejcart_summary_index => $tejcart_summary_method ) {
        $tejcart_summary_id = method_exists( $tejcart_summary_method, 'get_id' ) ? $tejcart_summary_method->get_id() : '';
        $tejcart_summary_is_chosen = ( $tejcart_chosen_id === $tejcart_summary_id ) || ( '' === $tejcart_chosen_id && 0 === $tejcart_summary_index );
        if ( $tejcart_summary_is_chosen && method_exists( $tejcart_summary_method, 'get_eta' ) ) {
            $tejcart_summary_eta = trim( (string) $tejcart_summary_method->get_eta() );
            break;
        }
    }

    if ( '' !== $tejcart_summary_eta ) : ?>
        <p class="tejcart-checkout-delivery-eta" aria-live="polite">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                <path d="M1.5 4.5h9v5h-9v-5zm9 2h2.5l1.5 1.5v1.5h-4v-3z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                <circle cx="4.5" cy="10.5" r="1.2" fill="none" stroke="currentColor" stroke-width="1.3"/>
                <circle cx="11.5" cy="10.5" r="1.2" fill="none" stroke="currentColor" stroke-width="1.3"/>
            </svg>
            <?php
            // The ETA string is already self-describing (e.g. "Estimated
            // delivery Mon, Jun 30"), so it's echoed as-is rather than
            // wrapped in a second label that would read redundantly.
            echo esc_html( $tejcart_summary_eta );
            ?>
        </p>
    <?php endif;
endif;
?>
