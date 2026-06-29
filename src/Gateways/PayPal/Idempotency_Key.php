<?php
/**
 * Deterministic PayPal-Request-Id (idempotency key) builder.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the deterministic `PayPal-Request-Id` header value for the
 * three mutating order endpoints (create, authorize, capture).
 *
 * PayPal's REST v2 API supports the `PayPal-Request-Id` header as an
 * idempotency token: when a request carries the same value as a prior
 * successful call within the dedupe window (~6 hours for orders v2),
 * PayPal returns the original response instead of mutating again. This
 * is the canonical defence against duplicate orders / captures when a
 * transient failure (network blip, PHP-FPM kill, Action Scheduler
 * retry, FastCGI timeout-and-restart) causes us to re-issue the same
 * logical operation.
 *
 * Until this class existed, only the subscriptions sibling supplied a
 * deterministic key (`nx_sub_*` from `PayPal_Bridge::build_request_id`);
 * everything else fell back to `wp_generate_uuid4()` per call inside
 * {@see PayPal_API::get_headers()}, which gave PayPal nothing to dedupe
 * on. PR #3 of the perf roadmap threads keys through the four
 * remaining call sites in core (Smart Buttons capture/authorize via
 * {@see PayPal_AJAX::capture_order()}, Smart Buttons express
 * create-order, the wallet/full-checkout create-order in
 * {@see PayPal_Gateway::process_payment()}, and the hosted-card
 * create-order in {@see Card_Gateway::process_payment()}).
 *
 * Key shape
 * =========
 * `tjc-<op>-<28 hex chars>` — a stable 32-char prefix for traceability
 * in PayPal merchant-support tickets (the `tjc-` prefix lets a PayPal
 * support engineer immediately tell that the request originated from
 * a TejCart store, not a custom integration). PayPal accepts up to
 * 80 chars; we stay well under the limit.
 *
 * Determinism inputs
 * ==================
 *  - Operation tag (`create` / `capture` / `auth`) — separates the
 *    three endpoints so a capture retry can never be deduped against
 *    its own create-order call.
 *  - Resource identifier — TejCart order ID for create, PayPal order
 *    ID for capture/authorize.
 *  - Attempt counter — for create-order this is the existing
 *    `_paypal_invoice_attempt` meta that already increments per
 *    operator-initiated retry. For capture/authorize the counter is
 *    1 by default; callers that explicitly want to bypass dedupe
 *    (e.g. a manual recapture after refund) can pass an incremented
 *    value.
 *  - Site fingerprint — derived from `wp_salt('auth')` so a cloned
 *    staging site cannot collide with production in PayPal's
 *    deduplication cache. Cloned sites typically share the same
 *    DB primary keys, so without the salt key collisions would
 *    return the production site's responses to the staging site.
 *
 * Why not just hand the existing UUID through?
 * ============================================
 * UUIDs are random per call. For idempotency to actually protect us,
 * a *retry* of the same logical operation must reuse the *same* key.
 * That means the key must be re-derivable from the same inputs, not
 * stored in transient state that the retry doesn't see.
 */
final class Idempotency_Key {
    /**
     * Truncate the keyed hash to this many hex chars. PayPal accepts up
     * to 80; 28 keeps the full key (with the `tjc-<op>-` prefix) at
     * 36 chars — comfortably short for log lines and dashboards.
     */
    private const HASH_LENGTH = 28;

    /**
     * Build the key for a create-order call.
     *
     * @param int $tejcart_order_id TejCart order ID being charged.
     * @param int $attempt          Attempt counter — pass the value of
     *                              `_paypal_invoice_attempt` meta AFTER
     *                              it has been incremented for this call,
     *                              so a network retry that re-enters
     *                              create_order with the same incremented
     *                              attempt reuses the same key.
     * @return string
     */
    public static function for_create_order( int $tejcart_order_id, int $attempt ): string {
        return self::derive( 'create', (string) max( 0, $tejcart_order_id ), max( 1, $attempt ) );
    }

    /**
     * Build the key for a capture-order call.
     *
     * @param string $paypal_order_id PayPal order ID being captured.
     * @param int    $attempt         Attempt counter (default 1). Bump
     *                                only when the operator is
     *                                deliberately re-attempting after
     *                                refund / authorisation expiry; a
     *                                normal retry must reuse 1.
     * @return string
     */
    public static function for_capture( string $paypal_order_id, int $attempt = 1 ): string {
        return self::derive( 'capture', $paypal_order_id, max( 1, $attempt ) );
    }

    /**
     * Build the key for an authorize-order call.
     *
     * @param string $paypal_order_id PayPal order ID being authorized.
     * @param int    $attempt         Attempt counter (default 1).
     * @return string
     */
    public static function for_authorize( string $paypal_order_id, int $attempt = 1 ): string {
        return self::derive( 'auth', $paypal_order_id, max( 1, $attempt ) );
    }

    /**
     * Combine inputs into a stable hash. Private — call the per-op
     * helpers above so the operation tag and input shape stay
     * consistent across every call site.
     *
     * @param string $op       Operation tag — one of `create`/`capture`/`auth`.
     * @param string $resource Resource identifier (order ID).
     * @param int    $attempt  Attempt counter.
     * @return string
     */
    private static function derive( string $op, string $resource, int $attempt ): string {
        $site_salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        $material  = implode(
            '|',
            array(
                $op,
                $resource,
                (string) $attempt,
                $site_salt,
            )
        );
        $hash = substr( hash( 'sha256', $material ), 0, self::HASH_LENGTH );
        return 'tjc-' . $op . '-' . $hash;
    }
}
