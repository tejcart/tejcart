<?php
/**
 * HTTP client for the Nexa Plugins exchange-rate API.
 *
 * @package TejCart\Currency_Switcher\API
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calls `nexaplugins.com/api/v1/exchange-rate.php` for one base→target
 * pair and validates the response shape before returning the rate.
 *
 * Validation rules (must all hold for `success=true`):
 *   1. HTTP 200
 *   2. Non-empty JSON body
 *   3. Keys based_currency, target_currency, exchange_rate present
 *   4. Returned based_currency matches the request (case-insensitive)
 *   5. Returned target_currency matches the request (case-insensitive)
 *   6. exchange_rate is numeric
 *
 * On any failure we return a structured result with the existing
 * stored rate preserved — never overwriting a known-good value with
 * garbage.
 *
 * Transient HTTP failures (network errors, 5xx, gateway timeouts) are
 * retried with jittered exponential backoff before being surfaced.
 * Validation errors (schema, currency mismatch) are NOT retried —
 * they are deterministic and re-sending would just waste quota.
 */
final class Exchange_Rate_Client {
    /**
     * Default FX-rate endpoint. Held as the public ENDPOINT constant
     * for backward compatibility — sites that referenced it directly
     * continue to compile. New code MUST go through `get_endpoint()`
     * so the per-site override + `tejcart_csw_exchange_rate_endpoint`
     * filter both apply.
     */
    public const ENDPOINT = 'https://nexaplugins.com/api/v1/exchange-rate.php';

    /** Option name for the merchant-configurable FX endpoint override. */
    public const ENDPOINT_OPTION = 'tejcart_csw_exchange_rate_endpoint';

    public const TIMEOUT = 10;

    /** Default retry policy — overridable per request via the filter. */
    private const DEFAULT_MAX_ATTEMPTS  = 3;
    private const DEFAULT_BACKOFF_BASE  = 250;   // milliseconds
    private const DEFAULT_BACKOFF_CAP   = 2000;  // milliseconds

