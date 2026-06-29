<?php
/**
 * Carrier credential storage.
 *
 * Credentials are persisted in a single WordPress option, keyed by
 * driver id. Fields whose schema declares `secret => true` are
 * encrypted at rest using the TejCart core AES-256-GCM helper. We
 * don't reach for a custom crypto implementation — the core helper
 * already derives keys from WP salts and is exercised by the core
 * test suite.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Credentials_Vault {
    public const OPTION = 'tejcart_shipping_credentials';

    /**
     * Return the decrypted credential map for a driver.
     *
     * @return array<string,string>
     */
    public function get( string $driver_id ): array {
        $all = get_option( self::OPTION, array() );
        if ( ! is_array( $all ) || ! isset( $all[ $driver_id ] ) || ! is_array( $all[ $driver_id ] ) ) {
            return array();
        }

        $out = array();
        foreach ( $all[ $driver_id ] as $key => $value ) {
            $out[ (string) $key ] = $this->maybe_decrypt( (string) $value );
        }
        return $out;
    }

    /**
     * Persist a driver's credentials, encrypting any field listed in
     * the secret-fields whitelist.
     *
     * @param array<string,string> $values        Plaintext values keyed by field id.
     * @param string[]             $secret_fields Field ids whose values should be encrypted.
     */
    public function put( string $driver_id, array $values, array $secret_fields = array() ): void {
        $all = get_option( self::OPTION, array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $secret_lookup = array_flip( $secret_fields );
        $row           = array();

        foreach ( $values as $key => $value ) {
            $string = (string) $value;
            $row[ (string) $key ] = isset( $secret_lookup[ $key ] )
                ? $this->encrypt( $string )
                : $string;
        }

        $all[ $driver_id ] = $row;
        update_option( self::OPTION, $all, false );
    }

    public function forget( string $driver_id ): void {
        $all = get_option( self::OPTION, array() );
        if ( ! is_array( $all ) || ! isset( $all[ $driver_id ] ) ) {
            return;
        }
        unset( $all[ $driver_id ] );
        update_option( self::OPTION, $all, false );
    }

    private function encrypt( string $plaintext ): string {
        if ( '' === $plaintext ) {
            return '';
        }
        if ( ! class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            // The shipping module persists carrier API keys / secrets.
            // Silently falling back to plaintext storage would leak
            // those credentials into wp_options on any site that hadn't
            // yet loaded TejCart core's Crypto helper. We treat the
            // missing dependency as a hard error so the merchant sees
            // the misconfiguration immediately rather than discovering
            // it in a breach post-mortem.
            //
            // Filterable for tests / niche deployments that genuinely
            // want plaintext (returning true keeps the legacy behaviour).
            $allow_plaintext = function_exists( 'apply_filters' )
                ? (bool) apply_filters( 'tejcart_shipping_allow_plaintext_credentials', false )
                : false;
            if ( ! $allow_plaintext ) {
                throw new Carrier_Exception(
                    'TejCart Shipping: cannot persist carrier credentials securely — '
                    . 'TejCart core (\\TejCart\\Security\\Crypto) is not available. '
                    . 'Ensure TejCart core is active and up to date before saving carrier settings.'
                );
            }
            return $plaintext;
        }
        return \TejCart\Security\Crypto::encrypt( $plaintext );
    }

    private function maybe_decrypt( string $value ): string {
        if ( '' === $value ) {
            return '';
        }
        if ( class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            return \TejCart\Security\Crypto::decrypt( $value );
        }
        // Decryption tolerates a missing Crypto class (the stored value
        // may legitimately be plaintext from a pre-1.0 install or from
        // the `allow_plaintext_credentials` opt-out above). Writes are
        // the dangerous path; reads only need to round-trip what we
        // already wrote.
        return $value;
    }
}
