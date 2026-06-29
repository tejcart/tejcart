<?php
/**
 * Secure Download Manager.
 *
 * Generates signed, time-limited, IP-locked download URLs and
 * serves files securely after validating tokens.
 *
 * @package TejCart\Download
 */

declare( strict_types=1 );

namespace TejCart\Download;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles secure digital product downloads.
 */
class Download_Manager {
    /**
     * Register the download handler on init.
     *
     * @return void
     */
    public function init() {
        add_action( 'init', array( $this, 'process_download' ) );
    }

    /**
     * Generate a signed download URL.
     *
     * @param int $order_id   The order ID.
     * @param int $product_id The product ID.
     * @param int $file_index The file index (default 0).
     * @return string The signed download URL.
     */
    public function generate_download_url( $order_id, $product_id, $file_index = 0 ) {
        $expiry_hours = absint( get_option( 'tejcart_download_expiry_hours', 48 ) );
        // DL-3: up to 30 minutes of NON-POSITIVE jitter so issuance times
        // don't form a predictable pattern a log-watcher could exploit.
        // Low: the jitter is clamped to (-30 min, 0] rather than ±15 min so
        // the effective expiry can only ever be at or BEFORE the merchant's
        // configured ceiling — positive jitter previously let a link live
        // up to 15 minutes longer than the configured window.
        $jitter  = wp_rand( -30 * MINUTE_IN_SECONDS, 0 );
        $expires = time() + ( $expiry_hours * HOUR_IN_SECONDS ) + $jitter;

        $token = $this->generate_token( $order_id, $product_id, $file_index, $expires );

        $args = array(
            'tejcart-download' => '1',
            'order_id'         => $order_id,
            'product_id'       => $product_id,
            'file'             => $file_index,
            'token'            => $token,
            'expires'          => $expires,
        );

        return add_query_arg( $args, site_url( '/' ) );
    }

