<?php
/**
 * PayPal REST API Client
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles all communication with the PayPal REST API v2.
 */
class PayPal_API {
    /**
     * PayPal live API base URL.
     */
    private const LIVE_URL = 'https://api-m.paypal.com';

    /**
     * PayPal sandbox API base URL.
     */
    private const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    /**
     * Reference to the parent gateway.
     *
     * @var PayPal_Gateway
     */
    private PayPal_Gateway $gateway;

    /**
     * In-request memo of the cost-per-method payload that
     * {@see build_shipping_options_for_order()} produces, keyed by the
     * tuple of inputs that affect those costs (destination + currency +
     * cart fingerprint).
     *
     * High-volume merchants running carrier-rated shipping (the
     * `tejcart-shipping` sibling — FedEx / UPS / USPS / DHL …) pay a
     * 300 ms–3 s round-trip to the carrier inside `$method->calculate()`
     * for every entry the manager returns. PayPal's wallet sheet calls
     * this method up to four times per `onShippingAddressChange` callback
     * (once via `apply_chosen_shipping_method()`, again to render the
     * options list, again on auto-select, and a fourth time to re-render
     * post-selection). Those repeated calls share the same cart and
     * address, so caching the cost array within a single PHP request
     * collapses N+1 carrier hits down to one without changing externally
     * observable behaviour — the `selected` flag is still recomputed on
     * every call from the order's currently-chosen method.
     *
     * Entries are cost-only; the per-call `selected` flag is layered on
     * top at output time.
     *
     * @var array<string, list<array{id:string,label:string,type:string,amount:array{currency_code:string,value:string}}>>
     */
    private array $shipping_options_cache = array();

    /**
     * Hard cap on memoised cache entries so a long-lived CLI / queue
     * worker that processes many distinct (cart, address) pairs cannot
     * grow this map without bound. Far above the realistic per-request
     * working set (1–4 callbacks per checkout) so a normal AJAX flow
     * never trips eviction.
     */
    private const SHIPPING_OPTIONS_CACHE_MAX = 64;

    /**
     * Constructor.
     *
     * @param PayPal_Gateway $gateway Gateway instance.
     */
    public function __construct( PayPal_Gateway $gateway ) {
        $this->gateway = $gateway;

        // Register the connection-reuse filter on construction so the
        // first outbound API call of the request already benefits from
        // share + keep-alive. Idempotent: safe to call from every
        // instantiation; the manager registers the filter once per
        // request and short-circuits subsequent invocations.
        Curl_Share_Manager::bootstrap();
    }

    /**
     * Circuit-breaker option name. Stored as { 'failures' => int, 'opened_at' => int }.
     *
     * M-1: kept as the default/fallback name for legacy callers. New code
     * passes a per-endpoint family (`token` | `orders` | `payments` |
     * `webhooks` | `disputes`) to `circuit_*` methods so a transient
     * `verify-webhook-signature` outage no longer trips the breaker for
     * `create-order` / `capture` traffic.
     */
    private const CIRCUIT_OPTION = 'tejcart_paypal_circuit';

    /**
     * Build the per-family circuit-breaker option name. Empty / 'default'
     * preserves the legacy behaviour for back-compat tests.
     */
    private function circuit_option_name( string $family = '' ): string {
        if ( '' === $family || 'default' === $family ) {
            return self::CIRCUIT_OPTION;
        }
        return self::CIRCUIT_OPTION . '_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $family ) );
    }

    /**
     * Number of consecutive failures before the breaker trips open.
     */
    private const CIRCUIT_THRESHOLD = 5;

    /**
     * Cool-off window once tripped, in seconds. After this many seconds we
     * allow one trial request through (half-open state) — if it succeeds the
     * breaker resets, if it fails the cool-off restarts.
     */
    private const CIRCUIT_COOLOFF = 60;

    /**
     * Build the credential-scoped portion of token cache keys so a rotated
     * client_secret invalidates the cached access token immediately rather
     * than serving the stale token until natural expiry.
     *
     * @param string $client_id     PayPal client ID.
     * @param string $client_secret PayPal client secret.
     * @return string Truncated SHA256 hash safe for inclusion in option/transient keys.
     */
    private function credential_fingerprint( string $client_id, string $client_secret ): string {
        return substr( hash( 'sha256', $client_id . '|' . $client_secret ), 0, 16 );
    }

    /**
     * Get the access-token transient key, scoped to (env, credentials).
     *
     * @param string $client_id     PayPal client ID.
     * @param string $client_secret PayPal client secret.
     * @return string
     */
    private function access_token_transient_key( string $client_id, string $client_secret ): string {
        $env = $this->gateway->is_sandbox() ? 'sandbox' : 'live';
        return 'tejcart_paypal_access_token_' . $env . '_' . $this->credential_fingerprint( $client_id, $client_secret );
    }

    /**
     * Obtain an OAuth 2.0 access token via client credentials grant.
     * The token is cached in a WordPress transient for its lifetime.
     *
     * @return string|\WP_Error Access token string on success, WP_Error on failure.
     */
    public function get_access_token() {
        $client_id     = $this->gateway->get_client_id();
        $client_secret = $this->gateway->get_client_secret();

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new \WP_Error( 'tejcart_paypal_missing_credentials', __( 'PayPal API credentials are not configured.', 'tejcart' ) );
        }

        $transient_key = $this->access_token_transient_key( $client_id, $client_secret );
        $cached_token  = get_transient( $transient_key );

        if ( $cached_token ) {
            return $cached_token;
        }

        $short_circuit = $this->circuit_short_circuit();
        if ( null !== $short_circuit ) {
            return $short_circuit;
        }

        // S-5: single-flight lock so a token-cache miss doesn't fan a
        // thundering herd of OAuth POSTs at PayPal. Only the lock holder
        // fetches; the rest poll the transient briefly and return the
        // freshly-warmed token.
        // Audit F-005: the single-flight guarantee relies on the
        // DB-backed Lock primitive, which is shared across the whole
        // farm (no persistent object cache required). The only window
        // where it degrades to "every worker may refetch" is when the
        // Lock class is unavailable — i.e. the brief upgrade window
        // before the tejcart_locks table is provisioned. After
        // activation runs create_tables() this is always true, so the
        // residual risk is bounded to first-boot and self-heals.
        $lock_key = 'pp_token_' . $this->credential_fingerprint( $client_id, $client_secret );
        $can_lock = class_exists( \TejCart\Core\Lock::class );
        $acquired = $can_lock ? \TejCart\Core\Lock::claim( $lock_key, 30, 'paypal_token' ) : true;

        if ( ! $acquired ) {
            for ( $i = 0; $i < 15; $i++ ) {
                usleep( 100 * 1000 );
                $cached_token = get_transient( $transient_key );
                if ( $cached_token ) {
                    return $cached_token;
                }
            }
            return new \WP_Error(
                'tejcart_paypal_token_busy',
                __( 'PayPal token refresh in progress. Please retry in a moment.', 'tejcart' ),
                array( 'status' => 503 )
            );
        }

