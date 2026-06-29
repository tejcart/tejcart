<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTV_Calculator {

    private const CHANNELS   = array( 'direct', 'referral', 'search', 'social', 'email', 'paid' );
    private const CHUNK_SIZE = 5000;

    /**
     * Full rebuild — chunked by distinct customer emails.
     *
     * Processes CHUNK_SIZE customers per invocation. When more remain,
     * schedules the next batch via Action Scheduler. The first call
     * truncates the table; subsequent chunks append with UPSERT.
     *
     * @param int $offset Row offset into the distinct customer list.
     * @return bool True when this was the final chunk (no more customers remain).
     */
    public function rebuild_all( int $offset = 0 ): bool {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $meta_table   = $wpdb->prefix . 'tejcart_order_meta';
        $ltv_table    = Schema::customer_ltv_table();
        $paid_sql     = Analytics_Advanced::paid_statuses_sql();

        if ( 0 === $offset ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( "TRUNCATE TABLE {$ltv_table}" );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$ltv_table}
                    (customer_email, currency, first_order_date, last_order_date,
                     order_count, total_revenue, avg_order_value, acquisition_channel, cohort_month)
                 SELECT
                    o.customer_email,
                    o.currency,
                    MIN(o.created_at) AS first_order_date,
                    MAX(o.created_at) AS last_order_date,
                    COUNT(o.id) AS order_count,
                    SUM(o.total) AS total_revenue,
                    SUM(o.total) DIV COUNT(o.id) AS avg_order_value,
                    COALESCE(
                        (SELECT m.meta_value
                         FROM {$meta_table} m
                         INNER JOIN {$orders_table} fo
                            ON fo.id = m.order_id
                           AND fo.customer_email = o.customer_email
                           AND fo.currency = o.currency
                         WHERE m.meta_key = '_acquisition_channel'
                         ORDER BY fo.created_at ASC
                         LIMIT 1),
                        'direct'
                    ) AS acquisition_channel,
                    DATE_FORMAT(MIN(o.created_at), '%%Y-%%m') AS cohort_month
                 FROM {$orders_table} o
                 WHERE o.status IN ({$paid_sql})
                   AND o.customer_email != ''
                   AND o.customer_email IN (
                       SELECT sub.email FROM (
                           SELECT DISTINCT customer_email AS email
                           FROM {$orders_table}
                           WHERE status IN ({$paid_sql})
                             AND customer_email != ''
                           ORDER BY customer_email
                           LIMIT %d OFFSET %d
                       ) sub
                   )
                 GROUP BY o.customer_email, o.currency
                 ON DUPLICATE KEY UPDATE
                    first_order_date    = VALUES(first_order_date),
                    last_order_date     = VALUES(last_order_date),
                    order_count         = VALUES(order_count),
                    total_revenue       = VALUES(total_revenue),
                    avg_order_value     = VALUES(avg_order_value),
                    acquisition_channel = VALUES(acquisition_channel),
                    cohort_month        = VALUES(cohort_month)",
                self::CHUNK_SIZE,
                $offset
            )
        );

        $next_offset = $offset + self::CHUNK_SIZE;
        if ( $this->has_more_customers( $next_offset ) ) {
            Analytics_Advanced::schedule_chunked_rebuild( $next_offset );
            return false;
        }

        return true;
    }

    /**
     * Incremental upsert for a single customer across all currencies.
     */
    public function update_customer( string $email ): void {
        if ( '' === $email ) {
            return;
        }

        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $meta_table   = $wpdb->prefix . 'tejcart_order_meta';
        $ltv_table    = Schema::customer_ltv_table();
        $paid_sql     = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_orders = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table}
                 WHERE customer_email = %s AND status IN ({$paid_sql})",
                $email
            )
        );

        if ( 0 === $has_orders ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( $ltv_table, array( 'customer_email' => $email ) );
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$ltv_table}
                    (customer_email, currency, first_order_date, last_order_date,
                     order_count, total_revenue, avg_order_value, acquisition_channel, cohort_month)
                 SELECT
                    o.customer_email,
                    o.currency,
                    MIN(o.created_at) AS first_order_date,
                    MAX(o.created_at) AS last_order_date,
                    COUNT(o.id) AS order_count,
                    SUM(o.total) AS total_revenue,
                    SUM(o.total) DIV COUNT(o.id) AS avg_order_value,
                    COALESCE(
                        (SELECT m.meta_value
                         FROM {$meta_table} m
                         INNER JOIN {$orders_table} fo
                            ON fo.id = m.order_id
                           AND fo.customer_email = o.customer_email
                           AND fo.currency = o.currency
                         WHERE m.meta_key = '_acquisition_channel'
                         ORDER BY fo.created_at ASC
                         LIMIT 1),
                        'direct'
                    ) AS acquisition_channel,
                    DATE_FORMAT(MIN(o.created_at), '%%Y-%%m') AS cohort_month
                 FROM {$orders_table} o
                 WHERE o.customer_email = %s
                   AND o.status IN ({$paid_sql})
                 GROUP BY o.customer_email, o.currency
                 ON DUPLICATE KEY UPDATE
                    first_order_date    = VALUES(first_order_date),
                    last_order_date     = VALUES(last_order_date),
                    order_count         = VALUES(order_count),
                    total_revenue       = VALUES(total_revenue),
                    avg_order_value     = VALUES(avg_order_value),
                    acquisition_channel = VALUES(acquisition_channel),
                    cohort_month        = VALUES(cohort_month)",
                $email
            )
        );
    }

    private function has_more_customers( int $offset ): bool {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $paid_sql     = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT customer_email)
                 FROM {$orders_table}
                 WHERE status IN ({$paid_sql})
                   AND customer_email != ''
                 LIMIT %d",
                1
            )
        );

        return $count > $offset;
    }

    /**
     * @return array<string, array{customer_count: int, total_revenue: int, avg_ltv: int, avg_orders: float}>
     */
    public function get_by_channel( string $currency = 'USD' ): array {
        global $wpdb;

        $ltv_table = Schema::customer_ltv_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    acquisition_channel,
                    COUNT(*) AS customer_count,
                    SUM(total_revenue) AS total_revenue,
                    CASE WHEN COUNT(*) > 0 THEN SUM(total_revenue) DIV COUNT(*) ELSE 0 END AS avg_ltv,
                    AVG(order_count) AS avg_orders
                 FROM {$ltv_table}
                 WHERE currency = %s
                 GROUP BY acquisition_channel
                 ORDER BY avg_ltv DESC",
                $currency
            ),
            ARRAY_A
        );

        $result = array();
        foreach ( self::CHANNELS as $ch ) {
            $result[ $ch ] = array(
                'customer_count' => 0,
                'total_revenue'  => 0,
                'avg_ltv'        => 0,
                'avg_orders'     => 0.0,
            );
        }

        foreach ( $rows as $row ) {
            $channel = $row['acquisition_channel'];
            $result[ $channel ] = array(
                'customer_count' => (int) $row['customer_count'],
                'total_revenue'  => (int) $row['total_revenue'],
                'avg_ltv'        => (int) $row['avg_ltv'],
                'avg_orders'     => round( (float) $row['avg_orders'], 1 ),
            );
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_top_customers( string $currency = 'USD', int $limit = 25 ): array {
        global $wpdb;

        $ltv_table = Schema::customer_ltv_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT customer_email, order_count, total_revenue, avg_order_value,
                        first_order_date, last_order_date, acquisition_channel, cohort_month
                 FROM {$ltv_table}
                 WHERE currency = %s
                 ORDER BY total_revenue DESC
                 LIMIT %d",
                $currency,
                $limit
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Format a minor-units value for display.
     */
    public static function format_minor_units( int $minor, string $currency = 'USD' ): string {
        $major = Currency::from_minor_units( $minor, $currency );
        return number_format( $major, Currency::decimals( $currency ) );
    }
}
