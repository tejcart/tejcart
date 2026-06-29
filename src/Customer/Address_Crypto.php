<?php
/**
 * Address-at-rest encryption helper.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Security\Crypto;
use TejCart\Security\Crypto_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts customer postal addresses (billing / shipping JSON blobs)
 * for at-rest protection.
 *
 * Off by default for backward compatibility. Merchants opt in via the
 * `tejcart_encrypt_addresses` filter (recommended for stores subject
 * to GDPR Article 32 "appropriate technical and organisational
 * measures" — encrypting non-PAN PII at rest is the canonical
 * interpretation).
 *
 *     add_filter( 'tejcart_encrypt_addresses', '__return_true' );
 *
 * Once enabled:
 *
 * - Every write that goes through {@see self::encode()} stores the
 *   AES-256-GCM ciphertext (prefix `tejc1:`) in `wp_tejcart_orders`
 *   `billing_address` / `shipping_address` and `wp_tejcart_addresses`
 *   `address` columns.
 * - Every read through {@see self::decode()} transparently decrypts.
 * - Legacy plaintext rows are passed through unchanged on read; they
 *   are upgraded to ciphertext the next time they are written.
 * - Operators can run `wp tejcart addresses encrypt` (defined in
 *   `src/CLI/Address_Encryption_CLI.php`) to retro-encrypt every
 *   existing row in-place. The command is idempotent and resumable.
 *
 * Threat model: protects against database-only compromise (stolen
 * SQL dumps, leaked backups, replicated read replicas). Does **not**
 * protect against host OS compromise (the encryption key derives from
 * `AUTH_KEY` / `SECURE_AUTH_KEY` which live in `wp-config.php` on the
 * same host). For host-OS-grade protection, store keys in a KMS or
 * Vault and re-derive them through {@see Crypto::material()}.
 *
 * @since 1.4.0
 */
final class Address_Crypto {
	/**
	 * Per-request cache of the `tejcart_encrypt_addresses` filter
	 * result. Null means "not yet resolved this request".
	 *
	 * @var bool|null
	 */
	private static ?bool $enabled_cache = null;

	/**
	 * Whether address encryption is enabled for this request.
	 *
	 * Result is filtered through `tejcart_encrypt_addresses`. Cached
	 * per request because `apply_filters()` walks every registered
	 * callback on every invocation; addresses are touched at every
	 * checkout step and on every order admin render, so caching once
	 * matters.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( null === self::$enabled_cache ) {
			/**
			 * Filter whether to encrypt customer addresses at rest.
			 *
			 * Audit M-36 (Product F-008): default changed from false to
			 * true. GDPR Art. 32 requires appropriate technical measures
			 * for PII at rest; address encryption is the baseline.
			 * Merchants can still opt OUT via:
			 *   add_filter( 'tejcart_encrypt_addresses', '__return_false' );
			 *
			 * @param bool $enabled Defaults to true.
			 */
			self::$enabled_cache = (bool) apply_filters( 'tejcart_encrypt_addresses', true );
		}
		return self::$enabled_cache;
	}

	/**
	 * Reset the per-request enabled cache. For tests that flip the
	 * filter value between cases — production never needs this
	 * because the cache lives only for the duration of one request.
	 */
	public static function reset_cache(): void {
		self::$enabled_cache = null;
	}

	/**
	 * Encode an address for storage.
	 *
	 * If encryption is enabled and the input is non-empty plaintext,
	 * returns the AES-256-GCM ciphertext (prefix `tejc1:`). If the
	 * input is already encrypted (e.g. round-tripped from another
	 * encrypted column), returns it unchanged. If encryption is
	 * disabled, returns the plaintext unchanged so existing rows and
	 * code paths keep working.
	 *
	 * @param string $plaintext The serialised address (typically
	 *                          JSON, but the helper is agnostic).
	 * @return string Encoded value ready for the DB column.
	 *
	 * @throws Crypto_Exception If encryption is enabled but the
	 *                          underlying openssl/random_bytes call
	 *                          fails. Callers must handle this — we
	 *                          fail closed rather than silently
	 *                          downgrade to plaintext.
	 */
	public static function encode( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! self::enabled() ) {
			return $plaintext;
		}
		if ( Crypto::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}
		return Crypto::encrypt_required( $plaintext );
	}

	/**
	 * Decode an address from storage.
	 *
	 * Always attempts to decrypt — works regardless of whether
	 * encryption is currently enabled, because legacy ciphertext
	 * needs to keep round-tripping after a merchant disables the
	 * feature mid-life. Plaintext input passes through unchanged.
	 *
	 * Decryption failures (corrupted ciphertext, key rotation
	 * without grace-window) return the empty string so the calling
	 * site renders an empty-but-valid address rather than crashing
	 * the order admin.
	 *
	 * @param string $stored The value read from the DB column.
	 * @return string Plaintext (typically the original JSON).
	 */
	public static function decode( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! Crypto::is_encrypted( $stored ) ) {
			return $stored;
		}
		try {
			return Crypto::decrypt( $stored );
		} catch ( Crypto_Exception $e ) {
			return '';
		}
	}

	/**
	 * Whether a stored address value is ciphertext.
	 *
	 * Convenience wrapper over {@see Crypto::is_encrypted()} so call
	 * sites don't need to know the prefix shape.
	 *
	 * @param string $value Raw stored value.
	 * @return bool
	 */
	public static function is_encoded( string $value ): bool {
		return Crypto::is_encrypted( $value );
	}
}
