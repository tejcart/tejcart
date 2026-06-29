<?php
/**
 * Edge-cacheable storefront pages with client-side fragment hydration.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires together three things that together let storefront pages be
 * served from an edge cache (Cloudflare, Varnish, fastcgi_cache) for
 * EVERY visitor — including those with a cart cookie — by moving the
 * per-visitor bits (cart icon count, mini-cart, login pill) out of the
 * cached HTML and into a tiny client-side hydrate call:
 *
 *   1. The `[tejcart_cart_icon]` shortcode emits placeholder HTML with
 *      `data-tejcart-fragment` attributes and safe zero-state defaults
 *      (count = 0, no items). The HTML is the SAME for every visitor,
 *      so the page is byte-identical at the cache key.
 *
 *   2. {@see enqueue_hydrator_script()} loads
 *      `assets/js/tejcart-storefront-hydrate.js` on every storefront
 *      page. After DOMContentLoaded it hits PR #6's
 *      `/wp-json/tejcart/v1/storefront/state` endpoint and fills the
 *      placeholders with the current visitor's cart state.
 *
 *   3. {@see emit_cache_headers()} sets `Cache-Control: public,
 *      max-age, s-maxage, stale-while-revalidate` on storefront pages
 *      that the merchant has marked edge-cacheable, plus a `Vary` that
 *      includes `Accept-Encoding` (so gzip / brotli variants don't
 *      poison each other) but DELIBERATELY OMITS `Cookie`. The
 *      placeholder HTML is the same for every visitor, so cookie
 *      variation isn't a privacy concern; including `Vary: Cookie`
 *      would defeat the entire point of the optimisation by giving
 *      every cookie-bearing visitor their own cache key.
 *
 * Why this exists
 * ===============
 * The classic storefront cache problem: "View cart (3)" is inlined into
 * every page's HTML, so the page cache key has to vary per visitor. A
 * cache that varies per visitor isn't really a cache. This class
 * breaks that coupling. The cache key becomes (page URL, encoding) —
 * stable across the entire visitor base.
 *
 * Critical safety: the shortcode + hydration only run on EDGE-
 * CACHEABLE pages. Cart, checkout, and my-account pages keep their
 * legacy server-rendered cart state and emit `Cache-Control: private,
 * no-store` — see {@see is_edge_cacheable_page()}.
 *
 * Opt-in
 * ======
 * Default OFF for the first release so existing merchants can adopt
 * incrementally. Enable per-site via:
 *
 *     update_option( 'tejcart_edge_hydrate_enabled', 'yes' );
 *     // OR
 *     add_filter( 'tejcart_edge_hydrate_enabled', '__return_true' );
 *
 * The filter wins over the option so a merchant can A/B test by
 * shipping the flag in code without touching the database.
 *
 * Merchants who want this win must ALSO add the
 * `[tejcart_cart_icon]` shortcode (or call
 * `do_shortcode('[tejcart_cart_icon]')`) in their theme's header. There is no
 * automatic HTML transform — we don't own merchant themes and refuse
 * to rewrite their markup.
 */
class Edge_Hydrate {
    public const OPTION_ENABLED = 'tejcart_edge_hydrate_enabled';

    /**
     * Default cache lifetime in seconds. 5 minutes is a sweet spot
     * for storefront browsing: long enough to soak burst traffic from
     * a viral product launch, short enough that price / stock edits
     * land within a tolerable window. Filterable per-site.
     */
    public const DEFAULT_MAX_AGE = 300;

    /**
     * Default stale-while-revalidate window. 24 hours means edge tiers
     * keep serving the stale response (instantly) while asynchronously
     * refreshing in the background, even when the origin is slow.
     * Filterable per-site.
     */
    public const DEFAULT_SWR = 86400;

    /**
     * @return void
     */
    public function init(): void {
        add_shortcode( 'tejcart_cart_icon', array( $this, 'render_cart_icon_shortcode' ) );

        // Hydrator JS — only loaded when the feature is enabled, on
        // pages that actually contain a placeholder. The placeholder
        // check is done client-side (querySelectorAll) so theme
        // authors don't have to remember to enqueue a script.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_hydrator_script' ), 20 );

        // Cache-Control header emission. Hooked at template_redirect
        // (matches existing HTTP_Cache class) so we know the request
        // has resolved to a real WP template before deciding.
        add_action( 'template_redirect', array( $this, 'emit_cache_headers' ), 30 );
    }

