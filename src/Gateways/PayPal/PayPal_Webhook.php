<?php
/**
 * PayPal Webhook Handler
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles incoming PayPal webhook notifications.
 */
class PayPal_Webhook {
    /**
     * PayPal webhook event types this plugin handles. Used both by
     * PayPal_AJAX::register_webhook when subscribing at PayPal and by
     * process_event when dispatching incoming deliveries, so the two
     * lists cannot drift.
     */
    public const EVENT_TYPES = array(
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'CHECKOUT.ORDER.APPROVED',
        'CHECKOUT.ORDER.COMPLETED',
        'CUSTOMER.DISPUTE.CREATED',
        'CUSTOMER.DISPUTE.RESOLVED',
        'CUSTOMER.DISPUTE.UPDATED',
        'RISK.DISPUTE.CREATED',
        'VAULT.PAYMENT-TOKEN.CREATED',
        'VAULT.PAYMENT-TOKEN.DELETED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'PAYMENT.SALE.COMPLETED',
    );

    /**
     * Resolve the filterable list of PayPal event types this site
     * subscribes to and dispatches.
     *
     * Wraps {@see self::EVENT_TYPES} with the
     * `tejcart_paypal_webhook_event_types` filter so addons can register
     * additional events without forking the constant.
     *
     * @return string[]
     */
    public static function event_types() : array {
        $types = apply_filters( 'tejcart_paypal_webhook_event_types', self::EVENT_TYPES );
        if ( ! is_array( $types ) || empty( $types ) ) {
            return self::EVENT_TYPES;
        }
        return array_values( array_unique( array_map( 'strval', $types ) ) );
    }

    /**
     * PayPal API handler.
     *
     * @var PayPal_API
     */
    private PayPal_API $api;

    /**
     * PayPal Gateway instance.
     *
     * @var PayPal_Gateway
     */
    private PayPal_Gateway $gateway;

    /**
     * Constructor.
     *
     * @param PayPal_Gateway $gateway Gateway instance.
     */
    public function __construct( PayPal_Gateway $gateway ) {
        $this->gateway = $gateway;
        $this->api     = $gateway->get_api();
    }

    /**
     * Register the REST API route for the PayPal webhook endpoint.
     */
    public function init(): void {
        add_action( 'rest_api_init', function () {
            register_rest_route( 'tejcart/v1', '/webhook/paypal', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                /*
                 * Inbound webhook from PayPal — by definition unauthenticated
                 * at the WP user layer. Authentication is enforced inside
                 * handle_webhook() via verify_webhook_signature() (PayPal's
                 * cryptographic signature on the request body), a
                 * 6-hour replay window on the signed transmission timestamp,
                 * per-IP rate limiting, and idempotent event-claim via
                 * add_option(). Do NOT replace with a permission check that
                 * relies on the current WP user — PayPal is the caller.
                 */
                'permission_callback' => '__return_true',
            ) );
        } );

