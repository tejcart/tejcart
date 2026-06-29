<?php
/**
 * cURL connection-reuse manager for PayPal API calls.
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-request singleton that lets `wp_remote_request()` reuse TLS
 * connections across multiple PayPal API calls within the same PHP
 * request.
 *
 * Why this exists
 * ===============
 * A typical Smart-Buttons checkout makes two PayPal calls in series:
 *
 *   1. `POST /v2/checkout/orders`   (create-order, on button click)
 *   2. `POST /v2/checkout/orders/{id}/capture`  (after buyer approves)
 *
 * `wp_remote_request()` (via the bundled `WP_Http_Curl` transport)
 * creates a fresh `curl` handle per call. Each handle does its own
 * DNS lookup, TCP handshake, TLS handshake, and SSL session
 * negotiation against `api-m.paypal.com`. On a typical merchant
 * server that's 80–150 ms of pure connection overhead per call —
 * 160–300 ms total on a checkout that hits PayPal twice, completely
 * separate from anything PayPal is actually computing on its side.
 *
 * libcurl already knows how to reuse connections when you give it a
 * `CURLSH` (curl-share) handle. `CURLSHOPT_SHARE` lets multiple curl
 * handles pool their connection cache, DNS cache, and SSL sessions.
 * We attach a process-wide share to every PayPal-bound request via
 * the `http_api_curl` filter; non-PayPal requests pass through
 * untouched.
 *
 * Scope
 * =====
 *  - **Per-request** — the share lives in a static and survives for
 *    the lifetime of the PHP request. Within one request, any number
 *    of PayPal calls reuse the same TCP connection.
 *  - **No cross-request reuse** — between requests, PHP frees the
 *    static and the share goes away. Cross-request reuse would need
 *    persistent FastCGI-level handles, which is host-specific and
 *    out of scope here. (PR #7 in the perf roadmap may revisit this.)
 *
 * Why this is safe for high-volume merchants
 * ==========================================
 *  - The filter is host-scoped to `*.paypal.com`. Other plugins'
 *    outbound HTTP traffic is untouched.
 *  - The keep-alive + HTTP/2 settings can each be disabled via filter
 *    so a merchant whose hosting environment trips on either feature
 *    can revert to legacy behaviour without un-deploying the plugin.
 *  - The share handle is created lazily on first PayPal request —
 *    sites that never talk to PayPal pay zero cost.
 *  - Setting `CURLOPT_SHARE` is a no-op when the curl extension lacks
 *    `curl_share_init` (e.g. minimal PHP builds), so we degrade
 *    gracefully into the existing per-call connection pattern.
 *
 * Failure modes considered
 * ========================
 *  - **HTTP/2 buggy on older curl/libssl** — HTTP/2 is **default-off**
 *    (opt-in via `tejcart_paypal_keep_alive_http2_enabled`). PayPal
 *    calls in a TejCart request are sequential, so the multiplexing
 *    win is nil; the connection-share + DNS + SSL-session caches
 *    deliver the full perf gain on HTTP/1.1 keep-alive. We also honour
 *    any explicit `httpversion` arg passed by the caller (the
 *    gateway-wide `http_request_args` filter pins `'1.1'` for every
 *    PayPal call), so this filter cannot silently re-upgrade a
 *    pinned-1.1 request and resurface the "Response could not be
 *    parsed" failure mode that bit live merchants on the original
 *    default-on rollout.
 *  - **Connection RST mid-flight** — libcurl handles transparent
 *    re-dial; the request looks like a normal first call, the merchant
 *    just doesn't get the keep-alive saving on that particular pair.
 *  - **Process running so long the keep-alive idles out** — guarded by
 *    `CURLOPT_TCP_KEEPALIVE` + 60 s idle / 30 s probe cadence, which
 *    matches `api-m.paypal.com`'s observed connection-keepalive
 *    window.
 *
 * Boundary with WP_Http_Curl (ARC-005)
 * ====================================
 * WP core's `WP_Http_Curl` transport does these for every request and
 * we deliberately do NOT re-implement them here:
 *
 *  - curl handle lifecycle (init / setopt / exec / close)
 *  - User-Agent, Accept, Cookie headers
 *  - request body / response body wiring
 *  - response header parsing, status normalisation
 *  - timeout, redirect-cap, SSL-verify-peer enforcement
 *  - blocking-mode / streaming-to-file modes
 *  - cookie jar persistence
 *
 * Curl_Share_Manager is strictly additive — it attaches `CURLOPT_SHARE`
 * (and an opt-in HTTP/2 toggle) to the curl handle WP_Http_Curl built,
 * via the `http_api_curl` filter. WP-core owns the request; we just
 * splice in cross-call connection / DNS / TLS-session reuse for the
 * narrow `*.paypal.com` host set. Removing this file would not break
 * any single PayPal request — it would just give back the 80-150 ms
 * of connection overhead per call.
 *
 * If a future WP version ships native per-host connection pooling at
 * the transport layer, this file can shrink to a no-op shim or be
 * deleted entirely.
 */
