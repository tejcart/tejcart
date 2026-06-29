<?php
/**
 * Log redaction helpers.
 *
 * Centralises the IP and coupon-code fingerprint formatters so every
 * `tejcart_log` call site reports a stable, GDPR-friendly shape: enough
 * signal for abuse correlation, none of the plaintext PII that wp.org
 * support tickets and merchant log forwards routinely surface.
 *
 * Promoted from Cart_Ajax::redact_ip / redact_coupon_code in fix L-5
 * of TEJCART_FINTECH_REVIEW.md so that Checkout, PayPal_Webhook,
 * PayPal_AJAX, Order_Manager, and any future `tejcart_log` caller can
 * hit the same redaction surface.
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static IP / coupon / token redaction helpers.
 */
final class Log_Redactor {
    /**
     * Reduce a client IP to a /24 (IPv4) or /48 (IPv6) prefix +
     * short HMAC fingerprint so log lines retain enough signal for
     * abuse correlation without storing the full address.
     *
     * Example outputs:
     *   - "203.0.113.0|9c3ae6b1"   (IPv4)
     *   - "2001:0db8:1234::/48|abcd1234" (IPv6)
     *   - "(redacted)|abcd1234"    (unparsable input)
     *   - "(none)"                 (empty)
     *
     * The HMAC suffix uses wp_salt('auth') so a stored log column
     * cannot be reverse-correlated to a known IP without the WP secret.
     *
     * @param string $ip Raw IP (may be empty / malformed).
     * @return string Redacted form.
     */
    public static function ip( string $ip ): string {
        $ip = trim( $ip );
        if ( '' === $ip ) {
            return '(none)';
        }

        $hash_suffix = '|' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 8 );

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts = explode( '.', $ip );
            if ( 4 === count( $parts ) ) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0' . $hash_suffix;
            }
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $packed = @inet_pton( $ip );
            if ( false !== $packed && strlen( $packed ) >= 6 ) {
                return bin2hex( substr( $packed, 0, 6 ) ) . '::/48' . $hash_suffix;
            }
        }

        return '(redacted)' . $hash_suffix;
    }

    /**
     * Reduce a coupon code to a 3-letter prefix + length + 8-char
     * SHA-256 fingerprint. Two failed attempts at the same code
     * correlate without the full code being persisted.
     *
     * Example: "SUM***[8:9c3ae6b1]" for "SUMMER25".
     *
     * @param string $code Coupon code (any case).
     * @return string Redacted form.
     */
    public static function coupon_code( string $code ): string {
        $code = strtoupper( trim( $code ) );
        if ( '' === $code ) {
            return '(empty)';
        }
        $prefix = substr( $code, 0, 3 );
        $hash   = substr( hash( 'sha256', $code ), 0, 8 );
        return sprintf( '%s***[%d:%s]', $prefix, strlen( $code ), $hash );
    }

    /**
     * Reduce a gateway transaction / capture / refund ID to a redacted
     * form: first 4 + last 4 of the input + a length marker. Lets ops
     * eyeball that "this log line corresponds to capture 7AB12345..."
     * without leaking the full ID into log forwards. PayPal
     * capture IDs are not strongly secret (they're returned in webhook
     * payloads), but they're still uniquely identifying per-merchant
     * and don't belong in third-party log aggregation systems by
     * default.
     *
     * Example: "7AB1***5678[20]" for a 20-char capture ID.
     *
     * @param string $id Gateway transaction identifier.
     * @return string Redacted form.
     */
    public static function transaction_id( string $id ): string {
        $id = trim( $id );
        if ( '' === $id ) {
            return '(none)';
        }
        $len = strlen( $id );
        if ( $len <= 8 ) {
            return sprintf( '%s[%d]', substr( $id, 0, 1 ) . str_repeat( '*', max( 0, $len - 2 ) ) . substr( $id, -1 ), $len );
        }
        return sprintf( '%s***%s[%d]', substr( $id, 0, 4 ), substr( $id, -4 ), $len );
    }
}
