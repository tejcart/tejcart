<?php
/**
 * Rate Limiter.
 *
 * Provides request rate-limiting using WordPress object cache (with
 * transient fallback). Uses fixed-window counters: a single integer
 * per (action, identifier, window) that auto-expires when the window
 * elapses. Trades a small amount of accuracy at the window boundary
 * (a bursty client could do ~2× the limit briefly across two adjacent
 * windows) for O(1) work per request and atomic increments under
 * Redis / Memcached.
 *
 * Previously used a sliding-window timestamp array which grew
 * proportionally to attempts and required a read-modify-write on every
 * call; under heavy add-to-cart load on a host without an external
 * object cache this hit wp_options on every request and was both
 * slow and racy.
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracks and enforces rate limits for arbitrary actions per identifier.
 */
class Rate_Limiter {
    /**
     * Check whether the identifier has exceeded the allowed attempts.
     *
     * @param string $action         The action key (e.g. 'checkout', 'add_to_cart').
     * @param string $identifier     The identifier (IP address, email, etc.).
     * @param int    $max_attempts   Maximum attempts allowed within the window.
     * @param int    $window_seconds Time window in seconds.
     * @return bool True if within limits, false if rate-limited.
     */
    public static function check( $action, $identifier, $max_attempts, $window_seconds ) {
        $attempts = self::get_attempts( $action, $identifier );

        return $attempts < $max_attempts;
    }

    /**
     * Record an attempt for the given action and identifier.
     *
     * Atomic increment when an external object cache is present
     * (`wp_cache_incr` after a `wp_cache_add` to seed the TTL).
     * Otherwise a non-atomic read/increment/write against transients —
     * still O(1) and far cheaper than the previous timestamp-array.
     *
     * @param string $action         The action key.
     * @param string $identifier     The identifier.
     * @param int    $window_seconds Time window in seconds for cache expiry.
     * @return int Counter value after the increment.
     */
    public static function record( $action, $identifier, $window_seconds = 300 ) {
        $key = self::get_cache_key( $action, $identifier );

        if ( self::is_using_fast_cache() ) {
            // wp_cache_add returns true ONLY when the key was absent;
            // that's our "first hit, set the TTL" signal. Subsequent
            // hits in the same window go through the atomic incr.
            if ( wp_cache_add( $key, 1, 'tejcart_rate_limiter', $window_seconds ) ) {
                return 1;
            }

            $count = wp_cache_incr( $key, 1, 'tejcart_rate_limiter' );
            if ( false !== $count ) {
                return (int) $count;
            }

            // Some object cache backends don't implement incr cleanly.
            // Fall through to the non-atomic path so we still record
            // the attempt rather than dropping it.
            $count = (int) wp_cache_get( $key, 'tejcart_rate_limiter' );
            $count++;
            wp_cache_set( $key, $count, 'tejcart_rate_limiter', $window_seconds );
            return $count;
        }

        $count = (int) get_transient( $key );
        $count++;
        set_transient( $key, $count, $window_seconds );
        return $count;
    }

    /**
     * Get the current number of attempts within the window.
     *
     * @param string $action     The action key.
     * @param string $identifier The identifier.
     * @return int
     */
    public static function get_attempts( $action, $identifier ) {
        $key   = self::get_cache_key( $action, $identifier );
        $value = self::cache_get( $key );

        if ( null === $value ) {
            return 0;
        }

        // Backwards compatibility: until cached entries from the old
        // sliding-window format expire, treat their array length as the
        // current count. New writes always use an integer.
        if ( is_array( $value ) ) {
            return count( $value );
        }

        return (int) $value;
    }

    /**
     * Reset (clear) all recorded attempts for the given action and identifier.
     *
     * @param string $action     The action key.
     * @param string $identifier The identifier.
     * @return void
     */
    public static function reset( $action, $identifier ) {
        $key = self::get_cache_key( $action, $identifier );
        self::cache_delete( $key );
    }