final class Curl_Share_Manager {
    /**
     * Lazily-initialised curl-share handle. `\CurlShareHandle` since
     * PHP 8.0; before that the curl extension returned a resource of
     * type `curl_share`. The plugin requires PHP 8.0 so the typed
     * variant is always available.
     *
     * @var \CurlShareHandle|null
     */
    private static ?\CurlShareHandle $share = null;

    /**
     * Set to `true` after we successfully attach the `http_api_curl`
     * filter so a re-entrant `bootstrap()` call (e.g. multiple gateway
     * instances created during the same request) doesn't register the
     * filter twice.
     */
    private static bool $registered = false;

    /**
     * Hosts whose outbound requests get the share treatment. Anchored
     * pattern so a host like `notpaypal.com.attacker.example` cannot
     * trick us into pooling its connection with PayPal's. The
     * `(\.sandbox)?` segment intentionally allows the live and sandbox
     * APIs to share a pool — the connections are to different hosts so
     * libcurl never actually mixes them, but registering both means we
     * cover every TejCart deployment without case-by-case logic.
     */
    private const PAYPAL_HOST_PATTERN = '#^https://api(-m)?(\.sandbox)?\.paypal\.com/#i';

    /**
     * Idempotently register the `http_api_curl` filter. Called from
     * {@see PayPal_API::__construct()} so the filter is up before the
     * first API call of the request.
     *
     * @return void
     */
    public static function bootstrap(): void {
        if ( self::$registered ) {
            return;
        }
        if ( ! function_exists( 'curl_share_init' ) ) {
            // Minimal PHP build without curl-share. Stay quiet — the
            // existing wp_remote_request path still works, just without
            // connection reuse. We mark the bootstrap "done" so we
            // don't keep retrying the function_exists check.
            self::$registered = true;
            return;
        }
        // `add_filter` is part of the WP core API and is always present
        // when this class runs; no `function_exists` guard. Brain Monkey
        // mocks the function in tests, but the mock isn't visible to
        // `function_exists()`, so guarding here would silently disable
        // the filter under test and let the production code regress
        // unnoticed.
        add_filter( 'http_api_curl', array( __CLASS__, 'on_http_api_curl' ), 10, 3 );
        self::$registered = true;
    }

    /**
     * Test-only inspector. Returns whether {@see bootstrap()} has run
     * its filter registration. Exists so the unit suite can pin
     * idempotency without depending on Brain Monkey's
     * `Filters\expectAdded()` tracker — the test-stub `add_filter()`
     * in tests/stubs/wp-functions.php intercepts the call before
     * Brain Monkey can see it, so we have to check the side effect
     * via our own state.
     *
     * @internal
     * @return bool
     */
    public static function is_registered_for_tests(): bool {
        return self::$registered;
    }