    /**
     * Validate download request parameters.
     *
     * @param array $params Request parameters.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function validate_download( $params ) {
        $order_id   = isset( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
        $product_id = isset( $params['product_id'] ) ? absint( $params['product_id'] ) : 0;
        $file_index = isset( $params['file'] ) ? absint( $params['file'] ) : 0;
        $token      = isset( $params['token'] ) ? sanitize_text_field( $params['token'] ) : '';
        $expires    = isset( $params['expires'] ) ? absint( $params['expires'] ) : 0;

        if ( ! $order_id || ! $product_id || empty( $token ) || ! $expires ) {
            return new \WP_Error( 'missing_params', __( 'Invalid download link.', 'tejcart' ) );
        }

        if ( time() > $expires ) {
            return new \WP_Error( 'expired', __( 'This download link has expired.', 'tejcart' ) );
        }

        $expected_token = $this->generate_token( $order_id, $product_id, $file_index, $expires );

        // Managed-secret migration grace: links generated before the
        // switch to Key_Manager were signed with wp_salt('auth'); keep
        // accepting them so URLs already delivered in order emails work.
        // (When no managed secret resolves this equals $expected_token.)
        $legacy_salt  = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        $legacy_token = ( '' !== $legacy_salt )
            ? $this->generate_token( $order_id, $product_id, $file_index, $expires, $legacy_salt )
            : '';

        // DL-1: salt-rotation grace. If a 'tejcart_download_previous_salt'
        // option is set (operator-staged during a salt rotation), also
        // accept tokens signed with that key. All comparisons use
        // hash_equals so timing leaks are impossible. Clear the option
        // after the grace period to retire the previous salt.
        $previous_salt = (string) get_option( 'tejcart_download_previous_salt', '' );
        $previous_token = '';
        if ( '' !== $previous_salt ) {
            $previous_token = $this->generate_token( $order_id, $product_id, $file_index, $expires, $previous_salt );
        }

        $token_ok = hash_equals( $expected_token, $token )
            || ( '' !== $legacy_token && hash_equals( $legacy_token, $token ) )
            || ( '' !== $previous_token && hash_equals( $previous_token, $token ) );

        if ( ! $token_ok ) {
            return new \WP_Error( 'invalid_token', __( 'Invalid download token.', 'tejcart' ) );
        }

        // Audit H-40: default changed from 'yes' to 'no'. Mobile
        // carriers, VPNs, and wi-fi/cellular handoffs change the
        // public IP between checkout and the email click, breaking
        // the download for a large fraction of legitimate buyers.
        // The token already binds order_id + product_id + file_index
        // + expires + order_key + customer_id — sufficient without IP.
        $ip_lock = get_option( 'tejcart_download_ip_lock', 'no' );

        if ( 'yes' === $ip_lock ) {
            $order       = tejcart_get_order( $order_id );
            $order_ip    = $order ? (string) $order->get_ip_address() : '';
            $current_ip  = \TejCart\Security\Rate_Limiter::get_client_ip();

            if ( '' === $order_ip ) {
                return new \WP_Error(
                    'ip_lock_no_origin',
                    __( 'This download link is locked to the IP that placed the order, and that origin could not be verified. Please contact the store.', 'tejcart' )
                );
            }

            if ( $current_ip !== $order_ip ) {
                return new \WP_Error( 'ip_mismatch', __( 'This download link is not valid from your current location.', 'tejcart' ) );
            }
        }

        $order        = isset( $order ) ? $order : tejcart_get_order( $order_id );
        $order_status = $order ? $order->get_status() : '';

        $allowed_statuses = array( 'completed', 'processing' );

        if ( ! in_array( $order_status, $allowed_statuses, true ) ) {
            return new \WP_Error( 'invalid_order_status', __( 'This order is not eligible for downloads.', 'tejcart' ) );
        }

        // Low: a fully-refunded line item must not keep serving downloads.
        // Sum the refunded quantity for each of this product's order lines
        // and compare against the line quantity — if every unit of the
        // product has been refunded, refuse access. (A partial refund still
        // leaves the download available, matching the order staying in a
        // download-eligible status.)
        if ( $order && $this->is_line_fully_refunded( $order, $product_id ) ) {
            return new \WP_Error( 'line_refunded', __( 'This item has been refunded and is no longer available for download.', 'tejcart' ) );
        }

        // F-PCA-007: The redundant non-atomic limit check that previously lived
        // here has been removed. The sole enforcement point is now
        // atomic_reserve_download_slot() which reads the count and increments it
        // inside a SELECT ... FOR UPDATE transaction, making it race-safe. A
        // pre-check here created a TOCTOU window where N concurrent requests
        // could all pass the read-only check and then contend at the lock, with
        // the extras receiving a confusing error code from the atomic gate rather
        // than a clean "limit reached" message. The atomic gate returns a
        // WP_Error('download_limit', ...) with a user-facing message already;
        // there is no user experience difference and the code path is simpler.

        return true;
    }

    /**
     * Determine whether every unit of a product on an order has been
     * refunded.
     *
     * Sums the refunded quantity recorded against each of the product's
     * order-item lines (across all refund rows) and compares it to the
     * line's ordered quantity. Returns true only when at least one line
     * exists for the product and all of them are fully refunded.
     *
     * @param \TejCart\Order\Order $order      Order instance.
     * @param int                  $product_id Product (or variation) ID.
     * @return bool
     */
    private function is_line_fully_refunded( $order, int $product_id ): bool {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || $product_id <= 0 ) {
            return false;
        }

        // Map this product's order_item_id => ordered quantity.
        $line_qty = array();
        foreach ( $order->get_items() as $item ) {
            if ( (int) ( $item->product_id ?? 0 ) !== $product_id ) {
                continue;
            }
            $item_id = (int) ( $item->id ?? 0 );
            if ( $item_id > 0 ) {
                $line_qty[ $item_id ] = (int) ( $item->quantity ?? 0 );
            }
        }

        if ( empty( $line_qty ) ) {
            return false;
        }

        if ( ! class_exists( '\\TejCart\\Order\\Order_Refund' ) ) {
            return false;
        }

        // Sum refunded quantities per order_item_id across all refunds.
        $refunded = array();
        $order_id = (int) ( method_exists( $order, 'get_id' ) ? $order->get_id() : 0 );
        foreach ( \TejCart\Order\Order_Refund::get_refunds( $order_id ) as $refund ) {
            $items = is_object( $refund ) && isset( $refund->items ) ? (array) $refund->items : array();
            foreach ( $items as $line ) {
                $iid = (int) ( $line['order_item_id'] ?? 0 );
                if ( isset( $line_qty[ $iid ] ) ) {
                    $refunded[ $iid ] = ( $refunded[ $iid ] ?? 0 ) + (int) ( $line['quantity'] ?? 0 );
                }
            }
        }

