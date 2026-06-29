<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Segment_Report {

    public const SEGMENT_VIP      = 'vip';
    public const SEGMENT_LOYAL    = 'loyal';
    public const SEGMENT_ACTIVE   = 'active';
    public const SEGMENT_AT_RISK  = 'at-risk';
    public const SEGMENT_LAPSED   = 'lapsed';
    public const SEGMENT_NEW      = 'new';

    private const SEGMENT_LABELS = array(
        self::SEGMENT_VIP     => 'VIP',
        self::SEGMENT_LOYAL   => 'Loyal',
        self::SEGMENT_ACTIVE  => 'Active',
        self::SEGMENT_AT_RISK => 'At-risk',
        self::SEGMENT_LAPSED  => 'Lapsed',
        self::SEGMENT_NEW     => 'New',
    );

    /**
     * @return array<string, string>
     */
    public static function labels(): array {
        return self::SEGMENT_LABELS;
    }

    /**
     * Classify a customer based on RFM-like criteria (order count, recency, total revenue).
     *
     * @param int  $order_count    Number of completed orders.
     * @param int  $days_since     Days since last order.
     * @param int  $total_revenue  Lifetime revenue in minor units.
     * @param int  $avg_ltv        Average store LTV in minor units (for VIP threshold).
     */
    public static function classify( int $order_count, int $days_since, int $total_revenue, int $avg_ltv ): string {
        $vip_threshold = (int) ( $avg_ltv * 3 );

        if ( $total_revenue > 0 && $total_revenue >= $vip_threshold && $order_count >= 5 && $days_since <= 90 ) {
            return self::SEGMENT_VIP;
        }

        if ( $order_count >= 4 && $days_since <= 120 ) {
            return self::SEGMENT_LOYAL;
        }

        if ( $order_count >= 2 && $days_since <= 90 ) {
            return self::SEGMENT_ACTIVE;
        }

        if ( $order_count >= 2 && $days_since > 90 && $days_since <= 365 ) {
            return self::SEGMENT_AT_RISK;
        }

        if ( $days_since > 365 ) {
            return self::SEGMENT_LAPSED;
        }

        return self::SEGMENT_NEW;
    }

    /**
     * Get revenue breakdown by customer segment.
     *
     * Classification is done in SQL to avoid loading all customers into PHP.
     *
     * @return array<string, array{customer_count: int, revenue: int, order_count: int, avg_ltv: int}>
     */
    public function get_breakdown( string $currency = 'USD' ): array {
        global $wpdb;

        $ltv_table     = Schema::customer_ltv_table();
        $store_avg_ltv = $this->get_store_avg_ltv( $currency );
        $vip_threshold = $store_avg_ltv * 3;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    CASE
                        WHEN total_revenue > 0
                             AND total_revenue >= %d
                             AND order_count >= 5
                             AND DATEDIFF(UTC_TIMESTAMP(), last_order_date) <= 90
                            THEN 'vip'
                        WHEN order_count >= 4
                             AND DATEDIFF(UTC_TIMESTAMP(), last_order_date) <= 120
                            THEN 'loyal'
                        WHEN order_count >= 2
                             AND DATEDIFF(UTC_TIMESTAMP(), last_order_date) <= 90
                            THEN 'active'
                        WHEN order_count >= 2
                             AND DATEDIFF(UTC_TIMESTAMP(), last_order_date) > 90
                             AND DATEDIFF(UTC_TIMESTAMP(), last_order_date) <= 365
                            THEN 'at-risk'
                        WHEN DATEDIFF(UTC_TIMESTAMP(), last_order_date) > 365
                             OR last_order_date IS NULL
                            THEN 'lapsed'
                        ELSE 'new'
                    END AS segment,
                    COUNT(*) AS customer_count,
                    COALESCE(SUM(total_revenue), 0) AS revenue,
                    COALESCE(SUM(order_count), 0) AS order_count
                 FROM {$ltv_table}
                 WHERE currency = %s
                 GROUP BY segment",
                $vip_threshold,
                $currency
            ),
            ARRAY_A
        );

        $segments = array();
        foreach ( self::SEGMENT_LABELS as $key => $label ) {
            $segments[ $key ] = array(
                'customer_count' => 0,
                'revenue'        => 0,
                'order_count'    => 0,
                'avg_ltv'        => 0,
            );
        }

        foreach ( $rows as $row ) {
            $key = $row['segment'];
            if ( ! isset( $segments[ $key ] ) ) {
                continue;
            }
            $count = (int) $row['customer_count'];
            $rev   = (int) $row['revenue'];
            $segments[ $key ] = array(
                'customer_count' => $count,
                'revenue'        => $rev,
                'order_count'    => (int) $row['order_count'],
                'avg_ltv'        => $count > 0 ? intdiv( $rev, $count ) : 0,
            );
        }

        return $segments;
    }

    private function get_store_avg_ltv( string $currency ): int {
        global $wpdb;

        $ltv_table = Schema::customer_ltv_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $avg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT CASE WHEN COUNT(*) > 0 THEN SUM(total_revenue) DIV COUNT(*) ELSE 0 END
                 FROM {$ltv_table}
                 WHERE currency = %s",
                $currency
            )
        );

        return (int) $avg;
    }
}
