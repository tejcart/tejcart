<?php
/**
 * REST API bootstrap.
 *
 * @package TejCart\API
 */

declare( strict_types=1 );

namespace TejCart\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\API\Controllers\Products_Controller;
use TejCart\API\Controllers\Orders_Controller;
use TejCart\API\Controllers\Cart_Controller;
use TejCart\API\Controllers\Customers_Controller;
use TejCart\API\Controllers\Coupons_Controller;
use TejCart\API\Controllers\Product_Categories_Controller;
use TejCart\API\Controllers\Product_Tags_Controller;
use TejCart\API\Controllers\Order_Notes_Controller;
use TejCart\API\Controllers\Order_Refunds_Controller;
use TejCart\API\Controllers\Tax_Classes_Controller;
use TejCart\API\Controllers\Tax_Rates_Controller;
use TejCart\API\Controllers\Shipping_Zones_Controller;
use TejCart\API\Controllers\Shipping_Zone_Locations_Controller;
use TejCart\API\Controllers\Shipping_Zone_Methods_Controller;
use TejCart\API\Controllers\Payment_Gateways_Controller;
use TejCart\API\Controllers\System_Status_Controller;
use TejCart\API\Controllers\Health_Controller;
use TejCart\API\Controllers\Data_Controller;
use TejCart\API\Controllers\Reports_Controller;
use TejCart\API\Controllers\Settings_Controller;
use TejCart\API\Controllers\Storefront_State_Controller;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers all TejCart REST API controllers.
 */
class REST_API {
    /**
     * Controller class names.
     *
     * @var string[]
     */
    private $controllers = array(
        Products_Controller::class,
        Orders_Controller::class,
        Cart_Controller::class,
        Customers_Controller::class,
        Coupons_Controller::class,
        Product_Categories_Controller::class,
        Product_Tags_Controller::class,
        Order_Notes_Controller::class,
        Order_Refunds_Controller::class,
        Tax_Classes_Controller::class,
        Tax_Rates_Controller::class,
        Shipping_Zones_Controller::class,
        Shipping_Zone_Locations_Controller::class,
        Shipping_Zone_Methods_Controller::class,
        Payment_Gateways_Controller::class,
        System_Status_Controller::class,
        Health_Controller::class,
        Data_Controller::class,
        Settings_Controller::class,
        // PR #6: storefront-state fragment for edge-cached storefront
        // pages. Tiny PII-free response keyed on (cart, login state)
        // with conditional-GET / cache-control headers tuned per-request.
        Storefront_State_Controller::class,
        // Audit #78 / 07 F-9: JSON access to the precomputed
        // wp_tejcart_daily_summary rollup so headless storefronts and
        // mobile dashboards have an API surface for sales/refund/tax
        // aggregates without screen-scraping admin.php.
        Reports_Controller::class,
    );

    /**
     * Instantiate each controller and register its routes.
     *
     * Called from {@see \TejCart\Core\TejCart::register_rest_routes()} on
     * the `rest_api_init` action — no separate init() step is needed on
     * this class, which is already constructed lazily through the DI
     * container.
     *
     * @return void
     */
    public function register_routes() {
        $enabled = 'yes' === get_option( 'tejcart_api_enabled', 'yes' );
        if ( ! apply_filters( 'tejcart_rest_api_enabled', $enabled ) ) {
            return;
        }

        foreach ( $this->controllers as $controller_class ) {
            if ( class_exists( $controller_class ) ) {
                $controller = new $controller_class();
                $controller->register_routes();
            }
        }

        $this->register_default_rate_limit();
    }

    /**
     * L-04: Apply a default per-(user|IP, namespace) rate limit to every
     * `tejcart/*` REST route as a defence-in-depth shim. Per-controller
     * limits (Cart, Order_Refunds, Webhook, …) continue to apply on top
     * — this shim only catches routes that have no explicit cap.
     *
     * The default budget — 120 requests / 60s — is filterable via
     * `tejcart_rest_default_rate_limit`. Returning <= 0 disables the
     * shim entirely (e.g. for hosts that prefer an edge-tier limit such
     * as Cloudflare Rules or fastcgi_cache).
     */
    private function register_default_rate_limit(): void {
        if ( has_filter( 'rest_pre_dispatch', array( $this, 'maybe_enforce_default_rate_limit' ) ) ) {
            return;
        }
        add_filter( 'rest_pre_dispatch', array( $this, 'maybe_enforce_default_rate_limit' ), 10, 3 );
    }

    /**
     * Enforce the default REST rate limit. Returns the original
     * $result untouched unless the bucket is full, in which case it
     * short-circuits the dispatch with a 429 WP_Error.
     *
     * @param mixed            $result  Existing pre-dispatch result.
     * @param \WP_REST_Server  $server  REST server instance.
     * @param \WP_REST_Request $request Inbound request.
     * @return mixed
     */
    public function maybe_enforce_default_rate_limit( $result, $server, $request ) {
        if ( null !== $result ) {
            return $result;
        }

        $route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        if ( '' === $route || 0 !== strpos( ltrim( $route, '/' ), 'tejcart/' ) ) {
            return $result;
        }

        $segments = explode( '/', trim( $route, '/' ), 3 );
        $namespace = isset( $segments[0], $segments[1] ) ? $segments[0] . '/' . $segments[1] : 'tejcart/v1';

        $defaults = array( 120, MINUTE_IN_SECONDS );
        /**
         * Filter the default REST API rate limit (requests, window seconds).
         *
         * Per-controller limits remain in force on top of this shim.
         * Returning a non-positive request count disables the global
         * shim — e.g. when an upstream layer (Cloudflare Rules,
         * fastcgi_cache) handles throttling.
         *
         * @param int[]            $defaults [max_requests, window_seconds]
         * @param string           $namespace REST namespace (e.g. tejcart/v1).
         * @param \WP_REST_Request $request   Inbound request.
         */
        $config = (array) apply_filters( 'tejcart_rest_default_rate_limit', $defaults, $namespace, $request );
        $max    = isset( $config[0] ) ? (int) $config[0] : 0;
        $window = isset( $config[1] ) ? (int) $config[1] : (int) MINUTE_IN_SECONDS;
        if ( $max <= 0 || $window <= 0 ) {
            return $result;
        }

        $ip   = class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ? \TejCart\Security\Rate_Limiter::get_client_ip() : 'unknown';
        $user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $id   = $user . '|' . $ip . '|' . $namespace;

        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            return $result;
        }

        $limited = \TejCart\Security\Rate_Limiter::check_and_record( 'rest_default', $id, $max, $window );
        if ( ! $limited ) {
            return $result;
        }

        return new \WP_Error(
            'tejcart_rest_rate_limited',
            __( 'Too many requests. Please slow down and try again.', 'tejcart' ),
            array(
                'status'  => 429,
                'headers' => array( 'Retry-After' => (string) $window ),
            )
        );
    }
}
