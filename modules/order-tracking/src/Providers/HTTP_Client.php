<?php
/**
 * HTTP client wrapper for outbound carrier API calls.
 *
 * Wraps `wp_remote_request` with:
 *  - Sensible timeouts (30s connect+read, suitable for synchronous
 *    polling jobs but not so long they block the queue indefinitely).
 *  - Exponential backoff with jitter on transient failures (network
 *    error, HTTP 5xx, 429). Retries 4xx other than 429 are NOT
 *    retried — they're terminal client errors.
 *  - JSON request/response convenience.
 *  - Optional response logging via the
 *    `tejcart_order_tracking_http_response` action (audit / debug).
 *
 * Why not `Requests` / `Guzzle`? B2 of the project rules: no Composer
 * runtime deps. wp_remote_request covers the surface we need.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HTTP_Client {
    public const MAX_ATTEMPTS = 3;
    public const BASE_DELAY_MS = 250;
    // Default 10s — keeps a stalled provider from blocking Action
    // Scheduler ticks for minutes at a time on stores with thousands
    // of in-flight shipments. Override per-call via the `timeout` arg.
    public const TIMEOUT       = 10;
    // Cap a Retry-After-driven sleep so a misbehaving server can't
    // wedge a worker for hours by returning `Retry-After: 86400`.
    public const RETRY_AFTER_MAX_SECONDS = 30;

    /**
     * @param array<string, mixed> $args wp_remote_request args (method, headers, body, etc.).
     * @return array{status:int,body:string,headers:array<string,string>}|\WP_Error
     */
    public function request( string $url, array $args = array() ): array|\WP_Error {
        $args = array_merge(
            array(
                'method'      => 'GET',
                'timeout'     => self::TIMEOUT,
                'redirection' => 3,
                'user-agent'  => 'TejCart-Order-Tracking/' . ( defined( 'TEJCART_ORDER_TRACKING_VERSION' ) ? TEJCART_ORDER_TRACKING_VERSION : '1.x' ),
            ),
            $args
        );

        $attempt   = 0;
        $last      = null;
        $log_debug = $this->debug_logging_enabled();

        while ( $attempt < self::MAX_ATTEMPTS ) {
            ++$attempt;

            if ( $log_debug ) {
                $this->log_request( $url, $args, $attempt );
            }

            $started  = microtime( true );
            $response = wp_remote_request( $url, $args );
            $duration = (int) round( ( microtime( true ) - $started ) * 1000 );

            if ( is_wp_error( $response ) ) {
                if ( $log_debug ) {
                    $this->log_transport_failure( $url, $response->get_error_message(), $attempt, $duration );
                }
                $last = $response;
                $this->sleep_with_backoff( $attempt );
                continue;
            }

            $code    = (int) wp_remote_retrieve_response_code( $response );
            $body    = (string) wp_remote_retrieve_body( $response );
            $headers = $this->normalise_headers( wp_remote_retrieve_headers( $response ) );

            $result = array( 'status' => $code, 'body' => $body, 'headers' => $headers );

            if ( $log_debug ) {
                $this->log_response( $url, $code, $attempt, $duration );
            }

            /**
             * Fires on every completed HTTP attempt (success or
             * retryable failure). Use for audit logging.
             *
             * @param string                                              $url
             * @param int                                                 $attempt
             * @param array{status:int,body:string,headers:array<string,string>} $result
             */
            do_action( 'tejcart_order_tracking_http_response', $url, $attempt, $result );

            // Retry on transient failures only.
            if ( $code >= 500 || 429 === $code ) {
                $last = new \WP_Error(
                    'http_transient',
                    sprintf( 'Transient HTTP %d', $code ),
                    $result
                );
                // Prefer the server's Retry-After hint when present
                // (429 + 503 both commonly use it). Falls back to
                // exponential backoff with jitter otherwise.
                $retry_after = $this->parse_retry_after( $headers['retry-after'] ?? '' );
                if ( null !== $retry_after ) {
                    $this->sleep_for_seconds( $retry_after );
                } else {
                    $this->sleep_with_backoff( $attempt );
                }
                continue;
            }

            return $result;
        }

        return $last instanceof \WP_Error
            ? $last
            : new \WP_Error( 'http_failed', 'HTTP request failed after retries' );
    }

    /**
     * @param array<string, mixed>   $payload
     * @param array<string, string>  $headers
     * @return array{status:int,body:string,headers:array<string,string>}|\WP_Error
     */
    public function post_json( string $url, array $payload, array $headers = array() ): array|\WP_Error {
        $headers = array_merge(
            array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
            $headers
        );
        return $this->request( $url, array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
        ) );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}|\WP_Error
     */
    public function get_json( string $url, array $headers = array() ): array|\WP_Error {
        $headers = array_merge( array( 'Accept' => 'application/json' ), $headers );
        return $this->request( $url, array( 'method' => 'GET', 'headers' => $headers ) );
    }

    /**
     * Sleep an exponentially-backing-off interval with full jitter.
     * Skipped in tests by setting TEJCART_ORDER_TRACKING_NO_SLEEP=true.
     */
    private function sleep_with_backoff( int $attempt ): void {
        if ( defined( 'TEJCART_ORDER_TRACKING_NO_SLEEP' ) && TEJCART_ORDER_TRACKING_NO_SLEEP ) {
            return;
        }
        $base_ms = self::BASE_DELAY_MS * ( 1 << ( $attempt - 1 ) );
        $delay   = (int) wp_rand( 0, $base_ms );
        usleep( max( 0, $delay ) * 1000 );
    }

    private function sleep_for_seconds( int $seconds ): void {
        if ( defined( 'TEJCART_ORDER_TRACKING_NO_SLEEP' ) && TEJCART_ORDER_TRACKING_NO_SLEEP ) {
            return;
        }
        if ( $seconds <= 0 ) {
            return;
        }
        sleep( min( $seconds, self::RETRY_AFTER_MAX_SECONDS ) );
    }

    /**
     * Parse a Retry-After header into seconds.
     *
     * RFC 7231 allows two forms: an integer (delta-seconds) or an
     * HTTP-date. Returns null when the value is empty, malformed, or
     * resolves to a non-positive delay.
     */
    private function parse_retry_after( string $value ): ?int {
        $value = trim( $value );
        if ( '' === $value ) {
            return null;
        }
        if ( ctype_digit( $value ) ) {
            $seconds = (int) $value;
            return $seconds > 0 ? $seconds : null;
        }
        $when = strtotime( $value );
        if ( false === $when ) {
            return null;
        }
        $delta = $when - time();
        return $delta > 0 ? $delta : null;
    }

    /**
     * Whether the configured `tejcart_log_level` is `debug` (or more verbose).
     * When true, each carrier request emits a request/response debug line
     * via {@see tejcart_log()}. Drop the level back to `error` to silence
     * the stream after troubleshooting.
     */
    private function debug_logging_enabled(): bool {
        return function_exists( 'tejcart_log' )
            && function_exists( 'tejcart_log_level_passes' )
            && tejcart_log_level_passes( 'debug' );
    }

    private function log_request( string $url, array $args, int $attempt ): void {
        tejcart_log(
            'Order tracking HTTP request',
            'debug',
            array(
                'source'  => 'order_tracking_http',
                'method'  => (string) ( $args['method'] ?? 'GET' ),
                'url'     => $this->redact_url( $url ),
                'attempt' => $attempt,
            )
        );
    }

    private function log_response( string $url, int $status, int $attempt, int $duration_ms ): void {
        tejcart_log(
            'Order tracking HTTP response',
            'debug',
            array(
                'source'      => 'order_tracking_http',
                'url'         => $this->redact_url( $url ),
                'status'      => $status,
                'attempt'     => $attempt,
                'duration_ms' => $duration_ms,
            )
        );
    }

    private function log_transport_failure( string $url, string $error, int $attempt, int $duration_ms ): void {
        tejcart_log(
            'Order tracking HTTP transport error',
            'debug',
            array(
                'source'      => 'order_tracking_http',
                'url'         => $this->redact_url( $url ),
                'attempt'     => $attempt,
                'duration_ms' => $duration_ms,
                'error'       => $error,
            )
        );
    }

    /**
     * Strip credential-shaped query parameters out of a URL before it
     * lands in the debug log (api_key=, token=, secret=, ...).
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
     * Normalise wp_remote_retrieve_headers() output (which may be
     * \Requests_Utility_CaseInsensitiveDictionary or array) to a
     * lowercase-keyed array<string,string>.
     *
     * @param mixed $headers
     * @return array<string, string>
     */
    private function normalise_headers( mixed $headers ): array {
        $out = array();
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }
        if ( ! is_iterable( $headers ) ) {
            return $out;
        }
        foreach ( $headers as $name => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            $out[ strtolower( (string) $name ) ] = (string) $value;
        }
        return $out;
    }
}
