<?php
/**
 * Per-carrier enable/disable state.
 *
 * Decouples "is this carrier configured?" (credentials present in
 * Credentials_Vault) from "is this carrier turned on?" (merchant has
 * flipped the toggle). The admin list view exposes a toggle per carrier
 * and the shipping rate path consults this store before fanning out
 * services, so a merchant can save credentials, pause the carrier from
 * the cart calculation, and turn it back on later without losing keys.
 *
 * Stored as a single option (`tejcart_shipping_carriers_enabled`) keyed
 * by driver id, so a typical install reads one row regardless of the
 * number of carriers in play.
 *
 * @package TejCart\Shipping_Plugin\Admin
 */

namespace TejCart\Shipping_Plugin\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carrier_State {
    public const OPTION = 'tejcart_shipping_carriers_enabled';

    /**
     * Whether the carrier has been enabled by the merchant. Defaults to
     * false — a carrier is opt-in even after credentials are saved, so
     * an accidental key paste doesn't put live rates in front of
     * shoppers.
     */
    public function is_enabled( string $driver_id ): bool {
        $map = $this->read();
        return ! empty( $map[ $driver_id ] );
    }

    /**
     * Set the enabled flag and persist.
     */
    public function set_enabled( string $driver_id, bool $enabled ): void {
        $map = $this->read();
        if ( $enabled ) {
            $map[ $driver_id ] = true;
        } else {
            unset( $map[ $driver_id ] );
        }
        update_option( self::OPTION, $map, false );
    }

    /**
     * @return array<string,bool>
     */
    public function all(): array {
        return $this->read();
    }

    /**
     * @return array<string,bool>
     */
    private function read(): array {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $key => $value ) {
            $out[ (string) $key ] = (bool) $value;
        }
        return $out;
    }
}
