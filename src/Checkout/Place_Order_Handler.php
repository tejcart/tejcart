<?php
/**
 * Place_Order_Handler — wp_ajax_tejcart_checkout entrypoint.
 *
 * Extracted from the inline closure that lived in `Core\TejCart::init_hooks()`
 * (F-M1 / #935). Moving the registration into a real class gives us:
 *
 *   - A unit-testable surface (vs. an anonymous closure baked into bootstrap).
 *   - A single place for any future place-order-specific guard rails.
 *   - Consistency with Cart_Ajax::register() / Checkout_AJAX::register()
 *     which the rest of the AJAX surface uses.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Place_Order_Handler {

    /**
     * Wire the two `wp_ajax(_nopriv)_tejcart_checkout` actions to {@see self::dispatch()}.
     *
     * Safe to call multiple times — the same callable on the same hook
     * is a no-op after the first add_action.
     *
     * @return void
     */
    public function register(): void {
        add_action( 'wp_ajax_tejcart_checkout',        array( $this, 'dispatch' ) );
        add_action( 'wp_ajax_nopriv_tejcart_checkout', array( $this, 'dispatch' ) );
    }

    /**
     * AJAX entrypoint: resolve the Checkout singleton from the
     * container, call process(), and respond with JSON.
     *
     * Mirrors the legacy inline-closure behaviour byte-for-byte so
     * callers see no observable change.
     *
     * @return void
     */
    public function dispatch(): void {
        // Audit #65 / 05 F-8 + 02 L-1 — wrap Checkout::process() in a
        // \Throwable catch. Without this, a throwing gateway / Tier-2
        // listener / addon callback escaped as a raw HTML 500 with
        // PHP error details, which the JSON-only JS handler swallowed
        // — the buyer's order had already COMMITted in `pending` with
        // stock decremented, so the browser then reloaded onto the
        // idempotency-lock dedup response. Logging the throwable and
        // emitting a sanitised 500 unblocks the JS path.
        try {
            $tejcart  = \TejCart\Core\TejCart::instance();
            $checkout = $tejcart->container()->make( 'checkout' );
            $result   = $checkout->process();
        } catch ( \Throwable $e ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Place_Order_Handler: %s — %s', get_class( $e ), $e->getMessage() ),
                    'critical'
                );
            }
            wp_send_json_error(
                array( 'message' => __( 'Could not complete checkout. Please retry.', 'tejcart' ) ),
                500
            );
            return;
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array( 'message' => $result->get_error_message() ),
                400
            );
            return;
        }

        wp_send_json_success( $result );
    }
}
