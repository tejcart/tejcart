<?php
/**
 * TejCart Simple DI Container
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A simple dependency injection container.
 *
 * Supports binding factories, singleton factories, and resolving instances.
 */
class Container {
    /**
     * Registered factory bindings.
     *
     * @var array<string, callable>
     */
    private $bindings = array();

    /**
     * Resolved singleton instances.
     *
     * @var array<string, mixed>
     */
    private $instances = array();

    /**
     * Track which bindings are singletons.
     *
     * @var array<string, bool>
     */
    private $singletons = array();

    /**
     * Track which abstracts are currently being resolved (cycle detection).
     *
     * @var array<string, bool>
     */
    private $resolving = array();

    /**
     * Register a factory binding.
     *
     * Each call to make() will invoke the factory and return a new instance.
     *
     * @param string   $abstract The abstract identifier.
     * @param callable $concrete A factory callable that returns the instance.
     */
    public function bind( $abstract, $concrete ) {
        $this->bindings[ $abstract ] = $concrete;
        unset( $this->singletons[ $abstract ], $this->instances[ $abstract ] );
    }

    /**
     * Register a singleton factory binding.
     *
     * The factory is called once; subsequent calls to make() return the same instance.
     *
     * @param string   $abstract The abstract identifier.
     * @param callable $concrete A factory callable that returns the instance.
     */
    public function singleton( $abstract, $concrete ) {
        $this->bindings[ $abstract ]  = $concrete;
        $this->singletons[ $abstract ] = true;
        unset( $this->instances[ $abstract ] );
    }

    /**
     * Resolve and return an instance for the given abstract.
     *
     * @param string $abstract The abstract identifier.
     * @return mixed The resolved instance.
     *
     * @throws \RuntimeException If the abstract has not been bound.
     */
    public function make( $abstract ) {
        if ( isset( $this->instances[ $abstract ] ) ) {
            return $this->instances[ $abstract ];
        }

        if ( ! isset( $this->bindings[ $abstract ] ) ) {
            throw new \RuntimeException(
                sprintf( 'No binding registered for "%s" in the TejCart container.', (string) $abstract )
            );
        }

        if ( isset( $this->resolving[ $abstract ] ) ) {
            throw new \RuntimeException(
                sprintf( 'Circular dependency detected while resolving "%s".', (string) $abstract )
            );
        }

        $this->resolving[ $abstract ] = true;

        try {
            $concrete = $this->bindings[ $abstract ];
            $instance = $concrete( $this );
        } finally {
            // F-CORE-002: unset unconditionally so a factory exception does
            // not permanently brick the circular-dependency guard for this
            // abstract on subsequent make() calls in the same request.
            unset( $this->resolving[ $abstract ] );
        }

        if ( isset( $this->singletons[ $abstract ] ) ) {
            $this->instances[ $abstract ] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a binding exists for the given abstract.
     *
     * @param string $abstract The abstract identifier.
     * @return bool True if a binding exists.
     */
    public function has( $abstract ) {
        return isset( $this->bindings[ $abstract ] ) || isset( $this->instances[ $abstract ] );
    }

    /**
     * Check if a singleton binding has already been resolved (instantiated).
     *
     * @param string $abstract The abstract identifier.
     * @return bool True if the singleton has been instantiated.
     */
    public function resolved( $abstract ) {
        return isset( $this->instances[ $abstract ] );
    }
}
