<?php
/**
 * Recursive PII / secrets redactor for log contexts.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursive scrubber for log context arrays.
 *
 * Replaces values whose keys match a sensitive pattern (passwords, API
 * keys, tokens, card data, customer PII) with the sentinel `[REDACTED]`,
 * and rewrites Authorization-style header strings so the credential
 * portion never reaches disk. Long string values are truncated to keep
 * log lines bounded.
 *
 * The redaction key list is filterable via `tejcart_logging_redact_keys`
 * so merchants and add-ons can extend it without monkey-patching this
 * class.
 */
final class Redactor {

	public const SENTINEL = '[REDACTED]';

	/** Maximum string length kept in a log entry before truncation. */
	public const MAX_STRING_LENGTH = 2048;

	/** Default key fragments that always get redacted (case-insensitive). */
	private const DEFAULT_KEYS = array(
		'password',
		'passwd',
		'secret',
		'api_key',
		'apikey',
		'access_token',
		'refresh_token',
		'auth_token',
		'authorization',
		'bearer',
		'client_secret',
		'private_key',
		'webhook_secret',
		'signing_key',
		// Payment-method PII / PCI fields. Substring matching keeps
		// these surgical — we redact "card_number" / "cardnumber" /
		// "card_pin" but explicitly preserve "card_brand" / "card_last4"
		// (PCI-safe and high-signal for support).
		'card_number',
		'cardnumber',
		'card_pin',
		'card_pan',
		'card_holder',
		'cardholder_name',
		'name_on_card',
		'cvc',
		'cvv',
		'cvv2',
		'card_cvc',
		'security_code',
		'exp_month',
		'exp_year',
		'card_expiry',
		'expiration_date',
		'track1',
		'track2',
		'track_data',
		'pin_block',
		// PII / banking.
		'ssn',
		'tax_id',
		'iban',
		'swift',
		'bic',
		'routing_number',
		'aba',
		'account_number',
		'bank_account',
		// PayPal vault / Stripe / Authorize.Net opaque tokens that
		// substitute for a card number in transit. Treat as secrets so
		// a leak of the log never re-enables an old session.
		'setup_token',
		'vault_id',
		'payment_method_id',
		'payment_token',
		'opaque_data',
		'data_value',
		'data_descriptor',
		'nonce_value',
		'three_ds_session_id',
		// Buyer PII. Substring-matched, so `email` covers
		// `customer_email`/`user_email`/`billing_email`, `phone` covers
		// `billing_phone`/`shipping_phone`, `address` covers
		// `billing_address`/`shipping_address`/`address_1`/`address_2`,
		// etc. `state` is intentionally NOT in the list (would clobber
		// `order_state`/`payment_state`); region names rarely show up
		// in logs anyway.
		'email',
		'phone',
		'address',
		'street',
		'postcode',
		'postal_code',
		'zip_code',
		'city',
		// IP fields — only the precise variants. Bare `ip` collides with
		// `description`/`recipient`/`subscription`/etc., so we list the
		// specific keys that actually carry an IP address.
		'ip_address',
		'remote_addr',
		'client_ip',
		'user_ip',
		'remote_ip',
		'x_forwarded_for',
	);

	/**
	 * Redact a context array in-place, returning a new array.
	 *
	 * @param array<string, mixed> $context    Untrusted log context.
	 * @param int                  $max_length Maximum length kept per string
	 *                                         value. 0 disables truncation
	 *                                         (used for full-fidelity debug
	 *                                         logging). Defaults to the 2KB
	 *                                         {@see MAX_STRING_LENGTH} guard.
	 * @return array<string, mixed>
	 */
	public static function redact( array $context, int $max_length = self::MAX_STRING_LENGTH ): array {
		$keys = self::sensitive_keys();
		return self::walk( $context, $keys, $max_length );
	}

