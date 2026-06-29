<?php
/**
 * Symmetric encryption helper for sensitive data at rest.
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encrypts/decrypts short strings (e.g. vault token IDs) using AES-256-GCM
 * with a self-managed, per-site random key.
 *
 * The key lives in the `tejcart_crypto_key` option (32 random bytes,
 * base64, autoload off) and is generated once on first use. It is
 * deliberately NOT derived from WordPress's AUTH_KEY / SECURE_AUTH_KEY /
 * wp_salt('auth'): rotating those WP secrets is routine security hygiene
 * (`wp config shuffle-salts`) and must not make stored payment-vault
 * tokens or PSP/tax credentials permanently undecryptable, nor invalidate
 * the deterministic lookup hashes built from {@see Crypto::hash()}.
 *
 * For backward compatibility, {@see Crypto::decrypt()} still falls back to
 * the older WP-salt-derived keys so any ciphertext written before the
 * managed key existed stays readable; the next save re-encrypts under the
 * managed key.
 *
 * Stored ciphertext format (base64): "tejc1:" . base64( iv | tag | ciphertext )
 * Values that don't carry the prefix are returned as-is by decrypt(), so
 * legacy plaintext rows continue to work until they are next written.
 */
class Crypto {
    const PREFIX = 'tejc1:';
    const CIPHER = 'aes-256-gcm';

    /**
     * Derive the 32-byte AES key via HKDF-SHA-256.
     *
     * The keying material is the rotation-stable, plugin-managed secret
     * from {@see Key_Manager} — NOT WordPress's `AUTH_KEY` / salts. This
     * decouples encrypted data from WP salt rotation: rotating WP salts
     * no longer makes stored ciphertext undecryptable (wp.org review).
     *
     * Only when no managed secret can be resolved (e.g. `get_option`
     * unavailable during very early boot) do we fall back to the legacy
     * WP-salt {@see Crypto::material()} — never an unstable per-request
     * key. Ciphertext written under the older derivations stays
     * decryptable via the layered fallback in {@see Crypto::decrypt()};
     * the next save re-wraps it under this key.
     *
     * @return string
     */
    private static function key(): string {
        $material = Key_Manager::is_available() ? Key_Manager::root_secret() : self::material();
        return hash_hkdf( 'sha256', $material, 32, 'tejcart|crypto|v2' );
    }

    /**
     * Legacy v1 key: the pre-{@see Key_Manager} primary, HKDF-derived
     * from the WP-salt {@see Crypto::material()}. Retained as a
     * decrypt-time fallback so ciphertext written before the managed-key
     * migration stays readable and is lazily re-wrapped on next save.
     *
     * @return string
     */
    private static function legacy_hkdf_key(): string {
        return hash_hkdf( 'sha256', self::material(), 32, 'tejcart|crypto|v1' );
    }

    /**
     * Decrypt-only fallback: the original SHA-256-of-concat key (pre-HKDF),
     * derived from the legacy WordPress-salt material.
     *
     * @return string
     */
    private static function legacy_key(): string {
        return hash( 'sha256', 'tejcart|crypto|v1|' . self::material(), true );
    }

    /**
     * Decrypt-only fallbacks reproducing the pre-1.0.1 Secret_Store key
     * schedule.
     *
     * Shipped 1.0.0 keyed Crypto off `\TejCart\Security\Secret_Store`, NOT
     * the WordPress salts, so a store updated from 1.0.0 must reproduce
     * that exact derivation or every value it encrypted (PayPal / PSP
     * credentials, saved-card vault tokens, REST API-key secrets) becomes
     * undecryptable — silently breaking payments after the update.
     * {@see Key_Manager} replaced Secret_Store but reads the SAME
     * `tejcart_secret_key` option with a different interpretation (the raw
     * stored string vs. the base64-decoded 32 bytes Secret_Store used) and
     * a renamed constant, so neither the managed {@see Crypto::key()} nor
     * the WP-salt fallbacks above can stand in for it.
     *
     * 1.0.0 derived:  hash_hkdf('sha256', $root, 32, 'tejcart|crypto|v1')
     * where $root was, in precedence order:
     *   1. hash('sha256', 'tejcart|root|v1|' . TEJCART_ENCRYPTION_KEY, true)
     *      when the (since renamed) TEJCART_ENCRYPTION_KEY constant was set;
     *   2. base64_decode( get_option('tejcart_secret_key') ) — the 32-byte
     *      auto-generated key (the zero-config default for every install).
     *
     * Both candidates are returned so a decrypt that already failed under
     * the current + WP-salt keys gets one more chance; trying an extra key
     * against a non-matching auth tag is harmless. The next save re-wraps
     * the value under {@see Crypto::key()}.
     *
     * @return list<string> Candidate 32-byte keys, most-likely first.
     */
    private static function secret_store_legacy_keys(): array {
        $roots = array();

        if ( defined( 'TEJCART_ENCRYPTION_KEY' ) && '' !== (string) constant( 'TEJCART_ENCRYPTION_KEY' ) ) {
            $roots[] = hash( 'sha256', 'tejcart|root|v1|' . (string) constant( 'TEJCART_ENCRYPTION_KEY' ), true );
        }

        if ( function_exists( 'get_option' ) ) {
            $stored = get_option( 'tejcart_secret_key', '' );
            if ( is_string( $stored ) && '' !== $stored ) {
                $raw = base64_decode( $stored, true );
                if ( false !== $raw && 32 === strlen( $raw ) ) {
                    $roots[] = $raw;
                }
            }
        }

        $keys = array();
        foreach ( $roots as $root ) {
            $keys[] = hash_hkdf( 'sha256', $root, 32, 'tejcart|crypto|v1' );
        }
        return $keys;
    }

