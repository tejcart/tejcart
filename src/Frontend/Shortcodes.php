<?php
/**
 * Shortcode registrations for the TejCart front-end.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and renders every public-facing TejCart shortcode.
 */
class Shortcodes {
    /**
     * Address fields used in billing/shipping forms.
     *
     * @var array
     */
    private $address_fields = array(
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    );

    /**
     * Whether the shop breadcrumb has been emitted on this request.
     *
     * Set true by render_shop_breadcrumb_above_title() once it prints the
     * trail on `loop_start`. The shortcode body checks this flag and
     * skips its own breadcrumb render so themes that fire `loop_start`
     * before `the_title()` get the breadcrumb above the page title, and
     * themes that don't (block themes, custom page builders) still
     * render it as a fallback inside the shortcode output.
     *
     * @var bool
     */
    private $shop_breadcrumb_rendered = false;

    /**
     * Register all shortcodes with WordPress.
     *
     * Every shortcode is `tejcart_*`-prefixed so we never squat on a
     * generic tag name another plugin might also register (e.g.
     * `[products]`, `[add_to_cart]`). Sites migrating in from another
     * shop plugin should rewrite their shortcodes to the prefixed
     * equivalents — see the readme.
     */
    public function init(): void {
        add_shortcode( 'tejcart_button',                array( $this, 'render_add_to_cart_button' ) );
        add_shortcode( 'tejcart_cart',                  array( $this, 'render_cart' ) );
        add_shortcode( 'tejcart_product',               array( $this, 'render_product' ) );
        add_shortcode( 'tejcart_products',              array( $this, 'render_products_grid' ) );
        add_shortcode( 'tejcart_checkout',              array( $this, 'render_checkout' ) );
        add_shortcode( 'tejcart_account',               array( $this, 'render_account' ) );
        add_shortcode( 'tejcart_thankyou',              array( $this, 'render_thankyou' ) );
        add_shortcode( 'tejcart_mini_cart',             array( $this, 'render_mini_cart' ) );
        add_shortcode( 'tejcart_product_category',      array( $this, 'render_product_category' ) );
        add_shortcode( 'tejcart_sale_products',         array( $this, 'render_sale_products' ) );
        add_shortcode( 'tejcart_best_selling_products', array( $this, 'render_best_selling_products' ) );
        // Audit #96 / 01 #5 — frontend product search form. The grid
        // shortcode already reads `?tejcart_s=`; this shortcode renders
        // a simple search input that posts to it.
        add_shortcode( 'tejcart_product_search',        array( $this, 'render_product_search' ) );

        add_action( 'template_redirect', array( $this, 'handle_save_address_post' ) );
        add_action( 'wp_ajax_tejcart_save_address', array( $this, 'ajax_save_address' ) );

        add_action( 'template_redirect', array( $this, 'maybe_redirect_empty_checkout' ) );

        add_action( 'loop_start', array( $this, 'render_shop_breadcrumb_above_title' ) );

        add_filter( 'the_title', array( $this, 'maybe_suppress_shop_title_in_single_mode' ), 10, 2 );
    }

    /**
     * Hide the shop page's WP title when the request resolves to a
     * single-product detail view.
     *
     * The product's own H1 is rendered inside the single-product
     * template, so leaving the shop page's "Shop" title in place
     * produces a duplicate heading above the product name. We only
     * blank the title for the main query on the configured shop page,
     * leaving menu items, sidebar widgets, and any other context that
     * happens to call the_title() with the shop page's ID untouched.
     *
     * @param string $title   The post title.
     * @param int    $post_id Optional. Post ID. WP <5.0 may omit this.
     * @return string
     */
    public function maybe_suppress_shop_title_in_single_mode( $title, $post_id = 0 ) {
        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id <= 0 || (int) $post_id !== $shop_page_id ) {
            return $title;
        }

        if ( ! function_exists( 'in_the_loop' ) || ! in_the_loop() ) {
            return $title;
        }
        if ( ! function_exists( 'is_main_query' ) || ! is_main_query() ) {
            return $title;
        }

        if ( ! $this->is_single_product_request() ) {
            return $title;
        }

