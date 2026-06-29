<?php
/**
 * Customers list table for the admin area.
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
 * Displays a paginated, sortable, searchable list of customers using
 * the WordPress WP_List_Table API. Same SaaS-style skin as Products
 * and Orders — only the data layer is customer-specific.
 */
class Customers_Table extends \WP_List_Table {
    /**
     * Memoised customer-id → ['orders' => N, 'spent' => float] map for
     * the rows currently on screen. Populated by prepare_items() in a
     * single GROUP BY query so columns don't N+1.
     *
     * @var array<int, array{orders:int, spent:float}>
     */
    private $totals_map = array();

    /**
     * Whether the RFM columns exist on the customers table.
     *
     * @var bool|null Lazy-initialised in prepare_items().
     */
    private $rfm_available = null;

    public function __construct() {
        parent::__construct( array(
            'singular' => 'customer',
            'plural'   => 'customers',
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
            'cb'          => '<input type="checkbox" />',
            'avatar'      => __( 'Avatar', 'tejcart' ),
            'name'        => __( 'Customer', 'tejcart' ),
            'orders'      => __( 'Orders', 'tejcart' ),
            'total_spent' => __( 'Total spent', 'tejcart' ),
            'ltv'         => __( 'LTV', 'tejcart' ),
            'segment'     => __( 'Segment', 'tejcart' ),
            'created_at'  => __( 'Registered', 'tejcart' ),
        );

        return $columns;
    }

    /**
     * Sortable columns. Order count + total spent live on the orders
     * table so they're not sortable in this version — keeping the
     * query simple.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'name'       => array( 'last_name', false ),
            'ltv'        => array( 'ltv_minor_units', false ),
            'segment'    => array( 'segment', false ),
            'created_at' => array( 'created_at', true ),
        );
    }

    /**
     * Bulk actions — minimal set. Export to CSV is handled on
     * admin_init (download headers), like Products / Orders.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'export_csv' => __( 'Export to CSV', 'tejcart' ),
        );
    }

    /**
     * Tell WP_List_Table the Customer column owns the row actions so
     * native hover-reveal links render under the name.
     */
    protected function get_primary_column_name() { return 'name'; }
    protected function get_default_primary_column_name() { return 'name'; }

    /**
     * Query, paginate, sort, search, and pre-compute order totals for
     * every customer currently on screen.
     *
     * @return void
     */
    public function prepare_items() {
        global $wpdb;

        // Reconcile against orders before reading so the screen reflects
        // any orders created by paths that bypass `tejcart_order_created`
        // (CLI imports, manual SQL, plugin-upgrade gaps). The repository
        // backfill is idempotent — LEFT JOIN-guarded INSERT...SELECT.
        if ( class_exists( \TejCart\Customer\Customer_Repository::class ) ) {
            \TejCart\Customer\Customer_Repository::backfill_from_orders();
        }

        $table    = $wpdb->prefix . 'tejcart_customers';
        $orders   = $wpdb->prefix . 'tejcart_orders';
        $per_page = 20;
        $paged    = max( 1, (int) $this->get_pagenum() );
        $offset   = ( $paged - 1 ) * $per_page;

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        $allowed_orderby = array( 'last_name', 'created_at', 'ltv_minor_units', 'segment' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'created_at';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';

        $where = '1=1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        if ( '' !== $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)',
                $like,
                $like,
                $like
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $segment_filter = isset( $_REQUEST['segment'] ) ? sanitize_key( wp_unslash( $_REQUEST['segment'] ) ) : '';
        if ( '' !== $segment_filter ) {
            $where .= $wpdb->prepare( ' AND segment = %s', $segment_filter );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->totals_map = array();
        if ( ! empty( $this->items ) ) {
            $user_ids_by_cid = array();
            $emails_by_cid   = array();
            foreach ( $this->items as $row ) {
                $cid                     = (int) $row['id'];
                $user_ids_by_cid[ $cid ] = (int) ( $row['user_id'] ?? 0 );
                $emails_by_cid[ $cid ]   = strtolower( (string) ( $row['email'] ?? '' ) );
            }

            $unique_user_ids = array_values( array_unique( array_filter( $user_ids_by_cid ) ) );
            $unique_emails   = array_values( array_unique( array_filter( $emails_by_cid ) ) );

            $conditions = array();
            $params     = array();
            if ( ! empty( $unique_user_ids ) ) {
                $ph           = implode( ',', array_fill( 0, count( $unique_user_ids ), '%d' ) );
                $conditions[] = "customer_id IN ({$ph})";
                $params       = array_merge( $params, $unique_user_ids );
            }
            if ( ! empty( $unique_emails ) ) {
                $ph           = implode( ',', array_fill( 0, count( $unique_emails ), '%s' ) );
                // Audit #14 / 08 — query the column directly (no `LOWER()`
                // wrapper) so the planner can hit `customer_email(191)`.
                // `Order::set_customer_email()` normalises to lowercase at
                // write time so the index lookup matches without needing a
                // functional index.
                $conditions[] = "customer_email IN ({$ph})";
                $params       = array_merge( $params, $unique_emails );
            }

            if ( ! empty( $conditions ) ) {
                $where_orders = implode( ' OR ', $conditions );

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $rows = $wpdb->get_results(
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $wpdb->prepare(
                        // Audit #14 — `customer_email` is stored lowercase
                        // (Order::set_customer_email + migration backfill),
                        // so the GROUP BY no longer needs LOWER(), which
                        // also lets the optimiser use the column index.
                        "SELECT customer_id,
                                customer_email AS email_lc,
                                COUNT(*) AS order_count,
                                COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END), 0) AS total_spent
                         FROM {$orders}
                         WHERE {$where_orders}
                         GROUP BY customer_id, customer_email",
                        ...$params
                    ),
                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    ARRAY_A
                );
                // phpcs:enable

                // The `total_spent` aggregate is SUM(`orders.total`),
                // and `orders.total` is stored as integer minor units
                // (BIGINT cents). Convert to major-unit floats at the
                // DB boundary so the rest of this method — and the
                // column renderer — can treat `spent` as dollars (or
                // whatever the shop's currency requires per ISO 4217).
                // Using shop currency here matches the existing column
                // formatter `tejcart_price()`, which formats in shop
                // currency; mixed-currency stores see an aggregate in
                // shop-currency precision, same as before, just without
                // the 100× inflation.
                $shop_currency = (string) get_option( 'tejcart_currency', 'USD' );
                $by_user_id    = array();
                $by_email      = array();
                foreach ( (array) $rows as $r ) {
                    $uid = (int) $r['customer_id'];
                    $em  = strtolower( (string) ( $r['email_lc'] ?? '' ) );
                    $add = array(
                        'orders' => (int) $r['order_count'],
                        'spent'  => \TejCart\Money\Currency::from_minor_units(
                            (int) $r['total_spent'],
                            $shop_currency
                        ),
                    );
                    if ( $uid > 0 ) {
                        $cur                 = $by_user_id[ $uid ] ?? array( 'orders' => 0, 'spent' => 0.0 );
                        $by_user_id[ $uid ]  = array(
                            'orders' => $cur['orders'] + $add['orders'],
                            'spent'  => $cur['spent'] + $add['spent'],
                        );
                    } elseif ( '' !== $em ) {
                        $cur              = $by_email[ $em ] ?? array( 'orders' => 0, 'spent' => 0.0 );
                        $by_email[ $em ]  = array(
                            'orders' => $cur['orders'] + $add['orders'],
                            'spent'  => $cur['spent'] + $add['spent'],
                        );
                    }
                }

                foreach ( $this->items as $row ) {
                    $cid    = (int) $row['id'];
                    $uid    = $user_ids_by_cid[ $cid ];
                    $em     = $emails_by_cid[ $cid ];
                    $totals = array( 'orders' => 0, 'spent' => 0.0 );

                    if ( $uid > 0 && isset( $by_user_id[ $uid ] ) ) {
                        $totals = $by_user_id[ $uid ];
                    } elseif ( '' !== $em && isset( $by_email[ $em ] ) ) {
                        $totals = $by_email[ $em ];
                    }

                    $this->totals_map[ $cid ] = $totals;
                }
            }
        }

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ) );
    }

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="customer_ids[]" value="%d" />', (int) $item['id'] );
    }

    public function column_avatar( $item ) {
        $email = (string) ( $item['email'] ?? '' );
        if ( '' === $email ) {
            return '<span class="nxc-thumb nxc-thumb--empty" aria-hidden="true">'
                . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                . '<circle cx="12" cy="8" r="4"/>'
                . '<path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>'
                . '</svg>'
                . '</span>';
        }

        $alt = trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ) ?: $email;
        return '<span class="nxc-thumb nxc-thumb--avatar">' . get_avatar( $email, 36, '', $alt, array( 'class' => 'nxc-avatar-img' ) ) . '</span>';
    }

    public function column_name( $item ) {
        $id     = (int) $item['id'];
        $first  = (string) ( $item['first_name'] ?? '' );
        $last   = (string) ( $item['last_name'] ?? '' );
        $name   = trim( $first . ' ' . $last );
        $email  = (string) ( $item['email'] ?? '' );
        $detail = admin_url( 'admin.php?page=tejcart-customers&action=view&id=' . $id );

        if ( '' === $name ) {
            $name = __( '(no name)', 'tejcart' );
        }

        $title = sprintf(
            '<span class="nxc-customer"><a href="%1$s" class="nxc-customer__name">%2$s</a><span class="nxc-customer__email">%3$s</span></span>',
            esc_url( $detail ),
            esc_html( $name ),
            esc_html( $email )
        );

        return $title . $this->build_row_actions( $id, $name, $email );
    }

    /**
     * View / Email — rendered via the native row_actions wrapper so
     * hover-reveal + keyboard access come from WP for free.
     */
    private function build_row_actions( int $id, string $name, string $email ): string {
        $detail = admin_url( 'admin.php?page=tejcart-customers&action=view&id=' . $id );

        $actions = array(
            'view' => sprintf( '<a href="%s">%s</a>', esc_url( $detail ), esc_html__( 'View', 'tejcart' ) ),
        );
        if ( '' !== $email ) {
            $actions['email'] = sprintf(
                '<a href="mailto:%s">%s</a>',
                esc_attr( $email ),
                esc_html__( 'Email', 'tejcart' )
            );
        }
        return $this->row_actions( $actions );
    }

    public function column_orders( $item ) {
        $id    = (int) $item['id'];
        $count = isset( $this->totals_map[ $id ] ) ? (int) $this->totals_map[ $id ]['orders'] : 0;
        if ( 0 === $count ) {
            return '<span class="nxc-muted">0</span>';
        }
        $href = add_query_arg(
            array( 'page' => 'tejcart-orders', 's' => $item['email'] ),
            admin_url( 'admin.php' )
        );
        return sprintf(
            '<a class="nxc-cell-link" href="%s">%s</a>',
            esc_url( $href ),
            esc_html( number_format_i18n( $count ) )
        );
    }

    public function column_total_spent( $item ) {
        $id    = (int) $item['id'];
        $spent = isset( $this->totals_map[ $id ] ) ? (float) $this->totals_map[ $id ]['spent'] : 0.0;
        if ( $spent <= 0 ) {
            return '<span class="nxc-muted">' . esc_html( tejcart_price( 0 ) ) . '</span>';
        }
        return '<span class="nxc-price">' . esc_html( tejcart_price( $spent ) ) . '</span>';
    }

    public function column_ltv( $item ) {
        $ltv_minor = (int) ( $item['ltv_minor_units'] ?? 0 );
        if ( 0 === $ltv_minor ) {
            return '<span class="nxc-muted">' . esc_html( tejcart_price( 0 ) ) . '</span>';
        }

        $shop_currency = (string) get_option( 'tejcart_currency', 'USD' );
        $ltv           = \TejCart\Money\Currency::from_minor_units( $ltv_minor, $shop_currency );
        return '<span class="nxc-price">' . esc_html( tejcart_price( $ltv ) ) . '</span>';
    }

    public function column_segment( $item ) {
        $slug = (string) ( $item['segment'] ?? '' );
        if ( '' === $slug ) {
            return '<span class="nxc-muted">—</span>';
        }

        $def   = \TejCart\Customer\Segment::get_auto_segment( $slug );
        $label = $def ? $def['label'] : ucfirst( $slug );
        $color = $def ? $def['color'] : '#6b7280';

        return sprintf(
            '<span class="nxc-badge" style="--nxc-badge-color:%s">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    /**
     * Render the segment filter dropdown above the table.
     */
    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current = isset( $_REQUEST['segment'] ) ? sanitize_key( wp_unslash( $_REQUEST['segment'] ) ) : '';
        $segments = \TejCart\Customer\Segment::AUTO_SEGMENTS;
        ?>
        <div class="alignleft actions">
            <select name="segment" id="filter-by-segment">
                <option value=""><?php esc_html_e( 'All segments', 'tejcart' ); ?></option>
                <?php foreach ( $segments as $slug => $def ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
                        <?php echo esc_html( $def['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( __( 'Filter', 'tejcart' ), 'button', 'filter_action', false ); ?>
        </div>
        <?php
    }

    public function column_default( $item, $column_name ) {
        if ( 'created_at' === $column_name ) {
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
        }
        return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
    }

    /**
     * SaaS-style search input with the magnifying-glass icon picked
     * up from the shared admin-list.css.
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
                   placeholder="<?php esc_attr_e( 'Search by name or email…', 'tejcart' ); ?>" />
            <?php submit_button( $text, 'button', '', false, array( 'id' => 'search-submit' ) ); ?>
        </p>
        <?php
    }

    /**
     * Empty-state with separate copy for "no customers yet" vs "no
     * match for your search".
     */
    public function no_items() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_search = ! empty( $_REQUEST['s'] );
        $clear     = admin_url( 'admin.php?page=tejcart-customers' );
        ?>
        <div class="nxc-empty-state">
            <svg class="nxc-empty-state__icon" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="24" cy="18" r="7"/>
                <path d="M8 40c2-7 9-12 16-12s14 5 16 12"/>
            </svg>
            <?php if ( $is_search ) : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No customers match your search', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Try a different keyword or clear the search.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn" href="<?php echo esc_url( $clear ); ?>"><?php esc_html_e( 'Clear search', 'tejcart' ); ?></a></p>
            <?php else : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No customers yet', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Customers will appear here after their first checkout.', 'tejcart' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
