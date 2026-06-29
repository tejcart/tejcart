<?php
/**
 * Evidence-due reminder job.
 *
 * Runs daily, queries actionable disputes whose `evidence_due` falls
 * inside the configurable reminder windows (default: 7-day, 3-day, and
 * 1-day-out), and fires a reminder email per dispute. Idempotent:
 * each (dispute_id, window) combination is recorded in a transient
 * so a second cron tick within the same window is a no-op.
 *
 * The cron runs via WP-Cron when `as_schedule_recurring_action()`
 * isn't available (Action Scheduler not loaded), which keeps the
 * dependency soft.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Evidence_Reminder {
    public const HOOK = 'tejcart_disputes_evidence_reminder';

    /**
     * Default reminder thresholds in days. The job emits one reminder per
     * (dispute, threshold) pair across the lifetime of the dispute.
     * Filterable via `tejcart_disputes_reminder_thresholds`.
     */
    public const THRESHOLDS = array( 7, 3, 1 );

    public function register(): void {
        add_action( self::HOOK, array( $this, 'run' ) );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    /**
     * The cron entry point. Walks each threshold and emits a reminder
     * for any actionable dispute whose evidence_due falls inside the
     * day-of window for that threshold.
     */
    public function run(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }
        $table = $wpdb->prefix . 'tejcart_disputes';

        // time() is already UTC; current_time('timestamp', true) is deprecated
        // since WP 5.3 and Plugin Check flags it.
        $now = time();

        $actionable        = Dispute::actionable_statuses();
        $actionable_count  = count( $actionable );
        $actionable_marks  = implode( ',', array_fill( 0, $actionable_count, '%s' ) );

        /** @var int[] $thresholds */
        $thresholds = apply_filters( 'tejcart_disputes_reminder_thresholds', self::THRESHOLDS );

        foreach ( $thresholds as $days_out ) {
            $window_start = gmdate( 'Y-m-d H:i:s', $now + ( $days_out - 1 ) * DAY_IN_SECONDS );
            $window_end   = gmdate( 'Y-m-d H:i:s', $now + $days_out * DAY_IN_SECONDS );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE evidence_due >= %s AND evidence_due <  %s AND status IN ({$actionable_marks})", array_merge( array( $window_start, $window_end ), $actionable ) ), ARRAY_A );

            foreach ( (array) $rows as $row ) {
                $dispute = new Dispute( (array) $row );
                if ( $dispute->id <= 0 ) {
                    continue;
                }
                // Belt-and-braces: actionable filter is already in the
                // SQL but a manual resolve race could land between the
                // query and the email send.
                if ( $dispute->is_terminal() ) {
                    continue;
                }
                if ( $this->already_sent( (int) $dispute->id, $days_out ) ) {
                    continue;
                }

                /**
                 * Fires when a dispute is approaching its
                 * evidence-due deadline. Subscribers (Email_Notification,
                 * Slack hooks, etc.) deliver the actual reminder.
                 *
                 * @param Dispute $dispute
                 * @param int     $days_out How many days until the deadline.
                 */
                do_action( 'tejcart_disputes_evidence_due_soon', $dispute, $days_out );

                $this->mark_sent( (int) $dispute->id, $days_out );
            }
        }
    }

    private function already_sent( int $dispute_id, int $days_out ): bool {
        if ( ! function_exists( 'get_transient' ) ) {
            return false;
        }
        return false !== get_transient( $this->key( $dispute_id, $days_out ) );
    }

    private function mark_sent( int $dispute_id, int $days_out ): void {
        if ( ! function_exists( 'set_transient' ) ) {
            return;
        }
        // 7-day TTL is safely longer than the largest window we
        // operate on (3 days) so the same threshold can't re-fire
        // before the dispute has actually closed.
        set_transient( $this->key( $dispute_id, $days_out ), 1, 7 * DAY_IN_SECONDS );
    }

    private function key( int $dispute_id, int $days_out ): string {
        return sprintf( 'tejcart_disputes_reminder_%d_d%d', $dispute_id, $days_out );
    }
}
