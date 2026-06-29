<?php
/**
 * Tax provider registry.
 *
 * @package TejCart\Tax
 */

declare( strict_types=1 );

namespace TejCart\Tax;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Collects third-party tax providers (TaxJar, Avalara, etc.) registered via
 * the `tejcart_tax_providers` filter and resolves the active provider.
 *
 * Mirrors the pattern used by `TejCart\Gateways\Gateway_Registry`: the list
 * is materialised lazily on first access, and instances are cached for the
 * request lifetime.
 */
class Tax_Provider_Registry {
    /**
     * Cached instances keyed by provider ID.
     *
     * @var Abstract_Tax_Provider[]|null
     */
    private static ?array $instances = null;

    /**
     * Get all registered tax providers, instantiated.
     *
     * @return Abstract_Tax_Provider[]
     */
    public static function get_providers(): array {
        if ( null !== self::$instances ) {
            return self::$instances;
        }

        /**
         * Filter the registered tax provider class map.
         *
         * Third-party plugins add their `Abstract_Tax_Provider` subclass here.
         *
         * @param array<string,string> $providers Map of provider ID → class name.
         */
        $classes = (array) apply_filters( 'tejcart_tax_providers', array() );

        $instances = array();
        foreach ( $classes as $id => $class ) {
            if ( ! is_string( $class ) || ! class_exists( $class ) ) {
                continue;
            }
            $instance = new $class();
            if ( $instance instanceof Abstract_Tax_Provider ) {
                $instances[ $instance->get_id() ?: (string) $id ] = $instance;
            }
        }

        self::$instances = $instances;
        return self::$instances;
    }

    /**
     * Resolve the currently active provider.
     *
     * Selection order:
     *   1. `tejcart_active_tax_provider` filter (highest priority — lets a
     *      per-request override pick a provider, e.g. based on cart currency).
     *   2. `tejcart_active_tax_provider` option (admin choice).
     *   3. Returns null when none is active — the cart falls through to the
     *      built-in `Tax_Manager` rate-table calculation.
     *
     * @return Abstract_Tax_Provider|null
     */
    public static function get_active(): ?Abstract_Tax_Provider {
        $providers = self::get_providers();
        if ( empty( $providers ) ) {
            self::debug( 'tax_registry', 'get_active: no providers registered (tejcart_tax_providers filter is empty) → returning null' );
            return null;
        }

        $configured = (string) get_option( 'tejcart_active_tax_provider', '' );

        /**
         * Filter the active tax provider ID.
         *
         * @param string                                $id        Configured provider ID.
         * @param array<string,Abstract_Tax_Provider>   $providers All registered providers.
         */
        $active_id = (string) apply_filters( 'tejcart_active_tax_provider', $configured, $providers );

        if ( '' === $active_id || ! isset( $providers[ $active_id ] ) ) {
            self::debug( 'tax_registry', 'get_active: no active provider configured or unknown id → returning null', array(
                'configured'      => $configured,
                'active_id'       => $active_id,
                'available_ids'   => array_keys( $providers ),
            ) );
            return null;
        }

        $provider = $providers[ $active_id ];
        if ( ! $provider->is_available() ) {
            self::debug( 'tax_' . $active_id, 'get_active: provider is registered but is_available() returned false → returning null' );
            return null;
        }

        self::debug( 'tax_' . $active_id, 'get_active: selected provider', array( 'id' => $active_id ) );
        return $provider;
    }

    /**
     * Emit a debug-level log line to a specific tax-channel file.
     *
     * Always-on — routes through `tejcart_tax_log()` which bypasses the
     * global `tejcart_log_level` so tax pipeline failures stay visible.
     */
    private static function debug( string $source, string $event, array $context = array() ): void {
        if ( ! function_exists( 'tejcart_tax_log' ) ) {
            return;
        }
        tejcart_tax_log( $source, $event, $context );
    }

    /**
     * Reset the cached registry. Test-only helper.
     */
    public static function reset(): void {
        self::$instances = null;
    }

    /**
     * Replace the cached provider registry. Test-substitution seam (#1242).
     *
     * Hands tests a way to inject fake providers without spinning up the
     * `tejcart_tax_providers` filter pipeline. Pass null to fall back to
     * lazy materialisation via the filter on the next `get_providers()`
     * call.
     *
     * @internal Use in tests and DI overrides only.
     * @param Abstract_Tax_Provider[]|null $instances Providers to install
     *   keyed by ID, or null to clear.
     */
    public static function set_instances( ?array $instances ): void {
        self::$instances = $instances;
    }
}
