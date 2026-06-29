<?php
/**
 * Retention cron.
 *
 * Soft-deleted shipments accumulate forever by default — operators can
 * see what was attached and removed during dispute investigations. For
 * stores at scale that audit trail is valuable for ~12 months and
 * useless thereafter, so we hard-purge soft-deleted rows older than
 * `tejcart_order_tracking_retention_days` (default 365).
 *
 * Optionally we also purge *delivered* rows older than
 * `tejcart_order_tracking_delivered_retention_days` (default 0 =
 * never), for sites where carriers' raw payloads accumulate large
 * `meta` blobs.
 *
 * The job is bounded by `tejcart_order_tracking_retention_batch`
 * (default 500) per tick; if the batch fills we chase a single-shot
 * follow-up so a backlog drains over multiple ticks rather than
 * blocking one tick for minutes.
 *
 * Schedule: daily, jittered by ±10 minutes to avoid thundering-herd
 * across stores on shared hosts.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Retention_Cron {
    public const HOOK                       = 'tejcart_order_tracking_retention';
    public const DEFAULT_INTERVAL           = DAY_IN_SECONDS;
    public const DEFAULT_BATCH              = 500;
    public const DEFAULT_SOFT_DELETE_DAYS   = 365;
    public const DEFAULT_DELIVERED_DAYS     = 0;   // 0 = disabled.
    public const DEFAULT_AUDIT_RETENTION_DAYS = 730; // ~2y; 0 = keep forever.

    private Shipment_Repository $repo;

    public function __construct( ?Shipment_Repository $repo = null ) {
        $this->repo = $repo ?? new Shipment_Repository();
    }

    public function register(): void {
        add_action( self::HOOK,            array( $this, 'run' ) );
        add_action( 'init',                array( $this, 'maybe_schedule' ), 99 );
    }

    public function maybe_schedule(): void {
        $interval = $this->interval_seconds();
        if ( $interval <= 0 ) {
            return;
        }

        if ( function_exists( 'as_next_scheduled_action' ) ) {
            if ( false === as_next_scheduled_action( self::HOOK, array(), 'tejcart' ) ) {
                $first = time() + $interval + wp_rand( -600, 600 );
                as_schedule_recurring_action( $first, $interval, self::HOOK, array(), 'tejcart' );
            }
            return;
        }
        if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + $interval, 'daily', self::HOOK );
        }
    }

    /**
     * Walk the soft-deleted (and optionally delivered) rows that are
     * past the retention horizon, hard-purge them via the Repository
     * (which also invalidates caches), and chase another tick if the
     * batch was full.
     *
     * @return array{soft:int,delivered:int}
     */
    public function run(): array {
        $batch          = $this->batch_size();
        $soft_horizon   = $this->soft_delete_horizon();
        $deliv_horizon  = $this->delivered_horizon();

        $purged_soft = 0;
        $remaining   = $batch;
        if ( null !== $soft_horizon && $remaining > 0 ) {
            $rows = $this->fetch_purgeable( 'soft', $soft_horizon, $remaining );
            foreach ( $rows as $row ) {
                if ( $this->repo->purge( (int) $row['id'] ) ) {
                    ++$purged_soft;
                }
            }
            $remaining -= count( $rows );
        }

        $purged_delivered = 0;
        if ( null !== $deliv_horizon && $remaining > 0 ) {
            $rows = $this->fetch_purgeable( 'delivered', $deliv_horizon, $remaining );
            foreach ( $rows as $row ) {
                if ( $this->repo->purge( (int) $row['id'] ) ) {
                    ++$purged_delivered;
                }
            }
        }

        $total = $purged_soft + $purged_delivered;

        $purged_audit   = 0;
        $audit_max_age  = $this->audit_retention_seconds();
        if ( $audit_max_age > 0 ) {
            $purged_audit = Audit_Log::prune_older_than( $audit_max_age, $batch );
        }

        /**
         * Fires after a retention pass. Useful for metrics dashboards.
         *
         * @param int $purged_soft
         * @param int $purged_delivered
         * @param int $purged_audit
         */
        do_action( 'tejcart_order_tracking_retention_ran', $purged_soft, $purged_delivered, $purged_audit );

        // If we filled the batch there may be more — chase a quick follow-up.
        if ( $total >= $batch && function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 60, self::HOOK, array(), 'tejcart' );
        }

        return array( 'soft' => $purged_soft, 'delivered' => $purged_delivered );
    }

    /**
     * Fetch rows eligible for purge.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_purgeable( string $kind, string $horizon, int $limit ): array {
        global $wpdb;
        $table = Schema_Migrator::table_name();

        if ( 'soft' === $kind ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < %s LIMIT %d", $horizon, $limit ), ARRAY_A );
            return is_array( $rows ) ? $rows : array();
        }

        // delivered
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE deleted_at IS NULL AND status = %s AND COALESCE(delivered_at, updated_at) < %s LIMIT %d", Shipment_Status::DELIVERED, $horizon, $limit ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private function interval_seconds(): int {
        /** @var int */
        return (int) apply_filters( 'tejcart_order_tracking_retention_interval', self::DEFAULT_INTERVAL );
    }

    private function batch_size(): int {
        /** @var int */
        return max( 1, (int) apply_filters( 'tejcart_order_tracking_retention_batch', self::DEFAULT_BATCH ) );
    }

    private function soft_delete_days(): int {
        return (int) apply_filters( 'tejcart_order_tracking_retention_days', self::DEFAULT_SOFT_DELETE_DAYS );
    }

    /**
     * Max age (in seconds) of audit-log rows before they are pruned, or 0
     * to keep the audit trail indefinitely.
     *
     * The audit trail is the immutable history high-compliance merchants
     * rely on, so it has its OWN horizon rather than reusing the soft-delete
     * window — pruning live-shipment audit rows on the soft-delete schedule
     * would silently destroy that history. Defaults to twice the soft-delete
     * window so the trail outlives the shipment rows it documents.
     */
    private function audit_retention_seconds(): int {
        $days = (int) apply_filters(
            'tejcart_order_tracking_audit_retention_days',
            self::DEFAULT_AUDIT_RETENTION_DAYS
        );
        return $days > 0 ? $days * DAY_IN_SECONDS : 0;
    }

    /**
     * Returns the MySQL DATETIME threshold beyond which soft-deleted
     * rows are eligible to purge, or null when retention is disabled.
     */
    private function soft_delete_horizon(): ?string {
        $days = $this->soft_delete_days();
        if ( $days <= 0 ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    }

    private function delivered_horizon(): ?string {
        $days = (int) apply_filters( 'tejcart_order_tracking_delivered_retention_days', self::DEFAULT_DELIVERED_DAYS );
        if ( $days <= 0 ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    }
}
