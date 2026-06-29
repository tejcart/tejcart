<?php
/**
 * Product Taxonomy - Categories & Tags with custom table bridge.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers custom taxonomies for products and provides a bridge
 * between TejCart's custom product tables and WordPress term relationships.
 */
class Product_Taxonomy {
    /**
     * Category taxonomy name.
     *
     * @var string
     */
    const CATEGORY_TAXONOMY = 'tejcart_product_cat';

    /**
     * Tag taxonomy name.
     *
     * @var string
     */
    const TAG_TAXONOMY = 'tejcart_product_tag';

    /**
     * Brand taxonomy name.
     *
     * @var string
     */
    const BRAND_TAXONOMY = 'tejcart_brand';

    /**
     * Shipping class taxonomy name.
     *
     * @var string
     */
    const SHIPPING_CLASS_TAXONOMY = 'tejcart_shipping_class';

    /**
     * Initialize taxonomy registration.
     *
     * @return void
     */
    public function init() {
        add_action( 'init', array( $this, 'register_taxonomies' ) );
    }

    /**
     * Register product category and tag taxonomies.
     *
     * Registered with an empty object_type since TejCart uses custom tables
     * instead of the WordPress post type system.
     *
     * @return void
     */
    public function register_taxonomies() {
        register_taxonomy(
            self::CATEGORY_TAXONOMY,
            array(),
            array(
                'labels'            => array(
                    'name'              => __( 'Product Categories', 'tejcart' ),
                    'singular_name'     => __( 'Product Category', 'tejcart' ),
                    'search_items'      => __( 'Search Product Categories', 'tejcart' ),
                    'all_items'         => __( 'All Product Categories', 'tejcart' ),
                    'parent_item'       => __( 'Parent Product Category', 'tejcart' ),
                    'parent_item_colon' => __( 'Parent Product Category:', 'tejcart' ),
                    'edit_item'         => __( 'Edit Product Category', 'tejcart' ),
                    'update_item'       => __( 'Update Product Category', 'tejcart' ),
                    'add_new_item'      => __( 'Add New Product Category', 'tejcart' ),
                    'new_item_name'     => __( 'New Product Category Name', 'tejcart' ),
                    'menu_name'         => __( 'Product Categories', 'tejcart' ),
                ),
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => false,
                'show_in_rest'      => true,
                'rewrite'           => array( 'slug' => 'product-category' ),
            )
        );

        $brand_slug = (string) apply_filters( 'tejcart_brand_rewrite_slug', 'brand' );
        register_taxonomy(
            self::BRAND_TAXONOMY,
            array(),
            array(
                'labels'            => array(
                    'name'              => __( 'Brands', 'tejcart' ),
                    'singular_name'     => __( 'Brand', 'tejcart' ),
                    'search_items'      => __( 'Search Brands', 'tejcart' ),
                    'all_items'         => __( 'All Brands', 'tejcart' ),
                    'parent_item'       => __( 'Parent Brand', 'tejcart' ),
                    'parent_item_colon' => __( 'Parent Brand:', 'tejcart' ),
                    'edit_item'         => __( 'Edit Brand', 'tejcart' ),
                    'update_item'       => __( 'Update Brand', 'tejcart' ),
                    'add_new_item'      => __( 'Add New Brand', 'tejcart' ),
                    'new_item_name'     => __( 'New Brand Name', 'tejcart' ),
                    'menu_name'         => __( 'Brands', 'tejcart' ),
                ),
                'hierarchical'      => true,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => false,
                'show_in_rest'      => true,
                'rewrite'           => array( 'slug' => $brand_slug ),
            )
        );

        register_taxonomy(
            self::SHIPPING_CLASS_TAXONOMY,
            array(),
            array(
                'labels'            => array(
                    'name'          => __( 'Shipping Classes', 'tejcart' ),
                    'singular_name' => __( 'Shipping Class', 'tejcart' ),
                    'search_items'  => __( 'Search Shipping Classes', 'tejcart' ),
                    'all_items'     => __( 'All Shipping Classes', 'tejcart' ),
                    'edit_item'     => __( 'Edit Shipping Class', 'tejcart' ),
                    'update_item'   => __( 'Update Shipping Class', 'tejcart' ),
                    'add_new_item'  => __( 'Add New Shipping Class', 'tejcart' ),
                    'new_item_name' => __( 'New Shipping Class', 'tejcart' ),
                    'menu_name'     => __( 'Shipping Classes', 'tejcart' ),
                ),
                'hierarchical'      => false,
                'public'            => false,
                'show_ui'           => true,
                'show_admin_column' => false,
                'show_in_menu'      => false,
                'show_in_rest'      => true,
                'rewrite'           => false,
            )
        );

        register_taxonomy(
            self::TAG_TAXONOMY,
            array(),
            array(
                'labels'            => array(
                    'name'                       => __( 'Product Tags', 'tejcart' ),
                    'singular_name'              => __( 'Product Tag', 'tejcart' ),
                    'search_items'               => __( 'Search Product Tags', 'tejcart' ),
                    'all_items'                  => __( 'All Product Tags', 'tejcart' ),
                    'edit_item'                  => __( 'Edit Product Tag', 'tejcart' ),
                    'update_item'                => __( 'Update Product Tag', 'tejcart' ),
                    'add_new_item'               => __( 'Add New Product Tag', 'tejcart' ),
                    'new_item_name'              => __( 'New Product Tag Name', 'tejcart' ),
                    'menu_name'                  => __( 'Product Tags', 'tejcart' ),
                    'popular_items'              => __( 'Popular Product Tags', 'tejcart' ),
                    'separate_items_with_commas' => __( 'Separate product tags with commas', 'tejcart' ),
                    'add_or_remove_items'        => __( 'Add or remove product tags', 'tejcart' ),
                    'choose_from_most_used'      => __( 'Choose from the most used product tags', 'tejcart' ),
                    'not_found'                  => __( 'No product tags found.', 'tejcart' ),
                ),
                'hierarchical'      => false,
                'public'            => true,
                'show_ui'           => true,
                'show_admin_column' => false,
                'show_in_rest'      => true,
                'rewrite'           => array( 'slug' => 'product-tag' ),
            )
        );
    }

