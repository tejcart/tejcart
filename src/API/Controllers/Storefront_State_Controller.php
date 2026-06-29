<?php
/**
 * Storefront state REST endpoint.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Cart\Cart;
use TejCart\Money\Currency;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `GET /wp-json/tejcart/v1/storefront/state`
 *
 * Minimal, cacheable, PII-free fragment used by edge-cached storefront
 * pages to "hydrate" the per-visitor bits (cart icon, mini-cart line
 * count, login pill) after the cached HTML has rendered.
 *
 * Why this endpoint exists
 * ========================
 * The classic storefront caching problem: a page-cached storefront still has
 * "View cart (3)" inlined into the HTML, so the cache key has to vary
 * by visitor session. Page caches that vary per session aren't really
 * caches — they're per-visitor renders. This endpoint breaks that
 * coupling: the storefront HTML is the SAME for every visitor (no cart
 * data inlined, just `<tejcart-fragment data-region="cart-icon" />`
 * placeholders), and a single tiny REST call after page load fills in
 * the per-visitor state. The HTML itself becomes edge-cacheable.
 *
 * PR #6 of the perf roadmap. PR #7 (storefront page edge cache + JS
 * hydration) consumes this endpoint.
 *
 * Response shape (PII-free)
 * =========================
 *  {
 *    "cart": {
 *      "item_count":          int,
 *      "subtotal_minor":      int,    // integer cents
 *      "subtotal_formatted":  string, // localised display string
 *      "currency":            string, // ISO 4217
 *      "items": [
 *        {
 *          "key":                  string (64-char SHA),
 *          "product_id":           int,
 *          "name":                 string,
 *          "quantity":             int,
 *          "line_total_minor":     int,
 *          "line_total_formatted": string,
 *          "thumb_url":            string|null
 *        },
 *        ...
 *      ]
 *    },
 *    "user": {
 *      "is_logged_in": bool
 *    },
 *    "currency": string,
 *    "ts":       int  // server unix timestamp (debug aid; never used by client)
 *  }
 *
 * Deliberately EXCLUDED from the response:
 *  - email, phone, full name, billing/shipping addresses
 *  - saved payment methods
 *  - coupon codes (codes can leak partner-program flags; the *count*
 *    is fine but the strings stay server-side)
 *  - any wp_users column other than is_logged_in
 *
 * Edge-cache contract
 * ===================
 * The endpoint emits HTTP cache-control headers chosen per-request:
 *
 *   - Anonymous visitor with empty cart →
 *       Cache-Control: public, max-age=60, s-maxage=60
 *       Vary: Cookie
 *     This is the "cold visitor" response and the most-cacheable case.
 *     Edge tiers (Cloudflare, Varnish) can serve a single response to
 *     every cold visitor for 60 s.
 *
 *   - Anyone with a non-empty cart OR a logged-in session →
 *       Cache-Control: private, no-store, must-revalidate
 *     Per-visitor; never edge-cached.
 *
 * ETag is always emitted (sha256 of the response body, truncated to
 * 16 hex). When the browser repeats the request with `If-None-Match`,
 * we return a 304 Not Modified with no body — this is the warm-fragment
 * fast path that keeps repeat-page-view costs near zero.
 */
class Storefront_State_Controller extends WP_REST_Controller {
    /**
     * @var string
     */
    protected $namespace = 'tejcart/v1';

    /**
     * @var string
     */
    protected $rest_base = 'storefront/state';

    /**
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_state' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Public endpoint — anyone can read their own storefront state.
     * The response is naturally session-scoped (cart contents come
     * from the current visitor's cart; user.is_logged_in from the
     * current request's auth state). No nonce required because the
     * endpoint is purely read-only and exposes no PII.
     *
     * Rate limit applies via the namespace-wide default shim
     * registered in {@see \TejCart\API\REST_API::register_default_rate_limit()}.
     *
     * @return bool
     */
    public function public_permissions_check(): bool {
        return true;
    }

