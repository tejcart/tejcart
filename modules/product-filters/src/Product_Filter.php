<?php
/**
 * Product Filtering & Search with AJAX support and faceted navigation.
 *
 * @package TejCart\Product_Filters
 */

declare( strict_types=1 );

namespace TejCart\Product_Filters;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use TejCart\Product\Product_Taxonomy;
use TejCart\Product\Global_Attributes;

/**
 * Provides a shortcode and AJAX endpoint for filtering products by
 * category, price range, rating, stock status, and custom attributes.
 *
 * Since 1.6.0 also provides full faceted navigation: server-side
 * rendered sidebar with counts, URL state preservation via GET params,
 * canonical tags for SEO, and AJAX progressive enhancement.
 */
class Product_Filter {
    private const ATTR_CACHE_KEY   = 'tejcart_filterable_attributes';
    private const FACET_CACHE_KEY  = 'tejcart_facet_counts';

    public const PARAM_CATEGORY   = 'filter_cat';
    public const PARAM_TAG        = 'filter_tag';
    public const PARAM_BRAND      = 'filter_brand';
    public const PARAM_MIN_PRICE  = 'min_price';
    public const PARAM_MAX_PRICE  = 'max_price';
    public const PARAM_RATING     = 'filter_rating';
    public const PARAM_STOCK      = 'in_stock';
    // URL query-parameter prefix for attribute facets (e.g. ?pa_color=red).
    // This is a per-request query key the module parses itself — NOT a
    // registered taxonomy/option in WordPress's shared namespace — so it is
    // intentionally kept short and does not collide with anything. Attribute
    // facets resolve against product meta (`_attribute_{slug}`), not the
    // tejcart_pa_* taxonomy.
    public const PARAM_ATTR_PREFIX = 'pa_';
    public const FILTER_PATH_VAR   = 'tejcart_filter_path';

    private const FILTER_PATH_PREFIX    = 'filter';
    private const HISTOGRAM_BUCKETS    = 20;
    private const SLUG_MAP_CACHE_KEY   = 'tejcart_filter_slug_map';
    private const FACET_INITIAL_LIMIT  = 8;
    private const FACET_SEARCH_THRESHOLD = 15;

    private static ?self $instance = null;

    /** @var array<string, mixed>|null Lazy-parsed URL filter state. */
    private ?array $url_state = null;

    /** @var bool Whether clean filter URLs are available. */
    private ?bool $clean_urls = null;

    public static function get_instance(): ?self {
        return self::$instance;
    }

    public static function set_instance( ?self $instance ): void {
        self::$instance = $instance;
    }

    public function init(): void {
        add_shortcode( 'tejcart_product_filter', array( $this, 'shortcode' ) );

        add_action( 'wp_ajax_tejcart_filter_products', array( $this, 'ajax_filter' ) );
        add_action( 'wp_ajax_nopriv_tejcart_filter_products', array( $this, 'ajax_filter' ) );

        // Refresh facet / slug-map caches whenever a product is created,
        // updated or deleted so the sidebar counts and clean-URL slug map
        // never serve stale data. tejcart_product_saved fires on both
        // create and update; tejcart_product_deleted covers removal.
        add_action( 'tejcart_product_saved', array( $this, 'invalidate_attribute_cache' ) );
        add_action( 'tejcart_product_saved', array( $this, 'invalidate_slug_map_cache' ) );
        add_action( 'tejcart_product_deleted', array( $this, 'invalidate_attribute_cache' ) );
        add_action( 'tejcart_product_deleted', array( $this, 'invalidate_slug_map_cache' ) );

        add_filter( 'tejcart_product_query_args', array( $this, 'apply_url_filters' ), 5 );
        add_filter( 'query_vars', array( $this, 'register_filter_query_vars' ) );
        add_action( 'wp_head', array( $this, 'render_seo_tags' ) );

        add_action( 'init', array( $this, 'register_filter_rewrite_rules' ) );
        add_action( 'update_option_tejcart_shop_page_id', array( $this, 'flush_filter_rules' ), 10, 0 );
        add_action( 'add_option_tejcart_shop_page_id', array( $this, 'flush_filter_rules' ), 10, 0 );
    }

    /**
     * Register filter GET parameters as WordPress query vars so they
     * survive parse_request.
     *
     * @param string[] $vars Registered query vars.
     * @return string[]
     */
    public function register_filter_query_vars( array $vars ): array {
        $vars[] = self::PARAM_CATEGORY;
        $vars[] = self::PARAM_TAG;
        $vars[] = self::PARAM_BRAND;
        $vars[] = self::PARAM_MIN_PRICE;
        $vars[] = self::PARAM_MAX_PRICE;
        $vars[] = self::PARAM_RATING;
        $vars[] = self::PARAM_STOCK;
        $vars[] = self::FILTER_PATH_VAR;
        return $vars;
    }

    // ──────────────────────────────────────────────
    //  URL state parsing
    // ──────────────────────────────────────────────

