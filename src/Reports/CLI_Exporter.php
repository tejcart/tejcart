<?php
/**
 * Stateless CSV exporter for the reports CLI command.
 *
 * Mirrors the per-type SQL used by {@see \TejCart\Admin\Reports::handle_export()}
 * so a `wp tejcart reports export <type>` invocation pulls the same rows
 * an admin would get via the browser. Designed to be callable from cron
 * / BI pipelines without a wp-admin nonce.
 *
 * @package TejCart\Reports
 */

declare( strict_types=1 );

namespace TejCart\Reports;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CLI_Exporter {

    /**
     * Page size for chunked SELECTs. Sized so a single chunk fits well
     * inside PHP's default 128M memory limit on the widest row. Matches
     * the sibling admin streamer's `CSV_EXPORT_CHUNK` constant.
     */
    private const CHUNK = 1000;

    /**
     * Stream a single report type as CSV to the given file handle.
     *
     * F-003: every monetary arm emits a `Currency` column and converts
     * each row using the order's own `currency`, so multi-currency
     * stores don't conflate JPY and USD totals.
     * F-004: every arm runs paginated `LIMIT/OFFSET` SELECTs through
     * {@see stream_chunks()}, so a 400k-customer export doesn't hold
     * the entire grouped result set in memory before the first byte.
     * F-010: site-local → UTC bounds conversion lives in
     * {@see Date_Range}, not a private duplicate.
     *
     * @param resource $handle Open file handle (e.g. STDOUT or fopen).
     * @param string   $type   sales|customers|stock|tax|refunds|products.
     * @param string   $from   YYYY-MM-DD (site local, converted to UTC for binds).
     * @param string   $to     YYYY-MM-DD.
     * @param string   $scope  For 'stock' only: low|all.
     * @return int Number of data rows written (header excluded).
     */
    public static function stream( $handle, string $type, string $from, string $to, string $scope = 'low' ): int {
        global $wpdb;
        $orders_t  = $wpdb->prefix . 'tejcart_orders';
        $items_t   = $wpdb->prefix . 'tejcart_order_items';
        $prods_t   = $wpdb->prefix . 'tejcart_products';
        $refunds_t = $wpdb->prefix . 'tejcart_order_refunds';

        [ $range_lo, $range_hi ] = Date_Range::to_utc_datetime_bounds( $from, $to );

        $rows_written = 0;

        switch ( $type ) {
            case 'sales':
                fputcsv( $handle, array( 'Date', 'Orders', 'Currency', 'Revenue' ), ',', '"', '' );
                $rows_written = self::stream_chunks(
                    $handle,
                    static function ( int $limit, int $offset ) use ( $wpdb, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT DATE(created_at) as day, currency, COUNT(*) as cnt, SUM(total) as rev
                               FROM {$orders_t}
                              WHERE status IN ('completed','processing')
                                AND created_at >= %s
                                AND created_at <  %s
                              GROUP BY DATE(created_at), currency
                              ORDER BY day ASC, currency ASC
                              LIMIT %d OFFSET %d",
                            $range_lo,
                            $range_hi,
                            $limit,
                            $offset
                        ) );
                    },
                    static function ( $r ) use ( $handle ) {
                        $currency = (string) ( $r->currency ?? 'USD' );
                        fputcsv( $handle, tejcart_csv_sanitize_row( array(
                            (string) $r->day,
                            (int) $r->cnt,
                            $currency,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->rev, $currency ),
                        ) ), ',', '"', '' );
                    }
                );
                break;

            case 'customers':
                fputcsv( $handle, array( 'Customer', 'Email', 'Currency', 'Orders', 'Total Spend' ), ',', '"', '' );
                $rows_written = self::stream_chunks(
                    $handle,
                    static function ( int $limit, int $offset ) use ( $wpdb, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT customer_name, customer_email, currency, COUNT(*) as cnt, SUM(total) as spend
                               FROM {$orders_t}
                              WHERE status IN ('completed','processing')
                                AND created_at >= %s
                                AND created_at <  %s
                              GROUP BY customer_email, currency
                              ORDER BY spend DESC
                              LIMIT %d OFFSET %d",
                            $range_lo,
                            $range_hi,
                            $limit,
                            $offset
                        ) );
                    },
                    static function ( $r ) use ( $handle ) {
                        $currency = (string) ( $r->currency ?? 'USD' );
                        fputcsv( $handle, tejcart_csv_sanitize_row( array(
                            (string) $r->customer_name,
                            (string) $r->customer_email,
                            $currency,
                            (int) $r->cnt,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->spend, $currency ),
                        ) ), ',', '"', '' );
                    }
                );
                break;

            case 'stock':
                fputcsv( $handle, array( 'Product', 'SKU', 'Stock', 'Status' ), ',', '"', '' );
                $threshold   = max( 0, (int) get_option( 'tejcart_low_stock_threshold', 2 ) );
                $is_all      = 'all' === $scope;
                $rows_written = self::stream_chunks(
                    $handle,
                    static function ( int $limit, int $offset ) use ( $wpdb, $prods_t, $threshold, $is_all ) {
                        if ( $is_all ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            return $wpdb->get_results( $wpdb->prepare(
                                "SELECT name, sku, stock_quantity, stock_status FROM {$prods_t}
                                  WHERE status = 'publish' AND manage_stock = 1
                                  ORDER BY stock_quantity ASC, id ASC
                                  LIMIT %d OFFSET %d",
                                $limit,
                                $offset
                            ) );
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT name, sku, stock_quantity, stock_status FROM {$prods_t}
                              WHERE status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d
                              ORDER BY stock_quantity ASC, id ASC
                              LIMIT %d OFFSET %d",
                            $threshold,
                            $limit,
                            $offset
                        ) );
                    },
                    static function ( $r ) use ( $handle ) {
                        fputcsv( $handle, tejcart_csv_sanitize_row( array(
                            (string) $r->name,
                            (string) $r->sku,
                            (int) $r->stock_quantity,
                            (string) $r->stock_status,
                        ) ), ',', '"', '' );
                    }
                );
                break;

            case 'tax':
                fputcsv( $handle, array( 'Status', 'Currency', 'Tax Total' ), ',', '"', '' );
                // GROUP BY (status, currency) is bounded by the (~10 statuses
                // × ~handful of currencies) cardinality so a single
                // unpaginated query is safe. Still iterates rather than
                // materialising for consistency with the other arms.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT status, currency, SUM(tax_total) as tax FROM {$orders_t}
                      WHERE created_at >= %s AND created_at < %s
                      GROUP BY status, currency
                      ORDER BY status ASC, currency ASC",
                    $range_lo,
                    $range_hi
                ) );
                foreach ( (array) $rows as $r ) {
                    $currency = (string) ( $r->currency ?? 'USD' );
                    fputcsv( $handle, tejcart_csv_sanitize_row( array(
                        (string) $r->status,
                        $currency,
                        \TejCart\Money\Currency::from_minor_units( (int) $r->tax, $currency ),
                    ) ), ',', '"', '' );
                    ++$rows_written;
                }
                break;

            case 'refunds':
                fputcsv( $handle, array( 'Date', 'Order ID', 'Customer', 'Currency', 'Amount', 'Reason' ), ',', '"', '' );
                $rows_written = self::stream_chunks(
                    $handle,
                    static function ( int $limit, int $offset ) use ( $wpdb, $refunds_t, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT r.created_at, r.order_id, o.customer_name, o.currency, r.amount, r.reason
                               FROM {$refunds_t} r LEFT JOIN {$orders_t} o ON o.id = r.order_id
                              WHERE r.created_at >= %s AND r.created_at < %s
                              ORDER BY r.created_at DESC, r.id DESC
                              LIMIT %d OFFSET %d",
                            $range_lo,
                            $range_hi,
                            $limit,
                            $offset
                        ) );
                    },
                    static function ( $r ) use ( $handle ) {
                        $currency = (string) ( $r->currency ?? 'USD' );
                        fputcsv( $handle, tejcart_csv_sanitize_row( array(
                            (string) $r->created_at,
                            (int) $r->order_id,
                            (string) $r->customer_name,
                            $currency,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->amount, $currency ),
                            (string) $r->reason,
                        ) ), ',', '"', '' );
                    }
                );
                break;

            case 'products':
                fputcsv( $handle, array( 'Product', 'SKU', 'Currency', 'Units sold', 'Revenue' ), ',', '"', '' );
                $rows_written = self::stream_chunks(
                    $handle,
                    static function ( int $limit, int $offset ) use ( $wpdb, $items_t, $orders_t, $prods_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT p.name AS name, p.sku AS sku, o.currency,
                                    SUM(oi.quantity) AS units,
                                    SUM(oi.line_total) AS revenue
                               FROM {$items_t} oi
                          LEFT JOIN {$orders_t} o ON o.id = oi.order_id
                          LEFT JOIN {$prods_t}  p ON p.id = oi.product_id
                              WHERE o.status IN ('completed','processing')
                                AND o.created_at >= %s
                                AND o.created_at <  %s
                              GROUP BY oi.product_id, o.currency
                              ORDER BY revenue DESC
                              LIMIT %d OFFSET %d",
                            $range_lo,
                            $range_hi,
                            $limit,
                            $offset
                        ) );
                    },
                    static function ( $r ) use ( $handle ) {
                        $currency = (string) ( $r->currency ?? 'USD' );
                        fputcsv( $handle, tejcart_csv_sanitize_row( array(
                            (string) $r->name,
                            (string) $r->sku,
                            $currency,
                            (int) $r->units,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->revenue, $currency ),
                        ) ), ',', '"', '' );
                    }
                );
                break;
        }

        return $rows_written;
    }

    /**
     * Paginate `$fetcher( int $limit, int $offset ): array` through CHUNK
     * pages, write each row via `$row_writer`, flush after every page,
     * and stop when the last page returns fewer rows than CHUNK.
     *
     * F-004 — keeps peak memory bounded on million-row exports.
     *
     * @param resource $handle
     * @param callable $fetcher
     * @param callable $row_writer
     * @return int Total rows written.
     */
    private static function stream_chunks( $handle, callable $fetcher, callable $row_writer ): int {
        $rows_written = 0;
        $offset       = 0;
        do {
            $rows = (array) $fetcher( self::CHUNK, $offset );
            foreach ( $rows as $row ) {
                $row_writer( $row );
                ++$rows_written;
            }
            fflush( $handle );
            $offset += self::CHUNK;
        } while ( count( $rows ) === self::CHUNK );
        return $rows_written;
    }
}
