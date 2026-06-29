<?php
/**
 * AJAX controllers — admin (privileged) + public (rate-limited lookup).
 *
 * The admin endpoints use TejCart core's capability check
 * (`tejcart_manage_orders`, filterable). The public endpoint is the
 * customer-facing "Track my order" lookup; it requires a matching
 * order_number + customer_email pair (so order numbers can't be
 * enumerated by guessing) and is double-rate-limited (per-IP burst plus
 * a tighter per-IP miss limit) so an attacker can't pair leaked order
 * numbers against known emails.
 *
 * Fail-closed posture: when the core Rate_Limiter class is missing for
 * any reason, the public endpoint refuses the request rather than
 * letting it through unlimited.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ajax_Controller {
    private const PUBLIC_BURST_ATTEMPTS = 20;
    private const PUBLIC_BURST_WINDOW   = 300; // 5 minutes.
    private const PUBLIC_MISS_ATTEMPTS  = 5;
    private const PUBLIC_MISS_WINDOW    = 60;  // 1 minute.

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'wp_ajax_tejcart_tracking_add',            array( $this, 'add' ) );
        add_action( 'wp_ajax_tejcart_tracking_update',         array( $this, 'update' ) );
        add_action( 'wp_ajax_tejcart_tracking_delete',         array( $this, 'delete' ) );
        add_action( 'wp_ajax_tejcart_tracking_list',           array( $this, 'list_admin' ) );
        add_action( 'wp_ajax_tejcart_tracking_repoll',         array( $this, 'repoll' ) );
        add_action( 'wp_ajax_tejcart_tracking_lookup',         array( $this, 'list_public' ) );
        add_action( 'wp_ajax_nopriv_tejcart_tracking_lookup',  array( $this, 'list_public' ) );
        // Back-compat: logged-out callers using the old action name.
        add_action( 'wp_ajax_nopriv_tejcart_tracking_list',    array( $this, 'list_public' ) );
    }

    public function add(): void {
        $this->require_admin();
        $result = $this->service->add(
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_admin()
            isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0,
            $this->payload_from_post()
        );
        if ( is_wp_error( $result ) ) {
            $this->send_wp_error( $result, 400 );
        }
        wp_send_json_success( array( 'id' => $result ) );
    }

    public function update(): void {
        $this->require_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_admin()
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing id.', 'tejcart' ) ), 400 );
        }
        $result = $this->service->update( $id, $this->payload_from_post() );
        if ( is_wp_error( $result ) ) {
            $this->send_wp_error( $result, 400 );
        }
        wp_send_json_success();
    }

    public function delete(): void {
        $this->require_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_admin()
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing id.', 'tejcart' ) ), 400 );
        }
        $result = $this->service->delete( $id );
        if ( is_wp_error( $result ) ) {
            $this->send_wp_error( $result, 400 );
        }
        wp_send_json_success();
    }

    public function list_admin(): void {
        $this->require_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by require_admin()
        $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
        wp_send_json_success( $this->service->for_order( $order_id ) );
    }

    /**
     * Trigger an immediate carrier-API refresh for one shipment. Used
     * by the "Re-poll" button in the admin metabox.
     *
     * Looks up the row, finds a configured provider for its carrier,
     * dispatches `Polling_Job::refresh_row()` synchronously. Any
     * resulting status transition fires through Tracking_Service::update,
     * exactly like a scheduled poll.
     */
    public function repoll(): void {
        $this->require_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by require_admin()
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Missing id.', 'tejcart' ) ), 400 );
        }
        $row = $this->service->repository()->find( $id );
        if ( null === $row ) {
            wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'tejcart' ) ), 404 );
        }

        // Manual re-poll rescues a dead-lettered row.
        ( new \TejCart\Tier2\Order_Tracking\Dead_Letter() )->reset( $id );

        $registry = \TejCart\Tier2\Order_Tracking\Order_Tracking::providers();
        $job      = new \TejCart\Tier2\Order_Tracking\Providers\Polling_Job( $this->service, $registry );
        $job->refresh_row( $row );
        wp_send_json_success( array( 'message' => __( 'Refresh requested.', 'tejcart' ) ) );
    }

    /**
     * Public customer-facing lookup. Order number + email must both
     * match. Rate-limited per IP with a fail-closed posture.
     */
    public function list_public(): void {
        check_ajax_referer( 'tejcart_nonce', 'nonce' );

        if ( ! class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            // Fail closed: without a limiter we cannot defend the
            // endpoint, so we refuse rather than risk enumeration.
            wp_send_json_error(
                array( 'message' => __( 'Service unavailable.', 'tejcart' ) ),
                503
            );
        }

        $ip = \TejCart\Security\Rate_Limiter::get_client_ip();

        $burst_blocked = \TejCart\Security\Rate_Limiter::check_and_record(
            'order_tracking_public',
            $ip,
            self::PUBLIC_BURST_ATTEMPTS,
            self::PUBLIC_BURST_WINDOW
        );
        if ( $burst_blocked ) {
            $this->send_429( self::PUBLIC_BURST_WINDOW );
        }

        $order_number = isset( $_GET['order_number'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['order_number'] ) )
            : '';
        $email = isset( $_GET['email'] )
            ? sanitize_email( wp_unslash( (string) $_GET['email'] ) )
            : '';

        if ( '' === $order_number || '' === $email ) {
            wp_send_json_error(
                array( 'message' => __( 'Order number and email are required.', 'tejcart' ) ),
                400
            );
        }

        global $wpdb;
        $orders   = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders} WHERE order_number = %s AND customer_email = %s LIMIT 1", $order_number, $email ) );

        if ( $order_id <= 0 ) {
            $miss_blocked = \TejCart\Security\Rate_Limiter::check_and_record(
                'order_tracking_public_miss',
                $ip,
                self::PUBLIC_MISS_ATTEMPTS,
                self::PUBLIC_MISS_WINDOW
            );
            if ( $miss_blocked ) {
                $this->send_429( self::PUBLIC_MISS_WINDOW );
            }
            wp_send_json_error(
                array( 'message' => __( 'No order found for that email.', 'tejcart' ) ),
                404
            );
        }

        wp_send_json_success( $this->service->for_order( $order_id ) );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload_from_post(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by caller's require_admin(); fields sanitized below before use.
        $raw = wp_unslash( $_POST );
        return array(
            'carrier'         => isset( $raw['carrier'] )         ? (string) $raw['carrier']         : '',
            'service'         => isset( $raw['service'] )         ? (string) $raw['service']         : '',
            'tracking_number' => isset( $raw['tracking_number'] ) ? (string) $raw['tracking_number'] : '',
            'tracking_url'    => isset( $raw['tracking_url'] )    ? (string) $raw['tracking_url']    : '',
            'label_url'       => isset( $raw['label_url'] )       ? (string) $raw['label_url']       : '',
            'status'          => isset( $raw['status'] )          ? (string) $raw['status']          : Shipment_Status::PENDING,
            'cost'            => isset( $raw['cost'] )            ? (float)  $raw['cost']            : 0.0,
            'shipped_at'      => isset( $raw['shipped_at'] )      ? (string) $raw['shipped_at']      : null,
            'delivered_at'    => isset( $raw['delivered_at'] )    ? (string) $raw['delivered_at']    : null,
        );
    }

    private function require_admin(): void {
        check_ajax_referer( 'tejcart_nonce', 'nonce' );
        if ( ! Capability::current_user_can_manage() ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage tracking.', 'tejcart' ) ),
                403
            );
        }
    }

    private function send_wp_error( \WP_Error $error, int $status ): void {
        wp_send_json_error(
            array(
                'message' => $error->get_error_message(),
                'code'    => $error->get_error_code(),
            ),
            $status
        );
    }

    private function send_429( int $retry_after ): void {
        if ( ! headers_sent() ) {
            header( 'Retry-After: ' . max( 1, $retry_after ) );
        }
        wp_send_json_error(
            array( 'message' => __( 'Too many lookups. Please try again later.', 'tejcart' ) ),
            429
        );
    }
}