        add_action( 'admin_notices', array( $this, 'maybe_render_cert_suffix_advisory' ) );
    }

    /**
     * L-06: Surface an admin notice when an operator has registered an
     * advanced cert-URL suffix allowlist via the
     * `tejcart_paypal_cert_url_suffixes_advanced` filter. The filter
     * widens the trust surface for PayPal cert-URL fetches; making the
     * decision visible in wp-admin reminds the next maintainer that it
     * is in effect.
     */
    public function maybe_render_cert_suffix_advisory(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $suffixes = (array) apply_filters( 'tejcart_paypal_cert_url_suffixes_advanced', array() );
        $suffixes = array_values( array_filter( array_map(
            static function ( $value ) {
                return is_string( $value ) ? trim( $value ) : '';
            },
            $suffixes
        ) ) );
        if ( array() === $suffixes ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
            esc_html__( 'TejCart:', 'tejcart' ),
            esc_html( sprintf(
                /* translators: %s: comma-separated suffix patterns */
                __( 'PayPal cert-URL suffix allow-list is widened by an active filter (%s). Confirm this is intended; broad patterns such as "*.paypal.com" re-include marketing CDNs and other PayPal subsidiary subdomains.', 'tejcart' ),
                implode( ', ', array_map( 'sanitize_text_field', $suffixes ) )
            ) ),
            tejcart_doc_link( 'troubleshooting/notices/paypal-cert-url-allowlist', __( 'What this filter does', 'tejcart' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_doc_link returns pre-escaped HTML.
        );
    }

    /**
     * Main webhook handler invoked by WordPress REST API.
     *
     * @param \WP_REST_Request $request Incoming request.
     * @return \WP_REST_Response
     */
    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $client_ip = \TejCart\Security\Rate_Limiter::get_client_ip();
        // Tightened from 120/60s to 30/60s. PayPal's webhook origin is a
        // small fixed IP set; legitimate traffic stays well under this.
        // The previous 120/60s left enough headroom for an attacker to
        // probe webhook routes for parser quirks before tripping the limit.
        if ( \TejCart\Security\Rate_Limiter::check_and_record(
            'paypal_webhook',
            $client_ip,
             30,
             60
        ) ) {
            return new \WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
        }

        $body = $request->get_json_params();

        if ( empty( $body ) || empty( $body['event_type'] ) ) {
            return new \WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
        }

        // Replay-window check. PayPal's PAYPAL-TRANSMISSION-TIME header is
        // signed alongside the body, so once verify_webhook_signature() has
        // accepted the request we trust it as PayPal's clock. Reject events
        // whose transmission timestamp is more than 6 hours old: PayPal's
        // own retry budget is well under that, and beyond 6h the dedup
        // option may already have been garbage-collected (DAY_IN_SECONDS
        // schedule), making a replay viable.
        //
        // L-1: parse via DateTimeImmutable in explicit UTC instead of
        // strtotime(), which on some PHP builds is sensitive to LC_TIME
        // and date.timezone — particularly around DST transitions.
        // PayPal sends ISO 8601 with explicit Z / ±HH:MM, which DATE_ATOM
        // accepts. Fall back to ::createFromFormat for the rare RFC 822
        // variant; reject anything else.
        // F-PPCP-003: reject when the header is absent rather than silently
        // passing through. A request that omits paypal-transmission-time would
        // bypass the 6-hour replay window. Log the distinct case so operators
        // can distinguish a missing header from an expired one.
        $transmission_time = (string) $request->get_header( 'paypal-transmission-time' );
        if ( '' === $transmission_time ) {
            tejcart_log( 'PayPal webhook rejected: paypal-transmission-time header missing.', 'warning' );
            return new \WP_REST_Response(
                array( 'error' => 'Missing transmission timestamp.' ),
                400
            );
        }

        $tx_dt = false;
        try {
            $tx_dt = new \DateTimeImmutable( $transmission_time, new \DateTimeZone( 'UTC' ) );
        } catch ( \Throwable $e ) {
            $tx_dt = false;
        }
        $tx_ts = $tx_dt instanceof \DateTimeImmutable ? $tx_dt->getTimestamp() : false;
        if ( false === $tx_ts || ( time() - $tx_ts ) > 6 * HOUR_IN_SECONDS ) {
            return new \WP_REST_Response(
                array( 'error' => 'Transmission timestamp out of window.' ),
                400
            );
        }

        if ( ! $this->verify_webhook_signature( $request ) ) {
            return new \WP_REST_Response( array( 'error' => 'Signature verification failed.' ), 403 );
        }

        $event_id = $body['id'] ?? '';
        if ( ! empty( $event_id ) ) {
            $claimed = $this->claim_event( $event_id );
            if ( null === $claimed ) {
                // DB error while claiming — return 503 so PayPal
                // retries and the event isn't silently dropped.
                return new \WP_REST_Response( array( 'status' => 'error', 'note' => 'claim_failed' ), 503 );
            }
            if ( false === $claimed ) {
                // L-5: redact the PayPal event id in the log line.
                // Guard the Log_Redactor reference — a missing
                // autoloader / partial upgrade should not fatal the
                // webhook handler; fall back to a coarse mask
                // (`pp-evt-…<last 6>`) that's still safe to log.
                $event_id_log = class_exists( '\\TejCart\\Security\\Log_Redactor' )
                    ? \TejCart\Security\Log_Redactor::transaction_id( (string) $event_id )
                    : 'pp-evt-…' . substr( (string) $event_id, -6 );
                tejcart_log( sprintf( 'Duplicate PayPal webhook event skipped: %s', $event_id_log ) );
                return new \WP_REST_Response( array( 'status' => 'ok', 'note' => 'duplicate' ), 200 );
            }
        }

        // C-2: persist the raw payload and dispatch an async worker
        // instead of calling process_event() inline. PayPal's 25-second
        // response window is no longer coupled to fulfilment latency.
        // Falls back to inline processing only when the events table is
        // unavailable (pre-1.2.0 install) or when the operator has
        // explicitly opted out via the filter.
        $async_enabled = (bool) apply_filters( 'tejcart_paypal_webhook_async', true );
        $row_id        = $async_enabled ? $this->store_pending_event( (string) $event_id, $body ) : 0;
        if ( $row_id > 0 ) {
            \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                time(),
                PayPal_Event_Worker::PROCESS_HOOK,
                array( $row_id )
            );
            return new \WP_REST_Response( array( 'status' => 'ok', 'async' => true, 'event_row' => $row_id ), 200 );
        }

        try {
            $result = $this->process_event( $body );
        } catch ( \Throwable $e ) {
            if ( ! empty( $event_id ) ) {
                $this->release_event_claim( $event_id );
            }
            if ( function_exists( 'tejcart_log' ) ) {
                $msg = 'PayPal webhook threw ' . get_class( $e ) . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine() . ' — ' . $e->getMessage();
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $msg .= "\nTrace: " . $e->getTraceAsString();
                }
                tejcart_log( $msg, 'error' );
            }
            return new \WP_REST_Response( array( 'error' => 'Internal error.' ), 500 );
        }

        if ( is_wp_error( $result ) ) {
            if ( ! empty( $event_id ) ) {
                $this->release_event_claim( $event_id );
            }
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'PayPal webhook processing failed: ' . $result->get_error_message(), 'error' );
            }
            return new \WP_REST_Response(
                array( 'error' => $result->get_error_message() ),
                500
            );
        }

        return new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    /**
     * Persist a verified PayPal webhook event to the buffer table.
     *
     * Returns the inserted row id, or 0 when the events table is not
     * available on this install (the caller falls back to inline
     * processing in that case).
     *
     * @param string $event_id PayPal event id (already used for the claim).
     * @param array  $body     Decoded webhook body.
     * @return int Row id, or 0 on failure.
     */
    private function store_pending_event( string $event_id, array $body ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }

        $payload_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body );
        if ( false === $payload_json || null === $payload_json ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tejcart_paypal_events
                    (event_id, event_type, payload, status, attempts)
                 VALUES (%s, %s, %s, 'pending', 0)",
                $event_id,
                (string) ( $body['event_type'] ?? '' ),
                $payload_json
            )
        );
        if ( 1 !== (int) $rows ) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Atomically claim an event for processing.
     *
     * Uses add_option() which performs INSERT IGNORE at the database level —
     * only the first concurrent call for a given event_id will succeed.
     * Schedules cleanup of the option after 24 hours.
     *
     * @param string $event_id PayPal event ID.
     * @return bool True if this request successfully claimed the event, false if already claimed.
     */
    private function claim_event( string $event_id ): ?bool {
        // S-4: prefer the custom-table lock primitive (zero alloptions
        // churn). Falls back to the legacy wp_options claim if the
        // tejcart_locks table is not yet provisioned on this install
        // (handles the upgrade window before the 1.2.0 migration runs).
        $lock_key = 'wh_' . hash( 'sha256', $event_id );
        if ( class_exists( \TejCart\Core\Lock::class ) ) {
            $claimed = \TejCart\Core\Lock::claim( $lock_key, 4 * DAY_IN_SECONDS, 'paypal_webhook' );
            if ( $claimed ) {
                if ( wp_rand( 1, 200 ) === 1 ) {
                    \TejCart\Core\Lock::sweep_expired();
                }
                return true;
            }
            // Lock said "already held". Belt-and-suspenders: also check
            // the legacy add_option claim so we honour any in-flight
            // claim from before the migration ran.
        }

        global $wpdb;
        $pre_error   = isset( $wpdb ) && is_object( $wpdb ) ? (string) $wpdb->last_error : '';
        $option_name = 'tejcart_wh_' . hash( 'sha256', $event_id );
        $claimed     = add_option( $option_name, time(), '', 'no' );
        if ( $claimed ) {
            wp_schedule_single_event(
                time() + ( 4 * DAY_IN_SECONDS ),
                'tejcart_cleanup_webhook_option',
                array( $option_name )
            );
            if ( wp_rand( 1, 200 ) === 1 ) {
                self::sweep_expired_webhook_claims();
            }
            return true;
        }

        // add_option() returns false on both "row already exists"
        // (expected — this is the duplicate-event case) and on DB error
        // (unexpected — we'd be silently dropping a real event if PayPal
        // doesn't retry). Inspect $wpdb->last_error to disambiguate: if
        // a new error appeared during the call we return null so the
        // caller responds with 503 and PayPal retries; otherwise return
        // false for the normal duplicate path.
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            $post_error = (string) $wpdb->last_error;
            if ( '' !== $post_error && $post_error !== $pre_error ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( 'PayPal webhook claim_event DB error: %s', $post_error ),
                        'error'
                    );
                }
                return null;
            }
        }
        return false;
    }

    /**
     * Prune expired `tejcart_wh_*` claim rows from `wp_options`.
     *
     * Called opportunistically from `claim_event()` so the table is
     * kept clean even on hosts that disable WP-Cron. Each value is the
     * acquisition timestamp (an int); rows older than the dedup TTL
     * plus a generous safety margin are deleted in one batch (limit
     * 200) to keep the sweep cheap. Public + static so the regression
     * test can drive it directly without a real webhook delivery.
     *
     * @internal
     * @return int Number of rows deleted.
     */
    public static function sweep_expired_webhook_claims(): int {
        global $wpdb;

        $cutoff = time() - ( 4 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
                $wpdb->esc_like( 'tejcart_wh_' ) . '%'
            )
        );
        if ( empty( $rows ) ) {
            return 0;
        }

        $deleted = 0;
        foreach ( $rows as $row ) {
            $stamp = (int) $row->option_value;
            if ( $stamp > 0 && $stamp < $cutoff ) {
                delete_option( $row->option_name );
                $deleted++;
            }
        }

        if ( $deleted > 0 && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'PayPal webhook sweeper removed %d expired claim row(s).', $deleted ),
                'info'
            );
        }

        return $deleted;
    }

    /**
     * Release a previously claimed event so it can be retried.
     *
     * Called when processing fails, to allow PayPal to re-deliver the webhook.
     *
     * @param string $event_id PayPal event ID.
     */
    private function release_event_claim( string $event_id ): void {
        // Release both the new lock-table entry and any legacy add_option
        // claim from before the S-4 migration. Idempotent on either path.
        if ( class_exists( \TejCart\Core\Lock::class ) ) {
            \TejCart\Core\Lock::release( 'wh_' . hash( 'sha256', $event_id ) );
        }
        $option_name = 'tejcart_wh_' . hash( 'sha256', $event_id );
        delete_option( $option_name );
    }

    /**
     * Verify the PayPal webhook signature using the PayPal-Transmission-Sig header.
     *
     * @param \WP_REST_Request $request Incoming request.
     * @return bool True if the signature is valid.
     */
    public function verify_webhook_signature( \WP_REST_Request $request ): bool {
        $webhook_id = $this->gateway->get_option( 'webhook_id', '' );

        if ( empty( $webhook_id ) ) {
            // #1195: surface the misconfig — silently 400-ing every event
            // is the worst possible outcome for an operator. Rate-limit
            // the log line so retries don't spam.
            if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
                if ( false === get_transient( 'tejcart_paypal_missing_webhook_id_logged' ) ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            'PayPal webhook received but webhook_id is not configured — rejecting. Configure the webhook in the PayPal developer dashboard and paste the ID into TejCart → Settings → Payments → PayPal.',
                            'error'
                        );
                    }
                    set_transient( 'tejcart_paypal_missing_webhook_id_logged', 1, HOUR_IN_SECONDS );
                }
            }
            return false;
        }

        $headers = $request->get_headers();

        $transmission_id   = $headers['paypal_transmission_id'][0] ?? '';
        $transmission_time = $headers['paypal_transmission_time'][0] ?? '';
        $transmission_sig  = $headers['paypal_transmission_sig'][0] ?? '';
        $cert_url          = $headers['paypal_cert_url'][0] ?? '';
        $auth_algo         = $headers['paypal_auth_algo'][0] ?? '';

        if ( empty( $transmission_id ) || empty( $transmission_sig ) ) {
            return false;
        }

        // Log explicitly when cert_url is absent — the whitelist check
        // below would still reject the empty value, but the log line
        // would read "not on whitelist ()" which obscures the actual
        // signal. Operators want to know "header was missing entirely"
        // distinctly from "header had a malicious / unexpected value."
        if ( '' === $cert_url ) {
            tejcart_log( 'PayPal webhook rejected: cert_url header missing.', 'error' );
            return false;
        }

        // Pin the cert URL to PayPal's domain set before we make
        // any outbound call. Without this, a header attacker can supply a
        // cert URL pointing at a host they control; PayPal's verify
        // endpoint would still reject it, but until then we'd happily issue
        // a network request to the attacker-controlled URL fragment via
        // any side-channels added later. Domain-pinning the cert here
        // preserves a meaningful defence-in-depth even if the verify call
        // itself is ever bypassed.
        if ( ! self::is_paypal_cert_url( $cert_url ) ) {
            tejcart_log(
                sprintf( 'PayPal webhook rejected: cert_url not on whitelist (%s).', $cert_url ),
                'warning'
            );
            return false;
        }

        // Cache verified webhook signatures so a retry after a degraded
        // /verify-webhook-signature endpoint can short-circuit. The cache
        // key MUST include a hash of the body (H-1): PayPal's actual
        // signature covers transmission_id|transmission_time|webhook_id|crc32(body),
        // so without binding the cache to the body, an attacker who
        // captures one valid header set can replay the same sig against
        // a forged body (e.g. PAYMENT.CAPTURE.COMPLETED for an arbitrary
        // capture_id) and the cache will say "verified".
        $body_raw      = (string) $request->get_body();
        $sig_cache_key = 'tejcart_pp_sig_' . hash(
            'sha256',
            $transmission_id . '|' . $transmission_sig . '|' . hash( 'sha256', $body_raw )
        );
        if ( '1' === (string) get_transient( $sig_cache_key ) ) {
            return true;
        }

        $access_token = $this->api->get_access_token();

        if ( is_wp_error( $access_token ) ) {
            return false;
        }

        $verify_body = array(
            'auth_algo'         => $auth_algo,
            'cert_url'          => $cert_url,
            'transmission_id'   => $transmission_id,
            'transmission_sig'  => $transmission_sig,
            'transmission_time' => $transmission_time,
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $request->get_json_params(),
        );

        $api_url  = $this->gateway->is_sandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        $response = wp_remote_post(
            $api_url . '/v1/notifications/verify-webhook-signature',
            array(
                'headers' => array(
                    'Authorization'                 => 'Bearer ' . $access_token,
                    'Content-Type'                  => 'application/json',
                    'PayPal-Partner-Attribution-Id' => PayPal_Gateway::bn_code(),
                ),
                'body'    => wp_json_encode( $verify_body ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $parsed      = \TejCart\Gateways\PayPal\PayPal_API::decode_json_response( $response );
        $status_code = $parsed['status'];
        if ( $status_code !== 200 ) {
            tejcart_log(
                'PayPal webhook verification returned HTTP ' . $status_code
                    . \TejCart\Gateways\PayPal\PayPal_API::format_response_diagnostics( $parsed ),
                'error'
            );
            return false;
        }

        if ( ! $parsed['parse_ok'] || ! isset( $parsed['decoded']['verification_status'] ) ) {
            tejcart_log(
                'PayPal webhook verification: invalid response body'
                    . \TejCart\Gateways\PayPal\PayPal_API::format_response_diagnostics( $parsed ),
                'error'
            );
            return false;
        }
        $body = $parsed['decoded'];

        $verified = 'SUCCESS' === $body['verification_status'];

        if ( $verified ) {
            // 6-hour cache mirrors the PAYPAL-TRANSMISSION-TIME replay
            // window enforced in handle_webhook(); no point caching beyond
            // the point where the same transmission_id stops being valid.
            set_transient( $sig_cache_key, '1', 6 * HOUR_IN_SECONDS );
        }

        return $verified;
    }

    /**
     * Restrict the trusted cert hosts to PayPal's published
     * domain set so a forged header can't redirect verification to an
     * attacker-controlled host. The list is filterable via
     * `tejcart_paypal_cert_url_hosts` for future PayPal infrastructure
     * changes.
     *
     * @param string $cert_url
     */
    public static function is_paypal_cert_url( string $cert_url ): bool {
        if ( '' === $cert_url ) {
            return false;
        }

        $parts = wp_parse_url( $cert_url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
            return false;
        }

        $host = strtolower( (string) $parts['host'] );
        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

        /**
         * Filter the exact-match allowlist of cert-URL hosts accepted on
         * inbound PayPal webhooks.
         *
         * Default is PayPal's published cert-distribution hosts:
         *  - `api.paypal.com` / `api.sandbox.paypal.com` — the REST
         *    webhook cert distribution endpoint (`/v1/notifications/certs/CERT-*`)
         *    referenced by the `PAYPAL-CERT-URL` header on every REST
         *    webhook (`PAYMENT.CAPTURE.*`, `CHECKOUT.ORDER.*`,
         *    `CUSTOMER.DISPUTE.*`, etc.).
         *  - `messageverificationcerts.paypal.com` and its sandbox
         *    variant — legacy IPN/Adaptive-Payments cert host, kept for
         *    backward compatibility.
         *
         * Entries are matched as EXACT hosts only. The wildcard suffix
         * branch (`*.paypal.com`) was removed in M-5 because it widened
         * the trust surface to every PayPal subdomain — including hosts
         * operated by acquired entities and marketing CDNs that may not
         * serve genuine cert chains. For the broader `api.*paypal.com`
         * hosts we additionally pin the URL path to
         * `/v1/notifications/certs/` (see below) so a forged header
         * cannot smuggle in an unrelated API path.
         *
         * If you genuinely need to accept a wider host pattern (region
         * migration, PayPal Payouts in a non-default cert host), use the
         * separate, security-sensitive {@see tejcart_paypal_cert_url_suffixes_advanced}
         * filter explicitly.
         *
         * @param string[] $allowed Host names (exact match only).
         */
        $allowed = (array) apply_filters(
            'tejcart_paypal_cert_url_hosts',
            array(
                'api.paypal.com',
                'api.sandbox.paypal.com',
                'messageverificationcerts.paypal.com',
                'messageverificationcerts.sandbox.paypal.com',
            )
        );

        // Hosts in this set are general-purpose PayPal API hosts; we only
        // trust them when the path is the REST webhook cert
        // distribution path. Without this, an attacker who controls the
        // `PAYPAL-CERT-URL` header could direct the (future) cert
        // fetcher at e.g. `https://api.paypal.com/v1/identity/...` —
        // still PayPal-served but not a cert payload.
        $path_pinned_hosts = array(
            'api.paypal.com'         => '/v1/notifications/certs/',
            'api.sandbox.paypal.com' => '/v1/notifications/certs/',
        );

        foreach ( $allowed as $entry ) {
            $entry = strtolower( trim( (string) $entry ) );
            if ( '' === $entry || 0 === strpos( $entry, '*.' ) ) {
                // Wildcards are no longer honoured here (M-5). Use the
                // dedicated `tejcart_paypal_cert_url_suffixes_advanced`
                // filter below if a suffix match is genuinely required.
                continue;
            }
            if ( $host === $entry ) {
                if ( isset( $path_pinned_hosts[ $entry ] )
                    && 0 !== strpos( $path, $path_pinned_hosts[ $entry ] )
                ) {
                    continue;
                }
                return true;
            }
        }

        /**
         * SECURITY-SENSITIVE: opt-in suffix-match allowlist for cert-URL
         * hosts. Empty by default. Each entry is a `*.host` pattern that
         * matches any host whose tail equals the suffix. Misusing this
         * filter (e.g. registering `*.paypal.com`) widens the trust
         * surface to every PayPal subdomain — only set this if you have
         * confirmed the target subdomain serves an authentic
         * paypal-issued cert chain.
         *
         * @param string[] $suffixes `*.host` patterns (suffix match).
         */
        $suffixes = (array) apply_filters( 'tejcart_paypal_cert_url_suffixes_advanced', array() );
        foreach ( $suffixes as $entry ) {
            $entry = strtolower( trim( (string) $entry ) );
            if ( '' === $entry || 0 !== strpos( $entry, '*.' ) ) {
                continue;
            }
            $suffix = substr( $entry, 1 ); // drop the '*'
            if ( '' === $suffix ) {
                continue;
            }
            if ( str_ends_with( $host, $suffix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process a webhook event based on its type.
     *
     * @param array $event The webhook event data.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function process_event( array $event ) {
        $event_type = $event['event_type'] ?? '';
        $resource   = $event['resource'] ?? array();

        switch ( $event_type ) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                return $this->handle_capture_completed( $resource );

            case 'PAYMENT.CAPTURE.DENIED':
                return $this->handle_capture_denied( $resource );

            case 'CHECKOUT.ORDER.APPROVED':
                return $this->handle_order_approved( $resource );

            case 'CHECKOUT.ORDER.COMPLETED':
                return $this->handle_order_completed( $resource );

            case 'PAYMENT.CAPTURE.REFUNDED':
                return $this->handle_capture_refunded( $resource );

            case 'PAYMENT.CAPTURE.REVERSED':
                return $this->handle_capture_reversed( $resource );

            case 'CUSTOMER.DISPUTE.CREATED':
            case 'RISK.DISPUTE.CREATED':
                return $this->handle_dispute_created( $resource );

            case 'CUSTOMER.DISPUTE.RESOLVED':
                return $this->handle_dispute_resolved( $resource );

            case 'CUSTOMER.DISPUTE.UPDATED':
                return $this->handle_dispute_updated( $resource );

            case 'VAULT.PAYMENT-TOKEN.CREATED':
            case 'VAULT.PAYMENT-TOKEN.DELETED':
                return $this->handle_vault_event( $event_type, $resource );

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
            case 'BILLING.SUBSCRIPTION.UPDATED':
            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
            case 'PAYMENT.SALE.COMPLETED':
                return $this->handle_subscription_event( $event_type, $resource );

            default:

                $event_id = isset( $event['id'] ) ? (string) $event['id'] : '';
                tejcart_log(
                    sprintf(
                        'PayPal webhook: unhandled event_type "%s" (event id %s). Add the type to EVENT_TYPES and process_event() if the plugin should act on it.',
                        (string) $event_type,
                        '' === $event_id ? 'unknown' : $event_id
                    ),
                    'warning'
                );
                /**
                 * Fires when a PayPal webhook event is received that
                 * process_event() does not handle natively. Allows
                 * extensions to react to new event types without
                 * modifying core.
                 *
                 * @param string $event_type The PayPal event type.
                 * @param array  $event      Full decoded webhook event payload.
                 */
                do_action( 'tejcart_paypal_webhook_unhandled_event', (string) $event_type, $event );
                return true;
        }
    }

    /**
     * Handle a completed payment capture.
     *
     * @param array $resource Webhook resource data.
     * @return true|\WP_Error
     */
    private function handle_capture_completed( array $resource ) {
        $transaction_id = $resource['id'] ?? '';
        $order_id       = $this->get_order_by_transaction( $transaction_id );

        // Webhook-before-AJAX race: PayPal frequently delivers
        // PAYMENT.CAPTURE.COMPLETED before the buyer's browser finishes the
        // capture AJAX round-trip that records `_paypal_capture_id`. In that
        // window the capture id (resource.id) matches no order yet, so fall
        // back to the parent PayPal order id, which is carried in the capture
        // resource's supplementary_data and was persisted as `_paypal_order_id`
        // at order-create time. Without this the order would sit `pending`
        // until the *daily* reconciler swept it, burning webhook retries and
        // emitting a false `critical` dead-letter.
        if ( ! $order_id ) {
            $parent_order_id = '';
            if ( isset( $resource['supplementary_data']['related_ids']['order_id'] ) ) {
                $parent_order_id = (string) $resource['supplementary_data']['related_ids']['order_id'];
            }
            if ( '' !== $parent_order_id ) {
                $order_id = $this->get_order_by_transaction( $parent_order_id );
            }
        }

        if ( ! $order_id ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Order not found for PayPal transaction.', 'tejcart' )
            );
        }

        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Could not load order.', 'tejcart' )
            );
        }

        $captured_amount   = isset( $resource['amount']['value'] ) ? (float) $resource['amount']['value'] : 0.0;
        $captured_currency = isset( $resource['amount']['currency_code'] ) ? strtoupper( $resource['amount']['currency_code'] ) : '';
        $order_total       = (float) $order->get_total();
        $order_currency    = strtoupper( $order->get_currency() );

        // Reject before recording any capture meta when PayPal didn't
        // tell us the currency. Falling back to the order currency
        // would silently process the event; returning WP_Error makes
        // PayPal retry and surfaces the malformed payload for review.
        if ( '' === $captured_currency ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_missing_currency',
                __( 'PayPal capture is missing currency_code.', 'tejcart' )
            );
        }

        // Audit #62 / 05 F-4 — record `_paypal_capture_id` BEFORE the
        // amount / currency mismatch branches. Previously the meta
        // write at line ~821 was unreachable when the webhook flipped
        // the order to on-hold for AMOUNT_MISMATCH / currency_mismatch,
        // which left the order in on-hold with no capture id —
        // PayPal_Refund_Capture then refused refunds because it reads
        // the meta and bails when empty. Capturing the id up-front
        // unblocks manual refunds without needing a meta backfill.
        PayPal_Gateway::record_transaction_meta( $order_id, $transaction_id );

        // Compare captured vs. expected in integer minor units against
        // the order currency. Float subtraction with a 0.01 tolerance is
        // unsafe in three-decimal currencies (KWD, BHD, OMR) and
        // meaningless in zero-decimal ones (JPY, KRW). When the two
        // currencies disagree we deliberately fall through to the
        // currency-mismatch branch below; comparing minor units across
        // currencies would silently coerce 1000 KWD ↔ 100,000 fil.
        $compare_currency = '' !== $captured_currency ? $captured_currency : $order_currency;
        $captured_minor   = \TejCart\Money\Currency::to_minor_units( $captured_amount, $compare_currency );
        $expected_minor   = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );

        if ( $captured_currency === $order_currency && $captured_minor !== $expected_minor ) {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: captured amount, 2: order total */
                    __( 'PayPal captured amount (%1$s) does not match order total (%2$s). Order placed on hold for manual review.', 'tejcart' ),
                    $captured_amount,
                    $order_total
                )
            );
            return new \WP_Error(
                'tejcart_paypal_amount_mismatch',
                __( 'Captured amount does not match order total.', 'tejcart' )
            );
        }

        if ( $captured_currency !== $order_currency ) {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: captured currency, 2: order currency */
                    __( 'PayPal captured currency (%1$s) does not match order currency (%2$s). Order placed on hold for manual review.', 'tejcart' ),
                    $captured_currency,
                    $order_currency
                )
            );
            return new \WP_Error(
                'tejcart_paypal_currency_mismatch',
                __( 'Captured currency does not match order currency.', 'tejcart' )
            );
        }

        $current_status = $order->get_status();
        // `on-hold` is included in the skip set: by this point the capture's
        // amount and currency have already reconciled (the mismatch branches
        // above return early), so any remaining on-hold was set by another
        // path — an SCA/3DS failure, a fraud-review hold, or a capture-id
        // collision — that a human is meant to clear. Auto-lifting it here
        // would silently release the hold and trigger fulfilment. Leave it for
        // manual review; the capture id was already recorded above so refunds
        // and the timeline note still work.
        if ( in_array( $current_status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
            tejcart_log( sprintf( 'PayPal capture webhook skipped: order #%d already has status "%s".', $order_id, $current_status ) );
            return true;
        }

        // Audit #62 / 05 F-4 — record_transaction_meta moved above the
        // mismatch branches; no longer needed here.

        $order->update_status( 'processing', __( 'PayPal payment captured.', 'tejcart' ) );

        /**
         * Fires when a PayPal payment is completed.
         *
         * @param int    $order_id       Order ID.
         * @param object $order          Order object.
         * @param string $transaction_id PayPal transaction ID.
         */
        do_action( 'tejcart_payment_complete', $order_id, $order, $transaction_id );

        return true;
    }

    /**
     * Handle a denied payment capture.
     *
     * @param array $resource Webhook resource data.
     * @return true|\WP_Error
     */
    private function handle_capture_denied( array $resource ) {
        $transaction_id = $resource['id'] ?? '';
        $order_id       = $this->get_order_by_transaction( $transaction_id );

        if ( ! $order_id ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Order not found for denied PayPal transaction.', 'tejcart' )
            );
        }

        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Could not load order.', 'tejcart' )
            );
        }

        $order->update_status( 'failed', __( 'PayPal payment was denied.', 'tejcart' ) );

        /**
         * Fires when a PayPal payment is denied.
         *
         * @param int    $order_id       Order ID.
         * @param object $order          Order object.
         * @param string $transaction_id PayPal transaction ID.
         */
        do_action( 'tejcart_payment_failed', $order_id, $order, $transaction_id );

        return true;
    }

    /**
     * Handle an approved checkout order by capturing it.
     *
     * @param array $resource Webhook resource data.
     * @return true|\WP_Error
     */
    private function handle_order_approved( array $resource ) {
        $paypal_order_id = $resource['id'] ?? '';

        if ( empty( $paypal_order_id ) ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Missing PayPal order ID in approved event.', 'tejcart' )
            );
        }

        $order_id = $this->get_order_by_transaction( $paypal_order_id );
        if ( ! $order_id ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'No matching TejCart order found for PayPal order ID.', 'tejcart' )
            );
        }

        // P-H2: pass the same deterministic idempotency key the buyer's
        // synchronous capture AJAX uses (PayPal_AJAX::capture_order →
        // Idempotency_Key::for_capture( $paypal_order_id, 1 )). Without a
        // PayPal-Request-Id the approval webhook and the AJAX path can
        // race into a genuine double-capture; sharing the key makes both
        // collide on PayPal's idempotency window so only one capture wins.
        $request_id     = Idempotency_Key::for_capture( $paypal_order_id, 1 );
        $capture_result = $this->api->capture_order( $paypal_order_id, $request_id );

        if ( is_wp_error( $capture_result ) ) {
            return $capture_result;
        }

        return true;
    }

    /**
     * Handle a completed checkout order.
     *
     * @param array $resource Webhook resource data.
     * @return true|\WP_Error
     */
    private function handle_order_completed( array $resource ) {
        $paypal_order_id = $resource['id'] ?? '';

        if ( empty( $paypal_order_id ) ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Missing PayPal order ID in completed event.', 'tejcart' )
            );
        }

        $order_id = $this->get_order_by_transaction( $paypal_order_id );

        if ( ! $order_id ) {
            tejcart_log( sprintf( 'PayPal CHECKOUT.ORDER.COMPLETED: no matching order for PayPal ID %s', $paypal_order_id ) );
            return true;
        }

        $order = tejcart_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error(
                'tejcart_paypal_webhook_error',
                __( 'Could not load order.', 'tejcart' )
            );
        }

        $current_status = $order->get_status();
        // Skip `on-hold` too: a hold set by an SCA/fraud/collision path must
        // be cleared by a human, not silently lifted by this event.
        if ( in_array( $current_status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
            return true;
        }

        $order->update_status( 'processing', __( 'PayPal checkout order completed.', 'tejcart' ) );

        return true;
    }

    /**
     * Resolve the parent capture ID for a refund / reversal resource by
     * following the `up` HATEOAS link. Shared by the REFUNDED and
     * REVERSED handlers.
     *
     * @param array $resource Webhook resource data.
     * @return string Parent capture ID, or '' if absent.
     */
    private function parent_capture_id_from_resource( array $resource ): string {
        if ( empty( $resource['links'] ) || ! is_array( $resource['links'] ) ) {
            return '';
        }
        foreach ( $resource['links'] as $link ) {
            if ( isset( $link['rel'] ) && 'up' === $link['rel'] && ! empty( $link['href'] ) ) {
                $parts = explode( '/', untrailingslashit( (string) $link['href'] ) );
                return (string) end( $parts );
            }
        }
        return '';
    }

    /**
     * Handle a PAYMENT.CAPTURE.REVERSED event.
     *
     * P-M1: a reversal is a bank reversal / chargeback, NOT a merchant
     * refund. It must not flow through handle_capture_refunded (which
     * marks the order `refunded`). Record the reversal as a refund row
     * so the money is accounted for, but leave a distinct "payment
     * reversed" note, fire a distinct action, and place a fulfilled
     * order `on-hold` for merchant review rather than silently treating
     * it as a clean refund.
     *
     * @param array $resource Webhook resource data (a reversal object).
     * @return true|\WP_Error
     */
    private function handle_capture_reversed( array $resource ) {
        $reversal_id = isset( $resource['id'] ) ? (string) $resource['id'] : '';
        $capture_id  = $this->parent_capture_id_from_resource( $resource );

        if ( '' === $capture_id ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Reversal webhook missing parent capture ID.', 'tejcart' ) );
        }

        $order_id = $this->get_order_by_transaction( $capture_id );
        if ( ! $order_id ) {
            tejcart_log( sprintf( 'PayPal reversal webhook: no matching order for capture %s', $capture_id ) );
            return true;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Could not load order.', 'tejcart' ) );
        }

        $reversal_amount = isset( $resource['amount']['value'] ) ? (float) $resource['amount']['value'] : 0.0;

        // Atomic persistence via UNIQUE KEY transaction_ref so a replayed
        // reversal webhook can never double-record the same reversal.
        $refund_record = new \TejCart\Order\Order_Refund( array(
            'order_id'        => $order_id,
            'transaction_ref' => $reversal_id,
            'amount'          => $reversal_amount,
            'reason'          => __( 'PayPal payment reversed (bank reversal / chargeback)', 'tejcart' ),
            'date'            => current_time( 'mysql' ),
        ) );
        $refund_record->save();

        $reversal_currency = isset( $resource['amount']['currency_code'] )
            ? strtoupper( (string) $resource['amount']['currency_code'] )
            : strtoupper( (string) ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' ) );

        if ( method_exists( $order, 'add_note' ) ) {
            $reversal_amount_str = \TejCart\Gateways\PayPal\PayPal_API::format_amount( $reversal_amount, $reversal_currency );
            $order->add_note(
                sprintf(
                    /* translators: 1: reversed amount, 2: currency, 3: PayPal reversal id, 4: parent capture id */
                    __( 'PayPal payment reversed: %1$s %2$s (reversal ID %3$s, capture ID %4$s). This is a bank reversal/chargeback — review and respond in the PayPal dashboard.', 'tejcart' ),
                    $reversal_amount_str,
                    $reversal_currency,
                    $reversal_id,
                    $capture_id
                )
            );
        }

        // Move a still-fulfillable order to on-hold so it is pulled from
        // the fulfilment queue pending the dispute outcome. A reversal is
        // not a clean refund, so we never set the `refunded` status here.
        if ( method_exists( $order, 'update_status' ) && method_exists( $order, 'get_status' ) ) {
            $current = $order->get_status();
            if ( ! in_array( $current, array( 'on-hold', 'cancelled', 'refunded' ), true ) ) {
                $order->update_status( 'on-hold', __( 'PayPal payment reversed — order placed on hold pending review.', 'tejcart' ) );
            }
        }

        /**
         * Fires when a PayPal capture is reversed (bank reversal /
         * chargeback). Distinct from `tejcart_paypal_refund_recorded`
         * so listeners can react to disputes specifically.
         *
         * @param int    $order_id        Order ID.
         * @param object $order           Order object.
         * @param string $reversal_id     PayPal reversal transaction ID.
         * @param string $capture_id      Parent capture ID.
         * @param float  $reversal_amount Reversed amount.
         */
        do_action( 'tejcart_paypal_payment_reversed', $order_id, $order, $reversal_id, $capture_id, $reversal_amount );

        return true;
    }

    /**
     * Handle a PAYMENT.CAPTURE.REFUNDED event.
     *
     * Locates the matching TejCart order via the parent capture ID stored
     * on order meta and records the refund. Marks the order refunded if the
     * cumulative refund total now equals the order total.
     *
     * @param array $resource Webhook resource data (a refund object).
     * @return true|\WP_Error
     */
    private function handle_capture_refunded( array $resource ) {
        $refund_id = isset( $resource['id'] ) ? (string) $resource['id'] : '';

        $capture_id = '';
        if ( ! empty( $resource['links'] ) && is_array( $resource['links'] ) ) {
            foreach ( $resource['links'] as $link ) {
                if ( isset( $link['rel'] ) && 'up' === $link['rel'] && ! empty( $link['href'] ) ) {
                    $parts      = explode( '/', untrailingslashit( (string) $link['href'] ) );
                    $capture_id = (string) end( $parts );
                    break;
                }
            }
        }

        if ( empty( $capture_id ) ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Refund webhook missing parent capture ID.', 'tejcart' ) );
        }

        $order_id = $this->get_order_by_transaction( $capture_id );

        if ( ! $order_id ) {
            tejcart_log( sprintf( 'PayPal refund webhook: no matching order for capture %s', $capture_id ) );
            return true;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Could not load order.', 'tejcart' ) );
        }

        $refund_amount = isset( $resource['amount']['value'] ) ? (float) $resource['amount']['value'] : 0.0;

        // Atomic persistence via UNIQUE KEY transaction_ref. INSERT IGNORE means
        // a replayed webhook (or two PayPal-side refunds delivered concurrently)
        // can never double-record the same gateway refund id.
        $refund_record = new \TejCart\Order\Order_Refund( array(
            'order_id'        => $order_id,
            'transaction_ref' => $refund_id,
            'amount'          => $refund_amount,
            'reason'          => __( 'PayPal refund', 'tejcart' ),
            'date'            => current_time( 'mysql' ),
        ) );
        $refund_record->save();

        // Authoritative total via SQL aggregate; no read-modify-write race.
        $total_refunded = (float) \TejCart\Order\Order_Refund::get_total_refunded( $order_id );

        // Maintain the denormalised meta cache for backward compatibility,
        // deduplicated by refund id so concurrent writes converge.
        $existing = tejcart_get_order_meta( $order_id, '_paypal_refunds' );
        $refunds  = is_array( $existing ) ? $existing : array();
        $by_id    = array();
        foreach ( $refunds as $r ) {
            if ( isset( $r['id'] ) && '' !== (string) $r['id'] ) {
                $by_id[ (string) $r['id'] ] = $r;
            }
        }
        $by_id[ $refund_id ] = array(
            'id'     => $refund_id,
            'amount' => $refund_amount,
            'time'   => time(),
        );
        tejcart_update_order_meta( $order_id, '_paypal_refunds', array_values( $by_id ) );
        tejcart_update_order_meta( $order_id, '_paypal_last_refund_id', $refund_id );

        // Append an order note for every refund (full or partial)
        // so disputes / chargeback investigations have an in-order audit
        // trail instead of having to cross-reference webhook logs with the
        // PayPal merchant dashboard.
        $refund_currency = isset( $resource['amount']['currency_code'] )
            ? strtoupper( (string) $resource['amount']['currency_code'] )
            : strtoupper( (string) ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' ) );
        if ( method_exists( $order, 'add_note' ) ) {
            // Format with currency-aware precision (JPY=0, USD=2,
            // KWD/BHD/OMR=3) — number_format(.., 2, ..) used to drop
            // the third decimal of dinar refunds in the audit trail.
            $refund_amount_str = \TejCart\Gateways\PayPal\PayPal_API::format_amount( $refund_amount, $refund_currency );
            $order->add_note(
                sprintf(
                    /* translators: 1: refund amount, 2: refund currency, 3: PayPal refund id, 4: parent capture id */
                    __( 'PayPal refund of %1$s %2$s recorded (refund ID %3$s, capture ID %4$s).', 'tejcart' ),
                    $refund_amount_str,
                    $refund_currency,
                    $refund_id,
                    $capture_id
                )
            );
        }

        // "Order is now fully refunded" check in integer minor units
        // against the order's stored currency. Float comparison would
        // mis-fire on three-decimal currencies (KWD, BHD, OMR) and the
        // 0.01 tolerance is meaningless in zero-decimal currencies (JPY).
        if ( method_exists( $order, 'get_total' ) && method_exists( $order, 'get_currency' ) ) {
            $order_currency_for_compare = strtoupper( (string) $order->get_currency() );
            $total_refunded_minor       = \TejCart\Money\Currency::to_minor_units( $total_refunded, $order_currency_for_compare );
            $order_total_minor          = \TejCart\Money\Currency::to_minor_units( (float) $order->get_total(), $order_currency_for_compare );

            if ( $total_refunded_minor >= $order_total_minor ) {
                if ( method_exists( $order, 'update_status' ) && 'refunded' !== $order->get_status() ) {
                    $order->update_status( 'refunded', __( 'PayPal refund completed in full.', 'tejcart' ) );
                }
            } elseif ( $total_refunded_minor > 0 ) {
                // Partial refund issued from the PayPal dashboard (not the
                // TejCart admin path). Mirror Order_Manager::process_refund so
                // both refund origins leave the order in the same state —
                // otherwise dashboard-issued partial refunds stay `processing`
                // /`completed` and downstream accounting/BI exports that key on
                // status silently miss them.
                $partial_eligible = array( 'processing', 'completed', 'partially-refunded' );
                if ( method_exists( $order, 'update_status' )
                    && in_array( $order->get_status(), $partial_eligible, true )
                    && 'partially-refunded' !== $order->get_status()
                ) {
                    $order->update_status( 'partially-refunded', __( 'PayPal partial refund recorded.', 'tejcart' ) );
                }
            }
        }

        return true;
    }

    /**
     * Handle VAULT.PAYMENT-TOKEN.CREATED / DELETED events.
     *
     * For DELETED events we walk every customer's saved methods list and
     * remove any entry whose token_id matches, so a token revoked at PayPal
     * can never be presented to the buyer again.
     *
     * @param string $event_type Event type.
     * @param array  $resource   Resource payload.
     * @return true
     */
    private function handle_vault_event( string $event_type, array $resource ) {
        $token_id = $resource['id'] ?? '';
        if ( empty( $token_id ) ) {
            return true;
        }

        if ( 'VAULT.PAYMENT-TOKEN.DELETED' === $event_type ) {
            global $wpdb;
            $meta_key   = \TejCart\Customer\Payment_Methods::META_KEY;
            $token_hash = \TejCart\Security\Crypto::hash( $token_id );

            // Indexed lookup via the per-token index rows that
            // Payment_Methods::save_method maintains (one
            // `_tejcart_pp_token_<hash>` user_meta row per token,
            // keyed by `meta_key` which is indexed). Two indexed
            // PRIMARY-KEY-on-meta_key seeks replace the previous
            // leading-wildcard `meta_value LIKE %hash%` full-table
            // scan that pinned the DB for seconds per event on
            // stores with sizeable customer bases.
            $index_keys = array(
                '_tejcart_pp_token_' . $token_hash,
                '_tejcart_pp_token_' . \TejCart\Security\Crypto::hash( $token_id ),
            );
            $index_keys = array_unique( $index_keys );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $placeholders = implode( ',', array_fill( 0, count( $index_keys ), '%s' ) );
            $user_ids     = (array) $wpdb->get_col(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
                    ...$index_keys
                )
            );

            // Legacy fallback: stores upgraded from a pre-index
            // version still carry tokens in user_meta without the
            // parallel index rows. If the indexed lookup returned
            // nothing, fall through to the old leading-wildcard
            // path so those installs continue working until their
            // next save_method() backfills the index. New stores
            // never hit this branch.
            if ( empty( $user_ids ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $legacy_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 50",
                        $meta_key,
                        '%' . $wpdb->esc_like( $token_hash ) . '%',
                        '%' . $wpdb->esc_like( $token_id ) . '%'
                    )
                );
                $rows = $legacy_rows;
            } else {
                // Fetch only the methods rows for the resolved users —
                // bounded set, no LIKE, no full-table scan.
                $user_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $rows = $wpdb->get_results(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare(
                        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ({$user_placeholders})",
                        array_merge( array( $meta_key ), array_map( 'intval', $user_ids ) )
                    )
                );
            }

            foreach ( (array) $rows as $row ) {
                $methods = maybe_unserialize( $row->meta_value, array( 'allowed_classes' => false ) );
                if ( ! is_array( $methods ) ) {
                    continue;
                }
                $filtered = array_values(
                    array_filter(
                        $methods,
                        static function ( $m ) use ( $token_id, $token_hash ) {
                            if ( ( $m['token_hash'] ?? '' ) === $token_hash ) {
                                return false;
                            }

                            return ( $m['token_id'] ?? '' ) !== $token_id;
                        }
                    )
                );
                if ( count( $filtered ) !== count( $methods ) ) {
                    update_user_meta( (int) $row->user_id, $meta_key, $filtered );
                }
            }
        }

        do_action( 'tejcart_paypal_vault_event', $event_type, $token_id, $resource );
        return true;
    }

    /**
     * Handle BILLING.SUBSCRIPTION.* and recurring PAYMENT.SALE.COMPLETED events.
     *
     * Records the latest subscription status on a generic option keyed by
     * the PayPal subscription ID and fires an action so subscription
     * extensions can react. We intentionally do not couple to a specific
     * subscription post type — that lives in a higher-level extension.
     *
     * @param string $event_type Event type string.
     * @param array  $resource   Resource payload.
     * @return true
     */
    private function handle_subscription_event( string $event_type, array $resource ) {
        $subscription_id = $resource['id']
            ?? $resource['billing_agreement_id']
            ?? '';

        if ( empty( $subscription_id ) ) {
            return true;
        }

        $option_key = 'tejcart_paypal_sub_' . hash( 'sha256', (string) $subscription_id );
        update_option(
            $option_key,
            array(
                'event_type' => $event_type,
                'status'     => $resource['status'] ?? '',
                'updated_at' => time(),
            ),
            'no'
        );

        /**
         * Fires when a PayPal subscription webhook arrives.
         *
         * @param string $event_type      Event type.
         * @param string $subscription_id PayPal subscription/agreement ID.
         * @param array  $resource        Raw resource payload.
         */
        do_action( 'tejcart_paypal_subscription_event', $event_type, $subscription_id, $resource );

        return true;
    }

    /**
     * Find a TejCart order ID by PayPal transaction/capture ID.
     *
     * @param string $transaction_id PayPal transaction ID.
     * @return int|null Order ID or null if not found.
     */
    private function get_order_by_transaction( string $transaction_id ): ?int {
        $id = PayPal_Gateway::find_order_id_by_paypal_id( $transaction_id );
        // Collision is distinct from "not found": the critical log line
        // and `tejcart_paypal_id_collision` action have already fired
        // inside find_order_id_by_paypal_id(). Return null here so the
        // event is skipped (fail-closed) but the audit trail captures
        // the ambiguity for operator review.
        if ( PayPal_Gateway::PAYPAL_ID_COLLISION === $id ) {
            return null;
        }
        return $id > 0 ? $id : null;
    }

    /**
     * Resolve the TejCart order referenced by a dispute resource.
     *
     * The capture/transaction ID lives inside disputed_transactions[] rather
     * than at the resource root for dispute events.
     *
     * @param array $resource Dispute resource payload.
     * @return int|null Order ID or null.
     */
    private function get_order_from_dispute( array $resource ): ?int {
        if ( empty( $resource['disputed_transactions'] ) || ! is_array( $resource['disputed_transactions'] ) ) {
            return null;
        }

        foreach ( $resource['disputed_transactions'] as $tx ) {
            $candidate = '';
            if ( is_array( $tx ) ) {
                $candidate = (string) ( $tx['seller_transaction_id'] ?? $tx['buyer_transaction_id'] ?? '' );
            }
            if ( '' === $candidate ) {
                continue;
            }
            $order_id = $this->get_order_by_transaction( $candidate );
            if ( $order_id ) {
                return $order_id;
            }
        }

        return null;
    }

    /**
     * Handle CUSTOMER.DISPUTE.CREATED and RISK.DISPUTE.CREATED.
     *
     * Places the order on-hold, preserves the pre-dispute status so the
     * resolution handler can restore it on SELLER_FAVOR, and triggers the
     * admin notification email.
     *
     * @param array $resource Dispute resource payload.
     * @return true|\WP_Error
     */
    private function handle_dispute_created( array $resource ) {
        $order_id = $this->get_order_from_dispute( $resource );
        if ( ! $order_id ) {
            tejcart_log( 'PayPal dispute webhook: no matching order for dispute ' . ( $resource['dispute_id'] ?? '' ) );
            return true;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Could not load order for dispute.', 'tejcart' ) );
        }

        $dispute_id       = (string) ( $resource['dispute_id'] ?? '' );
        $reason           = (string) ( $resource['reason'] ?? '' );
        $amount           = isset( $resource['dispute_amount']['value'] ) ? (float) $resource['dispute_amount']['value'] : 0.0;
        $dispute_currency = isset( $resource['dispute_amount']['currency_code'] )
            ? strtoupper( (string) $resource['dispute_amount']['currency_code'] )
            : strtoupper( (string) ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' ) );
        // Currency-aware format so JPY / KWD don't drop or fabricate a
        // decimal in the audit-trail note.
        $amount_formatted = '' !== $dispute_currency
            ? \TejCart\Gateways\PayPal\PayPal_API::format_amount( $amount, $dispute_currency )
            : (string) $amount;

        if ( '' === (string) tejcart_get_order_meta( $order_id, '_tejcart_predispute_status' ) ) {
            tejcart_update_order_meta( $order_id, '_tejcart_predispute_status', (string) $order->get_status() );
        }

        tejcart_update_order_meta( $order_id, '_paypal_dispute_id', $dispute_id );
        tejcart_update_order_meta( $order_id, '_paypal_dispute_reason', $reason );

        if ( method_exists( $order, 'update_status' ) && 'on-hold' !== $order->get_status() ) {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: dispute id, 2: reason, 3: amount */
                    __( 'PayPal dispute opened (ID %1$s, reason: %2$s, amount: %3$s).', 'tejcart' ),
                    $dispute_id,
                    $reason,
                    $amount_formatted
                )
            );
        } elseif ( method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: dispute id, 2: reason */
                    __( 'PayPal dispute opened (ID %1$s, reason: %2$s).', 'tejcart' ),
                    $dispute_id,
                    $reason
                )
            );
        }

        /**
         * Fires when a PayPal dispute is created.
         *
         * @since 1.0.0
         *
         * @param int    $order_id   Order ID.
         * @param string $dispute_id Dispute ID.
         * @param array  $resource   Raw webhook resource.
         */
        do_action( 'tejcart_paypal_dispute_created', $order_id, $dispute_id, $resource );

        return true;
    }

    /**
     * Handle CUSTOMER.DISPUTE.RESOLVED.
     *
     * @param array $resource Dispute resource payload.
     * @return true|\WP_Error
     */
    private function handle_dispute_resolved( array $resource ) {
        $order_id = $this->get_order_from_dispute( $resource );
        if ( ! $order_id ) {
            return true;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return new \WP_Error( 'tejcart_paypal_webhook_error', __( 'Could not load order for dispute.', 'tejcart' ) );
        }

        $dispute_id = (string) ( $resource['dispute_id'] ?? '' );
        // PayPal's customer disputes API places the outcome under
        // `dispute_outcome.outcome_code` (per customer_disputes_v1 spec).
        // Older docs / sandbox tooling occasionally surface `outcome.outcome_code`
        // or a flat `outcome` string, so accept all three.
        $outcome = strtoupper( (string) (
            $resource['dispute_outcome']['outcome_code']
                ?? $resource['outcome']['outcome_code']
                ?? $resource['outcome']
                ?? ''
        ) );

        if ( method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: dispute id, 2: outcome */
                    __( 'PayPal dispute resolved (ID %1$s, outcome: %2$s).', 'tejcart' ),
                    $dispute_id,
                    $outcome
                )
            );
        }

        // Per the customer_disputes_v1 spec the buyer-favour code is
        // `RESOLVED_BUYER_FAVOUR` (British spelling). PayPal also uses the
        // shorter `BUYER_FAVOR` in the adjudication-outcome path and some
        // sandbox payloads, so accept all spellings.
        if ( in_array( $outcome, array( 'RESOLVED_BUYER_FAVOUR', 'RESOLVED_BUYER_FAVOR', 'BUYER_FAVOR' ), true ) ) {
            if ( method_exists( $order, 'update_status' ) && 'refunded' !== $order->get_status() ) {
                $order->update_status( 'refunded', __( 'PayPal dispute resolved in buyer favor.', 'tejcart' ) );
            }
        } elseif ( in_array( $outcome, array( 'RESOLVED_SELLER_FAVOUR', 'RESOLVED_SELLER_FAVOR', 'SELLER_FAVOR' ), true ) ) {
            $prior = (string) tejcart_get_order_meta( $order_id, '_tejcart_predispute_status' );
            if ( '' !== $prior && method_exists( $order, 'update_status' ) && $prior !== $order->get_status() ) {
                $order->update_status( $prior, __( 'PayPal dispute resolved in seller favor; prior status restored.', 'tejcart' ) );
            }
        }

        /**
         * Fires when a PayPal dispute is resolved.
         *
         * @since 1.0.0
         *
         * @param int    $order_id   Order ID.
         * @param string $dispute_id Dispute ID.
         * @param string $outcome    Outcome code (BUYER_FAVOR, SELLER_FAVOR, etc.).
         * @param array  $resource   Raw webhook resource.
         */
        do_action( 'tejcart_paypal_dispute_resolved', $order_id, $dispute_id, $outcome, $resource );

        return true;
    }

    /**
     * Handle CUSTOMER.DISPUTE.UPDATED.
     *
     * Fires `tejcart_paypal_dispute_updated` so the disputes module (and
     * any other subscriber) can keep its local row in sync with PayPal's
     * lifecycle changes — `WAITING_FOR_SELLER_RESPONSE`, status / stage
     * transitions, evidence-due window refreshes, etc. Without this the
     * disputes-queue row would freeze at its initial status until the
     * dispute resolves, which makes the evidence-due reminder cron lie
     * about whose attention is needed.
     *
     * @param array $resource Dispute resource payload.
     * @return true
     */
    private function handle_dispute_updated( array $resource ) {
        $dispute_id = (string) ( $resource['dispute_id'] ?? '' );
        $status     = (string) ( $resource['status'] ?? '' );
        $stage      = (string) ( $resource['dispute_life_cycle_stage'] ?? '' );

        $order_id = $this->get_order_from_dispute( $resource );

        if ( $order_id ) {
            $order = tejcart_get_order( $order_id );
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: dispute id, 2: status, 3: stage */
                        __( 'PayPal dispute updated (ID %1$s, status: %2$s, stage: %3$s).', 'tejcart' ),
                        $dispute_id,
                        $status,
                        $stage
                    )
                );
            }
        }

        /**
         * Fires when a PayPal dispute lifecycle event lands. Subscribers
         * (the disputes module's `Disputes::on_paypal_dispute_updated`)
         * mirror the new status / stage / evidence-due into the unified
         * disputes table so the admin queue and the evidence reminder
         * cron see live values.
         *
         * @since 1.1.0
         *
         * @param int    $order_id   Order ID (or 0 if no match).
         * @param string $dispute_id PayPal dispute ID.
         * @param array  $resource   Raw webhook resource.
         */
        do_action( 'tejcart_paypal_dispute_updated', (int) $order_id, $dispute_id, $resource );

        return true;
    }
}