        return '';
    }

    /**
     * Whether the current request resolves to a single-product detail
     * view (slug rewrite or ?product= legacy fallback).
     *
     * Used by the title/breadcrumb suppression hooks to avoid mutating
     * the shop page when a customer is browsing the catalog grid.
     *
     * @return bool
     */
    private function is_single_product_request(): bool {
        $slug = (string) get_query_var( \TejCart\Frontend\Product_Permalinks::QUERY_VAR, '' );
        if ( '' !== $slug ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing flag.
        if ( isset( $_GET['product'] ) && absint( $_GET['product'] ) > 0 ) {
            return true;
        }
        return false;
    }

    /**
     * Print the shop breadcrumb above the page title.
     *
     * Hooked on `loop_start`. Only emits on the configured shop page,
     * for the main query, and at most once per request. The shortcode
     * body checks {@see $shop_breadcrumb_rendered} and skips its own
     * print to avoid the trail showing up twice.
     *
     * @param \WP_Query|mixed $query The query that started looping.
     * @return void
     */
    public function render_shop_breadcrumb_above_title( $query ): void {
        if ( $this->shop_breadcrumb_rendered ) {
            return;
        }
        if ( ! ( $query instanceof \WP_Query ) || ! $query->is_main_query() ) {
            return;
        }

        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id <= 0 || ! is_page( $shop_page_id ) ) {
            return;
        }

        if ( $this->is_single_product_request() ) {
            return;
        }

        /** This filter is documented in render_products_grid(). */
        if ( ! apply_filters( 'tejcart_shop_show_breadcrumbs', true, $shop_page_id ) ) {
            return;
        }

        $html = do_shortcode( '[tejcart_breadcrumbs]' );
        if ( '' === trim( (string) $html ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumbs::render() emits already-escaped markup + a JSON-LD <script>.
        echo $html;
        $this->shop_breadcrumb_rendered = true;
    }

    /**
     * Redirect to the cart page when the checkout page is requested with an empty cart.
     *
     * Without this guard the checkout form renders with $0.00 totals after a
     * session expires or the cart is cleared, leaving the customer stranded.
     */
    public function maybe_redirect_empty_checkout(): void {
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        if ( ! function_exists( 'tejcart_is_checkout_page' ) || ! tejcart_is_checkout_page() ) {
            return;
        }

        $cart = tejcart_get_cart();
        if ( $cart && ( ! method_exists( $cart, 'is_empty' ) || ! $cart->is_empty() ) ) {
            return;
        }

        $cart_url = tejcart_get_page_url( 'cart' );
        if ( ! $cart_url ) {
            return;
        }

        wp_safe_redirect( $cart_url );
        exit;
    }

    /**
     * Render an add-to-cart button for a given product.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_add_to_cart_button( $atts ): string {
        $atts = shortcode_atts(
            array(
                'id'       => 0,
                'label'    => __( 'Add to Cart', 'tejcart' ),
                'class'    => '',
                'quantity' => 1,
                'redirect' => '',
            ),
            $atts,
            'tejcart_button'
        );

        $product_id = absint( $atts['id'] );
        if ( 0 === $product_id ) {
            return '';
        }

        $product = tejcart_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $args = array(
            'product'  => $product,
            'label'    => sanitize_text_field( $atts['label'] ),
            'class'    => sanitize_html_class( $atts['class'] ),
            'quantity' => max( 1, absint( $atts['quantity'] ) ),
            'redirect' => esc_url( $atts['redirect'] ),
        );

        ob_start();
        tejcart_get_template( 'product/add-to-cart-button.php', $args );
        return ob_get_clean();
    }

    /**
     * Render the full cart view.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_cart( $atts ): string {
        $atts = shortcode_atts(
            array(
                'show_total'     => 'yes',
                'show_thumbnail' => 'yes',
                'class'          => '',
            ),
            $atts,
            'tejcart_cart'
        );

        $cart = tejcart_get_cart();
        $args = array(
            'cart'           => $cart,
            'show_total'     => 'yes' === $atts['show_total'],
            'show_thumbnail' => 'yes' === $atts['show_thumbnail'],
            'class'          => sanitize_html_class( $atts['class'] ),
        );

        ob_start();
        tejcart_get_template( 'cart/cart.php', $args );
        // F-FE-001: cart-drawer.php is rendered once by Frontend::maybe_render_cart_drawer()
        // on wp_footer. Rendering it here a second time duplicated role=dialog IDs,
        // breaking AT (WCAG 4.1.1) and caused JS querySelector to control the wrong element.
        return ob_get_clean();
    }

    /**
     * Render a single product box.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_product( $atts ): string {
        $atts = shortcode_atts(
            array(
                'id'         => 0,
                'show_image' => 'yes',
                'show_desc'  => 'yes',
                'show_price' => 'yes',
            ),
            $atts,
            'tejcart_product'
        );

        $product_id = absint( $atts['id'] );
        if ( 0 === $product_id ) {
            return '';
        }

        $product = tejcart_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $args = array(
            'product'    => $product,
            'show_image' => 'yes' === $atts['show_image'],
            'show_desc'  => 'yes' === $atts['show_desc'],
            'show_price' => 'yes' === $atts['show_price'],
        );

        ob_start();
        tejcart_get_template( 'product/product-box.php', $args );
        return ob_get_clean();
    }

    /**
     * Render a grid of published products using the shared product-box
     * template. Designed to be dropped onto the Shop page as its default
     * content, but works in any post/page/widget area.
     *
     * Supported attributes:
     *   limit   — Products per page (defaults to the "Products Per Page"
     *             setting, falling back to 12).
     *   columns — Grid column count, 1-6 (default 4). Applied inline so
     *             the shortcode can be reused outside the stock
     *             .tejcart-product-grid breakpoints.
     *   orderby — id | name | price | created_at (default 'created_at').
     *   order   — ASC | DESC (default 'DESC').
     *   type    — Optional product type filter (e.g. 'simple').
     *
     * When the shop URL carries ?product=<id>, defer rendering to the
     * single-product template instead of the grid.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_products_grid( $atts ): string {
        $requested_slug = (string) get_query_var( \TejCart\Frontend\Product_Permalinks::QUERY_VAR, '' );
        if ( '' !== $requested_slug ) {
            $product = \TejCart\Product\Product_Factory::get_by_slug( $requested_slug );
            if ( $product ) {
                $single = $this->render_single_product( (int) $product->get_id() );
                if ( '' !== $single ) {
                    return $single;
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $requested_product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
        if ( $requested_product_id > 0 ) {
            $single = $this->render_single_product( $requested_product_id );
            if ( '' !== $single ) {
                return $single;
            }
        }

        global $wpdb;

        $default_limit = absint( get_option( 'tejcart_products_per_page', 12 ) );
        if ( $default_limit < 1 ) {
            $default_limit = 12;
        }

        $atts = shortcode_atts(
            array(
                'limit'      => $default_limit,
                'columns'    => get_option( 'tejcart_products_columns', '4' ),
                'orderby'    => 'created_at',
                'order'      => 'DESC',
                'type'       => '',
                'visibility' => '',
                'featured'   => '',
                'brand'      => '',
                'show_sort'  => 'true',
            ),
            $atts,
            'tejcart_products'
        );

        $limit   = max( 1, min( 60, absint( $atts['limit'] ) ) );
        $columns = max( 1, min( 6, absint( $atts['columns'] ) ) );

        $allowed_orderby = array( 'id', 'name', 'price', 'created_at' );
        $orderby         = in_array( $atts['orderby'], $allowed_orderby, true ) ? $atts['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';
        $show_sort       = in_array( strtolower( (string) $atts['show_sort'] ), array( '1', 'true', 'yes' ), true );

        $paged = max( 1, (int) get_query_var( 'paged' ) );
        if ( 1 === $paged ) {
            $paged = max( 1, (int) get_query_var( 'page' ) );
        }
        // Read-only pagination input used for an integer offset.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( 1 === $paged && isset( $_GET['paged'] ) ) {
            $paged = max( 1, absint( $_GET['paged'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $offset = ( $paged - 1 ) * $limit;

        $table     = $wpdb->prefix . 'tejcart_products';
        $meta_tbl  = $wpdb->prefix . 'tejcart_product_meta';
        $where     = array( 'p.status = %s' );
        $values    = array( 'publish' );
        $extra_joins = array();

        $type = sanitize_key( (string) $atts['type'] );
        if ( '' !== $type ) {
            $where[]  = 'p.type = %s';
            $values[] = $type;
        } else {
            $where[] = "p.type <> 'variation'";
        }

        if ( \TejCart\Product\Stock_Display::hide_out_of_stock() ) {
            $where[]  = 'p.stock_status = %s';
            $values[] = 'instock';
        }

        $requested_visibility = sanitize_key( (string) $atts['visibility'] );
        $default_visibility   = 'catalog';
        $visibility_mode      = '' !== $requested_visibility ? $requested_visibility : $default_visibility;
        if ( 'featured' === $visibility_mode ) {
            $atts['featured'] = 'true';
            $visibility_mode  = 'catalog';
        }
        if ( 'catalog' === $visibility_mode ) {
            $where[] = "p.catalog_visibility IN ('visible','catalog','')";
        } elseif ( 'search' === $visibility_mode ) {
            $where[] = "p.catalog_visibility IN ('visible','search','')";
        } elseif ( 'hidden' === $visibility_mode ) {
            $where[] = "p.catalog_visibility = 'hidden'";
        }

        if ( ! empty( $atts['featured'] ) && in_array( strtolower( (string) $atts['featured'] ), array( '1', 'true', 'yes' ), true ) ) {
            $where[] = 'p.featured = 1';
        }

        // Audit #96 / 01 #5 — frontend product search. The shop grid
        // honours `?tejcart_s=` (preferred, plugin-scoped) and the
        // legacy `?s=` parameter from generic WP search forms. Search
        // is a substring match on name + SKU + short description; the
        // catalog visibility allow-list above already widens to include
        // `search` when the parameter is present (see below).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $search_term = '';
        if ( isset( $_GET['tejcart_s'] ) ) {
            $search_term = sanitize_text_field( wp_unslash( (string) $_GET['tejcart_s'] ) );
        } elseif ( isset( $_GET['s'] ) ) {
            $search_term = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $search_term = trim( (string) $search_term );
        // Cap to avoid pathologically long LIKE patterns.
        if ( strlen( $search_term ) > 100 ) {
            $search_term = substr( $search_term, 0, 100 );
        }
        if ( '' !== $search_term ) {
            // Re-evaluate visibility: a search request should include
            // products whose `catalog_visibility` is 'search' as well.
            // Drop the catalog-only restriction added above and replace
            // with the search-aware one.
            foreach ( $where as $idx => $clause ) {
                if ( false !== strpos( $clause, "catalog_visibility" ) ) {
                    unset( $where[ $idx ] );
                }
            }
            $where  = array_values( $where );
            $where[] = "p.catalog_visibility IN ('visible','catalog','search','')";

            /**
             * Allow a search provider (e.g. the bundled Search module) to
             * resolve the search term to a set of product IDs using its own
             * matching engine — FULLTEXT plus Levenshtein fuzzy fallback — so
             * the full-results page reached via "View all results" stays
             * consistent with the autocomplete dropdown. A typo such as
             * "kotton" matches "cotton" products in the dropdown but never in
             * a plain LIKE, which is why the shop page came back empty.
             *
             * Return an array of product IDs to take over matching (an empty
             * array means "matched nothing"), or null to fall back to the
             * built-in substring LIKE search below.
             *
             * @param int[]|null $ids         Provider-resolved product IDs, or null.
             * @param string     $search_term The sanitised search term.
             */
            $provided_ids = apply_filters( 'tejcart_shop_search_product_ids', null, $search_term );

            if ( is_array( $provided_ids ) ) {
                $provided_ids = array_values( array_unique( array_filter( array_map( 'absint', $provided_ids ) ) ) );
                if ( empty( $provided_ids ) ) {
                    $where[] = '1 = 0';
                } else {
                    $placeholders = implode( ',', array_fill( 0, count( $provided_ids ), '%d' ) );
                    $where[]      = "p.id IN ({$placeholders})";
                    $values       = array_merge( $values, $provided_ids );
                }
            } else {
                $like     = '%' . $wpdb->esc_like( $search_term ) . '%';
                $where[]  = '( p.name LIKE %s OR p.sku LIKE %s OR p.short_description LIKE %s )';
                $values[] = $like;
                $values[] = $like;
                $values[] = $like;
            }
        }

        $brand_slugs = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $atts['brand'] ) ) ) );
        if ( ! empty( $brand_slugs ) ) {
            $brand_tt_ids = array();
            foreach ( $brand_slugs as $slug ) {
                $term = get_term_by( 'slug', $slug, \TejCart\Product\Product_Taxonomy::BRAND_TAXONOMY );
                if ( $term && ! is_wp_error( $term ) ) {
                    $brand_tt_ids[] = (int) $term->term_taxonomy_id;
                }
            }
            if ( ! empty( $brand_tt_ids ) ) {
                $rel_table     = $wpdb->prefix . 'tejcart_term_relationships';
                $placeholders  = implode( ',', array_fill( 0, count( $brand_tt_ids ), '%d' ) );
                $extra_joins[] = "INNER JOIN {$rel_table} AS brandrel ON brandrel.product_id = p.id";
                $where[]       = "brandrel.term_taxonomy_id IN ({$placeholders})";
                $values        = array_merge( $values, $brand_tt_ids );
            } else {
                $where[] = '1 = 0';
            }
        }

        $join_sql         = implode( ' ', $extra_joins );
        $where_clause     = implode( ' AND ', $where );
        $default_order_by = "p.{$orderby} {$order}";

        $query_args = apply_filters(
            'tejcart_product_query_args',
            array(
                'where_sql' => $where_clause,
                'join_sql'  => $join_sql,
                'values'    => $values,
                'order_by'  => $default_order_by,
                'per_page'  => $limit,
                'offset'    => $offset,
            )
        );

        $where_clause = isset( $query_args['where_sql'] ) ? (string) $query_args['where_sql'] : $where_clause;
        $join_sql     = isset( $query_args['join_sql'] ) ? (string) $query_args['join_sql'] : $join_sql;
        $values       = isset( $query_args['values'] ) && is_array( $query_args['values'] ) ? $query_args['values'] : $values;
        $order_by     = ! empty( $query_args['order_by'] ) ? (string) $query_args['order_by'] : $default_order_by;

        // The `tejcart_product_query_args` filter is public, and $order_by is
        // interpolated directly into the SQL below ($wpdb->prepare() does NOT
        // escape interpolated fragments — only %-placeholders). An extension
        // could therefore inject arbitrary SQL through `order_by`. Re-validate
        // the (possibly filtered) value against the same whitelist used to
        // build $default_order_by; fall back to the safe default otherwise.
        $allowed_order_by = array();
        foreach ( $allowed_orderby as $allowed_column ) {
            $allowed_order_by[] = "p.{$allowed_column} ASC";
            $allowed_order_by[] = "p.{$allowed_column} DESC";
        }
        if ( ! in_array( $order_by, $allowed_order_by, true ) ) {
            $order_by = $default_order_by;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_clause / $order_by are built from whitelisted tokens.
        $total = (int) $wpdb->get_var(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.id) FROM {$table} AS p {$join_sql} WHERE {$where_clause}",
                $values
            )
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        );

        $query_values   = $values;
        $query_values[] = $limit;
        $query_values[] = $offset;

        $ids = $wpdb->get_col(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT DISTINCT p.id FROM {$table} AS p {$join_sql} WHERE {$where_clause} ORDER BY {$order_by} LIMIT %d OFFSET %d",
                $query_values
            )
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        );
        // phpcs:enable

        if ( empty( $ids ) ) {
            return '<div class="tejcart-product-grid tejcart-product-grid--empty"><p>'
                . esc_html__( 'No products found.', 'tejcart' )
                . '</p></div>';
        }

        $products = \TejCart\Product\Product_Factory::get_products( array_map( 'absint', $ids ) );

        if ( empty( $products ) ) {
            return '';
        }

        \TejCart\Product\Product_Factory::prime_meta_cache( array_keys( $products ) );

        $ordered = array();
        foreach ( $ids as $id ) {
            $pid = (int) $id;
            if ( isset( $products[ $pid ] ) ) {
                $ordered[] = $products[ $pid ];
            }
        }

        $style = sprintf( '--tejcart-product-columns: %d;', $columns );

        $total_pages = (int) ceil( $total / $limit );

        $range_start = $total > 0 ? $offset + 1 : 0;
        $range_end   = min( $offset + count( $ordered ), $total );

        ob_start();

        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        $on_shop_page = $shop_page_id > 0 && function_exists( 'is_page' ) && is_page( $shop_page_id );
        /**
         * Filter whether the breadcrumb renders on the shop page.
         *
         * Defaults to true on the configured shop page, false elsewhere.
         * Themes that paint their own breadcrumb chrome can return false
         * to avoid double rendering.
         *
         * @param bool $show         Whether to render.
         * @param int  $shop_page_id The configured shop page id.
         */
        $show_breadcrumbs = apply_filters( 'tejcart_shop_show_breadcrumbs', $on_shop_page, $shop_page_id );
        if ( $show_breadcrumbs && ! $this->shop_breadcrumb_rendered ) {
            $breadcrumb_html = do_shortcode( '[tejcart_breadcrumbs]' );
            if ( '' !== trim( (string) $breadcrumb_html ) ) {
                echo $breadcrumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumbs::render() emits already-escaped markup + a JSON-LD <script>.
                $this->shop_breadcrumb_rendered = true;
            }
        }

        // Capture the grid content (meta + grid + pagination) into a
        // buffer so the archive template can place it inside the
        // two-column faceted layout.
        ob_start();

        /**
         * Fires before the shop meta bar and product grid.
         *
         * Modules can hook here to inject content above the product
         * listing (e.g. a search bar, promotional banner).
         *
         * @param int $total         Total products matching the current query.
         * @param int $shop_page_id  The configured shop page ID.
         */
        do_action( 'tejcart_before_shop_loop', $total, $shop_page_id );

        echo '<div class="tejcart-shop-meta">';
        echo '<span class="tejcart-shop-meta-count">';
        if ( $total <= $limit ) {
            echo esc_html( sprintf(
                /* translators: %d: total number of products in the shop */
                _n( 'Showing %d result', 'Showing all %d results', $total, 'tejcart' ),
                $total
            ) );
        } else {
            echo esc_html( sprintf(
                /* translators: 1: range start, 2: range end, 3: total products */
                __( 'Showing %1$d&ndash;%2$d of %3$d results', 'tejcart' ),
                $range_start,
                $range_end,
                $total
            ) );
        }
        echo '</span>';
        if ( $show_sort && shortcode_exists( 'tejcart_product_sort' ) ) {
            echo do_shortcode( '[tejcart_product_sort]' );
        }
        echo '</div>';
        echo '<div class="tejcart-product-grid" style="' . esc_attr( $style ) . '">';
        $tejcart_grid_index = 0;
        foreach ( $ordered as $product ) {
            tejcart_get_template(
                'product/product-box.php',
                array(
                    'product'          => $product,
                    'show_image'       => true,
                    'show_desc'        => true,
                    'show_price'       => true,
                    'is_lcp_candidate' => 0 === $tejcart_grid_index,
                )
            );
            $tejcart_grid_index++;
        }
        echo '</div>';

        if ( $total_pages > 1 ) {
            $this->render_shop_pagination( $paged, $total_pages );
        }

        $grid_html = (string) ob_get_clean();

        /**
         * Filter whether the faceted sidebar is shown on the shop page.
         *
         * @param bool $show         Whether to show the sidebar. Defaults to true on the shop page.
         * @param int  $shop_page_id The configured shop page ID.
         */
        $show_facets = (bool) apply_filters( 'tejcart_shop_show_faceted_sidebar', $on_shop_page, $shop_page_id );

        /**
         * Filters the faceted sidebar HTML for the shop page.
         *
         * The product-filters module hooks this to supply the sidebar.
         * Returns empty string when the module is disabled.
         *
         * @param string $html Empty by default.
         */
        $sidebar_html = (string) apply_filters( 'tejcart_shop_sidebar_html', '' );

        if ( $show_facets && '' !== $sidebar_html ) {
            /** @see tejcart_shop_active_filters_html */
            $active_filters = (string) apply_filters( 'tejcart_shop_active_filters_html', '' );
            $active_count   = substr_count( $active_filters, 'tejcart-active-filter-chip' );

            ob_start();
            tejcart_get_template( 'product/archive.php', array(
                'sidebar_html'   => $sidebar_html,
                'active_filters' => $active_filters,
                'grid_html'      => $grid_html,
                'active_count'   => $active_count,
            ) );
            $grid_html = (string) ob_get_clean();
        }

        echo $grid_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped content from grid rendering.

        return (string) ob_get_clean();
    }

    /**
     * Output pagination links for the products grid.
     *
     * @param int $current Current page.
     * @param int $total   Total pages.
     * @return void
     */
    private function render_shop_pagination( int $current, int $total ): void {
        $base_url = '';
        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        if ( $shop_page_id > 0 ) {
            $base_url = (string) get_permalink( $shop_page_id );
        }
        if ( '' === $base_url ) {
            $base_url = (string) get_permalink();
        }
        if ( '' === $base_url ) {
            $base_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
        }

        $using_pretty = '' !== (string) get_option( 'permalink_structure', '' );
        if ( $using_pretty ) {
            $paginate_base = user_trailingslashit( trailingslashit( $base_url ) . 'page/%#%' );
        } else {
            $paginate_base = add_query_arg( 'paged', '%#%', $base_url );
        }

        $links = paginate_links(
            array(
                'base'      => $paginate_base,
                'format'    => '',
                'current'   => $current,
                'total'     => $total,
                'type'      => 'array',
                'prev_text' => __( '&larr;', 'tejcart' ),
                'next_text' => __( '&rarr;', 'tejcart' ),
            )
        );

        if ( empty( $links ) ) {
            return;
        }

        echo '<nav class="tejcart-pagination" aria-label="' . esc_attr__( 'Products pagination', 'tejcart' ) . '">';
        echo '<ul class="tejcart-pagination-list">';
        foreach ( $links as $link ) {
            if ( false !== strpos( $link, 'page-numbers current' ) ) {
                $link = preg_replace(
                    '/<(span|a)\b([^>]*\bclass="[^"]*\bpage-numbers current\b[^"]*"[^>]*)>/',
                    '<$1$2 aria-current="page">',
                    $link,
                    1
                );
            }
            echo '<li class="tejcart-pagination-item">' . wp_kses_post( $link ) . '</li>';
        }
        echo '</ul>';
        echo '</nav>';
    }

    /**
     * Render a single product's detail view.
     *
     * Returns an empty string when the requested product ID doesn't
     * resolve to a published product, allowing the caller to fall back
     * to the grid view.
     *
     * @param int $product_id The requested product ID.
     * @return string
     */
    private function render_single_product( int $product_id ): string {
        $product = tejcart_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $status = method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish';
        if ( 'publish' !== $status && ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        ob_start();
        tejcart_get_template( 'product/single-product.php', array( 'product' => $product ) );
        return (string) ob_get_clean();
    }

    /**
     * Render the mini cart widget.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_mini_cart( $atts ): string {
        $atts = shortcode_atts(
            array(
                'show_count' => 'yes',
                'show_total' => 'yes',
            ),
            $atts,
            'tejcart_mini_cart'
        );

        $cart = tejcart_get_cart();
        $args = array(
            'cart'       => $cart,
            'show_count' => 'yes' === $atts['show_count'],
            'show_total' => 'yes' === $atts['show_total'],
        );

        ob_start();
        tejcart_get_template( 'cart/mini-cart.php', $args );
        return ob_get_clean();
    }

    /**
     * Render the checkout form.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_checkout( $atts ): string {
        $cart              = tejcart_get_cart();
        $checkout_fields   = new \TejCart\Checkout\Checkout_Fields();
        $available_gateways = tejcart()->gateways()->get_available_gateways();

        ob_start();
        tejcart_get_template( 'checkout/checkout.php', array(
            'cart'               => $cart,
            'checkout_fields'    => $checkout_fields,
            'available_gateways' => $available_gateways,
        ) );
        return ob_get_clean();
    }

    /**
     * Render the customer account area (order history, addresses).
     *
     * Checks login status, resolves the current tab from $_GET,
     * and passes the relevant data to the main account template.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_account( $atts ): string {
        if ( ! is_user_logged_in() ) {
            $track_order_error = '';
            $login_error       = '';
            $register_error    = '';

            $request_method = isset( $_SERVER['REQUEST_METHOD'] )
                ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
                : '';

            // Audit #29 / 01 #1 — login form submission. The template
            // renders `<form method="post">` with nonce `tejcart_login`
            // and a `tejcart_login` submit button, but no handler
            // existed for that POST. Submitting just reloaded the page.
            if ( 'POST' === $request_method
                && isset( $_POST['tejcart_login'], $_POST['tejcart_login_nonce'] )
                && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_login_nonce'] ) ), 'tejcart_login' )
            ) {
                $username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
                $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
                $remember = ! empty( $_POST['rememberme'] );

                if ( '' === $username || '' === $password ) {
                    $login_error = __( 'Please enter both username and password.', 'tejcart' );
                } else {
                    $user = wp_signon(
                        array(
                            'user_login'    => $username,
                            'user_password' => $password,
                            'remember'      => $remember,
                        ),
                        is_ssl()
                    );
                    if ( is_wp_error( $user ) ) {
                        $login_error = $user->get_error_message();
                    } else {
                        $redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ) : '';
                        if ( '' === $redirect_to ) {
                            $redirect_to = get_permalink();
                            if ( ! $redirect_to ) {
                                $redirect_to = home_url( '/' );
                            }
                        }
                        wp_safe_redirect( $redirect_to );
                        exit;
                    }
                }
            }

            // Audit #30 / 01 #2 — registration form submission. Same
            // template, same fault: nonce + submit button rendered
            // but no PHP handler. Gated on the tejcart_enable_registration
            // option that the template uses to render the section.
            if ( 'POST' === $request_method
                && isset( $_POST['tejcart_register'], $_POST['tejcart_register_nonce'] )
                && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_register_nonce'] ) ), 'tejcart_register' )
                && 'yes' === get_option( 'tejcart_enable_registration', 'yes' )
            ) {
                $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

                if ( '' === $email || ! is_email( $email ) ) {
                    $register_error = __( 'Please enter a valid email address.', 'tejcart' );
                } elseif ( '' === $password ) {
                    $register_error = __( 'Please choose a password.', 'tejcart' );
                } elseif ( email_exists( $email ) ) {
                    $register_error = __( 'An account with that email already exists. Please sign in instead.', 'tejcart' );
                } else {
                    $user_id = wp_create_user( $email, $password, $email );
                    if ( is_wp_error( $user_id ) ) {
                        $register_error = $user_id->get_error_message();
                    } else {
                        wp_set_current_user( (int) $user_id );
                        wp_set_auth_cookie( (int) $user_id, false, is_ssl() );
                        do_action( 'wp_login', $email, get_user_by( 'id', (int) $user_id ) );

                        $redirect_to = get_permalink();
                        if ( ! $redirect_to ) {
                            $redirect_to = home_url( '/' );
                        }
                        wp_safe_redirect( $redirect_to );
                        exit;
                    }
                }
            }
            if ( 'POST' === $request_method
                 && isset( $_POST['tejcart_track_order'] )
                 && isset( $_POST['tejcart_track_order_nonce'] )
                 && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_track_order_nonce'] ) ), 'tejcart_track_order' )
            ) {
                $track_order_number = isset( $_POST['order_number'] )
                    ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) )
                    : '';
                $track_order_email  = isset( $_POST['order_email'] )
                    ? sanitize_email( wp_unslash( $_POST['order_email'] ) )
                    : '';

                if ( '' !== $track_order_number && is_email( $track_order_email ) ) {
                    $order = $this->find_order_for_tracking( $track_order_number, $track_order_email );

                    if ( $order ) {
                        // Send the guest to the keyed order-received page —
                        // the canonical guest-accessible order view. The
                        // numeric id + order_key are carried by
                        // tejcart_get_thankyou_url(), and the thank-you page
                        // authorises on a constant-time order_key match, so
                        // no login is required. The previous redirect pointed
                        // at the my-account `view-order` tab, which is
                        // logged-in only — a dead end for the very guests this
                        // "no account required" lookup is meant to serve.
                        wp_safe_redirect( tejcart_get_thankyou_url( (int) $order->id ) );
                        exit;
                    }

                    $track_order_error = __( 'No order found matching that order number and email address.', 'tejcart' );
                } else {
                    $track_order_error = __( 'Please enter a valid order number and email address.', 'tejcart' );
                }
            }

            ob_start();
            tejcart_get_template(
                'account/login-form.php',
                array(
                    'track_order_error' => $track_order_error,
                    'login_error'       => $login_error,
                    'register_error'    => $register_error,
                )
            );
            return ob_get_clean();
        }

        $customer_id = get_current_user_id();
        $orders      = tejcart_get_customer_orders( $customer_id );
        $addresses   = tejcart_get_customer_addresses( $customer_id );

        $valid_tabs  = array( 'dashboard', 'orders', 'view-order', 'downloads', 'addresses', 'account-details' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            /**
             * Allow extensions to register additional valid tabs.
             *
             * @param bool   $is_valid    Whether the tab is valid.
             * @param string $current_tab The requested tab slug.
             */
            $is_custom_valid = apply_filters( 'tejcart_is_valid_account_tab', false, $current_tab );
            if ( ! $is_custom_valid ) {
                $current_tab = 'dashboard';
            }
        }

        $args = array(
            'customer_id' => $customer_id,
            'orders'      => $orders,
            'addresses'   => $addresses,
            'current_tab' => $current_tab,
        );

        ob_start();
        tejcart_get_template( 'account/account.php', $args );
        return ob_get_clean();
    }

    /**
     * Look up a single order by its customer-facing order number + email.
     *
     * `order_number` holds the human-facing identifier printed on receipts
     * and in emails (e.g. NXC-2M5KR7-A3BX9P); the numeric `id` column is an
     * internal primary key the customer never sees, so guest tracking must
     * match on the order number rather than the id. Both the order number
     * AND the email must match, which keeps order numbers from being
     * enumerated by guessing a single field.
     *
     * @param string $order_number Customer-facing order number.
     * @param string $email        Customer email tied to the order.
     * @return object|null Matching order row, or null when nothing matches.
     */
    protected function find_order_for_tracking( string $order_number, string $email ) {
        if ( '' === $order_number || '' === $email ) {
            return null;
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$orders_table} WHERE order_number = %s AND customer_email = %s LIMIT 1",
            $order_number,
            $email
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $order ?: null;
    }

    /**
     * Render the order confirmation / thank-you page.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_thankyou( $atts ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

        if ( 0 === $order_id ) {
            return '<p>' . esc_html__( 'Invalid order.', 'tejcart' ) . '</p>';
        }

        $order = tejcart_get_order( $order_id );
        if ( ! $order ) {
            return '<p>' . esc_html__( 'Invalid order.', 'tejcart' ) . '</p>';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

        $is_authorized = false;

        // Use hash_equals() instead of `===` to remove the
        // timing-attack signal an attacker could otherwise use to learn
        // the order_key one byte at a time. The legacy === comparison is
        // short-circuited per character; hash_equals is constant-time
        // for equal-length strings.
        // The thank-you URL contract is supposed to be opaque:
        // a valid `order_key` is always required. Logged-in customer
        // ownership becomes an *additional* check on top of a verified
        // key, never a substitute for it. Without this, a user who
        // discovers their numeric `order_id` (e.g. by reading the URL
        // bar on a shared kiosk, a recovered backup, or a support thread)
        // can pull the full order details just by reloading without the
        // key. Site administrators (`manage_options`) keep the override
        // because they can already read every order from the admin UI.
        $stored_key = (string) $order->get_order_key();
        $key_valid  = ( '' !== $order_key && '' !== $stored_key && strlen( $order_key ) === strlen( $stored_key )
            && hash_equals( $stored_key, $order_key ) );

        if ( $key_valid ) {
            $is_authorized = true;
        }

        if ( ! $is_authorized && current_user_can( 'manage_options' ) ) {
            $is_authorized = true;
        }

        if ( ! $is_authorized ) {
            return '<p>' . esc_html__( 'Invalid order.', 'tejcart' ) . '</p>';
        }

        // Defence-in-depth: when the buyer is logged in, additionally
        // require that the order belongs to them. A leaked URL containing
        // a valid order_key shouldn't expose another customer's order to
        // a logged-in shopper just because the key was correct.
        if ( $key_valid && is_user_logged_in() ) {
            $order_customer = (int) $order->get_customer_id();
            $current_user   = (int) get_current_user_id();
            if ( $order_customer > 0 && $order_customer !== $current_user
                && ! current_user_can( 'manage_options' ) ) {
                return '<p>' . esc_html__( 'Invalid order.', 'tejcart' ) . '</p>';
            }
        }

        $args = array(
            'order' => $order,
        );

        ob_start();
        tejcart_get_template( 'order/thank-you.php', $args );
        return ob_get_clean();
    }

    /**
     * Handle the address save form POST submission (non-AJAX).
     *
     * Listens on template_redirect so the address is saved before
     * the page renders.
     *
     * @return void
     */
    public function handle_save_address_post(): void {
        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        if ( 'POST' !== $request_method ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_save_address'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $type = isset( $_POST['tejcart_address_type'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_address_type'] ) ) : '';

        if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) ) {
            return;
        }

        if ( ! isset( $_POST['tejcart_address_nonce'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_address_nonce'] ) ), 'tejcart_save_address_' . $type )
        ) {
            return;
        }

        $customer_id = get_current_user_id();

        foreach ( $this->address_fields as $field ) {
            $post_key  = $type . '_' . $field;
            $meta_key  = 'tejcart_' . $type . '_' . $field;
            $value     = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';
            update_user_meta( $customer_id, $meta_key, $value );
        }

        /**
         * Fires after a customer address has been saved.
         *
         * @param int    $customer_id The customer user ID.
         * @param string $type        Address type (billing or shipping).
         */
        do_action( 'tejcart_customer_address_saved', $customer_id, $type );
    }

    /**
     * AJAX handler to save a customer address.
     *
     * Expects POST with nonce, address_type, and individual address fields.
     *
     * @return void
     */
    public function ajax_save_address(): void {
        check_ajax_referer( 'tejcart_save_address', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Please log in.', 'tejcart' ) ) );
        }

        $type = isset( $_POST['address_type'] ) ? sanitize_text_field( wp_unslash( $_POST['address_type'] ) ) : '';

        if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid address type.', 'tejcart' ) ) );
        }

        $customer_id = get_current_user_id();
        $required    = array( 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'country' );

        foreach ( $required as $field ) {
            $post_key = $type . '_' . $field;
            $value    = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';
            if ( empty( $value ) ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: %s: field name */
                        __( 'The %s field is required.', 'tejcart' ),
                        str_replace( '_', ' ', $field )
                    ),
                ) );
            }
        }

        $saved_address = array();
        foreach ( $this->address_fields as $field ) {
            $post_key = $type . '_' . $field;
            $meta_key = 'tejcart_' . $type . '_' . $field;
            $value    = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';
            update_user_meta( $customer_id, $meta_key, $value );
            // F-L3 / #953: echo the canonical (sanitized) value back so
            // the client can re-render the address card with whatever
            // normalisation the server applied.
            $saved_address[ $field ] = $value;
        }

        /** This action is documented in src/Frontend/Shortcodes.php */
        do_action( 'tejcart_customer_address_saved', $customer_id, $type );

        wp_send_json_success( array(
            'message' => __( 'Address saved successfully.', 'tejcart' ),
            'type'    => $type,
            'address' => $saved_address,
        ) );
    }

    /**
     * Render products filtered by one or more category slugs.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_product_category( $atts ): string {
        $atts = shortcode_atts(
            array(
                'category' => '',
                'limit'    => 12,
                'columns'  => 4,
                'orderby'  => 'created_at',
                'order'    => 'DESC',
            ),
            $atts,
            'product_category'
        );

        $categories = array_filter( array_map( 'trim', explode( ',', (string) $atts['category'] ) ) );

        $filter = function ( array $ids, array $cats ): array {
            if ( empty( $cats ) || empty( $ids ) ) {
                return $ids;
            }
            $term_ids = array();
            foreach ( $cats as $slug ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), 'tejcart_product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $term_ids[] = (int) $term->term_taxonomy_id;
                }
            }
            if ( empty( $term_ids ) ) {
                return array();
            }

            global $wpdb;
            $rel_table    = $wpdb->prefix . 'tejcart_term_relationships';
            $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
            $id_ph        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $matching = $wpdb->get_col(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT DISTINCT product_id FROM {$rel_table} WHERE term_taxonomy_id IN ({$placeholders}) AND product_id IN ({$id_ph})",
                    array_merge( $term_ids, $ids )
                )
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            );
            // phpcs:enable

            return array_map( 'absint', (array) $matching );
        };

        return $this->render_filtered_products_grid(
            $atts,
            function ( array $ids ) use ( $filter, $categories ): array {
                return $filter( $ids, $categories );
            }
        );
    }

    /**
     * Render products that are currently on sale (sale_price < price).
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_sale_products( $atts ): string {
        $atts = shortcode_atts(
            array(
                'limit'   => 12,
                'columns' => 4,
                'orderby' => 'created_at',
                'order'   => 'DESC',
            ),
            $atts,
            'sale_products'
        );

        return $this->render_filtered_products_grid(
            $atts,
            function ( array $ids ): array {
                if ( empty( $ids ) ) {
                    return $ids;
                }
                global $wpdb;
                $table = $wpdb->prefix . 'tejcart_products';
                $ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $rows = $wpdb->get_col(
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $wpdb->prepare(
                        "SELECT id FROM {$table}
                         WHERE id IN ({$ph})
                           AND sale_price IS NOT NULL
                           AND CAST(sale_price AS DECIMAL(20,4)) > 0
                           AND CAST(sale_price AS DECIMAL(20,4)) < CAST(price AS DECIMAL(20,4))",
                        $ids
                    )
                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                );
                // phpcs:enable
                return array_map( 'absint', (array) $rows );
            }
        );
    }

    /**
     * Render products ranked by number of units sold across completed orders.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_best_selling_products( $atts ): string {
        $atts = shortcode_atts(
            array(
                'limit'   => 12,
                'columns' => 4,
            ),
            $atts,
            'best_selling_products'
        );

        global $wpdb;
        $items_table  = $wpdb->prefix . 'tejcart_order_items';
        $orders_table = $wpdb->prefix . 'tejcart_orders';
        $limit        = max( 1, min( 60, absint( $atts['limit'] ) ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = (array) $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT oi.product_id
                 FROM {$items_table} oi
                 INNER JOIN {$orders_table} o ON o.id = oi.order_id
                 WHERE o.status IN ('completed','processing')
                   AND oi.product_id IS NOT NULL
                 GROUP BY oi.product_id
                 ORDER BY SUM(oi.quantity) DESC
                 LIMIT %d",
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( empty( $ids ) ) {
            return '<div class="tejcart-product-grid tejcart-product-grid--empty"><p>'
                . esc_html__( 'No products found.', 'tejcart' )
                . '</p></div>';
        }

        $columns  = max( 1, min( 6, absint( $atts['columns'] ) ) );
        $products = \TejCart\Product\Product_Factory::get_products( $ids );

        $ordered = array();
        foreach ( $ids as $id ) {
            if ( isset( $products[ $id ] ) ) {
                $ordered[] = $products[ $id ];
            }
        }

        $style = sprintf( '--tejcart-product-columns: %d;', $columns );

        ob_start();
        echo '<div class="tejcart-product-grid" style="' . esc_attr( $style ) . '">';
        foreach ( $ordered as $product ) {
            tejcart_get_template(
                'product/product-box.php',
                array(
                    'product'    => $product,
                    'show_image' => true,
                    'show_desc'  => true,
                    'show_price' => true,
                )
            );
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render a frontend product-search form.
     *
     * Audit #96 / 01 #5 — the grid shortcode honours `?tejcart_s=` and
     * `?s=` for search; this shortcode emits a GET form that submits to
     * the configured shop page (or the current page when no shop page
     * is configured). The form intentionally uses GET so the result URL
     * is shareable / bookmarkable.
     *
     * Attributes:
     *  - placeholder: input placeholder. Defaults to "Search products".
     *  - button:      submit-button label. Defaults to "Search".
     *  - action:      override the form action URL.
     *
     * @param array<string,string>|string $atts Shortcode attributes.
     * @return string
     */
    public function render_product_search( $atts ): string {
        $atts = shortcode_atts(
            array(
                'placeholder' => __( 'Search products', 'tejcart' ),
                'button'      => __( 'Search', 'tejcart' ),
                'action'      => '',
            ),
            $atts,
            'tejcart_product_search'
        );

        $action = (string) $atts['action'];
        if ( '' === $action ) {
            $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
            $action       = $shop_page_id > 0 ? (string) get_permalink( $shop_page_id ) : '';
        }
        if ( '' === $action ) {
            $action = home_url( '/' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of current search term.
        $current = isset( $_GET['tejcart_s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tejcart_s'] ) ) : '';
        if ( '' === $current ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $current = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        }

        ob_start();
        ?>
        <form role="search" method="get" class="tejcart-product-search" action="<?php echo esc_url( $action ); ?>">
            <?php // .tejcart-sr-only defined in assets/css/tejcart-public.css ?>
            <label class="tejcart-sr-only" for="tejcart-product-search-input">
                <?php echo esc_html( (string) $atts['placeholder'] ); ?>
            </label>
            <svg class="tejcart-product-search__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.75"/><path d="m13 13 4.5 4.5"/></svg>
            <input
                type="search"
                id="tejcart-product-search-input"
                class="tejcart-product-search__input tejcart-search-field"
                name="tejcart_s"
                value="<?php echo esc_attr( $current ); ?>"
                placeholder="<?php echo esc_attr( (string) $atts['placeholder'] ); ?>"
                autocomplete="off"
            />
            <button type="submit" class="tejcart-product-search__button">
                <?php echo esc_html( (string) $atts['button'] ); ?>
            </button>
        </form>
        <?php
        $form = (string) ob_get_clean();

        /** This filter is documented in modules/search/src/Autocomplete_Widget.php */
        return (string) apply_filters( 'tejcart_search_form', $form );
    }

    /**
     * Render the published product grid with a caller-supplied ID filter.
     *
     * Keeps the ordering/pagination plumbing centralised so each classic
     * shortcode only has to declare how it narrows the ID list.
     *
     * @param array    $atts        Resolved shortcode attributes: limit, columns, orderby, order.
     * @param callable $id_filter   fn(int[] $ids): int[] — receives the base ID set and returns the final list.
     * @return string
     */
    private function render_filtered_products_grid( array $atts, callable $id_filter ): string {
        global $wpdb;

        $limit   = max( 1, min( 60, absint( $atts['limit'] ) ) );
        $columns = max( 1, min( 6, absint( $atts['columns'] ) ) );

        $allowed_orderby = array( 'id', 'name', 'price', 'created_at' );
        $orderby         = in_array( $atts['orderby'] ?? '', $allowed_orderby, true ) ? $atts['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper( (string) ( $atts['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

        $table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status = %s ORDER BY {$orderby} {$order} LIMIT %d",
                'publish',
                $limit * 4
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $ids = array_map( 'absint', (array) $ids );
        $ids = (array) $id_filter( $ids );
        $ids = array_slice( array_values( array_unique( $ids ) ), 0, $limit );

        if ( empty( $ids ) ) {
            return '<div class="tejcart-product-grid tejcart-product-grid--empty"><p>'
                . esc_html__( 'No products found.', 'tejcart' )
                . '</p></div>';
        }

        $products = \TejCart\Product\Product_Factory::get_products( $ids );

        $ordered = array();
        foreach ( $ids as $id ) {
            if ( isset( $products[ $id ] ) ) {
                $ordered[] = $products[ $id ];
            }
        }

        $style = sprintf( '--tejcart-product-columns: %d;', $columns );

        ob_start();
        echo '<div class="tejcart-product-grid" style="' . esc_attr( $style ) . '">';
        foreach ( $ordered as $product ) {
            tejcart_get_template(
                'product/product-box.php',
                array(
                    'product'    => $product,
                    'show_image' => true,
                    'show_desc'  => true,
                    'show_price' => true,
                )
            );
        }
        echo '</div>';

        return (string) ob_get_clean();
    }
}
