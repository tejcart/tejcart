<?php
/**
 * REST rate limit helper.
 *
 * @package TejCart\API
 */

declare( strict_types=1 );

namespace TejCart\API;

use TejCart\Security\Rate_Limiter;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait applying per-user + per-IP rate limits to admin REST endpoints.
 *
 * Read endpoints default to 60 req/min; writes to 30 req/min. Limits are
 * filterable via the tejcart_rate_limit_{endpoint} filter so they can be
 * tuned without code edits.
 */
trait REST_Rate_Limit {
    /**
     * Enforce a 60s rate limit keyed on user id + client IP.
     *
     * Returns true when within limits, or a 429 WP_Error when exceeded. The
     * WP_Error carries a Retry-After header via its data array so the REST
     * server serializes it for clients.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request  REST request.
     * @param string          $endpoint Endpoint key used for the filter name.
     * @param int             $limit    Default requests-per-minute.
     * @return true|WP_Error
     */
    protected function enforce_rest_rate_limit( $request, $endpoint, $limit ) {
        $method    = method_exists( $request, 'get_method' ) ? $request->get_method() : 'GET';
        $is_write  = in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
        $effective = $is_write ? min( $limit, 30 ) : $limit;

        /**
         * Filter the per-minute rate limit for this REST endpoint.
         *
         * @since 1.0.0
         *
         * @param int             $effective Request limit per 60-second window.
         * @param string          $method    HTTP method.
         * @param WP_REST_Request $request   REST request.
         */
        $effective = (int) apply_filters( 'tejcart_rate_limit_' . $endpoint, $effective, $method, $request );

        if ( $effective <= 0 ) {
            return true;
        }

        $ip   = class_exists( Rate_Limiter::class ) ? Rate_Limiter::get_client_ip() : 'unknown';
        $user = get_current_user_id();
        $id   = $user . '|' . $ip . '|' . $method;

        $limited = Rate_Limiter::check_and_record(
            'rest_' . $endpoint,
            $id,
            $effective,
            MINUTE_IN_SECONDS
        );

        if ( $limited ) {
            return new WP_Error(
                'tejcart_rate_limited',
                __( 'Too many requests. Please slow down and try again.', 'tejcart' ),
                array(
                    'status'  => 429,
                    'headers' => array( 'Retry-After' => (string) MINUTE_IN_SECONDS ),
                )
            );
        }

        return true;
    }
}
