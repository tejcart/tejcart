<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Analytics_Advanced {

    private static ?self $instance = null;

    public static function init(): void {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->register_hooks();
    }

    public static function instance(): ?self {
        return self::$instance;
    }

    public static function reset(): void {
        self::$instance = null;
    }

    private function register_hooks(): void {
        add_action( 'admin_init', array( $this, 'handle_export' ) );
        add_action( 'wp_ajax_tejcart_analytics_advanced_rebuild', array( $this, 'ajax_rebuild' ) );
        add_action( 'tejcart_order_status_changed', array( $this, 'schedule_incremental' ), 20, 3 );
        add_action( 'tejcart_analytics_advanced_incremental', array( $this, 'run_incremental' ) );
        add_action( 'tejcart_analytics_advanced_rebuild_cohorts', array( $this, 'run_rebuild' ) );
        add_action( 'tejcart_analytics_advanced_rebuild_chunk', array( $this, 'run_rebuild_chunk' ) );

        if ( is_admin() ) {
            add_action( 'admin_print_styles-tejcart_page_tejcart-analytics', array( $this, 'maybe_enqueue_admin_assets' ) );
        }

        Channel_Tracker::init();
        ( new Privacy() )->register();
    }

    public function maybe_enqueue_admin_assets(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( (string) $_GET['provider'] ) ) : '';
        if ( 'advanced' === $provider ) {
            $this->enqueue_admin_assets();
        }
    }

    public function enqueue_admin_assets(): void {
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        wp_enqueue_style(
            'tejcart-analytics-advanced',
            plugins_url( 'assets/css/dashboard' . $suffix . '.css', TEJCART_ANALYTICS_ADVANCED_FILE ),
            array( 'tejcart-admin' ),
            TEJCART_ANALYTICS_ADVANCED_VERSION
        );

        wp_register_script(
            'chart-js',
            plugins_url( 'assets/js/vendor/chart.umd.min.js', TEJCART_ANALYTICS_ADVANCED_FILE ),
            array(),
            '4.5.1',
            true
        );

        wp_enqueue_script(
            'tejcart-analytics-advanced-charts',
            plugins_url( 'assets/js/dashboard-charts' . $suffix . '.js', TEJCART_ANALYTICS_ADVANCED_FILE ),
            array( 'chart-js' ),
            TEJCART_ANALYTICS_ADVANCED_VERSION,
            true
        );
    }

    /**
     * Schedule an incremental update for a single customer.
     */
    public function schedule_incremental( string $old_status, string $new_status, object $order ): void {
        $terminal = array( 'completed', 'processing', 'refunded', 'cancelled' );
        if ( ! in_array( $new_status, $terminal, true ) && ! in_array( $old_status, $terminal, true ) ) {
            return;
        }

        // Order data lives in a protected $data array exposed only through
        // accessors — reading $order->customer_email directly returns null
        // (PHP 8.2 undefined-property), which silently disabled every
        // incremental update. Use the public getter.
        $email = method_exists( $order, 'get_customer_email' ) ? (string) $order->get_customer_email() : '';
        if ( '' === $email ) {
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 60,
                'tejcart_analytics_advanced_incremental',
                array( 'email' => $email ),
                'tejcart'
            );
        }
    }

    /**
     * Run an incremental update for a single customer.
     */
    public function run_incremental( string $email ): void {
        if ( '' === $email ) {
            return;
        }

        $calculator = new LTV_Calculator();
        $calculator->update_customer( $email );

        $cohort        = new Cohort_Report();
        $cohort_months = self::resolve_cohort_months( $email );
        if ( ! empty( $cohort_months ) ) {
            $cohort->refresh_cohort_months( $cohort_months );
        }

        Report_Cache::flush_all();
    }

    /**
     * Schedule the next chunk of a full rebuild (LTV only — cohorts
     * rebuild at the end once LTV is complete).
     */
    public static function schedule_chunked_rebuild( int $offset ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 5,
                'tejcart_analytics_advanced_rebuild_chunk',
                array( 'offset' => $offset ),
                'tejcart'
            );
        }
    }

    /**
     * Process one chunk of the LTV full rebuild. When the last chunk
     * finishes, trigger a full cohort rebuild and flush the cache.
     */
    public function run_rebuild_chunk( int $offset ): void {
        $calculator = new LTV_Calculator();
        $finished   = $calculator->rebuild_all( $offset );

        if ( $finished ) {
            $cohort = new Cohort_Report();
            $cohort->rebuild_all();
            Report_Cache::flush_all();
        }
    }

    /**
     * @return array<int, string>
     */
    public static function paid_statuses(): array {
        return (array) apply_filters( 'tejcart_analytics_advanced_paid_statuses', array( 'completed', 'processing' ) );
    }

    /**
     * @return string
     */
    public static function paid_statuses_sql(): string {
        $statuses = self::paid_statuses();
        return implode( ',', array_map( static fn( string $s ) => "'" . \esc_sql( $s ) . "'", $statuses ) );
    }

    /**
     * Legacy full rebuild — kept for manual admin trigger and the
     * `tejcart_analytics_advanced_rebuild_cohorts` hook.
     *
     * Starts a chunked LTV rebuild at offset 0, then rebuilds cohorts.
     */
    public function run_rebuild(): void {
        $calculator = new LTV_Calculator();
        $finished   = $calculator->rebuild_all( 0 );

        if ( $finished ) {
            $cohort = new Cohort_Report();
            $cohort->rebuild_all();
            Report_Cache::flush_all();
        }
    }

    /**
     * Determine which cohort months a customer belongs to (current and
     * possibly previous, if the order changes their first-order date).
     *
     * @return array<int, string> Distinct YYYY-MM strings.
     */
    private static function resolve_cohort_months( string $email ): array {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $paid_sql     = self::paid_statuses_sql();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT DATE_FORMAT(MIN(created_at), '%%Y-%%m')
                 FROM {$orders_table}
                 WHERE customer_email = %s
                   AND status IN ({$paid_sql})
                 GROUP BY currency",
                $email
            )
        );

        $ltv_table = Schema::customer_ltv_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT cohort_month FROM {$ltv_table} WHERE customer_email = %s",
                $email
            )
        );

        return array_values( array_unique( array_merge( $rows, array_filter( $existing ) ) ) );
    }

    public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'cohorts';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $currency = isset( $_GET['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['currency'] ) ) ) : '';

        $tabs = array(
            'cohorts'  => __( 'Cohorts', 'tejcart' ),
            'ltv'      => __( 'Lifetime Value', 'tejcart' ),
            'segments' => __( 'Segments', 'tejcart' ),
            'trends'   => __( 'Trends', 'tejcart' ),
        );

        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'cohorts';
        }

        if ( '' === $currency || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            $currency = (string) get_option( 'tejcart_currency', 'USD' );
        }

        Dashboard::render( $tab, $tabs, $currency );
    }

    public function handle_export(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['tejcart_analytics_advanced_export'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'tejcart_analytics_advanced_export' );

        $report = sanitize_text_field( wp_unslash( $_GET['tejcart_analytics_advanced_export'] ) );
        $format = isset( $_GET['format'] ) ? sanitize_text_field( wp_unslash( $_GET['format'] ) ) : 'csv';

        Exporter::export( $report, $format );
    }

    public function ajax_rebuild(): void {
        check_ajax_referer( 'tejcart_analytics_advanced_rebuild' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tejcart' ) ), 403 );
        }

        $this->run_rebuild();

        wp_send_json_success( array(
            'message'  => __( 'Analytics data rebuilt successfully.', 'tejcart' ),
            'built_at' => time(),
        ) );
    }
}
