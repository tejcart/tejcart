<?php
/**
 * Admin tools — CSV import and bulk-tracking helpers.
 *
 * Renders the "Tools" sub-section inside the Order Tracking Settings tab
 * (TejCart → Settings → Order Tracking → Tools):
 *   - CSV upload form for bulk-importing tracking from a 3PL drop.
 *     Columns: order_number, carrier, tracking_number, [service],
 *     [status]. Header required.
 *
 * The CSV importer reuses the same Tracking_Service::add() that the
 * WP-CLI `wp tejcart tracking import` uses, so behaviour is identical
 * and we don't fork validation logic. Files are streamed line by line
 * to keep memory bounded — a 100k-row CSV doesn't blow up PHP.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Tools {
    /**
     * Legacy slug retained so {@see Settings::maybe_redirect_legacy_menu()}
     * can recognise old bookmarks and forward them to the new sub-section.
     */
    public const PAGE_SLUG = 'tejcart-order-tracking-tools';
    public const NONCE     = 'tejcart_order_tracking_tools';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'admin_post_tejcart_ot_csv_import', array( $this, 'handle_import' ) );
    }

    /**
     * Render the Tools sub-section body. Called from
     * {@see Settings::render_tab()} when `?section=tools` is selected.
     */
    public function render_section(): void {
        if ( ! Capability::current_user_can_manage() ) {
            esc_html_e( 'You do not have permission to access this page.', 'tejcart' );
            return;
        }
        ?>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; already-shown admin notice ?>
        <?php if ( isset( $_GET['imported'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                printf(
                    /* translators: 1: ok count, 2: skipped count, 3: error count */
                    esc_html__( 'Imported %1$d row(s). Skipped %2$d. Errors: %3$d.', 'tejcart' ),
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- display-only admin notice; value cast to int.
                    (int) ( $_GET['imported'] ?? 0 ),
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- display-only admin notice; value cast to int.
                    (int) ( $_GET['skipped'] ?? 0 ),
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- display-only admin notice; value cast to int.
                    (int) ( $_GET['errors'] ?? 0 )
                );
                ?></p>
            </div>
        <?php endif; ?>

        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Bulk import tracking (CSV)', 'tejcart' ); ?></h3>
            </div>
            <div class="tejcart-card-body">
                <p class="description" style="margin-top:0;">
                    <?php esc_html_e( 'Upload a CSV with columns: order_number, carrier, tracking_number, service (optional), status (optional). The first row must be a header. Maximum file size: server upload limit.', 'tejcart' ); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE ); ?>
                    <input type="hidden" name="action" value="tejcart_ot_csv_import" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="tejcart_ot_csv"><?php esc_html_e( 'CSV file', 'tejcart' ); ?></label></th>
                            <td>
                                <input type="file" name="tejcart_ot_csv" id="tejcart_ot_csv" accept=".csv,text/csv" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dry_run"><?php esc_html_e( 'Dry run', 'tejcart' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dry_run" id="dry_run" value="1" />
                                    <?php esc_html_e( 'Validate only — do not insert.', 'tejcart' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Import CSV', 'tejcart' ); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>

        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Sample CSV', 'tejcart' ); ?></h3>
            </div>
            <div class="tejcart-card-body">
                <pre class="tejcart-ot-sample">order_number,carrier,tracking_number,service,status
ORD-1001,usps,9400111202555842761111,Priority Mail,shipped
ORD-1002,fedex,1234567890123456,Ground,shipped
ORD-1003,ups,1Z999AA10123456789,Ground,shipped</pre>
            </div>
        </div>
        <?php
    }

    public function handle_import(): void {
        if ( ! Capability::current_user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden.', 'tejcart' ) );
        }
        check_admin_referer( self::NONCE );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES handled below; tmp_name read as string then validated by import_stream().
        $file = isset( $_FILES['tejcart_ot_csv'] ) && is_array( $_FILES['tejcart_ot_csv'] ) ? $_FILES['tejcart_ot_csv'] : null;
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'imported', 0, Settings::settings_url( 'tools' ) ) );
            exit;
        }

        // Harden against path confusion / local-file disclosure: only ever
        // read a genuine PHP upload. Mirrors core's importer
        // (src/Settings/Settings_Page.php). check_admin_referer + cap are
        // already enforced above.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_uploaded_file() requires the raw tmp path; not used as a value.
        if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'imported', 0, Settings::settings_url( 'tools' ) ) );
            exit;
        }

        $dry_run = ! empty( $_POST['dry_run'] );

        $tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( wp_unslash( (string) $file['tmp_name'] ) ) : '';
        $stats    = $this->import_stream( $tmp_name, $dry_run );

        wp_safe_redirect( add_query_arg(
            array(
                'imported' => $stats['ok'],
                'skipped'  => $stats['skipped'],
                'errors'   => $stats['errors'],
            ),
            Settings::settings_url( 'tools' )
        ) );
        exit;
    }

    /**
     * @return array{ok:int,skipped:int,errors:int}
     */
    public function import_stream( string $path, bool $dry_run ): array {
        $stats = array( 'ok' => 0, 'skipped' => 0, 'errors' => 0 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV streaming requires native file handle
        $h = fopen( $path, 'r' );
        if ( ! is_resource( $h ) ) {
            return $stats;
        }
        $header = fgetcsv( $h );
        if ( ! is_array( $header ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streaming requires native file handle
            fclose( $h );
            return $stats;
        }
        $header = array_map( 'strtolower', array_map( 'trim', $header ) );
        $idx    = array_flip( $header );
        foreach ( array( 'order_number', 'carrier', 'tracking_number' ) as $required ) {
            if ( ! isset( $idx[ $required ] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streaming requires native file handle
                fclose( $h );
                $stats['errors']++;
                return $stats;
            }
        }

        global $wpdb;
        $orders_tbl = $wpdb->prefix . 'tejcart_orders';

        while ( false !== ( $row = fgetcsv( $h ) ) ) {
            if ( null === $row[0] && count( $row ) === 1 ) {
                continue;
            }
            $order_number    = trim( (string) ( $row[ $idx['order_number'] ] ?? '' ) );
            $carrier         = sanitize_key( (string) ( $row[ $idx['carrier'] ] ?? '' ) );
            $tracking_number = trim( (string) ( $row[ $idx['tracking_number'] ] ?? '' ) );
            $service         = isset( $idx['service'] ) ? trim( (string) ( $row[ $idx['service'] ] ?? '' ) ) : '';
            $status          = isset( $idx['status'] )  ? trim( (string) ( $row[ $idx['status'] ]  ?? '' ) ) : Shipment_Status::SHIPPED;

            if ( '' === $order_number || '' === $carrier || '' === $tracking_number ) {
                $stats['skipped']++;
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders_tbl} WHERE order_number = %s LIMIT 1", $order_number ) );
            if ( $order_id <= 0 ) {
                $stats['skipped']++;
                continue;
            }
            if ( $dry_run ) {
                $stats['ok']++;
                continue;
            }
            $r = $this->service->add( $order_id, array(
                'carrier'         => $carrier,
                'tracking_number' => $tracking_number,
                'service'         => $service,
                'status'          => $status,
            ) );
            if ( is_wp_error( $r ) ) {
                $stats['errors']++;
            } else {
                $stats['ok']++;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streaming requires native file handle
        fclose( $h );
        return $stats;
    }
}