        // Fully refunded only when every matching line is fully covered.
        foreach ( $line_qty as $iid => $qty ) {
            if ( $qty <= 0 ) {
                continue;
            }
            if ( ( $refunded[ $iid ] ?? 0 ) < $qty ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Atomically reserve a download slot.
     *
     * Wraps the count read + increment in a single transaction with a
     * row-level lock on the meta row, so two concurrent downloads can never
     * both pass a limit check and then both increment past the cap.
     *
     * @param int $order_id
     * @param int $product_id
     * @param int $file_index
     * @param int $limit      0 = unlimited.
     * @return true|\WP_Error True if a slot was reserved; WP_Error if the
     *                        limit has been exhausted.
     */
    private function atomic_reserve_download_slot( $order_id, $product_id, $file_index, $limit ) {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_order_meta';
        $meta_key = '_download_count_' . absint( $product_id ) . '_' . absint( $file_index );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( 'START TRANSACTION' );

        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT meta_id, meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s FOR UPDATE",
                    $order_id,
                    $meta_key
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

            if ( $row ) {
                $current = (int) $row['meta_value'];

                if ( $limit > 0 && $current >= $limit ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error(
                        'download_limit',
                        __( 'You have reached the download limit for this file.', 'tejcart' )
                    );
                }

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                $wpdb->query(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $wpdb->prepare(
                        "UPDATE {$table} SET meta_value = %s WHERE meta_id = %d",
                        (string) ( $current + 1 ),
                        (int) $row['meta_id']
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            } else {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                $wpdb->insert(
                    $table,
                    array(
                        'order_id'   => $order_id,
                        'meta_key'   => $meta_key,
                        'meta_value' => '1',
                    ),
                    array( '%d', '%s', '%s' )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'download_increment_failed', $e->getMessage() );
        }

        return true;
    }

    /**
     * Process a download request via the init hook.
     *
     * Checks for the tejcart-download query var and serves the file if valid.
     *
     * @return void
     */
    public function process_download() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['tejcart-download'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $params = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );

        $validation = $this->validate_download( $params );

        if ( is_wp_error( $validation ) ) {
            wp_die(
                esc_html( $validation->get_error_message() ),
                esc_html__( 'Download Error', 'tejcart' ),
                array( 'response' => 403 )
            );
        }

        $order_id   = absint( $params['order_id'] );
        $product_id = absint( $params['product_id'] );
        $file_index = isset( $params['file'] ) ? absint( $params['file'] ) : 0;

        $downloadable_files = \TejCart\Product\Product_Meta::get( $product_id, '_download_files' );

        if ( empty( $downloadable_files ) ) {
            $downloadable_files = \TejCart\Product\Product_Meta::get( $product_id, '_downloadable_files' );
        }

        if ( is_string( $downloadable_files ) ) {
            $decoded            = json_decode( $downloadable_files, true );
            $downloadable_files = is_array( $decoded ) ? $decoded : array();
        }

        if ( ! is_array( $downloadable_files ) ) {
            $downloadable_files = array();
        }

        $files = array_values( $downloadable_files );

        $file_index = absint( $params['file'] ?? 0 );
        if ( $file_index >= count( $files ) ) {
            wp_die( esc_html__( 'Invalid file.', 'tejcart' ), '', array( 'response' => 403 ) );
        }

        if ( ! isset( $files[ $file_index ] ) ) {
            wp_die(
                esc_html__( 'File not found.', 'tejcart' ),
                esc_html__( 'Download Error', 'tejcart' ),
                array( 'response' => 404 )
            );
        }

        $file      = $files[ $file_index ];
        $file_path = isset( $file['file'] ) ? $file['file'] : '';
        $file_name = isset( $file['name'] ) ? $file['name'] : basename( $file_path );

        if ( empty( $file_path ) || ! is_readable( $file_path ) ) {
            wp_die(
                esc_html__( 'File not found or not readable.', 'tejcart' ),
                esc_html__( 'Download Error', 'tejcart' ),
                array( 'response' => 404 )
            );
        }

        $upload_dir  = wp_upload_dir();
        $real_path   = realpath( $file_path );
        $real_base   = realpath( $upload_dir['basedir'] );
        $base_prefix = ( false !== $real_base ? $real_base : $upload_dir['basedir'] );
        $base_prefix = rtrim( $base_prefix, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( false === $real_path || 0 !== strpos( $real_path . DIRECTORY_SEPARATOR, $base_prefix ) ) {
            wp_die(
                esc_html__( 'Invalid file path.', 'tejcart' ),
                esc_html__( 'Download Error', 'tejcart' ),
                array( 'response' => 403 )
            );
        }

        $file_name = str_replace( array( '"', "\r", "\n" ), '', $file_name );

        $download_limit_meta = \TejCart\Product\Product_Meta::get( $product_id, '_download_limit' );
        $download_limit      = ( '' !== $download_limit_meta && false !== $download_limit_meta )
            ? absint( $download_limit_meta )
            : 0;

        $reservation = $this->atomic_reserve_download_slot( $order_id, $product_id, $file_index, $download_limit );
        if ( is_wp_error( $reservation ) ) {
            wp_die(
                esc_html( $reservation->get_error_message() ),
                esc_html__( 'Download Error', 'tejcart' ),
                array( 'response' => 429 )
            );
        }

        /**
         * Fires after a download has been served.
         *
         * @param int    $order_id   The order ID.
         * @param int    $product_id The product ID.
         * @param int    $file_index The file index.
         * @param string $file_path  The file path served.
         */
        do_action( 'tejcart_download_served', $order_id, $product_id, $file_index, $file_path );

        $this->serve_file( $file_path, $file_name );
    }

    /**
     * Get the number of times a file has been downloaded for a given order, product, and file index.
     *
     * @param int $order_id   The order ID.
     * @param int $product_id The product ID.
     * @param int $file_index The file index (default 0).
     * @return int
     */
    public function get_download_count( $order_id, $product_id, $file_index = 0 ) {
        $meta_key = '_download_count_' . absint( $product_id ) . '_' . absint( $file_index );

        return absint( tejcart_get_order_meta( $order_id, $meta_key ) );
    }

    /**
     * Get the number of remaining downloads, or 'unlimited'.
     *
     * @param int $order_id   The order ID.
     * @param int $product_id The product ID.
     * @param int $file_index The file index (default 0).
     * @return int|string Remaining count or 'unlimited'.
     */
    public function get_remaining_downloads( $order_id, $product_id, $file_index = 0 ) {
        $download_limit = \TejCart\Product\Product_Meta::get( $product_id, '_download_limit' );

        if ( '' === $download_limit || false === $download_limit || null === $download_limit ) {
            return 'unlimited';
        }

        $download_limit = absint( $download_limit );

        if ( 0 === $download_limit ) {
            return 'unlimited';
        }

        $current_count = $this->get_download_count( $order_id, $product_id, $file_index );

        return max( 0, $download_limit - $current_count );
    }

    /**
     * Generate a secure token for download URL signing.
     *
     * The signature binds:
     *   • order_id + product_id + file_index + expires — so the URL is
     *     specific to one file in one order and naturally rotates.
     *   • order_key — rotating the order key revokes outstanding links.
     *   • customer_id (0 for guests) — if the order is later re-assigned
     *     to a different customer, old tokens stop working.
     *
     * @param int $order_id   The order ID.
     * @param int $product_id The product ID.
     * @param int $file_index The file index.
     * @param int $expires    The expiry timestamp.
     * @return string
     */
    private function generate_token( $order_id, $product_id, $file_index, $expires, $salt_override = null ) {
        $order       = tejcart_get_order( $order_id );
        $order_key   = $order ? $order->get_order_key() : '';
        $customer_id = $order && method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
        $data        = implode( '|', array( $order_id, $product_id, $file_index, $expires, $order_key, $customer_id ) );

        $salt = ( null !== $salt_override && '' !== $salt_override ) ? (string) $salt_override : self::token_key();
        return hash_hmac( 'sha256', $data, $salt );
    }

    /**
     * Rotation-stable default HMAC key for download-link signing.
     *
     * Uses the plugin-managed {@see \TejCart\Security\Key_Manager} secret
     * so a WordPress salt reset no longer 403s links already delivered in
     * order emails. Falls back to wp_salt('auth') only when no managed
     * secret can be resolved (very early boot). Links signed under the
     * old wp_salt key keep validating via the legacy fallback in
     * {@see Download_Manager::validate_download()}.
     *
     * @return string
     */
    private static function token_key(): string {
        if ( \TejCart\Security\Key_Manager::is_available() ) {
            return \TejCart\Security\Key_Manager::hmac_key( 'tejcart|download|v2' );
        }
        return function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
    }

    /**
     * Compute the URI to send in `X-Accel-Redirect`, or empty when no
     * mapping has been configured for this host.
     *
     * nginx requires `X-Accel-Redirect` to point at an internal URI
     * (mapped via `internal;` + `alias`/`root` to the on-disk file).
     * Sending an absolute filesystem path is invalid, returns 404, and
     * leaks the path in the error response. We therefore default to
     * not sending the header at all and falling through to PHP's
     * `readfile()`-equivalent loop, which works on any nginx
     * configuration without server-side changes.
     *
     * Merchants who DO want X-Accel-Redirect (high-throughput
     * download stores) can opt in two ways:
     *
     *  1. Define `TEJCART_X_ACCEL_REDIRECT_PREFIX` in wp-config.php
     *     mapping a URI prefix to the wp-content/uploads root, e.g.
     *
     *         define( 'TEJCART_X_ACCEL_REDIRECT_PREFIX', '/protected-uploads' );
     *
     *     and add an `internal; alias` location to the nginx server
     *     block that maps that prefix back to the upload base dir.
     *  2. Hook the `tejcart_download_x_accel_redirect_url` filter to
     *     compute the URI from the absolute path however the host
     *     wants.
     *
     * Public + static so the regression test can exercise the
     * decision matrix without faking the full serve flow.
     *
     * @internal
     * @param string $absolute_path Absolute filesystem path to the file.
     * @return string Internal URI safe to put in the header, or '' to skip.
     */
    public static function maybe_x_accel_redirect_url( string $absolute_path ): string {
        // Filter wins outright when it returns a non-empty string.
        /**
         * Filter the X-Accel-Redirect URI emitted for a download.
         *
         * Return a non-empty string mapped to an `internal;` nginx
         * location to opt in. Default '' means: do not emit the
         * header, stream via PHP instead.
         *
         * @since 1.0.1
         *
         * @param string $url           Override URI (default '').
         * @param string $absolute_path Absolute filesystem path of the file.
         */
        $filtered = (string) apply_filters( 'tejcart_download_x_accel_redirect_url', '', $absolute_path );
        if ( '' !== $filtered ) {
            return $filtered;
        }

        if ( ! defined( 'TEJCART_X_ACCEL_REDIRECT_PREFIX' ) ) {
            return '';
        }

        $prefix = (string) constant( 'TEJCART_X_ACCEL_REDIRECT_PREFIX' );
        if ( '' === $prefix ) {
            return '';
        }

        $upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
        $real_base  = isset( $upload_dir['basedir'] ) ? realpath( (string) $upload_dir['basedir'] ) : false;
        $real_path  = realpath( $absolute_path );

        if ( false === $real_path || false === $real_base ) {
            return '';
        }

        $real_base_with_sep = rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( 0 !== strpos( $real_path . DIRECTORY_SEPARATOR, $real_base_with_sep ) ) {
            // Defence-in-depth: only files inside the upload base
            // get the X-Accel mapping. If serve_file's own base check
            // is ever bypassed (it shouldn't be), this stops us from
            // emitting an internal URI to an arbitrary on-disk path.
            return '';
        }

        $relative = substr( $real_path, strlen( $real_base_with_sep ) );
        $relative = '/' . ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative ), '/' );

        return rtrim( $prefix, '/' ) . $relative;
    }

    /**
     * Serve a file to the browser with proper headers. Uses X-Sendfile /
     * X-Accel-Redirect when configured and falls back to PHP-streamed
     * readfile() otherwise.
     *
     * @param string $file_path Absolute path to the file.
     * @param string $file_name The filename for the Content-Disposition header.
     * @return void
     */
    private function serve_file( $file_path, $file_name ) {
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Defence against header-injection via stored filenames or accelerator
        // paths. CR/LF would let an attacker who controls the product filename
        // (or compromised admin) split the response and inject arbitrary
        // headers. Quote characters in the Content-Disposition value would
        // similarly break out of the filename="..." string.
        $safe_basename = preg_replace( '/[\r\n"\\\\]+/', '', basename( $file_name ) );
        $safe_path     = preg_replace( '/[\r\n]+/', '', $file_path );

        if ( $safe_path !== $file_path ) {
            wp_die( esc_html__( 'File path is invalid.', 'tejcart' ), '', array( 'response' => 500 ) );
        }

        $mime_type = wp_check_filetype( $safe_basename );
        $type      = ! empty( $mime_type['type'] ) ? $mime_type['type'] : 'application/octet-stream';

        nocache_headers();

        header( 'Content-Type: ' . $type );
        header( 'Content-Disposition: attachment; filename="' . $safe_basename . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . filesize( $file_path ) );

        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
            : '';
        $accel_url = self::maybe_x_accel_redirect_url( $safe_path );

        // The default mod_xsendfile detection (`apache_get_modules` +
        // `in_array`) is correct for top-level `LoadModule`
        // configurations, but it silently misses modules loaded
        // inside an `<IfModule>` conditional block — a common Apache
        // posture. There is no reliable way to detect that case at
        // runtime, so the merchant gets two opt-ins:
        //
        //   - The `tejcart_download_use_x_sendfile` filter overrides
        //     the auto-detect altogether (return true to force, false
        //     to disable). This lets Apache configurations that rely
        //     on `<IfModule>` opt in even when the runtime check
        //     returns false.
        //   - The `TEJCART_DOWNLOAD_FORCE_X_SENDFILE` constant offers
        //     the same opt-in via wp-config.php for environments where
        //     a filter is awkward to wire (e.g. multisite).
        //
        // See review finding L-6.
        $auto_detected = function_exists( 'apache_get_modules' )
            && in_array( 'mod_xsendfile', apache_get_modules(), true );

        $force_constant = defined( 'TEJCART_DOWNLOAD_FORCE_X_SENDFILE' )
            && (bool) constant( 'TEJCART_DOWNLOAD_FORCE_X_SENDFILE' );

        /**
         * Filter whether to emit the `X-Sendfile` header for a download.
         *
         * Default is the auto-detected `apache_get_modules` result OR
         * the `TEJCART_DOWNLOAD_FORCE_X_SENDFILE` constant. Returning
         * `false` from the filter forces fall-through to the PHP
         * read loop even when mod_xsendfile is loaded.
         *
         * @since 1.0.1
         *
         * @param bool   $use_x_sendfile Whether to emit the header.
         * @param string $absolute_path  Absolute filesystem path of the file.
         */
        $use_x_sendfile = (bool) apply_filters(
            'tejcart_download_use_x_sendfile',
            $auto_detected || $force_constant,
            $safe_path
        );

        if ( $use_x_sendfile ) {
            header( 'X-Sendfile: ' . $safe_path );
        } elseif ( '' !== $accel_url && '' !== $server_software && stripos( $server_software, 'nginx' ) !== false ) {
            // nginx X-Accel-Redirect requires the value to be a URI
            // mapped by an `internal;` location in the server config —
            // NOT an absolute filesystem path. Emitting a filesystem
            // path against an unconfigured nginx returns 404 or, worse,
            // leaks the path in an error page. We now emit X-Accel-
            // Redirect only when the merchant has explicitly mapped the
            // upload base directory to an internal nginx alias via the
            // TEJCART_X_ACCEL_REDIRECT_PREFIX constant or the
            // `tejcart_download_x_accel_redirect_url` filter. See
            // review finding M-2 and bin/runbooks/x-accel-redirect.md
            // for the recommended nginx snippet.
            header( 'X-Accel-Redirect: ' . $accel_url );
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $handle = fopen( $file_path, 'rb' );
            if ( ! $handle ) {
                wp_die( esc_html__( 'File could not be read.', 'tejcart' ), '', array( 'response' => 500 ) );
            }
            while ( ! feof( $handle ) ) {
                // Streaming raw binary content — escaping would corrupt the payload.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped
                echo fread( $handle, 8192 );
                flush();
                if ( connection_aborted() ) {
                    break;
                }
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $handle );
        }

        exit;
    }
}
