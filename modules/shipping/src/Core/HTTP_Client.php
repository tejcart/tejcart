<?php
/**
 * Carrier-API HTTP client.
 *
 * Wraps `wp_remote_request()` with retry-with-jitter and a per-host
 * circuit breaker. Carrier APIs routinely flap (FedEx OAuth, USPS
 * weekend maintenance, DHL rate limits) and a misbehaving carrier
 * must not stall the checkout — the breaker fast-fails subsequent
 * requests for a cool-down window after consecutive failures.
 *
 * Breaker state lives in the WordPress object cache (NOT the options
 * table) so failure storms don't hammer the database. Use a persistent
 * object cache (Redis / Memcached) in production for the breaker to
 * span pageloads and PHP workers.
 *
 * Sensitive request/response bodies are NEVER written to the WP debug
 * log; only status codes, durations, and host are logged.
 *
 * Full request/response traffic logging is opt-in for troubleshooting:
 * set `tejcart_log_level` to `debug` (Settings → Advanced, or
 * `wp option update tejcart_log_level debug`). When that level is in
 * effect, each attempt writes a `http_request` and `http_response`
 * line to the carrier's shipping log file via
 * {@see tejcart_shipping_log()}. Credentials, tokens, and known
 * secret-shaped fields are redacted before logging, and bodies are
 * truncated to 4 KB so one chatty carrier can't fill the disk. Drop
 * the level back to `error` (or `warning`) after troubleshooting — the
 * redaction list is best-effort, not exhaustive.
 *
 * Each request fires the `tejcart_shipping_metric` action with
 * `{driver, host, status, duration_ms, attempt, correlation_id}` so
 * a metrics sink can plot per-carrier latency and success rate.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HTTP_Client {
    // Default per-call HTTP timeout for a carrier rating request.
    // Previously 15s; most carriers respond in ~1s, and the previous
    // value masked real upstream incidents while serialising 4×15s=60s
    // worst-case across a four-carrier checkout. Stores that need a
    // longer timeout (rare — usually a misconfigured corporate VPN
    // routing through a slow proxy) can raise it via the
    // `tejcart_shipping_http_timeout` filter applied in request().
    private const DEFAULT_TIMEOUT      = 7;
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const BREAKER_THRESHOLD    = 5;
    private const BREAKER_COOLDOWN     = 60;
    private const BREAKER_GROUP        = 'tejcart_shipping_breaker';

    /**
     * Soft per-request budget (in seconds) for cumulative time spent
     * waiting on carrier rating APIs across all carriers, all attempts.
     * Tracked via a request-scoped static counter and consulted at the
     * top of every request(); once exceeded the call short-circuits
     * with a WP_Error so the rest of the carrier fan-out falls back
     * to whatever rates are already cached (or the merchant's
     * flat-rate fallback) instead of pinning the PHP-FPM worker for
     * a minute+ on a single slow carrier. Filterable for stores that
     * legitimately need a wider budget; setting to 0 disables.
     */
    private const DEFAULT_REQUEST_BUDGET = 5;

    /**
     * Cumulative seconds spent in carrier HTTP calls within the
     * current PHP request. Reset implicitly per request (statics are
     * per-process and PHP-FPM workers are short-lived).
     *
     * @var float
     */
    private static float $cumulative_seconds = 0.0;

    /**
     * Max bytes of a request/response body that get written to the
     * traffic log when opt-in logging is on. Anything past this is
     * elided with `... (truncated, N bytes total)`.
     */
    private const TRAFFIC_LOG_BODY_LIMIT = 4096;

    /**
     * Headers whose values are unconditionally redacted in the traffic
     * log. Lower-case so the check is case-insensitive.
     */
    private const REDACTED_HEADERS = array(
        'authorization',
        'x-api-key',
        'x-auth-token',
        'apikey',
        'api-key',
        'cookie',
        'set-cookie',
        'proxy-authorization',
    );

    /**
     * Substrings (lower-cased) that mark a JSON / query-string key as
     * carrying a secret. Matched against the field name, not the value,
     * so partials like `password_confirm`, `client_secret`, `api_token`
     * all trip the redactor.
     */
    private const REDACTED_FIELD_HINTS = array(
        'password',
        'secret',
        'token',
        'apikey',
        'api_key',
        'access_key',
        'private_key',
        'authorization',
    );

    /**
     * Perform an HTTP request with retry, jitter, and circuit breaking.
     *
     * @param string              $method  GET|POST|PUT|DELETE.
     * @param string              $url
     * @param array<string,mixed> $options {
     *   @type array<string,string> $headers
     *   @type string|array         $body
     *   @type int                  $timeout
     *   @type int                  $max_attempts
     *   @type string               $driver_id     Driver id for metric tagging.
     *   @type string               $correlation_id Optional correlation id (auto-generated if omitted).
     * }
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     * @throws Carrier_Exception When the URL is malformed or the breaker is open.
     */
    public function request( string $method, string $url, array $options = array() ): array {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            throw new Carrier_Exception( 'HTTP_Client: malformed URL.' );
        }

        if ( $this->breaker_is_open( $host ) ) {
            $this->emit_metric( $options, $host, 0, 0, 0, 'breaker_open' );
            throw new Carrier_Exception( sprintf( 'HTTP_Client: circuit breaker open for %s', esc_html( $host ) ) );
        }

        // Per-request budget guard. Carrier fan-out at checkout is
        // sequential (FedEx + UPS + USPS + DHL each calling request()
        // in turn). Without a budget, one slow carrier could pin the
        // PHP-FPM worker for 60+ seconds, exhausting the pool under
        // load. Short-circuit once cumulative wait exceeds the
        // documented budget so the remaining carriers fall back to
        // cache (or the merchant's flat-rate fallback) and the
        // worker returns to the pool promptly.
        $budget = (int) apply_filters(
            'tejcart_shipping_http_request_budget',
            self::DEFAULT_REQUEST_BUDGET,
            $host
        );
        if ( $budget > 0 && self::$cumulative_seconds >= $budget ) {
            $this->emit_metric( $options, $host, 0, 0, 0, 'budget_exhausted' );
            throw new Carrier_Exception( sprintf(
                'HTTP_Client: per-request budget exhausted before %s (used %.2fs of %ds)',
                esc_html( $host ),
                self::$cumulative_seconds,
                $budget
            ) );
        }

        // Allow merchants on misconfigured networks to opt into a
        // longer per-call timeout via filter without re-introducing
        // the old 15s default for everyone.
        $default_timeout = (int) apply_filters(
            'tejcart_shipping_http_timeout',
            self::DEFAULT_TIMEOUT,
            $host
        );
        $timeout        = (int) ( $options['timeout'] ?? $default_timeout );
        $max_attempts   = max( 1, (int) ( $options['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );
        $correlation_id = (string) ( $options['correlation_id'] ?? $this->generate_correlation_id() );

        $headers = $options['headers'] ?? array();
        if ( ! isset( $headers['X-TejCart-Correlation-Id'] ) ) {
            $headers['X-TejCart-Correlation-Id'] = $correlation_id;
        }

        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => $options['body'] ?? null,
        );

        $attempt    = 0;
        $last_error = '';
        $driver_id  = (string) ( $options['driver_id'] ?? '' );
        $log_traffic = $this->traffic_logging_enabled();

        while ( $attempt < $max_attempts ) {
            ++$attempt;

            if ( $log_traffic ) {
                $this->log_request( $driver_id, $url, $args, $attempt, $correlation_id );
            }

            $started  = $this->now_ms();
            $response = wp_remote_request( $url, $args );
            $elapsed  = $this->now_ms() - $started;

            // Track cumulative wall-time spent in carrier HTTP calls
            // for the budget guard at the top of request(). $elapsed
            // is milliseconds; convert to seconds before adding.
            self::$cumulative_seconds += $elapsed / 1000.0;

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                $this->emit_metric( $options, $host, 0, $elapsed, $attempt, 'wp_error', $correlation_id );
                if ( $log_traffic ) {
                    $this->log_transport_failure( $driver_id, $url, $last_error, $attempt, $elapsed, $correlation_id );
                }
                $this->maybe_sleep_for_retry( $attempt, $max_attempts );
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );

            if ( $log_traffic ) {
                $this->log_response( $driver_id, $url, $response, $status, $attempt, $elapsed, $correlation_id );
            }

            if ( $status >= 500 || 429 === $status ) {
                $last_error = 'HTTP ' . $status;
                $this->emit_metric( $options, $host, $status, $elapsed, $attempt, 'retryable', $correlation_id );
                $this->maybe_sleep_for_retry( $attempt, $max_attempts );
                continue;
            }

            $this->breaker_record_success( $host );
            $this->emit_metric( $options, $host, $status, $elapsed, $attempt, 'ok', $correlation_id );

            return array(
                'status'  => $status,
                'headers' => $this->normalise_headers( wp_remote_retrieve_headers( $response ) ),
                'body'    => (string) wp_remote_retrieve_body( $response ),
            );
        }

        $this->breaker_record_failure( $host );
        $this->emit_metric( $options, $host, 0, 0, $attempt, 'exhausted', $correlation_id );
        throw new Carrier_Exception( sprintf( 'HTTP_Client: %1$s after %2$d attempts (%3$s)', esc_html( $last_error ), (int) $attempt, esc_html( $host ) ) );
    }

    /**
     * Sleep for a jittered exponential backoff between retries.
     * Skips the sleep on the final attempt (the loop exits anyway).
     *
     * Protected so test doubles can override the back-off and keep the
     * suite fast.
     */
    protected function maybe_sleep_for_retry( int $attempt, int $max_attempts ): void {
        if ( $attempt >= $max_attempts ) {
            return;
        }
        $base_us = ( 2 ** ( $attempt - 1 ) ) * 250_000;
        $jitter  = random_int( 0, (int) ( $base_us / 2 ) );
        usleep( $base_us + $jitter );
    }

    /**
     * @param mixed $headers
     * @return array<string,string>
     */
    private function normalise_headers( $headers ): array {
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }
        if ( ! is_array( $headers ) ) {
            return array();
        }
        $out = array();
        foreach ( $headers as $key => $value ) {
            $out[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
        }
        return $out;
    }

    /* ------------------------------------------------------------ */
    /* Circuit breaker                                              */
    /* ------------------------------------------------------------ */

    private function breaker_is_open( string $host ): bool {
        $entry = $this->breaker_load( $host );
        if ( null === $entry ) {
            return false;
        }
        if ( ( $entry['failures'] ?? 0 ) < self::BREAKER_THRESHOLD ) {
            return false;
        }
        $opened_at = (int) ( $entry['opened_at'] ?? 0 );
        return $opened_at > 0 && ( time() - $opened_at ) < self::BREAKER_COOLDOWN;
    }

    private function breaker_record_failure( string $host ): void {
        // Atomic increment via wp_cache_incr when the cache backend supports
        // it; otherwise fall back to read-modify-write. The fallback is racy
        // but the breaker is a soft signal and we accept slight under-count
        // in exchange for not requiring locks across PHP workers.
        $key  = $this->breaker_key( $host );
        $now  = time();
        $next = function_exists( 'wp_cache_incr' )
            ? wp_cache_incr( $key . ':failures', 1, self::BREAKER_GROUP )
            : false;
        if ( false === $next ) {
            wp_cache_set(
                $key . ':failures',
                ( $this->breaker_load_count( $host ) + 1 ),
                self::BREAKER_GROUP,
                self::BREAKER_COOLDOWN * 2
            );
            $next = $this->breaker_load_count( $host );
        }
        if ( (int) $next >= self::BREAKER_THRESHOLD ) {
            wp_cache_add(
                $key . ':opened_at',
                $now,
                self::BREAKER_GROUP,
                self::BREAKER_COOLDOWN * 2
            );
        }
    }

    private function breaker_record_success( string $host ): void {
        $key = $this->breaker_key( $host );
        wp_cache_delete( $key . ':failures', self::BREAKER_GROUP );
        wp_cache_delete( $key . ':opened_at', self::BREAKER_GROUP );
    }

    /**
     * @return array{failures:int,opened_at:int}|null
     */
    private function breaker_load( string $host ): ?array {
        $key       = $this->breaker_key( $host );
        $failures  = wp_cache_get( $key . ':failures', self::BREAKER_GROUP );
        $opened_at = wp_cache_get( $key . ':opened_at', self::BREAKER_GROUP );
        if ( false === $failures && false === $opened_at ) {
            return null;
        }
        return array(
            'failures'  => (int) ( false === $failures ? 0 : $failures ),
            'opened_at' => (int) ( false === $opened_at ? 0 : $opened_at ),
        );
    }

    private function breaker_load_count( string $host ): int {
        $key = $this->breaker_key( $host );
        $val = wp_cache_get( $key . ':failures', self::BREAKER_GROUP );
        return (int) ( false === $val ? 0 : $val );
    }

    private function breaker_key( string $host ): string {
        return 'host:' . $host;
    }

    /* ------------------------------------------------------------ */
    /* Observability                                                */
    /* ------------------------------------------------------------ */

    private function generate_correlation_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return bin2hex( random_bytes( 16 ) );
    }

    private function now_ms(): int {
        return (int) ( microtime( true ) * 1000 );
    }

    /* ------------------------------------------------------------ */
    /* Traffic logging (opt-in)                                     */
    /* ------------------------------------------------------------ */

    /**
     * Resolve whether full request/response logging is currently on.
     *
     * Single source of truth: the existing `tejcart_log_level` option
     * (Settings → Advanced). Traffic logging fires whenever the level
     * is set to `debug` — the most verbose tier — so merchants don't
     * have to learn a separate per-feature toggle to turn it on, and
     * dropping the level back down to `error`/`warning` after
     * troubleshooting unconditionally silences the traffic stream
     * without leaving a forgotten extra switch on.
     */
    private function traffic_logging_enabled(): bool {
        if ( ! function_exists( 'tejcart_shipping_log' ) || ! function_exists( 'tejcart_log_level_passes' ) ) {
            return false;
        }
        return tejcart_log_level_passes( 'debug' );
    }

    /**
     * Write one outgoing-request line to the carrier's shipping log.
     *
     * @param array<string,mixed> $args wp_remote_request arguments.
     */
    private function log_request(
        string $driver_id,
        string $url,
        array $args,
        int $attempt,
        string $correlation_id
    ): void {
        tejcart_shipping_log(
            $this->traffic_log_source( $driver_id ),
            'http_request',
            array(
                'driver_id'      => $driver_id,
                'correlation_id' => $correlation_id,
                'attempt'        => $attempt,
                'method'         => (string) ( $args['method'] ?? 'GET' ),
                'url'            => $this->redact_url( $url ),
                'headers'        => $this->redact_headers( (array) ( $args['headers'] ?? array() ) ),
                'body'           => $this->prepare_body_for_log( $args['body'] ?? null ),
            )
        );
    }

    /**
     * Write one inbound-response line to the carrier's shipping log.
     *
     * @param mixed $response Raw wp_remote_request return value.
     */
    private function log_response(
        string $driver_id,
        string $url,
        $response,
        int $status,
        int $attempt,
        int $duration_ms,
        string $correlation_id
    ): void {
        $headers = $this->normalise_headers( wp_remote_retrieve_headers( $response ) );
        $body    = (string) wp_remote_retrieve_body( $response );

        tejcart_shipping_log(
            $this->traffic_log_source( $driver_id ),
            'http_response',
            array(
                'driver_id'      => $driver_id,
                'correlation_id' => $correlation_id,
                'attempt'        => $attempt,
                'url'            => $this->redact_url( $url ),
                'status'         => $status,
                'duration_ms'    => $duration_ms,
                'headers'        => $this->redact_headers( $headers ),
                'body'           => $this->prepare_body_for_log( $body ),
            )
        );
    }

    /**
     * Write a transport-level failure (wp_error from wp_remote_request)
     * to the carrier's shipping log when traffic logging is on.
     */
    private function log_transport_failure(
        string $driver_id,
        string $url,
        string $error,
        int $attempt,
        int $duration_ms,
        string $correlation_id
    ): void {
        tejcart_shipping_log(
            $this->traffic_log_source( $driver_id ),
            'http_transport_error',
            array(
                'driver_id'      => $driver_id,
                'correlation_id' => $correlation_id,
                'attempt'        => $attempt,
                'url'            => $this->redact_url( $url ),
                'duration_ms'    => $duration_ms,
                'error'          => $error,
            )
        );
    }

    private function traffic_log_source( string $driver_id ): string {
        return '' === $driver_id ? 'shipping_http' : 'shipping_' . $driver_id;
    }

    /**
     * Strip credential-shaped query parameters out of a URL.
     */
    private function redact_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['query'] ) ) {
            return $url;
        }

        parse_str( (string) $parsed['query'], $params );
        if ( ! is_array( $params ) || array() === $params ) {
            return $url;
        }

        $changed = false;
        foreach ( $params as $key => $value ) {
            if ( $this->field_is_secret( (string) $key ) ) {
                $params[ $key ] = '[redacted]';
                $changed         = true;
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
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function redact_headers( array $headers ): array {
        $out = array();
        foreach ( $headers as $key => $value ) {
            $name = strtolower( (string) $key );
            if ( in_array( $name, self::REDACTED_HEADERS, true ) ) {
                $out[ (string) $key ] = '[redacted]';
                continue;
            }
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }
            $out[ (string) $key ] = (string) $value;
        }
        return $out;
    }

    /**
     * Stringify and truncate a request/response body for the log.
     * JSON bodies are first decoded so credential-shaped fields can be
     * redacted recursively; anything else falls through as a string.
     *
     * @param mixed $body
     */
    private function prepare_body_for_log( $body ): string {
        if ( null === $body || '' === $body ) {
            return '';
        }
        if ( is_array( $body ) ) {
            $redacted = $this->redact_array_secrets( $body );
            $encoded  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $redacted ) : json_encode( $redacted );
            return $this->truncate_for_log( is_string( $encoded ) ? $encoded : '' );
        }

        $str = (string) $body;

        $maybe_json = json_decode( $str, true );
        if ( is_array( $maybe_json ) ) {
            $redacted = $this->redact_array_secrets( $maybe_json );
            $encoded  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $redacted ) : json_encode( $redacted );
            return $this->truncate_for_log( is_string( $encoded ) ? $encoded : $str );
        }

        // application/x-www-form-urlencoded
        if ( false !== strpos( $str, '=' ) && false === strpos( $str, '<' ) ) {
            parse_str( $str, $params );
            if ( is_array( $params ) && array() !== $params ) {
                $changed = false;
                foreach ( $params as $key => $value ) {
                    if ( $this->field_is_secret( (string) $key ) ) {
                        $params[ $key ] = '[redacted]';
                        $changed         = true;
                    }
                }
                if ( $changed ) {
                    return $this->truncate_for_log( http_build_query( $params ) );
                }
            }
        }

        return $this->truncate_for_log( $str );
    }

    /**
     * Recursively replace values whose key looks credential-shaped with
     * `[redacted]`. Keeps the rest of the structure intact so the merchant
     * can still see request shape / response payload.
     *
     * @param array<mixed,mixed> $data
     * @return array<mixed,mixed>
     */
    private function redact_array_secrets( array $data ): array {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $data[ $key ] = $this->redact_array_secrets( $value );
                continue;
            }
            if ( $this->field_is_secret( (string) $key ) ) {
                $data[ $key ] = '[redacted]';
            }
        }
        return $data;
    }

    private function field_is_secret( string $field ): bool {
        $needle = strtolower( $field );
        foreach ( self::REDACTED_FIELD_HINTS as $hint ) {
            if ( false !== strpos( $needle, $hint ) ) {
                return true;
            }
        }
        return false;
    }

    private function truncate_for_log( string $body ): string {
        $len = strlen( $body );
        if ( $len <= self::TRAFFIC_LOG_BODY_LIMIT ) {
            return $body;
        }
        return substr( $body, 0, self::TRAFFIC_LOG_BODY_LIMIT )
            . sprintf( '... (truncated, %d bytes total)', $len );
    }

    /**
     * @param array<string,mixed> $options
     */
    private function emit_metric(
        array $options,
        string $host,
        int $status,
        int $duration_ms,
        int $attempt,
        string $outcome,
        string $correlation_id = ''
    ): void {
        if ( ! function_exists( 'do_action' ) ) {
            return;
        }
        /**
         * Fires once per HTTP attempt, with sanitised carrier-call telemetry.
         *
         * Subscribe a metrics sink (StatsD, Datadog, Prometheus push-gateway)
         * to plot success rate and p95 latency per (driver, host).
         *
         * @param array{driver:string,host:string,status:int,duration_ms:int,attempt:int,outcome:string,correlation_id:string} $metric
         */
        do_action( 'tejcart_shipping_metric', array(
            'driver'         => (string) ( $options['driver_id'] ?? '' ),
            'host'           => $host,
            'status'         => $status,
            'duration_ms'    => $duration_ms,
            'attempt'        => $attempt,
            'outcome'        => $outcome,
            'correlation_id' => $correlation_id,
        ) );
    }
}
