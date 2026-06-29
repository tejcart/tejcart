<?php
/**
 * RFM (Recency / Frequency / Monetary) scoring engine.
 *
 * Computes quintile-based R/F/M scores (1–5) for every customer using
 * the store's own order data as the distribution baseline. Designed to
 * run nightly via Action Scheduler on stores with up to 50K+ customers
 * by processing in configurable batches.
 *
 * @package TejCart\Customer
 */

declare( strict_types=1 );

namespace TejCart\Customer;

use TejCart\Core\Action_Scheduler;
use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RFM_Scorer {

    public const RECALCULATE_HOOK = 'tejcart_rfm_recalculate';
    public const BATCH_SIZE       = 500;

    /**
     * Auto-segment slug constants — keep in sync with Segment::AUTO_SEGMENTS.
     */
    public const SEGMENT_VIP     = 'vip';
    public const SEGMENT_ACTIVE  = 'active';
    public const SEGMENT_NEW     = 'new';
    public const SEGMENT_AT_RISK = 'at-risk';
    public const SEGMENT_CHURNED = 'churned';

    public function init(): void {
        add_action( self::RECALCULATE_HOOK, array( $this, 'run_full_rebuild' ) );
        add_action( 'init', array( $this, 'maybe_schedule_nightly' ) );
    }

    public function maybe_schedule_nightly(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::RECALCULATE_HOOK ) ) {
            Action_Scheduler::instance()->schedule_recurring(
                strtotime( 'tomorrow 03:00' ),
                DAY_IN_SECONDS,
                self::RECALCULATE_HOOK
            );
        }
    }

    /**
     * Full RFM rebuild — intended for nightly cron and CLI.
     *
     * 1. Aggregates order data per customer in one pass.
     * 2. Computes quintile boundaries for R, F, and M.
     * 3. Assigns 1–5 scores and a composite RFM score (1–125).
     * 4. Assigns auto-segment based on score thresholds.
     * 5. Writes back in batches.
     *
     * @return array{customers:int, elapsed:float} Summary stats.
     */
    public function run_full_rebuild(): array {
        global $wpdb;

        $start     = microtime( true );
        $customers = $wpdb->prefix . 'tejcart_customers';
        $orders    = $wpdb->prefix . 'tejcart_orders';
        $now       = current_time( 'mysql' );
        $now_ts    = strtotime( $now );

        $shop_currency = (string) get_option( 'tejcart_currency', 'USD' );

        // Step 1: aggregate per customer in one query.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $raw = $wpdb->get_results(
            "SELECT c.id AS customer_id,
                    COUNT(o.id) AS order_count,
                    COALESCE(SUM(CASE WHEN o.status IN ('completed','processing') THEN o.total ELSE 0 END), 0) AS total_spent,
                    MAX(o.created_at) AS last_order_at
             FROM {$customers} c
             LEFT JOIN {$orders} o
                ON (c.user_id IS NOT NULL AND c.user_id > 0 AND o.customer_id = c.user_id)
                OR (c.user_id IS NULL AND o.customer_email = c.email)
             GROUP BY c.id",
            ARRAY_A
        );
        // phpcs:enable

        if ( empty( $raw ) ) {
            return array( 'customers' => 0, 'elapsed' => microtime( true ) - $start );
        }

        // Step 2: build arrays for quintile computation.
        $recency_days = array();
        $frequencies  = array();
        $monetary     = array();
        $customer_data = array();

        foreach ( $raw as $row ) {
            $cid         = (int) $row['customer_id'];
            $count       = (int) $row['order_count'];
            $spent_minor = (int) $row['total_spent'];
            $last_order  = $row['last_order_at'];

            $days_since = 9999;
            if ( $last_order && $count > 0 ) {
                $last_ts    = strtotime( $last_order );
                $days_since = max( 0, (int) floor( ( $now_ts - $last_ts ) / DAY_IN_SECONDS ) );
            }

            $customer_data[ $cid ] = array(
                'recency_days'   => $days_since,
                'frequency'      => $count,
                'monetary_minor' => $spent_minor,
                'last_order_at'  => $last_order,
                'order_count'    => $count,
            );

            if ( $count > 0 ) {
                $recency_days[] = $days_since;
                $frequencies[]  = $count;
                $monetary[]     = $spent_minor;
            }
        }

        // Step 3: compute quintile boundaries.
        $r_bounds = self::quintile_boundaries( $recency_days );
        $f_bounds = self::quintile_boundaries( $frequencies );
        $m_bounds = self::quintile_boundaries( $monetary );

        // Step 4: score each customer and assign segment.
        $updates   = array();
        foreach ( $customer_data as $cid => $data ) {
            if ( 0 === $data['frequency'] ) {
                $updates[ $cid ] = array(
                    'r' => 1, 'f' => 1, 'm' => 1,
                    'composite'      => 1,
                    'ltv_minor'      => 0,
                    'segment'        => self::SEGMENT_NEW,
                    'last_order_at'  => null,
                    'order_count'    => 0,
                );
                continue;
            }

            // Recency: lower days = better = higher score (inverted).
            $r = self::score_inverted( $data['recency_days'], $r_bounds );
            $f = self::score_value( $data['frequency'], $f_bounds );
            $m = self::score_value( $data['monetary_minor'], $m_bounds );

            $composite = $r * $f * $m;
            $segment   = self::assign_segment( $r, $f, $m, $data );

            $updates[ $cid ] = array(
                'r' => $r, 'f' => $f, 'm' => $m,
                'composite'     => $composite,
                'ltv_minor'     => $data['monetary_minor'],
                'segment'       => $segment,
                'last_order_at' => $data['last_order_at'],
                'order_count'   => $data['order_count'],
            );
        }

        // Step 5: batch UPDATE.
        $this->write_scores( $updates, $now );

        return array(
            'customers' => count( $updates ),
            'elapsed'   => microtime( true ) - $start,
        );
    }

    /**
     * Assign auto-segment based on RFM scores.
     *
     * Priority order (first match wins):
     *   VIP     — R≥4, F≥4, M≥4  (best customers)
     *   At-risk — R≤2, F≥3        (was active, going cold)
     *   Churned — R=1, F≥2        (gone)
     *   Active  — R≥3, F≥2        (healthy)
     *   New     — everything else  (single purchase or recent)
     *
     * @return string Segment slug.
     */
    public static function assign_segment( int $r, int $f, int $m, array $data ): string {
        if ( $r >= 4 && $f >= 4 && $m >= 4 ) {
            return self::SEGMENT_VIP;
        }
        if ( $r <= 2 && $f >= 3 ) {
            return self::SEGMENT_AT_RISK;
        }
        if ( 1 === $r && $f >= 2 ) {
            return self::SEGMENT_CHURNED;
        }
        if ( $r >= 3 && $f >= 2 ) {
            return self::SEGMENT_ACTIVE;
        }

        return self::SEGMENT_NEW;
    }

    /**
     * Compute quintile boundary values from a sorted distribution.
     *
     * Returns array of 4 boundary values at 20th, 40th, 60th, 80th percentiles.
     * With fewer than 5 values, boundaries collapse (some quintiles share a value).
     *
     * @param int[] $values Raw values (will be sorted in place).
     * @return int[] Four boundary values [p20, p40, p60, p80].
     */
    public static function quintile_boundaries( array $values ): array {
        if ( empty( $values ) ) {
            return array( 0, 0, 0, 0 );
        }

        sort( $values, SORT_NUMERIC );
        $n = count( $values );

        $percentiles = array();
        foreach ( array( 20, 40, 60, 80 ) as $pct ) {
            $idx           = (int) floor( ( $pct / 100 ) * ( $n - 1 ) );
            $percentiles[] = $values[ $idx ];
        }

        return $percentiles;
    }

    /**
     * Score a value into quintile 1–5 (higher value = higher score).
     *
     * @param int   $value    The value to score.
     * @param int[] $bounds   Four boundary values [p20, p40, p60, p80].
     * @return int Score 1–5.
     */
    public static function score_value( int $value, array $bounds ): int {
        if ( $value <= $bounds[0] ) {
            return 1;
        }
        if ( $value <= $bounds[1] ) {
            return 2;
        }
        if ( $value <= $bounds[2] ) {
            return 3;
        }
        if ( $value <= $bounds[3] ) {
            return 4;
        }
        return 5;
    }

    /**
     * Score a value into quintile 1–5 (lower value = higher score).
     *
     * Used for recency where fewer days-since-last-order is better.
     *
     * @param int   $value    The value to score.
     * @param int[] $bounds   Four boundary values [p20, p40, p60, p80].
     * @return int Score 1–5.
     */
    public static function score_inverted( int $value, array $bounds ): int {
        if ( $value <= $bounds[0] ) {
            return 5;
        }
        if ( $value <= $bounds[1] ) {
            return 4;
        }
        if ( $value <= $bounds[2] ) {
            return 3;
        }
        if ( $value <= $bounds[3] ) {
            return 2;
        }
        return 1;
    }

    /**
     * Write RFM scores back to the customers table in batches.
     *
     * Uses a CASE-based bulk UPDATE to avoid N individual queries.
     *
     * @param array<int, array> $updates  customer_id => score data.
     * @param string            $now      Current datetime for rfm_updated_at.
     */
    private function write_scores( array $updates, string $now ): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_customers';
        $chunks = array_chunk( $updates, self::BATCH_SIZE, true );

        foreach ( $chunks as $batch ) {
            $ids = array_keys( $batch );
            $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $r_cases = '';
            $f_cases = '';
            $m_cases = '';
            $c_cases = '';
            $l_cases = '';
            $s_cases = '';
            $o_cases = '';
            $d_cases = '';
            $params  = array();

            foreach ( $batch as $cid => $data ) {
                $r_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['r'] );
                $f_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['f'] );
                $m_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['m'] );
                $c_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['composite'] );
                $l_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['ltv_minor'] );
                $s_cases .= $wpdb->prepare( ' WHEN id = %d THEN %s', $cid, $data['segment'] );
                $o_cases .= $wpdb->prepare( ' WHEN id = %d THEN %d', $cid, $data['order_count'] );

                if ( $data['last_order_at'] ) {
                    $d_cases .= $wpdb->prepare( ' WHEN id = %d THEN %s', $cid, $data['last_order_at'] );
                } else {
                    $d_cases .= $wpdb->prepare( ' WHEN id = %d THEN NULL', $cid );
                }
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET
                        rfm_recency_score  = CASE {$r_cases} ELSE rfm_recency_score END,
                        rfm_frequency_score = CASE {$f_cases} ELSE rfm_frequency_score END,
                        rfm_monetary_score  = CASE {$m_cases} ELSE rfm_monetary_score END,
                        rfm_score           = CASE {$c_cases} ELSE rfm_score END,
                        ltv_minor_units     = CASE {$l_cases} ELSE ltv_minor_units END,
                        segment             = CASE {$s_cases} ELSE segment END,
                        order_count         = CASE {$o_cases} ELSE order_count END,
                        last_order_at       = CASE {$d_cases} ELSE last_order_at END,
                        rfm_updated_at      = %s
                     WHERE id IN ({$ph})",
                    $now,
                    ...$ids
                )
            );
            // phpcs:enable
        }
    }
}