    /**
     * Get the term relationships table name.
     *
     * @return string
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_term_relationships';
    }

    /**
     * Get product categories for a given product.
     *
     * @param int $product_id Product ID.
     * @return \WP_Term[] Array of WP_Term objects.
     */
    public static function get_product_categories( $product_id ) {
        return self::get_product_terms( $product_id, self::CATEGORY_TAXONOMY );
    }

    /**
     * Set product categories for a given product.
     *
     * Replaces all existing category relationships for the product.
     *
     * @param int   $product_id Product ID.
     * @param int[] $term_ids   Array of term IDs.
     * @return bool True on success.
     */
    public static function set_product_categories( $product_id, $term_ids ) {
        return self::set_product_terms( $product_id, $term_ids, self::CATEGORY_TAXONOMY );
    }

    /**
     * Get product tags for a given product.
     *
     * @param int $product_id Product ID.
     * @return \WP_Term[] Array of WP_Term objects.
     */
    public static function get_product_tags( $product_id ) {
        return self::get_product_terms( $product_id, self::TAG_TAXONOMY );
    }

    /**
     * Set product tags for a given product.
     *
     * Replaces all existing tag relationships for the product.
     *
     * @param int   $product_id Product ID.
     * @param int[] $term_ids   Array of term IDs.
     * @return bool True on success.
     */
    public static function set_product_tags( $product_id, $term_ids ) {
        return self::set_product_terms( $product_id, $term_ids, self::TAG_TAXONOMY );
    }

    /**
     * Get brands for a given product.
     *
     * @param int $product_id Product ID.
     * @return \WP_Term[] Array of WP_Term objects.
     */
    public static function get_product_brands( $product_id ) {
        return self::get_product_terms( $product_id, self::BRAND_TAXONOMY );
    }

    /**
     * Set brands for a product.
     *
     * @param int   $product_id Product ID.
     * @param int[] $term_ids   Array of term IDs.
     * @return bool True on success.
     */
    public static function set_product_brands( $product_id, $term_ids ) {
        return self::set_product_terms( $product_id, $term_ids, self::BRAND_TAXONOMY );
    }