    /**
     * Build and return the storefront state. Emits cache-control +
     * ETag headers tuned to the visitor's cart / auth state.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_state( WP_REST_Request $request ): WP_REST_Response {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : new Cart();

        $currency = function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : 'USD';
        if ( ! is_string( $currency ) || ! Currency::is_valid_shape( strtoupper( $currency ) ) ) {
            $currency = 'USD';
        }
        $currency = strtoupper( $currency );

        $items = $this->build_items( $cart, $currency );

        $subtotal_minor      = $this->to_minor( (float) $cart->get_subtotal(), $currency );
        $subtotal_formatted  = function_exists( 'tejcart_price' )
            ? (string) tejcart_price( (float) $cart->get_subtotal(), $currency )
            : (string) number_format( (float) $cart->get_subtotal(), 2, '.', '' );

        $is_logged_in = function_exists( 'is_user_logged_in' ) ? (bool) is_user_logged_in() : false;
        $item_count   = (int) $cart->get_item_count();

        $body = array(
            'cart' => array(
                'item_count'         => $item_count,
                'subtotal_minor'     => $subtotal_minor,
                'subtotal_formatted' => $subtotal_formatted,
                'currency'           => $currency,
                'items'              => $items,
            ),
            'user' => array(
                'is_logged_in' => $is_logged_in,
            ),
            'currency' => $currency,
            'ts'       => time(),
        );

        $response = new WP_REST_Response( $body, 200 );

        // ETag = stable hash of the *body sans timestamp*. Including
        // `ts` would invalidate the ETag every second and defeat the
        // 304 fast path. We emit `ts` for client-side debugging only.
        $etag_material = $body;
        unset( $etag_material['ts'] );
        $etag = '"' . substr( hash( 'sha256', wp_json_encode( $etag_material ) ?: '' ), 0, 16 ) . '"';
        $response->header( 'ETag', $etag );

        // Conditional GET: client sent If-None-Match matching our ETag
        // → 304 Not Modified, empty body. Saves bandwidth + parse cost
        // on repeat page views in the same session.
        $inm = (string) $request->get_header( 'If-None-Match' );
        if ( '' !== $inm && self::etag_matches( $inm, $etag ) ) {
            $response = new WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
        }

        // Cache-control: choose per-request. Anonymous + empty cart
        // gets the public-cacheable header so an edge tier can collapse
        // every cold visitor's request to a single response. Anything
        // else stays per-visitor.
        if ( ! $is_logged_in && 0 === $item_count ) {
            $response->header( 'Cache-Control', 'public, max-age=60, s-maxage=60' );
        } else {
            $response->header( 'Cache-Control', 'private, no-store, must-revalidate' );
        }

        // Vary: Cookie regardless. Even when the response is publicly
        // cacheable, an edge tier MUST treat any cookie change as a
        // separate cache key — a visitor whose login cookie shows up
        // mid-session must NOT see the anonymous-empty-cart cached
        // response.
        // Audit M-35: added Authorization so edge caches distinguish
        // authenticated API-key consumers from anonymous visitors.
        $response->header( 'Vary', 'Cookie, Accept-Encoding, Authorization' );

        // Content-type pin so misbehaving intermediaries can't decide
        // to gzip-decompress + re-encode in a way that breaks our
        // ETag check.
        $response->header( 'Content-Type', 'application/json; charset=utf-8' );

        return $response;
    }

    /**
     * Build the per-line-item array. Duck-typed against the cart
     * surface (`get_items()` returning objects with `to_array()`)
     * rather than nominally typed against the Cart class — keeps
     * the controller test-friendly and doesn't lock in a class
     * dependency that future refactors would have to coordinate.
     *
     * @param object $cart     Anything with a `get_items()` method.
     * @param string $currency
     * @return array<int, array<string, mixed>>
     */
    private function build_items( $cart, string $currency ): array {
        $items = array();

        foreach ( (array) $cart->get_items() as $item ) {
            $row = is_callable( array( $item, 'to_array' ) ) ? (array) $item->to_array() : array();

            $key        = isset( $row['key'] ) ? (string) $row['key'] : '';
            $product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
            $name       = isset( $row['name'] ) ? (string) $row['name'] : '';
            $quantity   = isset( $row['quantity'] ) ? max( 1, (int) $row['quantity'] ) : 1;
            $line_total = isset( $row['line_total'] ) ? (float) $row['line_total'] : 0.0;

            // Thumbnail comes from the product, not the cart row. Fail
            // silently if the helper isn't loaded (rare CLI / cron
            // contexts) — better to drop the thumb than to 500 the
            // whole hydration.
            $thumb = null;
            if ( $product_id > 0 && function_exists( 'tejcart_get_product' ) ) {
                $product = tejcart_get_product( $product_id );
                // Audit M-45: only emit thumbnail for published products
                // so draft/private product images don't leak via the
                // edge-cached storefront hydration endpoint.
                $is_published = is_object( $product ) && method_exists( $product, 'get_status' ) && 'publish' === $product->get_status();
                if ( $is_published && is_callable( array( $product, 'get_image_id' ) ) ) {
                    $image_id = (int) $product->get_image_id();
                    if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
                        $maybe_url = wp_get_attachment_image_url( $image_id, 'tejcart-product-thumb' );
                        if ( is_string( $maybe_url ) && '' !== $maybe_url ) {
                            $thumb = $maybe_url;
                        }
                    }
                }
            }

            $items[] = array(
                'key'                  => $key,
                'product_id'           => $product_id,
                'name'                 => $name,
                'quantity'             => $quantity,
                'line_total_minor'     => $this->to_minor( $line_total, $currency ),
                'line_total_formatted' => function_exists( 'tejcart_price' )
                    ? (string) tejcart_price( $line_total, $currency )
                    : (string) number_format( $line_total, 2, '.', '' ),
                'thumb_url'            => $thumb,
            );
        }