    /**
     * Legacy WP-salt keying material (`AUTH_KEY` + `SECURE_AUTH_KEY`,
     * falling back to `wp_salt('auth')`).
     *
     * No longer the primary encryption key source — {@see Crypto::key()}
     * now derives from the rotation-stable {@see Key_Manager}. This
     * remains only for (a) decrypting pre-migration ciphertext via
     * {@see Crypto::legacy_hkdf_key()} / {@see Crypto::legacy_key()} and
     * (b) the deterministic lookup index in {@see Crypto::hash()}, whose
     * preimages are already high-entropy so the keying is defence-in-depth.
     *
     * @return string
     */
    private static function material(): string {
        $material = '';
        if ( defined( 'AUTH_KEY' ) ) {
            $material .= AUTH_KEY;
        }
        if ( defined( 'SECURE_AUTH_KEY' ) ) {
            $material .= SECURE_AUTH_KEY;
        }
        if ( '' === $material ) {
            $material = wp_salt( 'auth' );
        }
        return $material;
    }

    /**
     * Encrypt a string.
     *
     * When the openssl extension is loaded but encryption
     * fails at runtime (random_bytes throws, openssl_encrypt returns
     * false), this method now throws a {@see Crypto_Exception} instead
     * of returning the plaintext. Plaintext-fallback for vault tokens
     * is not a safe graceful-degradation pattern: an attacker who can
     * degrade the host's OpenSSL build (memory pressure, broken FIPS
     * config) would otherwise force the next saved card or PSP token
     * to be persisted in the clear.
     *
     * The "openssl extension entirely unavailable" path still returns
     * the plaintext unchanged: refusing to start on hosts without
     * openssl would brick legitimate deployments on stripped-down
     * shared hosting that don't store secrets at rest in the first
     * place. Callers persisting NON-sensitive values (display
     * strings, non-secret tokens) can keep using this method.
     * Callers persisting payment / authentication secrets MUST use
     * {@see Crypto::encrypt_required()} so a no-openssl host fails
     * closed instead of degrading silently. Callers that handle
     * sensitive material MUST verify the return value carries
     * {@see Crypto::PREFIX} before persisting.
     *
     * @param string $plaintext Plain text value.
     * @return string
     * @throws Crypto_Exception When encryption fails at runtime.
     */
    public static function encrypt( string $plaintext ): string {
        if ( '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
            return $plaintext;
        }
        if ( self::is_encrypted( $plaintext ) ) {
            return $plaintext;
        }

        try {
            $iv = random_bytes( 12 );
        } catch ( \Throwable $e ) {
            // $abort = true: we are about to throw — no plaintext is stored.
            self::log_failure( 'random_bytes failed: ' . esc_html( $e->getMessage() ), true );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Crypto_Exception( 'Could not generate IV for encryption: ' . esc_html( $e->getMessage() ), 0, $e );
        }

        $tag = '';
        $ct  = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $ct ) {
            // $abort = true: we are about to throw — no plaintext is stored.
            self::log_failure( 'openssl_encrypt returned false', true );
            throw new Crypto_Exception( 'openssl_encrypt failed; refusing to persist sensitive value as plaintext.' );
        }

