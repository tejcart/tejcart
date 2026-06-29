<?php
/**
 * Per-event audit log entry.
 *
 * Each webhook arrival, manual action, or status change appends one row
 * to `wp_tejcart_dispute_events`. This preserves the full lifecycle
 * history that was previously lost when `payload` was overwritten on
 * each upsert.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dispute_Event {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_dispute_events';
    }

    /**
     * Append an event to the audit log for a dispute.
     *
     * @param int                  $dispute_id    Internal dispute row ID.
     * @param string               $event_type    E.g. 'webhook_created', 'webhook_updated',
     *                                            'webhook_resolved', 'manual_resolve', 'note_added'.
     * @param string               $status_before Status before this event.
     * @param string               $status_after  Status after this event.
     * @param array<string, mixed> $payload       Raw gateway payload or action context.
     * @param string               $actor         Who/what triggered: 'paypal', 'stripe', user display name.
     * @param string               $source_event_id Gateway event ID for idempotency tracking.
     */
    public static function record(
        int $dispute_id,
        string $event_type,
        string $status_before,
        string $status_after,
        array $payload = array(),
        string $actor = '',
        string $source_event_id = ''
    ): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return false;
        }

        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom-table insert for audit log
        $result = $wpdb->insert(
            self::table(),
            array(
                'dispute_id'      => $dispute_id,
                'event_type'      => $event_type,
                'source_event_id' => $source_event_id,
                'status_before'   => $status_before,
                'status_after'    => $status_after,
                'occurred_at'     => $now,
                'ingested_at'     => $now,
                'actor'           => $actor,
                'payload'         => wp_json_encode( $payload ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return false !== $result;
    }

    /**
     * Retrieve all events for a dispute, ordered chronologically.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for_dispute( int $dispute_id, int $limit = 100 ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return array();
        }
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE dispute_id = %d ORDER BY occurred_at ASC, id ASC LIMIT %d",
                $dispute_id,
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }
}
