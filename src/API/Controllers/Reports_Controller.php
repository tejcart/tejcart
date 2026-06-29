<?php
/**
 * Reports REST API Controller.
 *
 * Exposes the precomputed `tejcart_daily_summary` rollup over the REST API
 * so headless storefronts, mobile-app dashboards, and BI tooling can pull
 * sales aggregates without screen-scraping the admin Reports page or
 * holding a wp-admin session for the CSV-export URL.
 *
 * Audit ID: 07 F-9.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use TejCart\Core\Capabilities;
use TejCart\Reports\Daily_Summary;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/reports/*.
 *
 * Currently exposes:
 *   GET /tejcart/v1/reports/daily-summary?from=&to=&currency=
 *     Range-summed bucket (revenue, orders, refunds, tax) from
 *     wp_tejcart_daily_summary, read via {@see Daily_Summary::read_range()}.
 *
 * Auth follows the same pattern as Orders_Controller — MANAGE_ORDERS via
 * {@see Capabilities::check()}, which the existing API_Keys / REST nonce
 * stack will exercise. Read endpoint, capped at 60 req/min via the shared
 * {@see REST_Rate_Limit} trait.
 */
class Reports_Controller extends WP_REST_Controller {
    use REST_Rate_Limit;

    /**
     * Route namespace.
     *
     * @var string
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'reports';

    /**
     * Register the report routes.
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/daily-summary',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'permission_callback' ),
                    'args'                => $this->prepare_args(),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * Argument schema for the daily-summary endpoint.
     *
     * Surfaced as the route's `args` so WordPress's REST argument validation
     * runs **before** the callback. Centralised so the unit test below can
     * pin the contract (required flag, format, default) without re-reading
     * register_routes().
     *
     * @return array<string, array<string, mixed>>
     */
    public function prepare_args(): array {
        return array(
            'from'     => array(
                'description'       => __( 'Inclusive start date (YYYY-MM-DD). Interpreted in the WP site timezone — same as the admin Reports date picker.', 'tejcart' ),
                'type'              => 'string',
                'format'            => 'date',
                'required'          => true,
                // Reject anything that's not YYYY-MM-DD up front so the
                // callback doesn't have to redo the validation. The
                // sanitize_callback strips slashes / whitespace before
                // the pattern check.
                'sanitize_callback' => array( $this, 'sanitize_date' ),
                'validate_callback' => array( $this, 'validate_date' ),
            ),
            'to'       => array(
                'description'       => __( 'Inclusive end date (YYYY-MM-DD). Interpreted in the WP site timezone.', 'tejcart' ),
                'type'              => 'string',
                'format'            => 'date',
                'required'          => true,
                'sanitize_callback' => array( $this, 'sanitize_date' ),
                'validate_callback' => array( $this, 'validate_date' ),
            ),
            'currency' => array(
                'description'       => __( 'ISO-4217 currency code for the bucket. Defaults to the store currency.', 'tejcart' ),
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => array( $this, 'sanitize_currency' ),
            ),
        );
    }

