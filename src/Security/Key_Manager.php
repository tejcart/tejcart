<?php
/**
 * Rotation-stable secret manager for at-rest crypto and persistent hashing.
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of plugin-managed key material.
 *
 * The purpose of this class is to DECOUPLE TejCart's long-lived secrets
 * (encrypted gateway credentials, saved payment tokens, signed download
 * links, REST API-key hashes) from WordPress's authentication constants
 * (`AUTH_KEY` / `SECURE_AUTH_KEY` / `wp_salt()`).
 *
 * Those WP secrets are rotated by routine hardening tools and by every
 * "reset salts" action. Keying persistent data off them means a rotation
 * silently invalidates API credentials and makes encrypted data
 * undecryptable (wp.org review: "salt/key rotation can invalidate API
 * credentials and make stored secrets undecryptable"). Instead we
 * generate ONE 256-bit random root secret on first use, store it in an
 * autoload=no option, and hand out domain-separated subkeys via HKDF.
 * The root secret is never derived from — and never invalidated by — WP
 * salt rotation.
 *
 * Resolution order for the root secret:
 *   1. `TEJCART_SECRET_KEY` constant (wp-config.php) — operator override.
 *   2. `tejcart_secret_key` option (autoload=no) — auto-generated once, stable.
 *   3. `tejcart_secret_key` filter — KMS / HashiCorp Vault integration point.
 *
 * Callers keep the previous WP-salt derivation as a decrypt/verify-time
 * fallback so existing data migrates forward with no loss — see
 * {@see Crypto::decrypt()} and {@see \TejCart\API\API_Keys}.
 */
final class Key_Manager {
    /** Option that stores the auto-generated root secret (autoload=no). */
    public const OPTION_KEY = 'tejcart_secret_key';

    /** Optional wp-config.php constant override. */
    public const CONSTANT = 'TEJCART_SECRET_KEY';

    /**
     * Per-request memo so subkey() does not re-read the option on every call.
     *
     * @var string|null
     */
    private static ?string $root = null;

    /**
     * Resolve the rotation-stable root secret.
     *
     * @return string High-entropy secret string (stable across requests).
     */
    public static function root_secret(): string {
        if ( null !== self::$root ) {
            return self::$root;
        }

        // 1. wp-config constant — highest precedence, never touches the DB.
        if ( defined( self::CONSTANT ) ) {
            $const = (string) constant( self::CONSTANT );
            if ( '' !== $const ) {
                self::$root = $const;
                return self::$root;
            }
        }

        // 2. Stored option, auto-generated and persisted on first use.
        $stored = '';
        if ( function_exists( 'get_option' ) ) {
            $opt    = get_option( self::OPTION_KEY, '' );
            $stored = is_string( $opt ) ? $opt : '';
        }

        if ( '' === $stored ) {
            $stored = self::generate();
            if ( function_exists( 'update_option' ) ) {
                // autoload=no: the secret must never ride in the alloptions
                // cache nor in autoload payloads exposed to other code.
                update_option( self::OPTION_KEY, $stored, false );
            }
        }

        /**
         * Filter the root secret — KMS / HashiCorp Vault integration point.
         *
         * Return a STABLE high-entropy string. It MUST NOT change between
         * requests, or previously-stored ciphertext becomes undecryptable.
         *
         * @param string $stored The resolved root secret.
         */
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = (string) apply_filters( 'tejcart_secret_key', $stored );
            if ( '' !== $filtered ) {
                $stored = $filtered;
            }
        }

        self::$root = $stored;
        return self::$root;
    }

    /**
     * Whether a STABLE managed secret can be resolved this request.
     *
     * When false (e.g. `get_option`/`update_option` unavailable during very
     * early boot), callers MUST fall back to the legacy WP-salt derivation
     * rather than mint an unstable per-request key that would write
     * undecryptable data.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if ( defined( self::CONSTANT ) && '' !== (string) constant( self::CONSTANT ) ) {
            return true;
        }
        return function_exists( 'get_option' ) && function_exists( 'update_option' );
    }

    /**
     * Derive a domain-separated raw subkey via HKDF-SHA-256.
     *
     * @param string $context Domain-separation label, e.g. 'tejcart|crypto|v2'.
     * @param int    $length  Output length in bytes (default 32, for AES-256).
     * @return string Raw key bytes.
     */
    public static function subkey( string $context, int $length = 32 ): string {
        return hash_hkdf( 'sha256', self::root_secret(), $length, $context );
    }

    /**
     * Derive a domain-separated HMAC key as a hex string, for call sites
     * that want an HMAC key rather than raw cipher-key bytes.
     *
     * @param string $context Domain-separation label.
     * @return string 64-char hex key.
     */
    public static function hmac_key( string $context ): string {
        return bin2hex( self::subkey( $context, 32 ) );
    }

    /**
     * Generate a fresh 256-bit root secret as a 64-char hex string.
     *
     * @return string
     */
    private static function generate(): string {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $e ) {
            // CSPRNG unavailable — extremely rare. Fall back to a
            // high-entropy WP password hash so we never store a weak key.
            if ( function_exists( 'wp_generate_password' ) ) {
                return hash( 'sha256', wp_generate_password( 64, true, true ) );
            }
            return hash( 'sha256', uniqid( '', true ) );
        }
    }

    /**
     * Reset the per-request memo. Test-only — production resolves once per
     * request and never needs to clear the cache.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$root = null;
    }
}
