<?php
/**
 * Carrier-tracking provider contract.
 *
 * A "provider" is anything that can answer "what's the latest status of
 * tracking number X?". In practice that's an aggregator like EasyPost,
 * Shippo, AfterShip, 17track, or a direct carrier API (FedEx, UPS,
 * USPS, DHL). Each one implements this same surface so the polling job
 * and webhook receiver don't care what's underneath.
 *
 * Implementations MUST be safe to instantiate cheaply (no network in
 * the constructor) and MUST be idempotent — calling `fetch_status` for
 * the same tracking number twice in a row must not have side effects.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Tracking_Provider {
    /**
     * Stable, kebab-case identifier. Used in the webhook URL
     * (`/tejcart/v1/tracking/webhook/{slug}`) so don't change it.
     */
    public function slug(): string;

    /**
     * Human-readable label, for admin UI.
     */
    public function label(): string;

    /**
     * True when the merchant has provided every credential the provider
     * needs (typically an API key). The polling/webhook layer skips
     * unconfigured providers — they don't generate errors, they just
     * don't run.
     */
    public function is_configured(): bool;

    /**
     * Whether this provider can handle the given carrier slug. Used by
     * the polling job when a shipment row records `carrier='ups'`.
     */
    public function supports( string $carrier ): bool;

    /**
     * Fetch the current status for a single tracking number.
     *
     * Implementations should:
     *  - Treat HTTP 4xx as terminal (return WP_Error 'provider_4xx').
     *  - Retry HTTP 5xx / timeout via the shared HTTP_Client.
     *  - NEVER throw — return WP_Error on every failure.
     */
    public function fetch_status( string $tracking_number, string $carrier ): Provider_Status|\WP_Error;

    /**
     * Verify a webhook signature against this provider's shared secret.
     *
     * Implementations MUST use a constant-time comparison
     * (`hash_equals`) and MUST refuse if no signature header is
     * present. False here causes the receiver to reject with HTTP 401.
     *
     * @param array<string, string> $headers
     */
    public function verify_webhook( array $headers, string $body ): bool;

    /**
     * Parse a webhook payload into our normalised Provider_Status.
     *
     * Returning WP_Error rejects the webhook with HTTP 422 (the
     * receiver does not retry — the provider is expected to retry on
     * its own schedule).
     *
     * @param array<string, string> $headers
     */
    public function parse_webhook( array $headers, string $body ): Provider_Status|\WP_Error;
}
