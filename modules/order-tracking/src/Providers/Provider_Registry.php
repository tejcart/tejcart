<?php
/**
 * Provider registry.
 *
 * Holds a singleton-ish list of registered providers. Sites can:
 *  - Register a provider via `register()` (typically inside a sibling
 *    or theme `tejcart_init` hook).
 *  - Override the active set via `tejcart_order_tracking_providers`
 *    filter (e.g. to disable a provider in staging).
 *
 * The registry exposes two lookup paths:
 *  - `for_carrier(string $carrier)` returns the first configured
 *    provider that supports the carrier — used by the polling job.
 *  - `get(string $slug)` returns the provider by slug, used by the
 *    webhook receiver routing.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Provider_Registry {
    /** @var array<string, Tracking_Provider> */
    private array $providers = array();

    public function register( Tracking_Provider $provider ): void {
        $this->providers[ $provider->slug() ] = $provider;
    }

    public function unregister( string $slug ): void {
        unset( $this->providers[ $slug ] );
    }

    /**
     * @return array<string, Tracking_Provider>
     */
    public function all(): array {
        /**
         * Filter the registered tracking providers.
         *
         * Receives the current registry as a `slug => provider` map.
         * Implementations may add, remove, or replace entries — the
         * map is taken at face value after filtering.
         *
         * @param array<string, Tracking_Provider> $providers
         */
        $filtered = (array) apply_filters( 'tejcart_order_tracking_providers', $this->providers );
        $clean    = array();
        foreach ( $filtered as $slug => $provider ) {
            if ( $provider instanceof Tracking_Provider ) {
                $clean[ (string) $slug ] = $provider;
            }
        }
        return $clean;
    }

    public function get( string $slug ): ?Tracking_Provider {
        $all = $this->all();
        return $all[ $slug ] ?? null;
    }

    /**
     * Find the first configured provider that supports the carrier.
     */
    public function for_carrier( string $carrier ): ?Tracking_Provider {
        if ( '' === $carrier ) {
            return null;
        }
        foreach ( $this->all() as $provider ) {
            if ( $provider->is_configured() && $provider->supports( $carrier ) ) {
                return $provider;
            }
        }
        return null;
    }
}
