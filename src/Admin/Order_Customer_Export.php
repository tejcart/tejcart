<?php
/**
 * CSV export for Orders and Customers.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Streams CSV exports of the TejCart orders and customers tables.
 *
 * Triggered via admin-post.php actions:
 *   - tejcart_export_orders
 *   - tejcart_export_customers
 */
class Order_Customer_Export {
    /**
     * User meta key used to remember a PII-export-notice dismissal.
     */
    private const PII_EXPORT_NOTICE_META = 'tejcart_dismissed_pii_export_notice';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_post_tejcart_export_orders',    array( $this, 'export_orders' ) );
        add_action( 'admin_post_tejcart_export_customers', array( $this, 'export_customers' ) );
        // F-PCA-005: Render a PII warning notice on the orders and customers list pages.
        add_action( 'admin_notices', array( $this, 'maybe_render_pii_export_notice' ) );
        // Persist a per-user dismissal so an acknowledged notice stays hidden.
        add_action( 'admin_init', array( $this, 'handle_pii_export_notice_dismiss' ) );
    }

    /**
     * Display a contextual PII-export notice on the orders and customers list
     * pages so administrators are aware the CSV download contains personal data.
     *
     * F-PCA-005: GDPR Art. 5(2) accountability — the notice is informational
     * only; it does not block the export. Merchants who want to suppress it
     * outright can filter `tejcart_show_pii_export_notice` to `false`.
     *
     * The notice is dismissible per-user: clicking Dismiss persists a flag in
     * user meta (mirroring the permalink / object-cache notices in
     * {@see \TejCart\Admin\Admin}) so an acknowledged notice stays hidden
     * instead of reappearing on the next page load.
     *
     * @return void
     */
    public function maybe_render_pii_export_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, array( 'tejcart-orders', 'tejcart-customers' ), true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 && get_user_meta( $user_id, self::PII_EXPORT_NOTICE_META, true ) ) {
            return;
        }

        /**
         * Filter whether the PII-export informational notice is shown on
         * the orders and customers list pages.
         *
         * @since 1.x.0
         * @param bool $show Whether to display the notice. Default true.
         */
        if ( ! apply_filters( 'tejcart_show_pii_export_notice', true ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            admin_url( 'admin.php?tejcart_dismiss_notice=pii_export' ),
            'tejcart_dismiss_pii_export_notice'
        );
        ?>
        <div class="notice notice-info is-dismissible tejcart-pii-export-notice">
            <p>
                <strong><?php esc_html_e( 'Personal data notice:', 'tejcart' ); ?></strong>
                <?php esc_html_e( 'The CSV export on this page includes personally identifiable information (PII) such as customer email addresses, billing/shipping addresses, and IP addresses. Handle exported files in accordance with your privacy policy and GDPR obligations. Use the tejcart_order_export_columns filter to exclude PII columns if needed.', 'tejcart' ); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'tejcart' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Persist a PII-export-notice dismissal against the current user.
     *
     * Mirrors the permalink / no-object-cache dismiss flow in
     * {@see \TejCart\Admin\Admin}: fires on admin_init so the redirect lands
     * before admin_notices renders. WordPress core's `is-dismissible` only
     * hides the notice client-side for the current page view, so without this
     * persistence the notice would reappear on the next load.
     *
     * @return void
     */
    public function handle_pii_export_notice_dismiss(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['tejcart_dismiss_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['tejcart_dismiss_notice'] ) ) : '';
        if ( 'pii_export' !== $action ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_dismiss_pii_export_notice' ) ) {
            return;
        }

        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 ) {
            update_user_meta( $user_id, self::PII_EXPORT_NOTICE_META, 1 );
        }

        $referer = wp_get_referer();
        wp_safe_redirect( ( is_string( $referer ) && '' !== $referer ) ? $referer : admin_url() );
        exit;
    }

    /**
     * Permission + nonce gate shared by both exports.
     *
     * @param string $nonce_action Nonce action name.
     * @return void
     */
    private function gate( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export data.', 'tejcart' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
            wp_die( esc_html__( 'Security check failed.', 'tejcart' ) );
        }
    }

    /**
     * Stream a CSV download with the given filename and rows.
     *
     * Cells are passed through {@see self::sanitize_csv_value()} so that
     * user-supplied data (customer names, notes, addresses, etc.) cannot be
     * interpreted as a formula when the file is opened in Excel / Sheets.
     *
     * @param string $filename Download filename.
     * @param array  $headers  Header row.
     * @param array  $rows     Data rows (array of arrays).
     * @return void
     */
    private function stream_csv( $filename, $headers, $rows ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, $headers );
        foreach ( $rows as $row ) {
            fputcsv( $out, array_map( array( $this, 'sanitize_csv_value' ), $row ) );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Prefix formula-injection characters so spreadsheet apps treat the
     * value as a literal string rather than a formula.
     *
     * @param mixed $value Raw cell value.
     * @return string
     */
    private function sanitize_csv_value( $value ): string {
        $value = (string) $value;
        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }

    /**
     * Export all orders to CSV.
     *
     * F-PCA-005: The default column list includes PII fields (`ip_address`,
     * `billing_address`, `shipping_address`). A `tejcart_order_export_columns`
     * filter lets merchants or compliance tools strip those columns before the
     * CSV is streamed. A `tejcart_pii_export` action is fired so audit-logging
     * plugins can record the download event for GDPR accountability (Art. 5(2)).
     *
     * @return void
     */
    public function export_orders() {
        $this->gate( 'tejcart_export_orders' );

        /**
         * Filter the list of columns included in the orders CSV export.
         *
         * Remove PII columns (e.g. 'ip_address', 'billing_address',
         * 'shipping_address') here to produce a compliance-safe export.
         *
         * @since 1.x.0
         *
         * @param string[] $columns Ordered list of database column names.
         */
        $headers = (array) apply_filters(
            'tejcart_order_export_columns',
            array(
                'id', 'order_number', 'order_key', 'status', 'currency',
                'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'total',
                'payment_method', 'transaction_id', 'coupon_code',
                'customer_id', 'customer_email', 'customer_name',
                'billing_address', 'shipping_address', 'customer_note',
                'ip_address', 'created_at', 'updated_at',
            )
        );

        /**
         * Fires when a PII-bearing data export is triggered.
         *
         * Audit-logging plugins should hook here to record the event,
         * the exporting user, and a timestamp for GDPR Art. 5(2) accountability.
         *
         * @since 1.x.0
         *
         * @param string $export_type  Identifier for the export type.
         * @param int    $user_id      WP user ID of the person triggering the export.
         */
        do_action( 'tejcart_pii_export', 'order_csv', get_current_user_id() );

        $this->stream_table_export(
            'tejcart_orders',
            'tejcart-orders-' . gmdate( 'Y-m-d' ) . '.csv',
            $headers
        );
    }

    /**
     * Export all customers to CSV.
     *
     * @return void
     */
    public function export_customers() {
        $this->gate( 'tejcart_export_customers' );

        $headers = array(
            'id', 'user_id', 'email', 'first_name', 'last_name',
            'billing_address', 'shipping_address', 'created_at', 'updated_at',
        );

        $this->stream_table_export(
            'tejcart_customers',
            'tejcart-customers-' . gmdate( 'Y-m-d' ) . '.csv',
            $headers
        );
    }

    /**
     * Stream a paginated CSV export from a single TejCart table.
     *
     * Cursor-paginates by primary key in $batch_size chunks (default 1000),
     * flushes each batch to the client, and never loads more than one batch
     * into PHP memory at a time. At 100k+ rows the previous "SELECT *"
     * would peak past the default 256MB PHP memory limit; this approach is
     * O(batch_size) regardless of table size.
     *
     * @param string   $table_suffix Table name without the wpdb prefix.
     * @param string   $filename     Download filename.
     * @param string[] $headers      Column order (and CSV header row).
     */
    private function stream_table_export( string $table_suffix, string $filename, array $headers ): void {
        global $wpdb;

        $table = $wpdb->prefix . $table_suffix;

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, $headers );

        $batch_size = (int) apply_filters( 'tejcart_export_batch_size', 1000 );
        if ( $batch_size <= 0 ) {
            $batch_size = 1000;
        }

        $last_id = 0;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $batch = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                    $last_id,
                    $batch_size
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! is_array( $batch ) || empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $r ) {
                $row = array();
                foreach ( $headers as $col ) {
                    $row[] = isset( $r[ $col ] ) ? (string) $r[ $col ] : '';
                }
                fputcsv( $out, array_map( array( $this, 'sanitize_csv_value' ), $row ) );
                $last_id = (int) $r['id'];
            }

            if ( function_exists( 'flush' ) ) {
                flush();
            }
        } while ( count( $batch ) === $batch_size );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Build the URL for the orders export endpoint.
     *
     * @return string
     */
    public static function orders_export_url() {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=tejcart_export_orders' ),
            'tejcart_export_orders'
        );
    }

    /**
     * Build the URL for the customers export endpoint.
     *
     * @return string
     */
    public static function customers_export_url() {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=tejcart_export_customers' ),
            'tejcart_export_customers'
        );
    }
}
