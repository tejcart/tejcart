<?php
/**
 * Single-flight lock helper.
 *
 * Collapses concurrent identical work into one upstream call by claiming
 * a short-lived lock in the WordPress object cache. Other workers see
 * the lock, wait briefly for the result to populate, and pick the result
 * up from the cache.
 *
 * Used by:
 *   - Rate_Cache::get_or_compute() to defeat the carrier-rate stampede.
 *   - OAuth_Token_Cache::token() to defeat the auth-endpoint stampede.
 *
 * The implementation degrades gracefully when no persistent object
 * cache is installed: the *lock* still scopes a single PHP request
 * (across-request lock contention falls back to recomputing rather than
 * blocking checkout), while the produced *result* is persisted via
 * Persistent_Cache so a later request — e.g. the checkout AJAX recalc —
 * reuses it instead of repeating the upstream call.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Single_Flight {
    public const GROUP = 'tejcart_shipping_singleflight';

    /**
     * Default lock TTL — bounded above by the carrier timeout (15s) plus
     * retries (~6s back-off). 30s is comfortably above the worst case so
     * the lock can never strand longer than the request that holds it.
     */
    public const DEFAULT_LOCK_TTL = 30;

    /** Default poll interval while waiting for the leader's result. */
    public const POLL_INTERVAL_US = 50_000; // 50 ms

    /** Default total wait budget for followers. */
    public const POLL_BUDGET_US = 5_000_000; // 5 seconds

    /**
     * Cross-request store for the produced *result*. Locks stay on
     * `wp_cache_*` (see methods below) because their atomicity depends on
     * `wp_cache_add`; only the computed value needs to survive across
     * requests so a checkout recalc reuses it instead of re-hitting the
     * carrier.
     */
    private Persistent_Cache $store;

    public function __construct( ?Persistent_Cache $store = null ) {
        $this->store = $store ?? new Persistent_Cache();
    }

    /**
     * Run $compute() under a single-flight lock keyed by $key. If another
     * worker already holds the lock, wait briefly for them to populate
     * the result cache and return that; otherwise produce + cache.
     *
     * @template T
     * @param string                 $key            Unique key for this work-unit.
     * @param string                 $result_group   Object-cache group the result lives in.
     * @param string                 $result_key     Object-cache key the result lives at.
     * @param int                    $result_ttl     TTL for the produced result.
     * @param callable():T           $compute        Producer; called only by the leader.
     * @param callable(mixed):bool   $is_valid       Validator: does the cache value count as a hit?
     * @return T|null
     */
    public function run(
        string $key,
        string $result_group,
        string $result_key,
        int $result_ttl,
        callable $compute,
        callable $is_valid
    ): mixed {
        // Fast path: result already cached.
        $cached = $this->store->get( $result_key, $result_group );
        if ( $is_valid( $cached ) ) {
            return $cached;
        }

        $lock_key = 'lock:' . $key;
        $token    = bin2hex( random_bytes( 8 ) );

        if ( $this->try_acquire( $lock_key, $token ) ) {
            try {
                $result = $compute();
                if ( null !== $result ) {
                    $this->store->set( $result_key, $result_group, $result, $result_ttl );
                }
                return $result;
            } finally {
                $this->release( $lock_key, $token );
            }
        }

        // Follower: poll briefly for the leader's result.
        $waited = 0;
        while ( $waited < self::POLL_BUDGET_US ) {
            usleep( self::POLL_INTERVAL_US );
            $waited += self::POLL_INTERVAL_US;
            $value = $this->store->get( $result_key, $result_group );
            if ( $is_valid( $value ) ) {
                return $value;
            }
            // Leader might have died — try to take over once.
            if ( $waited >= ( self::POLL_BUDGET_US / 2 ) && $this->try_acquire( $lock_key, $token ) ) {
                try {
                    $result = $compute();
                    if ( null !== $result ) {
                        $this->store->set( $result_key, $result_group, $result, $result_ttl );
                    }
                    return $result;
                } finally {
                    $this->release( $lock_key, $token );
                }
            }
        }

        // Followers gave up — run uncached so checkout doesn't stall.
        return $compute();
    }

    private function try_acquire( string $lock_key, string $token ): bool {
        return (bool) wp_cache_add( $lock_key, $token, self::GROUP, self::DEFAULT_LOCK_TTL );
    }

    private function release( string $lock_key, string $token ): void {
        $current = wp_cache_get( $lock_key, self::GROUP );
        if ( $current === $token ) {
            wp_cache_delete( $lock_key, self::GROUP );
        }
    }
}
