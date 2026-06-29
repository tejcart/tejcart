<?php
/**
 * Refund-inconsistency clear endpoint (F-M5 / #939).
 *
 * `Order_Manager` sets `_tejcart_refund_inconsistent` order meta when a
 * gateway-side refund succeeds but the local INSERT into
 * `wp_tejcart_order_refunds` fails (or any post-gateway local step
 * errors). Subsequent refund attempts on that order are blocked until
 * the flag is cleared. Previously there was no admin UI to clear it.
 *
 * This admin-AJAX handler lets a user with `tejcart_manage_orders` clear the
 * flag (with a nonce + capability check), logs the audit trail by
 * adding an order note, and fires `tejcart_refund_inconsistency_cleared`
 * for observability.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Refund_Inconsistency_Admin {

    /**
     * Wire the admin-AJAX handler. Safe to call multiple times.
     *
     * @return void
     */
    public function register(): void {
        add_action(
            'wp_ajax_tejcart_clear_refund_inconsistency',
            array( __CLASS__, 'ajax_clear' )
        );
    }

    /**
     * AJAX handler. Expects POST: order_id (int), _wpnonce.
     *
     * @return void
     */
    public static function ajax_clear(): void {
        // Capability gate first — refund management is an
        // administrative operation that uses the order-management cap.
        // Pre-fix this checked `manage_tejcart` which is not a
        // registered capability (audit H-1) — every call failed the
        // gate and the admin notice could never be cleared.
        if ( ! current_user_can( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to clear refund inconsistencies.', 'tejcart' ) ),
                403
            );
            return;
        }

        $order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
        if ( $order_id <= 0 ) {
            wp_send_json_error(
                array( 'message' => __( 'Missing order ID.', 'tejcart' ) ),
                400
            );
            return;
        }

        // Per-order nonce so a stolen pageload-wide nonce can't clear
        // every order at once.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_clear_refund_inconsistency_' . $order_id ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please reload the page.', 'tejcart' ) ),
                403
            );
            return;
        }

        $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        if ( ! is_object( $order ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Order not found.', 'tejcart' ) ),
                404
            );
            return;
        }

        if ( ! $order->get_meta( '_tejcart_refund_inconsistent' ) ) {
            wp_send_json_success( array(
                'message' => __( 'Refund inconsistency flag was not set; nothing to clear.', 'tejcart' ),
                'cleared' => false,
            ) );
            return;
        }

        $order->delete_meta( '_tejcart_refund_inconsistent' );

        $current_user = wp_get_current_user();
        $author       = ( $current_user && isset( $current_user->user_login ) )
            ? (string) $current_user->user_login
            : 'admin';

        if ( method_exists( $order, 'add_note' ) ) {
            $order->add_note(
                sprintf(
                    /* translators: %s: admin username */
                    __( 'Refund inconsistency flag cleared by %s. Verify the gateway-side refund manually before retrying.', 'tejcart' ),
                    $author
                ),
                false
            );
        }

        /**
         * Fires after an admin manually clears a refund-inconsistency flag.
         *
         * @param int    $order_id
         * @param string $author    Admin user login that cleared the flag.
         */
        do_action( 'tejcart_refund_inconsistency_cleared', $order_id, $author );

        wp_send_json_success( array(
            'message' => __( 'Refund inconsistency flag cleared.', 'tejcart' ),
            'cleared' => true,
        ) );
    }
}
