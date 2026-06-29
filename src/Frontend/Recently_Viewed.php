<?php
/**
 * Recently viewed products tracking.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracks the last N products a customer viewed in a cookie and
 * exposes the [tejcart_recently_viewed] shortcode to display them.
 */
class Recently_Viewed {
    const COOKIE_KEY = 'tejcart_recently_viewed';
    const MAX_ITEMS  = 12;
    const COOKIE_TTL = 1209600;

    /**
     * Resolve the cookie key, applying any site-level rotation override.
     *
     * Defaults to {@see self::COOKIE_KEY}. Sites that need to rotate
     * the cookie (e.g. after a consent/privacy event) can replace it
     * via the `tejcart_recently_viewed_cookie_key` filter.
     *
     * @return string
     */
    public static function cookie_key(): string {
        $key = (string) apply_filters( 'tejcart_recently_viewed_cookie_key', self::COOKIE_KEY );
        return '' !== $key ? $key : self::COOKIE_KEY;
    }

    /**
     * Wire up tracking and the shortcode.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_track_view' ) );
        add_shortcode( 'tejcart_recently_viewed', array( $this, 'render_shortcode' ) );
    }

    /**
     * Detect single product views and append the product ID to the cookie.
     *
     * Audit #95 / 01 #4 — TejCart products are NOT WP posts. The legacy
     * `is_singular('tejcart_product')` check never matched because the
     * plugin doesn't `register_post_type()`. Single-product pages are
     * rendered through the configured shop page with the
     * `tejcart_product_slug` query var (resolved in
     * `Shortcodes::render_products_grid`). The same query var is the
     * authoritative signal here too.
     */
    public function maybe_track_view(): void {
        if ( is_admin() ) {
            return;
        }

        $product_id = $this->resolve_current_product_id();

        // Legacy `?product=ID` parameter (also honoured by
        // `Shortcodes::render_products_grid`) — final fallback when
        // neither the slug query var nor a registered `tejcart_product`
        // post type matches. Kept here (not inside the resolver) because
        // it's a query-string concern rather than slug resolution, and
        // the resolver is shared with non-tracking callers that don't
        // want the legacy ?product= path.
        if ( $product_id <= 0 ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
        }
        if ( $product_id <= 0 ) {
            return;
        }

        if ( function_exists( 'tejcart_has_cookie_consent' ) && ! tejcart_has_cookie_consent() ) {
            return;
        }

        $items = $this->get_items();
        $items = array_diff( $items, array( $product_id ) );
        array_unshift( $items, $product_id );
        $items = array_slice( $items, 0, self::MAX_ITEMS );

        $encoded     = wp_json_encode( $items );
        $cookie_name = self::cookie_key();

        if ( ! headers_sent() ) {
            $secure = is_ssl();
            if ( PHP_VERSION_ID >= 70300 ) {
                setcookie(
                    $cookie_name,
                    $encoded,
                    array(
                        'expires'  => time() + self::COOKIE_TTL,
                        'path'     => COOKIEPATH ? COOKIEPATH : '/',
                        'domain'   => COOKIE_DOMAIN,
                        'secure'   => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    )
                );
            } else {
                setcookie(
                    $cookie_name,
                    $encoded,
                    time() + self::COOKIE_TTL,
                    ( COOKIEPATH ? COOKIEPATH : '/' ) . '; samesite=Lax',
                    COOKIE_DOMAIN,
                    $secure,
                    true
                );
            }
        }
        $_COOKIE[ $cookie_name ] = $encoded;
    }

    /**
     * Resolve the current request's TejCart product ID, if any.
     *
     * Handles both the canonical custom-table product URL (matched via
     * the `tejcart_product_slug` query var registered by
     * {@see \TejCart\Frontend\Product_Permalinks}) and the legacy
     * `tejcart_product`/`product` post-type fallback for sites that
     * still publish products as WP posts.
     *
     * @return int
     */
    private function resolve_current_product_id(): int {
        if ( function_exists( 'get_query_var' ) ) {
            $slug = (string) get_query_var( 'tejcart_product_slug', '' );
            if ( '' !== $slug && class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
                $resolved = \TejCart\Product\Product_Factory::get_by_slug( $slug );
                if ( $resolved && method_exists( $resolved, 'get_id' ) ) {
                    return (int) $resolved->get_id();
                }
            }
        }

        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_queried_object();
            if ( $post && ! empty( $post->ID ) ) {
                $product_types = apply_filters( 'tejcart_product_post_types', array( 'tejcart_product', 'product' ) );
                if ( in_array( $post->post_type, $product_types, true ) ) {
                    return (int) $post->ID;
                }
            }
        }

        return 0;
    }

    /**
     * Get the recently-viewed product IDs.
     *
     * @return int[]
     */
    public function get_items(): array {
        $cookie_name = self::cookie_key();
        if ( empty( $_COOKIE[ $cookie_name ] ) ) {
            return array();
        }

        // Audit #56 / 04 M-4 — bound the cookie size so a malicious
        // client (or a tampered local browser) cannot force a
        // multi-megabyte JSON decode on every product render. 4096
        // bytes is well past any legitimate Recently_Viewed list
        // (~20 int ids × 6 chars each ≈ 130 bytes).
        $raw = (string) wp_unslash( $_COOKIE[ $cookie_name ] );
        if ( strlen( $raw ) > 4096 ) {
            return array();
        }
        $raw   = sanitize_text_field( $raw );
        $items = json_decode( $raw, true );

        return is_array( $items ) ? array_map( 'intval', $items ) : array();
    }

    /**
     * Render the [tejcart_recently_viewed] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML.
     */
    public function render_shortcode( $atts = array() ): string {
        $atts = shortcode_atts(
            array(
                'limit' => 6,
                'title' => __( 'Recently Viewed', 'tejcart' ),
            ),
            $atts,
            'tejcart_recently_viewed'
        );

        $items = array_slice( $this->get_items(), 0, max( 1, (int) $atts['limit'] ) * 2 );
        if ( empty( $items ) ) {
            return '';
        }

        $hide_oos = class_exists( '\\TejCart\\Product\\Stock_Display' )
            ? \TejCart\Product\Stock_Display::hide_out_of_stock()
            : false;

        $rendered = 0;
        $limit    = max( 1, (int) $atts['limit'] );

        ob_start();
        echo '<div class="tejcart-recently-viewed">';
        if ( $atts['title'] ) {
            echo '<h3>' . esc_html( $atts['title'] ) . '</h3>';
        }
        echo '<ul>';
        foreach ( $items as $pid ) {
            if ( $rendered >= $limit ) {
                break;
            }

            $product = class_exists( '\\TejCart\\Product\\Product_Factory' )
                ? \TejCart\Product\Product_Factory::get_product( (int) $pid )
                : null;
            if ( ! $product || 'publish' !== $product->get_status() ) {
                continue;
            }
            if ( $hide_oos && method_exists( $product, 'get_stock_status' ) && 'instock' !== $product->get_stock_status() ) {
                continue;
            }

            $title = $product->get_name();
            $url   = method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : get_permalink( (int) $pid );
            if ( ! $title || ! $url ) {
                continue;
            }
            echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></li>';
            $rendered++;
        }
        echo '</ul>';
        echo '</div>';

        if ( 0 === $rendered ) {
            ob_end_clean();
            return '';
        }

        return ob_get_clean();
    }
}
