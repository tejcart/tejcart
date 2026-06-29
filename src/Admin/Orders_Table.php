<?php
/**
 * Orders list table for the admin area.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays a paginated, sortable, filterable list of orders
 * using the WordPress WP_List_Table API.
 */
class Orders_Table extends \WP_List_Table {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'order',
            'plural'   => 'orders',
            'ajax'     => false,
        ) );
    }

    /**
     * Define the columns for the table.
     *
     * @return array
     */
    public function get_columns() {
        $columns = array(
            'cb'             => '<input type="checkbox" />',
            'order_number'   => __( 'Order #', 'tejcart' ),
            'customer'       => __( 'Customer', 'tejcart' ),
            'status'         => __( 'Status', 'tejcart' ),
            'total'          => __( 'Total', 'tejcart' ),
            'created_at'     => __( 'Date', 'tejcart' ),
            'payment_method' => __( 'Payment method', 'tejcart' ),
        );

        /**
         * Filter the columns rendered by the TejCart orders list.
         *
         * Sibling plugins use this to add their own columns
         * (e.g. tejcart-order-tracking adds "Tracking"). Implementations
         * are also responsible for emitting the cell content via the
         * `tejcart_orders_table_column_{key}` action.
         *
         * @since 1.x.0
         * @param array<string, string> $columns
         */
        return (array) apply_filters( 'tejcart_orders_table_columns', $columns );
    }

    /**
     * Tell WP_List_Table that the Order # column owns the row actions.
     * Drives native hover-reveal Preview / View / Edit / Invoice links
     * inside column_default()'s order_number branch.
     *
     * @return string
     */
    protected function get_primary_column_name() {
        return 'order_number';
    }

    /**
     * Some WP versions look up the default primary column via the
     * protected method, others at this one; return from both.
     *
     * @return string
     */
    protected function get_default_primary_column_name() {
        return 'order_number';
    }

    /**
     * Define sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'order_number' => array( 'order_number', false ),
            'total'        => array( 'total', false ),
            'created_at'   => array( 'created_at', true ),
            'status'       => array( 'status', false ),
        );
    }

    /**
     * Memoised aggregate counts keyed by order status. Populated on
     * first call to get_status_counts() and reused by get_views() so
     * the chips don't re-query.
     *
     * @var array<string, int>|null
     */
    private $status_counts = null;

    /**
     * Aggregate per-status order counts. One CASE-WHEN query per page.
     *
     * @return array{total:int, pending:int, processing:int, on_hold:int, completed:int, cancelled:int, refunded:int, failed:int}
     */
    public function get_status_counts(): array {
        if ( null !== $this->status_counts ) {
            return $this->status_counts;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_orders';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status = 'on-hold'    THEN 1 ELSE 0 END) AS on_hold,
                SUM(CASE WHEN status = 'completed'  THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN status = 'refunded'   THEN 1 ELSE 0 END) AS refunded,
                SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) AS failed
             FROM {$table}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->status_counts = array(
            'total'      => (int) ( $row['total']      ?? 0 ),
            'pending'    => (int) ( $row['pending']    ?? 0 ),
            'processing' => (int) ( $row['processing'] ?? 0 ),
            'on_hold'    => (int) ( $row['on_hold']    ?? 0 ),
            'completed'  => (int) ( $row['completed']  ?? 0 ),
            'cancelled'  => (int) ( $row['cancelled']  ?? 0 ),
            'refunded'   => (int) ( $row['refunded']   ?? 0 ),
            'failed'     => (int) ( $row['failed']     ?? 0 ),
        );
        return $this->status_counts;
    }

    /**
     * Views chips above the table — All / Pending / Processing /
     * On hold / Completed / Cancelled. Failed + Refunded stay in
     * the status dropdown to keep the chip row tight.
     *
     * Composes with the existing ?order_status=… filter the status
     * dropdown in extra_tablenav() already posts.
     *
     * @return array<string, string>
     */
    protected function get_views() {
        $counts   = $this->get_status_counts();
        $base_url = admin_url( 'admin.php?page=tejcart-orders' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current  = isset( $_REQUEST['order_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_status'] ) ) : '';

        $make = static function ( $label, $count, $is_current, $href ) {
            return sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
                esc_url( $href ),
                $is_current ? 'current' : '',
                esc_html( $label ),
                esc_html( number_format_i18n( (int) $count ) )
            );
        };

        return array(
            'all'        => $make( __( 'All',        'tejcart' ), $counts['total'],      '' === $current,          $base_url ),
            'pending'    => $make( __( 'Pending',    'tejcart' ), $counts['pending'],    'pending' === $current,    add_query_arg( 'order_status', 'pending',    $base_url ) ),
            'processing' => $make( __( 'Processing', 'tejcart' ), $counts['processing'], 'processing' === $current, add_query_arg( 'order_status', 'processing', $base_url ) ),
            'on-hold'    => $make( __( 'On hold',    'tejcart' ), $counts['on_hold'],    'on-hold' === $current,    add_query_arg( 'order_status', 'on-hold',    $base_url ) ),
            'completed'  => $make( __( 'Completed',  'tejcart' ), $counts['completed'],  'completed' === $current,  add_query_arg( 'order_status', 'completed',  $base_url ) ),
            'cancelled'  => $make( __( 'Cancelled',  'tejcart' ), $counts['cancelled'],  'cancelled' === $current,  add_query_arg( 'order_status', 'cancelled',  $base_url ) ),
        );
    }

    /**
     * Re-render the views list without WP_List_Table's literal " |"
     * separators — CSS can't remove those (they're text content).
     *
     * @return void
     */
    public function views() {
        $views = $this->get_views();
        /** This filter is documented in wp-admin/includes/class-wp-list-table.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mirrors WP core filter name.
        $views = apply_filters( "views_{$this->screen->id}", $views );

        if ( empty( $views ) ) {
            return;
        }
        $this->screen->render_screen_reader_content( 'heading_views' );

        echo '<ul class="subsubsub">';
        foreach ( $views as $class => $view ) {
            printf(
                '<li class="%1$s">%2$s</li>',
                esc_attr( (string) $class ),
                $view // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_views() escapes inline.
            );
        }
        echo '</ul>';
    }

    /**
     * Define bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'export_csv'      => __( 'Export to CSV', 'tejcart' ),
            'mark_pending'    => __( 'Mark Pending Payment', 'tejcart' ),
            'mark_processing' => __( 'Mark Processing', 'tejcart' ),
            'mark_on-hold'    => __( 'Mark On Hold', 'tejcart' ),
            'mark_completed'  => __( 'Mark Completed', 'tejcart' ),
            'mark_cancelled'  => __( 'Mark Cancelled', 'tejcart' ),
            'mark_refunded'   => __( 'Mark Refunded', 'tejcart' ),
            'mark_failed'     => __( 'Mark Failed', 'tejcart' ),
            'delete'          => __( 'Delete', 'tejcart' ),

        );
    }

    /**
     * Prepare items for display: query, paginate and sort.
     *
     * @return void
     */
    public function prepare_items() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tejcart_orders';
        $per_page   = 20;
        $paged      = $this->get_pagenum();
        $offset     = ( $paged - 1 ) * $per_page;

        $this->process_bulk_action();

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        /*
         * Build the WHERE clause as ( SQL fragment with %-placeholders, values )
         * so the entire query goes through a single $wpdb->prepare() call.
         */
        $clauses = array( '1=1' );
        $values  = array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset( $_REQUEST['order_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_status'] ) ) : '';
        if ( ! empty( $status_filter ) ) {
            $clauses[] = 'status = %s';
            $values[]  = $status_filter;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        if ( '' !== $search ) {
            // H-2: prefer FULLTEXT MATCH...AGAINST when the
            // ft_customer_search index exists (added by the 1.2.0
            // migration). Falls back to the historical middle-wildcard
            // LIKE for installs where the index couldn't be created
            // (older MariaDB / MyISAM). The order_number lookup stays on
            // LIKE because order numbers don't tokenise well for
            // FULLTEXT — but the index on order_number makes the
            // prefix-match cheap.
            if ( self::has_customer_fulltext_index( $wpdb ) && strlen( $search ) >= 3 ) {
                $like_order_no = $wpdb->esc_like( $search ) . '%';
                // BOOLEAN MODE so partial words match without needing the
                // server-wide ft_min_word_len reconfigure that NATURAL
                // LANGUAGE mode requires.
                $boolean_term  = '+' . preg_replace( '/[+\-<>()~*"@]+/', ' ', $search ) . '*';
                $clauses[]     = '(order_number LIKE %s OR MATCH(customer_name, customer_email) AGAINST (%s IN BOOLEAN MODE))';
                $values[]      = $like_order_no;
                $values[]      = $boolean_term;
            } else {
                $like      = '%' . $wpdb->esc_like( $search ) . '%';
                $clauses[] = '(order_number LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s)';
                $values[]  = $like;
                $values[]  = $like;
                $values[]  = $like;
            }
        }

        // Map the requested sort column through a fixed allowlist so the value
        // interpolated into ORDER BY is always one of these hardcoded column
        // names — never request input, even after sanitisation.
        $orderby_columns = array(
            'order_number' => 'order_number',
            'total'        => 'total',
            'created_at'   => 'created_at',
            'status'       => 'status',
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby_req = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
        $orderby     = $orderby_columns[ $orderby_req ] ?? 'created_at';

        // $order is resolved to one of two literal keywords.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_req = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
        $order     = ( 'ASC' === $order_req ) ? 'ASC' : 'DESC';

        $where_sql = implode( ' AND ', $clauses );

        // $orderby and $order were validated against fixed allowlists above.
        // $where_sql is built from fixed clause strings only; values are bound via $wpdb->prepare().
        if ( empty( $values ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}", $values );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_items = (int) $wpdb->get_var( $count_sql );

        $list_values   = $values;
        $list_values[] = $per_page;
        $list_values[] = $offset;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $list_values
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * Render the checkbox column.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="order_ids[]" value="%d" />', (int) $item['id'] );
    }

    /**
     * Default column rendering.
     *
     * @param array  $item        Row data.
     * @param string $column_name Column key.
     * @return string
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'order_number':
                $id       = (int) $item['id'];
                $view_url = admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $id );
                $title    = sprintf(
                    '<a href="%1$s" class="nxc-order-number">#%2$s</a>',
                    esc_url( $view_url ),
                    esc_html( (string) $item['order_number'] )
                );
                return $title . $this->build_row_actions( $item );

            case 'customer':
                $name  = trim( (string) ( $item['customer_name']  ?? '' ) );
                $email = trim( (string) ( $item['customer_email'] ?? '' ) );
                if ( '' === $name && '' === $email ) {
                    return '<span class="nxc-muted">—</span>';
                }
                $display_name = '' !== $name ? $name : __( '(guest)', 'tejcart' );
                $initial      = function_exists( 'mb_strtoupper' )
                    ? mb_strtoupper( mb_substr( $display_name, 0, 1 ) )
                    : strtoupper( substr( $display_name, 0, 1 ) );
                $avatar = '' !== $email && function_exists( 'get_avatar' )
                    ? get_avatar( $email, 28, '', $display_name, array( 'class' => 'nxc-customer__avatar' ) )
                    : sprintf(
                        '<span class="nxc-customer__avatar nxc-customer__avatar--initial" aria-hidden="true">%s</span>',
                        esc_html( $initial )
                    );
                return sprintf(
                    '<span class="nxc-customer">%s<span class="nxc-customer__text"><span class="nxc-customer__name">%s</span>%s</span></span>',
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns a safe <img> tag; initial branch escapes inline.
                    $avatar,
                    esc_html( $display_name ),
                    '' !== $email
                        ? '<span class="nxc-customer__email">' . esc_html( $email ) . '</span>'
                        : ''
                );

            case 'created_at':
                $ts = isset( $item['created_at'] ) ? strtotime( (string) $item['created_at'] ) : false;
                if ( ! $ts ) {
                    return '<span class="nxc-muted">—</span>';
                }
                $now  = (int) current_time( 'timestamp' );
                $diff = $now - $ts;
                $full = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
                if ( $diff >= 0 && $diff < 7 * DAY_IN_SECONDS ) {
                    /* translators: %s: human-readable time difference */
                    $label = sprintf( __( '%s ago', 'tejcart' ), human_time_diff( $ts, $now ) );
                } else {
                    $label = date_i18n( 'M j, Y', $ts );
                }
                return sprintf(
                    '<time class="nxc-date" datetime="%s" title="%s">%s</time>',
                    esc_attr( gmdate( 'c', $ts ) ),
                    esc_attr( $full ),
                    esc_html( $label )
                );

            case 'payment_method':
                $raw = (string) ( $item['payment_method'] ?? '' );
                if ( '' === $raw ) {
                    return '<span class="nxc-muted">—</span>';
                }
                // Surface the wallet (Google Pay / Apple Pay / Venmo) for PayPal
                // captures instead of the generic "PayPal", matching the order
                // detail page and emails. Only PayPal rows incur the single
                // (object-cached) funding-source meta read.
                $funding = '';
                if ( 'tejcart_paypal' === $raw && ! empty( $item['id'] ) && function_exists( 'tejcart_get_order_meta' ) ) {
                    $funding = strtolower( (string) tejcart_get_order_meta( (int) $item['id'], '_paypal_funding_source' ) );
                }
                $label = self::payment_method_label( $raw, $funding );
                return sprintf(
                    '<span class="nxc-pill nxc-pill--gateway nxc-pill--%s">%s</span>',
                    esc_attr( sanitize_html_class( $raw ) ),
                    esc_html( $label )
                );

            default:
                /**
                 * Allow sibling plugins to render content for custom
                 * columns added via the `tejcart_orders_table_columns`
                 * filter. Returning a non-null value here short-circuits
                 * the default em-dash fallback.
                 *
                 * @since 1.x.0
                 * @param mixed                $value       Default null.
                 * @param string               $column_name
                 * @param array<string, mixed> $item
                 */
                $custom = apply_filters( 'tejcart_orders_table_column_value', null, $column_name, $item );
                if ( null !== $custom ) {
                    return (string) $custom;
                }
                return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
        }
    }

    /**
     * Build the native WP row-actions payload (Preview | View | Edit |
     * Invoice) for a given order. Uses WP_List_Table::row_actions() so
     * the links get the standard .row-actions wrapper: hover-reveal,
     * keyboard access, mobile toggle — all from core.
     *
     * @param array $item Row data.
     * @return string
     */
    private function build_row_actions( array $item ): string {
        $id          = (int) $item['id'];
        $view_url    = admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . $id );
        $edit_url    = admin_url( 'admin.php?page=tejcart-orders&action=edit&order_id=' . $id );
        $invoice_url = add_query_arg(
            array(
                'tejcart_invoice' => $id,
                'key'             => isset( $item['order_key'] ) ? $item['order_key'] : '',
            ),
            home_url( '/' )
        );

        $actions = array(
            'preview' => sprintf(
                '<a href="#" class="tejcart-order-preview" data-order-id="%1$d" data-nonce="%2$s">%3$s</a>',
                $id,
                esc_attr( wp_create_nonce( 'tejcart_preview_order' ) ),
                esc_html__( 'Preview', 'tejcart' )
            ),
            'view'    => sprintf( '<a href="%s">%s</a>', esc_url( $view_url ), esc_html__( 'View', 'tejcart' ) ),
            'edit'    => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'tejcart' ) ),
            'invoice' => sprintf(
                '<a href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url( $invoice_url ),
                esc_html__( 'Invoice', 'tejcart' )
            ),
        );

        return $this->row_actions( $actions );
    }

    /**
     * Render the status column with a colored badge.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_status( $item ) {
        $status = (string) ( $item['status'] ?? '' );

        $labels = array(
            'pending'    => __( 'Pending payment', 'tejcart' ),
            'processing' => __( 'Processing', 'tejcart' ),
            'on-hold'    => __( 'On hold', 'tejcart' ),
            'completed'  => __( 'Completed', 'tejcart' ),
            'cancelled'  => __( 'Cancelled', 'tejcart' ),
            'refunded'   => __( 'Refunded', 'tejcart' ),
            'failed'     => __( 'Failed', 'tejcart' ),
        );

        $label = $labels[ $status ] ?? ucfirst( str_replace( '-', ' ', $status ) );
        $tone  = array_key_exists( $status, $labels ) ? $status : 'neutral';

        return sprintf(
            '<span class="nxc-status nxc-status--%s"><span class="nxc-status__dot" aria-hidden="true"></span><span class="nxc-status__label">%s</span></span>',
            esc_attr( $tone ),
            esc_html( $label )
        );
    }

    /**
     * Render the total column with formatted price.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_total( $item ) {
        if ( ! isset( $item['total'] ) || '' === (string) $item['total'] ) {
            return '<span class="nxc-muted">—</span>';
        }
        // The `total` column is BIGINT minor units; wrap in Money so the
        // formatter divides by the right per-currency multiplier (USD=100,
        // JPY=1, KWD=1000) instead of rendering raw cents as dollars.
        $currency = isset( $item['currency'] ) ? strtoupper( trim( (string) $item['currency'] ) ) : '';
        if ( '' === $currency || ! \TejCart\Money\Currency::is_valid_shape( $currency ) ) {
            $currency = (string) tejcart_get_currency();
        }
        $money = \TejCart\Money\Money::from_minor_units( (int) $item['total'], $currency );
        return '<span class="nxc-price">' . esc_html( tejcart_price( $money ) ) . '</span>';
    }

    /**
     * Process bulk actions.
     *
     * @return void
     */
    public function process_bulk_action() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = $this->current_action();
        if ( ! $action ) {
            return;
        }

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-orders' ) ) {
            return;
        }

        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_ORDERS ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $order_ids = isset( $_REQUEST['order_ids'] ) ? array_map( 'absint', (array) $_REQUEST['order_ids'] ) : array();

        if ( empty( $order_ids ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'tejcart_orders';

        if ( 0 === strpos( $action, 'mark_' ) ) {
            $new_status = substr( $action, 5 );
            $allowed    = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

            if ( in_array( $new_status, $allowed, true ) ) {
                // Status updates stay per-row on purpose:
                // Order::update_status() fires the status-log write,
                // email triggers, inventory restock, and the
                // tejcart_order_status_changed action — all of which
                // need to fire once per order. A batched
                // `UPDATE ... WHERE id IN (...)` would silently skip
                // those hooks and leave the local order data in a
                // half-applied state. See DB-011 in the audit.
                foreach ( $order_ids as $order_id ) {
                    if ( class_exists( '\\TejCart\\Order\\Order' ) ) {
                        $order = new \TejCart\Order\Order( $order_id );
                        if ( $order->get_id() ) {
                            $order->update_status( $new_status, __( 'Bulk status change.', 'tejcart' ) );
                            continue;
                        }
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(
                        $table_name,
                        array( 'status' => $new_status ),
                        array( 'id' => $order_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
                return;
            }
        }

        switch ( $action ) {
            case 'delete':
                // Batch into a single DELETE — unlike status update,
                // order deletion doesn't fire per-row side-effect hooks
                // (FK ON DELETE CASCADE handles the dependent rows).
                $order_ids_filtered = array_values( array_filter( array_map( 'intval', $order_ids ), static fn( $id ) => $id > 0 ) );
                if ( empty( $order_ids_filtered ) ) {
                    break;
                }
                $placeholders = implode( ',', array_fill( 0, count( $order_ids_filtered ), '%d' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
                    ...$order_ids_filtered
                ) );
                break;
        }
    }

    /**
     * Render extra table navigation (status filter dropdown).
     *
     * @param string $which Top or bottom position.
     * @return void
     */
    public function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        $statuses = array(
            ''           => __( 'All statuses', 'tejcart' ),
            'pending'    => __( 'Pending', 'tejcart' ),
            'processing' => __( 'Processing', 'tejcart' ),
            'on-hold'    => __( 'On hold', 'tejcart' ),
            'completed'  => __( 'Completed', 'tejcart' ),
            'cancelled'  => __( 'Cancelled', 'tejcart' ),
            'refunded'   => __( 'Refunded', 'tejcart' ),
            'failed'     => __( 'Failed', 'tejcart' ),
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_status = isset( $_REQUEST['order_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_status'] ) ) : '';
        ?>
        <div class="alignleft actions nxc-filters">
            <label class="screen-reader-text" for="nxc-filter-order-status"><?php esc_html_e( 'Filter by status', 'tejcart' ); ?></label>
            <select name="order_status" id="nxc-filter-order-status">
                <?php foreach ( $statuses as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_status, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( __( 'Filter', 'tejcart' ), '', 'filter_action', false ); ?>
            <?php if ( '' !== $current_status ) : ?>
                <a class="nxc-filter-clear" href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders' ) ); ?>"><?php esc_html_e( 'Clear', 'tejcart' ); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Override the native search box to inject the SaaS-style
     * placeholder the CSS icon picks up.
     *
     * @param string $text     Submit button text.
     * @param string $input_id Input id base.
     * @return void
     */
    public function search_box( $text, $input_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
            return;
        }
        $input_id = $input_id . '-search-input';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
            <input type="search"
                   id="<?php echo esc_attr( $input_id ); ?>"
                   name="s"
                   value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search by order #, customer, or email…', 'tejcart' ); ?>" />
            <?php submit_button( $text, 'button', '', false, array( 'id' => 'search-submit' ) ); ?>
        </p>
        <?php
    }

    /**
     * Empty-state message. Splits "no orders yet" from "no match for
     * your search / filter" so each variant gets a useful next step.
     *
     * @return void
     */
    public function no_items() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_search   = ! empty( $_REQUEST['s'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_filtered = ! empty( $_REQUEST['order_status'] );
        $clear_url   = admin_url( 'admin.php?page=tejcart-orders' );
        $add_url     = admin_url( 'admin.php?page=tejcart-orders&action=new' );
        ?>
        <div class="nxc-empty-state">
            <svg class="nxc-empty-state__icon" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="8" y="10" width="32" height="30" rx="3"/>
                <path d="M14 18h20M14 24h20M14 30h12"/>
            </svg>
            <?php if ( $is_search || $is_filtered ) : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No orders match your filters', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Try a different keyword or clear the active filter.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear filters', 'tejcart' ); ?></a></p>
            <?php else : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No orders yet', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Orders placed by customers will appear here.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add order', 'tejcart' ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Per-request gateway-title cache to avoid resolving the same slug on
     * every row when a page has 20 orders that share a gateway.
     *
     * @var array<string, string>
     */
    private static $gateway_label_cache = array();

    /**
     * Resolve a payment-method slug to a friendly display label.
     *
     * Mirrors {@see Order::get_payment_method_title()} but doesn't require
     * instantiating an Order per row — the list-table renders the raw DB
     * row, so we only have the slug.
     */
    private static function payment_method_label( string $slug, string $funding = '' ): string {
        // Wallet captures share the `tejcart_paypal` slug but should read as
        // the funding source the buyer used. Keyed separately in the cache so
        // "PayPal" and "Google Pay" rows don't collide.
        $wallet_titles = array(
            'google_pay' => __( 'Google Pay', 'tejcart' ),
            'googlepay'  => __( 'Google Pay', 'tejcart' ),
            'apple_pay'  => __( 'Apple Pay', 'tejcart' ),
            'applepay'   => __( 'Apple Pay', 'tejcart' ),
            'venmo'      => __( 'Venmo', 'tejcart' ),
        );
        if ( 'tejcart_paypal' === $slug && '' !== $funding && isset( $wallet_titles[ $funding ] ) ) {
            return $wallet_titles[ $funding ];
        }

        if ( isset( self::$gateway_label_cache[ $slug ] ) ) {
            return self::$gateway_label_cache[ $slug ];
        }

        $label = '';
        if ( function_exists( 'tejcart' ) ) {
            $gw = tejcart()->gateways()->get_gateway( $slug );
            if ( $gw && method_exists( $gw, 'get_title' ) ) {
                $label = (string) $gw->get_title();
            }
        }

        if ( '' === $label ) {
            $fallbacks = array(
                'paypal'         => __( 'PayPal', 'tejcart' ),
                'tejcart_paypal' => __( 'PayPal', 'tejcart' ),
                'cod'            => __( 'Cash on delivery', 'tejcart' ),
                'bank_transfer'  => __( 'Bank transfer', 'tejcart' ),
                'check'          => __( 'Check', 'tejcart' ),
            );
            if ( isset( $fallbacks[ $slug ] ) ) {
                $label = $fallbacks[ $slug ];
            } else {
                // Drop the "tejcart_" namespace prefix so deactivated sibling
                // gateways still read sensibly (e.g. "tejcart_authorize_net"
                // → "Authorize Net" not "Tejcart Authorize Net").
                $stripped = preg_replace( '/^tejcart[_\s-]+/i', '', $slug );
                $label    = ucwords( str_replace( array( '_', '-' ), ' ', null !== $stripped ? $stripped : $slug ) );
            }
        }

        self::$gateway_label_cache[ $slug ] = $label;
        return $label;
    }

    /**
     * H-2: cached check for the ft_customer_search FULLTEXT index added
     * by the 1.2.0 migration. Cached for 5 min so the admin search route
     * doesn't probe information_schema on every request.
     */
    private static function has_customer_fulltext_index( $wpdb ): bool {
        $cache_key = 'has_ft_customer_search';
        $cached    = wp_cache_get( $cache_key, 'tejcart_micro' );
        if ( false !== $cached ) {
            return (bool) $cached;
        }
        $full = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'ft_customer_search' LIMIT 1",
            DB_NAME,
            $full
        ) );
        $present = ! empty( $exists );
        wp_cache_set( $cache_key, $present ? 1 : 0, 'tejcart_micro', 5 * MINUTE_IN_SECONDS );
        return $present;
    }
}
