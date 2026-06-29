<?php
/**
 * Checkout-time dual-currency behaviour.
 *
 * @package TejCart\Currency_Switcher\Checkout
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Checkout;

use TejCart\Currency_Switcher\Conversion\Converter;
use TejCart\Currency_Switcher\Frontend\Page_Context;
use TejCart\Currency_Switcher\Options;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Two operating modes governed by `tejcart_csw_checkout_diff_currency`:
 *
 *  - 'yes' (default) — customer pays in the currency they selected.
 *    Per-currency gateway filtering is the only extra behaviour.
 *
 *  - 'no'            — checkout is forced into the store base currency.
 *    Cart / checkout / order-pay pages, the Store REST API, and the
 *    checkout-related AJAX endpoints all see the base currency. Prices
 *    in the visitor's currency are appended as a `~secondary` reference
 *    line.
 */
final class Checkout_Controller {
    /**
     * Per-request flag tracking whether we should report the base
     * currency. Set true when {@see self::diff_currency_allowed()} is
     * false AND the current request is a checkout context.
     */
    private static ?bool $force_base = null;

    public function register(): void {
        add_action( 'wp', array( $this, 'detect_force_base_context' ) );
        add_action( 'rest_api_init', array( $this, 'detect_force_base_context_rest' ), 1 );
        add_action( 'init', array( $this, 'detect_force_base_context_ajax' ), 1 );
        add_action( 'tejcart_before_checkout_form', array( $this, 'maybe_print_charge_notice' ) );
        add_action( 'tejcart_before_cart', array( $this, 'maybe_print_charge_notice' ) );
    }

    public static function diff_currency_allowed(): bool {
        return 'no' !== (string) get_option( Options::CHECKOUT_DIFF_CURRENCY, 'yes' );
    }

    /**
     * Whether the current request must report the store base currency.
     */
    public static function is_force_base_request(): bool {
        return true === self::$force_base;
    }

    /** Test helper — clears the per-request decision. */
    public static function reset_force_base(): void {
        self::$force_base = null;
    }

    /**
     * Decide whether the current page-load is a checkout context that
     * should be forced into base currency. Runs on `wp` so conditional
     * tags (is_cart, is_checkout, is_account_page) are available.
     */
    public function detect_force_base_context(): void {
        if ( self::diff_currency_allowed() ) {
            self::$force_base = false;
            return;
        }
        self::$force_base = Page_Context::is_checkout_page()
            || Page_Context::is_cart_page()
            || Page_Context::is_pay_for_order_page();
    }

    /**
     * Same decision but for REST requests targeting the Store API
     * checkout endpoints — covers block-based checkout.
     *
     * F-MODS-006: The previous list only contained `/tejcart/v1/checkout`.
     * Payment-capture endpoints (e.g. `/tejcart/v1/paypal/capture`, order
     * creation via `/tejcart/v1/orders`) were not covered, so order rows
     * could be written with the display currency rather than the base
     * currency in Mode B. Extended to cover all order-creation and
     * payment-capture REST paths.
     *
     * The patterns use `preg_quote`-safe literal segments; `#` is the
     * delimiter. Add further paths via the `tejcart_csw_force_base_rest_patterns`
     * filter so gateway addons can register their own capture endpoints
     * without patching core.
     */
    public function detect_force_base_context_rest(): void {
        if ( self::diff_currency_allowed() ) {
            self::$force_base = false;
            return;
        }
        $route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        /**
         * REST URL patterns that should force the base currency in Mode B.
         * Each pattern is matched against the full REQUEST_URI (including
         * query string). Patterns must be valid PCRE with `#` as delimiter.
         *
         * @filter tejcart_csw_force_base_rest_patterns
         * @param string[] $patterns Array of PCRE patterns.
         */
        $patterns = (array) apply_filters( 'tejcart_csw_force_base_rest_patterns', array(
            // Core checkout endpoint (block checkout + classic checkout REST).
            '#/tejcart/v1/checkout#',
            // Order creation via the REST API (e.g. admin / app order flows).
            '#/tejcart/v1/orders\b#',
            // Cart checkout path (alternative checkout route).
            '#/tejcart/v1/cart/checkout\b#',
            // PayPal PPCP order creation + capture.
            '#/tejcart/v1/paypal/#',
            // Stripe gateway order creation + capture.
            '#/tejcart/v1/stripe/#',
            // Generic payment capture path segment used by gateway addons.
            '#/tejcart/v1/[^/]+/capture\b#',
            '#/tejcart/v1/[^/]+/order\b#',
        ) );

        foreach ( $patterns as $pattern ) {
            if ( is_string( $pattern ) && '' !== $pattern && preg_match( $pattern, $route ) ) {
                self::$force_base = true;
                return;
            }
        }
        self::$force_base = false;
    }

    /**
     * Force base currency for the three checkout-related WP-AJAX
     * actions: `update_order_review`, `checkout`, `get_refreshed_fragments`.
     */
    public function detect_force_base_context_ajax(): void {
        if ( self::diff_currency_allowed() ) {
            self::$force_base = false;
            return;
        }
        if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection of the WP-AJAX action name to decide on currency forcing; not state-changing
        $action = isset( $_REQUEST['action'] )
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection of the WP-AJAX action name to decide on currency forcing; not state-changing
            ? sanitize_text_field( wp_unslash( (string) $_REQUEST['action'] ) )
            : '';
        if ( in_array( $action, array( 'update_order_review', 'checkout', 'get_refreshed_fragments', 'tejcart_checkout' ), true ) ) {
            self::$force_base = true;
        }
    }

    /**
     * Print a dual-price notice on checkout when we're charging the
     * customer in the base currency while they were browsing in a
     * different one.
     */
    public function maybe_print_charge_notice(): void {
        if ( self::diff_currency_allowed() ) {
            return;
        }
        $resolver = new Currency_Resolver();
        $base     = ( new Converter() )->repository()->base_currency();
        $active   = $resolver->current();
        if ( $active === $base ) {
            return;
        }

        $symbol = function_exists( 'tejcart_get_currency_symbol' )
            ? tejcart_get_currency_symbol( $base )
            : $base;

        $notice = sprintf(
            /* translators: 1: base currency code, 2: base currency symbol, 3: selected currency code */
            esc_html__(
                'You will be charged in %1$s (%2$s). Prices shown in %3$s are estimates for your reference.',
                'tejcart'
            ),
            esc_html( $base ),
            esc_html( $symbol ),
            esc_html( $active )
        );

        do_action( 'tejcart_csw_render_checkout_notice', $notice, $base, $active );

        echo '<div class="tejcart-csw-checkout-notice">' . $notice . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
