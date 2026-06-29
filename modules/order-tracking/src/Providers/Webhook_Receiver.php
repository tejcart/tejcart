<?php
/**
 * Inbound carrier webhook receiver.
 *
 * Exposes a single REST route:
 *   POST /tejcart/v1/tracking/webhook/{provider_slug}
 *
 * The receiver:
 *  1. Looks the provider up in the registry (404 if unknown).
 *  2. Calls `verify_webhook()` (401 on signature mismatch — uses the
 *     provider's own constant-time check).
 *  3. Calls `parse_webhook()` to get a Provider_Status (422 on parse
 *     failure — providers should retry on their own schedule).
 *  4. Resolves the shipment row by `(carrier, tracking_number)` via
 *     the Repository's reverse-lookup (cached).
 *  5. Idempotently applies the status via `Polling_Job::apply_status`,
 *     which already runs the state machine and skips no-op transitions.
 *  6. Records a per-(provider, payload) idempotency key in transients
 *     so a duplicate webhook delivery is a no-op.
 *
 * Idempotency window defaults to 24h, overridable via
 * `tejcart_order_tracking_webhook_idempotency_ttl`.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

use TejCart\Tier2\Order_Tracking\Carriers;
use TejCart\Tier2\Order_Tracking\Tracking_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Webhook_Receiver {
    public const NAMESPACE_V1 = 'tejcart/v1';
    public const ROUTE        = '/tracking/webhook/(?P<provider>[a-z0-9_\-]+)';
    public const IDEMPOTENCY_PREFIX = 'tejcart_ot_wh_';

    private Tracking_Service  $service;
    private Provider_Registry $registry;
    private Polling_Job       $polling;

    public function __construct(
        Tracking_Service $service,
        Provider_Registry $registry,
        ?Polling_Job $polling = null
    ) {
        $this->service  = $service;
        $this->registry = $registry;
        $this->polling  = $polling ?? new Polling_Job( $service, $registry );
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            self::ROUTE,
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'handle' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        // Audit M-47: rate-limit unauthenticated webhook endpoint.
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip = \TejCart\Security\Rate_Limiter::get_client_ip();
            if ( \TejCart\Security\Rate_Limiter::check_and_record( 'tracking_webhook', $ip, 60, 60 ) ) {
                return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
            }
        }

        $provider_slug = sanitize_key( (string) $request->get_param( 'provider' ) );
        $provider      = $this->registry->get( $provider_slug );
        if ( null === $provider ) {
            return new \WP_Error( 'unknown_provider', 'Unknown provider', array( 'status' => 404 ) );
        }

        $headers = $this->headers_from_request( $request );
        $body    = (string) $request->get_body();

        if ( ! $provider->verify_webhook( $headers, $body ) ) {
            do_action(
                'tejcart_order_tracking_webhook_signature_failed',
                $provider_slug,
                array(
                    'ip'          => $this->client_ip(),
                    'user_agent'  => isset( $headers['user-agent'] ) ? substr( (string) $headers['user-agent'], 0, 200 ) : '',
                    'body_bytes'  => strlen( $body ),
                    'signature_headers' => $this->signature_header_names( $headers ),
                )
            );
            return new \WP_Error( 'invalid_signature', 'Invalid signature', array( 'status' => 401 ) );
        }

        // Cheap pre-parse idempotency check: if the exact same body has
        // been processed within the TTL window we can short-circuit
        // before doing the JSON decode. We still re-derive the key after
        // parse below using the provider's stable event_id when present
        // (a stronger guarantee than body hashing, since payloads can
        // re-serialise with different whitespace).
        $body_key = self::IDEMPOTENCY_PREFIX . hash( 'sha256', $provider_slug . '|' . $body );
        if ( get_transient( $body_key ) ) {
            return new \WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
        }

        $parsed = $provider->parse_webhook( $headers, $body );
        if ( is_wp_error( $parsed ) ) {
            // Tag with HTTP status. Use add_data when present (real
            // WP_Error class), otherwise drop a 422 wrapper around the
            // existing error so the REST layer still returns 422.
            if ( method_exists( $parsed, 'add_data' ) ) {
                $parsed->add_data( array( 'status' => 422 ) );
                return $parsed;
            }
            return new \WP_Error( $parsed->get_error_code(), $parsed->get_error_message(), array( 'status' => 422 ) );
        }

        $tracking_number = $this->extract_tracking_number( $parsed, $headers );
        $carrier         = Carriers::normalize_slug( $this->extract_carrier( $parsed, $headers ) );

        if ( '' === $tracking_number ) {
            return new \WP_Error( 'missing_tracking_number', 'Webhook contained no tracking number', array( 'status' => 422 ) );
        }

        // Prefer the provider-supplied event_id for idempotency (stable
        // across re-serialisations and retries); fall back to the body
        // hash. Both keys are cached so EITHER form of duplicate
        // delivery short-circuits.
        $idem_keys = array( $body_key );
        if ( null !== $parsed->event_id && '' !== $parsed->event_id ) {
            $event_key   = self::IDEMPOTENCY_PREFIX . 'evt_' . hash( 'sha256', $provider_slug . '|' . $parsed->event_id );
            $idem_keys[] = $event_key;
            if ( get_transient( $event_key ) ) {
                return new \WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
            }
        }

        $shipment = $this->resolve_shipment( $carrier, $tracking_number );
        if ( null === $shipment ) {
            // Not our shipment — return 200 so the carrier doesn't keep
            // retrying. We can't do anything useful about it. Cache the
            // idempotency keys so a flood of resends for the same
            // unknown number doesn't keep hitting the DB.
            foreach ( $idem_keys as $key ) {
                set_transient( $key, 1, $this->idempotency_ttl() );
            }
            return new \WP_REST_Response( array( 'status' => 'no_match' ), 200 );
        }

        // Wrap apply_status so a transient DB error (deadlock, lost
        // connection) is reported back to the carrier as 5xx rather
        // than being silently swallowed with a 200 — the carrier will
        // retry on 5xx, which is exactly what we want when our own
        // write failed.
        try {
            $this->polling->apply_status( (int) $shipment['id'], (string) $shipment['status'], $parsed );
        } catch ( \Throwable $e ) {
            do_action(
                'tejcart_order_tracking_webhook_apply_failed',
                $provider_slug,
                (int) $shipment['id'],
                $e
            );
            return new \WP_Error(
                'apply_status_failed',
                'Failed to apply provider status — carrier should retry.',
                array( 'status' => 500 )
            );
        }

        // Only mark idempotency after a successful apply so a 5xx
        // response above does not block a legitimate carrier retry.
        foreach ( $idem_keys as $key ) {
            set_transient( $key, 1, $this->idempotency_ttl() );
        }

        return new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    /**
     * @return array<string, string>
     */
    private function headers_from_request( \WP_REST_Request $request ): array {
        $out = array();
        foreach ( $request->get_headers() as $name => $values ) {
            $out[ strtolower( (string) $name ) ] = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
        }
        return $out;
    }

    /**
     * Tracking number can come from the parsed payload (preferred) or
     * from a provider header convention. Providers MAY put the value
     * in `Provider_Status::raw['tracking_number']`.
     *
     * @param array<string, string> $headers
     */
    private function extract_tracking_number( Provider_Status $parsed, array $headers ): string {
        if ( isset( $parsed->raw['tracking_number'] ) ) {
            return (string) $parsed->raw['tracking_number'];
        }
        if ( isset( $headers['x-tracking-number'] ) ) {
            return (string) $headers['x-tracking-number'];
        }
        return '';
    }

    /**
     * @param array<string, string> $headers
     */
    private function extract_carrier( Provider_Status $parsed, array $headers ): string {
        if ( isset( $parsed->raw['carrier'] ) ) {
            return (string) $parsed->raw['carrier'];
        }
        if ( isset( $headers['x-carrier'] ) ) {
            return (string) $headers['x-carrier'];
        }
        return '';
    }

    /**
     * Resolve the shipment a verified webhook should act on.
     *
     * When the provider hands us a carrier, we require an exact
     * `(carrier, tracking_number)` match: applying a webhook to the
     * wrong shipment because two carriers happened to mint the same
     * tracking number is a far worse outcome than letting that webhook
     * fall through as `no_match`. When the provider doesn't tell us
     * which carrier (rare — only when the provider's parse layer fails
     * to derive it), we only fall back to a tracking-number-only lookup
     * if exactly one undeleted shipment matches. Anything else returns
     * null so the receiver replies `no_match` rather than guessing.
     *
     * @return array<string, mixed>|null
     */
    private function resolve_shipment( string $carrier, string $tracking_number ): ?array {
        $repo = $this->service->repository();

        if ( '' !== $carrier ) {
            return $repo->find_by_tracking( $carrier, $tracking_number );
        }

        global $wpdb;
        $table = \TejCart\Tier2\Order_Tracking\Schema_Migrator::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_number = %s AND deleted_at IS NULL LIMIT 2", $tracking_number ), ARRAY_A );
        if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
            return null;
        }
        return is_array( $rows[0] ) ? $rows[0] : null;
    }

    private function client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        return ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
    }

    /**
     * Return just the *names* of headers that look like signature
     * envelopes — useful for triage in the signature-failed action
     * without leaking secret material to listeners.
     *
     * @param array<string, string> $headers
     * @return array<int, string>
     */
    private function signature_header_names( array $headers ): array {
        $needles = array( 'signature', 'hmac', 'x-shippo', 'x-ep-', 'aftership' );
        $out     = array();
        foreach ( array_keys( $headers ) as $name ) {
            foreach ( $needles as $needle ) {
                if ( false !== strpos( (string) $name, $needle ) ) {
                    $out[] = (string) $name;
                    break;
                }
            }
        }
        return $out;
    }

    private function idempotency_ttl(): int {
        // Default 72h — long enough to absorb the typical carrier retry
        // budget (EasyPost retries for ~24h, Shippo for ~48h on outage)
        // without holding storage forever. Filterable.
        /** @var int */
        return max( 60, (int) apply_filters( 'tejcart_order_tracking_webhook_idempotency_ttl', 3 * DAY_IN_SECONDS ) );
    }
}
