<?php
/**
 * Object-cache-backed rate cache.
 *
 * Carrier APIs are slow (300ms-3s per call) and rate-limited; checkout
 * page loads must not pay that cost on every render. Quotes are cached
 * per (driver, request signature) for a short TTL — long enough to
 * smooth a checkout pageview burst, short enough that a published rate
 * change reaches merchants within minutes.
 *
 * `get_or_compute()` adds a single-flight lock so concurrent identical
 * requests collapse to one upstream call — required for
 * very-high-traffic merchants where dozens of workers may hit the
 * carrier API at the same instant for the same cart.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rate_Cache {
    public const GROUP   = 'tejcart_shipping_rates';
    public const DEFAULT_TTL = 300;

    private Single_Flight $flight;
    private Persistent_Cache $store;

    public function __construct( ?Single_Flight $flight = null, ?Persistent_Cache $store = null ) {
        $this->store  = $store ?? new Persistent_Cache();
        $this->flight = $flight ?? new Single_Flight( $this->store );
    }

    /**
     * @return Rate_Quote[]|null Null on cache miss.
     */
    public function get( string $driver_id, Rate_Request $request ): ?array {
        $key   = $this->key( $driver_id, $request );
        $found = false;
        $value = $this->store->get( $key, self::GROUP, $found );

        if ( ! $found || ! is_array( $value ) ) {
            return null;
        }

        foreach ( $value as $quote ) {
            if ( ! $quote instanceof Rate_Quote ) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param Rate_Quote[] $quotes
     */
    public function set( string $driver_id, Rate_Request $request, array $quotes, int $ttl = self::DEFAULT_TTL ): void {
        $ttl = max( 1, $ttl );
        $this->store->set( $this->key( $driver_id, $request ), self::GROUP, $quotes, $ttl );
    }

    public function forget( string $driver_id, Rate_Request $request ): void {
        $this->store->delete( $this->key( $driver_id, $request ), self::GROUP );
    }

    /**
     * Return the cached quote list, or compute + cache it under a
     * single-flight lock so concurrent requests collapse.
     *
     * @param callable():Rate_Quote[] $compute
     * @return Rate_Quote[]
     */
    public function get_or_compute( string $driver_id, Rate_Request $request, callable $compute, int $ttl = self::DEFAULT_TTL ): array {
        $key = $this->key( $driver_id, $request );
        $ttl = max( 1, $ttl );

        $value = $this->flight->run(
            $key,
            self::GROUP,
            $key,
            $ttl,
            $compute,
            static function ( $value ): bool {
                if ( ! is_array( $value ) ) {
                    return false;
                }
                foreach ( $value as $quote ) {
                    if ( ! $quote instanceof Rate_Quote ) {
                        return false;
                    }
                }
                return true;
            }
        );

        return is_array( $value ) ? $value : array();
    }

    private function key( string $driver_id, Rate_Request $request ): string {
        return $driver_id . ':' . $request->cache_signature();
    }
}
