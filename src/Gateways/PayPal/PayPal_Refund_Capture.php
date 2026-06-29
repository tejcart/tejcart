<?php
/**
 * Shared refund-capture logic for PayPal-family gateways.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hardened PayPal `capture refund` flow shared by every gateway in the
 * PayPal family (the canonical PayPal Smart Buttons gateway as well as
 * the hosted-card / ApplePay / GooglePay / Fastlane sibling gateways).
 *
 * Before this trait existed, only PayPal_Gateway::process_refund had the
 * full set of guards; each sibling carried its own minimal override that:
 *
 *   - Omitted the `tejcart_can( Capabilities::REFUND_ORDERS )` capability check.
 *   - Omitted the deterministic `paypal_request_id` so PayPal could not
 *     dedupe a retried refund — admin double-clicks issued two refunds.
 *   - Omitted the `_paypal_refunds` audit log write.
 *   - Returned `true` instead of the gateway refund_id, so the local
 *     Order_Refund row never persisted `transaction_ref` and webhook
 *     replays silently inserted duplicate rows (no UNIQUE coverage).
 *
 * Each gateway exposes its API client via {@see get_paypal_api()} so the
 * trait stays gateway-agnostic. The canonical PayPal_Gateway returns its
 * own `$this->api`; the siblings return the curl-shared static
 * `PayPal_Gateway::get_shared_api()`.
 */
trait PayPal_Refund_Capture {

    /**
     * Resolve the PayPal API client for the gateway using this trait.
     */
    abstract protected function get_paypal_api(): PayPal_API;

    /**
     * Source tag for the `_paypal_refunds` audit log entry. Defaults to
     * `'admin'` — siblings may override (`paypal_card`, `paypal_apple`,
     * `paypal_google`, `paypal_fastlane`) so reconciliation knows which
     * funding source produced the refund.
     */
    protected function paypal_refund_source(): string {
        return 'admin';
    }

    /**
     * Issue a PayPal capture refund with the full hardened guard set.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount   Refund amount in major units (null = full).
     * @param string     $reason   Refund reason.
     * @return string|bool|\WP_Error Refund ID, true (fallback), or WP_Error.
     */
    public function process_refund( int $order_id, ?float $amount = null, string $reason = '' ) {
        // Defence-in-depth capability check. The Order_Manager / admin
        // order screen is the primary gate for refund authorization,
        // but any caller that reaches this method must also hold the
        // cap so a misconfigured Tier-2 / sibling-plugin code path
        // can't issue an unauthorised refund.
        if ( function_exists( 'tejcart_can' ) && ! tejcart_can( \TejCart\Core\Capabilities::REFUND_ORDERS ) ) {
            return new \WP_Error(
                'tejcart_paypal_refund_forbidden',
                __( 'You do not have permission to refund orders.', 'tejcart' )
            );
        }

        $capture_id = tejcart_get_order_meta( $order_id, '_paypal_capture_id' );

        if ( empty( $capture_id ) ) {
            return new \WP_Error(
                'tejcart_paypal_refund_error',
                __( 'No PayPal capture ID found for this order.', 'tejcart' )
            );
        }

        $order    = tejcart_get_order( $order_id );
        $currency = $order ? $order->get_currency() : 'USD';

        // Deterministic idempotency key so retried refunds (admin
        // double-click, network blip, webhook replay) are deduplicated
        // by PayPal instead of issuing a second refund. Truncated to
        // PayPal's 36-char Request-Id limit while staying collision-safe.
        //
        // Audit #12 / 05 F-3 — without an attempt counter, two
        // legitimate same-amount partial refunds (e.g. two separate $5
        // product returns) hashed to the same Request-Id and PayPal's
        // 6h idempotency window returned the cached refund object on
        // the second call. Order_Refund::insert() used INSERT IGNORE
        // so the duplicate silently dropped, restock_items() never
        // fired for the second refund, and the merchant had no signal.
        // Include the next attempt counter (existing refund count + 1)
        // so each legitimate retry of the same shape gets its own
        // Request-Id.
        $attempt = class_exists( '\\TejCart\\Order\\Order_Refund' )
            ? ( (int) \TejCart\Order\Order_Refund::count_for_order( $order_id ) + 1 )
            : 1;
        // F-PPCP-005: $reason is excluded from the idempotency hash.
        // Including it made a double-click with a slightly different reason
        // string (e.g. trailing whitespace from a textarea widget) yield two
        // distinct PayPal-Request-Id values, issuing two refunds. $reason is a
        // merchant note and does not affect the financial transaction; the
        // $attempt counter is the correct differentiator for intentional
        // retries of the same shape.
        $request_id = substr(
            'tcr_' . hash(
                'sha256',
                (string) $order_id
                    . '|' . $capture_id
                    . '|' . ( null === $amount ? 'full' : (string) $amount )
                    . '|' . (string) $attempt
            ),
            0,
            36
        );

        $result = $this->get_paypal_api()->refund_capture( $capture_id, $amount, $currency, $reason, $request_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $refund_id = '';
        if ( ! empty( $result['id'] ) ) {
            $refund_id = sanitize_text_field( $result['id'] );
            tejcart_update_order_meta( $order_id, '_paypal_last_refund_id', $refund_id );

            $log     = tejcart_get_order_meta( $order_id, '_paypal_refunds' );
            $refunds = is_array( $log ) ? $log : array();
            $refunds[] = array(
                'id'     => $refund_id,
                'amount' => (float) ( $amount ?? 0 ),
                'reason' => $reason,
                'time'   => time(),
                'source' => $this->paypal_refund_source(),
            );
            tejcart_update_order_meta( $order_id, '_paypal_refunds', $refunds );
        }

        return '' !== $refund_id ? $refund_id : true;
    }
}