	/**
	 * Internal recursive walker.
	 *
	 * @param array<int|string, mixed> $data       Untrusted data.
	 * @param array<int, string>       $keys       Lower-cased sensitive key fragments.
	 * @param int                      $max_length Per-string truncation cap (0 = no cap).
	 * @return array<int|string, mixed>
	 */
	private static function walk( array $data, array $keys, int $max_length = self::MAX_STRING_LENGTH ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::key_is_sensitive( $key, $keys ) ) {
				$out[ $key ] = self::SENTINEL;
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::walk( $value, $keys, $max_length );
				continue;
			}
			if ( is_string( $value ) ) {
				$out[ $key ] = self::scrub_string( $value, $max_length );
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
				continue;
			}
			// Objects / resources — coerce to a short marker so wp_json_encode
			// never balks at a circular reference.
			$out[ $key ] = '[' . ( is_object( $value ) ? get_class( $value ) : gettype( $value ) ) . ']';
		}
		return $out;
	}

	/**
	 * Check whether a key name matches any sensitive fragment.
	 *
	 * @param string             $name Key under inspection.
	 * @param array<int, string> $keys Sensitive fragments (lower case).
	 */
	private static function key_is_sensitive( string $name, array $keys ): bool {
		$lower = strtolower( $name );
		foreach ( $keys as $fragment ) {
			if ( '' !== $fragment && false !== strpos( $lower, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scrub a string value: redact Authorization-style headers and likely
	 * PANs, then truncate to a sane upper bound.
	 *
	 * @param string $value      Untrusted string value.
	 * @param int    $max_length Maximum length to keep. 0 disables truncation
	 *                           entirely (used for full-fidelity debug logs).
	 *                           Defaults to the 2KB {@see MAX_STRING_LENGTH}
	 *                           guard applied to production log levels.
	 */
	public static function scrub_string( string $value, int $max_length = self::MAX_STRING_LENGTH ): string {
		if ( '' === $value ) {
			return $value;
		}

		// Bearer / Basic auth headers — keep the scheme, redact the credential.
		$value = (string) preg_replace(
			'/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._\-+\/=]+/i',
			'$1 ' . self::SENTINEL,
			$value
		);

		// PAN-like 13-19 digit runs (allowing common separators). Mask
		// everything except the last four digits — same posture as PCI
		// truncated display.
		$value = (string) preg_replace_callback(
			'/\b(?:\d[ \-]?){12,18}\d\b/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D+/', '', $m[0] );
				if ( ! is_string( $digits ) || strlen( $digits ) < 13 ) {
					return $m[0];
				}
				return str_repeat( '*', strlen( $digits ) - 4 ) . substr( $digits, -4 );
			},
			$value
		);

		// Email addresses appearing free-form inside a log message.
		// Mask the local-part to its first character + stars; keep the
		// domain for support triage (`j***@example.com`). The key-based
		// redaction above already wipes anything stored under an
		// email-shaped key, so this only catches "user jane@example.com
		// did X" style messages.
		$value = (string) preg_replace_callback(
			'/\b([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/',
			static function ( array $m ): string {
				$local = $m[1];
				$mask  = strlen( $local ) > 1 ? $local[0] . '***' : '***';
				return $mask . '@' . $m[2];
			},
			$value
		);

		// Phone numbers in formats with explicit separators (so we don't
		// trample order ids or amounts). Requires at least one separator
		// (`-`, `.`, space, or `()`). Masks all but the last 4 digits.
		$value = (string) preg_replace_callback(
			'/(\+?\d{1,3}[\s.\-]\(?\d{2,4}\)?[\s.\-]\d{2,4}[\s.\-]\d{2,4})/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D+/', '', $m[0] );
				if ( ! is_string( $digits ) || strlen( $digits ) < 7 ) {
					return $m[0];
				}
				return '***-***-' . substr( $digits, -4 );
			},
			$value
		);

		if ( $max_length > 0 && strlen( $value ) > $max_length ) {
			$value = substr( $value, 0, $max_length ) . '…[truncated]';
		}
		return $value;
	}

	/** @var array<int, string>|null Cached sensitive-key list for this request. */
	private static ?array $cached_keys = null;

	/**
	 * Resolve the active sensitive-key list. Cached per request.
	 *
	 * @return array<int, string>
	 */
	private static function sensitive_keys(): array {
		if ( null !== self::$cached_keys ) {
			return self::$cached_keys;
		}

		$keys = self::DEFAULT_KEYS;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the list of context-key fragments treated as sensitive.
			 *
			 * Matching is case-insensitive substring — passing `card` will
			 * redact `card_number`, `card_cvc`, `creditcard`, etc.
			 *
			 * @since 1.0.0
			 * @param array<int, string> $keys Default fragments.
			 */
			$filtered = apply_filters( 'tejcart_logging_redact_keys', $keys );
			if ( is_array( $filtered ) ) {
				$keys = array_values( array_filter( array_map(
					static fn( $k ): string => is_string( $k ) ? strtolower( $k ) : '',
					$filtered
				) ) );
			}
		}

		self::$cached_keys = $keys;
		return self::$cached_keys;
	}

	/**
	 * Reset the sensitive-key cache. Test-only.
	 *
	 * @internal
	 */
	public static function reset_cache(): void {
		self::$cached_keys = null;
	}
}
