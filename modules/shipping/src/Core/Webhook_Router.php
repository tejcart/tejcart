<?php
/**
 * REST endpoint that fans inbound carrier webhooks out to per-driver handlers.
 *
 * Endpoints:
 *   POST /wp-json/tejcart-shipping/v1/webhook/{carrier}
 *
 * Per-carrier handlers are registered via the
 * `tejcart_shipping_webhook_handlers` filter:
 *
 *   add_filter( 'tejcart_shipping_webhook_handlers', function ( $h ) {
 *       $h['easypost'] = function ( WP_REST_Request $req ) { ... };
 *       return $h;
 *   } );
 *
 * Handlers MUST verify the carrier's signature themselves (the
 * signature scheme is carrier-specific) and either return a
 * `WP_REST_Response` on success or a `WP_Error` with a 4xx status on
 * failure.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Webhook_Router {
    public const NAMESPACE = 'tejcart-shipping/v1';

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        if ( ! function_exists( 'register_rest_route' ) ) {
            return;
        }
        register_rest_route(
            self::NAMESPACE,
            '/webhook/(?P<carrier>[a-z0-9_]+)',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'dispatch' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'carrier' => array( 'sanitize_callback' => 'sanitize_key' ),
                ),
            )
        );
    }

    /**
     * @param mixed $request WP_REST_Request (typed loosely so the test
     *                       suite can pass a stub without pulling WP).
     */
    public function dispatch( $request ) {
        // Audit M-44 (API M-2): rate-limit unauthenticated webhook
        // endpoint so an unsigned-handler bug can't be exploited as
        // a free amplification surface.
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
            if ( \TejCart\Security\Rate_Limiter::check_and_record( 'shipping_webhook', $ip, 60, 60 ) ) {
                return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
            }
        }

        $carrier = is_object( $request ) && method_exists( $request, 'get_param' )
            ? (string) $request->get_param( 'carrier' )
            : '';

        $handlers = apply_filters( 'tejcart_shipping_webhook_handlers', array() );
        if ( ! is_array( $handlers ) || ! isset( $handlers[ $carrier ] ) || ! is_callable( $handlers[ $carrier ] ) ) {
            return new \WP_Error(
                'tejcart_shipping_webhook_unknown_carrier',
                sprintf( 'No webhook handler registered for "%s".', $carrier ),
                array( 'status' => 404 )
            );
        }

        try {
            $result = call_user_func( $handlers[ $carrier ], $request );
        } catch ( \Throwable $e ) {
            return new \WP_Error(
                'tejcart_shipping_webhook_handler_error',
                $e->getMessage(),
                array( 'status' => 500 )
            );
        }

        /**
         * Fires after a webhook is dispatched. Useful for centralised
         * audit logging without coupling to each carrier handler.
         *
         * @param string $carrier
         * @param mixed  $request
         * @param mixed  $result
         */
        do_action( 'tejcart_shipping_webhook_dispatched', $carrier, $request, $result );

        return $result;
    }
}
