<?php
/**
 * Public, narrow, IP-allowlisted health endpoint (S-8).
 *
 * Designed for load-balancer / Kubernetes liveness/readiness probes and
 * uptime-monitor pollers that cannot authenticate. Returns 200 with a
 * one-line JSON status when DB + object cache + cron lag are within
 * thresholds, 503 otherwise.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Health_Controller extends WP_REST_Controller {

    protected $namespace = 'tejcart/v1';
    protected $rest_base = 'health';

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'check' ),
                    'permission_callback' => array( $this, 'gate' ),
                ),
            )
        );
    }

    /**
     * Allow only requests whose client IP is in the operator-supplied
     * allowlist. Default empty = the endpoint is effectively disabled
     * until the operator wires their LB CIDRs.
     */
    public function gate(): bool {
        $ip      = \TejCart\Security\Rate_Limiter::get_client_ip();
        $config  = (array) get_option( 'tejcart_health_check_ips', array() );
        $list    = (array) apply_filters( 'tejcart_health_check_ips', $config );

        if ( empty( $list ) ) {
            // Default-deny when the operator hasn't configured an allowlist.
            return false;
        }

        $matched = false;
        foreach ( $list as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }
            if ( false === strpos( $entry, '/' ) ) {
                if ( hash_equals( $entry, $ip ) ) {
                    $matched = true;
                    break;
                }
                continue;
            }
            if ( \TejCart\Security\Rate_Limiter::ip_in_cidr( $ip, $entry ) ) {
                $matched = true;
                break;
            }
        }
        if ( ! $matched ) {
            return false;
        }

        // Allowlisted IPs are still rate-limited so a misbehaving LB
        // or monitoring agent cannot saturate the DB with `SELECT 1` +
        // COUNT(*) probes. 60 req/min per IP is generous for any
        // sensible probe interval.
        if ( \TejCart\Security\Rate_Limiter::check_and_record( 'health_endpoint', $ip, 60, 60 ) ) {
            return false;
        }
        return true;
    }

    public function check( WP_REST_Request $request ) {
        $detail = array();
        $ok     = true;

        // 1. DB ping.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Liveness probe must bypass cache.
        $row = isset( $wpdb ) && is_object( $wpdb ) ? $wpdb->get_var( 'SELECT 1' ) : null;
        $detail['db'] = ( '1' === (string) $row ) ? 'ok' : 'fail';
        if ( 'ok' !== $detail['db'] ) {
            $ok = false;
        }

        // 2. Object-cache write/read round-trip. Optional — many WP installs
        // run with the in-process default; we report 'unset' rather than
        // failing.
        if ( function_exists( 'wp_cache_set' ) && function_exists( 'wp_cache_get' ) ) {
            $key  = 'tejcart_health_' . random_int( 0, PHP_INT_MAX );
            $set  = wp_cache_set( $key, 1, 'tejcart', 5 );
            $hit  = wp_cache_get( $key, 'tejcart' );
            $detail['object_cache'] = ( $set && 1 === (int) $hit ) ? 'ok' : 'unset';
        } else {
            $detail['object_cache'] = 'unavailable';
        }

        // 3. Cron lag — pick a stable recurring tejcart hook and check
        //    how far past its scheduled time we are.
        $next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'tejcart_stock_reservation_prune' ) : false;
        $detail['cron_lag_seconds'] = $next ? max( 0, time() - (int) $next ) : -1;
        if ( $detail['cron_lag_seconds'] > HOUR_IN_SECONDS ) {
            $ok = false;
        }

        // 4. Action Scheduler available?
        $detail['action_scheduler'] = \TejCart\Core\Action_Scheduler::is_action_scheduler_available()
            ? 'ok'
            : 'missing';

        // 5. Pending paypal_events buffer depth (C-2). Only meaningful
        //    when the table exists.
        $detail['paypal_events_pending'] = $this->paypal_events_pending();

        $detail['ok']        = $ok;
        $detail['version']   = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : 'unknown';
        $detail['timestamp'] = gmdate( 'c' );

        return new WP_REST_Response( $detail, $ok ? 200 : 503 );
    }

    private function paypal_events_pending(): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return -1;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$wpdb->prefix}tejcart_paypal_events WHERE status = %s",
                'pending'
            )
        );
        return null === $count ? -1 : (int) $count;
    }
}
