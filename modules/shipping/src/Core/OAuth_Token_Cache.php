<?php
/**
 * Shared OAuth 2.0 client-credentials token cache.
 *
 * USPS, UPS, and FedEx all use the same `grant_type=client_credentials`
 * flow against their respective auth endpoints. This helper handles
 * fetch + object-cache persistence so each driver doesn't re-implement
 * the token machinery (and so we don't pay the auth round-trip on every
 * checkout pageview).
 *
 * Tokens are cached through Persistent_Cache: the object cache when a
 * persistent backend is present, otherwise a short-lived transient so the
 * token survives across requests (a checkout render and its follow-up
 * AJAX recalc are separate requests) instead of forcing a fresh auth
 * round-trip each time. The transient fallback honours the
 * `tejcart_shipping_persistent_cache` filter for sites that prefer to
 * keep derived tokens out of the database. The fetch is wrapped in a
 * single-flight lock so a checkout-page burst doesn't fan out N
 * simultaneous token requests to the carrier auth endpoint.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OAuth_Token_Cache {
    public const GROUP = 'tejcart_shipping_oauth';

    private HTTP_Client $http;
    private Single_Flight $flight;
    private Persistent_Cache $store;

    public function __construct( HTTP_Client $http, ?Single_Flight $flight = null, ?Persistent_Cache $store = null ) {
        $this->http   = $http;
        $this->store  = $store ?? new Persistent_Cache();
        $this->flight = $flight ?? new Single_Flight( $this->store );
    }

    /**
     * Return a cached OAuth access token, fetching a fresh one if
     * none is cached or the cached one has expired. Concurrent callers
     * with the same $cache_key collapse to one upstream auth request.
     *
     * @param string               $cache_key     Stable per-driver+credential key.
     * @param string               $token_url     Token endpoint URL.
     * @param string               $client_id
     * @param string               $client_secret
     * @param array<string,string> $extra_params  Extra body params (e.g. `scope`).
     * @param string               $auth_style    'basic' (default) or 'body'.
     *
     * @throws Carrier_Exception On token fetch failure or malformed response.
     */
    public function token(
        string $cache_key,
        string $token_url,
        string $client_id,
        string $client_secret,
        array $extra_params = array(),
        string $auth_style = 'basic'
    ): string {
        $cached = $this->store->get( $cache_key, self::GROUP );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $http = $this->http;
        $self = $this;

        $value = $this->flight->run(
            $cache_key,
            self::GROUP,
            $cache_key,
            300,
            static function () use ( $self, $token_url, $client_id, $client_secret, $extra_params, $auth_style ): ?array {
                return $self->fetch( $token_url, $client_id, $client_secret, $extra_params, $auth_style );
            },
            static fn ( $v ): bool => is_array( $v ) && isset( $v['token'] ) && '' !== $v['token']
        );

        if ( ! is_array( $value ) || empty( $value['token'] ) ) {
            throw new Carrier_Exception( 'OAuth token response missing access_token.' );
        }

        // The single-flight cache stores the (token, ttl) tuple; if the cache
        // backend rounded the TTL we still want the token-string-only cache
        // hit to survive at the configured expiry, so re-store under the
        // string-only convention used by the fast path.
        $this->store->set( $cache_key, self::GROUP, (string) $value['token'], max( 60, (int) $value['ttl'] ) );

        return (string) $value['token'];
    }

    /**
     * @param array<string,string> $extra_params
     * @return array{token:string,ttl:int}|null
     */
    public function fetch(
        string $token_url,
        string $client_id,
        string $client_secret,
        array $extra_params,
        string $auth_style
    ): ?array {
        $body = array_merge( array( 'grant_type' => 'client_credentials' ), $extra_params );

        $headers = array(
            'Accept'       => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        if ( 'basic' === $auth_style ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
        } else {
            $body['client_id']     = $client_id;
            $body['client_secret'] = $client_secret;
        }

        $response = $this->http->request( 'POST', $token_url, array(
            'headers' => $headers,
            'body'    => http_build_query( $body, '', '&', PHP_QUERY_RFC3986 ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'OAuth token fetch failed (%s).', esc_html( (string) $response['status'] ) ) );
        }

        $decoded = json_decode( $response['body'], true );
        $token   = is_array( $decoded ) ? (string) ( $decoded['access_token'] ?? '' ) : '';
        $expires = is_array( $decoded ) ? (int) ( $decoded['expires_in'] ?? 0 ) : 0;

        if ( '' === $token ) {
            return null;
        }

        $ttl = $expires > 60 ? ( $expires - 60 ) : 300;
        return array( 'token' => $token, 'ttl' => $ttl );
    }

    public function forget( string $cache_key ): void {
        $this->store->delete( $cache_key, self::GROUP );
    }
}
