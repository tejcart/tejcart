<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Trend_Report {

    /**
     * Get daily trend data for revenue, orders, AOV, and customer count.
     *
     * @return array<int, array{day: string, revenue: int, order_count: int, aov: int, customer_count: int}>
     */
    public function get_daily_trends( string $date_from, string $date_to, string $currency = 'USD' ): array {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'tejcart_daily_summary';
        $orders_table  = $wpdb->prefix . 'tejcart_orders';
        $paid_sql      = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $summary_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT day, revenue, order_count
                 FROM {$summary_table}
                 WHERE day BETWEEN %s AND %s
                   AND currency = %s
                 ORDER BY day ASC",
                $date_from,
                $date_to,
                $currency
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $customer_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day, COUNT(DISTINCT customer_email) AS customer_count
                 FROM {$orders_table}
                 WHERE created_at BETWEEN %s AND %s
                   AND currency = %s
                   AND status IN ({$paid_sql})
                   AND customer_email != ''
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59',
                $currency
            ),
            ARRAY_A
        );

        $customer_map = array();
        foreach ( $customer_rows as $row ) {
            $customer_map[ $row['day'] ] = (int) $row['customer_count'];
        }

        $result = array();
        foreach ( $summary_rows as $row ) {
            $day         = $row['day'];
            $revenue     = (int) $row['revenue'];
            $order_count = (int) $row['order_count'];
            $result[]    = array(
                'day'            => $day,
                'revenue'        => $revenue,
                'order_count'    => $order_count,
                'aov'            => $order_count > 0 ? intdiv( $revenue, $order_count ) : 0,
                'customer_count' => $customer_map[ $day ] ?? 0,
            );
        }

        return $result;
    }

    /**
     * Get monthly trend data.
     *
     * @return array<int, array{month: string, revenue: int, order_count: int, aov: int, customer_count: int}>
     */
    public function get_monthly_trends( string $date_from, string $date_to, string $currency = 'USD' ): array {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'tejcart_daily_summary';
        $orders_table  = $wpdb->prefix . 'tejcart_orders';
        $paid_sql      = Analytics_Advanced::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $summary_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(day, '%%Y-%%m') AS month,
                        SUM(revenue) AS revenue,
                        SUM(order_count) AS order_count
                 FROM {$summary_table}
                 WHERE day BETWEEN %s AND %s
                   AND currency = %s
                 GROUP BY DATE_FORMAT(day, '%%Y-%%m')
                 ORDER BY month ASC",
                $date_from,
                $date_to,
                $currency
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $customer_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month,
                        COUNT(DISTINCT customer_email) AS customer_count
                 FROM {$orders_table}
                 WHERE created_at BETWEEN %s AND %s
                   AND currency = %s
                   AND status IN ({$paid_sql})
                   AND customer_email != ''
                 GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
                 ORDER BY month ASC",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59',
                $currency
            ),
            ARRAY_A
        );

        $customer_map = array();
        foreach ( $customer_rows as $row ) {
            $customer_map[ $row['month'] ] = (int) $row['customer_count'];
        }

        $result = array();
        foreach ( $summary_rows as $row ) {
            $month       = $row['month'];
            $revenue     = (int) $row['revenue'];
            $order_count = (int) $row['order_count'];
            $result[]    = array(
                'month'          => $month,
                'revenue'        => $revenue,
                'order_count'    => $order_count,
                'aov'            => $order_count > 0 ? intdiv( $revenue, $order_count ) : 0,
                'customer_count' => $customer_map[ $month ] ?? 0,
            );
        }

        return $result;
    }
}
