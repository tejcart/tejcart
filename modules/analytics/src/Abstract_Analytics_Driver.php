<?php
/**
 * Base class for analytics / marketing-automation drivers.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common scaffolding for the GA4 / Meta CAPI / Klaviyo / Mailchimp drivers
 * coordinated by {@see Analytics_Dispatcher}.
 *
 * Concrete drivers override the four event hooks
 * ({@see send_purchase()}, {@see send_refund()}, {@see send_customer_created()},
 * {@see send_cart_event()}) and the credential schema in
 * {@see static::credential_keys()}. The base class handles persistence
 * (including AES-256-GCM encryption of secrets) and the soft-fail
 * logging contract that keeps a flaky third-party from poisoning
 * checkout requests.
 *
 * Important: drivers MUST NOT throw out of the public send_* methods —
 * instead they should return false and log via {@see tejcart_log()}.
 * The dispatcher runs them inside background Action Scheduler jobs so
 * an outage at one provider never blocks order completion.
 */
abstract class Abstract_Analytics_Driver {
    /**
     * Stable driver ID (e.g. "ga4", "meta_capi"). Override in subclasses.
     *
     * @var string
     */
    protected string $id = '';

    /**
     * Display title for admin UI.
     *
     * @var string
     */
    protected string $title = '';

    /**
     * WordPress option key holding this driver's settings.
     *
     * @var string
     */
    protected string $option_key = '';

    /**
     * Credential fields surfaced in the admin UI. Override per driver.
     *
     * Defined as a method (not a const) so the human-readable `label`,
     * `description` and select-option strings can be wrapped in __() for
     * translation — const expressions cannot call __().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function credential_keys(): array {
        return array();
    }

    /**
     * IDs from {@see static::credential_keys()} that are sensitive.
     *
     * @var string[]
     */
    public const SECRET_KEYS = array();

    public function get_id(): string {
        return $this->id;
    }

    public function get_title(): string {
        return $this->title;
    }

    public function get_option_key(): string {
        return $this->option_key;
    }

