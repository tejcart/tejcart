<?php
/**
 * Shared nonce-verification helper for the PayPal gateway family.
 *
 * Originally lived inline in PayPal_Gateway::process_payment() — extracted
 * for Audit #11 / 05 F-2 so the four sibling gateways (Card, ApplePay,
 * GooglePay, Fastlane) can mount the same defence-in-depth check.
 *
 * The upstream callers (Checkout::process() for the standard flow,
 * PayPal_AJAX::create_order() for express) already verify a nonce, so
 * this is layered protection: a future caller that forgets the upstream
 * gate would otherwise expose the saved-method / save-method POST
 * inputs every sibling reads inside its own process_payment().
 *
 * @package TejCart\Gateways\PayPal\Concerns
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Verifies_Checkout_Nonce {
    /**
     * Refuse the payment if a `_wpnonce` is posted but doesn't match
     * either the checkout-form nonce or the PayPal-specific nonce.
     *
     * Returns null on success (caller continues) or the gateway's
     * standard failure-array shape on rejection (caller should
     * return it directly).
     *
     * @return array{result: 'failure', redirect: string, message: string}|null
     */
    protected function require_checkout_nonce(): ?array {
        // Standard checkout flow short-circuit. Checkout::process() already
        // verified the authoritative `tejcart_process_checkout` nonce at the
        // very top of the request — BEFORE any side effects. When a guest
        // ticks "create an account for faster checkout", maybe_create_account()
        // then logs the brand-new user in MID-REQUEST (current user id 0 → N).
        // WordPress nonces are hashed against the current user id, so
        // re-verifying the same browser-submitted nonce here — now under the
        // freshly logged-in identity — fails even though nothing is forged.
        // That false positive turned an otherwise-good order `failed` with
        // "Security check failed." on the guest-checkout + create-account +
        // PayPal path.
        //
        // Trust the authoritative upstream gate: if process() recorded a
        // successful verification for THIS request, the re-check has already
        // done its job, so pass. Every OTHER entry point (express / wallet
        // AJAX create_order, Pay-for-Order, cron, WP-CLI) never sets that
        // flag and still gets the full independent verification below — the
        // defence-in-depth contract for "a future caller that forgets the
        // upstream gate" is unchanged.
        if ( class_exists( \TejCart\Checkout\Checkout::class )
            && \TejCart\Checkout\Checkout::checkout_nonce_was_verified()
        ) {
            return null;
        }

        // The checkout-nonce fields a legitimate interactive entry point
        // may post, mapped to the action(s) each is allowed to carry:
        //   - classic checkout form  -> `tejcart_checkout_nonce`
        //   - express / wallet AJAX  -> `_wpnonce`
        // Each is independently (and mandatorily) verified by its upstream
        // handler — Checkout::process(), PayPal_AJAX::create_order() — so
        // this re-assertion is layered defence around the saved-method POST
        // inputs every sibling gateway reads inside process_payment().
        $fields = array(
            'tejcart_checkout_nonce' => array( 'tejcart_process_checkout' ),
            '_wpnonce'               => array( 'tejcart_paypal', 'tejcart_process_checkout' ),
        );

        $nonce_field_present = false;

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- this block IS the verification.
        foreach ( $fields as $field => $actions ) {
            if ( ! isset( $_POST[ $field ] ) ) {
                continue;
            }

            // A checkout-nonce field was supplied; from here on we fail
            // closed — an absent/empty/forged value is rejected rather than
            // silently skipped (the empty-string short-circuit that older
            // revisions allowed is gone).
            $nonce_field_present = true;
            $value               = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
            if ( '' === $value ) {
                continue;
            }

            foreach ( $actions as $action ) {
                if ( false !== wp_verify_nonce( $value, $action ) ) {
                    return null;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( $nonce_field_present ) {
            // A checkout nonce was posted but none verified — forged or
            // expired interactive request.
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Security check failed.', 'tejcart' ),
            );
        }

        // No checkout-nonce field at all: a non-interactive caller (a
        // subscription-renewal cron run, the Pay-for-Order flow which
        // carries its own order-scoped `tejcart_pay_nonce` verified in
        // Pay_For_Order::maybe_handle_post(), or WP-CLI). Those paths gate
        // authenticity upstream; don't break them here.
        return null;
    }
}
