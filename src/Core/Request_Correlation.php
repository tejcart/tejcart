<?php
/**
 * Request correlation IDs (M-2).
 *
 * Generates a per-request UUID, exposes it via `tejcart_request_id()`,
 * and persists notable boundary events to `wp_tejcart_request_log` so a
 * support engineer can stitch a buyer-reported issue across the cart →
 * checkout → PayPal → webhook → fulfilment legs.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Request_Correlation {

    /**
     * Per-request memoised UUID.
     *
     * @var string|null
     */
    private static ?string $request_id = null;

    /**
     * Get (or lazily generate) the correlation id for the current request.
     */
    public static function get(): string {
        if ( null !== self::$request_id ) {
            return self::$request_id;
        }
        // Honour an upstream-supplied X-Request-Id when present so the
        // origin's correlation id flows from the load-balancer / CDN /
        // tracing layer.
        $upstream = isset( $_SERVER['HTTP_X_REQUEST_ID'] )
            ? (string) sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) )
            : '';
        if ( '' !== $upstream && preg_match( '/^[a-zA-Z0-9_-]{8,128}$/', $upstream ) ) {
            self::$request_id = $upstream;
        } else {
            self::$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::random_uuid();
        }
        return self::$request_id;
    }

    /**
     * Persist a boundary event to wp_tejcart_request_log so support can
     * stitch up the request later. Idempotent — duplicate (request_id,
     * event_kind) inserts are absorbed by the PRIMARY KEY on request_id.
     */
    public static function persist( string $event_kind, array $context = array() ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tejcart_request_log
                    (request_id, order_id, paypal_order_id, session_key, event_kind)
                 VALUES (%s, %d, %s, %s, %s)",
                self::get(),
                isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
                isset( $context['paypal_order_id'] ) ? (string) $context['paypal_order_id'] : '',
                isset( $context['session_key'] ) ? (string) $context['session_key'] : '',
                $event_kind
            )
        );
    }

    private static function random_uuid(): string {
        $bytes    = random_bytes( 16 );
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
    }
}
