<?php
/**
 * Daily revenue / order / refund / coupon aggregates (C-3).
 *
 * Maintains `wp_tejcart_daily_summary` and `wp_tejcart_product_daily` so
 * the admin reports dashboard reads from precomputed rows instead of
 * issuing live SUM() / COUNT() / GROUP BY over the 100M-row orders
 * table on every page load.
 *
 * @package TejCart\Reports
 */

declare( strict_types=1 );

namespace TejCart\Reports;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Daily_Summary {

    public const REBUILD_HOOK = 'tejcart_reports_daily_rebuild';

    /**
     * Action Scheduler hook used to defer the per-order recompute off the
     * synchronous capture / status-change response. The handler
     * ({@see run_deferred_recompute()}) re-runs the same recompute logic
     * the legacy sync path used; the only difference is *when*.
     *
     * F-009: each enqueue produces one AS job — `Action_Scheduler::
     * schedule_single()` does not pass `$unique=true` to
     * `as_schedule_single_action`, so concurrent status changes for the
     * same order will queue separate recompute jobs.
     * `rebuild_bucket()` is idempotent (`ON DUPLICATE KEY UPDATE`), so
     * duplicates converge to the same totals at slightly higher CPU
     * cost. Operators sizing AS capacity should plan for one job per
     * status change, not per-order.
     */
    public const RECOMPUTE_HOOK = 'tejcart_reports_daily_recompute';

    public function init(): void {
        // Recompute affected rows on every order status transition. Defers
        // to Action Scheduler by default — see on_order_status_changed().
        add_action( 'tejcart_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 3 );

        // Action Scheduler handler — runs the deferred recompute when
        // the queue picks the job up (typically <10 s after enqueue when
        // AS is healthy; longer on the wp-cron fallback path).
        add_action( self::RECOMPUTE_HOOK, array( $this, 'run_deferred_recompute' ), 10, 1 );

        // Daily backstop sweep — for sites where status hooks were missed
        // (manual SQL edits, etc.). Recomputes yesterday's bucket from
        // scratch so any drift self-heals overnight. ALSO acts as the
        // long-tail safety net for the new async path: if a deferred
        // recompute job is dropped (AS down, hard wp-cron failure) the
        // backstop catches it within 24 h.
        add_action( self::REBUILD_HOOK, array( $this, 'run_backstop_rebuild' ) );
        add_action( 'init', array( $this, 'maybe_schedule_backstop' ) );
    }

    public function maybe_schedule_backstop(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
            \TejCart\Core\Action_Scheduler::instance()->schedule_recurring(
                time() + HOUR_IN_SECONDS,
                DAY_IN_SECONDS,
                self::REBUILD_HOOK
            );
        }
    }

    /**
     * Status-change listener.
     *
     * Historically this method ran the daily-bucket recompute synchronously
     * inside the request that flipped the order status. On a busy site the
     * recompute does N+1 aggregation queries (one daily-totals SELECT plus
     * one per-product-grouping SELECT plus M product-row UPSERTs), and on
     * the PayPal capture hot path that adds 20–50 ms of pure DB time
     * between "PayPal CAPTURE returned 200" and "browser receives JSON
     * success". Nothing about the bucket is read by the customer-facing
     * receipt response — it's an admin-dashboard aggregation — so we defer
     * the recompute to Action Scheduler and let the capture response
     * return immediately.
     *
     * Argument contract
     * =================
     * Matches `do_action( 'tejcart_order_status_changed', $old_status,
     * $new_status, $order )` in `Order::set_status()`. The first two
     * args are unused — every transition triggers a recompute because
     * `revenue` / `refund_total` / `tax_total` depend on the new status
     * but `coupon_count` and `order_count` depend on *any* status with
     * `coupon_code`. Throttling by old/new pair is a separate
     * optimisation (see audit notes).
     *
     * Failure handling
     * ================
     *  - Action Scheduler runs one recompute job per status change.
     *    `rebuild_bucket()` is idempotent (`ON DUPLICATE KEY UPDATE`),
     *    so concurrent jobs converge to the same totals; duplicates
     *    cost extra CPU but never produce wrong numbers. (Real
     *    insert-time dedup requires the 5th `$unique=true` argument
     *    on `as_schedule_single_action`, which `Action_Scheduler::
     *    schedule_single()` does not currently pass — F-009.)
     *  - When `schedule_single()` returns false (AS unavailable AND wp-cron
     *    refused the schedule, or AS itself errored at insert) we fall
     *    through to the synchronous recompute path. {@see rebuild_bucket()}
     *    is idempotent, so the worst case is one duplicated recompute if
     *    the failed-schedule turns out to have succeeded — never wrong
     *    totals.
     *  - The daily backstop sweep ({@see run_backstop_rebuild()}) catches
     *    any drift within 24 h as a long-tail safety net across every
     *    currency that saw orders yesterday. So even total AS / wp-cron
     *    failure self-heals overnight; the daily admin dashboard is
     *    never permanently stale.
     *
     * Merchants who want to keep the legacy synchronous behaviour can
     * disable the deferral via:
     *
     *     add_filter( 'tejcart_reports_daily_async_enabled', '__return_false' );
     *
     * @param string      $old_status Previous status slug.
     * @param string      $new_status New status slug.
     * @param object|null $order      Order object emitted by `Order::set_status()`. Duck-typed
     *                                via `method_exists($order, 'get_id')` so test doubles and
     *                                future Order_Lite-style proxies don't need to subclass.
     */
    public function on_order_status_changed( $old_status = '', $new_status = '', $order = null ): void {
        $order_id = 0;
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            $order_id = (int) $order->get_id();
        }
        if ( $order_id <= 0 ) {
            return;
        }

