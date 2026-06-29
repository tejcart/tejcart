<?php
/**
 * Performance hardening for high-volume stores.
 *
 * This class configures the plugin to take full advantage of a persistent
 * object cache (Redis, Memcached, or any drop-in) and adds a few
 * request-local micro-caches to keep hot paths allocation-free.
 *
 * The goal is to keep the critical path of shop listings, add-to-cart,
 * and checkout measured in low single-digit milliseconds of PHP time on a
 * warm cache, so a commodity LAMP host can sustain hundreds of RPS per
 * worker without degrading.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires up caching and micro-optimisation hooks.
 */
class Performance {
    /**
     * Object-cache groups that should survive between requests when a
     * persistent backend is available. Grouping matters because most
     * drop-in caches (Redis, Memcached) invalidate by group, and keeping
     * hot reads in the same namespace maximises hit rate.
     *
     * @var string[]
     */
    private static $global_groups = array(

        'tejcart',
        'tejcart_products',
        'tejcart_product_meta',
        'tejcart_orders',
        'tejcart_order_meta',
        'tejcart_carts',
        'tejcart_cart_sessions',
        'tejcart_coupons',
        'tejcart_coupon_meta',
        'tejcart_taxes',
        'tejcart_shipping',
        'tejcart_customers',
        'tejcart_api_keys',
        'tejcart_rate_limit',
        // Audit L-14 (Core F-016): module-owned cache groups so
        // multisite reads are network-global, not per-site.
        'tejcart_analytics',
        'tejcart_disputes',
        'tejcart_order_tracking',
        'tejcart_returns',
        'tejcart_gift_cards',
        'tejcart_currency_switcher',
    );

    /**
     * Register performance hooks.
     *
     * Called once during plugin bootstrap. Registers global cache groups
     * immediately (they must be declared before any get/set calls hit the
     * cache layer) and attaches the remaining hooks to later phases so we
     * do not pay the cost on admin-ajax heartbeats or cron runs.
     *
     * @return void
     */
    public static function init() {
        self::register_global_groups();

        add_action( 'init', array( __CLASS__, 'maybe_disable_heartbeat' ), 5 );
        add_action( 'send_headers', array( __CLASS__, 'emit_cache_hints' ) );

        add_filter( 'tejcart_cache_page_cacheable', array( __CLASS__, 'page_cacheable' ), 10, 2 );
    }

    /**
     * Tell the object cache which groups are shared across sites in a
     * multisite network. Doing this at load-time means every wp_cache_*
     * call afterwards uses the fast persistent path.
     *
     * @return void
     */
    public static function register_global_groups() {
        if ( function_exists( 'wp_cache_add_global_groups' ) ) {
            wp_cache_add_global_groups( self::$global_groups );
        }

        if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
            wp_cache_add_non_persistent_groups(
                array(
                    'tejcart_request',
                    'tejcart_micro',
                )
            );
        }
    }

    /**
     * On high-volume admin screens WordPress heartbeat polling creates
     * surprising amounts of CPU. We slow it down on TejCart admin pages
     * (the UI is AJAX-driven in its own cadence) without touching the
     * WP editor or other plugins' screens.
     *
     * @return void
     */
    public static function maybe_disable_heartbeat() {
        if ( ! is_admin() ) {
            return;
        }
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
        if ( 'admin.php' === $pagenow && 0 === strpos( $page, 'tejcart' ) ) {
            add_filter(
                'heartbeat_settings',
                static function ( $s ) {
                    $s['interval'] = 60;
                    return $s;
                }
            );
        }
    }

    /**
     * Emit cache-control hints so well-behaved CDNs can cache anonymous
     * shop / product / category pages safely.
     *
     * We intentionally do not cache cart, checkout, or account pages, and
     * we use a short stale-while-revalidate window so catalog edits
     * propagate quickly.
     *
     * @return void
     */
    public static function emit_cache_hints() {
        if ( is_admin() || headers_sent() ) {
            return;
        }

        // M-4: logged-in users must always carry a private,no-store header.
        // Without this the CDN falls through to its default policy and a
        // shopper's catalog response can be served to the next anonymous
        // visitor with their tejcart_session cookie attached.
        if ( is_user_logged_in() ) {
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Vary: Cookie' );
            return;
        }

        $cart_page     = (int) get_option( 'tejcart_cart_page_id' );
        $checkout_page = (int) get_option( 'tejcart_checkout_page_id' );
        // F-CORE-004: key must match what Installer writes — 'tejcart_myaccount_page_id'.
        // The old key 'tejcart_account_page_id' was never set, so My Account
        // pages were receiving public Cache-Control headers (private session
        // data visible to CDN caches).
        $account_page  = (int) get_option( 'tejcart_myaccount_page_id' );

        if ( is_page( array_filter( array( $cart_page, $checkout_page, $account_page ) ) ) ) {
            header( 'Cache-Control: private, no-store, max-age=0' );
            return;
        }

        /**
         * Filter the anonymous-page Cache-Control header.
         *
         * Default is 60 seconds of shared caching with 5 minutes of
         * stale-while-revalidate, which gives CDNs a comfortable buffer
         * while keeping staleness low after product edits.
         *
         * @param string $header
         */
        $header = (string) apply_filters(
            'tejcart_performance_cache_control',
            'public, max-age=60, s-maxage=300, stale-while-revalidate=600'
        );
        if ( '' !== $header ) {
            header( 'Cache-Control: ' . $header );
            // S-7: do NOT vary on Cookie for anonymous catalog responses.
            // The tejcart_session cookie is set on every visitor, so a
            // Vary: Cookie key would split the edge cache per-visitor and
            // collapse hit ratio to <5%. Operators should configure a
            // CDN bypass-on-cookie page rule (tejcart_session,
            // wordpress_logged_in_*, comment_author_*) instead.
            header( 'Vary: Accept-Encoding' );
        }
    }

    /**
     * Default rule: cart/checkout/account and any page containing a
     * TejCart dynamic block are not page-cacheable.
     *
     * @param bool     $cacheable
     * @param \WP_Post $post
     * @return bool
     */
    public static function page_cacheable( $cacheable, $post ) {
        if ( ! $cacheable ) {
            return false;
        }
        if ( ! $post instanceof \WP_Post ) {
            return $cacheable;
        }
        $dynamic_ids = array_filter( array(
            (int) get_option( 'tejcart_cart_page_id' ),
            (int) get_option( 'tejcart_checkout_page_id' ),
            (int) get_option( 'tejcart_account_page_id' ),
        ) );
        if ( in_array( (int) $post->ID, $dynamic_ids, true ) ) {
            return false;
        }
        if ( has_shortcode( (string) $post->post_content, 'tejcart_cart' ) ) {
            return false;
        }
        if ( has_shortcode( (string) $post->post_content, 'tejcart_checkout' ) ) {
            return false;
        }
        if ( has_shortcode( (string) $post->post_content, 'tejcart_account' ) ) {
            return false;
        }
        return true;
    }

    /**
     * Request-local memoisation helper. Safe to call from any hot path.
     *
     * Example:
     *
     *     $row = Performance::memoize( 'product:' . $id, static function () use ( $id ) {
     *         return $wpdb->get_row( ... );
     *     } );
     *
     * @param string   $key
     * @param callable $producer
     * @return mixed
     */
    public static function memoize( $key, callable $producer ) {
        static $store = array();
        if ( array_key_exists( $key, $store ) ) {
            return $store[ $key ];
        }
        return $store[ $key ] = $producer();
    }
}
