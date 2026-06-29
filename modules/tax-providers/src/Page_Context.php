<?php
/**
 * Detects the request context for tax-provider gating.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classifies the current request so live tax providers can decide whether to
 * incur a billable upstream call.
 *
 * Why this matters: a naive integration would fire a synchronous live tax
 * API call on every cart and minicart render, which both slows checkout to
 * a crawl on busy stores and racks up per-call surprise bills during
 * development. We default to **checkout-only** calculation and let merchants
 * opt in to the cart page if they need that accuracy.
 *
 * Returned values:
 *   - `checkout` — frontend checkout page render or its AJAX recalcs.
 *   - `cart`     — frontend cart page render or its AJAX recalcs.
 *   - `api`      — a REST request (programmatic, always allowed).
 *   - `admin`    — a wp-admin pageview (manual order edit; always allowed).
 *   - `ajax`     — an AJAX request whose origin we couldn't classify.
 *   - `unknown`  — none of the above (CLI, cron, block-editor preview, …).
 */
final class Page_Context {
    /**
     * @return string One of: `checkout`, `cart`, `api`, `admin`, `ajax`, `unknown`.
     */
    public static function detect(): string {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return 'api';
        }

        if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
            return 'admin';
        }

        if ( function_exists( 'tejcart_is_checkout_page' ) && tejcart_is_checkout_page() ) {
            return 'checkout';
        }

        if ( function_exists( 'tejcart_is_cart_page' ) && tejcart_is_cart_page() ) {
            return 'cart';
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            $referer = function_exists( 'wp_get_referer' ) ? (string) wp_get_referer() : '';
            if ( '' !== $referer && function_exists( 'tejcart_get_page_url' ) ) {
                $checkout_url = (string) tejcart_get_page_url( 'checkout' );
                if ( '' !== $checkout_url && false !== strpos( $referer, $checkout_url ) ) {
                    return 'checkout';
                }
                $cart_url = (string) tejcart_get_page_url( 'cart' );
                if ( '' !== $cart_url && false !== strpos( $referer, $cart_url ) ) {
                    return 'cart';
                }
            }
            return 'ajax';
        }

        return 'unknown';
    }

    /**
     * Decide whether a context value clears the merchant's "calculate on"
     * setting.
     *
     * @param string $context One of {@see self::detect()}.
     * @param string $setting `checkout_only`, `cart_and_checkout`, or `cart_only`.
     */
    public static function is_allowed( string $context, string $setting ): bool {
        // Admin and REST callers are always allowed — those are explicit,
        // non-pageview operations (manual orders, programmatic calculation).
        if ( 'admin' === $context || 'api' === $context ) {
            return true;
        }

        switch ( $setting ) {
            case 'cart_only':
                return 'cart' === $context;

            case 'cart_and_checkout':
                return in_array( $context, array( 'cart', 'checkout', 'ajax' ), true );

            case 'checkout_only':
            default:
                // Treat unclassified AJAX as checkout — most front-end AJAX
                // recalcs come from the checkout flow, and the alternative
                // (skipping) would silently report tax-free totals.
                return 'checkout' === $context || 'ajax' === $context;
        }
    }
}