        try {
            $result = $this->request_access_token_with_backoff( $client_id, $client_secret );

            if ( is_wp_error( $result ) ) {
                $this->circuit_record_failure();
                return $result;
            }

            $this->circuit_record_success();
            return $result['token'];
        } finally {
            if ( $can_lock ) {
                \TejCart\Core\Lock::release( $lock_key );
            }
        }
    }

    /**
     * Issue the access-token request with jittered exponential backoff.
     *
     * S-5: previous code retried once immediately. Under transient PayPal
     * outages every PHP-FPM worker hammered the OAuth endpoint twice in a
     * row with no jitter, tripping per-app rate limits and cascading the
     * circuit breaker open for all checkout traffic.
     *
     * @param string $client_id
     * @param string $client_secret
     * @return array|\WP_Error
     */
    private function request_access_token_with_backoff( string $client_id, string $client_secret ) {
        $delays_ms = array( 0, 100, 400 );
        $last      = null;
        $count     = count( $delays_ms );
        foreach ( $delays_ms as $i => $delay ) {
            if ( $delay > 0 ) {
                // Tests run with TEJCART_DISABLE_BACKOFF_SLEEP defined to skip the wall-clock cost.
                if ( ! defined( 'TEJCART_DISABLE_BACKOFF_SLEEP' ) ) {
                    $jitter = random_int( 0, $delay );
                    usleep( ( $delay + $jitter ) * 1000 );
                }
            }
            $result = $this->request_access_token( $client_id, $client_secret );
            if ( ! is_wp_error( $result ) ) {
                return $result;
            }
            $last = $result;
            tejcart_log( sprintf(
                'PayPal token request failed (attempt %d/%d): %s%s%s',
                $i + 1,
                $count,
                $result->get_error_message(),
                self::format_response_diagnostics( $result ),
                ( $i + 1 < $count ) ? '. Retrying with jittered backoff...' : '.'
            ) );
        }
        return $last;
    }

    /**
     * Inspect the circuit-breaker state and return a WP_Error short-circuit
     * when the breaker is open and still inside the cool-off window. Returns
     * null when the request is allowed to proceed.
     *
     * @return \WP_Error|null
     */
    private function circuit_short_circuit( string $family = 'token' ) {
        $state = get_option( $this->circuit_option_name( $family ), array() );
        if ( ! is_array( $state ) ) {
            return null;
        }

        $failures  = (int) ( $state['failures'] ?? 0 );
        $opened_at = (int) ( $state['opened_at'] ?? 0 );

        if ( $failures < self::CIRCUIT_THRESHOLD || $opened_at <= 0 ) {
            return null;
        }

        if ( ( time() - $opened_at ) < self::CIRCUIT_COOLOFF ) {
            return new \WP_Error(
                'tejcart_paypal_circuit_open',
                __( 'PayPal API circuit breaker is open after repeated failures. Try again in a moment.', 'tejcart' ),
                array( 'status' => 503 )
            );
        }

        return null;
    }

    /**
     * Reset the circuit breaker after a successful call.
     *
     * @return void
     */
    private function circuit_record_success( string $family = 'token' ): void {
        $opt   = $this->circuit_option_name( $family );
        $state = get_option( $opt, array() );
        if ( is_array( $state ) && ! empty( $state ) ) {
            delete_option( $opt );
        }
        // M-1: keep the legacy 'default' option in sync for back-compat
        // (tests + older filters subscribed to tejcart_paypal_circuit).
        if ( 'token' === $family ) {
            $legacy = get_option( self::CIRCUIT_OPTION, array() );
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                delete_option( self::CIRCUIT_OPTION );
            }
        }
    }

    /**
     * Bump the failure counter and trip the breaker once the threshold is met.
     *
     * @return void
     */
    private function circuit_record_failure( string $family = 'token' ): void {
        $opt      = $this->circuit_option_name( $family );
        $state    = get_option( $opt, array() );
        $failures = is_array( $state ) ? (int) ( $state['failures'] ?? 0 ) : 0;
        $failures++;

        $opened_at = $failures >= self::CIRCUIT_THRESHOLD ? time() : 0;

        update_option(
            $opt,
            array(
                'failures'  => $failures,
                'opened_at' => $opened_at,
            ),
            false
        );

        // Mirror to the legacy default option so existing operator alerts
        // keyed on tejcart_paypal_circuit still fire.
        if ( 'token' === $family ) {
            update_option(
                self::CIRCUIT_OPTION,
                array(
                    'failures'  => $failures,
                    'opened_at' => $opened_at,
                ),
                false
            );
        }
    }

    /**
     * Perform the actual access token HTTP request.
     *
     * @param string $client_id     PayPal client ID.
     * @param string $client_secret PayPal client secret.
     * @return array|\WP_Error Array with 'token' key on success, WP_Error on failure.
     */
    private function request_access_token( string $client_id, string $client_secret ) {
        $transient_key = $this->access_token_transient_key( $client_id, $client_secret );

        $api_url = $this->get_api_url();
        if ( 0 !== strpos( $api_url, 'https://' ) ) {
            return new \WP_Error(
                'tejcart_paypal_token_insecure_url',
                __( 'Refusing to send PayPal credentials over a non-HTTPS connection.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        $response = wp_remote_post(
            $api_url . '/v1/oauth2/token',
            array(
                'headers' => array(
                    'Authorization'                 => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for PayPal HTTP Basic Auth.
                    'Content-Type'                  => 'application/x-www-form-urlencoded',
                    'PayPal-Partner-Attribution-Id' => PayPal_Gateway::bn_code(),
                ),
                'body'    => 'grant_type=client_credentials',
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = self::decode_json_response( $response );
        $body   = $parsed['parse_ok'] ? $parsed['decoded'] : array();

        if ( $parsed['status'] >= 400 ) {
            $message = isset( $body['error_description'] ) ? $body['error_description'] : __( 'PayPal token request returned an error.', 'tejcart' );
            return new \WP_Error( 'tejcart_paypal_token_error', $message, array( 'status' => $parsed['status'] ) );
        }

        if ( ! $parsed['parse_ok'] ) {
            return new \WP_Error(
                'tejcart_paypal_token_parse_error',
                __( 'Response could not be parsed.', 'tejcart' ),
                array(
                    'status'       => $parsed['status'],
                    'content_type' => $parsed['content_type'],
                    'body_length'  => $parsed['body_length'],
                    'body_excerpt' => $parsed['body_excerpt'],
                )
            );
        }

        if ( empty( $body['access_token'] ) ) {
            return new \WP_Error(
                'tejcart_paypal_token_error',
                __( 'Unable to retrieve PayPal access token.', 'tejcart' )
            );
        }

        $expires_in = 3600;
        if ( isset( $body['expires_in'] ) && is_numeric( $body['expires_in'] ) && (int) $body['expires_in'] > 0 ) {
            $expires_in = (int) $body['expires_in'] - 60;
        }

        set_transient( $transient_key, $body['access_token'], max( $expires_in, 60 ) );

        return array( 'token' => $body['access_token'] );
    }

    /**
     * Get a browser-safe client token for SDK v6 (required for Fastlane and card fields).
     *
     * @return string Client token or empty string on failure.
     */
    public function get_client_token(): string {
        $transient_key = 'tejcart_paypal_client_token_' . ( $this->gateway->is_sandbox() ? 'sandbox' : 'live' );
        $cached = get_transient( $transient_key );
        if ( $cached ) {
            return $cached;
        }

        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return '';
        }

        $response = wp_remote_post(
            $this->get_api_url() . '/v1/identity/generate-token',
            array(
                'headers' => array(
                    'Authorization'                 => 'Bearer ' . $access_token,
                    'Content-Type'                  => 'application/json',
                    'Accept'                        => 'application/json',
                    'PayPal-Partner-Attribution-Id' => PayPal_Gateway::bn_code(),
                ),
                'body'    => '{}',
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            tejcart_log( 'PayPal client token error: ' . $response->get_error_message(), 'error' );
            return '';
        }

        $parsed = self::decode_json_response( $response );
        if ( ! $parsed['parse_ok'] ) {
            tejcart_log(
                'PayPal client token: non-JSON response'
                    . self::format_response_diagnostics( $parsed ),
                'error'
            );
            return '';
        }
        $body  = $parsed['decoded'];
        $token = isset( $body['client_token'] ) ? (string) $body['client_token'] : '';

        if ( $token ) {
            set_transient( $transient_key, $token, 50 * MINUTE_IN_SECONDS );
        }

        return $token;
    }

    /**
     * Create a PayPal order via REST API v2.
     *
     * @param object $order           TejCart order object.
     * @param string $vault_token_id  Optional PayPal vault token ID to charge.
     * @param bool   $save_method     When true, asks PayPal to vault the
     *                                payment instrument on a successful capture.
     *                                Ignored when $vault_token_id is supplied
     *                                (the buyer is already paying with a saved
     *                                method, so there's nothing new to vault).
     * @param array  $options         Optional flow hints:
     *                                - `funding_source` (string): `card`, `paypal`, `venmo`.
     *                                  Drives payment_source.<source> authentication options.
     *                                - `three_d_secure` (string): `SCA_ALWAYS`, `SCA_WHEN_REQUIRED`
     *                                  or `NONE`. Applied only when funding_source is `card`.
     * @return array|\WP_Error PayPal order data on success, WP_Error on failure.
     */
    public function create_order( $order, string $vault_token_id = '', bool $save_method = false, array $options = array() ) {
        $access_token = $this->get_access_token();

        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $total    = $order->get_total();
        $currency = $order->get_currency();

        if ( $total <= 0 ) {
            return new \WP_Error(
                'tejcart_paypal_invalid_amount',
                __( 'Order total must be greater than zero.', 'tejcart' )
            );
        }

        if ( ! preg_match( '/^[A-Z]{3}$/', strtoupper( (string) $currency ) ) ) {
            return new \WP_Error(
                'tejcart_paypal_invalid_currency',
                __( 'Order currency is invalid.', 'tejcart' )
            );
        }

        $send_items     = 'yes' === (string) $this->gateway->get_option( 'send_line_items', 'yes' );
        $items          = array();
        $items_subtotal = 0.0;

        if ( $send_items && method_exists( $order, 'get_items' ) ) {
            foreach ( (array) $order->get_items() as $line ) {
                $name     = '';
                $quantity = 0;
                $unit     = 0.0;

                if ( is_object( $line ) ) {
                    $name     = isset( $line->product_name ) ? (string) $line->product_name : '';
                    $quantity = isset( $line->quantity ) ? (int) $line->quantity : 0;
                    $unit     = isset( $line->unit_price ) ? (float) $line->unit_price : 0.0;
                } elseif ( is_array( $line ) ) {
                    $name     = (string) ( $line['product_name'] ?? '' );
                    $quantity = (int) ( $line['quantity'] ?? 0 );
                    $unit     = (float) ( $line['unit_price'] ?? 0 );
                }

                if ( '' === $name || $quantity <= 0 ) {
                    continue;
                }

                $items[] = array(
                    'name'        => mb_substr( $name, 0, 127 ),
                    'quantity'    => (string) $quantity,
                    'unit_amount' => array(
                        'currency_code' => strtoupper( $currency ),
                        // Currency-aware: zero-decimal currencies (JPY, KRW…)
                        // must NOT carry decimal places and three-decimal
                        // currencies (KWD, BHD…) need 3 — a hardcoded 2 here
                        // made PayPal reject create-order for those, and also
                        // disagreed with the breakdown below (which already
                        // uses self::format_amount).
                        'value'         => self::format_amount( $unit, $currency ),
                    ),
                );
                $items_subtotal += $unit * $quantity;
            }
        }

        $purchase_unit = array(
            'reference_id' => (string) $order->get_id(),
            'description'  => sprintf(
                /* translators: %s: order number */
                __( 'Order #%s', 'tejcart' ),
                $order->get_id()
            ),
            'amount'       => array(
                'currency_code' => strtoupper( $currency ),
                // Currency-aware (see unit_amount above): matches the
                // breakdown's self::format_amount so the top-level amount and
                // item_total agree for zero/three-decimal currencies.
                'value'         => self::format_amount( $total, $currency ),
            ),
        );

        $invoice_prefix = (string) $this->gateway->get_option( 'invoice_prefix', 'TEJ-' );
        if ( '' !== $invoice_prefix ) {
            // PayPal enforces lifetime uniqueness of `invoice_id` across the
            // entire merchant account. A deterministic `{prefix}{order_id}`
            // collides with any prior successful capture that used the same
            // pair (DB restore, staging→prod data import, two installs that
            // share a merchant account, the same TejCart order being retried
            // after a previously-completed capture, …) and PayPal then
            // rejects the next capture with DUPLICATE_INVOICE_ID. We append
            // an incrementing per-order attempt counter and a short random
            // nonce so every create-order call yields a fresh invoice_id
            // while still keeping the order id searchable in the PayPal
            // merchant dashboard.
            $attempt = max( 0, (int) tejcart_get_order_meta( (int) $order->get_id(), '_paypal_invoice_attempt' ) ) + 1;
            tejcart_update_order_meta( (int) $order->get_id(), '_paypal_invoice_attempt', $attempt );

            // 12 lowercase alphanum chars ≈ 62 bits — well above the
            // birthday bound for any plausible merchant volume, so the
            // overall invoice_id stays unique across the merchant
            // account even on a DB restore + retry scenario.
            $nonce = strtolower( wp_generate_password( 12, false, false ) );
            $purchase_unit['invoice_id'] = mb_substr(
                $invoice_prefix . $order->get_id() . '-' . $attempt . '-' . $nonce,
                0,
                127
            );
            tejcart_update_order_meta(
                (int) $order->get_id(),
                '_paypal_invoice_id',
                $purchase_unit['invoice_id']
            );
        }

        $soft_descriptor = (string) $this->gateway->get_option( 'soft_descriptor', '' );
        if ( '' !== $soft_descriptor ) {
            $purchase_unit['soft_descriptor'] = mb_substr( $soft_descriptor, 0, 22 );
        }

        // Pull each component the order knows about so the breakdown
        // we hand to PayPal lists shipping, tax, and discount separately
        // instead of folding everything into a single opaque amount. The
        // PayPal Orders v2 invariant is:
        //     amount.value = item_total + shipping + tax_total - discount
        //                    (+ handling + insurance, both unused here)
        // and the buyer wallet only renders a "Tax" line when tax_total
        // is present in the breakdown. Cent-integer arithmetic mirrors
        // Cart_Calculator and keeps the equality check exact.
        $shipping_total = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
        $tax_total      = method_exists( $order, 'get_tax_total' ) ? (float) $order->get_tax_total() : 0.0;
        $discount_total = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
        // Cart-level fees (gift wrap, handling) are folded into the order total
        // but have no dedicated column — they are stamped as meta at checkout.
        // PayPal's breakdown supports a `handling` field, so surface fees there
        // to keep `item_total + shipping + tax - discount + handling == total`.
        $fees_total     = 0.0;
        if ( method_exists( $order, 'get_meta' ) ) {
            $fees_minor_meta = (string) $order->get_meta( '_tejcart_fees_total' );
            if ( '' !== $fees_minor_meta && class_exists( \TejCart\Money\Currency::class ) ) {
                $fees_total = (float) \TejCart\Money\Currency::from_minor_units( (int) $fees_minor_meta, $currency );
            }
        }

        // Derive the major→minor multiplier from the currency's actual
        // ISO-4217 decimal places (0 for JPY, 2 for USD/EUR, 3 for the
        // Gulf currencies). Hardcoding *100 silently misclassifies a
        // valid breakdown as imbalanced for non-2-decimal currencies and
        // drops the items[] array — see build_amount_patch_op() for the
        // canonical reference.
        $multiplier     = class_exists( \TejCart\Money\Currency::class )
            ? (int) \TejCart\Money\Currency::multiplier( $currency )
            : 100;
        $items_cents    = (int) round( $items_subtotal * $multiplier );
        $shipping_cents = (int) round( $shipping_total * $multiplier );
        $tax_cents      = (int) round( $tax_total * $multiplier );
        $discount_cents = (int) round( $discount_total * $multiplier );
        $handling_cents = (int) round( $fees_total * $multiplier );
        $total_cents    = (int) round( $total * $multiplier );

        $breakdown_balances = ( $items_cents + $shipping_cents + $tax_cents - $discount_cents + $handling_cents ) === $total_cents;

        if ( ! empty( $items ) && $breakdown_balances ) {
            $cc = strtoupper( $currency );

            $breakdown = array(
                'item_total' => array(
                    'currency_code' => $cc,
                    'value'         => self::format_amount( $items_subtotal, $cc ),
                ),
            );

            if ( $shipping_cents > 0 ) {
                $breakdown['shipping'] = array(
                    'currency_code' => $cc,
                    'value'         => self::format_amount( $shipping_total, $cc ),
                );
            }

            if ( $tax_cents > 0 ) {
                $breakdown['tax_total'] = array(
                    'currency_code' => $cc,
                    'value'         => self::format_amount( $tax_total, $cc ),
                );
            }

            if ( $discount_cents > 0 ) {
                $breakdown['discount'] = array(
                    'currency_code' => $cc,
                    'value'         => self::format_amount( $discount_total, $cc ),
                );
            }

            if ( $handling_cents > 0 ) {
                $breakdown['handling'] = array(
                    'currency_code' => $cc,
                    'value'         => self::format_amount( $fees_total, $cc ),
                );
            }

            $purchase_unit['items']               = $items;
            $purchase_unit['amount']['breakdown'] = $breakdown;
            // Log the branch decision so customer reports of
            // "PayPal showed the wrong total" can be triaged from the
            // log alone.
            tejcart_log(
                sprintf(
                    'PayPal create_order on order #%d: line-items mode (item_total=%s, shipping=%s, tax=%s, discount=%s, total=%s %s).',
                    (int) $order->get_id(),
                    self::format_amount( $items_subtotal, $cc ),
                    self::format_amount( $shipping_total, $cc ),
                    self::format_amount( $tax_total, $cc ),
                    self::format_amount( $discount_total, $cc ),
                    self::format_amount( $total, $cc ),
                    $cc
                ),
                'debug'
            );
        } elseif ( ! empty( $items ) ) {
            // Historical behaviour silently dropped the items[]
            // array whenever the line-item subtotal disagreed with the
            // order total by more than a cent (typical cause: tax / shipping
            // computed in floats). PayPal then accepts the order but
            // merchants reconciling against a PayPal statement see opaque
            // totals with no breakdown. Log the drift and stamp it on the
            // order so the operator has a paper trail when they go looking.
            //
            // PAY-002 — strict mode (filter, default off) escalates the
            // silent drop to a hard rejection so the merchant fixes the
            // math instead of shipping opaque line-item-less PayPal
            // orders that nobody can reconcile against a PayPal statement.
            $delta_cents = $items_cents + $shipping_cents + $tax_cents - $discount_cents - $total_cents;
            $cc_for_log = strtoupper( $currency );
            tejcart_log(
                sprintf(
                    'PayPal line items dropped on order #%d: items_subtotal=%s, shipping=%s, tax=%s, discount=%s, total=%s, delta=%d minor units.',
                    (int) $order->get_id(),
                    self::format_amount( $items_subtotal, $cc_for_log ),
                    self::format_amount( $shipping_total, $cc_for_log ),
                    self::format_amount( $tax_total, $cc_for_log ),
                    self::format_amount( $discount_total, $cc_for_log ),
                    self::format_amount( $total, $cc_for_log ),
                    $delta_cents
                ),
                'warning'
            );
            tejcart_update_order_meta(
                (int) $order->get_id(),
                '_paypal_items_dropped_delta_cents',
                $delta_cents
            );

            /**
             * Filter whether the PayPal Orders API call aborts when the
             * line-item breakdown disagrees with the order total.
             *
             * Default `false` preserves the historical fall-through
             * behaviour (drop items[], submit amount-only). Flip to
             * `true` if your reconciliation pipeline depends on
             * never shipping a PayPal order without a parseable
             * line-item breakdown.
             *
             * @since 1.0.1
             *
             * @param bool $strict      Default false.
             * @param int  $delta_cents Imbalance in minor units.
             * @param int  $order_id    TejCart order id.
             */
            $strict = (bool) apply_filters(
                'tejcart_paypal_strict_items_breakdown',
                false,
                $delta_cents,
                (int) $order->get_id()
            );
            if ( $strict ) {
                return new \WP_Error(
                    'tejcart_paypal_items_breakdown_imbalanced',
                    sprintf(
                        /* translators: %d: delta in minor currency units */
                        __( 'PayPal line-item breakdown does not match the order total (off by %d minor units). Strict mode is enabled.', 'tejcart' ),
                        $delta_cents
                    ),
                    array( 'status' => 500 )
                );
            }
        } elseif ( empty( $items ) ) {
            // Visibly record the amount-only fallback (either
            // `send_line_items` disabled, or every line was empty / had
            // a zero quantity) so support can confirm the chosen mode
            // when a buyer reports a mismatched PayPal sheet total.
            tejcart_log(
                sprintf(
                    'PayPal create_order on order #%d: amount-only mode (send_items=%s, total=%s %s).',
                    (int) $order->get_id(),
                    $send_items ? 'yes' : 'no',
                    self::format_amount( $total, strtoupper( $currency ) ),
                    strtoupper( $currency )
                ),
                'debug'
            );
        }

        $intent = strtoupper( (string) $this->gateway->get_option( 'payment_action', 'capture' ) ) === 'AUTHORIZE'
            ? 'AUTHORIZE'
            : 'CAPTURE';

        $brand_name          = (string) $this->gateway->get_option( 'brand_name', '' );
        if ( '' === $brand_name ) {
            $brand_name = (string) get_bloginfo( 'name' );
        }
        $brand_name          = mb_substr( $brand_name, 0, 127 );

        $allowed_landing     = array( 'LOGIN', 'BILLING', 'NO_PREFERENCE' );
        $landing_page        = strtoupper( (string) $this->gateway->get_option( 'landing_page', 'NO_PREFERENCE' ) );
        if ( ! in_array( $landing_page, $allowed_landing, true ) ) {
            $landing_page = 'NO_PREFERENCE';
        }

        $allowed_shipping    = array( 'GET_FROM_FILE', 'NO_SHIPPING', 'SET_PROVIDED_ADDRESS' );
        $shipping_preference = strtoupper( (string) $this->gateway->get_option( 'shipping_preference', 'GET_FROM_FILE' ) );
        if ( ! in_array( $shipping_preference, $allowed_shipping, true ) ) {
            $shipping_preference = 'GET_FROM_FILE';
        }

        // Global shipping kill-switch overrides the per-gateway preference.
        // When the merchant has shipping disabled, PayPal must not collect a
        // shipping address — otherwise the wallet sheet fires
        // onShippingAddressChange, our update_shipping endpoint runs, and
        // (for stores with no zones configured) returns a COUNTRY_ERROR
        // that the buyer can never resolve. NO_SHIPPING short-circuits the
        // whole loop. Mirrors the symmetric tax flag.
        //
        // The same logic applies per-order when none of the items in the
        // current order need shipping (digital / virtual / downloadable
        // SKUs). Without this gate the PDP "Buy Now" button on a digital
        // product would render the PayPal address sheet on a store that
        // also sells physical goods — confusing the buyer and, on a store
        // with no zone covering their address, hard-failing the flow.
        if ( 'yes' !== (string) get_option( 'tejcart_enable_shipping', 'no' )
             || ! PayPal_AJAX::order_needs_shipping( $order ) ) {
            $shipping_preference = 'NO_SHIPPING';
        }

        $allowed_user_action = array( 'PAY_NOW', 'CONTINUE' );
        $user_action         = strtoupper( (string) $this->gateway->get_option( 'user_action', 'PAY_NOW' ) );
        if ( ! in_array( $user_action, $allowed_user_action, true ) ) {
            $user_action = 'PAY_NOW';
        }

        // When the order has a shipping address with a known
        // country, hand PayPal a `shipping.options[]` list so the wallet
        // sheet renders a method picker. Express orders that haven't seen
        // the buyer's address yet skip this; their selector is filled in
        // by `patch_order_shipping_options()` once PayPal returns an
        // address via onShippingChange. Disabled when shipping_preference
        // is NO_SHIPPING (digital-only stores) or SET_PROVIDED_ADDRESS
        // (the wallet renders no picker once the address is locked — see
        // the per-block note below).
        if ( 'NO_SHIPPING' !== $shipping_preference ) {
            $purchase_unit_shipping = isset( $purchase_unit['shipping'] ) && is_array( $purchase_unit['shipping'] )
                ? $purchase_unit['shipping']
                : array();

            // Forward the address the buyer already typed on the checkout
            // page to PayPal so they are not asked to re-enter it inside the
            // wallet sheet. This is the on-page "PayPal" payment-method flow
            // (Checkout::process() has populated the order's shipping
            // address from the form before we get here). Switching to
            // SET_PROVIDED_ADDRESS makes PayPal use our address verbatim
            // instead of GET_FROM_FILE (which silently ignores it and pulls
            // the address off the payer's PayPal account). Express orders —
            // where no address has been collected yet — return an empty
            // payload here and keep their GET_FROM_FILE preference so the
            // wallet still collects the address.
            $provided_shipping = $this->build_provided_shipping_payload( $order );
            if ( ! empty( $provided_shipping ) ) {
                $purchase_unit_shipping = array_merge( $purchase_unit_shipping, $provided_shipping );
                $shipping_preference    = 'SET_PROVIDED_ADDRESS';
            }

            // `shipping.options[]` is only valid under GET_FROM_FILE — the
            // one preference where PayPal both collects/confirms the address
            // and renders a shipping-method picker on the wallet sheet. The
            // moment the address is locked (SET_PROVIDED_ADDRESS, either set
            // just above from the on-page checkout address or configured by
            // the merchant) PayPal stops showing that picker, and sending
            // options anyway fails the create-order POST with HTTP 422
            // SHIPPING_OPTIONS_NOT_SUPPORTED. The method the buyer already
            // chose on our checkout page is still charged via
            // `amount.breakdown.shipping`, so there is nothing left to pick
            // inside the wallet. Express (GET_FROM_FILE) orders keep their
            // options here; onShippingChange PATCHes a fresh list per
            // address the buyer selects in the wallet.
            if ( 'GET_FROM_FILE' === $shipping_preference ) {
                $shipping_options = $this->build_shipping_options_for_order( $order );
                if ( ! empty( $shipping_options ) ) {
                    $purchase_unit_shipping['options'] = $shipping_options;
                }
            }

            if ( ! empty( $purchase_unit_shipping ) ) {
                $purchase_unit['shipping'] = $purchase_unit_shipping;
            }
        }

        // Persist the shipping_preference this order was created with so the
        // wallet-callback path (PayPal_AJAX::update_shipping) can tell a
        // SET_PROVIDED_ADDRESS order — address locked from the on-page
        // checkout, no method picker rendered — apart from a GET_FROM_FILE
        // express order. The former 422s with SHIPPING_OPTIONS_NOT_SUPPORTED
        // on any shipping.options[] PATCH, so update_shipping must skip the
        // PATCH and echo the order's existing totals back instead. The
        // resolved preference is a fixed property of the created PayPal
        // order, so it can't be re-derived from the order's (mutable)
        // address later — it has to be recorded here.
        $persist_pref_order_id = (int) $order->get_id();
        if ( $persist_pref_order_id > 0 ) {
            tejcart_update_order_meta( $persist_pref_order_id, '_paypal_shipping_preference', $shipping_preference );
        }

        $body = array(
            'intent'         => $intent,
            'purchase_units' => array( $purchase_unit ),
            'application_context' => array(
                'return_url'          => $order->get_checkout_return_url(),
                'cancel_url'          => $order->get_checkout_cancel_url(),
                'brand_name'          => $brand_name,
                'landing_page'        => $landing_page,
                'shipping_preference' => $shipping_preference,
                'user_action'         => $user_action,
            ),
        );

        // `paypal_request_id` is the deterministic idempotency key the
        // subscription renewal + switch flows pass through so a retried
        // Action Scheduler attempt that timed out at the network layer
        // doesn't create a second order at PayPal.
        //
        // When the caller doesn't supply one, derive a key from the
        // TejCart order ID + the just-incremented `_paypal_invoice_attempt`
        // counter (read fresh from meta so we pick up the value set
        // above, and so the `invoice_prefix === ''` branch — which
        // skips the local increment — still gets a stable key based
        // on the persisted attempt). This means every call site
        // (Smart Buttons via PayPal_AJAX, Smart Buttons express,
        // hosted-card via Card_Gateway, and the wallet/full-checkout
        // path via PayPal_Gateway) gets idempotency-by-default without
        // having to plumb the key through manually. Subscriptions'
        // bespoke `nx_sub_*` key still wins because the explicit option
        // is checked first.
        $request_id = isset( $options['paypal_request_id'] ) ? (string) $options['paypal_request_id'] : null;
        if ( null === $request_id || '' === $request_id ) {
            $persisted_attempt = max(
                1,
                (int) tejcart_get_order_meta( (int) $order->get_id(), '_paypal_invoice_attempt' )
            );
            $request_id = Idempotency_Key::for_create_order(
                (int) $order->get_id(),
                $persisted_attempt
            );
        }
        $headers = $this->get_headers( $access_token, $request_id );

        $funding_source = isset( $options['funding_source'] ) ? (string) $options['funding_source'] : '';
        $three_d_secure = isset( $options['three_d_secure'] ) ? strtoupper( (string) $options['three_d_secure'] ) : '';
        $allowed_3ds    = array( 'SCA_ALWAYS', 'SCA_WHEN_REQUIRED', 'NONE' );

        if ( '' !== $vault_token_id && self::is_valid_paypal_id( $vault_token_id ) ) {
            $body['payment_source'] = array(
                'token' => array(
                    'id'   => $vault_token_id,
                    'type' => 'PAYMENT_METHOD_TOKEN',
                ),
            );
        } elseif ( 'card' === $funding_source && in_array( $three_d_secure, $allowed_3ds, true ) && 'NONE' !== $three_d_secure ) {
            $body['payment_source'] = array(
                'card' => array(
                    'attributes' => array(
                        'verification' => array(
                            'method' => $three_d_secure,
                        ),
                    ),
                ),
            );

            if ( $save_method ) {
                $body['payment_source']['card']['attributes']['vault'] = array(
                    'store_in_vault' => 'ON_SUCCESS',
                );
            }
        } elseif ( $save_method ) {
            $body['payment_source'] = array(
                'paypal' => array(
                    'attributes'         => array(
                        'vault' => array(
                            'store_in_vault' => 'ON_SUCCESS',
                            'usage_type'     => 'MERCHANT',
                            'customer_type'  => 'CONSUMER',
                        ),
                    ),
                    'experience_context' => array(
                        'return_url'          => $order->get_checkout_return_url(),
                        'cancel_url'          => $order->get_checkout_cancel_url(),
                        'brand_name'          => $brand_name,
                        'landing_page'        => $landing_page,
                        'shipping_preference' => $shipping_preference,
                        'user_action'         => $user_action,
                    ),
                ),
            );
            // PayPal Orders v2 rejects requests that include both
            // `application_context` and `payment_source.paypal.experience_context`
            // with INCOMPATIBLE_PARAMETER_VALUE on every overlapping field
            // (brand_name, return_url, cancel_url, landing_page,
            // shipping_preference, user_action). experience_context fully
            // supersedes application_context for the PayPal wallet vault
            // flow, so drop the top-level block to satisfy the API.
            unset( $body['application_context'] );
        }

        return $this->request( '/v2/checkout/orders', 'POST', $body, $headers );
    }

    /**
     * Validate that a PayPal ID contains only safe characters for URL path segments.
     *
     * @param string $id The PayPal ID to validate.
     * @return bool True if valid, false otherwise.
     */
    private static function is_valid_paypal_id( string $id ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9-]+$/', $id );
    }

    /**
     * Capture a previously approved PayPal order.
     *
     * @param string $paypal_order_id PayPal order ID.
     * @return array|\WP_Error Capture data on success, WP_Error on failure.
     */
    public function capture_order( string $paypal_order_id, ?string $paypal_request_id = null ) {
        $headers = $this->prepare_authorized( $paypal_order_id, $paypal_request_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $result = $this->request( '/v2/checkout/orders/' . $paypal_order_id . '/capture', 'POST', array(), $headers );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $captures = $result['purchase_units'][0]['payments']['captures'] ?? [];
        if ( empty( $captures ) ) {
            tejcart_log( 'PayPal capture: No captures in response', 'error' );
            return new \WP_Error( 'capture_failed', __( 'Payment capture failed.', 'tejcart' ) );
        }

        $captured = $captures[0];
        $captured_amount = (float) ( $captured['amount']['value'] ?? 0 );
        $captured_currency = $captured['amount']['currency_code'] ?? '';

        $result['_tejcart_captured_amount'] = $captured_amount;
        $result['_tejcart_captured_currency'] = $captured_currency;

        return $result;
    }

    /**
     * List registered PayPal webhooks for the current app.
     *
     * @return array|\WP_Error
     */
    public function list_webhooks() {
        $headers = $this->prepare_authorized( '', null, true );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v1/notifications/webhooks', 'GET', array(), $headers );
    }

    /**
     * Register a webhook URL with PayPal and subscribe to the event types
     * this plugin handles.
     *
     * @param string $url Publicly reachable webhook URL.
     * @return array|\WP_Error Decoded response including the assigned webhook id.
     */
    public function create_webhook( string $url ) {
        $headers = $this->prepare_authorized();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $body = array(
            'url'         => $url,
            'event_types' => array_map(
                static function ( $name ) { return array( 'name' => $name ); },
                PayPal_Webhook::event_types()
            ),
        );
        return $this->request( '/v1/notifications/webhooks', 'POST', $body, $headers );
    }

    /**
     * Delete a registered webhook.
     *
     * @param string $webhook_id PayPal webhook ID.
     * @return true|\WP_Error
     */
    public function delete_webhook( string $webhook_id ) {
        $headers = $this->prepare_authorized( $webhook_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $result = $this->request( '/v1/notifications/webhooks/' . $webhook_id, 'DELETE', array(), $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Patch a PayPal order to update its amount (RFC 6902 JSON Patch).
     *
     * Used by the onShippingChange flow to replace the purchase unit's
     * amount and breakdown when the buyer picks a different shipping
     * method inside the PayPal approval sheet.
     *
     * @param string $paypal_order_id PayPal order ID.
     * @param string $reference_id    Purchase-unit `reference_id` set at create_order() time
     *                                (the TejCart order ID for orders we minted).
     * @param float  $total           New order total.
     * @param float  $item_total      New item subtotal.
     * @param float  $shipping_total  New shipping amount.
     * @param float  $tax_total       New tax amount.
     * @param string $currency        Currency code.
     * @param float  $discount_total  Coupon / order-level discount. Must be included
     *                                so the breakdown reconciles with the amount —
     *                                PayPal's Orders v2 invariant is
     *                                `value = item_total + shipping + tax_total - discount`
     *                                and omitting a positive discount produces a 422
     *                                AMOUNT_MISMATCH that drops the buyer out of the
     *                                wallet sheet mid-onShippingChange.
     * @return true|\WP_Error
     */
    public function patch_order_amount( string $paypal_order_id, string $reference_id, float $total, float $item_total, float $shipping_total, float $tax_total, string $currency, float $discount_total = 0.0 ) {
        $headers = $this->prepare_authorized( $paypal_order_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $patch = array(
            $this->build_amount_patch_op( $reference_id, $total, $item_total, $shipping_total, $tax_total, $currency, $discount_total ),
        );

        $result = $this->request( '/v2/checkout/orders/' . $paypal_order_id, 'PATCH', $patch, $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Combined amount + shipping-options PATCH. Required when the buyer
     * switches the selected shipping option mid-flow: PayPal validates each
     * PATCH against the current state of the order, so issuing the amount
     * and options updates as two separate requests will 422 with
     * `PREFERRED_SHIPPING_OPTION_AMOUNT_MISMATCH` on whichever request lands
     * first — the not-yet-updated side of the order still references the
     * previous selection's cost. Sending both ops in a single PATCH lets
     * PayPal validate the final state and accept the change.
     *
     * @param string                                                                                                          $paypal_order_id PayPal order ID.
     * @param string                                                                                                          $reference_id    Purchase-unit `reference_id` (TejCart order ID for orders we minted).
     * @param float                                                                                                           $total           New order total.
     * @param float                                                                                                           $item_total      New item subtotal.
     * @param float                                                                                                           $shipping_total  New shipping amount; MUST equal the `selected: true` option's `amount.value`.
     * @param float                                                                                                           $tax_total       New tax amount.
     * @param string                                                                                                          $currency        Currency code.
     * @param float                                                                                                           $discount_total  Coupon / order-level discount (see {@see patch_order_amount()}).
     * @param list<array{id:string,label:string,type:string,selected:bool,amount:array{currency_code:string,value:string}}>   $options         Shipping options payload from {@see build_shipping_options_for_order()}.
     * @return true|\WP_Error
     */
    public function patch_order_amount_with_shipping_options( string $paypal_order_id, string $reference_id, float $total, float $item_total, float $shipping_total, float $tax_total, string $currency, float $discount_total, array $options ) {
        $headers = $this->prepare_authorized( $paypal_order_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        // Order matters: PATCH ops are applied in array order, but PayPal
        // validates against the post-apply state, so the pairing succeeds
        // regardless of which op comes first. Keep amount first to mirror
        // the legacy single-op call sites in logs / debugging.
        $patch = array(
            $this->build_amount_patch_op( $reference_id, $total, $item_total, $shipping_total, $tax_total, $currency, $discount_total ),
            $this->build_shipping_options_patch_op( $reference_id, $options ),
        );

        $result = $this->request( '/v2/checkout/orders/' . $paypal_order_id, 'PATCH', $patch, $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Build the `amount` PATCH op for a purchase unit. Extracted so
     * {@see patch_order_amount()} and
     * {@see patch_order_amount_with_shipping_options()} share the
     * cent-integer balance check that drops a broken breakdown rather
     * than letting PayPal reject the call with 422 AMOUNT_MISMATCH —
     * a missing breakdown is valid; a wrong one strands the buyer.
     *
     * F-PPCP-004: the `send_line_items` gateway setting gates the entire
     * breakdown key on PATCH — matching `create_order()` behaviour. When
     * the setting is off the caller receives an amount-only op, consistent
     * with the amount-only PayPal order that was created.
     *
     * @return array{op:string,path:string,value:array<string,mixed>}
     */
    private function build_amount_patch_op( string $reference_id, float $total, float $item_total, float $shipping_total, float $tax_total, string $currency, float $discount_total ): array {
        $cc = strtoupper( $currency );

        // F-PPCP-004: honour the send_line_items toggle on PATCH, mirroring
        // create_order(). A merchant who has disabled line items expects an
        // amount-only PayPal order throughout its lifecycle.
        $send_items = 'yes' === (string) $this->gateway->get_option( 'send_line_items', 'yes' );

        // Derive the major→minor multiplier from the currency's actual
        // ISO-4217 decimal places (0 for JPY, 2 for USD/EUR, 3 for the
        // Gulf currencies). Hardcoding *100 silently misclassifies a
        // valid breakdown as imbalanced for non-2-decimal currencies and
        // drops the breakdown entirely.
        $multiplier     = class_exists( \TejCart\Money\Currency::class )
            ? (int) \TejCart\Money\Currency::multiplier( $cc )
            : 100;
        $items_cents    = (int) round( $item_total * $multiplier );
        $shipping_cents = (int) round( $shipping_total * $multiplier );
        $tax_cents      = (int) round( $tax_total * $multiplier );
        $discount_cents = (int) round( $discount_total * $multiplier );
        $total_cents    = (int) round( $total * $multiplier );
        $balances       = ( $items_cents + $shipping_cents + $tax_cents - $discount_cents ) === $total_cents;

        $amount = array(
            'currency_code' => $cc,
            'value'         => self::format_amount( $total, $cc ),
        );

        if ( $send_items && $balances ) {
            $breakdown = array(
                'item_total' => array( 'currency_code' => $cc, 'value' => self::format_amount( $item_total, $cc ) ),
                'shipping'   => array( 'currency_code' => $cc, 'value' => self::format_amount( $shipping_total, $cc ) ),
                'tax_total'  => array( 'currency_code' => $cc, 'value' => self::format_amount( $tax_total, $cc ) ),
            );
            if ( $discount_cents > 0 ) {
                $breakdown['discount'] = array( 'currency_code' => $cc, 'value' => self::format_amount( $discount_total, $cc ) );
            }
            $amount['breakdown'] = $breakdown;
        }

        // PayPal Orders v2 PATCH does not accept positional RFC-6901
        // pointers into `purchase_units`; it returns 422
        // INVALID_JSON_POINTER_FORMAT for `/purchase_units/0/amount`.
        // The documented form is the predicate extension
        // `/purchase_units/@reference_id=='<ref>'/amount`, where `<ref>`
        // is the value passed under `reference_id` in create_order().
        // Our create_order() sets that to (string) $order->get_id().
        // Use `op: add`: per PayPal Orders v2 PATCH semantics (RFC 6902),
        // `add` against an existing object member replaces it, so a
        // single op handles both newly-set and already-present
        // properties uniformly. This matches the `add`-everywhere
        // convention used throughout the gateway and side-steps the
        // 422 INVALID_PATCH_OPERATION class of errors PayPal returns
        // for `replace` against a missing target.
        return array(
            'op'    => 'add',
            'path'  => self::purchase_unit_pointer( $reference_id, '/amount' ),
            'value' => $amount,
        );
    }

    /**
     * Replace the purchase-unit `shipping.options[]` on an existing PayPal
     * order, used by the express onShippingChange flow once the buyer's
     * address arrives. PATCH semantics: the entire options array is
     * replaced — there is no PayPal merge for individual options.
     *
     * @param string                                                           $paypal_order_id PayPal order ID.
     * @param string                                                           $reference_id    Purchase-unit `reference_id` (TejCart order ID for orders we minted).
     * @param list<array{id:string,label:string,type:string,selected:bool,amount:array{currency_code:string,value:string}}> $options Already-formatted options payload.
     * @return true|\WP_Error
     */
    public function patch_order_shipping_options( string $paypal_order_id, string $reference_id, array $options ) {
        $headers = $this->prepare_authorized( $paypal_order_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $patch = array( $this->build_shipping_options_patch_op( $reference_id, $options ) );

        $result = $this->request( '/v2/checkout/orders/' . $paypal_order_id, 'PATCH', $patch, $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Build the `shipping.options` PATCH op for a purchase unit.
     *
     * @param list<array{id:string,label:string,type:string,selected:bool,amount:array{currency_code:string,value:string}}> $options
     * @return array{op:string,path:string,value:list<array<string,mixed>>}
     */
    private function build_shipping_options_patch_op( string $reference_id, array $options ): array {
        // PayPal Orders v2 requires the predicate JSON-Pointer extension
        // for purchase_unit fields; positional indices are rejected with
        // 422 INVALID_JSON_POINTER_FORMAT. See patch_order_amount().
        //
        // Use `op: add` (not `replace`): create_order() does NOT include
        // a `shipping` object on the purchase unit in the express flow,
        // so on the first onShippingChange the path
        // `/shipping/options` does not exist yet and `replace` returns
        // 422 INVALID_PATCH_OPERATION ("Cannot replace a property thats
        // not present, use add."). Per PayPal Orders v2 PATCH semantics
        // (RFC 6902), `add` against an object member creates it when
        // missing and replaces it when present, so the same op handles
        // both the first call and every subsequent address change.
        return array(
            'op'    => 'add',
            'path'  => self::purchase_unit_pointer( $reference_id, '/shipping/options' ),
            'value' => array_values( $options ),
        );
    }

    /**
     * Build a PayPal-flavoured JSON-Pointer that targets a purchase unit by
     * its `reference_id`, e.g. `/purchase_units/@reference_id=='26'/amount`.
     *
     * `reference_id` values we mint are the TejCart order ID cast to a
     * string, which is always a non-empty digit run; we still strip
     * single-quote characters defensively so a custom reference_id
     * (e.g. an `tejcart_paypal_purchase_unit_reference_id` filter override)
     * cannot escape the predicate.
     *
     * @param string $reference_id Purchase-unit reference_id.
     * @param string $suffix       Path under the purchase unit, with leading slash (e.g. `/amount`).
     */
    private static function purchase_unit_pointer( string $reference_id, string $suffix ): string {
        $safe_ref = str_replace( "'", '', $reference_id );
        if ( '' === $safe_ref ) {
            // Fall back to PayPal's documented default-purchase-unit alias
            // rather than emit an obviously broken `==''` predicate. In
            // practice this is unreachable for orders TejCart created.
            $safe_ref = 'default';
        }
        return "/purchase_units/@reference_id=='" . $safe_ref . "'" . $suffix;
    }

    /**
     * Build the `purchase_units[].shipping` name + address payload from the
     * shipping address already stored on the order.
     *
     * Returns an empty array — leaving the caller's shipping_preference
     * untouched — unless the order carries at least a 2-letter ISO country
     * and a street line. PayPal rejects (HTTP 422) a SET_PROVIDED_ADDRESS
     * order whose address is missing those, so a half-filled / empty
     * express order must fall through to GET_FROM_FILE rather than 422 the
     * whole create-order call. Empty optional components (line 2, state,
     * city, postal) are omitted instead of sent blank, again to avoid the
     * API rejecting an empty required-per-country field.
     *
     * @param object $order TejCart order.
     * @return array{name?:array{full_name:string},address?:array<string,string>}
     */
    private function build_provided_shipping_payload( $order ): array {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_address' ) ) {
            return array();
        }

        $address = (array) $order->get_shipping_address();
        if ( empty( $address ) ) {
            return array();
        }

        // get_shipping_address() exposes both prefixed and unprefixed
        // aliases; read the unprefixed form first, fall back to the
        // prefixed key so this also works on raw address blobs.
        $pick = static function ( $key ) use ( $address ) {
            return trim( (string) ( $address[ $key ] ?? $address[ 'shipping_' . $key ] ?? '' ) );
        };

        $country = strtoupper( $pick( 'country' ) );
        $line1   = $pick( 'address_1' );

        if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || '' === $line1 ) {
            return array();
        }

        $address_payload = array(
            'address_line_1' => mb_substr( $line1, 0, 300 ),
            'country_code'   => $country,
        );

        $line2 = $pick( 'address_2' );
        if ( '' !== $line2 ) {
            $address_payload['address_line_2'] = mb_substr( $line2, 0, 300 );
        }
        $city = $pick( 'city' );
        if ( '' !== $city ) {
            $address_payload['admin_area_2'] = mb_substr( $city, 0, 120 );
        }
        $state = $pick( 'state' );
        if ( '' !== $state ) {
            $address_payload['admin_area_1'] = mb_substr( $state, 0, 300 );
        }
        $postcode = $pick( 'postcode' );
        if ( '' !== $postcode ) {
            $address_payload['postal_code'] = mb_substr( $postcode, 0, 60 );
        }

        $payload  = array( 'address' => $address_payload );
        $fullname = trim( $pick( 'first_name' ) . ' ' . $pick( 'last_name' ) );
        if ( '' !== $fullname ) {
            $payload['name'] = array( 'full_name' => mb_substr( $fullname, 0, 300 ) );
        }

        return $payload;
    }

    /**
     * Build a `purchase_units[].shipping.options[]` payload for the given
     * TejCart order from {@see Shipping_Manager::get_available_methods()}.
     *
     * Only emits options when the order has a shipping address with a
     * country code we can hand to the shipping zone resolver; without an
     * address (e.g. brand-new express orders) PayPal renders no method
     * dropdown and the express flow's onShippingChange handler PATCHes
     * options in once the buyer chooses an address.
     *
     * The currently-selected method on the order is marked `selected:true`;
     * if no method has been recorded yet, the first / cheapest available
     * method is the default.
     *
     * The selected option's `amount.value` is pinned to `$order->get_shipping_total()`
     * so it matches `amount.breakdown.shipping.value` (which downstream callers
     * derive from the same getter). Without this pin, Cart_Calculator's
     * post-`$method->calculate()` adjustments — `tejcart_calculated_shipping`
     * filters and the global `tejcart_shipping_class_fees` option — let the
     * order's stored shipping_total drift away from the raw method cost,
     * which 422s with PREFERRED_SHIPPING_OPTION_AMOUNT_MISMATCH on the
     * create-order POST. The non-selected options keep their raw computed
     * amounts; when the buyer switches options in the wallet,
     * {@see PayPal_AJAX::apply_chosen_shipping_method()} re-sets
     * `shipping_total` to the matched option's amount before this method
     * runs again, so the invariant continues to hold.
     *
     * @param object $order TejCart order.
     * @return list<array{id:string,label:string,type:string,selected:bool,amount:array{currency_code:string,value:string}}>
     */
    public function build_shipping_options_for_order( $order ): array {
        if ( ! is_object( $order ) ) {
            return array();
        }
        if ( ! method_exists( $order, 'get_shipping_address' ) ) {
            return array();
        }
        $address = (array) $order->get_shipping_address();
        $country = strtoupper( (string) ( $address['shipping_country'] ?? $address['country'] ?? '' ) );
        if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
            return array();
        }

        $state    = (string) ( $address['shipping_state']    ?? $address['state']    ?? '' );
        $postcode = (string) ( $address['shipping_postcode'] ?? $address['postcode'] ?? '' );

        $currency_code = strtoupper( (string) $order->get_currency() );

        $base = $this->compute_shipping_options_base( $country, $state, $postcode, $currency_code );
        if ( empty( $base ) ) {
            return array();
        }

        $selected_method = method_exists( $order, 'get_shipping_method' ) ? (string) $order->get_shipping_method() : '';
        if ( '' === $selected_method ) {
            // Honour both PayPal-internal (`_shipping_method_id`) and
            // standard-checkout (`_shipping_method`) meta keys so the
            // sheet's pre-selected option matches whatever the buyer
            // already picked, regardless of which flow set it. See
            // PayPal_AJAX::chosen_shipping_method_for_order() for the
            // dual-key rationale.
            $selected_method = PayPal_AJAX::chosen_shipping_method_for_order( (int) $order->get_id() );
        }

        $shipping_total_on_order = method_exists( $order, 'get_shipping_total' )
            ? (float) $order->get_shipping_total()
            : 0.0;
        $pinned_amount_value = self::format_amount( max( 0.0, $shipping_total_on_order ), $currency_code );

        $payload      = array();
        $matched_pick = false;
        foreach ( $base as $option ) {
            $is_selected = ( '' !== $selected_method && $selected_method === $option['id'] );
            if ( $is_selected ) {
                $matched_pick = true;
                $option['amount']['value'] = $pinned_amount_value;
            }
            $option['selected'] = $is_selected;
            $payload[]          = $option;
        }

        // $base was already proven non-empty above; the loop appends one
        // entry per $base entry, so $payload is non-empty here. Default
        // to the first option when nothing on the order matches.
        if ( ! $matched_pick ) {
            $payload[0]['selected']         = true;
            $payload[0]['amount']['value']  = $pinned_amount_value;
        }

        return $payload;
    }

    /**
     * Compute (or retrieve from the per-request memo) the cost-per-method
     * payload for a given destination + cart. Splitting the costly part of
     * {@see build_shipping_options_for_order()} from the cheap "mark
     * selected" step is what lets repeated PayPal callbacks within a
     * single request collapse onto one carrier API round-trip.
     *
     * Cache key includes everything that drives method costs:
     *  - destination (country / state / postcode)
     *  - currency
     *  - cart fingerprint (line items + quantities + chosen-currency state)
     *
     * The `selected` flag is intentionally NOT part of the key — selection
     * is per-call buyer state, not a cost driver, and is layered on by
     * the public method.
     *
     * @return list<array{id:string,label:string,type:string,amount:array{currency_code:string,value:string}}>
     */
    private function compute_shipping_options_base(
        string $country,
        string $state,
        string $postcode,
        string $currency_code
    ): array {
        $cart      = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        $cart_sig  = $this->cart_fingerprint( $cart );
        $cache_key = $country . '|' . $state . '|' . $postcode . '|' . $currency_code . '|' . $cart_sig;

        if ( array_key_exists( $cache_key, $this->shipping_options_cache ) ) {
            return $this->shipping_options_cache[ $cache_key ];
        }

        $manager = $this->get_shipping_manager();
        if ( ! $manager ) {
            return array();
        }

        $methods = $manager->get_available_methods( $country, $state, $cart, $postcode );
        if ( empty( $methods ) ) {
            return $this->store_shipping_options_cache( $cache_key, array() );
        }

        $base = array();
        foreach ( $methods as $method ) {
            if ( ! is_object( $method ) || ! method_exists( $method, 'get_id' ) ) {
                continue;
            }
            $id    = (string) $method->get_id();
            $label = method_exists( $method, 'get_title' ) ? (string) $method->get_title() : $id;
            $cost  = method_exists( $method, 'calculate' ) ? (float) $method->calculate( $cart ) : 0.0;
            // `calculate()` returns the raw base-currency rate. Run it through
            // the same FINAL shipping filter Cart_Calculator applies
            // (`tejcart_calculated_shipping_with_classes`) so multi-currency
            // conversion reaches these wallet options exactly once. The
            // currency-switcher converts on the final filter only (hooking both
            // would double-convert), so we must use the same one here. The
            // selected option is re-pinned to the order's (already-converted)
            // shipping_total by the caller, so this only brings the NON-selected
            // options onto the same converted basis — important because
            // apply_chosen_shipping_method() charges the chosen option's amount
            // verbatim; without this, switching to a non-default option in the
            // wallet would charge the unconverted base amount relabelled as the
            // order currency. Passthrough on a single-currency store.
            $cost  = (float) apply_filters( 'tejcart_calculated_shipping_with_classes', $cost, $cart );
            $base[] = array(
                'id'     => mb_substr( $id, 0, 127 ),
                'label'  => mb_substr( $label, 0, 127 ),
                'type'   => 'SHIPPING',
                'amount' => array(
                    'currency_code' => $currency_code,
                    'value'         => self::format_amount( max( 0.0, $cost ), $currency_code ),
                ),
            );
        }

        return $this->store_shipping_options_cache( $cache_key, $base );
    }

    /**
     * Persist a cache entry, applying a simple FIFO cap so a long-lived
     * worker process processing many distinct buyers cannot grow the
     * memo unbounded.
     *
     * @param list<array{id:string,label:string,type:string,amount:array{currency_code:string,value:string}}> $entry
     * @return list<array{id:string,label:string,type:string,amount:array{currency_code:string,value:string}}>
     */
    private function store_shipping_options_cache( string $key, array $entry ): array {
        if ( count( $this->shipping_options_cache ) >= self::SHIPPING_OPTIONS_CACHE_MAX ) {
            // FIFO eviction — drop the oldest entry to bound memory.
            array_shift( $this->shipping_options_cache );
        }
        $this->shipping_options_cache[ $key ] = $entry;
        return $entry;
    }

    /**
     * Build a cheap, deterministic fingerprint of the cart's contents so
     * the shipping-options memo invalidates the moment line items,
     * quantities, or variations change. The fingerprint deliberately
     * normalises ordering so two cart objects with the same items in
     * different insertion orders produce the same key.
     *
     * @param mixed $cart Cart instance, array, or null.
     */
    private function cart_fingerprint( $cart ): string {
        if ( null === $cart ) {
            return 'no-cart';
        }

        $items = null;
        if ( is_object( $cart ) && method_exists( $cart, 'get_items' ) ) {
            $items = $cart->get_items();
        } elseif ( is_array( $cart ) && isset( $cart['items'] ) ) {
            $items = $cart['items'];
        }

        if ( ! is_array( $items ) || empty( $items ) ) {
            return 'empty-cart';
        }

        $parts = array();
        foreach ( $items as $item ) {
            $product_id   = 0;
            $variation_id = 0;
            $quantity     = 0;

            if ( is_object( $item ) ) {
                if ( isset( $item->product_id ) ) {
                    $product_id = (int) $item->product_id;
                } elseif ( method_exists( $item, 'get_product_id' ) ) {
                    $product_id = (int) $item->get_product_id();
                }
                if ( isset( $item->variation_id ) ) {
                    $variation_id = (int) $item->variation_id;
                } elseif ( method_exists( $item, 'get_variation_id' ) ) {
                    $variation_id = (int) $item->get_variation_id();
                }
                if ( isset( $item->quantity ) ) {
                    $quantity = (int) $item->quantity;
                } elseif ( method_exists( $item, 'get_quantity' ) ) {
                    $quantity = (int) $item->get_quantity();
                }
            } elseif ( is_array( $item ) ) {
                $product_id   = (int) ( $item['product_id']   ?? 0 );
                $variation_id = (int) ( $item['variation_id'] ?? 0 );
                $quantity     = (int) ( $item['quantity']     ?? 0 );
            }

            $parts[] = $product_id . ':' . $variation_id . ':' . $quantity;
        }

        sort( $parts );
        return md5( implode( '|', $parts ) );
    }

    /**
     * Reset the per-request shipping-options memo. Intended for tests and
     * for the rare callers that mutate the cart mid-request and need a
     * fresh recompute on the next call.
     */
    public function clear_shipping_options_cache(): void {
        $this->shipping_options_cache = array();
    }

    /**
     * Resolve a Shipping_Manager instance, preferring the one bound on
     * the core container so tests and Tier-2 overrides keep working.
     */
    protected function get_shipping_manager() {
        if ( function_exists( 'tejcart' ) ) {
            $tejcart = tejcart();
            if ( $tejcart && method_exists( $tejcart, 'container' ) ) {
                $container = $tejcart->container();
                if ( $container && method_exists( $container, 'has' ) && $container->has( 'shipping' )
                     && method_exists( $container, 'make' )
                ) {
                    $bound = $container->make( 'shipping' );
                    if ( $bound instanceof \TejCart\Shipping\Shipping_Manager ) {
                        return $bound;
                    }
                }
            }
        }

        if ( class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            return new \TejCart\Shipping\Shipping_Manager();
        }
        return null;
    }

    /**
     * Format a monetary value for the PayPal REST API.
     *
     * PayPal's Orders v2 API accepts the appropriate number of decimal
     * places per currency: 0 for JPY/KRW/VND, 2 for most (USD/EUR/GBP),
     * 3 for KWD/BHD/OMR/TND. Hard-coding 2 dp truncated three-decimal
     * currencies (sending `10.00` for a 10.000 KWD charge accepted by
     * PayPal as ~1/100th of the intended amount) and emitted redundant
     * trailing zeros for zero-decimal currencies.
     *
     * The currency parameter defaults to USD so legacy call sites that
     * haven't been updated yet retain their previous behaviour. Every
     * call site that has currency in scope (create_order, refund,
     * capture, breakdown construction) should pass it explicitly.
     */
    public static function format_amount( float $value, string $currency = 'USD' ): string {
        $decimals = 2;
        if ( '' !== $currency && class_exists( '\\TejCart\\Money\\Currency' ) ) {
            $decimals = max( 0, (int) \TejCart\Money\Currency::decimals( $currency ) );
        }
        return number_format( $value, $decimals, '.', '' );
    }

    /**
     * Decode an HTTP response body as JSON, defensively handling
     * BOM-prefixed payloads and returning enough diagnostic context for
     * an operator to triage parse failures from the log line alone.
     *
     * Centralized here so every PayPal-namespace HTTP call (token
     * exchange, onboarding auth-code grant, credentials lookup,
     * webhook verify) shares the same BOM strip + diagnostic shape and
     * a parse failure anywhere in the stack surfaces the same
     * actionable info.
     *
     * @param array<string,mixed>|mixed $response The wp_remote_* response array.
     * @return array{
     *     parse_ok:bool,
     *     status:int,
     *     content_type:string,
     *     body_length:int,
     *     body_excerpt:string,
     *     decoded:array<mixed>|null,
     *     raw:string
     * }
     */
    public static function decode_json_response( $response ): array {
        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );

        // Strip a UTF-8 BOM and surrounding whitespace before decoding.
        // Some PayPal-side proxies/CDNs prepend a BOM to otherwise-valid
        // JSON; the raw bytes trip json_decode and the call retried Nx
        // and tripped the circuit breaker even though the payload was fine.
        $clean = $raw;
        if ( 0 === strncmp( $clean, "\xEF\xBB\xBF", 3 ) ) {
            $clean = substr( $clean, 3 );
        }
        $clean = trim( $clean );

        $decoded  = ( '' === $clean ) ? null : json_decode( $clean, true );
        $parse_ok = ( JSON_ERROR_NONE === json_last_error() ) && is_array( $decoded );

        return array(
            'parse_ok'     => $parse_ok,
            'status'       => $status,
            'content_type' => self::response_content_type( $response ),
            'body_length'  => strlen( $raw ),
            'body_excerpt' => self::body_excerpt_for_log( $raw ),
            'decoded'      => $parse_ok ? $decoded : null,
            'raw'          => $raw,
        );
    }

    /**
     * Build a one-line " [status=…, content_type=…, body_length=…, body_excerpt=…]"
     * suffix for a log line. Accepts either the array returned by
     * {@see decode_json_response()} or a WP_Error whose data carries the
     * same keys. Returns an empty string when no diagnostic data is
     * present so the existing "Client Authentication failed" /
     * "circuit open" log lines keep their original shape.
     *
     * @param mixed $source Decoded-response array, WP_Error, or anything
     *                      else (the latter falls through to an empty
     *                      string so wrapped error wrappers stay safe).
     */
    public static function format_response_diagnostics( $source ): string {
        if ( $source instanceof \WP_Error ) {
            $data = $source->get_error_data();
            $data = is_array( $data ) ? $data : array();
        } elseif ( is_array( $source ) ) {
            $data = $source;
        } else {
            return '';
        }
        $parts = array();
        foreach ( array( 'status', 'content_type', 'body_length', 'body_excerpt' ) as $key ) {
            if ( ! array_key_exists( $key, $data ) ) {
                continue;
            }
            $parts[] = $key . '=' . (string) $data[ $key ];
        }
        return $parts ? ' [' . implode( ', ', $parts ) . ']' : '';
    }

    /**
     * Pull the response Content-Type header in a stub-friendly way. Falls
     * back to the raw `headers` map when `wp_remote_retrieve_header` is
     * unavailable (Brain\Monkey unit tests don't auto-stub it).
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private static function response_content_type( $response ): string {
        if ( function_exists( 'wp_remote_retrieve_header' ) ) {
            return (string) wp_remote_retrieve_header( $response, 'content-type' );
        }
        if ( is_array( $response ) && isset( $response['headers'] ) ) {
            $headers = $response['headers'];
            if ( is_array( $headers ) && isset( $headers['content-type'] ) ) {
                return (string) $headers['content-type'];
            }
        }
        return '';
    }

    /**
     * Produce a short, log-safe excerpt of an opaque response body so an
     * operator chasing "Response could not be parsed." can tell at a glance
     * whether PayPal returned an HTML error page from a load balancer, an
     * empty body, or something else. HTML tags are stripped, whitespace
     * collapsed, and the result truncated so the log line stays one line.
     */
    public static function body_excerpt_for_log( string $body ): string {
        if ( '' === $body ) {
            return '<empty>';
        }
        $stripped = wp_strip_all_tags( $body );
        $stripped = (string) preg_replace( '/\s+/', ' ', (string) $stripped );
        $stripped = trim( $stripped );
        if ( '' === $stripped ) {
            return '<no-text>';
        }
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $stripped ) > 200 ) {
            return mb_substr( $stripped, 0, 200 ) . '…';
        }
        if ( strlen( $stripped ) > 200 ) {
            return substr( $stripped, 0, 200 ) . '…';
        }
        return $stripped;
    }

    /**
     * Produce a log-safe representation of a PayPal API
     * payload (request or response). Strips bearer tokens, full PANs,
     * payer email addresses, and address fragments so a verbose
     * gateway log line does not leak buyer PII into shared-hosting
     * backups, sentry breadcrumbs, or grep-able log volumes.
     *
     * Mutating callers should ALWAYS pipe through this helper before
     * passing arbitrary PayPal payloads to {@see tejcart_log()}. The
     * canonical form for a log line is "<short summary>: <redacted JSON>"
     * so a downstream operator can still pattern-match on shape without
     * having to read PII to triage a failure.
     *
     * @param mixed $payload Anything decodeable by wp_json_encode.
     * @return string         JSON string with sensitive fields replaced
     *                        by `[redacted]`.
     */
    public static function redact_for_log( $payload ): string {
        $sensitive_keys = array(
            // Auth + credentials.
            'access_token', 'refresh_token', 'authorization', 'bearer',
            'client_secret', 'client_id_secret',
            // PII.
            'email_address', 'email', 'payer_email',
            'address_line_1', 'address_line_2', 'admin_area_1', 'admin_area_2',
            'postal_code', 'phone', 'phone_number', 'national_number',
            // Card data.
            'card_number', 'number', 'last_digits', 'card_holder_name', 'cvv2',
            // Webhook signature surface.
            'paypal-cert-url', 'paypal-auth-algo', 'paypal-auth-version',
            // M-12: transmission-* headers gate webhook signature verification;
            // capturing them in logs is the precondition for the H-1 cache
            // replay. Redact alongside the cert URL.
            'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time',
            // L-4: vault / setup tokens — leaking these in logs lets a future
            // log reader charge a buyer's saved instrument.
            'vault_id', 'payment_token', 'setup_token',
        );

        $walker = static function ( $value, callable $self ) use ( $sensitive_keys ) {
            if ( is_array( $value ) ) {
                $out = array();
                foreach ( $value as $k => $v ) {
                    $key_str = is_string( $k ) ? strtolower( $k ) : '';
                    if ( '' !== $key_str && in_array( $key_str, $sensitive_keys, true ) ) {
                        $out[ $k ] = '[redacted]';
                        continue;
                    }
                    $out[ $k ] = $self( $v, $self );
                }
                return $out;
            }
            if ( is_string( $value ) ) {
                // Heuristic: strip anything that looks like a JWT / opaque
                // bearer (long base64-ish string) when it landed in a
                // string context that we couldn't key-detect.
                if ( preg_match( '/^[A-Za-z0-9_\-]{32,}\.[A-Za-z0-9_\-]{16,}/', $value ) ) {
                    return '[redacted-token]';
                }
                return $value;
            }
            return $value;
        };

        $redacted = $walker( $payload, $walker );
        $encoded  = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $redacted )
            : json_encode( $redacted );

        return false === $encoded || null === $encoded ? '[unencodable]' : $encoded;
    }

    /**
     * Inspect a card capture response and decide whether the 3-D Secure
     * authentication result is acceptable for the configured SCA policy.
     *
     * Returns `true` when the capture may proceed, or a WP_Error whose
     * message is safe to surface to the buyer when it must be blocked.
     *
     * The checks follow PayPal's documented enrollment / authentication
     * status combinations: an unauthenticated card challenge (`Y` / `N`)
     * on an `SCA_ALWAYS` configuration is treated as a failure even when
     * PayPal itself chose to accept the liability, because the merchant
     * explicitly asked for a challenge.
     *
     * @param array  $capture_response Full capture response body.
     * @param string $policy           SCA_ALWAYS|SCA_WHEN_REQUIRED|NONE.
     * @return true|\WP_Error
     */
    public static function validate_card_3ds_outcome( array $capture_response, string $policy ) {
        $policy = strtoupper( $policy );
        if ( 'NONE' === $policy ) {
            return true;
        }

        $card = $capture_response['payment_source']['card'] ?? null;
        if ( ! is_array( $card ) ) {
            return true;
        }

        $auth = $card['authentication_result'] ?? array();
        if ( ! is_array( $auth ) ) {
            $auth = array();
        }

        $liability = strtoupper( (string) ( $auth['liability_shift'] ?? '' ) );
        $enroll    = strtoupper( (string) ( $auth['three_d_secure']['enrollment_status'] ?? '' ) );
        $status    = strtoupper( (string) ( $auth['three_d_secure']['authentication_status'] ?? '' ) );

        if ( 'SCA_ALWAYS' === $policy ) {
            if ( ! in_array( $status, array( 'Y', 'A' ), true ) ) {
                return new \WP_Error(
                    'tejcart_paypal_sca_failed',
                    __( 'Strong Customer Authentication is required but could not be completed. Please try another card or contact your bank.', 'tejcart' )
                );
            }
        }

        if ( in_array( $status, array( 'N', 'R' ), true ) ) {
            return new \WP_Error(
                'tejcart_paypal_sca_denied',
                __( 'Card authentication was denied. Please try a different card.', 'tejcart' )
            );
        }

        if ( 'SCA_ALWAYS' === $policy && 'Y' === $enroll && 'POSSIBLE' !== $liability && 'YES' !== $liability ) {
            return new \WP_Error(
                'tejcart_paypal_sca_liability',
                __( 'The card issuer required authentication that was not completed. Please retry and follow the verification steps.', 'tejcart' )
            );
        }

        // M-9: surface every liability_shift=NO outcome regardless of
        // policy. PayPal returns NO when the issuer didn't actually
        // authenticate the buyer but completed the transaction; the
        // merchant becomes liable for any subsequent fraud chargeback.
        // Fire an action so admin notice / order-note listeners can
        // record the residual risk, and optionally reject when the
        // merchant has opted into strict mode via the
        // tejcart_paypal_strict_3ds filter.
        if ( 'NO' === $liability ) {
            /**
             * Fires when a card capture completes with PayPal's
             * `liability_shift=NO`. The merchant retains chargeback
             * liability — observability tools should record this on
             * the order audit trail.
             *
             * @param array  $auth   The authentication_result payload from PayPal.
             * @param string $policy Effective SCA policy at the time of capture.
             */
            do_action( 'tejcart_paypal_liability_shift_no', $auth, $policy );

            $strict = (bool) apply_filters( 'tejcart_paypal_strict_3ds', false, $policy, $auth );
            if ( $strict ) {
                return new \WP_Error(
                    'tejcart_paypal_sca_no_liability_shift',
                    __( 'Card authentication completed without a liability shift. Please try a different card or retry the verification.', 'tejcart' )
                );
            }
        }

        return true;
    }

    /**
     * Authorize a previously approved order (intent=AUTHORIZE flow).
     *
     * @param string  $paypal_order_id    PayPal order ID.
     * @param ?string $paypal_request_id  Optional deterministic
     *                                    `PayPal-Request-Id` so a
     *                                    network-level retry within
     *                                    PayPal's idempotency window
     *                                    returns the original response
     *                                    instead of double-authorising.
     *                                    Build via
     *                                    {@see Idempotency_Key::for_authorize()}.
     * @return array|\WP_Error
     */
    public function authorize_order( string $paypal_order_id, ?string $paypal_request_id = null ) {
        $headers = $this->prepare_authorized( $paypal_order_id, $paypal_request_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v2/checkout/orders/' . $paypal_order_id . '/authorize', 'POST', array(), $headers );
    }

    /**
     * Capture a previously authorized payment.
     *
     * @param string     $authorization_id PayPal authorization ID.
     * @param float|null $amount           Optional capture amount.
     * @param string     $currency         Currency code.
     * @return array|\WP_Error
     */
    public function capture_authorization( string $authorization_id, ?float $amount = null, string $currency = 'USD' ) {
        $headers = $this->prepare_authorized( $authorization_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $body = array();
        if ( null !== $amount ) {
            $currency_upper = strtoupper( $currency );
            $body['amount'] = array(
                'currency_code' => $currency_upper,
                'value'         => self::format_amount( $amount, $currency_upper ),
            );
        }
        return $this->request( '/v2/payments/authorizations/' . $authorization_id . '/capture', 'POST', $body, $headers );
    }

    /**
     * Void (cancel) a previously authorized payment.
     *
     * @param string $authorization_id PayPal authorization ID.
     * @return true|\WP_Error
     */
    public function void_authorization( string $authorization_id, ?string $request_id = null ) {
        $headers = $this->prepare_authorized( $authorization_id, $request_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $result = $this->request( '/v2/payments/authorizations/' . $authorization_id . '/void', 'POST', array(), $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Refund a captured payment.
     *
     * Pass `$request_id` to set the `PayPal-Request-Id` header so a
     * retried call (network blip, admin double-click, webhook replay) is
     * deduplicated by PayPal instead of issuing a second refund.
     *
     * @param string      $capture_id PayPal capture ID.
     * @param float|null  $amount     Refund amount (null for full refund).
     * @param string      $currency   Currency code.
     * @param string      $reason     Refund reason.
     * @param string|null $request_id Optional deterministic idempotency key.
     * @return array|\WP_Error Refund data on success, WP_Error on failure.
     */
    public function refund_capture( string $capture_id, ?float $amount, string $currency, string $reason = '', ?string $request_id = null ) {
        $headers = $this->prepare_authorized( $capture_id, $request_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $body = array();
        if ( null !== $amount ) {
            $currency_upper = strtoupper( $currency );
            $body['amount'] = array(
                'currency_code' => $currency_upper,
                'value'         => self::format_amount( $amount, $currency_upper ),
            );
        }
        if ( '' !== $reason ) {
            $body['note_to_payer'] = mb_substr( $reason, 0, 255 );
        }

        return $this->request( '/v2/payments/captures/' . $capture_id . '/refund', 'POST', $body, $headers );
    }

    /**
     * Create a vault setup token, used by the SDK (or the standalone redirect
     * flow) to collect a payment instrument the customer wants to save without
     * charging it.
     *
     * Pass an `experience_context` array with `return_url` / `cancel_url` /
     * `brand_name` to drive the redirect-based vault flow used by the My
     * Account "Add payment method" button — PayPal will redirect the buyer
     * back to `return_url` after they approve the setup token.
     *
     * @param string $payment_source     One of: paypal, card, venmo.
     * @param array  $extra              Additional payment_source[$type] body fields.
     * @param array  $experience_context Optional experience_context (return_url, cancel_url, brand_name).
     * @return array|\WP_Error
     */
    public function create_setup_token( string $payment_source = 'paypal', array $extra = array(), array $experience_context = array() ) {
        if ( ! in_array( $payment_source, array( 'paypal', 'card', 'venmo' ), true ) ) {
            return new \WP_Error( 'tejcart_paypal_invalid_source', __( 'Invalid vault payment source.', 'tejcart' ) );
        }
        $headers = $this->prepare_authorized();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $source_body = array_merge( array( 'usage_type' => 'MERCHANT' ), $extra );
        if ( ! empty( $experience_context ) ) {
            $source_body['experience_context'] = $experience_context;
        }

        $body = array(
            'payment_source' => array(
                $payment_source => $source_body,
            ),
        );
        return $this->request( '/v3/vault/setup-tokens', 'POST', $body, $headers );
    }

    /**
     * Retrieve a vault setup token by ID. Used by the standalone vault
     * return flow to verify that the token PayPal redirected back with
     * actually belongs to this merchant before exchanging it.
     *
     * @param string $setup_token_id Setup token ID.
     * @return array|\WP_Error
     */
    public function get_setup_token( string $setup_token_id ) {
        $headers = $this->prepare_authorized( $setup_token_id, null, true );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v3/vault/setup-tokens/' . $setup_token_id, 'GET', array(), $headers );
    }

    /**
     * Exchange a setup token for a permanent payment token that can be
     * charged on future transactions.
     *
     * @param string $setup_token_id Setup token ID.
     * @return array|\WP_Error
     */
    public function create_payment_token( string $setup_token_id ) {
        $headers = $this->prepare_authorized( $setup_token_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $body = array(
            'payment_source' => array(
                'token' => array(
                    'id'   => $setup_token_id,
                    'type' => 'SETUP_TOKEN',
                ),
            ),
        );
        return $this->request( '/v3/vault/payment-tokens', 'POST', $body, $headers );
    }

    /**
     * Permanently revoke a vaulted payment token.
     *
     * @param string $payment_token_id Vault payment token ID.
     * @return true|\WP_Error
     */
    public function delete_payment_token( string $payment_token_id ) {
        $headers = $this->prepare_authorized( $payment_token_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $result = $this->request( '/v3/vault/payment-tokens/' . $payment_token_id, 'DELETE', array(), $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Retrieve order details from PayPal.
     *
     * @param string $paypal_order_id PayPal order ID.
     * @return array|\WP_Error Order data on success, WP_Error on failure.
     */
    public function get_order_details( string $paypal_order_id ) {
        $headers = $this->prepare_authorized( $paypal_order_id, null, true );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v2/checkout/orders/' . $paypal_order_id, 'GET', array(), $headers );
    }

    /**
     * Fetch a previously-captured payment by its PayPal capture id.
     *
     * Used by PayPal_AJAX (PAY-005 verify-stored-capture-on-retry):
     * before responding success on a retry that hits a stale capture
     * lock, the AJAX handler can confirm the capture id still exists
     * at PayPal. Cached implicitly by PayPal's CDN for read-only
     * captures, so the latency cost on a hot retry is minimal.
     *
     * @param string $capture_id PayPal capture ID.
     * @return array<string, mixed>|\WP_Error Capture data on success, WP_Error on failure.
     */
    public function get_capture( string $capture_id ) {
        if ( '' === $capture_id ) {
            return new \WP_Error( 'tejcart_paypal_capture_id_empty', __( 'PayPal capture ID is empty.', 'tejcart' ) );
        }
        $headers = $this->prepare_authorized( $capture_id, null, true );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v2/payments/captures/' . rawurlencode( $capture_id ), 'GET', array(), $headers );
    }

    /**
     * Perform an HTTP request against the PayPal API.
     *
     * @param string $endpoint API endpoint (relative path).
     * @param string $method   HTTP method.
     * @param array  $body     Request body (will be JSON-encoded for non-GET).
     * @param array  $headers  HTTP headers.
     * @return array|\WP_Error Decoded response body on success, WP_Error on failure.
     */
    private function request( string $endpoint, string $method, array $body, array $headers ) {
        $url = $this->get_api_url() . $endpoint;

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        if ( ! empty( $body ) && 'GET' !== $method ) {
            $args['body'] = wp_json_encode( $body );
        }

        // Debug-level: emit the outgoing request (with PII/secrets redacted)
        // so a full request/response pair can be reconstructed from the log.
        // Gated on `should_log( 'debug' )` so the redaction + JSON encode of
        // the body is skipped entirely unless verbose logging is enabled.
        if ( $this->should_log( 'debug' ) ) {
            $this->paypal_log(
                empty( $body )
                    ? sprintf( '%s %s ← request (no body)', $method, $endpoint )
                    : sprintf( '%s %s ← request %s', $method, $endpoint, self::redact_for_log( $body ) ),
                'debug'
            );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->paypal_log(
                $method . ' ' . $endpoint . ' → transport error: ' . $response->get_error_message(),
                'error'
            );
            return $response;
        }

        $parsed      = self::decode_json_response( $response );
        $status_code = $parsed['status'];
        $decoded     = $parsed['parse_ok'] ? $parsed['decoded'] : array();

        // Non-2xx is a real failure; log as `error` so `tejcart_log_level=error`
        // captures it. 2xx traffic is verbose and only emitted when the
        // operator has explicitly turned on info/debug logging.
        // L: route through the PII-aware redact_for_log() (not the narrow
        // redact(), which omits payer email / address / phone) so a failed
        // payment response body cannot leak buyer PII into gateway logs.
        // redact_for_log() returns an already-encoded JSON string.
        if ( $status_code >= 400 ) {
            $this->paypal_log(
                sprintf( '%s %s → %d %s', $method, $endpoint, $status_code, self::redact_for_log( $decoded ) ),
                $status_code >= 500 ? 'error' : 'warning'
            );
        } elseif ( $this->should_log( 'info' ) ) {
            $this->paypal_log(
                sprintf( '%s %s → %d %s', $method, $endpoint, $status_code, self::redact_for_log( $decoded ) ),
                'info'
            );
        }

        if ( $status_code >= 400 ) {
            $message = self::map_error( $decoded );
            return new \WP_Error(
                'tejcart_paypal_api_error',
                $message,
                array(
                    'status' => $status_code,
                    'name'   => $decoded['name'] ?? '',
                    'issue'  => $decoded['details'][0]['issue'] ?? '',
                )
            );
        }

        return $decoded;
    }

    /**
     * Shared setup for every authenticated call: validates a PayPal id
     * (when one is supplied) and obtains an access token. Returns either
     * the request headers array or the underlying WP_Error.
     *
     * @param string  $paypal_id  Optional PayPal resource ID to validate.
     * @param ?string $request_id Optional deterministic idempotency key.
     * @param bool    $read_only  True for read-only (GET) calls; omits the
     *                            `PayPal-Request-Id` idempotency header.
     * @return array|\WP_Error
     */
    private function prepare_authorized( string $paypal_id = '', ?string $request_id = null, bool $read_only = false ) {
        if ( '' !== $paypal_id && ! self::is_valid_paypal_id( $paypal_id ) ) {
            return new \WP_Error( 'tejcart_paypal_invalid_id', __( 'Invalid PayPal resource ID.', 'tejcart' ) );
        }
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }
        return $this->get_headers( $access_token, $request_id, $read_only );
    }

    /**
     * Whether the PayPal gateway should emit a log line at the given level.
     *
     * Logging is governed by a single source of truth — the global
     * `tejcart_log_level` setting (Settings → Advanced → Diagnostics).
     * There is intentionally no per-gateway log level; one global control
     * applies to every channel, including PayPal.
     *
     * @param string $level PSR-3 level the call site wants to emit at.
     * @return bool
     */
    private function should_log( string $level ): bool {
        $severity = function_exists( 'tejcart_log_level_severity' )
            ? tejcart_log_level_severity( $level )
            : 0;
        if ( 0 === $severity ) {
            return false;
        }
        if ( function_exists( 'tejcart_log_level_passes' ) && ! tejcart_log_level_passes( $level ) ) {
            return false;
        }
        return true;
    }

    /**
     * Whether the gateway should produce verbose request/response traces.
     * Used by callers that need to short-circuit expensive payload
     * serialisation when no observer will ever read the result.
     */
    private function is_debug(): bool {
        return $this->should_log( 'debug' );
    }

    /**
     * Create a billing plan (subscription template).
     *
     * @param array $plan Plan body matching PayPal /v1/billing/plans schema.
     * @return array|\WP_Error
     */
    public function create_billing_plan( array $plan ) {
        $headers = $this->prepare_authorized();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v1/billing/plans', 'POST', $plan, $headers );
    }

    /**
     * Create a subscription against an existing billing plan.
     *
     * @param array $subscription Subscription body for /v1/billing/subscriptions.
     * @return array|\WP_Error
     */
    public function create_subscription( array $subscription ) {
        $headers = $this->prepare_authorized();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v1/billing/subscriptions', 'POST', $subscription, $headers );
    }

    /**
     * Retrieve a subscription by ID.
     *
     * @param string $subscription_id PayPal subscription ID.
     * @return array|\WP_Error
     */
    public function get_subscription( string $subscription_id ) {
        $headers = $this->prepare_authorized( $subscription_id, null, true );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        return $this->request( '/v1/billing/subscriptions/' . $subscription_id, 'GET', array(), $headers );
    }

    /**
     * Cancel an active subscription.
     *
     * @param string $subscription_id PayPal subscription ID.
     * @param string $reason          Cancellation reason recorded with PayPal.
     * @return true|\WP_Error
     */
    public function cancel_subscription( string $subscription_id, string $reason = 'Cancelled by customer' ) {
        return $this->subscription_action( $subscription_id, 'cancel', array( 'reason' => $reason ) );
    }

    /**
     * Suspend an active subscription.
     *
     * @param string $subscription_id PayPal subscription ID.
     * @param string $reason          Reason recorded with PayPal.
     * @return true|\WP_Error
     */
    public function suspend_subscription( string $subscription_id, string $reason = 'Suspended by customer' ) {
        return $this->subscription_action( $subscription_id, 'suspend', array( 'reason' => $reason ) );
    }

    /**
     * Reactivate a suspended subscription.
     *
     * @param string $subscription_id PayPal subscription ID.
     * @param string $reason          Reason recorded with PayPal.
     * @return true|\WP_Error
     */
    public function activate_subscription( string $subscription_id, string $reason = 'Reactivated by customer' ) {
        return $this->subscription_action( $subscription_id, 'activate', array( 'reason' => $reason ) );
    }

    /**
     * Shared helper for cancel/suspend/activate subscription actions.
     *
     * @param string $subscription_id PayPal subscription ID.
     * @param string $action          Action verb appended to the URL.
     * @param array  $body            Request body.
     * @return true|\WP_Error
     */
    private function subscription_action( string $subscription_id, string $action, array $body ) {
        $headers = $this->prepare_authorized( $subscription_id );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $result = $this->request( '/v1/billing/subscriptions/' . $subscription_id . '/' . $action, 'POST', $body, $headers );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Emit a PayPal log entry at the given PSR-3 level.
     *
     * Callers should gate verbose/info-level entries on `should_log()`
     * (or `is_debug()` for legacy call sites) so request/response
     * payloads are not serialized when nothing will ever consume them.
     * Error-level entries are emitted unconditionally — the global
     * `tejcart_log_level` filter in `tejcart_log()` will discard them
     * if the operator has logging off.
     *
     * @param string $message PayPal log message (will be `[PayPal] `-prefixed).
     * @param string $level   PSR-3 level. Defaults to `info`.
     */
    private function paypal_log( string $message, string $level = 'info' ): void {
        if ( ! function_exists( 'tejcart_log' ) ) {
            return;
        }
        if ( ! $this->should_log( $level ) ) {
            return;
        }
        tejcart_log( '[PayPal] ' . $message, $level, array( 'source' => 'paypal' ) );
    }

    /**
     * Back-compat shim for the historical `debug_log()` call sites.
     *
     * @deprecated Use `paypal_log()` directly with an explicit PSR-3 level.
     */
    private function debug_log( string $message ): void {
        $this->paypal_log( $message, 'debug' );
    }

    /**
     * Recursively redact sensitive fields from a PayPal payload before
     * logging. Removes tokens, card numbers, CVVs, auth headers and any
     * structurally similar keys so debug logs are safe to share.
     *
     * @param mixed $data Raw data (array, scalar).
     * @return mixed
     */
    private static function redact( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }
        $sensitive = self::sensitive_field_names();
        $out = array();
        foreach ( $data as $k => $v ) {
            if ( is_string( $k ) && in_array( strtolower( $k ), $sensitive, true ) ) {
                $out[ $k ] = '***';
                continue;
            }
            $out[ $k ] = is_array( $v ) ? self::redact( $v ) : $v;
        }
        return $out;
    }

    /**
     * Field names whose values redact() replaces with `***` before
     * logging. Defaults cover PayPal tokens, card data, signatures,
     * and credential headers. Operators / extensions can extend the
     * list via the `tejcart_paypal_sensitive_fields` filter when a
     * new PayPal endpoint introduces additional sensitive keys.
     *
     * @return string[] Lowercase field-name keys.
     */
    private static function sensitive_field_names(): array {
        $defaults = array(
            'access_token', 'client_token', 'setup_token', 'payment_token',
            'number', 'cvv', 'cvv2', 'security_code', 'expiry', 'last_digits',
            'authorization', 'signature', 'client_secret', 'private_key',
        );
        $filtered = apply_filters( 'tejcart_paypal_sensitive_fields', $defaults );
        if ( ! is_array( $filtered ) ) {
            return $defaults;
        }
        $normalised = array();
        foreach ( $filtered as $name ) {
            if ( is_string( $name ) && '' !== $name ) {
                $normalised[] = strtolower( $name );
            }
        }
        return array_values( array_unique( $normalised ) );
    }

    /**
     * Translate a PayPal error response into a user-friendly message.
     *
     * @param array $decoded Decoded PayPal error body.
     * @return string
     */
    private static function map_error( array $decoded ): string {
        $issue = $decoded['details'][0]['issue'] ?? '';
        $name  = $decoded['name'] ?? '';

        $map = array(
            'INSTRUMENT_DECLINED'             => __( 'Your payment method was declined. Please try a different card or PayPal account.', 'tejcart' ),
            'PAYER_ACTION_REQUIRED'           => __( 'Additional verification is required by PayPal. Please complete the steps and try again.', 'tejcart' ),
            'PAYER_CANNOT_PAY'                => __( 'PayPal could not process this payment with your current account. Please try a different funding source.', 'tejcart' ),
            'PAYMENT_DENIED'                  => __( 'PayPal denied this payment. Please contact PayPal or try another method.', 'tejcart' ),
            'TRANSACTION_REFUSED'             => __( 'The transaction was refused by PayPal. Please try again later.', 'tejcart' ),
            'CARD_BRAND_NOT_SUPPORTED'        => __( 'This card brand is not supported. Please use a different card.', 'tejcart' ),
            'CARD_EXPIRED'                    => __( 'Your card has expired. Please use a different card.', 'tejcart' ),
            'CARD_TYPE_NOT_SUPPORTED'         => __( 'This card type is not supported. Please use a different card.', 'tejcart' ),
            'COMPLIANCE_VIOLATION'            => __( 'This payment was blocked for compliance reasons. Please contact support.', 'tejcart' ),
            'CURRENCY_NOT_SUPPORTED_FOR_CARD' => __( 'Your card does not support this currency. Please use a different card.', 'tejcart' ),
            'INSUFFICIENT_FUNDS'              => __( 'Your payment method has insufficient funds. Please try a different one.', 'tejcart' ),
            'PAYEE_BLOCKED_TRANSACTION'       => __( 'This merchant cannot accept your payment method right now.', 'tejcart' ),
            'AUTHENTICATION_FAILURE'          => __( 'Card authentication (3-D Secure) failed. Please try again.', 'tejcart' ),
            'PAYMENT_SOURCE_CANNOT_BE_USED'   => __( 'This payment method cannot be used for this order. Please choose another.', 'tejcart' ),
            'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR' => __( 'Your payment method was declined by the processor. Please try a different card or PayPal account.', 'tejcart' ),
            'ORDER_ALREADY_CAPTURED'          => __( 'This order has already been paid. Please refresh the page to see the latest status.', 'tejcart' ),
            'ORDER_NOT_APPROVED'              => __( 'The PayPal order has not been approved yet. Please complete the approval step.', 'tejcart' ),
            'ORDER_COMPLETED_OR_VOIDED'       => __( 'This PayPal order cannot be completed because it has already finished. Please start a new order.', 'tejcart' ),
            'AGREEMENT_ALREADY_CANCELLED'     => __( 'The saved payment agreement is no longer active. Please add a new payment method.', 'tejcart' ),
            'MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED' => __( 'Too many payment attempts. Please wait a few minutes and try again.', 'tejcart' ),
            'BILLING_ADDRESS_INVALID'         => __( 'The billing address is invalid. Please review and correct it.', 'tejcart' ),
            'SHIPPING_ADDRESS_INVALID'        => __( 'The shipping address is invalid. Please review and correct it.', 'tejcart' ),
            'POSTAL_CODE_REQUIRED'            => __( 'A postal code is required for this card. Please update your billing address.', 'tejcart' ),
            'CITY_REQUIRED'                   => __( 'A city is required for this address. Please update your billing address.', 'tejcart' ),
            'COUNTRY_NOT_SUPPORTED'           => __( 'Payments are not supported in the selected country.', 'tejcart' ),
            'DUPLICATE_INVOICE_ID'            => __( 'This order reference has already been used. Please refresh the page and try again.', 'tejcart' ),
            'INTERNAL_SERVER_ERROR'           => __( 'PayPal is experiencing a temporary issue. Please try again in a moment.', 'tejcart' ),
            'RATE_LIMIT_REACHED'              => __( 'Too many requests were made to PayPal. Please slow down and try again.', 'tejcart' ),
        );

        if ( $issue && isset( $map[ $issue ] ) ) {
            return $map[ $issue ];
        }
        if ( $name && isset( $map[ $name ] ) ) {
            return $map[ $name ];
        }

        return isset( $decoded['message'] ) && '' !== $decoded['message']
            ? (string) $decoded['message']
            : __( 'PayPal API request failed.', 'tejcart' );
    }

    /**
     * Get the base API URL based on the current mode.
     *
     * @return string
     */
    private function get_api_url(): string {
        return $this->gateway->is_sandbox() ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /**
     * Build standard request headers including the BN code.
     *
     * The `PayPal-Request-Id` header is PayPal's idempotency key — when
     * the same value is replayed within ~6 hours PayPal returns the
     * original response instead of creating a duplicate resource. By
     * default we send a fresh UUID per call so unrelated requests never
     * collide; callers that need at-least-once safety (renewal charges,
     * subscription switch charges) supply a deterministic key derived
     * from the subscription / renewal-order / amount tuple.
     *
     * @param string  $access_token OAuth access token.
     * @param ?string $request_id   Optional deterministic idempotency key.
     * @param bool    $read_only    True for read-only (GET) calls. The
     *                              `PayPal-Request-Id` header is an
     *                              idempotency key that only protects
     *                              mutating endpoints from being replayed
     *                              into duplicate resources — a GET can't
     *                              create a duplicate, so read-only calls
     *                              omit the header entirely and skip the
     *                              missing-key diagnostic below (see #1250).
     * @return array
     */
    private function get_headers( string $access_token, ?string $request_id = null, bool $read_only = false ): array {
        $headers = array(
            'Authorization'                 => 'Bearer ' . $access_token,
            'Content-Type'                  => 'application/json',
            'Prefer'                        => 'return=representation',
            'PayPal-Partner-Attribution-Id' => PayPal_Gateway::bn_code(),
        );

        // Read-only GET requests neither need nor should advertise an
        // idempotency key: there is nothing to dedupe, and emitting the
        // missing-key error on every read (e.g. get_order_details during
        // express-tax reconciliation) just floods the gateway log.
        if ( $read_only ) {
            return $headers;
        }

        $effective_request_id = (string) $request_id;
        if ( '' === $effective_request_id ) {
            // #1206: when a caller doesn't supply a deterministic key
            // we used to silently substitute wp_generate_uuid4(). That
            // makes the call unusable for at-least-once retries
            // (PayPal can't dedupe against a fresh UUID). Two-phase
            // remediation:
            //   1. Log at error level so missing keys surface in dev /
            //      staging logs before they cause a duplicate-charge
            //      incident in production.
            //   2. When `TEJCART_PAYPAL_REQUEST_ID_STRICT` is true,
            //      throw outright. Default off until #1250 sweeps the
            //      remaining call sites; flip on then.
            if ( defined( 'TEJCART_PAYPAL_REQUEST_ID_STRICT' ) && \TEJCART_PAYPAL_REQUEST_ID_STRICT ) {
                throw new \InvalidArgumentException(
                    'PayPal_API::get_headers() called without a deterministic PayPal-Request-Id. '
                    . 'Every mutating endpoint must supply one — see TejCart\\Gateways\\PayPal\\Idempotency_Key.'
                );
            }
            if ( function_exists( 'tejcart_log' ) ) {
                $trace = function_exists( 'wp_debug_backtrace_summary' )
                    ? wp_debug_backtrace_summary()
                    : '';
                tejcart_log(
                    'PayPal-Request-Id fallback to UUID — caller did not supply a deterministic key. Fix the call site (see #1250).',
                    'error',
                    array( 'trace' => $trace )
                );
            }
            $effective_request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
        }

        $headers['PayPal-Request-Id'] = $effective_request_id;

        return $headers;
    }
}
