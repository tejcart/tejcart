<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cohort_Report {

    private const RETENTION_MONTHS = 12;

    /**
     * Full rebuild — truncates and repopulates both tables.
     *
     * Called only during a manual admin rebuild or the chunked-rebuild
     * recovery path. Day-to-day order-status changes use
     * refresh_cohort_months() instead.
     */
    public function rebuild_all(): void {
        global $wpdb;

        $cohorts_table   = Schema::cohorts_table();
        $retention_table = Schema::cohort_retention_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$cohorts_table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$retention_table}" );

        $months = $this->get_all_cohort_months();
        foreach ( $months as $month ) {
            $this->upsert_cohort_month( $month );
            $this->upsert_retention_month( $month );
        }
    }

    /**
     * Incremental refresh for one or more cohort months.
     *
     * Recalculates only the affected cohort rows and their retention
     * data — O(customers-in-cohort) instead of O(all-orders).
     *
     * @param array<int, string> $cohort_months YYYY-MM strings.
     */
    public function refresh_cohort_months( array $cohort_months ): void {
        $seen = array();
        foreach ( $cohort_months as $month ) {
            if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || isset( $seen[ $month ] ) ) {
                continue;
            }
            $seen[ $month ] = true;
            $this->upsert_cohort_month( $month );
            $this->upsert_retention_month( $month );
        }
    }

    private function upsert_cohort_month( string $cohort_month ): void {
        global $wpdb;

        $orders_table  = $wpdb->prefix . 'tejcart_orders';
        $cohorts_table = Schema::cohorts_table();
        $paid_sql      = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$cohorts_table}
                    (cohort_month, currency, customer_count, first_order_revenue, total_revenue, total_orders, avg_ltv)
                 SELECT
                    c.cohort_month,
                    c.currency,
                    c.customer_count,
                    c.first_order_revenue,
                    c.total_revenue,
                    c.total_orders,
                    CASE WHEN c.customer_count > 0 THEN c.total_revenue DIV c.customer_count ELSE 0 END
                 FROM (
                    SELECT
                        f.cohort_month,
                        f.currency,
                        COUNT(DISTINCT f.customer_email) AS customer_count,
                        SUM(CASE WHEN o.created_at = f.first_order_date THEN o.total ELSE 0 END) AS first_order_revenue,
                        SUM(o.total) AS total_revenue,
                        COUNT(o.id) AS total_orders
                    FROM (
                        SELECT
                            customer_email,
                            currency,
                            DATE_FORMAT(MIN(created_at), '%%Y-%%m') AS cohort_month,
                            MIN(created_at) AS first_order_date
                        FROM {$orders_table}
                        WHERE status IN ({$paid_sql})
                          AND customer_email != ''
                        GROUP BY customer_email, currency
                        HAVING cohort_month = %s
                    ) f
                    INNER JOIN {$orders_table} o
                        ON o.customer_email = f.customer_email
                       AND o.currency = f.currency
                       AND o.status IN ({$paid_sql})
                    GROUP BY f.cohort_month, f.currency
                 ) c
                 ON DUPLICATE KEY UPDATE
                    customer_count      = c.customer_count,
                    first_order_revenue = c.first_order_revenue,
                    total_revenue       = c.total_revenue,
                    total_orders        = c.total_orders,
                    avg_ltv             = CASE WHEN c.customer_count > 0 THEN c.total_revenue DIV c.customer_count ELSE 0 END",
                $cohort_month
            )
        );
    }

    private function upsert_retention_month( string $cohort_month ): void {
        global $wpdb;

        $orders_table    = $wpdb->prefix . 'tejcart_orders';
        $retention_table = Schema::cohort_retention_table();
        $paid_sql        = Analytics_Advanced::paid_statuses_sql();

        for ( $month_offset = 0; $month_offset <= self::RETENTION_MONTHS; $month_offset++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$retention_table}
                        (cohort_month, currency, months_after, returning_customers, revenue, order_count)
                     SELECT
                        f.cohort_month,
                        f.currency,
                        %d AS months_after,
                        COUNT(DISTINCT o.customer_email),
                        COALESCE(SUM(o.total), 0),
                        COUNT(o.id)
                     FROM (
                        SELECT
                            customer_email,
                            currency,
                            DATE_FORMAT(MIN(created_at), '%%Y-%%m') AS cohort_month,
                            MIN(created_at) AS first_order_date
                        FROM {$orders_table}
                        WHERE status IN ({$paid_sql})
                          AND customer_email != ''
                        GROUP BY customer_email, currency
                        HAVING cohort_month = %s
                     ) f
                     INNER JOIN {$orders_table} o
                        ON o.customer_email = f.customer_email
                       AND o.currency = f.currency
                       AND o.status IN ({$paid_sql})
                       AND TIMESTAMPDIFF(MONTH, f.first_order_date, o.created_at) = %d
                     GROUP BY f.cohort_month, f.currency
                     ON DUPLICATE KEY UPDATE
                        returning_customers = VALUES(returning_customers),
                        revenue             = VALUES(revenue),
                        order_count         = VALUES(order_count)",
                    $month_offset,
                    $cohort_month,
                    $month_offset
                )
            );
        }
    }

    /**
     * @return array<int, string> All distinct YYYY-MM cohort months.
     */
    private function get_all_cohort_months(): array {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $paid_sql     = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT DATE_FORMAT(MIN(created_at), '%%Y-%%m') AS cohort_month
                 FROM {$orders_table}
                 WHERE status IN ({$paid_sql})
                   AND customer_email != ''
                 GROUP BY customer_email, currency
                 LIMIT %d",
                10000
            )
        );

        return array_values( array_unique( $rows ) );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_matrix( string $currency = 'USD' ): array {
        global $wpdb;

        $cohorts_table   = Schema::cohorts_table();
        $retention_table = Schema::cohort_retention_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cohorts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$cohorts_table}
                 WHERE currency = %s
                 ORDER BY cohort_month ASC",
                $currency
            ),
            ARRAY_A
        );

        if ( empty( $cohorts ) ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $retention_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$retention_table}
                 WHERE currency = %s
                 ORDER BY cohort_month ASC, months_after ASC",
                $currency
            ),
            ARRAY_A
        );

        $retention_map = array();
        foreach ( $retention_rows as $row ) {
            $retention_map[ $row['cohort_month'] ][ (int) $row['months_after'] ] = $row;
        }

        $matrix = array();
        foreach ( $cohorts as $cohort ) {
            $month = $cohort['cohort_month'];
            $count = (int) $cohort['customer_count'];
            $entry = array(
                'cohort_month'       => $month,
                'customer_count'     => $count,
                'first_order_revenue' => (int) $cohort['first_order_revenue'],
                'total_revenue'      => (int) $cohort['total_revenue'],
                'avg_ltv'            => (int) $cohort['avg_ltv'],
                'retention'          => array(),
            );

            for ( $m = 0; $m <= self::RETENTION_MONTHS; $m++ ) {
                $ret       = $retention_map[ $month ][ $m ] ?? null;
                $returning = $ret ? (int) $ret['returning_customers'] : 0;
                $entry['retention'][ $m ] = array(
                    'returning_customers' => $returning,
                    'rate'                => $count > 0 ? round( $returning / $count * 100, 1 ) : 0.0,
                    'revenue'             => $ret ? (int) $ret['revenue'] : 0,
                );
            }

            $matrix[] = $entry;
        }

        return $matrix;
    }
}