    /**
     * Test-only reset hook. Not part of the public API — exists so unit
     * tests under `@runInSeparateProcess` can re-bootstrap without
     * leaking the static across process boundaries. Production code
     * MUST NOT call this; doing so on a live request would orphan the
     * filter binding and silently disable connection reuse mid-flight.
     *
     * @internal
     * @return void
     */
    public static function reset_for_tests(): void {
        if ( null !== self::$share ) {
            // The share is closed implicitly when the last reference
            // drops; we just null it. Safer than calling
            // `curl_share_close()` on a handle that other in-flight
            // requests might still hold a reference to.
            self::$share = null;
        }
        self::$registered = false;
    }

    /**
     * `http_api_curl` callback. Attaches the share + keep-alive opts to
     * curl handles whose URL points at PayPal's REST API.
     *
     * The handle parameter is `mixed` rather than `\CurlHandle|resource`
     * deliberately: WP's filter signature is untyped, and unit tests
     * pass placeholder values that wouldn't be accepted by a strict
     * type. The {@see is_curl_handle()} guard inside the body rejects
     * anything that isn't actually a curl handle, so the runtime
     * behaviour is the same as a strictly-typed parameter — without
     * forcing tests to spin up a real curl handle just to verify the
     * URL-allowlist logic.
     *
     * @param mixed                $handle Curl easy-handle (or anything
     *                                     else WP / a test passes —
     *                                     guarded internally).
     * @param array<string, mixed> $args   Args passed to wp_remote_*.
     * @param string               $url    Final outbound URL.
     * @return void
     */
    public static function on_http_api_curl( $handle, array $args, string $url ): void {
        if ( ! self::is_paypal_url( $url ) ) {
            return;
        }
        if ( ! self::is_keep_alive_enabled() ) {
            return;
        }
        if ( ! self::is_curl_handle( $handle ) ) {
            return;
        }

        $share = self::share();
        if ( null !== $share ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
            curl_setopt( $handle, CURLOPT_SHARE, $share );
        }

        // TCP keep-alive: 60 s idle before first probe, 30 s between
        // probes. PayPal's idle-timeout has historically been ~80 s on
        // api-m.paypal.com; staying well under that means we don't
        // waste a round-trip re-establishing dead connections on
        // sporadic merchant traffic.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
        curl_setopt( $handle, CURLOPT_TCP_KEEPALIVE, 1 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
        curl_setopt( $handle, CURLOPT_TCP_KEEPIDLE, 60 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
        curl_setopt( $handle, CURLOPT_TCP_KEEPINTVL, 30 );
        // Forbid neither connection reuse nor the cache — these are
        // libcurl defaults (off) but pinned explicitly so that another
        // plugin's `http_api_curl` filter cannot accidentally turn
        // them on for our share group.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
        curl_setopt( $handle, CURLOPT_FORBID_REUSE, 0 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
        curl_setopt( $handle, CURLOPT_FRESH_CONNECT, 0 );

        if ( self::should_enable_http2( $args ) && defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
            // HTTP/2 multiplexes multiple in-flight requests over a
            // single TCP connection. PayPal's edge supports it; libcurl
            // ≥ 7.43 with nghttp2 supports it. Falls back to HTTP/1.1
            // automatically if the negotiation fails.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Tuning a curl handle inside the http_api_curl filter; wp_remote_* has no equivalent.
            curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS );
        }
    }

    /**
     * Decide whether to upgrade this request to HTTP/2.
     *
     * Two reasons to stay on HTTP/1.1:
     *
     *  1. The caller pinned `httpversion` in the wp_remote_* args. The
     *     gateway-wide `http_request_args` filter installed in
     *     {@see PayPal_Onboarding::filter_paypal_http_args()} sets
     *     `'1.1'` on every PayPal call so a buggy libcurl/openssl HTTP/2
     *     stack cannot truncate the response body and surface as
     *     "Response could not be parsed". WP applies that arg via
     *     `CURLOPT_HTTP_VERSION` BEFORE this `http_api_curl` filter
     *     fires, so without this guard the share manager would silently
     *     re-upgrade the request and re-introduce the parse failure.
     *  2. The merchant has not opted into HTTP/2 multiplexing. PayPal
     *     calls within a single TejCart request are sequential
     *     (create → capture, token → verify), so HTTP/2 multiplexing
     *     adds zero throughput; the keep-alive + connection-share win
     *     is fully captured on HTTP/1.1. Default-off therefore.
     *
     * @param array<string,mixed> $args Args passed to wp_remote_*.
     * @return bool
     */
    public static function should_enable_http2( array $args ): bool {
        if ( isset( $args['httpversion'] ) ) {
            $version = (string) $args['httpversion'];
            if ( '1.0' === $version || '1.1' === $version ) {
                return false;
            }
        }
        return self::is_http2_enabled();
    }

    /**
     * Lazily build the curl-share handle on first need.
     *
     * @return \CurlShareHandle|null Null when curl-share isn't available.
     */
    private static function share(): ?\CurlShareHandle {
        if ( null !== self::$share ) {
            return self::$share;
        }
        if ( ! function_exists( 'curl_share_init' ) ) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_init -- curl-share is a libcurl-only feature; no wp_remote_* equivalent. Used inside the http_api_curl filter to enable connection reuse.
        $share = curl_share_init();
        if ( ! $share instanceof \CurlShareHandle ) {
            return null;
        }
        // Share the connection cache (the actual perf win), DNS lookups
        // (saves ~5–30 ms on each call after the first), and SSL
        // session ID cache (lets libcurl skip a full TLS handshake on
        // reconnect).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_setopt -- curl-share is a libcurl-only feature; no wp_remote_* equivalent.
        curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_setopt -- curl-share is a libcurl-only feature; no wp_remote_* equivalent.
        curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS );
        if ( defined( 'CURL_LOCK_DATA_SSL_SESSION' ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_share_setopt -- curl-share is a libcurl-only feature; no wp_remote_* equivalent.
            curl_share_setopt( $share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION );
        }
        self::$share = $share;
        return self::$share;
    }

    /**
     * Anchor-checked pattern match against the PayPal host allowlist.
     *
     * @param string $url Outbound URL.
     * @return bool
     */
    private static function is_paypal_url( string $url ): bool {
        return (bool) preg_match( self::PAYPAL_HOST_PATTERN, $url );
    }

    /**
     * Top-level kill switch. Defaults `true`; merchants can disable per
     * site via:
     *
     *     add_filter( 'tejcart_paypal_keep_alive_enabled', '__return_false' );
     *
     * @return bool
     */
    private static function is_keep_alive_enabled(): bool {
        if ( ! function_exists( 'apply_filters' ) ) {
            return true;
        }
        return (bool) apply_filters( 'tejcart_paypal_keep_alive_enabled', true );
    }

    /**
     * HTTP/2 sub-toggle. Defaults **off** — opt-in via filter:
     *
     *     add_filter( 'tejcart_paypal_keep_alive_http2_enabled', '__return_true' );
     *
     * Why default-off: PayPal calls within a single request are
     * sequential (create → capture, token → verify), so HTTP/2
     * multiplexing has no throughput advantage over HTTP/1.1
     * keep-alive. The connection-share, DNS-cache, and SSL-session-
     * cache wins (~80–150 ms / call) are fully captured on HTTP/1.1.
     *
     * Default-on broke production checkouts on hosts whose
     * libcurl/openssl combination silently truncates the HTTP/2
     * response body, surfacing as "Response could not be parsed"
     * across every PayPal call site (onboarding, Smart Buttons, card
     * fields, webhook verify). Opting in is the safer default.
     *
     * @return bool
     */
    private static function is_http2_enabled(): bool {
        if ( ! function_exists( 'apply_filters' ) ) {
            return false;
        }
        return (bool) apply_filters( 'tejcart_paypal_keep_alive_http2_enabled', false );
    }

    /**
     * Type-safe handle check. Pre-8.0 the curl handle was a resource;
     * 8.0+ it's a `\CurlHandle` object. We need both branches because
     * the WP filter signature isn't strict-typed.
     *
     * @param mixed $handle Whatever WP's curl transport handed us.
     * @return bool
     */
    private static function is_curl_handle( $handle ): bool {
        return $handle instanceof \CurlHandle
            || ( is_resource( $handle ) && 'curl' === get_resource_type( $handle ) );
    }
}
