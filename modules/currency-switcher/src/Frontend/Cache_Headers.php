<?php
/**
 * Emits cache-control + Vary headers on currency-sensitive pages so a
 * CDN never serves a cached page rendered in the wrong currency.
 *
 * @package TejCart\Currency_Switcher\Frontend
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hooks `send_headers` to add `Cache-Control: private, no-cache` and
 * `Vary: Cookie` on cart / checkout / product / shop pages so the
 * customer never gets a cached page rendered in another visitor's
 * currency. The Vary value is restricted to standard HTTP header
 * names — RFC 7231 lists *header* names there, never cookie names.
 *
 * When core's opt-in HTTP edge cache is enabled
 * (`tejcart_http_cache_enabled`), we step out of its way: instead of
 * unconditionally stomping its `public, max-age=…` headers we hook
 * into the `tejcart_cache_page_cacheable` filter to veto edge caching
 * on the same pages. That keeps the two systems coordinated rather
 * than racing each other on `template_redirect` vs. `send_headers`.
 */
final class Cache_Headers {
    public function register(): void {
        add_action( 'send_headers', array( $this, 'maybe_send' ) );
        add_filter( 'tejcart_cache_page_cacheable', array( $this, 'veto_edge_cache' ), 10, 2 );
        add_action( 'init', array( $this, 'register_cache_plugin_compat' ), 1 );
    }

    /**
     * Register cookie-based cache-variant hints for third-party page
     * caches so each currency gets its own cached copy instead of one
     * shared HTML page served to every visitor.
     *
     * WP Rocket: `rocket_cache_dynamic_cookies`
     * LiteSpeed Cache: `litespeed_vary_cookies`
     * W3 Total Cache: reads the Vary header we already emit
     * WP Super Cache: `wpsc_cachedata` — excluded via the no-cache header
     */
    public function register_cache_plugin_compat(): void {
        add_filter( 'rocket_cache_dynamic_cookies', array( $this, 'add_rocket_cookie' ) );
        add_filter( 'rocket_cache_mandatory_cookies', array( $this, 'add_rocket_cookie' ) );
        add_filter( 'litespeed_vary_cookies', array( $this, 'add_litespeed_cookie' ) );
    }

    /**
     * @param string[] $cookies
     * @return string[]
     */
    public function add_rocket_cookie( $cookies ): array {
        if ( ! is_array( $cookies ) ) {
            $cookies = array();
        }
        if ( ! in_array( 'tejcart_csw_currency', $cookies, true ) ) {
            $cookies[] = 'tejcart_csw_currency';
        }
        return $cookies;
    }

    /**
     * @param string[] $cookies
     * @return string[]
     */
    public function add_litespeed_cookie( $cookies ): array {
        if ( ! is_array( $cookies ) ) {
            $cookies = array();
        }
        if ( ! in_array( 'tejcart_csw_currency', $cookies, true ) ) {
            $cookies[] = 'tejcart_csw_currency';
        }
        return $cookies;
    }

    public function maybe_send(): void {
        if ( ! $this->is_currency_sensitive_page() ) {
            return;
        }
        // If the merchant has explicitly opted into the core edge cache,
        // skip the private/no-cache header emission — the
        // `tejcart_cache_page_cacheable` veto below decides per-request
        // whether HTTP_Cache emits anything at all. Avoids the stomp
        // race where send_headers fires after template_redirect priority
        // 20 and overwrites the public Cache-Control just sent.
        if ( $this->edge_cache_enabled() ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }
        header( 'Cache-Control: private, no-cache, max-age=0, must-revalidate', true );
        // Vary lists header names per RFC 7231 §7.1.4, not cookie names.
        // Caches already partition on the Cookie header value so Vary:
        // Cookie is sufficient; the previous "Vary: Cookie, <cookie-name>"
        // form was a syntactic no-op at best, header-malforming at worst.
        header( 'Vary: Cookie', false );
    }

    /**
     * When HTTP_Cache is the active page-cache producer, refuse to let
     * it cache currency-sensitive pages — different visitors may see
     * the same path in different currencies, so a shared cache entry
     * is unsafe regardless of the cookie partitioning the cache layer
     * does.
     *
     * @param bool $cacheable Decision so far.
     * @param mixed $post     The queried post (unused — we decide on
     *                        page type, not post identity).
     */
    public function veto_edge_cache( $cacheable, $post = null ): bool {
        unset( $post );
        if ( ! $cacheable ) {
            return false;
        }
        return ! $this->is_currency_sensitive_page();
    }

    /**
     * Whether the merchant has opted into core's edge cache. We avoid a
     * direct option read in the hot path by short-circuiting on the
     * filter: if any plugin has filtered the value to true, we treat
     * the cache as enabled.
     */
    private function edge_cache_enabled(): bool {
        $option = (string) get_option( 'tejcart_http_cache_enabled', 'no' );
        $enabled = ( 'yes' === $option );
        return (bool) apply_filters( 'tejcart_http_cache_enabled', $enabled );
    }

    private function is_currency_sensitive_page(): bool {
        return Page_Context::is_currency_sensitive();
    }
}
