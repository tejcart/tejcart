<?php
/**
 * REST API for search suggestions.
 *
 * Exposes `GET /tejcart/v1/search/suggestions?q=` for the storefront
 * autocomplete widget. Public (no auth required) — the endpoint
 * returns only published products.
 *
 * @package TejCart\Tier2\Search
 */

declare(strict_types=1);

namespace TejCart\Tier2\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class REST_Controller {
    private const NAMESPACE_V1 = 'tejcart/v1';

    /** Per-IP burst: 60 requests per 60 seconds. */
    private const BURST_LIMIT  = 60;
    private const BURST_WINDOW = 60;

    /** Per-IP empty-result cap: 20 misses per 5 minutes (anti-enumeration). */
    private const MISS_LIMIT  = 20;
    private const MISS_WINDOW = 300;

    private Search_Service $service;

    public function __construct( Search_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            '/search/suggestions',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'suggestions' ),
                    // Rate limiting: the core tejcart/v1 namespace-level limiter
                    // (REST_API::register_default_rate_limit) enforces 120 req/min
                    // per IP. Filterable via 'tejcart_rest_default_rate_limit'.
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'q' => array(
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => static function ( $value ): bool {
                                return is_string( $value ) && mb_strlen( trim( $value ) ) >= 2;
                            },
                        ),
                        'limit' => array(
                            'type'              => 'integer',
                            'default'           => 8,
                            'minimum'           => 1,
                            'maximum'           => 20,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Handle `GET /tejcart/v1/search/suggestions?q=...`
     */
    public function suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_limited_response = $this->check_rate_limit();
        if ( $rate_limited_response !== null ) {
            return $rate_limited_response;
        }

        $query = (string) $request->get_param( 'q' );
        $limit = (int) $request->get_param( 'limit' );

        $results = $this->service->search( $query, $limit );

        if ( empty( $results ) ) {
            $miss_response = $this->check_miss_limit();
            if ( $miss_response !== null ) {
                return $miss_response;
            }
        }

        $shop_page_id  = (int) get_option( 'tejcart_shop_page_id', 0 );
        $shop_base     = $shop_page_id > 0 ? (string) get_permalink( $shop_page_id ) : '';
        $use_pretty    = '' !== (string) get_option( 'permalink_structure', '' )
            && $shop_page_id > 0
            && '' !== $shop_base
            && false !== $shop_base;
        $shop_is_front = $shop_page_id > 0
            && (int) get_option( 'page_on_front', 0 ) === $shop_page_id;

        $suggestions = array();
        foreach ( $results as $item ) {
            $slug = (string) ( $item['slug'] ?? '' );
            $url  = '';

            if ( $use_pretty && '' !== $slug ) {
                if ( $shop_is_front ) {
                    $url = home_url(
                        '/' . \TejCart\Frontend\Product_Permalinks::get_front_page_prefix() . '/' . $slug . '/'
                    );
                } else {
                    $url = trailingslashit( $shop_base ) . $slug . '/';
                }
            } elseif ( $item['id'] ) {
                $base = '' !== $shop_base ? $shop_base : home_url( '/' );
                $url  = add_query_arg( 'product', $item['id'], $base );
            }

            // The search index stores raw BASE-currency prices (it is shared
            // across all visitors and must stay currency-agnostic). Convert to
            // the visitor's active currency through the same product-price
            // filters the storefront uses, so the autocomplete dropdown shows
            // the same converted price as the product/cart pages instead of the
            // base amount wearing the active currency's symbol. Passthrough on a
            // single-currency store.
            $item_price = is_numeric( $item['price'] )
                ? (float) apply_filters( 'tejcart_product_get_price', (float) $item['price'], null )
                : $item['price'];
            $item_sale  = is_numeric( $item['sale_price'] )
                ? (float) apply_filters( 'tejcart_product_get_sale_price', (float) $item['sale_price'], null )
                : $item['sale_price'];

            $suggestion = array(
                'id'    => $item['id'],
                'name'  => $item['name'],
                'sku'   => $item['sku'],
                'price' => $item_price,
                'slug'  => $slug,
                'url'   => $url,
                'image' => '',
            );

            if ( $item['image_id'] && function_exists( 'wp_get_attachment_image_url' ) ) {
                $suggestion['image'] = (string) wp_get_attachment_image_url( $item['image_id'], 'thumbnail' );
            }

            // Treat the product as on-sale only when the sale price is a
            // real discount, mirroring Abstract_Product::is_on_sale(): a
            // non-empty numeric sale price that is strictly greater than 0
            // and strictly less than the regular price. Legacy/import rows
            // frequently store a zero or empty sale_price for products that
            // are not on sale; without this guard those rendered as a bogus
            // "$0.00" strikethrough sale in the dropdown.
            $sale_price = $item_sale;
            $on_sale    = null !== $sale_price
                && is_numeric( $sale_price )
                && (float) $sale_price > 0
                && is_numeric( $item_price )
                && (float) $sale_price < (float) $item_price;

            if ( $on_sale && function_exists( 'tejcart_price' ) ) {
                $suggestion['price_html'] = '<del>' . tejcart_price( (float) $item_price ) . '</del> <ins>' . tejcart_price( (float) $sale_price ) . '</ins>';
            } elseif ( function_exists( 'tejcart_price' ) ) {
                $suggestion['price_html'] = tejcart_price( (float) $item_price );
            } else {
                $suggestion['price_html'] = $item_price;
            }

            $suggestions[] = $suggestion;
        }

        return new \WP_REST_Response(
            array(
                'results' => $suggestions,
                'total'   => count( $suggestions ),
                'query'   => $query,
            ),
            200
        );
    }

    /**
     * Check per-IP burst rate limit. Returns a 429 response when breached,
     * or null when the request may proceed. Fail-open: if the core
     * Rate_Limiter class is unavailable, the request is allowed (search is
     * lower risk than order/payment data).
     */
    private function check_rate_limit(): ?\WP_REST_Response {
        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return null; // Fail-open — search is lower risk.
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();

        $burst_blocked = \TejCart\Security\Rate_Limiter::check_and_record(
            'search_public',
            $ip,
            self::BURST_LIMIT,
            self::BURST_WINDOW
        );

        if ( $burst_blocked ) {
            return $this->make_429_response( self::BURST_WINDOW );
        }

        return null;
    }

    /**
     * Record an empty-result hit and return a 429 response when the miss
     * cap is exceeded. Returns null when the request may proceed.
     */
    private function check_miss_limit(): ?\WP_REST_Response {
        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return null;
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();

        $miss_blocked = \TejCart\Security\Rate_Limiter::check_and_record(
            'search_public_miss',
            $ip,
            self::MISS_LIMIT,
            self::MISS_WINDOW
        );

        if ( $miss_blocked ) {
            return $this->make_429_response( self::MISS_WINDOW );
        }

        return null;
    }

    /**
     * Build a WP_REST_Response with 429 status and Retry-After header.
     */
    private function make_429_response( int $retry_after ): \WP_REST_Response {
        $response = new \WP_REST_Response(
            array(
                'code'    => 'rate_limit_exceeded',
                'message' => __( 'Too many requests. Please try again later.', 'tejcart' ),
                'data'    => array( 'status' => 429 ),
            ),
            429
        );
        $response->header( 'Retry-After', (string) max( 1, $retry_after ) );

        return $response;
    }
}
