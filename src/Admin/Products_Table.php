<?php
/**
 * Products list table for the admin area.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Product\Product_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays a paginated, sortable list of products
 * using the WordPress WP_List_Table API.
 */
class Products_Table extends \WP_List_Table {
    /**
     * Memoised aggregate counts (total / published / draft / featured /
     * low_stock / out_of_stock). Populated on first call to
     * get_summary_counts() and shared with get_views() so the stats
     * bar and the views bar don't run the count twice per page load.
     *
     * @var array<string, int>|null
     */
    private $summary_counts = null;

    /**
     * Memoised product-ID → category-term list for the current page.
     * Populated lazily on the first column_categories() call with a
     * single JOIN covering all product IDs currently on screen, so
     * each row's categories render without an N+1 query.
     *
     * @var array<int, array<int, array{id:int, name:string}>>|null
     */
    private $categories_map = null;

    /**
     * Memoised product-ID → ['_featured' => string, '_catalog_visibility' => string]
     * for the current page. Populated alongside prepare_items()'s Product_Meta
     * cache prime so get_visibility_badges() never issues a per-row query.
     *
     * @var array<int, array{_featured: string, _catalog_visibility: string}>|null
     */
    private $visibility_map = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'product',
            'plural'   => 'products',
            'ajax'     => false,
        ) );
    }

    /**
     * Define the columns for the table.
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'             => '<input type="checkbox" />',
            'image'          => __( 'Image', 'tejcart' ),
            'name'           => __( 'Name', 'tejcart' ),
            'sku'            => __( 'SKU', 'tejcart' ),
            'categories'     => __( 'Categories', 'tejcart' ),
            'price'          => __( 'Price', 'tejcart' ),
            'stock_quantity' => __( 'Stock', 'tejcart' ),
            'type'           => __( 'Type', 'tejcart' ),
            'created_at'     => __( 'Date', 'tejcart' ),
        );
    }

    /**
     * Tell WP_List_Table that the Name column owns the row actions.
     * This is what drives WP's native hover-to-reveal Edit / Duplicate /
     * View / Delete links inside column_name().
     *
     * @return string
     */
    protected function get_primary_column_name() {
        return 'name';
    }

    /**
     * Same as get_primary_column_name() for the default-primary-column
     * filter path — some WP versions look at the protected method, some
     * at this one.
     *
     * @return string
     */
    protected function get_default_primary_column_name() {
        return 'name';
    }

    /**
     * Define sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'name'           => array( 'name', false ),
            'price'          => array( 'price', false ),
            'created_at'     => array( 'created_at', true ),
            'stock_quantity' => array( 'stock_quantity', false ),
        );
    }

    /**
     * Define bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'duplicate'   => __( 'Duplicate', 'tejcart' ),
            'export_csv'  => __( 'Export to CSV', 'tejcart' ),
            'publish'     => __( 'Set status: Published', 'tejcart' ),
            'draft'       => __( 'Set status: Draft', 'tejcart' ),
            'feature_on'  => __( 'Mark as Featured', 'tejcart' ),
            'feature_off' => __( 'Remove Featured flag', 'tejcart' ),
            'instock'     => __( 'Mark In Stock', 'tejcart' ),
            'outofstock'  => __( 'Mark Out of Stock', 'tejcart' ),
            'delete'      => __( 'Delete', 'tejcart' ),
        );
    }

    /**
     * Transient key that signals the featured column may be out of sync.
     *
     * Set by Abstract_Product::set_featured() (or any caller that writes
     * the `_featured` meta key) and cleared once the heal passes. While
     * the transient is absent the bulk UPDATE is skipped entirely so large
     * catalogs pay no per-page-view cost.
     *
     * @see self::heal_featured_column()
     */
    const FEATURED_DIRTY_TRANSIENT = 'tejcart_featured_col_dirty';

    /**
     * Reconcile the indexed `featured` column on tejcart_products with the
     * `_featured` meta key. Older builds of set_featured() only wrote the
     * meta, leaving the column at 0 and hiding those rows from the Featured
     * view.
     *
     * F-PCA-003: Gated behind the `tejcart_featured_col_dirty` transient so
     * the two full-table UPDATEs only execute when a meta write has actually
     * flagged a potential drift. The transient is set by the product save path
     * (Abstract_Product::save() calls this indirectly) and cleared here once
     * the heal completes, making the heal effectively a no-op on every
     * page load where nothing was recently changed.
     *
     * @return void
     */
    private function heal_featured_column(): void {
        // Skip the expensive UPDATE pair when no drift has been flagged.
        if ( ! get_transient( self::FEATURED_DIRTY_TRANSIENT ) ) {
            return;
        }

        global $wpdb;
        $products = $wpdb->prefix . 'tejcart_products';
        $meta     = $wpdb->prefix . 'tejcart_product_meta';

        // Table names are plugin-controlled constants ($wpdb->prefix + literal
        // string) — no user input reaches these queries. The blanket suppression
        // below is intentionally scoped to these two statements only (F-PCA-009).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "UPDATE {$products} p
             SET featured = 1
             WHERE featured = 0
               AND EXISTS (
                   SELECT 1 FROM {$meta} m
                   WHERE m.product_id = p.id
                     AND m.meta_key = '_featured'
                     AND m.meta_value IN ('1','yes','true')
               )"
        );

        $wpdb->query(
            "UPDATE {$products} p
             SET featured = 0
             WHERE featured = 1
               AND NOT EXISTS (
                   SELECT 1 FROM {$meta} m
                   WHERE m.product_id = p.id
                     AND m.meta_key = '_featured'
                     AND m.meta_value IN ('1','yes','true')
               )"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Clear the flag so subsequent page loads skip the UPDATE pair.
        delete_transient( self::FEATURED_DIRTY_TRANSIENT );
    }

    /**
     * Aggregate the catalog-wide counts used by both the views bar and the
     * stats tiles. One CASE-WHEN query, memoised per request so the counts
     * are computed once per page load.
     *
     * All counts mirror the prepare_items() visibility rule: variation child
     * rows and auto-drafts (empty-name drafts) are excluded.
     *
     * @return array{total:int, published:int, drafts:int, featured:int, low_stock:int, out_of_stock:int}
     */
    public function get_summary_counts(): array {
        if ( null !== $this->summary_counts ) {
            return $this->summary_counts;
        }

        // Catalog-wide CASE-WHEN aggregation does a full table scan and is
        // O(N) in product count. At 100k+ products this is multi-second
        // every time admin opens the products list. Cache the result and
        // bust on product mutations via Product_Factory.
        $cache_key = 'tejcart_product_summary_counts';
        $cached    = wp_cache_get( $cache_key, 'tejcart' );
        if ( is_array( $cached ) ) {
            $this->summary_counts = $cached;
            return $this->summary_counts;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'publish' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN featured = 1 THEN 1 ELSE 0 END) AS featured,
                SUM(CASE WHEN stock_status = 'outofstock' OR (manage_stock = 1 AND stock_quantity <= 0) THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN stock_status = 'instock' AND manage_stock = 1 AND stock_quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS low_stock
             FROM {$table}
             WHERE type <> 'variation' AND NOT (name = '' AND status = 'draft')",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->summary_counts = array(
            'total'        => (int) ( $row['total'] ?? 0 ),
            'published'    => (int) ( $row['published'] ?? 0 ),
            'drafts'       => (int) ( $row['drafts'] ?? 0 ),
            'featured'     => (int) ( $row['featured'] ?? 0 ),
            'low_stock'    => (int) ( $row['low_stock'] ?? 0 ),
            'out_of_stock' => (int) ( $row['out_of_stock'] ?? 0 ),
        );

        wp_cache_set( $cache_key, $this->summary_counts, 'tejcart', 5 * MINUTE_IN_SECONDS );

        return $this->summary_counts;
    }

    /**
     * Filter chips above the table:
     * All | Published | Draft | Featured | Low stock | Out of stock.
     *
     * @return array<string, string>
     */
    protected function get_views() {
        $counts   = $this->get_summary_counts();
        $base_url = admin_url( 'admin.php?page=tejcart-products' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current  = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : '';

        $make = static function ( $key, $label, $count, $is_current, $href ) {
            return sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
                esc_url( $href ),
                $is_current ? 'current' : '',
                esc_html( $label ),
                esc_html( number_format_i18n( (int) $count ) )
            );
        };

        return array(
            'all'          => $make( 'all',          __( 'All',          'tejcart' ), $counts['total'],        '' === $current,                $base_url ),
            'published'    => $make( 'published',    __( 'Published',    'tejcart' ), $counts['published'],    'published' === $current,       add_query_arg( 'filter', 'published',    $base_url ) ),
            'draft'        => $make( 'draft',        __( 'Draft',        'tejcart' ), $counts['drafts'],       'draft' === $current,           add_query_arg( 'filter', 'draft',        $base_url ) ),
            'featured'     => $make( 'featured',     __( 'Featured',     'tejcart' ), $counts['featured'],     'featured' === $current,        add_query_arg( 'filter', 'featured',     $base_url ) ),
            'low_stock'    => $make( 'low_stock',    __( 'Low stock',    'tejcart' ), $counts['low_stock'],    'low_stock' === $current,       add_query_arg( 'filter', 'low_stock',    $base_url ) ),
            'out_of_stock' => $make( 'out_of_stock', __( 'Out of stock', 'tejcart' ), $counts['out_of_stock'], 'out_of_stock' === $current,    add_query_arg( 'filter', 'out_of_stock', $base_url ) ),
        );
    }

    /**
     * Override the default views() renderer so the chip row renders
     * cleanly without the literal " |" separators WP_List_Table
     * otherwise interleaves between <li> items. CSS alone can't remove
     * those — they're text content emitted by the core method — so we
     * emit the <ul class="subsubsub"> ourselves here.
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
                $view // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_views() already escapes.
            );
        }
        echo '</ul>';
    }

    /**
     * Prepare items for display: query, paginate and sort.
     *
     * @return void
     */
    public function prepare_items() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'tejcart_products';
        $per_page   = 20;
        $paged      = $this->get_pagenum();
        $offset     = ( $paged - 1 ) * $per_page;

        $this->heal_featured_column();

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        $this->process_bulk_action();

        $allowed_orderby = array( 'name', 'price', 'created_at', 'stock_quantity' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'created_at';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $order = 'DESC';
        }

        /*
         * Build the WHERE clause as ( SQL fragment with %-placeholders, values )
         * so the entire query goes through a single $wpdb->prepare() call.
         * Hardcoded literals contribute the fragment with no values; user
         * input contributes both the placeholder and the corresponding value.
         */
        $clauses = array( '1=1' );
        $values  = array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        if ( ! empty( $search ) ) {
            $like      = '%' . $wpdb->esc_like( $search ) . '%';
            $clauses[] = '(name LIKE %s OR sku LIKE %s)';
            $values[]  = $like;
            $values[]  = $like;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_key = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : '';
        switch ( $filter_key ) {
            case 'featured':
                $clauses[] = 'featured = 1';
                break;
            case 'published':
                $clauses[] = "status = 'publish'";
                break;
            case 'draft':
                $clauses[] = "status = 'draft'";
                break;
            case 'low_stock':
                $clauses[] = "stock_status = 'instock' AND manage_stock = 1 AND stock_quantity BETWEEN 1 AND 10";
                break;
            case 'out_of_stock':
                $clauses[] = "(stock_status = 'outofstock' OR (manage_stock = 1 AND stock_quantity <= 0))";
                break;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cat_id = isset( $_REQUEST['cat'] ) ? (int) $_REQUEST['cat'] : 0;
        if ( $cat_id > 0 ) {
            $taxonomy  = \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY;
            $clauses[] = "EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = {$table_name}.id
                  AND tt.taxonomy  = %s
                  AND tt.term_id   = %d
            )";
            $values[]  = $taxonomy;
            $values[]  = $cat_id;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ptype         = isset( $_REQUEST['ptype'] ) ? sanitize_key( wp_unslash( $_REQUEST['ptype'] ) ) : '';
        $allowed_types = Product_Type_Registry::get_admin_types();
        if ( in_array( $ptype, $allowed_types, true ) ) {
            $clauses[] = 'type = %s';
            $values[]  = $ptype;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sstatus = isset( $_REQUEST['sstatus'] ) ? sanitize_key( wp_unslash( $_REQUEST['sstatus'] ) ) : '';
        if ( in_array( $sstatus, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
            $clauses[] = 'stock_status = %s';
            $values[]  = $sstatus;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $m = isset( $_REQUEST['m'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_REQUEST['m'] ) ) ) : '';
        if ( '' !== $m && 6 === strlen( $m ) ) {
            $year  = (int) substr( $m, 0, 4 );
            $month = (int) substr( $m, 4, 2 );
            if ( $year > 0 && $month >= 1 && $month <= 12 ) {
                $clauses[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
                $values[]  = $year;
                $values[]  = $month;
            }
        }

        $clauses[] = "type <> 'variation'";
        $clauses[] = "NOT (name = '' AND status = 'draft')";

        $where_sql = implode( ' AND ', $clauses );

        // $orderby and $order were validated against fixed allowlists above.
        // $table_name is `{$wpdb->prefix}tejcart_products`, plugin-owned.
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

        // Audit #23 / 08 #2 (also addresses 08 #3, 08 #4) — pre-warm
        // the Product_Factory and product-meta caches for every id on
        // this page so the subsequent per-row build_row_actions(),
        // render_derived_price(), and get_visibility_badges() calls
        // hit the warm cache instead of issuing one SELECT each.
        // Pre-fix render of a 20-row page produced ~60+ trailing
        // queries (1 product fetch + ~2 derived-price fetches + 1
        // meta fetch per row).
        if ( is_array( $this->items ) && array() !== $this->items
            && class_exists( '\\TejCart\\Product\\Product_Factory' )
        ) {
            $page_ids = array();
            foreach ( $this->items as $row ) {
                if ( is_array( $row ) && isset( $row['id'] ) ) {
                    $rid = (int) $row['id'];
                    if ( $rid > 0 ) {
                        $page_ids[] = $rid;
                    }
                }
            }
            if ( array() !== $page_ids ) {
                \TejCart\Product\Product_Factory::get_products( $page_ids );
                \TejCart\Product\Product_Factory::prime_meta_cache( $page_ids );

                // F-PCA-002: Batch-load _featured / _catalog_visibility for
                // every product on this page in a single query so
                // get_visibility_badges() can read from the local map instead
                // of issuing a per-row SELECT. Mirrors the column_categories()
                // pattern introduced for the categories N+1 fix.
                $this->visibility_map = array();
                if ( class_exists( '\\TejCart\\Product\\Product_Meta' ) ) {
                    global $wpdb;
                    $table        = $wpdb->prefix . 'tejcart_product_meta';
                    $placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    $vis_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT product_id, meta_key, meta_value
                             FROM {$table}
                             WHERE product_id IN ({$placeholders})
                               AND meta_key IN ('_featured','_catalog_visibility')",
                            $page_ids
                        ),
                        ARRAY_A
                    );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    foreach ( (array) $vis_rows as $vis_row ) {
                        $pid = (int) $vis_row['product_id'];
                        $this->visibility_map[ $pid ][ $vis_row['meta_key'] ] = (string) $vis_row['meta_value'];
                    }
                }
            }
        }
    }

    /**
     * Render the checkbox column.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="product_ids[]" value="%d" />', (int) $item['id'] );
    }

    /**
     * Filter controls that WP injects into the top tablenav between
     * the bulk-actions block and the pagination block. Category,
     * product-type, stock-status and month dropdowns + a Filter
     * submit button. The active `filter` view chip is preserved via
     * a hidden input so the two filter surfaces compose.
     *
     * @param string $which 'top' or 'bottom'.
     * @return void
     */
    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $current_cat    = isset( $_REQUEST['cat'] ) ? (int) $_REQUEST['cat'] : 0;
        $current_type   = isset( $_REQUEST['ptype'] ) ? sanitize_key( wp_unslash( $_REQUEST['ptype'] ) ) : '';
        $current_stock  = isset( $_REQUEST['sstatus'] ) ? sanitize_key( wp_unslash( $_REQUEST['sstatus'] ) ) : '';
        $current_month  = isset( $_REQUEST['m'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_REQUEST['m'] ) ) ) : '';
        // phpcs:enable

        $cats = get_terms( array(
            'taxonomy'   => \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY,
            'hide_empty' => false,
            'number'     => 200,
            'orderby'    => 'name',
        ) );
        if ( is_wp_error( $cats ) ) {
            $cats = array();
        }

        $types = array(
            'physical' => __( 'Simple',   'tejcart' ),
            'variable' => __( 'Variable', 'tejcart' ),
            'digital'  => __( 'Digital',  'tejcart' ),
            'virtual'  => __( 'Virtual',  'tejcart' ),
            'bundle'   => __( 'Bundle',   'tejcart' ),
            'external' => __( 'External', 'tejcart' ),
            'grouped'  => __( 'Grouped',  'tejcart' ),
        );

        $stock_statuses = array(
            'instock'     => __( 'In stock',     'tejcart' ),
            'outofstock'  => __( 'Out of stock', 'tejcart' ),
            'onbackorder' => __( 'On backorder', 'tejcart' ),
        );

        $months = $this->get_available_months();
        ?>
        <div class="alignleft actions nxc-filters">
            <label class="screen-reader-text" for="nxc-filter-cat"><?php esc_html_e( 'Filter by category', 'tejcart' ); ?></label>
            <select name="cat" id="nxc-filter-cat">
                <option value="0"><?php esc_html_e( 'All categories', 'tejcart' ); ?></option>
                <?php foreach ( $cats as $cat ) : ?>
                    <option value="<?php echo esc_attr( (string) (int) $cat->term_id ); ?>" <?php selected( $current_cat, (int) $cat->term_id ); ?>>
                        <?php echo esc_html( $cat->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="nxc-filter-type"><?php esc_html_e( 'Filter by product type', 'tejcart' ); ?></label>
            <select name="ptype" id="nxc-filter-type">
                <option value=""><?php esc_html_e( 'All types', 'tejcart' ); ?></option>
                <?php foreach ( $types as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_type, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="screen-reader-text" for="nxc-filter-stock"><?php esc_html_e( 'Filter by stock status', 'tejcart' ); ?></label>
            <select name="sstatus" id="nxc-filter-stock">
                <option value=""><?php esc_html_e( 'Any stock', 'tejcart' ); ?></option>
                <?php foreach ( $stock_statuses as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_stock, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( ! empty( $months ) ) : ?>
                <label class="screen-reader-text" for="nxc-filter-month"><?php esc_html_e( 'Filter by date', 'tejcart' ); ?></label>
                <select name="m" id="nxc-filter-month">
                    <option value=""><?php esc_html_e( 'All dates', 'tejcart' ); ?></option>
                    <?php foreach ( $months as $m ) : ?>
                        <?php $value = sprintf( '%04d%02d', (int) $m['year'], (int) $m['month'] ); ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_month, $value ); ?>>
                            <?php echo esc_html( date_i18n( 'F Y', mktime( 0, 0, 0, (int) $m['month'], 1, (int) $m['year'] ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php submit_button( __( 'Filter', 'tejcart' ), 'button', 'nxc-filter-submit', false ); ?>

            <?php if ( $current_cat || $current_type || $current_stock || $current_month ) : ?>
                <?php
                $clear_href = admin_url( 'admin.php?page=tejcart-products' );
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter param for clear-link href; no state change.
                if ( isset( $_GET['filter'] ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter param for clear-link href; no state change.
                    $clear_href = add_query_arg( 'filter', sanitize_key( wp_unslash( $_GET['filter'] ) ), $clear_href );
                }
                ?>
                <a class="nxc-filter-clear" href="<?php echo esc_url( $clear_href ); ?>"><?php esc_html_e( 'Clear', 'tejcart' ); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Distinct (year, month) pairs in the products table. Used to
     * populate the date-filter dropdown without pulling every row.
     *
     * @return array<int, array{year:int, month:int}>
     */
    private function get_available_months(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT DISTINCT YEAR(created_at) AS year, MONTH(created_at) AS month
             FROM {$table}
             WHERE type <> 'variation' AND created_at IS NOT NULL AND created_at <> '0000-00-00 00:00:00'
             ORDER BY year DESC, month DESC
             LIMIT 36",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $out = array();
        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row['year'] ) && ! empty( $row['month'] ) ) {
                $out[] = array( 'year' => (int) $row['year'], 'month' => (int) $row['month'] );
            }
        }
        return $out;
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
            case 'sku':
                return ! empty( $item['sku'] )
                    ? '<code class="nxc-sku">' . esc_html( $item['sku'] ) . '</code>'
                    : '<span class="nxc-muted" aria-hidden="true">—</span>';

            case 'type':
                $type  = isset( $item['type'] ) ? (string) $item['type'] : 'physical';
                $label = $this->type_label( $type );
                return sprintf(
                    '<span class="nxc-pill nxc-pill--%s">%s</span>',
                    esc_attr( $type ),
                    esc_html( $label )
                );

            case 'created_at':
                $ts = isset( $item['created_at'] ) ? strtotime( (string) $item['created_at'] ) : false;
                if ( ! $ts ) {
                    return '<span class="nxc-muted">—</span>';
                }
                $now   = (int) current_time( 'timestamp' );
                $diff  = $now - $ts;
                $full  = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );

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

            default:
                return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
        }
    }

    /**
     * Translate a product type slug into its human-readable label.
     *
     * @param string $type Product type slug.
     * @return string
     */
    private function type_label( string $type ): string {
        $map = array(
            'physical' => __( 'Physical', 'tejcart' ),
            'digital'  => __( 'Digital', 'tejcart' ),
            'virtual'  => __( 'Virtual', 'tejcart' ),
            'bundle'   => __( 'Bundle', 'tejcart' ),
            'external' => __( 'External', 'tejcart' ),
            'variable' => __( 'Variable', 'tejcart' ),
            'grouped'  => __( 'Grouped', 'tejcart' ),
        );
        return $map[ $type ] ?? ucfirst( $type );
    }

    /**
     * Render the image column with a thumbnail.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_image( $item ) {
        $name = (string) ( $item['name'] ?? '' );

        $attachment_id = ! empty( $item['image_id'] ) ? (int) $item['image_id'] : 0;
        if ( ! $attachment_id && ! empty( $item['gallery_ids'] ) ) {
            $gallery = json_decode( (string) $item['gallery_ids'], true );
            if ( is_array( $gallery ) ) {
                foreach ( $gallery as $gid ) {
                    if ( (int) $gid > 0 ) {
                        $attachment_id = (int) $gid;
                        break;
                    }
                }
            }
        }

        if ( $attachment_id > 0 ) {
            $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
            if ( ! $url ) {
                $url = wp_get_attachment_image_url( $attachment_id, 'medium' );
            }
            if ( ! $url ) {
                $url = wp_get_attachment_image_url( $attachment_id, 'full' );
            }
            if ( $url ) {
                return sprintf(
                    '<span class="nxc-thumb"><img src="%s" alt="%s" loading="lazy" /></span>',
                    esc_url( $url ),
                    esc_attr( $name )
                );
            }
        }

        return '<span class="nxc-thumb nxc-thumb--empty" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M6 7h12l-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7z"/>'
            . '<path d="M9 7V5a3 3 0 0 1 6 0v2"/>'
            . '</svg>'
            . '</span>';
    }

    /**
     * Render the name column with an edit link.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_name( $item ) {
        $id        = (int) $item['id'];
        $name      = (string) ( $item['name'] ?? '' );
        $name_text = '' !== trim( $name ) ? $name : __( '(no title)', 'tejcart' );
        $status    = (string) ( $item['status'] ?? '' );
        $edit_url  = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . $id );

        $draft = '';
        if ( 'draft' === $status ) {
            $draft = ' <span class="nxc-badge nxc-badge--draft">' . esc_html__( 'Draft', 'tejcart' ) . '</span>';
        }

        $badges   = $this->get_visibility_badges( $id );
        $subtitle = $this->row_subtitle( (string) ( $item['type'] ?? 'physical' ) );

        $title = sprintf(
            '<a href="%1$s" class="nxc-product-name" title="%2$s">%3$s</a>%4$s%5$s<div class="nxc-product-sub">%6$s</div>',
            esc_url( $edit_url ),
            esc_attr( $name_text ),
            esc_html( $name_text ),
            $draft,
            '' !== $badges ? ' ' . $badges : '',
            esc_html( $subtitle )
        );

        return $title . $this->build_row_actions( $id, $name_text, $status );
    }

    /**
     * Build the native WP row-actions payload (Edit | Duplicate | View | Delete)
     * for a given product. Uses WP_List_Table::row_actions() so the links get
     * the standard .row-actions wrapper — hover-reveal CSS, keyboard access,
     * and the mobile "Show more" toggle all come for free.
     *
     * @param int    $id     Product ID.
     * @param string $name   Display name (used in confirm message + aria).
     * @param string $status Current product status.
     * @return string
     */
    private function build_row_actions( int $id, string $name, string $status ): string {
        $edit_url = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . $id );
        $dup_url  = wp_nonce_url(
            admin_url( 'admin.php?page=tejcart-products&action=duplicate&product_id=' . $id ),
            'tejcart_duplicate_product_' . $id
        );
        $del_url  = wp_nonce_url(
            admin_url( 'admin.php?page=tejcart-products&action=delete&product_id=' . $id ),
            'tejcart_delete_product_' . $id
        );

        $actions = array(
            'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'tejcart' ) ),
            'duplicate' => sprintf( '<a href="%s">%s</a>', esc_url( $dup_url ), esc_html__( 'Duplicate', 'tejcart' ) ),
        );

        if ( 'publish' === $status ) {
            $product = \TejCart\Product\Product_Factory::get_product( $id );
            if ( $product && method_exists( $product, 'get_permalink' ) ) {
                $view_url = (string) $product->get_permalink();
                if ( '' !== $view_url ) {
                    $actions['view'] = sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url( $view_url ),
                        esc_html__( 'View', 'tejcart' )
                    );
                }
            }
        }

        /* translators: %s: product name */
        $confirm = sprintf( __( 'This permanently deletes "%s". This cannot be undone.', 'tejcart' ), $name );

        $actions['trash delete'] = sprintf(
            '<a href="%s" data-tejcart-confirm data-confirm-title="%s" data-confirm-message="%s" data-confirm-button="%s" data-confirm-tone="danger">%s</a>',
            esc_url( $del_url ),
            esc_attr__( 'Delete product?', 'tejcart' ),
            esc_attr( $confirm ),
            esc_attr__( 'Delete product', 'tejcart' ),
            esc_html__( 'Delete', 'tejcart' )
        );

        return $this->row_actions( $actions );
    }

    /**
     * One-line subtitle shown under the product name.
     */
    private function row_subtitle( string $type ): string {
        $map = array(
            'physical' => __( 'Simple product', 'tejcart' ),
            'digital'  => __( 'Digital product', 'tejcart' ),
            'virtual'  => __( 'Virtual product', 'tejcart' ),
            'bundle'   => __( 'Bundled product', 'tejcart' ),
            'external' => __( 'External product', 'tejcart' ),
            'variable' => __( 'Variable product', 'tejcart' ),
            'grouped'  => __( 'Grouped product', 'tejcart' ),
        );
        return $map[ $type ] ?? ucfirst( $type );
    }

    /**
     * Return inline badges for featured / non-default catalog visibility.
     *
     * F-PCA-002: Reads from the $this->visibility_map that was batch-loaded
     * in prepare_items() instead of issuing a per-row SELECT, eliminating the
     * N+1 query that previously executed once per product row on every admin
     * product list page load.
     *
     * @param int $product_id Product ID.
     * @return string HTML-safe badges string.
     */
    private function get_visibility_badges( int $product_id ): string {
        // Read from the page-level visibility map populated by prepare_items().
        // Fall back gracefully when called outside the normal render loop.
        $meta = is_array( $this->visibility_map ) && isset( $this->visibility_map[ $product_id ] )
            ? $this->visibility_map[ $product_id ]
            : array();

        $featured   = isset( $meta['_featured'] ) && '1' === $meta['_featured'];
        $visibility = isset( $meta['_catalog_visibility'] ) ? (string) $meta['_catalog_visibility'] : 'visible';

        $badges = '';
        if ( $featured ) {
            $badges .= ' <span class="nxc-featured" title="' . esc_attr__( 'Featured', 'tejcart' ) . '" aria-label="' . esc_attr__( 'Featured', 'tejcart' ) . '">★</span>';
        }
        if ( 'visible' !== $visibility ) {
            $labels = array(
                'catalog' => __( 'Shop only', 'tejcart' ),
                'search'  => __( 'Search only', 'tejcart' ),
                'hidden'  => __( 'Hidden', 'tejcart' ),
            );
            $label   = $labels[ $visibility ] ?? $visibility;
            $badges .= ' <span class="nxc-badge nxc-badge--muted">' . esc_html( $label ) . '</span>';
        }

        return $badges;
    }

    /**
     * Render the categories column: up to two category names as small
     * chips, with "+N more" when a product has more than two.
     *
     * Categories for every row currently on screen are fetched with a
     * single JOIN on first call and memoised for the page render, so
     * the column stays O(1) queries regardless of per-page size.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_categories( $item ) {
        $map = $this->get_categories_for_page();
        $id  = (int) $item['id'];
        $cats = $map[ $id ] ?? array();

        if ( empty( $cats ) ) {
            return '<span class="nxc-muted">—</span>';
        }

        $visible = array_slice( $cats, 0, 2 );
        $extra   = max( 0, count( $cats ) - count( $visible ) );
        $html    = '<span class="nxc-cats">';
        foreach ( $visible as $cat ) {
            $href = add_query_arg(
                array( 'page' => 'tejcart-products', 'cat' => (int) $cat['id'] ),
                admin_url( 'admin.php' )
            );
            $html .= sprintf(
                '<a class="nxc-cat" href="%s">%s</a>',
                esc_url( $href ),
                esc_html( $cat['name'] )
            );
        }
        if ( $extra > 0 ) {
            /* translators: %d: additional category count */
            $tip = sprintf( __( '%d more', 'tejcart' ), $extra );
            $html .= '<span class="nxc-cat nxc-cat--more" title="' . esc_attr( $tip ) . '">+' . (int) $extra . '</span>';
        }
        $html .= '</span>';
        return $html;
    }

    /**
     * One-shot batched lookup: product ID → category list. Runs a
     * single JOIN over wp_term_relationships / wp_term_taxonomy /
     * wp_terms for every product currently on screen, then caches
     * the result on the instance.
     *
     * @return array<int, array<int, array{id:int, name:string}>>
     */
    private function get_categories_for_page(): array {
        if ( null !== $this->categories_map ) {
            return $this->categories_map;
        }

        $this->categories_map = array();
        $ids = array();
        foreach ( (array) $this->items as $row ) {
            if ( isset( $row['id'] ) ) {
                $ids[] = (int) $row['id'];
            }
        }
        if ( empty( $ids ) ) {
            return $this->categories_map;
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $taxonomy     = \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT tr.object_id AS pid, t.term_id, t.name
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t         ON t.term_id          = tt.term_id
                 WHERE tt.taxonomy = %s
                   AND tr.object_id IN ({$placeholders})
                 ORDER BY tr.object_id, t.name",
                array_merge( array( $taxonomy ), $ids )
            ),
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            ARRAY_A
        );
        // phpcs:enable

        foreach ( (array) $rows as $row ) {
            $pid = (int) $row['pid'];
            $this->categories_map[ $pid ][] = array(
                'id'   => (int) $row['term_id'],
                'name' => (string) $row['name'],
            );
        }

        return $this->categories_map;
    }

    /**
     * Render the price column showing regular and sale price.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_price( $item ) {
        $type = isset( $item['type'] ) ? (string) $item['type'] : '';
        if ( '' !== $type && Product_Type_Registry::type_supports( $type, 'derived_price' ) ) {
            return $this->render_derived_price( (int) $item['id'], $type );
        }

        $raw_price = isset( $item['price'] ) ? (string) $item['price'] : '';
        if ( '' === $raw_price ) {
            return '<span class="nxc-muted">—</span>';
        }

        $regular_price = (float) $raw_price;
        $sale_price    = isset( $item['sale_price'] ) && '' !== $item['sale_price'] ? (float) $item['sale_price'] : 0.0;

        if ( $sale_price > 0 && $sale_price < $regular_price ) {
            return sprintf(
                '<span class="nxc-price"><del>%s</del> <ins>%s</ins></span>',
                esc_html( tejcart_price( $regular_price ) ),
                esc_html( tejcart_price( $sale_price ) )
            );
        }

        return '<span class="nxc-price">' . esc_html( tejcart_price( $regular_price ) ) . '</span>';
    }

    /**
     * Render the price cell for a derived-price product (variable, grouped,
     * bundle) as a single figure when min == max, or a "min – max" range
     * when they differ. Zero-priced children are filtered so one unpriced
     * variation can't drag the displayed min to $0.00.
     *
     * @param int    $product_id Parent product ID.
     * @param string $type       Product type slug.
     * @return string HTML string.
     */
    private function render_derived_price( int $product_id, string $type ): string {
        $muted = '<span class="nxc-muted">—</span>';

        $product = \TejCart\Product\Product_Factory::get_product( $product_id );
        if ( ! $product ) {
            return $muted;
        }

        if ( 'variable' === $type && method_exists( $product, 'get_regular_price_range' ) ) {
            $range = $product->get_regular_price_range();
            if ( ! is_array( $range ) ) {
                return $muted;
            }
            $min = (float) $range[0];
            $max = (float) $range[1];
            if ( $min <= 0 && $max <= 0 ) {
                return $muted;
            }
        } else {
            $prices = array();
            if ( method_exists( $product, 'get_variations' ) ) {
                foreach ( (array) $product->get_variations() as $child ) {
                    $p = (float) $child->get_regular_price();
                    if ( $p > 0 ) {
                        $prices[] = $p;
                    }
                }
            }
            if ( empty( $prices ) ) {
                $derived = (string) $product->get_price();
                if ( '' === $derived || (float) $derived <= 0 ) {
                    return $muted;
                }
                $prices = array( (float) $derived );
            }
            $min = min( $prices );
            $max = max( $prices );
        }

        if ( (float) $min === (float) $max ) {
            return '<span class="nxc-price">' . esc_html( tejcart_price( (float) $min ) ) . '</span>';
        }

        return sprintf(
            '<span class="nxc-price nxc-price--range"><span class="nxc-price__min">%s <span class="nxc-price__sep">–</span></span><span class="nxc-price__max">%s</span></span>',
            esc_html( tejcart_price( (float) $min ) ),
            esc_html( tejcart_price( (float) $max ) )
        );
    }

    /**
     * Render the stock column with status and quantity.
     *
     * @param array $item Row data.
     * @return string
     */
    public function column_stock_quantity( $item ) {
        $status = (string) ( $item['stock_status'] ?? 'instock' );
        $manage = ! empty( $item['manage_stock'] );
        $qty    = isset( $item['stock_quantity'] ) && '' !== $item['stock_quantity']
            ? (int) $item['stock_quantity']
            : null;

        $tone  = 'neutral';
        $label = __( 'In stock', 'tejcart' );

        if ( 'outofstock' === $status || ( $manage && null !== $qty && $qty <= 0 ) ) {
            $tone  = 'out';
            $label = __( 'Out of stock', 'tejcart' );
        } elseif ( 'onbackorder' === $status ) {
            $tone  = 'low';
            $label = __( 'On backorder', 'tejcart' );
        } elseif ( $manage && null !== $qty ) {
            if ( $qty > 10 ) {
                $tone = 'in';
                /* translators: %d: number of items in stock */
                $label = sprintf( __( '%d in stock', 'tejcart' ), $qty );
            } else {
                $tone = 'low';
                /* translators: %d: number of items remaining */
                $label = sprintf( __( 'Low (%d left)', 'tejcart' ), $qty );
            }
        } elseif ( 'instock' === $status ) {
            $tone  = 'in';
            $label = __( 'In stock', 'tejcart' );
        }

        return sprintf(
            '<span class="nxc-stock nxc-stock--%s"><span class="nxc-stock__dot" aria-hidden="true"></span><span class="nxc-stock__label">%s</span></span>',
            esc_attr( $tone ),
            esc_html( $label )
        );
    }

    /**
     * Empty-state message. Distinguishes "no catalog yet" from
     * "search / filter returned nothing" so each state gets a useful
     * next step.
     *
     * @return void
     */
    public function no_items() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_search   = ! empty( $_REQUEST['s'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_filtered = ! empty( $_REQUEST['filter'] );
        $clear_url   = admin_url( 'admin.php?page=tejcart-products' );
        $add_url     = admin_url( 'admin.php?page=tejcart-products&action=add' );
        ?>
        <div class="nxc-empty-state">
            <svg class="nxc-empty-state__icon" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 14h24l-2 26a4 4 0 0 1-4 3.5H18a4 4 0 0 1-4-3.5L12 14z"/>
                <path d="M18 14V9a6 6 0 0 1 12 0v5"/>
            </svg>
            <?php if ( $is_search || $is_filtered ) : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No products match your search', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Try a different keyword or clear the active filter.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn nxc-btn--ghost" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear filters', 'tejcart' ); ?></a></p>
            <?php else : ?>
                <h3 class="nxc-empty-state__title"><?php esc_html_e( 'No products yet', 'tejcart' ); ?></h3>
                <p class="nxc-empty-state__body"><?php esc_html_e( 'Add your first product to see it here.', 'tejcart' ); ?></p>
                <p><a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add product', 'tejcart' ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Override the native search box so we can inject a richer
     * placeholder and keep the search-icon styling predictable.
     *
     * @param string $text     Button text (unused — kept for signature parity).
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
                   placeholder="<?php esc_attr_e( 'Search by name, SKU, or ID…', 'tejcart' ); ?>" />
            <?php submit_button( $text, 'button', '', false, array( 'id' => 'search-submit' ) ); ?>
        </p>
        <?php
    }

    /**
     * Process bulk actions.
     *
     * @return void
     */
    private function process_bulk_action() {
        $action = $this->current_action();
        if ( ! $action ) {
            return;
        }

        $supported = array(
            'delete', 'publish', 'draft',
            'feature_on', 'feature_off',
            'instock', 'outofstock',
            'duplicate',

        );
        if ( ! in_array( $action, $supported, true ) ) {
            return;
        }

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-products' ) ) {
            return;
        }

        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $product_ids = isset( $_REQUEST['product_ids'] ) ? array_map( 'absint', (array) $_REQUEST['product_ids'] ) : array();
        $product_ids = array_values( array_filter( $product_ids ) );

        if ( empty( $product_ids ) ) {
            return;
        }

        $updated = 0;

        foreach ( $product_ids as $product_id ) {
            $product = \TejCart\Product\Product_Factory::get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            switch ( $action ) {
                case 'delete':
                    if ( $product->delete() ) {
                        $updated++;
                    }
                    break;

                case 'publish':
                case 'draft':
                    $product->set_status( $action );
                    if ( $product->save() ) {
                        $updated++;
                    }
                    break;

                case 'feature_on':
                case 'feature_off':
                    if ( method_exists( $product, 'set_featured' ) ) {
                        $product->set_featured( 'feature_on' === $action );
                        $updated++;
                    }
                    break;

                case 'instock':
                case 'outofstock':
                    $product->set_stock_status( $action );
                    if ( $product->save() ) {
                        $updated++;
                    }
                    break;

                case 'duplicate':

                    $new_id = \TejCart\Product\Product_Factory::duplicate( (int) $product_id );
                    if ( $new_id > 0 ) {
                        $updated++;
                    }
                    break;
            }
        }

        if ( $updated > 0 ) {
            set_transient(
                'tejcart_bulk_products_notice_' . get_current_user_id(),
                array(
                    'count'  => $updated,
                    'action' => $action,
                ),
                30
            );
        }
    }
}