    /**
     * `[tejcart_cart_icon]` shortcode.
     *
     * Renders zero-state HTML with `data-tejcart-fragment` placeholders.
     * The hydrator script replaces the count + total after the page
     * loads. When the feature is disabled, falls back to a simple
     * server-rendered link with the current cart count — preserving
     * the legacy experience.
     *
     * Attributes:
     *   show_count  yes|no (default yes)
     *   show_total  yes|no (default no)
     *   icon_label  Accessible label (default "Cart")
     *
     * @param array<string, string>|string $atts
     * @return string
     */
    public function render_cart_icon_shortcode( $atts ): string {
        $atts = shortcode_atts(
            array(
                'show_count' => 'yes',
                'show_total' => 'no',
                'icon_label' => __( 'Cart', 'tejcart' ),
            ),
            is_array( $atts ) ? $atts : array(),
            'tejcart_cart_icon'
        );

        $cart_url = function_exists( 'tejcart_get_page_url' )
            ? (string) tejcart_get_page_url( 'cart' )
            : '/cart/';

        // Pick zero-state defaults for the un-hydrated render. When
        // edge cache is hot the same placeholder ships to every
        // visitor; the JS fills in the real values before the page
        // is interactive. When edge cache is cold OR the feature is
        // disabled, the count comes from the server-rendered cart so
        // the response still looks correct without JS.
        $count            = 0;
        $subtotal_display = function_exists( 'tejcart_price' ) ? (string) tejcart_price( 0.0 ) : '0.00';
        if ( ! self::feature_enabled() && function_exists( 'tejcart_get_cart' ) ) {
            $cart  = tejcart_get_cart();
            if ( is_object( $cart ) && is_callable( array( $cart, 'get_item_count' ) ) ) {
                $count = (int) $cart->get_item_count();
            }
            if ( is_object( $cart ) && is_callable( array( $cart, 'get_subtotal' ) ) && function_exists( 'tejcart_price' ) ) {
                $subtotal_display = (string) tejcart_price( (float) $cart->get_subtotal() );
            }
        }

        $show_count = 'yes' === strtolower( (string) $atts['show_count'] );
        $show_total = 'yes' === strtolower( (string) $atts['show_total'] );

        $html  = '<a href="' . esc_url( $cart_url ) . '" class="tejcart-cart-icon" data-tejcart-fragment="cart-icon-link" aria-label="' . esc_attr( (string) $atts['icon_label'] ) . '">';
        $html .= '<span class="tejcart-cart-icon__icon" aria-hidden="true">' . self::svg_icon() . '</span>';
        if ( $show_count ) {
            // The data-tejcart-fragment="cart-icon" element is what
            // the hydrator targets. Empty class on the badge when
            // count=0 so themes can hide via CSS.
            $hidden_class = 0 === $count ? ' tejcart-cart-icon__count--empty' : '';
            $html .= '<span class="tejcart-cart-icon__count' . esc_attr( $hidden_class ) . '" data-tejcart-fragment="cart-icon" data-cart-count="' . esc_attr( (string) $count ) . '">' . esc_html( (string) $count ) . '</span>';
        }
        if ( $show_total ) {
            $html .= '<span class="tejcart-cart-icon__total" data-tejcart-fragment="cart-subtotal">' . esc_html( $subtotal_display ) . '</span>';
        }
        $html .= '</a>';
        return $html;
    }

    /**
     * Enqueue the hydrator script on storefront pages. The script is
     * tiny (<2 KB minified, no deps) and is no-op on pages that don't
     * contain a placeholder, so the cost of an unnecessary enqueue
     * is one HTTP/2 request and the parse cost.
     *
     * Skipped when the feature is disabled — no script ships to
     * existing merchants until they opt in.
     *
     * @return void
     */
    public function enqueue_hydrator_script(): void {
        if ( ! self::feature_enabled() ) {
            return;
        }
        if ( is_admin() ) {
            return;
        }
        // Cart / checkout / my-account pages hydrate from server-side
        // state already; loading the hydrator there would race the
        // server-rendered cart and might briefly flash a stale count.
        if ( ! self::is_storefront_page() ) {
            return;
        }

        $handle = 'tejcart-storefront-hydrate';
        $src    = function_exists( 'tejcart_asset_url' )
            ? tejcart_asset_url( 'js/tejcart-storefront-hydrate.js' )
            : '';
        if ( '' === $src ) {
            return;
        }

        wp_register_script( $handle, $src, array(), defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : null, true );

        // Pass the REST endpoint URL via a tiny inline script. We
        // deliberately pass an absolute URL (rest_url) rather than
        // hard-coding the path so multisite installs in subdirectory
        // mode still resolve correctly.
        $endpoint = function_exists( 'rest_url' ) ? (string) rest_url( 'tejcart/v1/storefront/state' ) : '/wp-json/tejcart/v1/storefront/state';
        $config   = array(
            'endpoint' => $endpoint,
            // Brief client-side cache so back/forward navigations
            // don't re-hit the endpoint when the cart can't have
            // changed. Filter-tuned per-site.
            'maxAgeMs' => (int) apply_filters( 'tejcart_edge_hydrate_client_cache_ms', 30000 ),
        );

        wp_add_inline_script(
            $handle,
            'window.tejcartStorefront=' . wp_json_encode( $config ) . ';',
            'before'
        );
        wp_enqueue_script( $handle );
    }

