<?php
/**
 * Reports date-range helper.
 *
 * Single source of truth for converting a site-local `Y-m-d` picker range
 * into a half-open UTC datetime window for binding against
 * `tejcart_orders.created_at` / `tejcart_order_refunds.created_at`, and
 * for emitting the matching UTC `Y-m-d` strings used as keys into the
 * `tejcart_daily_summary` / `tejcart_product_daily` rollups.
 *
 * Replaces the two near-duplicate `date_range_to_utc_bounds()` copies
 * that used to live in `Admin\Reports` and `Reports\CLI_Exporter` —
 * F-010 in audits/AUDIT_reports_2026-05-28.md.
 *
 * Convention (F-006): the public read API of `Daily_Summary` and the
 * admin/CLI exporters take site-local `Y-m-d` strings (matching what
 * the admin date-picker emits via `wp_date('Y-m-d')`). All UTC
 * conversion happens inside this helper. The rollup write side keys
 * on UTC days; this helper makes the read side agree.
 *
 * @package TejCart\Reports
 */

declare( strict_types=1 );

namespace TejCart\Reports;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Date_Range {

    /**
     * Convert a `Y-m-d` site-local range into half-open UTC datetime
     * bounds suitable for `created_at >= %s AND created_at < %s`.
     *
     * Returns `[utc_start, utc_end_exclusive]`. The end is the start of
     * the day AFTER `$to` in UTC so the predicate is half-open — closed
     * `BETWEEN` either misses rows that land in the final second or
     * double-counts rows at exact midnight on the next day.
     *
     * Falls back to `[from . ' 00:00:00', to . ' 00:00:00' + 1 day]`
     * (treated as UTC) when `wp_timezone()` or the input strings are
     * unparseable, rather than throwing — admin Reports must never
     * fatal because the picker got a malformed value.
     *
     * @param string $from Site-local Y-m-d, inclusive.
     * @param string $to   Site-local Y-m-d, inclusive.
     * @return array{0:string,1:string} `[utc_start, utc_end_exclusive]` as `Y-m-d H:i:s`.
     */
    public static function to_utc_datetime_bounds( string $from, string $to ): array {
        $utc     = new \DateTimeZone( 'UTC' );
        $site_tz = self::site_timezone( $utc );

        try {
            $start = new \DateTimeImmutable( $from . ' 00:00:00', $site_tz );
            // Half-open: end is the start of (to + 1 day) in site-tz,
            // then converted to UTC. A site-tz day ending at 23:59:59
            // covers a 24-hour wall-clock span; using start-of-next-day
            // as the exclusive bound is the textbook idiom.
            $end_inclusive_site = new \DateTimeImmutable( $to . ' 00:00:00', $site_tz );
            $end_exclusive_site = $end_inclusive_site->modify( '+1 day' );
        } catch ( \Exception $e ) {
            return array( $from . ' 00:00:00', $to . ' 00:00:00' );
        }

        return array(
            $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
            $end_exclusive_site->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
        );
    }

    /**
     * Map a `Y-m-d` site-local range to the inclusive UTC `Y-m-d`
     * strings that bound the rollup `day` column.
     *
     * Because the writer keys `tejcart_daily_summary.day` on the UTC
     * day a given order's `created_at` falls in (see
     * `Daily_Summary::recompute_for_order()` which does
     * `SELECT DATE(created_at)` on the UTC-stored column), the read
     * window must be the **union** of UTC days that overlap the
     * site-local picker range. For a non-UTC site_tz this is one day
     * wider than the picker range on the trailing edge — the
     * `BETWEEN` then captures the bucket that holds the orders the
     * caller actually wants.
     *
     * Returns `[utc_from_day, utc_to_day]` both in `Y-m-d`. The caller
     * passes these straight into `day BETWEEN %s AND %s` against the
     * rollup tables.
     *
     * @param string $from Site-local Y-m-d, inclusive.
     * @param string $to   Site-local Y-m-d, inclusive.
     * @return array{0:string,1:string}
     */
    public static function to_utc_day_bounds( string $from, string $to ): array {
        [ $utc_start, $utc_end_exclusive ] = self::to_utc_datetime_bounds( $from, $to );

        $start_ts = strtotime( $utc_start . ' UTC' );
        $end_ts   = strtotime( $utc_end_exclusive . ' UTC' );

        if ( ! is_int( $start_ts ) || ! is_int( $end_ts ) ) {
            return array( $from, $to );
        }

        // $end_ts is exclusive (start-of-next-day UTC). The inclusive
        // last bucket day is the UTC day of `$end_ts - 1 second`.
        return array(
            gmdate( 'Y-m-d', $start_ts ),
            gmdate( 'Y-m-d', $end_ts - 1 ),
        );
    }

    /**
     * Inclusive day count covered by `[from, to]` in site-local time.
     *
     * Used by the rollup "is this range fully covered by precomputed
     * buckets?" gate. Day count is on the picker's terms — converting
     * `from`/`to` through `strtotime()` in PHP's local tz already gives
     * the right delta because they share the same tz; this helper just
     * centralises the +1 inclusive bump.
     */
    public static function expected_day_count( string $from, string $to ): int {
        $from_ts = strtotime( $from );
        $to_ts   = strtotime( $to );
        if ( ! is_int( $from_ts ) || ! is_int( $to_ts ) || $to_ts < $from_ts ) {
            return 0;
        }
        return (int) ( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1;
    }

    private static function site_timezone( \DateTimeZone $fallback ): \DateTimeZone {
        if ( ! function_exists( 'wp_timezone' ) ) {
            return $fallback;
        }
        try {
            $tz = wp_timezone();
            if ( $tz instanceof \DateTimeZone ) {
                return $tz;
            }
        } catch ( \Throwable $e ) {
            // Fall through.
        }
        return $fallback;
    }
}
