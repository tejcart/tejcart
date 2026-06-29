<?php
/**
 * Coupons list table for the admin area.
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
 * Paginated, sortable, searchable coupons list. Shares the SaaS
 * skin Products / Orders / Customers use via the .nxc-list scope.
 */
class Coupons_Table extends \WP_List_Table {
    /**
     * @var array<string, int>|null
     */
    private $status_counts = null;

    public function __construct() {
        parent::__construct( array(
            'singular' => 'coupon',
            'plural'   => 'coupons',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'cb'          => '<input type="checkbox" />',
            'code'        => __( 'Code', 'tejcart' ),
            'type'        => __( 'Type', 'tejcart' ),
            'amount'      => __( 'Amount', 'tejcart' ),
            'usage'       => __( 'Usage', 'tejcart' ),
            'expires_at'  => __( 'Expires', 'tejcart' ),
            'status'      => __( 'Status', 'tejcart' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'code'       => array( 'code', false ),
            'amount'     => array( 'amount', false ),
            'expires_at' => array( 'expires_at', false ),
            'status'     => array( 'status', false ),
        );
    }

    public function get_bulk_actions() {
        return array(
            'export_csv' => __( 'Export to CSV', 'tejcart' ),
            'activate'   => __( 'Mark active', 'tejcart' ),
            'deactivate' => __( 'Mark inactive', 'tejcart' ),
            'delete'     => __( 'Delete', 'tejcart' ),
        );
    }

    protected function get_primary_column_name() { return 'code'; }
    protected function get_default_primary_column_name() { return 'code'; }

    /**
     * Aggregate status counts for the views chips. One CASE-WHEN
     * query, memoised per request. "Expired" is derived live from
     * expires_at so it stays accurate without a cron job or a
     * status-rewrite pass.
     *
     * @return array{total:int, active:int, inactive:int, expired:int}
     */
    public function get_status_counts(): array {
        if ( null !== $this->status_counts ) {
            return $this->status_counts;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_coupons';
        // $table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) AS expired
             FROM {$table}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->status_counts = array(
            'total'    => (int) ( $row['total']    ?? 0 ),
            'active'   => (int) ( $row['active']   ?? 0 ),
            'inactive' => (int) ( $row['inactive'] ?? 0 ),
            'expired'  => (int) ( $row['expired']  ?? 0 ),
        );
        return $this->status_counts;
    }

    /**
     * Views chips: All / Active / Inactive / Expired. Drives the
     * ?coupon_status query arg honoured in prepare_items().
     *
     * @return array<string, string>
     */
    protected function get_views() {
        $counts   = $this->get_status_counts();
        $base_url = admin_url( 'admin.php?page=tejcart-coupons' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current  = isset( $_REQUEST['coupon_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['coupon_status'] ) ) : '';

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
            'all'      => $make( __( 'All',      'tejcart' ), $counts['total'],    '' === $current,          $base_url ),
            'active'   => $make( __( 'Active',   'tejcart' ), $counts['active'],   'active' === $current,    add_query_arg( 'coupon_status', 'active',   $base_url ) ),
            'inactive' => $make( __( 'Inactive', 'tejcart' ), $counts['inactive'], 'inactive' === $current,  add_query_arg( 'coupon_status', 'inactive', $base_url ) ),
            'expired'  => $make( __( 'Expired',  'tejcart' ), $counts['expired'],  'expired' === $current,   add_query_arg( 'coupon_status', 'expired',  $base_url ) ),
        );
    }

    /**
     * Re-render views without WP's literal " |" separators.
     *
     * @return void
     */
    public function views() {
        $views = $this->get_views();
        /** This filter is documented in wp-admin/includes/class-wp-list-table.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $views = apply_filters( "views_{$this->screen->id}", $views );
        if ( empty( $views ) ) { return; }
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
     * Query, paginate, sort, search, filter.
     *
     * @return void
     */
    public function prepare_items() {
        global $wpdb;

        $table    = $wpdb->prefix . 'tejcart_coupons';
        $per_page = 20;
        $paged    = max( 1, (int) $this->get_pagenum() );
        $offset   = ( $paged - 1 ) * $per_page;

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        $allowed_orderby = array( 'code', 'amount', 'expires_at', 'status', 'created_at' );
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
            $where .= $wpdb->prepare( ' AND code LIKE %s', $like );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset( $_REQUEST['coupon_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['coupon_status'] ) ) : '';
        switch ( $status_filter ) {
            case 'active':
                $where .= " AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())";
                break;
            case 'inactive':
                $where .= " AND status = 'inactive'";
                break;
            case 'expired':
                $where .= ' AND expires_at IS NOT NULL AND expires_at <= NOW()';
                break;
        }

        // $table is from $wpdb->prefix; $where is built from constant strings + prepare() fragments.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );

        // $table is from $wpdb->prefix, $where is prepare()-built or constant, $orderby is validated against $allowed_orderby, $order is forced ASC|DESC.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ) );
    }

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="coupon_ids[]" value="%d" />', (int) $item['id'] );
    }

    public function column_code( $item ) {
        $id       = (int) $item['id'];
        $code     = (string) ( $item['code'] ?? '' );
        $edit_url = admin_url( 'admin.php?page=tejcart-coupons&action=edit&coupon_id=' . $id );

        if ( '' === $code ) {
            $code = __( '(no code)', 'tejcart' );
        }

        $title = sprintf(
            '<a href="%1$s" class="nxc-order-number"><code class="nxc-sku">%2$s</code></a>',
            esc_url( $edit_url ),
            esc_html( $code )
        );

        return $title . $this->build_row_actions( $id, $code );
    }

    /**
     * Edit / Delete — native WP row_actions wrapper, so hover-reveal
     * + keyboard access come from core. Delete reuses the shared
     * data-tejcart-confirm modal instead of the onclick confirm().
     *
     * @param int    $id   Coupon ID.
     * @param string $code Coupon code (for the confirm message).
     * @return string
     */
    private function build_row_actions( int $id, string $code ): string {
        $edit_url = admin_url( 'admin.php?page=tejcart-coupons&action=edit&coupon_id=' . $id );
        $del_url  = wp_nonce_url(
            admin_url( 'admin.php?page=tejcart-coupons&action=delete&coupon_id=' . $id ),
            'tejcart_delete_coupon_' . $id
        );

        /* translators: %s: coupon code */
        $confirm = sprintf( __( 'This permanently deletes coupon "%s". Active orders are not affected.', 'tejcart' ), $code );

        $actions = array(
            'edit'         => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'tejcart' ) ),
            'trash delete' => sprintf(
                '<a href="%s" data-tejcart-confirm data-confirm-title="%s" data-confirm-message="%s" data-confirm-button="%s" data-confirm-tone="danger">%s</a>',
                esc_url( $del_url ),
                esc_attr__( 'Delete coupon?', 'tejcart' ),
                esc_attr( $confirm ),
                esc_attr__( 'Delete coupon', 'tejcart' ),
                esc_html__( 'Delete', 'tejcart' )
            ),
        );
        return $this->row_actions( $actions );
    }

    public function column_type( $item ) {
        $type = (string) ( $item['type'] ?? 'fixed' );
        $map  = array(
            'fixed'         => __( 'Fixed', 'tejcart' ),
            'percent'       => __( 'Percent', 'tejcart' ),
            'fixed_cart'    => __( 'Fixed cart', 'tejcart' ),
            'fixed_product' => __( 'Fixed product', 'tejcart' ),
            'free_shipping' => __( 'Free shipping', 'tejcart' ),
        );
        $label = $map[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
        return sprintf(
            '<span class="nxc-pill nxc-pill--%s">%s</span>',
            esc_attr( sanitize_html_class( $type ) ),
            esc_html( $label )
        );
    }

    public function column_amount( $item ) {
        $type   = (string) ( $item['type'] ?? '' );
        $amount = isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;

        if ( 'free_shipping' === $type ) {
            return '<span class="nxc-muted">—</span>';
        }
        if ( 'percent' === $type ) {
            $formatted = rtrim( rtrim( number_format_i18n( $amount, 2 ), '0' ), '.' );
            return '<span class="nxc-price">' . esc_html( $formatted . '%' ) . '</span>';
        }
        return '<span class="nxc-price">' . esc_html( tejcart_price( $amount ) ) . '</span>';
    }

    public function column_usage( $item ) {
        $used  = isset( $item['usage_count'] ) ? (int) $item['usage_count'] : 0;
        $limit = isset( $item['usage_limit'] ) && '' !== $item['usage_limit']
            ? (int) $item['usage_limit']
            : 0;

        $limit_label = $limit > 0 ? (string) $limit : '∞';
        $remaining   = $limit > 0 ? max( 0, $limit - $used ) : null;

        $tone = 'in';
        if ( null !== $remaining ) {
            if ( 0 === $remaining ) {
                $tone = 'out';
            } elseif ( $limit > 0 && $remaining / $limit <= 0.2 ) {
                $tone = 'low';
            }
        }

        $label = number_format_i18n( $used ) . ' / ' . esc_html( $limit_label );
        return sprintf(
            '<span class="nxc-stock nxc-stock--%s"><span class="nxc-stock__dot" aria-hidden="true"></span><span class="nxc-stock__label">%s</span></span>',
            esc_attr( $tone ),
            esc_html( $label )
        );
    }

    /**
     * Parse a stored expires_at wall-clock string to a UTC epoch.
     *
     * C-H3: expires_at is stored as a site-local wall-clock string (the
     * admin form appends ' 23:59:59' to the picked date with no timezone
     * conversion). Interpret it in the site timezone so the list table and
     * Coupon::is_valid() agree on when a coupon expires; both now compare
     * against time() (real UTC seconds).
     *
     * @param string $value Stored wall-clock datetime string.
     * @return int UTC epoch, or 0 when empty/unparseable.
     */
    private function expires_at_to_utc( string $value ): int {
        if ( '' === $value ) {
            return 0;
        }
        try {
            $dt = new \DateTimeImmutable(
                $value,
                function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' )
            );
            return $dt->getTimestamp();
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    public function column_status( $item ) {
        $status     = (string) ( $item['status'] ?? 'active' );
        $expires_at = ! empty( $item['expires_at'] ) ? $this->expires_at_to_utc( (string) $item['expires_at'] ) : 0;
        $now        = time();
        $is_expired = $expires_at > 0 && $expires_at <= $now;

        if ( $is_expired ) {
            $tone  = 'cancelled';
            $label = __( 'Expired', 'tejcart' );
        } elseif ( 'active' === $status ) {
            $tone  = 'completed';
            $label = __( 'Active', 'tejcart' );
        } else {
            $tone  = 'neutral';
            $label = ucfirst( $status );
        }

        return sprintf(
            '<span class="nxc-status nxc-status--%s"><span class="nxc-status__dot" aria-hidden="true"></span><span class="nxc-status__label">%s</span></span>',
            esc_attr( $tone ),
            esc_html( $label )
        );
    }

    public function column_default( $item, $column_name ) {
        if ( 'expires_at' === $column_name ) {
            if ( empty( $item['expires_at'] ) ) {
                return '<span class="nxc-muted">' . esc_html__( 'Never', 'tejcart' ) . '</span>';
            }
            $ts = $this->expires_at_to_utc( (string) $item['expires_at'] );
            if ( ! $ts ) {
                return '<span class="nxc-muted">—</span>';
            }
            $now  = time();
            $diff = $ts - $now;
            $full = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
            if ( $diff > 0 && $diff < 30 * DAY_IN_SECONDS ) {
                /* translators: %s: human-readable time until expiry */
                $label = sprintf( __( 'in %s', 'tejcart' ), human_time_diff( $now, $ts ) );
            } elseif ( $diff < 0 && abs( $diff ) < 30 * DAY_IN_SECONDS ) {
                /* translators: %s: human-readable time since expiry */
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
     * SaaS-style search with the shared magnifying-glass icon.
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
                   placeholder="<?php esc_attr_e( 'Search by coupon code…', 'tejcart' ); ?>" />
            <?php submit_button( $text, 'button', '', false, array( 'id' => 'search-submit' ) ); ?>
        </p>
        <?php
    }

    /**
     * Empty-state card. Splits "no coupons yet" from "no match".
     */
    public function no_items() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_search   = ! empty( $_REQUEST['s'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_filtered = ! empty( $_REQUEST['coupon_status'] );
        $clear       = admin_url( 'admin.php?page=tejcart-coupons' );
        $add         = admin_url( 'admin.php?page=tejcart-coupons&action=add' );
        ?>
        <div class="nxc-empty-state">
            <svg class="nxc-empty-state__icon" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 16h36v6a4 4 0 0 0 0 8v6H6v-6a4 4 0 0 0 0-8v-6z"/>
                <path d="M20 16v24M26 16v24" stroke-dasharray="2 3"/>
            </svg>
            <?php if ( $is_search || $is_filtered ) : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No coupons match your filters', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Try a different keyword or clear the active filter.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn" href="<?php echo esc_url( $clear ); ?>"><?php esc_html_e( 'Clear filters', 'tejcart' ); ?></a></p>
            <?php else : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No coupons yet', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Create a discount code to reward customers at checkout.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add ); ?>"><?php esc_html_e( 'Add coupon', 'tejcart' ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
