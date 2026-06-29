<?php
/**
 * Surface PayPal webhook misconfiguration as a blocking admin notice.
 *
 * When the PayPal gateway is enabled but `webhook_id` is empty,
 * verify_webhook_signature() bails out with `false` and every incoming
 * webhook silently 400s. The operator has no signal in production logs
 * — until the first refund / dispute / capture event quietly vanishes.
 * This class renders a `notice-error` on the WP admin so the misconfig
 * shows up at the next page load.
 *
 * Closes #1195.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PayPal_Webhook_Health {

    /**
     * Register the admin-notice listener. Called from the feature map.
     */
    public function init(): void {
        if ( function_exists( 'is_admin' ) && ! is_admin() ) {
            return;
        }
        add_action( 'admin_notices', array( $this, 'maybe_emit_notice' ) );
    }

    /**
     * Render the notice when the gateway is enabled but webhook_id is
     * unset. Guarded by `current_user_can( 'manage_options' )` so it
     * stays invisible to shop managers without the gateway settings cap.
     */
    public function maybe_emit_notice(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        /**
         * Filter to suppress the missing-webhook-id notice (e.g. for
         * tests, staging mirrors, or installs that intentionally run
         * without webhooks).
         *
         * @since 1.0.2
         */
        if ( ! apply_filters( 'tejcart_paypal_show_missing_webhook_notice', true ) ) {
            return;
        }

        if ( $this->is_localhost() ) {
            return;
        }

        $tejcart = function_exists( 'tejcart' ) ? tejcart() : null;
        if ( ! is_object( $tejcart ) ) {
            return;
        }
        $gateways = $tejcart->gateways();
        if ( ! is_object( $gateways ) || ! method_exists( $gateways, 'get_gateway' ) ) {
            return;
        }
        // Pre-fix this was 'paypal' — the real PayPal_Gateway::$id is
        // 'tejcart_paypal' (set in the constructor at PayPal_Gateway.php:71).
        // The lookup returned null and the missing-webhook-id admin
        // notice was never rendered, defeating the entire purpose of
        // this class. Audit H-3.
        $gateway = $gateways->get_gateway( 'tejcart_paypal' );
        if ( ! is_object( $gateway ) ) {
            return;
        }

        $enabled = method_exists( $gateway, 'is_enabled' )
            ? (bool) $gateway->is_enabled()
            : ( 'yes' === (string) ( method_exists( $gateway, 'get_option' ) ? $gateway->get_option( 'enabled', 'no' ) : 'no' ) );
        if ( ! $enabled ) {
            return;
        }

        if ( ! method_exists( $gateway, 'get_option' ) ) {
            return;
        }
        $webhook_id = (string) $gateway->get_option( 'webhook_id', '' );
        if ( '' !== $webhook_id ) {
            return;
        }

        $settings_url = function_exists( 'admin_url' )
            ? admin_url( 'admin.php?page=tejcart-settings&tab=payments&section=paypal' )
            : '#';

        echo '<div class="notice notice-error"><p><strong>TejCart PayPal:</strong> ';
        echo esc_html__(
            'No webhook ID is configured. Inbound PayPal webhooks (captures, refunds, disputes) are silently failing signature verification — every event is dropped. ',
            'tejcart'
        );
        echo '<a href="' . esc_url( $settings_url ) . '">';
        echo esc_html__( 'Open PayPal settings', 'tejcart' );
        echo '</a> &middot; ';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_doc_link returns pre-escaped HTML.
        echo tejcart_doc_link( 'troubleshooting/notices/paypal-webhook-id', __( 'Step-by-step setup guide', 'tejcart' ) );
        echo '</p></div>';
    }

    private function is_localhost(): bool {
        if ( function_exists( 'home_url' ) ) {
            $host = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
            if ( '' !== $host
                && (
                    'localhost' === $host
                    || '127.0.0.1' === $host
                    || '::1' === $host
                    || str_ends_with( $host, '.local' )
                    || str_ends_with( $host, '.test' )
                    || str_ends_with( $host, '.localhost' )
                )
            ) {
                return true;
            }
        }

        // Custom domains mapped to loopback/private IPs in the hosts
        // file (e.g. WAMP with "nexa.com → 127.0.0.1") won't match the
        // hostname patterns above. Fall back to the server address: if
        // it's a private or reserved IP, PayPal cannot reach it.
        $server_addr = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) );
        if ( '' !== $server_addr
            && false !== filter_var( $server_addr, FILTER_VALIDATE_IP )
            && false === filter_var( $server_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
        ) {
            return true;
        }

        return false;
    }
}