    /**
     * Check if the identifier is rate-limited for the given action.
     *
     * @param string $action         The action key.
     * @param string $identifier     The identifier.
     * @param int    $max_attempts   Maximum attempts allowed.
     * @param int    $window_seconds Time window in seconds.
     * @return bool True if rate-limited (exceeded), false otherwise.
     */
    public static function is_rate_limited( $action, $identifier, $max_attempts = 10, $window_seconds = 300 ) {
        return ! self::check( $action, $identifier, $max_attempts, $window_seconds );
    }

    /**
     * Atomically record an attempt AND check if the identifier is now rate-limited.
     *
     * Fixes the TOCTOU race condition of separate check() + record() calls.
     * Always records the attempt first, then checks the count.
     *
     * @param string $action         The action key.
     * @param string $identifier     The identifier (IP, email, etc.).
     * @param int    $max_attempts   Maximum attempts allowed within the window.
     * @param int    $window_seconds Time window in seconds.
     * @return bool True if rate-limited (limit exceeded AFTER this attempt), false if within limits.
     */
    public static function check_and_record( $action, $identifier, $max_attempts, $window_seconds ) {
        $count = self::record( $action, $identifier, $window_seconds );

        return $count > $max_attempts;
    }

    /**
     * Get the real client IP address.
     *
     * Trust model (S-1): proxy headers are only consulted when the request
     * actually arrived from a proxy whose CIDR is in the trusted-proxies
     * list. Without that origin check a direct hit on the origin (bypassing
     * Cloudflare / Fastly / the host's reverse proxy) can spoof
     * `CF-Connecting-IP` or `X-Forwarded-For` to defeat per-IP rate limits,
     * login throttle, coupon brute-force ceilings, and IP-locked download
     * tokens.
     *
     * `X-Forwarded-For` is parsed *rightmost-first* — that is the value the
     * trusted proxy appended; the leftmost is whatever the original client
     * sent and is attacker-controlled.
     *
     * @return string
     */
    public static function get_client_ip() {
        $filtered_ip = apply_filters( 'tejcart_client_ip', null );
        if ( null !== $filtered_ip && filter_var( $filtered_ip, FILTER_VALIDATE_IP ) ) {
            return $filtered_ip;
        }

        $remote = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                $remote = $candidate;
            }
        }

        $trust_enabled = defined( 'TEJCART_TRUST_PROXY_HEADERS' ) && TEJCART_TRUST_PROXY_HEADERS;
        if ( $trust_enabled && '' !== $remote && self::remote_is_trusted_proxy( $remote ) ) {
            $cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
                : '';
            if ( '' !== $cf && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
                return $cf;
            }

            $real = isset( $_SERVER['HTTP_X_REAL_IP'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) )
                : '';
            if ( '' !== $real && filter_var( $real, FILTER_VALIDATE_IP ) ) {
                return $real;
            }

            $xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
                : '';
            if ( '' !== $xff ) {
                $ips = array_map( 'trim', explode( ',', $xff ) );
                $rightmost = filter_var( end( $ips ), FILTER_VALIDATE_IP );
                if ( $rightmost ) {
                    return $rightmost;
                }
            }
        }

        return '' !== $remote ? $remote : '0.0.0.0';
    }

    /**
     * True when REMOTE_ADDR sits in one of the trusted-proxy CIDRs.
     *
     * Operators ship a list via the `tejcart_trusted_proxies` filter or the
     * `tejcart_trusted_proxies` option. The default empty list means
     * `TEJCART_TRUST_PROXY_HEADERS` alone is not enough — operators MUST
     * declare which proxy ranges they actually run behind.
     *
     * @param string $remote Validated REMOTE_ADDR.
     * @return bool
     */
    public static function remote_is_trusted_proxy( string $remote ): bool {
        static $cidrs = null;

        if ( null === $cidrs ) {
            $configured = get_option( 'tejcart_trusted_proxies', array() );
            if ( ! is_array( $configured ) ) {
                $configured = array();
            }
            /**
             * Filter the list of trusted reverse-proxy CIDRs.
             *
             * @param string[] $cidrs IPv4 / IPv6 CIDR notations (e.g. '173.245.48.0/20').
             */
            $cidrs = array_values( array_filter(
                (array) apply_filters( 'tejcart_trusted_proxies', $configured ),
                'is_string'
            ) );
        }

        if ( empty( $cidrs ) ) {
            return false;
        }

        foreach ( $cidrs as $cidr ) {
            if ( self::ip_in_cidr( $remote, $cidr ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv4 / IPv6 CIDR membership check. Pure PHP, no extension dependency.
     *
     * @param string $ip   Validated IP.
     * @param string $cidr CIDR notation (e.g. '173.245.48.0/20', '2400:cb00::/32').
     * @return bool
     */
    public static function ip_in_cidr( string $ip, string $cidr ): bool {
        if ( false === strpos( $cidr, '/' ) ) {
            // Bare IP — treat as /32 (v4) or /128 (v6).
            return filter_var( $cidr, FILTER_VALIDATE_IP ) ? hash_equals( inet_pton( $ip ) ?: '', inet_pton( $cidr ) ?: '' ) : false;
        }

        list( $subnet, $bits ) = explode( '/', $cidr, 2 );
        $bits = (int) $bits;

        $ip_bin     = @inet_pton( $ip );
        $subnet_bin = @inet_pton( $subnet );
        if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
            return false;
        }

        $byte_count = (int) floor( $bits / 8 );
        $bit_remain = $bits % 8;

        if ( $byte_count > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $byte_count ) ) {
            return false;
        }
        if ( 0 === $bit_remain ) {
            return true;
        }

        $mask = chr( 0xFF << ( 8 - $bit_remain ) & 0xFF );
        return ( ord( $ip_bin[ $byte_count ] ) & ord( $mask ) ) === ( ord( $subnet_bin[ $byte_count ] ) & ord( $mask ) );
    }

    /**
     * Check if an external object cache (Redis, Memcached, etc.) is available.
     *
     * @return bool
     */
    public static function is_using_fast_cache() {
        return wp_using_ext_object_cache();
    }

    /**
     * Cache key version. Bump to invalidate every existing rate-limit
     * counter (e.g. when migrating a site between an in-process object
     * cache and Redis, where stale counters would otherwise leak across
     * the storage swap).
     */
    private const CACHE_KEY_VERSION = 'v1';

    /**
     * Build a cache key for the given action and identifier.
     *
     * @param string $action     The action key.
     * @param string $identifier The identifier.
     * @return string
     */
    private static function get_cache_key( $action, $identifier ) {
        return 'tejcart_rl_' . self::CACHE_KEY_VERSION . '_' . md5( $action . '|' . $identifier );
    }

    /**
     * Get a value from the cache layer (object cache or transient fallback).
     *
     * @param string $key Cache key.
     * @return mixed
     */
    private static function cache_get( $key ) {
        if ( self::is_using_fast_cache() ) {
            $value = wp_cache_get( $key, 'tejcart_rate_limiter' );
            return false === $value ? null : $value;
        }

        $value = get_transient( $key );
        return false === $value ? null : $value;
    }

    /**
     * Set a value in the cache layer (object cache or transient fallback).
     *
     * @param string $key        Cache key.
     * @param mixed  $value      Value to store.
     * @param int    $expiration Expiration in seconds.
     * @return void
     */
    private static function cache_set( $key, $value, $expiration ) {
        if ( self::is_using_fast_cache() ) {
            wp_cache_set( $key, $value, 'tejcart_rate_limiter', $expiration );
            return;
        }

        set_transient( $key, $value, $expiration );
    }

    /**
     * Delete a value from the cache layer.
     *
     * @param string $key Cache key.
     * @return void
     */
    private static function cache_delete( $key ) {
        if ( self::is_using_fast_cache() ) {
            wp_cache_delete( $key, 'tejcart_rate_limiter' );
            return;
        }

        delete_transient( $key );
    }
}