    /**
     * Parse the current filter state from GET parameters.
     *
     * @return array{categories:string[], tags:string[], brands:string[], min_price:float, max_price:float, rating:int, in_stock:bool, attributes:array<string,string[]>}
     */
    public function parse_url_state(): array {
        if ( null !== $this->url_state ) {
            return $this->url_state;
        }

        $path_state = $this->parse_filter_path_from_query();
        if ( null !== $path_state ) {
            $this->url_state = $path_state;
            return $this->url_state;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter state from URL.
        $categories = isset( $_GET[ self::PARAM_CATEGORY ] )
            ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET[ self::PARAM_CATEGORY ] ) ) ) ) )
            : array();

        $tags = isset( $_GET[ self::PARAM_TAG ] )
            ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET[ self::PARAM_TAG ] ) ) ) ) )
            : array();

        $brands = isset( $_GET[ self::PARAM_BRAND ] )
            ? array_filter( array_map( 'sanitize_title', explode( ',', sanitize_text_field( wp_unslash( $_GET[ self::PARAM_BRAND ] ) ) ) ) )
            : array();

        $min_price = isset( $_GET[ self::PARAM_MIN_PRICE ] ) ? (float) $_GET[ self::PARAM_MIN_PRICE ] : -1.0;
        $max_price = isset( $_GET[ self::PARAM_MAX_PRICE ] ) ? (float) $_GET[ self::PARAM_MAX_PRICE ] : -1.0;

        $rating = isset( $_GET[ self::PARAM_RATING ] ) ? absint( $_GET[ self::PARAM_RATING ] ) : 0;
        if ( $rating < 1 || $rating > 5 ) {
            $rating = 0;
        }

        $in_stock = ! empty( $_GET[ self::PARAM_STOCK ] );

        $attributes = array();
        foreach ( $_GET as $key => $value ) {
            if ( ! is_string( $key ) || ! str_starts_with( $key, self::PARAM_ATTR_PREFIX ) ) {
                continue;
            }
            $attr_slug = sanitize_key( substr( $key, strlen( self::PARAM_ATTR_PREFIX ) ) );
            if ( '' === $attr_slug ) {
                continue;
            }
            $attr_vals = array_filter( array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $value ) ) ) ) );
            if ( ! empty( $attr_vals ) ) {
                $attributes[ $attr_slug ] = $attr_vals;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->url_state = array(
            'categories' => $categories,
            'tags'       => $tags,
            'brands'     => $brands,
            'min_price'  => $min_price,
            'max_price'  => $max_price,
            'rating'     => $rating,
            'in_stock'   => $in_stock,
            'attributes' => $attributes,
        );

        return $this->url_state;
    }

    /**
     * Count the number of individual active filter values.
     *
     * @param array $state Parsed URL state.
     * @return int
     */
    private function count_active_filters( array $state ): int {
        $count = count( $state['categories'] )
            + count( $state['brands'] )
            + count( $state['tags'] )
            + ( ( $state['min_price'] > 0 || $state['max_price'] > 0 ) ? 1 : 0 )
            + ( $state['rating'] > 0 ? 1 : 0 )
            + ( $state['in_stock'] ? 1 : 0 );

        foreach ( $state['attributes'] as $vals ) {
            $count += count( $vals );
        }

        return $count;
    }

    /**
     * Whether any URL-based filters are currently active.
     */
    public function has_active_filters(): bool {
        $s = $this->parse_url_state();
        return ! empty( $s['categories'] )
            || ! empty( $s['tags'] )
            || ! empty( $s['brands'] )
            || $s['min_price'] > 0
            || $s['max_price'] > 0
            || $s['rating'] > 0
            || $s['in_stock']
            || ! empty( $s['attributes'] );
    }

    // ──────────────────────────────────────────────
    //  URL filter → product query integration
    // ──────────────────────────────────────────────

    /**
     * Apply URL-based filters to the product query.
     *
     * Hooked into `tejcart_product_query_args` at priority 5 so it runs
     * before Product_Sort (priority 10).
     *
     * @param array $args Query arguments.
     * @return array Modified query arguments.
     */
    public function apply_url_filters( array $args ): array {
        if ( wp_doing_ajax() ) {
            return $args;
        }

        $state = $this->parse_url_state();
        if ( ! $this->has_active_filters() ) {
            return $args;
        }

        $clauses = $this->build_query_clauses( $state );

        if ( ! empty( $clauses['joins'] ) ) {
            $args['join_sql'] = ( $args['join_sql'] ?? '' ) . ' ' . implode( ' ', $clauses['joins'] );
        }

        if ( ! empty( $clauses['where'] ) ) {
            $extra_where      = implode( ' AND ', $clauses['where'] );
            $args['where_sql'] = ( $args['where_sql'] ?? '' ) . ' AND ' . $extra_where;
        }

        if ( ! empty( $clauses['values'] ) ) {
            $args['values'] = array_merge( $args['values'] ?? array(), $clauses['values'] );
        }

        return $args;
    }

    /**
     * Build JOIN / WHERE / values arrays for a given filter state.
     *
     * @param array  $state         Parsed filter state.
     * @param string $exclude_facet Facet type to exclude (for disjunctive counting).
     * @return array{joins:string[], where:string[], values:array}
     */
    private function build_query_clauses( array $state, string $exclude_facet = '' ): array {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';
        $rel_table  = $wpdb->prefix . 'tejcart_term_relationships';

        $joins   = array();
        $where   = array();
        $values  = array();
        $join_id = 100;

        $taxonomy_map = array(
            'category' => array( 'slugs' => $state['categories'], 'taxonomy' => Product_Taxonomy::CATEGORY_TAXONOMY, 'alias' => 'frel_cat' ),
            'tag'      => array( 'slugs' => $state['tags'],       'taxonomy' => Product_Taxonomy::TAG_TAXONOMY,      'alias' => 'frel_tag' ),
            'brand'    => array( 'slugs' => $state['brands'],     'taxonomy' => Product_Taxonomy::BRAND_TAXONOMY,    'alias' => 'frel_brand' ),
        );

        foreach ( $taxonomy_map as $facet_key => $cfg ) {
            if ( $exclude_facet === $facet_key || empty( $cfg['slugs'] ) ) {
                continue;
            }

            $tt_ids = $this->resolve_slugs_to_tt_ids( $cfg['slugs'], $cfg['taxonomy'] );
            if ( empty( $tt_ids ) ) {
                continue;
            }

            $placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
            $alias        = $cfg['alias'];
            $joins[]      = "INNER JOIN {$rel_table} AS {$alias} ON {$alias}.product_id = p.id";
            $where[]      = "{$alias}.term_taxonomy_id IN ({$placeholders})";
            $values       = array_merge( $values, $tt_ids );
        }

        if ( 'price' !== $exclude_facet ) {
            // The shopper's min/max are in the ACTIVE display currency, but
            // p.price/p.sale_price are stored in the BASE currency — reverse the
            // inputs to base before comparing. Passthrough on a single-currency
            // store.
            if ( $state['min_price'] > 0 ) {
                $where[]  = 'CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) >= %f';
                $values[] = (float) apply_filters( 'tejcart_amount_to_base', $state['min_price'] );
            }
            if ( $state['max_price'] > 0 ) {
                $where[]  = 'CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) <= %f';
                $values[] = (float) apply_filters( 'tejcart_amount_to_base', $state['max_price'] );
            }
        }

        if ( 'rating' !== $exclude_facet && $state['rating'] > 0 ) {
            $join_id++;
            $alias    = "fmeta_rating_{$join_id}";
            $joins[]  = "INNER JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = '_average_rating'";
            $where[]  = "CAST({$alias}.meta_value AS DECIMAL(3,2)) >= %f";
            $values[] = (float) $state['rating'];
        }

        if ( 'stock' !== $exclude_facet && $state['in_stock'] ) {
            $where[]  = 'p.stock_status = %s';
            $values[] = 'instock';
        }

        if ( ! empty( $state['attributes'] ) ) {
            foreach ( $state['attributes'] as $attr_key => $attr_values ) {
                if ( 'attr_' . $attr_key === $exclude_facet || empty( $attr_values ) ) {
                    continue;
                }

                $attr_key    = sanitize_key( $attr_key );
                $attr_values = array_map( 'sanitize_text_field', $attr_values );

                $join_id++;
                $alias        = "fmeta_attr_{$join_id}";
                $placeholders = implode( ',', array_fill( 0, count( $attr_values ), '%s' ) );
                // Pre-resolve the meta_key value so the JOIN has no placeholder — this
                // avoids parameter-ordering mismatches when consumers prepend their own
                // JOIN/WHERE values before merging $values.
                $safe_meta_key = $wpdb->prepare( '%s', '_attribute_' . $attr_key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $joins[]       = "INNER JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = {$safe_meta_key}";
                $where[]       = "{$alias}.meta_value IN ({$placeholders})";
                $values        = array_merge( $values, $attr_values );
            }
        }

        return array(
            'joins'  => $joins,
            'where'  => $where,
            'values' => $values,
        );
    }

    /**
     * Resolve an array of term slugs to term_taxonomy_ids.
     *
     * @param string[] $slugs    Term slugs.
     * @param string   $taxonomy Taxonomy name.
     * @return int[] term_taxonomy_ids.
     */
    private function resolve_slugs_to_tt_ids( array $slugs, string $taxonomy ): array {
        $tt_ids = array();
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $tt_ids[] = (int) $term->term_taxonomy_id;
            }
        }
        return $tt_ids;
    }

    // ──────────────────────────────────────────────
    //  SEO-clean filter URLs
    // ──────────────────────────────────────────────

    /**
     * Register rewrite rules for clean filter URLs.
     *
     * Generates URLs like /shop/filter/shoes/red/in-stock/ that map to
     * the shop page with a tejcart_filter_path query var.
     */
    public function register_filter_rewrite_rules(): void {
        if ( '' === get_option( 'permalink_structure' ) ) {
            return;
        }

        $shop_page_id = (int) get_option( 'tejcart_shop_page_id', 0 );
        if ( $shop_page_id <= 0 ) {
            return;
        }

        $shop_page = get_post( $shop_page_id );
        if ( ! $shop_page instanceof \WP_Post || 'publish' !== $shop_page->post_status ) {
            return;
        }

        $slug = $this->get_full_page_slug( $shop_page );
        if ( '' === $slug ) {
            return;
        }

        $quoted = preg_quote( $slug, '/' );
        $prefix = self::FILTER_PATH_PREFIX;

        add_rewrite_rule(
            '^' . $quoted . '/' . $prefix . '/(.+?)/?$',
            'index.php?page_id=' . $shop_page_id . '&' . self::FILTER_PATH_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^' . $quoted . '/' . $prefix . '/(.+?)/page/([0-9]+)/?$',
            'index.php?page_id=' . $shop_page_id . '&' . self::FILTER_PATH_VAR . '=$matches[1]&paged=$matches[2]',
            'top'
        );

        if ( (int) get_option( 'page_on_front', 0 ) === $shop_page_id ) {
            add_rewrite_rule(
                '^' . $prefix . '/(.+?)/?$',
                'index.php?page_id=' . $shop_page_id . '&' . self::FILTER_PATH_VAR . '=$matches[1]',
                'top'
            );

            add_rewrite_rule(
                '^' . $prefix . '/(.+?)/page/([0-9]+)/?$',
                'index.php?page_id=' . $shop_page_id . '&' . self::FILTER_PATH_VAR . '=$matches[1]&paged=$matches[2]',
                'top'
            );
        }
    }

    /**
     * Flush rewrite rules when the shop page setting changes.
     */
    public function flush_filter_rules(): void {
        $this->register_filter_rewrite_rules();
        flush_rewrite_rules( false );
    }

    /**
     * Invalidate the slug→facet lookup cache.
     */
    public function invalidate_slug_map_cache(): void {
        wp_cache_delete( self::SLUG_MAP_CACHE_KEY, 'tejcart' );
    }

    /**
     * Whether clean filter URLs are available.
     */
    public function has_clean_urls(): bool {
        if ( null !== $this->clean_urls ) {
            return $this->clean_urls;
        }

        $this->clean_urls = '' !== get_option( 'permalink_structure' )
            && absint( get_option( 'tejcart_shop_page_id', 0 ) ) > 0;

        return $this->clean_urls;
    }

    /**
     * Parse filter state from the tejcart_filter_path query var.
     *
     * @return array|null Parsed state or null if no path is set.
     */
    private function parse_filter_path_from_query(): ?array {
        $path = function_exists( 'get_query_var' ) ? (string) get_query_var( self::FILTER_PATH_VAR, '' ) : '';
        if ( '' === $path ) {
            return null;
        }

        return $this->parse_filter_path( $path );
    }

    /**
     * Convert a filter path string into a filter state array.
     *
     * Path format: {segment}/{segment}/... where each segment is one of:
     *   - A term slug (category, brand, tag) or comma-separated term slugs
     *   - An attribute value slug or comma-separated values
     *   - "in-stock" (reserved)
     *   - "rating-{1-5}" (reserved pattern)
     *   - "price-{min}-{max}" (reserved pattern)
     *
     * @param string $path Raw path string.
     * @return array Filter state array.
     */
    public function parse_filter_path( string $path ): array {
        $state = array(
            'categories' => array(),
            'tags'       => array(),
            'brands'     => array(),
            'min_price'  => -1.0,
            'max_price'  => -1.0,
            'rating'     => 0,
            'in_stock'   => false,
            'attributes' => array(),
        );

        $segments = array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s );
        if ( empty( $segments ) ) {
            return $state;
        }

        $slug_map = null;

        foreach ( $segments as $raw_segment ) {
            $raw_segment = preg_replace( '/[^a-zA-Z0-9\-_.,]/', '', $raw_segment );
            if ( '' === $raw_segment ) {
                continue;
            }

            if ( 'in-stock' === $raw_segment ) {
                $state['in_stock'] = true;
                continue;
            }

            if ( preg_match( '/^rating-([1-5])$/', $raw_segment, $m ) ) {
                $state['rating'] = (int) $m[1];
                continue;
            }

            if ( preg_match( '/^price-(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $raw_segment, $m ) ) {
                $state['min_price'] = (float) $m[1];
                $state['max_price'] = (float) $m[2];
                continue;
            }

            $values = array_filter( array_map( 'sanitize_title', explode( ',', $raw_segment ) ) );
            if ( empty( $values ) ) {
                continue;
            }

            if ( null === $slug_map ) {
                $slug_map = $this->build_slug_to_facet_map();
            }

            $first_val = $values[0];
            if ( ! isset( $slug_map[ $first_val ] ) ) {
                continue;
            }

            $facet = $slug_map[ $first_val ];

            if ( 'category' === $facet ) {
                $state['categories'] = array_merge( $state['categories'], $values );
            } elseif ( 'brand' === $facet ) {
                $state['brands'] = array_merge( $state['brands'], $values );
            } elseif ( 'tag' === $facet ) {
                $state['tags'] = array_merge( $state['tags'], $values );
            } elseif ( is_array( $facet ) && 'attribute' === $facet[0] ) {
                $attr_key = $facet[1];
                if ( ! isset( $state['attributes'][ $attr_key ] ) ) {
                    $state['attributes'][ $attr_key ] = array();
                }
                $state['attributes'][ $attr_key ] = array_merge(
                    $state['attributes'][ $attr_key ],
                    $values
                );
            }
        }

        $state['categories'] = array_values( array_unique( $state['categories'] ) );
        $state['brands']     = array_values( array_unique( $state['brands'] ) );
        $state['tags']       = array_values( array_unique( $state['tags'] ) );

        return $state;
    }

    /**
     * Build a map from slug → facet type for path segment resolution.
     *
     * Priority on collision: category > brand > tag > attribute.
     *
     * @return array<string, string|array{0:string,1:string}> slug → facet type.
     */
    public function build_slug_to_facet_map(): array {
        $cached = wp_cache_get( self::SLUG_MAP_CACHE_KEY, 'tejcart' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $map = array();

        $attrs = $this->get_filterable_attributes();
        foreach ( $attrs as $attr_name => $attr_values ) {
            $attr_key = sanitize_title( $attr_name );
            foreach ( $attr_values as $val ) {
                $slug = sanitize_title( $val );
                if ( '' !== $slug && ! isset( $map[ $slug ] ) ) {
                    $map[ $slug ] = array( 'attribute', $attr_key );
                }
            }
        }

        $taxonomies = array(
            'tag'      => Product_Taxonomy::TAG_TAXONOMY,
            'brand'    => Product_Taxonomy::BRAND_TAXONOMY,
            'category' => Product_Taxonomy::CATEGORY_TAXONOMY,
        );
        foreach ( $taxonomies as $facet_key => $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $map[ $term->slug ] = $facet_key;
            }
        }

        wp_cache_set( self::SLUG_MAP_CACHE_KEY, $map, 'tejcart', HOUR_IN_SECONDS );

        return $map;
    }

    /**
     * Build a clean filter URL path from a filter state.
     *
     * @param array $state Filter state.
     * @return string Path segment string (without leading/trailing slashes).
     */
    public function build_filter_path( array $state ): string {
        $segments = array();

        if ( ! empty( $state['categories'] ) ) {
            $segments[] = implode( ',', array_map( 'sanitize_title', $state['categories'] ) );
        }
        if ( ! empty( $state['brands'] ) ) {
            $segments[] = implode( ',', array_map( 'sanitize_title', $state['brands'] ) );
        }
        if ( ! empty( $state['tags'] ) ) {
            $segments[] = implode( ',', array_map( 'sanitize_title', $state['tags'] ) );
        }

        foreach ( $state['attributes'] as $attr_key => $vals ) {
            if ( ! empty( $vals ) ) {
                $segments[] = implode( ',', array_map( 'sanitize_title', $vals ) );
            }
        }

        if ( $state['in_stock'] ) {
            $segments[] = 'in-stock';
        }
        if ( $state['rating'] > 0 ) {
            $segments[] = 'rating-' . $state['rating'];
        }
        if ( $state['min_price'] > 0 || $state['max_price'] > 0 ) {
            $min = $state['min_price'] > 0 ? $state['min_price'] : 0;
            $max = $state['max_price'] > 0 ? $state['max_price'] : 0;
            if ( $min > 0 || $max > 0 ) {
                $min_str = floor( $min ) == $min ? (string) (int) $min : (string) $min;
                $max_str = floor( $max ) == $max ? (string) (int) $max : (string) $max;
                $segments[] = 'price-' . $min_str . '-' . $max_str;
            }
        }

        return implode( '/', $segments );
    }

    /**
     * Resolve a page's full URL slug including parent hierarchy.
     *
     * @param \WP_Post $page The page post.
     * @return string
     */
    private function get_full_page_slug( \WP_Post $page ): string {
        $slugs = array();

        $current = $page;
        while ( $current instanceof \WP_Post ) {
            if ( '' === $current->post_name ) {
                return '';
            }
            array_unshift( $slugs, $current->post_name );
            if ( (int) $current->post_parent <= 0 ) {
                break;
            }
            $current = get_post( (int) $current->post_parent );
        }

        return implode( '/', $slugs );
    }

    // ──────────────────────────────────────────────
    //  Faceted sidebar rendering
    // ──────────────────────────────────────────────

    /**
     * Render the full faceted filter sidebar.
     *
     * Server-side rendered — works without JavaScript. The JS layer
     * enhances with AJAX updates and History API URL management.
     *
     * @return string Sidebar HTML.
     */
    public function render_sidebar(): string {
        $state  = $this->parse_url_state();
        $counts = $this->get_facet_counts( $state );

        $shop_url     = $this->get_shop_base_url();
        $active_count = $this->count_active_filters( $state );

        ob_start();
        ?>
        <aside class="tejcart-facets" role="complementary" aria-label="<?php esc_attr_e( 'Product filters', 'tejcart' ); ?>">
            <div class="tejcart-facets-progress" aria-hidden="true"></div>
            <div class="tejcart-facets-header">
                <div class="tejcart-facets-header-row">
                    <div class="tejcart-facets-header-left">
                        <svg class="tejcart-facets-header-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <h2 class="tejcart-facets-title"><?php esc_html_e( 'Filters', 'tejcart' ); ?></h2>
                    </div>
                    <div class="tejcart-facets-header-right">
                        <?php if ( $active_count > 0 ) : ?>
                            <a href="<?php echo esc_url( $shop_url ); ?>" class="tejcart-facets-clear"><?php esc_html_e( 'Clear all', 'tejcart' ); ?></a>
                        <?php endif; ?>
                        <button type="button" class="tejcart-facets-close" aria-label="<?php esc_attr_e( 'Close filters', 'tejcart' ); ?>">&times;</button>
                    </div>
                </div>
                <span class="tejcart-facets-result-count" data-tejcart-result-count aria-live="polite"></span>
            </div>

            <form class="tejcart-facets-form" id="tejcart-facets-form" method="get" action="<?php echo esc_url( $shop_url ); ?>">
                <?php
                // Preserve non-filter query params (sort, search, etc.)
                $this->render_preserved_params();

                // Category facet
                $this->render_category_facet( $state, $counts );

                // Brand facet
                $this->render_taxonomy_facet(
                    'brand',
                    __( 'Brand', 'tejcart' ),
                    Product_Taxonomy::BRAND_TAXONOMY,
                    self::PARAM_BRAND,
                    $state['brands'],
                    $counts['brands'] ?? array()
                );

                // Tag facet
                $this->render_taxonomy_facet(
                    'tag',
                    __( 'Tags', 'tejcart' ),
                    Product_Taxonomy::TAG_TAXONOMY,
                    self::PARAM_TAG,
                    $state['tags'],
                    $counts['tags'] ?? array()
                );

                // Price facet
                $this->render_price_facet( $state, $counts );

                // Attribute facets
                $this->render_attribute_facets( $state, $counts );

                // Rating facet
                $this->render_rating_facet( $state, $counts );

                // Stock facet
                $this->render_stock_facet( $state, $counts );
                ?>

                <div class="tejcart-facets-actions">
                    <button type="submit" class="tejcart-facets-apply tejcart-button">
                        <?php esc_html_e( 'Apply Filters', 'tejcart' ); ?>
                    </button>
                </div>
            </form>
            <div class="tejcart-facets-drawer-footer">
                <a href="<?php echo esc_url( $shop_url ); ?>" class="tejcart-facets-drawer-clear">
                    <?php esc_html_e( 'Clear', 'tejcart' ); ?>
                </a>
                <button type="submit" form="tejcart-facets-form" class="tejcart-facets-drawer-apply">
                    <?php esc_html_e( 'Show', 'tejcart' ); ?> <span data-tejcart-result-count></span> <?php esc_html_e( 'results', 'tejcart' ); ?>
                </button>
            </div>
        </aside>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render removable chips for each active filter.
     *
     * @return string Active filter chips HTML.
     */
    public function render_active_filters(): string {
        $state = $this->parse_url_state();
        if ( ! $this->has_active_filters() ) {
            return '';
        }

        $chips = array();

        foreach ( $state['categories'] as $slug ) {
            $term = get_term_by( 'slug', $slug, Product_Taxonomy::CATEGORY_TAXONOMY );
            if ( $term && ! is_wp_error( $term ) ) {
                $chips[] = array(
                    'label'  => $term->name,
                    'url'    => $this->build_filter_url( $state, array( 'remove_category' => $slug ) ),
                    'facet'  => 'category',
                );
            }
        }

        foreach ( $state['brands'] as $slug ) {
            $term = get_term_by( 'slug', $slug, Product_Taxonomy::BRAND_TAXONOMY );
            if ( $term && ! is_wp_error( $term ) ) {
                $chips[] = array(
                    'label'  => $term->name,
                    'url'    => $this->build_filter_url( $state, array( 'remove_brand' => $slug ) ),
                    'facet'  => 'brand',
                );
            }
        }

        foreach ( $state['tags'] as $slug ) {
            $term = get_term_by( 'slug', $slug, Product_Taxonomy::TAG_TAXONOMY );
            if ( $term && ! is_wp_error( $term ) ) {
                $chips[] = array(
                    'label'  => $term->name,
                    'url'    => $this->build_filter_url( $state, array( 'remove_tag' => $slug ) ),
                    'facet'  => 'tag',
                );
            }
        }

        if ( $state['min_price'] > 0 || $state['max_price'] > 0 ) {
            $price_label = '';
            if ( $state['min_price'] > 0 && $state['max_price'] > 0 ) {
                $price_label = sprintf(
                    /* translators: 1: min price, 2: max price */
                    __( '%1$s – %2$s', 'tejcart' ),
                    tejcart_price( $state['min_price'] ),
                    tejcart_price( $state['max_price'] )
                );
            } elseif ( $state['min_price'] > 0 ) {
                /* translators: %s: minimum price */
                $price_label = sprintf( __( 'From %s', 'tejcart' ), tejcart_price( $state['min_price'] ) );
            } else {
                /* translators: %s: maximum price */
                $price_label = sprintf( __( 'Up to %s', 'tejcart' ), tejcart_price( $state['max_price'] ) );
            }
            $chips[] = array(
                'label'  => $price_label,
                'url'    => $this->build_filter_url( $state, array( 'remove_price' => true ) ),
                'facet'  => 'price',
            );
        }

        if ( $state['rating'] > 0 ) {
            $chips[] = array(
                'label'  => sprintf(
                    /* translators: %d: star rating */
                    __( '%d★ & up', 'tejcart' ),
                    $state['rating']
                ),
                'url'    => $this->build_filter_url( $state, array( 'remove_rating' => true ) ),
                'facet'  => 'rating',
            );
        }

        if ( $state['in_stock'] ) {
            $chips[] = array(
                'label'  => __( 'In stock', 'tejcart' ),
                'url'    => $this->build_filter_url( $state, array( 'remove_stock' => true ) ),
                'facet'  => 'stock',
            );
        }

        foreach ( $state['attributes'] as $attr_key => $attr_values ) {
            $attr_label = ucwords( str_replace( array( '_', '-' ), ' ', $attr_key ) );
            foreach ( $attr_values as $val ) {
                $chips[] = array(
                    'label'  => $attr_label . ': ' . $val,
                    'url'    => $this->build_filter_url( $state, array( 'remove_attr' => array( $attr_key, $val ) ) ),
                    'facet'  => 'attribute',
                );
            }
        }

        if ( empty( $chips ) ) {
            return '';
        }

        $shop_url = $this->get_shop_base_url();

        ob_start();
        ?>
        <div class="tejcart-active-filters" aria-label="<?php esc_attr_e( 'Active filters', 'tejcart' ); ?>">
            <span class="tejcart-active-filters-label"><?php esc_html_e( 'Filtered by:', 'tejcart' ); ?></span>
            <?php foreach ( $chips as $chip ) : ?>
                <a href="<?php echo esc_url( $chip['url'] ); ?>"
                   class="tejcart-active-filter-chip"
                   data-facet="<?php echo esc_attr( $chip['facet'] ); ?>"
                   aria-label="<?php
                   /* translators: %s: filter label */
                   echo esc_attr( sprintf( __( 'Remove filter: %s', 'tejcart' ), $chip['label'] ) ); ?>">
                    <span class="tejcart-active-filter-label"><?php echo esc_html( $chip['label'] ); ?></span>
                    <span class="tejcart-active-filter-remove" aria-hidden="true">&times;</span>
                </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url( $shop_url ); ?>" class="tejcart-active-filters-clear">
                <?php esc_html_e( 'Clear all', 'tejcart' ); ?>
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ──────────────────────────────────────────────
    //  SEO: canonical + robots
    // ──────────────────────────────────────────────

    /**
     * Emit canonical and robots meta for filtered pages.
     *
     * Filtered pages point their canonical back to the base shop page
     * to avoid duplicate-content indexing. A `noindex, follow` meta
     * tag prevents thin filter pages from diluting crawl budget.
     */
    public function render_seo_tags(): void {
        if ( ! $this->is_on_shop_page() || ! $this->has_active_filters() ) {
            return;
        }

        $state = $this->parse_url_state();
        $has_clean_path = '' !== (string) get_query_var( self::FILTER_PATH_VAR, '' );

        if ( $has_clean_path ) {
            $canonical = trailingslashit( $this->get_shop_base_url() )
                . self::FILTER_PATH_PREFIX . '/'
                . $this->build_filter_path( $state ) . '/';
            echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
            echo '<meta name="robots" content="noindex, follow" />' . "\n";
        } else {
            $shop_url = $this->get_shop_base_url();
            echo '<link rel="canonical" href="' . esc_url( $shop_url ) . '" />' . "\n";
            echo '<meta name="robots" content="noindex, follow" />' . "\n";
        }
    }

    // ──────────────────────────────────────────────
    //  Facet count computation
    // ──────────────────────────────────────────────

    /**
     * Compute product counts for every facet value.
     *
     * Uses disjunctive counting: counts for a facet are computed with
     * that facet's own filter removed, so users can see how many
     * products each option would yield.
     *
     * @param array $state Parsed URL state.
     * @return array Facet counts keyed by facet type.
     */
    public function get_facet_counts( array $state ): array {
        $cache_key = md5( wp_json_encode( $state ) );
        $cached    = wp_cache_get( $cache_key, self::FACET_CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $price_range = $this->get_price_range( $state );

        $counts = array(
            'categories'      => $this->count_taxonomy_facet( $state, 'category', Product_Taxonomy::CATEGORY_TAXONOMY ),
            'brands'          => $this->count_taxonomy_facet( $state, 'brand', Product_Taxonomy::BRAND_TAXONOMY ),
            'tags'            => $this->count_taxonomy_facet( $state, 'tag', Product_Taxonomy::TAG_TAXONOMY ),
            'attributes'      => $this->count_all_attribute_facets( $state ),
            'ratings'         => $this->count_rating_facet( $state ),
            'stock'           => $this->count_stock_facet( $state ),
            'price_range'     => $price_range,
            'price_histogram' => $this->get_price_histogram( $state, $price_range ),
        );

        wp_cache_set( $cache_key, $counts, self::FACET_CACHE_KEY, 5 * MINUTE_IN_SECONDS );

        return $counts;
    }

    /**
     * Count products per term for a taxonomy facet.
     *
     * @param array  $state     Filter state.
     * @param string $facet_key Facet key to exclude for disjunctive counting.
     * @param string $taxonomy  WordPress taxonomy.
     * @return array<int, int> term_id => count.
     */
    private function count_taxonomy_facet( array $state, string $facet_key, string $taxonomy ): array {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';
        $rel_table      = $wpdb->prefix . 'tejcart_term_relationships';

        $clauses = $this->build_query_clauses( $state, $facet_key );

        $base_where  = array( 'p.status = %s', "p.type <> 'variation'" );
        $base_values = array( 'publish' );

        if ( \TejCart\Product\Stock_Display::hide_out_of_stock() && 'stock' !== $facet_key ) {
            $base_where[]  = 'p.stock_status = %s';
            $base_values[] = 'instock';
        }

        $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

        $all_where  = array_merge( $base_where, $clauses['where'] );
        $all_values = array_merge( $base_values, $clauses['values'] );

        $join_sql  = implode( ' ', $clauses['joins'] );
        $where_sql = implode( ' AND ', $all_where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $safe_taxonomy = $wpdb->prepare( '%s', $taxonomy ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.term_id, COUNT(DISTINCT p.id) AS cnt
                 FROM {$products_table} AS p
                 {$join_sql}
                 INNER JOIN {$rel_table} AS fcnt_rel ON fcnt_rel.product_id = p.id
                 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = fcnt_rel.term_taxonomy_id AND tt.taxonomy = {$safe_taxonomy}
                 WHERE {$where_sql}
                 GROUP BY tt.term_id",
                $all_values
            ),
            ARRAY_A
        );
        // phpcs:enable

        $result = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $result[ (int) $row['term_id'] ] = (int) $row['cnt'];
            }
        }

        return $result;
    }

    /**
     * Count products per attribute value across all filterable attributes.
     *
     * @param array $state Filter state.
     * @return array<string, array<string, int>> attr_slug => (value => count).
     */
    private function count_all_attribute_facets( array $state ): array {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';
        $meta_table     = $wpdb->prefix . 'tejcart_product_meta';

        $attrs  = $this->get_filterable_attributes();
        $result = array();

        foreach ( $attrs as $attr_name => $attr_values ) {
            $attr_key = sanitize_title( $attr_name );
            $clauses  = $this->build_query_clauses( $state, 'attr_' . $attr_key );

            $base_where  = array( 'p.status = %s', "p.type <> 'variation'" );
            $base_values = array( 'publish' );

            if ( \TejCart\Product\Stock_Display::hide_out_of_stock() ) {
                $base_where[]  = 'p.stock_status = %s';
                $base_values[] = 'instock';
            }

            $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

            $all_where  = array_merge( $base_where, $clauses['where'] );
            $all_values = array_merge( $base_values, $clauses['values'] );

            $join_sql  = implode( ' ', $clauses['joins'] );
            $where_sql = implode( ' AND ', $all_where );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $safe_attr_key = $wpdb->prepare( '%s', '_attribute_' . $attr_key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT acnt.meta_value, COUNT(DISTINCT p.id) AS cnt
                     FROM {$products_table} AS p
                     {$join_sql}
                     INNER JOIN {$meta_table} AS acnt ON acnt.product_id = p.id AND acnt.meta_key = {$safe_attr_key} AND acnt.meta_value != ''
                     WHERE {$where_sql}
                     GROUP BY acnt.meta_value
                     ORDER BY acnt.meta_value",
                    $all_values
                ),
                ARRAY_A
            );
            // phpcs:enable

            $attr_counts = array();
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $attr_counts[ $row['meta_value'] ] = (int) $row['cnt'];
                }
            }
            $result[ $attr_key ] = $attr_counts;
        }

        return $result;
    }

    /**
     * Count products per rating bucket (5, 4, 3, 2, 1).
     *
     * @param array $state Filter state.
     * @return array<int, int> min_stars => count of products with >= that rating.
     */
    private function count_rating_facet( array $state ): array {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';
        $meta_table     = $wpdb->prefix . 'tejcart_product_meta';

        $clauses = $this->build_query_clauses( $state, 'rating' );

        $base_where  = array( 'p.status = %s', "p.type <> 'variation'" );
        $base_values = array( 'publish' );

        if ( \TejCart\Product\Stock_Display::hide_out_of_stock() ) {
            $base_where[]  = 'p.stock_status = %s';
            $base_values[] = 'instock';
        }

        $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

        $all_where  = array_merge( $base_where, $clauses['where'] );
        $all_values = array_merge( $base_values, $clauses['values'] );

        $join_sql  = implode( ' ', $clauses['joins'] );
        $where_sql = implode( ' AND ', $all_where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT FLOOR(CAST(rcnt.meta_value AS DECIMAL(3,2))) AS bucket, COUNT(DISTINCT p.id) AS cnt
                 FROM {$products_table} AS p
                 {$join_sql}
                 INNER JOIN {$meta_table} AS rcnt ON rcnt.product_id = p.id AND rcnt.meta_key = '_average_rating'
                 WHERE {$where_sql}
                 GROUP BY bucket",
                array_merge( $base_values, $clauses['values'] )
            ),
            ARRAY_A
        );
        // phpcs:enable

        $buckets = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $b = (int) $row['bucket'];
                if ( $b >= 1 && $b <= 5 ) {
                    $buckets[ $b ] = (int) $row['cnt'];
                }
            }
        }

        // Convert to cumulative "X & up" counts.
        $cumulative = array();
        $running    = 0;
        for ( $s = 5; $s >= 1; $s-- ) {
            $running         += $buckets[ $s ] ?? 0;
            $cumulative[ $s ] = $running;
        }

        return $cumulative;
    }

    /**
     * Count in-stock vs out-of-stock products.
     *
     * @param array $state Filter state.
     * @return array{instock:int, outofstock:int}
     */
    private function count_stock_facet( array $state ): array {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        $clauses = $this->build_query_clauses( $state, 'stock' );

        $base_where  = array( 'p.status = %s', "p.type <> 'variation'" );
        $base_values = array( 'publish' );

        $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

        $all_where  = array_merge( $base_where, $clauses['where'] );
        $all_values = array_merge( $base_values, $clauses['values'] );

        $join_sql  = implode( ' ', $clauses['joins'] );
        $where_sql = implode( ' AND ', $all_where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.stock_status, COUNT(DISTINCT p.id) AS cnt
                 FROM {$products_table} AS p
                 {$join_sql}
                 WHERE {$where_sql}
                 GROUP BY p.stock_status",
                $all_values
            ),
            ARRAY_A
        );
        // phpcs:enable

        $result = array( 'instock' => 0, 'outofstock' => 0 );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $status = $row['stock_status'];
                if ( isset( $result[ $status ] ) ) {
                    $result[ $status ] = (int) $row['cnt'];
                }
            }
        }

        return $result;
    }

    /**
     * Get min/max price from products matching all filters except price.
     *
     * @param array $state Filter state.
     * @return array{min:float, max:float}
     */
    private function get_price_range( array $state ): array {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        $clauses = $this->build_query_clauses( $state, 'price' );

        $base_where  = array( 'p.status = %s', "p.type <> 'variation'", 'COALESCE(p.sale_price, p.price) > 0' );
        $base_values = array( 'publish' );

        if ( \TejCart\Product\Stock_Display::hide_out_of_stock() ) {
            $base_where[]  = 'p.stock_status = %s';
            $base_values[] = 'instock';
        }

        $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

        $all_where  = array_merge( $base_where, $clauses['where'] );
        $all_values = array_merge( $base_values, $clauses['values'] );

        $join_sql  = implode( ' ', $clauses['joins'] );
        $where_sql = implode( ' AND ', $all_where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT MIN(CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2))) AS min_price,
                        MAX(CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2))) AS max_price
                 FROM {$products_table} AS p
                 {$join_sql}
                 WHERE {$where_sql}",
                $all_values
            ),
            ARRAY_A
        );
        // phpcs:enable

        return array(
            'min' => (float) ( $row['min_price'] ?? 0 ),
            'max' => (float) ( $row['max_price'] ?? 0 ),
        );
    }

    /**
     * Compute a price histogram for the current filter state.
     *
     * Returns an array of HISTOGRAM_BUCKETS entries, each with the
     * product count in that price range. The buckets span the full
     * min–max price range with equal width.
     *
     * @param array                $state       Filter state.
     * @param array{min:float,max:float} $price_range Pre-computed price range.
     * @return array{buckets:int[], bucket_width:float, max_count:int}
     */
    private function get_price_histogram( array $state, array $price_range ): array {
        $empty = array( 'buckets' => array(), 'bucket_width' => 0.0, 'max_count' => 0 );

        $range_min = $price_range['min'];
        $range_max = $price_range['max'];
        if ( $range_max <= $range_min || $range_max <= 0 ) {
            return $empty;
        }

        $num_buckets  = self::HISTOGRAM_BUCKETS;
        $bucket_width = ( $range_max - $range_min ) / $num_buckets;
        if ( $bucket_width <= 0 ) {
            return $empty;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        $clauses = $this->build_query_clauses( $state, 'price' );

        $base_where  = array( 'p.status = %s', "p.type <> 'variation'", 'COALESCE(p.sale_price, p.price) > 0' );
        $base_values = array( 'publish' );

        if ( \TejCart\Product\Stock_Display::hide_out_of_stock() ) {
            $base_where[]  = 'p.stock_status = %s';
            $base_values[] = 'instock';
        }

        $base_where[] = "p.catalog_visibility IN ('visible','catalog','')";

        $all_where  = array_merge( $base_where, $clauses['where'] );
        $all_values = array_merge( $base_values, $clauses['values'] );

        $join_sql  = implode( ' ', $clauses['joins'] );
        $where_sql = implode( ' AND ', $all_where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT LEAST(FLOOR((CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) - %f) / %f), %d) AS bucket_idx,
                        COUNT(DISTINCT p.id) AS cnt
                 FROM {$products_table} AS p
                 {$join_sql}
                 WHERE {$where_sql}
                   AND CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) >= %f
                   AND CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) <= %f
                 GROUP BY bucket_idx
                 ORDER BY bucket_idx",
                array_merge(
                    array( $range_min, $bucket_width, $num_buckets - 1 ),
                    $all_values,
                    array( $range_min, $range_max )
                )
            ),
            ARRAY_A
        );
        // phpcs:enable

        $buckets   = array_fill( 0, $num_buckets, 0 );
        $max_count = 0;

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $idx = max( 0, min( $num_buckets - 1, (int) $row['bucket_idx'] ) );
                $buckets[ $idx ] = (int) $row['cnt'];
                if ( $buckets[ $idx ] > $max_count ) {
                    $max_count = $buckets[ $idx ];
                }
            }
        }

        return array(
            'buckets'      => $buckets,
            'bucket_width' => $bucket_width,
            'max_count'    => $max_count,
        );
    }

    // ──────────────────────────────────────────────
    //  Individual facet section renderers
    // ──────────────────────────────────────────────

    private function render_category_facet( array $state, array $counts ): void {
        $terms = get_terms( array(
            'taxonomy'   => Product_Taxonomy::CATEGORY_TAXONOMY,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_counts = $counts['categories'] ?? array();
        $active      = $state['categories'];
        $has_children = false;

        foreach ( $terms as $term ) {
            if ( (int) $term->parent > 0 ) {
                $has_children = true;
                break;
            }
        }

        if ( $has_children ) {
            $tree_html = $this->build_category_tree_html( $terms, $term_counts, $active, 0 );
            if ( '' === $tree_html ) {
                return;
            }
            ?>
            <fieldset class="tejcart-facet-fieldset">
                <legend class="screen-reader-text"><?php esc_html_e( 'Categories', 'tejcart' ); ?></legend>
                <details class="tejcart-facet-section" open>
                    <summary class="tejcart-facet-heading" aria-expanded="true">
                        <span class="tejcart-facet-heading-text"><?php esc_html_e( 'Categories', 'tejcart' ); ?></span>
                        <?php if ( ! empty( $active ) ) : ?>
                            <span class="tejcart-facet-heading-count"><?php echo esc_html( (string) count( $active ) ); ?></span>
                        <?php endif; ?>
                        <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="tejcart-facet-body">
                        <ul class="tejcart-facet-list" role="list">
                            <?php echo $tree_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in build_category_tree_html. ?>
                        </ul>
                    </div>
                </details>
            </fieldset>
            <?php
            return;
        }

        $this->render_taxonomy_facet(
            'category',
            __( 'Categories', 'tejcart' ),
            Product_Taxonomy::CATEGORY_TAXONOMY,
            self::PARAM_CATEGORY,
            $active,
            $term_counts
        );
    }

    /**
     * Recursive category tree builder.
     *
     * @param \WP_Term[] $terms      All category terms.
     * @param array      $counts     term_id => count.
     * @param string[]   $active     Active category slugs.
     * @param int        $parent_id  Parent term ID.
     * @param int        $depth      Current depth.
     * @return string HTML list items.
     */
    private function build_category_tree_html( array $terms, array $counts, array $active, int $parent_id, int $depth = 0 ): string {
        if ( $depth > 5 ) {
            return '';
        }

        $html = '';
        foreach ( $terms as $term ) {
            if ( (int) $term->parent !== $parent_id ) {
                continue;
            }

            $count      = $counts[ $term->term_id ] ?? 0;
            $is_checked = in_array( $term->slug, $active, true );
            $children   = $this->build_category_tree_html( $terms, $counts, $active, $term->term_id, $depth + 1 );
            $has_children = '' !== $children;

            $html .= '<li class="tejcart-facet-item' . ( $has_children ? ' has-children' : '' ) . '">';
            $html .= '<label class="tejcart-facet-label">';
            $html .= '<input type="checkbox" class="screen-reader-text" name="' . esc_attr( self::PARAM_CATEGORY ) . '[]" value="' . esc_attr( $term->slug ) . '"' . ( $is_checked ? ' checked' : '' ) . ' />';
            $html .= '<span class="tejcart-facet-check" aria-hidden="true"></span>';
            $html .= '<span class="tejcart-facet-text">' . esc_html( $term->name ) . '</span>';
            $html .= '<span class="tejcart-facet-count">' . esc_html( (string) $count ) . '</span>';
            $html .= '</label>';

            if ( $has_children ) {
                $html .= '<ul class="tejcart-facet-children" role="list">' . $children . '</ul>';
            }

            $html .= '</li>';
        }

        return $html;
    }

    /**
     * Render a flat taxonomy facet (brands, tags).
     */
    private function render_taxonomy_facet( string $facet_key, string $label, string $taxonomy, string $param_name, array $active_slugs, array $term_counts ): void {
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $visible = array();
        foreach ( $terms as $term ) {
            $cnt = $term_counts[ $term->term_id ] ?? 0;
            if ( $cnt > 0 || in_array( $term->slug, $active_slugs, true ) ) {
                $visible[] = $term;
            }
        }
        if ( empty( $visible ) ) {
            return;
        }

        usort( $visible, function ( $a, $b ) use ( $term_counts, $active_slugs ): int {
            $a_active = in_array( $a->slug, $active_slugs, true ) ? 1 : 0;
            $b_active = in_array( $b->slug, $active_slugs, true ) ? 1 : 0;
            if ( $a_active !== $b_active ) {
                return $b_active - $a_active;
            }
            return ( $term_counts[ $b->term_id ] ?? 0 ) - ( $term_counts[ $a->term_id ] ?? 0 );
        } );

        $total        = count( $visible );
        $limit        = self::FACET_INITIAL_LIMIT;
        $has_overflow = $total > $limit;
        $has_search   = $total >= self::FACET_SEARCH_THRESHOLD;
        $overflow_count = 0;

        ?>
        <fieldset class="tejcart-facet-fieldset">
            <legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
            <?php $is_default_open = 'category' === $facet_key || ! empty( $active_slugs ); ?>
            <details class="tejcart-facet-section"<?php echo $is_default_open ? ' open' : ''; ?>>
                <summary class="tejcart-facet-heading" aria-expanded="<?php echo $is_default_open ? 'true' : 'false'; ?>">
                    <span class="tejcart-facet-heading-text"><?php echo esc_html( $label ); ?></span>
                    <?php if ( ! empty( $active_slugs ) ) : ?>
                        <span class="tejcart-facet-heading-count"><?php echo esc_html( (string) count( $active_slugs ) ); ?></span>
                    <?php endif; ?>
                    <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="tejcart-facet-body">
                    <?php if ( $has_search ) : ?>
                        <input type="text" class="tejcart-facet-search"
                               placeholder="<?php echo esc_attr( sprintf(
                                   /* translators: %s: facet label (e.g. "brands") */
                                   __( 'Search %s…', 'tejcart' ),
                                   strtolower( $label )
                               ) ); ?>"
                               aria-label="<?php
                               /* translators: %s: facet label */
                               echo esc_attr( sprintf( __( 'Search %s', 'tejcart' ), $label ) ); ?>" />
                    <?php endif; ?>
                    <ul class="tejcart-facet-list" role="list">
                        <?php
                        $i = 0;
                        foreach ( $visible as $term ) :
                            $cnt         = $term_counts[ $term->term_id ] ?? 0;
                            $is_checked  = in_array( $term->slug, $active_slugs, true );
                            $is_overflow = ! $is_checked && $i >= $limit;
                            if ( $is_overflow ) {
                                $overflow_count++;
                            }
                            $i++;
                        ?>
                            <li class="tejcart-facet-item<?php echo $is_overflow ? ' is-overflow' : ''; ?>">
                                <label class="tejcart-facet-label">
                                    <input type="checkbox" class="screen-reader-text" name="<?php echo esc_attr( $param_name ); ?>[]"
                                           value="<?php echo esc_attr( $term->slug ); ?>"
                                           <?php checked( $is_checked ); ?> />
                                    <span class="tejcart-facet-check" aria-hidden="true"></span>
                                    <span class="tejcart-facet-text"><?php echo esc_html( $term->name ); ?></span>
                                    <span class="tejcart-facet-count"><?php echo esc_html( (string) $cnt ); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ( $has_overflow && $overflow_count > 0 ) : ?>
                        <button type="button" class="tejcart-facet-toggle-more"
                                data-more="<?php echo esc_attr( sprintf(
                                    /* translators: %d: total number of items */
                                    __( 'Show all (%d)', 'tejcart' ),
                                    $total
                                ) ); ?>"
                                data-less="<?php esc_attr_e( 'Show less', 'tejcart' ); ?>">
                            <?php
                            /* translators: %d: total number of items */
                            echo esc_html( sprintf( __( 'Show all (%d)', 'tejcart' ), $total ) ); ?>
                            <span class="tejcart-facet-toggle-chevron" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                </div>
            </details>
        </fieldset>
        <?php
    }

    private function render_price_facet( array $state, array $counts ): void {
        $range = $counts['price_range'] ?? array( 'min' => 0, 'max' => 0 );
        if ( $range['max'] <= 0 ) {
            return;
        }

        // price_range is computed from the BASE-currency p.price columns.
        // Convert the whole facet UI into the ACTIVE display currency so the
        // slider bounds, histogram, and labels are in the shopper's currency —
        // and on the same scale as the selected min/max ($state, already in the
        // active currency). The shopper's posted values are reversed back to
        // base for the SQL comparison (see build_query_clauses + the AJAX
        // handler). Passthrough on a single-currency store.
        $range['min'] = (float) apply_filters( 'tejcart_amount_to_currency', $range['min'], tejcart_get_currency() );
        $range['max'] = (float) apply_filters( 'tejcart_amount_to_currency', $range['max'], tejcart_get_currency() );

        $current_min = $state['min_price'] > 0 ? $state['min_price'] : $range['min'];
        $current_max = $state['max_price'] > 0 ? $state['max_price'] : $range['max'];
        $step        = $range['max'] > 100 ? 1 : 0.01;
        $histogram   = $counts['price_histogram'] ?? array( 'buckets' => array(), 'max_count' => 0, 'bucket_width' => 0 );

        // Re-derive the histogram bucket width from the (now active-currency)
        // range so the bar boundaries and their labels are denominated in the
        // active currency too. Bar COUNTS are currency-independent; only the
        // boundaries needed rescaling.
        $tejcart_bucket_count = ( isset( $histogram['buckets'] ) && is_array( $histogram['buckets'] ) ) ? count( $histogram['buckets'] ) : 0;
        if ( $tejcart_bucket_count > 0 && $range['max'] > $range['min'] ) {
            $histogram['bucket_width'] = ( $range['max'] - $range['min'] ) / $tejcart_bucket_count;
        }

        ?>
        <fieldset class="tejcart-facet-fieldset">
            <legend class="screen-reader-text"><?php esc_html_e( 'Price', 'tejcart' ); ?></legend>
            <details class="tejcart-facet-section" open>
                <summary class="tejcart-facet-heading" aria-expanded="true">
                    <span class="tejcart-facet-heading-text"><?php esc_html_e( 'Price', 'tejcart' ); ?></span>
                    <?php
                    if ( $state['min_price'] > 0 || $state['max_price'] > 0 ) :
                        $badge_min = $state['min_price'] > 0 ? $state['min_price'] : $range['min'];
                        $badge_max = $state['max_price'] > 0 ? $state['max_price'] : $range['max'];
                        ?>
                        <span class="tejcart-facet-heading-count tejcart-facet-heading-count--range"><?php echo esc_html( tejcart_price( $badge_min ) . '–' . tejcart_price( $badge_max ) ); ?></span>
                    <?php endif; ?>
                    <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="tejcart-facet-body">
                    <div class="tejcart-facet-price"
                         data-min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                         data-max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                         data-step="<?php echo esc_attr( (string) $step ); ?>">
                        <?php if ( ! empty( $histogram['buckets'] ) && $histogram['max_count'] > 0 ) : ?>
                        <div class="tejcart-facet-price-histogram" aria-hidden="true">
                            <?php
                            $floor_min   = floor( $range['min'] );
                            $ceil_max    = ceil( $range['max'] );
                            $total_range = $ceil_max - $floor_min;

                            foreach ( $histogram['buckets'] as $idx => $count ) :
                                $pct       = (int) round( ( $count / $histogram['max_count'] ) * 100 );
                                $bar_min   = $range['min'] + ( $idx * $histogram['bucket_width'] );
                                $bar_max   = $bar_min + $histogram['bucket_width'];
                                $is_active = $bar_max >= $current_min && $bar_min <= $current_max;
                            ?>
                                <div class="tejcart-facet-price-bar<?php echo $is_active ? ' is-active' : ''; ?>"
                                     style="height: <?php echo esc_attr( max( 2, $pct ) ); ?>%"
                                     data-count="<?php echo esc_attr( (string) $count ); ?>"
                                     title="<?php echo esc_attr( sprintf(
                                         /* translators: 1: price range start, 2: price range end, 3: product count */
                                         __( '%1$s – %2$s: %3$d products', 'tejcart' ),
                                         tejcart_price( $bar_min ),
                                         tejcart_price( $bar_max ),
                                         $count
                                     ) ); ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="tejcart-facet-price-range-labels" aria-hidden="true">
                            <span><?php echo esc_html( tejcart_price( floor( $range['min'] ) ) ); ?></span>
                            <span><?php echo esc_html( tejcart_price( ceil( $range['max'] ) ) ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="tejcart-facet-price-slider" aria-hidden="true">
                            <div class="tejcart-facet-price-track">
                                <div class="tejcart-facet-price-fill"></div>
                            </div>
                            <div class="tejcart-facet-price-handle tejcart-facet-price-handle--min"></div>
                            <div class="tejcart-facet-price-handle tejcart-facet-price-handle--max"></div>
                            <input type="range" class="tejcart-facet-price-thumb tejcart-facet-price-thumb--min"
                                   min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                                   max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                                   value="<?php echo esc_attr( (string) $current_min ); ?>"
                                   step="<?php echo esc_attr( (string) $step ); ?>"
                                   aria-label="<?php esc_attr_e( 'Minimum price', 'tejcart' ); ?>" />
                            <input type="range" class="tejcart-facet-price-thumb tejcart-facet-price-thumb--max"
                                   min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                                   max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                                   value="<?php echo esc_attr( (string) $current_max ); ?>"
                                   step="<?php echo esc_attr( (string) $step ); ?>"
                                   aria-label="<?php esc_attr_e( 'Maximum price', 'tejcart' ); ?>" />
                        </div>
                        <div class="tejcart-facet-price-inputs">
                            <div class="tejcart-facet-price-col">
                                <span class="tejcart-facet-price-label" aria-hidden="true"><?php esc_html_e( 'Min', 'tejcart' ); ?></span>
                                <label class="tejcart-facet-price-field">
                                    <span class="screen-reader-text"><?php esc_html_e( 'Min price', 'tejcart' ); ?></span>
                                    <span class="tejcart-facet-price-prefix" aria-hidden="true"><?php echo esc_html( tejcart_get_currency_symbol() ); ?></span>
                                    <input type="number" name="<?php echo esc_attr( self::PARAM_MIN_PRICE ); ?>"
                                           class="tejcart-facet-price-input"
                                           value="<?php echo $state['min_price'] > 0 ? esc_attr( (string) $state['min_price'] ) : ''; ?>"
                                           placeholder="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                                           min="0" step="<?php echo esc_attr( (string) $step ); ?>" />
                                </label>
                            </div>
                            <span class="tejcart-facet-price-sep" aria-hidden="true"><?php esc_html_e( 'to', 'tejcart' ); ?></span>
                            <div class="tejcart-facet-price-col">
                                <span class="tejcart-facet-price-label" aria-hidden="true"><?php esc_html_e( 'Max', 'tejcart' ); ?></span>
                                <label class="tejcart-facet-price-field">
                                    <span class="screen-reader-text"><?php esc_html_e( 'Max price', 'tejcart' ); ?></span>
                                    <span class="tejcart-facet-price-prefix" aria-hidden="true"><?php echo esc_html( tejcart_get_currency_symbol() ); ?></span>
                                    <input type="number" name="<?php echo esc_attr( self::PARAM_MAX_PRICE ); ?>"
                                           class="tejcart-facet-price-input"
                                           value="<?php echo $state['max_price'] > 0 ? esc_attr( (string) $state['max_price'] ) : ''; ?>"
                                           placeholder="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                                           min="0" step="<?php echo esc_attr( (string) $step ); ?>" />
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        </fieldset>
        <?php
    }

    private function render_attribute_facets( array $state, array $counts ): void {
        $attributes      = $this->get_filterable_attributes();
        $attribute_counts = $counts['attributes'] ?? array();

        foreach ( $attributes as $attr_name => $attr_values ) {
            $attr_key      = sanitize_title( $attr_name );
            $value_counts  = $attribute_counts[ $attr_key ] ?? array();
            $active_values = $state['attributes'][ $attr_key ] ?? array();

            $visible = array();
            foreach ( $attr_values as $val ) {
                $cnt = $value_counts[ $val ] ?? 0;
                if ( $cnt > 0 || in_array( $val, $active_values, true ) ) {
                    $visible[] = $val;
                }
            }

            if ( empty( $visible ) ) {
                continue;
            }

            usort( $visible, function ( string $a, string $b ) use ( $value_counts, $active_values ): int {
                $a_active = in_array( $a, $active_values, true ) ? 1 : 0;
                $b_active = in_array( $b, $active_values, true ) ? 1 : 0;
                if ( $a_active !== $b_active ) {
                    return $b_active - $a_active;
                }
                return ( $value_counts[ $b ] ?? 0 ) - ( $value_counts[ $a ] ?? 0 );
            } );

            $total          = count( $visible );
            $limit          = self::FACET_INITIAL_LIMIT;
            $has_overflow   = $total > $limit;
            $has_search     = $total >= self::FACET_SEARCH_THRESHOLD;
            $overflow_count = 0;
            $param_name     = self::PARAM_ATTR_PREFIX . $attr_key;
            ?>
            <fieldset class="tejcart-facet-fieldset">
                <legend class="screen-reader-text"><?php echo esc_html( $attr_name ); ?></legend>
                <?php $attr_is_open = ! empty( $active_values ); ?>
                <details class="tejcart-facet-section"<?php echo $attr_is_open ? ' open' : ''; ?>>
                    <summary class="tejcart-facet-heading" aria-expanded="<?php echo $attr_is_open ? 'true' : 'false'; ?>">
                        <span class="tejcart-facet-heading-text"><?php echo esc_html( $attr_name ); ?></span>
                        <?php if ( ! empty( $active_values ) ) : ?>
                            <span class="tejcart-facet-heading-count"><?php echo esc_html( (string) count( $active_values ) ); ?></span>
                        <?php endif; ?>
                        <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="tejcart-facet-body">
                        <?php if ( $has_search ) : ?>
                            <input type="text" class="tejcart-facet-search"
                                   placeholder="<?php
                                   /* translators: %s: attribute name (e.g. "color") */
                                   echo esc_attr( sprintf( __( 'Search %s…', 'tejcart' ), strtolower( $attr_name ) ) ); ?>"
                                   aria-label="<?php
                                   /* translators: %s: attribute name (e.g. "Color") */
                                   echo esc_attr( sprintf( __( 'Search %s', 'tejcart' ), $attr_name ) ); ?>" />
                        <?php endif; ?>
                        <ul class="tejcart-facet-list" role="list">
                            <?php
                            $i = 0;
                            foreach ( $visible as $val ) :
                                $cnt         = $value_counts[ $val ] ?? 0;
                                $is_checked  = in_array( $val, $active_values, true );
                                $is_overflow = ! $is_checked && $i >= $limit;
                                if ( $is_overflow ) {
                                    $overflow_count++;
                                }
                                $i++;
                            ?>
                                <li class="tejcart-facet-item<?php echo $is_overflow ? ' is-overflow' : ''; ?>">
                                    <label class="tejcart-facet-label">
                                        <input type="checkbox" class="screen-reader-text" name="<?php echo esc_attr( $param_name ); ?>[]"
                                               value="<?php echo esc_attr( $val ); ?>"
                                               <?php checked( $is_checked ); ?> />
                                        <span class="tejcart-facet-check" aria-hidden="true"></span>
                                        <span class="tejcart-facet-text"><?php echo esc_html( $val ); ?></span>
                                        <span class="tejcart-facet-count"><?php echo esc_html( (string) $cnt ); ?></span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ( $has_overflow && $overflow_count > 0 ) : ?>
                            <button type="button" class="tejcart-facet-toggle-more"
                                    data-more="<?php
                                    /* translators: %d: total number of items */
                                    echo esc_attr( sprintf( __( 'Show all (%d)', 'tejcart' ), $total ) ); ?>"
                                    data-less="<?php esc_attr_e( 'Show less', 'tejcart' ); ?>">
                                <?php
                                /* translators: %d: total number of items */
                                echo esc_html( sprintf( __( 'Show all (%d)', 'tejcart' ), $total ) ); ?>
                                <span class="tejcart-facet-toggle-chevron" aria-hidden="true"></span>
                            </button>
                        <?php endif; ?>
                    </div>
                </details>
            </fieldset>
            <?php
        }
    }

    private function render_rating_facet( array $state, array $counts ): void {
        $buckets = $counts['ratings'] ?? array();
        $has_any = false;
        for ( $s = 5; $s >= 1; $s-- ) {
            if ( ( $buckets[ $s ] ?? 0 ) > 0 ) {
                $has_any = true;
                break;
            }
        }
        if ( ! $has_any ) {
            return;
        }

        $active = $state['rating'];

        ?>
        <fieldset class="tejcart-facet-fieldset">
            <legend class="screen-reader-text"><?php esc_html_e( 'Rating', 'tejcart' ); ?></legend>
            <?php $rating_is_open = $active > 0; ?>
            <details class="tejcart-facet-section"<?php echo $rating_is_open ? ' open' : ''; ?>>
                <summary class="tejcart-facet-heading" aria-expanded="<?php echo $rating_is_open ? 'true' : 'false'; ?>">
                    <span class="tejcart-facet-heading-text"><?php esc_html_e( 'Rating', 'tejcart' ); ?></span>
                    <?php if ( $active > 0 ) : ?>
                        <span class="tejcart-facet-heading-count">1</span>
                    <?php endif; ?>
                    <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="tejcart-facet-body">
                    <ul class="tejcart-facet-list tejcart-facet-list--rating" role="list">
                        <?php for ( $stars = 5; $stars >= 1; $stars-- ) :
                            $cnt = $buckets[ $stars ] ?? 0;
                            if ( 0 === $cnt && $active !== $stars ) {
                                continue;
                            }
                        ?>
                            <li class="tejcart-facet-item">
                                <label class="tejcart-facet-label">
                                    <input type="radio" name="<?php echo esc_attr( self::PARAM_RATING ); ?>"
                                           value="<?php echo esc_attr( (string) $stars ); ?>"
                                           <?php checked( $active, $stars ); ?> />
                                    <span class="tejcart-facet-radio" aria-hidden="true"></span>
                                    <span class="tejcart-facet-stars" aria-label="<?php echo esc_attr( sprintf( '%d stars & up', $stars ) ); ?>">
                                        <?php echo esc_html( str_repeat( "\u{2605}", $stars ) . str_repeat( "\u{2606}", 5 - $stars ) ); ?>
                                    </span>
                                    <span class="tejcart-facet-text"><?php esc_html_e( '& Up', 'tejcart' ); ?></span>
                                    <span class="tejcart-facet-count"><?php echo esc_html( (string) $cnt ); ?></span>
                                </label>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            </details>
        </fieldset>
        <?php
    }

    private function render_stock_facet( array $state, array $counts ): void {
        $stock = $counts['stock'] ?? array( 'instock' => 0, 'outofstock' => 0 );
        $total = (int) ( $stock['instock'] ?? 0 ) + (int) ( $stock['outofstock'] ?? 0 );
        if ( $total <= 0 || 0 === $stock['outofstock'] ) {
            return;
        }

        ?>
        <fieldset class="tejcart-facet-fieldset">
            <legend class="screen-reader-text"><?php esc_html_e( 'Availability', 'tejcart' ); ?></legend>
            <details class="tejcart-facet-section" open>
                <summary class="tejcart-facet-heading" aria-expanded="true">
                    <span class="tejcart-facet-heading-text"><?php esc_html_e( 'Availability', 'tejcart' ); ?></span>
                    <?php if ( $state['in_stock'] ) : ?>
                        <span class="tejcart-facet-heading-count">1</span>
                    <?php endif; ?>
                    <svg class="tejcart-facet-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="tejcart-facet-body">
                    <label class="tejcart-facet-label tejcart-facet-toggle" role="switch" aria-checked="<?php echo $state['in_stock'] ? 'true' : 'false'; ?>">
                        <input type="checkbox" name="<?php echo esc_attr( self::PARAM_STOCK ); ?>"
                               value="1" <?php checked( $state['in_stock'] ); ?> />
                        <span class="tejcart-facet-text"><?php esc_html_e( 'In Stock Only', 'tejcart' ); ?></span>
                        <span class="tejcart-facet-count"><?php echo esc_html( (string) $stock['instock'] ); ?></span>
                        <span class="tejcart-facet-switch" aria-hidden="true">
                            <span class="tejcart-facet-switch-thumb"></span>
                        </span>
                    </label>
                </div>
            </details>
        </fieldset>
        <?php
    }

    // ──────────────────────────────────────────────
    //  URL construction helpers
    // ──────────────────────────────────────────────

    /**
     * Build a filter URL from a given state with optional overrides.
     *
     * Overrides allow removing a single facet value via keys like
     * 'remove_category', 'remove_brand', 'remove_attr', etc.
     *
     * @param array $state    Current filter state.
     * @param array $override Modifications to apply.
     * @return string Full URL with filter params.
     */
    private function build_filter_url( array $state, array $override = array() ): string {
        $params = array();
        $s      = $state;

        if ( isset( $override['remove_category'] ) ) {
            $s['categories'] = array_values( array_diff( $s['categories'], array( $override['remove_category'] ) ) );
        }
        if ( isset( $override['remove_brand'] ) ) {
            $s['brands'] = array_values( array_diff( $s['brands'], array( $override['remove_brand'] ) ) );
        }
        if ( isset( $override['remove_tag'] ) ) {
            $s['tags'] = array_values( array_diff( $s['tags'], array( $override['remove_tag'] ) ) );
        }
        if ( ! empty( $override['remove_price'] ) ) {
            $s['min_price'] = -1.0;
            $s['max_price'] = -1.0;
        }
        if ( ! empty( $override['remove_rating'] ) ) {
            $s['rating'] = 0;
        }
        if ( ! empty( $override['remove_stock'] ) ) {
            $s['in_stock'] = false;
        }
        if ( isset( $override['remove_attr'] ) && is_array( $override['remove_attr'] ) ) {
            [ $attr_key, $attr_val ] = $override['remove_attr'];
            if ( isset( $s['attributes'][ $attr_key ] ) ) {
                $s['attributes'][ $attr_key ] = array_values( array_diff( $s['attributes'][ $attr_key ], array( $attr_val ) ) );
                if ( empty( $s['attributes'][ $attr_key ] ) ) {
                    unset( $s['attributes'][ $attr_key ] );
                }
            }
        }

        if ( ! empty( $s['categories'] ) ) {
            $params[ self::PARAM_CATEGORY ] = implode( ',', $s['categories'] );
        }
        if ( ! empty( $s['tags'] ) ) {
            $params[ self::PARAM_TAG ] = implode( ',', $s['tags'] );
        }
        if ( ! empty( $s['brands'] ) ) {
            $params[ self::PARAM_BRAND ] = implode( ',', $s['brands'] );
        }
        if ( $s['min_price'] > 0 ) {
            $params[ self::PARAM_MIN_PRICE ] = (string) $s['min_price'];
        }
        if ( $s['max_price'] > 0 ) {
            $params[ self::PARAM_MAX_PRICE ] = (string) $s['max_price'];
        }
        if ( $s['rating'] > 0 ) {
            $params[ self::PARAM_RATING ] = (string) $s['rating'];
        }
        if ( $s['in_stock'] ) {
            $params[ self::PARAM_STOCK ] = '1';
        }
        foreach ( $s['attributes'] as $key => $vals ) {
            if ( ! empty( $vals ) ) {
                $params[ self::PARAM_ATTR_PREFIX . $key ] = implode( ',', $vals );
            }
        }

        // Preserve non-filter params.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['tejcart_sort'] ) ) {
            $params['tejcart_sort'] = sanitize_key( $_GET['tejcart_sort'] );
        }
        if ( isset( $_GET['tejcart_s'] ) ) {
            $params['tejcart_s'] = sanitize_text_field( wp_unslash( $_GET['tejcart_s'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $base = $this->get_shop_base_url();

        if ( $this->has_clean_urls() && ! empty( $params ) ) {
            $path = $this->build_filter_path( $s );
            if ( '' !== $path ) {
                $clean_base = trailingslashit( $base ) . self::FILTER_PATH_PREFIX . '/' . $path . '/';
                $extra      = array();
                if ( isset( $params['tejcart_sort'] ) ) {
                    $extra['tejcart_sort'] = $params['tejcart_sort'];
                }
                if ( isset( $params['tejcart_s'] ) ) {
                    $extra['tejcart_s'] = $params['tejcart_s'];
                }
                return empty( $extra ) ? $clean_base : add_query_arg( $extra, $clean_base );
            }
        }

        return empty( $params ) ? $base : add_query_arg( $params, $base );
    }

    /**
     * Render hidden inputs for non-filter query params (sort, search)
     * so they survive the form GET submission.
     */
    private function render_preserved_params(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['tejcart_sort'] ) ) {
            echo '<input type="hidden" name="tejcart_sort" value="' . esc_attr( sanitize_key( $_GET['tejcart_sort'] ) ) . '" />';
        }
        if ( isset( $_GET['tejcart_s'] ) ) {
            echo '<input type="hidden" name="tejcart_s" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['tejcart_s'] ) ) ) . '" />';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Get the base shop page URL (no filter params).
     */
    private function get_shop_base_url(): string {
        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        if ( $shop_page_id > 0 ) {
            $url = (string) get_permalink( $shop_page_id );
            if ( '' !== $url ) {
                return $url;
            }
        }
        return home_url( '/' );
    }

    /**
     * Check if the current request is the shop page.
     */
    public function is_on_shop_page(): bool {
        $shop_page_id = absint( get_option( 'tejcart_shop_page_id', 0 ) );
        return $shop_page_id > 0 && function_exists( 'is_page' ) && is_page( $shop_page_id );
    }

    // ──────────────────────────────────────────────
    //  Existing shortcode + AJAX (preserved)
    // ──────────────────────────────────────────────

    public function invalidate_attribute_cache(): void {
        wp_cache_delete( self::ATTR_CACHE_KEY, 'tejcart' );
        wp_cache_delete( self::FACET_CACHE_KEY, 'tejcart' );
    }

    /**
     * Shortcode callback for [tejcart_product_filter].
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Filter form HTML.
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts(
            array(
                'show_category'   => 'yes',
                'show_tag'        => 'yes',
                'show_brand'      => 'yes',
                'show_price'      => 'yes',
                'show_rating'     => 'yes',
                'show_stock'      => 'yes',
                'show_attributes' => 'yes',
            ),
            $atts,
            'tejcart_product_filter'
        );

        $categories = get_terms( array(
            'taxonomy'   => Product_Taxonomy::CATEGORY_TAXONOMY,
            'hide_empty' => true,
        ) );
        if ( is_wp_error( $categories ) ) {
            $categories = array();
        }

        $tags = get_terms( array(
            'taxonomy'   => Product_Taxonomy::TAG_TAXONOMY,
            'hide_empty' => true,
        ) );
        if ( is_wp_error( $tags ) ) {
            $tags = array();
        }

        $brands = get_terms( array(
            'taxonomy'   => Product_Taxonomy::BRAND_TAXONOMY,
            'hide_empty' => true,
        ) );
        if ( is_wp_error( $brands ) ) {
            $brands = array();
        }

        ob_start();
        ?>
        <div class="tejcart-product-filter" data-nonce="<?php echo esc_attr( wp_create_nonce( 'tejcart_filter_nonce' ) ); ?>">
            <form class="tejcart-filter-form" method="get">

                <?php if ( 'yes' === $atts['show_category'] && ! empty( $categories ) ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Categories', 'tejcart' ); ?></h4>
                        <ul class="tejcart-filter-category-list">
                            <?php foreach ( $categories as $category ) : ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="tejcart_category[]" value="<?php echo esc_attr( $category->term_id ); ?>" />
                                        <?php echo esc_html( $category->name ); ?>
                                        <span class="tejcart-filter-count">(<?php echo esc_html( $category->count ); ?>)</span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_tag'] && ! empty( $tags ) ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Tags', 'tejcart' ); ?></h4>
                        <ul class="tejcart-filter-tag-list">
                            <?php foreach ( $tags as $tag ) : ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="tejcart_tag[]" value="<?php echo esc_attr( $tag->term_id ); ?>" />
                                        <?php echo esc_html( $tag->name ); ?>
                                        <span class="tejcart-filter-count">(<?php echo esc_html( $tag->count ); ?>)</span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_brand'] && ! empty( $brands ) ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Brand', 'tejcart' ); ?></h4>
                        <ul class="tejcart-filter-brand-list">
                            <?php foreach ( $brands as $brand ) : ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="tejcart_brand[]" value="<?php echo esc_attr( $brand->term_id ); ?>" />
                                        <?php echo esc_html( $brand->name ); ?>
                                        <span class="tejcart-filter-count">(<?php echo esc_html( $brand->count ); ?>)</span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_price'] ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Price Range', 'tejcart' ); ?></h4>
                        <div class="tejcart-filter-price-range">
                            <label>
                                <span class="screen-reader-text"><?php esc_html_e( 'Min price', 'tejcart' ); ?></span>
                                <input type="number" name="tejcart_price_min" class="tejcart-filter-price-input" placeholder="<?php esc_attr_e( 'Min', 'tejcart' ); ?>" min="0" step="0.01" />
                            </label>
                            <span class="tejcart-filter-price-separator">&ndash;</span>
                            <label>
                                <span class="screen-reader-text"><?php esc_html_e( 'Max price', 'tejcart' ); ?></span>
                                <input type="number" name="tejcart_price_max" class="tejcart-filter-price-input" placeholder="<?php esc_attr_e( 'Max', 'tejcart' ); ?>" min="0" step="0.01" />
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_rating'] ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Rating', 'tejcart' ); ?></h4>
                        <ul class="tejcart-filter-rating-list">
                            <?php for ( $stars = 5; $stars >= 1; $stars-- ) : ?>
                                <li>
                                    <label>
                                        <input type="radio" name="tejcart_rating" value="<?php echo esc_attr( $stars ); ?>" />
                                        <span class="tejcart-filter-stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: star rating */ __( '%d stars & up', 'tejcart' ), $stars ) ); ?>">
                                            <?php echo esc_html( str_repeat( "\u{2605}", $stars ) . str_repeat( "\u{2606}", 5 - $stars ) ); ?>
                                        </span>
                                        <?php esc_html_e( '& Up', 'tejcart' ); ?>
                                    </label>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_stock'] ) : ?>
                    <div class="tejcart-filter-section">
                        <h4 class="tejcart-filter-title"><?php esc_html_e( 'Availability', 'tejcart' ); ?></h4>
                        <label class="tejcart-filter-stock-label">
                            <input type="checkbox" name="tejcart_in_stock" value="1" />
                            <?php esc_html_e( 'In Stock Only', 'tejcart' ); ?>
                        </label>
                    </div>
                <?php endif; ?>

                <?php if ( 'yes' === $atts['show_attributes'] ) : ?>
                    <?php
                    $attributes = $this->get_filterable_attributes();
                    foreach ( $attributes as $attr_name => $attr_values ) :
                    ?>
                        <div class="tejcart-filter-section">
                            <h4 class="tejcart-filter-title"><?php echo esc_html( $attr_name ); ?></h4>
                            <ul class="tejcart-filter-attribute-list">
                                <?php foreach ( $attr_values as $value ) : ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="tejcart_attr[<?php echo esc_attr( sanitize_title( $attr_name ) ); ?>][]" value="<?php echo esc_attr( $value ); ?>" />
                                            <?php echo esc_html( $value ); ?>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="tejcart-filter-actions">
                    <button type="submit" class="tejcart-filter-apply tejcart-add-to-cart-btn">
                        <?php esc_html_e( 'Apply Filters', 'tejcart' ); ?>
                    </button>
                    <button type="reset" class="tejcart-filter-reset">
                        <?php esc_html_e( 'Reset', 'tejcart' ); ?>
                    </button>
                </div>

            </form>

            <div class="tejcart-filter-results"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for product filtering.
     *
     * @return void
     */
    public function ajax_filter(): void {
        check_ajax_referer( 'tejcart_filter_nonce', 'nonce' );

        global $wpdb;

        $products_table = $wpdb->prefix . 'tejcart_products';
        $meta_table     = $wpdb->prefix . 'tejcart_product_meta';
        $rel_table      = $wpdb->prefix . 'tejcart_term_relationships';

        $where   = array( 'p.status = %s', "p.type <> 'variation'", "p.catalog_visibility IN ('visible','catalog','')" );
        $values  = array( 'publish' );
        $joins   = array();
        $join_id = 0;

        $taxonomy_filters = array(
            'category' => array( 'taxonomy' => Product_Taxonomy::CATEGORY_TAXONOMY, 'alias' => 'rel_cat' ),
            'tag'      => array( 'taxonomy' => Product_Taxonomy::TAG_TAXONOMY,      'alias' => 'rel_tag' ),
            'brand'    => array( 'taxonomy' => Product_Taxonomy::BRAND_TAXONOMY,    'alias' => 'rel_brand' ),
        );

        foreach ( $taxonomy_filters as $param => $cfg ) {
            if ( empty( $_POST[ $param ] ) || ! is_array( $_POST[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
                continue;
            }

            $term_ids = array_filter( array_map( 'absint', wp_unslash( $_POST[ $param ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( empty( $term_ids ) ) {
                continue;
            }

            $tt_ids = array();
            foreach ( $term_ids as $tid ) {
                $term = get_term( $tid, $cfg['taxonomy'] );
                if ( $term && ! is_wp_error( $term ) ) {
                    $tt_ids[] = absint( $term->term_taxonomy_id );
                }
            }

            if ( empty( $tt_ids ) ) {
                continue;
            }

            $placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
            $alias        = $cfg['alias'];
            $joins[]      = "INNER JOIN {$rel_table} AS {$alias} ON {$alias}.product_id = p.id";
            $where[]      = "{$alias}.term_taxonomy_id IN ($placeholders)";
            $values       = array_merge( $values, $tt_ids );
        }

        $search_term = '';
        if ( isset( $_POST['search'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            $search_term = sanitize_text_field( wp_unslash( (string) $_POST['search'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        $search_term = trim( $search_term );
        if ( strlen( $search_term ) > 100 ) {
            $search_term = substr( $search_term, 0, 100 );
        }
        if ( '' !== $search_term ) {
            $like     = '%' . $wpdb->esc_like( $search_term ) . '%';
            $where[]  = '( p.name LIKE %s OR p.sku LIKE %s OR p.short_description LIKE %s )';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $price_min = isset( $_POST['price_min'] ) ? floatval( wp_unslash( $_POST['price_min'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $price_max = isset( $_POST['price_max'] ) ? floatval( wp_unslash( $_POST['price_max'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        // The posted min/max are in the ACTIVE display currency; reverse them to
        // the BASE currency the price columns are stored in. Passthrough on a
        // single-currency store.
        if ( $price_min > 0 ) {
            $where[]  = 'CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) >= %f';
            $values[] = (float) apply_filters( 'tejcart_amount_to_base', $price_min );
        }
        if ( $price_max > 0 ) {
            $where[]  = 'CAST(COALESCE(p.sale_price, p.price) AS DECIMAL(10,2)) <= %f';
            $values[] = (float) apply_filters( 'tejcart_amount_to_base', $price_max );
        }

        if ( ! empty( $_POST['in_stock'] ) || \TejCart\Product\Stock_Display::hide_out_of_stock() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $where[]  = 'p.stock_status = %s';
            $values[] = 'instock';
        }

        if ( ! empty( $_POST['rating'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $min_rating = absint( wp_unslash( $_POST['rating'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( $min_rating >= 1 && $min_rating <= 5 ) {
                $join_id++;
                $alias    = "meta_rating_{$join_id}";
                $joins[]  = "INNER JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = '_average_rating'";
                $where[]  = "CAST({$alias}.meta_value AS DECIMAL(3,2)) >= %f";
                $values[] = (float) $min_rating;
            }
        }

        if ( ! empty( $_POST['attr'] ) && is_array( $_POST['attr'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $raw_attrs = map_deep( wp_unslash( $_POST['attr'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            foreach ( $raw_attrs as $attr_key => $attr_values ) {
                if ( ! is_array( $attr_values ) ) {
                    continue;
                }

                $attr_key    = sanitize_key( $attr_key );
                $attr_values = array_map( 'sanitize_text_field', $attr_values );
                $attr_values = array_filter( $attr_values );

                if ( empty( $attr_values ) ) {
                    continue;
                }

                $join_id++;
                $alias        = "meta_attr_{$join_id}";
                $placeholders = implode( ',', array_fill( 0, count( $attr_values ), '%s' ) );
                $safe_meta_key = $wpdb->prepare( '%s', '_attribute_' . $attr_key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $joins[]       = "INNER JOIN {$meta_table} AS {$alias} ON {$alias}.product_id = p.id AND {$alias}.meta_key = {$safe_meta_key}";
                $where[]       = "{$alias}.meta_value IN ($placeholders)";
                $values        = array_merge( $values, $attr_values );
            }
        }

        $page     = max( 1, absint( $_POST['page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $per_page = absint( $_POST['per_page'] ?? 12 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $per_page = min( $per_page, 100 );
        $offset   = ( $page - 1 ) * $per_page;

        $join_sql  = implode( ' ', $joins );
        $where_sql = implode( ' AND ', $where );

        /** This filter is documented above in apply_url_filters context. */
        $query_args = apply_filters( 'tejcart_product_query_args', array(
            'where_sql' => $where_sql,
            'join_sql'  => $join_sql,
            'values'    => $values,
            'order_by'  => 'p.created_at DESC',
            'per_page'  => $per_page,
            'offset'    => $offset,
        ) );

        $where_sql = $query_args['where_sql'];
        $join_sql  = $query_args['join_sql'];
        $values    = $query_args['values'];
        $order_by  = ! empty( $query_args['order_by'] ) ? (string) $query_args['order_by'] : 'p.created_at DESC';
        $per_page  = $query_args['per_page'];
        $offset    = $query_args['offset'];

        if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_.]{0,63}(\s+(ASC|DESC|asc|desc))?$/', trim( $order_by ) ) ) {
            $order_by = 'p.created_at DESC';
        }

        $sql = "SELECT DISTINCT p.* FROM {$products_table} AS p {$join_sql} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        $count_sql    = "SELECT COUNT(DISTINCT p.id) FROM {$products_table} AS p {$join_sql} WHERE {$where_sql}";
        $count_values = array_slice( $values, 0, -2 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_values ) );

        $products_html = '';

        if ( ! empty( $results ) ) {
            foreach ( $results as $row ) {
                $product = tejcart_get_product( absint( $row['id'] ) );
                if ( ! $product || 0 === $product->get_id() ) {
                    continue;
                }

                ob_start();
                tejcart_get_template( 'product/product-box.php', array( 'product' => $product ) );
                $products_html .= ob_get_clean();
            }
        }

        wp_send_json_success( array(
            'html'       => $products_html,
            'total'      => $total,
            'pages'      => ceil( $total / max( 1, $per_page ) ),
            'page'       => $page,
            'found'      => count( $results ),
        ) );
    }

    /**
     * Retrieve filterable product attributes from the meta table.
     *
     * @return array<string, string[]>
     */
    public function get_filterable_attributes(): array {
        $cached = wp_cache_get( self::ATTR_CACHE_KEY, 'tejcart' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $meta_table     = $wpdb->prefix . 'tejcart_product_meta';
        $products_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT m.meta_key, m.meta_value
                 FROM {$meta_table} AS m
                 INNER JOIN {$products_table} AS p ON p.id = m.product_id
                 WHERE m.meta_key LIKE %s AND p.status = 'publish' AND m.meta_value != ''
                 ORDER BY m.meta_key, m.meta_value",
                '_attribute_%'
            ),
            ARRAY_A
        );
        // phpcs:enable

        $attributes = array();
        foreach ( $rows as $row ) {
            $name = ucwords( str_replace( array( '_attribute_', '_', '-' ), array( '', ' ', ' ' ), $row['meta_key'] ) );
            if ( ! isset( $attributes[ $name ] ) ) {
                $attributes[ $name ] = array();
            }
            $value = $row['meta_value'];
            if ( ! in_array( $value, $attributes[ $name ], true ) ) {
                $attributes[ $name ][] = $value;
            }
        }

        wp_cache_set( self::ATTR_CACHE_KEY, $attributes, 'tejcart', HOUR_IN_SECONDS );

        return $attributes;
    }
}