    /**
     * Fetch one rate.
     *
     * @return array{success: bool, rate: float|null, error: string|null, http_status: int|null, attempts?: int}
     */
    public function fetch( string $base, string $target ): array {
        $base   = strtoupper( $base );
        $target = strtoupper( $target );

        if ( ! preg_match( '/^[A-Z]{3}$/', $base ) || ! preg_match( '/^[A-Z]{3}$/', $target ) ) {
            return self::failure( 'invalid_currency_code' );
        }

        $url = add_query_arg(
            array(
                'based_currency'  => $base,
                'target_currency' => $target,
            ),
            self::get_endpoint()
        );

        /**
         * Filter the request args before the HTTP call. Lets advanced
         * sites swap timeout / sslverify / inject a custom user-agent.
         *
         * @param array<string, mixed> $args
         */
        // SEC-021: route through the shared TejCart HTTP defaults helper
        // so timeout / redirect / User-Agent are uniform across every
        // outbound call. The per-call timeout override here matches the
        // module's existing TIMEOUT constant.
        $base_args = function_exists( 'tejcart_external_http_args' )
            ? tejcart_external_http_args( 'currency-switcher', array( 'timeout' => self::TIMEOUT ) )
            : array( 'timeout' => self::TIMEOUT, 'sslverify' => true );
        $args = (array) apply_filters(
            'tejcart_csw_exchange_rate_request_args',
            $base_args
        );

        /**
         * Filter the retry policy. Return an array shaped like:
         *   [ 'max_attempts' => 3, 'backoff_base_ms' => 250, 'backoff_cap_ms' => 2000 ]
         * Pass `max_attempts => 1` to disable retries entirely.
         */
        $policy = (array) apply_filters(
            'tejcart_csw_exchange_rate_retry_policy',
            array(
                'max_attempts'    => self::DEFAULT_MAX_ATTEMPTS,
                'backoff_base_ms' => self::DEFAULT_BACKOFF_BASE,
                'backoff_cap_ms'  => self::DEFAULT_BACKOFF_CAP,
            )
        );

        $max_attempts    = max( 1, (int) ( $policy['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );
        $backoff_base_ms = max( 0, (int) ( $policy['backoff_base_ms'] ?? self::DEFAULT_BACKOFF_BASE ) );
        $backoff_cap_ms  = max( 0, (int) ( $policy['backoff_cap_ms'] ?? self::DEFAULT_BACKOFF_CAP ) );

        $last = self::failure( 'no_attempt' );
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $last = $this->fetch_once( $url, $args, $base, $target );
            $last['attempts'] = $attempt;

            // Success → stop.
            if ( $last['success'] ) {
                return $last;
            }
            // Validation / deterministic failure → no point retrying.
            if ( ! self::is_retryable( $last ) ) {
                return $last;
            }
            // Out of attempts.
            if ( $attempt >= $max_attempts ) {
                return $last;
            }

            // Jittered exponential backoff: 2^(n-1) * base, capped.
            $delay_ms = (int) min( $backoff_cap_ms, $backoff_base_ms * ( 1 << ( $attempt - 1 ) ) );
            if ( $delay_ms > 0 ) {
                $jitter = (int) random_int( 0, max( 1, (int) ( $delay_ms / 2 ) ) );
                usleep( ( $delay_ms + $jitter ) * 1000 );
            }
        }

        return $last;
    }

    /**
     * Resolve the FX-rate endpoint URL.
     *
     * Resolution order (first valid wins):
     *   1. `tejcart_csw_exchange_rate_endpoint` filter return value.
     *   2. `tejcart_csw_exchange_rate_endpoint` option (admin-set).
     *   3. `self::ENDPOINT` default constant.
     *
     * The candidate must be a non-empty https URL with a host part —
     * anything else is rejected and the next candidate is tried.
     * Prevents a merchant-controlled option from pointing at
     * `http://attacker.com` (downgrade attack) or a `javascript:` URL.
     *
     * Audit C-7: previously the endpoint was hardcoded with no
     * override path. A vendor outage broke FX refresh across every
     * install; a vendor compromise would let a single party
     * manipulate exchange rates on every store running the module.
     */
    public static function get_endpoint(): string {
        $default = self::ENDPOINT;

        $filtered = (string) apply_filters( 'tejcart_csw_exchange_rate_endpoint', '' );
        if ( '' !== $filtered && self::is_valid_https_url( $filtered ) ) {
            return $filtered;
        }

        $option = (string) get_option( self::ENDPOINT_OPTION, '' );
        if ( '' !== $option && self::is_valid_https_url( $option ) ) {
            return $option;
        }

        return $default;
    }

    /**
     * Strict https-URL validator for the endpoint resolver.
     */
    private static function is_valid_https_url( string $url ): bool {
        $url = trim( $url );
        if ( '' === $url ) {
            return false;
        }
        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        return 'https' === strtolower( (string) $parts['scheme'] );
    }

    /**
     * Single network attempt + validation pass.
     *
     * @param array<string, mixed> $args
     * @return array{success: bool, rate: float|null, error: string|null, http_status: int|null}
     */
    private function fetch_once( string $url, array $args, string $base, string $target ): array {
        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return self::failure( $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return self::failure( "HTTP {$status}", $status );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return self::failure( 'empty_body', $status );
        }

        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return self::failure( 'invalid_json', $status );
        }

        foreach ( array( 'based_currency', 'target_currency', 'exchange_rate' ) as $key ) {
            if ( ! array_key_exists( $key, $payload ) ) {
                return self::failure( "missing_key:{$key}", $status );
            }
        }

        if ( strtoupper( (string) $payload['based_currency'] ) !== $base ) {
            return self::failure( 'based_currency_mismatch', $status );
        }
        if ( strtoupper( (string) $payload['target_currency'] ) !== $target ) {
            return self::failure( 'target_currency_mismatch', $status );
        }
        if ( ! is_numeric( $payload['exchange_rate'] ) ) {
            return self::failure( 'rate_not_numeric', $status );
        }

        return array(
            'success'     => true,
            'rate'        => (float) $payload['exchange_rate'],
            'error'       => null,
            'http_status' => $status,
        );
    }

    /**
     * Whether a failed result should be retried.
     *
     * - Network errors (no status) → retry.
     * - 5xx / 408 / 429 → retry.
     * - Empty body / invalid JSON → retry (often a gateway hiccup).
     * - 4xx other than 408/429 → don't retry (request shape problem).
     * - Schema / currency-mismatch errors → don't retry (deterministic).
     *
     * @param array{success: bool, rate: float|null, error: string|null, http_status: int|null} $result
     */
    private static function is_retryable( array $result ): bool {
        $deterministic = array(
            'invalid_currency_code',
            'based_currency_mismatch',
            'target_currency_mismatch',
            'rate_not_numeric',
        );
        if ( in_array( (string) $result['error'], $deterministic, true ) ) {
            return false;
        }
        if ( 0 === strpos( (string) $result['error'], 'missing_key:' ) ) {
            return false;
        }

        $status = $result['http_status'];
        if ( null === $status ) {
            return true; // network-level error
        }
        if ( $status >= 500 || 408 === $status || 429 === $status ) {
            return true;
        }
        if ( 200 === $status ) {
            // 200 OK but empty body / invalid JSON → upstream blip.
            return in_array( (string) $result['error'], array( 'empty_body', 'invalid_json' ), true );
        }
        return false;
    }

    /**
     * @return array{success: bool, rate: float|null, error: string|null, http_status: int|null}
     */
    private static function failure( string $error, ?int $status = null ): array {
        return array(
            'success'     => false,
            'rate'        => null,
            'error'       => $error,
            'http_status' => $status,
        );
    }
}