    /**
     * Read decoded settings (secrets decrypted).
     *
     * @return array<string, mixed>
     */
    public function get_settings(): array {
        $stored = get_option( $this->option_key, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        foreach ( static::SECRET_KEYS as $secret_key ) {
            if ( isset( $stored[ $secret_key ] ) && is_string( $stored[ $secret_key ] ) && '' !== $stored[ $secret_key ] ) {
                $stored[ $secret_key ] = Crypto::decrypt( $stored[ $secret_key ] );
            }
        }
        return $stored;
    }

    /**
     * Persist settings, encrypting secrets at rest.
     *
     * @param array<string, mixed> $settings
     * @return bool
     */
    public function save_settings( array $settings ): bool {
        foreach ( static::SECRET_KEYS as $secret_key ) {
            if ( isset( $settings[ $secret_key ] ) && is_string( $settings[ $secret_key ] ) && '' !== $settings[ $secret_key ] ) {
                $settings[ $secret_key ] = Crypto::encrypt( $settings[ $secret_key ] );
            }
        }
        // autoload=false: these options hold encrypted third-party secrets
        // and are read only in admin/worker context, so keep them out of the
        // always-loaded alloptions cache.
        return (bool) update_option( $this->option_key, $settings, false );
    }

    /**
     * Read a single setting (decrypted for secrets).
     *
     * @param string $key     Setting ID.
     * @param mixed  $default Default value when unset.
     * @return mixed
     */
    public function get_setting( string $key, $default = '' ) {
        $settings = $this->get_settings();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Whether the driver is enabled in settings.
     */
    public function is_enabled(): bool {
        return 'yes' === $this->get_setting( 'enabled', 'no' );
    }

    /**
     * Whether all required credentials are populated.
     */
    public function is_configured(): bool {
        $settings = $this->get_settings();
        foreach ( static::credential_keys() as $field ) {
            if ( empty( $field['required'] ) ) {
                continue;
            }
            $value = $settings[ $field['id'] ?? '' ] ?? '';
            if ( '' === (string) $value ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the driver should receive events right now.
     */
    public function is_active(): bool {
        return $this->is_enabled() && $this->is_configured();
    }

    /**
     * Sanitise the HTTP timeout for outbound calls. Bound between 5 s and
     * 30 s so a single misconfigured endpoint can't stall a queue worker.
     */
    protected function http_timeout(): int {
        $timeout = (int) apply_filters( 'tejcart_analytics_http_timeout', 10, $this->get_id() );
        return min( 30, max( 5, $timeout ) );
    }

    /**
     * Standardised soft-fail logger so every driver writes the same shape
     * of log line. The dispatcher swallows exceptions but a logged warning
     * is the operator's only signal something is wrong.
     */
    protected function log_failure( string $context, string $message ): void {
        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( '[analytics:%s] %s — %s', $this->get_id(), $context, $message ),
                'warning'
            );
        }
    }

    /**
     * Wrap `wp_remote_post()` with gated debug logging.
     *
     * When `tejcart_log_level` is set to `debug` (Settings → Advanced),
     * each driver request emits a `[analytics:<driver>] http_request`
     * line before the call and a `[analytics:<driver>] http_response`
     * line after — both via {@see tejcart_log()}, gated by
     * {@see tejcart_log_level_passes()}. Only metadata is logged
     * (method, redacted URL, HTTP status, duration, attempt context);
     * request and response bodies are NEVER written to the log so the
     * PII the driver ships to GA4 / Meta / Klaviyo / Mailchimp does
     * not leak into the merchant's filesystem.
     *
     * @param string              $url     Target URL.
     * @param array<string,mixed> $args    `wp_remote_post()` arguments.
     * @param string              $context Short event label for the log line.
     * @return array|\WP_Error              Raw `wp_remote_post()` return.
     */
    protected function remote_post( string $url, array $args, string $context = '' ) {
        $debug = $this->debug_logging_enabled();
        if ( $debug ) {
            $this->log_http_request( 'POST', $url, $context );
        }
        $started  = microtime( true );
        $response = wp_remote_post( $url, $args );
        if ( $debug ) {
            $this->log_http_outcome( $url, $context, $response, (int) round( ( microtime( true ) - $started ) * 1000 ) );
        }
        return $response;
    }

    /**
     * Same as {@see remote_post()} but routes through `wp_remote_request()`
     * so callers that need a non-POST verb (Mailchimp's PUT upserts, for
     * example) still benefit from gated debug logging.
     *
     * @param array<string,mixed> $args `wp_remote_request()` arguments,
     *                                  including the `method` key.
     */
    protected function remote_request( string $url, array $args, string $context = '' ) {
        $debug  = $this->debug_logging_enabled();
        $method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
        if ( $debug ) {
            $this->log_http_request( $method, $url, $context );
        }
        $started  = microtime( true );
        $response = wp_remote_request( $url, $args );
        if ( $debug ) {
            $this->log_http_outcome( $url, $context, $response, (int) round( ( microtime( true ) - $started ) * 1000 ) );
        }
        return $response;
    }

    private function log_http_request( string $method, string $url, string $context ): void {
        tejcart_log(
            sprintf( '[analytics:%s] http_request', $this->get_id() ),
            'debug',
            array(
                'source'  => 'analytics_' . $this->get_id(),
                'method'  => $method,
                'url'     => $this->redact_url_for_log( $url ),
                'context' => $context,
            )
        );
    }

    /**
     * @param array|\WP_Error $response
     */
    private function log_http_outcome( string $url, string $context, $response, int $duration_ms ): void {
        if ( is_wp_error( $response ) ) {
            tejcart_log(
                sprintf( '[analytics:%s] http_transport_error', $this->get_id() ),
                'debug',
                array(
                    'source'      => 'analytics_' . $this->get_id(),
                    'url'         => $this->redact_url_for_log( $url ),
                    'context'     => $context,
                    'duration_ms' => $duration_ms,
                    'error'       => $response->get_error_message(),
                )
            );
            return;
        }
        tejcart_log(
            sprintf( '[analytics:%s] http_response', $this->get_id() ),
            'debug',
            array(
                'source'      => 'analytics_' . $this->get_id(),
                'url'         => $this->redact_url_for_log( $url ),
                'context'     => $context,
                'status'      => (int) wp_remote_retrieve_response_code( $response ),
                'duration_ms' => $duration_ms,
            )
        );
    }

    /**
     * Whether the configured `tejcart_log_level` is verbose enough to
     * emit per-request debug lines. Single source of truth so merchants
     * don't have to learn a per-driver toggle.
     */
    protected function debug_logging_enabled(): bool {
        return function_exists( 'tejcart_log' )
            && function_exists( 'tejcart_log_level_passes' )
            && tejcart_log_level_passes( 'debug' );
    }

    /**
     * Strip credential-shaped query parameters from a URL before logging
     * (Meta CAPI's access_token query param, GA4's api_secret, etc.).
     */
    protected function redact_url_for_log( string $url ): string {
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['query'] ) ) {
            return $url;
        }
        parse_str( (string) $parsed['query'], $params );
        if ( ! is_array( $params ) || array() === $params ) {
            return $url;
        }
        $hints   = array( 'password', 'secret', 'token', 'apikey', 'api_key', 'access_key' );
        $changed = false;
        foreach ( $params as $key => $value ) {
            $needle = strtolower( (string) $key );
            foreach ( $hints as $hint ) {
                if ( false !== strpos( $needle, $hint ) ) {
                    $params[ $key ] = '[redacted]';
                    $changed        = true;
                    break;
                }
            }
        }
        if ( ! $changed ) {
            return $url;
        }
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';
        $port   = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
        $path   = $parsed['path']   ?? '';
        $frag   = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
        return $scheme . '://' . $host . $port . $path . '?' . http_build_query( $params ) . $frag;
    }

    /**
     * Inspect a wp_remote_* response and translate a non-2xx into a
     * logged failure. Returns true on success.
     *
     * @param mixed  $response wp_remote_* response.
     * @param string $context  Short label for the log line.
     */
    protected function check_response( $response, string $context ): bool {
        if ( is_wp_error( $response ) ) {
            // wp_remote_* failures (DNS, TCP, TLS, timeout) are transient
            // by definition — surface them as retryable.
            $this->log_failure( $context, $response->get_error_message() );
            throw new Transient_Driver_Exception(
                sprintf( '[%s] transport error: %s', $context, $response->get_error_message() )
            );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = (string) wp_remote_retrieve_body( $response );
            $this->log_failure( $context, sprintf( 'HTTP %d %s', $code, substr( $body, 0, 200 ) ) );

            // Audit #20 / 07 F-5 — 429 (rate-limited) and 5xx are
            // transient and the event SHOULD be retried; the dispatcher
            // re-throws to fail the Action Scheduler job, which
            // retries with backoff. Other 4xx (validation, auth) are
            // terminal — return false so the caller can mark the
            // delivery dropped without retrying forever.
            if ( 429 === $code || $code >= 500 ) {
                throw new Transient_Driver_Exception(
                    sprintf( '[%s] HTTP %d (transient): %s', $context, $code, substr( $body, 0, 200 ) )
                );
            }
            return false;
        }
        return true;
    }

    /**
     * Hash a piece of PII (email, phone, name) the way most analytics
     * vendors expect — lowercased, trimmed, SHA-256 hex. Used by drivers
     * that require server-side identity matching (Meta CAPI is the
     * primary consumer; GA4 client_id / user_id can also be hashed).
     */
    protected function hash_pii( string $value ): string {
        $normalised = strtolower( trim( $value ) );
        if ( '' === $normalised ) {
            return '';
        }
        return hash( 'sha256', $normalised );
    }

    /**
     * Fired when an order reaches a "purchased" status (processing or
     * completed). Drivers should treat this as the canonical conversion
     * event. Implementations return true on success, false on soft-fail.
     */
    public function send_purchase( array $payload ): bool {
        return true;
    }

    /**
     * Fired when an order is refunded.
     */
    public function send_refund( array $payload ): bool {
        return true;
    }

    /**
     * Fired when a customer record is created.
     */
    public function send_customer_created( array $payload ): bool {
        return true;
    }

    /**
     * Fired when a cart event happens (add_to_cart, begin_checkout, …).
     * Drivers that don't track cart funnel events can ignore this.
     */
    public function send_cart_event( string $event, array $payload ): bool {
        return true;
    }
}
