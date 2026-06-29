<?php
/**
 * Carrier driver registry.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Carrier_Registry {
    /** @var array<string,Abstract_Carrier_Driver> */
    private array $drivers = array();

    public function register( Abstract_Carrier_Driver $driver ): void {
        $id = $driver->id();
        if ( '' === $id ) {
            throw new \InvalidArgumentException( 'Carrier driver must have a non-empty id().' );
        }
        if ( isset( $this->drivers[ $id ] ) ) {
            throw new \LogicException( sprintf( 'Carrier driver "%s" is already registered.', esc_html( $id ) ) );
        }
        $this->drivers[ $id ] = $driver;
    }

    public function get( string $id ): ?Abstract_Carrier_Driver {
        return $this->drivers[ $id ] ?? null;
    }

    public function has( string $id ): bool {
        return isset( $this->drivers[ $id ] );
    }

    /**
     * @return array<string,Abstract_Carrier_Driver>
     */
    public function all(): array {
        return $this->drivers;
    }

    /**
     * Return drivers grouped by their declared region for the admin UI.
     *
     * @return array<string,array<string,Abstract_Carrier_Driver>>
     */
    public function grouped_by_region(): array {
        $out = array();
        foreach ( $this->drivers as $id => $driver ) {
            $out[ $driver->region() ][ $id ] = $driver;
        }
        ksort( $out );
        return $out;
    }
}
