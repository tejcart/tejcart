<?php
/**
 * Outgoing Webhooks - Event notifications to external URLs.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Allows merchants to register webhook URLs that receive event notifications
 * when orders, products, or customers are created/updated.
 */
class Outgoing_Webhooks {
    /**
     * Option name for storing webhook configurations.
     *
     * @var string
     */
    const OPTION_KEY = 'tejcart_webhooks';

    /**
     * Supported webhook events.
     *
     * @var array
     */
    const EVENTS = array(
        'order.created',
        'order.updated',
        'order.completed',
        'order.refunded',
        'order.partially_refunded',
        'product.created',
        'product.updated',
        'product.deleted',
        'product.stock_changed',
        'customer.created',
    );

    /**
     * Return the effective event catalog, after the
     * `tejcart_outgoing_webhook_events` filter has had a chance to
     * extend it.
     *
     * Callers MUST use this accessor (not the raw {@see EVENTS}
     * constant) when intersecting / iterating events so first-party
     * modules and 3rd parties can register additional events (N-M3 /
     * fixes the orphan listener in `returns` and `analytics`).
     *
     * Filter input is normalised to a unique, string-only list. The
     * baseline core events are always present — extensions can only
     * add, not remove.
     *
     * @return string[]
     */
    public static function get_events(): array {
        $events = self::EVENTS;
        if ( function_exists( 'apply_filters' ) ) {
            /**
             * Filter the list of deliverable outgoing webhook events.
             *
             * @param string[] $events Catalog of event names. Baseline
             *                         core events are always re-added
             *                         after filtering.
             */
            $events = apply_filters( 'tejcart_outgoing_webhook_events', $events );
        }
        if ( ! is_array( $events ) ) {
            return self::EVENTS;
        }
        $events = array_values( array_unique( array_filter( array_map( 'strval', $events ) ) ) );
        // Defensive: core events are always available.
        return array_values( array_unique( array_merge( self::EVENTS, $events ) ) );
    }

    /**
     * Default maximum number of delivery retries.
     *
     * Use {@see self::get_max_retries()} to read the effective value, which
     * applies the `tejcart_webhook_max_retries` filter.
     *
     * @var int
     */
    const MAX_RETRIES = 5;

    /**
     * Default backoff schedule (seconds) for retry attempts 1..N.
     *
     * Index 0 is the delay before attempt 2, index 1 before attempt 3, etc.
     * Industry-standard exponential schedule: 1m, 5m, 30m, 4h, 24h, capping
     * total retry window at ~28h of best-effort delivery.
     *
     * @var int[]
     */
    const RETRY_BACKOFF = array( 60, 300, 1800, 14400, 86400 );

    /**
     * Effective maximum retry count, filterable.
     *
     * @return int
     */
    public static function get_max_retries(): int {
        $max = (int) apply_filters( 'tejcart_webhook_max_retries', self::MAX_RETRIES );
        return max( 0, $max );
    }

    /**
     * Effective backoff schedule (seconds), filterable.
     *
     * @return int[]
     */
    public static function get_retry_backoff(): array {
        $schedule = apply_filters( 'tejcart_webhook_retry_backoff', self::RETRY_BACKOFF );
        if ( ! is_array( $schedule ) || empty( $schedule ) ) {
            return self::RETRY_BACKOFF;
        }
        return array_values( array_map( 'intval', $schedule ) );
    }

    /**
     * The single instance of this class.
     *
     * @var Outgoing_Webhooks|null
     */
    private static $instance = null;

    /**
     * Returns the single instance of this class.
     *
     * @return Outgoing_Webhooks
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the process-wide singleton. Test-substitution seam (#1242).
     *
     * Pass null to reset to lazy construction on the next `instance()`
     * call. Tests / DI overrides can hand in a fake to exercise call
     * sites that resolve through `Outgoing_Webhooks::instance()`.
     *
     * @internal Use in tests and DI overrides only.
     * @param Outgoing_Webhooks|null $instance Instance to install, or null to clear.
     */
    public static function set_instance( ?Outgoing_Webhooks $instance ): void {
        if ( ! defined( 'TEJCART_TESTING' ) || ! TEJCART_TESTING ) { return; }
        self::$instance = $instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Initialize webhooks: hook into order/product/customer actions and register admin page.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'tejcart_order_created', array( $this, 'on_order_created' ), 10, 1 );
        add_action( 'tejcart_order_status_changed', array( $this, 'on_order_updated' ), 10, 3 );

        add_action( 'tejcart_product_created', array( $this, 'on_product_created' ), 10, 1 );
        add_action( 'tejcart_product_updated', array( $this, 'on_product_updated' ), 10, 1 );
        add_action( 'tejcart_product_deleted', array( $this, 'on_product_deleted' ), 10, 1 );
        add_action( 'tejcart_product_stock_changed', array( $this, 'on_product_stock_changed' ), 10, 3 );

        // N-H5 (follow-up to F-H4): a partial refund (Order_Refund::save())
        // does NOT transition the order to `refunded` — so `order.refunded`
        // never fires for partial refunds. External systems (accounting,
        // fulfilment, BI) silently miss them. Hook the dedicated
        // partial-refund action introduced for F-H4 and emit a sibling
        // `order.partially_refunded` event with the refund row attached.
        add_action( 'tejcart_partial_refund_created', array( $this, 'on_partial_refund_created' ), 10, 3 );

