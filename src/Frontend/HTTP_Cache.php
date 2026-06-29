<?php
/**
 * Opt-in edge cache headers for anonymous shop traffic.
 *
 * Most TejCart surfaces are cart-aware and should never be cached. The
 * shop grid page is identical for every anonymous visitor — caching it
 * at the edge for a minute is a big TTFB win without breaking cart state.
 * (Single-product pages use their own permalink route and are not included.)
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Emits Cache-Control + Vary headers on cacheable shop-page GETs.
 *
 * Only the main shop grid page (is_page($shop_page_id)) is cached.
 * Single-product pages use a separate permalink and are not covered here.
 */
class HTTP_Cache {
    public const OPTION_ENABLED = 'tejcart_http_cache_enabled';
    public const OPTION_TTL     = 'tejcart_http_cache_ttl';

    /**
     * Hook up.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_emit_headers' ), 20 );
    }

    /**
     * Decide whether to emit cache headers for this request and, if so,
     * send them. Called from template_redirect so we know the request
     * has resolved to an actual WP template.
     */
    public function maybe_emit_headers(): void {
        $enabled = 'yes' === (string) get_option( self::OPTION_ENABLED, 'no' );

        /**
         * Filter whether the edge-cache headers are emitted.
         *
         * @param bool $enabled
         */
        $enabled = (bool) apply_filters( 'tejcart_http_cache_enabled', $enabled );
        if ( ! $enabled ) {
            return;
        }

        if ( ! $this->is_cacheable_request() ) {
            return;
        }

        // N-M1: surface the documented `tejcart_cache_page_cacheable`
        // filter so the Performance class (and 3rd parties) can veto
        // per-post cacheability. The filter was previously registered
        // by Performance::init() but never fired — making this the
        // canonical producer.
        $post = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
        if ( $post instanceof \WP_Post ) {
            /**
             * Filter whether the current post can be edge-cached.
             *
             * @param bool     $cacheable Default true once the request has
             *                            passed the anonymous-GET gate.
             * @param \WP_Post $post      The queried post.
             */
            $cacheable = (bool) apply_filters( 'tejcart_cache_page_cacheable', true, $post );
            if ( ! $cacheable ) {
                return;
            }
        }

        $ttl = (int) get_option( self::OPTION_TTL, 60 );
        /**
         * Filter the browser cache lifetime (Cache-Control: max-age) in seconds.
         *
         * @param int $ttl
         */
        $ttl = (int) apply_filters( 'tejcart_http_cache_ttl', $ttl );
        if ( $ttl <= 0 ) {
            return;
        }

        // F-FE-009: max($ttl, 2*$ttl) is always 2*$ttl when $ttl > 0 — the max() is dead.
        // CDN/proxy gets double the browser TTL so warm-cache hits outlast browser revalidation.
        $s_maxage = 2 * $ttl;

        if ( ! headers_sent() ) {
            header( 'Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $s_maxage );

            header( 'Vary: Cookie', false );
        }
    }

    /**
     * Only cache anonymous GETs to the shop / product pages when no
     * session cookies are present.
     */
    private function is_cacheable_request(): bool {
        if ( 'GET' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
            return false;
        }
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
            return false;
        }
        if ( function_exists( 'is_admin' ) && is_admin() ) {
            return false;
        }

        // Bound the loop so a hostile client cannot DoS the cache check
        // with a 50k-cookie jar (audit 04 L-7). Real TejCart sites send
        // ~5 cookies on the front-end; >256 indicates an abusive request
        // and we refuse to mark it cacheable.
        if ( count( $_COOKIE ) > 256 ) {
            return false;
        }
        foreach ( array_keys( $_COOKIE ) as $name ) {
            if ( 0 === strpos( (string) $name, 'tejcart_' ) ) {
                return false;
            }
        }

        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        if ( $shop_page_id <= 0 ) {
            return false;
        }
        if ( function_exists( 'is_page' ) && is_page( $shop_page_id ) ) {
            return true;
        }

        return false;
    }
}
