<?php
/**
 * Page-cache compatibility shim.
 *
 * Cart, checkout, account, and thank-you pages are user-specific and must
 * never be served from a shared page cache. Without these hints, plugins
 * like W3 Total Cache, WP Super Cache, WP Rocket, Cache Enabler, and
 * upstream CDNs (Cloudflare, host edge caches) will happily cache a
 * logged-in user's cart contents and serve them to the next anonymous
 * visitor — a privacy incident with regulatory exposure.
 *
 * @package TejCart\Frontend
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Marks dynamic TejCart pages as "do not cache" for every cache layer
 * we have a documented hook into.
 */
class Cache_Compatibility {
    /**
     * Page option keys whose pages must never be cached.
     */
    private const DYNAMIC_PAGE_OPTIONS = array(
        'tejcart_cart_page_id',
        'tejcart_checkout_page_id',
        'tejcart_account_page_id',
        'tejcart_thankyou_page_id',
    );

    /**
     * Wire hooks. Runs early on template_redirect so cache plugins that
     * decide on the same hook see our flags before they make a decision.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'mark_dynamic_pages' ), 1 );
    }

    /**
     * If the current page is dynamic, set every signal a page-cache layer
     * is likely to look at.
     */
    public function mark_dynamic_pages(): void {
        if ( is_admin() || is_robots() || is_feed() || is_trackback() ) {
            return;
        }

        if ( ! $this->is_dynamic_page() ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! defined( 'DONOTCACHEDB' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
            define( 'DONOTCACHEDB', true );
        }
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
            define( 'DONOTCACHEOBJECT', true );
        }

        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
        }

        add_filter( 'rocket_cache_reject_uri', array( $this, 'reject_uri_paths' ) );
        add_filter( 'wp_cache_eof_tags', '__return_empty_array' );

        /**
         * Fires when TejCart marks the current request as uncacheable.
         *
         * Hosts and integrations can use this to push their own no-cache
         * signals (Varnish bans, Cloudflare bypass cookies, etc.).
         *
         * @since 1.0.0
         */
        do_action( 'tejcart_disable_page_cache' );
    }

    /**
     * Decide whether the current request is a TejCart dynamic page.
     */
    private function is_dynamic_page(): bool {
        foreach ( self::DYNAMIC_PAGE_OPTIONS as $opt ) {
            $page_id = absint( get_option( $opt, 0 ) );
            if ( $page_id > 0 && is_page( $page_id ) ) {
                return true;
            }
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post ) {
                $content = (string) $post->post_content;
                if ( has_shortcode( $content, 'tejcart_cart' )
                    || has_shortcode( $content, 'tejcart_checkout' )
                    || has_shortcode( $content, 'tejcart_account' )
                    || has_shortcode( $content, 'tejcart_thankyou' )
                    || has_shortcode( $content, 'tejcart_my_account' )
                ) {
                    return true;
                }
            }
        }

        /**
         * Filter whether the current request should be marked as
         * uncacheable by TejCart.
         *
         * @since 1.0.0
         *
         * @param bool $is_dynamic Default false.
         */
        return (bool) apply_filters( 'tejcart_is_dynamic_page', false );
    }

    /**
     * Add TejCart's dynamic page paths to WP Rocket's reject list.
     *
     * @param array $paths Existing reject patterns.
     * @return array
     */
    public function reject_uri_paths( $paths ): array {
        $paths = is_array( $paths ) ? $paths : array();

        foreach ( self::DYNAMIC_PAGE_OPTIONS as $opt ) {
            $page_id = absint( get_option( $opt, 0 ) );
            if ( $page_id <= 0 ) {
                continue;
            }
            $url = get_permalink( $page_id );
            if ( ! $url ) {
                continue;
            }
            $path = wp_parse_url( $url, PHP_URL_PATH );
            if ( $path ) {
                $paths[] = '/' . trim( $path, '/' ) . '(/.*)?';
            }
        }

        return $paths;
    }
}