        // F-H11 / #934: the canonical hook fired by Checkout::process()
        // at line 983 is `tejcart_created_customer`. The legacy
        // `tejcart_customer_created` name is never fired anywhere in
        // core — leaving this listener silently inert before. Keep
        // both for back-compat with any 3rd-party that fires the legacy
        // name; modern core fires the canonical name.
        add_action( 'tejcart_created_customer', array( $this, 'on_customer_created' ), 10, 1 );
        add_action( 'tejcart_customer_created', array( $this, 'on_customer_created' ), 10, 1 );

        add_action( 'tejcart_webhook_retry', array( $this, 'handle_retry' ), 10, 4 );

        add_action( 'tejcart_webhook_fanout', array( $this, 'handle_fanout' ), 10, 3 );
        add_action( 'tejcart_webhook_send', array( $this, 'handle_send' ), 10, 4 );

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        }

        add_action( 'wp_ajax_tejcart_test_webhook', array( $this, 'ajax_test_webhook' ) );
    }

    /**
     * Validate a webhook URL is safe to deliver to (SSRF protection).
     *
     * Blocks private/reserved IP ranges and non-HTTP(S) schemes to prevent
     * server-side request forgery on high-traffic deployments.
     *
     * @param string $url The URL to validate.
     * @return bool True if safe to use as a webhook target.
     */
    private function is_safe_webhook_url( string $url ): bool {
        if ( empty( $url ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        if ( ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
        if ( '' === $host ) {
            return false;
        }

        $bare_host = trim( $host, '[]' );

        $blocked_hosts = array( 'localhost', 'localhost.localdomain', '0', '0.0.0.0', '::', '::1' );
        if ( in_array( $bare_host, $blocked_hosts, true ) ) {
            return false;
        }
        foreach ( array( '.local', '.localhost', '.internal', '.intranet', '.corp', '.lan', '.home' ) as $suffix ) {
            if ( substr( $bare_host, -strlen( $suffix ) ) === $suffix ) {
                return false;
            }
        }

        $ips = array();
        if ( filter_var( $bare_host, FILTER_VALIDATE_IP ) ) {
            $ips[] = $bare_host;
        } else {
            foreach ( array( DNS_A, DNS_AAAA ) as $type ) {
                $records = @dns_get_record( $bare_host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( ! is_array( $records ) ) {
                    continue;
                }
                foreach ( $records as $rec ) {
                    if ( ! empty( $rec['ip'] ) ) {
                        $ips[] = $rec['ip'];
                    } elseif ( ! empty( $rec['ipv6'] ) ) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
            if ( empty( $ips ) ) {
                return false;
            }
        }

        $metadata_ips = array(
            '169.254.169.254',
            '100.100.100.200',
            'fd00:ec2::254',
        );

        foreach ( $ips as $ip ) {
            if ( in_array( $ip, $metadata_ips, true ) ) {
                return false;
            }

            if ( false === filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register a new webhook.
     *
     * @param string $url    The URL to receive webhook notifications.
     * @param array  $events Array of event names to subscribe to.
     * @param string $secret Secret key for signature verification.
     * @return array|\WP_Error The created webhook data, or WP_Error if the URL is unsafe.
     */
    public function register_webhook( string $url, array $events, string $secret = '' ) {
        if ( ! $this->is_safe_webhook_url( $url ) ) {
            return new \WP_Error( 'invalid_webhook_url', __( 'The webhook URL must be a publicly accessible http(s) address.', 'tejcart' ) );
        }

        $webhooks = $this->get_webhooks();

        $webhook = array(
            'id'         => wp_generate_uuid4(),
            'url'        => esc_url_raw( $url ),
            'secret'     => $secret ?: wp_generate_password( 32, false ),
            'events'     => array_values( array_intersect( $events, self::get_events() ) ),
            'status'     => 'active',
            'created_at' => current_time( 'mysql' ),
        );

        $webhooks[] = $webhook;
        $persisted = $this->persist_webhooks( $webhooks );
        if ( is_wp_error( $persisted ) ) {
            return $persisted;
        }

        return $webhook;
    }

    /**
     * Delete a webhook by ID.
     *
     * @param string $id Webhook UUID.
     * @return bool True if deleted, false if not found or on persistence failure.
     */
    public function delete_webhook( string $id ): bool {
        $webhooks = $this->get_webhooks();
        $filtered = array_filter( $webhooks, function ( $wh ) use ( $id ) {
            return $wh['id'] !== $id;
        } );

        if ( count( $filtered ) === count( $webhooks ) ) {
            return false;
        }

        $persisted = $this->persist_webhooks( array_values( $filtered ) );
        if ( is_wp_error( $persisted ) ) {
            return false;
        }
        return true;
    }

    /**
     * Get all registered webhooks.
     *
     * Secrets are stored encrypted at rest (AES-256-GCM via Security\Crypto)
     * and decrypted here so callers receive plaintext. Legacy plaintext rows
     * pass through Crypto::decrypt() unchanged and are re-encrypted on the
     * next write.
     *
     * @return array Array of webhook configuration arrays.
     */
    public function get_webhooks(): array {
        // High-traffic stores hit get_webhooks() on every order/product
        // mutation. Caching the decrypted blob in the persistent object
        // cache keeps wp_options off the hot path entirely on Redis-backed
        // deployments. The cache is invalidated on every persist_webhooks().
        $cached = wp_cache_get( self::OPTION_KEY, 'tejcart' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $webhooks = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $webhooks ) ) {
            wp_cache_set( self::OPTION_KEY, array(), 'tejcart', HOUR_IN_SECONDS );
            return array();
        }

        foreach ( $webhooks as &$webhook ) {
            if ( isset( $webhook['secret'] ) && is_string( $webhook['secret'] ) ) {
                $webhook['secret'] = \TejCart\Security\Crypto::decrypt( $webhook['secret'] );
            }
        }
        unset( $webhook );

        wp_cache_set( self::OPTION_KEY, $webhooks, 'tejcart', HOUR_IN_SECONDS );

        return $webhooks;
    }

    /**
     * Persist a webhook list to options, encrypting each secret at rest.
     *
     * The webhook secret is the HMAC key subscribers verify against; if a
     * DB leak exposes it as plaintext an attacker can forge signed
     * deliveries indistinguishable from genuine ones. M-2 (see review):
     * use Crypto::encrypt_required() so a host without the openssl
     * extension fails closed instead of silently storing plaintext. The
     * caller (admin-side webhook form) sees a WP_Error and can surface
     * an admin notice; the listener on `tejcart_crypto_failure` covers
     * runtime failures past the openssl-loaded gate.
     *
     * @param array $webhooks Webhooks with plaintext secrets.
     * @return true|\WP_Error True on success, WP_Error when a secret
     *                         could not be safely encrypted.
     */
    private function persist_webhooks( array $webhooks ) {
        foreach ( $webhooks as &$webhook ) {
            if ( isset( $webhook['secret'] ) && is_string( $webhook['secret'] ) && '' !== $webhook['secret'] ) {
                if ( \TejCart\Security\Crypto::is_encrypted( $webhook['secret'] ) ) {
                    continue;
                }
                try {
                    $webhook['secret'] = \TejCart\Security\Crypto::encrypt_required( $webhook['secret'] );
                } catch ( \TejCart\Security\Crypto_Exception $e ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            'Outgoing_Webhooks: refusing to persist webhook secret as plaintext (' . $e->getMessage() . ').',
                            'error'
                        );
                    }
                    return new \WP_Error(
                        'tejcart_webhook_crypto_unavailable',
                        __( 'Could not securely persist the webhook secret on this host. Install the openssl PHP extension and retry.', 'tejcart' )
                    );
                }
            }
        }
        unset( $webhook );

        // Autoload=false: this blob can grow to multi-KB on stores with
        // several webhooks (each row carries an encrypted secret). Reads
        // are wrapped in wp_cache via get_webhooks() so the no-autoload
        // flag doesn't cost a hot-path DB hit.
        update_option( self::OPTION_KEY, $webhooks, false );
        wp_cache_delete( self::OPTION_KEY, 'tejcart' );

        return true;
    }

    /**
     * Get a single webhook by ID.
     *
     * @param string $id Webhook UUID.
     * @return array|null Webhook data or null if not found.
     */
    public function get_webhook( string $id ): ?array {
        $webhooks = $this->get_webhooks();
        foreach ( $webhooks as $webhook ) {
            if ( $webhook['id'] === $id ) {
                return $webhook;
            }
        }
        return null;
    }

    /**
     * Update a webhook's configuration.
     *
     * @param string $id   Webhook UUID.
     * @param array  $data Updated fields (url, events, secret, status).
     * @return bool True if updated, false if not found.
     */
    public function update_webhook( string $id, array $data ): bool {
        $webhooks = $this->get_webhooks();

        foreach ( $webhooks as &$webhook ) {
            if ( $webhook['id'] === $id ) {
                if ( isset( $data['url'] ) ) {
                    if ( ! $this->is_safe_webhook_url( $data['url'] ) ) {
                        return false;
                    }
                    $webhook['url'] = esc_url_raw( $data['url'] );
                }
                if ( isset( $data['events'] ) ) {
                    $webhook['events'] = array_values( array_intersect( $data['events'], self::get_events() ) );
                }
                if ( isset( $data['secret'] ) ) {
                    // Preserve the existing secret on a blank submit. A blank
                    // secret means the form was submitted without re-entering
                    // it (browser autofill stripped, copy-paste mishap); we
                    // never want to silently null the HMAC key. To rotate,
                    // the admin must explicitly enter a new value (I-2).
                    $secret = (string) $data['secret'];
                    if ( '' !== trim( $secret ) ) {
                        $webhook['secret'] = $secret;
                    }
                }
                if ( isset( $data['status'] ) ) {
                    $webhook['status'] = $data['status'];
                }
                $persisted = $this->persist_webhooks( $webhooks );
                if ( is_wp_error( $persisted ) ) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Deliver an event payload to all registered webhooks subscribed to this event.
     *
     * Enqueues a single fan-out job, which then iterates the matching
     * subscribers in-process. Doing this with one scheduled action instead of
     * one-per-subscriber keeps the Action Scheduler / cron table from
     * exploding at N=50+ subscribers (50 row inserts per order otherwise).
     *
     * @param string $event   Event name (e.g. order.created).
     * @param array  $payload Data to send in the webhook body.
     * @return void
     */
    public function deliver( string $event, array $payload ): void {
        $subscribed_ids = array();
        foreach ( $this->get_webhooks() as $webhook ) {
            if ( 'active' !== $webhook['status'] ) {
                continue;
            }
            if ( ! in_array( $event, $webhook['events'], true ) ) {
                continue;
            }
            $subscribed_ids[] = $webhook['id'];
        }

        if ( empty( $subscribed_ids ) ) {
            return;
        }

        Action_Scheduler::instance()->schedule_single(
            time(),
            'tejcart_webhook_fanout',
            array( $event, $payload, $subscribed_ids )
        );
    }

    /**
     * Process a fan-out job: deliver an event to each subscribed webhook in
     * sequence. Each delivery handles its own retry scheduling.
     *
     * @param string   $event           Event name.
     * @param array    $payload         Event payload.
     * @param string[] $subscribed_ids  Webhook IDs that subscribe to this event at fan-out time.
     * @return void
     */
    public function handle_fanout( string $event, array $payload, array $subscribed_ids ): void {
        // H-1: schedule one job per subscriber so AS can dispatch them
        // concurrently. Falls back to sequential in-process delivery when
        // AS isn't available (legacy behaviour).
        $async = \TejCart\Core\Action_Scheduler::is_action_scheduler_available();

        foreach ( $subscribed_ids as $webhook_id ) {
            $webhook = $this->get_webhook( (string) $webhook_id );
            if ( ! $webhook || 'active' !== $webhook['status'] ) {
                continue;
            }

            if ( ! in_array( $event, $webhook['events'], true ) ) {
                continue;
            }

            if ( $async ) {
                \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                    time(),
                    'tejcart_webhook_send',
                    array( (string) $webhook_id, $event, $payload, 1 )
                );
            } else {
                $this->send_webhook( $webhook, $event, $payload, 1 );
            }
        }
    }

    /**
     * H-1: per-subscriber scheduled-action handler.
     */
    public function handle_send( string $webhook_id, string $event, array $payload, int $attempt = 1 ): void {
        $webhook = $this->get_webhook( $webhook_id );
        if ( ! $webhook || 'active' !== $webhook['status'] ) {
            return;
        }
        if ( ! in_array( $event, $webhook['events'], true ) ) {
            return;
        }
        $this->send_webhook( $webhook, $event, $payload, max( 1, $attempt ) );
    }

    /**
     * Send a single webhook delivery.
     *
     * @param array  $webhook  Webhook configuration.
     * @param string $event    Event name.
     * @param array  $payload  Event data.
     * @param int    $attempt  Current attempt number (1-based).
     * @return bool True on success, false on failure.
     */
    private function send_webhook( array $webhook, string $event, array $payload, int $attempt = 1 ): bool {
        global $wpdb;

        $delivery_id = hash( 'sha256', $webhook['id'] . '|' . $event . '|' . wp_json_encode( $payload ) );

        $deliveries_table = $wpdb->prefix . 'tejcart_webhook_deliveries';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $already_ok = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$deliveries_table} WHERE delivery_id = %s AND success = 1",
                $delivery_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $already_ok > 0 ) {
            return true;
        }

        $body = wp_json_encode( array(
            'delivery_id' => $delivery_id,
            'event'       => $event,
            'timestamp'   => current_time( 'mysql' ),
            'attempt'     => $attempt,
            'data'        => $payload,
        ) );

        // v2 signature: HMAC over `t=<unix_seconds>.<body>` so the
        // delivery is bound to a specific instant in time. Subscribers
        // that enforce a tolerance window against the X-TejCart-Timestamp
        // header reject replays even after the secret leaks once.
        //
        // Legacy v1 signature (HMAC over the JSON body alone) is also
        // emitted in a separate header for backwards compatibility with
        // subscribers that haven't migrated their verifier yet — they
        // continue to function, just without the replay protection
        // they could have. New subscribers should prefer the v2 header.
        $timestamp = (string) time();
        $signed    = $timestamp . '.' . $body;
        $sig_v2    = 'v2,t=' . $timestamp . ',sha256=' . hash_hmac( 'sha256', $signed, $webhook['secret'] );
        $sig_v1    = hash_hmac( 'sha256', $body, $webhook['secret'] );

        // M-4: re-run the SSRF / private-IP guard at delivery time, not
        // just at registration. is_safe_webhook_url() resolves DNS fresh
        // each call, so an attacker who registers a public-IP host then
        // flips the A record to 169.254.169.254 (cloud metadata),
        // 100.100.100.200 (Alibaba), 127.0.0.0/8 (loopback), or any
        // RFC1918 range before the next delivery fires gets caught here.
        // Without this guard, the merchant's webhook secret + signature
        // would leak to the attacker-controlled internal target.
        //
        // Audit #58 / 04 M-6 — known residual: the gap between this
        // pre-flight resolve and the curl getaddrinfo at TCP-connect
        // time is the classic DNS-rebind window. Complete mitigation
        // requires pinning the resolved IP via `pre_http_request` +
        // CURLOPT_RESOLVE, which we deliberately don't ship in core
        // (it requires platform-specific TLS / SNI workarounds that
        // belong in a dedicated outbound-proxy module). Documented
        // and accepted; CLAUDE.md does not claim PCI-grade outbound
        // isolation for the webhook surface.
        $success         = false;
        $status_code     = null;
        $rebinding_block = false;

        if ( ! $this->is_safe_webhook_url( $webhook['url'] ) ) {
            $rebinding_block = true;
            $status_message  = 'webhook host now resolves to a private/metadata IP — delivery aborted';
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'Outgoing_Webhooks: aborted delivery %s to %s: host failed delivery-time SSRF re-check (DNS rebinding suspected).',
                        $delivery_id,
                        (string) $webhook['url']
                    ),
                    'error'
                );
            }
            /**
             * Fires when an outbound webhook delivery is aborted because the
             * destination host now resolves to a private/metadata IP — i.e.
             * a likely DNS-rebinding attempt against the registration-time
             * SSRF guard. Operators should alert and inspect.
             *
             * @param array  $webhook     Webhook configuration (with secret stripped).
             * @param string $event       Event name.
             * @param string $delivery_id Per-delivery idempotency hash.
             */
            do_action(
                'tejcart_webhook_dns_rebinding_blocked',
                array_diff_key( $webhook, array( 'secret' => true ) ),
                $event,
                $delivery_id
            );
        } else {
            $response = wp_remote_post( $webhook['url'], array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type'                       => 'application/json',
                    // v1 header (body-only HMAC) — kept for backwards
                    // compat with existing subscribers. Deprecated;
                    // prefer X-TejCart-Webhook-Signature-V2 going
                    // forward.
                    'X-TejCart-Webhook-Signature'        => $sig_v1,
                    // v2 header (timestamp-scoped HMAC). Subscribers
                    // should enforce a 5-minute tolerance against
                    // X-TejCart-Timestamp to defeat replays.
                    'X-TejCart-Webhook-Signature-V2'     => $sig_v2,
                    'X-TejCart-Timestamp'                => $timestamp,
                    'X-TejCart-Delivery-ID'              => $delivery_id,
                    'X-TejCart-Event'                    => $event,
                    'X-TejCart-Attempt'                  => (string) $attempt,
                ),
                'body'    => $body,
            ) );

            if ( is_wp_error( $response ) ) {
                $status_message = $response->get_error_message();
            } else {
                $status_code    = (int) wp_remote_retrieve_response_code( $response );
                $success        = ( $status_code >= 200 && $status_code < 300 );
                $status_message = "HTTP {$status_code}";
            }
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "INSERT INTO {$deliveries_table}
                    (delivery_id, webhook_id, event, status_code, attempt, success, error_message)
                 VALUES (%s, %s, %s, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    status_code = VALUES(status_code),
                    attempt     = VALUES(attempt),
                    success     = GREATEST(success, VALUES(success)),
                    error_message = VALUES(error_message)",
                $delivery_id,
                (string) $webhook['id'],
                $event,
                null === $status_code ? 0 : $status_code,
                $attempt,
                $success ? 1 : 0,
                $success ? '' : (string) $status_message
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        tejcart_log(
            sprintf(
                'Webhook delivery [%s] to %s (attempt %d): %s',
                $event,
                $webhook['url'],
                $attempt,
                $status_message
            ),
            $success ? 'info' : 'warning'
        );

        $max_retries = self::get_max_retries();
        // Don't retry a delivery that was aborted by the rebinding guard
        // — the attacker controls the DNS, so re-attempting just burns
        // our retry budget against a host we know is malicious until the
        // operator removes the webhook record.
        if ( ! $success && ! $rebinding_block && $attempt < $max_retries ) {
            $schedule = self::get_retry_backoff();

            $idx       = min( $attempt - 1, count( $schedule ) - 1 );
            $delay     = (int) $schedule[ $idx ];
            $scheduler = Action_Scheduler::instance();
            $scheduler->schedule_single(
                time() + $delay,
                'tejcart_webhook_retry',
                array( $webhook['id'], $event, $payload, $attempt + 1 )
            );
        }

        return $success;
    }

    /**
     * Handle a scheduled webhook retry.
     *
     * @param string $webhook_id Webhook UUID.
     * @param string $event      Event name.
     * @param array  $payload    Event data.
     * @param int    $attempt    Current attempt number.
     * @return void
     */
    public function handle_retry( string $webhook_id, string $event, array $payload, int $attempt ): void {
        $webhook = $this->get_webhook( $webhook_id );

        if ( ! $webhook || 'active' !== $webhook['status'] ) {
            return;
        }

        $this->send_webhook( $webhook, $event, $payload, $attempt );
    }

    /**
     * Verify a webhook signature.
     *
     * Utility method for documentation and external verification.
     * Recognises both signature formats:
     *
     *   - v2: `X-TejCart-Webhook-Signature-V2: v2,t=<unix>,sha256=<hex>`
     *         HMAC computed over `<timestamp>.<body>`. The caller MUST
     *         pass `$timestamp` (the X-TejCart-Timestamp header value)
     *         and a non-zero `$tolerance_seconds` so replays are
     *         rejected. The default tolerance is 5 minutes, matching
     *         industry norms (Stripe, GitHub, Shopify).
     *
     *   - v1: `X-TejCart-Webhook-Signature: <hex>` — plain HMAC over
     *         the JSON body. No replay protection. Deprecated; kept
     *         for backwards compatibility with subscribers written
     *         against the original header.
     *
     * @param string $payload           Raw JSON payload body.
     * @param string $signature         The signature header value.
     * @param string $secret            The webhook secret key.
     * @param string $timestamp         The X-TejCart-Timestamp header value
     *                                   (Unix seconds). Required for v2.
     * @param int    $tolerance_seconds Reject v2 signatures whose timestamp
     *                                   is more than this many seconds away
     *                                   from `time()`. 0 disables (not
     *                                   recommended). Default 300.
     * @return bool True if signature is valid.
     */
    public function verify_signature(
        string $payload,
        string $signature,
        string $secret,
        string $timestamp = '',
        int $tolerance_seconds = 300
    ): bool {
        // v2 header format: `v2,t=<unix>,sha256=<hex>`.
        if ( 0 === strncmp( $signature, 'v2,', 3 ) ) {
            $parts = array();
            foreach ( explode( ',', substr( $signature, 3 ) ) as $segment ) {
                $pair = explode( '=', $segment, 2 );
                if ( 2 === count( $pair ) ) {
                    $parts[ trim( $pair[0] ) ] = trim( $pair[1] );
                }
            }
            $sig_t   = (string) ( $parts['t'] ?? '' );
            $sig_hex = (string) ( $parts['sha256'] ?? '' );
            if ( '' === $sig_t || '' === $sig_hex ) {
                return false;
            }
            // Prefer the timestamp encoded inside the signature header
            // when available; fall back to the caller-supplied
            // X-TejCart-Timestamp header. Both should be present and
            // agree; if they don't, fail.
            if ( '' !== $timestamp && $timestamp !== $sig_t ) {
                return false;
            }
            $ts_int = (int) $sig_t;
            if ( $tolerance_seconds > 0 && abs( time() - $ts_int ) > $tolerance_seconds ) {
                return false;
            }
            $expected = hash_hmac( 'sha256', $sig_t . '.' . $payload, $secret );
            return hash_equals( $expected, $sig_hex );
        }

        // v1 legacy format. No replay protection — accepted only for
        // backwards compatibility with subscribers that have not
        // migrated to the v2 verifier.
        $expected = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, $signature );
    }

    /**
     * Handle order created event.
     *
     * @param int $order_id Order ID.
     */
    public function on_order_created( $order_id ): void {
        $this->deliver( 'order.created', array( 'order_id' => $order_id ) );
    }

    /**
     * Handle order status changed event.
     *
     * @param int    $order_id   Order ID.
     * @param string $old_status Previous status.
     * @param string $new_status New status.
     */
    public function on_order_updated( $order_id, $old_status, $new_status ): void {
        $this->deliver( 'order.updated', array(
            'order_id'   => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ) );

        if ( 'completed' === $new_status ) {
            $this->deliver( 'order.completed', array( 'order_id' => $order_id ) );
        }

        if ( 'refunded' === $new_status ) {
            $this->deliver( 'order.refunded', array( 'order_id' => $order_id ) );
        }
    }

    /**
     * Handle partial-refund event (N-H5).
     *
     * Fired off `tejcart_partial_refund_created` (introduced for F-H4).
     * The payload mirrors the refund row so receivers don't have to
     * re-fetch the order to reconcile partial refunds.
     *
     * @param int   $order_id Order ID.
     * @param array $refund   Refund row (id, amount, reason, created_at, …).
     * @param mixed $order    Order object — unused (kept for signature parity).
     */
    public function on_partial_refund_created( $order_id, $refund = array(), $order = null ): void {
        $payload = array(
            'order_id' => (int) $order_id,
            'refund'   => is_array( $refund ) ? $refund : array(),
        );
        $this->deliver( 'order.partially_refunded', $payload );
    }

    /**
     * Handle product created event.
     *
     * @param int $product_id Product ID.
     */
    public function on_product_created( $product_id ): void {
        $this->deliver( 'product.created', array( 'product_id' => $product_id ) );
    }

    /**
     * Handle product updated event.
     *
     * @param int $product_id Product ID.
     */
    public function on_product_updated( $product_id ): void {
        $this->deliver( 'product.updated', array( 'product_id' => $product_id ) );
    }

    /**
     * Handle product deleted event.
     *
     * @param int $product_id Product ID.
     */
    public function on_product_deleted( $product_id ): void {
        $this->deliver( 'product.deleted', array( 'product_id' => $product_id ) );
    }

    /**
     * Handle a product stock-quantity change.
     *
     * @param int $product_id Product ID.
     * @param int $new_stock  New stock_quantity.
     * @param int $delta      Units deducted (positive integer).
     */
    public function on_product_stock_changed( $product_id, $new_stock = null, $delta = null ): void {
        $this->deliver( 'product.stock_changed', array(
            'product_id'    => (int) $product_id,
            'stock'         => null === $new_stock ? null : (int) $new_stock,
            'delta'         => null === $delta ? null : (int) $delta,
        ) );
    }

    /**
     * Handle customer created event.
     *
     * @param int $customer_id Customer ID.
     */
    public function on_customer_created( $customer_id ): void {
        $this->deliver( 'customer.created', array( 'customer_id' => $customer_id ) );
    }

    /**
     * Handle admin form actions (add, edit, delete, test).
     *
     * @return void
     */
    public function handle_admin_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $is_webhooks_page = ( 'tejcart-webhooks' === $page )
            || ( 'tejcart-settings' === $page && 'advanced' === $tab && 'webhooks' === $section );
        if ( ! $is_webhooks_page ) {
            return;
        }

        // Defence-in-depth capability gate. The admin page is already
        // capability-gated upstream, but `admin_init` fires for every
        // logged-in user — relying on the page registration alone is
        // fragile if a future commit exposes the form on a different
        // admin surface or a sibling plugin hooks the page rendering.
        // Webhooks carry HMAC secrets and SSRF risk; a customer-role
        // user must never reach this handler. See review re-audit
        // finding R-2.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['tejcart_add_webhook'] ) ) {
            check_admin_referer( 'tejcart_add_webhook' );

            $url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
            $events = isset( $_POST['webhook_events'] ) && is_array( $_POST['webhook_events'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['webhook_events'] ) )
                : array();

            if ( $url && ! empty( $events ) ) {
                $result = $this->register_webhook( $url, $events, $secret );
                if ( is_wp_error( $result ) ) {
                    add_settings_error( 'tejcart_webhooks', 'webhook_error', $result->get_error_message(), 'error' );
                } else {
                    add_settings_error( 'tejcart_webhooks', 'webhook_added', __( 'Webhook added successfully.', 'tejcart' ), 'success' );
                }
            } else {
                add_settings_error( 'tejcart_webhooks', 'webhook_error', __( 'Please provide a URL and select at least one event.', 'tejcart' ), 'error' );
            }
        }

        if ( isset( $_POST['tejcart_edit_webhook'] ) ) {
            check_admin_referer( 'tejcart_edit_webhook' );

            $id     = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
            $url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
            $status = isset( $_POST['webhook_status'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_status'] ) ) : 'active';
            $events = isset( $_POST['webhook_events'] ) && is_array( $_POST['webhook_events'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['webhook_events'] ) )
                : array();

            if ( $id ) {
                $updated = $this->update_webhook( $id, array(
                    'url'    => $url,
                    'secret' => $secret,
                    'status' => $status,
                    'events' => $events,
                ) );
                if ( $updated ) {
                    add_settings_error( 'tejcart_webhooks', 'webhook_updated', __( 'Webhook updated successfully.', 'tejcart' ), 'success' );
                } else {
                    add_settings_error(
                        'tejcart_webhooks',
                        'webhook_update_failed',
                        __( 'Could not update the webhook. The URL may have been rejected by the SSRF guard, or the secret could not be securely persisted on this host.', 'tejcart' ),
                        'error'
                    );
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'delete' === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $id = isset( $_GET['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_GET['webhook_id'] ) ) : '';
            if ( $id && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_delete_webhook_' . $id ) ) {
                $this->delete_webhook( $id );
                add_settings_error( 'tejcart_webhooks', 'webhook_deleted', __( 'Webhook deleted.', 'tejcart' ), 'success' );
            }
        }
    }

    /**
     * AJAX handler to test a webhook by sending a test ping.
     *
     * @return void
     */
    public function ajax_test_webhook(): void {
        check_ajax_referer( 'tejcart_test_webhook', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tejcart' ) ) );
        }

        $webhook_id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
        $webhook    = $this->get_webhook( $webhook_id );

        if ( ! $webhook ) {
            wp_send_json_error( array( 'message' => __( 'Webhook not found.', 'tejcart' ) ) );
        }

        // Re-validate the URL right before delivery to close the DNS-rebinding
        // window between registration and the manual "Test" press. send_webhook()
        // already does this on every scheduled delivery; mirror it here (L-2).
        if ( ! $this->is_safe_webhook_url( $webhook['url'] ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Webhook host now resolves to a private or metadata IP and was refused.', 'tejcart' ),
                ),
                400
            );
        }

        $body = wp_json_encode( array(
            'event'     => 'test.ping',
            'timestamp' => current_time( 'mysql' ),
            'data'      => array( 'message' => 'This is a test webhook delivery from TejCart.' ),
        ) );

        // v1 signature: plain HMAC over body (legacy compatibility).
        $sig_v1 = hash_hmac( 'sha256', $body, $webhook['secret'] );

        // F-SEC-006: mirror send_webhook()'s header set so subscribers that
        // have migrated to v2 verification accept test pings as they do live
        // deliveries.  v2 signature: HMAC over "<unix>.<body>", matching the
        // format verified by verify_webhook_signature().
        $timestamp = (string) time();
        $signed    = $timestamp . '.' . $body;
        $sig_v2    = 'v2,t=' . $timestamp . ',sha256=' . hash_hmac( 'sha256', $signed, $webhook['secret'] );

        $response = wp_remote_post( $webhook['url'], array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'                       => 'application/json',
                'X-TejCart-Webhook-Signature'        => $sig_v1,
                'X-TejCart-Webhook-Signature-V2'     => $sig_v2,
                'X-TejCart-Timestamp'                => $timestamp,
                'X-TejCart-Event'                    => 'test.ping',
            ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        wp_send_json_success( array(
            'message'     => sprintf(
                /* translators: %d: HTTP status code */
                __( 'Test delivered. Response code: %d', 'tejcart' ),
                $status_code
            ),
            'status_code' => $status_code,
        ) );
    }

    /**
     * Render the Delivery log tab: paginated list of the 100 most recent
     * webhook delivery attempts with status, HTTP code, attempt number, and
     * any error message returned by the remote endpoint.
     */
    private function render_deliveries_tab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_webhook_deliveries';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( "SELECT id, webhook_id, event, status_code, attempt, success, error_message, created_at FROM {$table} ORDER BY id DESC LIMIT 100" );

        $webhooks = array();
        foreach ( $this->get_webhooks() as $wh ) {
            $webhooks[ $wh['id'] ] = $wh['url'];
        }

        echo '<h2>' . esc_html__( 'Recent deliveries', 'tejcart' ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No webhook deliveries recorded yet.', 'tejcart' ) . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'When', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Webhook', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Event', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'HTTP', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Attempt', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Result', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Error', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $url = $webhooks[ $r->webhook_id ] ?? $r->webhook_id;
            echo '<tr>';
            echo '<td>' . esc_html( $r->created_at ) . '</td>';
            echo '<td><code>' . esc_html( $url ) . '</code></td>';
            echo '<td>' . esc_html( $r->event ) . '</td>';
            echo '<td>' . ( null !== $r->status_code ? (int) $r->status_code : '—' ) . '</td>';
            echo '<td>' . (int) $r->attempt . '</td>';
            $result = $r->success ? __( 'Success', 'tejcart' ) : __( 'Failed', 'tejcart' );
            $class  = $r->success ? 'notice-success' : 'notice-error';
            echo '<td><span class="' . esc_attr( $class ) . '" style="padding:2px 6px;border-radius:3px;">' . esc_html( $result ) . '</span></td>';
            echo '<td>' . esc_html( (string) ( $r->error_message ?? '' ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the Webhooks admin page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and `<h1>` for composition inside another admin
     *                      screen (Settings → Advanced → Webhooks).
     */
    private function enqueue_admin_assets(): void {
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        wp_enqueue_script(
            'tejcart-admin-webhooks',
            tejcart_asset_url( 'assets/js/admin/webhooks.js' ),
            array(),
            $version,
            true
        );
        wp_localize_script(
            'tejcart-admin-webhooks',
            'tejcartWebhooksSettings',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => array(
                    'sending' => __( 'Sending...', 'tejcart' ),
                    'failed'  => __( 'Failed', 'tejcart' ),
                    'error'   => __( 'Error', 'tejcart' ),
                    'test'    => __( 'Test', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Render the admin webhooks management page.
     *
     * @return void
     */
    public function render_admin_page( bool $embedded = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->enqueue_admin_assets();

        $webhooks = $this->get_webhooks();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $webhook_id = isset( $_GET['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_GET['webhook_id'] ) ) : '';
        $editing    = null;

        if ( 'edit' === $action && $webhook_id ) {
            $editing = $this->get_webhook( $webhook_id );
        }

        settings_errors( 'tejcart_webhooks' );
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <h1><?php esc_html_e( 'Webhooks', 'tejcart' ); ?></h1>
        <?php endif; ?>

            <?php
            $tabs = array(
                'list'       => __( 'Webhooks', 'tejcart' ),
                'deliveries' => __( 'Delivery log', 'tejcart' ),
            );
            $current_tab = ( 'deliveries' === $action ) ? 'deliveries' : 'list';

            echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
            foreach ( $tabs as $slug => $label ) {
                $tab_url = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=webhooks' );
                if ( 'deliveries' === $slug ) {
                    $tab_url = add_query_arg( 'action', 'deliveries', $tab_url );
                }
                $active = $current_tab === $slug ? ' nav-tab-active' : '';
                echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $tab_url ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
            ?>

            <?php if ( 'deliveries' === $current_tab ) : $this->render_deliveries_tab(); ?>
            <?php elseif ( 'add' === $action || $editing ) : ?>

                <h2><?php echo $editing ? esc_html__( 'Edit Webhook', 'tejcart' ) : esc_html__( 'Add Webhook', 'tejcart' ); ?></h2>

                <form method="post">
                    <?php if ( $editing ) : ?>
                        <?php wp_nonce_field( 'tejcart_edit_webhook' ); ?>
                        <input type="hidden" name="webhook_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
                    <?php else : ?>
                        <?php wp_nonce_field( 'tejcart_add_webhook' ); ?>
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="webhook_url"><?php esc_html_e( 'Delivery URL', 'tejcart' ); ?></label></th>
                            <td>
                                <input type="url" name="webhook_url" id="webhook_url" class="regular-text"
                                       value="<?php echo $editing ? esc_attr( $editing['url'] ) : ''; ?>" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="webhook_secret"><?php esc_html_e( 'Secret', 'tejcart' ); ?></label></th>
                            <td>
                                <?php if ( $editing ) : ?>
                                <input type="password" name="webhook_secret" id="webhook_secret" class="regular-text"
                                       value="" autocomplete="new-password"
                                       placeholder="<?php esc_attr_e( '(unchanged — enter a new value to rotate)', 'tejcart' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Leave blank to keep the current secret. Enter a new value to rotate it.', 'tejcart' ); ?></p>
                                <?php else : ?>
                                <input type="password" name="webhook_secret" id="webhook_secret" class="regular-text"
                                       value="" autocomplete="new-password" />
                                <p class="description"><?php esc_html_e( 'Leave blank to auto-generate.', 'tejcart' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ( $editing ) : ?>
                        <tr>
                            <th scope="row"><label for="webhook_status"><?php esc_html_e( 'Status', 'tejcart' ); ?></label></th>
                            <td>
                                <select name="webhook_status" id="webhook_status">
                                    <option value="active" <?php selected( $editing['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'tejcart' ); ?></option>
                                    <option value="paused" <?php selected( $editing['status'], 'paused' ); ?>><?php esc_html_e( 'Paused', 'tejcart' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Events', 'tejcart' ); ?></th>
                            <td>
                                <?php foreach ( self::get_events() as $event ) : ?>
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $event ); ?>"
                                            <?php echo $editing && in_array( $event, $editing['events'], true ) ? 'checked' : ''; ?> />
                                        <?php echo esc_html( $event ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>

                    <?php if ( $editing ) : ?>
                        <input type="submit" name="tejcart_edit_webhook" class="button button-primary" value="<?php esc_attr_e( 'Update Webhook', 'tejcart' ); ?>" />
                    <?php else : ?>
                        <input type="submit" name="tejcart_add_webhook" class="button button-primary" value="<?php esc_attr_e( 'Add Webhook', 'tejcart' ); ?>" />
                    <?php endif; ?>

                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=webhooks' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'tejcart' ); ?></a>
                </form>

            <?php else : ?>

                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=webhooks&action=add' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Add Webhook', 'tejcart' ); ?>
                    </a>
                </p>

                <?php if ( empty( $webhooks ) ) : ?>
                    <p><?php esc_html_e( 'No webhooks configured yet.', 'tejcart' ); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'URL', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Events', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Created', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $webhooks as $wh ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $wh['url'] ); ?></td>
                                    <td><?php echo esc_html( implode( ', ', $wh['events'] ) ); ?></td>
                                    <td>
                                        <span class="tejcart-webhook-status tejcart-webhook-status--<?php echo esc_attr( $wh['status'] ); ?>">
                                            <?php echo esc_html( ucfirst( $wh['status'] ) ); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html( $wh['created_at'] ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=webhooks&action=edit&webhook_id=' . $wh['id'] ) ); ?>">
                                            <?php esc_html_e( 'Edit', 'tejcart' ); ?>
                                        </a>
                                        |
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=webhooks&action=delete&webhook_id=' . $wh['id'] ), 'tejcart_delete_webhook_' . $wh['id'] ) ); ?>"
                                           onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this webhook?', 'tejcart' ); ?>');">
                                            <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                                        </a>
                                        |
                                        <a href="#" class="tejcart-test-webhook" data-webhook-id="<?php echo esc_attr( $wh['id'] ); ?>"
                                           data-nonce="<?php echo esc_attr( wp_create_nonce( 'tejcart_test_webhook' ) ); ?>">
                                            <?php esc_html_e( 'Test', 'tejcart' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endif; ?>

            <?php endif; ?>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
