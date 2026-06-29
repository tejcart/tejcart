<?php
/**
 * Customer "Account details" form handler.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Processes the Account Details POST from the My Account area:
 *  - updates first/last/display name and email,
 *  - optionally changes the password (requires current-password check).
 *
 * Runs on template_redirect so feedback + redirects happen before any
 * output. Errors are stashed in a short-lived user transient and
 * rendered by the template on the next GET.
 */
class Account_Details {
    /**
     * Transient key used to surface form errors to the next request.
     */
    const NOTICE_TRANSIENT = 'tejcart_account_details_notice_';

    /**
     * Wire into WP.
     */
    public function init(): void {
        add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
    }

    /**
     * Handle the POST when the Save button was used.
     */
    public function maybe_handle(): void {
        if ( empty( $_POST['tejcart_save_account_details'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_account_details_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['tejcart_account_details_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'tejcart_save_account_details' ) ) {
            $this->store_notice( __( 'Security check failed, please reload and try again.', 'tejcart' ), 'error' );
            return;
        }

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        if ( '' === $first_name || '' === $last_name || '' === $display_name || '' === $email ) {
            $this->store_notice( __( 'Please fill in every required field.', 'tejcart' ), 'error' );
            return;
        }

        if ( ! is_email( $email ) ) {
            $this->store_notice( __( 'Please enter a valid email address.', 'tejcart' ), 'error' );
            return;
        }

        // Passwords are credential material; sanitization would corrupt
        // legitimate non-ASCII or punctuation characters. Unslash only.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $current_password = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
        $new_password     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
        $confirm_password = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $email_change    = ( $email !== $user->user_email );
        $password_change = ( '' !== $new_password || '' !== $confirm_password );

        // Audit H-39 (Product-Customer-Admin F-003): email-change
        // requires current-password re-auth. Without this, a stolen
        // session cookie → email takeover → password-reset → permanent
        // account compromise. Matches the password-change gate below.
        if ( $email_change ) {
            if ( '' === $current_password ) {
                $this->store_notice( __( 'Please enter your current password to change your email address.', 'tejcart' ), 'error' );
                return;
            }
            if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
                $this->store_notice( __( 'Your current password is incorrect.', 'tejcart' ), 'error' );
                return;
            }
            $existing = email_exists( $email );
            if ( $existing && (int) $existing !== $user_id ) {
                $this->store_notice( __( 'That email address is already in use.', 'tejcart' ), 'error' );
                return;
            }
        }

        if ( $password_change ) {
            if ( '' === $current_password ) {
                $this->store_notice( __( 'Please enter your current password to change it.', 'tejcart' ), 'error' );
                return;
            }
            if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
                $this->store_notice( __( 'Your current password is incorrect.', 'tejcart' ), 'error' );
                return;
            }
            if ( $new_password !== $confirm_password ) {
                $this->store_notice( __( 'New password and confirmation do not match.', 'tejcart' ), 'error' );
                return;
            }
            if ( strlen( $new_password ) < 8 ) {
                $this->store_notice( __( 'Password must be at least 8 characters.', 'tejcart' ), 'error' );
                return;
            }
        }

        $update = array(
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $display_name,
            'user_email'   => $email,
        );

        if ( $password_change ) {
            $update['user_pass'] = $new_password;
        }

        $result = wp_update_user( $update );
        if ( is_wp_error( $result ) ) {
            $this->store_notice( $result->get_error_message(), 'error' );
            return;
        }

        /**
         * Fires after a customer has updated their account details via the My Account page.
         *
         * @param int  $user_id         User ID.
         * @param bool $password_change Whether the password was changed.
         */
        do_action( 'tejcart_account_details_saved', $user_id, $password_change );

        $this->store_notice( __( 'Account details saved.', 'tejcart' ), 'success' );

        $redirect = add_query_arg( 'tab', 'account-details', wp_get_referer() ?: home_url( '/' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Stash a one-off notice for the next request.
     */
    private function store_notice( string $message, string $type ): void {
        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            array(
                'message' => $message,
                'type'    => in_array( $type, array( 'success', 'error', 'info' ), true ) ? $type : 'info',
            ),
            60
        );
    }

    /**
     * Read and clear the notice for the current user.
     *
     * @return array{message:string,type:string}|null
     */
    public static function consume_notice(): ?array {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return null;
        }

        $notice = get_transient( self::NOTICE_TRANSIENT . $user_id );
        if ( ! $notice ) {
            return null;
        }

        delete_transient( self::NOTICE_TRANSIENT . $user_id );
        return is_array( $notice ) ? $notice : null;
    }
}
