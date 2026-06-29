<?php
/**
 * Customer self-service account deletion handler.
 *
 * @package TejCart\Customer
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Privacy\Data_Eraser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle the "Delete my account" POST from the account-details template.
 */
class Account_Deletion {
    /**
     * Wire into the template_redirect lifecycle so we run before any output.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
    }

    /**
     * Inspect the current request for a valid delete submission. Verify the
     * nonce, the user's password, and the acknowledgement checkbox; then run
     * the privacy erasers, log the user out, and redirect home.
     */
    public function maybe_handle(): void {
        if ( empty( $_POST['tejcart_delete_account'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_delete_account_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_delete_account_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_delete_account' ) ) {
            return;
        }

        if ( empty( $_POST['delete_account_ack'] ) ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! $user || 0 === (int) $user->ID ) {
            return;
        }

        // Passwords are credential material; sanitization would corrupt
        // legitimate non-ASCII or punctuation characters. Unslashing is
        // the only correct preprocessing step before wp_check_password().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $password = isset( $_POST['delete_password'] ) ? (string) wp_unslash( $_POST['delete_password'] ) : '';

        if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            set_transient( 'tejcart_delete_account_error_' . $user->ID, __( 'Password incorrect. Account was not deleted.', 'tejcart' ), 60 );
            return;
        }

        $email = (string) $user->user_email;
        $user_id = (int) $user->ID;

        if ( class_exists( Data_Eraser::class ) ) {
            $eraser = new Data_Eraser();
            $eraser->erase_orders( $email );
            $eraser->erase_addresses( $email );
            $eraser->erase_payment_methods( $email );
            $eraser->erase_wishlist( $email );
            $eraser->erase_recently_viewed( $email );
            $eraser->erase_abandoned_carts( $email );
            $eraser->erase_downloads( $email );
            // Audit #33 / 09 F-002 — also wipe the wp_tejcart_customers
            // row. Without this the admin Customers screen kept listing
            // the user's email + first/last name + billing/shipping
            // JSON blobs after a self-service deletion.
            $eraser->erase_customer_profile( $email );
            // Audit #37 / 09 F-006 — anonymise the user's TejCart
            // product reviews. Default WP comment eraser does not
            // target the `tejcart_review` custom comment type.
            if ( method_exists( $eraser, 'erase_product_reviews' ) ) {
                $eraser->erase_product_reviews( $email );
            }
        }

        /**
         * Fires just before the WordPress user is deleted as part of a
         * customer self-service account deletion.
         *
         * @since 1.0.0
         *
         * @param int    $user_id The user being deleted.
         * @param string $email   The user's email at time of deletion.
         */
        do_action( 'tejcart_before_account_deleted', $user_id, $email );

        wp_logout();

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user( $user_id );

        $redirect = add_query_arg(
            'tejcart_account_deleted',
            '1',
            home_url( '/' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }
}