        return $items;
    }

    /**
     * Convert a major-unit decimal to integer minor units (cents,
     * pence, etc.). Currencies with non-100 minor units (JPY, KRW,
     * BHD, etc.) are handled via the standard ISO 4217 exponent.
     *
     * @param float  $amount
     * @param string $currency
     * @return int
     */
    private function to_minor( float $amount, string $currency ): int {
        // JPY / KRW / VND / etc. have 0 minor units.
        $zero_decimal = array( 'JPY', 'KRW', 'VND', 'CLP', 'PYG', 'XAF', 'XOF', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'VUV', 'XPF' );
        // BHD / IQD / JOD / KWD / OMR / TND / LYD have 3 minor units.
        $three_decimal = array( 'BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND', 'LYD' );

        if ( in_array( $currency, $zero_decimal, true ) ) {
            return (int) round( $amount );
        }
        if ( in_array( $currency, $three_decimal, true ) ) {
            return (int) round( $amount * 1000 );
        }
        return (int) round( $amount * 100 ); // allowed:multiplier 2-decimal default branch after explicit 0 / 3 checks
    }

    /**
     * Compare client's `If-None-Match` against our generated ETag.
     * Tolerates the standard variations: weak prefix `W/`, multiple
     * comma-separated values, and the wildcard `*`.
     *
     * @param string $if_none_match Raw header value.
     * @param string $etag          Strong ETag we just generated.
     * @return bool
     */
    private static function etag_matches( string $if_none_match, string $etag ): bool {
        $candidates = array_map( 'trim', explode( ',', $if_none_match ) );
        foreach ( $candidates as $candidate ) {
            if ( '*' === $candidate ) {
                return true;
            }
            // Strip optional `W/` weak indicator — for our purposes a
            // weak match is fine: the client is signalling "I have the
            // same body even if not byte-identical metadata," and our
            // ETag IS the body hash.
            $candidate = preg_replace( '#^W/#i', '', $candidate );
            if ( $candidate === $etag ) {
                return true;
            }
        }
        return false;
    }
}
