<?php
/**
 * Partition-roll automation (C-5).
 *
 * Recurring weekly cron that adds the next-quarter partition before the
 * MAXVALUE catch-all on the new high-growth tables (paypal_events,
 * request_log, admin_audit), and drops partitions older than the
 * configured retention so the table footprint stays bounded.
 *
 * Best-effort: every ALTER is suppress_errors-wrapped so unsupported
 * environments (older MariaDB, non-InnoDB) silently no-op without
 * impacting normal operation.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Partition_Roller {

    public const HOOK = 'tejcart_partitions_roll';

    /**
     * Tables managed by the roller, plus the column they're partitioned on.
     */
    private const TABLES = array(
        'tejcart_paypal_events' => 'received_at',
        'tejcart_request_log'   => 'recorded_at',
        'tejcart_admin_audit'   => 'created_at',
    );

    public function init(): void {
        add_action( self::HOOK, array( $this, 'run' ) );
        add_action( 'init', array( $this, 'maybe_schedule' ) );
    }

    public function maybe_schedule(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            Action_Scheduler::instance()->schedule_recurring(
                time() + 12 * HOUR_IN_SECONDS,
                WEEK_IN_SECONDS,
                self::HOOK
            );
        }
    }

    public function run(): void {
        $retention_quarters = (int) apply_filters( 'tejcart_partition_retention_quarters', 8 );
        foreach ( self::TABLES as $table => $column ) {
            $this->roll_table( $table, $column, $retention_quarters );
        }
    }

    private function roll_table( string $table, string $column, int $retention ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        $full = $wpdb->prefix . $table;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $partitions = $wpdb->get_col( $wpdb->prepare(
            "SELECT PARTITION_NAME FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND PARTITION_NAME IS NOT NULL",
            DB_NAME,
            $full
        ) );
        if ( empty( $partitions ) ) {
            return; // table not partitioned on this install — nothing to do
        }

        $previous = $wpdb->suppress_errors( true );
        $this->ensure_future_partitions( $full, $column, $partitions );
        $this->drop_old_partitions( $full, $partitions, $retention );
        $wpdb->suppress_errors( $previous );
    }

    /**
     * Add the next 2 quarterly partitions if they aren't already present
     * (REORGANIZE the catch-all `pmax` so the new bound slots in just
     * before MAXVALUE).
     */
    private function ensure_future_partitions( string $full, string $column, array $partitions ): void {
        global $wpdb;

        $year   = (int) gmdate( 'Y' );
        $quart  = (int) ceil( ( (int) gmdate( 'n' ) ) / 3 );
        $missing = array();

        for ( $offset = 1; $offset <= 2; $offset++ ) {
            $q = $quart + $offset;
            $y = $year;
            while ( $q > 4 ) {
                $q -= 4;
                $y += 1;
            }
            $name = sprintf( 'p%dq%d', $y, $q );
            if ( in_array( $name, $partitions, true ) ) {
                continue;
            }
            $bound_month = ( $q * 3 ) + 1;
            $bound_year  = $y;
            if ( $bound_month > 12 ) {
                $bound_month -= 12;
                $bound_year  += 1;
            }
            $missing[] = sprintf(
                "PARTITION %s VALUES LESS THAN ('%04d-%02d-01')",
                $name,
                $bound_year,
                $bound_month
            );
        }

        if ( empty( $missing ) ) {
            return;
        }

        $missing[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        $sql = sprintf(
            'ALTER TABLE %s REORGANIZE PARTITION pmax INTO (%s)',
            $full,
            implode( ', ', $missing )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql composed from internal table-name literal and integer-derived partition bounds.
        $wpdb->query( $sql );
    }

    /**
     * Drop partitions older than `$retention` quarters back. Keeps `pmax`
     * untouched.
     */
    private function drop_old_partitions( string $full, array $partitions, int $retention ): void {
        global $wpdb;

        $cutoff_year  = (int) gmdate( 'Y' );
        $cutoff_quart = (int) ceil( ( (int) gmdate( 'n' ) ) / 3 ) - $retention;
        while ( $cutoff_quart < 1 ) {
            $cutoff_quart += 4;
            $cutoff_year  -= 1;
        }

        $to_drop = array();
        foreach ( $partitions as $name ) {
            if ( ! preg_match( '/^p(\d{4})q([1-4])$/', $name, $m ) ) {
                continue;
            }
            $py = (int) $m[1];
            $pq = (int) $m[2];
            if ( $py < $cutoff_year || ( $py === $cutoff_year && $pq < $cutoff_quart ) ) {
                $to_drop[] = $name;
            }
        }
        if ( empty( $to_drop ) ) {
            return;
        }
        $sql = sprintf( 'ALTER TABLE %s DROP PARTITION %s', $full, implode( ', ', $to_drop ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql composed from internal table-name literal and regex-validated partition names (^p\d{4}q[1-4]$).
        $wpdb->query( $sql );
    }
}