    /**
     * Permission gate for the reports endpoints.
     *
     * Mirrors Orders_Controller — anyone who can list/edit orders can read
     * the aggregated reports (same data the admin Reports page already
     * surfaces). Falls back to manage_options for legacy installs that
     * haven't reset role definitions since the cap matrix was introduced.
     *
     * Returns true (allow), false (deny), or a WP_Error with a 429 status
     * when the per-(user|IP) rate limit fires.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function permission_callback( $request ) {
        if ( ! Capabilities::check( Capabilities::MANAGE_ORDERS ) ) {
            return new WP_Error(
                'tejcart_rest_cannot_view_reports',
                __( 'Sorry, you are not allowed to view reports.', 'tejcart' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $this->enforce_rest_rate_limit( $request, 'reports', 60 );
    }

    /**
     * GET handler — sum the bucket over [from, to] for the given currency
     * and return the JSON payload.
     *
     * Returns a zeroed payload (with `days_present = 0`) when the requested
     * range has no precomputed buckets yet — the admin dashboard treats
     * this as a soft miss and falls back to a live SUM. For an external
     * API consumer the 200 + zeroes shape is more predictable than a 404,
     * and lets clients chart the range without special-casing the empty
     * case.
     *
     * @param WP_REST_Request $request
     * @return \WP_REST_Response|WP_Error
     */
    public function get_items( $request ) {
        $from     = (string) $request->get_param( 'from' );
        $to       = (string) $request->get_param( 'to' );
        $currency = (string) $request->get_param( 'currency' );

        if ( '' === $currency ) {
            $currency = (string) ( function_exists( 'get_option' ) ? get_option( 'tejcart_currency', 'USD' ) : 'USD' );
            $currency = '' === $currency ? 'USD' : $currency;
        }

        if ( strcmp( $from, $to ) > 0 ) {
            return new WP_Error(
                'tejcart_rest_invalid_range',
                __( 'The `from` date must be on or before the `to` date.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $row = Daily_Summary::read_range( $from, $to, $currency );

        // The read_range() contract is "null when no row exists (caller
        // falls back to live SUM)". For a public API we don't want to
        // ever return null; emit a zeroed payload with days_present=0 so
        // clients can chart the range uniformly.
        if ( null === $row ) {
            $row = array(
                'revenue'      => 0.0,
                'order_count'  => 0,
                'refund_total' => 0.0,
                'refund_count' => 0,
                'coupon_count' => 0,
                'tax_total'    => 0.0,
                'days_present' => 0,
            );
        }

        $payload = array(
            'from'         => $from,
            'to'           => $to,
            'currency'     => $currency,
            'revenue'      => (float) ( $row['revenue']      ?? 0.0 ),
            'order_count'  => (int)   ( $row['order_count']  ?? 0 ),
            'refund_total' => (float) ( $row['refund_total'] ?? 0.0 ),
            'refund_count' => (int)   ( $row['refund_count'] ?? 0 ),
            'coupon_count' => (int)   ( $row['coupon_count'] ?? 0 ),
            'tax_total'    => (float) ( $row['tax_total']    ?? 0.0 ),
            'days_present' => (int)   ( $row['days_present'] ?? 0 ),
        );

        return rest_ensure_response( $payload );
    }

    /**
     * Strip slashes / whitespace from an incoming date param. Pattern
     * validation happens in {@see validate_date()}.
     *
     * @param mixed $value
     * @return string
     */
    public function sanitize_date( $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        return trim( (string) $value );
    }

    /**
     * Strict YYYY-MM-DD validator. Rejects month/day out of range and
     * non-numeric input. Returns true for "ok"; a WP_Error with status
     * 400 for rejection so WordPress's argument-validation layer emits a
     * proper REST error instead of dropping into the callback.
     *
     * @param mixed           $value
     * @param WP_REST_Request $request
     * @param string          $param
     * @return true|WP_Error
     */
    public function validate_date( $value, $request = null, $param = '' ) {
        $value = $this->sanitize_date( $value );
        if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return new WP_Error(
                'tejcart_rest_invalid_date',
                sprintf(
                    /* translators: %s: parameter name (e.g. "from"). */
                    __( 'The %s parameter must be a YYYY-MM-DD date string.', 'tejcart' ),
                    is_string( $param ) ? $param : 'date'
                ),
                array( 'status' => 400 )
            );
        }

        [ $y, $m, $d ] = array_map( 'intval', explode( '-', $value ) );
        if ( ! checkdate( $m, $d, $y ) ) {
            return new WP_Error(
                'tejcart_rest_invalid_date',
                sprintf(
                    /* translators: %s: parameter name (e.g. "from"). */
                    __( 'The %s parameter is not a valid calendar date.', 'tejcart' ),
                    is_string( $param ) ? $param : 'date'
                ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    /**
     * Uppercase + 3-letter ISO-4217-ish sanitiser. Empty input passes
     * through to be filled with the store currency by {@see get_items()}.
     *
     * @param mixed $value
     * @return string
     */
    public function sanitize_currency( $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = strtoupper( trim( (string) $value ) );
        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $value ) ) {
            return '';
        }
        return $value;
    }
}
