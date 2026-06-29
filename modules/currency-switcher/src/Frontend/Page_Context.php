<?php
/**
 * TejCart-native page-type detection.
 *
 * @package TejCart\Currency_Switcher\Frontend
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of truth for "is the visitor on a currency-sensitive
 * page?" Replaces ad-hoc `is_shop()` / `is_product()` / `is_cart()` /
 * `is_checkout()` calls — those template tags are WooCommerce-only and
 * never resolve in a standalone TejCart install, so any module that
 * relied on them was silently broken (cache headers never emitted,
 * force-base mode never engaged on cart/checkout, sidebar never
 * auto-injected, …).
 *
 * Every method uses TejCart-native conditionals:
 *   - the merchant's saved cart/checkout/shop page IDs,
 *   - the canonical `tejcart_product_slug` query var registered by
 *     `\TejCart\Frontend\Product_Permalinks` (plus the legacy
 *     `?product=ID` parameter), which is how a standalone TejCart
 *     install routes single-product URLs — there is no
 *     `tejcart_product` post type,
 *   - the public `tejcart_product_cat` / `tejcart_product_tag`
 *     taxonomies registered by `\TejCart\Product\Product_Taxonomy`,
 *   - the documented `tejcart_is_cart_page()` / `tejcart_is_checkout_page()`
 *     public helpers from `src/functions.php`,
 *   - a singular-shortcode fallback for non-canonical placements.
 *
 * Each helper is pure and side-effect-free; callers may call them
 * many times per request.
 */
final class Page_Context {
    public static function is_front_page(): bool {
        return function_exists( 'is_front_page' ) && is_front_page();
    }

    public static function is_shop_page(): bool {
        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        if ( $shop_page_id > 0 && function_exists( 'is_page' ) && is_page( $shop_page_id ) ) {
            return true;
        }
        return self::singular_has_shortcode( 'tejcart_products' );
    }

    /**
     * A single-product view. Standalone TejCart routes these through
     * the shop page with the `tejcart_product_slug` query var (see
     * `\TejCart\Frontend\Product_Permalinks`); the legacy `?product=ID`
     * parameter is also honoured by core's product renderer. Either
     * canonical signal — or a `[tejcart_product]` shortcode placement —
     * counts.
     */
    public static function is_product_page(): bool {
        if ( function_exists( 'get_query_var' ) ) {
            $slug = (string) get_query_var( 'tejcart_product_slug', '' );
            if ( '' !== $slug ) {
                return true;
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['product'] ) && absint( wp_unslash( $_GET['product'] ) ) > 0 ) {
            return true;
        }
        return self::singular_has_shortcode( 'tejcart_product' );
    }

    public static function is_product_category_page(): bool {
        return function_exists( 'is_tax' )
            && is_tax( array( 'tejcart_product_cat', 'tejcart_product_tag' ) );
    }

    public static function is_cart_page(): bool {
        if ( function_exists( 'tejcart_is_cart_page' ) && tejcart_is_cart_page() ) {
            return true;
        }
        return self::singular_has_shortcode( 'tejcart_cart' );
    }

    public static function is_checkout_page(): bool {
        if ( function_exists( 'tejcart_is_checkout_page' ) && tejcart_is_checkout_page() ) {
            return true;
        }
        return self::singular_has_shortcode( 'tejcart_checkout' );
    }

    /**
     * Pay-for-order surface: the merchant's pay page (if configured)
     * OR any singular hit on a page carrying both `order_id` and
     * `order_key` query args (the standalone `[tejcart_order_pay]`
     * shortcode signature).
     */
    public static function is_pay_for_order_page(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['order_id'], $_GET['order_key'] ) ) {
            return false;
        }
        $page_id = (int) get_option( 'tejcart_order_pay_page_id', 0 );
        if ( $page_id > 0 && function_exists( 'is_page' ) && is_page( $page_id ) ) {
            return true;
        }
        // Conservative fallback: the (order_id, order_key) pair is so
        // specific to TejCart's pay flow that any page receiving both
        // is treated as a pay surface. Worst case: force-base mode
        // engages on a page that doesn't actually take payment —
        // harmless.
        return true;
    }

    /**
     * Any of the four currency-sensitive surfaces — shop, product,
     * product-category, cart, checkout — that must never be cached
     * across visitors with different active currencies.
     */
    public static function is_currency_sensitive(): bool {
        return self::is_shop_page()
            || self::is_product_page()
            || self::is_product_category_page()
            || self::is_cart_page()
            || self::is_checkout_page()
            || self::is_pay_for_order_page();
    }

    private static function singular_has_shortcode( string $shortcode ): bool {
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
            return false;
        }
        $post = function_exists( 'get_post' ) ? get_post() : null;
        if ( ! $post ) {
            return false;
        }
        return function_exists( 'has_shortcode' )
            && has_shortcode( (string) $post->post_content, $shortcode );
    }
}
