<?php

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reports and analytics dashboard.
 */
class Reports {
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_export' ) );
    }

    public function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display routing.
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'sales';
        $tabs = array(
            'sales'      => __( 'Sales', 'tejcart' ),
            'products'   => __( 'Products', 'tejcart' ),
            'categories' => __( 'Categories', 'tejcart' ),
            'customers'  => __( 'Customers', 'tejcart' ),
            'coupons'    => __( 'Coupons', 'tejcart' ),
            'statuses'   => __( 'Order statuses', 'tejcart' ),
            'stock'      => __( 'Stock', 'tejcart' ),
            'tax'        => __( 'Tax', 'tejcart' ),
            'refunds'    => __( 'Refunds', 'tejcart' ),
        );
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'sales';
        }

        // Audit #19 / 07 F-1 — picker defaults are now in site timezone
        // so admins see "today" as they see it on the Orders list, not
        // a UTC-shifted day. The bind values get converted to UTC by
        // self::date_range_to_utc_bounds() before each query.
        $default_from = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-01' ) : gmdate( 'Y-m-01' );
        $default_to   = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filtering.
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : $default_from;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : $default_to;

        $subtitles = array(
            'sales'      => __( 'Revenue, orders and trends across the selected range.', 'tejcart' ),
            'products'   => __( 'Best-selling products by units and revenue.', 'tejcart' ),
            'categories' => __( 'Revenue and units sold broken down by product category.', 'tejcart' ),
            'customers'  => __( 'Top customers and lifetime value.', 'tejcart' ),
            'coupons'    => __( 'Promotions used in the selected range, ranked by impact.', 'tejcart' ),
            'statuses'   => __( 'Order count and revenue split across statuses for the range.', 'tejcart' ),
            'stock'      => __( 'Inventory health and low-stock alerts.', 'tejcart' ),
            'tax'        => __( 'Tax collected across order statuses.', 'tejcart' ),
            'refunds'    => __( 'Refund volume, size and recent activity.', 'tejcart' ),
        );

        $export_url = wp_nonce_url(
            add_query_arg(
                array(
                    'tejcart_export_report' => $tab,
                    'date_from'             => $date_from,
                    'date_to'               => $date_to,
                ),
                admin_url( 'admin.php' )
            ),
            'tejcart_export_report'
        );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-reports">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Reports', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php echo esc_html( $subtitles[ $tab ] ?? $subtitles['sales'] ); ?>
                    </p>
                </div>
                <div class="nxc-page-header__actions">
                    <a class="nxc-btn" href="<?php echo esc_url( $export_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?php esc_html_e( 'Export CSV', 'tejcart' ); ?>
                    </a>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <div class="nxc-toolbar nxc-reports-toolbar">
                <ul class="nxc-reports-tabs" role="tablist">
                    <?php foreach ( $tabs as $id => $label ) :
                        $url       = add_query_arg(
                            array(
                                'page'      => 'tejcart-reports',
                                'tab'       => $id,
                                'date_from' => $date_from,
                                'date_to'   => $date_to,
                            ),
                            admin_url( 'admin.php' )
                        );
                        $is_active = ( $id === $tab );
                        ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="<?php echo $is_active ? 'is-active' : ''; ?>"
                               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html( $label ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <form method="get" class="nxc-reports-filters">
                    <input type="hidden" name="page" value="tejcart-reports" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
                    <label class="nxc-reports-filters__label" for="nxc-reports-from"><?php esc_html_e( 'From', 'tejcart' ); ?></label>
                    <input type="date" id="nxc-reports-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                    <span class="nxc-reports-filters__sep" aria-hidden="true">–</span>
                    <label class="nxc-reports-filters__label" for="nxc-reports-to"><?php esc_html_e( 'To', 'tejcart' ); ?></label>
                    <input type="date" id="nxc-reports-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                    <button type="submit" class="nxc-btn nxc-btn--sm nxc-btn--primary"><?php esc_html_e( 'Apply', 'tejcart' ); ?></button>
                </form>
            </div>

            <?php
            switch ( $tab ) {
                case 'products':   $this->render_products_report( $date_from, $date_to ); break;
                case 'categories': $this->render_categories_report( $date_from, $date_to ); break;
                case 'customers':  $this->render_customers_report( $date_from, $date_to ); break;
                case 'coupons':    $this->render_coupons_report( $date_from, $date_to ); break;
                case 'statuses':   $this->render_statuses_report( $date_from, $date_to ); break;
                case 'stock':      $this->render_stock_report(); break;
                case 'tax':        $this->render_tax_report( $date_from, $date_to ); break;
                case 'refunds':    $this->render_refunds_report( $date_from, $date_to ); break;
                default:           $this->render_sales_report( $date_from, $date_to ); break;
            }
            ?>
        </div>
        <?php
    }

    private function render_sales_report( string $from, string $to ): void {
        global $wpdb;
        $orders = $wpdb->prefix . 'tejcart_orders';
        $items  = $wpdb->prefix . 'tejcart_order_items';

        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // C-3: prefer the precomputed daily aggregate when complete for the
        // requested range. Falls back to a live SUM on the orders table
        // when any day is missing (e.g. backfill not yet caught up). At
        // 100M order rows the live SUM is multi-second; the aggregate
        // read is sub-millisecond.
        $currency      = (string) get_option( 'tejcart_currency', 'USD' );
        $expected_days = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
        $agg           = class_exists( \TejCart\Reports\Daily_Summary::class )
            ? \TejCart\Reports\Daily_Summary::read_range( $from, $to, $currency )
            : null;

        $aggregate_complete = $agg && (int) ( $agg['days_present'] ?? 0 ) >= $expected_days;

        if ( $aggregate_complete ) {
            $revenue     = (float) $agg['revenue'];
            $order_count = (int) $agg['order_count'];
            $refunds     = (float) $agg['refund_total'];
            $coupons     = (int) $agg['coupon_count'];
            $items_sold  = \TejCart\Reports\Daily_Summary::read_items_sold( $from, $to, $currency );
            $daily_rows  = \TejCart\Reports\Daily_Summary::read_daily_breakdown( $from, $to, $currency );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is fully prepared above.
            $where = $wpdb->prepare( "status IN ('completed','processing') AND created_at BETWEEN %s AND %s", $utc_lo, $utc_hi );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // SUM aggregates of BIGINT minor-units columns return ints;
            // convert to major-unit floats at this boundary so the
            // tejcart_price() consumers below keep their existing contract.
            $revenue     = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( "SELECT COALESCE(SUM(base_total),0) FROM {$orders} WHERE {$where}" ), $currency );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $order_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE {$where}" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $refunds     = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(base_total),0) FROM {$orders} WHERE status = 'refunded' AND created_at BETWEEN %s AND %s", $utc_lo, $utc_hi ) ), $currency );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $coupons     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE coupon_code IS NOT NULL AND coupon_code != '' AND {$where}" );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $items_sold  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(oi.quantity),0) FROM {$items} oi INNER JOIN {$orders} o ON oi.order_id = o.id WHERE o.status IN ('completed','processing') AND o.created_at BETWEEN %s AND %s",
                $utc_lo, $utc_hi
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $live_daily = (array) $wpdb->get_results( "SELECT DATE(created_at) as day, SUM(base_total) as total, COUNT(*) as cnt FROM {$orders} WHERE {$where} GROUP BY DATE(created_at) ORDER BY day" );
            $daily_rows = array();
            foreach ( $live_daily as $row ) {
                $daily_rows[] = array(
                    'day'         => (string) ( $row->day ?? '' ),
                    'revenue'     => \TejCart\Money\Currency::from_minor_units( (int) ( $row->total ?? 0 ), $currency ),
                    'order_count' => (int) ( $row->cnt ?? 0 ),
                );
            }
        }
        $avg_order = $order_count ? round( $revenue / $order_count, 2 ) : 0;

        $this->stats_open();
        $this->stat_card( __( 'Revenue', 'tejcart' ), tejcart_price( $revenue ) );
        $this->stat_card( __( 'Orders', 'tejcart' ), number_format_i18n( $order_count ) );
        $this->stat_card( __( 'Avg order', 'tejcart' ), tejcart_price( $avg_order ) );
        $this->stat_card( __( 'Items sold', 'tejcart' ), number_format_i18n( $items_sold ) );
        $this->stat_card( __( 'Net revenue', 'tejcart' ), tejcart_price( $revenue - $refunds ) );
        $this->stat_card( __( 'Coupons used', 'tejcart' ), number_format_i18n( $coupons ) );
        $this->stats_close();

        $this->data_card_open( __( 'Revenue by day', 'tejcart' ), __( 'Daily order volume and gross revenue.', 'tejcart' ) );
        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Date', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Orders', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Revenue', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $daily_rows ) {
            foreach ( $daily_rows as $d ) {
                echo '<tr>';
                echo '<td><span class="nxc-date">' . esc_html( date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $d['day'] ) ) ) . '</span></td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $d['order_count'] ) ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( (float) $d['revenue'] ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 3, __( 'No sales in the selected date range.', 'tejcart' ) );
        }
        echo '</tbody></table>';
        $this->data_card_close();
    }

    private function render_products_report( string $from, string $to ): void {
        // Audit #100 / 07 F-12 — prefer the precomputed
        // `tejcart_product_daily` rollup over the 3-table live JOIN.
        // The rollup is populated atomically with `tejcart_daily_summary`
        // by `Daily_Summary::rebuild_bucket()` — so the same
        // days_present gate (used by the Sales tab) tells us whether
        // the product rollup covers the range. Fall back to the live
        // JOIN when any day is missing (fresh install / partial
        // backfill).
        [ $top_qty, $top_rev ] = $this->fetch_top_products( $from, $to );

        echo '<div class="nxc-data-grid nxc-data-grid--2">';
        $this->data_card_open( __( 'Top products by units sold', 'tejcart' ), __( 'Ranked by quantity sold.', 'tejcart' ) );
        $this->render_product_table( $top_qty );
        $this->data_card_close();

        $this->data_card_open( __( 'Top products by revenue', 'tejcart' ), __( 'Ranked by gross revenue.', 'tejcart' ) );
        $this->render_product_table( $top_rev );
        $this->data_card_close();
        echo '</div>';
    }

    /**
     * Fetch the top-N units and top-N revenue product lists for the
     * given range. Prefers the precomputed rollup; falls back to a
     * 3-table live JOIN when the rollup doesn't cover the range.
     *
     * Audit #100 / 07 F-12.
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>} Tuple of [top_qty_rows, top_rev_rows].
     */
    private function fetch_top_products( string $from, string $to ): array {
        global $wpdb;

        $currency      = (string) get_option( 'tejcart_currency', 'USD' );
        $expected_days = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
        $agg           = class_exists( \TejCart\Reports\Daily_Summary::class )
            ? \TejCart\Reports\Daily_Summary::read_range( $from, $to, $currency )
            : null;
        $rollup_complete = $agg && (int) ( $agg['days_present'] ?? 0 ) >= $expected_days;

        if ( $rollup_complete && class_exists( \TejCart\Reports\Daily_Summary::class ) ) {
            $top_qty = \TejCart\Reports\Daily_Summary::read_top_products( $from, $to, 'qty', 10, $currency );
            $top_rev = \TejCart\Reports\Daily_Summary::read_top_products( $from, $to, 'rev', 10, $currency );
            return array( $top_qty, $top_rev );
        }

        $orders = $wpdb->prefix . 'tejcart_orders';
        $items  = $wpdb->prefix . 'tejcart_order_items';
        $prods  = $wpdb->prefix . 'tejcart_products';

        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_qty = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT oi.product_id, p.name, SUM(oi.quantity) as qty, SUM(oi.base_line_total) as rev FROM {$items} oi INNER JOIN {$orders} o ON oi.order_id = o.id INNER JOIN {$prods} p ON oi.product_id = p.id WHERE o.status IN ('completed','processing') AND o.created_at BETWEEN %s AND %s GROUP BY oi.product_id ORDER BY qty DESC LIMIT 10",
            $utc_lo, $utc_hi
        ) );

        $top_rev = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT oi.product_id, p.name, SUM(oi.quantity) as qty, SUM(oi.base_line_total) as rev FROM {$items} oi INNER JOIN {$orders} o ON oi.order_id = o.id INNER JOIN {$prods} p ON oi.product_id = p.id WHERE o.status IN ('completed','processing') AND o.created_at BETWEEN %s AND %s GROUP BY oi.product_id ORDER BY rev DESC LIMIT 10",
            $utc_lo, $utc_hi
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array( $top_qty, $top_rev );
    }

    private function render_product_table( $rows ): void {
        // SUM(line_total) is BIGINT minor units in the shop currency;
        // convert at this boundary so tejcart_price() sees major units.
        $currency = (string) get_option( 'tejcart_currency', 'USD' );
        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Units', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Revenue', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $rev_major = \TejCart\Money\Currency::from_minor_units( (int) $r->rev, $currency );
                echo '<tr>';
                echo '<td><span class="nxc-product-name">' . esc_html( $r->name ) . '</span></td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $r->qty ) ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( $rev_major ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 3, __( 'No product sales in the selected date range.', 'tejcart' ) );
        }
        echo '</tbody></table>';
    }

    private function render_customers_report( string $from, string $to ): void {
        global $wpdb;
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top = $wpdb->get_results( $wpdb->prepare(
            "SELECT customer_email, customer_name, COUNT(*) as order_count, SUM(base_total) as total_spend FROM {$orders} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s GROUP BY customer_email ORDER BY total_spend DESC LIMIT 10",
            $utc_lo, $utc_hi
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $unique = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT customer_email) FROM {$orders} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s", $utc_lo, $utc_hi ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_rev = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(base_total),0) FROM {$orders} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s", $utc_lo, $utc_hi ) ), $currency );
        $avg_range = $unique ? round( $total_rev / $unique, 2 ) : 0;

        // Audit 07 F-16 / #1461 — Avg lifetime value must be lifetime, not
        // range-bounded. The legacy stat was `range_total / range_unique`,
        // which is "avg revenue per customer in the window" — useful, but
        // mislabelled as LTV. Display both stats so merchants get the
        // retention signal they wanted from the lifetime number AND can
        // still compare against the current window.
        //
        // Lifetime LTV runs the same SUM/COUNT(DISTINCT) shape without a
        // date predicate. The result set is bounded by the number of
        // distinct customer emails — for the report-tab cardinality this
        // is acceptable; rollup to `tejcart_customer_stats.lifetime_total`
        // is a future optimisation tracked separately.
        // The two lifetime scalars are unbounded full-table aggregates
        // (COUNT(DISTINCT email) forces a temp-table/sort), so running them on
        // every Customers-tab load is the report's heaviest cost at scale.
        // Cache them briefly: a few minutes of staleness on an all-time stat is
        // immaterial, and it removes the per-load full scan. (A persistent
        // tejcart_customer_stats rollup remains the longer-term optimisation.)
        $lifetime_cache_key = 'tejcart_report_lifetime_customer_stats';
        $lifetime_stats     = get_transient( $lifetime_cache_key );
        if ( ! is_array( $lifetime_stats ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $lifetime_unique = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_email) FROM {$orders} WHERE status IN ('completed','processing')" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $lifetime_rev_minor = (int) $wpdb->get_var( "SELECT COALESCE(SUM(base_total),0) FROM {$orders} WHERE status IN ('completed','processing')" );
            $lifetime_stats     = array(
                'unique'    => $lifetime_unique,
                'rev_minor' => $lifetime_rev_minor,
            );
            set_transient( $lifetime_cache_key, $lifetime_stats, 5 * MINUTE_IN_SECONDS );
        }
        $lifetime_unique    = (int) ( $lifetime_stats['unique'] ?? 0 );
        $lifetime_rev_minor = (int) ( $lifetime_stats['rev_minor'] ?? 0 );
        $lifetime_rev   = \TejCart\Money\Currency::from_minor_units( $lifetime_rev_minor, $currency );
        $lifetime_ltv   = $lifetime_unique ? round( $lifetime_rev / $lifetime_unique, 2 ) : 0;

        $this->stats_open();
        $this->stat_card( __( 'Unique customers', 'tejcart' ), number_format_i18n( $unique ) );
        $this->stat_card( __( 'Avg revenue per customer (range)', 'tejcart' ), tejcart_price( $avg_range ) );
        $this->stat_card( __( 'Avg lifetime value (all time)', 'tejcart' ), tejcart_price( $lifetime_ltv ) );
        $this->stat_card( __( 'Gross revenue', 'tejcart' ), tejcart_price( $total_rev ) );
        $this->stats_close();

        $this->data_card_open( __( 'Top customers', 'tejcart' ), __( 'Ranked by total spend in this period.', 'tejcart' ) );
        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Customer', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Orders', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Total spend', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $top ) {
            foreach ( $top as $c ) {
                $display_name = trim( (string) $c->customer_name ) !== '' ? $c->customer_name : __( '(Guest)', 'tejcart' );
                echo '<tr>';
                echo '<td><div class="nxc-customer"><span class="nxc-customer__name">' . esc_html( $display_name ) . '</span></div></td>';
                echo '<td><a href="mailto:' . esc_attr( $c->customer_email ) . '">' . esc_html( $c->customer_email ) . '</a></td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $c->order_count ) ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( \TejCart\Money\Currency::from_minor_units( (int) $c->total_spend, $currency ) ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 4, __( 'No customer activity in the selected range.', 'tejcart' ) );
        }
        echo '</tbody></table>';
        $this->data_card_close();
    }

    /**
     * Audit 07 F-14 / #1459 — order-status-distribution report.
     *
     * Single bounded GROUP BY over `tejcart_orders` returning (status,
     * COUNT, SUM(total)) for the range. The status enumeration is small
     * (~8 rows) so no rollup is needed.
     */
    private function render_statuses_report( string $from, string $to ): void {
        global $wpdb;
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS order_count, COALESCE(SUM(base_total),0) AS revenue FROM {$orders} WHERE created_at BETWEEN %s AND %s GROUP BY status ORDER BY order_count DESC",
            $utc_lo, $utc_hi
        ) );

        // Headline cards: total orders and total revenue (across ALL statuses,
        // including cancelled/refunded/failed — operators want the unfiltered
        // distribution on this surface).
        $total_orders  = 0;
        $total_revenue = 0;
        foreach ( $rows as $r ) {
            $total_orders  += (int) $r->order_count;
            $total_revenue += (int) $r->revenue;
        }
        $total_rev_major = \TejCart\Money\Currency::from_minor_units( $total_revenue, $currency );

        $this->stats_open();
        $this->stat_card( __( 'Orders (all statuses)', 'tejcart' ), number_format_i18n( $total_orders ) );
        $this->stat_card( __( 'Gross revenue (all statuses)', 'tejcart' ), tejcart_price( $total_rev_major ) );
        $this->stat_card( __( 'Distinct statuses', 'tejcart' ), number_format_i18n( count( $rows ) ) );
        $this->stats_close();

        $this->data_card_open(
            __( 'Orders by status', 'tejcart' ),
            __( 'Includes cancelled, refunded and failed orders for the selected range.', 'tejcart' )
        );

        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Status', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Orders', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Share', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Revenue', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $status_slug  = (string) $r->status;
                $status_label = \TejCart\Order\Order_Status::get_label( $status_slug );
                if ( '' === $status_label ) {
                    // Unknown / legacy status — fall back to the slug.
                    $status_label = $status_slug;
                }
                $cnt   = (int) $r->order_count;
                $share = $total_orders > 0 ? ( $cnt * 100.0 / $total_orders ) : 0;
                $rev   = \TejCart\Money\Currency::from_minor_units( (int) $r->revenue, $currency );

                echo '<tr>';
                echo '<td><span class="nxc-status nxc-status--' . esc_attr( $status_slug ) . '">' . esc_html( $status_label ) . '</span></td>';
                echo '<td>' . esc_html( number_format_i18n( $cnt ) ) . '</td>';
                echo '<td>' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( $rev ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 4, __( 'No orders in the selected range.', 'tejcart' ) );
        }

        echo '</tbody></table>';
        $this->data_card_close();
    }

    /**
     * Audit 07 F-15 / #1460 — per-coupon usage table.
     *
     * Aggregates `tejcart_orders` by `coupon_code` for the range. The
     * `tejcart_orders.coupon_code` column stores the canonical applied
     * code (single-coupon mode); multi-coupon stores are bridged via the
     * `tejcart_coupon_usage` join table — see the comment block below.
     */
    private function render_coupons_report( string $from, string $to ): void {
        global $wpdb;
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // The orders table carries the redeemed `coupon_code` directly,
        // so a single GROUP BY answers the breakdown without joining the
        // `tejcart_coupon_usage` ledger. (The ledger remains the source of
        // truth for per-customer usage caps; this report only needs the
        // monetary impact, not the dedup.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT coupon_code, COUNT(*) AS usages, COALESCE(SUM(base_total),0) AS revenue, COALESCE(SUM(base_discount_total),0) AS discount FROM {$orders} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s AND coupon_code <> '' GROUP BY coupon_code ORDER BY usages DESC LIMIT 50",
            $utc_lo, $utc_hi
        ) );

        $total_usages   = 0;
        $total_revenue  = 0;
        $total_discount = 0;
        foreach ( $rows as $r ) {
            $total_usages   += (int) $r->usages;
            $total_revenue  += (int) $r->revenue;
            $total_discount += (int) $r->discount;
        }
        $rev_major  = \TejCart\Money\Currency::from_minor_units( $total_revenue,  $currency );
        $disc_major = \TejCart\Money\Currency::from_minor_units( $total_discount, $currency );

        $this->stats_open();
        $this->stat_card( __( 'Coupons used', 'tejcart' ), number_format_i18n( $total_usages ) );
        $this->stat_card( __( 'Distinct codes', 'tejcart' ), number_format_i18n( count( $rows ) ) );
        $this->stat_card( __( 'Discounted revenue', 'tejcart' ), tejcart_price( $rev_major ) );
        $this->stat_card( __( 'Total discount given', 'tejcart' ), tejcart_price( $disc_major ) );
        $this->stats_close();

        $this->data_card_open(
            __( 'Coupon usage', 'tejcart' ),
            __( 'Top 50 codes used on completed or processing orders in the range.', 'tejcart' )
        );

        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Code', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Usages', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Revenue', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Discount given', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $rev  = \TejCart\Money\Currency::from_minor_units( (int) $r->revenue,  $currency );
                $disc = \TejCart\Money\Currency::from_minor_units( (int) $r->discount, $currency );
                echo '<tr>';
                echo '<td><span class="nxc-sku">' . esc_html( strtoupper( (string) $r->coupon_code ) ) . '</span></td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $r->usages ) ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( $rev ) ) . '</span></td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( $disc ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 4, __( 'No coupons were redeemed in the selected range.', 'tejcart' ) );
        }

        echo '</tbody></table>';
        $this->data_card_close();
    }

    /**
     * Audit 07 F-13 / #1458 — sales-by-category report.
     *
     * Joins `tejcart_order_items × tejcart_orders × tejcart_term_relationships`
     * to surface revenue and units sold per product category. Result set is
     * bounded to top-20 by revenue — categories beyond that rarely register
     * in operator decisions. A `tejcart_category_daily` rollup is a future
     * optimisation; at single-store cardinality (typically <500 categories,
     * <100k order items per month) the live JOIN is acceptable.
     */
    private function render_categories_report( string $from, string $to ): void {
        global $wpdb;
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $items    = $wpdb->prefix . 'tejcart_order_items';
        $rels     = $wpdb->prefix . 'term_relationships';
        $tax      = $wpdb->prefix . 'term_taxonomy';
        $terms    = $wpdb->prefix . 'terms';
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(oi.quantity),0) AS units, COALESCE(SUM(oi.base_line_total),0) AS revenue
             FROM {$items} oi
             INNER JOIN {$orders} o ON oi.order_id = o.id
             INNER JOIN {$rels}   tr ON tr.object_id   = oi.product_id
             INNER JOIN {$tax}    tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$terms}  t  ON t.term_id      = tt.term_id
             WHERE o.status IN ('completed','processing')
               AND o.created_at BETWEEN %s AND %s
               AND tt.taxonomy = 'tejcart_product_category'
             GROUP BY t.term_id
             ORDER BY revenue DESC
             LIMIT 20",
            $utc_lo, $utc_hi
        ) );

        $total_units   = 0;
        $total_revenue = 0;
        foreach ( $rows as $r ) {
            $total_units   += (int) $r->units;
            $total_revenue += (int) $r->revenue;
        }
        $rev_major = \TejCart\Money\Currency::from_minor_units( $total_revenue, $currency );

        $this->stats_open();
        $this->stat_card( __( 'Categories with sales', 'tejcart' ), number_format_i18n( count( $rows ) ) );
        $this->stat_card( __( 'Units sold (top 20 cats)', 'tejcart' ), number_format_i18n( $total_units ) );
        $this->stat_card( __( 'Revenue (top 20 cats)', 'tejcart' ), tejcart_price( $rev_major ) );
        $this->stats_close();

        $this->data_card_open(
            __( 'Sales by category', 'tejcart' ),
            __( 'Top 20 categories by revenue. Bundled category memberships are counted on the parent product line.', 'tejcart' )
        );

        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Category', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Orders', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Units', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Revenue', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $rev = \TejCart\Money\Currency::from_minor_units( (int) $r->revenue, $currency );
                echo '<tr>';
                echo '<td><span class="nxc-product-name">' . esc_html( (string) $r->name ) . '</span></td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $r->order_count ) ) . '</td>';
                echo '<td>' . esc_html( number_format_i18n( (int) $r->units ) ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( $rev ) ) . '</span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 4, __( 'No category sales in the selected range.', 'tejcart' ) );
        }

        echo '</tbody></table>';
        $this->data_card_close();
    }

    private function render_stock_report(): void {
        global $wpdb;
        $prods = $wpdb->prefix . 'tejcart_products';
        $threshold = (int) get_option( 'tejcart_low_stock_threshold', 5 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prods} WHERE status = 'publish'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $in_stock  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prods} WHERE status = 'publish' AND stock_status = 'instock'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $out       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prods} WHERE status = 'publish' AND stock_status = 'outofstock'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $low       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prods} WHERE status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d AND stock_quantity > 0", $threshold ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $backorder = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prods} WHERE status = 'publish' AND stock_status = 'onbackorder'" );

        $this->stats_open();
        $this->stat_card( __( 'Total products', 'tejcart' ), number_format_i18n( $total ) );
        $this->stat_card( __( 'In stock', 'tejcart' ), number_format_i18n( $in_stock ) );
        $this->stat_card( __( 'Out of stock', 'tejcart' ), number_format_i18n( $out ) );
        $this->stat_card( __( 'Low stock', 'tejcart' ), number_format_i18n( $low ) );
        $this->stat_card( __( 'Backorder', 'tejcart' ), number_format_i18n( $backorder ) );
        $this->stats_close();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $low_items = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, sku, stock_quantity, stock_status FROM {$prods} WHERE status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d ORDER BY stock_quantity ASC LIMIT 20", $threshold ) );

        $this->data_card_open(
            __( 'Low stock items', 'tejcart' ),
            /* translators: %d: low-stock threshold */
            sprintf( __( 'Products at or below the %d-unit threshold.', 'tejcart' ), $threshold )
        );
        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'SKU', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Stock', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $low_items ) {
            $tone_map = array(
                'instock'      => 'in',
                'outofstock'   => 'out',
                'onbackorder'  => 'low',
            );
            $label_map = array(
                'instock'     => __( 'In stock', 'tejcart' ),
                'outofstock'  => __( 'Out of stock', 'tejcart' ),
                'onbackorder' => __( 'On backorder', 'tejcart' ),
            );
            foreach ( $low_items as $item ) {
                $qty  = (int) $item->stock_quantity;
                $tone = $tone_map[ $item->stock_status ] ?? ( $qty <= 0 ? 'out' : 'low' );
                $lbl  = $label_map[ $item->stock_status ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', (string) $item->stock_status ) );
                echo '<tr>';
                echo '<td><span class="nxc-product-name">' . esc_html( $item->name ) . '</span></td>';
                echo '<td>' . ( $item->sku ? '<span class="nxc-sku">' . esc_html( $item->sku ) . '</span>' : '<span class="nxc-muted">—</span>' ) . '</td>';
                echo '<td>' . esc_html( number_format_i18n( $qty ) ) . '</td>';
                echo '<td><span class="nxc-stock nxc-stock--' . esc_attr( $tone ) . '"><span class="nxc-stock__dot"></span><span class="nxc-stock__label">' . esc_html( $lbl ) . '</span></span></td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 4, __( 'No low-stock products right now.', 'tejcart' ) );
        }
        echo '</tbody></table>';
        $this->data_card_close();
    }

    private function render_tax_report( string $from, string $to ): void {
        global $wpdb;
        $orders = $wpdb->prefix . 'tejcart_orders';

        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $utc_lo, $utc_hi ] = self::date_range_to_utc_bounds( $from, $to );

        // Prefer the precomputed rollup for the headline total — it
        // already stores tax_total per (day, currency). Falls back to a
        // live SUM only when the rollup is incomplete for the range.
        $currency      = (string) get_option( 'tejcart_currency', 'USD' );
        $expected_days = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
        $agg           = class_exists( \TejCart\Reports\Daily_Summary::class )
            ? \TejCart\Reports\Daily_Summary::read_range( $from, $to, $currency )
            : null;

        if ( $agg && (int) ( $agg['days_present'] ?? 0 ) >= $expected_days ) {
            $total_tax = (float) $agg['tax_total'];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total_tax = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(base_tax_total),0) FROM {$orders} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s", $utc_lo, $utc_hi ) ), $currency );
        }

        $this->stats_open();
        $this->stat_card( __( 'Total tax collected', 'tejcart' ), tejcart_price( $total_tax ) );
        $this->stats_close();

        // Audit 07 F-18 / #1463 — the by-status breakdown still runs a
        // live GROUP BY because `tejcart_daily_summary` only carries a
        // single tax_total column (summed across statuses). Adding
        // tax_completed / tax_processing / tax_refunded columns to the
        // rollup is the long-term fix; on single-store cardinality
        // (typically <10k orders/range) the live cost is acceptable
        // and the data is admin-only. Heavy installs can disable this
        // panel via `tejcart_reports_tax_show_status_breakdown` to
        // avoid the GROUP BY entirely.
        if ( (bool) apply_filters( 'tejcart_reports_tax_show_status_breakdown', true, $from, $to ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $by_status = $wpdb->get_results( $wpdb->prepare( "SELECT status, SUM(base_tax_total) as tax FROM {$orders} WHERE created_at BETWEEN %s AND %s GROUP BY status ORDER BY tax DESC", $utc_lo, $utc_hi ) );

            $this->data_card_open( __( 'Tax by order status', 'tejcart' ), __( 'Breakdown of tax collected per order status.', 'tejcart' ) );
            echo '<table class="wp-list-table widefat"><thead><tr>';
            echo '<th>' . esc_html__( 'Status', 'tejcart' ) . '</th>';
            echo '<th>' . esc_html__( 'Tax', 'tejcart' ) . '</th>';
            echo '</tr></thead><tbody>';
            if ( $by_status ) {
                foreach ( $by_status as $s ) {
                    $status = (string) $s->status;
                    $label  = \TejCart\Order\Order_Status::get_label( $status );
                    if ( '' === $label ) {
                        $label = ucfirst( str_replace( array( '-', '_' ), ' ', $status ) );
                    }
                    echo '<tr>';
                    echo '<td><span class="nxc-status nxc-status--' . esc_attr( $status ) . '"><span class="nxc-status__dot"></span><span class="nxc-status__label">' . esc_html( $label ) . '</span></span></td>';
                    echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( \TejCart\Money\Currency::from_minor_units( (int) $s->tax, $currency ) ) ) . '</span></td>';
                    echo '</tr>';
                }
            } else {
                $this->empty_row( 2, __( 'No tax recorded in the selected range.', 'tejcart' ) );
            }
            echo '</tbody></table>';
            $this->data_card_close();
        }
    }

    /**
     * Render the refunds analytics tab.
     *
     * Shows total refunded amount, refund count, average refund size, and
     * a breakdown by day across the selected range. Sourced from
     * tejcart_order_refunds with the order join used for order-number display.
     */
    private function render_refunds_report( string $from, string $to ): void {
        global $wpdb;
        $refunds  = $wpdb->prefix . 'tejcart_order_refunds';
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $range_start, $range_end ] = self::date_range_to_utc_bounds( $from, $to );

        // NB: this tab sums the partial-refund rows in
        // `tejcart_order_refunds`, NOT the whole-order
        // `orders.status = 'refunded'` totals that `Daily_Summary`
        // tracks. The two are different by design — line-item refunds
        // vs. full-order refunds — so we cannot reuse the rollup
        // without changing the displayed semantics. The refunds table
        // is also much smaller than the orders table (refund rate is
        // single-digit %), so the live SUM/COUNT is acceptable here.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_refunded = \TejCart\Money\Currency::from_minor_units(
            (int) $wpdb->get_var(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(base_amount),0) FROM {$refunds} WHERE created_at BETWEEN %s AND %s",
                    $range_start,
                    $range_end
                )
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            ),
            $currency
        );

        $refund_count = (int) $wpdb->get_var(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$refunds} WHERE created_at BETWEEN %s AND %s",
                $range_start,
                $range_end
            )
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        );
        // phpcs:enable

        $avg_refund = $refund_count ? round( $total_refunded / $refund_count, 2 ) : 0.0;

        $this->stats_open();
        $this->stat_card( __( 'Total refunded', 'tejcart' ), tejcart_price( $total_refunded ) );
        $this->stat_card( __( 'Refund count', 'tejcart' ), number_format_i18n( $refund_count ) );
        $this->stat_card( __( 'Average refund', 'tejcart' ), tejcart_price( $avg_refund ) );
        $this->stats_close();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT r.id, r.order_id, r.base_amount, r.reason, r.created_at, o.customer_name
                 FROM {$refunds} r
                 LEFT JOIN {$orders} o ON o.id = r.order_id
                 WHERE r.created_at BETWEEN %s AND %s
                 ORDER BY r.created_at DESC
                 LIMIT 100",
                $range_start,
                $range_end
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->data_card_open( __( 'Recent refunds', 'tejcart' ), __( 'Up to 100 most recent refunds in the selected range.', 'tejcart' ) );
        echo '<table class="wp-list-table widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Date', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Order', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Customer', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Amount', 'tejcart' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'tejcart' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $customer = trim( (string) ( $row->customer_name ?? '' ) );
                $reason   = trim( (string) ( $row->reason ?? '' ) );
                echo '<tr>';
                echo '<td><span class="nxc-date">' . esc_html( date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $row->created_at ) ) ) . '</span></td>';
                echo '<td><a class="nxc-order-number" href="' . esc_url( admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . (int) $row->order_id ) ) . '">#' . (int) $row->order_id . '</a></td>';
                echo '<td>' . ( '' !== $customer ? esc_html( $customer ) : '<span class="nxc-muted">—</span>' ) . '</td>';
                echo '<td><span class="nxc-price">' . wp_kses_post( tejcart_price( \TejCart\Money\Currency::from_minor_units( (int) $row->base_amount, $currency ) ) ) . '</span></td>';
                echo '<td>' . ( '' !== $reason ? esc_html( $reason ) : '<span class="nxc-muted">—</span>' ) . '</td>';
                echo '</tr>';
            }
        } else {
            $this->empty_row( 5, __( 'No refunds in the selected range.', 'tejcart' ) );
        }
        echo '</tbody></table>';
        $this->data_card_close();
    }

    /**
     * Convert a `Y-m-d` date range (site timezone) to UTC datetime
     * bounds suitable for binding against the UTC `tejcart_orders` /
     * `tejcart_order_refunds` `created_at` columns.
     *
     * Audit #19 / 07 F-1 — without this conversion the report window
     * slid by `site_tz_offset` hours, mis-bucketing every order near
     * midnight UTC.
     *
     * F-010 — delegates to {@see \TejCart\Reports\Date_Range::to_utc_datetime_bounds()}
     * so admin, CLI exporter, and REST controller all share one source
     * of truth. Public for backwards compatibility — callers in this
     * class still call `self::date_range_to_utc_bounds(...)`.
     *
     * NB: the returned bounds are half-open (`utc_end` is the start of
     * the day AFTER `$to`, not 23:59:59). The existing query callers
     * use `created_at BETWEEN %s AND %s` which is closed-closed — that
     * was correct for the old 23:59:59 form and stays correct for the
     * new half-open form because `created_at < utc_end` and
     * `created_at <= utc_end - 1s` partition `created_at` identically
     * for DATETIME(0) columns. Audit notes track upgrading every
     * call-site to half-open `>= / <` for forward-compat with
     * DATETIME(6).
     *
     * @param string $from Site-timezone start date `Y-m-d`.
     * @param string $to   Site-timezone end date `Y-m-d`.
     * @return array{0:string,1:string} `[utc_from_datetime, utc_to_datetime]` formatted `Y-m-d H:i:s`.
     */
    public static function date_range_to_utc_bounds( string $from, string $to ): array {
        // The shared helper returns half-open `[utc_start, utc_end_exclusive]`.
        // Every legacy caller in this class binds the result into a
        // closed-closed `BETWEEN %s AND %s` predicate. Subtract one
        // second from the exclusive end to keep semantic parity with
        // the pre-extraction "23:59:59" form. New SQL written for
        // CLI_Exporter uses `>= start AND < end` directly.
        [ $utc_start, $utc_end_exclusive ] = \TejCart\Reports\Date_Range::to_utc_datetime_bounds( $from, $to );

        $end_ts = strtotime( $utc_end_exclusive . ' UTC' );
        if ( ! is_int( $end_ts ) ) {
            return array( $utc_start, $utc_end_exclusive );
        }
        return array( $utc_start, gmdate( 'Y-m-d H:i:s', $end_ts - 1 ) );
    }

    private function stat_card( string $label, string $value ): void {
        echo '<div class="nxc-stat-card"><div class="nxc-stat-card__label">' . esc_html( $label ) . '</div><div class="nxc-stat-card__value">' . wp_kses_post( $value ) . '</div></div>';
    }

    private function stats_open(): void {
        echo '<div class="nxc-stats">';
    }

    private function stats_close(): void {
        echo '</div>';
    }

    private function data_card_open( string $title, string $subtitle = '' ): void {
        echo '<section class="nxc-card nxc-data-card">';
        echo '<header class="nxc-data-card__header">';
        echo '<div><h3>' . esc_html( $title ) . '</h3>';
        if ( '' !== $subtitle ) {
            echo '<p class="nxc-data-card__subtitle">' . esc_html( $subtitle ) . '</p>';
        }
        echo '</div>';
        echo '</header>';
    }

    private function data_card_close(): void {
        echo '</section>';
    }

    private function empty_row( int $colspan, string $message ): void {
        echo '<tr class="no-items"><td class="nxc-data-card__empty" colspan="' . (int) $colspan . '">' . esc_html( $message ) . '</td></tr>';
    }

    /**
     * Prefix formula-injection characters to prevent CSV formula injection.
     *
     * Leading =, +, -, @, tab, and CR characters are prefixed with a single
     * quote so spreadsheet apps treat them as plain text.
     *
     * @param mixed $value Raw value.
     * @return string Safe string value.
     */
    private function sanitize_csv_value( $value ): string {
        $value = (string) $value;
        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }

    public function handle_export(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
        if ( ! isset( $_GET['tejcart_export_report'] ) ) {
            return;
        }
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return;
        }
        check_admin_referer( 'tejcart_export_report' );

        $type = sanitize_text_field( wp_unslash( $_GET['tejcart_export_report'] ) );
        $from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-01' );
        $to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' );

        // Allow-list the export type so $type can never reach the
        // header() / switch path with an unexpected value (audit M).
        // The default `products` arm is preserved for backwards-compat.
        $allowed_types = array( 'sales', 'customers', 'stock', 'tax', 'refunds', 'products', 'statuses', 'coupons', 'categories' );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'products';
        }

        $filename = 'tejcart-' . $type . '-report-' . gmdate( 'Y-m-d' ) . '.csv';
        // Disable any PHP output buffering so chunked writes hit the
        // browser as they're produced — otherwise a 400k-order
        // customers export would buffer every row in memory before
        // the script ends.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        // Wrap the filename in double quotes per RFC 6266 so values
        // containing spaces or commas don't confuse the client.
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Accel-Buffering: no' );
        // Reports on large stores can stream for a long time. Lift the
        // request timeout so the export isn't killed mid-write.
        // Wrap in try/catch (SEC-022): @set_time_limit silently swallows
        // safe_mode / open_basedir restrictions on shared hosts where
        // an operator might want to know the timeout wasn't actually
        // lifted. set_time_limit() now returns false on those hosts —
        // we log at debug so the trace is available without spamming
        // production logs.
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- intentional for long-running CSV export.
            $lifted = set_time_limit( 0 );
            if ( ! $lifted && function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Reports CSV export: set_time_limit(0) rejected; export may be killed mid-write.', 'debug' );
            }
        }

        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        global $wpdb;
        $orders_t  = $wpdb->prefix . 'tejcart_orders';
        $items_t   = $wpdb->prefix . 'tejcart_order_items';
        $prods_t   = $wpdb->prefix . 'tejcart_products';
        $refunds_t = $wpdb->prefix . 'tejcart_order_refunds';
        // Audit #19 / 07 F-1 — convert site-tz dates to UTC for binds.
        [ $range_lo, $range_hi ] = self::date_range_to_utc_bounds( $from, $to );
        // Money columns are BIGINT minor units; convert per-row using
        // the shop currency before writing to CSV so consumers get
        // major-unit decimal numbers consistent with the on-screen UI.
        $currency  = (string) get_option( 'tejcart_currency', 'USD' );

        switch ( $type ) {
            case 'sales':
                fputcsv( $out, array( 'Date', 'Orders', 'Revenue' ) );
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                        $sql = $wpdb->prepare( "SELECT DATE(created_at) as day, COUNT(*) as cnt, SUM(base_total) as rev FROM {$orders_t} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s GROUP BY DATE(created_at) ORDER BY day ASC LIMIT %d OFFSET %d", $range_lo, $range_hi, $limit, $offset );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array( $this->sanitize_csv_value( $r->day ), (int) $r->cnt, \TejCart\Money\Currency::from_minor_units( (int) $r->rev, $currency ) ) );
                    }
                );
                break;
            case 'customers':
                fputcsv( $out, array( 'Customer', 'Email', 'Orders', 'Total Spend' ) );
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                        $sql = $wpdb->prepare( "SELECT customer_name, customer_email, COUNT(*) as cnt, SUM(base_total) as spend FROM {$orders_t} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s GROUP BY customer_email ORDER BY spend DESC LIMIT %d OFFSET %d", $range_lo, $range_hi, $limit, $offset );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array(
                            $this->sanitize_csv_value( $r->customer_name ),
                            $this->sanitize_csv_value( $r->customer_email ),
                            (int) $r->cnt,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->spend, $currency ),
                        ) );
                    }
                );
                break;
            case 'stock':
                // Audit 07 F-17 — scope defaults to "low" to mirror the
                // Stock tab UI; pass ?scope=all to dump every managed
                // product.
                $stock_scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'low';
                if ( ! in_array( $stock_scope, array( 'low', 'all' ), true ) ) {
                    $stock_scope = 'low';
                }
                $threshold = max( 0, (int) get_option( 'tejcart_low_stock_threshold', 2 ) );

                fputcsv( $out, array( 'Product', 'SKU', 'Stock', 'Status' ) );
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $prods_t, $stock_scope, $threshold ) {
                        if ( 'low' === $stock_scope ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                            $sql = $wpdb->prepare( "SELECT name, sku, stock_quantity, stock_status FROM {$prods_t} WHERE status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d ORDER BY stock_quantity ASC, id ASC LIMIT %d OFFSET %d", $threshold, $limit, $offset );
                        } else {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                            $sql = $wpdb->prepare( "SELECT name, sku, stock_quantity, stock_status FROM {$prods_t} WHERE status = 'publish' AND manage_stock = 1 ORDER BY stock_quantity ASC, id ASC LIMIT %d OFFSET %d", $limit, $offset );
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out ) {
                        fputcsv( $out, array(
                            $this->sanitize_csv_value( $r->name ),
                            $this->sanitize_csv_value( $r->sku ),
                            (int) $r->stock_quantity,
                            $this->sanitize_csv_value( $r->stock_status ),
                        ) );
                    }
                );
                break;
            case 'tax':
                fputcsv( $out, array( 'Status', 'Tax Total' ) );
                // GROUP BY status is bounded by the (~10) order
                // statuses — one query is enough and pagination is
                // unnecessary.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                $sql_tax = $wpdb->prepare( "SELECT status, SUM(base_tax_total) as tax FROM {$orders_t} WHERE created_at BETWEEN %s AND %s GROUP BY status", $range_lo, $range_hi );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                $rows = $wpdb->get_results( $sql_tax );
                foreach ( (array) $rows as $r ) {
                    fputcsv( $out, array( $this->sanitize_csv_value( $r->status ), \TejCart\Money\Currency::from_minor_units( (int) $r->tax, $currency ) ) );
                }
                fflush( $out );
                break;
            case 'statuses':
                fputcsv( $out, array( 'Status', 'Orders', 'Revenue' ) );
                // GROUP BY status is bounded by the (~10) order statuses —
                // no pagination needed.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $sql_st = $wpdb->prepare( "SELECT status, COUNT(*) AS order_count, COALESCE(SUM(base_total),0) AS revenue FROM {$orders_t} WHERE created_at BETWEEN %s AND %s GROUP BY status ORDER BY order_count DESC", $range_lo, $range_hi );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $rows_st = $wpdb->get_results( $sql_st );
                foreach ( (array) $rows_st as $r ) {
                    fputcsv( $out, array(
                        $this->sanitize_csv_value( $r->status ),
                        (int) $r->order_count,
                        \TejCart\Money\Currency::from_minor_units( (int) $r->revenue, $currency ),
                    ) );
                }
                fflush( $out );
                break;
            case 'coupons':
                fputcsv( $out, array( 'Code', 'Usages', 'Revenue', 'Discount' ) );
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        $sql = $wpdb->prepare( "SELECT coupon_code, COUNT(*) AS usages, COALESCE(SUM(base_total),0) AS revenue, COALESCE(SUM(base_discount_total),0) AS discount FROM {$orders_t} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s AND coupon_code <> '' GROUP BY coupon_code ORDER BY usages DESC LIMIT %d OFFSET %d", $range_lo, $range_hi, $limit, $offset );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array(
                            $this->sanitize_csv_value( strtoupper( (string) $r->coupon_code ) ),
                            (int) $r->usages,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->revenue,  $currency ),
                            \TejCart\Money\Currency::from_minor_units( (int) $r->discount, $currency ),
                        ) );
                    }
                );
                break;
            case 'categories':
                fputcsv( $out, array( 'Category', 'Orders', 'Units', 'Revenue' ) );
                $rels_t  = $wpdb->prefix . 'term_relationships';
                $tax_t   = $wpdb->prefix . 'term_taxonomy';
                $terms_t = $wpdb->prefix . 'terms';
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $items_t, $orders_t, $rels_t, $tax_t, $terms_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        $sql = $wpdb->prepare(
                            "SELECT t.name, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(oi.quantity),0) AS units, COALESCE(SUM(oi.base_line_total),0) AS revenue
                             FROM {$items_t} oi
                             INNER JOIN {$orders_t} o ON oi.order_id = o.id
                             INNER JOIN {$rels_t}   tr ON tr.object_id = oi.product_id
                             INNER JOIN {$tax_t}    tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                             INNER JOIN {$terms_t}  t  ON t.term_id = tt.term_id
                             WHERE o.status IN ('completed','processing')
                               AND o.created_at BETWEEN %s AND %s
                               AND tt.taxonomy = 'tejcart_product_category'
                             GROUP BY t.term_id, t.name
                             ORDER BY revenue DESC
                             LIMIT %d OFFSET %d",
                            $range_lo, $range_hi, $limit, $offset
                        );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array(
                            $this->sanitize_csv_value( $r->name ),
                            (int) $r->order_count,
                            (int) $r->units,
                            \TejCart\Money\Currency::from_minor_units( (int) $r->revenue, $currency ),
                        ) );
                    }
                );
                break;
            case 'refunds':
                fputcsv( $out, array( 'Date', 'Order ID', 'Customer', 'Amount', 'Reason' ) );
                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $refunds_t, $orders_t, $range_lo, $range_hi ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                        $sql = $wpdb->prepare( "SELECT r.created_at, r.order_id, o.customer_name, r.amount, r.reason FROM {$refunds_t} r LEFT JOIN {$orders_t} o ON o.id = r.order_id WHERE r.created_at BETWEEN %s AND %s ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d", $range_lo, $range_hi, $limit, $offset );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array(
                            $this->sanitize_csv_value( $r->created_at ),
                            (int) $r->order_id,
                            $this->sanitize_csv_value( $r->customer_name ),
                            \TejCart\Money\Currency::from_minor_units( (int) $r->amount, $currency ),
                            $this->sanitize_csv_value( $r->reason ),
                        ) );
                    }
                );
                break;
            default:
                fputcsv( $out, array( 'Product', 'Units Sold', 'Revenue' ) );
                // Audit #100 / 07 F-12 — prefer the precomputed
                // `tejcart_product_daily` rollup; fall back to the live
                // 3-table JOIN only when the rollup doesn't cover the
                // requested range.
                $expected_days   = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
                $agg             = class_exists( \TejCart\Reports\Daily_Summary::class )
                    ? \TejCart\Reports\Daily_Summary::read_range( $from, $to, $currency )
                    : null;
                $rollup_complete = $agg && (int) ( $agg['days_present'] ?? 0 ) >= $expected_days;

                $this->stream_csv_rows(
                    $out,
                    static function ( int $limit, int $offset ) use ( $wpdb, $items_t, $orders_t, $prods_t, $range_lo, $range_hi, $from, $to, $rollup_complete, $currency ) {
                        if ( $rollup_complete ) {
                            $daily = $wpdb->prefix . 'tejcart_product_daily';
                            // F-002: per-currency rollup, so the rollup-fast-path
                            // sums revenue only inside the same currency the
                            // CSV row is going to be labelled with.
                            [ $utc_from, $utc_to ] = \TejCart\Reports\Date_Range::to_utc_day_bounds( $from, $to );
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                            $sql = $wpdb->prepare(
                                "SELECT p.name, SUM(d.qty) as qty, SUM(d.revenue) as rev
                                   FROM {$daily} d
                              LEFT JOIN {$prods_t} p ON p.id = d.product_id
                                  WHERE d.day BETWEEN %s AND %s
                                    AND d.currency = %s
                               GROUP BY d.product_id, p.name
                               ORDER BY rev DESC
                                  LIMIT %d OFFSET %d",
                                $utc_from, $utc_to, $currency, $limit, $offset
                            );
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                            return $wpdb->get_results( $sql );
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                        $sql = $wpdb->prepare( "SELECT p.name, SUM(oi.quantity) as qty, SUM(oi.base_line_total) as rev FROM {$items_t} oi INNER JOIN {$orders_t} o ON oi.order_id = o.id INNER JOIN {$prods_t} p ON oi.product_id = p.id WHERE o.status IN ('completed','processing') AND o.created_at BETWEEN %s AND %s GROUP BY oi.product_id ORDER BY rev DESC LIMIT %d OFFSET %d", $range_lo, $range_hi, $limit, $offset );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                        return $wpdb->get_results( $sql );
                    },
                    function ( $r ) use ( $out, $currency ) {
                        fputcsv( $out, array( $this->sanitize_csv_value( $r->name ), (int) $r->qty, \TejCart\Money\Currency::from_minor_units( (int) $r->rev, $currency ) ) );
                    }
                );
                break;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Stream a paginated SQL result set out as CSV.
     *
     * Fetches `CSV_EXPORT_CHUNK` rows at a time via the supplied
     * `$fetcher( int $limit, int $offset ): array` callable and pipes
     * each row to `$row_writer`. Flushes after every page so the
     * client starts receiving bytes early and PHP's peak memory stays
     * bounded — without this, a 400k-order customers export would
     * materialise every grouped row in memory before the first byte
     * goes out.
     *
     * @param resource $out
     * @param callable $fetcher    Returns array of stdClass rows for (limit, offset).
     * @param callable $row_writer Receives one row and writes it to $out.
     */
    private function stream_csv_rows( $out, callable $fetcher, callable $row_writer ): void {
        $chunk  = self::CSV_EXPORT_CHUNK;
        $offset = 0;
        do {
            $rows = (array) $fetcher( $chunk, $offset );
            foreach ( $rows as $row ) {
                $row_writer( $row );
            }
            fflush( $out );
            $offset += $chunk;
        } while ( count( $rows ) === $chunk );
    }

    /**
     * Page size for streamed CSV exports. Sized so a single chunk fits
     * comfortably in PHP's default 128M memory limit even for the
     * widest row (refunds, ~5 columns of mixed text).
     */
    private const CSV_EXPORT_CHUNK = 1000;
}
