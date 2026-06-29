<?php
/**
 * Hybrid cross-request value cache for the shipping module.
 *
 * The shipping cache layer (carrier auth tokens, rate quotes, the
 * single-flight result store) is built on the WordPress object cache
 * (`wp_cache_*`). On sites that run a *persistent* object-cache backend
 * (Redis / Memcached drop-in) that already survives across requests and
 * nothing more is needed. But on a default WordPress install the object
 * cache is **non-persistent** — it lives for exactly one PHP request — so
 * a value written during the checkout page render is gone by the time the
 * `tejcart_refresh_shipping_methods` AJAX request runs. The visible
 * symptom is a fresh carrier login + serviceability lookup on *every*
 * checkout recalculation instead of one per TTL window.
 *
 * This helper closes that gap without penalising sites that already have
 * a real object cache:
 *
 *   - When `wp_using_ext_object_cache()` is true, values go straight to
 *     `wp_cache_*` (already persistent, zero DB writes) — behaviour is
 *     unchanged for Redis/Memcached sites.
 *   - Otherwise values are mirrored into a transient (DB-backed, survives
 *     across requests) *and* the request-local object cache, so repeated
 *     reads within one request stay fast and reads across requests hit
 *     the transient instead of the carrier API.
 *
 * The transient fallback can be disabled with the
 * `tejcart_shipping_persistent_cache` filter (return false) — that
 * restores the original per-request-only behaviour for merchants who
 * prefer never to persist derived carrier tokens to the database.
 *
 * Scope note: this caches *values* (tokens, quotes) only. Single-flight
 * locks deliberately stay on `wp_cache_*` — lock atomicity depends on
 * `wp_cache_add`, and a transient-backed lock is neither atomic nor worth
 * the DB churn. Without a persistent object cache the lock simply scopes
 * a single request, which is the pre-existing, accepted behaviour.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Persistent_Cache {
    /**
     * Transient name prefix. `tejcart_sc_` (11) + 32-char md5 = 43 chars,
     * comfortably under WordPress's 172-char transient-name ceiling (and
     * the matching `_transient_timeout_…` option name stays under the
     * 191-char option_name column limit).
     */
    private const TRANSIENT_PREFIX = 'tejcart_sc_';

    /**
     * Should values persist to a transient when no external object cache
     * is available? Resolved once per instance so a single request can't
     * see the mode flip mid-flight.
     */
    private bool $persist;

    public function __construct() {
        $has_ext_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

        // Only the no-ext-cache path needs the transient fallback; when an
        // external object cache is present `wp_cache_*` is already durable.
        $this->persist = ! $has_ext_cache
            && (bool) apply_filters( 'tejcart_shipping_persistent_cache', true );
    }

    /**
     * Read a cached value.
     *
     * @param string    $key
     * @param string    $group
     * @param bool|null $found  Out-param: true on a cache hit, false on miss.
     * @return mixed The cached value, or false on miss (mirrors wp_cache_get).
     */
    public function get( string $key, string $group, ?bool &$found = null ) {
        $found = false;

        // Request-local fast path (works in every mode).
        $object_found = false;
        $value        = wp_cache_get( $key, $group, false, $object_found );
        if ( $object_found ) {
            $found = true;
            return $value;
        }

        if ( ! $this->persist ) {
            return false;
        }

        // Cross-request path: read the DB-backed transient. We never store
        // a literal `false`, so `false` unambiguously means "not present".
        $stored = get_transient( $this->transient_name( $key, $group ) );
        if ( false === $stored ) {
            return false;
        }

        // Prime the request-local cache so subsequent reads in this request
        // skip the DB round-trip.
        wp_cache_set( $key, $stored, $group );
        $found = true;
        return $stored;
    }

    /**
     * Write a value to the cache.
     *
     * @param string $key
     * @param string $group
     * @param mixed  $value
     * @param int    $ttl    Seconds; clamped to >= 1 (a 0 TTL would mean
     *                       "no expiry" for transients, which we never want
     *                       for derived carrier data).
     */
    public function set( string $key, string $group, $value, int $ttl ): void {
        $ttl = max( 1, $ttl );

        wp_cache_set( $key, $value, $group, $ttl );

        if ( $this->persist ) {
            set_transient( $this->transient_name( $key, $group ), $value, $ttl );
        }
    }

    /**
     * Delete a cached value from every layer it could live in.
     */
    public function delete( string $key, string $group ): void {
        wp_cache_delete( $key, $group );

        if ( $this->persist ) {
            delete_transient( $this->transient_name( $key, $group ) );
        }
    }

    private function transient_name( string $key, string $group ): string {
        return self::TRANSIENT_PREFIX . md5( $group . '|' . $key );
    }
}
