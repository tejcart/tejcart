<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

use TejCart\Money\Currency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dashboard {

    /** @var array<string, mixed> Collected during render, emitted as JSON for the chart JS. */
    private static array $chart_data = array();

    /**
     * @param array<string, string> $tabs
     */
    public static function render( string $active_tab, array $tabs, string $currency = '' ): void {
        self::$chart_data = array( 'tab' => $active_tab );

        if ( '' === $currency ) {
            $currency = (string) get_option( 'tejcart_currency', 'USD' );
        }

        $page_slug = 'tejcart-analytics';
        $tab_base  = array( 'page' => $page_slug, 'provider' => 'advanced' );

        $export_url = wp_nonce_url(
            add_query_arg(
                array(
                    'tejcart_analytics_advanced_export' => $active_tab,
                    'currency' => $currency,
                ),
                admin_url( 'admin.php' )
            ),
            'tejcart_analytics_advanced_export'
        );

        $available_currencies = self::get_active_currencies();

        ?>
        <div class="tejcart-analytics-advanced">
            <div style="display:flex;align-items:center;justify-content:space-between;margin:12px 0 16px;">
                <div>
                    <p class="description" style="margin:0;">
                        <?php esc_html_e( 'Cohort analysis, lifetime value and customer segments.', 'tejcart' ); ?>
                    </p>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <?php if ( count( $available_currencies ) > 1 ) : ?>
                        <select class="tejcart-aa-currency-select" onchange="location.href=this.value" aria-label="<?php esc_attr_e( 'Select currency', 'tejcart' ); ?>">
                            <?php foreach ( $available_currencies as $code ) :
                                $url = add_query_arg(
                                    array_merge( $tab_base, array( 'tab' => $active_tab, 'currency' => $code ) ),
                                    admin_url( 'admin.php' )
                                );
                                ?>
                                <option value="<?php echo esc_url( $url ); ?>" <?php selected( $code, $currency ); ?>>
                                    <?php echo esc_html( $code ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <a class="nxc-btn" href="<?php echo esc_url( $export_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?php esc_html_e( 'Export CSV', 'tejcart' ); ?>
                    </a>
                    <a class="nxc-btn" href="<?php echo esc_url( add_query_arg( 'format', 'html', $export_url ) ); ?>">
                        <?php esc_html_e( 'Export HTML', 'tejcart' ); ?>
                    </a>
                </div>
            </div>

            <nav class="tejcart-settings-subnav">
                <?php foreach ( $tabs as $slug => $label ) :
                    $url       = add_query_arg(
                        array_merge( $tab_base, array( 'tab' => $slug ) ),
                        admin_url( 'admin.php' )
                    );
                    $is_active = ( $slug === $active_tab );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="tejcart-settings-subnav-item<?php echo $is_active ? ' is-active' : ''; ?>"
                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php Report_Cache::render_staleness_banner(); ?>

            <?php
            switch ( $active_tab ) {
                case 'cohorts':
                    self::render_cohorts( $currency );
                    break;
                case 'ltv':
                    self::render_ltv( $currency );
                    break;
                case 'segments':
                    self::render_segments( $currency );
                    break;
                case 'trends':
                    self::render_trends( $currency );
                    break;
            }
            ?>

            <?php self::emit_chart_data(); ?>
        </div>
        <?php
    }

    private static function render_cohorts( string $currency ): void {
        $matrix = Report_Cache::get( 'cohorts', $currency );
        if ( false === $matrix ) {
            $report = new Cohort_Report();
            $matrix = $report->get_matrix( $currency );
            Report_Cache::set( 'cohorts', $currency, $matrix );
        }

        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'Acquisition Cohort Retention', 'tejcart' ); ?></h2>
            </div>
            <div class="tejcart-card-body">
                <?php if ( empty( $matrix ) ) : ?>
                    <p class="nxc-empty-state__message"><?php esc_html_e( 'No cohort data yet. Cohorts are rebuilt automatically when orders change status.', 'tejcart' ); ?></p>
                <?php else : ?>
                    <div class="tejcart-aa-table-wrap">
                        <table class="tejcart-aa-cohort-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Cohort', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Customers', 'tejcart' ); ?></th>
                                    <?php for ( $m = 0; $m <= 12; $m++ ) : ?>
                                        <th>M<?php echo esc_html( (string) $m ); ?></th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $matrix as $cohort ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $cohort['cohort_month'] ); ?></strong></td>
                                        <td><?php echo esc_html( number_format( $cohort['customer_count'] ) ); ?></td>
                                        <?php for ( $m = 0; $m <= 12; $m++ ) :
                                            $rate  = $cohort['retention'][ $m ]['rate'] ?? 0;
                                            $class = self::retention_class( (float) $rate );
                                            ?>
                                            <td class="tejcart-aa-retention-cell <?php echo esc_attr( $class ); ?>">
                                                <?php echo esc_html( $rate . '%' ); ?>
                                            </td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_ltv( string $currency ): void {
        $cached = Report_Cache::get( 'ltv', $currency );
        if ( false !== $cached ) {
            $channels = $cached['channels'];
            $top      = $cached['top'];
        } else {
            $calc     = new LTV_Calculator();
            $channels = $calc->get_by_channel( $currency );
            $top      = $calc->get_top_customers( $currency, 25 );
            Report_Cache::set( 'ltv', $currency, array( 'channels' => $channels, 'top' => $top ) );
        }

        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'LTV by Acquisition Channel', 'tejcart' ); ?></h2>
            </div>
            <div class="tejcart-card-body">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Channel', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Customers', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Total Revenue', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Avg LTV', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Avg Orders', 'tejcart' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $channels as $channel => $data ) : ?>
                            <tr>
                                <td><?php echo esc_html( ucfirst( $channel ) ); ?></td>
                                <td><?php echo esc_html( number_format( $data['customer_count'] ) ); ?></td>
                                <td><?php echo esc_html( self::fmt( $data['total_revenue'], $currency ) ); ?></td>
                                <td><strong><?php echo esc_html( self::fmt( $data['avg_ltv'], $currency ) ); ?></strong></td>
                                <td><?php echo esc_html( (string) $data['avg_orders'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tejcart-card tejcart-aa-top-customers">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'Top 25 Customers by LTV', 'tejcart' ); ?></h2>
            </div>
            <div class="tejcart-card-body">
                <?php if ( empty( $top ) ) : ?>
                    <p class="nxc-empty-state__message"><?php esc_html_e( 'No customer data yet.', 'tejcart' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Email', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Orders', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Total Revenue', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Avg Order', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'First Order', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Channel', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $top as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['customer_email'] ); ?></td>
                                    <td><?php echo esc_html( $row['order_count'] ); ?></td>
                                    <td><strong><?php echo esc_html( self::fmt( (int) $row['total_revenue'], $currency ) ); ?></strong></td>
                                    <td><?php echo esc_html( self::fmt( (int) $row['avg_order_value'], $currency ) ); ?></td>
                                    <td><?php echo esc_html( (string) wp_date( get_option( 'date_format', 'Y-m-d' ), strtotime( $row['first_order_date'] ) ) ); ?></td>
                                    <td><span class="tejcart-pill"><?php echo esc_html( ucfirst( $row['acquisition_channel'] ) ); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_segments( string $currency ): void {
        $data = Report_Cache::get( 'segments', $currency );
        if ( false === $data ) {
            $report = new Segment_Report();
            $data   = $report->get_breakdown( $currency );
            Report_Cache::set( 'segments', $currency, $data );
        }
        $labels  = Segment_Report::labels();
        $tones   = array(
            'vip'     => 'success',
            'loyal'   => 'success',
            'active'  => 'info',
            'at-risk' => 'warning',
            'lapsed'  => 'error',
            'new'     => 'info',
        );

        $has_data = false;
        foreach ( $data as $seg ) {
            if ( $seg['customer_count'] > 0 ) {
                $has_data = true;
                break;
            }
        }

        if ( $has_data ) {
            self::$chart_data['segments'] = array(
                'segments'          => $data,
                'segment_labels'    => $labels,
                'currency_decimals' => Currency::decimals( $currency ),
                'currency_symbol'   => self::currency_symbol( $currency ),
            );
        }

        if ( $has_data ) : ?>
            <div class="tejcart-aa-segments-charts">
                <div class="tejcart-card tejcart-aa-segments-chart-card">
                    <div class="tejcart-card-header">
                        <h2><?php esc_html_e( 'Customer Distribution', 'tejcart' ); ?></h2>
                    </div>
                    <div class="tejcart-card-body">
                        <div class="tejcart-aa-chart-container tejcart-aa-chart-container--square">
                            <canvas id="tejcart-aa-segments-donut"></canvas>
                        </div>
                    </div>
                </div>
                <div class="tejcart-card tejcart-aa-segments-chart-card">
                    <div class="tejcart-card-header">
                        <h2><?php esc_html_e( 'Revenue by Segment', 'tejcart' ); ?></h2>
                    </div>
                    <div class="tejcart-card-body">
                        <div class="tejcart-aa-chart-container tejcart-aa-chart-container--square">
                            <canvas id="tejcart-aa-segments-bar"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="tejcart-aa-kpi-grid">
            <?php foreach ( $data as $key => $seg ) : ?>
                <div class="tejcart-card tejcart-aa-kpi-card">
                    <div class="tejcart-card-header">
                        <span class="tejcart-pill tejcart-pill--<?php echo esc_attr( $tones[ $key ] ?? '' ); ?>">
                            <?php echo esc_html( $labels[ $key ] ?? $key ); ?>
                        </span>
                    </div>
                    <div class="tejcart-card-body">
                        <div class="tejcart-aa-kpi-value"><?php echo esc_html( number_format( $seg['customer_count'] ) ); ?></div>
                        <div class="tejcart-aa-kpi-label"><?php esc_html_e( 'customers', 'tejcart' ); ?></div>
                        <div class="tejcart-aa-kpi-detail">
                            <?php
                            printf(
                                /* translators: 1: revenue, 2: order count */
                                esc_html__( '%1$s revenue · %2$s orders', 'tejcart' ),
                                esc_html( self::fmt( $seg['revenue'], $currency ) ),
                                esc_html( number_format( $seg['order_count'] ) )
                            );
                            ?>
                        </div>
                        <div class="tejcart-aa-kpi-detail">
                            <?php
                            printf(
                                /* translators: %s: average LTV */
                                esc_html__( 'Avg LTV: %s', 'tejcart' ),
                                esc_html( self::fmt( $seg['avg_ltv'], $currency ) )
                            );
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_trends( string $currency ): void {
        $monthly = Report_Cache::get( 'trends', $currency );
        if ( false === $monthly ) {
            $report  = new Trend_Report();
            $from    = gmdate( 'Y-m-01', strtotime( '-12 months' ) );
            $to      = gmdate( 'Y-m-d' );
            $monthly = $report->get_monthly_trends( $from, $to, $currency );
            Report_Cache::set( 'trends', $currency, $monthly );
        }

        if ( ! empty( $monthly ) ) {
            self::$chart_data['trends'] = array(
                'rows'              => array_values( $monthly ),
                'currency_decimals' => Currency::decimals( $currency ),
                'currency_symbol'   => self::currency_symbol( $currency ),
                'labels'            => array(
                    'revenue'   => __( 'Revenue', 'tejcart' ),
                    'orders'    => __( 'Orders', 'tejcart' ),
                    'aov'       => __( 'AOV', 'tejcart' ),
                    'customers' => __( 'Customers', 'tejcart' ),
                ),
            );
        }

        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'Monthly Trends (Last 12 Months)', 'tejcart' ); ?></h2>
            </div>
            <div class="tejcart-card-body">
                <?php if ( empty( $monthly ) ) : ?>
                    <p class="nxc-empty-state__message"><?php esc_html_e( 'No trend data available yet.', 'tejcart' ); ?></p>
                <?php else : ?>
                    <div class="tejcart-aa-chart-container">
                        <canvas id="tejcart-aa-trends-chart"></canvas>
                    </div>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Month', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Revenue', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Orders', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'AOV', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Customers', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $monthly as $row ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $row['month'] ); ?></strong></td>
                                    <td><?php echo esc_html( self::fmt( $row['revenue'], $currency ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['order_count'] ) ); ?></td>
                                    <td><?php echo esc_html( self::fmt( $row['aov'], $currency ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $row['customer_count'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function retention_class( float $rate ): string {
        if ( $rate >= 50.0 ) {
            return 'tejcart-aa-retention--high';
        }
        if ( $rate >= 20.0 ) {
            return 'tejcart-aa-retention--mid';
        }
        if ( $rate > 0.0 ) {
            return 'tejcart-aa-retention--low';
        }
        return '';
    }

    private static function emit_chart_data(): void {
        if ( count( self::$chart_data ) <= 1 ) {
            return;
        }
        ?>
        <script type="application/json" id="tejcart-aa-chart-data"><?php echo wp_json_encode( self::$chart_data ); ?></script>
        <?php
    }

    private static function currency_symbol( string $currency ): string {
        $symbols = array(
            'USD' => '$',  'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}",
            'JPY' => "\u{00A5}", 'CAD' => 'CA$', 'AUD' => 'A$',
            'INR' => "\u{20B9}", 'BRL' => 'R$',  'MXN' => 'MX$',
        );
        return (string) apply_filters(
            'tejcart_analytics_advanced_currency_symbol',
            $symbols[ $currency ] ?? $currency . ' ',
            $currency
        );
    }

    /**
     * @return array<int, string> ISO-4217 codes with at least one LTV row.
     */
    private static function get_active_currencies(): array {
        global $wpdb;

        $ltv_table = Schema::customer_ltv_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $codes = $wpdb->get_col( "SELECT DISTINCT currency FROM {$ltv_table} ORDER BY currency" );

        $base = (string) get_option( 'tejcart_currency', 'USD' );
        if ( empty( $codes ) ) {
            return array( $base );
        }

        if ( ! in_array( $base, $codes, true ) ) {
            array_unshift( $codes, $base );
        }

        return $codes;
    }

    private static function fmt( int $minor, string $currency ): string {
        $major = Currency::from_minor_units( $minor, $currency );
        return number_format( $major, Currency::decimals( $currency ) );
    }
}