    /**
     * Get products by brand term ID.
     *
     * @param int   $term_id Brand term ID.
     * @param array $args    Optional. See get_products_by_term().
     * @return int[] Array of product IDs.
     */
    public static function get_products_by_brand( $term_id, $args = array() ) {
        return self::get_products_by_term( $term_id, self::BRAND_TAXONOMY, $args );
    }

    /**
     * Get products by category term ID.
     *
     * @param int   $term_id Category term ID.
     * @param array $args    Optional. Additional arguments:
     *                        - 'limit'  (int)    Number of products. Default 20.
     *                        - 'offset' (int)    Offset for pagination. Default 0.
     *                        - 'status' (string) Product status filter. Default 'publish'.
     * @return int[] Array of product IDs.
     */
    public static function get_products_by_category( $term_id, $args = array() ) {
        return self::get_products_by_term( $term_id, self::CATEGORY_TAXONOMY, $args );
    }

    /**
     * Get products by tag term ID.
     *
     * @param int   $term_id Tag term ID.
     * @param array $args    Optional. Same arguments as get_products_by_category().
     * @return int[] Array of product IDs.
     */
    public static function get_products_by_tag( $term_id, $args = array() ) {
        return self::get_products_by_term( $term_id, self::TAG_TAXONOMY, $args );
    }

    /**
     * Get terms associated with a product for a specific taxonomy.
     *
     * @param int    $product_id Product ID.
     * @param string $taxonomy   Taxonomy name.
     * @return \WP_Term[] Array of WP_Term objects.
     */
    public static function get_product_terms( $product_id, $taxonomy ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return array();
        }

        $table = self::get_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $term_taxonomy_ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$table} WHERE product_id = %d",
                $product_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $term_taxonomy_ids ) ) {
            return array();
        }

        $terms = get_terms( array(
            'taxonomy'         => $taxonomy,
            'term_taxonomy_id' => array_map( 'intval', $term_taxonomy_ids ),
            'hide_empty'       => false,
            'number'           => 0,
        ) );

        return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : array();
    }

    /**
     * Set terms for a product in a specific taxonomy.
     *
     * @param int    $product_id Product ID.
     * @param int[]  $term_ids   Array of term IDs.
     * @param string $taxonomy   Taxonomy name.
     * @return bool True on success.
     */
    private static function set_product_terms( $product_id, $term_ids, $taxonomy ) {
        global $wpdb;

        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return false;
        }

        $table    = self::get_table_name();
        $term_ids = array_map( 'absint', (array) $term_ids );
        $term_ids = array_filter( $term_ids );

        $existing_terms = self::get_product_terms( $product_id, $taxonomy );
        foreach ( $existing_terms as $term ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $table,
                array(
                    'product_id'       => $product_id,
                    'term_taxonomy_id' => $term->term_taxonomy_id,
                ),
                array( '%d', '%d' )
            );
        }

        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                array(
                    'product_id'       => $product_id,
                    'term_taxonomy_id' => $term->term_taxonomy_id,
                ),
                array( '%d', '%d' )
            );

            wp_update_term_count_now( array( $term->term_taxonomy_id ), $taxonomy );
        }

        return true;
    }

    /**
     * Get product IDs associated with a specific term.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy name.
     * @param array  $args     Optional query arguments.
     * @return int[] Array of product IDs.
     */
    private static function get_products_by_term( $term_id, $taxonomy, $args = array() ) {
        global $wpdb;

        $term_id = absint( $term_id );
        if ( ! $term_id ) {
            return array();
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return array();
        }

        $defaults = array(
            'limit'  => 20,
            'offset' => 0,
            'status' => 'publish',
        );
        $args = wp_parse_args( $args, $defaults );

        $rel_table     = self::get_table_name();
        $product_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $product_ids = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT r.product_id
                 FROM {$rel_table} AS r
                 INNER JOIN {$product_table} AS p ON p.id = r.product_id
                 WHERE r.term_taxonomy_id = %d AND p.status = %s
                 ORDER BY p.created_at DESC
                 LIMIT %d OFFSET %d",
                $term->term_taxonomy_id,
                sanitize_text_field( $args['status'] ),
                absint( $args['limit'] ),
                absint( $args['offset'] )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( 'absint', $product_ids );
    }
}
