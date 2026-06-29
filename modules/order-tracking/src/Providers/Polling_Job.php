<?php
/**
 * Action-Scheduler-driven polling for in-flight shipments.
 *
 * Every `tejcart_order_tracking_poll_interval` (default 1 hour) we:
 *  1. Pull all non-terminal, non-deleted shipments updated more than
 *     `tejcart_order_tracking_poll_min_age` (default 30 min) ago — the
 *     min-age guard prevents the poller from racing operators who just
 *     entered tracking from the admin metabox.
 *  2. For each row, find the configured provider that supports its
 *     carrier and call `fetch_status()`.
 *  3. If the returned status differs from the row, dispatch a
 *     `Tracking_Service::update()` — that runs the state machine,
 *     stamps timestamps, fires events, invalidates cache.
 *  4. If the provider returns a transient error, log and skip — we'll
 *     catch the row on the next poll.
 *
 * The poll loop is bounded (`tejcart_order_tracking_poll_batch`,
 * default 50) to keep each Action Scheduler tick under control on
 * stores with thousands of in-flight parcels. A fresh single-shot job
 * is scheduled when more rows remain.
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

use TejCart\Tier2\Order_Tracking\Schema_Migrator;
use TejCart\Tier2\Order_Tracking\Shipment_Status;
use TejCart\Tier2\Order_Tracking\Tracking_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Polling_Job {
    public const HOOK             = 'tejcart_order_tracking_poll';
    public const DEFAULT_INTERVAL = HOUR_IN_SECONDS;
    public const DEFAULT_BATCH    = 50;
    public const DEFAULT_MIN_AGE  = 1800; // 30 minutes.
    public const LOCK_KEY         = 'tejcart_ot_poll_lock';
    public const LOCK_GROUP       = 'tejcart_order_tracking';
    // Long enough to cover a full batch (50 rows × ~1s/poll = 50s) plus
    // a safety margin; short enough that a crashed worker doesn't block
    // polling for longer than one missed tick.
    public const LOCK_TTL_SECONDS = 300;

    private Tracking_Service  $service;
    private Provider_Registry $registry;

    public function __construct( Tracking_Service $service, Provider_Registry $registry ) {
        $this->service  = $service;
        $this->registry = $registry;
    }

    public function register(): void {
        add_action( self::HOOK, array( $this, 'run' ) );

        // Schedule on `init` (after WP is fully booted) so the
        // Action_Scheduler helper sees the right environment.
        add_action( 'init', array( $this, 'maybe_schedule' ), 99 );
    }

    public function maybe_schedule(): void {
        $interval = $this->interval_seconds();
        if ( $interval <= 0 ) {
            return;
        }

        // Action Scheduler: prefer if available; otherwise fall back to
        // wp-cron via core's helper class.
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            if ( false === as_next_scheduled_action( self::HOOK, array(), 'tejcart' ) ) {
                as_schedule_recurring_action( time() + $interval, $interval, self::HOOK, array(), 'tejcart' );
            }
            return;
        }
        if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + $interval, 'hourly', self::HOOK );
        }
    }

    public function run(): void {
        // Refuse to run if another worker already holds the lock. Two
        // concurrent runs would race on the same shipment rows and risk
        // status reversions even with the repository-level CAS — better
        // to drop the duplicate tick entirely and pick up the work on
        // the next interval.
        if ( ! $this->acquire_lock() ) {
            return;
        }

        try {
            $batch    = $this->batch_size();
            $min_age  = $this->min_age_seconds();
            $rows     = $this->fetch_in_flight( $batch, $min_age );

            if ( empty( $rows ) ) {
                return;
            }

            foreach ( $rows as $row ) {
                $this->refresh_row( $row );
            }

            // If the batch was full there may be more — chase a single-shot
            // job in a few seconds rather than waiting for the next interval.
            if ( count( $rows ) >= $batch && function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time() + 30, self::HOOK, array(), 'tejcart' );
            }
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Atomic-best-effort run lock. Object-cache `add()` is genuinely
     * atomic on Memcached / Redis backends; on the persistent-array
     * fallback it is at least atomic per-request, which still prevents
     * accidental double-dispatch from Action Scheduler firing two
     * single-shot jobs against the same hook in the same request.
     *
     * A transient-based fallback covers the (rare) case where no object
     * cache is wired and two requests race; transients persist across
     * requests, but the get-then-set window is small enough in practice
     * that the residual race is acceptable given the downstream CAS in
     * {@see \TejCart\Tier2\Order_Tracking\Shipment_Repository::update()}.
     */
    private function acquire_lock(): bool {
        if ( function_exists( 'wp_cache_add' ) ) {
            $added = wp_cache_add( self::LOCK_KEY, time(), self::LOCK_GROUP, self::LOCK_TTL_SECONDS );
            if ( $added ) {
                return true;
            }
        }
        if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
            if ( false === get_transient( self::LOCK_KEY ) ) {
                set_transient( self::LOCK_KEY, time(), self::LOCK_TTL_SECONDS );
                return true;
            }
        }
        return false;
    }

    private function release_lock(): void {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::LOCK_KEY, self::LOCK_GROUP );
        }
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::LOCK_KEY );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function refresh_row( array $row ): void {
        $carrier         = \TejCart\Tier2\Order_Tracking\Carriers::normalize_slug( (string) ( $row['carrier'] ?? '' ) );
        $tracking_number = (string) ( $row['tracking_number'] ?? '' );
        $shipment_id     = (int) ( $row['id'] ?? 0 );
        if ( $shipment_id <= 0 || '' === $carrier || '' === $tracking_number ) {
            return;
        }

        // Skip dead-lettered rows so a permanently-broken tracking
        // number doesn't burn through the rate limit on every tick.
        // Manual re-poll resets the dead-letter state, so operators
        // can rescue a row from the admin button.
        $dead_letter = new \TejCart\Tier2\Order_Tracking\Dead_Letter();
        if ( $dead_letter->is_dead( $shipment_id ) ) {
            return;
        }

        $provider = $this->registry->for_carrier( $carrier );
        if ( null === $provider ) {
            return;
        }

        $result = $provider->fetch_status( $tracking_number, $carrier );
        if ( is_wp_error( $result ) ) {
            /**
             * Fires when a provider call fails for an in-flight row.
             * Useful for error-rate alerting.
             */
            do_action( 'tejcart_order_tracking_poll_failed', $shipment_id, $carrier, $result );
            return;
        }

        $this->apply_status( $shipment_id, (string) ( $row['status'] ?? '' ), $result );
    }

    /**
     * Apply a provider-returned Provider_Status to the shipment row,
     * but only if it's a real change AND a legal transition. Side
     * effects fire through Tracking_Service::update() (state machine,
     * cache invalidation, action hooks).
     */
    public function apply_status( int $shipment_id, string $current_status, Provider_Status $next ): void {
        if ( $next->status === $current_status ) {
            return;
        }
        if ( ! Shipment_Status::can_transition( $current_status, $next->status ) ) {
            return;
        }

        $payload = array( 'status' => $next->status );
        if ( null !== $next->delivered_at ) {
            $payload['delivered_at'] = $next->delivered_at;
        }
        if ( null !== $next->shipped_at ) {
            $payload['shipped_at'] = $next->shipped_at;
        }
        if ( ! empty( $next->raw ) ) {
            $payload['meta'] = array(
                'provider_status' => $next->to_array(),
                'updated_via'     => 'poll',
            );
        }

        $result = $this->service->update( $shipment_id, $payload );
        if ( $result instanceof \WP_Error ) {
            // Most common cause at this point is the repository CAS
            // refusing the write because a peer (webhook or earlier
            // poll) already moved the row. Surface for observability;
            // not a hard error.
            do_action(
                'tejcart_order_tracking_apply_status_skipped',
                $shipment_id,
                $current_status,
                $next->status,
                $result
            );
        }
    }

    private function interval_seconds(): int {
        /** @var int */
        return (int) apply_filters( 'tejcart_order_tracking_poll_interval', self::DEFAULT_INTERVAL );
    }

    private function batch_size(): int {
        /** @var int */
        return max( 1, (int) apply_filters( 'tejcart_order_tracking_poll_batch', self::DEFAULT_BATCH ) );
    }

    private function min_age_seconds(): int {
        /** @var int */
        return max( 0, (int) apply_filters( 'tejcart_order_tracking_poll_min_age', self::DEFAULT_MIN_AGE ) );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch_in_flight( int $limit, int $min_age_seconds ): array {
        global $wpdb;
        $table          = Schema_Migrator::table_name();
        $terminal_in_sql = "'" . implode( "','", array( Shipment_Status::DELIVERED, Shipment_Status::RETURNED ) ) . "'";
        $threshold      = gmdate( 'Y-m-d H:i:s', time() - $min_age_seconds );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; {$terminal_in_sql} is built from a whitelisted status list; user values bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE deleted_at IS NULL AND status NOT IN ({$terminal_in_sql}) AND tracking_number <> '' AND updated_at <= %s ORDER BY updated_at ASC LIMIT %d", $threshold, $limit ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}