        return self::PREFIX . base64_encode( $iv . $tag . $ct );
    }

    /**
     * Encrypt a sensitive string — fail-closed path.
     *
     * Identical to {@see Crypto::encrypt()} except that when the
     * openssl extension is unavailable on the host, this method
     * throws {@see Crypto_Exception} instead of returning the
     * plaintext. Use this for payment-vault tokens, gateway-
     * webhook secrets, tax-provider API keys, and any other value
     * whose plaintext storage would be a PCI/PII violation. See
     * review finding H-5.
     *
     * Callers should catch Crypto_Exception and surface a hard
     * configuration error to ops (admin notice, alert hook) so the
     * host gets fixed before more sensitive values are accepted.
     *
     * @param string $plaintext Plain text value.
     * @return string Ciphertext (carrying the {@see Crypto::PREFIX} marker).
     * @throws Crypto_Exception When the openssl extension is missing or runtime encryption fails.
     */
    public static function encrypt_required( string $plaintext ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            // $abort = true: we are about to throw — no plaintext is stored.
            self::log_failure( 'openssl extension unavailable; refusing to store sensitive value as plaintext', true );
            throw new Crypto_Exception(
                'openssl extension is not available on this host; sensitive value cannot be safely persisted.'
            );
        }

        // Empty input: nothing to encrypt, but also nothing
        // sensitive to leak. Return empty rather than throwing so
        // upstream `if ( '' !== $value )` guards stay simple.
        if ( '' === $plaintext ) {
            return '';
        }

        $cipher = self::encrypt( $plaintext );

        // Defence-in-depth: if encrypt() somehow returned plaintext
        // (it shouldn't, given the openssl_encrypt check above, but
        // a future code-path change could regress), fail closed.
        if ( ! self::is_encrypted( $cipher ) ) {
            // $abort = true: we are about to throw — no plaintext is stored.
            self::log_failure( 'encrypt_required produced non-ciphertext; refusing to return plaintext', true );
            throw new Crypto_Exception(
                'Encryption produced a non-ciphertext result; refusing to return plaintext.'
            );
        }

        return $cipher;
    }

    /**
     * Surface a runtime encryption or decryption failure to operator logs.
     *
     * F-SEC-003: the log suffix is now context-sensitive.
     *   $abort = true  → the caller is about to throw; the value is never
     *                    stored.  Suffix: "— operation aborted, exception thrown."
     *   $abort = false → the caller fell back to returning plaintext (only the
     *                    openssl-absent path of encrypt()). Suffix: "— value
     *                    stored unencrypted."
     *
     * Passing the correct $abort flag prevents the misleading "stored
     * unencrypted" message from appearing in the payment-channel log when
     * encrypt_required() or decrypt() rejects the operation and throws/returns.
     *
     * @param string $message Human-readable failure detail.
     * @param bool   $abort   True when the operation is being aborted (no plaintext storage).
     * @return void
     */
    private static function log_failure( string $message, bool $abort = false ): void {
        $suffix = $abort
            ? ' — operation aborted, exception thrown.'
            : ' — value stored unencrypted.';

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log( 'Crypto: ' . $message . $suffix, 'error' );
        }
        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires whenever a Crypto operation fails at runtime.
             *
             * When $abort is false the failure caused a plaintext fallback;
             * when $abort is true the operation was refused and no plaintext
             * was stored.  Listeners should alert ops regardless.
             *
             * @param string $message Human-readable failure detail.
             * @param bool   $abort   Whether the operation was aborted (no storage).
             */
            do_action( 'tejcart_crypto_failure', $message, $abort );
        }
    }

    /**
     * Decrypt a value previously produced by encrypt(). Plain (legacy) input
     * passes through unchanged.
     *
     * @param string $value Stored value.
     * @return string
     */
    public static function decrypt( string $value ): string {
        // Legacy plaintext (no PREFIX) passes through unchanged — that's
        // the documented migration path for rows that pre-date encryption.
        if ( ! self::is_encrypted( $value ) ) {
            return $value;
        }
        // openssl extension unavailable: refuse to leak the prefixed
        // ciphertext to callers (which would otherwise concatenate it
        // into HTTP headers / logs). Return empty rather than throwing
        // to keep callers that compare against '' working.
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            // $abort = true: operation aborted, nothing is stored.
            self::log_failure( 'openssl_decrypt unavailable; cannot decrypt sensitive value', true );
            return '';
        }

        $raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
        if ( false === $raw || strlen( $raw ) < 28 ) {
            // Malformed envelope — fail closed (M-11). Previously returned
            // the original prefixed string, which would then leak as a
            // pseudo-credential into HTTP Basic auth and outbound logs.
            // $abort = true: we return '' — nothing plaintext is stored.
            self::log_failure( 'malformed ciphertext envelope; refusing to surface ciphertext to caller', true );
            return '';
        }

        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $ct  = substr( $raw, 28 );

        // Try the current managed-secret key first, then the layered
        // legacy fallbacks so older ciphertext stays readable across the
        // key migration: (1) managed HKDF (current), (2) WP-salt HKDF
        // (pre-managed primary), (3) WP-salt SHA-256 (pre-HKDF). The next
        // save re-wraps under the current key (encrypt() always uses key()).
        $pt = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $pt ) {
            $pt = openssl_decrypt( $ct, self::CIPHER, self::legacy_hkdf_key(), OPENSSL_RAW_DATA, $iv, $tag );
        }
        if ( false === $pt ) {
            $pt = openssl_decrypt( $ct, self::CIPHER, self::legacy_key(), OPENSSL_RAW_DATA, $iv, $tag );
        }
        // (4) Pre-1.0.1 Secret_Store-derived keys: a store updated from
        // 1.0.0 keyed its at-rest secrets off Secret_Store, which neither
        // key() nor the WP-salt fallbacks reproduce. Try those candidates
        // before failing closed so PayPal/PSP credentials and saved-card
        // vault tokens survive the update. See secret_store_legacy_keys().
        if ( false === $pt ) {
            foreach ( self::secret_store_legacy_keys() as $legacy_root_key ) {
                $pt = openssl_decrypt( $ct, self::CIPHER, $legacy_root_key, OPENSSL_RAW_DATA, $iv, $tag );
                if ( false !== $pt ) {
                    break;
                }
            }
        }
        if ( false === $pt ) {
            // Auth tag mismatch — either the salts rotated under us or
            // the row was tampered with. In either case the previous
            // behaviour (return $value) leaked the prefixed ciphertext
            // into outbound contexts (PayPal Basic auth, sentry, error
            // logs). Fail closed instead (M-11).
            // $abort = true: we return '' — nothing plaintext is stored.
            self::log_failure( 'decrypt auth-tag mismatch (key rotated or tampered ciphertext)', true );
            return '';
        }

        return $pt;
    }

    /**
     * Check whether a stored value is in the TejCart ciphertext format.
     *
     * @param string $value Stored value.
     * @return bool
     */
    public static function is_encrypted( string $value ): bool {
        if ( 0 !== strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) ) {
            return false;
        }
        // Defence-in-depth: also require the base64 envelope to decode and
        // be at least IV (12) + GCM tag (16) bytes long, otherwise a
        // plaintext that happens to start with the prefix would be treated
        // as already-encrypted and skip the encrypt/decrypt path (I-1).
        $raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
        return false !== $raw && strlen( $raw ) >= 28;
    }

    /**
     * Deterministic hash for indexing/lookup of an encrypted value (so we
     * can find a row by its plaintext without decrypting every record).
     *
     * Keyed from the self-managed material (via a purpose-labelled HKDF
     * subkey) so the persisted lookup hashes survive WordPress salt
     * rotation. Both the write side and the read side call this method, so
     * the index stays internally consistent.
     *
     * @param string $plaintext Plain text value.
     * @return string
     */
    public static function hash( string $plaintext ): string {
        $key = hash_hkdf( 'sha256', self::material(), 32, 'tejcart|crypto|hash|v1' );
        return hash_hmac( 'sha256', $plaintext, $key );
    }

    /**
     * HKDF-derived deterministic index hash for NEW call sites.
     *
     * Audit #53 / 04 M-1 — closes the hardening opportunity called
     * out in the docblock of {@see Crypto::hash()}: existing indices
     * must keep using `hash()` for schema stability, but new
     * deterministic indices should be keyed from the HKDF-derived
     * key + a per-purpose label so different domains (e.g. a
     * customer-id index vs. a token index) don't share the same
     * deterministic preimage even when their plaintexts coincide.
     *
     * @param string $plaintext Plain text value.
     * @param string $purpose   Per-call-site label, e.g. 'order-token'.
     *                          Mixed into the HKDF info so the same
     *                          plaintext under different purposes
     *                          hashes to different values.
     * @return string Hex digest.
     */
    public static function hash_v2( string $plaintext, string $purpose ): string {
        $info = 'tejcart|hash_v2|' . $purpose;
        $key  = hash_hkdf( 'sha256', self::material(), 32, $info );
        return hash_hmac( 'sha256', $plaintext, $key );
    }
}
