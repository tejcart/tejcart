<?php
/**
 * Custom-table-backed advisory locks.
 *
 * Replaces three `wp_options`-resident idempotency surfaces (PayPal webhook
 * event claim, checkout idempotency, PayPal capture lock — see review
 * finding S-4) with a single tiny custom table. Each `claim()` is an
 * `INSERT IGNORE` against a UNIQUE PRIMARY KEY, so the atomic-claim
 * semantics match `add_option()` without the alloptions-cache churn.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static accessor for advisory locks.
 */
final class Lock {

    /**
     * Atomically attempt to claim a lock.
     *
     * Returns true exactly when this caller is the first to insert the row.
     * The claim auto-expires `$ttl_seconds` from now; expired rows are
     * harvested by `sweep_expired()` (cron + opportunistic).
     *
     * @param string $key         Unique lock key (≤ 64 chars).
     * @param int    $ttl_seconds Time-to-live in seconds.
     * @param string $payload     Optional opaque payload (≤ 255 chars).
     * @return bool True on first claim, false when already held by another caller.
     */
    public static function claim( string $key, int $ttl_seconds, string $payload = '' ): bool {
        global $wpdb;

        $key     = self::normalize_key( $key );
        $expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_seconds ) );
        $payload = substr( $payload, 0, 255 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tejcart_locks (lock_key, payload, expires_at)
                 VALUES (%s, %s, %s)",
                $key,
                $payload,
                $expires
            )
        );

        if ( 1 === (int) $rows ) {
            return true;
        }

        // The row exists. Check whether it is expired and we can reclaim
        // it. Use a conditional UPDATE keyed on (lock_key, expires_at) so
        // only the request that actually flips the row from expired-to-live
        // wins the reclaim. No race even when two requests race to reclaim.
        $now = gmdate( 'Y-m-d H:i:s' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $reclaimed = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}tejcart_locks
                    SET payload = %s, expires_at = %s, created_at = UTC_TIMESTAMP()
                  WHERE lock_key = %s AND expires_at <= %s",
                $payload,
                $expires,
                $key,
                $now
            )
        );

        return 1 === (int) $reclaimed;
    }

    /**
     * Release a lock unconditionally. Idempotent.
     *
     * @param string $key Lock key.
     */
    public static function release( string $key ): void {
        global $wpdb;
        $key = self::normalize_key( $key );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            "{$wpdb->prefix}tejcart_locks",
            array( 'lock_key' => $key ),
            array( '%s' )
        );
    }

    /**
     * True when the key is currently claimed (and not expired).
     *
     * @param string $key Lock key.
     */
    public static function is_held( string $key ): bool {
        global $wpdb;
        $key = self::normalize_key( $key );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}tejcart_locks WHERE lock_key = %s AND expires_at > UTC_TIMESTAMP() LIMIT 1",
                $key
            )
        );
        return ! empty( $row );
    }

    /**
     * Sweep expired rows. Returns rows deleted.
     */
    public static function sweep_expired( int $limit = 5000 ): int {
        global $wpdb;
        $limit = max( 1, $limit );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tejcart_locks
                  WHERE expires_at <= UTC_TIMESTAMP()
                  LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Normalize a key to fit the CHAR(64) column. Long inputs are SHA-256-hashed.
     */
    private static function normalize_key( string $key ): string {
        if ( strlen( $key ) > 64 ) {
            return hash( 'sha256', $key );
        }
        return $key;
    }
}