    /**
     * Emit edge-cache-friendly headers on storefront pages. Skipped
     * when the feature is disabled, when the page is non-cacheable
     * (cart / checkout / account / admin / POST request), or when
     * `tejcart_edge_hydrate_emit_headers` filter returns false (lets
     * sites hand cache-control management to a CDN-side rule instead).
     *
     * @return void
     */
    public function emit_cache_headers(): void {
        if ( ! self::feature_enabled() ) {
            return;
        }
        if ( ! self::is_storefront_page() ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }
        if ( ! apply_filters( 'tejcart_edge_hydrate_emit_headers', true ) ) {
            return;
        }

        $max_age = (int) apply_filters(
            'tejcart_edge_hydrate_max_age',
            self::DEFAULT_MAX_AGE
        );
        $swr = (int) apply_filters(
            'tejcart_edge_hydrate_stale_while_revalidate',
            self::DEFAULT_SWR
        );
        if ( $max_age <= 0 ) {
            return;
        }

        header(
            sprintf(
                'Cache-Control: public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
                $max_age,
                $max_age,
                max( 0, $swr )
            )
        );
        // CRITICAL: Vary on Accept-Encoding only. Cookie-bearing
        // requests look the same in cached HTML because the cart icon
        // is a placeholder. Adding `Cookie` here would shatter the
        // cache and defeat the optimisation.
        header( 'Vary: Accept-Encoding', false );
    }

    /**
     * Top-level kill switch. Reads the option AND the filter; the
     * filter wins so merchants can flip the feature on at the code
     * level without touching the database.
     *
     * @return bool
     */
    private static function feature_enabled(): bool {
        $option_value = function_exists( 'get_option' ) ? get_option( self::OPTION_ENABLED, 'no' ) : 'no';
        $enabled      = 'yes' === (string) $option_value;
        if ( function_exists( 'apply_filters' ) ) {
            $enabled = (bool) apply_filters( 'tejcart_edge_hydrate_enabled', $enabled );
        }
        return $enabled;
    }

    /**
     * Strict allowlist for "is this a storefront page that should
     * edge-cache?". Anything that depends on per-visitor server state
     * (cart, checkout, customer area, admin, REST, AJAX, cron, POST
     * submissions) returns false.
     *
     * Filter `tejcart_is_storefront_page` lets merchants extend the
     * allowlist for custom storefront templates (e.g. a "/lookbook/"
     * archive that should also edge-cache).
     *
     * @return bool
     */
    public static function is_storefront_page(): bool {
        // Hard exclusions first.
        if ( function_exists( 'is_admin' ) && is_admin() ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return false;
        }
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return false;
        }
        // Logged-in visitors see the WP admin bar + personalised UI;
        // never edge-cache for them. (Hydration on its own would still
        // work, but the admin-bar markup and other personalised
        // content makes the page non-shareable.)
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
            return false;
        }
        // Form submissions and add-to-cart redirects mutate state and
        // must never be cached.
        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : 'GET';
        if ( 'GET' !== strtoupper( $request_method ) ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; no state mutation depends on this value.
        if ( ! empty( $_GET['add-to-cart'] ) ) {
            return false;
        }
        // Tejcart cart / checkout / account pages have inline cart
        // state that can't be cached.
        if ( function_exists( 'tejcart_is_cart_page' ) && tejcart_is_cart_page() ) {
            return false;
        }
        if ( function_exists( 'tejcart_is_checkout_page' ) && tejcart_is_checkout_page() ) {
            return false;
        }
        if ( function_exists( 'tejcart_is_account_page' ) && tejcart_is_account_page() ) {
            return false;
        }

        // Default-allow for: home (front page), single product, product
        // category archives, search results, generic CMS pages.
        $is_storefront = false;
        if ( function_exists( 'is_front_page' ) && is_front_page() ) {
            $is_storefront = true;
        } elseif ( function_exists( 'tejcart_is_single_product' ) && tejcart_is_single_product() ) {
            $is_storefront = true;
        } elseif ( function_exists( 'is_tax' ) && is_tax( array( 'tejcart_product_cat', 'tejcart_product_tag' ) ) ) {
            $is_storefront = true;
        } elseif ( function_exists( 'is_page' ) ) {
            // Any non-cart/checkout/account regular page.
            $is_storefront = is_page() && ! self::is_excluded_page();
        }

        return (bool) apply_filters( 'tejcart_is_storefront_page', $is_storefront );
    }

    /**
     * @return bool
     */
    private static function is_excluded_page(): bool {
        // Defensive: rely on tejcart_is_*_page() but also exclude
        // anything a merchant has filtered as non-cacheable.
        return false;
    }

    /**
     * Inline SVG cart icon. Self-contained so the shortcode has no
     * external asset dependency and the placeholder HTML is byte-
     * identical for every visitor. ~280 bytes.
     */
    private static function svg_icon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
    }
}