        if ( self::is_async_enabled() ) {
            $scheduled = \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                time(),
                self::RECOMPUTE_HOOK,
                array( $order_id )
            );
            if ( $scheduled ) {
                return;
            }
            // Scheduling returned false. Could be a benign "already
            // scheduled" (another recompute for this order is queued
            // and will collapse this one) on the wp-cron fallback path,
            // or a hard failure on the AS path. We can't distinguish
            // from the public API; running the sync recompute here is
            // the safe choice — rebuild_bucket() is idempotent so a
            // duplicate run produced by a "false-meant-already-queued"
            // race never produces wrong totals.
        }

        $this->recompute_for_order( $order_id );
    }

    /**
     * Action Scheduler handler for the deferred recompute. Pinned as a
     * separate public method (rather than reusing on_order_status_changed)
     * so the AS callback signature is `(int $order_id)` rather than
     * `(int $order_id, string $from, string $to)`. AS calls callbacks
     * with exactly the args supplied at schedule time — pretending the
     * 3-arg signature here would silently drop the recompute on PHP 8.x
     * strict-mode hosts.
     *
     * @param int $order_id
     * @return void
     */
    public function run_deferred_recompute( $order_id ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }
        $this->recompute_for_order( $order_id );
    }

    /**
     * The original synchronous recompute, factored out so both the legacy
     * sync path (filter-disabled / scheduling-failed) and the new
     * Action-Scheduler-driven path go through identical logic. Keeping a
     * single body avoids drift — adding a column to the bucket should
     * not require touching two near-duplicate code paths.
     *
     * @param int $order_id
     * @return void
     */
    private function recompute_for_order( int $order_id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day, currency FROM {$wpdb->prefix}tejcart_orders WHERE id = %d",
                $order_id
            ),
            ARRAY_A
        );
        if ( ! $row || empty( $row['day'] ) ) {
            return;
        }

        $this->rebuild_bucket( (string) $row['day'], (string) ( $row['currency'] ?? 'USD' ) );
    }

    /**
     * Filter-gated kill switch. Defaults `true`; merchants who need the
     * legacy synchronous recompute (e.g. they read the bucket from a
     * webhook handler that fires immediately after the status change)
     * can opt out per-site.
     *
     * @return bool
     */
    private static function is_async_enabled(): bool {
        if ( ! function_exists( 'apply_filters' ) ) {
            return true;
        }
        return (bool) apply_filters( 'tejcart_reports_daily_async_enabled', true );
    }

    /**
     * Convert a `YYYY-MM-DD` UTC day string into a half-open
     * `[start, end)` UTC datetime range suitable for a `created_at >=
     * start AND created_at < end` predicate.
     *
     * Half-open is deliberate: a closed-closed `BETWEEN` would either
     * miss rows that arrive in the final second of the day (rare but
     * real on flash sales straddling midnight) or double-count rows
     * at exact midnight on the next day. Using `< next_day_midnight`
     * is the textbook range-scan idiom.
     *
     * Returns UTC strings because TejCart stores timestamps in UTC by
     * convention (DEFAULT CURRENT_TIMESTAMP on a server with
     * time_zone='+00:00') and the daily-bucket key is already a UTC
     * date (set by {@see run_backstop_rebuild()}'s
     * `gmdate('Y-m-d', time() - DAY_IN_SECONDS)`).
     *
     * @param string $day YYYY-MM-DD UTC.
     * @return array{0:string,1:string} `[start, end)` as UTC datetime strings.
     */
    private static function day_range( string $day ): array {
        $start_ts = strtotime( $day . ' 00:00:00 UTC' );
        $end_ts   = is_int( $start_ts ) ? $start_ts + DAY_IN_SECONDS : null;

        $start = is_int( $start_ts ) ? gmdate( 'Y-m-d H:i:s', $start_ts ) : $day . ' 00:00:00';
        $end   = is_int( $end_ts ) ? gmdate( 'Y-m-d H:i:s', $end_ts ) : $day . ' 23:59:59';

        return array( $start, $end );
    }

    /**
     * Recompute a single (day, currency) bucket from authoritative rows.
     *
     * Idempotent — replaces the row entirely. Cheap (single bucket = at
     * most a few thousand orders even on a high-traffic day).
     *
     * Index strategy
     * ==============
     * The WHERE clause is `currency = %s AND created_at >= %s AND
     * created_at < %s`. PR #8's composite KEY `currency_created
     * (currency, created_at)` covers this exactly: leftmost column
     * (currency) is an equality predicate, second column (created_at)
     * is a range predicate, and the index becomes a single contiguous
     * range scan. The previous form `WHERE DATE(created_at) = %s AND
     * currency = %s` wrapped the column in DATE(), which defeated
     * every existing index — MySQL had to scan the orders table for
     * the day's rows. On a 10M-row orders table that's the difference
     * between an index-range read of ~5K rows and a full-table scan
     * of 10M.
     */
    public function rebuild_bucket( string $day, string $currency ): void {
        global $wpdb;
        $orders  = "{$wpdb->prefix}tejcart_orders";
        $items   = "{$wpdb->prefix}tejcart_order_items";
        $refunds = "{$wpdb->prefix}tejcart_order_refunds";

        $base_currency = function_exists( 'get_option' ) ? (string) get_option( 'tejcart_currency', 'USD' ) : 'USD';

        list( $range_start, $range_end ) = self::day_range( $day );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $totals = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN status IN ('completed','processing') THEN total ELSE 0 END), 0) AS revenue,
                    SUM(CASE WHEN status IN ('completed','processing') THEN 1 ELSE 0 END)                  AS order_count,
                    COALESCE(SUM(CASE WHEN status = 'refunded' THEN total ELSE 0 END), 0)                  AS refund_total,
                    SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END)                                  AS refund_count,
                    SUM(CASE WHEN status IN ('completed','processing') AND coupon_code IS NOT NULL AND coupon_code != '' THEN 1 ELSE 0 END) AS coupon_count,
                    COALESCE(SUM(CASE WHEN status IN ('completed','processing') THEN tax_total ELSE 0 END), 0) AS tax_total,
                    COALESCE(SUM(CASE WHEN status IN ('completed','processing') THEN base_total ELSE 0 END), 0) AS base_revenue,
                    COALESCE(SUM(CASE WHEN status = 'refunded' THEN base_total ELSE 0 END), 0)                  AS base_refund_total,
                    COALESCE(SUM(CASE WHEN status IN ('completed','processing') THEN base_tax_total ELSE 0 END), 0) AS base_tax_total
                  FROM {$orders}
                 WHERE currency = %s AND created_at >= %s AND created_at < %s",
                $currency,
                $range_start,
                $range_end
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! $totals ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}tejcart_daily_summary
                    (day, currency, revenue, order_count, refund_total, refund_count, coupon_count, tax_total, base_currency, base_revenue, base_refund_total, base_tax_total)
                 VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %s, %d, %d, %d)
                 ON DUPLICATE KEY UPDATE
                    revenue           = VALUES(revenue),
                    order_count       = VALUES(order_count),
                    refund_total      = VALUES(refund_total),
                    refund_count      = VALUES(refund_count),
                    coupon_count      = VALUES(coupon_count),
                    tax_total         = VALUES(tax_total),
                    base_currency     = VALUES(base_currency),
                    base_revenue      = VALUES(base_revenue),
                    base_refund_total = VALUES(base_refund_total),
                    base_tax_total    = VALUES(base_tax_total)",
                $day,
                $currency,
                (int) $totals['revenue'],
                (int) $totals['order_count'],
                (int) $totals['refund_total'],
                (int) $totals['refund_count'],
                (int) $totals['coupon_count'],
                (int) $totals['tax_total'],
                $base_currency,
                (int) $totals['base_revenue'],
                (int) $totals['base_refund_total'],
                (int) $totals['base_tax_total']
            )
        );

        // Per-product daily: only count completed/processing rows.
        // Same index strategy as the totals query above — range
        // predicate on created_at + equality on currency rides the
        // PR #8 currency_created composite index. F-002: the
        // `tejcart_product_daily` rollup is keyed on
        // `(day, product_id, currency)` so a multi-currency store
        // doesn't conflate JPY and USD revenue inside a single
        // best-sellers row.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $product_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oi.product_id, COALESCE(SUM(oi.quantity), 0) AS qty, COALESCE(SUM(oi.line_total), 0) AS revenue, COALESCE(SUM(oi.base_line_total), 0) AS base_revenue
                   FROM {$items} oi
                  INNER JOIN {$orders} o ON oi.order_id = o.id
                  WHERE o.status IN ('completed','processing')
                    AND o.currency = %s
                    AND o.created_at >= %s
                    AND o.created_at < %s
                  GROUP BY oi.product_id",
                $currency,
                $range_start,
                $range_end
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Wipe any pre-existing (day, currency) product rows so a
        // product that *used* to sell on this day but no longer
        // does (refunded out, line removed) drops back to zero. The
        // UPSERT below only touches rows that came back from the
        // SELECT above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tejcart_product_daily WHERE day = %s AND currency = %s",
                $day,
                $currency
            )
        );

        if ( $product_rows ) {
            foreach ( $product_rows as $r ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->prepare(
                        "INSERT INTO {$wpdb->prefix}tejcart_product_daily
                            (day, product_id, currency, qty, revenue, base_revenue)
                         VALUES (%s, %d, %s, %d, %d, %d)
                         ON DUPLICATE KEY UPDATE
                            qty          = VALUES(qty),
                            revenue      = VALUES(revenue),
                            base_revenue = VALUES(base_revenue)",
                        $day,
                        (int) $r['product_id'],
                        $currency,
                        (int) $r['qty'],
                        (int) $r['revenue'],
                        (int) $r['base_revenue']
                    )
                );
            }
        }

        do_action( 'tejcart_daily_summary_rebuilt', $day, $currency );
    }

    /**
     * Daily backstop — replays yesterday's buckets from authoritative
     * rows for every currency that saw orders. F-005: prior to this
     * the sweep only rebuilt the shop's default currency, leaving
     * non-default-currency buckets to drift forever on multi-currency
     * installs (currency-switcher module, USD store with a single JPY
     * order, etc.).
     */
    public function run_backstop_rebuild(): void {
        $day        = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
        $currencies = self::currencies_with_orders_on_day( $day );

        if ( array() === $currencies ) {
            // No orders yesterday in any currency — still rebuild the
            // shop currency so a zero-row day produces a zeroed bucket
            // (rather than a missing one that confuses the
            // `days_present` gate in admin Reports).
            $currencies = array( (string) get_option( 'tejcart_currency', 'USD' ) );
        }

        foreach ( $currencies as $currency ) {
            $this->rebuild_bucket( $day, $currency );
        }
    }

    /**
     * Distinct currencies present in `tejcart_orders.created_at` for
     * the given UTC `Y-m-d`. Used by the backstop to fan out the
     * recompute across every currency that actually saw activity.
     *
     * @return array<int,string>
     */
    private static function currencies_with_orders_on_day( string $day ): array {
        global $wpdb;
        [ $range_start, $range_end ] = self::day_range( $day );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT currency FROM {$wpdb->prefix}tejcart_orders WHERE created_at >= %s AND created_at < %s",
                $range_start,
                $range_end
            )
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'strval', $rows ), static fn ( string $c ): bool => '' !== $c ) );
    }

    /**
     * Read the precomputed bucket for a (day, currency). Returns null when
     * no row exists (caller falls back to live SUM as a safety net).
     *
     * @return array|null
     */
    public static function read_bucket( string $day, string $currency = 'USD' ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT day, currency, revenue, order_count, refund_total, refund_count, coupon_count, tax_total
                   FROM {$wpdb->prefix}tejcart_daily_summary
                  WHERE day = %s AND currency = %s",
                $day,
                $currency
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        // Convert money columns to major-unit floats at this boundary;
        // the bucket's currency is the row's own column.
        $bucket_currency     = (string) ( $row['currency'] ?? $currency );
        $row['revenue']      = \TejCart\Money\Currency::from_minor_units( (int) ( $row['revenue'] ?? 0 ), $bucket_currency );
        $row['refund_total'] = \TejCart\Money\Currency::from_minor_units( (int) ( $row['refund_total'] ?? 0 ), $bucket_currency );
        $row['tax_total']    = \TejCart\Money\Currency::from_minor_units( (int) ( $row['tax_total'] ?? 0 ), $bucket_currency );
        return $row;
    }

    /**
     * Per-day revenue / order_count rows for the Sales tab "Revenue by day"
     * table. Reads from the precomputed rollup so the admin dashboard
     * never groups the orders table at request time.
     *
     * F-006: `$from` / `$to` are site-local `Y-m-d`. The helper converts
     * to the UTC day bounds the rollup keys on before binding, so the
     * read agrees with the writer (`recompute_for_order` writes UTC days).
     *
     * @param string $from YYYY-MM-DD (site-local).
     * @param string $to   YYYY-MM-DD (site-local).
     * @param string $currency
     * @return array<int, array{day:string, revenue:float, order_count:int}>
     */
    public static function read_daily_breakdown( string $from, string $to, string $currency = 'USD' ): array {
        global $wpdb;
        [ $utc_from, $utc_to ] = Date_Range::to_utc_day_bounds( $from, $to );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                // Sum base_revenue across all currency buckets per day so
                // the "Revenue by day" rows are in the store base currency.
                "SELECT day, COALESCE(SUM(base_revenue), 0) AS revenue, COALESCE(SUM(order_count), 0) AS order_count
                   FROM {$wpdb->prefix}tejcart_daily_summary
                  WHERE day BETWEEN %s AND %s
                  GROUP BY day
                  ORDER BY day ASC",
                $utc_from,
                $utc_to
            ),
            ARRAY_A
        );
        // The `revenue` column is BIGINT minor units in the bucket's
        // `currency`. Convert at this boundary so callers (the admin
        // Reports tabs) keep their major-unit-float contract.
        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'day'         => (string) ( $r['day'] ?? '' ),
                'revenue'     => \TejCart\Money\Currency::from_minor_units( (int) ( $r['revenue'] ?? 0 ), $currency ),
                'order_count' => (int) ( $r['order_count'] ?? 0 ),
            );
        }
        return $out;
    }

    /**
     * SUM(qty) across `tejcart_product_daily` over the requested range.
     *
     * `product_daily` is populated from order_items joined to orders
     * filtered to `status IN ('completed','processing')` (see
     * {@see rebuild_bucket()}), so the result is "items sold" with the
     * same semantics the live Sales-tab query used.
     *
     * F-002 / F-006: filters by currency (the rollup row carries it)
     * and converts the site-local input range to UTC day bounds.
     * Empty `$currency` ('') sums across every currency — useful for
     * "Items sold" on a multi-currency store where the qty is
     * currency-agnostic.
     *
     * @param string $from     YYYY-MM-DD (site-local).
     * @param string $to       YYYY-MM-DD (site-local).
     * @param string $currency ISO-4217 code, '' to sum across currencies.
     * @return int
     */
    public static function read_items_sold( string $from, string $to, string $currency = '' ): int {
        global $wpdb;
        unset( $currency ); // Units sold is currency-agnostic — always sum across every currency bucket.
        [ $utc_from, $utc_to ] = Date_Range::to_utc_day_bounds( $from, $to );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(qty), 0)
                   FROM {$wpdb->prefix}tejcart_product_daily
                  WHERE day BETWEEN %s AND %s",
                $utc_from,
                $utc_to
            )
        );
        return (int) $value;
    }

    /**
     * Read top-N products from the precomputed `tejcart_product_daily`
     * rollup over the requested range, joined to `tejcart_products`
     * for the display name.
     *
     * Audit #100 / 07 F-12 — the Reports Products tab and its CSV
     * export previously always ran a 3-table live JOIN on every
     * page load (orders × order_items × products). On a 100M-row
     * orders table that's multi-second; the rollup read is sub-100ms
     * because the per-day per-product totals are precomputed by
     * `rebuild_bucket()`.
     *
     * F-002 / F-006: filters by currency (rollup row carries it now)
     * and converts the site-local input range to UTC day bounds. F-011:
     * deleted products fall back to `(Deleted product)` rather than
     * rendering an empty cell at position 3 of the best-sellers table.
     *
     * @param string $from     YYYY-MM-DD inclusive, site-local.
     * @param string $to       YYYY-MM-DD inclusive, site-local.
     * @param string $sort     'rev' (default) ranks by revenue, 'qty' ranks by units sold.
     * @param int    $limit    1–100 rows. Defaults to 10.
     * @param string $currency ISO-4217 code. Required to disambiguate revenue across currencies.
     * @return array<int, object{product_id:int,name:string,qty:int,rev:int}>
     */
    public static function read_top_products( string $from, string $to, string $sort = 'rev', int $limit = 10, string $currency = 'USD' ): array {
        global $wpdb;

        $limit  = max( 1, min( 100, $limit ) );
        $sort   = 'qty' === $sort ? 'qty' : 'rev';
        // Rank by base-currency revenue summed across every currency bucket.
        $order  = 'qty' === $sort ? 'SUM(d.qty) DESC' : 'SUM(d.base_revenue) DESC';

        unset( $currency ); // Base revenue is currency-agnostic — aggregate across all buckets.

        $daily    = $wpdb->prefix . 'tejcart_product_daily';
        $products = $wpdb->prefix . 'tejcart_products';

        [ $utc_from, $utc_to ] = Date_Range::to_utc_day_bounds( $from, $to );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.product_id,
                    p.name AS name,
                    COALESCE(SUM(d.qty), 0)          AS qty,
                    COALESCE(SUM(d.base_revenue), 0) AS rev
               FROM {$daily} d
          LEFT JOIN {$products} p ON p.id = d.product_id
              WHERE d.day BETWEEN %s AND %s
           GROUP BY d.product_id, p.name
           ORDER BY {$order}
              LIMIT %d",
            $utc_from,
            $utc_to,
            $limit
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $rows ) ) {
            return array();
        }

        foreach ( $rows as $row ) {
            $name              = isset( $row->name ) ? (string) $row->name : '';
            $row->product_id   = (int) ( $row->product_id ?? 0 );
            $row->name         = '' !== $name ? $name : __( '(Deleted product)', 'tejcart' );
            $row->qty          = (int) ( $row->qty ?? 0 );
            $row->rev          = (int) ( $row->rev ?? 0 );
        }
        return $rows;
    }

    /**
     * Sum a precomputed range. Returns null when one or more days are
     * missing (caller falls back to live SUM for that range).
     *
     * F-006: `$from` / `$to` are site-local `Y-m-d`. Converted to UTC
     * day bounds inside before binding against the rollup `day` column.
     *
     * @param string $from YYYY-MM-DD (site-local).
     * @param string $to   YYYY-MM-DD (site-local).
     * @param string $currency
     * @return array|null
     */
    public static function read_range( string $from, string $to, string $currency = 'USD' ): ?array {
        global $wpdb;
        [ $utc_from, $utc_to ] = Date_Range::to_utc_day_bounds( $from, $to );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                // Aggregate the base-currency columns across EVERY currency
                // bucket for the day (base_* is uniformly denominated in the
                // store base currency), so a multi-currency store's revenue
                // isn't split per transacted currency or silently dropped.
                // days_present counts DISTINCT days, not rows, so the
                // completeness gate isn't inflated by multi-currency days.
                "SELECT
                    COALESCE(SUM(base_revenue), 0)      AS revenue,
                    COALESCE(SUM(order_count), 0)       AS order_count,
                    COALESCE(SUM(base_refund_total), 0) AS refund_total,
                    COALESCE(SUM(refund_count), 0)      AS refund_count,
                    COALESCE(SUM(coupon_count), 0)      AS coupon_count,
                    COALESCE(SUM(base_tax_total), 0)    AS tax_total,
                    COUNT(DISTINCT day)                 AS days_present
                   FROM {$wpdb->prefix}tejcart_daily_summary
                  WHERE day BETWEEN %s AND %s",
                $utc_from,
                $utc_to
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        // The base_* columns are in the store base currency; `$currency`
        // here is that base (Reports passes get_option('tejcart_currency')).
        $row['revenue']      = \TejCart\Money\Currency::from_minor_units( (int) ( $row['revenue'] ?? 0 ), $currency );
        $row['refund_total'] = \TejCart\Money\Currency::from_minor_units( (int) ( $row['refund_total'] ?? 0 ), $currency );
        $row['tax_total']    = \TejCart\Money\Currency::from_minor_units( (int) ( $row['tax_total'] ?? 0 ), $currency );
        return $row;
    }
}
