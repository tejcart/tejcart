<?php
/**
 * WP Dashboard status widget.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a TejCart status widget to the WordPress dashboard showing
 * today's revenue, order count, and a short list of recent orders.
 *
 * Provides shop managers with at-a-glance information they can scan in
 * the first hour of the day.
 */
class Dashboard_Widget {
    /**
     * Register the widget.
     */
    public function init(): void {
        add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Enqueue the widget stylesheet on the WP dashboard screen only.
     *
     * The widget renders inside `wp-admin/index.php`, which never
     * loads the regular `tejcart-admin.css` bundle. A dedicated
     * stylesheet keeps the markup free of inline `style="…"` attributes
     * and means a future visual tweak doesn't need a PHP edit.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets( $hook ): void {
        if ( 'index.php' !== (string) $hook ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';
        wp_enqueue_style(
            'tejcart-dashboard-widget',
            tejcart_asset_url( 'assets/css/admin/dashboard-widget.css' ),
            array(),
            $version
        );
    }

    /**
     * Hook the widget into WP's dashboard only for users who can manage
     * the store.
     */
    public function register(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'tejcart_dashboard_status',
            __( 'TejCart status', 'tejcart' ),
            array( $this, 'render' )
        );
    }

    /**
     * Transient key for the widget's 60-second cache of pending count +
     * recent orders. Today + revenue are read from Daily_Summary
     * directly so they reflect each status change immediately.
     */
    private const CACHE_KEY = 'tejcart_dashboard_widget_v1';

    /**
     * Render the widget body.
     */
    public function render(): void {
        $currency = (string) get_option( 'tejcart_currency', 'USD' );

        // Audit #75 / 07 F-2 — read today's revenue + order count from
        // the precomputed `tejcart_daily_summary` rollup. The bucket is
        // recomputed on every order status transition, so it stays in
        // sync with the live `tejcart_orders` table without re-running
        // SUM/COUNT on every dashboard render. Fall through to a live
        // SUM/COUNT only when the bucket row is missing (early-of-day,
        // before the first status change of the day).
        $today_bucket = \TejCart\Reports\Daily_Summary::read_bucket( gmdate( 'Y-m-d' ), $currency );
        if ( null !== $today_bucket ) {
            $today_revenue = $today_bucket['revenue'];
            $today_orders  = (int) ( $today_bucket['order_count'] ?? 0 );
        } else {
            [ $today_revenue, $today_orders ] = $this->compute_today_live( $currency );
        }

        // Pending count + recent-orders list are not in the rollup, so
        // wrap them in a 60-second transient. Cheap to refresh, and
        // managers who keep the dashboard open in a background tab
        // would otherwise hit two DB queries per WP-Heartbeat tick.
        $cached = get_transient( self::CACHE_KEY );
        if ( false === $cached || ! is_array( $cached ) ) {
            $cached = $this->compute_pending_and_recent();
            set_transient( self::CACHE_KEY, $cached, MINUTE_IN_SECONDS );
        }

        $pending_orders = (int) ( $cached['pending'] ?? 0 );
        $recent         = isset( $cached['recent'] ) && is_array( $cached['recent'] )
            ? $cached['recent']
            : array();

        $orders_page = admin_url( 'admin.php?page=tejcart-orders' );
        ?>
        <div class="tejcart-dashboard-widget">
            <ul class="tejcart-dashboard-widget__strap">
                <li><strong><?php esc_html_e( 'Today', 'tejcart' ); ?>:</strong> <?php echo wp_kses_post( tejcart_price( $today_revenue ) ); ?> (<?php echo (int) $today_orders; ?>)</li>
                <li><strong><?php esc_html_e( 'Pending', 'tejcart' ); ?>:</strong> <?php echo (int) $pending_orders; ?></li>
            </ul>

            <?php if ( ! empty( $recent ) ) : ?>
                <h4 class="tejcart-dashboard-widget__section-title"><?php esc_html_e( 'Recent orders', 'tejcart' ); ?></h4>
                <ul class="tejcart-dashboard-widget__list">
                    <?php foreach ( $recent as $order ) : ?>
                        <li class="tejcart-dashboard-widget__row">
                            <span>
                                <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'order_id' => (int) $order->id ), $orders_page ) ); ?>">
                                    #<?php echo (int) $order->id; ?>
                                </a>
                                &mdash; <?php echo esc_html( (string) ( $order->customer_name ?: __( 'Guest', 'tejcart' ) ) ); ?>
                            </span>
                            <span>
                                <?php echo wp_kses_post( tejcart_price( \TejCart\Money\Money::from_minor_units( (int) $order->total, (string) ( $order->currency ?? $currency ) ) ) ); ?>
                                <em class="tejcart-dashboard-widget__status"><?php echo esc_html( ucfirst( (string) $order->status ) ); ?></em>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="tejcart-dashboard-widget__footer">
                    <a href="<?php echo esc_url( $orders_page ); ?>"><?php esc_html_e( 'View all orders →', 'tejcart' ); ?></a>
                </p>
            <?php else : ?>
                <p class="tejcart-dashboard-widget__empty"><?php esc_html_e( 'No orders yet.', 'tejcart' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Live SUM/COUNT for today, used only when the Daily_Summary bucket
     * row has not been written yet (no order status transitions today).
     *
     * @return array{0:mixed,1:int} [Money $revenue, int $order_count]
     */
    private function compute_today_live( string $currency ): array {
        global $wpdb;
        $orders      = $wpdb->prefix . 'tejcart_orders';
        $today_start = gmdate( 'Y-m-d 00:00:00' );
        $today_end   = gmdate( 'Y-m-d 23:59:59' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $revenue = \TejCart\Money\Currency::from_minor_units(
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(base_total),0) FROM {$orders}
                     WHERE status IN ('completed','processing')
                       AND created_at BETWEEN %s AND %s",
                    $today_start,
                    $today_end
                )
            ),
            $currency
        );

        // 07 F-7: count and revenue share a status filter so the
        // implied AOV isn't pulled down by failed/cancelled orders.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders}
                  WHERE status IN ('completed','processing')
                    AND created_at BETWEEN %s AND %s",
                $today_start,
                $today_end
            )
        );
        // phpcs:enable

        return array( $revenue, $count );
    }

    /**
     * Compute the pending count + recent-orders list. Hot data refreshed
     * via the 60s transient in render().
     *
     * @return array{pending:int,recent:array<int,object>}
     */
    private function compute_pending_and_recent(): array {
        global $wpdb;
        $orders = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$orders} WHERE status IN ('pending','on-hold')"
        );

        $recent = (array) $wpdb->get_results(
            "SELECT id, status, customer_name, currency, total, created_at
             FROM {$orders}
             ORDER BY id DESC
             LIMIT 5"
        );
        // phpcs:enable

        return array(
            'pending' => $pending,
            'recent'  => $recent,
        );
    }
}
