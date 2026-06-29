<?php
/**
 * Email administration UI.
 *
 * Owns two operator surfaces that previously did not exist:
 *
 *   1. F-M6 — "Send test" button per registered email so merchants can
 *      verify SMTP / transactional delivery before placing a real order.
 *   2. F-M7 — Email log viewer rendering the `wp_tejcart_email_log`
 *      table that the Tier-2 Template_System has been populating since
 *      day one. Paginated, filterable by email_id, status, recipient,
 *      and sent_at range.
 *
 * Both surfaces are gated behind `manage_options`. AJAX endpoints are
 * nonced via the existing `tejcart_nonce` action used elsewhere in the
 * admin.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Email_Admin_Page {
    /**
     * Action key used for the test-send AJAX nonce.
     */
    public const NONCE_ACTION = 'tejcart_email_admin';

    /**
     * Default page size for the log viewer.
     */
    private const LOG_PAGE_SIZE = 25;

    /**
     * Register the AJAX handler.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_ajax_tejcart_email_send_test', array( $this, 'ajax_send_test' ) );
    }

    /**
     * Render the management UI inline below the Emails settings tab form.
     *
     * @return void
     */
    public function render_inline(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $manager = function_exists( 'tejcart' ) ? tejcart()->emails() : null;
        if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_emails' ) ) {
            return;
        }

        $emails           = $manager->get_emails();
        $current_user     = wp_get_current_user();
        $default_recipient = is_object( $current_user ) && ! empty( $current_user->user_email )
            ? (string) $current_user->user_email
            : (string) get_option( 'admin_email' );
        $log_table_exists = $this->log_table_exists();
        ?>
        <div class="tejcart-card" style="margin-top:24px;">
            <div class="tejcart-card-header">
                <h3><?php esc_html_e( 'Send test email', 'tejcart' ); ?></h3>
            </div>
            <div class="tejcart-card-body" style="padding:16px;">
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: email address */
                        esc_html__( 'Sends a sample of each transactional email to %s using the most recent paid order (or a stub when no order exists). Use this to validate SMTP and template overrides before a real customer order is placed.', 'tejcart' ),
                        '<code>' . esc_html( $default_recipient ) . '</code>'
                    );
                    ?>
                </p>

                <table class="wp-list-table widefat fixed striped" data-tejcart-email-test>
                    <thead>
                        <tr>
                            <th style="width:34%;"><?php esc_html_e( 'Email', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'tejcart' ); ?></th>
                            <th style="width:18%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $emails as $email_id => $email ) :
                            if ( ! is_object( $email ) ) {
                                continue;
                            }
                            $title       = $this->resolve_property( $email, 'title' );
                            $description = $this->resolve_property( $email, 'description' );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $title ?: $email_id ); ?></strong>
                                    <br /><code><?php echo esc_html( $email_id ); ?></code>
                                </td>
                                <td><?php echo esc_html( $description ); ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="button button-secondary"
                                        data-tejcart-email-test-button
                                        data-email-id="<?php echo esc_attr( $email_id ); ?>"
                                    ><?php esc_html_e( 'Send test', 'tejcart' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ( $log_table_exists ) : ?>
            <div class="tejcart-card" style="margin-top:24px;">
                <div class="tejcart-card-header">
                    <h3><?php esc_html_e( 'Email log', 'tejcart' ); ?></h3>
                </div>
                <div class="tejcart-card-body" style="padding:16px;">
                    <?php $this->render_log_table(); ?>
                </div>
            </div>
        <?php endif; ?>

        <script>
        (function () {
            var buttons = document.querySelectorAll( '[data-tejcart-email-test-button]' );
            if ( ! buttons.length ) { return; }
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?>;
            buttons.forEach( function ( button ) {
                button.addEventListener( 'click', function () {
                    var emailId = button.getAttribute( 'data-email-id' );
                    button.disabled = true;
                    var originalLabel = button.textContent;
                    button.textContent = <?php echo wp_json_encode( esc_js( __( 'Sending…', 'tejcart' ) ) ); ?>;

                    var data = new URLSearchParams();
                    data.append( 'action', 'tejcart_email_send_test' );
                    data.append( 'email_id', emailId );
                    data.append( '_ajax_nonce', nonce );

                    fetch( ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data,
                    } )
                    .then( function ( response ) { return response.json(); } )
                    .then( function ( payload ) {
                        if ( payload && payload.success ) {
                            button.textContent = <?php echo wp_json_encode( esc_js( __( 'Sent ✓', 'tejcart' ) ) ); ?>;
                        } else {
                            var msg = ( payload && payload.data && payload.data.message ) ? payload.data.message : 'Error';
                            button.textContent = msg;
                        }
                    } )
                    .catch( function () {
                        button.textContent = <?php echo wp_json_encode( esc_js( __( 'Network error', 'tejcart' ) ) ); ?>;
                    } )
                    .finally( function () {
                        setTimeout( function () {
                            button.disabled  = false;
                            button.textContent = originalLabel;
                        }, 3500 );
                    } );
                } );
            } );
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler: send a test email to the current admin user.
     *
     * @return void
     */
    public function ajax_send_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }

        check_ajax_referer( self::NONCE_ACTION );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $email_id = isset( $_POST['email_id'] ) ? sanitize_key( wp_unslash( $_POST['email_id'] ) ) : '';
        if ( '' === $email_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing email id.', 'tejcart' ) ), 400 );
        }

        $manager = function_exists( 'tejcart' ) ? tejcart()->emails() : null;
        if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_emails' ) ) {
            wp_send_json_error( array( 'message' => __( 'Email manager unavailable.', 'tejcart' ) ), 500 );
        }

        $emails = $manager->get_emails();
        if ( ! isset( $emails[ $email_id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown email id.', 'tejcart' ) ), 404 );
        }

        $current_user = wp_get_current_user();
        $recipient    = is_object( $current_user ) && ! empty( $current_user->user_email )
            ? (string) $current_user->user_email
            : (string) get_option( 'admin_email' );

        if ( '' === sanitize_email( $recipient ) ) {
            wp_send_json_error( array( 'message' => __( 'Test recipient address is empty.', 'tejcart' ) ), 400 );
        }

        $args   = $this->build_test_args( $email_id );
        $route  = function ( array $atts ) use ( $recipient ): array {
            $atts['to'] = $recipient;
            return $atts;
        };

        add_filter( 'wp_mail', $route, 99 );
        try {
            if ( 'password_reset' === $email_id ) {
                // Password_Reset::trigger() only hydrates context (the real
                // send happens via core's retrieve_password_message filter),
                // so send_now() would never dispatch. Hydrate then send()
                // explicitly for the test.
                $email = $emails[ $email_id ];
                $context = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
                if ( '' === sanitize_email( (string) ( isset( $context['user'] ) && $context['user'] instanceof \WP_User ? $context['user']->user_email : '' ) ) ) {
                    wp_send_json_error( array( 'message' => __( 'No usable account for the password-reset test.', 'tejcart' ) ), 400 );
                }
                $email->trigger( $context );
                $ok = method_exists( $email, 'send' ) ? (bool) $email->send() : false;
            } else {
                $ok = $manager->send_now( $email_id, $args );
            }
        } finally {
            remove_filter( 'wp_mail', $route, 99 );
        }

        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'send_now() returned false. The email may be disabled or its template is missing.', 'tejcart' ) ), 500 );
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %s: recipient email */
                __( 'Test sent to %s.', 'tejcart' ),
                $recipient
            ),
        ) );
    }

    /**
     * Build the trigger() args for a test send of the given email id.
     *
     * Picks the most-recent paid order for order-scoped emails; for
     * stock alerts the most-recent published product; for new_account
     * / password_reset the current user. Falls back to an empty array
     * when no suitable subject can be located.
     *
     * @param string $email_id Registered email identifier.
     * @return array<int, mixed>
     */
    private function build_test_args( string $email_id ): array {
        switch ( $email_id ) {
            case 'buyer_receipt':
            case 'admin_notification':
            case 'order_processing':
            case 'order_completed':
            case 'order_cancelled':
            case 'order_refunded':
            case 'order_on_hold':
            case 'order_failed_payment':
            case 'customer_invoice':
                $order_id = $this->latest_paid_order_id();
                if ( $order_id <= 0 ) {
                    return array();
                }
                $order = \TejCart\Order\Order_Factory::get_order( $order_id );
                return $order ? array( $order_id, $order ) : array();

            case 'customer_note':
                $order_id = $this->latest_paid_order_id();
                if ( $order_id <= 0 ) {
                    return array();
                }
                $order = \TejCart\Order\Order_Factory::get_order( $order_id );
                return $order ? array( $order_id, __( 'This is a test customer note.', 'tejcart' ), $order ) : array();

            case 'low_stock_alert':
            case 'out_of_stock':
                $product_id = $this->latest_product_id();
                return $product_id > 0 ? array( $product_id ) : array();

            case 'back_in_stock':
                $product_id = $this->latest_product_id();
                if ( $product_id <= 0 ) {
                    return array();
                }
                $current = wp_get_current_user();
                $email   = is_object( $current ) && ! empty( $current->user_email )
                    ? (string) $current->user_email
                    : (string) get_option( 'admin_email' );
                return array( $product_id, $email, home_url( '/?tejcart_unsubscribe_stock=1&token=test' ) );

            case 'new_account':
                $current = wp_get_current_user();
                return is_object( $current ) ? array( $current->ID, $current ) : array();

            case 'password_reset':
                // Password_Reset::trigger() takes a single context array
                // (not positional id/user args), so return one array
                // element that is itself the context. ajax_send_test()
                // dispatches this case directly via trigger() + send().
                $current = wp_get_current_user();
                if ( ! is_object( $current ) || 0 === (int) $current->ID ) {
                    return array();
                }
                $login = (string) $current->user_login;
                return array(
                    array(
                        'user'  => $current,
                        'key'   => 'test-reset-key',
                        'login' => $login,
                        'url'   => network_site_url(
                            'wp-login.php?action=rp&key=test-reset-key&login=' . rawurlencode( $login ),
                            'login'
                        ),
                    ),
                );
        }

        return array();
    }

    /**
     * Render the email log table with optional filters and pagination.
     *
     * @return void
     */
    private function render_log_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_email_log';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filter_email_id  = isset( $_GET['email_id'] ) ? sanitize_key( wp_unslash( $_GET['email_id'] ) ) : '';
        $filter_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $filter_recipient = isset( $_GET['recipient'] ) ? sanitize_email( wp_unslash( $_GET['recipient'] ) ) : '';
        $page             = isset( $_GET['log_page'] ) ? max( 1, (int) $_GET['log_page'] ) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $where  = '1=1';
        $params = array();
        if ( '' !== $filter_email_id ) {
            $where    .= ' AND email_id = %s';
            $params[]  = $filter_email_id;
        }
        if ( '' !== $filter_status ) {
            $where    .= ' AND status = %s';
            $params[]  = $filter_status;
        }
        if ( '' !== $filter_recipient ) {
            $where    .= ' AND recipient = %s';
            $params[]  = $filter_recipient;
        }

        $offset = ( $page - 1 ) * self::LOG_PAGE_SIZE;

        // Build each statement fully — placeholders for every value — and run
        // a single $wpdb->prepare() over the finished string. The previous
        // count query branched on an empty filter set and, in that branch,
        // executed a directly-interpolated string. $where is built purely from
        // internal literal fragments today, so it was safe, but the
        // build-then-prepare-once idiom removes the latent footgun if a future
        // edit ever interpolates a value into $where instead of $params.
        // The count statement always carries a `id IS NOT NULL` clause with a
        // bound %d so there is always at least one placeholder to prepare,
        // avoiding a no-placeholder prepare() notice.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count_params   = $params;
        $count_params[] = 0;
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where} AND id > %d",
                ...$count_params
            )
        );

        $list_params   = $params;
        $list_params[] = self::LOG_PAGE_SIZE;
        $list_params[] = $offset;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, email_id, recipient, subject, object_type, object_id, status, error, sent_at "
                . "FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                ...$list_params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $base_url = remove_query_arg( array( 'log_page' ) );
        ?>
        <form method="get" class="tejcart-email-log-filters" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="tejcart-settings" />
            <input type="hidden" name="tab" value="emails" />
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Email id', 'tejcart' ); ?></span>
                <input type="text" name="email_id" value="<?php echo esc_attr( $filter_email_id ); ?>" placeholder="<?php esc_attr_e( 'Email id', 'tejcart' ); ?>" />
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Status', 'tejcart' ); ?></span>
                <select name="status">
                    <option value=""><?php esc_html_e( 'All statuses', 'tejcart' ); ?></option>
                    <option value="sent" <?php selected( $filter_status, 'sent' ); ?>><?php esc_html_e( 'Sent', 'tejcart' ); ?></option>
                    <option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'tejcart' ); ?></option>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Recipient', 'tejcart' ); ?></span>
                <input type="email" name="recipient" value="<?php echo esc_attr( $filter_recipient ); ?>" placeholder="<?php esc_attr_e( 'recipient@example.com', 'tejcart' ); ?>" />
            </label>
            <?php submit_button( __( 'Filter', 'tejcart' ), 'secondary', '', false ); ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:20%;"><?php esc_html_e( 'Email', 'tejcart' ); ?></th>
                    <th style="width:24%;"><?php esc_html_e( 'Recipient', 'tejcart' ); ?></th>
                    <th><?php esc_html_e( 'Subject', 'tejcart' ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                    <th style="width:14%;"><?php esc_html_e( 'Sent', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="5"><em><?php esc_html_e( 'No log entries match the current filters.', 'tejcart' ); ?></em></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $object_label = '';
                        if ( ! empty( $row['object_type'] ) && ! empty( $row['object_id'] ) ) {
                            $object_label = sprintf( '%s#%d', (string) $row['object_type'], (int) $row['object_id'] );
                        }
                        ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html( (string) $row['email_id'] ); ?></code>
                                <?php if ( $object_label ) : ?>
                                    <br /><small><?php echo esc_html( $object_label ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( (string) $row['recipient'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['subject'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['status'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['sent_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = (int) ceil( $total / self::LOG_PAGE_SIZE );
        if ( $total_pages > 1 ) :
            ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %s: number of items */
                            esc_html( _n( '%s item', '%s items', $total, 'tejcart' ) ),
                            esc_html( number_format_i18n( $total ) )
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        $links = paginate_links( array(
                            'base'      => add_query_arg( 'log_page', '%#%', $base_url ),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'type'      => 'plain',
                        ) );
                        if ( $links ) {
                            // wp_kses_post strips form/script but keeps a-tags.
                            echo wp_kses_post( $links );
                        }
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Look up the most recent paid order for use as a test subject.
     *
     * @return int Order id, or 0 when none exist.
     */
    private function latest_paid_order_id(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status IN ( %s, %s, %s ) ORDER BY id DESC LIMIT 1",
                'completed',
                'processing',
                'refunded'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $id;
    }

    /**
     * Look up the most recent product id for use as a test subject.
     *
     * @return int Product id, or 0 when none exist.
     */
    private function latest_product_id(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $id = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $id;
    }

    /**
     * Whether the Tier-2 email log table exists. Avoids fatals when
     * Tier-2 is disabled (the log surface is opt-in).
     *
     * @return bool
     */
    private function log_table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_email_log';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (bool) $exists;
    }

    /**
     * Read a protected property off an email instance via reflection.
     * The Abstract_Email properties (`title`, `description`, …) are
     * protected; we cannot rely on them being public on every subclass.
     *
     * @param object $email    Email instance.
     * @param string $property Property name.
     * @return string
     */
    private function resolve_property( $email, string $property ): string {
        try {
            $ref = new \ReflectionClass( $email );
            if ( ! $ref->hasProperty( $property ) ) {
                return '';
            }
            $prop = $ref->getProperty( $property );
            // ReflectionProperty reads protected members directly since PHP 8.1;
            // setAccessible() is a no-op there and is deprecated as of PHP 8.5.
            $value = $prop->getValue( $email );
            return is_scalar( $value ) ? (string) $value : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}
